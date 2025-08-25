<?php
/**
 * Content Balloon REST API
 *
 * @package Content_Balloon
 */

class Content_Balloon_API {
    
    /**
     * Initialize REST API
     */
    public function init() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * Register REST routes
     */
    public function register_routes() {
        register_rest_route('content-balloon/v1', '/generate', array(
            'methods' => 'POST',
            'callback' => array($this, 'generate_files'),
            'permission_callback' => array($this, 'check_webhook_permission'),
            'args' => array(
                'file_count' => array(
                    'required' => false,
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 10000,
                    'default' => 100
                ),
                'max_file_size' => array(
                    'required' => false,
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 10240,
                    'default' => 10240
                ),
                'min_file_size' => array(
                    'required' => false,
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 1000,
                    'default' => 1
                )
            )
        ));
        
        register_rest_route('content-balloon/v1', '/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_status'),
            'permission_callback' => array($this, 'check_webhook_permission')
        ));
        
        register_rest_route('content-balloon/v1', '/cleanup', array(
            'methods' => 'POST',
            'callback' => array($this, 'cleanup_files'),
            'permission_callback' => array($this, 'check_webhook_permission'),
            'args' => array(
                'dry_run' => array(
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false
                )
            )
        ));
    }
    
    /**
     * Check webhook permission using secret key
     */
    public function check_webhook_permission($request) {
        $secret = $request->get_header('X-Webhook-Secret');
        if (empty($secret)) {
            return false;
        }
        
        $options = get_option('content_balloon_options', array());
        $expected_secret = $options['webhook_secret'] ?? '';
        
        return hash_equals($expected_secret, $secret);
    }
    
    /**
     * Generate files via webhook
     */
    public function generate_files($request) {
        $file_count = $request->get_param('file_count');
        $max_file_size = $request->get_param('max_file_size');
        $min_file_size = $request->get_param('min_file_size');
        
        // Validate file size relationship
        if ($min_file_size >= $max_file_size) {
            return new WP_Error(
                'invalid_file_sizes',
                'Minimum file size must be less than maximum file size',
                array('status' => 400)
            );
        }
        
        // Update options
        $options = get_option('content_balloon_options', array());
        $options['max_files_per_run'] = $file_count;
        $options['max_file_size_mb'] = $max_file_size;
        $options['min_file_size_mb'] = $min_file_size;
        update_option('content_balloon_options', $options);
        
        // Start generation
        $generator = new Content_Balloon_Generator();
        $result = $generator->generate_files($file_count, $max_file_size, $min_file_size);
        
        if ($result['success']) {
            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'File generation started successfully',
                'file_count' => $file_count,
                'max_file_size' => $max_file_size,
                'min_file_size' => $min_file_size,
                'job_id' => $result['job_id'] ?? null
            ), 200);
        } else {
            return new WP_Error(
                'generation_failed',
                $result['message'] ?? 'File generation failed',
                array('status' => 500)
            );
        }
    }
    
    /**
     * Get status via webhook
     */
    public function get_status($request) {
        $generator = new Content_Balloon_Generator();
        $status = $generator->get_status();
        
        return new WP_REST_Response($status, 200);
    }
    
    /**
     * Cleanup files via webhook
     */
    public function cleanup_files($request) {
        $dry_run = $request->get_param('dry_run');
        
        $cleanup = new Content_Balloon_Cleanup();
        
        if ($dry_run) {
            $result = $cleanup->test_cleanup();
        } else {
            $result = $cleanup->manual_cleanup();
        }
        
        if ($result['success']) {
            return new WP_REST_Response(array(
                'success' => true,
                'message' => $dry_run ? 'Test cleanup completed' : 'Cleanup completed successfully',
                'files_to_delete' => $result['files_to_delete'] ?? 0,
                'size_to_free' => $result['size_to_free'] ?? 0,
                'dry_run' => $dry_run
            ), 200);
        } else {
            return new WP_Error(
                'cleanup_failed',
                $result['message'] ?? 'Cleanup failed',
                array('status' => 500)
            );
        }
    }
}
