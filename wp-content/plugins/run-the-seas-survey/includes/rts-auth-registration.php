<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Clean up first-visit state after the member creates their passcode. */
function rts_cleanup_temp_password($user)
{
    $user_id = $user instanceof WP_User ? $user->ID : intval($user);

    if (get_user_meta($user_id, 'rts_temp_password', true)) {
        $GLOBALS['rts_first_passcode_created'][$user_id] = true;
        delete_user_meta($user_id, 'rts_temp_password');
        delete_user_meta($user_id, 'rts_first_passcode_setup_requested_at');
        update_user_meta($user_id, 'rts_first_passcode_created_at', current_time('mysql'));
        error_log('RTS: Temporary password meta removed for user ' . $user_id);
    }
}
add_action('password_reset', 'rts_cleanup_temp_password');

/**
 * The verification link now continues directly to first-passcode creation.
 * Suppress BuddyNext's generic account email for only this registration request
 * so it cannot send the member to a competing setup/reset flow.
 */
function rts_suppress_buddynext_duplicate_welcome($user_id)
{
    if (!empty($GLOBALS['rts_creating_member_account'])) {
        update_user_meta($user_id, 'bn_welcome_sent', '1');
    }
}
add_action('user_register', 'rts_suppress_buddynext_duplicate_welcome', 1);

/**
 * Return the BuddyNext login hub, retaining the destination when one is given.
 *
 * BuddyNext owns the public sign-in and password-recovery experience. WordPress
 * core remains the account authority, so the core login URL is only a safe
 * fallback if BuddyNext has not finished loading.
 */
function rts_get_member_login_url($redirect_to = '')
{
    $login_url = '';

    if (class_exists('\\BuddyNext\\Core\\PageRouter')) {
        $login_url = \BuddyNext\Core\PageRouter::auth_url();
    }

    if ($login_url) {
        return $redirect_to
            ? add_query_arg('redirect_to', $redirect_to, $login_url)
            : $login_url;
    }

    return wp_login_url($redirect_to);
}

/**
 * Synchronize the participant email with its linked WordPress account.
 *
 * The user ID is the permanent relationship. This repairs older mismatches
 * created when an administrator changed wp_users.user_email without updating
 * the Run The Seas participant row.
 */
function rts_sync_linked_participant_email($user_id, $reset_verification = false, $send_verification = false)
{
    $user_id = absint($user_id);
    $user = $user_id ? get_userdata($user_id) : false;
    if (!$user instanceof WP_User) {
        return new WP_Error('rts_user_not_found', __('Member account not found.', 'run-the-seas'));
    }

    $registration = RunTheSeasPlugin::get_instance()->registration;
    $participant = $registration->get_participant_by_user_id($user_id);
    if (!$participant) {
        return new WP_Error('rts_participant_not_found', __('No linked Run The Seas participant was found.', 'run-the-seas'));
    }

    $old_email = sanitize_email($participant->email);
    $new_email = sanitize_email($user->user_email);
    if (!$new_email || strtolower($old_email) === strtolower($new_email)) {
        return $participant;
    }

    $email_owner = $registration->get_participant_by_email($new_email);
    if ($email_owner && (int) $email_owner->id !== (int) $participant->id) {
        return new WP_Error('rts_participant_email_conflict', __('That email is linked to another Run The Seas participant.', 'run-the-seas'));
    }

    global $wpdb;
    $update = array(
        'email' => $new_email,
        'updated_at' => current_time('mysql'),
    );
    $formats = array('%s', '%s');
    if ($reset_verification) {
        $update['email_verified'] = 0;
        $update['email_verification_date'] = null;
        $update['captain_suite_status'] = 'inactive';
        $formats = array('%s', '%s', '%d', '%s', '%s');
    }

    $saved = $wpdb->update(
        $wpdb->prefix . 'rts_participants',
        $update,
        array('id' => (int) $participant->id),
        $formats,
        array('%d')
    );
    if (false === $saved) {
        return new WP_Error('rts_participant_email_sync_failed', __('The Run The Seas email could not be synchronized.', 'run-the-seas'));
    }

    $registration->sync_participant_email((int) $participant->id, $old_email, $new_email);
    update_user_meta($user_id, 'rts_email_verified', $reset_verification ? '0' : ((int) $participant->email_verified === 1 ? '1' : '0'));
    $registration->log_timeline(
        (int) $participant->id,
        $reset_verification ? 'email_updated' : 'email_reconciled',
        $reset_verification
            ? 'Participant email synchronized after a WordPress account email change'
            : 'Participant email reconciled with its linked WordPress account',
        array('old_email' => $old_email, 'new_email' => $new_email)
    );

    if ($reset_verification && $send_verification) {
        $registration->send_verification_email((int) $participant->id, true);
    }

    return $registration->get_participant((int) $participant->id);
}

