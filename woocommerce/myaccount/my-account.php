<?php
/**
 * My Account page — xstatiq theme override
 * Based on WooCommerce myaccount/my-account.php v3.5.0
 *
 * On the dashboard endpoint we render the full custom account UI.
 * On all other endpoints (orders, edit-account, etc.) we fall through
 * to WooCommerce's standard content so those features still work.
 */

defined( 'ABSPATH' ) || exit;

// Detect whether we are on the dashboard (no sub-endpoint active).
$current_endpoint = WC()->query->get_current_endpoint();
$is_dashboard     = empty( $current_endpoint );

if ( ! $is_dashboard ) {
    // ── Non-dashboard endpoints: standard WooCommerce output ──────────────
    do_action( 'woocommerce_account_navigation' );
    ?>
    <div class="woocommerce-MyAccount-content">
        <?php do_action( 'woocommerce_account_content' ); ?>
    </div>
    <?php
    return;
}

// ── Dashboard: custom account UI ──────────────────────────────────────────

$user        = wp_get_current_user();
$plan        = statsight_get_user_plan();
$plan_labels = [
    'sharp' => 'Sharp',
    'pro'   => 'Pro',
    'free'  => 'Free',
];
$plan_label = $plan_labels[ $plan ] ?? 'Free';

// Pick record.
$pick_record = statsight_get_user_pick_record( $user->ID );

// Public watchlist opt-in.
$watchlist_public = (bool) get_user_meta( $user->ID, 'statsight_watchlist_public', true );

// Public pick record opt-in.
$record_public = (bool) get_user_meta( $user->ID, 'statsight_record_public', true );

// Public collections opt-in.
$collections_public = (bool) get_user_meta( $user->ID, 'statsight_collections_public', true );

// Notification rules — stored as JSON array in user meta.
$notif_rules_raw = get_user_meta( $user->ID, 'statsight_notif_rules', true );
$notif_rules     = ( $notif_rules_raw && is_string( $notif_rules_raw ) ) ? $notif_rules_raw : '[]';

// Sportsbook preferences — null means all books.
$active_books_raw = get_user_meta( $user->ID, 'statsight_active_books', true );
$active_books     = ( $active_books_raw && is_string( $active_books_raw ) )
    ? json_decode( $active_books_raw, true )
    : null;

// Deduplicate by label so aliases (e.g. williamhill_us → Caesars) don't show twice, then sort A–Z.
$all_books = [];
foreach ( statsight_get_book_labels() as $key => $label ) {
    if ( ! in_array( $label, $all_books, true ) ) {
        $all_books[ $key ] = $label;
    }
}
asort( $all_books );
?>

