<?php
/**
 * Ask AI: sports questions answered by Claude, grounded in our own data.
 *
 * Claude gets read-only tools over the prediction engine's tables (player game logs, today's projections and
 * lines, the slate, team form, injuries, the model's tested track record) plus xstatiq's live odds across books,
 * decides which to call, and writes a short answer from what they return. Ported from sport-predict's
 * engine/sp/ask.py; keep the system prompt, tools and guardrails in step with it.
 *
 * The engine's tables (players, games, player_games, predictions, ...) live in the WordPress database on the live
 * site. Locally they can live in another database on the same server: define( 'XS_ENGINE_DB', 'db_name' ) in
 * wp-config.php.
 */

defined( 'ABSPATH' ) || exit;

const STATSIGHT_ASK_MODEL       = 'claude-opus-5-5';
const STATSIGHT_ASK_MAX_LEN     = 400;
const STATSIGHT_ASK_TOOL_ROUNDS = 6;
const STATSIGHT_ASK_PER_HOUR    = 12;    // per user (admins exempt)
const STATSIGHT_ASK_PER_DAY     = 300;   // whole site: keeps the Anthropic bill bounded
const STATSIGHT_ASK_CACHE_SECS  = 600;   // the same question on the same tab within 10 minutes reuses the answer
const STATSIGHT_ASK_ENGINE      = [ 'nba', 'nfl', 'mlb' ];   // sports with engine game logs and projections

/** Short sport codes the odds tools accept => xstatiq / Odds API sport keys. */
const STATSIGHT_ASK_ODDS_SPORTS = [
    'nba'   => 'basketball_nba',
    'nfl'   => 'americanfootball_nfl',
    'mlb'   => 'baseball_mlb',
    'nhl'   => 'icehockey_nhl',
    'ncaaf' => 'americanfootball_ncaaf',
    'ncaab' => 'basketball_ncaab',
    'wnba'  => 'basketball_wnba',
    'epl'   => 'soccer_epl',
    'mls'   => 'soccer_usa_mls',
];

const STATSIGHT_ASK_SYSTEM = <<<'TXT'
You are xstatiq's analyst: you answer questions about sports - players, teams, matchups, stats, injuries,
projections and player-prop betting - using xstatiq's data through your tools.

What the data covers:
- Game logs, team form, injuries, the model's projections and its track record: NBA, NFL and MLB.
- Live player-prop odds across sportsbooks, with edge and EV against the no-vig consensus: NBA, NFL, MLB, NHL,
  college football and basketball, WNBA, EPL and MLS.

Scope and conduct:
- Only answer sports questions. If the question isn't about sports, or asks for anything inappropriate, harmful,
  hateful, sexual, personal (private information about anyone), or unrelated (coding, homework, politics, etc.),
  reply in one friendly sentence that you only cover sports and suggest a sports question instead. Do not call tools.
- Ground every number in tool results. Never invent stats, lines, injuries or results. If the data doesn't cover
  something (another league, a player we have no data for, a game too far out), say so plainly.
- Be honest about uncertainty: projections are probabilities, not guarantees. Never say a bet is a "lock", "sure
  thing" or "guaranteed". When you give betting advice, add one short line such as "Bet only what you can afford
  to lose." Don't encourage chasing losses or large stakes.
- The model's tested track record (from the track record tool) can inform how much weight to give a pick; NBA is the
  strongest sport, NFL moderate, MLB less tested.
- Edge and EV from the odds tools compare the best price with the other books' no-vig consensus. They measure price
  disagreement between books, not the model's opinion; say which one you are quoting.

Style:
- Lead with the answer or insight in one or two sentences, then up to 4 short bullet points with the key numbers.
- Keep it under 170 words. Plain language, no jargon without a quick explanation. Use the player's name, the
  stat line, the projection and the price where relevant.
- Use tools efficiently: look up only what the question needs (usually 1-4 calls).
TXT;

/** Engine stat table and the columns shown for each sport. */
const STATSIGHT_ASK_STAT_COLS = [
    'nba' => [ 'player_games', [ 'minutes', 'pts', 'reb', 'ast', 'fg3m', 'stl', 'blk', 'tov' ] ],
    'nfl' => [ 'nfl_player_games', [ 'pass_cmp', 'pass_att', 'pass_yds', 'pass_td', 'pass_int', 'rush_att', 'rush_yds',
                                     'rush_td', 'targets', 'rec', 'rec_yds', 'rec_td' ] ],
    'mlb' => [ 'mlb_player_games', [ 'role', 'ab', 'hits', 'runs', 'rbi', 'hr', 'bb', 'so', 'tb', 'p_outs', 'p_h', 'p_er',
                                     'p_bb', 'p_k' ] ],
];

/** Engine pick thresholds (sport-predict config.py and sports/*.py). */
const STATSIGHT_ASK_MIN_LEG_PROB = 0.52;
const STATSIGHT_ASK_MIN_LEG_EV   = [ 'nba' => 0.03, 'nfl' => 0.05, 'mlb' => 0.03 ];

