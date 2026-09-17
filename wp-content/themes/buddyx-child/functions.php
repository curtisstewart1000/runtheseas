<?php
// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// BEGIN ENQUEUE PARENT ACTION
// AUTO GENERATED - Do not modify or remove comment markers above or below:

if ( !function_exists( 'chld_thm_cfg_locale_css' ) ):
    function chld_thm_cfg_locale_css( $uri ){
        if ( empty( $uri ) && is_rtl() && file_exists( get_template_directory() . '/rtl.css' ) )
            $uri = get_template_directory_uri() . '/rtl.css';
        return $uri;
    }
endif;
add_filter( 'locale_stylesheet_uri', 'chld_thm_cfg_locale_css' );
         
if ( !function_exists( 'child_theme_configurator_css' ) ):
    function child_theme_configurator_css() {
        wp_enqueue_style( 'chld_thm_cfg_child', trailingslashit( get_stylesheet_directory_uri() ) . 'style.css', array( 'buddyx-global','buddyx-tokens-applied','buddyx-site-loader','buddyx-load-fontawesome','buddyx-slick','buddyx-dark-mode' ) );
    }
endif;
add_action( 'wp_enqueue_scripts', 'child_theme_configurator_css', 10 );

// END ENQUEUE PARENT ACTION

/**
 * Captain's Suite login presentation.
 *
 * This stays in the child theme because it customizes the BuddyNext/BuddyX
 * theme surface. Its files deliberately live outside the child theme's
 * `assets` directory so they cannot be mistaken for BuddyX core overrides.
 */
add_filter( 'buddynext_auth_show_form_logo', '__return_true' );

function rts_child_auth_site_relative_path( $url_or_path ) {
	$path      = '/' . trim( (string) wp_parse_url( (string) $url_or_path, PHP_URL_PATH ), '/' ) . '/';
	$home_path = '/' . trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) . '/';

	if ( '//' !== $home_path && 0 === strpos( $path, $home_path ) ) {
		$path = '/' . trim( substr( $path, strlen( $home_path ) ), '/' ) . '/';
	}

	return $path;
}

function rts_child_auth_media_url( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}

	if ( ctype_digit( $value ) ) {
		return (string) wp_get_attachment_url( absint( $value ) );
	}

	if ( 0 === strpos( $value, '/' ) ) {
		return esc_url_raw( home_url( $value ) );
	}

	$url          = esc_url_raw( $value );
	$path         = (string) wp_parse_url( $url, PHP_URL_PATH );
	$content_path = trailingslashit( (string) wp_parse_url( content_url( '/' ), PHP_URL_PATH ) );
	if ( $path && $content_path && 0 === strpos( $path, $content_path ) ) {
		return esc_url_raw( content_url( ltrim( substr( $path, strlen( $content_path ) ), '/' ) ) );
	}

	return $url;
}

function rts_child_is_buddynext_auth_request() {
	if ( is_admin() ) {
		return false;
	}

	$request_path = rts_child_auth_site_relative_path( $_SERVER['REQUEST_URI'] ?? '/' );
	$is_auth      = get_query_var( 'bn_hub' ) === 'auth'
		|| in_array( $request_path, array( '/login/', '/login-2/' ), true )
		|| is_page( array( 'login', 'login-2' ) );

	if ( ! $is_auth && class_exists( '\\BuddyNext\\Core\\PageRouter' ) ) {
		$auth_path = rts_child_auth_site_relative_path( \BuddyNext\Core\PageRouter::auth_url() );
		$is_auth   = '//' !== $auth_path
			&& ( $request_path === $auth_path || 0 === strpos( $request_path, $auth_path ) );
	}

	return $is_auth;
}

