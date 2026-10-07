jQuery(document).ready(function($) {
    // console.log('RTS Analytics initialized');
    // console.log('rts_admin:', typeof rts_admin !== 'undefined' ? rts_admin : 'NOT DEFINED');
    // console.log('rts_analytics:', typeof rts_analytics !== 'undefined' ? rts_analytics : 'NOT DEFINED');
    
    var currentFormId = 0;
    var completionChart = null;
    var geoChart = null;
    
    // Check if rts_admin is defined
    if (typeof rts_admin === 'undefined') {
        console.error('RTS Analytics: rts_admin is not defined');
        // Try to define it
        window.rts_admin = {
            ajax_url: ajaxurl || '/wp-admin/admin-ajax.php',
            nonce: rts_analytics ? rts_analytics.nonce : ''
        };
        // console.log('RTS Analytics: rts_admin defined manually');
    }
    
    // Check if rts_analytics is defined
    if (typeof rts_analytics === 'undefined') {
        console.error('RTS Analytics: rts_analytics is not defined');
        window.rts_analytics = {
            ajax_url: ajaxurl || '/wp-admin/admin-ajax.php',
            nonce: '',
            plugin_url: ''
        };
    }
    
    // Load analytics when form is selected
    function loadAnalytics(formId, dateFrom, dateTo) {
        if (!formId) {
            $('#rts-no-form-selected').show();
            $('#rts-analytics-dashboard').hide();
            return;
        }
        
        // console.log('Loading analytics for form:', formId);
        
        $('#rts-no-form-selected').hide();
        $('#rts-analytics-dashboard').show();
        $('#rts-stats-grid').html('<div style="text-align: center; padding: 40px;"><span class="spinner is-active"></span> Loading analytics...</div>');
        
        var ajaxUrl = rts_admin.ajax_url || rts_analytics.ajax_url || ajaxurl;
        var nonce = rts_admin.nonce || rts_analytics.nonce || '';
        
        // console.log('AJAX URL:', ajaxUrl);
        // console.log('Nonce:', nonce);
        
        $.ajax({
            type: 'POST',
            url: ajaxUrl,
            data: {
                action: 'rts_get_analytics_data',
                form_id: formId,
                date_from: dateFrom || '',
                date_to: dateTo || '',
                nonce: nonce
            },
            dataType: 'json',
            success: function(response) {
                // console.log('Analytics Response:', response);
                
                if (response.success) {
                    var data = response.data;
                    renderStats(data.stats);
                    renderCharts(data.charts);
                    renderQuestions(data.questions);
                    renderReferrals(data.referrals);
                } else {
                    $('#rts-stats-grid').html('<div style="text-align: center; padding: 40px; color: #dc3545;">❌ ' + (response.data || 'Failed to load analytics') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                // console.error('AJAX Error:', error);
                // console.log('Response:', xhr.responseText);
                $('#rts-stats-grid').html('<div style="text-align: center; padding: 40px; color: #dc3545;">❌ Error loading analytics. Please check console for details.</div>');
            }
        });
    }
    
    // Render stats
    function renderStats(stats) {
        var html = '';
        var statItems = [
            { key: 'total_responses', label: 'Total Responses', icon: '📝', class: '' },
            { key: 'completed', label: 'Completed Surveys', icon: '✅', class: 'success' },
            { key: 'incomplete', label: 'Incomplete', icon: '⏳', class: 'warning' },
            { key: 'completion_rate', label: 'Completion Rate', icon: '📈', class: 'success' },
            { key: 'avg_time', label: 'Avg. Time Spent', icon: '⏱️', class: 'info' },
            { key: 'verified_emails', label: 'Verified Emails', icon: '📧', class: 'success' },
            { key: 'unverified_emails', label: 'Unverified Emails', icon: '⚠️', class: 'warning' },
            { key: 'duplicates', label: 'Duplicate Responses', icon: '🔄', class: 'danger' },
            { key: 'referral_participation', label: 'Referral Race Participants', icon: '🏃', class: 'info' },
            { key: 'cabin_credits_issued', label: 'Cabin Credits Issued', icon: '🏅', class: 'success' }
        ];
        
        statItems.forEach(function(item) {
            var value = stats[item.key] || 0;
            html += '<div class="rts-stat-card ' + item.class + '">' +
                '<div style="font-size: 24px;">' + item.icon + '</div>' +
                '<div class="stat-number">' + (typeof value === 'number' && item.key === 'avg_time' ? formatTime(value) : value) + '</div>' +
                '<div class="stat-label">' + item.label + '</div>' +
                '</div>';
        });
        
        $('#rts-stats-grid').html(html);
    }
    
    // Format time
    function formatTime(seconds) {
        if (!seconds) return '0s';
        if (seconds < 60) return Math.round(seconds) + 's';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ' + Math.round(seconds % 60) + 's';
        return Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm';
    }
    
    // Render charts
    function renderCharts(chartData) {
        if (typeof Chart === 'undefined') {
            console.error('Chart.js not loaded');
            return;
        }
        
        // Completion chart
        if (completionChart) {
            completionChart.destroy();
        }
        
        var ctx = document.getElementById('rts-completion-chart').getContext('2d');
        completionChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.trend_labels || ['No Data'],
                datasets: [{
                    label: 'Completed',
                    data: chartData.trend_completed || [0],
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Started',
                    data: chartData.trend_started || [0],
                    borderColor: '#1a7efb',
                    backgroundColor: 'rgba(26, 126, 251, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        
        // Geo chart
        if (geoChart) {
            geoChart.destroy();
        }
        
        var geoCtx = document.getElementById('rts-geo-chart').getContext('2d');
        var geoLabels = chartData.geo_labels || ['No Data'];
        var geoData = chartData.geo_data || [1];
        var geoColors = ['#1a7efb', '#28a745', '#ffc107', '#dc3545', '#6c757d', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997', '#e83e8c'];
        
        geoChart = new Chart(geoCtx, {
            type: 'doughnut',
            data: {
                labels: geoLabels,
                datasets: [{
                    data: geoData,
                    backgroundColor: geoColors.slice(0, geoLabels.length),
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'right',
                    }
                }
            }
        });
    }
    
    // Render question analytics
    function renderQuestions(questions) {
        if (!questions || questions.length === 0) {
            $('#rts-questions-container').html('<p style="color: #666; text-align: center; padding: 20px;">No question data available</p>');
            return;
        }
        
        var html = '';
        questions.forEach(function(q) {
            html += '<div class="rts-question-block">' +
                '<div class="question-title">' + (q.question_label || q.question_id) + '</div>';
            
            if (q.answers && q.answers.length > 0) {
                q.answers.forEach(function(answer) {
                    var percent = answer.percentage || 0;
                    html += '<div class="rts-answer-row">' +
                        '<span style="min-width: 100px; font-size: 13px;">' + (answer.answer_option || 'Other') + '</span>' +
                        '<div class="bar">' +
                        '<div class="bar-fill" style="width: ' + percent + '%;"></div>' +
                        '</div>' +
                        '<span class="percentage">' + percent.toFixed(1) + '%</span>' +
                        '<span style="font-size: 12px; color: #666;">(' + answer.total_votes + ' votes)</span>' +
                        '</div>';
                });
            } else {
                html += '<p style="color: #999; font-size: 13px;">No responses for this question</p>';
            }
            
            html += '</div>';
        });
        
        $('#rts-questions-container').html(html);
    }
    
    // Render referral analytics
    function renderReferrals(referrals) {
        if (!referrals || referrals.length === 0) {
            $('#rts-referral-analytics').html('<p style="color: #666; text-align: center; padding: 20px;">No referral data available</p>');
            return;
        }
        
        var html = '<table class="wp-list-table widefat fixed striped">' +
            '<thead><tr><th>Source</th><th>Visits</th><th>Completed</th><th>Conversion Rate</th></tr></thead><tbody>';
        
        referrals.forEach(function(r) {
            var rate = r.visits > 0 ? ((r.completed / r.visits) * 100).toFixed(1) : 0;
            html += '<tr>' +
                '<td>' + (r.referral_source || 'Direct') + '</td>' +
                '<td>' + r.visits + '</td>' +
                '<td>' + r.completed + '</td>' +
                '<td>' + rate + '%</td>' +
                '</tr>';
        });
        
        html += '</tbody></table>';
        $('#rts-referral-analytics').html(html);
    }
    
    // Form selection change
    $('#rts-analytics-form-select').on('change', function() {
        currentFormId = $(this).val();
        // console.log('Form selected:', currentFormId);
        if (currentFormId) {
            window.history.pushState({}, '', '?page=rts-analytics&form_id=' + currentFormId);
            loadAnalytics(currentFormId, $('#rts-analytics-date-from').val(), $('#rts-analytics-date-to').val());
        } else {
            $('#rts-no-form-selected').show();
            $('#rts-analytics-dashboard').hide();
        }
    });
    
    // Apply filters
    $('#rts-analytics-apply-filters').on('click', function() {
        if (currentFormId) {
            loadAnalytics(currentFormId, $('#rts-analytics-date-from').val(), $('#rts-analytics-date-to').val());
        }
    });
    
    // Reset filters
    $('#rts-analytics-reset-filters').on('click', function() {
        $('#rts-analytics-date-from').val('');
        $('#rts-analytics-date-to').val('');
        if (currentFormId) {
            loadAnalytics(currentFormId);
        }
    });
    
    // Export CSV
    $('#rts-analytics-export-csv').on('click', function() {
        if (!currentFormId) {
            alert('Please select a survey first.');
            return;
        }
        var url = rts_admin.ajax_url + '?action=rts_export_analytics&form_id=' + currentFormId + '&format=csv&nonce=' + rts_admin.nonce;
        window.location.href = url;
    });
    
    // Export PDF
    $('#rts-analytics-export-pdf').on('click', function() {
        if (!currentFormId) {
            alert('Please select a survey first.');
            return;
        }
        var url = rts_admin.ajax_url + '?action=rts_export_analytics&form_id=' + currentFormId + '&format=pdf&nonce=' + rts_admin.nonce;
        window.location.href = url;
    });
    
    // Archive survey
    $('#rts-archive-survey').on('click', function() {
        if (!currentFormId) {
            alert('Please select a survey first.');
            return;
        }
        if (!confirm('Archive historical results for this survey? This will move data to archive but keep it accessible.')) {
            return;
        }
        
        $.ajax({
            type: 'POST',
            url: rts_admin.ajax_url,
            data: {
                action: 'rts_archive_analytics',
                form_id: currentFormId,
                nonce: rts_admin.nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showAdminMessage('✅ ' + response.data, 'success');
                    loadAnalytics(currentFormId);
                } else {
                    showAdminMessage('❌ ' + (response.data || 'Failed to archive'), 'error');
                }
            },
            error: function() {
                showAdminMessage('❌ Error archiving data', 'error');
            }
        });
    });
    
    // Reset survey analytics
    $('#rts-reset-survey-analytics').on('click', function() {
        if (!currentFormId) {
            alert('Please select a survey first.');
            return;
        }
        if (!confirm('⚠️ Are you sure you want to reset ALL statistics for this survey? This action cannot be undone!')) {
            return;
        }
        if (!confirm('⚠️ FINAL WARNING: All tracking data, answers, and analytics will be permanently deleted. Continue?')) {
            return;
        }
        
        $.ajax({
            type: 'POST',
            url: rts_admin.ajax_url,
            data: {
                action: 'rts_reset_analytics',
                form_id: currentFormId,
                nonce: rts_admin.nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showAdminMessage('✅ ' + response.data.message, 'success');
                    loadAnalytics(currentFormId);
                } else {
                    showAdminMessage('❌ ' + (response.data || 'Failed to reset'), 'error');
                }
            },
            error: function() {
                showAdminMessage('❌ Error resetting data', 'error');
            }
        });
    });
    
    // Refresh
    $('#rts-refresh-analytics').on('click', function() {
        if (currentFormId) {
            loadAnalytics(currentFormId, $('#rts-analytics-date-from').val(), $('#rts-analytics-date-to').val());
            showAdminMessage('🔄 Data refreshed!', 'info');
        }
    });
    
    function showAdminMessage(message, type) {
        var $msg = $('#rts-admin-message');
        var colors = {
            success: '#28a745',
            error: '#dc3545',
            info: '#17a2b8'
        };
        $msg.css({
            'display': 'block',
            'padding': '12px 15px',
            'border-radius': '4px',
            'background': type === 'success' ? '#d4edda' : type === 'error' ? '#f8d7da' : '#d1ecf1',
            'color': colors[type] || '#333',
            'border': '1px solid ' + (colors[type] || '#ddd')
        }).html(message);
        
        setTimeout(function() {
            $msg.fadeOut();
        }, 5000);
    }
    
    // Load initial data if form selected
    currentFormId = $('#rts-analytics-form-select').val();
    // console.log('Initial form ID:', currentFormId);
    if (currentFormId) {
        loadAnalytics(currentFormId);
    } else {
        $('#rts-stats-grid').html('');
    }
});