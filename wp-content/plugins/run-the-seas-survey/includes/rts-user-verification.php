<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether a user is a limited, front-end-only Run The Seas administrator.
 */
function rts_is_limited_admin($user = null)
{
    $user = $user instanceof WP_User ? $user : wp_get_current_user();

    return $user && $user->exists()
		&& function_exists('rts_is_run_the_seas_admin')
		&& rts_is_run_the_seas_admin($user)
        && !user_can($user, 'manage_options');
}

/**
 * Whether the user has WordPress's built-in Administrator role.
 *
 * Check the role itself instead of manage_options or the RTS capability so
 * custom administrative roles never receive the front-end WordPress toolbar.
 */
function rts_is_wordpress_administrator($user = null)
{
    $user = $user instanceof WP_User ? $user : wp_get_current_user();

    return $user && $user->exists()
        && in_array('administrator', (array) $user->roles, true);
}

/**
 * Show the WordPress toolbar only to the built-in Administrator role.
 */
function rts_hide_admin_bar_by_capability($show_admin_bar)
{
    return rts_is_wordpress_administrator();
}
add_filter('show_admin_bar', 'rts_hide_admin_bar_by_capability', 999);

/**
 * Remove any toolbar markup or margin that a theme/plugin adds on the front end.
 */
function rts_remove_frontend_admin_bar_markup()
{
    if (is_admin() || rts_is_wordpress_administrator()) {
        return;
    }

    echo '<style id="rts-hide-admin-bar">#wpadminbar{display:none!important}html{margin-top:0!important}</style>';
}
add_action('wp_head', 'rts_remove_frontend_admin_bar_markup', 999);

/**
 * Redirect users without the Run The Seas management capability away from admin.
 */
function rts_redirect_non_admins_from_admin()
{
	global $pagenow;
	$is_frontend_action_endpoint = in_array($pagenow, array('admin-post.php', 'admin-ajax.php', 'async-upload.php'), true);
	$is_rtsap_fluent_embed = class_exists('RTSAP_Frontend_Dashboard')
		&& RTSAP_Frontend_Dashboard::is_allowed_fluent_embed_request();
    $is_rts_settings_save = isset($_POST['option_page'])
        && 'rts_survey_settings_group' === sanitize_key(wp_unslash($_POST['option_page']));
    $request_action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
    $is_member_certificate_request = in_array($request_action, array(
        'rts_view_member_certificate',
        'rts_download_member_certificate',
        'rts_resend_member_certificate',
    ), true);

    if (is_user_logged_in() && rts_is_limited_admin() && is_admin() && !wp_doing_ajax() && !$is_frontend_action_endpoint && !$is_rtsap_fluent_embed && !$is_rts_settings_save && !$is_member_certificate_request) {
		$profile_url = function_exists('rts_get_admin_dashboard_url')
			? rts_get_admin_dashboard_url()
			: home_url('/');

        wp_safe_redirect($profile_url);
        exit;
    }

    if (is_user_logged_in() && !current_user_can(RTS_MANAGE_CAPABILITY) && !rts_is_limited_admin() && is_admin() && !wp_doing_ajax() && !$is_frontend_action_endpoint && !$is_rtsap_fluent_embed && !$is_member_certificate_request) {
        // Don't redirect if it's a login page or password reset
        $current_url = $_SERVER['REQUEST_URI'];
        if (
            strpos($current_url, 'wp-login.php') !== false ||
            strpos($current_url, 'rts_verify_email') !== false ||
            strpos($current_url, 'admin-ajax.php') !== false
        ) {
            return;
        }
        wp_redirect(home_url('/captains-suite'));
        exit;
    }
}
add_action('admin_init', 'rts_redirect_non_admins_from_admin');

/**
 * Show email verification notice on all frontend pages
 * Only for logged-in users with unverified email
 */