/** Return whether this request is the login screen, not reset/verify/signup. */
function rts_child_is_buddynext_login_request() {
	if ( is_admin() || wp_doing_ajax() ) {
		return false;
	}

	$auth_action = sanitize_key( (string) get_query_var( 'bn_auth_action', '' ) );
	if ( 'auth' === get_query_var( 'bn_hub' ) ) {
		return in_array( $auth_action, array( '', 'login' ), true );
	}

	$request_path = rts_child_auth_site_relative_path( $_SERVER['REQUEST_URI'] ?? '/' );
	$login_paths  = array( '/login/', '/login-2/' );
	if ( class_exists( '\\BuddyNext\\Core\\PageRouter' ) ) {
		$login_paths[] = rts_child_auth_site_relative_path( \BuddyNext\Core\PageRouter::auth_url() );
	}

	return in_array( $request_path, array_unique( $login_paths ), true )
		|| is_page( array( 'login', 'login-2' ) );
}

/** Resolve the correct home for an already authenticated account. */
function rts_child_logged_in_home_url( $user = null ) {
	$user = $user instanceof WP_User ? $user : wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return home_url( '/' );
	}

	// The built-in site owner always keeps the real WordPress dashboard.
	if ( in_array( 'administrator', (array) $user->roles, true ) || is_super_admin( $user->ID ) ) {
		return admin_url();
	}

	$staff_roles = array( 'rts_super_admin', 'rts_administrator', 'rts_content_editor', 'rts_contributor' );
	if ( array_intersect( $staff_roles, (array) $user->roles ) ) {
		return class_exists( 'RTSAP_Frontend_Dashboard' )
			? RTSAP_Frontend_Dashboard::dashboard_url()
			: home_url( '/run-the-seas-admin/' );
	}

	return home_url( '/captains-suite/' );
}

/** Replace BuddyNext's activity redirect when a signed-in user opens login. */
function rts_child_redirect_logged_in_login_request() {
	if ( ! is_user_logged_in() || ! rts_child_is_buddynext_login_request() ) {
		return;
	}

	wp_safe_redirect( rts_child_logged_in_home_url(), 302, 'Run The Seas Account Home' );
	exit;
}
add_action( 'template_redirect', 'rts_child_redirect_logged_in_login_request', -100 );

function rts_child_add_captains_suite_auth_body_class( $classes ) {
	if ( rts_child_is_buddynext_auth_request() ) {
		$classes[] = 'rts-captains-suite-auth';
	}

	return array_values( array_unique( $classes ) );
}
add_filter( 'body_class', 'rts_child_add_captains_suite_auth_body_class', PHP_INT_MAX );

function rts_child_redirect_legacy_login_page_to_buddynext() {
	if ( is_admin() || wp_doing_ajax() || '/login/' !== rts_child_auth_site_relative_path( $_SERVER['REQUEST_URI'] ?? '/' ) ) {
		return;
	}

	if ( is_user_logged_in() ) {
		wp_safe_redirect( rts_child_logged_in_home_url(), 302, 'Run The Seas Account Home' );
		exit;
	}

	$auth_page_id = absint( get_option( 'buddynext_page_auth' ) );
	$auth_slug    = sanitize_title( (string) get_option( 'buddynext_slug_auth', 'login' ) ) ?: 'login';
	$auth_url     = $auth_page_id && get_post_status( $auth_page_id )
		? get_permalink( $auth_page_id )
		: trailingslashit( home_url( '/' . $auth_slug ) );

	if ( '' === $auth_url || rts_child_auth_site_relative_path( $auth_url ) === rts_child_auth_site_relative_path( $_SERVER['REQUEST_URI'] ?? '/' ) ) {
		return;
	}

	$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
	if ( $redirect_to ) {
		$auth_url = add_query_arg( 'redirect_to', $redirect_to, $auth_url );
	}

	wp_safe_redirect( $auth_url, 302, 'Run The Seas BuddyNext Login' );
	exit;
}
add_action( 'init', 'rts_child_redirect_legacy_login_page_to_buddynext', 99 );

