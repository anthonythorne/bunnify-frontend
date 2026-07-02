<?php
/**
 * Shared BunnyCDN client bootstrap for controllers.
 *
 * File Path: src/php/Library/CdnClientTrait.php
 *
 * @package BunnifyFrontend
 * @since   1.0.0
 */

namespace BunnifyFrontend\Library;

/**
 * Lazily constructs a per-consumer URLTransformer from the configured hostname.
 *
 * Extracted verbatim from the duplicated init_cdn() implementations that lived
 * in CDNController and ContentController; behaviour is unchanged. Intentionally
 * NOT declared strict_types: get_option() returns false when the option is
 * unset, and the coercive assignment of that false to the ?string property
 * (yielding '') is the established behaviour these controllers rely on.
 */
trait CdnClientTrait {

	/**
	 * URL Transformer instance.
	 *
	 * @var URLTransformer|null
	 */
	private ?URLTransformer $url_transformer = null;

	/**
	 * BunnyCDN hostname.
	 *
	 * @var string|null
	 */
	private ?string $bunnify_hostname = null;

	/**
	 * Initialize CDN functionality.
	 *
	 * @return bool True if CDN is properly initialized, false otherwise.
	 */
	private function init_cdn(): bool {
		if ( null !== $this->url_transformer ) {
			return true;
		}

		// Master switch — an explicitly disabled bunnify_enabled stops all rewriting.
		if ( ! \BunnifyFrontend\Controller\SettingsController::is_enabled() ) {
			return false;
		}

		$this->bunnify_hostname = get_option( 'bunnify_hostname' );

		// Only proceed if hostname is configured.
		if ( empty( $this->bunnify_hostname ) ) {
			return false;
		}

		$this->url_transformer = new URLTransformer( $this->bunnify_hostname );
		return true;
	}
}
