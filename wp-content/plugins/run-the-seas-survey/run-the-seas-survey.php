<?php

/**
 * Plugin Name: Run The Seas - Survey
 * Plugin URI: https://runtheseas.com/
 * Description: Advanced survey management with gamification, 42.2K Referral Marathon Challenge
 * Version: 1.3.53
 * License: GPL v2 or later
 * Text Domain: run-the-seas
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('RTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RTS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('RTS_VERSION', '1.3.53');
define('RTS_MANAGE_CAPABILITY', 'rts_manage_surveys');

/** Atomically prevent concurrent web requests from running the same upgrade. */
function rts_acquire_upgrade_lock($name, $ttl = 600)
{
    $option_name = 'rts_upgrade_lock_' . sanitize_key($name);
    $locked_at = absint(get_option($option_name));
    if ($locked_at && (time() - $locked_at) < absint($ttl)) {
        return false;
    }
    if ($locked_at) {
        delete_option($option_name);
    }

    return add_option($option_name, time(), '', 'no');
}

function rts_release_upgrade_lock($name)
{
    delete_option('rts_upgrade_lock_' . sanitize_key($name));
}

/**
 * Create an opaque bearer token for one survey submission.
 *
 * The public numeric tracking ID is not an authorization credential. The
 * token binds that ID to the submission's random UUID without exposing the
 * UUID itself in URLs, forms, or AJAX requests.
 */
function rts_create_tracking_access_token($tracking_id, $submission_id)
{
    $tracking_id = absint($tracking_id);
    $submission_id = (string) $submission_id;
    if (!$tracking_id || '' === $submission_id) {
        return '';
    }

    return hash_hmac('sha256', $tracking_id . '|' . $submission_id, wp_salt('auth'));
}

/** Verify that a bearer token belongs to the requested tracking record. */
function rts_verify_tracking_access($tracking_id, $access_token)
{
    global $wpdb;

    $tracking_id = absint($tracking_id);
    $access_token = strtolower(trim((string) $access_token));
    if (!$tracking_id || !preg_match('/^[a-f0-9]{64}$/', $access_token)) {
        return false;
    }

    $submission_id = $wpdb->get_var($wpdb->prepare(
        "SELECT submission_id FROM {$wpdb->prefix}rts_survey_tracking WHERE id = %d",
        $tracking_id
    ));
    if (!is_string($submission_id) || '' === $submission_id) {
        return false;
    }

    return hash_equals(rts_create_tracking_access_token($tracking_id, $submission_id), $access_token);
}

/** Set a first-party cookie with consistent transport and SameSite flags. */
function rts_set_cookie($name, $value, $expires, $http_only = true)
{
    if (headers_sent()) {
        return false;
    }

    return setcookie($name, (string) $value, array(
        'expires'  => (int) $expires,
        'path'     => '/',
        'secure'   => is_ssl(),
        'httponly' => (bool) $http_only,
        'samesite' => 'Lax',
    ));
}

/** Retrieve a valid access token, upgrading the pre-1.3.48 UUID cookie. */
function rts_get_tracking_access_token_from_cookie($tracking_id)
{
    $tracking_id = absint($tracking_id);
    $access_token = sanitize_text_field(wp_unslash($_COOKIE['rts_tracking_token'] ?? ''));
    if (rts_verify_tracking_access($tracking_id, $access_token)) {
        return $access_token;
    }

    $legacy_submission_id = sanitize_text_field(wp_unslash($_COOKIE['rts_submission_id'] ?? ''));
    if (!$tracking_id || '' === $legacy_submission_id) {
        return '';
    }

    $access_token = rts_create_tracking_access_token($tracking_id, $legacy_submission_id);
    if (!rts_verify_tracking_access($tracking_id, $access_token)) {
        return '';
    }

    $has_consent = isset($_COOKIE['rts_survey_cookie_consent'])
        && 'accepted' === $_COOKIE['rts_survey_cookie_consent'];
    rts_set_cookie(
        'rts_tracking_token',
        $access_token,
        $has_consent ? time() + (DAY_IN_SECONDS * 30) : 0,
        true
    );

    return $access_token;
}

/** Lightweight per-client throttle for anonymous write endpoints. */
function rts_consume_rate_limit($scope, $limit, $window)
{
    $remote_address = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $key = 'rts_rl_' . md5((string) $scope . '|' . $remote_address);
    $count = absint(get_transient($key));
    if ($count >= absint($limit)) {
        return false;
    }

    set_transient($key, $count + 1, absint($window));
    return true;
}

