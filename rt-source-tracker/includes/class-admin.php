<?php
defined( 'ABSPATH' ) || exit;

class RT_Admin {

    const PER_PAGE = 20;

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_csv_export' ] );
        add_action( 'admin_init', [ $this, 'handle_retention' ] );
    }

    public function add_menu(): void {
        add_management_page(
            __( 'Source Tracker', 'rt-source-tracker' ),
            __( 'Source Tracker', 'rt-source-tracker' ),
            'manage_options',
            'rt-source-tracker',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $filters    = $this->get_filters();
        $tab        = ( isset( $_GET['tab'] ) && $_GET['tab'] === 'sessions' ) ? 'sessions' : 'events';
        $page       = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $summary    = RT_Database::get_channel_summary( $filters );

        $events      = $tab === 'events' ? RT_Database::get_events( $filters, $page, self::PER_PAGE ) : [];
        $total       = $tab === 'events' ? RT_Database::count_events( $filters ) : 0;
        $total_pages = (int) ceil( $total / self::PER_PAGE );

        $sessions       = $tab === 'sessions' ? RT_Database::get_sessions( $filters, $page, self::PER_PAGE ) : [];
        $session_total  = $tab === 'sessions' ? RT_Database::count_sessions( $filters ) : 0;
        $session_pages  = (int) ceil( $session_total / self::PER_PAGE );

        $top_referrers = RT_Database::get_top_referrer_domains( $filters );
        $direct_pages  = RT_Database::get_direct_entry_pages( $filters );

        include RTST_PLUGIN_DIR . 'templates/admin-page.php';
    }

    public function handle_csv_export(): void {
        if ( empty( $_GET['rt_export_csv'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'rt_export_csv' ) ) {
            wp_die( 'Security check failed.' );
        }

        $filters = $this->get_filters();
        $rows    = RT_Database::get_all_for_export( $filters );

        $filename = 'rt_source_tracker_export_' . gmdate( 'Ymd_His' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );

        fputcsv( $out, [ 'ID', 'Date', 'Type', 'Channel', 'Page URL', 'UTM Source', 'UTM Medium', 'Campaign', 'Form Plugin', 'Referrer' ] );

        foreach ( $rows as $row ) {
            fputcsv( $out, array_map( [ $this, 'csv_safe_cell' ], [
                $row['id'],
                $row['created_at'],
                $row['event_type'],
                RT_Classifier::channel_label( $row['source_channel'] ),
                $row['page_url'],
                $row['utm_source'] ?? '',
                $row['utm_medium'] ?? '',
                $row['utm_campaign'] ?? '',
                $row['form_plugin'] ?? '',
                $row['referrer'] ?? '',
            ] ) );
        }

        fclose( $out );
        exit;
    }

    /**
     * Neutralize CSV/formula injection: several row values (UTM params, referrer)
     * are attacker-controllable via the public tracking endpoints. A value starting
     * with =, +, -, @, tab, or CR is interpreted as a formula by Excel/Sheets when
     * the export is opened, so prefix those with a single quote to force text mode.
     */
    private function csv_safe_cell( $value ): string {
        $value = (string) $value;
        if ( $value !== '' && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
            return "'" . $value;
        }
        return $value;
    }

    public function handle_retention(): void {
        if ( ( empty( $_POST['rt_save_retention'] ) && empty( $_POST['rt_purge_now'] ) )
            || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_retention'] ?? '' ) ), 'rt_retention_settings' ) ) {
            wp_die( 'Security check failed.' );
        }

        $days = max( 1, (int) ( $_POST['rtst_retention_days'] ?? 365 ) );
        update_option( 'rtst_retention_days', $days );

        if ( ! empty( $_POST['rt_purge_now'] ) ) {
            $count = RT_Database::purge_events_older_than( $days );
            set_transient( 'rtst_admin_notice', sprintf(
                /* translators: 1: events deleted, 2: age in days */
                __( 'Purged %1$d events older than %2$d days.', 'rt-source-tracker' ),
                $count,
                $days
            ), 30 );
        }

        wp_safe_redirect( add_query_arg( 'page', 'rt-source-tracker', admin_url( 'tools.php' ) ) );
        exit;
    }

    private function get_filters(): array {
        return [
            'date_from'      => sanitize_text_field( $_GET['date_from'] ?? '' ),
            'date_to'        => sanitize_text_field( $_GET['date_to'] ?? '' ),
            'channel'        => sanitize_text_field( $_GET['channel'] ?? '' ),
            'event_type'     => sanitize_text_field( $_GET['event_type'] ?? '' ),
            'show_bots'      => sanitize_text_field( $_GET['show_bots'] ?? 'human' ),
            'referrer_domain' => sanitize_text_field( $_GET['referrer_domain'] ?? '' ),
        ];
    }
}