<div class="acct-page">

    <!-- ── Page header ──────────────────────────────────────────────────────── -->
    <div class="acct-hero container">
        <div class="acct-hero__inner">
            <div class="acct-avatar" aria-hidden="true">
                <?php echo esc_html( strtoupper( substr( $user->display_name ?: $user->user_login, 0, 1 ) ) ); ?>
            </div>
            <div class="acct-hero__meta">
                <div class="acct-hero__name-row">
                    <h1 class="acct-hero__name"><?php echo esc_html( $user->display_name ?: $user->user_login ); ?></h1>
                </div>
                <p class="acct-hero__email"><?php echo esc_html( $user->user_email ); ?></p>
            </div>
            <a href="<?php echo esc_url( wc_logout_url() ); ?>" class="acct-signout-btn">
                Sign Out
            </a>
        </div>
    </div>

    <!-- ── Main content ─────────────────────────────────────────────────────── -->
    <div class="acct-body container">

        <!-- Mobile tabs — hidden on desktop via CSS -->
        <div class="acct-tabs" id="acct-tabs" role="tablist">
            <button class="acct-tab acct-tab--active" data-tab="account" role="tab" aria-selected="true">Account</button>
            <button class="acct-tab" data-tab="alerts" role="tab" aria-selected="false">Alerts</button>
            <button class="acct-tab" data-tab="stats" role="tab" aria-selected="false">Stats</button>
        </div>

        <div class="acct-grid">

            <!-- ── Left column ────────────────────────────────────────────── -->
            <div class="acct-col acct-col--left">

                <!-- Plan card -->
                <section class="acct-card" id="acct-plan" data-acct-tab="account">
                    <h2 class="acct-card__title">Your Plan</h2>

                    <div class="acct-plan-info">
                        <div class="acct-info-item">
                            <span class="acct-label">Current Plan</span>
                            <span class="acct-value"><?php echo esc_html( $plan_label ); ?></span>
                        </div>
                        <p class="acct-plan-desc">
                            <?php if ( $plan === 'sharp' ) : ?>
                                You have full access to all xstatiq features including Watchlist, Parlay Builder, and live EV tracking.
                            <?php elseif ( $plan === 'pro' ) : ?>
                                You have access to Pro features including rest days, defense rankings, EV%, and Best Value props.
                            <?php else : ?>
                                You&rsquo;re on the free plan. Upgrade to unlock Pro and Sharp features.
                            <?php endif; ?>
                        </p>
                        <div class="acct-plan-actions">
                            <a href="<?php echo esc_url( wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) ) ); ?>" class="acct-btn acct-btn--primary">Manage Plan</a>
                        </div>
                    </div>

                    <?php if ( $plan !== 'free' ) : ?>
                        <div class="acct-plan-features">
                            <p class="acct-label">Included features</p>
                            <ul class="acct-feature-list">
                                <li>&#x2713; Props across all sports</li>
                                <li>&#x2713; Hit rate indicators</li>
                                <?php if ( in_array( $plan, [ 'pro', 'sharp' ], true ) ) : ?>
                                    <li>&#x2713; Rest days &amp; B2B detection</li>
                                    <li>&#x2713; Defense rankings</li>
                                    <li>&#x2713; EV% calculations</li>
                                    <li>&#x2713; Best Value props</li>
                                <?php endif; ?>
                                <?php if ( $plan === 'sharp' ) : ?>
                                    <li>&#x2713; Watchlist &amp; prop tracking</li>
                                    <li>&#x2713; Parlay builder</li>
                                    <li>&#x2713; Live odds history</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Account info card -->
                <section class="acct-card" id="acct-info" data-acct-tab="account">
                    <h2 class="acct-card__title">Account Info</h2>
                    <p class="acct-card__sub">To update your name, email, or password, visit your account settings.</p>

                    <div class="acct-info-grid">
                        <div class="acct-info-item">
                            <span class="acct-label">Display Name</span>
                            <span class="acct-value"><?php echo esc_html( $user->display_name ?: '—' ); ?></span>
                        </div>
                        <div class="acct-info-item">
                            <span class="acct-label">Email</span>
                            <span class="acct-value"><?php echo esc_html( $user->user_email ); ?></span>
                        </div>
                        <div class="acct-info-item">
                            <span class="acct-label">Username</span>
                            <span class="acct-value"><?php echo esc_html( $user->user_login ); ?></span>
                        </div>
                        <div class="acct-info-item">
                            <span class="acct-label">Member Since</span>
                            <span class="acct-value"><?php echo esc_html( gmdate( 'F j, Y', strtotime( $user->user_registered ) ) ); ?></span>
                        </div>
                    </div>

                    <a href="<?php echo esc_url( wc_get_endpoint_url( 'edit-account', '', wc_get_page_permalink( 'myaccount' ) ) ); ?>" class="acct-btn acct-btn--ghost">
                        Edit Account
                    </a>
                </section>

                <!-- Privacy settings card -->
                <section class="acct-card" id="acct-privacy" data-acct-tab="account">
                    <h2 class="acct-card__title">Privacy</h2>
                    <p class="acct-card__sub">Control what others can see on your public profile.</p>

                    <div class="acct-privacy-rows">

                        <div class="acct-privacy-row">
                            <div class="acct-privacy-row__info">
                                <span class="acct-privacy-row__label">Public Watchlist</span>
                                <span class="acct-privacy-row__desc">Let others follow you and see the props you're tracking.</span>
                            </div>
                            <div class="acct-privacy-row__control">
                                <label class="acct-toggle" for="watchlist-public-toggle">
                                    <input type="checkbox" id="watchlist-public-toggle" <?php echo $watchlist_public ? 'checked' : ''; ?>>
                                    <span class="acct-toggle__track"><span class="acct-toggle__thumb"></span></span>
                                </label>
                                <span class="acct-save-status" id="public-watchlist-status" aria-live="polite"></span>
                            </div>
                        </div>

                        <div class="acct-privacy-row">
                            <div class="acct-privacy-row__info">
                                <span class="acct-privacy-row__label">Pick Record</span>
                                <span class="acct-privacy-row__desc">Show your W/L record, tier badge, and appear on the leaderboard.</span>
                            </div>
                            <div class="acct-privacy-row__control">
                                <label class="acct-toggle" for="record-public-toggle">
                                    <input type="checkbox" id="record-public-toggle" <?php echo $record_public ? 'checked' : ''; ?>>
                                    <span class="acct-toggle__track"><span class="acct-toggle__thumb"></span></span>
                                </label>
                                <span class="acct-save-status" id="record-public-status" aria-live="polite"></span>
                            </div>
                        </div>

                        <div class="acct-privacy-row">
                            <div class="acct-privacy-row__info">
                                <span class="acct-privacy-row__label">Collections</span>
                                <span class="acct-privacy-row__desc">Show your saved parlay groups on your public profile.</span>
                            </div>
                            <div class="acct-privacy-row__control">
                                <label class="acct-toggle" for="collections-public-toggle">
                                    <input type="checkbox" id="collections-public-toggle" <?php echo $collections_public ? 'checked' : ''; ?>>
                                    <span class="acct-toggle__track"><span class="acct-toggle__thumb"></span></span>
                                </label>
                                <span class="acct-save-status" id="collections-public-status" aria-live="polite"></span>
                            </div>
                        </div>

                    </div>

                    <?php if ( $watchlist_public ) : ?>
                    <p class="acct-card__sub" style="margin-top:1rem;">
                        <a href="<?php echo esc_url( home_url( '/community' ) ); ?>">View Community &rarr;</a>
                    </p>
                    <?php endif; ?>
                </section>

                <!-- Sportsbook preferences card -->
                <section class="acct-card" id="acct-books" data-acct-tab="account">
                    <h2 class="acct-card__title">Sportsbooks</h2>
                    <p class="acct-card__sub">Choose which books to display odds columns for. Edge and EV calculations always use all books.</p>

                    <div class="book-prefs">
                        <label class="book-prefs__all">
                            <input type="checkbox" id="book-select-all" <?php echo $active_books === null ? 'checked' : ''; ?>>
                            <span>All books</span>
                        </label>
                        <div class="book-prefs__list" id="book-prefs-list">
                            <?php foreach ( $all_books as $key => $label ) :
                                $checked = $active_books === null || in_array( $key, $active_books, true );
                            ?>
                            <label class="book-prefs__item">
                                <input type="checkbox" class="book-pref-check" value="<?php echo esc_attr( $key ); ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                <span><?php echo esc_html( $label ); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="acct-form-footer">
                        <button class="acct-btn acct-btn--primary" id="acct-books-save" type="button">Save</button>
                        <span class="acct-save-status" id="acct-books-status" aria-live="polite"></span>
                    </div>
                </section>

            </div><!-- .acct-col--left -->

            <!-- ── Right column ───────────────────────────────────────────── -->
            <?php
            // Pick record variables.
            $pr_wins      = (int) $pick_record['wins'];
            $pr_losses    = (int) $pick_record['losses'];
            $pr_pushes    = (int) $pick_record['pushes'];
            $pr_total     = (int) $pick_record['total'];
            $pr_rate      = $pick_record['hit_rate'];
            $pr_roi       = $pick_record['roi'];
            $pr_decidable = $pr_wins + $pr_losses;
            $pr_profitable = $pr_roi !== null && $pr_roi > 0;
            if ( $pr_rate !== null ) {
                if ( $pr_rate >= 0.60 && $pr_decidable >= 20 && $pr_profitable ) {
                    $pr_tier = [ 'label' => 'Sharp',    'mod' => 'sharp' ];
                } elseif ( $pr_rate >= 0.55 && $pr_decidable >= 10 && $pr_profitable ) {
                    $pr_tier = [ 'label' => 'Solid',    'mod' => 'solid' ];
                } elseif ( $pr_rate >= 0.50 && $pr_decidable >= 10 ) {
                    $pr_tier = [ 'label' => 'Trending', 'mod' => 'trending' ];
                } else {
                    $pr_tier = [ 'label' => 'Rookie', 'mod' => 'rookie' ];
                }
            } else {
                $pr_tier = [ 'label' => 'Rookie', 'mod' => 'rookie' ];
            }
            $pr_profile_url = home_url( '/community/user/' . $user->ID . '/' );
            ?>
            <div class="acct-col acct-col--right">

                <!-- Push notification toggle card -->
                <section class="acct-card" id="acct-push" data-acct-tab="alerts">
                    <h2 class="acct-card__title">Push Notifications</h2>
                    <p class="acct-card__sub">Get instant alerts on your device — even when the app is closed.</p>
                    <div class="push-toggle-wrap">
                        <button class="push-toggle-btn" id="push-toggle-btn" type="button">
                            <span id="push-toggle-label">Enable Push Notifications</span>
                        </button>
                        <p class="push-toggle-status" id="push-toggle-status"></p>
                    </div>
                </section>

                <!-- Notification settings card -->
                <section class="acct-card" id="acct-notifications" data-acct-tab="alerts">
                    <h2 class="acct-card__title">Notification Rules</h2>
                    <p class="acct-card__sub">Get emailed when a prop hits your threshold. Each rule fires at most once per prop per day.</p>

                    <div class="notif-rules" id="notif-rules-list">
                        <!-- Rules injected by JS -->
                    </div>

                    <div class="notif-empty" id="notif-empty">
                        <p>No rules yet. Add one below to get started.</p>
                    </div>

                    <button class="notif-add-btn" id="notif-add-btn" type="button">
                        + Add Rule
                    </button>

                    <div class="acct-form-footer">
                        <button class="acct-btn acct-btn--primary" id="acct-notif-save" type="button">
                            Save Rules
                        </button>
                        <span class="acct-save-status" id="acct-notif-status" aria-live="polite"></span>
                    </div>
                </section>

                <!-- Prop Alerts card -->
                <?php if ( $plan !== 'free' ) : ?>
                <section class="acct-card" id="acct-prop-alerts" data-acct-tab="alerts">
                    <h2 class="acct-card__title">Prop Alerts</h2>
                    <p class="acct-card__sub">Alerts you&rsquo;ve set on individual props. Each fires once when the target odds are met.</p>
                    <div id="prop-alerts-list">
                        <p class="acct-loading">Loading&hellip;</p>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Pick record card -->
                <section class="acct-card" id="acct-record" data-acct-tab="stats">
                    <h2 class="acct-card__title">Pick Record</h2>
                    <p class="acct-card__sub">Your settled prop results across all sports.</p>

                    <div class="acct-record">
                        <div class="acct-record__stats">
                            <div class="acct-record__stat">
                                <span class="acct-record__num acct-record__num--win"><?php echo esc_html( $pr_wins ); ?></span>
                                <span class="acct-record__label">Wins</span>
                            </div>
                            <div class="acct-record__stat">
                                <span class="acct-record__num acct-record__num--loss"><?php echo esc_html( $pr_losses ); ?></span>
                                <span class="acct-record__label">Losses</span>
                            </div>
                            <?php if ( $pr_pushes > 0 ) : ?>
                            <div class="acct-record__stat">
                                <span class="acct-record__num"><?php echo esc_html( $pr_pushes ); ?></span>
                                <span class="acct-record__label">Pushes</span>
                            </div>
                            <?php endif; ?>
                            <?php if ( $pr_rate !== null ) : ?>
                            <div class="acct-record__stat">
                                <span class="acct-record__num"><?php echo esc_html( round( $pr_rate * 100, 1 ) . '%' ); ?></span>
                                <span class="acct-record__label">Hit Rate</span>
                            </div>
                            <?php endif; ?>
                            <?php if ( $pr_roi !== null ) : ?>
                            <div class="acct-record__stat">
                                <span class="acct-record__num <?php echo $pr_roi >= 0 ? 'acct-record__num--win' : 'acct-record__num--loss'; ?>">
                                    <?php echo esc_html( ( $pr_roi >= 0 ? '+' : '' ) . $pr_roi . '%' ); ?>
                                </span>
                                <span class="acct-record__label">ROI</span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="acct-record__tier">
                            <span class="pick-record__tier pick-record__tier--<?php echo esc_attr( $pr_tier['mod'] ); ?>">
                                <?php echo esc_html( $pr_tier['label'] ); ?>
                            </span>
                        </div>

                        <?php if ( $pr_decidable < 10 ) : ?>
                        <p class="acct-record__hint">
                            <?php
                            $needed = 10 - $pr_decidable;
                            echo esc_html( "Settle {$needed} more pick" . ( $needed !== 1 ? 's' : '' ) . ' to unlock your hit rate and tier.' );
                            ?>
                        </p>
                        <?php endif; ?>

                        <p class="acct-record__hint">
                            Only props saved <strong>before game start</strong> count toward your record.
                        </p>

                        <?php
                        if ( $pr_tier['mod'] !== 'sharp' ) :
                            if ( $pr_tier['mod'] === 'rookie' ) {
                                $target_label     = 'Trending';
                                $target_decidable = 10;
                                $target_rate      = 0.50;
                                $rate_pct         = $pr_rate !== null ? round( $pr_rate * 100, 1 ) : null;
                                $progress_pct     = min( 100, (int) round( ( $pr_decidable / $target_decidable ) * 100 ) );
                            } elseif ( $pr_tier['mod'] === 'trending' ) {
                                $target_label     = 'Solid';
                                $target_decidable = 10;
                                $target_rate      = 0.55;
                                $rate_pct         = $pr_rate !== null ? round( $pr_rate * 100, 1 ) : null;
                                $progress_pct     = $pr_rate !== null ? min( 100, (int) round( ( $pr_rate / $target_rate ) * 100 ) ) : 0;
                            } else {
                                $target_label     = 'Sharp';
                                $target_decidable = 20;
                                $target_rate      = 0.60;
                                $rate_pct         = $pr_rate !== null ? round( $pr_rate * 100, 1 ) : null;
                                $progress_pct     = $pr_rate !== null
                                    ? min( 100, (int) round( ( $pr_rate / $target_rate ) * 100 ) )
                                    : min( 100, (int) round( ( $pr_decidable / $target_decidable ) * 100 ) );
                            }
                        ?>
                        <div class="acct-tier-progress">
                            <div class="acct-tier-progress__header">
                                <span class="acct-tier-progress__label">Progress to <strong><?php echo esc_html( $target_label ); ?></strong></span>
                                <span class="acct-tier-progress__pct"><?php echo esc_html( $progress_pct . '%' ); ?></span>
                            </div>
                            <div class="acct-tier-progress__bar">
                                <div class="acct-tier-progress__fill" style="width:<?php echo esc_attr( $progress_pct ); ?>%"></div>
                            </div>
                            <ul class="acct-tier-progress__reqs">
                                <?php if ( $pr_tier['mod'] === 'rookie' ) : ?>
                                    <li class="<?php echo $pr_decidable >= 10 ? 'req--met' : ''; ?>">
                                        <?php echo $pr_decidable >= 10 ? '✓' : '○'; ?> <?php echo esc_html( $pr_decidable ); ?>/10 settled picks
                                    </li>
                                    <li class="<?php echo ( $pr_rate !== null && $pr_rate >= 0.50 ) ? 'req--met' : ''; ?>">
                                        <?php echo ( $pr_rate !== null && $pr_rate >= 0.50 ) ? '✓' : '○'; ?> ≥50% hit rate<?php echo $rate_pct !== null ? ' (' . esc_html( $rate_pct ) . '%)' : ''; ?>
                                    </li>
                                <?php elseif ( $pr_tier['mod'] === 'trending' ) : ?>
                                    <li class="req--met">✓ ≥10 settled picks (<?php echo esc_html( $pr_decidable ); ?>)</li>
                                    <li class="<?php echo ( $pr_rate !== null && $pr_rate >= 0.55 ) ? 'req--met' : ''; ?>">
                                        <?php echo ( $pr_rate !== null && $pr_rate >= 0.55 ) ? '✓' : '○'; ?> ≥55% hit rate<?php echo $rate_pct !== null ? ' (' . esc_html( $rate_pct ) . '%)' : ''; ?>
                                    </li>
                                    <li class="<?php echo $pr_profitable ? 'req--met' : ''; ?>">
                                        <?php echo $pr_profitable ? '✓' : '○'; ?> Positive ROI<?php echo $pr_roi !== null ? ' (' . esc_html( ( $pr_roi >= 0 ? '+' : '' ) . $pr_roi . '%' ) . ')' : ''; ?>
                                    </li>
                                <?php else : ?>
                                    <li class="<?php echo $pr_decidable >= 20 ? 'req--met' : ''; ?>">
                                        <?php echo $pr_decidable >= 20 ? '✓' : '○'; ?> <?php echo esc_html( $pr_decidable ); ?>/20 settled picks
                                    </li>
                                    <li class="<?php echo ( $pr_rate !== null && $pr_rate >= 0.60 ) ? 'req--met' : ''; ?>">
                                        <?php echo ( $pr_rate !== null && $pr_rate >= 0.60 ) ? '✓' : '○'; ?> ≥60% hit rate<?php echo $rate_pct !== null ? ' (' . esc_html( $rate_pct ) . '%)' : ''; ?>
                                    </li>
                                    <li class="<?php echo $pr_profitable ? 'req--met' : ''; ?>">
                                        <?php echo $pr_profitable ? '✓' : '○'; ?> Positive ROI<?php echo $pr_roi !== null ? ' (' . esc_html( ( $pr_roi >= 0 ? '+' : '' ) . $pr_roi . '%' ) . ')' : ''; ?>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <?php
                        $sport_breakdown = statsight_get_user_pick_record_by_sport( $user->ID );
                        $sport_labels    = [
                            'basketball_nba'         => 'NBA',
                            'americanfootball_nfl'   => 'NFL',
                            'baseball_mlb'           => 'MLB',
                            'icehockey_nhl'          => 'NHL',
                            'basketball_ncaab'       => 'NCAAB',
                            'americanfootball_ncaaf' => 'NCAAF',
                            'mma_mixed_martial_arts' => 'MMA',
                            'soccer_epl'             => 'EPL',
                            'soccer_usa_mls'         => 'MLS',
                        ];
                        if ( count( $sport_breakdown ) > 0 ) :
                        ?>
                        <details class="acct-sport-breakdown">
                            <summary class="acct-sport-breakdown__toggle">By Sport</summary>
                            <table class="acct-sport-breakdown__table">
                                <thead>
                                    <tr>
                                        <th>Sport</th><th>W</th><th>L</th><th>Push</th><th>Hit%</th><th>ROI</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $sport_breakdown as $row ) :
                                        $s_label   = $sport_labels[ $row['sport'] ] ?? ucwords( str_replace( '_', ' ', $row['sport'] ) );
                                        $s_rate    = $row['hit_rate'] !== null ? round( $row['hit_rate'] * 100, 1 ) . '%' : '—';
                                        $s_roi     = $row['roi'] !== null ? ( $row['roi'] >= 0 ? '+' : '' ) . $row['roi'] . '%' : '—';
                                        $s_roi_cls = $row['roi'] !== null ? ( $row['roi'] > 0 ? 'sport-roi--pos' : ( $row['roi'] < 0 ? 'sport-roi--neg' : '' ) ) : '';
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html( $s_label ); ?></td>
                                        <td class="sport-w"><?php echo esc_html( $row['wins'] ); ?></td>
                                        <td class="sport-l"><?php echo esc_html( $row['losses'] ); ?></td>
                                        <td><?php echo esc_html( $row['pushes'] ); ?></td>
                                        <td><?php echo esc_html( $s_rate ); ?></td>
                                        <td class="<?php echo esc_attr( $s_roi_cls ); ?>"><?php echo esc_html( $s_roi ); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                        <?php endif; ?>
                    </div>

                    <a href="<?php echo esc_url( $pr_profile_url ); ?>" class="acct-btn acct-btn--ghost">
                        View My Profile &rarr;
                    </a>
                </section>

                <!-- Following card -->
                <section class="acct-card" id="acct-following" data-acct-tab="stats">
                    <h2 class="acct-card__title">Following</h2>
                    <p class="acct-card__sub">Users whose public watchlists you&rsquo;re following.</p>
                    <div id="acct-following-list">
                        <p class="acct-loading">Loading&hellip;</p>
                    </div>
                    <div id="acct-following-footer" hidden></div>
                </section>

                <!-- Delete account card -->
                <section class="acct-card acct-card--danger" id="acct-danger" data-acct-tab="account">
                    <p class="acct-danger-label">Delete Account</p>
                    <p class="acct-danger-desc">
                        Schedules your account for deletion in 30 days. You can reactivate within that window via the link in your confirmation email.
                        <?php if ( $plan !== 'free' ) : ?>
                            Your <?php echo esc_html( $plan_label ); ?> plan will be cancelled immediately.
                        <?php endif; ?>
                    </p>
                    <div class="acct-form-footer">
                        <button class="acct-btn acct-btn--danger" id="acct-delete-btn">
                            Delete My Account
                        </button>
                    </div>
                </section>

            </div><!-- .acct-col--right -->

        </div><!-- .acct-grid -->

    </div><!-- .acct-body -->
