<?php

if (!defined('ABSPATH')) {
    exit;
}

class RunTheSeasPlugin
{
    use RTS_Database_Schema;
    use RTS_Frontend_Assets;
    use RTS_Survey_Ajax;
    use RTS_Registration_Ajax;
    use RTS_Analytics_Ajax;

    private static $instance = null;
    public $tracking = null;
    public $registration_page = null;
    public $registration = null;
    public $participant_operations = null;
    public $race_manager = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Load required files
        $this->load_dependencies();

        // Initialize tracking with database
        global $wpdb;
        $this->tracking = new RTS_Tracking($wpdb);

        $this->init_registration_system();
        $this->race_manager = new RTS_Race_Manager($this->registration);
        $this->participant_operations = new RTS_Participant_Operations($this->registration);

        $this->init_hooks();
    }

    private function init_registration_system()
    {
        // Initialize registration
        $this->registration = new RTS_Registration($this->tracking);

        // Initialize registration page
        $this->registration_page = new RTS_Registration_Page($this->tracking, $this->registration);

    }

    private function load_dependencies()
    {
        require_once RTS_PLUGIN_PATH . 'includes/class-rts-tracking.php';
        if (is_admin()) {
            require_once RTS_PLUGIN_PATH . 'includes/class-rts-admin.php';
            require_once RTS_PLUGIN_PATH . 'includes/class-rts-form-sync.php';
            require_once RTS_PLUGIN_PATH . 'includes/class-rts-analytics.php';
        }

        require_once RTS_PLUGIN_PATH . 'includes/class-rts-registration.php';
        require_once RTS_PLUGIN_PATH . 'includes/class-rts-registration-page.php';
        require_once RTS_PLUGIN_PATH . 'includes/class-rts-participant-operations.php';
        require_once RTS_PLUGIN_PATH . 'includes/class-rts-race-manager.php';
        require_once RTS_PLUGIN_PATH . 'includes/class-rts-trophy.php';
        require_once RTS_PLUGIN_PATH . 'includes/class-rts-wpum-qr.php';
    }

    private function init_hooks()
    {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('wp_ajax_rts_track_survey_start', array($this, 'ajax_track_survey_start'));
        add_action('wp_ajax_nopriv_rts_track_survey_start', array($this, 'ajax_track_survey_start'));
        add_action('wp_ajax_rts_track_question_answer', array($this, 'ajax_track_question_answer'));
        add_action('wp_ajax_nopriv_rts_track_question_answer', array($this, 'ajax_track_question_answer'));
        add_action('wp_ajax_rts_track_abandonment', array($this, 'ajax_track_abandonment'));
        add_action('wp_ajax_nopriv_rts_track_abandonment', array($this, 'ajax_track_abandonment'));
        add_action('wp_ajax_rts_complete_survey', array($this, 'ajax_complete_survey'));
        add_action('wp_ajax_nopriv_rts_complete_survey', array($this, 'ajax_complete_survey'));
        add_action('wp_ajax_rts_track_step_change', array($this, 'ajax_track_step_change'));
        add_action('wp_ajax_nopriv_rts_track_step_change', array($this, 'ajax_track_step_change'));

        add_action('wp_ajax_rts_check_survey_status', array($this, 'ajax_check_survey_status'));
        add_action('wp_ajax_nopriv_rts_check_survey_status', array($this, 'ajax_check_survey_status'));

        //for accurate location:        
        add_action('wp_ajax_rts_update_location', array($this, 'ajax_update_location'));
        add_action('wp_ajax_nopriv_rts_update_location', array($this, 'ajax_update_location'));
        add_action('wp_ajax_rts_geo_ip_fallback', array($this, 'ajax_geo_ip_fallback'));
        add_action('wp_ajax_nopriv_rts_geo_ip_fallback', array($this, 'ajax_geo_ip_fallback'));

        add_action('wp_ajax_rts_save_registration', array($this, 'ajax_save_registration'));
        add_action('wp_ajax_nopriv_rts_save_registration', array($this, 'ajax_save_registration'));

        add_action('wp_ajax_rts_get_analytics_data', array($this, 'ajax_get_analytics_data'));
        add_action('wp_ajax_rts_export_analytics', array($this, 'ajax_export_analytics'));
        add_action('wp_ajax_rts_archive_analytics', array($this, 'ajax_archive_analytics'));

        add_action('wp_ajax_rts_check_registration_status', array($this, 'ajax_check_registration_status'));

        add_action('wp_ajax_rts_track_share', array($this, 'ajax_track_share'));

        add_action('wp_ajax_rts_track_review_changes', array($this, 'ajax_track_review_changes'));
        add_action('wp_ajax_nopriv_rts_track_review_changes', array($this, 'ajax_track_review_changes'));
    }

}
