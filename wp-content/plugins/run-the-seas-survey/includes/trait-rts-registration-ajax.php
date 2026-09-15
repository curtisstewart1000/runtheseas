<?php

if (!defined('ABSPATH')) {
    exit;
}

trait RTS_Registration_Ajax
{
    public function ajax_save_registration()
    {
        // Check nonce
        if (
            !isset($_POST['rts_registration_nonce']) ||
            !wp_verify_nonce($_POST['rts_registration_nonce'], 'rts_registration_nonce')
        ) {
            error_log('RTS: Invalid registration nonce');
            wp_send_json_error('Invalid security token. Please refresh the page and try again.');
            return;
        }

        // This must remain a strict server-side check: client validation can be bypassed.
        $age_consent = isset($_POST['age_consent']) &&
            'true' === sanitize_text_field(wp_unslash($_POST['age_consent']));

        // Sanitize first, then enforce every required registration field on the server.
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last_name = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $city = sanitize_text_field(wp_unslash($_POST['city'] ?? ''));
        $province = sanitize_text_field(wp_unslash($_POST['state'] ?? ''));
        $postal_code = sanitize_text_field(wp_unslash($_POST['zip'] ?? ''));
        $country = strtoupper(sanitize_text_field(wp_unslash($_POST['country'] ?? '')));
        $address = sanitize_textarea_field(wp_unslash($_POST['address'] ?? ''));
        $address_2 = sanitize_text_field(wp_unslash($_POST['address_2'] ?? ''));

        $tracking_id = isset($_POST['tracking_id']) ? intval($_POST['tracking_id']) : 0;
        $tracking_token = sanitize_text_field(wp_unslash($_POST['tracking_token'] ?? ''));
        $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;

        $required_fields = array(
            'first_name' => array('label' => 'First name', 'value' => $first_name),
            'last_name' => array('label' => 'Last name', 'value' => $last_name),
            'email' => array('label' => 'Email address', 'value' => $email),
            'phone' => array('label' => 'Mobile phone', 'value' => $phone),
            'address' => array('label' => 'Address', 'value' => $address),
            'city' => array('label' => 'City', 'value' => $city),
            'state' => array('label' => 'State / province', 'value' => $province),
            'zip' => array('label' => 'ZIP / postal code', 'value' => $postal_code),
            'country' => array('label' => 'Country', 'value' => $country),
            'age_consent' => array(
                'label' => 'Age and policy confirmation',
                'value' => $age_consent ? 'true' : '',
            ),
        );
        $missing_fields = array();

        foreach ($required_fields as $field_name => $field) {
            if ('' === trim((string) $field['value'])) {
                $missing_fields[$field_name] = $field['label'];
            }
        }

        if ($missing_fields) {
            error_log('RTS: Missing required registration fields: ' . implode(', ', array_keys($missing_fields)));
            wp_send_json_error(array(
                'message' => 'Please complete the following required fields: ' . implode(', ', $missing_fields) . '.',
                'fields' => array_keys($missing_fields),
            ));
            return;
        }

        if (!is_email($email)) {
            wp_send_json_error('Please enter a valid email address.');
            return;
        }

        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            wp_send_json_error(array(
                'message' => 'Please select a valid country.',
                'fields' => array('country'),
            ));
            return;
        }

        if (strlen($postal_code) > 30) {
            wp_send_json_error(array(
                'message' => 'ZIP / postal code must be 30 characters or fewer.',
                'fields' => array('zip'),
            ));
            return;
        }

