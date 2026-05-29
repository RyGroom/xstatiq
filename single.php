<?php
/**
 * Single post template.
 */
get_header();
?>

<div class="container">
    <?php while ( have_posts() ) : the_post(); ?>

    <article id="post-<?php the_ID(); ?>" <?php post_class( 'blog-post' ); ?>>

        <header class="blog-post__header">
            <p class="blog-post__meta">
                <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
            </p>
            <h1 class="blog-post__title"><?php the_title(); ?></h1>
            <?php if ( has_post_thumbnail() ) : ?>
            <div class="blog-post__thumb">
                <?php the_post_thumbnail( 'full' ); ?>
            </div>
            <?php endif; ?>
        </header>

        <div class="blog-post__content">
            <?php the_content(); ?>
        </div>

        <footer class="blog-post__footer">
            <a href="<?php echo esc_url( get_post_type_archive_link( 'post' ) ?: home_url( '/blog/' ) ); ?>" class="blog-post__back">&larr; Back to blog</a>
        </footer>

    </article>

    <?php endwhile; ?>
</div>

<?php get_footer(); ?>