/** Backtest findings (engine README): tested against real historical sportsbook lines. */
const STATSIGHT_ASK_TRACK = [
    'nba' => 'Strongest sport. Two seasons (2024-25, 2025-26), ~70,000 tested picks: singles hit ~56.7% vs ~57.4% '
           . 'promised, about +4.5% ROI; parlays about +9-12%; 60%+ picks hit 63-70% at ~+5%; morning (11 AM) lines '
           . 'returned more (+8.4% in a one-month test). All five markets published (points, rebounds, assists, 3PM, PRA).',
    'nfl' => 'Moderate confidence. Three seasons (2023-25), each scored blind: +4.3% / +15.6% / +3.1% ROI at 5% EV; hit '
           . '~54.8% vs ~56.7% promised. Published markets only: rushing attempts, receiving yards, passing yards, '
           . 'rushing yards, receptions. Pass attempts/completions/passing TDs/anytime TD are held (no consistent edge).',
    'mlb' => 'Moderate-to-low confidence (one season tested, 2026): about +3.5% ROI on ~800 picks, hit ~58% vs ~58.6% '
           . 'promised. Not tested as deeply as NBA.',
];

function statsight_ask_tools(): array {
    $engine = [ 'type' => 'string', 'enum' => STATSIGHT_ASK_ENGINE ];
    $odds   = [ 'type' => 'string', 'enum' => array_keys( STATSIGHT_ASK_ODDS_SPORTS ) ];
    $tool   = fn( string $name, string $desc, array $props, array $required ) => [
        'name'         => $name,
        'description'  => $desc,
        'input_schema' => [ 'type' => 'object', 'properties' => $props, 'required' => $required, 'additionalProperties' => false ],
    ];

    return [
        $tool( 'find_player', 'Find players by name (partial names work). Returns ids, names, teams and the sport. Use this '
            . 'first whenever a question mentions a player (NBA, NFL, MLB).', [
            'name'  => [ 'type' => 'string', 'description' => 'Player name or part of it' ],
            'sport' => $engine + [ 'description' => 'Optional sport to search in' ],
        ], [ 'name' ] ),
        $tool( 'player_recent_games', "A player's last games (date, opponent, minutes or role and the main stats), newest "
            . 'first, plus averages.', [
            'player_id' => [ 'type' => 'integer' ],
            'sport'     => $engine,
            'games'     => [ 'type' => 'integer', 'description' => 'How many games, 1-20 (default 10)' ],
        ], [ 'player_id', 'sport' ] ),
        $tool( 'player_lines_today', "The player's prop lines on the current or next slate with the model's projection, "
            . "model hit probability, the market's no-vig probability, EV at the best book, whether it's recommended or "
            . "held back (and why), and the model's reasons.", [
            'player_id' => [ 'type' => 'integer' ],
            'sport'     => $engine,
        ], [ 'player_id', 'sport' ] ),
        $tool( 'slate', 'The current/next slate for a sport: games and start times, key injuries, and the top recommended '
            . 'value legs with their EV and hit probability.', [
            'sport' => $engine,
            'top'   => [ 'type' => 'integer', 'description' => 'How many top legs, 1-15 (default 8)' ],
        ], [ 'sport' ] ),
        $tool( 'team_form', "A team's recent results (scores, home/away, wins/losses) by team abbreviation (e.g. NY, BOS, "
            . 'KC, LAD).', [
            'team'  => [ 'type' => 'string' ],
            'sport' => $engine,
            'games' => [ 'type' => 'integer', 'description' => '1-15 (default 8)' ],
        ], [ 'team', 'sport' ] ),
        $tool( 'injuries', 'The latest injury report for a sport, optionally one team (abbreviation).', [
            'sport' => $engine,
            'team'  => [ 'type' => 'string' ],
        ], [ 'sport' ] ),
        $tool( 'model_track_record', "How accurate the model has been for a sport: tested results against real sportsbook "
            . 'lines, which markets it publishes, and graded live picks so far.', [
            'sport' => $engine,
        ], [ 'sport' ] ),
        $tool( 'odds_board', "A player's live prop odds at every sportsbook on today's slate (main line per market), with "
            . "the best price on each side, its edge and EV against the other books' no-vig consensus, and the fair price. "
            . 'Use for line shopping or "where should I bet X".', [
            'player' => [ 'type' => 'string', 'description' => 'Player name' ],
            'sport'  => $odds,
        ], [ 'player', 'sport' ] ),
        $tool( 'best_edges', "The props where one sportsbook's price beats the other books' no-vig consensus by the most "
            . 'right now (both overs and unders), like the Best Value page. Likely stale lines are left out.', [
            'sport' => $odds,
            'top'   => [ 'type' => 'integer', 'description' => '1-15 (default 8)' ],
        ], [ 'sport' ] ),
    ];
}