/** Reset verification when an administrator changes the WordPress email. */
function rts_sync_participant_after_wp_profile_update($user_id, $old_user_data)
{
    $user = get_userdata($user_id);
    if (
        !$user instanceof WP_User
        || !$old_user_data instanceof WP_User
        || strtolower((string) $old_user_data->user_email) === strtolower((string) $user->user_email)
    ) {
        return;
    }

    $result = rts_sync_linked_participant_email($user_id, true, true);
    if (is_wp_error($result) && 'rts_participant_not_found' !== $result->get_error_code()) {
        error_log('RTS: WordPress/participant email sync failed for user ' . absint($user_id) . ': ' . $result->get_error_message());
    }
}
add_action('profile_update', 'rts_sync_participant_after_wp_profile_update', 10, 2);

/** Repair a pre-existing email mismatch when the member signs in. */
function rts_reconcile_current_member_email($user_login = '', $user = null)
{
    $user_id = $user instanceof WP_User ? (int) $user->ID : get_current_user_id();
    if (!$user_id) {
        return;
    }

    $result = rts_sync_linked_participant_email($user_id, false, false);
    if (is_wp_error($result) && !in_array($result->get_error_code(), array('rts_participant_not_found', 'rts_user_not_found'), true)) {
        error_log('RTS: Existing WordPress/participant email mismatch could not be repaired for user ' . $user_id . ': ' . $result->get_error_message());
    }
}
add_action('wp_login', 'rts_reconcile_current_member_email', 10, 2);

/**
 * Enforce authentication and role access for protected front-end pages.
 *
 * Send guests through the branded member login flow and return them to the
 * originally requested page after they sign in. The administration dashboard
 * additionally requires the dedicated Run The Seas Admin role.
 */
function rts_enforce_protected_page_access()
{
    if (!is_page()) {
        return;
    }

    if (is_user_logged_in()) {
        if (!is_page('run-the-seas-admin') || rts_is_run_the_seas_admin()) {
            return;
        }

        wp_safe_redirect(rts_get_captains_suite_url());
        exit;
    }

    $protected_page_slugs = array(
        'captains-suite',
        'captains-log',
        'my-qr-code',
        'view-journey',
        'trophy-case',
        'trophy-case-m1',
        'single-trophy',
        'certificates',
        'run-the-seas-admin',
    );

    if (!is_page($protected_page_slugs)) {
        return;
    }

    $redirect_to = get_permalink(get_queried_object_id());
    wp_safe_redirect(rts_get_member_login_url($redirect_to));
    exit;
}
add_action('template_redirect', 'rts_enforce_protected_page_access', 0);

/**
 * Return BuddyNext's branded set-password screen for an existing reset key.
 */
function rts_get_member_password_reset_url($key = '', $login = '')
{
    if (class_exists('\\BuddyNext\\Core\\PageRouter')) {
        $reset_url = \BuddyNext\Core\PageRouter::reset_url();
        if ($reset_url) {
            return add_query_arg(
                array(
                    'key'   => $key,
                    'login' => $login,
                ),
                $reset_url
            );
        }
    }

    return network_site_url(
        'wp-login.php?action=rp&key=' . rawurlencode($key) .
        '&login=' . rawurlencode($login),
        'login'
    );
}

