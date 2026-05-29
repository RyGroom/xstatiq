<?php
/**
 * Front page template — marketing landing page.
 * Covers: hero, feature highlights, live teaser, pricing, footer CTA.
 */

get_header();
?>

<div class="lp">

    <!-- ── Hero ──────────────────────────────────────────────────────────── -->
    <section class="lp-hero">
        <div class="lp-hero__bg" aria-hidden="true">
            <div class="lp-hero__glow lp-hero__glow--1"></div>
            <div class="lp-hero__glow lp-hero__glow--2"></div>
        </div>
        <div class="container lp-hero__inner">
            <p class="lp-eyebrow">Sports prop intelligence</p>
            <h1 class="lp-hero__headline">Find the <em>edge</em><br>before the line moves.</h1>
            <p class="lp-hero__sub">xstatiq tracks odds from FanDuel, DraftKings, Caesars, Fanatics &amp; more — surfacing the best-value player props in real time so you can bet with confidence.</p>
            <div class="lp-hero__ctas">
                <a href="<?php echo esc_url( home_url( '/props/' ) ); ?>" class="lp-btn lp-btn--primary">Explore Props &rarr;</a>
                <a href="#pricing" class="lp-btn lp-btn--ghost">See plans</a>
            </div>
            <div class="lp-hero__proof">
                <span class="lp-proof-chip">10+ sportsbooks</span>
                <span class="lp-proof-chip">Updated every minute</span>
                <span class="lp-proof-chip">MLB &bull; NBA &bull; NHL &bull; NFL &bull; MMA</span>
            </div>
        </div>
    </section>

    <!-- ── Feature Highlights ────────────────────────────────────────────── -->
    <section class="lp-features">
        <div class="container">
            <h2 class="lp-section-title lp-animate">Everything you need to shop lines smarter</h2>
            <div class="lp-features__grid lp-animate-group">

                <div class="lp-feature-card lp-animate-child">
                    <div class="lp-feature-card__icon">&#9889;</div>
                    <h3 class="lp-feature-card__title">Best Value Finder</h3>
                    <p class="lp-feature-card__desc">We calculate the edge between the best and worst book for every prop. Filter by sport, set your minimum edge, and instantly see where the market is mispriced.</p>
                </div>

                <div class="lp-feature-card lp-animate-child">
                    <div class="lp-feature-card__icon">&#128200;</div>
                    <h3 class="lp-feature-card__title">Odds History &amp; Trends</h3>
                    <p class="lp-feature-card__desc">See how a line has moved since open. Sharp money moves lines — our trend charts show you whether the market is drifting toward or away from value.</p>
                </div>

                <div class="lp-feature-card lp-animate-child">
                    <div class="lp-feature-card__icon">&#128101;</div>
                    <h3 class="lp-feature-card__title">Player Intelligence</h3>
                    <p class="lp-feature-card__desc">Season stats, last 10 game averages, hit rates at the current line, back-to-back flags, injury status, and opponent defensive rankings — all in one click.</p>
                </div>

                <div class="lp-feature-card lp-animate-child">
                    <div class="lp-feature-card__icon">&#128155;</div>
                    <h3 class="lp-feature-card__title">Watchlist</h3>
                    <p class="lp-feature-card__desc">Save props from any game with one click. Track your picks across all sports, build parlays, and share your watchlist with the community.</p>
                </div>

                <div class="lp-feature-card lp-animate-child">
                    <div class="lp-feature-card__icon">&#127942;</div>
                    <h3 class="lp-feature-card__title">Roster &amp; Injury Reports</h3>
                    <p class="lp-feature-card__desc">Click any team to pull the full roster with headshots, positions, and real-time injury designations sourced directly from ESPN.</p>
                </div>

                <div class="lp-feature-card lp-animate-child">
                    <div class="lp-feature-card__icon">&#128277;</div>
                    <h3 class="lp-feature-card__title">Multi-Book Coverage</h3>
                    <p class="lp-feature-card__desc">Side-by-side odds across all major US sportsbooks on a single screen. No more tab-switching — the best line is always visible at a glance.</p>
                </div>

            </div>
        </div>
    </section>

    <!-- ── Live Teaser ───────────────────────────────────────────────────── -->
    <section class="lp-teaser lp-animate">
        <div class="container">
            <h2 class="lp-section-title">Live right now</h2>
            <p class="lp-section-sub">A snapshot of today's best-value props — subscribe to unlock full access.</p>

            <div class="lp-teaser__table-wrap">
                <table class="lp-teaser__table">
                    <thead>
                        <tr>
                            <th>Player</th>
                            <th>Market</th>
                            <th>Best book</th>
                            <th>Best odds</th>
                            <th>Edge</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>████████ ████</td>
                            <td>Points O/U 24.5</td>
                            <td>DraftKings</td>
                            <td>+115</td>
                            <td><span class="lp-edge-badge">+340</span></td>
                        </tr>
                        <tr>
                            <td>████ ██████████</td>
                            <td>Strikeouts O/U 7.5</td>
                            <td>FanDuel</td>
                            <td>+105</td>
                            <td><span class="lp-edge-badge">+290</span></td>
                        </tr>
                        <tr>
                            <td>███████ ████████</td>
                            <td>Assists O/U 8.5</td>
                            <td>Bet365</td>
                            <td>-108</td>
                            <td><span class="lp-edge-badge">+255</span></td>
                        </tr>
                        <tr>
                            <td>██████ ████</td>
                            <td>Rushing Yds O/U 72.5</td>
                            <td>Caesars</td>
                            <td>+110</td>
                            <td><span class="lp-edge-badge">+220</span></td>
                        </tr>
                        <tr>
                            <td>████ ████████</td>
                            <td>Rebounds O/U 9.5</td>
                            <td>FanDuel</td>
                            <td>-110</td>
                            <td><span class="lp-edge-badge">+185</span></td>
                        </tr>
                    </tbody>
                </table>
                <div class="lp-teaser__lock">
                    <div class="lp-teaser__lock-inner">
                        <div class="lp-teaser__lock-icon">&#128274;</div>
                        <p class="lp-teaser__lock-title">Unlock full access</p>
                        <p class="lp-teaser__lock-sub">Subscribe to see all props, full odds comparisons, player intel, and your watchlist.</p>
                        <a href="#pricing" class="lp-btn lp-btn--primary">See plans</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ── Pricing ───────────────────────────────────────────────────────── -->
    <section class="lp-pricing" id="pricing">
        <div class="container">
            <h2 class="lp-section-title">Simple pricing</h2>
            <p class="lp-section-sub">No hidden fees. Cancel anytime.</p>

            <div class="lp-pricing__grid lp-animate-group">

                <div class="lp-plan lp-animate-child">
                    <div class="lp-plan__header">
                        <h3 class="lp-plan__name">Free</h3>
                        <div class="lp-plan__price"><span class="lp-plan__amount">$0</span><span class="lp-plan__period">/mo</span></div>
                        <p class="lp-plan__tagline">Try before you commit</p>
                    </div>
                    <ul class="lp-plan__features">
                        <li>All props — all sports</li>
                        <li>Odds from 3 sportsbooks</li>
                        <li>Fixed lines (no adjustments)</li>
                        <li>Sport filter</li>
                        <li class="lp-plan__feature--locked">Adjustable lines</li>
                        <li class="lp-plan__feature--locked">Player intel &amp; hit rates</li>
                        <li class="lp-plan__feature--locked">Odds history charts</li>
                        <li class="lp-plan__feature--locked">Best Value finder</li>
                        <li class="lp-plan__feature--locked">Arbitrage finder</li>
                    </ul>
                    <a href="<?php echo esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' ) ); ?>" class="lp-btn lp-btn--outline lp-btn--full">Get started free</a>
                </div>

                <div class="lp-plan lp-plan--featured lp-animate-child">
                    <div class="lp-plan__badge">Most popular</div>
                    <div class="lp-plan__header">
                        <h3 class="lp-plan__name">Pro</h3>
                        <div class="lp-plan__price"><span class="lp-plan__amount">$9<sup class="lp-plan__cents">.99</sup></span><span class="lp-plan__period">/mo</span></div>
                        <p class="lp-plan__tagline">Everything you need to find edges</p>
                    </div>
                    <ul class="lp-plan__features">
                        <li>All props — all sports</li>
                        <li>Full multi-book odds table</li>
                        <li>Adjustable lines</li>
                        <li>Player intel &amp; hit rates</li>
                        <li>Odds history charts</li>
                        <li>Roster &amp; injury reports</li>
                        <li>Best Value finder</li>
                        <li>AI Analysis</li>
                        <li class="lp-plan__feature--locked">Arbitrage finder</li>
                        <li class="lp-plan__feature--locked">Bet tracker &amp; CLV</li>
                        <li class="lp-plan__feature--locked">EV% display</li>
                        <li class="lp-plan__feature--locked">Notifications</li>
                    </ul>
                    <a href="<?php echo esc_url( add_query_arg( [ 'add-to-cart' => 26 ], wc_get_checkout_url() ) ); ?>" class="lp-btn lp-btn--primary lp-btn--full">Start <strong>30-day free trial</strong></a>
                </div>

                <div class="lp-plan lp-animate-child">
                    <div class="lp-plan__header">
                        <h3 class="lp-plan__name">Sharp</h3>
                        <div class="lp-plan__price"><span class="lp-plan__amount">$19<sup class="lp-plan__cents">.99</sup></span><span class="lp-plan__period">/mo</span></div>
                        <p class="lp-plan__tagline">For serious bettors</p>
                    </div>
                    <ul class="lp-plan__features">
                        <li>Everything in Pro</li>
                        <li>Arbitrage finder</li>
                        <li>Bet tracker &amp; watchlist</li>
                        <li>Closing line value tracking</li>
                        <li>EV% display</li>
                        <li>Best Value &amp; arb notifications</li>
                    </ul>
                    <a href="<?php echo esc_url( add_query_arg( [ 'add-to-cart' => 27 ], wc_get_checkout_url() ) ); ?>" class="lp-btn lp-btn--outline lp-btn--full">Start <strong>30-day free trial</strong></a>
                </div>

            </div>
        </div>
    </section>

    <!-- ── Footer CTA ────────────────────────────────────────────────────── -->
    <section class="lp-cta lp-animate">
        <div class="container lp-cta__inner">
            <h2 class="lp-cta__title">Stop guessing. Start seeing the edge.</h2>
            <p class="lp-cta__sub">Join bettors who use xstatiq to shop lines faster and smarter every day.</p>
            <div class="lp-hero__ctas">
                <a href="<?php echo esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' ) ); ?>" class="lp-btn lp-btn--primary">Get started free &rarr;</a>
            </div>
        </div>
    </section>

</div><!-- .lp -->

<script>
(function () {
    const observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            observer.unobserve(entry.target);

            const staggered = entry.target.querySelectorAll('.lp-animate-child');
            if (staggered.length > 0) {
                staggered.forEach(function (el, i) {
                    setTimeout(function () {
                        el.classList.add('is-visible');
                    }, i * 200);
                });
            } else {
                entry.target.classList.add('is-visible');
            }
        });
    }, { threshold: 0, rootMargin: '0px 0px -180px 0px' });

    document.querySelectorAll('.lp-animate, .lp-animate-group').forEach(function (el) {
        observer.observe(el);
    });
})();
</script>

<?php get_footer(); ?>
