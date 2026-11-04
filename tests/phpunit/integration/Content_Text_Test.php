<?php
/**
 * Unit tests for the Content_Text class.
 *
 * @package Otaria_Search
 */

declare(strict_types=1);

namespace Otaria_Search\Tests\Integration;

use Otaria_Search\Content_Text;

/**
 * Class Content_Text_Test
 */
class Content_Text_Test extends \WP_UnitTestCase {

	/**
	 * HTML and block markup are stripped from the body.
	 */
	public function test_html_is_stripped(): void {
		$html = '<p>Hello <strong>world</strong></p>';
		$text = Content_Text::from_html( $html );

		$this->assertStringNotContainsString( '<p>', $text );
		$this->assertStringNotContainsString( '<strong>', $text );
		$this->assertStringContainsString( 'Hello world', $text );
	}

	/**
	 * Image alt text stays in the body, where the image was.
	 */
	public function test_image_alt_is_kept_in_place(): void {
		$html = '<p>Before</p><img src="cat.jpg" alt="ねこの写真"><p>After</p>';
		$text = Content_Text::from_html( $html );

		$this->assertMatchesRegularExpression( '/Before\s+ねこの写真\s+After/u', $text );
	}

	/**
	 * An Image block keeps both its alt text and its caption, and loses its markup.
	 */
	public function test_image_block_keeps_alt_and_caption(): void {
		$html = '<!-- wp:image {"id":1} --><figure class="wp-block-image"><img src="cat.jpg" alt="ねこの写真" class="wp-image-1"/><figcaption class="wp-element-caption">うちの猫</figcaption></figure><!-- /wp:image -->';
		$text = Content_Text::from_html( $html );

		$this->assertStringContainsString( 'ねこの写真', $text );
		$this->assertStringContainsString( 'うちの猫', $text );
		$this->assertStringNotContainsString( 'wp:image', $text );
		$this->assertStringNotContainsString( 'cat.jpg', $text );
	}

	/**
	 * Alt text does not run into the words around an inline image.
	 */
	public function test_inline_image_alt_is_separated_from_surrounding_text(): void {
		$text = Content_Text::from_html( 'foo<img src="a.jpg" alt="bar">baz' );

		$this->assertStringContainsString( 'foo bar baz', $text );
	}

	/**
	 * Characters that look like markup in alt text do not swallow what follows.
	 */
	public function test_markup_characters_in_alt_do_not_swallow_body(): void {
		$html = '<img src="a.jpg" alt="1 > 0"><img src="b.jpg" alt="a &lt;b"><p>After</p>';
		$text = Content_Text::from_html( $html );

		$this->assertStringContainsString( '1 &gt; 0', $text );
		$this->assertStringContainsString( 'a &lt;b', $text );
		$this->assertStringContainsString( 'After', $text );
		$this->assertStringNotContainsString( 'b.jpg', $text );
	}

	/**
	 * Images without alt text add nothing to the body.
	 */
	public function test_image_without_alt_adds_nothing(): void {
		$html = '<img src="a.jpg"><img src="b.jpg" alt=""><img src="c.jpg" alt="  "><img src="d.jpg" alt><p>Body</p>';
		$text = Content_Text::from_html( $html );

		$this->assertSame( 'Body', $text );
	}

	/**
	 * Images inside HTML comments are not content.
	 */
	public function test_image_in_comment_is_ignored(): void {
		$text = Content_Text::from_html( '<!-- <img src="a.jpg" alt="hidden"> --><p>Body</p>' );

		$this->assertSame( 'Body', $text );
	}
}