function rts_child_enqueue_captains_suite_auth_skin( $force = false ) {
	if ( ! $force && ! rts_child_is_buddynext_auth_request() ) {
		return;
	}
	static $prepared = false;
	if ( $prepared ) {
		return;
	}

	$theme_dir   = get_stylesheet_directory() . '/captains-suite-login/';
	$theme_uri   = trailingslashit( get_stylesheet_directory_uri() ) . 'captains-suite-login/';
	$style_path  = $theme_dir . 'css/buddynext-captains-suite-auth.css';
	$script_path = $theme_dir . 'js/buddynext-captains-suite-auth.js';
	if ( ! is_readable( $style_path ) || ! is_readable( $script_path ) ) {
		return;
	}
	$prepared = true;

	$dependencies = wp_style_is( 'bn-auth', 'registered' ) ? array( 'bn-auth' ) : array();
	wp_enqueue_style( 'rts-buddynext-captains-suite-auth', $theme_uri . 'css/buddynext-captains-suite-auth.css', $dependencies, (string) filemtime( $style_path ) );
	wp_enqueue_script( 'rts-buddynext-captains-suite-auth', $theme_uri . 'js/buddynext-captains-suite-auth.js', array(), (string) filemtime( $script_path ), true );

	$auth_assets      = get_option( 'rts_captains_suite_auth_assets', array() );
	$auth_assets      = is_array( $auth_assets ) ? $auth_assets : array();
	$asset_properties = array(
		'frame_image'          => '--rts-captains-frame-image',
		'divider_image'        => '--rts-captains-divider-image',
		'footer_divider_image' => '--rts-captains-footer-divider-image',
		'button_image'         => '--rts-captains-button-image',
		'reset_button_image'   => '--rts-captains-reset-button-image',
	);
	$inline_css = '';
	foreach ( $asset_properties as $asset_key => $css_property ) {
		if ( empty( $auth_assets[ $asset_key ] ) ) {
			continue;
		}

		$asset_url   = str_replace( array( '\\', "'", '"', "\r", "\n" ), '', rts_child_auth_media_url( $auth_assets[ $asset_key ] ) );
		$inline_css .= $css_property . ":url('" . $asset_url . "');";
		if ( 'divider_image' === $asset_key ) {
			$inline_css .= '--rts-captains-divider-logo-left:none;--rts-captains-divider-logo-right:none;--rts-captains-divider-logo-mark:none;--rts-captains-divider-title-line:none;--rts-captains-divider-title-mark:none;';
		} elseif ( 'frame_image' === $asset_key ) {
			$inline_css .= '--rts-captains-frame-border:transparent;--rts-captains-frame-shadow:none;--rts-captains-frame-inner-border:transparent;--rts-captains-frame-inner-shadow:none;';
		} elseif ( 'footer_divider_image' === $asset_key ) {
			$inline_css .= '--rts-captains-footer-anchor:none;--rts-captains-footer-left:none;--rts-captains-footer-right:none;';
		} elseif ( 'button_image' === $asset_key ) {
			$inline_css .= '--rts-captains-button-label-visibility:hidden;--rts-captains-button-border:transparent;--rts-captains-button-shadow:none;';
		} elseif ( 'reset_button_image' === $asset_key ) {
			$inline_css .= '--rts-captains-reset-button-text-color:transparent;--rts-captains-reset-button-border:transparent;--rts-captains-reset-button-shadow:none;';
		}
	}

	if ( $inline_css ) {
		wp_add_inline_style( 'rts-buddynext-captains-suite-auth', 'body.rts-captains-suite-auth,.rts-captains-suite-login-embed{' . $inline_css . '}' );
	}

	wp_localize_script( 'rts-buddynext-captains-suite-auth', 'rtsCaptainsSuiteAuth', array( 'loginLogoUrl' => rts_child_auth_media_url( $auth_assets['login_logo'] ?? '' ) ) );
}
add_action( 'wp_enqueue_scripts', 'rts_child_enqueue_captains_suite_auth_skin', PHP_INT_MAX );
add_action( 'wp_head', 'rts_child_enqueue_captains_suite_auth_skin', 1 );

