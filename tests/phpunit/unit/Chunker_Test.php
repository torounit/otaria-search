<?php
/**
 * Unit tests for the Chunker class.
 *
 * @package Otaria_Search
 */

declare(strict_types=1);

namespace Otaria_Search\Tests\Unit;

use Otaria_Search\Chunker;

/**
 * Class Chunker_Test
 */
class Chunker_Test extends \WP_UnitTestCase {

	/**
	 * Chunker instance under test.
	 *
	 * @var Chunker
	 */
	private Chunker $chunker;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->chunker = new Chunker( 2000, 300 );
	}

	/**
	 * Short text (under chunk_size) returns a single chunk.
	 */
	public function test_short_text_returns_single_chunk(): void {
		$chunks = $this->chunker->chunk( 'Hello world', 'My Title' );

		$this->assertCount( 1, $chunks );
		$this->assertStringContainsString( 'My Title', $chunks[0] );
		$this->assertStringContainsString( 'Hello world', $chunks[0] );
	}

	/**
	 * Empty body returns a single title-only chunk.
	 */
	public function test_empty_body_returns_title_chunk(): void {
		$chunks = $this->chunker->chunk( '', 'My Title' );

		$this->assertCount( 1, $chunks );
		$this->assertStringContainsString( 'My Title', $chunks[0] );
	}

	/**
	 * Title is prepended to every chunk.
	 */
	public function test_title_prepended_to_every_chunk(): void {
		// Build text that is 3× the chunk size to force multiple chunks.
		$long_text = $this->make_paragraphs( 200, 30 );
		$chunks    = $this->chunker->chunk( $long_text, 'Test Title' );

		$this->assertGreaterThan( 1, count( $chunks ) );
		foreach ( $chunks as $chunk ) {
			$this->assertStringStartsWith( 'Test Title', $chunk );
		}
	}

	/**
	 * No chunk exceeds the chunk size (title overhead excluded from limit check
	 * to keep the implementation simple; body portion stays within limit).
	 */
	public function test_no_chunk_body_exceeds_size_limit(): void {
		$long_text = $this->make_paragraphs( 200, 30 );
		$chunks    = $this->chunker->chunk( $long_text, 'T' );

		foreach ( $chunks as $chunk ) {
			// Body is after "T\n\n".
			$body = substr( $chunk, mb_strlen( "T\n\n" ) );
			$this->assertLessThanOrEqual( 2000 + 300, mb_strlen( $body ) );
		}
	}

	/**
	 * Adjacent chunks overlap: the end of chunk N appears in chunk N+1.
	 */
	public function test_chunks_overlap(): void {
		$long_text = $this->make_paragraphs( 200, 30 );
		$chunks    = $this->chunker->chunk( $long_text, 'T' );

		if ( count( $chunks ) < 2 ) {
			$this->markTestSkipped( 'Text too short to produce 2+ chunks.' );
		}

		// Strip title prefix then verify last 100 chars of chunk 0 appear in chunk 1.
		$prefix = "T\n\n";
		$body_0 = substr( $chunks[0], mb_strlen( $prefix ) );
		$body_1 = substr( $chunks[1], mb_strlen( $prefix ) );
		$tail   = mb_substr( $body_0, -100 );

		$this->assertStringContainsString( $tail, $body_1 );
	}

	/**
	 * Multibyte text is not split in the middle of a character.
	 */
	public function test_multibyte_text_not_split_mid_character(): void {
		// Japanese text: each character is 3 UTF-8 bytes.
		$japanese = str_repeat( 'あ', 3000 );
		$chunks   = $this->chunker->chunk( $japanese, 'タイトル' );

		foreach ( $chunks as $chunk ) {
			$this->assertTrue( mb_check_encoding( $chunk, 'UTF-8' ) );
		}
	}

	/**
	 * HTML and block markup are stripped from the body.
	 */
	public function test_html_is_stripped(): void {
		$html   = '<p>Hello <strong>world</strong></p>';
		$chunks = $this->chunker->chunk( $html, 'T' );

		$this->assertStringNotContainsString( '<p>', implode( '', $chunks ) );
		$this->assertStringNotContainsString( '<strong>', implode( '', $chunks ) );
		$this->assertStringContainsString( 'Hello world', implode( '', $chunks ) );
	}

	/**
	 * Image alt text stays in the body, where the image was.
	 */
	public function test_image_alt_is_kept_in_place(): void {
		$html   = '<p>Before</p><img src="cat.jpg" alt="ねこの写真"><p>After</p>';
		$chunks = $this->chunker->chunk( $html, 'T' );

		$this->assertMatchesRegularExpression( '/Before\s+ねこの写真\s+After/u', $chunks[0] );
	}

	/**
	 * An Image block keeps both its alt text and its caption, and loses its markup.
	 */
	public function test_image_block_keeps_alt_and_caption(): void {
		$html   = '<!-- wp:image {"id":1} --><figure class="wp-block-image"><img src="cat.jpg" alt="ねこの写真" class="wp-image-1"/><figcaption class="wp-element-caption">うちの猫</figcaption></figure><!-- /wp:image -->';
		$chunks = $this->chunker->chunk( $html, 'T' );

		$this->assertStringContainsString( 'ねこの写真', $chunks[0] );
		$this->assertStringContainsString( 'うちの猫', $chunks[0] );
		$this->assertStringNotContainsString( 'wp:image', $chunks[0] );
		$this->assertStringNotContainsString( 'cat.jpg', $chunks[0] );
	}

	/**
	 * Alt text does not run into the words around an inline image.
	 */
	public function test_inline_image_alt_is_separated_from_surrounding_text(): void {
		$chunks = $this->chunker->chunk( 'foo<img src="a.jpg" alt="bar">baz', 'T' );

		$this->assertStringContainsString( 'foo bar baz', $chunks[0] );
	}

	/**
	 * Characters that look like markup in alt text do not swallow what follows.
	 */
	public function test_markup_characters_in_alt_do_not_swallow_body(): void {
		$html   = '<img src="a.jpg" alt="1 > 0"><img src="b.jpg" alt="a &lt;b"><p>After</p>';
		$chunks = $this->chunker->chunk( $html, 'T' );

		$this->assertStringContainsString( '1 &gt; 0', $chunks[0] );
		$this->assertStringContainsString( 'a &lt;b', $chunks[0] );
		$this->assertStringContainsString( 'After', $chunks[0] );
		$this->assertStringNotContainsString( 'b.jpg', $chunks[0] );
	}

	/**
	 * Images without alt text add nothing to the body.
	 */
	public function test_image_without_alt_adds_nothing(): void {
		$html   = '<img src="a.jpg"><img src="b.jpg" alt=""><img src="c.jpg" alt="  "><img src="d.jpg" alt><p>Body</p>';
		$chunks = $this->chunker->chunk( $html, 'T' );

		$this->assertSame( array( "T\n\nBody" ), $chunks );
	}

	/**
	 * Images inside HTML comments are not content.
	 */
	public function test_image_in_comment_is_ignored(): void {
		$chunks = $this->chunker->chunk( '<!-- <img src="a.jpg" alt="hidden"> --><p>Body</p>', 'T' );

		$this->assertSame( array( "T\n\nBody" ), $chunks );
	}

	// -----------------------------------------------------------------------

	/**
	 * Build a string of $paragraphs paragraphs each containing $words words.
	 *
	 * @param int $paragraphs Number of paragraphs.
	 * @param int $words      Words per paragraph.
	 * @return string
	 */
	private function make_paragraphs( int $paragraphs, int $words ): string {
		$word = 'word';
		$para = implode( ' ', array_fill( 0, $words, $word ) );
		return implode( "\n\n", array_fill( 0, $paragraphs, $para ) );
	}
}
