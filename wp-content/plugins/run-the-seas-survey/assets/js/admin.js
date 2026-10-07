jQuery(document).ready(function($) {
    
    // Check if rts_admin is defined
    if (typeof rts_admin === 'undefined') {
        console.error('RTS Admin: rts_admin is not defined. Please check the localization.');
        return;
    }
    
    // console.log('RTS Admin initialized');
    // console.log('AJAX URL:', rts_admin.ajax_url);

    $('#rts-create-race-form').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $btn = $form.find('.rts-submit-race');
        var $message = $('#rts-race-message');
        
        // Validate form
        var raceName = $('#race_name').val();
        var distance = $('#distance_km').val();
        var startDate = $('#start_date').val();
        var endDate = $('#end_date').val();
        
        if (!raceName || !distance || !startDate || !endDate) {
            $message.html('<div class="notice notice-error"><p>Please fill in all required fields.</p></div>').show();
            return;
        }
        
        // Collect form data
        var formData = $form.serialize();
        formData += '&action=rts_create_race&nonce=' + rts_admin.nonce;
        
        $btn.prop('disabled', true).text('Creating...');
        $message.hide();
        
        $.ajax({
            type: 'POST',
            url: rts_admin.ajax_url,
            data: formData,
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false).text('Create Race');
                
                if (response.success) {
                    $message.html('<div class="notice notice-success"><p>✅ ' + response.data.message + '</p></div>').show();
                    $form[0].reset();
                    
                    // Reload the race list
                    loadRaces();
                } else {
                    $message.html('<div class="notice notice-error"><p>❌ ' + (response.data || 'Failed to create race') + '</p></div>').show();
                }
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false).text('Create Race');
                $message.html('<div class="notice notice-error"><p>❌ AJAX error: ' + error + '</p></div>').show();
                console.error('Race Creation Error:', xhr);
            }
        });
    });
    
    // Load Races
    function loadRaces() {
        $('#rts-races-list').html('<div class="spinner is-active"></div> Loading races...');
        
        $.ajax({
            type: 'POST',
            url: rts_admin.ajax_url,
            data: {
                action: 'rts_get_races',
                nonce: rts_admin.nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    renderRaceList(response.data);
                } else {
                    $('#rts-races-list').html('<p>No races found. Create your first race!</p>');
                }
            },
            error: function() {
                $('#rts-races-list').html('<p class="error">Error loading races.</p>');
            }
        });
    }
    
    function renderRaceList(races) {
        if (!races || races.length === 0) {
            $('#rts-races-list').html('<p>No races created yet. Use the form above to create your first race!</p>');
            return;
        }
        
        var html = '<table class="wp-list-table widefat fixed striped">';
        html += '<thead><tr><th>Race Name</th><th>Type</th><th>Distance</th><th>Date</th><th>Participants</th><th>Status</th><th>Actions</th></tr></thead>';
        html += '<tbody>';
        
        races.forEach(function(race) {
            var status = race.is_active ? '✅ Active' : '⏸️ Inactive';
            var statusClass = race.is_active ? 'active' : 'inactive';
            
            html += '<tr>';
            html += '<td><strong>' + race.race_name + '</strong></td>';
            html += '<td>' + race.race_type + '</td>';
            html += '<td>' + race.distance_km + ' KM</td>';
            html += '<td>' + formatDate(race.start_date) + '</td>';
            html += '<td>' + (race.participant_count || 0) + '</td>';
            html += '<td><span class="race-status ' + statusClass + '">' + status + '</span></td>';
            html += '<td>';
            html += '<button class="button button-small rts-manage-race" data-race-id="' + race.id + '">Manage</button> ';
            html += '<button class="button button-small rts-delete-race" data-race-id="' + race.id + '">Delete</button>';
            html += '</td>';
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        $('#rts-races-list').html(html);
        
        // Bind manage race button
        $('.rts-manage-race').on('click', function() {
            var raceId = $(this).data('race-id');
            openRaceManager(raceId);
        });
        
        // Bind delete race button
        $('.rts-delete-race').on('click', function() {
            var raceId = $(this).data('race-id');
            if (confirm('Are you sure you want to delete this race? This will also remove all participant data.')) {
                deleteRace(raceId);
            }
        });
    }
    
    function deleteRace(raceId) {
        $.ajax({
            type: 'POST',
            url: rts_admin.ajax_url,
            data: {
                action: 'rts_delete_race',
                race_id: raceId,
                nonce: rts_admin.nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    loadRaces();
                    showRaceMessage('✅ Race deleted successfully!', 'success');
                } else {
                    showRaceMessage('❌ Failed to delete race: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function() {
                showRaceMessage('❌ Error deleting race', 'error');
            }
        });
    }
    
    function formatDate(dateString) {
        if (!dateString) return 'N/A';
        var date = new Date(dateString);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }
    
    function showRaceMessage(message, type) {
        var $msg = $('#rts-race-message');
        var color = type === 'success' ? '#28a745' : '#dc3545';
        $msg.html('<div class="notice notice-' + type + '"><p>' + message + '</p></div>').show();
        setTimeout(function() {
            $msg.fadeOut();
        }, 5000);
    }
    
    // Open Race Manager (for adding participants and results)
    function openRaceManager(raceId) {
        // Show race management modal
        var $modal = $('#rts-race-manager-modal');
        $modal.show();
        $modal.find('.rts-race-id').val(raceId);
        
        // Load participants for this race
        loadRaceParticipants(raceId);
    }
    
    function loadRaceParticipants(raceId) {
        $('#rts-race-participants').html('<div class="spinner is-active"></div> Loading participants...');
        
        $.ajax({
            type: 'POST',
            url: rts_admin.ajax_url,
            data: {
                action: 'rts_get_race_participants',
                race_id: raceId,
                nonce: rts_admin.nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    renderParticipants(response.data);
                } else {
                    $('#rts-race-participants').html('<p>No participants yet.</p>');
                }
            },
            error: function() {
                $('#rts-race-participants').html('<p class="error">Error loading participants.</p>');
            }
        });
    }
    
    function renderParticipants(participants) {
        if (!participants || participants.length === 0) {
            $('#rts-race-participants').html('<p>No participants registered for this race yet.</p>');
            return;
        }
        
        var html = '<table class="wp-list-table widefat fixed striped">';
        html += '<thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Time</th><th>Rank</th><th>Actions</th></tr></thead>';
        html += '<tbody>';
        
        participants.forEach(function(p) {
            var status = p.status || 'registered';
            var statusLabels = {
                'registered': '📝 Registered',
                'completed': '✅ Completed',
                'dnf': '❌ DNF'
            };
            
            html += '<tr>';
            html += '<td>' + p.first_name + ' ' + p.last_name + '</td>';
            html += '<td>' + p.email + '</td>';
            html += '<td>' + (statusLabels[status] || status) + '</td>';
            html += '<td>' + (p.completion_time || '—') + '</td>';
            html += '<td>' + (p.rank_position || '—') + '</td>';
            html += '<td>';
            if (status !== 'completed') {
                html += '<button class="button button-small rts-complete-race" data-participant-id="' + p.participant_id + '" data-race-id="' + p.race_id + '">Complete</button>';
            } else {
                html += '✅ Done';
            }
            html += '</td>';
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        $('#rts-race-participants').html(html);
        
        // Bind complete race button
        $('.rts-complete-race').on('click', function() {
            var participantId = $(this).data('participant-id');
            var raceId = $(this).data('race-id');
            completeRace(participantId, raceId);
        });
    }
    
    function completeRace(participantId, raceId) {
        var time = prompt('Enter completion time (HH:MM:SS):', '02:30:00');
        if (time === null) return;
        
        var rank = prompt('Enter rank position:', '1');
        if (rank === null) return;
        
        $.ajax({
            type: 'POST',
            url: rts_admin.ajax_url,
            data: {
                action: 'rts_complete_race',
                participant_id: participantId,
                race_id: raceId,
                completion_time: time,
                rank_position: rank,
                nonce: rts_admin.nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showRaceMessage('✅ Race completed! Trophy earned!', 'success');
                    loadRaceParticipants(raceId);
                } else {
                    showRaceMessage('❌ Failed to complete race: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function() {
                showRaceMessage('❌ Error completing race', 'error');
            }
        });
    }
    
    // Close modal
    $('.rts-modal-close, .rts-modal-overlay').on('click', function() {
        $('#rts-race-manager-modal').hide();
    });
    
    // Toggle excluded status button
    $('.rts-toggle-excluded').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var formId = $btn.data('form-id');
        var currentExcluded = parseInt($btn.data('excluded'));
        var newExcluded = currentExcluded ? 0 : 1;
        
        var actionText = currentExcluded ? 'un-exclude' : 'exclude';
        var confirmText = currentExcluded ? 
            'Are you sure you want to un-exclude this survey? Tracking will be enabled.' : 
            'Are you sure you want to exclude this survey? No data will be collected.';
        
        if (!confirm(confirmText)) {
            return;
        }
        
        // Show loading state
        var originalText = $btn.text();
        $btn.prop('disabled', true).text('Processing...');
        
        $.ajax({
            type: 'POST',
            url: rts_admin.ajax_url,
            data: {
                action: 'rts_toggle_excluded',
                form_id: formId,
                excluded: newExcluded,
                nonce: rts_admin.nonce
            },
            dataType: 'json',
            success: function(response) {
                // console.log('Toggle Exclude Response:', response);
                
                if (response.success) {
                    // Update button
                    $btn.data('excluded', newExcluded);
                    $btn.text(newExcluded ? 'Un-exclude' : 'Exclude');
                    $btn.prop('disabled', false);
                    $btn.toggleClass('button-primary', newExcluded);
                    
                    // Update status badge
                    var $row = $btn.closest('tr');
                    updateRowStatus(formId, $row);
                    
                    showMessage('✅ Survey ' + (newExcluded ? 'excluded from' : 'un-excluded from') + ' tracking successfully!', 'success');
                } else {
                    showMessage('❌ Failed to update excluded status: ' + (response.data || 'Unknown error'), 'error');
                    $btn.prop('disabled', false);
                    $btn.text(originalText);
                }
            },
            error: function(xhr, status, error) {
                console.error('Toggle Exclude Error:', xhr);
                console.error('Response Text:', xhr.responseText);
                showMessage('❌ AJAX error: ' + error + '. Please check the console for details.', 'error');
                $btn.prop('disabled', false);
                $btn.text(originalText);
            }
        });
    });
    
    // Helper function to update row status
    function updateRowStatus(formId, $row) {
        $.ajax({
            type: 'POST',
            url: rts_admin.ajax_url,
            data: {
                action: 'rts_check_survey_status',
                form_id: formId,
                nonce: rts_admin.nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var data = response.data;
                    var statusHtml = '<span class="rts-status-badge rts-status-' + data.status.class + '">' + 
                                     data.status.label + '</span>';
                    $row.find('td:eq(1)').html(statusHtml);
                }
            },
            error: function(xhr) {
                console.error('Failed to update status:', xhr);
            }
        });
    }
    
    // Show message function
    function showMessage(message, type) {
        var $message = $('#rts-settings-message');
        if (!$message.length) {
            // If we're on the main page, create a message container
            $message = $('<div id="rts-settings-message" class="notice" style="display:none;"></div>');
            $('.wrap h1').after($message);
        }
        
        $message.removeClass('notice-success notice-error notice-warning notice-info')
               .addClass('notice-' + type)
               .html('<p>' + message + '</p>')
               .show();
        
        clearTimeout(window.messageTimeout);
        window.messageTimeout = setTimeout(function() {
            $message.slideUp(function() {
                $(this).hide();
            });
        }, 5000);
    }
    
    // Toggle survey status button
    $('.rts-toggle-survey').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var formId = $btn.data('form-id');
        var currentActive = parseInt($btn.data('active'));
        var newActive = currentActive ? 0 : 1;
        
        var actionText = currentActive ? 'deactivate' : 'activate';
        if (!confirm('Are you sure you want to ' + actionText + ' this survey?')) {
            return;
        }
        
        $btn.prop('disabled', true).text('Processing...');
        
        $.ajax({
            type: 'POST',
            url: rts_admin.ajax_url,
            data: {
                action: 'rts_toggle_survey',
                form_id: formId,
                active: newActive,
                nonce: rts_admin.nonce
            },
            dataType: 'json',
            success: function(response) {
                // console.log('Toggle Survey Response:', response);
                if (response.success) {
                    location.reload();
                } else {
                    showMessage('❌ Failed to update survey status: ' + (response.data || 'Unknown error'), 'error');
                    $btn.prop('disabled', false);
                    $btn.text(currentActive ? 'Deactivate' : 'Activate');
                }
            },
            error: function(xhr, status, error) {
                console.error('Toggle Error:', xhr);
                showMessage('❌ AJAX error: ' + error, 'error');
                $btn.prop('disabled', false);
                $btn.text(currentActive ? 'Deactivate' : 'Activate');
            }
        });
    });
    
    // Save settings
    $('#rts-save-settings').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var formId = $('#rts-form-id').val();
        var active = $('#rts-survey-active').is(':checked') ? 1 : 0;
        var excluded = $('#rts-survey-excluded').is(':checked') ? 1 : 0;
        var startDate = $('#rts-start-date').val();
        var endDate = $('#rts-end-date').val();
        
        if (startDate) {
            startDate = startDate.replace('T', ' ') + ':00';
        }
        if (endDate) {
            endDate = endDate.replace('T', ' ') + ':00';
        }
        
        $btn.prop('disabled', true).text('Saving...');
        $('#rts-saving-indicator').show();
        $('#rts-settings-message').hide();
        
        if (startDate && endDate && startDate > endDate) {
            showMessage('Start date must be before end date.', 'error');
            $btn.prop('disabled', false).text('Save Settings');
            $('#rts-saving-indicator').hide();
            return;
        }
        
        $.ajax({
            type: 'POST',
            url: rts_admin.ajax_url,
            data: {
                action: 'rts_save_survey_settings',
                form_id: formId,
                active: active,
                excluded: excluded,
                start_date: startDate,
                end_date: endDate,
                nonce: rts_admin.nonce
            },
            dataType: 'json',
            success: function(response) {
                // console.log('AJAX Response:', response);
                $('#rts-saving-indicator').hide();
                $btn.prop('disabled', false).text('Save Settings');
                
                if (response.success) {
                    showMessage('✅ Settings saved successfully!', 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showMessage('❌ Failed to save settings: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', xhr);
                $('#rts-saving-indicator').hide();
                $btn.prop('disabled', false).text('Save Settings');
                showMessage('❌ AJAX error: ' + error + ' - Check console for details', 'error');
            }
        });
    });
});


