<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RTS_Admin_Menu_4 {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 40 );
		foreach ( array( 'invite_admin','change_role','deactivate_admin','run_backup' ) as $a ) { add_action( "admin_post_rts_$a", array( __CLASS__, "handle_$a" ) ); }
	}

	public static function register_menu() {
		// Replace the Batch 1 dashboard with the expanded v2.
		remove_submenu_page( 'rts-admin', 'rts-admin' );
		RTS_Auth::page( 'rts-admin', 'Executive Dashboard', 'Executive Dashboard', 'rts_view', 'rts-admin', array( __CLASS__, 'render_exec' ), 0 );
		RTS_Auth::page( 'rts-admin', 'Super Admin Dashboard', 'Super Admin Dashboard', 'rts_manage_admins', 'rts-super-admin', array( __CLASS__, 'render_super' ) );
		RTS_Auth::page( 'rts-admin', 'Security Dashboard', 'Security Dashboard', 'rts_system', 'rts-security', array( __CLASS__, 'render_security' ) );
		RTS_Auth::page( 'rts-admin', 'Administrators & Roles', 'Administrators & Roles', 'rts_manage_admins', 'rts-admins', array( __CLASS__, 'render_admins' ) );
		RTS_Auth::page( 'rts-admin', 'Backup & System', 'Backup & System', 'rts_system', 'rts-backup', array( __CLASS__, 'render_backup' ) );
	}

	private static function form( $action, $fields, $button, $hidden = array(), $class = 'button', $onsubmit = '' ) {
		$h = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;"' . ( $onsubmit ? ' onsubmit="' . esc_attr( $onsubmit ) . '"' : '' ) . '><input type="hidden" name="action" value="rts_' . esc_attr( $action ) . '">' . wp_nonce_field( 'rts_' . $action, '_rts_nonce', true, false );
		foreach ( $hidden as $k => $v ) { $h .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">'; }
		return $h . $fields . '<button class="' . esc_attr( $class ) . '">' . esc_html( $button ) . '</button></form>';
	}
	private static function guard( $a ) { if ( ! current_user_can( RTS_Auth::action_cap( $a ) ) || ! isset( $_POST['_rts_nonce'] ) || ! wp_verify_nonce( $_POST['_rts_nonce'], 'rts_' . $a ) ) { wp_die( 'Not allowed.', 'Forbidden', array( 'response' => 403 ) ); } }
	private static function back( $page, $msg = '' ) { $args = array(); if ( $msg ) { $args['rts_msg'] = rawurlencode( $msg ); } wp_safe_redirect( RTSAP_Frontend_Dashboard::screen_url( $page, $args ) ); exit; }
	private static function notice() { if ( ! empty( $_GET['rts_msg'] ) ) { $m = rawurldecode( $_GET['rts_msg'] ); $cls = str_starts_with( $m, 'Error' ) ? 'notice-error' : 'notice-success'; echo "<div class=\"notice $cls is-dismissible\"><p>" . esc_html( $m ) . '</p></div>'; } }
	private static function admin() { $u = wp_get_current_user(); return $u ? $u->user_login : 'admin'; }
	private static function kpi( $l, $v, $sub = '' ) { return '<div class="rtsap-kpi"><div class="rtsap-kpi__label">' . esc_html( $l ) . '</div><div class="rtsap-kpi__value">' . esc_html( $v ) . '</div>' . ( $sub ? '<div class="rtsap-kpi__sub">' . esc_html( $sub ) . '</div>' : '' ) . '</div>'; }
	private static function section( $t ) { return '<h3 class="rtsap-section-title">' . esc_html( $t ) . '</h3>'; }
	private static function table( $heads, $rows ) { $h = '<table class="wp-list-table widefat fixed striped" style="max-width:600px"><thead><tr>'; foreach ( $heads as $x ) { $h .= '<th>' . esc_html( $x ) . '</th>'; } $h .= '</tr></thead><tbody>'; if ( ! $rows ) { $h .= '<tr><td colspan="' . count( $heads ) . '" style="color:#777">No data yet</td></tr>'; } foreach ( $rows as $r ) { $h .= '<tr>'; foreach ( $r as $c ) { $h .= '<td>' . esc_html( $c ) . '</td>'; } $h .= '</tr>'; } return $h . '</tbody></table>'; }
	private static function panel_head( $title, $eyebrow = '' ) { return '<div class="rtsap-panel__head"><div>' . ( $eyebrow ? '<span class="rtsap-panel__eyebrow">' . esc_html( $eyebrow ) . '</span>' : '' ) . '<h3>' . esc_html( $title ) . '</h3></div></div>'; }
	private static function bar_chart( $rows, $caption ) {
		if ( ! $rows ) { return '<div class="rtsap-panel__empty">No activity recorded for this period.</div>'; }
		$max = max( array_map( fn( $r ) => (float) $r->value, $rows ) );
		$html = '<div class="rtsap-chart"><div class="rtsap-bar-chart">';
		foreach ( $rows as $row ) {
			$value = (float) $row->value; $height = $max > 0 ? max( 3, round( $value / $max * 142, 1 ) ) : 3;
			$html .= '<div class="rtsap-bar-chart__item" title="' . esc_attr( $row->label . ': ' . $value ) . '"><span class="rtsap-bar-chart__value">' . esc_html( $value ) . '</span><span class="rtsap-bar-chart__bar" style="height:' . esc_attr( $height ) . 'px"></span><span class="rtsap-bar-chart__label">' . esc_html( $row->label ) . '</span></div>';
		}
		return $html . '</div></div><div class="rtsap-chart-caption">' . esc_html( $caption ) . '</div>';
	}
	private static function hbars( $rows ) {
		if ( ! $rows ) { return '<div class="rtsap-panel__empty">No data yet.</div>'; }
		$max = max( array_map( fn( $r ) => (float) $r['value'], $rows ) ); $html = '<div class="rtsap-hbars">';
		foreach ( $rows as $row ) {
			$value = (float) $row['value']; $width = $max > 0 ? max( 2, round( $value / $max * 100, 1 ) ) : 2;
			$html .= '<div class="rtsap-hbar"><span class="rtsap-hbar__label" title="' . esc_attr( $row['label'] ) . '">' . esc_html( $row['label'] ) . '</span><span class="rtsap-hbar__track"><span class="rtsap-hbar__fill" style="width:' . esc_attr( $width ) . '%"></span></span><span class="rtsap-hbar__value">' . esc_html( $value ) . '</span></div>';
		}
		return $html . '</div>';
	}
	private static function donut( $primary_label, $primary, $secondary_label, $secondary ) {
		$total = $primary + $secondary; $pct = $total ? round( $primary / $total * 100, 1 ) : 0;
		return '<div class="rtsap-donut-wrap"><div class="rtsap-donut" style="--rtsap-donut-pct:' . esc_attr( $pct ) . '%" data-center="' . esc_attr( $pct . '%' ) . '"></div><div class="rtsap-legend"><div class="rtsap-legend__item"><span class="rtsap-legend__swatch"></span><span>' . esc_html( $primary_label ) . '</span><b>' . esc_html( $primary ) . '</b></div><div class="rtsap-legend__item"><span class="rtsap-legend__swatch rtsap-legend__swatch--muted"></span><span>' . esc_html( $secondary_label ) . '</span><b>' . esc_html( $secondary ) . '</b></div></div></div>';
	}
	private static function gauge( $value, $target, $caption ) {
		$pct = $target ? min( 100, round( $value / $target * 100, 1 ) ) : 0;
		return '<div class="rtsap-gauge" style="--rtsap-gauge-pct:' . esc_attr( $pct / 2 ) . '%"><span class="rtsap-gauge__value">' . esc_html( $pct . '%' ) . '</span></div><div class="rtsap-gauge__caption">' . esc_html( $caption ) . '</div>';
	}
	private static function funnel( $stages ) {
		$base = $stages ? max( 1, (int) $stages[0]['value'] ) : 1; $html = '<div class="rtsap-funnel">';
		foreach ( $stages as $stage ) {
			$value = (int) $stage['value']; $pct = round( $value / $base * 100, 1 );
			$html .= '<div class="rtsap-funnel__row"><span class="rtsap-funnel__label">' . esc_html( $stage['label'] ) . '</span><span class="rtsap-funnel__track"><span class="rtsap-funnel__fill" style="width:' . esc_attr( $pct ) . '%"></span></span><span class="rtsap-funnel__value">' . esc_html( $value . ' · ' . $pct . '%' ) . '</span></div>';
		}
		return $html . '</div>';
	}

	// ---- Executive Dashboard v2 ----
	public static function render_exec() {
		$s = RTS_Business_Logic_4::executive_summary_v2(); $k = $s['referral_coefficient']['k'] ?? 0; $f = $s['conversion_funnel'];
		$geo = array_map( fn( $r ) => array( 'label' => $r->country ?: 'Unknown', 'value' => (int) $r->c ), $s['geographic_distribution'] );
		$marketing = array_map( fn( $r ) => array( 'label' => $r->marketing_source ?: 'Unknown', 'value' => (int) $r->c ), $s['marketing_source_breakdown'] );
		$referrers = array_map( fn( $r ) => array( 'label' => $r->label, 'value' => (int) $r->value ), $s['top_referrers'] );
		echo '<div class="wrap"><h1>Executive Dashboard — Top 20 KPIs</h1><p class="rtsap-muted">Live Run The Seas participant, survey, referral, cabin-credit and trophy data. Charts update on every page load.</p>';

		echo self::section( '1 — Growth & Volume' ) . '<div class="rtsap-kpi-grid">' . self::kpi( 'Surveys completed', $s['total_surveys_completed'] ) . self::kpi( 'Completion rate', $s['survey_completion_rate'] . '%' ) . self::kpi( 'Week-over-week growth', is_null( $s['week_over_week_growth'] ) ? '—' : $s['week_over_week_growth'] . '%', is_null( $s['week_over_week_growth'] ) ? 'Not enough prior-week history' : 'New participant registrations' ) . '</div>';
		echo '<div class="rtsap-dashboard-grid"><section class="rtsap-panel rtsap-panel--wide">' . self::panel_head( 'Survey completions', 'Last 30 days' ) . self::bar_chart( $s['daily_completions'], 'Each bar is the number of completed survey responses for that day.' ) . '</section></div>';

		echo self::section( '2 — Viral / Referral Engine' ) . '<div class="rtsap-kpi-grid">' . self::kpi( 'Referral coefficient (K)', $k, 'K > 1 is self-sustaining' ) . self::kpi( 'Verified referrals', $s['verified_referrals_total'], 'of ' . $s['total_referrals_sent'] . ' sent' ) . self::kpi( 'Avg referrals / Founding Runner', $s['avg_referrals_per_founding_runner'] ) . '</div>';
		echo '<div class="rtsap-dashboard-grid"><section class="rtsap-panel rtsap-panel--eight">' . self::panel_head( 'Verified referral velocity', 'Last 12 weeks' ) . self::bar_chart( $s['weekly_verified_referrals'], 'Verified referrals grouped by ISO week.' ) . '</section><section class="rtsap-panel rtsap-panel--four">' . self::panel_head( 'Top referrers', 'Verified referrals' ) . self::hbars( $referrers ) . '</section></div>';

		echo self::section( '3 — Audience Composition' ) . '<div class="rtsap-kpi-grid">' . self::kpi( 'Total participants', $s['total_participants'] ) . self::kpi( 'Verified participants', $s['verified_participants'], $s['email_verification_rate'] . '% verified' ) . self::kpi( 'Average travel party', $s['avg_travel_party_size'] ?? '—' ) . self::kpi( 'Cruise notifications', $s['notification_interest_total'], 'Opted-in participants' ) . '</div>';
		echo '<div class="rtsap-dashboard-grid"><section class="rtsap-panel rtsap-panel--four">' . self::panel_head( 'Runner profile', 'Audience mix' ) . self::donut( 'Runners', $s['runners_vs_non_runners']['runners'], 'Non-runners', $s['runners_vs_non_runners']['non_runners'] ) . '</section><section class="rtsap-panel rtsap-panel--four">' . self::panel_head( 'Geographic distribution', 'Top countries' ) . self::hbars( $geo ) . '</section><section class="rtsap-panel rtsap-panel--four">' . self::panel_head( 'Marketing source', 'Acquisition' ) . self::hbars( $marketing ) . '</section></div>';

		echo self::section( '4 — Demand & Cabin Credit' ) . '<div class="rtsap-kpi-grid">' . self::kpi( 'Cabin credits issued', $s['cabin_credits_issued'], 'Floor ' . $s['cabin_credit_floor'] . ' · target ' . $s['cabin_credit_target'] ) . self::kpi( 'Outstanding liability', '$' . number_format_i18n( $s['outstanding_credit_liability'] ) ) . self::kpi( 'Cost / Founding Runner', '—', 'Pending ad-spend integration' ) . '</div>';
		echo '<div class="rtsap-dashboard-grid"><section class="rtsap-panel rtsap-panel--four">' . self::panel_head( 'Cabin-credit target', 'Progress' ) . self::gauge( $s['cabin_credits_issued'], $s['cabin_credit_target'], $s['cabin_credits_issued'] . ' of ' . $s['cabin_credit_target'] . ' target credits' ) . '</section><section class="rtsap-panel rtsap-panel--eight">' . self::panel_head( 'Conversion funnel', 'Participant journey' ) . self::funnel( array( array( 'label' => 'Registered', 'value' => $f['registered'] ), array( 'label' => 'Verified', 'value' => $f['verified'] ), array( 'label' => 'Credited', 'value' => $f['credited'] ) ) ) . '</section></div>';

		echo self::section( '5 — Program Health' ) . '<div class="rtsap-kpi-grid">' . self::kpi( 'Email verification', $s['email_verification_rate'] . '%' ) . self::kpi( 'Trophies unlocked', $s['total_trophies_unlocked'] ) . self::kpi( 'Unique trophy holders', $s['unique_trophy_holders'] ) . self::kpi( 'Verified / registered', $s['verified_participants'] . ' / ' . $s['total_participants'] ) . '</div></div>';
	}

	// ---- Super Admin Dashboard (global search) ----
	public static function render_super() {
		$h = RTS_Business_Logic_4::system_health(); $q = sanitize_text_field( $_GET['q'] ?? '' );
		echo '<div class="wrap"><h1>Super Administrator Dashboard</h1>';
		echo '<form method="get" action="' . esc_url( RTSAP_Frontend_Dashboard::screen_url( 'rts-super-admin' ) ) . '">' . RTSAP_Frontend_Dashboard::screen_field( 'rts-super-admin' ) . '<input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="Global search — participants, surveys, trophies, admins, audit log…" style="width:480px"> <button class="button">Search</button></form>';
		if ( strlen( $q ) >= 2 ) {
			$r = RTS_Business_Logic_4::global_search( $q ); $any = false;
			foreach ( array( 'participants' => fn( $x ) => "$x->name — $x->email", 'surveys' => fn( $x ) => "$x->name ($x->status)", 'trophies' => fn( $x ) => $x->name, 'audit_log' => fn( $x ) => "$x->action — $x->user", 'admins' => fn( $x ) => "{$x['name']} — {$x['email']} ({$x['role']})" ) as $k => $fmt ) {
				if ( $r[ $k ] ) { $any = true; echo '<h3 style="margin:14px 0 4px;text-transform:uppercase;font-size:11px;color:#666">' . esc_html( $k ) . ' (' . count( $r[ $k ] ) . ')</h3><ul style="margin:0">'; foreach ( $r[ $k ] as $x ) { echo '<li>' . esc_html( $fmt( $x ) ) . '</li>'; } echo '</ul>'; }
			}
			if ( ! $any ) { echo '<p style="color:#777">No results.</p>'; }
		}
		echo self::section( 'SYSTEM HEALTH' ) . '<div style="display:flex;gap:12px;flex-wrap:wrap">' . self::kpi( 'Active admins', $h['active_admins'] ) . self::kpi( 'Last backup', $h['last_backup'] ? $h['last_backup']->created_at : 'Never run' ) . self::kpi( 'Audit entries', $h['total_audit_entries'] ) . self::kpi( 'WordPress', $h['wp_version'] ) . self::kpi( 'PHP', $h['php_version'] ) . '</div>';
		echo self::section( 'QUICK LINKS' ) . '<p>';
		foreach ( array( 'rts-participants' => 'Participants', 'rts-surveys' => 'Survey Administration', 'rts-trophies' => 'Trophies', 'rts-cabin-credits' => 'Cabin Credits', 'rts-referrals' => 'Referrals & Draws', 'rts-admins' => 'Administrators & Roles', 'rts-backup' => 'Backup & System', 'rts-security' => 'Security', 'rts-settings' => 'Settings & Integrations' ) as $slug => $label ) { echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '" style="margin:0 6px 6px 0">' . esc_html( $label ) . '</a>'; }
		echo '</p></div>';
	}

	// ---- Security Dashboard ----
	public static function render_security() {
		$s = RTS_Business_Logic_4::security_stats();
		echo '<div class="wrap"><h1>Security Dashboard</h1><div style="display:flex;gap:12px;flex-wrap:wrap">' . self::kpi( 'Failed logins (24h)', $s['failed_logins_24h'], 'tracked by RTS' ) . self::kpi( 'Active sessions', $s['active_sessions'], 'unexpired WordPress sessions' ) . self::kpi( 'Active administrators', $s['active_admins'] ) . self::kpi( 'Last backup', $s['last_backup'] ? $s['last_backup']->created_at : 'Never run' ) . '</div>';
		echo self::section( 'ROLE DISTRIBUTION (real WordPress roles)' ) . self::table( array( 'Role', 'Active count' ), array_map( fn( $r ) => array( $r['role'], $r['c'] ), $s['role_distribution'] ) );
		echo self::section( 'RECENT AUDIT LOG' ) . '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Module</th></tr></thead><tbody>';
		foreach ( $s['recent_audit_log'] as $a ) { echo '<tr><td>' . esc_html( $a->created_at ) . '</td><td>' . esc_html( $a->user ) . '</td><td>' . esc_html( $a->action ) . '</td><td>' . esc_html( $a->module ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	}

	// ---- Administrators & Roles (real wp_users) ----
	public static function render_admins() {
		echo '<div class="wrap"><h1>Administrator &amp; Role Management</h1>'; self::notice();
		echo '<p style="color:#666;font-size:12px;max-width:800px">These are <b>real WordPress user accounts</b> with real capabilities — unlike the Node prototype, role changes here actually change what a person can do when they log in to wp-admin.</p>';
		$sel = '<select name="role">'; foreach ( RTS_Business_Logic_4::ROLES as $slug => $d ) { $sel .= '<option value="' . esc_attr( $slug ) . '">' . esc_html( $d['label'] ) . '</option>'; } $sel .= '</select>';
		echo self::section( 'INVITE ADMINISTRATOR' ) . self::form( 'invite_admin', '<input type="text" name="name" placeholder="Name" required> <input type="email" name="email" placeholder="Email" required> ' . $sel . ' ', 'Invite', array(), 'button button-primary' );
		echo self::section( 'ADMINISTRATORS' ) . '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Name</th><th>Email</th><th>Login</th><th>Role</th><th>Change role</th><th></th></tr></thead><tbody>';
		foreach ( RTS_Business_Logic_4::list_admins() as $a ) {
			$rs = '<select name="role">'; foreach ( RTS_Business_Logic_4::ROLES as $slug => $d ) { $rs .= '<option value="' . esc_attr( $slug ) . '"' . selected( $a['role'], $slug, false ) . '>' . esc_html( $d['label'] ) . '</option>'; } $rs .= '</select> ';
			echo '<tr><td>' . esc_html( $a['name'] ) . '</td><td>' . esc_html( $a['email'] ) . '</td><td><code>' . esc_html( $a['login'] ) . '</code></td><td>' . esc_html( $a['role'] ?: '—' ) . '</td><td>' . self::form( 'change_role', $rs, 'Save', array( 'id' => $a['id'] ) ) . '</td><td>' . self::form( 'deactivate_admin', '', 'Deactivate', array( 'id' => $a['id'] ), 'button button-link-delete', 'return confirm(\'Deactivate this administrator? Their roles are stripped; the account is kept for the audit trail.\')' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
	public static function handle_invite_admin()     { self::guard( 'invite_admin' );     $r = RTS_Business_Logic_4::invite_admin( sanitize_text_field( $_POST['name'] ), sanitize_email( $_POST['email'] ), sanitize_key( $_POST['role'] ), self::admin() ); self::back( 'rts-admins', $r['error'] ? 'Error: ' . $r['error'] . ( isset( $r['message'] ) ? ' — ' . $r['message'] : '' ) : 'Invited as ' . $r['login'] . '.' ); }
	public static function handle_change_role()      { self::guard( 'change_role' );      $r = RTS_Business_Logic_4::change_role( (int) $_POST['id'], sanitize_key( $_POST['role'] ), self::admin() ); self::back( 'rts-admins', $r['error'] ? 'Error: ' . $r['error'] : 'Role updated.' ); }
	public static function handle_deactivate_admin() { self::guard( 'deactivate_admin' ); $r = RTS_Business_Logic_4::deactivate( (int) $_POST['id'], self::admin() ); self::back( 'rts-admins', $r['error'] ? 'Error: ' . $r['error'] : 'Administrator deactivated.' ); }

	// ---- Backup & System ----
	public static function render_backup() {
		echo '<div class="wrap"><h1>Backup, Security &amp; System Settings</h1>'; self::notice();
		echo RTS_Production::offline_panel_html();
		$provider = RTS_Business_Logic_4::backup_provider_status();
		$limit = absint( $_GET['backup_limit'] ?? 10 );
		$limit = in_array( $limit, array( 5, 10 ), true ) ? $limit : 10;
		$history = RTS_Business_Logic_4::backup_history( $limit );
		$ready = $provider['available'] && $provider['google_drive_ready'];
		echo self::section( 'BACKUPS' ) . '<p>';
		if ( $ready ) {
			echo self::form( 'run_backup', '', 'Run UpdraftPlus backup now', array(), 'button button-primary', "this.querySelector('button').disabled=true;this.querySelector('button').textContent='Starting…';" );
		} else {
			 echo '<button class="button button-primary" disabled>Run UpdraftPlus backup now</button> <span style="color:#b32d2e">' . esc_html( $provider['available'] ? 'Connect and select Google Drive in UpdraftPlus settings first.' : 'UpdraftPlus is not active.' ) . '</span>';
		}
		echo '</p>';
		echo '<form method="get" action="' . esc_url( RTSAP_Frontend_Dashboard::screen_url( 'rts-backup' ) ) . '" style="margin:0 0 10px">'
			. RTSAP_Frontend_Dashboard::screen_field( 'rts-backup' )
			. '<label for="rts-backup-limit">Show latest </label><select id="rts-backup-limit" name="backup_limit" onchange="this.form.submit()">'
			. '<option value="5"' . selected( $limit, 5, false ) . '>5 backups</option>'
			. '<option value="10"' . selected( $limit, 10, false ) . '>10 backups</option>'
			. '</select><noscript> <button class="button">Apply</button></noscript></form>';
		echo self::table(
			array( 'Triggered by', 'Status', 'Destination', 'Requested', 'Completed' ),
			array_map(
				fn( $b ) => array(
					$b->triggered_by,
					ucwords( str_replace( '_', ' ', $b->status ) ),
					$b->remote_storage ?: '—',
					$b->created_at,
					$b->completed_at ?: '—',
				),
				$history
			)
		);
		echo '<p style="color:#666;font-size:12px">Creates a complete UpdraftPlus files-and-database backup and sends it to the configured Google Drive destination. The list also records completed manual and automatic backups created directly in UpdraftPlus. Completed, Failed and Stopped statuses are synchronized from the UpdraftPlus job.</p>';
		if ( array_filter( $history, fn( $b ) => in_array( $b->status, array( 'queued', 'running' ), true ) ) ) {
			echo '<script>window.setTimeout(function(){ window.location.reload(); }, 10000);</script>';
		}
		echo '</div>';
	}
	public static function handle_run_backup() {
		self::guard( 'run_backup' );
		$result = RTS_Business_Logic_4::run_backup( self::admin() );
		self::back( 'rts-backup', $result['error'] ? 'Error: ' . ( $result['message'] ?? $result['error'] ) : 'Backup queued. UpdraftPlus is creating it and will upload it to Google Drive.' );
	}
}
