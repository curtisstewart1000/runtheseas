<?php
/**
 * Class RTS_Registration_Page
 * Handles the registration page and form processing
 */
class RTS_Registration_Page {
    
    private $db;
    private $tracking;
    private $registration;
    
    public function __construct($tracking = null, $registration = null) {
        global $wpdb;
        $this->db = $wpdb;
        $this->tracking = $tracking;
        $this->registration = $registration;
        
        // Register shortcode
        add_shortcode('rts_registration_form', array($this, 'render_registration_form'));        
        
        // AJAX handlers
        // Share tracking AJAX
        add_action('wp_ajax_rts_track_share', array($this, 'ajax_track_share'));
        
        // Add action to enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_registration_scripts'));
    }
    
    /**
     * Enqueue registration scripts
     */
    public function enqueue_registration_scripts() {
        global $post;
        
        // Only load on pages with the shortcode
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'rts_registration_form')) {
            wp_enqueue_script('jquery');
            
            // Get site icon URL safely
            $site_icon_url = get_site_icon_url(64);
            if (!$site_icon_url) {
                $site_icon_url = '';
            }
            
            wp_localize_script('jquery', 'rts_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('rts_nonce'),
                'user_id' => get_current_user_id(),
                'site_url' => home_url(),
                'site_name' => get_bloginfo('name'),
                'site_icon' => $site_icon_url
            ));
            
            wp_register_script('rts-registration', false, array('jquery'), '1.0', true);
            wp_enqueue_script('rts-registration');
            
            wp_localize_script('rts-registration', 'rts_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('rts_nonce'),
                'user_id' => get_current_user_id(),
                'site_url' => home_url(),
                'site_name' => get_bloginfo('name'),
                'site_icon' => $site_icon_url
            ));
        }
    }    
    
    
    public function render_registration_form($atts) {
        // Get parameters from URL
        $tracking_id = isset($_GET['tracking_id']) ? intval($_GET['tracking_id']) : 0;
        $tracking_token = isset($_GET['tracking_token'])
            ? sanitize_text_field(wp_unslash($_GET['tracking_token']))
            : sanitize_text_field(wp_unslash($_COOKIE['rts_tracking_token'] ?? ''));
        $form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
        $email = isset($_GET['email']) ? sanitize_email($_GET['email']) : '';
        $from_survey = isset($_GET['from_survey']) ? intval($_GET['from_survey']) : 0;

        // Essential access cookies permit the post-survey registration flow
        // even when optional analytics-cookie consent was declined.
        if (!$tracking_id && isset($_COOKIE['rts_tracking_id'])) {
            $tracking_id = absint($_COOKIE['rts_tracking_id']);
        }
        if ($tracking_id && '' === $tracking_token) {
            $tracking_token = rts_get_tracking_access_token_from_cookie($tracking_id);
        }

        // A numeric tracking ID alone never grants access to survey answers.
        if ($tracking_id && !rts_verify_tracking_access($tracking_id, $tracking_token)) {
            $tracking_id = 0;
            $tracking_token = '';
        }

        // --- GET TRACKING RECORD AND CHECK STATUS ---
        $tracking_record = null;
        $survey_completed = false;
        $already_registered = false;
        $participant_data = null;
        
        if ($tracking_id > 0) {
            global $wpdb;
            
            // Get tracking record
            $tracking_record = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}rts_survey_tracking WHERE id = %d",
                    $tracking_id
                )
            );
            
            if ($tracking_record) {
                // Check if survey is completed
                $survey_completed = ($tracking_record->completion_status === 'completed');
                
                // Check if already registered
                $registered_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}rts_participants WHERE survey_tracking_id = %d",
                        $tracking_id
                    )
                );
                
                if ($registered_id) {
                    $already_registered = true;
                    $participant_data = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}rts_participants WHERE id = %d",
                            $registered_id
                        )
                    );
                }
                
                // Get email from tracking
                if (!empty($tracking_record->email)) {
                    $email = $tracking_record->email;
                }
                
                // Get form_id from tracking
                if (empty($form_id) && !empty($tracking_record->form_id)) {
                    $form_id = $tracking_record->form_id;
                }
            }
        }

        // --- IF ALREADY REGISTERED, SHOW MESSAGE ---
        if ($already_registered && $participant_data) {
            return $this->render_already_registered($participant_data);
        }

        // --- IF SURVEY NOT COMPLETED, SHOW WARNING ---
        if ($tracking_id > 0 && !$survey_completed) {
            ob_start();
            ?>
            <div class="rts-registration-wrapper" style="max-width: 800px; margin: 0 auto; padding: 20px;">
                <div style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 12px; padding: 30px 25px; text-align: center;">
                    <span style="font-size: 48px; display: block; margin-bottom: 15px;">⚠️</span>
                    <h2 style="color: #856404; margin: 0 0 10px;">Survey Not Completed</h2>
                    <p style="color: #856404; font-size: 16px; margin-bottom: 20px;">
                        You haven't completed the survey yet. Please complete the survey first to claim your $100 credit.
                    </p>
                    <a href="/survey" style="display: inline-block; padding: 12px 40px; background: #1a7efb; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px;">
                        Take the Survey Now →
                    </a>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        // --- IF NO TRACKING_ID, SHOW MESSAGE ---
        if (!$tracking_id) {
            ob_start();
            ?>
            <div class="rts-registration-wrapper" style="max-width: 800px; margin: 0 auto; padding: 20px;">
                <div style="background: #e3f2fd; border: 2px solid #1a7efb; border-radius: 12px; padding: 30px 25px; text-align: center;">
                    <span style="font-size: 48px; display: block; margin-bottom: 15px;">📋</span>
                    <h2 style="color: #1a7efb; margin: 0 0 10px;">Complete the Survey First</h2>
                    <p style="color: #333; font-size: 16px; margin-bottom: 20px;">
                        You need to complete the survey before you can register and claim your $100 credit.
                    </p>
                    <a href="/survey" style="display: inline-block; padding: 12px 40px; background: #1a7efb; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px;">
                        Take the Survey Now →
                    </a>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        // --- GET REFERRAL CODE ---
        $referral_code = '';
        if (isset($_GET['ref'])) {
            $referral_code = sanitize_text_field($_GET['ref']);
        } elseif (isset($_GET['referral_code'])) {
            $referral_code = sanitize_text_field($_GET['referral_code']);
        } elseif (
            isset($_COOKIE['rts_survey_cookie_consent']) &&
            $_COOKIE['rts_survey_cookie_consent'] === 'accepted' &&
            isset($_COOKIE['rts_referral_code'])
        ) {
            $referral_code = sanitize_text_field($_COOKIE['rts_referral_code']);
        } elseif ($tracking_record && !empty($tracking_record->referral_code)) {
            $referral_code = $tracking_record->referral_code;
        }

        // --- CHECK IF USER IS ALREADY LOGGED IN ---
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $existing = $this->registration->get_participant_for_user($current_user);
            if ($existing) {
                return $this->render_already_registered($existing);
            }
            if (empty($email)) {
                $email = $current_user->user_email;
            }
        }
        
        // --- CHECK IF ALREADY REGISTERED BY EMAIL ---
        if ($email && $this->registration) {
            $existing = $this->registration->get_participant_by_email($email);
            if ($existing) {
                $login_url = rts_get_member_login_url(get_permalink());
                return '<div class="rts-registration-wrapper" style="max-width:800px;margin:0 auto;padding:20px;">'
                    . '<div style="background:#fff3cd;border:2px solid #ffc107;border-radius:12px;padding:30px 25px;text-align:center;">'
                    . '<h2 style="color:#856404;margin:0 0 10px;">Account Already Exists</h2>'
                    . '<p style="color:#856404;">Log in to link this completed survey to your existing account.</p>'
                    . '<a href="' . esc_url($login_url) . '" style="display:inline-block;padding:12px 40px;background:#1a7efb;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">Log In</a>'
                    . '</div></div>';
            }
        }
        
        // --- GET SURVEY DATA FOR PRE-FILLING ---
        $survey_data = array();
        if ($tracking_id && $form_id) {
            $survey_data = $this->get_survey_data($tracking_id, $form_id);
        }
        
        if (empty($email) && isset($survey_data['email'])) {
            $email = $survey_data['email'];
        }
        
        // --- GET SITE ICON URL FOR JAVASCRIPT ---
        $site_icon_url = get_site_icon_url(64);
        if (!$site_icon_url) {
            $site_icon_url = '';
        }
        
        // --- START OUTPUT ---
        ob_start();
        ?>
        <div class="rts-registration-wrapper" style="max-width: 800px; margin: 0 auto; padding: 20px;">
            <!-- SHOW SUCCESS MESSAGE IF SURVEY COMPLETED -->
            <?php if ($tracking_id > 0 && $survey_completed): ?>
                <!-- <div style="background: #d4edda; border: 2px solid #28a745; border-radius: 12px; padding: 20px 25px; margin-bottom: 25px; display: flex; align-items: center; gap: 15px;">
                    <span style="font-size: 32px;">✅</span>
                    <div>
                        <h3 style="margin: 0; color: #155724;">Survey Completed Successfully!</h3>
                        <p style="margin: 5px 0 0; color: #155724; font-size: 15px;">
                            Complete the registration form below to claim your $100 credit and access your Captain's Suite.
                        </p>
                    </div>
                </div> -->
            <?php endif; ?>

            <!-- SHOW REFERRAL NOTICE -->
            <?php if (!empty($referral_code)): ?>
                <!-- <div style="background: #e3f2fd; border: 2px solid #1a7efb; border-radius: 12px; padding: 15px 20px; margin-bottom: 25px; display: flex; align-items: center; gap: 15px;">
                    <span style="font-size: 24px;">🎉</span>
                    <div>
                        <p style="margin: 0; color: #1565c0; font-size: 15px;">
                            <strong>Welcome!</strong> You were referred by a Founding Runner. 
                            Complete your registration to claim your $100 credit!
                        </p>
                    </div>
                </div> -->
            <?php endif; ?>

            <!-- Form -->
            <div style="background: #fff; padding: 20px 25px; border-left: 2px solid #dee2e6; border-right: 2px solid #dee2e6; border-radius: 12px 12px 0 0; border-top: 2px solid #dee2e6;">
                <div id="rts-registration-status" style="display: none; margin-bottom: 20px;"></div>
                
                <form id="rts-registration-form" class="rts-registration-form" method="post" novalidate>
                    <?php wp_nonce_field('rts_registration_nonce', 'rts_registration_nonce'); ?>
                    
                    <!-- Hidden fields -->
                    <input type="hidden" name="tracking_id" id="rts_tracking_id_field" value="<?php echo esc_attr($tracking_id); ?>">
                    <input type="hidden" name="tracking_token" value="<?php echo esc_attr($tracking_token); ?>">
                    <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
                    <input type="hidden" name="from_survey" value="<?php echo esc_attr($from_survey); ?>">
                    <input type="hidden" name="action" value="rts_save_registration">
                    
                    <!-- YOUR INFORMATION -->
                    <h3 style="color: #1a7efb; border-bottom: 2px solid #1a7efb; padding-bottom: 10px; margin-top: 25px;">YOUR INFORMATION</h3>
                    
                    <div class="rts-form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="rts-form-group">
                            <label for="first_name" style="font-weight: 600; color: #333;">FIRST NAME *</label>
                            <input type="text" id="first_name" name="first_name" required 
                                placeholder="Enter your first name"
                                value="<?php echo esc_attr($survey_data['first_name'] ?? ''); ?>"
                                style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; box-sizing: border-box;">
                        </div>
                        <div class="rts-form-group">
                            <label for="last_name" style="font-weight: 600; color: #333;">LAST NAME *</label>
                            <input type="text" id="last_name" name="last_name" required
                                placeholder="Enter your last name"
                                value="<?php echo esc_attr($survey_data['last_name'] ?? ''); ?>"
                                style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; box-sizing: border-box;">
                        </div>
                    </div>
                    
                    <div class="rts-form-group">
                        <label for="email" style="font-weight: 600; color: #333;">EMAIL ADDRESS *</label>
                        <input type="email" id="email" name="email" required 
                            placeholder="Enter your email address"
                            value="<?php echo esc_attr($email); ?>"
                            <?php echo !empty($email) ? 'readonly' : ''; ?>
                            style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; box-sizing: border-box; <?php echo !empty($email) ? 'background: #f5f5f5;' : ''; ?>">
                        <?php if (!empty($email)): ?>
                            <small style="color: #28a745; display: block; margin-top: 5px;">✅ Email from survey submission</small>
                        <?php endif; ?>
                    </div>
                    
                    <div class="rts-form-group">
                        <label for="phone" style="font-weight: 600; color: #333;">MOBILE PHONE *</label>
                        <input type="tel" id="phone" name="phone" required
                            placeholder="Enter your mobile phone number"
                            value="<?php echo esc_attr($survey_data['phone'] ?? ''); ?>"
                            style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; box-sizing: border-box;">
                    </div>
                    
                    <!-- MAILING ADDRESS -->
                    <h3 style="color: #1a7efb; border-bottom: 2px solid #1a7efb; padding-bottom: 10px; margin-top: 30px;">MAILING ADDRESS</h3>
                    
                    <div class="rts-form-group">
                        <label for="address" style="font-weight: 600; color: #333;">ADDRESS LINE 1 *</label>
                        <input type="text" id="address" name="address" required
                            placeholder="Enter your street address"
                            value="<?php echo esc_attr($survey_data['address'] ?? ''); ?>"
                            style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; box-sizing: border-box;">
                    </div>
                    
                    <div class="rts-form-group">
                        <label for="address_2" style="font-weight: 600; color: #333;">ADDRESS LINE 2 (OPTIONAL)</label>
                        <input type="text" id="address_2" name="address_2"
                            placeholder="Apt, suite, unit, building, etc."
                            value="<?php echo esc_attr($survey_data['address_2'] ?? ''); ?>"
                            style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; box-sizing: border-box;">
                    </div>
                    
                    <div class="rts-form-row" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                        <div class="rts-form-group">
                            <label for="city" style="font-weight: 600; color: #333;">CITY *</label>
                            <input type="text" id="city" name="city" required
                                placeholder="Enter your city"
                                value="<?php echo esc_attr($survey_data['city'] ?? ''); ?>"
                                style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; box-sizing: border-box;">
                        </div>
                        <div class="rts-form-group">
                            <label for="state" style="font-weight: 600; color: #333;">STATE / PROVINCE *</label>
                            <input type="text" id="state" name="state" required
                                placeholder="Enter your state / province"
                                value="<?php echo esc_attr($survey_data['state'] ?? $survey_data['province'] ?? ''); ?>"
                                style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; box-sizing: border-box;">
                        </div>
                        <div class="rts-form-group">
                            <label for="zip" style="font-weight: 600; color: #333;">ZIP / POSTAL CODE *</label>
                            <input type="text" id="zip" name="zip" required
                                placeholder="Enter your ZIP / postal code"
                                value="<?php echo esc_attr($survey_data['zip'] ?? $survey_data['postal_code'] ?? ''); ?>"
                                style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; box-sizing: border-box;">
                        </div>
                    </div>
                    
                    <div class="rts-form-group">
                        <label for="country" style="font-weight: 600; color: #333;">COUNTRY *</label>
                        <select id="country" name="country" required style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; box-sizing: border-box;">
                            <option value="">Select your country</option>
                            <?php 
                            $selected_country = $survey_data['country'] ?? '';
                            echo $this->get_country_options($selected_country); 
                            ?>
                        </select>
                    </div>
                    
                    <!-- ADDITIONAL INFORMATION (OPTIONAL) -->
                    <h3 style="color: #1a7efb; border-bottom: 2px solid #1a7efb; padding-bottom: 10px; margin-top: 30px;">ADDITIONAL INFORMATION (OPTIONAL)</h3>
                    <p style="color: #666; font-size: 14px; margin-top: 0;">This helps us create a tailored onboard program for you.</p>
                    
                    <div class="rts-form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="rts-form-group">
                            <label for="gender" style="font-weight: 600; color: #333;">GENDER</label>
                            <select id="gender" name="gender" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; box-sizing: border-box;">
                                <option value="">Select your gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                                <option value="Prefer not to say">Prefer not to say</option>
                            </select>
                        </div>
                        <div class="rts-form-group">
                            <label for="age_range" style="font-weight: 600; color: #333;">AGE RANGE</label>
                            <select id="age_range" name="age_range" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; box-sizing: border-box;">
                                <option value="">Select your age range</option>
                                <option value="18-24">18-24</option>
                                <option value="25-34">25-34</option>
                                <option value="35-44">35-44</option>
                                <option value="45-54">45-54</option>
                                <option value="55-64">55-64</option>
                                <option value="65+">65+</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Cabin Credit Request -->
                    <div class="rts-form-group" style="background: #e3f2fd; padding: 20px; border-radius: 8px; margin-top: 20px; border: 2px solid #1a7efb;">
                        <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #1a7efb;">
                            <strong>Do you want to request the Founding Runner Cabin Credit?</strong>
                        </label>
                        <p style="color: #555; font-size: 14px; margin-bottom: 15px;">
                            This is required to participate in the 42.2K Referral Marathon Challenge.
                        </p>
                        <div style="display: flex; gap: 30px; flex-wrap: wrap;">
                            <label style="font-size: 16px;">
                                <input type="radio" name="request_cabin_credit" value="Yes" checked> 
                                ✅ Yes, Claim My Cabin Credit
                            </label>
                            <label style="font-size: 16px;">
                                <input type="radio" name="request_cabin_credit" value="No"> 
                                ❌ No, Skip for Now
                            </label>
                        </div>
                    </div>
                    
                    <!-- Referral Code -->
                    <div class="rts-form-group">
                        <label for="referral_code_input" style="font-weight: 600; color: #333;">Referral Code</label>
                        <input type="text" id="referral_code_input" name="referral_code_input"
                            placeholder="Enter the referral code from a friend"
                            value="<?php echo esc_attr($referral_code); ?>"
                            <?php echo !empty($referral_code) ? 'readonly' : ''; ?>
                            style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; box-sizing: border-box; <?php echo !empty($referral_code) ? 'background: #f5f5f5;' : ''; ?>">
                        <?php if (!empty($referral_code)): ?>
                            <small style="color: #28a745; display: block; margin-top: 5px;">
                                ✅ Referral code detected
                            </small>
                        <?php else: ?>
                            <!-- <small style="color: #666;">Enter a referral code to participate </small> -->
                        <?php endif; ?>
                    </div>
                    
                    <!-- Age and legal consent -->
                    <div class="rts-form-group" style="margin-top: 20px;">
                        <label for="age_consent" style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" id="age_consent" name="age_consent" value="true" required aria-describedby="age_consent_error">
                            I confirm I am 18 years of age or older, or the age of majority in my province or state of residence, and I have read and agree to the <a href="<?php echo esc_url(home_url('/terms')); ?>" target="_blank" rel="noopener noreferrer">Terms &amp; Conditions</a> and <a href="<?php echo esc_url(home_url('/privacy')); ?>" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.
                        </label>
                        <div id="age_consent_error" class="rts-consent-error" role="alert" aria-live="polite" hidden>
                            Please confirm your age and agreement to the Terms &amp; Conditions and Privacy Policy.
                        </div>
                        <label style="display: block;">
                            <input type="checkbox" name="marketing_consent">
                            I agree to receive marketing communications about events and promotions
                        </label>
                    </div>
                    
                    <!-- Submit -->
                    <div style="text-align: center; margin-top: 30px;">
                        <button type="submit" class="rts-submit-btn" style="padding: 16px 60px; background: #1a7efb; color: #fff; border: none; border-radius: 6px; font-size: 20px; font-weight: bold; cursor: pointer; transition: all 0.3s ease;">
                            BECOME A FOUNDING RUNNER
                        </button>
                        <p style="font-size: 12px; color: #999; margin-top: 10px;">
                            Your information is secure, encrypted, and used only to deliver your certificate and future Run The Seas™ updates.
                        </p>
                    </div>
                </form>
            </div>
        </div>
        
        <style>
            .rts-registration-form .rts-form-group {
                margin-bottom: 15px;
            }
            .rts-registration-form label {
                display: block;
                margin-bottom: 5px;
            }
            .rts-registration-form input[type="text"],
            .rts-registration-form input[type="email"],
            .rts-registration-form input[type="tel"],
            .rts-registration-form select {
                width: 100%;
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 6px;
                font-size: 16px;
                box-sizing: border-box;
            }
            .rts-registration-form input:focus,
            .rts-registration-form select:focus {
                border-color: #1a7efb;
                outline: none;
                box-shadow: 0 0 0 2px rgba(26, 126, 251, 0.2);
            }
            .rts-registration-form input[readonly] {
                background: #f5f5f5;
                cursor: not-allowed;
            }
            .rts-registration-form .error {
                border-color: #dc3545 !important;
            }
            .rts-registration-form .rts-consent-error {
                color: #dc3545;
                font-size: 14px;
                margin: 6px 0 12px;
            }
            .rts-registration-form .rts-field-error {
                color: #dc3545;
                display: block;
                font-size: 14px;
                margin-top: 6px;
            }
            .rts-registration-form .rts-submit-btn:hover {
                background: #1565c0 !important;
                transform: translateY(-2px);
                box-shadow: 0 4px 15px rgba(26, 126, 251, 0.3);
            }
            @media (max-width: 768px) {
                .rts-registration-form .rts-form-row {
                    grid-template-columns: 1fr !important;
                }
                .rts-registration-wrapper {
                    padding: 10px !important;
                }
            }
        </style>
        
        <script>
            jQuery(document).ready(function($) {
                console.log('Registration form initialized');
                
                // --- GET TRACKING_ID FROM MULTIPLE SOURCES ---
                function getCookie(name) {
                    var value = "; " + document.cookie;
                    var parts = value.split("; " + name + "=");
                    if (parts.length == 2) {
                        return parts.pop().split(";").shift();
                    }
                    return null;
                }
                
                function getTrackingId() {
                    // 1. Check hidden field
                    var trackingId = $('#rts_tracking_id_field').val();
                    if (trackingId && trackingId != '0') {
                        console.log('RTS: tracking_id from hidden field:', trackingId);
                        return trackingId;
                    }
                    
                    // 2. Check URL parameter
                    var urlParams = new URLSearchParams(window.location.search);
                    trackingId = urlParams.get('tracking_id');
                    if (trackingId) {
                        console.log('RTS: tracking_id from URL:', trackingId);
                        $('#rts_tracking_id_field').val(trackingId);
                        return trackingId;
                    }
                    
                    // 3. Check cookie
                    trackingId = getCookie('rts_survey_cookie_consent') === 'accepted'
                        ? getCookie('rts_tracking_id')
                        : null;
                    if (trackingId) {
                        console.log('RTS: tracking_id from cookie:', trackingId);
                        $('#rts_tracking_id_field').val(trackingId);
                        return trackingId;
                    }
                    
                    console.log('RTS: No tracking_id found');
                    return null;
                }
                
                // Get tracking ID on page load
                var trackingId = getTrackingId();
                console.log('RTS: Final tracking_id:', trackingId);            
            
                
                // --- BUILD SUCCESS MESSAGE FUNCTION ---
                function rtsBuildSuccessMessage(data, requestedCredit, cleanBaseUrl) {
                    var firstName = document.getElementById('first_name');
                    var firstNameVal = firstName ? firstName.value : '';
                    
                    var html = '<div class="rts-success-container" style="max-width: 700px; margin: 0 auto; padding: 20px;">';
                    html += '<div class="rts-success-card" style="background: #fff; border-radius: 12px; padding: 30px; border: 2px solid #28a745; box-shadow: 0 4px 20px rgba(40, 167, 69, 0.15);">';
                    
                    // Header
                    html += '<div style="text-align: center; padding-bottom: 20px; border-bottom: 2px solid #e9ecef;">';
                    html += '<div style="font-size: 48px;">🎉</div>';
                    html += '<h2 style="color: #28a745; margin: 10px 0 5px;">Registration Complete!</h2>';
                    html += '<p style="color: #666; font-size: 16px;">Welcome, <strong>' + firstNameVal + '</strong>! Your account has been created.</p>';
                    html += '</div>';

                    // Verification notice
                    if (data.email) {
                        html += '<div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 18px; margin: 20px 0; color: #664d03;">';
                        html += '<h3 style="margin: 0 0 10px; color: #856404;">You\'re almost there!</h3>';
                        html += '<p style="margin: 0 0 12px; line-height: 1.55;">We\'ve sent a verification email to <strong>' + data.email + '</strong>. Click the button in that email to receive your $100 Cruise Credit and unlock your Captain\'s Suite.</p>';
                        html += '<p style="margin: 0; line-height: 1.55;">Can\'t find the email? Check your junk or spam folder or <a href="' + (data.resend_url || '#') + '" style="color: #1a7efb; font-weight: 700;">click here to resend it</a>.</p>';
                        html += '</div>';
                    }
                    
                    // Cabin Credit
                    if (requestedCredit && data.cabin_credit_number) {
                        html += '<div style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin: 15px 0; text-align: center; border: 2px solid #1a7efb;">';
                        html += '<p style="margin: 0;"><strong>🏅 Cabin Credit Number:</strong> ' + data.cabin_credit_number + '</p>';
                        html += '<p style="margin: 5px 0 0; font-size: 14px; color: #666;">Status: Pending Approval</p>';
                        html += '</div>';
                    }
                    
                    // Referral Link Share Section
                    if (data.referral_code) {
                        html += '<div style="margin: 20px 0; padding: 20px; background: #f8f9fa; border-radius: 12px; border: 2px solid #1a7efb;">';
                        html += '<h4 style="color: #1a7efb; margin-top: 0; text-align: center;">🔗 Share Your Referral Link</h4>';
                        html += '<p style="font-size: 14px; color: #666; text-align: center; margin-bottom: 15px;">Share this link with friends and family to participate in the 42.2K Referral Marathon Challenge!</p>';
                        
                        html += '<div style="background: #f8f9fa; padding: 12px; border-radius: 8px; margin: 15px 0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; border: 1px solid #dee2e6;">';
                        html += '<input type="text" value="' + cleanBaseUrl + '" readonly id="rts-share-link" onclick="this.select()" style="flex: 1; min-width: 200px; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; font-size: 13px; font-family: monospace; color: #333;">';
                        html += '<button class="rts-share-btn copy" onclick="rtsCopyLinkWithTracking(\'' + cleanBaseUrl + '\')" style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; background: #1a7efb; color: #fff; border: none; border-radius: 4px; font-size: 13px; cursor: pointer; font-weight: 600;">📋 Copy Link</button>';
                        html += '</div>';
                        
                        html += '<p style="font-size: 12px; color: #666; text-align: center; margin: 10px 0;">Share the referral link on:</p>';
                        html += '<div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">';
                        html += '<button onclick="rtsShareOnPlatform(\'' + cleanBaseUrl + '\', \'facebook\')" style="padding: 6px 14px; background: #1877f2; color: #fff; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 600;">📘 Facebook</button>';
                        html += '<button onclick="rtsShareOnPlatform(\'' + cleanBaseUrl + '\', \'twitter\')" style="padding: 6px 14px; background: #000; color: #fff; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 600;">🐦 X</button>';
                        html += '<button onclick="rtsShareOnPlatform(\'' + cleanBaseUrl + '\', \'linkedin\')" style="padding: 6px 14px; background: #0a66c2; color: #fff; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 600;">💼 LinkedIn</button>';
                        html += '<button onclick="rtsShareOnPlatform(\'' + cleanBaseUrl + '\', \'whatsapp\')" style="padding: 6px 14px; background: #25D366; color: #fff; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 600;">📱 WhatsApp</button>';
                        html += '<button onclick="rtsShareOnPlatform(\'' + cleanBaseUrl + '\', \'telegram\')" style="padding: 6px 14px; background: #0088cc; color: #fff; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 600;">✈️ Telegram</button>';
                        html += '<button onclick="rtsShareOnPlatform(\'' + cleanBaseUrl + '\', \'reddit\')" style="padding: 6px 14px; background: #ff4500; color: #fff; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 600;">🤖 Reddit</button>';
                        html += '<button onclick="rtsShareOnPlatform(\'' + cleanBaseUrl + '\', \'email\')" style="padding: 6px 14px; background: #6c757d; color: #fff; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 600;">📧 Email</button>';
                        html += '</div>';
                        
                        html += '<div style="font-size: 11px; color: #999; text-align: center; margin-top: 12px; padding-top: 10px; border-top: 1px solid #dee2e6;">';
                        html += '📊 Each share button adds tracking to help us understand which platforms work best.<br>';
                        html += 'Your referral code <strong>' + data.referral_code + '</strong> works everywhere!';
                        html += '</div>';
                        html += '</div>';
                    }
                    
                    html += '<div style="text-align: center; margin-top: 20px;">';
                    html += '<a href="/captains-suite" style="display: inline-block; padding: 14px 40px; background: #1a7efb; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px;">Go to Captain\'s Suite →</a>';
                    html += '<p style="font-size: 12px; color: #999; margin-top: 10px;">Check your email for your personalized certificate and account details.</p>';
                    html += '</div>';
                    
                    html += '</div>';
                    html += '</div>';
                    
                    return html;
                }

                // --- SHARE FUNCTIONS ---
                function rtsCopyLinkWithTracking(baseUrl) {
                    var shareUrl = baseUrl + '&utm_source=direct&utm_medium=copy&utm_campaign=manual_share';
                    var btn = document.querySelector('.rts-share-btn.copy');
                    var originalText = btn ? btn.innerHTML : 'Copy Link';
                    var originalBg = btn ? btn.style.background : '';
                    
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(shareUrl).then(function() {
                            if (btn) {
                                btn.innerHTML = '✅ Copied!';
                                btn.style.background = '#28a745';
                                setTimeout(function() {
                                    btn.innerHTML = originalText;
                                    btn.style.background = originalBg;
                                }, 2000);
                            }
                            rtsTrackShareEvent('copy', 'manual');
                        }).catch(function() {
                            rtsFallbackCopy(shareUrl, btn, originalText, originalBg);
                        });
                    } else {
                        rtsFallbackCopy(shareUrl, btn, originalText, originalBg);
                    }
                }

                function rtsFallbackCopy(text, btnElement, originalText, originalBg) {
                    var input = document.createElement('input');
                    input.value = text;
                    document.body.appendChild(input);
                    input.select();
                    try {
                        document.execCommand('copy');
                        if (btnElement) {
                            btnElement.innerHTML = '✅ Copied!';
                            btnElement.style.background = '#28a745';
                            setTimeout(function() {
                                btnElement.innerHTML = originalText;
                                btnElement.style.background = originalBg;
                            }, 2000);
                        }
                        rtsTrackShareEvent('copy', 'manual');
                    } catch(e) {
                        console.error('Copy failed:', e);
                        if (btnElement) {
                            btnElement.innerHTML = '❌ Failed';
                            setTimeout(function() {
                                btnElement.innerHTML = originalText;
                                btnElement.style.background = originalBg;
                            }, 2000);
                        }
                    }
                    document.body.removeChild(input);
                }

                function rtsShareOnPlatform(baseUrl, platform) {
                    var shareUrl = baseUrl + '&utm_source=' + platform + '&utm_medium=social&utm_campaign=' + platform + '_share';
                    var message = "Join me as a Founding Runner with Run The Seas! 🏃 Get $100 credit when you register!";
                    
                    var shareUrls = {
                        'facebook': 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(shareUrl) + '&quote=' + encodeURIComponent(message),
                        'twitter': 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(message) + '&url=' + encodeURIComponent(shareUrl),
                        'linkedin': 'https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(shareUrl),
                        'whatsapp': 'https://api.whatsapp.com/send?text=' + encodeURIComponent(message + ' ' + shareUrl),
                        'telegram': 'https://t.me/share/url?url=' + encodeURIComponent(shareUrl) + '&text=' + encodeURIComponent(message),
                        'reddit': 'https://www.reddit.com/submit?url=' + encodeURIComponent(shareUrl) + '&title=' + encodeURIComponent("Join me as a Founding Runner with Run The Seas! 🏃"),
                        'email': 'mailto:?subject=' + encodeURIComponent("Join me as a Founding Runner!") + '&body=' + encodeURIComponent(message + "\n\n" + shareUrl)
                    };
                    
                    rtsTrackShareEvent('share', platform);
                    
                    if (shareUrls[platform]) {
                        var width = 600, height = 500;
                        var left = (screen.width - width) / 2;
                        var top = (screen.height - height) / 2;
                        window.open(shareUrls[platform], '_blank', 'width=' + width + ',height=' + height + ',left=' + left + ',top=' + top);
                    }
                }

                function rtsTrackShareEvent(action, platform) {
                    var linkInput = document.getElementById('rts-share-link');
                    var referralCode = '';
                    if (linkInput) {
                        var value = linkInput.value;
                        var match = value.match(/[?&]ref=([^&]+)/);
                        if (match) {
                            referralCode = match[1];
                        }
                    }
                    
                    console.log('📊 Share tracked:', action, platform, 'Ref:', referralCode);
                    
                    if (typeof rts_ajax !== 'undefined' && rts_ajax.ajax_url) {
                        jQuery.ajax({
                            type: 'POST',
                            url: rts_ajax.ajax_url,
                            data: {
                                action: 'rts_track_share',
                                share_action: action,
                                platform: platform,
                                referral_code: referralCode,
                                nonce: rts_ajax.nonce
                            },
                            dataType: 'json',
                            success: function(response) {
                                console.log('✅ Share tracking confirmed:', response);
                            },
                            error: function(xhr, status, error) {
                                console.error('❌ Share tracking failed:', error);
                            }
                        });
                    }
                }

                // Expose functions globally
                window.rtsBuildSuccessMessage = rtsBuildSuccessMessage;
                window.rtsCopyLinkWithTracking = rtsCopyLinkWithTracking;
                window.rtsShareOnPlatform = rtsShareOnPlatform;
                window.rtsTrackShareEvent = rtsTrackShareEvent;
                window.rtsFallbackCopy = rtsFallbackCopy;
                
                // --- FORM SUBMISSION HANDLER ---
                var $form = $('#rts-registration-form');
                var $submitBtn = $('.rts-submit-btn');
                var $status = $('#rts-registration-status');
                var $ageConsent = $('#age_consent');
                var $ageConsentError = $('#age_consent_error');
                var requiredFieldLabels = {
                    first_name: 'First name',
                    last_name: 'Last name',
                    email: 'Email address',
                    phone: 'Mobile phone',
                    address: 'Address',
                    city: 'City',
                    state: 'State / province',
                    zip: 'ZIP / postal code',
                    country: 'Country'
                };

                function showAgeConsentError() {
                    $ageConsent.addClass('error').attr('aria-invalid', 'true');
                    $ageConsentError.prop('hidden', false);
                }

                function clearAgeConsentError() {
                    $ageConsent.removeClass('error').removeAttr('aria-invalid');
                    $ageConsentError.prop('hidden', true);
                }

                function getFieldError($field) {
                    if ($field.attr('type') === 'checkbox') {
                        return $field.is(':checked')
                            ? ''
                            : 'Please confirm your age and agreement to the Terms & Conditions and Privacy Policy.';
                    }

                    var value = String($field.val() || '').trim();
                    var fieldName = $field.attr('name');
                    if (!value) {
                        return (requiredFieldLabels[fieldName] || 'This field') + ' is required.';
                    }

                    if ('email' === $field.attr('type') && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                        return 'Please enter a valid email address.';
                    }

                    return '';
                }

                function showFieldError($field, message) {
                    if ($field.is($ageConsent)) {
                        showAgeConsentError();
                        return;
                    }

                    var fieldName = $field.attr('name');
                    var errorId = 'rts_' + fieldName + '_error';
                    var $error = $('#' + errorId);
                    if (!$error.length) {
                        $error = $('<div>', {
                            id: errorId,
                            'class': 'rts-field-error',
                            role: 'alert',
                            'aria-live': 'polite'
                        });
                        $field.after($error);
                    }

                    $field.addClass('error')
                        .attr('aria-invalid', 'true')
                        .attr('aria-describedby', errorId);
                    $error.text(message).prop('hidden', false);
                }

                function clearFieldError($field) {
                    if ($field.is($ageConsent)) {
                        clearAgeConsentError();
                        return;
                    }

                    var errorId = 'rts_' + $field.attr('name') + '_error';
                    $field.removeClass('error')
                        .removeAttr('aria-invalid')
                        .removeAttr('aria-describedby');
                    $('#' + errorId).prop('hidden', true);
                }

                $form.on('input change', 'input[required], select[required]', function() {
                    var $field = $(this);
                    var message = getFieldError($field);
                    if (message) {
                        showFieldError($field, message);
                    } else {
                        clearFieldError($field);
                    }
                });
                
                $form.on('submit', function(e) {
                    e.preventDefault();
                    console.log('Form submission triggered');
                    
                    // Make sure tracking_id is in the hidden field
                    var trackingId = getTrackingId();
                    if (trackingId) {
                        $('#rts_tracking_id_field').val(trackingId);
                        console.log('RTS: Set tracking_id field to:', trackingId);
                    }
                    
                    var valid = true;
                    var firstError = null;
                    
                    $form.find('input[required], select[required]').each(function() {
                        var $field = $(this);
                        var message = getFieldError($field);

                        if (message) {
                            showFieldError($field, message);
                            valid = false;
                            if (!firstError) firstError = $field;
                        } else {
                            clearFieldError($field);
                        }
                    });
                    
                    if (!valid) {
                        if (firstError) {
                            firstError.focus();
                        }
                        $status.show().html('<div style="padding: 15px; background: #f8d7da; border-radius: 6px; color: #721c24; text-align: center; border: 1px solid #f5c6cb;">Please complete all highlighted required fields.</div>');
                        return false;
                    }
                    
                    $submitBtn.prop('disabled', true).html('Processing...');
                    $status.show().html('<div style="padding: 15px; background: #e3f2fd; border-radius: 6px; color: #1a7efb; text-align: center;">⏳ Creating your account and certificate...</div>');
                    
                    var formData = $form.serialize();
                    console.log('RTS: Submitting form data with tracking_id:', $('#rts_tracking_id_field').val());
                    console.log('RTS: Full form data:', formData);
                    
                    $.ajax({
                        type: 'POST',
                        url: rts_ajax.ajax_url,
                        data: formData,
                        dataType: 'json',
                        timeout: 30000,
                        success: function(response) {
                            console.log('Registration Response:', response);
                            
                            if (response.success) {
                                var data = response.data;
                                
                                if (data.is_existing_user) {
                                    console.log('RTS: Existing user, redirecting to:', data.redirect_url);
                                    window.location.href = data.redirect_url;
                                    return;
                                }
                                
                                var requestedCredit = $('input[name="request_cabin_credit"]:checked').val() === 'Yes';
                                var cleanBaseUrl = data.referral_link || (window.location.origin + '/survey?ref=' + data.referral_code);
                                
                                var message = rtsBuildSuccessMessage(data, requestedCredit, cleanBaseUrl);
                                $status.html(message);
                                $form.hide();
                                $submitBtn.hide();
                                
                            } else {
                                var errorData = response.data || 'Registration failed. Please try again.';
                                var errorMsg = errorData;
                                if (typeof errorData === 'object') {
                                    errorMsg = errorData.message || JSON.stringify(errorData);
                                    if (Array.isArray(errorData.fields)) {
                                        var firstServerError = null;
                                        errorData.fields.forEach(function(fieldName) {
                                            var $field = $form.find('[name="' + fieldName + '"]').first();
                                            if (!$field.length) return;
                                            showFieldError($field, getFieldError($field) || errorMsg);
                                            if (!firstServerError) firstServerError = $field;
                                        });
                                        if (firstServerError) firstServerError.trigger('focus');
                                    }
                                }
                                console.error('RTS: Registration error:', errorMsg);
                                $status.html('<div style="padding: 20px; background: #f8d7da; border-radius: 8px; color: #721c24; text-align: center; border: 1px solid #f5c6cb;">❌ ' + errorMsg + '</div>');
                                $submitBtn.prop('disabled', false).html('BECOME A FOUNDING RUNNER');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Registration AJAX Error:', error);
                            console.error('Status:', status);
                            console.error('Response Text:', xhr.responseText);
                            
                            var errorMsg = 'An error occurred. Please try again.';
                            if (xhr.responseText) {
                                try {
                                    var json = JSON.parse(xhr.responseText);
                                    if (json.data) {
                                        errorMsg = typeof json.data === 'string' ? json.data : (json.data.message || 'Server error');
                                    }
                                } catch(e) {
                                    console.error('Failed to parse JSON response:', e);
                                    errorMsg = 'Server error. Please check the console for details.';
                                }
                            }
                            $status.html('<div style="padding: 20px; background: #f8d7da; border-radius: 8px; color: #721c24; text-align: center; border: 1px solid #f5c6cb;">❌ ' + errorMsg + '</div>');
                            $submitBtn.prop('disabled', false).html('BECOME A FOUNDING RUNNER');
                        }
                    });
                    
                    return false;
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * AJAX: Check registration status
     */
    public function ajax_check_registration_status() {
        if (!isset($_POST['email'])) {
            wp_send_json_error('Email required');
            return;
        }
        
        $email = sanitize_email($_POST['email']);
        
        if (!$this->registration) {
            wp_send_json_success(array('is_registered' => false));
            return;
        }
        
        $participant = $this->registration->get_participant_by_email($email);
        
        if ($participant) {
            wp_send_json_success(array(
                'is_registered' => true,
                'first_name' => $participant->first_name,
                'last_name' => $participant->last_name,
                'cabin_credit_number' => $participant->cabin_credit_number,
                'cabin_credit_status' => $participant->cabin_credit_status,
                'captain_miles_balance' => $participant->captain_miles_balance,
                'referral_count' => $participant->referral_count
            ));
        } else {
            wp_send_json_success(array(
                'is_registered' => false
            ));
        }
    }
    
    /**
     * AJAX: Track share events
     */
    public function ajax_track_share() {
        check_ajax_referer('rts_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error('Authentication required.', 401);
        }
        
        $share_action = sanitize_text_field($_POST['share_action'] ?? '');
        $platform = sanitize_text_field($_POST['platform'] ?? '');
        $participant = $this->registration->get_participant_for_user(wp_get_current_user());
        if (!$participant) {
            wp_send_json_error('Participant not found.', 404);
        }
        $referral_code = sanitize_text_field((string) $participant->referral_code);
        
        // Log to database if tracking is available
        if ($this->tracking) {
            global $wpdb;
            
            // Check if activity logs table exists
            $log_table = $wpdb->prefix . 'rts_activity_logs';
            if ($wpdb->get_var("SHOW TABLES LIKE '$log_table'") == $log_table) {
                $wpdb->insert(
                    $log_table,
                    array(
                        'tracking_id' => 0,
                        'submission_id' => 'share_' . uniqid(),
                        'action' => 'share_' . $share_action,
                        'description' => "Share: $share_action on $platform",
                        'created_at' => current_time('mysql')
                    )
                );
            }
            
            // Also log to participants if we have the referral code
            if (!empty($referral_code)) {
                if ($participant) {
                    // Update referral count or track share event
                    $this->registration->log_timeline(
                        $participant->id,
                        'share_' . $share_action,
                        "Shared on $platform",
                        array('platform' => $platform, 'action' => $share_action)
                    );
                }
            }
        }
        
        wp_send_json_success(array(
            'message' => 'Share tracked successfully',
            'action' => $share_action,
            'platform' => $platform,
            'referral_code' => $referral_code
        ));
    }
    
    /**
     * Get survey data for pre-filling
     */
    private function get_survey_data($tracking_id, $form_id) {
        global $wpdb;
        
        $data = array();
        
        if ($tracking_id) {
            $answers = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT question_id, answer_value FROM {$wpdb->prefix}rts_survey_answers 
                    WHERE tracking_id = %d",
                    $tracking_id
                )
            );
            
            foreach ($answers as $answer) {
                $mapping = array(
                    'email' => 'email',
                    'first_name' => 'first_name',
                    'last_name' => 'last_name',
                    'phone' => 'phone',
                    'country' => 'country',
                    'city' => 'city',
                    'state' => 'state',
                    'province' => 'province',
                    'zip' => 'zip',
                    'postal_code' => 'postal_code',
                    'address' => 'address',
                    'address_2' => 'address_2',
                    'age_range' => 'age_range'
                );
                
                $field = $answer->question_id;
                if (isset($mapping[$field])) {
                    $data[$mapping[$field]] = $answer->answer_value;
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Render already registered message
     */
    private function render_already_registered($participant) {
        ob_start();
        ?>
        <div style="max-width: 600px; margin: 30px auto; padding: 40px; background: #f8f9fa; border-radius: 12px; text-align: center;">
            <div style="font-size: 48px; margin-bottom: 10px;">🎉</div>
            <h2 style="color: #28a745;">You're Already Registered!</h2>
            <p style="font-size: 18px; color: #333;">
                Welcome back, <strong><?php echo esc_html($participant->first_name); ?></strong>!
            </p>
            <div style="background: #fff; border-radius: 8px; padding: 20px; margin: 20px 0; text-align: left;">
                <p><strong>Cabin Credit:</strong> <?php echo $participant->cabin_credit_number ?: 'Pending'; ?></p>
                <p><strong>Status:</strong> <?php echo ucfirst($participant->cabin_credit_status); ?></p>
                <p><strong>Kilometres completed:</strong> <?php echo esc_html(rts_format_miles($participant->captain_miles_balance)); ?></p>
                <p><strong>Verified referrals:</strong> <?php echo esc_html($participant->successful_referrals); ?></p>
            </div>
            <a href="/captains-suite" style="display: inline-block; padding: 12px 30px; background: #1a7efb; color: #fff; text-decoration: none; border-radius: 6px;">
                Go to Captain's Suite →
            </a>
        </div>
        <?php
        return ob_get_clean();
    }
    
    
    
    /**
     * Get country options for dropdown
     */
    private function get_country_options($selected = '') {
        $countries = array(
            'US' => 'United States',
            'CA' => 'Canada',
            'GB' => 'United Kingdom',
            'AU' => 'Australia',
            'DE' => 'Germany',
            'FR' => 'France',
            'IN' => 'India',
            'JP' => 'Japan',
            'CN' => 'China',
            'BR' => 'Brazil',
            'MX' => 'Mexico',
            'ZA' => 'South Africa',
            'NG' => 'Nigeria',
            'EG' => 'Egypt',
            'AE' => 'United Arab Emirates'
        );
        
        $options = '';
        foreach ($countries as $code => $name) {
            $selected_attr = ($code === $selected) ? 'selected' : '';
            $options .= '<option value="' . esc_attr($code) . '" ' . $selected_attr . '>' . esc_html($name) . '</option>';
        }
        return $options;
    }

    /**
     * Render referral stats for user
     */
    public function render_referral_stats($atts) {
        if (!is_user_logged_in()) {
            return '<p>Please <a href="' . rts_get_member_login_url(get_permalink()) . '">login</a> to view your referral stats.</p>';
        }
        
        $user = wp_get_current_user();
        $participant = $this->registration->get_participant_for_user($user);
        
        if (!$participant) {
            return '<p>Please complete your registration.</p>';
        }
        
        global $wpdb;
        $referrals = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, p.first_name, p.last_name, p.email as referred_email 
                FROM {$wpdb->prefix}rts_referrals r
                LEFT JOIN {$wpdb->prefix}rts_participants p ON r.referred_participant_id = p.id
                WHERE r.referrer_id = %d
                ORDER BY r.referral_date DESC",
                $participant->id
            )
        );
        
        ob_start();
        ?>
        <div class="rts-referral-stats-user">
            <h3>🔗 Your Referral Stats</h3>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin: 15px 0;">
                <div style="background: #f8f9fa; padding: 12px; border-radius: 6px; text-align: center;">
                    <div style="font-size: 20px; font-weight: bold; color: #1a7efb;"><?php echo $participant->referral_count; ?></div>
                    <div style="font-size: 11px; color: #666;">Total Referrals</div>
                </div>
                <div style="background: #f8f9fa; padding: 12px; border-radius: 6px; text-align: center;">
                    <div style="font-size: 20px; font-weight: bold; color: #28a745;"><?php echo $participant->successful_referrals; ?></div>
                    <div style="font-size: 11px; color: #666;">Completed</div>
                </div>
                <div style="background: #f8f9fa; padding: 12px; border-radius: 6px; text-align: center;">
                    <div style="font-size: 20px; font-weight: bold; color: #1a7efb;"><?php echo rts_format_miles($participant->total_referral_bonus); ?></div>
                    <div style="font-size: 11px; color: #666;">Referral Kilometres</div>
                </div>
                <div style="background: #f8f9fa; padding: 12px; border-radius: 6px; text-align: center;">
                    <div style="font-size: 20px; font-weight: bold; color: #1a7efb;"><?php echo rts_format_miles($participant->total_captain_miles_earned); ?></div>
                    <div style="font-size: 11px; color: #666;">Kilometres Completed</div>
                </div>
            </div>
            
            <div style="background: #f0f7ff; padding: 12px 15px; border-radius: 6px; margin: 10px 0;">
                <p style="margin: 0; font-size: 13px;">
                    <strong>Your Referral Link:</strong><br>
                    <code style="word-break: break-all;"><?php echo home_url('/survey?ref=' . $participant->referral_code); ?></code>
                    <button onclick="copyReferralLink('<?php echo home_url('/survey?ref=' . $participant->referral_code); ?>')" style="margin-left: 10px; padding: 4px 12px; background: #1a7efb; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Copy</button>
                </p>
            </div>
            
            <?php if (!empty($referrals)): ?>
                <h4>Recent Referrals</h4>
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <thead>
                        <tr style="background: #f8f9fa;">
                            <th style="padding: 8px; text-align: left;">User</th>
                            <th style="padding: 8px; text-align: left;">Date</th>
                            <th style="padding: 8px; text-align: left;">Status</th>
                            <th style="padding: 8px; text-align: left;">Bonus</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($referrals, 0, 5) as $ref): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 8px;"><?php echo esc_html($ref->first_name . ' ' . $ref->last_name); ?></td>
                            <td style="padding: 8px;"><?php echo date('M j, Y', strtotime($ref->referral_date)); ?></td>
                            <td style="padding: 8px;">
                                <?php if ($ref->status == 'completed'): ?>
                                    <span style="color: #28a745;">✅ Completed</span>
                                <?php else: ?>
                                    <span style="color: #ffc107;">⏳ Pending</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 8px;"><?php echo rts_format_miles($ref->bonus_earned); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($referrals) > 5): ?>
                    <p><a href="/captains-suite/referrals" style="color: #1a7efb;">View all referrals →</a></p>
                <?php endif; ?>
            <?php else: ?>
                <p style="color: #666;">You haven't referred anyone yet. Share your referral link to start gaining verified referrals!</p>
            <?php endif; ?>
            
            <script>
            function copyReferralLink(url) {
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(url).then(function() {
                        alert('Referral link copied to clipboard!');
                    }).catch(function() {
                        fallbackCopy(url);
                    });
                } else {
                    fallbackCopy(url);
                }
            }
            
            function fallbackCopy(text) {
                var input = document.createElement('input');
                input.value = text;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
                alert('Referral link copied to clipboard!');
            }
            </script>
        </div>
        <?php
        return ob_get_clean();
    }

    
    /**
     * Generate and save QR code with logo for a participant
     */
    // public function generate_and_save_qr_code($participant_id, $referral_code) {
    //     global $wpdb;
        
    //     $base_url = home_url('/survey?ref=' . $referral_code);
    //     $upload_dir = wp_upload_dir();
    //     $qr_dir = $upload_dir['basedir'] . '/rts-qr-codes/';
    //     $qr_url_dir = $upload_dir['baseurl'] . '/rts-qr-codes/';
        
    //     // Create directory if it doesn't exist
    //     if (!file_exists($qr_dir)) {
    //         if (!wp_mkdir_p($qr_dir)) {
    //             error_log('RTS: Failed to create QR code directory');
    //             return false;
    //         }
    //     }
        
    //     // Add index.html to prevent directory listing
    //     if (!file_exists($qr_dir . 'index.html')) {
    //         file_put_contents($qr_dir . 'index.html', '');
    //     }
        
    //     $filename = 'qr_' . $participant_id . '_' . $referral_code . '.png';
    //     $filepath = $qr_dir . $filename;
    //     $fileurl = $qr_url_dir . $filename;
        
    //     // Get site logo URL
    //     $logo_url = get_site_icon_url(64);
    //     if (!$logo_url) {
    //         $custom_logo_id = get_theme_mod('custom_logo');
    //         if ($custom_logo_id) {
    //             $logo_url = wp_get_attachment_image_url($custom_logo_id, 'thumbnail');
    //         }
    //     }
    //     if (!$logo_url) {
    //         $logo_url = get_site_icon_url(64);
    //     }
    //     if (!$logo_url) {
    //         $logo_url = 'data:image/svg+xml,' . urlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="45" fill="#1a7efb"/><text x="50" y="65" font-size="40" text-anchor="middle" fill="white">R</text></svg>');
    //     }
        
    //     // Download logo to temp file
    //     $logo_temp = false;
    //     if (filter_var($logo_url, FILTER_VALIDATE_URL)) {
    //         $logo_temp = download_url($logo_url);
    //         if (is_wp_error($logo_temp)) {
    //             error_log('RTS: Failed to download logo: ' . $logo_temp->get_error_message());
    //             $logo_temp = false;
    //         }
    //     }
        
    //     // Generate QR code
    //     $qr_size = 300;
    //     $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $qr_size . 'x' . $qr_size . '&data=' . urlencode($base_url) . '&format=png';
        
    //     // Download QR code
    //     $qr_temp = download_url($qr_url);
    //     if (is_wp_error($qr_temp)) {
    //         error_log('RTS: Failed to download QR code: ' . $qr_temp->get_error_message());
    //         return false;
    //     }
        
    //     // Check if GD extension is available
    //     if (!extension_loaded('gd')) {
    //         error_log('RTS: GD extension not available');
    //         @unlink($qr_temp);
    //         return $qr_url;
    //     }
        
    //     // Create image from QR code
    //     $qr_image = imagecreatefrompng($qr_temp);
    //     if (!$qr_image) {
    //         error_log('RTS: Failed to create image from QR code');
    //         @unlink($qr_temp);
    //         return false;
    //     }
        
    //     // Get QR code dimensions
    //     $qr_width = imagesx($qr_image);
    //     $qr_height = imagesy($qr_image);
        
    //     // Create a new image with white background
    //     $final_image = imagecreatetruecolor($qr_width, $qr_height);
    //     $white = imagecolorallocate($final_image, 255, 255, 255);
    //     imagefill($final_image, 0, 0, $white);
        
    //     // Copy QR code onto final image
    //     imagecopy($final_image, $qr_image, 0, 0, 0, 0, $qr_width, $qr_height);
        
    //     // Add logo overlay if available
    //     if ($logo_temp && file_exists($logo_temp)) {
    //         $logo_data = file_get_contents($logo_temp);
    //         $logo_image = imagecreatefromstring($logo_data);
    //         if ($logo_image) {
    //             $logo_width = imagesx($logo_image);
    //             $logo_height = imagesy($logo_image);
                
    //             $target_size = 60;
    //             $logo_ratio = $logo_width / $logo_height;
    //             if ($logo_ratio > 1) {
    //                 $new_width = $target_size;
    //                 $new_height = $target_size / $logo_ratio;
    //             } else {
    //                 $new_width = $target_size * $logo_ratio;
    //                 $new_height = $target_size;
    //             }
                
    //             $resized_logo = imagecreatetruecolor($new_width, $new_height);
    //             $transparent = imagecolorallocatealpha($resized_logo, 0, 0, 0, 127);
    //             imagefill($resized_logo, 0, 0, $transparent);
    //             imagealphablending($resized_logo, true);
    //             imagesavealpha($resized_logo, true);
                
    //             imagecopyresampled($resized_logo, $logo_image, 0, 0, 0, 0, $new_width, $new_height, $logo_width, $logo_height);
                
    //             $x = ($qr_width - $new_width) / 2;
    //             $y = ($qr_height - $new_height) / 2;
                
    //             $circle_radius = 40;
    //             $circle_x = $qr_width / 2;
    //             $circle_y = $qr_height / 2;
    //             $circle_color = imagecolorallocate($final_image, 255, 255, 255);
    //             imagefilledellipse($final_image, $circle_x, $circle_y, $circle_radius * 2, $circle_radius * 2, $circle_color);
                
    //             $border_color = imagecolorallocate($final_image, 26, 126, 251);
    //             imageellipse($final_image, $circle_x, $circle_y, $circle_radius * 2, $circle_radius * 2, $border_color);
                
    //             imagecopy($final_image, $resized_logo, $x, $y, 0, 0, $new_width, $new_height);
                
    //             imagedestroy($logo_image);
    //             imagedestroy($resized_logo);
    //         }
    //         @unlink($logo_temp);
    //     }
        
    //     // Save final image
    //     imagepng($final_image, $filepath, 9);
    //     imagedestroy($qr_image);
    //     imagedestroy($final_image);
    //     @unlink($qr_temp);
        
    //     // Save QR code URL to participant record
    //     $updated = $wpdb->update(
    //         $wpdb->prefix . 'rts_participants',
    //         array('qr_code_url' => $fileurl),
    //         array('id' => $participant_id)
    //     );
        
    //     if ($updated !== false) {
    //         error_log('RTS: QR code saved for participant ' . $participant_id);
    //         return $fileurl;
    //     }
        
    //     error_log('RTS: Failed to save QR code URL for participant ' . $participant_id);
    //     return false;
    // }

    /**
     * Get QR code for a participant
     */
    // public function get_participant_qr_code($participant_id) {
    //     global $wpdb;
        
    //     $qr_url = $wpdb->get_var(
    //         $wpdb->prepare(
    //             "SELECT qr_code_url FROM {$wpdb->prefix}rts_participants WHERE id = %d",
    //             $participant_id
    //         )
    //     );
        
    //     if ($qr_url) {
    //         return $qr_url;
    //     }
        
    //     $participant = $this->registration->get_participant($participant_id);
    //     if ($participant && $participant->referral_code) {
    //         return $this->generate_and_save_qr_code($participant_id, $participant->referral_code);
    //     }
        
    //     return false;
    // }
}

