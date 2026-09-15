<?php
/**
 * Class RTS_Form_Sync
 * Handles synchronization between Fluent Forms and RTS tracking
 * ONLY deletes statistics for fields that are explicitly removed
 */
class RTS_Form_Sync {
    
    private $db;
    private $last_sync_time = 0;
    private $is_syncing = false;
    private $pending_syncs = array();
    
    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        
        // Use the correct hook from Fluent Forms
        add_filter('fluentform/form_fields_update', array($this, 'handle_form_fields_update'), 999, 2);
        add_action('fluentform/form_duplicated', array($this, 'handle_form_duplicated'), 999, 2);
        add_action('fluentform/form_deleted', array($this, 'handle_form_deleted'), 999, 1);
        add_action('fluentform/after_partial_entry_deleted', array($this, 'handle_partial_entry_deleted'), 999, 2);
        add_action('fluentform/submission_deleted', array($this, 'handle_submission_deleted'), 999, 2);
        
        // CRITICAL: Add a delay to ensure database is updated
        add_action('shutdown', array($this, 'process_pending_syncs'), 100);
        
        // AJAX handler for manual sync
        add_action('wp_ajax_rts_sync_form_fields', array($this, 'ajax_sync_form_fields'));
        
    }
    
    /**
     * Handle form fields update - Queue for later processing
     */
    public function handle_form_fields_update($form_fields, $form_id) {
        error_log("RTS: Fluent Form fields update filter triggered for form: {$form_id}");
        
        // Prevent multiple syncs in the same request
        $current_time = microtime(true);
        if ($this->is_syncing || ($current_time - $this->last_sync_time) < 1) {
            error_log("RTS: Skipping duplicate sync for form: {$form_id}");
            return $form_fields;
        }
        
        // Store the form fields for delayed processing
        $this->pending_syncs[$form_id] = array(
            'form_fields' => $form_fields,
            'timestamp' => time()
        );
        
        error_log("RTS: Queued sync for form: {$form_id} (will process after page load)");
        
        return $form_fields;
    }
    
    /**
     * Process pending syncs on shutdown (after database is updated)
     */
    public function process_pending_syncs() {
        if (empty($this->pending_syncs)) {
            return;
        }
        
        error_log("RTS: Processing " . count($this->pending_syncs) . " pending syncs");
        
        foreach ($this->pending_syncs as $form_id => $data) {
            // Wait a moment to ensure database is updated
            sleep(1);
            
            error_log("RTS: Processing delayed sync for form: {$form_id}");
            
            $this->is_syncing = true;
            $this->last_sync_time = microtime(true);
            
            try {
                // Re-fetch the form fields from database (now it should have the latest data)
                $form_fields = $this->get_form_fields_from_db($form_id);
                if ($form_fields) {
                    $this->sync_form_fields($form_id, $form_fields);
                } else {
                    error_log("RTS: Could not fetch form fields for delayed sync: {$form_id}");
                }
            } catch (Exception $e) {
                error_log("RTS: Error during delayed sync: " . $e->getMessage());
            }
            
            $this->is_syncing = false;
        }
        
        $this->pending_syncs = array();
    }
    
    public function ajax_sync_form_fields() {
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
        
        $form_fields = $this->get_form_fields_from_db($form_id);
        $result = $this->sync_form_fields($form_id, $form_fields);
        
        if (!empty($result)) {
            wp_send_json_success(array(
                'message' => 'Form synced successfully. Removed fields cleaned up.',
                'deleted_fields' => $result
            ));
        } else {
            wp_send_json_success(array(
                'message' => 'Form synced. No changes detected.',
                'deleted_fields' => array()
            ));
        }
    }
    
    /**
     * Normalize field name for comparison
     */
    private function normalize_field_name($field_name) {
        return str_replace('[]', '', $field_name);
    }
    
    /**
     * Log activity in the tracking logs
     */
    private function log_activity($form_id, $action, $description, $data = array()) {
        global $wpdb;
        
        $log_table = $wpdb->prefix . 'rts_activity_logs';
        
        // Create table if it doesn't exist
        if ($wpdb->get_var("SHOW TABLES LIKE '$log_table'") != $log_table) {
            $this->create_activity_log_table();
        }
        
        $wpdb->insert(
            $log_table,
            array(
                'tracking_id' => 0, // 0 for system activities
                'submission_id' => 'system',
                'action' => $action,
                'description' => $description . ' - ' . json_encode($data),
                'created_at' => current_time('mysql')
            )
        );
        
        error_log("RTS: Logged activity: {$action} - {$description}");
    }
    
    /**
     * Create activity log table
     */
    private function create_activity_log_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'rts_activity_logs';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            tracking_id bigint(20) NOT NULL,
            submission_id varchar(36) NOT NULL,
            action varchar(50) NOT NULL,
            description text DEFAULT NULL,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY tracking_id (tracking_id),
            KEY submission_id (submission_id),
            KEY action (action)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Main sync function
     */
    public function sync_form_fields($form_id, $form_fields = null) {
        error_log("RTS: Syncing form ID: {$form_id}");
        
        // Get current field names from the form (normalized)
        $current_fields_raw = $this->get_form_field_names($form_id, $form_fields);
        $current_fields = array_map(array($this, 'normalize_field_name'), $current_fields_raw);
        error_log("RTS: Current fields in form (normalized): " . print_r($current_fields, true));
        
        // Get previously tracked field names (normalized)
        $tracked_fields_raw = $this->get_tracked_field_names($form_id);
        $tracked_fields = array_map(array($this, 'normalize_field_name'), $tracked_fields_raw);
        error_log("RTS: Previously tracked fields (normalized): " . print_r($tracked_fields, true));
        
        // Only proceed if we have current fields (to avoid accidental deletion)
        if (empty($current_fields)) {
            error_log("RTS: WARNING - No current fields found. Skipping sync to prevent accidental data loss.");
            return array();
        }
        
        // Find fields that are TRULY removed (exist in tracked but NOT in current)
        $removed_fields_normalized = array_diff($tracked_fields, $current_fields);
        
        // Map back to original field names for deletion
        $removed_fields = array();
        foreach ($removed_fields_normalized as $normalized_name) {
            // Find the original tracked field name that matches this normalized name
            foreach ($tracked_fields_raw as $original_name) {
                if ($this->normalize_field_name($original_name) === $normalized_name) {
                    $removed_fields[] = $original_name;
                    break;
                }
            }
        }
        
        $deleted_counts = array();
        $total_deleted_answers = 0;
        $total_deleted_analytics = 0;
        $deleted_field_names = array();
        
        // ONLY delete data for fields that are explicitly removed
        if (!empty($removed_fields)) {
            error_log("RTS: Fields to remove: " . implode(', ', $removed_fields));
            
            foreach ($removed_fields as $field_name) {
                // Double-check: is this field REALLY not in the form?
                if (!$this->is_field_in_form($form_id, $field_name, $form_fields)) {
                    $result = $this->cleanup_field_statistics($form_id, $field_name);
                    $deleted_counts[$field_name] = $result;
                    $total_deleted_answers += $result['deleted_answers'];
                    $total_deleted_analytics += $result['deleted_analytics'];
                    $deleted_field_names[] = $field_name;
                    error_log("RTS: Deleted data for field: {$field_name} - {$result['deleted_answers']} answers, {$result['deleted_analytics']} analytics");
                } else {
                    error_log("RTS: Field {$field_name} is still in the form, skipping deletion");
                }
            }
            
            // Log the activity if anything was deleted
            if (!empty($deleted_counts)) {
                // Log to activity logs
                $this->log_activity(
                    $form_id,
                    'field_removed',
                    "Removed fields: " . implode(', ', $deleted_field_names),
                    array(
                        'form_id' => $form_id,
                        'fields' => $deleted_field_names,
                        'deleted_answers' => $total_deleted_answers,
                        'deleted_analytics' => $total_deleted_analytics
                    )
                );
                
                // Also log to sync logs
                $this->log_sync_activity($form_id, 'field_removed', array(
                    'fields' => $deleted_field_names,
                    'deleted_answers' => $total_deleted_answers,
                    'deleted_analytics' => $total_deleted_analytics
                ));
            }
        } else {
            error_log("RTS: No fields to remove");
        }
        
        return $deleted_counts;
    }
    
    /**
     * Get all field names from a Fluent Form
     */
    private function get_form_field_names($form_id, $form_fields = null) {
        $fields = array();
        
        // If form_fields is provided (from hook), use it directly
        if ($form_fields !== null && is_array($form_fields)) {
            error_log("RTS: Using provided form_fields array");
            $fields = $this->extract_fields_from_array($form_fields);
        } else {
            // Try multiple methods to get form fields
            error_log("RTS: Attempting to get form fields from database");
            
            // Method 1: Get from FluentForm model
            if (class_exists('FluentForm\App\Models\Form')) {
                try {
                    $form = \FluentForm\App\Models\Form::find($form_id);
                    if ($form && !empty($form->form_fields)) {
                        error_log("RTS: Found form in database via model");
                        $form_fields_data = $form->form_fields;
                        if (is_string($form_fields_data)) {
                            $form_fields_data = json_decode($form_fields_data, true);
                        }
                        if (is_array($form_fields_data)) {
                            $fields = $this->extract_fields_from_array($form_fields_data);
                        }
                    }
                } catch (Exception $e) {
                    error_log("RTS: Error getting form via model: " . $e->getMessage());
                }
            }
            
            // Method 2: Direct database query
            if (empty($fields)) {
                $table_name = $this->db->prefix . 'fluentform_forms';
                $result = $this->db->get_var(
                    $this->db->prepare(
                        "SELECT form_fields FROM $table_name WHERE id = %d",
                        $form_id
                    )
                );
                
                if ($result) {
                    error_log("RTS: Found form via direct database query");
                    $form_fields_data = json_decode($result, true);
                    if (is_array($form_fields_data)) {
                        $fields = $this->extract_fields_from_array($form_fields_data);
                    }
                }
            }
            
            // Method 3: Try to get from WordPress options
            if (empty($fields)) {
                $option_key = 'fluentform_form_' . $form_id;
                $option_value = get_option($option_key);
                if ($option_value) {
                    error_log("RTS: Found form via WordPress options");
                    $form_fields_data = maybe_unserialize($option_value);
                    if (is_array($form_fields_data) && isset($form_fields_data['form_fields'])) {
                        $fields = $this->extract_fields_from_array($form_fields_data['form_fields']);
                    }
                }
            }
        }
        
        // Add array versions for checkbox/multi-select fields
        $fields_with_arrays = array();
        foreach ($fields as $field) {
            $fields_with_arrays[] = $field;
        }
        
        error_log("RTS: Extracted " . count($fields_with_arrays) . " fields from form");
        return array_unique($fields_with_arrays);
    }
    
    /**
     * Extract field names from a form fields array
     */
    private function extract_fields_from_array($form_fields) {
        $fields = array();
        
        // Handle different formats
        if (isset($form_fields['form_fields'])) {
            $form_fields = $form_fields['form_fields'];
        }
        
        if (isset($form_fields['fields']) && is_array($form_fields['fields'])) {
            $this->extract_field_names_recursive($form_fields['fields'], $fields);
        } else if (is_array($form_fields)) {
            // Try to find fields directly
            foreach ($form_fields as $key => $value) {
                if ($key === 'fields' && is_array($value)) {
                    $this->extract_field_names_recursive($value, $fields);
                } else if (isset($value['attributes']['name'])) {
                    $field_name = $value['attributes']['name'];
                    if (!empty($field_name)) {
                        $fields[] = $field_name;
                    }
                }
            }
        }
        
        return $fields;
    }
    
    /**
     * Recursively extract field names
     */
    private function extract_field_names_recursive($fields_array, &$fields) {
        foreach ($fields_array as $field) {
            // Check if this is a field with a name attribute
            $element = $field['element'] ?? '';
            $field_name = $field['attributes']['name'] ?? '';
            
            // Skip containers and non-question elements
            $skip_elements = [
                'form_step', 'step_start', 'step_end', 'button', 
                'container', 'column', 'section_break', 'html', 
                'shortcode', 'custom_html'
            ];
            
            if (in_array($element, $skip_elements)) {
                // Check if this container has child fields
                if (isset($field['fields']) && is_array($field['fields'])) {
                    $this->extract_field_names_recursive($field['fields'], $fields);
                }
                continue;
            }
            
            // If we have a field name, add it
            if (!empty($field_name)) {
                $fields[] = $field_name;
                
                // For checkbox/multi-select, also track the array version
                if (in_array($element, ['checkbox', 'multi_select'])) {
                    $fields[] = $field_name . '[]';
                }
            }
            
            // Check for nested fields
            if (isset($field['fields']) && is_array($field['fields'])) {
                $this->extract_field_names_recursive($field['fields'], $fields);
            }
        }
    }
    
    /**
     * Get tracked field names
     */
    private function get_tracked_field_names($form_id) {
        $fields = array();
        
        // Get from answers
        $results = $this->db->get_col(
            $this->db->prepare(
                "SELECT DISTINCT question_id FROM {$this->db->prefix}rts_survey_answers WHERE form_id = %d",
                $form_id
            )
        );
        
        $fields = array_merge($fields, $results);
        
        // Get from analytics
        $analytics_results = $this->db->get_col(
            $this->db->prepare(
                "SELECT DISTINCT question_id FROM {$this->db->prefix}rts_survey_analytics WHERE form_id = %d",
                $form_id
            )
        );
        
        $fields = array_merge($fields, $analytics_results);
        return array_unique($fields);
    }
    
    /**
     * Check if a field exists in the form (with normalized comparison)
     */
    private function is_field_in_form($form_id, $field_name, $form_fields = null) {
        $current_fields = $this->get_form_field_names($form_id, $form_fields);
        $normalized_field = $this->normalize_field_name($field_name);
        
        foreach ($current_fields as $current_field) {
            if ($this->normalize_field_name($current_field) === $normalized_field) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get form fields from database
     */
    private function get_form_fields_from_db($form_id) {
        // Try multiple methods to get form fields
        
        // Method 1: FluentForm Model
        if (class_exists('FluentForm\App\Models\Form')) {
            try {
                $form = \FluentForm\App\Models\Form::find($form_id);
                if ($form && !empty($form->form_fields)) {
                    $form_fields = $form->form_fields;
                    if (is_string($form_fields)) {
                        return json_decode($form_fields, true);
                    }
                    return $form_fields;
                }
            } catch (Exception $e) {
                error_log("RTS: Error getting form via model: " . $e->getMessage());
            }
        }
        
        // Method 2: Direct database query
        $table_name = $this->db->prefix . 'fluentform_forms';
        $result = $this->db->get_var(
            $this->db->prepare(
                "SELECT form_fields FROM $table_name WHERE id = %d",
                $form_id
            )
        );
        
        if ($result) {
            return json_decode($result, true);
        }
        
        // Method 3: WordPress options
        $option_key = 'fluentform_form_' . $form_id;
        $option_value = get_option($option_key);
        if ($option_value) {
            return maybe_unserialize($option_value);
        }
        
        return null;
    }
    
    /**
     * Clean up statistics for a specific field
     */
    private function cleanup_field_statistics($form_id, $field_name) {
        error_log("RTS: Cleaning up statistics for field: {$field_name} in form: {$form_id}");
        
        $deleted_answers = 0;
        $deleted_analytics = 0;
        
        // Try multiple variations of the field name
        $variations = array(
            $field_name,
            $field_name . '[]',
            str_replace('[]', '', $field_name),
        );
        $variations = array_unique($variations);
        
        foreach ($variations as $variation) {
            // Delete answers
            $deleted = $this->db->delete(
                $this->db->prefix . 'rts_survey_answers',
                array(
                    'form_id' => $form_id,
                    'question_id' => $variation
                ),
                array('%d', '%s')
            );
            
            if ($deleted !== false) {
                $deleted_answers += $deleted;
                if ($deleted > 0) {
                    error_log("RTS: Deleted {$deleted} answers for variation: {$variation}");
                }
            }
            
            // Delete analytics
            $deleted = $this->db->delete(
                $this->db->prefix . 'rts_survey_analytics',
                array(
                    'form_id' => $form_id,
                    'question_id' => $variation
                ),
                array('%d', '%s')
            );
            
            if ($deleted !== false) {
                $deleted_analytics += $deleted;
                if ($deleted > 0) {
                    error_log("RTS: Deleted {$deleted} analytics records for variation: {$variation}");
                }
            }
        }
        
        return array(
            'deleted_answers' => $deleted_answers,
            'deleted_analytics' => $deleted_analytics
        );
    }
    
    /**
     * Handle form duplication
     */
    public function handle_form_duplicated($new_form_id, $old_form_id) {
        error_log("RTS: Form {$old_form_id} duplicated to {$new_form_id}");
        
        // Log the duplication
        $this->log_activity(
            $new_form_id,
            'form_duplicated',
            "Form duplicated from ID: {$old_form_id}",
            array(
                'new_form_id' => $new_form_id,
                'old_form_id' => $old_form_id
            )
        );
        
        $settings = get_option('rts_survey_settings', array());
        if (isset($settings[$old_form_id])) {
            $settings[$new_form_id] = $settings[$old_form_id];
            update_option('rts_survey_settings', $settings);
        }
        
        $this->log_sync_activity($new_form_id, 'form_duplicated', array('from_form' => $old_form_id));
    }
    
    /**
     * Handle form deletion
     */
    public function handle_form_deleted($form_id) {
        error_log("RTS: Form {$form_id} deleted - cleaning up all statistics");
        
        // Log before deletion
        $this->log_activity(
            $form_id,
            'form_deleted',
            "Form {$form_id} was deleted",
            array('form_id' => $form_id)
        );
        
        $tracking = $this->get_tracking_instance();
        if ($tracking) {
            $tracking->reset_survey_statistics($form_id);
        }
        
        $this->log_sync_activity($form_id, 'form_deleted', array());
    }
    
    /**
     * Handle partial entry deletion
     */
    public function handle_partial_entry_deleted($entry_id, $form_id) {
        error_log("RTS: Partial entry deleted for form: {$form_id}, entry: {$entry_id}");
        
        $this->log_activity(
            $form_id,
            'partial_entry_deleted',
            "Partial entry {$entry_id} deleted",
            array('entry_id' => $entry_id)
        );
    }
    
    /**
     * Handle submission deletion
     */
    public function handle_submission_deleted($submission_ids, $form_id) {
        error_log("RTS: Submissions deleted for form: {$form_id}, IDs: " . implode(', ', $submission_ids));
        
        $this->log_activity(
            $form_id,
            'submissions_deleted',
            "Submissions deleted: " . implode(', ', $submission_ids),
            array('submission_ids' => $submission_ids)
        );
    }
    
    /**
     * Log sync activities
     */
    private function log_sync_activity($form_id, $action, $data) {
        $log_table = $this->db->prefix . 'rts_sync_logs';
        
        if ($this->db->get_var("SHOW TABLES LIKE '$log_table'") != $log_table) {
            $this->create_log_table();
        }
        
        $this->db->insert(
            $log_table,
            array(
                'form_id' => $form_id,
                'action' => $action,
                'data' => json_encode($data),
                'created_at' => current_time('mysql')
            )
        );
    }
    
    /**
     * Create sync logs table
     */
    private function create_log_table() {
        $charset_collate = $this->db->get_charset_collate();
        $table_name = $this->db->prefix . 'rts_sync_logs';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            form_id bigint(20) NOT NULL,
            action varchar(50) NOT NULL,
            data text DEFAULT NULL,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY action (action)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Get tracking instance
     */
    private function get_tracking_instance() {
        if (function_exists('rts_init')) {
            $plugin = rts_init();
            if ($plugin && isset($plugin->tracking)) {
                return $plugin->tracking;
            }
        }
        
        global $wpdb;
        if (class_exists('RTS_Tracking')) {
            return new RTS_Tracking($wpdb);
        }
        
        return null;
    }
}

// Initialize the form sync
if (is_admin()) {
    new RTS_Form_Sync();
}
