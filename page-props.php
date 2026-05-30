<?php
/**
 * Template Name: Props App
 *
 * Page template for the main props aggregator app (/props).
 * Fetches the available sports list from The Odds API and renders
 * a tab for each active sport.
 */

get_header();

$result    = statsight_get_sports();
$sports    = $result['sports'];
$api_error = $result['error'];

$first_key = ! empty( $sports ) ? $sports[0]['key'] : '';

?>

<div class="props-aggregator" data-plan="<?php echo esc_attr( statsight_get_user_plan() ); ?>">

    <?php if ( empty( $sports ) ) : ?>

        <div class="empty-state">
            <div class="empty-state__icon">&#x26A0;</div>
            <p class="empty-state__title">Could not load sports</p>
            <?php if ( ! empty( $api_error ) ) : ?>
                <p class="api-error-block"><?php echo wp_kses_post( $api_error ); ?></p>
            <?php endif; ?>
        </div>

    <?php else : ?>

        <!-- League Tab Navigation -->
        <div class="league-tabs" role="tablist" aria-label="Sports leagues">
            <?php foreach ( $sports as $sport ) :
                $key   = $sport['key'];
                $title = $sport['title'];
            ?>
                <button
                    class="league-tab-btn"
                    role="tab"
                    aria-selected="false"
                    aria-controls="panel-<?php echo esc_attr( $key ); ?>"
                    data-tab="<?php echo esc_attr( $key ); ?>"
                    title="<?php echo esc_attr( $sport['description'] ?? $title ); ?>"
                    hidden
                >
                    <?php echo esc_html( $title ); ?>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- League Tab Panels -->
        <?php
        $sport_context = [
            'basketball_nba'             => 'Points, rebounds, assists, 3-pointers & more — compare player prop odds across all books.',
            'americanfootball_nfl'       => 'Passing yards, rushing yards, receiving yards, TDs & more — find the best line before kickoff.',
            'americanfootball_ncaaf'     => 'College football player props — passing, rushing, and receiving markets across all major books.',
            'basketball_ncaab'           => 'College basketball player props — points, rebounds, assists & more.',
            'baseball_mlb'              => 'Strikeouts, hits, runs, home runs & more — shop the sharpest lines in baseball.',
            'icehockey_nhl'             => 'Goals, assists, shots on goal & more — find the best hockey prop odds.',
            'basketball_wnba'           => 'Points, rebounds, assists, 3-pointers & more — compare WNBA player prop odds across all books.',
            'mma_mixed_martial_arts'       => 'Moneyline and round total odds for upcoming UFC & MMA cards — compare lines across all major books.',
            'basketball_nba_summer_league' => 'NBA Summer League player props — early looks at emerging talent.',
            'soccer_epl'                   => 'Premier League anytime scorers, shots on target, assists & more — compare EPL prop odds across FanDuel, DraftKings & BetRivers.',
            'soccer_usa_mls'               => 'MLS anytime scorers, shots on target, assists & more — find the best lines for every Major League Soccer match.',
        ];
        foreach ( $sports as $sport ) :
            $key         = $sport['key'];
            $title       = $sport['title'];
            $context_line = $sport_context[ $key ] ?? ( $sport['description'] ?? '' );
        ?>
        <div
            id="panel-<?php echo esc_attr( $key ); ?>"
            class="league-panel<?php echo $key === $first_key ? ' is-active' : ''; ?>"
            role="tabpanel"
            aria-labelledby="tab-<?php echo esc_attr( $key ); ?>"
            data-sport-key="<?php echo esc_attr( $key ); ?>"
        >
            <?php if ( ! empty( $context_line ) ) : ?>
                <p class="panel-context"><?php echo esc_html( $context_line ); ?></p>
            <?php endif; ?>
            <div class="panel-toolbar">
                <div class="panel-view-toggle">
                    <button class="panel-view-btn is-active" data-view="games" aria-pressed="true">By Game</button>
                    <button class="panel-view-btn" data-view="market" aria-pressed="false">By Market</button>
                    <button class="panel-view-btn" data-view="sharp" aria-pressed="false">Sharp Moves</button>
                    <button class="panel-view-btn" data-view="arb" aria-pressed="false">Arbitrage</button>
                </div>
                <div class="panel-search-wrap">
                    <input
                        class="panel-search"
                        type="search"
                        placeholder="Search team or player&hellip;"
                        aria-label="Search teams"
                    >
                </div>
            </div>
            <div class="panel-market-controls" hidden>
                <label class="panel-market-control-group">
                    <span class="panel-market-control-label">Market</span>
                    <select class="panel-market-select" aria-label="Select market">
                        <option value="">— Select a market —</option>
                    </select>
                </label>
                <label class="panel-market-control-group">
                    <span class="panel-market-control-label">Sort by</span>
                    <select class="panel-market-sort" aria-label="Sort by">
                        <option value="default">Default</option>
                        <option value="ev">Highest EV%</option>
                        <option value="best_odds">Best Odds</option>
                        <option value="line_desc">Line (High → Low)</option>
                        <option value="line_asc">Line (Low → High)</option>
                    </select>
                </label>
            </div>
            <div class="panel-events" data-loaded="false">
                <div class="empty-state empty-state--loading">
                    <p class="empty-state__title">Loading&hellip;</p>
                </div>
            </div>
            <div class="panel-market-view" hidden></div>
            <div class="panel-sharp-view" hidden></div>
            <div class="panel-arb-view" hidden></div>
        </div>
        <?php endforeach; ?>

    <?php endif; ?>

</div><!-- .props-aggregator -->

<?php
$active_books_raw = get_user_meta( get_current_user_id(), 'statsight_active_books', true );
$active_books     = ( $active_books_raw && is_string( $active_books_raw ) )
    ? json_decode( $active_books_raw, true )
    : null;
?>

<script>
var statsightAjax = {
    url:          <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
    nonce:        <?php echo wp_json_encode( wp_create_nonce( 'statsight_events' ) ); ?>,
    plan:         <?php echo wp_json_encode( statsight_get_user_plan() ); ?>,
    userId:       <?php echo wp_json_encode( get_current_user_id() ); ?>,
    homeUrl:      <?php echo wp_json_encode( home_url( '/#pricing' ) ); ?>,
    loginUrl:     <?php echo wp_json_encode( wp_login_url( get_permalink() ) ); ?>,
    watchlistUrl: <?php echo wp_json_encode( get_permalink( get_page_by_path( 'watchlist' ) ) ); ?>,
    activeBooks:  <?php echo wp_json_encode( $active_books ); ?>
};

// ── Shared display maps ────────────────────────────────────────────────────
var BOOK_LABELS = <?php echo wp_json_encode( statsight_get_book_labels() ); ?>;

var MARKET_LABELS = {
    // Basketball
    player_points:                   'Points',
    player_rebounds:                 'Rebounds',
    player_assists:                  'Assists',
    player_threes:                   '3-Pointers',
    player_blocks:                   'Blocks',
    player_steals:                   'Steals',
    player_turnovers:                'Turnovers',
    player_points_rebounds_assists:  'Pts+Reb+Ast',
    player_points_rebounds:          'Pts+Reb',
    player_points_assists:           'Pts+Ast',
    player_rebounds_assists:         'Reb+Ast',
    player_blocks_steals:            'Blk+Stl',
    player_double_double:            'Double-Double',
    player_triple_double:            'Triple-Double',
    // Football
    player_pass_yds:                 'Pass Yards',
    player_pass_tds:                 'Pass TDs',
    player_pass_attempts:            'Pass Attempts',
    player_pass_completions:         'Completions',
    player_pass_interceptions:       'Interceptions',
    player_rush_yds:                 'Rush Yards',
    player_rush_attempts:            'Rush Attempts',
    player_rush_tds:                 'Rush TDs',
    player_reception_yds:            'Rec Yards',
    player_receptions:               'Receptions',
    player_receiving_tds:            'Rec TDs',
    player_reception_longest:        'Longest Reception',
    player_anytime_td:               'Anytime TD',
    player_first_td:                 'First TD Scorer',
    player_kicking_points:           'Kicking Points',
    player_field_goals:              'Field Goals',
    // Baseball
    batter_hits:                     'Hits',
    batter_home_runs:                'Home Runs',
    batter_rbis:                     'RBIs',
    batter_runs_scored:              'Runs Scored',
    batter_total_bases:              'Total Bases',
    batter_singles:                  'Singles',
    batter_doubles:                  'Doubles',
    batter_triples:                  'Triples',
    batter_walks:                    'Walks',
    batter_strikeouts:               'Batter Strikeouts',
    batter_stolen_bases:             'Stolen Bases',
    batter_hits_runs_rbis:           'H+R+RBI',
    pitcher_strikeouts:              'Pitcher Strikeouts',
    pitcher_hits_allowed:            'Hits Allowed',
    pitcher_walks:                   'Walks Allowed',
    pitcher_earned_runs:             'Earned Runs',
    pitcher_outs:                    'Outs Recorded',
    // Hockey
    player_goals:                    'Goals',
    player_shots_on_goal:            'Shots on Goal',
    player_blocked_shots:            'Blocked Shots',
    player_saves:                    'Saves',
    player_power_play_points:        'PP Points',
    // MMA
    fighter_win_method_ko_tko:       'Win by KO/TKO',
    fighter_win_method_submission:   'Win by Submission',
    fighter_win_method_decision:     'Win by Decision',
    fighter_total_rounds:            'Total Rounds',
    fighter_goes_distance:           'Goes the Distance',
    // Soccer
    player_goal_scorer_anytime:      'Anytime Goalscorer',
    player_goal_scorer_first:        'First Goalscorer',
    player_first_goal_scorer:        'First Goalscorer',
    player_last_goal_scorer:         'Last Goalscorer',
    player_shots_on_target:          'Shots on Target',
};

