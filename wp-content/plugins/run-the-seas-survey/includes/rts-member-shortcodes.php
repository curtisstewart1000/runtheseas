<?php

if (!defined('ABSPATH')) {
    exit;
}

// add_action('plugins_loaded', 'rts_init_registration_system');
// function rts_init_registration_system() {
//     $tracking = function_exists('rts_init') ? rts_init()->tracking : null;
//     $registration = new RTS_Registration($tracking);
//     $registration_page = new RTS_Registration_Page($tracking, $registration);
// }

// Direct shortcode registration (as fallback)
add_shortcode('rts_registration_form', 'rts_render_registration_form_shortcode');

/** Get the total number of survey submissions marked as completed. */
function rts_get_completed_surveys_count()
{
    global $wpdb;

    $tracking_table = $wpdb->prefix . 'rts_survey_tracking';
    return absint($wpdb->get_var(
        "SELECT COUNT(*) FROM {$tracking_table} WHERE completion_status = 'completed'"
    ));
}

/** Render the completed-survey total with its display label. */
function rts_completed_surveys_count_shortcode()
{
    $count = number_format_i18n(rts_get_completed_surveys_count());

    return '<span>' . esc_html($count) . '</span><br>'
        . esc_html__('COMPLETED SURVEYS', 'run-the-seas');
}
add_shortcode('rts_completed_surveys_count', 'rts_completed_surveys_count_shortcode');

/**
 * Return the number of fully qualified Founding Runners.
 *
 * A participant qualifies only after completing the survey, submitting a
 * registration that created a participant record, and verifying their email.
 */
function rts_get_founding_runners_count()
{
    global $wpdb;

    $tracking_table = $wpdb->prefix . 'rts_survey_tracking';
    $participants_table = $wpdb->prefix . 'rts_participants';
    return absint($wpdb->get_var(
        "SELECT COUNT(DISTINCT participant.id)
        FROM {$participants_table} participant
        INNER JOIN {$tracking_table} tracking
            ON tracking.id = participant.survey_tracking_id
        WHERE tracking.completion_status = 'completed'
            AND participant.email_verified = 1"
    ));
}

/** Render the Founding Runner count against all completed surveys. */
function rts_founding_runners_count_shortcode()
{
    $founding_runners = number_format_i18n(rts_get_founding_runners_count());
    $completed_surveys = number_format_i18n(rts_get_completed_surveys_count());

    return esc_html($founding_runners . ' / ' . $completed_surveys);
}
add_shortcode('rts_founding_runners_count', 'rts_founding_runners_count_shortcode');

function rts_render_registration_form_shortcode($atts)
{
    $plugin = RunTheSeasPlugin::get_instance();

    // Use the plugin's registration page instance if available
    if ($plugin && isset($plugin->registration_page)) {
        return $plugin->registration_page->render_registration_form($atts);
    }

    // Fallback: create new instance
    $tracking = $plugin ? $plugin->tracking : null;
    if (!$tracking) {
        $tracking = new RTS_Tracking($wpdb);
    }
    $registration = new RTS_Registration($tracking);
    $registration_page = new RTS_Registration_Page($tracking, $registration);
    return $registration_page->render_registration_form($atts);
}

function rts_add_user_id_column()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'rts_participants';

    // Check if column exists
    $column_exists = $wpdb->get_var(
        "SHOW COLUMNS FROM $table_name LIKE 'user_id'"
    );

    if (!$column_exists) {
        $wpdb->query(
            "ALTER TABLE $table_name ADD COLUMN user_id bigint(20) DEFAULT NULL AFTER id"
        );
        error_log('RTS: Added user_id column to participants table');
    }
}
/**
 * Add participant fields introduced after the original registration schema.
 */
