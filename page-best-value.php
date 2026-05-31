<?php
/**
 * Template Name: Best Value
 *
 * Full-page best value finder — shows the highest-edge props across all
 * active sports, with sport filter tabs, sort options, and auto-refresh.
 */

statsight_require_plan_redirect( 'pro' );

get_header();

$result = statsight_get_sports();
$sports = $result['sports'];
?>

<div class="bv-page">

    <!-- ── Page Header ──────────────────────────────────────────────────────── -->
    <div class="bv-page-header">
        <div>
            <h1 class="bv-page-title">&#9889; Best Value</h1>
            <p class="bv-page-subtitle">Highest edges across all books today &mdash; updated every 5 minutes.</p>
        </div>
        <div class="bv-page-header__right">
            <span class="bv-refresh-status" id="bv-refresh-status"></span>
            <button class="bv-refresh-btn" id="bv-refresh-btn" title="Refresh data">&#x21BB; Refresh</button>
        </div>
    </div>

    <?php if ( empty( $sports ) ) : ?>
        <div class="empty-state">
            <div class="empty-state__icon">&#x26A0;</div>
            <p class="empty-state__title">No sports available</p>
        </div>
    <?php else : ?>

        <!-- ── Sport Filter Tabs ────────────────────────────────────────────── -->
        <div class="bv-sport-tabs" role="tablist" aria-label="Filter by sport">
            <button class="bv-sport-tab is-active" data-sport="all" role="tab" aria-selected="true">All Sports</button>
            <?php foreach ( $sports as $sport ) : ?>
                <button
                    class="bv-sport-tab"
                    data-sport="<?php echo esc_attr( $sport['key'] ); ?>"
                    role="tab"
                    aria-selected="false"
                >
                    <?php echo esc_html( $sport['title'] ); ?>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- ── Controls ─────────────────────────────────────────────────────── -->
        <div class="bv-controls-wrap">
            <div class="bv-controls-toolbar">
                <input class="bv-search-input" type="text" id="bv-search" placeholder="Search player, matchup, market&hellip;">
                <button class="bv-filter-toggle" id="bv-filter-toggle" aria-expanded="false" aria-controls="bv-filter-drawer">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="11" y1="18" x2="13" y2="18"/></svg>
                    Filters
                    <span class="bv-filter-count" id="bv-filter-count" hidden>0</span>
                </button>
            </div>
            <div class="bv-filter-drawer" id="bv-filter-drawer">
                <div class="bv-controls">
                    <div class="bv-control-group">
                        <label class="bv-control-label" for="bv-min-edge">Min edge</label>
                        <div class="bv-number-input-wrap">
                            <input type="number" class="bv-number-input" id="bv-min-edge" value="100" min="0" max="2000" step="25">
                            <span class="bv-number-unit">pts</span>
                        </div>
                    </div>
                    <div class="bv-control-group">
                        <label class="bv-control-label" for="bv-min-odds">Min odds</label>
                        <div class="bv-number-input-wrap">
                            <input type="number" class="bv-number-input" id="bv-min-odds" value="-1000" min="-1000" max="500" step="25">
                            <span class="bv-number-unit">pts</span>
                        </div>
                    </div>
                    <div class="bv-control-group">
                        <label class="bv-control-label" for="bv-max-odds">Max odds</label>
                        <div class="bv-number-input-wrap">
                            <input type="number" class="bv-number-input" id="bv-max-odds" value="1000" min="-500" max="2000" step="25">
                            <span class="bv-number-unit">pts</span>
                        </div>
                    </div>
                    <div class="bv-control-group">
                        <label class="bv-control-label" for="bv-sort">Sort by</label>
                        <select class="bv-sort-select" id="bv-sort">
                            <option value="edge">Edge (highest first)</option>
                            <option value="best_odds">Best odds</option>
                            <option value="sport">Sport</option>
                        </select>
                    </div>
                    <div class="bv-control-group">
                        <label class="bv-control-label" for="bv-book-filter">Sportsbook</label>
                        <select class="bv-sort-select" id="bv-book-filter">
                            <option value="">All books</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Results ──────────────────────────────────────────────────────── -->
        <div class="bv-results-meta" id="bv-results-meta" hidden>
            <span id="bv-results-count"></span>
        </div>

        <div class="bv-list" id="bv-list">
            <div class="empty-state empty-state--loading">
                <p class="empty-state__title">Loading&hellip;</p>
            </div>
        </div>

    <?php endif; ?>
