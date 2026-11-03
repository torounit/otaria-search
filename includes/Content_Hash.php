<?php
/**
 * Content hash utility.
 *
 * @package Otaria_Search
 */

declare(strict_types=1);

namespace Otaria_Search;

/**
 * Produces a stable fingerprint for a post's indexable content.
 *
 * The hash is stored alongside each indexed post so that unchanged content
 * is not re-embedded on every save. It also covers the embedding model, since
 * vectors from different models are not comparable even at equal dimensions.
 */
class Content_Hash {

	/**
	 * Compute a SHA-256 hex digest of the model, title and body.
	 *
	 * Length-prefixed separators are used so that ("AB", "") ≠ ("A", "B").
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Added the `$model` parameter.
	 *
	 * @param string $title Post title.
	 * @param string $body  Raw post content (may contain HTML/blocks).
	 * @param string $model Embedding model key, e.g. "openai:text-embedding-3-small".
	 * @return string 64-character lowercase hex string.
	 */
	public static function compute( string $title, string $body, string $model = '' ): string {
		$payload = strlen( $model ) . ':' . $model . strlen( $title ) . ':' . $title . "\x00" . $body;
		return hash( 'sha256', $payload );
	}
}
