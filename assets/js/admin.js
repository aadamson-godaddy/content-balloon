/**
 * Content Balloon Admin JavaScript
 */

jQuery(document).ready(function($) {
    
    // Configuration form handling
    $('#content-balloon-config').on('submit', function(e) {
        e.preventDefault();
        
        var formData = {
            action: 'content_balloon_save_config',
            nonce: contentBalloonAjax.nonce,
            file_count: $('#file_count').val(),
            min_file_size: $('#min_file_size').val(),
            max_file_size: $('#max_file_size').val(),
            auto_cleanup_days: $('#auto_cleanup_days').val(),
            cleanup_enabled: $('#cleanup_enabled').is(':checked') ? 1 : 0,
            cleanup_frequency: $('#cleanup_frequency').val()
        };
        
        $.post(contentBalloonAjax.ajaxurl, formData, function(response) {
            if (response.success) {
                showNotice('Configuration saved successfully!', 'success');
            } else {
                showNotice('Failed to save configuration: ' + (response.data || 'Unknown error'), 'error');
            }
        });
    });
    
    // Regenerate webhook secret
    $('#regenerate-secret').on('click', function() {
        if (confirm('Are you sure you want to regenerate the webhook secret? This will invalidate any existing webhook calls.')) {
            var formData = {
                action: 'content_balloon_regenerate_secret',
                nonce: contentBalloonAjax.nonce
            };
            
            $.post(contentBalloonAjax.ajaxurl, formData, function(response) {
                if (response.success) {
                    $('#webhook_secret').val(response.data.secret);
                    showNotice('Webhook secret regenerated successfully!', 'success');
                } else {
                    showNotice('Failed to regenerate webhook secret.', 'error');
                }
            });
        }
    });
    
    // Generate files
    $('#generate-files').on('click', function() {
        var button = $(this);
        var originalText = button.text();
        
        button.prop('disabled', true).text('Generating...');
        
        var formData = {
            action: 'content_balloon_generate',
            nonce: contentBalloonAjax.nonce,
            file_count: $('#file_count').val(),
            max_file_size: $('#max_file_size').val(),
            min_file_size: $('#min_file_size').val()
        };
        
        $.post(contentBalloonAjax.ajaxurl, formData, function(response) {
            if (response.success) {
                showNotice(response.data.message, 'success');
                startProgressMonitoring();
            } else {
                showNotice('Failed to start file generation.', 'error');
                button.prop('disabled', false).text(originalText);
            }
        });
    });
    
    // Check status
    $('#check-status').on('click', function() {
        updateStatus();
    });
    
    // Manual cleanup
    $('#manual-cleanup').on('click', function() {
        if (confirm('Are you sure you want to delete ALL generated files? This action cannot be undone.')) {
            var button = $(this);
            var originalText = button.text();
            
            button.prop('disabled', true).text('Cleaning...');
            
            var formData = {
                action: 'content_balloon_cleanup',
                nonce: contentBalloonAjax.nonce
            };
            
            $.post(contentBalloonAjax.ajaxurl, formData, function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    updateStatus();
                } else {
                    showNotice('Cleanup failed: ' + response.data.message, 'error');
                }
                button.prop('disabled', false).text(originalText);
            });
        }
    });
    
    // Test cleanup
    $('#test-cleanup').on('click', function() {
        var button = $(this);
        var originalText = button.text();
        
        button.prop('disabled', true).text('Testing...');
        
        var formData = {
            action: 'content_balloon_test_cleanup',
            nonce: contentBalloonAjax.nonce
        };
        
        $.post(contentBalloonAjax.ajaxurl, formData, function(response) {
            if (response.success) {
                showNotice(response.data.message, 'info');
            } else {
                showNotice('Test cleanup failed: ' + response.data.message, 'error');
            }
            button.prop('disabled', false).text(originalText);
        });
    });
    
    // Progress monitoring
    function startProgressMonitoring() {
        $('#generation-progress').show();
        $('#generate-files').prop('disabled', true);
        
        var progressInterval = setInterval(function() {
            updateProgress();
            
            // Check if generation is complete
            $.post(contentBalloonAjax.ajaxurl, {
                action: 'content_balloon_progress',
                nonce: contentBalloonAjax.nonce
            }, function(response) {
                if (response.success) {
                    if (response.data.status === 'Completed' || response.data.status === 'Idle') {
                        clearInterval(progressInterval);
                        $('#generation-progress').hide();
                        $('#generate-files').prop('disabled', false).text('Generate Files');
                        updateStatus();
                        showNotice('File generation completed!', 'success');
                    }
                }
            });
        }, 2000); // Update every 2 seconds
    }
    
    function updateProgress() {
        $.post(contentBalloonAjax.ajaxurl, {
            action: 'content_balloon_progress',
            nonce: contentBalloonAjax.nonce
        }, function(response) {
            if (response.success && response.data.current_job) {
                var job = response.data.current_job;
                var percent = job.progress_percent || 0;
                
                $('.progress-fill').css('width', percent + '%');
                $('#progress-text').text('Generated ' + job.files_completed + ' of ' + job.total_files + ' files (' + percent + '%)');
            }
        });
    }
    
    function updateStatus() {
        $.post(contentBalloonAjax.ajaxurl, {
            action: 'content_balloon_progress',
            nonce: contentBalloonAjax.nonce
        }, function(response) {
            if (response.success) {
                var data = response.data;
                $('#total-files').text(data.total_files);
                $('#total-size').text(formatBytes(data.total_size));
                $('#last-generation').text(data.last_generation || 'Never');
                $('#current-status').text(data.status);
            }
        });
    }
    
    // Utility functions
    function showNotice(message, type) {
        var noticeClass = 'notice-' + type;
        var notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
        
        $('.wrap h1').after(notice);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            notice.fadeOut();
        }, 5000);
    }
    
    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        
        var k = 1024;
        var sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    // Auto-update status every 30 seconds
    setInterval(updateStatus, 30000);
    
    // Initial status update
    updateStatus();
});