function rts_upgrade_participant_benefit_columns()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'rts_participants';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
        return;
    }
    $columns = array(
        'cabin_credit_amount'         => 'decimal(10,2) DEFAULT 100.00',
        'cabin_credit_issued_at'      => 'datetime DEFAULT NULL',
        'cabin_credit_issued_by'      => 'bigint(20) DEFAULT NULL',
        'captain_suite_activated_at'  => 'datetime DEFAULT NULL',
        'captain_suite_activated_by'  => 'bigint(20) DEFAULT NULL',
        'certificate_number'          => 'varchar(50) DEFAULT NULL',
        'certificate_issued_at'       => 'datetime DEFAULT NULL',
        'certificate_sent_at'         => 'datetime DEFAULT NULL',
        'age_consent_confirmed_at'    => 'datetime DEFAULT NULL',
        'age_consent_ip_address'      => 'varchar(45) DEFAULT NULL',
    );

    foreach ($columns as $column => $definition) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table_name LIKE %s", $column));
        if (!$exists) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN $column $definition");
        }
    }

    $certificate_index = $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Key_name = 'certificate_number'");
    if (!$certificate_index) {
        $wpdb->query("ALTER TABLE $table_name ADD UNIQUE KEY certificate_number (certificate_number)");
    }
}

/** Run legacy participant-column checks only when this schema version changes. */
function rts_maybe_upgrade_member_profile_schema()
{
    $schema_version = '1.0';
    if (get_option('rts_member_profile_schema_version') === $schema_version) {
        return;
    }
    if (!rts_acquire_upgrade_lock('member_profile_schema')) {
        return;
    }

    try {
        rts_add_user_id_column();
        rts_upgrade_participant_benefit_columns();
        update_option('rts_member_profile_schema_version', $schema_version, false);
    } finally {
        rts_release_upgrade_lock('member_profile_schema');
    }
}
add_action('plugins_loaded', 'rts_maybe_upgrade_member_profile_schema', 30);

/** Dedicated, on-site replacement for the removed BuddyPress profile tabs. */
function rts_render_member_profile_shortcode()
{
    if (!is_user_logged_in()) {
        return '<p>' . sprintf(
            wp_kses_post(__('Please <a href="%s">log in</a> to edit your Run The Seas details.', 'run-the-seas')),
            esc_url(rts_get_member_login_url(home_url('/my-details/')))
        ) . '</p>';
    }

    if (!function_exists('rts_init_buddypress_qr')) {
        return '<p>' . esc_html__('Your account page is temporarily unavailable.', 'run-the-seas') . '</p>';
    }
    $account = rts_init_buddypress_qr();
    ob_start();
    $account->render_profile_content();
    return ob_get_clean();
}
add_shortcode('rts_member_profile', 'rts_render_member_profile_shortcode');

function rts_render_member_qr_shortcode()
{
    if (!is_user_logged_in()) {
        return '<p>' . sprintf(
            wp_kses_post(__('Please <a href="%s">log in</a> to view your QR code.', 'run-the-seas')),
            esc_url(rts_get_member_login_url(home_url('/my-qr-code/')))
        ) . '</p>';
    }
    if (!function_exists('rts_init_buddypress_qr')) {
        return '<p>' . esc_html__('Your QR code is temporarily unavailable.', 'run-the-seas') . '</p>';
    }
    $account = rts_init_buddypress_qr();
    ob_start();
    $account->render_qr_content();
    return ob_get_clean();
}
add_shortcode('rts_member_qr', 'rts_render_member_qr_shortcode');

/**
 * Get the current member's Run The Seas participant record.
 *
 * The user_id lookup is the canonical relationship.  The email fallback keeps
 * older registrations (created before the user_id migration) working too.
 */
function rts_get_current_member_participant()
{
    static $participant = null;
    static $looked_up = false;

    if ($looked_up) {
        return $participant;
    }
    $looked_up = true;

    if (!is_user_logged_in()) {
        return null;
    }

    global $wpdb;
    $user = wp_get_current_user();
    $table = $wpdb->prefix . 'rts_participants';

    $participant = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE user_id = %d LIMIT 1",
        $user->ID
    ));

    if (!$participant && !empty($user->user_email)) {
        $participant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE email = %s LIMIT 1",
            $user->user_email
        ));
    }

    return $participant;
}

/** Return the permalink for an RTS member page, falling back to its slug. */
function rts_get_member_page_url($slug)
{
    $slug = sanitize_title($slug);
    $page = $slug ? get_page_by_path($slug) : null;

    return $page ? get_permalink($page) : home_url('/' . $slug . '/');
}