/** A table in the engine's database (see the file comment). */
function statsight_ask_table( string $table ): string {
    return defined( 'XS_ENGINE_DB' ) ? '`' . XS_ENGINE_DB . '`.`' . $table . '`' : '`' . $table . '`';
}

/** team_id => abbreviation for a sport. */
function statsight_ask_team_abbr( string $sport ): array {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT team_id, abbr FROM ' . statsight_ask_table( 'teams' ) . ' WHERE sport = %s', $sport ) );
    return array_column( array_map( fn( $r ) => [ (int) $r->team_id, $r->abbr ], $rows ?: [] ), 1, 0 );
}

/** Decimal price => American odds string. */
function statsight_ask_american( float $price ): string {
    return $price >= 2 ? '+' . round( ( $price - 1 ) * 100 ) : '-' . round( 100 / ( $price - 1 ) );
}

/** Probability => American odds string, e.g. 0.45 => "+122". */
function statsight_ask_prob_american( float $p ): string {
    return $p >= 0.5 ? '-' . round( 100 * $p / ( 1 - $p ) ) : '+' . round( 100 * ( 1 - $p ) / $p );
}

// ---------------------------------------------------------------- engine tools

function statsight_ask_find_player( string $name, ?string $sport = null ): array {
    global $wpdb;
    $name = trim( $name );
    if ( mb_strlen( preg_replace( '/[^a-z]/i', '', $name ) ) < 2 ) {
        return [ 'error' => 'Give at least two letters of the name.' ];
    }
    $out = [];
    foreach ( $sport ? [ $sport ] : STATSIGHT_ASK_ENGINE as $s ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT p.player_id, p.name, p.position, s.team_id, MAX(g.game_date) last_game
             FROM ' . statsight_ask_table( 'players' ) . ' p
             JOIN ' . statsight_ask_table( STATSIGHT_ASK_STAT_COLS[ $s ][0] ) . ' s USING (player_id)
             JOIN ' . statsight_ask_table( 'games' ) . ' g USING (game_id)
             WHERE p.name LIKE %s GROUP BY p.player_id, p.name, p.position, s.team_id
             ORDER BY last_game DESC LIMIT 40',
            '%' . $wpdb->esc_like( mb_substr( $name, 0, 40 ) ) . '%'
        ) );
        if ( ! $rows ) {
            continue;
        }
        $teams = statsight_ask_team_abbr( $s );
        $seen  = [];
        foreach ( $rows as $r ) {
            if ( isset( $seen[ $r->player_id ] ) || count( $seen ) >= 6 ) {
                continue;
            }
            $seen[ $r->player_id ] = true;
            $out[] = [ 'player_id' => (int) $r->player_id, 'name' => $r->name, 'sport' => $s,
                       'team' => $teams[ (int) $r->team_id ] ?? null, 'position' => $r->position, 'last_game' => $r->last_game ];
        }
    }
    return $out ? [ 'players' => array_slice( $out, 0, 10 ) ] : [ 'players' => [], 'note' => 'No player by that name in our NBA/NFL/MLB data.' ];
}

function statsight_ask_player_recent_games( int $player_id, string $sport, ?int $games = 10 ): array {
    global $wpdb;
    $games         = max( 1, min( (int) ( $games ?: 10 ), 20 ) );
    [ $table, $cols ] = STATSIGHT_ASK_STAT_COLS[ $sport ];
    $extra = $sport === 'nba' ? 'AND s.dnp = 0' : '';
    $rows  = $wpdb->get_results( $wpdb->prepare(
        'SELECT g.game_date, s.opp_team_id, s.is_home, ' . implode( ', ', array_map( fn( $c ) => 's.' . $c, $cols ) ) . '
         FROM ' . statsight_ask_table( $table ) . ' s JOIN ' . statsight_ask_table( 'games' ) . " g USING (game_id)
         WHERE s.player_id = %d $extra ORDER BY g.game_date DESC LIMIT %d",
        $player_id, $games
    ), ARRAY_A );
    if ( ! $rows ) {
        return [ 'games' => [], 'note' => 'No games found for that player.' ];
    }
    $teams = statsight_ask_team_abbr( $sport );
    $avg   = [];
    foreach ( array_diff( $cols, [ 'role' ] ) as $c ) {
        $vals      = array_filter( array_column( $rows, $c ), 'is_numeric' );
        $avg[ $c ] = $vals ? round( array_sum( $vals ) / count( $vals ), 2 ) : null;
    }
    foreach ( $rows as &$r ) {
        $r['opponent'] = ( $r['is_home'] ? 'vs ' : '@ ' ) . ( $teams[ (int) $r['opp_team_id'] ] ?? '?' );
        unset( $r['opp_team_id'], $r['is_home'] );
    }
    unset( $r );
    return [ 'games' => $rows, 'averages' => $avg ];
}

