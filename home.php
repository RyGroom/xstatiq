<?php
/**
 * Blog posts index — used when a static page is set as the Posts page
 * in Settings → Reading. WordPress loads this instead of archive.php.
 */
get_header();

// Collect all categories that have posts for the filter bar.
$filter_cats = get_categories( [ 'hide_empty' => true, 'orderby' => 'name' ] );
?>

<div class="container">
    <div class="blog-archive">

        <header class="blog-archive__header">
            <h1 class="blog-archive__title">Blog</h1>
            <p class="blog-archive__desc">Tips, guides, and updates from the xstatiq team.</p>
        </header>

        <?php if ( $filter_cats ) : ?>
        <div class="blog-filters" role="group" aria-label="Filter by category">
            <button class="blog-filter-btn is-active" data-filter="all">All</button>
            <?php foreach ( $filter_cats as $cat ) : ?>
                <button class="blog-filter-btn" data-filter="<?php echo esc_attr( $cat->slug ); ?>">
                    <?php echo esc_html( $cat->name ); ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ( have_posts() ) : ?>

            <div class="blog-archive__grid">

                <?php while ( have_posts() ) : the_post();
                    $cats     = get_the_category();
                    $cat      = ! empty( $cats ) ? $cats[0] : null;
                    $cat_slug = $cat ? $cat->slug : '';
                    $cat_name = $cat ? $cat->name : '';
                ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class( 'blog-card' ); ?> data-category="<?php echo esc_attr( $cat_slug ); ?>">
                    <a href="<?php the_permalink(); ?>" class="blog-card__link" aria-label="<?php the_title_attribute(); ?>">

                        <?php if ( has_post_thumbnail() ) : ?>
                        <div class="blog-card__thumb">
                            <?php the_post_thumbnail( 'medium_large' ); ?>
                        </div>
                        <?php endif; ?>

                        <div class="blog-card__body">
                            <?php if ( $cat_name ) : ?>
                            <span class="blog-card__cat"><?php echo esc_html( $cat_name ); ?></span>
                            <?php endif; ?>
                            <p class="blog-card__meta">
                                <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
                            </p>
                            <h2 class="blog-card__title"><?php the_title(); ?></h2>
                            <div class="blog-card__excerpt"><?php the_excerpt(); ?></div>
                            <span class="blog-card__read-more">Read article <span class="blog-card__arrow">&rarr;</span></span>
                        </div>

                    </a>
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

<script>
(function () {
    const filterBtns = document.querySelectorAll('.blog-filter-btn');
    const cards      = document.querySelectorAll('.blog-card');

    filterBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const filter = btn.dataset.filter;

            filterBtns.forEach(b => b.classList.toggle('is-active', b === btn));

            cards.forEach(function (card) {
                const match = filter === 'all' || card.classList.contains('category-' + filter);
                card.hidden = !match;
            });
        });
    });
})();
</script>

<?php get_footer(); ?>
