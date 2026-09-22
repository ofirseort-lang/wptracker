/**
 * RT Source Tracker — frontend script
 * Runs on every page. Reads referrer + UTM params, classifies the source,
 * persists it in sessionStorage (last-touch) and localStorage (first-touch),
 * then fires a REST pageview event. Also intercepts form submits.
 */
( function () {
    'use strict';

    const cfg = window.rtTracker || {};

    // -----------------------------------------------------------------------
    // Classification — mirrors RT_Classifier in PHP / api_fetcher.py
    // -----------------------------------------------------------------------

    const LLM_DOMAINS = [
        'chatgpt.com', 'chat.openai.com', 'openai.com',
        'perplexity.ai',
        'claude.ai', 'anthropic.com',
        'gemini.google.com', 'bard.google.com',
        'copilot.microsoft.com',
        'you.com', 'phind.com', 'character.ai',
        'poe.com', 'cohere.com', 'groq.com',
    ];

    const PPC_MEDIUMS = [
        'cpc', 'ppc', 'paid', 'paid search', 'paidsearch',
        'paid_search', 'paid-search', 'cpv', 'cpm',
    ];

    const SEARCH_DOMAINS = [
        'google.com', 'bing.com', 'yahoo.com',
        'duckduckgo.com', 'yandex.com', 'yandex.ru',
        'baidu.com', 'ecosia.org',
    ];

    const SOCIAL_DOMAINS = [
        'facebook.com', 'l.facebook.com', 'm.facebook.com',
        'instagram.com', 'twitter.com', 't.co', 'x.com',
        'linkedin.com', 'tiktok.com', 'youtube.com',
        'pinterest.com', 'reddit.com', 'snapchat.com',
    ];

    const FACEBOOK_SOURCES = [ 'facebook', 'instagram', 'fb', 'ig', 'meta' ];

    function hostMatches( host, list ) {
        return list.some( d => host === d || host.endsWith( '.' + d ) );
    }

    function isGoogleCctld( host ) {
        return /(?:^|\.)google\.[a-z]{2,}(?:\.[a-z]{2})?$/.test( host );
    }

    function classify( referrer, utm, gclid, fbclid ) {
        let refHost = '';
        try { refHost = referrer ? new URL( referrer ).hostname.toLowerCase() : ''; } catch (_) {}
        if ( refHost && refHost === window.location.hostname.toLowerCase() ) refHost = '';
        const src     = ( utm.source || '' ).toLowerCase();
        const med     = ( utm.medium || '' ).toLowerCase();

        if ( hostMatches( refHost, LLM_DOMAINS ) || hostMatches( src, LLM_DOMAINS ) ) return 'llm_ai';
        if ( gclid ) return 'paid_google';
        if ( PPC_MEDIUMS.includes( med ) && ( src.includes( 'google' ) || src === '' ) ) return 'paid_google';
        if ( fbclid ) return 'paid_facebook';
        if ( PPC_MEDIUMS.includes( med ) && FACEBOOK_SOURCES.some( f => src.includes( f ) ) ) return 'paid_facebook';
        if ( PPC_MEDIUMS.includes( med ) ) return 'paid_other';
        if ( med === 'email' || med === 'e-mail' ) return 'email';
        if ( refHost && ( hostMatches( refHost, SEARCH_DOMAINS ) || isGoogleCctld( refHost ) ) ) return 'organic_search';
        if ( med === 'organic' ) return 'organic_search';
        if ( refHost && hostMatches( refHost, SOCIAL_DOMAINS ) ) return 'organic_social';
        if ( [ 'social', 'social-network', 'social_network', 'sm' ].includes( med ) ) return 'organic_social';
        if ( refHost ) return 'referral';
        return 'direct';
    }

    // -----------------------------------------------------------------------
    // Read current-page signals
    // -----------------------------------------------------------------------

    const params    = new URLSearchParams( window.location.search );
    const referrer  = document.referrer || '';
    const utm       = {
        source:   params.get( 'utm_source' )   || '',
        medium:   params.get( 'utm_medium' )   || '',
        campaign: params.get( 'utm_campaign' ) || '',
    };
    const gclid     = params.get( 'gclid' )  || '';
    const fbclid    = params.get( 'fbclid' ) || '';
    const pageUrl   = cfg.pageUrl || window.location.href;

    // Session ID — one random token per browser session (tab), resets when session ends
    let sessionId = '';
    try {
        sessionId = sessionStorage.getItem( 'rt_session_id' ) || '';
        if ( ! sessionId ) {
            sessionId = Math.random().toString( 36 ).slice( 2, 12 );
            sessionStorage.setItem( 'rt_session_id', sessionId );
        }
    } catch (_) {}

    // If navigating within the same site with no UTMs, inherit the session's original
    // channel instead of reclassifying internal navigation as "referral"
    let isSameOrigin = false;
    if ( referrer ) {
        try { isSameOrigin = new URL( referrer ).origin === window.location.origin; } catch (_) {}
    }

    let channel;
    if ( isSameOrigin && ! utm.source && ! utm.medium && ! gclid && ! fbclid ) {
        try {
            const prev = JSON.parse( sessionStorage.getItem( 'rt_source' ) || '{}' );
            channel = prev.channel || classify( referrer, utm, gclid, fbclid );
        } catch (_) {
            channel = classify( referrer, utm, gclid, fbclid );
        }
    } else {
        channel = classify( referrer, utm, gclid, fbclid );
    }

    // -----------------------------------------------------------------------
    // Persistence
    // -----------------------------------------------------------------------

    const sourceData = {
        channel:  channel,
        source:   utm.source,
        medium:   utm.medium,
        campaign: utm.campaign,
        referrer: referrer,
        gclid:    gclid,
        fbclid:   fbclid,
    };

    // Last-touch: overwrite every visit
    try { sessionStorage.setItem( 'rt_source', JSON.stringify( sourceData ) ); } catch (_) {}

    // First-touch: only set once per browser
    try {
        if ( ! localStorage.getItem( 'rt_source_first' ) ) {
            localStorage.setItem( 'rt_source_first', JSON.stringify( sourceData ) );
        }
    } catch (_) {}

    // -----------------------------------------------------------------------
    // Send pageview to WP REST API
    // -----------------------------------------------------------------------

    function postEvent( endpoint, extra ) {
        if ( ! cfg.restUrl || ! cfg.nonce ) return;
        const body = Object.assign( {
            page_url:     pageUrl,
            referrer:     referrer,
            utm_source:   utm.source,
            utm_medium:   utm.medium,
            utm_campaign: utm.campaign,
            gclid:        gclid,
            fbclid:       fbclid,
            session_id:   sessionId,
            // Session-inherited channel (see isSameOrigin block above) — lets the
            // server preserve the original first-touch channel across same-site
            // navigation instead of reclassifying from a same-site referrer alone.
            channel:      channel,
        }, extra );

        const url     = cfg.restUrl + endpoint;
        const payload = JSON.stringify( body );

        if ( typeof navigator.sendBeacon === 'function' ) {
            const blob = new Blob( [ payload ], { type: 'application/json' } );
            navigator.sendBeacon( url + '?_wpnonce=' + encodeURIComponent( cfg.nonce ), blob );
        } else {
            fetch( url, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   cfg.nonce,
                },
                body:      payload,
                keepalive: true,
            } ).catch( () => {} );
        }
    }

    postEvent( 'pageview', {} );

    // -----------------------------------------------------------------------
    // Inject hidden fields into every form (for server-side fallback hooks)
    // and intercept submit to fire a /submission REST call
    // -----------------------------------------------------------------------

    function injectHiddenFields( form ) {
        if ( form.dataset.rtInjected ) return;
        form.dataset.rtInjected = '1';

        const fields = {
            _rt_source_channel: channel,
            _rt_referrer:       referrer,
            _rt_utm_source:     utm.source,
            _rt_utm_medium:     utm.medium,
            _rt_utm_campaign:   utm.campaign,
            _rt_gclid:          gclid,
            _rt_fbclid:         fbclid,
            _rt_page_url:       pageUrl,
        };

        for ( const [ name, value ] of Object.entries( fields ) ) {
            const input = document.createElement( 'input' );
            input.type  = 'hidden';
            input.name  = name;
            input.value = value || '';
            form.appendChild( input );
        }
    }

    function onFormSubmit( e ) {
        const form  = e.currentTarget;
        injectHiddenFields( form );

        // Unique token shared between this REST call and the hidden form field so
        // whichever arrives at the server first claims the transient; the other skips.
        const token = Math.random().toString( 36 ).slice( 2 ) + Date.now().toString( 36 );

        const tokenInput  = document.createElement( 'input' );
        tokenInput.type   = 'hidden';
        tokenInput.name   = '_rt_token';
        tokenInput.value  = token;
        form.appendChild( tokenInput );

        postEvent( 'submission', { form_plugin: 'generic', submission_token: token } );
    }

    function attachToForms() {
        document.querySelectorAll( 'form' ).forEach( function ( form ) {
            injectHiddenFields( form );
            form.removeEventListener( 'submit', onFormSubmit );
            form.addEventListener( 'submit', onFormSubmit );
        } );
    }

    // Attach immediately and again after DOM mutations (for AJAX-loaded forms)
    attachToForms();

    let mutationTimer;
    const observer = new MutationObserver( function () {
        clearTimeout( mutationTimer );
        mutationTimer = setTimeout( attachToForms, 32 );
    } );
    observer.observe( document.body, { childList: true, subtree: true } );

} )();
