<?php
/**
 * Class RTS_Analytics
 * Complete Business Intelligence Dashboard
 */
class RTS_Analytics {
    
    private $db;
    private $tracking;
    
    public function __construct($tracking = null) {
        global $wpdb;
        $this->db = $wpdb;
        $this->tracking = $tracking;
        
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        add_action('wp_ajax_rts_get_analytics_data', array($this, 'ajax_get_analytics_data'));
        add_action('wp_ajax_rts_export_analytics', array($this, 'ajax_export_analytics'));
        add_action('wp_ajax_rts_archive_analytics', array($this, 'ajax_archive_analytics'));
        add_action('wp_ajax_rts_reset_analytics', array($this, 'ajax_reset_analytics'));

        add_action('wp_ajax_rts_get_online_users', array($this, 'ajax_get_online_users'));

        add_action('wp_ajax_rts_get_trophy_visits', array($this, 'ajax_get_trophy_visits'));

        add_action('wp_ajax_rts_get_logged_in_users', array($this, 'ajax_get_logged_in_users'));
        
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'rts-survey-management',
            'Business Intelligence Dashboard',
            '📊 BI Dashboard',
            RTS_MANAGE_CAPABILITY,
            'rts-bi-dashboard',
            array($this, 'render_dashboard_page')
        );
    }
    
    public function enqueue_admin_assets($hook) {
        // if ($hook !== 'run-the-seas_page_rts-bi-dashboard' && $hook !== 'admin_page_rts-bi-dashboard') {
        //     return;
        // }
        
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', true);
        $bi_version = RTS_VERSION . '.' . filemtime(RTS_PLUGIN_PATH . 'assets/js/bi-dashboard.js');
        wp_enqueue_script('rts-bi-dashboard', RTS_PLUGIN_URL . 'assets/js/bi-dashboard.js', array('jquery', 'chart-js'), $bi_version, true);
        
        wp_localize_script('rts-bi-dashboard', 'rts_bi', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rts_admin_nonce'),
            'plugin_url' => RTS_PLUGIN_URL
        ));
        
        wp_localize_script('rts-bi-dashboard', 'rts_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rts_admin_nonce')
        ));
    }
    
    public function render_dashboard_page() {
        $forms = $this->get_fluent_forms();
        $selected_form = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
        ?>
        <div class="wrap">
            <h1>📊 Business Intelligence Dashboard</h1>
            <p style="color: #666; margin-bottom: 20px;">Measure demand, identify customer preferences, evaluate pricing, and support investor presentations.</p>
            
            <!-- Complete Filter Bar -->
            <div class="rts-bi-filter-bar" style="
                background: #fff;
                padding: 20px;
                margin: 20px 0;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 15px;
                align-items: end;
            ">
                <div>
                    <label style="font-weight: 600; display: block; margin-bottom: 5px; font-size: 13px;">Select Survey:</label>
                    <select id="rts-bi-form-select" style="width: 100%; padding: 9px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">Select a survey...</option>
                        <?php foreach ($forms as $form): ?>
                            <option value="<?php echo $form->id; ?>" <?php selected($selected_form, $form->id); ?>>
                                <?php echo esc_html($form->title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label style="font-weight: 600; display: block; margin-bottom: 5px; font-size: 13px;">Date From:</label>
                    <input type="date" id="rts-bi-date-from" style="width: 100%; padding: 9px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                
                <div>
                    <label style="font-weight: 600; display: block; margin-bottom: 5px; font-size: 13px;">Date To:</label>
                    <input type="date" id="rts-bi-date-to" style="width: 100%; padding: 9px; border: 1px solid #ddd; border-radius: 4px;">
                </div>

                <div>
                    <label style="font-weight: 600; display: block; margin-bottom: 5px; font-size: 13px;">Quick range:</label>
                    <div style="display:flex;gap:4px;flex-wrap:wrap;">
                        <button type="button" class="button rts-bi-range" data-days="1">Day</button>
                        <button type="button" class="button rts-bi-range" data-days="7">Week</button>
                        <button type="button" class="button rts-bi-range" data-days="30">Month</button>
                        <button type="button" class="button rts-bi-range" data-days="90">Quarter</button>
                        <button type="button" class="button rts-bi-range" data-days="365">Year</button>
                    </div>
                </div>
                
                <div>
                    <label style="font-weight: 600; display: block; margin-bottom: 5px; font-size: 13px;">Verification:</label>
                    <select id="rts-bi-verification-filter" style="width: 100%; padding: 9px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">All Participants</option>
                        <option value="verified">✅ Verified Only</option>
                        <option value="unverified">⏳ Unverified Only</option>
                    </select>
                </div>
                
                <div>
                    <label style="font-weight: 600; display: block; margin-bottom: 5px; font-size: 13px;">Referral:</label>
                    <select id="rts-bi-referral-filter" style="width: 100%; padding: 9px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">All Referrals</option>
                        <option value="with">🔗 With Referral</option>
                        <option value="without">❌ Without Referral</option>
                    </select>
                </div>

                <div>
                    <label style="font-weight: 600; display: block; margin-bottom: 5px; font-size: 13px;">Question search:</label>
                    <input type="search" id="rts-bi-question-search" placeholder="Question or answer keyword" style="width: 100%; padding: 9px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                
                <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                    <button id="rts-bi-apply-filters" class="button button-primary">Apply Filters</button>
                    <button id="rts-bi-export-excel" class="button button-secondary">Export Excel</button>
                    <button id="rts-bi-export-report" class="button button-secondary">📥 Export</button>
                    <button id="rts-bi-archive-data" class="button button-secondary">📦 Archive</button>
                    <button id="rts-bi-refresh" class="button">🔄 Refresh</button>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div id="rts-bi-dashboard-content" style="display: <?php echo $selected_form ? 'block' : 'none'; ?>;">
                
                <!-- Stats Grid -->
                <div style="
                    background: #fff;
                    padding: 20px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                ">
                    <h3 style="margin-top: 0; color: #1a7efb;">📋 Dashboard Statistics</h3>
                    <div id="rts-bi-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-top: 15px;">
                        <!-- Loaded by AJAX -->
                    </div>
                </div>
                
                <!-- Charts Row -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h4 style="margin-top: 0;">📈 Survey Trends</h4>
                        <canvas id="rts-bi-trend-chart" height="200"></canvas>
                    </div>
                    <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h4 style="margin-top: 0;">🌍 Geographic Distribution</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <canvas id="rts-bi-geo-chart" height="180"></canvas>
                            <div id="rts-bi-geo-list" style="max-height: 200px; overflow-y: auto; font-size: 12px;">
                                <!-- Loaded by AJAX -->
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Question-Level Analytics -->
                <div style="
                    background: #fff;
                    padding: 20px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                ">
                    <h3 style="margin-top: 0;">📋 Question-Level Analytics</h3>
                    <div id="rts-bi-questions-container">
                        <!-- Loaded by AJAX -->
                    </div>
                </div>
                
                <!-- Referral Analytics -->
                <div style="
                    background: #fff;
                    padding: 20px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                ">
                    <h3 style="margin-top: 0;">🔗 Referral Analytics</h3>
                    <div id="rts-bi-referral-container">
                        <!-- Loaded by AJAX -->
                    </div>
                </div>
                
                <!-- Investor Insights -->
                <div style="
                    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
                    padding: 20px;
                    border-radius: 8px;
                    color: #fff;
                    margin-bottom: 20px;
                ">
                    <h3 style="margin-top: 0; color: #fff;">🎯 Investor Insights</h3>
                    <div id="rts-bi-investor-insights" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
                        <!-- Loaded by AJAX -->
                    </div>
                </div>
                
                <div id="rts-bi-message" style="margin-top: 10px; display: none;"></div>
            </div>
            
            <!-- No Form Selected -->
            <div id="rts-bi-no-form" style="
                text-align: center;
                padding: 60px 20px;
                background: #fff;
                border-radius: 8px;
                margin: 20px 0;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                <?php echo $selected_form ? 'display: none;' : ''; ?>
            ">
                <div style="font-size: 48px;">📊</div>
                <h2>Select a Survey to View Business Intelligence</h2>
                <p style="color: #666;">Choose a survey from the dropdown above to see demand metrics, customer preferences, and investor insights.</p>
            </div>
        </div>
        
        <style>
            .rts-bi-stat {
                background: #f8f9fa;
                padding: 10px 12px;
                border-radius: 6px;
                text-align: center;
                border-left: 3px solid #1a7efb;
            }
            .rts-bi-stat .stat-number {
                font-size: 20px;
                font-weight: bold;
                color: #1a7efb;
            }
            .rts-bi-stat .stat-label {
                font-size: 10px;
                color: #666;
                margin-top: 2px;
            }
            .rts-bi-stat.success { border-left-color: #28a745; }
            .rts-bi-stat.success .stat-number { color: #28a745; }
            .rts-bi-stat.warning { border-left-color: #ffc107; }
            .rts-bi-stat.warning .stat-number { color: #856404; }
            .rts-bi-stat.danger { border-left-color: #dc3545; }
            .rts-bi-stat.danger .stat-number { color: #dc3545; }
            .rts-bi-stat.info { border-left-color: #17a2b8; }
            .rts-bi-stat.info .stat-number { color: #17a2b8; }
            
            .rts-question-block {
                background: #f8f9fa;
                padding: 12px 15px;
                margin: 8px 0;
                border-radius: 6px;
                border-left: 3px solid #1a7efb;
            }
            .rts-question-block .question-title {
                font-weight: 600;
                font-size: 14px;
                margin-bottom: 8px;
            }
            .rts-answer-row {
                display: flex;
                align-items: center;
                gap: 8px;
                margin: 3px 0;
            }
            .rts-answer-row .bar {
                flex: 1;
                height: 16px;
                background: #e9ecef;
                border-radius: 8px;
                overflow: hidden;
            }
            .rts-answer-row .bar-fill {
                height: 100%;
                background: linear-gradient(90deg, #1a7efb, #6c5ce7);
                border-radius: 8px;
                transition: width 0.6s ease;
            }
            .rts-answer-row .percentage {
                min-width: 40px;
                font-weight: 600;
                font-size: 12px;
                color: #333;
            }
            
            .rts-bi-insight-card {
                background: rgba(255,255,255,0.1);
                padding: 12px 15px;
                border-radius: 6px;
                border: 1px solid rgba(255,255,255,0.1);
            }
            .rts-bi-insight-card .insight-value {
                font-size: 24px;
                font-weight: bold;
                color: #fff;
            }
            .rts-bi-insight-card .insight-label {
                font-size: 12px;
                color: rgba(255,255,255,0.7);
                margin-top: 3px;
            }
            
            @media (max-width: 768px) {
                .rts-bi-filter-bar {
                    grid-template-columns: 1fr !important;
                }
                #rts-bi-stats-grid {
                    grid-template-columns: 1fr 1fr !important;
                }
            }
        </style>
        <?php
    }
    
    /**
     * AJAX: Get BI Dashboard Data
     */
    public function ajax_get_analytics_data() {
        error_log('RTS Analytics: AJAX request received');
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rts_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $form_id = intval($_POST['form_id']);
        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';
        $verification = isset($_POST['verification']) ? sanitize_text_field($_POST['verification']) : '';
        $referral = isset($_POST['referral']) ? sanitize_text_field($_POST['referral']) : '';
        
        error_log("RTS Analytics: Form ID: $form_id, Date: $date_from to $date_to, Verification: $verification, Referral: $referral");
        
        if (!$this->is_active_survey($form_id)) {
            wp_send_json_error('Select an active survey.');
            return;
        }
        
        $data = array(
            'stats' => $this->get_dashboard_stats($form_id, $date_from, $date_to, $verification, $referral),
            'trends' => $this->get_trend_data($form_id, $date_from, $date_to, $verification, $referral),
            'geo' => $this->get_geo_distribution($form_id, $date_from, $date_to, $verification, $referral),
            'questions' => $this->get_question_analytics($form_id, $date_from, $date_to, $verification, $referral),
            'referrals' => $this->get_referral_analytics($form_id, $date_from, $date_to, $referral),
            'investor' => $this->get_investor_insights($form_id, $date_from, $date_to, $verification, $referral)
        );
        
        // Debug: Log the data structure
        error_log('RTS Analytics: Questions count: ' . count($data['questions']));
        error_log('RTS Analytics: Referrals sources count: ' . count($data['referrals']['sources']));
        error_log('RTS Analytics: Investor keys: ' . implode(', ', array_keys($data['investor'])));
        
        // Check if questions have answers
        if (!empty($data['questions'])) {
        }
        
        wp_send_json_success($data);
    }
    
    /**
     * Get Dashboard Statistics (16.3)
     */
    private function get_tracking_filters($form_id, $date_from = '', $date_to = '', $verification = '', $referral = '', $alias = 't') {
        global $wpdb;

        $where = array("$alias.form_id = %d");
        $params = array($form_id);
        if ($date_from !== '') {
            $where[] = "$alias.started_at >= %s";
            $params[] = $date_from . ' 00:00:00';
        }
        if ($date_to !== '') {
            $where[] = "$alias.started_at <= %s";
            $params[] = $date_to . ' 23:59:59';
        }

        $participants_table = $wpdb->prefix . 'rts_participants';
        if ($verification === 'verified') {
            $where[] = "EXISTS (SELECT 1 FROM $participants_table fp WHERE fp.survey_tracking_id = $alias.id AND fp.email_verified = 1)";
        } elseif ($verification === 'unverified') {
            $where[] = "EXISTS (SELECT 1 FROM $participants_table fp WHERE fp.survey_tracking_id = $alias.id AND fp.email_verified = 0)";
        }
        if ($referral === 'with') {
            $where[] = "($alias.referral_code <> '' AND $alias.referral_code IS NOT NULL)";
        } elseif ($referral === 'without') {
            $where[] = "($alias.referral_code = '' OR $alias.referral_code IS NULL)";
        }

        return array(implode(' AND ', $where), $params);
    }

    private function get_dashboard_stats($form_id, $date_from = '', $date_to = '', $verification = '', $referral = '') {
        global $wpdb;
        $tracking_table = $wpdb->prefix . 'rts_survey_tracking';
        $participants_table = $wpdb->prefix . 'rts_participants';
        list($where, $params) = $this->get_tracking_filters($form_id, $date_from, $date_to, $verification, $referral, 't');
        
        $sql = "SELECT 
            COUNT(*) as total_responses,
            SUM(CASE WHEN t.completion_status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN t.completion_status = 'in_progress' THEN 1 ELSE 0 END) as incomplete,
            SUM(CASE WHEN t.completion_status = 'abandoned' THEN 1 ELSE 0 END) as abandoned,
            AVG(t.time_spent_seconds) as avg_time,
            COUNT(DISTINCT NULLIF(t.email, '')) as unique_respondents,
            SUM(CASE WHEN t.is_duplicate = 1 THEN 1 ELSE 0 END) as duplicates,
            SUM(CASE WHEN t.referral_code <> '' AND t.referral_code IS NOT NULL THEN 1 ELSE 0 END) as referral_participation,
            SUM(CASE WHEN p.id IS NULL THEN 1 ELSE 0 END) as anonymous_participants,
            COUNT(DISTINCT p.id) as registered_participants,
            COUNT(DISTINCT CASE WHEN p.email_verified = 1 THEN p.id END) as verified_emails,
            COUNT(DISTINCT CASE WHEN p.email_verified = 0 THEN p.id END) as unverified_emails,
            COUNT(DISTINCT CASE WHEN p.cabin_credit_status = 'approved' THEN p.id END) as cabin_credits_issued,
            COUNT(DISTINCT CASE WHEN p.certificate_issued_at IS NOT NULL THEN p.id END) as certificates_issued,
            COUNT(DISTINCT CASE WHEN p.captain_suite_status = 'active' THEN p.id END) as captain_suites_active,
            COUNT(DISTINCT CASE WHEN p.captain_referral_participation = 'registered' THEN p.id END) as race_participation,
            COUNT(DISTINCT CASE WHEN EXISTS (
                SELECT 1 FROM {$wpdb->prefix}rts_survey_answers runner_answer
                WHERE runner_answer.tracking_id = t.id
                    AND (runner_answer.question_id LIKE '%runner%' OR runner_answer.question_label LIKE '%runner%')
                    AND LOWER(CONCAT(runner_answer.answer_value, ' ', runner_answer.answer_label)) REGEXP '(^|[^a-z])(yes|runner)([^a-z]|$)'
                    AND LOWER(CONCAT(runner_answer.answer_value, ' ', runner_answer.answer_label)) NOT LIKE '%non-runner%'
            ) THEN t.id END) as runners,
            COUNT(DISTINCT CASE WHEN EXISTS (
                SELECT 1 FROM {$wpdb->prefix}rts_survey_answers non_runner_answer
                WHERE non_runner_answer.tracking_id = t.id
                    AND (non_runner_answer.question_id LIKE '%runner%' OR non_runner_answer.question_label LIKE '%runner%')
                    AND LOWER(CONCAT(non_runner_answer.answer_value, ' ', non_runner_answer.answer_label)) REGEXP '(^|[^a-z])(no|non.?runner)([^a-z]|$)'
            ) THEN t.id END) as non_runners,
            COALESCE(SUM(p.captain_miles_balance), 0) as captain_miles_balance,
            COALESCE(SUM(p.total_captain_miles_earned), 0) as captain_miles_earned
        FROM $tracking_table t
        LEFT JOIN $participants_table p ON p.survey_tracking_id = t.id
        WHERE $where";
        
        $sql = $wpdb->prepare($sql, $params);
        $stats = $wpdb->get_row($sql);
        
        $total = $stats ? intval($stats->total_responses) : 0;
        $completed = $stats ? intval($stats->completed) : 0;
        $abandoned = $stats ? intval($stats->abandoned) : 0;
        
        return array(
            'total_responses' => $total,
            'completed' => $completed,
            'incomplete' => $stats ? intval($stats->incomplete) : 0,
            'abandoned' => $abandoned,
            'completion_percentage' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
            'abandonment_rate' => $total > 0 ? round(($abandoned / $total) * 100, 1) : 0,
            'avg_completion_time' => $stats ? round(floatval($stats->avg_time), 1) : 0,
            'anonymous_participants' => $stats ? intval($stats->anonymous_participants) : 0,
            'registered_participants' => $stats ? intval($stats->registered_participants) : 0,
            'verified_emails' => $stats ? intval($stats->verified_emails) : 0,
            'unverified_emails' => $stats ? intval($stats->unverified_emails) : 0,
            'invalid_emails' => 0,
            'hard_bounces' => 0,
            'soft_bounces' => 0,
            'duplicate_responses' => $stats ? intval($stats->duplicates) : 0,
            'referral_participation' => $stats ? intval($stats->referral_participation) : 0,
            'cabin_credits_issued' => $stats ? intval($stats->cabin_credits_issued) : 0,
            'certificates_issued' => $stats ? intval($stats->certificates_issued) : 0,
            'captain_suites_active' => $stats ? intval($stats->captain_suites_active) : 0,
            'captain_race_participation' => $stats ? intval($stats->race_participation) : 0,
            'runners' => $stats ? intval($stats->runners) : 0,
            'non_runners' => $stats ? intval($stats->non_runners) : 0,
            'captain_miles_balance' => $stats ? intval($stats->captain_miles_balance) : 0,
            'captain_miles_earned' => $stats ? intval($stats->captain_miles_earned) : 0,
            'unique_respondents' => $stats ? intval($stats->unique_respondents) : 0
        );
    }
    
    /**
     * Get Trend Data
     */
    private function get_trend_data($form_id, $date_from = '', $date_to = '', $verification = '', $referral = '') {
        global $wpdb;
        $tracking_table = $wpdb->prefix . 'rts_survey_tracking';
        list($where, $params) = $this->get_tracking_filters($form_id, $date_from, $date_to, $verification, $referral, 't');
        if ($date_from === '' && $date_to === '') {
            $where .= ' AND t.started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
        }
        
        $trend_data = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    DATE(t.started_at) as date,
                    COUNT(*) as started,
                    SUM(CASE WHEN t.completion_status = 'completed' THEN 1 ELSE 0 END) as completed
                FROM $tracking_table t
                WHERE $where
                GROUP BY DATE(t.started_at)
                ORDER BY date ASC",
                $params
            )
        );
        
        $labels = array();
        $started = array();
        $completed = array();
        
        foreach ($trend_data as $row) {
            $labels[] = date('M j', strtotime($row->date));
            $started[] = intval($row->started);
            $completed[] = intval($row->completed);
        }
        
        return array(
            'labels' => $labels,
            'started' => $started,
            'completed' => $completed
        );
    }
    
    /**
     * Get Geographic Distribution
     */
    private function get_geo_distribution($form_id, $date_from = '', $date_to = '', $verification = '', $referral = '') {
        global $wpdb;
        $tracking_table = $wpdb->prefix . 'rts_survey_tracking';
        list($where, $params) = $this->get_tracking_filters($form_id, $date_from, $date_to, $verification, $referral, 't');
        
        $geo_data = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    t.country, 
                    COUNT(*) as count,
                    SUM(CASE WHEN t.completion_status = 'completed' THEN 1 ELSE 0 END) as completed
                FROM $tracking_table t
                WHERE $where AND t.country != '' AND t.country IS NOT NULL
                GROUP BY t.country 
                ORDER BY count DESC 
                LIMIT 10",
                $params
            )
        );
        
        $labels = array();
        $counts = array();
        $completions = array();
        
        foreach ($geo_data as $row) {
            $labels[] = $row->country;
            $counts[] = intval($row->count);
            $completions[] = intval($row->completed);
        }
        
        return array(
            'labels' => $labels,
            'counts' => $counts,
            'completions' => $completions
        );
    }
    
    /**
     * Get Question-Level Analytics (16.4)
     */
    private function get_question_analytics($form_id, $date_from = '', $date_to = '', $verification = '', $referral = '') {
        global $wpdb;
        $answers_table = $wpdb->prefix . 'rts_survey_answers';
        $tracking_table = $wpdb->prefix . 'rts_survey_tracking';
        list($where, $params) = $this->get_tracking_filters($form_id, $date_from, $date_to, $verification, $referral, 't');
        $total_respondents = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $tracking_table t WHERE $where", $params)
        );
        
        // Query raw answers, not the all-time summary table, so every dashboard
        // filter applies to question counts and percentages as well.
        $questions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.question_id, MAX(a.question_label) AS question_label,
                    COUNT(*) AS answered_count, COUNT(DISTINCT t.id) AS respondents
                FROM $answers_table a
                INNER JOIN $tracking_table t ON t.id = a.tracking_id
                WHERE $where
                GROUP BY a.question_id
                ORDER BY question_label",
                $params
            )
        );
        
        error_log('RTS Analytics: Found ' . count($questions) . ' questions for form ' . $form_id);
        
        $result = array();
        foreach ($questions as $q) {
            $answer_params = array_merge($params, array($q->question_id));
            $answers = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT COALESCE(NULLIF(a.answer_label, ''), NULLIF(a.answer_value, ''), 'No answer') AS answer_option,
                        COUNT(*) AS total_votes
                    FROM $answers_table a
                    INNER JOIN $tracking_table t ON t.id = a.tracking_id
                    WHERE $where AND a.question_id = %s
                    GROUP BY answer_option
                    ORDER BY total_votes DESC",
                    $answer_params
                )
            );
            
            $total_votes = 0;
            foreach ($answers as $a) {
                $total_votes += intval($a->total_votes);
            }
            
            foreach ($answers as $a) {
                $a->total_votes = intval($a->total_votes);
                $a->percentage = $total_votes > 0 ? round(($a->total_votes / $total_votes) * 100, 2) : 0;
            }
            
            $result[] = array(
                'question_id' => $q->question_id,
                'question_label' => $q->question_label ?: $q->question_id,
                'answers' => $answers,
                'total_votes' => $total_votes,
                'skipped_questions' => max(0, $total_respondents - intval($q->respondents))
            );
        }
        
        return $result;
    }
    
    /**
     * Get Referral Analytics
     */
    private function get_referral_analytics($form_id, $date_from = '', $date_to = '', $referral_filter = '') {
        global $wpdb;
        $tracking_table = $wpdb->prefix . 'rts_survey_tracking';
        list($where, $params) = $this->get_tracking_filters($form_id, $date_from, $date_to, '', $referral_filter, 't');
        $referral_where = $where . " AND t.referral_code != '' AND t.referral_code IS NOT NULL";
        
        // Get referral sources with data
        $sql = "SELECT 
            referral_source,
            COUNT(*) as visits,
            SUM(CASE WHEN completion_status = 'completed' THEN 1 ELSE 0 END) as completed,
            COUNT(DISTINCT referral_code) as unique_referrals
        FROM $tracking_table t
        WHERE $referral_where
        GROUP BY t.referral_source
        ORDER BY visits DESC";
        
        $sql = $wpdb->prepare($sql, $params);
        $referrals = $wpdb->get_results($sql);
        
        // Get total referral counts
        $total_sql = "SELECT 
            COUNT(*) as total_visits,
            SUM(CASE WHEN t.completion_status = 'completed' THEN 1 ELSE 0 END) as total_completed
        FROM $tracking_table t
        WHERE $referral_where";
        $total_sql = $wpdb->prepare($total_sql, $params);
        $totals = $wpdb->get_row($total_sql);
        
        $total_visits = $totals ? intval($totals->total_visits) : 0;
        $total_completed = $totals ? intval($totals->total_completed) : 0;
        
        // Get total responses for context
        $total_responses = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tracking_table t WHERE $where", $params));
        
        error_log('RTS Analytics: Referral visits: ' . $total_visits . ' out of ' . $total_responses . ' total responses');
        
        return array(
            'sources' => $referrals,
            'total_visits' => $total_visits,
            'total_completed' => $total_completed,
            'total_responses' => intval($total_responses),
            'conversion_rate' => $total_visits > 0 ? round(($total_completed / $total_visits) * 100, 1) : 0
        );
    }
    
    /**
     * Get Investor Insights
     */
    private function get_investor_insights($form_id, $date_from = '', $date_to = '', $verification = '', $referral_filter = '') {
        $stats = $this->get_dashboard_stats($form_id, $date_from, $date_to, $verification, $referral_filter);
        $trends = $this->get_trend_data($form_id, $date_from, $date_to, $verification, $referral_filter);
        $referrals = $this->get_referral_analytics($form_id, $date_from, $date_to, $referral_filter);
        $geo = $this->get_geo_distribution($form_id, $date_from, $date_to, $verification, $referral_filter);
        
        // Calculate trends
        $completion_rate = $stats['completion_percentage'];
        $completion_trend = '⚠️ Needs Improvement';
        if ($completion_rate > 70) {
            $completion_trend = '✅ Excellent';
        } elseif ($completion_rate > 50) {
            $completion_trend = '📊 Good';
        }
        
        // FIX: Use the correct referral visit count
        $referral_visits = isset($referrals['total_visits']) ? intval($referrals['total_visits']) : 0;
        $referral_conversion = isset($referrals['conversion_rate']) ? floatval($referrals['conversion_rate']) : 0;
        $referral_trend = '📊 No referrals yet';
        if ($referral_visits > 0) {
            $referral_trend = $referral_conversion > 50 ? '📈 High Conversion' : '📊 Moderate';
        }
        
        $total_visitors = array_sum($trends['started']);
        $avg_daily = count($trends['started']) > 0 ? round($total_visitors / count($trends['started']), 1) : 0;
        $demand_trend = '📊 Growing';
        if ($avg_daily > 10) {
            $demand_trend = '📈 High';
        }
        
        // Calculate engagement score
        $engagement_score = $this->calculate_engagement_score($stats);
        
        // Get country count
        $country_count = count($geo['labels']);
        
        return array(
            'total_demand' => array(
                'value' => $stats['total_responses'],
                'label' => 'Total Responses',
                'trend' => $demand_trend,
                'description' => 'Total interest in the Run The Seas concept'
            ),
            'completion_rate' => array(
                'value' => $stats['completion_percentage'] . '%',
                'label' => 'Completion Rate',
                'trend' => $completion_trend,
                'description' => 'How many users complete the full survey'
            ),
            'pricing_acceptance' => array(
                'value' => 'N/A',
                'label' => 'Pricing Acceptance',
                'trend' => '📊 Pending',
                'description' => 'Acceptance of proposed pricing model'
            ),
            'referral_impact' => array(
                'value' => $referral_visits, // This should be 3, not 11
                'label' => 'Referral Visits',
                'trend' => $referral_trend,
                'description' => 'Number of visits from referrals'
            ),
            'market_reach' => array(
                'value' => $country_count > 0 ? $country_count : 'N/A',
                'label' => 'Countries Reached',
                'trend' => $country_count > 0 ? '🌍 Global' : '📍 Local',
                'description' => 'Geographic distribution of respondents'
            ),
            'engagement_score' => array(
                'value' => $engagement_score,
                'label' => 'Engagement Score',
                'trend' => '📊 Active',
                'description' => 'Overall engagement based on completion and time'
            )
        );
    }
    
    /**
     * Calculate Engagement Score
     */
    private function calculate_engagement_score($stats) {
        $score = 0;
        
        if ($stats['completion_percentage'] >= 80) $score += 40;
        elseif ($stats['completion_percentage'] >= 60) $score += 30;
        elseif ($stats['completion_percentage'] >= 40) $score += 20;
        elseif ($stats['completion_percentage'] >= 20) $score += 10;
        
        if ($stats['avg_completion_time'] >= 300) $score += 30;
        elseif ($stats['avg_completion_time'] >= 180) $score += 20;
        elseif ($stats['avg_completion_time'] >= 60) $score += 10;
        
        if ($stats['unique_respondents'] >= 100) $score += 30;
        elseif ($stats['unique_respondents'] >= 50) $score += 20;
        elseif ($stats['unique_respondents'] >= 20) $score += 10;
        elseif ($stats['unique_respondents'] >= 5) $score += 5;
        
        return $score . '/100';
    }
    
    /**
     * Get Fluent Forms
     */
    private function get_fluent_forms() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fluentform_forms';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))) !== $table_name) {
            return array();
        }
        $forms = $wpdb->get_results("SELECT id, title FROM $table_name ORDER BY title ASC");
        $settings = get_option('rts_survey_settings', array());

        // BI reports should only offer forms currently approved as active
        // surveys. This keeps the main and Run The Seas dashboards aligned.
        return array_values(array_filter($forms, function ($form) use ($settings) {
            $setting = isset($settings[$form->id]) ? $settings[$form->id] : array();
            return !empty($setting['active']) && empty($setting['excluded']);
        }));
    }

    private function is_active_survey($form_id) {
        foreach ($this->get_fluent_forms() as $form) {
            if ((int) $form->id === (int) $form_id) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Export Report
     */
    public function ajax_export_analytics() {
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'rts_admin_nonce')) {
            wp_die('Invalid nonce');
        }
        
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die('Unauthorized');
        }
        
        $form_id = intval($_GET['form_id']);
        $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
        $verification = isset($_GET['verification']) ? sanitize_key(wp_unslash($_GET['verification'])) : '';
        $referral = isset($_GET['referral']) ? sanitize_key(wp_unslash($_GET['referral'])) : '';
        if (!$this->is_active_survey($form_id)) {
            wp_die('Select an active survey.');
        }
        
        $stats = $this->get_dashboard_stats($form_id, $date_from, $date_to, $verification, $referral);
        $trends = $this->get_trend_data($form_id, $date_from, $date_to, $verification, $referral);
        $questions = $this->get_question_analytics($form_id, $date_from, $date_to, $verification, $referral);
        $referrals = $this->get_referral_analytics($form_id, $date_from, $date_to, $referral);

        $format = isset($_GET['format']) ? sanitize_key(wp_unslash($_GET['format'])) : 'csv';
        if ($format === 'xls') {
            header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
            header('Content-Disposition: attachment; filename="bi-report-' . date('Y-m-d') . '.xls"');
            echo '<table><tr><th colspan="3">BUSINESS INTELLIGENCE REPORT</th></tr>';
            echo '<tr><td>Generated</td><td>' . esc_html(current_time('mysql')) . '</td></tr>';
            echo '<tr><td>Survey ID</td><td>' . esc_html($form_id) . '</td></tr>';
            echo '<tr><th colspan="3">DASHBOARD STATISTICS</th></tr>';
            foreach ($stats as $key => $value) {
                echo '<tr><td>' . esc_html(ucwords(str_replace('_', ' ', $key))) . '</td><td>' . esc_html($value) . '</td></tr>';
            }
            foreach ($questions as $question) {
                echo '<tr><th colspan="3">' . esc_html($question['question_label']) . '</th></tr>';
                echo '<tr><th>Answer option</th><th>Votes</th><th>Percentage</th></tr>';
                foreach ($question['answers'] as $answer) {
                    echo '<tr><td>' . esc_html($answer->answer_option) . '</td><td>' . esc_html($answer->total_votes) . '</td><td>' . esc_html(round($answer->percentage, 1) . '%') . '</td></tr>';
                }
            }
            echo '</table>';
            exit;
        }
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="bi-report-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        
        fputcsv($output, array('BUSINESS INTELLIGENCE REPORT'));
        fputcsv($output, array('Generated:', date('Y-m-d H:i:s')));
        fputcsv($output, array('Survey ID:', $form_id));
        fputcsv($output, array('Filters:', sprintf('Dates %s to %s; verification %s; referral %s', $date_from ?: 'all', $date_to ?: 'all', $verification ?: 'all', $referral ?: 'all')));
        fputcsv($output, array());
        
        // Dashboard Statistics
        fputcsv($output, array('DASHBOARD STATISTICS'));
        foreach ($stats as $key => $value) {
            fputcsv($output, array(ucfirst(str_replace('_', ' ', $key)), $value));
        }
        fputcsv($output, array());
        
        // Question Analytics
        fputcsv($output, array('QUESTION-LEVEL ANALYTICS'));
        foreach ($questions as $q) {
            fputcsv($output, array('Question:', $q['question_label']));
            fputcsv($output, array('Answer Option', 'Votes', 'Percentage'));
            foreach ($q['answers'] as $a) {
                fputcsv($output, array($a->answer_option, $a->total_votes, round($a->percentage, 1) . '%'));
            }
            fputcsv($output, array());
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Archive Data
     */
    public function ajax_archive_analytics() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rts_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $form_id = intval($_POST['form_id']);
        if (!$form_id) {
            wp_send_json_error('Invalid form ID');
            return;
        }
        
        $this->create_archive_tables();
        
        global $wpdb;
        $tracking_table = $wpdb->prefix . 'rts_survey_tracking';
        $answers_table = $wpdb->prefix . 'rts_survey_answers';
        $analytics_table = $wpdb->prefix . 'rts_survey_analytics';
        $archive_tracking = $wpdb->prefix . 'rts_survey_tracking_archive';
        $archive_answers = $wpdb->prefix . 'rts_survey_answers_archive';
        $archive_analytics = $wpdb->prefix . 'rts_survey_analytics_archive';
        
        $archive_date = date('Y-m-d H:i:s', strtotime('-90 days'));
        
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO $archive_tracking SELECT *, %s as archive_date FROM $tracking_table WHERE form_id = %d AND started_at < %s",
                $archive_date, $form_id, $archive_date
            )
        );
        
        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM $tracking_table WHERE form_id = %d AND started_at < %s", $form_id, $archive_date)
        );
        
        wp_send_json_success("Archived $deleted records older than 90 days");
    }
    
    /**
     * Reset Analytics
     */
    public function ajax_reset_analytics() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rts_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $form_id = intval($_POST['form_id']);
        if (!$form_id) {
            wp_send_json_error('Invalid form ID');
            return;
        }
        
        if ($this->tracking) {
            $result = $this->tracking->reset_survey_statistics($form_id);
            wp_send_json_success($result);
        } else {
            wp_send_json_error('Tracking system not available');
        }
    }
    
    /**
     * Create archive tables
     */
    private function create_archive_tables() {
        global $wpdb;
        
        $tables = array(
            'rts_survey_tracking_archive' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rts_survey_tracking_archive LIKE {$wpdb->prefix}rts_survey_tracking",
            'rts_survey_answers_archive' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rts_survey_answers_archive LIKE {$wpdb->prefix}rts_survey_answers",
            'rts_survey_analytics_archive' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rts_survey_analytics_archive LIKE {$wpdb->prefix}rts_survey_analytics"
        );
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        foreach ($tables as $table_name => $sql) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                $wpdb->query($sql);
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN archive_date datetime DEFAULT NULL");
            }
        }
    }


    public function ajax_get_online_users() {
        check_ajax_referer('rts_admin_nonce', 'nonce');
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_send_json_error('Insufficient permissions.', 403);
        }
        
        // Get active sessions in last 15 minutes
        global $wpdb;
        $count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}usermeta 
            WHERE meta_key = 'session_tokens' AND meta_value IS NOT NULL"
        );
        
        wp_send_json_success(array('count' => intval($count)));
    }



    public function ajax_get_trophy_visits() {
        check_ajax_referer('rts_admin_nonce', 'nonce');
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_send_json_error('Insufficient permissions.', 403);
        }
        
        global $wpdb;
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        
        $where = "action = 'trophy_case_view'";
        if ($date_from) {
            $where .= $wpdb->prepare(" AND created_at >= %s", $date_from . ' 00:00:00');
        }
        if ($date_to) {
            $where .= $wpdb->prepare(" AND created_at <= %s", $date_to . ' 23:59:59');
        }
        
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rts_activity_logs WHERE $where"
        );
        
        wp_send_json_success(array('count' => intval($count)));
    }

    /**
     * Get only logged-in users (unique, not sessions/tabs)
     */
    public function ajax_get_logged_in_users() {
        check_ajax_referer('rts_admin_nonce', 'nonce');
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_send_json_error('Unauthorized', 403);
        }
        
        // Get all logged-in users (unique users, not sessions)
        $logged_in_users = get_users(array(
            'fields' => 'ID',
            'meta_query' => array(
                array(
                    'key' => 'session_tokens',
                    'compare' => 'EXISTS'
                )
            )
        ));
        
        // Count only users with active sessions in the last 15 minutes
        $count = 0;
        $now = time();
        foreach ($logged_in_users as $user_id) {
            $sessions = get_user_meta($user_id, 'session_tokens', true);
            if (is_array($sessions)) {
                foreach ($sessions as $session) {
                    if (isset($session['expiration']) && $session['expiration'] > $now) {
                        $count++;
                        break;
                    }
                }
            }
        }
        
        wp_send_json_success(array('count' => $count));
    }
}

// Initialize the BI Dashboard
function rts_init_bi_dashboard() {
    if (!is_admin()) {
        return;
    }
    
    $tracking = function_exists('rts_init') ? rts_init()->tracking : null;
    global $rts_analytics_instance;
    
    if (!isset($rts_analytics_instance)) {
        $rts_analytics_instance = new RTS_Analytics($tracking);
    }
}
add_action('admin_init', 'rts_init_bi_dashboard');

// Fallback initialization
add_action('admin_menu', function() {
    global $rts_analytics_instance;
    if (!isset($rts_analytics_instance)) {
        $tracking = function_exists('rts_init') ? rts_init()->tracking : null;
        $rts_analytics_instance = new RTS_Analytics($tracking);
    }
}, 1);
