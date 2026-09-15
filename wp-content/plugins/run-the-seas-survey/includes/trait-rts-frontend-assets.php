<?php

if (!defined('ABSPATH')) {
    exit;
}

trait RTS_Frontend_Assets
{
    /**
     * Keep the sizeable survey/dashboard bundle off unrelated site pages.
     *
     * Page builders commonly store shortcode widgets in post meta instead of
     * post_content, so inspect the current Elementor document as well as the
     * normal post body. The filter is available for uncommon custom routes.
     */
    private function should_enqueue_rts_frontend_assets($post)
    {
        $page_slugs = array(
            'survey',
            'register',
            'registration',
            'captains-suite',
            'captains-log',
            'trophy-case',
            'trophy-case-m1',
            'trophy-case-2',
            'single-trophy',
            'certificates',
            'journey',
            'leaderboard',
            'referral-race',
            'marathon-challenge',
            'races',
            'my-details',
            'my-qr-code',
        );

        $should_enqueue = is_page($page_slugs);
        if (!$should_enqueue && $post instanceof WP_Post) {
            $content = (string) $post->post_content;
            $should_enqueue = false !== strpos($content, '[rts_')
                || false !== strpos($content, '[fluentform');

            if (!$should_enqueue) {
                $elementor_data = (string) get_post_meta($post->ID, '_elementor_data', true);
                $should_enqueue = false !== strpos($elementor_data, '[rts_')
                    || false !== strpos($elementor_data, 'rts_')
                    || false !== strpos($elementor_data, 'fluentform');
            }
        }

        return (bool) apply_filters('rts_should_enqueue_frontend_assets', $should_enqueue, $post);
    }

