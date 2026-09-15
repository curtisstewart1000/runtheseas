<?php
class RTS_Tracking
{
    private $db;
    private $session_id;

    public function __construct($database)
    {
        $this->db = $database;
        $this->session_id = $this->get_or_create_session();
    }

    private function get_or_create_session()
    {
        if ($this->has_survey_cookie_consent() && isset($_COOKIE['rts_session_id'])) {
            return sanitize_text_field(wp_unslash($_COOKIE['rts_session_id']));
        }

        $session_id = bin2hex(random_bytes(32));

        // This ID is request-only until the visitor agrees to survey storage.
        return $session_id;
    }

    private function has_survey_cookie_consent()
    {
        return isset($_COOKIE['rts_survey_cookie_consent'])
            && $_COOKIE['rts_survey_cookie_consent'] === 'accepted';
    }

    /**
     * Generate a unique submission ID (UUID v4)
     */
    private function generate_submission_id() {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function submission_id_exists($submission_id) {
        $table_name = $this->db->prefix . 'rts_survey_tracking';
        $exists = $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM $table_name WHERE submission_id = %s",
                $submission_id
            )
        );
        return $exists !== null;
    }

    private function create_unique_submission_id() {
        $max_attempts = 5;
        for ($i = 0; $i < $max_attempts; $i++) {
            $submission_id = $this->generate_submission_id();
            if (!$this->submission_id_exists($submission_id)) {
                return $submission_id;
            }
        }
        return 'sub_' . time() . '_' . uniqid();
    }

    private function parse_answer($answer)
    {
        if (is_array($answer)) {
            $value = json_encode($answer);
            $label = implode(', ', $answer);
        } else {
            $value = $answer;
            $label = $answer;
        }

        return array(
            'value' => $value,
            'label' => $label
        );
    }

    public function get_real_ip() {
        $ip = '';
        
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            if (strpos($ip, ',') !== false) {
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
            }
        }
        elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        }
        elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        }
        elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '0.0.0.0';
        }
        
        return $ip;
    }

    public function start_tracking($form_id, $referral_code = '', $referral_source = '', $persist_cookies = false)
    {
        global $wpdb;

        $persist_cookies = (bool) $persist_cookies;

        $geo = $this->get_geo_data();
        
        // Get referral from URL parameters
        $url_params = array();
        if (isset($_SERVER['REQUEST_URI'])) {
            $url_parts = parse_url($_SERVER['REQUEST_URI']);
            if (isset($url_parts['query'])) {
                parse_str($url_parts['query'], $url_params);
            }
        }
        
        // Get referral from URL if not provided
        if (empty($referral_code) && isset($url_params['ref'])) {
            $referral_code = sanitize_text_field($url_params['ref']);
        }
        
        // Also check for referral_code parameter
        if (empty($referral_code) && isset($url_params['referral_code'])) {
            $referral_code = sanitize_text_field($url_params['referral_code']);
        }
        
        // Determine the source
        if (isset($url_params['utm_source'])) {
            $referral_source = sanitize_text_field($url_params['utm_source']);
        } elseif (isset($url_params['source'])) {
            $referral_source = sanitize_text_field($url_params['source']);
        } elseif ($persist_cookies && isset($_COOKIE['rts_referral_source'])) {
            $referral_source = sanitize_text_field($_COOKIE['rts_referral_source']);
        }
        
        // Store referral code in cookie
        if ($persist_cookies && !empty($referral_code) && !headers_sent()) {
            rts_set_cookie('rts_referral_code', $referral_code, time() + (86400 * 30), true);
        }
        
        $referrer_url = isset($_SERVER['HTTP_REFERER']) ? sanitize_text_field($_SERVER['HTTP_REFERER']) : '';

        // Check if this is a valid referral code
        $referrer_participant = null;
        if (!empty($referral_code)) {
            $referrer_participant = $this->get_participant_by_referral_code($referral_code);
        }

        $table_name = $wpdb->prefix . 'rts_survey_tracking';
        
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $table_name 
                 WHERE session_id = %s AND form_id = %d AND completion_status = 'in_progress'",
                $this->session_id,
                $form_id
            )
        );

        if ($existing) {
            $submission_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT submission_id FROM $table_name WHERE id = %d",
                    $existing
                )
            );
            $wpdb->update(
                $table_name,
                array('last_activity' => current_time('mysql')),
                array('id' => $existing)
            );
            return array('tracking_id' => $existing, 'submission_id' => $submission_id);
        }

        $submission_id = $this->create_unique_submission_id();

        $insert_data = array(
            'submission_id' => $submission_id,
            'session_id' => $this->session_id,
            'form_id' => $form_id,
            'user_ip' => $geo['ip'],
            'country' => $geo['country'],
            'city' => $geo['city'],
            'region' => $geo['region'],
            'latitude' => $geo['latitude'] ?? null,
            'longitude' => $geo['longitude'] ?? null,
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'referrer_url' => $referrer_url,
            'referral_source' => $referral_source,
            'referral_code' => $referral_code,
            'started_at' => current_time('mysql'),
            'last_activity' => current_time('mysql'),
            'completion_status' => 'in_progress',
            'referrer_participant_id' => $referrer_participant ? $referrer_participant->id : null,
            'current_step' => 1,
            'is_duplicate' => 0
        );

        $inserted = $wpdb->insert(
            $table_name,
            $insert_data
        );

        if ($inserted === false) {
            error_log('RTS: Failed to insert tracking record: ' . $wpdb->last_error);
            return false;
        }

        $tracking_id = $wpdb->insert_id;
        if ($persist_cookies && !headers_sent()) {
            rts_set_cookie('rts_tracking_id', $tracking_id, time() + (86400 * 30), true);
            rts_set_cookie(
                'rts_tracking_token',
                rts_create_tracking_access_token($tracking_id, $submission_id),
                time() + (86400 * 30),
                true
            );
        }
        $this->log_activity($tracking_id, $submission_id, 'started', 'Survey started');

        if ($persist_cookies && !empty($referral_code) && !headers_sent()) {
            rts_set_cookie('rts_referral_code', $referral_code, time() + (86400 * 30), true);
        }
        if ($persist_cookies && !empty($referral_source) && !headers_sent()) {
            rts_set_cookie('rts_referral_source', $referral_source, time() + (86400 * 30), true);
        }

        return array('tracking_id' => $tracking_id, 'submission_id' => $submission_id);
    }

    private function get_participant_by_referral_code($referral_code) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}rts_participants WHERE referral_code = %s",
                $referral_code
            )
        );
    }

   /**
     * Track question answer with proper email handling
     */
    public function track_question_answer($tracking_id, $form_id, $question_id, $answer, $question_label = '', $question_type = '', $step = 0)
    {
        global $wpdb;

        // Get the submission_id for this tracking_id
        $submission_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT submission_id FROM {$wpdb->prefix}rts_survey_tracking WHERE id = %d",
                $tracking_id
            )
        );

        if (!$submission_id) {
            error_log('RTS: No submission_id found for tracking_id: ' . $tracking_id);
            return false;
        }

        // Special handling for email - sanitize it
        if ($question_id === 'email' || strpos($question_id, 'email') !== false) {
            $answer = sanitize_email($answer);
            if (empty($answer)) {
                error_log('RTS: Invalid email provided');
                return false;
            }
        }

        $answer_data = $this->parse_answer($answer);

        // Check if there's an existing answer for this question in the same step
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, answer_value, answer_label FROM {$wpdb->prefix}rts_survey_answers 
                WHERE tracking_id = %d 
                AND form_id = %d 
                AND question_id = %s 
                AND step_number = %d
                AND is_final_answer = 0",
                $tracking_id,
                $form_id,
                $question_id,
                $step
            )
        );

        $old_answer_label = '';
        $old_answer_value = '';

        if ($existing) {
            // Check if the answer has actually changed
            if ($existing->answer_value === $answer_data['value'] && $existing->answer_label === $answer_data['label']) {
                error_log('RTS: Answer unchanged for question: ' . $question_id . ' - Skipping update');
                return true;
            }

            $old_answer_label = $existing->answer_label;
            $old_answer_value = $existing->answer_value;

            // Update existing record with new values
            $updated = $wpdb->update(
                $wpdb->prefix . 'rts_survey_answers',
                array(
                    'answer_value' => $answer_data['value'],
                    'answer_label' => $answer_data['label'],
                    'answered_at' => current_time('mysql')
                ),
                array('id' => $existing->id)
            );
            
            if ($updated !== false) {
                // For email, also update the tracking table
                if ($question_id === 'email' || strpos($question_id, 'email') !== false) {
                    $wpdb->update(
                        $wpdb->prefix . 'rts_survey_tracking',
                        array('email' => $answer_data['value']),
                        array('id' => $tracking_id)
                    );
                }
                
                // UPDATE ANALYTICS: Remove old vote, add new vote
                $this->update_analytics_on_answer_change($form_id, $question_id, $old_answer_label, $answer_data['label']);
                
                // Log the change
                $this->log_activity(
                    $tracking_id,
                    $submission_id,
                    'answer_updated',
                    "Question updated at step {$step}"
                );
                
                return true;
            } else {
                error_log('RTS: Failed to update answer: ' . $wpdb->last_error);
                return false;
            }
        }

        // No existing record, insert new one
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'rts_survey_answers',
            array(
                'tracking_id' => $tracking_id,
                'tracking_submission_id' => $submission_id,
                'form_id' => $form_id,
                'question_id' => $question_id,
                'question_label' => $question_label,
                'question_type' => $question_type,
                'answer_value' => $answer_data['value'],
                'answer_label' => $answer_data['label'],
                'step_number' => $step,
                'answered_at' => current_time('mysql'),
                'is_final_answer' => 0
            )
        );

        if ($inserted === false) {
            error_log('RTS: Failed to insert answer: ' . $wpdb->last_error);
            return false;
        }

        // For email, also update the tracking table
        if ($question_id === 'email' || strpos($question_id, 'email') !== false) {
            $wpdb->update(
                $wpdb->prefix . 'rts_survey_tracking',
                array('email' => $answer_data['value']),
                array('id' => $tracking_id)
            );
        }

        // Update tracking record
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}rts_survey_tracking 
                SET answered_questions = answered_questions + 1,
                    last_activity = %s
                WHERE id = %d",
                current_time('mysql'),
                $tracking_id
            )
        );

        // Add vote to analytics (only for new answers)
        $this->update_analytics_summary($form_id, $question_id, $answer_data['label']);

        return true;
    }

    /**
     * Update analytics when an answer changes
     * Removes old vote and adds new vote
     */
    private function update_analytics_on_answer_change($form_id, $question_id, $old_answer, $new_answer)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_survey_analytics';

        // Decrement old vote
        $old_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $table WHERE form_id = %d AND question_id = %s AND answer_option = %s",
                $form_id,
                $question_id,
                $old_answer
            )
        );

        if ($old_exists) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table SET total_votes = total_votes - 1, updated_at = %s WHERE id = %d",
                    current_time('mysql'),
                    $old_exists
                )
            );
            
            // Remove if zero votes
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $table WHERE id = %d AND total_votes <= 0",
                    $old_exists
                )
            );
            
        }

        // Increment new vote
        $new_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $table WHERE form_id = %d AND question_id = %s AND answer_option = %s",
                $form_id,
                $question_id,
                $new_answer
            )
        );

        if ($new_exists) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table SET total_votes = total_votes + 1, updated_at = %s WHERE id = %d",
                    current_time('mysql'),
                    $new_exists
                )
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'form_id' => $form_id,
                    'question_id' => $question_id,
                    'answer_option' => $new_answer,
                    'total_votes' => 1,
                    'updated_at' => current_time('mysql')
                )
            );
        }

        // Recalculate percentages for this question
        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(total_votes) FROM $table WHERE form_id = %d AND question_id = %s",
                $form_id,
                $question_id
            )
        );

        if ($total > 0) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table SET percentage = (total_votes / %d) * 100 
                     WHERE form_id = %d AND question_id = %s",
                    $total,
                    $form_id,
                    $question_id
                )
            );
        }

    }

    public function set_session_id($session_id)
    {
        $this->session_id = $session_id;
    }

    public function complete_survey($tracking_id, $email = '', $final_step = 0)
    {
        global $wpdb;

        $tracking = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}rts_survey_tracking WHERE id = %d", $tracking_id)
        );

        if (!$tracking) {
            error_log('RTS: Tracking record not found for ID: ' . $tracking_id);
            return false;
        }

        if ($tracking->completion_status === 'completed') {
            error_log('RTS: Survey already completed for ID: ' . $tracking_id);
            return true;
        }

        // Try to get email from multiple sources
        if (empty($email)) {
            // 1. Try from the answers table (email field)
            $email_answer = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT answer_value FROM {$wpdb->prefix}rts_survey_answers 
                    WHERE tracking_id = %d AND (question_id = 'email' OR question_id LIKE '%email%') 
                    ORDER BY answered_at DESC LIMIT 1",
                    $tracking_id
                )
            );
            if ($email_answer) {
                $email = sanitize_email($email_answer);
            }
        }

        if (empty($email)) {
            // 2. Try from the tracking table (if already stored)
            if (!empty($tracking->email)) {
                $email = sanitize_email($tracking->email);
            }
        }

        if (empty($email)) {
            // 3. Try to find email in any answer that looks like an email
            $email_answer = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT answer_value FROM {$wpdb->prefix}rts_survey_answers 
                    WHERE tracking_id = %d 
                    AND answer_value LIKE '%@%' 
                    AND answer_value NOT LIKE '%[%' 
                    AND answer_value NOT LIKE '{%' 
                    ORDER BY answered_at DESC LIMIT 1",
                    $tracking_id
                )
            );
            if ($email_answer && is_email($email_answer)) {
                $email = sanitize_email($email_answer);
            }
        }

        $started = strtotime($tracking->started_at);
        $completed = time();
        $time_spent = $completed - $started;

        $update_data = array(
            'completion_status' => 'completed',
            'completed_at' => current_time('mysql'),
            'time_spent_seconds' => $time_spent,
            'last_activity' => current_time('mysql')
        );

        if (!empty($final_step)) {
            $update_data['current_step'] = $final_step;
        }

        if (!empty($email)) {
            $update_data['email'] = sanitize_email($email);
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'rts_survey_tracking',
            $update_data,
            array('id' => $tracking_id)
        );

        if ($updated === false) {
            error_log('RTS: Failed to update tracking record: ' . $wpdb->last_error);
            return false;
        }

        // Mark all answers as final
        $wpdb->update(
            $wpdb->prefix . 'rts_survey_answers',
            array('is_final_answer' => 1),
            array('tracking_id' => $tracking_id)
        );

        $this->log_activity($tracking_id, $tracking->submission_id, 'completed', 'Survey completed');
        $this->cleanup_duplicate_tracking($tracking->session_id, $tracking->form_id, $tracking_id);
        
        do_action('rts_survey_completed', $tracking_id);
        
        return true;
    }

    public function track_abandonment($tracking_id, $step = 0)
    {
        global $wpdb;

        $tracking = $wpdb->get_row(
            $wpdb->prepare("SELECT submission_id FROM {$wpdb->prefix}rts_survey_tracking WHERE id = %d", $tracking_id)
        );

        $update_data = array(
            'completion_status' => 'abandoned',
            'last_activity' => current_time('mysql')
        );
        
        if (!empty($step)) {
            $update_data['current_step'] = $step;
        }

        $wpdb->update(
            $wpdb->prefix . 'rts_survey_tracking',
            $update_data,
            array('id' => $tracking_id)
        );

        if ($tracking) {
            $this->log_activity($tracking_id, $tracking->submission_id, 'abandoned', 'Survey abandoned at step: ' . $step);
        }
    }

    public function cleanup_duplicate_tracking($session_id, $form_id, $keep_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rts_survey_tracking';
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT id, submission_id, session_id, form_id, email, started_at FROM $table WHERE id = %d",
            $keep_id
        ));
        if (!$current || (int) $current->form_id !== (int) $form_id) {
            return;
        }

        // A duplicate must be from the same survey form and match either the
        // saved session ID or a non-empty email address. A matching email on a
        // different form is intentionally not treated as a duplicate.
        $match_sql = '(session_id = %s';
        $params = array($current->session_id);
        if (!empty($current->email)) {
            $match_sql .= ' OR (email = %s AND email <> \'\')';
            $params[] = $current->email;
        }
        $match_sql .= ')';
        $params[] = $form_id;
        $candidates_sql = "SELECT id, submission_id, started_at FROM $table WHERE $match_sql AND form_id = %d ORDER BY started_at ASC, id ASC";
        $candidates = $wpdb->get_results($wpdb->prepare($candidates_sql, $params));
        if (!$candidates) {
            return;
        }

        // Retain the oldest response as the canonical survey and flag every
        // later matching response for review without discarding its data.
        $canonical = $candidates[0];
        foreach ($candidates as $candidate) {
            if ((int) $candidate->id === (int) $canonical->id) {
                $wpdb->update($table, array('is_duplicate' => 0, 'duplicate_of' => null), array('id' => $candidate->id), array('%d', '%s'), array('%d'));
                continue;
            }
            $wpdb->update(
                $table,
                array('is_duplicate' => 1, 'duplicate_of' => $canonical->submission_id),
                array('id' => $candidate->id),
                array('%d', '%s'),
                array('%d')
            );
        }
    }

    public function track_step_change($tracking_id, $step, $old_step, $action)
    {
        global $wpdb;
        
        $exists = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$wpdb->prefix}rts_survey_tracking WHERE id = %d", $tracking_id)
        );
        
        if (!$exists) {
            error_log('RTS: Tracking record not found for step change: ' . $tracking_id);
            return false;
        }
        
        $submission_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT submission_id FROM {$wpdb->prefix}rts_survey_tracking WHERE id = %d",
                $tracking_id
            )
        );
        
        $this->log_activity($tracking_id, $submission_id, 'step_change', "Step changed from {$old_step} to {$step} - {$action}");
        
        $updated = $wpdb->update(
            $wpdb->prefix . 'rts_survey_tracking',
            array(
                'last_activity' => current_time('mysql'),
                'current_step' => $step
            ),
            array('id' => $tracking_id)
        );
        
        if ($updated === false) {
            error_log('RTS: Failed to update step: ' . $wpdb->last_error);
            return false;
        }
        
        return true;
    }

    private function update_analytics_summary($form_id, $question_id, $answer_option)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rts_survey_analytics';

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $table WHERE form_id = %d AND question_id = %s AND answer_option = %s",
                $form_id,
                $question_id,
                $answer_option
            )
        );

        if ($existing) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table SET total_votes = total_votes + 1, updated_at = %s WHERE id = %d",
                    current_time('mysql'),
                    $existing
                )
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'form_id' => $form_id,
                    'question_id' => $question_id,
                    'answer_option' => $answer_option,
                    'total_votes' => 1,
                    'updated_at' => current_time('mysql')
                )
            );
        }

        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(total_votes) FROM $table WHERE form_id = %d AND question_id = %s",
                $form_id,
                $question_id
            )
        );

        if ($total > 0) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table SET percentage = (total_votes / %d) * 100 
                     WHERE form_id = %d AND question_id = %s",
                    $total,
                    $form_id,
                    $question_id
                )
            );
        }
    }

    public function log_activity($tracking_id, $submission_id, $action, $description)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rts_activity_logs';
        
        $inserted = $wpdb->insert(
            $table,
            array(
                'tracking_id' => $tracking_id,
                'submission_id' => $submission_id,
                'action' => $action,
                'description' => $description,
                'created_at' => current_time('mysql')
            )
        );

        // The table is created on activation. Retain a failure-only self-heal
        // for restored databases without issuing SHOW TABLES for every event.
        if (false === $inserted) {
            $wpdb->query(
                "CREATE TABLE IF NOT EXISTS $table (
                    id bigint(20) NOT NULL AUTO_INCREMENT,
                    tracking_id bigint(20) NOT NULL,
                    submission_id varchar(36) NOT NULL,
                    action varchar(50) NOT NULL,
                    description text DEFAULT NULL,
                    created_at datetime DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY tracking_id (tracking_id),
                    KEY submission_id (submission_id),
                    KEY action (action)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
            );
            $wpdb->insert(
                $table,
                array(
                    'tracking_id' => $tracking_id,
                    'submission_id' => $submission_id,
                    'action' => $action,
                    'description' => $description,
                    'created_at' => current_time('mysql')
                )
            );
        }
    }

    public function is_form_excluded($form_id) {
        $settings = get_option('rts_survey_settings', array());
        $form_settings = $settings[$form_id] ?? array(
            'active' => 0,
            'excluded' => 0,
            'start_date' => '',
            'end_date' => '',
            'timezone' => 'UTC'
        );
        
        return isset($form_settings['excluded']) && $form_settings['excluded'] == 1;
    }

    public function is_form_trackable($form_id) {
        $settings = get_option('rts_survey_settings', array());
        $form_settings = $settings[$form_id] ?? array(
            'active' => 0,
            'excluded' => 0,
            'start_date' => '',
            'end_date' => '',
            'timezone' => 'UTC'
        );
        
        if (isset($form_settings['excluded']) && $form_settings['excluded'] == 1) {
            return false;
        }
        
        if (!$form_settings['active']) {
            return false;
        }
        
        $now = current_time('timestamp', true);
        
        if (!empty($form_settings['start_date'])) {
            $start_timestamp = strtotime($form_settings['start_date']);
            if ($start_timestamp && $now < $start_timestamp) {
                return false;
            }
        }
        
        if (!empty($form_settings['end_date'])) {
            $end_timestamp = strtotime($form_settings['end_date']);
            if ($end_timestamp && $now > $end_timestamp) {
                return false;
            }
        }
        
        return true;
    }

    public function get_analytics($form_id)
    {
        global $wpdb;

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

        $questions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT question_id, question_label, answer_option, total_votes, percentage 
                 FROM {$wpdb->prefix}rts_survey_analytics
                 WHERE form_id = %d
                 ORDER BY question_id, total_votes DESC",
                $form_id
            )
        );

        $geo = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT country, COUNT(*) as count 
                 FROM {$wpdb->prefix}rts_survey_tracking
                 WHERE form_id = %d AND country != ''
                 GROUP BY country
                 ORDER BY count DESC",
                $form_id
            )
        );

        $referrals = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT referral_source, COUNT(*) as count 
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
            'questions' => $questions,
            'geo' => $geo,
            'referrals' => $referrals,
            'abandonment' => $abandonment
        );
    }

    public function get_geo_data() {
        $ip = $this->get_real_ip();
        $geo_data = array(
            'ip' => $ip,
            'country' => '',
            'city' => '',
            'region' => '',
            'latitude' => '',
            'longitude' => ''
        );

        if (empty($ip) || $ip == '0.0.0.0' || $ip == '127.0.0.1' || $ip == '::1') {
            return $geo_data;
        }

        $cache_key = 'rts_geo_' . substr(hash('sha256', $ip), 0, 32);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return array_merge($geo_data, $cached);
        }

        // Cloudflare already supplies the country at the edge, avoiding a
        // blocking third-party HTTP request for the common live-site path.
        $cloudflare_country = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
        if ($cloudflare_country && 'XX' !== $cloudflare_country) {
            $geo_data['country'] = $cloudflare_country;
            set_transient($cache_key, $geo_data, DAY_IN_SECONDS);
            return $geo_data;
        }

        try {
            $url = 'http://ip-api.com/json/' . $ip . '?fields=status,country,city,regionName,lat,lon';
            $response = wp_remote_get($url, array(
                'timeout' => 3,
                'headers' => array('User-Agent' => 'RTS-Tracking/1.0')
            ));
            
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                
                if ($data && isset($data['status']) && $data['status'] == 'success') {
                    $geo_data['country'] = $data['country'] ?? '';
                    $geo_data['city'] = $data['city'] ?? '';
                    $geo_data['region'] = $data['regionName'] ?? '';
                    $geo_data['latitude'] = $data['lat'] ?? '';
                    $geo_data['longitude'] = $data['lon'] ?? '';
                }
            }
        } catch (Exception $e) {
            if (isset($_SERVER['HTTP_CF_IPCOUNTRY'])) {
                $geo_data['country'] = $_SERVER['HTTP_CF_IPCOUNTRY'];
            }
        }

        set_transient($cache_key, $geo_data, DAY_IN_SECONDS);
        return $geo_data;
    }

    public function reset_survey_statistics($form_id) {
        global $wpdb;
        
        $results = array(
            'success' => false,
            'deleted_tracking' => 0,
            'deleted_answers' => 0,
            'deleted_analytics' => 0,
            'deleted_activity' => 0
        );
        
        // Get form name for logging
        $form_name = $this->get_form_name($form_id);
        
        // Get count before deletion
        $tracking_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}rts_survey_tracking WHERE form_id = %d",
                $form_id
            )
        );
        
        if (empty($tracking_ids)) {
            $results['success'] = true;
            $results['message'] = 'No statistics found to reset';
            
            // Log even if no data
            $this->log_activity(0, 'system', 'reset_survey', "Reset survey statistics for form: {$form_name} (ID: {$form_id}) - No data found");
            
            return $results;
        }
        
        $tracking_ids_list = implode(',', array_map('intval', $tracking_ids));
        
        // Count before deletion
        $answers_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rts_survey_answers WHERE tracking_id IN ($tracking_ids_list)"
        );
        
        $analytics_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}rts_survey_analytics WHERE form_id = %d",
                $form_id
            )
        );
        
        $activity_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rts_activity_logs WHERE tracking_id IN ($tracking_ids_list)"
        );
        
        // Delete data
        $results['deleted_answers'] = $wpdb->query(
            "DELETE FROM {$wpdb->prefix}rts_survey_answers WHERE tracking_id IN ($tracking_ids_list)"
        );
        
        $results['deleted_activity'] = $wpdb->query(
            "DELETE FROM {$wpdb->prefix}rts_activity_logs WHERE tracking_id IN ($tracking_ids_list)"
        );
        
        $results['deleted_tracking'] = $wpdb->query(
            "DELETE FROM {$wpdb->prefix}rts_survey_tracking WHERE form_id = $form_id"
        );
        
        $results['deleted_analytics'] = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}rts_survey_analytics WHERE form_id = %d",
                $form_id
            )
        );
        
        $results['success'] = true;
        $results['message'] = 'Survey statistics reset successfully';
        
        // Log the activity
        $this->log_activity(
            0, 
            'system', 
            'reset_survey', 
            "Reset ALL statistics for form: {$form_name} (ID: {$form_id}) - " .
            "Deleted: {$results['deleted_tracking']} tracking records, " .
            "{$results['deleted_answers']} answers, " .
            "{$results['deleted_analytics']} analytics records, " .
            "{$results['deleted_activity']} activity logs"
        );
        
        error_log("RTS: Reset survey statistics for form {$form_id}");
        
        return $results;
    }

    public function reset_question_statistics($form_id, $question_id) {
        global $wpdb;
        
        $results = array(
            'success' => false,
            'deleted_answers' => 0,
            'deleted_analytics' => 0
        );
        
        // Get form name for logging
        $form_name = $this->get_form_name($form_id);
        
        error_log("RTS: Resetting statistics for question: {$question_id} in form: {$form_id}");
        
        $question_variations = array(
            $question_id,
            $question_id . '[]',
            str_replace('[]', '', $question_id),
        );
        $question_variations = array_unique($question_variations);
        
        $total_deleted_answers = 0;
        $total_deleted_analytics = 0;
        
        foreach ($question_variations as $variation) {
            // Count before deletion
            $answers_count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}rts_survey_answers WHERE form_id = %d AND question_id = %s",
                    $form_id,
                    $variation
                )
            );
            
            $analytics_count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}rts_survey_analytics WHERE form_id = %d AND question_id = %s",
                    $form_id,
                    $variation
                )
            );
            
            // Delete answers
            $deleted_answers = $wpdb->delete(
                $wpdb->prefix . 'rts_survey_answers',
                array(
                    'form_id' => $form_id,
                    'question_id' => $variation
                ),
                array('%d', '%s')
            );
            
            if ($deleted_answers !== false && $deleted_answers > 0) {
                $total_deleted_answers += $deleted_answers;
                error_log("RTS: Deleted {$deleted_answers} answers for question {$variation}");
            }
            
            // Delete analytics
            $deleted_analytics = $wpdb->delete(
                $wpdb->prefix . 'rts_survey_analytics',
                array(
                    'form_id' => $form_id,
                    'question_id' => $variation
                ),
                array('%d', '%s')
            );
            
            if ($deleted_analytics !== false && $deleted_analytics > 0) {
                $total_deleted_analytics += $deleted_analytics;
                error_log("RTS: Deleted {$deleted_analytics} analytics records for question {$variation}");
            }
        }
        
        $results['deleted_answers'] = $total_deleted_answers;
        $results['deleted_analytics'] = $total_deleted_analytics;
        $results['success'] = true;
        $results['message'] = 'Question statistics reset successfully';
        
        // Log the activity
        $this->log_activity(
            0, 
            'system', 
            'reset_question', 
            "Reset statistics for question: '{$question_id}' in form: {$form_name} (ID: {$form_id}) - " .
            "Deleted: {$total_deleted_answers} answers, {$total_deleted_analytics} analytics records"
        );
        
        error_log("RTS: Reset question statistics for form {$form_id}, question {$question_id}");
        
        return $results;
    }

    private function get_form_name($form_id) {
        $form_name = '';
        
        if (class_exists('FluentForm\App\Models\Form')) {
            try {
                $form = \FluentForm\App\Models\Form::find($form_id);
                if ($form && isset($form->title)) {
                    $form_name = $form->title;
                }
            } catch (Exception $e) {
                // Silently fail
            }
        }
        
        if (empty($form_name)) {
            // Try direct database query
            $table_name = $this->db->prefix . 'fluentform_forms';
            $result = $this->db->get_var(
                $this->db->prepare(
                    "SELECT title FROM $table_name WHERE id = %d",
                    $form_id
                )
            );
            if ($result) {
                $form_name = $result;
            }
        }
        
        return $form_name ?: 'Unknown Form';
    }

    public function get_submission_by_id($submission_id) {
        global $wpdb;
        
        $submission = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}rts_survey_tracking WHERE submission_id = %s",
                $submission_id
            )
        );
        
        if ($submission) {
            $answers = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}rts_survey_answers WHERE tracking_submission_id = %s ORDER BY step_number, answered_at",
                    $submission_id
                )
            );
            $submission->answers = $answers;
        }
        
        return $submission;
    }

    public function get_submissions_by_email($email) {
        global $wpdb;
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}rts_survey_tracking WHERE email = %s ORDER BY completed_at DESC",
                $email
            )
        );
    }

    public function get_submissions_by_form($form_id, $limit = 50, $offset = 0) {
        global $wpdb;
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}rts_survey_tracking 
                 WHERE form_id = %d AND completion_status = 'completed'
                 ORDER BY completed_at DESC
                 LIMIT %d OFFSET %d",
                $form_id,
                $limit,
                $offset
            )
        );
    }

    public function count_submissions_by_form($form_id) {
        global $wpdb;
        
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}rts_survey_tracking 
                 WHERE form_id = %d AND completion_status = 'completed'",
                $form_id
            )
        );
    }
}