function fmtBook(key) {
    if (!key) return '—';
    return BOOK_LABELS[key] || key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function fmtMarket(key) {
    if (!key) return '—';
    return MARKET_LABELS[key] || key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}
</script>

<!-- ── Alert Popover ──────────────────────────────────────────────────── -->
<div id="alert-popover" class="alert-popover" hidden role="dialog" aria-label="Set odds alert">
    <div class="alert-popover__header">
        <span class="alert-popover__title">Odds Alert</span>
        <button class="alert-popover__close" aria-label="Close">&times;</button>
    </div>
    <p class="alert-popover__player"></p>
    <div class="alert-popover__row">
        <label class="alert-popover__label" for="alert-direction">Direction</label>
        <select class="alert-popover__select" id="alert-direction">
            <option value="over">Over</option>
            <option value="under">Under</option>
        </select>
    </div>
    <div class="alert-popover__row">
        <label class="alert-popover__label" for="alert-odds">Notify when odds reach</label>
        <div class="alert-popover__odds-wrap">
            <input class="alert-popover__odds-input" id="alert-odds" type="number" value="100" min="-1000" max="2000" step="5">
            <span class="alert-popover__odds-unit">or better</span>
        </div>
    </div>
    <div class="alert-popover__actions">
        <button class="alert-popover__save" id="alert-popover-save">Set Alert</button>
        <button class="alert-popover__delete" id="alert-popover-delete" hidden>Remove Alert</button>
    </div>
</div>

<!-- ── Player Stats Modal ─────────────────────────────────────────────── -->
<div id="player-stats-modal" class="odds-modal player-modal" role="dialog" aria-modal="true" aria-labelledby="player-modal-title" hidden>
    <div class="odds-modal__backdrop"></div>
    <div class="odds-modal__dialog player-modal__dialog">
        <div class="odds-modal__header player-modal__header">
            <img class="player-modal__headshot" src="" alt="" hidden>
            <h2 class="odds-modal__title" id="player-modal-title"></h2>
            <button class="odds-modal__close" aria-label="Close">&times;</button>
        </div>
        <div class="odds-modal__body player-modal__body">
            <div class="player-modal__loading">Loading player data&hellip;</div>
            <div class="player-modal__content" hidden></div>
            <div class="player-modal__error" hidden></div>
        </div>
    </div>
</div>

<!-- ── Combo Breakdown Modal ──────────────────────────────────────────── -->
<div id="combo-breakdown-modal" class="odds-modal" role="dialog" aria-modal="true" aria-labelledby="combo-breakdown-title" hidden>
    <div class="odds-modal__backdrop"></div>
    <div class="odds-modal__dialog combo-breakdown-modal__dialog">
        <div class="odds-modal__header">
            <h2 class="odds-modal__title" id="combo-breakdown-title"></h2>
            <button class="odds-modal__close" aria-label="Close">&times;</button>
        </div>
        <div class="odds-modal__body combo-breakdown-modal__body"></div>
    </div>
</div>


<!-- ── Roster Modal ────────────────────────────────────────────────────── -->
<div id="roster-modal" class="odds-modal roster-modal" role="dialog" aria-modal="true" aria-labelledby="roster-modal-title" hidden>
    <div class="odds-modal__backdrop"></div>
    <div class="odds-modal__dialog roster-modal__dialog">
        <div class="odds-modal__header roster-modal__header">
            <img class="roster-modal__logo" src="" alt="" hidden>
            <h2 class="odds-modal__title" id="roster-modal-title"></h2>
            <button class="odds-modal__close" aria-label="Close">&times;</button>
        </div>
        <div class="odds-modal__body roster-modal__body">
            <div class="roster-modal__content"></div>
        </div>
    </div>
</div>

<!-- ── Compare Bar ────────────────────────────────────────────────────── -->
<div class="compare-bar" id="compare-bar" hidden>
    <div class="compare-bar__slots">
        <div class="compare-bar__slot" id="compare-slot-a">
            <span class="compare-bar__name" id="compare-name-a">—</span>
            <button class="compare-bar__remove" data-slot="a" aria-label="Remove player A">&#x2715;</button>
        </div>
        <span class="compare-bar__vs">vs</span>
        <div class="compare-bar__slot" id="compare-slot-b">
            <span class="compare-bar__name" id="compare-name-b">—</span>
            <button class="compare-bar__remove" data-slot="b" aria-label="Remove player B">&#x2715;</button>
        </div>
    </div>
    <button class="compare-bar__go" id="compare-go" disabled>Compare &#x2192;</button>
    <button class="compare-bar__clear" id="compare-clear">Clear</button>
</div>

<!-- ── Compare Modal ──────────────────────────────────────────────────── -->
<div id="compare-modal" class="odds-modal compare-modal" role="dialog" aria-modal="true" aria-labelledby="compare-modal-title" hidden>
    <div class="odds-modal__backdrop"></div>
    <div class="odds-modal__dialog compare-modal__dialog">
        <div class="odds-modal__header">
            <h2 class="odds-modal__title" id="compare-modal-title">Player Comparison</h2>
            <button class="odds-modal__close" id="compare-modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="odds-modal__body compare-modal__body">
            <div class="compare-modal__loading">Loading&hellip;</div>
            <div class="compare-modal__content" hidden></div>
        </div>
    </div>
</div>

<!-- ── Odds History Modal ─────────────────────────────────────────────── -->
<div id="odds-history-modal" class="odds-modal" role="dialog" aria-modal="true" aria-labelledby="odds-modal-title" hidden>
    <div class="odds-modal__backdrop"></div>
    <div class="odds-modal__dialog">
        <div class="odds-modal__header">
            <h2 class="odds-modal__title" id="odds-modal-title"></h2>
            <button class="odds-modal__close" aria-label="Close">&times;</button>
        </div>
        <div class="odds-modal__body">
            <div id="odds-prop-score" hidden></div>
            <div class="odds-ev-bar" id="odds-ev-bar" hidden></div>
            <div class="odds-chart-toggle" id="odds-chart-toggle" hidden>
                <button class="odds-chart-toggle__btn odds-chart-toggle__btn--active" data-side="over">Over</button>
                <button class="odds-chart-toggle__btn" data-side="under">Under</button>
            </div>
            <canvas id="odds-history-chart"></canvas>
            <p class="odds-modal__empty" hidden></p>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    // ── Helpers ────────────────────────────────────────────────────────────

    function formatDate(isoDate) {
        // Compare against the user's local date so "Today" / "Tomorrow" reflect
        // their clock, not the server's ET bucketing timezone.
        const localTz    = Intl.DateTimeFormat().resolvedOptions().timeZone;
        const todayIso   = new Date().toLocaleDateString('en-CA', { timeZone: localTz });
        const tomorrowDt = new Date();
        tomorrowDt.setDate(tomorrowDt.getDate() + 1);
        const tomorrowIso = tomorrowDt.toLocaleDateString('en-CA', { timeZone: localTz });

        if (isoDate === todayIso)    return 'Today';
        if (isoDate === tomorrowIso) return 'Tomorrow';

        const d = new Date(isoDate + 'T12:00:00');
        return d.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
    }

    function formatTime(isoDatetime) {
        return new Date(isoDatetime).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    }

    function fmtConsensus(n) {
        if (n >= 1000000) return (n / 1000000).toFixed(n % 1000000 === 0 ? 0 : 1).replace(/\.0$/, '') + 'M';
        if (n >= 1000)    return (n / 1000).toFixed(n % 1000 === 0 ? 0 : 1).replace(/\.0$/, '') + 'k';
        return String(n);
    }

    /** Format American odds with explicit +/- sign. */
    function fmtOdds(val) {
        if (val === null || val === undefined) return '—';
        return val >= 0 ? '+' + val : String(val);
    }

    /**
     * Convert American odds to a comparable payout score.
     * Higher = better value for the bettor.
     */
    function oddsScore(val) {
        if (val === null || val === undefined) return -Infinity;
        return val >= 0 ? val : 10000 / Math.abs(val);
    }

    // Returns true if the given event_id belongs to a game ESPN reports as ended.
    function isEventEnded(sportKey, eventId) {
        const liveGames = liveGamesCache[sportKey] ?? [];
        // Find a game row in the events list whose event_id matches.
        const gameRow = document.querySelector(`.game-row[data-event-id="${CSS.escape(eventId)}"]`);
        if (gameRow) return gameRow.classList.contains('game-row--ended');
        // Fallback: check live cache by matching event_id stored on game rows.
        return liveGames.some(g => g.event_id === eventId && g.state === 'post');
    }

    function renderError(container, message) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state__icon">&#x26A0;</div>
                <p class="empty-state__title">Could not load</p>
                <p class="api-error-block">${escHtml(message)}</p>
            </div>`;
    }

    // ── Odds History ───────────────────────────────────────────────────────
    // Keyed by event_id. Each entry is the nested history object returned by
    // statsight_get_odds_history, or null while loading / unavailable.
    const oddsHistoryCache     = {};
    const oddsHistoryCacheTime = {}; // eventId -> Date.now() when last fetched

    // Keyed by event_id. Caches props, rosters, and defense so reopening a
    // game panel skips redundant network requests.
    const propsCache   = {};
    const rosterCache  = {};
    const defenseCache = {};

    // Keyed by "player||marketKey". Stores gamelog array from player stats fetch
    // so hit rate chips can be recomputed when the line stepper changes.
    const playerGamelogCache = {};

    // Keyed by "player||marketKey". Stores full player stats response for prop score.
    const playerStatsCache = {};

    // Keyed by sportKey. Each entry is the live games array from ESPN.
    const liveGamesCache = {};

    // ── Prop Consensus ─────────────────────────────────────────────────────
    // Keyed by "player|marketKey|line|direction" -> count (per event fetch).
    const consensusCache = {};

    function loadConsensusForEvent(eventId) {
        const params = new URLSearchParams({
            action:   'statsight_prop_consensus',
            nonce:    statsightAjax.nonce,
            event_id: eventId,
        });
        fetch(statsightAjax.url + '?' + params.toString())
            .then(r => r.json())
            .then(function (json) {
                if (!json.success) return;
                consensusCache[eventId] = json.data; // map of "player|market|line|dir" -> count
                document.querySelectorAll(`tr[data-player][data-event-id="${CSS.escape(eventId)}"]`).forEach(function (row) {
                    stampConsensusRow(row, eventId);
                });
            })
            .catch(() => {});
    }

    function stampConsensusRow(row, eventId) {
        const map = consensusCache[eventId];
        if (!map) return;

        const player    = row.dataset.player;
        const marketKey = row.dataset.market;
        if (!player || !marketKey) return;

        // Use the current stepper line, same as alert stamping.
        const stepperVal = row.querySelector('.line-stepper__val');
        const line       = stepperVal ? stepperVal.textContent.trim() : null;
        if (!line) return;

        // Sum over+under for this line.
        const overKey  = `${player}|${marketKey}|${line}|over`;
        const underKey = `${player}|${marketKey}|${line}|under`;
        const total    = (map[overKey] || 0) + (map[underKey] || 0);

        let badge = row.querySelector('.consensus-badge');
        if (total >= 1) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'consensus-badge';
                const heartBtn = row.querySelector('.track-bet-btn');
                if (heartBtn) heartBtn.appendChild(badge);
            }
            badge.textContent = '+' + fmtConsensus(total);
            badge.title       = `${total.toLocaleString()} user${total === 1 ? '' : 's'} tracking this prop`;
            badge.hidden      = false;
        } else if (badge) {
            badge.hidden = true;
        }
    }

    function nudgeConsensus(eventId, player, marketKey, line, delta) {
        if (!consensusCache[eventId]) return;
        const map    = consensusCache[eventId];
        const overKey = `${player}|${marketKey}|${line}|over`;
        map[overKey]  = Math.max(0, (map[overKey] || 0) + delta);
    }

    // ── Prop Alerts ────────────────────────────────────────────────────────
    // Keyed by "eventId|player|marketKey|line|direction" -> alert id.
    const alertCache = {};

    function alertCacheKey(eventId, player, marketKey, line, direction) {
        return `${eventId}|${player}|${marketKey}|${line}|${direction}`;
    }

    function loadAlertsForEvent(eventId) {
        const params = new URLSearchParams({
            action:   'statsight_prop_alert_get',
            nonce:    statsightAjax.nonce,
            event_id: eventId,
        });
        fetch(statsightAjax.url + '?' + params.toString())
            .then(r => r.json())
            .then(function (json) {
                if (!json.success) return;
                json.data.forEach(function (a) {
                    const key = alertCacheKey(eventId, a.player, a.market_key, a.line, a.direction);
                    alertCache[key] = { id: a.id, target_odds: parseInt(a.target_odds, 10) };
                });
                document.querySelectorAll(`.alert-btn[data-event-id="${CSS.escape(eventId)}"]`).forEach(stampAlertBtn);
            })
            .catch(() => {});
    }

    function stampAlertBtn(btn) {
        const eventId   = btn.dataset.eventId;
        const player    = btn.closest('tr')?.dataset.player || btn.dataset.player;
        const marketKey = btn.closest('tr')?.dataset.market || btn.dataset.market;

        let hasAlert = false;
        let isFilled = false;

        // Search the entire alertCache for any entry matching this event+player+market.
        // isFilled = the current stepper line matches the line the alert was saved on.
        const currentLine = getAlertLine(btn);
        const prefix = `${eventId}|${player}|${marketKey}|`;
        for (const [key, entry] of Object.entries(alertCache)) {
            if (!key.startsWith(prefix)) continue;
            hasAlert = true;
            // key format: eventId|player|market|line|direction
            const savedLine = key.split('|')[3];
            if (currentLine !== null && savedLine === currentLine) isFilled = true;
            break;
        }

        btn.classList.toggle('alert-btn--active', hasAlert);
        btn.classList.toggle('alert-btn--filled', isFilled);
    }

    // Returns the current line for an alert button — prefers the live stepper
    // value if one exists, falls back to the button's own data-line attribute.
    function getAlertLine(btn) {
        const row = btn.closest('tr');
        if (row) {
            const stepperVal = row.querySelector('.line-stepper__val');
            if (stepperVal) return stepperVal.textContent.trim();
        }
        return btn.dataset.line ?? null;
    }

    // Legacy helper used by the popover context builder.
    function getCurrentLine(row) {
        if (!row) return null;
        const stepperVal = row.querySelector('.line-stepper__val');
        if (stepperVal) return stepperVal.textContent.trim();
        const alertBtn = row.querySelector('.alert-btn');
        return alertBtn?.dataset.line ?? null;
    }

    // ── Alert Popover ──────────────────────────────────────────────────────
    const alertPopover   = document.getElementById('alert-popover');
    const alertPlayer    = alertPopover.querySelector('.alert-popover__player');
    const alertDirection = document.getElementById('alert-direction');
    const alertOddsInput = document.getElementById('alert-odds');
    const alertSaveBtn   = document.getElementById('alert-popover-save');
    const alertDeleteBtn = document.getElementById('alert-popover-delete');

    let _alertContext = null; // { btn, eventId, player, marketKey, line, matchup, sport, marketLabel }

    function openAlertPopover(btn, context) {
        _alertContext = context;
        alertPlayer.textContent = `${context.player} — ${context.marketLabel} ${context.line}`;

        // Check if an active alert exists; if so pre-fill and show delete.
        const existingDir = ['over', 'under'].find(dir =>
            alertCacheKey(context.eventId, context.player, context.marketKey, context.line, dir) in alertCache
        );
        if (existingDir) {
            const existingEntry = alertCache[alertCacheKey(context.eventId, context.player, context.marketKey, context.line, existingDir)];
            alertDirection.value  = existingDir;
            alertOddsInput.value  = existingEntry.target_odds;
            alertSaveBtn.textContent = 'Update Alert';
            alertDeleteBtn.hidden = false;
        } else {
            alertDirection.value = 'over';
            alertOddsInput.value = 100;
            alertSaveBtn.textContent = 'Set Alert';
            alertDeleteBtn.hidden = true;
        }

        // Center the popover in the viewport.
        alertPopover.hidden = false;
        alertPopover.style.top  = '';
        alertPopover.style.left = '';
    }

    function closeAlertPopover() {
        alertPopover.hidden = true;
        _alertContext = null;
    }

    alertPopover.querySelector('.alert-popover__close').addEventListener('click', closeAlertPopover);

    document.addEventListener('click', function (e) {
        if (!alertPopover.hidden && !alertPopover.contains(e.target) && !e.target.closest('.alert-btn')) {
            closeAlertPopover();
        }
    });

    alertSaveBtn.addEventListener('click', function () {
        if (!_alertContext) return;
        const ctx         = _alertContext;
        const direction   = alertDirection.value;
        const targetOdds  = parseInt(alertOddsInput.value, 10);
        if (isNaN(targetOdds)) return;

        // Remove any existing alert for this prop+direction before saving new one.
        const existingKey = alertCacheKey(ctx.eventId, ctx.player, ctx.marketKey, ctx.line, direction);
        if (existingKey in alertCache) {
            fetch(statsightAjax.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'statsight_prop_alert_delete', nonce: statsightAjax.nonce, id: alertCache[existingKey].id }).toString(),
            });
            delete alertCache[existingKey];
        }

        fetch(statsightAjax.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action:       'statsight_prop_alert_set',
                nonce:        statsightAjax.nonce,
                event_id:     ctx.eventId,
                sport:        ctx.sport,
                player:       ctx.player,
                market_key:   ctx.marketKey,
                market_label: ctx.marketLabel,
                line:         ctx.line,
                direction:    direction,
                target_odds:  targetOdds,
                matchup:      ctx.matchup,
            }).toString(),
        })
            .then(r => r.json())
            .then(function (json) {
                if (json.success) {
                    alertCache[existingKey] = { id: json.data.id, target_odds: targetOdds };
                    stampAlertBtn(ctx.btn);
                }
            });

        closeAlertPopover();
    });

    alertDeleteBtn.addEventListener('click', function () {
        if (!_alertContext) return;
        const ctx = _alertContext;
        const direction = alertDirection.value;
        const key = alertCacheKey(ctx.eventId, ctx.player, ctx.marketKey, ctx.line, direction);
        const entry = alertCache[key];
        if (entry) {
            fetch(statsightAjax.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'statsight_prop_alert_delete', nonce: statsightAjax.nonce, id: entry.id }).toString(),
            });
            delete alertCache[key];
        }
        stampAlertBtn(ctx.btn);
        closeAlertPopover();
    });


    // Format a line key for display — strips trailing ".0" so "1.0" shows as "1".
    const fmtLine = (k) => k === 'yn' ? 'Yes' : String(k).replace(/\.0$/, '');

    // Normalise team names for fuzzy matching between ESPN and The Odds API.
    // ESPN uses short forms like "LA Clippers"; Odds API uses "Los Angeles Clippers".
    const normTeamName = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/&/g, 'and')
        .replace(/[^a-z0-9 ]/g, '')
        .replace(/\blos angeles\b/g, 'la')
        .replace(/\bnew york\b/g,    'ny')
        .replace(/\bnew orleans\b/g, 'no')
        .replace(/\bgolden state\b/g,'gs')
        .replace(/\bsan antonio\b/g, 'sa')
        .replace(/\boklahoma city\b/g, 'okc')
        .replace(/\s+/g, ' ')
        .trim();
    const teamNameMatches = (a, b) => {
        const na = normTeamName(a), nb = normTeamName(b);
        return na === nb || na.includes(nb) || nb.includes(na);
    };

    // Keyed by eventId. Stores setInterval IDs for live odds polling.
    const liveOddsPollers = {};

    /**
     * Given the history array for one (market, player, line, book) combo,
     * return { direction: 'up'|'down'|null, delta: number }.
     * Compares the second-to-last snapshot's over_odds to the latest.
     */
    function calcTrend(snapshots) {
        if (!snapshots || snapshots.length < 2) return { direction: null, delta: 0 };
        const prev = snapshots[snapshots.length - 2].over;
        const curr = snapshots[snapshots.length - 1].over;
        if (prev === null || curr === null) return { direction: null, delta: 0 };
        const delta = curr - prev;
        if (delta > 0) return { direction: 'up',   delta };
        if (delta < 0) return { direction: 'down', delta };
        return { direction: null, delta: 0 };
    }

    /**
     * Build a tooltip string showing the last few odds snapshots.
     * e.g. "-115 → -110 → -105"
     */
    function buildHistoryTooltip(snapshots) {
        if (!snapshots || snapshots.length === 0) return '';
        const values = snapshots
            .slice(-5) // last 5 readings
            .map(s => s.over !== null ? fmtOdds(s.over) : '—');
        return values.join(' → ');
    }

    // ── Events List ────────────────────────────────────────────────────────

    function renderEvents(container, sportKey, data) {
        const totalGames = (data.days || []).reduce((n, d) => n + (d.events?.length ?? 0), 0);
        const tabBtn = document.querySelector(`.league-tab-btn[data-tab="${sportKey}"]`);

        if (!data.days || data.days.length === 0) {
            return;
        }

        // Reveal tab and stamp game count badge
        if (tabBtn) {
            tabBtn.hidden = false;
            if (!tabBtn.querySelector('.tab-game-count')) {
                const badge = document.createElement('span');
                badge.className   = 'tab-game-count';
                badge.textContent = totalGames;
                tabBtn.appendChild(badge);
            }
            // Activate this tab if none are active yet
            if (!document.querySelector('.league-tab-btn.is-active')) {
                activateTab(sportKey);
            }
        }

        const sections = data.days.map(function (day) {
            const dateLabel  = day.date ? formatDate(day.date) : 'Upcoming';
            const gameCount  = day.events.length;
            const rows = day.events.map(function (event) {
                const time = event.commence_time ? formatTime(event.commence_time) : 'TBD';
                return `
                <tr class="game-row" role="button" tabindex="0"
                    data-event-id="${escHtml(event.id)}"
                    data-sport="${escHtml(sportKey)}"
                    data-home="${escHtml(event.home_team)}"
                    data-away="${escHtml(event.away_team)}"
                    data-time="${escHtml(time)}"
                    data-commence="${escHtml(event.commence_time || '')}">
                    <td class="col-player">${escHtml(event.home_team)}</td>
                    <td class="col-player">${escHtml(event.away_team)}</td>
                    <td class="col-time">${escHtml(time)}</td>
                    <td class="col-cta"><span class="game-row__cta">View Props ›</span></td>
                </tr>`;
            }).join('');

            return `
            <div class="events-date-header">
                <p class="events-date-label"><strong>${escHtml(dateLabel)}</strong></p>
                <span class="events-game-count">${gameCount} game${gameCount !== 1 ? 's' : ''}</span>
            </div>
            <div class="props-table-wrap">
                <table class="props-table">
                    <thead>
                        <tr>
                            <th>Home</th>
                            <th>Away</th>
                            <th>Time</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;
        }).join('');

        container.innerHTML = sections;

        // Attach click + keyboard handlers to each game row
        container.querySelectorAll('.game-row').forEach(function (row) {
            const open = () => {
                // Don't open props for games that have already ended.
                if (row.classList.contains('game-row--ended')) return;
                openGame(
                    row.dataset.sport,
                    row.dataset.eventId,
                    row.dataset.home,
                    row.dataset.away,
                    row.dataset.time
                );
            };
            row.addEventListener('click', open);
            row.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
            });
        });

        // Batch-fetch player names for all games so panel search works immediately
        prefetchRosterNames(container, sportKey, data);
    }

    /**
     * Fetch player names for all games in one request and stamp data-players
     * on each game row so the panel search can filter by player name.
     */
    function prefetchRosterNames(container, sportKey, data) {
        const games = (data.days || []).flatMap(d => d.events || []).map(e => ({
            event_id: e.id,
            home:     e.home_team,
            away:     e.away_team,
        }));

        if (games.length === 0) return;

        const params = new URLSearchParams({
            action: 'statsight_get_roster_names',
            nonce:  statsightAjax.nonce,
            sport:  sportKey,
            games:  JSON.stringify(games),
        });

        fetch(statsightAjax.url + '?' + params.toString())
            .then(r => r.json())
            .then(function (json) {
                if (!json.success) return;
                const rosterMap = json.data; // { event_id: [playerName, ...] }
                container.querySelectorAll('.game-row').forEach(function (row) {
                    const players = rosterMap[row.dataset.eventId];
                    if (players && players.length > 0) {
                        row.dataset.players = players.join('||').toLowerCase();
                    }
                });
            })
            .catch(() => {});
    }

    /**
     * Stamp game rows with Live or Ended badges based on ESPN game statuses.
     * Called after renderEvents and after the live games fetch resolves.
     */
    function applyLiveBadges(container, games) {
        if (!games || games.length === 0) return;

        container.querySelectorAll('.game-row').forEach(function (row) {
            const home       = row.dataset.home || '';
            const away       = row.dataset.away || '';
            const commenceEt = row.dataset.commence
                ? new Date(row.dataset.commence).toLocaleDateString('en-CA', { timeZone: 'America/New_York' })
                : null;
            const match = games.find(g =>
                teamNameMatches(home, g.home) &&
                teamNameMatches(away, g.away) &&
                // Only match if ESPN's game date aligns with the row's commence date.
                // Prevents yesterday's final from greying out today's rematch.
                (!commenceEt || !g.date_et || g.date_et === commenceEt)
            );
            if (!match) return;

            const timeCell = row.querySelector('.col-time');
            if (!timeCell) return;

            const hasScore = match.home_score !== '' && match.away_score !== '';
            const scoreHtml = hasScore
                ? ` <span class="game-score">${escHtml(match.home_score)}–${escHtml(match.away_score)}</span>`
                : '';
            const periodHtml = match.period_label
                ? ` <span class="game-period">${escHtml(match.period_label)}</span>`
                : '';

            if (match.state === 'in') {
                timeCell.innerHTML = `<span class="live-badge">LIVE</span>${scoreHtml}${periodHtml}`;
                row.classList.add('game-row--live');
                row.classList.remove('game-row--ended');
            } else if (match.state === 'post') {
                timeCell.innerHTML = `<span class="ended-badge">FINAL</span>${scoreHtml}`;
                row.classList.add('game-row--ended');
                row.classList.remove('game-row--live');
            }
        });

        // Push ended rows to the bottom of each tbody.
        container.querySelectorAll('.props-table tbody').forEach(function (tbody) {
            const ended = [...tbody.querySelectorAll('.game-row--ended')];
            ended.forEach(row => tbody.appendChild(row));
        });
    }

    /**
     * Inject team logos into game row name cells.
     * logos is an object keyed by team displayName -> logo URL.
     *
     * ESPN uses short displayNames (e.g. "Celtics") while The Odds API uses full
     * names (e.g. "Boston Celtics"), so we do a loose match: check if either
     * string contains the other (case-insensitive).
     */
    function applyTeamLogos(container, logos) {
        if (!logos || Object.keys(logos).length === 0) return;

        const logoEntries = Object.entries(logos); // [[displayName, url], ...]

        /**
         * Find a logo URL for a given full team name by checking whether the
         * full name contains the ESPN displayName or vice versa.
         */
        const cityAbbrevMap = { 'los angeles': 'la', 'new york': 'ny', 'golden state': 'gs' };
        // Known mismatches between Odds API names and ESPN display names.
        const teamAliases = { 'los angeles fc': 'lafc', 'la fc': 'lafc', 'inter miami cf': 'inter miami', 'new york red bulls': 'red bull ny', 'cf montreal': 'cf montréal' };
        function normaliseName(name) {
            let n = name.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/&/g, 'and').replace(/[^a-z0-9 ]/g, '').replace(/\s+/g, ' ').trim();
            if (teamAliases[n]) return teamAliases[n];
            for (const [long, short] of Object.entries(cityAbbrevMap)) {
                n = n.replace(long, short);
            }
            return n;
        }

        function findLogoUrl(fullName) {
            // Exact match first
            if (logos[fullName]) return logos[fullName];
            const normFull = normaliseName(fullName);
            // Partial match: compare normalised names
            const entry = logoEntries.find(([key]) => {
                const normKey = normaliseName(key);
                return normFull === normKey || normFull.includes(normKey) || normKey.includes(normFull);
            });
            return entry ? entry[1] : null;
        }

        container.querySelectorAll('.game-row').forEach(function (row) {
            row.querySelectorAll('.col-player').forEach(function (cell) {
                if (cell.querySelector('.team-logo')) return; // already applied
                const name = cell.textContent.trim();
                const url  = findLogoUrl(name);
                if (!url) return;
                const img = document.createElement('img');
                img.src       = url;
                img.alt       = '';
                img.className = 'team-logo';
                cell.insertBefore(img, cell.firstChild);
            });
        });
    }

    // Maps a market key to the primary ESPN boxscore stat label to display inline.
    const MARKET_STAT = {
        // NBA / NCAAB
        player_points:               'PTS',
        player_rebounds:             'REB',
        player_assists:              'AST',
        player_threes:               '3PT',
        player_blocks:               'BLK',
        player_steals:               'STL',
        player_turnovers:            'TO',
        player_blocks_steals:        'BLK', // show BLK; STL shown via tooltip
        player_field_goals:          'FG',
        player_frees_made:           'FT',
        player_points_rebounds_assists: 'PTS',
        player_points_rebounds:      'PTS',
        player_points_assists:       'PTS',
        player_rebounds_assists:     'REB',
        // NFL
        player_pass_yds:             'YDS',
        player_pass_tds:             'TD',
        player_pass_attempts:        'ATT',
        player_pass_completions:     'COMP',
        player_rush_yds:             'YDS',
        player_rush_attempts:        'CAR',
        player_reception_yds:        'YDS',
        player_receptions:           'REC',
        player_pass_interceptions:   'INT',
        // MLB
        player_strikeouts:           'K',
        player_hits:                 'H',
        player_home_runs:            'HR',
        player_runs_scored:          'R',
        player_rbis:                 'RBI',
        batter_strikeouts:           'SO',
        // NHL
        player_shots_on_goal:        'SOG',
        player_goals:                'G',
        player_assists_hockey:       'A',
        player_points_hockey:        'PTS',
        player_blocked_shots:        'BS',
        player_power_play_points:    'PTS',
        player_total_saves:          'SV',
    };

    /**
     * Stamp player rows with their current live stat from the ESPN boxscore.
     * boxscore = { "Player Name": { "PTS": "22", "REB": "8", ... }, ... }
     */
    function applyLiveStats(detail, boxscore, sport = '') {
        // Loose name match — strips suffixes (Jr., Sr., II, III) and punctuation
        // so "Jabari Smith Jr." matches ESPN's "Jabari Smith" and vice versa.
        const normName = (n) => n.toLowerCase()
            .replace(/\b(jr|sr|ii|iii|iv)\.?$/i, '')
            .replace(/[.']/g, '')
            .trim();
        const findStats = (playerName) => {
            if (boxscore[playerName]) return boxscore[playerName];
            const lower = playerName.toLowerCase();
            const key = Object.keys(boxscore).find(k => k.toLowerCase() === lower);
            if (key) return boxscore[key];
            // Suffix-normalized fallback
            const norm = normName(playerName);
            const normKey = Object.keys(boxscore).find(k => normName(k) === norm);
            return normKey ? boxscore[normKey] : null;
        };

        detail.querySelectorAll('tr[data-player]').forEach(function (row) {
            const player    = row.dataset.player;
            const marketKey = (row.dataset.market || '').replace(/_alternate$/, '');
            const isHockey  = sport.startsWith('icehockey');
            const NHL_STAT  = { player_assists: 'A', player_points: 'PTS' };
            const statLabel = (isHockey && NHL_STAT[marketKey]) ? NHL_STAT[marketKey] : MARKET_STAT[marketKey];
            const stats     = findStats(player);

            if (!stats || !statLabel || !stats[statLabel] || stats[statLabel] === '—') return;

            const playerCell = row.querySelector('.col-player');
            if (!playerCell || playerCell.querySelector('.live-stat')) return;

            const chip = document.createElement('span');
            chip.className   = 'live-stat';
            // For made/attempted stats (e.g. "3/7" or "1-3"), show only the made count.
            const displayVal = String(stats[statLabel]).split(/[\/\-]/)[0];
            chip.textContent = `${displayVal} ${statLabel}`;
            chip.title       = 'Current game stat';
            // Insert before the hit-rate chip so order is: name → live stat → hit rate
            const hitChip = playerCell.querySelector('.hit-rate-chip');
            hitChip ? playerCell.insertBefore(chip, hitChip) : playerCell.appendChild(chip);
        });
    }

    /**
     * Fire per-game rest day requests and inject B2B / rest indicators
     * into the time cell of each game row.
     */
    function applyRestDays(container, sportKey) {
        if (sportKey.startsWith('soccer_')) return; // Soccer plays weekly — rest days not meaningful

        const injectTag = (cell, html) => {
            if (!cell || !html) return;
            cell.insertAdjacentHTML('beforeend', html);
            const tag = cell.lastElementChild;
            if (tag) {
                tag.classList.add('chip-fade-in');
                tag.addEventListener('animationend', () => tag.classList.remove('chip-fade-in'), { once: true });
            }
        };

        const makeTag = (restData) => {
            if (!restData || restData.days_rest === null) return '';
            const days = restData.days_rest;
            if (days <= 1) return `<span class="rest-tag rest-tag--b2b" title="Back-to-back">B2B</span>`;
            if (days <= 3) return `<span class="rest-tag rest-tag--short" title="${days} days rest">${days}d rest</span>`;
            return '';
        };

        const applyResult = (row, hRest, aRest) => {
            row.dataset.restHome = JSON.stringify(hRest);
            row.dataset.restAway = JSON.stringify(aRest);
            const gameDetail = document.querySelector(`.game-detail[data-event-id="${CSS.escape(row.dataset.eventId || '')}"]`);
            if (gameDetail) {
                gameDetail.dataset.restHome = JSON.stringify(hRest);
                gameDetail.dataset.restAway = JSON.stringify(aRest);
            }
            const homeTag = makeTag(hRest);
            const awayTag = makeTag(aRest);
            if (!homeTag && !awayTag) return;
            const cells = row.querySelectorAll('.col-player');
            injectTag(cells[0], homeTag);
            injectTag(cells[1], awayTag);
        };

        const games = [];
        const rowMap = {};
        container.querySelectorAll('.game-row').forEach(function (row) {
            const home     = row.dataset.home || '';
            const away     = row.dataset.away || '';
            const gameTime = row.dataset.commence || '';
            const eventId  = row.dataset.eventId || '';
            if (!home || !away || !gameTime || !eventId) return;
            games.push({ event_id: eventId, sport: sportKey, home, away, game_time: gameTime });
            rowMap[eventId] = row;
        });

        if (!games.length) return;

        const body = new URLSearchParams({
            action: 'statsight_get_rest_days_batch',
            nonce:  statsightAjax.nonce,
            games:  JSON.stringify(games),
        });

        fetch(statsightAjax.url, { method: 'POST', body })
            .then(r => r.json())
            .then(function (json) {
                if (!json.success) return;
                Object.entries(json.data).forEach(function ([eventId, data]) {
                    const row = rowMap[eventId];
                    if (!row || !data || !data.home) return;
                    applyResult(row, data.home, data.away);
                });
            })
            .catch(() => {}); // non-critical — fail silently
    }

    function loadEvents(sportKey) {
        const panel     = document.getElementById('panel-' + sportKey);
        const container = panel ? panel.querySelector('.panel-events') : null;
        if (!container || container.dataset.loaded === 'true') return;

        const liveParams = new URLSearchParams({
            action: 'statsight_get_live_games',
            nonce:  statsightAjax.nonce,
            sport:  sportKey,
        });

        const liveReq = fetch(statsightAjax.url + '?' + liveParams.toString())
            .then(r => r.json())
            .catch(() => ({ success: false }));

        // Use prefetched data if available, otherwise fetch fresh
        const prefetched = container.dataset.prefetched ? JSON.parse(container.dataset.prefetched) : null;
        const eventsReq  = prefetched
            ? Promise.resolve({ success: true, data: prefetched })
            : fetch(statsightAjax.url + '?' + new URLSearchParams({
                action: 'statsight_get_events',
                nonce:  statsightAjax.nonce,
                sport:  sportKey,
              }).toString()).then(r => r.json());

        Promise.all([eventsReq, liveReq])
            .then(function ([eventsJson, liveJson]) {
                if (eventsJson.success) {
                    renderEvents(container, sportKey, eventsJson.data);
                    if (liveJson.success) {
                        const liveGames = liveJson.data.live ?? [];
                        liveGamesCache[sportKey] = liveGames;
                        applyLiveBadges(container, liveGames);
                        applyTeamLogos(container,  liveJson.data.logos ?? {});
                    }
                    applyRestDays(container, sportKey);
                }
                container.dataset.loaded = 'true';
            })
            .catch(function () {
                container.dataset.loaded = 'true';
            });
    }

    // ── Game Detail / Props View ───────────────────────────────────────────

    /**
     * Open the props overlay for a specific game.
     */
    function openGame(sportKey, eventId, home, away, time) {
        const panel     = document.getElementById('panel-' + sportKey);
        const eventsEl  = panel.querySelector('.panel-events');

        // Build the detail shell (replaces events list within the same panel)
        const sportTitle = document.querySelector(`.league-tab-btn[data-tab="${sportKey}"]`)?.textContent.trim() || sportKey;
        const matchupStr = `${escHtml(home)} vs ${escHtml(away)}`;

        // Look up live score for this game — match on date too to avoid
        // confusing yesterday's final with today's rematch.
        const commenceEt = row => row.dataset?.commence
            ? new Date(row.dataset.commence).toLocaleDateString('en-CA', { timeZone: 'America/New_York' })
            : null;
        const gameRow    = document.querySelector(`.game-row[data-event-id="${CSS.escape(eventId)}"]`);
        const gameDate   = gameRow ? new Date(gameRow.dataset.commence || '').toLocaleDateString('en-CA', { timeZone: 'America/New_York' }) : null;
        const liveMatch = (liveGamesCache[sportKey] ?? []).find(g =>
            teamNameMatches(home, g.home) &&
            teamNameMatches(away, g.away) &&
            (!gameDate || !g.date_et || g.date_et === gameDate)
        );
        let scoreHtml = '';
        let blowoutHtml = '';
        if (liveMatch && liveMatch.home_score !== '' && liveMatch.away_score !== '') {
            const badge = liveMatch.state === 'in'
                ? `<span class="live-badge">LIVE</span>`
                : `<span class="ended-badge">FINAL</span>`;
            const period = liveMatch.period_label
                ? `<span class="game-detail__score-period">${escHtml(liveMatch.period_label)}</span>`
                : '';
            scoreHtml = `
                <div class="game-detail__score">
                    ${badge}
                    <span class="game-detail__score-line">
                        ${escHtml(home)} <strong>${escHtml(liveMatch.home_score)}</strong>
                        <span class="game-detail__score-sep">–</span>
                        <strong>${escHtml(liveMatch.away_score)}</strong> ${escHtml(away)}
                    </span>
                    ${period}
                </div>`;

            if (liveMatch.state === 'in') {
                const margin = Math.abs(parseInt(liveMatch.home_score, 10) - parseInt(liveMatch.away_score, 10));
                if (margin >= 15) {
                    blowoutHtml = `<p class="blowout-notice">&#9888; This game has a ${margin}-point margin — key players may rest in the 4th quarter, which could impact stats.</p>`;
                } else if (liveMatch.period >= 4 && margin <= 5 && sportKey.includes('basketball')) {
                    blowoutHtml = `<p class="blowout-notice blowout-notice--crunch">&#128248; Q4 crunch time — intentional fouling may inflate points and FTA for key players, and suppress assists and blocks.</p>`;
                }
            }
        }

        const detail = document.createElement('div');
        detail.className = 'game-detail';
        detail.dataset.home      = home;
        detail.dataset.away      = away;
        detail.dataset.eventId   = eventId;
        detail.dataset.sportKey  = sportKey;
        // Copy rest days from game-row if already fetched (applyRestDays fires at load time).
        const gameRowEl = document.querySelector(`.game-row[data-event-id="${CSS.escape(eventId)}"]`);
        if (gameRowEl?.dataset.restHome) detail.dataset.restHome = gameRowEl.dataset.restHome;
        if (gameRowEl?.dataset.restAway) detail.dataset.restAway = gameRowEl.dataset.restAway;
        detail.innerHTML = `
            <nav class="game-detail__breadcrumb" aria-label="Breadcrumb">
                <button class="game-detail__bc-btn" aria-label="Back to game list">
                    ${escHtml(sportTitle)}
                </button>
                <span class="game-detail__bc-sep" aria-hidden="true">›</span>
                <span class="game-detail__bc-current">${matchupStr}</span>
                ${!scoreHtml && time ? `<span class="game-detail__time">${escHtml(time)}</span>` : ''}
            </nav>
            ${scoreHtml}
            ${blowoutHtml}
            <div class="game-detail__cat-tabs" hidden>
            </div>
            <div class="game-detail__controls" hidden>
                <input class="game-detail__search" type="search" placeholder="Search player&hellip;" aria-label="Search players">
                <label class="game-detail__sort-label" for="props-sort-${escHtml(eventId)}">Sort by</label>
                <select class="game-detail__sort" id="props-sort-${escHtml(eventId)}">
                    <option value="default">Default</option>
                    <option value="best_odds">Best odds</option>
                    <option value="ev">Highest EV%</option>
                    <option value="edge">Biggest edge</option>
                    <option value="hit_rate">Hit rate</option>
                </select>
            </div>
            <div class="game-detail__body">
                <div class="empty-state empty-state--loading"><p class="empty-state__title">Loading&hellip;</p></div>
            </div>`;

        const toolbar = panel.querySelector('.panel-toolbar');
        eventsEl.style.display = 'none';
        if (toolbar) toolbar.hidden = true;
        panel.appendChild(detail);

        detail.querySelector('.game-detail__bc-btn').addEventListener('click', function () {
            if (liveOddsPollers[eventId]) {
                clearTimeout(liveOddsPollers[eventId]);
                delete liveOddsPollers[eventId];
            }
            detail.remove();
            eventsEl.style.display = '';
            if (toolbar) toolbar.hidden = false;
        });

        loadProps(sportKey, eventId, detail, home, away, gameDate);
    }

    /**
     * Fetch props, history, and rosters in parallel for an event, then render.
     */
    function loadProps(sportKey, eventId, detail, home, away, gameDate = null) {
        const propsParams = new URLSearchParams({
            action:   'statsight_get_props',
            nonce:    statsightAjax.nonce,
            sport:    sportKey,
            event_id: eventId,
        });


        const rosterParams = new URLSearchParams({
            action: 'statsight_get_rosters',
            nonce:  statsightAjax.nonce,
            sport:  sportKey,
            home:   home,
            away:   away,
        });

        const defenseParams = new URLSearchParams({
            action: 'statsight_get_defense_rankings',
            nonce:  statsightAjax.nonce,
            sport:  sportKey,
        });

        const isLive = !!(liveGamesCache[sportKey] ?? []).find(g =>
            teamNameMatches(home, g.home) &&
            teamNameMatches(away, g.away) &&
            (!gameDate || !g.date_et || g.date_et === gameDate) &&
            (g.state === 'in' || g.state === 'post')
        );

        const boxscoreParams = new URLSearchParams({
            action: 'statsight_get_live_boxscore',
            nonce:  statsightAjax.nonce,
            sport:  sportKey,
            home,
            away,
        });

        // Props: always re-fetch for live games so odds stay fresh; use cache otherwise.
        const propsReq = (isLive || !propsCache[eventId])
            ? fetch(statsightAjax.url + '?' + propsParams.toString()).then(r => r.json())
            : Promise.resolve(propsCache[eventId]);

        const rosterReq = rosterCache[eventId]
            ? Promise.resolve(rosterCache[eventId])
            : fetch(statsightAjax.url + '?' + rosterParams.toString())
                .then(r => r.json())
                .catch(() => ({ success: false }));

        // MMA has no team defense rankings — skip the request entirely.
        const defenseReq = sportKey === 'mma_mixed_martial_arts'
            ? Promise.resolve({ success: false })
            : defenseCache[sportKey]
                ? Promise.resolve(defenseCache[sportKey])
                : fetch(statsightAjax.url + '?' + defenseParams.toString())
                    .then(r => r.json())
                    .catch(() => ({ success: false }));

        const boxscoreReq = isLive
            ? fetch(statsightAjax.url + '?' + boxscoreParams.toString()).then(r => r.json()).catch(() => ({ success: false }))
            : Promise.resolve({ success: false });

        Promise.all([propsReq, rosterReq, defenseReq, boxscoreReq])
            .then(function ([propsJson, rosterJson, defenseJson, boxscoreJson]) {
                if (!propsJson.success) {
                    renderError(detail.querySelector('.game-detail__body'), propsJson.data?.message || 'Unknown error.');
                    return;
                }
                // Populate caches for subsequent panel opens.
                if (propsJson.success) propsCache[eventId] = propsJson;
                const rosters = rosterJson.success ? rosterJson.data : null;
                const defense = defenseJson.success ? defenseJson.data : null;
                if (rosters) rosterCache[eventId]     = rosterJson;
                if (defense) defenseCache[sportKey]   = defenseJson;
                const boxscore  = boxscoreJson.success  ? boxscoreJson.data : null;
                // Store defense rankings and team names on the detail element for player modal access
                detail.dataset.home    = home;
                detail.dataset.away    = away;
                detail.dataset.defense = defense ? JSON.stringify(defense) : '';
                detail.dataset.rosters = rosters ? JSON.stringify(rosters) : '';
                renderProps(detail, propsJson.data, eventId, rosters);
                if (boxscore) applyLiveStats(detail, boxscore, sportKey);
                wirePropsSort(detail);
                wireGameDetailSearch(detail);
                prefetchHitRates(detail, sportKey, eventId, home, away);
                if (statsightAjax.plan !== 'free') loadAlertsForEvent(eventId);
                loadConsensusForEvent(eventId);
                const liveGame = (liveGamesCache[sportKey] ?? []).find(g =>
                    teamNameMatches(home, g.home) && teamNameMatches(away, g.away) &&
                    (!gameDate || !g.date_et || g.date_et === gameDate)
                ) ?? null;
                injectPollCard(detail, sportKey, eventId, propsJson.data);
                injectAiCard(detail, sportKey, eventId, home, away, propsJson.data, liveGame);
                detail.querySelector('.game-detail__cat-tabs').hidden = false;
                detail.querySelector('.game-detail__controls').hidden = false;

            })
            .catch(function (err) {
                renderError(detail.querySelector('.game-detail__body'), err.message || 'Request failed.');
            });

        // Always start polling when a game detail opens — the interval checks
        // live state internally and stops itself once the game ends.
        startLiveOddsPolling(sportKey, eventId, detail, home, away, gameDate);
    }

    function startLiveOddsPolling(sportKey, eventId, detail, home, away, gameDate = null) {
        // Clear any existing poller for this event.
        if (liveOddsPollers[eventId]) {
            clearTimeout(liveOddsPollers[eventId]);
        }

        function schedulePoll() {
            const gameState = (liveGamesCache[sportKey] ?? []).find(g =>
                teamNameMatches(home, g.home) &&
                teamNameMatches(away, g.away) &&
                (!gameDate || !g.date_et || g.date_et === gameDate)
            )?.state;
            // 30s when live, 60s pre-game
            const interval = gameState === 'in' ? 30 * 1000 : 60 * 1000;
            liveOddsPollers[eventId] = setTimeout(poll, interval);
        }

        function poll() {
            // Stop polling if the detail has been removed from the DOM.
            if (!detail.isConnected) {
                delete liveOddsPollers[eventId];
                return;
            }

            // Stop polling once the game is no longer live.
            // Include date guard so a yesterday rematch doesn't trigger game-over.
            const gameState = (liveGamesCache[sportKey] ?? []).find(g =>
                teamNameMatches(home, g.home) &&
                teamNameMatches(away, g.away) &&
                (!gameDate || !g.date_et || g.date_et === gameDate)
            )?.state;
            if (gameState && gameState !== 'in') {
                delete liveOddsPollers[eventId];
                // Replace props with a game-over notice.
                const bodyEl = detail.querySelector('.game-detail__body');
                if (bodyEl) {
                    bodyEl.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state__icon">&#x1F3C1;</div>
                            <p class="empty-state__title">Game Over</p>
                            <p class="empty-state__desc">This game has ended. Props are no longer available to bet.</p>
                        </div>`;
                }
                return;
            }

            // Pause when the tab is backgrounded.
            if (document.visibilityState !== 'visible') return;

            const params = new URLSearchParams({
                action:    'statsight_get_props',
                nonce:     statsightAjax.nonce,
                sport:     sportKey,
                event_id:  eventId,
                live_poll: '1',
            });

            fetch(statsightAjax.url + '?' + params.toString())
                .then(r => r.json())
                .then(function (json) {
                    if (!json.success) return;

                    // Re-seed all odds cells with fresh data without re-rendering the full table.
                    const { props, default_lines } = json.data;
                    detail.querySelectorAll('tr[data-player][data-market]').forEach(function (row) {
                        const marketKey = row.dataset.market;
                        const player    = row.dataset.player;
                        const stepper   = row.querySelector('.line-stepper');
                        const freeTd    = stepper ? null : row.querySelector('td.col-line-stepper[data-lines]');
                        const dataSource = stepper ?? freeTd;
                        if (!dataSource) return;

                        const playerData = props[marketKey]?.[player];
                        if (!playerData) return;

                        // Update the data attributes with the fresh line map.
                        // Write raw JSON — dataset setter doesn't need &quot; encoding.
                        dataSource.dataset.lines = JSON.stringify(playerData);

                        // Update the default line if it changed.
                        const newDefault = default_lines[marketKey]?.[player];
                        if (newDefault) {
                            const valEl = dataSource.querySelector('.line-stepper__val');
                            if (valEl && !row.dataset.userAdjusted) {
                                valEl.textContent = newDefault;
                            }
                        }

                        refreshPlayerRow(row);
                    });

                    // Notify renderProps closure so lazy tabs use fresh data.
                    detail.dispatchEvent(new CustomEvent('statsight:props-updated', { detail: { props } }));

                    // Update the "last updated" stale notice if present.
                    const staleNotice = detail.querySelector('.stale-notice');
                    if (staleNotice) staleNotice.remove();
                })
                .catch(() => {}); // Non-critical — fail silently.

            // Also refresh the live score display from ESPN.
            const liveParams = new URLSearchParams({
                action: 'statsight_get_live_games',
                nonce:  statsightAjax.nonce,
                sport:  sportKey,
            });
            fetch(statsightAjax.url + '?' + liveParams.toString())
                .then(r => r.json())
                .then(function (json) {
                    if (!json.success) return;
                    const liveGames = json.data.live ?? [];
                    liveGamesCache[sportKey] = liveGames;

                    const match = liveGames.find(g =>
                        (home.toLowerCase().includes(g.home?.toLowerCase()) || g.home?.toLowerCase().includes(home.toLowerCase())) &&
                        (away.toLowerCase().includes(g.away?.toLowerCase()) || g.away?.toLowerCase().includes(away.toLowerCase()))
                    );
                    if (!match || match.home_score === '') return;

                    const scoreEl = detail.querySelector('.game-detail__score');
                    if (!scoreEl) return;

                    const badge  = match.state === 'in'
                        ? `<span class="live-badge">LIVE</span>`
                        : `<span class="ended-badge">FINAL</span>`;
                    const period = match.period_label
                        ? `<span class="game-detail__score-period">${escHtml(match.period_label)}</span>`
                        : '';
                    scoreEl.innerHTML = `
                        ${badge}
                        <span class="game-detail__score-line">
                            ${escHtml(home)} <strong>${escHtml(match.home_score)}</strong>
                            <span class="game-detail__score-sep">–</span>
                            <strong>${escHtml(match.away_score)}</strong> ${escHtml(away)}
                        </span>
                        ${period}`;

                    // Update blowout/crunch notice.
                    const existingNotice = detail.querySelector('.blowout-notice');
                    if (existingNotice) existingNotice.remove();
                    if (match.state === 'in') {
                        const margin = Math.abs(parseInt(match.home_score, 10) - parseInt(match.away_score, 10));
                        const scoreDiv = detail.querySelector('.game-detail__score');
                        if (margin >= 15) {
                            scoreDiv.insertAdjacentHTML('afterend', `<p class="blowout-notice">&#9888; This game has a ${margin}-point margin — key players may rest in the 4th quarter, which could impact stats.</p>`);
                        } else if (match.period >= 4 && margin <= 5 && detail.dataset.sportKey?.includes('basketball')) {
                            scoreDiv.insertAdjacentHTML('afterend', `<p class="blowout-notice blowout-notice--crunch">&#128248; Q4 crunch time — intentional fouling may inflate points and FTA for key players, and suppress assists and blocks.</p>`);
                        }
                    }
                })
                .catch(() => {});

            // Refresh live stat chips (PTS, REB, etc.) from the ESPN boxscore.
            const boxscoreParams = new URLSearchParams({
                action: 'statsight_get_live_boxscore',
                nonce:  statsightAjax.nonce,
                sport:  sportKey,
                home,
                away,
            });
            fetch(statsightAjax.url + '?' + boxscoreParams.toString())
                .then(r => r.json())
                .then(function (json) {
                    if (!json.success || !json.data) return;
                    // Remove existing live-stat chips so they get re-stamped fresh.
                    detail.querySelectorAll('.live-stat').forEach(el => el.remove());
                    applyLiveStats(detail, json.data, sportKey);
                })
                .catch(() => {});

            schedulePoll();
        }

        schedulePoll();
    }

    /**
     * Render the category sub-tabs and props tables into the detail view.
     */
    function renderProps(detail, data, eventId, rosters) {
        const { categories, market_labels, books, default_lines } = data;
        let props = data.props; // mutable ref so live polls can update lazy tabs

        // Filter displayed book columns by user preference; edge/EV still uses all books.
        const activeBooks = statsightAjax.activeBooks; // null = all, array = filtered
        const allBookKeys = Object.keys(books);
        const bookKeys    = activeBooks
            ? allBookKeys.filter(bk => activeBooks.includes(bk))
            : allBookKeys;
        // Always fall back to all books if the filter would leave zero columns.
        const effectiveBookKeys = bookKeys.length > 0 ? bookKeys : allBookKeys;
        const catTabsEl  = detail.querySelector('.game-detail__cat-tabs');
        const bodyEl     = detail.querySelector('.game-detail__body');
        // Keep props current so lazy tabs render with the latest data.
        detail.addEventListener('statsight:props-updated', function (e) {
            props = e.detail.props;
        });

        // Filter to only categories that have at least one market with data
        const activeCats = Object.entries(categories).filter(function ([catKey, cat]) {
            return cat.markets.some(m => props[m] && Object.keys(props[m]).length > 0);
        });

        if (activeCats.length === 0) {
            bodyEl.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state__icon">&#x1F4CA;</div>
                    <p class="empty-state__title">No props available yet</p>
                    <p class="empty-state__desc">Books haven't posted lines for this game yet. Check back closer to tip-off.</p>
                </div>`;
            return;
        }

        // Show stale data notice if the payload came from the DB fallback
        const existingNotice = detail.querySelector('.stale-notice');
        if (existingNotice) existingNotice.remove();

        if (data.stale && data.last_updated) {
            const updatedAt  = new Date(data.last_updated + 'Z'); // stored as UTC
            const minutesAgo = Math.round((Date.now() - updatedAt.getTime()) / 60000);
            const timeLabel  = minutesAgo < 2 ? 'just now'
                : minutesAgo < 60 ? `${minutesAgo} minutes ago`
                : `${Math.round(minutesAgo / 60)} hours ago`;

            const notice = document.createElement('p');
            notice.className = 'stale-notice';
            notice.innerHTML = `&#9888; Live odds unavailable &mdash; showing cached data from <strong>${escHtml(timeLabel)}</strong>.`;
            detail.insertBefore(notice, catTabsEl);
        }

        // Build category tab buttons — cat-tabs stays hidden until after
        // injectPollCard and injectAiCard run (called by the caller).
        catTabsEl.innerHTML = activeCats.map(function ([catKey, cat], i) {
            return `<button class="cat-tab-btn${i === 0 ? ' is-active' : ''}" data-cat="${escHtml(catKey)}">${escHtml(cat.label)}</button>`;
        }).join('');

        // Render only the first tab panel immediately; mark others as lazy.
        bodyEl.innerHTML = activeCats.map(function ([catKey, cat], i) {
            if (i === 0) {
                return `<div class="cat-panel is-active" data-cat="${escHtml(catKey)}">
                    ${renderCatPanel(cat, props, market_labels, effectiveBookKeys, books, default_lines, rosters, eventId)}
                </div>`;
            }
            return `<div class="cat-panel" data-cat="${escHtml(catKey)}" data-lazy="1"></div>`;
        }).join('');

        function initPanel(panel) {
            if (!panel.dataset.lazy) return;
            delete panel.dataset.lazy;
            const catKey = panel.dataset.cat;
            const [, cat] = activeCats.find(([k]) => k === catKey) || [];
            if (!cat) return;
            panel.innerHTML = renderCatPanel(cat, props, market_labels, effectiveBookKeys, books, default_lines, rosters, eventId);
            panel.querySelectorAll('tr[data-player]').forEach(function (row) {
                row.dataset.eventId = eventId;
                refreshPlayerRow(row);
            });
            stampWatchlistButtons(panel);
            if (rosters) applyRosterLogos(panel, rosters);
            const panelSportKey = detail.closest('[data-sport-key]')?.dataset.sportKey || '';
            prefetchHitRates(detail, panelSportKey, eventId, detail.dataset.home || '', detail.dataset.away || '');
        }

        // Inject team logos into first panel
        const firstPanel = bodyEl.querySelector('.cat-panel.is-active');
        if (rosters && firstPanel) applyRosterLogos(firstPanel, rosters);

        // Tag first panel rows with eventId and seed odds
        bodyEl.querySelectorAll('tr[data-player]').forEach(function (row) {
            row.dataset.eventId = eventId;
        });

        // Stamp watchlist button states for the first panel
        stampWatchlistButtons(bodyEl);

        // Seed all visible player rows on first render
        bodyEl.querySelectorAll('tr[data-player]').forEach(refreshPlayerRow);

        // Single delegated listener for all steppers in this detail view
        bodyEl.addEventListener('click', handleStepperClick);

        // Category tab switching — lazy-render on first click
        catTabsEl.querySelectorAll('.cat-tab-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                catTabsEl.querySelectorAll('.cat-tab-btn').forEach(b => b.classList.remove('is-active'));
                bodyEl.querySelectorAll('.cat-panel').forEach(p => p.classList.remove('is-active'));
                btn.classList.add('is-active');
                const panel = bodyEl.querySelector(`.cat-panel[data-cat="${btn.dataset.cat}"]`);
                initPanel(panel);
                panel.classList.add('is-active');
                panel.querySelectorAll('tr[data-player]').forEach(function (row) {
                    row.dataset.eventId = eventId;
                    refreshPlayerRow(row);
                });
                detail.dispatchEvent(new CustomEvent('catTabChange'));
            });
        });
    }

    /**
     * Build the HTML for a single category panel.
     *
     * Layout: one row per player.
     * The "Line" column is a stepper input seeded to the most common line.
     * Odds columns show each book's over price for the current line.
     * Stepping the input re-renders only that player's odds cells.
     */
    /**
     * Given a roster map and a full player name from The Odds API, return
     * 'home', 'away', or null if the player can't be matched.
     * Uses partial last-name matching to handle minor name differences.
     */
    function classifyPlayer(player, rosters) {
        if (!rosters) return null;
        const norm = (s) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '') // strip diacritics
            .toLowerCase().replace(/[.']/g, '').replace(/\b(jr|sr|ii|iii|iv)\b/g, '').replace(/\s+/g, ' ').trim();
        const lower    = norm(player);
        const lastName = lower.split(' ').slice(-1)[0]; // last word as fallback

        for (const side of ['home', 'away']) {
            const players = rosters[side]?.players ?? [];

            // Pass 1 — full name match (exact, or one contains the other).
            if (players.some(p => {
                const pl = norm(p);
                return pl === lower || pl.includes(lower) || lower.includes(pl);
            })) return side;

            // Pass 2 — last name only, but only if it's unambiguous (exactly one roster
            // player on this side shares the last name).
            const lastNameMatches = players.filter(p => norm(p).split(' ').slice(-1)[0] === lastName);
            if (lastNameMatches.length === 1) return side;
        }
        return null;
    }

    /**
     * Inject team logos next to team section headers.
     * Called after renderCatPanel inserts HTML into the DOM.
     */
    function applyRosterLogos(container, rosters) {
        container.querySelectorAll('.team-section__header[data-side]').forEach(function (header) {
            if (header.querySelector('.team-logo')) return;
            const side = header.dataset.side;
            const logo = rosters[side]?.logo;
            if (!logo) return;
            const img = document.createElement('img');
            img.src       = logo;
            img.alt       = '';
            img.className = 'team-logo team-logo--section';
            header.insertBefore(img, header.firstChild);
        });
    }


    // Combo → component market keys. Used to detect when a breakdown is possible.
    const COMBO_BREAKDOWN_MAP = {
        player_double_double:            ['player_points', 'player_rebounds', 'player_assists'],
        player_triple_double:            ['player_points', 'player_rebounds', 'player_assists'],
        player_points_rebounds_assists:  ['player_points', 'player_rebounds', 'player_assists'],
        player_points_rebounds:          ['player_points', 'player_rebounds'],
        player_points_assists:           ['player_points', 'player_assists'],
        player_rebounds_assists:         ['player_rebounds', 'player_assists'],
        player_blocks_steals:            ['player_blocks',  'player_steals'],
    };

    function renderCatPanel(cat, props, market_labels, effectiveBookKeys, books, default_lines, rosters, eventId) {
        const sections = cat.markets
            .filter(m => props[m] && Object.keys(props[m]).length > 0)
            .map(function (marketKey) {
                const label   = market_labels[marketKey] || marketKey;
                const players = props[marketKey]; // { player: { lineKey: { bookKey: {over,under} } } }

                const activeBks = effectiveBookKeys.filter(bk =>
                    Object.values(players).some(lineMap =>
                        Object.values(lineMap).some(bkData => bkData[bk] && bkData[bk].over !== null)
                    )
                );
                if (activeBks.length === 0) return '';

                // A market is yes/no only when NO player has a numeric line from any active book.
                // Some books (e.g. FanDuel) price anytime-goal markets at 0.5 rather than yn —
                // if any player has a numeric line we render the stepper for those players.
                const isYesNo = !Object.values(players).some(lineMap =>
                    Object.keys(lineMap).some(k =>
                        k !== 'yn' && activeBks.some(bk => lineMap[k]?.[bk]?.over != null)
                    )
                );

                const headerCells = activeBks.map(bk =>
                    `<th class="odds-col">${escHtml(books[bk])}</th>`
                ).join('');

                // Group players by team side when roster data is available.
                const playerEntries = Object.entries(players);
                const grouped = { home: [], away: [], unknown: [] };
                playerEntries.forEach(function ([player, lineMap]) {
                    const side = classifyPlayer(player, rosters) ?? 'unknown';
                    grouped[side].push([player, lineMap]);
                });

                const buildRows = (entries) => entries.map(function ([player, lineMap]) {
                    // lineKeys: lines priced by at least one active book (drives odds cells).
                    const lineKeys = Object.keys(lineMap).filter(k =>
                        k !== 'yn' && activeBks.some(bk => lineMap[k]?.[bk]?.over != null)
                    ).sort((a, b) => parseFloat(a) - parseFloat(b));
                    // allLineKeys: lines priced by any book (drives stepper range).
                    const allLineKeys = Object.keys(lineMap).filter(k =>
                        k !== 'yn' && Object.values(lineMap[k]).some(bkOdds => bkOdds?.over != null)
                    ).sort((a, b) => parseFloat(a) - parseFloat(b));

                    // Treat this player as yes/no if the market is yn-only OR if this
                    // specific player has no numeric lines from any book.
                    const playerIsYesNo = isYesNo || allLineKeys.length === 0;

                    if (playerIsYesNo) {
                        // Yes/No markets: no stepper, just show the single odds
                        const bkData = lineMap['yn'] ?? {};
                        let bestScore = -Infinity;
                        activeBks.forEach(bk => {
                            const v = bkData[bk]?.over ?? null;
                            if (v !== null) bestScore = Math.max(bestScore, oddsScore(v));
                        });

                        const componentKeys = COMBO_BREAKDOWN_MAP[marketKey] ?? [];
                        const isYesNoMarket = ['player_double_double', 'player_triple_double'].includes(marketKey);

                        // For a given book, return how many component markets that book has a
                        // qualifying line for (7.5–11.5 for yes/no, any line otherwise).
                        const bookComponentCount = (bk) => componentKeys.filter(mk => {
                            const plm = props[mk]?.[player] ?? props[mk + '_alternate']?.[player];
                            if (!plm) return false;
                            if (isYesNoMarket) {
                                return Object.keys(plm).some(lineKey => {
                                    const l = parseFloat(lineKey);
                                    return !isNaN(l) && l >= 7.5 && l <= 11.5 && plm[lineKey][bk]?.over != null;
                                });
                            }
                            return Object.values(plm).some(bkMap => bkMap[bk]?.over != null);
                        }).length;

                        // Minimum components needed: 2 for double double, all for others.
                        const minComponents = marketKey === 'player_double_double' ? 2 : componentKeys.length;

                        const cells = activeBks.map(function (bk) {
                            const v = bkData[bk]?.over ?? null;
                            const best = v !== null && oddsScore(v) === bestScore;
                            if (v === null) return `<td class="odds-cell odds-na" data-bk="${escHtml(bk)}">—</td>`;
                            const showBreakdown = componentKeys.length > 0 && bookComponentCount(bk) >= minComponents;
                            const breakdownBtn = showBreakdown
                                ? `<button class="combo-breakdown-btn" data-player="${escHtml(player)}" data-market="${escHtml(marketKey)}" data-bk="${escHtml(bk)}" data-odds="${v}" aria-label="Compare ${escHtml(market_labels[marketKey] || marketKey)} breakdown"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M16 21h5v-5"/><path d="M8 21H3v-5"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg></button>`
                                : '';
                            return `<td class="odds-cell${best ? ' odds-best' : ''}" data-bk="${escHtml(bk)}"><span class="${best ? 'odds-badge--best' : ''}">${fmtOdds(v)}</span>${breakdownBtn}</td>`;
                        }).join('');
                        const wkey1 = `${escHtml(player)}|${escHtml(marketKey)}|${escHtml(eventId)}`;
                        const ynLine = default_lines[marketKey]?.[player] ?? 'yn';
                        return `<tr data-player="${escHtml(player)}" data-market="${escHtml(marketKey)}" data-event-id="${escHtml(eventId)}"><td class="col-player">${escHtml(player)}<span class="hit-rate-chip hit-rate-chip--skeleton" aria-hidden="true"></span></td>${cells}<td class="col-track"><button class="track-bet-btn" data-wkey="${wkey1}" aria-label="Add to watchlist"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></button><button class="alert-btn" data-player="${escHtml(player)}" data-market="${escHtml(marketKey)}" data-event-id="${escHtml(eventId)}" data-line="${escHtml(ynLine)}" aria-label="Set odds alert for ${escHtml(player)}"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></button></td></tr>`;
                    }

                    if (allLineKeys.length === 0) return '';

                    // Build stepper line map from all books so lines only on non-active
                    // books (e.g. 0.5 goals on Fanatics when FanDuel is active) still
                    // appear as navigable stepper positions.
                    const pricedLineMap = Object.fromEntries(
                        Object.entries(lineMap).filter(([k]) =>
                            k !== 'yn' && Object.values(lineMap[k]).some(bkOdds => bkOdds?.over != null)
                        ).sort(([a], [b]) => parseFloat(a) - parseFloat(b))
                    );

                    // Seed to the standard market line tracked in default_lines.
                    // Check against lineMap (all books) not pricedLineMap (active books only)
                    // so a line like 0.5 that only non-active books price still wins as seed.
                    const defaultLine = default_lines[marketKey]?.[player];
                    const seedLine = (defaultLine && defaultLine in lineMap && lineMap[defaultLine] !== undefined)
                        ? defaultLine
                        : lineKeys[0] ?? lineKeys[Math.floor(lineKeys.length / 2)];

                    // Encode all line data into a data attribute.
                    // escHtml (innerHTML) does NOT escape double-quotes, so we replace them
                    // manually. &quot; round-trips correctly through dataset on all browsers.
                    const linesAttr = JSON.stringify(pricedLineMap).replace(/"/g, '&quot;');
                    const booksAttr = JSON.stringify(activeBks).replace(/"/g, '&quot;');

                    const isFree    = statsightAjax.plan === 'free';
                    const seedDisplay = fmtLine(seedLine);
                    const lineCell  = isFree
                        ? `<td class="col-line-stepper" data-lines="${linesAttr}" data-books="${booksAttr}"><span class="line-stepper__val">${escHtml(seedLine)}</span></td>`
                        : `<td class="col-line-stepper">
                            <div class="line-stepper" data-lines="${linesAttr}" data-books="${booksAttr}">
                                <button class="line-stepper__btn line-stepper__btn--down" aria-label="Lower line">−</button>
                                <span class="line-stepper__val">${escHtml(seedDisplay)}</span>
                                <button class="line-stepper__btn line-stepper__btn--up" aria-label="Raise line">+</button>
                            </div>
                           </td>`;

                    return `<tr data-player="${escHtml(player)}" data-market="${escHtml(marketKey)}" data-market-label="${escHtml(label)}" data-event-id="${escHtml(eventId)}">
                        <td class="col-player">${escHtml(player)}<span class="hit-rate-chip hit-rate-chip--skeleton" aria-hidden="true"></span></td>
                        ${lineCell}
                        <td class="col-ev" title="Expected Value vs no-vig line">—</td>
                        ${activeBks.map(bk => `<td class="odds-cell" data-bk="${escHtml(bk)}">—</td>`).join('')}
                        <td class="col-track">
                            <button class="track-bet-btn" data-wkey="${escHtml(player)}|${escHtml(marketKey)}|${escHtml(eventId)}" aria-label="Add to watchlist"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></button>
                            <button class="alert-btn" data-player="${escHtml(player)}" data-market="${escHtml(marketKey)}" data-event-id="${escHtml(eventId)}" data-line="${escHtml(seedLine)}" aria-label="Set odds alert for ${escHtml(player)}"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></button>
                            <button class="compare-btn" data-player="${escHtml(player)}" aria-label="Add ${escHtml(player)} to comparison">&#x21C4;</button>
                        </td>
                    </tr>`;
                }).join('');

                const buildTable = (rows) => `
                    <div class="props-table-wrap">
                        <table class="props-table props-table--stepper">
                            <thead>
                                <tr>
                                    <th>Player</th>
                                    ${isYesNo ? '' : '<th>Line</th><th class="col-ev-header" title="Expected Value vs no-vig line">EV%</th>'}
                                    ${headerCells}
                                    <th class="col-track-header"></th>
                                </tr>
                            </thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>`;

                const hasGroups = rosters && (grouped.home.length > 0 || grouped.away.length > 0);

                let tablesHtml;
                if (hasGroups) {
                    tablesHtml = ['home', 'away'].map(function (side) {
                        if (grouped[side].length === 0) return '';
                        const teamName = rosters[side].name;
                        return `
                            <div class="team-section">
                                <div class="team-section__header team-section__header--clickable" data-side="${escHtml(side)}" role="button" tabindex="0" title="View ${escHtml(teamName)} roster">
                                    <span class="team-section__name">${escHtml(teamName)}</span>
                                </div>
                                ${buildTable(buildRows(grouped[side]))}
                            </div>`;
                    }).join('');
                    // Append any unmatched players in a plain table below
                    if (grouped.unknown.length > 0) {
                        tablesHtml += buildTable(buildRows(grouped.unknown));
                    }
                } else {
                    tablesHtml = buildTable(buildRows(playerEntries));
                }

                return `
                    <div class="market-section">
                        <h3 class="market-section__title">${escHtml(label)}</h3>
                        ${tablesHtml}
                    </div>`;
            }).join('');

        if (!sections) {
            return `<div class="empty-state"><p class="empty-state__title">No lines posted yet.</p></div>`;
        }

        // Wrap in a container so we can attach a single delegated listener
        return `<div class="cat-panel-inner">${sections}</div>`;
    }

    /**
     * Update the odds cells for one player row to reflect the currently
     * selected line. Called on mount and on every stepper change.
     */
    /**
     * Convert American odds to implied probability (0–1).
     */
    function americanToImplied(odds) {
        if (odds >= 0) return 100 / (odds + 100);
        return Math.abs(odds) / (Math.abs(odds) + 100);
    }

    /**
     * Compute no-vig EV% for the best available over odds given all books' over prices.
     * Returns null if fewer than 2 books have data (can't remove vig without both sides or consensus).
     * Formula: (no_vig_prob × best_decimal_payout) - 1, expressed as a percentage.
     */
    function calcEV(bkData, bkList, bestOverOdds) {
        // Remove vig per book individually, then average the resulting true probabilities.
        // This is more accurate than averaging raw implied probs across books separately,
        // since each book's over/under pair is self-contained and has its own vig level.
        const noVigProbs = bkList
            .filter(bk => bkData[bk]?.over != null && bkData[bk]?.under != null)
            .map(bk => {
                const overImpl  = americanToImplied(bkData[bk].over);
                const underImpl = americanToImplied(bkData[bk].under);
                return overImpl / (overImpl + underImpl); // no-vig prob for this book
            });

        // Need at least 2 books with both sides to get a reliable consensus
        if (noVigProbs.length < 2) return null;

        const noVigProb = noVigProbs.reduce((s, p) => s + p, 0) / noVigProbs.length;

        // Best available profit per $1 risked
        const bestDecimal = bestOverOdds >= 0
            ? bestOverOdds / 100
            : 100 / Math.abs(bestOverOdds);

        const ev = (noVigProb * bestDecimal) - (1 - noVigProb);
        return parseFloat((ev * 100).toFixed(1));
    }

    function refreshPlayerRow(row) {
        const stepper    = row.querySelector('.line-stepper');
        const freeTd     = stepper ? null : row.querySelector('td.col-line-stepper[data-lines]');
        const dataSource = stepper ?? freeTd;
        if (!dataSource) return;

        const lineMap  = JSON.parse(dataSource.dataset.lines);  // { lineKey: { bookKey: {over,under} } }
        const bkList   = JSON.parse(dataSource.dataset.books);  // [bookKey, ...]
        const lineKeys = Object.keys(lineMap);
        const lineVal  = dataSource.querySelector('.line-stepper__val').textContent.trim();
        const lineKey  = lineKeys.find(k => fmtLine(k) === lineVal) ?? lineVal;

        // Disable +/- at the boundaries (stepper only)
        if (stepper) {
            const idx = lineKeys.indexOf(lineKey);
            stepper.querySelector('.line-stepper__btn--down').disabled = idx <= 0;
            stepper.querySelector('.line-stepper__btn--up').disabled   = idx >= lineKeys.length - 1;
        }

        const bkData = lineMap[lineKey] ?? {};

        // Find best over for this line
        let bestScore    = -Infinity;
        let bestOverOdds = null;
        bkList.forEach(bk => {
            const v = bkData[bk]?.over ?? null;
            if (v !== null) {
                const s = oddsScore(v);
                if (s > bestScore) { bestScore = s; bestOverOdds = v; }
            }
        });

        // Compute and render EV%
        const evCell = row.querySelector('.col-ev');
        if (evCell && bestOverOdds !== null) {
            const ev = calcEV(bkData, bkList, bestOverOdds);
            if (ev !== null) {
                const pos = ev >= 0;
                evCell.textContent = (pos ? '+' : '') + ev + '%';
                evCell.className   = 'col-ev ' + (pos ? 'col-ev--pos' : 'col-ev--neg');
                evCell.title       = `Expected Value: ${pos ? '+' : ''}${ev}% vs no-vig line`;
            } else {
                evCell.textContent = '—';
                evCell.className   = 'col-ev';
            }
        }

        // Look up history for this row if available
        const eventId   = row.dataset.eventId || null;
        const marketKey = row.dataset.market   || null;
        const player    = row.dataset.player   || null;
        const history   = (eventId && oddsHistoryCache[eventId]) ? oddsHistoryCache[eventId] : null;

        bkList.forEach(function (bk) {
            const cell = row.querySelector(`td[data-bk="${bk}"]`);
            if (!cell) return;
            const v    = bkData[bk]?.over ?? null;
            const best = v !== null && oddsScore(v) === bestScore;

            // Trend arrow from history
            let trendHtml = '';
            if (history && marketKey && player) {
                const snapshots         = history[marketKey]?.[player]?.[lineKey]?.[bk] ?? null;
                const { direction, delta } = calcTrend(snapshots);
                const tooltip           = buildHistoryTooltip(snapshots);
                if (direction === 'up') {
                    const label = `+${delta}`;
                    trendHtml = `<span class="odds-trend odds-trend--up" title="${escHtml(tooltip)}" aria-label="Moving up">&#9650;${escHtml(label)}</span>`;
                } else if (direction === 'down') {
                    const label = String(delta); // already negative
                    trendHtml = `<span class="odds-trend odds-trend--down" title="${escHtml(tooltip)}" aria-label="Moving down">&#9660;${escHtml(label)}</span>`;
                }
            }

            cell.className = 'odds-cell' + (best ? ' odds-best' : '') + (v === null ? ' odds-na' : '');
            cell.innerHTML = v !== null
                ? `<span class="${best ? 'odds-badge--best' : ''}">${fmtOdds(v)}</span>${trendHtml}`
                : '—';
        });

        // Recompute hit rate chip when the line changes
        if (player && marketKey) {
            const playerCell = row.querySelector('.col-player');
            const propLine   = parseFloat(lineVal) || null;
            const sport      = row.closest('[data-sport-key]')?.dataset.sportKey || '';

            if (playerCell && propLine !== null) {
                // Prefer pre-computed game values from prefetch (always available after load)
                const rawValues = playerCell.dataset.gameValues
                    ? JSON.parse(playerCell.dataset.gameValues) : null;

                if (rawValues) {
                    stampHitRateChipFromValues(playerCell, rawValues, propLine);
                } else {
                    // Fall back to full gamelog from modal cache
                    const gamelog = playerGamelogCache[`${player}||${marketKey}`] ?? null;
                    if (gamelog) updateHitRateChip(playerCell, gamelog, marketKey, propLine, sport);
                }
            }
        }
    }

    /**
     * Delegated stepper handler — attached once to the detail body element.
     */
    function handleStepperClick(e) {
        const btn = e.target.closest('.line-stepper__btn');
        if (!btn) return;
        if (statsightAjax.plan === 'free') return;

        const stepper  = btn.closest('.line-stepper');
        const valEl    = stepper.querySelector('.line-stepper__val');
        const lineMap  = JSON.parse(stepper.dataset.lines);
        const lineKeys = Object.keys(lineMap);
        const current  = valEl.textContent.trim();
        const idx      = lineKeys.findIndex(k => fmtLine(k) === current);

        let nextIdx = idx;
        if (btn.classList.contains('line-stepper__btn--up')   && idx < lineKeys.length - 1) nextIdx = idx + 1;
        if (btn.classList.contains('line-stepper__btn--down') && idx > 0)                   nextIdx = idx - 1;

        if (nextIdx === idx) return;
        valEl.textContent = fmtLine(lineKeys[nextIdx]);
        const stepperRow = btn.closest('tr');
        stepperRow.dataset.userAdjusted = '1';
        refreshPlayerRow(stepperRow);
        stampWatchlistButtons(stepperRow);
        const alertBtn = stepperRow.querySelector('.alert-btn');
        if (alertBtn) stampAlertBtn(alertBtn);
        stampConsensusRow(stepperRow, stepperRow.dataset.eventId);
    }

    // ── Props Sort ─────────────────────────────────────────────────────────

    function wirePropsSort(detail) {
        const select = detail.querySelector('.game-detail__sort');
        if (!select) return;

        function applySort(mode) {
            detail.querySelectorAll('.cat-panel.is-active tbody').forEach(function (tbody) {
                const rows = [...tbody.querySelectorAll('tr[data-player]')];
                if (rows.length === 0) return;

                rows.sort(function (a, b) {
                    if (mode === 'default') {
                        return parseInt(a.dataset.sortIdx || 0) - parseInt(b.dataset.sortIdx || 0);
                    }

                    const bestOddsVal = (row) => {
                        let best = -Infinity;
                        row.querySelectorAll('.odds-cell:not(.odds-na)').forEach(cell => {
                            const span = cell.querySelector('span');
                            if (!span) return;
                            const n = parseInt(span.textContent.replace('+', ''), 10);
                            if (!isNaN(n)) best = Math.max(best, oddsScore(n));
                        });
                        return best === -Infinity ? -9999 : best;
                    };

                    const edgeVal = (row) => {
                        let best = -Infinity, worst = Infinity;
                        row.querySelectorAll('.odds-cell:not(.odds-na)').forEach(cell => {
                            const span = cell.querySelector('span');
                            if (!span) return;
                            const n = parseInt(span.textContent.replace('+', ''), 10);
                            if (!isNaN(n)) {
                                const s = oddsScore(n);
                                best  = Math.max(best, s);
                                worst = Math.min(worst, s);
                            }
                        });
                        return (best === -Infinity || worst === Infinity) ? 0 : best - worst;
                    };

                    const evVal = (row) => {
                        const cell = row.querySelector('.col-ev');
                        if (!cell) return -Infinity;
                        return parseFloat(cell.textContent) || -Infinity;
                    };

                    const hitRateVal = (row) => parseFloat(row.dataset.hitRate ?? -Infinity);

                    const va = mode === 'best_odds' ? bestOddsVal(a) : mode === 'ev' ? evVal(a) : mode === 'hit_rate' ? hitRateVal(a) : edgeVal(a);
                    const vb = mode === 'best_odds' ? bestOddsVal(b) : mode === 'ev' ? evVal(b) : mode === 'hit_rate' ? hitRateVal(b) : edgeVal(b);
                    return vb - va;
                });

                rows.forEach(row => tbody.appendChild(row));
            });
        }

        select.addEventListener('change', function () {
            applySort(select.value);
        });

        // Re-apply sort when switching category tabs so the new panel respects current sort.
        detail.addEventListener('catTabChange', function () {
            if (select.value !== 'default') applySort(select.value);
        });

        // Stamp original order so we can restore it
        detail.querySelectorAll('tr[data-player]').forEach(function (row, i) {
            row.dataset.sortIdx = i;
        });
    }

    // ── Game Detail Search ─────────────────────────────────────────────────

    function wireGameDetailSearch(detail) {
        const input = detail.querySelector('.game-detail__search');
        if (!input) return;

        input.addEventListener('input', function () {
            const q = input.value.trim().toLowerCase();

            // Each category panel may have market-section headers (.market-section__header)
            // and player rows (tr[data-player]). Filter within every visible panel.
            detail.querySelectorAll('.cat-panel').forEach(function (panel) {
                panel.querySelectorAll('.market-section').forEach(function (section) {
                    const rows        = section.querySelectorAll('tr[data-player]');
                    let   visibleRows = 0;

                    rows.forEach(function (row) {
                        const name    = (row.dataset.player || '').toLowerCase();
                        const matches = !q || name.includes(q);
                        row.hidden    = !matches;
                        if (matches) visibleRows++;
                    });

                    // Hide the section title when no rows match
                    const header = section.querySelector('.market-section__title');
                    if (header) header.hidden = (visibleRows === 0);
                });

                // Also handle rows not wrapped in a .market-section (flat layout)
                panel.querySelectorAll('tbody > tr[data-player]').forEach(function (row) {
                    const name    = (row.dataset.player || '').toLowerCase();
                    row.hidden    = !(!q || name.includes(q));
                });
            });
        });
    }

    // ── Arb View ───────────────────────────────────────────────────────────

    /**
     * Fetch all props for the sport from the server, then find arbitrage
     * opportunities: best over odds (any book) + best under odds (any other book)
     * on the same player+line with combined implied probability < 100%.
     *
     * arb% = (1 - combinedImplied) * 100
     * Stake split: overStake  = totalStake × (underImpl / combined)
     *              underStake = totalStake × (overImpl  / combined)
     */
    function renderArbView(panel, sportKey) {
        const container = panel.querySelector('.panel-arb-view');
        if (!container) return;

        if (statsightAjax.plan !== 'sharp') {
            container.innerHTML = `
                <div class="arb-upsell">
                    <div class="arb-upsell__icon">&#x26A1;</div>
                    <h3 class="arb-upsell__title">Arbitrage is a Sharp feature</h3>
                    <p class="arb-upsell__desc">Automatically scan every prop across all books in real time and surface guaranteed-profit opportunities — no game needs to be open.</p>
                    <a class="arb-upsell__btn" href="${statsightAjax.homeUrl}">Upgrade to Sharp</a>
                </div>`;
            return;
        }

        container.innerHTML = '<div class="empty-state empty-state--loading"><p class="empty-state__title">Scanning for arbitrage bets&hellip;</p></div>';

        const params = new URLSearchParams({
            action: 'statsight_get_all_props',
            nonce:  statsightAjax.nonce,
            sport:  sportKey,
        });

        fetch(`${statsightAjax.url}?${params}`)
            .then(r => r.json())
            .then(function (json) {
                if (!json.success) throw new Error(json.data?.message || 'Request failed');
                buildArbTable(container, json.data);
            })
            .catch(function (err) {
                container.innerHTML = `<p class="arb-empty__title" style="padding:1rem">Failed to load props: ${escHtml(err.message)}</p>`;
            });
    }

    function buildArbTable(container, allProps) {
        const arbs = [];
        const sportKey = container.closest('.league-panel')?.dataset.sportKey ?? '';

        Object.entries(allProps).forEach(function ([eventId, eventData]) {
            if (isEventEnded(sportKey, eventId)) return;
            const matchup        = eventData.matchup || '';
            const books          = eventData.books   || {};
            const allBkList      = Object.keys(books);
            const activeBooks    = statsightAjax.activeBooks;
            const filteredBkList = activeBooks ? allBkList.filter(bk => activeBooks.includes(bk)) : allBkList;
            const bkList         = filteredBkList.length > 0 ? filteredBkList : allBkList;

            Object.entries(eventData.markets || {}).forEach(function ([marketKey, byPlayer]) {
                const marketLabel = fmtMarket(marketKey);

                Object.entries(byPlayer).forEach(function ([player, byLine]) {
                    Object.entries(byLine).forEach(function ([lineVal, bkData]) {
                        let bestOverOdds  = null, bestOverBk  = null, bestOverScore  = -Infinity;
                        let bestUnderOdds = null, bestUnderBk = null, bestUnderScore = -Infinity;

                        bkList.forEach(function (bk) {
                            const entry = bkData[bk];
                            if (!entry) return;
                            if (entry.over != null) {
                                const s = oddsScore(entry.over);
                                if (s > bestOverScore)  { bestOverScore  = s; bestOverOdds  = entry.over;  bestOverBk  = bk; }
                            }
                            if (entry.under != null) {
                                const s = oddsScore(entry.under);
                                if (s > bestUnderScore) { bestUnderScore = s; bestUnderOdds = entry.under; bestUnderBk = bk; }
                            }
                        });

                        if (bestOverOdds === null || bestUnderOdds === null) return;

                        const overImpl  = americanToImplied(bestOverOdds);
                        const underImpl = americanToImplied(bestUnderOdds);
                        const combined  = overImpl + underImpl;
                        if (combined >= 1) return;

                        // Require each side to be at least 5% implied probability.
                        // This filters out lopsided cases (e.g. +800 over vs -310 under)
                        // where the books are pricing different outcomes, not a true arb.
                        if (overImpl < 0.05 || underImpl < 0.05) return;

                        // Compute true arb % using decimal odds stake sizing.
                        const overDec  = bestOverOdds  >= 0 ? (bestOverOdds  / 100) + 1 : (100 / Math.abs(bestOverOdds))  + 1;
                        const underDec = bestUnderOdds >= 0 ? (bestUnderOdds / 100) + 1 : (100 / Math.abs(bestUnderOdds)) + 1;
                        const denom    = overDec + underDec;
                        const oStake   = underDec / denom; // fraction of total on over
                        const uStake   = overDec  / denom; // fraction of total on under
                        const grossReturn = oStake * overDec; // = uStake * underDec
                        const arbPct   = (grossReturn - 1) * 100; // profit as % of total staked

                        arbs.push({
                            player: player, marketLabel, marketKey, matchup, eventId,
                            line: lineVal,
                            overOdds: bestOverOdds, overBk: bestOverBk,
                            underOdds: bestUnderOdds, underBk: bestUnderBk,
                            overImpl, underImpl, combined,
                            arbPct,
                        });
                    });
                });
            });
        });

        // Update the Arbitrage tab button with a count badge.
        const panel = container.closest('.league-panel');
        const arbBtn = panel?.querySelector('.panel-view-btn[data-view="arb"]');
        if (arbBtn) {
            arbBtn.querySelector('.arb-count-badge')?.remove();
            const badge = document.createElement('span');
            badge.className   = 'arb-count-badge' + (arbs.length === 0 ? ' arb-count-badge--empty' : '');
            badge.textContent = arbs.length;
            arbBtn.appendChild(badge);
        }

        if (arbs.length === 0) {
            container.innerHTML = `
                <div class="arb-empty">
                    <p class="arb-empty__title">No arbitrage opportunities right now</p>
                    <p class="arb-empty__sub">Arbitrage bets appear when the best over odds at one book + the best under odds at another imply less than 100% combined. They close fast — check back often.</p>
                </div>`;
            return;
        }

        // Deduplicate: if two markets have identical player/line/books/odds, keep only the first.
        // This happens when the odds provider returns the same odds under different market keys.
        const arbSeen = new Set();
        const arbsDeduped = arbs.filter(function (a) {
            const key = `${a.eventId}|${a.player}|${a.line}|${a.overBk}|${a.overOdds}|${a.underBk}|${a.underOdds}`;
            if (arbSeen.has(key)) return false;
            arbSeen.add(key);
            return true;
        });
        arbs.length = 0;
        arbsDeduped.forEach(a => arbs.push(a));

        arbs.sort((a, b) => b.arbPct - a.arbPct);

        const rows = arbs.map(function (a) {
            const pct = a.arbPct.toFixed(2);
            return `
                <tr data-player="${escHtml(a.player)}" data-event-id="${escHtml(a.eventId)}" class="arb-row">
                    <td class="arb-col-player">${escHtml(a.player)}</td>
                    <td class="arb-col-market">${escHtml(a.marketLabel)}</td>
                    <td class="arb-col-matchup"><button class="matchup-link">${escHtml(a.matchup)}</button></td>
                    <td class="arb-col-line">${escHtml(a.line)}</td>
                    <td class="arb-col-odds">
                        <span class="arb-side arb-side--over">
                            <span class="arb-side__label">Over</span>
                            <span class="arb-side__odds">${fmtOdds(a.overOdds)}</span>
                            <span class="arb-side__bk">${escHtml(fmtBook(a.overBk))}</span>
                        </span>
                        <span class="arb-side__sep">vs</span>
                        <span class="arb-side arb-side--under">
                            <span class="arb-side__label">Under</span>
                            <span class="arb-side__odds">${fmtOdds(a.underOdds)}</span>
                            <span class="arb-side__bk">${escHtml(fmtBook(a.underBk))}</span>
                        </span>
                    </td>
                    <td class="arb-col-pct">+${pct}%</td>
                    <td class="arb-col-calc">
                        <button class="arb-calc-btn" aria-label="Calculate stakes">Calc</button>
                    </td>
                </tr>
                <tr class="arb-calc-row" hidden>
                    <td colspan="7">
                        <div class="arb-calc-wrap">
                            <label class="arb-calc-label">Total stake
                                <span class="arb-calc-currency">$</span><input
                                    class="arb-stake-input"
                                    type="number" min="1" value="100"
                                    data-over-odds="${a.overOdds}"
                                    data-under-odds="${a.underOdds}"
                                >
                            </label>
                            <div class="arb-calc-result">
                                <span class="arb-calc-over">Bet $<strong class="arb-over-amt">—</strong> Over @ ${fmtOdds(a.overOdds)} on <em>${escHtml(fmtBook(a.overBk))}</em></span>
                                <span class="arb-calc-under">Bet $<strong class="arb-under-amt">—</strong> Under @ ${fmtOdds(a.underOdds)} on <em>${escHtml(fmtBook(a.underBk))}</em></span>
                                <span class="arb-calc-profit">Guaranteed profit: $<strong class="arb-profit-amt">—</strong></span>
                            </div>
                        </div>
                    </td>
                </tr>`;
        }).join('');

        container.innerHTML = `
            <div class="arb-view__table-wrap">
                <table class="props-table arb-view__table">
                    <thead>
                        <tr>
                            <th>Player</th>
                            <th>Market</th>
                            <th>Matchup</th>
                            <th>Line</th>
                            <th>Best Odds</th>
                            <th>Arb %</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;

        container.querySelectorAll('.arb-calc-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const calcRow = btn.closest('tr').nextElementSibling;
                if (!calcRow) return;
                const isOpen  = !calcRow.hidden;
                calcRow.hidden = isOpen;
                btn.textContent = isOpen ? 'Calc' : 'Close';
                if (!isOpen) calcStakes(calcRow.querySelector('.arb-stake-input'));
            });
        });

        container.querySelectorAll('.arb-stake-input').forEach(function (input) {
            input.addEventListener('input', function () { calcStakes(input); });
            calcStakes(input);
        });
    }

    /**
     * Recompute over/under stakes and guaranteed profit from total stake input.
     * Over stake  = totalStake × (underImpl / combined)
     * Under stake = totalStake × (overImpl  / combined)
     * This weights each side proportionally to the other's implied prob.
     */
    function calcStakes(input) {
        const total     = parseFloat(input.value) || 100;
        const overOdds  = parseInt(input.dataset.overOdds,  10);
        const underOdds = parseInt(input.dataset.underOdds, 10);

        // Convert to decimal odds (includes stake return)
        const overDecimal  = overOdds  >= 0 ? (overOdds  / 100) + 1 : (100 / Math.abs(overOdds))  + 1;
        const underDecimal = underOdds >= 0 ? (underOdds / 100) + 1 : (100 / Math.abs(underOdds)) + 1;

        // Correct arb stake sizing: size each leg so both legs return the same gross amount.
        // overStake  = total × underDecimal / (overDecimal + underDecimal)
        // underStake = total × overDecimal  / (overDecimal + underDecimal)
        const denominator = overDecimal + underDecimal;
        const overStake   = total * (underDecimal / denominator);
        const underStake  = total * (overDecimal  / denominator);

        // Both legs should return the same gross amount in a true arb.
        const overReturn  = overStake  * overDecimal;
        const underReturn = underStake * underDecimal;
        // Guaranteed profit = gross return (same either way) minus total staked
        const profit = Math.min(overReturn, underReturn) - total;

        const wrap = input.closest('.arb-calc-wrap');
        if (!wrap) return;
        wrap.querySelector('.arb-over-amt').textContent   = overStake.toFixed(2);
        wrap.querySelector('.arb-under-amt').textContent  = underStake.toFixed(2);
        wrap.querySelector('.arb-profit-amt').textContent = profit.toFixed(2);
    }

    // ── Sharp Moves ────────────────────────────────────────────────────────

    // Market key → human label (reuse SPORT_MARKETS labels where possible)
    // MARKET_LABELS and fmtBook/fmtMarket are defined globally above.

    /**
     * Fetch line movement data for all of today's events and render the Sharp Moves view.
     * No game detail needs to be open — data comes directly from the DB via the server.
     */
    function renderSharpMoves(panel, sportKey) {
        const container = panel.querySelector('.panel-sharp-view');
        if (!container) return;

        container.innerHTML = '<div class="empty-state empty-state--loading"><p class="empty-state__title">Scanning for line moves&hellip;</p></div>';

        const params = new URLSearchParams({
            action: 'statsight_get_line_moves',
            nonce:  statsightAjax.nonce,
            sport:  sportKey,
        });

        fetch(`${statsightAjax.url}?${params}`)
            .then(r => r.json())
            .then(function (json) {
                if (!json.success) throw new Error(json.data?.message || 'Request failed');
                buildSharpTable(container, json.data);
            })
            .catch(function (err) {
                container.innerHTML = `<p class="sharp-empty__title" style="padding:1rem">Failed to load data: ${escHtml(err.message)}</p>`;
            });
    }

    function buildSharpTable(container, moves) {
        const sportKey = container.closest('.league-panel')?.dataset.sportKey ?? '';
        moves = moves.filter(m => !isEventEnded(sportKey, m.event_id));

        if (!moves.length) {
            container.innerHTML = `
                <div class="sharp-empty">
                    <p class="sharp-empty__title">No significant line movement yet</p>
                    <p class="sharp-empty__sub">Line moves appear here once books start adjusting. Check back closer to game time.</p>
                </div>`;
            return;
        }

        const rows = moves.map(function (m) {
            const dir      = m.delta > 0 ? 'up' : 'down';
            const arrow    = m.delta > 0 ? '▲' : '▼';
            const sign     = m.delta > 0 ? '+' : '';
            const deltaStr = `${sign}${parseFloat(m.delta).toFixed(1)}`;
            const label    = fmtMarket(m.market_key);
            return `
                <tr data-player="${escHtml(m.player)}" data-event-id="${escHtml(m.event_id)}">
                    <td class="sharp-col-player">${escHtml(m.player)}</td>
                    <td class="sharp-col-market">${escHtml(label)}</td>
                    <td class="sharp-col-matchup"><button class="matchup-link">${escHtml(m.matchup)}</button></td>
                    <td class="sharp-col-open">${m.open_line}</td>
                    <td class="sharp-col-current">${m.current_line}</td>
                    <td class="sharp-col-move sharp-col-move--${dir}">${arrow} ${deltaStr}</td>
                </tr>`;
        }).join('');

        container.innerHTML = `
            <div class="sharp-view__threshold-wrap">
                <label class="sharp-view__threshold-label">Min move:
                    <span class="bv-number-unit">+/-</span><input type="number" class="sharp-view__threshold bv-number-input" value="0.5" min="0" step="0.5" style="width:6ch">
                </label>
            </div>
            <div class="sharp-view__table-wrap">
                <table class="props-table sharp-view__table">
                    <thead>
                        <tr>
                            <th>Player</th>
                            <th>Market</th>
                            <th>Matchup</th>
                            <th>Open</th>
                            <th>Current</th>
                            <th>Move</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;

        function applyThreshold(min) {
            container.querySelectorAll('tbody tr').forEach(function (row) {
                const moveCell = row.querySelector('.sharp-col-move');
                if (!moveCell) return;
                const num = parseFloat(moveCell.textContent.replace(/[▲▼+\s]/g, ''));
                row.hidden = isNaN(num) || Math.abs(num) < min;
            });
        }

        container.querySelector('.sharp-view__threshold').addEventListener('input', function () {
            const min = parseFloat(this.value);
            if (!isNaN(min)) applyThreshold(min);
        });
    }

    // ── Market View ────────────────────────────────────────────────────────

    // Sport → ordered list of { key, label } for the market selector.
    // Markets that have no meaningful numeric line — always render without a Line column.
    const YES_NO_MARKETS = new Set([
        'h2h',
        'player_double_double',
        'player_triple_double',
        'player_first_basket',
        'player_anytime_td',
        'player_1st_td',
        'player_last_td',
        'player_goal_scorer_anytime',
        'player_goal_scorer_first',
        'player_goal_scorer_last',
        'player_first_goal_scorer',
        'player_last_goal_scorer',
        'player_to_receive_card',
        'player_to_receive_red_card',
    ]);

    const SPORT_MARKETS = {
        basketball: [
            { key: 'player_points',                  label: 'Points' },
            { key: 'player_rebounds',                label: 'Rebounds' },
            { key: 'player_assists',                 label: 'Assists' },
            { key: 'player_threes',                  label: '3-Pointers Made' },
            { key: 'player_blocks',                  label: 'Blocks' },
            { key: 'player_steals',                  label: 'Steals' },
            { key: 'player_blocks_steals',           label: 'Blks + Stls' },
            { key: 'player_turnovers',               label: 'Turnovers' },
            { key: 'player_points_rebounds_assists', label: 'Pts + Reb + Ast' },
            { key: 'player_points_rebounds',         label: 'Pts + Reb' },
            { key: 'player_points_assists',          label: 'Pts + Ast' },
            { key: 'player_rebounds_assists',        label: 'Reb + Ast' },
        ],
        americanfootball: [
            { key: 'player_pass_yds',         label: 'Pass Yards' },
            { key: 'player_pass_tds',         label: 'Pass TDs' },
            { key: 'player_pass_completions', label: 'Completions' },
            { key: 'player_rush_yds',         label: 'Rush Yards' },
            { key: 'player_reception_yds',    label: 'Receiving Yards' },
            { key: 'player_receptions',       label: 'Receptions' },
            { key: 'player_anytime_td',       label: 'Anytime TD' },
        ],
        baseball: [
            { key: 'batter_hits',          label: 'Hits' },
            { key: 'batter_total_bases',   label: 'Total Bases' },
            { key: 'batter_home_runs',     label: 'Home Runs' },
            { key: 'batter_rbis',          label: 'RBIs' },
            { key: 'pitcher_strikeouts',   label: 'Strikeouts' },
        ],
        icehockey: [
            { key: 'player_shots_on_goal', label: 'Shots on Goal' },
            { key: 'player_goals',         label: 'Goals' },
            { key: 'player_assists',       label: 'Assists' },
            { key: 'player_points',        label: 'Points' },
            { key: 'player_total_saves',   label: 'Saves' },
        ],
        soccer: [
            { key: 'player_goal_scorer_anytime', label: 'Anytime Goal Scorer' },
            { key: 'player_first_goal_scorer',   label: 'First Goal Scorer' },
            { key: 'player_last_goal_scorer',    label: 'Last Goal Scorer' },
            { key: 'player_shots_on_target',     label: 'Shots on Target' },
            { key: 'player_shots',               label: 'Shots' },
            { key: 'player_assists',             label: 'Assists' },
            { key: 'player_to_receive_card',     label: 'To Receive Card' },
            { key: 'player_to_receive_red_card', label: 'To Receive Red Card' },
        ],
        mma: [
            { key: 'h2h',    label: 'Moneyline' },
            { key: 'totals', label: 'Round Totals' },
        ],
    };

    function getMarketsForSport(sportKey) {
        for (const prefix of Object.keys(SPORT_MARKETS)) {
            if (sportKey.startsWith(prefix)) return SPORT_MARKETS[prefix];
        }
        return [];
    }

    // Cache market view results: sportKey → marketKey → rows[]
    const marketViewCache = {};

    function populateMarketSelect(panel, sportKey) {
        const select = panel.querySelector('.panel-market-select');
        if (!select || select.dataset.populated) return;
        const markets = getMarketsForSport(sportKey);
        markets.forEach(function ({ key, label }) {
            const opt = document.createElement('option');
            opt.value       = key;
            opt.textContent = label;
            select.appendChild(opt);
        });
        select.dataset.populated = '1';

        // Default to Points if available, otherwise the first market
        const pointsOpt = [...select.options].find(o => o.value === 'player_points');
        if (pointsOpt) {
            select.value = 'player_points';
        } else if (markets.length) {
            select.value = markets[0].key;
        }
    }

    function sortMarketRows(rows, mode) {
        return [...rows].sort(function (a, b) {
            if (mode === 'line_desc') return parseFloat(b.line) - parseFloat(a.line);
            if (mode === 'line_asc')  return parseFloat(a.line) - parseFloat(b.line);
            if (mode === 'ev' || mode === 'best_odds') {
                // We'll sort after render by reading the DOM
                return 0;
            }
            return 0;
        });
    }

    function renderMarketView(panel, sportKey, marketKey, sortMode) {
        const container = panel.querySelector('.panel-market-view');
        if (!container) return;

        container.innerHTML = '<div class="empty-state empty-state--loading"><p class="empty-state__title">Loading&hellip;</p></div>';

        const cacheKey = `${sportKey}||${marketKey}`;

        const doRender = (rows) => {
            if (!rows || rows.length === 0) {
                container.innerHTML = '<div class="empty-state"><p class="empty-state__title">No props available for this market today.</p></div>';
                return;
            }

            // Filter out props from games that have already ended.
            rows = rows.filter(r => !isEventEnded(sportKey, r.event_id));

            if (rows.length === 0) {
                container.innerHTML = '<div class="empty-state"><p class="empty-state__title">No props available for this market today.</p></div>';
                return;
            }

            // Collect books present across all rows, filtered by user preference.
            const bookSet = new Map();
            rows.forEach(r => Object.entries(r.books).forEach(([k, v]) => bookSet.set(k, v)));
            const activeBooks = statsightAjax.activeBooks;
            const allMarketBookKeys = [...bookSet.keys()];
            const filteredMarketBookKeys = activeBooks
                ? allMarketBookKeys.filter(bk => activeBooks.includes(bk))
                : allMarketBookKeys;
            const bookKeys   = filteredMarketBookKeys.length > 0 ? filteredMarketBookKeys : allMarketBookKeys;
            const bookLabels = bookKeys.map(bk => bookSet.get(bk));

            const headerCells = bookLabels.map(b => `<th class="odds-col">${escHtml(b)}</th>`).join('');

            const sorted = sortMarketRows(rows, sortMode);

            // Hide the Line column for markets that have no meaningful numeric threshold.
            const allYN = YES_NO_MARKETS.has(marketKey);

            const tableRows = sorted.map(function (row) {
                const isYN      = row.line === 'yn';
                const lineLabel = isYN ? 'Yes/No' : row.line;
                const linesAttr = JSON.stringify(row.lines).replace(/"/g, '&quot;');
                const booksAttr = JSON.stringify(bookKeys).replace(/"/g, '&quot;');
                const isFree    = statsightAjax.plan === 'free';

                // For yn markets the cell is always hidden visually but must stay in the
                // DOM with data-lines/data-books so refreshPlayerRow can read odds data.
                const hiddenClass = allYN ? ' col-line-stepper--hidden' : '';
                let lineCell;
                if (isYN) {
                    lineCell = `<td class="col-line-stepper${hiddenClass}" data-lines="${linesAttr}" data-books="${booksAttr}"><span class="line-stepper__val">yn</span></td>`;
                } else if (isFree) {
                    lineCell = `<td class="col-line-stepper${hiddenClass}" data-lines="${linesAttr}" data-books="${booksAttr}"><span class="line-stepper__val">${escHtml(lineLabel)}</span></td>`;
                } else {
                    lineCell = `<td class="col-line-stepper${hiddenClass}">
                           <div class="line-stepper" data-lines="${linesAttr}" data-books="${booksAttr}">
                               <button class="line-stepper__btn line-stepper__btn--down" aria-label="Lower line">−</button>
                               <span class="line-stepper__val">${escHtml(lineLabel)}</span>
                               <button class="line-stepper__btn line-stepper__btn--up" aria-label="Raise line">+</button>
                           </div>
                       </td>`;
                }

                const oddsCells = bookKeys.map(bk => `<td class="odds-cell" data-bk="${escHtml(bk)}">—</td>`).join('');

                return `<tr
                    data-player="${escHtml(row.player)}"
                    data-market="${escHtml(row.market_key)}"
                    data-market-label="${escHtml(row.market_label)}"
                    data-event-id="${escHtml(row.event_id)}"
                >
                    <td class="col-player">${escHtml(row.player)}</td>
                    <td class="col-matchup"><button class="matchup-link">${escHtml(row.matchup)}</button></td>
                    ${lineCell}
                    <td class="col-ev" title="Expected Value vs no-vig line">—</td>
                    ${oddsCells}
                    <td class="col-track">
                        <button class="track-bet-btn" data-wkey="${escHtml(row.player)}|${escHtml(row.market_key)}|${escHtml(row.event_id)}" aria-label="Add to watchlist"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></button>
                        <button class="alert-btn" data-player="${escHtml(row.player)}" data-market="${escHtml(row.market_key)}" data-event-id="${escHtml(row.event_id)}" data-line="${escHtml(row.line)}" aria-label="Set odds alert for ${escHtml(row.player)}"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></button>
                        <button class="compare-btn" data-player="${escHtml(row.player)}" aria-label="Add ${escHtml(row.player)} to comparison">&#x21C4;</button>
                    </td>
                </tr>`;
            }).join('');

            container.innerHTML = `
                <div class="props-table-wrap market-view__table-wrap">
                    <table class="props-table props-table--stepper props-table--market-view">
                        <thead>
                            <tr>
                                <th>Player</th>
                                <th>Matchup</th>
                                <th class="${allYN ? 'col-line-stepper--hidden' : ''}">Line</th>
                                <th class="col-ev-header" title="Expected Value vs no-vig line">EV%</th>
                                ${headerCells}
                                <th class="col-track-header"></th>
                            </tr>
                        </thead>
                        <tbody>${tableRows}</tbody>
                    </table>
                </div>`;

            // Seed odds cells and EV for every row
            container.querySelectorAll('tr[data-player]').forEach(refreshPlayerRow);

            // Wire stepper clicks (guard against re-attachment on market switch)
            if (!container.dataset.stepperBound) {
                container.addEventListener('click', handleStepperClick);
                container.dataset.stepperBound = '1';
            }

            // Sort by EV or best_odds after DOM is populated
            if (sortMode === 'ev' || sortMode === 'best_odds') {
                const tbody = container.querySelector('tbody');
                if (tbody) {
                    const domRows = [...tbody.querySelectorAll('tr[data-player]')];
                    domRows.sort(function (a, b) {
                        if (sortMode === 'ev') {
                            return (parseFloat(b.querySelector('.col-ev')?.textContent) || -Infinity)
                                 - (parseFloat(a.querySelector('.col-ev')?.textContent) || -Infinity);
                        }
                        // best_odds: highest oddsScore across any book
                        const bestOdds = (row) => {
                            let best = -Infinity;
                            row.querySelectorAll('.odds-cell:not(.odds-na) span').forEach(s => {
                                const n = parseInt(s.textContent.replace('+',''), 10);
                                if (!isNaN(n)) best = Math.max(best, oddsScore(n));
                            });
                            return best;
                        };
                        return bestOdds(b) - bestOdds(a);
                    });
                    domRows.forEach(r => tbody.appendChild(r));
                }
            }
        };

        if (marketViewCache[cacheKey]) {
            doRender(marketViewCache[cacheKey]);
            return;
        }

        const params = new URLSearchParams({
            action:     'statsight_get_market_props',
            nonce:      statsightAjax.nonce,
            sport:      sportKey,
            market_key: marketKey,
        });

        fetch(statsightAjax.url + '?' + params.toString())
            .then(r => r.json())
            .then(function (json) {
                if (json.success) {
                    marketViewCache[cacheKey] = json.data;
                    doRender(json.data);
                } else {
                    container.innerHTML = `<div class="empty-state"><p class="empty-state__title">Could not load market data.</p></div>`;
                }
            })
            .catch(function () {
                container.innerHTML = `<div class="empty-state"><p class="empty-state__title">Request failed.</p></div>`;
            });
    }

    // Wire view toggle and market/sort selects for each panel
    document.querySelectorAll('.league-panel').forEach(function (panel) {
        const sportKey   = panel.dataset.sportKey || '';
        const eventsDiv  = panel.querySelector('.panel-events');
        const marketDiv  = panel.querySelector('.panel-market-view');
        const sharpDiv   = panel.querySelector('.panel-sharp-view');
        const arbDiv     = panel.querySelector('.panel-arb-view');
        const controls   = panel.querySelector('.panel-market-controls');
        const select     = panel.querySelector('.panel-market-select');
        const sortSelect = panel.querySelector('.panel-market-sort');

        panel.querySelectorAll('.panel-view-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const view = btn.dataset.view;
                panel.querySelectorAll('.panel-view-btn').forEach(b => {
                    b.classList.toggle('is-active', b === btn);
                    b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
                });

                if (view === 'market') {
                    eventsDiv.hidden  = true;
                    marketDiv.hidden  = false;
                    sharpDiv.hidden   = true;
                    arbDiv.hidden     = true;
                    controls.hidden   = false;
                    populateMarketSelect(panel, sportKey);
                    // Auto-load the first market if one isn't already selected
                    if (select.value) {
                        renderMarketView(panel, sportKey, select.value, sortSelect.value);
                    }
                } else if (view === 'sharp') {
                    eventsDiv.hidden  = true;
                    marketDiv.hidden  = true;
                    sharpDiv.hidden   = false;
                    arbDiv.hidden     = true;
                    controls.hidden   = true;
                    renderSharpMoves(panel, sportKey);
                } else if (view === 'arb') {
                    eventsDiv.hidden  = true;
                    marketDiv.hidden  = true;
                    sharpDiv.hidden   = true;
                    arbDiv.hidden     = false;
                    controls.hidden   = true;
                    renderArbView(panel, sportKey);
                } else {
                    eventsDiv.hidden  = false;
                    marketDiv.hidden  = true;
                    sharpDiv.hidden   = true;
                    arbDiv.hidden     = true;
                    controls.hidden   = true;
                }
            });
        });

        if (select) {
            select.addEventListener('change', function () {
                if (select.value) renderMarketView(panel, sportKey, select.value, sortSelect.value);
            });
        }
        if (sortSelect) {
            sortSelect.addEventListener('change', function () {
                if (select && select.value) renderMarketView(panel, sportKey, select.value, sortSelect.value);
            });
        }
    });

    // ── Matchup link — click switches to By Game and opens that game ───────
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.matchup-link');
        if (!btn) return;

        const panel   = btn.closest('.league-panel');
        const row     = btn.closest('tr');
        const eventId = row?.dataset.eventId;
        if (!panel || !eventId) return;

        // Switch panel to By Game view
        const gamesBtn = panel.querySelector('.panel-view-btn[data-view="games"]');
        if (gamesBtn) gamesBtn.click();

        // Find and click the game row with this event_id
        const gameRow = panel.querySelector(`.game-row[data-event-id="${CSS.escape(eventId)}"]`);
        if (gameRow) {
            gameRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            gameRow.click();
        }
    });

    // ── Panel Search ───────────────────────────────────────────────────────

    document.querySelectorAll('.panel-search').forEach(function (input) {
        input.addEventListener('input', function () {
            const q     = input.value.trim().toLowerCase();
            const panel = input.closest('.league-panel');
            const isMarketView = !panel.querySelector('.panel-market-view')?.hidden;
            const isSharpView  = !panel.querySelector('.panel-sharp-view')?.hidden;
            const isArbView    = !panel.querySelector('.panel-arb-view')?.hidden;

            if (isSharpView) {
                panel.querySelectorAll('.panel-sharp-view tr[data-player]').forEach(function (row) {
                    const player = (row.dataset.player || '').toLowerCase();
                    row.hidden   = q !== '' && !player.includes(q);
                });
                return;
            }

            if (isArbView) {
                panel.querySelectorAll('.panel-arb-view tr[data-player]').forEach(function (row) {
                    const player = (row.dataset.player || '').toLowerCase();
                    row.hidden   = q !== '' && !player.includes(q);
                });
                return;
            }

            if (isMarketView) {
                // Filter player rows in the market view table
                const playerRows = panel.querySelectorAll('.panel-market-view tr[data-player]');
                let anyVisible = false;
                playerRows.forEach(function (row) {
                    const player  = (row.dataset.player || '').toLowerCase();
                    const matchup = (row.querySelector('.col-matchup')?.textContent || '').toLowerCase();
                    const show    = q === '' || player.includes(q) || matchup.includes(q);
                    row.hidden = !show;
                    if (show) anyVisible = true;
                });

                const noResults = panel.querySelector('.panel-search-empty');
                if (!anyVisible && q !== '') {
                    if (!noResults) {
                        const msg = document.createElement('p');
                        msg.className   = 'panel-search-empty';
                        msg.textContent = `No players matching "${input.value}"`;
                        panel.querySelector('.panel-market-view').appendChild(msg);
                    } else {
                        noResults.textContent = `No players matching "${input.value}"`;
                        noResults.hidden = false;
                    }
                } else if (noResults) {
                    noResults.hidden = true;
                }
                return;
            }

            // By Game view — filter game rows
            const rows    = panel.querySelectorAll('.game-row');
            const headers = panel.querySelectorAll('.events-date-header');
            const wraps   = panel.querySelectorAll('.props-table-wrap');

            rows.forEach(function (row) {
                const home    = (row.dataset.home    || '').toLowerCase();
                const away    = (row.dataset.away    || '').toLowerCase();
                const players = (row.dataset.players || '').toLowerCase();
                const hasPlayer = players ? players.split('||').some(p => p.includes(q)) : false;
                row.hidden = q !== '' && !home.includes(q) && !away.includes(q) && !hasPlayer;
            });

            // Hide date sections that have no visible rows
            panel.querySelectorAll('.props-table tbody').forEach(function (tbody, i) {
                const hasVisible = [...tbody.querySelectorAll('.game-row')].some(r => !r.hidden);
                if (wraps[i])   wraps[i].hidden   = !hasVisible;
                if (headers[i]) headers[i].hidden = !hasVisible;
            });

            // Show a no-results message if everything is hidden
            const noResults = panel.querySelector('.panel-search-empty');
            const anyVisible = [...rows].some(r => !r.hidden);
            if (!anyVisible && q !== '') {
                if (!noResults) {
                    const msg = document.createElement('p');
                    msg.className   = 'panel-search-empty';
                    msg.textContent = `No games matching "${input.value}"`;
                    panel.querySelector('.panel-events').appendChild(msg);
                } else {
                    noResults.textContent = `No games matching "${input.value}"`;
                    noResults.hidden = false;
                }
            } else if (noResults) {
                noResults.hidden = true;
            }
        });
    });

    // ── League Tab Switching ───────────────────────────────────────────────

    const tabs   = document.querySelectorAll('.league-tab-btn');
    const panels = document.querySelectorAll('.league-panel');

    function activateTab(target) {
        tabs.forEach(t => { t.classList.remove('is-active'); t.setAttribute('aria-selected', 'false'); });
        panels.forEach(p => p.classList.remove('is-active'));

        const btn = document.querySelector(`.league-tab-btn[data-tab="${target}"]`);
        if (btn) {
            btn.classList.add('is-active');
            btn.setAttribute('aria-selected', 'true');
        }

        const panel = document.getElementById('panel-' + target);
        if (panel) panel.classList.add('is-active');

        loadEvents(target);
        try { localStorage.setItem('statsight_active_sport', target); } catch (e) {}
    }

    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            activateTab(btn.dataset.tab);
        });
    });

    const readyEvents = {}; // sportKey → data, populated as responses arrive

    // All tabs start hidden. Fetch all sports in parallel, reveal each tab as
    // games are confirmed, then activate based on priority: saved sport first,
    // otherwise the first tab in DOM order that has games.
    const allFetches = Array.from(tabs).map(function (btn) {
        const sportKey  = btn.dataset.tab;
        const panel     = document.getElementById('panel-' + sportKey);
        const container = panel ? panel.querySelector('.panel-events') : null;
        const params    = new URLSearchParams({
            action: 'statsight_get_events',
            nonce:  statsightAjax.nonce,
            sport:  sportKey,
        });

        return fetch(statsightAjax.url + '?' + params.toString())
            .then(r => r.json())
            .then(function (json) {
                if (!json.success) return;
                const total = (json.data.days || []).reduce((n, d) => n + (d.events?.length ?? 0), 0);
                if (total === 0) return;

                btn.hidden = false;
                if (!btn.querySelector('.tab-game-count')) {
                    const badge = document.createElement('span');
                    badge.className   = 'tab-game-count';
                    badge.textContent = total;
                    btn.appendChild(badge);
                }

                readyEvents[sportKey] = json.data;
                if (container) {
                    container.dataset.prefetched = JSON.stringify(json.data);
                }
            })
            .catch(() => {});
    });

    // Once all responses are in, restore saved tab or fall back to first with games.
    Promise.allSettled(allFetches).then(function () {
        let saved = null;
        try { saved = localStorage.getItem('statsight_active_sport'); } catch (e) {}
        const savedBtn = saved ? document.querySelector(`.league-tab-btn[data-tab="${saved}"]:not([hidden])`) : null;
        const first = document.querySelector('.league-tab-btn:not([hidden])');
        const target = savedBtn || first;
        if (target) activateTab(target.dataset.tab);
    });

    // ── Live scores background poller ────────────────────────────────────
    // Refresh live badges on all loaded event panels every 30 seconds,
    // but only while the page is visible.
    setInterval(function () {
        if (document.hidden) return;

        document.querySelectorAll('.league-panel').forEach(function (panel) {
            const sportKey  = panel.dataset.sportKey;
            const container = panel.querySelector('.panel-events');
            if (!container || container.dataset.loaded !== 'true') return;

            // Only bother if the panel has at least one live or upcoming game row
            const hasRelevantGames = container.querySelector('.game-row--live, .game-row:not(.game-row--ended)');
            if (!hasRelevantGames) return;

            fetch(statsightAjax.url + '?' + new URLSearchParams({
                action: 'statsight_get_live_games',
                nonce:  statsightAjax.nonce,
                sport:  sportKey,
            }).toString())
                .then(r => r.json())
                .then(function (json) {
                    if (!json.success) return;
                    const liveGames = json.data.live ?? [];
                    liveGamesCache[sportKey] = liveGames;
                    applyLiveBadges(container, liveGames);
                    applyTeamLogos(container, json.data.logos ?? {});
                })
                .catch(() => {});
        });
    }, 30000);

    // ── Player Stats Modal ────────────────────────────────────────────────

    const playerModal         = document.getElementById('player-stats-modal');
    const playerModalTitle    = playerModal.querySelector('#player-modal-title');
    const playerModalHeadshot = playerModal.querySelector('.player-modal__headshot');
    const playerModalLoading  = playerModal.querySelector('.player-modal__loading');
    const playerModalContent  = playerModal.querySelector('.player-modal__content');
    const playerModalError    = playerModal.querySelector('.player-modal__error');

    // Track the active Chart.js instance so we can destroy it before re-rendering
    let playerChartInstance = null;

    let playerModalSourceRow = null; // the prop row that triggered the modal

    function openPlayerModal(playerName, sourceRow) {
        playerModalTitle.textContent = playerName;
        playerModalHeadshot.hidden   = true;
        playerModalHeadshot.src      = '';
        playerModalLoading.hidden    = false;
        playerModalContent.hidden    = true;
        playerModalError.hidden      = true;
        playerModalSourceRow         = sourceRow || null;
        // Destroy any existing chart before clearing the DOM
        if (playerChartInstance) {
            playerChartInstance.destroy();
            playerChartInstance = null;
        }
        playerModalContent.innerHTML = '';

        playerModal.hidden = false;
        document.body.classList.add('odds-modal-open');
        playerModal.querySelector('.odds-modal__close').focus();
    }

    function closePlayerModal() {
        playerModal.hidden = true;
        document.body.classList.remove('odds-modal-open');
    }

    playerModal.querySelector('.odds-modal__close').addEventListener('click', closePlayerModal);
    playerModal.querySelector('.odds-modal__backdrop').addEventListener('click', closePlayerModal);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !playerModal.hidden) closePlayerModal();
    });

    // ── Combo Breakdown Modal ─────────────────────────────────────────────
    const comboBreakdownModal     = document.getElementById('combo-breakdown-modal');
    const comboBreakdownTitle     = comboBreakdownModal.querySelector('#combo-breakdown-title');
    const comboBreakdownBody      = comboBreakdownModal.querySelector('.combo-breakdown-modal__body');

    const COMBO_LABEL_MAP = {
        player_double_double:            'Double Double',
        player_triple_double:            'Triple Double',
        player_points_rebounds_assists:  'Pts + Reb + Ast',
        player_points_rebounds:          'Pts + Reb',
        player_points_assists:           'Pts + Ast',
        player_rebounds_assists:         'Reb + Ast',
        player_blocks_steals:            'Blks + Stls',
    };
    const COMPONENT_LABEL_MAP = {
        player_points:   'Points',
        player_rebounds: 'Rebounds',
        player_assists:  'Assists',
        player_blocks:   'Blocks',
        player_steals:   'Steals',
    };

    function openComboBreakdown(btn) {
        const player     = btn.dataset.player;
        const marketKey  = btn.dataset.market;
        const bk         = btn.dataset.bk;
        const comboOdds  = parseInt(btn.dataset.odds, 10);
        const comboLabel = COMBO_LABEL_MAP[marketKey] ?? marketKey;
        const detail     = btn.closest('.game-detail');
        const isYesNo    = ['player_double_double', 'player_triple_double'].includes(marketKey);

        const fmtOdds = (o) => o == null ? '—' : (o >= 0 ? '+' + o : String(o));

        // Resolve a component market for the specific book this button belongs to.
        function resolveComponent(mk) {
            for (const tryMk of [mk, mk + '_alternate']) {
                const compRow = detail?.querySelector(`tr[data-player="${CSS.escape(player)}"][data-market="${CSS.escape(tryMk)}"]`);
                if (!compRow) continue;
                const ds = compRow.querySelector('.line-stepper') ?? compRow.querySelector('td.col-line-stepper[data-lines]');
                if (!ds) continue;
                const lineMap = (() => { try { return JSON.parse(ds.dataset.lines); } catch { return {}; } })();
                let chosenLine;
                if (isYesNo) {
                    const valid = Object.keys(lineMap).map(parseFloat).filter(l => !isNaN(l) && l >= 7.5 && l <= 11.5);
                    if (!valid.length) continue;
                    const bookValid = valid.filter(l => lineMap[String(l)]?.[bk]?.over != null);
                    if (!bookValid.length) continue;
                    chosenLine = bookValid.reduce((a, b) => Math.abs(a - 9.5) <= Math.abs(b - 9.5) ? a : b);
                } else {
                    chosenLine = parseFloat(ds.querySelector('.line-stepper__val')?.textContent.trim());
                }
                const over = lineMap[String(chosenLine)]?.[bk]?.over ?? null;
                if (over == null) continue;

                // Read hit rate from the game_values already stamped on the player cell.
                const playerCell = compRow.querySelector('.col-player');
                const gameValues = (() => { try { return JSON.parse(playerCell?.dataset.gameValues || 'null'); } catch { return null; } })();
                let hitRate = null;
                if (gameValues && gameValues.length > 0 && chosenLine != null) {
                    const hits = gameValues.filter(v => v > chosenLine).length;
                    hitRate = { hits, total: gameValues.length, pct: Math.round((hits / gameValues.length) * 100) };
                }

                return { label: COMPONENT_LABEL_MAP[mk] ?? mk, line: chosenLine, odds: over, hitRate };
            }
            return null;
        }

        const allKeys = COMBO_BREAKDOWN_MAP[marketKey] ?? [];

        // For double double show all 3 pairs; for other combos show each component individually
        let rows;
        if (marketKey === 'player_double_double') {
            rows = [
                [allKeys[0], allKeys[1]],
                [allKeys[0], allKeys[2]],
                [allKeys[1], allKeys[2]],
            ].map(([mkA, mkB]) => {
                const a = resolveComponent(mkA), b = resolveComponent(mkB);
                if (!a || !b) return null;
                return [a, b];
            }).filter(Boolean);
        } else {
            const legs = allKeys.map(resolveComponent).filter(Boolean);
            rows = legs.length ? [legs] : [];
        }

        if (!rows.length) {
            comboBreakdownBody.innerHTML = `<p style="color:var(--color-text-dim);text-align:center;padding:1rem 0">Not enough props available to compare.</p>`;
            comboBreakdownModal.hidden = false;
            document.body.classList.add('odds-modal-open');
            comboBreakdownModal.querySelector('.odds-modal__close').focus();
            return;
        }

        // Flatten all unique components across rows (dedup by label)
        const seen = new Set();
        const components = [];
        for (const legs of rows) {
            for (const l of legs) {
                if (!seen.has(l.label)) {
                    seen.add(l.label);
                    components.push(l);
                }
            }
        }

        const tableRows = components.map(l => {
            const hrCls = l.hitRate ? (l.hitRate.pct >= 60 ? 'hit-rate-chip--high' : l.hitRate.pct <= 40 ? 'hit-rate-chip--low' : 'hit-rate-chip--mid') : '';
            const hrHtml = l.hitRate ? `<span class="hit-rate-chip ${hrCls}" title="${l.hitRate.hits}/${l.hitRate.total} games">${l.hitRate.pct}% HIT</span>` : '';
            return `<tr>
                <td class="cbd-col-legs">${escHtml(l.line)}+ ${escHtml(l.label)} ${hrHtml}</td>
                <td class="cbd-col-odds">${escHtml(fmtOdds(l.odds))}</td>
            </tr>`;
        }).join('');

        comboBreakdownTitle.textContent = `${player} — ${comboLabel}`;
        comboBreakdownBody.innerHTML = `
            <table class="cbd-table">
                <thead><tr>
                    <th class="cbd-col-legs">${escHtml(comboLabel)}</th>
                    <th class="cbd-col-odds">${escHtml(fmtOdds(comboOdds))}</th>
                </tr></thead>
                <tbody>${tableRows}</tbody>
            </table>`;

        comboBreakdownModal.hidden = false;
        document.body.classList.add('odds-modal-open');
        comboBreakdownModal.querySelector('.odds-modal__close').focus();
    }

    function closeComboBreakdown() {
        comboBreakdownModal.hidden = true;
        document.body.classList.remove('odds-modal-open');
    }

    comboBreakdownModal.querySelector('.odds-modal__close').addEventListener('click', closeComboBreakdown);
    comboBreakdownModal.querySelector('.odds-modal__backdrop').addEventListener('click', closeComboBreakdown);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !comboBreakdownModal.hidden) closeComboBreakdown();
    });

    // Delegated click for combo breakdown buttons (they're rendered dynamically)
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.combo-breakdown-btn');
        if (!btn) return;
        e.stopPropagation(); // don't trigger row click / player modal
        openComboBreakdown(btn);
    });

    /**
     * Map a market key to the ESPN stat column(s) that should be summed
     * to produce the comparable value for hit rate.
     * Returns an array of column label strings.
     */
    function hitRateColumns(marketKey, sport) {
        const mk  = (marketKey || '').replace(/_alternate$/, '');
        const isHockey = sport && sport.includes('icehockey');

        // Keys that collide between sports — resolve by sport first.
        if (isHockey) {
            const hockeyMap = {
                'player_goals':              ['G'],
                'player_assists':            ['A'],
                'player_points':             ['PTS'],
                'player_shots_on_goal':      ['S'],
                'player_blocked_shots':      ['BLK'],
                'player_power_play_points':  ['PPG', 'PPA'],
                'player_total_saves':        ['SV'],
            };
            if (hockeyMap[mk]) return hockeyMap[mk];
        }

        const map = {
            // Basketball
            'player_points':                  ['PTS'],
            'player_rebounds':                ['REB'],
            'player_assists':                 ['AST'],
            'player_threes':                  ['3PT'],
            'player_blocks':                  ['BLK'],
            'player_steals':                  ['STL'],
            'player_blocks_steals':           ['BLK', 'STL'],
            'player_turnovers':               ['TO'],
            'player_field_goals':             ['FG'],
            'player_frees_made':              ['FT'],
            'player_points_rebounds_assists': ['PTS', 'REB', 'AST'],
            'player_points_rebounds':         ['PTS', 'REB'],
            'player_points_assists':          ['PTS', 'AST'],
            'player_rebounds_assists':        ['REB', 'AST'],
            // NFL
            'player_pass_yds':               ['YDS'],
            'player_pass_tds':               ['TD'],
            'player_pass_attempts':          ['CMP'],
            'player_pass_completions':       ['CMP'],
            'player_rush_yds':               ['YDS'],
            'player_rush_attempts':          ['CAR'],
            'player_reception_yds':          ['YDS'],
            'player_receptions':             ['REC'],
            // MLB
            'batter_hits':                   ['H'],
            'batter_total_bases':            ['H'],
            'batter_home_runs':              ['HR'],
            'batter_rbis':                   ['RBI'],
            'batter_runs_scored':            ['R'],
            'batter_hits_runs_rbis':         ['H', 'R', 'RBI'],
            'batter_walks':                  ['BB'],
            'batter_strikeouts':             ['SO'],
            'pitcher_strikeouts':            ['SO'],
        };
        return map[mk] ?? null;
    }

    const POSITION_BLURBS = {
        // NBA (generic positions ESPN sometimes returns)
        G:    { label: 'Guard',             blurb: 'Perimeter player responsible for ball-handling and scoring. Guards typically lead in assists and three-pointers and are featured in points, assists, and threes props.' },
        F:    { label: 'Forward',           blurb: 'Versatile scorer who operates both inside and outside. Forwards contribute across multiple stat categories — points, rebounds, and combo props are all in play.' },
        C:    { label: 'Center',            blurb: 'The anchor of the paint. Dominates rebounds and blocks, and scores efficiently inside. Rebound and block props are high-value for bigs.' },
        'G-F': { label: 'Guard-Forward',   blurb: 'Hybrid perimeter player with scoring and playmaking ability. Versatile enough to contribute in points, assists, and rebounding props.' },
        'F-C': { label: 'Forward-Center',  blurb: 'Big man who can step out and score. Rebounds, blocks, and interior points props are the most relevant.' },
        // NHL (keyed separately to avoid collision with NBA G/C)
        GOALIE: { label: 'Goalie',         blurb: 'Last line of defense. Saves props are directly tied to opposing team shot volume.' },
        // NBA (specific positions)
        PG:  { label: 'Point Guard',       blurb: 'The floor general. Controls pace and ball distribution — typically leads the team in assists and drives. Watch for points + assists combos.' },
        SG:  { label: 'Shooting Guard',    blurb: 'Primarily a scorer and perimeter shooter. Usually one of the top scorers on the team with high three-point volume.' },
        SF:  { label: 'Small Forward',     blurb: 'Versatile two-way player. Can score from range or drive the lane and contributes across multiple stat categories.' },
        PF:  { label: 'Power Forward',     blurb: 'Physical presence in the paint. Contributes heavily in rebounds, blocks, and interior scoring. Strong in points + rebounds props.' },
        // NFL
        QB:  { label: 'Quarterback',       blurb: 'Runs the offense. Passing yards, touchdowns, and completion stats are the key props. Usage is high but varies by game script.' },
        RB:  { label: 'Running Back',      blurb: 'Primary ball-carrier. Rushing yards and carries props are common. Receiving involvement can spike in pass-heavy game scripts.' },
        WR:  { label: 'Wide Receiver',     blurb: 'Pass-catcher and downfield threat. Receptions, receiving yards, and touchdown props are typical. Target share drives production.' },
        TE:  { label: 'Tight End',         blurb: 'Hybrid blocker and receiver. Can be a high-value target in the red zone. Receiving props vary widely based on role.' },
        K:   { label: 'Kicker',            blurb: 'Special teams scorer. Field goal and kicking points props depend on offensive drive efficiency and scoring opportunities.' },
        // MLB
        SP:  { label: 'Starting Pitcher',  blurb: 'Controls innings and strikeouts. Strikeout props are closely tied to opposing lineup quality and pitch count limits.' },
        RP:  { label: 'Relief Pitcher',    blurb: 'High-leverage situational pitcher. Strikeout props available but usage is harder to predict than starters.' },
        C_MLB: { label: 'Catcher',         blurb: 'Defensive specialist behind the plate. Batting props tend to be lower-line given typical offensive output.' },
        '1B': { label: 'First Baseman',    blurb: 'Power hitter. Home run and RBI props are common. Total bases props are popular for sluggers.' },
        '2B': { label: 'Second Baseman',   blurb: 'Contact hitter in the middle of the infield. Hits and runs props are typical.' },
        '3B': { label: 'Third Baseman',    blurb: 'Mix of power and contact. RBI and total bases props are often available.' },
        SS:  { label: 'Shortstop',         blurb: 'Athletic middle infielder. Hits and runs props are common — modern shortstops are often key offensive contributors.' },
        LF:  { label: 'Left Field',        blurb: 'Outfield power bat. Home run and total bases props reflect their offensive role.' },
        CF:  { label: 'Center Field',      blurb: 'Speedy leadoff-type outfielder. Runs scored and hits props are typical.' },
        RF:  { label: 'Right Field',       blurb: 'Often a power hitter. RBI and total bases props are common.' },
        DH:  { label: 'Designated Hitter', blurb: 'Pure offensive role. High plate appearances make hits, home run, and RBI props the most relevant.' },
        // NHL
        LW:  { label: 'Left Wing',         blurb: 'Offensive forward on the left side. Goals, assists, and shots props are all in play.' },
        RW:  { label: 'Right Wing',        blurb: 'Offensive forward on the right side. Often a top scorer — goals and points props are key.' },
        D:   { label: 'Defenseman',        blurb: 'Plays at the blue line. Shots on goal props are common. Point production varies greatly by role.' },
    };

    function getPositionBlurb(sport, position) {
        if (!position) return null;
        // MLB catcher — C clashes with the generic NBA Center
        if (sport && sport.includes('baseball') && position === 'C') return POSITION_BLURBS['C_MLB'] ?? null;
        // NHL goalie — ESPN returns 'G' for goalie but that clashes with NBA Guard
        if (sport && sport.includes('hockey') && position === 'G') return POSITION_BLURBS['GOALIE'];
        return POSITION_BLURBS[position] ?? null;
    }

    function renderMmaFighterStats(data) {
        const { bio = {}, fights = [], player } = data;

        // Show headshot if available
        if (bio.headshot) {
            playerModalHeadshot.src    = bio.headshot;
            playerModalHeadshot.alt    = player || '';
            playerModalHeadshot.hidden = false;
        }

        // Bio stats strip — record comes directly from PHP's statsSummary parse
        const record = bio.record || '—';

        const bioItems = [
            { label: 'Record',       value: record },
            { label: 'Weight Class', value: bio.weight_class || '—' },
            { label: 'Height',       value: bio.height || '—' },
            { label: 'Weight',       value: bio.weight || '—' },
            { label: 'Reach',        value: bio.reach  || '—' },
            { label: 'Stance',       value: bio.stance  || '—' },
            { label: 'Style',        value: bio.style   || '—' },
        ].filter(item => item.value && item.value !== '—');

        const bioHtml = bioItems.map(item => `
            <div class="player-modal__avg-item">
                <span class="player-modal__avg-item-label">${escHtml(item.label)}</span>
                <span class="player-modal__avg-item-val">${escHtml(item.value)}</span>
            </div>`).join('');

        // Win method breakdown — KOs and subs come from ESPN statsSummary (career totals).
        // Decisions = total wins minus KO wins and submission wins.
        const koWins  = bio.kos  ?? 0;
        const subWins = bio.subs ?? 0;
        const totalWins = bio.wins ?? 0;
        const decWins = Math.max(0, totalWins - koWins - subWins);

        const methodHtml = totalWins > 0 ? `
            <div class="mma-method-strip">
                ${koWins  > 0 ? `<div class="mma-method-item mma-method--ko"><span class="mma-method-val">${koWins}</span><span class="mma-method-label">KO/TKO</span></div>`  : ''}
                ${subWins > 0 ? `<div class="mma-method-item mma-method--sub"><span class="mma-method-val">${subWins}</span><span class="mma-method-label">Sub</span></div>` : ''}
                ${decWins > 0 ? `<div class="mma-method-item mma-method--dec"><span class="mma-method-val">${decWins}</span><span class="mma-method-label">Dec</span></div>` : ''}
            </div>` : '';

        // Fight history table
        let fightRowsHtml = '';
        if (fights.length > 0) {
            fightRowsHtml = fights.map(f => {
                const resultCls = f.result === 'W' || f.result === 'Win' ? 'mma-result--win'
                                : f.result === 'L' || f.result === 'Loss' ? 'mma-result--loss'
                                : 'mma-result--nc';
                const roundInfo = f.round ? `R${f.round}${f.time ? ' ' + f.time : ''}` : (f.time || '—');
                return `
                    <tr>
                        <td>${escHtml(f.date || '—')}</td>
                        <td class="mma-opponent">${escHtml(f.opponent || '—')}</td>
                        <td><span class="mma-result-badge ${resultCls}">${escHtml(f.result || '—')}</span></td>
                        <td>${escHtml(f.method || '—')}</td>
                        <td>${escHtml(roundInfo)}</td>
                    </tr>`;
            }).join('');
        }

        const fightTableHtml = fights.length > 0 ? `
            <div class="props-table-wrap">
                <table class="props-table player-modal__table mma-fight-history-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Opponent</th>
                            <th>Result</th>
                            <th>Method</th>
                            <th>Round</th>
                        </tr>
                    </thead>
                    <tbody>${fightRowsHtml}</tbody>
                </table>
            </div>` : `<p class="player-modal__empty-msg">No fight history available.</p>`;

        playerModalContent.innerHTML = `
            <div class="player-modal__section">
                <div class="player-modal__avgs-strip player-modal__avgs-strip--bio">
                    <div class="player-modal__avgs-group">
                        <span class="player-modal__avgs-group-label">Fighter Stats</span>
                        <div class="player-modal__avgs-group-items">${bioHtml}</div>
                    </div>
                </div>
                ${methodHtml}
            </div>
            <div class="player-modal__section">
                <h3 class="player-modal__section-title">Fight History</h3>
                ${fightTableHtml}
            </div>`;

        playerModalLoading.hidden = true;
        playerModalContent.hidden = false;
    }

    function renderPlayerStats(data, marketKey, propLine, opponentName, defense) {
        // ── MMA: separate lightweight layout ──────────────────────────────────
        if (data.mma) {
            renderMmaFighterStats(data);
            return;
        }

        const { columns, gamelog, averages, season_averages, games_played, live_game, season_avg_min, headshot, position, sport } = data;

        if (headshot) {
            playerModalHeadshot.src    = headshot;
            playerModalHeadshot.alt    = data.player || '';
            playerModalHeadshot.hidden = false;
        }
        const colEntries = Object.entries(columns); // [[label, header], ...]

        // Helper to render a stats row as <td> cells
        const statCells = (stats) => colEntries
            .map(([label]) => `<td>${escHtml(stats[label] ?? '—')}</td>`)
            .join('');

        // Header row
        const headerCells = colEntries
            .map(([, header]) => `<th>${escHtml(header)}</th>`)
            .join('');

        // Live game row is embedded in the gamelog table below.

        // Recent games log
        let gamelogHtml = '';
        let gamelogChartData = null; // initialised after innerHTML is set
        if (gamelog && gamelog.length > 0) {
            const statCols = hitRateColumns(marketKey, sport);

            // Compute per-game combined stat value for the chart bars
            const chartValues = gamelog.map(function (g) {
                if (!statCols) return null;
                return statCols.reduce(function (sum, col) {
                    const raw = parseFloat(g.stats[col]);
                    return sum + (isNaN(raw) ? 0 : raw);
                }, 0);
            });

            // Hit rate summary badge
            let hitRateHtml = '';
            if (propLine !== null && statCols) {
                const hits = chartValues.filter(v => v !== null && v > propLine).length;
                const pct  = Math.round((hits / gamelog.length) * 100);
                const cls  = pct >= 60 ? 'hit-rate--high' : pct <= 40 ? 'hit-rate--low' : 'hit-rate--mid';
                hitRateHtml = `<span class="hit-rate ${cls}">${hits}/${gamelog.length} over ${propLine} (${pct}%)</span>`;
            }

            // Averages strip (replaces the averages table rows)
            // label = ESPN key (e.g. 'FGM'), header = display name (e.g. 'FG')
            const avgItems = colEntries
                .map(([label, header]) => `
                    <div class="player-modal__avg-item">
                        <span class="player-modal__avg-item-label">${escHtml(header)}</span>
                        <span class="player-modal__avg-item-val">${escHtml(averages[label] ?? '—')}</span>
                    </div>`).join('');

            const seasonLabel = games_played ? `Season (${games_played}g)` : 'Season';
            const seasonAvgItems = colEntries
                .map(([label, header]) => `
                    <div class="player-modal__avg-item">
                        <span class="player-modal__avg-item-label">${escHtml(header)}</span>
                        <span class="player-modal__avg-item-val">${escHtml(season_averages?.[label] ?? '—')}</span>
                    </div>`).join('');

            const showChart = statCols !== null;
            const canvasId  = 'player-gamelog-chart';

            gamelogHtml = `
                <div class="player-modal__section">
                    ${live_game ? `
                    <div class="props-table-wrap player-modal__gamelog-wrap">
                        <table class="props-table player-modal__table player-modal__gamelog-table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    ${headerCells}
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="${live_game.state === 'in' ? 'player-modal__live-row' : 'player-modal__final-row'}">
                                    <td>${live_game.state === 'in' ? '<span class="live-badge">LIVE</span>' : 'Final'}</td>
                                    ${statCells(live_game.stats)}
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    ${live_game.state === 'in' && sport && sport.includes('basketball') && (live_game.min || season_avg_min) ? `
                    <div class="player-modal__minutes-bar">
                        <div class="player-modal__minutes-values">
                            <span class="player-modal__minutes-label">Minutes this game</span>
                            <span class="player-modal__minutes-nums">
                                <strong>${escHtml(live_game.min ?? '—')}</strong>
                                <span class="player-modal__minutes-sep">/</span>
                                <span class="player-modal__minutes-avg" title="Season average">${season_avg_min !== null ? season_avg_min + ' avg' : '—'}</span>
                            </span>
                        </div>
                        <p class="player-modal__minutes-note">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            Player minutes can fluctuate based on injuries to other players, foul trouble, or how the game is unfolding.
                        </p>
                    </div>` : ''}` : ''}
                    <h3 class="player-modal__section-title">Last ${gamelog.length} Games ${hitRateHtml}</h3>
                    ${showChart
                        ? `<div class="player-modal__chart-wrap">
                               <canvas id="${canvasId}" aria-label="Recent game performance chart" role="img"></canvas>
                           </div>`
                        : ''}
                    <div class="player-modal__avgs-strip">
                        <div class="player-modal__avgs-group">
                            <span class="player-modal__avgs-group-label">L${gamelog.length} Avg</span>
                            <div class="player-modal__avgs-group-items">${avgItems}</div>
                        </div>
                        <div class="player-modal__avgs-group">
                            <span class="player-modal__avgs-group-label">${escHtml(seasonLabel)}</span>
                            <div class="player-modal__avgs-group-items">${seasonAvgItems}</div>
                        </div>
                    </div>
                </div>`;

            if (showChart) {
                gamelogChartData = {
                    canvasId,
                    labels:   gamelog.map(g => g.opponent),
                    tooltips: gamelog.map(g => ({ date: g.date, opp: g.opponent, result: g.result })),
                    values:   chartValues,
                    propLine,
                };
            }
        } else {
            gamelogHtml = `<p class="player-modal__empty-msg">No recent game data available.</p>`;
        }

        // Opponent defense ranking block
        let defenseHtml = '';
        if (opponentName && defense) {
            // Fuzzy match opponent name to defense map key
            const normOpp = opponentName.toLowerCase().replace('los angeles','la').replace('new york','ny').replace('golden state','gs');
            const matchKey = Object.keys(defense).find(k => {
                const nk = k.toLowerCase().replace('los angeles','la').replace('new york','ny').replace('golden state','gs');
                return nk === normOpp || nk.includes(normOpp) || normOpp.includes(nk);
            });
            if (matchKey) {
                const { pts_rank, pts_allowed, team_count } = defense[matchKey];
                const rankCls = pts_rank <= Math.ceil(team_count / 3)  ? 'def-rank--good'
                              : pts_rank >= Math.floor(team_count * 2 / 3) ? 'def-rank--bad'
                              : 'def-rank--mid';
                defenseHtml = `
                    <div class="player-modal__section player-modal__defense">
                        <h3 class="player-modal__section-title">Opponent Defense</h3>
                        <div class="def-rank-row">
                            <span class="def-rank-label">${escHtml(matchKey)}</span>
                            <span class="def-rank-badge ${rankCls}" title="${pts_allowed} pts allowed per game">
                                #${pts_rank} of ${team_count} — ${pts_allowed} pts/g allowed
                            </span>
                        </div>
                    </div>`;
            }
        }

        // Position blurb
        let positionHtml = '';
        const posInfo = getPositionBlurb(sport, position);
        if (posInfo) {
            positionHtml = `
                <div class="player-position-blurb">
                    <span class="player-position-blurb__badge">${escHtml(position)}</span>
                    <span class="player-position-blurb__label">${escHtml(posInfo.label)}</span>
                    <p class="player-position-blurb__text">${escHtml(posInfo.blurb)}</p>
                </div>`;
        }

        playerModalContent.innerHTML = positionHtml + defenseHtml + gamelogHtml;
        playerModalLoading.hidden    = true;
        playerModalContent.hidden    = false;

        // Initialise Chart.js bar chart if we have data
        if (gamelogChartData) {
            const canvas = document.getElementById(gamelogChartData.canvasId);
            if (canvas && typeof Chart !== 'undefined') {
                const { labels, tooltips, values, propLine: line } = gamelogChartData;

                // Colour each bar: green = hit (> line), red = miss (≤ line)
                const barColors = values.map(v => v !== null && line !== null && v > line
                    ? 'rgba(72, 187, 120, 0.85)'   // green hit
                    : 'rgba(252, 129, 129, 0.85)'); // red miss

                // CSS variable colours for the chart theme
                const style   = getComputedStyle(document.documentElement);
                const textDim = style.getPropertyValue('--color-text-dim').trim() || '#8a9ab5';
                const gridCol = style.getPropertyValue('--color-border').trim()   || '#2a3347';

                playerChartInstance = new Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [{
                            data:            values,
                            backgroundColor: barColors,
                            borderRadius:    4,
                            borderSkipped:   false,
                        }],
                    },
                    options: {
                        responsive:          true,
                        maintainAspectRatio: false,
                        animation:           false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    title: function (ctx) {
                                        const i = ctx[0].dataIndex;
                                        const t = tooltips[i];
                                        return `${t.opp} — ${t.date}`;
                                    },
                                    label: function (ctx) {
                                        const i   = ctx.dataIndex;
                                        const val = ctx.parsed.y;
                                        const t   = tooltips[i];
                                        const hitStr = line !== null
                                            ? (val > line ? ' ✓ Hit' : ' ✗ Miss')
                                            : '';
                                        return [`Value: ${val}${hitStr}`, `Result: ${t.result}`];
                                    },
                                },
                            },
                        },
                        scales: {
                            x: {
                                ticks: { color: textDim, font: { size: 11 } },
                                grid:  { color: gridCol },
                            },
                            y: {
                                beginAtZero: true,
                                ticks: { color: textDim, font: { size: 11 } },
                                grid:  { color: gridCol },
                            },
                        },
                    },
                    plugins: [{
                        // Inline plugin: draws a dashed horizontal line at the prop line
                        id: 'propLine',
                        afterDraw: function (chart) {
                            if (line === null) return;
                            const { ctx, scales: { y, x } } = chart;
                            const yPx = y.getPixelForValue(line);
                            ctx.save();
                            ctx.beginPath();
                            ctx.moveTo(x.left, yPx);
                            ctx.lineTo(x.right, yPx);
                            ctx.strokeStyle = 'rgba(255, 255, 255, 0.75)';
                            ctx.lineWidth   = 2;
                            ctx.setLineDash([6, 4]);
                            ctx.stroke();
                            // Label on the right
                            ctx.fillStyle  = 'rgba(255, 255, 255, 0.9)';
                            ctx.font       = 'bold 11px sans-serif';
                            ctx.textAlign  = 'right';
                            ctx.fillText(`Line: ${line}`, x.right - 4, yPx - 5);
                            ctx.restore();
                        },
                    }],
                });
            }
        }
    }

    // Delegated click on player name cells
    document.addEventListener('click', function (e) {
        const cell = e.target.closest('.col-player');
        if (!cell) return;

        // Only trigger from props table rows (which have data-player), not event rows
        const row = cell.closest('tr[data-player]');
        if (!row) return;

        const playerName = row.dataset.player  || '';
        const eventId    = row.dataset.eventId || '';
        const marketKey  = row.dataset.market  || '';
        const sport      = row.closest('[data-sport-key]')?.dataset.sportKey || '';
        const propLine   = parseFloat(row.querySelector('.line-stepper__val')?.textContent.trim()) || null;

        // Sports with no ESPN gamelog / player history support — names are not clickable.
        if (sport.startsWith('soccer_')) return;

        // Determine which team is the opponent for this player
        const detail     = row.closest('.game-detail');
        const detHome    = detail?.dataset.home || '';
        const detAway    = detail?.dataset.away || '';
        const defenseRaw = detail?.dataset.defense || '';
        const defense    = defenseRaw ? JSON.parse(defenseRaw) : null;

        // Classify player side to find opponent team name
        const teamSection = row.closest('.team-section');
        const playerSide  = teamSection?.querySelector('.team-section__header')?.dataset.side || null;
        const opponentName = playerSide === 'home' ? detAway
                           : playerSide === 'away' ? detHome
                           : null;

        if (!playerName) return;

        // Build a readable title: "Devin Booker — Points"
        const marketHeader = row.closest('.market-section')
            ?.querySelector('.market-section__title')
            ?.textContent.trim() || '';
        const modalTitle = marketHeader ? `${playerName} — ${marketHeader}` : playerName;

        openPlayerModal(modalTitle, row);

        const params = new URLSearchParams({
            action:      'statsight_get_player_stats',
            nonce:       statsightAjax.nonce,
            sport:       sport,
            player_name: playerName,
            market_key:  marketKey,
            event_id:    eventId,
        });

        fetch(statsightAjax.url + '?' + params.toString())
            .then(r => r.json())
            .then(function (json) {
                if (!playerModal.hidden) { // only render if still open
                    if (json.success) {
                        playerStatsCache[`${playerName}||${marketKey}`] = json.data;
                        renderPlayerStats(json.data, marketKey, propLine, opponentName, defense);
                        // Inject hit-rate chip back into the source row
                        injectHitRateChip(row, json.data, marketKey, propLine);
                    } else {
                        playerModalLoading.hidden = true;
                        playerModalError.innerHTML = json.data?.plan_required
                            ? `<strong>Pro feature</strong> — Player intel &amp; hit rates are available on the Pro plan and above.<br><a href="${escHtml(statsightAjax.homeUrl)}" class="modal-upgrade-btn">View plans &rarr;</a>`
                            : escHtml(json.data?.message || 'Could not load player data.');
                        playerModalError.hidden = false;
                    }
                }
            })
            .catch(function (err) {
                if (!playerModal.hidden) {
                    playerModalLoading.hidden = true;
                    playerModalError.textContent = err.message || 'Request failed.';
                    playerModalError.hidden = false;
                }
            });
    });

    /**
     * Compute and inject a Prop Score block at the top of the modal content.
     * Signals: hit rate (40%), EV% (30%), book edge (30%).
     * Missing signals are noted transparently — the score only uses what's available.
     */
    const TOTAL_SIGNALS = 7;

    function buildPropScoreBlock(signals, totalSignalCount) {
        if (signals.length === 0) return null;

        const totalWeight = signals.reduce((s, sig) => s + sig.weight, 0);
        const score       = signals.reduce((s, sig) => s + (sig.value * (sig.weight / totalWeight)), 0);
        const scoreRounded = Math.round(score * 10) / 10;

        const scoreColor = score >= 5 ? 'var(--color-primary)'
                         : 'var(--color-text-muted)';

        const signalRows = signals.map(sig => {
            const pip  = Math.round(sig.value);
            const pips = Array.from({ length: 10 }, (_, i) =>
                `<span class="prop-score__pip ${i < pip ? 'prop-score__pip--on' : ''}"></span>`
            ).join('');
            return `
                <div class="prop-score__signal">
                    <span class="prop-score__signal-label">${escHtml(sig.label)}</span>
                    <span class="prop-score__signal-display">${escHtml(sig.display)}</span>
                    <div class="prop-score__pips">${pips}</div>
                </div>`;
        }).join('');

        const missingCount = totalSignalCount - signals.length;
        const missingNote  = missingCount > 0
            ? `<p class="prop-score__missing">Score based on ${signals.length} of ${totalSignalCount} signals — ${missingCount} unavailable</p>`
            : '';

        const detailsId = 'prop-score-details-' + Math.random().toString(36).slice(2, 7);

        const block = document.createElement('div');
        block.className = 'prop-score';
        block.innerHTML = `
            <div class="prop-score__header">
                <button class="prop-score__toggle" aria-expanded="false" aria-controls="${detailsId}">
                    <span class="prop-score__label">
                        Prop Score
                        <span class="prop-score__info-btn" role="button" aria-label="About Prop Score">i</span>
                    </span>
                    <span class="prop-score__score-group">
                        <span class="prop-score__value" style="color:${scoreColor}">${scoreRounded}</span>
                        <span class="prop-score__denom">/10</span>
                    </span>
                    <span class="prop-score__chevron" aria-hidden="true">&#x25BE;</span>
                </button>
                <div class="prop-score__info-popover" hidden>
                    <div class="prop-score__info-header">
                        <p class="prop-score__info-title">Signal weights &amp; what they measure</p>
                        <button class="prop-score__info-close" aria-label="Close">&times;</button>
                    </div>
                    <ul class="prop-score__info-list">
                        <li><strong>Hit Rate (30%)</strong> — How often the player has cleared this line historically.</li>
                        <li><strong>Recent Avg (25%)</strong> — Last 10 games average vs. the current line.</li>
                        <li><strong>EV% (20%)</strong> — Market-implied edge based on the book's no-vig price.</li>
                        <li><strong>Season Avg (10%)</strong> — Full-season baseline vs. the line.</li>
                        <li><strong>Defensive Rank (8%)</strong> — Opponent's defensive ranking by points allowed.</li>
                        <li><strong>Rest Days (4%)</strong> — Days since the player's team last played.</li>
                        <li><strong>Game Spread (3%)</strong> — Expected game competitiveness and pace.</li>
                    </ul>
                    <p class="prop-score__info-disclaimer">Prop Score is a data-driven research tool and does not guarantee any outcome. Past performance is not indicative of future results. Always gamble responsibly.</p>
                </div>
            </div>
            <div class="prop-score__details" id="${detailsId}" hidden>
                <div class="prop-score__signals">${signalRows}</div>
                ${missingNote}
            </div>`;

        block.querySelector('.prop-score__toggle').addEventListener('click', function () {
            const details  = block.querySelector('.prop-score__details');
            const expanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', String(!expanded));
            details.hidden = expanded;
            block.querySelector('.prop-score__chevron').style.transform = expanded ? '' : 'rotate(180deg)';
        });

        const infoBtn     = block.querySelector('.prop-score__info-btn');
        const infoPopover = block.querySelector('.prop-score__info-popover');
        const infoClose   = block.querySelector('.prop-score__info-close');

        // Open info popover — stop propagation so the toggle doesn't also fire.
        // Also expand the signal breakdown so there's content beneath the popover.
        infoBtn?.addEventListener('click', function (e) {
            e.stopPropagation();
            infoPopover.hidden = false;
            const toggle  = block.querySelector('.prop-score__toggle');
            const details = block.querySelector('.prop-score__details');
            toggle.setAttribute('aria-expanded', 'true');
            details.hidden = false;
            block.querySelector('.prop-score__chevron').style.transform = 'rotate(180deg)';
        });

        // Close button.
        infoClose?.addEventListener('click', function (e) {
            e.stopPropagation();
            infoPopover.hidden = true;
        });

        // Close when clicking outside the popover.
        document.addEventListener('click', function (e) {
            if (!block.contains(e.target)) infoPopover.hidden = true;
        });

        return block;
    }

    function computeSyncSignals(row, data, marketKey, propLine) {
        const signals  = [];
        const { gamelog, averages, season_averages, sport } = data;
        const statCols = hitRateColumns(marketKey, sport);
        const detail   = row?.closest('.game-detail');

        // ── 1. Hit Rate (0.30) ───────────────────────────────────────────────
        if (propLine !== null && statCols && gamelog && gamelog.length > 0) {
            const vals = gamelog.map(g => statCols.reduce((s, c) => s + (parseFloat(g.stats[c]) || 0), 0));
            const hits = vals.filter(v => v > propLine).length;
            const pct  = Math.round((hits / vals.length) * 100);
            signals.push({ label: 'Hit Rate', weight: 0.30, value: pct / 10, display: `${pct}% (${hits}/${vals.length} games)` });
        }

        // ── 2. Recent Avg (0.25) ─────────────────────────────────────────────
        if (propLine !== null && statCols && averages) {
            const recentAvg = statCols.reduce((s, c) => s + (parseFloat(averages[c]) || 0), 0);
            if (recentAvg > 0) {
                signals.push({
                    label:   'Recent Avg',
                    weight:  0.25,
                    value:   Math.min(10, Math.max(0, 5 + ((recentAvg - propLine) / propLine) * 25)),
                    display: `${recentAvg.toFixed(1)} vs ${propLine} line`,
                });
            }
        }

        // ── 3. EV% (0.20) ───────────────────────────────────────────────────
        if (row) {
            const evCell = row.querySelector('.col-ev');
            const evRaw  = evCell ? parseFloat(evCell.textContent) : NaN;
            if (!isNaN(evRaw)) {
                signals.push({
                    label:   'EV%',
                    weight:  0.20,
                    value:   Math.min(10, Math.max(0, 5 + evRaw / 2)),
                    display: `${evRaw > 0 ? '+' : ''}${evRaw.toFixed(1)}%`,
                });
            }
        }

        // ── 4. Season Avg (0.10) ─────────────────────────────────────────────
        if (propLine !== null && statCols && season_averages) {
            const seasonAvg = statCols.reduce((s, c) => s + (parseFloat(season_averages[c]) || 0), 0);
            if (seasonAvg > 0) {
                signals.push({
                    label:   'Season Avg',
                    weight:  0.10,
                    value:   Math.min(10, Math.max(0, 5 + ((seasonAvg - propLine) / propLine) * 25)),
                    display: `${seasonAvg.toFixed(1)} vs ${propLine} line`,
                });
            }
        }

        // ── 5. Def. Rank (0.08) ──────────────────────────────────────────────
        if (row && detail) {
            const defenseRaw = detail.dataset.defense || '';
            const defense    = defenseRaw ? (() => { try { return JSON.parse(defenseRaw); } catch { return null; } })() : null;
            if (defense) {
                const teamSection  = row.closest('.team-section');
                const playerSide   = teamSection?.querySelector('.team-section__header')?.dataset.side || null;
                const opponentName = playerSide === 'home' ? (detail.dataset.away || '') : playerSide === 'away' ? (detail.dataset.home || '') : null;
                if (opponentName) {
                    const norm     = n => n.toLowerCase().replace('los angeles','la').replace('new york','ny').replace('golden state','gs');
                    const matchKey = Object.keys(defense).find(k => { const nk = norm(k); const no = norm(opponentName); return nk === no || nk.includes(no) || no.includes(nk); });
                    if (matchKey) {
                        const rank  = defense[matchKey]?.pts_rank;
                        const total = defense[matchKey]?.team_count ?? 30;
                        if (rank != null) {
                            // Rank 1 = best defense (hardest for over) → score 0. Last = worst defense → score 10.
                            signals.push({ label: 'Defensive Rank', weight: 0.08, value: Math.min(10, Math.max(0, ((rank - 1) / (total - 1)) * 10)), display: `Opp ranks #${rank} of ${total} in pts allowed` });
                        }
                    }
                }
            }
        }

        // ── 6. Rest Days (0.04) ──────────────────────────────────────────────
        if (row && detail && !sport?.startsWith('soccer_')) {
            const teamSection  = row.closest('.team-section');
            const playerSide   = teamSection?.querySelector('.team-section__header')?.dataset.side || null;
            const restRaw      = playerSide === 'home' ? detail.dataset.restHome : playerSide === 'away' ? detail.dataset.restAway : null;
            if (restRaw) {
                const restData = (() => { try { return JSON.parse(restRaw); } catch { return null; } })();
                if (restData && restData.days_rest !== null) {
                    const days    = restData.days_rest;
                    const value   = days <= 1 ? 2 : days === 2 ? 5 : days === 3 ? 7 : 8;
                    const display = days <= 1 ? 'Back-to-back' : `${days} days rest`;
                    signals.push({ label: 'Rest Days', weight: 0.04, display, value });
                }
            }
        }

        return signals;
    }

    function injectPropScore(row, data, marketKey, propLine) {
        if (!playerModalContent || playerModalContent.hidden) return;
        if (data.mma) return;

        const syncSignals = computeSyncSignals(row, data, marketKey, propLine);
        if (syncSignals.length === 0) return;

        // Render immediately with sync signals (spread slot shown as pending)
        let block = buildPropScoreBlock(syncSignals, TOTAL_SIGNALS);
        if (!block) return;
        playerModalContent.insertBefore(block, playerModalContent.firstChild);

        // ── 8. Game spread async (0.03) ──────────────────────────────────────
        if (!row) return;
        const eventId = row.dataset.eventId;
        const sport   = row.closest('[data-sport-key]')?.dataset.sportKey || '';
        if (!eventId || !sport) return;

        const params = new URLSearchParams({
            action:   'statsight_get_game_spread',
            nonce:    statsightAjax.nonce,
            sport:    sport,
            event_id: eventId,
        });

        fetch(statsightAjax.url + '?' + params.toString())
            .then(r => r.json())
            .then(function (json) {
                if (!json.success || json.data?.spread == null) return;
                if (playerModalContent.hidden) return;

                const spread = Math.abs(parseFloat(json.data.spread));
                if (isNaN(spread)) return;

                // Tight game → high volume → good for overs. Large spread → garbage time risk.
                const value = Math.min(10, Math.max(2, 7 - (spread / 10) * 4));
                const label = json.data.spread_favorite
                    ? `${json.data.spread_favorite} -${spread.toFixed(1)}`
                    : `${spread.toFixed(1)} pt spread`;

                const spreadSignal = { label: 'Game Spread', weight: 0.03, value, display: label };
                const allSignals   = [...syncSignals, spreadSignal];

                const newBlock = buildPropScoreBlock(allSignals, TOTAL_SIGNALS);
                if (newBlock && block.parentNode) {
                    block.parentNode.replaceChild(newBlock, block);
                    block = newBlock;
                }
            })
            .catch(() => {}); // spread is optional — fail silently
    }

    /**
     * After player stats load, compute the hit rate and stamp a chip into
     * the player cell of the source row so it's visible without reopening the modal.
     */
    function injectHitRateChip(row, data, marketKey, propLine) {
        const playerCell = row.querySelector('.col-player');
        if (!playerCell) return;

        const { gamelog, sport } = data;

        // Cache the gamelog so the stepper can trigger recomputes later
        const cacheKey = `${row.dataset.player}||${marketKey}`;
        if (gamelog && gamelog.length > 0) {
            playerGamelogCache[cacheKey] = gamelog;
        }

        updateHitRateChip(playerCell, gamelog, marketKey, propLine, sport);
    }

    function updateHitRateChip(playerCell, gamelog, marketKey, propLine, sport) {
        // Remove existing chip before recomputing
        playerCell.querySelector('.hit-rate-chip')?.remove();

        if (!gamelog || gamelog.length === 0 || propLine === null) return;

        const statCols = hitRateColumns(marketKey, sport);
        if (!statCols) return;

        const hits = gamelog.filter(function (g) {
            const val = statCols.reduce(function (sum, col) {
                const raw = parseFloat(g.stats[col]);
                return sum + (isNaN(raw) ? 0 : raw);
            }, 0);
            return val > propLine;
        }).length;

        const total = gamelog.length;
        const pct   = Math.round((hits / total) * 100);
        const cls   = pct >= 60 ? 'hit-rate-chip--high' : pct <= 40 ? 'hit-rate-chip--low' : 'hit-rate-chip--mid';

        const chip = document.createElement('span');
        chip.className   = `hit-rate-chip ${cls}`;
        chip.title       = `Hit rate: ${hits}/${total} games over ${propLine} (${pct}%)`;
        chip.textContent = `${pct}% HIT`;
        playerCell.appendChild(chip);

        const row = playerCell.closest('tr[data-player]');
        if (row) row.dataset.hitRate = pct;
    }

    /**
     * After props render, fire one batch hit-rate fetch per market.
     * Only players with a warm server-side cache will get chips — others
     * will receive them after the modal is opened, as before.
     */
    function injectAiCard(detail, sportKey, eventId, home, away, propsData, liveGame = null) {
        // Hide AI Analysis when less than 10 minutes remain in the final period.
        if (liveGame && liveGame.state === 'in') {
            const isFinalPeriod = sportKey.includes('basketball')
                ? liveGame.period >= 4
                : liveGame.period >= (sportKey.includes('hockey') ? 3 : 4);
            if (isFinalPeriod && liveGame.clock) {
                const parts       = liveGame.clock.split(':');
                const minsLeft    = parseInt(parts[0], 10) || 0;
                if (minsLeft < 10) return;
            }
        }

        const books         = propsData?.books || {};
        const allAiBookKeys = Object.keys(books);
        const activeBooks   = statsightAjax.activeBooks;
        const filteredAiBookKeys = activeBooks
            ? allAiBookKeys.filter(bk => activeBooks.includes(bk))
            : allAiBookKeys;
        const bookKeys    = filteredAiBookKeys.length > 0 ? filteredAiBookKeys : allAiBookKeys;
        const bookOptions = bookKeys.map(bk =>
            `<option value="${escHtml(bk)}">${escHtml(books[bk] || bk)}</option>`
        ).join('');

        const card = document.createElement('div');
        card.className = 'ai-analysis-card';
        card.innerHTML = `
            <button class="ai-analysis-card__toggle" aria-expanded="false">
                <span class="ai-analysis-card__icon">✦</span>
                <span class="ai-analysis-card__label">AI Prop Analysis</span>
                <span class="ai-analysis-card__badge">Pro</span>
                <span class="ai-analysis-card__chevron">›</span>
            </button>
            <div class="ai-analysis-card__body" hidden>
                ${sportKey !== 'mma_mixed_martial_arts' ? `
                <div class="ai-analysis-card__toolbar">
                    <label class="ai-analysis-card__book-label" for="ai-book-${escHtml(eventId)}">Parlay book</label>
                    <select class="ai-analysis-card__book-select" id="ai-book-${escHtml(eventId)}">
                        <option value="">Best available</option>
                        ${bookOptions}
                    </select>
                    <label class="ai-analysis-card__book-label" for="ai-risk-${escHtml(eventId)}">Risk</label>
                    <select class="ai-analysis-card__risk-select" id="ai-risk-${escHtml(eventId)}">
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                        <option value="high">High</option>
                    </select>
                </div>
                ` : ''}
                <p class="ai-analysis-card__disclaimer">* AI analysis is for informational purposes only and does not constitute betting advice. Past trends don't guarantee future results — bet responsibly.</p>
                <div class="ai-analysis-card__content"></div>
            </div>`;

        const toggle     = card.querySelector('.ai-analysis-card__toggle');
        const body       = card.querySelector('.ai-analysis-card__body');
        const content      = card.querySelector('.ai-analysis-card__content');
        const bookSelect   = card.querySelector('.ai-analysis-card__book-select');
        const riskSelect   = card.querySelector('.ai-analysis-card__risk-select');
        let currentBook    = '';
        let currentRisk    = 'medium';
        let activeEs       = null;

        function runAnalysis() {
            if (activeEs) { activeEs.close(); activeEs = null; }
            content.innerHTML = '<p class="ai-analysis-card__loading">Analyzing props<span class="ai-analysis-card__dots"></span></p>';

            const params = new URLSearchParams({
                action:   'statsight_ai_analysis',
                nonce:    statsightAjax.nonce,
                sport:    sportKey,
                event_id: eventId,
                home,
                away,
                risk:     currentRisk,
            });
            if (currentBook) params.set('book', currentBook);

            const es = new EventSource(statsightAjax.url + '?' + params.toString());
            activeEs = es;
            let text = '';

            es.onmessage = function (e) {
                const msg = JSON.parse(e.data);
                if (msg.type === 'cached') {
                    text = msg.text;
                    renderAiContent(text);
                    activeEs = null;
                    es.close();
                } else if (msg.type === 'delta') {
                    text += msg.text;
                    const proseOnly = text.replace(/\nPICKS_JSON:.*$/s, '');
                    content.innerHTML = formatAiText(proseOnly) + '<span class="ai-analysis-card__cursor">▋</span>';
                } else if (msg.type === 'done') {
                    renderAiContent(text);
                    activeEs = null;
                    es.close();
                }
            };

            es.onerror = function () {
                content.innerHTML = '<p class="ai-analysis-card__error">Analysis unavailable — please try again.</p>';
                activeEs = null;
                es.close();
            };
        }

        // Insert above the category tabs.
        const aiCatTabs = detail.querySelector('.game-detail__cat-tabs');
        if (aiCatTabs) {
            aiCatTabs.parentNode.insertBefore(card, aiCatTabs);
        } else {
            detail.querySelector('.game-detail__body')?.appendChild(card);
        }

        toggle.addEventListener('click', function () {
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!expanded));
            body.hidden = expanded;
            card.classList.toggle('is-open', !expanded);
            if (!expanded && content.innerHTML === '') {
                if (!statsightAjax.userId) {
                    content.innerHTML = `<p class="ai-analysis-card__error">
                        <a href="${escHtml(statsightAjax.loginUrl)}">Log in</a> to access AI Prop Analysis.
                    </p>`;
                } else if (statsightAjax.plan === 'free') {
                    content.innerHTML = `<p class="ai-analysis-card__error">
                        AI Prop Analysis is available on the Pro plan and above.
                        <a href="${escHtml(statsightAjax.homeUrl)}">View plans &rarr;</a>
                    </p>`;
                } else {
                    runAnalysis();
                }
            }
        });

        bookSelect?.addEventListener('change', function () {
            currentBook = bookSelect.value;
            if (toggle.getAttribute('aria-expanded') === 'true') {
                runAnalysis();
            }
        });

        riskSelect?.addEventListener('change', function () {
            currentRisk = riskSelect.value;
            if (toggle.getAttribute('aria-expanded') === 'true') {
                runAnalysis();
            }
        });

        function renderAiContent(fullText) {
            const picksMatch = fullText.match(/\nPICKS_JSON:(\[[\s\S]*?\])\s*$/);
            const prose      = picksMatch ? fullText.slice(0, fullText.indexOf('\nPICKS_JSON:')) : fullText;
            content.innerHTML = formatAiText(prose);

            if (!picksMatch) return;
            let picks;
            try { picks = JSON.parse(picksMatch[1]); } catch { return; }
            if (!Array.isArray(picks) || picks.length === 0) return;

            renderAiPickCards(picks);
        }

        function renderAiPickCards(picks) {
            const picksWrap = document.createElement('div');
            picksWrap.className = 'ai-pick-cards';

            const headingText = currentBook
                ? `Parlay Picks — ${escHtml(books[currentBook] || currentBook)}`
                : 'Top Picks';

            picks.forEach(function (pick) {
                const { player, market_key, line } = pick;
                if (!player || !market_key) return;

                const row = detail.querySelector(`tr[data-player="${CSS.escape(player)}"][data-market="${CSS.escape(market_key)}"]`)
                    || detail.querySelector(`tr[data-player="${CSS.escape(player)}"][data-market="${CSS.escape(market_key + '_alternate')}"]`);

                const MMA_MARKET_LABELS = { h2h: 'Moneyline', totals: 'Round Total' };
                const marketLabel = row?.dataset.marketLabel
                    || MMA_MARKET_LABELS[market_key]
                    || market_key.replace(/_alternate$/, '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

                const lineKey = String(line);
                const mkData  = propsData?.props?.[market_key]?.[player]
                    ?? propsData?.props?.[market_key + '_alternate']?.[player];
                const lineData = mkData?.[lineKey] ?? (mkData ? Object.values(mkData)[0] : null);

                // For MMA h2h, hide the raw "yn" line label; for totals show "X rounds".
                const resolvedLine = lineKey || (mkData ? Object.keys(mkData)[0] : '') || '';
                const displayLine = market_key === 'h2h'
                    ? ''
                    : market_key === 'totals'
                        ? (resolvedLine ? resolvedLine + ' rounds' : '')
                        : resolvedLine;

                // When a book is selected show that book's odds; otherwise show best available
                let displayOdds = '', displayBook = '', bestScore = -Infinity;
                const allBooks  = [];

                if (lineData) {
                    const activeBooks = statsightAjax.activeBooks;
                    Object.entries(lineData).forEach(function ([bk, bkData]) {
                        const num = bkData?.over;
                        if (num == null) return;
                        if (activeBooks && !activeBooks.includes(bk)) return;
                        const score = num >= 0 ? num : 10000 / Math.abs(num);
                        const raw   = num >= 0 ? '+' + num : String(num);
                        allBooks.push({ book: bk, odds: num, score });
                        if (currentBook) {
                            if (bk === currentBook) { displayOdds = raw; displayBook = bk; }
                        } else if (score > bestScore) {
                            bestScore = score; displayOdds = raw; displayBook = bk;
                        }
                    });
                    allBooks.sort((a, b) => b.score - a.score);
                }

                const wkey = `${player}|${market_key}|${eventId}`;
                const isWatching  = Object.keys(watchlistMap).some(k => k.startsWith(wkey + '|'));

                const pickCard = document.createElement('div');
                pickCard.className = 'ai-pick-card';

                const oddsHtml = displayOdds
                    ? `<span class="ai-pick-card__odds">${escHtml(displayOdds)}</span><span class="ai-pick-card__book">${escHtml(books[displayBook] || displayBook)}</span>`
                    : '';

                pickCard.innerHTML = `
                    <div class="ai-pick-card__info">
                        <span class="ai-pick-card__player">${escHtml(player)}</span>
                        <span class="ai-pick-card__market">${escHtml(marketLabel)}</span>
                        ${displayLine ? `<span class="ai-pick-card__line">${escHtml(String(displayLine))}</span>` : ''}
                        ${oddsHtml}
                    </div>
                    <button class="track-bet-btn ai-pick-card__watch${isWatching ? ' track-bet-btn--watching' : ''}"
                            data-ai-pick="1"
                            data-player="${escHtml(player)}"
                            data-market="${escHtml(market_key)}"
                            data-line="${escHtml(String(displayLine))}"
                            data-event-id="${escHtml(eventId)}"
                            data-sport="${escHtml(sportKey)}"
                            data-matchup="${escHtml(away + ' @ ' + home)}"
                            data-best-odds="${escHtml(displayOdds)}"
                            data-best-book="${escHtml(displayBook)}"
                            data-direction="${escHtml(pick.direction || 'over')}"
                            data-all-books="${escHtml(JSON.stringify(allBooks.map(({book,odds}) => ({book,odds}))))}"
                            aria-label="${isWatching ? 'Remove from watchlist' : 'Add to watchlist'}"
                            title="${isWatching ? 'Remove from watchlist' : 'Add to watchlist'}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="${isWatching ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                    </button>`;

                picksWrap.appendChild(pickCard);
            });

            if (picksWrap.children.length > 0) {
                const heading = document.createElement('p');
                heading.className = 'ai-pick-cards__heading';
                heading.textContent = headingText;
                content.appendChild(heading);
                content.appendChild(picksWrap);
            }
        }
    }

    // ── Game Poll ──────────────────────────────────────────────────────────────

    function injectPollCard(detail, sportKey, eventId, propsData) {
        // Build candidate list: numeric over/under markets only — skip yes/no and alternates.
        const candidates = [];
        const { props, market_labels, default_lines } = propsData;

        Object.entries(props).forEach(function ([marketKey, playerMap]) {
            if (YES_NO_MARKETS.has(marketKey) || marketKey.endsWith('_alternate')) return;
            // Skip any market whose only line is 'yn' or '0.5' scorer-style
            const hasNumericLine = Object.values(playerMap).some(lineMap =>
                Object.keys(lineMap).some(k => k !== 'yn' && parseFloat(k) >= 1)
            );
            if (!hasNumericLine) return;
            Object.entries(playerMap).forEach(function ([player, lineMap]) {
                const line = default_lines[marketKey]?.[player];
                if (!line) return;
                const bkData = lineMap[line] ?? {};
                let bestOver = null, bestOverBook = null;
                let bestUnder = null, bestUnderBook = null;
                Object.entries(bkData).forEach(function ([bk, sides]) {
                    if (sides?.over != null && (bestOver === null || sides.over > bestOver)) {
                        bestOver = sides.over; bestOverBook = bk;
                    }
                    if (sides?.under != null && (bestUnder === null || sides.under > bestUnder)) {
                        bestUnder = sides.under; bestUnderBook = bk;
                    }
                });
                if (bestOver !== null || bestUnder !== null) {
                    candidates.push({
                        sport:             sportKey,
                        player,
                        market_key:        marketKey,
                        market_label:      market_labels[marketKey] || marketKey.replace(/_/g, ' '),
                        line,
                        best_over_odds:    bestOver,
                        best_over_book:    bestOverBook ? (BOOK_LABELS[bestOverBook] || bestOverBook) : '',
                        best_under_odds:   bestUnder,
                        best_under_book:   bestUnderBook ? (BOOK_LABELS[bestUnderBook] || bestUnderBook) : '',
                    });
                }
            });
        });

        if (candidates.length === 0) return;

        const catTabs = detail.querySelector('.game-detail__cat-tabs');
        if (!catTabs) return;

        const card = document.createElement('div');
        card.className = 'poll-card';
        card.innerHTML = '<p class="poll-card__loading">Loading poll&hellip;</p>';

        // Insert above the category tabs immediately so the loading state is visible.
        catTabs.parentNode.insertBefore(card, catTabs);

        // Grab commence time from the game row so the server can lock voting at tip-off.
        const gameRow     = document.querySelector(`.game-row[data-event-id="${CSS.escape(eventId)}"]`);
        const commenceIso = gameRow?.dataset.commence || '';

        // Fetch or create poll.
        fetch(statsightAjax.url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    new URLSearchParams({
                action:        'statsight_poll_get',
                nonce:         statsightAjax.nonce,
                event_id:      eventId,
                candidates:    JSON.stringify(candidates),
                commence_time: commenceIso,
            }).toString(),
        })
            .then(r => r.json())
            .then(function (json) {
                if (!json.success) { card.remove(); return; }
                renderPollCard(card, json.data);
            })
            .catch(function () { card.remove(); });
    }

    function renderPollCard(card, data) {
        const { poll_id, player, market_label, line, result, locked, best_over_odds, best_over_book, best_under_odds, best_under_book, tally, user_vote } = data;
        const total    = (tally.over || 0) + (tally.under || 0);
        const overPct  = total > 0 ? Math.round((tally.over  / total) * 100) : 50;
        const underPct = total > 0 ? Math.round((tally.under / total) * 100) : 50;
        const settled  = result !== null;
        const isLocked = locked || settled;

        const resultBadge = settled
            ? `<span class="poll-card__result poll-card__result--${escHtml(result)}">${result === 'over' ? 'Over Hit' : 'Under Hit'}</span>`
            : isLocked ? `<span class="poll-card__result poll-card__result--locked">Voting Closed</span>` : '';

        const showResults = user_vote || isLocked;

        card.innerHTML = `
            <div class="poll-card__header">
                <span class="poll-card__label">Community Poll</span>
                ${resultBadge}
            </div>
            <p class="poll-card__question">
                Will <strong>${escHtml(player)}</strong> go Over or Under
                <strong>${escHtml(line)} ${escHtml(market_label)}</strong>?
            </p>
            <div class="poll-card__odds">
                ${best_under_odds !== null ? `<span>Under <strong>${fmtOdds(best_under_odds)}</strong> <span class="poll-card__book">${escHtml(best_under_book)}</span></span>` : ''}
                ${best_over_odds  !== null ? `<span>Over <strong>${fmtOdds(best_over_odds)}</strong> <span class="poll-card__book">${escHtml(best_over_book)}</span></span>`  : ''}
            </div>
            <div class="poll-card__actions${showResults ? ' poll-card__actions--voted' : ''}">
                <button class="poll-card__btn poll-card__btn--under${user_vote === 'under' ? ' is-chosen' : ''}${result === 'under' ? ' is-winner' : result && result !== 'under' ? ' is-loser' : ''}"
                    data-vote="under" ${isLocked ? 'disabled' : ''}>
                    Under ${escHtml(line)}
                </button>
                <button class="poll-card__btn poll-card__btn--over${user_vote === 'over' ? ' is-chosen' : ''}${result === 'over' ? ' is-winner' : result && result !== 'over' ? ' is-loser' : ''}"
                    data-vote="over" ${isLocked ? 'disabled' : ''}>
                    Over ${escHtml(line)}
                </button>
            </div>
            ${showResults ? `
            <div class="poll-card__bar-wrap">
                <div class="poll-card__bar">
                    <div class="poll-card__bar-fill poll-card__bar-fill--under" style="width:${underPct}%"></div>
                    <div class="poll-card__bar-fill poll-card__bar-fill--over" style="width:${overPct}%"></div>
                </div>
                <div class="poll-card__bar-labels">
                    <span class="poll-card__bar-label poll-card__bar-label--under">
                        <strong>${underPct}%</strong> Under
                    </span>
                    <span class="poll-card__bar-label poll-card__bar-label--over">
                        Over <strong>${overPct}%</strong>
                    </span>
                </div>
            </div>
            <p class="poll-card__count">${total.toLocaleString()} vote${total === 1 ? '' : 's'}</p>
            ` : `<p class="poll-card__hint">${statsightAjax.userId ? 'Cast your vote to see results.' : 'Log in to vote.'}</p>`}`;

        if (!isLocked) {
            card.querySelectorAll('.poll-card__btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!statsightAjax.userId) {
                        window.location.href = statsightAjax.loginUrl;
                        return;
                    }
                    const vote = btn.dataset.vote;
                    card.querySelectorAll('.poll-card__btn').forEach(b => b.disabled = true);

                    fetch(statsightAjax.url, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body:    new URLSearchParams({
                            action:  'statsight_poll_vote',
                            nonce:   statsightAjax.nonce,
                            poll_id: poll_id,
                            vote:    vote,
                        }).toString(),
                    })
                        .then(r => r.json())
                        .then(function (json) {
                            if (json.success) {
                                renderPollCard(card, Object.assign({}, data, {
                                    tally:     json.data.tally,
                                    user_vote: json.data.user_vote,
                                    locked:    data.locked,
                                }));
                            } else {
                                card.querySelectorAll('.poll-card__btn').forEach(b => b.disabled = false);
                            }
                        })
                        .catch(function () {
                            card.querySelectorAll('.poll-card__btn').forEach(b => b.disabled = false);
                        });
                });
            });
        }
    }

    function formatAiText(text) {
        // Convert markdown-style bold and newlines to HTML.
        return text
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/^#{1,6}\s+/gm, '')           // strip markdown headings
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n\n+/g, '</p><p>')
            .replace(/\n/g, '<br>')
            .replace(/^/, '<p>').replace(/$/, '</p>');
    }

    function prefetchHitRates(detail, sportKey, eventId, home, away) {
        // Build player → opponent lookup using roster data
        const rosters   = (() => { try { return JSON.parse(detail.dataset.rosters || 'null'); } catch { return null; } })();
        const sideMap   = {}; // playerNameLower → 'home' | 'away'
        if (rosters) {
            (rosters.home?.players || []).forEach(n => { sideMap[n.toLowerCase()] = 'home'; });
            (rosters.away?.players || []).forEach(n => { sideMap[n.toLowerCase()] = 'away'; });
        }

        // Collect all player rows grouped by market key
        const byMarket = {};
        detail.querySelectorAll('tr[data-player][data-market]').forEach(function (row) {
            const market = (row.dataset.market || '').replace(/_alternate$/, '');
            const player = row.dataset.player || '';
            const line   = parseFloat(row.querySelector('.line-stepper__val')?.textContent.trim()) || null;
            if (!market || !player || line === null) return;
            if (!byMarket[market]) byMarket[market] = [];

            // Determine the opponent team name for this player
            const side     = sideMap[player.toLowerCase()]
                ?? (Object.keys(sideMap).find(k => k.includes(player.toLowerCase()) || player.toLowerCase().includes(k))
                    ? sideMap[Object.keys(sideMap).find(k => k.includes(player.toLowerCase()) || player.toLowerCase().includes(k))]
                    : null);
            const opponent = side === 'home' ? away : side === 'away' ? home : '';

            byMarket[market].push({ player, line, row, opponent });
        });

        // One request per market with all players batched
        Object.entries(byMarket).forEach(function ([marketKey, entries]) {
            // Group by opponent so we batch requests per market+opponent combo
            // (most games have 2 sides, so max 2 requests per market)
            const byOpponent = {};
            entries.forEach(function (entry) {
                const opp = entry.opponent || '';
                if (!byOpponent[opp]) byOpponent[opp] = [];
                byOpponent[opp].push(entry);
            });

            Object.entries(byOpponent).forEach(function ([opponent, oppEntries]) {
                const params = new URLSearchParams({
                    action:     'statsight_get_hit_rates',
                    nonce:      statsightAjax.nonce,
                    sport:      sportKey,
                    event_id:   eventId,
                    market_key: marketKey,
                    opponent:   opponent,
                    players:    JSON.stringify(oppEntries.map(e => ({ player: e.player, line: e.line }))),
                });

                fetch(statsightAjax.url + '?' + params.toString())
                    .then(r => r.json())
                    .then(function (json) {
                        if (!json.success) return;
                        oppEntries.forEach(function (entry) {
                            const rate = json.data[entry.player];
                            if (!rate) return;

                            const playerCell = entry.row.querySelector('.col-player');
                            if (!playerCell) return;

                            if (rate.game_values) {
                                playerCell.dataset.gameValues = JSON.stringify(rate.game_values);
                            }
                            if (rate.opponent_values) {
                                playerCell.dataset.opponentValues  = JSON.stringify(rate.opponent_values);
                                playerCell.dataset.opponentTeam    = opponent;
                            }

                            const currentLine = parseFloat(entry.row.querySelector('.line-stepper__val')?.textContent.trim()) || entry.line;
                            stampHitRateChipFromValues(playerCell, rate.game_values, currentLine);
                        });

                        // Re-trigger sort if hit_rate is the active sort mode.
                        const sortSelect = detail.querySelector('.game-detail__sort');
                        if (sortSelect?.value === 'hit_rate') {
                            sortSelect.dispatchEvent(new Event('change', { bubbles: false }));
                        }
                    })
                    .catch(() => {});
            });
        });
    }

    /**
     * Stamp hit rate chip from pre-fetched game values.
     * game_values — flat array of numeric stat totals (all games)
     * propLine    — current line value
     */
    function stampHitRateChipFromValues(playerCell, gameValues, propLine) {
        if (!gameValues || gameValues.length === 0 || propLine === null) return;

        const total = gameValues.length;
        const hits  = gameValues.filter(v => v > propLine).length;
        const pct   = Math.round((hits / total) * 100);

        // Skip re-render if the chip already shows the same value
        const existing = playerCell.querySelector('.hit-rate-chip');
        if (existing && existing.textContent === `${pct}% HIT`) return;

        existing?.remove();

        const cls  = pct >= 60 ? 'hit-rate-chip--high' : pct <= 40 ? 'hit-rate-chip--low' : 'hit-rate-chip--mid';
        const chip = document.createElement('span');
        chip.className   = `hit-rate-chip ${cls} chip-fade-in`;
        chip.title       = `Hit rate: ${hits}/${total} games over ${propLine} (${pct}%)`;
        chip.textContent = `${pct}% HIT`;
        chip.addEventListener('animationend', () => chip.classList.remove('chip-fade-in'), { once: true });
        playerCell.appendChild(chip);

        const row = playerCell.closest('tr[data-player]');
        if (row) row.dataset.hitRate = pct;
    }

    // ── Roster Modal ───────────────────────────────────────────────────────

    const rosterModal        = document.getElementById('roster-modal');
    const rosterModalTitle   = rosterModal.querySelector('#roster-modal-title');
    const rosterModalLogo    = rosterModal.querySelector('.roster-modal__logo');
    const rosterModalContent = rosterModal.querySelector('.roster-modal__content');

    function openRosterModal(team) {
        const { name: teamName, logo, players, injuries } = team;
        rosterModalTitle.textContent = teamName;

        if (logo) {
            rosterModalLogo.src    = logo;
            rosterModalLogo.alt    = teamName;
            rosterModalLogo.hidden = false;
        } else {
            rosterModalLogo.hidden = true;
            rosterModalLogo.src    = '';
        }

        const injuryKeys  = Object.keys(injuries  || {});
        const headshots   = team.headshots || {};

        // Helper: build a small headshot <img> or empty string
        const headshotImg = (name) => {
            const url = headshots[name];
            return url
                ? `<img class="roster-player__photo" src="${escHtml(url)}" alt="${escHtml(name)}" loading="lazy">`
                : `<span class="roster-player__photo roster-player__photo--placeholder"></span>`;
        };

        // Helper: injury badge html or empty string
        const injuryBadge = (status) => {
            if (!status) return '';
            const lower = status.toLowerCase();
            const cls   = lower === 'out' ? 'injury-tag--out'
                        : lower.includes('day') ? 'injury-tag--dtd'
                        : 'injury-tag--questionable';
            const abbr  = lower === 'out' ? 'OUT'
                        : lower.includes('day') ? 'DTD'
                        : 'Q';
            return `<span class="injury-tag ${cls}" title="${escHtml(status)}">${abbr}</span>`;
        };

        // Injury report section
        let injuryHtml = '';
        if (injuryKeys.length > 0) {
            const rows = injuryKeys.map(function (name) {
                const status = injuries[name];
                return `
                    <tr class="roster-player-row">
                        <td class="roster-player__photo-cell">${headshotImg(name)}</td>
                        <td class="roster-table__name">${escHtml(name)}</td>
                        <td class="roster-table__status">${escHtml(status)}</td>
                    </tr>`;
            }).join('');

            injuryHtml = `
                <div class="roster-section">
                    <h3 class="roster-section__title">Injury Report</h3>
                    <div class="props-table-wrap">
                        <table class="props-table roster-table">
                            <thead><tr><th></th><th>Player</th><th>Status</th></tr></thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>
                </div>`;
        }

        // Full roster section — mark injured players, sort by avg minutes desc
        const injuryLower = {};
        injuryKeys.forEach(k => { injuryLower[k.toLowerCase()] = injuries[k]; });

        function buildRosterRows(minutesMap) {
            const sorted = (players || []).slice().sort(function (a, b) {
                return (minutesMap[b] ?? -1) - (minutesMap[a] ?? -1);
            });
            return sorted.map(function (name) {
                const status = injuryLower[name.toLowerCase()] || null;
                const mins   = minutesMap[name] != null ? `<span class="roster-player__mins">${minutesMap[name]} min</span>` : '';
                return `
                    <tr class="roster-player-row">
                        <td class="roster-player__photo-cell">${headshotImg(name)}</td>
                        <td class="roster-table__name">${escHtml(name)}${mins}${injuryBadge(status)}</td>
                    </tr>`;
            }).join('');
        }

        const minutesMap = team.minutes || {};
        const rosterRows = `
            <h3 class="roster-section__title">Roster <span class="roster-section__count">(${(players || []).length})</span></h3>
            <div class="props-table-wrap">
                <table class="props-table roster-table">
                    <tbody>${buildRosterRows(minutesMap)}</tbody>
                </table>
            </div>`;

        rosterModalContent.innerHTML = (injuryHtml || '') + `<div class="roster-section">${rosterRows}</div>`;
        rosterModal.hidden = false;
        document.body.classList.add('odds-modal-open');
        rosterModal.querySelector('.odds-modal__close').focus();
    }

    function closeRosterModal() {
        rosterModal.hidden = true;
        document.body.classList.remove('odds-modal-open');
    }

    rosterModal.querySelector('.odds-modal__close').addEventListener('click', closeRosterModal);
    rosterModal.querySelector('.odds-modal__backdrop').addEventListener('click', closeRosterModal);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !rosterModal.hidden) closeRosterModal();
    });

    // Delegated click/keyboard on team section headers
    document.addEventListener('click', function (e) {
        const header = e.target.closest('.team-section__header--clickable');
        if (!header) return;

        const detail  = header.closest('.game-detail');
        if (!detail) return;

        const rostersRaw = detail.dataset.rosters || '';
        if (!rostersRaw) return;

        const rosters = JSON.parse(rostersRaw);
        const side    = header.dataset.side; // 'home' or 'away'
        const team    = rosters[side];
        if (!team) return;

        openRosterModal(team);
    });

    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('team-section__header--clickable')) {
            e.preventDefault();
            e.target.click();
        }
    });

    // ── Odds History Modal ─────────────────────────────────────────────────

    let oddsChartInstance = null;

    const oddsModal          = document.getElementById('odds-history-modal');
    const oddsModalTitle     = document.getElementById('odds-modal-title');
    const oddsModalEmpty     = oddsModal.querySelector('.odds-modal__empty');
    const oddsPropScoreEl    = document.getElementById('odds-prop-score');

    // Context for the currently open odds modal — used by prop score and line updates.
    let oddsPropScoreCtx = null;

    const oddsChartCanvas = document.getElementById('odds-history-chart');

    const oddsEvBar = document.getElementById('odds-ev-bar');

    function openOddsModal(title, snapshots, currentOdds, marketKey = '', lineVal = '') {
        oddsModalTitle.textContent = title;

        // ── Payout calculator ─────────────────────────────────────────────────
        if (currentOdds !== null && !isNaN(currentOdds)) {
            const calcPayout = (stake) => {
                const s = parseFloat(stake) || 0;
                const win = currentOdds >= 0
                    ? s * currentOdds / 100
                    : s * 100 / Math.abs(currentOdds);
                return { win: win.toFixed(2), total: (s + win).toFixed(2) };
            };

            const { win, total } = calcPayout(100);
            oddsEvBar.hidden = false;
            oddsEvBar.innerHTML = `
                <div class="odds-ev-bar__item">
                    <span class="odds-ev-bar__label">Stake</span>
                    <div class="odds-ev-bar__stake-wrap">
                        <span class="odds-ev-bar__stake-prefix">$</span>
                        <div class="num-stepper">
                            <button class="num-stepper__btn" type="button" data-step="-10" aria-label="Decrease stake">&#x2212;</button>
                            <input class="odds-ev-bar__stake-input" id="odds-payout-stake" type="number" min="1" value="100">
                            <button class="num-stepper__btn" type="button" data-step="10" aria-label="Increase stake">&#x2B;</button>
                        </div>
                    </div>
                </div>
                <div class="odds-ev-bar__item">
                    <span class="odds-ev-bar__label">You win</span>
                    <span class="odds-ev-bar__val odds-ev-bar__val--win" id="odds-payout-win">$${win}</span>
                </div>
                <div class="odds-ev-bar__item">
                    <span class="odds-ev-bar__label">Total payout</span>
                    <span class="odds-ev-bar__val" id="odds-payout-total">$${total}</span>
                </div>`;

            // Wire up the stake input to recalculate live
            const stakeInput = oddsEvBar.querySelector('#odds-payout-stake');
            stakeInput.addEventListener('input', function () {
                const { win: w, total: t } = calcPayout(this.value);
                document.getElementById('odds-payout-win').textContent   = '$' + w;
                document.getElementById('odds-payout-total').textContent = '$' + t;
            });

            // Custom stepper buttons for stake
            oddsEvBar.querySelectorAll('.num-stepper__btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const step = parseFloat(this.dataset.step);
                    const val  = Math.max(1, (parseFloat(stakeInput.value) || 0) + step);
                    stakeInput.value = val;
                    stakeInput.dispatchEvent(new Event('input'));
                });
            });
        } else {
            oddsEvBar.hidden = true;
        }

        // Hide toggle immediately — re-shown below only if data warrants it
        document.getElementById('odds-chart-toggle').hidden = true;

        // Destroy previous chart instance if one exists
        if (oddsChartInstance) {
            oddsChartInstance.destroy();
            oddsChartInstance = null;
        }

        const deduped = snapshots || [];

        oddsModalEmpty.classList.remove('odds-modal__empty--loading');
        if (deduped.length < 2) {
            oddsChartCanvas.hidden = true;
            oddsModalEmpty.textContent = snapshots === null ? '' : 'Not enough history to display a chart.';
            oddsModalEmpty.hidden  = snapshots === null;
        } else {
            oddsChartCanvas.hidden = false;
            oddsModalEmpty.hidden  = true;

            // Build labels — if all timestamps are identical fall back to sequential index
            const timestamps = deduped.map(s => s.recorded_at);
            const allSame    = timestamps.every(t => t === timestamps[0]);

            const labels     = deduped.map(function (s, i) {
                if (allSame) return `#${i + 1}`;
                const d = new Date(s.recorded_at.replace(' ', 'T') + 'Z');
                return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            });
            const overPoints  = deduped.map(s => s.over);
            const underPoints = deduped.map(s => s.under ?? null);
            const hasUnder    = underPoints.some(v => v !== null);
            const statPoints  = deduped.map(s => s.stat_value ?? null);
            const hasStats    = statPoints.some(v => v !== null);

            const statAbbr = {
                player_points:                   'pts',
                player_rebounds:                 'reb',
                player_assists:                  'ast',
                player_turnovers:                'to',
                player_steals:                   'stl',
                player_blocks:                   'blk',
                player_threes:                   '3pm',
                player_points_rebounds:          'p+r',
                player_points_assists:           'p+a',
                player_rebounds_assists:         'r+a',
                player_points_rebounds_assists:  'p+r+a',
            };
            const statLabel = statAbbr[marketKey] ?? 'stat';

            // Over/Under toggle — only show for spread/total markets, not yes/no markets.
            const chartToggle = document.getElementById('odds-chart-toggle');
            const isYesNoMarket = YES_NO_MARKETS.has(marketKey) || lineVal === 'yn' || !marketKey;
            chartToggle.hidden = !hasUnder || isYesNoMarket;

            // Reset toggle to Over state each time the modal opens.
            chartToggle.querySelectorAll('.odds-chart-toggle__btn').forEach(function (btn) {
                btn.classList.toggle('odds-chart-toggle__btn--active', btn.dataset.side === 'over');
            });

            let activeSide = 'over';

            function buildMainDataset(side) {
                const isOver = side === 'over';
                return {
                    label:                isOver ? 'Over' : 'Under',
                    data:                 isOver ? overPoints : underPoints,
                    borderColor:          isOver ? '#2e7cf6' : '#f97316',
                    backgroundColor:      isOver ? 'rgba(46, 124, 246, 0.12)' : 'rgba(249, 115, 22, 0.12)',
                    pointBackgroundColor: isOver ? '#2e7cf6' : '#f97316',
                    pointRadius:          4,
                    pointHoverRadius:     6,
                    tension:              0.3,
                    fill:                 true,
                    spanGaps:             true,
                    yAxisID:              'y',
                };
            }

            const datasets = [buildMainDataset('over')];

            if (hasStats) {
                datasets.push({
                    label:                `Live ${statLabel}`,
                    data:                 statPoints,
                    borderColor:          '#6b7280',
                    backgroundColor:      'transparent',
                    pointBackgroundColor: '#6b7280',
                    pointBorderColor:     '#6b7280',
                    pointBorderWidth:     2,
                    pointRadius:          4,
                    pointHoverRadius:     6,
                    tension:              0.3,
                    fill:                 false,
                    borderDash:           [5, 4],
                    spanGaps:             true,
                    yAxisID:              'yStat',
                });
            }

            const scales = {
                x: {
                    ticks: { color: 'rgba(240,242,247,0.55)', maxRotation: 45 },
                    grid:  { color: 'rgba(255,255,255,0.06)' },
                },
                y: {
                    ticks: {
                        color:    'rgba(240,242,247,0.55)',
                        stepSize: 5,
                        callback: v => fmtOdds(v),
                    },
                    grid: { color: 'rgba(255,255,255,0.06)' },
                },
            };

            if (hasStats) {
                scales.yStat = {
                    position: 'right',
                    ticks: {
                        color:    '#6b7280',
                        callback: v => Number.isInteger(v) ? v : null,
                        stepSize: 1,
                    },
                    grid: { drawOnChartArea: false },
                };
            }

            oddsChartInstance = new Chart(oddsChartCanvas, {
                type: 'line',
                data: { labels, datasets },
                options: {
                    responsive:          true,
                    maintainAspectRatio: true,
                    animation:           false,
                    plugins: {
                        legend: {
                            display: hasStats,
                            onClick: function (e, legendItem, legend) {
                                const chart = legend.chart;
                                const index = legendItem.datasetIndex;
                                // Default toggle behaviour
                                if (chart.isDatasetVisible(index)) {
                                    chart.hide(index);
                                    legendItem.hidden = true;
                                } else {
                                    chart.show(index);
                                    legendItem.hidden = false;
                                }
                                // Show/hide the yStat axis based on whether the stat dataset is visible
                                const statDatasetIndex = chart.data.datasets.findIndex(d => d.yAxisID === 'yStat');
                                if (statDatasetIndex !== -1) {
                                    const statVisible = chart.isDatasetVisible(statDatasetIndex);
                                    chart.options.scales.yStat.display = statVisible;
                                    chart.update();
                                }
                            },
                        },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    if (ctx.dataset.yAxisID === 'yStat') {
                                        return `${ctx.parsed.y} ${statLabel}`;
                                    }
                                    return `${ctx.dataset.label}: ${fmtOdds(ctx.parsed.y)}`;
                                },
                            },
                        },
                    },
                    scales,
                },
            });

            // Wire up the toggle buttons to swap the main dataset in place.
            chartToggle.querySelectorAll('.odds-chart-toggle__btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const side = this.dataset.side;
                    if (side === activeSide) return;
                    activeSide = side;

                    chartToggle.querySelectorAll('.odds-chart-toggle__btn').forEach(function (b) {
                        b.classList.toggle('odds-chart-toggle__btn--active', b.dataset.side === side);
                    });

                    const updated = buildMainDataset(side);
                    oddsChartInstance.data.datasets[0].label                = updated.label;
                    oddsChartInstance.data.datasets[0].data                 = updated.data;
                    oddsChartInstance.data.datasets[0].borderColor          = updated.borderColor;
                    oddsChartInstance.data.datasets[0].backgroundColor      = updated.backgroundColor;
                    oddsChartInstance.data.datasets[0].pointBackgroundColor = updated.pointBackgroundColor;
                    oddsChartInstance.update();
                });
            });
        }

        oddsModal.hidden = false;
        document.body.classList.add('odds-modal-open');
        oddsModal.querySelector('.odds-modal__close').focus();
    }

    function closeOddsModal() {
        oddsModal.hidden = true;
        document.body.classList.remove('odds-modal-open');
        oddsPropScoreCtx = null;
    }

    oddsModal.querySelector('.odds-modal__close').addEventListener('click', closeOddsModal);
    oddsModal.querySelector('.odds-modal__backdrop').addEventListener('click', closeOddsModal);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !oddsModal.hidden) closeOddsModal();
    });

    // ── Watchlist ──────────────────────────────────────────────────────────

    // Map of "player|marketKey|eventId" → watchlist row id, populated on load.
    const watchlistMap = {};

    function watchlistKey(player, marketKey, eventId, line) {
        return `${player}|${marketKey}|${eventId}|${line}`;
    }

    // Returns the key for any saved line of this prop (for mismatch detection).
    function watchlistKeysForProp(player, marketKey, eventId) {
        const prefix = `${player}|${marketKey}|${eventId}|`;
        return Object.keys(watchlistMap).filter(k => k.startsWith(prefix));
    }

    // state: 'off' | 'mismatch' | 'on'
    function setWatchBtnState(btn, state, animate) {
        btn.classList.toggle('track-bet-btn--watching',  state === 'on');
        btn.classList.toggle('track-bet-btn--mismatch',  state === 'mismatch');
        btn.title = state === 'off' ? 'Add to watchlist' : 'Remove from watchlist';
        if (state === 'on') {
            btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`;
        } else if (state === 'mismatch') {
            btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`;
        } else {
            btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`;
        }
        if (state === 'on' && animate) {
            btn.classList.remove('track-bet-btn--pop');
            void btn.offsetWidth;
            btn.classList.add('track-bet-btn--pop');
            btn.addEventListener('animationend', () => btn.classList.remove('track-bet-btn--pop'), { once: true });
        }
    }

    // Stamp all watchlist buttons in a container (or the whole doc) based on current watchlistMap.
    function stampWatchlistButtons(root) {
        (root || document).querySelectorAll('.track-bet-btn[data-wkey]').forEach(function (btn) {
            const [player, marketKey, eventId] = btn.dataset.wkey.split('|');
            const row         = btn.closest('tr');
            const currentLine = row?.querySelector('.line-stepper__val')?.textContent.trim() ?? '';
            const exactKey    = watchlistKey(player, marketKey, eventId, currentLine);
            const savedKeys   = watchlistKeysForProp(player, marketKey, eventId);

            let state;
            if (watchlistMap[exactKey] != null) {
                state = 'on';
            } else if (savedKeys.length > 0) {
                state = 'mismatch';
            } else {
                state = 'off';
            }

            const alreadyOn       = btn.classList.contains('track-bet-btn--watching');
            const alreadyMismatch = btn.classList.contains('track-bet-btn--mismatch');
            if (
                (state === 'on'       && !alreadyOn) ||
                (state === 'mismatch' && !alreadyMismatch) ||
                (state === 'off'      && (alreadyOn || alreadyMismatch))
            ) {
                setWatchBtnState(btn, state, false);
            }
        });
    }

    // Load existing watchlist for this user and stamp button states.
    if (statsightAjax.plan === 'sharp') {
        fetch(statsightAjax.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'statsight_watchlist_get', nonce: statsightAjax.nonce }).toString(),
        })
        .then(r => r.json())
        .then(function (json) {
            if (!json.success) return;
            json.data.props.forEach(function (prop) {
                watchlistMap[watchlistKey(prop.player, prop.market_key, prop.event_id, prop.line)] = { id: prop.id, line: prop.line };
            });
            stampWatchlistButtons();
        });
    }

    // Delegated click handler for AI pick card watchlist buttons.
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.track-bet-btn[data-ai-pick="1"]');
        if (!btn) return;

        e.stopImmediatePropagation();

        if (statsightAjax.plan !== 'sharp') {
            window.location.href = statsightAjax.homeUrl;
            return;
        }

        const player      = btn.dataset.player      || '';
        const marketKey   = btn.dataset.market      || '';
        const lineVal     = btn.dataset.line        || '';
        const eventId     = btn.dataset.eventId     || '';
        const sport       = btn.dataset.sport       || '';
        const matchup     = btn.dataset.matchup     || '';
        const bestOdds    = parseInt((btn.dataset.bestOdds || '0').replace('+', ''), 10) || 0;
        const bestBook    = btn.dataset.bestBook    || '';
        const direction   = btn.dataset.direction   || 'over';
        const FRIENDLY_MARKET_LABELS = { h2h: 'Moneyline', totals: 'Round Total' };
        const marketLabel = btn.closest('tr')?.dataset.marketLabel
            || FRIENDLY_MARKET_LABELS[marketKey]
            || marketKey.replace(/_alternate$/, '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

        const key           = watchlistKey(player, marketKey, eventId, lineVal);
        const existingEntry = watchlistMap[key];

        const SVG_FILLED  = `<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`;
        const SVG_OUTLINE = `<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`;

        function updateBtn(watching) {
            btn.title = watching ? 'Remove from watchlist' : 'Add to watchlist';
            btn.setAttribute('aria-label', watching ? 'Remove from watchlist' : 'Add to watchlist');
            btn.classList.toggle('track-bet-btn--watching', watching);
            btn.innerHTML = watching ? SVG_FILLED : SVG_OUTLINE;
            if (watching) {
                btn.classList.remove('track-bet-btn--pop');
                void btn.offsetWidth;
                btn.classList.add('track-bet-btn--pop');
                btn.addEventListener('animationend', () => btn.classList.remove('track-bet-btn--pop'), { once: true });
            }
        }

        if (existingEntry != null) {
            delete watchlistMap[key];
            updateBtn(false);
            fetch(statsightAjax.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'statsight_watchlist_remove', nonce: statsightAjax.nonce, id: existingEntry.id }).toString(),
            });
        } else {
            watchlistMap[key] = { id: -1, line: lineVal };
            updateBtn(true);

            fetch(statsightAjax.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action:       'statsight_watchlist_add',
                    nonce:        statsightAjax.nonce,
                    event_id:     eventId,
                    sport:        sport,
                    player:       player,
                    market_key:   marketKey,
                    market_label: marketLabel,
                    line:         lineVal,
                    direction:    direction,
                    odds:         bestOdds,
                    book:         bestBook,
                    matchup:      matchup,
                    all_books:    btn.dataset.allBooks || '[]',
                }).toString(),
            })
            .then(r => r.json())
            .then(function (json) {
                if (json.success) {
                    watchlistMap[key] = { id: json.data.id, line: lineVal };
                    // Also stamp the matching prop table row if visible
                    stampWatchlistButtons();
                } else {
                    delete watchlistMap[key];
                    updateBtn(false);
                }
            })
            .catch(function () {
                delete watchlistMap[key];
                updateBtn(false);
            });
        }
    });

    // Delegated click handler for watchlist buttons (prop table rows).
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.track-bet-btn');
        if (!btn) return;
        if (btn.dataset.aiPick) return; // handled above

        if (statsightAjax.plan !== 'sharp') {
            window.location.href = statsightAjax.homeUrl;
            return;
        }

        const row       = btn.closest('tr[data-player]');
        if (!row) return;

        const player      = row.dataset.player    || '';
        const marketKey   = row.dataset.market     || '';
        const marketLabel = row.dataset.marketLabel || marketKey.replace(/_/g, ' ');
        const eventId     = row.dataset.eventId    || '';
        const lineVal     = row.querySelector('.line-stepper__val')?.textContent.trim() || '';
        const detail      = row.closest('.game-detail');
        const sport       = detail?.dataset.sportKey || row.closest('[data-sport-key]')?.dataset.sportKey || '';
        const matchup     = detail ? `${detail.dataset.home || ''} vs ${detail.dataset.away || ''}`.trim() : '';

        const key           = watchlistKey(player, marketKey, eventId, lineVal);
        const existingEntry = watchlistMap[key];

        // Collect all books with valid odds, sorted best to worst.
        const allBooks = [];
        let bestScore = -Infinity, bestOdds = 0, bestBook = '';
        row.querySelectorAll('.odds-cell:not(.odds-na)').forEach(function (cell) {
            const badge = cell.querySelector('span');
            if (!badge) return;
            const num = parseInt(badge.textContent.trim().replace('+', ''), 10);
            if (!isNaN(num)) {
                const score = num >= 0 ? num : 10000 / Math.abs(num);
                allBooks.push({ book: cell.dataset.bk || '', odds: num, score });
                if (score > bestScore) {
                    bestScore = score;
                    bestOdds  = num;
                    bestBook  = cell.dataset.bk || '';
                }
            }
        });
        allBooks.sort((a, b) => b.score - a.score);
        const allBooksPayload = allBooks.map(({ book, odds }) => ({ book, odds }));

        if (existingEntry != null) {
            // This exact line is already saved — remove it.
            delete watchlistMap[key];
            // Re-stamp to show mismatch if another line for this prop is still saved.
            stampWatchlistButtons(row);
            nudgeConsensus(eventId, player, marketKey, lineVal, -1);
            stampConsensusRow(row, eventId);
            fetch(statsightAjax.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'statsight_watchlist_remove', nonce: statsightAjax.nonce, id: existingEntry.id }).toString(),
            });
        } else {
            // Not saved at this line — add it as a new entry.
            watchlistMap[key] = { id: -1, line: lineVal }; // placeholder
            setWatchBtnState(btn, 'on', true);
            nudgeConsensus(eventId, player, marketKey, lineVal, +1);
            stampConsensusRow(row, eventId);

            fetch(statsightAjax.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action:       'statsight_watchlist_add',
                    nonce:        statsightAjax.nonce,
                    event_id:     eventId,
                    sport:        sport,
                    player:       player,
                    market_key:   marketKey,
                    market_label: marketLabel,
                    line:         lineVal,
                    direction:    'over',
                    odds:         bestOdds,
                    book:         bestBook,
                    matchup:      matchup,
                    all_books:    JSON.stringify(allBooksPayload),
                }).toString(),
            })
            .then(r => r.json())
            .then(function (json) {
                if (json.success) {
                    watchlistMap[key] = { id: json.data.id, line: lineVal };
                } else {
                    delete watchlistMap[key];
                    nudgeConsensus(eventId, player, marketKey, lineVal, -1);
                    stampWatchlistButtons(row);
                    stampConsensusRow(row, eventId);
                }
            })
            .catch(function () {
                delete watchlistMap[key];
                nudgeConsensus(eventId, player, marketKey, lineVal, -1);
                setWatchBtnState(btn, 'off', false);
                stampConsensusRow(row, eventId);
            });
        }
    });

    // Delegated click on odds cells
    document.addEventListener('click', function (e) {
        // Don't trigger history modal when the breakdown button inside the cell was clicked
        if (e.target.closest('.combo-breakdown-btn')) return;

        const cell = e.target.closest('.odds-cell:not(.odds-na)');
        if (!cell) return;

        const row       = cell.closest('tr');
        const eventId   = row?.dataset.eventId || null;
        const marketKey = row?.dataset.market   || null;
        const player    = row?.dataset.player   || null;
        const bk        = cell.dataset.bk       || null;
        const sport     = row?.closest('[data-sport-key]')?.dataset.sportKey || '';

        if (!eventId || !marketKey || !player || !bk) return;

        const stepper = row.querySelector('.line-stepper__val');
        // Yes/No markets have no stepper — their history is keyed under 'yn'
        const lineVal = stepper ? stepper.textContent.trim() : 'yn';

        // Build a human-readable title using the column index to find the header
        const cellIndex = [...cell.parentElement.children].indexOf(cell);
        const bookName  = cell.closest('table')
            ?.querySelector(`thead tr th:nth-child(${cellIndex + 1})`)
            ?.textContent.trim() ?? bk;
        const linePart = lineVal === 'yn' ? '' : ` ${lineVal}`;
        const title = `${player} — ${marketKey.replace(/_/g, ' ')}${linePart} — ${bookName}`;

        // Get the current odds value from the cell's span text
        const oddsText    = cell.querySelector('span')?.textContent.trim() || '';
        const currentOdds = parseInt(oddsText.replace('+', ''), 10) || null;

        const propLineNum   = lineVal === 'yn' ? null : parseFloat(lineVal) || null;
        const statsCacheKey = `${player}||${marketKey}`;
        oddsPropScoreCtx    = { row, marketKey, sport, eventId };

        // Show loading skeleton immediately — score renders once all fetches settle.
        oddsPropScoreEl.hidden = false;
        oddsPropScoreEl.innerHTML = `
            <div class="prop-score prop-score--loading">
                <div class="prop-score__toggle prop-score__toggle--skeleton">
                    <span class="prop-score__label">Prop Score</span>
                    <span class="prop-score__skeleton-bar"></span>
                </div>
            </div>`;

        // Fetch player stats if not already cached.
        const statsPromise = playerStatsCache[statsCacheKey]
            ? Promise.resolve(playerStatsCache[statsCacheKey])
            : (function () {
                const statsParams = new URLSearchParams({
                    action:      'statsight_get_player_stats',
                    nonce:       statsightAjax.nonce,
                    sport:       sport,
                    player_name: player,
                    market_key:  marketKey,
                    event_id:    eventId,
                });
                return fetch(statsightAjax.url + '?' + statsParams.toString())
                    .then(r => r.json())
                    .then(function (sJson) {
                        if (!sJson.success || !sJson.data) return null;
                        playerStatsCache[statsCacheKey] = sJson.data;
                        if (playerGamelogCache[statsCacheKey] === undefined && sJson.data.gamelog) {
                            playerGamelogCache[statsCacheKey] = sJson.data.gamelog;
                        }
                        return sJson.data;
                    })
                    .catch(() => null);
            }());

        // Fetch game spread.
        const spreadPromise = (function () {
            const spreadParams = new URLSearchParams({
                action:   'statsight_get_game_spread',
                nonce:    statsightAjax.nonce,
                sport:    sport,
                event_id: eventId,
            });
            return fetch(statsightAjax.url + '?' + spreadParams.toString())
                .then(r => r.json())
                .then(j => (j.success && j.data?.spread != null) ? j.data : null)
                .catch(() => null);
        }());

        // Render once both settle.
        Promise.all([statsPromise, spreadPromise]).then(function ([statsData, spreadData]) {
            if (oddsModal.hidden || oddsPropScoreCtx?.row !== row) return;
            oddsPropScoreEl.innerHTML = '';

            const signals = [];
            const detail   = row?.closest('.game-detail');
            const statCols = hitRateColumns(marketKey, sport);

            // ── Signal 1: EV% (0.20) ────────────────────────────────────────────
            // Market-implied edge over the book's no-vig line.
            if (row) {
                const evCell = row.querySelector('.col-ev');
                const evRaw  = evCell ? parseFloat(evCell.textContent) : NaN;
                if (!isNaN(evRaw)) {
                    signals.push({
                        label:   'EV%',
                        weight:  0.20,
                        value:   Math.min(10, Math.max(0, 5 + evRaw / 2)),
                        display: `${evRaw > 0 ? '+' : ''}${evRaw.toFixed(1)}%`,
                    });
                }
            }

            // ── Signals 2–4: player performance signals ──────────────────────────
            if (statsData && propLineNum !== null && statCols) {
                const { gamelog, averages, season_averages } = statsData;

                // Signal 2: Hit Rate (0.30) — how often the player cleared this exact line historically
                if (gamelog && gamelog.length > 0) {
                    const vals = gamelog.map(g => statCols.reduce((s, c) => s + (parseFloat(g.stats[c]) || 0), 0));
                    const hits = vals.filter(v => v > propLineNum).length;
                    const pct  = Math.round((hits / vals.length) * 100);
                    signals.push({
                        label:   'Hit Rate',
                        weight:  0.30,
                        value:   pct / 10,
                        display: `${pct}% (${hits}/${vals.length} games)`,
                    });
                }

                // Signal 3: Recent Avg (0.25) — last N games trend vs the line
                if (averages) {
                    const recentAvg = statCols.reduce((s, c) => s + (parseFloat(averages[c]) || 0), 0);
                    if (recentAvg > 0) {
                        signals.push({
                            label:   'Recent Avg',
                            weight:  0.25,
                            value:   Math.min(10, Math.max(0, 5 + ((recentAvg - propLineNum) / propLineNum) * 25)),
                            display: `${recentAvg.toFixed(1)} vs ${propLineNum} line`,
                        });
                    }
                }

                // Signal 4: Season Avg (0.10) — full-season baseline, lower weight mid-season dilution
                if (season_averages) {
                    const seasonAvg = statCols.reduce((s, c) => s + (parseFloat(season_averages[c]) || 0), 0);
                    if (seasonAvg > 0) {
                        signals.push({
                            label:   'Season Avg',
                            weight:  0.10,
                            value:   Math.min(10, Math.max(0, 5 + ((seasonAvg - propLineNum) / propLineNum) * 25)),
                            display: `${seasonAvg.toFixed(1)} vs ${propLineNum} line`,
                        });
                    }
                }
            }

            // ── Signal 5: Defensive Rank (0.08) ─────────────────────────────────
            // Opponent's defensive rank for pts allowed (lower rank = tougher D).
            if (row && detail) {
                const defenseRaw = detail.dataset.defense || '';
                const defense    = defenseRaw ? (() => { try { return JSON.parse(defenseRaw); } catch { return null; } })() : null;
                if (defense) {
                    const teamSection  = row.closest('.team-section');
                    const playerSide   = teamSection?.querySelector('.team-section__header')?.dataset.side || null;
                    const opponentName = playerSide === 'home' ? (detail.dataset.away || '') : playerSide === 'away' ? (detail.dataset.home || '') : null;
                    if (opponentName) {
                        const norm     = n => n.toLowerCase().replace('los angeles','la').replace('new york','ny').replace('golden state','gs');
                        const matchKey = Object.keys(defense).find(k => { const nk = norm(k); const no = norm(opponentName); return nk === no || nk.includes(no) || no.includes(nk); });
                        if (matchKey) {
                            const rank  = defense[matchKey]?.pts_rank;
                            const total = defense[matchKey]?.team_count ?? 30;
                            if (rank != null) {
                                signals.push({
                                    label:   'Defensive Rank',
                                    weight:  0.08,
                                    value:   Math.min(10, Math.max(0, ((rank - 1) / (total - 1)) * 10)),
                                    display: `Opp ranks #${rank} of ${total} in pts allowed`,
                                });
                            }
                        }
                    }
                }
            }

            // ── Signal 6: Rest Days (0.04) ───────────────────────────────────────
            // Back-to-back games measurably suppress output; extra rest is a mild positive.
            if (row && detail && !sport.startsWith('soccer_')) {
                const teamSection  = row.closest('.team-section');
                const playerSide   = teamSection?.querySelector('.team-section__header')?.dataset.side || null;
                const restRaw      = playerSide === 'home' ? detail?.dataset.restHome : playerSide === 'away' ? detail?.dataset.restAway : null;
                if (restRaw) {
                    const restData = (() => { try { return JSON.parse(restRaw); } catch { return null; } })();
                    if (restData && restData.days_rest !== null) {
                        const days = restData.days_rest;
                        // B2B (1 day) → 2/10, 2 days → 5/10 (neutral), 3 days → 7/10, 4+ days → 8/10
                        const value   = days <= 1 ? 2 : days === 2 ? 5 : days === 3 ? 7 : 8;
                        const display = days <= 1 ? 'Back-to-back' : `${days} days rest`;
                        signals.push({ label: 'Rest Days', weight: 0.04, value, display });
                    }
                }
            }

            // ── Signal 7: Game Spread (0.03) ─────────────────────────────────────
            // Blowout potential affects pace/garbage time; tight games favor volume.
            if (spreadData) {
                const spread = Math.abs(parseFloat(spreadData.spread));
                if (!isNaN(spread)) {
                    // Tight game (≤3): high volume, good for props → ~7. Big spread (10+): garbage time risk → ~3.
                    const value   = Math.min(10, Math.max(2, 7 - (spread / 10) * 4));
                    const label   = spreadData.spread_favorite ? `${spreadData.spread_favorite} -${spread.toFixed(1)}` : `${spread.toFixed(1)} pt spread`;
                    signals.push({ label: 'Game Spread', weight: 0.03, value, display: label });
                }
            }

            if (signals.length === 0) { oddsPropScoreEl.hidden = true; return; }
            const block = buildPropScoreBlock(signals, TOTAL_SIGNALS);
            if (block) oddsPropScoreEl.appendChild(block);
        });

        // Cache key for this specific combo \u2014 avoids re-fetching on repeated opens.
        const histCacheKey = `${eventId}|${marketKey}|${player}|${lineVal}|${bk}`;
        const TWO_MIN = 2 * 60 * 1000;
        const cachedAt = oddsHistoryCacheTime[histCacheKey] ?? 0;
        const cacheWarmCombo = oddsHistoryCache[histCacheKey] !== undefined && (Date.now() - cachedAt) < TWO_MIN;

        function renderChartFromHistory(snapshots) {
            if (!oddsModal.hidden && oddsModalTitle.textContent === title) {
                openOddsModal(title, snapshots?.length >= 2 ? snapshots : null, currentOdds, marketKey, lineVal);
            }
        }

        if (cacheWarmCombo) {
            openOddsModal(title, oddsHistoryCache[histCacheKey]?.length >= 2 ? oddsHistoryCache[histCacheKey] : null, currentOdds, marketKey, lineVal);
        } else {
            openOddsModal(title, null, currentOdds, marketKey, lineVal);
            oddsModalEmpty.textContent = 'Loading\u2026';
            oddsModalEmpty.classList.add('odds-modal__empty--loading');
            oddsModalEmpty.hidden      = false;
            oddsChartCanvas.hidden     = true;

            const histParams = new URLSearchParams({
                action:     'statsight_get_odds_history',
                nonce:      statsightAjax.nonce,
                event_id:   eventId,
                market_key: marketKey,
                player:     player,
                line:       lineVal,
                book_key:   bk,
                limit:      window.innerWidth < 640 ? 10 : 20,
            });
            fetch(statsightAjax.url + '?' + histParams.toString())
                .then(r => r.json())
                .then(function (json) {
                    oddsModalEmpty.classList.remove('odds-modal__empty--loading');
                    if (!json.success) {
                        if (!oddsModal.hidden && oddsModalTitle.textContent === title) {
                            const isPlanError = json.data?.plan_required;
                            oddsModalEmpty.textContent = isPlanError
                                ? 'Odds history is available on Pro and Sharp plans.'
                                : 'Not enough history to display a chart.';
                        }
                        return;
                    }
                    oddsHistoryCache[histCacheKey]     = json.data;
                    oddsHistoryCacheTime[histCacheKey] = Date.now();
                    renderChartFromHistory(json.data);
                })
                .catch(() => {
                    oddsModalEmpty.classList.remove('odds-modal__empty--loading');
                    if (!oddsModal.hidden && oddsModalTitle.textContent === title) {
                        oddsModalEmpty.textContent = 'Not enough history to display a chart.';
                    }
                });
        }
    });

    // ── Player Comparison ──────────────────────────────────────────────────

    const compareBar     = document.getElementById('compare-bar');
    const compareNameA   = document.getElementById('compare-name-a');
    const compareNameB   = document.getElementById('compare-name-b');
    const compareGoBtn   = document.getElementById('compare-go');
    const compareClearBtn = document.getElementById('compare-clear');
    const compareModal   = document.getElementById('compare-modal');
    const compareModalClose = document.getElementById('compare-modal-close');
    const compareContent = compareModal.querySelector('.compare-modal__content');
    const compareLoading = compareModal.querySelector('.compare-modal__loading');

    // State: each slot holds { player, marketKey, sport, propLine, eventId } or null
    const compareSlots = { a: null, b: null };

    function updateCompareBar() {
        const hasA = compareSlots.a !== null;
        const hasB = compareSlots.b !== null;

        compareNameA.textContent = hasA ? compareSlots.a.player : '—';
        compareNameB.textContent = hasB ? compareSlots.b.player : '—';
        compareGoBtn.disabled    = !(hasA && hasB);
        compareBar.hidden        = !(hasA || hasB);

        // Highlight active compare buttons
        document.querySelectorAll('.compare-btn').forEach(function (btn) {
            const p = btn.dataset.player;
            const inA = compareSlots.a?.player === p;
            const inB = compareSlots.b?.player === p;
            btn.classList.toggle('compare-btn--active', inA || inB);
        });
    }

    // Delegated click on compare buttons
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.compare-btn');
        if (!btn) return;

        const row        = btn.closest('tr[data-player]');
        if (!row) return;

        const player    = row.dataset.player   || '';
        const marketKey = row.dataset.market   || '';
        const eventId   = row.dataset.eventId  || '';
        const sport     = row.closest('[data-sport-key]')?.dataset.sportKey || '';

        // Store a reference to the row so we can read the current line at modal-open time.
        const slot = { player, marketKey, sport, eventId, row };

        // If already in a slot, remove it
        if (compareSlots.a?.player === player) {
            compareSlots.a = null;
        } else if (compareSlots.b?.player === player) {
            compareSlots.b = null;
        } else if (!compareSlots.a) {
            compareSlots.a = slot;
        } else if (!compareSlots.b) {
            compareSlots.b = slot;
        } else {
            // Both full — replace slot A and shift B to A
            compareSlots.a = compareSlots.b;
            compareSlots.b = slot;
        }

        updateCompareBar();
    });

    // Remove buttons on the bar
    document.querySelectorAll('.compare-bar__remove').forEach(function (btn) {
        btn.addEventListener('click', function () {
            compareSlots[btn.dataset.slot] = null;
            updateCompareBar();
        });
    });

    compareClearBtn.addEventListener('click', function () {
        compareSlots.a = null;
        compareSlots.b = null;
        updateCompareBar();
    });

    compareGoBtn.addEventListener('click', openCompareModal);

    function openCompareModal() {
        if (!compareSlots.a || !compareSlots.b) return;

        compareLoading.hidden = false;
        compareContent.hidden = true;
        compareModal.hidden   = false;
        document.body.classList.add('odds-modal-open');

        const slotA = compareSlots.a;
        const slotB = compareSlots.b;

        // Read the current line from the DOM at modal-open time so stepper adjustments are reflected.
        const currentLine = (slot) => parseFloat(slot.row?.querySelector('.line-stepper__val')?.textContent.trim()) || null;
        slotA.propLine = currentLine(slotA);
        slotB.propLine = currentLine(slotB);

        const fetchPlayer = (slot) => fetch(statsightAjax.url + '?' + new URLSearchParams({
            action:      'statsight_get_player_stats',
            nonce:       statsightAjax.nonce,
            sport:       slot.sport,
            player_name: slot.player,
            market_key:  slot.marketKey,
            event_id:    slot.eventId,
        })).then(r => r.json());

        Promise.all([fetchPlayer(slotA), fetchPlayer(slotB)])
            .then(function ([jsonA, jsonB]) {
                compareLoading.hidden = true;
                if (!jsonA.success || !jsonB.success) {
                    const isPlanError = !jsonA.success && jsonA.data?.plan_required || !jsonB.success && jsonB.data?.plan_required;
                    compareContent.innerHTML = isPlanError
                        ? `<p class="compare-error"><strong>Pro feature</strong> — Player comparisons are available on the Pro plan and above.<br><a href="${escHtml(statsightAjax.homeUrl)}" class="modal-upgrade-btn">View plans &rarr;</a></p>`
                        : `<p class="compare-error">Could not load player data.</p>`;
                    compareContent.hidden = false;
                    return;
                }
                renderComparison(slotA, jsonA.data, slotB, jsonB.data);
                compareContent.hidden = false;
            })
            .catch(function () {
                compareLoading.hidden = true;
                compareContent.innerHTML = `<p class="compare-error">Request failed.</p>`;
                compareContent.hidden = false;
            });
    }

    function renderComparison(slotA, dataA, slotB, dataB) {
        const statColsA = hitRateColumns(slotA.marketKey, dataA.sport);
        const statColsB = hitRateColumns(slotB.marketKey, dataB.sport);

        const buildStatRows = (data, slot, statCols) => {
            const { gamelog, averages, season_averages } = data;
            if (!gamelog || gamelog.length === 0) return '<p class="compare-no-data">No recent game data.</p>';

            // Averages row
            const avgLabel = statCols ? statCols.join('+') : 'Stats';
            const recentAvg = averages ? (statCols || []).map(c => averages[c] ?? '—').join(' / ') : '—';
            const seasonAvg = season_averages ? (statCols || []).map(c => season_averages[c] ?? '—').join(' / ') : '—';

            // Hit rate
            let hitRateHtml = '';
            if (slot.propLine !== null && statCols && gamelog.length > 0) {
                const hits = gamelog.filter(function (g) {
                    const val = statCols.reduce((s, c) => s + (parseFloat(g.stats[c]) || 0), 0);
                    return val > slot.propLine;
                }).length;
                const pct = Math.round((hits / gamelog.length) * 100);
                const cls = pct >= 60 ? 'hit-rate-chip--high' : pct <= 40 ? 'hit-rate-chip--low' : 'hit-rate-chip--mid';
                hitRateHtml = `<span class="hit-rate-chip ${cls}" style="font-size:0.85rem;padding:0.2em 0.6em">${hits}/${gamelog.length} (${pct}%)</span>`;
            }

            // Last 5 games (gamelog is oldest-first; take the tail)
            const last5 = gamelog.slice(-5).reverse().map(function (g) {
                const val = statCols ? statCols.reduce((s, c) => s + (parseFloat(g.stats[c]) || 0), 0) : '—';
                const hit = slot.propLine !== null && statCols && val > slot.propLine;
                const cls = slot.propLine !== null && statCols ? (hit ? 'compare-game--hit' : 'compare-game--miss') : '';
                return `<div class="compare-game ${cls}">
                    <span class="compare-game__opp">${escHtml(g.opponent)}</span>
                    <span class="compare-game__val">${statCols ? val.toFixed(1) : '—'}</span>
                </div>`;
            }).join('');

            return `
                <div class="compare-stats">
                    <div class="compare-stats__line">
                        <span class="compare-stats__label">Line</span>
                        <span class="compare-stats__val">${slot.propLine ?? '—'}</span>
                    </div>
                    <div class="compare-stats__line">
                        <span class="compare-stats__label">L10 Avg</span>
                        <span class="compare-stats__val">${escHtml(recentAvg)}</span>
                    </div>
                    <div class="compare-stats__line">
                        <span class="compare-stats__label">Season Avg</span>
                        <span class="compare-stats__val">${escHtml(seasonAvg)}</span>
                    </div>
                    <div class="compare-stats__line">
                        <span class="compare-stats__label">Hit Rate</span>
                        <span class="compare-stats__val">${hitRateHtml || '—'}</span>
                    </div>
                    <div class="compare-stats__label compare-stats__label--section">Last 5 Games</div>
                    <div class="compare-games">${last5 || '<span style="color:var(--color-text-dim)">No data</span>'}</div>
                </div>`;
        };

        compareContent.innerHTML = `
            <div class="compare-grid">
                <div class="compare-player">
                    <div class="compare-player__header">
                        ${dataA.headshot ? `<img class="compare-player__headshot" src="${escHtml(dataA.headshot)}" alt="${escHtml(slotA.player)}" loading="lazy">` : ''}
                        <div>
                            <div class="compare-player__name">${escHtml(slotA.player)}</div>
                            <div class="compare-player__market">${escHtml(slotA.marketKey.replace(/_/g, ' '))}</div>
                        </div>
                    </div>
                    ${buildStatRows(dataA, slotA, statColsA)}
                </div>
                <div class="compare-divider"></div>
                <div class="compare-player">
                    <div class="compare-player__header">
                        ${dataB.headshot ? `<img class="compare-player__headshot" src="${escHtml(dataB.headshot)}" alt="${escHtml(slotB.player)}" loading="lazy">` : ''}
                        <div>
                            <div class="compare-player__name">${escHtml(slotB.player)}</div>
                            <div class="compare-player__market">${escHtml(slotB.marketKey.replace(/_/g, ' '))}</div>
                        </div>
                    </div>
                    ${buildStatRows(dataB, slotB, statColsB)}
                </div>
            </div>`;
    }

    compareModalClose.addEventListener('click', function () {
        compareModal.hidden = true;
        document.body.classList.remove('odds-modal-open');
    });
    compareModal.querySelector('.odds-modal__backdrop').addEventListener('click', function () {
        compareModal.hidden = true;
        document.body.classList.remove('odds-modal-open');
    });

    // Delegated click handler for alert bell buttons.
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.alert-btn');
        if (!btn) return;

        if (statsightAjax.plan === 'free') {
            window.location.href = statsightAjax.homeUrl + '#pricing';
            return;
        }

        const row         = btn.closest('tr[data-player]');
        if (!row) return;

        const player      = row.dataset.player      || '';
        const marketKey   = row.dataset.market       || '';
        const marketLabel = row.dataset.marketLabel  || marketKey.replace(/_/g, ' ');
        const eventId     = row.dataset.eventId      || btn.dataset.eventId || '';
        const sport       = row.closest('[data-sport-key]')?.dataset.sportKey || '';
        const line        = getAlertLine(btn) || '';
        const matchup     = row.closest('[data-home]')
            ? (row.closest('[data-away]')?.dataset.away || '') + ' @ ' + (row.closest('[data-home]')?.dataset.home || '')
            : document.querySelector(`.game-row[data-event-id="${CSS.escape(eventId)}"]`)?.dataset.matchup || '';

        openAlertPopover(btn, { btn, eventId, sport, player, marketKey, marketLabel, line, matchup });
    });

}());
</script>

<?php get_footer(); ?>
