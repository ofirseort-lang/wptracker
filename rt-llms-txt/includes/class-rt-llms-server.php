<?php
defined( 'ABSPATH' ) || exit;

/**
 * Serves /llms.txt and /llms-full.txt (FR-1..FR-6).
 *
 * Hooked early on init and matched on the request path, so it needs no rewrite
 * rules to flush. If the web server finds a physical file with the same name
 * it answers before WordPress runs; that case is a conflict the wizard reports
 * (FR-27, FR-31).
 */
class RT_LLMS_Server {

	const FILES = array(
		'/llms.txt'      => 'short',
		'/llms-full.txt' => 'full',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 1 );
	}

	public static function maybe_serve() {
		$key = self::requested();
		if ( null === $key ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			status_header( 405 );
			header( 'Allow: GET, HEAD' );
			exit;
		}

		$row = RT_LLMS_Cache::get( $key );
		if ( ! $row ) {
			RT_LLMS_Cache::rebuild();
			$row = RT_LLMS_Cache::get( $key );
		}
		if ( ! $row ) {
			// Build failed or another process is still building: ask crawlers to retry.
			status_header( 503 );
			header( 'Retry-After: 60' );
			exit;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // keep page-cache plugins out of the way.
		}

		$etag     = '"' . $row['hash'] . '"';
		$modified = gmdate( 'D, d M Y H:i:s', (int) $row['time'] ) . ' GMT';
		$max_age  = (int) RT_LLMS_Settings::get()['max_age'];

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=' . $max_age );
		header( 'ETag: ' . $etag );
		header( 'Last-Modified: ' . $modified );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-RT-LLMS: ' . RT_LLMS_VERSION );

		$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : '';
		$ims = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) ) : false;
		if ( ( '' !== $inm && $inm === $etag ) || ( '' === $inm && $ims && $ims >= (int) $row['time'] ) ) {
			status_header( 304 );
			exit;
		}

		status_header( 200 );
		header( 'Content-Length: ' . strlen( $row['text'] ) );
		if ( 'HEAD' !== $method ) {
			echo $row['text']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text file, not HTML.
		}
		exit;
	}

	/** @return string|null 'short', 'full' or null when the request is something else. */
	private static function requested() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return null;
		}
		$path = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$base = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return null;
		}
		$path = '/' . ltrim( substr( $path, strlen( rtrim( $base, '/' ) ) ), '/' );
		return self::FILES[ $path ] ?? null;
	}
}
