<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RTS_Business_Logic {

	private static $marketing_categories = array( 'survey', 'referral', 'trophy', 'general' );

	public static function log_audit( $user, $action, $module, $result = 'success', $notes = '' ) {
		global $wpdb;
		$ip_address = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidate = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) { $ip_address = $candidate; }
		}
		$wpdb->insert( RTS_DB::table( 'audit_log' ), array(
			'user' => $user, 'action' => $action, 'module' => $module,
			'ip_address' => $ip_address, 'result' => $result, 'notes' => $notes,
		) );
	}

	private static function gen_code( $len = 8 ) {
		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$out = '';
		for ( $i = 0; $i < $len; $i++ ) { $out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ]; }
		return $out;
	}

	// ---- Registration & Verification ----

	public static function register_participant( $data ) {
		global $wpdb;
		$table = RTS_DB::table( 'participants' );
		if ( empty( $data['email'] ) || ! is_email( $data['email'] ) ) { return array( 'error' => 'INVALID_EMAIL' ); } // defense in depth — REST/forms adapter validate too

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE email = %s", $data['email'] ) );
		if ( $existing ) {
			return array( 'error' => 'DUPLICATE_EMAIL', 'participant' => $existing );
		}

		$referred_by_participant_id = null;
		if ( ! empty( $data['referred_by_code'] ) ) {
			$referrer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE referral_code = %s", $data['referred_by_code'] ) );
			if ( $referrer ) { $referred_by_participant_id = $referrer->id; }
		}

		$token = self::gen_code( 16 ); // verification token: 16 chars, 32-char alphabet (~1.2e24); /verify is also rate-limited
		$my_referral_code = 'RTS-' . self::gen_code();
		$unsub_token = self::gen_code( 16 );
		$registration_country = $data['registration_country'] ?? $data['country'] ?? null;
		$detected_country = $data['detected_country'] ?? null;
		$effective_country = $detected_country ?: $registration_country;

		$wpdb->insert( $table, array(
			'name' => $data['name'] ?? null,
			'first_name' => trim( strtok( $data['name'] ?? '', ' ' ) ?: '' ),
			'last_name' => trim( strstr( $data['name'] ?? '', ' ' ) ?: '' ),
			'email' => $data['email'],
			'registration_date' => current_time( 'mysql' ),
			'created_at' => current_time( 'mysql' ),
			'verification_token' => $token,
			'verification_sent_at' => current_time( 'mysql' ),
			'referral_code' => $my_referral_code,
			'unsubscribe_token' => $unsub_token,
			'runner_status' => $data['runner_status'] ?? null,
			'country' => $effective_country,
			'registration_country' => $registration_country,
			'detected_country' => $detected_country,
			'province' => $data['province'] ?? null,
			'registration_province' => $data['registration_province'] ?? $data['province'] ?? null,
			'city' => $data['city'] ?? null,
			'postal_code' => $data['postal_code'] ?? null,
			'address' => $data['address'] ?? null,
			'address_2' => $data['address_2'] ?? null,
			'phone' => $data['phone'] ?? null,
			'date_of_birth' => $data['date_of_birth'] ?? null,
			'gender' => $data['gender'] ?? null,
			'age_range' => $data['age_range'] ?? null,
			'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
			'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
			'marketing_consent' => ! empty( $data['marketing_consent'] ) ? 1 : 0,
			'travel_party_size' => $data['travel_party_size'] ?? null,
			'household_income_bracket' => $data['household_income_bracket'] ?? null,
			'marketing_source' => $data['marketing_source'] ?? null,
			'utm_campaign' => $data['utm_campaign'] ?? null,
			'referred_by_participant_id' => $referred_by_participant_id,
		) );
		$participant_id = $wpdb->insert_id;

		// Seed subscriptions — every participant starts subscribed to all four categories.
		foreach ( self::$marketing_categories as $cat ) {
			$wpdb->insert( RTS_DB::table( 'subscriptions' ), array(
				'participant_id' => $participant_id, 'category' => $cat, 'subscribed' => 1,
			) );
		}

		if ( $referred_by_participant_id ) {
			$wpdb->insert( RTS_DB::table( 'referrals' ), array(
				'referrer_id' => $referred_by_participant_id,
				'referring_participant_id' => $referred_by_participant_id,
				'referred_participant_id' => $participant_id,
				'referred_email' => $data['email'],
				'referral_code' => $data['referred_by_code'],
				'status' => 'pending',
				'referral_date' => current_time( 'mysql' ),
				'created_at' => current_time( 'mysql' ),
			) );
		}

		self::log_audit( 'system', 'Participant registered', 'Participants', 'success', 'email=' . $data['email'] );

		return array( 'error' => null, 'participant_id' => $participant_id, 'verification_token' => $token, 'referral_code' => $my_referral_code, 'unsubscribe_token' => $unsub_token );
	}

	public static function verify_email( $token ) {
		global $wpdb;
		$table = RTS_DB::table( 'participants' );
		$participant = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE verification_token = %s", $token ) );
		if ( ! $participant ) { return array( 'error' => 'INVALID_TOKEN' ); }
		if ( $participant->email_verified ) { return array( 'error' => null, 'already_verified' => true, 'participant' => $participant ); }

		$frn = '#' . str_pad( (string) absint( $participant->id ), 7, '0', STR_PAD_LEFT );
		$wpdb->update( $table, array(
			'email_verified' => 1, 'verified_at' => current_time( 'mysql' ), 'founding_runner_number' => $frn,
		), array( 'id' => $participant->id ) );

		self::log_audit( 'system', 'Email verified', 'Email Verification', 'success', 'participant_id=' . $participant->id );

		$credit_result = self::issue_cabin_credit( $participant->id );

		if ( $participant->referred_by_participant_id ) {
			self::complete_referral( $participant->id );
		}

		self::unlock_trophy( $participant->id, 'founding_runner' );

		$participant->email_verified = 1;
		$participant->founding_runner_number = $frn;
		return array( 'error' => null, 'participant' => $participant, 'credit_result' => $credit_result );
	}

	// ---- Cabin Credit ----

	public static function issue_cabin_credit( $participant_id ) {
		global $wpdb;
		$table = RTS_DB::table( 'cabin_credits' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE participant_id = %d", $participant_id ) );
		if ( $existing ) { return array( 'error' => 'ALREADY_ISSUED', 'credit' => $existing ); }
		$wpdb->insert( $table, array( 'participant_id' => $participant_id, 'status' => 'issued', 'value_usd' => 100.00 ) );
		self::log_audit( 'system', 'Cabin Credit issued', 'Cabin Credit Management', 'success', 'participant_id=' . $participant_id );
		return array( 'error' => null, 'credit_id' => $wpdb->insert_id );
	}

	public static function void_all_outstanding_credits( $admin_user, $reason ) {
		global $wpdb;
		$table = RTS_DB::table( 'cabin_credits' );
		$outstanding = $wpdb->get_results( "SELECT * FROM $table WHERE status = 'issued'" );
		foreach ( $outstanding as $row ) {
			$wpdb->update( $table, array( 'status' => 'void' ), array( 'id' => $row->id ) );
		}
		self::log_audit( $admin_user, 'Bulk void all outstanding Cabin Credits', 'Cabin Credit Management', 'success', 'count=' . count( $outstanding ) . '; reason=' . $reason );
		return array( 'voided_count' => count( $outstanding ), 'total_value' => count( $outstanding ) * 100 );
	}

	// ---- Referrals ----

	private static function complete_referral( $referred_participant_id ) {
		global $wpdb;
		$rtable = RTS_DB::table( 'referrals' );
		$ptable = RTS_DB::table( 'participants' );

		$referral = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $rtable WHERE referred_participant_id = %d AND verified = 0", $referred_participant_id ) );
		if ( ! $referral ) { return null; }

		$referring = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ptable WHERE id = %d", $referral->referring_participant_id ) );
		$referred = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ptable WHERE id = %d", $referred_participant_id ) );

		// Business rule: self-referrals do not qualify.
		if ( $referring->email === $referred->email ) {
			$wpdb->update( $rtable, array( 'fraud_review_status' => 'rejected' ), array( 'id' => $referral->id ) );
			self::log_audit( 'system', 'Referral auto-rejected (self-referral)', 'Fraud Detection', 'success', 'referral_id=' . $referral->id );
			return null;
		}

		$wpdb->update( $rtable, array( 'verified' => 1, 'verified_at' => current_time( 'mysql' ) ), array( 'id' => $referral->id ) );
		self::log_audit( 'system', 'Referral verified', 'Referral Management', 'success', 'referral_id=' . $referral->id );

		$verified_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $rtable WHERE referring_participant_id = %d AND verified = 1 AND fraud_review_status = 'clear'",
			$referral->referring_participant_id
		) );
		if ( 1 === $verified_count ) { self::unlock_trophy( $referral->referring_participant_id, 'first_referral' ); }
		if ( $verified_count >= 42 ) { self::unlock_trophy( $referral->referring_participant_id, 'referrals_42' ); }

		return $referral;
	}

	// ---- Trophies ----

	public static function unlock_trophy( $participant_id, $unlock_rule_key ) {
		global $wpdb;
		$ttable = RTS_DB::table( 'trophies' );
		$utable = RTS_DB::table( 'trophy_unlocks' );
		$trophy = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ttable WHERE unlock_rule = %s", $unlock_rule_key ) );
		if ( ! $trophy ) { return null; }
		$result = $wpdb->insert( $utable, array( 'trophy_id' => $trophy->id, 'participant_id' => $participant_id ) );
		if ( false === $result ) { return null; } // already unlocked (UNIQUE constraint) — not an error
		self::log_audit( 'system', 'Trophy unlocked: ' . $trophy->name, 'Trophy Management', 'success', 'participant_id=' . $participant_id );
		return $trophy;
	}

	// ---- Referral Coefficient (K) ----

	public static function calculate_referral_coefficient() {
		global $wpdb;
		$ptable = RTS_DB::table( 'participants' );
		$rtable = RTS_DB::table( 'referrals' );

		$founders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ptable WHERE email_verified = 1" );
		if ( 0 === $founders ) { return array( 'k' => 0, 'avg_referrals_sent' => 0, 'conversion_rate' => 0 ); }

		$total_referrals_sent = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $rtable" );
		$avg_referrals_sent = $total_referrals_sent / $founders;

		$total_verified = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $rtable WHERE verified = 1" );
		$conversion_rate = $total_referrals_sent > 0 ? $total_verified / $total_referrals_sent : 0;

		$k = $avg_referrals_sent * $conversion_rate;
		return array(
			'k' => round( $k, 2 ),
			'avg_referrals_sent' => round( $avg_referrals_sent, 2 ),
			'conversion_rate' => round( $conversion_rate * 100, 2 ),
		);
	}

	// ---- Subscriptions & Unsubscribe ----

	public static function get_subscription_status( $unsubscribe_token ) {
		global $wpdb;
		$ptable = RTS_DB::table( 'participants' );
		$stable = RTS_DB::table( 'subscriptions' );
		$participant = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ptable WHERE unsubscribe_token = %s", $unsubscribe_token ) );
		if ( ! $participant ) { return array( 'error' => 'INVALID_TOKEN' ); }
		$subs = $wpdb->get_results( $wpdb->prepare( "SELECT category, subscribed FROM $stable WHERE participant_id = %d", $participant->id ) );
		return array( 'error' => null, 'participant' => array( 'name' => $participant->name, 'email' => $participant->email ), 'subscriptions' => $subs );
	}

	public static function unsubscribe( $unsubscribe_token, $category = 'all' ) {
		global $wpdb;
		$ptable = RTS_DB::table( 'participants' );
		$stable = RTS_DB::table( 'subscriptions' );
		$participant = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ptable WHERE unsubscribe_token = %s", $unsubscribe_token ) );
		if ( ! $participant ) { return array( 'error' => 'INVALID_TOKEN' ); }

		$categories = 'all' === $category ? self::$marketing_categories : array( $category );
		if ( 'all' !== $category && ! in_array( $category, self::$marketing_categories, true ) ) {
			return array( 'error' => 'INVALID_CATEGORY' );
		}

		foreach ( $categories as $cat ) {
			$wpdb->update( $stable, array( 'subscribed' => 0 ), array( 'participant_id' => $participant->id, 'category' => $cat ) );
		}
		self::log_audit( $participant->email, 'Unsubscribed (' . $category . ')', 'Subscription Management', 'success', 'participant_id=' . $participant->id );
		return array( 'error' => null, 'unsubscribed_from' => $categories );
	}

	public static function resubscribe( $unsubscribe_token, $category ) {
		global $wpdb;
		$ptable = RTS_DB::table( 'participants' );
		$stable = RTS_DB::table( 'subscriptions' );
		$participant = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ptable WHERE unsubscribe_token = %s", $unsubscribe_token ) );
		if ( ! $participant ) { return array( 'error' => 'INVALID_TOKEN' ); }
		$wpdb->update( $stable, array( 'subscribed' => 1 ), array( 'participant_id' => $participant->id, 'category' => $category ) );
		self::log_audit( $participant->email, 'Resubscribed (' . $category . ')', 'Subscription Management', 'success', 'participant_id=' . $participant->id );
		return array( 'error' => null );
	}

	// THE CORE GUARANTEE: every bulk send must build its recipient list through this function.
	public static function get_bulk_email_audience( $category = 'general', $extra_where_sql = '', $extra_params = array() ) {
		global $wpdb;
		$ptable = RTS_DB::table( 'participants' );
		$stable = RTS_DB::table( 'subscriptions' );
		if ( ! in_array( $category, self::$marketing_categories, true ) ) { throw new Exception( 'INVALID_CATEGORY' ); }

		$total_sql = "SELECT COUNT(*) FROM $ptable p WHERE p.email_verified = 1 $extra_where_sql";
		$total_matching = (int) ( empty( $extra_params ) ? $wpdb->get_var( $total_sql ) : $wpdb->get_var( $wpdb->prepare( $total_sql, ...$extra_params ) ) );

		$elig_sql = "SELECT p.id, p.name, p.email, p.founding_runner_number, p.referral_code, p.unsubscribe_token FROM $ptable p
			JOIN $stable s ON s.participant_id = p.id AND s.category = %s
			WHERE p.email_verified = 1
			AND COALESCE(p.declined_further_contact, 0) = 0
			AND p.email IS NOT NULL AND p.email != ''
			AND s.subscribed = 1 $extra_where_sql";
		$params = array_merge( array( $category ), $extra_params );
		$eligible = $wpdb->get_results( $wpdb->prepare( $elig_sql, ...$params ) );

		return array(
			'category' => $category,
			'total_matching_filter' => $total_matching,
			'excluded_unsubscribed' => $total_matching - count( $eligible ),
			'final_recipients' => $eligible,
			'final_recipient_count' => count( $eligible ),
		);
	}

	public static function send_bulk_email( $category, $subject, $sent_by, $test_mode = false ) {
		global $wpdb;
		$audience = self::get_bulk_email_audience( $category );
		$wpdb->insert( RTS_DB::table( 'sent_emails' ), array(
			'category' => $category, 'subject' => $subject,
			'recipient_count' => $audience['final_recipient_count'],
			'excluded_unsubscribed_count' => $audience['excluded_unsubscribed'],
			'sent_by' => $sent_by, 'test_mode' => $test_mode ? 1 : 0,
		) );
		self::log_audit( $sent_by, 'Bulk email sent: "' . $subject . '"', 'Email', 'success',
			"category=$category; recipients={$audience['final_recipient_count']}; excluded_unsubscribed={$audience['excluded_unsubscribed']}" );
		return $audience;
	}
}
