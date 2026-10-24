<?php
/**
 * Template Name: Ask AI
 *
 * Sports questions answered by the AI analyst from our own data: game logs, the model's projections and track
 * record, and live odds across books (inc/ask-ai.php).
 */

statsight_require_plan_redirect( 'pro' );

get_header();
?>

<div class="bv-page ask-page">

    <!-- ── Page Header ──────────────────────────────────────────────────────── -->
    <div class="bv-page-header">
        <div>
            <h1 class="bv-page-title">&#10022; Ask AI</h1>
            <p class="bv-page-subtitle">Ask about a player, a matchup, an injury or tonight&rsquo;s best value. It digs through game logs,
                live odds at every book, the model&rsquo;s projections and its track record, then gives you a straight answer with the numbers behind it.</p>
        </div>
    </div>

    <div class="bv-sport-tabs" role="tablist" aria-label="Sport">
        <button class="bv-sport-tab is-active" data-sport="nba" role="tab" aria-selected="true">NBA</button>
        <button class="bv-sport-tab" data-sport="nfl" role="tab" aria-selected="false">NFL</button>
        <button class="bv-sport-tab" data-sport="mlb" role="tab" aria-selected="false">MLB</button>
        <button class="bv-sport-tab" data-sport="" role="tab" aria-selected="false">Other sports</button>
    </div>

    <form class="ask-box" id="ask-form" autocomplete="off">
        <div class="ask-box__field">
            <div class="ask-box__glow" aria-hidden="true"></div>
            <div class="ask-box__inner">
                <svg class="ask-box__spark" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l1.8 5.6L19.5 9l-5.7 1.6L12 16l-1.8-5.4L4.5 9l5.7-1.4z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>
                <label class="screen-reader-text" for="ask-q">Your sports question</label>
                <textarea id="ask-q" rows="2" maxlength="400" placeholder="e.g. Is Jalen Brunson&rsquo;s points line worth it tonight?"></textarea>
                <button type="submit" class="ask-box__send" id="ask-send" aria-label="Ask" disabled>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h14M13 6l6 6-6 6"/></svg>
                </button>
            </div>
        </div>
        <div class="ask-box__meta"><span id="ask-count">0 / 400</span><span>Sports only &middot; answers come from our data, not guarantees</span></div>
    </form>

    <div class="ask-chips" id="ask-chips" aria-label="Example questions"></div>

    <div class="ask-thread" id="ask-thread" aria-live="polite"></div>

    <p class="ask-fine">The analyst can be wrong. Projections are probabilities, not promises. 21+. Bet only what you can afford to lose.
        Problem gambling? Call 1-800-GAMBLER.</p>
</div>

<script>
var statsightAsk = {
    url:   <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
    nonce: <?php echo wp_json_encode( wp_create_nonce( 'statsight_events' ) ); ?>
};
</script>