/** The canonical front-end home for a member's Captain's Suite. */
function rts_get_captains_suite_url()
{
    $page = get_page_by_path('captains-suite');
    if (!$page) {
        $page = get_page_by_path('captain-suite');
    }

    return $page ? get_permalink($page) : home_url('/captains-suite/');
}

/**
 * Embed a saved Elementor template in a normal WordPress page.
 *
 * This lets the Captain's Suite page contain only
 * [rts_elementor_template id="1196"], while the visual layout remains fully
 * editable in Elementor's saved-template editor.
 */
function rts_elementor_template_shortcode($atts)
{
    $atts = shortcode_atts(array('id' => 0), $atts, 'rts_elementor_template');
    $template_id = absint($atts['id']);

    if (!$template_id || !did_action('elementor/loaded') || !class_exists('\\Elementor\\Plugin')) {
        return current_user_can('edit_pages')
            ? '<p class="rts-elementor-template-notice">' . esc_html__('The requested Elementor template is unavailable.', 'run-the-seas') . '</p>'
            : '';
    }

    return \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($template_id, true);
}
add_shortcode('rts_elementor_template', 'rts_elementor_template_shortcode');

/** Display the current logged-in member's name in Elementor. */
function rts_member_name_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'format'   => 'full',
        'fallback' => '',
    ), $atts, 'rts_member_name');

    if (!is_user_logged_in()) {
        return esc_html($atts['fallback']);
    }

    $participant = rts_get_current_member_participant();
    $user = wp_get_current_user();
    $first_name = $participant ? $participant->first_name : $user->first_name;
    $last_name = $participant ? $participant->last_name : $user->last_name;
    $format = sanitize_key($atts['format']);

    if ('first' === $format) {
        $name = $first_name;
    } elseif ('last' === $format) {
        $name = $last_name;
    } else {
        $name = trim($first_name . ' ' . $last_name);
    }

    if ('' === $name) {
        $name = $user->display_name ?: $atts['fallback'];
    }

    return esc_html($name);
}
add_shortcode('rts_member_name', 'rts_member_name_shortcode');

/**
 * Display how long ago the logged-in member's latest referral was added.
 * Usage: [rts_last_referral_time]
 */
function rts_last_referral_time_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'empty' => '',
    ), $atts, 'rts_last_referral_time');

    $participant = rts_get_current_member_participant();
    if (!$participant) {
        return esc_html($atts['empty']);
    }

    global $wpdb;
    $referrals_table = $wpdb->prefix . 'rts_referrals';
    $last_referral_date = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(referral_date, created_at)
        FROM {$referrals_table}
        WHERE referrer_id = %d
        ORDER BY COALESCE(referral_date, created_at) DESC, id DESC
        LIMIT 1",
        $participant->id
    ));

    if (empty($last_referral_date)) {
        return esc_html($atts['empty']);
    }

    try {
        $referral_timestamp = (new DateTimeImmutable($last_referral_date, wp_timezone()))->getTimestamp();
    } catch (Exception $exception) {
        return esc_html($atts['empty']);
    }

    $now = current_datetime()->getTimestamp();
    return esc_html(human_time_diff($referral_timestamp, $now) . ' ' . __('ago', 'run-the-seas'));
}
add_shortcode('rts_last_referral_time', 'rts_last_referral_time_shortcode');

/**
 * Display current . Use target="42000" format="progress"
 * for a status such as "27K of 42.2K".
 */
