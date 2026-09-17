<?php
defined( 'ABSPATH' ) || exit;

class RT_Database {

    const TABLE_NAME = 'rt_source_tracker';

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    public static function create_table(): void {
        global $wpdb;
        $table      = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type     VARCHAR(20)  NOT NULL,
            page_url       TEXT         NOT NULL,
            form_plugin    VARCHAR(50)  DEFAULT NULL,
            source_channel VARCHAR(30)  NOT NULL,
            utm_source     VARCHAR(100) DEFAULT NULL,
            utm_medium     VARCHAR(100) DEFAULT NULL,
            utm_campaign   VARCHAR(100) DEFAULT NULL,
            referrer       TEXT         DEFAULT NULL,
            ip_hash        VARCHAR(64)  DEFAULT NULL,
            session_id     VARCHAR(20)  DEFAULT NULL,
            is_bot         TINYINT(1)   NOT NULL DEFAULT 0,
            created_at     DATETIME     NOT NULL,
            PRIMARY KEY (id),
            KEY idx_event_type     (event_type),
            KEY idx_source_channel (source_channel),
            KEY idx_session_id     (session_id),
            KEY idx_is_bot         (is_bot),
            KEY idx_created_at     (created_at),
            KEY idx_bot_created    (is_bot, created_at),
            KEY idx_session_created (session_id, created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'rtst_db_version', RTST_VERSION );
    }

    public static function insert_event( array $data ): bool {
        global $wpdb;

        $row = [
            'event_type'     => sanitize_text_field( $data['event_type'] ?? 'pageview' ),
            'page_url'       => esc_url_raw( $data['page_url'] ?? '' ),
            'form_plugin'    => isset( $data['form_plugin'] ) ? sanitize_text_field( $data['form_plugin'] ) : null,
            'source_channel' => sanitize_text_field( $data['source_channel'] ?? 'direct' ),
            'utm_source'     => isset( $data['utm_source'] )   ? sanitize_text_field( $data['utm_source'] )   : null,
            'utm_medium'     => isset( $data['utm_medium'] )   ? sanitize_text_field( $data['utm_medium'] )   : null,
            'utm_campaign'   => isset( $data['utm_campaign'] ) ? sanitize_text_field( $data['utm_campaign'] ) : null,
            'referrer'       => isset( $data['referrer'] )     ? esc_url_raw( $data['referrer'] )             : null,
            'ip_hash'        => isset( $data['ip'] )           ? hash_hmac( 'sha256', $data['ip'], wp_salt( 'auth' ) ) : null,
            'session_id'     => isset( $data['session_id'] )   ? sanitize_text_field( $data['session_id'] )   : null,
            'is_bot'         => isset( $data['is_bot'] )       ? (int) $data['is_bot']                        : 0,
            'created_at'     => current_time( 'mysql' ),
        ];

        $result = $wpdb->insert( self::table(), $row );
        return $result !== false;
    }