</div><!-- .acct-page -->

<!-- ── Delete account confirmation modal ──────────────────────────────────── -->
<div class="acct-modal-overlay" id="acct-delete-modal" hidden aria-modal="true" role="dialog" aria-labelledby="acct-delete-modal-title">
    <div class="acct-modal">
        <h3 class="acct-modal__title" id="acct-delete-modal-title">Delete your account?</h3>
        <p class="acct-modal__body">
            Your account will be <strong>scheduled for deletion in 30 days</strong>. During that time your data is preserved and you can reactivate via the link in your confirmation email.
            <?php if ( $plan !== 'free' ) : ?>
                Your <?php echo esc_html( $plan_label ); ?> plan will be cancelled immediately.
            <?php endif; ?>
        </p>
        <p class="acct-modal__confirm-label">Type <strong>DELETE</strong> to confirm:</p>
        <input
            type="text"
            class="acct-modal__input"
            id="acct-delete-confirm"
            placeholder="DELETE"
            autocomplete="off"
            spellcheck="false"
        >
        <div class="acct-modal__actions">
            <button class="acct-btn acct-btn--ghost" id="acct-delete-cancel">Cancel</button>
            <button class="acct-btn acct-btn--danger" id="acct-delete-confirm-btn" disabled>Delete Account</button>
        </div>
        <p class="acct-modal__error" id="acct-delete-error" hidden></p>
    </div>