function statsight_ask_player_lines_today( int $player_id, string $sport ): array {
    global $wpdb;
    $pr   = statsight_ask_table( 'predictions' );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT market, line, side, bookmaker, price, model_prob, fair_prob, ev, game_date, context FROM $pr
         WHERE sport = %s AND player_id = %d AND created_at = (SELECT MAX(created_at) FROM $pr WHERE sport = %s)
         ORDER BY ev DESC",
        $sport, $player_id, $sport
    ) );
    if ( ! $rows ) {
        return [ 'lines' => [], 'note' => "No lines for this player on the current slate (not playing, or books haven't posted props yet)." ];
    }
    $min_ev = STATSIGHT_ASK_MIN_LEG_EV[ $sport ];
    $lines  = [];
    foreach ( $rows as $r ) {
        $key = $r->market . '|' . $r->line . '|' . $r->side;
        if ( isset( $lines[ $key ] ) || count( $lines ) >= 12 ) {
            continue;
        }
        $ctx  = json_decode( $r->context ?: '{}', true ) ?: [];
        $held = $ctx['held'] ?? null;
        $lines[ $key ] = [
            'market'        => $r->market, 'line' => (float) $r->line, 'side' => $r->side, 'best_book' => $r->bookmaker,
            'american_odds' => statsight_ask_american( (float) $r->price ),
            'model_prob'    => round( (float) $r->model_prob, 3 ), 'fair_prob' => $r->fair_prob !== null ? round( (float) $r->fair_prob, 3 ) : null,
            'ev'            => round( (float) $r->ev, 3 ), 'game_date' => $r->game_date,
            'projection'    => $ctx['proj'] ?? null,
            'held'          => $held,
            'reasons'       => array_slice( array_map( fn( $w ) => is_array( $w ) ? ( $w['t'] ?? '' ) : $w, $ctx['why'] ?? [] ), 0, 5 ),
            'recommended'   => $held === null && (float) $r->ev >= $min_ev && (float) $r->model_prob >= STATSIGHT_ASK_MIN_LEG_PROB,
        ];
    }
    return [ 'lines' => array_values( $lines ),
             'note'  => sprintf( 'EV is per $1 at the best book; recommended = passes every check with EV >= %d%%.', $min_ev * 100 ) ];
}

function statsight_ask_slate( string $sport, ?int $top = 8 ): array {
    global $wpdb;
    $top   = max( 1, min( (int) ( $top ?: 8 ), 15 ) );
    $build = $wpdb->get_row( $wpdb->prepare(
        'SELECT game_date, slate_end FROM ' . statsight_ask_table( 'builds' ) . ' WHERE sport = %s ORDER BY id DESC LIMIT 1', $sport
    ) );
    if ( ! $build ) {
        return [ 'note' => 'No slate built for this sport yet.' ];
    }
    $first = $build->game_date;
    $last  = $build->slate_end ?: $first;
    $teams = statsight_ask_team_abbr( $sport );
    $games = $outs = [];
    $rows  = $wpdb->get_results( $wpdb->prepare(
        'SELECT start_utc, home_team_id, away_team_id, outs FROM ' . statsight_ask_table( 'slate_games' ) . '
         WHERE sport = %s AND game_date BETWEEN %s AND %s ORDER BY start_utc', $sport, $first, $last
    ) );
    foreach ( $rows ?: [] as $g ) {
        $games[] = [ 'game' => ( $teams[ (int) $g->away_team_id ] ?? '?' ) . ' @ ' . ( $teams[ (int) $g->home_team_id ] ?? '?' ), 'start_utc' => $g->start_utc ];
        foreach ( json_decode( $g->outs ?: '{}', true ) ?: [] as $tid => $list ) {
            foreach ( $list as $o ) {
                if ( preg_match( '/^(out|doubtful)/i', $o['status'] ?? '' ) ) {
                    $outs[] = "{$o['name']} (" . ( $teams[ (int) $tid ] ?? '?' ) . "): {$o['status']}";
                }
            }
        }
    }
    $pr   = statsight_ask_table( 'predictions' );
    $legs = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.name, pr.market, pr.line, pr.side, pr.bookmaker book, pr.price, pr.model_prob, pr.ev
         FROM $pr pr JOIN " . statsight_ask_table( 'players' ) . " p USING (player_id)
         WHERE pr.sport = %s AND pr.created_at = (SELECT MAX(created_at) FROM $pr WHERE sport = %s)
           AND JSON_EXTRACT(pr.context, '$.held') IS NULL AND pr.ev >= %f AND pr.model_prob >= %f
         ORDER BY pr.ev DESC",
        $sport, $sport, STATSIGHT_ASK_MIN_LEG_EV[ $sport ], STATSIGHT_ASK_MIN_LEG_PROB
    ), ARRAY_A );
    $top_legs = [];
    foreach ( $legs ?: [] as $l ) {
        $key = $l['name'] . '|' . $l['market'] . '|' . $l['side'];
        if ( isset( $top_legs[ $key ] ) || count( $top_legs ) >= $top ) {
            continue;
        }
        $l['american_odds'] = statsight_ask_american( (float) $l['price'] );
        unset( $l['price'] );
        $top_legs[ $key ] = $l;
    }
    return [ 'slate' => "$first to $last", 'games' => $games, 'key_injuries' => array_slice( $outs, 0, 25 ),
             'top_value_legs' => array_values( $top_legs ) ];
}

