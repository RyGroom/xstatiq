<?php
/**
 * Statsight Theme Functions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Theme setup
 */
function statsight_setup(): void {
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'html5', [
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
    ] );
    add_theme_support( 'automatic-feed-links' );

    register_nav_menus( [
        'primary' => __( 'Primary Menu', 'statsight' ),
    ] );
}
add_action( 'after_setup_theme', 'statsight_setup' );

/**
 * Create (or upgrade) the odds history table.
 * Runs on every request but dbDelta only alters when needed.
 */
function statsight_create_odds_history_table(): void {
    global $wpdb;

    $charset    = $wpdb->get_charset_collate();
    $current_db = (int) get_option( 'statsight_odds_history_db_version', 0 );
    $target_db  = 19;

    if ( $current_db >= $target_db ) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // v7: drop old unique key (no line) and replace with one that includes line,
    // so the same player/market can be saved at multiple lines.
    if ( $current_db < 7 ) {
        $watchlist_table = $wpdb->prefix . 'statsight_watchlist';
        $wpdb->query( "ALTER TABLE {$watchlist_table} DROP INDEX IF EXISTS idx_uniq" );
        $wpdb->query( "ALTER TABLE {$watchlist_table} ADD UNIQUE KEY idx_uniq (user_id, event_id, player, market_key, direction, line)" );
    }

    // ── Odds history table (v1, v9: is_closing, v11: stat_value) ────────────
    $history_table = $wpdb->prefix . 'statsight_odds_history';
    $sql_history   = "CREATE TABLE {$history_table} (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id     VARCHAR(64)     NOT NULL,
        market_key   VARCHAR(100)    NOT NULL,
        player       VARCHAR(150)    NOT NULL,
        line         VARCHAR(20)     NOT NULL,
        book_key     VARCHAR(64)     NOT NULL,
        over_odds    SMALLINT        DEFAULT NULL,
        under_odds   SMALLINT        DEFAULT NULL,
        stat_value   DECIMAL(6,2)    DEFAULT NULL,
        recorded_at  DATETIME        NOT NULL,
        is_closing   TINYINT(1)      NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY idx_lookup (event_id, market_key, player, line, book_key),
        KEY idx_recorded (recorded_at),
        KEY idx_closing (event_id, is_closing)
    ) {$charset};";

    // v9: add is_closing column if it doesn't exist yet.
    if ( $current_db < 9 ) {
        $wpdb->query( "ALTER TABLE {$history_table} ADD COLUMN IF NOT EXISTS is_closing TINYINT(1) NOT NULL DEFAULT 0" );
        $wpdb->query( "ALTER TABLE {$history_table} ADD KEY IF NOT EXISTS idx_closing (event_id, is_closing)" );
    }

    // v11: add stat_value column for live in-game stat correlation.
    if ( $current_db < 11 ) {
        $wpdb->query( "ALTER TABLE {$history_table} ADD COLUMN IF NOT EXISTS stat_value DECIMAL(6,2) DEFAULT NULL AFTER under_odds" );
    }

    dbDelta( $sql_history );

    // ── Watchlist table (v9: game_start_time, clv) ───────────────────────────
    $watchlist_table = $wpdb->prefix . 'statsight_watchlist';
    $sql_watchlist   = "CREATE TABLE {$watchlist_table} (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id         BIGINT UNSIGNED NOT NULL,
        event_id        VARCHAR(64)     NOT NULL DEFAULT '',
        sport           VARCHAR(64)     NOT NULL DEFAULT '',
        player          VARCHAR(150)    NOT NULL DEFAULT '',
        market_key      VARCHAR(150)    NOT NULL DEFAULT '',
        market_label    VARCHAR(150)    NOT NULL DEFAULT '',
        line            VARCHAR(20)     NOT NULL DEFAULT '',
        direction       VARCHAR(10)     NOT NULL DEFAULT 'over',
        odds            SMALLINT        NOT NULL DEFAULT 0,
        book            VARCHAR(64)     NOT NULL DEFAULT '',
        matchup         VARCHAR(200)    NOT NULL DEFAULT '',
        all_books       LONGTEXT        NOT NULL DEFAULT '',
        added_at        DATETIME        NOT NULL,
        game_start_time DATETIME        DEFAULT NULL,
        clv             SMALLINT        DEFAULT NULL,
        result          VARCHAR(10)     DEFAULT NULL,
        actual_stat     DECIMAL(6,2)    DEFAULT NULL,
        settled_at      DATETIME        DEFAULT NULL,
        deleted_at      DATETIME        DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY idx_user       (user_id),
        KEY idx_event      (event_id),
        KEY idx_settlement (game_start_time, result),
        KEY idx_deleted    (deleted_at),
        UNIQUE KEY idx_uniq (user_id, event_id, player, market_key, direction, line)
    ) {$charset};";

    // v9: add new watchlist columns if they don't exist yet.
    if ( $current_db < 9 ) {
        $wpdb->query( "ALTER TABLE {$watchlist_table} ADD COLUMN IF NOT EXISTS game_start_time DATETIME DEFAULT NULL" );
        $wpdb->query( "ALTER TABLE {$watchlist_table} ADD COLUMN IF NOT EXISTS clv SMALLINT DEFAULT NULL" );
    }

    // v18: add settlement columns.
    if ( $current_db < 18 ) {
        $wpdb->query( "ALTER TABLE {$watchlist_table} ADD COLUMN IF NOT EXISTS result VARCHAR(10) DEFAULT NULL AFTER clv" );
        $wpdb->query( "ALTER TABLE {$watchlist_table} ADD COLUMN IF NOT EXISTS actual_stat DECIMAL(6,2) DEFAULT NULL AFTER result" );
        $wpdb->query( "ALTER TABLE {$watchlist_table} ADD COLUMN IF NOT EXISTS settled_at DATETIME DEFAULT NULL AFTER actual_stat" );
        $wpdb->query( "ALTER TABLE {$watchlist_table} ADD KEY IF NOT EXISTS idx_settlement (game_start_time, result)" );
    }

    // v19: soft-delete column so settled props stay in pick record after removal.
    if ( $current_db < 19 ) {
        $wpdb->query( "ALTER TABLE {$watchlist_table} ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL AFTER settled_at" );
        $wpdb->query( "ALTER TABLE {$watchlist_table} ADD KEY IF NOT EXISTS idx_deleted (deleted_at)" );
    }

    dbDelta( $sql_watchlist );

    // ── Parlays table ─────────────────────────────────────────────────────────
    $parlays_table = $wpdb->prefix . 'statsight_parlays';
    $sql_parlays   = "CREATE TABLE {$parlays_table} (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id    BIGINT UNSIGNED NOT NULL,
        name       VARCHAR(150)    NOT NULL DEFAULT '',
        prop_ids   TEXT            NOT NULL,
        legs_json  LONGTEXT        NOT NULL DEFAULT '',
        created_at DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_user (user_id)
    ) {$charset};";
    dbDelta( $sql_parlays );

    // ── Notification sent log (v8) ────────────────────────────────────────────
    $notif_table = $wpdb->prefix . 'statsight_notif_sent';
    $sql_notif   = "CREATE TABLE {$notif_table} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id     BIGINT UNSIGNED NOT NULL,
        rule_id     VARCHAR(64)     NOT NULL,
        fingerprint VARCHAR(255)    NOT NULL,
        sent_at     DATETIME        NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY idx_dedup (user_id, rule_id, fingerprint),
        KEY idx_user (user_id),
        KEY idx_sent (sent_at)
    ) {$charset};";
    dbDelta( $sql_notif );

    // ── Push subscriptions table (v10) ───────────────────────────────────────
    $push_table = $wpdb->prefix . 'statsight_push_subscriptions';
    $sql_push   = "CREATE TABLE {$push_table} (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id      BIGINT UNSIGNED NOT NULL,
        endpoint     TEXT            NOT NULL,
        p256dh       TEXT            NOT NULL,
        auth         VARCHAR(255)    NOT NULL,
        created_at   DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_user (user_id)
    ) {$charset};";
    dbDelta( $sql_push );

    // ── Prop alerts table (v12) ───────────────────────────────────────────────
    $alerts_table = $wpdb->prefix . 'statsight_prop_alerts';
    $sql_alerts   = "CREATE TABLE {$alerts_table} (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id      BIGINT UNSIGNED NOT NULL,
        event_id     VARCHAR(64)     NOT NULL,
        sport        VARCHAR(64)     NOT NULL DEFAULT '',
        player       VARCHAR(150)    NOT NULL,
        market_key   VARCHAR(150)    NOT NULL,
        market_label VARCHAR(150)    NOT NULL DEFAULT '',
        line         VARCHAR(20)     NOT NULL,
        direction    VARCHAR(10)     NOT NULL DEFAULT 'over',
        target_odds  SMALLINT        NOT NULL,
        matchup      VARCHAR(200)    NOT NULL DEFAULT '',
        triggered    TINYINT(1)      NOT NULL DEFAULT 0,
        created_at   DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_user    (user_id),
        KEY idx_event   (event_id),
        KEY idx_active  (triggered, event_id)
    ) {$charset};";
    dbDelta( $sql_alerts );

    // ── Game polls table (v13, v14: commence_time, v15: best_odds/best_book) ──
    $polls_table = $wpdb->prefix . 'statsight_polls';
    $sql_polls   = "CREATE TABLE {$polls_table} (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id        VARCHAR(64)     NOT NULL,
        sport           VARCHAR(64)     NOT NULL DEFAULT '',
        player          VARCHAR(150)    NOT NULL,
        market_key      VARCHAR(150)    NOT NULL,
        market_label    VARCHAR(150)    NOT NULL DEFAULT '',
        line            VARCHAR(20)     NOT NULL,
        result          VARCHAR(10)     DEFAULT NULL,
        commence_time   DATETIME        DEFAULT NULL,
        best_over_odds  SMALLINT        DEFAULT NULL,
        best_over_book  VARCHAR(100)    NOT NULL DEFAULT '',
        best_under_odds SMALLINT        DEFAULT NULL,
        best_under_book VARCHAR(100)    NOT NULL DEFAULT '',
        created_at      DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY idx_event (event_id)
    ) {$charset};";
    $wpdb->query( "ALTER TABLE {$polls_table} ADD COLUMN IF NOT EXISTS commence_time DATETIME DEFAULT NULL" );
    $wpdb->query( "ALTER TABLE {$polls_table} ADD COLUMN IF NOT EXISTS best_over_odds  SMALLINT     DEFAULT NULL" );
    $wpdb->query( "ALTER TABLE {$polls_table} ADD COLUMN IF NOT EXISTS best_over_book  VARCHAR(100) NOT NULL DEFAULT ''" );
    $wpdb->query( "ALTER TABLE {$polls_table} ADD COLUMN IF NOT EXISTS best_under_odds SMALLINT     DEFAULT NULL" );
    $wpdb->query( "ALTER TABLE {$polls_table} ADD COLUMN IF NOT EXISTS best_under_book VARCHAR(100) NOT NULL DEFAULT ''" );
    dbDelta( $sql_polls );

    // ── Follows table (v17) ───────────────────────────────────────────────────
    $follows_table = $wpdb->prefix . 'statsight_follows';
    $sql_follows   = "CREATE TABLE {$follows_table} (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        follower_id  BIGINT UNSIGNED NOT NULL,
        following_id BIGINT UNSIGNED NOT NULL,
        created_at   DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY idx_pair (follower_id, following_id),
        KEY idx_follower  (follower_id),
        KEY idx_following (following_id)
    ) {$charset};";
    dbDelta( $sql_follows );

    // ── Poll votes table (v13) ────────────────────────────────────────────────
    $votes_table = $wpdb->prefix . 'statsight_poll_votes';
    $sql_votes   = "CREATE TABLE {$votes_table} (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        poll_id    BIGINT UNSIGNED NOT NULL,
        user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
        vote       VARCHAR(10)     NOT NULL,
        voted_at   DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY idx_poll_user (poll_id, user_id),
        KEY idx_poll (poll_id)
    ) {$charset};";
    dbDelta( $sql_votes );

    update_option( 'statsight_odds_history_db_version', $target_db );
}
add_action( 'admin_init', 'statsight_create_odds_history_table' );

// ── Community profile rewrite rule ────────────────────────────────────────
add_action( 'init', function (): void {
    // Matches /community/user/123/ and passes community_user_id to the query.
    add_rewrite_rule(
        '^community/user/([0-9]+)/?$',
        'index.php?pagename=community&community_user_id=$matches[1]',
        'top'
    );
} );

add_filter( 'query_vars', function ( array $vars ): array {
    $vars[] = 'community_user_id';
    return $vars;
} );

// ── Plan / Access Control ──────────────────────────────────────────────────

define( 'STATSIGHT_PRODUCT_PRO',   26 );
define( 'STATSIGHT_PRODUCT_SHARP', 27 );
define( 'STATSIGHT_MIN_PICKS',     10 );

/**
 * Canonical map of Odds API bookmaker keys → display labels.
 * Used in both PHP templates and rendered into JS as BOOK_LABELS.
 *
 * @return array<string, string>
 */
function statsight_get_book_labels(): array {
    return [
        'fanduel'          => 'FanDuel',
        'draftkings'       => 'DraftKings',
        'betmgm'           => 'BetMGM',
        'caesars'          => 'Caesars',
        'bet365'           => 'Bet365',
        'fanatics'         => 'Fanatics',
        'espnbet'          => 'ESPN Bet',
        'williamhill_us'   => 'Caesars',
        'pointsbetus'      => 'BetUS',
        'betus'            => 'BetUS',
        'mybookieag'       => 'MyBookie',
        'betonlineag'      => 'BetOnline',
        'superbook'        => 'SuperBook',
        'unibet_us'        => 'Unibet',
        'wynnbet'          => 'WynnBET',
        'betrivers'        => 'BetRivers',
        'bovada'           => 'Bovada',
        'ballybet'         => 'Bally Bet',
        'hardrock'         => 'Hard Rock Bet',
        'fliff'            => 'Fliff',
        'prizepicks'       => 'PrizePicks',
        'underdog_fantasy' => 'Underdog',
    ];
}

/** Canonical display-column order for sportsbooks. */
function statsight_get_book_order(): array {
    return [ 'fanduel', 'draftkings', 'betmgm', 'caesars', 'bet365', 'fanatics', 'williamhill_us', 'pointsbetus', 'betus', 'mybookieag', 'betonlineag' ];
}

/**
 * Normalise a team display name for fuzzy matching against ESPN team names.
 * Strips accents, lowercases, removes punctuation, and applies city abbreviations.
 */
function statsight_normalise_team_name( string $name ): string {
    $n = iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $name ) ?: $name;
    $n = str_replace( '&', 'and', $n );
    $n = preg_replace( '/[^a-z0-9 ]/i', '', $n );
    $n = strtolower( $n );
    $city_map = [ 'los angeles' => 'la', 'new york' => 'ny', 'golden state' => 'gs' ];
    foreach ( $city_map as $long => $short ) {
        $n = str_replace( $long, $short, $n );
    }
    return $n;
}

/**
 * Returns the current user's plan: 'sharp', 'pro', or 'free'.
 * Result is cached in a per-request static so WooCommerce order queries
 * only run once per page load.
 */
function statsight_get_user_plan(): string {
    static $plan = null;
    if ( $plan !== null ) {
        return $plan;
    }

    $user_id = get_current_user_id();

    // Admins always get sharp access.
    if ( $user_id && current_user_can( 'manage_options' ) ) {
        $plan = 'sharp';
        return $plan;
    }

    if ( ! $user_id ) {
        $plan = 'free';
        return $plan;
    }

    $has_pro   = false;
    $has_sharp = false;

    // Check active WooCommerce Subscriptions first (preferred).
    if ( function_exists( 'wcs_get_users_subscriptions' ) ) {
        $subscriptions = wcs_get_users_subscriptions( $user_id );
        foreach ( $subscriptions as $subscription ) {
            if ( ! $subscription->has_status( [ 'active', 'pending-cancel' ] ) ) {
                continue;
            }
            foreach ( $subscription->get_items() as $item ) {
                $product_id = (int) $item->get_product_id();
                if ( $product_id === STATSIGHT_PRODUCT_SHARP ) {
                    $has_sharp = true;
                } elseif ( $product_id === STATSIGHT_PRODUCT_PRO ) {
                    $has_pro = true;
                }
            }
            if ( $has_sharp ) break;
        }
    }

    // Fall back to completed one-time orders if no subscription found.
    if ( ! $has_sharp && ! $has_pro && function_exists( 'wc_get_orders' ) ) {
        $orders = wc_get_orders( [
            'customer_id' => $user_id,
            'status'      => [ 'wc-completed' ],
            'limit'       => -1,
        ] );
        foreach ( $orders as $order ) {
            if ( ! $order ) continue;
            foreach ( $order->get_items() as $item ) {
                $product_id = (int) $item->get_product_id();
                if ( $product_id === STATSIGHT_PRODUCT_SHARP ) {
                    $has_sharp = true;
                } elseif ( $product_id === STATSIGHT_PRODUCT_PRO ) {
                    $has_pro = true;
                }
            }
            if ( $has_sharp ) break;
        }
    }

    if ( $has_sharp ) {
        $plan = 'sharp';
    } elseif ( $has_pro ) {
        $plan = 'pro';
    } else {
        $plan = 'free';
    }

    return $plan;
}

/**
 * Sends a 403 JSON error and exits if the current user's plan is below $required.
 * Plan hierarchy: free < pro < sharp.
 *
 * @param string $required 'pro' or 'sharp'
 */
function statsight_require_plan( string $required ): void {
    $hierarchy = [ 'free' => 0, 'pro' => 1, 'sharp' => 2 ];
    $user_plan = statsight_get_user_plan();

    if ( ( $hierarchy[ $user_plan ] ?? 0 ) < ( $hierarchy[ $required ] ?? 0 ) ) {
        wp_send_json_error(
            [
                'message'       => 'Upgrade required.',
                'plan_required' => $required,
                'user_plan'     => $user_plan,
            ],
            403
        );
        exit;
    }
}

/**
 * Redirects to the pricing page if the current user's plan is below $required.
 * Call at the top of any page template that requires a paid plan.
 * Must be called before get_header() outputs anything.
 *
 * @param string $required 'pro' or 'sharp'
 */
function statsight_require_plan_redirect( string $required ): void {
    $hierarchy = [ 'free' => 0, 'pro' => 1, 'sharp' => 2 ];
    $user_plan = statsight_get_user_plan();

    if ( ( $hierarchy[ $user_plan ] ?? 0 ) < ( $hierarchy[ $required ] ?? 0 ) ) {
        wp_safe_redirect( home_url( '/pricing/' ) );
        exit;
    }
}

/**
 * Returns true if the text is appropriate for a public collection title, false if not.
 * Uses GPT-4o-mini to catch profanity, slurs, and creative bypasses (l33tspeak, etc.).
 * Fails open on API error so a network hiccup doesn't block saves.
 */
function statsight_text_is_clean( string $text ): bool {
    $api_key = defined( 'OPENAI_API_KEY' ) ? OPENAI_API_KEY : '';
    if ( empty( $api_key ) ) {
        return true;
    }

    $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
        'timeout' => 8,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode( [
            'model'      => 'gpt-4o-mini',
            'max_tokens' => 1,
            'messages'   => [
                [
                    'role'    => 'system',
                    'content' => 'You are a content moderator. Reply only with "Y" if the text is appropriate for a public sports app (no profanity, slurs, or offensive content — including creative spellings, numbers substituted for letters, or other bypasses), or "N" if it is not.',
                ],
                [
                    'role'    => 'user',
                    'content' => $text,
                ],
            ],
        ] ),
    ] );

    if ( is_wp_error( $response ) ) {
        return true;
    }

    $body   = json_decode( wp_remote_retrieve_body( $response ), true );
    $answer = strtoupper( trim( $body['choices'][0]['message']['content'] ?? 'Y' ) );
    return $answer === 'Y';
}

// ── Odds Refresh Cron ──────────────────────────────────────────────────────

/**
 * Returns the Unix timestamp of the next clock-aligned 10-minute boundary.
 * e.g. if it's 12:03, returns the timestamp for 12:10.
 */
function statsight_next_10min_boundary(): int {
    $now      = time();
    $interval = 5 * MINUTE_IN_SECONDS;
    return $now + ( $interval - ( $now % $interval ) );
}

/**
 * Returns the Unix timestamp of the next clock-aligned 1-minute boundary.
 */
function statsight_next_1min_boundary(): int {
    $now      = time();
    $interval = MINUTE_IN_SECONDS;
    return $now + ( $interval - ( $now % $interval ) );
}

/**
 * Schedule the next single props-refresh event at the next :00/:10/:20/… mark.
 * Called on theme activation and re-scheduled from within the cron callback
 * so each run is clock-aligned rather than drifting from first activation.
 */
function statsight_schedule_cron(): void {
    if ( ! wp_next_scheduled( 'statsight_refresh_props' ) ) {
        wp_schedule_single_event( statsight_next_10min_boundary(), 'statsight_refresh_props' );
    }
    if ( ! wp_next_scheduled( 'statsight_send_notifications' ) ) {
        wp_schedule_single_event( statsight_next_1min_boundary(), 'statsight_send_notifications' );
    }
    if ( ! wp_next_scheduled( 'statsight_refresh_live_props' ) ) {
        wp_schedule_single_event( statsight_next_1min_boundary(), 'statsight_refresh_live_props' );
    }
    if ( ! wp_next_scheduled( 'statsight_purge_odds_history' ) ) {
        wp_schedule_event( time(), 'daily', 'statsight_purge_odds_history' );
    }
    if ( ! wp_next_scheduled( 'statsight_run_settlement' ) ) {
        wp_schedule_event( time(), 'hourly', 'statsight_run_settlement' );
    }
    if ( ! wp_next_scheduled( 'statsight_purge_deleted_accounts' ) ) {
        wp_schedule_event( time(), 'daily', 'statsight_purge_deleted_accounts' );
    }
}
add_action( 'after_switch_theme', 'statsight_schedule_cron' );

// Bootstrap cron jobs on first load after this code is deployed.
add_action( 'init', function (): void {
    if ( ! wp_next_scheduled( 'statsight_refresh_live_props' ) ) {
        wp_schedule_single_event( statsight_next_1min_boundary(), 'statsight_refresh_live_props' );
    }
    if ( ! wp_next_scheduled( 'statsight_send_notifications' ) ) {
        wp_schedule_single_event( statsight_next_1min_boundary(), 'statsight_send_notifications' );
    }
    if ( ! wp_next_scheduled( 'statsight_run_settlement' ) ) {
        wp_schedule_event( time(), 'hourly', 'statsight_run_settlement' );
    }
    if ( ! wp_next_scheduled( 'statsight_purge_deleted_accounts' ) ) {
        wp_schedule_event( time(), 'daily', 'statsight_purge_deleted_accounts' );
    }
} );

/**
 * Clear the cron jobs when the theme is deactivated (another theme is switched to).
 */
function statsight_unschedule_cron(): void {
    $timestamp = wp_next_scheduled( 'statsight_refresh_props' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'statsight_refresh_props' );
    }
    $live_timestamp = wp_next_scheduled( 'statsight_refresh_live_props' );
    if ( $live_timestamp ) {
        wp_unschedule_event( $live_timestamp, 'statsight_refresh_live_props' );
    }
    $purge_timestamp = wp_next_scheduled( 'statsight_purge_odds_history' );
    if ( $purge_timestamp ) {
        wp_unschedule_event( $purge_timestamp, 'statsight_purge_odds_history' );
    }
    $acct_purge_timestamp = wp_next_scheduled( 'statsight_purge_deleted_accounts' );
    if ( $acct_purge_timestamp ) {
        wp_unschedule_event( $acct_purge_timestamp, 'statsight_purge_deleted_accounts' );
    }
    $notif_timestamp = wp_next_scheduled( 'statsight_send_notifications' );
    if ( $notif_timestamp ) {
        wp_unschedule_event( $notif_timestamp, 'statsight_send_notifications' );
    }
}
add_action( 'switch_theme', 'statsight_unschedule_cron' );

/**
 * Purge odds history records older than 3 days.
 * Runs daily via WP-Cron.
 */
function statsight_purge_odds_history(): void {
    global $wpdb;
    $table    = $wpdb->prefix . 'statsight_odds_history';
    $cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( '-8 hours' ) );
    $wpdb->query(
        $wpdb->prepare( "DELETE FROM {$table} WHERE recorded_at < %s", $cutoff )
    );
}
add_action( 'statsight_purge_odds_history', 'statsight_purge_odds_history' );

// ── Prop Settlement ───────────────────────────────────────────────────────────

/**
 * Resolve a Odds API event (identified by home/away team names and game date)
 * to an ESPN event ID by querying the ESPN scoreboard for that date range.
 * Results are cached for 24 hours since completed-game IDs never change.
 *
 * @param string $sport         e.g. 'americanfootball_nfl'
 * @param string $home_team     e.g. 'Atlanta Falcons'
 * @param string $away_team     e.g. 'Houston Texans'
 * @param string $game_date_utc UTC datetime string (from game_start_time column)
 * @return string|null ESPN event ID, or null on failure
 */
function statsight_resolve_espn_event_id(
    string $sport,
    string $home_team,
    string $away_team,
    string $game_date_utc
): ?string {
    $path = statsight_espn_sport_path( $sport );
    if ( ! $path ) return null;

    $cache_key = 'statsight_espn_eid_' . md5( $sport . $home_team . $away_team . substr( $game_date_utc, 0, 10 ) );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached ?: null;
    }

    // Normalize team name for fuzzy matching (mirrors live-cron logic).
    $norm = function ( string $s ): string {
        $s = strtolower( $s );
        $s = str_replace( [ 'los angeles', 'new york', 'new orleans', 'golden state', 'san antonio', 'oklahoma city' ],
                          [ 'la',          'ny',        'no',          'gs',           'sa',           'okc' ], $s );
        return trim( $s );
    };

    $norm_home = $norm( $home_team );
    $norm_away = $norm( $away_team );

    // ESPN scoreboard accepts a ?dates=YYYYMMDD parameter.
    $date_yyyymmdd = ( new DateTime( $game_date_utc, new DateTimeZone( 'UTC' ) ) )
        ->setTimezone( new DateTimeZone( 'America/New_York' ) )
        ->format( 'Ymd' );

    $scoreboard_url = add_query_arg(
        [ 'dates' => $date_yyyymmdd, 'limit' => 50 ],
        'https://site.api.espn.com/apis/site/v2/sports/'
            . $path['sport'] . '/' . $path['league'] . '/scoreboard'
    );

    $response = wp_remote_get( $scoreboard_url, [ 'timeout' => 12 ] );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return null;
    }

    $data     = json_decode( wp_remote_retrieve_body( $response ), true );
    $espn_id  = null;

    foreach ( $data['events'] ?? [] as $event ) {
        $teams = [];
        foreach ( $event['competitions'][0]['competitors'] ?? [] as $c ) {
            $teams[] = $norm( $c['team']['displayName'] ?? '' );
        }
        $match = false;
        foreach ( $teams as $t ) {
            if ( str_contains( $t, $norm_home ) || str_contains( $norm_home, $t ) ||
                 str_contains( $t, $norm_away ) || str_contains( $norm_away, $t ) ) {
                $match = true;
                break;
            }
        }
        if ( $match ) {
            $espn_id = $event['id'] ?? null;
            break;
        }
    }

    // Cache the resolved ID (empty string if not found, so we don't hammer ESPN on every cron run).
    set_transient( $cache_key, $espn_id ?? '', 24 * HOUR_IN_SECONDS );
    return $espn_id;
}

/**
 * Settle a yes/no touchdown or goal-scorer prop by inspecting ESPN's scoringPlays array.
 * Used for: player_anytime_td, player_1st_td, player_last_td,
 *           player_goal_scorer_anytime, player_goal_scorer_first, player_goal_scorer_last.
 *
 * Returns 'win', 'loss', or 'void' (event not found / plays unavailable).
 * Note: push is impossible for these binary markets.
 */
function statsight_settle_anytime_scorer( array $entry ): string {
    $sport      = $entry['sport']      ?? '';
    $player     = $entry['player']     ?? '';
    $market_key = $entry['market_key'] ?? '';
    $direction  = $entry['direction']  ?? 'over'; // 'over' = yes (scored)

    // Parse home/away from the matchup column ("Team A vs Team B").
    $matchup = $entry['matchup'] ?? '';
    $teams   = preg_split( '/\s+vs\.?\s+/i', $matchup );
    $home    = trim( $teams[0] ?? '' );
    $away    = trim( $teams[1] ?? '' );
    if ( ! $home && ! $away ) return 'void';

    $espn_id = statsight_resolve_espn_event_id(
        $sport, $home, $away, $entry['game_start_time'] ?? ''
    );
    if ( ! $espn_id ) return 'void';

    $path = statsight_espn_sport_path( $sport );
    if ( ! $path ) return 'void';

    $summary_url = 'https://site.api.espn.com/apis/site/v2/sports/'
        . $path['sport'] . '/' . $path['league'] . '/summary?event=' . rawurlencode( $espn_id );

    $response = wp_remote_get( $summary_url, [ 'timeout' => 12 ] );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return 'void';
    }

    $summary      = json_decode( wp_remote_retrieve_body( $response ), true );
    $plays        = $summary['scoringPlays'] ?? [];
    if ( empty( $plays ) ) return 'void';

    $is_football = str_starts_with( $sport, 'americanfootball' );
    $is_soccer   = str_starts_with( $sport, 'soccer' );
    $norm_player = statsight_normalize_name( $player );

    // Abbreviations that count as a touchdown score for the target player.
    $td_types = [ 'TD', 'RSHTD', 'RCVTD', 'PASSTD', 'RECVTD', 'INTRTD', 'FRTD', 'PRTD', 'KRTD' ];
    // Soccer goal types — ESPN uses 'G', 'PK' (penalty kick) for goals.
    $goal_types = [ 'G', 'PK', 'GK' ];

    $allowed_types = $is_football ? $td_types : ( $is_soccer ? $goal_types : $td_types );

    $matched_plays = [];
    foreach ( $plays as $play ) {
        $type_abbr = strtoupper( $play['type']['abbreviation'] ?? '' );
        if ( ! in_array( $type_abbr, $allowed_types, true ) ) continue;

        // ESPN stores scorer name inside the play text field.
        // Format varies: "Drake London 14 Yd pass from Kirk Cousins (Kicker)"
        //                "Marcus Jones 25 Yd run"  /  "Raheem Mostert 1 Yd run"
        // The scorer is always the first name in the text.
        $text = $play['text'] ?? '';
        // Extract the first person name from the text (up to the first verb/number).
        if ( preg_match( '/^([A-Z][a-zÀ-ÖØ-öø-ÿ]+(?:\s+[A-Z][a-zÀ-ÖØ-öø-ÿ]+){1,3})/', $text, $m ) ) {
            $scorer = statsight_normalize_name( $m[1] );
            if ( $scorer === $norm_player ) {
                $matched_plays[] = $play;
            }
        }
    }

    $scored = ! empty( $matched_plays );

    if ( $market_key === 'player_1st_td' || $market_key === 'player_goal_scorer_first' || $market_key === 'player_first_goal_scorer' ) {
        // First scorer: player must appear in the very first qualifying scoring play.
        $first_scorer = '';
        if ( ! empty( $plays ) ) {
            foreach ( $plays as $play ) {
                $type_abbr = strtoupper( $play['type']['abbreviation'] ?? '' );
                if ( ! in_array( $type_abbr, $allowed_types, true ) ) continue;
                $text = $play['text'] ?? '';
                if ( preg_match( '/^([A-Z][a-zÀ-ÖØ-öø-ÿ]+(?:\s+[A-Z][a-zÀ-ÖØ-öø-ÿ]+){1,3})/', $text, $m ) ) {
                    $first_scorer = statsight_normalize_name( $m[1] );
                    break;
                }
            }
        }
        $scored = ( $first_scorer === $norm_player );
    } elseif ( $market_key === 'player_last_td' || $market_key === 'player_goal_scorer_last' || $market_key === 'player_last_goal_scorer' ) {
        // Last scorer: player must appear in the last qualifying scoring play.
        $last_scorer = '';
        foreach ( array_reverse( $plays ) as $play ) {
            $type_abbr = strtoupper( $play['type']['abbreviation'] ?? '' );
            if ( ! in_array( $type_abbr, $allowed_types, true ) ) continue;
            $text = $play['text'] ?? '';
            if ( preg_match( '/^([A-Z][a-zÀ-ÖØ-öø-ÿ]+(?:\s+[A-Z][a-zÀ-ÖØ-öø-ÿ]+){1,3})/', $text, $m ) ) {
                $last_scorer = statsight_normalize_name( $m[1] );
                break;
            }
        }
        $scored = ( $last_scorer === $norm_player );
    }

    // 'over' direction = bettor said YES (player will score); 'under' = NO.
    if ( $direction === 'over' ) {
        return $scored ? 'win' : 'loss';
    } else {
        return $scored ? 'loss' : 'win';
    }
}

/**
 * Settle a single watchlist entry by fetching the player's actual stat for the
 * game and comparing it against the tracked line and direction.
 *
 * Returns 'win', 'loss', 'push', or 'void' (unsupported market / player not found).
 */
/**
 * Settle a first-basket prop using ESPN's plays array.
 * Free throws are excluded — only field goals count.
 */
function statsight_settle_first_basket( array $entry ): string {
    $sport  = $entry['sport']  ?? '';
    $player = $entry['player'] ?? '';

    $espn_id = statsight_resolve_espn_event_id(
        $sport,
        $entry['home_team']       ?? '',
        $entry['away_team']       ?? '',
        $entry['game_start_time'] ?? ''
    );
    if ( ! $espn_id ) return 'void';

    $path = statsight_espn_sport_path( $sport );
    if ( ! $path ) return 'void';

    $summary_url = 'https://site.api.espn.com/apis/site/v2/sports/'
        . $path['sport'] . '/' . $path['league'] . '/summary?event=' . rawurlencode( $espn_id );

    $response = wp_remote_get( $summary_url, [ 'timeout' => 12 ] );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return 'void';
    }

    $summary     = json_decode( wp_remote_retrieve_body( $response ), true );
    $plays       = $summary['plays'] ?? [];
    $norm_player = statsight_normalize_name( $player );

    foreach ( $plays as $play ) {
        if ( empty( $play['scoringPlay'] ) ) continue;

        // Exclude free throws — first basket must be a field goal.
        $type_text = strtolower( $play['type']['text'] ?? '' );
        if ( str_contains( $type_text, 'free throw' ) ) continue;

        // Play text format: "Player Name makes ..."
        $text = $play['text'] ?? '';
        if ( ! preg_match( '/^(.+?)\s+makes\b/i', $text, $m ) ) continue;

        $scorer = statsight_normalize_name( $m[1] );
        return ( $scorer === $norm_player ) ? 'win' : 'loss';
    }

    return 'void';
}

/**
 * Settle an MMA watchlist entry using ESPN's fighter profile eventsMap.
 * Supports h2h (moneyline) and fighter_win_method markets.
 * Returns 'win', 'loss', or 'void'.
 */
function statsight_settle_mma( array $entry ): string {
    $player     = $entry['player']     ?? '';
    $market_key = $entry['market_key'] ?? '';
    $event_id   = $entry['event_id']   ?? '';

    if ( ! $player || ! $event_id ) return 'void';

    // Resolve ESPN athlete ID via search.
    $search_url = 'https://site.web.api.espn.com/apis/search/v2?' . http_build_query( [
        'query' => statsight_strip_name_suffix( $player ),
        'sport' => 'mma',
        'limit' => 5,
    ] );
    $search_res = wp_remote_get( $search_url, [ 'timeout' => 10 ] );
    if ( is_wp_error( $search_res ) || wp_remote_retrieve_response_code( $search_res ) !== 200 ) {
        return 'void';
    }

    $athlete_id = null;
    $search_data = json_decode( wp_remote_retrieve_body( $search_res ), true );
    foreach ( $search_data['results'] ?? [] as $group ) {
        if ( ( $group['type'] ?? '' ) !== 'player' ) continue;
        foreach ( $group['contents'] ?? [] as $item ) {
            if ( statsight_normalize_name( $item['displayName'] ?? '' ) !== statsight_normalize_name( $player ) ) continue;
            $uid = $item['uid'] ?? '';
            if ( preg_match( '/~a:(\d+)$/', $uid, $m ) ) {
                $athlete_id = $m[1];
                break 2;
            }
        }
    }
    if ( ! $athlete_id ) return 'void';

    // Fetch athlete profile — eventsMap contains full fight history with W/L + method.
    $profile_url = 'https://site.web.api.espn.com/apis/common/v3/sports/mma/ufc/athletes/' . $athlete_id;
    $profile_res = wp_remote_get( $profile_url, [ 'timeout' => 10 ] );
    if ( is_wp_error( $profile_res ) || wp_remote_retrieve_response_code( $profile_res ) !== 200 ) {
        return 'void';
    }

    $profile_data = json_decode( wp_remote_retrieve_body( $profile_res ), true );
    $events_map   = $profile_data['eventsMap'] ?? [];

    // The odds API event ID is a hex hash unrelated to ESPN's numeric ID.
    // Match by game date (within 2 days of game_start_time) and opponent name
    // extracted from the matchup string (e.g. "Jon Jones vs. Stipe Miocic").
    $game_start   = $entry['game_start_time'] ?? '';
    $matchup      = $entry['matchup']         ?? '';
    $start_ts     = $game_start ? strtotime( $game_start ) : 0;

    // Extract both fighter names from matchup string.
    $matchup_names = [];
    if ( preg_match( '/^(.+?)\s+vs\.?\s+(.+)$/i', $matchup, $mm ) ) {
        $matchup_names[] = statsight_normalize_name( trim( $mm[1] ) );
        $matchup_names[] = statsight_normalize_name( trim( $mm[2] ) );
    }
    $norm_player = statsight_normalize_name( $player );

    $fight = null;
    foreach ( $events_map as $ev ) {
        // Check date proximity — within 2 days.
        $ev_ts = isset( $ev['gameDate'] ) ? strtotime( $ev['gameDate'] ) : 0;
        if ( $start_ts && $ev_ts && abs( $ev_ts - $start_ts ) > 2 * DAY_IN_SECONDS ) {
            continue;
        }

        // Check opponent name matches one of the matchup participants (not the player).
        $opp_name = statsight_normalize_name( $ev['opponent']['displayName'] ?? '' );
        if ( ! $opp_name ) continue;

        // The opponent in eventsMap should be the other fighter in our matchup.
        $opp_in_matchup = false;
        foreach ( $matchup_names as $mn ) {
            if ( $mn !== $norm_player && ( $mn === $opp_name || str_contains( $mn, $opp_name ) || str_contains( $opp_name, $mn ) ) ) {
                $opp_in_matchup = true;
                break;
            }
        }
        if ( ! $opp_in_matchup ) continue;

        $fight = $ev;
        break;
    }
    if ( ! $fight ) return 'void';

    $game_result = strtoupper( trim( $fight['gameResult'] ?? '' ) ); // 'W' or 'L'
    if ( ! in_array( $game_result, [ 'W', 'L' ], true ) ) return 'void';

    // h2h — moneyline: did the fighter win?
    if ( $market_key === 'h2h' ) {
        return $game_result === 'W' ? 'win' : 'loss';
    }

    // fighter_win_method — did the fighter win by the specified method?
    // direction field stores the method key: 'ko_tko', 'submission', 'decision'
    if ( $market_key === 'fighter_win_method' || $market_key === 'fighter_win_method_and_round' ) {
        if ( $game_result !== 'W' ) return 'loss'; // fighter lost — method bet loses

        $method_raw  = strtolower( $fight['status']['result']['name'] ?? '' );
        $direction   = strtolower( $entry['direction'] ?? '' );

        $is_ko  = str_contains( $method_raw, 'ko' ) || str_contains( $method_raw, 'tko' );
        $is_sub = str_contains( $method_raw, 'sub' );
        $is_dec = str_contains( $method_raw, 'dec' ) || str_contains( $method_raw, 'decision' );

        $matched = match ( $direction ) {
            'ko_tko'     => $is_ko,
            'submission' => $is_sub,
            'decision'   => $is_dec,
            default      => false,
        };

        return $matched ? 'win' : 'loss';
    }

    return 'void';
}

function statsight_settle_watchlist_entry( array $entry ): string {
    $sport      = $entry['sport']      ?? '';
    $player     = $entry['player']     ?? '';
    $market_key = $entry['market_key'] ?? '';
    $line       = (float) ( $entry['line']      ?? 0 );
    $direction  = $entry['direction']  ?? 'over';

    $mk_base = preg_replace( '/_alternate$/', '', $market_key );

    // MMA — settled via ESPN fighter profile eventsMap.
    if ( $sport === 'mma_mixed_martial_arts' ) {
        return statsight_settle_mma( $entry );
    }

    // First basket — uses ESPN plays array (field goals only, no free throws).
    if ( $mk_base === 'player_first_basket' ) {
        return statsight_settle_first_basket( $entry );
    }

    // Scoring-play markets are settled from ESPN's scoringPlays array, not gamelog stats.
    static $scorer_markets = [
        'player_anytime_td', 'player_1st_td', 'player_last_td',
        'player_goal_scorer_anytime', 'player_goal_scorer_first', 'player_goal_scorer_last',
        'player_first_goal_scorer', 'player_last_goal_scorer',
    ];
    if ( in_array( $mk_base, $scorer_markets, true ) ) {
        return statsight_settle_anytime_scorer( $entry );
    }

    // All other markets: resolve from ESPN gamelog stat totals.
    $hit_cols = statsight_hit_rate_columns( $market_key );
    if ( ! $hit_cols ) {
        return 'void';
    }

    // Fetch the player's gamelog — the most recent entry is the settled game.
    $gamelog = statsight_fetch_player_gamelog( $sport, $player, $market_key, $entry['event_id'] ?? '' );
    if ( ! $gamelog ) {
        return 'void';
    }

    // Find the gamelog entry closest to game_start_time.
    $game_date_et = null;
    if ( ! empty( $entry['game_start_time'] ) ) {
        $game_date_et = ( new DateTime( $entry['game_start_time'], new DateTimeZone( 'UTC' ) ) )
            ->setTimezone( new DateTimeZone( 'America/New_York' ) )
            ->format( 'M j' ); // matches gamelog date format e.g. "May 8"
    }

    // Try to match the game by date; fall back to the most recent entry.
    $game_entry = null;
    if ( $game_date_et ) {
        foreach ( array_reverse( $gamelog ) as $g ) {
            if ( ( $g['date'] ?? '' ) === $game_date_et ) {
                $game_entry = $g;
                break;
            }
        }
    }
    if ( ! $game_entry ) {
        $game_entry = end( $gamelog ); // most recent
    }
    if ( ! $game_entry ) {
        return 'void';
    }

    $stats = $game_entry['stats'] ?? [];

    // ── Computed markets — can't use simple column sum ────────────────────────

    // MLB: batter_singles = H - 2B - 3B - HR
    if ( $mk_base === 'batter_singles' ) {
        $h  = statsight_stat_to_float( $stats['H']  ?? null );
        $d  = statsight_stat_to_float( $stats['2B'] ?? null );
        $t  = statsight_stat_to_float( $stats['3B'] ?? null );
        $hr = statsight_stat_to_float( $stats['HR'] ?? null );
        if ( $h === null || $d === null || $t === null || $hr === null ) return 'void';
        $actual = $h - $d - $t - $hr;
    }

    // MLB: batter_total_bases = (1×1B) + (2×2B) + (3×3B) + (4×HR)
    elseif ( $mk_base === 'batter_total_bases' ) {
        $h  = statsight_stat_to_float( $stats['H']  ?? null );
        $d  = statsight_stat_to_float( $stats['2B'] ?? null );
        $t  = statsight_stat_to_float( $stats['3B'] ?? null );
        $hr = statsight_stat_to_float( $stats['HR'] ?? null );
        if ( $h === null || $d === null || $t === null || $hr === null ) return 'void';
        $singles = $h - $d - $t - $hr;
        $actual  = $singles + ( 2 * $d ) + ( 3 * $t ) + ( 4 * $hr );
    }

    // MLB: pitcher_outs = IP × 3 (each full inning = 3 outs; fractional innings e.g. 6.2 = 6⅔ IP = 20 outs)
    elseif ( $mk_base === 'pitcher_outs' ) {
        $ip = statsight_stat_to_float( $stats['IP'] ?? null );
        if ( $ip === null ) return 'void';
        $full    = (int) $ip;
        $partial = round( ( $ip - $full ) * 10 ); // ESPN stores .1 = 1 out, .2 = 2 outs
        $actual  = ( $full * 3 ) + $partial;
    }

    // MLB: pitcher_record_a_win — binary: did the pitcher get the W decision?
    elseif ( $mk_base === 'pitcher_record_a_win' ) {
        // ESPN Dec column is "W" on a win, "L" on a loss, blank/ND otherwise.
        $dec = trim( $stats['Dec'] ?? '' );
        if ( $dec === '' ) return 'void'; // no decision yet
        return str_starts_with( strtoupper( $dec ), 'W' ) ? 'win' : 'loss';
    }

    // MLB: batter_first_home_run — binary: did the batter hit any HR? (same as batter_home_runs > 0)
    elseif ( $mk_base === 'batter_first_home_run' ) {
        $hr = statsight_stat_to_float( $stats['HR'] ?? null );
        if ( $hr === null ) return 'void';
        return $hr >= 1 ? 'win' : 'loss';
    }

    // NHL: player_power_play_points = PPG + PPA
    elseif ( $mk_base === 'player_power_play_points' ) {
        $ppg = statsight_stat_to_float( $stats['PPG'] ?? null );
        $ppa = statsight_stat_to_float( $stats['PPA'] ?? null );
        if ( $ppg === null || $ppa === null ) return 'void';
        $actual = $ppg + $ppa;
    }

    // ── Standard column-sum markets ───────────────────────────────────────────
    else {
        $actual = 0.0;
        foreach ( $hit_cols as $col ) {
            $val = statsight_stat_to_float( $stats[ $col ] ?? null );
            if ( $val === null ) {
                return 'void'; // player DNP or stat unavailable
            }
            $actual += $val;
        }
    }

    // Compare actual vs line.
    if ( $actual > $line ) {
        $result = $direction === 'over' ? 'win' : 'loss';
    } elseif ( $actual < $line ) {
        $result = $direction === 'over' ? 'loss' : 'win';
    } else {
        $result = 'push';
    }

    return $result;
}

/**
 * Batch settlement runner — finds unsettled watchlist entries where the game
 * started at least 4 hours ago and attempts to settle each one.
 * Called hourly by WP-Cron.
 */
function statsight_run_settlement(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'statsight_watchlist';
    $cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( '-4 hours' ) );

    // Fetch up to 50 unsettled entries per run to avoid timeout.
    // Include soft-deleted rows (deleted_at IS NOT NULL) so in-game removals still get settled
    // and count against the pick record — preventing loss-scrubbing via same-day removal.
    $entries = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, sport, player, market_key, line, direction, event_id, matchup, game_start_time, added_at
             FROM {$table}
             WHERE result IS NULL
               AND game_start_time IS NOT NULL
               AND game_start_time < %s
             ORDER BY game_start_time ASC
             LIMIT 50",
            $cutoff
        ),
        ARRAY_A
    );

    if ( ! $entries ) {
        return;
    }

    static $scorer_markets = [
        'player_anytime_td', 'player_1st_td', 'player_last_td',
        'player_goal_scorer_anytime', 'player_goal_scorer_first', 'player_goal_scorer_last',
        'player_first_goal_scorer', 'player_last_goal_scorer',
    ];

    foreach ( $entries as $entry ) {
        $result = statsight_settle_watchlist_entry( $entry );

        $actual_stat = null;
        $mk_base     = preg_replace( '/_alternate$/', '', $entry['market_key'] ?? '' );

        if ( $result !== 'void' ) {
            if ( in_array( $mk_base, $scorer_markets, true ) ) {
                // For binary scorer markets, store 1 = scored, 0 = didn't score.
                $actual_stat = ( $result === 'win' && ( $entry['direction'] ?? 'over' ) === 'over' )
                            || ( $result === 'loss' && ( $entry['direction'] ?? 'over' ) === 'under' )
                    ? 1.0 : 0.0;
            } else {
                // Gamelog-based markets: re-fetch and sum the relevant stat columns.
                $hit_cols = statsight_hit_rate_columns( $entry['market_key'] ?? '' );
                if ( $hit_cols ) {
                    $gamelog = statsight_fetch_player_gamelog(
                        $entry['sport'],
                        $entry['player'],
                        $entry['market_key'],
                        $entry['event_id'] ?? ''
                    );
                    if ( $gamelog ) {
                        $game_date_et = null;
                        if ( ! empty( $entry['game_start_time'] ) ) {
                            $game_date_et = ( new DateTime( $entry['game_start_time'], new DateTimeZone( 'UTC' ) ) )
                                ->setTimezone( new DateTimeZone( 'America/New_York' ) )
                                ->format( 'M j' );
                        }
                        $game_entry = null;
                        if ( $game_date_et ) {
                            foreach ( array_reverse( $gamelog ) as $g ) {
                                if ( ( $g['date'] ?? '' ) === $game_date_et ) { $game_entry = $g; break; }
                            }
                        }
                        if ( ! $game_entry ) $game_entry = end( $gamelog );
                        if ( $game_entry ) {
                            $sum = 0.0;
                            foreach ( $hit_cols as $col ) {
                                $val = statsight_stat_to_float( $game_entry['stats'][ $col ] ?? null );
                                if ( $val !== null ) $sum += $val;
                            }
                            $actual_stat = $sum;
                        }
                    }
                }
            }
        }

        $wpdb->update(
            $table,
            [
                'result'      => $result,
                'actual_stat' => $actual_stat,
                'settled_at'  => current_time( 'mysql', true ),
            ],
            [ 'id' => (int) $entry['id'] ],
            [ '%s', $actual_stat !== null ? '%f' : null, '%s' ],
            [ '%d' ]
        );
    }
}
add_action( 'statsight_run_settlement', 'statsight_run_settlement' );

/**
 * Admin-only manual trigger: /wp-admin/admin-post.php?action=statsight_force_settle
 * Runs settlement immediately and reports results. Admins only.
 */
function statsight_force_settle_handler(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized', 403 );
    }

    global $wpdb;
    $table  = $wpdb->prefix . 'statsight_watchlist';
    $before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE result IS NULL AND game_start_time IS NOT NULL AND game_start_time < NOW()" );

    statsight_run_settlement();

    $after  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE result IS NULL AND game_start_time IS NOT NULL AND game_start_time < NOW()" );
    $settled = $before - $after;

    $rows = $wpdb->get_results(
        "SELECT player, market_key, result, actual_stat, settled_at FROM {$table} WHERE settled_at IS NOT NULL ORDER BY settled_at DESC LIMIT 20",
        ARRAY_A
    );

    header( 'Content-Type: text/plain; charset=utf-8' );
    echo "Settlement run complete.\n";
    echo "Unsettled before: {$before}\n";
    echo "Settled this run: {$settled}\n";
    echo "Unsettled after:  {$after}\n\n";
    echo "Recent results:\n";
    foreach ( $rows as $r ) {
        printf( "  %-30s %-35s %-6s actual=%-6s settled=%s\n",
            $r['player'], $r['market_key'], $r['result'] ?? '—',
            $r['actual_stat'] ?? '—', $r['settled_at'] ?? '—'
        );
    }
    exit;
}
add_action( 'admin_post_statsight_force_settle', 'statsight_force_settle_handler' );

/**
 * Cron callback — fetch and cache props for every event on the next two game
 * days across all supported sports. Snapshots are written by
 * statsight_fetch_and_cache_props() automatically.
 *
 * Runs every 10 minutes on the clock (:00, :10, :20, …), independent of user requests.
 */
function statsight_cron_refresh_props(): void {
    // Re-schedule the next run at the next clock-aligned boundary before doing
    // any work, so the job stays aligned even if this run takes a few seconds.
    wp_schedule_single_event( statsight_next_10min_boundary(), 'statsight_refresh_props' );

    $fetch_enabled = get_field( 'odds_data_fetch', 'option' );
    if ( $fetch_enabled !== false && ! $fetch_enabled ) {
        return;
    }

    if ( ! defined( 'THE_ODDS_API_KEY' ) || empty( THE_ODDS_API_KEY ) ) {
        return;
    }

    $allowed_sports = [
        'basketball_nba',
        'americanfootball_nfl',
        'americanfootball_ncaaf',
        'basketball_ncaab',
        'baseball_mlb',
        'icehockey_nhl',
        'mma_mixed_martial_arts',
        'basketball_nba_summer_league',
        'soccer_epl',
        'soccer_usa_mls',
        'basketball_wnba',
    ];

    $et_tz      = new DateTimeZone( 'America/New_York' );
    $et_date_of = fn( string $iso ): string =>
        ( new DateTime( $iso ) )->setTimezone( $et_tz )->format( 'Y-m-d' );

    foreach ( $allowed_sports as $sport ) {
        // Fetch the events list (uses its own 15-min transient cache).
        $events_cached = get_transient( 'statsight_events_' . $sport );

        if ( false !== $events_cached ) {
            // Flatten all events from the cached days structure.
            $events = array_merge( ...array_column( $events_cached['days'] ?? [], 'events' ) );
        } else {
            // Use ET midnight as the lower bound so games that started earlier
            // today (ET) are included even if their UTC commence_time predates
            // the UTC calendar date.
            // Delegate entirely to statsight_get_events_for_sport() which applies
            // the 3-day commenceTimeTo cap and all date-filtering logic in one place.
            $cached_result = statsight_get_events_for_sport( $sport );
            if ( ! is_array( $cached_result ) || empty( $cached_result['days'] ) ) {
                continue;
            }
            $events = array_merge( ...array_column( $cached_result['days'], 'events' ) );
        }

        // Fetch props for each event and record a history snapshot every run.
        // If the props cache is warm, snapshot from cached data (no API call).
        // If the cache has expired, fetch fresh data (which also records the snapshot).
        // Sleep 1s between live API calls to avoid bursting the rate limit.
        foreach ( $events as $event ) {
            $event_id = $event['id'] ?? '';
            if ( empty( $event_id ) ) {
                continue;
            }
            // Always fetch fresh from the API on each cron run so props are
            // never stale for arbitrage detection and history snapshots.
            statsight_fetch_and_cache_props( $sport, $event_id );
            sleep( 1 );
        }
    }
}
add_action( 'statsight_refresh_props', 'statsight_cron_refresh_props' );
add_action( 'statsight_bg_refresh_props', 'statsight_fetch_and_cache_props', 10, 2 );

/**
 * Cron callback — refresh props for currently live games only.
 * Runs every minute. Only fires API calls for events that ESPN reports as
 * in-progress, keeping quota usage proportional to actual live action.
 */
function statsight_cron_refresh_live_props(): void {
    // Re-schedule before doing work so timing stays aligned.
    wp_schedule_single_event( statsight_next_1min_boundary(), 'statsight_refresh_live_props' );

    $fetch_enabled = get_field( 'odds_data_fetch', 'option' );
    if ( $fetch_enabled !== false && ! $fetch_enabled ) {
        return;
    }

    if ( ! defined( 'THE_ODDS_API_KEY' ) || empty( THE_ODDS_API_KEY ) ) {
        return;
    }

    $allowed_sports = [
        'basketball_nba',
        'americanfootball_nfl',
        'americanfootball_ncaaf',
        'basketball_ncaab',
        'baseball_mlb',
        'icehockey_nhl',
        'mma_mixed_martial_arts',
        'basketball_nba_summer_league',
        'soccer_epl',
        'soccer_usa_mls',
        'basketball_wnba',
    ];

    foreach ( $allowed_sports as $sport ) {
        $path = statsight_espn_sport_path( $sport );
        if ( ! $path ) {
            continue;
        }

        // Fetch live game state from ESPN scoreboard.
        $scoreboard_url = 'https://site.api.espn.com/apis/site/v2/sports/'
            . $path['sport'] . '/' . $path['league'] . '/scoreboard';
        $sb_response    = wp_remote_get( $scoreboard_url, [ 'timeout' => 10 ] );

        if ( is_wp_error( $sb_response ) || wp_remote_retrieve_response_code( $sb_response ) !== 200 ) {
            continue;
        }

        $sb_data    = json_decode( wp_remote_retrieve_body( $sb_response ), true );
        $live_teams = []; // normalised team name -> true, for games currently in progress
        $live_espn_ids = []; // normalised team name -> ESPN event id

        // Normalise city abbreviations so ESPN "LA Clippers" matches Odds API "Los Angeles Clippers".
        $norm_team_cron = function ( string $s ): string {
            $s = strtolower( $s );
            $s = str_replace( 'los angeles',   'la',  $s );
            $s = str_replace( 'new york',      'ny',  $s );
            $s = str_replace( 'new orleans',   'no',  $s );
            $s = str_replace( 'golden state',  'gs',  $s );
            $s = str_replace( 'san antonio',   'sa',  $s );
            $s = str_replace( 'oklahoma city', 'okc', $s );
            return trim( $s );
        };

        // Market key → ESPN boxscore label mapping.
        $market_to_label = [
            'player_points'       => 'PTS',
            'player_rebounds'     => 'REB',
            'player_assists'      => 'AST',
            'player_turnovers'    => 'TO',
            'player_steals'       => 'STL',
            'player_blocks'       => 'BLK',
            'player_threes'       => '3PT',
            'player_points_rebounds_assists' => null, // composite — skip
        ];

        foreach ( $sb_data['events'] ?? [] as $espn_event ) {
            $state = $espn_event['status']['type']['state'] ?? '';
            if ( $state !== 'in' ) {
                continue;
            }
            $espn_id = $espn_event['id'] ?? '';
            foreach ( $espn_event['competitions'][0]['competitors'] ?? [] as $c ) {
                $name = $c['team']['displayName'] ?? '';
                if ( $name ) {
                    $norm = $norm_team_cron( $name );
                    $live_teams[ $norm ]    = true;
                    $live_espn_ids[ $norm ] = $espn_id;
                }
            }
        }

        if ( empty( $live_teams ) ) {
            continue; // No live games for this sport right now.
        }

        // Fetch boxscores for all live ESPN events in parallel to get current player stats.
        // Result: espn_event_id -> player_name -> market_key -> float
        $boxscore_stats = []; // espn_event_id -> [ player_name -> [ market_key -> value ] ]
        $espn_ids_needed = array_unique( array_values( $live_espn_ids ) );

        if ( ! empty( $espn_ids_needed ) ) {
            $mh    = curl_multi_init();
            $curls = []; // espn_event_id -> curl handle

            foreach ( $espn_ids_needed as $espn_id ) {
                $summary_url = 'https://site.api.espn.com/apis/site/v2/sports/'
                    . $path['sport'] . '/' . $path['league'] . '/summary?event=' . rawurlencode( $espn_id );
                $ch = curl_init( $summary_url );
                curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
                curl_setopt( $ch, CURLOPT_TIMEOUT, 8 );
                $curls[ $espn_id ] = $ch;
                curl_multi_add_handle( $mh, $ch );
            }

            $running = null;
            do { curl_multi_exec( $mh, $running ); curl_multi_select( $mh ); } while ( $running > 0 );

            foreach ( $curls as $espn_id => $ch ) {
                $body = curl_multi_getcontent( $ch );
                curl_multi_remove_handle( $mh, $ch );
                curl_close( $ch );

                if ( ! $body ) continue;
                $summary = json_decode( $body, true );

                // Each player group is one team; iterate all athletes across both teams.
                foreach ( $summary['boxscore']['players'] ?? [] as $team_group ) {
                    foreach ( $team_group['statistics'] ?? [] as $stat_group ) {
                        $labels   = $stat_group['labels'] ?? [];
                        $athletes = $stat_group['athletes'] ?? [];

                        foreach ( $athletes as $athlete_entry ) {
                            if ( $athlete_entry['didNotPlay'] ?? false ) continue;
                            $name  = $athlete_entry['athlete']['displayName'] ?? '';
                            $stats = $athlete_entry['stats'] ?? [];
                            if ( ! $name || empty( $stats ) ) continue;

                            foreach ( $market_to_label as $market_key => $espn_label ) {
                                if ( $espn_label === null ) continue;
                                $idx = array_search( $espn_label, $labels, true );
                                if ( $idx === false ) continue;

                                $raw = $stats[ $idx ] ?? null;
                                if ( $raw === null || $raw === '' || $raw === '--' ) continue;

                                // 3PT stat is formatted "made-attempted" — extract made count.
                                if ( $espn_label === '3PT' && str_contains( (string) $raw, '-' ) ) {
                                    $raw = explode( '-', $raw )[0];
                                }

                                $val = (float) $raw;
                                $boxscore_stats[ $espn_id ][ $name ][ $market_key ] = $val;
                            }
                        }
                    }
                }
            }

            curl_multi_close( $mh );
        }

        // Find cached events for this sport and match against live teams.
        $events_cached = get_transient( 'statsight_events_' . $sport );
        if ( false === $events_cached ) {
            continue;
        }

        $events = array_merge( ...array_column( $events_cached['days'] ?? [], 'events' ) );

        foreach ( $events as $event ) {
            $event_id  = $event['id'] ?? '';
            $home_name = $norm_team_cron( $event['home_team'] ?? '' );
            $away_name = $norm_team_cron( $event['away_team'] ?? '' );

            if ( empty( $event_id ) ) {
                continue;
            }

            // Check if either team is live using loose matching against normalised names.
            $matched_espn_id = null;
            foreach ( $live_espn_ids as $norm_team => $espn_id ) {
                if (
                    str_contains( $home_name, $norm_team ) || str_contains( $norm_team, $home_name ) ||
                    str_contains( $away_name, $norm_team ) || str_contains( $norm_team, $away_name )
                ) {
                    $matched_espn_id = $espn_id;
                    break;
                }
            }

            if ( $matched_espn_id === null ) {
                continue;
            }

            $live_stats = $boxscore_stats[ $matched_espn_id ] ?? [];

            // Force-refresh by deleting the transient then re-fetching.
            delete_transient( 'statsight_props2_' . $event_id );
            statsight_fetch_and_cache_props( $sport, $event_id, $live_stats );
            sleep( 1 ); // Avoid bursting API rate limit between events.
        }
    }
}
add_action( 'statsight_refresh_live_props', 'statsight_cron_refresh_live_props' );


/**
 * Enqueue styles and scripts
 */
/**
 * Output a shared escHtml() global utility before other scripts.
 */
function statsight_inline_globals(): void {
    echo "<script>function escHtml(s){const d=document.createElement('div');d.appendChild(document.createTextNode(String(s??'')));return d.innerHTML;}</script>\n";
}
add_action( 'wp_head', 'statsight_inline_globals' );

function statsight_enqueue_assets(): void {
    wp_enqueue_style(
        'statsight-style',
        get_stylesheet_uri(),
        [],
        wp_get_theme()->get( 'Version' )
    );

    wp_enqueue_script(
        'chartjs',
        'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js',
        [],
        '4',
        true
    );

    wp_enqueue_script(
        'statsight-main',
        get_template_directory_uri() . '/assets/js/main.js',
        [ 'chartjs' ],
        wp_get_theme()->get( 'Version' ),
        true
    );

    $active_books_raw = is_user_logged_in()
        ? get_user_meta( get_current_user_id(), 'statsight_active_books', true )
        : '';
    $active_books = ( $active_books_raw && is_string( $active_books_raw ) )
        ? json_decode( $active_books_raw, true )
        : null; // null = all books (default)

    wp_localize_script( 'statsight-main', 'statsightData', [
        'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
        'nonce'       => wp_create_nonce( 'statsight_events' ),
        'plan'        => statsight_get_user_plan(),
        'swUrl'       => home_url( '/service-worker.js' ),
        'swScope'     => trailingslashit( home_url( '/' ) ),
        'iconUrl'     => get_template_directory_uri() . '/assets/icons/icon-192.png',
        'vapidKey'    => defined( 'STATSIGHT_VAPID_PUBLIC' ) ? STATSIGHT_VAPID_PUBLIC : '',
        'activeBooks' => $active_books, // null = show all; array of book keys = filtered
    ] );

    // Inline service worker registration — runs on every page.
    wp_add_inline_script( 'statsight-main', "
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register(statsightData.swUrl, { scope: statsightData.swScope })
                .then(function() {})
                .catch(function(err) { console.warn('SW registration failed:', err); });
        }
    " );
}
add_action( 'wp_enqueue_scripts', 'statsight_enqueue_assets' );

// ── Push subscription AJAX handlers ──────────────────────────────────────

function statsight_ajax_push_subscribe(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
    }

    global $wpdb;

    $user_id  = get_current_user_id();
    $endpoint = esc_url_raw( wp_unslash( $_POST['endpoint'] ?? '' ) );
    $p256dh   = sanitize_text_field( wp_unslash( $_POST['p256dh']   ?? '' ) );
    $auth     = sanitize_text_field( wp_unslash( $_POST['auth']     ?? '' ) );

    if ( ! $endpoint || ! $p256dh || ! $auth ) {
        wp_send_json_error( [ 'message' => 'Invalid subscription data.' ], 400 );
    }

    $table = $wpdb->prefix . 'statsight_push_subscriptions';

    // Remove any existing subscription for this user+endpoint pair first.
    $wpdb->delete( $table, [ 'user_id' => $user_id, 'endpoint' => $endpoint ] );

    $wpdb->insert( $table, [
        'user_id'    => $user_id,
        'endpoint'   => $endpoint,
        'p256dh'     => $p256dh,
        'auth'       => $auth,
        'created_at' => gmdate( 'Y-m-d H:i:s' ),
    ] );

    wp_send_json_success( [ 'message' => 'Subscribed.' ] );
}
add_action( 'wp_ajax_statsight_push_subscribe', 'statsight_ajax_push_subscribe' );

function statsight_ajax_push_unsubscribe(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
    }

    global $wpdb;

    $user_id  = get_current_user_id();
    $endpoint = esc_url_raw( wp_unslash( $_POST['endpoint'] ?? '' ) );
    $table    = $wpdb->prefix . 'statsight_push_subscriptions';

    if ( $endpoint ) {
        $wpdb->delete( $table, [ 'user_id' => $user_id, 'endpoint' => $endpoint ] );
    } else {
        $wpdb->delete( $table, [ 'user_id' => $user_id ] );
    }

    wp_send_json_success( [ 'message' => 'Unsubscribed.' ] );
}
add_action( 'wp_ajax_statsight_push_unsubscribe', 'statsight_ajax_push_unsubscribe' );

// ── Prop Alerts AJAX ─────────────────────────────────────────────────────

function statsight_ajax_prop_alert_set(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'pro' );
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
    }

    $event_id     = sanitize_key( $_POST['event_id']     ?? '' );
    $sport        = sanitize_key( $_POST['sport']        ?? '' );
    $player       = sanitize_text_field( $_POST['player']       ?? '' );
    $market_key   = sanitize_key( $_POST['market_key']   ?? '' );
    $market_label = sanitize_text_field( $_POST['market_label'] ?? '' );
    $line         = sanitize_text_field( $_POST['line']         ?? '' );
    $direction    = in_array( $_POST['direction'] ?? 'over', [ 'over', 'under' ], true ) ? $_POST['direction'] : 'over';
    $target_odds  = (int) ( $_POST['target_odds'] ?? 0 );
    $matchup      = sanitize_text_field( $_POST['matchup']      ?? '' );

    if ( ! $event_id || ! $player || ! $market_key || ! $line ) {
        wp_send_json_error( [ 'message' => 'Missing required fields.' ], 400 );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'statsight_prop_alerts';

    // One active alert per user+event+player+market+line+direction.
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE user_id = %d AND event_id = %s AND player = %s AND market_key = %s AND line = %s AND direction = %s AND triggered = 0",
        $user_id, $event_id, $player, $market_key, $line, $direction
    ) );

    if ( $existing ) {
        // Update target odds on the existing alert.
        $wpdb->update( $table, [ 'target_odds' => $target_odds ], [ 'id' => (int) $existing ] );
        wp_send_json_success( [ 'id' => (int) $existing, 'updated' => true ] );
    }

    $wpdb->insert( $table, [
        'user_id'      => $user_id,
        'event_id'     => $event_id,
        'sport'        => $sport,
        'player'       => $player,
        'market_key'   => $market_key,
        'market_label' => $market_label,
        'line'         => $line,
        'direction'    => $direction,
        'target_odds'  => $target_odds,
        'matchup'      => $matchup,
        'triggered'    => 0,
        'created_at'   => current_time( 'mysql', true ),
    ] );

    wp_send_json_success( [ 'id' => (int) $wpdb->insert_id ] );
}
add_action( 'wp_ajax_statsight_prop_alert_set', 'statsight_ajax_prop_alert_set' );

function statsight_ajax_prop_alert_delete(): void {
    if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'statsight_events' ) &&
         ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'statsight_account' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }
    statsight_require_plan( 'pro' );
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
    }

    $id = (int) ( $_POST['id'] ?? 0 );
    if ( ! $id ) {
        wp_send_json_error( [ 'message' => 'Missing id.' ], 400 );
    }

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'statsight_prop_alerts', [ 'id' => $id, 'user_id' => $user_id ] );
    wp_send_json_success();
}
add_action( 'wp_ajax_statsight_prop_alert_delete', 'statsight_ajax_prop_alert_delete' );

function statsight_ajax_prop_alert_get(): void {
    // Accept either the props-page nonce or the account-page nonce.
    if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'statsight_events' ) &&
         ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'statsight_account' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_success( [] );
    }

    $event_id = sanitize_text_field( $_GET['event_id'] ?? '' );

    global $wpdb;
    $table = $wpdb->prefix . 'statsight_prop_alerts';

    if ( $event_id ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, player, market_key, market_label, line, direction, target_odds, matchup FROM {$table} WHERE user_id = %d AND event_id = %s AND triggered = 0",
            $user_id, $event_id
        ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, event_id, player, market_key, market_label, line, direction, target_odds, matchup FROM {$table} WHERE user_id = %d AND triggered = 0",
            $user_id
        ), ARRAY_A );
    }

    wp_send_json_success( $rows ?: [] );
}
add_action( 'wp_ajax_statsight_prop_alert_get', 'statsight_ajax_prop_alert_get' );

// ── Prop consensus counts ─────────────────────────────────────────────────
function statsight_ajax_prop_consensus(): void {
    if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'statsight_events' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }

    $event_id = sanitize_text_field( $_GET['event_id'] ?? '' );
    if ( ! $event_id ) {
        wp_send_json_error( [ 'message' => 'Missing event_id.' ], 400 );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'statsight_watchlist';

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT player, market_key, line, direction, COUNT(DISTINCT user_id) AS cnt
         FROM {$table}
         WHERE event_id = %s
         GROUP BY player, market_key, line, direction",
        $event_id
    ), ARRAY_A );

    // Return as a flat map: "player|market_key|line|direction" => count
    $map = [];
    foreach ( $rows as $row ) {
        $key       = $row['player'] . '|' . $row['market_key'] . '|' . $row['line'] . '|' . $row['direction'];
        $map[$key] = (int) $row['cnt'];
    }

    wp_send_json_success( $map );
}
add_action( 'wp_ajax_statsight_prop_consensus',             'statsight_ajax_prop_consensus' );
add_action( 'wp_ajax_nopriv_statsight_prop_consensus',      'statsight_ajax_prop_consensus' );

// ── Game polls ────────────────────────────────────────────────────────────────

/**
 * Get or create the poll for an event, and return vote counts + user's vote.
 * Accepts a props payload so the server can pick a random prop.
 */
function statsight_ajax_poll_get(): void {
    if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'statsight_events' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }

    $event_id = sanitize_text_field( $_REQUEST['event_id'] ?? '' );
    if ( ! $event_id ) {
        wp_send_json_error( [ 'message' => 'Missing event_id.' ], 400 );
    }

    global $wpdb;
    $polls_table = $wpdb->prefix . 'statsight_polls';
    $votes_table = $wpdb->prefix . 'statsight_poll_votes';

    // Check for existing poll.
    $poll = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$polls_table} WHERE event_id = %s",
        $event_id
    ), ARRAY_A );

    // Create poll if none exists — client sends candidate props.
    if ( ! $poll ) {
        $candidates_raw = stripslashes( $_REQUEST['candidates'] ?? '[]' );
        $candidates     = json_decode( $candidates_raw, true );

        if ( empty( $candidates ) || ! is_array( $candidates ) ) {
            wp_send_json_error( [ 'message' => 'No candidates.' ], 400 );
        }

        // Pick a random candidate.
        $pick = $candidates[ array_rand( $candidates ) ];

        $commence_raw  = sanitize_text_field( $_REQUEST['commence_time'] ?? '' );
        $commence_time = $commence_raw ? gmdate( 'Y-m-d H:i:s', strtotime( $commence_raw ) ) : null;

        $wpdb->insert( $polls_table, [
            'event_id'      => $event_id,
            'sport'         => sanitize_text_field( $pick['sport']        ?? '' ),
            'player'        => sanitize_text_field( $pick['player']       ?? '' ),
            'market_key'    => sanitize_text_field( $pick['market_key']   ?? '' ),
            'market_label'  => sanitize_text_field( $pick['market_label'] ?? '' ),
            'line'          => sanitize_text_field( $pick['line']         ?? '' ),
            'commence_time' => $commence_time,
            'best_over_odds'  => isset( $pick['best_over_odds'] )  ? (int) $pick['best_over_odds']  : null,
            'best_over_book'  => sanitize_text_field( $pick['best_over_book']  ?? '' ),
            'best_under_odds' => isset( $pick['best_under_odds'] ) ? (int) $pick['best_under_odds'] : null,
            'best_under_book' => sanitize_text_field( $pick['best_under_book'] ?? '' ),
            'created_at'    => current_time( 'mysql', true ),
        ] );

        $poll = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$polls_table} WHERE event_id = %s",
            $event_id
        ), ARRAY_A );
    }

    if ( ! $poll ) {
        wp_send_json_error( [ 'message' => 'Could not create poll.' ], 500 );
    }

    // Get vote counts.
    $counts = $wpdb->get_results( $wpdb->prepare(
        "SELECT vote, COUNT(*) AS cnt FROM {$votes_table} WHERE poll_id = %d GROUP BY vote",
        $poll['id']
    ), ARRAY_A );

    $tally = [ 'over' => 0, 'under' => 0 ];
    foreach ( $counts as $row ) {
        if ( isset( $tally[ $row['vote'] ] ) ) {
            $tally[ $row['vote'] ] = (int) $row['cnt'];
        }
    }

    // Get current user's vote if logged in.
    $user_id   = get_current_user_id();
    $user_vote = null;
    if ( $user_id ) {
        $user_vote = $wpdb->get_var( $wpdb->prepare(
            "SELECT vote FROM {$votes_table} WHERE poll_id = %d AND user_id = %d",
            $poll['id'], $user_id
        ) );
    }

    $now    = current_time( 'mysql', true );
    $locked = $poll['result'] !== null
        || ( ! empty( $poll['commence_time'] ) && $poll['commence_time'] <= $now );

    wp_send_json_success( [
        'poll_id'      => (int) $poll['id'],
        'player'       => $poll['player'],
        'market_label' => $poll['market_label'],
        'line'         => $poll['line'],
        'result'       => $poll['result'],
        'locked'       => $locked,
        'best_over_odds'  => $poll['best_over_odds']  !== null ? (int) $poll['best_over_odds']  : null,
        'best_over_book'  => $poll['best_over_book'],
        'best_under_odds' => $poll['best_under_odds'] !== null ? (int) $poll['best_under_odds'] : null,
        'best_under_book' => $poll['best_under_book'],
        'tally'        => $tally,
        'user_vote'    => $user_vote,
    ] );
}
add_action( 'wp_ajax_statsight_poll_get',        'statsight_ajax_poll_get' );
add_action( 'wp_ajax_nopriv_statsight_poll_get', 'statsight_ajax_poll_get' );

/**
 * Cast or change a vote on a poll.
 */
function statsight_ajax_poll_vote(): void {
    if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'statsight_events' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'Must be logged in to vote.' ], 403 );
    }

    $poll_id = (int) ( $_REQUEST['poll_id'] ?? 0 );
    $vote    = sanitize_text_field( $_REQUEST['vote'] ?? '' );

    if ( ! $poll_id || ! in_array( $vote, [ 'over', 'under' ], true ) ) {
        wp_send_json_error( [ 'message' => 'Invalid parameters.' ], 400 );
    }

    global $wpdb;
    $polls_table = $wpdb->prefix . 'statsight_polls';
    $votes_table = $wpdb->prefix . 'statsight_poll_votes';

    // Ensure poll exists and hasn't been settled.
    $poll = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, result FROM {$polls_table} WHERE id = %d",
        $poll_id
    ), ARRAY_A );

    if ( ! $poll ) {
        wp_send_json_error( [ 'message' => 'Poll not found.' ], 404 );
    }
    $now = current_time( 'mysql', true );
    if ( $poll['result'] !== null || ( ! empty( $poll['commence_time'] ) && $poll['commence_time'] <= $now ) ) {
        wp_send_json_error( [ 'message' => 'Poll is closed.' ], 400 );
    }

    // Upsert vote.
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$votes_table} (poll_id, user_id, vote, voted_at)
         VALUES (%d, %d, %s, %s)
         ON DUPLICATE KEY UPDATE vote = VALUES(vote), voted_at = VALUES(voted_at)",
        $poll_id, $user_id, $vote, current_time( 'mysql', true )
    ) );

    // Return updated tally.
    $counts = $wpdb->get_results( $wpdb->prepare(
        "SELECT vote, COUNT(*) AS cnt FROM {$votes_table} WHERE poll_id = %d GROUP BY vote",
        $poll_id
    ), ARRAY_A );

    $tally = [ 'over' => 0, 'under' => 0 ];
    foreach ( $counts as $row ) {
        if ( isset( $tally[ $row['vote'] ] ) ) {
            $tally[ $row['vote'] ] = (int) $row['cnt'];
        }
    }

    wp_send_json_success( [ 'tally' => $tally, 'user_vote' => $vote ] );
}
add_action( 'wp_ajax_statsight_poll_vote', 'statsight_ajax_poll_vote' );

// ── Community / Follows ───────────────────────────────────────────────────────

function statsight_ajax_set_public_watchlist(): void {
    if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'statsight_account' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 403 );
    }
    $public = (int) ( $_REQUEST['public'] ?? 0 );
    update_user_meta( $user_id, 'statsight_watchlist_public', $public ? 1 : 0 );
    wp_send_json_success();
}
add_action( 'wp_ajax_statsight_set_public_watchlist', 'statsight_ajax_set_public_watchlist' );

function statsight_ajax_set_record_public(): void {
    if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'statsight_account' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 403 );
    }
    $public = (int) ( $_REQUEST['public'] ?? 0 );
    update_user_meta( $user_id, 'statsight_record_public', $public ? 1 : 0 );
    wp_send_json_success();
}
add_action( 'wp_ajax_statsight_set_record_public', 'statsight_ajax_set_record_public' );

function statsight_ajax_set_collections_public(): void {
    if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'statsight_account' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 403 );
    }
    $public = (int) ( $_REQUEST['public'] ?? 0 );
    update_user_meta( $user_id, 'statsight_collections_public', $public ? 1 : 0 );
    wp_send_json_success();
}
add_action( 'wp_ajax_statsight_set_collections_public', 'statsight_ajax_set_collections_public' );

function statsight_ajax_follow(): void {
    if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'statsight_account' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }
    $follower_id  = get_current_user_id();
    $following_id = (int) ( $_REQUEST['user_id'] ?? 0 );
    if ( ! $follower_id || ! $following_id || $follower_id === $following_id ) {
        wp_send_json_error( [ 'message' => 'Invalid.' ], 400 );
    }
    global $wpdb;
    $table  = $wpdb->prefix . 'statsight_follows';
    $action = sanitize_text_field( $_REQUEST['action_type'] ?? 'follow' );
    if ( $action === 'unfollow' ) {
        $wpdb->delete( $table, [ 'follower_id' => $follower_id, 'following_id' => $following_id ] );
    } else {
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table} (follower_id, following_id, created_at) VALUES (%d, %d, %s)",
            $follower_id, $following_id, current_time( 'mysql', true )
        ) );
    }
    wp_send_json_success();
}
add_action( 'wp_ajax_statsight_follow', 'statsight_ajax_follow' );

function statsight_ajax_community_feed(): void {
    if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'statsight_account' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 403 );
    }
    global $wpdb;
    $follows_table   = $wpdb->prefix . 'statsight_follows';
    $watchlist_table = $wpdb->prefix . 'statsight_watchlist';
    $offset          = (int) ( $_REQUEST['offset'] ?? 0 );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT w.player, w.market_key, w.market_label, w.line, w.direction, w.odds, w.book, w.matchup, w.sport, w.event_id, w.all_books, w.added_at, w.game_start_time, w.result,
                u.display_name, u.ID AS user_id
         FROM {$watchlist_table} w
         JOIN {$follows_table} f   ON f.following_id = w.user_id AND f.follower_id = %d
         JOIN {$wpdb->users} u     ON u.ID = w.user_id
         JOIN {$wpdb->usermeta} um ON um.user_id = w.user_id AND um.meta_key = 'statsight_watchlist_public' AND um.meta_value = '1'
         WHERE w.deleted_at IS NULL
           AND (w.game_start_time IS NULL OR w.game_start_time > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY))
         ORDER BY w.added_at DESC
         LIMIT 20 OFFSET %d",
        $user_id, $offset
    ), ARRAY_A );

    // Attach pick records for each unique user in the feed — only if they've opted in.
    $pick_records = [];
    foreach ( array_unique( array_column( $rows ?: [], 'user_id' ) ) as $uid ) {
        if ( get_user_meta( (int) $uid, 'statsight_record_public', true ) ) {
            $pick_records[ (int) $uid ] = statsight_get_user_pick_record( (int) $uid );
        }
    }

    wp_send_json_success( [
        'rows'         => $rows ?: [],
        'pick_records' => $pick_records,
    ] );
}
add_action( 'wp_ajax_statsight_community_feed', 'statsight_ajax_community_feed' );

function statsight_ajax_community_discover(): void {
    if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'statsight_account' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 403 );
    }
    global $wpdb;
    $follows_table   = $wpdb->prefix . 'statsight_follows';
    $watchlist_table = $wpdb->prefix . 'statsight_watchlist';

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT u.ID AS user_id, u.display_name,
                COUNT(DISTINCT w.id)          AS watchlist_count,
                MAX(w.added_at)               AS last_active,
                SUM(f2.follower_id IS NOT NULL) AS is_following
         FROM {$wpdb->users} u
         JOIN {$wpdb->usermeta} um  ON um.user_id = u.ID AND um.meta_key = 'statsight_watchlist_public' AND um.meta_value = '1'
         LEFT JOIN {$watchlist_table} w  ON w.user_id = u.ID
         LEFT JOIN {$follows_table}   f2 ON f2.follower_id = %d AND f2.following_id = u.ID
         WHERE u.ID != %d
         GROUP BY u.ID
         ORDER BY watchlist_count DESC
         LIMIT 20",
        $user_id, $user_id
    ), ARRAY_A );

    // Grab the most recent prop for each user.
    $user_ids = array_column( $rows, 'user_id' );
    $recent   = [];
    if ( $user_ids ) {
        $placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
        $recent_rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT w.user_id, w.player, w.market_label, w.line, w.direction
             FROM {$watchlist_table} w
             INNER JOIN (
                 SELECT user_id, MAX(added_at) AS max_at
                 FROM {$watchlist_table}
                 WHERE user_id IN ({$placeholders})
                 GROUP BY user_id
             ) latest ON latest.user_id = w.user_id AND latest.max_at = w.added_at
             GROUP BY w.user_id",
            ...$user_ids
        ), ARRAY_A );
        foreach ( $recent_rows as $r ) {
            $recent[ $r['user_id'] ] = $r;
        }
    }

    foreach ( $rows as &$row ) {
        $row['watchlist_count'] = (int) $row['watchlist_count'];
        $row['is_following']    = (bool) $row['is_following'];
        if ( get_user_meta( (int) $row['user_id'], 'statsight_record_public', true ) ) {
            $row['pick_record'] = statsight_get_user_pick_record( (int) $row['user_id'] );
        } else {
            $row['pick_record'] = null;
        }
    }
    unset( $row );

    wp_send_json_success( $rows );
}
add_action( 'wp_ajax_statsight_community_discover', 'statsight_ajax_community_discover' );

function statsight_ajax_community_search(): void {
    if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'statsight_account' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }
    $viewer_id = get_current_user_id();
    if ( ! $viewer_id ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 403 );
    }

    $q = sanitize_text_field( wp_unslash( $_REQUEST['q'] ?? '' ) );
    if ( strlen( $q ) < 2 ) {
        wp_send_json_success( [] );
    }

    global $wpdb;
    $follows_table   = $wpdb->prefix . 'statsight_follows';
    $watchlist_table = $wpdb->prefix . 'statsight_watchlist';
    $like            = '%' . $wpdb->esc_like( $q ) . '%';

    // Search display_name, user_login, first_name meta, last_name meta.
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT u.ID AS user_id, u.display_name,
                COUNT(DISTINCT w.id)            AS watchlist_count,
                SUM(f2.follower_id IS NOT NULL) AS is_following
         FROM {$wpdb->users} u
         JOIN {$wpdb->usermeta} um_pub ON um_pub.user_id = u.ID
                                      AND um_pub.meta_key = 'statsight_watchlist_public'
                                      AND um_pub.meta_value = '1'
         LEFT JOIN {$watchlist_table} w   ON w.user_id = u.ID
         LEFT JOIN {$follows_table}   f2  ON f2.follower_id = %d AND f2.following_id = u.ID
         LEFT JOIN {$wpdb->usermeta} um_f ON um_f.user_id = u.ID AND um_f.meta_key = 'first_name'
         LEFT JOIN {$wpdb->usermeta} um_l ON um_l.user_id = u.ID AND um_l.meta_key = 'last_name'
         WHERE u.ID != %d
           AND (
               u.display_name LIKE %s
            OR u.user_login   LIKE %s
            OR um_f.meta_value LIKE %s
            OR um_l.meta_value LIKE %s
           )
         GROUP BY u.ID
         ORDER BY watchlist_count DESC
         LIMIT 20",
        $viewer_id, $viewer_id, $like, $like, $like, $like
    ), ARRAY_A );

    if ( ! $rows ) {
        wp_send_json_success( [] );
    }

    // Grab the most recent prop for each matched user.
    $user_ids = array_column( $rows, 'user_id' );
    $recent   = [];
    if ( $user_ids ) {
        $placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
        $recent_rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT w.user_id, w.player, w.market_label, w.line, w.direction
             FROM {$watchlist_table} w
             INNER JOIN (
                 SELECT user_id, MAX(added_at) AS max_at
                 FROM {$watchlist_table}
                 WHERE user_id IN ({$placeholders})
                 GROUP BY user_id
             ) latest ON latest.user_id = w.user_id AND latest.max_at = w.added_at
             GROUP BY w.user_id",
            ...$user_ids
        ), ARRAY_A );
        foreach ( $recent_rows as $r ) {
            $recent[ $r['user_id'] ] = $r;
        }
    }

    foreach ( $rows as &$row ) {
        $row['watchlist_count'] = (int) $row['watchlist_count'];
        $row['is_following']    = (bool) $row['is_following'];
        $row['recent_prop']     = $recent[ $row['user_id'] ] ?? null;
    }
    unset( $row );

    wp_send_json_success( $rows );
}
add_action( 'wp_ajax_statsight_community_search', 'statsight_ajax_community_search' );

function statsight_ajax_get_following(): void {
    if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'statsight_account' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 403 );
    }

    global $wpdb;
    $follows_table = $wpdb->prefix . 'statsight_follows';

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT u.ID AS user_id, u.display_name
         FROM {$follows_table} f
         JOIN {$wpdb->users} u ON u.ID = f.following_id
         WHERE f.follower_id = %d
         ORDER BY f.created_at DESC",
        $user_id
    ), ARRAY_A );

    wp_send_json_success( $rows ?: [] );
}
add_action( 'wp_ajax_statsight_get_following', 'statsight_ajax_get_following' );

function statsight_ajax_community_profile(): void {
    if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'statsight_account' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 403 );
    }

    $profile_user_id = (int) ( $_REQUEST['profile_user_id'] ?? 0 );
    if ( ! $profile_user_id ) {
        wp_send_json_error( [ 'message' => 'Invalid user.' ], 400 );
    }

    $viewer_id = get_current_user_id();

    // Allow own profile always; others only if watchlist is public.
    $is_public      = get_user_meta( $profile_user_id, 'statsight_watchlist_public', true );
    $is_own_profile = ( $viewer_id === $profile_user_id );
    if ( ! $is_public && ! $is_own_profile ) {
        wp_send_json_error( [ 'message' => 'This watchlist is private.' ], 403 );
    }

    $profile_user = get_userdata( $profile_user_id );
    if ( ! $profile_user ) {
        wp_send_json_error( [ 'message' => 'User not found.' ], 404 );
    }

    global $wpdb;
    $follows_table   = $wpdb->prefix . 'statsight_follows';
    $watchlist_table = $wpdb->prefix . 'statsight_watchlist';
    $offset          = (int) ( $_REQUEST['offset'] ?? 0 );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT player, market_key, market_label, line, direction, odds, book, matchup, sport, event_id, all_books, added_at, game_start_time, result
         FROM {$watchlist_table}
         WHERE user_id    = %d
           AND deleted_at IS NULL
         ORDER BY added_at DESC
         LIMIT 20 OFFSET %d",
        $profile_user_id, $offset
    ), ARRAY_A );

    $is_following = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$follows_table} WHERE follower_id = %d AND following_id = %d",
        $viewer_id, $profile_user_id
    ) );
    $record_is_public      = get_user_meta( $profile_user_id, 'statsight_record_public', true );
    $collections_is_public = get_user_meta( $profile_user_id, 'statsight_collections_public', true );

    $pick_record = ( $is_own_profile || $record_is_public )
        ? statsight_get_user_pick_record( $profile_user_id )
        : null;

    $collections = [];
    if ( $is_own_profile || $collections_is_public ) {
        $parlays_table = $wpdb->prefix . 'statsight_parlays';
        $raw_parlays   = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, legs_json, created_at FROM {$parlays_table} WHERE user_id = %d ORDER BY created_at DESC",
            $profile_user_id
        ), ARRAY_A );
        foreach ( $raw_parlays as $p ) {
            $collections[] = [
                'id'         => (int) $p['id'],
                'name'       => $p['name'],
                'legs'       => ! empty( $p['legs_json'] ) ? json_decode( $p['legs_json'], true ) : [],
                'created_at' => $p['created_at'],
            ];
        }
    }

    wp_send_json_success( [
        'display_name'          => $profile_user->display_name,
        'user_id'               => $profile_user_id,
        'is_following'          => $is_following,
        'props'                 => $rows ?: [],
        'pick_record'           => $pick_record,
        'record_is_public'      => (bool) $record_is_public,
        'collections'           => $collections,
        'collections_is_public' => (bool) $collections_is_public,
    ] );
}
add_action( 'wp_ajax_statsight_community_profile', 'statsight_ajax_community_profile' );

// ── SMTP configuration for wp_mail ───────────────────────────────────────
add_filter( 'wp_mail_from',      fn() => defined( 'STATSIGHT_SMTP_USER' ) ? STATSIGHT_SMTP_USER : 'info@xstatiq.io' );
add_filter( 'wp_mail_from_name', fn() => get_bloginfo( 'name' ) );

add_action( 'phpmailer_init', function ( $phpmailer ): void {
    $phpmailer->isSMTP();
    $phpmailer->Host       = 'smtp.gmail.com';
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Port       = 587;
    $phpmailer->SMTPSecure = 'tls';
    $phpmailer->Username   = defined( 'STATSIGHT_SMTP_USER' ) ? STATSIGHT_SMTP_USER : '';
    $phpmailer->Password   = defined( 'STATSIGHT_SMTP_PASS' ) ? STATSIGHT_SMTP_PASS : '';
} );

// ── WooCommerce: remove privacy policy text from registration form ────────
add_action( 'init', function (): void {
    remove_action( 'woocommerce_register_form', 'wc_registration_privacy_policy_text', 20 );
} );

// ── Odds API quota tracker ────────────────────────────────────────────────

/**
 * Extract quota headers from any Odds API response and persist them.
 * Call this after every successful wp_remote_get() to the Odds API.
 */
function statsight_record_quota( array $response ): void {
    $remaining = wp_remote_retrieve_header( $response, 'x-requests-remaining' );
    $used      = wp_remote_retrieve_header( $response, 'x-requests-used' );

    if ( $remaining === '' && $used === '' ) {
        return;
    }

    update_option( 'statsight_quota', [
        'remaining' => $remaining !== '' ? (int) $remaining : null,
        'used'      => $used !== '' ? (int) $used : null,
        'updated'   => time(),
    ], false );
}

/**
 * Admin dashboard widget — shows remaining Odds API quota.
 */
function statsight_quota_dashboard_widget(): void {
    $quota = get_option( 'statsight_quota' );
    ?>
    <div style="font-family:sans-serif;">
        <?php if ( ! $quota ) : ?>
            <p style="color:#999;margin:0;">No data yet — quota is recorded on the next live API call.</p>
        <?php else :
            $remaining = $quota['remaining'] ?? null;
            $used      = $quota['used'] ?? null;
            $total     = ( $remaining !== null && $used !== null ) ? $remaining + $used : null;
            $pct_used  = ( $total && $total > 0 ) ? round( ( $used / $total ) * 100 ) : null;
            $updated   = isset( $quota['updated'] ) ? human_time_diff( $quota['updated'] ) . ' ago' : 'unknown';

            $bar_color = '#2e7cf6';
            if ( $pct_used !== null ) {
                if ( $pct_used >= 90 ) $bar_color = '#e53e3e';
                elseif ( $pct_used >= 70 ) $bar_color = '#dd6b20';
            }
        ?>
            <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
                <tr>
                    <td style="padding:4px 0;color:#999;">Requests used</td>
                    <td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo $used !== null ? number_format( $used ) : '—'; ?></td>
                </tr>
                <tr>
                    <td style="padding:4px 0;color:#999;">Requests remaining</td>
                    <td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo $remaining !== null ? number_format( $remaining ) : '—'; ?></td>
                </tr>
                <?php if ( $total ) : ?>
                <tr>
                    <td style="padding:4px 0;color:#999;">Monthly quota</td>
                    <td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo number_format( $total ); ?></td>
                </tr>
                <?php endif; ?>
            </table>

            <?php if ( $pct_used !== null ) : ?>
            <div style="margin:12px 0 4px;background:#e2e8f0;border-radius:4px;height:8px;overflow:hidden;">
                <div style="width:<?php echo esc_attr( $pct_used ); ?>%;background:<?php echo esc_attr( $bar_color ); ?>;height:100%;border-radius:4px;transition:width .3s;"></div>
            </div>
            <p style="margin:4px 0 0;font-size:0.78rem;color:#999;"><?php echo esc_html( $pct_used ); ?>% used</p>
            <?php endif; ?>

            <p style="margin:12px 0 0;font-size:0.78rem;color:#bbb;">Last recorded: <?php echo esc_html( $updated ); ?></p>
        <?php endif; ?>
    </div>
    <?php
}

function statsight_register_dashboard_widgets(): void {
    wp_add_dashboard_widget(
        'statsight_quota_widget',
        'Odds API Quota',
        'statsight_quota_dashboard_widget'
    );
}
add_action( 'wp_dashboard_setup', 'statsight_register_dashboard_widgets' );

// ── Login page branding ───────────────────────────────────────────────────

function statsight_login_styles(): void {
    wp_enqueue_style(
        'statsight-login',
        get_template_directory_uri() . '/login.css',
        [],
        wp_get_theme()->get( 'Version' )
    );
}
add_action( 'login_enqueue_scripts', 'statsight_login_styles' );

function statsight_login_logo_url(): string {
    return home_url( '/' );
}
add_filter( 'login_headerurl', 'statsight_login_logo_url' );

function statsight_login_logo_text(): string {
    return get_bloginfo( 'name' );
}
add_filter( 'login_headertext', 'statsight_login_logo_text' );

/**
 * AJAX handler — fetch upcoming events for a sport from The Odds API.
 *
 * Finds the next calendar date that has at least one game and returns
 * all events on that date. Results are cached per sport for 15 minutes.
 */
/**
 * Fetch (or return cached) events for a sport.
 * Returns the same [ 'days' => [...] ] structure stored in the transient,
 * or null on failure. Shared by the AJAX handler and internal callers.
 */
function statsight_get_events_for_sport( string $sport ): ?array {
    $cache_key = 'statsight_events_' . $sport;
    $cached    = get_transient( $cache_key );

    if ( false !== $cached ) {
        return $cached;
    }

    $fetch_enabled = get_field( 'odds_data_fetch', 'option' );
    if ( $fetch_enabled !== false && ! $fetch_enabled ) {
        return [ 'days' => [] ];
    }

    if ( ! defined( 'THE_ODDS_API_KEY' ) || empty( THE_ODDS_API_KEY ) ) {
        return null;
    }

    $et_midnight_utc = ( new DateTime( 'yesterday noon', new DateTimeZone( 'America/New_York' ) ) )
        ->setTimezone( new DateTimeZone( 'UTC' ) )
        ->format( 'Y-m-d\TH:i:s\Z' );

    $et_3days_utc = ( new DateTime( '+3 days midnight', new DateTimeZone( 'America/New_York' ) ) )
        ->setTimezone( new DateTimeZone( 'UTC' ) )
        ->format( 'Y-m-d\TH:i:s\Z' );

    $url = 'https://api.the-odds-api.com/v4/sports/' . $sport . '/events?' . http_build_query( [
        'apiKey'           => THE_ODDS_API_KEY,
        'regions'          => 'us',
        'dateFormat'       => 'iso',
        'commenceTimeFrom' => $et_midnight_utc,
        'commenceTimeTo'   => $et_3days_utc,
    ] );

    $response = wp_remote_get( $url, [ 'timeout' => 15 ] );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return null;
    }

    statsight_record_quota( $response );

    $events = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! is_array( $events ) ) {
        return null;
    }

    if ( empty( $events ) ) {
        $payload = [ 'days' => [] ];
        set_transient( $cache_key, $payload, 15 * MINUTE_IN_SECONDS );
        return $payload;
    }

    // Games in US time zones can straddle UTC midnight — e.g. a 8 PM ET tip-off
    // is 00:00+ UTC the next calendar day. Group by Eastern Time date instead so
    // all games on the same US slate appear together. ET = UTC-5 (EST) / UTC-4 (EDT).
    $et_tz = new DateTimeZone( 'America/New_York' );

    $et_date_of = function ( string $iso ) use ( $et_tz ): string {
        return ( new DateTime( $iso ) )->setTimezone( $et_tz )->format( 'Y-m-d' );
    };

    // Group events by ET date.
    $grouped = [];
    foreach ( $events as $event ) {
        $date = $et_date_of( $event['commence_time'] ?? '' );
        if ( $date ) {
            $grouped[ $date ][] = $event;
        }
    }

    // Sort dates ascending.
    ksort( $grouped );

    $now_utc      = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
    $today_et     = ( clone $now_utc )->setTimezone( $et_tz )->format( 'Y-m-d' );
    $cutoff_et    = ( new DateTime( '+3 days midnight', new DateTimeZone( 'America/New_York' ) ) )->format( 'Y-m-d' );

    // Include dates that have at least one game not yet started or within 4 hours
    // of having started (live/recently finished), plus future dates up to 3 days out —
    // max 2 date buckets total.
    $selected_dates = [];
    $upcoming_count = 0;

    foreach ( array_keys( $grouped ) as $date ) {
        if ( $date < $today_et ) {
            // Yesterday or earlier — only include if any game hasn't started yet.
            $has_recent = false;
            foreach ( $grouped[ $date ] as $ev ) {
                $commence = new DateTime( $ev['commence_time'] ?? 'now' );
                if ( $commence->getTimestamp() > $now_utc->getTimestamp() ) {
                    $has_recent = true;
                    break;
                }
            }
            if ( $has_recent ) {
                $selected_dates[] = $date;
            }
        } else {
            // Skip dates beyond the 3-day window.
            if ( $date >= $cutoff_et ) {
                continue;
            }

            // Today or near-future — include if at least one game hasn't started yet
            // or started within the last 4 hours (likely still live).
            $four_hours_ago = $now_utc->getTimestamp() - ( 4 * HOUR_IN_SECONDS );
            $has_active     = false;
            foreach ( $grouped[ $date ] as $ev ) {
                $commence = new DateTime( $ev['commence_time'] ?? 'now' );
                $ts       = $commence->getTimestamp();
                if ( $ts > $now_utc->getTimestamp() || $ts >= $four_hours_ago ) {
                    $has_active = true;
                    break;
                }
            }
            if ( $has_active && $upcoming_count < 2 ) {
                $selected_dates[] = $date;
                $upcoming_count++;
            }
        }
    }

    $days = [];
    foreach ( $selected_dates as $date ) {
        $day_events = $grouped[ $date ];
        usort( $day_events, fn( array $a, array $b ) => strcmp( $a['commence_time'], $b['commence_time'] ) );
        $days[] = [ 'date' => $date, 'events' => $day_events ];
    }

    $payload = [ 'days' => $days ];
    set_transient( $cache_key, $payload, 15 * MINUTE_IN_SECONDS );
    return $payload;
}

function statsight_ajax_get_events(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );

    $sport = isset( $_GET['sport'] ) ? sanitize_key( $_GET['sport'] ) : '';

    if ( empty( $sport ) ) {
        wp_send_json_error( [ 'message' => 'Missing sport parameter.' ], 400 );
    }

    $result = statsight_get_events_for_sport( $sport );
    if ( $result === null ) {
        wp_send_json_error( [ 'message' => 'Could not fetch events.' ], 502 );
    }
    wp_send_json_success( $result );
}
add_action( 'wp_ajax_statsight_get_events',        'statsight_ajax_get_events' );
add_action( 'wp_ajax_nopriv_statsight_get_events', 'statsight_ajax_get_events' );

/**
 * Maps a The Odds API sport key to the ESPN scoreboard URL for that sport.
 * Returns an empty string for sports we don't have an ESPN mapping for.
 */
function statsight_espn_scoreboard_url( string $sport_key ): string {
    $map = [
        'basketball_nba'          => 'basketball/nba',
        'basketball_wnba'         => 'basketball/wnba',
        'americanfootball_nfl'    => 'football/nfl',
        'americanfootball_ncaaf'  => 'football/college-football',
        'baseball_mlb'            => 'baseball/mlb',
        'icehockey_nhl'           => 'hockey/nhl',
        'basketball_ncaab'        => 'basketball/mens-college-basketball',
        'soccer_epl'              => 'soccer/eng.1',
        'soccer_usa_mls'          => 'soccer/usa.1',
    ];

    $slug = $map[ $sport_key ] ?? '';
    if ( empty( $slug ) ) {
        return '';
    }

    return 'https://site.api.espn.com/apis/site/v2/sports/' . $slug . '/scoreboard';
}

/**
 * Fetch all team logos for a sport from the ESPN teams endpoint.
 * Returns an array keyed by team displayName => logo URL.
 * Cached for 24 hours since logos rarely change.
 *
 * @return array<string, string>
 */
function statsight_espn_team_logos( array $path ): array {
    $cache_key = 'statsight_logos_' . $path['sport'] . '_' . $path['league'];
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached;
    }

    $url      = 'https://site.api.espn.com/apis/site/v2/sports/' . $path['sport'] . '/' . $path['league'] . '/teams?limit=500';
    $response = wp_remote_get( $url, [ 'timeout' => 10 ] );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return [];
    }

    $data   = json_decode( wp_remote_retrieve_body( $response ), true );
    $teams  = $data['sports'][0]['leagues'][0]['teams'] ?? [];
    $logos  = [];

    foreach ( $teams as $entry ) {
        $team = $entry['team'] ?? [];
        $name = $team['displayName'] ?? '';
        $logo = $team['logos'][0]['href'] ?? '';
        if ( $name && $logo ) {
            $logos[ $name ] = $logo;
        }
    }

    set_transient( $cache_key, $logos, DAY_IN_SECONDS );
    return $logos;
}

/**
 * AJAX handler — fetch live game statuses from the ESPN scoreboard for a sport.
 * Returns an array of { home, away } pairs for games currently in progress.
 * Results are cached for 1 minute to stay fresh without hammering ESPN.
 */
function statsight_ajax_get_live_games(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );

    $sport = isset( $_GET['sport'] ) ? sanitize_key( $_GET['sport'] ) : '';
    if ( empty( $sport ) ) {
        wp_send_json_error( [ 'message' => 'Missing sport parameter.' ], 400 );
    }

    $cache_key = 'statsight_live_' . $sport;
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        wp_send_json_success( $cached );
    }

    $url = statsight_espn_scoreboard_url( $sport );
    if ( empty( $url ) ) {
        // No ESPN mapping — return empty so the front end degrades gracefully.
        wp_send_json_success( [] );
    }

    $response = wp_remote_get( $url, [ 'timeout' => 10 ] );
    if ( is_wp_error( $response ) ) {
        wp_send_json_error( [ 'message' => $response->get_error_message() ], 502 );
    }

    $status = wp_remote_retrieve_response_code( $response );
    $body   = wp_remote_retrieve_body( $response );
    if ( $status !== 200 ) {
        wp_send_json_error( [ 'message' => "ESPN API returned HTTP {$status}." ], 502 );
    }

    $data = json_decode( $body, true );
    if ( ! is_array( $data ) ) {
        wp_send_json_error( [ 'message' => 'Could not parse ESPN response.' ], 502 );
    }

    $live  = [];
    $logos = []; // displayName => logo URL, built from all events regardless of state

    foreach ( $data['events'] ?? [] as $event ) {
        $state       = $event['status']['type']['state'] ?? '';
        $competitors = $event['competitions'][0]['competitors'] ?? [];

        // Collect logos from every event.
        foreach ( $competitors as $c ) {
            $name = $c['team']['displayName'] ?? '';
            $logo = $c['team']['logo']        ?? '';
            if ( $name && $logo && ! isset( $logos[ $name ] ) ) {
                $logos[ $name ] = $logo;
            }
        }

        // Only include live/ended games in the live list.
        if ( ! in_array( $state, [ 'in', 'post' ], true ) ) {
            continue;
        }

        $home       = '';
        $away       = '';
        $home_score = '';
        $away_score = '';
        foreach ( $competitors as $c ) {
            if ( ( $c['homeAway'] ?? '' ) === 'home' ) {
                $home       = $c['team']['displayName'] ?? '';
                $home_score = $c['score'] ?? '';
            } else {
                $away       = $c['team']['displayName'] ?? '';
                $away_score = $c['score'] ?? '';
            }
        }

        $period        = $event['status']['period']              ?? null;
        $display_clock = $event['status']['displayClock']       ?? '';
        $period_label  = $event['status']['type']['shortDetail'] ?? '';

        // ESPN sometimes returns the clock as raw seconds (e.g. "31.9") instead of
        // a formatted string. Detect and convert to M:SS format.
        if ( preg_match( '/^(\d+(?:\.\d+)?)\s*-\s*(.+)$/', $period_label, $m ) ) {
            $raw_secs     = (float) $m[1];
            $period_part  = $m[2];
            $mins         = (int) floor( $raw_secs / 60 );
            $secs         = (int) round( $raw_secs - $mins * 60 );
            $period_label = $mins . ':' . str_pad( $secs, 2, '0', STR_PAD_LEFT ) . ' - ' . $period_part;
        }

        // Include the ET date of the game so the front end can avoid matching
        // yesterday's finished game against today's upcoming rematch.
        $game_date_et = '';
        $date_str     = $event['date'] ?? '';
        if ( $date_str ) {
            $game_date_et = ( new DateTime( $date_str ) )
                ->setTimezone( new DateTimeZone( 'America/New_York' ) )
                ->format( 'Y-m-d' );
        }

        if ( $home && $away ) {
            $live[] = [
                'home'         => $home,
                'away'         => $away,
                'state'        => $state,
                'home_score'   => $home_score,
                'away_score'   => $away_score,
                'period'       => $period,
                'clock'        => $display_clock,
                'period_label' => $period_label,
                'date_et'      => $game_date_et,
            ];
        }
    }

    // If the scoreboard didn't cover all teams (e.g. no games today), fall back
    // to the full ESPN teams endpoint which has every team's logo.
    $path = statsight_espn_sport_path( $sport );
    if ( $path ) {
        $team_logos = statsight_espn_team_logos( $path );
        // Merge: scoreboard logos take priority, teams endpoint fills the gaps.
        $logos = array_merge( $team_logos, $logos );
    }

    $payload = [ 'live' => $live, 'logos' => $logos ];

    // Cache for 30 seconds — short enough for live score updates.
    set_transient( $cache_key, $payload, 30 );
    wp_send_json_success( $payload );
}
add_action( 'wp_ajax_statsight_get_live_games',        'statsight_ajax_get_live_games' );
add_action( 'wp_ajax_nopriv_statsight_get_live_games', 'statsight_ajax_get_live_games' );

/**
 * AJAX handler — return a flat player-name → stats map for a live/final game.
 *
 * Accepts: sport (sport key), home (home team name), away (away team name).
 * Returns: { "Player Name": { "PTS": "22", "REB": "8", ... }, ... }
 * Only fires for games that are in-progress or just finished.
 */
function statsight_ajax_get_live_boxscore(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );

    $sport = isset( $_GET['sport'] ) ? sanitize_key( $_GET['sport'] )    : '';
    $home  = isset( $_GET['home']  ) ? sanitize_text_field( $_GET['home'] ) : '';
    $away  = isset( $_GET['away']  ) ? sanitize_text_field( $_GET['away'] ) : '';

    if ( ! $sport || ! $home || ! $away ) {
        wp_send_json_error( [ 'message' => 'Missing parameters.' ], 400 );
    }

    $cache_key = 'statsight_boxscore_' . md5( $sport . $home . $away );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        wp_send_json_success( $cached );
    }

    $path = statsight_espn_sport_path( $sport );
    if ( ! $path ) {
        wp_send_json_success( [] );
    }

    $base_url      = 'https://site.api.espn.com/apis/site/v2/sports/' . $path['sport'] . '/' . $path['league'];
    $scoreboard_url = $base_url . '/scoreboard';

    $sb_response = wp_remote_get( $scoreboard_url, [ 'timeout' => 10 ] );
    if ( is_wp_error( $sb_response ) || wp_remote_retrieve_response_code( $sb_response ) !== 200 ) {
        wp_send_json_success( [] );
    }

    $sb_data = json_decode( wp_remote_retrieve_body( $sb_response ), true );

    // Find the matching event using loose name matching.
    // Normalise city abbreviations so "LA Clippers" matches "Los Angeles Clippers", etc.
    $norm_team = function ( string $s ): string {
        $s = strtolower( $s );
        $s = str_replace( 'los angeles', 'la',          $s );
        $s = str_replace( 'new york',   'ny',           $s );
        $s = str_replace( 'new orleans','no',           $s );
        $s = str_replace( 'golden state','gs',          $s );
        $s = str_replace( 'san antonio','sa',           $s );
        $s = str_replace( 'oklahoma city','okc',        $s );
        return trim( $s );
    };
    $name_match = function ( string $full, string $espn ) use ( $norm_team ): bool {
        $a = $norm_team( $full );
        $b = $norm_team( $espn );
        return $a === $b || str_contains( $a, $b ) || str_contains( $b, $a );
    };
    $event_id = null;

    foreach ( $sb_data['events'] ?? [] as $event ) {
        $state = $event['status']['type']['state'] ?? '';
        if ( ! in_array( $state, [ 'in', 'post' ], true ) ) {
            continue;
        }
        $competitors = $event['competitions'][0]['competitors'] ?? [];
        $espn_home   = '';
        $espn_away   = '';
        foreach ( $competitors as $c ) {
            $display = $c['team']['displayName'] ?? '';
            if ( ( $c['homeAway'] ?? '' ) === 'home' ) {
                $espn_home = $display;
            } else {
                $espn_away = $display;
            }
        }
        if ( $name_match( $home, $espn_home ) && $name_match( $away, $espn_away ) ) {
            $event_id = $event['id'];
            break;
        }
    }

    if ( ! $event_id ) {
        wp_send_json_success( [] );
    }

    $summary_url  = $base_url . '/summary?event=' . $event_id;
    $sum_response = wp_remote_get( $summary_url, [ 'timeout' => 10 ] );
    if ( is_wp_error( $sum_response ) || wp_remote_retrieve_response_code( $sum_response ) !== 200 ) {
        wp_send_json_success( [] );
    }

    $sum_data  = json_decode( wp_remote_retrieve_body( $sum_response ), true );
    $player_stats = [];

    foreach ( $sum_data['boxscore']['players'] ?? [] as $team_block ) {
        foreach ( $team_block['statistics'] ?? [] as $stat_group ) {
            $labels = $stat_group['labels'] ?? [];
            foreach ( $stat_group['athletes'] ?? [] as $athlete ) {
                if ( $athlete['didNotPlay'] ?? false ) {
                    continue;
                }
                $name      = $athlete['athlete']['displayName'] ?? '';
                $stats_arr = $athlete['stats'] ?? [];
                if ( ! $name || empty( $stats_arr ) ) {
                    continue;
                }
                $stats_map = [];
                foreach ( $labels as $i => $label ) {
                    $stats_map[ $label ] = $stats_arr[ $i ] ?? '—';
                }
                // Merge across stat groups (e.g. NFL has separate passing/rushing/receiving)
                $player_stats[ $name ] = array_merge( $player_stats[ $name ] ?? [], $stats_map );
            }
        }
    }

    // Cache for 45 seconds — live stats update frequently.
    set_transient( $cache_key, $player_stats, 45 );
    wp_send_json_success( $player_stats );
}
add_action( 'wp_ajax_statsight_get_live_boxscore',        'statsight_ajax_get_live_boxscore' );
add_action( 'wp_ajax_nopriv_statsight_get_live_boxscore', 'statsight_ajax_get_live_boxscore' );

/**
 * AJAX handler — return a player→team map for a game's two rosters.
 *
 * Expects GET params: sport, home (full team name), away (full team name).
 * Returns { home: { name, logo, players: [] }, away: { name, logo, players: [] } }
 * Cached 24 hours per team pair.
 */
function statsight_ajax_get_rosters(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );

    $sport = isset( $_GET['sport'] ) ? sanitize_key( $_GET['sport'] )        : '';
    $home  = isset( $_GET['home'] )  ? sanitize_text_field( $_GET['home'] )  : '';
    $away  = isset( $_GET['away'] )  ? sanitize_text_field( $_GET['away'] )  : '';

    if ( empty( $sport ) || empty( $home ) || empty( $away ) ) {
        wp_send_json_error( [ 'message' => 'Missing parameters.' ], 400 );
    }

    $path = statsight_espn_sport_path( $sport );
    if ( ! $path ) {
        wp_send_json_success( [] ); // Sport has no ESPN roster data
    }

    $cache_key = 'statsight_rosters_v2_' . md5( $sport . $home . $away );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        wp_send_json_success( $cached );
    }

    // Load all teams (already cached 24h by statsight_espn_team_logos).
    $teams_url  = 'https://site.api.espn.com/apis/site/v2/sports/' . $path['sport'] . '/' . $path['league'] . '/teams?limit=500';
    $teams_resp = wp_remote_get( $teams_url, [ 'timeout' => 10 ] );

    if ( is_wp_error( $teams_resp ) || wp_remote_retrieve_response_code( $teams_resp ) !== 200 ) {
        wp_send_json_error( [ 'message' => 'Could not fetch teams.' ], 502 );
    }

    $teams_data = json_decode( wp_remote_retrieve_body( $teams_resp ), true );
    $all_teams  = $teams_data['sports'][0]['leagues'][0]['teams'] ?? [];

    $normalise = 'statsight_normalise_team_name';

    // Odds API names that don't match ESPN display names after normalisation.
    $team_aliases = [
        'los angeles fc'     => 'lafc',
        'la fc'              => 'lafc',
        'inter miami cf'     => 'inter miami',
        'new york red bulls' => 'red bull ny',
        'cf montreal'        => 'cf montreal',
    ];

    $find_team = function ( string $full_name ) use ( $all_teams, $normalise, $team_aliases ): ?array {
        $norm = $team_aliases[ strtolower( $full_name ) ] ?? $normalise( $full_name );
        foreach ( $all_teams as $entry ) {
            $team     = $entry['team'];
            $norm_dn  = $team_aliases[ strtolower( $team['displayName'] ?? '' ) ] ?? $normalise( $team['displayName'] ?? '' );
            if ( $norm === $norm_dn || str_contains( $norm, $norm_dn ) || str_contains( $norm_dn, $norm ) ) {
                return $team;
            }
        }
        return null;
    };

    $home_team = $find_team( $home );
    $away_team = $find_team( $away );

    if ( ! $home_team || ! $away_team ) {
        wp_send_json_error( [ 'message' => 'Could not match teams.' ], 404 );
    }

    $fetch_roster = function ( array $team ) use ( $path ): array {
        $url      = 'https://site.api.espn.com/apis/site/v2/sports/' . $path['sport'] . '/' . $path['league'] . '/teams/' . $team['id'] . '/roster?enable=roster,stats,injuries';
        $response = wp_remote_get( $url, [ 'timeout' => 10 ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return [];
        }

        $data     = json_decode( wp_remote_retrieve_body( $response ), true );
        $athletes = $data['athletes'] ?? [];

        // Some sports wrap athletes in position groups.
        if ( ! empty( $athletes ) && isset( $athletes[0]['items'] ) ) {
            $athletes = array_merge( ...array_column( $athletes, 'items' ) );
        }

        $players    = [];
        $injuries   = []; // player name => injury status string
        $headshots  = []; // player name => headshot URL
        $positions  = []; // player name => position abbreviation
        $minutes    = []; // player name => avg minutes per game (float)
        $id_to_name = []; // athlete id => player name, for curl_multi lookup

        foreach ( $athletes as $a ) {
            $name = $a['fullName'] ?? ( $a['displayName'] ?? null );
            if ( ! $name ) {
                continue;
            }
            $players[] = $name;

            // Position
            $pos = $a['position']['abbreviation'] ?? ( $a['position']['displayName'] ?? null );
            if ( $pos ) {
                $positions[ $name ] = $pos;
            }

            // Headshot
            $headshot_url = $a['headshot']['href'] ?? ( $a['headshot'] ?? null );
            if ( is_string( $headshot_url ) && $headshot_url ) {
                $headshots[ $name ] = $headshot_url;
            }

            if ( ! empty( $a['id'] ) ) {
                $id_to_name[ (int) $a['id'] ] = $name;
            }

            // Use the most recent injury entry, but only if it's dated within the last 2 days.
            // ESPN doesn't always update the `date` field when status changes, so stale entries
            // (e.g. yesterday's OUT) can appear newer than today's Questionable update.
            // Capping to 2 days prevents carrying forward outdated designations.
            $injury_list = $a['injuries'] ?? [];
            if ( ! empty( $injury_list ) ) {
                usort( $injury_list, fn( $x, $y ) => strcmp( $y['date'] ?? '', $x['date'] ?? '' ) );
                $cutoff = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
                foreach ( $injury_list as $inj ) {
                    $inj_date = substr( $inj['date'] ?? '', 0, 10 ); // YYYY-MM-DD
                    if ( $inj_date && $inj_date < $cutoff ) {
                        break; // all remaining entries are older — stop
                    }
                    $status = $inj['status'] ?? '';
                    if ( $status ) {
                        $injuries[ $name ] = $status;
                        break;
                    }
                }
            }
        }

        // Fetch avg minutes for all athletes in parallel via curl_multi.
        // Uses the ESPN core stats API which confirmed to return avgMinutes for NBA.
        if ( ! empty( $id_to_name ) ) {
            $mh    = curl_multi_init();
            $curls = [];
            foreach ( $id_to_name as $athlete_id => $aname ) {
                $cache_key = 'statsight_avg_min_' . $athlete_id;
                $cached    = get_transient( $cache_key );
                if ( false !== $cached ) {
                    if ( $cached > 0 ) {
                        $minutes[ $aname ] = (float) $cached;
                    }
                    continue;
                }
                $stats_url = 'https://sports.core.api.espn.com/v2/sports/' . $path['sport'] . '/leagues/' . $path['league'] . '/athletes/' . $athlete_id . '/statistics';
                $ch        = curl_init( $stats_url );
                curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
                curl_setopt( $ch, CURLOPT_TIMEOUT, 8 );
                $curls[ $athlete_id ] = $ch;
                curl_multi_add_handle( $mh, $ch );
            }

            if ( ! empty( $curls ) ) {
                $running = null;
                do {
                    curl_multi_exec( $mh, $running );
                    curl_multi_select( $mh );
                } while ( $running > 0 );

                foreach ( $curls as $athlete_id => $ch ) {
                    $body = curl_multi_getcontent( $ch );
                    curl_multi_remove_handle( $mh, $ch );
                    curl_close( $ch );
                    if ( ! $body ) {
                        continue;
                    }
                    $stats_data = json_decode( $body, true );
                    $avg_min    = null;
                    foreach ( $stats_data['splits']['categories'] ?? [] as $cat ) {
                        foreach ( $cat['stats'] ?? [] as $stat ) {
                            if ( $stat['name'] === 'avgMinutes' ) {
                                $avg_min = round( (float) $stat['value'], 1 );
                                break 2;
                            }
                        }
                    }
                    set_transient( 'statsight_avg_min_' . $athlete_id, $avg_min ?? 0, 6 * HOUR_IN_SECONDS );
                    if ( $avg_min !== null && isset( $id_to_name[ $athlete_id ] ) ) {
                        $minutes[ $id_to_name[ $athlete_id ] ] = $avg_min;
                    }
                }
            }
            curl_multi_close( $mh );
        }

        return [ 'players' => $players, 'injuries' => $injuries, 'headshots' => $headshots, 'positions' => $positions, 'minutes' => $minutes ];
    };

    $home_roster = $fetch_roster( $home_team );
    $away_roster = $fetch_roster( $away_team );

    $payload = [
        'home' => [
            'name'      => $home_team['displayName'],
            'logo'      => $home_team['logos'][0]['href'] ?? '',
            'players'   => $home_roster['players'],
            'injuries'  => $home_roster['injuries'],
            'headshots' => $home_roster['headshots'],
            'minutes'   => $home_roster['minutes'],
        ],
        'away' => [
            'name'      => $away_team['displayName'],
            'logo'      => $away_team['logos'][0]['href'] ?? '',
            'players'   => $away_roster['players'],
            'injuries'  => $away_roster['injuries'],
            'headshots' => $away_roster['headshots'],
            'minutes'   => $away_roster['minutes'],
        ],
    ];

    set_transient( $cache_key, $payload, 30 * MINUTE_IN_SECONDS );
    wp_send_json_success( $payload );
}
add_action( 'wp_ajax_statsight_get_rosters',        'statsight_ajax_get_rosters' );
add_action( 'wp_ajax_nopriv_statsight_get_rosters', 'statsight_ajax_get_rosters' );

/**
 * Fetch average minutes played for a list of athletes in parallel via curl_multi.
 * Accepts POST: sport (e.g. "basketball/nba"), ids (JSON array of ESPN athlete IDs and names).
 * Returns: { "Player Name": 28.4, ... }
 */
function statsight_ajax_athlete_minutes(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    $sport_key = sanitize_text_field( $_POST['sport'] ?? '' );
    $ids_raw   = wp_unslash( $_POST['ids'] ?? '' );
    $ids       = json_decode( $ids_raw, true );

    if ( ! $sport_key || ! is_array( $ids ) || empty( $ids ) ) {
        wp_send_json_error( [ 'message' => 'Invalid parameters.' ], 400 );
    }

    $path = statsight_espn_sport_path( $sport_key );
    if ( ! $path ) {
        wp_send_json_success( [] ); // sport not supported
    }
    $sport = $path['sport'] . '/leagues/' . $path['league'];

    // $ids is an array of { id, name } objects.
    $result = [];
    $mh     = curl_multi_init();
    $curls  = [];

    foreach ( $ids as $entry ) {
        $athlete_id = (int) ( $entry['id'] ?? 0 );
        $name       = (string) ( $entry['name'] ?? '' );
        if ( ! $athlete_id || ! $name ) {
            continue;
        }

        $cache_key = 'statsight_avg_min_' . $athlete_id;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            $result[ $name ] = (float) $cached;
            continue;
        }

        $url = 'https://sports.core.api.espn.com/v2/sports/' . $sport . '/athletes/' . $athlete_id . '/statistics';
        $ch  = curl_init( $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 8 );
        $curls[ $athlete_id ] = [ 'ch' => $ch, 'name' => $name ];
        curl_multi_add_handle( $mh, $ch );
    }

    if ( ! empty( $curls ) ) {
        $running = null;
        do {
            curl_multi_exec( $mh, $running );
            curl_multi_select( $mh );
        } while ( $running > 0 );

        foreach ( $curls as $athlete_id => $info ) {
            $body = curl_multi_getcontent( $info['ch'] );
            curl_multi_remove_handle( $mh, $info['ch'] );
            curl_close( $info['ch'] );

            if ( ! $body ) {
                continue;
            }
            $data = json_decode( $body, true );
            $avg_min = null;
            foreach ( $data['splits']['categories'] ?? [] as $cat ) {
                foreach ( $cat['stats'] ?? [] as $stat ) {
                    if ( $stat['name'] === 'avgMinutes' ) {
                        $avg_min = round( (float) $stat['value'], 1 );
                        break 2;
                    }
                }
            }
            if ( $avg_min !== null ) {
                $result[ $info['name'] ] = $avg_min;
                set_transient( 'statsight_avg_min_' . $athlete_id, $avg_min, 6 * HOUR_IN_SECONDS );
            }
        }
    }

    curl_multi_close( $mh );
    wp_send_json_success( $result );
}
add_action( 'wp_ajax_statsight_athlete_minutes',        'statsight_ajax_athlete_minutes' );
add_action( 'wp_ajax_nopriv_statsight_athlete_minutes', 'statsight_ajax_athlete_minutes' );

/**
 * Batch roster player-names lookup.
 * Returns only player name arrays (no headshots/injuries) for all games in one request.
 * Accepts GET: sport, games (JSON array of {event_id, home, away}).
 * Reuses the same 24h transient cache as statsight_ajax_get_rosters.
 */
function statsight_ajax_get_roster_names(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );

    $sport = isset( $_GET['sport'] ) ? sanitize_key( $_GET['sport'] ) : '';
    $games = isset( $_GET['games'] ) ? json_decode( stripslashes( $_GET['games'] ), true ) : [];

    if ( empty( $sport ) || ! is_array( $games ) ) {
        wp_send_json_error( [ 'message' => 'Missing parameters.' ], 400 );
    }

    $path = statsight_espn_sport_path( $sport );
    if ( ! $path ) {
        wp_send_json_success( [] ); // sport not supported — return empty, not an error
    }

    // Load team list once (cached 24h).
    $teams_url  = 'https://site.api.espn.com/apis/site/v2/sports/' . $path['sport'] . '/' . $path['league'] . '/teams?limit=500';
    $teams_resp = wp_remote_get( $teams_url, [ 'timeout' => 10 ] );

    if ( is_wp_error( $teams_resp ) || wp_remote_retrieve_response_code( $teams_resp ) !== 200 ) {
        wp_send_json_success( [] );
    }

    $teams_data = json_decode( wp_remote_retrieve_body( $teams_resp ), true );
    $all_teams  = $teams_data['sports'][0]['leagues'][0]['teams'] ?? [];

    $normalise = 'statsight_normalise_team_name';

    $find_team = function ( string $full_name ) use ( $all_teams, $normalise ): ?array {
        $norm = $normalise( $full_name );
        foreach ( $all_teams as $entry ) {
            $team    = $entry['team'];
            $norm_dn = $normalise( $team['displayName'] ?? '' );
            if ( $norm === $norm_dn || str_contains( $norm, $norm_dn ) || str_contains( $norm_dn, $norm ) ) {
                return $team;
            }
        }
        return null;
    };

    $fetch_player_names = function ( array $team ) use ( $path ): array {
        $url      = 'https://site.api.espn.com/apis/site/v2/sports/' . $path['sport'] . '/' . $path['league'] . '/teams/' . $team['id'] . '/roster';
        $response = wp_remote_get( $url, [ 'timeout' => 10 ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return [];
        }

        $data     = json_decode( wp_remote_retrieve_body( $response ), true );
        $athletes = $data['athletes'] ?? [];

        if ( ! empty( $athletes ) && isset( $athletes[0]['items'] ) ) {
            $athletes = array_merge( ...array_column( $athletes, 'items' ) );
        }

        $players = [];
        foreach ( $athletes as $a ) {
            $name = $a['fullName'] ?? ( $a['displayName'] ?? null );
            if ( $name ) {
                $players[] = $name;
            }
        }
        return $players;
    };

    $result = [];

    foreach ( $games as $game ) {
        $event_id = sanitize_text_field( $game['event_id'] ?? '' );
        $home     = sanitize_text_field( $game['home']     ?? '' );
        $away     = sanitize_text_field( $game['away']     ?? '' );

        if ( ! $event_id || ! $home || ! $away ) {
            continue;
        }

        // Reuse the full roster transient if already cached.
        $cache_key = 'statsight_rosters_v2_' . md5( $sport . $home . $away );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            $result[ $event_id ] = array_merge(
                $cached['home']['players'] ?? [],
                $cached['away']['players'] ?? []
            );
            continue;
        }

        // Not cached yet — fetch just the player names and store a lightweight transient.
        $names_key    = 'statsight_roster_names_' . md5( $sport . $home . $away );
        $names_cached = get_transient( $names_key );

        if ( false !== $names_cached ) {
            $result[ $event_id ] = $names_cached;
            continue;
        }

        $home_team = $find_team( $home );
        $away_team = $find_team( $away );

        $players = [];
        if ( $home_team ) {
            $players = array_merge( $players, $fetch_player_names( $home_team ) );
        }
        if ( $away_team ) {
            $players = array_merge( $players, $fetch_player_names( $away_team ) );
        }

        set_transient( $names_key, $players, DAY_IN_SECONDS );
        $result[ $event_id ] = $players;
    }

    wp_send_json_success( $result );
}
add_action( 'wp_ajax_statsight_get_roster_names',        'statsight_ajax_get_roster_names' );
add_action( 'wp_ajax_nopriv_statsight_get_roster_names', 'statsight_ajax_get_roster_names' );

/**
 * AJAX handler — return rest days and back-to-back status for both teams in a game.
 *
 * Expects GET params: sport, home, away, game_time (ISO string of the game).
 * Returns {
 *   home: { name, days_rest: int|null, is_b2b: bool },
 *   away: { name, days_rest: int|null, is_b2b: bool }
 * }
 * Cached 1 hour per matchup.
 */
/**
 * Internal helper — compute rest days for a single game.
 * Returns the payload array, or null if the sport/path is not applicable.
 * Results are cached in a transient for HOUR_IN_SECONDS.
 *
 * @param string $sport
 * @param string $home
 * @param string $away
 * @param string $game_time  ISO 8601 datetime string
 * @return array|null
 */
function statsight_get_rest_days_for_game( string $sport, string $home, string $away, string $game_time ): ?array {
    if ( str_starts_with( $sport, 'soccer' ) ) {
        return [];
    }

    $path = statsight_espn_sport_path( $sport );
    if ( ! $path ) {
        return [];
    }

    $cache_key = 'statsight_rest_' . md5( $sport . $home . $away . substr( $game_time, 0, 10 ) );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached;
    }

    $teams_url  = 'https://site.api.espn.com/apis/site/v2/sports/' . $path['sport'] . '/' . $path['league'] . '/teams?limit=500';
    $teams_resp = wp_remote_get( $teams_url, [ 'timeout' => 10 ] );

    if ( is_wp_error( $teams_resp ) || wp_remote_retrieve_response_code( $teams_resp ) !== 200 ) {
        return null;
    }

    $teams_data = json_decode( wp_remote_retrieve_body( $teams_resp ), true );
    $all_teams  = $teams_data['sports'][0]['leagues'][0]['teams'] ?? [];

    $normalise = 'statsight_normalise_team_name';

    $team_aliases = [
        'los angeles fc'     => 'lafc',
        'la fc'              => 'lafc',
        'inter miami cf'     => 'inter miami',
        'new york red bulls' => 'red bull ny',
        'cf montreal'        => 'cf montreal',
    ];

    $find_team_id = function ( string $full_name ) use ( $all_teams, $normalise, $team_aliases ): ?string {
        $norm = $team_aliases[ strtolower( $full_name ) ] ?? $normalise( $full_name );
        foreach ( $all_teams as $entry ) {
            $team    = $entry['team'];
            $norm_dn = $team_aliases[ strtolower( $team['displayName'] ?? '' ) ] ?? $normalise( $team['displayName'] ?? '' );
            if ( $norm === $norm_dn || str_contains( $norm, $norm_dn ) || str_contains( $norm_dn, $norm ) ) {
                return (string) $team['id'];
            }
        }
        return null;
    };

    // Use ET for date comparisons — NBA/NHL/MLB schedules are perceived in local US time.
    // A game at 2026-04-11T00:00Z is April 10 evening ET, not April 11.
    $et = new DateTimeZone( 'America/New_York' );

    $game_dt      = new DateTime( $game_time );
    $game_date_et = ( clone $game_dt )->setTimezone( $et )->format( 'Y-m-d' );

    $get_rest = function ( string $team_name ) use ( $path, $find_team_id, $game_dt, $game_date_et, $et ): array {
        $team_id = $find_team_id( $team_name );
        if ( ! $team_id ) {
            return [ 'name' => $team_name, 'days_rest' => null, 'is_b2b' => false ];
        }

        $url      = 'https://site.api.espn.com/apis/site/v2/sports/' . $path['sport'] . '/' . $path['league'] . '/teams/' . $team_id . '/schedule';
        $response = wp_remote_get( $url, [ 'timeout' => 10 ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return [ 'name' => $team_name, 'days_rest' => null, 'is_b2b' => false ];
        }

        $events            = json_decode( wp_remote_retrieve_body( $response ), true )['events'] ?? [];
        $last_game_date_et = null;

        // Find the most recent completed game before this one (compare in ET).
        foreach ( array_reverse( $events ) as $event ) {
            $state = $event['competitions'][0]['status']['type']['state'] ?? '';
            if ( $state !== 'post' ) {
                continue;
            }
            $event_dt      = new DateTime( $event['date'] ?? '' );
            $event_date_et = ( clone $event_dt )->setTimezone( $et )->format( 'Y-m-d' );
            if ( $event_dt >= $game_dt ) {
                continue;
            }
            $last_game_date_et = $event_date_et;
            break;
        }

        if ( ! $last_game_date_et ) {
            return [ 'name' => $team_name, 'days_rest' => null, 'is_b2b' => false ];
        }

        $last_dt   = new DateTime( $last_game_date_et, $et );
        $this_dt   = new DateTime( $game_date_et, $et );
        $days_rest = (int) $last_dt->diff( $this_dt )->days;
        $is_b2b    = $days_rest <= 1;

        return [ 'name' => $team_name, 'days_rest' => $days_rest, 'is_b2b' => $is_b2b ];
    };

    $payload = [
        'home' => $get_rest( $home ),
        'away' => $get_rest( $away ),
    ];

    set_transient( $cache_key, $payload, HOUR_IN_SECONDS );
    return $payload;
}

function statsight_ajax_get_rest_days(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );

    $sport     = isset( $_GET['sport'] )     ? sanitize_key( $_GET['sport'] )              : '';
    $home      = isset( $_GET['home'] )      ? sanitize_text_field( $_GET['home'] )        : '';
    $away      = isset( $_GET['away'] )      ? sanitize_text_field( $_GET['away'] )        : '';
    $game_time = isset( $_GET['game_time'] ) ? sanitize_text_field( $_GET['game_time'] )   : '';

    if ( empty( $sport ) || empty( $home ) || empty( $away ) || empty( $game_time ) ) {
        wp_send_json_error( [ 'message' => 'Missing parameters.' ], 400 );
    }

    $result = statsight_get_rest_days_for_game( $sport, $home, $away, $game_time );
    if ( $result === null ) {
        wp_send_json_error( [ 'message' => 'Could not fetch teams.' ], 502 );
    }
    wp_send_json_success( $result );
}
add_action( 'wp_ajax_statsight_get_rest_days',        'statsight_ajax_get_rest_days' );
add_action( 'wp_ajax_nopriv_statsight_get_rest_days', 'statsight_ajax_get_rest_days' );

/**
 * Batch endpoint — accepts a JSON-encoded array of game objects and returns
 * a map keyed by event_id. Each game object: { event_id, sport, home, away, game_time }.
 * Shares the transient cache with the single-game handler.
 */
function statsight_ajax_get_rest_days_batch(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );

    $raw   = isset( $_POST['games'] ) ? wp_unslash( $_POST['games'] ) : '[]';
    $games = json_decode( $raw, true );

    if ( ! is_array( $games ) || empty( $games ) ) {
        wp_send_json_error( [ 'message' => 'Missing games.' ], 400 );
    }

    $results = [];

    foreach ( $games as $game ) {
        $event_id  = sanitize_key( $game['event_id']  ?? '' );
        $sport     = sanitize_key( $game['sport']      ?? '' );
        $home      = sanitize_text_field( $game['home']      ?? '' );
        $away      = sanitize_text_field( $game['away']      ?? '' );
        $game_time = sanitize_text_field( $game['game_time'] ?? '' );

        if ( ! $event_id || ! $sport || ! $home || ! $away || ! $game_time ) {
            continue;
        }

        $result = statsight_get_rest_days_for_game( $sport, $home, $away, $game_time );
        $results[ $event_id ] = $result ?? [];
    }

    wp_send_json_success( $results );
}
add_action( 'wp_ajax_statsight_get_rest_days_batch',        'statsight_ajax_get_rest_days_batch' );
add_action( 'wp_ajax_nopriv_statsight_get_rest_days_batch', 'statsight_ajax_get_rest_days_batch' );

/**
 * AJAX handler — return defensive rankings for all teams in a sport.
 *
 * Returns a map of team displayName → {
 *   pts_allowed:    float,   // avg points allowed per game
 *   pts_rank:       int,     // rank among all teams (1 = best defense)
 *   team_count:     int,     // total teams in league
 * }
 * Cached 24 hours per sport.
 */
function statsight_ajax_get_defense_rankings(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );

    $sport = isset( $_GET['sport'] ) ? sanitize_key( $_GET['sport'] ) : '';
    if ( empty( $sport ) ) {
        wp_send_json_error( [ 'message' => 'Missing sport parameter.' ], 400 );
    }

    $cache_key = 'statsight_defense_' . $sport;
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        wp_send_json_success( $cached );
    }

    // Map sport key to ESPN standings URL.
    $standings_map = [
        'basketball_nba'         => 'https://site.api.espn.com/apis/v2/sports/basketball/nba/standings?type=0&level=1&sort=winpercent&limit=35',
        'basketball_ncaab'       => 'https://site.api.espn.com/apis/v2/sports/basketball/mens-college-basketball/standings?type=0&level=1&sort=winpercent&limit=400',
        'americanfootball_nfl'   => 'https://site.api.espn.com/apis/v2/sports/football/nfl/standings?type=0&level=1&sort=winpercent&limit=35',
        'americanfootball_ncaaf' => 'https://site.api.espn.com/apis/v2/sports/football/college-football/standings?type=0&level=1&sort=winpercent&limit=400',
        'baseball_mlb'           => 'https://site.api.espn.com/apis/v2/sports/baseball/mlb/standings?type=0&level=1&sort=winpercent&limit=35',
        'icehockey_nhl'          => 'https://site.api.espn.com/apis/v2/sports/hockey/nhl/standings?type=0&level=1&sort=winpercent&limit=35',
    ];

    $url = $standings_map[ $sport ] ?? '';
    if ( empty( $url ) ) {
        wp_send_json_error( [ 'message' => 'Sport not supported.' ], 400 );
    }

    $response = wp_remote_get( $url, [ 'timeout' => 15 ] );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        wp_send_json_error( [ 'message' => 'Could not fetch standings.' ], 502 );
    }

    $data    = json_decode( wp_remote_retrieve_body( $response ), true );
    $entries = $data['standings']['entries'] ?? [];

    if ( empty( $entries ) ) {
        wp_send_json_error( [ 'message' => 'No standings data.' ], 502 );
    }

    // Extract pts allowed per game for each team.
    $teams = [];
    foreach ( $entries as $entry ) {
        $team_name = $entry['team']['displayName'] ?? '';
        if ( empty( $team_name ) ) {
            continue;
        }
        $stats = [];
        foreach ( $entry['stats'] ?? [] as $s ) {
            $stats[ $s['name'] ] = $s['value'] ?? null;
        }

        // NFL uses pointsAgainst (total) — convert to per-game.
        $games_played  = ( $stats['wins'] ?? 0 ) + ( $stats['losses'] ?? 0 );
        $pts_allowed   = $stats['avgPointsAgainst']
            ?? ( isset( $stats['pointsAgainst'], $stats['losses'], $stats['wins'] )
                 ? ( $stats['pointsAgainst'] / max( 1, $stats['wins'] + $stats['losses'] ) )
                 : null );

        if ( $pts_allowed === null ) {
            continue;
        }

        $teams[ $team_name ] = (float) $pts_allowed;
    }

    // Rank teams: rank 1 = fewest points allowed (best defense).
    asort( $teams ); // ascending: lowest pts allowed first
    $team_count = count( $teams );
    $rankings   = [];
    $rank        = 1;
    foreach ( $teams as $name => $pts ) {
        $rankings[ $name ] = [
            'pts_allowed' => round( $pts, 1 ),
            'pts_rank'    => $rank,
            'team_count'  => $team_count,
        ];
        $rank++;
    }

    set_transient( $cache_key, $rankings, DAY_IN_SECONDS );
    wp_send_json_success( $rankings );
}
add_action( 'wp_ajax_statsight_get_defense_rankings',        'statsight_ajax_get_defense_rankings' );
add_action( 'wp_ajax_nopriv_statsight_get_defense_rankings', 'statsight_ajax_get_defense_rankings' );

/**
 * Maps a The Odds API sport key to the ESPN sport/league path used in
 * summary and athlete endpoints.
 *
 * @return array{ sport: string, league: string }|null
 */
function statsight_espn_sport_path( string $sport_key ): ?array {
    $map = [
        'basketball_nba'         => [ 'sport' => 'basketball',    'league' => 'nba' ],
        'basketball_wnba'        => [ 'sport' => 'basketball',    'league' => 'wnba' ],
        'americanfootball_nfl'   => [ 'sport' => 'football',      'league' => 'nfl' ],
        'americanfootball_ncaaf' => [ 'sport' => 'football',      'league' => 'college-football' ],
        'baseball_mlb'           => [ 'sport' => 'baseball',      'league' => 'mlb' ],
        'icehockey_nhl'          => [ 'sport' => 'hockey',        'league' => 'nhl' ],
        'basketball_ncaab'       => [ 'sport' => 'basketball',    'league' => 'mens-college-basketball' ],
        'soccer_epl'             => [ 'sport' => 'soccer',        'league' => 'eng.1' ],
        'soccer_usa_mls'         => [ 'sport' => 'soccer',        'league' => 'usa.1' ],
    ];

    return $map[ $sport_key ] ?? null;
}

/**
 * Returns the ESPN gamelog stat columns relevant to a specific prop market key.
 * Keys are ESPN label strings (used to index into the stats array).
 * Values are the display headers shown in the modal table.
 *
 * Always includes a contextual "anchor" stat for the prop first, followed by
 * supporting stats that give useful game context.
 *
 * @return array<string, string>
 */
function statsight_espn_stat_columns( string $sport_key, string $market_key = '' ): array {
    // Strip _alternate suffix so it maps to the same columns as the base market.
    $market_key = preg_replace( '/_alternate$/', '', $market_key );

    // ── NBA / NCAAB ──────────────────────────────────────────────────────────
    if ( str_starts_with( $sport_key, 'basketball' ) ) {
        // Keys are ESPN gamelog label strings; values are the display headers shown in the UI.
        // ESPN NBA gamelog labels: MIN, FGM, FGA, FG%, 3PM, 3PA, 3P%, FTM, FTA, FT%,
        //                          OREB, DREB, REB, AST, STL, BLK, TO, PF, PTS, +/-
        $map = [
            'player_points'                  => [ 'MIN' => 'MIN', 'PTS' => 'PTS', 'FG' => 'FG', '3PT' => '3PT', 'FT' => 'FT' ],
            'player_rebounds'                => [ 'MIN' => 'MIN', 'REB' => 'REB', 'OREB' => 'OREB', 'DREB' => 'DREB' ],
            'player_assists'                 => [ 'MIN' => 'MIN', 'AST' => 'AST', 'TO' => 'TO' ],
            'player_threes'                  => [ 'MIN' => 'MIN', '3PT' => '3PT', 'PTS' => 'PTS' ],
            'player_blocks'                  => [ 'MIN' => 'MIN', 'BLK' => 'BLK', 'DREB' => 'DREB' ],
            'player_steals'                  => [ 'MIN' => 'MIN', 'STL' => 'STL', 'AST' => 'AST' ],
            'player_blocks_steals'           => [ 'MIN' => 'MIN', 'BLK' => 'BLK', 'STL' => 'STL' ],
            'player_turnovers'               => [ 'MIN' => 'MIN', 'TO' => 'TO',   'AST' => 'AST' ],
            'player_field_goals'             => [ 'MIN' => 'MIN', 'FG' => 'FG',   'PTS' => 'PTS' ],
            'player_frees_made'              => [ 'MIN' => 'MIN', 'FT' => 'FT',   'PTS' => 'PTS' ],
            'player_points_rebounds_assists' => [ 'MIN' => 'MIN', 'PTS' => 'PTS', 'REB' => 'REB', 'AST' => 'AST' ],
            'player_points_rebounds'         => [ 'MIN' => 'MIN', 'PTS' => 'PTS', 'REB' => 'REB' ],
            'player_points_assists'          => [ 'MIN' => 'MIN', 'PTS' => 'PTS', 'AST' => 'AST' ],
            'player_rebounds_assists'        => [ 'MIN' => 'MIN', 'REB' => 'REB', 'AST' => 'AST' ],
            'player_double_double'           => [ 'MIN' => 'MIN', 'PTS' => 'PTS', 'REB' => 'REB', 'AST' => 'AST' ],
            'player_triple_double'           => [ 'MIN' => 'MIN', 'PTS' => 'PTS', 'REB' => 'REB', 'AST' => 'AST' ],
            'player_first_basket'            => [ 'MIN' => 'MIN', 'PTS' => 'PTS', 'FG' => 'FG' ],
        ];
        return $map[ $market_key ]
            ?? [ 'MIN' => 'MIN', 'PTS' => 'PTS', 'REB' => 'REB', 'AST' => 'AST', 'STL' => 'STL', 'BLK' => 'BLK', 'TO' => 'TO' ];
    }

    // ── NFL / NCAAF ──────────────────────────────────────────────────────────
    if ( str_starts_with( $sport_key, 'americanfootball' ) ) {
        $map = [
            'player_pass_yds'                => [ 'CMP' => 'CMP', 'YDS' => 'PASS YDS', 'TD' => 'TD', 'INT' => 'INT' ],
            'player_pass_tds'                => [ 'CMP' => 'CMP', 'YDS' => 'PASS YDS', 'TD' => 'TD', 'INT' => 'INT' ],
            'player_pass_attempts'           => [ 'CMP' => 'CMP', 'YDS' => 'PASS YDS', 'TD' => 'TD' ],
            'player_pass_completions'        => [ 'CMP' => 'CMP', 'YDS' => 'PASS YDS', 'TD' => 'TD' ],
            'player_pass_interceptions'      => [ 'INT' => 'INT', 'CMP' => 'CMP', 'YDS' => 'PASS YDS' ],
            'player_pass_longest_completion' => [ 'LNG' => 'LNG', 'CMP' => 'CMP', 'YDS' => 'PASS YDS' ],
            'player_rush_yds'                => [ 'CAR' => 'CAR', 'YDS' => 'RUSH YDS', 'TD' => 'TD' ],
            'player_rush_attempts'           => [ 'CAR' => 'CAR', 'YDS' => 'RUSH YDS', 'TD' => 'TD' ],
            'player_rush_tds'                => [ 'CAR' => 'CAR', 'YDS' => 'RUSH YDS', 'TD' => 'TD' ],
            'player_rush_longest'            => [ 'LNG' => 'LNG', 'CAR' => 'CAR', 'YDS' => 'RUSH YDS' ],
            'player_reception_yds'           => [ 'REC' => 'REC', 'YDS' => 'REC YDS', 'TD' => 'TD' ],
            'player_receptions'              => [ 'REC' => 'REC', 'YDS' => 'REC YDS', 'TD' => 'TD' ],
            'player_reception_tds'           => [ 'REC' => 'REC', 'YDS' => 'REC YDS', 'TD' => 'TD' ],
            'player_reception_longest'       => [ 'LNG' => 'LNG', 'REC' => 'REC', 'YDS' => 'REC YDS' ],
            'player_sacks'                   => [ 'SACK' => 'SACKS', 'TOT' => 'TACKLES' ],
            'player_solo_tackles'            => [ 'SOLO' => 'SOLO', 'TOT' => 'TACKLES' ],
            'player_tackles_assists'         => [ 'TOT' => 'TACKLES', 'SOLO' => 'SOLO', 'SACK' => 'SACKS' ],
            'player_anytime_td'              => [ 'CAR' => 'CAR', 'YDS' => 'RUSH YDS', 'REC' => 'REC', 'TD' => 'TD' ],
            'player_1st_td'                  => [ 'CAR' => 'CAR', 'YDS' => 'RUSH YDS', 'REC' => 'REC', 'TD' => 'TD' ],
            'player_kicking_points'          => [ 'FGM' => 'FGM', 'FGA' => 'FGA', 'XPM' => 'XPM' ],
            'player_field_goals'             => [ 'FGM' => 'FGM', 'FGA' => 'FGA' ],
        ];
        return $map[ $market_key ]
            ?? [ 'CMP' => 'CMP', 'YDS' => 'PASS YDS', 'TD' => 'TD', 'INT' => 'INT', 'CAR' => 'CAR' ];
    }

    // ── MLB ──────────────────────────────────────────────────────────────────
    if ( str_starts_with( $sport_key, 'baseball' ) ) {
        $map = [
            'batter_hits'            => [ 'AB' => 'AB', 'H' => 'H',   'AVG' => 'AVG', 'HR' => 'HR',  'RBI' => 'RBI' ],
            'batter_total_bases'     => [ 'AB' => 'AB', 'H' => 'H',   'HR' => 'HR',   'RBI' => 'RBI', '2B' => '2B',  '3B' => '3B' ],
            'batter_home_runs'       => [ 'AB' => 'AB', 'HR' => 'HR', 'H' => 'H',     'RBI' => 'RBI' ],
            'batter_rbis'            => [ 'AB' => 'AB', 'RBI' => 'RBI', 'H' => 'H',   'HR' => 'HR' ],
            'batter_runs_scored'     => [ 'AB' => 'AB', 'R' => 'R',   'H' => 'H',     'BB' => 'BB' ],
            'batter_hits_runs_rbis'  => [ 'AB' => 'AB', 'H' => 'H',   'R' => 'R',     'RBI' => 'RBI' ],
            'batter_walks'           => [ 'AB' => 'AB', 'BB' => 'BB', 'OBP' => 'OBP' ],
            'batter_strikeouts'      => [ 'AB' => 'AB', 'SO' => 'SO', 'AVG' => 'AVG' ],
            'batter_stolen_bases'    => [ 'AB' => 'AB', 'SB' => 'SB', 'H' => 'H' ],
            'pitcher_strikeouts'     => [ 'IP' => 'IP', 'SO' => 'SO', 'BB' => 'BB',   'ER' => 'ER' ],
            'pitcher_outs'           => [ 'IP' => 'IP', 'SO' => 'SO', 'BB' => 'BB',   'ER' => 'ER' ],
            'pitcher_hits_allowed'   => [ 'IP' => 'IP', 'H' => 'H',   'SO' => 'SO',   'ER' => 'ER' ],
            'pitcher_walks'          => [ 'IP' => 'IP', 'BB' => 'BB', 'SO' => 'SO',   'ER' => 'ER' ],
            'pitcher_earned_runs'    => [ 'IP' => 'IP', 'ER' => 'ER', 'SO' => 'SO',   'H' => 'H' ],
        ];
        return $map[ $market_key ]
            ?? [ 'AB' => 'AB', 'H' => 'H', 'HR' => 'HR', 'RBI' => 'RBI', 'BB' => 'BB', 'SO' => 'SO', 'AVG' => 'AVG' ];
    }

    // ── NHL ──────────────────────────────────────────────────────────────────
    if ( str_starts_with( $sport_key, 'icehockey' ) ) {
        $map = [
            'player_goals'               => [ 'G' => 'G',   'A' => 'A',   'PTS' => 'PTS', 'S' => 'SOG', 'TOI/G' => 'TOI' ],
            'player_assists'             => [ 'A' => 'A',   'G' => 'G',   'PTS' => 'PTS', 'TOI/G' => 'TOI' ],
            'player_points'              => [ 'PTS' => 'PTS', 'G' => 'G', 'A' => 'A',     'TOI/G' => 'TOI' ],
            'player_shots_on_goal'       => [ 'S' => 'SOG', 'G' => 'G',   'TOI/G' => 'TOI' ],
            'player_blocked_shots'       => [ 'BLK' => 'BLK', 'S' => 'SOG', 'TOI/G' => 'TOI' ],
            'player_power_play_points'   => [ 'PPG' => 'PPG', 'PPA' => 'PPA', 'PTS' => 'PTS', 'TOI/G' => 'TOI' ],
            'player_total_saves'         => [ 'SV' => 'SV',  'SA' => 'SA',  'SV%' => 'SV%', 'GA' => 'GA' ],
            'player_goal_scorer_anytime' => [ 'G' => 'G',   'A' => 'A',   'S' => 'SOG',   'TOI/G' => 'TOI' ],
            'player_goal_scorer_first'   => [ 'G' => 'G',   'A' => 'A',   'S' => 'SOG',   'TOI/G' => 'TOI' ],
        ];
        return $map[ $market_key ]
            ?? [ 'G' => 'G', 'A' => 'A', 'PTS' => 'PTS', '+/-' => '+/-', 'S' => 'SOG', 'TOI/G' => 'TOI' ];
    }

    return [];
}

/**
 * AJAX handler — fetch a player's recent game log and current-game stats
 * from the ESPN API.
 *
 * Expects GET params: sport, player_name, event_id (optional).
 * Returns:
 *   gamelog   — last 5 games: [ { date, opponent, result, stats: {label: value} } ]
 *   averages  — { label: avg_value } computed over the returned games
 *   live_game — current game stats if the event is in progress, else null
 *   columns   — ordered list of stat labels to display
 */
/**
 * Maps a prop market key to the ESPN stat column label(s) used to compute hit rate.
 * Mirrors the JS hitRateColumns() function in page-props.php.
 */
function statsight_hit_rate_columns( string $market_key ): ?array {
    $mk  = preg_replace( '/_alternate$/', '', $market_key );
    $map = [
        // ── NBA / NCAAB ──────────────────────────────────────────────────────
        'player_points'                  => [ 'PTS' ],
        'player_rebounds'                => [ 'REB' ],
        'player_assists'                 => [ 'AST' ],
        'player_threes'                  => [ '3PT' ],
        'player_blocks'                  => [ 'BLK' ],
        'player_steals'                  => [ 'STL' ],
        'player_blocks_steals'           => [ 'BLK', 'STL' ],
        'player_turnovers'               => [ 'TO' ],
        'player_field_goals'             => [ 'FG' ],
        'player_frees_made'              => [ 'FT' ],
        'player_points_rebounds_assists' => [ 'PTS', 'REB', 'AST' ],
        'player_points_rebounds'         => [ 'PTS', 'REB' ],
        'player_points_assists'          => [ 'PTS', 'AST' ],
        'player_rebounds_assists'        => [ 'REB', 'AST' ],

        // ── NFL / NCAAF ───────────────────────────────────────────────────────
        'player_pass_yds'                => [ 'YDS' ],
        'player_pass_tds'                => [ 'TD' ],
        'player_pass_attempts'           => [ 'CMP' ],  // ATT not in gamelog; CMP is closest proxy
        'player_pass_completions'        => [ 'CMP' ],
        'player_pass_interceptions'      => [ 'INT' ],
        'player_pass_longest_completion' => [ 'LNG' ],
        'player_rush_yds'                => [ 'YDS' ],
        'player_rush_attempts'           => [ 'CAR' ],
        'player_rush_tds'                => [ 'TD' ],
        'player_rush_longest'            => [ 'LNG' ],
        'player_reception_yds'           => [ 'YDS' ],
        'player_receptions'              => [ 'REC' ],
        'player_reception_tds'           => [ 'TD' ],
        'player_reception_longest'       => [ 'LNG' ],
        'player_sacks'                   => [ 'SACK' ],
        'player_solo_tackles'            => [ 'SOLO' ],
        'player_tackles_assists'         => [ 'TOT' ],
        'player_defensive_interceptions' => [ 'INT' ],
        'player_tds_over'                => [ 'TD' ],
        // Anytime/1st/last TD settled via scorer logic (scoringPlays), not gamelog.
        'player_anytime_td'              => [ 'TD' ],
        'player_1st_td'                  => [ 'TD' ],
        'player_last_td'                 => [ 'TD' ],

        // ── MLB ───────────────────────────────────────────────────────────────
        'batter_hits'          => [ 'H' ],
        'batter_total_bases'   => [ 'H' ],   // computed separately in settlement
        'batter_home_runs'     => [ 'HR' ],
        'batter_singles'       => [ 'H' ],   // computed separately: H - 2B - 3B - HR
        'batter_doubles'       => [ '2B' ],
        'batter_rbis'          => [ 'RBI' ],
        'batter_runs_scored'   => [ 'R' ],
        'batter_hits_runs_rbis'=> [ 'H', 'R', 'RBI' ],
        'batter_walks'         => [ 'BB' ],
        'batter_strikeouts'    => [ 'SO' ],
        'batter_stolen_bases'   => [ 'SB' ],
        'batter_first_home_run' => [ 'HR' ],  // binary: HR >= 1; computed in settlement
        'pitcher_strikeouts'    => [ 'SO' ],
        'pitcher_hits_allowed'  => [ 'H' ],
        'pitcher_walks'         => [ 'BB' ],
        'pitcher_earned_runs'   => [ 'ER' ],
        'pitcher_outs'          => [ 'IP' ],  // computed in settlement: IP × 3
        'pitcher_record_a_win'  => [ 'Dec' ], // binary: W decision; computed in settlement

        // ── NHL ───────────────────────────────────────────────────────────────
        'player_goals'               => [ 'G' ],
        'player_assists'             => [ 'A' ],
        'player_assists_nhl'         => [ 'A' ],
        'player_points'              => [ 'PTS' ],
        'player_shots_on_goal'       => [ 'S' ],
        'player_total_saves'         => [ 'SV' ],
        'player_power_play_points'   => [ 'PPG' ], // computed in settlement: PPG + PPA
        // player_blocked_shots: not available in ESPN NHL gamelog — voids.

        // MMA — settled via statsight_settle_mma(), not gamelog.
        'h2h'                        => [ 'W' ],
        'fighter_win_method'         => [ 'W' ],
        'fighter_win_method_and_round' => [ 'W' ],

        // NHL/soccer goal scorer markets — settled via scoringPlays, not gamelog.
        'player_goal_scorer_anytime' => [ 'G' ],
        'player_goal_scorer_first'   => [ 'G' ],
        'player_goal_scorer_last'    => [ 'G' ],
        'player_first_goal_scorer'   => [ 'G' ],
        'player_last_goal_scorer'    => [ 'G' ],
    ];
    return $map[ $mk ] ?? null;
}

/**
 * Fetch and cache a player's gamelog for hit rate calculation.
 * Returns the gamelog array (same format as the full player stats handler),
 * or null on failure. Results are stored in the same v3 transient so the
 * full player stats handler will also benefit from the warm cache.
 */
/**
 * Extract a numeric value from an ESPN stat string.
 * ESPN returns made/attempted as "10-25" — we want the made value (10).
 * Plain numeric strings like "34" or "6" pass through unchanged.
 */
/**
 * Normalize a player name for fuzzy matching.
 * Strips diacritics (é→e, ñ→n, etc.), removes punctuation, and lowercases.
 * This allows "Agustín Ramírez" to match "Agustin Ramirez".
 */
function statsight_strip_name_suffix( string $name ): string {
    // Remove generational suffixes (Jr., Sr., II, III, IV) so ESPN search finds the player.
    return trim( preg_replace( '/[\s,]+(jr\.?|sr\.?|ii|iii|iv)\.?\s*$/i', '', $name ) );
}

function statsight_normalize_name( string $name ): string {
    // Transliterate accented characters to ASCII equivalents.
    $name = transliterator_transliterate( 'Any-Latin; Latin-ASCII', $name ) ?? $name;
    // Convert hyphens to spaces so "Diggins-Smith" matches as two tokens; strip other non-alphanumeric characters.
    $name = str_replace( '-', ' ', $name );
    $name = preg_replace( '/[^a-z0-9 ]/i', '', $name );
    // Strip generational suffixes so "Isaiah Stewart II" matches ESPN's "Isaiah Stewart".
    $name = preg_replace( '/\b(jr|sr|ii|iii|iv)\s*$/i', '', $name );
    return strtolower( trim( $name ) );
}

function statsight_stat_to_float( mixed $value ): ?float {
    if ( $value === null || $value === '—' || $value === '' ) return null;
    $str = (string) $value;
    // "10-25" → take the part before the dash
    if ( str_contains( $str, '-' ) ) {
        $str = explode( '-', $str )[0];
    }
    return is_numeric( $str ) ? (float) $str : null;
}

function statsight_fetch_player_gamelog( string $sport, string $player_name, string $market_key, string $event_id ): ?array {
    $path = statsight_espn_sport_path( $sport );
    if ( ! $path ) return null;

    // Extend WP HTTP timeout for this function's ESPN calls.
    add_filter( 'http_request_timeout', fn() => 15 );

    $columns    = statsight_espn_stat_columns( $sport, $market_key );
    $col_labels = array_keys( $columns );
    $cache_key = 'statsight_player_v6_' . md5( $sport . $player_name . $market_key . $event_id );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached['gamelog'] ?? null;
    }

    $gamelog_base = 'https://site.web.api.espn.com/apis/common/v3/sports/'
        . $path['sport'] . '/' . $path['league'] . '/athletes';

    // Resolve athlete ID via ESPN search — strip suffix so "Isaiah Stewart II" finds "Isaiah Stewart".
    $athlete_id      = null;
    $search_url      = 'https://site.web.api.espn.com/apis/search/v2?' . http_build_query( [
        'query' => statsight_strip_name_suffix( $player_name ),
        'sport' => $path['sport'],
        'limit' => 5,
    ] );
    $search_response = wp_remote_get( $search_url, [ 'timeout' => 15 ] );

    if ( ! is_wp_error( $search_response ) && wp_remote_retrieve_response_code( $search_response ) === 200 ) {
        $search_data = json_decode( wp_remote_retrieve_body( $search_response ), true );
        foreach ( $search_data['results'] ?? [] as $group ) {
            if ( ( $group['type'] ?? '' ) !== 'player' ) continue;
            foreach ( $group['contents'] ?? [] as $item ) {
                $espn_name   = statsight_normalize_name( $item['displayName'] ?? '' );
                $search_name = statsight_normalize_name( $player_name );
                // ESPN sometimes drops a hyphenated suffix (e.g. "Skylar Diggins" for "Skylar Diggins-Smith"),
                // so accept if the ESPN name matches the start of the search name.
                if ( $espn_name !== $search_name && strpos( $search_name, $espn_name ) !== 0 ) continue;
                $uid = $item['uid'] ?? '';
                if ( preg_match( '/~a:(\d+)$/', $uid, $m ) ) {
                    $athlete_id = $m[1];
                }
                if ( ( $item['defaultLeagueSlug'] ?? '' ) === $path['league'] ) break 2;
            }
        }
    }

    if ( ! $athlete_id ) {
        return null;
    }

    // Fetch gamelog
    $gl_response = wp_remote_get( $gamelog_base . '/' . $athlete_id . '/gamelog', [ 'timeout' => 15 ] );
    if ( is_wp_error( $gl_response ) || wp_remote_retrieve_response_code( $gl_response ) !== 200 ) return null;

    $gl_data     = json_decode( wp_remote_retrieve_body( $gl_response ), true );
    $gl_labels   = $gl_data['labels'] ?? [];
    $col_indices = [];
    foreach ( $col_labels as $label ) {
        $idx = array_search( $label, $gl_labels, true );
        $col_indices[ $label ] = $idx !== false ? $idx : null;
    }

    $meta_by_id  = $gl_data['events'] ?? [];
    $stat_events = [];
    foreach ( $gl_data['seasonTypes'] ?? [] as $season_type ) {
        foreach ( $season_type['categories'] ?? [] as $category ) {
            foreach ( $category['events'] ?? [] as $stat_entry ) {
                $eid = $stat_entry['eventId'] ?? null;
                if ( $eid && isset( $meta_by_id[ $eid ] ) ) {
                    $stat_events[] = [ 'meta' => $meta_by_id[ $eid ], 'stats' => $stat_entry['stats'] ?? [] ];
                }
            }
        }
    }

    $seen    = [];
    $deduped = [];
    foreach ( $stat_events as $entry ) {
        $eid = $entry['meta']['id'] ?? '';
        if ( ! isset( $seen[ $eid ] ) ) { $seen[ $eid ] = true; $deduped[] = $entry; }
    }
    usort( $deduped, fn( $a, $b ) => strcmp( $a['meta']['gameDate'] ?? '', $b['meta']['gameDate'] ?? '' ) );
    $recent = array_slice( $deduped, -10 );

    // Compute season averages from all deduped games
    $season_sum   = array_fill_keys( $col_labels, 0 );
    $season_count = 0;
    foreach ( $deduped as $entry ) {
        $stats_arr = $entry['stats'];
        foreach ( $col_labels as $label ) {
            $idx = $col_indices[ $label ];
            $num = statsight_stat_to_float( $idx !== null ? ( $stats_arr[ $idx ] ?? null ) : null );
            if ( $num !== null ) {
                $season_sum[ $label ] += $num;
            }
        }
        $season_count++;
    }
    $season_averages = [];
    foreach ( $col_labels as $label ) {
        if ( $season_count > 0 ) {
            $avg = $season_sum[ $label ] / $season_count;
            $season_averages[ $label ] = str_contains( $label, '%' ) ? '—' : number_format( $avg, 1 );
        } else {
            $season_averages[ $label ] = '—';
        }
    }

    $gamelog  = [];
    $sum_cols  = array_fill_keys( $col_labels, 0 );
    $count     = 0;
    foreach ( $recent as $entry ) {
        $meta      = $entry['meta'];
        $stats_arr = $entry['stats'];
        $row_stats = [];
        foreach ( $col_labels as $label ) {
            $idx   = $col_indices[ $label ];
            $value = $idx !== null ? ( $stats_arr[ $idx ] ?? '—' ) : '—';
            $row_stats[ $label ] = $value;
            $num   = statsight_stat_to_float( $value );
            if ( $idx !== null && $num !== null ) {
                $sum_cols[ $label ] += $num;
            }
        }
        $count++;
        $opponent  = $meta['opponent']['abbreviation'] ?? $meta['opponent']['displayName'] ?? '?';
        $date      = isset( $meta['gameDate'] )
            ? ( new DateTime( $meta['gameDate'] ) )->setTimezone( new DateTimeZone( 'America/New_York' ) )->format( 'M j' )
            : '';
        $gamelog[] = [
            'date'     => $date,
            'opponent' => strtoupper( $opponent ),
            'result'   => strtoupper( $meta['gameResult'] ?? '' ),
            'stats'    => $row_stats,
        ];
    }

    // Compute recent averages
    $averages = [];
    foreach ( $col_labels as $label ) {
        if ( $count > 0 ) {
            $avg = $sum_cols[ $label ] / $count;
            $averages[ $label ] = str_contains( $label, '%' ) ? '—' : number_format( $avg, 1 );
        } else {
            $averages[ $label ] = '—';
        }
    }

    // Fetch position using the shared per-player cache so it's available
    // regardless of which market key triggered this fetch.
    $position_cache_key = 'statsight_player_pos_' . md5( $sport . $player_name );
    $position           = get_transient( $position_cache_key );
    if ( false === $position ) {
        $position         = null;
        $athlete_response = wp_remote_get( $gamelog_base . '/' . $athlete_id, [ 'timeout' => 15 ] );
        if ( ! is_wp_error( $athlete_response ) && wp_remote_retrieve_response_code( $athlete_response ) === 200 ) {
            $athlete_data = json_decode( wp_remote_retrieve_body( $athlete_response ), true );
            $position     = $athlete_data['athlete']['position']['abbreviation']
                ?? $athlete_data['athlete']['position']['displayName']
                ?? null;
        }
        set_transient( $position_cache_key, $position ?? '', 6 * HOUR_IN_SECONDS );
    }
    if ( $position === '' ) $position = null;

    remove_all_filters( 'http_request_timeout' );
    return $gamelog;
}

/**
 * Batch hit-rate lookup.
 * Accepts GET: sport, event_id, market_key, players (JSON array of {player, line}).
 * Returns { player: { hits, total, pct } } for all players, fetching fresh data as needed.
 */
function statsight_ajax_get_hit_rates(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'pro' );

    $sport      = isset( $_GET['sport'] )      ? sanitize_key( $_GET['sport'] )            : '';
    $market_key = isset( $_GET['market_key'] ) ? sanitize_key( $_GET['market_key'] )       : '';
    $event_id   = isset( $_GET['event_id'] )   ? sanitize_text_field( wp_unslash( $_GET['event_id'] ) )  : '';
    $players    = isset( $_GET['players'] )    ? json_decode( wp_unslash( $_GET['players'] ), true ) : [];

    if ( empty( $sport ) || empty( $market_key ) || ! is_array( $players ) ) {
        wp_send_json_success( [] );
    }

    $stat_cols = statsight_hit_rate_columns( $market_key );
    if ( ! $stat_cols ) {
        wp_send_json_success( [] );
    }

    // Optional opponent abbreviation — used to compute matchup-specific hit rate
    $opponent = isset( $_GET['opponent'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['opponent'] ) ) ) : '';

    $result = [];

    foreach ( $players as $entry ) {
        $player_name = sanitize_text_field( wp_unslash( $entry['player'] ?? '' ) );
        $line        = (float) ( $entry['line'] ?? 0 );

        if ( ! $player_name || $line <= 0 ) continue;

        $gamelog = statsight_fetch_player_gamelog( $sport, $player_name, $market_key, $event_id );
        if ( ! $gamelog ) continue;

        $total            = count( $gamelog );
        $hits             = 0;
        $game_values      = [];
        $opponent_values  = []; // games vs today's opponent only

        foreach ( $gamelog as $game ) {
            $val = 0.0;
            foreach ( $stat_cols as $col ) {
                $num = statsight_stat_to_float( $game['stats'][ $col ] ?? null );
                if ( $num !== null ) $val += $num;
            }
            $game_values[] = $val;
            if ( $val > $line ) $hits++;

            // Match opponent abbreviation (case-insensitive, partial OK)
            if ( $opponent ) {
                $game_opp = strtoupper( $game['opponent'] ?? '' );
                if ( $game_opp && ( $game_opp === $opponent || str_contains( $game_opp, $opponent ) || str_contains( $opponent, $game_opp ) ) ) {
                    $opponent_values[] = $val;
                }
            }
        }

        $result[ $player_name ] = [
            'hits'             => $hits,
            'total'            => $total,
            'pct'              => $total > 0 ? round( ( $hits / $total ) * 100 ) : 0,
            'game_values'      => $game_values,
            'opponent_values'  => $opponent_values,
        ];
    }

    wp_send_json_success( $result );
}
add_action( 'wp_ajax_statsight_get_hit_rates',        'statsight_ajax_get_hit_rates' );
add_action( 'wp_ajax_nopriv_statsight_get_hit_rates', 'statsight_ajax_get_hit_rates' );

function statsight_ajax_get_player_stats(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'pro' );

    $sport       = isset( $_GET['sport'] )       ? sanitize_key( $_GET['sport'] )             : '';
    $player_name = isset( $_GET['player_name'] ) ? sanitize_text_field( wp_unslash( $_GET['player_name'] ) ) : '';
    $event_id    = isset( $_GET['event_id'] )    ? sanitize_text_field( wp_unslash( $_GET['event_id'] ) )    : '';
    $market_key  = isset( $_GET['market_key'] )  ? sanitize_key( $_GET['market_key'] )                      : '';

    if ( empty( $sport ) || empty( $player_name ) ) {
        wp_send_json_error( [ 'message' => 'Missing required parameters.' ], 400 );
    }

    // ── MMA: separate lightweight handler ─────────────────────────────────
    if ( $sport === 'mma_mixed_martial_arts' ) {
        statsight_ajax_get_mma_fighter_stats( $player_name );
        return;
    }

    $path = statsight_espn_sport_path( $sport );
    if ( ! $path ) {
        wp_send_json_error( [ 'message' => 'Sport not supported.' ], 400 );
    }

    $columns     = statsight_espn_stat_columns( $sport, $market_key );
    $col_labels  = array_keys( $columns );
    $cache_key = 'statsight_player_v6_' . md5( $sport . $player_name . $market_key . $event_id );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        wp_send_json_success( $cached );
    }

    $base_url = 'https://site.api.espn.com/apis/site/v2/sports/'
        . $path['sport'] . '/' . $path['league'];
    $gamelog_base = 'https://site.web.api.espn.com/apis/common/v3/sports/'
        . $path['sport'] . '/' . $path['league'] . '/athletes';

    // ── Step 1: Resolve athlete ID via ESPN search ────────────────────────
    $athlete_id  = null;
    $live_stats  = null;

    $search_url      = 'https://site.web.api.espn.com/apis/search/v2?' . http_build_query( [
        'query' => statsight_strip_name_suffix( $player_name ),
        'sport' => $path['sport'],
        'limit' => 5,
    ] );
    $search_response = wp_remote_get( $search_url, [ 'timeout' => 10 ] );

    if ( ! is_wp_error( $search_response ) && wp_remote_retrieve_response_code( $search_response ) === 200 ) {
        $search_data = json_decode( wp_remote_retrieve_body( $search_response ), true );

        // Results are grouped by type (players, articles, clips). Each group has
        // a "contents" array with the actual items. Iterate contents of the
        // "player" group and match on displayName + defaultLeagueSlug.
        foreach ( $search_data['results'] ?? [] as $group ) {
            if ( ( $group['type'] ?? '' ) !== 'player' ) {
                continue;
            }
            foreach ( $group['contents'] ?? [] as $item ) {
                $espn_norm   = statsight_normalize_name( $item['displayName'] ?? '' );
                $search_norm = statsight_normalize_name( $player_name );
                if ( $espn_norm !== $search_norm && strpos( $search_norm, $espn_norm ) !== 0 ) {
                    continue;
                }
                // Prefer the result whose league matches — e.g. nba over ncaam.
                $uid          = $item['uid'] ?? '';
                $item_league  = $item['defaultLeagueSlug'] ?? '';
                if ( preg_match( '/~a:(\d+)$/', $uid, $m ) ) {
                    $athlete_id = $m[1];
                }
                // Keep looping only if this wasn't the right league slug.
                if ( $item_league === $path['league'] ) {
                    break 2; // exact league match — stop searching
                }
                // Otherwise keep the ID but continue in case a better match exists.
            }
        }
    }

    if ( ! $athlete_id ) {
        wp_send_json_error( [ 'message' => 'Player not found in ESPN data.' ], 404 );
    }

    // ── Step 2: Check today's scoreboard for a live/final game ────────────
    // Only fetch summaries for games that are in-progress or just finished.
    $scoreboard_url = $base_url . '/scoreboard';
    $sb_response    = wp_remote_get( $scoreboard_url, [ 'timeout' => 10 ] );
    if ( ! is_wp_error( $sb_response ) && wp_remote_retrieve_response_code( $sb_response ) === 200 ) {
        $sb_data = json_decode( wp_remote_retrieve_body( $sb_response ), true );

        foreach ( $sb_data['events'] ?? [] as $event ) {
            $state = $event['status']['type']['state'] ?? '';

            if ( ! in_array( $state, [ 'in', 'post' ], true ) ) {
                continue;
            }

            $summary_url  = $base_url . '/summary?event=' . $event['id'];
            $sum_response = wp_remote_get( $summary_url, [ 'timeout' => 10 ] );
            if ( is_wp_error( $sum_response ) || wp_remote_retrieve_response_code( $sum_response ) !== 200 ) {
                continue;
            }

            $sum_data = json_decode( wp_remote_retrieve_body( $sum_response ), true );
            $found    = false;

            foreach ( $sum_data['boxscore']['players'] ?? [] as $team_block ) {
                $labels = $team_block['statistics'][0]['labels'] ?? [];
                foreach ( $team_block['statistics'][0]['athletes'] ?? [] as $athlete ) {
                    // Compare as strings — ESPN search returns string IDs, boxscore may vary.
                    if ( (string) ( $athlete['athlete']['id'] ?? '' ) !== (string) $athlete_id ) {
                        continue;
                    }
                    if ( $athlete['didNotPlay'] ?? false ) {
                        break 2;
                    }
                    $stats_arr  = $athlete['stats'] ?? [];
                    $game_stats = [];
                    foreach ( $col_labels as $label ) {
                        $idx = array_search( $label, $labels, true );
                        $game_stats[ $label ] = $idx !== false ? ( $stats_arr[ $idx ] ?? '—' ) : '—';
                    }
                    $min_idx  = array_search( 'MIN', $labels, true );
                    $live_min = $min_idx !== false ? ( $stats_arr[ $min_idx ] ?? null ) : null;
                    $live_stats = [ 'state' => $state, 'stats' => $game_stats, 'min' => $live_min ];
                    $found = true;
                    break 2;
                }
            }

            if ( $found ) {
                break;
            }
        }
    }

    // ── Step 3: Fetch the gamelog ──────────────────────────────────────────
    $gamelog_url  = $gamelog_base . '/' . $athlete_id . '/gamelog';
    $gl_response  = wp_remote_get( $gamelog_url, [ 'timeout' => 10 ] );

    $gl_status = wp_remote_retrieve_response_code( $gl_response );
    if ( is_wp_error( $gl_response ) || $gl_status !== 200 ) {
        wp_send_json_error( [ 'message' => 'Could not fetch player game log.' ], 502 );
    }

    $gl_body = wp_remote_retrieve_body( $gl_response );
    $gl_data = json_decode( $gl_body, true );


    // Labels are at the top level.
    $gl_labels   = $gl_data['labels'] ?? [];
    $col_indices = [];
    foreach ( $col_labels as $label ) {
        $idx = array_search( $label, $gl_labels, true );
        $col_indices[ $label ] = $idx !== false ? $idx : null;
    }

    // Game metadata (date, opponent, result) is in top-level `events` keyed by eventId.
    // Stats are in seasonTypes[n].categories[n].events[n] as { eventId, stats[] }.
    // We use the most recent regular-season category (seasonType index 0, category index 0).
    $meta_by_id  = $gl_data['events'] ?? [];  // keyed by eventId
    $stat_events = [];
    foreach ( $gl_data['seasonTypes'] ?? [] as $season_type ) {
        foreach ( $season_type['categories'] ?? [] as $category ) {
            foreach ( $category['events'] ?? [] as $stat_entry ) {
                $eid = $stat_entry['eventId'] ?? null;
                if ( $eid && isset( $meta_by_id[ $eid ] ) ) {
                    $stat_events[] = [
                        'meta'  => $meta_by_id[ $eid ],
                        'stats' => $stat_entry['stats'] ?? [],
                    ];
                }
            }
        }
    }

    // Deduplicate by eventId (multiple categories can reference the same game),
    // sort ascending by gameDate, and take the last 5.
    $seen       = [];
    $deduped    = [];
    foreach ( $stat_events as $entry ) {
        $eid = $entry['meta']['id'] ?? '';
        if ( ! isset( $seen[ $eid ] ) ) {
            $seen[ $eid ] = true;
            $deduped[]    = $entry;
        }
    }
    usort( $deduped, fn( $a, $b ) => strcmp( $a['meta']['gameDate'] ?? '', $b['meta']['gameDate'] ?? '' ) );
    $recent = array_slice( $deduped, -10 );

    // Compute season average minutes separately (MIN is not in col_labels for most markets).
    $min_gl_idx     = array_search( 'MIN', $gl_labels, true );
    $season_min_sum = 0;
    $season_min_cnt = 0;
    if ( $min_gl_idx !== false ) {
        foreach ( $deduped as $entry ) {
            $raw = $entry['stats'][ $min_gl_idx ] ?? null;
            $num = statsight_stat_to_float( $raw );
            if ( $num !== null ) {
                $season_min_sum += $num;
                $season_min_cnt++;
            }
        }
    }
    $season_avg_min = $season_min_cnt > 0 ? round( $season_min_sum / $season_min_cnt, 1 ) : null;

    // Compute season averages from all games (not just the last 10).
    $season_sum   = array_fill_keys( $col_labels, 0 );
    $season_count = 0;
    foreach ( $deduped as $entry ) {
        $stats_arr = $entry['stats'];
        foreach ( $col_labels as $label ) {
            $idx = $col_indices[ $label ];
            $num = statsight_stat_to_float( $idx !== null ? ( $stats_arr[ $idx ] ?? null ) : null );
            if ( $num !== null ) {
                $season_sum[ $label ] += $num;
            }
        }
        $season_count++;
    }
    $season_averages = [];
    foreach ( $col_labels as $label ) {
        if ( $season_count > 0 ) {
            $avg = $season_sum[ $label ] / $season_count;
            $season_averages[ $label ] = str_contains( $label, '%' ) ? '—' : number_format( $avg, 1 );
        } else {
            $season_averages[ $label ] = '—';
        }
    }

    $gamelog  = [];
    $sum_cols = array_fill_keys( $col_labels, 0 );
    $count    = 0;

    foreach ( $recent as $entry ) {
        $meta      = $entry['meta'];
        $stats_arr = $entry['stats'];
        $row_stats = [];

        foreach ( $col_labels as $label ) {
            $idx   = $col_indices[ $label ];
            $value = $idx !== null ? ( $stats_arr[ $idx ] ?? '—' ) : '—';
            $row_stats[ $label ] = $value;

            // Accumulate numeric values for averages — parse "made-attempted" strings.
            $num = statsight_stat_to_float( $value );
            if ( $idx !== null && $num !== null ) {
                $sum_cols[ $label ] += $num;
            }
        }
        $count++;

        $opponent = $meta['opponent']['abbreviation'] ?? $meta['opponent']['displayName'] ?? '?';
        $result   = $meta['gameResult'] ?? '';
        $date     = isset( $meta['gameDate'] )
            ? ( new DateTime( $meta['gameDate'] ) )->setTimezone( new DateTimeZone( 'America/New_York' ) )->format( 'M j' )
            : '';

        $gamelog[] = [
            'date'     => $date,
            'opponent' => strtoupper( $opponent ),
            'result'   => strtoupper( $result ),
            'stats'    => $row_stats,
        ];
    }

    // Compute averages.
    $averages = [];
    foreach ( $col_labels as $label ) {
        if ( $count > 0 ) {
            $avg = $sum_cols[ $label ] / $count;
            // Show one decimal for all except percentages which ESPN already formats.
            $averages[ $label ] = str_contains( $label, '%' ) ? '—' : number_format( $avg, 1 );
        } else {
            $averages[ $label ] = '—';
        }
    }

    $headshot = 'https://a.espncdn.com/i/headshots/' . $path['league'] . '/players/full/' . $athlete_id . '.png';

    // ── Step 4: Fetch player position from ESPN athlete profile ───────────
    // Cached separately by sport + player so it's shared across all market keys.
    $position_cache_key = 'statsight_player_pos_' . md5( $sport . $player_name );
    $position           = get_transient( $position_cache_key );

    if ( false === $position ) {
        $position         = null; // default if fetch fails
        $athlete_url      = $gamelog_base . '/' . $athlete_id;
        $athlete_response = wp_remote_get( $athlete_url, [ 'timeout' => 10 ] );
        if ( ! is_wp_error( $athlete_response ) && wp_remote_retrieve_response_code( $athlete_response ) === 200 ) {
            $athlete_data = json_decode( wp_remote_retrieve_body( $athlete_response ), true );
            $position     = $athlete_data['athlete']['position']['abbreviation']
                ?? $athlete_data['athlete']['position']['displayName']
                ?? null;
        }
        // Cache for 24 hours — position rarely changes
        set_transient( $position_cache_key, $position ?? '', 6 * HOUR_IN_SECONDS );
    }

    // Stored as empty string when unknown — normalise back to null
    if ( $position === '' ) {
        $position = null;
    }

    $payload = [
        'player'          => $player_name,
        'headshot'        => $headshot,
        'position'        => $position,
        'sport'           => $sport,
        'columns'         => $columns,
        'gamelog'         => $gamelog,
        'averages'        => $averages,
        'season_averages' => $season_averages,
        'games_played'    => $season_count,
        'live_game'       => $live_stats,
        'season_avg_min'  => $season_avg_min,
    ];

    // Cache for 60 seconds when no live game found (so a game going live is picked
    // up quickly), or 30 seconds during a live game to keep stats fresh.
    $ttl = $live_stats ? 30 : MINUTE_IN_SECONDS;
    set_transient( $cache_key, $payload, $ttl );
    wp_send_json_success( $payload );
}
add_action( 'wp_ajax_statsight_get_player_stats',        'statsight_ajax_get_player_stats' );
add_action( 'wp_ajax_nopriv_statsight_get_player_stats', 'statsight_ajax_get_player_stats' );

/**
 * MMA lightweight fighter stats — called internally from statsight_ajax_get_player_stats.
 * Searches ESPN for the fighter, fetches bio + fight history, and sends JSON.
 */
function statsight_ajax_get_mma_fighter_stats( string $player_name ): void {
    $cache_key = 'statsight_mma_v2_' . md5( $player_name );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        wp_send_json_success( $cached );
        return;
    }

    // ── Step 1: Search for the fighter ───────────────────────────────────────
    $search_url = 'https://site.web.api.espn.com/apis/search/v2?' . http_build_query( [
        'query' => statsight_strip_name_suffix( $player_name ),
        'sport' => 'mma',
        'limit' => 5,
    ] );
    $search_res = wp_remote_get( $search_url, [ 'timeout' => 10 ] );

    $athlete_id = null;
    if ( ! is_wp_error( $search_res ) && wp_remote_retrieve_response_code( $search_res ) === 200 ) {
        $search_data = json_decode( wp_remote_retrieve_body( $search_res ), true );
        foreach ( $search_data['results'] ?? [] as $group ) {
            if ( ( $group['type'] ?? '' ) !== 'player' ) {
                continue;
            }
            foreach ( $group['contents'] ?? [] as $item ) {
                $espn_norm   = statsight_normalize_name( $item['displayName'] ?? '' );
                $search_norm = statsight_normalize_name( $player_name );
                if ( $espn_norm !== $search_norm ) {
                    continue;
                }
                $uid = $item['uid'] ?? '';
                if ( preg_match( '/~a:(\d+)$/', $uid, $m ) ) {
                    $athlete_id = $m[1];
                    break 2;
                }
            }
        }
    }

    if ( ! $athlete_id ) {
        wp_send_json_error( [ 'message' => 'Fighter not found in ESPN data.' ], 404 );
        return;
    }

    // ── Step 2: Fetch fighter bio / profile ──────────────────────────────────
    // Actual field names confirmed via ESPN API inspection:
    // - record is in statsSummary.statistics[0].displayValue as "W-L-D"
    // - stance is an object { id, text } — use ['text']
    // - weightClass is an object { id, text, ... } — use ['text']
    // - reach is 'displayReach', height is 'displayHeight', weight is 'displayWeight'
    // - headshot is an object { href, alt } — use ['href']
    $bio_url = 'https://site.web.api.espn.com/apis/common/v3/sports/mma/ufc/athletes/' . $athlete_id;
    $bio_res = wp_remote_get( $bio_url, [ 'timeout' => 10 ] );

    $bio = [];
    if ( ! is_wp_error( $bio_res ) && wp_remote_retrieve_response_code( $bio_res ) === 200 ) {
        $bio_data = json_decode( wp_remote_retrieve_body( $bio_res ), true );
        $athlete  = $bio_data['athlete'] ?? [];

        // Record from statsSummary statistics array (displayValue = "28-1-0")
        $record_str = '';
        $wins = $losses = $draws = $nc = 0;
        foreach ( $athlete['statsSummary']['statistics'] ?? [] as $stat ) {
            if ( ( $stat['name'] ?? '' ) === 'wins-losses-draws' ) {
                $record_str = $stat['displayValue'] ?? '';
                break;
            }
        }
        if ( $record_str && preg_match( '/(\d+)-(\d+)-(\d+)/', $record_str, $rm ) ) {
            $wins   = (int) $rm[1];
            $losses = (int) $rm[2];
            $draws  = (int) $rm[3];
        }

        // KO and submission breakdown from statsSummary
        $kos  = 0;
        $subs = 0;
        foreach ( $athlete['statsSummary']['statistics'] ?? [] as $stat ) {
            $name = $stat['name'] ?? '';
            $dv   = $stat['displayValue'] ?? '';
            if ( $name === 'tkos-tkoLosses' && preg_match( '/^(\d+)/', $dv, $rm ) ) {
                $kos = (int) $rm[1];
            }
            if ( $name === 'submissions-submissionLosses' && preg_match( '/^(\d+)/', $dv, $rm ) ) {
                $subs = (int) $rm[1];
            }
        }

        // Headshot is an object { href, alt }
        $headshot = $athlete['headshot']['href'] ?? '';

        $bio = [
            'name'         => $athlete['displayName'] ?? $player_name,
            'headshot'     => $headshot,
            'weight_class' => $athlete['weightClass']['text'] ?? '',
            'record'       => $record_str,
            'wins'         => $wins,
            'losses'       => $losses,
            'draws'        => $draws,
            'nc'           => $nc,
            'kos'          => $kos,
            'subs'         => $subs,
            'stance'       => $athlete['stance']['text'] ?? '',
            'reach'        => $athlete['displayReach'] ?? '',
            'height'       => $athlete['displayHeight'] ?? '',
            'weight'       => $athlete['displayWeight'] ?? '',
            'style'        => $athlete['displayFightingStyle'] ?? '',
        ];
    }

    // ── Step 3: Fetch fight history from overview fightHistory UIDs ──────────
    // The eventlog endpoint is empty for MMA. The overview endpoint returns a
    // fightHistory array of UIDs in the format s:SPORT~l:LEAGUE~e:EVENT~c:COMP.
    // We parse each UID, fetch the competition (date + competitor IDs + winner),
    // the event name, the status (method/round/time), and the opponent name.
    // Fighter can compete in multiple promotions (UFC, PFL, etc.) — we use the
    // numeric league ID to derive the slug via a known map.
    $league_slug_map = [
        '3321' => 'ufc',
        '3359' => 'pfl',
    ];

    // Fetch the overview from the UFC endpoint — it includes cross-promotion UIDs.
    $overview_url = 'https://site.web.api.espn.com/apis/common/v3/sports/mma/ufc/athletes/' . $athlete_id . '/overview';
    $ov_res       = wp_remote_get( $overview_url, [ 'timeout' => 10 ] );
    $fh_uids      = [];
    if ( ! is_wp_error( $ov_res ) && wp_remote_retrieve_response_code( $ov_res ) === 200 ) {
        $ov_data = json_decode( wp_remote_retrieve_body( $ov_res ), true );
        $fh_uids = $ov_data['fightHistory'] ?? [];
    }

    $fights = [];

    foreach ( array_slice( $fh_uids, 0, 10 ) as $uid ) {
        // Parse s:3301~l:3359~e:600015472~c:401408931
        if ( ! preg_match( '/~l:(\d+)~e:(\d+)~c:(\d+)/', $uid, $um ) ) {
            continue;
        }
        $league_id = $um[1];
        $event_id  = $um[2];
        $comp_id   = $um[3];
        $slug      = $league_slug_map[ $league_id ] ?? $league_id; // fallback to numeric id

        $core_base = 'https://sports.core.api.espn.com/v2/sports/mma/leagues/' . $slug;

        // Fetch competition for date, competitor IDs, winner flag.
        $comp_url  = $core_base . '/events/' . $event_id . '/competitions/' . $comp_id . '?lang=en&region=us';
        $comp_res  = wp_remote_get( $comp_url, [ 'timeout' => 8 ] );
        if ( is_wp_error( $comp_res ) || wp_remote_retrieve_response_code( $comp_res ) !== 200 ) {
            continue;
        }
        $comp_data = json_decode( wp_remote_retrieve_body( $comp_res ), true );

        $date        = substr( $comp_data['date'] ?? '', 0, 10 );
        $result      = 'L';
        $opponent_id = null;
        foreach ( $comp_data['competitors'] ?? [] as $c ) {
            if ( (string) ( $c['id'] ?? '' ) === (string) $athlete_id ) {
                $result = ( $c['winner'] ?? false ) ? 'W' : 'L';
            } else {
                $opponent_id = (string) ( $c['id'] ?? '' );
            }
        }

        // Fetch event name.
        $event_name = '';
        $ev_res     = wp_remote_get( $core_base . '/events/' . $event_id . '?lang=en&region=us', [ 'timeout' => 8 ] );
        if ( ! is_wp_error( $ev_res ) && wp_remote_retrieve_response_code( $ev_res ) === 200 ) {
            $ev_data    = json_decode( wp_remote_retrieve_body( $ev_res ), true );
            $event_name = $ev_data['shortName'] ?? $ev_data['name'] ?? '';
        }

        // Fetch opponent name.
        $opponent = '—';
        if ( $opponent_id ) {
            $ath_res = wp_remote_get( 'https://sports.core.api.espn.com/v2/sports/mma/athletes/' . $opponent_id . '?lang=en&region=us', [ 'timeout' => 8 ] );
            if ( ! is_wp_error( $ath_res ) && wp_remote_retrieve_response_code( $ath_res ) === 200 ) {
                $ath_data = json_decode( wp_remote_retrieve_body( $ath_res ), true );
                $opponent = $ath_data['displayName'] ?? $ath_data['fullName'] ?? '—';
            }
        }

        // Fetch status for method, round, time.
        $method = '';
        $round  = '';
        $time   = '';
        $st_res = wp_remote_get( $core_base . '/events/' . $event_id . '/competitions/' . $comp_id . '/status?lang=en&region=us', [ 'timeout' => 8 ] );
        if ( ! is_wp_error( $st_res ) && wp_remote_retrieve_response_code( $st_res ) === 200 ) {
            $st_data    = json_decode( wp_remote_retrieve_body( $st_res ), true );
            $result_obj = $st_data['result'] ?? [];
            $method     = $result_obj['shortDisplayName'] ?? $result_obj['displayName'] ?? '';
            $round      = isset( $st_data['period'] ) ? (string) $st_data['period'] : '';
            $time       = $st_data['displayClock'] ?? '';
        }

        $fights[] = [
            'date'     => $date,
            'event'    => $event_name,
            'opponent' => $opponent,
            'result'   => $result,
            'method'   => $method,
            'round'    => $round,
            'time'     => $time,
        ];
    }

    $payload = [
        'mma'    => true,
        'player' => $bio['name'] ?? $player_name,
        'bio'    => $bio,
        'fights' => $fights,
        'sport'  => 'mma_mixed_martial_arts',
    ];

    set_transient( $cache_key, $payload, 5 * MINUTE_IN_SECONDS );
    wp_send_json_success( $payload );
}

/**
 * AJAX handler — return all players for a given market key across every game
 * on the next game day for a sport. Uses only cached props so no fresh API
 * calls are needed. Returns a flat list sorted by default line descending.
 */
function statsight_ajax_get_market_props(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'pro' );

    $sport      = isset( $_GET['sport'] )      ? sanitize_key( $_GET['sport'] )             : '';
    $market_key = isset( $_GET['market_key'] ) ? sanitize_key( $_GET['market_key'] )        : '';

    if ( empty( $sport ) || empty( $market_key ) ) {
        wp_send_json_error( [ 'message' => 'Missing parameters.' ], 400 );
    }

    // Resolve the base market key (strip _alternate suffix for lookup)
    $base_key = preg_replace( '/_alternate$/', '', $market_key );

    // Gather events — fetch fresh if the transient isn't cached yet.
    $events_cache = get_transient( 'statsight_events_' . $sport ) ?: statsight_get_events_for_sport( $sport );
    $events       = [];
    foreach ( $events_cache['days'] ?? [] as $day ) {
        foreach ( $day['events'] ?? [] as $event ) {
            $events[] = $event;
        }
    }

    if ( empty( $events ) ) {
        wp_send_json_success( [] );
    }

    $market_labels = statsight_market_labels();
    $rows          = [];

    foreach ( $events as $event ) {
        $event_id = $event['id'] ?? '';
        if ( empty( $event_id ) ) continue;

        $cached = get_transient( 'statsight_props2_' . $event_id );
        if ( ! $cached ) {
            $fetched = statsight_fetch_and_cache_props( $sport, $event_id );
            if ( is_wp_error( $fetched ) ) continue;
            $cached = $fetched;
        }

        $props         = $cached['props']         ?? [];
        $books         = $cached['books']         ?? [];
        $default_lines = $cached['default_lines'] ?? [];

        // Merge base + alternate market data for this market key.
        $market_data = $props[ $base_key ] ?? [];
        $alt_key     = $base_key . '_alternate';
        foreach ( $props[ $alt_key ] ?? [] as $player => $lines ) {
            foreach ( $lines as $lk => $bk_data ) {
                if ( ! isset( $market_data[ $player ][ $lk ] ) ) {
                    $market_data[ $player ][ $lk ] = $bk_data;
                } else {
                    foreach ( $bk_data as $bk => $odds ) {
                        if ( ! isset( $market_data[ $player ][ $lk ][ $bk ] ) ) {
                            $market_data[ $player ][ $lk ][ $bk ] = $odds;
                        }
                    }
                }
            }
        }

        if ( empty( $market_data ) ) continue;

        $matchup   = ( $event['away_team'] ?? '' ) . ' @ ' . ( $event['home_team'] ?? '' );
        $game_time = $event['commence_time'] ?? '';

        foreach ( $market_data as $player => $lines ) {
            // Pick the default (consensus) line for this player.
            $default_line = $default_lines[ $base_key ][ $player ]
                ?? $default_lines[ $alt_key ][ $player ]
                ?? null;

            if ( $default_line === null ) {
                // Fall back to middle line if no default recorded.
                $line_keys    = array_keys( $lines );
                $default_line = $line_keys[ (int) floor( count( $line_keys ) / 2 ) ] ?? null;
            }

            if ( $default_line === null || ! isset( $lines[ $default_line ] ) ) continue;

            $rows[] = [
                'player'       => $player,
                'line'         => $default_line,
                'lines'        => $lines,          // full line map for stepper
                'books'        => $books,
                'event_id'     => $event_id,
                'matchup'      => $matchup,
                'game_time'    => $game_time,
                'market_key'   => $base_key,
                'market_label' => $market_labels[ $base_key ] ?? $base_key,
            ];
        }
    }

    // Sort by default line descending (highest line = biggest star/volume)
    usort( $rows, fn( $a, $b ) => (float) $b['line'] <=> (float) $a['line'] );

    wp_send_json_success( $rows );
}
add_action( 'wp_ajax_statsight_get_market_props', 'statsight_ajax_get_market_props' );

/**
 * Return all props across all markets for a sport — used by the Arbitrage tab
 * to find arbitrage opportunities without requiring a game to be open.
 *
 * Response: { event_id: { matchup, game_time, markets: { market_key: { player: { line: { book: {over,under} } } } } } }
 */
function statsight_ajax_get_all_props(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Login required.' ], 401 );
    }
    statsight_require_plan( 'sharp' );

    $sport = isset( $_GET['sport'] ) ? sanitize_key( $_GET['sport'] ) : '';
    if ( empty( $sport ) ) {
        wp_send_json_error( [ 'message' => 'Missing sport.' ], 400 );
    }

    $cache_key = 'statsight_all_props_' . $sport;
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        wp_send_json_success( $cached );
    }

    $events_cache = get_transient( 'statsight_events_' . $sport ) ?: statsight_get_events_for_sport( $sport );
    $events       = [];
    foreach ( $events_cache['days'] ?? [] as $day ) {
        foreach ( $day['events'] ?? [] as $event ) {
            $events[] = $event;
        }
    }

    if ( empty( $events ) ) {
        wp_send_json_success( [] );
    }

    $result = [];

    foreach ( $events as $event ) {
        $event_id = $event['id'] ?? '';
        if ( empty( $event_id ) ) continue;

        $cached_props = get_transient( 'statsight_props2_' . $event_id );
        if ( ! $cached_props ) continue;

        $props         = $cached_props['props']         ?? [];
        $books         = $cached_props['books']         ?? [];
        $default_lines = $cached_props['default_lines'] ?? [];

        if ( empty( $props ) ) continue;

        $result[ $event_id ] = [
            'matchup'   => ( $event['away_team'] ?? '' ) . ' @ ' . ( $event['home_team'] ?? '' ),
            'game_time' => $event['commence_time'] ?? '',
            'books'     => $books,
            'markets'   => [],
        ];

        foreach ( $props as $market_key => $market_data ) {
            // Merge alternate into its base market
            $base_key = preg_replace( '/_alternate$/', '', $market_key );
            if ( ! isset( $result[ $event_id ]['markets'][ $base_key ] ) ) {
                $result[ $event_id ]['markets'][ $base_key ] = [];
            }
            foreach ( $market_data as $player => $lines ) {
                foreach ( $lines as $line => $bk_data ) {
                    foreach ( $bk_data as $bk => $odds ) {
                        if ( ! isset( $result[ $event_id ]['markets'][ $base_key ][ $player ][ $line ][ $bk ] ) ) {
                            $result[ $event_id ]['markets'][ $base_key ][ $player ][ $line ][ $bk ] = $odds;
                        }
                    }
                }
            }
        }
    }

    set_transient( $cache_key, $result, 1 * MINUTE_IN_SECONDS );
    wp_send_json_success( $result );
}
add_action( 'wp_ajax_statsight_get_all_props',        'statsight_ajax_get_all_props' );
add_action( 'wp_ajax_nopriv_statsight_get_all_props', 'statsight_ajax_get_all_props' );

/**
 * Return line movement data for all of today's events for a sport.
 * For each player+market, returns the earliest and latest recorded line
 * so the client can compute movement without needing a game detail open.
 *
 * Response: [ { player, market_key, matchup, open_line, current_line, delta }, ... ]
 */
function statsight_ajax_get_line_moves(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'sharp' );

    $sport = isset( $_GET['sport'] ) ? sanitize_key( $_GET['sport'] ) : '';
    if ( empty( $sport ) ) {
        wp_send_json_error( [ 'message' => 'Missing sport.' ], 400 );
    }

    $cache_key = 'statsight_line_moves_' . $sport;
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        wp_send_json_success( $cached );
    }

    $events_cache = get_transient( 'statsight_events_' . $sport ) ?: statsight_get_events_for_sport( $sport );
    $events       = [];
    foreach ( $events_cache['days'] ?? [] as $day ) {
        foreach ( $day['events'] ?? [] as $event ) {
            $events[] = $event;
        }
    }

    if ( empty( $events ) ) {
        wp_send_json_success( [] );
    }

    global $wpdb;
    $table     = $wpdb->prefix . 'statsight_odds_history';
    $event_ids = array_values( array_filter( array_column( $events, 'id' ) ) );

    if ( empty( $event_ids ) ) {
        wp_send_json_success( [] );
    }

    $matchups = [];
    foreach ( $events as $event ) {
        $id = $event['id'] ?? '';
        if ( $id ) {
            $matchups[ $id ] = ( $event['away_team'] ?? '' ) . ' @ ' . ( $event['home_team'] ?? '' );
        }
    }

    $placeholders = implode( ', ', array_fill( 0, count( $event_ids ), '%s' ) );

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT event_id, market_key, player,
                    MIN(recorded_at) AS first_at,
                    MAX(recorded_at) AS last_at
             FROM {$table}
             WHERE event_id IN ({$placeholders})
             GROUP BY event_id, market_key, player",
            ...$event_ids
        ),
        ARRAY_A
    );

    if ( empty( $rows ) ) {
        wp_send_json_success( [] );
    }

    $moves = [];

    foreach ( $rows as $row ) {
        $event_id   = $row['event_id'];
        $market_key = $row['market_key'];
        $player     = $row['player'];
        $first_at   = $row['first_at'];
        $last_at    = $row['last_at'];

        if ( $first_at === $last_at ) continue;

        $base_key = preg_replace( '/_alternate$/', '', $market_key );

        // Open = most frequently recorded line at the earliest snapshot (mode).
        $open_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT line, COUNT(*) AS cnt FROM {$table}
                 WHERE event_id = %s AND market_key = %s AND player = %s AND recorded_at = %s
                 GROUP BY line ORDER BY cnt DESC LIMIT 1",
                $event_id, $market_key, $player, $first_at
            ),
            ARRAY_A
        );

        // Current = most frequently recorded line at the most recent snapshot (mode).
        $current_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT line, COUNT(*) AS cnt FROM {$table}
                 WHERE event_id = %s AND market_key = %s AND player = %s AND recorded_at = %s
                 GROUP BY line ORDER BY cnt DESC LIMIT 1",
                $event_id, $market_key, $player, $last_at
            ),
            ARRAY_A
        );

        if ( ! $open_row || ! $current_row ) continue;

        $open_line    = (float) $open_row['line'];
        $current_line = (float) $current_row['line'];
        $delta        = $current_line - $open_line;

        if ( abs( $delta ) < 0.5 ) continue;

        $moves[] = [
            'event_id'     => $event_id,
            'player'       => $player,
            'market_key'   => $base_key,
            'matchup'      => $matchups[ $event_id ] ?? $event_id,
            'open_line'    => $open_line,
            'current_line' => $current_line,
            'delta'        => $delta,
        ];
    }

    usort( $moves, fn( $a, $b ) => abs( $b['delta'] ) <=> abs( $a['delta'] ) );

    set_transient( $cache_key, $moves, 2 * MINUTE_IN_SECONDS );
    wp_send_json_success( $moves );
}
add_action( 'wp_ajax_statsight_get_line_moves', 'statsight_ajax_get_line_moves' );

/**
 * Returns prop market categories and their API market keys per sport group.
 * Each category maps to a tab inside the game detail view.
 *
 * @param string $sport  The Odds API sport key (e.g. "basketball_nba").
 * @return array<string, array{label: string, markets: string[]}>
 */
function statsight_prop_categories( string $sport ): array {
    if ( str_starts_with( $sport, 'basketball' ) ) {
        return [
            'scoring'   => [ 'label' => 'Scoring',   'markets' => [ 'player_points',   'player_points_alternate',   'player_threes',   'player_threes_alternate',   'player_field_goals', 'player_frees_made' ] ],
            'boards'    => [ 'label' => 'Rebounds',  'markets' => [ 'player_rebounds',  'player_rebounds_alternate' ] ],
            'playmaker' => [ 'label' => 'Assists',   'markets' => [ 'player_assists',   'player_assists_alternate' ] ],
            'defense'   => [ 'label' => 'Defense',   'markets' => [ 'player_blocks',    'player_blocks_alternate',   'player_steals',   'player_steals_alternate',   'player_blocks_steals' ] ],
            'combos'    => [ 'label' => 'Combos',    'markets' => [ 'player_points_rebounds_assists', 'player_points_rebounds_assists_alternate', 'player_points_rebounds', 'player_points_rebounds_alternate', 'player_points_assists', 'player_points_assists_alternate', 'player_rebounds_assists', 'player_rebounds_assists_alternate' ] ],
            'special'   => [ 'label' => 'Special',   'markets' => [ 'player_double_double', 'player_triple_double', 'player_first_basket', 'player_turnovers', 'player_turnovers_alternate' ] ],
        ];
    }

    if ( str_starts_with( $sport, 'americanfootball' ) ) {
        return [
            'passing'   => [ 'label' => 'Passing',   'markets' => [ 'player_pass_yds',       'player_pass_yds_alternate',       'player_pass_tds',        'player_pass_tds_alternate',       'player_pass_attempts', 'player_pass_completions', 'player_pass_interceptions', 'player_pass_longest_completion' ] ],
            'rushing'   => [ 'label' => 'Rushing',   'markets' => [ 'player_rush_yds',       'player_rush_yds_alternate',       'player_rush_attempts',   'player_rush_tds',                  'player_rush_longest' ] ],
            'receiving' => [ 'label' => 'Receiving', 'markets' => [ 'player_reception_yds',  'player_reception_yds_alternate',  'player_receptions',      'player_receptions_alternate',      'player_reception_tds', 'player_reception_longest' ] ],
            'defense'   => [ 'label' => 'Defense',   'markets' => [ 'player_sacks',          'player_sacks_alternate',          'player_solo_tackles',    'player_tackles_assists',            'player_defensive_interceptions' ] ],
            'tds'       => [ 'label' => 'Touchdowns','markets' => [ 'player_anytime_td',     'player_1st_td',                   'player_last_td',         'player_tds_over' ] ],
            'kicking'   => [ 'label' => 'Kicking',   'markets' => [ 'player_kicking_points', 'player_field_goals',              'player_pats' ] ],
        ];
    }

    if ( str_starts_with( $sport, 'baseball' ) ) {
        return [
            'hitting'   => [ 'label' => 'Hitting',   'markets' => [ 'batter_hits',         'batter_hits_alternate',         'batter_total_bases', 'batter_total_bases_alternate', 'batter_home_runs', 'batter_home_runs_alternate', 'batter_singles', 'batter_doubles' ] ],
            'rbi'       => [ 'label' => 'RBI & Runs','markets' => [ 'batter_rbis',          'batter_rbis_alternate',         'batter_runs_scored', 'batter_hits_runs_rbis' ] ],
            'pitching'  => [ 'label' => 'Pitching',  'markets' => [ 'pitcher_strikeouts',   'pitcher_strikeouts_alternate',  'pitcher_outs',       'pitcher_hits_allowed', 'pitcher_walks', 'pitcher_earned_runs' ] ],
            'special'   => [ 'label' => 'Special',   'markets' => [ 'batter_stolen_bases',  'batter_walks',                  'batter_strikeouts',  'batter_first_home_run', 'pitcher_record_a_win' ] ],
        ];
    }

    if ( str_starts_with( $sport, 'icehockey' ) ) {
        return [
            // player_goal_scorer_anytime is fetched separately (see $fetch_only_hist) and merged into player_goals at 0.5.
            'scoring'    => [ 'label' => 'Scoring',    'markets' => [ 'player_goals', 'player_goals_alternate' ] ],
            'production' => [ 'label' => 'Production', 'markets' => [ 'player_points', 'player_points_alternate', 'player_power_play_points', 'player_power_play_points_alternate', 'player_assists', 'player_assists_alternate' ] ],
            'shots'      => [ 'label' => 'Shots',      'markets' => [ 'player_shots_on_goal', 'player_shots_on_goal_alternate', 'player_blocked_shots', 'player_blocked_shots_alternate' ] ],
            'goalie'     => [ 'label' => 'Goalie',     'markets' => [ 'player_total_saves', 'player_total_saves_alternate' ] ],
            'scorer'     => [ 'label' => 'Goal Scorer', 'markets' => [ 'player_goal_scorer_first', 'player_goal_scorer_last' ] ],
        ];
    }

    if ( str_starts_with( $sport, 'mma' ) ) {
        return [
            'moneyline'    => [ 'label' => 'Moneyline',    'markets' => [ 'h2h' ] ],
            'round_totals' => [ 'label' => 'Round Totals', 'markets' => [ 'totals' ] ],
        ];
    }

    if ( str_starts_with( $sport, 'soccer' ) ) {
        return [
            'goals'     => [ 'label' => 'Goals',   'markets' => [ 'player_goal_scorer_anytime', 'player_first_goal_scorer', 'player_last_goal_scorer' ] ],
            'shots'     => [ 'label' => 'Shots',   'markets' => [ 'player_shots_on_target',     'player_shots' ] ],
            'assists'   => [ 'label' => 'Assists', 'markets' => [ 'player_assists' ] ],
            'cards'     => [ 'label' => 'Cards',   'markets' => [ 'player_to_receive_card',      'player_to_receive_red_card' ] ],
        ];
    }

    // Generic fallback
    return [
        'props' => [ 'label' => 'Props', 'markets' => [ 'player_points', 'player_points_alternate', 'player_assists', 'player_rebounds' ] ],
    ];
}

/**
 * Human-readable labels for individual market keys.
 *
 * @return array<string, string>
 */
function statsight_market_labels(): array {
    return [
        'player_points'                      => 'Points',
        'player_rebounds'                    => 'Rebounds',
        'player_assists'                     => 'Assists',
        'player_threes'                      => '3-Pointers Made',
        'player_blocks'                      => 'Blocks',
        'player_steals'                      => 'Steals',
        'player_blocks_steals'               => 'Blks + Stls',
        'player_turnovers'                   => 'Turnovers',
        'player_field_goals'                 => 'Field Goals',
        'player_frees_made'                  => 'Free Throws Made',
        'player_points_rebounds_assists'     => 'Pts + Reb + Ast',
        'player_points_rebounds'             => 'Pts + Reb',
        'player_points_assists'              => 'Pts + Ast',
        'player_rebounds_assists'            => 'Reb + Ast',
        'player_double_double'               => 'Double Double',
        'player_triple_double'               => 'Triple Double',
        'player_first_basket'                => 'First Basket',
        'player_pass_yds'                    => 'Pass Yards',
        'player_pass_tds'                    => 'Pass TDs',
        'player_pass_attempts'               => 'Pass Attempts',
        'player_pass_completions'            => 'Completions',
        'player_pass_interceptions'          => 'Interceptions',
        'player_pass_longest_completion'     => 'Longest Completion',
        'player_rush_yds'                    => 'Rush Yards',
        'player_rush_attempts'               => 'Rush Attempts',
        'player_rush_tds'                    => 'Rush TDs',
        'player_rush_longest'                => 'Longest Rush',
        'player_reception_yds'               => 'Receiving Yards',
        'player_receptions'                  => 'Receptions',
        'player_reception_tds'               => 'Receiving TDs',
        'player_reception_longest'           => 'Longest Reception',
        'player_sacks'                       => 'Sacks',
        'player_solo_tackles'                => 'Solo Tackles',
        'player_tackles_assists'             => 'Tackles + Assists',
        'player_defensive_interceptions'     => 'Def. Interceptions',
        'player_anytime_td'                  => 'Anytime TD',
        'player_1st_td'                      => 'First TD',
        'player_last_td'                     => 'Last TD',
        'player_tds_over'                    => 'TDs Over',
        'player_kicking_points'              => 'Kicking Points',
        'player_pats'                        => 'PATs Made',
        'batter_hits'                        => 'Hits',
        'batter_total_bases'                 => 'Total Bases',
        'batter_home_runs'                   => 'Home Runs',
        'batter_singles'                     => 'Singles',
        'batter_doubles'                     => 'Doubles',
        'batter_rbis'                        => 'RBIs',
        'batter_runs_scored'                 => 'Runs Scored',
        'batter_hits_runs_rbis'              => 'Hits + Runs + RBIs',
        'batter_walks'                       => 'Walks',
        'batter_strikeouts'                  => 'Strikeouts',
        'batter_stolen_bases'                => 'Stolen Bases',
        'batter_first_home_run'              => 'First Home Run',
        'pitcher_strikeouts'                 => 'Strikeouts',
        'pitcher_outs'                       => 'Outs Recorded',
        'pitcher_hits_allowed'               => 'Hits Allowed',
        'pitcher_walks'                      => 'Walks Allowed',
        'pitcher_earned_runs'                => 'Earned Runs',
        'pitcher_record_a_win'               => 'Record a Win',
        'player_goals'                       => 'Goals',
        'player_shots_on_goal'               => 'Shots on Goal',
        'player_blocked_shots'               => 'Blocked Shots',
        'player_power_play_points'           => 'Power Play Pts',
        'player_total_saves'                 => 'Saves',
        'player_goal_scorer_anytime'         => 'Anytime Scorer',
        'player_goal_scorer_first'           => 'First Goal Scorer',
        'player_goal_scorer_last'            => 'Last Goal Scorer',
        'player_first_goal_scorer'           => 'First Goal Scorer',
        'player_last_goal_scorer'            => 'Last Goal Scorer',
        'player_shots_on_target'             => 'Shots on Target',
        'player_shots'                       => 'Shots',
        'player_to_receive_card'                      => 'To Receive Card',
        'player_to_receive_red_card'                  => 'Red Card',
        // MMA / H2H / Game totals
        'h2h'                                         => 'Moneyline',
        'totals'                                      => 'Round Total',
        'spreads'                                     => 'Round Spread',
        'fighter_win_method'                          => 'Method of Victory',
        'fighter_win_method_and_round'                => 'Method & Round',
        'fighter_total_rounds'                        => 'Total Rounds',
        'fighter_round_betting'                       => 'Round Betting',
        'fighter_round_scored'                        => 'Round Scored',
        'fighter_points'                              => 'Fighter Points',
        'fighter_head_strikes'                        => 'Head Strikes',
        'fighter_body_strikes'                        => 'Body Strikes',
        'fighter_leg_strikes'                         => 'Leg Strikes',
        'fighter_takedowns'                           => 'Takedowns',
        'fighter_knockdowns'                          => 'Knockdowns',
        // Alternates — inherit the same label as the standard market
        'player_points_alternate'                     => 'Points (Alt Lines)',
        'player_rebounds_alternate'                   => 'Rebounds (Alt Lines)',
        'player_assists_alternate'                    => 'Assists (Alt Lines)',
        'player_threes_alternate'                     => '3-Pointers (Alt Lines)',
        'player_blocks_alternate'                     => 'Blocks (Alt Lines)',
        'player_steals_alternate'                     => 'Steals (Alt Lines)',
        'player_turnovers_alternate'                  => 'Turnovers (Alt Lines)',
        'player_points_rebounds_assists_alternate'    => 'Pts+Reb+Ast (Alt Lines)',
        'player_points_rebounds_alternate'            => 'Pts+Reb (Alt Lines)',
        'player_points_assists_alternate'             => 'Pts+Ast (Alt Lines)',
        'player_rebounds_assists_alternate'           => 'Reb+Ast (Alt Lines)',
        'player_pass_yds_alternate'                   => 'Pass Yards (Alt Lines)',
        'player_pass_tds_alternate'                   => 'Pass TDs (Alt Lines)',
        'player_rush_yds_alternate'                   => 'Rush Yards (Alt Lines)',
        'player_reception_yds_alternate'              => 'Receiving Yards (Alt Lines)',
        'player_receptions_alternate'                 => 'Receptions (Alt Lines)',
        'player_sacks_alternate'                      => 'Sacks (Alt Lines)',
        'batter_hits_alternate'                       => 'Hits (Alt Lines)',
        'batter_total_bases_alternate'                => 'Total Bases (Alt Lines)',
        'batter_home_runs_alternate'                  => 'Home Runs (Alt Lines)',
        'batter_rbis_alternate'                       => 'RBIs (Alt Lines)',
        'pitcher_strikeouts_alternate'                => 'Strikeouts (Alt Lines)',
        'player_goals_alternate'                      => 'Goals (Alt Lines)',
        'player_points_alternate'                     => 'Points (Alt Lines)',
        'player_shots_on_goal_alternate'              => 'Shots on Goal (Alt Lines)',
        'player_blocked_shots_alternate'              => 'Blocked Shots (Alt Lines)',
        'player_assists_alternate'                    => 'Assists (Alt Lines)',
        'player_total_saves_alternate'                => 'Saves (Alt Lines)',
        'player_power_play_points_alternate'          => 'Power Play Pts (Alt Lines)',
    ];
}

/**
 * Reconstruct a props payload from the most recent DB snapshot for an event.
 * Used as a fallback when the API is unavailable.
 *
 * Returns null if there is no history data for this event.
 *
 * The returned payload includes:
 *   'stale'        => true
 *   'last_updated' => UTC datetime string of the most recent snapshot row
 *
 * @param string $sport
 * @param string $event_id
 * @return array|null
 */
function statsight_props2_from_history( string $sport, string $event_id ): ?array {
    global $wpdb;

    $table = $wpdb->prefix . 'statsight_odds_history';

    // Pull the single most-recent snapshot row per (market_key, player, line, book_key).
    // JOIN against a pre-aggregated max subquery — avoids a correlated subquery scan per row.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT h.market_key, h.player, h.line, h.book_key,
                    h.over_odds, h.under_odds, h.recorded_at
             FROM {$table} h
             INNER JOIN (
                 SELECT market_key, player, line, book_key, MAX(recorded_at) AS max_recorded
                 FROM {$table}
                 WHERE event_id = %s
                 GROUP BY market_key, player, line, book_key
             ) latest
               ON  latest.market_key   = h.market_key
               AND latest.player       = h.player
               AND latest.line         = h.line
               AND latest.book_key     = h.book_key
               AND latest.max_recorded = h.recorded_at
             WHERE h.event_id = %s
             ORDER BY h.market_key, h.player, h.line, h.book_key",
            $event_id,
            $event_id
        ),
        ARRAY_A
    );

    if ( empty( $rows ) ) {
        return null;
    }

    // Rebuild the props structure from the snapshot rows.
    $props       = [];
    $books_seen  = [];
    $last_updated = '';

    foreach ( $rows as $row ) {
        $mk    = $row['market_key'];
        $player = $row['player'];
        $line  = $row['line'];
        $bk    = $row['book_key'];

        $props[ $mk ][ $player ][ $line ][ $bk ] = [
            'over'  => $row['over_odds']  !== null ? (int) $row['over_odds']  : null,
            'under' => $row['under_odds'] !== null ? (int) $row['under_odds'] : null,
        ];

        $books_seen[ $bk ] = $bk; // title not stored in history; use key as fallback

        if ( $row['recorded_at'] > $last_updated ) {
            $last_updated = $row['recorded_at'];
        }
    }

    // Merge player_goal_scorer_anytime history rows into player_goals at 0.5.
    // Snapshot rows were written with market_key='player_goal_scorer_anytime' before
    // the normalization was moved into the fetch pipeline.
    if ( isset( $props['player_goal_scorer_anytime'] ) ) {
        foreach ( $props['player_goal_scorer_anytime'] as $player => $lines ) {
            $sources = array_filter( [ $lines['yn'] ?? [], $lines['0.5'] ?? [] ] );
            foreach ( $sources as $bk_data ) {
                foreach ( $bk_data as $bk => $odds ) {
                    if ( ! isset( $props['player_goals'][ $player ]['0.5'][ $bk ] ) ) {
                        $props['player_goals'][ $player ]['0.5'][ $bk ] = $odds;
                    }
                }
            }
        }
        unset( $props['player_goal_scorer_anytime'] );
    }

    // Promote yn to 0.5 for threshold markets, then sort and normalize line keys.
    $yn_keep = [ 'h2h', 'player_double_double', 'player_triple_double' ];
    foreach ( $props as $mk => &$players ) {
        if ( ! in_array( $mk, $yn_keep, true ) ) {
            foreach ( $players as $player => &$lines ) {
                if ( isset( $lines['yn'] ) ) {
                    foreach ( $lines['yn'] as $bk => $odds ) {
                        if ( ! isset( $lines['0.5'][ $bk ] ) ) {
                            $lines['0.5'][ $bk ] = $odds;
                        }
                    }
                    unset( $lines['yn'] );
                }
            }
            unset( $lines );
        }
    }
    unset( $players );

    // Convert integer player prop lines to half-ball equivalents and normalize keys.
    // Integer lines mean "X or more" (e.g. FanDuel "2+" = Over 1.5). Excludes genuine
    // yes/no markets and game-level markets where integer lines are real thresholds.
    $yn_keep_2 = [ 'h2h', 'player_double_double', 'player_triple_double', 'totals', 'spreads', 'alternate_totals', 'alternate_spreads' ];
    foreach ( $props as $mk => &$players ) {
        foreach ( $players as $player => &$lines ) {
            $sorted = [];
            uksort( $lines, fn( $a, $b ) => (float) $a <=> (float) $b );
            foreach ( $lines as $lk => $bk_data ) {
                if ( $lk === 'yn' ) {
                    $sorted[ $lk ] = $bk_data;
                    continue;
                }
                $val = (float) $lk;
                // Convert integer lines to half-ball for player prop markets.
                if ( ! in_array( $mk, $yn_keep_2, true ) && $val > 0 && floor( $val ) === $val ) {
                    $val -= 0.5;
                }
                $key = number_format( $val, 1, '.', '' );
                // Merge into existing entry if the half-ball key already exists.
                if ( isset( $sorted[ $key ] ) ) {
                    foreach ( $bk_data as $bk => $odds ) {
                        if ( ! isset( $sorted[ $key ][ $bk ] ) ) {
                            $sorted[ $key ][ $bk ] = $odds;
                        }
                    }
                } else {
                    $sorted[ $key ] = $bk_data;
                }
            }
            $lines = $sorted;
        }
    }
    unset( $players, $lines );

    // Helper: convert American odds to implied probability (higher = more likely).
    $implied_prob = static function ( int $odds ): float {
        return $odds < 0 ? ( -$odds / ( -$odds + 100 ) ) : ( 100 / ( $odds + 100 ) );
    };

    // Helper: best (highest) implied probability across all books for a player's 0.5 line.
    $best_prob = static function ( array $lines ) use ( $implied_prob ): float {
        $line_data = $lines['0.5'] ?? $lines['yn'] ?? null;
        if ( ! $line_data ) return 0.0;
        $best = 0.0;
        foreach ( $line_data as $bk_odds ) {
            $over = $bk_odds['over'] ?? null;
            if ( $over !== null ) {
                $p = $implied_prob( (int) $over );
                if ( $p > $best ) $best = $p;
            }
        }
        return $best;
    };

    foreach ( $props as $mk => &$players ) {
        // Detect whether this market is a binary (0.5-only) market.
        $is_binary = true;
        foreach ( $players as $lines ) {
            $numeric = array_filter( array_keys( $lines ), fn( $k ) => $k !== 'yn' && $k !== '0.5' );
            if ( ! empty( $numeric ) ) { $is_binary = false; break; }
        }

        if ( $is_binary ) {
            // Binary markets (anytime scorer, anytime TD, etc.): sort most-likely first.
            uksort( $players, function ( $a, $b ) use ( $players, $best_prob ) {
                return $best_prob( $players[ $b ] ) <=> $best_prob( $players[ $a ] );
            } );
        } else {
            // Numeric line markets: sort by highest available line descending.
            uksort( $players, function ( $a, $b ) use ( $players ) {
                $keys_a = array_filter( array_keys( $players[ $a ] ), fn( $k ) => $k !== 'yn' );
                $keys_b = array_filter( array_keys( $players[ $b ] ), fn( $k ) => $k !== 'yn' );
                $max_a  = ! empty( $keys_a ) ? (float) end( $keys_a ) : -INF;
                $max_b  = ! empty( $keys_b ) ? (float) end( $keys_b ) : -INF;
                return $max_b <=> $max_a;
            } );
        }
    }
    unset( $players );

    $categories    = statsight_prop_categories( $sport );
    $market_labels = statsight_market_labels();

    $fetch_only_hist = [ 'player_goal_scorer_anytime' ];
    foreach ( $categories as &$cat ) {
        $cat['markets'] = array_values(
            array_filter( $cat['markets'], fn( string $m ) =>
                ! str_ends_with( $m, '_alternate' ) && ! in_array( $m, $fetch_only_hist, true )
            )
        );
    }
    unset( $cat );

    $book_titles  = statsight_get_book_labels();
    $book_order   = statsight_get_book_order();
    $sorted_books = [];
    foreach ( $book_order as $bk ) {
        if ( isset( $books_seen[ $bk ] ) ) {
            $sorted_books[ $bk ] = $book_titles[ $bk ] ?? $bk;
        }
    }
    foreach ( $books_seen as $bk => $title ) {
        if ( ! isset( $sorted_books[ $bk ] ) ) {
            $sorted_books[ $bk ] = $book_titles[ $bk ] ?? $bk;
        }
    }

    // Compute default lines using the same consensus logic as the live fetch.
    $hist_defaults = [];
    foreach ( $props as $mk => $mk_players ) {
        foreach ( $mk_players as $player => $lines ) {
            $best_line         = null;
            $best_book_count   = 0;
            $best_distance     = PHP_FLOAT_MAX;
            $best_line_1bk     = null;
            $best_distance_1bk = PHP_FLOAT_MAX;

            foreach ( $lines as $lk => $book_data ) {
                if ( $lk === 'yn' ) continue;
                $over_prices = [];
                foreach ( $book_data as $bk => $sides ) {
                    if ( isset( $sides['over'] ) && $sides['over'] !== null ) {
                        $over_prices[] = (int) $sides['over'];
                    }
                }
                if ( empty( $over_prices ) ) continue;

                $avg_over   = array_sum( $over_prices ) / count( $over_prices );
                $distance   = abs( $avg_over - ( -110 ) );
                $book_count = count( $over_prices );

                if ( $distance < $best_distance_1bk ) {
                    $best_distance_1bk = $distance;
                    $best_line_1bk     = $lk;
                }

                if ( $lk !== '0.5' && ( $avg_over < -500 || $avg_over > 500 ) ) continue;

                if (
                    $book_count > $best_book_count ||
                    ( $book_count === $best_book_count && $distance < $best_distance )
                ) {
                    $best_book_count = $book_count;
                    $best_distance   = $distance;
                    $best_line       = $lk;
                }
            }

            $chosen = $best_line ?? $best_line_1bk;
            if ( $chosen !== null ) {
                // For player_goals, prefer 0.5 when it has any coverage.
                if ( $mk === 'player_goals' && $chosen !== '0.5' && isset( $lines['0.5'] ) ) {
                    foreach ( $lines['0.5'] as $bk => $sides ) {
                        if ( isset( $sides['over'] ) && $sides['over'] !== null ) {
                            $chosen = '0.5';
                            break;
                        }
                    }
                }
                $hist_defaults[ $mk ][ $player ] = $chosen;
            }
        }
    }

    return [
        'categories'    => $categories,
        'market_labels' => $market_labels,
        'books'         => $sorted_books,
        'props'         => $props,
        'default_lines' => $hist_defaults,
        'stale'         => true,
        'last_updated'  => $last_updated,
    ];
}

/**
 * Fetch props for one event from The Odds API, normalise, write a history
 * snapshot, and store the result in the transient cache.
 *
 * Returns the cached payload array on success, or WP_Error on failure.
 * Safe to call from both the AJAX handler and the cron job.
 *
 * @param string $sport
 * @param string $event_id
 * @return array|WP_Error
 */
function statsight_fetch_and_cache_props( string $sport, string $event_id, array $live_stats = [] ): array|WP_Error {
    if ( ! defined( 'THE_ODDS_API_KEY' ) || empty( THE_ODDS_API_KEY ) ) {
        return statsight_props2_from_history( $sport, $event_id )
            ?? new WP_Error( 'no_api_key', 'API key not configured.' );
    }

    // Collect every market key across all categories for this sport.
    $categories  = statsight_prop_categories( $sport );
    $all_markets = [];
    foreach ( $categories as $cat ) {
        foreach ( $cat['markets'] as $m ) {
            $all_markets[] = $m;
        }
    }
    // Always fetch scorer markets so they can be merged into player_goals at 0.5.
    $fetch_extra = [ 'player_goal_scorer_anytime', 'player_anytime_td' ];
    $all_markets = array_unique( array_merge( $all_markets, $fetch_extra ) );

    $url = 'https://api.the-odds-api.com/v4/sports/' . $sport . '/events/' . $event_id . '/odds?' . http_build_query( [
        'apiKey'     => THE_ODDS_API_KEY,
        'regions'    => 'us',
        'markets'    => implode( ',', $all_markets ),
        'dateFormat' => 'iso',
        'oddsFormat' => 'american',
    ] );

    $response = wp_remote_get( $url, [ 'timeout' => 20 ] );

    if ( is_wp_error( $response ) ) {
        return statsight_props2_from_history( $sport, $event_id )
            ?? $response;
    }

    statsight_record_quota( $response );

    $status = wp_remote_retrieve_response_code( $response );
    $body   = wp_remote_retrieve_body( $response );

    if ( $status !== 200 ) {
        return statsight_props2_from_history( $sport, $event_id )
            ?? new WP_Error( 'api_error', "API returned HTTP {$status}.", $body );
    }

    $data = json_decode( $body, true );


    if ( ! is_array( $data ) ) {
        return statsight_props2_from_history( $sport, $event_id )
            ?? new WP_Error( 'parse_error', 'Could not parse API response.' );
    }



    // Normalise into: market_key -> player -> line_value -> book_key -> { over, under }
    $props              = [];
    $default_lines      = [];
    $books_seen         = [];
    $market_labels      = statsight_market_labels();
    $line_seed_priority = [ 'fanduel', 'draftkings', 'caesars', 'betmgm', 'bet365', 'fanatics' ];

    // For game-level markets (totals, spreads) with no outcome description,
    // use the matchup name as the "player" key.
    $matchup_label = trim( ( $data['away_team'] ?? '' ) . ' vs ' . ( $data['home_team'] ?? '' ) );

    // Markets where the outcome name is Over/Under with no description field.
    $game_level_markets = [ 'totals', 'spreads', 'alternate_totals', 'alternate_spreads' ];

    foreach ( $data['bookmakers'] ?? [] as $bookmaker ) {
        $book_key                = $bookmaker['key'];
        $books_seen[ $book_key ] = $bookmaker['title'];

        foreach ( $bookmaker['markets'] ?? [] as $market ) {
            $market_key  = $market['key'];
            $is_standard = ! str_ends_with( $market_key, '_alternate' );

            foreach ( $market['outcomes'] ?? [] as $outcome ) {
                $player = $outcome['description'] ?? '';
                $name   = $outcome['name']        ?? '';
                $price  = $outcome['price']        ?? null;
                $point  = $outcome['point']        ?? null;

                if ( $price === null ) continue;

                // h2h markets have no description — the outcome name IS the fighter/team.
                // Represent as a yn line so the front end renders a single odds column.
                $is_h2h = ( $market_key === 'h2h' );
                if ( $is_h2h ) {
                    $player   = $name;
                    $line_key = 'yn';
                    if ( empty( $player ) ) continue;
                    if ( ! isset( $props[ $market_key ][ $player ][ $line_key ][ $book_key ] ) ) {
                        $props[ $market_key ][ $player ][ $line_key ][ $book_key ] = [ 'over' => null, 'under' => null ];
                    }
                    $props[ $market_key ][ $player ][ $line_key ][ $book_key ]['over'] = $price;
                    continue;
                }

                // Scorer markets (goal scorer anytime/first/last, anytime TD, etc.) also put
                // the player name in 'name' with no 'description' and no Over/Under outcome.
                // Each outcome row IS the player's odds — treat as yn / over.
                static $scorer_name_markets = [
                    'player_goal_scorer_anytime', 'player_goal_scorer_first', 'player_goal_scorer_last',
                    'player_first_goal_scorer', 'player_last_goal_scorer',
                    'player_anytime_td', 'player_1st_td', 'player_last_td',
                ];
                if ( in_array( $market_key, $scorer_name_markets, true ) && empty( $player ) ) {
                    $player   = $name;
                    // Some books (e.g. FanDuel) send a point value (0.5) for scorer markets.
                    // Preserve it so the odds merge correctly with player_goals at 0.5.
                    $line_key = $point !== null ? (string) (float) $point : 'yn';
                    if ( empty( $player ) ) continue;
                    if ( ! isset( $props[ $market_key ][ $player ][ $line_key ][ $book_key ] ) ) {
                        $props[ $market_key ][ $player ][ $line_key ][ $book_key ] = [ 'over' => null, 'under' => null ];
                    }
                    $props[ $market_key ][ $player ][ $line_key ][ $book_key ]['over'] = $price;
                    continue;
                }

                // Game-level markets (totals, spreads) have no description — use the matchup label.
                if ( in_array( $market_key, $game_level_markets, true ) && empty( $player ) ) {
                    $player = $matchup_label ?: 'Fight Total';
                }

                if ( empty( $player ) ) continue;

                // Player prop integer lines mean "X or more" (e.g. FanDuel "2+" shots).
                // Convert to the equivalent half-ball line (2 → 1.5) so they consolidate
                // with books that post Over 1.5. Game-level markets (totals, spreads) are
                // excluded — their integer lines are genuine over/under thresholds.
                $is_player_prop = ! in_array( $market_key, $game_level_markets, true ) && ! $is_h2h;
                if ( $point !== null && $is_player_prop && (float) $point > 0 && floor( (float) $point ) === (float) $point ) {
                    $point = (float) $point - 0.5;
                }

                $line_key = $point !== null ? (string) $point : 'yn';

                if ( ! isset( $props[ $market_key ][ $player ][ $line_key ][ $book_key ] ) ) {
                    $props[ $market_key ][ $player ][ $line_key ][ $book_key ] = [
                        'over'  => null,
                        'under' => null,
                    ];
                }

                $side = strtolower( $name );
                if ( in_array( $side, [ 'over', 'yes' ], true ) ) {
                    $props[ $market_key ][ $player ][ $line_key ][ $book_key ]['over'] = $price;
                } elseif ( in_array( $side, [ 'under', 'no' ], true ) ) {
                    $props[ $market_key ][ $player ][ $line_key ][ $book_key ]['under'] = $price;
                }

                if ( $is_standard && $line_key !== 'yn' ) {
                    $existing_book     = $default_lines[ $market_key ][ $player ]['book'] ?? null;
                    $existing_priority = $existing_book !== null
                        ? (int) array_search( $existing_book, $line_seed_priority, true )
                        : PHP_INT_MAX;
                    $this_priority     = array_search( $book_key, $line_seed_priority, true );
                    $this_priority     = $this_priority !== false ? (int) $this_priority : PHP_INT_MAX;

                    if ( $this_priority < $existing_priority ) {
                        $default_lines[ $market_key ][ $player ] = [
                            'line' => $line_key,
                            'book' => $book_key,
                        ];
                    }
                }
            }
        }
    }

    // Merge alternate markets into their standard counterpart.
    foreach ( array_keys( $props ) as $market_key ) {
        if ( ! str_ends_with( $market_key, '_alternate' ) ) {
            continue;
        }
        $base_key = substr( $market_key, 0, -strlen( '_alternate' ) );

        foreach ( $props[ $market_key ] as $player => $lines ) {
            foreach ( $lines as $line_key => $book_data ) {
                if ( ! isset( $props[ $base_key ][ $player ][ $line_key ] ) ) {
                    $props[ $base_key ][ $player ][ $line_key ] = $book_data;
                } else {
                    foreach ( $book_data as $bk => $odds ) {
                        if ( ! isset( $props[ $base_key ][ $player ][ $line_key ][ $bk ] ) ) {
                            $props[ $base_key ][ $player ][ $line_key ][ $bk ] = $odds;
                        }
                    }
                }
            }
        }

        unset( $props[ $market_key ] );
    }

    // Merge player_goal_scorer_anytime into player_goals at line 0.5.
    // Both represent "will this player score a goal?" — just posted under different
    // market keys by different books. Merging them lets the table cross-compare odds.
    if ( isset( $props['player_goal_scorer_anytime'] ) ) {
        foreach ( $props['player_goal_scorer_anytime'] as $player => $lines ) {
            // scorer_anytime outcomes land on 'yn' (most books) or '0.5' (e.g. FanDuel).
            // Merge both into player_goals at '0.5' so all books are represented.
            $sources = array_filter( [ $lines['yn'] ?? [], $lines['0.5'] ?? [] ] );
            foreach ( $sources as $bk_data ) {
                foreach ( $bk_data as $bk => $odds ) {
                    if ( ! isset( $props['player_goals'][ $player ]['0.5'][ $bk ] ) ) {
                        $props['player_goals'][ $player ]['0.5'][ $bk ] = $odds;
                    }
                }
            }
        }
        unset( $props['player_goal_scorer_anytime'] );
    }

    // Sort lines numerically ascending per player.
    foreach ( $props as $market_key => &$players ) {
        foreach ( $players as $player => &$lines ) {
            uksort( $lines, fn( $a, $b ) => (float) $a <=> (float) $b );
        }
    }
    unset( $players, $lines );

    // Sort players: binary markets by most-likely first, numeric markets by highest line.
    $implied_prob_fn = static function ( int $odds ): float {
        return $odds < 0 ? ( -$odds / ( -$odds + 100 ) ) : ( 100 / ( $odds + 100 ) );
    };
    $best_prob_fn = static function ( array $lines ) use ( $implied_prob_fn ): float {
        $line_data = $lines['0.5'] ?? $lines['yn'] ?? null;
        if ( ! $line_data ) return 0.0;
        $best = 0.0;
        foreach ( $line_data as $bk_odds ) {
            $over = $bk_odds['over'] ?? null;
            if ( $over !== null ) {
                $p = $implied_prob_fn( (int) $over );
                if ( $p > $best ) $best = $p;
            }
        }
        return $best;
    };

    foreach ( $props as $market_key => &$players ) {
        $is_binary = true;
        foreach ( $players as $lines ) {
            $numeric = array_filter( array_keys( $lines ), fn( $k ) => $k !== 'yn' && $k !== '0.5' );
            if ( ! empty( $numeric ) ) { $is_binary = false; break; }
        }

        if ( $is_binary ) {
            uksort( $players, function ( $a, $b ) use ( $players, $best_prob_fn ) {
                return $best_prob_fn( $players[ $b ] ) <=> $best_prob_fn( $players[ $a ] );
            } );
        } else {
            uksort( $players, function ( $a, $b ) use ( $players ) {
                $keys_a = array_filter( array_keys( $players[ $a ] ), fn( $k ) => $k !== 'yn' );
                $keys_b = array_filter( array_keys( $players[ $b ] ), fn( $k ) => $k !== 'yn' );
                $max_a  = ! empty( $keys_a ) ? (float) end( $keys_a ) : -INF;
                $max_b  = ! empty( $keys_b ) ? (float) end( $keys_b ) : -INF;
                return $max_b <=> $max_a;
            } );
        }
    }
    unset( $players );

    // Strip alternate keys and fetch-only markets (merged during normalization) from
    // display categories — they have no standalone rows in the UI.
    $fetch_only_markets = [ 'player_goal_scorer_anytime' ];
    foreach ( $categories as $cat_key => &$cat ) {
        $cat['markets'] = array_values(
            array_filter( $cat['markets'], fn( string $m ) =>
                ! str_ends_with( $m, '_alternate' ) && ! in_array( $m, $fetch_only_markets, true )
            )
        );
    }
    unset( $cat );

    // Preferred book display order with human-readable titles.
    $book_titles  = statsight_get_book_labels();
    $book_order   = statsight_get_book_order();
    $sorted_books = [];
    foreach ( $book_order as $bk ) {
        if ( isset( $books_seen[ $bk ] ) ) {
            $sorted_books[ $bk ] = $book_titles[ $bk ] ?? $books_seen[ $bk ];
        }
    }
    foreach ( $books_seen as $bk => $title ) {
        if ( ! isset( $sorted_books[ $bk ] ) ) {
            $sorted_books[ $bk ] = $book_titles[ $bk ] ?? $title;
        }
    }

    // Promote yn to 0.5 for threshold-style markets (e.g. "1+ shots", "anytime scorer").
    // These are binary props where yn means "Over 0.5" — normalising to 0.5 lets books
    // that post a point value and books that don't appear in the same stepper row.
    // Genuine yes/no markets (no numeric threshold) keep yn so they render without a stepper.
    $yn_keep_markets = [ 'h2h', 'player_double_double', 'player_triple_double' ];
    foreach ( $props as $market_key => &$players ) {
        if ( in_array( $market_key, $yn_keep_markets, true ) ) continue;
        foreach ( $players as $player => &$lines ) {
            if ( ! isset( $lines['yn'] ) ) continue;
            foreach ( $lines['yn'] as $bk => $odds ) {
                if ( ! isset( $lines['0.5'][ $bk ] ) ) {
                    $lines['0.5'][ $bk ] = $odds;
                }
            }
            unset( $lines['yn'] );
        }
        unset( $lines );
    }
    unset( $players );

    // Rebuild default_lines: pick the consensus line — the one priced by the most
    // sportsbooks. Use closest average over odds to -110 as a tiebreaker when book
    // counts are equal. Lines with implausible odds (worse than ±500) are excluded
    // from the primary selection so an outlier line at +2000 can't win on count alone.
    // Fall back to the best single-book line (no odds filter) when coverage is thin.
    $flat_defaults = [];
    foreach ( $props as $market_key => $players ) {
        foreach ( $players as $player => $lines ) {
            $best_line          = null;
            $best_book_count    = 0;
            $best_distance      = PHP_FLOAT_MAX;
            $best_line_1bk      = null;
            $best_distance_1bk  = PHP_FLOAT_MAX;

            foreach ( $lines as $line_key => $book_data ) {
                if ( $line_key === 'yn' ) continue;

                // Collect all over odds posted for this line across books.
                $over_prices = [];
                foreach ( $book_data as $bk => $sides ) {
                    if ( isset( $sides['over'] ) && $sides['over'] !== null ) {
                        $over_prices[] = (int) $sides['over'];
                    }
                }

                if ( empty( $over_prices ) ) continue;

                $avg_over    = array_sum( $over_prices ) / count( $over_prices );
                $distance    = abs( $avg_over - ( -110 ) );
                $book_count  = count( $over_prices );

                // Track best single-book line as unconditional fallback.
                if ( $distance < $best_distance_1bk ) {
                    $best_distance_1bk = $distance;
                    $best_line_1bk     = $line_key;
                }

                // Exclude lines with implausible average odds (e.g. +2000 / -2000).
                // These are specialty markets (e.g. "hits O/U 2.5") that a handful of
                // books post but that are clearly not the consensus market line.
                // Exception: never filter out line 0.5 — it's the primary market for
                // goal scorer / anytime TD props which legitimately have long odds.
                if ( $line_key !== '0.5' && ( $avg_over < -500 || $avg_over > 500 ) ) continue;

                // Primary signal: most books pricing this line.
                // Tiebreaker: average over odds closest to -110 (most balanced).
                if (
                    $book_count > $best_book_count ||
                    ( $book_count === $best_book_count && $distance < $best_distance )
                ) {
                    $best_book_count = $book_count;
                    $best_distance   = $distance;
                    $best_line       = $line_key;
                }
            }

            $chosen = $best_line ?? $best_line_1bk;
            if ( $chosen !== null ) {
                // For player_goals, always prefer 0.5 when it has any coverage — it's the
                // primary "will this player score a goal?" line, same question as anytime scorer.
                if ( $market_key === 'player_goals' && $chosen !== '0.5' && isset( $lines['0.5'] ) ) {
                    $has_05_coverage = false;
                    foreach ( $lines['0.5'] as $bk => $sides ) {
                        if ( isset( $sides['over'] ) && $sides['over'] !== null ) {
                            $has_05_coverage = true;
                            break;
                        }
                    }
                    if ( $has_05_coverage ) {
                        $chosen = '0.5';
                    }
                }
                $flat_defaults[ $market_key ][ $player ] = $chosen;
            }
        }
    }

    // Sort line keys numerically and normalize integer keys to "X.5"-style decimal
    // strings (e.g. "1" → "1.0") so json_encode quotes them. Without this, PHP
    // json_encode outputs integer-looking keys without quotes, and V8 hoists all
    // unquoted integer keys to the front of the parsed object, breaking sort order.
    foreach ( $props as $mk => &$mk_players ) {
        foreach ( $mk_players as $player => &$player_lines ) {
            $sorted = [];
            uksort( $player_lines, fn( $a, $b ) => (float) $a <=> (float) $b );
            foreach ( $player_lines as $lk => $bk_data ) {
                if ( $lk === 'yn' ) {
                    $sorted[ $lk ] = $bk_data;
                } else {
                    $normalized = number_format( (float) $lk, 1, '.', '' );
                    $sorted[ $normalized ] = $bk_data;
                }
            }
            $player_lines = $sorted;
        }
        unset( $player_lines );
    }
    unset( $mk_players );

    $payload = [
        'categories'    => $categories,
        'market_labels' => $market_labels,
        'books'         => $sorted_books,
        'props'         => $props,
        'default_lines' => $flat_defaults,
        'fetched_at'    => time(),
    ];

    // Write history snapshot then warm the cache.
    statsight_record_odds_snapshot( $event_id, $props, $data['commence_time'] ?? null, $live_stats );
    set_transient( 'statsight_props2_' . $event_id, $payload, 1 * MINUTE_IN_SECONDS );

    return $payload;
}

/**
 * AJAX handler — serve props for a specific event.
 * Reads from cache when warm; falls back to a live fetch only on cache miss.
 */
/**
 * Filters a full props payload down to what the free tier is allowed to see:
 * - Only the first sportsbook column
 *
 * We trim the 'books' map to a single entry so the JS only renders one column,
 * but leave the full props data intact so every market can still find its data
 * under whichever book carries it (avoids markets like 3-pointers disappearing
 * when the globally-first book doesn't offer that line).
 */
function statsight_filter_props_for_free( array $payload ): array {
    $all_books        = $payload['books'] ?? [];
    $allowed_books    = array_slice( array_keys( $all_books ), 0, 3 );
    $payload['books'] = array_intersect_key( $all_books, array_flip( $allowed_books ) );
    $payload['plan']  = 'free';

    // Strip non-allowed books from the props data so the full payload is not
    // accessible via XHR inspection.
    foreach ( $payload['props'] ?? [] as $market_key => &$players ) {
        foreach ( $players as $player => &$lines ) {
            foreach ( $lines as $line => &$bk_data ) {
                $bk_data = array_intersect_key( $bk_data, array_flip( $allowed_books ) );
            }
            unset( $bk_data );
        }
        unset( $lines );
    }
    unset( $players );

    return $payload;
}

function statsight_ajax_get_props(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );

    $sport    = isset( $_GET['sport'] )    ? sanitize_key( $_GET['sport'] )    : '';
    $event_id = isset( $_GET['event_id'] ) ? sanitize_key( $_GET['event_id'] ) : '';

    if ( empty( $sport ) || empty( $event_id ) ) {
        wp_send_json_error( [ 'message' => 'Missing sport or event_id parameter.' ], 400 );
    }

    $plan = statsight_get_user_plan();

    $cached        = get_transient( 'statsight_props2_' . $event_id );
    $is_live_poll  = isset( $_GET['live_poll'] ) && $_GET['live_poll'] === '1';
    $cache_age     = $cached ? ( time() - (int) ( $cached['fetched_at'] ?? 0 ) ) : PHP_INT_MAX;
    $cache_is_fresh = $cache_age < 60; // less than 60 seconds old

    if ( false !== $cached && ( ! $is_live_poll || $cache_is_fresh ) ) {
        if ( $plan === 'free' ) {
            $cached = statsight_filter_props_for_free( $cached );
        }
        wp_send_json_success( $cached );
    }

    $fetch_enabled = get_field( 'odds_data_fetch', 'option' );
    if ( $fetch_enabled !== false && ! $fetch_enabled ) {
        $history = statsight_props2_from_history( $sport, $event_id );
        if ( $history ) {
            if ( $plan === 'free' ) {
                $history = statsight_filter_props_for_free( $history );
            }
            wp_send_json_success( $history );
        }
        wp_send_json_error( [ 'message' => 'Data fetching is disabled and no cached data is available.' ], 503 );
    }

    // Serve stale history immediately and refresh in the background.
    // This keeps the UI responsive — fresh data arrives on the next live poll.
    $history = statsight_props2_from_history( $sport, $event_id );
    if ( $history ) {
        wp_schedule_single_event( time(), 'statsight_bg_refresh_props', [ $sport, $event_id ] );
        spawn_cron();
        if ( $plan === 'free' ) {
            $history = statsight_filter_props_for_free( $history );
        }
        wp_send_json_success( $history );
    }

    // No history at all — must block and fetch live.
    $payload = statsight_fetch_and_cache_props( $sport, $event_id );

    if ( is_wp_error( $payload ) ) {
        wp_send_json_error( [ 'message' => $payload->get_error_message() ], 502 );
    }

    if ( $plan === 'free' ) {
        $payload = statsight_filter_props_for_free( $payload );
    }

    wp_send_json_success( $payload );
}
add_action( 'wp_ajax_statsight_get_props',        'statsight_ajax_get_props' );
add_action( 'wp_ajax_nopriv_statsight_get_props', 'statsight_ajax_get_props' );

/**
 * Persist a snapshot of all current odds into the history table.
 * Called once per fresh API fetch (not from cache).
 *
 * @param string $event_id
 * @param array  $props      Shape: market_key -> player -> line_key -> book_key -> { over, under }
 * @param string|null $commence_time
 * @param array  $live_stats Optional. Shape: player_name -> market_key -> float (current in-game stat value).
 */
function statsight_record_odds_snapshot( string $event_id, array $props, ?string $commence_time = null, array $live_stats = [] ): void {
    global $wpdb;

    $table  = $wpdb->prefix . 'statsight_odds_history';
    $now    = current_time( 'mysql', true ); // UTC
    $now_ts = strtotime( $now );

    // Avoid duplicate snapshots: skip if a snapshot was already recorded for
    // this event within the last 5 minutes (e.g. concurrent cache misses).
    $last = $wpdb->get_var( $wpdb->prepare(
        "SELECT recorded_at FROM {$table} WHERE event_id = %s ORDER BY recorded_at DESC LIMIT 1",
        $event_id
    ) );
    if ( $last && ( $now_ts - strtotime( $last ) ) < 5 * MINUTE_IN_SECONDS ) {
        return;
    }

    // Batch inserts — collect each row as its own array for clean chunking.
    $rows = [];

    foreach ( $props as $market_key => $players ) {
        if ( str_ends_with( $market_key, '_alternate' ) ) {
            continue; // alternates are merged into base; skip to avoid duplication
        }
        foreach ( $players as $player => $lines ) {
            // Look up the live stat for this player+market if available.
            $stat_val = isset( $live_stats[ $player ][ $market_key ] )
                ? (int) round( $live_stats[ $player ][ $market_key ] )
                : null;

            foreach ( $lines as $line_key => $books ) {
                foreach ( $books as $book_key => $odds ) {
                    $rows[] = [
                        'event_id'   => $event_id,
                        'market_key' => $market_key,
                        'player'     => $player,
                        'line_key'   => $line_key,
                        'book_key'   => $book_key,
                        'over_odds'  => isset( $odds['over'] )   ? (int) $odds['over']   : null,
                        'under_odds' => isset( $odds['under'] )  ? (int) $odds['under']  : null,
                        'stat_value' => $stat_val,
                        'now'        => $now,
                    ];
                }
            }
        }
    }

    if ( empty( $rows ) ) {
        return;
    }

    // Chunk to avoid hitting max_allowed_packet on large payloads.
    foreach ( array_chunk( $rows, 200 ) as $chunk ) {
        $placeholders = [];
        $values       = [];

        foreach ( $chunk as $row ) {
            // Use NULL literal for missing values to avoid wpdb->prepare() deprecation notices.
            $over_ph  = $row['over_odds']  !== null ? '%d'  : 'NULL';
            $under_ph = $row['under_odds'] !== null ? '%d'  : 'NULL';
            $stat_ph  = $row['stat_value'] !== null ? '%s'  : 'NULL';
            $placeholders[] = "(%s, %s, %s, %s, %s, {$over_ph}, {$under_ph}, {$stat_ph}, %s)";
            $values[]       = $row['event_id'];
            $values[]       = $row['market_key'];
            $values[]       = $row['player'];
            $values[]       = $row['line_key'];
            $values[]       = $row['book_key'];
            if ( $row['over_odds']  !== null ) $values[] = $row['over_odds'];
            if ( $row['under_odds'] !== null ) $values[] = $row['under_odds'];
            if ( $row['stat_value'] !== null ) $values[] = $row['stat_value'];
            $values[]       = $row['now'];
        }

        $sql = "INSERT INTO {$table}
                    (event_id, market_key, player, line, book_key, over_odds, under_odds, stat_value, recorded_at)
                VALUES " . implode( ', ', $placeholders );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( $wpdb->prepare( $sql, $values ) );
    }

    // After writing a snapshot, attempt CLV resolution for this event.
    statsight_resolve_clv( $event_id );
}


/**
 * Find all distinct event_ids with pending CLV and resolve each one.
 * Called from the 1-minute notification cron so CLV is written promptly
 * even if the odds snapshot cron has stopped fetching a game's props.
 */
function statsight_resolve_clv_all_pending(): void {
    global $wpdb;

    $wl_table = $wpdb->prefix . 'statsight_watchlist';
    $now      = current_time( 'mysql', true );

    $event_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT event_id
             FROM {$wl_table}
             WHERE clv             IS NULL
               AND game_start_time IS NOT NULL
               AND game_start_time <= %s",
            $now
        )
    );

    foreach ( $event_ids as $event_id ) {
        statsight_resolve_clv( $event_id );
    }
}

/**
 * Calculate and store CLV for all watchlist props on a given event.
 *
 * CLV = saved_odds - closing_odds (positive = beat the closing line).
 *
 * "Closing odds" = the last snapshot recorded BEFORE game_start_time.
 * This is computed retroactively so timing of the live cron doesn't matter.
 * Only runs on props where game_start_time is known and is in the past.
 * Skips props where clv is already set.
 */
function statsight_resolve_clv( string $event_id ): void {
    global $wpdb;

    $wl_table   = $wpdb->prefix . 'statsight_watchlist';
    $hist_table = $wpdb->prefix . 'statsight_odds_history';
    $now        = current_time( 'mysql', true );

    // Only process props whose game has already started and CLV isn't set yet.
    $props = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, player, market_key, line, book, direction, odds, game_start_time
             FROM {$wl_table}
             WHERE event_id       = %s
               AND clv            IS NULL
               AND game_start_time IS NOT NULL
               AND game_start_time <= %s",
            $event_id, $now
        ),
        ARRAY_A
    );

    if ( empty( $props ) ) {
        return;
    }

    foreach ( $props as $prop ) {
        $odds_col = $prop['direction'] === 'under' ? 'under_odds' : 'over_odds';

        // Last snapshot on the saved book recorded before game start = closing line.
        $closing_odds = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT {$odds_col}
                 FROM {$hist_table}
                 WHERE event_id   = %s
                   AND market_key = %s
                   AND player     = %s
                   AND line       = %s
                   AND book_key   = %s
                   AND recorded_at < %s
                   AND {$odds_col} IS NOT NULL
                 ORDER BY recorded_at DESC
                 LIMIT 1",
                $event_id, $prop['market_key'], $prop['player'],
                $prop['line'], $prop['book'], $prop['game_start_time']
            )
        );

        if ( $closing_odds === null ) {
            // Saved book pulled the line — fall back to the consensus closing line
            // (best odds across all books just before game start).
            $closing_odds = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT MAX({$odds_col})
                     FROM {$hist_table}
                     WHERE event_id   = %s
                       AND market_key = %s
                       AND player     = %s
                       AND line       = %s
                       AND recorded_at < %s
                       AND {$odds_col} IS NOT NULL",
                    $event_id, $prop['market_key'], $prop['player'],
                    $prop['line'], $prop['game_start_time']
                )
            );
        }

        if ( $closing_odds === null ) {
            continue;
        }

        $clv = (int) $prop['odds'] - (int) $closing_odds;

        $wpdb->update(
            $wl_table,
            [ 'clv' => $clv ],
            [ 'id'  => $prop['id'] ],
            [ '%d' ],
            [ '%d' ]
        );
    }
}

/**
 * AJAX handler — return odds movement history for a specific event.
 *
 * Returns the last 20 snapshots per (market_key, player, line, book_key)
 * so the frontend can draw trend arrows.
 *
 * Query params: event_id
 *
 * Response shape:
 *   {
 *     [market_key]: {
 *       [player]: {
 *         [line]: {
 *           [book_key]: [ { over, under, recorded_at }, ... ]  // oldest→newest
 *         }
 *       }
 *     }
 *   }
 */
function statsight_ajax_get_odds_history(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'pro' );

    $event_id   = isset( $_GET['event_id'] )   ? sanitize_key( $_GET['event_id'] )          : '';
    $market_key = isset( $_GET['market_key'] )  ? sanitize_text_field( $_GET['market_key'] ) : '';
    $player     = isset( $_GET['player'] )      ? sanitize_text_field( $_GET['player'] )     : '';
    $line       = isset( $_GET['line'] )        ? sanitize_text_field( $_GET['line'] )       : '';
    $book_key   = isset( $_GET['book_key'] )    ? sanitize_key( $_GET['book_key'] )          : '';
    $limit      = isset( $_GET['limit'] )       ? min( 20, max( 1, (int) $_GET['limit'] ) )  : 20;

    if ( empty( $event_id ) || empty( $market_key ) || empty( $player ) || empty( $book_key ) ) {
        wp_send_json_error( [ 'message' => 'Missing required parameters.' ], 400 );
        return;
    }

    $cache_key = 'statsight_hist2_' . md5( "{$event_id}|{$market_key}|{$player}|{$line}|{$book_key}|{$limit}" );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        wp_send_json_success( $cached );
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'statsight_odds_history';

    // Normalise the requested line to match how it's stored: strip trailing .0
    $line_norm = preg_replace( '/\.0$/', '', $line );

    // Fetch the 20 most recent snapshots for this exact player/market/line/book combo.
    // Select DESC to get newest first, then reverse so chart renders oldest→newest.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT over_odds, under_odds, stat_value, recorded_at
             FROM {$table}
             WHERE event_id   = %s
               AND market_key = %s
               AND player     = %s
               AND line       = %s
               AND book_key   = %s
             ORDER BY recorded_at DESC
             LIMIT %d",
            $event_id, $market_key, $player, $line_norm, $book_key, $limit
        ),
        ARRAY_A
    );
    $rows = array_reverse( $rows );

    // If no rows found for exact line, fall back to closest available line for this combo.
    if ( empty( $rows ) && $line_norm !== 'yn' ) {
        $closest_line = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT line FROM {$table}
                 WHERE event_id   = %s
                   AND market_key = %s
                   AND player     = %s
                   AND book_key   = %s
                   AND over_odds IS NOT NULL
                 GROUP BY line
                 ORDER BY ABS(CAST(line AS DECIMAL(8,2)) - %f) ASC
                 LIMIT 1",
                $event_id, $market_key, $player, $book_key, (float) $line_norm
            )
        );

        if ( $closest_line ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT over_odds, under_odds, stat_value, recorded_at
                     FROM {$table}
                     WHERE event_id   = %s
                       AND market_key = %s
                       AND player     = %s
                       AND line       = %s
                       AND book_key   = %s
                     ORDER BY recorded_at DESC
                     LIMIT %d",
                    $event_id, $market_key, $player, $closest_line, $book_key, $limit
                ),
                ARRAY_A
            );
            $rows = array_reverse( $rows );
        }
    }

    if ( empty( $rows ) ) {
        wp_send_json_success( [] );
        return;
    }

    $snapshots = array_map( function ( $row ) {
        return [
            'over'        => $row['over_odds']  !== null ? (int)   $row['over_odds']  : null,
            'under'       => $row['under_odds'] !== null ? (int)   $row['under_odds'] : null,
            'stat_value'  => $row['stat_value'] !== null ? (float) $row['stat_value'] : null,
            'recorded_at' => $row['recorded_at'],
        ];
    }, $rows );

    // Cache 1 min for live games; stable enough for pre-game.
    set_transient( $cache_key, $snapshots, 1 * MINUTE_IN_SECONDS );

    wp_send_json_success( $snapshots );
}
add_action( 'wp_ajax_statsight_get_odds_history',        'statsight_ajax_get_odds_history' );
add_action( 'wp_ajax_nopriv_statsight_get_odds_history', 'statsight_ajax_get_odds_history' );

/**
 * Calculate profit on a $100 bet for American odds.
 *
 * @param int|float $odds
 * @return float
 */
function statsight_payout_per_100( int|float $odds ): float {
    if ( $odds >= 0 ) {
        return (float) $odds; // e.g. +700 → $700 profit on $100 risked
    }
    return round( 10000 / abs( $odds ), 2 ); // e.g. -135 → $74.07 profit on $100 risked
}

/**
 * AJAX handler — fetch best-value props across all upcoming games for a sport.
 *
 * For each event on the next game day, fetches props and computes the edge
 * for every player+market+line as:
 *   edge = best_payout - worst_payout  (profit difference on a $100 bet)
 *
 * Returns a flat list sorted by edge descending, cached 15 minutes.
 */
function statsight_ajax_get_best_value(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'pro' );

    $sport = isset( $_GET['sport'] ) ? sanitize_key( $_GET['sport'] ) : '';

    if ( empty( $sport ) ) {
        wp_send_json_error( [ 'message' => 'Missing sport parameter.' ], 400 );
    }

    if ( ! defined( 'THE_ODDS_API_KEY' ) || empty( THE_ODDS_API_KEY ) ) {
        wp_send_json_error( [ 'message' => 'API key not configured.' ], 500 );
    }

    $et_date   = ( new DateTime( 'now', new DateTimeZone( 'America/New_York' ) ) )->format( 'Y-m-d' );
    $cache_key = 'statsight_bestvalue3_' . $sport . '_' . $et_date;
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        wp_send_json_success( $cached );
    }

    $fetch_enabled = get_field( 'odds_data_fetch', 'option' );
    if ( $fetch_enabled !== false && ! $fetch_enabled ) {
        wp_send_json_success( [ 'props' => [] ] );
    }

    // ── Step 1: get the next game day's events ──────────────────────────────
    $events_cache = get_transient( 'statsight_events_' . $sport );
    $events       = $events_cache ? array_merge( ...array_column( $events_cache['days'] ?? [], 'events' ) ) : [];

    if ( empty( $events ) ) {
        $url = 'https://api.the-odds-api.com/v4/sports/' . $sport . '/events?' . http_build_query( [
            'apiKey'           => THE_ODDS_API_KEY,
            'regions'          => 'us',
            'dateFormat'       => 'iso',
            'commenceTimeFrom' => gmdate( 'Y-m-d' ) . 'T00:00:00Z',
        ] );
        $resp = wp_remote_get( $url, [ 'timeout' => 15 ] );
        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            wp_send_json_error( [ 'message' => 'Could not fetch events.' ], 502 );
        }
        $all_events = json_decode( wp_remote_retrieve_body( $resp ), true ) ?? [];

        // Filter to earliest ET date (games straddle UTC midnight).
        $et_tz    = new DateTimeZone( 'America/New_York' );
        $earliest = null;
        foreach ( $all_events as $e ) {
            $d = ( new DateTime( $e['commence_time'] ?? 'now' ) )->setTimezone( $et_tz )->format( 'Y-m-d' );
            if ( $earliest === null || $d < $earliest ) {
                $earliest = $d;
            }
        }
        $events = array_values(
            array_filter( $all_events, function ( $e ) use ( $et_tz, $earliest ) {
                return ( new DateTime( $e['commence_time'] ?? 'now' ) )->setTimezone( $et_tz )->format( 'Y-m-d' ) === $earliest;
            } )
        );
    }

    if ( empty( $events ) ) {
        wp_send_json_success( [] );
    }

    // ── Step 2: collect all market keys for this sport ──────────────────────
    $categories  = statsight_prop_categories( $sport );
    $all_markets = [];
    foreach ( $categories as $cat ) {
        foreach ( $cat['markets'] as $m ) {
            $all_markets[] = $m;
        }
    }
    $all_markets    = array_unique( $all_markets );
    $market_labels  = statsight_market_labels();
    $book_order     = [ 'fanduel', 'draftkings', 'betmgm', 'caesars', 'bet365', 'fanatics' ];

    // ── Step 3: fetch props for each event and compute discrepancies ─────────
    $discrepancies = [];

    foreach ( $events as $event ) {
        $event_id  = $event['id']        ?? '';
        $home      = $event['home_team'] ?? '';
        $away      = $event['away_team'] ?? '';
        $time      = $event['commence_time'] ?? '';

        if ( empty( $event_id ) ) {
            continue;
        }

        // Re-use cached props if available
        $props_cache = get_transient( 'statsight_props2_' . $event_id );
        if ( false !== $props_cache ) {
            $event_props   = $props_cache['props']   ?? [];
            $event_books   = $props_cache['books']   ?? [];
            $default_lines = $props_cache['default_lines'] ?? [];
        } else {
            $url = 'https://api.the-odds-api.com/v4/sports/' . $sport . '/events/' . $event_id . '/odds?' . http_build_query( [
                'apiKey'     => THE_ODDS_API_KEY,
                'regions'    => 'us',
                'markets'    => implode( ',', $all_markets ),
                'dateFormat' => 'iso',
                'oddsFormat' => 'american',
            ] );

            $resp = wp_remote_get( $url, [ 'timeout' => 20 ] );
            if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
                continue; // skip this event silently
            }

            $raw                 = json_decode( wp_remote_retrieve_body( $resp ), true );
            $event_props         = [];
            $event_books         = [];
            $default_lines       = [];
            $line_priority       = [ 'fanduel', 'draftkings', 'caesars', 'betmgm', 'bet365', 'fanatics' ];
            $game_level_markets  = [ 'totals', 'spreads', 'alternate_totals', 'alternate_spreads' ];

            foreach ( $raw['bookmakers'] ?? [] as $bookmaker ) {
                $bk                   = $bookmaker['key'];
                $event_books[ $bk ]   = $bookmaker['title'];

                foreach ( $bookmaker['markets'] ?? [] as $market ) {
                    $mk          = $market['key'];
                    $is_standard = ! str_ends_with( $mk, '_alternate' );

                    foreach ( $market['outcomes'] ?? [] as $outcome ) {
                        $player = $outcome['description'] ?? '';
                        $name   = $outcome['name']        ?? '';
                        $price  = $outcome['price']        ?? null;
                        $point  = $outcome['point']        ?? null;

                        if ( empty( $player ) || $price === null ) continue;

                        // Same integer → half-ball conversion as main props builder.
                        $is_pp = ! in_array( $mk, $game_level_markets, true ) && $mk !== 'h2h';
                        if ( $point !== null && $is_pp && (float) $point > 0 && floor( (float) $point ) === (float) $point ) {
                            $point = (float) $point - 0.5;
                        }

                        $lk = $point !== null ? (string) $point : 'yn';

                        if ( ! isset( $event_props[ $mk ][ $player ][ $lk ][ $bk ] ) ) {
                            $event_props[ $mk ][ $player ][ $lk ][ $bk ] = [ 'over' => null, 'under' => null ];
                        }

                        $side = strtolower( $name );
                        if ( in_array( $side, [ 'over', 'yes' ], true ) ) {
                            $event_props[ $mk ][ $player ][ $lk ][ $bk ]['over'] = $price;
                        } elseif ( in_array( $side, [ 'under', 'no' ], true ) ) {
                            $event_props[ $mk ][ $player ][ $lk ][ $bk ]['under'] = $price;
                        }

                        if ( $is_standard && $lk !== 'yn' ) {
                            $existing_pri = isset( $default_lines[ $mk ][ $player ] )
                                ? (int) array_search( $default_lines[ $mk ][ $player ]['book'], $line_priority, true )
                                : PHP_INT_MAX;
                            $this_pri = array_search( $bk, $line_priority, true );
                            $this_pri = $this_pri !== false ? (int) $this_pri : PHP_INT_MAX;
                            if ( $this_pri < $existing_pri ) {
                                $default_lines[ $mk ][ $player ] = [ 'line' => $lk, 'book' => $bk ];
                            }
                        }
                    }
                }
            }

            // Merge alternates into base
            foreach ( array_keys( $event_props ) as $mk ) {
                if ( ! str_ends_with( $mk, '_alternate' ) ) continue;
                $base = substr( $mk, 0, -strlen( '_alternate' ) );
                foreach ( $event_props[ $mk ] as $player => $lines ) {
                    foreach ( $lines as $lk => $bk_data ) {
                        if ( ! isset( $event_props[ $base ][ $player ][ $lk ] ) ) {
                            $event_props[ $base ][ $player ][ $lk ] = $bk_data;
                        } else {
                            foreach ( $bk_data as $bk => $odds ) {
                                if ( ! isset( $event_props[ $base ][ $player ][ $lk ][ $bk ] ) ) {
                                    $event_props[ $base ][ $player ][ $lk ][ $bk ] = $odds;
                                }
                            }
                        }
                    }
                }
                unset( $event_props[ $mk ] );
            }

            // Merge player_goal_scorer_anytime into player_goals at 0.5 (same as main props builder).
            if ( isset( $event_props['player_goal_scorer_anytime'] ) ) {
                foreach ( $event_props['player_goal_scorer_anytime'] as $player => $lines ) {
                    $bk_data = $lines['yn'] ?? $lines['0.5'] ?? null;
                    if ( ! $bk_data ) continue;
                    foreach ( $bk_data as $bk => $odds ) {
                        if ( ! isset( $event_props['player_goals'][ $player ]['0.5'][ $bk ] ) ) {
                            $event_props['player_goals'][ $player ]['0.5'][ $bk ] = $odds;
                        }
                    }
                }
                unset( $event_props['player_goal_scorer_anytime'] );
            }

            // Promote yn to 0.5 for threshold markets (same logic as main props builder).
            $yn_keep = [ 'h2h', 'player_double_double', 'player_triple_double' ];
            foreach ( $event_props as $mk => &$ep_players ) {
                if ( in_array( $mk, $yn_keep, true ) ) continue;
                foreach ( $ep_players as $player => &$ep_lines ) {
                    if ( ! isset( $ep_lines['yn'] ) ) continue;
                    foreach ( $ep_lines['yn'] as $bk => $odds ) {
                        if ( ! isset( $ep_lines['0.5'][ $bk ] ) ) {
                            $ep_lines['0.5'][ $bk ] = $odds;
                        }
                    }
                    unset( $ep_lines['yn'] );
                }
                unset( $ep_lines );
            }
            unset( $ep_players );
        }

        // ── Compute discrepancies for this event ────────────────────────────
        foreach ( $event_props as $mk => $players ) {
            if ( str_ends_with( $mk, '_alternate' ) ) continue;

            foreach ( $players as $player => $lines ) {
                // For Yes/No markets the line key is always 'yn'.
                // For O/U markets use the recorded default (standard) line.
                $is_yes_no  = isset( $lines['yn'] );
                $default_lk = $is_yes_no
                    ? 'yn'
                    : ( $default_lines[ $mk ][ $player ]['line'] ?? null );

                if ( $default_lk === null || ! isset( $lines[ $default_lk ] ) ) continue;

                $bk_data    = $lines[ $default_lk ];
                $odds_map   = [];

                foreach ( $bk_data as $bk => $odds ) {
                    if ( $odds['over'] !== null ) {
                        $odds_map[ $bk ] = (int) $odds['over'];
                    }
                }

                if ( count( $odds_map ) < 2 ) continue; // need at least 2 books to compare

                // Higher American odds = better payout for the bettor.
                // Edge = difference between the best and worst number on the board.
                $best_odds_val  = max( $odds_map );
                $worst_odds_val = min( $odds_map );
                $edge           = $best_odds_val - $worst_odds_val;

                if ( $edge <= 0 ) continue;

                $best_bk  = array_search( $best_odds_val,  $odds_map, true );
                $worst_bk = array_search( $worst_odds_val, $odds_map, true );

                $discrepancies[] = [
                    'edge'        => $edge,
                    'ev'          => statsight_calc_ev( $odds_map ),
                    'player'      => $player,
                    'market'      => $market_labels[ $mk ] ?? $mk,
                    'market_key'  => $mk,
                    'line'        => $is_yes_no ? 'Yes' : $default_lk,
                    'matchup'     => $home . ' vs ' . $away,
                    'game_time'   => $time,
                    'event_id'    => $event_id,
                    'best_book'   => $event_books[ $best_bk ]  ?? $best_bk,
                    'best_odds'   => $bk_data[ $best_bk ]['over'],
                    'worst_book'  => $event_books[ $worst_bk ] ?? $worst_bk,
                    'worst_odds'  => $bk_data[ $worst_bk ]['over'],
                    'all_odds'    => array_map(
                        fn( $bk ) => [
                            'book' => $bk,
                            'odds' => $bk_data[ $bk ]['over'],
                        ],
                        array_keys( $bk_data )
                    ),
                    'best_book_key' => $best_bk,
                ];
            }
        }
    }

    // Sort by edge descending
    usort( $discrepancies, fn( $a, $b ) => $b['edge'] <=> $a['edge'] );

    set_transient( $cache_key, $discrepancies, 5 * MINUTE_IN_SECONDS );

    wp_send_json_success( $discrepancies );
}
add_action( 'wp_ajax_statsight_get_best_value',        'statsight_ajax_get_best_value' );
add_action( 'wp_ajax_nopriv_statsight_get_best_value', 'statsight_ajax_get_best_value' );

// ── Bet Tracker AJAX Handlers ──────────────────────────────────────────────

/**
 * Save a new bet to the tracker.
 * Accepts POST: sport, event_id, player, market_label, line, direction,
 *               book, odds, stake, matchup, game_time, notes
 */
// ── Watchlist AJAX handlers ────────────────────────────────────────────────

/**
 * Add a prop to the current user's watchlist.
 * POST: event_id, sport, player, market_key, market_label, line, direction, odds, book, matchup, all_books (JSON)
 */
function statsight_ajax_watchlist_add(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'sharp' );

    global $wpdb;

    $user_id = get_current_user_id();

    // Validate and sanitize all_books JSON array of {book, odds} objects.
    $all_books_raw = json_decode( wp_unslash( $_POST['all_books'] ?? '[]' ), true );
    $all_books     = [];
    if ( is_array( $all_books_raw ) ) {
        foreach ( $all_books_raw as $entry ) {
            if ( isset( $entry['book'], $entry['odds'] ) ) {
                $all_books[] = [
                    'book' => sanitize_text_field( $entry['book'] ),
                    'odds' => (int) $entry['odds'],
                ];
            }
        }
    }

    $event_id_raw = sanitize_text_field( $_POST['event_id'] ?? '' );
    $sport_raw    = sanitize_key( $_POST['sport'] ?? '' );

    // Look up game_start_time from the cached events list.
    // If the event isn't in the current cache the game is no longer upcoming — reject.
    $game_start_time = null;
    $events_cache    = get_transient( 'statsight_events_' . $sport_raw );
    foreach ( $events_cache['days'] ?? [] as $day ) {
        foreach ( $day['events'] ?? [] as $event ) {
            if ( ( $event['id'] ?? '' ) === $event_id_raw && ! empty( $event['commence_time'] ) ) {
                $game_start_time = gmdate( 'Y-m-d H:i:s', strtotime( $event['commence_time'] ) );
                break 2;
            }
        }
    }

    // Block saving props for games that finished more than 4 hours ago.
    if ( $game_start_time === null || strtotime( $game_start_time ) < ( time() - 4 * HOUR_IN_SECONDS ) ) {
        wp_send_json_error( [ 'message' => 'This game has already ended or is no longer available.' ], 400 );
    }

    $row = [
        'user_id'         => $user_id,
        'event_id'        => $event_id_raw,
        'sport'           => $sport_raw,
        'player'          => sanitize_text_field( $_POST['player']        ?? '' ),
        'market_key'      => sanitize_text_field( $_POST['market_key']    ?? '' ),
        'market_label'    => sanitize_text_field( $_POST['market_label']  ?? '' ),
        'line'            => sanitize_text_field( $_POST['line']          ?? '' ),
        'direction'       => in_array( $_POST['direction'] ?? 'over', [ 'over', 'under' ], true ) ? $_POST['direction'] : 'over',
        'odds'            => (int) ( $_POST['odds'] ?? 0 ),
        'book'            => sanitize_text_field( $_POST['book']          ?? '' ),
        'matchup'         => sanitize_text_field( $_POST['matchup']       ?? '' ),
        'all_books'       => wp_json_encode( $all_books ),
        'added_at'        => gmdate( 'Y-m-d H:i:s' ),
        'game_start_time' => $game_start_time,
    ];

    if ( empty( $row['player'] ) || empty( $row['market_key'] ) || empty( $row['event_id'] ) ) {
        wp_send_json_error( [ 'message' => 'Missing required fields.' ], 400 );
    }

    $table = $wpdb->prefix . 'statsight_watchlist';

    // Use INSERT IGNORE so duplicate unique-key violations are silently skipped.
    $gst_ph  = $row['game_start_time'] !== null ? '%s' : 'NULL';
    $gst_val = $row['game_start_time'] !== null ? [ $row['game_start_time'] ] : [];

    $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO {$table}
         (user_id, event_id, sport, player, market_key, market_label, line, direction, odds, book, matchup, all_books, added_at, game_start_time)
         VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s, {$gst_ph})",
        array_merge(
            [
                $row['user_id'], $row['event_id'], $row['sport'], $row['player'],
                $row['market_key'], $row['market_label'], $row['line'], $row['direction'],
                $row['odds'], $row['book'], $row['matchup'], $row['all_books'], $row['added_at'],
            ],
            $gst_val
        )
    ) );

    // If INSERT IGNORE skipped a duplicate, fetch the existing row's id.
    $id = $wpdb->insert_id ?: (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE user_id = %d AND event_id = %s AND player = %s AND market_key = %s AND direction = %s",
        $row['user_id'], $row['event_id'], $row['player'], $row['market_key'], $row['direction']
    ) );

    wp_send_json_success( [ 'id' => $id ] );
}
add_action( 'wp_ajax_statsight_watchlist_add', 'statsight_ajax_watchlist_add' );

/**
 * Remove a prop from the watchlist. POST: id
 */
function statsight_ajax_watchlist_remove(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'sharp' );

    global $wpdb;

    $user_id = get_current_user_id();
    $id      = (int) ( $_POST['id'] ?? 0 );

    if ( ! $id ) {
        wp_send_json_error( [ 'message' => 'Invalid ID.' ], 400 );
    }

    $table = $wpdb->prefix . 'statsight_watchlist';

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT result, game_start_time, added_at FROM {$table} WHERE id = %d AND user_id = %d",
        $id, $user_id
    ), ARRAY_A );

    if ( ! $row ) {
        wp_send_json_error( [ 'message' => 'Not found.' ], 404 );
    }

    // Soft-delete if the prop is already settled OR the game has already started.
    // Hard-delete only if the game hasn't started yet — those picks never count toward the record.
    $game_started = $row['game_start_time'] && strtotime( $row['game_start_time'] ) <= time();

    if ( $row['result'] !== null || $game_started ) {
        $wpdb->update(
            $table,
            [ 'deleted_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id, 'user_id' => $user_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );
    } else {
        $wpdb->delete( $table, [ 'id' => $id, 'user_id' => $user_id ], [ '%d', '%d' ] );
    }

    wp_send_json_success();
}
add_action( 'wp_ajax_statsight_watchlist_remove', 'statsight_ajax_watchlist_remove' );

/**
 * Return game status + player boxscore stats for watchlist event IDs.
 * POST: props (JSON array of {event_id, sport, matchup})
 * Returns: {
 *   statuses:  { event_id => 'live'|'final'|'pre' },
 *   boxscores: { event_id => { "Player Name" => { "PTS" => "22", ... } } }
 * }
 */
function statsight_ajax_watchlist_game_status(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'sharp' );

    $props_raw = json_decode( wp_unslash( $_POST['props'] ?? '[]' ), true );
    if ( ! is_array( $props_raw ) || empty( $props_raw ) ) {
        wp_send_json_success( [ 'statuses' => [], 'boxscores' => [] ] );
    }

    // Group by sport so we only hit each ESPN scoreboard once.
    $by_sport = [];
    foreach ( $props_raw as $p ) {
        $sport     = sanitize_key( $p['sport']     ?? '' );
        $event_id  = sanitize_text_field( $p['event_id']  ?? '' );
        $matchup   = sanitize_text_field( $p['matchup']   ?? '' );
        $added_at  = sanitize_text_field( $p['added_at']  ?? '' );
        $game_time = sanitize_text_field( $p['game_time'] ?? '' );
        if ( $sport && $event_id ) {
            $by_sport[ $sport ][] = [ 'event_id' => $event_id, 'matchup' => $matchup, 'added_at' => $added_at, 'game_time' => $game_time ];
        }
    }

    $statuses  = [];
    $boxscores = []; // event_id => player stats map
    $logos     = []; // event_id => { home: url, away: url }

    foreach ( $by_sport as $sport => $entries ) {
        $path = statsight_espn_sport_path( $sport );
        if ( ! $path ) {
            foreach ( $entries as $e ) { $statuses[ $e['event_id'] ] = 'pre'; }
            continue;
        }

        $base_url       = 'https://site.api.espn.com/apis/site/v2/sports/' . $path['sport'] . '/' . $path['league'];
        $scoreboard_url = $base_url . '/scoreboard';

        $cache_key = 'statsight_wl_sb_' . $sport;
        $sb_data   = get_transient( $cache_key );

        if ( false === $sb_data ) {
            $response = wp_remote_get( $scoreboard_url, [ 'timeout' => 10 ] );
            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
                foreach ( $entries as $e ) { $statuses[ $e['event_id'] ] = 'pre'; }
                continue;
            }
            $sb_data = json_decode( wp_remote_retrieve_body( $response ), true );
            set_transient( $cache_key, $sb_data, 2 * MINUTE_IN_SECONDS );
        }

        // Build a map: "home|away" => { state, event_index } so we can fetch boxscores.
        $game_map = []; // "home||away" => [ 'state', 'event_id_espn', 'event_index' ]
        foreach ( $sb_data['events'] ?? [] as $idx => $event ) {
            $state       = $event['status']['type']['state'] ?? '';
            $competitors = $event['competitions'][0]['competitors'] ?? [];
            $home = $away = '';
            foreach ( $competitors as $c ) {
                if ( ( $c['homeAway'] ?? '' ) === 'home' ) {
                    $home = $c['team']['displayName'] ?? '';
                } else {
                    $away = $c['team']['displayName'] ?? '';
                }
            }
            $home_logo = $away_logo = '';
            foreach ( $competitors as $c ) {
                $logo = $c['team']['logo'] ?? '';
                if ( ( $c['homeAway'] ?? '' ) === 'home' ) {
                    $home_logo = $logo;
                } else {
                    $away_logo = $logo;
                }
            }

            if ( $home && $away ) {
                $game_map[] = [
                    'home'      => $home,
                    'away'      => $away,
                    'state'     => $state,
                    'espn_id'   => $event['id'] ?? '',
                    'home_logo' => $home_logo,
                    'away_logo' => $away_logo,
                ];
            }
        }

        foreach ( $entries as $e ) {
            // Matchup is stored as "Away @ Home".
            $parts  = array_map( 'trim', explode( ' @ ', $e['matchup'], 2 ) );
            $m_away = $parts[0] ?? '';
            $m_home = $parts[1] ?? '';

            $matched_state   = 'pre';
            $matched_espn_id = '';

            foreach ( $game_map as $g ) {
                $home_match = stripos( $g['home'], $m_home ) !== false || stripos( $m_home, $g['home'] ) !== false;
                $away_match = stripos( $g['away'], $m_away ) !== false || stripos( $m_away, $g['away'] ) !== false;
                if ( $home_match && $away_match ) {
                    $matched_state   = $g['state'] === 'post' ? 'final' : ( $g['state'] === 'in' ? 'live' : 'pre' );
                    $matched_espn_id = $g['espn_id'];
                    break;
                }
            }

            // If not found on today's scoreboard, only mark as final if the game's
            // scheduled start time has already passed. This prevents back-to-back
            // matchups (same teams, consecutive days) from being wrongly marked final.
            // Note: $e['event_id'] is a The Odds API hash — not an ESPN event ID.
            if ( $matched_state === 'pre' && ! empty( $e['added_at'] ) ) {
                $added_ts  = strtotime( $e['added_at'] );
                $game_ts   = ! empty( $e['game_time'] ) ? strtotime( $e['game_time'] ) : 0;
                // Use game_time if available, otherwise fall back to added_at + 24h heuristic.
                $has_passed = $game_ts ? ( time() > $game_ts + 3 * HOUR_IN_SECONDS ) : ( $added_ts && ( time() - $added_ts ) > DAY_IN_SECONDS );
                if ( $has_passed ) {
                    $matched_state = 'final';

                    $game_date      = gmdate( 'Ymd', $added_ts );
                    $hist_cache_key = 'statsight_wl_sb_' . $sport . '_' . $game_date;
                    $hist_sb        = get_transient( $hist_cache_key );

                    if ( false === $hist_sb ) {
                        $hist_url  = $base_url . '/scoreboard?dates=' . $game_date;
                        $hist_resp = wp_remote_get( $hist_url, [ 'timeout' => 10 ] );
                        $hist_sb   = ( ! is_wp_error( $hist_resp ) && wp_remote_retrieve_response_code( $hist_resp ) === 200 )
                            ? json_decode( wp_remote_retrieve_body( $hist_resp ), true )
                            : [];
                        set_transient( $hist_cache_key, $hist_sb, DAY_IN_SECONDS );
                    }

                    // Search same day and next day (prop may have been added before game date).
                    $search_dates = [ $game_date, gmdate( 'Ymd', $added_ts + DAY_IN_SECONDS ) ];
                    $all_hist_events = $hist_sb['events'] ?? [];

                    if ( $game_date !== $search_dates[1] ) {
                        $next_cache_key = 'statsight_wl_sb_' . $sport . '_' . $search_dates[1];
                        $next_sb        = get_transient( $next_cache_key );
                        if ( false === $next_sb ) {
                            $next_url  = $base_url . '/scoreboard?dates=' . $search_dates[1];
                            $next_resp = wp_remote_get( $next_url, [ 'timeout' => 10 ] );
                            $next_sb   = ( ! is_wp_error( $next_resp ) && wp_remote_retrieve_response_code( $next_resp ) === 200 )
                                ? json_decode( wp_remote_retrieve_body( $next_resp ), true )
                                : [];
                            set_transient( $next_cache_key, $next_sb, DAY_IN_SECONDS );
                        }
                        $all_hist_events = array_merge( $all_hist_events, $next_sb['events'] ?? [] );
                    }

                    foreach ( $all_hist_events as $hist_event ) {
                        $competitors = $hist_event['competitions'][0]['competitors'] ?? [];
                        $h_home = $h_away = '';
                        foreach ( $competitors as $c ) {
                            if ( ( $c['homeAway'] ?? '' ) === 'home' ) {
                                $h_home = $c['team']['displayName'] ?? '';
                            } else {
                                $h_away = $c['team']['displayName'] ?? '';
                            }
                        }
                        $home_match = stripos( $h_home, $m_home ) !== false || stripos( $m_home, $h_home ) !== false;
                        $away_match = stripos( $h_away, $m_away ) !== false || stripos( $m_away, $h_away ) !== false;
                        if ( $home_match && $away_match ) {
                            $matched_espn_id = $hist_event['id'] ?? '';
                            break;
                        }
                    }
                    if ( ! $matched_espn_id ) {
                    }
                }
            }

            $statuses[ $e['event_id'] ] = $matched_state;

            // Store logos for this event.
            foreach ( $game_map as $g ) {
                $home_match = stripos( $g['home'], $m_home ) !== false || stripos( $m_home, $g['home'] ) !== false;
                $away_match = stripos( $g['away'], $m_away ) !== false || stripos( $m_away, $g['away'] ) !== false;
                if ( $home_match && $away_match ) {
                    $logos[ $e['event_id'] ] = [
                        'home' => esc_url_raw( $g['home_logo'] ?? '' ),
                        'away' => esc_url_raw( $g['away_logo'] ?? '' ),
                    ];
                    break;
                }
            }

            // Fetch boxscore for live/final games.
            if ( in_array( $matched_state, [ 'live', 'final' ], true ) && $matched_espn_id ) {
                $bs_cache_key = 'statsight_wl_bs_' . $matched_espn_id;
                $bs_cached    = get_transient( $bs_cache_key );

                if ( false === $bs_cached ) {
                    $sum_url  = $base_url . '/summary?event=' . $matched_espn_id;
                    $bs_resp  = wp_remote_get( $sum_url, [ 'timeout' => 10 ] );
                    $bs_data  = ( ! is_wp_error( $bs_resp ) && wp_remote_retrieve_response_code( $bs_resp ) === 200 )
                        ? json_decode( wp_remote_retrieve_body( $bs_resp ), true )
                        : [];

                    $player_stats = [];
                    foreach ( $bs_data['boxscore']['players'] ?? [] as $team_block ) {
                        foreach ( $team_block['statistics'] ?? [] as $stat_group ) {
                            $labels   = $stat_group['labels'] ?? [];
                            $athletes = $stat_group['athletes'] ?? [];
                            foreach ( $athletes as $athlete ) {
                                if ( $athlete['didNotPlay'] ?? false ) continue;
                                $name      = $athlete['athlete']['displayName'] ?? '';
                                $stats_arr = $athlete['stats'] ?? [];
                                if ( ! $name || empty( $stats_arr ) ) continue;
                                $stats_map = [];
                                foreach ( $labels as $i => $label ) {
                                    $stats_map[ $label ] = $stats_arr[ $i ] ?? '—';
                                }
                                $player_stats[ $name ] = array_merge( $player_stats[ $name ] ?? [], $stats_map );
                            }
                        }
                    }

                    $ttl      = $matched_state === 'final' ? 10 * MINUTE_IN_SECONDS : 45;
                    set_transient( $bs_cache_key, $player_stats, $ttl );
                    $bs_cached = $player_stats;
                }

                $boxscores[ $e['event_id'] ] = $bs_cached;
            }
        }
    }

    wp_send_json_success( [ 'statuses' => $statuses, 'boxscores' => $boxscores, 'logos' => $logos ] );
}
add_action( 'wp_ajax_statsight_watchlist_game_status', 'statsight_ajax_watchlist_game_status' );

/**
 * Update line/odds/book/all_books on an existing watchlist row. POST: id, line, odds, book, all_books
 */
function statsight_ajax_watchlist_update(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'sharp' );

    global $wpdb;

    $user_id = get_current_user_id();
    $id      = (int) ( $_POST['id'] ?? 0 );

    if ( ! $id ) {
        wp_send_json_error( [ 'message' => 'Invalid ID.' ], 400 );
    }

    $table = $wpdb->prefix . 'statsight_watchlist';

    // Fetch the existing row to check ownership and game status.
    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT game_start_time, result FROM {$table} WHERE id = %d AND user_id = %d",
        $id, $user_id
    ), ARRAY_A );

    if ( ! $existing ) {
        wp_send_json_error( [ 'message' => 'Not found.' ], 404 );
    }

    // Block updates once the game has started — prevents mid-game line/direction manipulation.
    $game_start = $existing['game_start_time'] ? strtotime( $existing['game_start_time'] ) : null;
    if ( $game_start && $game_start <= time() ) {
        wp_send_json_error( [ 'message' => 'Cannot update a prop after the game has started.' ], 403 );
    }

    // Also block if already settled.
    if ( $existing['result'] !== null ) {
        wp_send_json_error( [ 'message' => 'Cannot update a settled prop.' ], 403 );
    }

    $all_books_raw = json_decode( wp_unslash( $_POST['all_books'] ?? '[]' ), true );
    $all_books     = [];
    if ( is_array( $all_books_raw ) ) {
        foreach ( $all_books_raw as $entry ) {
            if ( isset( $entry['book'], $entry['odds'] ) ) {
                $all_books[] = [
                    'book' => sanitize_text_field( $entry['book'] ),
                    'odds' => (int) $entry['odds'],
                ];
            }
        }
    }

    // Only update display/cosmetic fields — line and direction are settlement inputs and locked at save time.
    $wpdb->update(
        $table,
        [
            'odds'      => (int) ( $_POST['odds']              ?? 0 ),
            'book'      => sanitize_text_field( $_POST['book'] ?? '' ),
            'all_books' => wp_json_encode( $all_books ),
        ],
        [ 'id' => $id, 'user_id' => $user_id ],
        [ '%d', '%s', '%s' ],
        [ '%d', '%d' ]
    );

    wp_send_json_success();
}
add_action( 'wp_ajax_statsight_watchlist_update', 'statsight_ajax_watchlist_update' );

/**
 * Get all watchlist props for the current user.
 */
function statsight_ajax_watchlist_get(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'sharp' );

    global $wpdb;

    $user_id = get_current_user_id();
    $table   = $wpdb->prefix . 'statsight_watchlist';

    $props = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND deleted_at IS NULL ORDER BY added_at DESC", $user_id ),
        ARRAY_A
    );

    if ( empty( $props ) ) {
        wp_send_json_success( [ 'props' => [] ] );
    }

    // For each prop, fetch odds history from the history table:
    //   live_odds     — latest over_odds per book (for display + EV)
    //   odds_history  — last 5 snapshots for the saved book (for trend arrow)
    //   under_odds    — latest under_odds per book (for no-vig EV calc)
    $history_table = $wpdb->prefix . 'statsight_odds_history';
    $live_odds     = []; // "{watchlist_id}" -> { book_key -> over_odds }
    $under_odds    = []; // "{watchlist_id}" -> { book_key -> under_odds }
    $odds_history  = []; // "{watchlist_id}" -> [ { over, recorded_at }, ... ] for saved book

    foreach ( $props as $prop ) {
        // Latest over + under per book.
        $latest_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT h.book_key, h.over_odds, h.under_odds
                 FROM {$history_table} h
                 INNER JOIN (
                     SELECT book_key, MAX(recorded_at) AS max_at
                     FROM {$history_table}
                     WHERE event_id   = %s
                       AND market_key = %s
                       AND player     = %s
                       AND line       = %s
                     GROUP BY book_key
                 ) latest ON h.book_key = latest.book_key AND h.recorded_at = latest.max_at
                 WHERE h.event_id   = %s
                   AND h.market_key = %s
                   AND h.player     = %s
                   AND h.line       = %s",
                $prop['event_id'], $prop['market_key'], $prop['player'], $prop['line'],
                $prop['event_id'], $prop['market_key'], $prop['player'], $prop['line']
            ),
            ARRAY_A
        );

        $book_over  = [];
        $book_under = [];
        foreach ( $latest_rows as $row ) {
            if ( $row['over_odds'] !== null ) {
                $book_over[ $row['book_key'] ]  = (int) $row['over_odds'];
            }
            if ( $row['under_odds'] !== null ) {
                $book_under[ $row['book_key'] ] = (int) $row['under_odds'];
            }
        }
        $live_odds[ $prop['id'] ]  = $book_over;
        $under_odds[ $prop['id'] ] = $book_under;

        // Last 5 snapshots for the saved book — used for the trend arrow.
        $trend_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT over_odds, recorded_at
                 FROM {$history_table}
                 WHERE event_id   = %s
                   AND market_key = %s
                   AND player     = %s
                   AND line       = %s
                   AND book_key   = %s
                 ORDER BY recorded_at DESC
                 LIMIT 5",
                $prop['event_id'], $prop['market_key'], $prop['player'], $prop['line'], $prop['book']
            ),
            ARRAY_A
        );
        // Reverse so oldest→newest for calcTrend().
        $odds_history[ $prop['id'] ] = array_reverse(
            array_map( fn( $r ) => [ 'over' => $r['over_odds'] !== null ? (int) $r['over_odds'] : null ], $trend_rows )
        );
    }

    wp_send_json_success( [
        'props'        => $props,  // includes clv and game_start_time columns
        'live_odds'    => $live_odds,
        'under_odds'   => $under_odds,
        'odds_history' => $odds_history,
    ] );
}
add_action( 'wp_ajax_statsight_watchlist_get', 'statsight_ajax_watchlist_get' );

/**
 * Calculate a user's pick record from their settled watchlist props.
 *
 * Only counts props where added_at < game_start_time (no hindsight saves).
 * Requires MIN_PICKS_FOR_RECORD settled qualifying props to return a hit rate.
 * Soft-deleted props (deleted_at IS NOT NULL) are still counted — they settled.
 *
 * @return array{ wins: int, losses: int, pushes: int, total: int, hit_rate: float|null, roi: float|null }
 */
function statsight_get_user_pick_record( int $user_id ): array {
    global $wpdb;

    $table = $wpdb->prefix . 'statsight_watchlist';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT result, odds
             FROM {$table}
             WHERE user_id        = %d
               AND result         IS NOT NULL
               AND result         != 'void'
               AND game_start_time IS NOT NULL
               AND added_at       < game_start_time",
            $user_id
        ),
        ARRAY_A
    );

    $wins   = 0;
    $losses = 0;
    $pushes = 0;
    $roi    = 0.0; // cumulative profit on $100 stake per pick

    foreach ( $rows as $row ) {
        $result = $row['result'];
        $odds   = (int) $row['odds'];

        if ( $result === 'win' ) {
            $wins++;
            $roi += $odds >= 0 ? $odds : ( 10000 / abs( $odds ) );
        } elseif ( $result === 'loss' ) {
            $losses++;
            $roi -= 100;
        } elseif ( $result === 'push' ) {
            $pushes++;
            // push returns stake — net zero
        }
    }

    $total     = $wins + $losses + $pushes;
    $decidable = $wins + $losses; // pushes don't count toward hit rate

    return [
        'wins'     => $wins,
        'losses'   => $losses,
        'pushes'   => $pushes,
        'total'    => $total,
        'hit_rate' => ( $decidable >= STATSIGHT_MIN_PICKS ) ? round( $wins / $decidable, 4 ) : null,
        'roi'      => ( $decidable >= STATSIGHT_MIN_PICKS && $decidable > 0 ) ? round( $roi / $decidable, 2 ) : null,
    ];
}

/**
 * AJAX handler — return pick record for any user by ID (public data).
 * GET: user_id
 */
function statsight_ajax_get_pick_record(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );

    $user_id = (int) ( $_GET['user_id'] ?? 0 );
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'Missing user_id.' ], 400 );
    }

    wp_send_json_success( statsight_get_user_pick_record( $user_id ) );
}
add_action( 'wp_ajax_statsight_get_pick_record',        'statsight_ajax_get_pick_record' );
add_action( 'wp_ajax_nopriv_statsight_get_pick_record', 'statsight_ajax_get_pick_record' );

/**
 * Returns the user's pick record broken down by sport.
 * Same eligibility rules as statsight_get_user_pick_record().
 */
function statsight_get_user_pick_record_by_sport( int $user_id ): array {
    global $wpdb;

    $table = $wpdb->prefix . 'statsight_watchlist';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT sport, result, odds
             FROM {$table}
             WHERE user_id         = %d
               AND result          IS NOT NULL
               AND result          != 'void'
               AND game_start_time IS NOT NULL
               AND added_at        < game_start_time",
            $user_id
        ),
        ARRAY_A
    );

    $sports = [];

    foreach ( $rows as $row ) {
        $sport  = $row['sport']  ?? 'unknown';
        $result = $row['result'] ?? '';
        $odds   = (int) $row['odds'];

        if ( ! isset( $sports[ $sport ] ) ) {
            $sports[ $sport ] = [ 'wins' => 0, 'losses' => 0, 'pushes' => 0, 'roi' => 0.0 ];
        }

        if ( $result === 'win' ) {
            $sports[ $sport ]['wins']++;
            $sports[ $sport ]['roi'] += $odds >= 0 ? $odds : ( 10000 / abs( $odds ) );
        } elseif ( $result === 'loss' ) {
            $sports[ $sport ]['losses']++;
            $sports[ $sport ]['roi'] -= 100;
        } elseif ( $result === 'push' ) {
            $sports[ $sport ]['pushes']++;
        }
    }

    $sport_labels = [
        'basketball_nba'           => 'NBA',
        'basketball_ncaab'         => 'NCAAB',
        'basketball_wnba'          => 'WNBA',
        'americanfootball_nfl'     => 'NFL',
        'americanfootball_ncaaf'   => 'NCAAF',
        'baseball_mlb'             => 'MLB',
        'icehockey_nhl'            => 'NHL',
        'mma_mixed_martial_arts'   => 'MMA',
        'soccer_epl'               => 'EPL',
        'soccer_usa_mls'           => 'MLS',
    ];

    $out = [];
    foreach ( $sports as $key => $data ) {
        $decidable = $data['wins'] + $data['losses'];
        $out[] = [
            'sport'    => $key,
            'label'    => $sport_labels[ $key ] ?? strtoupper( $key ),
            'wins'     => $data['wins'],
            'losses'   => $data['losses'],
            'pushes'   => $data['pushes'],
            'hit_rate' => $decidable > 0 ? round( $data['wins'] / $decidable, 4 ) : null,
            'roi'      => $decidable > 0 ? round( $data['roi'] / $decidable, 2 ) : null,
        ];
    }

    // Sort by most picks (decidable) descending.
    usort( $out, fn( $a, $b ) => ( $b['wins'] + $b['losses'] ) <=> ( $a['wins'] + $a['losses'] ) );

    return $out;
}

/**
 * Returns settled pick record broken down by sport, market, book, and direction.
 * Single query; all grouping done in PHP.
 */
function statsight_get_user_pick_record_breakdown( int $user_id ): array {
    global $wpdb;

    $table = $wpdb->prefix . 'statsight_watchlist';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT sport, market_key, market_label, book, direction, result, odds
             FROM {$table}
             WHERE user_id         = %d
               AND result          IS NOT NULL
               AND result          != 'void'
               AND game_start_time IS NOT NULL
               AND added_at        < game_start_time",
            $user_id
        ),
        ARRAY_A
    );

    $sport_labels = [
        'basketball_nba'           => 'NBA',
        'basketball_ncaab'         => 'NCAAB',
        'basketball_wnba'          => 'WNBA',
        'americanfootball_nfl'     => 'NFL',
        'americanfootball_ncaaf'   => 'NCAAF',
        'baseball_mlb'             => 'MLB',
        'icehockey_nhl'            => 'NHL',
        'mma_mixed_martial_arts'   => 'MMA',
        'soccer_epl'               => 'EPL',
        'soccer_usa_mls'           => 'MLS',
    ];

    $book_labels = [
        'fanduel'          => 'FanDuel',
        'draftkings'       => 'DraftKings',
        'betmgm'           => 'BetMGM',
        'caesars'          => 'Caesars',
        'bet365'           => 'Bet365',
        'fanatics'         => 'Fanatics',
        'espnbet'          => 'ESPN Bet',
        'betrivers'        => 'BetRivers',
        'bovada'           => 'Bovada',
        'williamhill_us'   => 'Caesars',
        'pointsbetus'      => 'BetUS',
        'betonlineag'      => 'BetOnline',
        'superbook'        => 'SuperBook',
        'unibet_us'        => 'Unibet',
        'wynnbet'          => 'WynnBET',
        'ballybet'         => 'Bally Bet',
        'hardrock'         => 'Hard Rock',
        'fliff'            => 'Fliff',
        'prizepicks'       => 'PrizePicks',
        'underdog_fantasy' => 'Underdog',
    ];

    $buckets = [
        'sport'     => [],
        'market'    => [],
        'book'      => [],
        'direction' => [],
    ];

    foreach ( $rows as $row ) {
        $result = $row['result'];
        $odds   = (int) $row['odds'];

        $keys = [
            'sport'     => $row['sport']     ?? 'unknown',
            'market'    => $row['market_key'] ?? 'unknown',
            'book'      => $row['book']       ?? 'unknown',
            'direction' => $row['direction']  ?? 'over',
        ];

        foreach ( $keys as $dim => $key ) {
            if ( ! isset( $buckets[ $dim ][ $key ] ) ) {
                $buckets[ $dim ][ $key ] = [ 'wins' => 0, 'losses' => 0, 'pushes' => 0, 'roi' => 0.0, 'label' => $key ];
            }
            if ( $result === 'win' ) {
                $buckets[ $dim ][ $key ]['wins']++;
                $buckets[ $dim ][ $key ]['roi'] += $odds >= 0 ? $odds : ( 10000 / abs( $odds ) );
            } elseif ( $result === 'loss' ) {
                $buckets[ $dim ][ $key ]['losses']++;
                $buckets[ $dim ][ $key ]['roi'] -= 100;
            } elseif ( $result === 'push' ) {
                $buckets[ $dim ][ $key ]['pushes']++;
            }
        }

        // Store market display label alongside the key.
        $mkey = $row['market_key'] ?? 'unknown';
        if ( isset( $buckets['market'][ $mkey ] ) && ! empty( $row['market_label'] ) ) {
            $buckets['market'][ $mkey ]['label'] = $row['market_label'];
        }
    }

    $finalize = function ( array $data, string $dim ) use ( $sport_labels, $book_labels ): array {
        $out = [];
        foreach ( $data as $key => $d ) {
            $decidable = $d['wins'] + $d['losses'];
            if ( $dim === 'sport' ) {
                $label = $sport_labels[ $key ] ?? strtoupper( $key );
            } elseif ( $dim === 'book' ) {
                $label = $book_labels[ $key ] ?? ucwords( str_replace( '_', ' ', $key ) );
            } elseif ( $dim === 'direction' ) {
                $label = ucfirst( $key );
            } else {
                $label = $d['label'];
            }
            $out[] = [
                'key'      => $key,
                'label'    => $label,
                'wins'     => $d['wins'],
                'losses'   => $d['losses'],
                'pushes'   => $d['pushes'],
                'hit_rate' => $decidable > 0 ? round( $d['wins'] / $decidable, 4 ) : null,
                'roi'      => $decidable > 0 ? round( $d['roi'] / $decidable, 2 ) : null,
            ];
        }
        usort( $out, fn( $a, $b ) => ( $b['wins'] + $b['losses'] ) <=> ( $a['wins'] + $a['losses'] ) );
        return $out;
    };

    return [
        'sport'     => $finalize( $buckets['sport'],     'sport' ),
        'market'    => $finalize( $buckets['market'],    'market' ),
        'book'      => $finalize( $buckets['book'],      'book' ),
        'direction' => $finalize( $buckets['direction'], 'direction' ),
    ];
}

function statsight_ajax_get_pick_breakdown(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 403 );
    }

    wp_send_json_success( statsight_get_user_pick_record_breakdown( $user_id ) );
}
add_action( 'wp_ajax_statsight_get_pick_breakdown', 'statsight_ajax_get_pick_breakdown' );

/**
 * AJAX handler — return the current user's settled prop history (soft-deleted + settled active).
 */
function statsight_ajax_get_pick_history(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 403 );
    }

    global $wpdb;
    $table  = $wpdb->prefix . 'statsight_watchlist';
    $offset = (int) ( $_REQUEST['offset'] ?? 0 );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, sport, player, market_label, line, direction, odds, book, matchup,
                    result, actual_stat, game_start_time, added_at, clv
             FROM {$table}
             WHERE user_id         = %d
               AND result          IS NOT NULL
               AND result          != 'void'
               AND game_start_time IS NOT NULL
               AND added_at        < game_start_time
             ORDER BY game_start_time DESC
             LIMIT 20 OFFSET %d",
            $user_id, $offset
        ),
        ARRAY_A
    );

    wp_send_json_success( $rows ?: [] );
}
add_action( 'wp_ajax_statsight_get_pick_history', 'statsight_ajax_get_pick_history' );

/**
 * AJAX handler — return leaderboard: top pickers by hit rate (min 10 qualifying picks).
 * Returns up to 50 users, ordered by hit rate desc then wins desc.
 */
function statsight_ajax_get_leaderboard(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );

    global $wpdb;

    $table      = $wpdb->prefix . 'statsight_watchlist';
    $users_table = $wpdb->prefix . 'users';

    $rows = $wpdb->get_results(
        "SELECT
             w.user_id,
             u.display_name,
             SUM( w.result = 'win'  ) AS wins,
             SUM( w.result = 'loss' ) AS losses,
             SUM( w.result = 'push' ) AS pushes,
             COUNT(*)                 AS total
         FROM {$table} w
         INNER JOIN {$users_table} u ON u.ID = w.user_id
         INNER JOIN {$wpdb->usermeta} um ON um.user_id = w.user_id
             AND um.meta_key = 'statsight_record_public'
             AND um.meta_value = '1'
         WHERE w.result         IS NOT NULL
           AND w.result         != 'void'
           AND w.game_start_time IS NOT NULL
           AND w.added_at       < w.game_start_time
         GROUP BY w.user_id, u.display_name
         HAVING (wins + losses) >= 10
         ORDER BY (wins / (wins + losses)) DESC, wins DESC
         LIMIT 20",
        ARRAY_A
    );

    $leaderboard = array_map( function ( $row ) {
        $decidable = (int) $row['wins'] + (int) $row['losses'];
        return [
            'user_id'      => (int) $row['user_id'],
            'display_name' => $row['display_name'],
            'wins'         => (int) $row['wins'],
            'losses'       => (int) $row['losses'],
            'pushes'       => (int) $row['pushes'],
            'total'        => (int) $row['total'],
            'hit_rate'     => $decidable > 0 ? round( $row['wins'] / $decidable, 4 ) : null,
        ];
    }, $rows );

    wp_send_json_success( $leaderboard );
}
add_action( 'wp_ajax_statsight_get_leaderboard',        'statsight_ajax_get_leaderboard' );
add_action( 'wp_ajax_nopriv_statsight_get_leaderboard', 'statsight_ajax_get_leaderboard' );

/**
 * Save a parlay group.
 * POST: name, prop_ids (JSON array of selection keys), legs_json (JSON array of resolved leg objects)
 */
function statsight_ajax_parlay_save(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'sharp' );

    global $wpdb;

    $user_id  = get_current_user_id();
    $name     = sanitize_text_field( $_POST['name'] ?? '' );
    $prop_ids = json_decode( wp_unslash( $_POST['prop_ids'] ?? '[]' ), true );

    if ( empty( $name ) || empty( $prop_ids ) || ! is_array( $prop_ids ) ) {
        wp_send_json_error( [ 'message' => 'Name and at least one prop are required.' ], 400 );
    }

    if ( ! statsight_text_is_clean( $name ) ) {
        wp_send_json_error( [ 'message' => 'That collection name isn\'t allowed. Please choose a different name.' ], 422 );
    }

    // JS resolves legs and sends them directly; sanitize each field.
    $legs_raw = json_decode( wp_unslash( $_POST['legs_json'] ?? '[]' ), true );
    $legs     = [];
    if ( is_array( $legs_raw ) ) {
        foreach ( $legs_raw as $l ) {
            $legs[] = [
                'player'       => sanitize_text_field( $l['player']       ?? '' ),
                'market_label' => sanitize_text_field( $l['market_label'] ?? '' ),
                'line'         => sanitize_text_field( $l['line']         ?? '' ),
                'direction'    => sanitize_text_field( $l['direction']    ?? 'over' ),
                'odds'         => (int) ( $l['odds']                      ?? 0 ),
                'book'         => sanitize_text_field( $l['book']         ?? '' ),
                'matchup'      => sanitize_text_field( $l['matchup']      ?? '' ),
            ];
        }
    }

    $wpdb->insert( $wpdb->prefix . 'statsight_parlays', [
        'user_id'    => $user_id,
        'name'       => $name,
        'prop_ids'   => wp_json_encode( $prop_ids ),
        'legs_json'  => wp_json_encode( $legs ),
        'created_at' => gmdate( 'Y-m-d H:i:s' ),
    ] );

    wp_send_json_success( [ 'id' => $wpdb->insert_id ] );
}
add_action( 'wp_ajax_statsight_parlay_save', 'statsight_ajax_parlay_save' );

/**
 * Get all parlays for the current user.
 */
function statsight_ajax_parlay_get(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'sharp' );

    global $wpdb;

    $user_id = get_current_user_id();

    $parlays = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}statsight_parlays WHERE user_id = %d ORDER BY created_at DESC",
            $user_id
        ),
        ARRAY_A
    );

    // Use the snapshotted legs_json; fall back to empty array for old rows without it.
    foreach ( $parlays as &$parlay ) {
        $parlay['legs'] = ! empty( $parlay['legs_json'] )
            ? json_decode( $parlay['legs_json'], true )
            : [];
        unset( $parlay['legs_json'] );
    }
    unset( $parlay );

    wp_send_json_success( [ 'parlays' => $parlays ] );
}
add_action( 'wp_ajax_statsight_parlay_get', 'statsight_ajax_parlay_get' );

/**
 * Delete a parlay. POST: id
 */
function statsight_ajax_parlay_delete(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'sharp' );

    global $wpdb;

    $user_id = get_current_user_id();
    $id      = (int) ( $_POST['id'] ?? 0 );

    if ( ! $id ) {
        wp_send_json_error( [ 'message' => 'Invalid ID.' ], 400 );
    }

    $wpdb->delete(
        $wpdb->prefix . 'statsight_parlays',
        [ 'id' => $id, 'user_id' => $user_id ],
        [ '%d', '%d' ]
    );

    wp_send_json_success();
}
add_action( 'wp_ajax_statsight_parlay_delete', 'statsight_ajax_parlay_delete' );

// ── Shared Sports Helpers ──────────────────────────────────────────────────

/**
 * Fetch the list of in-season sports from The Odds API.
 * Results are cached in a transient for 1 hour.
 *
 * @return array{sports: array<int, array<string, mixed>>, error: string}
 */
function statsight_get_sports(): array {
    // v2: cache key versioned so old caches (pre-games-filter) are ignored automatically.
    $cache_key = 'statsight_sports_list_v2';
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return [ 'sports' => $cached, 'error' => '' ];
    }

    if ( ! defined( 'THE_ODDS_API_KEY' ) || empty( THE_ODDS_API_KEY ) ) {
        return [ 'sports' => [], 'error' => 'THE_ODDS_API_KEY constant is not defined.' ];
    }

    $url = 'https://api.the-odds-api.com/v4/sports/?' . http_build_query( [
        'apiKey'  => THE_ODDS_API_KEY,
        'regions' => 'us',
        'sports'  => 'upcoming',
    ] );

    $response = wp_remote_get( $url, [ 'timeout' => 15 ] );

    if ( is_wp_error( $response ) ) {
        return [ 'sports' => [], 'error' => 'wp_remote_get failed: ' . $response->get_error_message() ];
    }

    statsight_record_quota( $response );

    $status = wp_remote_retrieve_response_code( $response );
    $body   = wp_remote_retrieve_body( $response );

    if ( $status !== 200 ) {
        return [ 'sports' => [], 'error' => "API returned HTTP {$status}." ];
    }

    $sports = json_decode( $body, true );
    if ( ! is_array( $sports ) ) {
        return [ 'sports' => [], 'error' => 'Failed to parse API response.' ];
    }

    $allowed_keys = [
        'americanfootball_nfl',
        'basketball_nba',
        'basketball_nba_summer_league',
        'baseball_mlb',
        'icehockey_nhl',
        'mma_mixed_martial_arts',
        'americanfootball_ncaaf',
        'soccer_epl',
        'soccer_usa_mls',
        'basketball_ncaab',
        'basketball_wnba',
    ];

    $indexed = array_column( $sports, null, 'key' );
    $sports  = array_values(
        array_filter(
            array_map( fn( string $k ) => $indexed[ $k ] ?? null, $allowed_keys ),
            fn( $s ) => $s !== null
        )
    );

    // Only include sports that have at least one confirmed upcoming game.
    // Treat null (API failure / fetch disabled) as no games — don't let a
    // failed fetch leave a sport visible indefinitely in the cached list.
    $sports = array_values(
        array_filter( $sports, function ( array $sport ): bool {
            $events = statsight_get_events_for_sport( $sport['key'] );
            return is_array( $events ) && ! empty( $events['days'] );
        } )
    );

    // Only cache if at least one sport has games — avoids locking in an empty/wrong
    // list during a brief API outage. 15 min matches the events transient TTL.
    if ( ! empty( $sports ) ) {
        set_transient( 'statsight_sports_list_v2', $sports, 15 * MINUTE_IN_SECONDS );
    }
    return [ 'sports' => $sports, 'error' => '' ];
}

// ── Account page handlers ──────────────────────────────────────────────────

/**
 * AJAX handler — save notification preferences as user meta.
 * Keys default to false until email sending is wired up.
 */
function statsight_ajax_save_notifications(): void {
    check_ajax_referer( 'statsight_account', 'nonce' );

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
    }

    $raw   = isset( $_GET['rules'] ) ? wp_unslash( $_GET['rules'] ) : '[]';
    $rules = json_decode( $raw, true );

    if ( ! is_array( $rules ) ) {
        wp_send_json_error( [ 'message' => 'Invalid rules format.' ], 400 );
    }

    $allowed_types = [ 'arbitrage', 'best_value', 'line_move', 'ev_threshold', 'watchlist_result', 'daily_digest' ];
    $allowed_sports = [ 'all', 'basketball_nba', 'americanfootball_nfl', 'baseball_mlb', 'icehockey_nhl', 'basketball_ncaab', 'americanfootball_ncaaf', 'mma_mixed_martial_arts', 'soccer_epl', 'soccer_usa_mls', 'basketball_wnba' ];

    $sanitized = [];
    foreach ( $rules as $rule ) {
        $type = sanitize_key( $rule['type'] ?? '' );
        if ( ! in_array( $type, $allowed_types, true ) ) {
            continue;
        }
        $allowed_outcomes = [ 'any', 'win', 'loss' ];
        $outcome          = sanitize_key( $rule['outcome'] ?? 'any' );

        $sanitized[] = [
            'id'        => sanitize_key( $rule['id'] ?? '' ),
            'type'      => $type,
            'threshold' => isset( $rule['threshold'] ) ? (float) $rule['threshold'] : null,
            'sport'     => in_array( $rule['sport'] ?? 'all', $allowed_sports, true ) ? $rule['sport'] : 'all',
            'outcome'   => in_array( $outcome, $allowed_outcomes, true ) ? $outcome : 'any',
        ];
    }

    update_user_meta( $user_id, 'statsight_notif_rules', wp_json_encode( $sanitized ) );

    wp_send_json_success();
}
add_action( 'wp_ajax_statsight_save_notifications', 'statsight_ajax_save_notifications' );

/**
 * AJAX handler — save the user's active sportsbook preferences.
 */
function statsight_ajax_save_active_books(): void {
    check_ajax_referer( 'statsight_account', 'nonce' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
    }

    $user_id = get_current_user_id();
    $books   = isset( $_POST['books'] ) ? json_decode( wp_unslash( $_POST['books'] ), true ) : null;

    if ( $books === null ) {
        // null means "show all" — delete the meta so we fall back to default
        delete_user_meta( $user_id, 'statsight_active_books' );
    } else {
        $sanitized = array_values( array_filter( array_map( 'sanitize_key', (array) $books ) ) );
        update_user_meta( $user_id, 'statsight_active_books', wp_json_encode( $sanitized ) );
    }

    wp_send_json_success();
}
add_action( 'wp_ajax_statsight_save_active_books', 'statsight_ajax_save_active_books' );

/**
 * AJAX handler — initiate a 30-day soft-delete of the current user's account.
 *
 * Steps:
 *  1. Mark the account as pending deletion (user meta + timestamp).
 *  2. Cancel any active WooCommerce orders so the user isn't charged again.
 *  3. Send a confirmation email with a reactivation link.
 *  4. Log the user out.
 *
 * A daily cron job (statsight_purge_deleted_accounts) permanently deletes
 * accounts whose pending deletion timestamp is older than 30 days.
 */
function statsight_ajax_delete_account(): void {
    check_ajax_referer( 'statsight_account', 'nonce' );

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
    }

    // Cancel WooCommerce orders for plan products immediately.
    if ( function_exists( 'wc_get_orders' ) ) {
        $plan_product_ids = [ STATSIGHT_PRODUCT_PRO, STATSIGHT_PRODUCT_SHARP ];
        $orders = wc_get_orders( [
            'customer_id' => $user_id,
            'status'      => [ 'wc-completed', 'wc-processing', 'wc-on-hold' ],
            'limit'       => -1,
            'return'      => 'ids',
        ] );
        foreach ( $orders as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) continue;
            foreach ( $order->get_items() as $item ) {
                if ( in_array( (int) $item->get_product_id(), $plan_product_ids, true ) ) {
                    $order->update_status( 'cancelled', 'Account deletion requested by user.' );
                    break;
                }
            }
        }
    }

    // Generate a signed reactivation token valid for 30 days.
    $token = wp_generate_password( 32, false );
    update_user_meta( $user_id, 'statsight_pending_deletion', current_time( 'timestamp' ) );
    update_user_meta( $user_id, 'statsight_reactivation_token', wp_hash( $token ) );

    // Send confirmation email with reactivation link.
    $reactivate_url = add_query_arg( [
        'action'  => 'statsight_reactivate_account',
        'uid'     => $user_id,
        'token'   => $token,
    ], admin_url( 'admin-post.php' ) );

    $deletion_date = date_i18n( 'F j, Y', strtotime( '+30 days' ) );

    statsight_send_notif_email(
        $user_id,
        'Your account is scheduled for deletion',
        "<p style='margin:0 0 16px;font-size:1rem;font-weight:600;'>Account deletion scheduled</p>
         <p style='margin:0 0 12px;color:rgba(240,242,247,0.75);'>Your account will be permanently deleted on <strong style='color:#f0f2f7;'>{$deletion_date}</strong>. Until then, your data is preserved.</p>
         <p style='margin:0 0 24px;color:rgba(240,242,247,0.75);'>Changed your mind? Click the button below to cancel the deletion and reactivate your account.</p>
         <a href='" . esc_url( $reactivate_url ) . "' style='display:inline-block;background:#2e7cf6;color:#fff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:600;'>Reactivate My Account</a>
         <p style='margin:24px 0 0;font-size:0.8rem;color:rgba(240,242,247,0.4);'>If you didn't request this, contact support immediately.</p>"
    );

    wp_logout();
    wp_send_json_success( [ 'deletion_date' => $deletion_date ] );
}
add_action( 'wp_ajax_statsight_delete_account', 'statsight_ajax_delete_account' );

/**
 * Handles the reactivation link from the deletion confirmation email.
 * Clears the pending deletion meta so the account is restored.
 */
function statsight_handle_reactivate_account(): void {
    $user_id = (int) ( $_GET['uid'] ?? 0 );
    $token   = sanitize_text_field( $_GET['token'] ?? '' );

    if ( ! $user_id || ! $token ) {
        wp_die( 'Invalid reactivation link.', 'Error', [ 'response' => 400 ] );
    }

    $stored_hash = get_user_meta( $user_id, 'statsight_reactivation_token', true );
    if ( ! $stored_hash || ! hash_equals( $stored_hash, wp_hash( $token ) ) ) {
        wp_die( 'This reactivation link is invalid or has already been used.', 'Error', [ 'response' => 400 ] );
    }

    $pending = get_user_meta( $user_id, 'statsight_pending_deletion', true );
    if ( ! $pending ) {
        wp_die( 'No pending deletion found for this account.', 'Error', [ 'response' => 400 ] );
    }

    delete_user_meta( $user_id, 'statsight_pending_deletion' );
    delete_user_meta( $user_id, 'statsight_reactivation_token' );

    statsight_send_notif_email(
        $user_id,
        'Your account has been reactivated',
        "<p style='margin:0 0 16px;font-size:1rem;font-weight:600;'>Account reactivated</p>
         <p style='margin:0 0 24px;color:rgba(240,242,247,0.75);'>Your xstatiq account has been successfully reactivated. No data was deleted.</p>
         <a href='" . esc_url( home_url( '/my-account/' ) ) . "' style='display:inline-block;background:#2e7cf6;color:#fff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:600;'>Go to My Account</a>"
    );

    wp_safe_redirect( add_query_arg( 'reactivated', '1', home_url( '/my-account/' ) ) );
    exit;
}
add_action( 'admin_post_statsight_reactivate_account',        'statsight_handle_reactivate_account' );
add_action( 'admin_post_nopriv_statsight_reactivate_account', 'statsight_handle_reactivate_account' );

/**
 * Blocks login for accounts with a pending deletion.
 */
add_filter( 'authenticate', function ( $user ) {
    if ( ! ( $user instanceof WP_User ) ) {
        return $user;
    }
    if ( get_user_meta( $user->ID, 'statsight_pending_deletion', true ) ) {
        return new WP_Error(
            'account_pending_deletion',
            'This account is scheduled for deletion. Check your email for the reactivation link, or contact support.'
        );
    }
    return $user;
}, 30 );

/**
 * Daily cron — permanently deletes accounts pending deletion for 30+ days.
 */
function statsight_cron_purge_deleted_accounts(): void {
    $cutoff = strtotime( '-30 days' );

    $users = get_users( [
        'meta_key'     => 'statsight_pending_deletion',
        'meta_value'   => $cutoff,
        'meta_compare' => '<=',
        'fields'       => 'ids',
    ] );

    if ( empty( $users ) ) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/user.php';

    foreach ( $users as $user_id ) {
        global $wpdb;

        $wpdb->delete( $wpdb->prefix . 'statsight_watchlist', [ 'user_id' => $user_id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'statsight_parlays',   [ 'user_id' => $user_id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'statsight_prop_alerts', [ 'user_id' => $user_id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'statsight_push_subscriptions', [ 'user_id' => $user_id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'statsight_follows',   [ 'follower_id'  => $user_id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'statsight_follows',   [ 'following_id' => $user_id ], [ '%d' ] );

        wp_delete_user( $user_id, 1 );
        error_log( "[xstatiq] Permanently deleted account for user_id={$user_id} after 30-day pending period." );
    }
}
add_action( 'statsight_purge_deleted_accounts', 'statsight_cron_purge_deleted_accounts' );

// ── Notification system ────────────────────────────────────────────────────

/**
 * Check whether notifications have already been sent for a given
 * (user, rule, fingerprint) combination. Returns true if already sent.
 */
function statsight_notif_already_sent( int $user_id, string $rule_id, string $fingerprint ): bool {
    global $wpdb;
    $table = $wpdb->prefix . 'statsight_notif_sent';
    $count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND rule_id = %s AND fingerprint = %s",
        $user_id,
        $rule_id,
        $fingerprint
    ) );
    return $count > 0;
}

/**
 * Atomically claim a notification slot via INSERT IGNORE.
 * Returns true if this process won the race (should send).
 * Returns false if another process already inserted this fingerprint (skip).
 */
function statsight_notif_mark_sent( int $user_id, string $rule_id, string $fingerprint ): bool {
    global $wpdb;
    $table = $wpdb->prefix . 'statsight_notif_sent';
    $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO {$table} (user_id, rule_id, fingerprint, sent_at) VALUES (%d, %s, %s, %s)",
        $user_id,
        $rule_id,
        $fingerprint,
        current_time( 'mysql', true )
    ) );
    return $wpdb->rows_affected === 1;
}

/**
 * Purge notification sent-log entries older than 48 hours.
 * Called at the start of each notification cron run to keep the table lean.
 */
function statsight_notif_purge_old_sent(): void {
    global $wpdb;
    $table  = $wpdb->prefix . 'statsight_notif_sent';
    $cutoff = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE sent_at < %s", $cutoff ) );
}

/**
 * Send one notification email to a user.
 *
 * @param int    $user_id
 * @param string $subject
 * @param string $body_html  Simple HTML body — wrapped in a minimal template.
 */
function statsight_send_notif_email( int $user_id, string $subject, string $body_html ): bool {
    $user = get_userdata( $user_id );
    if ( ! $user || empty( $user->user_email ) ) {
        return false;
    }

    $site_name = get_bloginfo( 'name' );
    $home_url  = home_url( '/' );

    $html = "<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width'></head>
<body style='margin:0;padding:0;background:#0a0c10;font-family:Inter,Arial,sans-serif;color:#f0f2f7;'>
  <div style='max-width:560px;margin:40px auto;padding:0 16px;'>
    <div style='margin-bottom:24px;'>
      <span style='font-size:1.25rem;font-weight:800;letter-spacing:2px;color:#f0f2f7;'>xstat</span><span style='font-size:1.25rem;font-weight:800;letter-spacing:2px;color:#2e7cf6;'>iq</span>
    </div>
    <div style='background:#13161d;border:1px solid rgba(255,255,255,0.07);border-radius:10px;padding:28px 32px;'>
      {$body_html}
    </div>
    <p style='margin-top:20px;font-size:0.75rem;color:rgba(240,242,247,0.4);text-align:center;'>
      You're receiving this because you set up notification rules in your
      <a href='" . esc_url( $home_url . 'my-account/' ) . "' style='color:#2e7cf6;text-decoration:none;'>xstatiq account</a>.
    </p>
  </div>
</body>
</html>";

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
    ];

    return wp_mail( $user->user_email, '[xstatiq] ' . $subject, $html, $headers );
}

/**
 * Send a Web Push notification to all subscriptions for a user.
 * Uses the VAPID JWT approach (RFC 8292) with manual OpenSSL signing.
 * Payload is plaintext JSON — encryption skipped for RFC 8030 basic push.
 *
 * @return int Number of subscriptions successfully pushed.
 */
function statsight_send_push_to_user( int $user_id, string $title, string $body, string $url = '' ): int {
    if ( ! defined( 'STATSIGHT_VAPID_PUBLIC' ) || ! defined( 'STATSIGHT_VAPID_PRIVATE' ) ) {
        return 0;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'statsight_push_subscriptions';
    $subs  = $wpdb->get_results( $wpdb->prepare(
        "SELECT endpoint, p256dh, auth FROM {$table} WHERE user_id = %d",
        $user_id
    ), ARRAY_A );

    if ( empty( $subs ) ) {
        return 0;
    }

    $sent    = 0;
    $payload = wp_json_encode( [
        'title' => $title,
        'body'  => $body,
        'icon'  => get_template_directory_uri() . '/assets/icons/icon-192.png',
        'badge' => get_template_directory_uri() . '/assets/icons/icon-192.png',
        'url'   => $url ?: home_url( '/props/' ),
        'tag'   => 'statsight-' . md5( $title . $body ),
    ] );

    foreach ( $subs as $sub ) {
        $result = statsight_webpush_send( $sub['endpoint'], $sub['p256dh'], $sub['auth'], $payload );
        if ( $result ) {
            $sent++;
        } else {
            // If endpoint is gone (410 Gone), remove the subscription.
            $wpdb->delete( $table, [ 'endpoint' => $sub['endpoint'] ], [ '%s' ] );
        }
    }

    return $sent;
}

/**
 * Send a single Web Push message using VAPID authentication and
 * aes128gcm content encryption (RFC 8291 / RFC 8188).
 */
function statsight_webpush_send( string $endpoint, string $p256dh, string $auth, string $payload ): bool {
    $vapid_public  = STATSIGHT_VAPID_PUBLIC;
    $vapid_private = STATSIGHT_VAPID_PRIVATE;

    // ── 1. Build VAPID JWT ────────────────────────────────────────────────
    $parsed  = wp_parse_url( $endpoint );
    $aud     = $parsed['scheme'] . '://' . $parsed['host'];
    $exp     = time() + 12 * HOUR_IN_SECONDS;
    $subject = 'mailto:' . ( defined( 'STATSIGHT_SMTP_USER' ) ? STATSIGHT_SMTP_USER : get_option( 'admin_email' ) );

    $header        = statsight_base64url_encode( wp_json_encode( [ 'typ' => 'JWT', 'alg' => 'ES256' ] ) );
    $claims        = statsight_base64url_encode( wp_json_encode( [ 'aud' => $aud, 'exp' => $exp, 'sub' => $subject ] ) );
    $signing_input = $header . '.' . $claims;

    $raw_private = statsight_base64url_decode( $vapid_private );
    $ec_oid      = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    $key_der     = "\x02\x01\x01\x04\x20" . str_pad( $raw_private, 32, "\x00", STR_PAD_LEFT ) . "\xa0\x0a" . $ec_oid;
    $ec_wrap     = "\x30" . statsight_der_len( strlen( $key_der ) ) . $key_der;
    $pem         = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split( base64_encode( $ec_wrap ), 64, "\n" ) . "-----END EC PRIVATE KEY-----\n";

    $private_key = openssl_pkey_get_private( $pem );
    if ( ! $private_key ) {
        error_log( '[Statsight Push] Failed to load private key.' );
        return false;
    }

    $signature_der = '';
    openssl_sign( $signing_input, $signature_der, $private_key, OPENSSL_ALGO_SHA256 );
    $signature_raw = statsight_der_sig_to_raw( $signature_der );
    if ( ! $signature_raw ) {
        error_log( '[Statsight Push] Failed to convert signature.' );
        return false;
    }

    $jwt           = $signing_input . '.' . statsight_base64url_encode( $signature_raw );
    $authorization = 'vapid t=' . $jwt . ', k=' . $vapid_public;

    // ── 2. Encrypt payload (RFC 8291 aes128gcm) ───────────────────────────
    $encrypted = statsight_webpush_encrypt( $payload, $p256dh, $auth );
    if ( ! $encrypted ) {
        error_log( '[Statsight Push] Payload encryption failed.' );
        return false;
    }

    // ── 3. POST to push endpoint ──────────────────────────────────────────
    $response = wp_remote_post( $endpoint, [
        'timeout' => 10,
        'headers' => [
            'Authorization'   => $authorization,
            'Content-Type'    => 'application/octet-stream',
            'Content-Encoding' => 'aes128gcm',
            'TTL'             => '86400',
            'Urgency'         => 'high',
        ],
        'body'    => $encrypted,
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[Statsight Push] wp_remote_post error: ' . $response->get_error_message() );
        return false;
    }

    $code = wp_remote_retrieve_response_code( $response );
    error_log( '[Statsight Push] response code: ' . $code );
    if ( $code === 410 || $code === 404 ) {
        return false;
    }

    return $code >= 200 && $code < 300;
}

/**
 * Encrypt a Web Push payload using aes128gcm (RFC 8291 / RFC 8188).
 *
 * Returns the encrypted binary blob (header + ciphertext) ready to POST,
 * or null on failure. Requires only the openssl extension.
 *
 * Steps:
 *  1. Generate an ephemeral EC key pair (server side).
 *  2. ECDH with the subscriber's p256dh public key → shared secret.
 *  3. Derive content encryption key + nonce via HKDF-SHA256.
 *  4. Encrypt with AES-128-GCM, append padding delimiter (0x02).
 *  5. Prepend the aes128gcm content-coding header.
 */
function statsight_webpush_encrypt( string $plaintext, string $p256dh_b64, string $auth_b64 ): ?string {
    // Decode subscriber keys.
    $receiver_pub = statsight_base64url_decode( $p256dh_b64 ); // 65-byte uncompressed EC point
    $auth_secret  = statsight_base64url_decode( $auth_b64 );   // 16-byte auth secret

    // Generate ephemeral server EC key pair on prime256v1.
    $ephemeral    = openssl_pkey_new( [ 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ] );
    if ( ! $ephemeral ) {
        return null;
    }
    $ephemeral_details = openssl_pkey_get_details( $ephemeral );
    // Uncompressed public key: 0x04 || x || y (each 32 bytes).
    $server_pub_raw = "\x04"
        . str_pad( $ephemeral_details['ec']['x'], 32, "\x00", STR_PAD_LEFT )
        . str_pad( $ephemeral_details['ec']['y'], 32, "\x00", STR_PAD_LEFT );

    // Rebuild receiver public key as an OpenSSL key object.
    $receiver_pem = statsight_ec_pub_to_pem( $receiver_pub );
    if ( ! $receiver_pem ) {
        return null;
    }
    $receiver_key = openssl_pkey_get_public( $receiver_pem );
    if ( ! $receiver_key ) {
        return null;
    }

    // ECDH: compute shared secret (returns raw bytes, length = 32 for prime256v1).
    $shared_secret = openssl_pkey_derive( $receiver_key, $ephemeral, 32 );
    if ( ! $shared_secret ) {
        return null;
    }
    $shared_secret = str_pad( $shared_secret, 32, "\x00", STR_PAD_LEFT );

    // Random 16-byte salt.
    $salt = random_bytes( 16 );

    // HKDF-SHA256: extract pseudo-random key from shared secret + auth secret.
    // ikm  = HKDF-Extract(auth_secret, shared_secret)
    // Then build the content encryption key (CEK) and nonce via HKDF-Expand.
    //
    // Per RFC 8291 §3.3:
    //   PRK   = HKDF-Extract(auth_secret, ecdh_secret)
    //   key_info  = "WebPush: info\x00" || receiver_pub || server_pub
    //   IKM   = HKDF-Expand(PRK, key_info, 32)
    //   CEK   = HKDF-Expand(HKDF-Extract(salt, IKM), "Content-Encoding: aes128gcm\x00", 16)
    //   NONCE = HKDF-Expand(HKDF-Extract(salt, IKM), "Content-Encoding: nonce\x00", 12)

    $prk     = hash_hmac( 'sha256', $shared_secret, $auth_secret, true );
    $key_info = "WebPush: info\x00" . $receiver_pub . $server_pub_raw;
    $ikm     = statsight_hkdf_expand( $prk, $key_info, 32 );

    $prk2 = hash_hmac( 'sha256', $ikm, $salt, true );
    $cek  = statsight_hkdf_expand( $prk2, "Content-Encoding: aes128gcm\x00", 16 );
    $nonce = statsight_hkdf_expand( $prk2, "Content-Encoding: nonce\x00", 12 );

    // Encrypt: AES-128-GCM. Append 0x02 padding delimiter before encrypting.
    $tag        = '';
    $ciphertext = openssl_encrypt( $plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
    if ( $ciphertext === false ) {
        return null;
    }

    // aes128gcm content-coding header (RFC 8188 §2.1):
    // salt (16) || rs (4, big-endian uint32) || idlen (1) || keyid (server_pub, 65 bytes)
    $rs     = 4096; // record size
    $header = $salt
        . pack( 'N', $rs )
        . chr( strlen( $server_pub_raw ) )
        . $server_pub_raw;

    return $header . $ciphertext . $tag;
}

/**
 * HKDF-Expand (RFC 5869) using SHA-256.
 * Expands pseudo-random key $prk with $info to $length bytes.
 */
function statsight_hkdf_expand( string $prk, string $info, int $length ): string {
    $output = '';
    $block  = '';
    $i      = 0;
    while ( strlen( $output ) < $length ) {
        $block   = hash_hmac( 'sha256', $block . $info . chr( ++$i ), $prk, true );
        $output .= $block;
    }
    return substr( $output, 0, $length );
}

/**
 * Wrap a 65-byte uncompressed EC public key in a PEM SubjectPublicKeyInfo envelope
 * so OpenSSL can import it for ECDH.
 */
function statsight_ec_pub_to_pem( string $raw_pub ): ?string {
    if ( strlen( $raw_pub ) !== 65 || ord( $raw_pub[0] ) !== 0x04 ) {
        return null;
    }
    // SubjectPublicKeyInfo DER for prime256v1:
    // SEQUENCE { SEQUENCE { OID ecPublicKey, OID prime256v1 } BIT STRING { 0x00 || raw_pub } }
    $algo = "\x30\x13"                           // SEQUENCE (19 bytes)
          . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"  // OID ecPublicKey
          . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // OID prime256v1
    $bit_string = "\x03" . statsight_der_len( strlen( $raw_pub ) + 1 ) . "\x00" . $raw_pub;
    $spki       = "\x30" . statsight_der_len( strlen( $algo ) + strlen( $bit_string ) ) . $algo . $bit_string;

    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
}

function statsight_base64url_encode( string $data ): string {
    return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

function statsight_base64url_decode( string $data ): string {
    return base64_decode( strtr( $data, '-_', '+/' ) );
}

/** Returns a DER length encoding for the given byte count. */
function statsight_der_len( int $len ): string {
    if ( $len < 0x80 ) {
        return chr( $len );
    }
    $bytes = '';
    $tmp   = $len;
    while ( $tmp > 0 ) {
        $bytes = chr( $tmp & 0xff ) . $bytes;
        $tmp >>= 8;
    }
    return chr( 0x80 | strlen( $bytes ) ) . $bytes;
}

/**
 * Convert a DER-encoded ECDSA signature to raw r||s (32+32 bytes).
 */
function statsight_der_sig_to_raw( string $der ): ?string {
    // DER format: 0x30 [total-len] 0x02 [r-len] [r] 0x02 [s-len] [s]
    $pos = 0;
    if ( ord( $der[ $pos++ ] ) !== 0x30 ) return null;
    // Skip total length byte(s).
    $len_byte = ord( $der[ $pos++ ] );
    if ( $len_byte & 0x80 ) {
        $pos += $len_byte & 0x7f;
    }
    // Read r.
    if ( ord( $der[ $pos++ ] ) !== 0x02 ) return null;
    $r_len = ord( $der[ $pos++ ] );
    $r     = substr( $der, $pos, $r_len );
    $pos  += $r_len;
    // Read s.
    if ( ord( $der[ $pos++ ] ) !== 0x02 ) return null;
    $s_len = ord( $der[ $pos++ ] );
    $s     = substr( $der, $pos, $s_len );

    // Pad or trim each to exactly 32 bytes.
    $r = str_pad( ltrim( $r, "\x00" ), 32, "\x00", STR_PAD_LEFT );
    $s = str_pad( ltrim( $s, "\x00" ), 32, "\x00", STR_PAD_LEFT );

    return $r . $s;
}

/**
 * Format American odds for display in emails.
 */
function statsight_fmt_odds( int $odds ): string {
    return $odds > 0 ? '+' . $odds : (string) $odds;
}

/**
 * Given a props payload (from the transient) and a market key, return a flat
 * array of best-value opportunities: each entry has player, market_label, line,
 * best_book, best_odds, worst_odds, edge (pts).
 *
 * Edge = best_odds_line - worst_odds_line (American odds converted to implied).
 * We use simple point differential: best_line_value vs worst_line_value across books.
 */
function statsight_calc_best_value( array $payload, string $market_key, ?array $active_books = null ): array {
    $base_key    = preg_replace( '/_alternate$/', '', $market_key );
    $props       = $payload['props'] ?? [];
    $books       = $payload['books'] ?? [];
    $def_lines   = $payload['default_lines'] ?? [];
    $market_labels = $payload['market_labels'] ?? [];

    // Merge base + alternate.
    $market_data = $props[ $base_key ] ?? [];
    foreach ( $props[ $base_key . '_alternate' ] ?? [] as $player => $lines ) {
        foreach ( $lines as $lk => $bk_data ) {
            foreach ( $bk_data as $bk => $odds ) {
                if ( ! isset( $market_data[ $player ][ $lk ][ $bk ] ) ) {
                    $market_data[ $player ][ $lk ][ $bk ] = $odds;
                }
            }
        }
    }

    $results = [];
    foreach ( $market_data as $player => $lines ) {
        $default_line = $def_lines[ $base_key ][ $player ]
            ?? $def_lines[ $base_key . '_alternate' ][ $player ]
            ?? null;
        if ( $default_line === null || ! isset( $lines[ $default_line ] ) ) {
            continue;
        }

        $book_data = $lines[ $default_line ];

        // Collect over odds per book, restricted to the user's active books if set.
        $over_odds = [];
        foreach ( $book_data as $bk => $odds ) {
            if ( $active_books !== null && ! in_array( $bk, $active_books, true ) ) {
                continue;
            }
            if ( isset( $odds['over'] ) && $odds['over'] !== null ) {
                $over_odds[ $bk ] = (int) $odds['over'];
            }
        }
        if ( count( $over_odds ) < 2 ) {
            continue; // need at least 2 books to measure edge
        }

        // Edge = raw American odds difference between best and worst book,
        // matching the calculation used on the Best Value page.
        $best_odds  = max( $over_odds );
        $worst_odds = min( $over_odds );
        $edge       = $best_odds - $worst_odds;

        // Only show the row if the best odds (the favorable bet) is on a selected book.
        $best_bk  = array_search( $best_odds,  $over_odds, true );
        $worst_bk = array_search( $worst_odds, $over_odds, true );

        if ( $edge <= 0 ) {
            continue;
        }

        $ev = statsight_calc_ev( $over_odds );

        $results[] = [
            'player'       => $player,
            'market_key'   => $base_key,
            'market_label' => $market_labels[ $base_key ] ?? $base_key,
            'line'         => $default_line,
            'best_book'    => $books[ $best_bk ] ?? $best_bk,
            'best_bk_key'  => $best_bk,
            'best_odds'    => $best_odds,
            'worst_odds'   => $worst_odds,
            'edge'         => $edge,
            'ev'           => $ev,
        ];
    }

    return $results;
}

/**
 * Calculate EV% for a prop relative to the no-vig consensus line.
 * Returns EV% for the best-odds book, or null if insufficient data.
 *
 * EV% = (best_implied_prob × (1 + payout)) - 1, where payout is
 * derived from the no-vig mid across all books.
 */
function statsight_calc_ev( array $over_odds_by_book ): ?float {
    if ( count( $over_odds_by_book ) < 2 ) {
        return null;
    }

    $to_impl = function ( int $o ): float {
        return $o > 0 ? 100 / ( $o + 100 ) : abs( $o ) / ( abs( $o ) + 100 );
    };

    // No-vig mid: average implied across books.
    $impls  = array_map( fn( $o ) => $to_impl( (int) $o ), $over_odds_by_book );
    $avg    = array_sum( $impls ) / count( $impls );

    // Best (highest) odds book.
    arsort( $over_odds_by_book );
    $best_odds   = (int) reset( $over_odds_by_book );
    $best_impl   = $to_impl( $best_odds );

    // Payout = 1 / best_implied - 1
    $payout = ( 1 / $best_impl ) - 1;

    // EV% = (true_prob × payout) - (1 - true_prob)
    $ev = round( ( $avg * $payout ) - ( 1 - $avg ), 4 ) * 100;

    return round( $ev, 2 );
}

/**
 * Main cron callback — evaluate every user's notification rules against
 * the current props data and send emails where thresholds are met.
 *
 * Runs every 10 minutes, 30 seconds after the props refresh cron.
 */
function statsight_cron_send_notifications(): void {
    // Re-schedule next run at the next 1-minute boundary.
    wp_schedule_single_event( statsight_next_1min_boundary(), 'statsight_send_notifications' );

    error_log( '[Statsight Notif] cron fired at ' . gmdate( 'Y-m-d H:i:s' ) );

    // Global kill switch via ACF option.
    if ( ! get_field( 'email_notifications', 'option' ) ) {
        error_log( '[Statsight Notif] email_notifications ACF option is disabled — aborting.' );
        return;
    }

    statsight_notif_purge_old_sent();

    // Resolve CLV for any watchlist props whose games have started.
    statsight_resolve_clv_all_pending();

    $today    = gmdate( 'Y-m-d' );
    $now_hour = (int) gmdate( 'G' ); // 0-23 UTC

    // Collect all users who have saved notification rules.
    $users_with_rules = get_users( [
        'meta_key'     => 'statsight_notif_rules',
        'meta_compare' => 'EXISTS',
        'fields'       => [ 'ID', 'user_email', 'display_name' ],
        'number'       => -1,
    ] );

    error_log( '[Statsight Notif] users with rules: ' . count( $users_with_rules ) );

    if ( empty( $users_with_rules ) ) {
        return;
    }

    // Build a list of all sports with cached events so we don't re-query per user.
    $allowed_sports = [
        'basketball_nba', 'americanfootball_nfl', 'americanfootball_ncaaf',
        'basketball_ncaab', 'baseball_mlb', 'icehockey_nhl', 'mma_mixed_martial_arts',
        'soccer_epl', 'soccer_usa_mls', 'basketball_wnba',
    ];

    // Gather all events across all sports.
    $all_events = []; // keyed by event_id => event array + 'sport' key
    foreach ( $allowed_sports as $sport ) {
        $events_cache = get_transient( 'statsight_events_' . $sport );
        foreach ( $events_cache['days'] ?? [] as $day ) {
            foreach ( $day['events'] ?? [] as $event ) {
                $event['sport']                   = $sport;
                $all_events[ $event['id'] ?? '' ] = $event;
            }
        }
    }

    error_log( '[Statsight Notif] events found: ' . count( $all_events ) );

    // Load props for every event — fetch fresh from API if cache is cold.
    // Arbitrage windows close fast so we always want current data.
    $payloads = []; // event_id => payload
    foreach ( $all_events as $event_id => $event ) {
        $cached = get_transient( 'statsight_props2_' . $event_id );
        if ( $cached ) {
            $payloads[ $event_id ] = $cached;
        } else {
            $fetched = statsight_fetch_and_cache_props( $event['sport'], $event_id );
            if ( $fetched && ! is_wp_error( $fetched ) ) {
                $payloads[ $event_id ] = $fetched;
            }
        }
    }

    error_log( '[Statsight Notif] payloads cached: ' . count( $payloads ) );

    foreach ( $users_with_rules as $user ) {
        $user_id = (int) $user->ID;

        $raw   = get_user_meta( $user_id, 'statsight_notif_rules', true );
        $rules = $raw ? json_decode( $raw, true ) : [];

        if ( empty( $rules ) || ! is_array( $rules ) ) {
            continue;
        }

        // Respect the user's active book selection so notifications only fire
        // for books they can actually bet on.
        $active_books_raw  = get_user_meta( $user_id, 'statsight_active_books', true );
        $user_active_books = $active_books_raw ? json_decode( $active_books_raw, true ) : null;

        error_log( '[Statsight Notif] evaluating ' . count( $rules ) . ' rule(s) for user ' . $user_id );

        // Cap push notifications per cron run to avoid browser throttling.
        $push_sent_this_run = 0;
        $push_limit_per_run = 3;

        // Accumulate daily digest props separately.
        $digest_entries = [];

        foreach ( $rules as $rule ) {
            $rule_id   = $rule['id']   ?? '';
            $rule_type = $rule['type'] ?? '';
            $threshold = isset( $rule['threshold'] ) ? (float) $rule['threshold'] : 0;
            $sport_filter = $rule['sport'] ?? 'all';

            if ( empty( $rule_id ) || empty( $rule_type ) ) {
                continue;
            }

            // ── daily_digest: collect once, send at 8am–10am UTC ──────────
            if ( $rule_type === 'daily_digest' ) {
                if ( $now_hour < 8 || $now_hour >= 10 ) {
                    continue;
                }
                $fingerprint = 'digest|' . $today;
                if ( statsight_notif_already_sent( $user_id, $rule_id, $fingerprint ) ) {
                    continue;
                }

                // Gather top-edge props for the digest.
                $top_props = [];
                foreach ( $payloads as $event_id => $payload ) {
                    $event = $all_events[ $event_id ] ?? [];
                    if ( $sport_filter !== 'all' && ( $event['sport'] ?? '' ) !== $sport_filter ) {
                        continue;
                    }
                    foreach ( $payload['props'] ?? [] as $market_key => $_ ) {
                        if ( str_ends_with( $market_key, '_alternate' ) ) continue;
                        $bv_rows = statsight_calc_best_value( $payload, $market_key, $user_active_books );
                        foreach ( $bv_rows as $row ) {
                            $row['matchup'] = ( $event['away_team'] ?? '' ) . ' @ ' . ( $event['home_team'] ?? '' );
                            $top_props[]    = $row;
                        }
                    }
                }

                if ( empty( $top_props ) ) {
                    continue;
                }

                // Sort by edge desc, take top 10.
                usort( $top_props, fn( $a, $b ) => $b['edge'] <=> $a['edge'] );
                $digest_entries[ $rule_id ] = [
                    'fingerprint' => $fingerprint,
                    'props'       => array_slice( $top_props, 0, 10 ),
                    'sport'       => $sport_filter,
                ];
                continue;
            }

            // ── Per-event rule evaluation ──────────────────────────────────
            foreach ( $payloads as $event_id => $payload ) {
                $event = $all_events[ $event_id ] ?? [];

                if ( $sport_filter !== 'all' && ( $event['sport'] ?? '' ) !== $sport_filter ) {
                    continue;
                }

                $matchup = ( $event['away_team'] ?? '' ) . ' @ ' . ( $event['home_team'] ?? '' );
                $props   = $payload['props'] ?? [];

                // ── best_value ────────────────────────────────────────────
                if ( $rule_type === 'best_value' ) {
                    foreach ( array_keys( $props ) as $market_key ) {
                        if ( str_ends_with( $market_key, '_alternate' ) ) continue;

                        $bv_rows = statsight_calc_best_value( $payload, $market_key, $user_active_books );
                        foreach ( $bv_rows as $row ) {
                            if ( $row['edge'] < $threshold ) continue;

                            // Fingerprint: one alert per player+market per day.
                            $fingerprint = implode( '|', [ $event_id, $row['player'], $row['market_key'], $today ] );
                            error_log( "[Statsight Notif] best_value candidate: {$row['player']} {$row['market_key']} edge={$row['edge']} threshold={$threshold} fp={$fingerprint}" );
                            if ( statsight_notif_already_sent( $user_id, $rule_id, $fingerprint ) ) {
                                error_log( '[Statsight Notif] already sent — skipping.' );
                                continue;
                            }

                            $subject = "Best Value: {$row['player']} {$row['market_label']}";
                            $body    = "<h2 style='margin:0 0 8px;font-size:1.1rem;font-weight:700;color:#f0f2f7;'>Best Value Alert</h2>
                                <p style='margin:0 0 16px;color:rgba(240,242,247,0.6);font-size:0.875rem;'>{$matchup}</p>
                                <table style='width:100%;border-collapse:collapse;font-size:0.9rem;'>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Player</td><td style='padding:6px 0;font-weight:600;'>{$row['player']}</td></tr>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Market</td><td style='padding:6px 0;'>{$row['market_label']} {$row['line']}</td></tr>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Best Odds</td><td style='padding:6px 0;color:#2e7cf6;font-weight:700;'>" . statsight_fmt_odds( $row['best_odds'] ) . " ({$row['best_book']})</td></tr>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Edge</td><td style='padding:6px 0;color:#22c55e;font-weight:700;'>{$row['edge']}pp</td></tr>
                                </table>
                                <a href='" . esc_url( home_url( '/props/' ) ) . "' style='display:inline-block;margin-top:20px;padding:10px 20px;background:#2e7cf6;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:0.9rem;'>View Props &rarr;</a>";

                            if ( ! statsight_notif_mark_sent( $user_id, $rule_id, $fingerprint ) ) continue;
                            $sent = statsight_send_notif_email( $user_id, $subject, $body );
                            error_log( '[Statsight Notif] wp_mail result: ' . ( $sent ? 'sent' : 'FAILED' ) );
                            if ( $push_sent_this_run < $push_limit_per_run ) {
                                $push_sent_this_run += statsight_send_push_to_user( $user_id, $subject, "{$row['player']} {$row['market_label']} {$row['line']} — {$row['edge']}pp edge", home_url( '/best-value/' ) );
                            }
                        }
                    }
                }

                // ── arbitrage ─────────────────────────────────────────────
                elseif ( $rule_type === 'arbitrage' ) {
                    foreach ( array_keys( $props ) as $market_key ) {
                        if ( str_ends_with( $market_key, '_alternate' ) ) continue;

                        $base_key    = preg_replace( '/_alternate$/', '', $market_key );
                        $market_data = $props[ $base_key ] ?? [];
                        $def_lines   = $payload['default_lines'] ?? [];
                        $books       = $payload['books'] ?? [];

                        foreach ( $market_data as $player => $lines ) {
                            $default_line = $def_lines[ $base_key ][ $player ] ?? null;
                            if ( ! $default_line || ! isset( $lines[ $default_line ] ) ) continue;

                            $book_data = $lines[ $default_line ];

                            // Arbitrage: best over + best under < 100% combined implied.
                            $best_over  = null; $best_over_bk  = '';
                            $best_under = null; $best_under_bk = '';

                            foreach ( $book_data as $bk => $odds ) {
                                if ( $user_active_books !== null && ! in_array( $bk, $user_active_books, true ) ) {
                                    continue;
                                }
                                if ( isset( $odds['over'] ) && $odds['over'] !== null ) {
                                    if ( $best_over === null || $odds['over'] > $best_over ) {
                                        $best_over    = (int) $odds['over'];
                                        $best_over_bk = $bk;
                                    }
                                }
                                if ( isset( $odds['under'] ) && $odds['under'] !== null ) {
                                    if ( $best_under === null || $odds['under'] > $best_under ) {
                                        $best_under    = (int) $odds['under'];
                                        $best_under_bk = $bk;
                                    }
                                }
                            }

                            if ( $best_over === null || $best_under === null ) continue;

                            $impl_over  = $best_over  > 0 ? 100 / ( $best_over  + 100 ) : abs( $best_over )  / ( abs( $best_over )  + 100 );
                            $impl_under = $best_under > 0 ? 100 / ( $best_under + 100 ) : abs( $best_under ) / ( abs( $best_under ) + 100 );
                            $combined   = ( $impl_over + $impl_under ) * 100;
                            $arb_pct    = round( 100 - $combined, 2 );

                            if ( $arb_pct < $threshold ) continue;

                            $market_labels = $payload['market_labels'] ?? [];
                            $market_label  = $market_labels[ $base_key ] ?? $base_key;

                            $fingerprint = implode( '|', [ $event_id, $player, $base_key, $today ] );
                            if ( statsight_notif_already_sent( $user_id, $rule_id, $fingerprint ) ) continue;

                            $over_book_label  = $books[ $best_over_bk ]  ?? $best_over_bk;
                            $under_book_label = $books[ $best_under_bk ] ?? $best_under_bk;

                            $subject = "Arbitrage: {$player} {$market_label}";
                            $body    = "<h2 style='margin:0 0 8px;font-size:1.1rem;font-weight:700;color:#f0f2f7;'>Arbitrage Opportunity</h2>
                                <p style='margin:0 0 16px;color:rgba(240,242,247,0.6);font-size:0.875rem;'>{$matchup}</p>
                                <table style='width:100%;border-collapse:collapse;font-size:0.9rem;'>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Player</td><td style='padding:6px 0;font-weight:600;'>{$player}</td></tr>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Market</td><td style='padding:6px 0;'>{$market_label} {$default_line}</td></tr>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Best Over</td><td style='padding:6px 0;'>" . statsight_fmt_odds( $best_over ) . " ({$over_book_label})</td></tr>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Best Under</td><td style='padding:6px 0;'>" . statsight_fmt_odds( $best_under ) . " ({$under_book_label})</td></tr>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Arb %</td><td style='padding:6px 0;color:#22c55e;font-weight:700;'>{$arb_pct}%</td></tr>
                                </table>
                                <a href='" . esc_url( home_url( '/props/' ) ) . "' style='display:inline-block;margin-top:20px;padding:10px 20px;background:#2e7cf6;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:0.9rem;'>View Props &rarr;</a>";

                            if ( ! statsight_notif_mark_sent( $user_id, $rule_id, $fingerprint ) ) continue;
                            $email_sent = statsight_send_notif_email( $user_id, $subject, $body );
                            if ( $push_sent_this_run < $push_limit_per_run ) {
                                $push_sent_this_run += statsight_send_push_to_user( $user_id, $subject, "{$player} {$market_label} {$default_line} — {$arb_pct}% arb", home_url( '/props/' ) );
                            }
                        }
                    }
                }

                // ── ev_threshold ──────────────────────────────────────────
                elseif ( $rule_type === 'ev_threshold' ) {
                    foreach ( array_keys( $props ) as $market_key ) {
                        if ( str_ends_with( $market_key, '_alternate' ) ) continue;

                        $base_key    = preg_replace( '/_alternate$/', '', $market_key );
                        $market_data = $props[ $base_key ] ?? [];
                        $def_lines   = $payload['default_lines'] ?? [];
                        $books       = $payload['books'] ?? [];
                        $market_labels = $payload['market_labels'] ?? [];

                        foreach ( $market_data as $player => $lines ) {
                            $default_line = $def_lines[ $base_key ][ $player ] ?? null;
                            if ( ! $default_line || ! isset( $lines[ $default_line ] ) ) continue;

                            $over_odds = [];
                            foreach ( $lines[ $default_line ] as $bk => $odds ) {
                                if ( isset( $odds['over'] ) && $odds['over'] !== null ) {
                                    $over_odds[ $bk ] = (int) $odds['over'];
                                }
                            }

                            $ev = statsight_calc_ev( $over_odds );
                            if ( $ev === null || $ev < $threshold ) continue;

                            $market_label = $market_labels[ $base_key ] ?? $base_key;
                            arsort( $over_odds );
                            $best_bk   = array_key_first( $over_odds );
                            $best_odds = $over_odds[ $best_bk ];

                            $fingerprint = implode( '|', [ $event_id, $player, $base_key, $today ] );
                            if ( statsight_notif_already_sent( $user_id, $rule_id, $fingerprint ) ) continue;

                            $ev_book_label = $books[ $best_bk ] ?? $best_bk;

                            $subject = "EV Alert: {$player} {$market_label}";
                            $body    = "<h2 style='margin:0 0 8px;font-size:1.1rem;font-weight:700;color:#f0f2f7;'>EV% Alert</h2>
                                <p style='margin:0 0 16px;color:rgba(240,242,247,0.6);font-size:0.875rem;'>{$matchup}</p>
                                <table style='width:100%;border-collapse:collapse;font-size:0.9rem;'>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Player</td><td style='padding:6px 0;font-weight:600;'>{$player}</td></tr>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Market</td><td style='padding:6px 0;'>{$market_label} {$default_line}</td></tr>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Best Odds</td><td style='padding:6px 0;color:#2e7cf6;font-weight:700;'>" . statsight_fmt_odds( $best_odds ) . " ({$ev_book_label})</td></tr>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>EV%</td><td style='padding:6px 0;color:#22c55e;font-weight:700;'>{$ev}%</td></tr>
                                </table>
                                <a href='" . esc_url( home_url( '/props/' ) ) . "' style='display:inline-block;margin-top:20px;padding:10px 20px;background:#2e7cf6;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:0.9rem;'>View Props &rarr;</a>";

                            if ( ! statsight_notif_mark_sent( $user_id, $rule_id, $fingerprint ) ) continue;
                            $email_sent = statsight_send_notif_email( $user_id, $subject, $body );
                            if ( $push_sent_this_run < $push_limit_per_run ) {
                                $push_sent_this_run += statsight_send_push_to_user( $user_id, $subject, "{$player} {$market_label} {$default_line} — {$ev}% EV", home_url( '/props/' ) );
                            }
                        }
                    }
                }

                // ── line_move ─────────────────────────────────────────────
                elseif ( $rule_type === 'line_move' ) {
                    global $wpdb;
                    $history_table = $wpdb->prefix . 'statsight_odds_history';
                    $def_lines     = $payload['default_lines'] ?? [];
                    $market_labels = $payload['market_labels'] ?? [];
                    $books         = $payload['books'] ?? [];

                    foreach ( array_keys( $props ) as $market_key ) {
                        if ( str_ends_with( $market_key, '_alternate' ) ) continue;

                        $base_key    = preg_replace( '/_alternate$/', '', $market_key );
                        $market_data = $props[ $base_key ] ?? [];

                        foreach ( $market_data as $player => $lines ) {
                            $current_line = $def_lines[ $base_key ][ $player ] ?? null;
                            if ( ! $current_line ) continue;

                            // Find the line from 20–90 minutes ago to detect movement.
                            $prev = $wpdb->get_var( $wpdb->prepare(
                                "SELECT line FROM {$history_table}
                                 WHERE event_id = %s AND market_key = %s AND player = %s
                                   AND recorded_at BETWEEN %s AND %s
                                 ORDER BY recorded_at DESC LIMIT 1",
                                $event_id,
                                $base_key,
                                $player,
                                gmdate( 'Y-m-d H:i:s', time() - 90 * MINUTE_IN_SECONDS ),
                                gmdate( 'Y-m-d H:i:s', time() - 20 * MINUTE_IN_SECONDS )
                            ) );

                            if ( $prev === null ) continue;

                            $move = abs( (float) $current_line - (float) $prev );
                            if ( $move < $threshold ) continue;

                            $market_label = $market_labels[ $base_key ] ?? $base_key;

                            // Fingerprint includes the specific from→to so each distinct move alerts once.
                            $fingerprint = implode( '|', [ $event_id, $player, $base_key, $prev, $current_line ] );
                            if ( statsight_notif_already_sent( $user_id, $rule_id, $fingerprint ) ) continue;

                            $direction = (float) $current_line > (float) $prev ? '&uarr;' : '&darr;';

                            $subject = "Line Move: {$player} {$market_label}";
                            $body    = "<h2 style='margin:0 0 8px;font-size:1.1rem;font-weight:700;color:#f0f2f7;'>Line Move Alert</h2>
                                <p style='margin:0 0 16px;color:rgba(240,242,247,0.6);font-size:0.875rem;'>{$matchup}</p>
                                <table style='width:100%;border-collapse:collapse;font-size:0.9rem;'>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Player</td><td style='padding:6px 0;font-weight:600;'>{$player}</td></tr>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Market</td><td style='padding:6px 0;'>{$market_label}</td></tr>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Line</td><td style='padding:6px 0;'>{$prev} {$direction} <strong>{$current_line}</strong></td></tr>
                                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Move</td><td style='padding:6px 0;color:#f59e0b;font-weight:700;'>{$move} pts</td></tr>
                                </table>
                                <a href='" . esc_url( home_url( '/props/' ) ) . "' style='display:inline-block;margin-top:20px;padding:10px 20px;background:#2e7cf6;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:0.9rem;'>View Props &rarr;</a>";

                            if ( ! statsight_notif_mark_sent( $user_id, $rule_id, $fingerprint ) ) continue;
                            $email_sent = statsight_send_notif_email( $user_id, $subject, $body );
                            if ( $push_sent_this_run < $push_limit_per_run ) {
                                $push_sent_this_run += statsight_send_push_to_user( $user_id, $subject, "{$player} {$market_label}: {$prev} → {$current_line}", home_url( '/props/' ) );
                            }
                        }
                    }
                }

                // ── watchlist_result: handled separately below ────────────
            }

            // ── watchlist_result ──────────────────────────────────────────
            if ( $rule_type === 'watchlist_result' ) {
                global $wpdb;
                $outcome_filter = $rule['outcome'] ?? 'any';
                $wl_table       = $wpdb->prefix . 'statsight_watchlist';

                $wl_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM {$wl_table} WHERE user_id = %d",
                    $user_id
                ), ARRAY_A );

                foreach ( $wl_rows as $wl ) {
                    $event_id = $wl['event_id'];
                    if ( $sport_filter !== 'all' && ( $all_events[ $event_id ]['sport'] ?? '' ) !== $sport_filter ) {
                        continue;
                    }

                    // Only alert if game has ended (no longer in active events list).
                    if ( isset( $all_events[ $event_id ] ) ) {
                        continue; // game still upcoming/live
                    }

                    // Check if we have a settled result in the history table.
                    $history_table = $wpdb->prefix . 'statsight_odds_history';
                    $last_odds = $wpdb->get_row( $wpdb->prepare(
                        "SELECT over_odds, under_odds FROM {$history_table}
                         WHERE event_id = %s AND market_key = %s AND player = %s AND line = %s AND book_key = %s
                         ORDER BY recorded_at DESC LIMIT 1",
                        $event_id,
                        $wl['market_key'],
                        $wl['player'],
                        $wl['line'],
                        $wl['book']
                    ), ARRAY_A );

                    if ( ! $last_odds ) continue;

                    // Game has ended — notify user to check their watchlist for the result.
                    $fingerprint = implode( '|', [ $event_id, $wl['player'], $wl['market_key'], $wl['line'], 'settled' ] );
                    if ( statsight_notif_already_sent( $user_id, $rule_id, $fingerprint ) ) continue;

                    $tracked_odds = $wl['direction'] === 'over'
                        ? statsight_fmt_odds( (int) ( $last_odds['over_odds'] ?? $wl['odds'] ) )
                        : statsight_fmt_odds( (int) ( $last_odds['under_odds'] ?? $wl['odds'] ) );

                    $subject = "Game Ended: {$wl['player']} {$wl['market_label']}";
                    $body    = "<h2 style='margin:0 0 8px;font-size:1.1rem;font-weight:700;color:#f0f2f7;'>Watchlist Prop Settled</h2>
                        <p style='margin:0 0 16px;color:rgba(240,242,247,0.6);font-size:0.875rem;'>{$wl['matchup']}</p>
                        <table style='width:100%;border-collapse:collapse;font-size:0.9rem;'>
                          <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Player</td><td style='padding:6px 0;font-weight:600;'>{$wl['player']}</td></tr>
                          <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Market</td><td style='padding:6px 0;'>{$wl['market_label']} {$wl['line']}</td></tr>
                          <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Direction</td><td style='padding:6px 0;'>" . ucfirst( $wl['direction'] ) . "</td></tr>
                          <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Closing Odds</td><td style='padding:6px 0;'>{$tracked_odds} ({$wl['book']})</td></tr>
                        </table>
                        <a href='" . esc_url( home_url( '/watchlist/' ) ) . "' style='display:inline-block;margin-top:20px;padding:10px 20px;background:#2e7cf6;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:0.9rem;'>View Watchlist &rarr;</a>";

                    if ( ! statsight_notif_mark_sent( $user_id, $rule_id, $fingerprint ) ) continue;
                    $email_sent = statsight_send_notif_email( $user_id, $subject, $body );
                    if ( $push_sent_this_run < $push_limit_per_run ) {
                        $push_sent_this_run += statsight_send_push_to_user( $user_id, $subject, "{$wl['player']} {$wl['market_label']} {$wl['line']} — game settled", home_url( '/watchlist/' ) );
                    }
                }
            }
        } // end foreach rules

        // ── Send daily digest if any accumulated ──────────────────────────
        foreach ( $digest_entries as $rule_id => $digest ) {
            $rows_html = '';
            foreach ( $digest['props'] as $row ) {
                $rows_html .= "<tr>
                    <td style='padding:8px 6px;border-bottom:1px solid rgba(255,255,255,0.05);font-weight:600;'>{$row['player']}</td>
                    <td style='padding:8px 6px;border-bottom:1px solid rgba(255,255,255,0.05);color:rgba(240,242,247,0.7);font-size:0.85rem;'>{$row['market_label']} {$row['line']}</td>
                    <td style='padding:8px 6px;border-bottom:1px solid rgba(255,255,255,0.05);color:#2e7cf6;font-weight:700;'>" . statsight_fmt_odds( $row['best_odds'] ) . "</td>
                    <td style='padding:8px 6px;border-bottom:1px solid rgba(255,255,255,0.05);font-size:0.85rem;color:rgba(240,242,247,0.6);'>{$row['best_book']}</td>
                    <td style='padding:8px 6px;border-bottom:1px solid rgba(255,255,255,0.05);color:#22c55e;font-weight:700;'>{$row['edge']}pp</td>
                </tr>";
            }

            $subject = 'Your Daily Best Value Digest — ' . gmdate( 'M j' );
            $body    = "<h2 style='margin:0 0 4px;font-size:1.1rem;font-weight:700;color:#f0f2f7;'>Daily Best Value Digest</h2>
                <p style='margin:0 0 20px;color:rgba(240,242,247,0.5);font-size:0.85rem;'>Top props by edge for today</p>
                <table style='width:100%;border-collapse:collapse;font-size:0.875rem;'>
                  <thead>
                    <tr style='color:rgba(240,242,247,0.4);font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;'>
                      <th style='padding:4px 6px 8px;text-align:left;'>Player</th>
                      <th style='padding:4px 6px 8px;text-align:left;'>Market</th>
                      <th style='padding:4px 6px 8px;text-align:left;'>Odds</th>
                      <th style='padding:4px 6px 8px;text-align:left;'>Book</th>
                      <th style='padding:4px 6px 8px;text-align:left;'>Edge</th>
                    </tr>
                  </thead>
                  <tbody>{$rows_html}</tbody>
                </table>
                <a href='" . esc_url( home_url( '/best-value/' ) ) . "' style='display:inline-block;margin-top:20px;padding:10px 20px;background:#2e7cf6;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:0.9rem;'>View Best Value &rarr;</a>";

            if ( ! statsight_notif_mark_sent( $user_id, $rule_id, $digest['fingerprint'] ) ) continue;
            $email_sent = statsight_send_notif_email( $user_id, $subject, $body );
            if ( $push_sent_this_run < $push_limit_per_run ) {
                $push_sent_this_run += statsight_send_push_to_user( $user_id, $subject, 'Your top props by edge for today are ready.', home_url( '/best-value/' ) );
            }
        }
        // ── Prop alerts ───────────────────────────────────────────────────
        // Evaluate each user's active prop alerts against current live odds.
        global $wpdb;
        $alerts_table = $wpdb->prefix . 'statsight_prop_alerts';
        $active_alerts = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$alerts_table} WHERE user_id = %d AND triggered = 0",
            $user_id
        ), ARRAY_A );

        foreach ( $active_alerts as $alert ) {
            $a_event_id   = $alert['event_id'];
            $a_player     = $alert['player'];
            $a_market_key = $alert['market_key'];
            $a_line       = $alert['line'];
            $a_direction  = $alert['direction']; // 'over' or 'under'
            $a_target     = (int) $alert['target_odds'];
            $a_matchup    = $alert['matchup'];
            $a_label      = $alert['market_label'];

            $payload = $payloads[ $a_event_id ] ?? null;
            if ( ! $payload ) continue;

            $line_data = $payload['props'][ $a_market_key ][ $a_player ][ $a_line ]
                      ?? $payload['props'][ $a_market_key . '_alternate' ][ $a_player ][ $a_line ]
                      ?? null;
            if ( ! $line_data ) continue;

            // Find the best current odds for the specified direction across all books.
            $best_odds = null;
            $best_bk   = '';
            foreach ( $line_data as $bk => $odds ) {
                $val = $a_direction === 'over' ? ( $odds['over'] ?? null ) : ( $odds['under'] ?? null );
                if ( $val === null ) continue;
                if ( $best_odds === null || (int) $val > $best_odds ) {
                    $best_odds = (int) $val;
                    $best_bk   = $bk;
                }
            }

            if ( $best_odds === null ) continue;

            // Alert fires when best available odds meets or exceeds the target.
            if ( $best_odds < $a_target ) continue;

            $book_labels  = $payload['books'] ?? [];
            $book_display = $book_labels[ $best_bk ] ?? $best_bk;
            $odds_display = statsight_fmt_odds( $best_odds );
            $target_display = statsight_fmt_odds( $a_target );

            $subject = "Odds Alert: {$a_player} {$a_label} {$a_line}";
            $body    = "<h2 style='margin:0 0 8px;font-size:1.1rem;font-weight:700;color:#f0f2f7;'>Odds Alert Triggered</h2>
                <p style='margin:0 0 16px;color:rgba(240,242,247,0.6);font-size:0.875rem;'>{$a_matchup}</p>
                <table style='width:100%;border-collapse:collapse;font-size:0.9rem;'>
                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Player</td><td style='padding:6px 0;font-weight:600;'>{$a_player}</td></tr>
                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Prop</td><td style='padding:6px 0;'>{$a_label} {$a_line} (" . ucfirst( $a_direction ) . ")</td></tr>
                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Best Odds</td><td style='padding:6px 0;color:#2e7cf6;font-weight:700;'>{$odds_display} @ {$book_display}</td></tr>
                  <tr><td style='padding:6px 0;color:rgba(240,242,247,0.5);'>Your Target</td><td style='padding:6px 0;'>{$target_display} or better</td></tr>
                </table>
                <a href='" . esc_url( home_url( '/props/' ) ) . "' style='display:inline-block;margin-top:20px;padding:10px 20px;background:#2e7cf6;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:0.9rem;'>View Props &rarr;</a>";

            // Mark triggered so it only fires once.
            $wpdb->update( $alerts_table, [ 'triggered' => 1 ], [ 'id' => (int) $alert['id'] ] );

            statsight_send_notif_email( $user_id, $subject, $body );
            if ( $push_sent_this_run < $push_limit_per_run ) {
                $push_sent_this_run += statsight_send_push_to_user(
                    $user_id,
                    $subject,
                    "{$a_player} {$a_label} {$a_line} hit {$odds_display} @ {$book_display} — your target was {$target_display}",
                    home_url( '/props/' )
                );
            }
        }

    } // end foreach users
}
add_action( 'statsight_send_notifications', 'statsight_cron_send_notifications' );

/**
 * Manual cron trigger URL: /?statsight_run_notif=<key>
 * Key is defined in ACF options or falls back to a hard-coded dev key.
 */
add_action( 'init', function (): void {
    $key = sanitize_text_field( $_GET['statsight_run_notif'] ?? '' );
    if ( ! $key ) return;

    $valid_key = get_option( 'statsight_notif_trigger_key', 'dev-trigger-2024' );
    if ( ! hash_equals( $valid_key, $key ) ) {
        wp_die( 'Invalid key.', 403 );
    }

    // Capture error_log output during the run.
    $log_file = '/tmp/statsight_notif_test.log';
    @unlink( $log_file );
    $prev_log = ini_set( 'error_log', $log_file );

    $start = microtime( true );
    statsight_cron_send_notifications();
    $elapsed = round( microtime( true ) - $start, 2 );

    ini_set( 'error_log', $prev_log );
    $log_output = @file_get_contents( $log_file ) ?: '(no log output)';

    wp_die(
        '<pre style="font-family:monospace;font-size:13px;white-space:pre-wrap;">'
        . 'Ran in ' . $elapsed . "s\n\n"
        . esc_html( $log_output )
        . '</pre>',
        'Notification Test',
        [ 'response' => 200 ]
    );
} );

// ── AI Analysis ──────────────────────────────────────────────────────────────

/**
 * Fetch consensus spread and game total for an event from The Odds API.
 * Cached 10 minutes — lines move but don't need to be real-time for AI context.
 *
 * @return array{ spread_favorite: string, spread_line: float, total: float }|null
 */
function statsight_ai_game_lines( string $sport, string $event_id ): ?array {
    if ( ! defined( 'THE_ODDS_API_KEY' ) || ! THE_ODDS_API_KEY ) return null;

    $cache_key = 'statsight_ai_lines_' . $event_id;
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) return $cached ?: null;

    $url = 'https://api.the-odds-api.com/v4/sports/' . $sport . '/events/' . $event_id . '/odds?' . http_build_query( [
        'apiKey'     => THE_ODDS_API_KEY,
        'regions'    => 'us',
        'markets'    => 'spreads,totals',
        'oddsFormat' => 'american',
    ] );

    $response = wp_remote_get( $url, [ 'timeout' => 10 ] );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        set_transient( $cache_key, false, 2 * MINUTE_IN_SECONDS );
        return null;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    // Average the spread and total across all bookmakers for a consensus line.
    $spread_points = [];
    $spread_favs   = [];
    $total_points  = [];

    foreach ( $data['bookmakers'] ?? [] as $bm ) {
        foreach ( $bm['markets'] ?? [] as $market ) {
            if ( $market['key'] === 'spreads' ) {
                foreach ( $market['outcomes'] ?? [] as $outcome ) {
                    $pt = $outcome['point'] ?? null;
                    if ( $pt === null ) continue;
                    $spread_points[] = abs( (float) $pt );
                    if ( (float) $pt < 0 ) {
                        $spread_favs[] = $outcome['name'] ?? '';
                    }
                }
            } elseif ( $market['key'] === 'totals' ) {
                foreach ( $market['outcomes'] ?? [] as $outcome ) {
                    $pt = $outcome['point'] ?? null;
                    if ( $pt !== null ) $total_points[] = (float) $pt;
                }
            }
        }
    }

    if ( empty( $spread_points ) && empty( $total_points ) ) {
        set_transient( $cache_key, false, 2 * MINUTE_IN_SECONDS );
        return null;
    }

    // Most common favorite by name vote
    $fav_counts = array_count_values( $spread_favs );
    arsort( $fav_counts );
    $favorite = array_key_first( $fav_counts ) ?? '';

    $result = [
        'spread_favorite' => $favorite,
        'spread_line'     => ! empty( $spread_points ) ? round( array_sum( $spread_points ) / count( $spread_points ), 1 ) : null,
        'total'           => ! empty( $total_points )  ? round( array_sum( $total_points )  / count( $total_points ),  1 ) : null,
    ];

    set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );
    return $result;
}

/**
 * Fetch injury report and positions for both teams from cached roster data.
 * Positions come from two sources (in priority order):
 *   1. The per-player position cache populated during gamelog fetches.
 *   2. The roster cache (only if positions were stored there).
 * Only reads from already-cached transients — no new HTTP calls.
 *
 * @return array{
 *   injuries:  array<string, string>,
 *   positions: array<string, string>
 * }
 */
function statsight_ai_roster_context( string $sport, string $home, string $away ): array {
    $cache_key = 'statsight_rosters_v2_' . md5( $sport . $home . $away );
    $cached    = get_transient( $cache_key );

    $injuries  = [];
    $positions = [];

    if ( is_array( $cached ) ) {
        foreach ( [ 'home', 'away' ] as $side ) {
            foreach ( $cached[ $side ]['injuries'] ?? [] as $player => $status ) {
                $injuries[ $player ] = $status;
            }
            // Positions stored in roster cache (populated by updated fetch logic below).
            foreach ( $cached[ $side ]['positions'] ?? [] as $player => $pos ) {
                $positions[ $player ] = $pos;
            }
            // Fill any gaps from the per-player position cache.
            foreach ( $cached[ $side ]['players'] ?? [] as $player ) {
                if ( isset( $positions[ $player ] ) ) continue;
                $pos_key = 'statsight_player_pos_' . md5( $sport . $player );
                $pos     = get_transient( $pos_key );
                if ( $pos && $pos !== '' ) {
                    $positions[ $player ] = $pos;
                }
            }
        }
    }

    return [ 'injuries' => $injuries, 'positions' => $positions ];
}

/**
 * Get last N game values for a player/market from their cached gamelog.
 * Only reads from cache — no new HTTP calls.
 *
 * @return float[]|null
 */
function statsight_ai_recent_values( string $sport, string $player, string $market_key, string $event_id, int $n = 5 ): ?array {
    $cache_key = 'statsight_player_v6_' . md5( $sport . $player . $market_key . $event_id );
    $cached    = get_transient( $cache_key );
    if ( false === $cached ) return null;

    $gamelog  = $cached['gamelog'] ?? null;
    if ( ! $gamelog ) return null;

    $stat_cols = statsight_hit_rate_columns( $market_key );
    if ( ! $stat_cols ) return null;

    $values = [];
    foreach ( $gamelog as $game ) {
        $val = 0.0;
        foreach ( $stat_cols as $col ) {
            $num = statsight_stat_to_float( $game['stats'][ $col ] ?? null );
            if ( $num !== null ) $val += $num;
        }
        $values[] = round( $val, 1 );
    }

    // Gamelog is newest-first — return the last N games in chronological order.
    return array_reverse( array_slice( array_reverse( $values ), 0, $n ) );
}

/**
 * Fetch live game state (score, period) and per-player boxscore stats from ESPN.
 * Returns null if the game isn't live or ESPN can't be reached.
 * Reuses the same cache as statsight_ajax_get_live_boxscore (45-second TTL).
 *
 * @return array{ score: string, period: string, player_stats: array<string,array> }|null
 */
function statsight_ai_live_context( string $sport, string $home, string $away, ?string $game_date_et = null ): ?array {
    $path = statsight_espn_sport_path( $sport );
    if ( ! $path ) return null;

    $base_url       = 'https://site.api.espn.com/apis/site/v2/sports/' . $path['sport'] . '/' . $path['league'];
    $scoreboard_url = $base_url . '/scoreboard';

    $sb_response = wp_remote_get( $scoreboard_url, [ 'timeout' => 10 ] );
    if ( is_wp_error( $sb_response ) || wp_remote_retrieve_response_code( $sb_response ) !== 200 ) return null;

    $sb_data  = json_decode( wp_remote_retrieve_body( $sb_response ), true );
    $norm     = fn( string $s ): string => trim( strtolower( str_replace(
        [ 'los angeles', 'new york', 'new orleans', 'golden state', 'san antonio', 'oklahoma city' ],
        [ 'la',          'ny',       'no',           'gs',           'sa',          'okc' ],
        $s
    ) ) );
    $match_fn = fn( string $a, string $b ): bool =>
        ( $n1 = $norm( $a ) ) === ( $n2 = $norm( $b ) ) || str_contains( $n1, $n2 ) || str_contains( $n2, $n1 );

    $et_tz         = new DateTimeZone( 'America/New_York' );
    $espn_event_id = null;
    $score_text    = '';
    $period_text   = '';

    foreach ( $sb_data['events'] ?? [] as $event ) {
        $state       = $event['status']['type']['state'] ?? '';
        $competitors = $event['competitions'][0]['competitors'] ?? [];
        $espn_home   = '';
        $espn_away   = '';
        $home_score  = '';
        $away_score  = '';

        foreach ( $competitors as $c ) {
            if ( ( $c['homeAway'] ?? '' ) === 'home' ) {
                $espn_home  = $c['team']['displayName'] ?? '';
                $home_score = $c['score'] ?? '';
            } else {
                $espn_away  = $c['team']['displayName'] ?? '';
                $away_score = $c['score'] ?? '';
            }
        }

        if ( ! ( $match_fn( $home, $espn_home ) && $match_fn( $away, $espn_away ) ) ) {
            continue;
        }

        // If we know the game's ET date, skip ESPN events on a different date.
        // This prevents back-to-back matchups from matching yesterday's final.
        if ( $game_date_et && ! empty( $event['date'] ) ) {
            $espn_date = ( new DateTime( $event['date'] ) )->setTimezone( $et_tz )->format( 'Y-m-d' );
            if ( $espn_date !== $game_date_et ) {
                continue;
            }
        }

        $espn_event_id = $event['id'];
        if ( $state === 'in' ) {
            $period_text = $event['status']['type']['shortDetail'] ?? '';
            $score_text  = $home . ' ' . $home_score . ', ' . $away . ' ' . $away_score;
        } elseif ( $state === 'post' ) {
            $period_text = 'Final';
            $score_text  = $home . ' ' . $home_score . ', ' . $away . ' ' . $away_score;
        }
        break;
    }

    if ( ! $espn_event_id || ! $score_text ) return null;

    // Fetch per-player stats from the boxscore (use cached result if available).
    $boxscore_cache_key = 'statsight_boxscore_' . md5( $sport . $home . $away );
    $player_stats       = get_transient( $boxscore_cache_key );

    if ( false === $player_stats ) {
        $summary_url  = $base_url . '/summary?event=' . $espn_event_id;
        $sum_response = wp_remote_get( $summary_url, [ 'timeout' => 10 ] );
        $player_stats = [];

        if ( ! is_wp_error( $sum_response ) && wp_remote_retrieve_response_code( $sum_response ) === 200 ) {
            $sum_data = json_decode( wp_remote_retrieve_body( $sum_response ), true );
            foreach ( $sum_data['boxscore']['players'] ?? [] as $team_block ) {
                foreach ( $team_block['statistics'] ?? [] as $stat_group ) {
                    $labels = $stat_group['labels'] ?? [];
                    foreach ( $stat_group['athletes'] ?? [] as $athlete ) {
                        if ( $athlete['didNotPlay'] ?? false ) continue;
                        $name      = $athlete['athlete']['displayName'] ?? '';
                        $stats_arr = $athlete['stats'] ?? [];
                        if ( ! $name || empty( $stats_arr ) ) continue;
                        $stats_map = [];
                        foreach ( $labels as $i => $label ) {
                            $stats_map[ $label ] = $stats_arr[ $i ] ?? '—';
                        }
                        $player_stats[ $name ] = array_merge( $player_stats[ $name ] ?? [], $stats_map );
                    }
                }
            }
        }
        set_transient( $boxscore_cache_key, $player_stats, 45 );
    }

    return [
        'score'        => $score_text,
        'period'       => $period_text,
        'player_stats' => $player_stats,
    ];
}

/**
 * Compute hit rate for a player/market using their cached gamelog.
 * Only uses already-cached data — will not fire new ESPN HTTP calls.
 *
 * @return array{ pct: int, hits: int, total: int, avg: float }|null
 */
function statsight_ai_hit_rate( string $sport, string $player, string $market_key, float $line, string $event_id ): ?array {
    $cache_key = 'statsight_player_v6_' . md5( $sport . $player . $market_key . $event_id );
    $cached    = get_transient( $cache_key );
    if ( false === $cached ) return null; // not pre-warmed — skip rather than block

    $gamelog   = $cached['gamelog'] ?? null;
    if ( ! $gamelog ) return null;

    $stat_cols = statsight_hit_rate_columns( $market_key );
    if ( ! $stat_cols ) return null;

    $hits   = 0;
    $total  = count( $gamelog );
    $sum    = 0.0;

    foreach ( $gamelog as $game ) {
        $val = 0.0;
        foreach ( $stat_cols as $col ) {
            $num = statsight_stat_to_float( $game['stats'][ $col ] ?? null );
            if ( $num !== null ) $val += $num;
        }
        $sum += $val;
        if ( $val > $line ) $hits++;
    }

    if ( $total === 0 ) return null;

    return [
        'pct'   => (int) round( ( $hits / $total ) * 100 ),
        'hits'  => $hits,
        'total' => $total,
        'avg'   => round( $sum / $total, 1 ),
    ];
}

/**
 * Fetch MMA fighter data for AI analysis.
 * Reads from the existing statsight_mma_v2_ transient if warm; otherwise fetches fresh.
 * Returns the bio + fights payload array, or null on failure.
 */
function statsight_ai_get_mma_fighter( string $fighter_name ): ?array {
    $cache_key = 'statsight_mma_v2_' . md5( $fighter_name );
    $cached    = get_transient( $cache_key );
    if ( is_array( $cached ) ) return $cached;

    // Not cached — fetch from ESPN now (same logic as statsight_ajax_get_mma_fighter_stats).
    $search_url = 'https://site.web.api.espn.com/apis/search/v2?' . http_build_query( [
        'query' => $fighter_name,
        'sport' => 'mma',
        'limit' => 5,
    ] );
    $search_res = wp_remote_get( $search_url, [ 'timeout' => 10 ] );
    if ( is_wp_error( $search_res ) || wp_remote_retrieve_response_code( $search_res ) !== 200 ) return null;

    $search_data    = json_decode( wp_remote_retrieve_body( $search_res ), true );
    $athlete_id     = null;
    $fallback_id    = null;
    foreach ( $search_data['results'] ?? [] as $group ) {
        if ( ( $group['type'] ?? '' ) !== 'player' ) continue;
        foreach ( $group['contents'] ?? [] as $item ) {
            $uid = $item['uid'] ?? '';
            if ( ! preg_match( '/~a:(\d+)$/', $uid, $m ) ) continue;
            $numeric_id = $m[1];
            if ( statsight_normalize_name( $item['displayName'] ?? '' ) === statsight_normalize_name( $fighter_name ) ) {
                $athlete_id = $numeric_id;
                break 2;
            }
            if ( $fallback_id === null ) $fallback_id = $numeric_id;
        }
    }
    $athlete_id = $athlete_id ?? $fallback_id;
    if ( ! $athlete_id ) return null;

    $ath_url = 'https://site.web.api.espn.com/apis/common/v3/sports/mma/ufc/athletes/' . $athlete_id;
    $ath_res  = wp_remote_get( $ath_url, [ 'timeout' => 10 ] );
    if ( is_wp_error( $ath_res ) || wp_remote_retrieve_response_code( $ath_res ) !== 200 ) return null;

    $ath_data = json_decode( wp_remote_retrieve_body( $ath_res ), true );
    $athlete  = $ath_data['athlete'] ?? [];

    $record_str = '';
    $kos = $subs = 0;
    foreach ( $athlete['statsSummary']['statistics'] ?? [] as $stat ) {
        $name = $stat['name'] ?? '';
        $dv   = $stat['displayValue'] ?? '';
        if ( $name === 'wins-losses-draws' ) $record_str = $dv;
        if ( $name === 'tkos-tkoLosses' && preg_match( '/^(\d+)/', $dv, $rm ) ) $kos  = (int) $rm[1];
        if ( $name === 'submissions-submissionLosses' && preg_match( '/^(\d+)/', $dv, $rm ) ) $subs = (int) $rm[1];
    }

    $bio = [
        'name'         => $athlete['displayName'] ?? $fighter_name,
        'record'       => $record_str,
        'kos'          => $kos,
        'subs'         => $subs,
        'weight_class' => $athlete['weightClass']['text'] ?? '',
        'stance'       => $athlete['stance']['text'] ?? '',
        'reach'        => $athlete['displayReach'] ?? '',
        'height'       => $athlete['displayHeight'] ?? '',
        'style'        => $athlete['displayFightingStyle'] ?? '',
    ];

    // Fight history.
    $league_slug_map = [ '3321' => 'ufc', '3359' => 'pfl' ];
    $ov_url  = 'https://site.web.api.espn.com/apis/common/v3/sports/mma/ufc/athletes/' . $athlete_id . '/overview';
    $ov_res  = wp_remote_get( $ov_url, [ 'timeout' => 10 ] );
    $fh_uids = [];
    if ( ! is_wp_error( $ov_res ) && wp_remote_retrieve_response_code( $ov_res ) === 200 ) {
        $fh_uids = json_decode( wp_remote_retrieve_body( $ov_res ), true )['fightHistory'] ?? [];
    }

    $fights = [];
    foreach ( array_slice( $fh_uids, 0, 8 ) as $uid ) {
        if ( ! preg_match( '/~l:(\d+)~e:(\d+)~c:(\d+)/', $uid, $um ) ) continue;
        $slug     = $league_slug_map[ $um[1] ] ?? $um[1];
        $core     = 'https://sports.core.api.espn.com/v2/sports/mma/leagues/' . $slug;
        $comp_res = wp_remote_get( $core . '/events/' . $um[2] . '/competitions/' . $um[3] . '?lang=en&region=us', [ 'timeout' => 8 ] );
        if ( is_wp_error( $comp_res ) || wp_remote_retrieve_response_code( $comp_res ) !== 200 ) continue;
        $comp     = json_decode( wp_remote_retrieve_body( $comp_res ), true );
        $date     = substr( $comp['date'] ?? '', 0, 10 );
        $result   = 'L';
        $opp_id   = null;
        foreach ( $comp['competitors'] ?? [] as $c ) {
            if ( (string) ( $c['id'] ?? '' ) === (string) $athlete_id ) {
                $result = ( $c['winner'] ?? false ) ? 'W' : 'L';
            } else {
                $opp_id = (string) ( $c['id'] ?? '' );
            }
        }
        $opponent = '—';
        if ( $opp_id ) {
            $opp_res = wp_remote_get( 'https://sports.core.api.espn.com/v2/sports/mma/athletes/' . $opp_id . '?lang=en&region=us', [ 'timeout' => 6 ] );
            if ( ! is_wp_error( $opp_res ) && wp_remote_retrieve_response_code( $opp_res ) === 200 ) {
                $opp_data = json_decode( wp_remote_retrieve_body( $opp_res ), true );
                $opponent = $opp_data['displayName'] ?? $opp_data['fullName'] ?? '—';
            }
        }
        $method = $round = $time = '';
        $st_res = wp_remote_get( $core . '/events/' . $um[2] . '/competitions/' . $um[3] . '/status?lang=en&region=us', [ 'timeout' => 6 ] );
        if ( ! is_wp_error( $st_res ) && wp_remote_retrieve_response_code( $st_res ) === 200 ) {
            $st      = json_decode( wp_remote_retrieve_body( $st_res ), true );
            $res_obj = $st['result'] ?? [];
            $method  = $res_obj['shortDisplayName'] ?? $res_obj['displayName'] ?? '';
            $round   = isset( $st['period'] ) ? (string) $st['period'] : '';
            $time    = $st['displayClock'] ?? '';
        }
        $fights[] = compact( 'date', 'opponent', 'result', 'method', 'round', 'time' );
    }

    $payload = [ 'bio' => $bio, 'fights' => $fights ];
    set_transient( $cache_key, $payload, 5 * MINUTE_IN_SECONDS );
    return $payload;
}

/**
 * Build a compact fighter profile string for the AI prompt.
 */
function statsight_ai_mma_fighter_profile( string $name, ?array $data ): string {
    if ( ! $data ) return "{$name}: No data available.\n";

    $bio    = $data['bio'] ?? [];
    $fights = $data['fights'] ?? [];

    $lines = [ "**{$name}**" ];
    $lines[] = 'Record: ' . ( $bio['record'] ?: 'N/A' );
    if ( $bio['kos'] || $bio['subs'] ) {
        $lines[] = 'Finishes: ' . $bio['kos'] . ' KO/TKO, ' . $bio['subs'] . ' submissions';
    }
    $attrs = array_filter( [
        $bio['weight_class'] ?? '',
        $bio['stance']       ? 'Stance: ' . $bio['stance']       : '',
        $bio['reach']        ? 'Reach: '  . $bio['reach']        : '',
        $bio['height']       ? 'Height: ' . $bio['height']       : '',
        $bio['style']        ? 'Style: '  . $bio['style']        : '',
    ] );
    if ( $attrs ) $lines[] = implode( ' | ', $attrs );

    if ( $fights ) {
        $lines[] = 'Recent fights (newest first):';
        foreach ( array_slice( $fights, 0, 5 ) as $f ) {
            $finish = $f['method'] ? " via {$f['method']}" : '';
            $rnd    = $f['round'] ? " (R{$f['round']} {$f['time']})" : '';
            $lines[] = "  {$f['date']} {$f['result']} vs {$f['opponent']}{$finish}{$rnd}";
        }
    }

    return implode( "\n", $lines ) . "\n";
}

/**
 * Stream an AI prop analysis for a game via Server-Sent Events.
 * Cached for 1 hour per event so repeated opens don't re-call the API.
 */
// ── Game spread endpoint (used by Prop Score) ─────────────────────────────
function statsight_ajax_get_game_spread(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );

    $sport    = sanitize_text_field( $_GET['sport']    ?? '' );
    $event_id = sanitize_text_field( $_GET['event_id'] ?? '' );

    if ( ! $sport || ! $event_id ) {
        wp_send_json_error( [ 'message' => 'Missing parameters.' ], 400 );
    }

    $lines = statsight_ai_game_lines( $sport, $event_id );

    if ( ! $lines ) {
        wp_send_json_success( [ 'spread' => null ] );
    }

    wp_send_json_success( [
        'spread'          => $lines['spread_line']     ?? null,
        'spread_favorite' => $lines['spread_favorite'] ?? null,
        'total'           => $lines['total']           ?? null,
    ] );
}
add_action( 'wp_ajax_statsight_get_game_spread',         'statsight_ajax_get_game_spread' );
add_action( 'wp_ajax_nopriv_statsight_get_game_spread',  'statsight_ajax_get_game_spread' );

add_action( 'wp_ajax_statsight_ai_analysis', 'statsight_ajax_ai_analysis' );
function statsight_ajax_ai_analysis(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'pro' );

    if ( ! defined( 'ANTHROPIC_API_KEY' ) || ! ANTHROPIC_API_KEY ) {
        wp_send_json_error( [ 'message' => 'AI analysis is not configured.' ], 503 );
    }

    $event_id  = sanitize_key( $_GET['event_id'] ?? '' );
    $sport     = sanitize_key( $_GET['sport']    ?? '' );
    $home      = sanitize_text_field( wp_unslash( $_GET['home'] ?? '' ) );
    $away      = sanitize_text_field( wp_unslash( $_GET['away'] ?? '' ) );
    $book      = sanitize_key( $_GET['book']     ?? '' ); // empty = best available
    $risk      = in_array( $_GET['risk'] ?? '', [ 'low', 'medium', 'high' ], true ) ? $_GET['risk'] : 'medium';

    if ( ! $event_id || ! $sport ) {
        wp_send_json_error( [ 'message' => 'Missing parameters.' ], 400 );
    }

    // Cache key includes book and risk so each combination gets its own cached analysis.
    $cache_key = 'statsight_ai2_' . $event_id . ( $book ? '_' . $book : '' ) . '_' . $risk;
    $cached    = get_transient( $cache_key );
    if ( $cached ) {
        header( 'Content-Type: text/event-stream; charset=UTF-8' );
        header( 'Cache-Control: no-cache' );
        header( 'X-Accel-Buffering: no' );
        echo "data: " . wp_json_encode( [ 'type' => 'cached', 'text' => $cached ] ) . "\n\n";
        echo "data: " . wp_json_encode( [ 'type' => 'done' ] ) . "\n\n";
        flush();
        exit;
    }

    // Build context from cached props payload.
    $props_payload = get_transient( 'statsight_props2_' . $event_id );
    if ( ! $props_payload ) {
        $props_payload = statsight_props2_from_history( $sport, $event_id );
    }

    // ── MMA: fighter-profile analysis path ──────────────────────────────────
    if ( $sport === 'mma_mixed_martial_arts' ) {
        $home_data = statsight_ai_get_mma_fighter( $home );
        $away_data = statsight_ai_get_mma_fighter( $away );

        // Build odds summary from cached props (moneyline + round totals).
        $odds_section = '';
        if ( $props_payload ) {
            $ml_props = $props_payload['props']['h2h'] ?? [];
            if ( $ml_props ) {
                $odds_section .= "\nMONEYLINE ODDS (best available across books):\n";
                foreach ( $ml_props as $fighter => $lines ) {
                    $best_price = null;
                    $best_book  = '';
                    foreach ( ( $lines['yn'] ?? [] ) as $bk => $odds ) {
                        $price = $odds['over'] ?? null;
                        if ( $price === null ) continue;
                        if ( $best_price === null || $price > $best_price ) {
                            $best_price = $price;
                            $best_book  = $bk;
                        }
                    }
                    if ( $best_price !== null ) {
                        $fmt          = $best_price >= 0 ? "+{$best_price}" : $best_price;
                        $book_titles  = $props_payload['books'] ?? [];
                        $book_label   = $book_titles[ $best_book ] ?? $best_book;
                        $odds_section .= "  {$fighter}: {$fmt} @ {$book_label}\n";
                    }
                }
            }
            $tot_props = $props_payload['props']['totals'] ?? [];
            if ( $tot_props ) {
                $odds_section .= "\nROUND TOTAL ODDS (best available):\n";
                foreach ( $tot_props as $matchup => $lines ) {
                    foreach ( $lines as $line_val => $books ) {
                        $best_over = $best_under = null;
                        $bo_book   = $bu_book   = '';
                        foreach ( $books as $bk => $odds ) {
                            $ov = $odds['over']  ?? null;
                            $un = $odds['under'] ?? null;
                            if ( $ov !== null && ( $best_over === null || $ov > $best_over ) ) {
                                $best_over = $ov; $bo_book = $bk;
                            }
                            if ( $un !== null && ( $best_under === null || $un > $best_under ) ) {
                                $best_under = $un; $bu_book = $bk;
                            }
                        }
                        $book_titles = $props_payload['books'] ?? [];
                        $ov_fmt = $best_over  !== null ? ( $best_over  >= 0 ? "+{$best_over}"  : $best_over )  : 'N/A';
                        $un_fmt = $best_under !== null ? ( $best_under >= 0 ? "+{$best_under}" : $best_under ) : 'N/A';
                        $odds_section .= "  O/U {$line_val} rounds — Over: {$ov_fmt} | Under: {$un_fmt}\n";
                    }
                }
            }
        }

        $home_profile = statsight_ai_mma_fighter_profile( $home, $home_data );
        $away_profile = statsight_ai_mma_fighter_profile( $away, $away_data );

        $book_label    = $book ? ( ( $props_payload['books'] ?? [] )[ $book ] ?? $book ) : '';
        $parlay_note   = $book ? "The user is focused on {$book_label} odds. Tailor your bet recommendation to markets available there." : '';

        $risk_instructions = match ( $risk ) {
            'low'  => 'RISK PROFILE — LOW (target odds -200 to 0): Recommend the safest, most likely outcome. Favor the clear betting favorite priced between -200 and -101. Prefer the under on rounds if both fighters tend to go the distance. Avoid picks that require a specific finish method.',
            'high' => 'RISK PROFILE — HIGH (target odds +200 to +400): The user wants high-risk, high-reward picks. You MUST prioritise props and moneylines priced between +200 and +400. Recommend the underdog if there is a credible path to victory (style matchup, finishing ability). Consider method-of-victory props or the underdog outright. Be aggressive and flag the risk.',
            default => 'RISK PROFILE — MEDIUM (target odds 0 to +200): Balance upside with confidence. Prioritise picks priced between -100 and +200. The favorite is fine if the line is fair, but note whether the underdog has a realistic path. Round total over/under based on both fighters\' tendencies.',
        };

        $prompt = <<<PROMPT
You are a sharp MMA betting analyst. A user is looking at odds for the following fight:

{$away} vs {$home}{$odds_section}

FIGHTER PROFILES:

{$away_profile}
{$home_profile}

{$parlay_note}
{$risk_instructions}

Provide a sharp, concise fight analysis (3-5 paragraphs) covering:
1. Style matchup — how the two fighters' strengths and weaknesses interact, who has the edge standing and on the ground
2. Finish probability — based on both fighters' KO/sub rates and recent results, assess how likely this ends before the judges
3. Your recommended bet(s) — with clear reasoning tied to the matchup and odds. Include both the moneyline pick and a round total recommendation if the odds support it.
4. Key risks to your picks — what could cause them to lose

Be direct and specific. Cite the fighter records, finish methods, and recent opponents. Do not use markdown headings (no # or ##) — plain text and **bold** only. No generic gambling disclaimers.

After your prose, append on a new line:
PICKS_JSON:[{"player":"<fighter name or matchup label>","market_key":"<h2h or totals>","line":"<yn or round number>"}]
Use only fighter names exactly as shown above, and market_key values "h2h" or "totals". Do not mention PICKS_JSON in your prose.
PROMPT;

        // Stream from Anthropic.
        $request_body = wp_json_encode( [
            'model'      => 'claude-sonnet-4-6',
            'max_tokens' => 2000,
            'stream'     => true,
            'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
        ] );

        $ch = curl_init( 'https://api.anthropic.com/v1/messages' );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $request_body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . ANTHROPIC_API_KEY,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_VERBOSE        => false,
        ] );

        header( 'Content-Type: text/event-stream; charset=UTF-8' );
        header( 'Cache-Control: no-cache' );
        header( 'X-Accel-Buffering: no' );
        if ( ob_get_level() ) ob_end_clean();

        $full_text    = '';
        $raw_response = '';
        curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function ( $ch, $chunk ) use ( &$full_text, &$raw_response ) {
            $raw_response .= $chunk;
            foreach ( explode( "\n", $chunk ) as $line ) {
                $line = trim( $line );
                if ( ! str_starts_with( $line, 'data:' ) ) continue;
                $json = json_decode( trim( substr( $line, 5 ) ), true );
                if ( ! $json ) continue;
                $type = $json['type'] ?? '';
                if ( $type === 'content_block_delta' ) {
                    $text = $json['delta']['text'] ?? '';
                    if ( $text !== '' ) {
                        $full_text .= $text;
                        echo "data: " . wp_json_encode( [ 'type' => 'delta', 'text' => $text ] ) . "\n\n";
                        flush();
                    }
                } elseif ( $type === 'message_stop' ) {
                    echo "data: " . wp_json_encode( [ 'type' => 'done' ] ) . "\n\n";
                    flush();
                }
            }
            return strlen( $chunk );
        } );

        curl_exec( $ch );
        $curl_error = curl_error( $ch );
        $http_code  = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );
        if ( $curl_error || $http_code >= 400 ) {
            error_log( '[Statsight AI MMA] curl error: ' . $curl_error . ' | HTTP: ' . $http_code );
        }
        if ( $full_text ) {
            set_transient( $cache_key, $full_text, HOUR_IN_SECONDS );
        }
        exit;
    }
    // ── End MMA path ────────────────────────────────────────────────────────

    if ( ! $props_payload ) {
        wp_send_json_error( [ 'message' => 'No props data available for this game yet.' ], 404 );
    }

    // Build a compact summary of top edges per market for the prompt.
    $market_labels = statsight_market_labels();
    $categories    = statsight_prop_categories( $sport );
    $top_props     = [];

    foreach ( $categories as $cat_key => $cat ) {
        foreach ( $cat['markets'] as $market_key ) {
            if ( str_ends_with( $market_key, '_alternate' ) ) continue;
            $rows = statsight_calc_best_value( $props_payload, $market_key );
            usort( $rows, fn( $a, $b ) => $b['edge'] <=> $a['edge'] );
            foreach ( array_slice( $rows, 0, 3 ) as $row ) {
                $top_props[] = [
                    'category'   => $cat['label'],
                    'market'     => $market_labels[ $market_key ] ?? $market_key,
                    'market_key' => $market_key,
                    'player'     => $row['player'],
                    'line'       => $row['line'],
                    'best_odds'  => $row['best_odds'],
                    'best_book'  => $row['best_book'],
                    'edge'       => $row['edge'],
                    'ev'         => $row['ev'] ?? null,
                ];
            }
        }
    }

    // Sort all props by edge descending, keep top 20 for the prompt.
    usort( $top_props, fn( $a, $b ) => $b['edge'] <=> $a['edge'] );
    $top_props = array_slice( $top_props, 0, 20 );

    // Attach hit rates and last-5 game values from cached gamelogs (no new HTTP calls).
    foreach ( $top_props as &$p ) {
        $p['hit_rate']      = statsight_ai_hit_rate( $sport, $p['player'], $p['market_key'], (float) $p['line'], $event_id );
        $p['recent_values'] = statsight_ai_recent_values( $sport, $p['player'], $p['market_key'], $event_id, 5 );
    }
    unset( $p );

    // Look up book titles from the cached props payload.
    $book_titles   = $props_payload['books'] ?? [];
    $book_label    = $book ? ( $book_titles[ $book ] ?? $book ) : '';
    $book_label_col = $book ? " | {$book_label} odds" : '';

    $props_text = '';
    foreach ( $top_props as $p ) {
        $ev_str  = $p['ev'] !== null ? " | EV: {$p['ev']}%" : '';
        $hr_str  = '';
        $rec_str = '';
        if ( $p['hit_rate'] ) {
            $hr     = $p['hit_rate'];
            $hr_str = " | hit {$hr['pct']}% ({$hr['hits']}/{$hr['total']} games, avg {$hr['avg']})";
        }
        if ( ! empty( $p['recent_values'] ) ) {
            $rec_str = ' | L' . count( $p['recent_values'] ) . ': ' . implode( ', ', $p['recent_values'] );
        }
        $pos_str = isset( $positions[ $p['player'] ] ) ? " [{$positions[$p['player']]}]" : '';

        // When a specific book is requested, include that book's odds alongside the best.
        $book_odds_str = '';
        if ( $book ) {
            $line_data  = $props_payload['props'][ $p['market_key'] ][ $p['player'] ][ (string) $p['line'] ] ?? null;
            $book_price = $line_data[ $book ]['over'] ?? null;
            if ( $book_price !== null ) {
                $book_odds_str = " | {$book_label}: " . ( $book_price >= 0 ? "+{$book_price}" : $book_price );
            } else {
                $book_odds_str = " | {$book_label}: N/A";
            }
        }

        $props_text .= "- [{$p['category']}] {$p['player']}{$pos_str} {$p['market']} (key:{$p['market_key']}) {$p['line']}: best {$p['best_odds']} @ {$p['best_book']}{$book_odds_str} | edge {$p['edge']}pp{$ev_str}{$hr_str}{$rec_str}\n";
    }

    $sport_label = ucwords( str_replace( '_', ' ', preg_replace( '/^[a-z]+_/', '', $sport ) ) );

    // Fetch all context in parallel (cached calls are instant, network calls are fast).
    $game_lines     = statsight_ai_game_lines( $sport, $event_id );
    $roster_context = statsight_ai_roster_context( $sport, $home, $away );
    $injuries       = $roster_context['injuries'];
    $positions      = $roster_context['positions'];

    // Derive the game's ET date from the cached events so the live context lookup
    // can avoid matching yesterday's final for back-to-back matchups.
    $game_date_et = null;
    $events_cache = get_transient( 'statsight_events_' . $sport );
    if ( $events_cache ) {
        $all_events = array_merge( ...array_column( $events_cache['days'] ?? [], 'events' ) );
        foreach ( $all_events as $ev ) {
            if ( ( $ev['id'] ?? '' ) === $event_id && ! empty( $ev['commence_time'] ) ) {
                $game_date_et = ( new DateTime( $ev['commence_time'] ) )
                    ->setTimezone( new DateTimeZone( 'America/New_York' ) )
                    ->format( 'Y-m-d' );
                break;
            }
        }
    }

    $live_context = statsight_ai_live_context( $sport, $home, $away, $game_date_et );

    // ── Game Lines section ───────────────────────────────────────────────────
    $lines_section = '';
    if ( $game_lines ) {
        $lines_section .= "\n\nGAME LINES (consensus)";
        if ( $game_lines['spread_line'] !== null ) {
            $lines_section .= "\nSpread: {$game_lines['spread_favorite']} -{$game_lines['spread_line']}";
        }
        if ( $game_lines['total'] !== null ) {
            $lines_section .= "\nGame total (O/U): {$game_lines['total']}";
        }
    }

    // ── Injury Report section ────────────────────────────────────────────────
    $injury_section = '';
    if ( ! empty( $injuries ) ) {
        $injury_section = "\n\nINJURY REPORT";
        foreach ( $injuries as $player => $status ) {
            $injury_section .= "\n  {$player}: {$status}";
        }
    }

    // ── Live Game section ────────────────────────────────────────────────────
    $live_section = '';
    if ( $live_context ) {
        $live_section  = "\n\nLIVE GAME CONTEXT";
        $live_section .= "\nScore: {$live_context['score']}";
        $live_section .= "\nStatus: {$live_context['period']}";

        if ( ! empty( $live_context['player_stats'] ) ) {
            $live_section .= "\nIn-game stats (players in our prop list):";
            $top_players   = array_unique( array_column( $top_props, 'player' ) );
            $norm_name     = fn( string $s ): string => strtolower( preg_replace( '/\s+/', ' ', trim( $s ) ) );
            foreach ( $top_players as $player ) {
                $stats = null;
                foreach ( $live_context['player_stats'] as $espn_name => $espn_stats ) {
                    if ( $norm_name( $espn_name ) === $norm_name( $player ) ) {
                        $stats = $espn_stats;
                        break;
                    }
                }
                if ( ! $stats ) continue;
                $stat_str      = implode( ', ', array_map( fn( $k, $v ) => "{$k}: {$v}", array_keys( $stats ), array_values( $stats ) ) );
                $live_section .= "\n  {$player}: {$stat_str}";
            }
        }
    }

    // ── Build instructions based on available context ────────────────────────
    $extra_instructions = '';
    if ( $game_lines ) {
        $extra_instructions .= "- Factor the game total and spread into your prop analysis (high totals favour overs on scoring props; big spreads affect game script and usage)\n";
    }
    if ( ! empty( $injuries ) ) {
        $extra_instructions .= "- Note any injured/questionable players whose absence could inflate or deflate teammates' prop values\n";
    }
    if ( $live_context ) {
        $extra_instructions .= "- Use the live score and in-game stats to assess pace and whether players are on track to hit their lines\n";
    }

    // ── Risk profile instructions ─────────────────────────────────────────────
    $risk_instructions = match ( $risk ) {
        'low'  => "RISK PROFILE — LOW (target odds -200 to 0): The user wants safe, high-probability plays. Prioritise props priced between -200 and -101 (favourites). These are your primary picks. Only recommend props with hit rates above 60% and lines comfortably within the player's typical range. Avoid high-variance markets (blocks, steals, anytime TD, etc.) entirely. Do not recommend a prop unless the historical data strongly supports it.",
        'high' => "RISK PROFILE — HIGH (target odds +200 to +400): The user explicitly wants high-risk, high-reward plays. You MUST prioritise props priced between +200 and +400. These are your primary picks — if you are not recommending props in this odds range you are not following this instruction. High-variance markets (blocks, steals, anytime scorer, large unders, underdogs) are encouraged. Hit rate does not need to be above 40% — a strong edge and positive EV are sufficient. Look for contrarian plays where the line may be set too high. Be aggressive and bold. Flag the risk on each pick.",
        default => "RISK PROFILE — MEDIUM (target odds 0 to +200): Balance upside and confidence. Prioritise props priced between -100 and +200. You may include one or two slight favourites if the edge is very strong, but the majority of picks should sit in the +EV range. Include one higher-variance play only if it has a strong edge and recent form.",
    };

    // ── Parlay mode vs best-available mode ───────────────────────────────────
    $odds_range_note = match ( $risk ) {
        'low'    => 'Prioritise legs priced between -200 and -101.',
        'high'   => 'You MUST select legs priced between +200 and +400. Do not include any leg priced below +200.',
        default  => 'Prioritise legs priced between -100 and +200.',
    };

    if ( $book ) {
        $parlay_intro = "The user wants to build a same-game parlay on {$book_label}. Each prop data row now shows the {$book_label} odds alongside best-market odds. Only recommend props that {$book_label} has priced (not marked N/A). {$odds_range_note}";
        $analysis_instructions = <<<INSTR
Provide a sharp parlay-focused analysis (3-5 paragraphs) covering:
1. The 3-5 strongest legs for a {$book_label} same-game parlay that fit the risk profile odds range — prioritise correlated outcomes (e.g. a QB throwing TDs and their WR going over on receiving yards) to maximise the parlay's edge
2. For each leg: why it's a good pick given edge, hit rate, and game script — and how it correlates with the other legs
3. Any legs to avoid due to negative correlation, injury risk, or poor {$book_label} pricing
4. Briefly note the overall parlay story — what game script needs to play out for all legs to hit
INSTR;
        $picks_instruction = "PICKS_JSON:[{\"player\":\"<exact name>\",\"market_key\":\"<exact key>\",\"line\":\"<line as string>\"},...]";
    } else {
        $parlay_intro = '';
        $analysis_instructions = <<<INSTR
Provide a sharp, concise analysis (3-5 paragraphs) covering:
1. Which prop categories look most exploitable given the edges AND the hit rate trends — focus on props that fit the risk profile odds range
2. Your 2-3 best specific plays with clear reasoning — cite edge, recent form, and game-script context
3. Any key caveats (injured players affecting prop values, game total implications, pace/usage concerns)
INSTR;
        $picks_instruction = "PICKS_JSON:[{\"player\":\"<exact name>\",\"market_key\":\"<exact key>\",\"line\":\"<line as string>\"},...]";
    }

    $volatility_guidelines = $risk === 'high' ? '' : <<<GUIDE

STAT VOLATILITY GUIDELINES — apply these before recommending any prop:

1. HIGH-VARIANCE MARKETS (blocks, steals, turnovers, 3-pointers made, goals, assists for non-primary playmakers, first-basket scorer, anytime touchdown scorer): These stats occur infrequently and are largely unpredictable game-to-game. Do NOT recommend a prop in these categories unless the hit rate is above 60% AND the last 5 values show consistency. If you mention them at all, explicitly label them as high-variance and discourage blind over-reliance on the edge alone.

2. HIT RATE FLOOR: If a player's hit rate at the given line is below 40%, treat the prop as a coin flip regardless of edge. Do not recommend it as a strong play. You may note the edge exists but warn that the historical success rate does not support confidence.

3. RECENCY INSTABILITY: If the L5 average diverges by more than 30% from the season average (implied by the hit rate and line), flag the trend as unstable — the player may be in a hot or cold streak that reverses to the mean.

4. SAMPLE SIZE: If the hit rate shows fewer than 5 games tracked (e.g. "1/3 games"), treat it as statistically insufficient and do not use it as a confidence signal.

5. CONSISTENT PRODUCERS: Prioritise props where the player consistently produces near or above the line — steady scorers, primary ball-handlers, high-minute starters. These are lower-variance and more reliable picks.
GUIDE;

    $prompt = <<<PROMPT
You are a sharp sports betting analyst. A user is looking at prop bets for the following {$sport_label} game:

{$away} @ {$home}{$lines_section}{$injury_section}{$live_section}

{$parlay_intro}

Here are the props with the strongest pricing edges right now.
Columns: edge = best-book minus worst-book in American odds points | hit = season hit rate | L5 = last 5 actual values{$book_label_col}:

{$props_text}
{$volatility_guidelines}

{$risk_instructions}

{$analysis_instructions}
{$extra_instructions}
Be direct. Synthesise the data — don't repeat raw numbers verbatim. No generic gambling disclaimers. Do not use markdown headings (no # or ##) — plain text and **bold** only.

After your prose, append on a new line:
{$picks_instruction}
Use only exact player names and market_key values from the data above. Do not mention PICKS_JSON in your prose.
PROMPT;

    // Stream from Anthropic API.
    $request_body = wp_json_encode( [
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 2000,
        'stream'     => true,
        'messages'   => [
            [ 'role' => 'user', 'content' => $prompt ],
        ],
    ] );

    $ch = curl_init( 'https://api.anthropic.com/v1/messages' );
    curl_setopt_array( $ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $request_body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_VERBOSE        => false,
    ] );

    // Set up SSE headers before streaming starts.
    header( 'Content-Type: text/event-stream; charset=UTF-8' );
    header( 'Cache-Control: no-cache' );
    header( 'X-Accel-Buffering: no' );
    if ( ob_get_level() ) ob_end_clean();

    $full_text = '';

    $raw_response = '';
    curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function ( $ch, $chunk ) use ( &$full_text, &$raw_response ) {
        $raw_response .= $chunk;
        // Anthropic streams newline-delimited SSE events.
        foreach ( explode( "\n", $chunk ) as $line ) {
            $line = trim( $line );
            if ( ! str_starts_with( $line, 'data:' ) ) continue;
            $json = json_decode( trim( substr( $line, 5 ) ), true );
            if ( ! $json ) continue;

            $type = $json['type'] ?? '';
            if ( $type === 'content_block_delta' ) {
                $text = $json['delta']['text'] ?? '';
                if ( $text !== '' ) {
                    $full_text .= $text;
                    echo "data: " . wp_json_encode( [ 'type' => 'delta', 'text' => $text ] ) . "\n\n";
                    flush();
                }
            } elseif ( $type === 'message_stop' ) {
                echo "data: " . wp_json_encode( [ 'type' => 'done' ] ) . "\n\n";
                flush();
            }
        }
        return strlen( $chunk );
    } );

    curl_exec( $ch );
    $curl_error = curl_error( $ch );
    $http_code  = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    curl_close( $ch );
    if ( $curl_error || $http_code >= 400 ) {
        error_log( '[Statsight AI] curl error: ' . $curl_error . ' | HTTP: ' . $http_code . ' | response: ' . substr( $raw_response, 0, 500 ) );
    }

    // Cache the full response for 1 hour.
    if ( $full_text ) {
        set_transient( $cache_key, $full_text, HOUR_IN_SECONDS );
    }

    exit;
}

/**
 * Register widget areas
 */
function statsight_widgets_init(): void {
    register_sidebar( [
        'name'          => __( 'Sidebar', 'statsight' ),
        'id'            => 'sidebar-1',
        'description'   => __( 'Add widgets here.', 'statsight' ),
        'before_widget' => '<section id="%1$s" class="widget %2$s">',
        'after_widget'  => '</section>',
        'before_title'  => '<h2 class="widget__title">',
        'after_title'   => '</h2>',
    ] );
}
add_action( 'widgets_init', 'statsight_widgets_init' );

// Moderate display name on account details save.
add_action( 'woocommerce_save_account_details_errors', function ( WP_Error $errors, stdClass $user ) {
    $display_name = sanitize_text_field( $_POST['display_name'] ?? '' );
    if ( $display_name && ! statsight_text_is_clean( $display_name ) ) {
        $errors->add( 'display_name_inappropriate', 'That display name isn\'t allowed. Please choose something appropriate.' );
    }
}, 10, 2 );

// Redirect straight to checkout after add-to-cart instead of back to referring page.
add_filter( 'woocommerce_add_to_cart_redirect', function ( $url ) {
    return wc_get_checkout_url();
} );

// Suppress "X has been added to your cart" notice on the login/account page.
add_filter( 'woocommerce_add_to_cart_fragments', function ( $fragments ) {
    if ( is_account_page() ) {
        wc_clear_notices();
    }
    return $fragments;
} );

add_action( 'template_redirect', function (): void {
    if ( is_account_page() ) {
        wc_clear_notices();
    }
} );

add_filter( 'woocommerce_account_menu_items', function ( array $items ): array {
    unset( $items['downloads'] );
    return $items;
} );

// Prevent the "temporary password" notice from showing more than once.
// WooCommerce's my-account shortcode can output it twice if the page
// template renders the shortcode in multiple contexts.
add_filter( 'woocommerce_add_notice', function ( string $message, string $notice_type ): string {
    static $shown = false;
    if ( $notice_type === 'notice' && str_contains( $message, 'temporary password' ) ) {
        if ( $shown ) return '';
        $shown = true;
    }
    return $message;
}, 10, 2 );

// ── Dynamic PWA manifest ───────────────────────────────────────────────────
add_action( 'template_redirect', function (): void {
    if ( ! isset( $_GET['statsight_manifest'] ) ) {
        return;
    }
    $icon_base = get_template_directory_uri() . '/assets/icons';
    $manifest  = [
        'name'             => 'xstatiq',
        'short_name'       => 'xstatiq',
        'description'      => 'Real-time prop betting odds, best value finder, and arbitrage detection.',
        'start_url'        => home_url( '/props/' ),
        'scope'            => home_url( '/' ),
        'display'          => 'standalone',
        'orientation'      => 'portrait-primary',
        'background_color' => '#0a0c10',
        'theme_color'      => '#0a0c10',
        'icons'            => [
            [
                'src'     => $icon_base . '/icon-192.png',
                'sizes'   => '192x192',
                'type'    => 'image/png',
                'purpose' => 'any maskable',
            ],
            [
                'src'     => $icon_base . '/icon-512.png',
                'sizes'   => '512x512',
                'type'    => 'image/png',
                'purpose' => 'any maskable',
            ],
        ],
        'categories'  => [ 'sports', 'finance' ],
        'screenshots' => [],
    ];
    header( 'Content-Type: application/manifest+json' );
    header( 'Cache-Control: no-cache' );
    echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    exit;
} );
