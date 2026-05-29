<?php
/**
 * Template Name: Pricing
 *
 * Standalone pricing page — reuses homepage plan cards and lp-* styles.
 */

get_header();
?>

<div class="lp">

    <!-- Hero -->
    <section class="lp-pricing-hero">
        <div class="container lp-pricing-hero__inner">
            <h1 class="lp-section-title">Simple pricing</h1>
            <p class="lp-section-sub">No hidden fees. Cancel anytime.</p>
        </div>
    </section>

    <!-- Plans -->
    <section class="lp-pricing lp-pricing--page">
        <div class="container">
            <div class="lp-pricing__grid">

                <div class="lp-plan">
                    <div class="lp-plan__header">
                        <h2 class="lp-plan__name">Free</h2>
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

                <div class="lp-plan lp-plan--featured">
                    <div class="lp-plan__badge">Most popular</div>
                    <div class="lp-plan__header">
                        <h2 class="lp-plan__name">Pro</h2>
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

                <div class="lp-plan">
                    <div class="lp-plan__header">
                        <h2 class="lp-plan__name">Sharp</h2>
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

    <!-- CTA -->
    <section class="lp-cta">
        <div class="container lp-cta__inner">
            <h2 class="lp-cta__title">Stop guessing. Start seeing the edge.</h2>
            <p class="lp-cta__sub">Join bettors who use xstatiq to shop lines faster and smarter every day.</p>
            <a href="<?php echo esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' ) ); ?>" class="lp-btn lp-btn--primary">Get started free &rarr;</a>
        </div>
    </section>

</div>

<?php get_footer(); ?>