/**
 * Move survey access tokens out of URLs before rendering a page.
 *
 * These are essential session cookies when analytics consent was declined;
 * consented visitors retain them for the existing 30-day survey window.
 */
function rts_capture_tracking_access_token()
{
    if (is_admin() || wp_doing_ajax() || empty($_GET['tracking_id'])) {
        return;
    }

    $tracking_id = absint($_GET['tracking_id']);
    $has_query_token = !empty($_GET['tracking_token']);
    $access_token = $has_query_token
        ? sanitize_text_field(wp_unslash($_GET['tracking_token']))
        : rts_get_tracking_access_token_from_cookie($tracking_id);
    if (!rts_verify_tracking_access($tracking_id, $access_token)) {
        return;
    }

    $has_consent = isset($_COOKIE['rts_survey_cookie_consent'])
        && 'accepted' === $_COOKIE['rts_survey_cookie_consent'];
    $expires = $has_consent ? time() + (DAY_IN_SECONDS * 30) : 0;
    rts_set_cookie('rts_tracking_id', $tracking_id, $expires, true);
    rts_set_cookie('rts_tracking_token', $access_token, $expires, true);

    if ($has_query_token) {
        wp_safe_redirect(remove_query_arg('tracking_token'));
        exit;
    }
}
add_action('template_redirect', 'rts_capture_tracking_access_token', 0);

/** Permit administrators to upload validated binary glTF trophy models. */
function rts_allow_glb_upload_mime($mimes)
{
    $mimes['glb'] = 'model/gltf-binary';
    return $mimes;
}
add_filter('upload_mimes', 'rts_allow_glb_upload_mime');

/** Confirm that a .glb upload has the binary glTF magic header before accepting it. */
function rts_validate_glb_upload($data, $file, $filename, $mimes, $real_mime)
{
    if ('glb' !== strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) || !is_readable($file)) {
        return $data;
    }

    $handle = fopen($file, 'rb');
    $magic = $handle ? fread($handle, 4) : '';
    if ($handle) {
        fclose($handle);
    }

    if ('glTF' === $magic) {
        $data['ext'] = 'glb';
        $data['type'] = 'model/gltf-binary';
        $data['proper_filename'] = $filename;
    }

    return $data;
}
add_filter('wp_check_filetype_and_ext', 'rts_validate_glb_upload', 10, 5);

/** Load the Three.js single-trophy renderer as an ES module. */
function rts_single_trophy_module_script_tag($tag, $handle, $src)
{
    if ('rts-single-trophy' !== $handle) {
        return $tag;
    }

    return '<script type="module" src="' . esc_url($src) . '"></script>' . "\n";
}
add_filter('script_loader_tag', 'rts_single_trophy_module_script_tag', 10, 3);

/** Keep legacy shortcode settings aligned with the whole-1K unlock model. */
function rts_normalize_marathon_target($target)
{
    $target = max(1, absint($target));

    return 42200 === $target ? 42000 : $target;
}

// Microsoft 365 only permits authenticated mailboxes (or explicitly granted
// aliases) in the From header. Keep every plugin email aligned with the
// mailbox configured in SMTP instead of falling back to the WP admin email.
if (!defined('RTS_MAIL_FROM_EMAIL')) {
    define('RTS_MAIL_FROM_EMAIL', 'noreply@runtheseas.com');
}
if (!defined('RTS_MAIL_FROM_NAME')) {
    define('RTS_MAIL_FROM_NAME', 'Run The Seas');
}

/**
 * Build consistent headers for email sent by this plugin.
 *
 * FluentSMTP's default connection is used when available. The constants act
 * as a fallback and may also be defined in wp-config.php. Filters allow other
 * environments to override the result without editing plugin files.
 */
