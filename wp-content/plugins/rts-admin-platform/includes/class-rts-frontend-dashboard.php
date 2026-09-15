<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Front-end-only home for the four RTS platform roles.
 *
 * Existing screen callbacks and capability checks are reused so the front-end
 * and wp-admin implementations cannot drift into different permission models.
 */
class RTSAP_Frontend_Dashboard {

	const SHORTCODE = 'rts_admin_platform_dashboard';
	const PAGE_SLUG = 'run-the-seas-admin';
	const PAGE_OPTION = 'rts_admin_dashboard_page_id';

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		add_action( 'init', array( __CLASS__, 'ensure_page' ), 30 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_login', array( __CLASS__, 'mark_login_redirect' ), 20, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'canonicalize_legacy_page_arg' ), -1 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_after_frontend_login' ), 0 );
		add_action( 'admin_init', array( __CLASS__, 'block_wp_admin' ), -1 );
		add_action( 'admin_head', array( __CLASS__, 'style_fluent_embed' ), PHP_INT_MAX );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), PHP_INT_MAX, 3 );
		add_filter( 'admin_url', array( __CLASS__, 'filter_admin_url' ), 20, 3 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ), PHP_INT_MAX );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_inactive_participant_login' ), 30, 2 );
	}

	public static function role_slugs() {
		return array_keys( RTS_Business_Logic_4::ROLES );
	}

	public static function is_platform_user( $user = null ) {
		$user = $user instanceof WP_User ? $user : wp_get_current_user();
		return $user && $user->exists() && (bool) array_intersect( self::role_slugs(), (array) $user->roles );
	}

	/** Prevent deactivated participant accounts from starting a new WordPress session. */
	public static function block_inactive_participant_login( $user, $password = '' ) {
		if ( is_wp_error( $user ) || ! $user instanceof WP_User || user_can( $user, 'manage_options' ) || self::is_platform_user( $user ) ) { return $user; }
		global $wpdb;
		$status = $wpdb->get_var( $wpdb->prepare(
			"SELECT account_status FROM " . RTS_DB::table( 'participants' ) . " WHERE user_id = %d OR email = %s ORDER BY (user_id = %d) DESC LIMIT 1",
			$user->ID, $user->user_email, $user->ID
		) );
		if ( in_array( $status, array( 'inactive', 'suspended' ), true ) ) {
			return new WP_Error( 'rts_account_inactive', __( '<strong>Account inactive.</strong> Please contact Run The Seas support if you believe this is a mistake.', 'run-the-seas' ) );
		}
		return $user;
	}

	public static function ensure_page() {
		$page_id = absint( get_option( self::PAGE_OPTION ) );
		if ( $page_id && 'page' === get_post_type( $page_id ) ) { return; }

		$page = get_page_by_path( self::PAGE_SLUG );
		if ( $page instanceof WP_Post ) {
			update_option( self::PAGE_OPTION, (int) $page->ID );
			return;
		}

		$page_id = wp_insert_post( array(
			'post_title'   => __( 'Run The Seas Admin', 'run-the-seas' ),
			'post_name'    => self::PAGE_SLUG,
			'post_content' => '[' . self::SHORTCODE . ']',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		) );
		if ( ! is_wp_error( $page_id ) ) { update_option( self::PAGE_OPTION, (int) $page_id ); }
	}

	public static function dashboard_url( $screen = '', $args = array() ) {
		global $wp_rewrite;
		$page_id = absint( get_option( self::PAGE_OPTION ) );
		// admin_url() may be filtered by another plugin during plugins_loaded,
		// before $wp_rewrite exists. get_permalink() is not safe that early.
		$url = home_url( '/' . self::PAGE_SLUG . '/' );
		if ( did_action( 'init' ) && $wp_rewrite instanceof WP_Rewrite && $page_id && get_post_status( $page_id ) ) {
			$permalink = get_permalink( $page_id );
			if ( $permalink ) { $url = $permalink; }
		}
		if ( $screen ) { $args = array_merge( array( 'rts_page' => sanitize_key( $screen ) ), $args ); }
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/** Build a screen URL that works in both the front-end shell and wp-admin. */
	public static function screen_url( $screen, $args = array() ) {
		$screen = sanitize_key( $screen );
		if ( self::is_platform_user() ) { return self::dashboard_url( $screen, $args ); }
		return add_query_arg( array_merge( array( 'page' => $screen ), $args ), admin_url( 'admin.php' ) );
	}

	/** Preserve the selected screen when a GET form replaces the URL query string. */
	public static function screen_field( $screen ) {
		$name = self::is_platform_user() ? 'rts_page' : 'page';
		return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( sanitize_key( $screen ) ) . '">';
	}

	public static function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		return self::is_platform_user( $user ) ? self::dashboard_url() : $redirect_to;
	}

	private static function login_redirect_key( $user_id ) {
		return 'rtsap_login_redirect_' . absint( $user_id );
	}

	public static function mark_login_redirect( $user_login, $user ) {
		if ( self::is_platform_user( $user ) ) {
			set_transient( self::login_redirect_key( $user->ID ), 1, 5 * MINUTE_IN_SECONDS );
		}
	}

	/** Catch branded login plugins that ignore WordPress's login_redirect filter. */
	public static function redirect_after_frontend_login() {
		if ( ! self::is_platform_user() ) { return; }
		$user_id = get_current_user_id();
		$key = self::login_redirect_key( $user_id );
		if ( ! get_transient( $key ) ) { return; }

		$page_id = absint( get_option( self::PAGE_OPTION ) );
		$request_path = trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );
		$dashboard_path = trim( (string) wp_parse_url( self::dashboard_url(), PHP_URL_PATH ), '/' );
		if ( ( $page_id && is_page( $page_id ) ) || $request_path === $dashboard_path ) {
			delete_transient( $key );
			return;
		}

		delete_transient( $key );
		wp_safe_redirect( self::dashboard_url() );
		exit;
	}

	public static function hide_admin_bar( $show ) {
		return self::is_platform_user() ? false : $show;
	}

	public static function body_class( $classes ) {
		$page_id = absint( get_option( self::PAGE_OPTION ) );
		if ( self::is_platform_user() && $page_id && is_page( $page_id ) ) {
			$classes[] = 'rtsap-staff-dashboard-page';
		}
		return $classes;
	}

	public static function block_wp_admin() {
		if ( ! self::is_platform_user() || ! is_admin() || wp_doing_ajax() ) { return; }
		global $pagenow;
		if ( in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php', 'async-upload.php' ), true ) ) { return; }
		if ( self::is_allowed_fluent_embed_request() ) { return; }
		wp_safe_redirect( self::dashboard_url() );
		exit;
	}

	private static function fluent_embed_action( $form_id, $route ) {
		return 'rtsap_fluent_embed_' . absint( $form_id ) . '_' . sanitize_key( $route );
	}

	private static function raw_admin_url() {
		return site_url( '/wp-admin/admin.php', 'admin' );
	}

	private static function fluent_embed_admin_url( $form_id, $route = 'editor', $extra = array() ) {
		$form_id = absint( $form_id );
		$route = in_array( $route, array( 'editor', 'settings', 'entries' ), true ) ? $route : 'editor';
		$args = array_merge( array(
			'page'         => 'fluent_forms',
			'form_id'      => $form_id,
			'route'        => $route,
			'rtsap_embed'  => 1,
			'_rtsap_nonce' => wp_create_nonce( self::fluent_embed_action( $form_id, $route ) ),
		), $extra );
		return add_query_arg( $args, self::raw_admin_url() );
	}

	public static function is_allowed_fluent_embed_request() {
		global $pagenow;
		if ( 'admin.php' !== $pagenow || ! current_user_can( 'rts_manage' ) || ! current_user_can( 'fluentform_forms_manager' ) ) { return false; }
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		$route = isset( $_GET['route'] ) ? sanitize_key( wp_unslash( $_GET['route'] ) ) : '';
		$nonce = isset( $_GET['_rtsap_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_rtsap_nonce'] ) ) : '';
		return 'fluent_forms' === $page
			&& $form_id > 0
			&& in_array( $route, array( 'editor', 'settings', 'entries' ), true )
			&& ! empty( $_GET['rtsap_embed'] )
			&& wp_verify_nonce( $nonce, self::fluent_embed_action( $form_id, $route ) );
	}

	/** Remove WordPress administration chrome from the one signed Fluent editor view. */
	public static function style_fluent_embed() {
		if ( ! self::is_allowed_fluent_embed_request() ) { return; }
		echo '<style id="rtsap-fluent-embed-css">html.wp-toolbar{padding-top:0!important}#wpadminbar,#adminmenumain,#wpfooter,.ff_global_menu{display:none!important}#wpcontent,#wpfooter{margin-left:0!important}#wpcontent{padding-left:0!important}#wpbody-content{padding-bottom:0!important}.update-nag,.notice:not(.fluentform-notice){display:none!important}</style>';
	}

	/** Convert callbacks' wp-admin links and post-action redirects to the front-end shell. */
	public static function filter_admin_url( $url, $path, $blog_id ) {
		if ( ! self::is_platform_user() ) { return $url; }
		$path = ltrim( (string) $path, '/' );
		if ( ! str_starts_with( $path, 'admin.php' ) ) { return $url; }

		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$args = array();
		if ( $query ) { parse_str( $query, $args ); }
		$screen = isset( $args['page'] ) ? sanitize_key( $args['page'] ) : '';

		// Keep Fluent Forms route changes inside the signed embedded editor.
		if ( self::is_allowed_fluent_embed_request() && 'fluent_forms' === $screen ) {
			$form_id = isset( $args['form_id'] ) ? absint( $args['form_id'] ) : absint( $_GET['form_id'] ?? 0 );
			$route = isset( $args['route'] ) ? sanitize_key( $args['route'] ) : sanitize_key( $_GET['route'] ?? 'editor' );
			$fragment = (string) wp_parse_url( $url, PHP_URL_FRAGMENT );
			unset( $args['page'], $args['form_id'], $args['route'], $args['rtsap_embed'], $args['_rtsap_nonce'] );
			$embed_url = self::fluent_embed_admin_url( $form_id, $route, $args );
			return $fragment ? $embed_url . '#' . $fragment : $embed_url;
		}

		unset( $args['page'] );

		if ( $screen && ! str_starts_with( $screen, 'rts-' ) ) {
			return self::dashboard_url( 'rts-surveys', array( 'rts_msg' => rawurlencode( __( 'This WordPress admin screen is unavailable to front-end staff accounts.', 'run-the-seas' ) ) ) );
		}
		return self::dashboard_url( $screen, $args );
	}

	public static function canonicalize_legacy_page_arg() {
		if ( ! self::is_platform_user() || ! is_page( absint( get_option( self::PAGE_OPTION ) ) ) ) { return; }
		$legacy = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$legacy_fluent = empty( $_GET['rts_page'] )
			&& ! empty( $_GET['form_id'] )
			&& isset( $_GET['route'] )
			&& in_array( sanitize_key( wp_unslash( $_GET['route'] ) ), array( 'editor', 'settings', 'entries' ), true )
			&& current_user_can( 'rts_manage' );
		if ( ! str_starts_with( $legacy, 'rts-' ) && ! $legacy_fluent ) { return; }
		$args = wp_unslash( $_GET );
		unset( $args['page'] );
		$args['rts_page'] = $legacy_fluent ? 'rts-fluent-form' : $legacy;
		wp_safe_redirect( add_query_arg( array_map( 'sanitize_text_field', $args ), self::dashboard_url() ) );
		exit;
	}

	public static function enqueue_assets() {
		if ( ! is_page( absint( get_option( self::PAGE_OPTION ) ) ) ) { return; }
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'rts-admin-platform', RTSAP_PLUGIN_URL . 'assets/admin-survey.css', array(), RTSAP_VERSION );
		wp_enqueue_style( 'rts-admin-platform-frontend', RTSAP_PLUGIN_URL . 'assets/frontend-dashboard.css', array( 'rts-admin-platform' ), RTSAP_VERSION );
	}

	private static function pages() {
		return array(
			'rts-admin'              => array( 'Executive Dashboard', 'rts_view', array( 'RTS_Admin_Menu_4', 'render_exec' ) ),
			'rts-super-admin'        => array( 'Super Admin Dashboard', 'rts_manage_admins', array( 'RTS_Admin_Menu_4', 'render_super' ) ),
			'rts-participants'       => array( 'Participants', 'rts_view', array( 'RTS_Admin_Menu', 'render_participants' ) ),
			'rts-surveys'            => array( 'Survey Administration', 'rts_view', array( 'RTS_Admin_Menu_2', 'render_surveys' ) ),
			'rts-survey-reporting'   => array( 'Survey Reporting', 'rts_view', array( 'RTS_Admin_Menu_2', 'render_survey_reporting' ) ),
			'rts-verification-queue' => array( 'Verification Queue', 'rts_view', array( 'RTS_Admin_Menu_2', 'render_queue' ) ),
			'rts-email-templates'    => array( 'Email Templates', 'rts_view', array( 'RTS_Admin_Menu_2', 'render_templates' ) ),
			'rts-cabin-credits'      => array( 'Cabin Credits', 'rts_view', array( 'RTS_Admin_Menu_3', 'render_credits' ) ),
			'rts-trophies'           => array( 'Trophies', 'rts_view', array( 'RTS_Admin_Menu_3', 'render_trophies' ) ),
			'rts-referrals'          => array( 'Referrals & Draws', 'rts_view', array( 'RTS_Admin_Menu_3', 'render_referrals' ) ),
			'rts-subscriptions'      => array( 'Subscriptions', 'rts_view', array( 'RTS_Admin_Menu_3', 'render_subscriptions' ) ),
			'rts-broadcast'          => array( 'Broadcast', 'rts_send_bulk', array( 'RTS_Admin_Menu_3', 'render_broadcast' ) ),
			'rts-email-campaigns'    => array( 'Email Campaigns', 'rts_view', array( 'RTS_Admin_Menu_5', 'render_campaigns' ) ),
			'rts-email-reporting'    => array( 'Email Reporting', 'rts_view', array( 'RTS_Admin_Menu_5', 'render_reporting' ) ),
			'rts-ad-campaigns'       => array( 'Ad Campaign Analysis', 'rts_view', array( 'RTS_Admin_Menu_5', 'render_ads' ) ),
			'rts-interest-lists'     => array( 'Interest Lists', 'rts_view', array( 'RTS_Admin_Menu_5', 'render_interest' ) ),
			'rts-fraud'              => array( 'Fraud Detection', 'rts_view', array( 'RTS_Admin_Menu_5', 'render_fraud' ) ),
			'rts-feedback'           => array( 'Customer Feedback', 'rts_view', array( 'RTS_Admin_Menu_6', 'render_feedback' ) ),
			'rts-questions'          => array( 'Question Queue', 'rts_view', array( 'RTS_Admin_Menu_6', 'render_questions' ) ),
			'rts-customer'           => array( 'Who Is The Customer', 'rts_view', array( 'RTS_Admin_Menu_6', 'render_customer' ) ),
			'rts-cms'                => array( 'Website Content', 'rts_content', array( 'RTS_Admin_Menu_6', 'render_cms' ) ),
			'rts-export'             => array( 'Export Center', 'rts_view', array( 'RTS_Admin_Menu_6', 'render_export' ) ),
			'rts-report-builder'     => array( 'Report Builder', 'rts_view', array( 'RTS_Admin_Menu_7', 'render_builder' ) ),
			'rts-saved-reports'      => array( 'Saved Reports', 'rts_view', array( 'RTS_Admin_Menu_7', 'render_saved' ) ),
			'rts-segments'           => array( 'Segments', 'rts_view', array( 'RTS_Admin_Menu_7', 'render_segments' ) ),
			'rts-quick-reports'      => array( 'Quick Reports', 'rts_view', array( 'RTS_Admin_Menu_7', 'render_quick' ) ),
			'rts-action-items'       => array( 'Action Items', 'rts_view', array( 'RTS_Admin_Menu_7', 'render_actions' ) ),
			'rts-forecast'           => array( 'Cabin Sales Forecast', 'rts_view', array( 'RTS_Admin_Menu_7', 'render_forecast' ) ),
			'rts-fr-outreach'        => array( 'FR Outreach', 'rts_view', array( 'RTS_Admin_Menu_7', 'render_fr' ) ),
			'rts-logic-map'          => array( 'Survey Logic Map', 'rts_view', array( 'RTS_Admin_Menu_7', 'render_logic' ) ),
			'rts-audit-log'          => array( 'Audit Log', 'rts_view', array( 'RTS_Admin_Menu', 'render_audit_log' ) ),
			'rts-security'           => array( 'Security', 'rts_system', array( 'RTS_Admin_Menu_4', 'render_security' ) ),
			'rts-admins'             => array( 'Administrators & Roles', 'rts_manage_admins', array( 'RTS_Admin_Menu_4', 'render_admins' ) ),
			'rts-backup'             => array( 'Backup & System', 'rts_system', array( 'RTS_Admin_Menu_4', 'render_backup' ) ),
			'rts-settings'           => array( 'Settings & Integrations', 'rts_system', array( 'RTS_Production', 'render_settings' ) ),
			'rts-participant-profile'=> array( 'Participant Profile', 'rts_view', array( 'RTS_Admin_Menu_2', 'render_profile' ), true ),
			'rts-fluent-form'        => array( 'Edit Fluent Form', 'rts_manage', array( __CLASS__, 'render_fluent_form' ), true ),
		);
	}

	/** Load the large admin renderers only for the staff dashboard itself. */
	private static function load_admin_renderers() {
		foreach ( array(
			'class-rts-admin-menu.php',
			'class-rts-admin-menu-2.php',
			'class-rts-admin-menu-3.php',
			'class-rts-admin-menu-4.php',
			'class-rts-admin-menu-5.php',
			'class-rts-admin-menu-6.php',
			'class-rts-admin-menu-7.php',
		) as $file ) {
			require_once RTSAP_PLUGIN_DIR . 'includes/' . $file;
		}
	}

	public static function render_fluent_form() {
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		$route = isset( $_GET['route'] ) ? sanitize_key( wp_unslash( $_GET['route'] ) ) : 'editor';
		if ( ! $form_id || ! in_array( $route, array( 'editor', 'settings', 'entries' ), true ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Edit Fluent Form', 'run-the-seas' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'A valid Fluent Form was not selected.', 'run-the-seas' ) . '</p></div></div>';
			return;
		}
		$extra = array();
		if ( ! empty( $_GET['sub_route'] ) ) { $extra['sub_route'] = sanitize_key( wp_unslash( $_GET['sub_route'] ) ); }
		$src = self::fluent_embed_admin_url( $form_id, $route, $extra );
		echo '<div class="rtsap-fluent-editor"><div class="rtsap-fluent-editor__bar"><div><a href="' . esc_url( self::dashboard_url( 'rts-surveys' ) ) . '">← ' . esc_html__( 'Survey Administration', 'run-the-seas' ) . '</a><h1>' . esc_html__( 'Edit Fluent Form', 'run-the-seas' ) . ' #' . (int) $form_id . '</h1></div><a class="button" href="' . esc_url( RTS_Business_Logic_2::fluent_form_preview_url( $form_id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Preview Form', 'run-the-seas' ) . ' ↗</a></div>';
		echo '<iframe class="rtsap-fluent-editor__frame" src="' . esc_url( $src ) . '" title="' . esc_attr__( 'Fluent Forms editor', 'run-the-seas' ) . '"></iframe></div>';
	}

	/** Navigation order and grouping from the approved v17 platform wireframe. */
	private static function navigation() {
		return array(
			'Dashboards & Overview' => array( 'rts-admin', 'rts-super-admin', 'rts-security', 'rts-forecast' ),
			'Action Items' => array( 'rts-action-items' ),
			'Advertising & Campaigns' => array( 'rts-ad-campaigns', 'rts-interest-lists' ),
			'Customer Feedback' => array( 'rts-feedback', 'rts-questions', 'rts-customer' ),
			'Survey Management' => array( 'rts-surveys', 'rts-logic-map', 'rts-survey-reporting' ),
			'Participants' => array( 'rts-participants', 'rts-verification-queue' ),
			'Founding Runner Program' => array( 'rts-cabin-credits', 'rts-trophies', 'rts-referrals', 'rts-fr-outreach' ),
			'Marketing & Email' => array( 'rts-email-campaigns', 'rts-email-templates', 'rts-email-reporting', 'rts-subscriptions', 'rts-broadcast' ),
			'Reporting & Analytics' => array( 'rts-report-builder', 'rts-saved-reports', 'rts-export', 'rts-segments', 'rts-quick-reports' ),
			'Content & Website' => array( 'rts-cms', 'rts-fraud' ),
			'Administration & System' => array( 'rts-admins', 'rts-audit-log', 'rts-backup', 'rts-settings' ),
		);
	}

	private static function allowed_pages() {
		return array_filter( self::pages(), function ( $page ) { return current_user_can( $page[1] ); } );
	}

	private static function current_screen( $allowed ) {
		$screen = isset( $_GET['rts_page'] ) ? sanitize_key( wp_unslash( $_GET['rts_page'] ) ) : '';
		if ( ! $screen && isset( $_GET['page'] ) ) { $screen = sanitize_key( wp_unslash( $_GET['page'] ) ); }
		if ( isset( $allowed[ $screen ] ) ) { return $screen; }
		if ( isset( $allowed['rts-admin'] ) ) { return 'rts-admin'; }
		return array_key_first( $allowed );
	}

	private static function role_label() {
		$user = wp_get_current_user();
		foreach ( self::role_slugs() as $slug ) {
			if ( in_array( $slug, (array) $user->roles, true ) ) { return RTS_Business_Logic_4::ROLES[ $slug ]['label']; }
		}
		return __( 'RTS Staff', 'run-the-seas' );
	}

	public static function render_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p class="rtsap-access-message">' . esc_html__( 'Please sign in to access the Run The Seas staff dashboard.', 'run-the-seas' ) . '</p>';
		}
		if ( ! self::is_platform_user() || ! current_user_can( 'rts_dashboard' ) ) {
			return '<p class="rtsap-access-message">' . esc_html__( 'You do not have permission to view this dashboard.', 'run-the-seas' ) . '</p>';
		}

		self::load_admin_renderers();
		$allowed = self::allowed_pages();
		$screen = self::current_screen( $allowed );
		$user = wp_get_current_user();
		ob_start();
		echo '<div class="rtsap-frontend rtsap-admin-page">';
		echo '<header class="rtsap-frontend__header"><div><span class="rtsap-frontend__mark" aria-hidden="true">⚓</span><span><b>RUN THE SEAS</b><small>' . esc_html__( 'Staff Operations Platform', 'run-the-seas' ) . '</small></span></div><div class="rtsap-frontend__user"><span><b>' . esc_html( $user->display_name ) . '</b><small>' . esc_html( self::role_label() ) . '</small></span><a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Log out', 'run-the-seas' ) . '</a></div></header>';
		$logout_url = wp_logout_url( home_url( '/' ) );
		echo '<div class="rtsap-frontend__layout"><aside class="rtsap-frontend__nav" aria-label="' . esc_attr__( 'Run The Seas administration', 'run-the-seas' ) . '">';
		if ( ! $allowed ) { echo '<p>' . esc_html__( 'No operational modules are assigned to this role.', 'run-the-seas' ) . '</p>'; }
		foreach ( self::navigation() as $group => $slugs ) {
			$visible = array_values( array_filter( $slugs, function ( $slug ) use ( $allowed ) { return isset( $allowed[ $slug ] ) && empty( $allowed[ $slug ][3] ); } ) );
			if ( ! $visible ) { continue; }
			echo '<div class="rtsap-frontend__nav-group"><div class="rtsap-frontend__nav-title">' . esc_html( $group ) . '</div>';
			foreach ( $visible as $slug ) {
				$page = $allowed[ $slug ];
				echo '<a class="' . esc_attr( $slug === $screen ? 'is-active' : '' ) . '" href="' . esc_url( self::dashboard_url( $slug ) ) . '">' . esc_html( $page[0] ) . '</a>';
			}
			echo '</div>';
		}
		echo '<a class="rtsap-frontend__nav-logout" href="' . esc_url( $logout_url ) . '"><span class="dashicons dashicons-exit" aria-hidden="true"></span>' . esc_html__( 'Log out', 'run-the-seas' ) . '</a>';
		echo '</aside><main class="rtsap-frontend__content">';
		if ( $screen && isset( $allowed[ $screen ] ) && is_callable( $allowed[ $screen ][2] ) ) {
			call_user_func( $allowed[ $screen ][2] );
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Run The Seas Staff Dashboard', 'run-the-seas' ) . '</h1><p>' . esc_html__( 'Your account is active, but no operational modules have been assigned to this role yet.', 'run-the-seas' ) . '</p></div>';
		}
		echo '</main></div></div>';
		return ob_get_clean();
	}
}
