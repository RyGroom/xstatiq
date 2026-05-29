<?php
/**
 * Template Name: Terms of Service
 */

get_header();
?>

<div class="container">
    <div class="legal-page">

        <header class="legal-page__header">
            <h1 class="legal-page__title">Terms of Service</h1>
            <p class="legal-page__meta">Last updated: <?php echo esc_html( date( 'F j, Y' ) ); ?></p>
        </header>

        <div class="legal-page__body">

            <section class="legal-section">
                <h2>1. Acceptance of Terms</h2>
                <p>By accessing or using xstatiq ("the Service"), you agree to be bound by these Terms of Service. If you do not agree, do not use the Service. You must be at least 21 years of age and located in a jurisdiction where sports betting is legal to use xstatiq.</p>
            </section>

            <section class="legal-section">
                <h2>2. Description of Service</h2>
                <p>xstatiq is an informational platform that aggregates and displays sports betting odds and player prop data from licensed US sportsbooks. xstatiq does not operate as a sportsbook, accept wagers, or provide financial or gambling advice. All data is provided for informational purposes only.</p>
            </section>

            <section class="legal-section">
                <h2>3. Accounts and Subscriptions</h2>
                <p>Some features require a paid subscription. You are responsible for maintaining the confidentiality of your account credentials and for all activity under your account. Subscriptions are billed on the schedule displayed at checkout and renew automatically unless cancelled.</p>
                <p>We reserve the right to suspend or terminate accounts that violate these Terms.</p>
            </section>

            <section class="legal-section">
                <h2>4. Payments and Refunds</h2>
                <p>All payments are processed securely through Stripe. Subscription fees are non-refundable except where required by law. If you believe you have been charged in error, contact us within 30 days at <a href="mailto:info@xstatiq.io">info@xstatiq.io</a>.</p>
            </section>

            <section class="legal-section">
                <h2>5. Acceptable Use</h2>
                <p>You agree not to:</p>
                <ul>
                    <li>Scrape, crawl, or systematically download data from xstatiq</li>
                    <li>Reverse engineer or attempt to extract source code</li>
                    <li>Use the Service for any unlawful purpose</li>
                    <li>Share your account credentials with others</li>
                    <li>Attempt to circumvent subscription restrictions</li>
                    <li>Interfere with or disrupt the Service or its servers</li>
                </ul>
            </section>

            <section class="legal-section">
                <h2>6. Intellectual Property</h2>
                <p>All content, design, code, and data on xstatiq is the property of xstatiq or its licensors and is protected by copyright and other intellectual property laws. You may not reproduce, distribute, or create derivative works without our express written permission.</p>
            </section>

            <section class="legal-section">
                <h2>7. Disclaimer of Warranties</h2>
                <p>The Service is provided "as is" without warranties of any kind, express or implied. We do not guarantee the accuracy, completeness, or timeliness of odds data. Odds can change rapidly and may differ from what is displayed at your sportsbook at time of wagering.</p>
                <p><strong>xstatiq does not guarantee any financial outcome from the use of this Service. Bet responsibly.</strong></p>
            </section>

            <section class="legal-section">
                <h2>8. Limitation of Liability</h2>
                <p>To the maximum extent permitted by law, xstatiq shall not be liable for any indirect, incidental, special, consequential, or punitive damages arising from your use of the Service, including any wagering decisions made based on data displayed on xstatiq.</p>
            </section>

            <section class="legal-section">
                <h2>9. Responsible Gambling</h2>
                <p>xstatiq is committed to promoting responsible gambling. If you or someone you know has a gambling problem, please seek help:</p>
                <ul>
                    <li><strong>National Problem Gambling Helpline:</strong> 1-800-522-4700</li>
                    <li><strong>NCPG:</strong> <a href="https://www.ncpgambling.org" target="_blank" rel="noopener">ncpgambling.org</a></li>
                </ul>
            </section>

            <section class="legal-section">
                <h2>10. Changes to Terms</h2>
                <p>We may modify these Terms at any time. We will notify registered users of material changes via email. Continued use of the Service after changes take effect constitutes acceptance of the updated Terms.</p>
            </section>

            <section class="legal-section">
                <h2>11. Governing Law</h2>
                <p>These Terms are governed by the laws of the State of Utah, without regard to its conflict of law provisions. Any disputes shall be resolved in the courts of Utah.</p>
            </section>

            <section class="legal-section">
                <h2>12. Contact</h2>
                <p>Questions about these Terms? Email us at <a href="mailto:info@xstatiq.io">info@xstatiq.io</a>.</p>
            </section>

        </div>

    </div>
</div>

<?php get_footer(); ?>
