<?php
/**
 * Content Balloon Generator
 *
 * @package Content_Balloon
 */

class Content_Balloon_Generator {
    
    /**
     * Project Gutenberg catalog URL
     */
    private $gutenberg_catalog_url = 'https://www.gutenberg.org/ebooks/';
    
    /**
     * Work-appropriate book IDs (classic literature, public domain)
     */
    private $book_ids = array(
        1342,  // Pride and Prejudice
        11,    // Alice's Adventures in Wonderland
        76,    // Adventures of Huckleberry Finn
        1661,  // The Adventures of Sherlock Holmes
        98,    // A Tale of Two Cities
        1400,  // Great Expectations
        345,   // Dracula
        84,    // Frankenstein
        1952,  // The Yellow Wallpaper
        74,    // The Adventures of Tom Sawyer
        1080,  // A Modest Proposal
        1184,  // The Count of Monte Cristo
        1399,  // A Christmas Carol
        158,   // Emma
        1260,  // Jane Eyre
        514,   // Middlemarch
        2814,  // Dubliners
        2600,  // War and Peace
        4363,  // The Moonstone
        5200   // Metamorphosis
    );
    
    /**
     * Current generation job
     */
    private $current_job = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Load current job from transient
        $this->current_job = get_transient('content_balloon_current_job');
    }
    
    /**
     * Generate files by downloading novels and splitting them
     */
    public function generate_files($file_count, $max_file_size, $min_file_size) {
        // Check if already running
        if ($this->current_job && $this->current_job['status'] === 'running') {
            return array(
                'success' => false,
                'message' => 'File generation already in progress'
            );
        }
        
        // Initialize job
        $this->current_job = array(
            'id' => uniqid('cb_'),
            'status' => 'running',
            'total_files' => $file_count,
            'files_completed' => 0,
            'total_size' => 0,
            'started_at' => current_time('timestamp'),
            'max_file_size' => $max_file_size * 1024 * 1024, // Convert to bytes
            'min_file_size' => $min_file_size * 1024 * 1024  // Convert to bytes
        );
        
        // Set memory limit for large file operations
        if ($max_file_size > 1000) { // If max file size > 1GB
            ini_set('memory_limit', '2G');
        }
        
        // Save job to transient
        set_transient('content_balloon_current_job', $this->current_job, HOUR_IN_SECONDS);
        
        // Start background process
        $this->process_generation();
        
        return array(
            'success' => true,
            'message' => 'File generation started',
            'job_id' => $this->current_job['id']
        );
    }
    
    /**
     * Process file generation
     */
    private function process_generation() {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'] . '/content-balloon-' . uniqid();
        
        // Create base directory
        if (!file_exists($base_dir)) {
            wp_mkdir_p($base_dir);
        }
        
        $files_created = 0;
        $total_size = 0;
        
        // Download and process novels
        while ($files_created < $this->current_job['total_files']) {
            // Select random book
            $book_id = $this->book_ids[array_rand($this->book_ids)];
            $book_content = $this->download_book($book_id);
            
            if (!$book_content) {
                continue; // Try another book
            }
            
            // Create random subdirectories
            $subdir = $base_dir . '/' . $this->generate_random_subdir();
            if (!file_exists($subdir)) {
                wp_mkdir_p($subdir);
            }
            
            // Split book into files
            $files_from_book = $this->split_book_into_files(
                $book_content, 
                $subdir, 
                $this->current_job['total_files'] - $files_created,
                $this->current_job['max_file_size'],
                $this->current_job['min_file_size']
            );
            
            foreach ($files_from_book as $file_info) {
                if ($files_created >= $this->current_job['total_files']) {
                    break;
                }
                
                $files_created++;
                $total_size += $file_info['size'];
                
                // Update progress
                $this->current_job['files_completed'] = $files_created;
                $this->current_job['total_size'] = $total_size;
                set_transient('content_balloon_current_job', $this->current_job, HOUR_IN_SECONDS);
                
                // Trigger progress action
                do_action('content_balloon_progress', $files_created, $this->current_job['total_files']);
                
                // Small delay to prevent overwhelming the system
                usleep(10000); // 10ms
            }
        }
        
        // Update options
        $options = get_option('content_balloon_options', array());
        $options['last_generation'] = current_time('timestamp');
        $options['total_files_generated'] = ($options['total_files_generated'] ?? 0) + $files_created;
        $options['total_size_generated'] = ($options['total_size_generated'] ?? 0) + $total_size;
        update_option('content_balloon_options', $options);
        
        // Mark job as complete
        $this->current_job['status'] = 'completed';
        $this->current_job['completed_at'] = current_time('timestamp');
        set_transient('content_balloon_current_job', $this->current_job, HOUR_IN_SECONDS);
    }
    
    /**
     * Download book from Project Gutenberg
     */
    private function download_book($book_id) {
        $url = $this->gutenberg_catalog_url . $book_id;
        
        // Get the book page to find the text file link
        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        
        // Look for plain text download link
        if (preg_match('/href="([^"]*\.txt)"/', $body, $matches)) {
            $text_url = $matches[1];
            
            // If relative URL, make it absolute
            if (strpos($text_url, 'http') !== 0) {
                $text_url = 'https://www.gutenberg.org' . $text_url;
            }
            
            // Download the text file
            $text_response = wp_remote_get($text_url);
            if (!is_wp_error($text_response)) {
                return wp_remote_retrieve_body($text_response);
            }
        }
        
        // Fallback: try direct text file URL
        $direct_url = "https://www.gutenberg.org/files/{$book_id}/{$book_id}-0.txt";
        $direct_response = wp_remote_get($direct_url);
        if (!is_wp_error($direct_response)) {
            return wp_remote_retrieve_body($direct_response);
        }
        
        return false;
    }
    
    /**
     * Split book content into multiple files
     */
    private function split_book_into_files($content, $directory, $max_files, $max_size, $min_size) {
        $files = array();
        $content_length = strlen($content);
        
        for ($i = 0; $i < $max_files; $i++) {
            // Generate random file size within range
            $target_size = rand($min_size, $max_size);
            
            // Generate random filename
            $filename = $this->generate_random_filename();
            $filepath = $directory . '/' . $filename;
            
            // Extract random chunk of content
            $chunk = $this->extract_random_chunk($content, $target_size);
            
            // Write file using streaming for very large files
            if ($target_size > 100 * 1024 * 1024) { // If > 100MB, use streaming
                if ($this->write_large_file_streaming($filepath, $chunk)) {
                    $files[] = array(
                        'path' => $filepath,
                        'size' => strlen($chunk),
                        'filename' => $filename
                    );
                }
            } else {
                // Use regular file writing for smaller files
                if (file_put_contents($filepath, $chunk) !== false) {
                    $files[] = array(
                        'path' => $filepath,
                        'size' => strlen($chunk),
                        'filename' => $filename
                    );
                }
            }
        }
        
        return $files;
    }
    
    /**
     * Extract random chunk of content
     */
    private function extract_random_chunk($content, $target_size) {
        $content_length = strlen($content);
        
        if ($content_length <= $target_size) {
            return $content;
        }
        
        // Find random starting position
        $max_start = $content_length - $target_size;
        $start = rand(0, $max_start);
        
        // Extract chunk
        $chunk = substr($content, $start, $target_size);
        
        // Try to end at a word boundary
        $last_space = strrpos($chunk, ' ');
        if ($last_space !== false && $last_space > $target_size * 0.8) {
            $chunk = substr($chunk, 0, $last_space);
        }
        
        return $chunk;
    }
    
    /**
     * Generate random subdirectory name
     */
    private function generate_random_subdir() {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $length = rand(5, 10);
        $subdir = '';
        
        for ($i = 0; $i < $length; $i++) {
            $subdir .= $chars[rand(0, strlen($chars) - 1)];
        }
        
        return $subdir;
    }
    
    /**
     * Write large files using streaming to avoid memory issues
     */
    private function write_large_file_streaming($filepath, $content) {
        $handle = fopen($filepath, 'w');
        if ($handle === false) {
            return false;
        }
        
        // Write content in chunks to avoid memory issues
        $chunk_size = 1024 * 1024; // 1MB chunks
        $content_length = strlen($content);
        
        for ($offset = 0; $offset < $content_length; $offset += $chunk_size) {
            $chunk = substr($content, $offset, $chunk_size);
            if (fwrite($handle, $chunk) === false) {
                fclose($handle);
                return false;
            }
        }
        
        fclose($handle);
        return true;
    }
    
    /**
     * Generate random filename
     */
    private function generate_random_filename() {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $length = rand(8, 15);
        $filename = '';
        
        for ($i = 0; $i < $length; $i++) {
            $filename .= $chars[rand(0, strlen($chars) - 1)];
        }
        
        return $filename . '.txt';
    }
    
    /**
     * Get current generation status
     */
    public function get_status() {
        $options = get_option('content_balloon_options', array());
        
        $status = array(
            'total_files' => $options['total_files_generated'] ?? 0,
            'total_size' => $options['total_size_generated'] ?? 0,
            'last_generation' => $options['last_generation'] ? date('Y-m-d H:i:s', $options['last_generation']) : null,
            'status' => 'Idle'
        );
        
        if ($this->current_job) {
            if ($this->current_job['status'] === 'running') {
                $status['status'] = 'Running';
                $status['current_job'] = array(
                    'files_completed' => $this->current_job['files_completed'],
                    'total_files' => $this->current_job['total_files'],
                    'progress_percent' => round(($this->current_job['files_completed'] / $this->current_job['total_files']) * 100, 2)
                );
            } elseif ($this->current_job['status'] === 'completed') {
                $status['status'] = 'Completed';
            }
        }
        
        return $status;
    }
    
    /**
     * Stop current generation
     */
    public function stop_generation() {
        if ($this->current_job && $this->current_job['status'] === 'running') {
            $this->current_job['status'] = 'stopped';
            $this->current_job['stopped_at'] = current_time('timestamp');
            set_transient('content_balloon_current_job', $this->current_job, HOUR_IN_SECONDS);
            
            return array(
                'success' => true,
                'message' => 'Generation stopped'
            );
        }
        
        return array(
            'success' => false,
            'message' => 'No generation running'
        );
    }
}
