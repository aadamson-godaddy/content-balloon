<?php
/**
 * Content Balloon Cleanup
 *
 * @package Content_Balloon
 */

class Content_Balloon_Cleanup {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Hook into scheduled cleanup
        add_action('content_balloon_cleanup', array($this, 'auto_cleanup'));
    }
    
    /**
     * Automatic cleanup based on configured retention period
     */
    public function auto_cleanup() {
        $options = get_option('content_balloon_options', array());
        
        if (!($options['cleanup_enabled'] ?? false)) {
            return;
        }
        
        $retention_days = $options['auto_cleanup_days'] ?? 7;
        $cutoff_time = time() - ($retention_days * DAY_IN_SECONDS);
        
        $this->cleanup_files_older_than($cutoff_time);
    }
    
    /**
     * Manual cleanup - remove all generated files
     */
    public function manual_cleanup() {
        $upload_dir = wp_upload_dir();
        $base_pattern = $upload_dir['basedir'] . '/content-balloon-*';
        
        $directories = glob($base_pattern, GLOB_ONLYDIR);
        $files_deleted = 0;
        $size_freed = 0;
        
        foreach ($directories as $directory) {
            $result = $this->delete_directory_contents($directory);
            $files_deleted += $result['files_deleted'];
            $size_freed += $result['size_freed'];
            
            // Remove the directory itself
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
        
        // Reset statistics
        $options = get_option('content_balloon_options', array());
        $options['total_files_generated'] = 0;
        $options['total_size_generated'] = 0;
        $options['last_generation'] = null;
        update_option('content_balloon_options', $options);
        
        // Clear current job
        delete_transient('content_balloon_current_job');
        
        return array(
            'success' => true,
            'files_deleted' => $files_deleted,
            'size_freed' => $size_freed,
            'message' => "Successfully deleted {$files_deleted} files and freed " . $this->format_bytes($size_freed)
        );
    }
    
    /**
     * Test cleanup - show what would be deleted without actually deleting
     */
    public function test_cleanup() {
        $upload_dir = wp_upload_dir();
        $base_pattern = $upload_dir['basedir'] . '/content-balloon-*';
        
        $directories = glob($base_pattern, GLOB_ONLYDIR);
        $files_to_delete = 0;
        $size_to_free = 0;
        
        foreach ($directories as $directory) {
            $result = $this->count_directory_contents($directory);
            $files_to_delete += $result['file_count'];
            $size_to_free += $result['total_size'];
        }
        
        return array(
            'success' => true,
            'files_to_delete' => $files_to_delete,
            'size_to_free' => $size_to_free,
            'message' => "Would delete {$files_to_delete} files and free " . $this->format_bytes($size_to_free)
        );
    }
    
    /**
     * Cleanup files older than specified timestamp
     */
    private function cleanup_files_older_than($cutoff_time) {
        $upload_dir = wp_upload_dir();
        $base_pattern = $upload_dir['basedir'] . '/content-balloon-*';
        
        $directories = glob($base_pattern, GLOB_ONLYDIR);
        $files_deleted = 0;
        $size_freed = 0;
        
        foreach ($directories as $directory) {
            $result = $this->delete_old_files_in_directory($directory, $cutoff_time);
            $files_deleted += $result['files_deleted'];
            $size_freed += $result['size_freed'];
            
            // If directory is empty, remove it
            if (is_dir($directory) && count(scandir($directory)) <= 2) { // . and .. only
                rmdir($directory);
            }
        }
        
        if ($files_deleted > 0) {
            // Update statistics
            $options = get_option('content_balloon_options', array());
            $options['total_files_generated'] = max(0, ($options['total_files_generated'] ?? 0) - $files_deleted);
            $options['total_size_generated'] = max(0, ($options['total_size_generated'] ?? 0) - $size_freed);
            update_option('content_balloon_options', $options);
            
            // Log cleanup
            error_log("Content Balloon: Auto cleanup deleted {$files_deleted} files and freed " . $this->format_bytes($size_freed));
        }
        
        return array(
            'success' => true,
            'files_deleted' => $files_deleted,
            'size_freed' => $size_freed
        );
    }
    
    /**
     * Delete all contents of a directory
     */
    private function delete_directory_contents($directory) {
        $files_deleted = 0;
        $size_freed = 0;
        
        if (!is_dir($directory)) {
            return array('files_deleted' => 0, 'size_freed' => 0);
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                $size = $file->getSize();
                if (unlink($file->getRealPath())) {
                    $files_deleted++;
                    $size_freed += $size;
                }
            }
        }
        
        return array(
            'files_deleted' => $files_deleted,
            'size_freed' => $size_freed
        );
    }
    
    /**
     * Count contents of a directory
     */
    private function count_directory_contents($directory) {
        $file_count = 0;
        $total_size = 0;
        
        if (!is_dir($directory)) {
            return array('file_count' => 0, 'total_size' => 0);
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $file_count++;
                $total_size += $file->getSize();
            }
        }
        
        return array(
            'file_count' => $file_count,
            'total_size' => $total_size
        );
    }
    
    /**
     * Delete old files in a directory
     */
    private function delete_old_files_in_directory($directory, $cutoff_time) {
        $files_deleted = 0;
        $size_freed = 0;
        
        if (!is_dir($directory)) {
            return array('files_deleted' => 0, 'size_freed' => 0);
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $file_time = $file->getMTime();
                if ($file_time < $cutoff_time) {
                    $size = $file->getSize();
                    if (unlink($file->getRealPath())) {
                        $files_deleted++;
                        $size_freed += $size;
                    }
                }
            }
        }
        
        return array(
            'files_deleted' => $files_deleted,
            'size_freed' => $size_freed
        );
    }
    
    /**
     * Get cleanup statistics
     */
    public function get_cleanup_stats() {
        $upload_dir = wp_upload_dir();
        $base_pattern = $upload_dir['basedir'] . '/content-balloon-*';
        
        $directories = glob($base_pattern, GLOB_ONLYDIR);
        $total_files = 0;
        $total_size = 0;
        $oldest_file_time = null;
        $newest_file_time = null;
        
        foreach ($directories as $directory) {
            $result = $this->get_directory_stats($directory);
            $total_files += $result['file_count'];
            $total_size += $result['total_size'];
            
            if ($result['oldest_file_time'] && (!$oldest_file_time || $result['oldest_file_time'] < $oldest_file_time)) {
                $oldest_file_time = $result['oldest_file_time'];
            }
            
            if ($result['newest_file_time'] && (!$newest_file_time || $result['newest_file_time'] > $newest_file_time)) {
                $newest_file_time = $result['newest_file_time'];
            }
        }
        
        return array(
            'total_files' => $total_files,
            'total_size' => $total_size,
            'oldest_file_time' => $oldest_file_time,
            'newest_file_time' => $newest_file_time,
            'directories_count' => count($directories)
        );
    }
    
    /**
     * Get statistics for a specific directory
     */
    private function get_directory_stats($directory) {
        $file_count = 0;
        $total_size = 0;
        $oldest_file_time = null;
        $newest_file_time = null;
        
        if (!is_dir($directory)) {
            return array(
                'file_count' => 0,
                'total_size' => 0,
                'oldest_file_time' => null,
                'newest_file_time' => null
            );
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $file_count++;
                $file_size = $file->getSize();
                $file_time = $file->getMTime();
                
                $total_size += $file_size;
                
                if (!$oldest_file_time || $file_time < $oldest_file_time) {
                    $oldest_file_time = $file_time;
                }
                
                if (!$newest_file_time || $file_time > $newest_file_time) {
                    $newest_file_time = $file_time;
                }
            }
        }
        
        return array(
            'file_count' => $file_count,
            'total_size' => $total_size,
            'oldest_file_time' => $oldest_file_time,
            'newest_file_time' => $newest_file_time
        );
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