function rts_show_verification_notice_frontend()
{
    // Don't show on admin pages, login page, or registration page
    if (is_admin() || is_login_page() || is_page('register') || is_page('registration')) {
        return;
    }

    if (!is_user_logged_in()) {
        return;
    }

    $user = wp_get_current_user();
    $registration = new RTS_Registration();
    $participant = $registration->get_participant_for_user($user);

    // Only show if email is not verified
    if ($participant && $participant->email_verified == 0) {
?>
        <div class="rts-verification-banner" style="
            background: #fff3cd;
            border-bottom: 3px solid #ffc107;
            padding: 12px 20px;
            text-align: center;
            position: sticky;
            top: 0;
            z-index: 9999;
            box-shadow: 0 2px 10px rgba(255, 193, 7, 0.3);
        ">
            <div style="max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: center; gap: 15px; flex-wrap: wrap;">
                <span style="font-size: 20px;">⚠️</span>
                <span style="color: #856404; font-size: 14px;">
                    <strong>Email Verification Required:</strong>
                    Please verify your email to unlock all Founding Runner benefits.
                </span>
                <a href="<?php echo add_query_arg('rts_resend_verification', '1', home_url()); ?>"
                    style="display: inline-block; padding: 6px 20px; background: #ffc107; color: #333; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 13px; border: none; cursor: pointer;">
                    Resend Email
                </a>
                <a href="/captains-suite"
                    style="display: inline-block; padding: 6px 20px; background: #1a7efb; color: #fff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 13px;">
                    Go to Captain's Suite
                </a>
                <span style="font-size: 12px; color: #856404; opacity: 0.7;">
                    <a href="#" onclick="this.parentElement.parentElement.style.display='none'; return false;" style="color: #856404; text-decoration: none;">✕ Dismiss</a>
                </span>
            </div>
        </div>
    <?php
    }
}
add_action('wp_body_open', 'rts_show_verification_notice_frontend');

/**
 * Check if current page is login page
 */
function is_login_page()
{
    return in_array($GLOBALS['pagenow'], array('wp-login.php', 'wp-register.php'));
}

/**
 * Show prominent verification notice on Captain's Suite page
 */
function rts_show_prominent_verification_notice()
{
    if (!is_page('captains-suite') && !is_page('captain-suite')) {
        return;
    }

    if (!is_user_logged_in()) {
        return;
    }

    $user = wp_get_current_user();
    $registration = new RTS_Registration();
    $participant = $registration->get_participant_for_user($user);

    if ($participant && $participant->email_verified == 0) {
    ?>
        <div style="
            background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%);
            border: 2px solid #ffc107;
            border-radius: 12px;
            padding: 30px;
            margin: 20px auto;
            max-width: 800px;
            box-shadow: 0 4px 20px rgba(255, 193, 7, 0.2);
        ">
            <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                <div style="font-size: 48px;">🔓</div>
                <div style="flex: 1;">
                    <h3 style="margin: 0 0 5px 0; color: #856404; font-size: 20px;">Unlock Your Full Benefits</h3>
                    <p style="margin: 0; color: #856404; font-size: 15px;">
                        Your email is not verified yet. Verify your email to access:
                    </p>
                    <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 10px;">
                        <span style="background: #fff; padding: 4px 12px; border-radius: 20px; font-size: 13px; border: 1px solid #ffc107;">
                            🏅 Cabin Credit Approval
                        </span>
                        <span style="background: #fff; padding: 4px 12px; border-radius: 20px; font-size: 13px; border: 1px solid #ffc107;">
                            ⭐ Verified Referral Progress
                        </span>
                        <span style="background: #fff; padding: 4px 12px; border-radius: 20px; font-size: 13px; border: 1px solid #ffc107;">
                            🏆 Achievements
                        </span>
                    </div>
                </div>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <a href="<?php echo add_query_arg('rts_resend_verification', '1', home_url()); ?>"
                        style="display: inline-block; padding: 10px 30px; background: #1a7efb; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 15px; text-align: center;">
                        Verify Email Now
                    </a>
                    <span style="font-size: 12px; color: #856404; text-align: center;">
                        Check your inbox and spam folder
                    </span>
                </div>
            </div>
        </div>
    <?php
    }
}
add_action('wp_body_open', 'rts_show_prominent_verification_notice');