function rts_member_distance_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'source' => 'earned',
        'target' => '',
        'format' => 'value',
        'unit'   => '',
    ), $atts, 'rts_member_distance');

    $participant = rts_get_current_member_participant();
    $field = 'balance' === sanitize_key($atts['source'])
        ? 'captain_miles_balance'
        : 'total_captain_miles_earned';
    $miles = $participant ? max(0, (int) $participant->{$field}) : 0;
    $format = sanitize_key($atts['format']);
    $is_progress = 'progress' === $format;
    $milestone_key = in_array($miles, array(21000, 21100), true) ? '21k' : (in_array($miles, array(42000, 42200), true) ? '42k' : '');
    $value = $is_progress && function_exists('rts_format_trophy_miles')
        ? rts_format_trophy_miles($miles, $milestone_key)
        : (function_exists('rts_format_miles') ? rts_format_miles($miles) : number_format_i18n($miles));
    $unit = trim(wp_strip_all_tags($atts['unit']));

    $output = '<span class="rts-member-distance__value">' . esc_html($value) . '</span>';

    if ($is_progress && is_numeric($atts['target'])) {
        $target = max(0, (int) $atts['target']);
        if (42200 === $target) {
            $target = 42000;
        }
        $target_key = in_array($target, array(21000, 21100), true) ? '21k' : (in_array($target, array(42000, 42200), true) ? '42k' : '');
        $target_value = function_exists('rts_format_trophy_miles')
            ? rts_format_trophy_miles($target, $target_key)
            : number_format_i18n($target / 1000) . 'K';
        $output .= ' <span class="rts-member-distance__of">' . esc_html__('of', 'run-the-seas') . '</span> '
            . esc_html($target_value);
    }

    if ('' !== $unit) {
        $output .= ' ' . esc_html($unit);
    }

    return $output;
}
add_shortcode('rts_member_distance', 'rts_member_distance_shortcode');

/** milestones, ordered by distance for progress displays. */
function rts_get_captains_milestones()
{
    $milestones = array(
        array('key' => 'founder', 'name' => __('Founding Runner Trophy', 'run-the-seas'), 'miles' => 0, 'rank' => 0, 'icon' => '⚓'),
        array('key' => '5k', 'name' => __('5K Trophy', 'run-the-seas'), 'miles' => 5000, 'rank' => 1, 'icon' => '🏆'),
        array('key' => '10k', 'name' => __('10K Trophy', 'run-the-seas'), 'miles' => 10000, 'rank' => 2, 'icon' => '🏆'),
        array('key' => '15k', 'name' => __('15K Trophy', 'run-the-seas'), 'miles' => 15000, 'rank' => 3, 'icon' => '🏆'),
        array('key' => '20k', 'name' => __('20K Trophy', 'run-the-seas'), 'miles' => 20000, 'rank' => 4, 'icon' => '🏆'),
        array('key' => '21k', 'name' => __('21.1K Half Marathon Trophy', 'run-the-seas'), 'miles' => 21000, 'rank' => 5, 'icon' => '🏆'),
        array('key' => '25k', 'name' => __('25K Trophy', 'run-the-seas'), 'miles' => 25000, 'rank' => 6, 'icon' => '🏆'),
        array('key' => '30k', 'name' => __('30K Trophy', 'run-the-seas'), 'miles' => 30000, 'rank' => 7, 'icon' => '🏆'),
        array('key' => '35k', 'name' => __('35K Trophy', 'run-the-seas'), 'miles' => 35000, 'rank' => 8, 'icon' => '🏆'),
        array('key' => '42k', 'name' => __('42.2K Marathon Trophy', 'run-the-seas'), 'miles' => 42000, 'rank' => 9, 'icon' => '🏆'),
    );

    // Keep Captain's Suite displays aligned with the actual trophy-award
    // definitions. This also honours the rts_trophy_definitions filter.
    if (function_exists('rts_init_trophy_system')) {
        $trophy_system = rts_init_trophy_system();
        if ($trophy_system && method_exists($trophy_system, 'get_all_trophy_definitions')) {
            $definitions = $trophy_system->get_all_trophy_definitions();
            if (!empty($definitions)) {
                $earned_milestones = array();
                foreach ($definitions as $key => $definition) {
                    if (empty($definition['miles_required'])) {
                        continue;
                    }
                    $earned_milestones[] = array(
                        'key'   => sanitize_key($key),
                        'name'  => $definition['name'] ?? $key,
                        'miles' => absint($definition['miles_required']),
                        'icon'  => '🏆',
                        'icon_url' => !empty($definition['icon_url']) ? esc_url_raw($definition['icon_url']) : '',
                    );
                }
                usort($earned_milestones, function ($a, $b) {
                    return $a['miles'] <=> $b['miles'];
                });
                foreach ($earned_milestones as $index => &$milestone) {
                    $milestone['rank'] = $index + 1;
                }
                unset($milestone);
                if (!empty($earned_milestones)) {
                    array_unshift($earned_milestones, $milestones[0]);
                    $milestones = $earned_milestones;
                }
            }
        }
    }

    return apply_filters('rts_captains_trophy_milestones', $milestones);
}

