<?php
/**
 * Theme footer template
 */
?>
</main><!-- #main-content -->

<footer class="site-footer">
    <div class="container">
        <div class="site-footer__body">

            <!-- Brand -->
            <div class="site-footer__brand">
                <a href="<?php echo esc_url( home_url( '/props/' ) ); ?>" class="site-footer__logo">
                    xstatiq<em>_</em>
                </a>
                <p class="site-footer__tagline">Compare player prop odds across top US sportsbooks.</p>
            </div>

            <!-- Right: nav + legal -->
            <div class="site-footer__right">

                <nav class="site-footer__nav" aria-label="Footer navigation">
                    <ul>
                        <li><a href="<?php echo esc_url( home_url( '/props/' ) ); ?>">Props</a></li>
                        <li><a href="<?php echo esc_url( home_url( '/best-value/' ) ); ?>">Best Value</a></li>
                        <li><a href="<?php echo esc_url( home_url( '/watchlist/' ) ); ?>">Watchlist</a></li>
                        <?php if ( is_user_logged_in() ) : ?>
                        <li><a href="<?php echo esc_url( home_url( '/community/' ) ); ?>">Community</a></li>
                        <?php endif; ?>
                        <li><a href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">Pricing</a></li>
                        <li><a href="<?php echo esc_url( home_url( '/blog/' ) ); ?>">Blog</a></li>
                    </ul>
                </nav>

                <div class="site-footer__legal">
                    <p class="site-footer__disclaimer">
                        xstatiq is for informational purposes only. Gambling involves financial risk &mdash; please bet responsibly.
                        Must be 21+ and located in a jurisdiction where sports betting is legal.
                    </p>
                    <p class="site-footer__copy">
                        &copy; <?php echo esc_html( date( 'Y' ) ); ?> xstatiq. All rights reserved.
                        <a href="<?php echo esc_url( home_url( '/privacy/' ) ); ?>">Privacy Policy</a> &middot;
                        <a href="<?php echo esc_url( home_url( '/terms/' ) ); ?>">Terms of Service</a>
                    </p>
                </div>

            </div>

        </div>
    </div>
</footer>

<!-- Back to top button -->
<button class="back-to-top" id="back-to-top" aria-label="Back to top" title="Back to top">&#x2191;</button>

<script>
(function () {
    const btn = document.getElementById('back-to-top');
    if (!btn) return;
    window.addEventListener('scroll', function () {
        btn.classList.toggle('back-to-top--visible', window.scrollY > 400);
    }, { passive: true });
    btn.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}());
</script>

</div><!-- .site-wrapper -->

<?php wp_footer(); ?>
</body>
</html>