/**
 * Show verification notice after registration
 */
function rts_show_verification_after_registration()
{
    if (!isset($_GET['registration_complete'])) {
        return;
    }

    if (!is_user_logged_in()) {
        return;
    }

    $user = wp_get_current_user();
    $registration = new RTS_Registration();
    $participant = $registration->get_participant_for_user($user);

    if ($participant && $participant->email_verified == 0) {
    ?>
        <div style="
            background: #d4edda;
            border: 2px solid #28a745;
            border-radius: 12px;
            padding: 30px;
            margin: 20px auto;
            max-width: 700px;
            text-align: center;
        ">
            <div style="font-size: 48px;">🎉</div>
            <h2 style="color: #155724; margin: 10px 0;">Registration Complete!</h2>
            <p style="color: #155724; font-size: 16px;">
                Your account has been created successfully. Please check your email to verify your account.
            </p>
            <div style="background: #fff; border-radius: 8px; padding: 20px; margin: 20px 0; text-align: left;">
                <h4 style="margin-top: 0; color: #333;">📧 Next Steps:</h4>
                <ol style="margin: 0; padding-left: 20px; color: #555;">
                    <li style="margin-bottom: 8px;">Check your email for the verification link</li>
                    <li style="margin-bottom: 8px;">Click the link to verify your email address</li>
                    <li>Unlock all Founding Runner benefits after verification</li>
                </ol>
            </div>
            <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                <a href="<?php echo add_query_arg('rts_resend_verification', '1', home_url()); ?>"
                    style="display: inline-block; padding: 10px 30px; background: #1a7efb; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600;">
                    Resend Verification Email
                </a>
                <a href="/captains-suite"
                    style="display: inline-block; padding: 10px 30px; background: #28a745; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600;">
                    Go to Captain's Suite
                </a>
            </div>
        </div>
        <?php
    }
}
add_action('wp_body_open', 'rts_show_verification_after_registration');


/**
 * Handle resend verification email request - Frontend redirect
 */
function rts_handle_resend_verification_frontend()
{
    if (!isset($_GET['rts_resend_verification'])) {
        return;
    }

    if (!is_user_logged_in()) {
        wp_redirect(home_url());
        exit;
    }

    $user = wp_get_current_user();
    $registration = new RTS_Registration();
    $participant = $registration->get_participant_for_user($user);

    if ($participant && $participant->email_verified == 0) {
        // This is an explicit member request. Force a real resend even when a
        // previous verification email exists in the timeline.
        $sent = $registration->send_verification_email($participant->id, true);

        if ($sent) {
            // Store message in transient
            set_transient('rts_verification_message_' . $user->ID, 'sent', 60);
        } else {
            set_transient('rts_verification_message_' . $user->ID, 'failed', 60);
        }
    }

    // Redirect back to the referring page
    $referer = wp_get_referer();
    if (!$referer) {
        $referer = home_url('/captains-suite');
    }
    wp_redirect($referer);
    exit;
}
add_action('init', 'rts_handle_resend_verification_frontend');

/**
 * Resend a verification email directly after registration, before the visitor
 * has a verified account or an authenticated WordPress session.
 */
function rts_handle_registration_resend_verification()
{
    if (empty($_GET['rts_registration_resend'])) {
        return;
    }

    $token = sanitize_text_field(wp_unslash($_GET['rts_registration_resend']));
    $resend_data = get_transient('rts_registration_resend_' . $token);
    $redirect_url = home_url('/register');

    if (!$resend_data || empty($resend_data['participant_id'])) {
        wp_safe_redirect(add_query_arg('rts_verification_resend', 'expired', $redirect_url));
        exit;
    }

    delete_transient('rts_registration_resend_' . $token);

    $registration = new RTS_Registration();
    $sent = $registration->send_verification_email(intval($resend_data['participant_id']), true);

    wp_safe_redirect(add_query_arg('rts_verification_resend', $sent ? 'sent' : 'failed', $redirect_url));
    exit;
}
add_action('init', 'rts_handle_registration_resend_verification', 1);

