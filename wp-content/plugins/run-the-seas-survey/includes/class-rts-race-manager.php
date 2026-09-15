<?php
/**
 * Class RTS_Race_Manager
 * Manages races, participant registration, and trophy earning
 */
class RTS_Race_Manager {
    
    private $db;
    private $registration;
    
    public function __construct($registration = null) {
        global $wpdb;
        $this->db = $wpdb;
        $this->registration = $registration instanceof RTS_Registration
            ? $registration
            : new RTS_Registration();
        
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // AJAX handlers
        add_action('wp_ajax_rts_register_for_race', array($this, 'ajax_register_for_race'));
        add_action('wp_ajax_rts_get_user_trophies', array($this, 'ajax_get_user_trophies'));
        add_action('wp_ajax_rts_earn_trophy', array($this, 'ajax_earn_trophy'));
        
        // Shortcodes
        add_shortcode('rts_races', array($this, 'render_races_page'));       
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'rts-survey-management',
            'Race Management',
            '🏃 Races',
            RTS_MANAGE_CAPABILITY,
            'rts-races',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Create a new race
     */
    public function create_race($data) {
        $inserted = $this->db->insert(
            $this->db->prefix . 'rts_races',
            array(
                'race_name' => sanitize_text_field($data['race_name']),
                'race_type' => sanitize_text_field($data['race_type']),
                'distance_km' => floatval($data['distance_km']),
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'location' => sanitize_text_field($data['location']),
                'description' => sanitize_textarea_field($data['description']),
                'trophy_image_url' => esc_url_raw($data['trophy_image_url']),
                'is_active' => isset($data['is_active']) ? 1 : 0,
                'created_at' => current_time('mysql')
            )
        );
        
        if ($inserted) {
            return $this->db->insert_id;
        }
        return false;
    }
    
    /**
     * Register participant for a race
     */
    public function register_for_race($participant_id, $race_id) {
        // Check if already registered
        $existing = $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM {$this->db->prefix}rts_race_participants 
                WHERE participant_id = %d AND race_id = %d",
                $participant_id,
                $race_id
            )
        );
        
        if ($existing) {
            return false;
        }
        
        $inserted = $this->db->insert(
            $this->db->prefix . 'rts_race_participants',
            array(
                'participant_id' => $participant_id,
                'race_id' => $race_id,
                'registration_date' => current_time('mysql'),
                'status' => 'registered',
                'created_at' => current_time('mysql')
            )
        );
        
