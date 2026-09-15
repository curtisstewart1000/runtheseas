<?php

/**
 * Class RTS_BuddyPress_QR
 * Handles QR code cards on the dedicated member page and, when available,
 * the legacy BuddyPress member navigation.
 */
class RTS_BuddyPress_QR
{

    private $db;
    private $registration;
    private $upload_dir;
    private $upload_url;
    private $terms_version = '1.0';

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $plugin = function_exists('rts_init') ? rts_init() : null;
        $this->registration = $plugin && $plugin->registration instanceof RTS_Registration
            ? $plugin->registration
            : new RTS_Registration();

        // Set up upload directories for QR cards
        $upload_dir = wp_upload_dir();
        $this->upload_dir = $upload_dir['basedir'] . '/rts-qr-cards/';
        $this->upload_url = $upload_dir['baseurl'] . '/rts-qr-cards/';

        // Get terms version
        $this->terms_version = get_option('rts_qr_terms_version', '1.0');

        // BuddyPress sets up member navigation after it knows the displayed user.
        // Add the permitted member entries after the standard BuddyPress tabs
        // are removed, so they remain visible in the member dashboard.
        add_action('bp_setup_nav', array($this, 'register_member_navigation'), 1000);
        //add_action('bp_setup_nav', array($this, 'remove_default_member_navigation'), 999);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('init', array($this, 'handle_profile_update'));

