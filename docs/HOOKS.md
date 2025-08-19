# Hooks and Filters

This document lists all WordPress hooks and filters used by the Bunnify Frontend plugin.

## WordPress Core Hooks Used

### Actions
- `plugins_loaded` - Initializes the plugin application
- `admin_menu` - Adds admin menu items
- `admin_init` - Registers settings and fields

### Filters
- `wp_resource_hints` - Adds DNS prefetch hints
- `image_downsize` - Processes image size requests
- `wp_get_attachment_image_src` - Processes attachment image sources
- `wp_get_attachment_image` - Processes attachment image HTML
- `wp_calculate_image_srcset` - Processes responsive image srcset
- `wp_calculate_image_sizes` - Processes responsive image sizes
- `the_content` - Processes post content for images
- `widget_text` - Processes widget content for images
- `get_post_galleries` - Processes gallery content for images
- `widget_media_image_instance` - Processes media widget images
- `render_block` - Processes block content for images
- `render_block_core/gallery` - Processes gallery blocks specifically

## Custom Actions Provided

### `bunnify_processing_attachment_image`
Fired when processing an attachment image in the `filter_attachment_image` method.

```php
add_action( 'bunnify_processing_attachment_image', function( $attachment_id, $size, $html ) {
    // Custom logic when processing attachment images
}, 10, 3 );
```

## Custom Filters Provided

### `bunnify_url`
Filters the final CDN URL before it's returned.

```php
add_filter( 'bunnify_url', function( $cdn_url, $args, $scheme ) {
    // Modify the CDN URL
    return $cdn_url;
}, 10, 3 );
```

### `bunnify_pre_image_url`
Filters the image URL before processing.

```php
add_filter( 'bunnify_pre_image_url', function( $image_url, $args, $scheme ) {
    // Modify the image URL before processing
    return $image_url;
}, 10, 3 );
```

### `bunnify_pre_args`
Filters the CDN arguments before processing.

```php
add_filter( 'bunnify_pre_args', function( $args, $image_url, $scheme ) {
    // Modify the CDN arguments
    return $args;
}, 10, 3 );
```

### `bunnify_post_image_url`
Filters the final CDN URL after processing.

```php
add_filter( 'bunnify_post_image_url', function( $cdn_url, $image_url, $args, $scheme ) {
    // Modify the final CDN URL
    return $cdn_url;
}, 10, 4 );
```

### `bunnify_skip_for_url`
Allows specific URLs to bypass CDN processing.

```php
add_filter( 'bunnify_skip_for_url', function( $skip, $image_url, $args, $scheme ) {
    // Return true to skip processing for this URL
    return strpos( $image_url, 'skip-this-image' ) !== false;
}, 10, 4 );
```

### `bunnify_skip_content_processing`
Allows content processing to be skipped entirely.

```php
add_filter( 'bunnify_skip_content_processing', function( $skip, $content ) {
    // Return true to skip content processing
    return $skip;
}, 10, 2 );
```

### `bunnify_replace_attachment_srcs`
Controls whether attachment sources should be replaced.

```php
add_filter( 'bunnify_replace_attachment_srcs', function( $replace ) {
    // Return false to prevent attachment source replacement
    return $replace;
}, 10, 1 );
```

### `bunnify_admin_allow_image_downsize`
Allows image downsize processing in admin area.

```php
add_filter( 'bunnify_admin_allow_image_downsize', function( $allow, $context ) {
    // Return true to allow processing in admin
    return $allow;
}, 10, 2 );
```

### `bunnify_override_image_downsize`
Allows complete override of image downsize processing.

```php
add_filter( 'bunnify_override_image_downsize', function( $override, $context ) {
    // Return true to override image downsize processing
    return $override;
}, 10, 2 );
```

### `bunnify_image_downsize_string`
Filters CDN arguments for string-based image sizes.

```php
add_filter( 'bunnify_image_downsize_string', function( $cdn_args, $context ) {
    // Modify CDN arguments for string-based sizes
    return $cdn_args;
}, 10, 2 );
```

### `bunnify_image_downsize_array`
Filters CDN arguments for array-based image sizes.

```php
add_filter( 'bunnify_image_downsize_array', function( $cdn_args, $context ) {
    // Modify CDN arguments for array-based sizes
    return $cdn_args;
}, 10, 2 );
```

### `bunnify_admin_allow_attachment_srcs`
Allows attachment sources processing in admin area.

```php
add_filter( 'bunnify_admin_allow_attachment_srcs', function( $allow, $context ) {
    // Return true to allow processing in admin
    return $allow;
}, 10, 2 );
```

### `bunnify_override_attachment_srcs`
Allows complete override of attachment sources processing.

```php
add_filter( 'bunnify_override_attachment_srcs', function( $override, $context ) {
    // Return true to override attachment sources processing
    return $override;
}, 10, 2 );
```

### `bunnify_validate_image_url`
Filters image URL validation results.

```php
add_filter( 'bunnify_validate_image_url', function( $is_valid, $url, $parsed_url ) {
    // Modify URL validation logic
    return $is_valid;
}, 10, 3 );
```

### `bunnify_any_extension_for_domain`
Allows any file extension to be processed for specific domains.

```php
add_filter( 'bunnify_any_extension_for_domain', function( $allow, $domain ) {
    // Return true to allow any extension for this domain
    return $allow;
}, 10, 2 );
```

### `bunnify_local_dev_mode_check`
Allows custom local development mode checks.

```php
add_filter( 'bunnify_local_dev_mode_check', function( $custom_check ) {
    // Return custom local development mode logic
    return $custom_check;
}, 10, 1 );
```

## Hook Context Objects

Many hooks receive context objects with relevant data:

### Image Processing Context
```php
$context = [
    'image' => $image_data,
    'attachment_id' => $attachment_id,
    'size' => $size,
    'icon' => $icon, // For attachment sources
];
```

### URL Processing Context
```php
$context = [
    'original_url' => $original_url,
    'attachment_id' => $attachment_id,
    'size' => $size,
];
```

## Usage Examples

### Skip Processing for Specific URLs
```php
add_filter( 'bunnify_skip_for_url', function( $skip, $image_url, $args, $scheme ) {
    // Skip processing for external images
    if ( strpos( $image_url, 'external-domain.com' ) !== false ) {
        return true;
    }
    return $skip;
}, 10, 4 );
```

### Modify CDN Arguments
```php
add_filter( 'bunnify_pre_args', function( $args, $image_url, $scheme ) {
    // Add quality parameter for all images
    $args['quality'] = 85;
    return $args;
}, 10, 3 );
```

### Custom URL Validation
```php
add_filter( 'bunnify_validate_image_url', function( $is_valid, $url, $parsed_url ) {
    // Allow custom file extensions for specific domains
    if ( $parsed_url['host'] === 'my-custom-domain.com' ) {
        return true;
    }
    return $is_valid;
}, 10, 3 );
```

### Enable Admin Processing
```php
// Enable image processing in admin area
add_filter( 'bunnify_admin_allow_image_downsize', '__return_true' );
add_filter( 'bunnify_admin_allow_attachment_srcs', '__return_true' );
```

### Custom Local Development Mode
```php
add_filter( 'bunnify_local_dev_mode_check', function() {
    // Enable local dev mode based on environment
    return defined( 'WP_ENV' ) && WP_ENV === 'development';
});
``` 