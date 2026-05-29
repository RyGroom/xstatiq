<?php
/**
 * Theme header template
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0a0c10">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="xstatiq">
    <link rel="manifest" href="<?php echo esc_url( get_template_directory_uri() . '/manifest.json' ); ?>">
    <link rel="apple-touch-icon" href="<?php echo esc_url( get_template_directory_uri() . '/assets/icons/icon-192.png' ); ?>">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<div class="site-wrapper">

<header class="site-header">
    <div class="container">
        <a href="<?php echo esc_url( is_user_logged_in() ? home_url( '/props/' ) : home_url( '/' ) ); ?>" class="site-branding">
            <span class="site-branding__logo">xstatiq<em>_</em></span>
        </a>

        <!-- Desktop nav (hidden on mobile via CSS) -->
        <nav class="site-nav site-nav--desktop" aria-label="Primary navigation">
            <?php
            $page_id      = get_the_ID() ?: get_queried_object_id();
            $current_slug = get_post_field( 'post_name', $page_id );

            $nav_links = [
                'props'      => 'Props',
                'best-value' => 'Best Value',
                'watchlist'  => 'Watchlist',
            ];
            if ( is_user_logged_in() ) {
                $nav_links['community'] = 'Community';
            } else {
                $nav_links['pricing'] = 'Pricing';
            }
            $nav_links['blog'] = 'Blog';

            echo '<ul>';
            foreach ( $nav_links as $slug => $label ) {
                $href   = home_url( '/' . $slug . '/' );
                $active = $current_slug === $slug ? ' class="current-menu-item"' : '';
                echo '<li' . $active . '><a href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a></li>';
            }
            $myaccount_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
            if ( is_user_logged_in() ) {
                $active = is_account_page() ? ' class="current-menu-item"' : '';
                echo '<li' . $active . '><a href="' . esc_url( $myaccount_url ) . '">Account</a></li>';
            } else {
                echo '<li class="nav-login-item"><a class="nav-login-btn" href="' . esc_url( $myaccount_url ) . '">Log In</a></li>';
            }
            echo '</ul>';
            ?>
        </nav>

        <button
            class="nav-hamburger"
            id="nav-hamburger"
            aria-label="Toggle navigation"
            aria-expanded="false"
            aria-controls="mobile-nav"
        >
            <span class="nav-hamburger__bar"></span>
            <span class="nav-hamburger__bar"></span>
            <span class="nav-hamburger__bar"></span>
        </button>
    </div>
</header>

<!-- Mobile nav — sibling of header so fixed positioning isn't clipped by backdrop-filter -->
<nav class="site-nav site-nav--mobile" id="mobile-nav" aria-label="Primary navigation" hidden>
    <?php
    echo '<ul>';
    foreach ( $nav_links as $slug => $label ) {
        $href   = home_url( '/' . $slug . '/' );
        $active = $current_slug === $slug ? ' class="current-menu-item"' : '';
        echo '<li' . $active . '><a href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a></li>';
    }
    if ( is_user_logged_in() ) {
        $active = is_account_page() ? ' class="current-menu-item"' : '';
        echo '<li' . $active . '><a href="' . esc_url( $myaccount_url ) . '">Account</a></li>';
    } else {
        echo '<li class="nav-login-item"><a class="nav-login-btn" href="' . esc_url( $myaccount_url ) . '">Log In</a></li>';
    }
    echo '</ul>';
    ?>
</nav>

<script>
(function () {
    const btn    = document.getElementById('nav-hamburger');
    const mobileNav = document.getElementById('mobile-nav');

    function openNav() {
        mobileNav.removeAttribute('hidden');
        // Trigger transition on next frame
        requestAnimationFrame(function () {
            mobileNav.classList.add('is-open');
        });
        btn.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }

    function closeNav() {
        mobileNav.classList.remove('is-open');
        btn.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        // Re-hide after transition so it's removed from tab order
        mobileNav.addEventListener('transitionend', function hide() {
            mobileNav.setAttribute('hidden', '');
            mobileNav.removeEventListener('transitionend', hide);
        });
    }

    btn.addEventListener('click', function () {
        mobileNav.classList.contains('is-open') ? closeNav() : openNav();
    });

    // Close when a nav link is tapped
    mobileNav.addEventListener('click', function (e) {
        if (e.target.tagName === 'A') closeNav();
    });
})();
</script>

<main class="site-main" id="main-content">