/** Return a trophy's uploaded artwork when supplied, otherwise its icon. */
function rts_render_trophy_milestone_icon($milestone, $class = '')
{
    $class = trim('rts-trophy-icon ' . $class);
    if (!empty($milestone['icon_url'])) {
        return '<span class="' . esc_attr($class) . '"><img src="' . esc_url($milestone['icon_url'])
            . '" alt="" loading="lazy"></span>';
    }

    return '<span class="' . esc_attr($class) . '" aria-hidden="true">' . esc_html($milestone['icon'] ?? '🏆') . '</span>';
}

/** Get earned or currently available Miles for the current member. */
function rts_get_current_member_miles($source = 'earned')
{
    $participant = rts_get_current_member_participant();
    if (!$participant) {
        return 0;
    }

    $field = 'balance' === sanitize_key($source)
        ? 'captain_miles_balance'
        : 'total_captain_miles_earned';

    return max(0, (int) $participant->{$field});
}

/** Render a percentage progress bar for the member's marathon journey. */
function rts_member_progress_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'target'       => 42000,
        'source'       => 'earned',
        'show_caption' => 'yes',
    ), $atts, 'rts_member_progress');

    $target = rts_normalize_marathon_target($atts['target']);
    $miles = rts_get_current_member_miles($atts['source']);
    $percent = min(100, round(($miles / $target) * 100, 1));
    $current = rts_format_miles($miles);
    $goal = 42000 === $target
        ? rts_format_trophy_miles($target, '42k')
        : rts_format_miles($target);

    $output = '<div class="rts-member-progress" role="progressbar" aria-valuemin="0" aria-valuemax="'
        . esc_attr($target) . '" aria-valuenow="' . esc_attr($miles) . '" aria-label="'
        . esc_attr__('Marathon progress', 'run-the-seas') . '">'
        . '<div class="rts-member-progress__track"><div class="rts-member-progress__fill" style="width:'
        . esc_attr($percent) . '%"></div></div>';

    if ('yes' === strtolower($atts['show_caption'])) {
        $output .= '<span class="rts-member-progress__caption">' . esc_html(sprintf(
            __('%1$s of %2$s (%3$s%%)', 'run-the-seas'),
            $current,
            $goal,
            $percent
        )) . '</span>';
    }

    return $output . '</div>';
}
add_shortcode('rts_member_progress', 'rts_member_progress_shortcode');

/** Display the Miles remaining until the 42.2K Marathon Trophy. */
function rts_member_distance_to_trophy_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'target'  => 42000,
        'source'  => 'earned',
        'suffix'  => __('to go', 'run-the-seas'),
        'complete' => __('Marathon Trophy earned!', 'run-the-seas'),
    ), $atts, 'rts_member_distance_to_trophy');

    $target = rts_normalize_marathon_target($atts['target']);
    $remaining = max(0, $target - rts_get_current_member_miles($atts['source']));

    if (0 === $remaining) {
        return esc_html($atts['complete']);
    }

    return esc_html(trim(rts_format_miles($remaining) . ' ' . wp_strip_all_tags($atts['suffix'])));
}
add_shortcode('rts_member_distance_to_trophy', 'rts_member_distance_to_trophy_shortcode');

/** Get the first trophy milestone that the current member has not reached. */
function rts_get_current_member_next_trophy($source = 'earned')
{
    $miles = rts_get_current_member_miles($source);
    foreach (rts_get_captains_milestones() as $milestone) {
        if ($miles < $milestone['miles']) {
            return $milestone;
        }
    }

    return null;
}

