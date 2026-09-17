<?php
defined( 'ABSPATH' ) || exit;

/**
 * Classifies a visitor's traffic source into a named channel.
 * Logic mirrors the Python sets in api_fetcher.py (LLM_AI_SOURCES, PPC_MEDIUMS, etc.).
 */
class RT_Classifier {

    private static array $llm_domains = [
        'chatgpt.com', 'chat.openai.com', 'openai.com',
        'perplexity.ai',
        'claude.ai', 'anthropic.com',
        'gemini.google.com', 'bard.google.com',
        'copilot.microsoft.com',
        'you.com', 'phind.com', 'character.ai',
        'poe.com', 'cohere.com', 'groq.com',
    ];

    private static array $ppc_mediums = [
        'cpc', 'ppc', 'paid', 'paid search', 'paidsearch',
        'paid_search', 'paid-search', 'cpv', 'cpm',
    ];

    private static array $search_domains = [
        'google.com',
        'bing.com',
        'yahoo.com',
        'duckduckgo.com',
        'yandex.com', 'yandex.ru',
        'baidu.com',
        'ecosia.org',
    ];

    private static array $social_domains = [
        'facebook.com', 'l.facebook.com', 'm.facebook.com',
        'instagram.com',
        'twitter.com', 't.co', 'x.com',
        'linkedin.com',
        'tiktok.com',
        'youtube.com',
        'pinterest.com',
        'reddit.com',
        'snapchat.com',
    ];

    private static array $facebook_sources = [
        'facebook', 'instagram', 'fb', 'ig', 'meta',
    ];

    /**
     * Classify a visit into a channel string.
     *
     * @param string $referrer   Raw referrer URL (may be empty).
     * @param array  $utm        Associative array with keys: source, medium, campaign.
     * @param bool   $has_gclid  Whether the URL contained a gclid parameter.
     * @param bool   $has_fbclid Whether the URL contained an fbclid parameter.
     * @return string One of: organic_search | paid_google | paid_facebook | organic_social | llm_ai | email | referral | direct
     */
    public static function classify(
        string $referrer,
        array $utm,
        bool $has_gclid,
        bool $has_fbclid
    ): string {
        $utm_source = strtolower( trim( $utm['source'] ?? '' ) );
        $utm_medium = strtolower( trim( $utm['medium'] ?? '' ) );
        $referrer_host = $referrer ? strtolower( (string) parse_url( $referrer, PHP_URL_HOST ) ) : '';

        // --- LLM / AI engines ---
        if ( self::matches_domain_list( $referrer_host, self::$llm_domains )
            || self::matches_domain_list( $utm_source, self::$llm_domains ) ) {
            return 'llm_ai';
        }

        // --- Google Ads (paid_google) ---
        if ( $has_gclid ) {
            return 'paid_google';
        }
        if ( in_array( $utm_medium, self::$ppc_mediums, true )
            && ( str_contains( $utm_source, 'google' ) || $utm_source === '' ) ) {
            return 'paid_google';
        }

        // --- Facebook / Meta Ads (paid_facebook) ---
        if ( $has_fbclid ) {
            return 'paid_facebook';
        }
        if ( in_array( $utm_medium, self::$ppc_mediums, true )
            && self::matches_partial_list( $utm_source, self::$facebook_sources ) ) {
            return 'paid_facebook';
        }

        // --- Any other PPC (still paid, but unknown platform) ---
        if ( in_array( $utm_medium, self::$ppc_mediums, true ) ) {
            return 'paid_other';
        }

        // --- Email ---
        if ( $utm_medium === 'email' || $utm_medium === 'e-mail' ) {
            return 'email';
        }

        // --- Organic search ---
        if ( $referrer_host && ( self::matches_domain_list( $referrer_host, self::$search_domains ) || self::is_google_cctld( $referrer_host ) ) ) {
            return 'organic_search';
        }
        if ( $utm_medium === 'organic' ) {
            return 'organic_search';
        }

        // --- Organic social ---
        if ( $referrer_host && self::matches_domain_list( $referrer_host, self::$social_domains ) ) {
            return 'organic_social';
        }
        if ( in_array( $utm_medium, [ 'social', 'social-network', 'social_network', 'sm' ], true ) ) {
            return 'organic_social';
        }

        // --- Referral (any other referrer) ---
        if ( $referrer_host ) {
            return 'referral';
        }

        // --- Direct ---
        return 'direct';
    }

    private static function matches_domain_list( string $host, array $domains ): bool {
        foreach ( $domains as $domain ) {
            if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
                return true;
            }
        }
        return false;
    }

    private static function is_google_cctld( string $host ): bool {
        return (bool) preg_match( '/(?:^|\.)google\.[a-z]{2,}(?:\.[a-z]{2})?$/', $host );
    }

    private static function matches_partial_list( string $value, array $partials ): bool {
        foreach ( $partials as $partial ) {
            if ( str_contains( $value, $partial ) ) {
                return true;
            }
        }
        return false;
    }

    public static function channel_label( string $channel ): string {
        $labels = [
            'organic_search'  => 'Organic Search',
            'paid_google'     => 'Google Ads',
            'paid_facebook'   => 'Facebook / Meta Ads',
            'paid_other'      => 'Paid (Other)',
            'organic_social'  => 'Organic Social',
            'llm_ai'          => 'LLM / AI',
            'email'           => 'Email',
            'referral'        => 'Referral',
            'direct'          => 'Direct',
        ];
        return $labels[ $channel ] ?? ucwords( str_replace( '_', ' ', $channel ) );
    }

    public static function all_channels(): array {
        return [
            'organic_search', 'paid_google', 'paid_facebook',
            'paid_other', 'organic_social', 'llm_ai',
            'email', 'referral', 'direct',
        ];
    }
}
