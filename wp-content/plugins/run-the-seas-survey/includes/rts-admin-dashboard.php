<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register the limited administrator role used for Run The Seas operations.
 *
 * WordPress Administrators are granted the same capability explicitly, while
 * the Run The Seas Admin role cannot manage plugins, themes, users, or site
 * settings.
 */
function rts_register_admin_role()
{
    add_role(
        'rts_admin',
        __('Run The Seas Admin', 'run-the-seas'),
        array(
            'read'                  => true,
            RTS_MANAGE_CAPABILITY   => true,
        )
    );

    $rts_admin = get_role('rts_admin');
    if ($rts_admin && !$rts_admin->has_cap(RTS_MANAGE_CAPABILITY)) {
        $rts_admin->add_cap(RTS_MANAGE_CAPABILITY);
    }

    $administrator = get_role('administrator');
    if ($administrator && !$administrator->has_cap(RTS_MANAGE_CAPABILITY)) {
        $administrator->add_cap(RTS_MANAGE_CAPABILITY);
    }
}
add_action('init', 'rts_register_admin_role', 1);

/** Whether a user has a front-end Run The Seas administration role. */
function rts_is_run_the_seas_admin($user = null)
{
    $user = $user instanceof WP_User ? $user : wp_get_current_user();
	$admin_roles = array(
		'rts_admin',
		'rts_super_admin',
		'rts_administrator',
		'rts_content_editor',
		'rts_contributor',
	);

    return $user && $user->exists()
        && (bool) array_intersect($admin_roles, (array) $user->roles);
}

/** Return the dedicated front-end administration URL and optional section. */
function rts_get_admin_dashboard_url($section = 'overview')
{
    $page_id = absint(get_option('rts_admin_dashboard_page_id'));
    $url = $page_id && get_post_status($page_id)
        ? get_permalink($page_id)
        : home_url('/run-the-seas-admin/');

    return add_query_arg('rts_admin_section', sanitize_key($section ?: 'overview'), $url);
}

/** Send the limited Run The Seas administrator role to its front-end dashboard. */
function rts_redirect_rts_admin_after_login($redirect_to, $requested_redirect_to, $user)
{
    if (
        $user instanceof WP_User
        && rts_is_run_the_seas_admin($user)
    ) {
        return rts_get_admin_dashboard_url();
    }

    return $redirect_to;
}
add_filter('login_redirect', 'rts_redirect_rts_admin_after_login', PHP_INT_MAX, 3);

/** Mark the next front-end request after a Run The Seas Admin signs in. */
function rts_mark_rts_admin_login($user_login, $user)
{
    if ($user instanceof WP_User && rts_is_run_the_seas_admin($user)) {
        set_transient('rts_admin_login_redirect_' . (int) $user->ID, 1, 5 * MINUTE_IN_SECONDS);
    }
}
add_action('wp_login', 'rts_mark_rts_admin_login', 20, 2);

/**
 * BuddyNext can bypass WordPress's login_redirect filter and send users to
 * /activity/. Redirect only that first post-login request to the RTS dashboard.
 */