/** Render a next-trophy card, name, image, or remaining-distance value. */
function rts_member_next_trophy_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'source' => 'earned',
        'field'  => 'card',
    ), $atts, 'rts_member_next_trophy');
    $miles = rts_get_current_member_miles($atts['source']);
    $next = rts_get_current_member_next_trophy($atts['source']);

    if (!$next) {
        return 'name' === sanitize_key($atts['field'])
            ? esc_html__('Marathon Trophy Earned', 'run-the-seas')
            : '<div class="rts-member-trophy"><span class="rts-member-trophy__name">'
                . esc_html__('Marathon Trophy Earned', 'run-the-seas') . '</span></div>';
    }

    $remaining = $next['miles'] - $miles;
    $field = sanitize_key($atts['field']);
    if ('name' === $field) {
        return esc_html($next['name']);
    }
    if ('image' === $field || 'icon' === $field) {
        return rts_render_trophy_milestone_icon($next, 'rts-member-next-trophy__image');
    }
    if ('remaining' === $field) {
        return esc_html(rts_format_miles($remaining));
    }
    if ('remaining_label' === $field) {
        return esc_html(sprintf(
            __('%1$s to go to earn %2$s', 'run-the-seas'),
            rts_format_miles($remaining),
            $next['name']
        ));
    }

    return '<div class="rts-member-trophy"><span class="rts-member-trophy__name">'
        . esc_html($next['name']) . '</span><span class="rts-member-trophy__remaining">'
        . esc_html(sprintf(__('%s to go', 'run-the-seas'), rts_format_miles($remaining)))
        . '</span></div>';
}
add_shortcode('rts_member_next_trophy', 'rts_member_next_trophy_shortcode');

/** Display the distance remaining to the member's immediate next trophy. */
function rts_member_distance_to_next_trophy_shortcode($atts)
{
    $atts = shortcode_atts(array('source' => 'earned'), $atts, 'rts_member_distance_to_next_trophy');
    $next = rts_get_current_member_next_trophy($atts['source']);
    if (!$next) {
        return esc_html__('Marathon Trophy earned!', 'run-the-seas');
    }

    $remaining = max(0, $next['miles'] - rts_get_current_member_miles($atts['source']));
    return esc_html(sprintf(
        __('%1$s to go to earn %2$s', 'run-the-seas'),
        rts_format_miles($remaining),
        $next['name']
    ));
}
add_shortcode('rts_member_distance_to_next_trophy', 'rts_member_distance_to_next_trophy_shortcode');

/** Render the member's highest earned milestone, for the Founding Runner card. */
function rts_member_current_trophy_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'source' => 'earned',
        'field'  => 'card',
    ), $atts, 'rts_member_current_trophy');
    if (!rts_get_current_member_participant()) {
        return '';
    }
    $miles = rts_get_current_member_miles($atts['source']);
    $current = null;
    foreach (rts_get_captains_milestones() as $milestone) {
        if ($miles >= $milestone['miles']) {
            $current = $milestone;
        }
    }
    if (!$current) {
        return '';
    }

    $field = sanitize_key($atts['field']);
    if ('name' === $field) {
        return esc_html($current['name']);
    }
    if ('icon' === $field) {
        return rts_render_trophy_milestone_icon($current, 'rts-member-current-trophy__icon');
    }
    if ('distance' === $field) {
        return esc_html(rts_format_trophy_miles($current['miles'], $current['key'] ?? ''));
    }

    return '<div class="rts-member-current-trophy"><span class="rts-member-current-trophy__label">'
        . esc_html__('Current Trophy', 'run-the-seas') . '</span>'
        . rts_render_trophy_milestone_icon($current, 'rts-member-current-trophy__icon')
        . '<strong>' . esc_html($current['name']) . '</strong></div>';
}
add_shortcode('rts_member_current_trophy', 'rts_member_current_trophy_shortcode');

/**
 * Show the logged-in member's highest earned trophy with a personalised plaque.
 *
 * Use [rts_member_display_trophy] in Elementor or page content. Choose an
 * alternate scene with scene="control-room" or scene="trophy-room". The text
 * is an HTML overlay, so it remains crisp and can be read by screen readers.
 */
