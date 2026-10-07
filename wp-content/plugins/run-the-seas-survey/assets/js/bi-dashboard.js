jQuery(document).ready(function($) {
    console.log('BI Dashboard initialized');
    
    var currentFormId = 0;
    var trendChart = null;
    var geoChart = null;
    
    // Load dashboard data
    function loadDashboard(formId, dateFrom, dateTo, verification, referral, version) {
        if (!formId) {
            $('#rts-bi-no-form').show();
            $('#rts-bi-dashboard-content').hide();
            return;
        }
        
        // console.log('Loading BI Dashboard for form:', formId);
        
        $('#rts-bi-no-form').hide();
        $('#rts-bi-dashboard-content').show();
        $('#rts-bi-stats-grid').html('<div style="text-align: center; padding: 20px;"><span class="spinner is-active"></span> Loading analytics...</div>');
        
        var ajaxUrl = rts_bi.ajax_url || rts_admin.ajax_url || ajaxurl;
        var nonce = rts_bi.nonce || rts_admin.nonce || '';
        
        $.ajax({
            type: 'POST',
            url: ajaxUrl,
            data: {
                action: 'rts_get_analytics_data',
                form_id: formId,
                date_from: dateFrom || '',
                date_to: dateTo || '',
                verification: verification || '',
                referral: referral || '',
                version: version || '',
                nonce: nonce
            },
            dataType: 'json',
            timeout: 30000,
            success: function(response) {
                // console.log('BI Data response:', response);
                // console.log('Questions data:', response.data ? response.data.questions : 'No data');
                // console.log('Referrals data:', response.data ? response.data.referrals : 'No data');
                // console.log('Investor data:', response.data ? response.data.investor : 'No data');
                
                if (response.success) {
                    var data = response.data;
                    renderStats(data.stats);
                    renderTrendChart(data.trends);
                    renderGeoChart(data.geo);
                    renderQuestions(data.questions);
                    renderReferrals(data.referrals);
                    renderInvestorInsights(data.investor);
                    $('#rts-bi-stats-grid').find('.spinner').remove();
                } else {
                    showMessage('❌ ' + (response.data || 'Failed to load data'), 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                showMessage('❌ Error loading data. Please check console.', 'error');
                $('#rts-bi-stats-grid').html('<div style="text-align: center; padding: 20px; color: #dc3545;">Error loading data</div>');
            }
        });
    }
    
    // Render Stats
    function renderStats(stats) {
        if (!stats) {
            $('#rts-bi-stats-grid').html('<p style="color: #999; text-align: center;">No data available</p>');
            return;
        }
        
        var items = [
            { key: 'runners', label: 'Runners', icon: 'Runner', class: 'success' },
            { key: 'non_runners', label: 'Non-runners', icon: 'Group', class: 'info' },
            { key: 'certificates_issued', label: 'Certificates', icon: 'Certificate', class: 'success' },
            { key: 'captain_suites_active', label: 'Captain Suites', icon: 'Suite', class: 'success' },
            { key: 'invalid_emails', label: 'Invalid Emails', icon: 'Email', class: 'danger' },
            { key: 'hard_bounces', label: 'Hard Bounces', icon: 'Email', class: 'danger' },
            { key: 'soft_bounces', label: 'Soft Bounces', icon: 'Email', class: 'warning' },
            { key: 'total_responses', label: 'Total Responses', icon: '📝', class: '' },
            { key: 'completed', label: 'Completed', icon: '✅', class: 'success' },
            { key: 'incomplete', label: 'Incomplete', icon: '⏳', class: 'warning' },
            { key: 'abandoned', label: 'Abandoned', icon: '🚫', class: 'danger' },
            { key: 'completion_percentage', label: 'Completion Rate', icon: '📈', class: 'success' },
            { key: 'abandonment_rate', label: 'Abandonment Rate', icon: '⚠️', class: 'warning' },
            { key: 'avg_completion_time', label: 'Avg Time', icon: '⏱️', class: 'info' },
            { key: 'anonymous_participants', label: 'Anonymous', icon: '🕶️', class: 'info' },
            { key: 'registered_participants', label: 'Registered', icon: '👥', class: 'info' },
            { key: 'verified_emails', label: 'Verified', icon: '📧', class: 'success' },
            { key: 'unverified_emails', label: 'Unverified', icon: '⚠️', class: 'warning' },
            { key: 'duplicate_responses', label: 'Duplicates', icon: '🔄', class: 'danger' },
            { key: 'referral_participation', label: 'Referrals', icon: '🏃', class: 'info' },
            { key: 'cabin_credits_issued', label: 'Cabin Credits', icon: '🏅', class: 'success' },
            { key: 'captain_race_participation', label: 'Race Participants', icon: '🏁', class: 'info' },
            { key: 'captain_miles_balance', label: 'Kilometres', icon: '⭐', class: 'info' },
            { key: 'unique_respondents', label: 'Unique Users', icon: '👤', class: 'info' }
        ];
        
        var html = '';
        items.forEach(function(item) {
            var value = stats[item.key] || 0;
            if (item.key === 'completion_percentage' || item.key === 'abandonment_rate') {
                value = value + '%';
            }
            if (item.key === 'avg_completion_time') {
                value = formatTime(value);
            }
            html += '<div class="rts-bi-stat ' + item.class + '">' +
                '<div class="stat-number">' + value + '</div>' +
                '<div class="stat-label">' + item.label + '</div>' +
                '</div>';
        });
        
        $('#rts-bi-stats-grid').html(html);
    }
    
    // Render Trend Chart
    function renderTrendChart(trends) {
        if (trendChart) {
            trendChart.destroy();
        }
        
        if (typeof Chart === 'undefined') {
            console.error('Chart.js not loaded');
            return;
        }
        
        var ctx = document.getElementById('rts-bi-trend-chart').getContext('2d');
        trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: trends.labels || ['No Data'],
                datasets: [{
                    label: 'Started',
                    data: trends.started || [0],
                    borderColor: '#1a7efb',
                    backgroundColor: 'rgba(26, 126, 251, 0.1)',
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Completed',
                    data: trends.completed || [0],
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { 
                        position: 'top',
                        labels: { font: { size: 11 } }
                    }
                },
                scales: {
                    y: { 
                        beginAtZero: true,
                        ticks: { font: { size: 10 } }
                    },
                    x: {
                        ticks: { font: { size: 9 } }
                    }
                }
            }
        });
    }
    
    // Render Geo Chart
    function renderGeoChart(geo) {
        if (geoChart) {
            geoChart.destroy();
        }
        
        if (typeof Chart === 'undefined') {
            console.error('Chart.js not loaded');
            return;
        }
        
        var colors = ['#1a7efb', '#28a745', '#ffc107', '#dc3545', '#6c757d', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997', '#e83e8c'];
        var labels = geo.labels || ['No Data'];
        var counts = geo.counts || [1];
        
        var ctx = document.getElementById('rts-bi-geo-chart').getContext('2d');
        geoChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: counts,
                    backgroundColor: colors.slice(0, labels.length),
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 9 } }
                    }
                }
            }
        });
        
        // Geo list
        var html = '';
        if (geo.labels && geo.labels.length > 0) {
            geo.labels.forEach(function(label, index) {
                var count = geo.counts[index] || 0;
                var completed = geo.completions[index] || 0;
                var rate = count > 0 ? ((completed / count) * 100).toFixed(0) : 0;
                html += '<div style="display: flex; justify-content: space-between; padding: 2px 0; border-bottom: 1px solid #f0f0f0; font-size: 11px;">' +
                    '<span>' + label + '</span>' +
                    '<span>' + count + ' (' + rate + '%)</span>' +
                    '</div>';
            });
        } else {
            html = '<p style="color: #999; font-size: 12px;">No data</p>';
        }
        
        $('#rts-bi-geo-list').html(html);
    }
    
    // Render Questions
    function renderQuestions(questions) {
        // console.log('Rendering questions:', questions);
        
        if (!questions || questions.length === 0) {
            $('#rts-bi-questions-container').html('<div style="padding: 20px; text-align: center; color: #999;">No question data available. Complete a survey to see analytics.</div>');
            return;
        }
        
        var html = '';
        questions.forEach(function(q) {
            html += '<div class="rts-question-block">' +
                '<div class="question-title">' + (q.question_label || q.question_id) + 
                ' <span style="font-size: 11px; color: #999;">(' + (q.total_votes || 0) + ' responses; ' + (q.skipped_questions || 0) + ' skipped)</span></div>';
            
            if (q.answers && q.answers.length > 0) {
                q.answers.forEach(function(answer) {
                    // FIX: Ensure percent is a number
                    var percent = parseFloat(answer.percentage) || 0;
                    var totalVotes = parseInt(answer.total_votes) || 0;
                    
                    html += '<div class="rts-answer-row">' +
                        '<span style="min-width: 70px; font-size: 11px;">' + (answer.answer_option || 'Other') + '</span>' +
                        '<div class="bar">' +
                        '<div class="bar-fill" style="width: ' + percent + '%;"></div>' +
                        '</div>' +
                        '<span class="percentage">' + percent.toFixed(0) + '%</span>' +
                        '<span style="font-size: 10px; color: #999;">(' + totalVotes + ')</span>' +
                        '</div>';
                });
            } else {
                html += '<p style="color: #999; font-size: 12px; margin: 5px 0;">No responses for this question</p>';
            }
            
            html += '</div>';
        });
        
        $('#rts-bi-questions-container').html(html);
    }
    
    // Render Referrals
    function renderReferrals(referrals) {
        // console.log('Rendering referrals:', referrals);
        
        if (!referrals || !referrals.sources || referrals.sources.length === 0) {
            $('#rts-bi-referral-container').html('<div style="padding: 20px; text-align: center; color: #999;">No referral data available. Share referral links to see analytics.</div>');
            return;
        }
        
        var totalVisits = parseInt(referrals.total_visits) || 0;
        var totalCompleted = parseInt(referrals.total_completed) || 0;
        var conversionRate = parseFloat(referrals.conversion_rate) || 0;
        
        var html = '<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 15px;">' +
            '<div style="text-align: center; padding: 10px; background: #f8f9fa; border-radius: 6px;">' +
            '<div style="font-size: 20px; font-weight: bold; color: #1a7efb;">' + totalVisits + '</div>' +
            '<div style="font-size: 11px; color: #666;">Total Visits</div>' +
            '</div>' +
            '<div style="text-align: center; padding: 10px; background: #f8f9fa; border-radius: 6px;">' +
            '<div style="font-size: 20px; font-weight: bold; color: #28a745;">' + totalCompleted + '</div>' +
            '<div style="font-size: 11px; color: #666;">Completed</div>' +
            '</div>' +
            '<div style="text-align: center; padding: 10px; background: #f8f9fa; border-radius: 6px;">' +
            '<div style="font-size: 20px; font-weight: bold; color: ' + (conversionRate > 50 ? '#28a745' : '#ffc107') + ';">' +
            conversionRate + '%</div>' +
            '<div style="font-size: 11px; color: #666;">Conversion Rate</div>' +
            '</div>' +
            '</div>';
        
        html += '<table class="wp-list-table widefat fixed striped" style="font-size: 12px;">' +
            '<thead><tr><th>Source</th><th>Visits</th><th>Completed</th><th>Conversion</th></tr></thead><tbody>';
        
        referrals.sources.forEach(function(r) {
            var visits = parseInt(r.visits) || 0;
            var completed = parseInt(r.completed) || 0;
            var rate = visits > 0 ? ((completed / visits) * 100).toFixed(0) : 0;
            html += '<tr>' +
                '<td>' + (r.referral_source || 'Direct') + '</td>' +
                '<td>' + visits + '</td>' +
                '<td>' + completed + '</td>' +
                '<td>' + rate + '%</td>' +
                '</tr>';
        });
        
        html += '</tbody></table>';
        $('#rts-bi-referral-container').html(html);
    }    
    
    // Render Investor Insights
    function renderInvestorInsights(investor) {
        // console.log('Rendering investor insights:', investor);
        
        if (!investor) {
            $('#rts-bi-investor-insights').html('<div style="padding: 20px; text-align: center; color: rgba(255,255,255,0.7);">No investor data available</div>');
            return;
        }
        
        // Debug: Log the referral_impact value
        if (investor.referral_impact) {
            console.log('Referral Impact Value:', investor.referral_impact.value);
            console.log('Referral Impact Label:', investor.referral_impact.label);
        }
        
        var html = '';
        var keys = ['total_demand', 'completion_rate', 'pricing_acceptance', 'referral_impact', 'market_reach', 'engagement_score'];
        var icons = {
            'total_demand': '📊',
            'completion_rate': '✅',
            'pricing_acceptance': '💰',
            'referral_impact': '🔗',
            'market_reach': '🌍',
            'engagement_score': '🎯'
        };
        
        var hasData = false;
        keys.forEach(function(key) {
            if (investor[key]) {
                hasData = true;
                var item = investor[key];
                var value = item.value !== undefined && item.value !== null ? item.value : 'N/A';
                
                // Format the value for display
                if (typeof value === 'number') {
                    value = value.toString();
                }
                
                html += '<div class="rts-bi-insight-card">' +
                    '<div style="font-size: 20px;">' + (icons[key] || '📊') + '</div>' +
                    '<div class="insight-value">' + value + '</div>' +
                    '<div class="insight-label">' + (item.label || '') + '</div>' +
                    '<div style="font-size: 10px; color: rgba(255,255,255,0.4); margin-top: 3px;">' + (item.trend || '') + '</div>' +
                    '<div style="font-size: 9px; color: rgba(255,255,255,0.3); margin-top: 2px;">' + (item.description || '') + '</div>' +
                    '</div>';
            }
        });
        
        if (!hasData) {
            html = '<div style="padding: 20px; text-align: center; color: rgba(255,255,255,0.7);">Complete surveys to generate investor insights</div>';
        }
        
        $('#rts-bi-investor-insights').html(html);
    }
    
    // Format time
    function formatTime(seconds) {
        if (!seconds) return '0s';
        if (seconds < 60) return Math.round(seconds) + 's';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ' + Math.round(seconds % 60) + 's';
        return Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm';
    }
    
    // Show message
    function showMessage(message, type) {
        var $msg = $('#rts-bi-message');
        var colors = { success: '#28a745', error: '#dc3545', info: '#17a2b8' };
        $msg.css({
            'display': 'block',
            'padding': '10px 15px',
            'border-radius': '4px',
            'background': type === 'success' ? '#d4edda' : type === 'error' ? '#f8d7da' : '#d1ecf1',
            'color': colors[type] || '#333',
            'border': '1px solid ' + (colors[type] || '#ddd')
        }).html(message);
        setTimeout(function() { $msg.fadeOut(); }, 5000);
    }
    
    // Event handlers
    $('#rts-bi-form-select').on('change', function() {
        currentFormId = $(this).val();
        if (currentFormId) {
            window.history.pushState({}, '', '?page=rts-bi-dashboard&form_id=' + currentFormId);
            loadDashboard(currentFormId);
        } else {
            $('#rts-bi-no-form').show();
            $('#rts-bi-dashboard-content').hide();
        }
    });
    
    $('#rts-bi-apply-filters').on('click', function() {
        if (currentFormId) {
            loadDashboard(
                currentFormId,
                $('#rts-bi-date-from').val(),
                $('#rts-bi-date-to').val(),
                $('#rts-bi-verification-filter').val(),
                $('#rts-bi-referral-filter').val(),
                $('#rts-bi-version-filter').val()
            );
        }
    });
    
    $('#rts-bi-reset-filters').on('click', function() {
        $('#rts-bi-date-from').val('');
        $('#rts-bi-date-to').val('');
        $('#rts-bi-verification-filter').val('');
        $('#rts-bi-referral-filter').val('');
        $('#rts-bi-version-filter').val('');
        if (currentFormId) {
            loadDashboard(currentFormId);
        }
    });
    
    $('#rts-bi-refresh').on('click', function() {
        if (currentFormId) {
            loadDashboard(currentFormId);
            showMessage('🔄 Data refreshed!', 'info');
        }
    });
    
    $('#rts-bi-export-report').on('click', function() {
        if (!currentFormId) {
            alert('Please select a survey first.');
            return;
        }
        window.location.href = (rts_bi.ajax_url || rts_admin.ajax_url || ajaxurl) + 
            '?action=rts_export_analytics&form_id=' + currentFormId + 
            '&date_from=' + encodeURIComponent($('#rts-bi-date-from').val() || '') +
            '&date_to=' + encodeURIComponent($('#rts-bi-date-to').val() || '') +
            '&verification=' + encodeURIComponent($('#rts-bi-verification-filter').val() || '') +
            '&referral=' + encodeURIComponent($('#rts-bi-referral-filter').val() || '') +
            '&nonce=' + (rts_bi.nonce || rts_admin.nonce || '');
    });

    $('#rts-bi-export-excel').on('click', function() {
        if (!currentFormId) {
            alert('Please select a survey first.');
            return;
        }
        window.location.href = (rts_bi.ajax_url || rts_admin.ajax_url || ajaxurl) +
            '?action=rts_export_analytics&format=xls&form_id=' + currentFormId +
            '&date_from=' + encodeURIComponent($('#rts-bi-date-from').val() || '') +
            '&date_to=' + encodeURIComponent($('#rts-bi-date-to').val() || '') +
            '&verification=' + encodeURIComponent($('#rts-bi-verification-filter').val() || '') +
            '&referral=' + encodeURIComponent($('#rts-bi-referral-filter').val() || '') +
            '&nonce=' + (rts_bi.nonce || rts_admin.nonce || '');
    });

    $('#rts-bi-question-search').on('input', function() {
        var keyword = ($(this).val() || '').toLowerCase().trim();
        $('#rts-bi-questions-container .rts-question-block').each(function() {
            var matches = !keyword || $(this).text().toLowerCase().indexOf(keyword) !== -1;
            $(this).toggle(matches);
        });
    });

    $('.rts-bi-range').on('click', function() {
        var days = parseInt($(this).data('days'), 10);
        var today = new Date();
        var from = new Date(today);
        from.setDate(today.getDate() - Math.max(days - 1, 0));
        var formatDate = function(value) { return value.toISOString().slice(0, 10); };
        $('#rts-bi-date-from').val(formatDate(from));
        $('#rts-bi-date-to').val(formatDate(today));
        if (currentFormId) {
            $('#rts-bi-apply-filters').trigger('click');
        }
    });
    
    $('#rts-bi-archive-data').on('click', function() {
        if (!currentFormId) {
            alert('Please select a survey first.');
            return;
        }
        if (!confirm('Archive data older than 90 days for this survey?')) return;
        
        $.ajax({
            type: 'POST',
            url: rts_bi.ajax_url || rts_admin.ajax_url || ajaxurl,
            data: {
                action: 'rts_archive_analytics',
                form_id: currentFormId,
                nonce: rts_bi.nonce || rts_admin.nonce || ''
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showMessage('✅ ' + response.data, 'success');
                } else {
                    showMessage('❌ ' + (response.data || 'Failed to archive'), 'error');
                }
            },
            error: function() {
                showMessage('❌ Error archiving data', 'error');
            }
        });
    });
    
    $('#rts-bi-reset-data').on('click', function() {
        if (!currentFormId) {
            alert('Please select a survey first.');
            return;
        }
        if (!confirm('⚠️ Are you sure you want to reset ALL statistics for this survey? This action cannot be undone!')) return;
        if (!confirm('⚠️ FINAL WARNING: All data will be permanently deleted!')) return;
        
        $.ajax({
            type: 'POST',
            url: rts_bi.ajax_url || rts_admin.ajax_url || ajaxurl,
            data: {
                action: 'rts_reset_analytics',
                form_id: currentFormId,
                nonce: rts_bi.nonce || rts_admin.nonce || ''
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showMessage('✅ ' + response.data.message, 'success');
                    loadDashboard(currentFormId);
                } else {
                    showMessage('❌ ' + (response.data || 'Failed to reset'), 'error');
                }
            },
            error: function() {
                showMessage('❌ Error resetting data', 'error');
            }
        });
    });
    
    // Load initial data if form selected
    currentFormId = $('#rts-bi-form-select').val();
    if (currentFormId) {
        loadDashboard(currentFormId);
    }
});