    public static function get_events( array $filters = [], int $page = 1, int $per_page = 20 ): array {
        global $wpdb;
        $table  = self::table();
        $where  = self::build_where( $filters );
        $offset = ( $page - 1 ) * $per_page;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );
    }

    public static function count_events( array $filters = [] ): int {
        global $wpdb;
        $table = self::table();
        $where = self::build_where( $filters );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
    }

    public static function get_channel_summary( array $filters = [] ): array {
        global $wpdb;
        $table = self::table();
        $where = self::build_where( $filters );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT source_channel, event_type, COUNT(*) AS total
             FROM {$table}
             {$where}
             GROUP BY source_channel, event_type
             ORDER BY total DESC",
            ARRAY_A
        );

        $summary = [];
        foreach ( $rows as $row ) {
            $ch = $row['source_channel'];
            if ( ! isset( $summary[ $ch ] ) ) {
                $summary[ $ch ] = [ 'pageviews' => 0, 'submissions' => 0 ];
            }
            if ( $row['event_type'] === 'pageview' ) {
                $summary[ $ch ]['pageviews'] += (int) $row['total'];
            } else {
                $summary[ $ch ]['submissions'] += (int) $row['total'];
            }
        }

        return $summary;
    }

    public static function get_all_for_export( array $filters = [], int $limit = 50000 ): array {
        global $wpdb;
        $table = self::table();
        $where = self::build_where( $filters );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d", $limit ),
            ARRAY_A
        );
    }

    public static function get_top_referrer_domains( array $filters = [], int $limit = 10 ): array {
        global $wpdb;
        $table = self::table();
        $where = self::build_where( $filters );
        $extra = $where ? 'AND referrer IS NOT NULL AND referrer != ""' : 'WHERE referrer IS NOT NULL AND referrer != ""';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    SUBSTRING_INDEX( SUBSTRING_INDEX( SUBSTRING_INDEX( referrer, '://', -1 ), '/', 1 ), '?', 1 ) AS domain,
                    COUNT(*) AS total
                 FROM {$table}
                 {$where} {$extra}
                 GROUP BY domain
                 ORDER BY total DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function get_direct_entry_pages( array $filters = [], int $limit = 10 ): array {
        global $wpdb;
        $table         = self::table();
        $direct_filters = array_merge( $filters, [ 'channel' => 'direct', 'event_type' => 'pageview' ] );
        $where         = self::build_where( $direct_filters );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT page_url, COUNT(*) AS total
                 FROM {$table}
                 {$where}
                 GROUP BY page_url
                 ORDER BY total DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function purge_events_older_than( int $days ): int {
        global $wpdb;
        $table  = self::table();
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
        return (int) $wpdb->rows_affected;
    }

    public static function get_sessions( array $filters = [], int $page = 1, int $per_page = 20 ): array {
        global $wpdb;
        $table  = self::table();
        $where  = self::build_session_where( $filters );
        $offset = ( $page - 1 ) * $per_page;

        // entry_page used to be pulled via GROUP_CONCAT(page_url ORDER BY created_at) +
        // SUBSTRING_INDEX, which concatenates every page_url in the session before
        // taking the first one — expensive for long sessions and repeated on every
        // 30s admin auto-refresh. A correlated subquery (backed by
        // idx_session_created) only ever reads the one row it needs.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    e.session_id,
                    MIN(e.created_at)              AS first_seen,
                    MAX(e.created_at)              AS last_seen,
                    MIN(e.source_channel)          AS source_channel,
                    SUM(e.event_type = 'pageview')   AS pageviews,
                    SUM(e.event_type = 'submission') AS submissions,
                    (
                        SELECT e2.page_url FROM {$table} e2
                        WHERE e2.session_id = e.session_id
                        ORDER BY e2.created_at ASC
                        LIMIT 1
                    ) AS entry_page
                 FROM {$table} e
                 {$where}
                 GROUP BY e.session_id
                 ORDER BY first_seen DESC
                 LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );
    }

    public static function count_sessions( array $filters = [] ): int {
        global $wpdb;
        $table = self::table();
        $where = self::build_session_where( $filters );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT session_id) FROM {$table} {$where}" );
    }

    private static function build_session_where( array $filters ): string {
        global $wpdb;
        $clauses = [ 'session_id IS NOT NULL' ];

        if ( ! empty( $filters['date_from'] ) ) {
            $clauses[] = $wpdb->prepare( 'created_at >= %s', $filters['date_from'] . ' 00:00:00' );
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $clauses[] = $wpdb->prepare( 'created_at <= %s', $filters['date_to'] . ' 23:59:59' );
        }
        if ( ! empty( $filters['channel'] ) ) {
            $clauses[] = $wpdb->prepare( 'source_channel = %s', $filters['channel'] );
        }
        if ( isset( $filters['show_bots'] ) && $filters['show_bots'] !== 'all' ) {
            $clauses[] = $filters['show_bots'] === 'bot' ? 'is_bot = 1' : 'is_bot = 0';
        }

        return 'WHERE ' . implode( ' AND ', $clauses );
    }

    private static function build_where( array $filters ): string {
        global $wpdb;
        $clauses = [];

        if ( ! empty( $filters['date_from'] ) ) {
            $clauses[] = $wpdb->prepare( 'created_at >= %s', $filters['date_from'] . ' 00:00:00' );
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $clauses[] = $wpdb->prepare( 'created_at <= %s', $filters['date_to'] . ' 23:59:59' );
        }
        if ( ! empty( $filters['channel'] ) ) {
            $clauses[] = $wpdb->prepare( 'source_channel = %s', $filters['channel'] );
        }
        if ( ! empty( $filters['event_type'] ) ) {
            $clauses[] = $wpdb->prepare( 'event_type = %s', $filters['event_type'] );
        }
        if ( ! empty( $filters['referrer_domain'] ) ) {
            $clauses[] = $wpdb->prepare( 'referrer LIKE %s', '%' . $wpdb->esc_like( $filters['referrer_domain'] ) . '%' );
        }
        if ( isset( $filters['show_bots'] ) && $filters['show_bots'] !== 'all' ) {
            $clauses[] = $filters['show_bots'] === 'bot' ? 'is_bot = 1' : 'is_bot = 0';
        }

        return $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';
    }
}
