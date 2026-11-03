<?php
/**
 * Settings registration for the plugin.
 *
 * @package Otaria_Search
 */

declare(strict_types=1);

namespace Otaria_Search;

/**
 * Handles registration of plugin settings.
 */
class Settings {

	const OPTION_NAME = 'otaria_search_settings';

	/**
	 * Settings keys holding cosine distance thresholds for search.
	 *
	 * @since 0.2.0
	 */
	const THRESHOLD_KEYS = array( 'max_distance', 'relative_cutoff' );

	/**
	 * Default thresholds, used until the admin saves model-specific values.
	 *
	 * The max_distance stays loose: distance scales differ a lot between models,
	 * and a tight absolute cutoff empties the results of a model with large
	 * distances. relative_cutoff trims the tail instead, measured per query
	 * against a typical unrelated distance (Repository::filter_by_distance()),
	 * so one value suits different models. It never drops the best match, and
	 * hybrid search keeps the LIKE matches.
	 *
	 * @since 0.2.0
	 */
	const DEFAULT_THRESHOLDS = array(
		'max_distance'    => 0.65,
		'relative_cutoff' => 0.7,
	);

	/**
	 * Valid range of each threshold.
	 */
	private const THRESHOLD_RANGES = array(
		'max_distance'    => array( 0.0, 2.0 ),
		'relative_cutoff' => array( 0.0, 1.0 ),
	);

	/**
	 * Settings keys holding the texts prepended before embedding: to search
	 * terms and to post chunks. Models that need them document them.
	 *
	 * @since 0.3.0
	 */
	const PREFIX_KEYS = array( 'query_prefix', 'document_prefix' );

	/**
	 * Saved distance thresholds, falling back to the defaults.
	 *
	 * @since 0.2.0
	 *
	 * @return array{max_distance: float, relative_cutoff: float}
	 */
	public static function get_thresholds(): array {
		$settings = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$thresholds = array();
		foreach ( self::DEFAULT_THRESHOLDS as $key => $default ) {
			$thresholds[ $key ] = isset( $settings[ $key ] ) && self::is_valid_threshold( $key, $settings[ $key ] )
				? (float) $settings[ $key ]
				: $default;
		}

		return $thresholds;
	}

	/**
	 * Whether password-protected posts are left out of the index.
	 *
	 * Their content is otherwise sent to the embedding provider. Defaults to true.
	 *
	 * @since 0.3.0
	 *
	 * @return bool
	 */
	public static function excludes_password_protected(): bool {
		$settings = get_option( self::OPTION_NAME, array() );

		return ! is_array( $settings ) || ! isset( $settings['exclude_password_protected'] )
			|| (bool) $settings['exclude_password_protected'];
	}

	/**
	 * Saved embedding prefixes.
	 *
	 * @since 0.3.0
	 *
	 * @return array{query_prefix: string, document_prefix: string} Empty strings when not set.
	 */
	public static function get_prefixes(): array {
		$settings = get_option( self::OPTION_NAME, array() );

		$prefixes = array();
		foreach ( self::PREFIX_KEYS as $key ) {
			$prefixes[ $key ] = is_array( $settings ) && isset( $settings[ $key ] ) && is_string( $settings[ $key ] )
				? $settings[ $key ]
				: '';
		}

		return $prefixes;
	}

	/**
	 * Identify how chunks are embedded: the selected model as "provider:model",
	 * plus the document prefix when one is set.
	 *
	 * Part of each post's content hash, so posts are re-embedded when it
	 * changes.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public static function get_embedding_key(): string {
		$settings = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $settings ) ) {
			return '';
		}

		$key    = ( $settings['provider'] ?? '' ) . ':' . ( $settings['model'] ?? '' );
		$prefix = self::get_prefixes()['document_prefix'];

		// Without a prefix the key stays "provider:model", so posts indexed
		// before prefixes existed are not re-embedded.
		return '' === $prefix ? $key : $key . "\x00" . $prefix;
	}

	/**
	 * Whether vector results are fused with WordPress's LIKE search results.
	 *
	 * @since 0.3.0
	 *
	 * @return bool True unless turned off on the admin page.
	 */
	public static function is_hybrid(): bool {
		$settings = get_option( self::OPTION_NAME, array() );

		return ! is_array( $settings ) || ( $settings['hybrid'] ?? true ) !== false;
	}