        if ($inserted) {
            // Log activity
            $this->log_race_activity($participant_id, $race_id, 'registered', 'Registered for race');
            return $this->db->insert_id;
        }
        return false;
    }
    
    /**
     * Complete a race and earn trophy
     */
    public function complete_race($participant_id, $race_id, $completion_time, $rank_position = null) {
        // Update participant record
        $updated = $this->db->update(
            $this->db->prefix . 'rts_race_participants',
            array(
                'completion_time' => $completion_time,
                'completion_date' => current_time('mysql'),
                'status' => 'completed',
                'rank_position' => $rank_position
            ),
            array(
                'participant_id' => $participant_id,
                'race_id' => $race_id
            )
        );
        
        if ($updated !== false) {
            // Get race details
            $race = $this->get_race($race_id);
            
            // Determine medal type based on rank
            $medal_type = $this->determine_medal_type($rank_position);
            
            // Earn trophy
            $trophy_id = $this->earn_trophy(
                $participant_id,
                $race_id,
                $race->race_name,
                $medal_type,
                $rank_position
            );
            
            // Log activity
            $this->log_race_activity(
                $participant_id,
                $race_id,
                'completed',
                "Completed race in " . $completion_time . " - Rank: " . ($rank_position ?: 'N/A')
            );
            
            return $trophy_id;
        }
        
        return false;
    }
    
    /**
     * Earn a trophy
     */
    public function earn_trophy($participant_id, $race_id, $race_name, $medal_type, $rank_position = null) {
        $trophy_name = $this->generate_trophy_name($race_name, $medal_type);
        
        // Calculate achievement points
        $points = $this->calculate_achievement_points($medal_type, $rank_position);
        
        $inserted = $this->db->insert(
            $this->db->prefix . 'rts_user_trophies',
            array(
                'participant_id' => $participant_id,
                'race_id' => $race_id,
                'trophy_name' => $trophy_name,
                'trophy_type' => $medal_type,
                'trophy_rank' => $rank_position,
                'trophy_image_url' => $this->get_trophy_image($medal_type),
                'earned_date' => current_time('mysql'),
                'is_displayed' => 1,
                'achievement_points' => $points,
                'created_at' => current_time('mysql')
            )
        );
        
        if ($inserted) {
            $trophy_id = $this->db->insert_id;
            
            // Update participant's total points
            $this->update_participant_points($participant_id, $points);
            
            // Add to activity timeline
            $this->registration->log_timeline(
                $participant_id,
                'trophy_earned',
                "Earned {$medal_type} trophy for {$race_name}",
                array(
                    'trophy_id' => $trophy_id,
                    'medal_type' => $medal_type,
                    'points' => $points
                )
            );
            
            return $trophy_id;
        }
        
        return false;
    }
    
    /**
     * Get user's trophies
     */
    public function get_user_trophies($participant_id) {
        return $this->db->get_results(
            $this->db->prepare(
                "SELECT t.*, r.race_name, r.race_type, r.distance_km, r.location
                FROM {$this->db->prefix}rts_user_trophies t
                JOIN {$this->db->prefix}rts_races r ON t.race_id = r.id
                WHERE t.participant_id = %d AND t.is_displayed = 1
                ORDER BY t.earned_date DESC",
                $participant_id
            )
        );
    }
    
    /**
     * Get trophy statistics
     */
    public function get_trophy_stats($participant_id) {
        $stats = $this->db->get_row(
            $this->db->prepare(
                "SELECT 
                    COUNT(*) as total_trophies,
                    SUM(CASE WHEN trophy_type = 'gold' THEN 1 ELSE 0 END) as gold_medals,
                    SUM(CASE WHEN trophy_type = 'silver' THEN 1 ELSE 0 END) as silver_medals,
                    SUM(CASE WHEN trophy_type = 'bronze' THEN 1 ELSE 0 END) as bronze_medals,
                    SUM(achievement_points) as total_points
                FROM {$this->db->prefix}rts_user_trophies
                WHERE participant_id = %d AND is_displayed = 1",
                $participant_id
            )
        );
        
        return $stats;
    }
    
    /**
     * Get all active races
     */
    public function get_active_races() {
        return $this->db->get_results(
            "SELECT * FROM {$this->db->prefix}rts_races 
            WHERE is_active = 1 AND end_date >= NOW()
            ORDER BY start_date ASC"
        );
    }
    
    /**
     * Get participant's race status
     */
    public function get_participant_race_status($participant_id, $race_id) {
        return $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}rts_race_participants 
                WHERE participant_id = %d AND race_id = %d",
                $participant_id,
                $race_id
            )
        );
    }
    
    /**
     * Determine medal type based on rank
     */
    private function determine_medal_type($rank_position) {
        if (!$rank_position) {
            return 'participation';
        }
        
        if ($rank_position <= 3) {
            return 'gold';
        } elseif ($rank_position <= 10) {
            return 'silver';
        } elseif ($rank_position <= 25) {
            return 'bronze';
        }
        
        return 'participation';
    }
    
    /**
     * Generate trophy name
     */
    private function generate_trophy_name($race_name, $medal_type) {
        $medal_names = array(
            'gold' => '🏆 Gold Champion',
            'silver' => '🥈 Silver Medalist',
            'bronze' => '🥉 Bronze Medalist',
            'participation' => '🏅 Finisher'
        );
        
        $medal_name = isset($medal_names[$medal_type]) ? $medal_names[$medal_type] : '🏅 Participant';
        
        return $medal_name . ' - ' . $race_name;
    }
    
    /**
     * Get trophy image based on medal type
     */
    private function get_trophy_image($medal_type) {
        $images = array(
            'gold' => RTS_PLUGIN_URL . 'assets/images/gold-trophy.png',
            'silver' => RTS_PLUGIN_URL . 'assets/images/silver-trophy.png',
            'bronze' => RTS_PLUGIN_URL . 'assets/images/bronze-trophy.png',
            'participation' => RTS_PLUGIN_URL . 'assets/images/participation-trophy.png'
        );
        
        return isset($images[$medal_type]) ? $images[$medal_type] : $images['participation'];
    }
    
    /**
     * Calculate achievement points
     */
    private function calculate_achievement_points($medal_type, $rank_position) {
        $points = array(
            'gold' => 100,
            'silver' => 50,
            'bronze' => 25,
            'participation' => 10
        );
        
        $base_points = isset($points[$medal_type]) ? $points[$medal_type] : 5;
        
        // Bonus points for top 3
        if ($rank_position && $rank_position <= 3) {
            $base_points += (4 - $rank_position) * 10;
        }
        
        return $base_points;
    }
    
    /**
     * Update participant's total points
     */
    private function update_participant_points($participant_id, $points) {
        $this->db->query(
            $this->db->prepare(
                "UPDATE {$this->db->prefix}rts_participants 
                SET total_captain_miles_earned = total_captain_miles_earned + %d,
                    captain_miles_balance = captain_miles_balance + %d
                WHERE id = %d",
                $points,
                $points,
                $participant_id
            )
        );
    }
    
    /**
     * Log race activity
     */
    private function log_race_activity($participant_id, $race_id, $action, $description) {
        $this->db->insert(
            $this->db->prefix . 'rts_activity_logs',
            array(
                'tracking_id' => 0,
                'submission_id' => 'race_' . $race_id,
                'action' => $action,
                'description' => $description,
                'created_at' => current_time('mysql')
            )
        );
    }
    
    /**
     * Get race details
     */
    private function get_race($race_id) {
        return $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}rts_races WHERE id = %d",
                $race_id
            )
        );
    }
    
    /**
     * AJAX: Register for race
     */
    public function ajax_register_for_race() {
        check_ajax_referer('rts_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error('Please login to register for races');
        }
        
        $user = wp_get_current_user();
        $participant = $this->registration->get_participant_for_user($user);
        
        if (!$participant) {
            wp_send_json_error('Participant not found. Please complete registration first.');
        }
        
        $race_id = intval($_POST['race_id']);
        $result = $this->register_for_race($participant->id, $race_id);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => 'Successfully registered for the race!',
                'participant_id' => $participant->id,
                'race_id' => $race_id
            ));
        } else {
            wp_send_json_error('Failed to register. You may already be registered.');
        }
    }
    
    /**
     * AJAX: Get user trophies
     */
    public function ajax_get_user_trophies() {
        check_ajax_referer('rts_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error('Please login');
        }
        
        $user = wp_get_current_user();
        $participant = $this->registration->get_participant_for_user($user);
        
        if (!$participant) {
            wp_send_json_error('Participant not found');
        }
        
        $trophies = $this->get_user_trophies($participant->id);
        $stats = $this->get_trophy_stats($participant->id);
        
        wp_send_json_success(array(
            'trophies' => $trophies,
            'stats' => $stats,
            'participant' => $participant
        ));
    }
    
    /**
     * AJAX: Earn trophy (for race completion)
     */
    public function ajax_earn_trophy() {
        check_ajax_referer('rts_nonce', 'nonce');
        
        if (!current_user_can(RTS_MANAGE_CAPABILITY)) {
            wp_send_json_error('Unauthorized');
        }
        
        $participant_id = intval($_POST['participant_id']);
        $race_id = intval($_POST['race_id']);
        $completion_time = sanitize_text_field($_POST['completion_time']);
        $rank_position = intval($_POST['rank_position']);
        
        $result = $this->complete_race($participant_id, $race_id, $completion_time, $rank_position);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => 'Trophy earned successfully!',
                'trophy_id' => $result
            ));
        } else {
            wp_send_json_error('Failed to earn trophy');
        }
    }
    
    /**
     * Render races page
     */
    public function render_races_page($atts) {
        if (!is_user_logged_in()) {
            return '<p>Please <a href="' . rts_get_member_login_url(get_permalink()) . '">login</a> to view races.</p>';
        }
        
        $user = wp_get_current_user();
        $participant = $this->registration->get_participant_for_user($user);
        
        if (!$participant) {
            return '<p>Please complete your registration to participate in races.</p>';
        }
        
        $races = $this->get_active_races();
        
        ob_start();
        ?>
        <div class="rts-races-container">
            <h2>🏃 Available Races</h2>
            
            <?php if (empty($races)): ?>
                <p>No active races available at the moment. Check back soon!</p>
            <?php else: ?>
                <div class="rts-races-grid">
                    <?php foreach ($races as $race): 
                        $status = $this->get_participant_race_status($participant->id, $race->id);
                    ?>
                        <div class="rts-race-card">
                            <h3><?php echo esc_html($race->race_name); ?></h3>
                            <div class="rts-race-details">
                                <span class="race-type"><?php echo esc_html($race->race_type); ?></span>
                                <span class="race-distance"><?php echo esc_html($race->distance_km); ?> K</span>
                                <span class="race-date"><?php echo date('M j, Y', strtotime($race->start_date)); ?></span>
                            </div>
                            <div class="rts-race-actions">
                                <?php if ($status && $status->status === 'completed'): ?>
                                    <span class="badge-completed">✅ Completed</span>
                                <?php elseif ($status && $status->status === 'registered'): ?>
                                    <span class="badge-registered">⏳ Registered</span>
                                <?php else: ?>
                                    <button class="rts-btn-register" data-race-id="<?php echo $race->id; ?>">
                                        Register Now
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <style>
            .rts-races-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 20px;
            }
            .rts-race-card {
                background: #fff;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .rts-race-card h3 {
                margin-top: 0;
                color: #1a7efb;
            }
            .rts-race-details {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
                margin: 10px 0;
            }
            .rts-race-details span {
                background: #f0f0f0;
                padding: 4px 10px;
                border-radius: 20px;
                font-size: 12px;
            }
            .badge-completed { color: #28a745; }
            .badge-registered { color: #ffc107; }
            .rts-btn-register {
                background: #1a7efb;
                color: #fff;
                border: none;
                padding: 10px 20px;
                border-radius: 4px;
                cursor: pointer;
            }
            .rts-btn-register:hover {
                background: #1565c0;
            }
        </style>
        <script>
        jQuery(document).ready(function($) {
            $('.rts-btn-register').on('click', function() {
                var $btn = $(this);
                var raceId = $btn.data('race-id');
                
                if (!confirm('Register for this race?')) return;
                
                $btn.prop('disabled', true).text('Registering...');
                
                $.ajax({
                    type: 'POST',
                    url: rts_ajax.ajax_url,
                    data: {
                        action: 'rts_register_for_race',
                        race_id: raceId,
                        nonce: rts_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $btn.replaceWith('<span class="badge-registered">✅ Registered!</span>');
                        } else {
                            alert(response.data || 'Registration failed');
                            $btn.prop('disabled', false).text('Register Now');
                        }
                    },
                    error: function() {
                        alert('An error occurred. Please try again.');
                        $btn.prop('disabled', false).text('Register Now');
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }   
    
    
    /**
     * Render admin page for race management
     */
    public function render_admin_page() {
        // Admin interface for creating/managing races
        ?>
        <div class="wrap">
            <h1>🏃 Race Management</h1>
            <p>Create and manage races for participants to earn trophies.</p>
            
            <div class="rts-admin-race-form">
                <h2>Create New Race</h2>
                <form method="post" action="">
                    <table class="form-table">
                        <tr>
                            <th><label for="race_name">Race Name</label></th>
                            <td><input type="text" id="race_name" name="race_name" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="race_type">Race Type</label></th>
                            <td>
                                <select id="race_type" name="race_type">
                                    <option value="marathon">Marathon</option>
                                    <option value="half-marathon">Half Marathon</option>
                                    <option value="10k">10K</option>
                                    <option value="5k">5K</option>
                                    <option value="ultra">Ultra Marathon</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="distance_km">Distance (K)</label></th>
                            <td><input type="number" id="distance_km" name="distance_km" step="0.1" required></td>
                        </tr>
                        <tr>
                            <th><label for="start_date">Start Date</label></th>
                            <td><input type="datetime-local" id="start_date" name="start_date" required></td>
                        </tr>
                        <tr>
                            <th><label for="end_date">End Date</label></th>
                            <td><input type="datetime-local" id="end_date" name="end_date" required></td>
                        </tr>
                        <tr>
                            <th><label for="location">Location</label></th>
                            <td><input type="text" id="location" name="location" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="description">Description</label></th>
                            <td><textarea id="description" name="description" rows="3" class="large-text"></textarea></td>
                        </tr>
                        <tr>
                            <th><label for="is_active">Active</label></th>
                            <td><input type="checkbox" id="is_active" name="is_active" value="1" checked></td>
                        </tr>
                    </table>
                    <?php submit_button('Create Race', 'primary', 'submit_race'); ?>
                </form>
            </div>
        </div>
        <?php
        
        // Handle form submission
        if (isset($_POST['submit_race'])) {
            $data = array(
                'race_name' => $_POST['race_name'],
                'race_type' => $_POST['race_type'],
                'distance_km' => $_POST['distance_km'],
                'start_date' => $_POST['start_date'],
                'end_date' => $_POST['end_date'],
                'location' => $_POST['location'],
                'description' => $_POST['description'],
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'trophy_image_url' => ''
            );
            
            $result = $this->create_race($data);
            if ($result) {
                echo '<div class="notice notice-success"><p>Race created successfully! ID: ' . $result . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to create race.</p></div>';
            }
        }
    }
}