function rts_mail_headers($content_type = 'text/html; charset=UTF-8', $headers = array())
{
    if (!is_array($headers)) {
        $headers = preg_split('/\r?\n/', (string) $headers, -1, PREG_SPLIT_NO_EMPTY);
    }

    $headers = array_values(array_filter($headers, function ($header) {
        return stripos((string) $header, 'Content-Type:') !== 0
            && stripos((string) $header, 'From:') !== 0;
    }));

    $from_email = RTS_MAIL_FROM_EMAIL;
    if (function_exists('fluentMailDefaultConnection')) {
        $smtp_connection = fluentMailDefaultConnection();
        if (!empty($smtp_connection['sender_email'])) {
            $from_email = $smtp_connection['sender_email'];
        }
    }

    $from_email = sanitize_email((string) apply_filters('rts_mail_from_email', $from_email));
    $from_name = sanitize_text_field((string) apply_filters('rts_mail_from_name', RTS_MAIL_FROM_NAME));

    $headers[] = 'Content-Type: ' . $content_type;
    $headers[] = sprintf('From: %s <%s>', $from_name, $from_email);

    return apply_filters('rts_mail_headers', $headers, $content_type);
}


// Load the core class and its concern-specific implementations.
require_once RTS_PLUGIN_PATH . 'includes/trait-rts-database-schema.php';
require_once RTS_PLUGIN_PATH . 'includes/trait-rts-frontend-assets.php';
require_once RTS_PLUGIN_PATH . 'includes/trait-rts-survey-ajax.php';
require_once RTS_PLUGIN_PATH . 'includes/trait-rts-registration-ajax.php';
require_once RTS_PLUGIN_PATH . 'includes/trait-rts-analytics-ajax.php';
require_once RTS_PLUGIN_PATH . 'includes/rts-email-template-integration.php';
require_once RTS_PLUGIN_PATH . 'includes/class-run-the-seas-plugin.php';

// Initialize the plugin
function rts_init()
{
    return RunTheSeasPlugin::get_instance();
}
add_action('plugins_loaded', 'rts_init');

/** Ensure verification tracking fields exist when upgrading an active install. */
function rts_maybe_upgrade_verification_schema()
{
    $schema_version = '1.0';
    if (get_option('rts_verification_schema_version') === $schema_version) {
        return;
    }
    if (!rts_acquire_upgrade_lock('verification_schema')) {
        return;
    }

    $plugin = RunTheSeasPlugin::get_instance();
    if ($plugin->ensure_participant_verification_columns()) {
        update_option('rts_verification_schema_version', $schema_version, false);
    }
    rts_release_upgrade_lock('verification_schema');
}
add_action('plugins_loaded', 'rts_maybe_upgrade_verification_schema', 11);

/** Preserve complete registration data and detected survey location. */
function rts_maybe_upgrade_participant_registration_schema()
{
    $schema_version = '1.2';
    if (get_option('rts_participant_registration_schema_version') === $schema_version) {
        return;
    }
    if (!rts_acquire_upgrade_lock('participant_registration_schema')) {
        return;
    }

    $plugin = RunTheSeasPlugin::get_instance();
    if ($plugin->ensure_participant_registration_columns()) {
        update_option('rts_participant_registration_schema_version', $schema_version, false);
    }
    rts_release_upgrade_lock('participant_registration_schema');
}
add_action('plugins_loaded', 'rts_maybe_upgrade_participant_registration_schema', 12);

/** Remove legacy duplicated registration PII and secrets from pending jobs. */
function rts_maybe_minimize_pending_registration_options()
{
    $privacy_version = '1.2';
    if (get_option('rts_privacy_pending_registration_version') === $privacy_version) {
        return;
    }
    if (!rts_acquire_upgrade_lock('pending_registration_privacy')) {
        return;
    }

    global $wpdb;
    $option_names = $wpdb->get_col(
        "SELECT option_name FROM {$wpdb->options}
         WHERE option_name REGEXP '^rts_pending_registration_[0-9]+$'"
    );
    foreach ((array) $option_names as $option_name) {
        $stored = get_option($option_name, array());
        $participant_id = absint(is_array($stored) ? ($stored['participant_id'] ?? 0) : 0);
        if (!$participant_id) {
            delete_option($option_name);
            continue;
        }

        delete_option($option_name);
        add_option($option_name, array('participant_id' => $participant_id), '', 'no');
    }

    $activity_table = $wpdb->prefix . 'rts_activity_logs';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $activity_table)) === $activity_table) {
        $wpdb->query(
            "UPDATE {$activity_table}
             SET description = CASE
                WHEN action = 'started' THEN 'Survey started'
                WHEN action = 'answer_updated' THEN 'Survey answer updated'
                WHEN action = 'completed' THEN 'Survey completed'
                WHEN action = 'location_update' THEN 'Browser location updated'
                WHEN action = 'location_fallback' THEN 'IP fallback location updated'
                WHEN action = 'review_changes' THEN 'Survey review changes recorded'
                WHEN action LIKE 'share_%' THEN 'Share event recorded'
                ELSE description
             END
             WHERE action IN ('started', 'answer_updated', 'completed', 'location_update', 'location_fallback', 'review_changes')
                OR action LIKE 'share_%'"
        );
    }

    $review_option_names = $wpdb->get_col(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name REGEXP '^rts_review_changes_[0-9]+$'"
    );
    foreach ((array) $review_option_names as $option_name) {
        delete_option($option_name);
    }

    // Retire unbounded per-visitor/per-participant flags. Current versions use
    // expiring, non-autoloaded transients for these short-lived states.
    foreach (array('rts_survey_skipped_registration_', 'rts_registration_processed_') as $legacy_prefix) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like($legacy_prefix) . '%'
        ));
    }

    delete_option('rts_pending_registration_privacy_version');
    update_option('rts_privacy_pending_registration_version', $privacy_version, false);
    rts_release_upgrade_lock('pending_registration_privacy');
}
add_action('plugins_loaded', 'rts_maybe_minimize_pending_registration_options', 13);