	/**
	 * Post types that can be indexed: the public, searchable ones.
	 *
	 * Attachments are left out: only published posts are indexed, and
	 * attachments are never published (their status is "inherit").
	 *
	 * @since 0.3.0
	 *
	 * @return string[] Post type slugs.
	 */
	public static function available_post_types(): array {
		$post_types = get_post_types(
			array(
				'public'              => true,
				'exclude_from_search' => false,
			)
		);
		unset( $post_types['attachment'] );

		return array_values( $post_types );
	}

	/**
	 * Post types that are indexed and searched.
	 *
	 * @since 0.3.0
	 *
	 * @param array|null $settings Settings to read; the saved ones by default.
	 * @return string[] The saved post types that are still available; without a
	 *                  saved list, all available post types.
	 */
	public static function get_post_types( ?array $settings = null ): array {
		$settings  = $settings ?? get_option( self::OPTION_NAME, array() );
		$available = self::available_post_types();

		if ( ! is_array( $settings ) || ! isset( $settings['post_types'] ) || ! is_array( $settings['post_types'] ) ) {
			return $available;
		}

		return array_values( array_intersect( $settings['post_types'], $available ) );
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_setting(
			'general',
			self::OPTION_NAME,
			array(
				'type'              => 'object',
				'default'           => array(),
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Save changes to the settings, keeping the keys not in $changes.
	 *
	 * The merge happens here, not in sanitize_settings(), so that writing the
	 * option always means what it says.
	 *
	 * @since 0.3.0
	 *
	 * @param array $changes Settings to change. Null removes a key, restoring its default.
	 * @return void
	 */
	public static function update( array $changes ): void {
		$settings = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		foreach ( $changes as $key => $value ) {
			if ( null === $value ) {
				unset( $settings[ $key ] );
			} else {
				$settings[ $key ] = $value;
			}
		}

		// Sanitize here too: the sanitize_callback is registered only when
		// the database supports VECTOR.
		update_option( self::OPTION_NAME, self::sanitize_settings( $settings ) );
	}

	/**
	 * Sanitize settings.
	 *
	 * Validates and normalizes the given settings only; invalid values and
	 * unknown keys are dropped. Saved settings are not read: use update() to
	 * change some keys and keep the others.
	 *
	 * @param mixed $input Settings to sanitize.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( array( 'provider', 'model' ) as $key ) {
			if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) ) {
				$sanitized[ $key ] = sanitize_text_field( $input[ $key ] );
			}
		}

		if ( isset( $input['dimensions'] ) && is_numeric( $input['dimensions'] ) && (int) $input['dimensions'] >= 1 ) {
			$sanitized['dimensions'] = (int) $input['dimensions'];
		}

		foreach ( self::THRESHOLD_KEYS as $key ) {
			if ( isset( $input[ $key ] ) && self::is_valid_threshold( $key, $input[ $key ] ) ) {
				$sanitized[ $key ] = (float) $input[ $key ];
			}
		}

		if ( isset( $input['exclude_password_protected'] ) ) {
			$sanitized['exclude_password_protected'] = rest_sanitize_boolean( $input['exclude_password_protected'] );
		}

		if ( isset( $input['hybrid'] ) && is_bool( $input['hybrid'] ) ) {
			$sanitized['hybrid'] = $input['hybrid'];
		}

		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$sanitized['post_types'] = array_values(
				array_unique( array_map( 'sanitize_key', array_filter( $input['post_types'], 'is_string' ) ) )
			);
		}

		foreach ( self::PREFIX_KEYS as $key ) {
			$prefix = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? self::sanitize_prefix( $input[ $key ] ) : '';
			if ( '' !== $prefix ) {
				$sanitized[ $key ] = $prefix;
			}
		}

		return $sanitized;
	}

	/**
	 * Remove invalid UTF-8 and control characters from a prefix.
	 *
	 * Spaces are kept: a trailing space is part of prefixes such as "query: ".
	 * sanitize_text_field() would trim it.
	 *
	 * @since 0.3.0
	 *
	 * @param string $prefix Prefix to clean.
	 * @return string
	 */
	private static function sanitize_prefix( string $prefix ): string {
		return (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', wp_check_invalid_utf8( $prefix ) );
	}

	/**
	 * Whether a value is within the range of a threshold.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key   Threshold key, one of THRESHOLD_KEYS.
	 * @param mixed  $value Value to check.
	 * @return bool
	 */
	private static function is_valid_threshold( string $key, mixed $value ): bool {
		[ $min, $max ] = self::THRESHOLD_RANGES[ $key ];
		return is_numeric( $value ) && (float) $value >= $min && (float) $value <= $max;
	}
}
