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
	 */
	const THRESHOLD_KEYS = array( 'max_distance', 'max_relative_distance' );

	/**
	 * Default thresholds, used until the admin saves model-specific values.
	 */
	const DEFAULT_THRESHOLDS = array(
		'max_distance'          => 0.65,
		'max_relative_distance' => 0.25,
	);

	/**
	 * Saved distance thresholds, falling back to the defaults.
	 *
	 * Filters are not applied here; see Search::rewrite_search_query().
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
							'provider'              => array(
								'type' => 'string',
							),
							'model'                 => array(
								'type' => 'string',
							),
							'dimensions'            => array(
								'type' => 'integer',
							),
							'max_distance'          => array(
								'type'    => 'number',
								'minimum' => 0,
								'maximum' => 2,
							),
							'max_relative_distance' => array(
								'type'    => 'number',
								'minimum' => 0,
								'maximum' => 2,
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

		return $sanitized;
	}

	/**
	 * Whether a value is a cosine distance (0–2).
	 *
	 * @param mixed $value Value to check.
	 * @return bool
	 */
	private static function is_valid_distance( mixed $value ): bool {
		return is_numeric( $value ) && (float) $value >= 0.0 && (float) $value <= 2.0;
	}
}
