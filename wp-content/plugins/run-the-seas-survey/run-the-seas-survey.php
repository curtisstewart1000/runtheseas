<?php

/**
 * Plugin Name: Run The Seas - Survey
 * Plugin URI: https://runtheseas.com/
 * Description: Advanced survey management with gamification, 42.2K Referral Marathon Challenge
 * Version: 1.3.45
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
define('RTS_VERSION', '1.3.45');
define('RTS_MANAGE_CAPABILITY', 'rts_manage_surveys');

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

    $plugin = RunTheSeasPlugin::get_instance();
    if ($plugin->ensure_participant_verification_columns()) {
        update_option('rts_verification_schema_version', $schema_version, false);
    }
}
add_action('plugins_loaded', 'rts_maybe_upgrade_verification_schema', 11);

/** Preserve complete registration data and detected survey location. */
function rts_maybe_upgrade_participant_registration_schema()
{
    $schema_version = '1.2';
    if (get_option('rts_participant_registration_schema_version') === $schema_version) {
        return;
    }

    $plugin = RunTheSeasPlugin::get_instance();
    if ($plugin->ensure_participant_registration_columns()) {
        update_option('rts_participant_registration_schema_version', $schema_version, false);
    }
}
add_action('plugins_loaded', 'rts_maybe_upgrade_participant_registration_schema', 12);

/** Backfill registration details into WordPress user meta for existing users. */
function rts_maybe_backfill_participant_user_meta()
{
    $sync_version = '1.2';
    if (get_option('rts_participant_user_meta_sync_version') === $sync_version) {
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
