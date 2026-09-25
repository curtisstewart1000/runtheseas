<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RTS_Admin_Menu_2 {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		// WordPress-native form handling: admin_post_{action} with nonce verification.
		foreach ( array( 'clone_survey','survey_status','participant_status','participant_edit','participant_note','participant_email','suspend','reinstate','merge','manual_verify','reset_passcode','create_template','update_template','assign_template' ) as $a ) {
			add_action( "admin_post_rts_$a", array( __CLASS__, "handle_$a" ) );
		}
	}

	public static function register_menu() {
		RTS_Auth::page( 'rts-admin', 'Survey Administration', 'Survey Administration', 'rts_view', 'rts-surveys', array( __CLASS__, 'render_surveys' ) );
		RTS_Auth::page( 'rts-admin', 'Survey Analytics & Statistical Reporting', 'Survey Reporting', 'rts_view', 'rts-survey-reporting', array( __CLASS__, 'render_survey_reporting' ) );
		RTS_Auth::page( 'rts-admin', 'Verification Queue', 'Verification Queue', 'rts_view', 'rts-verification-queue', array( __CLASS__, 'render_queue' ) );
		RTS_Auth::page( 'rts-admin', 'Email Templates', 'Email Templates', 'rts_view', 'rts-email-templates', array( __CLASS__, 'render_templates' ) );
		RTS_Auth::page( null, 'Participant Profile', 'Participant Profile', 'rts_view', 'rts-participant-profile', array( __CLASS__, 'render_profile' ) ); // hidden from menu; reached via Participants list
	}

	// ---------- helpers ----------
	private static function form( $action, $fields_html, $button, $extra_hidden = array() ) {
		$h = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">'
		   . '<input type="hidden" name="action" value="rts_' . esc_attr( $action ) . '">' . wp_nonce_field( 'rts_' . $action, '_rts_nonce', true, false );
		foreach ( $extra_hidden as $k => $v ) { $h .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">'; }
		return $h . $fields_html . '<button class="button">' . esc_html( $button ) . '</button></form>';
	}
	private static function guard( $action ) {
		if ( ! current_user_can( RTS_Auth::action_cap( $action ) ) || ! isset( $_POST['_rts_nonce'] ) || ! wp_verify_nonce( $_POST['_rts_nonce'], 'rts_' . $action ) ) { wp_die( 'Not allowed.', 'Forbidden', array( 'response' => 403 ) ); }
	}
	private static function back( $page, $msg = '', $extra = array() ) {
		$fragment = isset( $extra['_fragment'] ) ? sanitize_key( $extra['_fragment'] ) : '';
		unset( $extra['_fragment'] );
		$args = $extra;
		if ( $msg ) { $args['rts_msg'] = rawurlencode( $msg ); }
		$url = RTSAP_Frontend_Dashboard::screen_url( $page, $args );
		if ( $fragment ) { $url .= '#' . $fragment; }
		wp_safe_redirect( $url ); exit;
	}
	private static function notice() {
		if ( ! empty( $_GET['rts_msg'] ) ) {
			$message = rawurldecode( $_GET['rts_msg'] );
			$class = str_starts_with( $message, 'Error' ) ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}
	private static function admin() {
		$u = wp_get_current_user();
		if ( ! $u || ! $u->exists() ) { return 'admin'; }
		return $u->display_name ?: $u->user_login;
	}

	// ---------- Survey Administration ----------
	public static function render_surveys() {
		$survey_id = isset( $_GET['survey_id'] ) ? absint( $_GET['survey_id'] ) : 0;
		if ( $survey_id ) {
			self::render_survey_workspace( $survey_id );
			return;
		}

		echo '<div class="wrap"><h1>Survey Administration</h1>'; self::notice();
		echo '<div class="rtsap-list-toolbar"><div><b>' . esc_html__( 'Fluent Forms surveys', 'run-the-seas' ) . '</b><span>' . esc_html__( 'Clone, edit, preview and inspect conditional routing from one workspace.', 'run-the-seas' ) . '</span></div>'
			. '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=fluent_forms#add=1' ) ) . '">' . esc_html__( '+ New Fluent Form', 'run-the-seas' ) . '</a></div>';
		$rows = RTS_Business_Logic_2::list_surveys();
		echo '<table class="wp-list-table widefat striped rtsap-surveys-table"><thead><tr><th>Name</th><th>Fluent Form</th><th>Status</th><th>Responses</th><th>Completion</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $rows as $s ) {
			$workspace_url = self::survey_workspace_url( $s->id, 'questions' );
			$report_url = self::survey_reporting_url( $s->id );
			echo '<tr><td><a href="' . esc_url( $workspace_url ) . '"><b>' . esc_html( $s->name ) . '</b></a></td>'
				. '<td>' . ( $s->source_form_id ? '#' . (int) $s->source_form_id : '<span class="rtsap-muted">Legacy</span>' ) . '</td>'
				. '<td><span class="rtsap-badge rtsap-badge--' . esc_attr( $s->status ) . '">' . esc_html( ucfirst( $s->status ) ) . '</span></td>'
				. '<td>' . (int) $s->total_responses . '</td><td>' . esc_html( $s->completion_rate ) . '%</td><td class="rtsap-row-actions"><div class="rtsap-action-group">';
			echo '<a class="button" href="' . esc_url( $workspace_url ) . '">' . esc_html__( 'Manage', 'run-the-seas' ) . '</a> '
				. '<a class="button" href="' . esc_url( $report_url ) . '">' . esc_html__( 'Report', 'run-the-seas' ) . '</a> ';
			if ( $s->edit_url ) { echo '<a class="button" href="' . esc_url( $s->edit_url ) . '">' . esc_html__( 'Edit Form', 'run-the-seas' ) . '</a> '; }
			echo self::form( 'clone_survey', '', 'Clone', array( 'id' => $s->id ) ) . ' ';
			if ( 'live' !== $s->status ) { echo self::form( 'survey_status', '', 'Publish', array( 'id' => $s->id, 'status' => 'live' ) ) . ' '; }
			if ( 'live' === $s->status ) { echo self::form( 'survey_status', '', 'Archive', array( 'id' => $s->id, 'status' => 'archived' ) ); }
			echo '</div></td></tr>';
		}
		echo '</tbody></table>';
		self::render_reporting_snapshot();
		echo '</div>';
	}

	private static function survey_reporting_url( $survey_id ) {
		return RTSAP_Frontend_Dashboard::screen_url( 'rts-survey-reporting', array( 'survey_id' => (int) $survey_id ) );
	}

	private static function render_reporting_snapshot() {
		$snapshot = RTS_Business_Logic_2::survey_reporting_snapshot();
		$total = max( 1, (int) $snapshot['total'] ); $registered = max( 1, (int) $snapshot['registered'] );
		$anonymous_pct = round( (int) $snapshot['anonymous'] / $total * 100 );
		$registered_pct = round( (int) $snapshot['registered'] / $total * 100 );
		$verified_pct = round( (int) $snapshot['verified'] / $registered * 100 );
		$unverified_pct = round( (int) $snapshot['unverified'] / $registered * 100 );
		$trend = is_null( $snapshot['trend'] ) ? '—' : ( $snapshot['trend'] > 0 ? '▲ ' : ( $snapshot['trend'] < 0 ? '▼ ' : '' ) ) . abs( $snapshot['trend'] ) . '%';
		echo '<section class="rtsap-report-snapshot"><div class="rtsap-panel__head"><div><span class="rtsap-panel__eyebrow">' . esc_html__( 'All Fluent Forms surveys', 'run-the-seas' ) . '</span><h3>' . esc_html__( 'Reporting Snapshot', 'run-the-seas' ) . '</h3></div><a class="button" href="' . esc_url( self::survey_reporting_url( 0 ) ) . '">' . esc_html__( 'Open Survey Reporting', 'run-the-seas' ) . ' →</a></div><div class="rtsap-kpi-grid">'
			. self::report_kpi( 'Participant totals', number_format_i18n( $snapshot['total'] ), $snapshot['completed'] . ' completed · ' . $snapshot['completion_rate'] . '% completion' )
			. self::report_kpi( 'Anonymous vs registered', $anonymous_pct . '% / ' . $registered_pct . '%', number_format_i18n( $snapshot['anonymous'] ) . ' anonymous · ' . number_format_i18n( $snapshot['registered'] ) . ' registered' )
			. self::report_kpi( 'Verified vs unverified', $verified_pct . '% / ' . $unverified_pct . '%', number_format_i18n( $snapshot['verified'] ) . ' verified records' )
			. self::report_kpi( 'Survey completion trend', $trend, 'last 30 days vs previous 30 days' )
			. '</div></section>';
	}

	private static function report_kpi( $label, $value, $sub = '' ) {
		return '<div class="rtsap-kpi"><div class="rtsap-kpi__label">' . esc_html( $label ) . '</div><div class="rtsap-kpi__value">' . esc_html( $value ) . '</div>' . ( $sub ? '<div class="rtsap-kpi__sub">' . esc_html( $sub ) . '</div>' : '' ) . '</div>';
	}

	private static function format_duration( $seconds ) {
		if ( is_null( $seconds ) ) { return '—'; }
		$seconds = max( 0, (int) $seconds );
		return $seconds >= 3600 ? floor( $seconds / 3600 ) . 'h ' . floor( ( $seconds % 3600 ) / 60 ) . 'm' : floor( $seconds / 60 ) . 'm ' . ( $seconds % 60 ) . 's';
	}

	private static function render_completion_funnel( $stats ) {
		$started = max( 1, (int) $stats['started'] );
		$stages = array( 'Started' => $stats['started'], 'Reached Q5' => $stats['step5'], 'Reached Q10' => $stats['step10'], 'Completed' => $stats['completed'] );
		echo '<div class="rtsap-report-funnel">';
		foreach ( $stages as $label => $value ) {
			$height = max( 5, round( (int) $value / $started * 100, 1 ) );
			echo '<div class="rtsap-report-funnel__stage"><span class="rtsap-report-funnel__value">' . esc_html( number_format_i18n( $value ) ) . '</span><span class="rtsap-report-funnel__bar" style="height:' . esc_attr( $height ) . '%"></span><b>' . esc_html( $label ) . '</b><small>' . esc_html( round( (int) $value / $started * 100, 1 ) ) . '%</small></div>';
		}
		echo '</div>';
	}

	private static function render_time_chart( $daily_times, $stats ) {
		$max = $daily_times ? max( array_map( fn( $row ) => (float) $row->value, $daily_times ) ) : 0;
		echo '<div class="rtsap-time-summary"><b>' . esc_html( self::format_duration( $stats['avg_seconds'] ) ) . '</b><span>' . esc_html__( 'average', 'run-the-seas' ) . '</span><b>' . esc_html( self::format_duration( $stats['median_seconds'] ) ) . '</b><span>' . esc_html__( 'median', 'run-the-seas' ) . '</span></div>';
		if ( ! $daily_times ) { echo '<div class="rtsap-panel__empty">' . esc_html__( 'No completed-response timing data in this date range.', 'run-the-seas' ) . '</div>'; return; }
		echo '<div class="rtsap-time-chart">';
		foreach ( $daily_times as $row ) {
			$height = $max ? max( 4, round( (float) $row->value / $max * 100, 1 ) ) : 4;
			echo '<span title="' . esc_attr( $row->label . ' · ' . self::format_duration( $row->value ) ) . '" style="height:' . esc_attr( $height ) . '%"></span>';
		}
		echo '</div><div class="rtsap-chart-caption">' . esc_html__( 'Daily average completion time', 'run-the-seas' ) . '</div>';
	}

	public static function render_survey_reporting() {
		$surveys = RTS_Business_Logic_2::list_surveys();
		$survey_id = isset( $_GET['survey_id'] ) ? absint( $_GET['survey_id'] ) : 0;
		if ( ! $survey_id && $surveys ) {
			$default_survey = array_reduce( $surveys, fn( $best, $survey ) => ! $best || (int) $survey->total_responses > (int) $best->total_responses ? $survey : $best );
			$survey_id = (int) $default_survey->id;
		}
		$range = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '30';
		$question_id = isset( $_GET['question_id'] ) ? sanitize_text_field( wp_unslash( $_GET['question_id'] ) ) : '';
		$audience = isset( $_GET['audience'] ) ? sanitize_key( wp_unslash( $_GET['audience'] ) ) : 'all';
		$report = RTS_Business_Logic_2::survey_reporting( $survey_id, $range, $question_id, $audience );
		echo '<div class="wrap"><h1>' . esc_html__( 'Survey Analytics & Statistical Reporting', 'run-the-seas' ) . '</h1><p class="rtsap-muted">' . esc_html__( 'Question-level breakdowns, completion funnel and response timing from live survey data.', 'run-the-seas' ) . '</p>';
		if ( ! empty( $report['error'] ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'No survey is available for reporting.', 'run-the-seas' ) . '</p></div></div>'; return; }

		echo '<form class="rtsap-report-toolbar" method="get" action="' . esc_url( RTSAP_Frontend_Dashboard::screen_url( 'rts-survey-reporting' ) ) . '">' . RTSAP_Frontend_Dashboard::screen_field( 'rts-survey-reporting' ) . '<label><span>' . esc_html__( 'Survey', 'run-the-seas' ) . '</span><select name="survey_id">';
		foreach ( $surveys as $survey ) { echo '<option value="' . (int) $survey->id . '"' . selected( $survey_id, $survey->id, false ) . '>' . esc_html( $survey->name . ( $survey->source_form_id ? ' · Form #' . $survey->source_form_id : ' · Legacy' ) ) . '</option>'; }
		echo '</select></label><label><span>' . esc_html__( 'Date range', 'run-the-seas' ) . '</span><select name="range"><option value="30"' . selected( $report['range'], '30', false ) . '>Last 30 days</option><option value="90"' . selected( $report['range'], '90', false ) . '>Last 90 days</option><option value="all"' . selected( $report['range'], 'all', false ) . '>All time</option></select></label><button class="button">' . esc_html__( 'Apply', 'run-the-seas' ) . '</button><div class="rtsap-report-toolbar__actions">';
		if ( $report['form_id'] ) { echo '<a class="button button-primary" href="' . esc_url( RTSAP_Frontend_Dashboard::screen_url( 'rts-fluent-form', array( 'form_id' => (int) $report['form_id'], 'route' => 'entries' ) ) ) . '">' . esc_html__( 'Open Fluent Entries', 'run-the-seas' ) . ' ↗</a>'; }
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=rts-report-builder' ) ) . '">' . esc_html__( 'Report Builder', 'run-the-seas' ) . '</a></div></form>';

		echo '<div class="rtsap-kpi-grid">' . self::report_kpi( 'Survey starts', number_format_i18n( $report['stats']['started'] ), $report['range'] === 'all' ? 'all time' : 'last ' . $report['range'] . ' days' ) . self::report_kpi( 'Completed', number_format_i18n( $report['stats']['completed'] ) ) . self::report_kpi( 'Completion rate', $report['stats']['completion_rate'] . '%' ) . self::report_kpi( 'Average time', self::format_duration( $report['stats']['avg_seconds'] ), 'median ' . self::format_duration( $report['stats']['median_seconds'] ) ) . '</div>';
		echo '<div class="rtsap-dashboard-grid"><section class="rtsap-panel rtsap-panel--seven"><div class="rtsap-panel__head"><h3>' . esc_html__( 'Completion Funnel', 'run-the-seas' ) . '</h3></div>'; self::render_completion_funnel( $report['stats'] ); echo '</section><section class="rtsap-panel rtsap-panel--five"><div class="rtsap-panel__head"><h3>' . esc_html__( 'Average Completion Time', 'run-the-seas' ) . '</h3></div>'; self::render_time_chart( $report['daily_times'], $report['stats'] ); echo '</section></div>';

		echo '<section class="rtsap-panel rtsap-panel--wide rtsap-question-analysis"><div class="rtsap-panel__head"><div><span class="rtsap-panel__eyebrow">' . esc_html__( 'Advanced survey analysis', 'run-the-seas' ) . '</span><h3>' . esc_html__( 'Question-Level Breakdown', 'run-the-seas' ) . '</h3></div></div><form class="rtsap-analysis-controls" method="get" action="' . esc_url( RTSAP_Frontend_Dashboard::screen_url( 'rts-survey-reporting' ) ) . '">' . RTSAP_Frontend_Dashboard::screen_field( 'rts-survey-reporting' ) . '<input type="hidden" name="survey_id" value="' . (int) $survey_id . '"><input type="hidden" name="range" value="' . esc_attr( $report['range'] ) . '"><label><span>' . esc_html__( 'Question', 'run-the-seas' ) . '</span><select name="question_id">';
		foreach ( $report['questions'] as $question ) { echo '<option value="' . esc_attr( $question->question_id ) . '"' . selected( $report['question_id'], $question->question_id, false ) . '>' . esc_html( $question->label ) . '</option>'; }
		echo '</select></label><label><span>' . esc_html__( 'Audience', 'run-the-seas' ) . '</span><select name="audience"><option value="all"' . selected( $report['audience'], 'all', false ) . '>All respondents</option><option value="runner"' . selected( $report['audience'], 'runner', false ) . '>Runners</option><option value="non_runner"' . selected( $report['audience'], 'non_runner', false ) . '>Non-runners</option></select></label><button class="button button-primary">' . esc_html__( 'Run Analysis', 'run-the-seas' ) . '</button></form>';
		echo '<table class="wp-list-table widefat striped rtsap-analysis-table"><thead><tr><th>' . esc_html__( 'Answer', 'run-the-seas' ) . '</th><th>' . esc_html__( 'Count', 'run-the-seas' ) . '</th><th>' . esc_html__( '% of Total', 'run-the-seas' ) . '</th></tr></thead><tbody>';
		if ( ! $report['breakdown'] ) { echo '<tr><td colspan="3">' . esc_html__( 'No answer data is available for this question and filter.', 'run-the-seas' ) . '</td></tr>'; }
		foreach ( $report['breakdown'] as $row ) { $percent = $report['breakdown_total'] ? round( (int) $row->answer_count / $report['breakdown_total'] * 100, 1 ) : 0; echo '<tr><td>' . esc_html( $row->answer ) . '</td><td>' . esc_html( number_format_i18n( $row->answer_count ) ) . '</td><td><span class="rtsap-answer-share"><i style="width:' . esc_attr( $percent ) . '%"></i></span>' . esc_html( $percent ) . '%</td></tr>'; }
		echo '</tbody></table></section></div>';
	}

	private static function survey_workspace_url( $survey_id, $tab ) {
		return RTSAP_Frontend_Dashboard::screen_url( 'rts-surveys', array( 'survey_id' => (int) $survey_id, 'tab' => sanitize_key( $tab ) ) );
	}

	private static function render_survey_workspace( $survey_id ) {
		$workspace = RTS_Business_Logic_2::survey_workspace( $survey_id );
		if ( ! empty( $workspace['error'] ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Survey Question Builder', 'run-the-seas' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'The selected survey could not be found.', 'run-the-seas' ) . '</p></div></div>';
			return;
		}

		$survey = $workspace['survey'];
		$questions = $workspace['questions'];
		$allowed_tabs = array( 'questions', 'logic', 'design', 'preview', 'publish' );
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'questions';
		if ( ! in_array( $tab, $allowed_tabs, true ) ) { $tab = 'questions'; }

		echo '<div class="wrap rtsap-survey-workspace"><h1>' . esc_html__( 'Survey Question Builder', 'run-the-seas' ) . '</h1>'; self::notice();
		echo '<div class="rtsap-survey-heading"><div><a href="' . esc_url( RTSAP_Frontend_Dashboard::screen_url( 'rts-surveys' ) ) . '">← ' . esc_html__( 'All surveys', 'run-the-seas' ) . '</a>'
			. '<h2>' . esc_html( $survey->name ) . '</h2><p>' . esc_html__( 'Conditional survey system powered by the selected Fluent Form.', 'run-the-seas' ) . '</p></div>';
		if ( $workspace['editor_url'] ) {
			echo '<a class="button button-primary" href="' . esc_url( $workspace['editor_url'] ) . '">' . esc_html__( 'Edit Form in Fluent Forms', 'run-the-seas' ) . ' ↗</a>';
		}
		echo '</div>';

		$tabs = array( 'questions' => 'Questions', 'logic' => 'Logic Map', 'design' => 'Design & Branding', 'preview' => 'Preview', 'publish' => 'Publish' );
		echo '<nav class="rtsap-tabs" aria-label="' . esc_attr__( 'Survey workspace', 'run-the-seas' ) . '">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="rtsap-tab' . ( $tab === $key ? ' is-active' : '' ) . '" href="' . esc_url( self::survey_workspace_url( $survey_id, $key ) ) . '"' . ( $tab === $key ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		if ( 'logic' === $tab ) { self::render_logic_tab( $questions, $workspace['editor_url'] ); }
		elseif ( 'design' === $tab ) { self::render_design_tab( $workspace ); }
		elseif ( 'preview' === $tab ) { self::render_preview_tab( $workspace ); }
		elseif ( 'publish' === $tab ) { self::render_publish_tab( $survey ); }
		else { self::render_questions_tab( $survey_id, $questions, $workspace ); }

		echo '</div>';
	}

	private static function render_questions_tab( $survey_id, $questions, $workspace ) {
		$sections = array_values( array_unique( array_column( $questions, 'section' ) ) );
		$types = array_values( array_unique( array_column( $questions, 'type_label' ) ) );
		$selected_section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		$selected_type = isset( $_GET['question_type'] ) ? sanitize_text_field( wp_unslash( $_GET['question_type'] ) ) : '';
		$search = isset( $_GET['question_search'] ) ? sanitize_text_field( wp_unslash( $_GET['question_search'] ) ) : '';
		$label_map = array_column( $questions, 'label', 'name' );

		echo '<form class="rtsap-question-toolbar" method="get"><input type="hidden" name="page" value="rts-surveys"><input type="hidden" name="survey_id" value="' . (int) $survey_id . '"><input type="hidden" name="tab" value="questions">';
		echo '<select name="section"><option value="">' . esc_html__( 'All sections', 'run-the-seas' ) . '</option>'; foreach ( $sections as $section ) { echo '<option value="' . esc_attr( $section ) . '"' . selected( $selected_section, $section, false ) . '>' . esc_html( $section ) . '</option>'; } echo '</select>';
		echo '<select name="question_type"><option value="">' . esc_html__( 'All question types', 'run-the-seas' ) . '</option>'; foreach ( $types as $type ) { echo '<option value="' . esc_attr( $type ) . '"' . selected( $selected_type, $type, false ) . '>' . esc_html( $type ) . '</option>'; } echo '</select>';
		echo '<input type="search" name="question_search" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search questions…', 'run-the-seas' ) . '"><button class="button">' . esc_html__( 'Filter', 'run-the-seas' ) . '</button>';
		if ( $workspace['editor_url'] ) { echo '<a class="button button-primary rtsap-toolbar-end" href="' . esc_url( $workspace['editor_url'] ) . '">' . esc_html__( '+ Add or Edit Questions', 'run-the-seas' ) . '</a>'; }
		$display_count = count( array_filter( $questions, fn( $question ) => ! empty( $question['is_display'] ) ) );
		$answer_count = count( $questions ) - $display_count;
		echo '</form><div class="rtsap-question-count"><b>' . esc_html( $answer_count ) . ' ' . esc_html__( 'answer fields', 'run-the-seas' ) . '</b><span>' . esc_html( count( $sections ) ) . ' ' . esc_html__( 'pages', 'run-the-seas' ) . ' · ' . esc_html( $display_count ) . ' ' . esc_html__( 'information or parent-prompt blocks', 'run-the-seas' ) . '</span></div><div class="rtsap-question-list">';

		$shown = 0;
		foreach ( $questions as $index => $question ) {
			if ( $selected_section && $selected_section !== $question['section'] ) { continue; }
			if ( $selected_type && $selected_type !== $question['type_label'] ) { continue; }
			if ( $search && false === stripos( $question['label'], $search ) ) { continue; }
			$shown++;
			$is_display = ! empty( $question['is_display'] );
			echo '<article class="rtsap-question-card' . ( $is_display ? ' is-display' : '' ) . '"><span class="rtsap-question-card__handle" aria-hidden="true">' . ( $is_display ? 'ℹ' : '⠿' ) . '</span><div class="rtsap-question-card__body">'
				. '<div class="rtsap-question-card__title"><b>' . esc_html( $question['label'] ) . '</b><span>' . esc_html( $question['type_label'] ) . '</span><span>' . esc_html( $is_display ? 'Display content' : ( $question['required'] ? 'Required' : 'Optional' ) ) . '</span></div>'
				. '<small>' . esc_html( $question['section'] ) . ( ! $is_display && $question['name'] ? ' · ' . esc_html( $question['name'] ) : '' ) . '</small>';
			foreach ( $question['conditions'] as $condition ) {
				$source = $label_map[ $condition['field'] ] ?? $condition['field'];
				echo '<div class="rtsap-question-card__condition">↳ ' . esc_html( sprintf( 'Shown when “%1$s” %2$s “%3$s”', $source, $condition['operator'] ?? '=', $condition['value'] ?? '' ) ) . '</div>';
			}
			echo '</div>';
			if ( $workspace['editor_url'] ) { echo '<a class="rtsap-question-card__edit" href="' . esc_url( $workspace['editor_url'] ) . '" aria-label="' . esc_attr__( 'Edit this form in Fluent Forms', 'run-the-seas' ) . '">✎</a>'; }
			echo '</article>';
		}
		if ( ! $shown ) { echo '<div class="rtsap-empty-state">' . esc_html__( 'No questions match these filters.', 'run-the-seas' ) . '</div>'; }
		echo '</div><div class="rtsap-note">' . esc_html__( 'Question order, field settings and conditional rules are read directly from Fluent Forms. Edit there, then return here to review the updated flow.', 'run-the-seas' ) . '</div>';
		echo '<div class="rtsap-workspace-actions">';
		if ( $workspace['preview_url'] ) { echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( $workspace['preview_url'] ) . '">' . esc_html__( 'Preview Survey', 'run-the-seas' ) . '</a>'; }
		echo '<a class="button button-primary" href="' . esc_url( self::survey_workspace_url( $survey_id, 'publish' ) ) . '">' . esc_html__( 'Publish', 'run-the-seas' ) . '</a></div>';
	}

	private static function condition_operator_label( $operator ) {
		$labels = array( '=' => 'is', '==' => 'is', '!=' => 'is not', 'contains' => 'contains', 'not_contains' => 'does not contain', 'starts_with' => 'starts with', 'ends_with' => 'ends with', '>' => 'is greater than', '<' => 'is less than', '>=' => 'is at least', '<=' => 'is at most' );
		return $labels[ $operator ] ?? $operator;
	}

	private static function render_logic_tab( $questions, $editor_url ) {
		$by_name = array_column( $questions, null, 'name' );
		$routes = array(); $conditional_items = 0; $condition_count = 0;
		$sections = array();
		foreach ( $questions as $question ) {
			$sections[ $question['section'] ][] = $question;
			if ( ! empty( $question['conditions'] ) ) { $conditional_items++; }
			foreach ( $question['conditions'] as $condition ) {
				$condition_count++;
				$key = (string) $condition['field'];
				$route_key = ( $condition['operator'] ?? '=' ) . '|' . ( $condition['value'] ?? '' );
				if ( ! isset( $routes[ $key ][ $route_key ] ) ) { $routes[ $key ][ $route_key ] = array( 'condition' => $condition, 'targets' => array() ); }
				$routes[ $key ][ $route_key ]['targets'][] = $question;
			}
		}

		$display_count = count( array_filter( $questions, fn( $question ) => ! empty( $question['is_display'] ) ) );
		$answer_count = count( $questions ) - $display_count;
		echo '<div class="rtsap-panel rtsap-panel--wide rtsap-logic-panel"><div class="rtsap-panel__head"><div><span class="rtsap-panel__eyebrow">' . esc_html__( 'Complete form order plus Fluent Forms rules', 'run-the-seas' ) . '</span><h3>' . esc_html__( 'Survey Logic Map', 'run-the-seas' ) . '</h3></div>';
		if ( $editor_url ) { echo '<a class="button" href="' . esc_url( $editor_url ) . '">' . esc_html__( 'Edit logic in Fluent Forms', 'run-the-seas' ) . ' ↗</a>'; }
		echo '</div><div class="rtsap-logic-stats"><div><b>' . esc_html( $answer_count ) . '</b><span>' . esc_html__( 'answer fields', 'run-the-seas' ) . '</span></div><div><b>' . esc_html( count( $sections ) ) . '</b><span>' . esc_html__( 'survey pages', 'run-the-seas' ) . '</span></div><div><b>' . esc_html( count( $routes ) ) . '</b><span>' . esc_html__( 'branch sources', 'run-the-seas' ) . '</span></div><div><b>' . esc_html( $conditional_items ) . '</b><span>' . esc_html__( 'conditionally displayed items', 'run-the-seas' ) . '</span></div></div>';

		if ( $routes ) {
			echo '<section class="rtsap-route-summary"><div class="rtsap-flow-heading"><div><span>' . esc_html__( 'Conditional flowchart', 'run-the-seas' ) . '</span><h4>' . esc_html__( 'Answer paths and destination questions', 'run-the-seas' ) . '</h4></div><small>' . esc_html( $condition_count ) . ' ' . esc_html__( 'active rules', 'run-the-seas' ) . '</small></div>';
			foreach ( $routes as $source_name => $source_routes ) {
				$source = $by_name[ $source_name ] ?? array( 'label' => $source_name );
				echo '<article class="rtsap-flowchart"><div class="rtsap-flowchart__source rtsap-flowchart__node"><small>' . esc_html__( 'IF ANSWER TO', 'run-the-seas' ) . '</small><b>' . esc_html( $source['label'] ) . '</b></div><span class="rtsap-flowchart__stem" aria-hidden="true">↓</span><div class="rtsap-flowchart__branches" style="--rtsap-path-count:' . esc_attr( count( $source_routes ) ) . '">';
				foreach ( $source_routes as $route ) {
					$condition = $route['condition'];
					echo '<section class="rtsap-flowchart__path"><div class="rtsap-flowchart__answer"><small>' . esc_html__( 'ANSWER', 'run-the-seas' ) . '</small><b>' . esc_html( self::condition_operator_label( $condition['operator'] ?? '=' ) . ' “' . trim( (string) ( $condition['value'] ?? '' ) ) . '”' ) . '</b></div><span class="rtsap-flowchart__path-arrow" aria-hidden="true">↓</span><div class="rtsap-flowchart__targets">';
					foreach ( $route['targets'] as $target_index => $target ) {
						echo '<div class="rtsap-flowchart__node"><small>' . esc_html__( 'SHOW', 'run-the-seas' ) . ' · ' . esc_html( $target['section'] ) . '</small><b>' . esc_html( $target['label'] ) . '</b></div>';
						if ( $target_index < count( $route['targets'] ) - 1 ) { echo '<span class="rtsap-flowchart__node-arrow" aria-hidden="true">↓</span>'; }
					}
					echo '</div></section>';
				}
				echo '</div><span class="rtsap-flowchart__merge-arrow" aria-hidden="true">↓</span><div class="rtsap-flowchart__merge rtsap-flowchart__node"><small>' . esc_html__( 'PATHS REJOIN', 'run-the-seas' ) . '</small><b>' . esc_html__( 'Continue through the shared survey questions in display order', 'run-the-seas' ) . '</b></div></article>';
			}
			echo '</section>';
		}

		echo '<section class="rtsap-complete-flow"><div class="rtsap-flow-heading"><div><span>' . esc_html__( 'Complete survey', 'run-the-seas' ) . '</span><h4>' . esc_html__( 'Every field in display order', 'run-the-seas' ) . '</h4></div><small>' . esc_html( count( $questions ) ) . ' ' . esc_html__( 'items including parent prompts', 'run-the-seas' ) . '</small></div>';
		$position = 0;
		foreach ( $sections as $section => $items ) {
			echo '<article class="rtsap-flow-section"><header><b>' . esc_html( $section ) . '</b><span>' . esc_html( count( $items ) ) . ' ' . esc_html__( 'items', 'run-the-seas' ) . '</span></header><div class="rtsap-flow-list">';
			foreach ( $items as $item ) {
				$position++; $is_display = ! empty( $item['is_display'] ); $is_conditional = ! empty( $item['conditions'] ); $controls = $routes[ $item['name'] ] ?? array();
				echo '<div class="rtsap-flow-item' . ( $is_conditional ? ' is-conditional' : '' ) . ( $is_display ? ' is-display' : '' ) . '"><span class="rtsap-flow-item__number">' . esc_html( $position ) . '</span><div class="rtsap-flow-item__content"><div class="rtsap-flow-item__title"><b>' . esc_html( $item['label'] ) . '</b><span>' . esc_html( $item['type_label'] ) . '</span>' . ( $is_conditional ? '<em>' . esc_html__( 'Conditional', 'run-the-seas' ) . '</em>' : '' ) . '</div>';
				if ( $is_conditional ) {
					echo '<div class="rtsap-flow-item__conditions"><strong>' . esc_html( 'any' === ( $item['condition_type'] ?? 'all' ) ? 'Show when any rule matches:' : 'Show when all rules match:' ) . '</strong>';
					foreach ( $item['conditions'] as $condition ) {
						$source = $by_name[ $condition['field'] ]['label'] ?? $condition['field'];
						echo '<span>' . esc_html( $source . ' ' . self::condition_operator_label( $condition['operator'] ?? '=' ) . ' “' . trim( (string) ( $condition['value'] ?? '' ) ) . '”' ) . '</span>';
					}
					echo '</div>';
				}
				if ( $controls ) { echo '<div class="rtsap-flow-item__controls">◆ ' . esc_html__( 'Branch source — controls', 'run-the-seas' ) . ' ' . esc_html( array_sum( array_map( fn( $route ) => count( $route['targets'] ), $controls ) ) ) . ' ' . esc_html__( 'later items', 'run-the-seas' ) . '</div>'; }
				echo '</div></div>';
			}
			echo '</div></article>';
		}
		echo '</section><div class="rtsap-note">' . esc_html__( 'The complete flow includes normal fields, conditionally displayed fields and top-level Fluent Forms content blocks. Page breaks and layout-only container labels are counted as structure, not questions.', 'run-the-seas' ) . '</div></div>';
	}

	private static function render_design_tab( $workspace ) {
		echo '<div class="rtsap-dashboard-grid"><section class="rtsap-panel rtsap-panel--eight"><div class="rtsap-panel__head"><h3>' . esc_html__( 'Design & Branding', 'run-the-seas' ) . '</h3></div><p>' . esc_html__( 'Use Fluent Forms’ native styling and custom CSS screen so the public survey and its preview stay identical.', 'run-the-seas' ) . '</p>';
		if ( $workspace['design_url'] ) { echo '<a class="button button-primary" href="' . esc_url( $workspace['design_url'] ) . '">' . esc_html__( 'Open Fluent Forms Design Settings', 'run-the-seas' ) . ' ↗</a>'; }
		echo '</section><aside class="rtsap-panel rtsap-panel--four"><div class="rtsap-panel__head"><h3>' . esc_html__( 'Brand direction', 'run-the-seas' ) . '</h3></div><div class="rtsap-brand-swatches"><span></span><span></span><span></span></div><p>' . esc_html__( 'Navy, gold, white panels and accessible high-contrast controls match the approved admin wireframe.', 'run-the-seas' ) . '</p></aside></div>';
	}

	private static function render_preview_tab( $workspace ) {
		if ( empty( $workspace['preview_url'] ) ) { echo '<div class="rtsap-empty-state">' . esc_html__( 'Preview is available after this survey is linked to a Fluent Form.', 'run-the-seas' ) . '</div>'; return; }
		echo '<div class="rtsap-preview-head"><p>' . esc_html__( 'Live Fluent Forms preview using the current saved fields, styling and conditional rules.', 'run-the-seas' ) . '</p><a class="button" target="_blank" rel="noopener" href="' . esc_url( $workspace['preview_url'] ) . '">' . esc_html__( 'Open Full Preview', 'run-the-seas' ) . ' ↗</a></div>';
		echo '<iframe class="rtsap-survey-preview" title="' . esc_attr__( 'Survey preview', 'run-the-seas' ) . '" src="' . esc_url( $workspace['preview_url'] ) . '" loading="lazy"></iframe>';
	}

	private static function render_publish_tab( $survey ) {
		$is_live = 'live' === $survey->status;
		echo '<div class="rtsap-dashboard-grid"><section class="rtsap-panel rtsap-panel--eight"><div class="rtsap-panel__head"><div><span class="rtsap-panel__eyebrow">' . esc_html__( 'Current status', 'run-the-seas' ) . '</span><h3>' . esc_html( ucfirst( $survey->status ) ) . '</h3></div><span class="rtsap-badge rtsap-badge--' . esc_attr( $survey->status ) . '">' . esc_html( $is_live ? 'Public' : 'Not public' ) . '</span></div>';
		if ( $is_live ) { echo '<p>' . esc_html__( 'The linked Fluent Form is published. Archiving changes the Fluent Form status to unpublished while keeping all submissions and configuration.', 'run-the-seas' ) . '</p>' . self::form( 'survey_status', '', 'Archive Survey', array( 'id' => $survey->id, 'status' => 'archived', 'return_survey' => $survey->id ) ); }
		else { echo '<p>' . esc_html__( 'Publishing updates both this platform record and the linked Fluent Form, making it available for public rendering.', 'run-the-seas' ) . '</p>' . self::form( 'survey_status', '', 'Publish Survey', array( 'id' => $survey->id, 'status' => 'live', 'return_survey' => $survey->id ) ); }
		echo '</section><aside class="rtsap-panel rtsap-panel--four"><div class="rtsap-panel__head"><h3>' . esc_html__( 'Before publishing', 'run-the-seas' ) . '</h3></div><ul class="rtsap-checklist"><li>' . esc_html__( 'Review required fields', 'run-the-seas' ) . '</li><li>' . esc_html__( 'Check every branch in Logic Map', 'run-the-seas' ) . '</li><li>' . esc_html__( 'Test desktop and mobile preview', 'run-the-seas' ) . '</li></ul></aside></div>';
	}

	public static function handle_clone_survey() { self::guard( 'clone_survey' ); $r = RTS_Business_Logic_2::clone_survey( (int) $_POST['id'], null, self::admin() ); $detail = ! empty( $r['message'] ) ? ' — ' . $r['message'] : ''; self::back( 'rts-surveys', $r['error'] ? 'Error: ' . $r['error'] . $detail : 'Fluent Form cloned (new form #' . (int) ( $r['new_form_id'] ?? 0 ) . ', survey #' . (int) $r['new_survey_id'] . ') — fields, settings and conditional logic preserved.' ); }
	public static function handle_survey_status() {
		self::guard( 'survey_status' );
		$survey_id = (int) $_POST['id'];
		$r = RTS_Business_Logic_2::set_survey_status( $survey_id, sanitize_text_field( $_POST['status'] ), self::admin() );
		$message = $r['error'] ? 'Error: ' . $r['error'] : 'Status updated in Run The Seas and Fluent Forms.';
		if ( ! empty( $_POST['return_survey'] ) ) {
			self::back( 'rts-surveys', $message, array( 'survey_id' => $survey_id, 'tab' => 'publish' ) );
		}
		self::back( 'rts-surveys', $message );
	}

	// ---------- Participant Profile ----------
	public static function render_profile() {
		global $wpdb;
		$id = (int) ( $_GET['id'] ?? 0 );
		$p = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . RTS_DB::table( 'participants' ) . " WHERE id = %d", $id ) );
		echo '<div class="wrap rtsap-participant-profile"><h1>' . esc_html__( 'Participant Profile', 'run-the-seas' ) . '</h1><p class="rtsap-page-subtitle">' . esc_html__( 'Full detail view: registration, verification, credits, referrals and activity', 'run-the-seas' ) . '</p>';
		self::notice();
		if ( ! $p ) { echo '<div class="rtsap-empty-state">' . esc_html__( 'Participant not found.', 'run-the-seas' ) . '</div></div>'; return; }
		if ( ! empty( $p->merged_into_participant_id ) ) {
			$survivor_url = RTSAP_Frontend_Dashboard::screen_url( 'rts-participant-profile', array( 'id' => (int) $p->merged_into_participant_id ) );
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( 'This record was merged into participant #%d and is no longer editable. ', (int) $p->merged_into_participant_id ) ) . '<a href="' . esc_url( $survivor_url ) . '">' . esc_html__( 'Open the surviving participant', 'run-the-seas' ) . '</a></p></div></div>';
			return;
		}

		$refs_table = RTS_DB::table( 'referrals' );
		$participants_table = RTS_DB::table( 'participants' );
		$refs = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.*, rp.name AS referred_name, rp.email AS participant_email
			 FROM $refs_table r
			 LEFT JOIN $participants_table rp ON rp.id = r.referred_participant_id
			 WHERE r.referring_participant_id = %d
			 ORDER BY COALESCE(r.verified_at, r.completed_date, r.referral_date, r.created_at) DESC, r.id DESC",
			$id
		) );
		// The participant profile must show earned trophies, not normalized
		// achievement/medal imports. rts_user_trophies is the authoritative
		// award ledger used by the member Trophy Case and referral engine.
		$troph = $wpdb->get_results( $wpdb->prepare(
			"SELECT trophy_name AS name, trophy_type AS category, trophy_key,
				trophy_image_url, miles_required, earned_date AS unlocked_at,
				split_days, total_days
			 FROM " . RTS_DB::table( 'user_trophies' ) . "
			 WHERE participant_id = %d AND is_displayed = 1
			 ORDER BY earned_date DESC, id DESC",
			$id
		) );
		global $rts_trophy_instance;
		if ( $rts_trophy_instance && method_exists( $rts_trophy_instance, 'get_trophy_image_url' ) ) {
			foreach ( $troph as $earned_trophy ) {
				$current_artwork = $rts_trophy_instance->get_trophy_image_url( $earned_trophy->trophy_key, 'unlocked' );
				if ( $current_artwork ) { $earned_trophy->trophy_image_url = $current_artwork; }
			}
		}
		$credit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . RTS_DB::table( 'cabin_credits' ) . " WHERE participant_id = %d", $id ) );
		$responses = $wpdb->get_results( $wpdb->prepare(
			"SELECT sr.*, s.name AS survey_name, s.source_form_id,
			 st.answered_questions AS tracked_answer_count,
			 (SELECT COUNT(*) FROM " . RTS_DB::table( 'survey_answers' ) . " sa WHERE sa.response_id = sr.id) AS answer_count
			 FROM " . RTS_DB::table( 'survey_responses' ) . " sr
			 LEFT JOIN " . RTS_DB::table( 'surveys' ) . " s ON s.id = sr.survey_id
			 LEFT JOIN " . RTS_DB::table( 'survey_tracking' ) . " st ON st.id = sr.source_tracking_id
			 WHERE sr.participant_id = %d ORDER BY sr.started_at DESC",
			$id
		) );
		$native_answers = $wpdb->get_results( $wpdb->prepare(
			"SELECT sa.*, sr.id AS participant_response_id, s.name AS survey_name
			 FROM " . RTS_DB::table( 'survey_answers' ) . " sa
			 JOIN " . RTS_DB::table( 'survey_responses' ) . " sr ON sr.id = sa.response_id
			 LEFT JOIN " . RTS_DB::table( 'surveys' ) . " s ON s.id = sr.survey_id
			 WHERE sr.participant_id = %d
			 ORDER BY sr.started_at DESC, COALESCE(sa.step_number, 999999), sa.id LIMIT 250",
			$id
		) );
		$native_answers_by_response = array();
		foreach ( $native_answers as $answer ) { $native_answers_by_response[ (int) $answer->participant_response_id ][] = $answer; }
		$answers = array();
		foreach ( $responses as $response ) {
			$response_answers = $native_answers_by_response[ (int) $response->id ] ?? array();
			if ( ! $response_answers ) { $response_answers = self::fluent_submission_answers( $response, $p->email ); }
			$response->answer_count = max( count( $response_answers ), (int) $response->tracked_answer_count );
			$answers = array_merge( $answers, $response_answers );
		}
		$activity = self::participant_activity( $p );
		$activity_per_page = 25;
		$activity_page = isset( $_GET['activity_page'] ) ? max( 1, absint( $_GET['activity_page'] ) ) : 1;
		$activity_total = count( $activity );
		$activity_total_pages = max( 1, (int) ceil( $activity_total / $activity_per_page ) );
		if ( $activity_page > $activity_total_pages ) { $activity_page = $activity_total_pages; }
		$activity_page_rows = array_slice( $activity, ( $activity_page - 1 ) * $activity_per_page, $activity_per_page );
		$notes = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . RTS_DB::table( 'participant_notes' ) . " WHERE participant_id = %d ORDER BY created_at DESC, id DESC",
			$id
		) );
		$tracking_location = null;
		if ( ! empty( $p->survey_tracking_id ) ) {
			$tracking_location = $wpdb->get_row( $wpdb->prepare(
				"SELECT country, city, region, location_source, location_accuracy FROM " . RTS_DB::table( 'survey_tracking' ) . " WHERE id = %d",
				(int) $p->survey_tracking_id
			) );
		}

		$name = trim( (string) ( $p->name ?: trim( (string) $p->first_name . ' ' . (string) $p->last_name ) ) );
		if ( '' === $name ) { $name = 'Participant #' . $id; }
		$name_parts = preg_split( '/\s+/', $name );
		$initials = strtoupper( substr( $name_parts[0], 0, 1 ) . ( count( $name_parts ) > 1 ? substr( end( $name_parts ), 0, 1 ) : '' ) );
		$registered_raw = $p->registered_at ?: ( $p->registration_date ?: $p->created_at );
		$registered = $registered_raw ? date_i18n( get_option( 'date_format' ), strtotime( $registered_raw ) ) : '—';
		$location = implode( ', ', array_filter( array( $p->city, $p->registration_province, $p->country ) ) );
		$status = $p->email_verified ? 'Verified' : 'Pending';
		$status_class = $p->email_verified ? 'is-verified' : 'is-pending';
		$participant_user = $p->user_id ? get_user_by( 'id', (int) $p->user_id ) : get_user_by( 'email', $p->email );
		$merge_records = current_user_can( 'rts_manage' ) ? self::merge_group_records( $p ) : array();
		$tab = sanitize_key( $_GET['profile_tab'] ?? 'overview' );
		$tabs = array(
			'overview' => 'Overview', 'survey-response' => 'Survey Response', 'referrals' => 'Referrals',
			'cabin-credit' => 'Cabin Credit', 'trophies' => 'Trophies', 'activity-log' => 'Activity Log', 'admin-notes' => 'Admin Notes',
		);
		// $tabs = array(
		// 	'overview' => 'Overview', 'survey-response' => 'Survey Response', 'referrals' => 'Referrals',			
		// );
		if ( ! isset( $tabs[ $tab ] ) ) { $tab = 'overview'; }

		echo '<div class="rtsap-profile-identity"><span class="rtsap-profile-avatar">' . esc_html( $initials ) . '</span><div><div class="rtsap-profile-name"><h2>' . esc_html( $name ) . '</h2><span class="rtsap-directory-badge ' . esc_attr( $status_class ) . '">' . esc_html( $status ) . '</span>';
		if ( 'active' !== ( $p->account_status ?: 'active' ) ) { echo '<span class="rtsap-directory-badge is-suspended">' . esc_html( ucfirst( $p->account_status ) ) . '</span>'; }
		echo '</div><p>' . esc_html( sprintf( 'Founding Runner %s · Registered %s%s', $p->founding_runner_number ?: '—', $registered, $location ? ' · ' . $location : '' ) ) . '</p></div></div>';

		echo '<nav class="rtsap-tabs rtsap-profile-tabs" aria-label="' . esc_attr__( 'Participant profile sections', 'run-the-seas' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$url = RTSAP_Frontend_Dashboard::screen_url( 'rts-participant-profile', array( 'id' => $id, 'profile_tab' => $key ) );
			echo '<a class="rtsap-tab ' . ( $tab === $key ? 'is-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav><div class="rtsap-profile-tab-panel">';

		if ( 'overview' === $tab ) {
			echo '<div class="rtsap-profile-overview"><section class="rtsap-panel rtsap-profile-summary"><div class="rtsap-panel__head"><div><h3>' . esc_html__( 'Profile Summary', 'run-the-seas' ) . '</h3><span>' . esc_html__( 'Changes are written to the participant Activity Log with your administrator name.', 'run-the-seas' ) . '</span></div></div>';
			if ( current_user_can( 'rts_manage' ) ) {
				$stored_runner_status = strtolower( str_replace( array( '-', ' ' ), '_', trim( (string) $p->runner_status ) ) );
				if ( in_array( $stored_runner_status, array( 'runner', 'yes', 'true', '1' ), true ) ) {
					$runner_value = 'runner';
				} elseif ( in_array( $stored_runner_status, array( 'non_runner', 'nonrunner', 'no', 'false', '0' ), true ) ) {
					$runner_value = 'non_runner';
				} else {
					$runner_value = 'not_specified';
				}
				$is_suspended = 'suspended' === strtolower( (string) $p->account_status );
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rtsap-profile-edit-form">'
					. '<input type="hidden" name="action" value="rts_participant_edit">' . wp_nonce_field( 'rts_participant_edit', '_rts_nonce', true, false )
					. '<input type="hidden" name="id" value="' . (int) $id . '"><label><span>' . esc_html__( 'Email', 'run-the-seas' ) . '</span><input type="email" name="email" value="' . esc_attr( $p->email ) . '" required></label>'
					. '<label><span>' . esc_html__( 'Runner Status', 'run-the-seas' ) . '</span><select name="runner_status"><option value="not_specified"' . selected( $runner_value, 'not_specified', false ) . '>' . esc_html__( 'Not specified', 'run-the-seas' ) . '</option><option value="runner"' . selected( $runner_value, 'runner', false ) . '>' . esc_html__( 'Runner', 'run-the-seas' ) . '</option><option value="non_runner"' . selected( $runner_value, 'non_runner', false ) . '>' . esc_html__( 'Non-runner', 'run-the-seas' ) . '</option></select></label>'
					. '<label><span>' . esc_html__( 'Marketing Source', 'run-the-seas' ) . '</span><input type="text" name="marketing_source" maxlength="50" value="' . esc_attr( $p->marketing_source ) . '" placeholder="Not recorded"></label>'
					. '<label class="rtsap-profile-status-field"><span>' . esc_html__( 'Account Status', 'run-the-seas' ) . '</span><input type="hidden" name="account_status" value="active"><span class="rtsap-profile-switch"><input type="checkbox" name="account_status" value="suspended"' . checked( $is_suspended, true, false ) . '><i></i><b>' . esc_html( $is_suspended ? 'Suspended' : 'Active' ) . '</b></span></label>'
					. '<div class="rtsap-profile-edit-submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save Profile', 'run-the-seas' ) . '</button></div></form>';
			} else {
				echo '<div class="rtsap-profile-fields">';
				foreach ( array( 'Email' => $p->email, 'Runner Status' => $p->runner_status, 'Marketing Source' => $p->marketing_source, 'Account Status' => $p->account_status ) as $label => $value ) { echo '<div class="rtsap-profile-field"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ?: 'Not recorded' ) . '</strong></div>'; }
				echo '</div>';
			}
			echo '</section><section class="rtsap-panel rtsap-profile-credit"><div class="rtsap-panel__head"><h3>' . esc_html__( 'Cabin Credit', 'run-the-seas' ) . '</h3></div><div class="rtsap-profile-kpis">';
			$credit_status = $credit ? ucfirst( $credit->status ) : 'Not issued';
			$redemption = $credit && 'redeemed' === strtolower( $credit->status ) ? 'Redeemed' : ( $credit ? 'Not yet redeemed' : 'Not available' );
			$value = $credit ? '$' . number_format_i18n( (float) $credit->value_usd, 0 ) . ' USD' : '—';
			foreach ( array( 'Credit Status' => $credit_status, 'Redemption' => $redemption, 'Value' => $value ) as $label => $value ) { echo '<div class="rtsap-profile-kpi"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>'; }
			echo '</div></section></div>';

			$registration_fields = array(
				'first_name' => array( 'First Name', 'text' ),
				'last_name' => array( 'Last Name', 'text' ),
				'phone' => array( 'Mobile Phone', 'text' ),
				'address' => array( 'Address Line 1', 'textarea' ),
				'address_2' => array( 'Address Line 2', 'text' ),
				'city' => array( 'City', 'text' ),
				'registration_province' => array( 'State / Province', 'text' ),
				'postal_code' => array( 'ZIP / Postal Code', 'text' ),
				'registration_country' => array( 'Country Entered at Registration', 'text' ),
				'detected_country' => array( 'Country Detected During Survey', 'text' ),
				'country' => array( 'Country Used on Leaderboards & Reports', 'text' ),
				'gender' => array( 'Gender', 'text' ),
				'age_range' => array( 'Age Range', 'text' ),
			);
			$age_policy_confirmed = ! empty( $p->age_consent_confirmed_at );
			$age_policy_label = $age_policy_confirmed
				? sprintf( 'Accepted on %s', date_i18n( get_option( 'date_format' ), strtotime( $p->age_consent_confirmed_at ) ) )
				: 'Not recorded';
			$source_text = $tracking_location && $tracking_location->location_source
				? ucwords( str_replace( '_', ' ', $tracking_location->location_source ) )
				: 'Not detected';
			if ( $tracking_location && $tracking_location->location_accuracy ) { $source_text .= ' · ±' . number_format_i18n( (float) $tracking_location->location_accuracy, 0 ) . 'm'; }
			echo '<section class="rtsap-panel rtsap-registration-details"><div class="rtsap-panel__head"><div><h3>' . esc_html__( 'Registration & Location Details', 'run-the-seas' ) . '</h3><span>'
				. esc_html__( 'Detected country is preferred automatically; registration country is the fallback. Staff can correct the country used across leaderboards and reports, then mark it verified.', 'run-the-seas' )
				. '</span></div><span class="rtsap-location-source">' . esc_html( 'Detection: ' . $source_text ) . '</span></div>';
			if ( current_user_can( 'rts_manage' ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rtsap-profile-edit-form rtsap-registration-edit-form">'
					. '<input type="hidden" name="action" value="rts_participant_edit">' . wp_nonce_field( 'rts_participant_edit', '_rts_nonce', true, false )
					. '<input type="hidden" name="id" value="' . (int) $id . '">';
				foreach ( $registration_fields as $field => $config ) {
					$value = (string) ( $p->$field ?? '' );
					$wide_class = 'address' === $field ? ' class="rtsap-field-wide"' : '';
					echo '<label' . $wide_class . '><span>' . esc_html( $config[0] ) . '</span>';
					if ( 'textarea' === $config[1] ) {
						echo '<textarea name="' . esc_attr( $field ) . '" rows="2">' . esc_textarea( $value ) . '</textarea>';
					} else {
						echo '<input type="' . esc_attr( $config[1] ) . '" name="' . esc_attr( $field ) . '" value="' . esc_attr( $value ) . '">';
					}
					echo '</label>';
				}
					echo '<label class="rtsap-profile-check-field"><span>' . esc_html__( 'Marketing Consent', 'run-the-seas' ) . '</span><input type="hidden" name="marketing_consent" value="0"><span><input type="checkbox" name="marketing_consent" value="1"' . checked( ! empty( $p->marketing_consent ), true, false ) . '> ' . esc_html__( 'Participant opted in', 'run-the-seas' ) . '</span></label>'
					. '<label class="rtsap-profile-check-field"><span>' . esc_html__( 'Age & Policy Confirmation', 'run-the-seas' ) . '</span><span><input type="checkbox" disabled' . checked( $age_policy_confirmed, true, false ) . '> ' . esc_html( $age_policy_label ) . '</span></label>'
					. '<label class="rtsap-profile-check-field"><span>' . esc_html__( 'Country Verification', 'run-the-seas' ) . '</span><input type="hidden" name="country_verified" value="0"><span><input type="checkbox" name="country_verified" value="1"' . checked( ! empty( $p->country_verified ), true, false ) . '> ' . esc_html__( 'Country reviewed and verified', 'run-the-seas' ) . '</span></label>'
					. '<div class="rtsap-profile-edit-submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save Registration Details', 'run-the-seas' ) . '</button></div></form>';
			} else {
				echo '<div class="rtsap-profile-fields rtsap-registration-view-fields">';
				foreach ( $registration_fields as $field => $config ) {
					$value = (string) ( $p->$field ?? '' );
					echo '<div class="rtsap-profile-field"><span>' . esc_html( $config[0] ) . '</span><strong>' . esc_html( '' !== $value ? $value : 'Not recorded' ) . '</strong></div>';
				}
				foreach ( array( 'Marketing Consent' => ! empty( $p->marketing_consent ) ? 'Yes' : 'No', 'Age & Policy Confirmation' => $age_policy_label, 'Country Verified' => ! empty( $p->country_verified ) ? 'Yes' : 'No' ) as $label => $value ) {
					echo '<div class="rtsap-profile-field"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
				}
				echo '</div>';
			}
			echo '</section>';

			$activity_counts = array();
			foreach ( array_reverse( $refs ) as $ref ) {
				$ref_date = $ref->verified_at ?: ( $ref->completed_date ?: ( $ref->referral_date ?: $ref->created_at ) );
				if ( ! $ref_date ) { continue; }
				$key = date( 'Y-m-d', strtotime( $ref_date ) );
				$activity_counts[ $key ] = ( $activity_counts[ $key ] ?? 0 ) + 1;
			}
			$activity_counts = array_slice( $activity_counts, -10, 10, true );
			$max_referrals = $activity_counts ? max( $activity_counts ) : 1;
			echo '<section class="rtsap-panel rtsap-referral-activity"><div class="rtsap-panel__head"><h3>' . esc_html__( 'Referral Activity', 'run-the-seas' ) . '</h3></div>';
			if ( $activity_counts ) {
				echo '<div class="rtsap-referral-chart" aria-label="' . esc_attr__( 'Referrals over time', 'run-the-seas' ) . '">';
				foreach ( $activity_counts as $date => $count ) { $height = max( 18, round( $count / $max_referrals * 100 ) ); echo '<span style="--rtsap-bar-height:' . (int) $height . '%" title="' . esc_attr( date_i18n( get_option( 'date_format' ), strtotime( $date ) ) . ': ' . $count ) . '"><i></i><small>' . esc_html( date_i18n( 'M j', strtotime( $date ) ) ) . '</small></span>'; }
				echo '</div><p class="rtsap-chart-caption">' . esc_html__( 'Referrals over time', 'run-the-seas' ) . '</p>';
			} else { echo '<div class="rtsap-empty-state">' . esc_html__( 'No referral activity has been recorded.', 'run-the-seas' ) . '</div>'; }
			self::render_referral_table( $refs );
			echo '</section>';
		} elseif ( 'survey-response' === $tab ) {
			echo '<section class="rtsap-panel"><div class="rtsap-panel__head"><div><h3>' . esc_html__( 'Survey Responses', 'run-the-seas' ) . '</h3><span>' . esc_html( count( $responses ) . ' submission(s)' ) . '</span></div></div>';
			if ( ! $responses ) { echo '<div class="rtsap-empty-state">' . esc_html__( 'No linked survey responses were found.', 'run-the-seas' ) . '</div>'; }
			else {
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Survey', 'run-the-seas' ) . '</th><th>' . esc_html__( 'Status', 'run-the-seas' ) . '</th><th>' . esc_html__( 'Answers', 'run-the-seas' ) . '</th><th>' . esc_html__( 'Started', 'run-the-seas' ) . '</th><th>' . esc_html__( 'Completed', 'run-the-seas' ) . '</th></tr></thead><tbody>';
				foreach ( $responses as $response ) { echo '<tr><td>' . esc_html( $response->survey_name ?: 'Survey #' . $response->survey_id ) . '</td><td>' . esc_html( ucfirst( str_replace( '_', ' ', $response->status ) ) ) . '</td><td>' . (int) $response->answer_count . '</td><td>' . esc_html( $response->started_at ?: '—' ) . '</td><td>' . esc_html( $response->completed_at ?: '—' ) . '</td></tr>'; }
				echo '</tbody></table>';
				if ( $answers ) { echo '<h3 class="rtsap-profile-subheading">' . esc_html__( 'Question-level answers', 'run-the-seas' ) . '</h3><table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Question', 'run-the-seas' ) . '</th><th>' . esc_html__( 'Answer', 'run-the-seas' ) . '</th><th>' . esc_html__( 'Survey', 'run-the-seas' ) . '</th></tr></thead><tbody>'; $answer_number = 0; foreach ( $answers as $answer ) { $answer_number++; $answer_text = $answer->answer_label ?: $answer->answer_value; echo '<tr><td>' . (int) $answer_number . '</td><td>' . esc_html( $answer->question_label ?: $answer->question_id ) . '</td><td>' . esc_html( $answer_text ?: '—' ) . '</td><td>' . esc_html( $answer->survey_name ?: 'Survey response #' . $answer->participant_response_id ) . '</td></tr>'; } echo '</tbody></table>'; }
			}
			echo '</section>';
		} elseif ( 'referrals' === $tab ) {
			echo '<section class="rtsap-panel"><div class="rtsap-panel__head"><div><h3>' . esc_html__( 'Referrals', 'run-the-seas' ) . '</h3><span>' . esc_html( count( $refs ) . ' referral(s)' ) . '</span></div></div>'; self::render_referral_table( $refs, true ); echo '</section>';
		} elseif ( 'cabin-credit' === $tab ) {
			echo '<section class="rtsap-panel"><div class="rtsap-panel__head"><h3>' . esc_html__( 'Cabin Credit', 'run-the-seas' ) . '</h3></div>';
			if ( ! $credit ) { echo '<div class="rtsap-empty-state">' . esc_html__( 'No cabin credit has been issued to this participant.', 'run-the-seas' ) . '</div>'; }
			else { echo '<div class="rtsap-profile-kpis rtsap-profile-kpis--wide">'; foreach ( array( 'Status' => ucfirst( $credit->status ), 'Value' => '$' . number_format_i18n( (float) $credit->value_usd, 2 ) . ' USD', 'Issued' => $credit->issued_at ?: '—', 'Reservation' => $credit->cabin_reservation_id ?: 'Not linked' ) as $label => $value ) { echo '<div class="rtsap-profile-kpi"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>'; } echo '</div>'; }
			echo '</section>';
		} elseif ( 'trophies' === $tab ) {
			echo '<section class="rtsap-panel"><div class="rtsap-panel__head"><div><h3>' . esc_html__( 'Trophies', 'run-the-seas' ) . '</h3><span>' . esc_html( count( $troph ) . ' unlocked' ) . '</span></div></div>';
			if ( ! $troph ) { echo '<div class="rtsap-empty-state">' . esc_html__( 'No trophies have been unlocked.', 'run-the-seas' ) . '</div>'; } else { echo '<div class="rtsap-trophy-list">'; foreach ( $troph as $trophy ) {
				$is_founding = in_array( $trophy->trophy_key, array( 'founding-runner', 'founder', 'founding-runner-trophy' ), true );
				$is_marathon_two = str_starts_with( (string) $trophy->trophy_key, 'm2-' );
				$description = $is_founding
					? __( 'Survey completed, registration and age confirmation recorded, and email verified.', 'run-the-seas' )
					: sprintf(
						__( '%1$s milestone at %2$s Captain\'s Miles.', 'run-the-seas' ),
						$is_marathon_two ? __( 'Marathon 2', 'run-the-seas' ) : __( 'Marathon 1', 'run-the-seas' ),
						number_format_i18n( (int) $trophy->miles_required )
					);
				$art = $trophy->trophy_image_url
					? '<img class="rtsap-trophy-art" src="' . esc_url( $trophy->trophy_image_url ) . '" alt="">'
					: '<span class="dashicons dashicons-awards" aria-hidden="true"></span>';
				echo '<article>' . $art . '<div><h4>' . esc_html( $trophy->name ) . '</h4><p>' . esc_html( $description ) . '</p><small>' . esc_html( 'Unlocked ' . ( $trophy->unlocked_at ?: '—' ) ) . '</small></div></article>';
			} echo '</div>'; }
			echo '</section>';
		} elseif ( 'activity-log' === $tab ) {
			echo '<section class="rtsap-panel"><div class="rtsap-panel__head"><div><h3>' . esc_html__( 'Activity Log', 'run-the-seas' ) . '</h3><span>' . esc_html( sprintf( '%s events · registration, verification, referrals, trophies and administrator changes.', number_format_i18n( $activity_total ) ) ) . '</span></div></div>';
			if ( ! $activity ) { echo '<div class="rtsap-empty-state">' . esc_html__( 'No activity has been recorded for this participant.', 'run-the-seas' ) . '</div>'; }
			else {
				echo '<table class="widefat striped rtsap-participant-activity"><thead><tr><th>' . esc_html__( 'Date', 'run-the-seas' ) . '</th><th>' . esc_html__( 'Actor', 'run-the-seas' ) . '</th><th>' . esc_html__( 'Event', 'run-the-seas' ) . '</th><th>' . esc_html__( 'Source', 'run-the-seas' ) . '</th><th>' . esc_html__( 'Details', 'run-the-seas' ) . '</th></tr></thead><tbody>';
				foreach ( $activity_page_rows as $entry ) { echo '<tr><td>' . esc_html( $entry->date ) . '</td><td>' . esc_html( $entry->actor ) . '</td><td><b>' . esc_html( $entry->event ) . '</b></td><td><span class="rtsap-activity-source">' . esc_html( $entry->source ) . '</span></td><td>' . esc_html( $entry->details ?: '—' ) . '</td></tr>'; }
				echo '</tbody></table>' . self::activity_pagination( $id, $activity_page, $activity_total_pages, $activity_total );
			}
			echo '</section>';
		} elseif ( 'admin-notes' === $tab ) {
			echo '<section class="rtsap-panel rtsap-admin-notes"><div class="rtsap-panel__head"><div><h3>' . esc_html__( 'Admin Notes', 'run-the-seas' ) . '</h3><span>' . esc_html__( 'Internal support context. These notes are never visible to the participant.', 'run-the-seas' ) . '</span></div></div>';
			if ( current_user_can( 'rts_manage' ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rtsap-admin-note-form"><input type="hidden" name="action" value="rts_participant_note">' . wp_nonce_field( 'rts_participant_note', '_rts_nonce', true, false ) . '<input type="hidden" name="id" value="' . (int) $id . '"><textarea name="note_text" rows="4" maxlength="5000" placeholder="Add an internal note…" required></textarea><button class="button button-primary" type="submit">' . esc_html__( 'Add Note', 'run-the-seas' ) . '</button></form>';
			}
			if ( ! $notes ) { echo '<div class="rtsap-empty-state">' . esc_html__( 'No internal notes have been added.', 'run-the-seas' ) . '</div>'; }
			else { echo '<div class="rtsap-admin-note-list">'; foreach ( $notes as $note ) { echo '<article><p>' . nl2br( esc_html( $note->note_text ) ) . '</p><small>' . esc_html( $note->admin_name . ' · ' . $note->created_at ) . '</small></article>'; } echo '</div>'; }
			echo '</section>';
		}

		echo '</div>';

		// Preview is stashed in a short-lived transient keyed to the current user — NOT passed
		// through the URL. (A first attempt JSON-encoded it into a query arg; add_query_arg mangled
		// the empty-array brackets and the page fatal-errored on count(null). Transients are the
		// WordPress-idiomatic way to hand state between a POST handler and the redirect target.)
		$pv_key = 'rts_merge_preview_' . get_current_user_id();
		if ( isset( $_GET['merge_preview'] ) && ( $pv = get_transient( $pv_key ) ) ) {
			delete_transient( $pv_key );
			$source_type = $pv['source_type'] ?? 'participant'; $source_id = (int) $pv['source_id']; $pv = $pv['preview'];
			if ( 'tracking' === $source_type ) {
				$source = $pv['source_tracking'];
				echo '<section class="rtsap-panel rtsap-merge-preview"><div class="rtsap-panel__head"><div><h3>' . esc_html__( 'Attach Survey Record', 'run-the-seas' ) . '</h3><span>' . esc_html__( 'This completed form has no registered account. It will be attached to the selected participant.', 'run-the-seas' ) . '</span></div></div>'
					. '<div class="rtsap-survey-merge-summary"><div><span>' . esc_html__( 'Surviving participant', 'run-the-seas' ) . '</span><b>' . esc_html( ( $pv['keep']->name ?: $pv['keep']->email ) . ' · ' . $pv['keep']->email ) . '</b></div><div><span>' . esc_html__( 'Survey-only record', 'run-the-seas' ) . '</span><b>' . esc_html( ( $source->email ?: 'Email not captured' ) . ' · ' . ucfirst( $source->completion_status ) ) . '</b></div><div><span>' . esc_html__( 'Survey content', 'run-the-seas' ) . '</span><b>' . esc_html( (int) $pv['answer_count'] . ' answers · Form ' . (int) $source->form_id ) . '</b></div><div><span>' . esc_html__( 'Session', 'run-the-seas' ) . '</span><code>' . esc_html( $source->session_id ) . '</code></div></div>'
					. self::form( 'merge', '<input type="hidden" name="mode" value="commit"><input type="hidden" name="source_type" value="tracking"><input type="hidden" name="source_id" value="' . (int) $source_id . '">', 'Confirm — Attach Survey Record', array( 'keep_id' => $id ) ) . '</section>';
			} else {
			$mid = $source_id;
			$field_labels = array( 'email' => 'Email', 'name' => 'Display Name', 'first_name' => 'First Name', 'last_name' => 'Last Name', 'phone' => 'Phone', 'runner_status' => 'Runner Status', 'marketing_source' => 'Marketing Source', 'country' => 'Country Used Across Site', 'registration_country' => 'Registration Country', 'detected_country' => 'Survey-detected Country', 'province' => 'Province', 'registration_province' => 'Registration State / Province', 'city' => 'City', 'postal_code' => 'ZIP / Postal Code', 'address' => 'Address Line 1', 'address_2' => 'Address Line 2', 'date_of_birth' => 'Date of Birth', 'gender' => 'Gender', 'age_range' => 'Age Range', 'travel_party_size' => 'Travel Party Size', 'emergency_contact_name' => 'Emergency Contact Name', 'emergency_contact_phone' => 'Emergency Contact Phone', 'marketing_consent' => 'Marketing Consent' );
			echo '<section class="rtsap-panel rtsap-merge-preview"><div class="rtsap-panel__head"><div><h3>' . esc_html__( 'Merge Duplicate Comparison', 'run-the-seas' ) . '</h3><span>' . esc_html__( 'Choose the surviving value for each field, then commit the merge.', 'run-the-seas' ) . '</span></div></div><ul>'
			   . '<li>Reassign ' . (int) $pv['referrals_to_reassign'] . ' referral(s) to this record</li>'
			   . '<li>Merge ' . count( (array) $pv['trophies_to_merge'] ) . ' trophy unlock(s)</li>'
			   . '<li>' . ( $pv['credit_conflict'] ? 'Both records have a Cabin Credit — the duplicate\'s credit will be cancelled.' : 'No Cabin Credit conflict.' ) . '</li></ul>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rts_merge">' . wp_nonce_field( 'rts_merge', '_rts_nonce', true, false ) . '<input type="hidden" name="mode" value="commit"><input type="hidden" name="keep_id" value="' . (int) $id . '"><input type="hidden" name="merge_id" value="' . (int) $mid . '"><table class="widefat striped rtsap-merge-table"><thead><tr><th>' . esc_html__( 'Field', 'run-the-seas' ) . '</th><th>' . esc_html( 'Keep #' . $id ) . '</th><th>' . esc_html( 'Duplicate #' . $mid ) . '</th></tr></thead><tbody>';
			foreach ( (array) $pv['merge_fields'] as $field ) {
				$keep_value = (string) ( $pv['keep']->$field ?? '' ); $merge_value = (string) ( $pv['merge']->$field ?? '' );
				$default = '' === $keep_value && '' !== $merge_value ? 'merge' : 'keep';
				echo '<tr><th>' . esc_html( $field_labels[ $field ] ?? ucwords( str_replace( '_', ' ', $field ) ) ) . '</th><td><label><input type="radio" name="field_sources[' . esc_attr( $field ) . ']" value="keep"' . checked( $default, 'keep', false ) . '> ' . esc_html( '' === $keep_value ? '—' : $keep_value ) . '</label></td><td><label><input type="radio" name="field_sources[' . esc_attr( $field ) . ']" value="merge"' . checked( $default, 'merge', false ) . '> ' . esc_html( '' === $merge_value ? '—' : $merge_value ) . '</label></td></tr>';
			}
			echo '</tbody></table><button type="submit" class="button button-primary rtsap-merge-commit">' . esc_html__( 'Confirm and Commit Merge', 'run-the-seas' ) . '</button></form></section>';
			}
		}
		if ( current_user_can( 'rts_manage' ) ) {
			echo '<div class="rtsap-profile-actions">';
			if ( $participant_user ) { echo '<details><summary class="button">' . esc_html__( 'Reset Passcode', 'run-the-seas' ) . '</summary><div class="rtsap-profile-action-popover"><p>' . esc_html__( 'Send the participant a secure WordPress passcode reset email.', 'run-the-seas' ) . '</p>' . self::form( 'reset_passcode', '', 'Send Reset Email', array( 'id' => $id ) ) . '</div></details>'; }
			if ( $p->email ) { echo '<details><summary class="button">' . esc_html__( 'Send Email', 'run-the-seas' ) . '</summary><div class="rtsap-profile-action-popover rtsap-email-popover"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rts_participant_email">' . wp_nonce_field( 'rts_participant_email', '_rts_nonce', true, false ) . '<input type="hidden" name="id" value="' . (int) $id . '"><label>' . esc_html__( 'Subject', 'run-the-seas' ) . '<input type="text" name="subject" maxlength="255" required></label><label>' . esc_html__( 'Message', 'run-the-seas' ) . '<textarea name="message" rows="6" required></textarea></label><button class="button button-primary" type="submit">' . esc_html__( 'Send Email', 'run-the-seas' ) . '</button></form></div></details>'; }
			if ( ! $p->email_verified ) { echo '<details><summary class="button">' . esc_html__( 'Verify Email', 'run-the-seas' ) . '</summary><div class="rtsap-profile-action-popover">' . self::form( 'manual_verify', '<input type="text" name="reason" placeholder="Reason for manual verification" required> ', 'Confirm Verification', array( 'id' => $id, 'return_profile' => 1 ) ) . '</div></details>'; }
			if ( 'active' !== ( $p->account_status ?: 'active' ) ) { echo self::form( 'reinstate', '', 'Reinstate Account', array( 'id' => $id ) ); }
			else { echo '<details><summary class="button rtsap-danger-button">' . esc_html__( 'Suspend Account', 'run-the-seas' ) . '</summary><div class="rtsap-profile-action-popover">' . self::form( 'suspend', '<input type="text" name="reason" placeholder="Reason for suspension" required> ', 'Confirm Suspension', array( 'id' => $id ) ) . '</div></details>'; }
			if ( count( $merge_records ) > 1 ) { echo self::merge_candidate_control( $merge_records, $id, ! empty( $_GET['merge_group'] ) ); }
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Build one participant history from the same audit ledger used by Security,
	 * supplemented by source rows that have not yet been mapped into that ledger.
	 */
	private static function participant_activity( $participant ) {
		global $wpdb;
		$id = (int) $participant->id;
		$audit_table = RTS_DB::table( 'audit_log' );
		$timeline_table = RTS_DB::table( 'timeline' );
		$participant_pattern = 'participant_id=' . $id . '([^0-9]|$)';
		$email_like = '%' . $wpdb->esc_like( (string) $participant->email ) . '%';
		$audit_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT a.* FROM $audit_table a
			 LEFT JOIN $timeline_table t ON a.source_table = 'timeline' AND a.source_id = t.id
			 WHERE t.participant_id = %d OR a.notes REGEXP %s OR (a.notes LIKE %s AND %s <> '')
			 ORDER BY a.created_at DESC, a.id DESC",
			$id, $participant_pattern, $email_like, (string) $participant->email
		) );
		$events = array();
		$mapped_timeline_ids = array();
		foreach ( $audit_rows as $row ) {
			if ( 'timeline' === $row->source_table ) { $mapped_timeline_ids[ (int) $row->source_id ] = true; }
			$events[] = (object) array(
				'date' => $row->created_at,
				'actor' => $row->user ?: 'system',
				'event' => ucwords( str_replace( '_', ' ', (string) $row->action ) ),
				'source' => $row->module ?: ( $row->source_table ?: 'Audit Log' ),
				'details' => $row->notes,
			);
		}

		$timeline_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $timeline_table WHERE participant_id = %d ORDER BY COALESCE(activity_date, created_at) DESC, id DESC",
			$id
		) );
		foreach ( $timeline_rows as $row ) {
			if ( isset( $mapped_timeline_ids[ (int) $row->id ] ) ) { continue; }
			$events[] = (object) array(
				'date' => $row->activity_date ?: $row->created_at,
				'actor' => 'system',
				'event' => ucwords( str_replace( '_', ' ', (string) $row->activity_type ) ),
				'source' => 'Participant Timeline',
				'details' => $row->activity_description,
			);
		}

		$referrals = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, referred_email, referred_participant_id, status, verified, COALESCE(verified_at, completed_date, referral_date, created_at) AS event_date
			 FROM " . RTS_DB::table( 'referrals' ) . " WHERE referring_participant_id = %d OR referrer_id = %d ORDER BY event_date DESC, id DESC",
			$id, $id
		) );
		foreach ( $referrals as $row ) {
			$events[] = (object) array(
				'date' => $row->event_date,
				'actor' => 'system',
				'event' => $row->verified ? 'Referral Verified' : 'Referral Recorded',
				'source' => 'Referrals',
				'details' => ( $row->referred_email ?: 'Participant #' . (int) $row->referred_participant_id ) . ' · ' . ( $row->status ?: ( $row->verified ? 'verified' : 'pending' ) ),
			);
		}

		$trophies = $wpdb->get_results( $wpdb->prepare(
			"SELECT trophy_name, trophy_key, earned_date FROM " . RTS_DB::table( 'user_trophies' ) . " WHERE participant_id = %d ORDER BY earned_date DESC, id DESC",
			$id
		) );
		foreach ( $trophies as $row ) {
			$events[] = (object) array( 'date' => $row->earned_date, 'actor' => 'system', 'event' => 'Trophy Earned', 'source' => 'Trophies', 'details' => $row->trophy_name . ' (' . $row->trophy_key . ')' );
		}

		$has_registration = false;
		foreach ( $events as $event ) { if ( false !== stripos( $event->event, 'registr' ) ) { $has_registration = true; break; } }
		if ( ! $has_registration ) {
			$registered = $participant->registered_at ?: ( $participant->registration_date ?: $participant->created_at );
			if ( $registered ) { $events[] = (object) array( 'date' => $registered, 'actor' => 'participant', 'event' => 'Registration Completed', 'source' => 'Participants', 'details' => 'Participant record created.' ); }
		}
		usort( $events, static function ( $a, $b ) { return strcmp( (string) $b->date, (string) $a->date ); } );
		return $events;
	}

	private static function activity_pagination( $participant_id, $current_page, $total_pages, $total_items ) {
		if ( $total_pages <= 1 ) { return ''; }
		$base = RTSAP_Frontend_Dashboard::screen_url( 'rts-participant-profile', array(
			'id' => (int) $participant_id, 'profile_tab' => 'activity-log', 'activity_page' => '%#%',
		) );
		$links = paginate_links( array(
			'base' => $base, 'format' => '', 'current' => $current_page, 'total' => $total_pages,
			'prev_text' => '&laquo;', 'next_text' => '&raquo;', 'type' => 'array', 'end_size' => 1, 'mid_size' => 2,
		) );
		if ( ! $links ) { return ''; }
		$html = '<div class="rts-pagination"><span class="rts-pagination-count">' . esc_html( number_format_i18n( $total_items ) . ' events' ) . '</span><div class="rts-pagination-links">';
		foreach ( $links as $link ) { $html .= $link; }
		return $html . '</div></div>';
	}

	private static function merge_group_records( $participant ) {
		global $wpdb;
		if ( ! $participant->survey_tracking_id ) { return array(); }
		$tracking_table = RTS_DB::table( 'survey_tracking' ); $participants_table = RTS_DB::table( 'participants' );
		$links_table = RTS_DB::table( 'participant_survey_links' );
		$primary = $wpdb->get_row( $wpdb->prepare( "SELECT session_id, form_id FROM $tracking_table WHERE id = %d", $participant->survey_tracking_id ) );
		if ( ! $primary || ! $primary->session_id ) { return array(); }
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT st.*, p.id AS participant_id, p.name AS participant_name, p.first_name, p.last_name,
				p.email AS participant_email, p.email_verified, p.merged_into_participant_id,
				psl.participant_id AS linked_participant_id
			 FROM $tracking_table st
			 LEFT JOIN $participants_table p ON p.survey_tracking_id = st.id
			 LEFT JOIN $links_table psl ON psl.tracking_id = st.id
			 WHERE st.session_id = %s AND st.form_id = %d
			 ORDER BY st.started_at ASC, st.id ASC",
			$primary->session_id, $primary->form_id
		) );
	}

	private static function merge_candidate_control( $records, $current_participant_id, $open = false ) {
		$survivors = array(); $sources = array();
		foreach ( $records as $record ) {
			if ( $record->participant_id && ! $record->merged_into_participant_id ) {
				$name = trim( (string) $record->participant_name ) ?: trim( (string) $record->first_name . ' ' . (string) $record->last_name );
				$label = 'Registered: ' . ( $name ?: $record->participant_email ) . ' — ' . $record->participant_email;
				$survivors[ (int) $record->participant_id ] = $label;
				$sources[ 'participant:' . (int) $record->participant_id ] = $label;
			} elseif ( ! $record->linked_participant_id && ! $record->participant_id && ( 'completed' === $record->completion_status || (int) $record->answered_questions > 0 ) ) {
				$label = 'Survey only: ' . ( $record->email ?: 'Email not captured' ) . ' — ' . ucfirst( $record->completion_status ) . ' ' . ( $record->completed_at ?: $record->started_at );
				$sources[ 'tracking:' . (int) $record->id ] = $label;
			}
		}
		if ( ! $survivors || count( $sources ) < 2 ) { return ''; }
		$html = '<details class="rtsap-merge-picker"' . ( $open ? ' open' : '' ) . '><summary class="button">' . esc_html__( 'Merge Account', 'run-the-seas' ) . '</summary><div class="rtsap-profile-action-popover rtsap-merge-picker-popover"><p>' . esc_html__( 'These records share the same survey session. Select the registered record that survives and the record it should absorb.', 'run-the-seas' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rts_merge">' . wp_nonce_field( 'rts_merge', '_rts_nonce', true, false ) . '<input type="hidden" name="mode" value="preview"><label>' . esc_html__( 'Surviving registered record', 'run-the-seas' ) . '<select name="keep_id" required>';
		foreach ( $survivors as $participant_id => $label ) { $html .= '<option value="' . (int) $participant_id . '"' . selected( $participant_id, $current_participant_id, false ) . '>' . esc_html( $label ) . '</option>'; }
		$html .= '</select></label><label>' . esc_html__( 'Record to merge into survivor', 'run-the-seas' ) . '<select name="merge_record" required><option value="">' . esc_html__( 'Select a record…', 'run-the-seas' ) . '</option>';
		foreach ( $sources as $value => $label ) { $html .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>'; }
		$html .= '</select></label><button class="button button-primary" type="submit">' . esc_html__( 'Preview Merge', 'run-the-seas' ) . '</button></form></div></details>';
		return $html;
	}

	private static function render_referral_table( $refs, $show_detail = false ) {
		if ( ! $refs ) { echo '<div class="rtsap-empty-state">' . esc_html__( 'No referrals have been recorded.', 'run-the-seas' ) . '</div>'; return; }
		echo '<table class="widefat striped rtsap-profile-referrals"><thead><tr><th>' . esc_html__( 'Referred Participant', 'run-the-seas' ) . '</th><th>' . esc_html__( 'Date', 'run-the-seas' ) . '</th><th>' . esc_html__( 'Verified', 'run-the-seas' ) . '</th>';
		if ( $show_detail ) { echo '<th>' . esc_html__( 'Source', 'run-the-seas' ) . '</th><th>' . esc_html__( 'Fraud Review', 'run-the-seas' ) . '</th>'; }
		echo '<th>' . esc_html__( 'Actions', 'run-the-seas' ) . '</th></tr></thead><tbody>';
		foreach ( $refs as $ref ) {
			$name = $ref->referred_name ?: ( $ref->participant_email ?: ( $ref->referred_email ?: 'Participant #' . (int) $ref->referred_participant_id ) );
			$date = $ref->verified_at ?: ( $ref->completed_date ?: ( $ref->referral_date ?: $ref->created_at ) );
			echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $date ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : '—' ) . '</td><td><span class="rtsap-directory-badge ' . ( $ref->verified ? 'is-verified' : 'is-pending' ) . '">' . esc_html( $ref->verified ? 'Yes' : 'Pending' ) . '</span></td>';
			if ( $show_detail ) { echo '<td>' . esc_html( $ref->referral_source ?: '—' ) . '</td><td>' . esc_html( ucfirst( $ref->fraud_review_status ?: 'clear' ) ) . '</td>'; }
			echo '<td>';
			if ( $ref->referred_participant_id ) { echo '<a class="button rtsap-open-participant" href="' . esc_url( RTSAP_Frontend_Dashboard::screen_url( 'rts-participant-profile', array( 'id' => (int) $ref->referred_participant_id ) ) ) . '">' . esc_html__( 'Open', 'run-the-seas' ) . '</a>'; } else { echo '—'; }
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Historical Fluent Forms submissions can predate the platform answer mapper.
	 * Match them without changing either source table, then expose their saved JSON
	 * fields through the same objects used by native rts_survey_answers rows.
	 */
	private static function fluent_submission_answers( $response, $participant_email ) {
		global $wpdb;
		static $cache = array();
		static $assigned_submissions = array();
		static $labels_by_survey = array();
		$response_id = (int) $response->id;
		if ( isset( $cache[ $response_id ] ) ) { return $cache[ $response_id ]; }
		$cache[ $response_id ] = array();
		$form_id = (int) $response->source_form_id;
		$anchor = $response->completed_at ?: $response->started_at;
		$table = $wpdb->prefix . 'fluentform_submissions';
		if ( ! $form_id || ! $anchor || $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { return $cache[ $response_id ]; }

		$anchor_time = strtotime( $anchor );
		$from = date( 'Y-m-d H:i:s', $anchor_time - 10 * MINUTE_IN_SECONDS );
		$to = date( 'Y-m-d H:i:s', $anchor_time + 10 * MINUTE_IN_SECONDS );
		$candidates = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, response, created_at FROM $table WHERE form_id = %d AND created_at BETWEEN %s AND %s ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_at, %s)) LIMIT 10",
			$form_id, $from, $to, $anchor
		) );
		$selected = null;
		foreach ( $candidates as $candidate ) {
			if ( isset( $assigned_submissions[ (int) $candidate->id ] ) && $assigned_submissions[ (int) $candidate->id ] !== $response_id ) { continue; }
			$payload = json_decode( (string) $candidate->response, true );
			if ( ! is_array( $payload ) ) { continue; }
			$candidate_email = sanitize_email( $payload['email'] ?? '' );
			if ( $participant_email && $candidate_email && 0 !== strcasecmp( sanitize_email( $participant_email ), $candidate_email ) ) { continue; }
			$selected = array( 'row' => $candidate, 'payload' => $payload );
			break;
		}
		if ( ! $selected ) { return $cache[ $response_id ]; }
		$assigned_submissions[ (int) $selected['row']->id ] = $response_id;

		$survey_id = (int) $response->survey_id;
		if ( ! isset( $labels_by_survey[ $survey_id ] ) ) {
			$labels_by_survey[ $survey_id ] = array();
			$workspace = RTS_Business_Logic_2::survey_workspace( $survey_id );
			foreach ( (array) ( $workspace['questions'] ?? array() ) as $question ) {
				if ( empty( $question['is_display'] ) && ! empty( $question['name'] ) ) { $labels_by_survey[ $survey_id ][ $question['name'] ] = $question; }
			}
		}
		$labels = $labels_by_survey[ $survey_id ];
		$position = 0;
		foreach ( $selected['payload'] as $field_name => $value ) {
			if ( str_starts_with( (string) $field_name, '_' ) || self::empty_fluent_answer( $value ) ) { continue; }
			$position++;
			$question = $labels[ $field_name ] ?? array();
			$cache[ $response_id ][] = (object) array(
				'participant_response_id' => $response_id,
				'survey_name'             => $response->survey_name,
				'question_id'             => (string) $field_name,
				'question_label'          => $question['label'] ?? ucwords( str_replace( '_', ' ', (string) $field_name ) ),
				'question_type'           => $question['type'] ?? '',
				'answer_value'            => self::format_fluent_answer( $value ),
				'answer_label'            => '',
				'step_number'             => $position,
				'answered_at'             => $selected['row']->created_at,
			);
		}
		return $cache[ $response_id ];
	}

	private static function empty_fluent_answer( $value ) {
		return '' === self::format_fluent_answer( $value );
	}

	private static function format_fluent_answer( $value ) {
		if ( ! is_array( $value ) ) { return trim( (string) $value ); }
		$flat = array();
		array_walk_recursive( $value, function ( $item ) use ( &$flat ) { if ( '' !== trim( (string) $item ) ) { $flat[] = trim( (string) $item ); } } );
		return implode( ', ', $flat );
	}
	public static function handle_suspend()   {
		self::guard( 'suspend' );
		$id = (int) $_POST['id'];
		RTS_Business_Logic_2::set_account_status( $id, 'suspended', self::admin(), sanitize_text_field( $_POST['reason'] ?? '' ) );
		self::destroy_participant_sessions( $id );
		self::back( 'rts-participant-profile', 'Account suspended.', array( 'id' => $id ) );
	}
	public static function handle_reinstate() { self::guard( 'reinstate' ); RTS_Business_Logic_2::set_account_status( (int) $_POST['id'], 'active', self::admin() ); self::back( 'rts-participant-profile', 'Account reinstated.', array( 'id' => (int) $_POST['id'] ) ); }
	public static function handle_participant_edit() {
		self::guard( 'participant_edit' );
		global $wpdb;
		$id = (int) ( $_POST['id'] ?? 0 );
		$p = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, email FROM " . RTS_DB::table( 'participants' ) . " WHERE id = %d", $id ) );
		if ( ! $p ) { self::back( 'rts-participant-profile', 'Error: Participant not found.', array( 'id' => $id ) ); }
		$user = $p->user_id ? get_user_by( 'id', (int) $p->user_id ) : get_user_by( 'email', $p->email );
		if ( $user && RTSAP_Frontend_Dashboard::is_platform_user( $user ) ) { self::back( 'rts-participant-profile', 'Error: Staff dashboard accounts must be managed from Security.', array( 'id' => $id ) ); }
		$fields = array();
		$text_fields = array(
			'first_name','last_name','phone','registration_country','detected_country','country',
			'city','province','registration_province','postal_code','address_2','date_of_birth','gender','age_range',
			'emergency_contact_name','emergency_contact_phone','marketing_source',
		);
		foreach ( $text_fields as $field ) {
			if ( array_key_exists( $field, $_POST ) ) { $fields[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) ); }
		}
		if ( array_key_exists( 'address', $_POST ) ) { $fields['address'] = sanitize_textarea_field( wp_unslash( $_POST['address'] ) ); }
		if ( array_key_exists( 'email', $_POST ) ) { $fields['email'] = sanitize_email( wp_unslash( $_POST['email'] ) ); }
		foreach ( array( 'runner_status', 'account_status' ) as $field ) {
			if ( array_key_exists( $field, $_POST ) ) { $fields[ $field ] = sanitize_key( wp_unslash( $_POST[ $field ] ) ); }
		}
		foreach ( array( 'marketing_consent', 'country_verified' ) as $field ) {
			if ( array_key_exists( $field, $_POST ) ) { $fields[ $field ] = empty( $_POST[ $field ] ) ? 0 : 1; }
		}
		$result = RTS_Business_Logic_2::update_participant( $id, $fields, self::admin() );
		if ( $result['error'] ) { self::back( 'rts-participant-profile', 'Error: ' . $result['error'], array( 'id' => $id ) ); }
		if ( 'suspended' === ( $fields['account_status'] ?? '' ) ) { self::destroy_participant_sessions( $id ); }
		$message = empty( $result['changed'] ) ? 'No profile changes were needed.' : 'Participant profile updated and logged.';
		self::back( 'rts-participant-profile', $message, array( 'id' => $id ) );
	}
	public static function handle_participant_note() {
		self::guard( 'participant_note' );
		global $wpdb;
		$id = (int) ( $_POST['id'] ?? 0 );
		$note = trim( sanitize_textarea_field( wp_unslash( $_POST['note_text'] ?? '' ) ) );
		if ( ! $id || '' === $note ) { self::back( 'rts-participant-profile', 'Error: A note is required.', array( 'id' => $id, 'profile_tab' => 'admin-notes' ) ); }
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . RTS_DB::table( 'participants' ) . " WHERE id = %d", $id ) ) ) { self::back( 'rts-participant-profile', 'Error: Participant not found.', array( 'id' => $id ) ); }
		$inserted = $wpdb->insert( RTS_DB::table( 'participant_notes' ), array( 'participant_id' => $id, 'admin_user_id' => get_current_user_id(), 'admin_name' => self::admin(), 'note_text' => $note, 'created_at' => current_time( 'mysql' ) ) );
		if ( ! $inserted ) { self::back( 'rts-participant-profile', 'Error: ' . ( $wpdb->last_error ?: 'The note could not be saved.' ), array( 'id' => $id, 'profile_tab' => 'admin-notes' ) ); }
		RTS_Business_Logic::log_audit( self::admin(), 'Admin note added', 'Participants', 'success', 'participant_id=' . $id . '; note_id=' . (int) $wpdb->insert_id );
		self::back( 'rts-participant-profile', 'Internal note added.', array( 'id' => $id, 'profile_tab' => 'admin-notes' ) );
	}
	public static function handle_participant_email() {
		self::guard( 'participant_email' );
		global $wpdb;
		$id = (int) ( $_POST['id'] ?? 0 );
		$subject = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$p = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . RTS_DB::table( 'participants' ) . " WHERE id = %d", $id ) );
		if ( ! $p || ! $subject || ! $message ) { self::back( 'rts-participant-profile', 'Error: Participant, subject and message are required.', array( 'id' => $id ) ); }
		$result = RTS_Production::send( $p->email, RTS_Production::merge( $subject, $p ), RTS_Production::merge( $message, $p ), 'marketing', array( 'participant_id' => $id, 'unsubscribe_url' => RTS_Production::unsubscribe_url( $p->unsubscribe_token ) ) );
		if ( $result['error'] ) { self::back( 'rts-participant-profile', 'Error: ' . $result['error'], array( 'id' => $id ) ); }
		RTS_Business_Logic::log_audit( self::admin(), 'Participant email sent', 'Participants', 'success', 'participant_id=' . $id . '; subject=' . $subject . '; outbox_id=' . (int) $result['outbox_id'] );
		$notice = 'send' === $result['mode'] ? 'Email sent and logged.' : 'Email recorded in the outbox (email mode is log-only).';
		self::back( 'rts-participant-profile', $notice, array( 'id' => $id ) );
	}
	private static function destroy_participant_sessions( $id ) {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, email FROM " . RTS_DB::table( 'participants' ) . " WHERE id = %d", $id ) );
		if ( ! $p ) { return; }
		$user = $p->user_id ? get_user_by( 'id', (int) $p->user_id ) : get_user_by( 'email', $p->email );
		if ( $user ) { WP_Session_Tokens::get_instance( $user->ID )->destroy_all(); }
	}
	public static function handle_participant_status() {
		self::guard( 'participant_status' );
		global $wpdb;
		$id = (int) $_POST['id'];
		$status = sanitize_key( $_POST['status'] ?? '' );
		if ( ! in_array( $status, array( 'active', 'suspended' ), true ) ) { self::back( 'rts-participant-profile', 'Error: Invalid account status.', array( 'id' => $id ) ); }
		$p = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, email FROM " . RTS_DB::table( 'participants' ) . " WHERE id = %d", $id ) );
		if ( ! $p ) { self::back( 'rts-participant-profile', 'Error: Participant not found.', array( 'id' => $id ) ); }
		$user = $p->user_id ? get_user_by( 'id', (int) $p->user_id ) : get_user_by( 'email', $p->email );
		if ( $user && RTSAP_Frontend_Dashboard::is_platform_user( $user ) ) { self::back( 'rts-participant-profile', 'Error: Staff dashboard accounts must be managed from Security.', array( 'id' => $id ) ); }
		$result = RTS_Business_Logic_2::set_account_status( $id, $status, self::admin(), 'Changed from participant profile toggle' );
		if ( $result['error'] ) { self::back( 'rts-participant-profile', 'Error: ' . $result['error'], array( 'id' => $id ) ); }
		if ( 'suspended' === $status && $user ) { WP_Session_Tokens::get_instance( $user->ID )->destroy_all(); }
		self::back( 'rts-participant-profile', 'active' === $status ? 'Participant activated.' : 'Participant suspended.', array( 'id' => $id ) );
	}
	public static function handle_reset_passcode() {
		self::guard( 'reset_passcode' );
		global $wpdb;
		$id = (int) $_POST['id'];
		$p = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, email FROM " . RTS_DB::table( 'participants' ) . " WHERE id = %d", $id ) );
		if ( ! $p ) { self::back( 'rts-participant-profile', 'Error: Participant not found.', array( 'id' => $id ) ); }
		$user = $p->user_id ? get_user_by( 'id', (int) $p->user_id ) : get_user_by( 'email', $p->email );
		if ( ! $user ) { self::back( 'rts-participant-profile', 'Error: This participant does not have a WordPress login.', array( 'id' => $id ) ); }
		$result = retrieve_password( $user->user_login );
		if ( is_wp_error( $result ) ) { self::back( 'rts-participant-profile', 'Error: ' . $result->get_error_message(), array( 'id' => $id ) ); }
		RTS_Business_Logic::log_audit( self::admin(), 'Participant passcode reset email sent', 'Participants', 'success', "participant_id=$id" );
		self::back( 'rts-participant-profile', 'Passcode reset email sent.', array( 'id' => $id ) );
	}
	public static function handle_merge() {
		self::guard( 'merge' );
		$keep = (int) ( $_POST['keep_id'] ?? 0 ); $mode = sanitize_key( $_POST['mode'] ?? 'preview' );
		$source_type = sanitize_key( $_POST['source_type'] ?? '' ); $source_id = (int) ( $_POST['source_id'] ?? 0 );
		if ( 'preview' === $mode ) {
			$record = sanitize_text_field( wp_unslash( $_POST['merge_record'] ?? '' ) );
			if ( ! preg_match( '/^(participant|tracking):(\d+)$/', $record, $matches ) ) { self::back( 'rts-participant-profile', 'Error: Select a record to merge.', array( 'id' => $keep, 'merge_group' => 1 ) ); }
			$source_type = $matches[1]; $source_id = (int) $matches[2];
		} elseif ( ! $source_type && ! empty( $_POST['merge_id'] ) ) {
			$source_type = 'participant'; $source_id = (int) $_POST['merge_id'];
		}
		if ( ! in_array( $source_type, array( 'participant', 'tracking' ), true ) || ! $keep || ! $source_id ) { self::back( 'rts-participant-profile', 'Error: Invalid merge selection.', array( 'id' => $keep ) ); }
		$field_sources = array();
		foreach ( (array) ( $_POST['field_sources'] ?? array() ) as $field => $source ) {
			$field = sanitize_key( $field ); $source = sanitize_key( $source );
			if ( in_array( $source, array( 'keep', 'merge' ), true ) ) { $field_sources[ $field ] = $source; }
		}
		$r = 'tracking' === $source_type
			? RTS_Business_Logic_2::merge_survey_record( $keep, $source_id, 'commit' === $mode, self::admin() )
			: RTS_Business_Logic_2::merge_duplicates( $keep, $source_id, 'commit' === $mode, $field_sources, self::admin() );
		if ( $r['error'] ) { self::back( 'rts-participant-profile', 'Error: ' . $r['error'], array( 'id' => $keep ) ); }
		if ( 'commit' === $mode ) { self::back( 'rts-participant-profile', 'tracking' === $source_type ? 'Survey record attached to participant.' : 'Participant merge committed.', array( 'id' => $keep ) ); }
		set_transient( 'rts_merge_preview_' . get_current_user_id(), array( 'source_type' => $source_type, 'source_id' => $source_id, 'preview' => $r['preview'] ), 5 * MINUTE_IN_SECONDS );
		self::back( 'rts-participant-profile', '', array( 'id' => $keep, 'merge_preview' => 1 ) );
	}

	// ---------- Verification Queue ----------
	public static function render_queue() {
		$q = RTS_Business_Logic_2::get_verification_queue();
		echo '<div class="wrap"><h1>Email Verification Queue</h1>'; self::notice();
		echo '<p><b>Pending:</b> ' . (int) $q['pending_count'] . ' · <b>Verification rate:</b> ' . esc_html( $q['verification_rate'] ) . '%</p>';
		if ( ! $q['pending_count'] ) { echo '<p>🎉 All caught up.</p></div>'; return; }
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Name</th><th>Email</th><th>Sent</th><th>Manual verify (reason required, logged)</th></tr></thead><tbody>';
		foreach ( $q['pending'] as $p ) {
			echo '<tr><td>' . esc_html( $p->name ) . '</td><td>' . esc_html( $p->email ) . '</td><td>' . esc_html( $p->verification_sent_at ) . '</td><td>'
			   . self::form( 'manual_verify', '<input type="text" name="reason" placeholder="Reason" required> ', 'Manually Verify', array( 'id' => $p->id ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
	public static function handle_manual_verify() {
		self::guard( 'manual_verify' );
		$id = (int) $_POST['id'];
		$r = RTS_Business_Logic_2::manually_verify( $id, self::admin(), sanitize_text_field( $_POST['reason'] ?? '' ) );
		$message = $r['error'] ? 'Error: ' . $r['error'] : 'Verified — Cabin Credit issued, same side effects as a real link click.';
		if ( ! empty( $_POST['return_profile'] ) ) { self::back( 'rts-participant-profile', $message, array( 'id' => $id ) ); }
		self::back( 'rts-verification-queue', $message );
	}

	// ---------- Email Templates ----------
	public static function render_templates() {
		global $wpdb;
		$template_id = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0;
		if ( $template_id ) {
			self::render_template_editor( $template_id );
			return;
		}
		echo '<div class="wrap"><h1>Email Template Library</h1>'; self::notice();
		$rows = $wpdb->get_results( "SELECT * FROM " . RTS_DB::table( 'email_templates' ) . " ORDER BY updated_at DESC" );
		$actions = RTS_Business_Logic_2::email_template_actions();
		$action_options = '<option value="">Use survey plugin default</option>';
		foreach ( $actions as $key => $label ) { $action_options .= '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>'; }
		echo '<p>Assign one template to each transactional action. Reassigning an action automatically unassigns its previous template. If no template is assigned, the original email defined in the Run The Seas Survey plugin is used automatically. Every template can be edited with WordPress Visual or HTML/Text mode.</p>';
		echo '<p><strong>Available merge fields:</strong> <code>{first_name}</code> <code>{last_name}</code> <code>{full_name}</code> <code>{email}</code> <code>{password_reset_url}</code> <code>{verification_url}</code> <code>{certificate_number}</code> <code>{founding_runner_number}</code> <code>{certificate_preview_url}</code> <code>{captains_suite_url}</code> <code>{login_url}</code> <code>{account_url}</code> <code>{logo_url}</code> <code>{support_email}</code> <code>{site_name}</code> <code>{site_url}</code></p>';
		echo '<h3>New Template</h3>' . self::form( 'create_template',
			'<input type="text" name="name" placeholder="Name" required> <input type="text" name="subject" placeholder="Subject" required> <select name="category"><option>onboarding</option><option>acquisition</option><option>engagement</option><option>transactional</option><option>milestone</option></select> <select name="action_key" aria-label="Email action">' . $action_options . '</select> <input type="hidden" name="html_body" value=""> ', 'Create, then edit visually' );
		echo '<h3>Templates (' . count( $rows ) . ')</h3><table class="wp-list-table widefat fixed striped"><thead><tr><th>Name</th><th>Subject</th><th>Category</th><th>Assigned action</th><th>Template content</th></tr></thead><tbody>';
		foreach ( $rows as $t ) {
			$selected_options = '<option value="">Use survey plugin default</option>';
			foreach ( $actions as $key => $label ) { $selected_options .= '<option value="' . esc_attr( $key ) . '"' . selected( $t->action_key, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
			$assignment = self::form( 'assign_template', '<select name="action_key" aria-label="Assigned email action">' . $selected_options . '</select> ', 'Apply', array( 'id' => $t->id ) );
			$edit_url = RTSAP_Frontend_Dashboard::screen_url( 'rts-email-templates', array( 'template_id' => (int) $t->id ) );
			$content_excerpt = wp_trim_words( wp_strip_all_tags( (string) $t->html_body ), 18, '…' );
			$editor = '<a class="button button-primary" href="' . esc_url( $edit_url ) . '">Visual / HTML Editor</a><small style="display:block;margin-top:7px;line-height:1.35;">' . esc_html( $content_excerpt ) . '</small>';
			echo '<tr><td>' . esc_html( $t->name ) . '</td><td>' . esc_html( $t->subject ) . '</td><td>' . esc_html( $t->category ) . '</td><td>' . $assignment . '</td><td>' . $editor . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_template_editor( $template_id ) {
		global $wpdb;
		$template = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . RTS_DB::table( 'email_templates' ) . " WHERE id = %d", $template_id ) );
		if ( ! $template ) {
			echo '<div class="wrap"><h1>Email Template</h1><div class="notice notice-error"><p>Template not found.</p></div></div>';
			return;
		}
		if ( function_exists( 'rts_normalize_email_certificate_template' ) ) {
			$certificate = rts_normalize_email_certificate_template( $template->html_body, $template->action_key ?? '' );
			$template->html_body = $certificate['html_body'];
		}

		$categories = array( 'onboarding', 'acquisition', 'engagement', 'transactional', 'milestone' );
		$list_url = RTSAP_Frontend_Dashboard::screen_url( 'rts-email-templates' );
		echo '<div class="wrap"><h1>Edit Email Template</h1>';
		echo '<p><a class="button" href="' . esc_url( $list_url ) . '">&larr; Back to Template Library</a></p>';
		echo '<p>Use the <strong>Visual</strong> tab for normal editing or the <strong>Code</strong> tab for complete HTML control. Saving updates this template directly.</p>';
		echo '<p><em>The logo and certificate shown in this editor are previews. Their saved merge fields are restored automatically so sent emails use the current logo and each participant&rsquo;s personalised certificate.</em></p>';
		echo '<p><strong>Merge fields:</strong> <code>{first_name}</code> <code>{last_name}</code> <code>{full_name}</code> <code>{email}</code> <code>{password_reset_url}</code> <code>{verification_url}</code> <code>{certificate_number}</code> <code>{founding_runner_number}</code> <code>{certificate_preview_url}</code> <code>{captains_suite_url}</code> <code>{login_url}</code> <code>{account_url}</code> <code>{logo_url}</code> <code>{support_email}</code> <code>{site_name}</code> <code>{site_url}</code></p>';
		// Image replacement values can be dynamic/local URLs. Native URL-field
		// validation must not silently cancel an otherwise valid template save.
		echo '<form id="rts-email-template-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" novalidate>';
		self::notice();
		echo '<input type="hidden" name="action" value="rts_update_template"><input type="hidden" name="id" value="' . (int) $template->id . '">';
		wp_nonce_field( 'rts_update_template', '_rts_nonce' );
		echo '<table class="form-table"><tr><th><label for="rts-template-name">Name</label></th><td><input id="rts-template-name" class="regular-text" type="text" name="name" value="' . esc_attr( $template->name ) . '" required></td></tr>';
		echo '<tr><th><label for="rts-template-subject">Subject</label></th><td><input id="rts-template-subject" class="large-text" type="text" name="subject" value="' . esc_attr( $template->subject ) . '" required></td></tr>';
		echo '<tr><th><label for="rts-template-category">Category</label></th><td><select id="rts-template-category" name="category">';
		foreach ( $categories as $category ) { echo '<option value="' . esc_attr( $category ) . '"' . selected( $template->category, $category, false ) . '>' . esc_html( $category ) . '</option>'; }
		echo '</select></td></tr></table>';
		if ( function_exists( 'wp_enqueue_media' ) ) { wp_enqueue_media(); }
		self::render_template_image_controls( $template );
		echo '<h2>Message body</h2>';
		echo '<div class="rts-email-layout-tools"><div><strong>Email-safe layout blocks</strong><span>Insert table-based sections at the current cursor position. These blocks use inline styles and presentation tables for reliable Gmail and Outlook rendering.</span></div><div class="rts-email-layout-tools__buttons"><button type="button" class="button rts-insert-email-layout" data-layout="frame">Full Email Frame</button><button type="button" class="button rts-insert-email-layout" data-layout="one_column">1 Column Section</button><button type="button" class="button rts-insert-email-layout" data-layout="two_columns">2 Column Section</button><button type="button" class="button rts-insert-email-layout" data-layout="button">Button Row</button></div></div>';
		wp_editor(
			self::email_template_editor_fragment( self::template_editor_preview_body( $template, (string) $template->html_body ) ),
			'rts_email_template_body',
			array(
				'textarea_name' => 'html_body',
				'textarea_rows' => 24,
				'media_buttons' => true,
				'teeny' => false,
				'quicktags' => true,
				'tinymce' => array( 'wpautop' => false ),
			)
		);
		$layout_json = wp_json_encode( self::email_layout_snippets(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		echo '<script>(function(){var layouts=' . $layout_json . ';function insertLayout(html){var editor=window.tinymce&&tinymce.get("rts_email_template_body");if(editor&&!editor.isHidden()){editor.focus();editor.execCommand("mceInsertContent",false,html);return;}var field=document.getElementById("rts_email_template_body");if(!field){return;}if(typeof field.setRangeText==="function"){field.setRangeText(html,field.selectionStart,field.selectionEnd,"end");field.dispatchEvent(new Event("input",{bubbles:true}));}else{field.value+=html;}}document.addEventListener("click",function(event){var button=event.target.closest(".rts-insert-email-layout");if(!button||!layouts[button.dataset.layout]){return;}insertLayout(layouts[button.dataset.layout]);});var form=document.getElementById("rts-email-template-form");if(form){form.addEventListener("submit",function(event){if(window.tinymce){tinymce.triggerSave();}var required=[document.getElementById("rts-template-name"),document.getElementById("rts-template-subject")];for(var i=0;i<required.length;i++){if(required[i]&&!required[i].value.trim()){event.preventDefault();required[i].focus();window.alert("Please complete the template name and subject before saving.");return;}}});}})();</script>';
		submit_button( 'Save Template' );
		echo '</form></div>';
	}

	/** TinyMCE edits body fragments, not nested HTML documents. */
	private static function email_template_editor_fragment( $html ) {
		$html = preg_replace( '/^\xEF\xBB\xBF/', '', trim( (string) $html ) );
		$html = preg_replace( '/<!doctype[^>]*>/i', '', $html );
		if ( preg_match( '/<body\b[^>]*>(.*)<\/body>/is', $html, $match ) ) {
			return trim( $match[1] );
		}
		$html = preg_replace( '/<\/?html\b[^>]*>/i', '', $html );
		return trim( $html );
	}

	/** Starter markup for new marketing templates: email-client-safe tables and inline styles. */
	private static function default_email_template_html( $subject = '' ) {
		$heading = trim( (string) $subject ) ?: 'Run The Seas Update';
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:0;background-color:#f4f5f7;">'
			. '<tr><td align="center" style="padding:30px 12px;">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;border-collapse:collapse;">'
			. '<tr><td align="center" style="padding:22px 30px;background-color:#0b1420;color:#e4c77a;font-family:Georgia,Times New Roman,serif;font-size:18px;letter-spacing:1px;">RUN THE SEAS</td></tr>'
			. '<tr><td style="padding:36px 34px;font-family:Arial,Helvetica,sans-serif;color:#1b2430;">'
			. '<h1 style="margin:0 0 18px;font-family:Georgia,Times New Roman,serif;font-size:28px;line-height:1.25;color:#0b1420;">' . esc_html( $heading ) . '</h1>'
			. '<p style="margin:0 0 20px;font-size:16px;line-height:1.6;">Hi {first_name},</p>'
			. '<p style="margin:0 0 24px;font-size:16px;line-height:1.6;">Add your email message here.</p>'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td style="border-radius:24px;background-color:#c9a24b;"><a href="{site_url}" style="display:inline-block;padding:13px 24px;color:#0b1420;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;">Call to Action</a></td></tr></table>'
			. '</td></tr><tr><td align="center" style="padding:20px 30px;background-color:#eef0f3;color:#6b7686;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;">Run The Seas<br><a href="{unsubscribe_url}" style="color:#6b7686;text-decoration:underline;">Manage email preferences</a></td></tr>'
			. '</table></td></tr></table>';
	}

	/** Reusable blocks inserted by the email layout toolbar. */
	private static function email_layout_snippets() {
		return array(
			'frame' => self::default_email_template_html( 'Email heading' ),
			'one_column' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;"><tr><td style="padding:24px;font-family:Arial,Helvetica,sans-serif;color:#1b2430;"><h2 style="margin:0 0 12px;font-family:Georgia,Times New Roman,serif;font-size:22px;line-height:1.3;color:#0b1420;">Section heading</h2><p style="margin:0;font-size:15px;line-height:1.6;">Add section content here.</p></td></tr></table><p>&nbsp;</p>',
			'two_columns' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;"><tr><td width="50%" valign="top" style="width:50%;padding:20px 12px 20px 0;font-family:Arial,Helvetica,sans-serif;color:#1b2430;"><h3 style="margin:0 0 10px;font-size:18px;color:#0b1420;">Left column</h3><p style="margin:0;font-size:14px;line-height:1.6;">Add content here.</p></td><td width="50%" valign="top" style="width:50%;padding:20px 0 20px 12px;font-family:Arial,Helvetica,sans-serif;color:#1b2430;"><h3 style="margin:0 0 10px;font-size:18px;color:#0b1420;">Right column</h3><p style="margin:0;font-size:14px;line-height:1.6;">Add content here.</p></td></tr></table><p>&nbsp;</p>',
			'button' => '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;"><tr><td style="border-radius:24px;background-color:#c9a24b;"><a href="{site_url}" style="display:inline-block;padding:13px 24px;color:#0b1420;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;">Button text</a></td></tr></table>',
		);
	}

	/** Return each unique image source used by img, background, or CSS url(). */
	private static function template_image_sources( $body ) {
		$sources = array();
		$patterns = array(
			'~<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1~is',
			'~\bbackground\s*=\s*(["\'])(.*?)\1~is',
			'~\burl\(\s*(["\']?)(.*?)\1\s*\)~is',
		);
		foreach ( $patterns as $pattern ) {
			if ( ! preg_match_all( $pattern, (string) $body, $matches ) ) { continue; }
			foreach ( $matches[2] as $source ) {
				$source = html_entity_decode( trim( (string) $source ), ENT_QUOTES, 'UTF-8' );
				if ( '' !== $source && ! in_array( $source, $sources, true ) ) { $sources[] = $source; }
			}
		}
		return $sources;
	}

	/** Resolve one merge-field image into the representative editor artwork. */
	private static function template_image_preview_url( $template, $source ) {
		if ( ! preg_match( '/^\{([a-z0-9_]+)\}$/', (string) $source, $match ) ) { return $source; }
		if ( ! function_exists( 'rts_get_transactional_email_editor_preview_context' ) ) { return ''; }
		$context = rts_get_transactional_email_editor_preview_context( $template->action_key ?? '', $template->html_body );
		return ! empty( $context[ $match[1] ] ) ? (string) $context[ $match[1] ] : '';
	}

	/** Human-readable name for an image row in the no-code replacement panel. */
	private static function template_image_label( $source, $index ) {
		$labels = array(
			'{logo_url}'                => 'Site logo (dynamic)',
			'{certificate_preview_url}' => 'Participant certificate preview (dynamic)',
		);
		if ( isset( $labels[ $source ] ) ) { return $labels[ $source ]; }
		if ( preg_match( '/^\{(verification|certificate)_([a-z0-9_]+)\}$/', (string) $source, $match ) ) {
			return ucwords( str_replace( '_', ' ', $match[2] ) ) . ' (from email design)';
		}
		$path = (string) wp_parse_url( $source, PHP_URL_PATH );
		$file = $path ? rawurldecode( basename( $path ) ) : '';
		return $file ?: 'Template image ' . ( $index + 1 );
	}

	/** Display Media Library replacement controls without requiring HTML edits. */
	private static function render_template_image_controls( $template ) {
		$sources = self::template_image_sources( $template->html_body );
		$certificate = function_exists( 'rts_normalize_email_certificate_template' )
			? rts_normalize_email_certificate_template( $template->html_body, $template->action_key ?? '' ) : array();
		echo '<h2>Template Images</h2>';
		echo '<p>Images marked <strong>from email design</strong> follow the Survey plugin&rsquo;s email-design settings. Replacing one here creates an override for this template only. New images added in the Visual editor appear here after the template is saved and reopened.</p>';
		if ( ! $sources ) { echo '<p><em>No images were detected in this template.</em></p>'; return; }
		echo '<div class="rts-template-image-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin:14px 0 24px;">';
		foreach ( $sources as $index => $source ) {
			$is_certificate = '{certificate_preview_url}' === $source;
			$preview = $is_certificate && ! empty( $certificate['backplate_url'] )
				? $certificate['backplate_url'] : self::template_image_preview_url( $template, $source );
			echo '<div class="rts-template-image-card" style="padding:14px;border:1px solid #ccd0d4;border-radius:6px;background:#fff;">';
			echo '<strong style="display:block;margin-bottom:9px;">' . esc_html( self::template_image_label( $source, $index ) ) . '</strong>';
			if ( $is_certificate ) { echo '<p>The selected image is the certificate background. The recipient&rsquo;s name and certificate details are added automatically when sending.</p>'; }
			if ( $preview ) { echo '<img class="rts-template-image-preview" src="' . esc_url( $preview ) . '" alt="" style="display:block;width:auto;max-width:100%;height:100px;object-fit:contain;margin:0 0 10px;background:#eef1f4;">'; }
			echo '<input class="large-text code rts-template-image-url" type="text" inputmode="url" name="template_image_replacement[' . (int) $index . ']" value="' . esc_attr( $preview ) . '" data-default="' . esc_attr( $preview ) . '" placeholder="https://">';
			echo '<p style="margin:9px 0 0;"><button type="button" class="button rts-template-image-select">Select / Replace from Media Library</button> <button type="button" class="button-link rts-template-image-reset">Undo unsaved replacement</button></p>';
			echo '</div>';
		}
		echo '</div>';
		echo '<script>(function(){document.addEventListener("click",function(event){var selectButton=event.target.closest(".rts-template-image-select"),resetButton=event.target.closest(".rts-template-image-reset"),card=(selectButton||resetButton)?(selectButton||resetButton).closest(".rts-template-image-card"):null;if(!card){return;}var input=card.querySelector(".rts-template-image-url"),preview=card.querySelector(".rts-template-image-preview");if(resetButton){input.value=input.getAttribute("data-default")||"";if(preview){preview.src=input.value;}return;}if(!window.wp||!wp.media){return;}var frame=wp.media({title:"Select replacement image",button:{text:"Use this image"},library:{type:"image"},multiple:false});frame.on("select",function(){var attachment=frame.state().get("selection").first().toJSON();input.value=attachment.url||"";if(preview){preview.src=input.value;}});frame.open();});document.addEventListener("input",function(event){if(!event.target.classList.contains("rts-template-image-url")){return;}var preview=event.target.closest(".rts-template-image-card").querySelector(".rts-template-image-preview");if(preview){preview.src=event.target.value;}});})();</script>';
	}

	/** Replace image merge fields with real URLs while the Visual editor is open. */
	private static function template_editor_preview_body( $template, $body ) {
		if ( ! function_exists( 'rts_get_transactional_email_editor_preview_context' ) ) { return $body; }
		$context = rts_get_transactional_email_editor_preview_context( $template->action_key ?? '', $template->html_body );
		foreach ( $context as $key => $value ) {
			if ( ! empty( $value ) ) { $body = str_replace( '{' . $key . '}', $value, $body ); }
		}
		return $body;
	}

	/** Convert editor-only image preview URLs back to recipient-aware merge fields. */
	private static function restore_template_image_merge_fields( $template_id, $body ) {
		global $wpdb;
		$template = $wpdb->get_row( $wpdb->prepare( "SELECT action_key, html_body FROM " . RTS_DB::table( 'email_templates' ) . " WHERE id = %d", $template_id ) );
		if ( ! $template || ! function_exists( 'rts_get_transactional_email_editor_preview_context' ) ) { return $body; }
		if ( function_exists( 'rts_normalize_email_certificate_template' ) ) {
			$certificate = rts_normalize_email_certificate_template( $template->html_body, $template->action_key ?? '' );
			$template->html_body = $certificate['html_body'];
		}
		$context = rts_get_transactional_email_editor_preview_context( $template->action_key ?? '', $template->html_body );
		foreach ( $context as $key => $value ) {
			$token = '{' . $key . '}';
			// Certificate src is restored by the HTML parser below. A global URL
			// replacement would also erase its backplate metadata when GD is absent.
			if ( 'certificate_preview_url' === $key && function_exists( 'rts_normalize_email_certificate_template' ) ) { continue; }
			if ( false !== strpos( (string) $template->html_body, $token ) && ! empty( $value ) ) {
				$body = str_replace( array( $value, esc_url( $value ) ), $token, $body );
			}
		}
		return function_exists( 'rts_normalize_email_certificate_template' )
			? rts_normalize_email_certificate_template( $body, $template->action_key ?? '', $context['certificate_preview_url'] ?? '' )['html_body'] : $body;
	}

	/** Apply replacements selected in the Template Images panel. */
	private static function apply_template_image_replacements( $template_id, $body, $submitted ) {
		global $wpdb;
		$template = $wpdb->get_row( $wpdb->prepare( "SELECT action_key, html_body FROM " . RTS_DB::table( 'email_templates' ) . " WHERE id = %d", $template_id ) );
		if ( ! $template || ! is_array( $submitted ) ) { return $body; }
		if ( function_exists( 'rts_normalize_email_certificate_template' ) ) {
			$certificate = rts_normalize_email_certificate_template( $template->html_body, $template->action_key ?? '' );
			$template->html_body = $certificate['html_body'];
		}
		$sources = self::template_image_sources( $template->html_body );
		foreach ( $sources as $index => $source ) {
			if ( ! array_key_exists( $index, $submitted ) ) { continue; }
			$replacement = esc_url_raw( wp_unslash( $submitted[ $index ] ) );
			$preview = self::template_image_preview_url( $template, $source );
			if ( '{certificate_preview_url}' === $source && function_exists( 'rts_normalize_email_certificate_template' ) ) {
				$normalized = rts_normalize_email_certificate_template( $body, $template->action_key ?? '', $preview );
				$body = $normalized['html_body'];
				// Repair even an unchanged Media Library selection. Store the artwork
				// on this template, leaving src as the recipient-aware merge field.
				if ( '' !== $replacement && $replacement !== $preview && $replacement !== $source ) {
					$tags = new WP_HTML_Tag_Processor( $body );
					while ( $tags->next_tag( 'IMG' ) ) {
						if ( '{certificate_preview_url}' === $tags->get_attribute( 'src' ) ) {
							$tags->set_attribute( 'data-rts-certificate-backplate', $replacement );
						}
					}
					$body = $tags->get_updated_html();
				}
				continue;
			}
			if ( '' === $replacement || $replacement === $preview || $replacement === $source ) { continue; }
			$body = str_replace( array( $source, esc_url( $source ) ), $replacement, $body );
		}
		return function_exists( 'rts_normalize_email_certificate_template' )
			? rts_normalize_email_certificate_template( $body, $template->action_key ?? '' )['html_body'] : $body;
	}
	public static function handle_create_template()   { self::guard( 'create_template' ); $subject = sanitize_text_field( $_POST['subject'] ); $body = wp_kses_post( wp_unslash( $_POST['html_body'] ?? '' ) ); if ( '' === trim( $body ) ) { $body = self::default_email_template_html( $subject ); } $r = RTS_Business_Logic_2::create_template( array( 'name' => sanitize_text_field( $_POST['name'] ), 'subject' => $subject, 'category' => sanitize_text_field( $_POST['category'] ?? 'general' ), 'action_key' => sanitize_key( $_POST['action_key'] ?? '' ), 'html_body' => $body, 'created_by' => self::admin() ) ); self::back( 'rts-email-templates', $r['error'] ? 'Error: ' . $r['error'] : 'Template created with an email-safe table layout. Open the Visual / HTML Editor to customise it.' ); }
	public static function handle_update_template()   { self::guard( 'update_template' ); $id = (int) $_POST['id']; $submitted_body = self::email_template_editor_fragment( wp_unslash( $_POST['html_body'] ?? '' ) ); $html_body = self::restore_template_image_merge_fields( $id, wp_kses_post( $submitted_body ) ); $html_body = self::apply_template_image_replacements( $id, $html_body, $_POST['template_image_replacement'] ?? array() ); $r = RTS_Business_Logic_2::update_template( $id, array( 'name' => sanitize_text_field( $_POST['name'] ?? '' ), 'subject' => sanitize_text_field( $_POST['subject'] ), 'category' => sanitize_text_field( $_POST['category'] ?? '' ), 'html_body' => $html_body, 'updated_by' => self::admin() ) ); self::back( 'rts-email-templates', $r['error'] ? 'Error: ' . $r['error'] : 'Template saved.', array( 'template_id' => $id, '_fragment' => 'rts-email-template-form' ) ); }
	public static function handle_assign_template()   { self::guard( 'assign_template' );   $r = RTS_Business_Logic_2::assign_template_action( (int) $_POST['id'], sanitize_key( $_POST['action_key'] ?? '' ), self::admin() ); self::back( 'rts-email-templates', $r['error'] ? 'Error: ' . $r['error'] : ( $r['action_key'] ? 'Template assigned. Any previous template for this action was unassigned.' : 'Template unassigned; the survey plugin default will be used.' ) ); }
}
