<?php
/**
 * Settings registration for the plugin.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Handles registration of plugin settings.
 */
class Settings {

	const OPTION_NAME = 'wp_mariadb_vector_search_settings';

	/**
	 * Settings keys holding cosine distance thresholds for search.
	 *
	 * @since 0.2.0
	 */
	const THRESHOLD_KEYS = array( 'max_distance', 'max_relative_distance' );

	/**
	 * Default thresholds, used until the admin saves model-specific values.
	 *
	 * @since 0.2.0
	 */
	const DEFAULT_THRESHOLDS = array(
		'max_distance'          => 0.65,
		'max_relative_distance' => 0.25,
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
	 * @return array{max_distance: float, max_relative_distance: float}
	 */
	public static function get_thresholds(): array {
		$settings = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$thresholds = array();
		foreach ( self::DEFAULT_THRESHOLDS as $key => $default ) {
			$thresholds[ $key ] = isset( $settings[ $key ] ) && self::is_valid_distance( $settings[ $key ] )
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
	 * @return string[] The saved post types that are still available; without a
	 *                  saved list, all available post types.
	 */
	public static function get_post_types(): array {
		$settings  = get_option( self::OPTION_NAME, array() );
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
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'provider'                   => array(
								'type' => 'string',
							),
							'model'                      => array(
								'type' => 'string',
							),
							'dimensions'                 => array(
								'type' => 'integer',
							),
							'max_distance'               => array(
								'type'    => 'number',
								'minimum' => 0,
								'maximum' => 2,
							),
							'max_relative_distance'      => array(
								'type'    => 'number',
								'minimum' => 0,
								'maximum' => 2,
							),
							'exclude_password_protected' => array(
								'type' => 'boolean',
							),
							'hybrid'                     => array(
								'type' => 'boolean',
							),
							'post_types'                 => array(
								'type'  => 'array',
								'items' => array(
									'type' => 'string',
								),
							),
							'query_prefix'               => array(
								'type' => 'string',
							),
							'document_prefix'            => array(
								'type' => 'string',
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $input Sanitized input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$existing = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$sanitized = array();

		if ( isset( $input['provider'] ) && is_string( $input['provider'] ) ) {
			$sanitized['provider'] = sanitize_text_field( $input['provider'] );
		} elseif ( isset( $existing['provider'] ) && is_string( $existing['provider'] ) ) {
			$sanitized['provider'] = $existing['provider'];
		}

		if ( isset( $input['model'] ) && is_string( $input['model'] ) ) {
			$sanitized['model'] = sanitize_text_field( $input['model'] );
		} elseif ( isset( $existing['model'] ) && is_string( $existing['model'] ) ) {
			$sanitized['model'] = $existing['model'];
		}

		if ( isset( $input['dimensions'] ) && is_numeric( $input['dimensions'] ) && (int) $input['dimensions'] >= 1 ) {
			$sanitized['dimensions'] = (int) $input['dimensions'];
		} elseif ( isset( $existing['dimensions'] ) && is_numeric( $existing['dimensions'] ) ) {
			$sanitized['dimensions'] = (int) $existing['dimensions'];
		}

		foreach ( self::THRESHOLD_KEYS as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				// Present: a valid value is stored; null or invalid clears it.
				if ( self::is_valid_distance( $input[ $key ] ) ) {
					$sanitized[ $key ] = (float) $input[ $key ];
				}
			} elseif ( isset( $existing[ $key ] ) && self::is_valid_distance( $existing[ $key ] ) ) {
				$sanitized[ $key ] = (float) $existing[ $key ];
			}
		}

		if ( isset( $input['exclude_password_protected'] ) ) {
			$sanitized['exclude_password_protected'] = rest_sanitize_boolean( $input['exclude_password_protected'] );
		} elseif ( isset( $existing['exclude_password_protected'] ) ) {
			$sanitized['exclude_password_protected'] = (bool) $existing['exclude_password_protected'];
		}

		if ( isset( $input['hybrid'] ) && is_bool( $input['hybrid'] ) ) {
			$sanitized['hybrid'] = $input['hybrid'];
		} elseif ( isset( $existing['hybrid'] ) && is_bool( $existing['hybrid'] ) ) {
			$sanitized['hybrid'] = $existing['hybrid'];
		}

		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$sanitized['post_types'] = array_values(
				array_unique( array_map( 'sanitize_key', array_filter( $input['post_types'], 'is_string' ) ) )
			);
		} elseif ( isset( $existing['post_types'] ) && is_array( $existing['post_types'] ) ) {
			$sanitized['post_types'] = $existing['post_types'];
		}

		foreach ( self::PREFIX_KEYS as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				// Present: a non-empty string is stored; null or empty clears it.
				$prefix = is_string( $input[ $key ] ) ? self::sanitize_prefix( $input[ $key ] ) : '';
				if ( '' !== $prefix ) {
					$sanitized[ $key ] = $prefix;
				}
			} elseif ( isset( $existing[ $key ] ) && is_string( $existing[ $key ] ) ) {
				$sanitized[ $key ] = $existing[ $key ];
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
	 * Whether a value is a cosine distance (0–2).
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value Value to check.
	 * @return bool
	 */
	private static function is_valid_distance( mixed $value ): bool {
		return is_numeric( $value ) && (float) $value >= 0.0 && (float) $value <= 2.0;
	}
}
