<?php

/**
 * Class RTS_Registration
 * Handles participant registration and Cabin Credit management
 */
class RTS_Registration
{

    private $db;
    private $tracking;
    private static $hooks_registered = false;

    public function __construct($tracking = null)
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->tracking = $tracking;

        // Several feature services use this class. Register its WordPress
        // callbacks once so constructing a helper cannot duplicate form work.
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;

        // Hook into Fluent Form submission for registration form
        add_action('fluentform/before_insert_submission', array($this, 'handle_registration_submission'), 10, 3);
        add_action('fluentform/after_submission', array($this, 'after_registration_submission'), 10, 3);

        // Email verification
        add_action('init', array($this, 'serve_certificate_preview_image'), 0);
        add_action('init', array($this, 'verify_email_handler'));

        // AJAX handlers
        add_action('wp_ajax_rts_get_participant_data', array($this, 'ajax_get_participant_data'));
    }

    /**
     * Handle registration form submission
     */
    public function handle_registration_submission($submission_data, $form_data, $form)
    {
        // Check if this is the registration form (Form ID 4 - adjust as needed)
        $registration_form_id = apply_filters('rts_registration_form_id', 4);
        if ($form->id != $registration_form_id) {
            return;
        }

        error_log("RTS: Processing registration submission for form: {$form->id}");

        // Extract form data
        $email = sanitize_email($submission_data['email'] ?? '');
        $first_name = sanitize_text_field($submission_data['first_name'] ?? '');
        $last_name = sanitize_text_field($submission_data['last_name'] ?? '');
        $request_cabin_credit = isset($submission_data['request_cabin_credit']) &&
            $submission_data['request_cabin_credit'] === 'Yes';
        $age_consent = isset($submission_data['age_consent']) &&
            'true' === sanitize_text_field(wp_unslash($submission_data['age_consent']));

        if (empty($email) || empty($first_name) || empty($last_name)) {
            error_log("RTS: Missing required fields for registration");
            return;
        }

        if (!$age_consent) {
            error_log('RTS: Fluent Forms registration rejected because age/legal consent was not confirmed');
            return;
        }

        // Legacy Fluent Forms registration must satisfy the same survey
        // ownership check as the dedicated registration endpoint.
        $tracking_id = absint($submission_data['tracking_id'] ?? ($_POST['tracking_id'] ?? ($_COOKIE['rts_tracking_id'] ?? 0)));
        $tracking_token = sanitize_text_field(wp_unslash(
            $submission_data['tracking_token'] ?? ($_POST['tracking_token'] ?? ($_COOKIE['rts_tracking_token'] ?? ''))
        ));
        if (!rts_verify_tracking_access($tracking_id, $tracking_token)) {
            error_log('RTS: Fluent Forms registration rejected because survey ownership could not be verified');
            return;
        }

        $tracking = $this->db->get_row($this->db->prepare(
            "SELECT email, completion_status FROM {$this->db->prefix}rts_survey_tracking WHERE id = %d",
            $tracking_id
        ));
        if (
            !$tracking ||
            'completed' !== $tracking->completion_status ||
            (!empty($tracking->email) && 0 !== strcasecmp($email, sanitize_email($tracking->email)))
        ) {
            error_log('RTS: Fluent Forms registration rejected because the completed survey did not match');
            return;
        }
        $submission_data['tracking_id'] = $tracking_id;

        // Check if participant already exists
        $existing = $this->get_participant_by_email($email);

        if ($existing) {
            // Update existing participant
            $participant_id = $this->update_participant($existing->id, $submission_data);
            $this->log_timeline($participant_id, 'registration_update', 'Participant updated registration information');
        } else {
            // Create new participant
            $participant_id = $this->create_participant(
                $submission_data,
                $request_cabin_credit,
                null,
                $age_consent,
                $this->get_request_ip_address()
            );

            // REMOVED: send_verification_email is now handled by background processor
            // if ($participant_id) {
            //     $this->send_verification_email($participant_id);
            //     $this->log_timeline($participant_id, 'registration_created', 'New registration created - Email verification sent');
            // }

            // Just log the creation without email
            if ($participant_id) {
                $this->log_timeline($participant_id, 'registration_created', 'New registration created - Email will be sent by background processor');
            }
        }

        // If cabin credit requested, handle the request
        if ($request_cabin_credit && $participant_id) {
            $this->handle_cabin_credit_request($participant_id, $submission_data);
        }

        // Store in session for later use
        if ($participant_id) {
            if (!session_id()) {
                session_start();
            }
            $_SESSION['rts_participant_id'] = $participant_id;
            $_SESSION['rts_participant_email'] = $email;
        }
    }


    /**
     * Check if user has a completed survey but no registration
     */
    public function get_completed_survey_without_registration($email = null)
    {
        global $wpdb;

        // A survey may be resumed only by its essential access cookie; an
        // email address or numeric ID by itself is never sufficient.
        $tracking_id = isset($_COOKIE['rts_tracking_id']) ? absint($_COOKIE['rts_tracking_id']) : 0;
        $tracking_token = sanitize_text_field(wp_unslash($_COOKIE['rts_tracking_token'] ?? ''));
        if ($tracking_id && rts_verify_tracking_access($tracking_id, $tracking_token)) {
            $tracking = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}rts_survey_tracking 
                    WHERE id = %d AND completion_status = 'completed' 
                    AND id NOT IN (SELECT survey_tracking_id FROM {$wpdb->prefix}rts_participants WHERE survey_tracking_id IS NOT NULL)",
                    $tracking_id
                )
            );
            if ($tracking && (!$email || 0 === strcasecmp(sanitize_email($email), sanitize_email($tracking->email)))) {
                return $tracking;
            }
        }

        return null;
    }

    /**
     * After registration submission - send confirmation
     */
    public function after_registration_submission($submission_data, $form_data, $form)
    {
        $registration_form_id = apply_filters('rts_registration_form_id', 4);
        if ($form->id != $registration_form_id) {
            return;
        }

        // Send confirmation email
        $email = sanitize_email($submission_data['email'] ?? '');
        if ($email) {
            $this->send_confirmation_email($email, $submission_data);
        }
    }

    /**
     * Create a new participant
     */
    public function create_participant($data, $request_cabin_credit, $user_id = null, $age_consent = false, $age_consent_ip_address = '')
    {
        // Defense in depth for every code path that can create a Founding Runner.
        if (true !== $age_consent) {
            error_log('RTS: Participant creation rejected because age/legal consent was not confirmed');
            return false;
        }

        $age_consent_ip_address = filter_var($age_consent_ip_address, FILTER_VALIDATE_IP)
            ? $age_consent_ip_address
            : '0.0.0.0';
        $age_consent_confirmed_at = current_time('mysql');

        $email = sanitize_email($data['email'] ?? '');
        $first_name = sanitize_text_field($data['first_name'] ?? '');
        $last_name = sanitize_text_field($data['last_name'] ?? '');
        $phone = sanitize_text_field($data['phone'] ?? '');
        $registration_country = strtoupper(sanitize_text_field($data['country'] ?? $data['registration_country'] ?? ''));
        $detected_country = '';
        $city = sanitize_text_field($data['city'] ?? '');
        $province = sanitize_text_field($data['state'] ?? $data['province'] ?? '');
        $postal_code = sanitize_text_field($data['zip'] ?? $data['postal_code'] ?? '');
        $address = sanitize_textarea_field($data['address'] ?? '');
        $address_2 = sanitize_text_field($data['address_2'] ?? '');
        $date_of_birth = sanitize_text_field($data['date_of_birth'] ?? '');
        $gender = sanitize_text_field($data['gender'] ?? '');
        $age_range = sanitize_text_field($data['age_range'] ?? '');
        $emergency_contact_name = sanitize_text_field($data['emergency_contact_name'] ?? '');
        $emergency_contact_phone = sanitize_text_field($data['emergency_contact_phone'] ?? '');
        $marketing_consent = !empty($data['marketing_consent']) ? 1 : 0;

        // Keep this validation at the data-write boundary as well as the AJAX
        // endpoint so imports, hooks, or future callers cannot bypass it.
        $required_fields = array(
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
            'city' => $city,
            'state' => $province,
            'zip' => $postal_code,
            'country' => $registration_country,
        );
        $missing_fields = array();

        foreach ($required_fields as $field_name => $field_value) {
            if ('' === trim((string) $field_value)) {
                $missing_fields[] = $field_name;
            }
        }

        if ($missing_fields) {
            error_log('RTS: Participant creation rejected; missing required fields: ' . implode(', ', $missing_fields));
            return false;
        }

        if (!is_email($email)) {
            error_log('RTS: Participant creation rejected because the email address is invalid');
            return false;
        }

        if (!preg_match('/^[A-Z]{2}$/', $registration_country)) {
            error_log('RTS: Participant creation rejected because the registration country is invalid');
            return false;
        }

        // International postal codes may contain letters, spaces, and hyphens.
        if (strlen($postal_code) > 30) {
            error_log('RTS: Participant creation rejected because the postal code is too long');
            return false;
        }

        // Check if there's a referral code in the data
        $referral_code = sanitize_text_field($data['referral_code_input'] ?? '');
        $referred_by = null;
        $tracking_id = isset($data['tracking_id']) ? intval($data['tracking_id']) : 0;
        $travel_party_size = $this->get_travel_party_size_from_survey($tracking_id, $data);

        $tracking = $tracking_id > 0 ? $this->db->get_row(
            $this->db->prepare(
                "SELECT referral_code, referrer_participant_id, country FROM {$this->db->prefix}rts_survey_tracking WHERE id = %d",
                $tracking_id
            )
        ) : null;
        if ($tracking && !empty($tracking->country)) {
            $detected_country = sanitize_text_field($tracking->country);
        }
        // Public surfaces and reporting use the country detected during the
        // survey, falling back to the country entered during registration.
        $country = $detected_country ?: $registration_country;

        // If no referral code provided, check if we have one from tracking
        if (empty($referral_code) && $tracking) {
            if ($tracking && !empty($tracking->referral_code)) {
                $referral_code = $tracking->referral_code;
                if (!empty($tracking->referrer_participant_id)) {
                    $referred_by = $tracking->referrer_participant_id;
                    error_log('RTS: Referrer ID from tracking: ' . $referred_by);
                }
            }
        }

        // If still no referrer, look up by referral code
        if (empty($referred_by) && !empty($referral_code)) {
            $referrer = $this->get_participant_by_referral_code($referral_code);
            if ($referrer) {
                $referred_by = $referrer->id;
                error_log('RTS: New user referred by participant: ' . $referred_by);
            }
        }

        // Generate unique referral code for this user
        $new_referral_code = $this->generate_referral_code($first_name, $last_name);

        // Generate verification token
        $verification_token = bin2hex(random_bytes(32));

        // Every completed registration becomes eligible on email verification.
        // Benefits are not issued until verification; a certificate number is
        // reserved only when the verification email is prepared.
        $cabin_credit_number = null;

        $participant_data = array(
            'user_id' => $user_id,
            'survey_tracking_id' => $tracking_id,
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'phone' => $phone,
            'country' => $country,
            'registration_country' => $registration_country,
            'detected_country' => $detected_country,
            'city' => $city,
            'province' => $province,
            'registration_province' => $province,
            'postal_code' => $postal_code,
            'address' => $address,
            'address_2' => $address_2,
            'date_of_birth' => $date_of_birth,
            'gender' => $gender,
            'age_range' => $age_range,
            'emergency_contact_name' => $emergency_contact_name,
            'emergency_contact_phone' => $emergency_contact_phone,
            'marketing_consent' => $marketing_consent,
            'age_consent_confirmed_at' => $age_consent_confirmed_at,
            'age_consent_ip_address' => $age_consent_ip_address,
            'registration_date' => current_time('mysql'),
            'email_verified' => 0,
            'email_verification_token' => $verification_token,
            'verification_token' => $verification_token,
            'cabin_credit_requested' => 1,
            'cabin_credit_number' => $cabin_credit_number,
            'cabin_credit_status' => 'pending',
            'cabin_credit_amount' => 100.00,
            'captain_suite_status' => 'inactive',
            'captain_referral_participation' => isset($data['referral_race_participation']) && $data['referral_race_participation'] === 'Yes' ? 'registered' : 'not_started',
            'captain_miles_balance' => 0,
            'total_captain_miles_earned' => 0,
            'total_captain_miles_used' => 0,
            'referral_code' => $new_referral_code,
            'referred_by' => $referred_by,
            'referral_count' => 0,
            'successful_referrals' => 0,
            'total_referral_bonus' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );
        if (null !== $travel_party_size) {
            $participant_data['travel_party_size'] = $travel_party_size;
        }

        $inserted = $this->db->insert(
            $this->db->prefix . 'rts_participants',
            $participant_data
        );

        if ($inserted === false) {
            error_log("RTS: Failed to create participant: " . $this->db->last_error);
            return false;
        }

        $participant_id = $this->db->insert_id;
        error_log("RTS: Created participant ID: {$participant_id}");
        $this->sync_participant_user_meta($participant_id);

        // If referred by someone, add to referrals table (ONLY if not exists)
        if ($referred_by && $participant_id) {
            // CRITICAL: Check if referral already exists for this email
            $existing_referral = $this->db->get_var(
                $this->db->prepare(
                    "SELECT id FROM {$this->db->prefix}rts_referrals 
                    WHERE referrer_id = %d AND referred_email = %s",
                    $referred_by,
                    $email
                )
            );

            if (!$existing_referral) {
                // Insert new referral
                $this->db->insert(
                    $this->db->prefix . 'rts_referrals',
                    array(
                        'referrer_id' => $referred_by,
                        'referred_email' => $email,
                        'referred_participant_id' => $participant_id,
                        'referral_code' => $referral_code,
                        'referral_source' => 'registration',
                        'status' => 'pending',
                        'bonus_earned' => 0,
                        'referral_date' => current_time('mysql'),
                        'created_at' => current_time('mysql')
                    )
                );
                error_log('RTS: Created referral record for participant ' . $participant_id . ' referred by ' . $referred_by);
            } else {
                // Update existing referral with participant ID if missing
                $this->db->update(
                    $this->db->prefix . 'rts_referrals',
                    array(
                        'referred_participant_id' => $participant_id
                    ),
                    array('id' => $existing_referral)
                );
                error_log('RTS: Updated existing referral with participant ID ' . $participant_id);
            }
        }

        // Add initial achievements
        $this->add_achievement($participant_id, 'registration', 'Welcome Aboard!', 'Thank you for registering as a Founding Runner!');

        // Add timeline entry
        $this->log_timeline($participant_id, 'registration_created', 'Participant registered successfully');

        return $participant_id;
    }

    /**
     * Return the validated submitting IP address used for consent evidence.
     */
    public function get_request_ip_address()
    {
        if ($this->tracking && is_callable(array($this->tracking, 'get_real_ip'))) {
            $ip_address = $this->tracking->get_real_ip();
        } else {
            $ip_address = isset($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : '';
        }

        return filter_var($ip_address, FILTER_VALIDATE_IP) ? $ip_address : '0.0.0.0';
    }

    public function get_participant_by_referral_code($referral_code)
    {
        return $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}rts_participants WHERE referral_code = %s",
                $referral_code
            )
        );
    }

    /**
     * Generate referral link
     */
    public function get_referral_link($participant_id)
    {
        $participant = $this->get_participant($participant_id);
        if (!$participant || empty($participant->referral_code)) {
            return '';
        }
        return home_url('/?ref=' . $participant->referral_code);
    }

    /**
     * Update existing participant
     */
    private function update_participant($participant_id, $data)
    {
        $registration_country = sanitize_text_field($data['country'] ?? $data['registration_country'] ?? '');
        $tracking_id = isset($data['tracking_id']) ? absint($data['tracking_id']) : 0;
        $detected_country = $this->get_detected_country($tracking_id);
        $update_data = array(
            'first_name' => sanitize_text_field($data['first_name'] ?? ''),
            'last_name' => sanitize_text_field($data['last_name'] ?? ''),
            'phone' => sanitize_text_field($data['phone'] ?? ''),
            'country' => $detected_country ?: $registration_country,
            'registration_country' => $registration_country,
            'detected_country' => $detected_country,
            'city' => sanitize_text_field($data['city'] ?? ''),
            'province' => sanitize_text_field($data['state'] ?? $data['province'] ?? ''),
            'registration_province' => sanitize_text_field($data['state'] ?? $data['registration_province'] ?? $data['province'] ?? ''),
            'postal_code' => sanitize_text_field($data['zip'] ?? $data['postal_code'] ?? ''),
            'address' => sanitize_textarea_field($data['address'] ?? ''),
            'address_2' => sanitize_text_field($data['address_2'] ?? ''),
            'date_of_birth' => sanitize_text_field($data['date_of_birth'] ?? ''),
            'gender' => sanitize_text_field($data['gender'] ?? ''),
            'age_range' => sanitize_text_field($data['age_range'] ?? ''),
            'emergency_contact_name' => sanitize_text_field($data['emergency_contact_name'] ?? ''),
            'emergency_contact_phone' => sanitize_text_field($data['emergency_contact_phone'] ?? ''),
            'marketing_consent' => !empty($data['marketing_consent']) ? 1 : 0,
            'updated_at' => current_time('mysql')
        );

        $travel_party_size = $this->get_travel_party_size_from_survey($tracking_id, $data);
        if (null !== $travel_party_size) {
            $update_data['travel_party_size'] = $travel_party_size;
        }

        // Only update cabin credit request if it's explicitly set
        if (isset($data['request_cabin_credit'])) {
            $request_cabin_credit = $data['request_cabin_credit'] === 'Yes';
            $update_data['cabin_credit_requested'] = $request_cabin_credit ? 1 : 0;
            if ($request_cabin_credit && $update_data['cabin_credit_status'] === 'not_requested') {
                $update_data['cabin_credit_status'] = 'pending';
            }
        }

        $updated = $this->db->update(
            $this->db->prefix . 'rts_participants',
            $update_data,
            array('id' => $participant_id)
        );

        if ($updated !== false) {
            $this->sync_participant_user_meta($participant_id);
            error_log("RTS: Updated participant ID: {$participant_id}");
            $this->log_timeline($participant_id, 'registration_update', 'Participant information updated');
        }

        return $participant_id;
    }

    /**
     * Copy registration-owned participant fields to WordPress user meta.
     *
     * The participant table remains authoritative; these keys make the same
     * values available to themes, membership plugins and WordPress APIs.
     */
    public function sync_participant_user_meta($participant_id)
    {
        $participant_id = absint($participant_id);
        if (!$participant_id) {
            return false;
        }

        $participant = $this->get_participant($participant_id);
        if (!$participant) {
            return false;
        }

        $user_id = !empty($participant->user_id) ? absint($participant->user_id) : 0;
        if (!$user_id && !empty($participant->email)) {
            $user = get_user_by('email', $participant->email);
            $user_id = $user ? absint($user->ID) : 0;
            if ($user_id) {
                $this->db->update(
                    $this->db->prefix . 'rts_participants',
                    array('user_id' => $user_id),
                    array('id' => $participant_id),
                    array('%d'),
                    array('%d')
                );
            }
        }
        if (!$user_id) {
            return false;
        }

        $meta = array(
            'rts_phone' => $participant->phone ?? '',
            'rts_address' => $participant->address ?? '',
            'rts_address_2' => $participant->address_2 ?? '',
            'rts_city' => $participant->city ?? '',
            'rts_province' => $participant->registration_province ?? '',
            'rts_postal_code' => $participant->postal_code ?? '',
            'rts_registration_country' => $participant->registration_country ?? '',
            'rts_detected_country' => $participant->detected_country ?? '',
            'rts_country' => $participant->country ?? $participant->registration_country ?? '',
            'rts_gender' => $participant->gender ?? '',
            'rts_age_range' => $participant->age_range ?? '',
            'rts_marketing_consent' => !empty($participant->marketing_consent) ? '1' : '0',
            'rts_age_consent_confirmed_at' => $participant->age_consent_confirmed_at ?? '',
        );
        foreach ($meta as $key => $value) {
            update_user_meta($user_id, $key, sanitize_text_field((string) $value));
        }

        return true;
    }

    /** Return the country detected for a survey session, if available. */
    private function get_detected_country($tracking_id)
    {
        if (!$tracking_id) {
            return '';
        }

        return sanitize_text_field((string) $this->db->get_var(
            $this->db->prepare(
                "SELECT country FROM {$this->db->prefix}rts_survey_tracking WHERE id = %d",
                $tracking_id
            )
        ));
    }

    /** Apply survey-detected country priority when linking an existing account. */
    public function sync_location_from_tracking($participant_id, $tracking_id)
    {
        $participant_id = absint($participant_id);
        $tracking_id = absint($tracking_id);
        $detected_country = $this->get_detected_country($tracking_id);
        if (!$participant_id || !$tracking_id || '' === $detected_country) {
            return false;
        }

        $participant = $this->db->get_row($this->db->prepare(
            "SELECT user_id, country_verified FROM {$this->db->prefix}rts_participants WHERE id = %d",
            $participant_id
        ));
        $update_data = array(
            'survey_tracking_id' => $tracking_id,
            'detected_country' => $detected_country,
            'updated_at' => current_time('mysql'),
        );
        if (!$participant || empty($participant->country_verified)) {
            $update_data['country'] = $detected_country;
        }

        $updated = false !== $this->db->update(
            $this->db->prefix . 'rts_participants',
            $update_data,
            array('id' => $participant_id)
        );
        if ($updated && $participant && !empty($participant->user_id)) {
            update_user_meta((int) $participant->user_id, 'rts_detected_country', $detected_country);
            if (empty($participant->country_verified)) {
                update_user_meta((int) $participant->user_id, 'rts_country', $detected_country);
            }
        }

        return $updated;
    }

    /**
     * Convert survey questions 10/10a into the integer participant party size.
     *
     * The Fluent Forms export identifies s12_radio as the cabin-composition
     * question and dropdown as its family-only follow-up. The database column
     * is an integer because the admin dashboards calculate an average, so the
     * displayed "6+" answer is stored as its numeric lower bound, 6.
     */
    public function get_travel_party_size_from_survey($tracking_id = 0, $data = array())
    {
        $data = is_array($data) ? $data : array();
        $cabin_answer = isset($data['s12_radio'])
            ? $this->normalize_survey_answer_value($data['s12_radio'])
            : '';
        $family_size_answer = isset($data['dropdown'])
            ? $this->normalize_survey_answer_value($data['dropdown'])
            : '';

        if ($tracking_id > 0 && ('' === $cabin_answer || '' === $family_size_answer)) {
            $answer_rows = $this->db->get_results(
                $this->db->prepare(
                    "SELECT question_id, answer_value
                    FROM {$this->db->prefix}rts_survey_answers
                    WHERE tracking_id = %d
                      AND question_id IN ('s12_radio', 'dropdown')
                    ORDER BY answered_at DESC, id DESC",
                    absint($tracking_id)
                )
            );

            foreach ((array) $answer_rows as $answer_row) {
                $question_id = sanitize_key((string) ($answer_row->question_id ?? ''));
                if ('s12_radio' === $question_id && '' === $cabin_answer) {
                    $cabin_answer = $this->normalize_survey_answer_value($answer_row->answer_value ?? '');
                } elseif ('dropdown' === $question_id && '' === $family_size_answer) {
                    $family_size_answer = $this->normalize_survey_answer_value($answer_row->answer_value ?? '');
                }
            }
        }

        $two_person_answers = array(
            'Two runners',
            'One runner and one walker',
            'One runner and one non-participant',
            'Two walkers or non-runners',
        );
        if (in_array($cabin_answer, $two_person_answers, true)) {
            return 2;
        }

        if (in_array($cabin_answer, array('Solo traveller', 'Unsure'), true)) {
            return 1;
        }

        if ('Family with a mixture of runners and non-runners' === $cabin_answer) {
            $family_size = absint($family_size_answer);
            return $family_size >= 2 && $family_size <= 6 ? $family_size : null;
        }

        return null;
    }

    /** Update an existing participant after linking a completed survey. */
    public function sync_travel_party_size($participant_id, $tracking_id)
    {
        $travel_party_size = $this->get_travel_party_size_from_survey(absint($tracking_id));
        if (null === $travel_party_size) {
            return false;
        }

        return false !== $this->db->update(
            $this->db->prefix . 'rts_participants',
            array(
                'travel_party_size' => $travel_party_size,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => absint($participant_id)),
            array('%d', '%s'),
            array('%d')
        );
    }

    /** Normalize plain or JSON-encoded answer values stored by survey tracking. */
    private function normalize_survey_answer_value($value)
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        $value = trim(sanitize_text_field(wp_unslash((string) $value)));
        if ('' === $value) {
            return '';
        }

        $decoded = json_decode($value, true);
        if (JSON_ERROR_NONE === json_last_error()) {
            if (is_array($decoded)) {
                $decoded = reset($decoded);
            }
            if (is_scalar($decoded)) {
                $value = trim(sanitize_text_field((string) $decoded));
            }
        }

        return $value;
    }


    /**
     * Handle Cabin Credit request
     */
    public function handle_cabin_credit_request($participant_id, $data)
    {
        // Get current participant data
        $participant = $this->get_participant($participant_id);
        if (!$participant) {
            return false;
        }

        $update_data = array(
            'cabin_credit_status' => 'pending',
            'cabin_credit_requested' => 1,
            'cabin_credit_amount' => 100.00
        );

        // Handle Captain's Suite request
        if (isset($data['captain_suite_request']) && $data['captain_suite_request'] === 'Yes') {
            $update_data['captain_suite_status'] = 'pending';
        }

        // Handle participation
        if (isset($data['referral_race_participation']) && $data['referral_race_participation'] === 'Yes') {
            $update_data['captain_referral_participation'] = 'registered';
        }

        // Handle referral code - ONLY if not already processed
        if (!empty($data['referral_code_input'])) {
            $referral_code = sanitize_text_field($data['referral_code_input']);

            // Check if this participant already has a referral
            $has_referral = $this->db->get_var(
                $this->db->prepare(
                    "SELECT id FROM {$this->db->prefix}rts_referrals 
                    WHERE referred_participant_id = %d OR referred_email = %s",
                    $participant_id,
                    $participant->email
                )
            );

            // Only process if no referral exists yet
            if (!$has_referral) {
                $this->process_referral($participant_id, $referral_code);
            } else {
                error_log("RTS: Referral already exists for participant {$participant_id}, skipping");
            }
        }

        $updated = $this->db->update(
            $this->db->prefix . 'rts_participants',
            $update_data,
            array('id' => $participant_id)
        );

        if ($updated !== false) {
            error_log("RTS: Cabin Credit eligibility recorded for participant {$participant_id}");

            // Add achievement (check if already exists)
            $achievement_exists = $this->db->get_var(
                $this->db->prepare(
                    "SELECT id FROM {$this->db->prefix}rts_achievements 
                    WHERE participant_id = %d AND achievement_type = 'cabin_credit_request'",
                    $participant_id
                )
            );

            if (!$achievement_exists) {
                $this->add_achievement(
                    $participant_id,
                    'cabin_credit_request',
                    'Cabin Credit Requested',
                    'Your $100 Founding Runner Cabin Credit will be issued after email verification.'
                );
            }

            // Log timeline
            $this->log_timeline(
                $participant_id,
                'cabin_credit_requested',
                'Cabin Credit eligibility recorded - status: pending email verification'
            );
        }

        return $updated !== false;
    }

    /**
     * Generate unique cabin credit number
     */
    private function generate_cabin_credit_number()
    {
        $prefix = 'RTS';
        $year = date('Y');
        $random = strtoupper(substr(uniqid(), -6));
        $number = $prefix . $year . '-' . $random;

        // Check if number exists
        $exists = $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM {$this->db->prefix}rts_participants WHERE cabin_credit_number = %s",
                $number
            )
        );

        if ($exists) {
            return $this->generate_cabin_credit_number();
        }

        return $number;
    }

    /**
     * Return duplicate information when this participant's survey response
     * requires an administrator to approve its benefits. A matching session is
     * only a duplicate within the same survey form; the email may be different.
     */
    private function get_duplicate_benefit_hold($participant)
    {
        if (empty($participant->survey_tracking_id)) {
            return false;
        }

        $tracking_table = $this->db->prefix . 'rts_survey_tracking';
        $tracking = $this->db->get_row($this->db->prepare(
            "SELECT id, submission_id, session_id, form_id, started_at, is_duplicate, duplicate_of
             FROM $tracking_table WHERE id = %d",
            $participant->survey_tracking_id
        ));
        if (!$tracking) {
            return false;
        }

        // Respect a duplicate flag already made by the survey-completion flow.
        if ((int) $tracking->is_duplicate === 1) {
            return $tracking;
        }

        // Cover registrations created before duplicate cleanup completed. The
        // oldest submission in the same form/session is the canonical one;
        // later registrations are held even when they use another email.
        if (empty($tracking->session_id) || empty($tracking->form_id)) {
            return false;
        }
        $canonical = $this->db->get_row($this->db->prepare(
            "SELECT id, submission_id FROM $tracking_table
             WHERE form_id = %d AND session_id = %s
             ORDER BY started_at ASC, id ASC LIMIT 1",
            $tracking->form_id,
            $tracking->session_id
        ));
        if (!$canonical || (int) $canonical->id === (int) $tracking->id) {
            return false;
        }

        $this->db->update(
            $tracking_table,
            array('is_duplicate' => 1, 'duplicate_of' => $canonical->submission_id),
            array('id' => $tracking->id),
            array('%d', '%s'),
            array('%d')
        );
        $tracking->is_duplicate = 1;
        $tracking->duplicate_of = $canonical->submission_id;
        return $tracking;
    }

    /**
     * Issue the verified-registration benefits exactly once. The status fields
     * remain the source of truth; timestamps and actor IDs make the decision
     * visible in Participant Verification & Account Operations.
     */
    public function activate_verified_benefits($participant_id, $actor_id = 0, $send_certificate = true)
    {
        $participant = $this->get_participant($participant_id);
        if (!$participant) {
            return new WP_Error('rts_participant_not_found', __('Participant not found.', 'run-the-seas'));
        }
        if ((int) $participant->email_verified !== 1) {
            return new WP_Error('rts_email_not_verified', __('Verify the participant email before issuing benefits.', 'run-the-seas'));
        }

        $duplicate_hold = $this->get_duplicate_benefit_hold($participant);
        if ($duplicate_hold && !(int) $actor_id) {
            $already_logged = $this->db->get_var($this->db->prepare(
                "SELECT id FROM {$this->db->prefix}rts_timeline
                 WHERE participant_id = %d AND activity_type = 'duplicate_benefits_pending' LIMIT 1",
                $participant_id
            ));
            if (!$already_logged) {
                $this->log_timeline(
                    $participant_id,
                    'duplicate_benefits_pending',
                    'Benefits held for administrator approval because this survey response shares a form and session with an earlier registration.',
                    array('tracking_id' => (int) $duplicate_hold->id, 'session_id' => $duplicate_hold->session_id, 'duplicate_of' => $duplicate_hold->duplicate_of)
                );
            }
            return new WP_Error('rts_duplicate_review_required', __('This verified registration is a suspected duplicate. Cabin Credit and Captain\'s Suite activation require administrator approval.', 'run-the-seas'));
        }

        $now = current_time('mysql');
        $certificate_number = !empty($participant->certificate_number)
            ? $participant->certificate_number
            : $this->generate_certificate_number();
        // For new issuances, the certificate number is also the permanent
        // cruise-credit reference. Preserve historical references already on
        // older records instead of silently renumbering them.
        $credit_number = $participant->cabin_credit_number ?: $certificate_number;
        $credit_was_issued = $participant->cabin_credit_status === 'approved';
        $suite_was_active = $participant->captain_suite_status === 'active';

        $updated = $this->db->update(
            $this->db->prefix . 'rts_participants',
            array(
                'cabin_credit_requested' => 1,
                'cabin_credit_number' => $credit_number,
                'cabin_credit_status' => 'approved',
                'cabin_credit_amount' => 100.00,
                'cabin_credit_approved_date' => $participant->cabin_credit_approved_date ?: $now,
                'cabin_credit_issued_at' => $participant->cabin_credit_issued_at ?: $now,
                'cabin_credit_issued_by' => $participant->cabin_credit_issued_by ?: $actor_id,
                'captain_suite_status' => 'active',
                'captain_suite_activated_at' => $participant->captain_suite_activated_at ?: $now,
                'captain_suite_activated_by' => $participant->captain_suite_activated_by ?: $actor_id,
                'certificate_number' => $certificate_number,
                'certificate_issued_at' => $participant->certificate_issued_at ?: $now,
                'updated_at' => $now,
            ),
            array('id' => $participant_id)
        );

        if ($updated === false) {
            return new WP_Error('rts_benefits_not_issued', __('The participant benefits could not be issued.', 'run-the-seas'));
        }

        if (!$credit_was_issued) {
            $this->log_timeline($participant_id, 'cabin_credit_issued', 'Verified Founding Runner Cabin Credit issued: ' . $credit_number, array('amount' => 100, 'issued_by' => $actor_id));
        }
        if (!$suite_was_active) {
            $this->log_timeline($participant_id, 'captain_suite_activated', "Captain's Suite activated after email verification", array('activated_by' => $actor_id));
        }
        if (empty($participant->certificate_issued_at)) {
            $this->log_timeline($participant_id, 'certificate_issued', 'Founding Runner certificate issued: ' . $certificate_number, array('issued_by' => $actor_id));
        }
        if ($duplicate_hold && (int) $actor_id) {
            $this->log_timeline($participant_id, 'duplicate_benefits_approved', 'Administrator approved benefits for a suspected duplicate registration.', array('tracking_id' => (int) $duplicate_hold->id, 'admin_id' => (int) $actor_id));
        }

        if ($send_certificate) {
            $email_result = $this->send_certificate($participant_id, $actor_id);
            if (is_wp_error($email_result)) {
                error_log('RTS: Benefits were issued, but certificate email failed for participant ' . $participant_id . ': ' . $email_result->get_error_message());
            }
        }

        return $this->get_participant($participant_id);
    }

    private function generate_certificate_number()
    {
        do {
            $number = 'RTS-CERT-' . gmdate('Y') . '-' . strtoupper(wp_generate_password(6, false, false));
        } while ($this->db->get_var($this->db->prepare("SELECT id FROM {$this->db->prefix}rts_participants WHERE certificate_number = %s", $number)));

        return $number;
    }

    /**
     * Create a personalised PDF from the approved Run The Seas certificate.
     * The approved artwork stays intact; only the participant-specific fields
     * are overlaid at issue time.
     */
    public function generate_certificate_pdf($participant_id)
    {
        $participant = $this->get_participant($participant_id);
        if (!$participant || empty($participant->certificate_number)) {
            return new WP_Error('rts_certificate_unavailable', __('No issued certificate is available for this participant.', 'run-the-seas'));
        }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return new WP_Error('rts_certificate_directory', $uploads['error']);
        }
        $directory = trailingslashit($uploads['basedir']) . 'rts-certificates/';
        if (!wp_mkdir_p($directory)) {
            return new WP_Error('rts_certificate_directory', __('Certificate storage could not be created.', 'run-the-seas'));
        }

        $filename = 'run-the-seas-certificate-' . absint($participant_id) . '-' . sanitize_file_name($participant->certificate_number) . '.pdf';
        $path = $directory . $filename;

        $template_url = $this->get_certificate_backplate_url('rts_certificate_email_design_assets');
        $template_path = $this->get_verification_preview_source_path($template_url);
        if (!$template_path || !is_readable($template_path)) {
            return new WP_Error('rts_certificate_template', __('The approved certificate artwork is unavailable.', 'run-the-seas'));
        }
        $template = $this->create_certificate_image_resource($template_path);
        if (!$template) {
            return new WP_Error('rts_certificate_template', __('The approved certificate artwork could not be read.', 'run-the-seas'));
        }
        $width = imagesx($template);
        $height = imagesy($template);

        // Rasterize every personalised field before embedding the image. The
        // PDF, email preview, Captain's Suite, print, and download views now
        // share this exact renderer and therefore cannot drift apart.
        imagealphablending($template, true);
        if (function_exists('imageantialias')) {
            imageantialias($template, true);
        }
        $this->render_certificate_personalisation($template, $participant);

        ob_start();
        imagejpeg($template, null, 94);
        $background = ob_get_clean();
        imagedestroy($template);
        if (!$background) {
            return new WP_Error('rts_certificate_template', __('The certificate artwork could not be prepared.', 'run-the-seas'));
        }

        $stream = "q\n{$width} 0 0 {$height} 0 0 cm\n/Im1 Do\nQ\n";
        $objects = array(
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $width . ' ' . $height . '] /Resources << /Font << /F1 4 0 R /F2 5 0 R /F3 6 0 R >> /XObject << /Im1 7 0 R >> >> /Contents 8 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Italic >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
            '<< /Type /XObject /Subtype /Image /Width ' . $width . ' /Height ' . $height . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($background) . " >>\nstream\n" . $background . "\nendstream",
            '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream',
        );
        $pdf = "%PDF-1.4\n";
        $offsets = array(0);
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";

        if (false === file_put_contents($path, $pdf)) {
            return new WP_Error('rts_certificate_write', __('The certificate PDF could not be saved.', 'run-the-seas'));
        }

        return $path;
    }

    public function send_certificate($participant_id, $actor_id = 0)
    {
        $participant = $this->get_participant($participant_id);
        if (!$participant || (int) $participant->email_verified !== 1) {
            return new WP_Error('rts_certificate_email', __('A verified participant is required to send a certificate.', 'run-the-seas'));
        }
        $attachment = $this->generate_certificate_pdf($participant_id);
        if (is_wp_error($attachment)) {
            return $attachment;
        }

        $subject = 'Your Run The Seas Founding Runner Certificate';
        $captains_suite_url = function_exists('rts_get_captains_suite_url')
            ? rts_get_captains_suite_url()
            : home_url('/captains-suite/');
        $message = $this->get_certificate_email_message(
            $participant,
            $captains_suite_url
        );
        $certificate_values = $this->get_certificate_personalisation($participant);
        $email_template = rts_resolve_transactional_email_template(
            'founding_runner_certificate',
            $subject,
            $message,
            array(
                'first_name' => $participant->first_name,
                'last_name' => $participant->last_name,
                'email' => $participant->email,
                'certificate_number' => $participant->certificate_number,
                'founding_runner_number' => $certificate_values['runner_number'],
                'certificate_status' => $certificate_values['status'],
                'certificate_issued_date' => $certificate_values['issued_date'],
                'captains_suite_url' => $captains_suite_url,
                'account_url' => $captains_suite_url,
                'certificate_preview_url' => $this->get_email_certificate_preview_url($participant, 'rts_certificate_email_design_assets'),
                '_certificate_participant' => $participant,
            )
        );
        $subject = $email_template['subject'];
        $message = $email_template['html_body'];
        $sent = wp_mail($participant->email, $subject, $message, rts_mail_headers(), array($attachment));
        if (!$sent) {
            return new WP_Error('rts_certificate_email', __('The certificate email could not be sent. Check the mail configuration.', 'run-the-seas'));
        }

        $this->db->update($this->db->prefix . 'rts_participants', array('certificate_sent_at' => current_time('mysql'), 'updated_at' => current_time('mysql')), array('id' => $participant_id));
        $this->log_timeline($participant_id, 'certificate_emailed', 'Certificate PDF emailed to participant', array('sent_by' => $actor_id));
        return true;
    }

    /**
     * Build the Founding Runner certificate delivery email. The table-based
     * layout and inline styles are intentionally email-client safe; imagery is
     * configured in Surveys > Certificate Email in the WordPress admin.
     */
    private function get_certificate_email_message($participant, $captains_suite_url)
    {
        $assets = get_option('rts_certificate_email_design_assets', array());
        $assets = is_array($assets) ? $assets : array();
        $asset = function ($key) use ($assets) {
            return !empty($assets[$key]) ? esc_url($assets[$key]) : '';
        };

        $name = trim((string) $participant->first_name . ' ' . (string) $participant->last_name);
        $name = $name !== '' ? $name : __('Founding Runner', 'run-the-seas');
        $header_image = $asset('complete_header_image');
        $header_logo = $asset('header_logo_image');
        $hero_image = $asset('hero_image');
        $certificate_preview = $this->get_email_certificate_preview_url($participant, 'rts_certificate_email_design_assets');
        $suite_background = $asset('suite_background_image');

        $image = function ($url, $width, $alt, $style = '') {
            if (!$url) {
                return '';
            }
            return '<img src="' . esc_url($url) . '" width="' . absint($width) . '" alt="' . esc_attr($alt) . '" style="display:block;width:100%;max-width:' . absint($width) . 'px;height:auto;border:0;' . $style . '">';
        };
        $header_section = $header_image
            ? '<tr><td style="background:#031b38;border-bottom:2px solid #d99a1b;">' . $image($header_image, 1200, 'Run The Seas — More than a race') . '</td></tr>'
            : '<tr><td style="padding:20px 28px;background:#031b38;border-bottom:2px solid #d99a1b;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
            . '<td width="52%" valign="middle">'
            . ($header_logo ? $image($header_logo, 300, 'Run The Seas') : '<span style="font-family:Georgia,serif;font-size:30px;font-weight:bold;letter-spacing:.5px;color:#e7a41e;">&#9875; RUN THE SEAS</span>')
            . '</td><td width="48%" align="right" valign="middle" style="font-family:Georgia,serif;color:#f5bd42;">'
            . '<div style="font-size:21px;line-height:1.2;">More than a race.</div><div style="padding-top:5px;font-size:13px;color:#ffffff;">IT&rsquo;S THE ADVENTURE OF A LIFETIME.<br>RUN. EXPLORE. CELEBRATE. BELONG.</div>'
            . '</td></tr></table></td></tr>';

        $hero_divider_image = $image($asset('hero_divider_image'), 245, '', 'margin:0 0 19px;');
        $hero_divider = $hero_divider_image ?: '<table role="presentation" width="245" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 19px;"><tr>'
            . '<td width="92" valign="middle"><div style="border-top:2px solid #d59b19;font-size:0;line-height:0;">&nbsp;</div></td>'
            . '<td width="61" align="center" valign="middle" style="font-family:Georgia,serif;font-size:27px;line-height:1;color:#a86612;">&#9875;&#65038;</td>'
            . '<td width="92" valign="middle"><div style="border-top:2px solid #d59b19;font-size:0;line-height:0;">&nbsp;</div></td>'
            . '</tr></table>';
        $hero_content = '<table role="presentation" width="100%" height="490" cellspacing="0" cellpadding="0" border="0" style="background-image:linear-gradient(90deg,rgba(255,255,255,.80) 0%,rgba(255,255,255,.62) 24%,rgba(255,255,255,.12) 36%,rgba(255,255,255,0) 46%);"><tr>'
            . '<td width="35%" valign="middle" style="padding:38px 24px;color:#071b3b;font-family:Arial,Helvetica,sans-serif;">'
            . '<div style="font-family:Georgia,serif;font-size:26px;font-weight:bold;line-height:1.05;">CONGRATULATIONS,</div>'
            . '<h1 style="margin:8px 0 14px;font-family:Georgia,serif;font-size:42px;line-height:1;color:#b26a12;text-shadow:1px 1px 0 #f3d78d;overflow-wrap:anywhere;word-break:break-word;">' . esc_html(strtoupper($participant->first_name ?: $name)) . '!</h1>'
            . $hero_divider
            . '<p style="margin:0 0 13px;font-family:Georgia,serif;font-size:20px;line-height:1.35;font-weight:bold;">Your personalized Founding Runner promotional cruise credit is ready.</p>'
            . '<p style="margin:0;font-size:16px;line-height:1.55;">Thank you for helping shape the future of Run The Seas<sup>&reg;</sup>. You are now officially part of history.</p>'
            . '</td><td width="65%" style="font-size:0;line-height:0;">&nbsp;</td></tr></table>';
        $hero_section = $hero_image
            ? '<tr><td height="490" background="' . esc_url($hero_image) . '" style="height:490px;background-color:#2da9e9;background-image:url(\'' . esc_url($hero_image) . '\');background-position:center center;background-repeat:no-repeat;background-size:cover;">' . $hero_content . '</td></tr>'
            : '<tr><td style="background:#cfeeff;">' . $hero_content . '</td></tr>';

        $certificate_image = $image($certificate_preview, 800, 'Your Founding Runner Gift Certificate', 'margin:0 auto;border:1px solid #b68018;');
        $certificate_filename = 'run-the-seas-certificate-' . sanitize_file_name((string) $participant->certificate_number) . '.png';
        $certificate_link_attributes = ' href="' . esc_url($certificate_preview) . '" download="' . esc_attr($certificate_filename) . '" target="_blank" rel="noopener"';
        $certificate_image_link = '<a' . $certificate_link_attributes . ' style="display:inline-block;text-decoration:none;">' . $certificate_image . '</a>';
        $download_button_image = $image($asset('download_button_image'), 360, 'Download Certificate', 'margin:0 auto;');
        $download_button = $download_button_image
            ? '<a' . $certificate_link_attributes . ' style="display:inline-block;text-decoration:none;">' . $download_button_image . '</a>'
            : '<a' . $certificate_link_attributes . ' style="display:inline-block;padding:14px 28px;border-radius:6px;background:#eaa20f;color:#15110a;font-family:Georgia,serif;font-size:18px;font-weight:bold;text-decoration:none;border:1px solid #b97508;">DOWNLOAD CERTIFICATE</a>';

        $suite_benefits = array(
            array('suite_icon_voyage', 'Voyage updates and key announcements'),
            array('suite_icon_priority', 'Priority access to booking and events'),
            array('suite_icon_marathon', 'The Referral Marathon Challenge'),
            array('suite_icon_avatar', 'Your own avatar on the race route'),
            array('suite_icon_profile', 'Your profile, certificate and cruise credit'),
        );
        $suite_items = '';
        foreach ($suite_benefits as $benefit) {
            $icon = $image($asset($benefit[0]), 49, '', 'border-radius:50%;');
            if (!$icon) {
                $icon = '<span style="display:inline-block;width:43px;height:43px;line-height:43px;border:2px solid #d69b20;border-radius:50%;color:#eab02a;font-family:Georgia,serif;font-size:23px;text-align:center;">&#9875;</span>';
            }
            $suite_items .= '<tr><td width="59" valign="top" style="padding:0 10px 13px 0;">' . $icon . '</td><td valign="middle" style="padding:0 0 13px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.28;color:#ffffff;">' . esc_html($benefit[1]) . '</td></tr>';
        }
        $suite_button_image = $image($asset('suite_button_image'), 320, "Enter the Captain's Suite", 'margin:0 auto;');
        $suite_button = $suite_button_image
            ? '<a href="' . esc_url($captains_suite_url) . '" style="display:inline-block;text-decoration:none;">' . $suite_button_image . '</a>'
            : '<a href="' . esc_url($captains_suite_url) . '" style="display:inline-block;padding:13px 22px;border-radius:6px;background:#eaa20f;color:#15110a;font-family:Georgia,serif;font-size:16px;font-weight:bold;text-decoration:none;border:1px solid #b97508;">&#9875;&nbsp; ENTER THE CAPTAIN&rsquo;S SUITE</a>';
        $suite_style = 'background:#031b38;border:2px solid #d69b20;border-radius:18px;';
        if ($suite_background) {
            $suite_style .= 'background-image:url(\'' . esc_url($suite_background) . '\');background-position:center;background-size:cover;';
        }
        $suite_top_divider_image = $image($asset('suite_top_divider_image'), 330, '', 'margin:0 auto 9px;');
        $suite_top_divider = $suite_top_divider_image ?: '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 9px;"><tr>'
            . '<td valign="middle"><div style="border-top:1px solid #d69b20;font-size:0;line-height:0;">&nbsp;</div></td>'
            . '<td width="46" align="center" style="font-family:Georgia,serif;font-size:24px;line-height:1;color:#e9a31d;">&#9819;</td>'
            . '<td valign="middle"><div style="border-top:1px solid #d69b20;font-size:0;line-height:0;">&nbsp;</div></td>'
            . '</tr></table>';
        $suite_bottom_divider_image = $image($asset('suite_bottom_divider_image'), 330, '', 'margin:9px auto 15px;');
        $suite_bottom_divider = $suite_bottom_divider_image ?: '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:9px 0 15px;"><tr>'
            . '<td valign="middle"><div style="border-top:1px solid #d69b20;font-size:0;line-height:0;">&nbsp;</div></td>'
            . '<td width="38" align="center" style="font-family:Georgia,serif;font-size:19px;line-height:1;color:#e9a31d;">&#9875;&#65038;</td>'
            . '<td valign="middle"><div style="border-top:1px solid #d69b20;font-size:0;line-height:0;">&nbsp;</div></td>'
            . '</tr></table>';

        $footer_icon_image = $image($asset('footer_icon_image'), 30, 'Founding Runner', 'margin:0 auto;');
        $footer_icon = $footer_icon_image ?: '<span style="font-family:Georgia,serif;font-size:33px;line-height:1;color:#a86612;">&#9875;&#65038;</span>';
        $footer_image_url = $asset('footer_foliage_image');
        $footer_background = $footer_image_url
            ? ' background="' . esc_url($footer_image_url) . '" style="height:132px;padding:4px 20px;background-color:#ffffff;background-image:url(\'' . esc_url($footer_image_url) . '\');background-position:center center;background-repeat:no-repeat;background-size:100% 100%;"'
            : ' style="height:132px;padding:4px 20px;background:#ffffff;"';
        $footer = '<tr><td height="132" align="center" valign="top"' . $footer_background . '>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:720px;background:transparent;">'
            . '<tr style="display: flex; justify-content: center; margin-top: 30px;"><td width="52" align="right" valign="middle" style="padding:5px 9px 0;">' . $footer_icon . '</td>'
            . '<td align="left" valign="middle" style="padding:5px 8px 0 0;color:#071b3b;white-space:nowrap;">'
            . '<div style="font-family:Georgia,\'Times New Roman\',serif;font-size:20px;line-height:1.05;font-weight:bold;letter-spacing:.1px;">YOU ARE NOW OFFICIALLY A FOUNDING RUNNER.</div>'
            . '<div style="padding-top:1px;font-family:\'Brush Script MT\',\'Segoe Script\',cursive;font-size:19px;line-height:1;font-style:italic;color:#a84b23;text-align:center;">Thank you for helping us make history.</div>'
            . '</td></tr></table>'
            . '<table role="presentation" width="370" cellspacing="0" cellpadding="0" border="0" style="margin:7px auto 0;background:#ffffff;border-radius:3px;"><tr><td align="center" style="padding:3px 10px 4px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.1;color:#071b3b;">We can&rsquo;t wait to welcome you aboard.</td></tr></table>'
            . '</td></tr>';

        return '<!doctype html><html><body style="margin:0;padding:0;background:#edf1f4;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#edf1f4;"><tr><td align="center" style="padding:16px 7px;">'
            . '<table role="presentation" width="1200" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:1200px;background:#ffffff;border-collapse:collapse;">'
            . $header_section . $hero_section
            . '<tr><td style="padding:0 22px 15px;background:#ffffff;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="position:relative;z-index:2;margin:-58px auto 0;background:#ffffff;border-radius:24px 24px 0 0;box-shadow:0 -8px 20px rgba(5,27,56,.18);"><tr>'
            . '<td width="70%" valign="top" style="padding:24px 17px 18px;color:#071b3b;text-align:center;"><table role="presentation" width="92%" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto 15px;background:#fffdf9;border:1px solid #ead7bb;border-radius:10px;box-shadow:0 7px 15px rgba(75,44,14,.22);"><tr><td align="center" style="padding:12px;font-family:Georgia,serif;color:#71341e;"><div style="font-size:22px;font-weight:bold;line-height:1.13;">YOUR FOUNDING RUNNER<br>PROMOTIONAL CRUISE CREDIT</div></td></tr></table>'
            . '<p style="margin:auot;max-width:"40%";font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.45;">Download it. Print it. Share it.<br>This certificate includes your unique Founding Runner Number and $100 Founding Runner Cruise Credit.</p>'
            . $certificate_image_link . '<div style="padding:17px 0 0;">' . $download_button . '</div></td>'
            . '<td width="30%" valign="top" style="padding:19px 17px 18px 8px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:18px 18px 18px;' . $suite_style . '">'
            . $suite_top_divider . '<div style="font-family:Georgia,serif;font-size:19px;color:#ffffff;">WELCOME TO YOUR</div><div style="padding:3px 0 0;font-family:Georgia,serif;font-size:32px;line-height:1;color:#e9a31d;">CAPTAIN&rsquo;S SUITE</div>'
            . $suite_bottom_divider . '<p style="margin:0 0 15px;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.4;">We&rsquo;ve created your private captain&rsquo;s suite&mdash;your exclusive hub for everything Run The Seas<sup>&reg;</sup>.</p>'
            . '<p style="margin:0 0 12px;color:#f2c15a;font-family:Georgia,serif;font-size:17px;">Inside, you&rsquo;ll find:</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">' . $suite_items . '</table>'
            . '<div style="padding-top:4px;">' . $suite_button . '</div></td></tr></table></td>'
            . '</tr></table></td></tr>' . $footer
            . '</table></td></tr></table></body></html>';
    }

    /** Notify an opted-in referrer when a referred participant verifies. */
    public function send_referral_progress_notification($referrer_id)
    {
        $referrer = $this->get_participant($referrer_id);
        if (!$referrer || empty($referrer->email)) {
            return false;
        }

        // Notifications are enabled by default. A member can opt out from My Details.
        if (!empty($referrer->user_id) && get_user_meta($referrer->user_id, 'rts_referral_progress_notifications', true) === 'off') {
            return false;
        }

        $total_miles = (int) $referrer->total_captain_miles_earned;
        $successful_referrals = (int) $referrer->successful_referrals;
        $milestones = array(
            5000 => '5K Trophy',
            10000 => '10K Trophy',
            15000 => '15K Trophy',
            20000 => '20K Trophy',
            21000 => '21.1K Trophy',
            25000 => '25K Trophy',
            30000 => '30K Trophy',
            35000 => '35K Trophy',
            42000 => '42.2K Trophy',
            47000 => 'Marathon 2 — 5K Trophy',
            52000 => 'Marathon 2 — 10K Trophy',
            57000 => 'Marathon 2 — 15K Trophy',
            62000 => 'Marathon 2 — 20K Trophy',
            63000 => 'Marathon 2 — 21.1K Trophy',
            67000 => 'Marathon 2 — 25K Trophy',
            72000 => 'Marathon 2 — 30K Trophy',
            77000 => 'Marathon 2 — 35K Trophy',
            84000 => 'Marathon 2 — 42.2K Trophy',
        );
        $next_milestone = null;
        foreach ($milestones as $required_miles => $milestone_name) {
            if ($total_miles < $required_miles) {
                $next_milestone = array('name' => $milestone_name, 'miles' => $required_miles - $total_miles);
                break;
            }
        }

        $name = trim($referrer->first_name . ' ' . $referrer->last_name);
        $preferences_url = home_url('/my-details/');
        $message = '<p>Hello ' . esc_html($name) . ',</p>'
            . '<p><strong>A new referral has verified their email!</strong> That verified referral advanced you by <strong>1 km</strong>. You have now completed <strong>' . esc_html(rts_format_miles($total_miles)) . '</strong> in the 42.2K Referral Marathon Challenge.</p>'
            . '<p>You have <strong>' . esc_html(number_format_i18n($successful_referrals)) . '</strong> verified referral' . ($successful_referrals === 1 ? '' : 's') . '.</p>';
        if ($next_milestone) {
            $referrals_needed = (int) ceil($next_milestone['miles'] / 1000);
            $message .= '<p>You need <strong>' . esc_html(number_format_i18n($referrals_needed)) . ' more verified referral' . ($referrals_needed === 1 ? '' : 's') . '</strong> (' . esc_html(rts_format_miles($next_milestone['miles'])) . ') to reach your next medal: <strong>' . esc_html($next_milestone['name']) . '</strong>. Keep sharing your referral link!</p>';
        } else {
            $message .= '<p>You have reached every current 42.2K Referral Marathon Challenge milestone. Keep growing your crew!</p>';
        }
        $message .= '<p style="font-size:12px;color:#666;">To turn off these referral-progress emails, update your preference in <a href="' . esc_url($preferences_url) . '">My Details</a>.</p>';

        $sent = wp_mail(
            $referrer->email,
            'A verified referral advanced you by 1 km!',
            $message,
            rts_mail_headers()
        );
        if ($sent) {
            $this->log_timeline($referrer_id, 'referral_progress_email_sent', 'Referral progress email sent after a referred participant verified.', array('miles' => 1000, 'successful_referrals' => $successful_referrals));
        }
        return $sent;
    }

    private function pdf_escape($value)
    {
        $value = wp_strip_all_tags($value);
        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
        return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $value);
    }

    /**
     * Generate unique referral code
     */
    private function generate_referral_code($first_name, $last_name)
    {
        $base = strtoupper(substr($first_name, 0, 3) . substr($last_name, 0, 3));
        $random = strtoupper(substr(uniqid(), -4));
        $code = $base . $random;

        // Check if code exists
        $exists = $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM {$this->db->prefix}rts_participants WHERE referral_code = %s",
                $code
            )
        );

        if ($exists) {
            return $this->generate_referral_code($first_name, $last_name);
        }

        return $code;
    }

    /**
     * Process referral
     */
    private function process_referral($participant_id, $referral_code)
    {
        // Find referrer by referral code
        $referrer = $this->db->get_row(
            $this->db->prepare(
                "SELECT id FROM {$this->db->prefix}rts_participants WHERE referral_code = %s",
                $referral_code
            )
        );

        if (!$referrer) {
            error_log('RTS: Invalid referral code supplied');
            return false;
        }

        // Get participant data
        $participant = $this->get_participant($participant_id);

        if (!$participant) {
            error_log("RTS: Participant not found: {$participant_id}");
            return false;
        }

        // CRITICAL: Check if referral already exists for this email and referrer
        $existing = $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM {$this->db->prefix}rts_referrals 
                WHERE referrer_id = %d AND referred_email = %s",
                $referrer->id,
                $participant->email
            )
        );

        if ($existing) {
            error_log('RTS: Referral record already exists for participant ' . $participant_id);
            // Update existing referral with participant ID if missing
            $this->db->update(
                $this->db->prefix . 'rts_referrals',
                array(
                    'referred_participant_id' => $participant_id
                ),
                array('id' => $existing)
            );
            return true;
        }

        // Record referral - only if it doesn't exist
        $inserted = $this->db->insert(
            $this->db->prefix . 'rts_referrals',
            array(
                'referrer_id' => $referrer->id,
                'referred_email' => $participant->email,
                'referred_participant_id' => $participant_id,
                'referral_code' => $referral_code,
                'referral_source' => 'registration',
                'status' => 'pending',
                'bonus_earned' => 0,
                'referral_date' => current_time('mysql'),
                'created_at' => current_time('mysql')
            )
        );

        if ($inserted !== false) {
            // Update referrer's referral count
            $this->db->query(
                $this->db->prepare(
                    "UPDATE {$this->db->prefix}rts_participants 
                    SET referral_count = referral_count + 1 
                    WHERE id = %d",
                    $referrer->id
                )
            );

            // Add achievement for referrer
            $this->add_achievement(
                $referrer->id,
                'referral_made',
                'New Referral!',
                "You referred {$participant->first_name} {$participant->last_name} to the Founding Runners program!"
            );

            // Log timeline
            $this->log_timeline(
                $participant_id,
                'referred_by',
                "Referred by {$referrer->id} using code: {$referral_code}"
            );

            $this->log_timeline(
                $referrer->id,
                'referral_made',
                "Referred {$participant->first_name} {$participant->last_name}"
            );

            error_log("RTS: Referral processed: {$referrer->id} -> {$participant_id}");
            return true;
        }

        return false;
    }

    /**
     * Send verification email
     */
    public function send_verification_email($participant_id, $force = false)
    {
        global $wpdb;

        $participant = $this->get_participant($participant_id);

        if (!$participant) {
            error_log('RTS: Participant not found for verification email: ' . $participant_id);
            return false;
        }

        // Check if already verified
        if ($participant->email_verified == 1) {
            error_log('RTS: Email already verified for participant ' . $participant_id);
            return false;
        }

        // Reserve the exact certificate number shown in the verification
        // preview. It remains unissued until activate_verified_benefits() sets
        // certificate_issued_at after the recipient verifies their email.
        if (empty($participant->certificate_number)) {
            $certificate_number = $this->generate_certificate_number();
            $certificate_reserved = $wpdb->update(
                $wpdb->prefix . 'rts_participants',
                array(
                    'certificate_number' => $certificate_number,
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => $participant_id),
                array('%s', '%s'),
                array('%d')
            );
            if ($certificate_reserved === false) {
                error_log('RTS: Failed to reserve certificate number for participant ' . $participant_id . ': ' . $wpdb->last_error);
                return false;
            }
            $participant->certificate_number = $certificate_number;
        }

        // Check if verification email was sent recently (within last 10 minutes)
        $recent_sent = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}rts_timeline 
                WHERE participant_id = %d 
                AND activity_type = 'verification_sent' 
                AND activity_date > DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
                $participant_id
            )
        );

        if (!$force && $recent_sent > 0) {
            error_log('RTS: Verification email already sent recently for participant ' . $participant_id);
            return true;
        }

        // Check total count
        $total_sent = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}rts_timeline 
                WHERE participant_id = %d AND activity_type = 'verification_sent'",
                $participant_id
            )
        );

        if (!$force && $total_sent > 0) {
            error_log('RTS: Verification email already sent ' . $total_sent . ' times for participant ' . $participant_id);
            // If already sent once, don't send again unless it's a manual resend
            return true;
        }

        /*
         * The registration request and the background processor can overlap by
         * a few seconds. Reserve this first automatic verification send before
         * calling wp_mail(), because a mail transport can report failure after
         * accepting the message and would otherwise cause a duplicate send.
         */
        if (
            !$force
            && !add_option(
                'rts_verification_email_issued_' . $participant_id,
                current_time('mysql'),
                '',
                'no'
            )
        ) {
            error_log('RTS: Verification email already issued for participant ' . $participant_id);
            return true;
        }

        error_log('RTS: Sending verification email for participant ' . $participant_id);

        // Every resend gets a fresh token, invalidating older links. Keep the
        // legacy token used by this plugin's public verification handler in
        // sync with the canonical token used by the admin platform.
        $verification_token = bin2hex(random_bytes(32));
        $verification_updated_at = current_time('mysql');
        $verification_updated = $wpdb->update(
            $wpdb->prefix . 'rts_participants',
            array(
                'email_verification_token' => $verification_token,
                'verification_token' => $verification_token,
                'updated_at' => $verification_updated_at,
            ),
            array('id' => $participant_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        if ($verification_updated === false) {
            error_log('RTS: Failed to store verification token before sending for participant ' . $participant_id . ': ' . $wpdb->last_error);
            return false;
        }

        $participant->email_verification_token = $verification_token;
        $participant->verification_token = $verification_token;

        $verification_link = add_query_arg(
            array(
                'rts_verify_email' => '1',
                'token' => $participant->email_verification_token,
                'email' => urlencode($participant->email)
            ),
            home_url()
        );

        $subject = 'Confirm Your Email Address | Run The Seas';
        $message = $this->get_verification_email_message($participant, $verification_link);
        $certificate_values = $this->get_certificate_personalisation($participant);
        $email_template = rts_resolve_transactional_email_template(
            'email_verification',
            $subject,
            $message,
            array(
                'first_name' => $participant->first_name,
                'last_name' => $participant->last_name,
                'email' => $participant->email,
                'verification_url' => $verification_link,
                'certificate_number' => !empty($participant->certificate_number) ? $participant->certificate_number : '',
                'founding_runner_number' => $certificate_values['runner_number'],
                'certificate_status' => $certificate_values['status'],
                'certificate_issued_date' => $certificate_values['issued_date'],
                'captains_suite_url' => function_exists('rts_get_captains_suite_url') ? rts_get_captains_suite_url() : home_url('/captains-suite/'),
                'certificate_preview_url' => $this->get_email_certificate_preview_url($participant, 'rts_verification_email_design_assets'),
                '_certificate_participant' => $participant,
            )
        );
        $subject = $email_template['subject'];
        $message = $email_template['html_body'];
        $headers = rts_mail_headers();

        $sent = wp_mail($participant->email, $subject, $message, $headers);

        if ($sent) {
            $verification_sent_at = current_time('mysql');
            $wpdb->update(
                $wpdb->prefix . 'rts_participants',
                array(
                    'verification_sent_at' => $verification_sent_at,
                    'updated_at' => $verification_sent_at,
                ),
                array('id' => $participant_id),
                array('%s', '%s'),
                array('%d')
            );
            $this->log_timeline(
                $participant_id,
                'verification_sent',
                'Verification email sent'
            );
            error_log('RTS: Verification email sent for participant ' . $participant_id);
        } else {
            error_log('RTS: Verification email failed for participant ' . $participant_id);
        }

        return $sent;
    }

    /**
     * Build the verification email with email-client-safe tables and inline CSS.
     * No certificate file is attached or linked; the selected certificate is an
     * image-only preview for the verification email.
     */
    private function get_verification_email_message($participant, $verification_link)
    {
        $assets = get_option('rts_verification_email_design_assets', array());
        $assets = is_array($assets) ? $assets : array();
        $complete_header_image = !empty($assets['complete_header_image']) ? esc_url($assets['complete_header_image']) : '';
        $header_image = !empty($assets['header_image']) ? esc_url($assets['header_image']) : '';
        $hero_image = !empty($assets['hero_image']) ? esc_url($assets['hero_image']) : '';
        $headline_divider_image = !empty($assets['headline_divider_image']) ? esc_url($assets['headline_divider_image']) : '';
        $name_divider_image = !empty($assets['name_divider_image']) ? esc_url($assets['name_divider_image']) : '';
        $email_icon_image = !empty($assets['email_icon_image']) ? esc_url($assets['email_icon_image']) : '';
        $lock_icon_image = !empty($assets['lock_icon_image']) ? esc_url($assets['lock_icon_image']) : '';
        $complete_button_image = !empty($assets['complete_button_image']) ? esc_url($assets['complete_button_image']) : '';
        $certificate_preview_image = $this->get_email_certificate_preview_url($participant, 'rts_verification_email_design_assets');
        $name = trim((string) $participant->first_name . ' ' . (string) $participant->last_name);
        $name = $name !== '' ? $name : __('Founding Runner', 'run-the-seas');

        $header_logo = $header_image
            ? '<img src="' . $header_image . '" width="300" alt="Run The Seas" style="display:block;width:100%;max-width:300px;height:auto;border:0;">'
            : '<div style="color:#f6bd32;font-family:Georgia,serif;font-size:29px;font-weight:bold;line-height:1.1;letter-spacing:.5px;">&#9875; RUN THE SEAS</div>';
        $header_section = $complete_header_image
            ? '<tr><td bgcolor="#041c3a" style="background:#041c3a;border-bottom:2px solid #d48618;"><img src="' . $complete_header_image . '" width="1200" alt="Run The Seas — More than a race. It’s the adventure of a lifetime." style="display:block;width:100%;height:auto;border:0;"></td></tr>'
            : '<tr><td bgcolor="#041c3a" style="padding:22px 26px;border-bottom:2px solid #d48618;background:#041c3a;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
            . '<td width="52%" valign="middle" align="left">' . $header_logo . '</td>'
            . '<td width="48%" valign="middle" align="right" style="padding-left:18px;color:#f6bd32;font-family:Georgia,serif;">'
            . '<div style="font-size:22px;line-height:1.25;font-style:italic;">More than a race.<br>It&rsquo;s the adventure of a lifetime!</div>'
            . '<div style="padding-top:9px;font-size:13px;line-height:1.4;font-style:normal;">Run. Explore. Celebrate. Belong.</div>'
            . '</td></tr></table></td></tr>';
        $certificate = $certificate_preview_image
            ? '<img src="' . esc_url($certificate_preview_image) . '" width="490" alt="Preview of your Founding Runner Cruise Credit" style="display:block;width:100%;min-width:490px;max-width:490px;height:auto;border:1px solid #d59b19;">'
            : '';
        $headline_divider = $headline_divider_image
            ? '<img src="' . $headline_divider_image . '" width="190" alt="" style="display:block;width:100%;max-width:190px;height:auto;border:0;margin:17px 0 13px;">'
            : '<div style="width:175px;border-top:2px solid #d48618;margin:18px 0 13px;"></div>';
        $confirmation_divider = $headline_divider_image
            ? '<img src="' . $headline_divider_image . '" width="260" alt="" style="display:block;width:100%;max-width:260px;height:auto;border:0;margin:0 auto 24px;">'
            : '<div style="border-top:2px solid #d48618;max-width:390px;margin:0 auto 24px;"></div>';
        $name_divider = $name_divider_image
            ? '<img src="' . $name_divider_image . '" width="190" alt="" style="display:block;width:100%;max-width:190px;height:auto;border:0;margin:13px 0 16px;">'
            : '';
        $email_icon = $email_icon_image
            ? '<img src="' . $email_icon_image . '" width="78" alt="Email verification" style="display:block;width:78px;max-width:78px;height:auto;border:0;">'
            : '';
        $lock_icon = $lock_icon_image
            ? '<img src="' . $lock_icon_image . '" width="46" alt="" style="display:block;width:46px;max-width:46px;height:auto;border:0;margin:0 0 12px;">'
            : '';
        $confirmation_button = $complete_button_image
            ? '<a href="' . esc_url($verification_link) . '" style="display:inline-block;text-decoration:none;border:0;"><img src="' . $complete_button_image . '" width="440" alt="Confirm my email address" style="display:block;width:100%;max-width:440px;height:auto;border:0;"></a>'
            : '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto;"><tr><td align="center" bgcolor="#e2a11d" style="border-radius:5px;background:linear-gradient(90deg,#c47a0c,#ffc54b,#d98a0d);"><a href="' . esc_url($verification_link) . '" style="display:inline-block;padding:17px 34px;color:#071b3b;font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:bold;text-decoration:none;">CONFIRM MY EMAIL ADDRESS</a></td></tr></table>';

        $hero_content = '<table role="presentation" width="100%" height="455" cellspacing="0" cellpadding="0" border="0"><tr>'
            . '<td width="44%" valign="middle" style="padding:34px 30px;background-color:rgba(255,255,255,.66);background-image:linear-gradient(90deg,rgba(255,255,255,.90) 0%,rgba(255,255,255,.66) 78%,rgba(255,255,255,.20) 100%);color:#071b3b;font-family:Arial,Helvetica,sans-serif;">'
            . '<h1 style="margin:0 0 13px;font-family:Georgia,serif;font-size:34px;line-height:1.16;color:#071b3b;">Your Founding Runner<br>Cruise Credit Is<br>Almost Ready!</h1>'
            . $headline_divider
            . '<p style="margin:0 0 17px;font-family:Georgia,serif;font-size:31px;line-height:1.1;color:#a94f12;">Ahoy ' . esc_html($name) . ',</p>'
            . $name_divider
            . '<p style="margin:0 0 12px;font-size:15px;line-height:1.55;">Thank you for helping shape the future of Run The Seas<sup>&reg;</sup>.</p>'
            . '<p style="margin:0;font-size:15px;line-height:1.55;">To deliver your personalized Founding Runner Cruise Credit, we first need to verify your email address.</p>'
            . '</td><td width="56%" style="font-size:0;line-height:0;">&nbsp;</td></tr>'
            . ($email_icon !== '' ? '<tr><td colspan="2" height="86" valign="bottom" align="center" style="padding:0 0 8px;">' . $email_icon . '</td></tr>' : '')
            . '</table>';
        $hero_section = $hero_image
            ? '<tr><td height="455" valign="middle" background="' . $hero_image . '" style="height:455px;background-color:#d9ecf5;background-image:url(\'' . $hero_image . '\');background-position:center center;background-repeat:no-repeat;background-size:cover;">' . $hero_content . '</td></tr>'
            : '<tr><td style="background:#f7fbff;">' . $hero_content . '</td></tr>';

        return '<!doctype html><html><body style="margin:0;padding:0;background:#eef2f5;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#eef2f5;"><tr><td align="center" style="padding:18px 8px;">'
            . '<table role="presentation" width="760" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:760px;background:#ffffff;border-collapse:collapse;">'
            . $header_section
            . $hero_section
            . '<tr><td align="center" style="padding:26px 30px 34px;background:#fffdf9;color:#071b3b;font-family:Arial,Helvetica,sans-serif;">'
            . '<h2 style="margin:0 0 8px;font-family:Georgia,serif;font-size:29px;line-height:1.2;color:#071b3b;">Please confirm your email address</h2>'
            . '<p style="margin:0 0 20px;font-size:17px;line-height:1.5;">Click the button below to verify your email.</p>'
            . $confirmation_divider
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td valign="top" width="30%" style="padding:0 14px 0 0;text-align:left;">'
            . '<div style="padding:17px;border:1px solid #db9a42;border-radius:10px;color:#071b3b;font-size:13px;line-height:1.55;">'
            . $lock_icon
            . '<strong style="display:block;font-size:16px;margin-bottom:8px;color:#a65d12;">Why we need you to confirm your email</strong>Email verification helps us securely deliver your personalized Founding Runner Cruise Credit and keep you updated on the inaugural Run The Seas voyage.'
            . '</div></td><td valign="top" width="70%" style="padding:0;text-align:center;">'
            . '<p style="margin:0 0 7px;font-family:Georgia,serif;font-size:14px;font-weight:bold;line-height:1.25;"><span style="color:#c56f12;">PREVIEW OF YOUR</span><br><span style="color:#071b3b;">FOUNDING RUNNER CRUISE CREDIT</span></p>'
            . $certificate
            . '</td></tr></table>'
            . '<div style="margin:28px auto 0;text-align:center;">' . $confirmation_button . '</div>'
            . '<p style="margin:20px 0 0;font-size:12px;line-height:1.5;color:#5e6877;">If the button does not work, copy and paste this secure link into your browser:<br><a href="' . esc_url($verification_link) . '" style="color:#a65d12;word-break:break-all;">' . esc_html($verification_link) . '</a></p>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /** Return the shared certificate backplate selected by staff, or the bundled approved artwork. */
    private function get_certificate_backplate_url($option_name = '')
    {
        $option_names = array();
        if ($option_name !== '') {
            $option_names[] = $option_name;
        }
        if (!in_array('rts_certificate_email_design_assets', $option_names, true)) {
            $option_names[] = 'rts_certificate_email_design_assets';
        }

        foreach ($option_names as $asset_option) {
            $assets = get_option($asset_option, array());
            if (is_array($assets) && !empty($assets['certificate_preview_image'])) {
                return esc_url_raw($assets['certificate_preview_image']);
            }
        }

        return esc_url_raw(RTS_PLUGIN_URL . 'assets/certificate-template.jpg');
    }

    /** Return the current configured, participant-specific certificate preview. */
    public function get_email_certificate_preview_url($participant, $option_name, $backplate_url = '')
    {
        $configured_source = $this->get_certificate_backplate_url($option_name);
        $source = $backplate_url !== '' ? esc_url_raw($backplate_url) : $configured_source;

        // The certificate number is reserved before the confirmation email is
        // built, so the preview can and must use the real participant values.
        // Verification state is rendered as PENDING until the link is opened.
        // The signed endpoint renders the configured design. A template-only
        // backplate must instead fall back to HTML over that exact artwork if
        // its PNG cannot be saved; otherwise the endpoint would use a different
        // image from the one staff selected in the template editor.
        return $this->get_verification_certificate_preview_url(
            $participant,
            $source,
            $source === $configured_source ? $option_name : ''
        );
    }

    /** Create the public registration-page sample from the same live renderer. */
    public function get_sample_certificate_preview_url($source_url, $date_text, $first_name = 'Your Name', $last_name = 'Here')
    {
        $first_name = trim((string) $first_name);
        $last_name = trim((string) $last_name);
        if ($first_name === '' && $last_name === '') {
            $first_name = 'Your Name';
            $last_name = 'Here';
        }

        $sample = (object) array(
            'id' => 237,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'founding_runner_number' => '0000237',
            'certificate_number' => 'RTS-CERT-' . wp_date('Y') . '-V6JYLZ',
            'certificate_issued_at' => $date_text,
            'email_verified' => 1,
        );

        $source_url = esc_url_raw((string) $source_url);
        if ($source_url === '') {
            $source_url = $this->get_certificate_backplate_url('rts_certificate_email_design_assets');
        }

        return $this->get_verification_certificate_preview_url($sample, $source_url);
    }

    /** Load supported certificate artwork into a GD image resource. */
    private function create_certificate_image_resource($source_path)
    {
        $image_info = @getimagesize($source_path);
        if (!$image_info) {
            return false;
        }

        switch ($image_info[2]) {
            case IMAGETYPE_JPEG:
                return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($source_path) : false;
            case IMAGETYPE_PNG:
                return function_exists('imagecreatefrompng') ? @imagecreatefrompng($source_path) : false;
            case IMAGETYPE_WEBP:
                return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source_path) : false;
        }

        return false;
    }

    /** Build the exact participant-specific values shown on the certificate. */
    private function get_certificate_personalisation($participant)
    {
        $name = trim((string) ($participant->first_name ?? '') . ' ' . (string) ($participant->last_name ?? ''));
        $name = $name !== '' ? $name : __('Founding Runner', 'run-the-seas');

        $runner_number = trim((string) ($participant->founding_runner_number ?? ''));
        if ($runner_number === '') {
            $runner_number = str_pad((string) absint($participant->id ?? 0), 7, '0', STR_PAD_LEFT);
        } else {
            $runner_number = ltrim($runner_number, '#');
            if (ctype_digit($runner_number)) {
                $runner_number = str_pad($runner_number, 7, '0', STR_PAD_LEFT);
            }
        }
        $runner_number = '#' . $runner_number;

        $certificate_number = trim((string) ($participant->certificate_number ?? ''));
        $certificate_number = $certificate_number !== '' ? $certificate_number : __('Pending', 'run-the-seas');

        $issued_at = trim((string) ($participant->certificate_issued_at ?? ''));
        $issued_date = __('Pending verification', 'run-the-seas');
        if ($issued_at !== '') {
            $timestamp = strtotime($issued_at);
            $issued_date = $timestamp ? wp_date('F j, Y', $timestamp) : $issued_at;
        }

        $credit_status = sanitize_key((string) ($participant->cabin_credit_status ?? ''));
        if ('redeemed' === $credit_status) {
            $status = 'REDEEMED';
        } elseif (in_array($credit_status, array('void', 'cancelled', 'canceled', 'declined', 'rejected'), true)) {
            $status = 'VOID';
        } elseif ('approved' === $credit_status || ($issued_at !== '' && (int) ($participant->email_verified ?? 0) === 1)) {
            $status = 'APPROVED';
        } else {
            // NOT REQUESTED is an administrative state and is never printed.
            $status = 'PENDING';
        }

        return array(
            'name' => $name,
            'runner_number' => $runner_number,
            'certificate_number' => $certificate_number,
            'status' => $status,
            'issued_date' => $issued_date,
            'approved' => 'APPROVED' === $status,
        );
    }

    /** Draw all fields onto the approved blank backplate using sample-v4 positioning. */
    private function render_certificate_personalisation($certificate, $participant)
    {
        $width = imagesx($certificate);
        $height = imagesy($certificate);
        $values = $this->get_certificate_personalisation($participant);
        $navy = imagecolorallocate($certificate, 7, 27, 59);
        $muted_navy = imagecolorallocate($certificate, 44, 65, 86);
        $white = imagecolorallocate($certificate, 255, 255, 255);
        $status_colour = $values['approved']
            ? imagecolorallocate($certificate, 45, 153, 91)
            : imagecolorallocate($certificate, 190, 126, 18);
        $name_font = $this->get_verification_preview_font_path('name');
        $bold_font = $this->get_verification_preview_font_path('number');
        $regular_font = $this->get_verification_preview_font_path('date');
        $has_freetype = function_exists('imagettftext') && function_exists('imagettfbbox');

        if ($name_font && $has_freetype) {
            $this->draw_verification_preview_text(
                $certificate,
                $values['name'],
                $name_font,
                max(23, (int) round($width * 0.026)),
                (int) round($width * 0.490),
                (int) round($height * 0.435),
                $navy,
                (int) round($width * 0.54),
                14
            );
        } else {
            $this->draw_verification_preview_bitmap_text(
                $certificate,
                $values['name'],
                (int) round($width * 0.468),
                (int) round($height * 0.414),
                $navy,
                max(1.25, $width / 760),
                (int) round($width * 0.54)
            );
        }

        $left = (int) round($width * 0.065);
        $small_size = max(9, (int) round($width * 0.0084));
        $value_size = max(14, (int) round($width * 0.0135));
        if ($regular_font && $bold_font && $has_freetype) {
            $this->draw_verification_preview_text_left($certificate, __('FOUNDING RUNNER NUMBER', 'run-the-seas'), $regular_font, $small_size, $left, (int) round($height * 0.713), $muted_navy, (int) round($width * 0.26));
            $this->draw_verification_preview_text_left($certificate, $values['runner_number'], $bold_font, $value_size, $left, (int) round($height * 0.746), $navy, (int) round($width * 0.22));
            $this->draw_verification_preview_text_left($certificate, __('CERTIFICATE NO.', 'run-the-seas'), $regular_font, $small_size, $left, (int) round($height * 0.779), $muted_navy, (int) round($width * 0.09));
            $this->draw_verification_preview_text_left($certificate, $values['certificate_number'], $bold_font, $small_size, (int) round($width * 0.172), (int) round($height * 0.779), $navy, (int) round($width * 0.14), 7);
            $this->draw_certificate_status_badge($certificate, $values['status'], $bold_font, max(8, $small_size - 1), (int) round($width * 0.316), (int) round($height * 0.765), $status_colour, $white);
            $this->draw_verification_preview_text_left($certificate, __('ISSUED:', 'run-the-seas'), $regular_font, $small_size, $left, (int) round($height * 0.811), $muted_navy, (int) round($width * 0.05));
            $this->draw_verification_preview_text_left($certificate, $values['issued_date'], $bold_font, $small_size, (int) round($width * 0.116), (int) round($height * 0.811), $navy, (int) round($width * 0.19), 7);
        } else {
            imagestring($certificate, 2, $left, (int) round($height * 0.700), __('FOUNDING RUNNER NUMBER', 'run-the-seas'), $muted_navy);
            imagestring($certificate, 5, $left, (int) round($height * 0.720), $values['runner_number'], $navy);
            imagestring($certificate, 2, $left, (int) round($height * 0.754), __('CERTIFICATE NO. ', 'run-the-seas') . $values['certificate_number'], $navy);
            imagestring($certificate, 2, $left, (int) round($height * 0.780), __('ISSUED: ', 'run-the-seas') . $values['issued_date'], $navy);
        }
    }

    /**
     * Bake an issued date into a locally uploaded certificate image.
     * Coordinates are percentages so the shortcode remains responsive to the
     * original artwork dimensions while the returned file stays a real image.
     */
    public function get_dated_certificate_image_url($source_url, $date_text, $position_x = 74.5, $position_y = 80)
    {
        $source_url = esc_url_raw((string) $source_url);
        $date_text = trim(wp_strip_all_tags((string) $date_text));
        if (
            $source_url === ''
            || $date_text === ''
            || !function_exists('imagecreatetruecolor')
            || !function_exists('imagepng')
        ) {
            return $source_url;
        }

        $source_path = $this->get_verification_preview_source_path($source_url);
        if (!$source_path || !is_readable($source_path)) {
            return $source_url;
        }

        $certificate = $this->create_certificate_image_resource($source_path);
        if (!$certificate) {
            return $source_url;
        }

        $uploads = wp_upload_dir();
        $directory = trailingslashit($uploads['basedir']) . 'rts-certificate-previews/';
        if (!empty($uploads['error']) || !wp_mkdir_p($directory)) {
            imagedestroy($certificate);
            return $source_url;
        }

        $position_x = min(95, max(5, (float) $position_x));
        $position_y = min(95, max(5, (float) $position_y));
        $cache_key = md5('issued-date-v3|' . $source_path . '|' . filemtime($source_path) . '|' . $date_text . '|' . $position_x . '|' . $position_y);
        $filename = 'certificate-issued-date-' . $cache_key . '.png';
        $output_path = $directory . $filename;
        $output_url = trailingslashit($uploads['baseurl']) . 'rts-certificate-previews/' . $filename;
        if (is_readable($output_path)) {
            imagedestroy($certificate);
            return $output_url;
        }

        $width = imagesx($certificate);
        $height = imagesy($certificate);
        imagealphablending($certificate, true);
        if (function_exists('imageantialias')) {
            imageantialias($certificate, true);
        }

        $navy = imagecolorallocate($certificate, 7, 27, 59);
        $font_path = $this->get_verification_preview_font_path('date');
        $center_x = (int) round($width * ($position_x / 100));
        $baseline_y = (int) round($height * ($position_y / 100));
        $max_width = (int) round($width * 0.20);
        if ($font_path && function_exists('imagettftext') && function_exists('imagettfbbox')) {
            $this->draw_verification_preview_text(
                $certificate,
                $date_text,
                $font_path,
                max(12, (int) round($width * 0.009)),
                $center_x,
                $baseline_y,
                $navy,
                $max_width,
                9
            );
        } else {
            $this->draw_verification_preview_bitmap_text(
                $certificate,
                $date_text,
                $center_x,
                (int) round($baseline_y - ($height * 0.018)),
                $navy,
                max(1, $width / 1450),
                $max_width
            );
        }

        $saved = imagepng($certificate, $output_path, 9);
        imagedestroy($certificate);
        return $saved ? $output_url : $source_url;
    }

    /** Build an editable copy from the exact production verification renderer. */
    public function get_verification_email_template_definition()
    {
        $participant = (object) array(
            'id' => 0,
            'first_name' => 'RTS_FIRST_NAME',
            'last_name' => 'RTS_LAST_NAME',
            'email' => 'RTS_EMAIL',
            'certificate_number' => '',
            'founding_runner_number' => '',
        );
        $verification_url = 'https://rts-template.invalid/verification-url';
        $preview_url = $this->get_email_certificate_preview_url($participant, 'rts_verification_email_design_assets');
        $html = $this->get_verification_email_message($participant, $verification_url);
        $html = str_replace(
            array('RTS_FIRST_NAME RTS_LAST_NAME', $verification_url, $preview_url),
            array('{full_name}', '{verification_url}', '{certificate_preview_url}'),
            $html
        );
        $assets = get_option('rts_verification_email_design_assets', array());
        foreach (is_array($assets) ? $assets : array() as $key => $url) {
            if ('certificate_preview_image' === $key || empty($url)) {
                continue;
            }
            $html = str_replace(
                esc_url($url),
                '{verification_' . sanitize_key($key) . '}',
                $html
            );
        }
        return array(
            'template_key' => 'default_email_verification',
            'action_key' => 'email_verification',
            'name' => 'Email Verification',
            'subject' => 'Confirm Your Email Address | Run The Seas',
            'html_body' => $html,
        );
    }

    /** Build an editable copy from the exact production certificate renderer. */
    public function get_certificate_email_template_definition()
    {
        $participant = (object) array(
            'id' => 0,
            'first_name' => 'RTS_FIRST_NAME',
            'last_name' => 'RTS_LAST_NAME',
            'email' => 'RTS_EMAIL',
            'certificate_number' => 'RTS_CERTIFICATE_NUMBER',
            'founding_runner_number' => 'RTS_FOUNDING_RUNNER_NUMBER',
        );
        $captains_suite_url = 'https://rts-template.invalid/captains-suite-url';
        $preview_url = $this->get_email_certificate_preview_url($participant, 'rts_certificate_email_design_assets');
        $html = $this->get_certificate_email_message($participant, $captains_suite_url);
        $html = str_replace(
            array('RTS_FIRST_NAME', 'RTS_LAST_NAME', 'RTS_CERTIFICATE_NUMBER', 'RTS_FOUNDING_RUNNER_NUMBER', $captains_suite_url, $preview_url),
            array('{first_name}', '{last_name}', '{certificate_number}', '{founding_runner_number}', '{captains_suite_url}', '{certificate_preview_url}'),
            $html
        );
        $assets = get_option('rts_certificate_email_design_assets', array());
        foreach (is_array($assets) ? $assets : array() as $key => $url) {
            if ('certificate_preview_image' === $key || empty($url)) {
                continue;
            }
            $html = str_replace(
                esc_url($url),
                '{certificate_' . sanitize_key($key) . '}',
                $html
            );
        }
        return array(
            'template_key' => 'default_founding_runner_certificate',
            'action_key' => 'founding_runner_certificate',
            'name' => 'Founding Runner Certificate',
            'subject' => 'Your Run The Seas Founding Runner Certificate',
            'html_body' => $html,
        );
    }

    /**
     * Stream a signed participant certificate when a generated preview file
     * could not be persisted in the uploads directory.
     */
    public function serve_certificate_preview_image()
    {
        if (empty($_GET['rts_certificate_preview'])) {
            return;
        }

        $participant_id = isset($_GET['rts_participant'])
            ? absint(wp_unslash($_GET['rts_participant']))
            : 0;
        $design = isset($_GET['rts_design'])
            ? sanitize_key(wp_unslash($_GET['rts_design']))
            : '';
        $signature = isset($_GET['rts_signature'])
            ? sanitize_text_field(wp_unslash($_GET['rts_signature']))
            : '';
        $option_names = array(
            'verification' => 'rts_verification_email_design_assets',
            'certificate' => 'rts_certificate_email_design_assets',
        );

        if (
            $participant_id < 1
            || !isset($option_names[$design])
            || $signature === ''
            || !hash_equals($this->get_certificate_preview_signature($participant_id, $design), $signature)
        ) {
            status_header(403);
            exit;
        }

        $participant = $this->get_participant($participant_id);
        if (!$participant) {
            status_header(404);
            exit;
        }

        $source_url = $this->get_certificate_backplate_url($option_names[$design]);
        $source_path = $this->get_verification_preview_source_path($source_url);
        $certificate = $source_path && is_readable($source_path)
            ? $this->create_certificate_image_resource($source_path)
            : false;

        // A stale or externally hosted design URL must not turn the email back
        // into an empty certificate. The bundled approved backplate is always
        // available as a local rendering fallback.
        if (!$certificate) {
            $source_path = RTS_PLUGIN_PATH . 'assets/certificate-template.jpg';
            $certificate = is_readable($source_path)
                ? $this->create_certificate_image_resource($source_path)
                : false;
        }
        if (!$certificate || !function_exists('imagepng')) {
            status_header(404);
            exit;
        }

        imagealphablending($certificate, true);
        if (function_exists('imageantialias')) {
            imageantialias($certificate, true);
        }
        $this->render_certificate_personalisation($certificate, $participant);

        nocache_headers();
        header('Content-Type: image/png');
        header('Content-Disposition: inline; filename="founding-runner-certificate-' . $participant_id . '.png"');
        imagepng($certificate);
        imagedestroy($certificate);
        exit;
    }

    /** Create the HMAC used by the public image fallback endpoint. */
    private function get_certificate_preview_signature($participant_id, $design)
    {
        return hash_hmac(
            'sha256',
            absint($participant_id) . '|' . sanitize_key((string) $design),
            wp_salt('auth')
        );
    }

    /** Return a signed image URL only for real recipient email previews. */
    private function get_dynamic_certificate_preview_url($participant, $option_name)
    {
        $participant_id = absint($participant->id ?? 0);
        if ($participant_id < 1) {
            return '';
        }

        if ('rts_verification_email_design_assets' === $option_name) {
            $design = 'verification';
        } elseif ('rts_certificate_email_design_assets' === $option_name) {
            $design = 'certificate';
        } else {
            return '';
        }

        return add_query_arg(
            array(
                'rts_certificate_preview' => '1',
                'rts_participant' => $participant_id,
                'rts_design' => $design,
                'rts_signature' => $this->get_certificate_preview_signature($participant_id, $design),
            ),
            home_url('/')
        );
    }

    /**
     * Create an image-only, personalised certificate preview for email. It
     * uses the shared artwork but never creates a PDF or attachment. When the
     * PNG cannot be persisted, a signed render-on-request URL is returned.
     */
    private function get_verification_certificate_preview_url($participant, $source_url, $option_name = '')
    {
        if (
            !function_exists('imagepng')
            || empty($participant->id)
            || empty($source_url)
        ) {
            return $source_url;
        }

        $source_path = $this->get_verification_preview_source_path($source_url);
        if (!$source_path || !is_readable($source_path)) {
            if ($option_name === '') {
                return $source_url;
            }
            $source_path = RTS_PLUGIN_PATH . 'assets/certificate-template.jpg';
        }

        $certificate = is_readable($source_path)
            ? $this->create_certificate_image_resource($source_path)
            : false;
        if (!$certificate) {
            return $source_url;
        }

        $uploads = wp_upload_dir();
        $directory = trailingslashit($uploads['basedir']) . 'rts-certificate-previews/';
        if (!empty($uploads['error']) || !wp_mkdir_p($directory)) {
            imagedestroy($certificate);
            $dynamic_url = $this->get_dynamic_certificate_preview_url($participant, $option_name);
            return $dynamic_url !== '' ? $dynamic_url : $source_url;
        }

        $name = trim((string) ($participant->first_name ?? '') . ' ' . (string) ($participant->last_name ?? ''));
        if ($name === '') {
            imagedestroy($certificate);
            return $source_url;
        }
        $values = $this->get_certificate_personalisation($participant);
        $cache_key = md5('v23-real-participant|' . $source_path . '|' . filemtime($source_path) . '|' . wp_json_encode($values));
        $filename = 'verification-preview-' . absint($participant->id) . '-' . $cache_key . '.png';
        $preview_path = $directory . $filename;
        $preview_url = trailingslashit($uploads['baseurl']) . 'rts-certificate-previews/' . $filename;
        if (is_readable($preview_path)) {
            imagedestroy($certificate);
            return $preview_url;
        }

        imagealphablending($certificate, true);
        if (function_exists('imageantialias')) {
            imageantialias($certificate, true);
        }
        $this->render_certificate_personalisation($certificate, $participant);
        $saved = @imagepng($certificate, $preview_path, 9);
        imagedestroy($certificate);

        if ($saved) {
            return $preview_url;
        }

        $dynamic_url = $this->get_dynamic_certificate_preview_url($participant, $option_name);
        error_log('RTS: Certificate preview could not be saved to ' . $preview_path
            . ($dynamic_url !== '' ? '; using signed image endpoint.' : '; using template artwork with email personalization.'));
        return $dynamic_url !== '' ? $dynamic_url : $source_url;
    }

    /** Find separate portable fonts for the certificate name and number. */
    private function get_verification_preview_font_path($field = 'name')
    {
        $inter_font = defined('WP_CONTENT_DIR')
            ? WP_CONTENT_DIR . '/plugins/wb-gamification/assets/fonts/Inter.ttf'
            : '';
        if ('number' === $field) {
            $candidates = array(
                RTS_PLUGIN_PATH . 'assets/fonts/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
                'C:/Windows/Fonts/arialbd.ttf',
                $inter_font,
            );
        } elseif ('date' === $field) {
            $candidates = array(
                RTS_PLUGIN_PATH . 'assets/fonts/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
                'C:/Windows/Fonts/arial.ttf',
                $inter_font,
            );
        } else {
            $candidates = array(
                RTS_PLUGIN_PATH . 'assets/fonts/CormorantGaramond-SemiBoldItalic.ttf',
                RTS_PLUGIN_PATH . 'assets/fonts/Allura-Regular.ttf',
                RTS_PLUGIN_PATH . 'assets/fonts/DejaVuSerif-Italic.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Italic.ttf',
                '/usr/share/fonts/truetype/liberation2/LiberationSerif-Italic.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSerif-Italic.ttf',
                'C:/Windows/Fonts/georgiai.ttf',
                'C:/Windows/Fonts/timesi.ttf',
                $inter_font,
            );
        }
        foreach ($candidates as $font_path) {
            if ($font_path && is_readable($font_path)) {
                return $font_path;
            }
        }
        return '';
    }

    /** Draw centred, fitted TrueType text into a certificate field. */
    private function draw_verification_preview_text($image, $text, $font_path, $font_size, $center_x, $baseline_y, $colour, $max_width, $min_font_size = 8)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return;
        }
        $min_font_size = max(5, (int) $min_font_size);
        $font_size = max($min_font_size, (int) $font_size);
        do {
            $box = imagettfbbox($font_size, 0, $font_path, $text);
            $width = $box ? abs($box[2] - $box[0]) : 0;
            if ($width <= $max_width || $font_size <= $min_font_size) {
                break;
            }
            $font_size--;
        } while ($font_size > $min_font_size);
        if (!$box) {
            return;
        }
        $position_x = (int) round($center_x - ($width / 2) - $box[0]);
        imagettftext($image, $font_size, 0, $position_x, (int) $baseline_y, $colour, $font_path, $text);
    }

    /** Draw left-aligned TrueType text, reducing its size until it fits. */
    private function draw_verification_preview_text_left($image, $text, $font_path, $font_size, $left_x, $baseline_y, $colour, $max_width, $min_font_size = 6)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return;
        }

        $font_size = max($min_font_size, (int) $font_size);
        do {
            $box = imagettfbbox($font_size, 0, $font_path, $text);
            $text_width = $box ? abs($box[2] - $box[0]) : 0;
            if ($text_width <= $max_width || $font_size <= $min_font_size) {
                break;
            }
            $font_size--;
        } while ($font_size > $min_font_size);

        if ($box) {
            imagettftext($image, $font_size, 0, (int) $left_x - $box[0], (int) $baseline_y, $colour, $font_path, $text);
        }
    }

    /** Draw the compact APPROVED/PENDING pill shown beside the certificate number. */
    private function draw_certificate_status_badge($image, $text, $font_path, $font_size, $left_x, $top_y, $background, $foreground)
    {
        $text = trim((string) $text);
        if ($text === '' || !$font_path || !function_exists('imagettfbbox') || !function_exists('imagettftext')) {
            return;
        }

        $font_size = max(6, (int) $font_size);
        $box = imagettfbbox($font_size, 0, $font_path, $text);
        if (!$box) {
            return;
        }

        $text_width = abs($box[2] - $box[0]);
        $text_height = abs($box[7] - $box[1]);
        $padding_x = max(5, (int) round($font_size * 0.65));
        $padding_y = max(3, (int) round($font_size * 0.42));
        $badge_width = $text_width + ($padding_x * 2);
        $badge_height = $text_height + ($padding_y * 2);
        $radius = (int) floor($badge_height / 2);
        $center_y = (int) $top_y + $radius;

        imagefilledrectangle($image, (int) $left_x + $radius, (int) $top_y, (int) $left_x + $badge_width - $radius, (int) $top_y + $badge_height, $background);
        imagefilledellipse($image, (int) $left_x + $radius, $center_y, $badge_height, $badge_height, $background);
        imagefilledellipse($image, (int) $left_x + $badge_width - $radius, $center_y, $badge_height, $badge_height, $background);

        $text_x = (int) $left_x + $padding_x - $box[0];
        $text_y = (int) $top_y + $padding_y - $box[7];
        imagettftext($image, $font_size, 0, $text_x, $text_y, $foreground, $font_path, $text);
    }

    /** Last-resort text rendering when FreeType fonts are unavailable. */
    private function draw_verification_preview_bitmap_text($image, $text, $center_x, $position_y, $colour, $scale, $max_width = 0)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return;
        }

        $font = 5;
        $text_width = imagefontwidth($font) * strlen($text);
        $text_height = imagefontheight($font);
        if ($max_width > 0 && ($text_width * $scale) > $max_width) {
            $scale = max(0.5, $max_width / max(1, $text_width));
        }

        // Render a small monochrome mask, then copy only glyph pixels. This
        // avoids the opaque rectangle some GD builds produce when resampling
        // a transparent temporary image.
        $text_image = imagecreatetruecolor($text_width, $text_height);
        $mask_background = imagecolorallocate($text_image, 255, 255, 255);
        $mask_foreground = imagecolorallocate($text_image, 0, 0, 0);
        imagefill($text_image, 0, 0, $mask_background);
        imagestring($text_image, $font, 0, 0, $text, $mask_foreground);

        $scaled_width = max(1, (int) round($text_width * $scale));
        $position_x = (int) round($center_x - ($scaled_width / 2));
        for ($source_y = 0; $source_y < $text_height; $source_y++) {
            for ($source_x = 0; $source_x < $text_width; $source_x++) {
                if (imagecolorat($text_image, $source_x, $source_y) === $mask_background) {
                    continue;
                }
                $left = $position_x + (int) floor($source_x * $scale);
                $top = (int) $position_y + (int) floor($source_y * $scale);
                $right = $position_x + (int) ceil(($source_x + 1) * $scale) - 1;
                $bottom = (int) $position_y + (int) ceil(($source_y + 1) * $scale) - 1;
                imagefilledrectangle($image, $left, $top, $right, $bottom, $colour);
            }
        }
        imagedestroy($text_image);
    }

    /** Resolve a Media Library or bundled certificate image URL to its file path. */
    private function get_verification_preview_source_path($source_url)
    {
        // Cache-busting query strings are not part of the Media Library file.
        $source_url = preg_replace('/[?#].*$/', '', (string) $source_url);
        $bundled_url = RTS_PLUGIN_URL . 'assets/certificate-template.jpg';
        if (untrailingslashit($source_url) === untrailingslashit($bundled_url)) {
            return RTS_PLUGIN_PATH . 'assets/certificate-template.jpg';
        }

        // Permit other bundled certificate artwork, including the dedicated
        // registration-page sample, while keeping the resolved path inside
        // this plugin directory.
        $plugin_url = trailingslashit(RTS_PLUGIN_URL);
        if (0 === strpos($source_url, $plugin_url)) {
            $relative_path = ltrim(rawurldecode(substr($source_url, strlen($plugin_url))), '/');
            $candidate_path = realpath(RTS_PLUGIN_PATH . $relative_path);
            $plugin_path = realpath(RTS_PLUGIN_PATH);
            if (
                $candidate_path
                && $plugin_path
                && 0 === stripos(
                    wp_normalize_path($candidate_path),
                    trailingslashit(wp_normalize_path($plugin_path))
                )
            ) {
                return $candidate_path;
            }
        }

        $attachment_id = attachment_url_to_postid($source_url);
        if ($attachment_id) {
            $attachment_path = get_attached_file($attachment_id);
            if ($attachment_path) {
                return $attachment_path;
            }
        }

        $uploads = wp_upload_dir();
        $base_url = trailingslashit($uploads['baseurl']);
        if (0 === strpos($source_url, $base_url)) {
            return trailingslashit($uploads['basedir']) . ltrim(substr($source_url, strlen($base_url)), '/');
        }

        // Media URLs can retain a previous domain or a different HTTP scheme
        // after a site migration. Resolve matching upload paths locally while
        // still ensuring the resulting file remains inside the uploads folder.
        $source_url_path = wp_parse_url($source_url, PHP_URL_PATH);
        $base_url_path = wp_parse_url($base_url, PHP_URL_PATH);
        if (
            is_string($source_url_path)
            && is_string($base_url_path)
            && 0 === strpos($source_url_path, trailingslashit($base_url_path))
        ) {
            $relative_path = ltrim(rawurldecode(substr($source_url_path, strlen(trailingslashit($base_url_path)))), '/');
            $candidate_path = realpath(trailingslashit($uploads['basedir']) . $relative_path);
            $uploads_path = realpath($uploads['basedir']);
            if (
                $candidate_path
                && $uploads_path
                && 0 === stripos(
                    wp_normalize_path($candidate_path),
                    trailingslashit(wp_normalize_path($uploads_path))
                )
            ) {
                return $candidate_path;
            }
        }

        return '';
    }

    /**
     * Send confirmation email
     */
    public function send_confirmation_email($email, $data)
    {
        $subject = 'Registration Confirmation - Founding Runner';
        $message = "Hello {$data['first_name']} {$data['last_name']},\n\n";
        $message .= "Thank you for registering as a Founding Runner!\n\n";

        if (isset($data['request_cabin_credit']) && $data['request_cabin_credit'] === 'Yes') {
            $message .= "Your Cabin Credit request has been received and is being processed.\n";
            $message .= "You will receive a confirmation once your Cabin Credit is approved.\n\n";
        }

        $participant = $this->get_participant_by_email($email);
        if ($participant && $participant->referral_code) {
            $message .= "Your Referral Code: " . $participant->referral_code . "\n\n";
            $message .= "Share your referral code with friends and earn rewards!\n\n";
        }

        $message .= "Best regards,\nThe Run The Seas Team";

        $headers = rts_mail_headers('text/plain; charset=UTF-8');

        return wp_mail($email, $subject, $message, $headers);
    }

    /**
     * Send cabin credit notification
     */
    private function send_cabin_credit_notification($participant_id)
    {
        $participant = $this->get_participant($participant_id);

        if (!$participant) {
            return false;
        }

        $subject = 'Cabin Credit Request Received - Founding Runner';
        $message = "Hello {$participant->first_name} {$participant->last_name},\n\n";
        $message .= "Your Founding Runner Cabin Credit request has been received.\n";
        $message .= "Cabin Credit Number: {$participant->cabin_credit_number}\n";
        $message .= "Status: Pending Approval\n\n";
        $message .= "You will receive a confirmation once your Cabin Credit is approved.\n\n";
        $message .= "Best regards,\nThe Run The Seas Team";

        $headers = rts_mail_headers('text/plain; charset=UTF-8');

        return wp_mail($participant->email, $subject, $message, $headers);
    }

    /**
     * Email verification handler
     */
    private function redirect_to_first_passcode_if_required($participant)
    {
        $user_id = !empty($participant->user_id) ? absint($participant->user_id) : 0;
        if (
            !$user_id
            || '1' !== (string) get_user_meta($user_id, 'rts_temp_password', true)
            || !function_exists('rts_get_first_passcode_setup_url')
        ) {
            return false;
        }

        $setup_url = rts_get_first_passcode_setup_url($user_id);
        if (is_wp_error($setup_url)) {
            error_log('RTS: Could not create first-passcode URL for user ' . $user_id . ': ' . $setup_url->get_error_message());
            return false;
        }

        $this->log_timeline(
            absint($participant->id),
            'passcode_setup_started',
            'Verified member continued to first-visit passcode creation'
        );
        if (wp_safe_redirect($setup_url)) {
            exit;
        }
        return false;
    }

    public function verify_email_handler()
    {
        if (!isset($_GET['rts_verify_email']) || !isset($_GET['token']) || !isset($_GET['email'])) {
            return;
        }

        $email = sanitize_email($_GET['email']);
        $token = sanitize_text_field($_GET['token']);

        // Check if already verified to prevent duplicate processing
        $verified_participant = $this->db->get_row(
            $this->db->prepare(
                "SELECT id, user_id, email_verified FROM {$this->db->prefix}rts_participants 
                WHERE email = %s AND email_verification_token = %s",
                $email,
                $token
            )
        );

        // If already verified, show success message without reprocessing
        if ($verified_participant && (int) $verified_participant->email_verified === 1) {
            $this->redirect_to_first_passcode_if_required($verified_participant);
            wp_die(
                '<h1>Email Already Verified!</h1>
                <p>Your email has already been verified. You can now enjoy all the benefits of being a Founding Runner.</p>
                <p><a href="/captains-suite">Go to Captain\'s Suite →</a></p>',
                'Already Verified'
            );
            return;
        }

        // Find participant by email and token
        $participant = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}rts_participants 
                WHERE email = %s AND email_verification_token = %s AND email_verified = 0",
                $email,
                $token
            )
        );

        if ($participant) {
            $referrer_notification_id = 0;
            // Use a transaction to prevent race conditions
            $this->db->query('START TRANSACTION');

            try {
                // Verify email - only if not already verified
                $updated = $this->db->update(
                    $this->db->prefix . 'rts_participants',
                    array(
                        'email_verified' => 1,
                        'email_verification_date' => current_time('mysql'),
                        'referral_completed' => 1,
                        'referral_completed_date' => current_time('mysql')
                    ),
                    array(
                        'id' => $participant->id,
                        'email_verified' => 0 // Only update if not already verified
                    )
                );

                // If no rows were updated, it was already verified
                if ($updated === 0) {
                    $this->db->query('COMMIT');
                    $this->redirect_to_first_passcode_if_required((object) array(
                        'id' => $participant->id,
                        'user_id' => $participant->user_id,
                    ));
                    wp_die(
                        '<h1>Email Already Verified!</h1>
                        <p>Your email has already been verified.</p>
                        <p><a href="/captains-suite">Go to Captain\'s Suite →</a></p>',
                        'Already Verified'
                    );
                    return;
                }

                // If this participant was referred by someone, update the referral
                if ($participant->referred_by > 0) {
                    // Check if referral already completed (prevent double processing)
                    $referral_exists = $this->db->get_var(
                        $this->db->prepare(
                            "SELECT id FROM {$this->db->prefix}rts_referrals 
                            WHERE referred_participant_id = %d AND status = 'completed'",
                            $participant->id
                        )
                    );

                    // Only process if not already completed
                    if (!$referral_exists) {
                        // Find the pending referral
                        $referral_id = $this->db->get_var(
                            $this->db->prepare(
                                "SELECT id FROM {$this->db->prefix}rts_referrals 
                                WHERE referred_participant_id = %d AND status = 'pending'",
                                $participant->id
                            )
                        );

                        if ($referral_id) {
                            // Update the referral to completed
                            $this->db->update(
                                $this->db->prefix . 'rts_referrals',
                                array(
                                    'status' => 'completed',
                                    'completed_date' => current_time('mysql'),
                                    'bonus_earned' => 1000
                                ),
                                array('id' => $referral_id)
                            );

                            // Add 1K miles to referrer
                            $this->add_captain_miles(
                                $participant->referred_by,
                                1000,
                                'Referral completed: ' . $participant->email
                            );

                            // Update referrer's successful referrals count
                            $this->db->query(
                                $this->db->prepare(
                                    "UPDATE {$this->db->prefix}rts_participants 
                                    SET successful_referrals = successful_referrals + 1,
                                        total_referral_bonus = total_referral_bonus + 1000
                                    WHERE id = %d",
                                    $participant->referred_by
                                )
                            );
                            $referrer_notification_id = (int) $participant->referred_by;

                            // Trigger trophy check for referrer
                            do_action('rts_referral_completed', $participant->referred_by, 1000);

                            error_log('RTS: Referral completed for participant ' . $participant->id . ' by referrer ' . $participant->referred_by);
                        } else {
                            error_log('RTS: No pending referral found for participant ' . $participant->id);
                        }
                    } else {
                        error_log('RTS: Referral already completed for participant ' . $participant->id);
                    }
                }

                $this->db->query('COMMIT');

                if (!empty($participant->user_id)) {
                    update_user_meta((int) $participant->user_id, 'rts_email_verified', '1');
                }

                if ($referrer_notification_id) {
                    $this->send_referral_progress_notification($referrer_notification_id);
                }

                // Add achievement (check if already exists)
                $achievement_exists = $this->db->get_var(
                    $this->db->prepare(
                        "SELECT id FROM {$this->db->prefix}rts_achievements 
                        WHERE participant_id = %d AND achievement_type = 'email_verified'",
                        $participant->id
                    )
                );

                if (!$achievement_exists) {
                    $this->add_achievement(
                        $participant->id,
                        'email_verified',
                        'Email Verified!',
                        'Your email address has been verified successfully.'
                    );
                }

                // Log timeline
                $this->log_timeline(
                    $participant->id,
                    'email_verified',
                    'Email address verified'
                );

                // Trophy fulfilment listens to the same authoritative event as
                // Captain's Suite activation. This awards Founding Runner and
                // any distance milestones that were pending verification.
                do_action('rts_participant_verified', $participant->id);

                // Verification is the fulfilment event: it activates the
                // Captain's Suite, issues the $100 credit, and emails the PDF.
                $benefits = $this->activate_verified_benefits($participant->id, 0, true);
                $benefits_held_for_review = is_wp_error($benefits) && $benefits->get_error_code() === 'rts_duplicate_review_required';
                if (is_wp_error($benefits)) {
                    error_log('RTS: Email was verified but benefit fulfilment failed for participant ' . $participant->id . ': ' . $benefits->get_error_message());
                }

                // A newly created account still has only its inaccessible
                // random bootstrap password. Continue directly to the branded
                // one-time passcode creation form instead of the login screen.
                $this->redirect_to_first_passcode_if_required($participant);

                // Show success message
                wp_die(
                    '<h1>Email Verified!</h1>
                    <p>Your email has been verified successfully.</p>'
                        . ($benefits_held_for_review
                            ? '<p>Your registration needs a short duplicate-response review. Your Cabin Credit and Captain\'s Suite will be activated after a Run The Seas administrator approves it.</p>'
                            : '<p>Your Captain\'s Suite is active and your $100 Promotional Cruise Credit has been issued.</p><p><a href="/captains-suite">Go to Captain\'s Suite →</a></p>'),
                    'Verification Success'
                );
            } catch (Exception $e) {
                $this->db->query('ROLLBACK');
                error_log('RTS: Error in email verification: ' . $e->getMessage());
                wp_die(
                    '<h1>Verification Error</h1>
                    <p>There was an error verifying your email. Please contact support.</p>',
                    'Verification Error'
                );
            }
        } else {
            wp_die(
                '<h1>Verification Failed</h1>
                <p>Invalid verification link. Please contact support.</p>',
                'Verification Failed'
            );
        }
    }

    /**
     * Get participant by ID
     */
    public function get_participant($participant_id)
    {
        return $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}rts_participants WHERE id = %d",
                $participant_id
            )
        );
    }

    /**
     * Get participant by email
     */
    public function get_participant_by_email($email)
    {
        return $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}rts_participants WHERE email = %s",
                $email
            )
        );
    }

    /** Get the participant linked to a WordPress user ID. */
    public function get_participant_by_user_id($user_id)
    {
        $user_id = absint($user_id);
        if (!$user_id) {
            return null;
        }

        return $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}rts_participants WHERE user_id = %d LIMIT 1",
                $user_id
            )
        );
    }

    /**
     * Resolve a member by their permanent user link before trying email.
     * Email is editable; user_id is the stable account relationship.
     */
    public function get_participant_for_user($user)
    {
        if (is_numeric($user)) {
            $user = get_userdata(absint($user));
        }
        if (!$user instanceof WP_User) {
            return null;
        }

        $participant = $this->get_participant_by_user_id($user->ID);
        if ($participant) {
            return $participant;
        }

        return !empty($user->user_email)
            ? $this->get_participant_by_email($user->user_email)
            : null;
    }

    /** Keep the member's changed email consistent across Run The Seas records. */
    public function sync_participant_email($participant_id, $old_email, $new_email)
    {
        $old_email = sanitize_email($old_email);
        $new_email = sanitize_email($new_email);
        if (!$participant_id || !$old_email || !$new_email || strtolower($old_email) === strtolower($new_email)) {
            return;
        }

        $this->db->update(
            $this->db->prefix . 'rts_survey_tracking',
            array('email' => $new_email),
            array('email' => $old_email),
            array('%s'),
            array('%s')
        );
        $this->db->update(
            $this->db->prefix . 'rts_referrals',
            array('referred_email' => $new_email),
            array('referred_participant_id' => $participant_id),
            array('%s'),
            array('%d')
        );
        $this->log_timeline($participant_id, 'email_updated', 'Participant email updated across Run The Seas records');
    }

    /**
     * Add achievement
     */
    public function add_achievement($participant_id, $type, $name, $description)
    {
        $inserted = $this->db->insert(
            $this->db->prefix . 'rts_achievements',
            array(
                'participant_id' => $participant_id,
                'achievement_type' => $type,
                'achievement_name' => $name,
                'achievement_description' => $description,
                'achievement_date' => current_time('mysql'),
                'is_displayed' => 1,
                'created_at' => current_time('mysql')
            )
        );

        if ($inserted !== false) {
            error_log("RTS: Added achievement for participant {$participant_id}: {$name}");
        }
    }

    /**
     * Add medal
     */
    public function add_medal($participant_id, $type, $name, $description, $event_name = '', $rank = '')
    {
        $inserted = $this->db->insert(
            $this->db->prefix . 'rts_medals',
            array(
                'participant_id' => $participant_id,
                'medal_type' => $type,
                'medal_name' => $name,
                'medal_description' => $description,
                'medal_image_url' => '',
                'earned_date' => current_time('mysql'),
                'event_name' => $event_name,
                'medal_rank' => $rank,
                'is_displayed' => 1,
                'created_at' => current_time('mysql')
            )
        );

        if ($inserted !== false) {
            error_log("RTS: Added medal for participant {$participant_id}: {$name}");
        }
    }

    /**
     * Log timeline entry
     */
    public function log_timeline($participant_id, $activity_type, $description, $data = array())
    {
        $this->db->insert(
            $this->db->prefix . 'rts_timeline',
            array(
                'participant_id' => $participant_id,
                'activity_type' => $activity_type,
                'activity_description' => $description,
                'activity_data' => json_encode($data),
                'activity_date' => current_time('mysql'),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'created_at' => current_time('mysql')
            )
        );
    }

    /**
     * Add distance
     */
    public function add_captain_miles($participant_id, $miles, $reason)
    {
        $participant = $this->get_participant($participant_id);

        if (!$participant) {
            return false;
        }

        $new_balance = $participant->captain_miles_balance + $miles;
        $new_total_earned = $participant->total_captain_miles_earned + $miles;

        $updated = $this->db->update(
            $this->db->prefix . 'rts_participants',
            array(
                'captain_miles_balance' => $new_balance,
                'total_captain_miles_earned' => $new_total_earned,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $participant_id)
        );

        if ($updated !== false) {
            $this->log_timeline(
                $participant_id,
                'captain_miles_earned',
                'Verified referral progress: +' . rts_format_miles($miles),
                array(
                    'miles' => $miles,
                    'reason' => $reason,
                    'previous_total_earned' => absint($participant->total_captain_miles_earned),
                    'new_total_earned' => absint($new_total_earned),
                    'new_balance' => absint($new_balance),
                )
            );

            // Check for achievements based on miles
            $this->check_miles_achievements($participant_id, $new_balance);

            error_log("RTS: Added {$miles} miles to participant {$participant_id}");
            return true;
        }

        return false;
    }

    /**
     * Check miles achievements
     */
    private function check_miles_achievements($participant_id, $miles)
    {
        $milestones = array(100, 500, 1000, 2500, 5000, 10000);

        foreach ($milestones as $milestone) {
            if ($miles >= $milestone) {
                // Check if achievement already exists
                $exists = $this->db->get_var(
                    $this->db->prepare(
                        "SELECT id FROM {$this->db->prefix}rts_achievements 
                         WHERE participant_id = %d AND achievement_type = 'miles_milestone' 
                         AND achievement_name LIKE '%{$milestone}%'",
                        $participant_id
                    )
                );

                if (!$exists) {
                    $this->add_achievement(
                        $participant_id,
                        'miles_milestone',
                        rts_format_trophy_miles($milestone) . ' milestone!',
                        "Congratulations! You've completed " . rts_format_miles($milestone) . " in the 42.2K Referral Marathon Challenge!"
                    );

                    // Add medal for major milestones
                    if (in_array($milestone, array(1000, 5000, 10000))) {
                        $this->add_medal(
                            $participant_id,
                            'miles_milestone',
                            rts_format_trophy_miles($milestone) . ' Milestone Medal',
                            "Awarded for completing " . rts_format_miles($milestone) . " in the 42.2K Referral Marathon Challenge!",
                            '42.2K Referral Marathon Challenge Program',
                            $milestone >= 10000 ? 'Diamond' : ($milestone >= 5000 ? 'Gold' : 'Silver')
                        );
                    }
                }
            }
        }
    }

    /**
     * AJAX: Get participant data
     */
    public function ajax_get_participant_data()
    {
        check_ajax_referer('rts_admin_nonce', 'nonce');
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_send_json_error('You do not have permission to view participant data.', 403);
        }

        if (!isset($_POST['email'])) {
            wp_send_json_error('Email required');
            return;
        }

        $email = sanitize_email($_POST['email']);
        $participant = $this->get_participant_by_email($email);

        if (!$participant) {
            wp_send_json_error('Participant not found');
            return;
        }

        // Get achievements
        $achievements = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}rts_achievements WHERE participant_id = %d",
                $participant->id
            )
        );

        // Get medals
        $medals = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}rts_medals WHERE participant_id = %d",
                $participant->id
            )
        );

        // Get referrals
        $referrals = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}rts_referrals WHERE referrer_id = %d",
                $participant->id
            )
        );

        // Get timeline
        $timeline = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}rts_timeline WHERE participant_id = %d ORDER BY activity_date DESC",
                $participant->id
            )
        );

        wp_send_json_success(array(
            'participant' => $participant,
            'achievements' => $achievements,
            'medals' => $medals,
            'referrals' => $referrals,
            'timeline' => $timeline
        ));
    }

    public function referral_exists($referrer_id, $email)
    {
        return $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM {$this->db->prefix}rts_referrals 
                WHERE referrer_id = %d AND referred_email = %s",
                $referrer_id,
                $email
            )
        );
    }
}

// // Initialize the registration class
// function rts_init_registration() {
//     $tracking = function_exists('rts_init') ? rts_init()->tracking : null;
//     return new RTS_Registration($tracking);
// }
// add_action('plugins_loaded', 'rts_init_registration');
