<?php
/**
 * Private operational tools for Run The Seas administrators.
 *
 * This class deliberately keeps automated survey data intact. Manual decisions
 * are written to the participant timeline and duplicate-review audit table.
 */
class RTS_Participant_Operations
{
    private $registration;

    public function __construct($registration = null)
    {
        $this->registration = $registration instanceof RTS_Registration ? $registration : new RTS_Registration();

        $this->maybe_upgrade_review_table();
        add_action('init', array($this, 'handle_participant_record_update'));
        add_action('wp_ajax_rts_participant_action', array($this, 'ajax_participant_action'));
        add_action('wp_ajax_rts_export_participants', array($this, 'ajax_export_participants'));
    }

    /** Create or alter the review table only when its schema version changes. */
    private function maybe_upgrade_review_table()
    {
        $schema_version = '1.0';
        if (get_option('rts_duplicate_review_schema_version') === $schema_version) {
            return;
        }
        if (!rts_acquire_upgrade_lock('duplicate_review_schema')) {
            return;
        }

        try {
            $this->create_review_table();
            update_option('rts_duplicate_review_schema_version', $schema_version, false);
        } finally {
            rts_release_upgrade_lock('duplicate_review_schema');
        }
    }

    public function create_review_table()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rts_duplicate_reviews';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            tracking_id bigint(20) NOT NULL,
            participant_id bigint(20) DEFAULT NULL,
            decision varchar(20) NOT NULL,
            notes text DEFAULT NULL,
            reviewed_by bigint(20) NOT NULL,
            reviewed_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY tracking_id (tracking_id),
            KEY participant_id (participant_id),
            KEY decision (decision)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function render_page($requested_tab = '')
    {
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            return;
        }

        global $wpdb;
        $participants_table = $wpdb->prefix . 'rts_participants';
        $tracking_table = $wpdb->prefix . 'rts_survey_tracking';
        $review_table = $wpdb->prefix . 'rts_duplicate_reviews';

        $editing_id = isset($_GET['participant_id']) ? absint($_GET['participant_id']) : 0;
        if ($editing_id) {
            $this->render_participant_editor($editing_id);
            return;
        }

        $search = isset($_GET['rts_search']) ? sanitize_text_field(wp_unslash($_GET['rts_search'])) : '';
        $verification = isset($_GET['verification']) ? sanitize_key(wp_unslash($_GET['verification'])) : '';
        $credit = isset($_GET['credit']) ? sanitize_key(wp_unslash($_GET['credit'])) : '';
        $suite = isset($_GET['suite']) ? sanitize_key(wp_unslash($_GET['suite'])) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
        $sort = isset($_GET['sort']) ? sanitize_key(wp_unslash($_GET['sort'])) : 'newest';

        $where = array('1=1');
        $params = array();
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(p.email LIKE %s OR p.first_name LIKE %s OR p.last_name LIKE %s OR p.referral_code LIKE %s OR p.cabin_credit_number LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if (in_array($verification, array('verified', 'unverified'), true)) {
            $where[] = 'p.email_verified = %d';
            $params[] = $verification === 'verified' ? 1 : 0;
        }
        if (in_array($credit, array('pending', 'approved', 'not_requested'), true)) {
            $where[] = 'p.cabin_credit_status = %s';
            $params[] = $credit;
        }
        if (in_array($suite, array('active', 'inactive', 'pending'), true)) {
            $where[] = 'p.captain_suite_status = %s';
            $params[] = $suite;
        }
        if ($date_from !== '') {
            $where[] = 'p.registration_date >= %s';
            $params[] = $date_from . ' 00:00:00';
        }
        if ($date_to !== '') {
            $where[] = 'p.registration_date <= %s';
            $params[] = $date_to . ' 23:59:59';
        }

        $order_by = $sort === 'oldest' ? 'p.registration_date ASC, p.id ASC' : ($sort === 'name' ? 'p.last_name ASC, p.first_name ASC, p.id DESC' : 'p.registration_date DESC, p.id DESC');

        $sql = "SELECT p.*, st.is_duplicate, st.id AS duplicate_tracking_id, dr.decision AS duplicate_decision
            FROM $participants_table p
            LEFT JOIN $tracking_table st ON st.id = p.survey_tracking_id
            LEFT JOIN $review_table dr ON dr.tracking_id = st.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY $order_by LIMIT 100";
        $participants = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);

