<?php
/**
 * Settings logic for Bunnify Frontend plugin.
 *
 * Handles admin settings page, options registration, and admin-only hooks.
 *
 * File Path: src/php/Controller/SettingsController.php
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
	 * Debug log path relative to the uploads directory.
	 *
	 * Kept in sync with DebugTrait (the writer) and uninstall.php (the cleaner).
	 */
	private const LOG_SUBPATH = 'bunnify-logs/debug.log';

	/**
	 * Environment types on which local-dev mode auto-enables.
	 *
	 * Development-class only: `staging` is deliberately excluded so a staging
	 * box that mirrors production still exercises the CDN (rather than serving
	 * origin files it happens to have on disk).
	 */
	private const LOCAL_DEV_ENVIRONMENTS = array( 'local', 'development' );

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
			__( 'BunnyCDN', 'bunnify-frontend' ),
			__( 'BunnyCDN', 'bunnify-frontend' ),
			'manage_options',
			'bunnify-frontend',
			[ $this, 'admin_page' ],
		);
	}

	/**
	 * Initialize settings.
	 */
	public function init_settings() {
		$checkbox = array(
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
		);

		register_setting( 'bunnify_frontend_options', 'bunnify_enabled', $checkbox );
		register_setting(
			'bunnify_frontend_options',
			'bunnify_hostname',
			array(
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_hostname' ],
				'default'           => '',
			)
		);
		register_setting(
			'bunnify_frontend_options',
			'bunnify_default_quality',
			array(
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_quality' ],
				'default'           => '',
			)
		);
		register_setting(
			'bunnify_frontend_options',
			'bunnify_format',
			array(
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_format' ],
				'default'           => '',
			)
		);
		register_setting(
			'bunnify_frontend_options',
			'bunnify_debug_refreshes',
			array(
				'type'              => 'integer',
				'sanitize_callback' => [ $this, 'sanitize_refreshes' ],
				'default'           => 10,
			)
		);
		register_setting( 'bunnify_frontend_options', 'bunnify_emit_dimensions', $checkbox );
		register_setting( 'bunnify_frontend_options', 'bunnify_lcp_optimize', $checkbox );
		register_setting( 'bunnify_frontend_options', 'bunnify_debug_enabled', $checkbox );
		register_setting( 'bunnify_frontend_options', 'bunnify_local_dev_mode', $checkbox );

		// Categorized debug logging settings.
		register_setting( 'bunnify_frontend_options', 'bunnify_debug_url_transformation', $checkbox );
		register_setting( 'bunnify_frontend_options', 'bunnify_debug_image_processing', $checkbox );
		register_setting( 'bunnify_frontend_options', 'bunnify_debug_srcset_generation', $checkbox );
		register_setting( 'bunnify_frontend_options', 'bunnify_debug_content_filtering', $checkbox );
		register_setting( 'bunnify_frontend_options', 'bunnify_debug_local_dev_mode', $checkbox );
		register_setting( 'bunnify_frontend_options', 'bunnify_debug_performance', $checkbox );

		add_settings_section(
			'bunnify_frontend_settings_section',
			__( 'BunnyCDN Configuration', 'bunnify-frontend' ),
			[ $this, 'settings_section_callback' ],
			'bunnify-frontend',
		);

		add_settings_field(
			'bunnify_enabled_field',
			__( 'Enable BunnyCDN', 'bunnify-frontend' ),
			[ $this, 'enabled_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_settings_section',
		);

		add_settings_field(
			'bunnify_hostname_field',
			__( 'BunnyCDN Hostname', 'bunnify-frontend' ),
			[ $this, 'hostname_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_settings_section',
		);

		add_settings_field(
			'bunnify_default_quality_field',
			__( 'Image Quality', 'bunnify-frontend' ),
			[ $this, 'default_quality_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_settings_section',
		);

		add_settings_field(
			'bunnify_format_field',
			__( 'Image Format', 'bunnify-frontend' ),
			[ $this, 'format_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_settings_section',
		);

		add_settings_field(
			'bunnify_emit_dimensions_field',
			__( 'Add Image Dimensions (CLS)', 'bunnify-frontend' ),
			[ $this, 'emit_dimensions_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_settings_section',
		);

		add_settings_field(
			'bunnify_lcp_optimize_field',
			__( 'Prioritise LCP Image', 'bunnify-frontend' ),
			[ $this, 'lcp_optimize_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_settings_section',
		);

		add_settings_field(
			'bunnify_local_dev_mode_field',
			__( 'Local Development Mode', 'bunnify-frontend' ),
			[ $this, 'local_dev_mode_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_settings_section',
		);

		// Debug logging section.
		add_settings_section(
			'bunnify_frontend_debug_section',
			__( 'Debug Logging', 'bunnify-frontend' ),
			[ $this, 'debug_section_callback' ],
			'bunnify-frontend',
		);

		add_settings_field(
			'bunnify_debug_enabled_field',
			__( 'Enable Debug Logging', 'bunnify-frontend' ),
			[ $this, 'debug_enabled_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_debug_section',
		);

		add_settings_field(
			'bunnify_debug_refreshes_field',
			__( 'Log Lines to Keep', 'bunnify-frontend' ),
			[ $this, 'debug_refreshes_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_debug_section',
		);

		add_settings_field(
			'bunnify_debug_url_transformation_field',
			__( 'URL Transformation', 'bunnify-frontend' ),
			[ $this, 'debug_url_transformation_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_debug_section',
		);

		add_settings_field(
			'bunnify_debug_image_processing_field',
			__( 'Image Processing', 'bunnify-frontend' ),
			[ $this, 'debug_image_processing_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_debug_section',
		);

		add_settings_field(
			'bunnify_debug_srcset_generation_field',
			__( 'Srcset Generation', 'bunnify-frontend' ),
			[ $this, 'debug_srcset_generation_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_debug_section',
		);

		add_settings_field(
			'bunnify_debug_content_filtering_field',
			__( 'Content Filtering', 'bunnify-frontend' ),
			[ $this, 'debug_content_filtering_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_debug_section',
		);

		add_settings_field(
			'bunnify_debug_local_dev_mode_field',
			__( 'Local Development Mode', 'bunnify-frontend' ),
			[ $this, 'debug_local_dev_mode_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_debug_section',
		);

		add_settings_field(
			'bunnify_debug_performance_field',
			__( 'Performance', 'bunnify-frontend' ),
			[ $this, 'debug_performance_field_callback' ],
			'bunnify-frontend',
			'bunnify_frontend_debug_section',
		);
	}

	/**
	 * Sanitize a checkbox value to the canonical '1' (on) or '0' (off).
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string '1' or '0'.
	 */
	public function sanitize_checkbox( $value ): string {
		return $value ? '1' : '0';
	}

	/**
	 * Sanitize the CDN hostname to a bare host (no scheme, path, or whitespace).
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string Sanitized hostname.
	 */
	public function sanitize_hostname( $value ): string {
		$value = sanitize_text_field( (string) $value );
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		// Accept a full URL or a bare host; reduce to the host component.
		if ( false !== strpos( $value, '//' ) ) {
			$host = wp_parse_url( $value, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				return $host;
			}
		}

		// Strip any accidental path/query and a trailing slash.
		$value = preg_replace( '#[/?].*$#', '', $value );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Sanitize the log-line retention count to an integer in [1, 100].
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int Clamped value.
	 */
	public function sanitize_refreshes( $value ): int {
		return max( 1, min( 100, (int) $value ) );
	}

	/**
	 * Sanitize the default image quality to '' (off) or an int in [1, 100].
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	public function sanitize_quality( $value ): string {
		if ( '' === trim( (string) $value ) ) {
			return '';
		}

		$quality = (int) $value;
		if ( $quality < 1 ) {
			return '';
		}

		return (string) min( 100, $quality );
	}

	/**
	 * Sanitize the output format to a supported value ('' | 'webp' | 'avif').
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	public function sanitize_format( $value ): string {
		return in_array( $value, array( 'webp', 'avif' ), true ) ? (string) $value : '';
	}

	/**
	 * Settings section callback.
	 */
	public function settings_section_callback() {
		echo '<p>' . esc_html__( 'Configure BunnyCDN settings. This plugin provides simplified image CDN functionality for your WordPress media.', 'bunnify-frontend' ) . '</p>';
	}

	/**
	 * Debug section callback.
	 */
	public function debug_section_callback() {
		echo '<p>' . esc_html__( 'Configure debug logging options to track image processing and performance.', 'bunnify-frontend' ) . '</p>';
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
		<p class="description"><?php esc_html_e( 'Enable BunnyCDN functionality for your media.', 'bunnify-frontend' ); ?></p>
		<?php
	}

	/**
	 * Hostname field callback.
	 */
	public function hostname_field_callback() {
		?>
		<input type="text" name="bunnify_hostname" value="<?php echo esc_attr( (string) get_option( 'bunnify_hostname', '' ) ); ?>"
			class="regular-text" />
		<p class="description"><?php esc_html_e( 'Your BunnyCDN hostname (e.g., cdn.example.com).', 'bunnify-frontend' ); ?></p>
		<?php
	}

	/**
	 * Default image quality field callback.
	 */
	public function default_quality_field_callback() {
		?>
		<input type="number" name="bunnify_default_quality" value="<?php echo esc_attr( (string) get_option( 'bunnify_default_quality', '' ) ); ?>"
			min="1" max="100" step="1" class="small-text" placeholder="85" />
		<p class="description"><?php esc_html_e( 'Image quality (1-100) sent to the CDN. Leave blank to use the CDN default. Lower values mean smaller files.', 'bunnify-frontend' ); ?></p>
		<?php
	}

	/**
	 * Output format field callback.
	 */
	public function format_field_callback() {
		$format = (string) get_option( 'bunnify_format', '' );
		?>
		<select name="bunnify_format">
			<option value="" <?php selected( '', $format ); ?>><?php esc_html_e( 'Original format', 'bunnify-frontend' ); ?></option>
			<option value="webp" <?php selected( 'webp', $format ); ?>>WebP</option>
			<option value="avif" <?php selected( 'avif', $format ); ?>><?php esc_html_e( 'AVIF (experimental)', 'bunnify-frontend' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Serve images in a next-gen format via the CDN. WebP is widely supported; AVIF has limited browser support.', 'bunnify-frontend' ); ?></p>
		<?php
	}

	/**
	 * Emit-dimensions (CLS) field callback.
	 */
	public function emit_dimensions_field_callback() {
		?>
		<input type="checkbox" name="bunnify_emit_dimensions" value="1" <?php checked( 1, get_option( 'bunnify_emit_dimensions' ), true ); ?> />
		<p class="description"><?php esc_html_e( 'Add width/height attributes to rewritten content images that lack them, so the browser can reserve space (reduces layout shift / CLS). Never overwrites an author-set dimension.', 'bunnify-frontend' ); ?></p>
		<?php
	}

	/**
	 * LCP-optimize field callback.
	 */
	public function lcp_optimize_field_callback() {
		?>
		<input type="checkbox" name="bunnify_lcp_optimize" value="1" <?php checked( 1, get_option( 'bunnify_lcp_optimize' ), true ); ?> />
		<p class="description"><?php esc_html_e( 'Mark the first rewritten image on a page with fetchpriority="high" (and stop it lazy-loading) so the Largest Contentful Paint image loads sooner. Use the bunnify_lcp_image filter to name a specific hero.', 'bunnify-frontend' ); ?></p>
		<?php
	}

	/**
	 * Debug enabled field callback.
	 */
	public function debug_enabled_field_callback() {
		$debug_enabled = get_option( 'bunnify_debug_enabled' );
		?>
		<input type="checkbox" name="bunnify_debug_enabled" value="1" <?php checked( 1, $debug_enabled, true ); ?> />
		<p class="description">
			<?php
			printf(
				/* translators: %s: the debug log file path. */
				esc_html__( 'Enable debug logging to track image processing. Logs are stored in %s.', 'bunnify-frontend' ),
				'<code>' . esc_html( self::get_debug_log_relative_path() ) . '</code>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Debug refreshes field callback.
	 */
	public function debug_refreshes_field_callback() {
		$debug_refreshes = get_option( 'bunnify_debug_refreshes', 10 );
		?>
		<input type="number" name="bunnify_debug_refreshes" value="<?php echo esc_attr( (string) $debug_refreshes ); ?>" min="1" max="100" class="small-text" />
		<p class="description"><?php esc_html_e( 'Number of log lines to keep (1-100). The oldest lines are trimmed beyond this.', 'bunnify-frontend' ); ?></p>
		<?php
	}

	/**
	 * Debug URL transformation field callback.
	 */
	public function debug_url_transformation_field_callback() {
		$debug_url_transformation = get_option( 'bunnify_debug_url_transformation', false );
		?>
		<input type="checkbox" name="bunnify_debug_url_transformation" value="1" <?php checked( 1, $debug_url_transformation, true ); ?> />
		<p class="description"><?php esc_html_e( 'Enable logging for URL transformation logic.', 'bunnify-frontend' ); ?></p>
		<?php
	}

	/**
	 * Debug image processing field callback.
	 */
	public function debug_image_processing_field_callback() {
		$debug_image_processing = get_option( 'bunnify_debug_image_processing', false );
		?>
		<input type="checkbox" name="bunnify_debug_image_processing" value="1" <?php checked( 1, $debug_image_processing, true ); ?> />
		<p class="description"><?php esc_html_e( 'Enable logging for image processing logic.', 'bunnify-frontend' ); ?></p>
		<?php
	}

	/**
	 * Debug srcset generation field callback.
	 */
	public function debug_srcset_generation_field_callback() {
		$debug_srcset_generation = get_option( 'bunnify_debug_srcset_generation', false );
		?>
		<input type="checkbox" name="bunnify_debug_srcset_generation" value="1" <?php checked( 1, $debug_srcset_generation, true ); ?> />
		<p class="description"><?php esc_html_e( 'Enable logging for srcset generation logic.', 'bunnify-frontend' ); ?></p>
		<?php
	}

	/**
	 * Debug content filtering field callback.
	 */
	public function debug_content_filtering_field_callback() {
		$debug_content_filtering = get_option( 'bunnify_debug_content_filtering', false );
		?>
		<input type="checkbox" name="bunnify_debug_content_filtering" value="1" <?php checked( 1, $debug_content_filtering, true ); ?> />
		<p class="description"><?php esc_html_e( 'Enable logging for content filtering logic.', 'bunnify-frontend' ); ?></p>
		<?php
	}

	/**
	 * Debug local development mode field callback.
	 */
	public function debug_local_dev_mode_field_callback() {
		$debug_local_dev_mode = get_option( 'bunnify_debug_local_dev_mode', false );
		?>
		<input type="checkbox" name="bunnify_debug_local_dev_mode" value="1" <?php checked( 1, $debug_local_dev_mode, true ); ?> />
		<p class="description"><?php esc_html_e( 'Enable logging specifically for local development mode checks.', 'bunnify-frontend' ); ?></p>
		<?php
	}

	/**
	 * Debug performance field callback.
	 */
	public function debug_performance_field_callback() {
		$debug_performance = get_option( 'bunnify_debug_performance', false );
		?>
		<input type="checkbox" name="bunnify_debug_performance" value="1" <?php checked( 1, $debug_performance, true ); ?> />
		<p class="description"><?php esc_html_e( 'Enable logging for performance tracking.', 'bunnify-frontend' ); ?></p>
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
		$environment = self::current_environment_type();
		$auto_on     = in_array( $environment, self::LOCAL_DEV_ENVIRONMENTS, true );
		$stored      = (string) get_option( 'bunnify_local_dev_mode', '' );

		if ( $auto_on ) {
			// Auto-enabled by the environment. The visible control is disabled
			// (and so not submitted), so a hidden field preserves any stored
			// force-on value across saves.
			?>
			<input type="hidden" name="bunnify_local_dev_mode" value="<?php echo esc_attr( '' === $stored ? '0' : $stored ); ?>" />
			<label><input type="checkbox" checked disabled /> <?php esc_html_e( 'Automatically enabled', 'bunnify-frontend' ); ?></label>
			<p class="description">
				<?php
				printf(
					/* translators: %s: the current environment type (e.g. local). */
					esc_html__( 'Serve the local file when it exists and fall back to the CDN for missing images. Automatically enabled because the environment type is %s.', 'bunnify-frontend' ),
					'<code>' . esc_html( $environment ) . '</code>'
				);
				?>
			</p>
			<?php
		} else {
			?>
			<input type="hidden" name="bunnify_local_dev_mode" value="0" />
			<input type="checkbox" name="bunnify_local_dev_mode" value="1" <?php checked( '1', $stored ); ?> />
			<p class="description">
				<?php
				printf(
					/* translators: %s: the current environment type (e.g. staging, production). */
					esc_html__( 'Serve the local file when it exists and fall back to the CDN for missing images. This environment reports as %s, so it is off by default; tick to force it on here.', 'bunnify-frontend' ),
					'<code>' . esc_html( $environment ) . '</code>'
				);
				?>
			</p>
			<?php
		}
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

		// Debug log info (same path DebugTrait writes to).
		$log_file   = self::get_debug_log_file();
		$log_exists = $log_file && file_exists( $log_file );
		$log_size   = $log_exists ? size_format( (int) filesize( $log_file ) ) : '0 B';

		// Count log lines (the retention setting trims by line, not "refreshes").
		$log_lines = 0;
		if ( $log_exists ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin log file, not a remote resource.
			$content = file_get_contents( $log_file );
			if ( ! empty( $content ) ) {
				$log_lines = count( array_filter( explode( PHP_EOL, $content ) ) );
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BunnyCDN Settings', 'bunnify-frontend' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'bunnify_frontend_options' );
				do_settings_sections( 'bunnify-frontend' );
				submit_button( __( 'Save Settings', 'bunnify-frontend' ) );
				?>
			</form>

			<div class="card" style="width: 100%; max-width: unset;">
				<h2><?php esc_html_e( 'Debug Log', 'bunnify-frontend' ); ?></h2>
				<?php if ( $this->is_debug_enabled() ) : ?>
					<p>
						<strong><?php esc_html_e( 'Debug mode is enabled.', 'bunnify-frontend' ); ?></strong>
						<?php
						printf(
							/* translators: %s: the debug log file path. */
							esc_html__( 'Log file: %s', 'bunnify-frontend' ),
							'<code>' . esc_html( self::get_debug_log_relative_path() ) . '</code>'
						);
						?>
					</p>
					<p><?php esc_html_e( 'Logging runs automatically for the debug categories enabled above; load a front-end page to generate entries.', 'bunnify-frontend' ); ?></p>
					<?php if ( $log_exists ) : ?>
						<p>
							<?php
							printf(
								/* translators: 1: human-readable file size, 2: number of log lines, 3: retention limit. */
								esc_html__( 'Log file size: %1$s — %2$d lines (keeping the %3$d most recent).', 'bunnify-frontend' ),
								esc_html( $log_size ),
								(int) $log_lines,
								(int) get_option( 'bunnify_debug_refreshes', 10 )
							);
							?>
						</p>
						<p class="description"><?php esc_html_e( 'The log is retrieved over SFTP/host file access; it is not linked here so the raw file is not exposed publicly.', 'bunnify-frontend' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'No log file yet. Load a front-end page with debug categories enabled to generate entries.', 'bunnify-frontend' ); ?></p>
					<?php endif; ?>
				<?php else : ?>
					<p><?php esc_html_e( 'Debug mode is disabled. Enable it above to start logging image processing.', 'bunnify-frontend' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Absolute path of the debug log file (same target as DebugTrait).
	 *
	 * @return string
	 */
	private static function get_debug_log_file(): string {
		$upload_dir = wp_get_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . self::LOG_SUBPATH;
	}

	/**
	 * Human-readable relative path of the debug log, for display.
	 *
	 * @return string e.g. wp-content/uploads/bunnify-logs/debug.log
	 */
	private static function get_debug_log_relative_path(): string {
		return 'wp-content/uploads/' . self::LOG_SUBPATH;
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
	 * The current WordPress environment type (defaults to production).
	 *
	 * @return string
	 */
	private static function current_environment_type(): string {
		return function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
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
	 * Whether to add missing width/height attributes to rewritten images (CLS).
	 *
	 * @return bool
	 */
	public static function emit_dimensions(): bool {
		return (bool) get_option( 'bunnify_emit_dimensions', false );
	}

	/**
	 * Whether to prioritise the first rewritten image as the LCP element.
	 *
	 * @return bool
	 */
	public static function lcp_optimize(): bool {
		return (bool) get_option( 'bunnify_lcp_optimize', false );
	}

	/**
	 * Check if local development mode is enabled.
	 *
	 * Local-dev mode means "serve the local file when it exists, otherwise fall
	 * back to the CDN" — so an install without synced uploads still shows every
	 * image (missing ones from the CDN) instead of broken thumbnails.
	 *
	 * It is enabled automatically on a development-class environment (`local`
	 * or `development`), using WordPress core's {@see wp_get_environment_type()}
	 * (set via the `WP_ENVIRONMENT_TYPE` constant or environment variable;
	 * defaults to `production`). `staging` is deliberately NOT auto-enabled so a
	 * staging box still exercises the CDN before a production promotion.
	 * Resolution order:
	 *
	 * 1. The `bunnify_local_dev_mode_check` filter — return non-null to force
	 *    it on or off (highest priority).
	 * 2. Automatic: on for a `local`/`development` environment.
	 * 3. The `bunnify_local_dev_mode` option — a manual force-on for any other
	 *    environment (e.g. staging, or a production-typed box).
	 *
	 * @return bool True if local development mode is enabled.
	 */
	public static function is_local_dev_mode_enabled(): bool {
		// Explicit override wins (non-null return forces on or off).
		$custom_check = apply_filters( 'bunnify_local_dev_mode_check', null );
		if ( null !== $custom_check ) {
			return (bool) $custom_check;
		}

		// Automatic: development-class environments prefer local files.
		if ( in_array( self::current_environment_type(), self::LOCAL_DEV_ENVIRONMENTS, true ) ) {
			return true;
		}

		// Manual force-on for staging / production-typed environments.
		return (bool) get_option( 'bunnify_local_dev_mode', false );
	}
}