/** Return the same logo used by the Captain's Suite login card. */
function rts_child_captains_suite_login_logo_url() {
	$auth_assets = get_option( 'rts_captains_suite_auth_assets', array() );
	$auth_assets = is_array( $auth_assets ) ? $auth_assets : array();
	$logo_url    = rts_child_auth_media_url( $auth_assets['login_logo'] ?? '' );
	if ( ! $logo_url ) {
		$logo_url = rts_child_auth_media_url( get_option( 'buddynext_logo_url', '' ) );
	}
	if ( ! $logo_url ) {
		$custom_logo_id = absint( get_theme_mod( 'custom_logo' ) );
		$logo_url       = $custom_logo_id ? (string) wp_get_attachment_image_url( $custom_logo_id, 'medium' ) : '';
	}
	return esc_url_raw( $logo_url );
}

/** Use first-visit language on BuddyNext's core-secured password form. */
function rts_child_first_passcode_text( $translated, $text, $domain ) {
	if (
		'buddynext' !== $domain
		|| '1' !== sanitize_text_field( wp_unslash( $_GET['rts_first_visit'] ?? '' ) )
	) {
		return $translated;
	}

	$labels = array(
		'Choose a new password'                  => 'Create your passcode',
		'Enter a new password for your account.' => 'Create the passcode you will use to sign in to your Captain’s Suite.',
		'New password'                           => 'Passcode',
		'Choose a strong password'               => 'Choose a secure passcode',
		'Reset password'                         => 'Create my passcode',
		'Please choose a new password.'           => 'Please create your passcode.',
		'Could not reset your password.'          => 'Could not create your passcode.',
		'Password updated. Please sign in.'       => 'Passcode created. Please sign in.',
		'Password-reset links are valid for a limited time. Request a new one to continue.' => 'This passcode-creation link has expired. Request a new link to continue.',
	);
	return $labels[ $text ] ?? $translated;
}
add_filter( 'gettext', 'rts_child_first_passcode_text', 20, 3 );

/**
 * Embed the BuddyNext Captain's Suite login at any page-builder location.
 * Logged-in visitors stay on the host page and see their name below the logo.
 *
 * Usage: [rts_captains_suite_login]
 */