/**
 * Create the one-time passcode-creation URL used immediately after email
 * verification. WordPress core owns the key, expiry, and final password write;
 * the first-visit flag only changes the member-facing wording.
 */
function rts_get_first_passcode_setup_url($user_id)
{
    $user = get_userdata(absint($user_id));
    if (!$user instanceof WP_User) {
        return new WP_Error('rts_first_passcode_user_missing', __('Member account not found.', 'run-the-seas'));
    }

    $key = get_password_reset_key($user);
    if (is_wp_error($key)) {
        return $key;
    }

    update_user_meta($user->ID, 'rts_first_passcode_setup_requested_at', current_time('mysql'));
    return add_query_arg(
        'rts_first_visit',
        '1',
        rts_get_member_password_reset_url($key, $user->user_login)
    );
}

/** Return the public website logo used by the password emails. */
function rts_password_email_logo_url()
{
    $logo_url = (string) get_site_icon_url(512);
    $custom_logo_id = absint(get_theme_mod('custom_logo'));

    if (!$logo_url && $custom_logo_id) {
        $logo_url = (string) wp_get_attachment_image_url($custom_logo_id, 'full');
    }
    if (!$logo_url && function_exists('get_header_image')) {
        $logo_url = (string) get_header_image();
    }

    return (string) apply_filters('rts_password_email_logo_url', $logo_url);
}

/** Render one of the email-safe password templates bundled with this plugin. */
function rts_render_password_email_template($template, array $variables)
{
    $template_path = RTS_PLUGIN_PATH . 'templates/emails/' . sanitize_file_name($template) . '.php';
    if (!is_readable($template_path)) {
        return '';
    }

    extract($variables, EXTR_SKIP);
    ob_start();
    include $template_path;
    return (string) ob_get_clean();
}

/** Resolve a friendly first name without exposing a login name unnecessarily. */
function rts_password_email_first_name($user)
{
    if (!$user instanceof WP_User) {
        return __('Captain', 'run-the-seas');
    }

    $first_name = trim((string) get_user_meta($user->ID, 'first_name', true));
    if (!$first_name) {
        $first_name = trim((string) $user->display_name);
    }

    return $first_name ?: __('Captain', 'run-the-seas');
}

/** Ensure HTML is scoped to this email without changing unrelated wp_mail calls. */
function rts_password_email_headers($headers = array())
{
    return rts_mail_headers('text/html; charset=UTF-8', $headers);
}

/** Build the final reset email from the supplied Run The Seas design. */
function rts_apply_password_reset_email_template($defaults, $key, $user_login, $user_data = null)
{
    $user = $user_data instanceof WP_User ? $user_data : get_user_by('login', $user_login);
    $reset_link = rts_get_member_password_reset_url($key, $user_login);
    $subject = __('Captain’s Suite Passcode Reset', 'run-the-seas');
    $message = rts_render_password_email_template('password-reset', array(
        'first_name'    => rts_password_email_first_name($user),
        'logo_url'      => rts_password_email_logo_url(),
        'reset_link'    => $reset_link,
        'site_url'      => home_url('/'),
        'support_email' => 'support@runtheseas.com',
    ));

    if ($message && function_exists('rts_resolve_transactional_email_template')) {
        $resolved = rts_resolve_transactional_email_template(
            'password_reset',
            $subject,
            $message,
            array(
                'first_name' => rts_password_email_first_name($user),
                'last_name' => $user instanceof WP_User ? $user->last_name : '',
                'email' => $user instanceof WP_User ? $user->user_email : '',
                'password_reset_url' => $reset_link,
                'login_url' => function_exists('rts_get_member_login_url') ? rts_get_member_login_url() : home_url('/login/'),
                'account_url' => home_url('/captains-suite/'),
                'captains_suite_url' => home_url('/captains-suite/'),
                'logo_url' => rts_password_email_logo_url(),
                'support_email' => 'support@runtheseas.com',
            )
        );
        $subject = $resolved['subject'];
        $message = $resolved['html_body'];
    }

    if ($message) {
        $defaults['subject'] = $subject;
        $defaults['message'] = $message;
        $defaults['headers'] = rts_password_email_headers($defaults['headers'] ?? array());
    }

    return $defaults;
}