/**
 * Show verification email status messages on frontend
 */
function rts_show_verification_status_messages()
{
    if (!is_user_logged_in()) {
        return;
    }

    $user = wp_get_current_user();
    $message = get_transient('rts_verification_message_' . $user->ID);

    if ($message) {
        delete_transient('rts_verification_message_' . $user->ID);

        if ($message === 'sent') {
        ?>
            <div style="
                background: #d4edda;
                border: 1px solid #28a745;
                border-radius: 8px;
                padding: 15px 20px;
                margin: 20px auto;
                max-width: 800px;
            ">
                <p style="margin: 0; color: #155724;">
                    ✅ Verification email has been resent! Please check your inbox and spam folder.
                </p>
            </div>
        <?php
        } elseif ($message === 'failed') {
        ?>
            <div style="
                background: #f8d7da;
                border: 1px solid #dc3545;
                border-radius: 8px;
                padding: 15px 20px;
                margin: 20px auto;
                max-width: 800px;
            ">
                <p style="margin: 0; color: #721c24;">
                    ❌ Failed to send verification email. Please try again or contact support.
                </p>
            </div>
    <?php
        }
    }
}
add_action('wp_body_open', 'rts_show_verification_status_messages', 5);

//admin display for email verification:

/**
 * Add custom columns to WordPress Users list
 */
function rts_add_user_columns($columns)
{
    // Add column after 'email'
    $new_columns = array();
    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;
        if ($key === 'email') {
            $new_columns['rts_verified'] = __('Email Verified', 'run-the-seas');
            $new_columns['rts_verification_date'] = __('Verification Date', 'run-the-seas');
        }
    }
    return $new_columns;
}
add_filter('manage_users_columns', 'rts_add_user_columns');

/**
 * Display verification status in the custom column
 */
function rts_display_user_column($value, $column_name, $user_id)
{
    if ($column_name === 'rts_verified') {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_participants';

        $participant = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT email_verified FROM $table WHERE user_id = %d",
                $user_id
            )
        );

        if ($participant) {
            if ($participant->email_verified == 1) {
                return '<span style="color: #28a745; font-weight: 600;">✅ Verified</span>';
            } else {
                return '<span style="color: #856404; font-weight: 600;">⏳ Pending</span>';
            }
        }
        return '<span style="color: #999;">—</span>';
    }

    if ($column_name === 'rts_verification_date') {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_participants';

        $participant = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT email_verification_date FROM $table WHERE user_id = %d",
                $user_id
            )
        );

        if ($participant && $participant->email_verification_date) {
            return date_i18n('Y-m-d H:i', strtotime($participant->email_verification_date));
        }
        return '<span style="color: #999;">—</span>';
    }

    return $value;
}
add_action('manage_users_custom_column', 'rts_display_user_column', 10, 3);

/**
 * Make the verification column sortable
 */
function rts_sortable_user_columns($columns)
{
    $columns['rts_verified'] = 'rts_verified';
    $columns['rts_verification_date'] = 'rts_verification_date';
    return $columns;
}
add_filter('manage_users_sortable_columns', 'rts_sortable_user_columns');

/**
 * Handle sorting for verification columns
 */
