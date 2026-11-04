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

		return wp_strip_all_tags( $processor->get_updated_html() );
	}

	/**
	 * Replace each image that has alt text with that text, where the image was.
	 *
	 * Stripping tags would otherwise discard it. The text is escaped again so
	 * that a `<` in it is not read as the start of a tag.
	 */
	private function inline_image_alt(): void {
		while ( $this->next_tag( 'IMG' ) ) {
			$alt = $this->get_attribute( 'alt' );
			$alt = is_string( $alt ) ? trim( $alt ) : '';

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
}
