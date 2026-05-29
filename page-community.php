<?php
/**
 * Template Name: Community
 *
 * Public watchlist feed and user discovery.
 */

statsight_require_plan_redirect( 'sharp' );

get_header();

$plan            = statsight_get_user_plan();
$is_loggedin     = is_user_logged_in();
$viewer_id       = get_current_user_id();
$nonce           = wp_create_nonce( 'statsight_account' );
$profile_user_id = (int) get_query_var( 'community_user_id', 0 );
$community_url   = home_url( '/community/' );

// ── Profile view ──────────────────────────────────────────────────────────
if ( $profile_user_id ) :
    $profile_user   = get_userdata( $profile_user_id );
    $is_public        = $profile_user && get_user_meta( $profile_user_id, 'statsight_watchlist_public', true );
    $is_own_profile   = $viewer_id === $profile_user_id;
    $record_is_public = (bool) get_user_meta( $profile_user_id, 'statsight_record_public', true );
?>

<div class="community-page">
    <div class="community-header container">
        <a href="<?php echo esc_url( $community_url ); ?>" class="community-back">&larr; Community</a>
        <?php if ( ! $profile_user || ( ! $is_public && ! $is_own_profile ) ) : ?>
        <h1 class="community-title">Profile not found</h1>
        <p class="community-subtitle">This watchlist is private or does not exist.</p>
        <?php else : ?>
        <div class="community-profile-header">
            <span class="community-avatar community-avatar--lg"><?php echo esc_html( strtoupper( substr( $profile_user->display_name, 0, 1 ) ) ); ?></span>
            <div>
                <h1 class="community-title"><?php echo esc_html( $profile_user->display_name ); ?></h1>
                <div id="profile-pick-record"></div>
                <?php if ( $is_loggedin && ! $is_own_profile ) : ?>
                <button class="discover-card__follow-btn" id="profile-follow-btn" data-user-id="<?php echo esc_attr( $profile_user_id ); ?>">
                    Follow
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ( $profile_user && ( $is_public || $is_own_profile ) ) : ?>
    <div class="community-body container">
        <div class="community-tabs" id="profile-tabs">
            <button class="community-tab-btn is-active" data-tab="picks">Picks</button>
            <button class="community-tab-btn" data-tab="collections">Collections</button>
        </div>
        <div class="community-panel is-active" id="profile-tab-picks">
            <div id="profile-props">
                <p class="community-loading">Loading&hellip;</p>
            </div>
            <button class="community-load-more" id="profile-load-more" hidden>Load more</button>
        </div>
        <div class="community-panel" id="profile-tab-collections">
            <div id="profile-collections">
                <p class="community-loading">Loading&hellip;</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    'use strict';

    function escHtml(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    const ajaxUrl       = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
    const nonce         = <?php echo wp_json_encode( $nonce ); ?>;
    const profileUserId = <?php echo wp_json_encode( $profile_user_id ); ?>;
    const isLoggedIn    = <?php echo wp_json_encode( $is_loggedin ); ?>;
    const isOwnProfile  = <?php echo wp_json_encode( $is_own_profile ); ?>;
    const plan          = <?php echo wp_json_encode( $plan ); ?>;
    <?php if ( $is_loggedin && $plan === 'sharp' ) :
        global $wpdb;
        $wl_table  = $wpdb->prefix . 'statsight_watchlist';
        $my_saved  = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, event_id, player, market_key, direction FROM {$wl_table} WHERE user_id = %d AND deleted_at IS NULL",
            $viewer_id
        ), ARRAY_A );
        $saved_map = [];
        foreach ( $my_saved as $r ) {
            $key = $r['event_id'] . '|' . strtolower( $r['player'] ) . '|' . $r['market_key'] . '|' . $r['direction'];
            $saved_map[ $key ] = (int) $r['id'];
        }
    ?>
    const mySavedProps = new Map(<?php echo wp_json_encode( array_map( null, array_keys( $saved_map ), array_values( $saved_map ) ) ); ?>);
    const wlNonce      = <?php echo wp_json_encode( wp_create_nonce( 'statsight_events' ) ); ?>;
    <?php else : ?>
    const mySavedProps = new Map();
    const wlNonce      = '';
    <?php endif; ?>

    <?php if ( ! $profile_user || ( ! $is_public && ! $is_own_profile ) ) : ?>
    // Profile not available — nothing to load.
    <?php return; endif; ?>

    const propsEl       = document.getElementById('profile-props');
    const collectionsEl = document.getElementById('profile-collections');
    const loadMore      = document.getElementById('profile-load-more');
    let offset          = 0;
    let loading         = false;

    // ── Profile tab switching ─────────────────────────────────────────────
    function switchProfileTab(tab) {
        document.querySelectorAll('#profile-tabs .community-tab-btn').forEach(b => b.classList.toggle('is-active', b.dataset.tab === tab));
        document.querySelectorAll('#profile-tab-picks, #profile-tab-collections').forEach(p => p.classList.remove('is-active'));
        document.getElementById('profile-tab-' + tab).classList.add('is-active');
    }

    document.getElementById('profile-tabs').addEventListener('click', function (e) {
        const btn = e.target.closest('.community-tab-btn');
        if (!btn) return;
        switchProfileTab(btn.dataset.tab);
    });

    // Auto-switch to Collections tab if ?collection= is in the URL.
    if (new URLSearchParams(window.location.search).has('collection')) {
        switchProfileTab('collections');
    }

    function timeAgo(dateStr) {
        const diff = Math.floor((Date.now() - new Date(dateStr + 'Z').getTime()) / 1000);
        if (diff < 60)    return 'just now';
        if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    }

    function fmtOdds(n) {
        n = parseInt(n, 10);
        return isNaN(n) ? '—' : (n > 0 ? '+' + n : String(n));
    }

    function fmtDir(dir) {
        return dir === 'under' ? 'Under' : 'Over';
    }

    // Returns a tier label + CSS modifier based on hit rate, volume, and ROI.
    function pickerTier(record) {
        if (!record || record.hit_rate === null) return null;
        const rate       = record.hit_rate;
        const decidable  = record.wins + record.losses;
        const profitable = record.roi !== null && record.roi > 0;
        if (rate >= 0.60 && decidable >= 20 && profitable) return { label: 'Sharp',    mod: 'sharp' };
        if (rate >= 0.55 && decidable >= 10 && profitable) return { label: 'Solid',    mod: 'solid' };
        if (rate >= 0.50 && decidable >= 10)               return { label: 'Trending', mod: 'trending' };
        return { label: 'Rookie', mod: 'rookie' };
    }

    function pickRecordBadge(record) {
        if (!record || record.hit_rate === null) return '';
        const pct  = Math.round(record.hit_rate * 100);
        const tier = pickerTier(record);
        return `<span class="pick-record" title="${record.wins}W · ${record.losses}L · ${pct}% hit rate">
            <span class="pick-record__stat">${record.wins}W&ndash;${record.losses}L</span>
            <span class="pick-record__rate">${pct}%</span>
            ${tier ? `<span class="pick-record__tier pick-record__tier--${escHtml(tier.mod)}">${escHtml(tier.label)}</span>` : ''}
        </span>`;
    }

    const HEART_EMPTY  = `<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`;
    const HEART_FILLED = `<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`;

    function savedKey(row) {
        return `${row.event_id}|${(row.player || '').toLowerCase()}|${row.market_key}|${row.direction || 'over'}`;
    }

    function saveBtnHtml(row) {
        if (plan !== 'sharp' || isOwnProfile) return '';
        if (row.result) return '';
        const eligible = row.game_start_time && new Date(row.game_start_time + 'Z').getTime() > Date.now();
        if (!eligible) return '';
        const key   = savedKey(row);
        const wlId  = mySavedProps.get(key) ?? null;
        const saved = wlId !== null;
        return `<button class="feed-item__save-btn${saved ? ' is-saved' : ''}" title="${saved ? 'Remove from watchlist' : 'Save to my watchlist'}"
            data-event-id="${escHtml(row.event_id)}"
            data-sport="${escHtml(row.sport)}"
            data-player="${escHtml(row.player)}"
            data-market-key="${escHtml(row.market_key)}"
            data-market-label="${escHtml(row.market_label)}"
            data-line="${escHtml(row.line)}"
            data-direction="${escHtml(row.direction)}"
            data-odds="${escHtml(row.odds)}"
            data-book="${escHtml(row.book)}"
            data-matchup="${escHtml(row.matchup)}"
            data-all-books="${escHtml(row.all_books || '[]')}"
            data-wl-id="${wlId ?? ''}">${saved ? HEART_FILLED : HEART_EMPTY}</button>`;
    }

    function loadProps(off) {
        if (loading) return;
        loading = true;
        loadMore.disabled    = true;
        loadMore.textContent = 'Loading…';
        fetch(ajaxUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    new URLSearchParams({ action: 'statsight_community_profile', nonce, profile_user_id: profileUserId, offset: off }),
        })
            .then(r => r.json())
            .then(function (json) {
                loading              = false;
                loadMore.disabled    = false;
                loadMore.textContent = 'Load more';
                if (!json.success) {
                    propsEl.innerHTML = '<p class="community-loading">Failed to load.</p>';
                    return;
                }

                const { props, is_following, pick_record, collections } = json.data;

                // Set follow button state once we have data.
                const followBtn = document.getElementById('profile-follow-btn');
                if (followBtn && off === 0) {
                    followBtn.classList.toggle('is-following', is_following);
                    followBtn.textContent = is_following ? 'Following' : 'Follow';
                }

                // Inject pick record badge into profile header on first load.
                if (off === 0 && pick_record) {
                    const badgeEl = document.getElementById('profile-pick-record');
                    if (badgeEl) badgeEl.innerHTML = pickRecordBadge(pick_record);
                }

                // Render collections tab on first load.
                if (off === 0) {
                    propsEl.innerHTML = '';
                    if (collections && collections.length > 0) {
                        const section = document.createElement('div');
                        const shareIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>`;
                        const profileUrl = window.location.origin + window.location.pathname;

                        section.className = 'profile-collections';
                        section.innerHTML = collections.map(function (c) {
                            const legs = (c.legs || []).map(l =>
                                `<li class="profile-collection__leg">
                                    <span class="profile-collection__leg-player">${escHtml(l.player)}</span>
                                    <span class="profile-collection__leg-line">${fmtDir(l.direction)} ${escHtml(l.line)} ${escHtml(l.market_label)}</span>
                                    <span class="profile-collection__leg-odds">${fmtOdds(l.odds)}</span>
                                </li>`
                            ).join('');
                            return `<div class="profile-collection" data-collection-id="${escHtml(c.id)}">
                                <div class="profile-collection__header" role="button" tabindex="0" aria-expanded="false">
                                    <span class="profile-collection__name">${escHtml(c.name)}</span>
                                    <span class="profile-collection__count">${(c.legs || []).length} prop${(c.legs || []).length !== 1 ? 's' : ''}</span>
                                    <button class="profile-collection__share" title="Copy link" data-collection-id="${escHtml(c.id)}" aria-label="Copy shareable link">${shareIcon}</button>
                                    <span class="profile-collection__chevron" aria-hidden="true"></span>
                                </div>
                                <ul class="profile-collection__legs" hidden>${legs}</ul>
                            </div>`;
                        }).join('');
                        collectionsEl.innerHTML = '';
                        collectionsEl.appendChild(section);

                        function toggleCollection(header) {
                            const expanded = header.getAttribute('aria-expanded') === 'true';
                            header.setAttribute('aria-expanded', String(!expanded));
                            header.nextElementSibling.hidden = expanded;
                        }

                        section.addEventListener('click', function (e) {
                            // Share button — copy link, don't expand.
                            const shareBtn = e.target.closest('.profile-collection__share');
                            if (shareBtn) {
                                e.stopPropagation();
                                const url = profileUrl + '?collection=' + shareBtn.dataset.collectionId;
                                navigator.clipboard.writeText(url).then(function () {
                                    const orig = shareBtn.innerHTML;
                                    shareBtn.textContent = 'Copied!';
                                    setTimeout(function () { shareBtn.innerHTML = orig; }, 2000);
                                });
                                return;
                            }
                            const header = e.target.closest('.profile-collection__header');
                            if (header) toggleCollection(header);
                        });

                        section.addEventListener('keydown', function (e) {
                            if (e.key !== 'Enter' && e.key !== ' ') return;
                            const header = e.target.closest('.profile-collection__header');
                            if (header) { e.preventDefault(); toggleCollection(header); }
                        });

                        // Auto-open collection if ?collection= is in the URL.
                        const targetId = new URLSearchParams(window.location.search).get('collection');
                        if (targetId) {
                            const card = section.querySelector(`[data-collection-id="${CSS.escape(targetId)}"]`);
                            if (card) {
                                const header = card.querySelector('.profile-collection__header');
                                header.setAttribute('aria-expanded', 'true');
                                card.querySelector('.profile-collection__legs').hidden = false;
                                card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }
                        }
                    } else {
                        collectionsEl.innerHTML = '<div class="community-empty"><p>No collections yet.</p></div>';
                    }
                }

                if (props.length === 0) {
                    loadMore.hidden = true;
                    if (off === 0) {
                        propsEl.innerHTML = '<div class="community-empty"><p>No props on this watchlist yet.</p></div>';
                    }
                    return;
                }

                props.forEach(function (row) {
                    const item = document.createElement('div');
                    item.className = 'feed-item';
                    item.innerHTML = `
                        <div class="feed-item__prop" style="padding-left:0">
                            <span class="feed-item__player">${escHtml(row.player)}</span>
                            <span class="feed-item__market">${escHtml(row.market_label)}</span>
                            <span class="feed-item__line">${fmtDir(row.direction)} ${escHtml(row.line)}</span>
                            <span class="feed-item__odds">${fmtOdds(row.odds)}</span>
                            <span class="feed-item__book">${escHtml(row.book)}</span>
                            ${!isOwnProfile ? saveBtnHtml(row) : ''}
                        </div>
                        <div class="feed-item__matchup" style="padding-left:0">${escHtml(row.matchup)} &middot; ${timeAgo(row.added_at)}</div>`;
                    propsEl.appendChild(item);
                });

                offset = off + props.length;
                loadMore.hidden = props.length < 20;
            })
            .catch(function () {
                loading              = false;
                loadMore.disabled    = false;
                loadMore.textContent = 'Load more';
                propsEl.innerHTML = '<p class="community-loading">Failed to load.</p>';
            });
    }

    loadMore.addEventListener('click', function () { loadProps(offset); });
    loadProps(0);

    // ── Follow / Unfollow ─────────────────────────────────────────────────
    const followBtn = document.getElementById('profile-follow-btn');
    if (followBtn) {
        followBtn.addEventListener('click', function () {
            const isFollowing = followBtn.classList.contains('is-following');

            // Optimistic update — apply immediately, revert on failure
            followBtn.disabled = true;
            followBtn.classList.toggle('is-following', !isFollowing);
            followBtn.textContent = isFollowing ? 'Follow' : 'Following';

            fetch(ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ action: 'statsight_follow', nonce, user_id: profileUserId, action_type: isFollowing ? 'unfollow' : 'follow' }),
            })
                .then(r => r.json())
                .then(function (json) {
                    followBtn.disabled = false;
                    if (!json.success) {
                        // Revert on failure
                        followBtn.classList.toggle('is-following', isFollowing);
                        followBtn.textContent = isFollowing ? 'Following' : 'Follow';
                    }
                })
                .catch(function () {
                    // Revert on network error
                    followBtn.disabled = false;
                    followBtn.classList.toggle('is-following', isFollowing);
                    followBtn.textContent = isFollowing ? 'Following' : 'Follow';
                });
        });
    }

    // ── Save prop from profile page ───────────────────────────────────────
    if (plan === 'sharp' && !isOwnProfile) {
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.feed-item__save-btn');
            if (!btn || btn.disabled) return;

            const d       = btn.dataset;
            const key     = `${d.eventId}|${(d.player || '').toLowerCase()}|${d.marketKey}|${d.direction || 'over'}`;
            const isSaved = btn.classList.contains('is-saved');
            const wlId    = d.wlId ? parseInt(d.wlId, 10) : null;

            btn.disabled  = true;
            btn.classList.toggle('is-saved', !isSaved);
            btn.title     = isSaved ? 'Save to my watchlist' : 'Remove from watchlist';
            btn.innerHTML = isSaved ? HEART_EMPTY : HEART_FILLED;

            if (isSaved && wlId) {
                mySavedProps.delete(key);
                fetch(ajaxUrl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    new URLSearchParams({ action: 'statsight_watchlist_remove', nonce: wlNonce, id: wlId }),
                })
                    .then(r => r.json())
                    .then(function (json) {
                        btn.disabled = false;
                        if (!json.success) {
                            mySavedProps.set(key, wlId);
                            btn.classList.add('is-saved');
                            btn.title = 'Remove from watchlist';
                            btn.innerHTML = HEART_FILLED;
                            btn.dataset.wlId = wlId;
                        } else {
                            btn.dataset.wlId = '';
                        }
                    })
                    .catch(function () {
                        btn.disabled = false;
                        mySavedProps.set(key, wlId);
                        btn.classList.add('is-saved');
                        btn.title = 'Remove from watchlist';
                        btn.innerHTML = HEART_FILLED;
                        btn.dataset.wlId = wlId;
                    });
            } else {
                fetch(ajaxUrl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    new URLSearchParams({
                        action:       'statsight_watchlist_add',
                        nonce:        wlNonce,
                        event_id:     d.eventId,
                        sport:        d.sport,
                        player:       d.player,
                        market_key:   d.marketKey,
                        market_label: d.marketLabel,
                        line:         d.line,
                        direction:    d.direction,
                        odds:         d.odds,
                        book:         d.book,
                        matchup:      d.matchup,
                        all_books:    d.allBooks,
                    }),
                })
                    .then(r => r.json())
                    .then(function (json) {
                        btn.disabled = false;
                        if (json.success) {
                            const newId = json.data.id;
                            mySavedProps.set(key, newId);
                            btn.dataset.wlId = newId;
                        } else {
                            mySavedProps.delete(key);
                            btn.classList.remove('is-saved');
                            btn.title = 'Save to my watchlist';
                            btn.innerHTML = HEART_EMPTY;
                        }
                    })
                    .catch(function () {
                        btn.disabled = false;
                        mySavedProps.delete(key);
                        btn.classList.remove('is-saved');
                        btn.title = 'Save to my watchlist';
                        btn.innerHTML = HEART_EMPTY;
                    });
            }
        });
    }

}());
</script>

<?php
// ── Community list (tabs) view ─────────────────────────────────────────────
else :
?>

<div class="community-page">

    <div class="community-header container">
        <h1 class="community-title">Community</h1>
        <p class="community-subtitle">See what other bettors are tracking and follow the sharpest watchlists.</p>
    </div>

    <div class="community-body container">

        <?php if ( ! $is_loggedin ) : ?>
        <div class="community-gate">
            <p>Please <a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>">log in</a> to view the community.</p>
        </div>
        <?php else : ?>

        <!-- Tabs -->
        <div class="community-tabs">
            <button class="community-tab-btn is-active" data-tab="discover">Discover</button>
            <button class="community-tab-btn" data-tab="following">Following</button>
            <button class="community-tab-btn" data-tab="leaderboard">Leaderboard</button>
        </div>

        <!-- Following tab -->
        <div class="community-panel" id="community-panel-following">
            <?php if ( $plan !== 'sharp' ) : ?>
            <div class="community-gate">
                <p>The Following feed is available on the <strong>Sharp</strong> plan.</p>
                <a href="<?php echo esc_url( home_url( '/#pricing' ) ); ?>" class="acct-btn acct-btn--primary">Upgrade</a>
            </div>
            <?php else : ?>
            <div id="following-feed">
                <p class="community-loading">Loading&hellip;</p>
            </div>
            <button class="community-load-more" id="following-load-more" hidden>Load more</button>
            <?php endif; ?>
        </div>

        <!-- Discover tab -->
        <div class="community-panel is-active" id="community-panel-discover">
            <div class="community-search">
                <input
                    type="search"
                    class="community-search__input"
                    id="discover-search"
                    placeholder="Search by name or username&hellip;"
                    autocomplete="off"
                >
            </div>
            <div id="discover-list">
                <p class="community-loading">Loading&hellip;</p>
            </div>
        </div>

        <!-- Leaderboard tab -->
        <div class="community-panel" id="community-panel-leaderboard">
            <div id="leaderboard-list">
                <p class="community-loading">Loading&hellip;</p>
            </div>
        </div>

        <?php endif; ?>

    </div>
</div>

<script>
(function () {
    'use strict';

    function escHtml(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function pickerTier(record) {
        if (!record || record.hit_rate === null) return null;
        const rate       = record.hit_rate;
        const decidable  = record.wins + record.losses;
        const profitable = record.roi !== null && record.roi > 0;
        if (rate >= 0.60 && decidable >= 20 && profitable) return { label: 'Sharp',    mod: 'sharp' };
        if (rate >= 0.55 && decidable >= 10 && profitable) return { label: 'Solid',    mod: 'solid' };
        if (rate >= 0.50 && decidable >= 10)               return { label: 'Trending', mod: 'trending' };
        return { label: 'Rookie', mod: 'rookie' };
    }

    function pickRecordBadge(record) {
        if (!record || record.hit_rate === null) return '';
        const pct  = Math.round(record.hit_rate * 100);
        const tier = pickerTier(record);
        return `<span class="pick-record" title="${record.wins}W · ${record.losses}L · ${pct}% hit rate">
            <span class="pick-record__stat">${record.wins}W&ndash;${record.losses}L</span>
            <span class="pick-record__rate">${pct}%</span>
            ${tier ? `<span class="pick-record__tier pick-record__tier--${escHtml(tier.mod)}">${escHtml(tier.label)}</span>` : ''}
        </span>`;
    }

    const ajaxUrl    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
    const nonce      = <?php echo wp_json_encode( $nonce ); ?>;
    const plan       = <?php echo wp_json_encode( $plan ); ?>;
    const myAcctUrl  = <?php echo wp_json_encode( wc_get_page_permalink( 'myaccount' ) ); ?>;
    const profileBase = <?php echo wp_json_encode( home_url( '/community/user/' ) ); ?>;

    <?php if ( $is_loggedin && $plan === 'sharp' ) :
        global $wpdb;
        $wl_table = $wpdb->prefix . 'statsight_watchlist';
        $my_saved = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, event_id, player, market_key, direction FROM {$wl_table} WHERE user_id = %d",
            $viewer_id
        ), ARRAY_A );
        // Map: "event_id|player|market_key|direction" => watchlist row id
        $saved_map = [];
        foreach ( $my_saved as $r ) {
            $key = $r['event_id'] . '|' . strtolower( $r['player'] ) . '|' . $r['market_key'] . '|' . $r['direction'];
            $saved_map[ $key ] = (int) $r['id'];
        }
    ?>
    // Map of props already in the current user's watchlist: "event_id|player|market_key|direction" => watchlist id
    const mySavedProps = new Map(<?php echo wp_json_encode( array_map( null, array_keys( $saved_map ), array_values( $saved_map ) ) ); ?>);
    <?php else : ?>
    const mySavedProps = new Map();
    <?php endif; ?>

    <?php if ( ! $is_loggedin ) : ?>
    return;
    <?php endif; ?>

    // ── Tab switching ─────────────────────────────────────────────────────────
    const tabBtns = document.querySelectorAll('.community-tab-btn');
    const panels  = document.querySelectorAll('.community-panel');
    let leaderboardLoaded = false;

    tabBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            tabBtns.forEach(b => b.classList.remove('is-active'));
            panels.forEach(p => p.classList.remove('is-active'));
            btn.classList.add('is-active');
            document.getElementById('community-panel-' + btn.dataset.tab).classList.add('is-active');
            if (btn.dataset.tab === 'leaderboard' && !leaderboardLoaded) {
                leaderboardLoaded = true;
                loadLeaderboard();
            }
        });
    });

    // ── Helpers ───────────────────────────────────────────────────────────────
    function timeAgo(dateStr) {
        const diff = Math.floor((Date.now() - new Date(dateStr + 'Z').getTime()) / 1000);
        if (diff < 60)    return 'just now';
        if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    }

    function avatar(name) {
        return `<span class="community-avatar">${escHtml(String(name || '?')[0].toUpperCase())}</span>`;
    }

    function fmtOdds(n) {
        n = parseInt(n, 10);
        return isNaN(n) ? '—' : (n > 0 ? '+' + n : String(n));
    }

    function fmtDir(dir) {
        return dir === 'under' ? 'Under' : 'Over';
    }

    function savedKey(row) {
        return `${row.event_id}|${(row.player || '').toLowerCase()}|${row.market_key}|${row.direction || 'over'}`;
    }

    const HEART_EMPTY  = `<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`;
    const HEART_FILLED = `<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`;

    function triggerHeartPop(btn) {
        btn.classList.remove('feed-item__save-btn--pop');
        void btn.offsetWidth; // force reflow so animation restarts
        btn.classList.add('feed-item__save-btn--pop');
        btn.addEventListener('animationend', () => btn.classList.remove('feed-item__save-btn--pop'), { once: true });
    }

    function saveBtnHtml(row) {
        if (row.result) return '';
        const eligible = row.game_start_time && new Date(row.game_start_time + 'Z').getTime() > Date.now();
        if (!eligible) return '';
        const key     = savedKey(row);
        const wlId    = mySavedProps.get(key) ?? null;
        const saved   = wlId !== null;
        return `<button class="feed-item__save-btn${saved ? ' is-saved' : ''}" title="${saved ? 'Remove from watchlist' : 'Save to my watchlist'}"
            data-event-id="${escHtml(row.event_id)}"
            data-sport="${escHtml(row.sport)}"
            data-player="${escHtml(row.player)}"
            data-market-key="${escHtml(row.market_key)}"
            data-market-label="${escHtml(row.market_label)}"
            data-line="${escHtml(row.line)}"
            data-direction="${escHtml(row.direction)}"
            data-odds="${escHtml(row.odds)}"
            data-book="${escHtml(row.book)}"
            data-matchup="${escHtml(row.matchup)}"
            data-all-books="${escHtml(row.all_books || '[]')}"
            data-wl-id="${wlId ?? ''}">${saved ? HEART_FILLED : HEART_EMPTY}</button>`;
    }

    // ── Following feed ────────────────────────────────────────────────────────
    <?php if ( $plan === 'sharp' ) : ?>
    const feedEl    = document.getElementById('following-feed');
    const loadMore  = document.getElementById('following-load-more');
    let   feedOffset  = 0;
    let   feedLoading = false;

    function loadFeed(offset) {
        if (feedLoading) return;
        feedLoading          = true;
        loadMore.disabled    = true;
        loadMore.textContent = 'Loading…';
        fetch(ajaxUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    new URLSearchParams({ action: 'statsight_community_feed', nonce, offset }),
        })
            .then(r => r.json())
            .then(function (json) {
                feedLoading          = false;
                loadMore.disabled    = false;
                loadMore.textContent = 'Load more';
                if (!json.success) return;
                const rows         = json.data.rows ?? [];
                const pickRecords  = json.data.pick_records ?? {};

                if (rows.length === 0) {
                    loadMore.hidden = true;
                    if (offset === 0) {
                        feedEl.innerHTML = `
                            <div class="community-empty">
                                <p>You're not following anyone yet.</p>
                                <p>Head to <button class="community-inline-tab-btn" data-tab="discover">Discover</button> to find public watchlists to follow.</p>
                            </div>`;
                        feedEl.querySelector('.community-inline-tab-btn')?.addEventListener('click', function () {
                            document.querySelector('.community-tab-btn[data-tab="discover"]')?.click();
                        });
                    }
                    return;
                }

                if (offset === 0) feedEl.innerHTML = '';

                rows.forEach(function (row) {
                    const record = pickRecords[row.user_id] ?? null;
                    const item = document.createElement('div');
                    item.className = 'feed-item';
                    item.innerHTML = `
                        <div class="feed-item__user">
                            <a href="${escHtml(profileBase + row.user_id + '/')}" class="feed-item__user-link">
                                ${avatar(row.display_name)}
                                <span class="feed-item__name">${escHtml(row.display_name)}</span>
                            </a>
                            ${pickRecordBadge(record)}
                            <span class="feed-item__time">${timeAgo(row.added_at)}</span>
                        </div>
                        <div class="feed-item__prop">
                            <span class="feed-item__player">${escHtml(row.player)}</span>
                            <span class="feed-item__market">${escHtml(row.market_label)}</span>
                            <span class="feed-item__line">${fmtDir(row.direction)} ${escHtml(row.line)}</span>
                            <span class="feed-item__odds">${fmtOdds(row.odds)}</span>
                            <span class="feed-item__book">${escHtml(row.book)}</span>
                            ${saveBtnHtml(row)}
                        </div>
                        <div class="feed-item__matchup">${escHtml(row.matchup)}</div>`;
                    feedEl.appendChild(item);
                });

                feedOffset = offset + rows.length;
                loadMore.hidden = rows.length < 20;
            })
            .catch(function () {
                feedLoading          = false;
                loadMore.disabled    = false;
                loadMore.textContent = 'Load more';
            });
    }

    loadMore.addEventListener('click', function () { loadFeed(feedOffset); });
    loadFeed(0);
    <?php endif; ?>

    // ── Discover ──────────────────────────────────────────────────────────────
    const discoverEl  = document.getElementById('discover-list');
    const searchInput = document.getElementById('discover-search');
    let   allUsers    = [];

    function renderDiscover(users) {
        if (users.length === 0) {
            discoverEl.innerHTML = `
                <div class="community-empty">
                    <p>No results found.</p>
                </div>`;
            return;
        }

        discoverEl.innerHTML = '';
        users.forEach(function (u) {
            const card = document.createElement('div');
            card.className = 'discover-card';
            card.dataset.userId = u.user_id;

            card.innerHTML = `
                <a href="${escHtml(profileBase + u.user_id + '/')}" class="discover-card__left">
                    ${avatar(u.display_name)}
                    <div class="discover-card__info">
                        <span class="discover-card__name">${escHtml(u.display_name)}</span>
                        <span class="discover-card__meta">${u.watchlist_count} prop${u.watchlist_count === 1 ? '' : 's'} tracked</span>
                        ${pickRecordBadge(u.pick_record)}
                    </div>
                </a>
                <button class="discover-card__follow-btn${u.is_following ? ' is-following' : ''}" data-user-id="${escHtml(u.user_id)}">
                    ${u.is_following ? 'Following' : 'Follow'}
                </button>`;

            discoverEl.appendChild(card);
        });
    }

    function loadDiscover() {
        fetch(ajaxUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    new URLSearchParams({ action: 'statsight_community_discover', nonce }),
        })
            .then(r => r.json())
            .then(function (json) {
                if (!json.success) return;
                allUsers = json.data;

                if (allUsers.length === 0) {
                    discoverEl.innerHTML = `
                        <div class="community-empty">
                            <p>No public watchlists yet.</p>
                            <p>Be the first — enable yours in <a href="${escHtml(myAcctUrl)}">Account Settings</a>.</p>
                        </div>`;
                    return;
                }

                renderDiscover(allUsers);
            })
            .catch(function () {
                discoverEl.innerHTML = '<p class="community-loading">Failed to load.</p>';
            });
    }

    let searchTimer = null;

    searchInput.addEventListener('input', function () {
        const q = searchInput.value.trim();
        clearTimeout(searchTimer);

        if (!q) {
            renderDiscover(allUsers);
            return;
        }

        if (q.length < 2) return;

        searchTimer = setTimeout(function () {
            discoverEl.innerHTML = '<p class="community-loading">Searching&hellip;</p>';
            fetch(ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ action: 'statsight_community_search', nonce, q }),
            })
                .then(r => r.json())
                .then(function (json) {
                    if (!json.success) return;
                    if (json.data.length === 0) {
                        discoverEl.innerHTML = '<div class="community-empty"><p>No users found.</p></div>';
                        return;
                    }
                    renderDiscover(json.data);
                })
                .catch(function () {
                    discoverEl.innerHTML = '<p class="community-loading">Search failed.</p>';
                });
        }, 350);
    });

    discoverEl.addEventListener('click', function (e) {
        const btn = e.target.closest('.discover-card__follow-btn');
        if (!btn) return;

        const userId      = btn.dataset.userId;
        const isFollowing = btn.classList.contains('is-following');
        const actionType  = isFollowing ? 'unfollow' : 'follow';

        // Optimistic update — apply immediately, revert on failure
        btn.disabled = true;
        btn.classList.toggle('is-following', !isFollowing);
        btn.textContent = isFollowing ? 'Follow' : 'Following';

        fetch(ajaxUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    new URLSearchParams({ action: 'statsight_follow', nonce, user_id: userId, action_type: actionType }),
        })
            .then(r => r.json())
            .then(function (json) {
                btn.disabled = false;
                if (!json.success) {
                    btn.classList.toggle('is-following', isFollowing);
                    btn.textContent = isFollowing ? 'Following' : 'Follow';
                    return;
                }
                // Update local cache so search re-renders correctly.
                const user = allUsers.find(u => String(u.user_id) === String(userId));
                if (user) user.is_following = !isFollowing;
            })
            .catch(function () {
                btn.disabled = false;
                btn.classList.toggle('is-following', isFollowing);
                btn.textContent = isFollowing ? 'Following' : 'Follow';
            });
    });

    loadDiscover();

    // ── Leaderboard ───────────────────────────────────────────────────────────
    function loadLeaderboard() {
        const el = document.getElementById('leaderboard-list');
        fetch(ajaxUrl + '?' + new URLSearchParams({
            action: 'statsight_get_leaderboard',
            nonce:  <?php echo wp_json_encode( wp_create_nonce( 'statsight_events' ) ); ?>,
        }))
            .then(function (r) { return r.text(); })
            .then(function (text) {
                let json;
                try { json = JSON.parse(text); } catch (e) {
                    el.innerHTML = '<div class="community-empty"><p>No one on the leaderboard yet — at least 10 settled picks required to qualify.</p></div>';
                    return;
                }
                if (!json.success) {
                    el.innerHTML = '<div class="community-empty"><p>No one on the leaderboard yet — at least 10 settled picks required to qualify.</p></div>';
                    return;
                }
                if (json.data.length === 0) {
                    el.innerHTML = '<div class="community-empty"><p>No one on the leaderboard yet — it requires at least 10 settled picks with a public record. Be the first!</p></div>';
                    return;
                }

                const rows = json.data.map(function (u, i) {
                    const pct  = Math.round(u.hit_rate * 100);
                    const tier = pickerTier(u);
                    const rank = i + 1;
                    const rankClass = rank === 1 ? 'leaderboard-row--gold'
                                    : rank === 2 ? 'leaderboard-row--silver'
                                    : rank === 3 ? 'leaderboard-row--bronze'
                                    : '';
                    return `
                        <a href="${escHtml(profileBase + u.user_id + '/')}" class="leaderboard-row ${rankClass}">
                            <span class="leaderboard-row__rank">${rank}</span>
                            <span class="leaderboard-row__user">
                                <span class="community-avatar">${escHtml(u.display_name.charAt(0).toUpperCase())}</span>
                                <span class="leaderboard-row__name">${escHtml(u.display_name)}</span>
                            </span>
                            ${tier ? `<span class="pick-record__tier pick-record__tier--${escHtml(tier.mod)}">${escHtml(tier.label)}</span>` : '<span></span>'}
                            <span class="leaderboard-row__record">${u.wins}W&ndash;${u.losses}L</span>
                            <span class="leaderboard-row__rate">${pct}%</span>
                        </a>`;
                }).join('');

                el.innerHTML = `
                    <div class="leaderboard-header">
                        <span>Rank</span><span>Picker</span><span>Tier</span>
                        <span>Record</span><span>Hit %</span>
                    </div>
                    <div class="leaderboard-rows">${rows}</div>
                    <p class="leaderboard-note">Minimum 10 settled picks (saved before game start) required to appear.</p>`;
            })
            .catch(function () {
                el.innerHTML = '<div class="community-empty"><p>Couldn\'t load the leaderboard right now — try refreshing.</p></div>';
            });
    }

    // ── Save prop to own watchlist ────────────────────────────────────────────
    // Delegated from the whole page so it catches both feed and profile items.
    <?php if ( $plan === 'sharp' ) : ?>
    const wlNonce = <?php echo wp_json_encode( wp_create_nonce( 'statsight_events' ) ); ?>;

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.feed-item__save-btn');
        if (!btn || btn.disabled) return;

        const d       = btn.dataset;
        const key     = `${d.eventId}|${(d.player || '').toLowerCase()}|${d.marketKey}|${d.direction || 'over'}`;
        const isSaved = btn.classList.contains('is-saved');
        const wlId    = d.wlId ? parseInt(d.wlId, 10) : null;

        // Optimistic update — flip state immediately.
        btn.disabled = true;
        btn.classList.toggle('is-saved', !isSaved);
        btn.title     = isSaved ? 'Save to my watchlist' : 'Remove from watchlist';
        btn.innerHTML = isSaved ? HEART_EMPTY : HEART_FILLED;
        if (!isSaved) triggerHeartPop(btn);

        if (isSaved && wlId) {
            // Remove from watchlist.
            mySavedProps.delete(key);
            fetch(ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ action: 'statsight_watchlist_remove', nonce: wlNonce, id: wlId }),
            })
                .then(r => r.json())
                .then(function (json) {
                    btn.disabled = false;
                    if (!json.success) {
                        // Revert.
                        mySavedProps.set(key, wlId);
                        btn.classList.add('is-saved');
                        btn.title = 'Remove from watchlist';
                        btn.innerHTML = HEART_FILLED;
                        btn.dataset.wlId = wlId;
                    } else {
                        btn.dataset.wlId = '';
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    mySavedProps.set(key, wlId);
                    btn.classList.add('is-saved');
                    btn.title = 'Remove from watchlist';
                    btn.innerHTML = HEART_FILLED;
                    btn.dataset.wlId = wlId;
                });
        } else {
            // Save to watchlist.
            fetch(ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({
                    action:       'statsight_watchlist_add',
                    nonce:        wlNonce,
                    event_id:     d.eventId,
                    sport:        d.sport,
                    player:       d.player,
                    market_key:   d.marketKey,
                    market_label: d.marketLabel,
                    line:         d.line,
                    direction:    d.direction,
                    odds:         d.odds,
                    book:         d.book,
                    matchup:      d.matchup,
                    all_books:    d.allBooks,
                }),
            })
                .then(r => r.json())
                .then(function (json) {
                    btn.disabled = false;
                    if (json.success) {
                        const newId = json.data.id;
                        mySavedProps.set(key, newId);
                        btn.dataset.wlId = newId;
                    } else {
                        // Revert.
                        mySavedProps.delete(key);
                        btn.classList.remove('is-saved');
                        btn.title = 'Save to my watchlist';
                        btn.innerHTML = HEART_EMPTY;
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    mySavedProps.delete(key);
                    btn.classList.remove('is-saved');
                    btn.title = 'Save to my watchlist';
                    btn.innerHTML = HEART_EMPTY;
                });
        }
    });
    <?php endif; ?>

}());
</script>

<?php endif; ?>

<?php get_footer(); ?>
