<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings. Nothing site-specific is hard-coded: the defaults are
 * discovered from the site, and the wizard (not built yet) lets the installer
 * change them.
 */
class RT_LLMS_Settings {

	const OPTION = 'rt_llms_settings';

	public static function defaults() {
		return array(
			'post_types'  => self::discover_post_types(),
			'run_hour'    => 23,
			'max_age'     => 3600,
			'len_program' => 170,
			'len_short'   => 120,
			'len_full'    => 300,
			'title'       => get_bloginfo( 'name' ),
			'summary'     => get_bloginfo( 'description' ),
			'facts'       => array(),
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/** Validate and store the settings form (FR-22). Unknown keys are ignored. */
	public static function save( array $in ) {
		$old = self::get();
		$new = $old;

		foreach ( array( 'title', 'summary' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$new[ $k ] = sanitize_textarea_field( (string) $in[ $k ] );
			}
		}

		$allowed           = self::discover_post_types();
		$new['post_types'] = isset( $in['post_types'] ) && is_array( $in['post_types'] )
			? array_values( array_intersect( array_map( 'sanitize_key', $in['post_types'] ), $allowed ) )
			: array();

		$new['run_hour']    = self::int_in( $in, 'run_hour', 0, 23, $old['run_hour'] );
		$new['max_age']     = self::int_in( $in, 'max_age', 60, 86400, $old['max_age'] );
		$new['len_short']   = self::int_in( $in, 'len_short', 40, 1000, $old['len_short'] );
		$new['len_full']    = self::int_in( $in, 'len_full', 40, 1000, $old['len_full'] );

		$facts = array();
		if ( isset( $in['facts'] ) && is_array( $in['facts'] ) ) {
			foreach ( array_slice( $in['facts'], 0, 40 ) as $row ) {
				$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
				$value = isset( $row['value'] ) ? sanitize_text_field( (string) $row['value'] ) : '';
				if ( '' !== $label && '' !== $value ) {
					$facts[] = array( 'label' => $label, 'value' => $value );
				}
			}
		}
		$new['facts'] = $facts;

		update_option( self::OPTION, $new, true );
		return $new;
	}

	private static function int_in( array $in, $key, $min, $max, $fallback ) {
		if ( ! isset( $in[ $key ] ) || ! is_numeric( $in[ $key ] ) ) {
			return (int) $fallback;
		}
		return max( $min, min( $max, (int) $in[ $key ] ) );
	}

	/** All public, viewable post types except attachments (FR-7). */
	public static function discover_post_types() {
		$types = get_post_types( array( 'public' => true, 'publicly_queryable' => true ), 'names' );
		unset( $types['attachment'] );
		// Pages are public but not "publicly_queryable" on some setups.
		if ( post_type_exists( 'page' ) ) {
			$types['page'] = 'page';
		}
		return array_values( $types );
	}
}
