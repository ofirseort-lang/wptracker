<?php
defined( 'ABSPATH' ) || exit;

/**
 * Nightly job (FR-24) and immediate removals (FR-24a).
 *
 * The nightly run fetches meta tags for new/changed posts, records the diff
 * against the previous run, and rebuilds both files completely. Appending to
 * the old file is deliberately not done: entries are grouped and sorted.
 */
class RT_LLMS_Scheduler {

	const HOOK      = 'rt_llms_nightly';
	const HOOK_MORE = 'rt_llms_nightly_more';
	const BATCH     = 150;
	const LOG_LIMIT = 30;

	private static $dirty = false;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::HOOK_MORE, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'catch_up' ) );

		// FR-24a: take unpublished/deleted content out right away.
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush_dirty' ) );
	}

	public static function activate() {
		self::schedule_next();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::HOOK_MORE );
	}

	/** Next run at the configured hour in the site timezone; recomputed each night so DST is followed. */
	public static function schedule_next() {
		wp_clear_scheduled_hook( self::HOOK );
		$hour = (int) RT_LLMS_Settings::get()['run_hour'];
		$next = new DateTimeImmutable( 'today ' . sprintf( '%02d:00', $hour ), wp_timezone() );
		if ( $next->getTimestamp() <= time() ) {
			$next = $next->modify( '+1 day' );
		}
		wp_schedule_single_event( $next->getTimestamp(), self::HOOK );
	}

	/** WP-Cron only fires on traffic: if the last run is over 25 h old, run it on the next request. */
	public static function catch_up() {
		$last = (int) get_option( 'rt_llms_last_run', 0 );
		if ( $last && $last > time() - 25 * HOUR_IN_SECONDS ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK_MORE ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK_MORE );
		}
	}

	public static function run() {
		$ids = get_posts(
			array(
				'post_type'      => RT_LLMS_Settings::get()['post_types'],
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		// Fetch meta tags for new and changed posts only, in batches (FR-10).
		$fetched = 0;
		foreach ( $ids as $id ) {
			$meta = get_post_meta( $id, RT_LLMS_Fetcher::META, true );
			if ( is_array( $meta ) && isset( $meta['modified'] ) && get_post( $id )->post_modified_gmt === $meta['modified'] ) {
				continue;
			}
			if ( $fetched >= self::BATCH ) {
				// More left: continue shortly instead of risking a timeout.
				if ( ! wp_next_scheduled( self::HOOK_MORE ) ) {
					wp_schedule_single_event( time() + 120, self::HOOK_MORE );
				}
				return;
			}
			RT_LLMS_Fetcher::refresh( $id );
			++$fetched;
		}

		$diff = self::diff( $ids );
		RT_LLMS_Cache::rebuild();
		self::log( $diff, $fetched );

		update_option( 'rt_llms_last_run', time(), true );
		self::schedule_next();
	}

	/** @return array{added:int[],removed:int[],changed:int[]} against the previous run's snapshot. */
	private static function diff( array $ids ) {
		$now = array();
		foreach ( $ids as $id ) {
			$p         = get_post( $id );
			$now[ $id ] = md5( $p->post_title . '|' . $p->post_name . '|' . $p->post_modified_gmt );
		}
		$prev = get_option( 'rt_llms_snapshot', array() );
		$prev = is_array( $prev ) ? $prev : array();

		$diff = array(
			'added'   => array_keys( array_diff_key( $now, $prev ) ),
			'removed' => array_keys( array_diff_key( $prev, $now ) ),
			'changed' => array(),
		);
		foreach ( array_intersect_key( $now, $prev ) as $id => $hash ) {
			if ( $prev[ $id ] !== $hash ) {
				$diff['changed'][] = $id;
			}
		}
		update_option( 'rt_llms_snapshot', $now, false );
		return $diff;
	}

	private static function log( array $diff, $fetched ) {
		$log   = get_option( 'rt_llms_log', array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'time'    => time(),
			'added'   => $diff['added'],
			'removed' => $diff['removed'],
			'changed' => $diff['changed'],
			'fetched' => $fetched,
		);
		update_option( 'rt_llms_log', array_slice( $log, -self::LOG_LIMIT ), false );
	}

	public static function on_transition( $new, $old, $post ) {
		if ( 'publish' === $old && 'publish' !== $new && in_array( $post->post_type, RT_LLMS_Settings::get()['post_types'], true ) ) {
			self::$dirty = true;
		}
	}

	public static function on_delete( $post_id ) {
		$post = get_post( $post_id );
		if ( $post && 'publish' === $post->post_status && in_array( $post->post_type, RT_LLMS_Settings::get()['post_types'], true ) ) {
			self::$dirty = true;
		}
	}

	/** One rebuild per request, however many posts changed. */
	public static function flush_dirty() {
		if ( self::$dirty ) {
			self::$dirty = false;
			RT_LLMS_Cache::rebuild();
		}
	}
}
