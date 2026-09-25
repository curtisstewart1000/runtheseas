<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Additive adapter between the existing Run The Seas survey tables and the
 * reporting fields consumed by the Admin Platform. Source columns are never
 * removed or renamed; shared rows are enriched in place.
 */
class RTSAP_Data_Mapper {

	const MAP_VERSION = '1.3.1';
	const SYNC_LOCK   = 'rtsap_data_mapper_lock';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_sync' ), 1 );
	}

	public static function maybe_sync() {
		if ( get_transient( self::SYNC_LOCK ) ) { return; }
		$last = (int) get_option( 'rtsap_last_data_sync', 0 );
		if ( get_option( 'rtsap_map_version' ) !== self::MAP_VERSION || $last < time() - 300 ) {
			self::sync();
		}
	}

	public static function sync() {
		global $wpdb;
		if ( get_transient( self::SYNC_LOCK ) ) { return false; }
		set_transient( self::SYNC_LOCK, 1, 120 );

		try {
			self::repair_zero_question_ids();
			self::map_surveys();
			self::map_questions();
			self::map_participants();
			self::map_responses_and_answers();
			self::map_referrals();
			self::map_cabin_credits();
			self::map_subscriptions();
			self::map_legacy_awards();
			self::map_audit_history();

			update_option( 'rtsap_map_version', self::MAP_VERSION, false );
			update_option( 'rtsap_last_data_sync', time(), false );
			return true;
		} finally {
			delete_transient( self::SYNC_LOCK );
		}
	}

	/**
	 * Repairs keys if a previous platform dbDelta pass coerced the survey
	 * plugin's varchar question_id column through BIGINT. Labels/answers remain
	 * intact, and these stable source keys are the ones used by the live form.
	 */
	private static function repair_zero_question_ids() {
		global $wpdb;
		$table = RTS_DB::table( 'survey_answers' );
		if ( ! self::exists( $table ) || ! $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE question_id = '0'" ) ) { return; }
		$wpdb->query( "UPDATE $table SET question_id = CASE
			WHEN question_label LIKE '%statement best describes you today%' THEN 's1_radio'
			WHEN question_label LIKE '%country do you currently live in%' THEN 's2_country'
			WHEN question_label LIKE '%organized distances have you completed%' THEN 's3r_checkbox[]'
			WHEN question_label LIKE '%activities for every fitness level%' THEN 's3n_checkbox[]'
			WHEN question_label LIKE '%events would you most likely enter%' THEN 's4r_checkbox[]'
			WHEN question_label LIKE '%dedicated 5K walking event%' THEN 's4n_radio'
			WHEN question_label LIKE '%professionally timed%' THEN 's5r_radio'
			WHEN question_label LIKE '%alternatives would interest you%' THEN 's5n_checkbox[]'
			WHEN question_label LIKE '%timed half marathon at a beautiful port%' THEN 's6r_radio'
			WHEN question_label = 'Fitness, Wellness and Personal Growth' THEN 's6n_checkbox1[]'
			WHEN question_label = 'Travel, Culture and Learning' THEN 's6n_checkbox2[]'
			WHEN question_label = 'Creative and Social Experiences' THEN 's6n_checkbox3[]'
			WHEN question_label = 'Exclusive Voyage Partner and Ship Experiences' THEN 's6n_checkbox4[]'
			WHEN question_label LIKE '%pre-cruise running support%' THEN 's7r_checkbox[]'
			WHEN question_label LIKE '%prevent you from joining%' THEN 's7n_radio'
			WHEN question_label LIKE '%five features would be most important%' THEN 's8r_checkbox[]'
			WHEN question_label LIKE '%biggest concern about booking%' THEN 's9r_radio'
			WHEN question_label LIKE '%interested would you be in a seven-night%' THEN 's10_radio'
			WHEN question_label LIKE '%most likely travel with you%' THEN 's11_checkbox[]'
			WHEN question_label LIKE '%best describe your likely cabin%' THEN 's12_radio'
			WHEN question_label LIKE '%cruises have you taken previously%' OR question_label LIKE '%previously taken a cruise%' THEN 's13_radio'
			WHEN question_label LIKE '%choose your specific cabin or deck%' THEN 's14_radio'
			WHEN question_label LIKE '%type of cabin would you prefer%' THEN 's15_radio'
			WHEN question_label LIKE '%deck level would you prefer%' THEN 's16_radio'
			WHEN question_label LIKE '%location on the ship would you prefer%' THEN 's17_radio'
			WHEN question_label LIKE '%likely would you be to book the inaugural%' THEN 's18_radio'
			WHEN question_label LIKE '%departure region would you prefer%' THEN 's19_radio'
			WHEN question_label LIKE '%when would you be most likely to travel%' THEN 's20_radio'
			WHEN question_label LIKE '%what is your age range%' THEN 's21_radio'
			WHEN question_label LIKE '%describe your gender%' THEN 's22_radio'
			WHEN question_label LIKE '%annual household income%' THEN 's23_radio'
			WHEN question_label LIKE '%what price, if any, would you consider reasonable%' THEN 's24_numeric_field'
			WHEN question_label LIKE '%price ranges would you be willing to pay%' OR question_label LIKE '%total package-price ranges%' THEN 's24_radio'
			WHEN question_label LIKE '%what could Run The Seas include or change%' THEN 's26_input_text'
			WHEN question_label = 'Add a comment (optional)' AND step_number <= 3 THEN 's3_description'
			WHEN question_label = 'Add a comment (optional)' THEN 's14_description'
			ELSE question_id END
			WHERE question_id = '0'" );
	}

	private static function exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	private static function map_surveys() {
		global $wpdb;
		$surveys  = RTS_DB::table( 'surveys' );
		$tracking = RTS_DB::table( 'survey_tracking' );
		$answers  = RTS_DB::table( 'survey_answers' );
		$form_ids = array();

		if ( self::exists( $tracking ) ) { $form_ids = $wpdb->get_col( "SELECT DISTINCT form_id FROM $tracking WHERE form_id IS NOT NULL" ); }
		if ( self::exists( $answers ) )  { $form_ids = array_merge( $form_ids, $wpdb->get_col( "SELECT DISTINCT form_id FROM $answers WHERE form_id IS NOT NULL" ) ); }
		$forms    = $wpdb->prefix . 'fluentform_forms';
		if ( self::exists( $forms ) ) { $form_ids = array_merge( $form_ids, $wpdb->get_col( "SELECT id FROM $forms" ) ); }
		$form_ids = array_unique( array_map( 'intval', $form_ids ) );

		foreach ( $form_ids as $form_id ) {
			$form = self::exists( $forms ) ? $wpdb->get_row( $wpdb->prepare( "SELECT title, status FROM $forms WHERE id = %d", $form_id ) ) : null;
			$data = array(
				'name'     => $form && $form->title ? $form->title : 'Survey Form ' . $form_id,
				'status'   => ! $form || in_array( $form->status, array( 'published', 'active' ), true ) ? 'live' : 'draft',
				'language' => 'EN',
			);
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $surveys WHERE source_form_id = %d", $form_id ) );
			if ( $id ) { $wpdb->update( $surveys, $data, array( 'id' => $id ) ); }
			else { $data['source_form_id'] = $form_id; $wpdb->insert( $surveys, $data ); }
		}
	}

	private static function map_questions() {
		global $wpdb;
		$answers = RTS_DB::table( 'survey_answers' );
		if ( ! self::exists( $answers ) ) { return; }
		$surveys  = RTS_DB::table( 'surveys' );
		$questions = RTS_DB::table( 'survey_questions' );
		$wpdb->query( "DELETE FROM $questions WHERE source_question_id = '0'" );
		$rows = $wpdb->get_results( "SELECT form_id, question_id, MAX(question_label) AS prompt, MAX(question_type) AS question_type, MIN(step_number) AS step_number FROM $answers WHERE form_id IS NOT NULL AND question_id IS NOT NULL GROUP BY form_id, question_id ORDER BY form_id, MIN(step_number), MIN(id)" );
		$sequence = array();

		foreach ( $rows as $row ) {
			$survey_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $surveys WHERE source_form_id = %d", $row->form_id ) );
			if ( ! $survey_id ) { continue; }
			$sequence[ $row->form_id ] = ( $sequence[ $row->form_id ] ?? 0 ) + 1;
			$number = $sequence[ $row->form_id ];
			if ( preg_match( '/^s(\d+)/i', (string) $row->question_id, $match ) ) { $number = (int) $match[1]; }
			$type = strtolower( (string) $row->question_type );
			$data = array(
				'survey_id'      => $survey_id,
				'question_number'=> $number,
				'prompt'         => $row->prompt ?: (string) $row->question_id,
				'question_type'  => $type ?: 'text',
				'allow_comment'  => in_array( $type, array( 'textarea', 'text' ), true ) ? 1 : 0,
				'sort_order'     => $row->step_number ? (int) $row->step_number * 100 + $sequence[ $row->form_id ] : $sequence[ $row->form_id ],
			);
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $questions WHERE source_form_id = %d AND source_question_id = %s", $row->form_id, $row->question_id ) );
			if ( $id ) { $wpdb->update( $questions, $data, array( 'id' => $id ) ); }
			else {
				$data['source_form_id'] = (int) $row->form_id;
				$data['source_question_id'] = (string) $row->question_id;
				$wpdb->insert( $questions, $data );
			}
		}
	}

	private static function map_participants() {
		global $wpdb;
		$participants = RTS_DB::table( 'participants' );
		$tracking     = RTS_DB::table( 'survey_tracking' );
		$answers      = RTS_DB::table( 'survey_answers' );
		if ( ! self::exists( $participants ) ) { return; }

		// Captain's Suite defines the Founding Runner ID as the participant's
		// primary key, displayed with a hash and at least seven digits (#0000004).
		$wpdb->query( "UPDATE $participants
			SET founding_runner_number = CONCAT('#', IF(id < 10000000, LPAD(id, 7, '0'), id))" );

		$wpdb->query( "UPDATE $participants SET
			name = COALESCE(NULLIF(name,''), NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)),'')),
			verification_token = COALESCE(verification_token, email_verification_token),
			verified_at = COALESCE(verified_at, email_verification_date),
			registered_at = COALESCE(registered_at, registration_date, created_at),
			referred_by_participant_id = COALESCE(referred_by_participant_id, referred_by),
			account_status = COALESCE(NULLIF(account_status,''), 'active')" );

		$rows = $wpdb->get_results( "SELECT id, email, survey_tracking_id, unsubscribe_token, country, registration_country, detected_country, country_verified, gender, age_range, household_income_bracket FROM $participants" );
		foreach ( $rows as $participant ) {
			$data = array();
			if ( empty( $participant->registration_country ) && ! empty( $participant->country ) && ! self::looks_like_income( $participant->country ) ) {
				$data['registration_country'] = mb_substr( trim( (string) $participant->country ), 0, 100 );
			}
			if ( empty( $participant->unsubscribe_token ) ) {
				$data['unsubscribe_token'] = substr( hash_hmac( 'sha256', $participant->email . '|' . $participant->id, wp_salt( 'auth' ) ), 0, 40 );
			}
			$tracking_id = (int) $participant->survey_tracking_id;
			if ( ! $tracking_id && self::exists( $tracking ) ) {
				$tracking_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tracking WHERE email = %s ORDER BY COALESCE(completed_at,last_activity,started_at) DESC LIMIT 1", $participant->email ) );
				if ( $tracking_id ) { $data['survey_tracking_id'] = $tracking_id; }
			}
			if ( $tracking_id && self::exists( $tracking ) ) {
				$track = $wpdb->get_row( $wpdb->prepare( "SELECT referral_source, country FROM $tracking WHERE id = %d", $tracking_id ) );
				if ( $track ) {
					if ( $track->referral_source ) { $data['marketing_source'] = mb_substr( $track->referral_source, 0, 50 ); }
					if ( $track->country ) {
						$data['detected_country'] = mb_substr( trim( (string) $track->country ), 0, 100 );
						if ( empty( $participant->country_verified ) ) { $data['country'] = $data['detected_country']; }
					}
				}
			}
			if ( $tracking_id && self::exists( $answers ) ) {
				// Labels are the stable source of meaning across live form versions.
				// Do not trust question_id here: historical imports may reuse or
				// coerce those keys, which can put an income answer in every field.
				$answer_map = array(
					'age_range'                => '%what is your age range%',
					'gender'                   => '%describe your gender%',
					'household_income_bracket' => '%annual household income%',
				);
				foreach ( $answer_map as $field => $label_pattern ) {
					$value = self::semantic_answer( $answers, $tracking, $tracking_id, $participant->email, $label_pattern );
					if ( self::valid_demographic_value( $field, $value ) ) { $data[ $field ] = mb_substr( trim( (string) $value ), 0, 100 ); }
				}
				$runner = self::semantic_answer( $answers, $tracking, $tracking_id, $participant->email, '%statement best describes you today%' );
				if ( $runner ) { $data['runner_status'] = false !== stripos( $runner, 'currently run or run/walk' ) ? 'runner' : 'non_runner'; }
			}

			// If corrupted demographic values cannot be repaired from a linked
			// survey answer, clear them instead of continuing to report income as
			// a country, gender, or age. A valid existing value is preserved.
			if ( ! array_key_exists( 'country', $data ) && empty( $participant->country_verified ) ) {
				$fallback_country = ! empty( $participant->detected_country )
					? $participant->detected_country
					: ( $participant->registration_country ?: $participant->country );
				$data['country'] = self::looks_like_income( $fallback_country ) ? null : $fallback_country;
			}
			if ( ! array_key_exists( 'gender', $data ) && ! empty( $participant->gender ) && ! self::valid_demographic_value( 'gender', $participant->gender ) ) { $data['gender'] = null; }
			if ( ! array_key_exists( 'age_range', $data ) && ! empty( $participant->age_range ) && ! self::valid_demographic_value( 'age_range', $participant->age_range ) ) { $data['age_range'] = null; }
			if ( ! array_key_exists( 'household_income_bracket', $data ) && ! empty( $participant->household_income_bracket ) && ! self::valid_demographic_value( 'household_income_bracket', $participant->household_income_bracket ) ) { $data['household_income_bracket'] = null; }
			if ( $data ) { $wpdb->update( $participants, $data, array( 'id' => $participant->id ) ); }
		}
	}

	private static function semantic_answer( $answers, $tracking, $tracking_id, $email, $label_pattern ) {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare(
			"SELECT answer_value FROM $answers WHERE tracking_id = %d AND LOWER(question_label) LIKE %s AND answer_value IS NOT NULL AND answer_value != '' ORDER BY answered_at DESC, id DESC LIMIT 1",
			$tracking_id,
			strtolower( $label_pattern )
		) );
		if ( ( null === $value || '' === $value ) && self::exists( $tracking ) && $email ) {
			$value = $wpdb->get_var( $wpdb->prepare(
				"SELECT sa.answer_value FROM $answers sa JOIN $tracking st ON st.id = sa.tracking_id WHERE st.email = %s AND LOWER(sa.question_label) LIKE %s AND sa.answer_value IS NOT NULL AND sa.answer_value != '' ORDER BY sa.answered_at DESC, sa.id DESC LIMIT 1",
				$email,
				strtolower( $label_pattern )
			) );
		}
		return $value;
	}

	private static function looks_like_income( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return '' !== $value && ( false !== strpos( $value, '$' ) || false !== strpos( $value, ' usd' ) || false !== strpos( $value, 'income' ) );
	}

	private static function valid_demographic_value( $field, $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) { return false; }
		switch ( $field ) {
			case 'country':
				return ! self::looks_like_income( $value ) && strlen( $value ) <= 100;
			case 'gender':
				return (bool) preg_match( '/^(man|woman|male|female|non[- ]?binary|prefer not to answer|prefer to self-describe|other)$/i', $value );
			case 'age_range':
				return (bool) preg_match( '/^(under\s*\d+|\d+\s*[\-–—]\s*\d+|\d+\s*(or|and)\s*(older|over|above)|prefer not to answer)$/iu', $value );
			case 'household_income_bracket':
				return self::looks_like_income( $value ) || false !== stripos( $value, 'prefer not to answer' );
		}
		return false;
	}

	private static function map_responses_and_answers() {
		global $wpdb;
		$tracking = RTS_DB::table( 'survey_tracking' );
		if ( ! self::exists( $tracking ) ) { return; }
		$surveys     = RTS_DB::table( 'surveys' );
		$participants= RTS_DB::table( 'participants' );
		$responses   = RTS_DB::table( 'survey_responses' );
		$answers     = RTS_DB::table( 'survey_answers' );
		$questions   = RTS_DB::table( 'survey_questions' );

		$wpdb->query( "INSERT INTO $responses (survey_id, participant_id, source_tracking_id, source_submission_id, session_token, status, started_at, completed_at)
			SELECT s.id,
				(SELECT p.id FROM $participants p WHERE p.survey_tracking_id = st.id OR (st.email IS NOT NULL AND st.email != '' AND p.email = st.email) ORDER BY (p.survey_tracking_id = st.id) DESC LIMIT 1),
				st.id, st.submission_id, COALESCE(NULLIF(st.session_id,''), st.submission_id),
				CASE WHEN st.completion_status = 'completed' THEN 'completed' WHEN st.completion_status = 'abandoned' THEN 'abandoned' ELSE 'in_progress' END,
				st.started_at, st.completed_at
			FROM $tracking st JOIN $surveys s ON s.source_form_id = st.form_id
			ON DUPLICATE KEY UPDATE survey_id = VALUES(survey_id), participant_id = VALUES(participant_id), status = VALUES(status), completed_at = VALUES(completed_at)" );

		$wpdb->query( "UPDATE $answers sa
			LEFT JOIN $responses sr ON sr.source_tracking_id = sa.tracking_id
			LEFT JOIN $questions sq ON sq.source_form_id = sa.form_id AND sq.source_question_id = sa.question_id
			SET sa.response_id = COALESCE(sa.response_id, sr.id),
				sa.platform_question_id = COALESCE(sq.id, sa.platform_question_id),
				sa.comment_text = CASE WHEN sa.comment_text IS NULL AND (sa.question_type IN ('textarea','text') OR sa.question_id LIKE '%description%') THEN sa.answer_value ELSE sa.comment_text END
			WHERE sa.tracking_id IS NOT NULL" );
	}

	private static function map_referrals() {
		global $wpdb;
		$table = RTS_DB::table( 'referrals' );
		if ( ! self::exists( $table ) ) { return; }
		$wpdb->query( "UPDATE $table SET
			referring_participant_id = COALESCE(referring_participant_id, referrer_id),
			referrer_id = COALESCE(referrer_id, referring_participant_id),
			clicked_at = COALESCE(clicked_at, referral_date, created_at),
			verified_at = COALESCE(verified_at, completed_date),
			verified = CASE WHEN status = 'completed' OR completed_date IS NOT NULL THEN 1 ELSE COALESCE(verified,0) END,
			fraud_review_status = COALESCE(NULLIF(fraud_review_status,''), 'clear')" );
	}

	private static function map_cabin_credits() {
		global $wpdb;
		$participants = RTS_DB::table( 'participants' );
		$credits = RTS_DB::table( 'cabin_credits' );
		$wpdb->query( "INSERT INTO $credits (participant_id, status, value_usd, issued_at, cabin_reservation_id)
			SELECT id, 'issued', COALESCE(cabin_credit_amount,100.00), COALESCE(cabin_credit_issued_at,cabin_credit_approved_date,registered_at), cabin_credit_number
			FROM $participants WHERE cabin_credit_status IN ('approved','issued') OR cabin_credit_issued_at IS NOT NULL
			ON DUPLICATE KEY UPDATE value_usd = VALUES(value_usd), cabin_reservation_id = COALESCE(cabin_reservation_id,VALUES(cabin_reservation_id))" );
	}

	private static function map_subscriptions() {
		global $wpdb;
		$participants = RTS_DB::table( 'participants' );
		$subscriptions = RTS_DB::table( 'subscriptions' );
		foreach ( array( 'survey', 'referral', 'trophy', 'general' ) as $category ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $subscriptions (participant_id, category, subscribed) SELECT id, %s, 1 FROM $participants", $category ) );
		}
	}

	private static function map_legacy_awards() {
		global $wpdb;
		$trophies = RTS_DB::table( 'trophies' );
		$unlocks  = RTS_DB::table( 'trophy_unlocks' );

		// Older mapper versions projected achievements and medals into the
		// trophy tables. Those are activity logs, not trophy awards, and caused
		// every real trophy to be represented again by its trophy_earned
		// achievement. Remove only those generated projections; their source
		// rows remain intact in rts_achievements and rts_medals.
		$wpdb->query( "DELETE u FROM $unlocks u
			INNER JOIN $trophies t ON t.id = u.trophy_id
			WHERE t.description IN ('Imported from achievements', 'Imported from medals')" );
		$wpdb->query( "DELETE FROM $trophies
			WHERE description IN ('Imported from achievements', 'Imported from medals')" );

		// rts_user_trophies is the sole authoritative earned-trophy ledger.
		$sources = array(
			'user_trophies' => array( 'trophy_name', 'trophy_type', 'earned_date' ),
		);
		foreach ( $sources as $source => $columns ) {
			$table = RTS_DB::table( $source );
			if ( ! self::exists( $table ) ) { continue; }
			$rows = $wpdb->get_results( "SELECT id, participant_id, {$columns[0]} AS award_name, {$columns[1]} AS award_type, {$columns[2]} AS awarded_at FROM $table" );
			foreach ( $rows as $row ) {
				$rule = 'legacy_' . substr( md5( $source . '|' . $row->award_name . '|' . $row->award_type ), 0, 32 );
				$trophy_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $trophies WHERE unlock_rule = %s LIMIT 1", $rule ) );
				if ( ! $trophy_id ) {
					$wpdb->insert( $trophies, array( 'name' => mb_substr( $row->award_name, 0, 100 ), 'description' => 'Imported from ' . $source, 'unlock_rule' => $rule, 'category' => 'legacy' ) );
					$trophy_id = (int) $wpdb->insert_id;
				}
				if ( $trophy_id ) { $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $unlocks (trophy_id, participant_id, unlocked_at) VALUES (%d,%d,%s)", $trophy_id, $row->participant_id, $row->awarded_at ?: current_time( 'mysql' ) ) ); }
			}
		}
	}

	private static function map_audit_history() {
		global $wpdb;
		$audit = RTS_DB::table( 'audit_log' );
		$activity = RTS_DB::table( 'activity_logs' );
		$timeline = RTS_DB::table( 'timeline' );
		if ( self::exists( $activity ) ) {
			$wpdb->query( "INSERT IGNORE INTO $audit (source_table, source_id, user, action, module, result, notes, created_at)
				SELECT 'activity_logs', id, 'system', action, 'Survey Activity', 'success', description, created_at FROM $activity" );
		}
		if ( self::exists( $timeline ) ) {
			$wpdb->query( "INSERT IGNORE INTO $audit (source_table, source_id, user, action, module, ip_address, result, notes, created_at)
				SELECT 'timeline', id, 'system', activity_type, 'Participant Timeline', ip_address, 'success', activity_description, COALESCE(activity_date,created_at) FROM $timeline" );
		}
	}
}