function rts_member_display_trophy_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'size'      => '',
        'show_name' => 'yes',
        'scene'     => 'trophy',
    ), $atts, 'rts_member_display_trophy');

    $participant = rts_get_current_member_participant();
    if (!$participant) {
        return '';
    }

    $user = wp_get_current_user();
    $member_name = trim((string) $participant->first_name . ' ' . (string) $participant->last_name);
    if ('' === $member_name) {
        $member_name = $user->display_name;
    }

    global $wpdb;
    $trophies_table = $wpdb->prefix . 'rts_user_trophies';
    $trophy = $wpdb->get_row($wpdb->prepare(
        "SELECT trophy_name, trophy_key
        FROM {$trophies_table}
        WHERE participant_id = %d AND is_displayed = 1
        ORDER BY miles_required DESC, earned_date DESC, id DESC
        LIMIT 1",
        $participant->id
    ));

    // A registered member with no completed-referral trophy receives the
    // Founding Runner trophy. Earned 5K/10K/etc. records always take priority.
    $is_founding_runner = !$trophy;
    $trophy_name = $is_founding_runner
        ? __('Founding Runner Trophy', 'run-the-seas')
        : (string) $trophy->trophy_name;
    // Every level uses the selected supplied artwork. The plaque text
    // distinguishes Founding Runner, 5K, 10K, and the other earned levels.
    $scene = sanitize_key((string) $atts['scene']);
    $scene_images = array(
        'trophy'       => 'rts-founding-runner-trophy2.png',
        'control-room' => 'rts-trophy-control-room.png',
        'trophy-room'  => 'rts-trophy-room.png',
    );
    if (!isset($scene_images[$scene])) {
        $scene = 'trophy';
    }
    $image_url = RTS_PLUGIN_URL . 'assets/images/' . $scene_images[$scene];

    $size = absint($atts['size']);
    if ($size <= 0) {
        $size = 'control-room' === $scene ? 900 : 360;
    }
    $size = min(1000, max(180, $size));
    $show_name = 'yes' === strtolower((string) $atts['show_name']);
    $alt = sprintf(__('%1$s awarded to %2$s', 'run-the-seas'), $trophy_name, $member_name);

    $output = '<figure class="rts-member-display-trophy rts-member-display-trophy--' . esc_attr($scene)
        . '" style="--rts-member-display-trophy-size:'
        . esc_attr($size) . 'px">'
        . '<img class="rts-member-display-trophy__image" src="' . esc_url($image_url)
        . '" alt="' . esc_attr($alt) . '" loading="lazy">'
        . '<figcaption class="rts-member-display-trophy__plaque '.esc_html($scene).'">'
        . '<span class="rts-member-display-trophy__title">' . esc_html($trophy_name) . '</span>';

    if ($show_name && '' !== $member_name) {
        $output .= '<span class="rts-member-display-trophy__member">' . esc_html($member_name) . '</span>';
    }

    return $output . '</figcaption></figure>';
}
add_shortcode('rts_member_display_trophy', 'rts_member_display_trophy_shortcode');

/**
 * Display the current member's most recently earned trophy artwork.
 *
 * Use [rts_member_latest_trophies] in an Elementor Shortcode widget. Trophy
 * artwork is loaded from assets/images/trophies using each trophy's key.
 */
function rts_member_latest_trophies_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'count' => 3,
        'class' => '',
    ), $atts, 'rts_member_latest_trophies');

    $participant = rts_get_current_member_participant();
    if (!$participant) {
        return '';
    }

    $count = min(3, max(1, absint($atts['count'])));
    global $wpdb;
    $trophies_table = $wpdb->prefix . 'rts_user_trophies';
    $trophies = $wpdb->get_results($wpdb->prepare(
        "SELECT trophy_name, trophy_key, trophy_type
        FROM {$trophies_table}
        WHERE participant_id = %d AND is_displayed = 1
        ORDER BY earned_date DESC, id DESC
        LIMIT %d",
        $participant->id,
        $count
    ));

    // Every verified member receives Founding Runner status. Older accounts do
    // not necessarily have a corresponding user-trophies row, so add it as a
    // virtual trophy until the member has three newer trophies to display.
    $has_founding_runner = false;
    foreach ($trophies as $trophy) {
        $trophy_key = sanitize_key($trophy->trophy_key ?: $trophy->trophy_type);
        if (in_array($trophy_key, array('founder', 'founding-runner', 'founding-runner-trophy'), true)) {
            $has_founding_runner = true;
            break;
        }
    }
    if ((int) $participant->email_verified === 1 && !$has_founding_runner && count($trophies) < $count) {
        $trophies[] = (object) array(
            'trophy_name' => __('Founding Runner Trophy', 'run-the-seas'),
            'trophy_key'  => 'founding-runner',
            'trophy_type' => 'founding-runner',
        );
    }

    if (empty($trophies)) {
        return '';
    }

    $image_keys = array(
        'founder'                => 'founding-runner',
        'founding-runner'        => 'founding-runner',
        'founding-runner-trophy' => 'founding-runner',
    );
    $items = '';

    foreach ($trophies as $trophy) {
        $key = sanitize_key($trophy->trophy_key ?: $trophy->trophy_type);
        if (isset($image_keys[$key])) {
            $key = $image_keys[$key];
        }

        $image_path = RTS_PLUGIN_PATH . 'assets/images/trophies/' . $key . '.png';
        if (!$key || !file_exists($image_path)) {
            continue;
        }

        $image_url = RTS_PLUGIN_URL . 'assets/images/trophies/' . $key . '.png';
        $items .= '<div class="rts-member-latest-trophies__item">'
            . '<img class="rts-member-latest-trophies__image" src="' . esc_url($image_url)
            . '" alt="' . esc_attr($trophy->trophy_name) . '" loading="lazy" decoding="async">'
            . '</div>';
    }

    if ('' === $items) {
        return '';
    }

    $classes = 'rts-member-latest-trophies rts-member-latest-trophies--count-' . $count;
    if ('' !== $atts['class']) {
        $classes .= ' ' . sanitize_html_class($atts['class']);
    }

    return '<div class="' . esc_attr($classes) . '">' . $items . '</div>';
}
add_shortcode('rts_member_latest_trophies', 'rts_member_latest_trophies_shortcode');

