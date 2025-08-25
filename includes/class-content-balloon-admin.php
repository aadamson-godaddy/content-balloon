<?php
/**
 * Content Balloon Admin Interface
 *
 * @package Content_Balloon
 */

class Content_Balloon_Admin {
    
    /**
     * Initialize admin interface
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_content_balloon_progress', array($this, 'ajax_progress'));
        add_action('wp_ajax_content_balloon_save_config', array($this, 'ajax_save_config'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_management_page(
            'Content Balloon',
            'Content Balloon',
            'manage_options',
            'content-balloon',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'tools_page_content-balloon') {
            return;
        }
        
        wp_enqueue_script(
            'content-balloon-admin',
            CONTENT_BALLOON_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            CONTENT_BALLOON_VERSION,
            true
        );
        
        wp_enqueue_style(
            'content-balloon-admin',
            CONTENT_BALLOON_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CONTENT_BALLOON_VERSION
        );
        
        // Localize script
        wp_localize_script('content-balloon-admin', 'contentBalloonAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('content_balloon_nonce'),
            'strings' => array(
                'generating' => 'Generating files...',
                'cleaning' => 'Cleaning up...',
                'success' => 'Operation completed successfully!',
                'error' => 'An error occurred. Please try again.'
            )
        ));
    }
    
    /**
     * Admin page content
     */
    public function admin_page() {
        $options = get_option('content_balloon_options', array());
        $generator = new Content_Balloon_Generator();
        $status = $generator->get_status();
        ?>
        <div class="wrap">
            <h1>Content Balloon</h1>
            <p>Generate large amounts of test data by downloading novels from Project Gutenberg and splitting them into smaller files.</p>
            
            <div class="content-balloon-container">
                <!-- Configuration Section -->
                <div class="content-balloon-section">
                    <h2>Configuration</h2>
                    <form id="content-balloon-config">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="file_count">Number of Files to Generate</label>
                                </th>
                                <td>
                                    <input type="number" id="file_count" name="file_count" 
                                           value="<?php echo esc_attr($options['max_files_per_run'] ?? 100); ?>" 
                                           min="1" max="10000" class="regular-text" />
                                    <p class="description">Maximum 10,000 files per run</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="min_file_size">Minimum File Size (MB)</label>
                                </th>
                                <td>
                                    <input type="number" id="min_file_size" name="min_file_size" 
                                           value="<?php echo esc_attr($options['min_file_size_mb'] ?? 1); ?>" 
                                           min="1" max="1000" class="regular-text" />
                                    <p class="description">Maximum 1 GB (1,000 MB) per file</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="max_file_size">Maximum File Size (MB)</label>
                                </th>
                                <td>
                                    <input type="number" id="max_file_size" name="max_file_size" 
                                           value="<?php echo esc_attr($options['max_file_size_mb'] ?? 10240); ?>" 
                                           min="1" max="10240" class="regular-text" />
                                    <p class="description">Maximum 10 GB (10,240 MB) per file</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="cleanup_enabled">Enable Auto Cleanup</label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="cleanup_enabled" name="cleanup_enabled" 
                                               value="1" <?php checked(($options['cleanup_enabled'] ?? true), true); ?> />
                                        Enable automatic cleanup of generated files
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="cleanup_frequency">Cleanup Frequency</label>
                                </th>
                                <td>
                                    <select id="cleanup_frequency" name="cleanup_frequency">
                                        <option value="hourly" <?php selected(($options['cleanup_frequency'] ?? 'daily'), 'hourly'); ?>>Hourly</option>
                                        <option value="twicedaily" <?php selected(($options['cleanup_frequency'] ?? 'daily'), 'twicedaily'); ?>>Twice Daily</option>
                                        <option value="daily" <?php selected(($options['cleanup_frequency'] ?? 'daily'), 'daily'); ?>>Daily</option>
                                        <option value="weekly" <?php selected(($options['cleanup_frequency'] ?? 'daily'), 'weekly'); ?>>Weekly</option>
                                    </select>
                                    <p class="description">How often to run automatic cleanup</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="auto_cleanup_days">Auto Cleanup After (Days)</label>
                                </th>
                                <td>
                                    <input type="number" id="auto_cleanup_days" name="auto_cleanup_days" 
                                           value="<?php echo esc_attr($options['auto_cleanup_days'] ?? 7); ?>" 
                                           min="1" max="365" class="regular-text" />
                                    <p class="description">Files will be automatically deleted after this many days</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="webhook_secret">Webhook Secret Key</label>
                                </th>
                                <td>
                                    <input type="text" id="webhook_secret" name="webhook_secret" 
                                           value="<?php echo esc_attr($options['webhook_secret'] ?? ''); ?>" 
                                           class="regular-text" readonly />
                                    <button type="button" class="button" id="regenerate-secret">Regenerate</button>
                                    <p class="description">Use this secret key in your webhook calls</p>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="submit" class="button button-primary" id="save-config">Save Configuration</button>
                        </p>
                    </form>
                </div>
                
                <!-- Generation Section -->
                <div class="content-balloon-section">
                    <h2>Generate Test Files</h2>
                    <div class="content-balloon-actions">
                        <button type="button" class="button button-primary" id="generate-files">Generate Files</button>
                        <button type="button" class="button button-secondary" id="check-status">Check Status</button>
                    </div>
                    
                    <div id="generation-progress" style="display: none;">
                        <div class="progress-bar">
                            <div class="progress-fill"></div>
                        </div>
                        <p id="progress-text">Preparing...</p>
                    </div>
                </div>
                
                <!-- Status Section -->
                <div class="content-balloon-section">
                    <h2>Current Status</h2>
                    <div id="status-display">
                        <p><strong>Total Files Generated:</strong> <span id="total-files"><?php echo esc_html($status['total_files'] ?? 0); ?></span></p>
                        <p><strong>Total Size Generated:</strong> <span id="total-size"><?php echo esc_html($this->format_bytes($status['total_size'] ?? 0)); ?></span></p>
                        <p><strong>Last Generation:</strong> <span id="last-generation"><?php echo esc_html($status['last_generation'] ?? 'Never'); ?></span></p>
                        <p><strong>Current Status:</strong> <span id="current-status"><?php echo esc_html($status['status'] ?? 'Idle'); ?></span></p>
                    </div>
                </div>
                
                <!-- Cleanup Section -->
                <div class="content-balloon-section">
                    <h2>Cleanup</h2>
                    <div class="content-balloon-actions">
                        <button type="button" class="button button-warning" id="manual-cleanup">Manual Cleanup</button>
                        <button type="button" class="button button-secondary" id="test-cleanup">Test Cleanup (Dry Run)</button>
                    </div>
                    <p class="description">Manual cleanup will remove all generated files immediately. Test cleanup will show what would be deleted without actually deleting.</p>
                </div>
                
                <!-- Webhook Information -->
                <div class="content-balloon-section">
                    <h2>Webhook API</h2>
                    <p>You can trigger file generation via webhook using the following endpoint:</p>
                    <code><?php echo esc_url(rest_url('content-balloon/v1/generate')); ?></code>
                    
                    <h3>Usage Example:</h3>
                    <pre><code>curl -X POST <?php echo esc_url(rest_url('content-balloon/v1/generate')); ?> \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: <?php echo esc_html($options['webhook_secret'] ?? ''); ?>" \
  -d '{"file_count": 100, "max_file_size": 10, "min_file_size": 1}'</code></pre>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX progress handler
     */
    public function ajax_progress() {
        if (!wp_verify_nonce($_POST['nonce'], 'content_balloon_nonce')) {
            wp_die('Security check failed');
        }
        
        $generator = new Content_Balloon_Generator();
        $status = $generator->get_status();
        
        wp_send_json_success($status);
    }
    
    /**
     * AJAX handler for saving configuration
     */
    public function ajax_save_config() {
        if (!wp_verify_nonce($_POST['nonce'], 'content_balloon_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $options = array(
            'max_files_per_run' => intval($_POST['file_count']),
            'min_file_size_mb' => intval($_POST['min_file_size']),
            'max_file_size_mb' => intval($_POST['max_file_size']),
            'auto_cleanup_days' => intval($_POST['auto_cleanup_days']),
            'cleanup_enabled' => isset($_POST['cleanup_enabled']),
            'cleanup_frequency' => sanitize_text_field($_POST['cleanup_frequency'])
        );
        
        // Validate inputs
        if ($options['max_files_per_run'] < 1 || $options['max_files_per_run'] > 10000) {
            wp_send_json_error('Invalid file count');
        }
        
        if ($options['min_file_size_mb'] < 1 || $options['min_file_size_mb'] > 1000) {
            wp_send_json_error('Invalid min file size');
        }
        
        if ($options['max_file_size_mb'] < 1 || $options['max_file_size_mb'] > 10240) {
            wp_send_json_error('Invalid max file size');
        }
        
        if ($options['min_file_size_mb'] >= $options['max_file_size_mb']) {
            wp_send_json_error('Min file size must be less than max file size');
        }
        
        if ($options['auto_cleanup_days'] < 1 || $options['auto_cleanup_days'] > 365) {
            wp_send_json_error('Invalid cleanup days');
        }
        
        // Update options
        $result = update_option('content_balloon_options', $options);
        
        if ($result) {
            wp_send_json_success('Configuration saved successfully');
        } else {
            wp_send_json_error('Failed to save configuration');
        }
    }
    
    /**
     * Format bytes to human readable format
     */
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
