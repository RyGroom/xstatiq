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
                            <input type="number" class="bv-number-input" id="bv-min-edge" value="1" min="0" max="20" step="0.5">
                            <span class="bv-number-unit">%</span>
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
                            <option value="ev">EV% (highest first)</option>
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
$active_books = statsight_get_active_books( get_current_user_id() );
?>
<script>
var statsightAjax = {
    url:         <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
    nonce:       <?php echo wp_json_encode( wp_create_nonce( 'statsight_events' ) ); ?>,
    plan:        <?php echo wp_json_encode( statsight_get_user_plan() ); ?>,
    homeUrl:     <?php echo wp_json_encode( home_url( '/#pricing' ) ); ?>,
    activeBooks: <?php echo wp_json_encode( $active_books ); ?>,
    bookOrder:   <?php echo wp_json_encode( statsight_get_book_order() ); ?>
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
    const DEFAULT_MIN_EDGE = 1;   // percentage points vs the no-vig consensus
    let minEdge      = DEFAULT_MIN_EDGE;
    let minLowestOdds = -1000; // hide props where every book's odds are below this value
    let maxLowestOdds = 1000;  // hide props where no book has odds <= this value
    let sortBy       = 'edge';
    let searchQuery  = '';
    let loadedSports = {};   // sportKey -> rows (null while loading)
    let lastUpdated  = null;
    let refreshTimer = null;
    // "player|marketKey|eventId|line" → watchlist row id
    const watchlistMap = {};
    let staleHidden = 0;     // rows dropped by the stale-line check in the last getVisible()

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
        const map = <?php echo wp_json_encode( statsight_get_book_labels() ); ?>;
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
        if (minEdge !== DEFAULT_MIN_EDGE) count++;
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
        // Each prop becomes an over row and an under row. _odds holds that side's price per book
        // (price) plus the book's opposite price (other), used to remove its margin.
        let rows = allRows.flatMap(r => ['over', 'under'].map(side => ({
            ...r,
            side,
            _odds: (r.all_odds || [])
                .map(o => ({ book: o.book, price: side === 'over' ? o.odds : o.under, other: side === 'over' ? o.under : o.odds }))
                .filter(o => o.price != null),
        })));

        // Sport filter
        if (sportFilter !== 'all') {
            rows = rows.filter(r => r.sport === sportFilter);
        }

        // Only the user's selected books count, for display and for edge/EV.
        const activeBooks = statsightAjax.activeBooks;
        rows = rows.map(r => ({ ...r, _odds: r._odds.filter(o => !activeBooks || activeBooks.includes(o.book)) }));

        // Book filter — only show rows where the selected book has the best price on that side.
        if (bookFilter) {
            rows = rows.filter(function (r) {
                const bookOdds = r._odds.find(o => o.book === bookFilter);
                return bookOdds && bookOdds.price === Math.max(...r._odds.map(o => o.price));
            });
        }

        // Odds range filters.
        rows = rows.filter(function (r) {
            // Max: at least one book must be at or below the ceiling.
            if (!r._odds.some(o => o.price <= maxLowestOdds)) return false;
            // Min: at least one book must be at or above the floor.
            if (!r._odds.some(o => o.price >= minLowestOdds)) return false;
            return true;
        });

        // Edge/EV vs the no-vig consensus; needs at least two selected books on this side.
        rows = rows.map(function (r) {
            const value = calcValue(r._odds);
            return value && { ...r, _displayEdge: value.edge, _ev: value.ev, _fair: value.fair, _bestOdds: value.bestOdds, _bestBook: value.bestBook, _stale: value.stale };
        }).filter(Boolean);

        // Drop likely stale/mismatched lines (counted so the results line can say so).
        rows = rows.filter(r => r._displayEdge >= minEdge);
        staleHidden = rows.filter(r => r._stale).length;
        rows = rows.filter(r => !r._stale);

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
            if (sortBy === 'ev')        return (b._ev ?? -Infinity) - (a._ev ?? -Infinity);
            if (sortBy === 'best_odds') return b._bestOdds - a._bestOdds;
            if (sortBy === 'sport')     return (a.sport || '').localeCompare(b.sport || '');
            return 0;
        });

        return rows;
    }

    /**
     * Value of the best price on one side against the no-vig consensus. JS mirror of
     * statsight_calc_value() in functions.php — keep the two in sync.
     * odds: [{ book, price (this side), other (opposite side, may be null) }].
     * Returns { edge (pp), ev (%), fair (prob), bestOdds, bestBook, stale } or null with < 2 books.
     */
    const ONE_SIDED_MARGIN = 1.07;
    const STALE_RATIO      = 0.5;  // best price implying < half the consensus prob = stale/mismatched line
    function calcValue(odds) {
        if (odds.length < 2) return null;
        const impl   = o => o > 0 ? 100 / (o + 100) : Math.abs(o) / (Math.abs(o) + 100);
        const median = xs => {
            const s = [...xs].sort((a, b) => a - b), n = s.length;
            return n % 2 ? s[(n - 1) / 2] : (s[n / 2 - 1] + s[n / 2]) / 2;
        };
        const margins = odds.filter(o => o.other != null).map(o => impl(o.price) + impl(o.other));
        const oneSided = margins.length ? median(margins) : ONE_SIDED_MARGIN;
        const best = odds.reduce((a, b) => (b.price > a.price ? b : a));
        const fair = median(odds.filter(o => o !== best).map(o =>
            impl(o.price) / (o.other != null ? impl(o.price) + impl(o.other) : oneSided)));
        const decimal = best.price > 0 ? 1 + best.price / 100 : 1 + 100 / Math.abs(best.price);
        return {
            edge:     Math.round((fair - impl(best.price)) * 10000) / 100,
            ev:       Math.round((fair * decimal - 1) * 10000) / 100,
            fair:     fair,
            bestOdds: best.price,
            bestBook: best.book,
            stale:    impl(best.price) < STALE_RATIO * fair,
        };
    }

    /** Probability (0–1) to American odds, e.g. 0.45 => +122. */
    function probToAmerican(p) {
        return p >= 0.5 ? -Math.round(100 * p / (1 - p)) : Math.round(100 * (1 - p) / p);
    }

    const RENDER_LIMIT = 250;

    function renderList() {
        const rows    = getVisible();
        const visible = rows.slice(0, RENDER_LIMIT);

        countEl.textContent = (rows.length > RENDER_LIMIT
            ? `Showing ${RENDER_LIMIT} of ${rows.length} props`
            : `${rows.length} prop${rows.length !== 1 ? 's' : ''}`)
            + (staleHidden ? ` · ${staleHidden} hidden as likely stale lines` : '');
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

        listEl.innerHTML = tableMedia.matches ? renderTable(visible) : visible.map(renderCard).join('');
    }

    // Desktop shows a table with a column per sportsbook (like the props page); mobile keeps cards.
    const tableMedia = window.matchMedia('(min-width: 901px)');
    tableMedia.addEventListener('change', () => { if (allRows.length) renderList(); });

    /** Markup shared by the card and table layouts for one row. */
    function rowParts(r) {
        // Yes/No markets: over = Yes, under = No.
        const sideLabel = r.line === 'Yes'
            ? (r.side === 'over' ? '' : ' &mdash; No')
            : ` &mdash; ${r.side === 'over' ? 'Over' : 'Under'} ${escHtml(r.line)}`;

        const timeStr   = r.game_time ? formatTime(r.game_time) : '';
        const wlKey     = `${r.player}|${r.market_key}|${r.event_id}|${r.line}|${r.side}`;
        const isWatched = watchlistMap[wlKey] != null;
        const allBooksJson = JSON.stringify(
            r._odds.map(o => ({ book: o.book, odds: o.price }))
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
            data-side="${r.side}"
            data-matchup="${escHtml(r.matchup)}"
            data-game-time="${escHtml(r.game_time ?? '')}"
            data-best-odds="${escHtml(String(r._bestOdds))}"
            data-best-book="${escHtml(r._bestBook)}"
            data-all-books="${allBooksJson}"
        >${isWatched ? heartFilled : heartEmpty}</button>`;

        return {
            sport:   escHtml(sportTitle(r.sport)),
            market:  escHtml(r.market) + sideLabel,
            matchup: escHtml(r.matchup) + (timeStr ? ' &bull; ' + escHtml(timeStr) : ''),
            edge:    `${r._displayEdge > 0 ? '+' : ''}${r._displayEdge.toFixed(1)}%`,
            ev:      `${r._ev > 0 ? '+' : ''}${r._ev.toFixed(1)}%`,
            fair:    escHtml(fmtOdds(probToAmerican(r._fair))),
            watchBtn,
        };
    }

    const EDGE_TITLE = "Fair (no-vig) probability minus the best price's implied probability";
    const EV_TITLE   = 'Expected Value at the best price';
    const FAIR_TITLE = 'No-vig consensus price across your books (excluding the best book)';

    function renderCard(r) {
        const p = rowParts(r);
        const allOdds = r._odds
            .map(o => `<span class="bv-odds-chip${o.price === r._bestOdds ? ' bv-odds-chip--best' : ''}">
                ${escHtml(fmtBook(o.book))} <strong>${escHtml(fmtOdds(o.price))}</strong>
            </span>`)
            .join('');

        return `
            <div class="bv-row" data-edge="${r._displayEdge}">
                <div class="bv-row__meta">
                    <div class="bv-row__top">
                        <span class="bv-row__player">${escHtml(r.player)}</span>
                        <span class="bv-row__sport-tag">${p.sport}</span>
                    </div>
                    <span class="bv-row__market">${p.market}</span>
                    <span class="bv-row__matchup">${p.matchup}</span>
                </div>
                <div class="bv-row__odds">${allOdds}</div>
                <div class="bv-row__edge">
                    <span class="bv-edge-badge" title="${EDGE_TITLE}">${p.edge}</span>
                    <span class="bv-edge-label">edge</span>
                    <span class="bv-ev-badge${r._ev > 0 ? ' bv-ev-badge--pos' : ''}" title="${EV_TITLE}">${p.ev} EV</span>
                    <span class="bv-fair" title="${FAIR_TITLE}">Fair ${p.fair}</span>
                </div>
                <div class="bv-row__track">${p.watchBtn}</div>
            </div>`;
    }

    /**
     * Book columns: every book with a price in these rows (already limited to the user's selected
     * books), in the site's book order, so there are no empty columns.
     */
    function tableBooks(rows) {
        const rank = bk => { const i = statsightAjax.bookOrder.indexOf(bk); return i === -1 ? Infinity : i; };
        return [...new Set(rows.flatMap(r => r._odds.map(o => o.book)))]
            .sort((a, b) => rank(a) - rank(b) || fmtBook(a).localeCompare(fmtBook(b)));
    }

    function renderTable(rows) {
        const books = tableBooks(rows);
        const sortTh = (key, label, title) =>
            `<th class="bv-col-num bv-th-sort${sortBy === key ? ' bv-th-sort--active' : ''}" data-sort="${key}" title="${title}">${label}${sortBy === key ? ' &#9662;' : ''}</th>`;

        const body = rows.map(function (r) {
            const p = rowParts(r);
            const prices = Object.fromEntries(r._odds.map(o => [o.book, o.price]));
            const bestBooks = r._odds.filter(o => o.price === r._bestOdds).map(o => escHtml(fmtBook(o.book))).join(', ');
            const cells = books.map(function (bk) {
                const v = prices[bk];
                if (v == null) return '<td class="odds-cell odds-na">&mdash;</td>';
                const best = v === r._bestOdds;
                return `<td class="odds-cell${best ? ' odds-best' : ''}"><span class="${best ? 'odds-badge--best' : ''}">${escHtml(fmtOdds(v))}</span></td>`;
            }).join('');

            return `
                <tr>
                    <td class="bv-col-prop"><div class="bv-prop">
                        <div class="bv-row__top">
                            <span class="bv-row__player">${escHtml(r.player)}</span>
                            <span class="bv-row__sport-tag">${p.sport}</span>
                        </div>
                        <span class="bv-row__market">${p.market}</span>
                        <span class="bv-row__matchup">${p.matchup}</span>
                    </div></td>
                    <td class="bv-col-num"><span class="bv-edge-badge bv-edge-badge--table">${p.edge}</span></td>
                    <td class="bv-col-num"><span class="bv-ev-badge${r._ev > 0 ? ' bv-ev-badge--pos' : ''}">${p.ev}</span></td>
                    <td class="bv-col-num bv-fair">${p.fair}</td>
                    <td class="bv-col-best"><div class="odds-badge--best">${escHtml(fmtOdds(r._bestOdds))}</div><div class="bv-best-book">${bestBooks}</div></td>
                    <td class="bv-col-track">${p.watchBtn}</td>
                    ${cells}
                </tr>`;
        }).join('');

        return `
            <div class="props-table-wrap bv-table-wrap">
                <table class="props-table props-table--stepper bv-table">
                    <thead>
                        <tr>
                            <th>Prop</th>
                            ${sortTh('edge', 'Edge', EDGE_TITLE)}
                            ${sortTh('ev', 'EV', EV_TITLE)}
                            <th class="bv-col-num" title="${FAIR_TITLE}">Fair</th>
                            <th>Best</th>
                            <th aria-label="Watchlist"></th>
                            ${books.map(bk => `<th class="bv-col-book">${escHtml(fmtBook(bk))}</th>`).join('')}
                        </tr>
                    </thead>
                    <tbody>${body}</tbody>
                </table>
            </div>`;
    }

    // Edge / EV column headers sort the table (kept in sync with the Sort by dropdown).
    listEl.addEventListener('click', function (e) {
        const th = e.target.closest('.bv-th-sort');
        if (!th) return;
        sortBy = th.dataset.sort;
        sortSelect.value = sortBy;
        updateFilterCount();
        renderList();
    });

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
        const side       = btn.dataset.side;
        const wlKey      = `${player}|${marketKey}|${eventId}|${line}|${side}`;
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
                    direction:    side,
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

