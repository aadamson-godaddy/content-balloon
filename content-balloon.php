<?php
/**
 * Plugin Name: Content Balloon
 * Plugin URI: https://github.com/your-username/content-balloon
 * Description: Generate large amounts of test data by downloading novels from Project Gutenberg and splitting them into smaller files to stress-test filesystem performance and backup systems.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: content-balloon
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CONTENT_BALLOON_VERSION', '1.0.0');
define('CONTENT_BALLOON_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CONTENT_BALLOON_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CONTENT_BALLOON_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Include required files
require_once CONTENT_BALLOON_PLUGIN_DIR . 'includes/class-content-balloon.php';
require_once CONTENT_BALLOON_PLUGIN_DIR . 'includes/class-content-balloon-admin.php';
require_once CONTENT_BALLOON_PLUGIN_DIR . 'includes/class-content-balloon-api.php';
require_once CONTENT_BALLOON_PLUGIN_DIR . 'includes/class-content-balloon-cli.php';
require_once CONTENT_BALLOON_PLUGIN_DIR . 'includes/class-content-balloon-generator.php';
require_once CONTENT_BALLOON_PLUGIN_DIR . 'includes/class-content-balloon-cleanup.php';

// Initialize the plugin
function content_balloon_init() {
    // Initialize main plugin class
    $content_balloon = new Content_Balloon();
    $content_balloon->init();
    
    // Initialize admin interface
    if (is_admin()) {
        $admin = new Content_Balloon_Admin();
        $admin->init();
    }
    
    // Initialize REST API
    $api = new Content_Balloon_API();
    $api->init();
    
    // Initialize CLI commands
    if (defined('WP_CLI') && WP_CLI) {
        WP_CLI::add_command('content-balloon', 'Content_Balloon_CLI');
    }
}
add_action('plugins_loaded', 'content_balloon_init');

// Activation hook
register_activation_hook(__FILE__, 'content_balloon_activate');
function content_balloon_activate() {
            // Create default options
        $default_options = array(
            'webhook_secret' => wp_generate_password(32, false),
            'max_files_per_run' => 100,
            'max_file_size_mb' => 10240,
            'min_file_size_mb' => 0.001,
            'auto_cleanup_days' => 7,
            'cleanup_enabled' => true,
            'cleanup_frequency' => 'daily',
            'last_generation' => null,
            'total_files_generated' => 0,
            'total_size_generated' => 0
        );
    
    add_option('content_balloon_options', $default_options);
    
    // Create upload directory structure
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'] . '/content-balloon-' . uniqid();
    
    if (!file_exists($base_dir)) {
        wp_mkdir_p($base_dir);
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'content_balloon_deactivate');
function content_balloon_deactivate() {
    // Clear scheduled cleanup events
    wp_clear_scheduled_hook('content_balloon_cleanup');
}

// Uninstall hook
register_uninstall_hook(__FILE__, 'content_balloon_uninstall');
function content_balloon_uninstall() {
    // Remove options
    delete_option('content_balloon_options');
    
    // Note: We don't automatically delete generated files on uninstall
    // as this could be destructive. Users should clean up manually.
}