function rts_sort_users_by_verification($query)
{
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    $orderby = $query->get('orderby');

    if ($orderby === 'rts_verified') {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_participants';

        // Modify the query to join with participants table
        add_filter('users_pre_query', function ($results, $query) use ($wpdb, $table) {
            if ($query->get('orderby') === 'rts_verified') {
                // Get user IDs ordered by verification status
                $user_ids = $wpdb->get_col(
                    "SELECT u.ID 
                    FROM {$wpdb->users} u 
                    LEFT JOIN {$table} p ON u.ID = p.user_id 
                    ORDER BY p.email_verified DESC, u.user_registered DESC"
                );

                if (!empty($user_ids)) {
                    // Set the results to the ordered users
                    $users = array();
                    foreach ($user_ids as $id) {
                        $users[] = get_userdata($id);
                    }
                    $query->set('orderby', '');
                    $query->set('order', '');
                    return $users;
                }
            }
            return $results;
        }, 10, 2);
    }

    if ($orderby === 'rts_verification_date') {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_participants';

        add_filter('users_pre_query', function ($results, $query) use ($wpdb, $table) {
            if ($query->get('orderby') === 'rts_verification_date') {
                $order = $query->get('order') === 'DESC' ? 'DESC' : 'ASC';
                $user_ids = $wpdb->get_col(
                    "SELECT u.ID 
                    FROM {$wpdb->users} u 
                    LEFT JOIN {$table} p ON u.ID = p.user_id 
                    ORDER BY p.email_verification_date {$order}, u.user_registered DESC"
                );

                if (!empty($user_ids)) {
                    $users = array();
                    foreach ($user_ids as $id) {
                        $users[] = get_userdata($id);
                    }
                    $query->set('orderby', '');
                    $query->set('order', '');
                    return $users;
                }
            }
            return $results;
        }, 10, 2);
    }
}
add_action('pre_get_users', 'rts_sort_users_by_verification');

/**
 * Add quick action link to resend verification email from user list
 */
function rts_add_user_action_links($actions, $user_object)
{
    global $wpdb;
    $table = $wpdb->prefix . 'rts_participants';

    $participant = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, email_verified FROM $table WHERE user_id = %d",
            $user_object->ID
        )
    );

    if ($participant && $participant->email_verified == 0) {
        $actions['rts_resend_verification'] = sprintf(
            '<a href="%s" style="color: #856404;">%s</a>',
            wp_nonce_url(
                admin_url('admin-post.php?action=rts_admin_resend_verification&user_id=' . $user_object->ID),
                'rts_resend_verification_' . $user_object->ID
            ),
            __('Resend Verification', 'run-the-seas')
        );

        $actions['rts_manual_verify'] = sprintf(
            '<a href="%s" style="color: #28a745;">%s</a>',
            wp_nonce_url(
                admin_url('admin-post.php?action=rts_admin_manual_verify&user_id=' . $user_object->ID),
                'rts_manual_verify_' . $user_object->ID
            ),
            __('Verify Manually', 'run-the-seas')
        );
    }

    return $actions;
}
add_filter('user_row_actions', 'rts_add_user_action_links', 10, 2);

/** Return whether the current administrator may manage member verification. */
function rts_can_manage_member_verification()
{
    return current_user_can('edit_users')
        || (defined('RTS_MANAGE_CAPABILITY') && current_user_can(RTS_MANAGE_CAPABILITY));
}

/** Return a participant by its linked WordPress user ID. */
function rts_get_participant_for_verification_user($user_id)
{
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rts_participants WHERE user_id = %d LIMIT 1",
            $user_id
        )
    );
}

/** Redirect an admin verification action back to the Users screen. */
function rts_redirect_verification_admin_action($status)
{
    wp_safe_redirect(
        add_query_arg(
            'rts_verification_action',
            sanitize_key($status),
            admin_url('users.php')
        )
    );
    exit;
}

/** Handle the Users screen "Resend Verification" action. */
function rts_admin_resend_verification()
{
    if (!rts_can_manage_member_verification()) {
        wp_die(esc_html__('You do not have permission to resend verification emails.', 'run-the-seas'), '', array('response' => 403));
    }

    $user_id = absint($_GET['user_id'] ?? 0);
    check_admin_referer('rts_resend_verification_' . $user_id);

    $participant = rts_get_participant_for_verification_user($user_id);
    if (!$participant) {
        rts_redirect_verification_admin_action('participant_not_found');
    }
    $participant = rts_sync_linked_participant_email($user_id, false, false);
    if (is_wp_error($participant)) {
        rts_redirect_verification_admin_action('email_sync_failed');
    }
    if ((int) $participant->email_verified === 1) {
        rts_redirect_verification_admin_action('already_verified');
    }

    $registration = new RTS_Registration();
    if (!$registration->send_verification_email((int) $participant->id, true)) {
        rts_redirect_verification_admin_action('resend_failed');
    }

    $registration->log_timeline(
        (int) $participant->id,
        'verification_resent_by_admin',
        'Verification email resent from the WordPress Users screen',
        array('admin_id' => get_current_user_id())
    );
    rts_redirect_verification_admin_action('resent');
}
add_action('admin_post_rts_admin_resend_verification', 'rts_admin_resend_verification');