function statsight_ask_team_form( string $team, string $sport, ?int $games = 8 ): array {
    global $wpdb;
    $games = max( 1, min( (int) ( $games ?: 8 ), 15 ) );
    $t     = $wpdb->get_row( $wpdb->prepare(
        'SELECT team_id, abbr FROM ' . statsight_ask_table( 'teams' ) . ' WHERE sport = %s AND (abbr = %s OR name LIKE %s) LIMIT 1',
        $sport, strtoupper( mb_substr( trim( $team ), 0, 5 ) ), '%' . $wpdb->esc_like( mb_substr( trim( $team ), 0, 30 ) ) . '%'
    ) );
    if ( ! $t ) {
        return [ 'note' => 'No ' . strtoupper( $sport ) . " team matching '$team'." ];
    }
    $tid   = (int) $t->team_id;
    $teams = statsight_ask_team_abbr( $sport );
    $rows  = $wpdb->get_results( $wpdb->prepare(
        'SELECT game_date, home_team_id, away_team_id, home_score, away_score FROM ' . statsight_ask_table( 'games' ) . '
         WHERE sport = %s AND (home_team_id = %d OR away_team_id = %d) ORDER BY game_date DESC LIMIT %d',
        $sport, $tid, $tid, $games
    ) );
    $res = [];
    $won = 0;
    foreach ( $rows ?: [] as $r ) {
        $home        = (int) $r->home_team_id === $tid;
        [ $us, $them ] = $home ? [ $r->home_score, $r->away_score ] : [ $r->away_score, $r->home_score ];
        $win         = $us !== null && $them !== null && (int) $us > (int) $them;
        $won        += $win ? 1 : 0;
        $res[]       = [ 'date' => $r->game_date, 'opponent' => ( $home ? 'vs ' : '@ ' ) . ( $teams[ (int) ( $home ? $r->away_team_id : $r->home_team_id ) ] ?? '?' ),
                         'score' => "$us-$them", 'result' => $win ? 'W' : 'L' ];
    }
    return [ 'team' => $t->abbr, 'record_last_n' => $won . '-' . ( count( $res ) - $won ), 'games' => $res ];
}

function statsight_ask_injuries( string $sport, ?string $team = null ): array {
    global $wpdb;
    $inj  = statsight_ask_table( 'injuries' );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.name, t.abbr team, i.status, LEFT(i.comment, 160) comment FROM $inj i
         JOIN " . statsight_ask_table( 'players' ) . ' p USING (player_id)
         LEFT JOIN ' . statsight_ask_table( 'teams' ) . " t ON t.sport = i.sport AND t.team_id = i.team_id
         WHERE i.sport = %s AND i.fetched_at = (SELECT MAX(fetched_at) FROM $inj WHERE sport = %s)",
        $sport, $sport
    ), ARRAY_A ) ?: [];
    if ( $team ) {
        $rows = array_values( array_filter( $rows, fn( $r ) => strtoupper( (string) $r['team'] ) === strtoupper( trim( $team ) ) ) );
    }
    return [ 'injuries' => array_slice( $rows, 0, 40 ), 'count' => count( $rows ) ];
}

function statsight_ask_model_track_record( string $sport ): array {
    global $wpdb;
    $live = $wpdb->get_row( $wpdb->prepare(
        "SELECT COUNT(*) n, SUM(result = 'win') w, SUM(result = 'loss') l FROM " . statsight_ask_table( 'predictions' ) . "
         WHERE sport = %s AND result IN ('win', 'loss') AND ev >= %f AND model_prob >= %f
           AND JSON_EXTRACT(context, '$.held') IS NULL",
        $sport, STATSIGHT_ASK_MIN_LEG_EV[ $sport ], STATSIGHT_ASK_MIN_LEG_PROB
    ) );
    return [ 'tested_against_real_lines' => STATSIGHT_ASK_TRACK[ $sport ],
             'graded_live_picks'         => [ 'settled' => (int) ( $live->n ?? 0 ), 'won' => (int) ( $live->w ?? 0 ), 'lost' => (int) ( $live->l ?? 0 ) ],
             'note'                      => 'Backtests used each season\'s real sportsbook lines, priced blind. Results are averages; any single pick can lose.' ];
}

// ---------------------------------------------------------------- xstatiq odds tools

