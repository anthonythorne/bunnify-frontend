# Debug Logging

This document explains the debug logging functionality in the Bunnify Frontend plugin.

## Overview

The plugin includes a comprehensive debug logging system that helps developers troubleshoot CDN URL generation and image processing. The logging system is designed to be secure, efficient, and easy to use.

## Security Controls

Debug logging is protected by multiple security layers:

### 1. User Authentication
- **Requirement**: User must be logged in
- **Capability**: User must have `manage_options` capability (administrator)
- **Purpose**: Prevents unauthorized access to debug information

### 2. Query Parameter Requirement
- **Requirement**: URL must include `?bunnify_debug=1`
- **Example**: `https://yoursite.com/page?bunnify_debug=1`
- **Purpose**: Prevents accidental logging and reduces log file size

### 3. Admin-Only Settings
- **Location**: WordPress Admin → Settings → Bunnify Frontend
- **Control**: Enable/disable debug logging
- **Configuration**: Set number of page loads to retain

## How It Works

### Page Load Tracking
The system tracks actual page refreshes, not just log entries:

1. **Page Refresh Marker**: Each page load adds a marker:
   ```
   ================================================================================
   [2025-08-03 20:15:30] ===== PAGE REFRESH: front-page - / =====
   ================================================================================
   ```

2. **Log Entries**: All debug messages within a page load are grouped under the marker:
   ```
   [2025-08-03 20:15:30] [front-page] [/] [cdn_url] cdn_url called with: https://...
   [2025-08-03 20:15:30] [front-page] [/] [cdn_url] cdn_url returning: https://...
   ```

3. **Page Types**: The system identifies different page types:
   - `front-page`: Front page
   - `home`: Blog home page
   - `single`: Single post
   - `page`: Static page
   - `unknown`: Other page types

### Automatic Cleanup
The system automatically manages log file size:

1. **Page Count**: Keeps only the last N page loads (default: 10)
2. **Configuration**: Set via `bunnify_debug_refreshes` option
3. **Cleanup Process**:
   - Counts actual page refreshes by markers
   - Removes oldest page loads when limit exceeded
   - Reconstructs log file with only recent data

## Usage Instructions

### 1. Enable Debug Logging
1. Go to **WordPress Admin** → **Settings** → **Bunnify Frontend**
2. Check **"Enable debug logging"**
3. Set **"Debug refreshes to keep"** (default: 10)
4. Click **"Save Changes"**

### 2. Trigger Debug Logging
Add the debug parameter to any URL:
```
https://yoursite.com/page?bunnify_debug=1
```

### 3. View Debug Logs
The log file is located at:
```
wp-content/uploads/bunnify-debug.log
```

### 4. Access via Admin
1. Go to **WordPress Admin** → **Settings** → **Bunnify Frontend**
2. Scroll to the **"Debug Log"** section
3. Click **"View Debug Log"** to open the log file

## Log File Format

### Page Refresh Marker
```
================================================================================
[2025-08-03 20:15:30] ===== PAGE REFRESH: front-page - / =====
================================================================================
```

### Log Entry Format
```
[timestamp] [page-type] [url] [context] message
```

**Example**:
```
[2025-08-03 20:15:30] [front-page] [/] [cdn_url] cdn_url called with: https://yoursite.com/wp-content/uploads/image.jpg | args: Array ( [width] => 512 ) | scheme: 
[2025-08-03 20:15:30] [front-page] [/] [cdn_url] cdn_url returning: https://cdn.yoursite.com/wp-content/uploads/image.jpg?width=512&height=288
```

## Context Types

The logging system uses different contexts to categorize messages:

- `cdn_url`: CDN URL generation
- `transform_url`: URL transformation process
- `filter_image_downsize`: Image downsize filtering
- `filter_attachment_img_srcs`: Attachment image source filtering
- `filter_srcset_array`: Srcset array filtering
- `filter_srcset_meta`: Srcset metadata processing
- `filter_attachment_image`: HTML post-processing
- `url_transformation`: URL validation and transformation
- `srcset_generation`: Srcset creation and modification
- `image_processing`: Core image processing steps
- `local_dev_mode`: Local development mode detection

## Configuration Options

### WordPress Options
- `bunnify_debug_enabled`: Enable/disable debug logging (boolean)
- `bunnify_debug_refreshes`: Number of page loads to retain (integer, default: 10)

### File Location
- **Log File**: `wp-content/uploads/bunnify-debug.log`
- **Permissions**: Should be writable by web server
- **Size Management**: Automatic cleanup prevents unlimited growth

## Troubleshooting

### Debug Logging Not Working
1. **Check User Permissions**: Ensure you're logged in as administrator
2. **Verify URL Parameter**: Add `?bunnify_debug=1` to the URL
3. **Check Settings**: Ensure debug logging is enabled in admin
4. **File Permissions**: Verify log file is writable

### Log File Too Large
1. **Reduce Page Count**: Lower the "Debug refreshes to keep" setting
2. **Manual Cleanup**: Delete the log file to start fresh
3. **Disable Logging**: Turn off debug logging when not needed

### No Log Entries
1. **Check CDN Configuration**: Ensure BunnyCDN hostname is set
2. **Verify Image Processing**: Ensure images are being processed
3. **Check URL Parameter**: Confirm `?bunnify_debug=1` is in URL

## Best Practices

### Development
- Enable debug logging only when troubleshooting
- Use specific page URLs to isolate issues
- Check log file regularly to prevent size issues

### Production
- Disable debug logging in production environments
- Monitor log file size if logging is needed
- Use query parameter to limit logging scope

### Security
- Never leave debug logging enabled for public access
- Regularly review log files for sensitive information
- Use admin-only access controls

## Example Debug Session

1. **Enable logging** in admin settings
2. **Visit page** with `?bunnify_debug=1`
3. **Check log file** for entries
4. **Analyze output** for CDN URL generation
5. **Disable logging** when finished

This provides a complete audit trail of how the plugin processes images and generates CDN URLs. 