/** Render a member's Founding Runner number from their participant record. */
function rts_founding_runner_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'digits'    => 7,
        'show_name' => 'yes',
    ), $atts, 'rts_founding_runner');
    $participant = rts_get_current_member_participant();
    if (!$participant) {
        return '';
    }

    $number = str_pad((string) absint($participant->id), max(1, absint($atts['digits'])), '0', STR_PAD_LEFT);
    $output = '<div class="rts-founding-runner"><span class="rts-founding-runner__label">'
        . esc_html__('Founding Runner', 'run-the-seas') . '</span><strong class="rts-founding-runner__number">'
        . esc_html($number) . '</strong>';

    if ('yes' === strtolower($atts['show_name'])) {
        $output .= '<span class="rts-founding-runner__name">' . esc_html(trim($participant->first_name . ' ' . $participant->last_name)) . '</span>';
    }

    return $output . '</div>';
}
add_shortcode('rts_founding_runner', 'rts_founding_runner_shortcode');

/**
 * Clickable account menu for the Suite header. It uses native <details>, so
 * it remains functional without JavaScript and contains a nonce-protected
 * WordPress logout URL.
 */
function rts_member_account_menu_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'role' => __('Captain', 'run-the-seas'),
    ), $atts, 'rts_member_account_menu');
    if (!is_user_logged_in()) {
        return '<a class="rts-member-account-menu__login" href="' . esc_url(rts_get_member_login_url(rts_get_captains_suite_url())) . '">'
            . esc_html__('Log in', 'run-the-seas') . '</a>';
    }

    $user = wp_get_current_user();
    $participant = rts_get_current_member_participant();
    $name = $participant ? trim($participant->first_name . ' ' . $participant->last_name) : $user->display_name;
    $name = $name ?: __('Captain', 'run-the-seas');
    $profile_url = rts_get_member_page_url('profile-settings');

    return '<details class="rts-member-account-menu"><summary>' . get_avatar($user->ID, 56, '', '', array('class' => 'rts-member-account-menu__avatar'))
        . '<span><strong>' . esc_html($name) . '</strong><p>' . esc_html($atts['role']) . '</p></span><b aria-hidden="true">⌄</b></summary>'
        . '<nav aria-label="' . esc_attr__('Member account menu', 'run-the-seas') . '"><a href="' . esc_url(rts_get_captains_suite_url()) . '">'
        . esc_html__("Captain's Suite", 'run-the-seas') . '</a><a href="' . esc_url($profile_url) . '">'
        . esc_html__('Profile & Settings', 'run-the-seas') . '</a><a href="' . esc_url(wp_logout_url(home_url('/'))) . '">'
        . esc_html__('Log out', 'run-the-seas') . '</a></nav></details>';
}
add_shortcode('rts_member_account_menu', 'rts_member_account_menu_shortcode');
