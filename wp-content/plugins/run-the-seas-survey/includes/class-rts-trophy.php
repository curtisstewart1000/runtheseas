<?php
/**
 * Class RTS_Trophy
 * Handles trophy management, earning, and display
 */
class RTS_Trophy {
    
    private $db;
    private $registration;
    private $trophy_definitions = array();
    private $suppress_notifications = false;
    private $legacy_consent_cutoff_loaded = false;
    private $legacy_consent_cutoff = '';
    private $completed_referrals_by_participant = array();
    
    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->registration = new RTS_Registration();
        
        // Define trophy levels with their requirements
        $this->trophy_definitions = array(
            'founding-runner' => array(
                'name' => 'FOUNDING RUNNER TROPHY',
                'miles_required' => 0,
                'crew_members' => 0,
                'trophy_type' => 'founding-runner',
                'rank' => 0,
                'description' => 'Registration completed and email verified',
                'image_url' => RTS_PLUGIN_URL . 'assets/images/trophies/founding-runner.png',
                'icon' => '⚓'
            ),
            '5k' => array(
                'name' => '5K TROPHY',
                'miles_required' => 5000,
                'crew_members' => 5,
                'trophy_type' => '5k',
                'rank' => 1,
                'description' => 'Founding Runner Marathon - 5K',
                'image_url' => RTS_PLUGIN_URL . 'assets/images/trophies/5k.png',
                'icon' => '🏅'
            ),
            '10k' => array(
                'name' => '10K TROPHY',
                'miles_required' => 10000,
                'crew_members' => 5,
                'trophy_type' => '10k',
                'rank' => 2,
                'description' => 'Founding Runner Marathon - 10K',
                'image_url' => RTS_PLUGIN_URL . 'assets/images/trophies/10k.png',
                'icon' => '🏅'
            ),
            '15k' => array(
                'name' => '15K TROPHY',
                'miles_required' => 15000,
                'crew_members' => 5,
                'trophy_type' => '15k',
                'rank' => 3,
                'description' => 'Founding Runner Marathon - 15K',
                'image_url' => RTS_PLUGIN_URL . 'assets/images/trophies/15k.png',
                'icon' => '🏅'
            ),
            '20k' => array(
                'name' => '20K TROPHY',
                'miles_required' => 20000,
                'crew_members' => 5,
                'trophy_type' => '20k',
                'rank' => 4,
                'description' => 'Founding Runner Marathon - 20K',
                'image_url' => RTS_PLUGIN_URL . 'assets/images/trophies/20k.png',
                'icon' => '🏅'
            ),
            '25k' => array(
                'name' => '25K TROPHY',
                'miles_required' => 25000,
                'crew_members' => 5,
                'trophy_type' => '25k',
                'rank' => 5,
                'description' => 'Founding Runner Marathon - 25K',
                'image_url' => RTS_PLUGIN_URL . 'assets/images/trophies/25k.png',
                'icon' => '🏅'
            ),
            '30k' => array(
                'name' => '30K TROPHY',
                'miles_required' => 30000,
                'crew_members' => 5,
                'trophy_type' => '30k',
                'rank' => 6,
                'description' => 'Founding Runner Marathon - 30K',
                'image_url' => RTS_PLUGIN_URL . 'assets/images/trophies/30k.png',
                'icon' => '🏅'
            ),
            '35k' => array(
                'name' => '35K TROPHY',
                'miles_required' => 35000,
                'crew_members' => 5,
                'trophy_type' => '35k',
                'rank' => 7,
                'description' => 'Founding Runner Marathon - 35K',
                'image_url' => RTS_PLUGIN_URL . 'assets/images/trophies/35k.png',
                'icon' => '🏅'
            ),
            '21k' => array(
                'name' => '21.1K TROPHY',
                'miles_required' => 21000,
                'crew_members' => 2,
                'trophy_type' => 'half_marathon',
                'rank' => 8,
                'description' => 'Half Marathon - 21.1K',
                'image_url' => RTS_PLUGIN_URL . 'assets/images/trophies/21k.png',
                'icon' => '🏃'
            ),
            '42k' => array(
                'name' => '42.2K TROPHY',
                'miles_required' => 42000,
                'crew_members' => 42,
                'trophy_type' => 'marathon',
                'rank' => 9,
                'description' => 'Full Marathon - 42.2K',
                'image_url' => RTS_PLUGIN_URL . 'assets/images/trophies/42k.png',
                'icon' => '🏆'
            )
        );
        // Marathon 2 repeats the same milestones after the first 42K unlock
        // threshold. Public labels retain the recognised 42.2K race name, but
        // the referral-based award remains reachable in whole 1K increments.
        // each award has its own stable key and record. This lets a participant
        // hold both Marathon 1's 5K trophy and Marathon 2's different 5K trophy.
        $marathon_two_base = 42000;
        foreach (array('5k', '10k', '15k', '20k', '21k', '25k', '30k', '35k', '42k') as $milestone_key) {
            $source = $this->trophy_definitions[$milestone_key];
            $second_key = 'm2-' . $milestone_key;
            $source['name'] = 'MARATHON 2 — ' . $source['name'];
            $source['miles_required'] = $marathon_two_base + absint($source['miles_required']);
            $source['trophy_type'] = 'marathon-two-' . $source['trophy_type'];
            $source['rank'] = 10 + absint($source['rank']);
            $source['description'] = 'Marathon 2 - ' . str_replace(' TROPHY', '', $this->trophy_definitions[$milestone_key]['name']) . ' milestone';
            $this->trophy_definitions[$second_key] = $source;
        }

        // Allow a site-specific plugin or child theme to adjust trophy rules
        // without modifying this plugin on every update.
        $this->trophy_definitions = apply_filters('rts_trophy_definitions', $this->trophy_definitions);
        // Design-page artwork is the final authority. Apply it after filters so
        // legacy definition filters cannot replace a saved Marathon 1 or 2 image.
        $this->apply_saved_trophy_artwork();
        
        // Hooks
        add_action('rts_referral_completed', array($this, 'check_trophy_eligibility'), 10, 2);
        add_action('rts_participant_verified', array($this, 'check_verification_trophies'), 10, 1);
        add_action('init', array($this, 'maybe_reconcile_historical_trophies'), 30);
        
        // Shortcodes
        add_shortcode('rts_trophy_case', array($this, 'render_trophy_case'));
        add_shortcode('rts_marathon_one_trophy_case', array($this, 'render_marathon_one_trophy_case'));
        add_shortcode('rts_trophy_case_marathon_1', array($this, 'render_marathon_one_trophy_case'));
        add_shortcode('rts_single_trophy', array($this, 'render_single_trophy'));
        add_shortcode('rts_trophy_room', array($this, 'render_trophy_room'));
        
