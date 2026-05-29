<?php
/**
 * WooCommerce shop / product archive template.
 * Bypasses the default page.php wrapper so the shop renders without
 * the nested container > article > entry-content card stack.
 */
get_header();
?>

<main class="shop-archive">
    <?php woocommerce_content(); ?>
</main>

<?php get_footer(); ?>
