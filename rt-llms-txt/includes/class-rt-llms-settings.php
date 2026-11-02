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