<script>
(function () {
    'use strict';

    const MAX     = 400;
    const form    = document.getElementById('ask-form');
    const q       = document.getElementById('ask-q');
    const send    = document.getElementById('ask-send');
    const count   = document.getElementById('ask-count');
    const chips   = document.getElementById('ask-chips');
    const thread  = document.getElementById('ask-thread');
    let sport     = 'nba';
    let busy      = false;

    const IDEAS = {
        nba: ['Best value props on tonight\'s slate?', 'How has Jalen Brunson played over his last 10?', 'Where\'s the best price on LeBron\'s points?', 'How accurate is the model on NBA props?'],
        nfl: ['Which NFL props have the most value this week?', 'How has Josh Allen thrown lately?', 'Who is hurt on the Chiefs?', 'How accurate is the model on NFL props?'],
        mlb: ['Best value MLB props today?', 'How has Aaron Judge hit over his last 15 games?', 'Which teams are hot right now?', 'How accurate is the model on MLB props?'],
        '':  ['Biggest edges in the NHL right now?', 'Best value college football props?', 'Where should I bet the EPL anytime scorers?'],
    };
    const PLACEHOLDERS = {
        nba: 'e.g. Is Jalen Brunson\u2019s points line worth it tonight?',
        nfl: 'e.g. Should I take the over on Josh Allen\u2019s passing yards?',
        mlb: 'e.g. Is Aaron Judge\u2019s total bases line worth it today?',
        '':  'e.g. Where\u2019s the best price on NHL anytime goal scorers?',
    };
    // what the analyst looks at while it thinks; the real tools used are listed under the answer
    const STEPS  = ['Reading your question', 'Pulling game logs', 'Checking lines at every book', 'Scanning injury reports', 'Weighing the matchup', 'Writing it up'];
    const SOURCE = {
        find_player: 'Player lookup', player_recent_games: 'Game logs', player_lines_today: 'Model projections',
        slate: 'Slate', team_form: 'Team form', injuries: 'Injuries', model_track_record: 'Track record',
        odds_board: 'Live odds', best_edges: 'Best edges',
    };

    function esc(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    /** Escape first, then a safe subset: **bold**, "- " bullets, paragraphs. */
    function md(text) {
        const inline = t => esc(t).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        const out = [];
        let list = null;
        String(text).split('\n').forEach(function (raw) {
            const line = raw.trim();
            const b = /^[-*•]\s+(.*)/.exec(line);
            if (b) { (list = list || []).push(`<li>${inline(b[1])}</li>`); return; }
            if (list) { out.push(`<ul>${list.join('')}</ul>`); list = null; }
            if (line) out.push(`<p>${inline(line.replace(/^#+\s*/, ''))}</p>`);
        });
        if (list) out.push(`<ul>${list.join('')}</ul>`);
        return out.join('');
    }

    function renderChips() {
        chips.innerHTML = (IDEAS[sport] || []).map(t => `<button type="button" class="ask-chip">${esc(t)}</button>`).join('');
        q.placeholder   = PLACEHOLDERS[sport] || PLACEHOLDERS.nba;
    }

    function setCount() {
        count.textContent = `${q.value.length} / ${MAX}`;
        send.disabled = busy || !q.value.trim();
    }

    document.querySelectorAll('.bv-sport-tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.bv-sport-tab').forEach(b => {
                b.classList.toggle('is-active', b === btn);
                b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
            });
            sport = btn.dataset.sport;
            renderChips();
        });
    });

    q.addEventListener('input', function () {
        setCount();
        q.style.height = 'auto';
        q.style.height = `${Math.min(q.scrollHeight, 200)}px`;
    });
    q.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
    });
    chips.addEventListener('click', function (e) {
        const c = e.target.closest('.ask-chip');
        if (!c || busy) return;
        q.value = c.textContent;
        setCount();
        form.requestSubmit();
    });

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const question = q.value.trim();
        if (!question || busy) return;
        busy = true;
        form.classList.add('is-busy');
        setCount();

        const tab  = document.querySelector('.bv-sport-tab.is-active');
        const card = document.createElement('article');
        card.className = 'ask-card is-thinking';
        card.innerHTML = `<p class="ask-card__q">${esc(question)}<span class="bv-row__sport-tag">${esc(tab ? tab.textContent : '')}</span></p>
            <div class="ask-card__a"><ol class="ask-steps">${STEPS.map(t => `<li>${esc(t)}</li>`).join('')}</ol></div>`;
        thread.prepend(card);
        const steps = [...card.querySelectorAll('.ask-steps li')];
        let i = 0;
        steps[0].classList.add('is-on');
        const tick = setInterval(function () {
            if (i < steps.length - 1) { steps[i].classList.replace('is-on', 'is-done'); steps[++i].classList.add('is-on'); }
        }, 2600);

        let html;
        try {
            const res  = await fetch(statsightAsk.url, {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams({ action: 'statsight_ask_ai', nonce: statsightAsk.nonce, question, sport }),
            });
            const json = await res.json().catch(() => ({}));
            if (!json.success) throw new Error(json.data?.message || 'The analyst is unavailable right now.');
            const used = [...new Set((json.data.tools || []).map(t => SOURCE[t]).filter(Boolean))];
            html = `<div class="ask-card__body">${md(json.data.answer)}</div>${used.length
                ? `<p class="ask-card__src">Looked at ${used.map(u => `<span>${esc(u)}</span>`).join('')}</p>` : ''}`;
            q.value = '';
            q.style.height = '';
        } catch (err) {
            card.classList.add('is-error');
            html = `<p class="ask-card__err">${esc(err.message)}</p>`;
        }
        clearInterval(tick);
        card.classList.remove('is-thinking');
        card.querySelector('.ask-card__a').innerHTML = html;
        busy = false;
        form.classList.remove('is-busy');
        setCount();
        q.focus();
    });

    renderChips();
    setCount();
}());
</script>

<?php get_footer(); ?>