/**
 * Every player prop on the sport's current slate from the cached props payloads (no Odds API calls): one row per
 * player, market and main line, limited to the user's selected books.
 *
 * @return array<int, array{player: string, market: string, line: string, matchup: string, start: string, books: array, odds: array}>
 */
function statsight_ask_prop_rows( string $sport_key ): array {
    $events = statsight_get_events_for_sport( $sport_key )['days'] ?? [];
    $active = statsight_get_active_books( get_current_user_id() );
    $skip   = [ 'h2h', 'totals', 'spreads' ];
    $rows   = [];
    foreach ( $events as $day ) {
        foreach ( $day['events'] ?? [] as $event ) {
            $payload = get_transient( 'statsight_props2_' . ( $event['id'] ?? '' ) );
            if ( ! is_array( $payload ) ) {
                continue;
            }
            foreach ( $payload['props'] ?? [] as $mk => $players ) {
                if ( in_array( $mk, $skip, true ) || str_contains( $mk, 'alternate' ) ) {
                    continue;
                }
                foreach ( $players as $player => $lines ) {
                    $lk = isset( $lines['yn'] ) ? 'yn' : ( $payload['default_lines'][ $mk ][ $player ] ?? null );
                    if ( $lk === null || empty( $lines[ $lk ] ) ) {
                        continue;
                    }
                    $odds = $active !== null ? array_intersect_key( $lines[ $lk ], array_flip( $active ) ) : $lines[ $lk ];
                    if ( $odds ) {
                        $rows[] = [
                            'player'  => $player,
                            'market'  => $payload['market_labels'][ $mk ] ?? $mk,
                            'line'    => $lk === 'yn' ? 'Yes' : (string) $lk,
                            'matchup' => ( $event['away_team'] ?? '' ) . ' @ ' . ( $event['home_team'] ?? '' ),
                            'start'   => $event['commence_time'] ?? '',
                            'books'   => $payload['books'] ?? [],
                            'odds'    => $odds,
                        ];
                    }
                }
            }
        }
    }
    return $rows;
}

/** Best price, edge, EV and fair price for one side of a prop row, or null with fewer than two books. */
function statsight_ask_side_value( array $row, string $side ): ?array {
    $v = statsight_calc_value( $row['odds'], $side );
    if ( ! $v ) {
        return null;
    }
    return [ 'best_price' => statsight_fmt_odds( $v['best_odds'] ), 'best_book' => $row['books'][ $v['best_bk'] ] ?? $v['best_bk'],
             'edge_pct' => $v['edge'], 'ev_pct' => $v['ev'], 'fair_price' => statsight_ask_prob_american( $v['fair_prob'] ),
             'likely_stale' => $v['stale'] ];
}

function statsight_ask_odds_board( string $player, string $sport ): array {
    $want = statsight_normalise_team_name( $player );
    $rows = array_filter( statsight_ask_prop_rows( STATSIGHT_ASK_ODDS_SPORTS[ $sport ] ),
        fn( $r ) => str_contains( statsight_normalise_team_name( $r['player'] ), $want ) );
    if ( ! $rows ) {
        return [ 'props' => [], 'note' => "No live props for '$player' in the current " . strtoupper( $sport ) . ' slate (not playing, '
                                         . 'books not posted yet, or odds not loaded).' ];
    }
    $out = [];
    foreach ( array_slice( array_values( $rows ), 0, 12 ) as $r ) {
        $prices = [];
        foreach ( $r['odds'] as $bk => $o ) {
            $prices[ $r['books'][ $bk ] ?? $bk ] = [ 'over' => isset( $o['over'] ) ? statsight_fmt_odds( (int) $o['over'] ) : null,
                                                    'under' => isset( $o['under'] ) ? statsight_fmt_odds( (int) $o['under'] ) : null ];
        }
        $out[] = [ 'player' => $r['player'], 'market' => $r['market'], 'line' => $r['line'], 'game' => $r['matchup'],
                   'start_utc' => $r['start'], 'prices' => $prices,
                   'over' => statsight_ask_side_value( $r, 'over' ), 'under' => statsight_ask_side_value( $r, 'under' ) ];
    }
    return [ 'props' => $out, 'note' => 'Edge = consensus no-vig probability minus the best price\'s implied probability (percentage points); '
                                        . 'EV is per $1 at the best price. likely_stale = the best price is far from every other book.' ];
}

function statsight_ask_best_edges( string $sport, ?int $top = 8 ): array {
    $top   = max( 1, min( (int) ( $top ?: 8 ), 15 ) );
    $edges = [];
    foreach ( statsight_ask_prop_rows( STATSIGHT_ASK_ODDS_SPORTS[ $sport ] ) as $r ) {
        foreach ( [ 'over', 'under' ] as $side ) {
            $v = statsight_ask_side_value( $r, $side );
            if ( $v && ! $v['likely_stale'] && $v['edge_pct'] > 0 ) {
                unset( $v['likely_stale'] );
                $edges[] = [ 'player' => $r['player'], 'market' => $r['market'], 'side' => $side, 'line' => $r['line'],
                             'game' => $r['matchup'], 'books_quoting' => count( $r['odds'] ) ] + $v;
            }
        }
    }
    if ( ! $edges ) {
        return [ 'edges' => [], 'note' => 'No positive edges on the current ' . strtoupper( $sport ) . ' slate right now (or odds not loaded).' ];
    }
    usort( $edges, fn( $a, $b ) => $b['edge_pct'] <=> $a['edge_pct'] );
    return [ 'edges' => array_slice( $edges, 0, $top ), 'note' => 'Edge and EV measure price disagreement between books, not the model.' ];
}