/** Handle the Users screen "Verify Manually" action. */
function rts_admin_manual_verify()
{
    if (!rts_can_manage_member_verification()) {
        wp_die(esc_html__('You do not have permission to verify members.', 'run-the-seas'), '', array('response' => 403));
    }

    $user_id = absint($_GET['user_id'] ?? 0);
    check_admin_referer('rts_manual_verify_' . $user_id);

    $participant = rts_get_participant_for_verification_user($user_id);
    if (!$participant) {
        rts_redirect_verification_admin_action('participant_not_found');
    }
    $participant = rts_sync_linked_participant_email($user_id, false, false);
    if (is_wp_error($participant)) {
        rts_redirect_verification_admin_action('email_sync_failed');
    }

    global $wpdb;
    $now = current_time('mysql');
    $updated = $wpdb->update(
        $wpdb->prefix . 'rts_participants',
        array(
            'email_verified' => 1,
            'email_verification_date' => $now,
            'updated_at' => $now,
        ),
        array('id' => (int) $participant->id),
        array('%d', '%s', '%s'),
        array('%d')
    );
    if (false === $updated) {
        rts_redirect_verification_admin_action('verify_failed');
    }

    update_user_meta($user_id, 'rts_email_verified', '1');

    $registration = new RTS_Registration();
    if ((int) $participant->email_verified !== 1) {
        $registration->log_timeline(
            (int) $participant->id,
            'manual_email_verified',
            'Email verified manually from the WordPress Users screen',
            array('admin_id' => get_current_user_id())
        );
        do_action('rts_participant_verified', (int) $participant->id);
    }

    // Re-enable the suite after an email-address change while preserving the
    // existing credit/certificate numbers through the idempotent service.
    $benefits = $registration->activate_verified_benefits(
        (int) $participant->id,
        get_current_user_id(),
        true
    );
    if (is_wp_error($benefits)) {
        rts_redirect_verification_admin_action('benefits_failed');
    }

    rts_redirect_verification_admin_action('verified');
}
add_action('admin_post_rts_admin_manual_verify', 'rts_admin_manual_verify');

/** Display the result of a Users screen verification action. */
function rts_verification_admin_action_notice()
{
    if (empty($_GET['rts_verification_action'])) {
        return;
    }

    $status = sanitize_key(wp_unslash($_GET['rts_verification_action']));
    $messages = array(
        'resent' => array('success', __('Verification email resent successfully.', 'run-the-seas')),
        'verified' => array('success', __('The member email is verified and the Captain’s Suite is active.', 'run-the-seas')),
        'already_verified' => array('info', __('That member email is already verified.', 'run-the-seas')),
        'participant_not_found' => array('error', __('No linked Run The Seas participant was found.', 'run-the-seas')),
        'email_sync_failed' => array('error', __('The WordPress and Run The Seas emails could not be synchronized. Check that the address is not assigned to another participant.', 'run-the-seas')),
        'resend_failed' => array('error', __('The verification email could not be sent. Check the mail log and SMTP configuration.', 'run-the-seas')),
        'verify_failed' => array('error', __('The member verification status could not be saved.', 'run-the-seas')),
        'benefits_failed' => array('error', __('The email was verified, but the Captain’s Suite could not be reactivated.', 'run-the-seas')),
    );
    if (!isset($messages[$status])) {
        return;
    }

    printf(
        '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
        esc_attr($messages[$status][0]),
        esc_html($messages[$status][1])
    );
}
add_action('admin_notices', 'rts_verification_admin_action_notice');


/**
 * Add filter dropdown to users list
 */
