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
            <?php
            // Sent here from a page the current plan doesn't include (statsight_require_plan_redirect()).
            $feature = sanitize_key( $_GET['feature'] ?? '' );
            if ( isset( STATSIGHT_PLAN_PAGES[ $feature ] ) ) :
                [ $needs, $name ] = STATSIGHT_PLAN_PAGES[ $feature ];
                ?>
                <p class="lp-pricing-notice" role="status">
                    <?php echo esc_html( $name ); ?> is included with <?php echo $needs === 'pro' ? 'Pro and Sharp' : 'Sharp'; ?>.
                    Pick a plan below to unlock it.
                </p>
            <?php endif; ?>
        </div>
    </section>

    <!-- Plans -->
    <section class="lp-pricing lp-pricing--page">
        <div class="container">
            <?php get_template_part( 'template-parts/plan-cards', null, [ 'heading' => 'h2' ] ); ?>
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
