<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reads the meta description, robots and canonical tags from the page as it is
 * actually rendered (FR-10), so no SEO plugin's stored fields are needed.
 * Results are stored per post and refreshed only when the post changed.
 */
class RT_LLMS_Fetcher {

	const META = '_rt_llms_meta';

	/** @return array{desc:string,robots:string,canonical:string,ok:bool,modified:string} */
	public static function get( $post ) {
		$post   = get_post( $post );
		$stored = get_post_meta( $post->ID, self::META, true );
		if ( is_array( $stored ) && isset( $stored['modified'] ) && $stored['modified'] === $post->post_modified_gmt ) {
			return $stored;
		}
		return self::refresh( $post );
	}

	public static function refresh( $post ) {
		$post = get_post( $post );
		$out  = array(
			'desc'      => '',
			'robots'    => '',
			'canonical' => '',
			'ok'        => false,
			'modified'  => $post->post_modified_gmt,
		);

		$response = wp_remote_get(
			get_permalink( $post ),
			array(
				'timeout'    => 10,
				'user-agent' => 'RT-LLMS-Fetcher/' . RT_LLMS_VERSION,
				'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$html = wp_remote_retrieve_body( $response );
			// Only the head is needed.
			$end = stripos( $html, '</head>' );
			if ( false !== $end ) {
				$html = substr( $html, 0, $end );
			}
			$out['desc']      = self::meta_content( $html, 'name', 'description' );
			$out['robots']    = self::meta_content( $html, 'name', 'robots' );
			$out['canonical'] = self::link_href( $html, 'canonical' );
			$out['ok']        = true;
		}

		update_post_meta( $post->ID, self::META, $out );
		return $out;
	}

	private static function meta_content( $html, $attr, $value ) {
		if ( ! preg_match_all( '/<meta\b[^>]*>/i', $html, $tags ) ) {
			return '';
		}
		foreach ( $tags[0] as $tag ) {
			if ( preg_match( '/\b' . $attr . '\s*=\s*["\']' . preg_quote( $value, '/' ) . '["\']/i', $tag )
				&& preg_match( '/\bcontent\s*=\s*(["\'])(.*?)\1/is', $tag, $m ) ) {
				return trim( html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
		}
		return '';
	}

	private static function link_href( $html, $rel ) {
		if ( ! preg_match_all( '/<link\b[^>]*>/i', $html, $tags ) ) {
			return '';
		}
		foreach ( $tags[0] as $tag ) {
			if ( preg_match( '/\brel\s*=\s*["\']' . preg_quote( $rel, '/' ) . '["\']/i', $tag )
				&& preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/is', $tag, $m ) ) {
				return trim( html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
		}
		return '';
	}
}
