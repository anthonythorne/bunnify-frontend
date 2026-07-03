<?php
/**
 * Core Web Vitals helpers for rewritten images (CLS + LCP).
 *
 * File Path: src/php/Library/Cwv.php
 *
 * @package BunnifyFrontend\Library
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace BunnifyFrontend\Library;

use BunnifyFrontend\Controller\SettingsController;
use WP_HTML_Tag_Processor;

/**
 * Opt-in Core Web Vitals decorations, applied to images the plugin rewrites.
 *
 * - CLS: add missing width/height so the browser can reserve the box.
 * - LCP: mark exactly one image per request with fetchpriority="high" (and stop
 *   it lazy-loading) so the Largest Contentful Paint image loads sooner.
 *
 * Both are off by default and gated by their settings; a fresh install behaves
 * exactly as before until an admin opts in.
 */
final class Cwv {

	/**
	 * Whether the single per-request LCP image has already been assigned.
	 *
	 * @var bool
	 */
	private static bool $lcp_assigned = false;

	/**
	 * Reset per-request state (used by tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$lcp_assigned = false;
	}

	/**
	 * Whether to add missing width/height attributes (CLS).
	 *
	 * @return bool
	 */
	public static function emit_dimensions_enabled(): bool {
		return (bool) apply_filters( 'bunnify_emit_dimensions', SettingsController::emit_dimensions() );
	}

	/**
	 * Add width/height to an <img> that lacks them, never overwriting an
	 * author-set value. The pair is the aspect-consistent one already computed
	 * for the CDN URL, so the reserved box matches the delivered pixels.
	 *
	 * @param WP_HTML_Tag_Processor $img      The image tag processor (positioned on the img).
	 * @param int|string|null       $width    Resolved width, if any.
	 * @param int|string|null       $height   Resolved height, if any.
	 * @return void
	 */
	public static function maybe_add_dimensions( WP_HTML_Tag_Processor $img, $width, $height ): void {
		if ( ! self::emit_dimensions_enabled() ) {
			return;
		}

		if ( $width && ! $img->get_attribute( 'width' ) ) {
			$img->set_attribute( 'width', (string) (int) $width );
		}
		if ( $height && ! $img->get_attribute( 'height' ) ) {
			$img->set_attribute( 'height', (string) (int) $height );
		}
	}

	/**
	 * Mark an <img> as the LCP image, at most once per request.
	 *
	 * Off in admin and unless `bunnify_lcp_optimize` is set. The default heuristic
	 * is "the first rewritten image"; a site can name its hero (or veto one) via
	 * the `bunnify_lcp_image` filter.
	 *
	 * @param WP_HTML_Tag_Processor $img     The image tag processor.
	 * @param string                $cdn_url The rewritten CDN URL (for the filter).
	 * @return bool True if this image was marked as the LCP image.
	 */
	public static function maybe_mark_lcp( WP_HTML_Tag_Processor $img, string $cdn_url ): bool {
		if ( self::$lcp_assigned || is_admin() || ! SettingsController::lcp_optimize() ) {
			return false;
		}

		/**
		 * Whether this image is the LCP image. Default: the first eligible image.
		 *
		 * @param bool   $is_lcp  Default true (first eligible image wins).
		 * @param string $cdn_url The rewritten CDN URL.
		 */
		if ( ! apply_filters( 'bunnify_lcp_image', true, $cdn_url ) ) {
			return false;
		}

		$img->set_attribute( 'fetchpriority', 'high' );
		$img->remove_attribute( 'loading' ); // Never lazy-load the LCP image.
		self::$lcp_assigned = true;

		return true;
	}
}
