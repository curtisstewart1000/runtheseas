<?php

if (!defined('ABSPATH')) {
    exit;
}

trait RTS_Database_Schema
{
    public function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Survey tracking table with submission_id
        $tracking_table = $wpdb->prefix . 'rts_survey_tracking';
        $sql1 = "CREATE TABLE IF NOT EXISTS $tracking_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            submission_id varchar(36) NOT NULL,
            session_id varchar(64) NOT NULL,
            form_id bigint(20) NOT NULL,
            user_ip varchar(45) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            country varchar(100) DEFAULT NULL,
            city varchar(100) DEFAULT NULL,
            region varchar(100) DEFAULT NULL,
            latitude decimal(10,8) DEFAULT NULL,
            longitude decimal(11,8) DEFAULT NULL,
            location_accuracy int(11) DEFAULT NULL,
            location_source varchar(20) DEFAULT 'ip',
            user_agent text DEFAULT NULL,
            referrer_url text DEFAULT NULL,
            referral_source varchar(255) DEFAULT NULL,
            referral_code varchar(255) DEFAULT NULL,
            email varchar(255) DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            last_activity datetime DEFAULT NULL,
            completion_status varchar(20) DEFAULT 'in_progress',
            answered_questions int(11) DEFAULT 0,
            time_spent_seconds int(11) DEFAULT 0,
            current_step int(11) DEFAULT 0,
            is_duplicate tinyint(1) DEFAULT 0,
            duplicate_of varchar(36) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY submission_id (submission_id),
            KEY session_id (session_id),
            KEY form_id (form_id),
            KEY completion_status (completion_status),
            KEY location_source (location_source),
            KEY email (email),
            KEY is_duplicate (is_duplicate)
        ) $charset_collate;";

        // Survey answers table (add tracking_submission_id)
        $answers_table = $wpdb->prefix . 'rts_survey_answers';
        $sql2 = "CREATE TABLE IF NOT EXISTS $answers_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            tracking_id bigint(20) NOT NULL,
            tracking_submission_id varchar(36) NOT NULL,
            form_id bigint(20) NOT NULL,
            question_id varchar(255) NOT NULL,
            question_label text DEFAULT NULL,
            question_type varchar(50) DEFAULT NULL,
            answer_value text DEFAULT NULL,
            answer_label text DEFAULT NULL,
            step_number int(11) DEFAULT 0,
            answered_at datetime DEFAULT NULL,
            is_final_answer tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY tracking_id (tracking_id),
            KEY tracking_submission_id (tracking_submission_id),
            KEY form_id (form_id),
            KEY question_id (question_id)
        ) $charset_collate;";

        // Activity logs table (add submission_id)
        $logs_table = $wpdb->prefix . 'rts_activity_logs';
        $sql3 = "CREATE TABLE IF NOT EXISTS $logs_table (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        // Analytics summary table
        $analytics_table = $wpdb->prefix . 'rts_survey_analytics';
        $sql4 = "CREATE TABLE IF NOT EXISTS $analytics_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            form_id bigint(20) NOT NULL,
            question_id varchar(255) NOT NULL,
            answer_option text NOT NULL,
            total_votes int(11) DEFAULT 0,
            percentage decimal(5,2) DEFAULT 0.00,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY question_id (question_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
        dbDelta($sql4);

        return true;
    }

    /**
     * Create registration tables
     */
    public function create_registration_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Main participants table
        $participants_table = $wpdb->prefix . 'rts_participants';
        $sql1 = "CREATE TABLE IF NOT EXISTS $participants_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            survey_tracking_id bigint(20) DEFAULT NULL,
            email varchar(255) NOT NULL,
            first_name varchar(100) NOT NULL,
            last_name varchar(100) NOT NULL,
            phone varchar(50) DEFAULT NULL,
            country varchar(100) DEFAULT NULL,
            registration_country varchar(100) DEFAULT NULL,
            detected_country varchar(100) DEFAULT NULL,
            country_verified tinyint(1) DEFAULT 0,
            country_verified_at datetime DEFAULT NULL,
            country_verified_by bigint(20) DEFAULT NULL,
            city varchar(100) DEFAULT NULL,
            province varchar(100) DEFAULT NULL,
            registration_province varchar(100) DEFAULT NULL,
            postal_code varchar(30) DEFAULT NULL,
            address text DEFAULT NULL,
            address_2 varchar(255) DEFAULT NULL,
            date_of_birth date DEFAULT NULL,
            gender varchar(20) DEFAULT NULL,
            age_range varchar(20) DEFAULT NULL,
            emergency_contact_name varchar(255) DEFAULT NULL,
            emergency_contact_phone varchar(50) DEFAULT NULL,
            marketing_consent tinyint(1) DEFAULT 0,
            age_consent_confirmed_at datetime DEFAULT NULL,
            age_consent_ip_address varchar(45) DEFAULT NULL,
            registration_date datetime DEFAULT NULL,
            email_verified tinyint(1) DEFAULT 0,
            email_verification_token varchar(64) DEFAULT NULL,
            email_verification_date datetime DEFAULT NULL,
            verification_token varchar(64) DEFAULT NULL,
            verification_sent_at datetime DEFAULT NULL,
            cabin_credit_requested tinyint(1) DEFAULT 0,
            cabin_credit_number varchar(50) DEFAULT NULL,
            cabin_credit_status varchar(20) DEFAULT 'pending',
            cabin_credit_approved_date datetime DEFAULT NULL,
            cabin_credit_amount decimal(10,2) DEFAULT 100.00,
            cabin_credit_issued_at datetime DEFAULT NULL,
            cabin_credit_issued_by bigint(20) DEFAULT NULL,
            captain_suite_status varchar(20) DEFAULT 'inactive',
            captain_suite_activated_at datetime DEFAULT NULL,
            captain_suite_activated_by bigint(20) DEFAULT NULL,
            certificate_number varchar(50) DEFAULT NULL,
            certificate_issued_at datetime DEFAULT NULL,
            certificate_sent_at datetime DEFAULT NULL,
            captain_referral_participation varchar(20) DEFAULT 'not_started',
            captain_miles_balance int(11) DEFAULT 0,
            total_captain_miles_earned int(11) DEFAULT 0,
            total_captain_miles_used int(11) DEFAULT 0,
            referral_code varchar(50) DEFAULT NULL,
            referral_count int(11) DEFAULT 0,
            successful_referrals int(11) DEFAULT 0,
            total_referral_bonus int(11) DEFAULT 0,
            created_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            UNIQUE KEY cabin_credit_number (cabin_credit_number),
            UNIQUE KEY referral_code (referral_code),
            KEY survey_tracking_id (survey_tracking_id),
            KEY email_verified (email_verified),
            KEY cabin_credit_status (cabin_credit_status),
            KEY captain_suite_status (captain_suite_status),
            UNIQUE KEY certificate_number (certificate_number)
        ) $charset_collate;";

        // Awards and achievements table
        $achievements_table = $wpdb->prefix . 'rts_achievements';
        $sql2 = "CREATE TABLE IF NOT EXISTS $achievements_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            participant_id bigint(20) NOT NULL,
            achievement_type varchar(50) NOT NULL,
            achievement_name varchar(255) NOT NULL,
            achievement_description text DEFAULT NULL,
            achievement_image_url varchar(500) DEFAULT NULL,
            achievement_date datetime DEFAULT NULL,
            is_displayed tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY participant_id (participant_id),
            KEY achievement_type (achievement_type)
        ) $charset_collate;";

        // Captain's Trophy Room - Digital Medals
        $medals_table = $wpdb->prefix . 'rts_medals';
        $sql3 = "CREATE TABLE IF NOT EXISTS $medals_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            participant_id bigint(20) NOT NULL,
            medal_type varchar(50) NOT NULL,
            medal_name varchar(255) NOT NULL,
            medal_description text DEFAULT NULL,
            medal_image_url varchar(500) DEFAULT NULL,
            earned_date datetime DEFAULT NULL,
            event_name varchar(255) DEFAULT NULL,
            medal_rank varchar(20) DEFAULT NULL,
            is_displayed tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY participant_id (participant_id),
            KEY medal_type (medal_type)
        ) $charset_collate;";

        // Referrals tracking
        $referrals_table = $wpdb->prefix . 'rts_referrals';
        $sql4 = "CREATE TABLE IF NOT EXISTS $referrals_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            referrer_id bigint(20) NOT NULL,
            referred_email varchar(255) NOT NULL,
            referred_participant_id bigint(20) DEFAULT NULL,
            referral_code varchar(50) NOT NULL,
            referral_source varchar(100) DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            bonus_earned int(11) DEFAULT 0,
            referral_date datetime DEFAULT NULL,
            completed_date datetime DEFAULT NULL,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY referrer_id (referrer_id),
            KEY referred_email (referred_email),
            KEY referral_code (referral_code),
            KEY status (status)
        ) $charset_collate;";

        // Activity timeline
        $timeline_table = $wpdb->prefix . 'rts_timeline';
        $sql5 = "CREATE TABLE IF NOT EXISTS $timeline_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            participant_id bigint(20) NOT NULL,
            activity_type varchar(50) NOT NULL,
            activity_description text NOT NULL,
            activity_data text DEFAULT NULL,
            activity_date datetime DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY participant_id (participant_id),
            KEY activity_type (activity_type),
            KEY activity_date (activity_date)
        ) $charset_collate;";



        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
        dbDelta($sql4);
        dbDelta($sql5);
    }

    /** Add canonical verification fields to the participant table on upgrade. */
    public function ensure_participant_verification_columns()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rts_participants';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return false;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
        if (!is_array($columns)) {
            return false;
        }

        if (!in_array('verification_token', $columns, true)) {
            if ($wpdb->query("ALTER TABLE `{$table}` ADD COLUMN verification_token varchar(64) DEFAULT NULL AFTER email_verification_date") === false) {
                return false;
            }
        }

        if (!in_array('verification_sent_at', $columns, true)) {
            if ($wpdb->query("ALTER TABLE `{$table}` ADD COLUMN verification_sent_at datetime DEFAULT NULL AFTER verification_token") === false) {
                return false;
            }
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
        return is_array($columns)
            && in_array('verification_token', $columns, true)
            && in_array('verification_sent_at', $columns, true);
    }

    /** Add complete registration and location-source fields on upgrade. */
    public function ensure_participant_registration_columns()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rts_participants';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return false;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
        if (!is_array($columns)) {
            return false;
        }

        $required = array(
            'registration_country' => 'varchar(100) DEFAULT NULL AFTER country',
            'detected_country' => 'varchar(100) DEFAULT NULL AFTER registration_country',
            'country_verified' => 'tinyint(1) DEFAULT 0 AFTER detected_country',
            'country_verified_at' => 'datetime DEFAULT NULL AFTER country_verified',
            'country_verified_by' => 'bigint(20) DEFAULT NULL AFTER country_verified_at',
            'province' => 'varchar(100) DEFAULT NULL AFTER city',
            'registration_province' => 'varchar(100) DEFAULT NULL AFTER province',
            'postal_code' => 'varchar(30) DEFAULT NULL AFTER registration_province',
            'address_2' => 'varchar(255) DEFAULT NULL AFTER address',
            'age_range' => 'varchar(20) DEFAULT NULL AFTER gender',
            'marketing_consent' => 'tinyint(1) DEFAULT 0 AFTER emergency_contact_phone',
        );

        foreach ($required as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                if (false === $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) {
                    return false;
                }
                $columns[] = $column;
            }
        }

        // Recover a registration state only from registration-owned data. A
        // tracking region is deliberately not used because it may describe
        // the participant's current device rather than their mailing address.
        $participants = $wpdb->get_results("SELECT id, user_id, province, registration_province FROM `{$table}`");
        foreach ((array) $participants as $participant) {
            if (!empty($participant->registration_province)) {
                continue;
            }

            $registration_province = '';
            $pending = get_option('rts_pending_registration_' . (int) $participant->id);
            if (is_array($pending) && !empty($pending['post_data']['state'])) {
                $registration_province = sanitize_text_field($pending['post_data']['state']);
            } elseif (!empty($participant->user_id)) {
                $registration_province = sanitize_text_field(
                    (string) get_user_meta((int) $participant->user_id, 'rts_province', true)
                );
            }

            if ('' !== $registration_province) {
                $wpdb->update(
                    $table,
                    array('registration_province' => $registration_province, 'province' => $registration_province),
                    array('id' => (int) $participant->id),
                    array('%s', '%s'),
                    array('%d')
                );
            }
        }

        // Preserve the original saved country before detected survey location
        // becomes the public/reporting value for existing participants.
        $wpdb->query("UPDATE `{$table}`
            SET registration_country = country
            WHERE (registration_country IS NULL OR registration_country = '')
              AND country IS NOT NULL AND country != ''");

        $tracking_table = $wpdb->prefix . 'rts_survey_tracking';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tracking_table)) === $tracking_table) {
            $wpdb->query("UPDATE `{$table}` p
                INNER JOIN `{$tracking_table}` st ON st.id = p.survey_tracking_id
                SET p.detected_country = st.country
                WHERE st.country IS NOT NULL AND st.country != ''");
            $wpdb->query("UPDATE `{$table}` p
                INNER JOIN `{$tracking_table}` st ON st.id = p.survey_tracking_id
                SET p.country = st.country
                WHERE st.country IS NOT NULL AND st.country != ''
                  AND (p.country_verified IS NULL OR p.country_verified = 0)");
        }

        return true;
    }

    public function create_race_tables()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Races table
        $races_table = $wpdb->prefix . 'rts_races';
        $sql1 = "CREATE TABLE IF NOT EXISTS $races_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            race_name varchar(255) NOT NULL,
            race_type varchar(50) NOT NULL,
            distance_km decimal(10,2) NOT NULL,
            start_date datetime DEFAULT NULL,
            end_date datetime DEFAULT NULL,
            location varchar(255) DEFAULT NULL,
            description text DEFAULT NULL,
            trophy_image_url varchar(500) DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // Race participants table
        $participants_table = $wpdb->prefix . 'rts_race_participants';
        $sql2 = "CREATE TABLE IF NOT EXISTS $participants_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            participant_id bigint(20) NOT NULL,
            race_id bigint(20) NOT NULL,
            registration_date datetime DEFAULT NULL,
            completion_time time DEFAULT NULL,
            completion_date datetime DEFAULT NULL,
            status varchar(20) DEFAULT 'registered',
            rank_position int(11) DEFAULT NULL,
            medal_type varchar(20) DEFAULT NULL,
            achievement_points int(11) DEFAULT 0,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY participant_id (participant_id),
            KEY race_id (race_id),
            KEY status (status)
        ) $charset_collate;";
        // User trophies table
        $trophies_table = $wpdb->prefix . 'rts_user_trophies';
        $sql3 = "CREATE TABLE IF NOT EXISTS $trophies_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            participant_id bigint(20) NOT NULL,
            race_id bigint(20) DEFAULT NULL,
            trophy_name varchar(255) NOT NULL,
            trophy_type varchar(50) NOT NULL,
            trophy_key varchar(50) DEFAULT NULL,
            trophy_rank varchar(20) DEFAULT NULL,
            trophy_image_url varchar(500) DEFAULT NULL,
            earned_date datetime DEFAULT NULL,
            split_days int(11) DEFAULT 0,
            total_days int(11) DEFAULT 0,
            crew_members int(11) DEFAULT 0,
            miles_required int(11) DEFAULT 0,
            is_displayed tinyint(1) DEFAULT 1,
            achievement_points int(11) DEFAULT 0,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY participant_id (participant_id),
            KEY trophy_type (trophy_type),
            KEY trophy_key (trophy_key),
            KEY participant_trophy (participant_id, trophy_key),
            KEY participant_display_date (participant_id, is_displayed, earned_date)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
    }

}
