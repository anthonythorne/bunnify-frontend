# Bunnify Frontend

A lightweight, frontend-only WordPress plugin for BunnyCDN image delivery. This plugin is designed for sites where BunnyCDN pulls directly from the local `wp-content/uploads` directory, and all image transformation is handled on the frontend.

## What Makes This Plugin Different?

- **Frontend-Only**: All logic is for frontend image delivery and transformation. There is no upload, sync, or storage management—BunnyCDN pulls from the local uploads directory.
- **No S3/Remote Storage**: The plugin does not handle or care about remote storage. It assumes all images are in `wp-content/uploads` and BunnyCDN is configured to pull from there.
- **Full Image Preference**: On the frontend, the plugin always references the original/full image and uses CDN parameters for resizing, rather than using WordPress-generated image sizes. This ensures the highest quality and flexibility for BunnyCDN's image processing.
- **Admin**: The only admin functionality is a settings/options page for configuring the CDN hostname and enabling/disabling the plugin. No other admin-side manipulation is performed.
- **Performance**: By avoiding unnecessary complexity and focusing only on frontend delivery, the plugin is lightweight and fast.

## Use Case

This plugin is ideal for:
- Sites already using BunnyCDN to serve images from `wp-content/uploads`.
- Installations that want to optimize frontend image delivery without changing upload/storage workflows.
- Developers who want a simple, maintainable, and high-performance BunnyCDN integration for WordPress.

## Features
- **URL Transformation**: Converts WordPress image URLs to use your BunnyCDN hostname.
- **Content Filtering**: Processes images in post content, widgets, and galleries.
- **Attachment Processing**: Handles `wp_get_attachment_image_src()` and `image_downsize()` filters.
- **Responsive Images**: Processes `srcset` arrays for responsive images.
- **Lazy Load Support**: Compatible with lazy loading plugins.
- **DNS Prefetch**: Adds DNS prefetch hints for your BunnyCDN domain.
- **Admin Interface**: Simple settings page to configure hostname and enable/disable.
- **Local Development Mode**: Bypass CDN processing for locally existing images during development.
- **Debug Logging**: Comprehensive logging system for troubleshooting (see [Debug Logging](docs/LOGGING.md)).
- **Performance Optimized**: Efficient content processing with minimal overhead.

## Installation

1. Download the plugin files
2. Upload the `bunnify-frontend` folder to your `wp-content/plugins` directory
3. Activate the plugin through the WordPress admin
4. Go to **Settings → Bunnify Frontend** in the admin menu
5. Configure your BunnyCDN hostname
6. Enable the plugin

## Requirements
- WordPress 6.8 or higher
- PHP 8.1 or higher
- BunnyCDN account and configured hostname

## Configuration

### Basic Settings
- **Hostname**: Your BunnyCDN hostname (e.g., `cdn.yoursite.com`)
- **Enabled**: Checkbox to enable/disable the functionality

### Development Settings
- **Local Development Mode**: Serves the local file when it exists and falls back to the CDN for missing images. Auto-enabled on `local`/`development` environments (via `wp_get_environment_type()`); `staging` and `production` are off by default.
- **Debug Logging**: Enable per-category logging for troubleshooting. Logging runs automatically for the enabled categories when a front-end page loads.
- **Log Lines to Keep**: Number of log lines retained before the oldest are trimmed (1-100).

**Note:** Logs are written to `wp-content/uploads/bunnify-logs/debug.log` (a hardened, non-browsable directory).

## Local Development Mode

The plugin includes a local development mode that helps developers work with local images without CDN processing:

- **Automatic Detection**: Checks if images exist locally on the filesystem
- **Complete Bypass**: When enabled and image exists locally, all CDN processing is skipped
- **Original WordPress Behavior**: Maintains standard WordPress image handling locally
- **Custom Override**: Use the `bunnify_local_dev_mode_check` filter for environment-specific logic

### Usage Examples

```php
// Always enable local dev mode
add_filter( 'bunnify_local_dev_mode_check', '__return_true' );

// Enable based on environment
add_filter( 'bunnify_local_dev_mode_check', function() {
    return defined( 'WP_ENV' ) && WP_ENV === 'development';
});
```

## Migration from Original Bunnify
- No storage or upload management: this plugin is for frontend delivery only.
- Settings are compatible, but the codebase is simpler and more focused.
- Always uses the original image for BunnyCDN transformation.

## Documentation

- **[Image Processing Flow](docs/IMAGE-PROCESSING-FLOW.md)**: Complete guide to image processing, filters, and CDN transformation
- **[Debug Logging](docs/LOGGING.md)**: Comprehensive guide to the debug logging system
- **[Hooks and Filters](docs/HOOKS.md)**: Complete list of available WordPress hooks and filters
- **[Troubleshooting](docs/TROUBLESHOOTING.md)**: Common issues and solutions

## Support
- Check the troubleshooting section in the context readme
- Review the hooks and filters documentation
- Test with a simple URL transformation first
- For debugging issues, see [Debug Logging Documentation](docs/LOGGING.md)

## License
GPL2+ - Same as WordPress

## Credits
- Built for WordPress
- Compatible with BunnyCDN
- Inspired by the original Bunnify plugin 