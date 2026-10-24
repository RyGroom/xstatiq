<?php
/**
 * Template Name: Privacy Policy
 */

get_header();
?>

<div class="container">
    <div class="legal-page">

        <header class="legal-page__header">
            <h1 class="legal-page__title">Privacy Policy</h1>
            <p class="legal-page__meta">Last updated: October 24, 2026</p>
        </header>

        <div class="legal-page__body">

            <section class="legal-section">
                <h2>1. Who We Are</h2>
                <p>xstatiq ("we," "us," or "our") operates the website at <?php echo esc_html( home_url() ); ?>. We provide sports betting odds comparison and player prop analysis tools. For privacy-related questions, contact us at <a href="mailto:info@xstatiq.io">info@xstatiq.io</a>.</p>
            </section>

            <section class="legal-section">
                <h2>2. Information We Collect</h2>
                <h3>Account Information</h3>
                <p>When you create an account or make a purchase, we collect your name, email address, and billing information. Card and PayPal details are handled by our payment providers (see Data Sharing) and are not stored on our servers.</p>
                <h3>Picks, Alerts and Questions</h3>
                <p>We store the props you save to your watchlist and collections, their results, your alert settings and your sportsbook preferences. If you use Ask AI or AI Prop Analysis, the question you type and the odds and stats it concerns are sent to our AI provider to generate the answer.</p>
                <h3>Public Profile</h3>
                <p>If you choose to make your watchlist, pick record or collections public, your display name and those picks are visible to other xstatiq users on the Community page. Each is private unless you turn it on, and you can turn it off at any time in your account.</p>
                <h3>Usage Data</h3>
                <p>We automatically collect information about how you interact with xstatiq, including pages visited, features used, browser type, IP address, and referring URLs.</p>
                <h3>Push Notifications</h3>
                <p>If you opt in to push notifications, we store a subscription token associated with your browser to deliver alerts. You can revoke this permission at any time through your browser settings.</p>
                <h3>Cookies</h3>
                <p>We use cookies and similar technologies to keep you logged in, remember your preferences, and analyze site traffic. Traffic analytics run through Google Tag Manager, which may set Google analytics cookies. We do not use third-party advertising cookies.</p>
            </section>

            <section class="legal-section">
                <h2>3. How We Use Your Information</h2>
                <ul>
                    <li>To provide and improve the xstatiq service</li>
                    <li>To process payments and manage your subscription</li>
                    <li>To send you odds alerts and notifications you have requested</li>
                    <li>To respond to support requests</li>
                    <li>To analyze usage patterns and fix bugs</li>
                    <li>To comply with legal obligations</li>
                </ul>
                <p>We do not sell your personal information to third parties.</p>
            </section>

            <section class="legal-section">
                <h2>4. Data Sharing</h2>
                <p>We share your information only with trusted service providers necessary to operate xstatiq, including:</p>
                <ul>
                    <li><strong>WooPayments (powered by Stripe)</strong> — card payment processing</li>
                    <li><strong>PayPal</strong> — PayPal payments</li>
                    <li><strong>WooCommerce</strong> — subscription management</li>
                    <li><strong>Anthropic</strong> — generates Ask AI and AI Prop Analysis answers from the questions and data described above</li>
                    <li><strong>OpenAI</strong> — checks the names you give public collections for offensive language</li>
                    <li><strong>Google (Tag Manager and Analytics)</strong> — site traffic analytics</li>
                    <li><strong>Browser push services</strong> (from Google, Apple or Mozilla, depending on your browser) — deliver push notifications you opt in to</li>
                    <li><strong>Hosting providers</strong> — server infrastructure and email delivery</li>
                </ul>
                <p>We may also disclose information if required by law or to protect the rights and safety of xstatiq and its users.</p>
            </section>

            <section class="legal-section">
                <h2>5. Data Retention</h2>
                <p>We retain your account information for as long as your account is active or as needed to provide services.</p>
                <p>You can delete your account yourself under <strong>Account &rarr; Delete Account</strong>. Your subscription stops renewing straight away and your account is scheduled for deletion in 30 days; until then you can restore it with the link in the confirmation email. After 30 days your account, watchlist, picks, collections, alerts and push subscriptions are permanently deleted. We keep order and payment records we are required to retain for tax and accounting. You can also ask us to delete your data by contacting us.</p>
            </section>

            <section class="legal-section">
                <h2>6. Your Rights</h2>
                <p>Depending on your location, you may have the right to:</p>
                <ul>
                    <li>Access the personal data we hold about you</li>
                    <li>Request correction of inaccurate data</li>
                    <li>Request deletion of your data</li>
                    <li>Opt out of marketing communications</li>
                    <li>Lodge a complaint with a data protection authority</li>
                </ul>
                <p>To exercise any of these rights, contact us at <a href="mailto:info@xstatiq.io">info@xstatiq.io</a>.</p>
            </section>

            <section class="legal-section">
                <h2>7. Children's Privacy</h2>
                <p>xstatiq is not directed at anyone under the age of 21. We do not knowingly collect personal information from minors. If you believe a minor has provided us with personal information, please contact us and we will delete it.</p>
            </section>

            <section class="legal-section">
                <h2>8. Changes to This Policy</h2>
                <p>We may update this Privacy Policy from time to time. We will notify registered users of material changes via email. Continued use of xstatiq after changes take effect constitutes acceptance of the updated policy.</p>
            </section>

            <section class="legal-section">
                <h2>9. Contact</h2>
                <p>Questions about this Privacy Policy? Email us at <a href="mailto:info@xstatiq.io">info@xstatiq.io</a>.</p>
            </section>

        </div>

    </div>
</div>

<?php get_footer(); ?>
