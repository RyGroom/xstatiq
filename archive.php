<?php
/**
 * Archive template — used for the blog post list.
 */
get_header();
?>

<div class="container">
    <div class="blog-archive">

        <header class="blog-archive__header">
            <h1 class="blog-archive__title"><?php the_archive_title(); ?></h1>
            <?php the_archive_description( '<p class="blog-archive__desc">', '</p>' ); ?>
        </header>

        <?php if ( have_posts() ) : ?>

            <div class="blog-archive__grid">
                <?php while ( have_posts() ) : the_post(); ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class( 'blog-card' ); ?>>

                    <?php if ( has_post_thumbnail() ) : ?>
                    <a href="<?php the_permalink(); ?>" class="blog-card__thumb" tabindex="-1" aria-hidden="true">
                        <?php the_post_thumbnail( 'medium_large' ); ?>
                    </a>
                    <?php endif; ?>

                    <div class="blog-card__body">
                        <p class="blog-card__meta">
                            <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
                        </p>
                        <h2 class="blog-card__title">
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h2>
                        <div class="blog-card__excerpt"><?php the_excerpt(); ?></div>
                        <a href="<?php the_permalink(); ?>" class="blog-card__read-more">Read more &rarr;</a>
                    </div>

                </article>
                <?php endwhile; ?>
            </div>

            <nav class="blog-pagination" aria-label="Posts navigation">
                <?php the_posts_pagination( [
                    'prev_text' => '&larr; Newer',
                    'next_text' => 'Older &rarr;',
                ] ); ?>
            </nav>

        <?php else : ?>
            <p class="blog-archive__empty">No posts yet — check back soon.</p>
        <?php endif; ?>

    </div>
</div>

<?php get_footer(); ?>
