<?php
/**
 * Free / Pro / Sharp plan cards, shared by the homepage and the pricing page.
 * Feature lists come from statsight_plan_features().
 *
 * $args: heading (tag for plan names, default 'h2'), animate (add the homepage's lp-animate-child classes).
 */

$heading  = in_array( $args['heading'] ?? 'h2', [ 'h2', 'h3' ], true ) ? $args['heading'] : 'h2';
$animate  = ! empty( $args['animate'] ) ? ' lp-animate-child' : '';
$features = statsight_plan_features();
$account  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
$checkout = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );

$plans = [
    'free'  => [ 'name' => 'Free',  'amount' => '$0',  'cents' => '',    'tagline' => 'Try before you commit',
                 'href' => $account, 'cta' => 'Get started free', 'btn' => 'lp-btn--outline' ],
    'pro'   => [ 'name' => 'Pro',   'amount' => '$9',  'cents' => '.99', 'tagline' => 'Everything you need to find edges',
                 'href' => add_query_arg( [ 'add-to-cart' => STATSIGHT_PRODUCT_PRO ], $checkout ),
                 'cta' => 'Start <strong>30-day free trial</strong>', 'btn' => 'lp-btn--primary', 'badge' => 'Most popular' ],
    'sharp' => [ 'name' => 'Sharp', 'amount' => '$19', 'cents' => '.99', 'tagline' => 'For serious bettors',
                 'href' => add_query_arg( [ 'add-to-cart' => STATSIGHT_PRODUCT_SHARP ], $checkout ),
                 'cta' => 'Start <strong>30-day free trial</strong>', 'btn' => 'lp-btn--outline' ],
];
?>
<div class="lp-pricing__grid<?php echo $animate ? ' lp-animate-group' : ''; ?>">
    <?php foreach ( $plans as $key => $plan ) : ?>
        <div class="lp-plan<?php echo $key === 'pro' ? ' lp-plan--featured' : ''; ?><?php echo esc_attr( $animate ); ?>">
            <?php if ( ! empty( $plan['badge'] ) ) : ?>
                <div class="lp-plan__badge"><?php echo esc_html( $plan['badge'] ); ?></div>
            <?php endif; ?>
            <div class="lp-plan__header">
                <<?php echo $heading; ?> class="lp-plan__name"><?php echo esc_html( $plan['name'] ); ?></<?php echo $heading; ?>>
                <div class="lp-plan__price"><span class="lp-plan__amount"><?php echo esc_html( $plan['amount'] ); ?><?php if ( $plan['cents'] ) : ?><sup class="lp-plan__cents"><?php echo esc_html( $plan['cents'] ); ?></sup><?php endif; ?></span><span class="lp-plan__period">/mo</span></div>
                <p class="lp-plan__tagline"><?php echo esc_html( $plan['tagline'] ); ?></p>
            </div>
            <ul class="lp-plan__features">
                <?php foreach ( $features[ $key ]['included'] as $item ) : ?>
                    <li><?php echo wp_kses( $item, [] ); ?></li>
                <?php endforeach; ?>
                <?php foreach ( $features[ $key ]['locked'] as $item ) : ?>
                    <li class="lp-plan__feature--locked"><?php echo wp_kses( $item, [] ); ?></li>
                <?php endforeach; ?>
            </ul>
            <a href="<?php echo esc_url( $plan['href'] ); ?>" class="lp-btn <?php echo esc_attr( $plan['btn'] ); ?> lp-btn--full"><?php echo wp_kses( $plan['cta'], [ 'strong' => [] ] ); ?></a>
        </div>
    <?php endforeach; ?>
</div>
