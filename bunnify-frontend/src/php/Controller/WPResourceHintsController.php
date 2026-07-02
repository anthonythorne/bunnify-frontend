<?php
/**
 * Resource hints for the BunnyCDN hostname.
 *
 * File Path: src/php/Controller/WPResourceHintsController.php
 *
 * @package BunnifyFrontend
 * @since   1.0.0
 */

namespace BunnifyFrontend\Controller;

use BunnifyFrontend\Base\Main\Controller;

/**
 * Registers preconnect (and strips duplicate dns-prefetch) for the CDN hostname.
 */
class WPResourceHintsController extends Controller {

	/**
	 * Register hooks.
	 */
	public function set_up() {
		add_filter( 'wp_resource_hints', [ $this, 'update_resource_hints' ], \PHP_INT_MAX, 2 );
	}

	/**
	 * Update resource hints for the BunnyCDN hostname.
	 *
	 * @param array  $urls          Resource hint URLs or attribute arrays.
	 * @param string $relation_type One of 'dns-prefetch', 'preconnect', 'prefetch', or 'prerender'.
	 *
	 * @return array
	 */
	public function update_resource_hints( array $urls, string $relation_type ): array {
		// No hints when rewriting is disabled — nothing will load from the CDN.
		if ( ! SettingsController::is_enabled() ) {
			return $urls;
		}

		$bunnify_hostname = get_option( 'bunnify_hostname', false );
		if ( ! $bunnify_hostname ) {
			return $urls;
		}

		if ( 'dns-prefetch' === $relation_type ) {
			$key = array_search( $bunnify_hostname, $urls, true );
			if ( false !== $key ) {
				unset( $urls[ $key ] );
			}
		} elseif ( 'preconnect' === $relation_type ) {
			// Only preconnect when the CDN is a genuinely different origin from the
			// current site. If the configured hostname matches the site host (e.g. a
			// local/staging environment where Bunny rewriting points back at the site,
			// or a misconfiguration), a preconnect to our own origin is pointless —
			// skip it so the hint stays correct across prod / WP Engine / local.
			$site_host = wp_parse_url( home_url(), \PHP_URL_HOST );

			if ( $bunnify_hostname !== $site_host ) {
				// No `crossorigin`: Bunny assets load as plain <img>/<link>/<script>
				// (non-CORS requests), so a CORS-pool preconnect sits unused while the
				// browser opens a separate non-CORS connection for the asset. A plain
				// preconnect matches the connection actually used (clears Lighthouse's
				// "Unused preconnect" + captures the LCP saving). If CORS assets (e.g.
				// fonts) are ever served from the CDN, add a second crossorigin hint.
				$urls[] = "https://$bunnify_hostname";
			}
		}

		return $urls;
	}
}