/** Identify a reset request submitted through BuddyNext's REST endpoint. */
function rts_is_buddynext_password_reset_request()
{
    if (!defined('REST_REQUEST') || !REST_REQUEST) {
        return false;
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
    return false !== strpos($request_uri, 'buddynext/v1/auth/lost-password');
}

/**
 * Style WordPress and programmatic reset messages from the plugin. BuddyNext
 * REST requests are delegated to the active child theme when its override is
 * registered, with this callback remaining as a safe fallback.
 */
function rts_filter_password_reset_email($defaults, $key, $user_login, $user_data = null)
{
    $child_override = has_filter(
        'retrieve_password_notification_email',
        'rts_child_buddynext_password_reset_email'
    );
    if (rts_is_buddynext_password_reset_request() && $child_override) {
        return $defaults;
    }

    return rts_apply_password_reset_email_template($defaults, $key, $user_login, $user_data);
}
add_filter('retrieve_password_notification_email', 'rts_filter_password_reset_email', 30, 4);

/** Keep the visible 60-minute expiry statement in the supplied email accurate. */
function rts_password_reset_expiration()
{
    return HOUR_IN_SECONDS;
}
add_filter('password_reset_expiration', 'rts_password_reset_expiration');

/** Return the validated network address associated with the reset request. */
function rts_password_email_request_ip()
{
    $candidates = array(
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']))[0] : '',
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    );

    foreach ($candidates as $candidate) {
        $candidate = trim((string) wp_unslash($candidate));
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    return '';
}

/** Convert the request user agent into a short, useful security description. */
function rts_password_email_device_info()
{
    $user_agent = isset($_SERVER['HTTP_USER_AGENT'])
        ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
        : '';
    if (!$user_agent) {
        return __('Unknown device', 'run-the-seas');
    }

    $browser = 'Browser';
    foreach (array('Edg/' => 'Microsoft Edge', 'OPR/' => 'Opera', 'Chrome/' => 'Google Chrome', 'Firefox/' => 'Firefox', 'Safari/' => 'Safari') as $needle => $label) {
        if (false !== strpos($user_agent, $needle)) {
            $browser = $label;
            break;
        }
    }

    $platform = 'Unknown platform';
    foreach (array('Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Macintosh' => 'macOS', 'Linux' => 'Linux') as $needle => $label) {
        if (false !== strpos($user_agent, $needle)) {
            $platform = $label;
            break;
        }
    }

    return sprintf('%s on %s', $browser, $platform);
}

/** Present the best request-local location signal without an external lookup. */
function rts_password_email_approx_location()
{
    $ip_address = rts_password_email_request_ip();
    $country = isset($_SERVER['HTTP_CF_IPCOUNTRY'])
        ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_IPCOUNTRY'])))
        : '';
    if (in_array($country, array('XX', 'T1'), true) || !preg_match('/^[A-Z]{2}$/', $country)) {
        $country = '';
    }

    if (!$ip_address || in_array($ip_address, array('127.0.0.1', '::1'), true)) {
        return __('Local or unavailable', 'run-the-seas');
    }

    return $country
        ? sprintf('%s · IP %s', $country, $ip_address)
        : sprintf('IP %s', $ip_address);
}

/** Send the supplied confirmation design only after WordPress commits a reset. */
function rts_send_password_changed_confirmation($user, $new_password)
{
    unset($new_password);
    if (!$user instanceof WP_User || !is_email($user->user_email)) {
        return;
    }

    // First-passcode creation is onboarding, not a password change. Later
    // forgotten-passcode resets still receive the security confirmation.
    if (!empty($GLOBALS['rts_first_passcode_created'][$user->ID])) {
        unset($GLOBALS['rts_first_passcode_created'][$user->ID]);
        return;
    }

    static $sent = array();
    if (isset($sent[$user->ID])) {
        return;
    }
    $sent[$user->ID] = true;

    $subject = __('Your Captain’s Suite Passcode Was Changed', 'run-the-seas');
    $message = rts_render_password_email_template('password-changed', array(
        'approx_location' => rts_password_email_approx_location(),
        'change_datetime' => wp_date('F j, Y \a\t g:i A T'),
        'device_info'     => rts_password_email_device_info(),
        'first_name'      => rts_password_email_first_name($user),
        'login_link'      => rts_get_member_login_url(home_url('/captains-suite/')),
        'logo_url'        => rts_password_email_logo_url(),
        'site_url'        => home_url('/'),
        'support_email'   => 'support@runtheseas.com',
    ));

    if ($message) {
        wp_mail(
            $user->user_email,
            $subject,
            $message,
            rts_password_email_headers()
        );
    }
}
add_action('after_password_reset', 'rts_send_password_changed_confirmation', 20, 2);

/**
 * Format the legacy stored progress units as kilometres.
 *
 * The database historically called these values "miles", but one verified
 * referral stores 1,000 units and represents one kilometre of progress.
 */
function rts_format_miles($miles)
{
    // Keep zero a float so the whole-number comparison also removes its decimal.
    $kilometres = max(0.0, (float) $miles / 1000);
    $decimals = floor($kilometres) === $kilometres ? 0 : 1;

    return number_format_i18n($kilometres, $decimals) . ' km';
}

/**
 * Format a trophy threshold as a fixed product label, never as a progress unit.
 * Keep K and the decimal point even when descriptive kilometres are localised.
 */
function rts_format_trophy_miles($miles, $trophy_key = '')
{
    $trophy_key = sanitize_key((string) $trophy_key);
    $milestone_key = preg_replace('/^m2-/', '', $trophy_key);
    if ('21k' === $milestone_key) {
        return '21.1K';
    }
    if ('42k' === $milestone_key) {
        return '42.2K';
    }
    if (str_starts_with($trophy_key, 'm2-')) {
        $miles = max(0, absint($miles) - 42000);
    }

    $kilometres = max(0, (float) $miles / 1000);
    return rtrim(rtrim(number_format($kilometres, 1, '.', ''), '0'), '.') . 'K';
}

// In run-the-seas-survey.php, add this check
function rts_check_pending_processing()
{
    // The registration endpoint sets this short-lived hint. Avoid scanning the
    // options table during every administration request when no work exists.
    if (is_admin() && get_transient('rts_pending_registration_hint')) {
        if (!get_transient('rts_pending_registration_lock')) {
            rts_process_pending_registrations();
        } else {
            error_log('RTS: Pending registration processing already running, skipping admin_init trigger');
        }
    }
}
// Remove the existing admin_init hook and add this instead
remove_action('admin_init', 'rts_process_pending_registrations');
add_action('admin_init', 'rts_check_pending_processing');


// Add this after the plugin class definition (before the initialization)


/**
 * Restrict user login if not verified and survey not completed
 */
function rts_restrict_user_login($user, $username, $password = null)
{
    // If $user is a WP_Error, return it immediately
    if (is_wp_error($user)) {
        return $user;
    }

    // Administration accounts are not survey participants and do not need
    // survey registration or its age confirmation to sign in.
    if (user_can($user, 'manage_options') || rts_is_run_the_seas_admin($user)) {
        return $user;
    }

    // Get participant data
    $registration = new RTS_Registration();
    $participant = $registration->get_participant_for_user($user);

    if ($participant) {
        $email_verified = $participant->email_verified == 1;
        $survey_completed = $participant->survey_tracking_id > 0;

        // Check if email is verified
        if (!$email_verified) {
            $error = new WP_Error(
                'bn_pending_approval',
                __('<strong>Email Verification Required</strong><br>Please verify your email address to login. Check your inbox for the verification link, or <a href="' . add_query_arg('rts_resend_verification', '1', home_url()) . '">click here to resend</a>.', 'run-the-seas')
            );
            return $error;
        }

        // Check if survey was completed (if participant exists but no survey tracking)
        if (!$survey_completed) {
            $error = new WP_Error(
                'bn_pending_approval',
                __('<strong>Survey Required</strong><br>You need to complete the survey before you can access your account. <a href="/survey">Take the survey now</a>.', 'run-the-seas')
            );
            return $error;
        }
    }

    return $user;
}
add_filter('wp_authenticate_user', 'rts_restrict_user_login', 10, 3);


/**
 * Redirect non-verified users to verification page
 */
function rts_redirect_non_verified_users()
{
    if (!is_user_logged_in()) {
        return;
    }

    // Apply participant prerequisites only to member accounts.
    if (current_user_can('manage_options') || rts_is_run_the_seas_admin()) {
        return;
    }

    // Skip for AJAX requests
    if (wp_doing_ajax()) {
        return;
    }

    // Get current URL FIRST
    $current_url = $_SERVER['REQUEST_URI'];

    // Add these paths to the skip list
    $allowed_paths = array(
        'survey',
        'register',
        'verify-email',
        'password-reset',
        'log-in',
        'login',
        'rts_verify_email',
        'rts_resend_verification'
    );

    // Check if current URL is in allowed paths
    foreach ($allowed_paths as $path) {
        if (strpos($current_url, $path) !== false) {
            return;
        }
    }

    // Skip for login page and verification handler
    if (
        strpos($current_url, 'wp-login.php') !== false ||
        strpos($current_url, 'rts_verify_email') !== false ||
        strpos($current_url, 'rts_resend_verification') !== false
    ) {
        return;
    }

    $user = wp_get_current_user();
    $registration = new RTS_Registration();
    $participant = $registration->get_participant_for_user($user);

    if ($participant) {
        $email_verified = $participant->email_verified == 1;
        $survey_completed = $participant->survey_tracking_id > 0;

        // If email not verified, redirect to verification page
        if (!$email_verified) {
            // Check if this is a survey page - allow access to complete survey
            if (is_page('survey') || strpos($current_url, 'survey') !== false) {
                return;
            }

            // The verification notice itself lives on this page. Once the
            // marker is present, allow the request to render instead of
            // redirecting the page back to its own URL indefinitely.
            if (
                isset($_GET['rts_verification_required'])
                && (is_page('captains-suite') || is_page('captain-suite'))
            ) {
                return;
            }

            wp_safe_redirect(add_query_arg('rts_verification_required', '1', home_url('/captains-suite/')));
            exit;
        }

        // If survey not completed, redirect to survey
        if (!$survey_completed) {
            // Allow access to survey page
            if (is_page('survey') || strpos($current_url, 'survey') !== false) {
                return;
            }

            wp_redirect(home_url('/survey?rts_survey_required=1'));
            exit;
        }
    }
}
add_action('template_redirect', 'rts_redirect_non_verified_users', 1);

/**
 * Show verification required notice on Captain's Suite
 */
function rts_show_verification_required_notice()
{
    if (!is_page('captains-suite') && !is_page('captain-suite')) {
        return;
    }

    if (!is_user_logged_in()) {
        return;
    }

    // Check if verification required flag is set
    if (isset($_GET['rts_verification_required'])) {
        $user = wp_get_current_user();
        $registration = new RTS_Registration();
        $participant = $registration->get_participant_for_user($user);
    ?>
        <div style="
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 12px;
            padding: 30px;
            margin: 20px auto;
            max-width: 800px;
            text-align: center;
        ">
            <div style="font-size: 48px;">🔓</div>
            <h2 style="color: #856404;">Email Verification Required</h2>
            <p style="font-size: 16px; color: #856404;">
                Please verify your email address to access your account.
            </p>
            <?php if ($participant): ?>
                <p style="color: #856404;">
                    A verification email was sent to <strong><?php echo esc_html($participant->email); ?></strong>.
                    Please check your inbox and spam folder.
                </p>
            <?php endif; ?>
            <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap; margin-top: 20px;">
                <a href="<?php echo add_query_arg('rts_resend_verification', '1', home_url()); ?>"
                    style="display: inline-block; padding: 12px 30px; background: #1a7efb; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600;">
                    Resend Verification Email
                </a>
                <a href="/survey"
                    style="display: inline-block; padding: 12px 30px; background: #28a745; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600;">
                    Take Survey
                </a>
                <a href="<?php echo wp_logout_url(home_url()); ?>"
                    style="display: inline-block; padding: 12px 30px; background: #6c757d; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600;">
                    Logout
                </a>
            </div>
        </div>
        <?php
        // Remove the flag to prevent showing again
        if (isset($_GET['rts_verification_required'])) {
        ?>
            <script>
                if (window.history && window.history.replaceState) {
                    window.history.replaceState({}, document.title, window.location.pathname + window.location.search.replace(/[?&]rts_verification_required=[^&]*/, ''));
                }
            </script>
        <?php
        }
    }
}
add_action('wp_body_open', 'rts_show_verification_required_notice');

/**
 * Show survey required notice
 */
function rts_show_survey_required_notice()
{
    if (!is_page('captains-suite') && !is_page('captain-suite')) {
        return;
    }

    if (!is_user_logged_in()) {
        return;
    }

    if (isset($_GET['rts_survey_required'])) {
        ?>
        <div style="
            background: #cce5ff;
            border: 2px solid #1a7efb;
            border-radius: 12px;
            padding: 30px;
            margin: 20px auto;
            max-width: 800px;
            text-align: center;
        ">
            <div style="font-size: 48px;">📋</div>
            <h2 style="color: #004085;">Survey Required</h2>
            <p style="font-size: 16px; color: #004085;">
                Please complete the survey to access your account.
            </p>
            <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap; margin-top: 20px;">
                <a href="/survey"
                    style="display: inline-block; padding: 12px 30px; background: #1a7efb; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600;">
                    Take Survey Now
                </a>
                <a href="<?php echo wp_logout_url(home_url()); ?>"
                    style="display: inline-block; padding: 12px 30px; background: #6c757d; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600;">
                    Logout
                </a>
            </div>
        </div>
        <?php
        // Remove the flag
        if (isset($_GET['rts_survey_required'])) {
        ?>
            <script>
                if (window.history && window.history.replaceState) {
                    window.history.replaceState({}, document.title, window.location.pathname + window.location.search.replace(/[?&]rts_survey_required=[^&]*/, ''));
                }
            </script>
<?php
        }
    }
}
add_action('wp_body_open', 'rts_show_survey_required_notice');

/**
 * Check if user has completed survey during registration
 */
function rts_check_survey_completed_on_registration($participant_id)
{
    global $wpdb;

    $participant = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rts_participants WHERE id = %d",
            $participant_id
        )
    );

    if (!$participant) {
        return;
    }

    // Registrations from the survey already carry the authoritative tracking ID.
    // Do not replace it by looking up a survey through the email address.
    if (!empty($participant->survey_tracking_id)) {
        return;
    }

    // Check if survey was completed
    $survey_completed = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rts_survey_tracking 
            WHERE email = %s AND completion_status = 'completed'",
            $participant->email
        )
    );

    if ($survey_completed) {
        $wpdb->update(
            $wpdb->prefix . 'rts_participants',
            array('survey_tracking_id' => $survey_completed),
            array('id' => $participant_id)
        );
    }
}
add_action('rts_registration_completed', 'rts_check_survey_completed_on_registration');