/** Backfill registration details into WordPress user meta for existing users. */
function rts_maybe_backfill_participant_user_meta()
{
    $sync_version = '1.2';
    if (get_option('rts_participant_user_meta_sync_version') === $sync_version) {
        return;
    }
    if (!rts_acquire_upgrade_lock('participant_user_meta_sync')) {
        return;
    }

    global $wpdb;
    $participant_ids = $wpdb->get_col(
        "SELECT id FROM {$wpdb->prefix}rts_participants
         WHERE user_id IS NOT NULL OR (email IS NOT NULL AND email != '')"
    );
    $registration = RunTheSeasPlugin::get_instance()->registration;
    foreach ((array) $participant_ids as $participant_id) {
        $registration->sync_participant_user_meta($participant_id);
    }

    update_option('rts_participant_user_meta_sync_version', $sync_version, false);
    rts_release_upgrade_lock('participant_user_meta_sync');
}
add_action('plugins_loaded', 'rts_maybe_backfill_participant_user_meta', 13);

// Register activation hook
function rts_activate_plugin()
{
    rts_register_admin_role();

    $plugin = RunTheSeasPlugin::get_instance();
    $plugin->create_tables();
    $plugin->create_registration_tables();
    $plugin->create_race_tables();

    if (!get_option('rts_qr_terms_version')) {
        update_option('rts_qr_terms_version', '1.0');
    }
}
register_activation_hook(__FILE__, 'rts_activate_plugin');

// Load feature modules. Each module owns its functions and hook registrations.
require_once RTS_PLUGIN_PATH . 'includes/rts-admin-dashboard.php';
require_once RTS_PLUGIN_PATH . 'includes/rts-admin-ajax-isolation.php';
require_once RTS_PLUGIN_PATH . 'includes/rts-member-shortcodes.php';
require_once RTS_PLUGIN_PATH . 'includes/rts-leaderboard-shortcodes.php';
require_once RTS_PLUGIN_PATH . 'includes/rts-dashboard-widgets.php';
require_once RTS_PLUGIN_PATH . 'includes/rts-survey-shortcodes.php';
require_once RTS_PLUGIN_PATH . 'includes/rts-marathon-challenge.php';
require_once RTS_PLUGIN_PATH . 'includes/rts-captains-suite.php';
require_once RTS_PLUGIN_PATH . 'includes/rts-captains-log.php';
require_once RTS_PLUGIN_PATH . 'includes/rts-journey-shortcode.php';
require_once RTS_PLUGIN_PATH . 'includes/rts-user-verification.php';
require_once RTS_PLUGIN_PATH . 'includes/rts-referrals-trophies.php';
require_once RTS_PLUGIN_PATH . 'includes/rts-auth-registration.php';
require_once RTS_PLUGIN_PATH . 'includes/rts-admin-user-profile.php';

add_filter('http_request_timeout', function ($timeout, $url) {
    if (
        strpos($url, 'graph.microsoft.com') !== false ||
        strpos($url, 'login.microsoftonline.com') !== false
    ) {
        return 20;
    }

    return $timeout;
}, 10, 2);
