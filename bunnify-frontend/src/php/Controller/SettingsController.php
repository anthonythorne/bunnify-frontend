<?php
/**
 * Settings logic for Bunnify Frontend plugin.
 *
 * Handles admin settings page, options registration, and admin-only hooks.
 *
 * File Path: src/php/Controller/Settings.php
 *
 * @package BunnifyFrontend
 * @since   1.0.0
 *
 * Provides the admin interface and settings logic.
 */

namespace BunnifyFrontend\Controller;

use BunnifyFrontend\Base\Main\Controller;

/**
 * Class Settings
 *
 * Handles admin settings and menu registration.
 */
class SettingsController extends Controller {

	/**
	 * Initialize WordPress hooks for admin functionality.
	 */
	public function set_up() {
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_init', [ $this, 'init_settings' ] );
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'upload.php',
			'BunnyCDN',
			'BunnyCDN',
			'manage_options',
			'bunnify-frontend',
			[ $this, 'admin_page' ],
		);
	}

	/**
	 * Initialize settings.
	 */
	public function init_settings() {
		register_setting( 'bunnify_frontend_options', 'bunnify_enabled' );
		register_setting( 'bunnify_frontend_options', 'bunnify_hostname' );
		register_setting( 'bunnify_frontend_options', 'bunnify_debug_enabled' );
		register_setting( 'bunnify_frontend_options', 'bunnify_debug_refreshes' );
		register_setting( 'bunnify_frontend_options', 'bunnify_local_dev_mode' );

		// Categorized debug logging settings.
		register_setting( 'bunnify_frontend_options', 'bunnify_debug_url_transformation' );
		register_setting( 'bunnify_frontend_options', 'bunnify_debug_image_processing' );
		register_setting( 'bunnify_frontend_options', 'bunnify_debug_srcset_generation' );
		register_setting( 'bunnify_frontend_options', 'bunnify_debug_content_filtering' );
		register_setting( 'bunnify_frontend_options', 'bunnify_debug_local_dev_mode' );
		register_setting( 'bunnify_frontend_options', 'bunnify_debug_performance' );

		add_settings_section(
			'bunnify_frontend_settings_section',
			'BunnyCDN Configuration',
			[ $this, 'settings_section_callback' ],
			'bunnify-frontend',
		);

		add_settings_field(
			'bunnify_enabled_field',
			'Enable BunnyCDN',
			[ $this, 'enabled_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_settings_section',
		);

		add_settings_field(
			'bunnify_hostname_field',
			'BunnyCDN Hostname',
			[ $this, 'hostname_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_settings_section',
		);

		add_settings_field(
			'bunnify_local_dev_mode_field',
			'Local Development Mode',
			[ $this, 'local_dev_mode_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_settings_section',
		);

		// Debug logging section.
		add_settings_section(
			'bunnify_frontend_debug_section',
			'Debug Logging',
			[ $this, 'debug_section_callback' ],
			'bunnify-frontend',
		);

		add_settings_field(
			'bunnify_debug_enabled_field',
			'Enable Debug Logging',
			[ $this, 'debug_enabled_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_debug_section',
		);

		add_settings_field(
			'bunnify_debug_refreshes_field',
			'Debug Refreshes to Keep',
			[ $this, 'debug_refreshes_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_debug_section',
		);

		add_settings_field(
			'bunnify_debug_url_transformation_field',
			'URL Transformation',
			[ $this, 'debug_url_transformation_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_debug_section',
		);

		add_settings_field(
			'bunnify_debug_image_processing_field',
			'Image Processing',
			[ $this, 'debug_image_processing_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_debug_section',
		);

		add_settings_field(
			'bunnify_debug_srcset_generation_field',
			'Srcset Generation',
			[ $this, 'debug_srcset_generation_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_debug_section',
		);

		add_settings_field(
			'bunnify_debug_content_filtering_field',
			'Content Filtering',
			[ $this, 'debug_content_filtering_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_debug_section',
		);

		add_settings_field(
			'bunnify_debug_local_dev_mode_field',
			'Local Development Mode',
			[ $this, 'debug_local_dev_mode_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_debug_section',
		);

		add_settings_field(
			'bunnify_debug_performance_field',
			'Performance',
			[ $this, 'debug_performance_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_debug_section',
		);
	}

	/**
	 * Settings section callback.
	 */
	public function settings_section_callback() {
		echo '<p>Configure BunnyCDN settings. This plugin provides simplified image CDN functionality for your WordPress media.</p>';
	}

	/**
	 * Debug section callback.
	 */
	public function debug_section_callback() {
		echo '<p>Configure debug logging options to track image processing and performance.</p>';
	}

	/**
	 * Enabled field callback.
	 */
	public function enabled_field_callback() {
		// The hidden field makes an unticked checkbox store an explicit '0'
		// rather than the '' options.php writes for an absent checkbox, so a
		// deliberate disable is distinguishable from a legacy pre-switch save.
		// The checkbox reflects the EFFECTIVE state (is_enabled()), so legacy
		// ''/missing installs render checked and normalise to '1' on save.
		?>
		<input type="hidden" name="bunnify_enabled" value="0" />
		<input type="checkbox" name="bunnify_enabled" value="1" <?php checked( true, self::is_enabled(), true ); ?> />
		<p class="description">Enable BunnyCDN functionality for your media.</p>
		<?php
	}

	/**
	 * Hostname field callback.
	 */
	public function hostname_field_callback() {
		$hostname = get_option( 'bunnify_hostname' );
		?>
		<input type="text" name="bunnify_hostname" value="<?php echo isset( $hostname ) ? esc_attr( $hostname ) : ''; ?>"
			class="regular-text" />
		<p class="description">Your BunnyCDN hostname (e.g., cdn.example.com).</p>
		<?php
	}

	/**
	 * Debug enabled field callback.
	 */
	public function debug_enabled_field_callback() {
		$debug_enabled = get_option( 'bunnify_debug_enabled' );
		?>
		<input type="checkbox" name="bunnify_debug_enabled" value="1" <?php checked( 1, $debug_enabled, true ); ?> />
		<p class="description">Enable debug logging to track image processing. Logs are stored in wp-content/uploads/bunnify-debug.log.</p>
		<?php
	}

	/**
	 * Debug refreshes field callback.
	 */
	public function debug_refreshes_field_callback() {
		$debug_refreshes = get_option( 'bunnify_debug_refreshes', 10 );
		?>
		<input type="number" name="bunnify_debug_refreshes" value="<?php echo esc_attr( $debug_refreshes ); ?>" min="1" max="100" class="small-text" />
		<p class="description">Number of page refreshes to keep in the debug log (1-100). Each page refresh is marked with a separator.</p>
		<?php
	}

	/**
	 * Debug URL transformation field callback.
	 */
	public function debug_url_transformation_field_callback() {
		$debug_url_transformation = get_option( 'bunnify_debug_url_transformation', false );
		?>
		<input type="checkbox" name="bunnify_debug_url_transformation" value="1" <?php checked( 1, $debug_url_transformation, true ); ?> />
		<p class="description">Enable logging for URL transformation logic.</p>
		<?php
	}

	/**
	 * Debug image processing field callback.
	 */
	public function debug_image_processing_field_callback() {
		$debug_image_processing = get_option( 'bunnify_debug_image_processing', false );
		?>
		<input type="checkbox" name="bunnify_debug_image_processing" value="1" <?php checked( 1, $debug_image_processing, true ); ?> />
		<p class="description">Enable logging for image processing logic.</p>
		<?php
	}

	/**
	 * Debug srcset generation field callback.
	 */
	public function debug_srcset_generation_field_callback() {
		$debug_srcset_generation = get_option( 'bunnify_debug_srcset_generation', false );
		?>
		<input type="checkbox" name="bunnify_debug_srcset_generation" value="1" <?php checked( 1, $debug_srcset_generation, true ); ?> />
		<p class="description">Enable logging for srcset generation logic.</p>
		<?php
	}

	/**
	 * Debug content filtering field callback.
	 */
	public function debug_content_filtering_field_callback() {
		$debug_content_filtering = get_option( 'bunnify_debug_content_filtering', false );
		?>
		<input type="checkbox" name="bunnify_debug_content_filtering" value="1" <?php checked( 1, $debug_content_filtering, true ); ?> />
		<p class="description">Enable logging for content filtering logic.</p>
		<?php
	}

	/**
	 * Debug local development mode field callback.
	 */
	public function debug_local_dev_mode_field_callback() {
		$debug_local_dev_mode = get_option( 'bunnify_debug_local_dev_mode', false );
		?>
		<input type="checkbox" name="bunnify_debug_local_dev_mode" value="1" <?php checked( 1, $debug_local_dev_mode, true ); ?> />
		<p class="description">Enable logging specifically for local development mode checks.</p>
		<?php
	}

	/**
	 * Debug performance field callback.
	 */
	public function debug_performance_field_callback() {
		$debug_performance = get_option( 'bunnify_debug_performance', false );
		?>
		<input type="checkbox" name="bunnify_debug_performance" value="1" <?php checked( 1, $debug_performance, true ); ?> />
		<p class="description">Enable logging for performance tracking.</p>
		<?php
	}

	/**
	 * Check if debug logging is enabled for a specific category.
	 *
	 * @param string $category The debug category to check.
	 * @return bool True if debug logging is enabled for the category.
	 */
	public static function is_debug_enabled_for_category( string $category ): bool {
		// First check if debug logging is globally enabled.
		if ( ! (bool) get_option( 'bunnify_debug_enabled', false ) ) {
			return false;
		}

		// Then check if the specific category is enabled.
		$category_option = 'bunnify_debug_' . $category;
		return (bool) get_option( $category_option, false );
	}

	/**
	 * Get all enabled debug categories.
	 *
	 * @return array Array of enabled debug categories.
	 */
	public static function get_enabled_debug_categories(): array {
		$categories = [
			'url_transformation',
			'image_processing',
			'srcset_generation',
			'content_filtering',
			'local_dev_mode',
			'performance',
		];

		$enabled = [];
		foreach ( $categories as $category ) {
			if ( self::is_debug_enabled_for_category( $category ) ) {
				$enabled[] = $category;
			}
		}

		return $enabled;
	}

	/**
	 * Local development mode field callback.
	 */
	public function local_dev_mode_field_callback() {
		$local_dev_mode = get_option( 'bunnify_local_dev_mode', false );
		$environment    = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$auto_on        = 'production' !== $environment;
		?>
		<input type="checkbox" name="bunnify_local_dev_mode" value="1" <?php checked( 1, $local_dev_mode, true ); ?> <?php disabled( $auto_on ); ?> />
		<p class="description">
			Serve the local file when it exists and fall back to the CDN for missing images
			(so an install without synced uploads still shows every image).
			<?php if ( $auto_on ) : ?>
				<strong>Automatically enabled</strong> because the environment type is
				<code><?php echo esc_html( $environment ); ?></code> (not <code>production</code>).
			<?php else : ?>
				This environment reports as <code>production</code>, so it is off by default;
				tick to force it on here.
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Admin page.
	 */
	public function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WordPress core's own post-save redirect flag; no form data is processed.
		if ( isset( $_GET['settings-updated'] ) ) {
			add_settings_error( 'bunnify_frontend_messages', 'bunnify_frontend_message', __( 'BunnyCDN Settings Saved', 'bunnify-frontend' ), 'updated' );
		}

		settings_errors( 'bunnify_frontend_messages' );

		// Get log file info.
		$upload_dir   = wp_get_upload_dir();
		$log_file     = $upload_dir['basedir'] . '/bunnify-debug.log';
		$log_file_url = $upload_dir['baseurl'] . '/bunnify-debug.log';
		$log_exists   = file_exists( $log_file );
		$log_size     = $log_exists ? size_format( filesize( $log_file ) ) : '0 B';

		// Count page refreshes in log file.
		$page_refresh_count = 0;
		if ( $log_exists ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin log file, not a remote resource.
			$content = file_get_contents( $log_file );
			if ( ! empty( $content ) ) {
				$refresh_marker = str_repeat( '=', 80 );
				$sections       = explode( $refresh_marker, $content );
				foreach ( $sections as $section ) {
					if ( strpos( $section, 'PAGE REFRESH:' ) !== false ) {
						++$page_refresh_count;
					}
				}
			}
		}
		?>
		<div class="wrap">
			<h1>BunnyCDN Settings</h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'bunnify_frontend_options' );
				do_settings_sections( 'bunnify-frontend' );
				submit_button( 'Save Settings' );
				?>
			</form>

			<div class="card" style="width: 100%; max-width: unset;">
				<h2>Debug Log</h2>
				<?php if ( $this->is_debug_enabled() ) : ?>
					<p><strong>Debug mode is enabled.</strong> Log file: <code><?php echo esc_html( $log_file ); ?></code></p>
					<p><strong>Important:</strong> To generate debug logs, you must add <code>?bunnify_debug=1</code> to any page URL you want to debug.</p>
					<p><strong>Example:</strong> <code><?php echo esc_url( home_url() . '/?bunnify_debug=1' ); ?></code></p>
					<?php if ( $log_exists ) : ?>
						<p>Log file size: <?php echo esc_html( $log_size ); ?></p>
						<p>Page refreshes logged: <?php echo esc_html( $page_refresh_count ); ?> (keeping <?php echo esc_html( get_option( 'bunnify_debug_refreshes', 10 ) ); ?> most recent)</p>
						<p><a href="<?php echo esc_url( $log_file_url ); ?>" target="_blank" class="button">View Debug Log</a></p>
					<?php else : ?>
						<p>No log file found yet. Add <code>?bunnify_debug=1</code> to a page URL and refresh to generate logs.</p>
					<?php endif; ?>
				<?php else : ?>
					<p>Debug mode is disabled. Enable it above to start logging image processing.</p>
					<p><strong>Note:</strong> When enabled, you'll need to add <code>?bunnify_debug=1</code> to page URLs to generate logs.</p>
				<?php endif; ?>
			</div>

			<div class="card" style="width: 100%; max-width: unset;">
				<h2>Test BunnyCDN Configuration</h2>
				<p>Use these functions to test the BunnyCDN functionality:</p>
				<pre>
					<code>
						// Test URL transformation
						$original_url = 'https://www.example.com/wp-content/uploads/2024/01/test-image.jpg';
						$plugin = new BunnifyFrontend\CDN();
						$transformed_url = $plugin->cdn_url($original_url, ['width' => 300, 'height' => 200]);
						echo "Transformed: " . $transformed_url;

						// Test attachment processing
						$image_data = wp_get_attachment_image_src(123, 'medium');
						print_r($image_data); // For testing purposes only
					</code>
				</pre>
			</div>
		</div>
		<?php
	}

	/**
	 * Check if debug mode is enabled.
	 *
	 * @return bool
	 */
	private function is_debug_enabled() {
		return (bool) get_option( 'bunnify_debug_enabled', false );
	}

	/**
	 * Check if BunnyCDN rewriting is enabled (the `bunnify_enabled` master switch).
	 *
	 * The option predates this check, so two stored states must stay enabled:
	 * a missing option (settings never saved), and a stored '' — options.php
	 * writes '' for any whitelisted checkbox absent from the POST, so every
	 * pre-switch settings save (e.g. just entering the hostname) left ''
	 * behind while the checkbox was still inert; treating that as disabled
	 * would silently turn off rewriting on upgrade. Only an explicit '0',
	 * written by the hidden field that now accompanies the checkbox, disables.
	 *
	 * @return bool True if enabled.
	 */
	public static function is_enabled(): bool {
		$enabled = get_option( 'bunnify_enabled', null );

		if ( null === $enabled || '' === $enabled ) {
			return true;
		}

		return (bool) $enabled;
	}

	/**
	 * Check if local development mode is enabled.
	 *
	 * Local-dev mode means "serve the local file when it exists, otherwise fall
	 * back to the CDN" — so an install without synced uploads still shows every
	 * image (missing ones from the CDN) instead of broken thumbnails.
	 *
	 * It is enabled automatically on any non-production environment, using
	 * WordPress core's {@see wp_get_environment_type()} (set via the
	 * `WP_ENVIRONMENT_TYPE` constant or environment variable; defaults to
	 * `production`). No manual toggle is needed on local/staging. Resolution
	 * order:
	 *
	 * 1. The `bunnify_local_dev_mode_check` filter — return non-null to force
	 *    it on or off (highest priority; e.g. to disable on a local box that
	 *    does have every upload synced).
	 * 2. Automatic: on when the environment is not `production`.
	 * 3. The `bunnify_local_dev_mode` option — a manual force-on for a
	 *    production-typed environment (rare).
	 *
	 * @return bool True if local development mode is enabled.
	 */
	public static function is_local_dev_mode_enabled(): bool {
		// Explicit override wins (non-null return forces on or off).
		$custom_check = apply_filters( 'bunnify_local_dev_mode_check', null );
		if ( null !== $custom_check ) {
			return (bool) $custom_check;
		}

		// Automatic: any non-production environment prefers local files.
		if ( function_exists( 'wp_get_environment_type' ) && 'production' !== wp_get_environment_type() ) {
			return true;
		}

		// Manual force-on for a production-typed environment.
		return (bool) get_option( 'bunnify_local_dev_mode', false );
	}
}
