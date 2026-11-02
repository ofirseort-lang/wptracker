<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stores each generated file (FR-23). Options, not transients: the files are
 * large and must survive object-cache flushes. autoload is off.
 */
class RT_LLMS_Cache {

	const KEYS = array( 'short', 'full' );

	public static function get( $key ) {
		$row = get_option( 'rt_llms_cache_' . $key, null );
		return ( is_array( $row ) && isset( $row['text'], $row['hash'], $row['time'] ) ) ? $row : null;
	}

	public static function put( $key, $text ) {
		$row = array(
			'text' => $text,
			'hash' => md5( $text ),
			'time' => time(),
		);
		update_option( 'rt_llms_cache_' . $key, $row, false );
		return $row;
	}

	/**
	 * Rebuild both files from current data. Returns false when another
	 * process holds the lock (FR-25); callers keep serving the previous copy.
	 */
	public static function rebuild() {
		if ( ! self::lock() ) {
			return false;
		}
		try {
			$builder = new RT_LLMS_Builder( RT_LLMS_Settings::get() );
			foreach ( self::KEYS as $key ) {
				$text = $builder->build( $key );
				$old  = self::get( $key );
				// Keep the old timestamp when nothing changed, so ETag and
				// Last-Modified stay stable (FR-17).
				if ( $old && $old['hash'] === md5( $text ) ) {
					continue;
				}
				self::put( $key, $text );
			}
		} finally {
			self::unlock();
		}
		return true;
	}

	private static function lock() {
		// add_option is atomic: it fails if the row already exists.
		if ( add_option( 'rt_llms_lock', time(), '', false ) ) {
			return true;
		}
		// Stale lock (a fatal in the middle of a build): take it over after 2 min.
		if ( (int) get_option( 'rt_llms_lock', 0 ) < time() - 120 ) {
			update_option( 'rt_llms_lock', time(), false );
			return true;
		}
		return false;
	}

	private static function unlock() {
		delete_option( 'rt_llms_lock' );
	}
}
