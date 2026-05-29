<?php
/**
 * 404 Not Found template
 */
get_header();
?>

<div class="container">
    <div class="error-404">
        <p class="error-404__code">404</p>
        <h1 class="error-404__title">Page Not Found</h1>
        <p class="error-404__desc">The page you&rsquo;re looking for doesn&rsquo;t exist or has been moved.</p>
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="btn">&larr; Back to Props</a>
    </div>
</div>

<?php get_footer(); ?>