/**
 * Add survey tracking ID to participants table
 */
function rts_add_survey_tracking_id_column()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'rts_participants';

    $column_exists = $wpdb->get_var(
        "SHOW COLUMNS FROM $table_name LIKE 'survey_tracking_id'"
    );

    if (!$column_exists) {
        $wpdb->query(
            "ALTER TABLE $table_name ADD COLUMN survey_tracking_id bigint(20) DEFAULT NULL AFTER user_id"
        );
        error_log('RTS: Added survey_tracking_id column to participants table');
    }
}

/** Apply the legacy participant/tracking relationship column once. */
function rts_maybe_upgrade_survey_tracking_link_schema()
{
    $schema_version = '1.0';
    if (get_option('rts_survey_tracking_link_schema_version') === $schema_version) {
        return;
    }
    if (!rts_acquire_upgrade_lock('survey_tracking_link_schema')) {
        return;
    }

    try {
        rts_add_survey_tracking_id_column();
        update_option('rts_survey_tracking_link_schema_version', $schema_version, false);
    } finally {
        rts_release_upgrade_lock('survey_tracking_link_schema');
    }
}
add_action('plugins_loaded', 'rts_maybe_upgrade_survey_tracking_link_schema', 30);

/**
 * Update survey tracking ID when survey is completed
 */
