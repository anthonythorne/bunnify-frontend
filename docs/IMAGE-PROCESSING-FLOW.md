---

## Architecture Overview

### Core Components

The plugin uses a trait-based architecture to centralize common functionality and eliminate code duplication:

#### DebugTrait
Provides centralized debug logging, local development mode checks, and aspect ratio calculations:

```php
// Debug logging with context and category support
$this->debug_log( $message, $context, $category );

// Local development mode detection
if ( $this->is_local_dev_mode_enabled() ) {
    // Handle local development logic
}

// Aspect ratio calculations for responsive images
$dimensions = $this->calculate_aspect_ratio_dimensions( $attachment_id, $width, $height );
```

#### CachingTrait
Standardizes all caching operations with smart TTL based on attachment age:

```php
// Standardized cache operations
$this->get_cached_value( $key, $group );
$this->set_cached_value( $key, $value, $ttl, $group );

// Cached filesystem operations
$this->cached_file_exists( $file_path );
$this->cached_is_readable( $file_path );

// Smart TTL based on attachment age
$ttl = $this->get_attachment_cache_ttl( $attachment_id );
```

### Performance Optimizations

#### Smart Caching Strategy
- **Attachment ID Lookup**: Cached with smart TTL (7-120 days based on attachment age)
- **Metadata Caching**: Reduces repeated `wp_get_attachment_metadata()` calls
- **Filesystem Caching**: Caches expensive `file_exists` and `is_readable` operations
- **Original URL Caching**: Caches WordPress attachment URL lookups

#### Cache TTL Logic
```php
// Very old attachments (>1 year): 120 days
// Old attachments (>4 months): 60 days  
// Moderately old attachments (>1 month): 30 days
// Recent attachments: 7 days
```

#### Memory Efficiency
- **Age-based TTL**: Prevents cache bloat while maximizing hit rates
- **Standardized Groups**: All components use `bunnify_frontend` cache group
- **Automatic Cleanup**: Cache expires based on attachment age

### Image Processing Features

#### True Original URL Resolution
The plugin intelligently handles WordPress-generated image suffixes:

```php
// Handles -scaled, -full, -medium, -large, -thumbnail suffixes
// Checks filesystem for non-scaled versions when metadata contains -scaled
// Falls back to metadata version if non-scaled version doesn't exist
// Local development mode bypasses filesystem checks
```

#### Aspect Ratio Preservation
Maintains correct aspect ratios for responsive images:

```php
// Uses original image dimensions from metadata
// Calculates proportional heights/widths for CDN parameters
// Ensures consistent aspect ratios across all image sizes
```

#### Content Image Processing
Processes images in post content, widgets, and galleries:

```php
// Uses WP_HTML_Tag_Processor for safe HTML manipulation
// Extracts dimensions from img attributes
// Transforms both src and srcset attributes
// Maintains original aspect ratios
```

### Development Features

#### Debug Logging System
Comprehensive logging for troubleshooting:

```php
// Enable debug logging in admin settings
// Add ?bunnify_debug=1 to any page URL
// View logs at wp-content/uploads/bunnify-debug.log
// Automatic log file cleanup prevents unlimited growth
```

#### Local Development Mode
Bypasses CDN processing for local development:

```php
// Checks if images exist locally on filesystem
// Skips CDN processing when images are available locally
// Custom override via bunnify_local_dev_mode_check filter
```

### Hook System

#### WordPress Core Hooks
- `image_downsize` - Processes image size requests
- `wp_get_attachment_image_src` - Processes attachment image sources
- `wp_get_attachment_image` - Processes attachment image HTML
- `wp_calculate_image_srcset` - Processes responsive image srcset
- `the_content` - Processes post content for images
- `render_block` - Processes block content for images

#### Custom Filters
- `bunnify_url` - Filters final CDN URL
- `bunnify_skip_for_url` - Allows specific URLs to bypass processing
- `bunnify_validate_image_url` - Filters URL validation results
- `bunnify_local_dev_mode_check` - Custom local development mode logic

### Error Handling

#### Graceful Degradation
- **Fallback URLs**: Returns original URL if CDN transformation fails
- **Error Logging**: Comprehensive error logging for debugging
- **Exception Handling**: Try-catch blocks prevent fatal errors
- **Validation**: Input validation prevents processing errors

#### Security Measures
- **Input Sanitization**: All user inputs are properly sanitized
- **Output Escaping**: All output uses appropriate escaping functions
- **Capability Checks**: Admin functions require proper capabilities
- **Nonce Protection**: Where needed, nonces are properly verified

---

*This documentation covers the complete image processing flow for the BunnifyFrontend plugin. For specific implementation details, refer to the individual filter methods in `ImageController.php`.* 