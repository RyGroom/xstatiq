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
                <div class="site-footer__social">
                    <a href="https://www.youtube.com/@xstatiq-io" class="site-footer__social-link" target="_blank" rel="noopener noreferrer" aria-label="xstatiq on YouTube">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M23.5 6.2a3.01 3.01 0 0 0-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3.01 3.01 0 0 0 .5 6.2C0 8.1 0 12 0 12s0 3.9.5 5.8a3.01 3.01 0 0 0 2.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3.01 3.01 0 0 0 2.1-2.1C24 15.9 24 12 24 12s0-3.9-.5-5.8zM9.6 15.6V8.4l6.3 3.6-6.3 3.6z"/></svg>
                        YouTube
                    </a>
                </div>
            </div>

            <!-- Right: nav + legal -->
            <div class="site-footer__right">

                <nav class="site-footer__nav" aria-label="Footer navigation">
                    <ul>
                        <li><a class="nav-footer-link" href="<?php echo esc_url( home_url( '/props/' ) ); ?>">Props</a></li>
                        <li><a class="nav-footer-link" href="<?php echo esc_url( home_url( '/best-value/' ) ); ?>">Best Value</a></li>
                        <li><a class="nav-footer-link" href="<?php echo esc_url( home_url( '/watchlist/' ) ); ?>">Watchlist</a></li>
                        <?php if ( is_user_logged_in() ) : ?>
                        <li><a class="nav-footer-link" href="<?php echo esc_url( home_url( '/community/' ) ); ?>">Community</a></li>
                        <?php endif; ?>
                        <li><a class="nav-footer-link" href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">Pricing</a></li>
                        <li><a class="nav-footer-link" href="<?php echo esc_url( home_url( '/blog/' ) ); ?>">Blog</a></li>
                    </ul>
                </nav>

                <div class="site-footer__legal">
                    <p class="site-footer__disclaimer">
                        xstatiq is for informational purposes only. Gambling involves financial risk &mdash; please bet responsibly.
                        Must be 21+ and located in a jurisdiction where sports betting is legal.
                    </p>
                    <p class="site-footer__copy">
                        &copy; <?php echo esc_html( date( 'Y' ) ); ?> xstatiq. All rights reserved.
                    </p>
                    <p class="site-footer__links">
                        <a class="nav-footer-utility-link" href="<?php echo esc_url( home_url( '/support/' ) ); ?>">Support</a> &middot;
                        <a class="nav-footer-utility-link" href="<?php echo esc_url( home_url( '/privacy/' ) ); ?>">Privacy Policy</a> &middot;
                        <a class="nav-footer-utility-link" href="<?php echo esc_url( home_url( '/terms/' ) ); ?>">Terms of Service</a> &middot;
                        <a class="nav-footer-utility-link" href="<?php echo esc_url( home_url( '/responsible-gambling/' ) ); ?>">Responsible Gaming</a>
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
