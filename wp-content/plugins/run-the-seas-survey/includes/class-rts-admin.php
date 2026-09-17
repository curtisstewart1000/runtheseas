<?php
class RTS_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Add AJAX handlers
        add_action('wp_ajax_rts_save_survey_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_rts_toggle_survey', array($this, 'ajax_toggle_survey'));
        add_action('wp_ajax_rts_toggle_excluded', array($this, 'ajax_toggle_excluded'));
        add_action('wp_ajax_rts_check_survey_status', array($this, 'ajax_check_survey_status'));
        add_action('admin_post_rts_save_login_design', array($this, 'save_login_design'));
        add_action('admin_post_rts_save_survey_design', array($this, 'save_survey_design'));
        add_action('admin_post_rts_save_verification_email_design', array($this, 'save_verification_email_design'));
        add_action('admin_post_rts_save_certificate_email_design', array($this, 'save_certificate_email_design'));
        add_action('admin_post_rts_save_journey_design', array($this, 'save_journey_design'));
        add_action('admin_post_rts_save_dashboard_design', array($this, 'save_dashboard_design'));
        add_action('admin_post_rts_save_captains_log_design', array($this, 'save_captains_log_design'));
        add_action('admin_post_rts_save_marathon_challenge_design', array($this, 'save_marathon_challenge_design'));
        add_action('admin_post_rts_save_trophy_case_design', array($this, 'save_trophy_case_design'));
        add_action('admin_post_rts_save_marathon_one_trophy_case_design', array($this, 'save_marathon_one_trophy_case_design'));

        // Reset AJAX handlers
        add_action('wp_ajax_rts_reset_survey', array($this, 'ajax_reset_survey'));
        add_action('wp_ajax_rts_reset_question', array($this, 'ajax_reset_question'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Survey Management',
            'Surveys',
            RTS_MANAGE_CAPABILITY,
            'rts-survey-management',
            array($this, 'render_surveys_page'),
            'dashicons-clipboard',
            30
        );
        
        add_submenu_page(
            'rts-survey-management',
            'Survey Settings',
            'Settings',
            RTS_MANAGE_CAPABILITY,
            'rts-survey-settings',
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            'rts-survey-management',
            "Captain's Suite Login Design",
            'Login Design',
            RTS_MANAGE_CAPABILITY,
            'rts-login-design',
            array($this, 'render_login_design_page')
        );

        add_submenu_page(
            'rts-survey-management',
            'Verification Email Design',
            'Verification Email',
            RTS_MANAGE_CAPABILITY,
            'rts-verification-email-design',
            array($this, 'render_verification_email_design_page')
        );

        add_submenu_page(
            'rts-survey-management',
            'Certificate Email Design',
            'Certificate Email',
            RTS_MANAGE_CAPABILITY,
            'rts-certificate-email-design',
            array($this, 'render_certificate_email_design_page')
        );

        add_submenu_page(
            'rts-survey-management',
            'View Journey Design',
            'Journey Design',
            RTS_MANAGE_CAPABILITY,
            'rts-journey-design',
            array($this, 'render_journey_design_page')
        );

        add_submenu_page(
            'rts-survey-management',
            'Survey Design',
            'Survey Design',
            RTS_MANAGE_CAPABILITY,
            'rts-survey-design',
            array($this, 'render_survey_design_page')
        );

        add_submenu_page(
            'rts-survey-management',
            "Captain's Dashboard Design",
            'Dashboard Design',
            RTS_MANAGE_CAPABILITY,
            'rts-dashboard-design',
            array($this, 'render_dashboard_design_page')
        );

        add_submenu_page(
            'rts-survey-management',
            "Captain's Log Design",
            "Captain's Log",
            RTS_MANAGE_CAPABILITY,
            'rts-captains-log-design',
            array($this, 'render_captains_log_design_page')
        );

        add_submenu_page(
            'rts-survey-management',
            'Marathon Challenge Design',
            'Marathon Challenge',
            RTS_MANAGE_CAPABILITY,
            'rts-marathon-challenge-design',
            array($this, 'render_marathon_challenge_design_page')
        );

        add_submenu_page(
            'rts-survey-management',
            'Marathon 1 Trophy Design',
            'Marathon 1 Trophies',
            RTS_MANAGE_CAPABILITY,
            'rts-marathon-one-trophy-design',
            array($this, 'render_marathon_one_trophy_case_design_page')
        );

        add_submenu_page(
            'rts-survey-management',
            'Trophy Case Design',
            'Marathon 2 Trophies',
            RTS_MANAGE_CAPABILITY,
            'rts-trophy-case-design',
            array($this, 'render_trophy_case_design_page')
        );

        // add_submenu_page(
        //     'rts-survey-management',
        //     'Survey Analytics',
        //     'Analytics',
        //     'manage_options',
        //     'rts-survey-analytics',
        //     array($this, 'render_analytics_page')
        // );       
        
        // Add Referral Dashboard
        add_submenu_page(
            'rts-survey-management',
            'Referral Dashboard',
            '🔗 Referrals',
            RTS_MANAGE_CAPABILITY,
            'rts-referral-dashboard',
            array($this, 'render_referral_dashboard')
        );

        add_submenu_page(
            'rts-survey-management',
            'Captain\'s Leaderboard',
            'Leaderboard',
            RTS_MANAGE_CAPABILITY,
            'rts-leaderboard',
            array($this, 'render_leaderboard_page')
        );
        
        // Add Referral Details (hidden from menu)
        add_submenu_page(
            null,
            'Referral Details',
            'Referral Details',
            RTS_MANAGE_CAPABILITY,
            'rts-referral-details',
            array($this, 'render_referral_details')
        );

        add_submenu_page(
            'rts-survey-management',
            'Executive Dashboard',
            '📊 Executive BI',
            RTS_MANAGE_CAPABILITY,
            'rts-executive-dashboard',
            array($this, 'render_executive_dashboard')
        );

        
    }

    public function render_executive_dashboard() {
        global $wpdb;
        $forms_table = $wpdb->prefix . 'fluentform_forms';
        $all_forms = $wpdb->get_var("SHOW TABLES LIKE '$forms_table'") === $forms_table
            ? $wpdb->get_results("SELECT id, title FROM $forms_table ORDER BY title ASC")
            : array();
        $settings = get_option('rts_survey_settings', array());
        $rts_executive_forms = array_values(array_filter($all_forms, function ($form) use ($settings) {
            $setting = isset($settings[$form->id]) ? $settings[$form->id] : array();
            return !empty($setting['active']) && empty($setting['excluded']);
        }));
        include RTS_PLUGIN_PATH . 'templates/executive-dashboard.php';
    }
    
    public function register_settings() {
        register_setting(
            'rts_survey_settings_group',
            'rts_survey_settings',
            array($this, 'sanitize_settings')
        );
    }

    /** Render the Media Library controls used by the Captain's Suite auth skin. */
    public function render_login_design_page() {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        $assets = get_option('rts_captains_suite_auth_assets', array());
        $assets = is_array($assets) ? $assets : array();
        $fields = array(
            'login_logo'   => array('Login-only logo', 'Used only above the Captain\'s Suite form. It does not change the BuddyNext rail or email logo.'),
            'frame_image'  => array('Frame overlay image', 'Use a transparent PNG/SVG containing the complete outer frame and corner ornaments.'),
            'divider_image'=> array('Divider image', 'Use a transparent, wide horizontal PNG/SVG. It is placed below the logo, title, and button area.'),
            'footer_divider_image' => array('Divider below the button', 'Use a transparent, wide horizontal PNG/SVG for the divider directly above the survey message.'),
            'button_image' => array('Access Suite button image', 'Optional. Use the complete button image, including its icon and text. The CSS label is hidden when this is set.'),
            'reset_button_image' => array('Send Reset Link button image', 'Optional. Use a separate complete button image for the forgot-password request form, including its icon and text. It will not affect the sign-in button.'),
        );

        wp_enqueue_media();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e("Captain's Suite Login Design", 'run-the-seas'); ?></h1>
            <p><?php esc_html_e('Select an image from the Media Library or paste an image URL. Transparent PNG or SVG files work best for the frame and dividers.', 'run-the-seas'); ?></p>
            <?php if (isset($_GET['rts_login_design']) && 'saved' === sanitize_key(wp_unslash($_GET['rts_login_design']))) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Login design assets saved.', 'run-the-seas'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="rts_save_login_design">
                <?php wp_nonce_field('rts_save_login_design'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <?php foreach ($fields as $key => $field) : ?>
                            <tr>
                                <th scope="row"><label for="rts-<?php echo esc_attr($key); ?>"><?php echo esc_html($field[0]); ?></label></th>
                                <td>
                                    <input id="rts-<?php echo esc_attr($key); ?>" class="regular-text" type="url" name="rts_captains_suite_auth_assets[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($assets[$key] ?? ''); ?>" placeholder="https://">
                                    <button type="button" class="button rts-select-login-asset" data-target="rts-<?php echo esc_attr($key); ?>"><?php esc_html_e('Select from Media Library', 'run-the-seas'); ?></button>
                                    <p class="description"><?php echo esc_html($field[1]); ?></p>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button(__('Save Login Design', 'run-the-seas')); ?>
            </form>
        </div>
        <script>
        document.addEventListener('click', function (event) {
            var button = event.target.closest('.rts-select-login-asset');
            if (!button || !window.wp || !wp.media) return;
            event.preventDefault();
            var target = document.getElementById(button.getAttribute('data-target'));
            var frame = wp.media({ title: 'Select login design image', library: { type: 'image' }, multiple: false });
            frame.on('select', function () {
                var image = frame.state().get('selection').first().toJSON();
                target.value = image.url;
            });
            frame.open();
        });
        </script>
        <?php
    }

    /** Save the Captain's Suite auth images after capability and nonce checks. */
    public function save_login_design() {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        check_admin_referer('rts_save_login_design');
        $input = isset($_POST['rts_captains_suite_auth_assets']) && is_array($_POST['rts_captains_suite_auth_assets'])
            ? wp_unslash($_POST['rts_captains_suite_auth_assets'])
            : array();
        $assets = array();

        foreach (array('login_logo', 'frame_image', 'divider_image', 'footer_divider_image', 'button_image', 'reset_button_image') as $key) {
            $assets[$key] = isset($input[$key]) ? esc_url_raw(trim((string) $input[$key])) : '';
        }

        update_option('rts_captains_suite_auth_assets', $assets, false);
        wp_safe_redirect(add_query_arg('rts_login_design', 'saved', admin_url('admin.php?page=rts-login-design')));
        exit;
    }

    /** Render Media Library controls for the Founding Runner verification email. */
    public function render_verification_email_design_page() {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        $assets = get_option('rts_verification_email_design_assets', array());
        $assets = is_array($assets) ? $assets : array();
        $fields = array(
            'complete_header_image' => array(
                'Complete header banner',
                'Recommended for the exact approved design. Upload the full navy header containing the logo, tagline, and supporting text. When set, it replaces the separate logo and generated tagline.'
            ),
            'header_image' => array(
                'Run The Seas logo',
                'Fallback option. Use the transparent logo only when a Complete header banner is not selected.'
            ),
            'hero_image' => array(
                'Cruise hero image',
                'The large cruise image below the headline. A landscape image at least 1200px wide works best.'
            ),
            'headline_divider_image' => array(
                'Divider below the main headline',
                'A transparent horizontal ornament displayed below “Your Founding Runner Cruise Credit Is Almost Ready!”.'
            ),
            'name_divider_image' => array(
                'Divider below the recipient name',
                'A transparent horizontal ornament displayed below the dynamic “Ahoy Name” text.'
            ),
            'email_icon_image' => array(
                'Email icon on the cruise banner',
                'The circular envelope icon displayed at the bottom of the cruise-image banner.'
            ),
            'lock_icon_image' => array(
                'Confirmation lock icon',
                'The lock or shield icon displayed above “Why we need you to confirm your email”.'
            ),
            'complete_button_image' => array(
                'Complete confirmation button image',
                'Upload the complete Confirm My Email Address button, including its left logo, text, background, and right icon. The entire image becomes the secure verification link.'
            ),
        );

        wp_enqueue_media();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Verification Email Design', 'run-the-seas'); ?></h1>
            <p><?php esc_html_e('This email uses a dependable HTML-email layout rather than Elementor, so it displays correctly in Gmail, Outlook, and mobile email apps. The recipient name and secure verification link are added automatically.', 'run-the-seas'); ?></p>
            <?php if (isset($_GET['rts_verification_email_design']) && 'saved' === sanitize_key(wp_unslash($_GET['rts_verification_email_design']))) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Verification email design saved.', 'run-the-seas'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="rts_save_verification_email_design">
                <?php wp_nonce_field('rts_save_verification_email_design'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <?php foreach ($fields as $key => $field) : ?>
                            <tr>
                                <th scope="row"><label for="rts-verification-email-<?php echo esc_attr($key); ?>"><?php echo esc_html($field[0]); ?></label></th>
                                <td>
                                    <input id="rts-verification-email-<?php echo esc_attr($key); ?>" class="regular-text" type="url" name="rts_verification_email_design_assets[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($assets[$key] ?? ''); ?>" placeholder="https://">
                                    <button type="button" class="button rts-select-verification-email-asset" data-target="rts-verification-email-<?php echo esc_attr($key); ?>"><?php esc_html_e('Select from Media Library', 'run-the-seas'); ?></button>
                                    <p class="description"><?php echo esc_html($field[1]); ?></p>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button(__('Save Verification Email Design', 'run-the-seas')); ?>
            </form>
        </div>
        <script>
        document.addEventListener('click', function (event) {
            var button = event.target.closest('.rts-select-verification-email-asset');
            if (!button || !window.wp || !wp.media) return;
            event.preventDefault();
            var target = document.getElementById(button.getAttribute('data-target'));
            var frame = wp.media({ title: 'Select verification email image', library: { type: 'image' }, multiple: false });
            frame.on('select', function () {
                target.value = frame.state().get('selection').first().toJSON().url;
            });
            frame.open();
        });
        </script>
        <?php
    }

    /** Save optional verification-email artwork after capability and nonce checks. */
    public function save_verification_email_design() {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        check_admin_referer('rts_save_verification_email_design');
        $input = isset($_POST['rts_verification_email_design_assets']) && is_array($_POST['rts_verification_email_design_assets'])
            ? wp_unslash($_POST['rts_verification_email_design_assets'])
            : array();
        $assets = array();
        foreach (array(
            'complete_header_image',
            'header_image',
            'hero_image',
            'headline_divider_image',
            'name_divider_image',
            'email_icon_image',
            'lock_icon_image',
            'complete_button_image',
        ) as $key) {
            $assets[$key] = isset($input[$key]) ? esc_url_raw(trim((string) $input[$key])) : '';
        }

        update_option('rts_verification_email_design_assets', $assets, false);
        wp_safe_redirect(add_query_arg('rts_verification_email_design', 'saved', admin_url('admin.php?page=rts-verification-email-design')));
        exit;
    }

    /** Render Media Library controls for the post-verification certificate email. */
    public function render_certificate_email_design_page() {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        $assets = get_option('rts_certificate_email_design_assets', array());
        $assets = is_array($assets) ? $assets : array();
        $fields = array(
            'complete_header_image' => array('Complete header banner', 'The full navy Run The Seas header with logo and tagline. This most closely matches the supplied design.'),
            'header_logo_image' => array('Header logo', 'Optional fallback logo when a complete header banner is not used.'),
            'hero_image' => array('Cruise hero image', 'Wide image of the ship, ocean, and sky shown behind the congratulations message.'),
            'hero_divider_image' => array('Hero name divider', 'Transparent divider displayed below the large recipient name in the cruise banner.'),
            'certificate_preview_image' => array('Certificate backplate (used everywhere)', 'Upload the empty landscape certificate. It becomes the shared artwork for certificate emails, Captain\'s Suite viewing, printing, and PDF downloads. Name, Founding Runner number, certificate number, approval status, and issue date are added automatically.'),
            'suite_background_image' => array("Captain's Suite background", 'Optional navy/gold background image for the Captain’s Suite panel.'),
            'suite_top_divider_image' => array("Captain's Suite top divider", 'Transparent divider displayed above “Welcome to Your Captain’s Suite”.'),
            'suite_bottom_divider_image' => array("Captain's Suite bottom divider", 'Transparent divider displayed below the Captain’s Suite heading.'),
            'suite_icon_voyage' => array('Voyage updates icon', 'Round icon for voyage updates and key announcements.'),
            'suite_icon_priority' => array('Priority access icon', 'Round icon for priority booking and events.'),
            'suite_icon_marathon' => array('Marathon icon', 'Round icon for the Referral Marathon Challenge.'),
            'suite_icon_avatar' => array('Avatar icon', 'Round icon for the race-route avatar.'),
            'suite_icon_profile' => array('Profile icon', 'Round icon for the profile, certificate, and cruise credit.'),
            'download_button_image' => array('Download certificate button image', 'Optional complete gold Download Certificate button image. It links directly to the recipient’s personalized certificate image.'),
            'suite_button_image' => array("Captain's Suite button image", 'Optional complete gold Enter the Captain’s Suite button image.'),
            'footer_icon_image' => array('Footer icon', 'Optional icon displayed beside the "You are now officially a Founding Runner" footer message.'),
            'footer_foliage_image' => array('Footer foliage image', 'Optional tropical foliage/footer artwork displayed at the bottom of the email.'),
        );

        wp_enqueue_media();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Certificate Email Design', 'run-the-seas'); ?></h1>
            <p><?php esc_html_e('Choose the artwork used after a participant verifies their email. The certificate backplate is shared by certificate emails, the Captain\'s Suite, printing, and downloads. Every field is optional: the approved bundled artwork is used when an image has not been selected.', 'run-the-seas'); ?></p>
            <?php if (isset($_GET['rts_certificate_email_design']) && 'saved' === sanitize_key(wp_unslash($_GET['rts_certificate_email_design']))) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Certificate email design saved.', 'run-the-seas'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="rts_save_certificate_email_design">
                <?php wp_nonce_field('rts_save_certificate_email_design'); ?>
                <table class="form-table" role="presentation"><tbody>
                    <?php foreach ($fields as $key => $field) : ?>
                        <tr>
                            <th scope="row"><label for="rts-certificate-email-<?php echo esc_attr($key); ?>"><?php echo esc_html($field[0]); ?></label></th>
                            <td>
                                <input id="rts-certificate-email-<?php echo esc_attr($key); ?>" class="regular-text" type="url" name="rts_certificate_email_design_assets[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($assets[$key] ?? ''); ?>" placeholder="https://">
                                <button type="button" class="button rts-select-certificate-email-asset" data-target="rts-certificate-email-<?php echo esc_attr($key); ?>"><?php esc_html_e('Select from Media Library', 'run-the-seas'); ?></button>
                                <p class="description"><?php echo esc_html($field[1]); ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>
                <?php submit_button(__('Save Certificate Email Design', 'run-the-seas')); ?>
            </form>
        </div>
        <script>
        document.addEventListener('click', function (event) {
            var button = event.target.closest('.rts-select-certificate-email-asset');
            if (!button || !window.wp || !wp.media) return;
            event.preventDefault();
            var target = document.getElementById(button.getAttribute('data-target'));
            var frame = wp.media({ title: 'Select certificate email image', library: { type: 'image' }, multiple: false });
            frame.on('select', function () { target.value = frame.state().get('selection').first().toJSON().url; });
            frame.open();
        });
        </script>
        <?php
    }

    /** Save the optional certificate-email artwork after capability and nonce checks. */
    public function save_certificate_email_design() {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        check_admin_referer('rts_save_certificate_email_design');
        $input = isset($_POST['rts_certificate_email_design_assets']) && is_array($_POST['rts_certificate_email_design_assets'])
            ? wp_unslash($_POST['rts_certificate_email_design_assets'])
            : array();
        $assets = array();
        foreach (array(
            'complete_header_image', 'header_logo_image', 'hero_image', 'hero_divider_image',
            'certificate_preview_image', 'suite_background_image', 'suite_top_divider_image', 'suite_bottom_divider_image', 'suite_icon_voyage', 'suite_icon_priority',
            'suite_icon_marathon', 'suite_icon_avatar', 'suite_icon_profile', 'download_button_image',
            'suite_button_image', 'footer_icon_image', 'footer_foliage_image',
        ) as $key) {
            $assets[$key] = isset($input[$key]) ? esc_url_raw(trim((string) $input[$key])) : '';
        }

        update_option('rts_certificate_email_design_assets', $assets, false);
        wp_safe_redirect(add_query_arg('rts_certificate_email_design', 'saved', admin_url('admin.php?page=rts-certificate-email-design')));
        exit;
    }

    /** Render artwork controls for the two marked Captain's Dashboard blocks. */
    public function render_dashboard_design_page() {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        $assets = get_option('rts_dashboard_design_assets', array());
        $assets = is_array($assets) ? $assets : array();
        $fields = array(
            'status_frame_image' => array(
                'Status strip frame',
                'Transparent PNG/SVG containing the complete frame around the four status cards.'
            ),
            'referrals_icon_image' => array(
                'Your Referrals icon',
                'Transparent icon displayed in the first status card.'
            ),
            'progress_icon_image' => array(
                'Your Progress icon',
                'Transparent runner/progress icon displayed in the second status card.'
            ),
            'trophies_icon_image' => array(
                'Trophies Unlocked icon',
                'Transparent trophy icon displayed in the third status card.'
            ),
            'next_trophy_icon_image' => array(
                'Next Trophy icon',
                'Transparent icon displayed in the fourth status card.'
            ),
            'leaderboard_frame_image' => array(
                'Leaderboard frame',
                'Transparent PNG/SVG containing the complete outer frame and corner ornaments for the leaderboard.'
            ),
            'leaderboard_left_ornament_image' => array(
                'Leaderboard left ornament',
                'Transparent image displayed immediately to the left of the live Leaderboard title.'
            ),
            'leaderboard_right_ornament_image' => array(
                'Leaderboard right ornament',
                'Transparent image displayed immediately to the right of the live Leaderboard title.'
            ),
            'leaderboard_trophy_icon_image' => array(
                'Default leaderboard trophy icon',
                'Small transparent trophy icon used by the normal leaderboard style.'
            ),
            'leaderboard_trophy_green_image' => array(
                'Marathon trophy icon 1 (green)',
                'First earned trophy icon used by trophy_style="marathon".'
            ),
            'leaderboard_trophy_blue_image' => array(
                'Marathon trophy icon 2 (blue)',
                'Second earned trophy icon used by trophy_style="marathon".'
            ),
            'leaderboard_trophy_purple_image' => array(
                'Marathon trophy icon 3 (purple)',
                'Third earned trophy icon used by trophy_style="marathon".'
            ),
            'leaderboard_trophy_gold_image' => array(
                'Marathon trophy icon 4 (gold)',
                'Fourth earned trophy icon used by trophy_style="marathon".'
            ),
            'leaderboard_trophy_orange_image' => array(
                'Marathon trophy icon 5 (orange)',
                'Fifth earned trophy icon used by trophy_style="marathon".'
            ),
            'leaderboard_trophy_red_image' => array(
                'Marathon trophy icon 6 (red)',
                'Sixth earned trophy icon used by trophy_style="marathon".'
            ),
            'leaderboard_trophy_silver_image' => array(
                'Marathon trophy icon 7 (silver)',
                'Seventh earned trophy icon used by trophy_style="marathon".'
            ),
            'leaderboard_invite_icon_image' => array(
                'Invite friends icon',
                'Small icon displayed beside the leaderboard invitation message.'
            ),
        );

        wp_enqueue_media();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e("Captain's Dashboard Design", 'run-the-seas'); ?></h1>
            <p><?php esc_html_e('Choose the images used by the marked status strip and leaderboard. All fields are optional; polished built-in icons and frames are used as fallbacks.', 'run-the-seas'); ?></p>
            <p><strong><?php esc_html_e('Shortcodes:', 'run-the-seas'); ?></strong> <code>[rts_captain_status]</code> <code>[rts_captain_leaderboard_card]</code> <code>[rts_captain_leaderboard_card limit="8" trophy_style="marathon" max_trophies="7"]</code></p>
            <?php if (isset($_GET['rts_dashboard_design']) && 'saved' === sanitize_key(wp_unslash($_GET['rts_dashboard_design']))) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Dashboard artwork saved.', 'run-the-seas'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="rts_save_dashboard_design">
                <?php wp_nonce_field('rts_save_dashboard_design'); ?>
                <table class="form-table" role="presentation"><tbody>
                    <?php foreach ($fields as $key => $field) : ?>
                        <tr>
                            <th scope="row"><label for="rts-dashboard-<?php echo esc_attr($key); ?>"><?php echo esc_html($field[0]); ?></label></th>
                            <td>
                                <input id="rts-dashboard-<?php echo esc_attr($key); ?>" class="regular-text" type="url" name="rts_dashboard_design_assets[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($assets[$key] ?? ''); ?>" placeholder="https://">
                                <button type="button" class="button rts-select-dashboard-asset" data-target="rts-dashboard-<?php echo esc_attr($key); ?>"><?php esc_html_e('Select from Media Library', 'run-the-seas'); ?></button>
                                <p class="description"><?php echo esc_html($field[1]); ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>
                <?php submit_button(__('Save Dashboard Design', 'run-the-seas')); ?>
            </form>
        </div>
        <script>
        document.addEventListener('click', function (event) {
            var button = event.target.closest('.rts-select-dashboard-asset');
            if (!button || !window.wp || !wp.media) return;
            event.preventDefault();
            var target = document.getElementById(button.getAttribute('data-target'));
            var frame = wp.media({ title: 'Select dashboard image', library: { type: 'image' }, multiple: false });
            frame.on('select', function () { target.value = frame.state().get('selection').first().toJSON().url; });
            frame.open();
        });
        </script>
        <?php
    }

    /** Save Captain's Dashboard artwork after capability and nonce checks. */
    public function save_dashboard_design() {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        check_admin_referer('rts_save_dashboard_design');
        $input = isset($_POST['rts_dashboard_design_assets']) && is_array($_POST['rts_dashboard_design_assets'])
            ? wp_unslash($_POST['rts_dashboard_design_assets'])
            : array();
        $assets = array();
        foreach (array(
            'status_frame_image', 'referrals_icon_image', 'progress_icon_image',
            'trophies_icon_image', 'next_trophy_icon_image', 'leaderboard_frame_image',
            'leaderboard_left_ornament_image', 'leaderboard_right_ornament_image',
            'leaderboard_trophy_icon_image', 'leaderboard_trophy_green_image',
            'leaderboard_trophy_blue_image', 'leaderboard_trophy_purple_image',
            'leaderboard_trophy_gold_image', 'leaderboard_trophy_orange_image',
            'leaderboard_trophy_red_image', 'leaderboard_trophy_silver_image',
            'leaderboard_invite_icon_image',
        ) as $key) {
            $assets[$key] = isset($input[$key]) ? esc_url_raw(trim((string) $input[$key])) : '';
        }

        update_option('rts_dashboard_design_assets', $assets, false);
        wp_safe_redirect(add_query_arg('rts_dashboard_design', 'saved', admin_url('admin.php?page=rts-dashboard-design')));
        exit;
    }

    /** Render the Media Library controls for the Captain's Log artwork. */
    public function render_captains_log_design_page() {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        $assets = get_option('rts_captains_log_design_assets', array());
        $assets = is_array($assets) ? $assets : array();
        $fields = array(
            'background_image' => array(
                'Complete background image',
                'Full-page scene behind the Captain’s Log book. For best results, upload a wide image.'
            ),
            'button_image' => array(
                "Captain's Log button",
                "Complete button artwork used for the Back to Captain’s Suite link at the bottom."
            ),
            'messages_left_art_image' => array(
                'Messages left art',
                'Transparent ornament displayed to the left of the Messages heading.'
            ),
            'messages_right_art_image' => array(
                'Messages right art',
                'Transparent ornament displayed to the right of the Messages heading.'
            ),
            'logo_image' => array(
                'Logo',
                'Logo or crest displayed above the Captain’s Log title.'
            ),
            'message_center_left_art_image' => array(
                'Message Center left art',
                'Transparent ornament displayed to the left of the Message Center text.'
            ),
            'message_center_right_art_image' => array(
                'Message Center right art',
                'Transparent ornament displayed to the right of the Message Center text.'
            ),
            'message_center_below_art_image' => array(
                'Message Center below art',
                'Centered ornament displayed below the Message Center heading.'
            ),
            'column_center_art_image' => array(
                'Column center art',
                'Vertical artwork displayed between the message list and selected message columns.'
            ),
            'message_below_art_image' => array(
                'Message divider art',
                'Decorative horizontal artwork displayed between the message rows in the left column.'
            ),
            'selected_message_art_image' => array(
                'Selected message art',
                'Complete frame/background artwork used behind the selected row in the message list.'
            ),
        );

        wp_enqueue_media();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e("Captain's Log Design", 'run-the-seas'); ?></h1>
            <p><?php esc_html_e('Upload each artwork piece separately. Empty fields use the built-in Captain’s Log styling.', 'run-the-seas'); ?></p>
            <p><strong><?php esc_html_e('Shortcode:', 'run-the-seas'); ?></strong> <code>[rts_captains_log]</code></p>
            <?php if (isset($_GET['rts_captains_log_design']) && 'saved' === sanitize_key(wp_unslash($_GET['rts_captains_log_design']))) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Captain’s Log artwork saved.', 'run-the-seas'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="rts_save_captains_log_design">
                <?php wp_nonce_field('rts_save_captains_log_design'); ?>
                <table class="form-table" role="presentation"><tbody>
                    <?php foreach ($fields as $key => $field) : ?>
                        <tr>
                            <th scope="row"><label for="rts-captains-log-<?php echo esc_attr($key); ?>"><?php echo esc_html($field[0]); ?></label></th>
                            <td>
                                <input id="rts-captains-log-<?php echo esc_attr($key); ?>" class="regular-text" type="url" name="rts_captains_log_design_assets[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($assets[$key] ?? ''); ?>" placeholder="https://">
                                <button type="button" class="button rts-select-captains-log-asset" data-target="rts-captains-log-<?php echo esc_attr($key); ?>"><?php esc_html_e('Select from Media Library', 'run-the-seas'); ?></button>
                                <p class="description"><?php echo esc_html($field[1]); ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th scope="row"><label for="rts-captains-log-header-top-spacing"><?php esc_html_e('Header top spacing (px)', 'run-the-seas'); ?></label></th>
                        <td>
                            <input id="rts-captains-log-header-top-spacing" class="small-text" type="number" min="0" max="160" step="1" name="rts_captains_log_design_assets[header_top_spacing]" value="<?php echo esc_attr(isset($assets['header_top_spacing']) ? absint($assets['header_top_spacing']) : 40); ?>">
                            <p class="description"><?php esc_html_e('Distance from the top of the complete background to the Captain’s Log header. Recommended starting value: 40.', 'run-the-seas'); ?></p>
                        </td>
                    </tr>
                </tbody></table>
                <?php submit_button(__("Save Captain's Log Design", 'run-the-seas')); ?>
            </form>
        </div>
        <script>
        document.addEventListener('click', function (event) {
            var button = event.target.closest('.rts-select-captains-log-asset');
            if (!button || !window.wp || !wp.media) return;
            event.preventDefault();
            var target = document.getElementById(button.getAttribute('data-target'));
            var frame = wp.media({ title: "Select Captain's Log image", library: { type: 'image' }, multiple: false });
            frame.on('select', function () { target.value = frame.state().get('selection').first().toJSON().url; });
            frame.open();
        });
        </script>
        <?php
    }

    /** Save Captain's Log artwork after capability and nonce checks. */
    public function save_captains_log_design() {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        check_admin_referer('rts_save_captains_log_design');
        $input = isset($_POST['rts_captains_log_design_assets']) && is_array($_POST['rts_captains_log_design_assets'])
            ? wp_unslash($_POST['rts_captains_log_design_assets'])
            : array();
        $assets = array();
        foreach (array(
            'background_image', 'button_image', 'messages_left_art_image',
            'messages_right_art_image', 'logo_image', 'message_center_left_art_image',
            'message_center_right_art_image', 'message_center_below_art_image',
            'column_center_art_image', 'message_below_art_image',
            'selected_message_art_image',
        ) as $key) {
            $assets[$key] = isset($input[$key]) ? esc_url_raw(trim((string) $input[$key])) : '';
        }
        $assets['header_top_spacing'] = isset($input['header_top_spacing'])
            ? min(160, absint($input['header_top_spacing']))
            : 40;

        update_option('rts_captains_log_design_assets', $assets, false);
        wp_safe_redirect(add_query_arg('rts_captains_log_design', 'saved', admin_url('admin.php?page=rts-captains-log-design')));
        exit;
    }

    /** Return the ten trophy-case milestones and their saved-key prefixes. */
    private function trophy_case_design_trophies() {
        return array(
            'founding_runner' => __('Founding Runner', 'run-the-seas'),
            '5k'              => __('5K Trophy', 'run-the-seas'),
            '10k'             => __('10K Trophy', 'run-the-seas'),
            '15k'             => __('15K Trophy', 'run-the-seas'),
            '20k'             => __('20K Trophy', 'run-the-seas'),
            '21k'             => __('21.1K Trophy', 'run-the-seas'),
            '25k'             => __('25K Trophy', 'run-the-seas'),
            '30k'             => __('30K Trophy', 'run-the-seas'),
            '35k'             => __('35K Trophy', 'run-the-seas'),
            '42k'             => __('42.2K Trophy', 'run-the-seas'),
        );
    }

    /** Optional standalone icons used by the Marathon 1 reference layout. */
    private function trophy_case_design_icons() {
        return array(
            'lock_icon_image' => __('Fallback trophy lock icon', 'run-the-seas'),
            'title_left_flourish_image' => __('Title left flourish icon', 'run-the-seas'),
            'title_right_flourish_image' => __('Title right flourish icon', 'run-the-seas'),
            'title_left_compass_image' => __('Trophy Case left compass divider', 'run-the-seas'),
            'title_right_compass_image' => __('Trophy Case right compass divider', 'run-the-seas'),
            'title_heading_frame_image' => __('Title heading background / frame', 'run-the-seas'),
            'title_nameplate_frame_image' => __('Member nameplate frame', 'run-the-seas'),
            'how_to_earn_icon_image' => __('Panel-top anchor icon (How to Earn + Race Progress)', 'run-the-seas'),
            'how_to_earn_frame_image' => __('How to earn panel frame', 'run-the-seas'),
            'learn_more_link_icon_image' => __('Learn more external-link icon', 'run-the-seas'),
            'race_progress_icon_image' => __('Race progress / crew icon', 'run-the-seas'),
            'race_progress_frame_image' => __('Race progress panel frame', 'run-the-seas'),
            'view_race_link_icon_image' => __('View the race external-link icon', 'run-the-seas'),
            'marathon_two_lock_icon_image' => __('Marathon 2 locked-panel icon', 'run-the-seas'),
            'marathon_two_compass_icon_image' => __('Marathon 2 panel compass icon', 'run-the-seas'),
            'marathon_two_frame_image' => __('Marathon 2 panel frame', 'run-the-seas'),
            'footer_calendar_icon_image' => __('Journey began calendar icon', 'run-the-seas'),
            'footer_compass_icon_image' => __('Footer compass icon', 'run-the-seas'),
        );
    }

    /** Shared artwork used by the interactive single-trophy page. */
    private function single_trophy_design_fields() {
        return array(
            'single_background_image' => array(__('Single trophy page background', 'run-the-seas'), __('Full-width dark/navy background behind the trophy viewer.', 'run-the-seas')),
            'single_title_left_art_image' => array(__('Title left artwork', 'run-the-seas'), __('Transparent flourish displayed to the left of “Marathon Trophy”.', 'run-the-seas')),
            'single_title_right_art_image' => array(__('Title right artwork', 'run-the-seas'), __('Transparent flourish displayed to the right of “Marathon Trophy”.', 'run-the-seas')),
            'single_subheading_bottom_art_image' => array(__('Subtitle bottom artwork', 'run-the-seas'), __('Wide ornament displayed directly below the milestone and marathon subtitle.', 'run-the-seas')),
            'single_details_frame_image' => array(__('Details panel frame', 'run-the-seas'), __('Transparent frame surrounding the member and achievement details.', 'run-the-seas')),
            'single_details_icon_image' => array(__('Details panel top icon', 'run-the-seas'), __('Transparent anchor or crest displayed at the top of the details panel.', 'run-the-seas')),
            'single_referrals_icon_image' => array(__('Verified referrals icon', 'run-the-seas'), __('Transparent icon shown beside the member’s verified-referral total.', 'run-the-seas')),
            'single_calendar_icon_image' => array(__('Unlocked date icon', 'run-the-seas'), __('Transparent calendar icon shown beside the unlock date.', 'run-the-seas')),
            'single_stage_image' => array(__('Trophy stage / pedestal artwork', 'run-the-seas'), __('Transparent or full-width artwork behind the model and its live nameplate.', 'run-the-seas')),
            'single_trophy_view_frame_image' => array(__('Trophy view frame artwork', 'run-the-seas'), __('Portrait frame placed behind the GLB in each right-side front, right, back, and left camera view.', 'run-the-seas')),
            'single_previous_button_image' => array(__('Rotate left button artwork', 'run-the-seas'), __('Complete artwork for the button that rotates the current GLB to the left.', 'run-the-seas')),
            'single_next_button_image' => array(__('Rotate right button artwork', 'run-the-seas'), __('Complete artwork for the button that rotates the current GLB to the right.', 'run-the-seas')),
            'single_close_button_image' => array(__('Close button artwork', 'run-the-seas'), __('Optional transparent artwork for the top-right close button.', 'run-the-seas')),
            'single_return_button_image' => array(__('Return button artwork', 'run-the-seas'), __('Optional complete background/frame for the Return to Trophy Case button.', 'run-the-seas')),
            'single_share_button_image' => array(__('Share button artwork', 'run-the-seas'), __('Optional complete background/frame for the Share Trophy button.', 'run-the-seas')),
        );
    }

    /** Render paired locked/unlocked Media Library controls for the trophy case. */
    public function render_trophy_case_design_page() {
        $this->render_trophy_case_design_editor(false);
    }

    /** Render a separate asset editor for the first marathon trophy case. */
    public function render_marathon_one_trophy_case_design_page() {
        $this->render_trophy_case_design_editor(true);
    }

    private function render_trophy_case_design_editor($is_marathon_one) {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        $option_name = $is_marathon_one ? 'rts_marathon_one_trophy_case_design_assets' : 'rts_trophy_case_design_assets';
        $field_name = $option_name;
        $action = $is_marathon_one ? 'rts_save_marathon_one_trophy_case_design' : 'rts_save_trophy_case_design';
        $notice_key = $is_marathon_one ? 'rts_marathon_one_trophy_case_design' : 'rts_trophy_case_design';
        $assets = get_option($option_name, array());
        $assets = is_array($assets) ? $assets : array();
        $trophies = $this->trophy_case_design_trophies();
        $icons = $is_marathon_one
            ? $this->trophy_case_design_icons()
            : array('lock_icon_image' => __('Fallback trophy lock icon', 'run-the-seas'));
        wp_enqueue_media();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(sprintf(__('Marathon %d Trophy Case Design', 'run-the-seas'), $is_marathon_one ? 1 : 2)); ?></h1>
            <p><?php echo esc_html($is_marathon_one
                ? __('Upload the empty cabinet frame and its icons. All headings, trophy labels, dates, statistics, panel copy, and footer wording are rendered automatically as live text.', 'run-the-seas')
                : __('Upload two complete images for every podium: an unlocked trophy without glass or lock, and a locked trophy with its glass cover and lock already included.', 'run-the-seas')); ?></p>
            <?php if (!$is_marathon_one) : ?>
                <p><?php esc_html_e('Milestone captions are live HTML text by default. “The Journey Begins” and “At Sea” use a script-style font automatically; caption artwork is only needed when you want exact custom lettering.', 'run-the-seas'); ?></p>
            <?php endif; ?>
            <p><strong><?php esc_html_e('Shortcode:', 'run-the-seas'); ?></strong> <code><?php echo $is_marathon_one ? '[rts_marathon_one_trophy_case]' : '[rts_trophy_case]'; ?></code></p>
            <?php if (isset($_GET[$notice_key]) && 'saved' === sanitize_key(wp_unslash($_GET[$notice_key]))) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Trophy case artwork saved.', 'run-the-seas'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
                <?php wp_nonce_field($action); ?>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><label for="rts-trophy-case-background-image"><?php esc_html_e('Trophy cabinet background', 'run-the-seas'); ?></label></th>
                        <td>
                            <input id="rts-trophy-case-background-image" class="regular-text" type="url" name="<?php echo esc_attr($field_name); ?>[background_image]" value="<?php echo esc_attr($assets['background_image'] ?? ''); ?>" placeholder="https://">
                            <button type="button" class="button rts-select-trophy-case-asset" data-target="rts-trophy-case-background-image"><?php esc_html_e('Select image', 'run-the-seas'); ?></button>
                            <p class="description"><?php esc_html_e('Upload the empty 3:2 cabinet containing its borders, shelves, lighting, and podium areas.', 'run-the-seas'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rts-trophy-case-responsive-background-image"><?php esc_html_e('Mobile and tablet cabinet background', 'run-the-seas'); ?></label></th>
                        <td>
                            <input id="rts-trophy-case-responsive-background-image" class="regular-text" type="url" name="<?php echo esc_attr($field_name); ?>[responsive_background_image]" value="<?php echo esc_attr($assets['responsive_background_image'] ?? ''); ?>" placeholder="<?php esc_attr_e('Bundled responsive background', 'run-the-seas'); ?>">
                            <button type="button" class="button rts-select-trophy-case-asset" data-target="rts-trophy-case-responsive-background-image"><?php esc_html_e('Select image', 'run-the-seas'); ?></button>
                            <p class="description"><?php echo esc_html($is_marathon_one
                                ? __('Optional replacement for the bundled 375 × 1689 responsive cabinet. The layout displays two trophies per shelf row.', 'run-the-seas')
                                : __('Optional replacement for the bundled 375 × 2197 responsive cabinet. Regular trophies use paired rows, while the three feature milestones display one trophy beside its caption.', 'run-the-seas')); ?></p>
                        </td>
                    </tr>
                    <?php if (!$is_marathon_one) : ?>
                    <tr>
                        <th scope="row"><label for="rts-trophy-case-title-image"><?php esc_html_e('Top title and nameplate image', 'run-the-seas'); ?></label></th>
                        <td>
                            <input id="rts-trophy-case-title-image" class="regular-text" type="url" name="<?php echo esc_attr($field_name); ?>[title_image]" value="<?php echo esc_attr($assets['title_image'] ?? ''); ?>" placeholder="https://">
                            <button type="button" class="button rts-select-trophy-case-asset" data-target="rts-trophy-case-title-image"><?php esc_html_e('Select image', 'run-the-seas'); ?></button>
                            <p class="description"><?php esc_html_e('Upload the transparent Trophy Case heading artwork with an empty member-name plate. The current member name is placed inside it.', 'run-the-seas'); ?></p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><label for="rts-trophy-case-title-icon-image"><?php echo esc_html($is_marathon_one ? __('Icon between “TH” and “E”', 'run-the-seas') : __('Top center icon image', 'run-the-seas')); ?></label></th>
                        <td>
                            <input id="rts-trophy-case-title-icon-image" class="regular-text" type="url" name="<?php echo esc_attr($field_name); ?>[title_icon_image]" value="<?php echo esc_attr($assets['title_icon_image'] ?? ''); ?>" placeholder="https://">
                            <button type="button" class="button rts-select-trophy-case-asset" data-target="rts-trophy-case-title-icon-image"><?php esc_html_e('Select image', 'run-the-seas'); ?></button>
                            <p class="description"><?php echo esc_html($is_marathon_one
                                ? __('Transparent anchor or decorative icon inserted directly between TH and E in the top THE word.', 'run-the-seas')
                                : __('Optional transparent icon centered above the Trophy Case title, such as the gold anchor shown in the reference.', 'run-the-seas')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rts-trophy-case-footer-frame-image"><?php esc_html_e('Bottom content frame image', 'run-the-seas'); ?></label></th>
                        <td>
                            <input id="rts-trophy-case-footer-frame-image" class="regular-text" type="url" name="<?php echo esc_attr($field_name); ?>[footer_frame_image]" value="<?php echo esc_attr($assets['footer_frame_image'] ?? ''); ?>" placeholder="https://">
                            <button type="button" class="button rts-select-trophy-case-asset" data-target="rts-trophy-case-footer-frame-image"><?php esc_html_e('Select image', 'run-the-seas'); ?></button>
                            <p class="description"><?php esc_html_e('Upload a transparent empty frame. The voyage and legacy footer text is centered inside it.', 'run-the-seas'); ?></p>
                        </td>
                    </tr>
                    <?php if (!$is_marathon_one) : ?>
                    <tr>
                        <th scope="row"><label for="rts-trophy-case-founding-caption-image"><?php esc_html_e('Signed Up caption image', 'run-the-seas'); ?></label></th>
                        <td>
                            <input id="rts-trophy-case-founding-caption-image" class="regular-text" type="url" name="<?php echo esc_attr($field_name); ?>[founding_caption_image]" value="<?php echo esc_attr($assets['founding_caption_image'] ?? ''); ?>" placeholder="https://">
                            <button type="button" class="button rts-select-trophy-case-asset" data-target="rts-trophy-case-founding-caption-image"><?php esc_html_e('Select image', 'run-the-seas'); ?></button>
                            <p class="description"><?php esc_html_e('Optional transparent artwork containing Signed Up and The Journey Begins. The current member name and its left/right ornaments are still rendered live below it.', 'run-the-seas'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rts-trophy-case-half-caption-image"><?php esc_html_e('Half Marathon caption image', 'run-the-seas'); ?></label></th>
                        <td>
                            <input id="rts-trophy-case-half-caption-image" class="regular-text" type="url" name="<?php echo esc_attr($field_name); ?>[half_caption_image]" value="<?php echo esc_attr($assets['half_caption_image'] ?? ''); ?>" placeholder="https://">
                            <button type="button" class="button rts-select-trophy-case-asset" data-target="rts-trophy-case-half-caption-image"><?php esc_html_e('Select image', 'run-the-seas'); ?></button>
                            <p class="description"><?php esc_html_e('Optional transparent artwork containing the complete Half Marathon caption. Leave empty to use editable HTML text and the separate arrow settings below.', 'run-the-seas'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rts-trophy-case-marathon-caption-image"><?php esc_html_e('Marathon caption image', 'run-the-seas'); ?></label></th>
                        <td>
                            <input id="rts-trophy-case-marathon-caption-image" class="regular-text" type="url" name="<?php echo esc_attr($field_name); ?>[marathon_caption_image]" value="<?php echo esc_attr($assets['marathon_caption_image'] ?? ''); ?>" placeholder="https://">
                            <button type="button" class="button rts-select-trophy-case-asset" data-target="rts-trophy-case-marathon-caption-image"><?php esc_html_e('Select image', 'run-the-seas'); ?></button>
                            <p class="description"><?php esc_html_e('Optional transparent artwork containing the complete Marathon caption. Leave empty to use editable HTML text and the separate arrow settings below.', 'run-the-seas'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rts-trophy-case-milestone-left-ornament-image"><?php esc_html_e('Milestone left arrow / ornament', 'run-the-seas'); ?></label></th>
                        <td>
                            <input id="rts-trophy-case-milestone-left-ornament-image" class="regular-text" type="url" name="<?php echo esc_attr($field_name); ?>[milestone_left_ornament_image]" value="<?php echo esc_attr($assets['milestone_left_ornament_image'] ?? ''); ?>" placeholder="https://">
                            <button type="button" class="button rts-select-trophy-case-asset" data-target="rts-trophy-case-milestone-left-ornament-image"><?php esc_html_e('Select image', 'run-the-seas'); ?></button>
                            <p class="description"><?php esc_html_e('Optional transparent left-facing arrow or flourish shown beside the member name, 21K, and 42K. Leave empty to use the built-in gold arrow.', 'run-the-seas'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rts-trophy-case-milestone-right-ornament-image"><?php esc_html_e('Milestone right arrow / ornament', 'run-the-seas'); ?></label></th>
                        <td>
                            <input id="rts-trophy-case-milestone-right-ornament-image" class="regular-text" type="url" name="<?php echo esc_attr($field_name); ?>[milestone_right_ornament_image]" value="<?php echo esc_attr($assets['milestone_right_ornament_image'] ?? ''); ?>" placeholder="https://">
                            <button type="button" class="button rts-select-trophy-case-asset" data-target="rts-trophy-case-milestone-right-ornament-image"><?php esc_html_e('Select image', 'run-the-seas'); ?></button>
                            <p class="description"><?php esc_html_e('Optional transparent right-facing arrow or flourish. Leave empty to use the built-in gold arrow.', 'run-the-seas'); ?></p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($icons as $icon_key => $icon_label) : ?>
                    <tr>
                        <th scope="row"><label for="rts-trophy-case-<?php echo esc_attr($icon_key); ?>"><?php echo esc_html($icon_label); ?></label></th>
                        <td>
                            <input id="rts-trophy-case-<?php echo esc_attr($icon_key); ?>" class="regular-text" type="url" name="<?php echo esc_attr($field_name); ?>[<?php echo esc_attr($icon_key); ?>]" value="<?php echo esc_attr($assets[$icon_key] ?? ''); ?>" placeholder="https://">
                            <button type="button" class="button rts-select-trophy-case-asset" data-target="rts-trophy-case-<?php echo esc_attr($icon_key); ?>"><?php esc_html_e('Select image', 'run-the-seas'); ?></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody></table>
                <h2 class="title" style="margin-top:32px;"><?php esc_html_e('Interactive Single Trophy Page', 'run-the-seas'); ?></h2>
                <p><?php esc_html_e('These shared assets style the existing [rts_single_trophy] page. Empty fields use the built-in navy and gold design.', 'run-the-seas'); ?></p>
                <table class="form-table" role="presentation"><tbody>
                    <?php foreach ($this->single_trophy_design_fields() as $single_key => $single_field) :
                        $single_input_id = 'rts-trophy-case-' . sanitize_html_class($single_key);
                    ?>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($single_input_id); ?>"><?php echo esc_html($single_field[0]); ?></label></th>
                        <td>
                            <input id="<?php echo esc_attr($single_input_id); ?>" class="regular-text" type="url" name="<?php echo esc_attr($field_name); ?>[<?php echo esc_attr($single_key); ?>]" value="<?php echo esc_attr($assets[$single_key] ?? ''); ?>" placeholder="https://">
                            <button type="button" class="button rts-select-trophy-case-asset" data-target="<?php echo esc_attr($single_input_id); ?>" data-kind="image"><?php esc_html_e('Select image', 'run-the-seas'); ?></button>
                            <p class="description"><?php echo esc_html($single_field[1]); ?></p>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th scope="row"><label for="rts-trophy-case-single-rotation-step"><?php esc_html_e('Arrow rotation step', 'run-the-seas'); ?></label></th>
                        <td>
                            <input id="rts-trophy-case-single-rotation-step" class="small-text" type="number" min="15" max="90" step="5" name="<?php echo esc_attr($field_name); ?>[single_rotation_step]" value="<?php echo esc_attr(isset($assets['single_rotation_step']) ? absint($assets['single_rotation_step']) : 45); ?>"> °
                            <p class="description"><?php esc_html_e('Each left/right arrow click rotates the GLB horizontally by this angle. The right-side camera presets remain Front, Right, Back, and Left.', 'run-the-seas'); ?></p>
                        </td>
                    </tr>
                </tbody></table>
                <table class="widefat striped" role="presentation">
                    <thead><tr><th><?php esc_html_e('Trophy', 'run-the-seas'); ?></th><th><?php esc_html_e('Unlocked artwork / GLB poster', 'run-the-seas'); ?></th><th><?php esc_html_e('Locked + glass artwork', 'run-the-seas'); ?></th><th><?php esc_html_e('Unlocked GLB model', 'run-the-seas'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($trophies as $prefix => $label) :
                        $unlocked_key = $prefix . '_unlocked_image';
                        $locked_key = $prefix . '_locked_image';
                        $glb_key = $prefix . '_unlocked_glb';
                        ?>
                        <tr>
                            <th scope="row" style="width:180px"><?php echo esc_html($label); ?></th>
                            <td>
                                <input id="rts-trophy-case-<?php echo esc_attr($unlocked_key); ?>" class="regular-text" type="url" name="<?php echo esc_attr($field_name); ?>[<?php echo esc_attr($unlocked_key); ?>]" value="<?php echo esc_attr($assets[$unlocked_key] ?? ''); ?>" placeholder="https://">
                                <button type="button" class="button rts-select-trophy-case-asset" data-target="rts-trophy-case-<?php echo esc_attr($unlocked_key); ?>"><?php esc_html_e('Select image', 'run-the-seas'); ?></button>
                            </td>
                            <td>
                                <input id="rts-trophy-case-<?php echo esc_attr($locked_key); ?>" class="regular-text" type="url" name="<?php echo esc_attr($field_name); ?>[<?php echo esc_attr($locked_key); ?>]" value="<?php echo esc_attr($assets[$locked_key] ?? ''); ?>" placeholder="https://">
                                <button type="button" class="button rts-select-trophy-case-asset" data-target="rts-trophy-case-<?php echo esc_attr($locked_key); ?>"><?php esc_html_e('Select image', 'run-the-seas'); ?></button>
                            </td>
                            <td>
                                <input id="rts-trophy-case-<?php echo esc_attr($glb_key); ?>" class="regular-text" type="url" name="<?php echo esc_attr($field_name); ?>[<?php echo esc_attr($glb_key); ?>]" value="<?php echo esc_attr($assets[$glb_key] ?? ''); ?>" placeholder="https://…/trophy.glb">
                                <button type="button" class="button rts-select-trophy-case-asset" data-target="rts-trophy-case-<?php echo esc_attr($glb_key); ?>" data-kind="glb"><?php esc_html_e('Select GLB', 'run-the-seas'); ?></button>
                                <p class="description"><?php esc_html_e('Displayed only after this trophy has been unlocked.', 'run-the-seas'); ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button(sprintf(__('Save Marathon %d Trophy Design', 'run-the-seas'), $is_marathon_one ? 1 : 2)); ?>
            </form>
        </div>
        <script>
        document.addEventListener('click', function (event) {
            var button = event.target.closest('.rts-select-trophy-case-asset');
            if (!button || !window.wp || !wp.media) return;
            event.preventDefault();
            var target = document.getElementById(button.getAttribute('data-target'));
            var kind = button.getAttribute('data-kind') || 'image';
            var options = { title: kind === 'glb' ? 'Select GLB trophy model' : 'Select trophy artwork', multiple: false };
            if (kind !== 'glb') options.library = { type: 'image' };
            var frame = wp.media(options);
            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                if (kind === 'glb' && !/\.glb(?:\?.*)?$/i.test(attachment.url || '')) {
                    window.alert('Please select a .glb model.');
                    return;
                }
                target.value = attachment.url;
            });
            frame.open();
        });
        </script>
        <?php
    }

    /** Save the trophy-case artwork URLs after capability and nonce checks. */
    public function save_trophy_case_design() {
        $this->save_trophy_case_design_assets(false);
    }

    public function save_marathon_one_trophy_case_design() {
        $this->save_trophy_case_design_assets(true);
    }

    private function save_trophy_case_design_assets($is_marathon_one) {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        $action = $is_marathon_one ? 'rts_save_marathon_one_trophy_case_design' : 'rts_save_trophy_case_design';
        $option_name = $is_marathon_one ? 'rts_marathon_one_trophy_case_design_assets' : 'rts_trophy_case_design_assets';
        $notice_key = $is_marathon_one ? 'rts_marathon_one_trophy_case_design' : 'rts_trophy_case_design';
        $page_slug = $is_marathon_one ? 'rts-marathon-one-trophy-design' : 'rts-trophy-case-design';
        check_admin_referer($action);
        $input = isset($_POST[$option_name]) && is_array($_POST[$option_name])
            ? wp_unslash($_POST[$option_name])
            : array();
        $assets = array(
            'background_image' => isset($input['background_image']) ? esc_url_raw(trim((string) $input['background_image'])) : '',
            'responsive_background_image' => isset($input['responsive_background_image']) ? esc_url_raw(trim((string) $input['responsive_background_image'])) : '',
            'title_image' => isset($input['title_image']) ? esc_url_raw(trim((string) $input['title_image'])) : '',
            'title_icon_image' => isset($input['title_icon_image']) ? esc_url_raw(trim((string) $input['title_icon_image'])) : '',
            'footer_frame_image' => isset($input['footer_frame_image']) ? esc_url_raw(trim((string) $input['footer_frame_image'])) : '',
            'founding_caption_image' => isset($input['founding_caption_image']) ? esc_url_raw(trim((string) $input['founding_caption_image'])) : '',
            'half_caption_image' => isset($input['half_caption_image']) ? esc_url_raw(trim((string) $input['half_caption_image'])) : '',
            'marathon_caption_image' => isset($input['marathon_caption_image']) ? esc_url_raw(trim((string) $input['marathon_caption_image'])) : '',
            'milestone_left_ornament_image' => isset($input['milestone_left_ornament_image']) ? esc_url_raw(trim((string) $input['milestone_left_ornament_image'])) : '',
            'milestone_right_ornament_image' => isset($input['milestone_right_ornament_image']) ? esc_url_raw(trim((string) $input['milestone_right_ornament_image'])) : '',
        );
        foreach (array_keys($this->trophy_case_design_trophies()) as $prefix) {
            foreach (array('unlocked_image', 'locked_image', 'unlocked_glb') as $state) {
                $key = $prefix . '_' . $state;
                $assets[$key] = isset($input[$key]) ? esc_url_raw(trim((string) $input[$key])) : '';
            }
        }

        foreach (array_keys($this->single_trophy_design_fields()) as $key) {
            $assets[$key] = isset($input[$key]) ? esc_url_raw(trim((string) $input[$key])) : '';
        }
        $assets['single_rotation_step'] = isset($input['single_rotation_step'])
            ? min(90, max(15, absint($input['single_rotation_step'])))
            : 45;

        $icon_keys = $is_marathon_one
            ? array_keys($this->trophy_case_design_icons())
            : array('lock_icon_image');
        foreach ($icon_keys as $key) {
            $assets[$key] = isset($input[$key]) ? esc_url_raw(trim((string) $input[$key])) : '';
        }

        update_option($option_name, $assets, false);
        wp_safe_redirect(add_query_arg($notice_key, 'saved', admin_url('admin.php?page=' . $page_slug)));
        exit;
    }

    /** Uploadable artwork exposed by the Marathon Challenge design screen. */
    private function marathon_challenge_design_fields() {
        return array(
            __('Map and Frames', 'run-the-seas') => array(
                'map_image' => array(__('Challenge map image', 'run-the-seas'), __('Wide 1.37:1 clean island artwork. Do not bake names, avatars, pins, or panels into this image.', 'run-the-seas'), 'wide', RTS_PLUGIN_URL . 'assets/images/marathon-challenge-island.png'),
                'header_frame_image' => array(__('Challenge title frame', 'run-the-seas'), __('Transparent frame layered around the “42.2K Referral Marathon Challenge” title bar.', 'run-the-seas'), 'frame'),
                'guest_avatar_frame_image' => array(__('Logged-out “You Are Here” avatar frame', 'run-the-seas'), __('Transparent circular outer frame layered around the anonymous visitor avatar. A square PNG or WebP with transparency works best.', 'run-the-seas'), 'icon'),
                'current_user_avatar_frame_image' => array(__('Current logged-in user avatar frame', 'run-the-seas'), __('Transparent circular outer frame around the current user avatar at 0K and during the marathon. When empty, the logged-out avatar frame is used as its fallback.', 'run-the-seas'), 'icon'),
                'user_position_marker_image' => array(__('Map user-position pin', 'run-the-seas'), __('Blank transparent position-pin artwork displayed above map participants. The live distance, such as 0K, 5K, or 15K, is placed inside it automatically.', 'run-the-seas'), 'icon'),
                'user_position_marker_selected_image' => array(__('Map user-position selected pin', 'run-the-seas'), __('Replacement position-pin artwork shown while a participant is hovered, focused, or their user list is open.', 'run-the-seas'), 'icon'),
                'top_four_frame_image' => array(__('Top 4 panel frame', 'run-the-seas'), __('Transparent full-panel frame for the Top 4 section.', 'run-the-seas'), 'frame'),
                'around_you_frame_image' => array(__('Around You panel frame', 'run-the-seas'), __('Transparent full-panel frame for the Around You section.', 'run-the-seas'), 'frame'),
                'finishers_frame_image' => array(__('Marathon Finishers heading banner', 'run-the-seas'), __('Complete gold heading artwork displayed above the four finishers. This image is not used as a frame around the finisher section.', 'run-the-seas'), 'frame'),
                'milestones_frame_image' => array(__('Milestone Groups frame', 'run-the-seas'), __('Transparent full-panel frame for the Milestone Groups section.', 'run-the-seas'), 'frame'),
                'over_target_frame_image' => array(__('Over 42.2K frame', 'run-the-seas'), __('Transparent full-panel frame for captains beyond one marathon.', 'run-the-seas'), 'frame'),
            ),
            __('Section Icons', 'run-the-seas') => array(
                'top_four_icon_image' => array(__('Top 4 heading divider', 'run-the-seas'), __('Transparent decorative artwork displayed below the Top 4 heading, never to its left.', 'run-the-seas'), 'frame'),
                'around_you_icon_image' => array(__('Around You heading divider', 'run-the-seas'), __('Transparent decorative artwork displayed below the Around You heading, never to its left.', 'run-the-seas'), 'frame'),
                'milestones_icon_image' => array(__('Milestone Groups heading divider', 'run-the-seas'), __('Transparent decorative artwork displayed below the Milestone Groups heading.', 'run-the-seas'), 'frame'),
                'over_target_icon_image' => array(__('Over 42.2K heading divider', 'run-the-seas'), __('Transparent decorative artwork displayed below the Over 42.2K heading.', 'run-the-seas'), 'frame'),
                'footer_icon_image' => array(__('Footer icon', 'run-the-seas'), __('Large transparent icon above “Every referral moves us forward”.', 'run-the-seas'), 'icon'),
                'panel_heading_divider_image' => array(__('Panel heading divider', 'run-the-seas'), __('Transparent decorative divider displayed below the Top 4, Around You, Milestone Groups, and Over 42.2K headings.', 'run-the-seas'), 'frame'),
                'list_open_icon_image' => array(__('Open-list icon', 'run-the-seas'), __('Icon displayed at the right of a closed milestone row.', 'run-the-seas'), 'icon'),
                'list_close_icon_image' => array(__('Close-list icon', 'run-the-seas'), __('Icon displayed while a milestone user list is hovered or open.', 'run-the-seas'), 'icon'),
                'user_list_header_right_icon_image' => array(__('Map user-list header right icon', 'run-the-seas'), __('Arrow artwork displayed at the right of compact map-point user-list headings.', 'run-the-seas'), 'icon'),
                'milestone_group_header_right_icon_image' => array(__('Milestone-group header right icon', 'run-the-seas'), __('Anchor or decorative artwork displayed at the right of the large milestone-group card heading.', 'run-the-seas'), 'icon'),
                'around_you_up_arrow_image' => array(__('Around You up-arrow icon', 'run-the-seas'), __('Icon for participants positioned ahead of the current user.', 'run-the-seas'), 'icon'),
                'around_you_down_arrow_image' => array(__('Around You down-arrow icon', 'run-the-seas'), __('Icon for participants positioned behind the current user.', 'run-the-seas'), 'icon'),
                'around_you_right_arrow_image' => array(__('Around You right-arrow icon', 'run-the-seas'), __('Icon for the current user and participants at an equal position.', 'run-the-seas'), 'icon'),
            ),
            __('Marathon 2 User Icons', 'run-the-seas') => array(
                'marathon2_position_marker_image' => array(__('Marathon 2 milestone pin', 'run-the-seas'), __('Normal milestone-pin artwork shown above a Marathon 2 user. On hover it changes to the standard Marathon 1 selected pin.', 'run-the-seas'), 'icon'),
                'marathon2_position_marker_selected_image' => array(__('Marathon 2 avatar badge', 'run-the-seas'), __('Persistent M2 badge displayed at the right side of a Marathon 2 user avatar.', 'run-the-seas'), 'icon'),
                'marathon2_badge_image' => array(__('Marathon 2 avatar frame', 'run-the-seas'), __('Transparent outer frame placed around a Marathon 2 user avatar.', 'run-the-seas'), 'frame'),
            ),
            __('Interactive Frames', 'run-the-seas') => array(
                'around_you_current_frame_image' => array(__('Around You current-user frame', 'run-the-seas'), __('Transparent frame layered around the highlighted current-user row in Around You.', 'run-the-seas'), 'frame'),
                'milestone_active_frame_image' => array(__('Active milestone-row frame', 'run-the-seas'), __('Transparent frame shown around a milestone row while its user list is hovered or open.', 'run-the-seas'), 'frame'),
                'user_list_popup_frame_image' => array(__('User-list popup frame', 'run-the-seas'), __('Transparent outer frame used for map-point and milestone user-list popups.', 'run-the-seas'), 'frame'),
                'finisher_avatar_frame_image' => array(__('Finisher avatar frame', 'run-the-seas'), __('Transparent circular frame layered around each of the four Marathon Finisher avatars.', 'run-the-seas'), 'icon'),
                'finisher_rank_icon_image' => array(__('Finisher rank icon', 'run-the-seas'), __('Blank transparent medal or circle used behind the dynamic finisher rank numbers 1–4.', 'run-the-seas'), 'icon'),
            ),
            __('Milestone Trophy Images', 'run-the-seas') => array(
                'trophy_5k_image' => array(__('5K trophy', 'run-the-seas'), __('Artwork for the 5K Milestone Group.', 'run-the-seas'), 'trophy'),
                'trophy_10k_image' => array(__('10K trophy', 'run-the-seas'), __('Artwork for the 10K Milestone Group.', 'run-the-seas'), 'trophy'),
                'trophy_15k_image' => array(__('15K trophy', 'run-the-seas'), __('Artwork for the 15K Milestone Group.', 'run-the-seas'), 'trophy'),
                'trophy_20k_image' => array(__('20K trophy', 'run-the-seas'), __('Artwork for the 20K Milestone Group.', 'run-the-seas'), 'trophy'),
                'trophy_21k_image' => array(__('21.1K trophy', 'run-the-seas'), __('Artwork for the Half Marathon Milestone Group.', 'run-the-seas'), 'trophy'),
                'trophy_25k_image' => array(__('25K trophy', 'run-the-seas'), __('Artwork for the 25K Milestone Group.', 'run-the-seas'), 'trophy'),
                'trophy_30k_image' => array(__('30K trophy', 'run-the-seas'), __('Artwork for the 30K Milestone Group.', 'run-the-seas'), 'trophy'),
                'trophy_35k_image' => array(__('35K trophy', 'run-the-seas'), __('Artwork for the 35K Milestone Group.', 'run-the-seas'), 'trophy'),
                'trophy_42k_image' => array(__('42.2K trophy', 'run-the-seas'), __('Artwork for the Marathon Milestone Group.', 'run-the-seas'), 'trophy'),
                'trophy_over_image' => array(__('Over 42.2K trophy', 'run-the-seas'), __('Artwork displayed for captains who have started another marathon.', 'run-the-seas'), 'trophy'),
            ),
        );
    }

    /** Render Media Library controls for every Marathon Challenge visual asset. */
    public function render_marathon_challenge_design_page() {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        $assets = get_option('rts_marathon_challenge_design_assets', array());
        $assets = is_array($assets) ? $assets : array();
        $sections = $this->marathon_challenge_design_fields();
        wp_enqueue_media();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Marathon Challenge Design', 'run-the-seas'); ?></h1>
            <p><?php esc_html_e('Upload the map, transparent frames, section icons, footer icon, and each milestone trophy. Empty fields keep the built-in CSS or existing trophy fallback.', 'run-the-seas'); ?></p>
            <p><a class="button button-secondary" href="<?php echo esc_url(add_query_arg(array('rts_marathon_demo' => '1', 'rts_marathon_demo_state' => 'public'), home_url('/marathon-challenge/'))); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open Temporary Demo Data Preview', 'run-the-seas'); ?></a> <span class="description"><?php esc_html_e('Opens the public reference state with in-memory test users; no database records are created.', 'run-the-seas'); ?></span></p>
            <?php if (isset($_GET['rts_marathon_challenge_design']) && 'saved' === sanitize_key(wp_unslash($_GET['rts_marathon_challenge_design']))) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Marathon Challenge design assets saved.', 'run-the-seas'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="rts_save_marathon_challenge_design">
                <?php wp_nonce_field('rts_save_marathon_challenge_design'); ?>
                <?php foreach ($sections as $section_title => $fields) : ?>
                    <h2 class="title" style="margin-top:28px;"><?php echo esc_html($section_title); ?></h2>
                    <table class="form-table" role="presentation"><tbody>
                        <?php foreach ($fields as $key => $field) : ?>
                            <?php
                            $value = esc_url($assets[$key] ?? '');
                            $fallback = isset($field[3]) ? esc_url($field[3]) : '';
                            $preview = $value ?: $fallback;
                            $preview_id = 'rts-marathon-preview-' . sanitize_html_class($key);
                            $input_id = 'rts-marathon-' . sanitize_html_class($key);
                            $max_width = 'wide' === $field[2] ? '760px' : ('frame' === $field[2] ? '420px' : '110px');
                            $max_height = 'wide' === $field[2] ? 'none' : ('frame' === $field[2] ? '220px' : '110px');
                            ?>
                            <tr>
                                <th scope="row"><label for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($field[0]); ?></label></th>
                                <td>
                                    <input id="<?php echo esc_attr($input_id); ?>" class="regular-text" type="url" name="rts_marathon_challenge_design_assets[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>" placeholder="https://">
                                    <button type="button" class="button rts-select-marathon-asset" data-target="<?php echo esc_attr($input_id); ?>" data-preview="<?php echo esc_attr($preview_id); ?>"><?php esc_html_e('Select from Media Library', 'run-the-seas'); ?></button>
                                    <button type="button" class="button rts-clear-marathon-asset" data-target="<?php echo esc_attr($input_id); ?>" data-preview="<?php echo esc_attr($preview_id); ?>" data-fallback="<?php echo esc_attr($fallback); ?>"<?php echo $value ? '' : ' disabled'; ?>><?php echo esc_html($fallback ? __('Use Built-in Fallback', 'run-the-seas') : __('Clear', 'run-the-seas')); ?></button>
                                    <p class="description"><?php echo esc_html($field[1]); ?></p>
                                    <img id="<?php echo esc_attr($preview_id); ?>" src="<?php echo esc_url($preview); ?>" alt="" style="<?php echo $preview ? 'display:block;' : 'display:none;'; ?>max-width:<?php echo esc_attr($max_width); ?>;max-height:<?php echo esc_attr($max_height); ?>;width:auto;height:auto;margin-top:10px;padding:6px;background:#001422;border:1px solid #c3c4c7;border-radius:4px;">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table>
                <?php endforeach; ?>
                <?php submit_button(__('Save Marathon Challenge Design', 'run-the-seas')); ?>
            </form>
        </div>
        <script>
        document.addEventListener('click', function (event) {
            var selectButton = event.target.closest('.rts-select-marathon-asset');
            var clearButton = event.target.closest('.rts-clear-marathon-asset');
            if (selectButton && window.wp && wp.media) {
                event.preventDefault();
                var target = document.getElementById(selectButton.getAttribute('data-target'));
                var preview = document.getElementById(selectButton.getAttribute('data-preview'));
                var frame = wp.media({ title: 'Select Marathon Challenge artwork', library: { type: 'image' }, multiple: false });
                frame.on('select', function () {
                    var image = frame.state().get('selection').first().toJSON();
                    target.value = image.url;
                    preview.src = image.url;
                    preview.style.display = 'block';
                    target.parentNode.querySelector('.rts-clear-marathon-asset').disabled = false;
                });
                frame.open();
            }
            if (clearButton) {
                event.preventDefault();
                var clearTarget = document.getElementById(clearButton.getAttribute('data-target'));
                var clearPreview = document.getElementById(clearButton.getAttribute('data-preview'));
                var fallback = clearButton.getAttribute('data-fallback') || '';
                clearTarget.value = '';
                clearPreview.src = fallback;
                clearPreview.style.display = fallback ? 'block' : 'none';
                clearButton.disabled = true;
            }
        });
        </script>
        <?php
    }

    /** Save all optional Marathon Challenge artwork selected in the Media Library. */
    public function save_marathon_challenge_design() {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        check_admin_referer('rts_save_marathon_challenge_design');
        $input = isset($_POST['rts_marathon_challenge_design_assets']) && is_array($_POST['rts_marathon_challenge_design_assets'])
            ? wp_unslash($_POST['rts_marathon_challenge_design_assets'])
            : array();
        $assets = array();
        foreach ($this->marathon_challenge_design_fields() as $fields) {
            foreach (array_keys($fields) as $key) {
                $assets[$key] = esc_url_raw(trim((string) ($input[$key] ?? '')));
            }
        }
        update_option('rts_marathon_challenge_design_assets', $assets, false);

        wp_safe_redirect(add_query_arg(
            'rts_marathon_challenge_design',
            'saved',
            admin_url('admin.php?page=rts-marathon-challenge-design')
        ));
        exit;
    }

    /** Render Media Library and colour/width controls for the View Journey page. */
    public function render_journey_design_page() {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        $assets = get_option('rts_journey_design_assets', array());
        $assets = is_array($assets) ? $assets : array();
        $fields = array(
            'logo_image' => array('Left header logo', 'Transparent Run The Seas logo displayed at the left of the journey title.'),
            'print_icon_image' => array('Print icon', 'Small transparent icon inside the Print Report button.'),
            'email_icon_image' => array('Email icon', 'Small transparent icon inside the Email Report button.'),
            'hero_image' => array('Journey scene image', 'Wide ocean or voyage artwork displayed in the main framed scene.'),
            'founding_runner_icon' => array('Founding runner icon', 'Icon which display the founding runner.'),
            'frame_image' => array('Journey report frame', 'Transparent outer-frame artwork layered around the complete Journey report. Upload the complete frame as one image.'),
            'progress_start_icon_image' => array('Progress start icon', 'Icon displayed immediately before the progress bar.'),
            'footer_icon_image' => array('Footer icon', 'Icon displayed before the automatic-update footer message.'),
        );
        $gold = sanitize_hex_color($assets['gold_color'] ?? '') ?: '#d99214';
        $width = max(700, min(1600, absint($assets['page_width'] ?? 1180)));
        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('View Journey Design', 'run-the-seas'); ?></h1>
            <p><?php esc_html_e('Upload the artwork used by the View Journey report and set its global gold colour and desktop width. Empty image fields keep the built-in fallback design.', 'run-the-seas'); ?></p>
            <?php if (isset($_GET['rts_journey_design']) && 'saved' === sanitize_key(wp_unslash($_GET['rts_journey_design']))) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Journey design saved.', 'run-the-seas'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="rts_save_journey_design">
                <?php wp_nonce_field('rts_save_journey_design'); ?>
                <table class="form-table" role="presentation"><tbody>
                    <?php foreach ($fields as $key => $field) : ?>
                        <tr><th scope="row"><label for="rts-journey-<?php echo esc_attr($key); ?>"><?php echo esc_html($field[0]); ?></label></th><td>
                            <input id="rts-journey-<?php echo esc_attr($key); ?>" class="regular-text" type="url" name="rts_journey_design_assets[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($assets[$key] ?? ''); ?>" placeholder="https://">
                            <button type="button" class="button rts-select-journey-asset" data-target="rts-journey-<?php echo esc_attr($key); ?>"><?php esc_html_e('Select from Media Library', 'run-the-seas'); ?></button>
                            <p class="description"><?php echo esc_html($field[1]); ?></p>
                        </td></tr>
                    <?php endforeach; ?>
                    <tr><th scope="row"><label for="rts-journey-gold-color"><?php esc_html_e('Gold text and accent colour', 'run-the-seas'); ?></label></th><td><input id="rts-journey-gold-color" class="rts-journey-color" type="text" name="rts_journey_design_assets[gold_color]" value="<?php echo esc_attr($gold); ?>"><p class="description"><?php esc_html_e('Controls gold headings, labels, borders, buttons, and progress accents.', 'run-the-seas'); ?></p></td></tr>
                    <tr><th scope="row"><label for="rts-journey-page-width"><?php esc_html_e('Overall page width', 'run-the-seas'); ?></label></th><td><input id="rts-journey-page-width" class="small-text" type="number" min="700" max="1600" step="10" name="rts_journey_design_assets[page_width]" value="<?php echo esc_attr($width); ?>"> px<p class="description"><?php esc_html_e('Maximum desktop width of the journey report. It remains responsive on smaller screens.', 'run-the-seas'); ?></p></td></tr>
                </tbody></table>
                <?php submit_button(__('Save Journey Design', 'run-the-seas')); ?>
            </form>
        </div>
        <script>
        jQuery(function ($) { $('.rts-journey-color').wpColorPicker(); });
        document.addEventListener('click', function (event) {
            var button = event.target.closest('.rts-select-journey-asset');
            if (!button || !window.wp || !wp.media) return;
            event.preventDefault();
            var target = document.getElementById(button.getAttribute('data-target'));
            var frame = wp.media({ title: 'Select journey design image', library: { type: 'image' }, multiple: false });
            frame.on('select', function () { target.value = frame.state().get('selection').first().toJSON().url; });
            frame.open();
        });
        </script>
        <?php
    }

    /** Save optional View Journey artwork and appearance settings. */
    public function save_journey_design() {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        check_admin_referer('rts_save_journey_design');
        $input = isset($_POST['rts_journey_design_assets']) && is_array($_POST['rts_journey_design_assets'])
            ? wp_unslash($_POST['rts_journey_design_assets']) : array();
        $assets = array();
        foreach (array('logo_image', 'print_icon_image', 'email_icon_image', 'hero_image', 'founding_runner_icon', 'frame_image', 'progress_start_icon_image', 'footer_icon_image') as $key) {
            $assets[$key] = esc_url_raw(trim((string) ($input[$key] ?? '')));
        }
        $assets['gold_color'] = sanitize_hex_color($input['gold_color'] ?? '') ?: '#d99214';
        $assets['page_width'] = max(700, min(1600, absint($input['page_width'] ?? 1180)));

        update_option('rts_journey_design_assets', $assets, false);
        wp_safe_redirect(add_query_arg('rts_journey_design', 'saved', admin_url('admin.php?page=rts-journey-design')));
        exit;
    }

    /** Render Media Library controls for the public Captain's Suite survey. */
    public function render_survey_design_page() {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        $assets = get_option('rts_survey_design_assets', array());
        $assets = is_array($assets) ? $assets : array();
        wp_enqueue_media();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e("Captain's Suite Survey Design", 'run-the-seas'); ?></h1>
            <p><?php esc_html_e('Select images and the completion video from the Media Library, or paste their URLs. All files are optional.', 'run-the-seas'); ?></p>
            <?php if (isset($_GET['rts_survey_design']) && 'saved' === sanitize_key(wp_unslash($_GET['rts_survey_design']))) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Survey design assets saved.', 'run-the-seas'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="rts_save_survey_design">
                <?php wp_nonce_field('rts_save_survey_design'); ?>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><label for="rts-survey-hero-image"><?php esc_html_e('Survey banner image', 'run-the-seas'); ?></label></th>
                        <td><input id="rts-survey-hero-image" class="regular-text" type="url" name="rts_survey_design_assets[hero_image]" value="<?php echo esc_attr($assets['hero_image'] ?? ''); ?>" placeholder="https://"> <button type="button" class="button rts-select-survey-asset" data-target="rts-survey-hero-image"><?php esc_html_e('Select from Media Library', 'run-the-seas'); ?></button><p class="description"><?php esc_html_e('A wide cruise image for the banner on the right.', 'run-the-seas'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rts-survey-question-images"><?php esc_html_e('Per-question banner images', 'run-the-seas'); ?></label></th>
                        <td><textarea id="rts-survey-question-images" class="large-text code" rows="4" name="rts_survey_design_assets[question_images]" placeholder="101|102|103"><?php echo esc_textarea($assets['question_images'] ?? ''); ?></textarea> <button type="button" class="button rts-select-survey-asset" data-target="rts-survey-question-images" data-multiple="1"><?php esc_html_e('Select images from Media Library', 'run-the-seas'); ?></button><p class="description"><?php esc_html_e('Select one banner per question, in question order. You can also enter attachment IDs or image URLs separated by |, for example: 101|102|103.', 'run-the-seas'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rts-survey-header-left-image"><?php esc_html_e('Survey title left image', 'run-the-seas'); ?></label></th>
                        <td><input id="rts-survey-header-left-image" class="regular-text" type="url" name="rts_survey_design_assets[header_left_image]" value="<?php echo esc_attr($assets['header_left_image'] ?? ''); ?>" placeholder="https://"> <button type="button" class="button rts-select-survey-asset" data-target="rts-survey-header-left-image"><?php esc_html_e('Select from Media Library', 'run-the-seas'); ?></button><p class="description"><?php esc_html_e('A small transparent logo or ornament displayed to the left of “Survey Questions”.', 'run-the-seas'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rts-survey-header-right-image"><?php esc_html_e('Survey title right image', 'run-the-seas'); ?></label></th>
                        <td><input id="rts-survey-header-right-image" class="regular-text" type="url" name="rts_survey_design_assets[header_right_image]" value="<?php echo esc_attr($assets['header_right_image'] ?? ''); ?>" placeholder="https://"> <button type="button" class="button rts-select-survey-asset" data-target="rts-survey-header-right-image"><?php esc_html_e('Select from Media Library', 'run-the-seas'); ?></button><p class="description"><?php esc_html_e('A small transparent logo or ornament displayed to the right of “Survey Questions”.', 'run-the-seas'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rts-survey-progress-divider"><?php esc_html_e('Progress divider image', 'run-the-seas'); ?></label></th>
                        <td><input id="rts-survey-progress-divider" class="regular-text" type="url" name="rts_survey_design_assets[progress_divider_image]" value="<?php echo esc_attr($assets['progress_divider_image'] ?? ''); ?>" placeholder="https://"> <button type="button" class="button rts-select-survey-asset" data-target="rts-survey-progress-divider"><?php esc_html_e('Select from Media Library', 'run-the-seas'); ?></button><p class="description"><?php esc_html_e('Use a transparent, wide horizontal divider. It appears below the question count.', 'run-the-seas'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rts-survey-completion-video"><?php esc_html_e('Completion video', 'run-the-seas'); ?></label></th>
                        <td><input id="rts-survey-completion-video" class="regular-text" type="url" name="rts_survey_design_assets[completion_video]" value="<?php echo esc_attr($assets['completion_video'] ?? ''); ?>" placeholder="https://"> <button type="button" class="button rts-select-survey-asset" data-target="rts-survey-completion-video" data-library="video"><?php esc_html_e('Select video from Media Library', 'run-the-seas'); ?></button><p class="description"><?php esc_html_e('Upload or select the video shown after survey completion. MP4 is recommended for the widest browser support.', 'run-the-seas'); ?></p></td>
                    </tr>
                </tbody></table>
                <?php submit_button(__('Save Survey Design', 'run-the-seas')); ?>
            </form>
        </div>
        <script>
        document.addEventListener('click', function (event) {
            var button = event.target.closest('.rts-select-survey-asset');
            if (!button || !window.wp || !wp.media) return;
            event.preventDefault();
            var target = document.getElementById(button.getAttribute('data-target'));
            var libraryType = button.getAttribute('data-library') || 'image';
            var multiple = button.getAttribute('data-multiple') === '1';
            var frame = wp.media({ title: libraryType === 'video' ? 'Select completion video' : 'Select survey design image', library: { type: libraryType }, multiple: multiple });
            frame.on('select', function () {
                var selection = frame.state().get('selection');
                target.value = multiple ? selection.map(function (attachment) { return attachment.toJSON().url; }).join('|') : selection.first().toJSON().url;
            });
            frame.open();
        });
        </script>
        <?php
    }

    /** Save the optional survey images and completion video. */
    public function save_survey_design() {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(__('You do not have permission to manage this page.', 'run-the-seas'));
        }

        check_admin_referer('rts_save_survey_design');
        $input = isset($_POST['rts_survey_design_assets']) && is_array($_POST['rts_survey_design_assets'])
            ? wp_unslash($_POST['rts_survey_design_assets']) : array();
        $question_images = preg_split('/[|\\r\\n]+/', (string) ($input['question_images'] ?? ''));
        $question_images = array_filter(array_map(function ($image) {
            $image = trim($image);
            return ctype_digit($image) ? (string) absint($image) : esc_url_raw($image);
        }, $question_images));

        update_option('rts_survey_design_assets', array(
            'hero_image' => esc_url_raw(trim((string) ($input['hero_image'] ?? ''))),
            'question_images' => implode('|', $question_images),
            'header_left_image' => esc_url_raw(trim((string) ($input['header_left_image'] ?? ''))),
            'header_right_image' => esc_url_raw(trim((string) ($input['header_right_image'] ?? ''))),
            'progress_divider_image' => esc_url_raw(trim((string) ($input['progress_divider_image'] ?? ''))),
            'completion_video' => esc_url_raw(trim((string) ($input['completion_video'] ?? ''))),
        ), false);
        wp_safe_redirect(add_query_arg('rts_survey_design', 'saved', admin_url('admin.php?page=rts-survey-design')));
        exit;
    }
    
    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (!is_array($input)) {
            return $sanitized;
        }
        
        foreach ($input as $form_id => $settings) {
            $form_id = intval($form_id);
            if ($form_id <= 0) {
                continue;
            }
            
            $sanitized[$form_id] = array(
                'active' => isset($settings['active']) ? intval($settings['active']) : 0,
                'excluded' => isset($settings['excluded']) ? intval($settings['excluded']) : 0,  // ADD THIS
                'start_date' => sanitize_text_field($settings['start_date'] ?? ''),
                'end_date' => sanitize_text_field($settings['end_date'] ?? ''),
                'timezone' => sanitize_text_field($settings['timezone'] ?? 'UTC')
            );
        }
        
        return $sanitized;
    }
    
    /**
     * AJAX handler for saving settings - FIXED
     */
    public function ajax_save_settings() {
        // Debug: Log incoming request
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rts_admin_nonce')) {
            error_log('RTS: Invalid nonce');
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Check permissions
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            error_log('RTS: Unauthorized user');
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $form_id = intval($_POST['form_id']);
        $active = isset($_POST['active']) ? intval($_POST['active']) : 0;
        $excluded = isset($_POST['excluded']) ? intval($_POST['excluded']) : 0;
        $start_date = sanitize_text_field($_POST['start_date'] ?? '');
        $end_date = sanitize_text_field($_POST['end_date'] ?? '');
        
        error_log("RTS: Saving - Form: $form_id, Active: $active, Excluded: $excluded, Start: $start_date, End: $end_date");
        
        if (!$form_id) {
            error_log('RTS: Invalid form ID');
            wp_send_json_error('Invalid form ID');
            return;
        }
        
        // Get current settings
        $settings = get_option('rts_survey_settings', array());
        
        // Update settings for this form - MAKE SURE excluded IS INCLUDED
        $settings[$form_id] = array(
            'active' => $active,
            'excluded' => $excluded,  // THIS IS CRITICAL
            'start_date' => $start_date,
            'end_date' => $end_date,
            'timezone' => wp_timezone_string()
        );
        
        
        // Save settings
        $updated = update_option('rts_survey_settings', $settings);
        
        if ($updated !== false) {
            error_log('RTS: Settings saved successfully for form ' . $form_id);
            wp_send_json_success(array(
                'message' => 'Settings saved successfully',
                'settings' => $settings[$form_id]
            ));
            return;
        }
        
        // If update_option returns false, check if the value is already the same
        $current = get_option('rts_survey_settings', array());
        if (isset($current[$form_id]) && $current[$form_id] === $settings[$form_id]) {
            error_log('RTS: Settings already saved (no changes)');
            wp_send_json_success(array(
                'message' => 'Settings already saved',
                'settings' => $settings[$form_id]
            ));
            return;
        }
        
        error_log('RTS: Failed to save settings');
        wp_send_json_error('Failed to save settings');
    }
    
    /**
     * AJAX handler for toggling survey
     */
    public function ajax_toggle_survey() {
        // Debug: Log incoming request
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rts_admin_nonce')) {
            error_log('RTS: Invalid nonce for toggle survey');
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Check permissions
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            error_log('RTS: Unauthorized user for toggle survey');
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $form_id = intval($_POST['form_id']);
        $active = intval($_POST['active']);
        
        error_log("RTS: Admin Toggle Survey - Form: $form_id, Active: $active");
        
        if (!$form_id) {
            wp_send_json_error('Invalid form ID');
            return;
        }
        
        // Get current settings
        $settings = get_option('rts_survey_settings', array());
        
        // Initialize if not exists
        if (!isset($settings[$form_id])) {
            $settings[$form_id] = array(
                'active' => 0,
                'excluded' => 0,
                'start_date' => '',
                'end_date' => '',
                'timezone' => 'UTC'
            );
        }
        
        // Update active status (keep excluded status as is)
        $settings[$form_id]['active'] = $active;
        
        
        // Save settings
        $updated = update_option('rts_survey_settings', $settings);
        
        if ($updated !== false) {
            error_log('RTS: Admin Survey ' . $form_id . ' ' . ($active ? 'activated' : 'deactivated'));
            wp_send_json_success(array(
                'form_id' => $form_id,
                'active' => $active,
                'excluded' => $settings[$form_id]['excluded'],
                'message' => $active ? 'Survey activated successfully' : 'Survey deactivated successfully'
            ));
            return;
        }
        
        // If update_option returns false, check if the value is already the same
        $verify_settings = get_option('rts_survey_settings', array());
        if (isset($verify_settings[$form_id]) && $verify_settings[$form_id]['active'] == $active) {
            error_log('RTS: Admin Value already set correctly, returning success');
            wp_send_json_success(array(
                'form_id' => $form_id,
                'active' => $active,
                'excluded' => $settings[$form_id]['excluded'],
                'message' => $active ? 'Survey already activated' : 'Survey already deactivated'
            ));
            return;
        }
        
        error_log('RTS: Admin Failed to update option');
        wp_send_json_error('Failed to update survey status');
    }
    
    /**
     * AJAX handler for checking survey status
     */
    public function ajax_check_survey_status() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rts_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $form_id = intval($_POST['form_id']);
        if (!$form_id) {
            wp_send_json_error('Invalid form ID');
            return;
        }
        
        // Get settings
        $settings = get_option('rts_survey_settings', array());
        $form_settings = $settings[$form_id] ?? array(
            'active' => 0,
            'excluded' => 0,
            'start_date' => '',
            'end_date' => '',
            'timezone' => 'UTC'
        );
        
        // Get status
        $status = $this->get_survey_status($form_id, $form_settings);
        
        wp_send_json_success(array(
            'active' => intval($form_settings['active']),
            'excluded' => intval($form_settings['excluded'] ?? 0),
            'start_date' => $form_settings['start_date'] ?? '',
            'end_date' => $form_settings['end_date'] ?? '',
            'status' => $status,
            'current_time_utc' => current_time('mysql', true),
            'timezone' => wp_timezone_string()
        ));
    }
    
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'rts-survey') === false) {
            return;
        }
        
        wp_enqueue_style('rts-admin', RTS_PLUGIN_URL . 'assets/css/admin.css', array(), RTS_VERSION);
        wp_enqueue_script('rts-admin', RTS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), RTS_VERSION, true);
        
        wp_localize_script('rts-admin', 'rts_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rts_admin_nonce')
        ));
        
    }
    
    public function render_surveys_page() {
        global $wpdb;
        
        $forms = $this->get_fluent_forms();
        $settings = get_option('rts_survey_settings', array());
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Survey Management', 'run-the-seas'); ?></h1>
            
            <div class="rts-survey-list">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Survey Name', 'run-the-seas'); ?></th>
                            <th><?php _e('Status', 'run-the-seas'); ?></th>
                            <th><?php _e('Start Date', 'run-the-seas'); ?></th>
                            <th><?php _e('End Date', 'run-the-seas'); ?></th>
                            <th><?php _e('Responses', 'run-the-seas'); ?></th>
                            <th><?php _e('Actions', 'run-the-seas'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($forms as $form): 
                            $form_id = $form->id;
                            $form_settings = $settings[$form_id] ?? array(
                                'active' => 0,
                                'excluded' => 0,
                                'start_date' => '',
                                'end_date' => '',
                                'timezone' => 'UTC'
                            );
                            $status = $this->get_survey_status($form_id, $form_settings);
                            $responses = $this->get_survey_responses($form_id);
                            $is_excluded = isset($form_settings['excluded']) && $form_settings['excluded'] == 1;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($form->title); ?></strong>
                                <br>
                                <small>ID: <?php echo $form_id; ?></small>
                            </td>
                            <td>
                                <span class="rts-status-badge rts-status-<?php echo $status['class']; ?>">
                                    <?php echo $status['label']; ?>
                                </span>
                            </td>
                            <td><?php echo !empty($form_settings['start_date']) ? $form_settings['start_date'] : '—'; ?></td>
                            <td><?php echo !empty($form_settings['end_date']) ? $form_settings['end_date'] : '—'; ?></td>
                            <td>
                                <?php 
                                    echo $responses['total'];
                                    if ($responses['completed'] > 0) {
                                        echo ' <small>(' . $responses['completed'] . ' completed)</small>';
                                    }
                                ?>
                            </td>
                            <td>
                                <div class="rts-action-buttons">
                                    <a href="<?php echo esc_url($this->get_survey_settings_url($form_id)); ?>" class="button button-small">
                                        <?php _e('Settings', 'run-the-seas'); ?>
                                    </a>
                                    <a href="?page=rts-survey-analytics&form_id=<?php echo $form_id; ?>" class="button button-small">
                                        <?php _e('Analytics', 'run-the-seas'); ?>
                                    </a>
                                    <button class="button button-small rts-toggle-survey" data-form-id="<?php echo $form_id; ?>" data-active="<?php echo $form_settings['active']; ?>">
                                        <?php echo $form_settings['active'] ? __('Deactivate', 'run-the-seas') : __('Activate', 'run-the-seas'); ?>
                                    </button>
                                    <button class="button button-small rts-toggle-excluded <?php echo $is_excluded ? 'button-primary' : ''; ?>" 
                                            data-form-id="<?php echo $form_id; ?>" 
                                            data-excluded="<?php echo $is_excluded ? 1 : 0; ?>">
                                        <?php echo $is_excluded ? __('Un-exclude', 'run-the-seas') : __('Exclude', 'run-the-seas'); ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
    
    public function render_settings_page() {
        $form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
        $forms = $this->get_fluent_forms();
        $settings = get_option('rts_survey_settings', array());
        $current_form = null;
        
        // Find the current form if form_id is provided
        if ($form_id > 0) {
            foreach ($forms as $form) {
                if ($form->id == $form_id) {
                    $current_form = $form;
                    break;
                }
            }
        }
        ?>
        <div class="wrap">
            <h1><?php echo __('Survey Settings', 'run-the-seas'); ?></h1>
            
            <?php if (!$form_id || !$current_form): ?>
                <!-- Show form selection when no form is selected -->
                <div class="rts-form-selector">
                    <h2><?php _e('Select a Survey to Configure', 'run-the-seas'); ?></h2>
                    
                    <?php if (empty($forms)): ?>
                        <div class="notice notice-warning">
                            <p><?php _e('No Fluent Forms found. Please create a form first.', 'run-the-seas'); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="rts-form-grid">
                            <?php foreach ($forms as $form): 
                                $form_settings = $settings[$form->id] ?? array(
                                    'active' => 0,
                                    'excluded' => 0,
                                    'start_date' => '',
                                    'end_date' => '',
                                    'timezone' => 'UTC'
                                );
                                $status = $this->get_survey_status($form->id, $form_settings);
                                $responses = $this->get_survey_responses($form->id);
                                $is_excluded = isset($form_settings['excluded']) && $form_settings['excluded'] == 1;
                            ?>
                            <div class="rts-form-card <?php echo $is_excluded ? 'excluded-card' : ''; ?>" 
                                onclick="window.location.href='<?php echo esc_js($this->get_survey_settings_url($form->id)); ?>'">
                                <div class="rts-form-card-header">
                                    <h3><?php echo esc_html($form->title); ?></h3>
                                    <span class="rts-status-badge rts-status-<?php echo $status['class']; ?>">
                                        <?php echo $status['label']; ?>
                                    </span>
                                </div>
                                <div class="rts-form-card-body">
                                    <p><strong>ID:</strong> <?php echo $form->id; ?></p>
                                    <p><strong>Responses:</strong> <?php echo $responses['total']; ?></p>
                                    <?php if ($is_excluded): ?>
                                        <p><span class="rts-excluded-hint">⛔ Tracking Disabled</span></p>
                                    <?php endif; ?>
                                    <?php if (!empty($form_settings['start_date']) || !empty($form_settings['end_date'])): ?>
                                        <p><strong>Schedule:</strong> 
                                            <?php 
                                            if (!empty($form_settings['start_date'])) {
                                                echo 'From ' . $form_settings['start_date'];
                                            }
                                            if (!empty($form_settings['end_date'])) {
                                                echo ' To ' . $form_settings['end_date'];
                                            }
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <div class="rts-form-card-footer">
                                    <span class="rts-click-hint"><?php _e('Click to configure', 'run-the-seas'); ?> →</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Dropdown for selection -->
                        <div class="rts-form-select-dropdown">
                            <h3><?php _e('Or select from dropdown:', 'run-the-seas'); ?></h3>
                            <form method="get">
                                <input type="hidden" name="page" value="rts-survey-settings">
                                <select name="form_id" class="regular-text" style="min-width: 300px;">
                                    <option value=""><?php _e('Select a survey...', 'run-the-seas'); ?></option>
                                    <?php foreach ($forms as $form): ?>
                                        <option value="<?php echo $form->id; ?>">
                                            <?php echo esc_html($form->title); ?> (ID: <?php echo $form->id; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="button button-primary"><?php _e('Configure Survey', 'run-the-seas'); ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
                
            <?php else: ?>
                <!-- Show settings for selected form -->
                <div id="rts-settings-container">
                    <div class="rts-settings-header">
                        <h2><?php echo esc_html($current_form->title); ?></h2>
                        <a href="<?php echo esc_url($this->get_survey_settings_url()); ?>" class="button">← <?php _e('Back to Survey List', 'run-the-seas'); ?></a>
                    </div>
                    
                    <div id="rts-settings-message" class="notice" style="display:none;"></div>
                    
                    <?php 
                    $form_settings = $settings[$form_id] ?? array(
                        'active' => 0,
                        'excluded' => 0,
                        'start_date' => '',
                        'end_date' => '',
                        'timezone' => 'UTC'
                    );
                    
                    $start_date = !empty($form_settings['start_date']) ? date('Y-m-d\TH:i', strtotime($form_settings['start_date'])) : '';
                    $end_date = !empty($form_settings['end_date']) ? date('Y-m-d\TH:i', strtotime($form_settings['end_date'])) : '';
                    ?>
                    
                    <!-- Settings Form -->
                    <form method="post" action="options.php" id="rts-settings-form">
                        <?php settings_fields('rts_survey_settings_group'); ?>
                        
                        <div class="notice notice-info">
                            <p><strong>Current Time (UTC):</strong> <?php echo current_time('Y-m-d H:i:s', true); ?></p>
                            <p><strong>Timezone:</strong> <?php echo wp_timezone_string(); ?></p>
                        </div>
                        
                        <table class="form-table">
                            <!-- Survey Status -->
                            <tr>
                                <th scope="row"><?php _e('Survey Status', 'run-the-seas'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="rts_survey_settings[<?php echo $form_id; ?>][active]" value="1" <?php checked($form_settings['active'], 1); ?>>
                                        <?php _e('Activate Survey', 'run-the-seas'); ?>
                                    </label>
                                    <p class="description"><?php _e('When activated, the survey will be available to users.', 'run-the-seas'); ?></p>
                                </td>
                            </tr>
                            
                            <!-- Exclude from tracking -->
                            <tr>
                                <th scope="row"><?php _e('Exclude from Tracking', 'run-the-seas'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="rts_survey_settings[<?php echo $form_id; ?>][excluded]" value="1" <?php checked($form_settings['excluded'] ?? 0, 1); ?>>
                                        <?php _e('Exclude this survey from tracking', 'run-the-seas'); ?>
                                    </label>
                                    <p class="description"><?php _e('When checked, this survey will be completely skipped by the tracking system. No data will be collected.', 'run-the-seas'); ?></p>
                                </td>
                            </tr>
                            
                            <!-- Schedule -->
                            <tr>
                                <th scope="row"><?php _e('Schedule Survey', 'run-the-seas'); ?></th>
                                <td>
                                    <div class="rts-schedule-fields">
                                        <div class="rts-date-field">
                                            <label><?php _e('Start Date (UTC):', 'run-the-seas'); ?></label>
                                            <input type="datetime-local" 
                                                name="rts_survey_settings[<?php echo $form_id; ?>][start_date]" 
                                                value="<?php echo esc_attr($start_date); ?>"
                                                class="regular-text">
                                            <p class="description"><?php _e('Format: YYYY-MM-DDTHH:mm (UTC). Leave empty for no start date restriction.', 'run-the-seas'); ?></p>
                                        </div>
                                        
                                        <div class="rts-date-field">
                                            <label><?php _e('End Date (UTC):', 'run-the-seas'); ?></label>
                                            <input type="datetime-local" 
                                                name="rts_survey_settings[<?php echo $form_id; ?>][end_date]" 
                                                value="<?php echo esc_attr($end_date); ?>"
                                                class="regular-text">
                                            <p class="description"><?php _e('Format: YYYY-MM-DDTHH:mm (UTC). Leave empty for no end date restriction.', 'run-the-seas'); ?></p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            
                            <!-- Current Status -->
                            <tr>
                                <th scope="row"><?php _e('Current Status', 'run-the-seas'); ?></th>
                                <td>
                                    <div id="rts-current-status">
                                        <?php 
                                        $status = $this->get_survey_status($form_id, $form_settings);
                                        ?>
                                        <span class="rts-status-badge rts-status-<?php echo $status['class']; ?>">
                                            <?php echo $status['label']; ?>
                                        </span>
                                        <p class="description"><?php echo $status['message']; ?></p>
                                    </div>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="submit" class="button button-primary">
                                <?php _e('Save Settings', 'run-the-seas'); ?>
                            </button>
                            <span id="rts-saving-indicator" style="display:none; margin-left: 10px;">
                                <span class="spinner is-active"></span> Saving...
                            </span>
                        </p>
                    </form>
                    
                    <!-- Reset Statistics Section -->
                    <div class="rts-reset-section-wrapper">
                        <hr style="margin: 30px 0;">
                        <?php $this->render_reset_section($form_id); ?>
                    </div>
                    
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function render_analytics_page() {
        $form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
        $forms = $this->get_fluent_forms();
        $analytics = $form_id ? $this->get_form_analytics($form_id) : null;
        ?>
        <div class="wrap">
            <h1><?php echo __('Survey Analytics', 'run-the-seas'); ?></h1>
            
            <div class="rts-analytics-filters">
                <form method="get">
                    <input type="hidden" name="page" value="rts-survey-analytics">
                    <select name="form_id" class="regular-text">
                        <option value=""><?php _e('Select a survey...', 'run-the-seas'); ?></option>
                        <?php foreach ($forms as $form): ?>
                            <option value="<?php echo $form->id; ?>" <?php selected($form_id, $form->id); ?>>
                                <?php echo esc_html($form->title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button button-primary"><?php _e('View Analytics', 'run-the-seas'); ?></button>
                </form>
            </div>
            
            <?php if ($form_id && $analytics): ?>
                <div class="rts-analytics-dashboard">
                    <div class="rts-stats-grid">
                        <div class="rts-stat-card">
                            <h3><?php _e('Total Responses', 'run-the-seas'); ?></h3>
                            <div class="rts-stat-number"><?php echo $analytics['summary']->total_starts ?? 0; ?></div>
                        </div>
                        <div class="rts-stat-card">
                            <h3><?php _e('Completed', 'run-the-seas'); ?></h3>
                            <div class="rts-stat-number"><?php echo $analytics['summary']->completed ?? 0; ?></div>
                            <div class="rts-stat-percentage">
                                <?php 
                                $total = $analytics['summary']->total_starts ?? 0;
                                $completed = $analytics['summary']->completed ?? 0;
                                $percentage = $total > 0 ? round(($completed / $total) * 100) : 0;
                                echo $percentage . '% ' . __('completion rate', 'run-the-seas');
                                ?>
                            </div>
                        </div>
                        <div class="rts-stat-card">
                            <h3><?php _e('Abandoned', 'run-the-seas'); ?></h3>
                            <div class="rts-stat-number"><?php echo $analytics['summary']->abandoned ?? 0; ?></div>
                            <div class="rts-stat-percentage">
                                <?php 
                                $abandoned = $analytics['summary']->abandoned ?? 0;
                                $percentage = $total > 0 ? round(($abandoned / $total) * 100) : 0;
                                echo $percentage . '% ' . __('abandonment rate', 'run-the-seas');
                                ?>
                            </div>
                        </div>
                        <div class="rts-stat-card">
                            <h3><?php _e('Avg. Time Spent', 'run-the-seas'); ?></h3>
                            <div class="rts-stat-number">
                                <?php 
                                $avg_time = $analytics['summary']->avg_time_spent ?? 0;
                                echo $this->format_time($avg_time);
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    
    /** Build links that stay inside the Run The Seas BuddyPress admin area. */
    private function get_survey_settings_url($form_id = 0) {
        $frontend_page_id = absint(get_option('rts_admin_dashboard_page_id'));
        if ($frontend_page_id && is_page($frontend_page_id) && function_exists('rts_get_admin_dashboard_url')) {
            $url = rts_get_admin_dashboard_url('survey-settings');
        } elseif (function_exists('bp_current_component') && bp_current_component() === 'rts-admin' && function_exists('bp_displayed_user_domain')) {
            $url = trailingslashit(bp_displayed_user_domain() . 'rts-admin/survey-settings');
        } else {
            $url = admin_url('admin.php?page=rts-survey-settings');
        }
        return $form_id > 0 ? add_query_arg('form_id', (int) $form_id, $url) : $url;
    }

    private function get_fluent_forms() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fluentform_forms';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))) !== $table_name) {
            return array();
        }
        
        return $wpdb->get_results(
            "SELECT id, title FROM $table_name ORDER BY title ASC"
        );
    }
    
    // In class-rts-admin.php - RTS_Admin class
    public function get_survey_status($form_id, $settings) {
        $active = isset($settings['active']) ? intval($settings['active']) : 0;
        $excluded = isset($settings['excluded']) ? intval($settings['excluded']) : 0;
        $start_date = isset($settings['start_date']) ? $settings['start_date'] : '';
        $end_date = isset($settings['end_date']) ? $settings['end_date'] : '';
        
        $now = current_time('timestamp', true);
        
        // If excluded, show as excluded - don't combine with active
        if ($excluded) {
            return array(
                'class' => 'excluded',
                'label' => __('Excluded', 'run-the-seas'),
                'message' => __('This survey is excluded from tracking. No data is being collected.', 'run-the-seas'),
                'excluded' => true,
                'active' => $active
            );
        }
        
        if (!$active) {
            return array(
                'class' => 'inactive',
                'label' => __('Inactive', 'run-the-seas'),
                'message' => __('Survey is currently deactivated.', 'run-the-seas'),
                'excluded' => false,
                'active' => false
            );
        }
        
        if (!empty($start_date)) {
            $start_timestamp = strtotime($start_date);
            if ($start_timestamp && $now < $start_timestamp) {
                return array(
                    'class' => 'upcoming',
                    'label' => __('Scheduled', 'run-the-seas'),
                    'message' => sprintf(
                        __('Survey will start on %s (UTC)', 'run-the-seas'),
                        date_i18n('Y-m-d H:i:s', $start_timestamp)
                    ),
                    'excluded' => false,
                    'active' => true
                );
            }
        }
        
        if (!empty($end_date)) {
            $end_timestamp = strtotime($end_date);
            if ($end_timestamp && $now > $end_timestamp) {
                return array(
                    'class' => 'ended',
                    'label' => __('Ended', 'run-the-seas'),
                    'message' => sprintf(
                        __('Survey ended on %s (UTC)', 'run-the-seas'),
                        date_i18n('Y-m-d H:i:s', $end_timestamp)
                    ),
                    'excluded' => false,
                    'active' => true
                );
            }
        }
        
        return array(
            'class' => 'active',
            'label' => __('Active', 'run-the-seas'),
            'message' => __('Survey is currently active and accepting responses.', 'run-the-seas'),
            'excluded' => false,
            'active' => true
        );
    }

    private function get_form_analytics($form_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rts_survey_tracking';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return array(
                'summary' => null,
                'referrals' => array(),
                'abandonment' => array()
            );
        }
        
        $summary = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    COUNT(*) as total_starts,
                    SUM(CASE WHEN completion_status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN completion_status = 'abandoned' THEN 1 ELSE 0 END) as abandoned,
                    SUM(CASE WHEN completion_status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    AVG(time_spent_seconds) as avg_time_spent
                FROM {$wpdb->prefix}rts_survey_tracking
                WHERE form_id = %d",
                $form_id
            )
        );
        
        $referrals = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    referral_source,
                    COUNT(*) as count,
                    SUM(CASE WHEN completion_status = 'completed' THEN 1 ELSE 0 END) as completed
                FROM {$wpdb->prefix}rts_survey_tracking
                WHERE form_id = %d AND referral_source != ''
                GROUP BY referral_source
                ORDER BY count DESC",
                $form_id
            )
        );
        
        $abandonment = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT step_number, COUNT(*) as count 
                FROM {$wpdb->prefix}rts_survey_answers a
                JOIN {$wpdb->prefix}rts_survey_tracking t ON a.tracking_id = t.id
                WHERE t.form_id = %d AND t.completion_status = 'abandoned'
                GROUP BY step_number
                ORDER BY step_number",
                $form_id
            )
        );
        
        return array(
            'summary' => $summary,
            'referrals' => $referrals,
            'abandonment' => $abandonment
        );
    }
    
    private function get_survey_responses($form_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rts_survey_tracking';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return array('total' => 0, 'completed' => 0);
        }
        
        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}rts_survey_tracking WHERE form_id = %d",
                $form_id
            )
        );
        
        $completed = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}rts_survey_tracking WHERE form_id = %d AND completion_status = 'completed'",
                $form_id
            )
        );
        
        return array(
            'total' => $total ?: 0,
            'completed' => $completed ?: 0
        );
    }
    
    private function format_time($seconds) {
        if (!$seconds || $seconds < 0) {
            return '0s';
        }
        
        if ($seconds < 60) {
            return round($seconds) . 's';
        } elseif ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . round($seconds % 60) . 's';
        } else {
            return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
        }
    }

    /**
     * AJAX handler for toggling exclude status
     */
    public function ajax_toggle_excluded() {
        // Debug: Log incoming request
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rts_admin_nonce')) {
            error_log('RTS: Invalid nonce for toggle excluded');
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Check permissions
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            error_log('RTS: Unauthorized user for toggle excluded');
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $form_id = intval($_POST['form_id']);
        $excluded = intval($_POST['excluded']);
        
        error_log("RTS: Toggle Excluded - Form: $form_id, Excluded: $excluded");
        
        if (!$form_id) {
            wp_send_json_error('Invalid form ID');
            return;
        }
        
        // Get current settings
        $settings = get_option('rts_survey_settings', array());
        
        // Initialize if not exists
        if (!isset($settings[$form_id])) {
            $settings[$form_id] = array(
                'active' => 0,
                'excluded' => 0,
                'start_date' => '',
                'end_date' => '',
                'timezone' => 'UTC'
            );
        }
        
        // Update excluded status - MAKE SURE THIS IS SET
        $settings[$form_id]['excluded'] = $excluded;
        
        
        // Save settings
        $updated = update_option('rts_survey_settings', $settings);
        
        if ($updated !== false) {
            error_log('RTS: Form ' . $form_id . ' ' . ($excluded ? 'excluded' : 'un-excluded'));
            wp_send_json_success(array(
                'form_id' => $form_id,
                'excluded' => $excluded,
                'active' => $settings[$form_id]['active'],
                'message' => $excluded ? 'Survey excluded successfully' : 'Survey un-excluded successfully'
            ));
            return;
        }
        
        // If update_option returns false, check if the value is already the same
        $verify_settings = get_option('rts_survey_settings', array());
        if (isset($verify_settings[$form_id]) && isset($verify_settings[$form_id]['excluded']) && $verify_settings[$form_id]['excluded'] == $excluded) {
            error_log('RTS: Excluded value already set correctly');
            wp_send_json_success(array(
                'form_id' => $form_id,
                'excluded' => $excluded,
                'active' => $settings[$form_id]['active'],
                'message' => $excluded ? 'Survey already excluded' : 'Survey already un-excluded'
            ));
            return;
        }
        
        error_log('RTS: Failed to update excluded status');
        wp_send_json_error('Failed to update excluded status');
    }

    /**
     * Render reset statistics section in settings page
     */
    public function render_reset_section($form_id) {
        $questions = $this->get_form_questions($form_id);
        ?>
        <div class="rts-reset-section">
            <h3><?php _e('Reset Statistics', 'run-the-seas'); ?></h3>
            <p class="rts-reset-description">
                <?php _e('Reset statistics for this survey. These actions are independent of saving settings and will permanently delete data.', 'run-the-seas'); ?>
            </p>
            
            <div class="rts-reset-warning">
                <p><strong>⚠️ Warning:</strong> Resetting statistics will permanently delete data. This action cannot be undone.</p>
            </div>
            
            <!-- Reset Entire Survey -->
            <div class="rts-reset-option">
                <h4><?php _e('Reset Entire Survey', 'run-the-seas'); ?></h4>
                <p><?php _e('Delete all tracking data, answers, and analytics for this survey.', 'run-the-seas'); ?></p>
                <button type="button" class="button button-danger rts-reset-survey" data-form-id="<?php echo $form_id; ?>">
                    <?php _e('Reset All Statistics', 'run-the-seas'); ?>
                </button>
                <span id="rts-reset-survey-status" style="margin-left: 10px;"></span>
            </div>
            
            <!-- Reset Specific Question -->
            <div class="rts-reset-option">
                <h4><?php _e('Reset Specific Question', 'run-the-seas'); ?></h4>
                <p><?php _e('Delete all answers and analytics for a specific question.', 'run-the-seas'); ?></p>
                <div class="rts-reset-question-row">
                    <select id="rts-question-select" class="regular-text">
                        <option value=""><?php _e('Select a question...', 'run-the-seas'); ?></option>
                        <?php foreach ($questions as $question_id => $question_label): ?>
                            <option value="<?php echo esc_attr($question_id); ?>">
                                <?php echo esc_html($question_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button button-danger rts-reset-question" data-form-id="<?php echo $form_id; ?>">
                        <?php _e('Reset Question', 'run-the-seas'); ?>
                    </button>
                    <span id="rts-reset-question-status" style="margin-left: 10px;"></span>
                </div>
            </div>           
                     
        </div>
        <?php
    }

    /**
     * Get form questions for dropdown with proper display
     */
    private function get_form_questions($form_id) {
        global $wpdb;
        
        $questions = array();
        
        // Get questions from the form directly
        if (class_exists('FluentForm\App\Models\Form')) {
            $form = \FluentForm\App\Models\Form::find($form_id);
            if ($form && $form->form_fields) {
                $form_fields = json_decode($form->form_fields, true);
                if ($form_fields && isset($form_fields['fields'])) {
                    foreach ($form_fields['fields'] as $field) {
                        if (in_array($field['element'] ?? '', ['form_step', 'step_start', 'step_end', 'button'])) {
                            continue;
                        }
                        if (isset($field['attributes']['name'])) {
                            $field_name = $field['attributes']['name'];
                            $field_label = $field['settings']['label'] ?? $field_name;
                            
                            // Store the exact name (with brackets if present)
                            $questions[$field_name] = $field_label;
                        }
                    }
                }
            }
        }
        
        // If no questions found from form, get from database
        if (empty($questions)) {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT question_id, question_label 
                    FROM {$wpdb->prefix}rts_survey_answers 
                    WHERE form_id = %d 
                    ORDER BY question_label ASC",
                    $form_id
                )
            );
            foreach ($results as $row) {
                $questions[$row->question_id] = $row->question_label ?: $row->question_id;
            }
        }
        
        return $questions;
    }
    
    /**
     * AJAX handler for resetting survey statistics - FIXED
     */
    public function ajax_reset_survey() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rts_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $form_id = intval($_POST['form_id']);
        if (!$form_id) {
            wp_send_json_error('Invalid form ID');
            return;
        }
        
        // Confirm action
        $confirm = isset($_POST['confirm']) && $_POST['confirm'] === 'yes';
        if (!$confirm) {
            wp_send_json_error('Confirmation required');
            return;
        }
        
        // Get the tracking instance
        $tracking = $this->get_tracking_instance();
        if (!$tracking) {
            wp_send_json_error('Tracking system not available');
            return;
        }
        
        $result = $tracking->reset_survey_statistics($form_id);
        
        if ($result['success']) {
            // Log to WordPress admin action log
            $this->log_admin_action(
                'reset_survey',
                "Reset ALL statistics for form ID: {$form_id}",
                $result
            );
            
            wp_send_json_success($result);
        } else {
            wp_send_json_error('Failed to reset survey statistics');
        }
    }

    private function log_admin_action($action, $description, $data = array()) {
        global $wpdb;
        
        $log_table = $wpdb->prefix . 'rts_activity_logs';
        
        // Create table if it doesn't exist
        if ($wpdb->get_var("SHOW TABLES LIKE '$log_table'") != $log_table) {
            $this->create_activity_log_table();
        }
        
        $wpdb->insert(
            $log_table,
            array(
                'tracking_id' => 0,
                'submission_id' => 'admin',
                'action' => $action,
                'description' => $description . ' - ' . json_encode($data),
                'created_at' => current_time('mysql')
            )
        );
        
        error_log("RTS: Admin action logged - {$action}: {$description}");
    }
   
    public function ajax_reset_question() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rts_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $form_id = intval($_POST['form_id']);
        $question_id = sanitize_text_field($_POST['question_id']);
        
        error_log("RTS: Reset question request - Form: {$form_id}, Question: {$question_id}");
        
        if (!$form_id || empty($question_id)) {
            wp_send_json_error('Invalid form ID or question ID');
            return;
        }
        
        // Confirm action
        $confirm = isset($_POST['confirm']) && $_POST['confirm'] === 'yes';
        if (!$confirm) {
            wp_send_json_error('Confirmation required');
            return;
        }
        
        // Get the tracking instance
        $tracking = $this->get_tracking_instance();
        if (!$tracking) {
            wp_send_json_error('Tracking system not available');
            return;
        }
        
        $result = $tracking->reset_question_statistics($form_id, $question_id);
        
        if ($result['success']) {
            // Log to WordPress admin action log
            $this->log_admin_action(
                'reset_question',
                "Reset statistics for question: '{$question_id}' in form ID: {$form_id}",
                $result
            );
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'deleted_answers' => $result['deleted_answers'],
                'deleted_analytics' => $result['deleted_analytics']
            ));
        } else {
            wp_send_json_error('Failed to reset question statistics');
        }
    }
        

    /**
     * Get tracking instance - FIXED
     */
    private function get_tracking_instance() {
        // Try to get from the main plugin instance
        if (function_exists('rts_init')) {
            $plugin = rts_init();
            if ($plugin && isset($plugin->tracking)) {
                return $plugin->tracking;
            }
        }
        
        // Fallback: Create a new instance
        global $wpdb;
        if (class_exists('RTS_Tracking')) {
            if (!class_exists('RTS_Tracking')) {
                require_once plugin_dir_path(__FILE__) . 'includes/class-rts-tracking.php';
            }
            return new RTS_Tracking($wpdb);
        }
        
        return null;
    }

    /**
     * Get current questions from Fluent Form
     */
    private function get_current_questions_from_form($form_id) {
        $questions = array();
        
        if (class_exists('FluentForm\App\Models\Form')) {
            $form = \FluentForm\App\Models\Form::find($form_id);
            
            if ($form && $form->form_fields) {
                $form_fields = json_decode($form->form_fields, true);
                
                if ($form_fields && isset($form_fields['fields'])) {
                    foreach ($form_fields['fields'] as $field) {
                        // Skip non-question elements
                        if (in_array($field['element'] ?? '', ['form_step', 'step_start', 'step_end', 'button'])) {
                            continue;
                        }
                        
                        // Get field name
                        $field_name = $field['attributes']['name'] ?? 'field_' . ($field['index'] ?? uniqid());
                        
                        // Get field label
                        $field_label = $field['settings']['label'] ?? $field_name;
                        
                        $questions[$field_name] = $field_label;
                    }
                }
            }
        }
        
        return $questions;
    }    
    
   
    /**
     * Add referral stats to admin
     */
    public function render_referral_dashboard() {
        global $wpdb;
        
        // Get all participants with referral stats - FIXED
        $referrers = $wpdb->get_results(
            "SELECT 
                p.id,
                p.first_name,
                p.last_name,
                p.email,
                p.referral_code,
                p.referral_count,
                p.successful_referrals,
                p.total_referral_bonus,
                p.total_captain_miles_earned,
                COALESCE(
                    (SELECT COUNT(*) FROM {$wpdb->prefix}rts_referrals r 
                    WHERE r.referrer_id = p.id AND r.status = 'pending'), 0
                ) as pending_referrals,
                COALESCE(
                    (SELECT COUNT(*) FROM {$wpdb->prefix}rts_referrals r 
                    WHERE r.referrer_id = p.id AND r.status = 'completed'), 0
                ) as completed_referrals
            FROM {$wpdb->prefix}rts_participants p
            WHERE p.referral_count > 0 OR p.successful_referrals > 0
            ORDER BY p.successful_referrals DESC, p.total_referral_bonus DESC"
        );
        
        ?>
        <div class="wrap">
            <h1>📊 Referral Analytics Dashboard</h1>
            
            <div class="rts-referral-stats">
                <h2>Top Referrers</h2>
                
                <?php if (empty($referrers)): ?>
                    <p>No referral data available yet.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Referrer</th>
                                <th>Email</th>
                                <th>Referral Code</th>
                                <th>Total Referrals</th>
                                <th>Pending</th>
                                <th>Completed</th>
                                <th>Bonus Earned</th>
                                <th>Total Kilometres</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rank = 1; foreach ($referrers as $referrer): 
                                $pending = intval($referrer->pending_referrals);
                                $completed = intval($referrer->completed_referrals);
                                $total = $pending + $completed;
                            ?>
                            <tr>
                                <td><?php echo $rank++; ?></td>
                                <td><strong><?php echo esc_html($referrer->first_name . ' ' . $referrer->last_name); ?></strong></td>
                                <td><?php echo esc_html($referrer->email); ?></td>
                                <td><code><?php echo esc_html($referrer->referral_code); ?></code></td>
                                <td><?php echo $total; ?></td>
                                <td><?php echo $pending; ?></td>
                                <td><?php echo $completed; ?></td>
                                <td><?php echo rts_format_miles($referrer->total_referral_bonus); ?></td>
                                <td><?php echo rts_format_miles($referrer->total_captain_miles_earned); ?></td>
                                <td>
                                    <?php
                                    $frontend_page_id = absint(get_option('rts_admin_dashboard_page_id'));
                                    if ($frontend_page_id && is_page($frontend_page_id) && function_exists('rts_get_admin_dashboard_url')) {
                                        $details_url = add_query_arg('user_id', $referrer->id, rts_get_admin_dashboard_url('referrals'));
                                    } elseif (function_exists('bp_current_component') && bp_current_component() === 'rts-admin') {
                                        $details_url = add_query_arg('user_id', $referrer->id, trailingslashit(bp_displayed_user_domain() . 'rts-admin/referrals'));
                                    } else {
                                        $details_url = admin_url('admin.php?page=rts-referral-details&user_id=' . $referrer->id);
                                    }
                                    ?>
                                    <a href="<?php echo esc_url($details_url); ?>" class="button button-small">View Details</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Show the highest scoring participants.
     *
     * This is deliberately separate from referrals: participants can earn
     * points through other eligible activities as well as referrals.
     */
    public function render_leaderboard_page() {
        global $wpdb;

        $leaders = $wpdb->get_results(
            "SELECT id, first_name, last_name, referral_code, captain_miles_balance,
                    total_captain_miles_earned, successful_referrals
             FROM {$wpdb->prefix}rts_participants
             WHERE total_captain_miles_earned > 0
             ORDER BY total_captain_miles_earned DESC, captain_miles_balance DESC, id ASC
             LIMIT 5"
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e("Captain's Leaderboard", 'run-the-seas'); ?></h1>
            <p><?php esc_html_e('Top five participants with verified referral progress.', 'run-the-seas'); ?></p>

            <?php if (empty($leaders)): ?>
                <p><?php esc_html_e('No leaderboard points have been earned yet.', 'run-the-seas'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Rank', 'run-the-seas'); ?></th>
                            <th><?php esc_html_e('Participant', 'run-the-seas'); ?></th>
                            <th><?php esc_html_e("Distance", 'run-the-seas'); ?></th>
                            <th><?php esc_html_e('Available Kilometres', 'run-the-seas'); ?></th>
                            <th><?php esc_html_e('Verified Referrals', 'run-the-seas'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leaders as $index => $leader): ?>
                            <tr>
                                <td><strong><?php echo esc_html(number_format_i18n($index + 1)); ?></strong></td>
                                <td><?php echo esc_html(trim($leader->first_name . ' ' . $leader->last_name)); ?></td>
                                <td><strong><?php echo esc_html(rts_format_miles((int) $leader->total_captain_miles_earned)); ?></strong></td>
                                <td><?php echo esc_html(rts_format_miles((int) $leader->captain_miles_balance)); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int) $leader->successful_referrals)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

   
    /**
     * Add referral details page
     */
    public function render_referral_details() {
        global $wpdb;
        
        $participant_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        if (!$participant_id) {
            echo '<p>Invalid user ID</p>';
            return;
        }
        
        $participant = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}rts_participants WHERE id = %d",
                $participant_id
            )
        );
        
        if (!$participant) {
            echo '<p>User not found</p>';
            return;
        }
        
        // Get all referrals for this user - FIXED
        $referrals = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, p.first_name, p.last_name, p.email as referred_email 
                FROM {$wpdb->prefix}rts_referrals r
                LEFT JOIN {$wpdb->prefix}rts_participants p ON r.referred_participant_id = p.id
                WHERE r.referrer_id = %d
                ORDER BY r.referral_date DESC",
                $participant_id
            )
        );
        
        // Calculate stats
        $pending_count = 0;
        $completed_count = 0;
        foreach ($referrals as $ref) {
            if ($ref->status == 'pending') {
                $pending_count++;
            } elseif ($ref->status == 'completed') {
                $completed_count++;
            }
        }
        $total_count = $pending_count + $completed_count;
        
        ?>
        <div class="wrap">
            <h1>Referral Details: <?php echo esc_html($participant->first_name . ' ' . $participant->last_name); ?></h1>
            
            <div class="rts-referral-summary">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin: 20px 0;">
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #1a7efb;"><?php echo $total_count; ?></div>
                        <div style="font-size: 12px; color: #666;">Total Referrals</div>
                    </div>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #28a745;"><?php echo $completed_count; ?></div>
                        <div style="font-size: 12px; color: #666;">Completed</div>
                    </div>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #ffc107;"><?php echo $pending_count; ?></div>
                        <div style="font-size: 12px; color: #666;">Pending</div>
                    </div>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #1a7efb;"><?php echo rts_format_miles($participant->total_referral_bonus); ?></div>
                        <div style="font-size: 12px; color: #666;">Referral Kilometres</div>
                    </div>
                </div>
            </div>
            
            <h2>Referral List</h2>
            <?php if (empty($referrals)): ?>
                <p>No referrals found.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Referred User</th>
                            <th>Email</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Bonus</th>
                            <th>Completed Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $num = 1; foreach ($referrals as $ref): ?>
                        <tr>
                            <td><?php echo $num++; ?></td>
                            <td><?php echo esc_html($ref->first_name . ' ' . $ref->last_name); ?></td>
                            <td><?php echo esc_html($ref->referred_email); ?></td>
                            <td><?php echo date('M j, Y', strtotime($ref->referral_date)); ?></td>
                            <td>
                                <?php if ($ref->status == 'completed'): ?>
                                    <span style="color: #28a745;">✅ Completed</span>
                                <?php else: ?>
                                    <span style="color: #ffc107;">⏳ Pending</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo rts_format_miles($ref->bonus_earned); ?></td>
                            <td><?php echo $ref->completed_date ? date('M j, Y', strtotime($ref->completed_date)) : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
            <p><a href="?page=rts-referral-dashboard" class="button">← Back to Dashboard</a></p>
        </div>
        <?php
    }
    
  
}

// Admin hooks and handlers are unnecessary during ordinary front-end views.
if (is_admin()) {
    new RTS_Admin();
}
