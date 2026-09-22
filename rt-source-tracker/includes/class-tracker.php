<?php
defined( 'ABSPATH' ) || exit;

class RT_Tracker {

    public function init(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );

        // Contact Form 7 — wpcf7_submit fires for every attempt (mail sent, mail
        // failed, spam, validation failed), unlike wpcf7_mail_sent which only fires
        // once mail delivery succeeds. A visitor with JS blocked whose mail also
        // fails to send (SMTP down, etc.) would otherwise never be recorded at all.
        add_action( 'wpcf7_submit', [ $this, 'on_cf7_submit' ], 10, 2 );

        // Gravity Forms
        add_action( 'gform_after_submission', [ $this, 'on_gf_submit' ], 10, 2 );

        // WPForms
        add_action( 'wpforms_process_complete', [ $this, 'on_wpf_submit' ], 10, 4 );
    }

    // -------------------------------------------------------------------------
    // REST API
    // -------------------------------------------------------------------------

    public function register_routes(): void {
        register_rest_route( 'rt-tracker/v1', '/pageview', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_pageview' ],
            'permission_callback' => '__return_true',
            'args'                => $this->event_schema(),
        ] );

        register_rest_route( 'rt-tracker/v1', '/submission', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_submission' ],
            'permission_callback' => '__return_true',
            'args'                => $this->event_schema(),
        ] );
    }

    public function handle_pageview( WP_REST_Request $req ): WP_REST_Response {
        return $this->record_event( $req, 'pageview' );
    }

    public function handle_submission( WP_REST_Request $req ): WP_REST_Response {
        return $this->record_event( $req, 'submission' );
    }

    private function record_event( WP_REST_Request $req, string $event_type ): WP_REST_Response {
        $ip = $this->get_client_ip();

        // Nonce isn't required (cached pages serve a stale one, see wp_enqueue_scripts
        // callback), but a request presenting a valid one is far more likely to be a
        // real browser than a naive flood script — give it a much higher rate-limit
        // ceiling instead of an all-or-nothing accept/reject.
        $nonce         = $req->get_header( 'X-WP-Nonce' ) ?: $req->get_param( '_wpnonce' );
        $nonce_trusted = $nonce && wp_verify_nonce( $nonce, 'wp_rest' );

        if ( ! $this->check_rate_limit( $ip, $nonce_trusted ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'rate_limited' ], 429 );
        }

        // Bot rows are cheap individually, but on a bot-heavy site they add up to real
        // table growth and query cost for no reporting value beyond the raw count.
        // Skipped by default as of 1.1.0; sites that want the "Bots only" view
        // populated again can restore it with
        // `add_filter( 'rtst_record_bot_events', '__return_true' );`.
        // Checked before the dedup-token claim below: a bot's REST call must not
        // consume the token slot a real form-hook submission would otherwise need.
        $is_bot = $this->is_bot_request();
        if ( $is_bot && ! apply_filters( 'rtst_record_bot_events', false, $event_type ) ) {
            return new WP_REST_Response( [ 'ok' => true, 'channel' => 'bot_skipped' ], 200 );
        }

        // Dedup: if JS sent a token and the PHP server-side hook already inserted
        // this submission, skip to avoid a duplicate row.
        if ( $event_type === 'submission' ) {
            $token = sanitize_text_field( $req->get_param( 'submission_token' ) ?? '' );
            if ( $token ) {
                $tk_key = $this->dedup_key( $token );
                if ( get_transient( $tk_key ) ) {
                    return new WP_REST_Response( [ 'ok' => true, 'channel' => 'dedup' ], 200 );
                }
                set_transient( $tk_key, 'rest', 120 );
            }
        }

        $referrer     = esc_url_raw( $req->get_param( 'referrer' ) ?? '' );
        $utm_source   = sanitize_text_field( $req->get_param( 'utm_source' ) ?? '' );
        $utm_medium   = sanitize_text_field( $req->get_param( 'utm_medium' ) ?? '' );
        $utm_campaign = sanitize_text_field( $req->get_param( 'utm_campaign' ) ?? '' );
        $has_gclid    = (bool) $req->get_param( 'gclid' );
        $has_fbclid   = (bool) $req->get_param( 'fbclid' );
        $page_url     = esc_url_raw( $req->get_param( 'page_url' ) ?? '' );
        $form_plugin  = sanitize_text_field( $req->get_param( 'form_plugin' ) ?? '' );
        $session_id   = sanitize_text_field( $req->get_param( 'session_id' ) ?? '' );
        $client_channel = sanitize_text_field( $req->get_param( 'channel' ) ?? '' );

        // Skip duplicate pageviews: same session + same URL within 10 seconds
        if ( $event_type === 'pageview' && $session_id && $page_url ) {
            if ( $this->is_duplicate_pageview( $session_id, $page_url ) ) {
                return new WP_REST_Response( [ 'ok' => true, 'channel' => 'dedup' ], 200 );
            }
        }

        $channel = RT_Classifier::classify(
            $referrer,
            [ 'source' => $utm_source, 'medium' => $utm_medium, 'campaign' => $utm_campaign ],
            $has_gclid,
            $has_fbclid
        );

        // Internal navigation (same-site referrer, no UTM/click-id of its own) carries
        // no external source signal — classify() already falls back to 'direct' for it,
        // but tracker.js knows the session's *original* first-touch channel (e.g.
        // paid_google) and sends it here. Prefer that over the generic fallback.
        // Note: this endpoint is intentionally unauthenticated (see README), so this
        // is not an access-control check — `referrer` and `channel` are both plain
        // POST fields an attacker could set to anything regardless of this condition
        // (just as gclid=1 already yields paid_google with no restriction at all).
        // It only decides when to prefer the client's inherited value over a fresh
        // classification; the in_array() below keeps the stored value one of the
        // known channels either way.
        $referrer_host = $referrer ? strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) ) : '';
        if ( $referrer_host && RT_Classifier::is_own_host( $referrer_host )
            && $client_channel && in_array( $client_channel, RT_Classifier::all_channels(), true )
        ) {
            $channel = $client_channel;
        }

        RT_Database::insert_event( [
            'event_type'     => $event_type,
            'page_url'       => $page_url,
            'form_plugin'    => $form_plugin ?: null,
            'source_channel' => $channel,
            'utm_source'     => $utm_source ?: null,
            'utm_medium'     => $utm_medium ?: null,
            'utm_campaign'   => $utm_campaign ?: null,
            'referrer'       => $referrer ?: null,
            'session_id'     => $session_id ?: null,
            'is_bot'         => (int) $is_bot,
            'ip'             => $ip,
        ] );

        return new WP_REST_Response( [ 'ok' => true, 'channel' => $channel ], 200 );
    }

    // -------------------------------------------------------------------------
    // Form plugin hooks — server-side fallback
    // These fire when JS didn't already send a /submission REST call.
    // Each hook reads hidden input fields (_rt_source_channel, _rt_utm_*) that
    // tracker.js injects into every form before submit.
    // -------------------------------------------------------------------------

    public function on_cf7_submit( $contact_form, $result = [] ): void {
        $status = is_array( $result ) ? ( $result['status'] ?? '' ) : '';
        // 'mail_sent' and 'mail_failed' both mean the submission passed validation
        // and isn't spam — only the mail step differs. 'validation_failed' and
        // 'spam' are not genuine submissions and shouldn't be recorded.
        if ( ! in_array( $status, [ 'mail_sent', 'mail_failed' ], true ) ) {
            return;
        }
        $submission = WPCF7_Submission::get_instance();
        if ( ! $submission ) {
            return;
        }
        $posted = $submission->get_posted_data();
        $this->record_from_hidden_fields( $posted, 'cf7' );
    }

    public function on_gf_submit( $entry, $form ): void {
        // Gravity Forms stores posted data in $_POST
        $this->record_from_hidden_fields( $_POST, 'gravityforms' ); // phpcs:ignore WordPress.Security.NonceVerification
    }

    public function on_wpf_submit( $fields, $entry, $form_data, $entry_id ): void {
        $this->record_from_hidden_fields( $_POST, 'wpforms' ); // phpcs:ignore WordPress.Security.NonceVerification
    }

    private function record_from_hidden_fields( array $data, string $plugin ): void {
        // Same bot-skip as the REST path (record_event) — without this, a bot that
        // POSTs straight to the form processor and never runs tracker.js entirely
        // bypasses the "don't store bot events by default" behavior.
        $is_bot = $this->is_bot_request();
        if ( $is_bot && ! apply_filters( 'rtst_record_bot_events', false, 'submission' ) ) {
            return;
        }

        $channel = sanitize_text_field( $data['_rt_source_channel'] ?? '' );
        if ( $channel && ! in_array( $channel, RT_Classifier::all_channels(), true ) ) {
            $channel = ''; // fall through to reclassification below rather than store an arbitrary label
        }

        // Dedup via token: if JS fired the REST /submission call it injected _rt_token
        // into both the REST body and this form POST. The first handler to arrive sets
        // the transient; the second skips. Falls back to inserting if no token (JS off).
        $token = sanitize_text_field( $data['_rt_token'] ?? '' );
        if ( $token ) {
            $tk_key = $this->dedup_key( $token );
            if ( get_transient( $tk_key ) ) {
                return;
            }
            set_transient( $tk_key, 'hook', 120 );
        }

        if ( ! $channel ) {
            // Reconstruct from UTM fields if available
            $referrer   = sanitize_text_field( $data['_rt_referrer'] ?? '' );
            $utm_source = sanitize_text_field( $data['_rt_utm_source'] ?? '' );
            $utm_medium = sanitize_text_field( $data['_rt_utm_medium'] ?? '' );
            $utm_campaign = sanitize_text_field( $data['_rt_utm_campaign'] ?? '' );
            $has_gclid  = ! empty( $data['_rt_gclid'] );
            $has_fbclid = ! empty( $data['_rt_fbclid'] );

            $channel = RT_Classifier::classify(
                $referrer,
                [ 'source' => $utm_source, 'medium' => $utm_medium, 'campaign' => $utm_campaign ],
                $has_gclid,
                $has_fbclid
            );
        }

        RT_Database::insert_event( [
            'event_type'     => 'submission',
            'page_url'       => esc_url_raw( $data['_rt_page_url'] ?? '' ),
            'form_plugin'    => $plugin,
            'source_channel' => $channel,
            'utm_source'     => sanitize_text_field( $data['_rt_utm_source'] ?? '' ) ?: null,
            'utm_medium'     => sanitize_text_field( $data['_rt_utm_medium'] ?? '' ) ?: null,
            'utm_campaign'   => sanitize_text_field( $data['_rt_utm_campaign'] ?? '' ) ?: null,
            'referrer'       => sanitize_text_field( $data['_rt_referrer'] ?? '' ) ?: null,
            'is_bot'         => (int) $is_bot,
            'ip'             => $this->get_client_ip(),
        ] );
    }

    private function is_bot_request(): bool {
        $ua = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) );
        if ( ! $ua ) {
            return true; // no UA at all = automated request
        }
        foreach ( [
            'bot', 'spider', 'crawler', 'scraper', 'fetcher',
            'semrush', 'ahrefs', 'moz/', 'majestic', 'dotbot', 'rogerbot',
            'headlesschrome', 'phantomjs', 'selenium', 'puppeteer', 'playwright',
            'wget', 'curl/', 'python-requests', 'python/', 'go-http-client',
            'java/', 'libwww-perl', 'httpclient', 'okhttp/', 'postman',
            'facebookexternalhit', 'twitterbot', 'linkedinbot', 'slackbot',
        ] as $pattern ) {
            if ( str_contains( $ua, $pattern ) ) {
                return true;
            }
        }
        return false;
    }

    private function is_duplicate_pageview( string $session_id, string $page_url ): bool {
        $key = 'rtst_pvd_' . substr( hash_hmac( 'sha256', $session_id . '|' . $page_url, wp_salt( 'auth' ) ), 0, 20 );
        if ( get_transient( $key ) ) {
            return true;
        }
        set_transient( $key, 1, 10 );
        return false;
    }

    /**
     * Shared dedup-token transient key, used by both the REST path (record_event)
     * and the form-hook fallback (record_from_hidden_fields) so the two paths agree
     * on which slot claims a given submission token.
     */
    private function dedup_key( string $token ): string {
        return 'rtst_tk_' . substr( hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ), 0, 20 );
    }

    private function check_rate_limit( string $ip, bool $trusted = true ): bool {
        $limit = $trusted ? 60 : 10;
        // Separate buckets per trust tier so a request that fails nonce verification
        // can't inherit the higher-trust counter (or vice versa).
        $key   = 'rtst_rl_' . ( $trusted ? 't_' : 'u_' ) . substr( md5( $ip ?: 'noip' ), 0, 16 );

        if ( wp_using_ext_object_cache() ) {
            // get_transient()/set_transient() is a non-atomic read-modify-write: two
            // near-simultaneous requests can both read the same count before either
            // writes, letting a burst slip past the cap. wp_cache_incr() is atomic
            // when a persistent object cache (Redis/Memcached) backs it — exactly the
            // higher-traffic sites where that race is most likely to matter.
            //
            // wp_cache_add() first, unconditionally: it's a no-op if the key already
            // exists, but on many persistent-cache backends (e.g. Redis via INCRBY)
            // wp_cache_incr() auto-creates a missing key WITHOUT the expiry we ask
            // for, which would turn this into a lifetime cap instead of a per-minute
            // one. add() is itself atomic, so this doesn't reintroduce the race.
            wp_cache_add( $key, 0, 'rtst', MINUTE_IN_SECONDS );
            $count = wp_cache_incr( $key, 1, 'rtst' );
            if ( false === $count ) {
                $count = 1; // backend doesn't support incr on this key for some reason — fail open for one request
            }
            return $count <= $limit;
        }

        $count = (int) get_transient( $key );
        if ( $count >= $limit ) {
            return false;
        }
        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
        return true;
    }

    private function get_client_ip(): string {
        // CF-Connecting-IP / X-Forwarded-For are client-suppliable unless something in
        // front of PHP (Cloudflare, a trusted reverse proxy) strips and re-sets them.
        // Trusting them unconditionally lets a direct request to the origin spoof a
        // fresh IP on every call and walk straight past check_rate_limit(). Off by
        // default; enable only if the origin is actually locked down to accept
        // traffic solely through that trusted proxy:
        // `add_filter( 'rtst_trust_proxy_headers', '__return_true' );`
        $keys = apply_filters( 'rtst_trust_proxy_headers', false )
            ? [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ]
            : [ 'REMOTE_ADDR' ];
        foreach ( $keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '';
    }

    private function event_schema(): array {
        return [
            'page_url'     => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'esc_url_raw' ],
            'referrer'     => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'esc_url_raw' ],
            'utm_source'   => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'utm_medium'   => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'utm_campaign' => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'gclid'            => [ 'type' => 'string', 'required' => false ],
            'fbclid'           => [ 'type' => 'string', 'required' => false ],
            'channel'          => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'form_plugin'      => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'submission_token' => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'session_id'       => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
        ];
    }
}