    public function enqueue_frontend_assets()
    {
        if (is_admin()) {
            return;
        }

        global $post;
        if (!$this->should_enqueue_rts_frontend_assets($post)) {
            return;
        }

        $content_source = $post instanceof WP_Post ? (string) $post->post_content : '';
        if ($post instanceof WP_Post) {
            $content_source .= ' ' . (string) get_post_meta($post->ID, '_elementor_data', true);
        }
        $is_survey_context = is_page(array('survey', 'register', 'registration'))
            || false !== strpos($content_source, 'rts_luxury_survey')
            || false !== strpos($content_source, 'rts_virtual_marathon')
            || false !== strpos($content_source, 'rts_registration_form')
            || false !== strpos($content_source, 'fluentform');
        $is_trophy_context = is_page(array('trophy-case', 'trophy-case-m1', 'trophy-case-2'))
            || false !== strpos($content_source, 'rts_trophy_case')
            || false !== strpos($content_source, 'rts_marathon_one_trophy_case')
            || false !== strpos($content_source, 'rts_trophy_room');
        $is_dashboard_context = is_page(array('captains-suite', 'leaderboard', 'referral-race', 'marathon-challenge', 'races', 'my-details', 'my-qr-code'))
            || false !== strpos($content_source, 'rts_captain')
            || false !== strpos($content_source, 'rts_member')
            || false !== strpos($content_source, 'rts_leaderboard')
            || false !== strpos($content_source, 'rts_referral')
            || false !== strpos($content_source, 'rts_marathon_challenge')
            || false !== strpos($content_source, 'rts_races');

        wp_enqueue_script('jquery');
        wp_enqueue_script(
            'rts-frontend',
            RTS_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/js/frontend.js'),
            true
        );
        wp_enqueue_style(
            'rts-web-fonts',
            'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300..700;1,300..700&family=Inter+Tight:ital,wght@0,100..900;1,100..900&display=swap',
            array(),
            null
        );
        wp_enqueue_style(
            'rts-captains-suite',
            RTS_PLUGIN_URL . 'assets/css/captains-suite.css',
            array(),
            RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/captains-suite.css')
        );
        if ($is_dashboard_context) {
            wp_enqueue_style(
                'rts-dashboard-widgets',
                RTS_PLUGIN_URL . 'assets/css/dashboard-widgets.css',
                array('rts-captains-suite'),
                RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/dashboard-widgets.css')
            );
        }
        if ($is_trophy_context) {
            wp_enqueue_style(
                'rts-trophy-case',
                RTS_PLUGIN_URL . 'assets/css/trophy-case.css',
                array('rts-captains-suite'),
                RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/trophy-case.css')
            );
        }

        $typography_dependencies = array('rts-web-fonts', 'rts-captains-suite');
        if ($is_survey_context) {
            wp_enqueue_style(
            'rts-luxury-survey',
            RTS_PLUGIN_URL . 'assets/css/luxury-survey.css',
            array('rts-captains-suite'),
            RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/luxury-survey.css')
        );
        wp_enqueue_style(
            'rts-luxury-survey-frames',
            RTS_PLUGIN_URL . 'assets/css/luxury-survey-frames.css',
            array('rts-luxury-survey'),
            RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/luxury-survey-frames.css')
        );
        wp_enqueue_style(
            'rts-luxury-survey-panels',
            RTS_PLUGIN_URL . 'assets/css/luxury-survey-panels.css',
            array('rts-luxury-survey-frames'),
            RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/luxury-survey-panels.css')
        );
        wp_enqueue_style(
            'rts-luxury-survey-multi-frames',
            RTS_PLUGIN_URL . 'assets/css/luxury-survey-multi-frames.css',
            array('rts-luxury-survey-panels'),
            RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/luxury-survey-multi-frames.css')
        );
        $number_rules_style_path = RTS_PLUGIN_PATH . 'assets/css/luxury-survey-number-rules.css';
        $elementor_background_dependencies = array('rts-luxury-survey-multi-frames');

        if (is_readable($number_rules_style_path)) {
            wp_enqueue_style(
                'rts-luxury-survey-number-rules',
                RTS_PLUGIN_URL . 'assets/css/luxury-survey-number-rules.css',
                array('rts-luxury-survey-multi-frames'),
                RTS_VERSION . '.' . filemtime($number_rules_style_path)
            );
            $elementor_background_dependencies[] = 'rts-luxury-survey-number-rules';
        }

        wp_enqueue_style(
            'rts-luxury-survey-elementor-background',
            RTS_PLUGIN_URL . 'assets/css/luxury-survey-elementor-background.css',
            $elementor_background_dependencies,
            RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/luxury-survey-elementor-background.css')
        );
        wp_enqueue_style(
            'rts-luxury-survey-captains-layout',
            RTS_PLUGIN_URL . 'assets/css/luxury-survey-captains-layout-v28.css',
            array('rts-luxury-survey-elementor-background'),
            RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/luxury-survey-captains-layout-v28.css') . '.captains-layout-v28'
        );
        wp_enqueue_script(
            'rts-luxury-survey',
            RTS_PLUGIN_URL . 'assets/js/luxury-survey-v24.js',
            array('jquery', 'rts-frontend'),
            RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/js/luxury-survey-v24.js') . '.survey-v24',
            true
        );
        wp_enqueue_script(
            'rts-luxury-survey-badges',
            RTS_PLUGIN_URL . 'assets/js/luxury-survey-badges.js',
            array('rts-luxury-survey'),
            RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/js/luxury-survey-badges.js'),
            true
        );
        wp_enqueue_script(
            'rts-luxury-survey-number-rules',
            RTS_PLUGIN_URL . 'assets/js/luxury-survey-number-rules.js',
            array('rts-luxury-survey-badges'),
            RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/js/luxury-survey-number-rules.js'),
            true
        );
            $typography_dependencies[] = 'rts-luxury-survey-captains-layout';
        }
        if ($post && (is_page('captains-log') || has_shortcode($post->post_content, 'rts_captains_log'))) {
            wp_enqueue_style(
                'rts-captains-log',
                RTS_PLUGIN_URL . 'assets/css/captains-log.css',
                array('rts-captains-suite'),
                RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/captains-log.css')
            );
            $typography_dependencies[] = 'rts-captains-log';
        }
        if ($post && (is_page('single-trophy') || has_shortcode($post->post_content, 'rts_single_trophy'))) {
            wp_enqueue_script(
                'rts-dom-to-image',
                RTS_PLUGIN_URL . 'assets/js/vendor/dom-to-image.min.js',
                array(),
                '2.6.0',
                true
            );
            wp_enqueue_style(
                'rts-single-trophy',
                RTS_PLUGIN_URL . 'assets/css/single-trophy.css',
                array('rts-captains-suite'),
                RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/single-trophy.css')
            );
            wp_enqueue_script(
                'rts-single-trophy',
                RTS_PLUGIN_URL . 'assets/js/single-trophy-three-surface.js',
                array('rts-dom-to-image'),
                RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/js/single-trophy-three-surface.js'),
                true
            );
            $typography_dependencies[] = 'rts-single-trophy';
        }
        if ($post && (is_page('certificates') || has_shortcode($post->post_content, 'rts_certificate_page') || has_shortcode($post->post_content, 'rts_certificate'))) {
            wp_enqueue_style(
                'rts-certificate-page',
                RTS_PLUGIN_URL . 'assets/css/certificate-page.css',
                array('rts-captains-suite'),
                RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/certificate-page.css')
            );
            $typography_dependencies[] = 'rts-certificate-page';
        }
        wp_enqueue_style(
            'rts-typography',
            RTS_PLUGIN_URL . 'assets/css/typography.css',
            $typography_dependencies,
            RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/css/typography.css')
        );
        wp_localize_script('rts-frontend', 'rts_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rts_nonce'),
            'user_id' => get_current_user_id(),
            'registration_url' => home_url('/register/'),
            'dom_to_image_url' => RTS_PLUGIN_URL . 'assets/js/vendor/dom-to-image.min.js',
            'dom_to_image_fallback_url' => plugins_url('elementor/assets/lib/dom-to-image/js/dom-to-image.min.js')
        ));
    }

    // AJAX Methods
}
