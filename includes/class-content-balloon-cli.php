<?php
/**
 * Content Balloon CLI Commands
 *
 * @package Content_Balloon
 */

class Content_Balloon_CLI {
    
    /**
     * Generate test files
     *
     * ## OPTIONS
     *
     * [--count=<number>]
     * : Number of files to generate (default: 100)
     *
     * [--max-size=<mb>]
     * : Maximum file size in MB (default: 10)
     *
     * [--min-size=<mb>]
     * : Minimum file size in MB (default: 1)
     *
     * [--verbose]
     * : Show detailed progress information
     *
     * ## EXAMPLES
     *
     *     wp content-balloon generate --count=500 --max-size=20 --min-size=5
     *     wp content-balloon generate --count=1000 --verbose
     *
     * @when after_wp_load
     */
    public function generate($args, $assoc_args) {
        $count = isset($assoc_args['count']) ? intval($assoc_args['count']) : 100;
        $max_size = isset($assoc_args['max-size']) ? intval($assoc_args['max-size']) : 10240;
        $min_size = isset($assoc_args['min-size']) ? intval($assoc_args['min-size']) : 1;
        $verbose = isset($assoc_args['verbose']);
        
        // Validate inputs
        if ($count < 1 || $count > 10000) {
            WP_CLI::error('File count must be between 1 and 10,000');
        }
        
        if ($max_size < 1 || $max_size > 10240) {
            WP_CLI::error('Maximum file size must be between 1 and 10,240 MB (10 GB)');
        }
        
        if ($min_size < 1 || $min_size >= $max_size) {
            WP_CLI::error('Minimum file size must be less than maximum file size');
        }
        
        WP_CLI::log("Starting file generation...");
        WP_CLI::log("Files to generate: {$count}");
        WP_CLI::log("File size range: {$min_size} - {$max_size} MB");
        
        // Update options
        $options = get_option('content_balloon_options', array());
        $options['max_files_per_run'] = $count;
        $options['max_file_size_mb'] = $max_size;
        $options['min_file_size_mb'] = $min_size;
        update_option('content_balloon_options', $options);
        
        // Start generation
        $generator = new Content_Balloon_Generator();
        
        if ($verbose) {
            // Use progress bar for verbose mode
            $progress = \WP_CLI\Utils\make_progress_bar('Generating files', $count);
            
            // Hook into progress updates
            add_action('content_balloon_progress', function($current, $total) use ($progress) {
                $progress->tick();
            });
        }
        
        $result = $generator->generate_files($count, $max_size, $min_size);
        
        if ($verbose) {
            $progress->finish();
        }
        
        if ($result['success']) {
            WP_CLI::success("File generation completed successfully!");
            WP_CLI::log("Generated {$result['files_created']} files");
            WP_CLI::log("Total size: " . $this->format_bytes($result['total_size']));
        } else {
            WP_CLI::error("File generation failed: " . $result['message']);
        }
    }
    
    /**
     * Clean up generated files
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would be deleted without actually deleting
     *
     * [--force]
     * : Skip confirmation prompt
     *
     * ## EXAMPLES
     *
     *     wp content-balloon cleanup
     *     wp content-balloon cleanup --dry-run
     *     wp content-balloon cleanup --force
     *
     * @when after_wp_load
     */
    public function cleanup($args, $assoc_args) {
        $dry_run = isset($assoc_args['dry-run']);
        $force = isset($assoc_args['force']);
        
        $cleanup = new Content_Balloon_Cleanup();
        
        if ($dry_run) {
            WP_CLI::log("Performing dry run cleanup...");
            $result = $cleanup->test_cleanup();
            
            if ($result['success']) {
                WP_CLI::log("Files that would be deleted: {$result['files_to_delete']}");
                WP_CLI::log("Space that would be freed: " . $this->format_bytes($result['size_to_free']));
            } else {
                WP_CLI::error("Test cleanup failed: " . $result['message']);
            }
            return;
        }
        
        // Get cleanup info first
        $test_result = $cleanup->test_cleanup();
        if (!$test_result['success']) {
            WP_CLI::error("Cannot determine files to delete: " . $test_result['message']);
        }
        
        WP_CLI::log("Files to delete: {$test_result['files_to_delete']}");
        WP_CLI::log("Space to free: " . $this->format_bytes($test_result['size_to_free']));
        
        if (!$force) {
            $answer = WP_CLI\Utils\get_flag_value($assoc_args, 'yes', false);
            if (!$answer) {
                $answer = \WP_CLI\Utils\get_input('Are you sure you want to delete all generated files? [y/N]');
                if (strtolower($answer) !== 'y' && strtolower($answer) !== 'yes') {
                    WP_CLI::log('Operation cancelled.');
                    return;
                }
            }
        }
        
        WP_CLI::log("Starting cleanup...");
        $result = $cleanup->manual_cleanup();
        
        if ($result['success']) {
            WP_CLI::success("Cleanup completed successfully!");
            WP_CLI::log("Deleted {$result['files_deleted']} files");
            WP_CLI::log("Freed " . $this->format_bytes($result['size_freed']));
        } else {
            WP_CLI::error("Cleanup failed: " . $result['message']);
        }
    }
    
    /**
     * Show current status
     *
     * ## EXAMPLES
     *
     *     wp content-balloon status
     *
     * @when after_wp_load
     */
    public function status($args, $assoc_args) {
        $generator = new Content_Balloon_Generator();
        $status = $generator->get_status();
        
        WP_CLI::log("Content Balloon Status");
        WP_CLI::log("=====================");
        WP_CLI::log("Total files generated: {$status['total_files']}");
        WP_CLI::log("Total size generated: " . $this->format_bytes($status['total_size']));
        WP_CLI::log("Last generation: " . ($status['last_generation'] ?: 'Never'));
        WP_CLI::log("Current status: {$status['status']}");
        
        if ($status['current_job']) {
            WP_CLI::log("Current job: {$status['current_job']['files_completed']}/{$status['current_job']['total_files']} files completed");
        }
        
        // Show options
        $options = get_option('content_balloon_options', array());
        WP_CLI::log("");
        WP_CLI::log("Configuration:");
        WP_CLI::log("Max files per run: " . ($options['max_files_per_run'] ?? 'Not set'));
        WP_CLI::log("File size range: " . ($options['min_file_size_mb'] ?? 'Not set') . " - " . ($options['max_file_size_mb'] ?? 'Not set') . " MB");
        WP_CLI::log("Auto cleanup: " . (($options['cleanup_enabled'] ?? false) ? 'Enabled' : 'Disabled'));
        if ($options['cleanup_enabled']) {
            WP_CLI::log("Auto cleanup frequency: " . ($options['cleanup_frequency'] ?? 'daily'));
            WP_CLI::log("Auto cleanup after: " . ($options['auto_cleanup_days'] ?? 'Not set') . " days");
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