jQuery(document).ready(function($) {
    
    // Reset entire survey
    $('.rts-reset-survey').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var formId = $btn.data('form-id');
        var $status = $('#rts-reset-survey-status');
        
        if (!confirm('⚠️ Are you sure you want to reset ALL statistics for this survey? This action cannot be undone!')) {
            return;
        }
        
        if (!confirm('⚠️ FINAL WARNING: All tracking data, answers, and analytics will be permanently deleted. Continue?')) {
            return;
        }
        
        $btn.prop('disabled', true).text('Resetting...');
        $status.text('Processing...');
        
        $.ajax({
            type: 'POST',
            url: rts_admin.ajax_url,
            data: {
                action: 'rts_reset_survey',
                form_id: formId,
                confirm: 'yes',
                nonce: rts_admin.nonce
            },
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false).text('Reset All Statistics');
                
                if (response.success) {
                    var data = response.data;
                    $status.html('✅ ' + data.message + 
                        ' (Deleted: ' + data.deleted_tracking + ' tracking records, ' +
                        data.deleted_answers + ' answers, ' +
                        data.deleted_analytics + ' analytics records)'
                    ).css('color', 'green');
                } else {
                    $status.html('❌ ' + (response.data || 'Failed to reset statistics')).css('color', 'red');
                }
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false).text('Reset All Statistics');
                $status.html('❌ AJAX error: ' + error).css('color', 'red');
                console.error('Reset Error:', xhr);
            }
        });
    });
    
    // Reset specific question
    $('.rts-reset-question').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var formId = $btn.data('form-id');
        var questionId = $('#rts-question-select').val();
        var $status = $('#rts-reset-question-status');
        
        if (!questionId) {
            alert('Please select a question to reset.');
            return;
        }
        
        if (!confirm('⚠️ Are you sure you want to reset statistics for this question? This action cannot be undone!')) {
            return;
        }
        
        $btn.prop('disabled', true).text('Resetting...');
        $status.text('Processing...');
        
        $.ajax({
            type: 'POST',
            url: rts_admin.ajax_url,
            data: {
                action: 'rts_reset_question',
                form_id: formId,
                question_id: questionId,
                confirm: 'yes',
                nonce: rts_admin.nonce
            },
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false).text('Reset Question');
                
                if (response.success) {
                    var data = response.data;
                    var message = '✅ ' + data.message;
                    if (data.deleted_answers === 0 && data.deleted_analytics === 0) {
                        message += ' (No data found for this question)';
                    } else {
                        message += ' (Deleted: ' + data.deleted_answers + ' answers, ' + 
                                data.deleted_analytics + ' analytics records)';
                    }
                    $status.html(message).css('color', 'green');
                } else {
                    $status.html('❌ ' + (response.data || 'Failed to reset question')).css('color', 'red');
                }
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false).text('Reset Question');
                $status.html('❌ AJAX error: ' + error).css('color', 'red');
                console.error('Reset Error:', xhr);
            }
        });
    });
    
    // Add manual sync button to settings page
    $('#rts-manual-sync').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var formId = $('#rts-form-id').val();
        var $status = $('#rts-sync-status');
        
        $btn.prop('disabled', true).text('Syncing...');
        $status.text('Processing...');
        
        $.ajax({
            type: 'POST',
            url: rts_admin.ajax_url,
            data: {
                action: 'rts_sync_form_fields',
                form_id: formId,
                nonce: rts_admin.nonce
            },
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false).text('Sync Now');
                if (response.success) {
                    $status.html('✅ ' + response.data.message).css('color', 'green');
                } else {
                    $status.html('❌ ' + (response.data || 'Sync failed')).css('color', 'red');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Sync Now');
                $status.html('❌ AJAX error during sync').css('color', 'red');
            }
        });
    });

    if ($('#rts-races-list').length) {
        loadRaces();
    }
    
    
});

