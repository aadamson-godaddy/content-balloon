# Content Balloon - WordPress Plugin

A powerful WordPress plugin designed to generate large amounts of test data by downloading novels from Project Gutenberg and splitting them into smaller files. Perfect for stress-testing filesystem performance, backup systems, and storage solutions.

## Features

- **📚 Novel Downloads**: Automatically downloads classic literature from Project Gutenberg
- **✂️ Smart File Splitting**: Efficiently splits novels into configurable file sizes
- **🌐 Multiple Interfaces**: Admin dashboard, REST API webhooks, and WP-CLI commands
- **📁 Organized Storage**: Creates random subdirectories with `content-balloon-` prefix
- **🔄 Progress Tracking**: Real-time progress updates during file generation
- **🧹 Auto Cleanup**: Configurable automatic cleanup with retention periods
- **🔒 Secure Webhooks**: Secret key authentication for API endpoints
- **📊 Statistics**: Track total files generated and storage used

## Installation

### Method 1: Manual Installation
1. Download the plugin files
2. Upload the `content-balloon` folder to your `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress

### Method 2: Git Clone
```bash
cd wp-content/plugins/
git clone https://github.com/your-username/content-balloon.git
```

## Configuration

### Admin Dashboard
1. Go to **Tools > Content Balloon** in your WordPress admin
2. Configure the following settings:
   - **Number of Files**: How many files to generate per run (1-10,000)
   - **File Size Range**: Minimum and maximum file sizes in MB
   - **Auto Cleanup**: Enable/disable automatic cleanup and set frequency
   - **Cleanup Retention**: Days to keep files before automatic deletion
   - **Webhook Secret**: Secret key for API authentication

### Cleanup Configuration
- **Enable Auto Cleanup**: Toggle automatic cleanup on/off completely
- **Cleanup Frequency**: Choose from hourly, twice daily, daily, or weekly
- **Retention Period**: Set how many days to keep generated files
- **Manual Cleanup**: Always available regardless of auto-cleanup settings

### Default Settings
- Files per run: 100
- File size range: 0.001 MB (1KB) to 10 GB (10,240 MB)
- Auto cleanup: Enabled (daily)
- Cleanup retention: 7 days
- Webhook secret: Auto-generated

## Usage

### Admin Dashboard
The plugin provides a comprehensive admin interface at **Tools > Content Balloon**:

- **Configuration**: Set file generation parameters
- **Generate Files**: Start file generation with progress tracking
- **Status**: View current generation status and statistics
- **Cleanup**: Manual cleanup with dry-run option
- **Webhook Info**: API endpoint details and examples

### WP-CLI Commands

#### Generate Files
```bash
# Generate 500 files with 5-20 GB size range
wp content-balloon generate --count=500 --min-size=5120 --max-size=20480

# Generate 100 files with 1KB to 1MB size range
wp content-balloon generate --count=100 --min-size=0.001 --max-size=1

# Generate 1000 files with verbose progress
wp content-balloon generate --count=1000 --verbose
```

#### Check Status
```bash
wp content-balloon status
```

#### Cleanup Files
```bash
# Test cleanup (dry run)
wp content-balloon cleanup --dry-run

# Manual cleanup with confirmation
wp content-balloon cleanup

# Force cleanup without confirmation
wp content-balloon cleanup --force
```

### REST API Webhooks

#### Generate Files
```bash
curl -X POST https://yoursite.com/wp-json/content-balloon/v1/generate \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: YOUR_SECRET_KEY" \
  -d '{
    "file_count": 100,
    "max_file_size": 10240,
    "min_file_size": 0.001
  }'
```

#### Check Status
```bash
curl -X GET https://yoursite.com/wp-json/content-balloon/v1/status \
  -H "X-Webhook-Secret: YOUR_SECRET_KEY"
```

#### Cleanup Files
```bash
# Test cleanup
curl -X POST https://yoursite.com/wp-json/content-balloon/v1/cleanup \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: YOUR_SECRET_KEY" \
  -d '{"dry_run": true}'

# Actual cleanup
curl -X POST https://yoursite.com/wp-json/content-balloon/v1/cleanup \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: YOUR_SECRET_KEY"
```

## File Structure

Generated files are stored in your WordPress uploads directory with the following structure:

```
wp-content/uploads/
├── content-balloon-abc123/
│   ├── xyz789/
│   │   ├── random1.txt
│   │   ├── random2.txt
│   │   └── ...
│   └── def456/
│       ├── random3.txt
│       └── ...
└── content-balloon-ghi789/
    └── ...
```

- Each `content-balloon-*` directory contains multiple subdirectories
- Subdirectories have random names for organization
- Files are named with random strings and `.txt` extension
- File sizes are randomly distributed within your specified range

## Novel Sources

The plugin downloads from a curated list of work-appropriate, public domain books from Project Gutenberg:

- Pride and Prejudice
- Alice's Adventures in Wonderland
- Adventures of Huckleberry Finn
- The Adventures of Sherlock Holmes
- A Tale of Two Cities
- Great Expectations
- Dracula
- Frankenstein
- And many more...

## Performance Considerations

- **Memory Management**: Uses streaming and chunked processing for large files
- **Rate Limiting**: Includes small delays to prevent overwhelming the system
- **Background Processing**: File generation runs in the background
- **Progress Updates**: Real-time progress tracking without blocking

## Security Features

- **Nonce Verification**: All admin actions use WordPress nonces
- **Capability Checks**: Only users with `manage_options` capability can access
- **Secret Key Authentication**: Webhook endpoints require valid secret keys
- **Input Validation**: All user inputs are validated and sanitized

## Troubleshooting

### Common Issues

1. **Files Not Generating**
   - Check WordPress permissions
   - Verify upload directory is writable
   - Check error logs for specific errors

2. **Memory Issues**
   - Increase PHP memory limit
   - Reduce file count per run
   - Use smaller file sizes

3. **Download Failures**
   - Check internet connectivity
   - Verify Project Gutenberg is accessible
   - Check firewall/proxy settings

### Debug Mode
Enable WordPress debug mode to see detailed error messages:

```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## API Reference

### Endpoints

- `POST /wp-json/content-balloon/v1/generate` - Start file generation
- `GET /wp-json/content-balloon/v1/status` - Get current status
- `POST /wp-json/content-balloon/v1/cleanup` - Cleanup files

### Response Format
```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "files_created": 100,
    "total_size": 1048576
  }
}
```

## Development

### Hooks and Filters

The plugin provides several action hooks:

- `content_balloon_progress` - Fired during file generation
- `content_balloon_cleanup` - Fired during cleanup operations

### Extending the Plugin

You can extend the plugin by:

1. Adding new novel sources
2. Customizing file naming patterns
3. Implementing additional storage backends
4. Adding new cleanup strategies

## Changelog

### Version 1.0.0
- Initial release
- Core file generation functionality
- Admin dashboard interface
- WP-CLI integration
- REST API webhooks
- Automatic cleanup system

## Support

For support, feature requests, or bug reports:

1. Check the troubleshooting section above
2. Review WordPress error logs
3. Test with default settings
4. Create an issue on GitHub

## License

This plugin is licensed under the GPL v2 or later.

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## Credits

- **Project Gutenberg**: For providing free, public domain literature
- **WordPress Community**: For the excellent platform and documentation
- **Open Source Contributors**: For inspiration and best practices

---

**Note**: This plugin is designed for testing and development purposes. Use responsibly and ensure you have adequate storage space and backup systems in place.
