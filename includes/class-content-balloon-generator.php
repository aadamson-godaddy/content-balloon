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
            ini_set('memory_limit', '3G');
        }
        
        // Add memory cleanup
        add_action('content_balloon_progress', array($this, 'cleanup_memory'), 10, 2);
        
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
        
        // Download multiple books to build a content library
        $content_library = $this->build_content_library();
        
        if (empty($content_library)) {
            error_log('Content Balloon: Failed to download any books for content library');
            return;
        }
        
        // Create random subdirectories
        $subdir = $base_dir . '/' . $this->generate_random_subdir();
        if (!file_exists($subdir)) {
            wp_mkdir_p($subdir);
        }
        
        // Generate files using the content library
        $files_from_library = $this->generate_files_from_library(
            $content_library, 
            $subdir, 
            $this->current_job['total_files'],
            $this->current_job['max_file_size'],
            $this->current_job['min_file_size']
        );
        
        foreach ($files_from_library as $file_info) {
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
     * Build a content library from multiple books
     */
    private function build_content_library() {
        $library = array();
        
        // Download multiple books to build a larger content pool
        $books_to_download = min(5, count($this->book_ids)); // Reduced to 5 books to save memory
        
        $selected_keys = array_rand($this->book_ids, $books_to_download);
        if (!is_array($selected_keys)) {
            $selected_keys = array($selected_keys);
        }
        
        foreach ($selected_keys as $key) {
            $book_id = $this->book_ids[$key];
            $book_content = $this->download_book($book_id);
            
            if ($book_content) {
                $library[] = $book_content;
                error_log("Content Balloon: Downloaded book {$book_id}, size: " . $this->format_bytes(strlen($book_content)));
                
                // Free memory after each book
                unset($book_content);
            }
        }
        
        error_log("Content Balloon: Built content library with " . count($library) . " books");
        return $library;
    }
    
    /**
     * Generate files from the content library
     */
    private function generate_files_from_library($library, $directory, $file_count, $max_size, $min_size) {
        $files = array();
        
        // Generate size distribution
        $size_distribution = $this->generate_size_distribution($file_count, $min_size, $max_size);
        
        for ($i = 0; $i < $file_count; $i++) {
            $target_size = $size_distribution[$i];
            $filename = $this->generate_random_filename();
            $filepath = $directory . '/' . $filename;
            
            // Generate file directly without loading into memory
            if ($this->generate_file_directly($library, $filepath, $target_size)) {
                $actual_size = filesize($filepath);
                $files[] = array(
                    'path' => $filepath,
                    'size' => $actual_size,
                    'filename' => $filename
                );
                
                error_log("Content Balloon: Generated file {$filename} - Target: " . $this->format_bytes($target_size) . ", Actual: " . $this->format_bytes($actual_size));
            }
        }
        
        return $files;
    }
    
    /**
     * Generate file directly to disk without loading into memory
     */
    private function generate_file_directly($library, $filepath, $target_size) {
        $handle = fopen($filepath, 'w');
        if ($handle === false) {
            return false;
        }
        
        $bytes_written = 0;
        $book_index = 0;
        $repetition_count = 0;
        
        // Write content until we reach the target size
        while ($bytes_written < $target_size) {
            // Get content from current book
            $book_content = $library[$book_index % count($library)];
            $book_length = strlen($book_content);
            
            // If this book is large enough, extract a chunk
            if ($book_length >= ($target_size - $bytes_written)) {
                $chunk_size = $target_size - $bytes_written;
                $start_pos = rand(0, $book_length - $chunk_size);
                $chunk = substr($book_content, $start_pos, $chunk_size);
                
                if (fwrite($handle, $chunk) === false) {
                    fclose($handle);
                    return false;
                }
                $bytes_written += strlen($chunk);
                break;
            }
            
            // Write the entire book content
            if (fwrite($handle, $book_content) === false) {
                fclose($handle);
                return false;
            }
            $bytes_written += $book_length;
            
            // Add separator
            $separator = "\n\n--- BOOK " . ($book_index + 1) . " ---\n\n";
            if (fwrite($handle, $separator) === false) {
                fclose($handle);
                return false;
            }
            $bytes_written += strlen($separator);
            
            // Move to next book
            $book_index++;
            
            // If we've used all books, start repeating
            if ($book_index >= count($library)) {
                $repetition_count++;
                $book_index = 0;
                
                // Add repetition marker
                $repetition_marker = "\n\n--- REPETITION " . $repetition_count . " ---\n\n";
                if (fwrite($handle, $repetition_marker) === false) {
                    fclose($handle);
                    return false;
                }
                $bytes_written += strlen($repetition_marker);
            }
            
            // If we're getting close to target, add padding
            if (($target_size - $bytes_written) < 1024) {
                $padding_needed = $target_size - $bytes_written;
                $padding = $this->generate_padding_content($padding_needed);
                
                if (fwrite($handle, $padding) === false) {
                    fclose($handle);
                    return false;
                }
                $bytes_written += strlen($padding);
                break;
            }
        }
        
        fclose($handle);
        return true;
    }
    
    /**
     * Generate padding content to reach target size
     */
    private function generate_padding_content($size_needed) {
        $padding = '';
        $words = array(
            'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit',
            'sed', 'do', 'eiusmod', 'tempor', 'incididunt', 'ut', 'labore', 'et', 'dolore',
            'magna', 'aliqua', 'ut', 'enim', 'ad', 'minim', 'veniam', 'quis', 'nostrud',
            'exercitation', 'ullamco', 'laboris', 'nisi', 'ut', 'aliquip', 'ex', 'ea',
            'commodo', 'consequat', 'duis', 'aute', 'irure', 'dolor', 'in', 'reprehenderit',
            'voluptate', 'velit', 'esse', 'cillum', 'dolore', 'eu', 'fugiat', 'nulla',
            'pariatur', 'excepteur', 'sint', 'occaecat', 'cupidatat', 'non', 'proident',
            'sunt', 'culpa', 'qui', 'officia', 'deserunt', 'mollit', 'anim', 'id', 'est',
            'laborum', 'et', 'dolore', 'magna', 'aliqua', 'ut', 'enim', 'ad', 'minim'
        );
        
        while (strlen($padding) < $size_needed) {
            $padding .= $words[array_rand($words)] . ' ';
        }
        
        return substr($padding, 0, $size_needed);
    }
    
    /**
     * Write file content (handles both small and large files)
     */
    private function write_file_content($filepath, $content) {
        $content_size = strlen($content);
        
        if ($content_size > 100 * 1024 * 1024) { // If > 100MB, use streaming
            return $this->write_large_file_streaming($filepath, $content);
        } else {
            return file_put_contents($filepath, $content) !== false;
        }
    }
    
    /**
     * Generate a better distribution of file sizes
     */
    private function generate_size_distribution($file_count, $min_size, $max_size) {
        $sizes = array();
        
        // Create a more varied distribution
        for ($i = 0; $i < $file_count; $i++) {
            // Use different distribution methods for variety
            $method = $i % 4;
            
            switch ($method) {
                case 0: // Random distribution
                    $sizes[] = rand($min_size, $max_size);
                    break;
                    
                case 1: // Linear distribution
                    $ratio = $i / ($file_count - 1);
                    $sizes[] = $min_size + ($ratio * ($max_size - $min_size));
                    break;
                    
                case 2: // Exponential distribution (more small files)
                    $ratio = $i / ($i == 0 ? 1 : $i);
                    $sizes[] = $min_size + (pow($ratio, 2) * ($max_size - $min_size));
                    break;
                    
                case 3: // Inverse exponential distribution (more large files)
                    $ratio = $i / ($file_count - 1);
                    $sizes[] = $min_size + (pow(1 - $ratio, 2) * ($max_size - $min_size));
                    break;
            }
        }
        
        // Shuffle the sizes for randomness
        shuffle($sizes);
        
        return $sizes;
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
        
        // Try to end at a word boundary, but be less aggressive about cutting
        $last_space = strrpos($chunk, ' ');
        if ($last_space !== false && $last_space > $target_size * 0.9) {
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
    
    /**
     * Clean up memory during file generation
     */
    public function cleanup_memory($current, $total) {
        // Force garbage collection every 10 files
        if ($current % 10 === 0) {
            gc_collect_cycles();
            
            // Log memory usage
            $memory_usage = memory_get_usage(true);
            $memory_peak = memory_get_peak_usage(true);
            error_log("Content Balloon: Memory usage - Current: " . $this->format_bytes($memory_usage) . ", Peak: " . $this->format_bytes($memory_peak));
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
