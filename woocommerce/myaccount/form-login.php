<?php
/**
 * Login Form — Statsight theme override
 * Based on WooCommerce myaccount/form-login.php v9.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Prevent WooCommerce from printing notices outside our panel.
remove_action( 'woocommerce_before_customer_login_form', 'woocommerce_output_all_notices', 10 );
do_action( 'woocommerce_before_customer_login_form' );
?>

<div class="ss-auth-wrap">

    <?php // ── Login panel ─────────────────────────────────────────────────── ?>
    <div class="ss-auth-panel ss-auth-panel--login" id="ss-panel-login">

        <div class="ss-auth-logo">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>">xstatiq<em>_</em></a>
        </div>

        <?php wc_print_notices(); ?>

        <form class="woocommerce-form woocommerce-form-login login" method="post" novalidate>

            <?php do_action( 'woocommerce_login_form_start' ); ?>

            <div class="ss-auth-field">
                <label for="username"><?php esc_html_e( 'Email address', 'woocommerce' ); ?></label>
                <input type="email" name="username" id="username" autocomplete="email"
                    value="<?php echo ( ! empty( $_POST['username'] ) && is_string( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>"
                    required aria-required="true">
            </div>

            <div class="ss-auth-field">
                <label for="password"><?php esc_html_e( 'Password', 'woocommerce' ); ?></label>
                <input type="password" name="password" id="password" autocomplete="current-password" required aria-required="true">
            </div>

            <?php do_action( 'woocommerce_login_form' ); ?>

            <div class="ss-auth-row">
                <label class="ss-auth-remember">
                    <input type="checkbox" name="rememberme" id="rememberme" value="forever">
                    <span><?php esc_html_e( 'Remember me', 'woocommerce' ); ?></span>
                </label>
                <a class="ss-auth-lost-pw" href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Forgot password?', 'woocommerce' ); ?></a>
            </div>

            <?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>

            <button type="submit" class="ss-auth-btn" name="login" value="<?php esc_attr_e( 'Log in', 'woocommerce' ); ?>">
                <?php esc_html_e( 'Log In', 'woocommerce' ); ?>
            </button>

            <?php do_action( 'woocommerce_login_form_end' ); ?>

        </form>

        <?php if ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ) : ?>
        <p class="ss-auth-switch">
            <?php esc_html_e( "Don't have an account?", 'woocommerce' ); ?>
            <a href="#" class="ss-auth-switch-link" data-show="ss-panel-register"><?php esc_html_e( 'Sign up', 'woocommerce' ); ?></a>
        </p>
        <?php endif; ?>

    </div>

    <?php // ── Register panel ───────────────────────────────────────────────── ?>
    <?php if ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ) : ?>
    <div class="ss-auth-panel ss-auth-panel--register" id="ss-panel-register" hidden>

        <div class="ss-auth-logo">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>">xstatiq<em>_</em></a>
        </div>

        <h2 class="ss-auth-title"><?php esc_html_e( 'Create an account', 'woocommerce' ); ?></h2>

        <form method="post" class="woocommerce-form woocommerce-form-register register" <?php do_action( 'woocommerce_register_form_tag' ); ?>>

            <?php do_action( 'woocommerce_register_form_start' ); ?>

            <?php if ( 'no' === get_option( 'woocommerce_registration_generate_username' ) ) : ?>
            <div class="ss-auth-field">
                <label for="reg_username"><?php esc_html_e( 'Username', 'woocommerce' ); ?></label>
                <input type="text" name="username" id="reg_username" autocomplete="username"
                    value="<?php echo ( ! empty( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>"
                    required aria-required="true">
            </div>
            <?php endif; ?>

            <div class="ss-auth-field">
                <label for="reg_email"><?php esc_html_e( 'Email address', 'woocommerce' ); ?></label>
                <input type="email" name="email" id="reg_email" autocomplete="email"
                    value="<?php echo ( ! empty( $_POST['email'] ) ) ? esc_attr( wp_unslash( $_POST['email'] ) ) : ''; ?>"
                    required aria-required="true">
            </div>

            <?php if ( 'no' === get_option( 'woocommerce_registration_generate_password' ) ) : ?>
            <div class="ss-auth-field">
                <label for="reg_password"><?php esc_html_e( 'Password', 'woocommerce' ); ?></label>
                <input type="password" name="password" id="reg_password" autocomplete="new-password" required aria-required="true">
            </div>
            <?php else : ?>
            <p class="ss-auth-hint"><?php esc_html_e( 'A link to set your password will be sent to your email address.', 'woocommerce' ); ?></p>
            <?php endif; ?>

            <?php do_action( 'woocommerce_register_form' ); ?>

            <?php wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' ); ?>

            <button type="submit" class="ss-auth-btn" name="register" value="<?php esc_attr_e( 'Register', 'woocommerce' ); ?>">
                <?php esc_html_e( 'Create Account', 'woocommerce' ); ?>
            </button>

            <?php do_action( 'woocommerce_register_form_end' ); ?>

        </form>

        <p class="ss-auth-switch">
            <?php esc_html_e( 'Already have an account?', 'woocommerce' ); ?>
            <a href="#" class="ss-auth-switch-link" data-show="ss-panel-login"><?php esc_html_e( 'Log in', 'woocommerce' ); ?></a>
        </p>

    </div>
    <?php endif; ?>

</div>

<script>
(function () {
    // Show register panel if URL has #register
    if (window.location.hash === '#register') {
        const login    = document.getElementById('ss-panel-login');
        const register = document.getElementById('ss-panel-register');
        if (login && register) {
            login.setAttribute('hidden', '');
            register.removeAttribute('hidden');
        }
    }

    document.querySelectorAll('.ss-auth-switch-link').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            const showId  = this.dataset.show;
            const panels  = document.querySelectorAll('.ss-auth-panel');
            panels.forEach(function (p) { p.setAttribute('hidden', ''); });
            const target = document.getElementById(showId);
            if (target) target.removeAttribute('hidden');
        });
    });
}());
</script>

<?php do_action( 'woocommerce_after_customer_login_form' ); ?>
