<?php
/**
 * Template Name: Account Page
 * Renders without the page.php container wrapper so acct-body
 * and acct-hero control their own horizontal spacing.
 */
get_header();
?>
<?php while ( have_posts() ) : the_post(); the_content(); endwhile; ?>
<?php get_footer(); ?>