// ---------------------------------------------------------------- the agent loop

/** Run one tool call. Returns [json string (max 12k chars), is_error]. */
function statsight_ask_run_tool( string $name, array $args ): array {
    $funcs = [
        'find_player'         => 'statsight_ask_find_player',
        'player_recent_games' => 'statsight_ask_player_recent_games',
        'player_lines_today'  => 'statsight_ask_player_lines_today',
        'slate'               => 'statsight_ask_slate',
        'team_form'           => 'statsight_ask_team_form',
        'injuries'            => 'statsight_ask_injuries',
        'model_track_record'  => 'statsight_ask_model_track_record',
        'odds_board'          => 'statsight_ask_odds_board',
        'best_edges'          => 'statsight_ask_best_edges',
    ];
    if ( ! isset( $funcs[ $name ] ) ) {
        return [ wp_json_encode( [ 'error' => "unknown tool $name" ] ), true ];
    }
    $sports = in_array( $name, [ 'odds_board', 'best_edges' ], true ) ? array_keys( STATSIGHT_ASK_ODDS_SPORTS ) : STATSIGHT_ASK_ENGINE;
    if ( isset( $args['sport'] ) && ! in_array( $args['sport'], $sports, true ) ) {
        return [ wp_json_encode( [ 'error' => 'Sport must be one of: ' . implode( ', ', $sports ) . '.' ] ), true ];
    }
    try {
        return [ mb_substr( (string) wp_json_encode( call_user_func_array( $funcs[ $name ], $args ) ), 0, 12000 ), false ];
    } catch ( Throwable $e ) {                     // a bad lookup shouldn't end the answer
        return [ wp_json_encode( [ 'error' => 'lookup failed: ' . get_class( $e ) ] ), true ];
    }
}

/** One Messages API call. Returns the decoded response (objects, so content can be sent back unchanged) or WP_Error. */
function statsight_ask_call( array $messages ) {
    $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout' => 90,
        'headers' => [
            'x-api-key'         => ANTHROPIC_API_KEY,
            'anthropic-version' => '2023-06-01',
            'anthropic-beta'    => 'server-side-fallback-2026-07-01',
            'content-type'      => 'application/json',
        ],
        'body'    => wp_json_encode( [
            'model'         => STATSIGHT_ASK_MODEL,
            'max_tokens'    => 16000,
            'system'        => STATSIGHT_ASK_SYSTEM,
            'tools'         => statsight_ask_tools(),
            'messages'      => $messages,
            'thinking'      => [ 'type' => 'adaptive' ],
            'output_config' => [ 'effort' => 'medium' ],
            'fallbacks'     => 'default',   // retry a safety refusal on a fallback model
        ] ),
    ] );
    if ( is_wp_error( $response ) ) {
        return $response;
    }
    $data = json_decode( wp_remote_retrieve_body( $response ) );
    if ( wp_remote_retrieve_response_code( $response ) !== 200 || ! is_object( $data ) ) {
        error_log( '[Statsight Ask] API ' . wp_remote_retrieve_response_code( $response ) . ': ' . substr( wp_remote_retrieve_body( $response ), 0, 500 ) );
        return new WP_Error( 'ask_api', 'The analyst is unavailable right now.' );
    }
    return $data;
}

/**
 * Answer one question. Returns [ 'ok' => true, 'answer' => string, 'tools' => string[] ] or [ 'ok' => false, 'error' => string ].
 */
