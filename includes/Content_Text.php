<?php
/**
 * Post content to plain text conversion.
 *
 * @package Otaria_Search
 */

declare(strict_types=1);

namespace Otaria_Search;

/**
 * Extracts the text of a post from its stored HTML.
 *
 * Besides the visible text, this covers the alt text of images in the content.
 */
final class Content_Text extends \WP_HTML_Tag_Processor {

	/**
	 * Convert raw post content to plain text.
	 *
	 * @param string $html Raw post content (may contain HTML / block markup).
	 * @return string Plain text, trimmed.
	 */
	public static function from_html( string $html ): string {
		$processor = new self( $html );
		$processor->inline_image_alt();

		$text = wp_strip_all_tags( $processor->get_updated_html() );

		return self::trim_text( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Replace each image that has alt text with that text, where the image was.
	 *
	 * Stripping tags would otherwise discard it. The text is escaped again so
	 * that a `<` in it is not read as the start of a tag. Images in NOSCRIPT are
	 * skipped: they are fallbacks that repeat a lazy-loaded image.
	 */
	private function inline_image_alt(): void {
		$in_noscript = false;

		while ( $this->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			if ( 'NOSCRIPT' === $this->get_tag() ) {
				$in_noscript = ! $this->is_tag_closer();
				continue;
			}

			if ( 'IMG' !== $this->get_tag() || $in_noscript ) {
				continue;
			}

			$alt = $this->get_attribute( 'alt' );
			$alt = is_string( $alt ) ? self::trim_text( $alt ) : '';

			if ( '' === $alt ) {
				continue;
			}

			$this->set_bookmark( 'img' );
			$this->lexical_updates[] = new \WP_HTML_Text_Replacement(
				$this->bookmarks['img']->start,
				$this->bookmarks['img']->length,
				' ' . htmlspecialchars( $alt, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . ' '
			);
		}
	}

	/**
	 * Trim whitespace, including non-ASCII spaces such as U+3000 and U+00A0.
	 *
	 * @param string $text Text to trim.
	 * @return string
	 */
	private static function trim_text( string $text ): string {
		return preg_replace( '/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $text ) ?? trim( $text );
	}
}
