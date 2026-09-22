<?php
/**
 * Plugin Name: RT Source Tracker
 * Plugin URI:  https://realtimecollege.co.il
 * Description: Tracks visitor traffic sources (organic, paid, social, LLM/AI) on every page and ties them to form submissions. Supports CF7, Gravity Forms, WPForms, and generic HTML forms.
 * Version:     1.1.1
 * Author:      Real Time College
 * License:     GPL-2.0-or-later
 * Text Domain: rt-source-tracker
 *
 * Changelog:
 *   1.1.1 - Fixed a 1.1.0 regression where a bot's REST submission could claim the
 *           dedup slot and suppress the real form-hook row (bot check now runs
 *           before the dedup claim); added the same bot-skip guard to the
 *           form-hook fallback path, which had none; entry_page in the sessions
 *           report now respects the active date/channel/bot filters again;
 *           CF7 fallback now fires on wpcf7_submit (covers failed-mail
 *           submissions, not just successfully-mailed ones); fixed retention
 *           purge comparing a UTC cutoff against site-local timestamps; same-site
 *           referrers no longer misclassify as "referral", and tracker.js now
 *           sends its session-inherited channel so internal navigation keeps the
 *           original first-touch source (same-site check now shared via
 *           RT_Classifier::is_own_host(), covering both home_url() and the actual
 *           request Host header); a bogus source_channel value from the form-hook
 *           hidden field is no longer stored verbatim; CSV export neutralizes
 *           formula-injection payloads; rate limiting is atomic when a persistent
 *           object cache is active, using wp_cache_add()+incr() so the counter
 *           reliably gets its expiry on creation regardless of backend.
 *   1.1.0 - Hardened client-IP resolution (proxy headers no longer trusted by
 *           default, closing a rate-limit bypass); tiered rate limits based on
 *           REST nonce validity; added rtst_record_bot_events filter to skip
 *           storing bot-flagged events; bot flag now recorded for server-side
 *           form-hook submissions too; added composite indexes and replaced a
 *           GROUP_CONCAT-based session query with a cheaper correlated
 *           subquery.
 */

defined( 'ABSPATH' ) || exit;

define( 'RTST_VERSION', '1.1.1' );
define( 'RTST_PLUGIN_FILE', __FILE__ );
define( 'RTST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RTST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once RTST_PLUGIN_DIR . 'includes/class-database.php';
require_once RTST_PLUGIN_DIR . 'includes/class-classifier.php';
require_once RTST_PLUGIN_DIR . 'includes/class-tracker.php';
require_once RTST_PLUGIN_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, [ 'RT_Database', 'create_table' ] );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'rtst_weekly_purge' );
} );

add_action( 'rtst_weekly_purge', function () {
    $days = (int) get_option( 'rtst_retention_days', 365 );
    if ( $days > 0 ) {
        RT_Database::purge_events_older_than( $days );
    }
} );

add_action( 'plugins_loaded', function () {
    if ( get_option( 'rtst_db_version' ) !== RTST_VERSION ) {
        RT_Database::create_table();
    }

    if ( ! wp_next_scheduled( 'rtst_weekly_purge' ) ) {
        wp_schedule_event( time(), 'weekly', 'rtst_weekly_purge' );
    }

    $tracker = new RT_Tracker();
    $tracker->init();

    $admin = new RT_Admin();
    $admin->init();
} );

add_action( 'admin_init', function () {
    wp_add_privacy_policy_content(
        'RT Source Tracker',
        wp_kses_post( sprintf(
            /* translators: %d: data retention period in days */
            __( 'This site uses RT Source Tracker to record how visitors arrive (search, social, ads, etc.). It stores the page URL, traffic source channel, UTM parameters, and a one-way hash of your IP address. No directly identifiable information (name, email) is stored. Data is automatically deleted after %d days.', 'rt-source-tracker' ),
            (int) get_option( 'rtst_retention_days', 365 )
        ) )
    );
} );

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_script(
        'rt-source-tracker',
        RTST_PLUGIN_URL . 'assets/tracker.js',
        [],
        RTST_VERSION,
        true
    );

    // Nonce is baked into cached HTML. REST endpoints use __return_true so a stale
    // nonce is harmless today — but tightening permission_callback later would break
    // tracking on cached pages without a dynamic nonce strategy.
    wp_localize_script( 'rt-source-tracker', 'rtTracker', [
        'restUrl'   => esc_url_raw( rest_url( 'rt-tracker/v1/' ) ),
        'nonce'     => wp_create_nonce( 'wp_rest' ),
        'pageUrl'   => esc_url_raw( home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ),
    ] );
} );