function rts_add_user_filter_dropdown()
{
    $screen = get_current_screen();
    if ($screen->base !== 'users') {
        return;
    }
    ?>
    <div class="alignleft actions" style="margin-right: 10px;">
        <select name="rts_verification_filter" style="float: none; margin-left: 0;">
            <option value=""><?php _e('All Verification Status', 'run-the-seas'); ?></option>
            <option value="verified" <?php selected(isset($_GET['rts_verification_filter']) && $_GET['rts_verification_filter'] === 'verified'); ?>>
                ✅ Verified
            </option>
            <option value="unverified" <?php selected(isset($_GET['rts_verification_filter']) && $_GET['rts_verification_filter'] === 'unverified'); ?>>
                ⏳ Pending
            </option>
        </select>
        <?php submit_button(__('Filter'), 'button', 'filter_action', false); ?>
    </div>
    <?php
}
add_action('manage_users_extra_tablenav', 'rts_add_user_filter_dropdown', 10, 1);

/**
 * Filter users by verification status
 */
function rts_filter_users_by_verification($query)
{
    global $pagenow;

    if (!is_admin() || $pagenow !== 'users.php') {
        return;
    }

    if (!isset($_GET['rts_verification_filter']) || empty($_GET['rts_verification_filter'])) {
        return;
    }

    $filter = sanitize_text_field($_GET['rts_verification_filter']);

    if ($filter === 'verified') {
        $meta_query = array(
            array(
                'key' => 'rts_email_verified',
                'value' => '1',
                'compare' => '='
            )
        );
        $query->set('meta_query', $meta_query);
    } elseif ($filter === 'unverified') {
        $meta_query = array(
            array(
                'key' => 'rts_email_verified',
                'value' => '0',
                'compare' => '='
            )
        );
        $query->set('meta_query', $meta_query);
    }
}
add_action('pre_get_users', 'rts_filter_users_by_verification');

/**
 * Process pending registrations in background
 * This runs on every page load but only processes if there are pending items
 */