function statsight_ask_answer( string $question, string $sport = '' ): array {
    if ( ! defined( 'ANTHROPIC_API_KEY' ) || ! ANTHROPIC_API_KEY ) {
        return [ 'ok' => false, 'error' => "The AI analyst isn't set up yet." ];
    }
    $hint     = in_array( $sport, STATSIGHT_ASK_ENGINE, true ) ? ' (The user is looking at the ' . strtoupper( $sport ) . ' tab.)' : '';
    $today    = 'Today is ' . wp_date( 'l, F j, Y' ) . '.';
    $messages = [ [ 'role' => 'user', 'content' => "$today$hint\n\nQuestion: $question" ] ];
    $used     = [];

    for ( $round = 0; $round <= STATSIGHT_ASK_TOOL_ROUNDS; $round++ ) {
        $response = statsight_ask_call( $messages );
        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'error' => $response->get_error_message() ];
        }
        if ( ( $response->stop_reason ?? '' ) === 'refusal' ) {
            return [ 'ok' => true, 'answer' => 'I can only help with sports questions. Try asking about a player, team, matchup or '
                                               . "tonight's best value.", 'tools' => $used ];
        }
        if ( ( $response->stop_reason ?? '' ) !== 'tool_use' ) {
            $text = trim( implode( "\n", array_map( fn( $b ) => $b->text, array_filter( $response->content ?? [], fn( $b ) => ( $b->type ?? '' ) === 'text' ) ) ) );
            return [ 'ok' => true, 'answer' => $text ?: "I couldn't find an answer to that in our data.", 'tools' => $used ];
        }
        $messages[] = [ 'role' => 'assistant', 'content' => $response->content ];
        $results    = [];
        foreach ( $response->content as $block ) {
            if ( ( $block->type ?? '' ) !== 'tool_use' ) {
                continue;
            }
            [ $out, $err ] = statsight_ask_run_tool( $block->name, (array) ( $block->input ?? [] ) );
            $used[]        = $block->name;
            $results[]     = [ 'type' => 'tool_result', 'tool_use_id' => $block->id, 'content' => $out ] + ( $err ? [ 'is_error' => true ] : [] );
        }
        $messages[] = [ 'role' => 'user', 'content' => $results ];
    }
    return [ 'ok' => true, 'answer' => "That needed more digging than I'm allowed for one question. Try narrowing it to one player or one game.",
             'tools' => $used ];
}

/**
 * AJAX: POST question, sport. Pro plan and up; rate-limited per user per hour and site-wide per day; identical
 * questions on the same tab are cached for 10 minutes.
 */
function statsight_ajax_ask_ai(): void {
    check_ajax_referer( 'statsight_events', 'nonce' );
    statsight_require_plan( 'pro' );

    $question = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( wp_unslash( (string) ( $_POST['question'] ?? '' ) ) ) ) );
    $sport    = sanitize_key( $_POST['sport'] ?? '' );
    $sport    = in_array( $sport, STATSIGHT_ASK_ENGINE, true ) ? $sport : '';
    if ( $question === '' ) {
        wp_send_json_error( [ 'message' => 'Ask a question about a player, team, game or bet.' ], 400 );
    }
    if ( mb_strlen( $question ) > STATSIGHT_ASK_MAX_LEN ) {
        wp_send_json_error( [ 'message' => 'Keep questions under ' . STATSIGHT_ASK_MAX_LEN . ' characters.' ], 400 );
    }

    $cache_key = 'statsight_ask_c_' . md5( strtolower( $question ) . '|' . $sport );
    $cached    = get_transient( $cache_key );
    if ( is_array( $cached ) ) {
        wp_send_json_success( $cached + [ 'cached' => true ] );
    }

    $day_key = 'statsight_ask_day_' . gmdate( 'Ymd' );
    $day     = (int) get_transient( $day_key );
    if ( $day >= STATSIGHT_ASK_PER_DAY ) {
        wp_send_json_error( [ 'message' => 'The analyst has answered its limit for today. Try again tomorrow.' ], 429 );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        $hour_key = 'statsight_ask_h_' . md5( get_current_user_id() . gmdate( 'YmdH' ) );
        $used     = (int) get_transient( $hour_key );
        if ( $used >= STATSIGHT_ASK_PER_HOUR ) {
            wp_send_json_error( [ 'message' => "That's a lot of questions for one hour. Try again a little later." ], 429 );
        }
        set_transient( $hour_key, $used + 1, HOUR_IN_SECONDS );
    }
    set_transient( $day_key, $day + 1, DAY_IN_SECONDS );

    set_time_limit( 300 );   // up to 7 model calls; nearly all of it is waiting on the API
    $data = statsight_ask_answer( $question, $sport );
    if ( empty( $data['ok'] ) ) {
        wp_send_json_error( [ 'message' => $data['error'] ?? "The analyst couldn't answer that." ], 503 );
    }
    $resp = [ 'answer' => $data['answer'], 'tools' => array_values( array_unique( $data['tools'] ) ), 'sport' => $sport ];
    set_transient( $cache_key, $resp, STATSIGHT_ASK_CACHE_SECS );
    wp_send_json_success( $resp );
}
add_action( 'wp_ajax_statsight_ask_ai', 'statsight_ajax_ask_ai' );

/** Create the Ask AI page (slug "ask", rendered by page-ask.php) once. */
add_action( 'init', function (): void {
    if ( get_option( 'statsight_ask_page' ) ) {
        return;
    }
    if ( ! get_page_by_path( 'ask' ) ) {
        wp_insert_post( [ 'post_title' => 'Ask AI', 'post_name' => 'ask', 'post_type' => 'page', 'post_status' => 'publish' ] );
    }
    update_option( 'statsight_ask_page', 1 );
} );
