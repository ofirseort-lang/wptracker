<?php
defined( 'ABSPATH' ) || exit;

/**
 * A small authenticated health/diagnostics endpoint for this plugin's own
 * footprint — NOT general server monitoring. It can only see what runs inside a
 * PHP request to this site: system load average, PHP's own memory usage, this
 * plugin's table size, and errors caught inside this plugin's own code paths.
 * It has no visibility into other plugins, the web server, or MySQL's own slow
 * query log — that data only exists in the server's own logs (SSH / hosting
 * panel), not through any WordPress API.
 */
class RT_Health {

    const ERROR_LOG_OPTION = 'rtst_recent_errors';
    const MAX_LOGGED_ERRORS = 20;
    const LOG_THROTTLE_SECONDS = 10;

    public function init(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route( 'rt-tracker/v1', '/health', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_health' ],
            'permission_callback' => function () {
                // Same capability the Tools -> Source Tracker admin page requires.
                // Works for both a logged-in wp-admin session and an Application
                // Password request authenticated as an administrator.
                return current_user_can( RT_Admin::CAPABILITY );
            },
        ] );
    }

    public function handle_health( WP_REST_Request $req ): WP_REST_Response {
        return new WP_REST_Response( self::get_status(), 200 );
    }

    /**
     * Assembled status snapshot — shared by the REST endpoint and the Tools ->
     * Source Tracker admin page, so both show the same data instead of the health
     * data being invisible unless someone knows to call the raw REST endpoint.
     */
    public static function get_status(): array {
        $load = function_exists( 'sys_getloadavg' ) ? sys_getloadavg() : null;

        return [
            'plugin_version' => RTST_VERSION,
            'timestamp'      => current_time( 'mysql' ),
            'load_avg'       => $load ? [
                '1m'  => $load[0],
                '5m'  => $load[1],
                '15m' => $load[2],
            ] : null,
            'php' => [
                'version'      => PHP_VERSION,
                'memory_usage' => memory_get_usage(),
                'memory_peak'  => memory_get_peak_usage(),
                'memory_limit' => ini_get( 'memory_limit' ),
            ],
            'table'         => RT_Database::get_table_stats(),
            'recent_errors' => get_option( self::ERROR_LOG_OPTION, [] ),
        ];
    }

    /**
     * Record an exception caught inside this plugin's own code (REST handlers,
     * form hooks, DB inserts) so it shows up in get_status() instead of either
     * fataling the request or vanishing silently.
     */
    public static function log_error( string $context, \Throwable $e ): void {
        self::log_issue( $context, sprintf( '%s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine() ) );
    }

    /**
     * Record a non-exception failure (e.g. $wpdb->insert() returning false, which
     * never throws) — same ring buffer as log_error(), for failures that don't
     * come as a Throwable.
     *
     * Throttled: a sustained failure (e.g. a DB outage making every insert fail)
     * would otherwise turn every single pageview/submission request into its own
     * get_option()+update_option() write — extra load on the exact thing that's
     * already failing, and a non-atomic read-modify-write besides (concurrent
     * requests can race and drop each other's entries). At most one persisted
     * write per LOG_THROTTLE_SECONDS; error_log() below still fires every time
     * when WP_DEBUG is on, so nothing is lost from the server's own log during
     * that window, only from this option-backed ring buffer.
     */
    public static function log_issue( string $context, string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( 'RT Source Tracker [%s]: %s', $context, $message ) );
        }

        $lock_key = 'rtst_health_log_lock';
        if ( get_transient( $lock_key ) ) {
            return;
        }
        set_transient( $lock_key, 1, self::LOG_THROTTLE_SECONDS );

        $entries   = get_option( self::ERROR_LOG_OPTION, [] );
        $entries[] = [
            'time'    => current_time( 'mysql' ),
            'context' => $context,
            'message' => $message,
        ];
        if ( count( $entries ) > self::MAX_LOGGED_ERRORS ) {
            $entries = array_slice( $entries, -self::MAX_LOGGED_ERRORS );
        }
        update_option( self::ERROR_LOG_OPTION, $entries, false );
    }
}