        // AJAX handlers
        add_action('wp_ajax_rts_get_trophy_data', array($this, 'ajax_get_trophy_data'));
        add_action('wp_ajax_nopriv_rts_get_trophy_data', array($this, 'ajax_get_trophy_data'));
    }    
   
    /**
     * Get actual crew members count for a trophy level
     * This counts all users who have earned this trophy
     */
    public function get_crew_members_count($trophy_key, $miles_required = 0) {
        global $wpdb;
        
        // Count users who have earned this trophy
        $earned_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT participant_id) 
                FROM {$wpdb->prefix}rts_user_trophies 
                WHERE trophy_key = %s",
                $trophy_key
            )
        );
        
        // If no one has earned it yet, return 0
        return intval($earned_count);
    }

    
    
    /**
     * Check if a user has earned any new trophies
     */
    public function check_trophy_eligibility($participant_id, $new_miles = 0) {
        $participant = $this->registration->get_participant($participant_id);
        if (
            !$participant ||
            !$this->has_completed_trophy_registration($participant) ||
            !$this->has_completed_survey($participant)
        ) {
            return;
        }
        
        // Only check if email is verified
        if ($participant->email_verified != 1) {
            // Store pending trophies for later verification
            $this->store_pending_trophies($participant_id);
            return;
        }
        
        $total_miles = intval($participant->total_captain_miles_earned);
        
        foreach ($this->get_trophy_milestone_order() as $key) {
            if (!isset($this->trophy_definitions[$key])) {
                continue;
            }
            $trophy = $this->trophy_definitions[$key];
            // Check if user has enough miles
            if ($total_miles >= $trophy['miles_required']) {
                // Check if trophy already earned
                if (!$this->has_trophy($participant_id, $key)) {
                    $this->earn_trophy($participant_id, $key);
                }
            }
        }
    }
    
    /**
     * Store pending trophies for unverified users
     */
    private function store_pending_trophies($participant_id) {
        $participant = $this->registration->get_participant($participant_id);
        if (
            !$participant ||
            !$this->has_completed_trophy_registration($participant) ||
            !$this->has_completed_survey($participant)
        ) {
            return;
        }
        
        $total_miles = intval($participant->total_captain_miles_earned);
        $pending = get_user_meta($participant->user_id, 'rts_pending_trophies', true);
        if (!is_array($pending)) {
            $pending = array();
        }
        
        foreach ($this->get_trophy_milestone_order() as $key) {
            if (!isset($this->trophy_definitions[$key])) {
                continue;
            }
            $trophy = $this->trophy_definitions[$key];
            if ($total_miles >= $trophy['miles_required']) {
                if (!in_array($key, $pending) && !$this->has_trophy($participant_id, $key)) {
                    $pending[] = $key;
                }
            }
        }
        
        update_user_meta($participant->user_id, 'rts_pending_trophies', $pending);
    }
    
    /**
     * Check for pending trophies on verification
     */
    public function check_verification_trophies($participant_id) {
        $participant = $this->registration->get_participant($participant_id);
        if (
            !$participant ||
            (int) $participant->email_verified !== 1 ||
            !$this->has_completed_trophy_registration($participant) ||
            !$this->has_completed_survey($participant)
        ) {
            return;
        }

        // Current registration includes the required age/legal confirmation;
        // legacy registration is recognised by has_completed_trophy_registration().
        // The Founding Runner trophy is awarded when email is verified.
        if (!$this->has_trophy($participant_id, 'founding-runner')) {
            $this->earn_trophy($participant_id, 'founding-runner');
        }
        
        $pending = get_user_meta($participant->user_id, 'rts_pending_trophies', true);
        if (is_array($pending)) {
            $pending = array_values(array_intersect($this->get_trophy_milestone_order(), array_map('sanitize_key', $pending)));
            foreach ($pending as $key) {
                if (!$this->has_trophy($participant_id, $key)) {
                    $this->earn_trophy($participant_id, $key);
                }
            }
        }
        
        delete_user_meta($participant->user_id, 'rts_pending_trophies');

        // Also catch milestones already crossed before verification even when
        // an older account has no pending-trophy user meta.
        $this->check_trophy_eligibility($participant_id);
    }

    /**
     * Reconcile every trophy implied by the participant's authoritative state.
     *
     * This is intentionally idempotent: has_trophy() prevents duplicate rows.
     * Historical reconciliation suppresses email, while live referral and
     * verification events continue to notify members normally.
     */
    public function reconcile_participant_trophies($participant_id, $send_notifications = false) {
        $participant = $this->registration->get_participant(absint($participant_id));
        if (
            !$participant ||
            (int) $participant->email_verified !== 1 ||
            !$this->has_completed_trophy_registration($participant) ||
            !$this->has_completed_survey($participant)
        ) {
            return 0;
        }

        $previous_suppression = $this->suppress_notifications;
        $this->suppress_notifications = !$send_notifications;
        $before = count($this->get_user_trophies($participant->id));

        try {
            $total_miles = max(0, (int) $participant->total_captain_miles_earned);
            foreach ($this->get_trophy_milestone_order() as $key) {
                if (
                    !isset($this->trophy_definitions[$key]) ||
                    $total_miles < (int) $this->trophy_definitions[$key]['miles_required'] ||
                    $this->has_trophy($participant->id, $key)
                ) {
                    continue;
                }

                $this->earn_trophy(
                    $participant->id,
                    $key,
                    $this->get_historical_trophy_earned_date($participant, $key)
                );
            }

            $this->repair_trophy_record_dates_and_stats($participant);
        } finally {
            $this->suppress_notifications = $previous_suppression;
        }

        return max(0, count($this->get_user_trophies($participant->id)) - $before);
    }

    /** Run the new eligibility model once for records created by older versions. */
    public function maybe_reconcile_historical_trophies() {
        $migration_version = '5';
        if (get_option('rts_trophy_reconciliation_version') === $migration_version) {
            return;
        }

        $participant_ids = $this->db->get_col(
            "SELECT id FROM {$this->db->prefix}rts_participants
             WHERE email_verified = 1
               AND registration_date IS NOT NULL
               AND registration_date != '0000-00-00 00:00:00'"
        );
        foreach ((array) $participant_ids as $participant_id) {
            $this->reconcile_participant_trophies($participant_id, false);
        }

        // Keep existing awards aligned with current labels, whole-1K unlock
        // thresholds, and artwork. This upgrades old 21K/42.2K records and
        // removes the former .2K offset from Marathon 2 requirements.
        foreach ($this->trophy_definitions as $key => $definition) {
            $this->db->update(
                $this->db->prefix . 'rts_user_trophies',
                array(
                    'trophy_name' => $definition['name'],
                    'miles_required' => $definition['miles_required'],
                    'trophy_image_url' => $definition['image_url'],
                ),
                array('trophy_key' => $key)
            );
        }

        update_option('rts_trophy_reconciliation_version', $migration_version, false);
    }
    
    /**
     * Earn a trophy
     */
    public function earn_trophy($participant_id, $trophy_key, $earned_date = '') {
        if (!isset($this->trophy_definitions[$trophy_key])) {
            return false;
        }
        
        $trophy = $this->trophy_definitions[$trophy_key];
        $participant = $this->registration->get_participant($participant_id);
        
        if (!$participant) {
            return false;
        }

        // New participants require recorded registration consent. Verified
        // legacy registrations are handled by the shared compatibility check.
        if (
            !$this->has_completed_trophy_registration($participant) ||
            (int) $participant->email_verified !== 1 ||
            !$this->has_completed_survey($participant)
        ) {
            return false;
        }
        
        // Check if already earned
        if ($this->has_trophy($participant_id, $trophy_key)) {
            return false;
        }
        
        $earned_date = $this->normalise_trophy_date($earned_date) ?: current_time('mysql');

        // Calculate split days (days from previous trophy or registration)
        $split_days = $this->calculate_split_days($participant_id, $trophy_key, $earned_date);
        $total_days = $this->calculate_total_days($participant_id, $trophy_key, $earned_date);
        
        // Get actual crew members count (after this user earns it, it will be count + 1)
        // First get current count, then we'll add 1 for this user
        $current_crew_count = $this->get_crew_members_count($trophy_key);
        $new_crew_count = $current_crew_count + 1; // This user is now a crew member
        
        // Insert trophy record with the NEW crew count
        $inserted = $this->db->insert(
            $this->db->prefix . 'rts_user_trophies',
            array(
                'participant_id' => $participant_id,
                'race_id' => 0,
                'trophy_name' => $trophy['name'],
                'trophy_type' => $trophy['trophy_type'],
                'trophy_key' => $trophy_key,
                'trophy_rank' => $trophy['rank'],
                'trophy_image_url' => $trophy['image_url'],
                'earned_date' => $earned_date,
                'split_days' => $split_days,
                'total_days' => $total_days,
                'crew_members' => $new_crew_count,
                'miles_required' => $trophy['miles_required'],
                'is_displayed' => 1,
                'created_at' => $earned_date
            )
        );
        
        if ($inserted) {
            $trophy_id = $this->db->insert_id;
            
            // Add achievement
            $this->registration->add_achievement(
                $participant_id,
                'trophy_earned',
                $trophy['name'],
                $trophy['description'] . ' - ' . $new_crew_count . ' verified referrals!'
            );
            
            // Log timeline
            $this->registration->log_timeline(
                $participant_id,
                'trophy_earned',
                "Earned {$trophy['name']} with {$new_crew_count} verified referrals",
                array(
                    'trophy_id' => $trophy_id,
                    'trophy_key' => $trophy_key,
                    'miles_required' => $trophy['miles_required']
                )
            );
            
            // Send notification
            if (!$this->suppress_notifications) {
                $this->send_trophy_notification($participant_id, $trophy_key);
            }
            
            // Update ALL existing trophies of the same type to have the new crew count
            // This keeps all records consistent
            $this->db->update(
                $this->db->prefix . 'rts_user_trophies',
                array('crew_members' => $new_crew_count),
                array('trophy_key' => $trophy_key)
            );
            
            return $trophy_id;
        }
        
        return false;
    }
    
    /**
     * Check if user has a specific trophy
     */
    public function has_trophy($participant_id, $trophy_key) {
        if ('founding-runner' === $trophy_key) {
            $existing = $this->db->get_var(
                $this->db->prepare(
                    "SELECT id FROM {$this->db->prefix}rts_user_trophies
                    WHERE participant_id = %d
                      AND trophy_key IN ('founding-runner', 'founder', 'founding-runner-trophy')
                    LIMIT 1",
                    $participant_id
                )
            );
            return $existing !== null;
        }

        $existing = $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM {$this->db->prefix}rts_user_trophies 
                WHERE participant_id = %d AND trophy_key = %s",
                $participant_id,
                $trophy_key
            )
        );
        return $existing !== null;
    }
    
    /**
     * Get all trophies for a user
     */
    public function get_user_trophies($participant_id) {
        $participant = $this->registration->get_participant($participant_id);
        if (
            !$participant ||
            !$this->has_completed_trophy_registration($participant) ||
            (int) $participant->email_verified !== 1 ||
            !$this->has_completed_survey($participant)
        ) {
            return array();
        }

        return $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}rts_user_trophies 
                WHERE participant_id = %d AND is_displayed = 1
                ORDER BY earned_date ASC",
                $participant_id
            )
        );
    }
    
    /**
     * Get trophy by key
     */
    public function get_trophy_by_key($trophy_key) {
        return isset($this->trophy_definitions[$trophy_key]) ? $this->trophy_definitions[$trophy_key] : null;
    }
    
    /**
     * Get all trophy definitions
     */
    public function get_all_trophy_definitions() {
        return $this->trophy_definitions;
    }

    /** Resolve current artwork directly from the correct marathon design page. */
    public function get_trophy_image_url($trophy_key, $state = 'unlocked') {
        $trophy_key = sanitize_key((string) $trophy_key);
        if (in_array($trophy_key, array('founder', 'founding-runner-trophy'), true)) {
            $trophy_key = 'founding-runner';
        }
        $is_marathon_two = str_starts_with($trophy_key, 'm2-');
        $milestone_key = preg_replace('/^m2-/', '', $trophy_key);
        $state = 'locked' === sanitize_key((string) $state) ? 'locked' : 'unlocked';
        $option_name = $is_marathon_two
            ? 'rts_trophy_case_design_assets'
            : 'rts_marathon_one_trophy_case_design_assets';
        $assets = get_option($option_name, array());
        $assets = is_array($assets) ? $assets : array();
        $asset_key = str_replace('-', '_', $milestone_key) . '_' . $state . '_image';

        if (!empty($assets[$asset_key])) {
            return esc_url_raw($assets[$asset_key]);
        }

        return !empty($this->trophy_definitions[$trophy_key]['image_url'])
            ? esc_url_raw($this->trophy_definitions[$trophy_key]['image_url'])
            : '';
    }
    
    /**
     * Calculate split days (days since previous trophy)
     * If no previous trophy, returns 0
     */
    private function calculate_split_days($participant_id, $trophy_key, $earned_date = '') {
        $earned_date = $this->resolve_trophy_calculation_date($participant_id, $trophy_key, $earned_date);
        $trophy_order = $this->get_trophy_milestone_order();
        $current_index = array_search($trophy_key, $trophy_order);
        
        if ($current_index === false) {
            return 0;
        }
        
        // Get previous trophy
        $previous_key = null;
        if ($current_index > 0) {
            $previous_key = $trophy_order[$current_index - 1];
        }

        if ($previous_key) {
            $previous_trophy = $this->db->get_row(
                $this->db->prepare(
                    "SELECT earned_date FROM {$this->db->prefix}rts_user_trophies 
                    WHERE participant_id = %d AND trophy_key = %s",
                    $participant_id,
                    $previous_key
                )
            );
            
            if ($previous_trophy) {
                return $this->days_between_trophy_dates($previous_trophy->earned_date, $earned_date);
            }
        }
        
        // If the previous trophy is unavailable, start when registration and
        // email verification were both complete.
        $participant = $this->registration->get_participant($participant_id);
        $journey_start = $this->get_trophy_journey_start_date($participant);
        if ($journey_start) {
            return $this->days_between_trophy_dates($journey_start, $earned_date);
        }
        
        return 0;
    }
    
    /**
     * Calculate total days since registration
     */
    private function calculate_total_days($participant_id, $trophy_key, $earned_date = '') {
        $earned_date = $this->resolve_trophy_calculation_date($participant_id, $trophy_key, $earned_date);
        $participant = $this->registration->get_participant($participant_id);
        $journey_start = $this->get_trophy_journey_start_date($participant);
        if ($journey_start) {
            return $this->days_between_trophy_dates($journey_start, $earned_date);
        }
        return 0;
    }

    /** Milestone order used when calculating the time since the prior trophy. */
    private function get_trophy_milestone_order() {
        $order = array('founding-runner', '5k', '10k', '15k', '20k', '21k', '25k', '30k', '35k', '42k');
        foreach (array('5k', '10k', '15k', '20k', '21k', '25k', '30k', '35k', '42k') as $key) {
            $order[] = 'm2-' . $key;
        }
        return array_merge($order, array_values(array_diff(array_keys($this->trophy_definitions), $order)));
    }

    /** Use each trophy case's uploaded unlocked artwork when it is configured. */
    private function apply_saved_trophy_artwork() {
        $marathon_one_assets = get_option('rts_marathon_one_trophy_case_design_assets', array());
        $marathon_two_assets = get_option('rts_trophy_case_design_assets', array());
        $marathon_one_assets = is_array($marathon_one_assets) ? $marathon_one_assets : array();
        $marathon_two_assets = is_array($marathon_two_assets) ? $marathon_two_assets : array();

        foreach (array('founding-runner', '5k', '10k', '15k', '20k', '21k', '25k', '30k', '35k', '42k') as $key) {
            $asset_key = str_replace('-', '_', $key) . '_unlocked_image';
            if (!empty($marathon_one_assets[$asset_key]) && isset($this->trophy_definitions[$key])) {
                $this->trophy_definitions[$key]['image_url'] = esc_url_raw($marathon_one_assets[$asset_key]);
            }
            if ('founding-runner' !== $key && !empty($marathon_two_assets[$asset_key])) {
                $this->trophy_definitions['m2-' . $key]['image_url'] = esc_url_raw($marathon_two_assets[$asset_key]);
            }
        }
    }

    /** A real completed survey is a non-negotiable trophy prerequisite. */
    private function has_completed_survey($participant) {
        if (!$participant || empty($participant->id)) {
            return false;
        }

        $tracking_table = $this->db->prefix . 'rts_survey_tracking';
        if (!empty($participant->survey_tracking_id)) {
            $completed = $this->db->get_var(
                $this->db->prepare(
                    "SELECT id FROM $tracking_table WHERE id = %d AND completion_status = 'completed' LIMIT 1",
                    absint($participant->survey_tracking_id)
                )
            );
            if ($completed) {
                return true;
            }
        }

        if (!empty($participant->email)) {
            return (bool) $this->db->get_var(
                $this->db->prepare(
                    "SELECT id FROM $tracking_table WHERE email = %s AND completion_status = 'completed' LIMIT 1",
                    sanitize_email($participant->email)
                )
            );
        }

        return false;
    }

    /**
     * New registrations must carry explicit age/legal consent. Participants
     * saved by older plugin versions predate that field, so their existing
     * registration date is the legacy completion record. Do not fabricate an
     * age-consent timestamp for those accounts.
     */
    private function has_completed_trophy_registration($participant) {
        if (!$participant || empty($participant->registration_date)) {
            return false;
        }

        if (!empty($participant->age_consent_confirmed_at)) {
            return true;
        }

        // The first consented registration marks when the mandatory field was
        // deployed on this installation. Only null records older than that
        // boundary are grandfathered; a later malformed registration remains
        // ineligible and must not bypass the current consent requirement.
        if (!$this->legacy_consent_cutoff_loaded) {
            $this->legacy_consent_cutoff = (string) $this->db->get_var(
                "SELECT MIN(registration_date)
                 FROM {$this->db->prefix}rts_participants
                 WHERE age_consent_confirmed_at IS NOT NULL
                   AND age_consent_confirmed_at != ''
                   AND registration_date IS NOT NULL"
            );
            $this->legacy_consent_cutoff_loaded = true;
        }

        $registration_date = $this->normalise_trophy_date($participant->registration_date);
        $cutoff_date = $this->normalise_trophy_date($this->legacy_consent_cutoff);

        return $registration_date && (!$cutoff_date || $registration_date < $cutoff_date);
    }

    /** Return a valid MySQL date string without changing the represented time. */
    private function normalise_trophy_date($date) {
        if (!$date || '0000-00-00 00:00:00' === $date) {
            return '';
        }

        $timestamp = strtotime((string) $date);
        return false === $timestamp ? '' : date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Use an existing award's recorded date when callers omit the date.
     * New awards always pass their explicit earn time from earn_trophy().
     */
    private function resolve_trophy_calculation_date($participant_id, $trophy_key, $earned_date = '') {
        $earned_date = $this->normalise_trophy_date($earned_date);
        if ($earned_date) {
            return $earned_date;
        }

        $trophy_key = sanitize_key((string) $trophy_key);
        if (in_array($trophy_key, array('founder', 'founding-runner-trophy'), true)) {
            $trophy_key = 'founding-runner';
        }

        if ('founding-runner' === $trophy_key) {
            $recorded_date = $this->db->get_var(
                $this->db->prepare(
                    "SELECT earned_date FROM {$this->db->prefix}rts_user_trophies
                     WHERE participant_id = %d
                       AND trophy_key IN ('founding-runner', 'founder', 'founding-runner-trophy')
                     ORDER BY earned_date ASC, id ASC
                     LIMIT 1",
                    absint($participant_id)
                )
            );
        } else {
            $recorded_date = $this->db->get_var(
                $this->db->prepare(
                    "SELECT earned_date FROM {$this->db->prefix}rts_user_trophies
                     WHERE participant_id = %d AND trophy_key = %s
                     ORDER BY earned_date ASC, id ASC
                     LIMIT 1",
                    absint($participant_id),
                    $trophy_key
                )
            );
        }

        return $this->normalise_trophy_date($recorded_date) ?: current_time('mysql');
    }

    /** Infer when a historical milestone was crossed from completed referrals. */
    private function get_historical_trophy_earned_date($participant, $trophy_key) {
        $journey_start = $this->get_trophy_journey_start_date($participant);
        $required_miles = isset($this->trophy_definitions[$trophy_key])
            ? max(0, (int) $this->trophy_definitions[$trophy_key]['miles_required'])
            : 0;

        if (0 === $required_miles) {
            return $journey_start;
        }

        $participant_id = absint($participant->id);
        if (!array_key_exists($participant_id, $this->completed_referrals_by_participant)) {
            $this->completed_referrals_by_participant[$participant_id] = (array) $this->db->get_results(
                $this->db->prepare(
                    "SELECT bonus_earned, completed_date
                     FROM {$this->db->prefix}rts_referrals
                     WHERE referrer_id = %d
                       AND status = 'completed'
                       AND completed_date IS NOT NULL
                     ORDER BY completed_date ASC, id ASC",
                    $participant_id
                )
            );
        }
        $referrals = $this->completed_referrals_by_participant[$participant_id];
        $earned_miles = 0;
        foreach ((array) $referrals as $referral) {
            // Old completed rows may predate bonus_earned but each verified
            // referral still represented one kilometre in this marathon.
            $earned_miles += max(1000, (int) ($referral->bonus_earned ?? 0));
            if ($earned_miles >= $required_miles) {
                return $this->normalise_trophy_date($referral->completed_date);
            }
        }

        return '';
    }

    /** Repair legacy award dates and persist their recalculated day columns. */
    private function repair_trophy_record_dates_and_stats($participant) {
        $records = array();
        foreach ($this->get_user_trophies($participant->id) as $record) {
            $key = sanitize_key((string) ($record->trophy_key ?? ''));
            if (in_array($key, array('founder', 'founding-runner-trophy'), true)) {
                $key = 'founding-runner';
            }
            if (!$key) {
                continue;
            }

            $inferred_date = $this->get_historical_trophy_earned_date($participant, $key);
            if ($inferred_date) {
                $record->earned_date = $inferred_date;
                $this->db->update(
                    $this->db->prefix . 'rts_user_trophies',
                    array('earned_date' => $inferred_date),
                    array('id' => absint($record->id)),
                    array('%s'),
                    array('%d')
                );
            }
            $records[$key] = $record;
        }

        foreach ($records as $key => $record) {
            $stats = $this->get_trophy_record_day_stats($participant, $records, $key);
            $record->split_days = absint($stats['split_days']);
            $record->total_days = absint($stats['total_days']);
            $this->db->update(
                $this->db->prefix . 'rts_user_trophies',
                array(
                    'split_days' => $record->split_days,
                    'total_days' => $record->total_days,
                ),
                array('id' => absint($record->id)),
                array('%d', '%d'),
                array('%d')
            );
        }
    }

    /** The journey starts only after both registration and email verification. */
    private function get_trophy_journey_start_date($participant) {
        if (!$participant || empty($participant->registration_date)) {
            return '';
        }

        $timestamps = array(strtotime((string) $participant->registration_date));
        if (!empty($participant->email_verification_date)) {
            $timestamps[] = strtotime((string) $participant->email_verification_date);
        }
        $timestamps = array_filter($timestamps);

        return $timestamps ? date('Y-m-d H:i:s', max($timestamps)) : '';
    }

    /** Return achievement days between two dates, counting a same-day result as day one. */
    private function days_between_trophy_dates($start_date, $end_date) {
        if (!$start_date || !$end_date) {
            return 0;
        }

        try {
            $start = new DateTimeImmutable((string) $start_date);
            $end = new DateTimeImmutable((string) $end_date);
        } catch (Exception $exception) {
            return 0;
        }

        if ($end < $start) {
            return 0;
        }

        // A milestone completed on the starting calendar day counts as day 1,
        // rather than presenting a confusing zero-day achievement.
        return max(1, (int) $start->diff($end)->days);
    }

    /** Recalculate display statistics from actual dates for legacy trophies. */
    private function get_trophy_record_day_stats($participant, $records, $trophy_key) {
        $record = $records[$trophy_key] ?? null;
        $earned_date = $record ? $this->normalise_trophy_date($record->earned_date ?? '') : '';
        if (!$earned_date) {
            return array('split_days' => 0, 'total_days' => 0);
        }

        $journey_start = $this->normalise_trophy_date($this->get_trophy_journey_start_date($participant));
        $founding_record = $records['founding-runner'] ?? null;
        $founding_date = $founding_record
            ? $this->normalise_trophy_date($founding_record->earned_date ?? '')
            : '';

        // Some legacy imports recorded verification after an already-earned
        // trophy. Prefer the founding award as the authoritative journey start
        // in that case, and never show an earned trophy as day zero.
        if (!$journey_start || strtotime($journey_start) > strtotime($earned_date)) {
            $journey_start = $founding_date && strtotime($founding_date) <= strtotime($earned_date)
                ? $founding_date
                : $earned_date;
        }
        $split_start = $journey_start;
        $order = $this->get_trophy_milestone_order();
        $current_index = array_search($trophy_key, $order, true);
        if (false !== $current_index && $current_index > 0) {
            $previous_key = $order[$current_index - 1];
            if (!empty($records[$previous_key]->earned_date)) {
                $previous_date = $this->normalise_trophy_date($records[$previous_key]->earned_date);
                if ($previous_date && strtotime($previous_date) <= strtotime($earned_date)) {
                    $split_start = $previous_date;
                }
            }
        }

        return array(
            'split_days' => $this->days_between_trophy_dates($split_start, $earned_date),
            'total_days' => $this->days_between_trophy_dates($journey_start, $earned_date),
        );
    }
    
    /**
     * Send trophy notification
     */
    private function send_trophy_notification($participant_id, $trophy_key) {
        $participant = $this->registration->get_participant($participant_id);
        $trophy = isset($this->trophy_definitions[$trophy_key])
            ? $this->trophy_definitions[$trophy_key]
            : null;
        
        if (!$participant || !$trophy) {
            return;
        }
        
        $crew_members = $this->get_crew_members_count($trophy_key, $trophy['miles_required']);
        $milestone_key = preg_replace('/^m2-/', '', sanitize_key($trophy_key));
        $requirements = array(
            '5k' => array('referrals' => 5, 'kilometres' => '5'),
            '10k' => array('referrals' => 10, 'kilometres' => '10'),
            '15k' => array('referrals' => 15, 'kilometres' => '15'),
            '20k' => array('referrals' => 20, 'kilometres' => '20'),
            '21k' => array('referrals' => 21, 'kilometres' => '21'),
            '25k' => array('referrals' => 25, 'kilometres' => '25'),
            '30k' => array('referrals' => 30, 'kilometres' => '30'),
            '35k' => array('referrals' => 35, 'kilometres' => '35'),
            '42k' => array('referrals' => 42, 'kilometres' => '42'),
        );
        $requirement = $requirements[$milestone_key] ?? null;
        
        $subject = "🏆 You've Earned a New Trophy! - " . $trophy['name'];
        
        $message = "Hello {$participant->first_name} {$participant->last_name},\n\n";
        $message .= "🏆 Congratulations,\n\n";
        $message .= "You've earned the **" . $trophy['name'] . "**\n\n";
        $message .= "**Details:**\n";
        $message .= "• Trophy: " . $trophy['name'] . "\n";
        $message .= "• Verified referrals: " . $crew_members . "\n";
        if ($requirement) {
            $message .= "• Verified referrals needed: " . $requirement['referrals']
                . " (" . $requirement['kilometres'] . " km)\n";
        }
        $message .= "\n";
        $message .= "View your trophy case: " . home_url('/trophy-case') . "\n\n";
        $message .= "Keep going, Captain! 🚀\n\n";
        $message .= "Best regards,\nThe Run The Seas Team";
        
        $headers = rts_mail_headers('text/plain; charset=UTF-8');
        
        wp_mail($participant->email, $subject, $message, $headers);
    }
    
    /**
     * AJAX: Get trophy data for frontend
     */
    public function ajax_get_trophy_data() {
        if (!is_user_logged_in()) {
            wp_send_json_error('Please login to view trophies');
        }
        
        $user = wp_get_current_user();
        $participant = $this->registration->get_participant_for_user($user);
        
        if (!$participant) {
            wp_send_json_error('Participant not found');
        }
        
        $definitions = $this->get_all_trophy_definitions();
        
        // Get latest trophy (if any)
        $latest_trophy = null;
        if (!empty($trophies)) {
            $latest_trophy = $trophies[count($trophies) - 1];
        }
        
        // Get next trophy
        $next_trophy = null;
        $earned_keys = array();
        foreach ($trophies as $trophy) {
            $earned_keys[] = $trophy->trophy_key;
        }
        
        foreach ($definitions as $key => $def) {
            if (!in_array($key, $earned_keys)) {
                $next_trophy = $def;
                $next_trophy['key'] = $key;
                break;
            }
        }
        
        wp_send_json_success(array(
            'participant' => $participant,
            'trophies' => $trophies,
            'definitions' => $definitions,
            'latest_trophy' => $latest_trophy,
            'next_trophy' => $next_trophy,
            'total_miles' => intval($participant->total_captain_miles_earned)
        ));
    }
    
    /**
     * Render Trophy Room
     */
    public function render_trophy_room($atts) {
        if (!is_user_logged_in()) {
            return '<p>Please <a href="' . rts_get_member_login_url(get_permalink()) . '">login</a> to view your trophy room.</p>';
        }
        
        $user = wp_get_current_user();
        $participant = $this->registration->get_participant_for_user($user);
        
        if (!$participant) {
            return '<p>Please complete your registration to view your trophy room.</p>';
        }
        
        $trophies = $this->get_user_trophies($participant->id);
        $definitions = $this->get_all_trophy_definitions();
        $total_miles = intval($participant->total_captain_miles_earned);
        $email_verified = $participant->email_verified == 1;
        
        ob_start();
        ?>
        <div class="rts-trophy-room">
            <div class="rts-trophy-header">
                <h2>🏆 <?php echo esc_html($participant->first_name); ?>'s Trophy Room</h2>
                
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 10px 0;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <span><strong>Kilometres completed:</strong> <?php echo rts_format_miles($total_miles); ?></span>
                        <span><strong>Trophies Earned:</strong> <?php echo count($trophies); ?>/<?php echo count($definitions); ?></span>
                        <?php if (!$email_verified): ?>
                            <span style="color: #856404;">⚠️ Verify email to unlock trophies</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if (empty($trophies)): ?>
                <div class="rts-no-trophies">
                    <p>No trophies yet. Invite friends to start gaining verified referrals!</p>
                    <p>Every verified referral advances you by 1 km in the 42.2K Referral Marathon Challenge.</p>
                </div>
            <?php else: ?>
                <div class="rts-trophies-grid">
                    <?php foreach ($trophies as $trophy): 
                        $def = isset($definitions[$trophy->trophy_key]) ? $definitions[$trophy->trophy_key] : null;
                    ?>
                        <div class="rts-trophy-card <?php echo $trophy->trophy_type; ?>" 
                             onclick="window.location.href='/single-trophy?trophy=<?php echo $trophy->trophy_key; ?>'">
                            <div class="trophy-icon">
                                <?php if ($trophy->trophy_type === 'marathon'): ?>
                                    🏆
                                <?php elseif ($trophy->trophy_type === 'half_marathon'): ?>
                                    🏃
                                <?php else: ?>
                                    🏅
                                <?php endif; ?>
                            </div>
                            <h4><?php echo esc_html($def['name'] ?? $trophy->trophy_name); ?></h4>
                            <div class="trophy-details">
                                <span class="trophy-crew"><?php echo $this->get_crew_members_count($trophy->trophy_key); ?> Verified referrals</span>
                            </div>
                            <div class="trophy-date">
                                Earned: <?php echo date('M j, Y', strtotime($trophy->earned_date)); ?>
                            </div>
                            <div class="trophy-points">🏅 <?php echo esc_html(rts_format_trophy_miles($trophy->miles_required, $trophy->trophy_key)); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <style>
        .rts-trophy-room {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .rts-trophy-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .rts-trophy-header h2 {
            font-size: 32px;
            color: #1a7efb;
        }
        .rts-trophies-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
        }
        .rts-trophy-card {
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-top: 4px solid #ccc;
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
        }
        .rts-trophy-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        .rts-trophy-card.marathon {
            border-top-color: #FFD700;
            background: linear-gradient(135deg, #fff8e1, #fff);
        }
        .rts-trophy-card.half_marathon {
            border-top-color: #C0C0C0;
            background: linear-gradient(135deg, #f5f5f5, #fff);
        }
        .rts-trophy-card._5k {
            border-top-color: #CD7F32;
            background: linear-gradient(135deg, #fdf0e8, #fff);
        }
        .trophy-icon {
            font-size: 48px;
            margin-bottom: 10px;
        }
        .rts-trophy-card h4 {
            margin: 10px 0 5px;
            font-size: 16px;
            color: #333;
        }
        .trophy-details {
            display: flex;
            justify-content: center;
            gap: 10px;
            font-size: 12px;
            color: #666;
        }
        .trophy-date, .trophy-rank, .trophy-points {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
        }
        .trophy-points {
            color: #28a745;
            font-weight: bold;
        }
        .rts-no-trophies {
            text-align: center;
            padding: 40px;
            background: #f8f9fa;
            border-radius: 12px;
        }
        @media (max-width: 768px) {
            .rts-trophies-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            }
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /** Render the visual glass-cabinet trophy case. */
    public function render_luxury_trophy_case($atts) {
        $atts = shortcode_atts(array('marathon' => '2'), is_array($atts) ? $atts : array(), 'rts_trophy_case');
        $is_marathon_one = in_array(strtolower((string) $atts['marathon']), array('1', 'one', 'marathon-1'), true);

        if (!is_user_logged_in()) {
            return '<p>Please <a href="' . rts_get_member_login_url(get_permalink()) . '">login</a> to view your trophy case.</p>';
        }

        $user = wp_get_current_user();
        $participant = $this->registration->get_participant_for_user($user);
        if (!$participant) {
            return '<p>Please complete your registration to view your trophy case.</p>';
        }

        $trophies = $this->get_user_trophies($participant->id);
        $trophy_records = array();
        foreach ($trophies as $trophy_record) {
            $record_key = sanitize_key((string) ($trophy_record->trophy_key ?? ''));
            if (in_array($record_key, array('founder', 'founding-runner-trophy'), true)) {
                $record_key = 'founding-runner';
            }
            if ($record_key) {
                $trophy_records[$record_key] = $trophy_record;
            }
        }
        $definitions = $this->get_all_trophy_definitions();
        $design_assets = get_option(
            $is_marathon_one ? 'rts_marathon_one_trophy_case_design_assets' : 'rts_trophy_case_design_assets',
            array()
        );
        $design_assets = is_array($design_assets) ? $design_assets : array();
        $background_url = !empty($design_assets['background_image']) ? esc_url($design_assets['background_image']) : '';
        $bundled_responsive_background_path = RTS_PLUGIN_PATH . 'assets/images/trophy-case-marathon-' . ($is_marathon_one ? '1' : '2') . '-responsive.png';
        $bundled_responsive_background_url = file_exists($bundled_responsive_background_path)
            ? RTS_PLUGIN_URL . 'assets/images/trophy-case-marathon-' . ($is_marathon_one ? '1' : '2') . '-responsive.png'
            : '';
        $responsive_background_url = !empty($design_assets['responsive_background_image'])
            ? esc_url($design_assets['responsive_background_image'])
            : $bundled_responsive_background_url;
        $title_image_url = !empty($design_assets['title_image']) ? esc_url($design_assets['title_image']) : '';
        $title_icon_url = !empty($design_assets['title_icon_image']) ? esc_url($design_assets['title_icon_image']) : '';
        $footer_frame_url = !empty($design_assets['footer_frame_image']) ? esc_url($design_assets['footer_frame_image']) : '';
        $founding_caption_url = !empty($design_assets['founding_caption_image']) ? esc_url($design_assets['founding_caption_image']) : '';
        $half_caption_url = !empty($design_assets['half_caption_image']) ? esc_url($design_assets['half_caption_image']) : '';
        $marathon_caption_url = !empty($design_assets['marathon_caption_image']) ? esc_url($design_assets['marathon_caption_image']) : '';
        $left_ornament_url = !empty($design_assets['milestone_left_ornament_image']) ? esc_url($design_assets['milestone_left_ornament_image']) : '';
        $right_ornament_url = !empty($design_assets['milestone_right_ornament_image']) ? esc_url($design_assets['milestone_right_ornament_image']) : '';
        $lock_icon_url = !empty($design_assets['lock_icon_image']) ? esc_url($design_assets['lock_icon_image']) : '';
        $panel_icon_urls = array();
        foreach (array('how_to_earn_icon_image', 'learn_more_link_icon_image', 'race_progress_icon_image', 'view_race_link_icon_image', 'marathon_two_lock_icon_image', 'marathon_two_compass_icon_image', 'footer_calendar_icon_image', 'footer_compass_icon_image') as $icon_key) {
            $panel_icon_urls[$icon_key] = !empty($design_assets[$icon_key]) ? esc_url($design_assets[$icon_key]) : '';
        }
        $marathon_one_decor_urls = array();
        foreach (array('title_left_flourish_image', 'title_right_flourish_image', 'title_left_compass_image', 'title_right_compass_image') as $decor_key) {
            $marathon_one_decor_urls[$decor_key] = !empty($design_assets[$decor_key]) ? esc_url($design_assets[$decor_key]) : '';
        }
        $case_classes = 'rts-trophy-case'
            . ($is_marathon_one ? ' rts-trophy-case--marathon-one' : ' rts-trophy-case--marathon-two')
            . ($background_url ? ' has-background-artwork' : '')
            . ($responsive_background_url ? ' has-responsive-background-artwork' : '')
            . ($title_image_url ? ' has-title-artwork' : '')
            . ($footer_frame_url ? ' has-footer-frame-artwork' : '');
        $case_style_parts = array();
        if ($background_url) {
            $case_style_parts[] = "--rts-trophy-case-background:url('" . str_replace("'", '%27', $background_url) . "')";
        }
        if ($responsive_background_url) {
            $case_style_parts[] = "--rts-trophy-case-responsive-background:url('" . str_replace("'", '%27', $responsive_background_url) . "')";
        }
        if ($footer_frame_url) {
            $case_style_parts[] = "--rts-trophy-case-footer-frame:url('" . str_replace("'", '%27', $footer_frame_url) . "')";
        }
        $marathon_one_frame_urls = array();
        foreach (array(
            'title_heading_frame_image' => '--rts-m1-heading-frame',
            'title_nameplate_frame_image' => '--rts-m1-nameplate-frame',
            'how_to_earn_frame_image' => '--rts-m1-earn-panel-frame',
            'race_progress_frame_image' => '--rts-m1-progress-panel-frame',
            'marathon_two_frame_image' => '--rts-m1-marathon-two-panel-frame',
        ) as $frame_key => $css_variable) {
            $frame_url = !empty($design_assets[$frame_key]) ? esc_url($design_assets[$frame_key]) : '';
            $marathon_one_frame_urls[$frame_key] = $frame_url;
            if ($frame_url) {
                $case_style_parts[] = $css_variable . ":url('" . str_replace("'", '%27', $frame_url) . "')";
            }
        }
        $case_style = implode(';', $case_style_parts) . ($case_style_parts ? ';' : '');
        $total_miles = intval($participant->total_captain_miles_earned);
        $email_verified = (int) $participant->email_verified === 1;
        $registration_complete = $this->has_completed_trophy_registration($participant);
        $trophy_unlock_eligible = $email_verified && $registration_complete;
        $marathon_base_miles = $is_marathon_one ? 0 : 42000;
        $marathon_progress_miles = max(0, $total_miles - $marathon_base_miles);
        $member_name = trim((string) $participant->first_name . ' ' . (string) $participant->last_name);
        if ('' === $member_name) {
            $member_name = $user->display_name;
        }
        $member_first_name = trim((string) $participant->first_name);
        if ('' === $member_first_name) {
            $member_first_name = trim((string) get_user_meta($user->ID, 'first_name', true));
        }
        $member_name_length = function_exists('mb_strlen') ? mb_strlen($member_name) : strlen($member_name);
        $trophy_display_name = $member_name;
        if ($member_name_length > 16 && '' !== $member_first_name) {
            $trophy_display_name = $member_first_name;
        }
        $joined_date = !empty($participant->registration_date)
            ? date_i18n(get_option('date_format'), strtotime($participant->registration_date))
            : '';
        $founding_number = '#' . str_pad((string) absint($participant->id), 3, '0', STR_PAD_LEFT);

        // Marathon 1 unlocks at 42K while retaining its 42.2K display label.
        // Marathon 2 uses the next clean 42K block.
        // existing cumulative offset and starts only after Marathon 1 is complete.
        $case_items = array(
            'founding-runner' => array(
                'name' => $is_marathon_one
                    ? __('Founding Runner Trophy', 'run-the-seas')
                    : __('Marathon 2 Unlocked', 'run-the-seas'),
                'miles_required' => $marathon_base_miles,
                'trophy_type' => 'founding-runner',
            ),
        );
        foreach (array('5k', '10k', '15k', '20k', '21k', '25k', '30k', '35k', '42k') as $key) {
            $definition_key = $is_marathon_one ? $key : 'm2-' . $key;
            if (isset($definitions[$definition_key])) {
                $case_items[$definition_key] = $definitions[$definition_key];
            }
        }

        $founding_runner_earned = $trophy_unlock_eligible
            && $total_miles >= $marathon_base_miles;
        $founding_caption_name = $founding_runner_earned
            ? $trophy_display_name
            : __('Your Name Here', 'run-the-seas');
        $case_earned_count = 0;
        if ($trophy_unlock_eligible) {
            foreach ($case_items as $case_key => $case_item) {
                $case_item_earned = 'founding-runner' === $case_key
                    ? $founding_runner_earned
                    : $total_miles >= absint($case_item['miles_required']);
                if ($case_item_earned) {
                    $case_earned_count++;
                }
            }
        }
        $progress_percent = min(100, max(0, ($marathon_progress_miles / 42000) * 100));
        $crew_members = min(42, max(
            absint($participant->successful_referrals ?? 0),
            absint($participant->referral_count ?? 0),
            (int) floor($marathon_progress_miles / 1000)
        ));
        $crew_remaining = max(0, 42 - $crew_members);
        $case_title_id = 'rts-trophy-case-title-' . ($is_marathon_one ? '1-' : '2-') . absint($participant->id);
        ob_start();
        ?>
        <section class="<?php echo esc_attr($case_classes); ?>"<?php echo $case_style ? ' style="' . esc_attr($case_style) . '"' : ''; ?> aria-labelledby="<?php echo esc_attr($case_title_id); ?>">
            <header class="rts-trophy-case__header">
                <span class="rts-trophy-case__anchor" aria-hidden="true">&#9875;</span>
                <h1 id="<?php echo esc_attr($case_title_id); ?>"><?php esc_html_e('Trophy Case', 'run-the-seas'); ?></h1>
                <?php if ($is_marathon_one) : ?>
                    <div class="rts-trophy-case__marathon-one-title" aria-hidden="true">
                        <div class="rts-trophy-case__title-run-line">
                            <?php if ($marathon_one_decor_urls['title_left_flourish_image']) : ?><img src="<?php echo esc_url($marathon_one_decor_urls['title_left_flourish_image']); ?>" alt="" decoding="async"><?php endif; ?>
                            <span class="rts-trophy-case__title-run-copy"><?php esc_html_e('Run Th', 'run-the-seas'); ?><?php if ($title_icon_url) : ?><img class="rts-trophy-case__title-word-icon" src="<?php echo esc_url($title_icon_url); ?>" alt="" decoding="async"><?php endif; ?><?php esc_html_e('e Seas', 'run-the-seas'); ?></span>
                            <?php if ($marathon_one_decor_urls['title_right_flourish_image']) : ?><img src="<?php echo esc_url($marathon_one_decor_urls['title_right_flourish_image']); ?>" alt="" decoding="async"><?php endif; ?>
                        </div>
                        <small><?php esc_html_e('Founding Runner Marathon', 'run-the-seas'); ?></small>
                        <div class="rts-trophy-case__title-case-line">
                            <?php if ($marathon_one_decor_urls['title_left_compass_image']) : ?><img src="<?php echo esc_url($marathon_one_decor_urls['title_left_compass_image']); ?>" alt="" decoding="async"><?php endif; ?>
                            <strong><?php esc_html_e('Trophy Case', 'run-the-seas'); ?></strong>
                            <?php if ($marathon_one_decor_urls['title_right_compass_image']) : ?><img src="<?php echo esc_url($marathon_one_decor_urls['title_right_compass_image']); ?>" alt="" decoding="async"><?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($title_image_url) : ?>
                    <img class="rts-trophy-case__title-image" src="<?php echo esc_url($title_image_url); ?>" alt="" decoding="async">
                <?php endif; ?>
                <?php if ($title_icon_url && !$is_marathon_one) : ?>
                    <img class="rts-trophy-case__title-icon-image" src="<?php echo esc_url($title_icon_url); ?>" alt="" decoding="async">
                <?php endif; ?>
                <p>
                    <?php if ($is_marathon_one && $marathon_one_frame_urls['title_nameplate_frame_image']) : ?><img class="rts-trophy-case__nameplate-frame" src="<?php echo esc_url($marathon_one_frame_urls['title_nameplate_frame_image']); ?>" alt="" decoding="async"><?php endif; ?>
                    <span><?php echo esc_html($member_name); ?></span>
                </p>
                <div class="rts-trophy-case__summary">
                    <span><?php echo esc_html(rts_format_miles($total_miles)); ?> <?php esc_html_e('completed', 'run-the-seas'); ?></span>
                    <span><?php echo esc_html($case_earned_count); ?> / <?php echo esc_html(count($case_items)); ?> <?php echo esc_html(sprintf(__('Marathon %d milestones earned', 'run-the-seas'), $is_marathon_one ? 1 : 2)); ?></span>
                    <?php if (!$email_verified) : ?>
                        <span><?php esc_html_e('Verify your email to unlock new trophies', 'run-the-seas'); ?></span>
                    <?php elseif (!$registration_complete) : ?>
                        <span><?php esc_html_e('Age confirmation is required to unlock trophies', 'run-the-seas'); ?></span>
                    <?php endif; ?>
                </div>
            </header>

            <div class="rts-trophy-case__cabinet">
                <div class="rts-trophy-case__grid">
                    <?php foreach ($case_items as $key => $trophy) :
                        $is_founding = 'founding-runner' === $key;
                        $milestone_key = preg_replace('/^m2-/', '', $key);
                        $required = absint($trophy['miles_required'] ?? 0);
                        $earned = $is_founding
                            ? $founding_runner_earned
                            : $trophy_unlock_eligible && $total_miles >= $required;
                        $is_major = $is_founding || in_array($milestone_key, array('21k', '42k'), true);
                        $label = $is_founding
                            ? __('Founding Runner Trophy', 'run-the-seas')
                            : (string) $trophy['name'];
                        if ($is_marathon_one && '21k' === $milestone_key) {
                            $label = __('Half Marathon 21.1K Trophy', 'run-the-seas');
                        } elseif ($is_marathon_one && '42k' === $milestone_key) {
                            $label = __('Marathon 42.2K Trophy', 'run-the-seas');
                        }
                        $label_lines = array($label);
                        if ($is_marathon_one && $is_founding) {
                            $label_lines = array(__('Founding', 'run-the-seas'), __('Runner Trophy', 'run-the-seas'));
                        } elseif ($is_marathon_one && '21k' === $milestone_key) {
                            $label_lines = array(__('Half Marathon', 'run-the-seas'), __('21.1K Trophy', 'run-the-seas'));
                        } elseif ($is_marathon_one && '42k' === $milestone_key) {
                            $label_lines = array(__('Marathon', 'run-the-seas'), __('42.2K Trophy', 'run-the-seas'));
                        }
                        $asset_prefix = str_replace('-', '_', $milestone_key);
                        $state_asset_key = $asset_prefix . ($earned ? '_unlocked_image' : '_locked_image');
                        $uploaded_image_url = !empty($design_assets[$state_asset_key]) ? esc_url($design_assets[$state_asset_key]) : '';
                        $bundled_state_path = RTS_PLUGIN_PATH . 'assets/images/trophy-case/' . $asset_prefix . ($earned ? '-unlocked.png' : '-locked.png');
                        $bundled_state_url = file_exists($bundled_state_path)
                            ? RTS_PLUGIN_URL . 'assets/images/trophy-case/' . $asset_prefix . ($earned ? '-unlocked.png' : '-locked.png')
                            : '';
                        $fallback_path = RTS_PLUGIN_PATH . 'assets/images/trophies/' . $milestone_key . '.png';
                        $fallback_url = file_exists($fallback_path)
                            ? RTS_PLUGIN_URL . 'assets/images/trophies/' . $milestone_key . '.png'
                            : (!empty($trophy['image_url']) ? $trophy['image_url'] : '');
                        $image_url = $uploaded_image_url ?: ($bundled_state_url ?: $fallback_url);
                        $has_complete_locked_artwork = !$earned && ($uploaded_image_url || $bundled_state_url);
                        $remaining = max(0, $required - $total_miles);
                        $item_classes = 'rts-trophy-case__item rts-trophy-case__item--' . sanitize_html_class($key) . ' ' . ($earned ? 'is-earned' : 'is-locked');
                        // Marathon 2 keeps its distinct m2-* key for records and
                        // links, but shares the cabinet slots named 5k, 10k,
                        // etc. Without this layout class the absolutely
                        // positioned item has no dimensions and is invisible.
                        if ($key !== $milestone_key) {
                            $item_classes .= ' rts-trophy-case__item--' . sanitize_html_class($milestone_key);
                        }
                        if ($uploaded_image_url || $bundled_state_url) {
                            $item_classes .= ' has-complete-artwork';
                        }
                        if ($has_complete_locked_artwork) {
                            $item_classes .= ' has-locked-artwork';
                        }
                        if ($is_major) {
                            $item_classes .= ' is-major';
                        }
                        $single_url = add_query_arg('trophy', $key, home_url('/single-trophy/'));
                        $record = $trophy_records[$key] ?? null;
                        $day_stats = $record
                            ? $this->get_trophy_record_day_stats($participant, $trophy_records, $key)
                            : array('split_days' => 0, 'total_days' => 0);
                        $split_days = absint($day_stats['split_days']);
                        $total_days = absint($day_stats['total_days']);
                    ?>
                        <article class="<?php echo esc_attr($item_classes); ?>">
                            <?php if ($earned) : ?>
                                <a class="rts-trophy-case__display" href="<?php echo esc_url($single_url); ?>" aria-label="<?php echo esc_attr(sprintf(__('View %s', 'run-the-seas'), $trophy['name'])); ?>">
                            <?php else : ?>
                                <div class="rts-trophy-case__display">
                            <?php endif; ?>
                                <span class="rts-trophy-case__spotlight" aria-hidden="true"></span>
                                <?php if ($image_url) : ?>
                                    <img class="rts-trophy-case__image" src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr(sprintf($earned ? __('Unlocked %s', 'run-the-seas') : __('Locked %s', 'run-the-seas'), $label)); ?>" loading="lazy" decoding="async">
                                <?php endif; ?>
                                <?php if (!$earned && !$has_complete_locked_artwork) : ?>
                                    <span class="rts-trophy-case__glass" aria-hidden="true"></span>
                                    <span class="rts-trophy-case__lock<?php echo $lock_icon_url ? ' has-image' : ''; ?>" aria-hidden="true"><?php if ($lock_icon_url) : ?><img src="<?php echo esc_url($lock_icon_url); ?>" alt="" loading="lazy" decoding="async"><?php else : ?><i></i><?php endif; ?></span>
                                <?php endif; ?>
                            <?php if ($earned) : ?>
                                </a>
                            <?php else : ?>
                                </div>
                            <?php endif; ?>
                            <span class="rts-trophy-case__plaque">
                                <?php if ($is_marathon_one) : ?>
                                    <b><?php foreach ($label_lines as $label_line) : ?><span><?php echo esc_html($label_line); ?></span><?php endforeach; ?></b>
                                    <?php if ($is_founding) : ?>
                                        <span class="rts-trophy-case__founding-details">
                                            <?php if ($earned && $joined_date) : ?><em><?php echo esc_html(sprintf(__('Joined %s', 'run-the-seas'), $joined_date)); ?></em><?php endif; ?>
                                            <small><?php echo $earned ? esc_html(sprintf(__('Founding Member %s', 'run-the-seas'), $founding_number)) : esc_html__('Complete registration and verification', 'run-the-seas'); ?></small>
                                        </span>
                                    <?php else : ?>
                                        <span class="rts-trophy-case__day-stats">
                                            <em><?php esc_html_e('Split Days', 'run-the-seas'); ?><i><?php echo $earned ? esc_html($split_days) : '&ndash;'; ?></i></em>
                                            <em><?php esc_html_e('Total Days', 'run-the-seas'); ?><i><?php echo $earned ? esc_html($total_days) : '&ndash;'; ?></i></em>
                                        </span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <small><?php echo $earned ? esc_html($trophy_display_name) : esc_html__('Your Name Here', 'run-the-seas'); ?></small>
                                <?php endif; ?>
                            </span>
                            <footer class="rts-trophy-case__status">
                                <?php if ($earned) : ?>
                                    <span><?php esc_html_e('Earned', 'run-the-seas'); ?></span>
                                <?php elseif (!$email_verified) : ?>
                                    <span><?php esc_html_e('Verify email to unlock', 'run-the-seas'); ?></span>
                                <?php elseif (!$registration_complete) : ?>
                                    <span><?php esc_html_e('Confirm age to unlock', 'run-the-seas'); ?></span>
                                <?php else : ?>
                                    <span><?php echo esc_html(rts_format_miles($remaining)); ?> <?php esc_html_e('to unlock', 'run-the-seas'); ?></span>
                                <?php endif; ?>
                            </footer>
                        </article>
                    <?php endforeach; ?>
                    <span class="rts-trophy-case__milestone-copy rts-trophy-case__milestone-copy--founding<?php echo $founding_caption_url ? ' has-image' : ''; ?>" aria-hidden="true">
                        <?php if ($founding_caption_url) : ?>
                            <img class="rts-trophy-case__caption-art" src="<?php echo esc_url($founding_caption_url); ?>" alt="" loading="lazy" decoding="async">
                        <?php else : ?>
                            <strong><?php esc_html_e('Signed Up', 'run-the-seas'); ?></strong>
                            <em class="rts-trophy-case__script-copy"><?php esc_html_e('The Journey Begins', 'run-the-seas'); ?></em>
                        <?php endif; ?>
                        <span class="rts-trophy-case__ornamented-line rts-trophy-case__member-line">
                            <i class="rts-trophy-case__ornament rts-trophy-case__ornament--left"><?php if ($left_ornament_url) : ?><img src="<?php echo esc_url($left_ornament_url); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?></i>
                            <b><?php echo esc_html($founding_caption_name); ?></b>
                            <i class="rts-trophy-case__ornament rts-trophy-case__ornament--right"><?php if ($right_ornament_url) : ?><img src="<?php echo esc_url($right_ornament_url); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?></i>
                        </span>
                    </span>
                    <span class="rts-trophy-case__milestone-copy rts-trophy-case__milestone-copy--half<?php echo $half_caption_url ? ' has-image' : ''; ?>" aria-hidden="true">
                        <?php if ($half_caption_url) : ?>
                            <img class="rts-trophy-case__caption-art" src="<?php echo esc_url($half_caption_url); ?>" alt="" loading="lazy" decoding="async">
                        <?php else : ?>
                            <strong><?php esc_html_e('Half Marathon', 'run-the-seas'); ?></strong>
                            <span class="rts-trophy-case__ornamented-line">
                                <i class="rts-trophy-case__ornament rts-trophy-case__ornament--left"><?php if ($left_ornament_url) : ?><img src="<?php echo esc_url($left_ornament_url); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?></i>
                                <b>21.1K</b>
                                <i class="rts-trophy-case__ornament rts-trophy-case__ornament--right"><?php if ($right_ornament_url) : ?><img src="<?php echo esc_url($right_ornament_url); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?></i>
                            </span>
                            <em class="rts-trophy-case__script-copy"><?php esc_html_e('At Sea', 'run-the-seas'); ?></em>
                        <?php endif; ?>
                    </span>
                    <span class="rts-trophy-case__milestone-copy rts-trophy-case__milestone-copy--marathon<?php echo $marathon_caption_url ? ' has-image' : ''; ?>" aria-hidden="true">
                        <?php if ($marathon_caption_url) : ?>
                            <img class="rts-trophy-case__caption-art" src="<?php echo esc_url($marathon_caption_url); ?>" alt="" loading="lazy" decoding="async">
                        <?php else : ?>
                            <strong><?php esc_html_e('Marathon', 'run-the-seas'); ?></strong>
                            <span class="rts-trophy-case__ornamented-line">
                                <i class="rts-trophy-case__ornament rts-trophy-case__ornament--left"><?php if ($left_ornament_url) : ?><img src="<?php echo esc_url($left_ornament_url); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?></i>
                                <b>42.2K</b>
                                <i class="rts-trophy-case__ornament rts-trophy-case__ornament--right"><?php if ($right_ornament_url) : ?><img src="<?php echo esc_url($right_ornament_url); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?></i>
                            </span>
                            <em class="rts-trophy-case__script-copy"><?php esc_html_e('At Sea', 'run-the-seas'); ?></em>
                        <?php endif; ?>
                    </span>
                    <?php if ($is_marathon_one) : ?>
                        <div class="rts-trophy-case__marathon-one-panel rts-trophy-case__marathon-one-panel--earn">
                            <?php if ($marathon_one_frame_urls['how_to_earn_frame_image']) : ?><img class="rts-trophy-case__panel-frame" src="<?php echo esc_url($marathon_one_frame_urls['how_to_earn_frame_image']); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                            <?php if ($panel_icon_urls['how_to_earn_icon_image']) : ?><img class="rts-trophy-case__panel-heading-icon" src="<?php echo esc_url($panel_icon_urls['how_to_earn_icon_image']); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                            <h2><?php esc_html_e('How to Earn Trophies', 'run-the-seas'); ?></h2>
                            <p><?php esc_html_e('Learn how every confirmed survey helps you unlock your next trophy.', 'run-the-seas'); ?></p>
                            <a href="<?php echo esc_url(home_url('/captains-suite/')); ?>"><?php esc_html_e('Learn More', 'run-the-seas'); ?><?php if ($panel_icon_urls['learn_more_link_icon_image']) : ?><img src="<?php echo esc_url($panel_icon_urls['learn_more_link_icon_image']); ?>" alt="" loading="lazy" decoding="async"><?php else : ?><span aria-hidden="true">&#8599;</span><?php endif; ?></a>
                        </div>
                        <div class="rts-trophy-case__marathon-one-panel rts-trophy-case__marathon-one-panel--progress">
                            <?php if ($marathon_one_frame_urls['race_progress_frame_image']) : ?><img class="rts-trophy-case__panel-frame" src="<?php echo esc_url($marathon_one_frame_urls['race_progress_frame_image']); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                            <?php if ($panel_icon_urls['how_to_earn_icon_image']) : ?><img class="rts-trophy-case__panel-heading-icon" src="<?php echo esc_url($panel_icon_urls['how_to_earn_icon_image']); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                            <h2><?php esc_html_e('Race Progress', 'run-the-seas'); ?></h2>
                            <div class="rts-trophy-case__crew-count">
                                <?php if ($panel_icon_urls['race_progress_icon_image']) : ?><img src="<?php echo esc_url($panel_icon_urls['race_progress_icon_image']); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                                <span><em><?php esc_html_e('Verified referrals', 'run-the-seas'); ?></em><strong><?php echo esc_html($crew_members); ?> / 42</strong></span>
                            </div>
                            <i class="rts-trophy-case__race-meter"><b style="width:<?php echo esc_attr($progress_percent); ?>%"></b></i>
                            <p><?php echo $crew_remaining
                                ? esc_html(sprintf(_n('%d more crew member to unlock the Marathon Trophy', '%d more verified referrals members to unlock the Marathon Trophy', $crew_remaining, 'run-the-seas'), $crew_remaining))
                                : esc_html__('Marathon Trophy unlocked!', 'run-the-seas'); ?></p>
                            <a href="<?php echo esc_url(home_url('/referral-race/')); ?>"><?php esc_html_e('View the Race', 'run-the-seas'); ?><?php if ($panel_icon_urls['view_race_link_icon_image']) : ?><img src="<?php echo esc_url($panel_icon_urls['view_race_link_icon_image']); ?>" alt="" loading="lazy" decoding="async"><?php else : ?><span aria-hidden="true">&#8599;</span><?php endif; ?></a>
                        </div>
                        <div class="rts-trophy-case__marathon-one-panel rts-trophy-case__marathon-one-panel--marathon-two">
                            <?php if ($marathon_one_frame_urls['marathon_two_frame_image']) : ?><img class="rts-trophy-case__panel-frame" src="<?php echo esc_url($marathon_one_frame_urls['marathon_two_frame_image']); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                            <h2><?php esc_html_e('Marathon 2', 'run-the-seas'); ?></h2>
                            <?php if ($panel_icon_urls['marathon_two_lock_icon_image']) : ?><img class="rts-trophy-case__marathon-two-lock" src="<?php echo esc_url($panel_icon_urls['marathon_two_lock_icon_image']); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                            <p><?php esc_html_e('Complete the inaugural voyage marathon to unlock ', 'run-the-seas'); ?><span><?php esc_html_e('Trophy Case 2.', 'run-the-seas'); ?></span></p>
                            <?php if ($panel_icon_urls['marathon_two_compass_icon_image']) : ?><img class="rts-trophy-case__marathon-two-compass" src="<?php echo esc_url($panel_icon_urls['marathon_two_compass_icon_image']); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <footer class="rts-trophy-case__footer">
                <?php if ($is_marathon_one && $footer_frame_url) : ?><img class="rts-trophy-case__footer-frame" src="<?php echo esc_url($footer_frame_url); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                <?php if ($is_marathon_one) : ?>
                    <div class="rts-trophy-case__journey-date">
                        <?php if ($panel_icon_urls['footer_calendar_icon_image']) : ?><img src="<?php echo esc_url($panel_icon_urls['footer_calendar_icon_image']); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                        <span><em><?php esc_html_e('Journey Began', 'run-the-seas'); ?></em><strong><?php echo esc_html($joined_date ?: __('Registration pending', 'run-the-seas')); ?></strong></span>
                    </div>
                <?php endif; ?>
                <p><?php if ($is_marathon_one) : ?><?php esc_html_e('Every crew member. ', 'run-the-seas'); ?><?php endif; ?><?php esc_html_e('Every mile. Every achievement. Every victory.', 'run-the-seas'); ?><br>
                    <strong><?php esc_html_e('Your voyage. Your legacy.', 'run-the-seas'); ?></strong></p>
                <?php if ($is_marathon_one && $panel_icon_urls['footer_compass_icon_image']) : ?><img class="rts-trophy-case__footer-compass" src="<?php echo esc_url($panel_icon_urls['footer_compass_icon_image']); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                <div class="rts-trophy-case__progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr(round($progress_percent)); ?>">
                    <span style="width:<?php echo esc_attr($progress_percent); ?>%"></span>
                </div>
            </footer>
        </section>
        <?php
        return ob_get_clean();
    }

    /** Render Marathon 1 without changing the existing Marathon 2 shortcode. */
    public function render_marathon_one_trophy_case($atts = array()) {
        $atts = is_array($atts) ? $atts : array();
        $atts['marathon'] = '1';
        return $this->render_luxury_trophy_case($atts);
    }

    /**
     * Render Trophy Case (based on your screenshot)
     */
    public function render_trophy_case($atts) {
        return $this->render_luxury_trophy_case($atts);

        if (!is_user_logged_in()) {
            return '<p>Please <a href="' . rts_get_member_login_url(get_permalink()) . '">login</a> to view your trophy case.</p>';
        }
        
        $user = wp_get_current_user();
        $participant = $this->registration->get_participant_for_user($user);
        
        if (!$participant) {
            return '<p>Please complete your registration to view your trophy case.</p>';
        }
        
        $trophies = $this->get_user_trophies($participant->id);
        $definitions = $this->get_all_trophy_definitions();
        $total_miles = intval($participant->total_captain_miles_earned);
        $email_verified = $participant->email_verified == 1;
        $founding_member_number = str_pad($participant->id, 3, '0', STR_PAD_LEFT);
        
        // Get earned trophy keys
        $earned_keys = array();
        foreach ($trophies as $trophy) {
            $earned_keys[] = $trophy->trophy_key;
        }
        
        ob_start();
        ?>
        <div class="rts-trophy-case-wrapper">
            <!-- Header -->
            <div style="
                background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
                padding: 30px;
                border-radius: 12px 12px 0 0;
                color: #fff;
                text-align: center;
            ">
                <h1 style="margin: 0; font-size: 28px; color: #fff;">🏆 RUN THE SEAS</h1>
                <h2 style="margin: 5px 0; font-size: 18px; opacity: 0.8;">FOUNDING RUNNER MARATHON</h2>
                <p style="margin: 10px 0 0 0; font-size: 16px; opacity: 0.7;">
                    <?php echo esc_html($participant->first_name . ' ' . $participant->last_name); ?>
                    <?php if (!$email_verified): ?>
                        <span style="display: inline-block; background: #ffc107; color: #333; padding: 2px 12px; border-radius: 20px; font-size: 12px; margin-left: 10px;">
                            ⚠️ Verify Email to Unlock
                        </span>
                    <?php endif; ?>
                </p>
            </div>
            
            <!-- Trophy Grid -->
            <div style="
                background: #f8f9fa;
                padding: 20px;
                border-left: 2px solid #dee2e6;
                border-right: 2px solid #dee2e6;
            ">
                <div style="
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                    gap: 15px;
                ">
                    <?php foreach ($definitions as $key => $trophy): 
                        $earned = in_array($key, $earned_keys);
                        $can_earn = $total_miles >= $trophy['miles_required'] && $email_verified;
                        $is_locked = !$earned && (!$can_earn || !$email_verified);
                        $is_pending = !$earned && $total_miles >= $trophy['miles_required'] && !$email_verified;
                        $crew_count = $this->get_crew_members_count($key, $trophy['miles_required']);
                    ?>
                        <div class="rts-trophy-item <?php echo $earned ? 'earned' : ($is_pending ? 'pending' : 'locked'); ?>" 
                             style="
                                background: <?php echo $earned ? '#fff' : ($is_pending ? '#fff3cd' : '#f0f0f0'); ?>;
                                border-radius: 12px;
                                padding: 15px;
                                text-align: center;
                                border: <?php echo $earned ? '2px solid #28a745' : ($is_pending ? '2px solid #ffc107' : '2px solid #dee2e6'); ?>;
                                opacity: <?php echo $is_locked && !$is_pending ? '0.6' : '1'; ?>;
                                transition: all 0.3s ease;
                                cursor: <?php echo $earned ? 'pointer' : 'default'; ?>;
                                position: relative;
                             " <?php echo $earned ? 'onclick="window.location.href=\'/single-trophy?trophy=' . $key . '\'"' : ''; ?>>
                            
                            <?php if ($is_pending): ?>
                                <div style="position: absolute; top: -8px; right: -8px; background: #ffc107; color: #333; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: bold;">
                                    ⏳
                                </div>
                            <?php endif; ?>
                            
                            <div style="font-size: 48px; margin-bottom: 10px;">
                                <?php 
                                if ($earned) {
                                    echo '🏆';
                                } elseif ($is_pending) {
                                    echo '⏳';
                                } else {
                                    echo '🏅';
                                }
                                ?>
                            </div>
                            
                            <h3 style="font-size: 14px; margin: 5px 0; color: <?php echo $earned ? '#28a745' : ($is_pending ? '#856404' : '#666'); ?>;">
                                <?php echo esc_html($trophy['name']); ?>
                            </h3>
                            
                            <div style="font-size: 12px; color: #999;">
                                <?php echo $crew_count; ?> Verified referrals
                            </div>
                            
                            <?php if ($earned): ?>
                                <div style="font-size: 11px; color: #28a745; margin-top: 5px;">✅ Earned</div>
                            <?php elseif ($is_pending): ?>
                                <div style="font-size: 11px; color: #856404; margin-top: 5px;">⏳ Verify Email to Unlock</div>
                            <?php elseif ($can_earn): ?>
                                <div style="font-size: 11px; color: #1a7efb; margin-top: 5px;">🔓 Ready to Earn</div>
                            <?php else: ?>
                                <div style="font-size: 11px; color: #999; margin-top: 5px;">
                                    🔒 <?php echo esc_html((int) ceil(max(0, $trophy['miles_required'] - $total_miles) / 1000)); ?> more verified referrals needed (<?php echo esc_html(rts_format_miles($trophy['miles_required'] - $total_miles)); ?>)
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- How to Earn -->
            <div style="
                background: #f8f9fa;
                padding: 20px;
                border-left: 2px solid #dee2e6;
                border-right: 2px solid #dee2e6;
                border-bottom: 2px solid #dee2e6;
                border-radius: 0 0 12px 12px;
                text-align: center;
            ">
                <h3 style="margin: 0; color: #1a7efb;">HOW TO EARN TROPHIES</h3>
                <p style="color: #666; font-size: 14px; margin: 5px 0;">
                    Every verified referral advances you by 1 km in the 42.2K Referral Marathon Challenge. Reach each kilometre milestone to unlock trophies!
                </p>
                <a href="/captains-suite" style="
                    display: inline-block;
                    padding: 8px 30px;
                    background: #1a7efb;
                    color: #fff;
                    text-decoration: none;
                    border-radius: 6px;
                    font-size: 14px;
                    margin-top: 10px;
                ">
                    LEARN MORE →
                </a>
            </div>
            
            <!-- Race Progress -->
            <div style="
                background: #fff;
                padding: 20px;
                border: 2px solid #dee2e6;
                border-top: none;
                border-radius: 0 0 12px 12px;
                margin-top: 20px;
            ">
                <h3 style="margin: 0 0 5px 0; color: #1a7efb;">RACE PROGRESS</h3>
                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <div style="font-size: 24px; font-weight: bold; color: #1a7efb;">
                        <?php echo rts_format_miles($total_miles); ?> / 42.2 km
                    </div>
                    <div style="flex: 1; min-width: 200px;">
                        <div style="
                            height: 8px;
                            background: #e9ecef;
                            border-radius: 4px;
                            overflow: hidden;
                        ">
                            <div style="
                                height: 100%;
                                width: <?php echo min(($total_miles / 42000) * 100, 100); ?>%;
                                background: linear-gradient(90deg, #1a7efb, #28a745);
                                border-radius: 4px;
                                transition: width 0.5s ease;
                            "></div>
                        </div>
                    </div>
                    <div style="font-size: 12px; color: #666;">
                        <?php echo round(($total_miles / 42000) * 100, 1); ?>% Complete
                    </div>
                </div>
                <div style="font-size: 12px; color: #999; margin-top: 5px;">
                    Journey Began: <?php echo date('M j, Y', strtotime($participant->registration_date)); ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render Single Trophy (based on your screenshot)
     */
    public function render_single_trophy($atts) {
        if (!is_user_logged_in()) {
            return '<p>Please <a href="' . esc_url(rts_get_member_login_url(get_permalink())) . '">login</a> to view this trophy.</p>';
        }

        $user = wp_get_current_user();
        $participant = $this->registration->get_participant_for_user($user);
        if (!$participant) {
            return '<p>Please complete your registration.</p>';
        }

        $earned_records = array();
        foreach ($this->get_user_trophies($participant->id) as $earned_record) {
            $earned_key = sanitize_key((string) ($earned_record->trophy_key ?? ''));
            if (in_array($earned_key, array('founder', 'founding-runner-trophy'), true)) {
                $earned_key = 'founding-runner';
            }
            if ($earned_key && isset($this->trophy_definitions[$earned_key])) {
                $earned_records[$earned_key] = $earned_record;
            }
        }

        $trophy_key = isset($_GET['trophy']) ? sanitize_key(wp_unslash($_GET['trophy'])) : '';
        if (in_array($trophy_key, array('founder', 'founding-runner-trophy'), true)) {
            $trophy_key = 'founding-runner';
        }
        if (!$trophy_key && $earned_records) {
            $record_keys = array_keys($earned_records);
            $trophy_key = end($record_keys);
        }

        $trophy_case_url = rts_get_member_page_url(str_starts_with($trophy_key, 'm2-') ? 'trophy-case' : 'trophy-case-m1');
        if (!$trophy_key || !isset($this->trophy_definitions[$trophy_key])) {
            return '<p>Trophy not found. <a href="' . esc_url($trophy_case_url) . '">← Back to Trophy Case</a></p>';
        }
        if (!isset($earned_records[$trophy_key])) {
            return '<p>You haven\'t earned this trophy yet. <a href="' . esc_url($trophy_case_url) . '">← Back to Trophy Case</a></p>';
        }

        $is_marathon_two = str_starts_with($trophy_key, 'm2-');
        $option_name = $is_marathon_two
            ? 'rts_trophy_case_design_assets'
            : 'rts_marathon_one_trophy_case_design_assets';
        $design_assets = get_option($option_name, array());
        $design_assets = is_array($design_assets) ? $design_assets : array();
        $trophy_def = $this->trophy_definitions[$trophy_key];
        $trophy_data = $earned_records[$trophy_key];
        $milestone_key = preg_replace('/^m2-/', '', $trophy_key);
        $asset_prefix = str_replace('-', '_', $milestone_key);
        $model_url = !empty($design_assets[$asset_prefix . '_unlocked_glb'])
            ? esc_url_raw($design_assets[$asset_prefix . '_unlocked_glb'])
            : '';
        $poster_url = $this->get_trophy_image_url($trophy_key, 'unlocked');

        $member_name = trim((string) $participant->first_name . ' ' . (string) $participant->last_name);
        $member_name = $member_name ?: $user->display_name;
        $founding_runner_number = str_pad((string) absint($participant->id), 3, '0', STR_PAD_LEFT);
        $verified_referrals = absint($participant->successful_referrals ?? 0);
        $single_day_stats = $this->get_trophy_record_day_stats($participant, $earned_records, $trophy_key);
        $earned_date = !empty($trophy_data->earned_date)
            ? date_i18n(get_option('date_format'), strtotime($trophy_data->earned_date))
            : '';
        $milestone_label = rts_format_trophy_miles($trophy_def['miles_required'], $trophy_key);
        $marathon_label = $is_marathon_two
            ? __('Founding Runner Marathon 2', 'run-the-seas')
            : __('Founding Runner Marathon', 'run-the-seas');
        $rotation_step = isset($design_assets['single_rotation_step'])
            ? min(90, max(15, absint($design_assets['single_rotation_step'])))
            : 45;
        $view_angles = array(
            array('angle' => 0, 'label' => __('Front', 'run-the-seas')),
            array('angle' => 90, 'label' => __('Right side', 'run-the-seas')),
            array('angle' => 180, 'label' => __('Back', 'run-the-seas')),
            array('angle' => 270, 'label' => __('Left side', 'run-the-seas')),
        );
        $asset_url = static function ($key) use ($design_assets) {
            return !empty($design_assets[$key]) ? esc_url($design_assets[$key]) : '';
        };
        $background_url = $asset_url('single_background_image');
        $details_frame_url = $asset_url('single_details_frame_image');
        $subheading_art_url = $asset_url('single_subheading_bottom_art_image');
        $view_frame_url = $asset_url('single_trophy_view_frame_image');
        $scene_style = $background_url ? '--rts-single-trophy-background:url("' . $background_url . '");' : '';
        $button_style = static function ($url) {
            return $url ? '--rts-single-button-art:url("' . $url . '");' : '';
        };

        ob_start();
        ?>
        <section class="rts-single-trophy" style="<?php echo esc_attr($scene_style); ?>" data-rts-single-trophy
            data-plaque-heading="<?php esc_attr_e('Marathon', 'run-the-seas'); ?>"
            data-plaque-milestone="<?php echo esc_attr($milestone_label . ' ' . __('Trophy', 'run-the-seas')); ?>"
            data-plaque-member="<?php echo esc_attr($member_name); ?>"
            data-plaque-runner="<?php echo esc_attr(sprintf(__('Founding Runner #%s', 'run-the-seas'), $founding_runner_number)); ?>"
            data-plaque-referrals="<?php echo esc_attr(sprintf(_n('%s verified referral', '%s verified referrals', $verified_referrals, 'run-the-seas'), number_format_i18n($verified_referrals))); ?>"
            data-plaque-split-label="<?php esc_attr_e('Split Days', 'run-the-seas'); ?>"
            data-plaque-split-days="<?php echo esc_attr(absint($single_day_stats['split_days'])); ?>"
            data-plaque-total-label="<?php esc_attr_e('Total Days', 'run-the-seas'); ?>"
            data-plaque-total-days="<?php echo esc_attr(absint($single_day_stats['total_days'])); ?>"
            data-plaque-earned-date="<?php echo esc_attr($earned_date); ?>"
            data-share-marathon="<?php echo esc_attr($marathon_label); ?>"
            data-plaque-days="<?php echo esc_attr(sprintf(__('Split Days %1$d · Total Days %2$d', 'run-the-seas'), absint($single_day_stats['split_days']), absint($single_day_stats['total_days']))); ?>">
            <header class="rts-single-trophy__header">
                <span class="rts-single-trophy__title-art is-left" aria-hidden="true"><?php if ($asset_url('single_title_left_art_image')) : ?><img src="<?php echo $asset_url('single_title_left_art_image'); ?>" alt=""><?php endif; ?></span>
                <div>
                    <h1><?php esc_html_e('Marathon Trophy', 'run-the-seas'); ?></h1>
                    <p><?php echo esc_html($milestone_label); ?> <span aria-hidden="true">—</span> <?php echo esc_html($marathon_label); ?></p>
                    <?php if ($subheading_art_url) : ?><img class="rts-single-trophy__subheading-art" src="<?php echo $subheading_art_url; ?>" alt="" aria-hidden="true"><?php endif; ?>
                </div>
                <span class="rts-single-trophy__title-art is-right" aria-hidden="true"><?php if ($asset_url('single_title_right_art_image')) : ?><img src="<?php echo $asset_url('single_title_right_art_image'); ?>" alt=""><?php endif; ?></span>
            </header>

            <a class="rts-single-trophy__close<?php echo $asset_url('single_close_button_image') ? ' has-custom-art' : ''; ?>" href="<?php echo esc_url($trophy_case_url); ?>" aria-label="<?php esc_attr_e('Return to Trophy Case', 'run-the-seas'); ?>">
                <?php if ($asset_url('single_close_button_image')) : ?><img src="<?php echo $asset_url('single_close_button_image'); ?>" alt=""><?php else : ?><span aria-hidden="true">×</span><?php endif; ?>
            </a>

            <div class="rts-single-trophy__layout">
                <aside class="rts-single-trophy__details<?php echo $details_frame_url ? ' has-custom-frame' : ''; ?>">
                    <?php if ($details_frame_url) : ?><img class="rts-single-trophy__details-frame" src="<?php echo $details_frame_url; ?>" alt="" aria-hidden="true"><?php endif; ?>
                    <div class="rts-single-trophy__details-content">
                        <?php if (!$details_frame_url) : ?>
                        <div class="rts-single-trophy__details-icon" aria-hidden="true">
                            <?php if ($asset_url('single_details_icon_image')) : ?><img src="<?php echo $asset_url('single_details_icon_image'); ?>" alt=""><?php else : ?>⚓<?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <h2><?php esc_html_e('Marathon Trophy', 'run-the-seas'); ?></h2>
                        <strong><?php echo esc_html($milestone_label); ?></strong>
                        <p class="rts-single-trophy__marathon-name"><?php echo esc_html($marathon_label); ?></p>
                        <?php if (!$details_frame_url) : ?><span class="rts-single-trophy__rule" aria-hidden="true"></span><?php endif; ?>
                        <h3><?php echo esc_html($member_name); ?></h3>
                        <p><?php echo esc_html(sprintf(__('Founding Runner #%s', 'run-the-seas'), $founding_runner_number)); ?></p>
                        <div class="rts-single-trophy__referrals">
                            <?php if ($asset_url('single_referrals_icon_image')) : ?><img src="<?php echo $asset_url('single_referrals_icon_image'); ?>" alt="" aria-hidden="true"><?php else : ?><span aria-hidden="true">♟</span><?php endif; ?>
                            <strong><?php echo esc_html(number_format_i18n($verified_referrals)); ?></strong>
                            <span><?php esc_html_e('Verified Referrals', 'run-the-seas'); ?></span>
                        </div>
                        <dl class="rts-single-trophy__stats">
                            <div><dt><?php esc_html_e('Split Days', 'run-the-seas'); ?></dt><dd><?php echo esc_html(absint($single_day_stats['split_days'])); ?></dd></div>
                            <div><dt><?php esc_html_e('Total Days', 'run-the-seas'); ?></dt><dd><?php echo esc_html(absint($single_day_stats['total_days'])); ?></dd></div>
                        </dl>
                        <?php if ($earned_date) : ?>
                        <div class="rts-single-trophy__unlocked">
                            <?php if ($asset_url('single_calendar_icon_image')) : ?><img src="<?php echo $asset_url('single_calendar_icon_image'); ?>" alt="" aria-hidden="true"><?php else : ?><span aria-hidden="true">▦</span><?php endif; ?>
                            <span><b><?php esc_html_e('Unlocked', 'run-the-seas'); ?></b><?php echo esc_html($earned_date); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="blockquote-single-trophy">
                            <p><?php esc_html_e('“Every mile. Every achievement.', 'run-the-seas'); ?></p>
                            <p><?php esc_html_e('Every victory', 'run-the-seas'); ?></p>
                            <p><?php esc_html_e('Your voyage. Your legacy.”', 'run-the-seas'); ?></p>
                        </div>
                    </div>
                </aside>

                <div class="rts-single-trophy__viewer" data-rotation-step="<?php echo esc_attr($rotation_step); ?>">
                    <div class="rts-single-trophy__model-stage<?php echo $model_url ? ' has-model' : ''; ?>">
                        <?php if ($asset_url('single_stage_image')) : ?><img class="rts-single-trophy__stage-art" src="<?php echo $asset_url('single_stage_image'); ?>" alt="" aria-hidden="true"><?php endif; ?>
                        <?php if ($model_url) : ?>
                            <div class="rts-single-trophy__model rts-single-trophy__three-canvas" data-rts-main-model data-model-url="<?php echo esc_url($model_url); ?>" role="img" aria-label="<?php echo esc_attr(sprintf(__('%s earned by %s', 'run-the-seas'), $trophy_def['name'], $member_name)); ?>"></div>
                        <?php endif; ?>
                        <?php if ($poster_url) : ?><img class="rts-single-trophy__model-fallback" src="<?php echo esc_url($poster_url); ?>" alt="<?php echo esc_attr($trophy_def['name']); ?>"><?php endif; ?>
                        <?php if (!$model_url && !$poster_url) : ?><div class="rts-single-trophy__empty-model" aria-hidden="true">🏆</div><?php endif; ?>
                    </div>

                    <?php if ($model_url) : ?>
                    <button class="rts-single-trophy__arrow is-previous<?php echo $asset_url('single_previous_button_image') ? ' has-custom-art' : ''; ?>" type="button" data-rts-rotate="previous" aria-label="<?php echo esc_attr(sprintf(__('Rotate trophy %d degrees left', 'run-the-seas'), $rotation_step)); ?>">
                        <?php if ($asset_url('single_previous_button_image')) : ?><img src="<?php echo $asset_url('single_previous_button_image'); ?>" alt=""><?php else : ?><span aria-hidden="true">‹</span><?php endif; ?>
                    </button>
                    <button class="rts-single-trophy__arrow is-next<?php echo $asset_url('single_next_button_image') ? ' has-custom-art' : ''; ?>" type="button" data-rts-rotate="next" aria-label="<?php echo esc_attr(sprintf(__('Rotate trophy %d degrees right', 'run-the-seas'), $rotation_step)); ?>">
                        <?php if ($asset_url('single_next_button_image')) : ?><img src="<?php echo $asset_url('single_next_button_image'); ?>" alt=""><?php else : ?><span aria-hidden="true">›</span><?php endif; ?>
                    </button>
                    <?php endif; ?>
                    <p class="rts-single-trophy__interaction"><span aria-hidden="true">☝</span> <?php esc_html_e('Drag to rotate · Scroll to zoom', 'run-the-seas'); ?></p>
                </div>

                <div class="rts-single-trophy__rail" role="group" aria-label="<?php esc_attr_e('Trophy camera views', 'run-the-seas'); ?>">
                    <?php if ($model_url) : foreach ($view_angles as $view_index => $view) : ?>
                    <button class="rts-single-trophy__thumbnail<?php echo 0 === $view_index ? ' is-current' : ''; ?><?php echo $view_frame_url ? ' has-custom-frame' : ''; ?>" type="button" data-rts-model-angle="<?php echo esc_attr($view['angle']); ?>" aria-label="<?php echo esc_attr(sprintf(__('Show %s view of trophy', 'run-the-seas'), $view['label'])); ?>" aria-pressed="<?php echo 0 === $view_index ? 'true' : 'false'; ?>">
                        <?php if ($view_frame_url) : ?><img class="rts-single-trophy__thumbnail-frame" src="<?php echo $view_frame_url; ?>" alt="" aria-hidden="true"><?php endif; ?>
                        <div class="rts-single-trophy__three-canvas" data-rts-thumbnail-model data-model-url="<?php echo esc_url($model_url); ?>" data-model-angle="<?php echo esc_attr($view['angle']); ?>" aria-hidden="true"></div>
                    </button>
                    <?php endforeach; elseif ($poster_url) : ?>
                    <span class="rts-single-trophy__thumbnail is-current"><img src="<?php echo esc_url($poster_url); ?>" alt="<?php echo esc_attr($trophy_def['name']); ?>"></span>
                    <?php endif; ?>
                </div>
            </div>

            <footer class="rts-single-trophy__actions">
                <a class="<?php echo $asset_url('single_return_button_image') ? 'has-custom-art' : ''; ?>" href="<?php echo esc_url($trophy_case_url); ?>" style="<?php echo esc_attr($button_style($asset_url('single_return_button_image'))); ?>"><?php if ($asset_url('single_return_button_image')) : ?><span class="rts-single-trophy__sr-only"><?php esc_html_e('Return to Trophy Case', 'run-the-seas'); ?></span><?php else : ?><span aria-hidden="true">‹</span> <?php esc_html_e('Return to Trophy Case', 'run-the-seas'); ?><?php endif; ?></a>
                <button class="<?php echo $asset_url('single_share_button_image') ? 'has-custom-art' : ''; ?>" type="button" data-rts-share style="<?php echo esc_attr($button_style($asset_url('single_share_button_image'))); ?>" data-share-title="<?php echo esc_attr(sprintf(__('I earned the %s', 'run-the-seas'), $trophy_def['name'])); ?>" data-share-text="<?php echo esc_attr(sprintf(__('I unlocked the %1$s in the %2$s.', 'run-the-seas'), $milestone_label, $marathon_label)); ?>" data-share-filename="<?php echo esc_attr(sanitize_file_name('run-the-seas-' . $trophy_key . '-trophy.png')); ?>"><?php if ($asset_url('single_share_button_image')) : ?><span class="rts-single-trophy__sr-only"><?php esc_html_e('Share Trophy', 'run-the-seas'); ?></span><?php else : ?><span aria-hidden="true">↗</span> <?php esc_html_e('Share Trophy', 'run-the-seas'); ?><?php endif; ?></button>
                <output class="rts-single-trophy__share-status" data-rts-share-status aria-live="polite"></output>
            </footer>
        </section>
        <?php
        return ob_get_clean();
    }

    /** Retained only as a reference for pre-1.3.09 markup. */
    private function render_single_trophy_legacy($atts) {
        if (!is_user_logged_in()) {
            return '<p>Please <a href="' . rts_get_member_login_url(get_permalink()) . '">login</a> to view this trophy.</p>';
        }
        
        $user = wp_get_current_user();
        $participant = $this->registration->get_participant_for_user($user);
        
        if (!$participant) {
            return '<p>Please complete your registration.</p>';
        }
        
        // Get trophy key from URL parameter
        $trophy_key = isset($_GET['trophy']) ? sanitize_text_field($_GET['trophy']) : '';
        
        // If no trophy key in URL, try to get the latest earned trophy
        if (empty($trophy_key)) {
            $trophies = $this->get_user_trophies($participant->id);
            if (!empty($trophies)) {
                $latest = $trophies[count($trophies) - 1];
                $trophy_key = $latest->trophy_key;
            }
        }
        
        if (empty($trophy_key) || !isset($this->trophy_definitions[$trophy_key])) {
            return '<p>Trophy not found. <a href="/trophy-case">← Back to Trophy Case</a></p>';
        }
        
        $trophy_def = $this->trophy_definitions[$trophy_key];
        $trophy_data = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}rts_user_trophies 
                WHERE participant_id = %d AND trophy_key = %s",
                $participant->id,
                $trophy_key
            )
        );
        
        if (!$trophy_data) {
            return '<p>You haven\'t earned this trophy yet. <a href="/trophy-case">← Back to Trophy Case</a></p>';
        }

        $single_trophy_records = array();
        foreach ($this->get_user_trophies($participant->id) as $earned_trophy) {
            $earned_key = sanitize_key((string) ($earned_trophy->trophy_key ?? ''));
            if (in_array($earned_key, array('founder', 'founding-runner-trophy'), true)) {
                $earned_key = 'founding-runner';
            }
            if ($earned_key) {
                $single_trophy_records[$earned_key] = $earned_trophy;
            }
        }
        $single_day_stats = $this->get_trophy_record_day_stats($participant, $single_trophy_records, $trophy_key);
        
        $founding_member_number = str_pad($participant->id, 3, '0', STR_PAD_LEFT);
        $crew_count = $this->get_crew_members_count($trophy_key, $trophy_def['miles_required']);
        
        ob_start();
        ?>
        <div class="rts-single-trophy-wrapper" style="max-width: 600px; margin: 0 auto; padding: 20px;">
            <a href="/trophy-case" style="color: #1a7efb; text-decoration: none; font-size: 14px;">← RETURN TO TROPHY CASE</a>
            
            <div style="
                background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
                padding: 30px;
                border-radius: 12px 12px 0 0;
                color: #fff;
                text-align: center;
                margin-top: 10px;
            ">
                <div style="font-size: 64px; margin-bottom: 10px;">🏆</div>
                <h1 style="margin: 0; font-size: 24px; color: #fff;">MARATHON TROPHY</h1>
                <h2 style="margin: 5px 0; font-size: 18px; opacity: 0.8; color: #fff;"><?php echo esc_html($trophy_def['name']); ?></h2>
                <p style="margin: 10px 0 0 0; font-size: 14px; opacity: 0.7;">
                    FOUNDING RUNNER MARATHON
                </p>
            </div>
            
            <div style="
                background: #f8f9fa;
                padding: 25px;
                border-left: 2px solid #dee2e6;
                border-right: 2px solid #dee2e6;
                text-align: center;
            ">
                <div style="font-size: 48px; margin-bottom: 10px;">🏆</div>
                <h2 style="margin: 0; font-size: 20px; color: #1a7efb;"><?php echo esc_html($trophy_def['name']); ?></h2>
                <p style="color: #666; font-size: 14px;">FOUNDING RUNNER MARATHON</p>
                
                <div style="
                    background: #fff;
                    border-radius: 8px;
                    padding: 20px;
                    margin: 15px 0;
                    border: 1px solid #dee2e6;
                ">
                    <p style="margin: 5px 0; font-size: 14px;">
                        <strong><?php echo esc_html($participant->first_name . ' ' . $participant->last_name); ?></strong>
                    </p>
                    <p style="margin: 5px 0; font-size: 13px; color: #666;">
                        Founding Member #<?php echo $founding_member_number; ?>
                    </p>
                    <p style="margin: 5px 0; font-size: 13px; color: #666;">
                        <?php echo $crew_count; ?> VERIFIED REFERRALS
                    </p>
                    <p style="margin: 5px 0; font-size: 13px; color: #666;">
                        <?php echo esc_html(rts_format_trophy_miles($trophy_data->miles_required, $trophy_key)); ?> REQUIRED
                    </p>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 15px 0;">
                    <div style="background: #fff; padding: 12px; border-radius: 6px; border: 1px solid #dee2e6;">
                        <div style="font-size: 11px; color: #999;">SPLIT DAYS</div>
                        <div style="font-size: 24px; font-weight: bold; color: #1a7efb;">
                            <?php echo esc_html(absint($single_day_stats['split_days'])); ?>
                        </div>
                    </div>
                    <div style="background: #fff; padding: 12px; border-radius: 6px; border: 1px solid #dee2e6;">
                        <div style="font-size: 11px; color: #999;">TOTAL DAYS</div>
                        <div style="font-size: 24px; font-weight: bold; color: #1a7efb;">
                            <?php echo esc_html(absint($single_day_stats['total_days'])); ?>
                        </div>
                    </div>
                </div>
                
                <?php if ($trophy_data->earned_date): ?>
                    <div style="font-size: 12px; color: #999; margin-top: 10px;">
                        <?php echo date('F j, Y', strtotime($trophy_data->earned_date)); ?>
                    </div>
                <?php endif; ?>
                
                <div style="margin-top: 15px; padding: 10px; background: #e8f5e9; border-radius: 6px;">
                    <span style="color: #28a745;">✅ UNLOCKED</span>
                </div>
            </div>
            
            <div style="
                background: #f8f9fa;
                padding: 20px;
                border-left: 2px solid #dee2e6;
                border-right: 2px solid #dee2e6;
                border-bottom: 2px solid #dee2e6;
                border-radius: 0 0 12px 12px;
                text-align: center;
            ">
                <p style="margin: 5px 0; font-size: 14px; color: #666;">
                    <?php echo esc_html($trophy_def['description']); ?>
                </p>
                <p style="margin: 5px 0; font-size: 12px; color: #999;">
                    Every mile. Every achievement. Every victory. Your voyage. Your legacy.
                </p>
                
                <div style="display: flex; gap: 10px; justify-content: center; margin-top: 15px; flex-wrap: wrap;">
                    <a href="/trophy-case" style="
                        display: inline-block;
                        padding: 10px 25px;
                        background: #6c757d;
                        color: #fff;
                        text-decoration: none;
                        border-radius: 6px;
                        font-size: 14px;
                    ">
                        RETURN TO TROPHY CASE
                    </a>
                    <button onclick="rtsShareTrophy()" style="
                        display: inline-block;
                        padding: 10px 25px;
                        background: #1a7efb;
                        color: #fff;
                        border: none;
                        border-radius: 6px;
                        font-size: 14px;
                        cursor: pointer;
                    ">
                        SHARE TROPHY
                    </button>
                </div>
            </div>
            
            <!-- Share Modal -->
            <div id="rts-share-modal" style="
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 9999;
                justify-content: center;
                align-items: center;
            ">
                <div style="
                    background: #fff;
                    padding: 30px;
                    border-radius: 12px;
                    max-width: 400px;
                    width: 90%;
                    text-align: center;
                ">
                    <h3>Share Your Trophy</h3>
                    <p style="color: #666; font-size: 14px;">Share your achievement with the world!</p>
                    <div style="display: flex; gap: 15px; justify-content: center; margin: 20px 0; flex-wrap: wrap;">
                        <button onclick="rtsCopyLink()" style="
                            padding: 10px 20px;
                            background: #1a7efb;
                            color: #fff;
                            border: none;
                            border-radius: 6px;
                            cursor: pointer;
                        ">
                            📋 Copy Link
                        </button>
                        <a href="https://twitter.com/intent/tweet?text=I%20just%20earned%20the%20<?php echo urlencode($trophy_def['name']); ?>!%20Run%20The%20Seas%20🏆" 
                           target="_blank" style="
                            display: inline-block;
                            padding: 10px 20px;
                            background: #000;
                            color: #fff;
                            text-decoration: none;
                            border-radius: 6px;
                        ">
                            🐦 Share on X
                        </a>
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(get_permalink()); ?>" 
                           target="_blank" style="
                            display: inline-block;
                            padding: 10px 20px;
                            background: #1877f2;
                            color: #fff;
                            text-decoration: none;
                            border-radius: 6px;
                        ">
                            📘 Share on Facebook
                        </a>
                    </div>
                    <button onclick="rtsCloseShare()" style="
                        padding: 8px 30px;
                        background: #6c757d;
                        color: #fff;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                    ">
                        Close
                    </button>
                </div>
            </div>
        </div>
        
        <script>
        function rtsShareTrophy() {
            document.getElementById('rts-share-modal').style.display = 'flex';
        }
        
        function rtsCloseShare() {
            document.getElementById('rts-share-modal').style.display = 'none';
        }
        
        function rtsCopyLink() {
            var url = window.location.href;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(function() {
                    alert('Link copied to clipboard!');
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
            alert('Link copied to clipboard!');
        }
        </script>
        <?php
        return ob_get_clean();
    }
}

// Initialize the trophy system
// function rts_init_trophy_system() {
//     global $rts_trophy_instance;
//     if (!isset($rts_trophy_instance)) {
//         $rts_trophy_instance = new RTS_Trophy();
//     }
//     return $rts_trophy_instance;
// }
// add_action('init', 'rts_init_trophy_system', 5);
// add_action('plugins_loaded', 'rts_init_trophy_system');