function rts_process_pending_registrations()
{
    // Only run in admin or via cron
    if (!is_admin() && !defined('DOING_CRON')) {
        return;
    }

    // Use a lock to prevent concurrent execution
    $lock_key = 'rts_pending_registration_lock';
    if (get_transient($lock_key)) {
        error_log('RTS: Pending registration processor already running, skipping...');
        return;
    }

    // Set lock for 5 minutes
    set_transient($lock_key, true, 300);

    global $wpdb;
    $pending = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name REGEXP '^rts_pending_registration_[0-9]+$'"
    );

    if (empty($pending)) {
        error_log('RTS: No pending registrations found');
        delete_transient('rts_pending_registration_hint');
        delete_transient($lock_key);
        return;
    }

    error_log('RTS: Found ' . count($pending) . ' pending registrations');

    $plugin = RunTheSeasPlugin::get_instance();

    foreach ($pending as $row) {
        $email_data = maybe_unserialize($row->option_value);
        if (!$email_data) {
            delete_option($row->option_name);
            continue;
        }

        $participant_id = $email_data['participant_id'];
        $user_id = $email_data['user_id'] ?? 0;
        error_log('RTS: Processing pending participant: ' . $participant_id);

        // Check if already processed - if so, skip and delete
        $processed_key = 'rts_registration_processed_' . $participant_id;
        $already_processed = get_transient($processed_key);
        if (!$already_processed) {
            // Read and retire flags created by older plugin versions.
            $already_processed = get_option($processed_key);
            if ($already_processed) {
                delete_option($processed_key);
            }
        }
        if ($already_processed) {
            error_log('RTS: Registration already processed for participant: ' . $participant_id . ' at ' . $already_processed);
            delete_option('rts_pending_registration_' . $participant_id);
            continue;
        }

        // Get participant data
        $participant = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}rts_participants WHERE id = %d",
                $participant_id
            )
        );

        if (!$participant) {
            error_log('RTS: Participant not found: ' . $participant_id);
            delete_option('rts_pending_registration_' . $participant_id);
            continue;
        }
        $email = sanitize_email($participant->email);

        // CRITICAL: Mark as processed with timestamp and unique identifier
        $process_id = uniqid('rts_process_', true);
        $process_time = current_time('mysql');
        set_transient($processed_key, $process_time . '|' . $process_id, DAY_IN_SECONDS);

        // Delete the pending record immediately
        delete_option('rts_pending_registration_' . $participant_id);

        $registration = new RTS_Registration();
        $emails_sent = 0;

        // ============================================
        // SEND VERIFICATION EMAIL
        // ============================================
        $verification_sent = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}rts_timeline 
                 WHERE participant_id = %d AND activity_type = 'verification_sent'",
                $participant_id
            )
        );

        if (!$verification_sent && $participant->email_verified == 0) {
            error_log('RTS: Sending verification email for participant: ' . $participant_id);
            $sent = $registration->send_verification_email($participant_id);
            if ($sent) {
                $emails_sent++;
                error_log('RTS: Verification email SENT for participant: ' . $participant_id);
            } else {
                error_log('RTS: Failed to send verification email for participant: ' . $participant_id);
            }
        } else {
            error_log('RTS: Verification email already sent or user verified for participant: ' . $participant_id . ' (sent: ' . ($verification_sent ? 'yes' : 'no') . ', verified: ' . $participant->email_verified . ')');
        }

        // ============================================
        // SEND CONFIRMATION EMAIL
        // ============================================
        $confirmation_sent = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}rts_timeline 
                 WHERE participant_id = %d AND activity_type = 'confirmation_sent'",
                $participant_id
            )
        );

        if (!$confirmation_sent) {
            error_log('RTS: Sending confirmation email for participant: ' . $participant_id);
            if (method_exists($plugin, 'send_registration_confirmation')) {
                $sent = $plugin->send_registration_confirmation(
                    $email,
                    array(),
                    $participant,
                    ''
                );
                if ($sent) {
                    $emails_sent++;
                    error_log('RTS: Confirmation email SENT for participant: ' . $participant_id);
                } else {
                    error_log('RTS: Failed to send confirmation email for participant: ' . $participant_id);
                }
            }
        } else {
            error_log('RTS: Confirmation email already sent for participant: ' . $participant_id);
        }

        error_log('RTS: Processed participant: ' . $participant_id . ' - Total emails sent: ' . $emails_sent);
    }

    // Release the lock
    delete_transient('rts_pending_registration_hint');
    delete_transient($lock_key);
    error_log('RTS: Pending registration processing completed');
}
// Run on admin_init and via cron
add_action('admin_init', 'rts_process_pending_registrations');
add_action('rts_cron_process_pending', 'rts_process_pending_registrations');

// Keep a twice-hourly fallback for jobs interrupted before the admin trigger.
if (!wp_next_scheduled('rts_cron_process_pending')) {
    wp_schedule_event(time(), 'twicehourly', 'rts_cron_process_pending');
}

// Add custom cron schedule if needed
add_filter('cron_schedules', 'rts_add_cron_schedule');
function rts_add_cron_schedule($schedules)
{
    $schedules['every_5_minutes'] = array(
        'interval' => 300,
        'display' => __('Every 5 Minutes', 'run-the-seas')
    );
    return $schedules;
}

/**
 * Show email status notice on Captain's Suite page
 */
function rts_show_email_status_notice()
{
    if (!is_page('captains-suite') && !is_page('captain-suite')) {
        return;
    }

    if (!is_user_logged_in()) {
        return;
    }

    $user = wp_get_current_user();
    global $wpdb;

    $participant = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rts_participants WHERE user_id = %d",
            $user->ID
        )
    );

    if ($participant) {
        // Check if emails are still pending
        $pending = get_option('rts_pending_registration_' . $participant->id);
        if ($pending) {
    ?>
            <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 15px 20px; margin: 20px 0;">
                <p style="margin: 0; color: #856404;">
                    📧 <strong>Emails are being sent!</strong>
                    You will receive your verification and confirmation emails shortly.
                    Please check your inbox and spam folder.
                </p>
            </div>
        <?php
        }
    }
}
add_action('wp_body_open', 'rts_show_email_status_notice');
