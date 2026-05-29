<?php
/**
 * Template Name: Watchlist
 *
 * Prop watchlist and collection builder for Sharp-tier users.
 */

statsight_require_plan_redirect( 'sharp' );

get_header();

$plan        = statsight_get_user_plan();
$is_sharp    = ( $plan === 'sharp' );
$is_loggedin = is_user_logged_in();
?>

<div class="wl-page">

    <div class="wl-page-header">
        <div>
            <h1 class="wl-page-title">Watchlist</h1>
            <p class="wl-page-subtitle">Props you're eyeing — group them into collections when you're ready.</p>
        </div>
    </div>

    <?php if ( ! $is_loggedin ) : ?>
        <div class="wl-gate">
            <p>You need to be logged in to use the watchlist.</p>
            <a class="wl-gate-btn" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">Log in</a>
        </div>
    <?php elseif ( ! $is_sharp ) : ?>
        <div class="wl-gate">
            <p>The watchlist is available on the <strong>Sharp</strong> plan.</p>
            <a class="wl-gate-btn" href="<?php echo esc_url( home_url( '/#pricing' ) ); ?>">Upgrade</a>
        </div>
    <?php else : ?>

    <!-- Tab bar -->
    <div class="wl-tabs" role="tablist">
        <button class="wl-tab wl-tab--active" id="wl-tab-watchlist" role="tab" aria-selected="true" aria-controls="wl-panel-watchlist">Watchlist</button>
        <button class="wl-tab" id="wl-tab-history" role="tab" aria-selected="false" aria-controls="wl-panel-history">History</button>
    </div>

    <!-- Watchlist panel -->
    <div id="wl-panel-watchlist" role="tabpanel" aria-labelledby="wl-tab-watchlist">
        <div class="wl-filter-bar">
            <input
                class="wl-filter-input"
                id="wl-filter-input"
                type="search"
                placeholder="Search by player, market, or matchup&hellip;"
                autocomplete="off"
                aria-label="Filter watchlist"
            >
        </div>

        <div id="wl-app">
            <div class="wl-loading">Loading your watchlist&hellip;</div>
        </div>
    </div>

    <!-- History panel -->
    <div id="wl-panel-history" role="tabpanel" aria-labelledby="wl-tab-history" hidden>
        <div id="wl-breakdown-app"></div>
        <div id="wl-history-app">
            <div class="wl-loading">Loading history&hellip;</div>
        </div>
    </div>

    <script>
    (function () {
        const ajaxUrl    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        const nonce      = <?php echo wp_json_encode( wp_create_nonce( 'statsight_events' ) ); ?>;
        const app        = document.getElementById('wl-app');
        const filterInput = document.getElementById('wl-filter-input');
        let historyLoaded = false;
        let   filterQuery = '';

        function escHtml(s) {
            const d = document.createElement('div');
            d.appendChild(document.createTextNode(String(s ?? '')));
            return d.innerHTML;
        }

        function fmtOdds(o) {
            o = parseInt(o, 10);
            return isNaN(o) ? '—' : (o >= 0 ? '+' + o : String(o));
        }

        function fmtBook(key) {
            if (!key) return '—';
            // Use the global map if the props page loaded it, otherwise fall back to formatting.
            if (typeof BOOK_LABELS !== 'undefined' && BOOK_LABELS[key]) return BOOK_LABELS[key];
            const map = {
                fanduel: 'FanDuel', draftkings: 'DraftKings', betmgm: 'BetMGM',
                caesars: 'Caesars', bet365: 'Bet365', fanatics: 'Fanatics',
                espnbet: 'ESPN Bet', williamhill_us: 'Caesars', pointsbetus: 'BetUS',
                betus: 'BetUS', mybookieag: 'MyBookie', betonlineag: 'BetOnline',
                superbook: 'SuperBook', unibet_us: 'Unibet', wynnbet: 'WynnBET',
                betrivers: 'BetRivers', bovada: 'Bovada', ballybet: 'Bally Bet',
                hardrock: 'Hard Rock Bet', fliff: 'Fliff', prizepicks: 'PrizePicks',
                underdog_fantasy: 'Underdog',
            };
            return map[key] || key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        }

        // ── Math helpers ─────────────────────────────────────────────────────
        function americanToImplied(odds) {
            if (odds >= 0) return 100 / (odds + 100);
            return Math.abs(odds) / (Math.abs(odds) + 100);
        }

        // No-vig EV% given { bookKey -> over_odds } and { bookKey -> under_odds }.
        // Returns null if fewer than 2 books have both sides.
        function calcEV(overMap, underMap, bestOverOdds) {
            const books = Object.keys(overMap).filter(bk => overMap[bk] != null && underMap[bk] != null);
            if (books.length < 2) return null;
            const noVigProbs = books.map(bk => {
                const ov = americanToImplied(overMap[bk]);
                const un = americanToImplied(underMap[bk]);
                return ov / (ov + un);
            });
            const noVigProb  = noVigProbs.reduce((s, p) => s + p, 0) / noVigProbs.length;
            const bestDecimal = bestOverOdds >= 0 ? bestOverOdds / 100 : 100 / Math.abs(bestOverOdds);
            const ev = (noVigProb * bestDecimal) - (1 - noVigProb);
            return parseFloat((ev * 100).toFixed(1));
        }

        // Trend from oldest→newest snapshots array of { over }.
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

        function post(action, data) {
            return fetch(ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ action, nonce, ...data }).toString(),
            }).then(r => r.json());
        }

        // ── Stat helpers ─────────────────────────────────────────────────────
        const MARKET_STAT_COLS = {
            player_points:                  ['PTS'],
            player_rebounds:                ['REB'],
            player_assists:                 ['AST'],
            player_threes:                  ['3PT'],
            player_blocks:                  ['BLK'],
            player_steals:                  ['STL'],
            player_blocks_steals:           ['BLK', 'STL'],
            player_turnovers:               ['TO'],
            player_field_goals:             ['FG'],
            player_frees_made:              ['FT'],
            player_points_rebounds_assists: ['PTS', 'REB', 'AST'],
            player_points_rebounds:         ['PTS', 'REB'],
            player_points_assists:          ['PTS', 'AST'],
            player_rebounds_assists:        ['REB', 'AST'],
            player_pass_yds:                ['YDS'],
            player_pass_tds:                ['TD'],
            player_pass_attempts:           ['ATT'],
            player_pass_completions:        ['COMP'],
            player_rush_yds:                ['YDS'],
            player_rush_attempts:           ['CAR'],
            player_reception_yds:           ['YDS'],
            player_receptions:              ['REC'],
            player_pass_interceptions:      ['INT'],
            player_strikeouts:              ['K'],
            player_hits:                    ['H'],
            player_total_bases:             ['TB'],
            player_home_runs:               ['HR'],
            player_runs_scored:             ['R'],
            player_rbis:                    ['RBI'],
            player_shots_on_goal:           ['S'],   // ESPN skater stat is 'S', not 'SOG'
            player_goals:                   ['G'],
            player_assists_hockey:          ['A'],
            player_points_hockey:           ['G', 'A'],
        };

        // Look up a player's current stat total for a given market from the boxscore.
        // Returns null if no data, or a number.
        function getPlayerStat(eventId, player, marketKey) {
            const bs = gameBoxscores[eventId];
            if (!bs) return null;
            // Try exact name match first, then partial.
            let stats = bs[player];
            if (!stats) {
                const lc = player.toLowerCase();
                const key = Object.keys(bs).find(k => k.toLowerCase() === lc || k.toLowerCase().includes(lc) || lc.includes(k.toLowerCase()));
                stats = key ? bs[key] : null;
            }
            if (!stats) return null;
            const mk   = (marketKey || '').replace(/_alternate$/, '');
            const cols = MARKET_STAT_COLS[mk];
            if (!cols) return null;
            let total = 0;
            for (const col of cols) {
                const raw = stats[col];
                if (raw == null || raw === '—') return null;
                const num = parseFloat(String(raw).split(/[\/\-]/)[0]);
                if (isNaN(num)) return null;
                total += num;
            }
            return total;
        }

        // ── State ────────────────────────────────────────────────────────────
        let props          = [];        // watchlist rows from server
        let parlays        = [];        // parlay groups from server
        let selected       = new Set(); // prop ids (strings) selected for a new parlay
        let buildingParlay = false;     // whether parlay builder UI is open
        let expanded       = new Set(); // prop ids with books expanded
        let gameStatuses   = {};        // event_id -> 'pre'|'live'|'final'
        let gameLogos      = {};        // event_id -> { home, away }
        let gameBoxscores  = {};        // event_id -> { "Player Name" -> { "PTS": "22", ... } }
        let underOddsMap   = {};        // watchlist_id -> { book_key -> under_odds }
        let oddsHistoryMap = {};        // watchlist_id -> [{ over }, ...]

        // ── Render ───────────────────────────────────────────────────────────
        function render() {
            if (!props.length && !parlays.length) {
                app.innerHTML = `
                    <div class="wl-empty">
                        <p>Your watchlist is empty.</p>
                        <p class="wl-empty-hint">Click the <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg> button on any prop row to save it here.</p>
                    </div>`;
                return;
            }

            // ── Feature 1-4: group by game, live progress, trend, EV ──────────
            // Group props by event_id, preserving server order within each group.
            const gameGroups = [];
            const gameIndex  = {};
            props.forEach(p => {
                if (!gameIndex[p.event_id]) {
                    gameIndex[p.event_id] = { matchup: p.matchup, props: [] };
                    gameGroups.push(gameIndex[p.event_id]);
                }
                gameIndex[p.event_id].props.push(p);
            });

            const propRows = gameGroups.map(group => {
                const gameStatus  = gameStatuses[group.props[0].event_id] || 'pre';
                // If every prop in the group has a settled result, treat the game as final
                // even if the ESPN status API didn't return 'final' (can happen for older games).
                const allSettled  = group.props.every(p => p.result && p.result !== 'void');
                const effectiveStatus = (allSettled || gameStatus === 'final') ? 'final' : gameStatus;
                const groupBadge  = effectiveStatus === 'final'
                    ? `<span class="wl-status-badge wl-status-badge--final">Final</span>`
                    : effectiveStatus === 'live'
                    ? `<span class="wl-status-badge wl-status-badge--live">Live</span>`
                    : '';

                // Game header row
                const logos    = gameLogos[group.props[0].event_id] || {};
                const awayLogo = logos.away
                    ? `<img class="wl-game-header__logo" src="${escHtml(logos.away)}" alt="" aria-hidden="true">`
                    : '';
                const homeLogo = logos.home
                    ? `<img class="wl-game-header__logo" src="${escHtml(logos.home)}" alt="" aria-hidden="true">`
                    : '';

                // Split "Away @ Home" so we can insert logos before each team name.
                const matchupParts = group.matchup.split(' vs ');
                const awayName = matchupParts[0] || group.matchup;
                const homeName = matchupParts[1] || '';
                const matchupHtml = homeName
                    ? `${awayLogo}<span>${escHtml(awayName)}</span><span class="wl-game-header__sep">vs</span>${homeLogo}<span>${escHtml(homeName)}</span>`
                    : `${awayLogo}<span>${escHtml(awayName)}</span>`;

                const groupHeader = `
                    <div class="wl-game-header">
                        <div class="wl-game-header__inner">
                            ${matchupHtml}
                            ${groupBadge}
                        </div>
                    </div>`;

                const rows = group.props.map(p => {
                    const isSel    = selected.has(String(p.id));
                    const isExp    = expanded.has(String(p.id));
                    const otherBooks = (() => {
                        try { return JSON.parse(p.all_books || '[]'); } catch { return []; }
                    })().filter(b => b.book !== p.book);
                    const hasOthers = otherBooks.length > 0;

                    // Settlement result — written by cron after game ends.
                    const settled   = p.result && p.result !== 'void';

                    // A prop with a result is definitively final regardless of what
                    // the ESPN status API returns (it may be stale or missing for old games).
                    const isFinal   = gameStatus === 'final' || settled;
                    const isInGame  = gameStatus === 'live' || isFinal;
                    const statVal   = isInGame ? getPlayerStat(p.event_id, p.player, p.market_key) : null;
                    const line      = parseFloat(p.line);
                    const dir       = (p.direction || 'over').toLowerCase();
                    const actualStat = p.actual_stat != null ? parseFloat(p.actual_stat) : null;

                    // Build a human-readable stat label for settled props.
                    const SCORER_MARKETS = ['player_anytime_td','player_1st_td','player_last_td',
                        'player_goal_scorer_anytime','player_goal_scorer_first','player_goal_scorer_last',
                        'player_first_goal_scorer','player_last_goal_scorer'];
                    const mk = (p.market_key || '').replace(/_alternate$/, '');
                    const isScorer = SCORER_MARKETS.includes(mk);
                    const actualLabel = settled
                        ? ( isScorer
                            ? ( actualStat === 1 ? 'Scored' : 'No score' )
                            : ( actualStat != null ? String(actualStat) : null ) )
                        : null;

                    // Feature 1 — result badge (settled) or live progress badge.
                    const resultBadge = settled
                        ? `<span class="wl-result-badge wl-result-badge--${escHtml(p.result)}" title="${actualLabel ? `Actual: ${actualLabel}` : ''}">${p.result.charAt(0).toUpperCase() + p.result.slice(1)}${actualLabel ? ` · ${actualLabel}` : ''}</span>`
                        : ( gameStatus === 'live' && statVal !== null
                            ? `<span class="wl-result-badge wl-result-badge--progress">${statVal} / ${line}</span>`
                            : '' );

                    // Feature 2 — odds trend arrow
                    const snapshots = oddsHistoryMap[p.id] || [];
                    const trend     = calcTrend(snapshots);
                    const trendHtml = trend.direction
                        ? `<span class="wl-trend wl-trend--${trend.direction}" title="${trend.direction === 'up' ? '+' : ''}${trend.delta} since last update">
                               ${trend.direction === 'up' ? '↑' : '↓'}${Math.abs(trend.delta)}
                           </span>`
                        : '';

                    // Feature 3 — EV%
                    const overMap  = (underOddsMap[p.id] ? Object.fromEntries(
                        Object.entries(underOddsMap[p.id]).map(([bk]) => [bk, null])
                    ) : {});
                    // Build over + under maps from live_odds and under_odds
                    const liveOverMap  = Object.fromEntries(
                        Object.entries(underOddsMap[p.id] || {}).map(([bk, un]) => {
                            // Find over from all_books or from the prop itself
                            const allBooks = (() => { try { return JSON.parse(p.all_books || '[]'); } catch { return []; } })();
                            const entry    = allBooks.find(b => b.book === bk);
                            return [bk, entry ? parseInt(entry.odds, 10) : (bk === p.book ? parseInt(p.odds, 10) : null)];
                        })
                    );
                    const liveUnderMap = underOddsMap[p.id] || {};
                    const bestOdds     = parseInt(p.odds, 10);
                    const ev           = !isFinal ? calcEV(liveOverMap, liveUnderMap, bestOdds) : null;
                    const evHtml       = ev !== null
                        ? `<span class="wl-ev wl-ev--${ev > 0 ? 'pos' : ev < -5 ? 'neg' : 'mid'}">${ev > 0 ? '+' : ''}${ev}%</span>`
                        : '';

                    const oddsCell = isFinal
                        ? `<td class="wl-col-odds">${fmtOdds(p.odds)}</td>`
                        : `<td class="wl-col-odds">${fmtOdds(p.odds)}${trendHtml}</td>`;

                    // CLV badge — shown for final games only.
                    const clv    = p.clv !== null && p.clv !== undefined && p.clv !== '' ? parseInt(p.clv, 10) : null;
                    const clvHtml = isFinal && clv !== null
                        ? `<span class="wl-clv wl-clv--${clv > 0 ? 'pos' : clv < 0 ? 'neg' : 'mid'}" title="Closing Line Value">${clv > 0 ? '+' : ''}${clv} CLV</span>`
                        : null;

                    const mainRow = `<tr class="wl-row${isSel ? ' wl-row--selected' : ''}${isFinal ? ' wl-row--ended' : ''}" data-id="${p.id}">
                        <td class="wl-col-check">
                            ${!isFinal ? `<button class="wl-select-btn${isSel ? ' wl-select-btn--on' : ''}" data-id="${p.id}" title="Select for collection">
                                <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="visibility:${isSel ? 'visible' : 'hidden'}"><polyline points="20 6 9 17 4 12"/></svg>
                            </button>` : ''}
                        </td>
                        <td class="wl-col-player"><strong>${escHtml(p.player)}</strong> ${resultBadge}</td>
                        <td class="wl-col-market">${escHtml(p.market_label)}</td>
                        <td class="wl-col-line">${escHtml(p.line)} (${escHtml(dir)})</td>
                        ${oddsCell}
                        <td class="wl-col-book">${escHtml(fmtBook(p.book))}</td>
                        <td class="wl-col-ev">${isFinal ? (clvHtml || '—') : (evHtml || '—')}</td>
                        <td class="wl-col-remove">
                            ${hasOthers && !isFinal ? `<button class="wl-expand-btn${isExp ? ' wl-expand-btn--open' : ''}" data-id="${p.id}" title="${isExp ? 'Collapse' : 'Show all books'}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>` : ''}
                            <button class="wl-remove-btn" data-id="${p.id}" title="Remove">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </td>
                    </tr>`;

                    const bookRows = (isExp && hasOthers && !isFinal) ? otherBooks.map(b => {
                        const bkey = `${p.id}:${b.book}`;
                        const bSel = selected.has(bkey);
                        return `<tr class="wl-book-row${bSel ? ' wl-row--selected' : ''}" data-parent-id="${p.id}">
                            <td class="wl-col-check">
                                <button class="wl-select-btn${bSel ? ' wl-select-btn--on' : ''}" data-bkey="${escHtml(bkey)}" title="Select for collection">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="visibility:${bSel ? 'visible' : 'hidden'}"><polyline points="20 6 9 17 4 12"/></svg>
                                </button>
                            </td>
                            <td class="wl-col-player"></td>
                            <td class="wl-col-market"></td>
                            <td class="wl-col-line"></td>
                            <td class="wl-col-odds">${fmtOdds(b.odds)}</td>
                            <td class="wl-col-book">${escHtml(fmtBook(b.book))}</td>
                            <td class="wl-col-ev"></td>
                            <td class="wl-col-remove"></td>
                        </tr>`;
                    }).join('') : '';

                    return mainRow + bookRows;
                }).join('');

                return `
                    <div class="wl-game-group">
                        ${groupHeader}
                        <div class="props-table-wrap">
                            <table class="props-table wl-table">
                                <thead><tr>
                                    <th class="wl-col-check"></th>
                                    <th class="wl-col-player">Player / Prop</th>
                                    <th class="wl-col-market">Market</th>
                                    <th class="wl-col-line">Line</th>
                                    <th class="wl-col-odds">Odds</th>
                                    <th class="wl-col-book">Book</th>
                                    <th class="wl-col-ev">${gameStatus === 'final' ? 'CLV' : 'EV%'}</th>
                                    <th class="wl-col-remove"></th>
                                </tr></thead>
                                <tbody>${rows}</tbody>
                            </table>
                        </div>
                    </div>`;
            }).join('');

            const parlayHtml = parlays.map(parlay => {
                const legs = (parlay.legs || []).map(l =>
                    `<li class="wl-parlay-leg">
                        <span class="wl-parlay-leg__player">${escHtml(l.player)} <span class="wl-parlay-leg__line">${escHtml(l.line)}+</span> <span class="wl-parlay-leg__market">${escHtml(l.market_label)}</span></span>
                        <span class="wl-parlay-leg__odds">${fmtOdds(l.odds)}</span>
                        <span class="wl-parlay-leg__book">${escHtml(fmtBook(l.book))}</span>
                    </li>`
                ).join('');
                return `<div class="wl-parlay" data-parlay-id="${parlay.id}">
                    <div class="wl-parlay__header">
                        <span class="wl-parlay__name">${escHtml(parlay.name)}</span>
                        <span class="wl-parlay__count">${(parlay.legs || []).length} prop${(parlay.legs || []).length !== 1 ? 's' : ''}</span>
                        <button class="wl-parlay__delete" data-parlay-id="${parlay.id}" title="Delete collection">&times;</button>
                    </div>
                    <ul class="wl-parlay__legs">${legs}</ul>
                </div>`;
            }).join('');

            app.innerHTML = `
                ${props.length ? `
                <section class="wl-section">
                    <div class="wl-section-header">
                        <h2 class="wl-section-title">Saved Props</h2>
                        <div class="wl-section-header__actions">
                            ${props.some(p => gameStatuses[p.event_id] === 'final' || (p.result && p.result !== 'void')) ? `<button class="wl-clear-ended-btn" id="wl-clear-ended-btn">Clear Ended</button>` : ''}
                            ${props.length >= 2 ? `<button class="wl-build-btn${buildingParlay ? ' wl-build-btn--active' : ''}" id="wl-build-parlay-btn">${buildingParlay ? `New Collection${selected.size >= 2 ? ` (${selected.size} selected)` : ''}` : 'New Collection'}</button>` : ''}
                        </div>
                    </div>
                    ${buildingParlay ? `
                    <div class="wl-parlay-builder">
                        <input class="wl-parlay-name" id="wl-parlay-name" type="text" placeholder="Collection name (e.g. Thursday Night Legs)" maxlength="100">
                        <button class="wl-parlay-save-btn" id="wl-save-parlay-btn">Save Collection</button>
                        <button class="wl-parlay-cancel-btn" id="wl-cancel-parlay-btn">Cancel</button>
                        <p class="wl-parlay-hint">Select at least 2 props below, then save.</p>
                        <p class="wl-parlay-error" id="wl-parlay-error" hidden></p>
                    </div>` : ''}
                    <div class="wl-groups">${propRows}</div>
                </section>` : ''}

                ${parlays.length ? `
                <section class="wl-section wl-section--parlays">
                    <h2 class="wl-section-title">My Collections</h2>
                    <div class="wl-parlays">${parlayHtml}</div>
                </section>` : ''}`;

            bindEvents();
            applyFilter();
        }

        // ── Filter ───────────────────────────────────────────────────────────
        function applyFilter() {
            const q = filterQuery.trim().toLowerCase();
            const groups = app.querySelectorAll('.wl-game-group');

            groups.forEach(group => {
                const rows = group.querySelectorAll('tr.wl-row');
                let groupVisible = false;

                rows.forEach(row => {
                    if (!q) {
                        row.classList.remove('wl-row--hidden');
                        // Also show any associated book sub-rows.
                        const id = row.dataset.id;
                        if (id) {
                            group.querySelectorAll(`tr.wl-book-row[data-parent-id="${id}"]`)
                                .forEach(br => br.classList.remove('wl-row--hidden'));
                        }
                        groupVisible = true;
                        return;
                    }

                    // Gather searchable text from this row's cells.
                    const playerCell = row.querySelector('.wl-col-player');
                    const marketCell = row.querySelector('.wl-col-market');
                    const playerText = (playerCell?.textContent || '').toLowerCase();
                    const marketText = (marketCell?.textContent || '').toLowerCase();
                    // Also include the matchup from the group header.
                    const headerText = (group.querySelector('.wl-game-header')?.textContent || '').toLowerCase();

                    const matches = playerText.includes(q) || marketText.includes(q) || headerText.includes(q);

                    if (matches) {
                        row.classList.remove('wl-row--hidden');
                        // Show associated book sub-rows too.
                        const id = row.dataset.id;
                        if (id) {
                            group.querySelectorAll(`tr.wl-book-row[data-parent-id="${id}"]`)
                                .forEach(br => br.classList.remove('wl-row--hidden'));
                        }
                        groupVisible = true;
                    } else {
                        row.classList.add('wl-row--hidden');
                        // Hide associated book sub-rows.
                        const id = row.dataset.id;
                        if (id) {
                            group.querySelectorAll(`tr.wl-book-row[data-parent-id="${id}"]`)
                                .forEach(br => br.classList.add('wl-row--hidden'));
                        }
                    }
                });

                group.classList.toggle('wl-game-group--hidden', !groupVisible);
            });

            // Show/hide empty state message when filter hides everything.
            let noResultsEl = app.querySelector('.wl-filter-empty');
            const anyVisible = [...groups].some(g => !g.classList.contains('wl-game-group--hidden'));
            if (q && groups.length && !anyVisible) {
                if (!noResultsEl) {
                    noResultsEl = document.createElement('p');
                    noResultsEl.className = 'wl-filter-empty';
                    noResultsEl.textContent = 'No props match your search.';
                    app.appendChild(noResultsEl);
                }
            } else if (noResultsEl) {
                noResultsEl.remove();
            }
        }

        function bindEvents() {
            // Select / deselect for parlay building.
            app.querySelectorAll('.wl-select-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    // Book sub-row buttons use data-bkey; main row buttons use data-id.
                    const key = this.dataset.bkey != null ? String(this.dataset.bkey) : String(this.dataset.id);
                    if (selected.has(key)) {
                        selected.delete(key);
                        if (selected.size === 0) buildingParlay = false;
                    } else {
                        selected.add(key);
                        buildingParlay = true;
                    }
                    render();
                });
            });

            // Expand / collapse other books.
            app.querySelectorAll('.wl-expand-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const id = String(this.dataset.id);
                    if (expanded.has(id)) expanded.delete(id);
                    else expanded.add(id);
                    render();
                });
            });

            // Remove a prop.
            app.querySelectorAll('.wl-remove-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const id = String(this.dataset.id);
                    props = props.filter(p => String(p.id) !== id);
                    selected.delete(id);
                    render();
                    post('statsight_watchlist_remove', { id });
                });
            });

            // Clear all ended games from watchlist.
            document.getElementById('wl-clear-ended-btn')?.addEventListener('click', function () {
                const isEnded = p => gameStatuses[p.event_id] === 'final' || (p.result && p.result !== 'void');
                const endedIds = props.filter(isEnded).map(p => String(p.id));
                if (!endedIds.length) return;
                endedIds.forEach(id => {
                    selected.delete(id);
                    post('statsight_watchlist_remove', { id });
                });
                props = props.filter(p => !isEnded(p));
                render();
            });

            // Toggle parlay builder open/closed.
            document.getElementById('wl-build-parlay-btn')?.addEventListener('click', function () {
                buildingParlay = !buildingParlay;
                if (!buildingParlay) selected.clear();
                render();
            });

            // Cancel parlay builder.
            document.getElementById('wl-cancel-parlay-btn')?.addEventListener('click', function () {
                buildingParlay = false;
                selected.clear();
                render();
            });

            // Save parlay.
            document.getElementById('wl-save-parlay-btn')?.addEventListener('click', function () {
                const name = document.getElementById('wl-parlay-name')?.value.trim();
                if (!name) {
                    document.getElementById('wl-parlay-name')?.focus();
                    return;
                }
                if (selected.size < 2) {
                    alert('Select at least 2 props to build a collection.');
                    return;
                }
                // Resolve selected keys into leg objects.
                // Keys are either "propId" (main row) or "propId:bookName" (sub-row).
                const propMap = Object.fromEntries(props.map(p => [String(p.id), p]));
                const legs = [];
                for (const key of selected) {
                    const colonIdx = key.indexOf(':');
                    if (colonIdx === -1) {
                        // Main row — use the saved best book.
                        const p = propMap[key];
                        if (p) legs.push({ player: p.player, market_label: p.market_label, line: p.line, direction: p.direction, odds: p.odds, book: p.book, matchup: p.matchup });
                    } else {
                        // Sub-row — propId:bookName.
                        const propId  = key.slice(0, colonIdx);
                        const bookName = key.slice(colonIdx + 1);
                        const p = propMap[propId];
                        if (p) {
                            const allBooks = (() => { try { return JSON.parse(p.all_books || '[]'); } catch { return []; } })();
                            const entry = allBooks.find(b => b.book === bookName);
                            if (entry) legs.push({ player: p.player, market_label: p.market_label, line: p.line, direction: p.direction, odds: entry.odds, book: entry.book, matchup: p.matchup });
                        }
                    }
                }

                const btn      = this;
                const errorEl  = document.getElementById('wl-parlay-error');
                if (errorEl) errorEl.hidden = true;
                btn.disabled = true;
                post('statsight_parlay_save', {
                    name,
                    prop_ids: JSON.stringify([...selected]),
                    legs_json: JSON.stringify(legs),
                }).then(json => {
                    btn.disabled = false;
                    if (json.success) {
                        selected.clear();
                        buildingParlay = false;
                        loadAll();
                    } else if (errorEl) {
                        errorEl.textContent = json.data?.message || 'Could not save collection.';
                        errorEl.hidden = false;
                    }
                }).catch(() => {
                    btn.disabled = false;
                    if (errorEl) {
                        errorEl.textContent = 'Something went wrong. Please try again.';
                        errorEl.hidden = false;
                    }
                });
            });

            // Delete a parlay.
            app.querySelectorAll('.wl-parlay__delete').forEach(btn => {
                btn.addEventListener('click', function () {
                    const id = String(this.dataset.parlayId);
                    parlays = parlays.filter(p => String(p.id) !== id);
                    render();
                    post('statsight_parlay_delete', { id });
                });
            });
        }

        // ── Load ─────────────────────────────────────────────────────────────
        function loadAll() {
            Promise.all([
                post('statsight_watchlist_get', {}),
                post('statsight_parlay_get', {}),
            ]).then(([wlJson, parlayJson]) => {
                const rawProps  = wlJson.success ? wlJson.data.props         : [];
                const liveOdds  = wlJson.success ? (wlJson.data.live_odds    || {}) : {};
                underOddsMap    = wlJson.success ? (wlJson.data.under_odds   || {}) : {};
                oddsHistoryMap  = wlJson.success ? (wlJson.data.odds_history || {}) : {};
                parlays         = parlayJson.success ? parlayJson.data.parlays : [];

                // Patch each prop's odds and all_books with latest values from the history table.
                props = rawProps.map(p => {
                    const bookMap = liveOdds[p.id] || {};
                    if (!Object.keys(bookMap).length) return p;

                    const updatedP = { ...p };
                    if (bookMap[p.book] != null) updatedP.odds = bookMap[p.book];

                    const allBooks = (() => { try { return JSON.parse(p.all_books || '[]'); } catch { return []; } })();
                    if (allBooks.length) {
                        updatedP.all_books = JSON.stringify(
                            allBooks.map(b => bookMap[b.book] != null ? { ...b, odds: bookMap[b.book] } : b)
                        );
                    }

                    return updatedP;
                });

                // Render immediately with what we have, then fetch game statuses in the background.
                render();

                if (props.length) {
                    // Deduplicate by event_id — only need one entry per event.
                    const seen = {};
                    const statusPayload = props
                        .filter(p => { if (seen[p.event_id]) return false; seen[p.event_id] = true; return true; })
                        .map(p => ({ event_id: p.event_id, sport: p.sport, matchup: p.matchup, added_at: p.added_at, game_time: p.game_time ?? '' }));

                    post('statsight_watchlist_game_status', { props: JSON.stringify(statusPayload) })
                        .then(json => {
                            if (json.success) {
                                gameStatuses  = json.data.statuses  || {};
                                gameBoxscores = json.data.boxscores || {};
                                gameLogos     = json.data.logos     || {};
                                render();
                            }
                        })
                        .catch(() => {}); // non-critical — badges just won't show
                }
            }).catch(err => {
                app.innerHTML = `<p class="wl-error">Failed to load watchlist. Please refresh. (${err.message})</p>`;
            });
        }

        filterInput?.addEventListener('input', function () {
            filterQuery = this.value;
            applyFilter();
        });

        loadAll();

        // ── Tab switching ─────────────────────────────────────────────────────
        const tabs   = document.querySelectorAll('.wl-tab');
        const panels = document.querySelectorAll('[role="tabpanel"]');

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(t => { t.classList.remove('wl-tab--active'); t.setAttribute('aria-selected', 'false'); });
                panels.forEach(p => { p.hidden = true; });

                tab.classList.add('wl-tab--active');
                tab.setAttribute('aria-selected', 'true');
                const panel = document.getElementById(tab.getAttribute('aria-controls'));
                if (panel) panel.hidden = false;

                // Lazy-load history on first open.
                if (tab.id === 'wl-tab-history' && !historyLoaded) {
                    loadHistory();
                }
            });
        });

        // ── Prop history ──────────────────────────────────────────────────────
        const historyContainer = document.getElementById('wl-history-app');
        const SPORT_LABELS = {
            basketball_nba:         'NBA',
            americanfootball_nfl:   'NFL',
            baseball_mlb:           'MLB',
            icehockey_nhl:          'NHL',
            basketball_ncaab:       'NCAAB',
            americanfootball_ncaaf: 'NCAAF',
            mma_mixed_martial_arts: 'MMA',
        };

        let histOffset  = 0;
        let histLoading = false;

        function escH(s) {
            return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function fmtOddsH(o) {
            o = parseInt(o, 10);
            return isNaN(o) ? '—' : (o >= 0 ? '+' + o : String(o));
        }

        function fmtDate(iso) {
            if (!iso) return '—';
            const d = new Date(iso + (iso.includes('Z') ? '' : 'Z'));
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function histRowHtml(row) {
            const resultCls   = row.result === 'win' ? 'wl-result-badge--win'
                              : row.result === 'loss' ? 'wl-result-badge--loss'
                              : 'wl-result-badge--push';
            const resultLabel = row.result ? (row.result.charAt(0).toUpperCase() + row.result.slice(1)) : '—';
            const dir         = (row.direction || 'over').charAt(0).toUpperCase() + (row.direction || 'over').slice(1);
            const clv         = row.clv !== null && row.clv !== undefined && row.clv !== '' ? parseInt(row.clv, 10) : null;
            const clvHtml     = clv !== null
                ? `<span class="wl-clv wl-clv--${clv > 0 ? 'pos' : clv < 0 ? 'neg' : 'mid'}">${clv > 0 ? '+' : ''}${clv} CLV</span>`
                : '';
            const sport  = SPORT_LABELS[row.sport] || (row.sport || '').toUpperCase();
            const actual = row.actual_stat !== null && row.actual_stat !== undefined ? row.actual_stat : null;

            return `<tr>
                <td class="wl-hist-sport">${escH(sport)}</td>
                <td class="wl-hist-player"><strong>${escH(row.player)}</strong></td>
                <td class="wl-hist-market">${escH(row.market_label)}</td>
                <td class="wl-hist-line">${escH(row.line)} ${escH(dir)}</td>
                <td class="wl-hist-odds">${escH(fmtOddsH(row.odds))}</td>
                <td class="wl-hist-actual">${actual !== null ? escH(actual) : '—'}</td>
                <td class="wl-hist-result"><span class="wl-result-badge ${resultCls}">${escH(resultLabel)}</span></td>
                <td class="wl-hist-clv">${clvHtml || '—'}</td>
                <td class="wl-hist-date">${escH(fmtDate(row.game_start_time))}</td>
            </tr>`;
        }

        // ── Pick record breakdown ─────────────────────────────────────────────
        const breakdownContainer = document.getElementById('wl-breakdown-app');
        let breakdownLoaded = false;

        function fmtHitRate(r) {
            if (r === null || r === undefined) return '—';
            return Math.round(r * 100) + '%';
        }

        function fmtRoi(r) {
            if (r === null || r === undefined) return '—';
            return (r >= 0 ? '+' : '') + r.toFixed(1) + 'u';
        }

        function breakdownTableHtml(rows) {
            if (!rows || !rows.length) return '<p class="wl-empty" style="margin:0">Not enough data yet.</p>';
            return `<table class="wl-breakdown-table">
                <thead><tr>
                    <th></th>
                    <th>Record</th>
                    <th>Hit %</th>
                    <th>ROI/pick</th>
                </tr></thead>
                <tbody>
                    ${rows.map(r => {
                        const decidable = r.wins + r.losses;
                        const pct       = r.hit_rate !== null ? Math.round(r.hit_rate * 100) : null;
                        const roiCls    = r.roi === null ? '' : r.roi > 0 ? 'wl-breakdown-pos' : r.roi < 0 ? 'wl-breakdown-neg' : '';
                        const pctCls    = pct === null ? '' : pct >= 55 ? 'wl-breakdown-pos' : pct < 45 ? 'wl-breakdown-neg' : '';
                        return `<tr>
                            <td class="wl-breakdown-label">${escH(r.label)}</td>
                            <td class="wl-breakdown-record">${r.wins}–${r.losses}${r.pushes ? ' · ' + r.pushes + 'P' : ''}</td>
                            <td class="wl-breakdown-pct ${pctCls}">${fmtHitRate(r.hit_rate)}</td>
                            <td class="wl-breakdown-roi ${roiCls}">${fmtRoi(r.roi)}</td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>`;
        }

        function loadBreakdown() {
            if (breakdownLoaded || !breakdownContainer) return;
            post('statsight_get_pick_breakdown', {})
                .then(function (json) {
                    breakdownLoaded = true;
                    if (!json.success) { breakdownContainer.innerHTML = ''; return; }
                    const d = json.data;
                    const hasAny = ['sport','market','book','direction'].some(k => d[k] && d[k].length);
                    if (!hasAny) { breakdownContainer.innerHTML = ''; return; }

                    breakdownContainer.innerHTML = `
                        <div class="wl-breakdown">
                            <h3 class="wl-breakdown__title">Pick Record Breakdown</h3>
                            <div class="wl-breakdown__grid">
                                ${d.sport && d.sport.length ? `
                                <div class="wl-breakdown__card">
                                    <div class="wl-breakdown__card-label">By Sport</div>
                                    ${breakdownTableHtml(d.sport)}
                                </div>` : ''}
                                ${d.market && d.market.length ? `
                                <div class="wl-breakdown__card">
                                    <div class="wl-breakdown__card-label">By Market</div>
                                    ${breakdownTableHtml(d.market)}
                                </div>` : ''}
                                ${d.book && d.book.length ? `
                                <div class="wl-breakdown__card">
                                    <div class="wl-breakdown__card-label">By Book</div>
                                    ${breakdownTableHtml(d.book)}
                                </div>` : ''}
                                ${d.direction && d.direction.length ? `
                                <div class="wl-breakdown__card">
                                    <div class="wl-breakdown__card-label">Over / Under</div>
                                    ${breakdownTableHtml(d.direction)}
                                </div>` : ''}
                            </div>
                        </div>`;
                })
                .catch(function () {});
        }

        function loadHistory() {
            if (histLoading) return;
            histLoading = true;
            loadBreakdown();

            const loadMoreBtn = document.getElementById('wl-history-more-btn');
            if (loadMoreBtn) {
                loadMoreBtn.disabled    = true;
                loadMoreBtn.textContent = 'Loading…';
            }

            post('statsight_get_pick_history', { offset: histOffset })
                .then(function (json) {
                    histLoading   = false;
                    historyLoaded = true;

                    const rows = json.success ? json.data : [];

                    if (!histOffset && !rows.length) {
                        historyContainer.innerHTML = '<p class="wl-empty">No settled props yet. Props you save before game start will appear here once settled.</p>';
                        return;
                    }

                    // Build table scaffold on first load.
                    if (!histOffset) {
                        historyContainer.innerHTML = `
                            <div class="props-table-wrap wl-history-wrap">
                                <table class="props-table wl-table wl-history-table">
                                    <thead><tr>
                                        <th class="wl-hist-sport">Sport</th>
                                        <th class="wl-hist-player">Player / Prop</th>
                                        <th class="wl-hist-market">Market</th>
                                        <th class="wl-hist-line">Line</th>
                                        <th class="wl-hist-odds">Odds</th>
                                        <th class="wl-hist-actual">Actual</th>
                                        <th class="wl-hist-result">Result</th>
                                        <th class="wl-hist-clv">CLV</th>
                                        <th class="wl-hist-date">Date</th>
                                    </tr></thead>
                                    <tbody id="wl-history-tbody"></tbody>
                                </table>
                            </div>
                            <div class="wl-history-footer" id="wl-history-footer"></div>`;
                    }

                    const tbody = document.getElementById('wl-history-tbody');
                    rows.forEach(function (r) {
                        tbody.insertAdjacentHTML('beforeend', histRowHtml(r));
                    });

                    histOffset += rows.length;

                    const footer = document.getElementById('wl-history-footer');
                    if (rows.length === 20) {
                        footer.innerHTML = `<button class="community-load-more" id="wl-history-more-btn">Load More</button>`;
                        document.getElementById('wl-history-more-btn').addEventListener('click', loadHistory);
                    } else {
                        footer.innerHTML = histOffset > 0 ? '<p class="wl-history-end">All settled picks loaded.</p>' : '';
                    }
                })
                .catch(function () {
                    histLoading = false;
                    historyLoaded = true;
                    if (loadMoreBtn) { loadMoreBtn.disabled = false; loadMoreBtn.textContent = 'Load More'; }
                    if (!histOffset) historyContainer.innerHTML = '<p class="wl-empty">Failed to load history. Please refresh.</p>';
                });
        }

    }());
    </script>

    <?php endif; ?>

</div><!-- .wl-page -->

<?php get_footer(); ?>
