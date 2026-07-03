<?php
/**
 * Origin attachment-URL accessor with a re-entrancy guard.
 *
 * File Path: src/php/Library/AttachmentUrl.php
 *
 * @package BunnifyFrontend\Library
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace BunnifyFrontend\Library;

/**
 * Provides the *origin* (un-rewritten) attachment URL and a Photon-style
 * suspend guard.
 *
 * The plugin registers a `wp_get_attachment_url` filter to rewrite bare
 * attachment URLs to the CDN. But the plugin itself calls
 * `wp_get_attachment_url()` internally to obtain the ORIGIN URL — to build a
 * CDN URL from it, test local existence, or cache it. Those internal lookups
 * must keep returning the origin, and the filter must not recurse into itself.
 *
 * Every internal origin lookup goes through {@see self::origin()}, which sets a
 * static suspend flag for the duration of the core call; the filter callback
 * short-circuits whenever {@see self::is_suspended()} is true.
 */
final class AttachmentUrl {

	/**
	 * True while an internal origin lookup is in flight.
	 *
	 * @var bool
	 */
	private static bool $suspend = false;

	/**
	 * Whether an internal origin lookup is currently in progress.
	 *
	 * @return bool
	 */
	public static function is_suspended(): bool {
		return self::$suspend;
	}

	/**
	 * The origin (un-rewritten) URL for an attachment.
	 *
	 * Suspends the `wp_get_attachment_url` filter for the duration of the core
	 * call so the plugin's own callback returns the raw URL. Nested-safe: the
	 * previous flag value is restored, not blindly cleared.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return string|false The origin URL, or false if unavailable.
	 */
	public static function origin( int $attachment_id ) {
		$previous      = self::$suspend;
		self::$suspend = true;

		try {
			return wp_get_attachment_url( $attachment_id );
		} finally {
			self::$suspend = $previous;
		}
	}
}
