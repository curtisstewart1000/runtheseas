<?php
/**
 * Plugin Name: Run The Seas — Admin Platform
 * Description: Real, working WordPress-native implementation of the core critical path from the
 *              Run The Seas Admin Platform specification — survey engine with conditional logic,
 *              registration, email verification, Cabin Credit issuance, referral tracking,
 *              trophies, and subscription/unsubscribe management. Built as a WordPress custom
 *              plugin (PHP + $wpdb custom tables + WP REST API), mirroring the same business
 *              rules already proven in the Node.js prototype, so the two can be directly compared.
 * Version: 1.22.4
 * Author: Run The Seas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'RTSAP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RTSAP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RTSAP_VERSION', '1.22.4' );
define( 'RTSAP_DB_VERSION', '1.17.5' );

require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-db.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-data-mapper.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-auth.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-production.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-business-logic.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-business-logic-2.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-business-logic-3.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-business-logic-4.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-business-logic-5.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-business-logic-6.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-business-logic-7.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-rest-api.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-rest-api-2.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-rest-api-3.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-rest-api-4.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-rest-api-5.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-rest-api-6.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-rest-api-7.php';
if ( is_admin() ) {
	require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-admin-menu.php';
	require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-admin-menu-2.php';
	require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-admin-menu-3.php';
	require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-admin-menu-4.php';
	require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-admin-menu-5.php';
	require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-admin-menu-6.php';
	require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-admin-menu-7.php';
}
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-shortcodes.php';
require_once RTSAP_PLUGIN_DIR . 'includes/class-rts-frontend-dashboard.php';

register_activation_hook( __FILE__, array( 'RTS_DB', 'create_tables' ) );
register_activation_hook( __FILE__, array( 'RTSAP_Data_Mapper', 'sync' ) );
register_activation_hook( __FILE__, array( 'RTS_Business_Logic_4', 'register_roles' ) );
register_activation_hook( __FILE__, array( 'RTS_Production', 'schedule_cron' ) );
register_deactivation_hook( __FILE__, array( 'RTS_Production', 'unschedule_cron' ) );

add_action( 'plugins_loaded', function () {
	RTS_REST_API::init();
	RTS_REST_API_2::init();
	RTS_REST_API_3::init();
	RTS_REST_API_4::init();
	RTS_REST_API_5::init();
	RTS_REST_API_6::init();
	RTS_REST_API_7::init();
	if ( is_admin() ) {
		RTS_Admin_Menu::init();
		RTS_Admin_Menu_2::init();
		RTS_Admin_Menu_3::init();
		RTS_Admin_Menu_4::init();
		RTS_Admin_Menu_5::init();
		RTS_Admin_Menu_6::init();
		RTS_Admin_Menu_7::init();
	}
	RTS_Shortcodes::init();
	RTSAP_Frontend_Dashboard::init();
	RTS_Production::init();
	RTSAP_Data_Mapper::init();
	RTS_Business_Logic_4::init_security_monitor();
	RTS_Business_Logic_4::init_backup_integration();
} );

// Import exact editable copies from the active survey plugin's production
// transactional-email renderers after both plugins have initialized.
add_action( 'plugins_loaded', array( 'RTS_DB', 'sync_production_transactional_email_templates' ), 30 );

// Safety net: if the DB version stored in options doesn't match, re-run table creation.
// This is the WordPress-idiomatic equivalent of the Node prototype's idempotent seed.js —
// dbDelta() is safe to re-run and will alter existing tables to match rather than erroring.
add_action( 'plugins_loaded', function () {
	if ( get_option( 'rts_admin_platform_db_version' ) !== RTSAP_DB_VERSION ) {
		RTS_DB::create_tables();
		RTSAP_Data_Mapper::sync();
		RTS_Business_Logic_4::register_roles();
		RTS_Production::schedule_cron();
	}
} );