function rts_redirect_rts_admin_activity_after_login()
{
    if (!is_user_logged_in()) {
        return;
    }

    $user = wp_get_current_user();
    if (!$user || !rts_is_run_the_seas_admin($user)) {
        return;
    }

    $transient_key = 'rts_admin_login_redirect_' . (int) $user->ID;
    if (!get_transient($transient_key)) {
        return;
    }

    $request_path = trim((string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    $activity_path = trim((string) wp_parse_url(home_url('/activity/'), PHP_URL_PATH), '/');
    if ($request_path !== $activity_path) {
        return;
    }

    delete_transient($transient_key);
    wp_safe_redirect(rts_get_admin_dashboard_url());
    exit;
}
add_action('template_redirect', 'rts_redirect_rts_admin_activity_after_login', 1);

/** Create the private, role-protected admin page required by BuddyNext. */
function rts_ensure_admin_dashboard_page()
{
    $page_id = absint(get_option('rts_admin_dashboard_page_id'));
    if ($page_id && 'page' === get_post_type($page_id)) {
        return;
    }

    $page = get_page_by_path('run-the-seas-admin');
    if ($page instanceof WP_Post) {
        update_option('rts_admin_dashboard_page_id', (int) $page->ID);
        return;
    }

    $page_id = wp_insert_post(array(
        'post_title'   => __('Run The Seas Admin', 'run-the-seas'),
        'post_name'    => 'run-the-seas-admin',
        'post_content' => '[rts_admin_dashboard]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ));

    if (!is_wp_error($page_id) && $page_id > 0) {
        update_option('rts_admin_dashboard_page_id', (int) $page_id);
    }
}
add_action('init', 'rts_ensure_admin_dashboard_page', 20);

/** Return the post-survey Captain's Update page with its survey context. */
function rts_get_captains_update_page_url($tracking_id = 0, $form_id = 0, $tracking_token = '')
{
    $page_id = absint(get_option('rts_captains_update_page_id'));
    $url = $page_id && get_post_status($page_id)
        ? get_permalink($page_id)
        : home_url('/captains-update/');

    return add_query_arg(array_filter(array(
        'tracking_id' => absint($tracking_id),
        'tracking_token' => sanitize_text_field((string) $tracking_token),
        'form_id'     => absint($form_id),
        'from_survey' => 1,
    )), $url);
}

/** Create the dedicated post-survey video page once for the site. */
function rts_ensure_captains_update_page()
{
    $page_id = absint(get_option('rts_captains_update_page_id'));
    if ($page_id && 'page' === get_post_type($page_id)) {
        return;
    }

    $page = get_page_by_path('captains-update');
    if ($page instanceof WP_Post) {
        update_option('rts_captains_update_page_id', (int) $page->ID);
        return;
    }

    $page_id = wp_insert_post(array(
        'post_title'   => __('Your Captain\'s Suite Is Ready', 'run-the-seas'),
        'post_name'    => 'captains-update',
        'post_content' => '[rts_captains_update]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ));

    if (!is_wp_error($page_id) && $page_id > 0) {
        update_option('rts_captains_update_page_id', (int) $page_id);
    }
}
add_action('init', 'rts_ensure_captains_update_page', 21);

/** Render the post-survey Captain's Update video and reward information. */
function rts_captains_update_shortcode()
{
    $tracking_id = isset($_GET['tracking_id']) ? absint($_GET['tracking_id']) : 0;
    $form_id = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;
    $tracking_token = isset($_GET['tracking_token'])
        ? sanitize_text_field(wp_unslash($_GET['tracking_token']))
        : sanitize_text_field(wp_unslash($_COOKIE['rts_tracking_token'] ?? ''));
    if ($tracking_id && '' === $tracking_token) {
        $tracking_token = rts_get_tracking_access_token_from_cookie($tracking_id);
    }
    $is_completed = false;

    if ($tracking_id && rts_verify_tracking_access($tracking_id, $tracking_token)) {
        global $wpdb;
        $tracking = $wpdb->get_row($wpdb->prepare(
            "SELECT form_id, completion_status FROM {$wpdb->prefix}rts_survey_tracking WHERE id = %d",
            $tracking_id
        ));
        if ($tracking && 'completed' === $tracking->completion_status) {
            $is_completed = true;
            $form_id = $form_id ?: absint($tracking->form_id);
        }
    }

    $cover_url = RTS_PLUGIN_URL . 'assets/images/captains-suite-update-cover.png';
    $design_assets = get_option('rts_survey_design_assets', array());
    $design_assets = is_array($design_assets) ? $design_assets : array();
    $video_url = trim((string) ($design_assets['completion_video'] ?? ''));

    // Keep Media Library URLs working if this WordPress database is migrated
    // between the local site and its production domain.
    if (ctype_digit($video_url)) {
        $video_url = (string) wp_get_attachment_url(absint($video_url));
    } elseif (0 === strpos($video_url, '/')) {
        $video_url = esc_url_raw(home_url($video_url));
    } elseif ('' !== $video_url) {
        $video_url = esc_url_raw($video_url);
        $video_path = (string) wp_parse_url($video_url, PHP_URL_PATH);
        $content_path = trailingslashit((string) wp_parse_url(content_url('/'), PHP_URL_PATH));
        if ($video_path && $content_path && 0 === strpos($video_path, $content_path)) {
            $video_url = esc_url_raw(content_url(ltrim(substr($video_path, strlen($content_path)), '/')));
        }
    }

    if ('' === $video_url) {
        $video_url = RTS_PLUGIN_URL . 'assets/videos/captains-suite-update.mp4';
    }

    $video_filetype = wp_check_filetype((string) wp_parse_url($video_url, PHP_URL_PATH));
    $video_mime = !empty($video_filetype['type']) ? $video_filetype['type'] : 'video/mp4';
    $video_id = 'rts-captains-update-video-' . wp_rand(1000, 9999);
    $cover_id = $video_id . '-cover';
    $registration_url = add_query_arg(array_filter(array(
        'tracking_id' => $tracking_id,
        'tracking_token' => $tracking_token,
        'form_id'     => $form_id,
        'from_survey' => 1,
    )), rts_get_member_page_url('register'));

    $output = '<section class="rts-captains-update">'
        . '<img id="' . esc_attr($cover_id) . '" class="rts-captains-update__cover" src="' . esc_url($cover_url)
        . '" alt="' . esc_attr__('Your Captain’s Suite Is Ready', 'run-the-seas') . '">'
        . '<video id="' . esc_attr($video_id) . '" class="rts-captains-update__video" controls playsinline preload="auto" poster="'
        . esc_url($cover_url) . '" hidden><source src="' . esc_url($video_url)
        . '" type="' . esc_attr($video_mime) . '">' . esc_html__('Your browser does not support this video.', 'run-the-seas') . '</video>'
        . '<div class="rts-captains-update__actions">'
        . '<button class="rts-captains-update__play" type="button" data-rts-video-id="' . esc_attr($video_id)
        . '" data-rts-cover-id="' . esc_attr($cover_id) . '">' . esc_html__('Play Captain’s Update', 'run-the-seas') . '</button>'
        . '<a class="rts-captains-update__fallback" href="' . esc_url($video_url) . '" target="_blank" rel="noopener">'
        . esc_html__('Open video in a new window', 'run-the-seas') . '</a></div>'
        . '<p class="rts-captains-update__status" aria-live="polite"></p>';

    if ($is_completed) {
        $output .= '<div class="rts-captains-update__details">'
            . '<div class="rts-captains-update__celebration" aria-hidden="true">🎉</div>'
            . '<h1>' . esc_html__('Congratulations—you’ve earned your $100 Run The Seas Cruise Credit!', 'run-the-seas') . '</h1>'
            . '<p>' . esc_html__('Thank you for helping us create the Run The Seas experience.', 'run-the-seas') . '</p>'
            . '<p>' . esc_html__('By completing the survey, you have earned:', 'run-the-seas') . '</p>'
            . '<ul><li>' . esc_html__('A $100 Run The Seas Cruise Credit', 'run-the-seas') . '</li>'
            . '<li>' . esc_html__('Free access to your own Captain’s Suite', 'run-the-seas') . '</li>'
            . '<li>' . esc_html__('Entry into the 42.2K Referral Marathon Challenge', 'run-the-seas') . '</li>'
            . '<li>' . esc_html__('The opportunity to earn digital trophies and additional rewards', 'run-the-seas') . '</li></ul>'
            . '<h2>' . esc_html__('What Happens Next?', 'run-the-seas') . '</h2>'
            . '<p>' . esc_html__('Complete the short form below so we can register your $100 Cruise Credit and create your Captain’s Suite.', 'run-the-seas') . '</p>'
            . '<p>' . esc_html__('Once you submit the form:', 'run-the-seas') . '</p>'
            . '<ul><li>' . esc_html__('We’ll send you an email to verify your address.', 'run-the-seas') . '</li>'
            . '<li>' . esc_html__('Click the verification button in the email.', 'run-the-seas') . '</li>'
            . '<li>' . esc_html__('Your $100 Cruise Credit will be sent to you, and your Captain’s Suite will be unlocked.', 'run-the-seas') . '</li></ul>'
            . '<p>' . esc_html__('It only takes a moment—complete the form below to claim your rewards.', 'run-the-seas') . '</p>'
            . '<p class="rts-captains-update__claim-wrap"><a class="rts-captains-update__claim" href="' . esc_url($registration_url) . '">'
            . esc_html__('Claim My $100 Cruise Credit', 'run-the-seas') . '</a></p>'
            . '</div>';
    }

    return $output . '</section>';
}
add_shortcode('rts_captains_update', 'rts_captains_update_shortcode');

/** Show the same Run The Seas administration functions without BuddyPress tabs. */
function rts_admin_dashboard_shortcode($atts)
{
	$user = wp_get_current_user();
	$platform_roles = array('rts_super_admin', 'rts_administrator', 'rts_content_editor', 'rts_contributor');
	if ($user && (bool) array_intersect($platform_roles, (array) $user->roles)) {
		if (class_exists('RTSAP_Frontend_Dashboard')) {
			return RTSAP_Frontend_Dashboard::render_shortcode();
		}

		return '<p class="rts-admin-access-denied">'
			. esc_html__('The Run The Seas Admin Platform plugin must be active to use this dashboard.', 'run-the-seas')
			. '</p>';
	}

    if (!function_exists('rts_init_buddypress_qr')) {
        return '';
    }

    $account = rts_init_buddypress_qr();
    return $account ? $account->render_admin_dashboard_shortcode($atts) : '';
}
add_shortcode('rts_admin_dashboard', 'rts_admin_dashboard_shortcode');

/** Give Run The Seas administrators a direct link in the WordPress toolbar. */
function rts_add_admin_dashboard_toolbar_link($admin_bar)
{
    if (!function_exists('rts_is_wordpress_administrator') || !rts_is_wordpress_administrator()) {
        return;
    }

    $admin_bar->add_node(array(
        'id'    => 'rts-admin-dashboard',
        'title' => esc_html__('Run The Seas Admin', 'run-the-seas'),
        'href'  => rts_get_admin_dashboard_url(),
    ));
}
add_action('admin_bar_menu', 'rts_add_admin_dashboard_toolbar_link', 80);

/** Add the dashboard to BuddyNext's navigation rail for RTS administrators. */
function rts_register_buddynext_admin_navigation()
{
    add_filter('buddynext_rail_items', 'rts_add_buddynext_admin_rail_item');
}
add_action('plugins_loaded', 'rts_register_buddynext_admin_navigation', 20);

function rts_add_buddynext_admin_rail_item($items)
{
    if (!is_user_logged_in() || !current_user_can(RTS_MANAGE_CAPABILITY)) {
        return $items;
    }

    $items[] = array(
        'key'   => 'rts-admin-dashboard',
        'label' => __('Run The Seas Admin', 'run-the-seas'),
        'url'   => rts_get_admin_dashboard_url(),
        'icon'  => 'list',
        'show'  => true,
    );

    return $items;
}

/**
 * Let the limited front-end admin role save only the RTS settings group.
 */
function rts_survey_settings_capability($capability)
{
    return RTS_MANAGE_CAPABILITY;
}
add_filter('option_page_capability_rts_survey_settings_group', 'rts_survey_settings_capability');

// Check and create tables on init if they don't exist
function rts_check_and_create_tables()
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'rts_survey_tracking';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $plugin = RunTheSeasPlugin::get_instance();
        $plugin->create_tables();
        $plugin->create_registration_tables();
    }
}
add_action('init', 'rts_check_and_create_tables');
