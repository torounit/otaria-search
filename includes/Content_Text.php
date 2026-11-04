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
class Content_Text {

	/**
	 * Convert raw post content to plain text.
	 *
	 * @param string $html Raw post content (may contain HTML / block markup).
	 * @return string Plain text, trimmed.
	 */
	public static function from_html( string $html ): string {
		return wp_strip_all_tags( self::inline_image_alt( $html ) );
	}

	/**
	 * Replace each image that has alt text with that text, where the image was.
	 *
	 * Stripping tags would otherwise discard it. The text is escaped again so
	 * that a `<` in it is not read as the start of a tag.
	 *
	 * @param string $html Raw post content.
	 * @return string
	 */
	private static function inline_image_alt( string $html ): string {
		$processor = new class( $html ) extends \WP_HTML_Tag_Processor {
			/**
			 * Return the input span of the current tag.
			 *
			 * @return \WP_HTML_Span
			 */
			public function get_tag_span(): \WP_HTML_Span {
				$this->set_bookmark( 'tag' );
				return $this->bookmarks['tag'];
			}
		};

		$output = '';
		$cursor = 0;

		while ( $processor->next_tag( 'IMG' ) ) {
			$alt = $processor->get_attribute( 'alt' );
			$alt = is_string( $alt ) ? trim( $alt ) : '';

			if ( '' === $alt ) {
				continue;
			}

			$span    = $processor->get_tag_span();
			$output .= substr( $html, $cursor, $span->start - $cursor );
			$output .= ' ' . htmlspecialchars( $alt, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . ' ';
			$cursor  = $span->start + $span->length;
		}

		return $output . substr( $html, $cursor );
	}
}