        $stats = $wpdb->get_row("SELECT
            COUNT(*) AS total,
            SUM(email_verified = 1) AS verified,
            SUM(email_verified = 0) AS unverified,
            SUM(cabin_credit_status = 'pending') AS credits_pending,
            SUM(cabin_credit_status = 'approved') AS credits_issued,
            SUM(captain_suite_status = 'active') AS suites_active,
            SUM(certificate_issued_at IS NOT NULL) AS certificates_issued
            FROM $participants_table");

        $duplicate_alerts = $wpdb->get_results("SELECT st.*, p.id AS participant_id, p.first_name, p.last_name, p.email, dr.decision
            FROM $tracking_table st
            LEFT JOIN $participants_table p ON p.survey_tracking_id = st.id
            LEFT JOIN $review_table dr ON dr.tracking_id = st.id
            WHERE st.is_duplicate = 1
            ORDER BY st.started_at DESC LIMIT 25");

        // Group only the responses that are actually flagged as duplicates.
        // The original canonical response is referenced but not repeated in
        // every comparison row.
        $duplicate_groups = array();
        foreach ($duplicate_alerts as $alert) {
            $group_match = !empty($alert->session_id)
                ? 'session:' . $alert->form_id . ':' . $alert->session_id
                : 'email:' . $alert->form_id . ':' . strtolower($alert->email);
            if (!isset($duplicate_groups[$group_match])) {
                $duplicate_groups[$group_match] = (object) array(
                    'form_id' => $alert->form_id,
                    'session_id' => $alert->session_id,
                    'duplicate_of' => $alert->duplicate_of,
                    'alerts' => array(),
                );
            }
            $duplicate_groups[$group_match]->alerts[] = $alert;
        }

        // Include every later response in a duplicate session/email group, not
        // just those that have already been auto-flagged. The oldest response
        // remains the original and is excluded from the candidate list.
        foreach ($duplicate_groups as $group) {
            $entry_params = array($group->form_id);
            $entry_where = 'st.form_id = %d AND (';
            if (!empty($group->session_id)) {
                $entry_where .= 'st.session_id = %s';
                $entry_params[] = $group->session_id;
            } else {
                $email = !empty($group->alerts[0]->email) ? $group->alerts[0]->email : '';
                $entry_where .= "st.email = %s AND st.email <> ''";
                $entry_params[] = $email;
            }
            $entry_where .= ')';
            $group->entries = $wpdb->get_results($wpdb->prepare(
                "SELECT st.id, st.submission_id, st.email AS tracking_email, st.started_at,
                        st.is_duplicate, st.duplicate_of, p.id AS participant_id,
                        p.first_name, p.last_name, p.email AS participant_email
                 FROM $tracking_table st
                 LEFT JOIN $participants_table p ON p.survey_tracking_id = st.id
                 WHERE $entry_where
                 ORDER BY st.started_at ASC, st.id ASC",
                $entry_params
            ));
            if ($group->entries) {
                array_shift($group->entries);
            }
        }

        $history = $wpdb->get_results("SELECT t.activity_date, t.activity_type, t.activity_description,
            p.first_name, p.last_name, p.email
            FROM {$wpdb->prefix}rts_timeline t
            INNER JOIN $participants_table p ON p.id = t.participant_id
            WHERE t.activity_type IN ('verification_sent', 'email_verified', 'manual_email_verified', 'captain_suite_activated', 'cabin_credit_issued', 'duplicate_reviewed')
            ORDER BY t.activity_date DESC LIMIT 25");

        $action_nonce = wp_create_nonce('rts_admin_nonce');
        $export_url = add_query_arg(array(
            'action' => 'rts_export_participants',
            'nonce' => $action_nonce,
            'rts_search' => $search,
            'verification' => $verification,
            'credit' => $credit,
            'suite' => $suite,
            'date_from' => $date_from,
            'date_to' => $date_to,
        ), admin_url('admin-ajax.php'));
        $ops_tab = $requested_tab ?: (isset($_GET['ops_tab']) ? sanitize_key(wp_unslash($_GET['ops_tab'])) : 'participants');
        if (!in_array($ops_tab, array('participants', 'reviews'), true)) {
            $ops_tab = 'participants';
        }
        ?>
        <div class="wrap rts-operations-page">
            <h1><?php esc_html_e('Participant Verification & Account Operations', 'run-the-seas'); ?></h1>
            <p>Search participant records, resolve duplicate-response alerts, and make auditable manual account decisions.</p>

            <?php if ($ops_tab === 'participants') : ?>
            <div class="rts-ops-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:20px 0;">
                <?php foreach (array(
                    __('Registered participants', 'run-the-seas') => (int) $stats->total,
                    __('Verified', 'run-the-seas') => (int) $stats->verified,
                    __('Unverified', 'run-the-seas') => (int) $stats->unverified,
                    __('Credits pending', 'run-the-seas') => (int) $stats->credits_pending,
                    __('Credits issued', 'run-the-seas') => (int) $stats->credits_issued,
                    __('Suites active', 'run-the-seas') => (int) $stats->suites_active,
                    __('Certificates issued', 'run-the-seas') => (int) $stats->certificates_issued,
                ) as $label => $value) : ?>
                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">
                        <strong style="display:block;font-size:25px;line-height:1.1;"><?php echo esc_html(number_format_i18n($value)); ?></strong>
                        <span><?php echo esc_html($label); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;background:#fff;padding:16px;border:1px solid #dcdcde;border-radius:8px;margin:16px 0;">
                <input type="hidden" name="page" value="<?php echo esc_attr(isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''); ?>">
                <div><label for="rts-search"><strong>Search</strong></label><br><input id="rts-search" name="rts_search" value="<?php echo esc_attr($search); ?>" placeholder="Name, email, referral or credit" style="min-width:230px;"></div>
                <div><label><strong>Verification</strong></label><br><select name="verification"><option value="">All</option><option value="verified" <?php selected($verification, 'verified'); ?>>Verified</option><option value="unverified" <?php selected($verification, 'unverified'); ?>>Unverified</option></select></div>
                <div><label><strong>Cabin Credit</strong></label><br><select name="credit"><option value="">All</option><option value="pending" <?php selected($credit, 'pending'); ?>>Pending</option><option value="approved" <?php selected($credit, 'approved'); ?>>Issued</option><option value="not_requested" <?php selected($credit, 'not_requested'); ?>>Not requested</option></select></div>
                <div><label><strong>Captain's Suite</strong></label><br><select name="suite"><option value="">All</option><option value="pending" <?php selected($suite, 'pending'); ?>>Pending</option><option value="active" <?php selected($suite, 'active'); ?>>Active</option><option value="inactive" <?php selected($suite, 'inactive'); ?>>Inactive</option></select></div>
                <div><label><strong>Registered from</strong></label><br><input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>"></div>
                <div><label><strong>Registered to</strong></label><br><input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>"></div>
                <div><label><strong>Sort</strong></label><br><select name="sort"><option value="newest" <?php selected($sort, 'newest'); ?>>Newest first</option><option value="oldest" <?php selected($sort, 'oldest'); ?>>Oldest first</option><option value="name" <?php selected($sort, 'name'); ?>>Name</option></select></div>
                <button class="button button-primary">Apply filters</button>
                <a class="button" href="<?php echo esc_url($export_url); ?>">Export registrations CSV</a>
            </form>

            <h2>Participant records</h2>
            <div style="overflow:auto;background:#fff;border:1px solid #dcdcde;">
                <table class="widefat striped" style="min-width:1100px;"><thead><tr><th>Participant</th><th>Registered / verified</th><th>Cabin Credit</th><th>Captain's Suite</th><th>Survey / duplicate</th><th>Actions</th></tr></thead><tbody>
                <?php if (!$participants) : ?><tr><td colspan="6">No participants match these filters.</td></tr><?php endif; ?>
                <?php foreach ($participants as $participant) : ?>
                    <tr>
                        <td><strong><?php echo esc_html(trim($participant->first_name . ' ' . $participant->last_name)); ?></strong><br><a href="mailto:<?php echo esc_attr($participant->email); ?>"><?php echo esc_html($participant->email); ?></a><br><small>#<?php echo esc_html($participant->id); ?> · <?php echo esc_html($participant->referral_code ?: 'No referral code'); ?></small></td>
                        <td><?php echo esc_html($participant->registration_date ?: '—'); ?><br><?php echo $participant->email_verified ? '<span style="color:#188038;font-weight:600;">Verified</span>' : '<span style="color:#b06000;font-weight:600;">Unverified</span>'; ?><br><small><?php echo esc_html($participant->email_verification_date ?: 'No verification timestamp'); ?></small></td>
                        <td><?php echo esc_html(ucwords(str_replace('_', ' ', $participant->cabin_credit_status))); ?><br><small><?php echo esc_html($participant->cabin_credit_number ?: 'No credit issued'); ?></small></td>
                        <td><?php echo esc_html(ucfirst($participant->captain_suite_status)); ?><br><small>Kilometres: <?php echo esc_html(rts_format_miles((int) $participant->captain_miles_balance)); ?></small></td>
                        <td><?php echo $participant->survey_tracking_id ? 'Survey #' . esc_html($participant->survey_tracking_id) : 'No linked survey'; ?><br><?php if ($participant->is_duplicate) : ?><span style="color:#b42318;font-weight:600;">Duplicate review required<?php echo $participant->duplicate_decision ? ' (' . esc_html($participant->duplicate_decision) . ')' : ''; ?></span><?php endif; ?></td>
                        <td class="rts-ops-actions" style="display:flex;gap:5px;flex-wrap:wrap;">
                            <a class="button button-small" href="<?php echo esc_url(add_query_arg('participant_id', $participant->id)); ?>">Open record</a>
                            <?php if (!$participant->email_verified) : ?><button class="button button-small" data-action="resend_verification" data-id="<?php echo esc_attr($participant->id); ?>">Resend email</button><button class="button button-small" data-action="verify" data-id="<?php echo esc_attr($participant->id); ?>"><?php echo $participant->is_duplicate ? 'Verify & approve benefits' : 'Verify & issue benefits'; ?></button><?php endif; ?>
                            <?php if ($participant->email_verified && ($participant->captain_suite_status !== 'active' || $participant->cabin_credit_status !== 'approved')) : ?><button class="button button-small" data-action="issue_verified_benefits" data-id="<?php echo esc_attr($participant->id); ?>"><?php echo $participant->is_duplicate ? 'Approve duplicate benefits' : 'Issue verified benefits'; ?></button><?php endif; ?>
                            <?php if ($participant->email_verified && !empty($participant->certificate_number)) : ?><button class="button button-small" data-action="resend_certificate" data-id="<?php echo esc_attr($participant->id); ?>">Resend certificate</button><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
            <?php endif; ?>

            <?php if ($ops_tab === 'reviews') : ?>
            <h2 style="margin-top:32px;">Suspected duplicate survey responses</h2>
            <p>Every later response in a duplicate session or email group is listed for review. The original canonical response is kept as a reference and is not repeated in the comparison list.</p>
            <div style="overflow:auto;background:#fff;border:1px solid #dcdcde;"><table class="widefat striped"><thead><tr><th>Duplicate group</th><th>Duplicate survey responses</th><th>Decision</th><th>Review</th></tr></thead><tbody>
                <?php if (!$duplicate_groups) : ?><tr><td colspan="4">No duplicate-response alerts.</td></tr><?php endif; ?>
                <?php foreach ($duplicate_groups as $group) : ?>
                    <tr>
                        <td>Form #<?php echo esc_html($group->form_id); ?><br><small><?php echo $group->session_id ? 'Shared session' : 'Shared email'; ?></small><br><small>Original: <?php echo esc_html($group->duplicate_of ?: '—'); ?></small></td>
                        <td>
                            <?php foreach ($group->entries as $entry) : ?>
                                <div style="padding:6px 0;border-bottom:1px solid #eee;">
                                    <strong>#<?php echo esc_html($entry->id); ?></strong> · <?php echo esc_html($entry->participant_email ?: $entry->tracking_email ?: trim($entry->first_name . ' ' . $entry->last_name) ?: 'No email'); ?>
                                    <br><small><?php echo esc_html($entry->started_at ?: '—'); ?> · <?php echo $entry->is_duplicate ? '<span style="color:#b42318;">Duplicate / held</span>' : '<span style="color:#b06000;">Pending review</span>'; ?></small>
                                    <span class="rts-ops-actions" style="display:inline-flex;gap:4px;margin-left:8px;"><button class="button button-small" data-action="confirm_duplicate" data-tracking-id="<?php echo esc_attr($entry->id); ?>" data-participant-id="<?php echo esc_attr($entry->participant_id); ?>">Mark duplicate</button><button class="button button-small" data-action="clear_duplicate" data-tracking-id="<?php echo esc_attr($entry->id); ?>" data-participant-id="<?php echo esc_attr($entry->participant_id); ?>">Not a duplicate</button></span>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <td><?php echo esc_html(implode(', ', array_unique(wp_list_pluck($group->alerts, 'decision'))) ?: 'Awaiting review'); ?></td>
                        <td><small>Choose an action for an individual matching response.</small></td>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div>

            <h2 style="margin-top:32px;">Recent verification history</h2>
            <div style="overflow:auto;background:#fff;border:1px solid #dcdcde;"><table class="widefat striped"><thead><tr><th>Timestamp</th><th>Participant</th><th>Action</th><th>Details</th></tr></thead><tbody>
                <?php if (!$history) : ?><tr><td colspan="4">No verification history yet.</td></tr><?php endif; ?>
                <?php foreach ($history as $item) : ?><tr><td><?php echo esc_html($item->activity_date); ?></td><td><?php echo esc_html(trim($item->first_name . ' ' . $item->last_name)); ?><br><small><?php echo esc_html($item->email); ?></small></td><td><?php echo esc_html(ucwords(str_replace('_', ' ', $item->activity_type))); ?></td><td><?php echo esc_html($item->activity_description); ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>
        <script>
        jQuery(function ($) {
            $('.rts-ops-actions button').on('click', function (event) {
                event.preventDefault();
                var button = $(this), action = button.data('action');
                if (action === 'confirm_duplicate' || action === 'clear_duplicate') {
                    var notes = window.prompt('Optional review note:', '');
                    if (notes === null) { return; }
                }
                button.prop('disabled', true).text('Saving…');
                $.post(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, {
                    action: 'rts_participant_action', nonce: <?php echo wp_json_encode($action_nonce); ?>,
                    participant_id: button.data('id') || button.data('participant-id') || 0,
                    tracking_id: button.data('tracking-id') || 0,
                    operation: action, notes: typeof notes === 'undefined' ? '' : notes
                }).done(function (response) {
                    if (response.success) { window.location.reload(); }
                    else { window.alert(response.data || 'The action could not be completed.'); button.prop('disabled', false); }
                }).fail(function () { window.alert('The action could not be completed.'); button.prop('disabled', false); });
            });
        });
        </script>
        <?php
    }

    public function ajax_participant_action()
    {
        check_ajax_referer('rts_admin_nonce', 'nonce');
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_send_json_error('You do not have permission to perform this action.', 403);
        }

        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        $participant_id = absint($_POST['participant_id'] ?? 0);
        $tracking_id = absint($_POST['tracking_id'] ?? 0);
        $notes = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));
        global $wpdb;
        $participants_table = $wpdb->prefix . 'rts_participants';