function rts_update_survey_tracking_on_complete($tracking_id)
{
    global $wpdb;

    $tracking = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rts_survey_tracking WHERE id = %d",
            $tracking_id
        )
    );

    if ($tracking && !empty($tracking->email)) {
        $wpdb->update(
            $wpdb->prefix . 'rts_participants',
            array('survey_tracking_id' => $tracking_id),
            array('email' => $tracking->email)
        );
    }
}
add_action('rts_survey_completed', 'rts_update_survey_tracking_on_complete');


add_action('template_redirect', function () {

    if (
        isset($_GET['step']) &&
        $_GET['step'] === 'reset'
    ) {
        nocache_headers();

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
    }

}, 1);


/**
 * Check for pending registration and show notice on any page
 */
function rts_check_pending_registration_notice() {
    // Don't show on admin, AJAX, or login page
    if (is_admin() || wp_doing_ajax() || is_login_page()) {
        return;
    }
    
    // Don't show if user is logged in
    if (is_user_logged_in()) {
        return;
    }
    
    // Don't show on registration or survey page (they handle it themselves)
    if (is_page('register') || is_page('registration') || is_page('survey')) {
        return;
    }
    
    $tracking_id = (
        isset($_COOKIE['rts_survey_cookie_consent']) &&
        $_COOKIE['rts_survey_cookie_consent'] === 'accepted' &&
        isset($_COOKIE['rts_tracking_id'])
    ) ? intval($_COOKIE['rts_tracking_id']) : 0;
    if (!$tracking_id) {
        return;
    }
    
    global $wpdb;
    $tracking = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rts_survey_tracking 
            WHERE id = %d AND completion_status = 'completed'",
            $tracking_id
        )
    );
    
    if ($tracking) {
        // Check if already registered
        $registered = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}rts_participants WHERE survey_tracking_id = %d",
                $tracking_id
            )
        );
        
        if (!$registered) {
            ?>
            <div style="
                background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%);
                border: 2px solid #ffc107;
                border-radius: 12px;
                padding: 20px 25px;
                margin: 20px auto;
                max-width: 700px;
                box-shadow: 0 4px 20px rgba(255, 193, 7, 0.2);
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 15px;
            ">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <span style="font-size: 32px;">🎉</span>
                    <div>
                        <p style="margin: 0; color: #856404; font-size: 16px; font-weight: 600;">
                            You completed the survey!
                        </p>
                        <p style="margin: 5px 0 0; color: #856404; font-size: 14px;">
                            Claim your $100 credit by completing your registration.
                        </p>
                    </div>
                </div>
                <a href="<?php echo add_query_arg('tracking_id', $tracking_id, home_url('/register')); ?>" 
                   style="
                       display: inline-block;
                       padding: 10px 30px;
                       background: #1a7efb;
                       color: #fff;
                       text-decoration: none;
                       border-radius: 8px;
                       font-weight: 600;
                       font-size: 15px;
                       white-space: nowrap;
                       transition: all 0.3s ease;
                   "
                   onmouseover="this.style.background='#1565c0'; this.style.transform='translateY(-2px)';"
                   onmouseout="this.style.background='#1a7efb'; this.style.transform='translateY(0)';">
                    Claim Your Credit →
                </a>
            </div>
            <?php
        }
    }
}
add_action('wp_body_open', 'rts_check_pending_registration_notice');