</div><!-- .bv-page -->

<?php
$active_books_raw = get_user_meta( get_current_user_id(), 'statsight_active_books', true );
$active_books     = ( $active_books_raw && is_string( $active_books_raw ) )
    ? json_decode( $active_books_raw, true )
    : null;
?>
<script>
var statsightAjax = {
    url:         <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
    nonce:       <?php echo wp_json_encode( wp_create_nonce( 'statsight_events' ) ); ?>,
    plan:        <?php echo wp_json_encode( statsight_get_user_plan() ); ?>,
    homeUrl:     <?php echo wp_json_encode( home_url( '/#pricing' ) ); ?>,
    activeBooks: <?php echo wp_json_encode( $active_books ); ?>
};
var bvSports = <?php echo wp_json_encode( array_map( fn( $s ) => [ 'key' => $s['key'], 'title' => $s['title'] ], $sports ) ); ?>;
</script>

<script>
(function () {
    'use strict';

    // ── State ──────────────────────────────────────────────────────────────
    let allRows      = [];   // merged results across all sports
    let sportFilter  = 'all';
    let bookFilter   = '';   // empty = all books
    let minEdge      = 100;
    let minLowestOdds = -1000; // hide props where every book's odds are below this value
    let maxLowestOdds = 1000;  // hide props where no book has odds <= this value
    let sortBy       = 'edge';
    let searchQuery  = '';
    let loadedSports = {};   // sportKey -> rows (null while loading)
    let lastUpdated  = null;
    let refreshTimer = null;
    // "player|marketKey|eventId|line" → watchlist row id
    const watchlistMap = {};

    const listEl          = document.getElementById('bv-list');
    const metaEl          = document.getElementById('bv-results-meta');
    const countEl         = document.getElementById('bv-results-count');
    const minEdgeInput    = document.getElementById('bv-min-edge');
    const minOddsInput    = document.getElementById('bv-min-odds');
    const maxOddsInput    = document.getElementById('bv-max-odds');
    const sortSelect      = document.getElementById('bv-sort');
    const bookSelect      = document.getElementById('bv-book-filter');
    const searchInput     = document.getElementById('bv-search');
    const refreshBtn      = document.getElementById('bv-refresh-btn');
    const refreshStatus   = document.getElementById('bv-refresh-status');
    const filterToggle    = document.getElementById('bv-filter-toggle');
    const filterDrawer    = document.getElementById('bv-filter-drawer');
    const filterCount     = document.getElementById('bv-filter-count');

    // ── Helpers ────────────────────────────────────────────────────────────
    function escHtml(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function fmtOdds(val) {
        const n = parseInt(val, 10);
        return isNaN(n) ? '—' : (n >= 0 ? '+' + n : String(n));
    }

    function fmtBook(key) {
        if (!key) return '—';
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

    function formatTime(iso) {
        if (!iso) return '';
        return new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    }

    function sportTitle(key) {
        const s = bvSports.find(s => s.key === key);
        return s ? s.title : key;
    }

    function updateRefreshStatus() {
        if (!lastUpdated) { refreshStatus.textContent = ''; return; }
        const mins = Math.round((Date.now() - lastUpdated) / 60000);
        refreshStatus.textContent = mins < 1 ? 'Updated just now' : `Updated ${mins}m ago`;
    }

    // Update the filter count badge on the toggle button
    function updateFilterCount() {
        let count = 0;
        if (minEdge !== 100) count++;
        if (minLowestOdds !== -500) count++;
        if (maxLowestOdds !== 500) count++;
        if (sortBy !== 'edge') count++;
        if (bookFilter !== '') count++;
        filterCount.textContent = count;
        filterCount.hidden = count === 0;
        filterToggle.classList.toggle('has-filters', count > 0);
    }

    // Heart SVGs — defined at module scope so both renderList and the click handler can use them.
    const heartEmpty  = `<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`;
    const heartFilled = `<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`;

    // ── Data loading ───────────────────────────────────────────────────────
    function fetchSport(sportKey) {
        const params = new URLSearchParams({
            action: 'statsight_get_best_value',
            nonce:  statsightAjax.nonce,
            sport:  sportKey,
        });

        return fetch(statsightAjax.url + '?' + params.toString())
            .then(r => r.json())
            .then(function (json) {
                if (!json.success) {
                    if (json.data?.plan_required) {
                        return { planRequired: true };
                    }
                    return [];
                }
                // Tag each row with sport key for filtering/display
                return (json.data || []).map(row => ({ ...row, sport: sportKey }));
            })
            .catch(() => []);
    }

    function loadAll(forceRefresh) {
        refreshBtn.disabled = true;
        refreshBtn.textContent = '↻ Loading…';

        if (forceRefresh) {
            loadedSports = {};
            allRows = [];
        }

        // Show loading state
        listEl.innerHTML = `<div class="empty-state empty-state--loading"><p class="empty-state__title">Loading&hellip;</p></div>`;
        metaEl.hidden = true;

        const sportKeys = bvSports.map(s => s.key);
        const fetches   = sportKeys.map(key => fetchSport(key));

        Promise.all(fetches).then(function (results) {
            // Check if any result signals a plan requirement
            const planBlocked = results.some(r => r && !Array.isArray(r) && r.planRequired);
            if (planBlocked) {
                refreshBtn.disabled = false;
                refreshBtn.textContent = '↻ Refresh';
                listEl.innerHTML = `<div class="bv-upgrade-prompt">
                    <div class="bv-upgrade-prompt__icon">&#9889;</div>
                    <p class="bv-upgrade-prompt__title">Pro feature</p>
                    <p class="bv-upgrade-prompt__desc">Best Value is available on the Pro plan and above. Upgrade to see the highest-edge props across all books in real time.</p>
                    <a href="${escHtml(statsightAjax.homeUrl)}" class="modal-upgrade-btn">View plans &rarr;</a>
                </div>`;
                metaEl.hidden = true;
                return;
            }

            allRows = results.flat();
            lastUpdated = Date.now();
            updateRefreshStatus();
            refreshBtn.disabled = false;
            refreshBtn.textContent = '↻ Refresh';
            populateBookFilter();
            renderList();

            // Schedule next auto-refresh at 5 minutes
            clearTimeout(refreshTimer);
            refreshTimer = setTimeout(() => loadAll(true), 5 * 60 * 1000);
        });
    }

    // ── Book filter dropdown ───────────────────────────────────────────────
    function populateBookFilter() {
        const current     = bookSelect.value;
        const activeBooks = statsightAjax.activeBooks;
        const books = [...new Set(
            allRows.flatMap(r => (r.all_odds || []).map(o => o.book).filter(Boolean))
        )]
        .filter(b => !activeBooks || activeBooks.includes(b))
        .sort((a, b) => fmtBook(a).localeCompare(fmtBook(b)));

        bookSelect.innerHTML = '<option value="">All books</option>' +
            books.map(b => `<option value="${b}"${b === current ? ' selected' : ''}>${fmtBook(b)}</option>`).join('');
    }

    // ── Rendering ──────────────────────────────────────────────────────────
    function getVisible() {
        let rows = allRows;

        // Sport filter
        if (sportFilter !== 'all') {
            rows = rows.filter(r => r.sport === sportFilter);
        }

        // Book filter — only show rows where the selected book has the highest odds.
        if (bookFilter) {
            rows = rows.filter(function (r) {
                const odds = (r.all_odds || []).filter(o => o.odds !== null);
                const bookOdds = odds.find(o => o.book === bookFilter);
                if (!bookOdds) return false;
                const maxOdds = Math.max(...odds.map(o => o.odds));
                return bookOdds.odds === maxOdds;
            });
        }

        // Odds range filters: operate on visible books, fall back to all books.
        rows = rows.filter(function (r) {
            const activeBooks = statsightAjax.activeBooks;
            const visOdds = (r.all_odds || []).filter(o => o.odds !== null && (!activeBooks || activeBooks.includes(o.book)));
            const odds = visOdds.length > 0 ? visOdds : (r.all_odds || []).filter(o => o.odds !== null);
            // Max: at least one book must be at or below the ceiling.
            if (!odds.some(o => o.odds <= maxLowestOdds)) return false;
            // Min: at least one book must be at or above the floor.
            if (!odds.some(o => o.odds >= minLowestOdds)) return false;
            return true;
        });

        // Compute display edge and determine whether the favorable side is on a selected book.
        const activeBooks = statsightAjax.activeBooks;
        rows = rows.map(function (r) {
            const allOdds = (r.all_odds || []).filter(o => o.odds !== null);
            const visOdds = allOdds.filter(o => !activeBooks || activeBooks.includes(o.book));
            const oddsVals = allOdds.map(o => o.odds);
            const dispEdge = oddsVals.length >= 2 ? Math.max(...oddsVals) - Math.min(...oddsVals) : 0;
            // The favorable bet is the highest odds (most +). Check if it's on a selected book.
            const bestOdds = oddsVals.length > 0 ? Math.max(...oddsVals) : null;
            const bestIsVisible = bestOdds !== null && visOdds.some(o => o.odds === bestOdds);
            return { ...r, _displayEdge: dispEdge, _bestIsVisible: bestIsVisible };
        });

        // Hide rows where the user can't actually place the favorable bet (best odds not on a selected book).
        if (activeBooks) {
            rows = rows.filter(r => r._bestIsVisible);
        }

        // Re-apply min edge against the filtered edge.
        rows = rows.filter(r => r._displayEdge >= minEdge);

        // Search
        if (searchQuery) {
            const q = searchQuery.toLowerCase();
            rows = rows.filter(r =>
                (r.player  || '').toLowerCase().includes(q) ||
                (r.matchup || '').toLowerCase().includes(q) ||
                (r.market  || '').toLowerCase().includes(q)
            );
        }

        // Sort
        rows = [...rows].sort(function (a, b) {
            if (sortBy === 'edge')      return b._displayEdge - a._displayEdge;
            if (sortBy === 'best_odds') return (b.best_odds ?? -Infinity) - (a.best_odds ?? -Infinity);
            if (sortBy === 'sport')     return (a.sport || '').localeCompare(b.sport || '');
            return 0;
        });

        return rows;
    }

    const RENDER_LIMIT = 250;

    function renderList() {
        const rows    = getVisible();
        const visible = rows.slice(0, RENDER_LIMIT);

        countEl.textContent = rows.length > RENDER_LIMIT
            ? `Showing ${RENDER_LIMIT} of ${rows.length} props`
            : `${rows.length} prop${rows.length !== 1 ? 's' : ''}`;
        metaEl.hidden = false;

        if (rows.length === 0) {
            listEl.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state__icon">&#x1F50D;</div>
                    <p class="empty-state__title">No edges found</p>
                    <p class="empty-state__desc">Try lowering the minimum edge or broadening your search.</p>
                </div>`;
            return;
        }

        listEl.innerHTML = visible.map(function (r) {
            // Filter to user's selected books; fall back to all if filter leaves none.
            const activeBooks  = statsightAjax.activeBooks;
            const filteredOdds = (r.all_odds || []).filter(o => o.odds !== null && (!activeBooks || activeBooks.includes(o.book)));
            const visibleOdds  = filteredOdds.length > 0 ? filteredOdds : (r.all_odds || []).filter(o => o.odds !== null);

            // Recompute best book within visible set.
            let bestKey = '', bestOddsVal = -Infinity;
            visibleOdds.forEach(o => { if (o.odds > bestOddsVal) { bestOddsVal = o.odds; bestKey = o.book; } });

            const allOdds = visibleOdds
                .map(o => `<span class="bv-odds-chip${o.book === bestKey ? ' bv-odds-chip--best' : ''}">
                    ${escHtml(fmtBook(o.book))} <strong>${escHtml(fmtOdds(o.odds))}</strong>
                </span>`)
                .join('');

            const timeStr   = r.game_time ? formatTime(r.game_time) : '';
            const sport     = sportTitle(r.sport);
            const wlKey     = `${r.player}|${r.market_key}|${r.event_id}|${r.line}`;
            const isWatched = watchlistMap[wlKey] != null;
            const allBooksJson = JSON.stringify(
                visibleOdds.map(o => ({ book: o.book, odds: o.odds }))
            ).replace(/"/g, '&quot;');
            const watchBtn = `<button
                class="track-bet-btn${isWatched ? ' track-bet-btn--watching' : ''}"
                aria-label="${isWatched ? 'Remove from watchlist' : 'Add to watchlist'}"
                title="${isWatched ? 'Remove from watchlist' : 'Add to watchlist'}"
                data-player="${escHtml(r.player)}"
                data-market-key="${escHtml(r.market_key)}"
                data-market-label="${escHtml(r.market)}"
                data-event-id="${escHtml(r.event_id)}"
                data-sport="${escHtml(r.sport)}"
                data-line="${escHtml(r.line)}"
                data-matchup="${escHtml(r.matchup)}"
                data-game-time="${escHtml(r.game_time ?? '')}"
                data-best-odds="${escHtml(String(bestOddsVal !== -Infinity ? bestOddsVal : ''))}"
                data-best-book="${escHtml(bestKey)}"
                data-all-books="${allBooksJson}"
            >${isWatched ? heartFilled : heartEmpty}</button>`;

            return `
                <div class="bv-row" data-edge="${r._displayEdge}">
                    <div class="bv-row__meta">
                        <div class="bv-row__top">
                            <span class="bv-row__player">${escHtml(r.player)}</span>
                            <span class="bv-row__sport-tag">${escHtml(sport)}</span>
                        </div>
                        <span class="bv-row__market">${escHtml(r.market)}${r.line !== 'Yes' ? ' &mdash; Over ' + escHtml(r.line) : ''}</span>
                        <span class="bv-row__matchup">${escHtml(r.matchup)}${timeStr ? ' &bull; ' + escHtml(timeStr) : ''}</span>
                    </div>
                    <div class="bv-row__odds">${allOdds}</div>
                    <div class="bv-row__edge">
                        <span class="bv-edge-badge">+${r._displayEdge}</span>
                        <span class="bv-edge-label">edge</span>
                        ${r.ev != null ? `<span class="bv-ev-badge${r.ev > 0 ? ' bv-ev-badge--pos' : ''}" title="Expected Value">${r.ev > 0 ? '+' : ''}${r.ev}% EV</span>` : ''}
                    </div>
                    <div class="bv-row__track">${watchBtn}</div>
                </div>`;
        }).join('');
    }

    // ── Sport tabs ─────────────────────────────────────────────────────────
    document.querySelectorAll('.bv-sport-tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.bv-sport-tab').forEach(b => {
                b.classList.remove('is-active');
                b.setAttribute('aria-selected', 'false');
            });
            btn.classList.add('is-active');
            btn.setAttribute('aria-selected', 'true');
            sportFilter = btn.dataset.sport;
            renderList();
        });
    });

    // ── Filter drawer toggle ───────────────────────────────────────────────
    filterToggle.addEventListener('click', function () {
        const expanded = filterToggle.getAttribute('aria-expanded') === 'true';
        filterToggle.setAttribute('aria-expanded', String(!expanded));
        filterDrawer.classList.toggle('is-open', !expanded);
    });

    // ── Controls ───────────────────────────────────────────────────────────
    minEdgeInput.addEventListener('input', function () {
        minEdge = parseFloat(minEdgeInput.value) || 0;
        updateFilterCount();
        renderList();
    });

    minOddsInput.addEventListener('input', function () {
        minLowestOdds = parseFloat(minOddsInput.value) ?? -1000;
        updateFilterCount();
        renderList();
    });

    maxOddsInput.addEventListener('input', function () {
        maxLowestOdds = parseFloat(maxOddsInput.value) ?? 1000;
        updateFilterCount();
        renderList();
    });

    bookSelect.addEventListener('change', function () {
        bookFilter = bookSelect.value;
        updateFilterCount();
        renderList();
    });

    sortSelect.addEventListener('change', function () {
        sortBy = sortSelect.value;
        updateFilterCount();
        renderList();
    });

    searchInput.addEventListener('input', function () {
        searchQuery = searchInput.value.trim();
        renderList();
    });

    // ── Refresh ────────────────────────────────────────────────────────────
    refreshBtn.addEventListener('click', function () { loadAll(true); });

    setInterval(updateRefreshStatus, 30000);

    // ── Watchlist handlers ─────────────────────────────────────────────────
    listEl.addEventListener('click', function (e) {
        const btn = e.target.closest('.track-bet-btn');
        if (!btn) return;

        if (statsightAjax.plan === 'free') {
            window.location.href = statsightAjax.homeUrl;
            return;
        }

        const player     = btn.dataset.player;
        const marketKey  = btn.dataset.marketKey;
        const marketLabel = btn.dataset.marketLabel;
        const eventId    = btn.dataset.eventId;
        const sport      = btn.dataset.sport;
        const line       = btn.dataset.line;
        const matchup    = btn.dataset.matchup;
        const gameTime   = btn.dataset.gameTime;
        const bestOdds   = btn.dataset.bestOdds;
        const bestBook   = btn.dataset.bestBook;
        const allBooks   = btn.dataset.allBooks;
        const wlKey      = `${player}|${marketKey}|${eventId}|${line}`;
        const isWatched  = watchlistMap[wlKey] != null;

        if (isWatched) {
            const wlId = watchlistMap[wlKey];
            delete watchlistMap[wlKey];
            btn.classList.remove('track-bet-btn--watching');
            btn.innerHTML = heartEmpty;
            btn.setAttribute('aria-label', 'Add to watchlist');
            btn.title = 'Add to watchlist';

            fetch(statsightAjax.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'statsight_watchlist_remove', nonce: statsightAjax.nonce, id: wlId }),
            });
        } else {
            btn.classList.add('track-bet-btn--watching');
            btn.innerHTML = heartFilled;
            btn.setAttribute('aria-label', 'Remove from watchlist');
            btn.title = 'Remove from watchlist';

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
                    line:         line,
                    direction:    'over',
                    odds:         bestOdds,
                    book:         bestBook,
                    matchup:      matchup,
                    game_start_time: gameTime,
                    all_books:    allBooks,
                }),
            })
            .then(r => r.json())
            .then(function (json) {
                if (json.success && json.data?.id) {
                    watchlistMap[wlKey] = json.data.id;
                }
            });
        }
    });

    // ── Init ───────────────────────────────────────────────────────────────
    loadAll(false);

}());
</script>

<?php get_footer(); ?>