        // A completed survey is linked only when the caller proves ownership
        // of its immutable tracking record.
        if ($tracking_id) {
            global $wpdb;
            if (!rts_verify_tracking_access($tracking_id, $tracking_token)) {
                wp_send_json_error('Your survey session is invalid or expired. Please complete the survey again.', 403);
                return;
            }
            $tracking = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, form_id, email, completion_status FROM {$wpdb->prefix}rts_survey_tracking WHERE id = %d",
                    $tracking_id
                )
            );

            if (!$tracking || $tracking->completion_status !== 'completed') {
                wp_send_json_error('Please complete the survey before claiming your rewards.');
                return;
            }
            if (!empty($tracking->email) && 0 !== strcasecmp($email, sanitize_email($tracking->email))) {
                wp_send_json_error('The registration email must match the completed survey.', 403);
                return;
            }
            $form_id = absint($tracking->form_id);
        } else {
            wp_send_json_error('Please complete the survey before registering.');
            return;
        }

        // Initialize registration
        if (!class_exists('RTS_Registration')) {
            require_once RTS_PLUGIN_PATH . 'includes/class-rts-registration.php';
        }

        $registration = new RTS_Registration($this->tracking);

        // Check if already registered by email
        $existing = $registration->get_participant_by_email($email);
        if ($existing) {
            $owns_existing_account = is_user_logged_in()
                && (int) $existing->user_id === get_current_user_id();
            if ($tracking_id && $owns_existing_account) {
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'rts_participants',
                    array('survey_tracking_id' => $tracking_id),
                    array('id' => $existing->id)
                );
                $registration->sync_travel_party_size($existing->id, $tracking_id);
                $registration->sync_location_from_tracking($existing->id, $tracking_id);
                wp_send_json_success(array(
                    'message' => 'Survey linked to existing account.',
                    'redirect_url' => home_url('/captains-suite'),
                    'is_existing_user' => true,
                ));
                return;
            }
            wp_send_json_error(array(
                'message' => 'This email already has an account. Please log in before linking a new survey.',
                'login_required' => true,
                'login_url' => rts_get_member_login_url(home_url('/captains-suite/')),
            ), 409);
            return;
        }
        if (!rts_consume_rate_limit('registration', 15, HOUR_IN_SECONDS)) {
            wp_send_json_error('Too many registration attempts. Please try again later.', 429);
        }

        // The registration name is displayed on member and QR-card surfaces,
        // so it must pass the same moderation review as later QR name edits.
        $name_moderator = function_exists('rts_init_buddypress_qr') ? rts_init_buddypress_qr() : null;
        if (!$name_moderator) {
            wp_send_json_error('Name review is temporarily unavailable. Please try again.');
            return;
        }
        foreach (array($first_name, $last_name) as $name_part) {
            $moderation = $name_moderator->moderate_display_name($name_part);
            if (!$moderation['valid']) {
                wp_send_json_error($moderation['message']);
                return;
            }
        }

        // CREATE WORDPRESS USER
        $user_id = $this->create_wordpress_user(
            $email,
            $first_name,
            $last_name,
            $phone,
            $country,
            $city,
            $province,
            $postal_code,
            $address,
            $address_2
        );
        if (!$user_id) {
            error_log('RTS: Failed to create WordPress user during registration');
            wp_send_json_error('Failed to create user account. Please try again.');
            return;
        }

        $request_cabin_credit = isset($_POST['request_cabin_credit']) &&
            $_POST['request_cabin_credit'] === 'Yes';

        $_POST['tracking_id'] = $tracking_id;

        // Create participant with user_id
        $participant_id = $registration->create_participant(
            $_POST,
            $request_cabin_credit,
            $user_id,
            $age_consent,
            $registration->get_request_ip_address()
        );

        if (!$participant_id) {
            error_log('RTS: Failed to create participant during registration');
            wp_send_json_error('Failed to create registration. Please try again.');
            return;
        }

        // Store the email against the completed survey
        if ($tracking_id) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'rts_survey_tracking',
                array('email' => $email),
                array('id' => $tracking_id),
                array('%s'),
                array('%d')
            );
        }

        // Handle cabin credit if requested
        if ($request_cabin_credit) {
            $registration->handle_cabin_credit_request($participant_id, $_POST);
            error_log('RTS: Cabin credit requested for participant: ' . $participant_id);
        }

        // Get participant data
        $participant = $registration->get_participant($participant_id);
        $registration->sync_participant_user_meta($participant_id);

        // ============================================
        // CLEAN REFERRAL URL - NO UTM PARAMETERS
        // ============================================
        $clean_referral_url = home_url('/survey?ref=' . $participant->referral_code);
        $referral_link = $clean_referral_url;

        // Simple sharing links with clean URL (UTM will be added by JavaScript)
        $sharing_links = array(
            'direct' => array(
                'url' => $clean_referral_url,
                'utm_source' => 'direct',
                'utm_medium' => 'direct',
                'utm_campaign' => 'direct_share',
                'icon' => '🔗',
                'color' => '#1a7efb',
                'share_url' => null
            )
        );

        // Passcode creation now starts only after the member proves ownership
        // of this address through the verification link. Do not send a reset-
        // style email to an account that has never chosen a passcode.
        $registration->log_timeline(
            $participant_id,
            'passcode_setup_pending',
            'Passcode creation will begin after email verification'
        );

        // ============================================
        // OPTIMIZATION: Store email data for background sending
        // ============================================
        // Store only the database identifier. The worker re-fetches the
        // participant, so duplicating PII, nonces, and access tokens in the
        // options table is unnecessary.
        $email_data = array('participant_id' => $participant_id);

        // Pending jobs must not be autoloaded on every WordPress request.
        $pending_key = 'rts_pending_registration_' . $participant_id;
        delete_option($pending_key);
        add_option($pending_key, $email_data, '', 'no');
        set_transient('rts_pending_registration_hint', true, HOUR_IN_SECONDS);
        error_log('RTS: Stored pending registration for participant: ' . $participant_id);

        // Send the verification email
        $verification_sent = $registration->send_verification_email($participant_id);
        error_log('RTS: Verification email sent: ' . ($verification_sent ? 'YES' : 'NO'));

        // ============================================
        // AUTO-LOGIN THE USER
        // ============================================
        wp_logout();

        if ($tracking_id) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'rts_participants',
                array('survey_tracking_id' => $tracking_id),
                array('id' => $participant_id)
            );
            error_log('RTS: Updated participant ' . $participant_id . ' with survey_tracking_id: ' . $tracking_id);
        }

        $user = get_userdata($user_id);
        if ($user) {
            do_action('wp_login', $user->user_login, $user);
            error_log('RTS: Auto-logged in user: ' . $user->user_login);
        }

        update_user_meta($user_id, 'rts_registration_completed', current_time('mysql'));

        do_action('rts_registration_completed', $participant_id);

        $redirect_url = home_url('/captains-suite');

        $resend_token = wp_generate_password(32, false, false);
        set_transient(
            'rts_registration_resend_' . $resend_token,
            array('participant_id' => $participant_id),
            30 * MINUTE_IN_SECONDS
        );
        $resend_url = add_query_arg(
            'rts_registration_resend',
            rawurlencode($resend_token),
            home_url('/register')
        );

        error_log('RTS: Registration completed successfully for participant: ' . $participant_id);

        // ============================================
        // SEND RESPONSE WITH CLEAN URL
        // ============================================
        wp_send_json_success(array(
            'participant_id' => $participant_id,
            'user_id' => $user_id,
            'email' => $email,
            'cabin_credit_number' => $participant->cabin_credit_number,
            'referral_code' => $participant->referral_code,
            'referral_link' => $clean_referral_url,
            'sharing_links' => $sharing_links,
            'redirect_url' => $redirect_url,
            'resend_url' => $resend_url,
            'message' => 'Registration completed successfully'
        ));
    }

    private function generate_all_sharing_links($referral_code, $form_page_url)
    {
        if (empty($form_page_url)) {
            $form_page_url = home_url('/survey');
        }

        // Parse the base URL
        $url_parts = parse_url($form_page_url);
        $base_url = $url_parts['scheme'] . '://' . $url_parts['host'] . (isset($url_parts['port']) ? ':' . $url_parts['port'] : '') . ($url_parts['path'] ?? '/');

        // Platform-specific configurations
        $platforms = array(
            'facebook' => array(
                'utm_source' => 'facebook',
                'utm_medium' => 'social',
                'utm_campaign' => 'facebook_share',
                'icon' => '📘',
                'color' => '#1877f2',
                'share_url' => 'https://www.facebook.com/sharer/sharer.php?u='
            ),
            'instagram' => array(
                'utm_source' => 'instagram',
                'utm_medium' => 'social',
                'utm_campaign' => 'instagram_share',
                'icon' => '📸',
                'color' => '#e4405f',
                'share_url' => null // Copy link for Instagram
            ),
            'twitter' => array(
                'utm_source' => 'twitter',
                'utm_medium' => 'social',
                'utm_campaign' => 'twitter_share',
                'icon' => '🐦',
                'color' => '#000000',
                'share_url' => 'https://twitter.com/intent/tweet?text='
            ),
            'linkedin' => array(
                'utm_source' => 'linkedin',
                'utm_medium' => 'social',
                'utm_campaign' => 'linkedin_share',
                'icon' => '💼',
                'color' => '#0a66c2',
                'share_url' => 'https://www.linkedin.com/sharing/share-offsite/?url='
            ),
            'reddit' => array(
                'utm_source' => 'reddit',
                'utm_medium' => 'social',
                'utm_campaign' => 'reddit_share',
                'icon' => '🤖',
                'color' => '#ff4500',
                'share_url' => 'https://www.reddit.com/submit?url='
            ),
            'running_club' => array(
                'utm_source' => 'running_club',
                'utm_medium' => 'website',
                'utm_campaign' => 'club_share',
                'icon' => '🏃',
                'color' => '#28a745',
                'share_url' => null // Copy link for website
            ),
            'forum' => array(
                'utm_source' => 'forum',
                'utm_medium' => 'forum',
                'utm_campaign' => 'forum_share',
                'icon' => '💬',
                'color' => '#6c757d',
                'share_url' => null // Copy link for forum
            ),
            'email' => array(
                'utm_source' => 'email',
                'utm_medium' => 'email',
                'utm_campaign' => 'email_share',
                'icon' => '📧',
                'color' => '#6c757d',
                'share_url' => 'mailto:?subject='
            ),
            'sms' => array(
                'utm_source' => 'sms',
                'utm_medium' => 'sms',
                'utm_campaign' => 'sms_share',
                'icon' => '📱',
                'color' => '#25D366',
                'share_url' => null // Copy link for SMS
            ),
            'direct' => array(
                'utm_source' => 'direct',
                'utm_medium' => 'direct',
                'utm_campaign' => 'direct_share',
                'icon' => '🔗',
                'color' => '#1a7efb',
                'share_url' => null // Direct link
            )
        );

        $links = array();
        foreach ($platforms as $platform => $config) {
            // Build the URL with UTM parameters
            $params = array(
                'ref' => $referral_code,
                'utm_source' => $config['utm_source'],
                'utm_medium' => $config['utm_medium'],
                'utm_campaign' => $config['utm_campaign'],
                'utm_content' => $referral_code
            );

            // Preserve existing query parameters
            if (isset($url_parts['query']) && !empty($url_parts['query'])) {
                parse_str($url_parts['query'], $existing_params);
                unset($existing_params['utm_source']);
                unset($existing_params['utm_medium']);
                unset($existing_params['utm_campaign']);
                unset($existing_params['utm_content']);
                unset($existing_params['ref']);
                $params = array_merge($existing_params, $params);
            }

            $query_string = http_build_query($params);
            $full_url = $base_url . '?' . $query_string;

            $links[$platform] = array(
                'url' => $full_url,
                'utm_source' => $config['utm_source'],
                'utm_medium' => $config['utm_medium'],
                'utm_campaign' => $config['utm_campaign'],
                'icon' => $config['icon'],
                'color' => $config['color'],
                'share_url' => $config['share_url'] ? $this->build_share_url($config['share_url'], $full_url, $referral_code) : null
            );
        }

        return $links;
    }

    /**
     * Build platform-specific share URL
     */
    private function build_share_url($share_url_template, $full_url, $referral_code)
    {
        $message = urlencode("Join me as a Founding Runner with Run The Seas! 🏃 Get \$100 credit when you register! Use my referral link: " . $full_url);

        if (strpos($share_url_template, 'facebook') !== false) {
            return $share_url_template . urlencode($full_url) . '&quote=' . urlencode("Join me as a Founding Runner with Run The Seas! 🏃 Get \$100 credit when you register!");
        } elseif (strpos($share_url_template, 'twitter') !== false) {
            return $share_url_template . $message . '&url=' . urlencode($full_url);
        } elseif (strpos($share_url_template, 'linkedin') !== false) {
            return $share_url_template . urlencode($full_url);
        } elseif (strpos($share_url_template, 'reddit') !== false) {
            return $share_url_template . urlencode($full_url) . '&title=' . urlencode("Join me as a Founding Runner with Run The Seas! 🏃");
        } elseif (strpos($share_url_template, 'mailto') !== false) {
            $subject = urlencode("Join me as a Founding Runner with Run The Seas!");
            $body = urlencode("I've just registered as a Founding Runner with Run The Seas! 🏃\n\nUse my referral link to join and get \$100 credit:\n\n" . $full_url . "\n\nJoin me on this exciting journey! 🏆");
            return $share_url_template . $subject . '&body=' . $body;
        }

        return $share_url_template . urlencode($full_url);
    }


    /**
     * Send registration confirmation email with referral link
     */
    public function send_registration_confirmation($email, $data, $participant, $referral_link)
    {
        global $wpdb;

        // Enhanced duplicate check with locking
        $lock_key = 'rts_confirmation_lock_' . $participant->id;
        if (get_transient($lock_key)) {
            error_log('RTS: Confirmation email lock active for participant ' . $participant->id . ', skipping...');
            return true;
        }
        set_transient($lock_key, true, 60);

        $confirmation_sent = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}rts_timeline 
                WHERE participant_id = %d AND activity_type = 'confirmation_sent'",
                $participant->id
            )
        );

        if ($confirmation_sent > 0) {
            error_log('RTS: Confirmation email already sent for participant ' . $participant->id . ' (count: ' . $confirmation_sent . ')');
            delete_transient($lock_key);
            return true;
        }

        // Also check if it was sent recently (last 10 minutes)
        $recent_sent = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}rts_timeline 
                WHERE participant_id = %d 
                AND activity_type = 'confirmation_sent' 
                AND activity_date > DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
                $participant->id
            )
        );

        if ($recent_sent > 0) {
            error_log('RTS: Confirmation email sent recently for participant ' . $participant->id . ', skipping...');
            delete_transient($lock_key);
            return true;
        }

        // No certificate is sent at registration. Email verification is the
        // only fulfilment event; it activates the suite and sends the PDF.
        if ((int) $participant->email_verified !== 1) {
            delete_transient($lock_key);
            return true;
        }

        $registration = new RTS_Registration();
        $benefits = $registration->activate_verified_benefits($participant->id, 0, true);
        delete_transient($lock_key);
        return !is_wp_error($benefits);

        error_log('RTS: Sending confirmation email for participant ' . $participant->id);
        $subject = 'Your Founding Runner Gift Certificate is Ready!';

        $first_name = sanitize_text_field($data['first_name'] ?? '');
        $last_name = sanitize_text_field($data['last_name'] ?? '');
        $cabin_credit_number = $participant->cabin_credit_number ?? 'Pending';

        // Get the form page URL from survey tracking
        $form_page_url = $this->get_form_page_url($data['tracking_id'] ?? 0);

        // Build platform-specific referral URLs
        $referral_url = $this->build_referral_url($participant->referral_code, $form_page_url);

        // Platform-specific URLs with different UTM parameters
        $facebook_url = $this->build_platform_url($participant->referral_code, $form_page_url, 'facebook', 'social', 'facebook_share');
        $instagram_url = $this->build_platform_url($participant->referral_code, $form_page_url, 'instagram', 'social', 'instagram_share');
        $twitter_url = $this->build_platform_url($participant->referral_code, $form_page_url, 'twitter', 'social', 'twitter_share');
        $linkedin_url = $this->build_platform_url($participant->referral_code, $form_page_url, 'linkedin', 'social', 'linkedin_share');
        $reddit_url = $this->build_platform_url($participant->referral_code, $form_page_url, 'reddit', 'social', 'reddit_share');
        $email_url = $this->build_platform_url($participant->referral_code, $form_page_url, 'email', 'direct', 'email_share');

        $message = "<html><body style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>";
        $message .= "<div style='text-align: center; padding: 30px; background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); border-radius: 12px; color: #fff;'>";
        $message .= "<h1 style='font-size: 28px; margin: 0;'>🏆 Congratulations, {$first_name}!</h1>";
        $message .= "<p style='font-size: 18px; opacity: 0.9;'>Your Founding Runner Gift Certificate is Ready</p>";
        $message .= "</div>";

        $message .= "<div style='background: #f8f9fa; border-radius: 12px; padding: 25px; margin: 20px 0; border: 2px solid #1a7efb;'>";
        $message .= "<h2 style='color: #1a7efb; margin-top: 0; text-align: center;'>🎫 YOUR PERSONALIZED CERTIFICATE</h2>";

        $message .= "<div style='background: #fff; border-radius: 8px; padding: 20px; margin: 15px 0; border: 1px solid #dee2e6;'>";
        $message .= "<p style='font-size: 20px; font-weight: bold; color: #1a7efb; margin: 0;'>RUN THE SEAS™</p>";
        $message .= "<p style='font-size: 16px; color: #333; margin: 5px 0;'><strong>FOUNDERS CRUISE CREDIT</strong></p>";
        $message .= "<p style='font-size: 36px; font-weight: bold; color: #28a745; margin: 10px 0;'>$100</p>";
        $message .= "<p style='font-size: 14px; color: #666; margin: 5px 0;'>GOOD TOWARDS THE FIRST RUN THE SEAS™ CRUISE!</p>";
        $message .= "<hr style='border: 1px dashed #dee2e6; margin: 15px 0;'>";
        $message .= "<p><strong>FOUNDING RUNNER:</strong> #" . sprintf("%06d", $participant->id) . "</p>";
        $message .= "<p><strong>CERTIFICATE NUMBER:</strong> " . ($cabin_credit_number ?: 'RTS-' . date('Y') . '-' . strtoupper(substr(uniqid(), -6))) . "</p>";
        $message .= "<p><strong>DATE ISSUED:</strong> " . date('F j, Y') . "</p>";
        $message .= "</div>";
        $message .= "</div>";

        // Referral section - with platform-specific buttons
        if ($participant->referral_code) {
            $message .= "<div style='background: #e3f2fd; border-radius: 12px; padding: 20px; margin: 20px 0; border-left: 4px solid #1a7efb;'>";
            $message .= "<h3 style='color: #1a7efb; margin-top: 0;'>🔗 Share Your Referral Link</h3>";
            $message .= "<p style='margin: 5px 0;'>Share your referral link with friends and family:</p>";

            // Main referral link (copyable)
            $message .= "<div style='background: #fff; padding: 12px; border-radius: 6px; margin: 10px 0; word-break: break-all;'>";
            $message .= "<code style='font-size: 14px;'>" . esc_url($referral_url) . "</code>";
            $message .= "</div>";

            // Social sharing buttons with platform-specific URLs
            $message .= "<div style='margin: 15px 0;'>";
            $message .= "<p style='font-size: 13px; color: #666; margin-bottom: 10px;'>Share on social media:</p>";
            $message .= "<div style='display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;'>";

            // Facebook
            $fb_share_url = 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode($facebook_url) . '&quote=Join%20me%20as%20a%20Founding%20Runner%20with%20Run%20The%20Seas%20and%20get%20%24100%20credit!';
            $message .= "<a href='" . esc_url($fb_share_url) . "' target='_blank' style='display: inline-block; padding: 10px 18px; background: #1877f2; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 600;'>📘 Facebook</a>";

            // Instagram (copy link for Instagram - can't share directly)
            $message .= "<button onclick='copyReferralLink(this, \"" . esc_url($instagram_url) . "\")' style='display: inline-block; padding: 10px 18px; background: #e4405f; color: #fff; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer;'>📸 Instagram</button>";

            // X (Twitter)
            $twitter_share_url = 'https://twitter.com/intent/tweet?text=Join%20me%20as%20a%20Founding%20Runner%20with%20Run%20The%20Seas!%20Get%20%24100%20credit%20when%20you%20register!&url=' . urlencode($twitter_url);
            $message .= "<a href='" . esc_url($twitter_share_url) . "' target='_blank' style='display: inline-block; padding: 10px 18px; background: #000; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 600;'>🐦 X</a>";

            // LinkedIn
            $linkedin_share_url = 'https://www.linkedin.com/sharing/share-offsite/?url=' . urlencode($linkedin_url);
            $message .= "<a href='" . esc_url($linkedin_share_url) . "' target='_blank' style='display: inline-block; padding: 10px 18px; background: #0a66c2; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 600;'>💼 LinkedIn</a>";

            // Reddit
            $reddit_share_url = 'https://www.reddit.com/submit?url=' . urlencode($reddit_url) . '&title=Join%20me%20as%20a%20Founding%20Runner%20with%20Run%20The%20Seas!';
            $message .= "<a href='" . esc_url($reddit_share_url) . "' target='_blank' style='display: inline-block; padding: 10px 18px; background: #ff4500; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 600;'>🤖 Reddit</a>";

            // Email
            $email_subject = 'Join me as a Founding Runner with Run The Seas!';
            $email_body = "I've just registered as a Founding Runner with Run The Seas! Use my referral link to join and get \$100 credit:\n\n" . $email_url . "\n\nJoin me on this exciting journey!";
            $mailto_url = 'mailto:?subject=' . urlencode($email_subject) . '&body=' . urlencode($email_body);
            $message .= "<a href='" . esc_url($mailto_url) . "' style='display: inline-block; padding: 10px 18px; background: #6c757d; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 600;'>📧 Email</a>";

            $message .= "</div></div>";

            // Platform tracking note
            $message .= "<p style='font-size: 11px; color: #999; margin-top: 10px; text-align: center;'>";
            $message .= "Each platform has unique tracking to help us understand which channels work best. ";
            $message .= "Your referral code works on all platforms!";
            $message .= "</p>";

            $message .= "</div>";
        }

        // Benefits section
        $message .= "<div style='background: #f8f9fa; border-radius: 12px; padding: 20px; margin: 20px 0;'>";
        $message .= "<h3 style='color: #333; margin-top: 0;'>🎁 Your Founding Runner Benefits</h3>";
        $message .= "<ul style='list-style: none; padding: 0;'>";
        $message .= "<li style='padding: 8px 0; border-bottom: 1px solid #dee2e6;'>✅ <strong>Founding Runner Cabin Credit</strong> - Your unique credit number</li>";
        $message .= "<li style='padding: 8px 0; border-bottom: 1px solid #dee2e6;'>✅ <strong>Captain's Suite</strong> - Exclusive access and privileges</li>";
        $message .= "<li style='padding: 8px 0; border-bottom: 1px solid #dee2e6;'>✅ <strong>42.2K Referral Marathon Challenge</strong> - Compete and win rewards</li>";
        $message .= "<li style='padding: 8px 0; border-bottom: 1px solid #dee2e6;'>✅ <strong>Verified Referral Progress</strong> - Each verified referral advances you by one kilometre</li>";
        $message .= "<li style='padding: 8px 0;'>✅ <strong>Digital Medals & Achievements</strong> - Build your collection</li>";
        $message .= "</ul>";
        $message .= "</div>";

        $message .= "<div style='text-align: center; padding: 20px;'>";
        $message .= "<a href='" . home_url('/captains-suite') . "' style='display: inline-block; padding: 14px 40px; background: #1a7efb; color: #fff; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: bold;'>";
        $message .= "Go to Captain's Suite →";
        $message .= "</a>";
        $message .= "</div>";

        $message .= "<p style='text-align: center; color: #999; font-size: 12px; margin-top: 20px;'>";
        $message .= "Your information is secure, encrypted, and used only to deliver your certificate and future Run The Seas™ updates.";
        $message .= "</p>";
        $message .= "</body></html>";

        // Keep registration-triggered reset messages identical to the normal
        // Captain's Suite passcode reset email.
        if (function_exists('rts_render_password_email_template')) {
            $production_message = rts_render_password_email_template('password-reset', array(
                'first_name' => $first_name ?: __('Captain', 'run-the-seas'),
                'logo_url' => function_exists('rts_password_email_logo_url') ? rts_password_email_logo_url() : '',
                'reset_link' => $reset_page_url,
                'site_url' => home_url('/'),
                'support_email' => 'support@runtheseas.com',
            ));
            if ($production_message) {
                $subject = __('Captain’s Suite Passcode Reset', 'run-the-seas');
                $message = $production_message;
            }
        }

        $headers = rts_mail_headers();

        $sent = wp_mail($email, $subject, $message, $headers);

        if ($sent) {
            $registration = new RTS_Registration();
            $registration->log_timeline(
                $participant->id,
                'confirmation_sent',
                'Confirmation email sent'
            );
            error_log('RTS: Confirmation email logged for participant ' . $participant->id);
        } else {
            error_log('RTS: Confirmation email FAILED for participant ' . $participant->id);
        }

        delete_transient($lock_key);
        return $sent;
    }

    /**
     * Build platform-specific URL with custom UTM parameters
     */
    private function build_platform_url($referral_code, $form_page_url, $utm_source, $utm_medium, $utm_campaign)
    {
        if (empty($form_page_url)) {
            $form_page_url = home_url('/survey');
        }

        // Parse the form page URL
        $url_parts = parse_url($form_page_url);
        $scheme = isset($url_parts['scheme']) ? $url_parts['scheme'] : 'http';
        $host = isset($url_parts['host']) ? $url_parts['host'] : $_SERVER['HTTP_HOST'];
        $port = isset($url_parts['port']) ? ':' . $url_parts['port'] : '';
        $path = isset($url_parts['path']) ? $url_parts['path'] : '/';

        // Build the base URL
        $base_url = $scheme . '://' . $host . $port . $path;

        // Add query parameters with platform-specific UTM
        $params = array(
            'ref' => $referral_code,
            'utm_source' => $utm_source,
            'utm_medium' => $utm_medium,
            'utm_campaign' => $utm_campaign,
            'utm_content' => $referral_code
        );

        // Preserve existing query parameters
        if (isset($url_parts['query']) && !empty($url_parts['query'])) {
            parse_str($url_parts['query'], $existing_params);
            // Remove any existing UTM params to avoid duplication
            unset($existing_params['utm_source']);
            unset($existing_params['utm_medium']);
            unset($existing_params['utm_campaign']);
            unset($existing_params['utm_content']);
            unset($existing_params['ref']);
            $params = array_merge($existing_params, $params);
        }

        $query_string = http_build_query($params);

        // Build the full URL
        $referral_url = $base_url . '?' . $query_string;

        return $referral_url;
    }


    /**
     * Get the form page URL from tracking data
     */
    private function get_form_page_url($tracking_id = 0, $form_id = 0)
    {
        global $wpdb;

        // If tracking_id is provided, try to get from tracking
        if ($tracking_id > 0) {
            // Try to get the referrer URL from tracking
            $referrer = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT referrer_url FROM {$wpdb->prefix}rts_survey_tracking WHERE id = %d",
                    $tracking_id
                )
            );

            if ($referrer && filter_var($referrer, FILTER_VALIDATE_URL)) {
                error_log('RTS: Found referrer URL from tracking: ' . $referrer);
                return $referrer;
            }

            // If we have form_id from tracking, try to find the form page
            if (empty($form_id)) {
                $form_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT form_id FROM {$wpdb->prefix}rts_survey_tracking WHERE id = %d",
                        $tracking_id
                    )
                );
            }
        }

        // If we have form_id, try to find the page with the form
        if ($form_id > 0) {
            // Try to find a page with this form shortcode
            $pages = get_posts(array(
                'post_type' => 'page',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                's' => '[fluentform id="' . $form_id . '"]'
            ));

            if (!empty($pages)) {
                $page_url = get_permalink($pages[0]->ID);
                error_log('RTS: Found form page from shortcode: ' . $page_url);
                return $page_url;
            }

            // Try meta query
            $pages = get_posts(array(
                'post_type' => 'page',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'meta_query' => array(
                    array(
                        'key' => '_fluentform_id',
                        'value' => $form_id,
                        'compare' => '='
                    )
                )
            ));

            if (!empty($pages)) {
                $page_url = get_permalink($pages[0]->ID);
                error_log('RTS: Found form page from meta: ' . $page_url);
                return $page_url;
            }
        }

        // Fallback: Try to find any page with the fluentform shortcode
        $pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            's' => '[fluentform'
        ));

        if (!empty($pages)) {
            $page_url = get_permalink($pages[0]->ID);
            error_log('RTS: Found survey page (fallback): ' . $page_url);
            return $page_url;
        }

        // Final fallback
        $fallback_url = home_url('/survey');
        error_log('RTS: Using fallback URL: ' . $fallback_url);
        return $fallback_url;
    }

    /**
     * AJAX: Check registration status
     */
    public function ajax_check_registration_status()
    {
        check_ajax_referer('rts_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error('Authentication required.', 401);
        }

        $current_user = wp_get_current_user();
        $email = current_user_can(RTS_MANAGE_CAPABILITY) && isset($_POST['email'])
            ? sanitize_email(wp_unslash($_POST['email']))
            : sanitize_email($current_user->user_email);
        if (!$email) {
            wp_send_json_error('Email required');
        }

        // Initialize registration
        if (!class_exists('RTS_Registration')) {
            require_once RTS_PLUGIN_PATH . 'includes/class-rts-registration.php';
        }

        $registration = new RTS_Registration($this->tracking);
        $participant = $registration->get_participant_by_email($email);
        wp_send_json_success(array('is_registered' => (bool) $participant));
    }

    /**
     * Build the full referral URL with proper parameters
     */
    private function build_referral_url($referral_code, $form_page_url)
    {
        if (empty($form_page_url) || $form_page_url == home_url('/survey')) {
            // Try to get the current page URL
            $form_page_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') .
                $_SERVER['HTTP_HOST'] .
                $_SERVER['REQUEST_URI'];
        }

        // Parse the form page URL
        $url_parts = parse_url($form_page_url);
        $scheme = isset($url_parts['scheme']) ? $url_parts['scheme'] : 'http';
        $host = isset($url_parts['host']) ? $url_parts['host'] : $_SERVER['HTTP_HOST'];
        $port = isset($url_parts['port']) ? ':' . $url_parts['port'] : '';
        $path = isset($url_parts['path']) ? $url_parts['path'] : '/';

        // Build the base URL
        $base_url = $scheme . '://' . $host . $port . $path;

        // Add query parameters
        $params = array(
            'ref' => $referral_code,
            'utm_source' => 'referral',
            'utm_medium' => 'share',
            'utm_campaign' => 'founding_runner'
        );

        // Preserve existing query parameters
        if (isset($url_parts['query']) && !empty($url_parts['query'])) {
            parse_str($url_parts['query'], $existing_params);
            $params = array_merge($existing_params, $params);
        }

        $query_string = http_build_query($params);

        // Build the full URL
        $referral_url = $base_url . '?' . $query_string;


        return $referral_url;
    }

    /**
     * Create WordPress user
     */
    private function create_wordpress_user(
        $email,
        $first_name,
        $last_name,
        $phone = '',
        $country = '',
        $city = '',
        $province = '',
        $postal_code = '',
        $address = '',
        $address_2 = ''
    )
    {
        // Check if user already exists
        $user_id = email_exists($email);
        if ($user_id) {
            return $user_id;
        }

        // Generate a random password
        $password = wp_generate_password(12, true);

        // Create the account while suppressing BuddyNext's separate generic
        // welcome email. The member creates their own passcode immediately
        // after proving ownership of their email address.
        $GLOBALS['rts_creating_member_account'] = true;
        try {
            $user_id = wp_create_user($email, $password, $email);
        } finally {
            unset($GLOBALS['rts_creating_member_account']);
        }

        if (is_wp_error($user_id)) {
            error_log('RTS: Failed to create WordPress user: ' . $user_id->get_error_message());
            return false;
        }

        // Update user meta
        wp_update_user(array(
            'ID' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $first_name . ' ' . $last_name
        ));

        // Keep the BuddyPress profile name in sync with this custom registration.
        if (function_exists('xprofile_set_field_data')) {
            xprofile_set_field_data('Name', $user_id, trim($first_name . ' ' . $last_name));
        }

        // Add user role (default subscriber)
        $user = new WP_User($user_id);
        $user->set_role('subscriber');

        // Set user meta
        update_user_meta($user_id, 'rts_registration_date', current_time('mysql'));
        update_user_meta($user_id, 'rts_phone', $phone ?? '');
        update_user_meta($user_id, 'rts_country', $country ?? '');
        update_user_meta($user_id, 'rts_city', $city ?? '');
        update_user_meta($user_id, 'rts_province', $province ?? '');
        update_user_meta($user_id, 'rts_postal_code', $postal_code ?? '');
        update_user_meta($user_id, 'rts_address', $address ?? '');
        update_user_meta($user_id, 'rts_address_2', $address_2 ?? '');

        // Keep a marker only; never store a plaintext password in user meta.
        update_user_meta($user_id, 'rts_temp_password', '1');

        // Send welcome email with password
        //wp_new_user_notification($user_id, null, 'both');

        return $user_id;
    }
    
   
    public function send_welcome_email_with_reset_link($user_id, $email, $first_name, $last_name)
    {
        // Deprecated: first-time members create their passcode directly after
        // email verification. Never send a password-reset email at registration,
        // including from legacy cron jobs or third-party callers.
        unset($user_id, $email, $first_name, $last_name);
        return false;

        /*
        * Get the WordPress user.
        */
        $user = get_user_by('ID', $user_id);

        if (!$user) {
            error_log('RTS: User not found for password reset: ' . $user_id);
            return false;
        }

        /*
         * One password-reset key is valid at a time. Reserve this initial send
         * before generating the key so overlapping registration processors do
         * not invalidate each other's emailed link.
         */
        if (!add_user_meta($user_id, 'rts_initial_password_reset_requested', current_time('mysql'), true)) {
            error_log('RTS: Initial password-reset email already requested for user ' . $user_id);
            return true;
        }

        /* Generate a one-time WordPress reset key for BuddyNext. */
        $password_reset_key = get_password_reset_key($user);

        if (is_wp_error($password_reset_key)) {
            delete_user_meta($user_id, 'rts_initial_password_reset_requested');
            error_log(
                'RTS: Failed to generate password reset key: ' .
                $password_reset_key->get_error_message()
            );

            return false;
        }

        $reset_page_url = function_exists('rts_get_member_password_reset_url')
            ? rts_get_member_password_reset_url($password_reset_key, $user->user_login)
            : network_site_url(
                'wp-login.php?action=rp&key=' . rawurlencode($password_reset_key) .
                '&login=' . rawurlencode($user->user_login),
                'login'
            );

        /*
        * Subject.
        */
        $subject = 'Welcome to Run The Seas - Set Your Password';

        /*
        * Login page.
        */
        $login_url = function_exists('rts_get_member_login_url')
            ? rts_get_member_login_url()
            : home_url('/login/');

        /* BuddyNext profile, with a safe fallback while its routes load. */
        $account_url = (
            class_exists('\\BuddyNext\\Core\\PageRouter')
            && method_exists('\\BuddyNext\\Core\\PageRouter', 'edit_profile_url')
        )
            ? \BuddyNext\Core\PageRouter::edit_profile_url($user_id)
            : home_url('/captains-suite/');

        /*
        * Build email.
        */
        $message = "<html><body style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>";

        /*
        * Header.
        */
        $message .= "<div style='text-align: center; padding: 30px; background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); border-radius: 12px; color: #fff;'>";

        $message .= "<h1 style='font-size: 28px; margin: 0;'>🏆 Welcome to Run The Seas!</h1>";

        $message .= "<p style='font-size: 18px; opacity: 0.9;'>Your Founding Runner Account Has Been Created</p>";

        $message .= "</div>";

        /*
        * Main content.
        */
        $message .= "<div style='background: #f8f9fa; border-radius: 12px; padding: 25px; margin: 20px 0; border: 2px solid #1a7efb;'>";

        $message .= "<h2 style='color: #1a7efb; margin-top: 0; text-align: center;'>🎫 Welcome, " .
            esc_html($first_name) .
            " " .
            esc_html($last_name) .
            "!</h2>";

        /*
        * Account created message.
        */
        $message .= "<div style='background: #fff; border-radius: 8px; padding: 20px; margin: 15px 0; border: 1px solid #dee2e6;'>";

        $message .= "<p style='font-size: 16px; color: #333;'>Your Founding Runner account has been created successfully!</p>";

        $message .= "<p style='font-size: 14px; color: #666;'>To get started, please set your password by clicking the link below:</p>";

        $message .= "</div>";

        /*
        * Password reset button.
        */
        $message .= "<div style='text-align: center; padding: 20px;'>";

        $message .= "<a href='" .
            esc_url($reset_page_url) .
            "' style='display: inline-block; padding: 14px 40px; background: #1a7efb; color: #fff; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: bold;'>";

        $message .= "🔑 Set Your Password";

        $message .= "</a>";

        $message .= "</div>";

        /*
        * Existing login link.
        */
        $message .= "<p style='font-size: 12px; color: #999; text-align: center;'>";

        $message .= "If you already have a password, you can ignore this email and ";

        $message .= "<a href='" .
            esc_url($login_url) .
            "' style='color: #1a7efb;'>login here</a>.";

        $message .= "</p>";

        /*
        * Account details.
        */
        $message .= "<div style='background: #f8f9fa; border-radius: 8px; padding: 15px; margin: 15px 0;'>";

        $message .= "<p style='margin: 5px 0; font-size: 13px;'><strong>Email:</strong> " .
            esc_html($email) .
            "</p>";

        $message .= "<p style='margin: 5px 0; font-size: 13px;'><strong>Account Type:</strong> Founding Runner</p>";

        $message .= "</div>";

        /*
        * BuddyNext profile.
        */
        $message .= "<p style='text-align: center; color: #666; font-size: 14px;'>";

        $message .= "Once you've set your password, you can access your ";

        $message .= "<a href='" .
            esc_url($account_url) .
            "' style='color: #1a7efb;'>BuddyNext profile</a>.";

        $message .= "</p>";

        $message .= "</div>";

        /*
        * Footer.
        */
        $message .= "<p style='text-align: center; color: #999; font-size: 12px; margin-top: 20px;'>";

        $message .= "Your information is secure and encrypted. © Run The Seas™";

        $message .= "</p>";

        $message .= "</body></html>";

        /*
        * Email headers.
        */
        $headers = rts_mail_headers();

        $email_template = rts_resolve_transactional_email_template(
            'password_reset',
            $subject,
            $message,
            array(
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'password_reset_url' => $reset_page_url,
                'login_url' => $login_url,
                'account_url' => $account_url,
                'captains_suite_url' => $account_url,
                'logo_url' => function_exists('rts_password_email_logo_url') ? rts_password_email_logo_url() : '',
                'support_email' => 'support@runtheseas.com',
            )
        );
        $subject = $email_template['subject'];
        $message = $email_template['html_body'];

        /*
        * Send email.
        */
        $sent = wp_mail(
            $email,
            $subject,
            $message,
            $headers
        );

        if ($sent) {
            error_log(
                'RTS: Welcome email with WordPress password reset link sent to ' . $email
            );
        } else {
            delete_user_meta($user_id, 'rts_initial_password_reset_requested');
            error_log(
                'RTS: Failed to send welcome email to ' . $email
            );
        }

        return $sent;
    }





}