</div>

<script>
(function () {
    'use strict';

    // ── Mobile account tabs ───────────────────────────────────────────────────
    const tabs     = document.querySelectorAll('.acct-tab');
    const cards    = document.querySelectorAll('[data-acct-tab]');
    const MOBILE   = () => window.innerWidth < 900;
    let activeTab  = 'account';

    function applyTabs() {
        if (!MOBILE()) {
            cards.forEach(c => c.style.display = '');
            return;
        }
        cards.forEach(c => {
            c.style.display = c.dataset.acctTab === activeTab ? '' : 'none';
        });
    }

    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            activeTab = this.dataset.tab;
            tabs.forEach(t => {
                t.classList.toggle('acct-tab--active', t.dataset.tab === activeTab);
                t.setAttribute('aria-selected', t.dataset.tab === activeTab ? 'true' : 'false');
            });
            applyTabs();
        });
    });

    applyTabs();
    window.addEventListener('resize', applyTabs);

    const nonce    = <?php echo wp_json_encode( wp_create_nonce( 'statsight_account' ) ); ?>;
    const ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
    const swNonce  = <?php echo wp_json_encode( wp_create_nonce( 'statsight_events' ) ); ?>;
    const vapidKey = <?php echo wp_json_encode( defined( 'STATSIGHT_VAPID_PUBLIC' ) ? STATSIGHT_VAPID_PUBLIC : '' ); ?>;

    // ── Push notification toggle ───────────────────────────────────────────
    (function () {
        const btn    = document.getElementById('push-toggle-btn');
        const label  = document.getElementById('push-toggle-label');
        const status = document.getElementById('push-toggle-status');

        if (!btn || !('serviceWorker' in navigator) || !('PushManager' in window)) {
            if (btn) btn.disabled = true;
            if (status) status.textContent = 'Push notifications are not supported in this browser.';
            return;
        }

        function urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const raw     = atob(base64);
            return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
        }

        function updateUI(subscribed) {
            label.textContent = subscribed ? 'Disable Push Notifications' : 'Enable Push Notifications';
            btn.classList.toggle('push-toggle-btn--active', subscribed);
            status.textContent = subscribed ? 'Push notifications are enabled on this device.' : '';
        }

        function postAjax(action, body) {
            return fetch(ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ action, nonce: swNonce, ...body }).toString(),
            }).then(r => r.json());
        }

        // Check current state.
        navigator.serviceWorker.ready.then(reg => {
            reg.pushManager.getSubscription().then(sub => updateUI(!!sub));
        });

        btn.addEventListener('click', async () => {
            btn.disabled = true;
            try {
                const reg = await navigator.serviceWorker.ready;
                const sub = await reg.pushManager.getSubscription();

                if (sub) {
                    // Unsubscribe.
                    await postAjax('statsight_push_unsubscribe', { endpoint: sub.endpoint });
                    await sub.unsubscribe();
                    updateUI(false);
                } else {
                    // Subscribe — requires VAPID public key.
                    if (!vapidKey) {
                        status.textContent = 'Push notifications are not configured yet.';
                        return;
                    }
                    const newSub = await reg.pushManager.subscribe({
                        userVisibleOnly:      true,
                        applicationServerKey: urlBase64ToUint8Array(vapidKey),
                    });
                    const key  = newSub.getKey('p256dh');
                    const auth = newSub.getKey('auth');
                    await postAjax('statsight_push_subscribe', {
                        endpoint: newSub.endpoint,
                        p256dh:   btoa(String.fromCharCode(...new Uint8Array(key))),
                        auth:     btoa(String.fromCharCode(...new Uint8Array(auth))),
                    });
                    updateUI(true);
                }
            } catch (err) {
                status.textContent = err.name === 'NotAllowedError'
                    ? 'Permission denied. Enable notifications in your browser settings.'
                    : 'Something went wrong: ' + err.message;
            } finally {
                btn.disabled = false;
            }
        });
    }());

    // ── Notification rule builder ──────────────────────────────────────────

    const RULE_TYPES = [
        {
            key:       'arbitrage',
            label:     'Arbitrage opportunity',
            desc:      'Arb % exceeds threshold',
            threshold: { label: 'Arb %', unit: '%', min: 1, max: 100, default: 5 },
            sport:     true,
        },
        {
            key:       'best_value',
            label:     'Best Value edge',
            desc:      'Prop edge exceeds threshold',
            threshold: { label: 'Edge', unit: 'pts', min: 1, max: 2000, default: 100 },
            sport:     true,
        },
        {
            key:       'line_move',
            label:     'Line moves on watchlist prop',
            desc:      'A saved prop moves by threshold',
            threshold: { label: 'Move', unit: 'pts', min: 0.5, max: 10, default: 1, step: 0.5 },
            sport:     false,
        },
        {
            key:       'ev_threshold',
            label:     'EV% threshold',
            desc:      'Prop EV% exceeds threshold',
            threshold: { label: 'EV%', unit: '%', min: 1, max: 50, default: 5 },
            sport:     true,
        },
        {
            key:       'watchlist_result',
            label:     'Watchlist prop settles',
            desc:      'A tracked prop hits or misses',
            threshold: null,
            sport:     false,
            outcome:   true,
        },
        {
            key:       'daily_digest',
            label:     'Daily Best Value digest',
            desc:      'Morning summary of top-edge props',
            threshold: null,
            sport:     true,
        },
    ];

    const SPORTS = [
        { key: 'all',                    label: 'All Sports' },
        { key: 'basketball_nba',         label: 'NBA' },
        { key: 'basketball_wnba',        label: 'WNBA' },
        { key: 'americanfootball_nfl',   label: 'NFL' },
        { key: 'baseball_mlb',           label: 'MLB' },
        { key: 'icehockey_nhl',          label: 'NHL' },
        { key: 'basketball_ncaab',       label: 'NCAAB' },
        { key: 'americanfootball_ncaaf', label: 'NCAAF' },
        { key: 'mma_mixed_martial_arts', label: 'MMA' },
        { key: 'soccer_epl',             label: 'EPL' },
        { key: 'soccer_usa_mls',         label: 'MLS' },
    ];

    const rulesList  = document.getElementById('notif-rules-list');
    const emptyState = document.getElementById('notif-empty');
    const addBtn     = document.getElementById('notif-add-btn');
    const saveBtn    = document.getElementById('acct-notif-save');
    const saveStatus = document.getElementById('acct-notif-status');

    let rules  = <?php echo wp_json_encode( json_decode( $notif_rules, true ) ?: [] ); ?>;
    let nextId = Date.now();

    function getType(key) {
        return RULE_TYPES.find(t => t.key === key) || RULE_TYPES[0];
    }

    function buildTypeOptions(selected) {
        return RULE_TYPES.map(t =>
            `<option value="${t.key}" ${t.key === selected ? 'selected' : ''}>${t.label}</option>`
        ).join('');
    }

    function buildSportOptions(selected) {
        return SPORTS.map(s =>
            `<option value="${s.key}" ${s.key === selected ? 'selected' : ''}>${s.label}</option>`
        ).join('');
    }

    function renderThreshold(type, value) {
        if (!type.threshold) return '';
        const t    = type.threshold;
        const step = t.step || 1;
        return `
            <div class="notif-rule__field">
                <label class="notif-rule__field-label">${t.label}</label>
                <div class="notif-rule__input-wrap">
                    <div class="num-stepper">
                        <button class="num-stepper__btn" type="button" data-step="-${step}" aria-label="Decrease">&#x2212;</button>
                        <input
                            type="number"
                            class="notif-rule__input notif-threshold"
                            value="${value ?? t.default}"
                            min="${t.min}"
                            max="${t.max}"
                            step="${step}"
                            aria-label="${t.label}"
                        >
                        <button class="num-stepper__btn" type="button" data-step="${step}" aria-label="Increase">&#x2B;</button>
                    </div>
                    <span class="notif-rule__unit">${t.unit}</span>
                </div>
            </div>`;
    }

    function renderSport(type, value) {
        if (!type.sport) return '';
        return `
            <div class="notif-rule__field">
                <label class="notif-rule__field-label">Sport</label>
                <select class="notif-rule__select notif-sport" aria-label="Sport">
                    ${buildSportOptions(value || 'all')}
                </select>
            </div>`;
    }

    function renderOutcome(type, value) {
        if (!type.outcome) return '';
        return `
            <div class="notif-rule__field">
                <label class="notif-rule__field-label">Outcome</label>
                <select class="notif-rule__select notif-outcome" aria-label="Outcome">
                    <option value="any"  ${(value || 'any') === 'any'  ? 'selected' : ''}>Win or Loss</option>
                    <option value="win"  ${value === 'win'             ? 'selected' : ''}>Win only</option>
                    <option value="loss" ${value === 'loss'            ? 'selected' : ''}>Loss only</option>
                </select>
            </div>`;
    }

    function renderRule(rule) {
        const type = getType(rule.type);
        const el   = document.createElement('div');
        el.className  = 'notif-rule';
        el.dataset.id = rule.id;
        el.innerHTML  = `
            <div class="notif-rule__summary">
                <div class="notif-rule__field notif-rule__field--type">
                    <label class="notif-rule__field-label">Notify me when</label>
                    <select class="notif-rule__select notif-type" aria-label="Notification type">
                        ${buildTypeOptions(rule.type)}
                    </select>
                </div>
                ${renderThreshold(type, rule.threshold)}
                ${renderSport(type, rule.sport)}
                ${renderOutcome(type, rule.outcome)}
                <button class="notif-rule__remove" aria-label="Remove rule" title="Remove">&#x2715;</button>
            </div>`;

        el.querySelector('.notif-type').addEventListener('change', function () {
            const newType = getType(this.value);
            el.querySelectorAll('.notif-rule__field:not(.notif-rule__field--type)').forEach(f => f.remove());
            const removeBtn     = el.querySelector('.notif-rule__remove');
            const thresholdHtml = renderThreshold(newType, newType.threshold?.default ?? null);
            const sportHtml     = renderSport(newType, 'all');
            const outcomeHtml   = renderOutcome(newType, 'any');
            removeBtn.insertAdjacentHTML('beforebegin', thresholdHtml + sportHtml + outcomeHtml);
        });

        el.querySelector('.notif-rule__remove').addEventListener('click', function () {
            rules = rules.filter(r => r.id !== rule.id);
            el.classList.add('notif-rule--removing');
            el.addEventListener('animationend', function () {
                el.remove();
                updateEmpty();
            }, { once: true });
        });

        return el;
    }

    function getRuleValues(el) {
        const typeEl      = el.querySelector('.notif-type');
        const thresholdEl = el.querySelector('.notif-threshold');
        const sportEl     = el.querySelector('.notif-sport');
        const outcomeEl   = el.querySelector('.notif-outcome');
        return {
            id:        el.dataset.id,
            type:      typeEl?.value || 'arbitrage',
            threshold: thresholdEl ? parseFloat(thresholdEl.value) : null,
            sport:     sportEl?.value || 'all',
            outcome:   outcomeEl?.value || null,
        };
    }

    function updateEmpty() {
        emptyState.hidden = rulesList.children.length > 0;
    }

    function renderAll() {
        rulesList.innerHTML = '';
        rules.forEach(rule => rulesList.appendChild(renderRule(rule)));
        updateEmpty();
    }

    addBtn.addEventListener('click', function () {
        const newRule = { id: String(nextId++), type: 'arbitrage', threshold: 5, sport: 'all' };
        rules.push(newRule);
        const el = renderRule(newRule);
        rulesList.appendChild(el);
        updateEmpty();
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    saveBtn.addEventListener('click', function () {
        const current = Array.from(rulesList.querySelectorAll('.notif-rule')).map(getRuleValues);

        saveBtn.disabled      = true;
        saveStatus.textContent = '';
        saveStatus.className  = 'acct-save-status';

        const data = new URLSearchParams({
            action: 'statsight_save_notifications',
            nonce:  nonce,
            rules:  JSON.stringify(current),
        });

        fetch(ajaxUrl + '?' + data.toString())
            .then(r => r.json())
            .then(function (json) {
                saveBtn.disabled = false;
                if (json.success) {
                    rules = current;
                    saveStatus.textContent = 'Saved.';
                    saveStatus.classList.add('acct-save-status--ok');
                } else {
                    saveStatus.textContent = 'Failed to save. Please try again.';
                    saveStatus.classList.add('acct-save-status--err');
                }
            })
            .catch(function () {
                saveBtn.disabled       = false;
                saveStatus.textContent = 'Network error. Please try again.';
                saveStatus.classList.add('acct-save-status--err');
            });
    });

    renderAll();

    // ── Sportsbook preferences ────────────────────────────────────────────
    (function () {
        const selectAllChk = document.getElementById('book-select-all');
        const bookChecks   = document.querySelectorAll('.book-pref-check');
        const bookSaveBtn  = document.getElementById('acct-books-save');
        const bookStatus   = document.getElementById('acct-books-status');

        if (!selectAllChk) return;

        function syncSelectAll() {
            const total   = bookChecks.length;
            const checked = Array.from(bookChecks).filter(c => c.checked).length;
            selectAllChk.checked       = checked === total;
            selectAllChk.indeterminate = checked > 0 && checked < total;
        }

        selectAllChk.addEventListener('change', function () {
            bookChecks.forEach(c => { c.checked = this.checked; });
        });

        bookChecks.forEach(c => c.addEventListener('change', syncSelectAll));

        syncSelectAll();

        bookSaveBtn.addEventListener('click', function () {
            const checked = Array.from(bookChecks).filter(c => c.checked).map(c => c.value);
            if (checked.length === 0) {
                bookStatus.textContent = 'Select at least one book.';
                bookStatus.className   = 'acct-save-status acct-save-status--err';
                return;
            }

            const allSelected = checked.length === bookChecks.length;
            const books       = allSelected ? 'null' : JSON.stringify(checked);

            bookSaveBtn.disabled   = true;
            bookStatus.textContent = '';
            bookStatus.className   = 'acct-save-status';

            fetch(ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ action: 'statsight_save_active_books', nonce, books }),
            })
                .then(r => r.json())
                .then(function (json) {
                    bookSaveBtn.disabled = false;
                    if (json.success) {
                        bookStatus.textContent = 'Saved.';
                        bookStatus.classList.add('acct-save-status--ok');
                    } else {
                        bookStatus.textContent = 'Failed to save. Please try again.';
                        bookStatus.classList.add('acct-save-status--err');
                    }
                })
                .catch(function () {
                    bookSaveBtn.disabled   = false;
                    bookStatus.textContent = 'Network error. Please try again.';
                    bookStatus.classList.add('acct-save-status--err');
                });
        });
    }());

    // ── Custom stepper buttons ─────────────────────────────────────────────
    document.getElementById('notif-rules-list').addEventListener('click', function (e) {
        const btn = e.target.closest('.num-stepper__btn');
        if (!btn) return;
        const input = btn.closest('.num-stepper').querySelector('input[type="number"]');
        if (!input) return;
        const step  = parseFloat(btn.dataset.step);
        const min   = parseFloat(input.min);
        const max   = parseFloat(input.max);
        const val   = parseFloat(input.value) || 0;
        input.value = Math.min(max, Math.max(min, val + step));
    });

    // ── Delete account modal ───────────────────────────────────────────────

    const deleteBtn    = document.getElementById('acct-delete-btn');
    const modal        = document.getElementById('acct-delete-modal');
    const cancelBtn    = document.getElementById('acct-delete-cancel');
    const confirmInput = document.getElementById('acct-delete-confirm');
    const confirmBtn   = document.getElementById('acct-delete-confirm-btn');
    const errorMsg     = document.getElementById('acct-delete-error');

    function openModal() {
        modal.removeAttribute('hidden');
        requestAnimationFrame(function () { modal.classList.add('is-open'); });
        document.body.style.overflow = 'hidden';
        confirmInput.value  = '';
        confirmBtn.disabled = true;
        errorMsg.hidden     = true;
        setTimeout(function () { confirmInput.focus(); }, 50);
    }

    function closeModal() {
        modal.classList.remove('is-open');
        document.body.style.overflow = '';
        modal.addEventListener('transitionend', function hide() {
            modal.setAttribute('hidden', '');
            modal.removeEventListener('transitionend', hide);
        });
    }

    deleteBtn.addEventListener('click', openModal);
    cancelBtn.addEventListener('click', closeModal);

    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hasAttribute('hidden')) closeModal();
    });

    confirmInput.addEventListener('input', function () {
        confirmBtn.disabled = confirmInput.value.trim() !== 'DELETE';
    });

    confirmBtn.addEventListener('click', function () {
        if (confirmInput.value.trim() !== 'DELETE') return;

        confirmBtn.disabled    = true;
        confirmBtn.textContent = 'Scheduling deletion…';
        errorMsg.hidden        = true;

        const params = new URLSearchParams({
            action: 'statsight_delete_account',
            nonce:  nonce,
        });

        fetch(ajaxUrl + '?' + params.toString(), { method: 'POST' })
            .then(r => r.json())
            .then(function (json) {
                if (json.success) {
                    modal.innerHTML = `<div class="acct-modal" style="text-align:center">
                        <p style="font-size:1.25rem;font-weight:700;margin:0 0 12px;">Deletion scheduled</p>
                        <p style="color:rgba(240,242,247,0.7);margin:0 0 24px;">Your account will be deleted on <strong style="color:#f0f2f7">${json.data?.deletion_date ?? 'in 30 days'}</strong>. Check your email for a reactivation link.</p>
                        <p style="color:rgba(240,242,247,0.5);font-size:0.85rem;">You have been logged out.</p>
                    </div>`;
                    setTimeout(function () {
                        window.location.href = <?php echo wp_json_encode( home_url( '/' ) ); ?>;
                    }, 4000);
                } else {
                    confirmBtn.disabled    = false;
                    confirmBtn.textContent = 'Delete Account';
                    errorMsg.textContent   = json.data?.message || 'Something went wrong. Please try again.';
                    errorMsg.hidden        = false;
                }
            })
            .catch(function () {
                confirmBtn.disabled    = false;
                confirmBtn.textContent = 'Delete Account';
                errorMsg.textContent   = 'Request failed. Please try again.';
                errorMsg.hidden        = false;
            });
    });

    // ── Public watchlist toggle ───────────────────────────────────────────────
    (function () {
        const toggle = document.getElementById('watchlist-public-toggle');
        const status = document.getElementById('public-watchlist-status');
        const label  = toggle?.closest('.acct-toggle')?.querySelector('.acct-toggle__label');
        if (!toggle) return;

        toggle.addEventListener('change', function () {
            const isPublic = toggle.checked;
            if (label) label.textContent = isPublic ? 'Your watchlist is public' : 'Your watchlist is private';
            status.textContent = '';
            status.className   = 'acct-save-status';

            fetch(ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ action: 'statsight_set_public_watchlist', nonce, public: isPublic ? 1 : 0 }),
            })
                .then(r => r.json())
                .then(function (json) {
                    if (json.success) {
                        status.textContent = 'Saved.';
                        status.classList.add('acct-save-status--ok');
                    } else {
                        toggle.checked     = !isPublic;
                        status.textContent = 'Failed to save.';
                        status.classList.add('acct-save-status--err');
                    }
                })
                .catch(function () {
                    toggle.checked     = !isPublic;
                    status.textContent = 'Network error.';
                    status.classList.add('acct-save-status--err');
                });
        });
    }());

    // ── Pick record public toggle ─────────────────────────────────────────────
    (function () {
        const toggle = document.getElementById('record-public-toggle');
        const status = document.getElementById('record-public-status');
        const label  = toggle?.closest('.acct-toggle')?.querySelector('.acct-toggle__label');
        if (!toggle) return;

        toggle.addEventListener('change', function () {
            const isPublic = toggle.checked;
            if (label) label.textContent = isPublic ? 'Your record is public' : 'Your record is private';
            status.textContent = '';
            status.className   = 'acct-save-status';

            fetch(ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ action: 'statsight_set_record_public', nonce, public: isPublic ? 1 : 0 }),
            })
                .then(r => r.json())
                .then(function (json) {
                    if (json.success) {
                        status.textContent = 'Saved.';
                        status.classList.add('acct-save-status--ok');
                    } else {
                        toggle.checked     = !isPublic;
                        if (label) label.textContent = !isPublic ? 'Your record is public' : 'Your record is private';
                        status.textContent = 'Failed to save.';
                        status.classList.add('acct-save-status--err');
                    }
                })
                .catch(function () {
                    toggle.checked     = !isPublic;
                    if (label) label.textContent = !isPublic ? 'Your record is public' : 'Your record is private';
                    status.textContent = 'Network error.';
                    status.classList.add('acct-save-status--err');
                });
        });
    }());

    // ── Collections public toggle ─────────────────────────────────────────────
    (function () {
        const toggle = document.getElementById('collections-public-toggle');
        const status = document.getElementById('collections-public-status');
        const label  = toggle?.closest('.acct-toggle')?.querySelector('.acct-toggle__label');
        if (!toggle) return;

        toggle.addEventListener('change', function () {
            const isPublic = toggle.checked;
            if (label) label.textContent = isPublic ? 'Your collections are public' : 'Your collections are private';
            status.textContent = '';
            status.className   = 'acct-save-status';

            fetch(ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ action: 'statsight_set_collections_public', nonce, public: isPublic ? 1 : 0 }),
            })
                .then(r => r.json())
                .then(function (json) {
                    if (json.success) {
                        status.textContent = 'Saved.';
                        status.classList.add('acct-save-status--ok');
                    } else {
                        toggle.checked     = !isPublic;
                        if (label) label.textContent = !isPublic ? 'Your collections are public' : 'Your collections are private';
                        status.textContent = 'Failed to save.';
                        status.classList.add('acct-save-status--err');
                    }
                })
                .catch(function () {
                    toggle.checked     = !isPublic;
                    if (label) label.textContent = !isPublic ? 'Your collections are public' : 'Your collections are private';
                    status.textContent = 'Network error.';
                    status.classList.add('acct-save-status--err');
                });
        });
    }());

    // ── Prop Alerts ───────────────────────────────────────────────────────────
    (function () {
        const container = document.getElementById('prop-alerts-list');
        if (!container) return;

        function escHtml(str) {
            return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function formatOdds(n) {
            n = parseInt(n, 10);
            return n > 0 ? '+' + n : String(n);
        }

        function loadAlerts() {
            fetch(ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ action: 'statsight_prop_alert_get', nonce }),
            })
                .then(r => r.json())
                .then(function (json) {
                    if (!json.success || !json.data.length) {
                        container.innerHTML = '<p class="acct-muted">No active prop alerts.</p>';
                        return;
                    }
                    const rows = json.data.map(function (a) {
                        const dir   = a.direction === 'under' ? 'Under' : 'Over';
                        const label = a.market_label || a.market_key;
                        return `<tr>
                            <td>${escHtml(a.player)}</td>
                            <td>${escHtml(label)}</td>
                            <td>${escHtml(a.line)} ${escHtml(dir)}</td>
                            <td>${escHtml(formatOdds(a.target_odds))}</td>
                            <td>${escHtml(a.matchup)}</td>
                            <td>
                                <button class="prop-alert-remove-btn" data-id="${escHtml(a.id)}" aria-label="Remove alert">
                                    &times;
                                </button>
                            </td>
                        </tr>`;
                    }).join('');
                    container.innerHTML = `
                        <table class="prop-alerts-table">
                            <thead>
                                <tr>
                                    <th>Player</th>
                                    <th>Market</th>
                                    <th>Line</th>
                                    <th>Target</th>
                                    <th>Matchup</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>${rows}</tbody>
                        </table>`;
                })
                .catch(function () {
                    container.innerHTML = '<p class="acct-muted">Failed to load alerts.</p>';
                });
        }

        container.addEventListener('click', function (e) {
            const btn = e.target.closest('.prop-alert-remove-btn');
            if (!btn) return;
            const id = btn.dataset.id;
            btn.disabled = true;
            fetch(ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ action: 'statsight_prop_alert_delete', nonce, id }),
            })
                .then(r => r.json())
                .then(function (json) {
                    if (json.success) {
                        btn.closest('tr').remove();
                        const tbody = container.querySelector('tbody');
                        if (tbody && !tbody.children.length) {
                            container.innerHTML = '<p class="acct-muted">No active prop alerts.</p>';
                        }
                    } else {
                        btn.disabled = false;
                    }
                })
                .catch(function () { btn.disabled = false; });
        });

        loadAlerts();
    }());

    // ── Following list ────────────────────────────────────────────────────────
    (function () {
        const container   = document.getElementById('acct-following-list');
        const footer      = document.getElementById('acct-following-footer');
        const profileBase = <?php echo wp_json_encode( home_url( '/community/user/' ) ); ?>;
        const communityUrl = <?php echo wp_json_encode( home_url( '/community/' ) ); ?>;
        const VISIBLE     = 3;
        if (!container) return;

        let allUsers    = [];
        let filtered    = [];
        let showingAll  = false;
        let searchInput = null;

        function escHtml(str) {
            return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function renderRows(users) {
            container.querySelectorAll('.acct-following-row').forEach(r => r.remove());
            const visible = showingAll ? users : users.slice(0, VISIBLE);
            visible.forEach(function (u) {
                const row = document.createElement('div');
                row.className = 'acct-following-row';
                row.dataset.userId = u.user_id;
                row.innerHTML = `
                    <a href="${escHtml(profileBase + u.user_id + '/')}" class="acct-following-row__name">${escHtml(u.display_name)}</a>
                    <button class="acct-btn acct-btn--ghost acct-btn--sm acct-unfollow-btn" data-user-id="${escHtml(u.user_id)}">Unfollow</button>`;
                container.appendChild(row);
            });
        }

        function renderFooter(users) {
            footer.hidden = false;
            footer.innerHTML = '';

            if (users.length === 0 && searchInput?.value.trim()) {
                return;
            }

            const extra = users.length - VISIBLE;

            if (users.length > VISIBLE) {
                const toggle = document.createElement('button');
                toggle.className   = 'acct-following-toggle';
                toggle.textContent = showingAll ? 'Show less' : `Show all ${users.length}`;
                toggle.addEventListener('click', function () {
                    showingAll = !showingAll;
                    renderRows(filtered);
                    renderFooter(filtered);
                });
                footer.appendChild(toggle);
            }
        }

        function applyFilter(q) {
            filtered   = q
                ? allUsers.filter(u => u.display_name.toLowerCase().includes(q.toLowerCase()))
                : allUsers;
            showingAll = !!q; // show all results when searching
            renderRows(filtered);
            renderFooter(filtered);
        }

        function renderAll(users) {
            container.innerHTML = '';
            footer.hidden       = false;
            footer.innerHTML    = '';

            if (!users.length) {
                container.innerHTML = `<p class="acct-muted">You&rsquo;re not following anyone yet. <a href="${escHtml(communityUrl)}">Discover users &rarr;</a></p>`;
                return;
            }

            // Search input — only show if more than VISIBLE users.
            if (users.length > VISIBLE) {
                const wrap  = document.createElement('div');
                wrap.className = 'acct-following-search';
                searchInput = document.createElement('input');
                searchInput.type        = 'search';
                searchInput.placeholder = 'Search following…';
                searchInput.className   = 'acct-following-search__input';
                searchInput.autocomplete = 'off';
                searchInput.addEventListener('input', function () {
                    applyFilter(searchInput.value.trim());
                });
                wrap.appendChild(searchInput);
                container.appendChild(wrap);
            }

            filtered = users;
            renderRows(users);
            renderFooter(users);
        }

        fetch(ajaxUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    new URLSearchParams({ action: 'statsight_get_following', nonce }),
        })
            .then(r => r.json())
            .then(function (json) {
                if (!json.success) {
                    container.innerHTML = '<p class="acct-muted">Failed to load.</p>';
                    return;
                }
                allUsers = json.data;
                renderAll(allUsers);
            })
            .catch(function () {
                container.innerHTML = '<p class="acct-muted">Failed to load.</p>';
            });

        container.addEventListener('click', function (e) {
            const btn = e.target.closest('.acct-unfollow-btn');
            if (!btn) return;
            const userId = btn.dataset.userId;
            btn.disabled = true;
            fetch(ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ action: 'statsight_follow', nonce, user_id: userId, action_type: 'unfollow' }),
            })
                .then(r => r.json())
                .then(function (json) {
                    if (json.success) {
                        allUsers  = allUsers.filter(u => String(u.user_id) !== String(userId));
                        filtered  = filtered.filter(u => String(u.user_id) !== String(userId));
                        if (!allUsers.length) {
                            renderAll([]);
                        } else {
                            renderRows(filtered);
                            renderFooter(filtered);
                        }
                    } else {
                        btn.disabled = false;
                    }
                })
                .catch(function () { btn.disabled = false; });
        });
    }());
}());
</script>
