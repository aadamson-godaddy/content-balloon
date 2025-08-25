<?php
/**
 * Main Content Balloon Plugin Class
 *
 * @package Content_Balloon
 */

class Content_Balloon {
    
    /**
     * Plugin options
     */
    private $options;
    
    /**
     * Generator instance
     */
    private $generator;
    
    /**
     * Cleanup instance
     */
    private $cleanup;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->options = get_option('content_balloon_options', array());
        $this->generator = new Content_Balloon_Generator();
        $this->cleanup = new Content_Balloon_Cleanup();
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Set up scheduled cleanup only if enabled
        $options = get_option('content_balloon_options', array());
        if (($options['cleanup_enabled'] ?? true) && !wp_next_scheduled('content_balloon_cleanup')) {
            wp_schedule_event(time(), 'daily', 'content_balloon_cleanup');
        }
        
        // Hook cleanup event
        add_action('content_balloon_cleanup', array($this->cleanup, 'auto_cleanup'));
        
        // Add AJAX handlers
        add_action('wp_ajax_content_balloon_generate', array($this, 'ajax_generate'));
        add_action('wp_ajax_content_balloon_cleanup', array($this, 'ajax_cleanup'));
        add_action('wp_ajax_content_balloon_status', array($this, 'ajax_status'));
        
        // Add admin notices
        add_action('admin_notices', array($this, 'admin_notices'));
    }
    
    /**
     * AJAX handler for file generation
     */
    public function ajax_generate() {
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'content_balloon_nonce')) {
            wp_die('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $file_count = intval($_POST['file_count']);
        $max_file_size = floatval($_POST['max_file_size']);
        $min_file_size = floatval($_POST['min_file_size']);
        
        // Validate inputs
        if ($file_count < 1 || $file_count > 10000) {
            wp_die('Invalid file count');
        }
        
        if ($max_file_size < 0.001 || $max_file_size > 10240) {
            wp_die('Invalid max file size (must be between 0.001 and 10,240 MB)');
        }
        
        if ($min_file_size < 0.001 || $min_file_size >= $max_file_size) {
            wp_die('Invalid min file size (must be between 0.001 and max file size)');
        }
        
        // Update options
        $this->options['max_files_per_run'] = $file_count;
        $this->options['max_file_size_mb'] = $max_file_size;
        $this->options['min_file_size_mb'] = $min_file_size;
        update_option('content_balloon_options', $this->options);
        
        // Start generation in background
        $this->generator->generate_files($file_count, $max_file_size, $min_file_size);
        
        wp_send_json_success(array(
            'message' => 'File generation started. Check the status for progress updates.',
            'file_count' => $file_count
        ));
    }
    
    /**
     * AJAX handler for cleanup
     */
    public function ajax_cleanup() {
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'content_balloon_nonce')) {
            wp_die('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $result = $this->cleanup->manual_cleanup();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX handler for status
     */
    public function ajax_status() {
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'content_balloon_nonce')) {
            wp_die('Security check failed');
        }
        
        $status = $this->generator->get_status();
        wp_send_json_success($status);
    }
    
    /**
     * Display admin notices
     */
    public function admin_notices() {
        if (isset($_GET['page']) && $_GET['page'] === 'content-balloon') {
            if (isset($_GET['generated'])) {
                echo '<div class="notice notice-success is-dismissible"><p>Files generated successfully!</p></div>';
            }
            if (isset($_GET['cleaned'])) {
                echo '<div class="notice notice-success is-dismissible"><p>Cleanup completed successfully!</p></div>';
            }
        }
    }
    
    /**
     * Get plugin options
     */
    public function get_options() {
        return $this->options;
    }
    
    /**
     * Update plugin options
     */
    public function update_options($options) {
        $this->options = array_merge($this->options, $options);
        
        // Handle cleanup schedule changes
        $this->update_cleanup_schedule();
        
        return update_option('content_balloon_options', $this->options);
    }
    
    /**
     * Update cleanup schedule based on configuration
     */
    private function update_cleanup_schedule() {
        $cleanup_enabled = $this->options['cleanup_enabled'] ?? true;
        $cleanup_frequency = $this->options['cleanup_frequency'] ?? 'daily';
        
        // Clear existing schedule
        wp_clear_scheduled_hook('content_balloon_cleanup');
        
        // Set new schedule if enabled
        if ($cleanup_enabled) {
            $frequency_map = array(
                'hourly' => 'hourly',
                'twicedaily' => 'twicedaily', 
                'daily' => 'daily',
                'weekly' => 'weekly'
            );
            
            $schedule = $frequency_map[$cleanup_frequency] ?? 'daily';
            wp_schedule_event(time(), $schedule, 'content_balloon_cleanup');
        }
    }
}
