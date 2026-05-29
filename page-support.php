<?php
/**
 * Template Name: Support
 *
 * Contact page for bug reports and feature requests.
 * Split-panel layout: branded left panel + form right panel.
 */

get_header();
?>

<div class="support-split">

    <!-- ── Left panel ───────────────────────────────────────────────────────── -->
    <div class="support-split__left">
        <div aria-hidden="true">
            <div class="support-split__glow support-split__glow--1"></div>
            <div class="support-split__glow support-split__glow--2"></div>
        </div>
        <div class="support-split__left-inner">

            <span class="support-split__eyebrow">Contact Us</span>

            <h1 class="support-split__headline">Let&rsquo;s Get In Touch.</h1>

            <p class="support-split__sub">Found a bug or have a feature idea? We&rsquo;re actively building and every piece of feedback matters.</p>

            <ul class="support-split__reasons">
                <li class="support-split__reason">
                    <span class="support-split__reason-icon" aria-hidden="true">&#x1F41B;</span>
                    <div>
                        <strong>Bug Reports</strong>
                        <span>Odds not loading, wrong data, display issues</span>
                    </div>
                </li>
                <li class="support-split__reason">
                    <span class="support-split__reason-icon" aria-hidden="true">&#x1F4A1;</span>
                    <div>
                        <strong>Feature Requests</strong>
                        <span>New sports, stats, filters, or tools</span>
                    </div>
                </li>
                <li class="support-split__reason">
                    <span class="support-split__reason-icon" aria-hidden="true">&#x23F1;</span>
                    <div>
                        <strong>Response Time</strong>
                        <span>Typically within 1&ndash;2 business days</span>
                    </div>
                </li>
            </ul>

        </div>
    </div>

    <!-- ── Right panel ──────────────────────────────────────────────────────── -->
    <div class="support-split__right">
        <div class="support-split__right-inner">
            <h2 class="support-split__form-title">Send a Message</h2>
            <?php echo do_shortcode('[contact-form-7 id="400773d" title="General Contact"]'); ?>
        </div>
    </div>

</div>

<?php get_footer(); ?>