function rts_child_captains_suite_login_shortcode( $atts = array() ) {
	$atts = shortcode_atts(
		array(
			'redirect_to'       => '',
			'logged_in_message' => 'Welcome back, Captain. You are already signed in to your suite.',
		),
		$atts,
		'rts_captains_suite_login'
	);
	rts_child_enqueue_captains_suite_auth_skin( true );

	$body_class_script = '<script>document.body.classList.add("bn-hub-auth","rts-captains-suite-login-embedded-page");</script>';
	$open              = '<div class="rts-captains-suite-login-embed">';
	$close             = '</div>';
	$redirect_to       = trim( (string) $atts['redirect_to'] );
	if ( '' === $redirect_to ) {
		$redirect_to = get_permalink() ?: home_url( '/' );
	} elseif ( 0 === strpos( $redirect_to, '/' ) ) {
		$redirect_to = home_url( $redirect_to );
	}
	$redirect_to = wp_validate_redirect( esc_url_raw( $redirect_to ), home_url( '/' ) );

	if ( is_user_logged_in() ) {
		$user = wp_get_current_user();
		$name = trim( (string) $user->display_name );
		if ( '' === $name ) {
			$name = trim( (string) get_user_meta( $user->ID, 'first_name', true ) . ' ' . (string) get_user_meta( $user->ID, 'last_name', true ) );
		}
		if ( '' === $name ) {
			$name = (string) $user->user_login;
		}
		$logged_in_message = str_replace(
			'{name}',
			$name,
			wp_strip_all_tags( (string) $atts['logged_in_message'] )
		);
		if ( ! function_exists( 'buddynext_get_template' ) ) {
			return $open . '<p>' . esc_html__( 'The login form is temporarily unavailable.', 'buddyx-child' ) . '</p>' . $close;
		}

		wp_enqueue_style( 'bn-shell' );
		if ( function_exists( 'buddynext_service' ) ) {
			$assets = buddynext_service( 'assets' );
			if ( is_object( $assets ) && method_exists( $assets, 'enqueue' ) ) {
				$assets->enqueue( 'auth' );
			}
		}
		if ( function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module( '@buddynext/auth-login' );
		}

		$had_redirect = array_key_exists( 'redirect_to', $_GET );
		$old_redirect = $had_redirect ? $_GET['redirect_to'] : null;
		$_GET['redirect_to'] = $redirect_to;
		ob_start();
		buddynext_get_template( 'auth/login.php', array() );
		$form = (string) ob_get_clean();
		if ( $had_redirect ) {
			$_GET['redirect_to'] = $old_redirect;
		} else {
			unset( $_GET['redirect_to'] );
		}

		$name_html = '<p class="rts-captains-suite-member__name">' . esc_html( $name ) . '</p>';
		$inserted  = 0;
		$form      = preg_replace_callback(
			'~<div class="bn-auth-formlogo">.*?</div>~s',
			function ( $match ) use ( $name_html ) { return $match[0] . $name_html; },
			$form,
			1,
			$inserted
		);
		if ( ! $inserted ) {
			$form = str_replace( '<h1 class="bn-auth-title">', $name_html . '<h1 class="bn-auth-title">', $form );
		}
		$form = preg_replace(
			'~<p class="bn-auth-sub">.*?</p>~s',
			'<p class="bn-auth-sub rts-captains-suite-logged-in-message">' . esc_html( $logged_in_message ) . '</p>',
			$form,
			1
		);

		$logged_in_open = '<div class="rts-captains-suite-login-embed rts-captains-suite-login-embed--logged-in">';
		return $body_class_script . $logged_in_open
			. '<div class="bn-app bn-app--embedded" data-bn-embedded="1">' . $form . '</div>'
			. $close;
	}

	if ( ! shortcode_exists( 'buddynext_auth' ) ) {
		return $open . '<p>' . esc_html__( 'The login form is temporarily unavailable.', 'buddyx-child' ) . '</p>' . $close;
	}

	$had_redirect = array_key_exists( 'redirect_to', $_GET );
	$old_redirect = $had_redirect ? $_GET['redirect_to'] : null;
	$_GET['redirect_to'] = $redirect_to;
	$form = do_shortcode( '[buddynext_auth view="login"]' );
	if ( $had_redirect ) {
		$_GET['redirect_to'] = $old_redirect;
	} else {
		unset( $_GET['redirect_to'] );
	}

	return $body_class_script . $open . $form . $close;
}
add_shortcode( 'rts_captains_suite_login', 'rts_child_captains_suite_login_shortcode' );

/**
 * Let the child theme own BuddyNext's password-reset email presentation.
 * General WordPress and programmatic reset emails remain owned by the RTS
 * plugin; this callback only handles BuddyNext's lost-password REST request.
 */
