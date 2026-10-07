<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RTS_Business_Logic_4 {

	private static function audit( $u, $a, $m, $n = '' ) { RTS_Business_Logic::log_audit( $u ?: 'admin', $a, $m, 'success', $n ); }
	private static $updraft_backup_id = 0;
	
	const ROLES = array(
		'rts_super_admin'    => array( 'label' => 'RTS Super Administrator', 'caps' => array( 'rts_dashboard', 'rts_view', 'rts_manage', 'rts_send_bulk', 'rts_manage_admins', 'rts_system', 'rts_content', 'upload_files' ) ),
		'rts_administrator'  => array( 'label' => 'RTS Administrator',       'caps' => array( 'rts_dashboard', 'rts_view', 'rts_manage', 'rts_send_bulk', 'rts_content', 'upload_files' ) ),
		'rts_content_editor' => array( 'label' => 'RTS Content Editor',      'caps' => array( 'rts_dashboard', 'rts_content' ) ),
		'rts_contributor'    => array( 'label' => 'RTS Contributor',         'caps' => array( 'rts_dashboard' ) ),
	);

	public static function register_roles() {
		$fluent_caps = array( 'fluentform_dashboard_access', 'fluentform_forms_manager', 'fluentform_entries_viewer' );
		$media_caps = array( 'upload_files' );
		foreach ( self::ROLES as $slug => $def ) {
			$want = array_fill_keys( $def['caps'], true );
			$role = get_role( $slug );
			if ( ! $role ) { add_role( $slug, $def['label'], $want + array( 'read' => true ) ); continue; }
			foreach ( RTS_Auth::CAPS as $c ) { // sync: exactly the defined set, no more
				if ( isset( $want[ $c ] ) ) { $role->add_cap( $c ); } else { $role->remove_cap( $c ); }
			}
			// Survey operations use Fluent Forms' native editor and AJAX APIs.
			// Only roles holding rts_manage receive those native capabilities.
			foreach ( $fluent_caps as $c ) {
				if ( isset( $want['rts_manage'] ) ) { $role->add_cap( $c ); } else { $role->remove_cap( $c ); }
			}
			foreach ( $media_caps as $c ) {
				if ( isset( $want[ $c ] ) ) { $role->add_cap( $c ); } else { $role->remove_cap( $c ); }
			}
			$role->add_cap( 'read' );
		}
		// Built-in WP administrators get every RTS cap so the site owner is never locked out.
		$wp_admin = get_role( 'administrator' );
		if ( $wp_admin ) { foreach ( RTS_Auth::CAPS as $c ) { $wp_admin->add_cap( $c ); } }
	}

	public static function list_admins() {
		$users = get_users( array( 'role__in' => array_merge( array_keys( self::ROLES ), array( 'administrator' ) ), 'orderby' => 'registered', 'order' => 'DESC' ) );
		return array_map( function ( $u ) {
			$rts_roles = array_values( array_filter( $u->roles, fn( $r ) => str_starts_with( $r, 'rts_' ) || 'administrator' === $r ) );
			return array( 'id' => $u->ID, 'name' => $u->display_name, 'email' => $u->user_email, 'login' => $u->user_login, 'role' => $rts_roles[0] ?? null, 'registered' => $u->user_registered );
		}, $users );
	}

	public static function invite_admin( $name, $email, $role, $invited_by ) {
		if ( ! isset( self::ROLES[ $role ] ) ) { return array( 'error' => 'INVALID_ROLE' ); }
		if ( ! is_email( $email ) ) { return array( 'error' => 'INVALID_EMAIL' ); }
		if ( email_exists( $email ) ) { return array( 'error' => 'EMAIL_ALREADY_INVITED' ); }
		$login = sanitize_user( strstr( $email, '@', true ) ?: $email, true ); $base = $login; $i = 2;
		while ( username_exists( $login ) ) { $login = $base . $i++; }
		$uid = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'display_name' => $name, 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
		if ( is_wp_error( $uid ) ) { return array( 'error' => 'WP_ERROR', 'message' => $uid->get_error_message() ); }
		self::audit( $invited_by, "Administrator invited: $name ($role)", 'Administrator Management', "user_id=$uid" );
		return array( 'error' => null, 'admin_id' => $uid, 'login' => $login );
	}

	private static function count_super_admins_excluding( $uid ) {
		$n = 0;
		foreach ( get_users( array( 'role__in' => array( 'rts_super_admin', 'administrator' ) ) ) as $u ) { if ( (int) $u->ID !== (int) $uid ) { $n++; } }
		return $n;
	}

	// Business rule: never allow the last Super Administrator to be demoted/removed (lockout prevention).
	public static function change_role( $uid, $new_role, $by ) {
		if ( ! isset( self::ROLES[ $new_role ] ) ) { return array( 'error' => 'INVALID_ROLE' ); }
		$u = get_user_by( 'id', $uid );
		if ( ! $u ) { return array( 'error' => 'NOT_FOUND' ); }
		$is_super = in_array( 'rts_super_admin', $u->roles, true ) || in_array( 'administrator', $u->roles, true );
		if ( $is_super && 'rts_super_admin' !== $new_role && 0 === self::count_super_admins_excluding( $uid ) ) { return array( 'error' => 'CANNOT_REMOVE_LAST_SUPER_ADMIN' ); }
		$u->set_role( $new_role );
		self::audit( $by, "Administrator role changed: {$u->display_name} -> $new_role", 'Administrator Management', "user_id=$uid" );
		return array( 'error' => null );
	}

	public static function deactivate( $uid, $by ) {
		$u = get_user_by( 'id', $uid );
		if ( ! $u ) { return array( 'error' => 'NOT_FOUND' ); }
		$is_super = in_array( 'rts_super_admin', $u->roles, true ) || in_array( 'administrator', $u->roles, true );
		if ( $is_super && 0 === self::count_super_admins_excluding( $uid ) ) { return array( 'error' => 'CANNOT_DEACTIVATE_LAST_SUPER_ADMIN' ); }
		$u->set_role( '' ); // strips all roles = cannot log in to anything RTS; account kept for audit trail
		update_user_meta( $uid, 'rts_deactivated', current_time( 'mysql' ) );
		self::audit( $by, "Administrator deactivated: {$u->display_name}", 'Administrator Management', "user_id=$uid" );
		return array( 'error' => null );
	}

	// ---- Executive Dashboard — expanded Top-20 KPIs ----
	public static function executive_summary_v2() {
		global $wpdb; $pt = RTS_DB::table( 'participants' ); $rt = RTS_DB::table( 'referrals' ); $ct = RTS_DB::table( 'cabin_credits' ); $srt = RTS_DB::table( 'survey_responses' ); $tut = RTS_DB::table( 'trophy_unlocks' );
		$i = fn( $q ) => (int) $wpdb->get_var( $q );
		$completed = $i( "SELECT COUNT(*) FROM $srt WHERE status='completed'" ); $started = $i( "SELECT COUNT(*) FROM $srt" );
		$this_wk = $i( "SELECT COUNT(*) FROM $pt WHERE registered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" );
		$last_wk = $i( "SELECT COUNT(*) FROM $pt WHERE registered_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND registered_at < DATE_SUB(NOW(), INTERVAL 7 DAY)" );
		$k = RTS_Business_Logic::calculate_referral_coefficient();
		$total_ref = $i( "SELECT COUNT(*) FROM $rt" ); $ver_ref = $i( "SELECT COUNT(*) FROM $rt WHERE verified=1" );
		$total_p = $i( "SELECT COUNT(*) FROM $pt" ); $ver_p = $i( "SELECT COUNT(*) FROM $pt WHERE email_verified=1" );
		$avg_party = $wpdb->get_var( "SELECT AVG(travel_party_size) FROM $pt WHERE travel_party_size IS NOT NULL" );
		$credits = $i( "SELECT COUNT(*) FROM $ct WHERE status IN ('issued','deferred')" );
		$daily_completions = $wpdb->get_results( "SELECT DATE(completed_at) AS label, COUNT(*) AS value FROM $srt WHERE status='completed' AND completed_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(completed_at) ORDER BY label ASC" );
		$weekly_referrals = $wpdb->get_results( "SELECT DATE_FORMAT(COALESCE(verified_at, completed_date, created_at), '%x-W%v') AS label, COUNT(*) AS value FROM $rt WHERE verified=1 AND COALESCE(verified_at, completed_date, created_at) >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK) GROUP BY YEARWEEK(COALESCE(verified_at, completed_date, created_at), 3) ORDER BY YEARWEEK(COALESCE(verified_at, completed_date, created_at), 3) ASC" );
		$top_referrers = $wpdb->get_results( "SELECT COALESCE(NULLIF(p.name,''), NULLIF(p.email,''), 'Unknown') AS label, COUNT(*) AS value FROM $rt r LEFT JOIN $pt p ON p.id=COALESCE(r.referring_participant_id,r.referrer_id) WHERE r.verified=1 GROUP BY COALESCE(NULLIF(p.name,''), NULLIF(p.email,''), 'Unknown') ORDER BY value DESC LIMIT 5" );
		return array(
			'total_surveys_completed' => $completed,
			'survey_completion_rate' => $started ? round( $completed / $started * 100, 1 ) : 0,
			'week_over_week_growth' => $last_wk ? round( ( $this_wk - $last_wk ) / $last_wk * 100, 1 ) : null,
			'referral_coefficient' => $k,
			'verified_referrals_total' => $ver_ref, 'total_referrals_sent' => $total_ref,
			'avg_referrals_per_founding_runner' => $ver_p ? round( $total_ref / $ver_p, 2 ) : 0,
			'total_participants' => $total_p, 'verified_participants' => $ver_p,
			'runners_vs_non_runners' => array( 'runners' => $i( "SELECT COUNT(*) FROM $pt WHERE runner_status='runner'" ), 'non_runners' => $i( "SELECT COUNT(*) FROM $pt WHERE runner_status='non_runner'" ) ),
			'avg_travel_party_size' => $avg_party ? round( (float) $avg_party, 1 ) : null,
			'geographic_distribution' => $wpdb->get_results( "SELECT country, COUNT(*) AS c FROM $pt WHERE country IS NOT NULL GROUP BY country ORDER BY c DESC LIMIT 10" ),
			'marketing_source_breakdown' => $wpdb->get_results( "SELECT marketing_source, COUNT(*) AS c FROM $pt WHERE marketing_source IS NOT NULL GROUP BY marketing_source ORDER BY c DESC" ),
			'cabin_credits_issued' => $credits,
			'cabin_credit_floor' => 400, 'cabin_credit_target' => 500,
			'conversion_funnel' => array( 'registered' => $total_p, 'verified' => $ver_p, 'credited' => $i( "SELECT COUNT(*) FROM $ct" ) ),
			'cost_per_founding_runner' => null, // pending ad-spend integration (Batch 5)
			'email_verification_rate' => $total_p ? round( $ver_p / $total_p * 100, 1 ) : 0,
			'notification_interest_total' => $i( "SELECT COUNT(*) FROM $pt WHERE wants_cruise_notification=1" ),
			'total_trophies_unlocked' => $i( "SELECT COUNT(*) FROM $tut" ),
			'unique_trophy_holders' => $i( "SELECT COUNT(DISTINCT participant_id) FROM $tut" ),
			'outstanding_credit_liability' => $credits * 100,
			'daily_completions' => $daily_completions,
			'weekly_verified_referrals' => $weekly_referrals,
			'top_referrers' => $top_referrers,
		);
	}

	// ---- Global search across every searchable module ----
	public static function global_search( $q ) {
		global $wpdb;
		$q = trim( (string) $q );
		$empty = array( 'participants' => array(), 'surveys' => array(), 'trophies' => array(), 'audit_log' => array(), 'admins' => array() );
		if ( strlen( $q ) < 2 ) { return $empty; }
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		return array(
			'participants' => $wpdb->get_results( $wpdb->prepare( "SELECT id, name, email, founding_runner_number FROM " . RTS_DB::table( 'participants' ) . " WHERE name LIKE %s OR email LIKE %s OR founding_runner_number LIKE %s LIMIT 10", $like, $like, $like ) ),
			'surveys'      => $wpdb->get_results( $wpdb->prepare( "SELECT id, name, status FROM " . RTS_DB::table( 'surveys' ) . " WHERE name LIKE %s LIMIT 10", $like ) ),
			'trophies'     => $wpdb->get_results( $wpdb->prepare( "SELECT id, name, unlock_rule FROM " . RTS_DB::table( 'trophies' ) . " WHERE name LIKE %s LIMIT 10", $like ) ),
			'audit_log'    => $wpdb->get_results( $wpdb->prepare( "SELECT id, user, action, module, created_at FROM " . RTS_DB::table( 'audit_log' ) . " WHERE action LIKE %s OR user LIKE %s OR module LIKE %s ORDER BY created_at DESC LIMIT 10", $like, $like, $like ) ),
			'admins'       => array_values( array_filter( self::list_admins(), fn( $a ) => stripos( $a['name'], $q ) !== false || stripos( $a['email'], $q ) !== false ) ),
		);
	}

	// ---- Backups ----
	public static function init_backup_integration() {
		add_action( 'rtsap_run_updraft_backup', array( __CLASS__, 'start_updraft_backup' ), 10, 2 );
		add_filter( 'updraftplus_initial_jobdata', array( __CLASS__, 'tag_updraft_source' ), 20, 1 );
		add_filter( 'updraftplus_backup_complete', array( __CLASS__, 'updraft_backup_complete' ) );
		add_action( 'updraft_backup_resume', array( __CLASS__, 'after_updraft_resume' ), 999, 3 );
		add_action( 'admin_init', array( __CLASS__, 'watch_for_updraft_stop_request' ), 1 );
	}
	
	public static function tag_updraft_source( $jobdata ) {
		if ( ! is_array( $jobdata ) ) { return $jobdata; }

		$values = array();
		for ( $i = 0, $count = count( $jobdata ); $i + 1 < $count; $i += 2 ) {
			if ( is_string( $jobdata[ $i ] ) ) { $values[ $jobdata[ $i ] ] = $jobdata[ $i + 1 ]; }
		}

		// Jobs launched by RTS already have an exact database row and administrator.
		if ( ! empty( $values['rtsap_backup_id'] ) || isset( $values['rtsap_backup_source'] ) ) { return $jobdata; }

		$automatic = ! empty( $values['is_scheduled_backup'] ) || ! empty( $values['is_autobackup'] );
		$user = wp_get_current_user();
		$triggered_by = $automatic
			? 'UpdraftPlus schedule'
			: ( $user && $user->exists() ? $user->user_login . ' (UpdraftPlus manual)' : 'UpdraftPlus manual' );

		array_push( $jobdata, 'rtsap_backup_source', $automatic ? 'automatic' : 'manual' );
		array_push( $jobdata, 'rtsap_triggered_by', $triggered_by );
		return $jobdata;
	}

	/**
	 * UpdraftPlus has no public action that fires when its Stop link succeeds.
	 * Watch its authenticated AJAX request and reconcile after UpdraftPlus has
	 * created the delete flag and removed the next resume event.
	 */
	public static function watch_for_updraft_stop_request() {
		if ( ! wp_doing_ajax() || 'updraft_ajax' !== sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) ) || 'activejobs_delete' !== sanitize_key( wp_unslash( $_REQUEST['subaction'] ?? '' ) ) ) {
			return;
		}

		$job_id = sanitize_key( wp_unslash( $_POST['action_data'] ?? '' ) );
		$nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) );
		if ( ! preg_match( '/^[0-9a-f]{12}$/', $job_id ) || ! wp_verify_nonce( $nonce, 'updraftplus-credentialtest-nonce' ) ) {
			return;
		}
		if ( class_exists( 'UpdraftPlus_Options' ) && ! UpdraftPlus_Options::user_can_manage() ) {
			return;
		}

		register_shutdown_function( array( __CLASS__, 'reconcile_updraft_job' ), $job_id );
	}

	public static function backup_provider_status() {
		global $updraftplus;
		$available = is_object( $updraftplus ) && method_exists( $updraftplus, 'backup_all' ) && class_exists( 'UpdraftPlus_Options' );
		$services = $available ? UpdraftPlus_Options::get_updraft_option( 'updraft_service' ) : array();
		$services = is_array( $services ) ? $services : array( $services );
		$services = array_values( array_filter( $services ) );
		return array(
			'available'          => $available,
			'google_drive_ready' => $available && in_array( 'googledrive', $services, true ),
			'services'           => $services,
		);
	}

	public static function run_backup( $by ) {
		global $wpdb, $updraftplus;
		$provider = self::backup_provider_status();
		if ( ! $provider['available'] ) {
			return array( 'error' => 'UPDRAFTPLUS_NOT_AVAILABLE', 'message' => 'UpdraftPlus must be installed and active.' );
		}
		if ( ! $provider['google_drive_ready'] ) {
			return array( 'error' => 'GOOGLE_DRIVE_NOT_CONFIGURED', 'message' => 'Select and connect Google Drive in UpdraftPlus settings first.' );
		}

		$nonce = $updraftplus->backup_time_nonce();
		$inserted = $wpdb->insert(
			RTS_DB::table( 'backups' ),
			array(
				'triggered_by'    => $by ?: 'admin',
				'status'          => 'queued',
				'provider'        => 'updraftplus',
				'provider_job_id' => $nonce,
				'remote_storage'  => 'Google Drive',
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return array( 'error' => 'BACKUP_LOG_FAILED', 'message' => 'The backup request could not be recorded.' );
		}

		$id = (int) $wpdb->insert_id;
		$scheduled = wp_schedule_single_event( time(), 'rtsap_run_updraft_backup', array( $id, $nonce ), true );
		if ( is_wp_error( $scheduled ) || ! $scheduled ) {
			$message = is_wp_error( $scheduled ) ? $scheduled->get_error_message() : 'WordPress could not queue the backup job.';
			self::mark_backup_failed( $id, $message );
			return array( 'error' => 'BACKUP_QUEUE_FAILED', 'message' => $message, 'backup_id' => $id );
		}

		self::audit( $by, 'UpdraftPlus backup queued', 'Backup & System Settings', "backup_id=$id; destination=Google Drive; job=$nonce" );
		if ( function_exists( 'spawn_cron' ) ) { spawn_cron( time() ); }
		return array( 'error' => null, 'backup_id' => $id, 'status' => 'queued', 'provider_job_id' => $nonce );
	}

	public static function tag_updraft_job( $jobdata ) {
		if ( self::$updraft_backup_id ) { array_push( $jobdata, 'rtsap_backup_id', self::$updraft_backup_id ); }
		return $jobdata;
	}

	public static function start_updraft_backup( $backup_id, $nonce ) {
		global $wpdb, $updraftplus;
		$backup_id = absint( $backup_id );
		$nonce = sanitize_key( $nonce );
		if ( ! $backup_id || ! $nonce ) { return; }

		$provider = self::backup_provider_status();
		if ( ! $provider['available'] || ! $provider['google_drive_ready'] ) {
			self::mark_backup_failed( $backup_id, 'UpdraftPlus or its Google Drive destination is unavailable.' );
			return;
		}

		$table = RTS_DB::table( 'backups' );
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status = 'running', started_at = NOW() WHERE id = %d", $backup_id ) );
		self::$updraft_backup_id = $backup_id;
		add_filter( 'updraftplus_initial_jobdata', array( __CLASS__, 'tag_updraft_job' ), 10, 1 );

		try {
			$result = $updraftplus->backup_all(
				array(
					'nocloud'  => false,
					'use_nonce' => $nonce,
					'label'     => 'RTS Admin backup #' . $backup_id,
				)
			);
			if ( false === $result ) {
				self::mark_backup_failed( $backup_id, 'UpdraftPlus could not start the backup. Check its latest log.' );
			} elseif ( $updraftplus->error_count() > 0 && empty( $updraftplus->newresumption_scheduled ) ) {
				self::mark_backup_failed( $backup_id, 'UpdraftPlus finished with errors. Check its latest log.' );
			}
		} catch ( Throwable $error ) {
			self::mark_backup_failed( $backup_id, $error->getMessage() );
		} finally {
			remove_filter( 'updraftplus_initial_jobdata', array( __CLASS__, 'tag_updraft_job' ), 10 );
			self::$updraft_backup_id = 0;
		}
	}

	public static function updraft_backup_complete( $delete_jobdata ) {
		global $wpdb, $updraftplus;
		if ( ! is_object( $updraftplus ) ) { return $delete_jobdata; }

		$backup_id = absint( $updraftplus->jobdata_get( 'rtsap_backup_id' ) );
		$job_id = sanitize_key( (string) $updraftplus->nonce );
		$table = RTS_DB::table( 'backups' );
		$completed_at = current_time( 'mysql' );

		if ( $backup_id ) {
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status = 'completed', completed_at = %s, details = %s WHERE id = %d AND status <> 'completed'", $completed_at, 'UpdraftPlus completed the backup and remote upload.', $backup_id ) );
			if ( ! $changed ) { return $delete_jobdata; }
			$backup = $wpdb->get_row( $wpdb->prepare( "SELECT triggered_by, provider_job_id, remote_storage FROM $table WHERE id = %d", $backup_id ) );
			self::audit( $backup ? $backup->triggered_by : 'system', 'UpdraftPlus backup completed', 'Backup & System Settings', "backup_id=$backup_id; destination=" . ( $backup ? $backup->remote_storage : '' ) . '; job=' . ( $backup ? $backup->provider_job_id : '' ) );
			return $delete_jobdata;
		}

		// This is a backup started directly by UpdraftPlus (manual or scheduled).
		if ( ! preg_match( '/^[0-9a-f]{12}$/', $job_id ) ) { return $delete_jobdata; }
		$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE provider = 'updraftplus' AND provider_job_id = %s ORDER BY id DESC LIMIT 1", $job_id ) );
		$source = sanitize_key( (string) $updraftplus->jobdata_get( 'rtsap_backup_source' ) );
		if ( ! in_array( $source, array( 'automatic', 'manual' ), true ) ) {
			$source = $updraftplus->jobdata_get( 'is_scheduled_backup' ) || $updraftplus->jobdata_get( 'is_autobackup' ) ? 'automatic' : 'manual';
		}
		$triggered_by = sanitize_text_field( (string) $updraftplus->jobdata_get( 'rtsap_triggered_by' ) );
		if ( ! $triggered_by ) { $triggered_by = 'automatic' === $source ? 'UpdraftPlus schedule' : 'UpdraftPlus manual'; }
		$remote_storage = self::updraft_remote_storage_label( $updraftplus->jobdata_get( 'service' ) );
		$requested_at = self::updraft_timestamp_to_mysql( absint( $updraftplus->jobdata_get( 'backup_time' ) ) );
		$details = 'Recorded from an UpdraftPlus ' . $source . ' backup.';
		$recorded = false;

		if ( $existing_id ) {
			$recorded = (bool) $wpdb->query(
				$wpdb->prepare(
					"UPDATE $table SET triggered_by = %s, status = 'completed', remote_storage = %s, completed_at = %s, details = %s WHERE id = %d",
					$triggered_by,
					$remote_storage,
					$completed_at,
					$details,
					$existing_id
				)
			);
			$backup_id = $existing_id;
		} else {
			$recorded = false !== $wpdb->insert(
				$table,
				array(
					'triggered_by'    => $triggered_by,
					'status'          => 'completed',
					'provider'        => 'updraftplus',
					'provider_job_id' => $job_id,
					'remote_storage'  => $remote_storage,
					'started_at'      => $requested_at,
					'completed_at'    => $completed_at,
					'details'         => $details,
					'created_at'      => $requested_at,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			$backup_id = (int) $wpdb->insert_id;
		}

		if ( $recorded ) {
			self::audit( $triggered_by, 'UpdraftPlus ' . $source . ' backup recorded', 'Backup & System Settings', "backup_id=$backup_id; destination=$remote_storage; job=$job_id" );
		}
		return $delete_jobdata;
	}

	public static function after_updraft_resume( $resumption, $nonce, $unused = null ) {
		global $updraftplus;
		$jobdata = get_site_option( 'updraft_jobdata_' . sanitize_key( $nonce ), array() );
		$backup_id = is_array( $jobdata ) ? absint( $jobdata['rtsap_backup_id'] ?? 0 ) : 0;
		if ( $backup_id && is_object( $updraftplus ) && $updraftplus->error_count() > 0 && empty( $updraftplus->newresumption_scheduled ) ) {
			self::mark_backup_failed( $backup_id, 'UpdraftPlus finished with errors. Check its latest log.' );
		}
	}

	private static function mark_backup_failed( $backup_id, $details ) {
		global $wpdb;
		$table = RTS_DB::table( 'backups' );
		$details = mb_substr( sanitize_text_field( (string) $details ), 0, 1000 );
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status = 'failed', completed_at = NOW(), details = %s WHERE id = %d AND status NOT IN ('completed', 'failed', 'stopped')", $details, absint( $backup_id ) ) );
		if ( ! $changed ) { return; }
		$backup = $wpdb->get_row( $wpdb->prepare( "SELECT triggered_by, provider_job_id FROM $table WHERE id = %d", absint( $backup_id ) ) );
		RTS_Business_Logic::log_audit( $backup ? $backup->triggered_by : 'system', 'UpdraftPlus backup failed', 'Backup & System Settings', 'failed', "backup_id=" . absint( $backup_id ) . '; job=' . ( $backup ? $backup->provider_job_id : '' ) . '; ' . $details );
	}

	/** Reconcile an RTS backup row with UpdraftPlus's cancellation markers. */
	public static function reconcile_updraft_job( $job_id ) {
		global $wpdb, $updraftplus;
		$job_id = sanitize_key( (string) $job_id );
		if ( ! preg_match( '/^[0-9a-f]{12}$/', $job_id ) ) { return; }

		$table = RTS_DB::table( 'backups' );
		$backup = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $table WHERE provider = 'updraftplus' AND provider_job_id = %s AND status IN ('queued', 'running') ORDER BY id DESC LIMIT 1", $job_id ) );
		if ( ! $backup || ! is_object( $updraftplus ) || ! method_exists( $updraftplus, 'backups_dir_location' ) ) { return; }

		$directory = $updraftplus->backups_dir_location();
		$delete_flag = trailingslashit( $directory ) . 'deleteflag-' . $job_id . '.txt';
		$log_file = trailingslashit( $directory ) . 'log.' . $job_id . '.txt';
		if ( file_exists( $delete_flag ) || self::updraft_log_shows_abort( $log_file ) ) {
			self::mark_backup_stopped( (int) $backup->id, 'Stopped by an administrator in UpdraftPlus.' );
		}
	}

	private static function updraft_log_shows_abort( $log_file ) {
		if ( ! is_readable( $log_file ) ) { return false; }
		$handle = fopen( $log_file, 'rb' );
		if ( false === $handle ) { return false; }
		$size = filesize( $log_file );
		if ( $size > 131072 ) { fseek( $handle, -131072, SEEK_END ); }
		$tail = stream_get_contents( $handle );
		fclose( $handle );
		return false !== stripos( (string) $tail, 'User request for abort: backup job will be immediately halted' )
			|| false !== stripos( (string) $tail, 'The backup was aborted by the user' );
	}

	private static function mark_backup_stopped( $backup_id, $details ) {
		global $wpdb;
		$table = RTS_DB::table( 'backups' );
		$details = mb_substr( sanitize_text_field( (string) $details ), 0, 1000 );
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status = 'stopped', completed_at = NOW(), details = %s WHERE id = %d AND status IN ('queued', 'running')", $details, absint( $backup_id ) ) );
		if ( ! $changed ) { return; }
		$backup = $wpdb->get_row( $wpdb->prepare( "SELECT triggered_by, provider_job_id FROM $table WHERE id = %d", absint( $backup_id ) ) );
		RTS_Business_Logic::log_audit( $backup ? $backup->triggered_by : 'system', 'UpdraftPlus backup stopped', 'Backup & System Settings', 'stopped', "backup_id=" . absint( $backup_id ) . '; job=' . ( $backup ? $backup->provider_job_id : '' ) );
	}

	private static function reconcile_backup_statuses() {
		global $wpdb;
		$jobs = $wpdb->get_col( "SELECT provider_job_id FROM " . RTS_DB::table( 'backups' ) . " WHERE provider = 'updraftplus' AND status IN ('queued', 'running') AND provider_job_id IS NOT NULL" );
		foreach ( $jobs as $job_id ) { self::reconcile_updraft_job( $job_id ); }
	}

	private static function updraft_timestamp_to_mysql( $timestamp ) {
		return $timestamp ? wp_date( 'Y-m-d H:i:s', $timestamp ) : current_time( 'mysql' );
	}

	private static function updraft_remote_storage_label( $services ) {
		global $updraftplus;
		$labels = array();
		foreach ( (array) $services as $service ) {
			$service = sanitize_key( (string) $service );
			if ( ! $service || 'none' === $service ) { continue; }
			$labels[] = is_object( $updraftplus ) && isset( $updraftplus->backup_methods[ $service ] )
				? $updraftplus->backup_methods[ $service ]
				: ucwords( str_replace( array( '-', '_' ), ' ', $service ) );
		}
		return $labels ? implode( ', ', array_unique( $labels ) ) : 'Local storage';
	}

	/** Import recent successful UpdraftPlus artifacts that pre-date this integration. */
	private static function sync_updraft_backup_history( $limit = 10 ) {
		global $wpdb, $updraftplus;
		if ( ! class_exists( 'UpdraftPlus_Backup_History' ) || ! is_object( $updraftplus ) ) { return; }

		$history = UpdraftPlus_Backup_History::get_history();
		if ( ! is_array( $history ) ) { return; }
		$history = array_slice( $history, 0, max( 10, absint( $limit ) ), true );
		$table = RTS_DB::table( 'backups' );

		foreach ( $history as $timestamp => $backup ) {
			$job_id = sanitize_key( (string) ( $backup['nonce'] ?? '' ) );
			if ( ! preg_match( '/^[0-9a-f]{12}$/', $job_id ) ) { continue; }
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE provider = 'updraftplus' AND provider_job_id = %s LIMIT 1", $job_id ) );
			if ( $exists ) { continue; }

			// A history entry can be written before a multi-resumption job has finished.
			$jobdata = method_exists( $updraftplus, 'jobdata_getarray' ) ? $updraftplus->jobdata_getarray( $job_id ) : array();
			if ( is_array( $jobdata ) && ! empty( $jobdata ) && 'finished' !== ( $jobdata['jobstatus'] ?? '' ) ) { continue; }

			$label = sanitize_text_field( (string) ( $backup['label'] ?? '' ) );
			if ( ! empty( $backup['autobackup'] ) ) {
				$triggered_by = 'UpdraftPlus automatic';
			} elseif ( str_starts_with( $label, 'RTS Admin backup #' ) ) {
				$triggered_by = 'RTS Admin button (existing)';
			} else {
				$triggered_by = 'UpdraftPlus (existing; type unknown)';
			}

			$backup_time = self::updraft_timestamp_to_mysql( absint( $timestamp ) );
			$wpdb->insert(
				$table,
				array(
					'triggered_by'    => $triggered_by,
					'status'          => 'completed',
					'provider'        => 'updraftplus',
					'provider_job_id' => $job_id,
					'remote_storage'  => self::updraft_remote_storage_label( $backup['service'] ?? array() ),
					'started_at'      => $backup_time,
					'completed_at'    => $backup_time,
					'details'         => 'Imported from UpdraftPlus backup history; the original completion time and trigger type may no longer be available.',
					'created_at'      => $backup_time,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	public static function backup_history( $limit = 10 ) {
		global $wpdb;
		$limit = in_array( absint( $limit ), array( 5, 10 ), true ) ? absint( $limit ) : 10;
		self::reconcile_backup_statuses();
		self::sync_updraft_backup_history( $limit );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . RTS_DB::table( 'backups' ) . " ORDER BY created_at DESC, id DESC LIMIT %d", $limit ) );
	}
	public static function last_backup() {
		global $wpdb;
		self::sync_updraft_backup_history( 10 );
		return $wpdb->get_row( "SELECT * FROM " . RTS_DB::table( 'backups' ) . " WHERE status = 'completed' ORDER BY COALESCE(completed_at, created_at) DESC LIMIT 1" );
	}

	// ---- Security Dashboard — WordPress-native authentication metrics ----
	public static function init_security_monitor() {
		add_action( 'wp_login_failed', array( __CLASS__, 'record_failed_login' ), 10, 2 );
		add_action( 'wp_login', array( __CLASS__, 'clear_session_count_cache' ), 10, 0 );
		add_action( 'wp_logout', array( __CLASS__, 'clear_session_count_cache' ), 10, 0 );
	}

	/** Record a failed WordPress authentication without retaining passwords or error messages. */
	public static function record_failed_login( $username, $error = null ) {
		$codes = is_wp_error( $error ) ? $error->get_error_codes() : array();
		RTS_Business_Logic::log_audit(
			mb_substr( sanitize_text_field( (string) $username ), 0, 100 ),
			'Failed login',
			'Authentication',
			'failed',
			$codes ? 'codes=' . implode( ',', array_map( 'sanitize_key', $codes ) ) : ''
		);
		delete_transient( 'rtsap_failed_logins_24h' );

		// Keep useful incident history without allowing brute-force traffic to
		// grow the shared audit table forever.
		if ( false === get_transient( 'rtsap_auth_log_pruned' ) ) {
			set_transient( 'rtsap_auth_log_pruned', 1, DAY_IN_SECONDS );
			global $wpdb;
			$table = RTS_DB::table( 'audit_log' );
			$wpdb->query( "DELETE FROM $table WHERE module = 'Authentication' AND action = 'Failed login' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
		}
	}

	public static function clear_session_count_cache() {
		delete_transient( 'rtsap_active_sessions' );
	}

	private static function failed_logins_24h() {
		$cached = get_transient( 'rtsap_failed_logins_24h' );
		if ( false !== $cached ) { return (int) $cached; }

		global $wpdb;
		$table = RTS_DB::table( 'audit_log' );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE module = 'Authentication' AND action = 'Failed login' AND result = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)" );
		set_transient( 'rtsap_failed_logins_24h', $count, MINUTE_IN_SECONDS );
		return $count;
	}

	/** Count unexpired sessions from WordPress core's session-token store. */
	private static function active_sessions() {
		$cached = get_transient( 'rtsap_active_sessions' );
		if ( false !== $cached ) { return (int) $cached; }

		global $wpdb;
		$stored_sessions = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
			'session_tokens'
		) );
		$now = time();
		$count = 0;
		foreach ( $stored_sessions as $stored ) {
			$sessions = maybe_unserialize( $stored );
			if ( ! is_array( $sessions ) ) { continue; }
			foreach ( $sessions as $session ) {
				if ( is_array( $session ) && absint( $session['expiration'] ?? 0 ) > $now ) { $count++; }
			}
		}

		set_transient( 'rtsap_active_sessions', $count, MINUTE_IN_SECONDS );
		return $count;
	}

	public static function security_stats() {
		global $wpdb;
		$dist = array();
		foreach ( array_merge( array_keys( self::ROLES ), array( 'administrator' ) ) as $slug ) { $n = count( get_users( array( 'role' => $slug, 'fields' => 'ID' ) ) ); if ( $n ) { $dist[] = array( 'role' => $slug, 'c' => $n ); } }
		return array(
			'role_distribution' => $dist,
			'active_admins'     => array_sum( array_column( $dist, 'c' ) ),
			'last_backup'       => self::last_backup(),
			'recent_audit_log'  => $wpdb->get_results( "SELECT * FROM " . RTS_DB::table( 'audit_log' ) . " ORDER BY created_at DESC LIMIT 15" ),
			'failed_logins_24h' => self::failed_logins_24h(),
			'active_sessions'   => self::active_sessions(),
		);
	}

	public static function system_health() {
		global $wpdb;
		return array( 'active_admins' => array_sum( array_column( self::security_stats()['role_distribution'], 'c' ) ), 'last_backup' => self::last_backup(), 'total_audit_entries' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . RTS_DB::table( 'audit_log' ) ), 'wp_version' => get_bloginfo( 'version' ), 'php_version' => PHP_VERSION );
	}
}
