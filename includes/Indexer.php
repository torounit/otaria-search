<?php
/**
 * Post indexer — generates and stores embeddings when posts are saved.
 *
 * @package Otaria_Search
 */

declare(strict_types=1);

namespace Otaria_Search;

/**
 * Orchestrates the embed → store pipeline for a single post.
 *
 * The actual embedding is delegated to the core AI Client via
 * otaria_search_embedding(); storage to Repository. This class
 * is also responsible for deciding WHEN to (re-)index (published posts
 * only, skip on unchanged content hash).
 */
class Indexer {

	/**
	 * Constructor.
	 *
	 * @param Repository $repository Embeddings table wrapper.
	 * @param Chunker    $chunker    Text splitter.
	 */
	public function __construct(
		private Repository $repository,
		private Chunker $chunker = new Chunker(),
	) {}

	/**
	 * Index a single post: chunk → embed → store.
	 *
	 * Skips non-published posts and posts whose content hash has not changed.
	 * Password-protected posts are removed from the index unless the setting
	 * allows them.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function index_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			otaria_search_log( "index_post({$post_id}): post not found, skipping." );
			return;
		}

		if ( ! in_array( $post->post_type, Settings::get_post_types(), true ) ) {
			otaria_search_log( "index_post({$post_id}): post_type={$post->post_type}, skipping (not indexable)." );
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			otaria_search_log( "index_post({$post_id}): status={$post->post_status}, skipping (not published)." );
			return;
		}

		if ( '' !== $post->post_password && Settings::excludes_password_protected() ) {
			// Also drops rows indexed before the password was set.
			otaria_search_log( "index_post({$post_id}): password-protected, removing from the index." );
			$this->repository->delete_post( $post_id );
			return;
		}

		$hash = Content_Hash::compute( $post->post_title, $post->post_content, Settings::get_embedding_key() );
		if ( $this->repository->get_content_hash( $post_id ) === $hash ) {
			otaria_search_log( "index_post({$post_id}): content hash unchanged, skipping." );
			return;
		}

		$texts  = $this->chunker->chunk( Content_Text::from_html( $post->post_content ), $post->post_title );
		$prefix = Settings::get_prefixes()['document_prefix'];
		$result = otaria_search_embedding(
			array_map( static fn( string $text ): string => $prefix . $text, $texts )
		)->generate_embeddings();

		if ( is_wp_error( $result ) ) {
			otaria_search_log(
				sprintf(
					'index_post(%d): embed failed [%s] %s',
					$post_id,
					$result->get_error_code(),
					$result->get_error_message()
				)
			);
			return;
		}

		$dim_info = count( $result ) > 0 ? count( $result[0] ) : 0;
		otaria_search_log(
			sprintf(
				'index_post(%d): embed OK — %d chunk(s), %d dimensions.',
				$post_id,
				count( $result ),
				$dim_info
			)
		);

		$chunks = array();
		foreach ( $result as $i => $embedding ) {
			$chunks[] = array(
				'chunk_index' => $i,
				'chunk_text'  => $texts[ $i ],
				'vector'      => $embedding->getValues(),
			);
		}

		$this->repository->replace_post_chunks(
			$post_id,
			$post->post_type,
			$hash,
			$chunks
		);
	}

	/**
	 * Remove all stored embeddings for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function delete_post( int $post_id ): void {
		$this->repository->delete_post( $post_id );
	}

	/**
	 * Remove the rows of post types that a settings change deselected.
	 *
	 * Hooked to the option update, so it runs however the settings are
	 * written. Added post types are left to a Reindex.
	 *
	 * @since 0.3.0
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 * @return void
	 */
	public function remove_deselected_post_types( mixed $old_value, mixed $value ): void {
		$before = Settings::get_post_types( is_array( $old_value ) ? $old_value : array() );
		$after  = Settings::get_post_types( is_array( $value ) ? $value : array() );

		if ( array() !== array_diff( $before, $after ) && Schema::is_installed() ) {
			$this->repository->delete_other_post_types( $after );
		}
	}
}
