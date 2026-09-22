<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap">
    <h1 style="display:flex;align-items:center;gap:16px;">
        <?php esc_html_e( 'Source Tracker', 'rt-source-tracker' ); ?>
        <span style="font-size:13px;font-weight:400;color:#646970;">
            <?php esc_html_e( 'Auto-refresh in', 'rt-source-tracker' ); ?>
            <span id="rtst-countdown" style="font-weight:600;color:#2271b1;">30</span>s
        </span>
    </h1>

    <?php
    $rtst_notice = get_transient( 'rtst_admin_notice' );
    if ( $rtst_notice ) {
        delete_transient( 'rtst_admin_notice' );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $rtst_notice ) . '</p></div>';
    }

    $filter_args      = array_filter( [
        'page'            => 'rt-source-tracker',
        'date_from'       => $filters['date_from'],
        'date_to'         => $filters['date_to'],
        'channel'         => $filters['channel'],
        'event_type'      => $filters['event_type'],
        'show_bots'       => $filters['show_bots'] !== 'human' ? $filters['show_bots'] : '',
        'referrer_domain' => $filters['referrer_domain'],
    ] );
    $events_tab_url   = admin_url( 'tools.php?' . http_build_query( $filter_args ) );
    $sessions_tab_url = admin_url( 'tools.php?' . http_build_query( $filter_args + [ 'tab' => 'sessions' ] ) );
    $base_url         = $tab === 'sessions' ? $sessions_tab_url : $events_tab_url;
    $filter_query     = http_build_query( $filter_args + ( $tab === 'sessions' ? [ 'tab' => 'sessions' ] : [] ) );

    $badge_colors = [
        'organic_search' => '#00a32a',
        'paid_google'    => '#2271b1',
        'paid_facebook'  => '#3858e9',
        'paid_other'     => '#8c8f94',
        'organic_social' => '#9B59B6',
        'llm_ai'         => '#c0392b',
        'email'          => '#d63638',
        'referral'       => '#dba617',
        'direct'         => '#646970',
    ];
    ?>

    <!-- ------------------------------------------------------------------ -->
    <!-- Summary cards                                                         -->
    <!-- ------------------------------------------------------------------ -->
    <div style="display:flex;flex-wrap:wrap;gap:12px;margin:16px 0;">
        <?php
        $channel_totals_pv  = 0;
        $channel_totals_sub = 0;
        foreach ( $summary as $ch => $counts ) {
            $channel_totals_pv  += $counts['pageviews'];
            $channel_totals_sub += $counts['submissions'];
        }
        ?>
        <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 24px;min-width:140px;text-align:center;">
            <div style="font-size:28px;font-weight:700;color:#1d2327;"><?php echo esc_html( number_format( $channel_totals_pv ) ); ?></div>
            <div style="color:#646970;font-size:13px;"><?php esc_html_e( 'Total Page Views', 'rt-source-tracker' ); ?></div>
        </div>
        <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 24px;min-width:140px;text-align:center;">
            <div style="font-size:28px;font-weight:700;color:#2271b1;"><?php echo esc_html( number_format( $channel_totals_sub ) ); ?></div>
            <div style="color:#646970;font-size:13px;"><?php esc_html_e( 'Form Submissions', 'rt-source-tracker' ); ?></div>
        </div>
        <?php foreach ( $summary as $ch => $counts ) : ?>
        <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 20px;min-width:130px;text-align:center;">
            <div style="font-size:20px;font-weight:600;color:#1d2327;"><?php echo esc_html( number_format( $counts['pageviews'] + $counts['submissions'] ) ); ?></div>
            <div style="color:#646970;font-size:12px;"><?php echo esc_html( RT_Classifier::channel_label( $ch ) ); ?></div>
            <div style="color:#aaa;font-size:11px;"><?php echo esc_html( $counts['submissions'] ); ?> <?php esc_html_e( 'submits', 'rt-source-tracker' ); ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ------------------------------------------------------------------ -->
    <!-- Traffic Insights                                                       -->
    <!-- ------------------------------------------------------------------ -->
    <div style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:16px;">

        <div style="flex:1;min-width:220px;background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px 18px;">
            <div style="font-size:12px;font-weight:600;color:#1d2327;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">
                <?php esc_html_e( 'Top Referrer Domains', 'rt-source-tracker' ); ?>
            </div>
            <?php if ( empty( $top_referrers ) ) : ?>
                <p style="color:#aaa;font-size:12px;margin:0;"><?php esc_html_e( 'No referrer data yet.', 'rt-source-tracker' ); ?></p>
            <?php else : ?>
                <table style="width:100%;font-size:12px;border-collapse:collapse;">
                    <?php foreach ( $top_referrers as $ref ) :
                        $domain_url = admin_url( 'tools.php?' . http_build_query( array_filter( [
                            'page'            => 'rt-source-tracker',
                            'date_from'       => $filters['date_from'],
                            'date_to'         => $filters['date_to'],
                            'show_bots'       => $filters['show_bots'] !== 'human' ? $filters['show_bots'] : '',
                            'referrer_domain' => $ref['domain'],
                        ] ) ) );
                        $is_active = $filters['referrer_domain'] === $ref['domain'];
                    ?>
                    <tr style="<?php echo $is_active ? 'background:#e8f0fe;border-radius:3px;' : ''; ?>">
                        <td style="padding:3px 0;">
                            <a href="<?php echo esc_url( $domain_url ); ?>"
                               style="color:#2271b1;text-decoration:none;font-weight:<?php echo $is_active ? '700' : '400'; ?>;">
                                <?php echo esc_html( $ref['domain'] ); ?>
                            </a>
                        </td>
                        <td style="text-align:right;color:#1d2327;font-weight:600;padding-left:8px;"><?php echo esc_html( number_format( (int) $ref['total'] ) ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <div style="flex:1;min-width:220px;background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px 18px;">
            <div style="font-size:12px;font-weight:600;color:#1d2327;margin-bottom:2px;text-transform:uppercase;letter-spacing:.5px;">
                <?php esc_html_e( 'Direct — Top Landing Pages', 'rt-source-tracker' ); ?>
            </div>
            <div style="color:#646970;font-size:11px;margin-bottom:8px;">
                <?php esc_html_e( 'No referrer or UTMs — could be bookmarks, WhatsApp, SMS, email clients.', 'rt-source-tracker' ); ?>
            </div>
            <?php if ( empty( $direct_pages ) ) : ?>
                <p style="color:#aaa;font-size:12px;margin:0;"><?php esc_html_e( 'No direct traffic yet.', 'rt-source-tracker' ); ?></p>
            <?php else : ?>
                <table style="width:100%;font-size:12px;border-collapse:collapse;">
                    <?php foreach ( $direct_pages as $dp ) :
                        $path = wp_parse_url( $dp['page_url'], PHP_URL_PATH ) ?: $dp['page_url'];
                    ?>
                    <tr>
                        <td style="padding:3px 0;">
                            <a href="<?php echo esc_url( $dp['page_url'] ); ?>" target="_blank" rel="noopener"
                               style="color:#2271b1;text-decoration:none;" title="<?php echo esc_attr( $dp['page_url'] ); ?>">
                                <?php echo esc_html( $path ); ?>
                            </a>
                        </td>
                        <td style="text-align:right;color:#1d2327;font-weight:600;padding-left:8px;"><?php echo esc_html( number_format( (int) $dp['total'] ) ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

    </div>

    <!-- ------------------------------------------------------------------ -->
    <!-- Filters + Export                                                      -->
    <!-- ------------------------------------------------------------------ -->
    <form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>" style="margin-bottom:0;display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;">
        <input type="hidden" name="page" value="rt-source-tracker">
        <input type="hidden" name="tab"  value="<?php echo esc_attr( $tab ); ?>">

        <label style="display:flex;flex-direction:column;font-size:13px;">
            <?php esc_html_e( 'From', 'rt-source-tracker' ); ?>
            <input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" style="margin-top:4px;">
        </label>

        <label style="display:flex;flex-direction:column;font-size:13px;">
            <?php esc_html_e( 'To', 'rt-source-tracker' ); ?>
            <input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" style="margin-top:4px;">
        </label>

        <label style="display:flex;flex-direction:column;font-size:13px;">
            <?php esc_html_e( 'Channel', 'rt-source-tracker' ); ?>
            <select name="channel" style="margin-top:4px;">
                <option value=""><?php esc_html_e( '— All —', 'rt-source-tracker' ); ?></option>
                <?php foreach ( RT_Classifier::all_channels() as $ch ) : ?>
                    <option value="<?php echo esc_attr( $ch ); ?>" <?php selected( $filters['channel'], $ch ); ?>>
                        <?php echo esc_html( RT_Classifier::channel_label( $ch ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <?php if ( $tab === 'events' ) : ?>
        <label style="display:flex;flex-direction:column;font-size:13px;">
            <?php esc_html_e( 'Type', 'rt-source-tracker' ); ?>
            <select name="event_type" style="margin-top:4px;">
                <option value=""><?php esc_html_e( '— All —', 'rt-source-tracker' ); ?></option>
                <option value="pageview"   <?php selected( $filters['event_type'], 'pageview' ); ?>><?php esc_html_e( 'Page View', 'rt-source-tracker' ); ?></option>
                <option value="submission" <?php selected( $filters['event_type'], 'submission' ); ?>><?php esc_html_e( 'Form Submission', 'rt-source-tracker' ); ?></option>
            </select>
        </label>
        <?php endif; ?>

        <label style="display:flex;flex-direction:column;font-size:13px;">
            <?php esc_html_e( 'Traffic', 'rt-source-tracker' ); ?>
            <select name="show_bots" style="margin-top:4px;">
                <option value="human"  <?php selected( $filters['show_bots'], 'human' ); ?>><?php esc_html_e( 'Humans only', 'rt-source-tracker' ); ?></option>
                <option value="all"    <?php selected( $filters['show_bots'], 'all' ); ?>><?php esc_html_e( 'All traffic', 'rt-source-tracker' ); ?></option>
                <option value="bot"    <?php selected( $filters['show_bots'], 'bot' ); ?>><?php esc_html_e( 'Bots only', 'rt-source-tracker' ); ?></option>
            </select>
        </label>

        <button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'rt-source-tracker' ); ?></button>
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'rt-source-tracker', 'tab' => $tab ], admin_url( 'tools.php' ) ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'rt-source-tracker' ); ?></a>

        <?php if ( $tab === 'events' ) : ?>
        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'tools.php?' . $filter_query . '&rt_export_csv=1' ), 'rt_export_csv' ) ); ?>"
           class="button button-secondary" style="margin-left:auto;">
            &#8595; <?php esc_html_e( 'Export CSV', 'rt-source-tracker' ); ?>
        </a>
        <?php endif; ?>
    </form>

    <!-- ------------------------------------------------------------------ -->
    <!-- Tabs                                                                  -->
    <!-- ------------------------------------------------------------------ -->
    <nav class="nav-tab-wrapper" style="margin:16px 0 0;border-bottom:1px solid #c3c4c7;">
        <a href="<?php echo esc_url( $events_tab_url ); ?>"
           class="nav-tab<?php echo $tab === 'events' ? ' nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Events', 'rt-source-tracker' ); ?>
            <?php if ( $tab === 'events' ) : ?>
            <span style="background:#ddd;color:#333;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;"><?php echo esc_html( number_format( $total ) ); ?></span>
            <?php endif; ?>
        </a>
        <a href="<?php echo esc_url( $sessions_tab_url ); ?>"
           class="nav-tab<?php echo $tab === 'sessions' ? ' nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Sessions', 'rt-source-tracker' ); ?>
            <?php if ( $tab === 'sessions' ) : ?>
            <span style="background:#ddd;color:#333;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;"><?php echo esc_html( number_format( $session_total ) ); ?></span>
            <?php endif; ?>
        </a>
    </nav>

    <?php if ( ! empty( $filters['referrer_domain'] ) ) : ?>
    <div style="background:#e8f0fe;border:1px solid #c5d5f5;border-radius:4px;padding:8px 14px;margin:8px 0;font-size:13px;display:flex;align-items:center;gap:10px;">
        <span><?php esc_html_e( 'Showing traffic referred by:', 'rt-source-tracker' ); ?>
            <strong><?php echo esc_html( $filters['referrer_domain'] ); ?></strong>
        </span>
        <?php
        $clear_filter_args = array_diff_key( $filter_args, [ 'referrer_domain' => '' ] );
        if ( $tab === 'sessions' ) {
            $clear_filter_args['tab'] = 'sessions';
        }
        ?>
        <a href="<?php echo esc_url( admin_url( 'tools.php?' . http_build_query( array_filter( $clear_filter_args ) ) ) ); ?>"
           style="color:#c0392b;text-decoration:none;font-size:12px;">&#10005; <?php esc_html_e( 'Clear filter', 'rt-source-tracker' ); ?></a>
    </div>
    <?php endif; ?>

    <?php if ( $tab === 'events' ) : ?>
    <!-- ------------------------------------------------------------------ -->
    <!-- Events table                                                          -->
    <!-- ------------------------------------------------------------------ -->
    <table class="wp-list-table widefat fixed striped" style="font-size:13px;margin-top:12px;">
        <thead>
            <tr>
                <th style="width:140px;"><?php esc_html_e( 'Date', 'rt-source-tracker' ); ?></th>
                <th style="width:80px;"><?php esc_html_e( 'Type', 'rt-source-tracker' ); ?></th>
                <th><?php esc_html_e( 'Page URL', 'rt-source-tracker' ); ?></th>
                <th style="width:130px;"><?php esc_html_e( 'Channel', 'rt-source-tracker' ); ?></th>
                <th><?php esc_html_e( 'Referrer', 'rt-source-tracker' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'UTM Source', 'rt-source-tracker' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'UTM Medium', 'rt-source-tracker' ); ?></th>
                <th style="width:110px;"><?php esc_html_e( 'Campaign', 'rt-source-tracker' ); ?></th>
                <th style="width:70px;"><?php esc_html_e( 'Form', 'rt-source-tracker' ); ?></th>
                <th style="width:70px;"><?php esc_html_e( 'Session', 'rt-source-tracker' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $events ) ) : ?>
            <tr>
                <td colspan="10" style="text-align:center;padding:24px;color:#646970;">
                    <?php esc_html_e( 'No events found.', 'rt-source-tracker' ); ?>
                </td>
            </tr>
        <?php else : ?>
            <?php foreach ( $events as $event ) :
                $channel_slug = $event['source_channel'];
                $badge_color  = $badge_colors[ $channel_slug ] ?? '#646970';
                $is_submit    = $event['event_type'] === 'submission';
                $is_bot_row   = ! empty( $event['is_bot'] );
            ?>
            <tr style="<?php echo esc_attr( $is_bot_row ? 'opacity:0.55;' : ( $is_submit ? 'background:#f6f7ff;' : '' ) ); ?>">
                <td><?php echo esc_html( $event['created_at'] ); ?></td>
                <td>
                    <?php if ( $is_bot_row ) : ?>
                        <span style="background:#8c8f94;color:#fff;padding:2px 6px;border-radius:3px;font-size:10px;">&#129302; Bot</span>
                    <?php elseif ( $is_submit ) : ?>
                        <span style="background:#2271b1;color:#fff;padding:2px 7px;border-radius:3px;font-size:11px;"><?php esc_html_e( 'Submit', 'rt-source-tracker' ); ?></span>
                    <?php else : ?>
                        <span style="background:#f0f0f1;color:#646970;padding:2px 7px;border-radius:3px;font-size:11px;"><?php esc_html_e( 'View', 'rt-source-tracker' ); ?></span>
                    <?php endif; ?>
                </td>
                <td style="word-break:break-all;">
                    <a href="<?php echo esc_url( $event['page_url'] ); ?>" target="_blank" rel="noopener" style="color:inherit;">
                        <?php echo esc_html( $event['page_url'] ); ?>
                    </a>
                </td>
                <td>
                    <span style="background:<?php echo esc_attr( $badge_color ); ?>;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;white-space:nowrap;">
                        <?php echo esc_html( RT_Classifier::channel_label( $channel_slug ) ); ?>
                    </span>
                </td>
                <td style="word-break:break-all;color:#646970;font-size:11px;">
                    <?php if ( ! empty( $event['referrer'] ) ) : ?>
                        <a href="<?php echo esc_url( $event['referrer'] ); ?>" target="_blank" rel="noopener" style="color:inherit;">
                            <?php echo esc_html( wp_parse_url( $event['referrer'], PHP_URL_HOST ) ?: $event['referrer'] ); ?>
                        </a>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( $event['utm_source'] ?? '' ); ?></td>
                <td><?php echo esc_html( $event['utm_medium'] ?? '' ); ?></td>
                <td style="word-break:break-all;"><?php echo esc_html( $event['utm_campaign'] ?? '' ); ?></td>
                <td><?php echo esc_html( $event['form_plugin'] ?? '' ); ?></td>
                <td data-session="<?php echo esc_attr( $event['session_id'] ?? '' ); ?>"
                    style="font-family:monospace;font-size:11px;color:#999;letter-spacing:1px;">
                    <?php echo esc_html( substr( $event['session_id'] ?? '', -6 ) ); ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $total_pages > 1 ) : ?>
    <div class="tablenav bottom" style="margin-top:8px;">
        <div class="tablenav-pages" style="float:right;">
            <?php echo paginate_links( [ 'base' => $base_url . '&paged=%#%', 'format' => '', 'current' => $page, 'total' => $total_pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ] ); ?>
            <span class="displaying-num" style="margin-left:12px;">
                <?php printf( esc_html__( '%s events', 'rt-source-tracker' ), esc_html( number_format( $total ) ) ); ?>
            </span>
        </div>
        <br class="clear">
    </div>
    <?php endif; ?>

    <?php endif; // events tab ?>

    <?php if ( $tab === 'sessions' ) : ?>
    <!-- ------------------------------------------------------------------ -->
    <!-- Sessions table                                                        -->
    <!-- ------------------------------------------------------------------ -->
    <table class="wp-list-table widefat fixed striped" style="font-size:13px;margin-top:12px;">
        <thead>
            <tr>
                <th style="width:140px;"><?php esc_html_e( 'Start', 'rt-source-tracker' ); ?></th>
                <th style="width:80px;"><?php esc_html_e( 'Duration', 'rt-source-tracker' ); ?></th>
                <th style="width:140px;"><?php esc_html_e( 'Channel', 'rt-source-tracker' ); ?></th>
                <th><?php esc_html_e( 'Entry Page', 'rt-source-tracker' ); ?></th>
                <th style="width:80px;"><?php esc_html_e( 'Page Views', 'rt-source-tracker' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'Submitted', 'rt-source-tracker' ); ?></th>
                <th style="width:80px;"><?php esc_html_e( 'Session ID', 'rt-source-tracker' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $sessions ) ) : ?>
            <tr>
                <td colspan="7" style="text-align:center;padding:24px;color:#646970;">
                    <?php esc_html_e( 'No sessions found.', 'rt-source-tracker' ); ?>
                </td>
            </tr>
        <?php else : ?>
            <?php foreach ( $sessions as $session ) :
                $ch          = $session['source_channel'];
                $badge_color = $badge_colors[ $ch ] ?? '#646970';
                $secs        = strtotime( $session['last_seen'] ) - strtotime( $session['first_seen'] );
                $duration    = $secs < 60 ? $secs . 's' : floor( $secs / 60 ) . 'm ' . ( $secs % 60 ) . 's';
                $submitted   = (int) $session['submissions'] > 0;
                $entry_host  = ! empty( $session['entry_page'] ) ? ( wp_parse_url( $session['entry_page'], PHP_URL_PATH ) ?: $session['entry_page'] ) : '—';
            ?>
            <tr style="<?php echo $submitted ? 'background:#f6f7ff;' : ''; ?>">
                <td><?php echo esc_html( $session['first_seen'] ); ?></td>
                <td style="color:#646970;"><?php echo esc_html( $duration ); ?></td>
                <td>
                    <span style="background:<?php echo esc_attr( $badge_color ); ?>;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;white-space:nowrap;">
                        <?php echo esc_html( RT_Classifier::channel_label( $ch ) ); ?>
                    </span>
                </td>
                <td style="word-break:break-all;">
                    <?php if ( ! empty( $session['entry_page'] ) ) : ?>
                    <a href="<?php echo esc_url( $session['entry_page'] ); ?>" target="_blank" rel="noopener" style="color:inherit;">
                        <?php echo esc_html( $entry_host ); ?>
                    </a>
                    <?php else : ?>—<?php endif; ?>
                </td>
                <td style="text-align:center;"><?php echo esc_html( $session['pageviews'] ); ?></td>
                <td style="text-align:center;">
                    <?php if ( $submitted ) : ?>
                        <span style="background:#00a32a;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;"><?php esc_html_e( 'Yes', 'rt-source-tracker' ); ?></span>
                    <?php else : ?>
                        <span style="color:#aaa;font-size:11px;"><?php esc_html_e( 'No', 'rt-source-tracker' ); ?></span>
                    <?php endif; ?>
                </td>
                <td style="font-family:monospace;font-size:11px;color:#999;letter-spacing:1px;">
                    <?php echo esc_html( substr( $session['session_id'], -8 ) ); ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $session_pages > 1 ) : ?>
    <div class="tablenav bottom" style="margin-top:8px;">
        <div class="tablenav-pages" style="float:right;">
            <?php echo paginate_links( [ 'base' => $base_url . '&paged=%#%', 'format' => '', 'current' => $page, 'total' => $session_pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ] ); ?>
            <span class="displaying-num" style="margin-left:12px;">
                <?php printf( esc_html__( '%s sessions', 'rt-source-tracker' ), esc_html( number_format( $session_total ) ) ); ?>
            </span>
        </div>
        <br class="clear">
    </div>
    <?php endif; ?>

    <?php endif; // sessions tab ?>

    <!-- ------------------------------------------------------------------ -->
    <!-- Data Retention                                                        -->
    <!-- ------------------------------------------------------------------ -->
    <div style="margin-top:32px;padding:16px 20px;background:#fff;border:1px solid #ddd;border-radius:6px;max-width:600px;">
        <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;"><?php esc_html_e( 'Data Retention', 'rt-source-tracker' ); ?></h2>
        <form method="post" action="" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;">
            <?php wp_nonce_field( 'rt_retention_settings', '_wpnonce_retention' ); ?>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
                <?php esc_html_e( 'Delete events older than', 'rt-source-tracker' ); ?>
                <input type="number" name="rtst_retention_days"
                       value="<?php echo esc_attr( get_option( 'rtst_retention_days', 365 ) ); ?>"
                       min="1" max="3650" style="width:70px;">
                <?php esc_html_e( 'days', 'rt-source-tracker' ); ?>
            </label>
            <button type="submit" name="rt_save_retention" class="button button-primary">
                <?php esc_html_e( 'Save', 'rt-source-tracker' ); ?>
            </button>
            <button type="submit" name="rt_purge_now" class="button button-secondary"
                    onclick="return confirm('<?php esc_attr_e( 'Delete all events older than the configured retention period? This cannot be undone.', 'rt-source-tracker' ); ?>');">
                <?php esc_html_e( 'Purge Old Data Now', 'rt-source-tracker' ); ?>
            </button>
        </form>
        <p style="margin:10px 0 0;color:#646970;font-size:12px;">
            <?php esc_html_e( 'Events are also purged automatically once per week.', 'rt-source-tracker' ); ?>
        </p>
    </div>

    <script>
    ( function () {
        var palette = [ '#fff8e1', '#e8f5e9', '#e3f2fd', '#fce4ec', '#f3e5f5', '#e0f7fa', '#fff3e0' ];
        var cells   = document.querySelectorAll( '[data-session]' );
        var counts  = {}, colors = {}, ci = 0;
        cells.forEach( function (c) { var s = c.dataset.session; if (s) counts[s] = (counts[s]||0)+1; } );
        cells.forEach( function (c) {
            var s = c.dataset.session;
            if ( s && counts[s] > 1 ) {
                if ( ! colors[s] ) colors[s] = palette[ ci++ % palette.length ];
                c.closest('tr').style.backgroundColor = colors[s];
            }
        } );
    } )();

    ( function () {
        var seconds = 30;
        var el = document.getElementById( 'rtst-countdown' );
        setInterval( function () {
            seconds--;
            if ( el ) el.textContent = seconds;
            if ( seconds <= 0 ) { window.location.reload(); }
        }, 1000 );
    } )();
    </script>
</div>