function rts_child_is_buddynext_password_email_request(): bool {
	if ( function_exists( 'rts_is_buddynext_password_reset_request' ) ) {
		return rts_is_buddynext_password_reset_request();
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	return defined( 'REST_REQUEST' )
		&& REST_REQUEST
		&& false !== strpos( $request_uri, 'buddynext/v1/auth/lost-password' );
}

function rts_child_buddynext_password_reset_email( $defaults, $key, $user_login, $user_data = null ) {
	if ( ! rts_child_is_buddynext_password_email_request() ) {
		return $defaults;
	}

	return function_exists( 'rts_apply_password_reset_email_template' )
		? rts_apply_password_reset_email_template( $defaults, $key, $user_login, $user_data )
		: $defaults;
}
add_filter( 'retrieve_password_notification_email', 'rts_child_buddynext_password_reset_email', 999, 4 );

function rts_child_remove_buddynext_password_email_filter(): void {
	if ( class_exists( '\\BuddyNext\\Auth\\AuthController' ) ) {
		remove_filter(
			'retrieve_password_notification_email',
			array( \BuddyNext\Auth\AuthController::class, 'brand_reset_notification_email' ),
			10
		);
	}
}
add_action( 'init', 'rts_child_remove_buddynext_password_email_filter', 1 );

 
/**
 * Register these only after every plugin (including BuddyNext) has registered
 * its own navigation and override callbacks. This avoids a later BuddyNext
 * callback rebuilding the item list after our custom entries have been added.
 */
function rts_register_buddynext_navigation_customizations(): void {
	add_filter(
		'buddynext_rail_items',
		'rts_add_custom_rail_items_after_feed',
		99
	);

	add_filter(
		'buddynext_rail_items',
		'rts_remove_unwanted_rail_items',
		999
	);

	add_filter(
		'buddynext_nav_items',
		'rts_add_member_media_profile_tabs',
		99,
		2
	);
}
/*
 * Plugins are loaded before plugins_loaded; themes are loaded after it. Support
 * either location so this integration can be tested from a child theme too.
 */
if ( did_action( 'plugins_loaded' ) ) {
	rts_register_buddynext_navigation_customizations();
} else {
	add_action( 'plugins_loaded', 'rts_register_buddynext_navigation_customizations', 99 );
}

function rts_add_custom_rail_items_after_feed( array $items ): array {
	$user_id = get_current_user_id();


	if ( $user_id <= 0 ) {
		return $items;
	}
	
	error_log("items: ". print_r($items, true));

	$profile_url = trailingslashit(
		\BuddyNext\Core\PageRouter::profile_url( $user_id )
	);

	$all_posts_url = home_url( '/activity/explore/?filter=posts' );
	$photos_url    = $profile_url . 'photos/';
	$videos_url    = $profile_url . 'videos/';

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? (string) wp_unslash( $_SERVER['REQUEST_URI'] )
		: '';

	$current_path = rtrim(
		(string) wp_parse_url( $request_uri, PHP_URL_PATH ),
		'/'
	);

	$current_filter = isset( $_GET['filter'] )
		? sanitize_key( wp_unslash( $_GET['filter'] ) )
		: '';

	$photos_path = rtrim(
		(string) wp_parse_url( $photos_url, PHP_URL_PATH ),
		'/'
	);

	$videos_path = rtrim(
		(string) wp_parse_url( $videos_url, PHP_URL_PATH ),
		'/'
	);

	$explore_path = rtrim(
		(string) wp_parse_url(
			home_url( '/activity/explore/' ),
			PHP_URL_PATH
		),
		'/'
	);

	$custom_items = array(
		array(
			'key'    => 'all-posts',
			'label'  => __( 'All Posts', 'run-the-seas' ),
			'url'    => $all_posts_url,
			'icon'   => 'list',
			'show'   => true,
			'active' => (
				$current_path === $explore_path &&
				'posts' === $current_filter
			),
			'order'  => 11,
		),
		array(
			'key'    => 'photos',
			'label'  => __( 'Photos', 'run-the-seas' ),
			'url'    => $photos_url,
			'icon'   => 'image',
			'show'   => true,
			'active' => $current_path === $photos_path,
			'order'  => 12,
		),
		array(
			'key'    => 'videos',
			'label'  => __( 'Videos', 'run-the-seas' ),
			'url'    => $videos_url,

			/*
			 * BuddyNext has play.svg, but no video.svg.
			 */
			'icon'   => 'play',
			'show'   => true,
			'active' => $current_path === $videos_path,
			'order'  => 13,
		),
	);

	/*
	 * Insert the custom links directly after Feed.
	 */
	foreach ( $items as $index => $item ) {
		$key = isset( $item['key'] )
			? sanitize_key( (string) $item['key'] )
			: '';

		if ( 'feed' === $key ) {
			array_splice(
				$items,
				$index + 1,
				0,
				$custom_items
			);

			return $items;
		}
	}

	/*
	 * Fallback if the Feed item cannot be found.
	 */
	return array_merge( $custom_items, $items );
}

function rts_remove_unwanted_rail_items( array $items ): array {
	$remove_keys = array(
		'explore',
		'media',
	);

	foreach ( $items as $index => $item ) {
		$key = isset( $item['key'] )
			? sanitize_key( (string) $item['key'] )
			: '';

		if ( in_array( $key, $remove_keys, true ) ) {
			unset( $items[ $index ] );
		}
	}

	return array_values( $items );
}

function rts_add_member_media_profile_tabs(
	array $items,
	$context
): array {

	if (
		! $context instanceof \BuddyNext\Nav\NavContext ||
		'profile' !== $context->surface
	) {
		return $items;
	}

	/*
	 * Remove BuddyNext's original combined Media profile tab.
	 */
	foreach ( $items as $index => $item ) {
		$item_id = isset( $item['id'] )
			? sanitize_key( (string) $item['id'] )
			: '';

		if ( 'media' === $item_id ) {
			unset( $items[ $index ] );
		}
	}

	$items = array_values( $items );

	$items[] = array(
		'id'        => 'photos',
		'surface'   => 'profile',
		'layer'     => 'primary',
		'label'     => __( 'Photos', 'run-the-seas' ),
		'priority'  => 40,

		'condition' => static function (): bool {
			return shortcode_exists( 'mvs_member_photos' );
		},

		'url'       => static function (
			\BuddyNext\Nav\NavContext $nav_context
		): string {
			return trailingslashit(
				\BuddyNext\Core\PageRouter::profile_url(
					$nav_context->subject_id
				)
			) . 'photos/';
		},

		'render'    => static function (
			\BuddyNext\Nav\NavContext $nav_context
		): void {
			rts_render_member_mvs_gallery(
				$nav_context->subject_id,
				'image'
			);
		},
	);

	$items[] = array(
		'id'        => 'videos',
		'surface'   => 'profile',
		'layer'     => 'primary',
		'label'     => __( 'Videos', 'run-the-seas' ),
		'priority'  => 41,

		'condition' => static function (): bool {
			return shortcode_exists( 'mvs_member_photos' );
		},

		'url'       => static function (
			\BuddyNext\Nav\NavContext $nav_context
		): string {
			return trailingslashit(
				\BuddyNext\Core\PageRouter::profile_url(
					$nav_context->subject_id
				)
			) . 'videos/';
		},

		'render'    => static function (
			\BuddyNext\Nav\NavContext $nav_context
		): void {
			rts_render_member_mvs_gallery(
				$nav_context->subject_id,
				'video'
			);
		},
	);

	return $items;
}

function rts_render_member_mvs_gallery(
	int $member_id,
	string $media_type
): void {

	if ( $member_id <= 0 ) {
		echo '<p>' .
			esc_html__( 'Member not found.', 'run-the-seas' ) .
			'</p>';

		return;
	}

	if ( ! shortcode_exists( 'mvs_member_photos' ) ) {
		echo '<p>' .
			esc_html__(
				'The media gallery is unavailable.',
				'run-the-seas'
			) .
			'</p>';

		return;
	}

	$media_type = 'video' === $media_type
		? 'video'
		: 'image';

	$shortcode = sprintf(
		'[mvs_member_photos user_id="%d" columns="3" per_page="12" type="%s" show_header="false"]',
		absint( $member_id ),
		$media_type
	);

	printf(
		'<div class="rts-member-media-gallery rts-member-media-gallery--%1$s">%2$s</div>',
		esc_attr( $media_type ),
		do_shortcode( $shortcode ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	);
}

add_filter('rts_password_email_logo_url', function () {
    return 'https://runtheseas.com/wp-content/uploads/2026/08/run-the-sea-logo-new.png';
});

/**
 * Store the My Details profile photo through BuddyNext's native image pipeline.
 *
 * Keeping this bridge in the child theme lets the site-specific account page
 * use BuddyNext without changing or removing any BuddyNext plugin code.
 *
 * @param int   $user_id Target member ID.
 * @param array $file    A single entry from $_FILES.
 * @return string|WP_Error Stored avatar URL on success.
 */
function rts_child_save_buddynext_profile_photo( int $user_id, array $file ) {
	if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
		return new WP_Error( 'rts_profile_photo_user', __( 'Member account not found.', 'run-the-seas' ) );
	}

	if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
		return new WP_Error( 'rts_profile_photo_upload', __( 'The profile photo could not be uploaded.', 'run-the-seas' ) );
	}

	if ( (int) ( $file['size'] ?? 0 ) > 2 * MB_IN_BYTES ) {
		return new WP_Error( 'rts_profile_photo_size', __( 'The profile photo must be 2 MB or smaller.', 'run-the-seas' ) );
	}

	$tmp_name = (string) ( $file['tmp_name'] ?? '' );
	$file_name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
	$check = wp_check_filetype_and_ext( $tmp_name, $file_name );
	$allowed_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
	if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed_types, true ) ) {
		return new WP_Error( 'rts_profile_photo_type', __( 'Only JPEG, PNG, GIF or WebP photos are accepted.', 'run-the-seas' ) );
	}

	if ( class_exists( '\\BuddyNext\\Media\\ImageStorageService' ) ) {
		$stored = ( new \BuddyNext\Media\ImageStorageService() )->store( $tmp_name, 'avatar', 'user', $user_id );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		if ( function_exists( 'buddynext_service' ) ) {
			$profiles = buddynext_service( 'profiles' );
			if ( is_object( $profiles ) && method_exists( $profiles, 'update_avatar' ) ) {
				$profiles->update_avatar( $user_id, esc_url_raw( $stored ) );
				return $stored;
			}
		}

		update_user_meta( $user_id, 'bn_avatar', esc_url_raw( $stored ) );
		return $stored;
	}

	// Compatibility fallback keeps BuddyNext's canonical usermeta key even if
	// its image service is unavailable during a future theme/plugin transition.
	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$uploaded = wp_handle_upload( $file, array( 'test_form' => false ) );
	if ( ! empty( $uploaded['error'] ) ) {
		return new WP_Error( 'rts_profile_photo_store', sanitize_text_field( $uploaded['error'] ) );
	}

	$url = esc_url_raw( (string) ( $uploaded['url'] ?? '' ) );
	if ( '' === $url ) {
		return new WP_Error( 'rts_profile_photo_store', __( 'The profile photo could not be stored.', 'run-the-seas' ) );
	}
	update_user_meta( $user_id, 'bn_avatar', $url );

	return $url;
}

/** Remove a custom profile photo and restore BuddyNext's default avatar. */
function rts_child_remove_buddynext_profile_photo( int $user_id ): void {
	if ( $user_id <= 0 ) {
		return;
	}

	if ( function_exists( 'buddynext_service' ) ) {
		$profiles = buddynext_service( 'profiles' );
		if ( is_object( $profiles ) && method_exists( $profiles, 'delete_avatar' ) ) {
			$profiles->delete_avatar( $user_id );
			return;
		}
	}

	delete_user_meta( $user_id, 'bn_avatar' );
}

