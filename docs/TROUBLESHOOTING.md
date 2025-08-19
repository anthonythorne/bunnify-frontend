# Troubleshooting

This document covers common issues and solutions for the Bunnify Frontend plugin.

## Common Issues

### Images Not Loading from CDN

**Symptoms:**
- Images show 404 errors
- CDN URLs are generated but images don't load
- Original WordPress URLs still showing

**Possible Causes:**
1. **BunnyCDN not configured correctly**
   - Check your BunnyCDN hostname in settings
   - Verify BunnyCDN is pulling from your `wp-content/uploads` directory
   - Ensure BunnyCDN is active and working

2. **Plugin not enabled**
   - Check if the plugin is enabled in settings
   - Verify the plugin is activated in WordPress

3. **Image paths incorrect**
   - Ensure images are in the standard WordPress uploads directory
   - Check that BunnyCDN is configured to pull from the correct path

**Solutions:**
1. Test with a simple image URL first
2. Check BunnyCDN dashboard for any errors
3. Verify the CDN hostname is correct
4. Enable debug logging to see what URLs are being generated

### Images Still Using WordPress URLs

**Symptoms:**
- Images still show local WordPress URLs
- No CDN transformation happening

**Possible Causes:**
1. **Plugin disabled**
   - Check plugin settings
   - Verify plugin is activated

2. **Local development mode enabled**
   - Check if local development mode is enabled
   - Verify images exist locally on filesystem

3. **Filters preventing processing**
   - Check if other plugins are interfering
   - Look for `bunnify_skip_for_url` filters

**Solutions:**
1. Disable local development mode if not needed
2. Check debug logs for processing information
3. Verify no conflicting plugins

### Admin Images Not Processing

**Symptoms:**
- Images in admin area not using CDN
- Frontend works but admin doesn't

**Cause:**
- Admin processing is disabled by default for performance

**Solutions:**
1. Enable admin processing if needed:
   ```php
   add_filter( 'bunnify_admin_allow_image_downsize', '__return_true' );
   ```
2. Only enable for specific admin pages if needed

### Srcset Issues

**Symptoms:**
- Responsive images not working correctly
- Same URL repeated in srcset
- Missing image sizes

**Possible Causes:**
1. **Local development mode enabled**
   - Srcset will show original URLs when local dev mode is active
   - This is expected behavior

2. **Image metadata missing**
   - WordPress needs image dimensions for srcset generation
   - Regenerate thumbnails if needed

3. **Filter priority conflicts**
   - Other plugins may interfere with srcset processing
   - Check for conflicting image processing plugins

**Solutions:**
1. Disable local development mode for production testing
2. Regenerate image thumbnails using a plugin like "Regenerate Thumbnails"
3. Check image metadata in WordPress admin
4. Test with other plugins disabled to identify conflicts

### Performance Issues

**Symptoms:**
- Slow page loading
- High server load
- Timeout errors

**Possible Causes:**
1. **Too many images being processed**
   - Large galleries or image-heavy pages
   - Complex content with many images

2. **Debug logging enabled**
   - Debug logging can impact performance
   - File I/O operations

**Solutions:**
1. Disable debug logging in production
2. Consider caching solutions
3. Optimize image sizes before upload
4. Use lazy loading plugins

### Local Development Mode Not Working

**Symptoms:**
- Images still going through CDN in local development
- Local development mode enabled but not bypassing

**Possible Causes:**
1. **Images don't exist locally**
   - Check if images are actually in the uploads directory
   - Verify file permissions

2. **Custom filter overriding**
   - Check for `bunnify_local_dev_mode_check` filters
   - Verify environment variables

**Solutions:**
1. Check if images exist in `wp-content/uploads/`
2. Verify local development mode is enabled in settings
3. Check debug logs for bypass information
4. Test with a simple local image

## Debug Steps

### 1. Enable Debug Logging
1. Go to **Settings → Bunnify Frontend**
2. Enable "Debug logging"
3. Set "Debug refreshes to keep" to 10
4. Save settings

### 2. Test with Debug Parameter
1. Add `?bunnify_debug=1` to any page URL
2. Refresh the page
3. Check the debug log at `wp-content/uploads/bunnify-debug.log`

### 3. Check Debug Log
1. Go to **Settings → Bunnify Frontend**
2. Click "View Debug Log"
3. Look for processing information
4. Check for any error messages

### 4. Test Simple URL
1. Create a simple test page with one image
2. Check if the image URL is transformed
3. Verify the CDN URL works in browser

## Performance Optimization

### 1. Disable Debug Logging in Production
- Debug logging adds overhead
- Only enable when troubleshooting

### 2. Use Appropriate Image Sizes
- Upload images at the size you need
- Avoid unnecessarily large images

### 3. Consider Caching
- Use page caching plugins
- Consider object caching for WordPress

### 4. Optimize BunnyCDN Settings
- Configure appropriate cache headers
- Use BunnyCDN's optimization features

## Migration from Original Bunnify

### Settings Migration
- Hostname setting should be compatible
- Enable/disable setting should work the same

### Code Changes
- This plugin is frontend-only
- No upload or storage management
- Different hook priorities may apply

### Testing Checklist
1. Verify all images load correctly
2. Check responsive images work
3. Test admin functionality
4. Verify performance is acceptable

## Getting Help

### Before Asking for Help
1. Check this troubleshooting guide
2. Enable debug logging and check logs
3. Test with a simple setup
4. Verify BunnyCDN is working

### Information to Provide
1. WordPress version
2. PHP version
3. Plugin version
4. Debug log contents
5. BunnyCDN configuration
6. Steps to reproduce the issue

### Support Resources
- [Debug Logging Documentation](LOGGING.md)
- [Hooks and Filters Documentation](HOOKS.md)
- WordPress.org plugin support forums
- BunnyCDN documentation 