        if (in_array($operation, array('confirm_duplicate', 'clear_duplicate'), true)) {
            if (!$tracking_id) {
                wp_send_json_error('A survey response is required.');
            }
            $decision = $operation === 'confirm_duplicate' ? 'confirmed_duplicate' : 'cleared';
            $tracking_table = $wpdb->prefix . 'rts_survey_tracking';
            $tracking = $wpdb->get_row($wpdb->prepare(
                "SELECT id, form_id, session_id, email FROM $tracking_table WHERE id = %d",
                $tracking_id
            ));
            if (!$tracking) {
                wp_send_json_error('Survey response not found.');
            }

            $update_data = array('is_duplicate' => $operation === 'confirm_duplicate' ? 1 : 0);
            $update_format = array('%d');
            if ($operation === 'confirm_duplicate') {
                $match_where = '(session_id = %s';
                $match_params = array($tracking->session_id);
                if (!empty($tracking->email)) {
                    $match_where .= " OR (email = %s AND email <> '')";
                    $match_params[] = $tracking->email;
                }
                $match_where .= ')';
                $match_params[] = $tracking->form_id;
                $canonical = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, submission_id FROM $tracking_table
                     WHERE $match_where AND form_id = %d
                     ORDER BY started_at ASC, id ASC LIMIT 1",
                    $match_params
                ));
                if (!$canonical || (int) $canonical->id === (int) $tracking_id) {
                    wp_send_json_error('The oldest matching response is the original record and cannot be marked as a duplicate.');
                }
                $update_data['duplicate_of'] = $canonical->submission_id;
                $update_format[] = '%s';
            } else {
                $update_data['duplicate_of'] = null;
                $update_format[] = '%s';
            }
            $wpdb->update($tracking_table, $update_data, array('id' => $tracking_id), $update_format, array('%d'));
            $wpdb->replace($wpdb->prefix . 'rts_duplicate_reviews', array('tracking_id' => $tracking_id, 'participant_id' => $participant_id ?: null, 'decision' => $decision, 'notes' => $notes, 'reviewed_by' => get_current_user_id(), 'reviewed_at' => current_time('mysql')), array('%d', '%d', '%s', '%s', '%d', '%s'));
            if ($participant_id) {
                $this->registration->log_timeline($participant_id, 'duplicate_reviewed', 'Duplicate response review: ' . $decision, array('tracking_id' => $tracking_id, 'notes' => $notes, 'reviewed_by' => get_current_user_id()));
            }
            wp_send_json_success('Duplicate review saved.');
        }

        $participant = $participant_id ? $this->registration->get_participant($participant_id) : null;
        if (!$participant) {
            wp_send_json_error('Participant not found.');
        }

        switch ($operation) {
            case 'resend_verification':
                if (!$this->registration->send_verification_email($participant_id, true)) {
                    wp_send_json_error('Verification email could not be sent. Check the mail log/configuration.');
                }
                $this->registration->log_timeline($participant_id, 'verification_resent_by_admin', 'Verification email resent manually', array('admin_id' => get_current_user_id()));
                break;

            case 'verify':
                $wpdb->update($participants_table, array('email_verified' => 1, 'email_verification_date' => current_time('mysql'), 'updated_at' => current_time('mysql')), array('id' => $participant_id), array('%d', '%s', '%s'), array('%d'));
                if (!empty($participant->user_id)) {
                    update_user_meta((int) $participant->user_id, 'rts_email_verified', '1');
                }
                $this->registration->log_timeline($participant_id, 'manual_email_verified', 'Email verified manually by Run The Seas Admin', array('admin_id' => get_current_user_id()));
                do_action('rts_participant_verified', $participant_id);
                $benefits = $this->registration->activate_verified_benefits($participant_id, get_current_user_id(), true);
                if (is_wp_error($benefits)) {
                    wp_send_json_error($benefits->get_error_message());
                }
                break;

            case 'issue_verified_benefits':
                do_action('rts_participant_verified', $participant_id);
                $benefits = $this->registration->activate_verified_benefits($participant_id, get_current_user_id(), true);
                if (is_wp_error($benefits)) {
                    wp_send_json_error($benefits->get_error_message());
                }
                break;

            case 'resend_certificate':
                $sent = $this->registration->send_certificate($participant_id, get_current_user_id());
                if (is_wp_error($sent)) {
                    wp_send_json_error($sent->get_error_message());
                }
                break;

            default:
                wp_send_json_error('Unknown participant action.');
        }

        wp_send_json_success('Participant record updated.');
    }

    /** Export the currently filtered participant dataset for Excel/CSV use. */
    public function ajax_export_participants()
    {
        check_ajax_referer('rts_admin_nonce', 'nonce');
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die('Unauthorized', 403);
        }
        global $wpdb;
        $search = isset($_GET['rts_search']) ? sanitize_text_field(wp_unslash($_GET['rts_search'])) : '';
        $verification = isset($_GET['verification']) ? sanitize_key(wp_unslash($_GET['verification'])) : '';
        $credit = isset($_GET['credit']) ? sanitize_key(wp_unslash($_GET['credit'])) : '';
        $suite = isset($_GET['suite']) ? sanitize_key(wp_unslash($_GET['suite'])) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
        $where = array('1=1');
        $params = array();
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR referral_code LIKE %s OR cabin_credit_number LIKE %s OR certificate_number LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if (in_array($verification, array('verified', 'unverified'), true)) {
            $where[] = 'email_verified = %d';
            $params[] = $verification === 'verified' ? 1 : 0;
        }
        if (in_array($credit, array('pending', 'approved', 'not_requested'), true)) {
            $where[] = 'cabin_credit_status = %s';
            $params[] = $credit;
        }
        if (in_array($suite, array('active', 'inactive', 'pending'), true)) {
            $where[] = 'captain_suite_status = %s';
            $params[] = $suite;
        }
        if ($date_from !== '') {
            $where[] = 'registration_date >= %s';
            $params[] = $date_from . ' 00:00:00';
        }
        if ($date_to !== '') {
            $where[] = 'registration_date <= %s';
            $params[] = $date_to . ' 23:59:59';
        }
        $table = $wpdb->prefix . 'rts_participants';
        $sql = 'SELECT id, first_name, last_name, email, phone, country, city, registration_date, email_verified, email_verification_date, referral_code, referral_count, successful_referrals, cabin_credit_status, cabin_credit_amount, cabin_credit_number, cabin_credit_issued_at, captain_suite_status, captain_suite_activated_at, certificate_number, certificate_issued_at, certificate_sent_at FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY registration_date DESC, id DESC';
        $participants = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="run-the-seas-participants-' . gmdate('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        if ($participants) {
            fputcsv($output, array_keys($participants[0]));
            foreach ($participants as $participant) {
                fputcsv($output, $participant);
            }
        } else {
            fputcsv($output, array('No participant records match the selected filters.'));
        }
        fclose($output);
        exit;
    }

    /** Save authorized corrections from the on-site participant record screen. */
    public function handle_participant_record_update()
    {
        if (empty($_POST['rts_save_participant_record'])) {
            return;
        }
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to update participant records.', 'run-the-seas'), 403);
        }
        check_admin_referer('rts_save_participant_record', 'rts_participant_record_nonce');

        $participant_id = absint($_POST['participant_id'] ?? 0);
        $participant = $this->registration->get_participant($participant_id);
        if (!$participant) {
            wp_die(esc_html__('Participant not found.', 'run-the-seas'), 404);
        }
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!is_email($email)) {
            wp_die(esc_html__('Please provide a valid email address.', 'run-the-seas'), 400);
        }
        $existing = $this->registration->get_participant_by_email($email);
        if ($existing && (int) $existing->id !== $participant_id) {
            wp_die(esc_html__('That email address belongs to another participant.', 'run-the-seas'), 400);
        }

        $email_changed = strtolower($email) !== strtolower($participant->email);
        $data = array(
            'email' => $email,
            'first_name' => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
            'last_name' => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),
            'phone' => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'country' => sanitize_text_field(wp_unslash($_POST['country'] ?? '')),
            'city' => sanitize_text_field(wp_unslash($_POST['city'] ?? '')),
            'address' => sanitize_textarea_field(wp_unslash($_POST['address'] ?? '')),
            'date_of_birth' => sanitize_text_field(wp_unslash($_POST['date_of_birth'] ?? '')),
            'gender' => sanitize_text_field(wp_unslash($_POST['gender'] ?? '')),
            'emergency_contact_name' => sanitize_text_field(wp_unslash($_POST['emergency_contact_name'] ?? '')),
            'emergency_contact_phone' => sanitize_text_field(wp_unslash($_POST['emergency_contact_phone'] ?? '')),
            'updated_at' => current_time('mysql'),
        );
        if ($email_changed) {
            $data['email_verified'] = 0;
            $data['email_verification_date'] = null;
            $data['email_verification_token'] = bin2hex(random_bytes(32));
            $data['captain_suite_status'] = 'inactive';
        }

        global $wpdb;
        if (false === $wpdb->update($wpdb->prefix . 'rts_participants', $data, array('id' => $participant_id))) {
            wp_die(esc_html__('The participant record could not be saved.', 'run-the-seas'), 500);
        }
        if ($email_changed) {
            $this->registration->sync_participant_email($participant_id, $participant->email, $email);
        }
        if (!empty($participant->user_id)) {
            wp_update_user(array('ID' => $participant->user_id, 'user_email' => $email, 'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'display_name' => trim($data['first_name'] . ' ' . $data['last_name'])));
        }
        $this->registration->log_timeline($participant_id, 'participant_record_updated', 'Participant record corrected by Run The Seas Admin', array('admin_id' => get_current_user_id()));
        if ($email_changed) {
            $this->registration->send_verification_email($participant_id, true);
        }
        wp_safe_redirect(add_query_arg('updated', '1', remove_query_arg('participant_id')));
        exit;
    }

    private function render_participant_editor($participant_id)
    {
        $participant = $this->registration->get_participant($participant_id);
        if (!$participant) {
            echo '<div class="notice notice-error"><p>Participant not found.</p></div>';
            return;
        }
        $back_url = remove_query_arg(array('participant_id', 'updated'));
        ?>
        <div class="wrap rts-operations-page">
            <p><a class="button" href="<?php echo esc_url($back_url); ?>">&larr; Back to participants</a></p>
            <h1><?php echo esc_html(trim($participant->first_name . ' ' . $participant->last_name)); ?> <small>#<?php echo esc_html($participant->id); ?></small></h1>
            <p><strong>Verification:</strong> <?php echo $participant->email_verified ? 'Verified' : 'Unverified'; ?> &nbsp; <strong>Suite:</strong> <?php echo esc_html(ucfirst($participant->captain_suite_status)); ?> &nbsp; <strong>Credit:</strong> <?php echo esc_html($participant->cabin_credit_number ?: 'Pending'); ?> &nbsp; <strong>Certificate:</strong> <?php echo esc_html($participant->certificate_number ?: 'Pending'); ?></p>
            <form method="post" class="rts-member-profile-form" style="max-width:850px;">
                <?php wp_nonce_field('rts_save_participant_record', 'rts_participant_record_nonce'); ?>
                <input type="hidden" name="rts_save_participant_record" value="1"><input type="hidden" name="participant_id" value="<?php echo esc_attr($participant->id); ?>">
                <div><label>First name <input required name="first_name" value="<?php echo esc_attr($participant->first_name); ?>"></label></div>
                <div><label>Last name <input required name="last_name" value="<?php echo esc_attr($participant->last_name); ?>"></label></div>
                <div><label>Email <input required type="email" name="email" value="<?php echo esc_attr($participant->email); ?>"></label></div>
                <div><label>Phone <input name="phone" value="<?php echo esc_attr($participant->phone); ?>"></label></div>
                <div><label>Country <input name="country" value="<?php echo esc_attr($participant->country); ?>"></label></div>
                <div><label>City <input name="city" value="<?php echo esc_attr($participant->city); ?>"></label></div>
                <div><label>Date of birth <input type="date" name="date_of_birth" value="<?php echo esc_attr($participant->date_of_birth); ?>"></label></div>
                <div><label>Gender <input name="gender" value="<?php echo esc_attr($participant->gender); ?>"></label></div>
                <div class="rts-profile-full"><label>Address <textarea name="address" rows="3"><?php echo esc_textarea($participant->address); ?></textarea></label></div>
                <div><label>Emergency contact name <input name="emergency_contact_name" value="<?php echo esc_attr($participant->emergency_contact_name); ?>"></label></div>
                <div><label>Emergency contact phone <input name="emergency_contact_phone" value="<?php echo esc_attr($participant->emergency_contact_phone); ?>"></label></div>
                <div class="rts-profile-full"><button type="submit">Save authorized corrections</button></div>
            </form>
            <p><small>Changing the email address sends a new verification email and pauses Captain's Suite access until the address is verified.</small></p>
        </div>
        <?php
    }

}