        // All QR actions require an authenticated owner of the participant record.
        add_action('wp_ajax_rts_update_card_name', array($this, 'ajax_update_card_name'));
        add_action('wp_ajax_rts_generate_qr_card', array($this, 'ajax_generate_qr_card'));
        add_action('wp_ajax_rts_check_qr_terms', array($this, 'ajax_check_qr_terms'));
        add_action('wp_ajax_rts_accept_qr_terms', array($this, 'ajax_accept_qr_terms'));
    }

    /** Prepare QR storage only when a card is actually being generated. */
    private function ensure_upload_directory()
    {
        if (!is_dir($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
        }
        if (!file_exists($this->upload_dir . 'index.html')) {
            file_put_contents($this->upload_dir . 'index.html', '');
        }
    }

    /**
     * Add private account actions to the member's BuddyPress profile.
     */
    public function register_member_navigation()
    {
        if (!function_exists('bp_is_my_profile') || !bp_is_my_profile()) {
            return;
        }

        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            bp_core_new_nav_item(array(
                'name'                => esc_html__('My Details', 'run-the-seas'),
                'slug'                => 'my-details',
                'screen_function'     => array($this, 'profile_screen'),
                'position'            => 70,
                'show_for_displayed_user' => true,
            ));
            bp_core_new_nav_item(array(
                'name'                => esc_html__('My QR Code', 'run-the-seas'),
                'slug'                => 'my-qr-code',
                'screen_function'     => array($this, 'qr_screen'),
                'position'            => 75,
                'show_for_displayed_user' => true,
            ));
            $this->add_logout_navigation();
            return;
        }

        $admin_slug = 'rts-admin';
        $admin_url  = trailingslashit(bp_displayed_user_domain() . $admin_slug);

        bp_core_new_nav_item(array(
            'name'                => esc_html__('Run The Seas Admin', 'run-the-seas'),
            'slug'                => $admin_slug,
            'screen_function'     => array($this, 'admin_screen'),
            'position'            => 76,
            'show_for_displayed_user' => true,
        ));

        $admin_sections = array(
            'overview'   => __('Overview', 'run-the-seas'),
            'surveys'    => __('Surveys', 'run-the-seas'),
            'survey-settings' => __('Survey Settings', 'run-the-seas'),
            'participants' => __('Participants & Verification', 'run-the-seas'),
            'reviews'    => __('Duplicate Review & History', 'run-the-seas'),
            'referrals'  => __('Referrals', 'run-the-seas'),
            'leaderboard' => __('Leaderboard', 'run-the-seas'),
            'analytics'  => __('Analytics & Exports', 'run-the-seas'),
        );

        $position = 10;
        foreach ($admin_sections as $slug => $name) {
            bp_core_new_subnav_item(array(
                'name'            => $name,
                'slug'            => $slug,
                'parent_url'      => $admin_url,
                'parent_slug'     => $admin_slug,
                'screen_function' => array($this, 'admin_section_screen'),
                'position'        => $position,
            ));
            $position += 10;
        }

        $this->add_logout_navigation();
    }

    /**
     * Add a secure logout action to the end of the current member's dashboard.
     */
    private function add_logout_navigation()
    {
        bp_core_new_nav_item(array(
            'name'                => esc_html__('Log out', 'run-the-seas'),
            'slug'                => 'rts-logout',
            'screen_function'     => array($this, 'logout_screen'),
            // BuddyPress Settings normally uses position 100. Keep Logout
            // beyond that so it remains the final item on the right.
            'position'            => 999,
            'show_for_displayed_user' => true,
        ));
    }

    /**
     * Run The Seas uses a dedicated account page instead of BuddyPress member
     * tabs. Removing these at the final navigation priority applies to every
     * member, not only to the currently displayed profile.
     */
    // public function remove_default_member_navigation()
    // {
    //     if (!function_exists('bp_core_remove_nav_item')) {
    //         return;
    //     }      

    //     $remove_page = array('profile', 'xprofile', 'notifications', 'messages', 'friends', 'groups');         
    //     foreach ($remove_page as $slug) {
    //         bp_core_remove_nav_item($slug);
    //     }

        
    // }

    public function qr_screen()
    {
        add_action('bp_template_content', array($this, 'render_qr_content'));
        bp_core_load_template(apply_filters('bp_core_template_plugin', 'members/single/plugins'));
    }

    public function profile_screen()
    {
        add_action('bp_template_content', array($this, 'render_profile_content'));
        bp_core_load_template(apply_filters('bp_core_template_plugin', 'members/single/plugins'));
    }

    /** Route the member-nav item to the dedicated branded account page. */
    public function profile_page_screen()
    {
        $url = function_exists('rts_get_member_profile_url') ? rts_get_member_profile_url() : home_url('/my-details/');
        wp_safe_redirect($url);
        exit;
    }

    /** Save a member's own registration details without exposing wp-admin. */
    public function handle_profile_update()
    {
        if (empty($_POST['rts_profile_update']) || !is_user_logged_in()) {
            return;
        }
        check_admin_referer('rts_profile_update', 'rts_profile_nonce');

        $user = wp_get_current_user();
        $participant = $this->registration->get_participant_for_user($user);
        if (!$participant || (int) $participant->user_id !== (int) $user->ID) {
            wp_die(esc_html__('You do not have permission to update this record.', 'run-the-seas'), '', array('response' => 403, 'back_link' => true));
        }

        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!is_email($email)) {
            wp_die(esc_html__('Please provide a valid email address.', 'run-the-seas'), '', array('response' => 400, 'back_link' => true));
        }
        $other_participant = $this->registration->get_participant_by_email($email);
        if ($other_participant && (int) $other_participant->id !== (int) $participant->id) {
            wp_die(esc_html__('That email address is already linked to another participant.', 'run-the-seas'), '', array('response' => 400, 'back_link' => true));
        }
        $other_user = get_user_by('email', $email);
        if ($other_user && (int) $other_user->ID !== (int) $user->ID) {
            wp_die(esc_html__('That email address is already in use.', 'run-the-seas'), '', array('response' => 400, 'back_link' => true));
        }

        $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last_name = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        if ('' === $first_name || '' === $last_name) {
            wp_die(esc_html__('Please provide both your first and last name.', 'run-the-seas'), '', array('response' => 400, 'back_link' => true));
        }

        $current_password = (string) wp_unslash($_POST['current_password'] ?? '');
        $new_password = (string) wp_unslash($_POST['new_password'] ?? '');
        $confirm_password = (string) wp_unslash($_POST['confirm_password'] ?? '');
        $password_requested = '' !== $current_password || '' !== $new_password || '' !== $confirm_password;
        if ($password_requested) {
            if ('' === $current_password || !wp_check_password($current_password, $user->user_pass, $user->ID)) {
                wp_die(esc_html__('Your current passcode is incorrect.', 'run-the-seas'), '', array('response' => 400, 'back_link' => true));
            }
            if (strlen($new_password) < 8) {
                wp_die(esc_html__('Your new passcode must contain at least 8 characters.', 'run-the-seas'), '', array('response' => 400, 'back_link' => true));
            }
            if ($new_password !== $confirm_password) {
                wp_die(esc_html__('The new passcodes do not match.', 'run-the-seas'), '', array('response' => 400, 'back_link' => true));
            }
            if (wp_check_password($new_password, $user->user_pass, $user->ID)) {
                wp_die(esc_html__('Your new passcode must be different from your current passcode.', 'run-the-seas'), '', array('response' => 400, 'back_link' => true));
            }
        }

        $email_changed = strtolower($email) !== strtolower($participant->email);
        $update = array(
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'updated_at' => current_time('mysql'),
        );
        if ($email_changed) {
            $update['email_verified'] = 0;
            $update['email_verification_date'] = null;
            $update['email_verification_token'] = bin2hex(random_bytes(32));
            $update['captain_suite_status'] = 'inactive';
        }

        // Process the photo before account data so an invalid image cannot
        // leave the email/name partly saved while the request reports failure.
        $profile_photo = isset($_FILES['profile_photo']) && is_array($_FILES['profile_photo'])
            ? $_FILES['profile_photo']
            : array();
        $has_new_photo = !empty($profile_photo)
            && UPLOAD_ERR_NO_FILE !== (int) ($profile_photo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($has_new_photo) {
            if (!function_exists('rts_child_save_buddynext_profile_photo')) {
                wp_die(esc_html__('Profile photo support is temporarily unavailable.', 'run-the-seas'), '', array('response' => 500, 'back_link' => true));
            }
            $photo_result = rts_child_save_buddynext_profile_photo($user->ID, $profile_photo);
            if (is_wp_error($photo_result)) {
                wp_die(esc_html($photo_result->get_error_message()), '', array('response' => 400, 'back_link' => true));
            }
        } elseif (!empty($_POST['remove_profile_photo'])) {
            if (function_exists('rts_child_remove_buddynext_profile_photo')) {
                rts_child_remove_buddynext_profile_photo($user->ID);
            } else {
                delete_user_meta($user->ID, 'bn_avatar');
            }
        }

        global $wpdb;
        $saved = $wpdb->update($wpdb->prefix . 'rts_participants', $update, array('id' => $participant->id));
        if ($saved === false) {
            wp_die(esc_html__('Your details could not be saved. Please try again.', 'run-the-seas'), '', array('response' => 500, 'back_link' => true));
        }
        if ($email_changed) {
            update_user_meta($user->ID, 'rts_email_verified', '0');
            $this->registration->sync_participant_email($participant->id, $participant->email, $email);
        }
        $user_update = array('ID' => $user->ID, 'user_email' => $email, 'first_name' => $first_name, 'last_name' => $last_name, 'display_name' => trim($first_name . ' ' . $last_name));
        if ($password_requested) {
            $user_update['user_pass'] = $new_password;
        }
        $user_result = wp_update_user($user_update);
        if (is_wp_error($user_result)) {
            wp_die(esc_html($user_result->get_error_message()), '', array('response' => 400, 'back_link' => true));
        }
        if (function_exists('xprofile_set_field_data')) {
            xprofile_set_field_data('Name', $user->ID, trim($first_name . ' ' . $last_name));
        }
        update_user_meta(
            $user->ID,
            'rts_referral_progress_notifications',
            !empty($_POST['rts_referral_progress_notifications']) ? 'on' : 'off'
        );

        $this->registration->log_timeline($participant->id, 'profile_updated', 'Participant updated their account details');
        if ($email_changed) {
            $this->registration->send_verification_email($participant->id, true);
        }

        $destination = function_exists('rts_get_member_profile_url') ? rts_get_member_profile_url() : home_url('/captains-suite/');
        wp_safe_redirect(add_query_arg('updated', '1', $destination));
        exit;
    }

    public function render_profile_content()
    {
        $user = wp_get_current_user();
        $participant = $this->registration->get_participant_for_user($user);
        if (!$participant) {
            echo '<p>' . esc_html__('No Run The Seas registration was found for this account.', 'run-the-seas') . '</p>';
            return;
        }
        $referral_progress_notifications = get_user_meta($user->ID, 'rts_referral_progress_notifications', true) !== 'off';
        $custom_profile_photo = (string) get_user_meta($user->ID, 'bn_avatar', true);
        ?>
        <div class="rts-member-profile">
            <div class="rts-profile-heading">
                <h2><?php esc_html_e('Profile Edit', 'run-the-seas'); ?></h2>
            </div>
            <?php if (isset($_GET['updated'])) : ?>
                <div class="rts-profile-notice" role="status"><?php esc_html_e('Your details have been updated.', 'run-the-seas'); ?><?php echo (int) $participant->email_verified ? '' : ' ' . esc_html__('Please verify your email to activate your Captain’s Suite.', 'run-the-seas'); ?></div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="rts-member-profile-form">
                <?php wp_nonce_field('rts_profile_update', 'rts_profile_nonce'); ?>
                <input type="hidden" name="rts_profile_update" value="1">

                <section class="rts-profile-section rts-profile-photo-section" aria-labelledby="rts-profile-photo-heading">
                    <div>
                        <h3 id="rts-profile-photo-heading"><?php esc_html_e('Profile photo', 'run-the-seas'); ?></h3>
                        <p><?php esc_html_e('This photo will be used across your member account.', 'run-the-seas'); ?></p>
                    </div>
                    <div class="rts-profile-photo-editor">
                        <?php echo get_avatar($user->ID, 128, '', esc_attr__('Your profile photo', 'run-the-seas'), array('class' => 'rts-profile-avatar')); ?>
                        <div class="rts-profile-photo-controls">
                            <label for="rts-profile-photo"><?php esc_html_e('Choose a new photo', 'run-the-seas'); ?></label>
                            <input id="rts-profile-photo" type="file" name="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp">
                            <small><?php esc_html_e('JPEG, PNG, GIF or WebP. Maximum 2 MB.', 'run-the-seas'); ?></small>
                            <?php if ('' !== $custom_profile_photo) : ?>
                                <label class="rts-profile-check rts-profile-remove-photo">
                                    <input type="checkbox" name="remove_profile_photo" value="1">
                                    <span><?php esc_html_e('Remove my current photo', 'run-the-seas'); ?></span>
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <section class="rts-profile-section" aria-labelledby="rts-profile-account-heading">
                    <div class="rts-profile-section-heading">
                        <h3 id="rts-profile-account-heading"><?php esc_html_e('Account details', 'run-the-seas'); ?></h3>
                        <p><?php esc_html_e('Keep your name and account email current.', 'run-the-seas'); ?></p>
                    </div>
                    <div class="rts-profile-grid">
                        <label><?php esc_html_e('First name', 'run-the-seas'); ?><input required autocomplete="given-name" name="first_name" value="<?php echo esc_attr($participant->first_name); ?>"></label>
                        <label><?php esc_html_e('Last name', 'run-the-seas'); ?><input required autocomplete="family-name" name="last_name" value="<?php echo esc_attr($participant->last_name); ?>"></label>
                        <label class="rts-profile-full"><?php esc_html_e('Email', 'run-the-seas'); ?><input required type="email" autocomplete="email" name="email" value="<?php echo esc_attr($participant->email); ?>"><small><?php esc_html_e('Changing this address requires email verification.', 'run-the-seas'); ?></small></label>
                    </div>
                </section>

                <section class="rts-profile-section" aria-labelledby="rts-profile-passcode-heading">
                    <div class="rts-profile-section-heading">
                        <h3 id="rts-profile-passcode-heading"><?php esc_html_e('Change passcode', 'run-the-seas'); ?></h3>
                        <p><?php esc_html_e('Leave these fields blank to keep your current passcode.', 'run-the-seas'); ?></p>
                    </div>
                    <div class="rts-profile-grid rts-profile-password-grid">
                        <label class="rts-profile-full"><?php esc_html_e('Current passcode', 'run-the-seas'); ?><input type="password" name="current_password" autocomplete="current-password"></label>
                        <label><?php esc_html_e('New passcode', 'run-the-seas'); ?><input type="password" name="new_password" autocomplete="new-password" minlength="8"></label>
                        <label><?php esc_html_e('Confirm new passcode', 'run-the-seas'); ?><input type="password" name="confirm_password" autocomplete="new-password" minlength="8"></label>
                    </div>
                </section>

                <section class="rts-profile-section" aria-labelledby="rts-profile-email-heading">
                    <div class="rts-profile-section-heading">
                        <h3 id="rts-profile-email-heading"><?php esc_html_e('Email preferences', 'run-the-seas'); ?></h3>
                    </div>
                    <label class="rts-profile-check">
                        <input type="checkbox" name="rts_referral_progress_notifications" value="1" <?php checked($referral_progress_notifications); ?>>
                        <span><strong><?php esc_html_e('Referral progress emails', 'run-the-seas'); ?></strong><small><?php esc_html_e('Email me when a referral is verified and advances my kilometre progress.', 'run-the-seas'); ?></small></span>
                    </label>
                </section>

                <div class="rts-profile-actions"><button type="submit"><?php esc_html_e('Save my details', 'run-the-seas'); ?></button></div>
            </form>
        </div>
        <?php
    }

    public function logout_screen()
    {
        // wp_logout_url() adds WordPress' logout nonce before performing the
        // logout, preventing a third-party page from forcing a user to log out.
        wp_safe_redirect(wp_logout_url(home_url('/')));
        exit;
    }

    /**
     * Load the private Run The Seas administration area inside BuddyPress.
     */
    public function admin_screen()
    {
        $this->load_admin_profile_template();
    }

    public function admin_section_screen()
    {
        $this->load_admin_profile_template();
    }

    private function load_admin_profile_template()
    {
        if (!current_user_can(RTS_MANAGE_CAPABILITY) || !bp_is_my_profile()) {
            bp_core_add_message(__('You do not have permission to view this page.', 'run-the-seas'), 'error');
            bp_core_redirect(bp_core_get_user_domain(get_current_user_id()));
        }

        add_action('bp_template_content', array($this, 'render_admin_profile_content'));
        bp_core_load_template(apply_filters('bp_core_template_plugin', 'members/single/plugins'));
    }

    /**
     * Render existing RTS dashboard data inside the member's BuddyPress profile.
     */
    public function render_admin_profile_content($section = '')
    {
        if ('' === $section && function_exists('bp_current_action')) {
            $section = bp_current_action();
        }
        if ('' === $section && isset($_GET['rts_admin_section'])) {
            $section = sanitize_key(wp_unslash($_GET['rts_admin_section']));
        }
        if (empty($section)) {
            $section = 'overview';
        }

        // Existing admin templates contain legacy `?page=` links. Keep them
        // working when they are rendered inside the BuddyPress profile.
        $legacy_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $legacy_sections = array(
            'rts-surveys'             => 'surveys',
            'rts-survey-management'   => 'surveys',
            'rts-survey-settings'     => 'survey-settings',
            'rts-survey-analytics'    => 'analytics',
            'rts-bi-dashboard'        => 'analytics',
            'rts-referral-dashboard'  => 'referrals',
            'rts-referral-details'    => 'referrals',
            'rts-leaderboard'         => 'leaderboard',
            'rts-executive-dashboard' => 'analytics',
        );

        if (isset($legacy_sections[$legacy_page])) {
            $section = $legacy_sections[$legacy_page];
        }

        if (in_array($section, array('surveys', 'survey-settings', 'referrals', 'leaderboard'), true)
            && !class_exists('RTS_Admin')) {
            require_once RTS_PLUGIN_PATH . 'includes/class-rts-admin.php';
        }
        if ('analytics' === $section && !class_exists('RTS_Analytics')) {
            require_once RTS_PLUGIN_PATH . 'includes/class-rts-analytics.php';
        }

        echo '<div class="rts-admin-profile">';

        switch ($section) {
            case 'surveys':
                (new RTS_Admin())->render_surveys_page();
                break;

            case 'survey-settings':
                (new RTS_Admin())->render_settings_page();
                break;

            case 'referrals':
                $admin = new RTS_Admin();
                if (!empty($_GET['user_id'])) {
                    $admin->render_referral_details();
                } else {
                    $admin->render_referral_dashboard();
                }
                break;

            case 'leaderboard':
                (new RTS_Admin())->render_leaderboard_page();
                break;

            case 'participants':
                $plugin = RunTheSeasPlugin::get_instance();
                $plugin->participant_operations->render_page('participants');
                break;

            case 'reviews':
                $plugin = RunTheSeasPlugin::get_instance();
                $plugin->participant_operations->render_page('reviews');
                break;

            case 'analytics':
                (new RTS_Analytics())->render_dashboard_page();
                break;

            default:
                $this->render_admin_overview();
                break;
        }

        echo '</div>';
    }

    /** Render the protected administrator dashboard on a normal WordPress page. */
    public function render_admin_dashboard_shortcode($atts)
    {
        if (!function_exists('rts_is_run_the_seas_admin') || !rts_is_run_the_seas_admin()) {
            return '<p class="rts-admin-access-denied">' . esc_html__('You do not have permission to view this page.', 'run-the-seas') . '</p>';
        }

        $atts = shortcode_atts(array('section' => ''), $atts, 'rts_admin_dashboard');
        $section = sanitize_key((string) $atts['section']);
        if (isset($_GET['rts_admin_section'])) {
            $section = sanitize_key(wp_unslash($_GET['rts_admin_section']));
        }
        if ('' === $section) {
            $section = 'overview';
        }

        $sections = array(
            'overview'        => __('Overview', 'run-the-seas'),
            'surveys'         => __('Surveys', 'run-the-seas'),
            'survey-settings' => __('Survey Settings', 'run-the-seas'),
            'participants'    => __('Participants & Verification', 'run-the-seas'),
            'reviews'         => __('Duplicate Review & History', 'run-the-seas'),
            'referrals'       => __('Referrals', 'run-the-seas'),
            'leaderboard'     => __('Leaderboard', 'run-the-seas'),
            'analytics'       => __('Analytics & Exports', 'run-the-seas'),
        );
        if (!isset($sections[$section])) {
            $section = 'overview';
        }

        ob_start();
        echo '<nav class="rts-admin-dashboard-nav" aria-label="' . esc_attr__('Run The Seas administration', 'run-the-seas') . '">';
        foreach ($sections as $slug => $label) {
            $class = $slug === $section ? ' is-active' : '';
            echo '<a class="rts-admin-dashboard-nav__link' . esc_attr($class) . '" href="'
                . esc_url(rts_get_admin_dashboard_url($slug)) . '">' . esc_html($label) . '</a>';
        }
        echo '<a class="rts-admin-dashboard-nav__link rts-admin-dashboard-nav__link--logout" href="'
            . esc_url(wp_logout_url(home_url('/'))) . '">' . esc_html__('Log out', 'run-the-seas') . '</a>';
        echo '</nav>';
        $this->render_admin_profile_content($section);

        return ob_get_clean();
    }

    private function render_admin_overview()
    {
        global $wpdb;

        $participants = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rts_participants");
        $completed    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rts_survey_tracking WHERE completion_status = 'completed'");
        $referrals    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rts_referrals");
        $races        = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rts_races");
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Run The Seas Admin', 'run-the-seas'); ?></h1>
            <p><?php esc_html_e('Manage Run The Seas from this private dashboard.', 'run-the-seas'); ?></p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-top:24px;">
                <?php foreach (array(
                    __('Participants', 'run-the-seas') => $participants,
                    __('Completed surveys', 'run-the-seas') => $completed,
                    __('Referrals', 'run-the-seas') => $referrals,
                    __('Races', 'run-the-seas') => $races,
                ) as $label => $value) : ?>
                    <div style="padding:20px;background:#fff;border:1px solid #dcdcde;border-radius:8px;">
                        <strong style="display:block;font-size:28px;"><?php echo esc_html(number_format_i18n($value)); ?></strong>
                        <span><?php echo esc_html($label); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    public function enqueue_scripts()
    {
        $profile_page_id = absint(get_option('rts_member_profile_page_id'));
        $qr_page_id = absint(get_option('rts_member_qr_page_id'));
        $admin_dashboard_page_id = absint(get_option('rts_admin_dashboard_page_id'));
        $is_frontend_admin_dashboard = $admin_dashboard_page_id
            && is_page($admin_dashboard_page_id)
            && current_user_can(RTS_MANAGE_CAPABILITY);

        if ($is_frontend_admin_dashboard) {
            $admin_css_version = RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/admin.css');
            wp_enqueue_style('rts-admin', RTS_PLUGIN_URL . 'assets/css/admin.css', array(), $admin_css_version);
            wp_enqueue_script('rts-admin', RTS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), RTS_VERSION, true);
            wp_localize_script('rts-admin', 'rts_admin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('rts_admin_nonce'),
            ));

            $section = isset($_GET['rts_admin_section'])
                ? sanitize_key(wp_unslash($_GET['rts_admin_section']))
                : 'overview';
            if ('analytics' === $section) {
                wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', true);
                $bi_version = RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/js/bi-dashboard.js');
                wp_enqueue_script('rts-bi-dashboard', RTS_PLUGIN_URL . 'assets/js/bi-dashboard.js', array('jquery', 'chart-js'), $bi_version, true);
                wp_localize_script('rts-bi-dashboard', 'rts_bi', array(
                    'ajax_url'   => admin_url('admin-ajax.php'),
                    'nonce'      => wp_create_nonce('rts_admin_nonce'),
                    'plugin_url' => RTS_PLUGIN_URL,
                ));
            }
        }

        if (is_user_logged_in() && !current_user_can(RTS_MANAGE_CAPABILITY)) {
            $admin_css_version = RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/admin.css');
            wp_enqueue_style('rts-admin', RTS_PLUGIN_URL . 'assets/css/admin.css', array(), $admin_css_version);
        }
        if (($profile_page_id && is_page($profile_page_id)) || (function_exists('bp_is_my_profile') && bp_is_my_profile() && function_exists('bp_current_action') && bp_current_action() === 'my-details')) {
            $admin_css_version = RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/admin.css');
            wp_enqueue_style('rts-admin', RTS_PLUGIN_URL . 'assets/css/admin.css', array(), $admin_css_version);
        }

        if ((function_exists('bp_is_user') && bp_is_user()) || ($qr_page_id && is_page($qr_page_id))) {
            wp_enqueue_script('jquery');
            wp_localize_script('jquery', 'rts_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('rts_nonce'),
                'user_id' => get_current_user_id(),
                'site_url' => home_url(),
                'site_name' => get_bloginfo('name'),
                'site_icon' => get_site_icon_url(64)
            ));
        }

        if (function_exists('bp_current_component') && bp_current_component() === 'rts-admin') {
            $admin_css_version = RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/admin.css');
            wp_enqueue_style('rts-admin', RTS_PLUGIN_URL . 'assets/css/admin.css', array(), $admin_css_version);
            wp_enqueue_script('rts-admin', RTS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), RTS_VERSION, true);
            wp_localize_script('rts-admin', 'rts_admin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('rts_admin_nonce'),
            ));

            if (in_array(bp_current_action(), array('analytics', 'reporting'), true)) {
                wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', true);
                $bi_version = RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/js/bi-dashboard.js');
                wp_enqueue_script('rts-bi-dashboard', RTS_PLUGIN_URL . 'assets/js/bi-dashboard.js', array('jquery', 'chart-js'), $bi_version, true);
                wp_localize_script('rts-bi-dashboard', 'rts_bi', array(
                    'ajax_url'   => admin_url('admin-ajax.php'),
                    'nonce'      => wp_create_nonce('rts_admin_nonce'),
                    'plugin_url' => RTS_PLUGIN_URL,
                ));
            }
        }
    }

    /**
     * Validate display name
     */
    private function validate_display_name($name)
    {
        if (strlen($name) < 2) {
            return array('valid' => false, 'message' => 'Name must be at least 2 characters long.');
        }
        if (strlen($name) > 50) {
            return array('valid' => false, 'message' => 'Name must be less than 50 characters.');
        }

        $spam_patterns = array(
            '/[0-9]{10,}/',
            '/(.)\1{5,}/',
            '/[^\w\s\-\.\']/',
            '/^[0-9]+$/',
        );

        foreach ($spam_patterns as $pattern) {
            if (preg_match($pattern, $name)) {
                return array('valid' => false, 'message' => 'Please enter a valid name.');
            }
        }

        $spam_words = array('spam', 'test', 'dummy', 'fake', 'xxx', 'porn', 'casino', 'viagra', 'crypto', 'bitcoin');
        $name_lower = strtolower($name);
        foreach ($spam_words as $word) {
            if (strpos($name_lower, $word) !== false) {
                return array('valid' => false, 'message' => 'Please enter a genuine name.');
            }
        }

        return array('valid' => true, 'message' => '');
    }

    /**
     * Review a QR-card display name before it is stored or rendered publicly.
     *
     * WordPress's Disallowed Comment Keys provide a local block list. When a
     * WebPurify key is configured, its service performs the additional
     * profanity review. Define RTS_WEBPURIFY_API_KEY in wp-config.php to turn
     * on the service without exposing the key in browser code or the database.
     */
    public function moderate_display_name($name)
    {
        if (function_exists('wp_check_comment_disallowed_list') && wp_check_comment_disallowed_list($name, '', '', '', '', '')) {
            return array('valid' => false, 'message' => __('This name cannot be used on a public QR card.', 'run-the-seas'));
        }

        $api_key = defined('RTS_WEBPURIFY_API_KEY') ? trim((string) RTS_WEBPURIFY_API_KEY) : '';
        if ('' === $api_key) {
            return array('valid' => true, 'message' => '');
        }

        $cache_key = 'rts_qr_name_moderation_' . md5(strtolower($name));
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['valid'])) {
            return $cached;
        }

        $endpoint = defined('RTS_WEBPURIFY_ENDPOINT')
            ? esc_url_raw(RTS_WEBPURIFY_ENDPOINT)
            : 'https://api1.webpurify.com/services/rest/';
        $response = wp_remote_post($endpoint, array(
            'timeout' => 8,
            'body'    => array(
                'method'  => 'webpurify.live.check',
                'api_key' => $api_key,
                'text'    => $name,
                'format'  => 'json',
                'lang'    => 'en',
                'semail'  => 1,
                'sphone'  => 1,
                'slink'   => 1,
            ),
        ));

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return array('valid' => false, 'message' => __('Name review is temporarily unavailable. Please try again.', 'run-the-seas'));
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $found = $data['rsp']['found'] ?? $data['found'] ?? null;
        if (null === $found) {
            return array('valid' => false, 'message' => __('Name review is temporarily unavailable. Please try again.', 'run-the-seas'));
        }

        $result = (int) $found > 0
            ? array('valid' => false, 'message' => __('Please choose a different name for your public QR card.', 'run-the-seas'))
            : array('valid' => true, 'message' => '');
        set_transient($cache_key, $result, DAY_IN_SECONDS);

        return $result;
    }

    private function get_qr_card_template_source()
    {
        $template_url = trim((string) get_option('rts_qr_card_template_url', ''));
        if ($template_url !== '') {
            return $template_url;
        }

        $template_path = trim((string) get_option('rts_qr_card_template_path', ''));
        if ($template_path !== '' && file_exists($template_path)) {
            return $template_path;
        }

        $template_candidates = array(
            $this->upload_dir . 'template.png',
            $this->upload_dir . 'template.jpg',
            $this->upload_dir . 'template.jpeg',
            $this->upload_dir . 'template.webp',
        );

        foreach ($template_candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return false;
    }

    private function load_gd_image_from_source($source, &$cleanup_file = null)
    {
        $cleanup_file = null;

        if (!$source) {
            return false;
        }

        if (preg_match('/^https?:\/\//i', $source)) {
            $downloaded = $this->download_remote_file($source);
            if (is_wp_error($downloaded) || !file_exists($downloaded)) {
                return false;
            }

            $cleanup_file = $downloaded;
            $source = $downloaded;
        }

        if (!file_exists($source)) {
            return false;
        }

        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if ($extension === 'png') {
            return imagecreatefrompng($source);
        }
        if ($extension === 'jpg' || $extension === 'jpeg') {
            return imagecreatefromjpeg($source);
        }
        if ($extension === 'webp' && function_exists('imagecreatefromwebp')) {
            return imagecreatefromwebp($source);
        }

        $image_data = @file_get_contents($source);
        if (!$image_data) {
            return false;
        }

        return imagecreatefromstring($image_data);
    }

    /**
     * download_url() is an admin helper and is not guaranteed to be loaded on
     * a front-end shortcode request. Load it explicitly before generating a
     * card so the QR page remains independent of other plugins.
     */
    private function download_remote_file($url)
    {
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        return download_url($url);
    }

    private function generate_qr_card_from_template($participant, $display_name, $template_source)
    {
        $referral_link = home_url('/survey?ref=' . $participant->referral_code);
        $cleanup_file = null;
        $image = $this->load_gd_image_from_source($template_source, $cleanup_file);

        if (!$image) {
            if ($cleanup_file && file_exists($cleanup_file)) {
                @unlink($cleanup_file);
            }
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $black = imagecolorallocate($image, 0, 0, 0);
        $gold = imagecolorallocate($image, 212, 175, 55);
        $light_gold = imagecolorallocate($image, 245, 220, 130);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);

        $frame_x = (int) round($width * 0.06);
        $frame_y = (int) round($height * 0.15);
        $frame_size = (int) round(min($width * 0.33, $height * 0.68));
        // Use most of the inner frame while retaining a narrow white quiet zone
        // around the code so phone cameras can still scan it reliably.
        $qr_size = (int) round($frame_size * 0.74);
        $qr_x = (int) round($frame_x + (($frame_size - $qr_size) / 2));
        $qr_y = (int) round($frame_y + (($frame_size - $qr_size) / 2));

        $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $qr_size . 'x' . $qr_size . '&data=' . urlencode($referral_link) . '&format=png&ecc=H&margin=1';
        $qr_temp = $this->download_remote_file($qr_url);

        if (!is_wp_error($qr_temp) && file_exists($qr_temp)) {
            $qr_image = imagecreatefrompng($qr_temp);
            if ($qr_image) {
                imagecopyresampled(
                    $image,
                    $qr_image,
                    $qr_x,
                    $qr_y,
                    0,
                    0,
                    $qr_size,
                    $qr_size,
                    imagesx($qr_image),
                    imagesy($qr_image)
                );
                imagedestroy($qr_image);
            }
            @unlink($qr_temp);
        }

        $name = strtoupper(trim($display_name));
        if ($name === '') {
            $name = strtoupper(trim($participant->first_name . ' ' . $participant->last_name));
        }

        // The replacement landscape template places the name inside the
        // ornamental plaque below the logo. Keep this box proportional so the
        // overlay remains centred when a higher-resolution template is used.
        $name_left = (int) round($width * 0.46);
        $name_right = (int) round($width * 0.93);
        $name_top = (int) round($height * 0.385);
        $name_bottom = (int) round($height * 0.445);

        imagefilledrectangle($image, $name_left, $name_top, $name_right, $name_bottom, $transparent);

        $font_candidates = array(
            34 => array(
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
                'C:\\Windows\\Fonts\\arialbd.ttf',
            ),
            30 => array(
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
                'C:\\Windows\\Fonts\\arialbd.ttf',
            ),
            26 => array(
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
                'C:\\Windows\\Fonts\\arialbd.ttf',
            ),
            22 => array(
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
                'C:\\Windows\\Fonts\\arialbd.ttf',
            ),
        );

        $font_path = false;
        $font_size = 0;
        foreach ($font_candidates as $candidate_size => $paths) {
            foreach ($paths as $path) {
                if (file_exists($path)) {
                    $font_path = $path;
                    $font_size = $candidate_size;
                    break 2;
                }
            }
        }

        if ($font_path && function_exists('imagettftext')) {
            $bbox = imagettfbbox($font_size, 0, $font_path, $name);
            $text_width = abs($bbox[2] - $bbox[0]);
            $text_height = abs($bbox[7] - $bbox[1]);
            while ($font_size > 18 && ($text_width > ($name_right - $name_left - 28) || $text_height > ($name_bottom - $name_top - 18))) {
                $font_size -= 2;
                $bbox = imagettfbbox($font_size, 0, $font_path, $name);
                $text_width = abs($bbox[2] - $bbox[0]);
                $text_height = abs($bbox[7] - $bbox[1]);
            }
            // Account for the font's actual bearings instead of assuming its
            // baseline starts at zero; this centres names consistently across
            // Arial, Liberation Sans and DejaVu Sans.
            $text_x = (int) round($name_left + (($name_right - $name_left - $text_width) / 2) - $bbox[0]);
            $text_y = (int) round($name_top + (($name_bottom - $name_top - $text_height) / 2) - $bbox[7]);
            imagettftext($image, $font_size, 0, $text_x, $text_y, $light_gold, $font_path, $name);
        } else {
            $font = 5;
            $max_width = $name_right - $name_left - 18;
            while ($font > 1 && (strlen($name) * imagefontwidth($font)) > $max_width) {
                $font--;
            }

            $text_width = strlen($name) * imagefontwidth($font);
            $text_height = imagefontheight($font);
            $available_width = $name_right - $name_left - 28;
            $available_height = $name_bottom - $name_top - 18;
            $scale = min(
                $available_width / max(1, $text_width),
                $available_height / max(1, $text_height),
                3
            );
            $target_width = max(1, (int) round($text_width * $scale));
            $target_height = max(1, (int) round($text_height * $scale));

            // GD's built-in fonts are only 5-15 pixels tall. Render to a small
            // transparent canvas and scale it up so servers without a TTF font
            // still produce a readable card (the common live-server case).
            $text_image = imagecreatetruecolor($text_width, $text_height);
            imagealphablending($text_image, false);
            imagesavealpha($text_image, true);
            $text_transparent = imagecolorallocatealpha($text_image, 0, 0, 0, 127);
            imagefill($text_image, 0, 0, $text_transparent);
            $text_colour = imagecolorallocate($text_image, 245, 220, 130);
            imagestring($text_image, $font, 0, 0, $name, $text_colour);

            $text_x = (int) round($name_left + (($name_right - $name_left - $target_width) / 2));
            $text_y = (int) round($name_top + (($name_bottom - $name_top - $target_height) / 2));
            imagecopyresampled(
                $image,
                $text_image,
                $text_x,
                $text_y,
                0,
                0,
                $target_width,
                $target_height,
                $text_width,
                $text_height
            );
            imagedestroy($text_image);
        }

        $this->ensure_upload_directory();
        $filename = 'qr_card_' . $participant->id . '_' . time() . '.png';
        $filepath = $this->upload_dir . $filename;
        $fileurl = $this->upload_url . $filename;

        imagepng($image, $filepath, 9);
        imagedestroy($image);

        if ($cleanup_file && file_exists($cleanup_file)) {
            @unlink($cleanup_file);
        }

        $this->cleanup_old_cards($participant->id, $filename);

        return $fileurl;
    }

    /**
     * Generate QR card image matching the screenshot design
     */

    // private function generate_qr_card_image($participant, $display_name)
    // {
    //     $referral_link = home_url('/survey?ref=' . $participant->referral_code);

    //     if (!extension_loaded('gd')) {
    //         error_log('RTS: GD extension not available');
    //         return false;
    //     }

    //     // ======================================================
    //     // CARD SIZE (LANDSCAPE)
    //     // ======================================================
    //     $width  = 1200;
    //     $height = 700;

    //     $image = imagecreatetruecolor($width, $height);

    //     // ======================================================
    //     // COLORS
    //     // ======================================================
    //     $black      = imagecolorallocate($image, 0, 0, 0);
    //     $white      = imagecolorallocate($image, 255, 255, 255);
    //     $gold       = imagecolorallocate($image, 212, 175, 55);   // royal gold
    //     $lightGold  = imagecolorallocate($image, 240, 205, 110);
    //     $gray       = imagecolorallocate($image, 200, 200, 200);
    //     $darkGray   = imagecolorallocate($image, 45, 45, 45);

    //     imagefill($image, 0, 0, $black);

    //     // ======================================================
    //     // DOUBLE GOLD BORDER
    //     // ======================================================
    //     imagerectangle($image, 8, 8, $width - 8, $height - 8, $gold);
    //     imagerectangle($image, 16, 16, $width - 16, $height - 16, $gold);

    //     // ======================================================
    //     // VERTICAL DIVIDER
    //     // ======================================================
    //     $dividerX = 430;
    //     imageline($image, $dividerX, 60, $dividerX, $height - 60, $gold);

    //     // ======================================================
    //     // FONTS
    //     // ======================================================
    //     // Put these fonts in: /wp-content/uploads/run-the-seas/fonts/
    //     $font_dir = WP_CONTENT_DIR . '/uploads/run-the-seas/fonts/';

    //     $font_logo  = $font_dir . 'Cinzel-Bold.ttf';
    //     $font_title = $font_dir . 'Cinzel-SemiBold.ttf';
    //     $font_body  = $font_dir . 'Montserrat-Regular.ttf';
    //     $font_bold  = $font_dir . 'Montserrat-Bold.ttf';

    //     // Fallback if fonts missing
    //     if (!file_exists($font_logo)) {
    //         imagestring($image, 5, 470, 100, 'RUN THE SEAS', $gold);
    //     }

    //     // ======================================================
    //     // LEFT SECTION (QR)
    //     // ======================================================
    //     $frameX = 70;
    //     $frameY = 150;
    //     $frameSize = 280;

    //     // Gold outer frame
    //     imagefilledrectangle(
    //         $image,
    //         $frameX - 18,
    //         $frameY - 18,
    //         $frameX + $frameSize + 18,
    //         $frameY + $frameSize + 18,
    //         $gold
    //     );

    //     // Black middle
    //     imagefilledrectangle(
    //         $image,
    //         $frameX - 10,
    //         $frameY - 10,
    //         $frameX + $frameSize + 10,
    //         $frameY + $frameSize + 10,
    //         $black
    //     );

    //     // White QR background
    //     imagefilledrectangle(
    //         $image,
    //         $frameX,
    //         $frameY,
    //         $frameX + $frameSize,
    //         $frameY + $frameSize,
    //         $white
    //     );

    //     // ======================================================
    //     // GENERATE QR
    //     // ======================================================
    //     $qrSize = 260;

    //     $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=' .
    //         $qrSize . 'x' . $qrSize .
    //         '&data=' . urlencode($referral_link) .
    //         '&format=png&ecc=H&margin=2';

    //     $qr_temp = download_url($qr_url);

    //     if (!is_wp_error($qr_temp) && file_exists($qr_temp)) {

    //         $qr_image = imagecreatefrompng($qr_temp);

    //         if ($qr_image) {

    //             imagecopyresampled(
    //                 $image,
    //                 $qr_image,
    //                 $frameX + 10,
    //                 $frameY + 10,
    //                 0,
    //                 0,
    //                 $qrSize,
    //                 $qrSize,
    //                 imagesx($qr_image),
    //                 imagesy($qr_image)
    //             );

    //             imagedestroy($qr_image);
    //         }

    //         @unlink($qr_temp);
    //     }

    //     // ======================================================
    //     // LEFT FOOTER
    //     // ======================================================
    //     $leftFooter = 'RUN. EXPLORE. CELEBRATE. BELONG';

    //     if (file_exists($font_bold)) {

    //         $bbox = imagettfbbox(18, 0, $font_bold, $leftFooter);
    //         $textWidth = $bbox[2] - $bbox[0];

    //         imagettftext(
    //             $image,
    //             18,
    //             0,
    //             ($dividerX - $textWidth) / 2,
    //             500,
    //             $gold,
    //             $font_bold,
    //             $leftFooter
    //         );
    //     }

    //     // ======================================================
    //     // RIGHT SECTION
    //     // ======================================================
    //     $x = 500;

    //     // LOGO
    //     if (file_exists($font_logo)) {
    //         imagettftext($image, 48, 0, $x, 120, $gold, $font_logo, 'RUN THE SEAS');
    //     }

    //     // Decorative line
    //     imageline($image, $x - 10, 155, 1120, 155, $gold);

    //     // NAME
    //     $name = strtoupper($display_name);

    //     if (file_exists($font_title)) {
    //         imagettftext($image, 36, 0, $x, 245, $gold, $font_title, $name);
    //     }

    //     // Subtitle
    //     if (file_exists($font_bold)) {
    //         imagettftext(
    //             $image,
    //             20,
    //             0,
    //             $x,
    //             290,
    //             $lightGold,
    //             $font_bold,
    //             'RUN THE SEAS COMMUNITY AMBASSADOR'
    //         );
    //     }

    //     // Small separator
    //     imageline($image, $x, 320, 1050, 320, $gold);

    //     // Tagline
    //     if (file_exists($font_body)) {
    //         imagettftext(
    //             $image,
    //             19,
    //             0,
    //             $x,
    //             375,
    //             $white,
    //             $font_body,
    //             'Help shape the future of Run The Seas™'
    //         );
    //     }

    //     // Gold ornament line
    //     imageline($image, $x + 20, 415, 980, 415, $gold);

    //     // SCAN TITLE
    //     if (file_exists($font_bold)) {
    //         imagettftext(
    //             $image,
    //             26,
    //             0,
    //             $x + 35,
    //             470,
    //             $gold,
    //             $font_bold,
    //             'SCAN TO TAKE THE SURVEY'
    //         );
    //     }

    //     // Supporting text
    //     if (file_exists($font_body)) {

    //         imagettftext(
    //             $image,
    //             16,
    //             0,
    //             $x + 35,
    //             505,
    //             $gray,
    //             $font_body,
    //             'Every verified survey moves me 1 KM closer'
    //         );

    //         imagettftext(
    //             $image,
    //             16,
    //             0,
    //             $x + 140,
    //             530,
    //             $gray,
    //             $font_body,
    //             'to my next virtual trophy.'
    //         );
    //     }

    //     // Bottom decorative line
    //     imageline($image, $x + 80, 575, 940, 575, $gold);

    //     // Website
    //     if (file_exists($font_title)) {

    //         $web = 'www.runtheseas.com';

    //         $bbox = imagettfbbox(20, 0, $font_title, $web);
    //         $webWidth = $bbox[2] - $bbox[0];

    //         imagettftext(
    //             $image,
    //             20,
    //             0,
    //             $x + (520 - $webWidth) / 2,
    //             620,
    //             $gold,
    //             $font_title,
    //             $web
    //         );
    //     }

    //     // ======================================================
    //     // DISCLAIMER
    //     // ======================================================
    //     $disclaimer = 'Independent Run The Seas™ Community Ambassador. Not an employee, agent or authorized sales representative of Run The Seas™. No authority to make commitments, provide official information, accept reservations or collect payments.';

    //     if (file_exists($font_body)) {

    //         $lines = explode("\n", wordwrap($disclaimer, 110));

    //         $y = 660;

    //         foreach ($lines as $line) {

    //             imagettftext(
    //                 $image,
    //                 9,
    //                 0,
    //                 25,
    //                 $y,
    //                 $gray,
    //                 $font_body,
    //                 $line
    //             );

    //             $y += 14;
    //         }
    //     }

    //     // ======================================================
    //     // SAVE
    //     // ======================================================
    //     $filename = 'qr_card_' . $participant->id . '_' . time() . '.png';
    //     $filepath = $this->upload_dir . $filename;
    //     $fileurl  = $this->upload_url . $filename;

    //     imagepng($image, $filepath, 9);
    //     imagedestroy($image);

    //     $this->cleanup_old_cards($participant->id, $filename);

    //     return $fileurl;
    // }
    private function generate_qr_card_image($participant, $display_name)
    {
        $template_source = $this->get_qr_card_template_source();
        if ($template_source) {
            $generated = $this->generate_qr_card_from_template($participant, $display_name, $template_source);
            if ($generated) {
                return $generated;
            }
        }

        $referral_link = home_url('/survey?ref=' . $participant->referral_code);
        $member_number = str_pad($participant->id, 6, '0', STR_PAD_LEFT);

        if (!extension_loaded('gd')) {
            error_log('RTS: GD extension not available for QR card generation');
            return false;
        }

        // Card dimensions
        $width = 600;
        $height = 900;
        $image = imagecreatetruecolor($width, $height);

        // Colors - matching the design
        $black = imagecolorallocate($image, 0, 0, 0);
        $white = imagecolorallocate($image, 255, 255, 255);
        $gold = imagecolorallocate($image, 255, 215, 0);
        $light_gold = imagecolorallocate($image, 255, 230, 150);
        $gray = imagecolorallocate($image, 180, 180, 180);
        $light_gray = imagecolorallocate($image, 150, 150, 150);
        $dark_gray = imagecolorallocate($image, 80, 80, 80);

        // Fill background with black
        imagefill($image, 0, 0, $black);

        // Draw gold borders
        imagerectangle($image, 8, 8, $width - 8, $height - 8, $gold);
        imagerectangle($image, 14, 14, $width - 14, $height - 14, $gold);

        // ============================================
        // RUN and SEAS on separate lines with wave/curve
        // ============================================
        $y_pos = 30;

        // "RUN" - large, centered
        $run_text = 'RUN';
        imagestring($image, 5, ($width - (strlen($run_text) * 10)) / 2, $y_pos, $run_text, $gold);
        $y_pos += 38;

        // "SEAS" - large, centered
        $seas_text = 'SEAS';
        imagestring($image, 5, ($width - (strlen($seas_text) * 10)) / 2, $y_pos, $seas_text, $gold);
        $y_pos += 20;

        // Decorative line/wave (simulated with dashes)
        for ($i = 20; $i < $width - 20; $i += 6) {
            $y_offset = ($i % 12 < 6) ? 0 : 4;
            imagestring($image, 1, $i, $y_pos + $y_offset, '-', $gold);
        }
        $y_pos += 20;

        // ============================================
        // DISPLAY NAME - UPPERCASE, large, white
        // ============================================
        $name_text = strtoupper($display_name);
        $name_length = strlen($name_text);

        // Choose font size based on name length
        if ($name_length <= 10) {
            $font_size = 5;
            $char_width = 10;
        } elseif ($name_length <= 16) {
            $font_size = 4;
            $char_width = 8;
        } elseif ($name_length <= 22) {
            $font_size = 3;
            $char_width = 7;
        } else {
            $font_size = 2;
            $char_width = 6;
        }

        $name_width = $name_length * $char_width;
        $name_x = ($width - $name_width) / 2;

        imagestring($image, $font_size, $name_x, $y_pos, $name_text, $white);
        $y_pos += 45;

        // ============================================
        // SUBTITLE - RUNTHESEASCOMMUNITYAMBASSADOR
        // ============================================
        $sub_text = 'RUNTHESEASCOMMUNITYAMBASSADOR';
        imagestring($image, 3, ($width - (strlen($sub_text) * 7)) / 2, $y_pos, $sub_text, $light_gold);
        $y_pos += 30;

        // ============================================
        // TAGLINE - "Help shape the future of Run The Seas"
        // ============================================
        $tagline = 'Help shape the future of Run The Seas';
        imagestring($image, 2, ($width - (strlen($tagline) * 7)) / 2, $y_pos, $tagline, $gray);
        $y_pos += 30;

        // ============================================
        // QR CODE
        // ============================================
        $qr_size = 200;
        $qr_x = ($width - $qr_size) / 2;
        $qr_y = $y_pos;

        // White background for QR code
        imagefilledrectangle($image, $qr_x - 5, $qr_y - 5, $qr_x + $qr_size + 5, $qr_y + $qr_size + 5, $white);

        // Generate QR code
        $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $qr_size . 'x' . $qr_size . '&data=' . urlencode($referral_link) . '&format=png&ecc=H&margin=6';
        $qr_temp = $this->download_remote_file($qr_url);

        if (!is_wp_error($qr_temp) && file_exists($qr_temp)) {
            $qr_image = imagecreatefrompng($qr_temp);
            if ($qr_image) {
                imagecopy($image, $qr_image, $qr_x, $qr_y, 0, 0, $qr_size, $qr_size);
                imagedestroy($qr_image);
            }
            @unlink($qr_temp);
        }

        // Logo overlay on QR code
        $logo_url = get_site_icon_url(64);
        if (!$logo_url) {
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo_url = wp_get_attachment_image_url($custom_logo_id, 'thumbnail');
            }
        }
        if ($logo_url) {
            $logo_temp = $this->download_remote_file($logo_url);
            if (!is_wp_error($logo_temp) && file_exists($logo_temp)) {
                $logo_data = file_get_contents($logo_temp);
                $logo_image = imagecreatefromstring($logo_data);
                if ($logo_image) {
                    $logo_w = imagesx($logo_image);
                    $logo_h = imagesy($logo_image);
                    $logo_size = 40;
                    $ratio = $logo_w / $logo_h;
                    if ($ratio > 1) {
                        $new_w = $logo_size;
                        $new_h = $logo_size / $ratio;
                    } else {
                        $new_w = $logo_size * $ratio;
                        $new_h = $logo_size;
                    }

                    // Black background for logo
                    $bg_x = ($width - $logo_size) / 2 - 4;
                    $bg_y = $qr_y + ($qr_size - $logo_size) / 2 - 4;
                    imagefilledrectangle($image, $bg_x, $bg_y, $bg_x + $logo_size + 8, $bg_y + $logo_size + 8, $black);

                    $logo_x = ($width - $new_w) / 2;
                    $logo_y = $qr_y + ($qr_size - $new_h) / 2;
                    imagecopyresampled($image, $logo_image, $logo_x, $logo_y, 0, 0, $new_w, $new_h, $logo_w, $logo_h);
                    imagedestroy($logo_image);
                }
                @unlink($logo_temp);
            }
        }

        $y_pos = $qr_y + $qr_size + 20;

        // ============================================
        // SCAN TO TAKE THE SURVEY
        // ============================================
        $scan_text = 'SCAN TO TAKE THE SURVEY';
        imagestring($image, 3, ($width - (strlen($scan_text) * 7)) / 2, $y_pos, $scan_text, $gold);
        $y_pos += 25;

        // ============================================
        // EVERY VERIFIED SURVEY...
        // ============================================
        $verify_text = 'Every verified survey moves me 1KM closer';
        imagestring($image, 2, ($width - (strlen($verify_text) * 7)) / 2, $y_pos, $verify_text, $gray);
        $y_pos += 18;

        $verify_text2 = 'to my next virtual trophy.';
        imagestring($image, 2, ($width - (strlen($verify_text2) * 7)) / 2, $y_pos, $verify_text2, $gray);
        $y_pos += 30;

        // ============================================
        // RUN.EXPLORE.CELEBRATE.BELONG
        // ============================================
        $footer_text = 'RUN.EXPLORE.CELEBRATE.BELONG';
        imagestring($image, 3, ($width - (strlen($footer_text) * 7)) / 2, $y_pos, $footer_text, $gold);
        $y_pos += 28;

        // ============================================
        // WEBSITE
        // ============================================
        $web_text = 'www.runtheseas.com';
        imagestring($image, 2, ($width - (strlen($web_text) * 7)) / 2, $y_pos, $web_text, $light_gray);
        $y_pos += 35;

        // ============================================
        // DISCLAIMER
        // ============================================
        $disclaimer = 'DISCLAIMER: Independent Run The Seas™ Community Ambassador. Not an employee, agent or authorized sales representative of Run The Seas™. No authority to make commitments, provide official information, accept reservations or collect payments.';

        $disclaimer_lines = wordwrap($disclaimer, 42, "\n");
        $disclaimer_lines = explode("\n", $disclaimer_lines);
        $line_height = 13;

        foreach ($disclaimer_lines as $index => $line) {
            $line_y = $y_pos + ($index * $line_height);
            if ($line_y < $height - 15) {
                imagestring($image, 1, 15, $line_y, $line, $light_gray);
            }
        }

        // Save image
        $this->ensure_upload_directory();
        $filename = 'qr_card_' . $participant->id . '_' . time() . '.png';
        $filepath = $this->upload_dir . $filename;
        $fileurl = $this->upload_url . $filename;

        imagepng($image, $filepath, 9);
        imagedestroy($image);

        // Clean up old cards
        $this->cleanup_old_cards($participant->id, $filename);

        return $fileurl;
    }

    /**
     * Clean up old card files
     */
    private function cleanup_old_cards($participant_id, $current_file)
    {
        $files = glob($this->upload_dir . 'qr_card_' . $participant_id . '_*.png');
        foreach ($files as $file) {
            $basename = basename($file);
            if ($basename !== $current_file) {
                @unlink($file);
            }
        }
    }

    /**
     * Get terms HTML
     */
    private function get_terms_html()
    {
        ob_start();
?>
        <div style="font-size: 13px; line-height: 1.6; text-align: left;">
            <p><strong>Before downloading a card, you must agree to the following program rules:</strong></p>
            <ul style="list-style: none; padding: 0; margin: 10px 0;">
                <li style="padding: 4px 0; border-bottom: 1px solid #eee;">✓ I will use only the approved card generated inside the Captain's Suite and will not alter the logo, wording, colours, title, disclaimer or QR code.</li>
                <li style="padding: 4px 0; border-bottom: 1px solid #eee;">✓ I will not claim to be an employee, agent, official spokesperson, travel agent, salesperson or authorized representative of Run The Seas™.</li>
                <li style="padding: 4px 0; border-bottom: 1px solid #eee;">✓ I will not make promises about cruise dates, routes, prices, availability, race details, credits, rewards or whether a sailing will proceed.</li>
                <li style="padding: 4px 0; border-bottom: 1px solid #eee;">✓ I will not accept money, deposits, bookings, personal financial information or reservation requests on behalf of Run The Seas™.</li>
                <li style="padding: 4px 0; border-bottom: 1px solid #eee;">✓ I will not use spam, harassment, deception, pressure tactics, automated messaging or misleading statements.</li>
                <li style="padding: 4px 0; border-bottom: 1px solid #eee;">✓ I will not distribute cards where solicitation is prohibited or where the property owner, race organizer, club or business has not given permission.</li>
                <li style="padding: 4px 0; border-bottom: 1px solid #eee;">✓ I will treat every person respectfully and follow all applicable laws, event rules and community standards.</li>
                <li style="padding: 4px 0; border-bottom: 1px solid #eee;">✓ I will stop using the card immediately if Run The Seas™ suspends or ends my Community Ambassador privilege.</li>
                <li style="padding: 4px 0;">✓ I understand that Run The Seas™ may deactivate my QR code and revoke my card access at any time to protect the brand or the public.</li>
            </ul>
        </div>
    <?php
        return ob_get_clean();
    }

    public function render_qr_content()
    {
        if (!is_user_logged_in()) {
            echo '<p>Please login to view your QR code.</p>';
            return;
        }

        $user = wp_get_current_user();
        $participant = $this->registration->get_participant_for_user($user);

        if (!$participant) {
            echo '<p>No registration found. Please complete your registration.</p>';
            return;
        }

        $total_miles = intval($participant->total_captain_miles_earned);
        // $has_5k = $total_miles >= 5000;        
        $referral_link = home_url('/survey?ref=' . $participant->referral_code);
        $referral_code = $participant->referral_code;

        // Get display name
        $display_name = get_user_meta($participant->user_id, 'rts_qr_display_name', true);
        if (empty($display_name)) {
            $display_name = function_exists('bp_core_get_user_displayname')
                ? bp_core_get_user_displayname($participant->user_id)
                : $participant->first_name . ' ' . $participant->last_name;
        }

        // Check terms
        $terms_accepted = get_user_meta($participant->user_id, 'rts_qr_terms_accepted', true);
        $terms_version = get_user_meta($participant->user_id, 'rts_qr_terms_version', true);
        $needs_terms = ($terms_accepted != '1' || $terms_version !== $this->terms_version);

        // Always regenerate card to ensure correct layout
        $card_url = $this->generate_qr_card_image($participant, $display_name);
        if ($card_url) {
            update_user_meta($participant->user_id, 'rts_qr_card_url', $card_url);
        }

    ?>
        <div style="max-width: 650px; margin: 0 auto; padding: 20px;">

            <?php //if (!$has_5k): 
            ?>
            <!-- <div style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 12px; padding: 40px 20px; text-align: center; margin-bottom: 20px;">
                    <div style="font-size: 48px; margin-bottom: 15px;">🏃</div>
                    <h3 style="color: #856404; margin: 0;">Keep Going, Captain!</h3>
                    <p style="color: #856404; font-size: 16px; max-width: 500px; margin: 10px auto;">
                        You need <strong><?php echo esc_html((int) ceil(max(0, 5000 - $total_miles) / 1000)); ?></strong> more verified referrals
                        to unlock your QR Code Card.
                    </p>
                    <div style="background: #fff; border-radius: 8px; padding: 15px; margin: 15px auto; max-width: 400px;">
                        <div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 5px;">
                            <span>Progress</span>
                            <span><?php echo rts_format_miles($total_miles); ?> / 5 km</span>
                        </div>
                        <div style="height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden;">
                            <div style="height: 100%; width: <?php echo min(($total_miles / 5000) * 100, 100); ?>%; background: linear-gradient(90deg, #1a7efb, #28a745); border-radius: 4px;"></div>
                        </div>
                    </div>
                    <p style="font-size: 13px; color: #856404;">💡 Invite friends to join. Every verified referral advances you by 1 km.</p>
                </div> -->

            <?php //$this->render_referral_share_options($participant, $referral_link); 
            ?>

            <?php //else: 
            ?>
            <h2 style="text-align: center; color: #1a7efb;">Your QR Card</h2>
            <p style="text-align: center; color: #666; margin-bottom: 20px;">Share this card with friends and family</p>

            <!-- Name Update Section -->
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #dee2e6;">
                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; justify-content: center;">
                    <label style="font-weight: 600; color: #333;">Display Name:</label>
                    <input type="text" id="rts-card-name" value="<?php echo esc_attr($display_name); ?>"
                        style="flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;"
                        maxlength="50">
                    <button onclick="rtsUpdateCardName()" style="padding: 8px 20px; background: #1a7efb; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: 600;">
                        Update Name
                    </button>
                    <span id="rts-name-status" style="font-size: 13px;"></span>
                </div>
                <p style="font-size: 11px; color: #999; text-align: center; margin: 5px 0 0;">Use your real name (2-50 characters). Names are reviewed for inappropriate language before appearing on your QR card.</p>
            </div>

            <?php if ($needs_terms): ?>
                <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 12px 15px; margin-bottom: 15px; text-align: center;">
                    <span style="color: #856404; font-size: 14px;">⚠️ You must accept the program rules before downloading or sharing your QR card.</span>
                </div>
            <?php endif; ?>

            <!-- QR Card Display -->
            <div id="rts-card-container" style="display: flex; justify-content: center; margin: 20px 0;">
                <?php if ($card_url): ?>
                    <img src="<?php echo esc_url($card_url . '?v=' . time()); ?>" alt="QR Card" style="max-width: 100%; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
                <?php else: ?>
                    <div style="padding: 30px; text-align: center; background: #f8f9fa; border-radius: 12px; border: 2px dashed #dee2e6;">
                        <p style="color: #666;">Generating your QR card...</p>
                        <div class="spinner is-active" style="float: none; margin: 10px auto;"></div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Action Buttons -->
            <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin: 20px 0;">
                <button onclick="rtsDownloadQRCard()" style="padding: 12px 30px; background: #28a745; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 15px;">
                    📥 Download QR Card
                </button>

                <button onclick="rtsShareQRCard()" style="padding: 12px 30px; background: #1a7efb; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 15px;">
                    📤 Share QR Card
                </button>

                <button onclick="rtsCopyLink('<?php echo esc_url($referral_link); ?>')" style="padding: 12px 30px; background: #6c757d; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 15px;">
                    📋 Copy Link
                </button>
            </div>

            <div style="margin-top: 20px; font-size: 12px; color: #999; text-align: center;">
                <p>Share your QR card with friends and family. Each verified referral advances you by <strong>1 km in the 42.2K Referral Marathon Challenge</strong>.</p>
            </div>
            <?php //endif; 
            ?>
        </div>

        <script id="rts-qr-data" type="application/json">
            <?php echo wp_json_encode(array(
                'participant_id' => intval($participant->id),
                'card_url' => esc_url_raw($card_url),
                'referral_link' => esc_url_raw($referral_link),
                'referral_code' => esc_attr($referral_code),
                'needs_terms' => $needs_terms,
            )); ?>
        </script>
    <?php
    }

    /**
     * AJAX: Update card name
     */
    public function ajax_update_card_name()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rts_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        $participant_id = intval($_POST['participant_id']);
        $display_name = sanitize_text_field($_POST['display_name']);

        if (!$participant_id) {
            wp_send_json_error('Invalid participant ID');
            return;
        }
        if (empty($display_name)) {
            wp_send_json_error('Display name cannot be empty');
            return;
        }

        // $validation = $this->validate_display_name($display_name);
        // if (!$validation['valid']) {
        //     wp_send_json_error($validation['message']);
        //     return;
        // }

        $participant = $this->registration->get_participant($participant_id);
        if (!$participant) {
            wp_send_json_error('Participant not found');
            return;
        }

        $user = wp_get_current_user();
        if ($participant->user_id != $user->ID && !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $moderation = $this->moderate_display_name($display_name);
        if (!$moderation['valid']) {
            wp_send_json_error($moderation['message']);
            return;
        }

        update_user_meta($participant->user_id, 'rts_qr_display_name', $display_name);
        update_user_meta($participant->user_id, 'rts_qr_terms_accepted', '0');
        update_user_meta($participant->user_id, 'rts_qr_terms_version', '');

        $card_url = $this->generate_qr_card_image($participant, $display_name);
        if ($card_url) {
            update_user_meta($participant->user_id, 'rts_qr_card_url', $card_url);
            wp_send_json_success(array(
                'message' => 'Name updated successfully',
                'display_name' => $display_name,
                'card_url' => $card_url
            ));
        } else {
            wp_send_json_success(array(
                'message' => 'Name updated, but card generation failed',
                'display_name' => $display_name
            ));
        }
    }

    /**
     * AJAX: Generate QR card
     */
    public function ajax_generate_qr_card()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rts_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        $participant_id = intval($_POST['participant_id']);
        if (!$participant_id) {
            wp_send_json_error('Invalid participant ID');
            return;
        }

        $participant = $this->registration->get_participant($participant_id);
        if (!$participant) {
            wp_send_json_error('Participant not found');
            return;
        }

        $user = wp_get_current_user();
        if ($participant->user_id != $user->ID && !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $display_name = get_user_meta($participant->user_id, 'rts_qr_display_name', true);
        if (empty($display_name)) {
            $display_name = function_exists('bp_core_get_user_displayname')
                ? bp_core_get_user_displayname($participant->user_id)
                : $participant->first_name . ' ' . $participant->last_name;
        }

        $card_url = $this->generate_qr_card_image($participant, $display_name);
        if ($card_url) {
            update_user_meta($participant->user_id, 'rts_qr_card_url', $card_url);
            wp_send_json_success(array(
                'card_url' => $card_url,
                'display_name' => $display_name
            ));
        } else {
            wp_send_json_error('Failed to generate QR card');
        }
    }

    /**
     * AJAX: Check QR terms
     */
    public function ajax_check_qr_terms()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rts_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        $participant_id = intval($_POST['participant_id']);
        if (!$participant_id) {
            wp_send_json_error('Invalid participant ID');
            return;
        }

        $participant = $this->registration->get_participant($participant_id);
        if (!$participant) {
            wp_send_json_error('Participant not found');
            return;
        }

        $user = wp_get_current_user();
        if ($participant->user_id != $user->ID && !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $terms_accepted = get_user_meta($participant->user_id, 'rts_qr_terms_accepted', true);
        $terms_version = get_user_meta($participant->user_id, 'rts_qr_terms_version', true);

        $terms_required = ($terms_accepted != '1' || $terms_version !== $this->terms_version);

        wp_send_json_success(array(
            'terms_required' => $terms_required,
            'terms_html' => $this->get_terms_html(),
            'terms_accepted' => $terms_accepted == '1',
            'terms_version' => $terms_version,
            'current_version' => $this->terms_version
        ));
    }

    /**
     * AJAX: Accept QR terms
     */
    public function ajax_accept_qr_terms()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rts_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        $participant_id = intval($_POST['participant_id']);
        if (!$participant_id) {
            wp_send_json_error('Invalid participant ID');
            return;
        }

        $participant = $this->registration->get_participant($participant_id);
        if (!$participant) {
            wp_send_json_error('Participant not found');
            return;
        }

        $user = wp_get_current_user();
        if ($participant->user_id != $user->ID && !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        update_user_meta($participant->user_id, 'rts_qr_terms_accepted', '1');
        update_user_meta($participant->user_id, 'rts_qr_terms_version', $this->terms_version);

        wp_send_json_success(array(
            'message' => 'Terms accepted successfully'
        ));
    }

    private function render_referral_share_options($participant, $referral_link)
    {
    ?>
        <div class="rts-referral-share-section" style="background: #fff; border-radius: 12px; padding: 20px; margin-top: 20px; border: 1px solid #dee2e6;">
            <h4 style="color: #1a7efb; margin: 0 0 10px 0;">🔗 Share Your Referral Link</h4>
            <p style="font-size: 13px; color: #666; margin-bottom: 10px;">Share this link with friends and family to participate in the 42.2K Referral Marathon Challenge!</p>

            <div style="background: #f8f9fa; padding: 10px 15px; border-radius: 6px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; border: 1px solid #dee2e6; margin: 10px 0;">
                <input type="text" value="<?php echo esc_url($referral_link); ?>" readonly id="rts-share-link" onclick="this.select()" style="flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; font-size: 13px; font-family: monospace; color: #333;">
                <button onclick="rtsCopyLink('<?php echo esc_url($referral_link); ?>')" style="display: inline-flex; align-items: center; gap: 5px; padding: 8px 16px; background: #1a7efb; color: #fff; border: none; border-radius: 6px; font-size: 13px; cursor: pointer; font-weight: 600;">📋 Copy Link</button>
            </div>

            <div style="display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; margin: 10px 0;">
                <button onclick="window.open('https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($referral_link); ?>', '_blank'); rtsTrackShareEvent('share', 'facebook');" style="padding: 6px 14px; background: #1877f2; color: #fff; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 600;">📘 Facebook</button>
                <button onclick="window.open('https://twitter.com/intent/tweet?text=Join%20me%20as%20a%20Founding%20Runner%20with%20Run%20The%20Seas!&url=<?php echo urlencode($referral_link); ?>', '_blank'); rtsTrackShareEvent('share', 'twitter');" style="padding: 6px 14px; background: #000; color: #fff; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 600;">🐦 X</button>
                <button onclick="window.open('https://www.linkedin.com/sharing/share-offsite/?url=<?php echo urlencode($referral_link); ?>', '_blank'); rtsTrackShareEvent('share', 'linkedin');" style="padding: 6px 14px; background: #0a66c2; color: #fff; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 600;">💼 LinkedIn</button>
                <button onclick="window.open('https://api.whatsapp.com/send?text=Join%20me%20as%20a%20Founding%20Runner%20with%20Run%20The%20Seas!%20<?php echo urlencode($referral_link); ?>', '_blank'); rtsTrackShareEvent('share', 'whatsapp');" style="padding: 6px 14px; background: #25D366; color: #fff; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 600;">📱 WhatsApp</button>
                <button onclick="window.open('https://t.me/share/url?url=<?php echo urlencode($referral_link); ?>&text=Join%20me%20as%20a%20Founding%20Runner%20with%20Run%20The%20Seas!', '_blank'); rtsTrackShareEvent('share', 'telegram');" style="padding: 6px 14px; background: #0088cc; color: #fff; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 600;">✈️ Telegram</button>
                <button onclick="window.open('https://www.reddit.com/submit?url=<?php echo urlencode($referral_link); ?>&title=Join%20me%20as%20a%20Founding%20Runner%20with%20Run%20The%20Seas!', '_blank'); rtsTrackShareEvent('share', 'reddit');" style="padding: 6px 14px; background: #ff4500; color: #fff; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 600;">🤖 Reddit</button>
                <button onclick="window.location.href='mailto:?subject=Join%20me%20as%20a%20Founding%20Runner!&body=Join%20me%20as%20a%20Founding%20Runner%20with%20Run%20The%20Seas!%20<?php echo urlencode($referral_link); ?>'; rtsTrackShareEvent('share', 'email');" style="padding: 6px 14px; background: #6c757d; color: #fff; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 600;">📧 Email</button>
            </div>

            <div style="font-size: 11px; color: #999; text-align: center; margin-top: 10px;">💡 Each verified referral advances you by 1 km in the 42.2K Referral Marathon Challenge!</div>
        </div>
<?php
    }
}

function rts_init_buddypress_qr()
{
    global $rts_buddypress_qr_instance;
    if (!isset($rts_buddypress_qr_instance) && class_exists('RTS_BuddyPress_QR')) {
        $rts_buddypress_qr_instance = new RTS_BuddyPress_QR();
    }
    return $rts_buddypress_qr_instance;
}
// This functionality must be available on every WordPress request.  It was
// previously bootstrapped from BuddyPress' bp_init hook, so after BuddyPress
// was removed the shortcode could render but its admin-ajax handlers were
// never registered.  Initialising on core init keeps the standalone page and
// its Update Name action working with BuddyNext/BuddyX or no social plugin.
add_action('init', 'rts_init_buddypress_qr', 1);
