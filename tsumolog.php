<?php
/**
 * Plugin Name: Tsumolog
 * Description: ツモログ用REST API（ユーザー情報・ポイント管理・牌譜管理）
 * Version: 2.0
 * Author: Tsumolog
 *
 * ── エンドポイント一覧 ──────────────────────────────────────────────
 *  GET  /wp-json/tsumolog/v1/user            ログイン中のユーザー情報
 *  POST /wp-json/tsumolog/v1/points          ポイント付与（日次上限あり）
 *  POST /wp-json/tsumolog/v1/points/spend    ポイント消費
 *  GET  /wp-json/tsumolog/v1/users           全ユーザー一覧（管理者のみ）
 *
 *  POST /wp-json/tsumolog/v1/kifu            牌譜を保存・更新（統合）
 *  GET  /wp-json/tsumolog/v1/kifu            完成済み牌譜一覧（公開）
 *  GET  /wp-json/tsumolog/v1/kifu/{id}       牌譜を1件取得（購入者のみ）
 *  POST /wp-json/tsumolog/v1/kifu/{id}/purchase  牌譜を購入
 *  GET  /wp-json/tsumolog/v1/purchases       自分の購入済み一覧
 * ──────────────────────────────────────────────────────────────────
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ══════════════════════════════════════════════════════════════════════
// § 0. 初期化
//    カスタム投稿タイプ "tsumolog_kifu" の登録
// ══════════════════════════════════════════════════════════════════════

add_action( 'init', 'tsumolog_register_post_type' );
function tsumolog_register_post_type() {
    register_post_type( 'tsumolog_kifu', [
        'labels'       => [ 'name' => '牌譜', 'singular_name' => '牌譜' ],
        'public'       => false,   // フロントには直接公開しない（API経由のみ）
        'show_ui'      => true,    // WP管理画面で確認できる
        'show_in_menu' => true,
        'supports'     => [ 'title', 'custom-fields' ],
        'capabilities' => [
            'create_posts' => 'manage_options',  // 管理者のみ投稿作成可（APIから作成）
        ],
        'map_meta_cap' => true,
    ]);
}


// ══════════════════════════════════════════════════════════════════════
// § 1. WPページへの設定値埋め込み
//    functions.php の代わりにプラグインから直接インジェクト
//    → <head> に tsumologConfig オブジェクトが出力される
// ══════════════════════════════════════════════════════════════════════

add_action( 'wp_head', 'tsumolog_inject_config' );
function tsumolog_inject_config() {
    // tsumolog 関連ページ（スラッグが paifu-* のページ）でのみ出力
    // 全ページで出したくない場合は is_page() などで条件を絞る
    $user = wp_get_current_user();
    $config = [
        'nonce'      => wp_create_nonce( 'wp_rest' ),
        'userId'     => $user->ID ? 'wp_' . $user->ID : null,
        'userName'   => $user->display_name ?: null,
        'isLoggedIn' => is_user_logged_in(),
        'apiBase'    => rest_url( 'tsumolog/v1' ),
        'loginUrl'   => wp_login_url( get_permalink() ),
    ];
    echo '<script>window.tsumologConfig = ' . json_encode( $config ) . ';</script>' . "\n";
}


// ══════════════════════════════════════════════════════════════════════
// § 2. REST API エンドポイント登録
// ══════════════════════════════════════════════════════════════════════

add_action( 'rest_api_init', function () {

    // ── ユーザー情報 ─────────────────────────────────────────────────
    register_rest_route( 'tsumolog/v1', '/user', [
        'methods'             => 'GET',
        'callback'            => 'tsumolog_get_user',
        'permission_callback' => 'tsumolog_is_logged_in',
    ]);

    // ── ポイント付与 ──────────────────────────────────────────────────
    register_rest_route( 'tsumolog/v1', '/points', [
        'methods'             => 'POST',
        'callback'            => 'tsumolog_grant_points',
        'permission_callback' => 'tsumolog_is_logged_in',
    ]);

    // ── ポイント消費 ──────────────────────────────────────────────────
    register_rest_route( 'tsumolog/v1', '/points/spend', [
        'methods'             => 'POST',
        'callback'            => 'tsumolog_spend_points',
        'permission_callback' => 'tsumolog_is_logged_in',
    ]);

    // ── 全ユーザー一覧（管理者のみ）──────────────────────────────────
    register_rest_route( 'tsumolog/v1', '/users', [
        'methods'             => 'GET',
        'callback'            => 'tsumolog_get_users',
        'permission_callback' => 'tsumolog_is_admin',
    ]);

    // ── 牌譜を保存・更新（ログイン必須）──────────────────────────────
    // フロントの TsumologLibrary.submitContrib() から呼ばれる
    register_rest_route( 'tsumolog/v1', '/kifu', [
        'methods'             => 'POST',
        'callback'            => 'tsumolog_save_kifu',
        'permission_callback' => 'tsumolog_is_logged_in',
    ]);

    // ── 完成牌譜一覧（公開・未ログインでも閲覧可）────────────────────
    register_rest_route( 'tsumolog/v1', '/kifu', [
        'methods'             => 'GET',
        'callback'            => 'tsumolog_list_kifu',
        'permission_callback' => '__return_true',
    ]);

    // ── 牌譜1件取得（購入者のみ内容を返す）──────────────────────────
    register_rest_route( 'tsumolog/v1', '/kifu/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'tsumolog_get_kifu',
        'permission_callback' => '__return_true',
        'args'                => [ 'id' => [ 'validate_callback' => fn($v) => is_numeric($v) ] ],
    ]);

    // ── 牌譜を購入（ポイント消費）────────────────────────────────────
    register_rest_route( 'tsumolog/v1', '/kifu/(?P<id>\d+)/purchase', [
        'methods'             => 'POST',
        'callback'            => 'tsumolog_purchase_kifu',
        'permission_callback' => 'tsumolog_is_logged_in',
        'args'                => [ 'id' => [ 'validate_callback' => fn($v) => is_numeric($v) ] ],
    ]);

    // ── 購入済み牌譜一覧 ──────────────────────────────────────────────
    register_rest_route( 'tsumolog/v1', '/purchases', [
        'methods'             => 'GET',
        'callback'            => 'tsumolog_get_purchases',
        'permission_callback' => 'tsumolog_is_logged_in',
    ]);

});


// ══════════════════════════════════════════════════════════════════════
// § 3. 権限チェック
// ══════════════════════════════════════════════════════════════════════

function tsumolog_is_logged_in() {
    return is_user_logged_in();
}
function tsumolog_is_admin() {
    return current_user_can( 'manage_options' );
}


// ══════════════════════════════════════════════════════════════════════
// § 4. GET /user — ユーザー情報取得
// ══════════════════════════════════════════════════════════════════════

function tsumolog_get_user() {
    $user    = wp_get_current_user();
    $user_id = $user->ID;

    $points     = (int) get_user_meta( $user_id, 'tsumolog_points', true );
    $log_raw    = get_user_meta( $user_id, 'tsumolog_log', true );
    $log        = $log_raw ? json_decode( $log_raw, true ) : [];
    $purchases  = get_user_meta( $user_id, 'tsumolog_purchases', true ) ?: [];
    $flags_raw  = get_user_meta( $user_id, 'tsumolog_flags', true );
    $flags      = $flags_raw ? json_decode( $flags_raw, true ) : [];

    return rest_ensure_response([
        'id'         => 'wp_' . $user_id,
        'name'       => $user->display_name,
        'email'      => $user->user_email,
        'points'     => $points,
        'log'        => array_slice( $log, 0, 200 ),
        'purchases'  => $purchases,   // 購入済み post_id の配列
        'flags'      => $flags,       // プレミアム購読フラグ等
        'createdAt'  => strtotime( $user->user_registered ) * 1000,
    ]);
}


// ══════════════════════════════════════════════════════════════════════
// § 5. POST /points — ポイント付与（日次上限つき）
//
//   不正防止のため task ごとに1日に付与できる上限を設けている。
//   上限はこの関数内の $daily_limits で管理する。
// ══════════════════════════════════════════════════════════════════════

// task ごとの1日の付与上限（pt）
define( 'TSUMOLOG_DAILY_LIMITS', [
    'new_kifu_start' => 50,   // 新規入力開始
    'submit_game'    => 300,  // 送信（1巡1ptで最大で大体この程度）
    'haipai'         => 30,   // 配牌入力
    'player_pos'     => 20,   // プレイヤー位置情報
    'turn'           => 100,  // ツモ・捨て牌（巡ごと）
    'meld'           => 30,   // 副露
    'final_wait'     => 30,   // 最終手牌・待ち
    'agari'          => 30,   // アガリ情報
] );

function tsumolog_grant_points( WP_REST_Request $req ) {
    $user_id = get_current_user_id();
    $task    = sanitize_text_field( $req->get_param('task') );
    $pts     = (int) $req->get_param('pts');
    $note    = sanitize_text_field( $req->get_param('note') );

    if ( $pts <= 0 ) {
        return new WP_Error( 'invalid_pts', 'ptsは1以上にしてください', [ 'status' => 400 ] );
    }

    // ── 日次上限チェック ──────────────────────────────────────────
    $limits = TSUMOLOG_DAILY_LIMITS;
    if ( isset( $limits[ $task ] ) ) {
        $today     = date('Y-m-d');
        $daily_key = "tsumolog_daily_{$task}_{$today}";
        $daily_pts = (int) get_user_meta( $user_id, $daily_key, true );

        if ( $daily_pts + $pts > $limits[ $task ] ) {
            // 残り付与可能分だけ付与する（0になる場合はエラー）
            $pts = $limits[ $task ] - $daily_pts;
            if ( $pts <= 0 ) {
                return new WP_Error( 'daily_limit', "本日の「{$task}」ポイント上限に達しました", [ 'status' => 429 ] );
            }
        }
        update_user_meta( $user_id, $daily_key, $daily_pts + $pts );

        // 翌日に古いメタが残らないよう30日後に自動削除（wp-cronで管理が面倒なため記録のみ）
        // ※ 実運用では cron か WP-Cron でのクリーンアップを推奨
    }

    // ── ポイント加算 ──────────────────────────────────────────────
    $current = (int) get_user_meta( $user_id, 'tsumolog_points', true );
    $new_pts = $current + $pts;
    update_user_meta( $user_id, 'tsumolog_points', $new_pts );

    // ── ログ追記 ──────────────────────────────────────────────────
    $log_raw = get_user_meta( $user_id, 'tsumolog_log', true );
    $log     = $log_raw ? json_decode( $log_raw, true ) : [];
    array_unshift( $log, [
        'task' => $task,
        'pts'  => $pts,
        'note' => $note,
        'ts'   => time() * 1000,
    ]);
    $log = array_slice( $log, 0, 200 );
    update_user_meta( $user_id, 'tsumolog_log', json_encode( $log ) );

    return rest_ensure_response([
        'points'  => $new_pts,
        'granted' => $pts,
    ]);
}


// ══════════════════════════════════════════════════════════════════════
// § 6. POST /points/spend — ポイント消費
// ══════════════════════════════════════════════════════════════════════

function tsumolog_spend_points( WP_REST_Request $req ) {
    $user_id = get_current_user_id();
    $task    = sanitize_text_field( $req->get_param('task') );
    $pts     = (int) $req->get_param('pts');
    $note    = sanitize_text_field( $req->get_param('note') );

    $current = (int) get_user_meta( $user_id, 'tsumolog_points', true );

    if ( $current < $pts ) {
        return rest_ensure_response([
            'ok'     => false,
            'reason' => 'insufficient_points',
            'points' => $current,
        ]);
    }

    $new_pts = $current - $pts;
    update_user_meta( $user_id, 'tsumolog_points', $new_pts );

    $log_raw = get_user_meta( $user_id, 'tsumolog_log', true );
    $log     = $log_raw ? json_decode( $log_raw, true ) : [];
    array_unshift( $log, [
        'task' => $task,
        'pts'  => -$pts,
        'note' => $note,
        'ts'   => time() * 1000,
    ]);
    $log = array_slice( $log, 0, 200 );
    update_user_meta( $user_id, 'tsumolog_log', json_encode( $log ) );

    return rest_ensure_response([
        'ok'     => true,
        'points' => $new_pts,
    ]);
}


// ══════════════════════════════════════════════════════════════════════
// § 7. GET /users — 全ユーザー一覧（管理者用）
// ══════════════════════════════════════════════════════════════════════

function tsumolog_get_users() {
    $users  = get_users([ 'number' => 500 ]);
    $result = [];

    foreach ( $users as $user ) {
        $pts     = (int) get_user_meta( $user->ID, 'tsumolog_points', true );
        $log_raw = get_user_meta( $user->ID, 'tsumolog_log', true );
        $log     = $log_raw ? json_decode( $log_raw, true ) : [];
        $inputs  = count( array_filter( $log, fn($l) => ($l['task'] ?? '') === 'submit_game' ) );

        $result[] = [
            'id'         => 'wp_' . $user->ID,
            'name'       => $user->display_name,
            'pts'        => $pts,
            'inputs'     => $inputs,
            'joined'     => substr( $user->user_registered, 0, 10 ),
            'lastActive' => count($log) ? date('Y-m-d', ($log[0]['ts'] ?? 0) / 1000) : '—',
            'isPro'      => in_array( 'tsumolog_pro', $user->roles ),
            'isPlayer'   => in_array( 'tsumolog_player', $user->roles ),
            'log'        => array_slice( $log, 0, 20 ),
        ];
    }

    usort( $result, fn($a, $b) => $b['pts'] - $a['pts'] );
    return rest_ensure_response( $result );
}


// ══════════════════════════════════════════════════════════════════════
// § 8. POST /kifu — 牌譜を保存・更新
//
//   フロントの TsumologLibrary.submitContrib() から呼ばれる。
//   matchKey で既存投稿を検索し、あれば更新、なければ新規作成。
//   status が "complete" のとき post_status = 'publish'（ショップに公開）。
//   "partial" のときは 'draft'（非公開）。
// ══════════════════════════════════════════════════════════════════════

function tsumolog_save_kifu( WP_REST_Request $req ) {
    $user_id   = get_current_user_id();
    $body      = $req->get_json_params();

    // 必須フィールドチェック
    if ( empty( $body['matchKey'] ) || empty( $body['gameData'] ) ) {
        return new WP_Error( 'invalid_kifu', 'matchKey と gameData は必須です', [ 'status' => 400 ] );
    }

    $match_key   = sanitize_text_field( $body['matchKey'] );
    $status      = isset( $body['status'] ) && $body['status'] === 'complete' ? 'complete' : 'partial';
    $post_status = $status === 'complete' ? 'publish' : 'draft';
    $kifu_json   = wp_json_encode( $body );  // v2.0 JSON 全体を保存

    // タイトル生成（管理画面で識別しやすくする）
    $meta = $body['gameData']['meta'] ?? [];
    $title = ( $body['match']['title'] ?? '' )
           ?: ( ( $meta['bakaze'] ?? '' ) . ( $meta['kyoku'] ?? '' ) . '局' );

    // matchKey で既存投稿を検索
    $existing = get_posts([
        'post_type'      => 'tsumolog_kifu',
        'posts_per_page' => 1,
        'meta_key'       => '_kifu_match_key',
        'meta_value'     => $match_key,
        'post_status'    => [ 'publish', 'draft' ],
    ]);

    if ( $existing ) {
        // ── 既存更新 ────────────────────────────────────────────
        $post_id = $existing[0]->ID;

        // 既存JSONと新しいcontributionsをマージ（サーバー側でもマージ処理）
        $existing_json = $existing[0]->post_content;
        $existing_data = $existing_json ? json_decode( $existing_json, true ) : null;

        if ( $existing_data && isset( $body['contributions'] ) ) {
            $merged = tsumolog_merge_contributions( $existing_data, $body['contributions'] );
            $kifu_json = wp_json_encode( $merged );
            // マージ後のstatus再評価
            $status      = ( $merged['status'] ?? 'partial' );
            $post_status = $status === 'complete' ? 'publish' : 'draft';
        }

        wp_update_post([
            'ID'           => $post_id,
            'post_content' => $kifu_json,
            'post_status'  => $post_status,
            'post_title'   => sanitize_text_field( $title ),
        ]);

    } else {
        // ── 新規作成 ────────────────────────────────────────────
        $post_id = wp_insert_post([
            'post_type'    => 'tsumolog_kifu',
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => $kifu_json,
            'post_status'  => $post_status,
            'post_author'  => $user_id,
        ]);

        if ( is_wp_error( $post_id ) ) {
            return new WP_Error( 'save_failed', '保存に失敗しました', [ 'status' => 500 ] );
        }
    }

    // メタデータ更新
    update_post_meta( $post_id, '_kifu_match_key',    $match_key );
    update_post_meta( $post_id, '_kifu_status',       $status );
    update_post_meta( $post_id, '_kifu_kifuId',       $body['kifuId'] ?? '' );
    update_post_meta( $post_id, '_kifu_completeness', $body['completeness']['total'] ?? 0 );
    update_post_meta( $post_id, '_kifu_date',         $body['match']['date'] ?? '' );
    update_post_meta( $post_id, '_kifu_org',          $body['match']['org'] ?? '' );
    update_post_meta( $post_id, '_kifu_tournament',   $body['match']['tournament'] ?? '' );

    return rest_ensure_response([
        'ok'      => true,
        'post_id' => $post_id,
        'kifuId'  => $body['kifuId'] ?? '',
        'status'  => $status,
    ]);
}

/**
 * サーバー側でcontributionsをマージする（フロントのmergeContribs相当）
 * 同じ seat の contribution は最新のもので上書き。
 */
function tsumolog_merge_contributions( array $existing, array $new_contribs ): array {
    if ( ! isset( $existing['contributions'] ) ) {
        $existing['contributions'] = [];
    }

    foreach ( $new_contribs as $new_c ) {
        $seat    = $new_c['seat'] ?? '';
        $user_id = $new_c['userId'] ?? '';
        $found   = false;

        foreach ( $existing['contributions'] as &$ex_c ) {
            if ( $ex_c['seat'] === $seat && $ex_c['userId'] === $user_id ) {
                $ex_c  = $new_c;  // 上書き
                $found = true;
                break;
            }
        }
        unset( $ex_c );

        if ( ! $found ) {
            $existing['contributions'][] = $new_c;
        }

        // gameData の対応する席データを更新
        if ( $seat && isset( $new_c['data'] ) ) {
            $d = $new_c['data'];
            if ( ! empty( $d['haipai'] ) )   $existing['gameData']['initHands'][$seat] = $d['haipai'];
            if ( ! empty( $d['discards'] ) )  $existing['gameData']['discards'][$seat]  = $d['discards'];
            if ( ! empty( $d['finalHand'] ) ) $existing['gameData']['players'][$seat]['hand']      = $d['finalHand'];
            if ( ! empty( $d['tsumo'] ) )     $existing['gameData']['players'][$seat]['tsumo']     = $d['tsumo'];
            if ( ! empty( $d['melds'] ) )     $existing['gameData']['players'][$seat]['melds']     = $d['melds'];
            if ( ! empty( $d['wait'] ) )      $existing['gameData']['players'][$seat]['wait']      = $d['wait'];
            if ( isset( $d['riichiDiscIdx'] ) && $d['riichiDiscIdx'] >= 0 ) {
                $existing['gameData']['players'][$seat]['riichiIdx'] = $d['riichiDiscIdx'];
            }
        }
    }

    // 完成度再計算（4席入力済みで complete）
    $filled_seats = array_unique( array_column( $existing['contributions'], 'seat' ) );
    $total        = (int) ( $existing['completeness']['total'] ?? 0 );
    if ( count( $filled_seats ) >= 4 || $total >= 100 ) {
        $existing['status'] = 'complete';
    }
    $existing['updatedAt'] = gmdate( 'c' );

    return $existing;
}


// ══════════════════════════════════════════════════════════════════════
// § 9. GET /kifu — 完成牌譜一覧（ショップ表示用）
//
//   status = "complete"（post_status = publish）の牌譜のみ返す。
//   JSONの中身（gameData）は返さない。メタ情報のみ。
//   フロントのポイントショップで一覧表示・検索に使う。
// ══════════════════════════════════════════════════════════════════════

function tsumolog_list_kifu( WP_REST_Request $req ) {
    $per_page = min( (int) ( $req->get_param('per_page') ?? 20 ), 50 );
    $page     = max( (int) ( $req->get_param('page') ?? 1 ), 1 );
    $search   = sanitize_text_field( $req->get_param('search') ?? '' );
    $org      = sanitize_text_field( $req->get_param('org') ?? '' );
    $tour     = sanitize_text_field( $req->get_param('tournament') ?? '' );

    $args = [
        'post_type'      => 'tsumolog_kifu',
        'post_status'    => 'publish',   // complete のもののみ
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    // メタクエリ（団体・大会フィルタ）
    $meta_query = [];
    if ( $org )  $meta_query[] = [ 'key' => '_kifu_org',        'value' => $org,  'compare' => '=' ];
    if ( $tour ) $meta_query[] = [ 'key' => '_kifu_tournament', 'value' => $tour, 'compare' => '=' ];
    if ( $meta_query ) $args['meta_query'] = $meta_query;

    // タイトル検索
    if ( $search ) $args['s'] = $search;

    $posts = get_posts( $args );
    $total = wp_count_posts( 'tsumolog_kifu' )->publish;

    $result = array_map( function( $post ) {
        return [
            'post_id'      => $post->ID,
            'kifuId'       => get_post_meta( $post->ID, '_kifu_kifuId', true ),
            'title'        => $post->post_title,
            'date'         => get_post_meta( $post->ID, '_kifu_date', true ),
            'org'          => get_post_meta( $post->ID, '_kifu_org', true ),
            'tournament'   => get_post_meta( $post->ID, '_kifu_tournament', true ),
            'completeness' => (int) get_post_meta( $post->ID, '_kifu_completeness', true ),
            'createdAt'    => get_the_date( 'c', $post ),
            'price'        => 30,   // 将来的に shop.csv から取得する
        ];
    }, $posts );

    return rest_ensure_response([
        'items' => $result,
        'total' => (int) $total,
        'pages' => ceil( $total / $per_page ),
    ]);
}


// ══════════════════════════════════════════════════════════════════════
// § 10. GET /kifu/{id} — 牌譜1件取得
//
//   購入済みユーザーと管理者にのみ gameData（JSON全体）を返す。
//   未購入のユーザーにはメタ情報のみ（プレビュー用）。
// ══════════════════════════════════════════════════════════════════════

function tsumolog_get_kifu( WP_REST_Request $req ) {
    $post_id = (int) $req->get_param('id');
    $post    = get_post( $post_id );

    if ( ! $post || $post->post_type !== 'tsumolog_kifu' ) {
        return new WP_Error( 'not_found', '牌譜が見つかりません', [ 'status' => 404 ] );
    }

    if ( $post->post_status !== 'publish' && ! current_user_can('manage_options') ) {
        return new WP_Error( 'not_published', 'この牌譜はまだ公開されていません', [ 'status' => 403 ] );
    }

    // 購入済みか確認
    $user_id   = get_current_user_id();
    $purchases = get_user_meta( $user_id, 'tsumolog_purchases', true ) ?: [];
    $purchased = in_array( $post_id, array_map('intval', $purchases) )
              || current_user_can('manage_options');

    $meta = [
        'post_id'      => $post_id,
        'kifuId'       => get_post_meta( $post_id, '_kifu_kifuId', true ),
        'title'        => $post->post_title,
        'date'         => get_post_meta( $post_id, '_kifu_date', true ),
        'org'          => get_post_meta( $post_id, '_kifu_org', true ),
        'tournament'   => get_post_meta( $post_id, '_kifu_tournament', true ),
        'completeness' => (int) get_post_meta( $post_id, '_kifu_completeness', true ),
        'purchased'    => $purchased,
        'price'        => 30,
    ];

    if ( $purchased ) {
        // JSON全体を返す
        $meta['json'] = json_decode( $post->post_content, true );
    }

    return rest_ensure_response( $meta );
}


// ══════════════════════════════════════════════════════════════════════
// § 11. POST /kifu/{id}/purchase — 牌譜を購入
//
//   ポイントを消費して購入履歴に追加。JSONを返す。
//   同じ牌譜を二重購入しようとするとエラー。
// ══════════════════════════════════════════════════════════════════════

function tsumolog_purchase_kifu( WP_REST_Request $req ) {
    $user_id = get_current_user_id();
    $post_id = (int) $req->get_param('id');
    $post    = get_post( $post_id );

    if ( ! $post || $post->post_type !== 'tsumolog_kifu' || $post->post_status !== 'publish' ) {
        return new WP_Error( 'not_found', '購入できる牌譜が見つかりません', [ 'status' => 404 ] );
    }

    // 二重購入チェック
    $purchases = get_user_meta( $user_id, 'tsumolog_purchases', true ) ?: [];
    if ( in_array( $post_id, array_map('intval', $purchases) ) ) {
        // 既に購入済み → JSONをそのまま返す（エラーにしない）
        return rest_ensure_response([
            'ok'      => true,
            'already' => true,
            'json'    => json_decode( $post->post_content, true ),
        ]);
    }

    // ポイント消費
    $price   = 30;   // 将来的に shop.csv / post_meta から取得
    $current = (int) get_user_meta( $user_id, 'tsumolog_points', true );

    if ( $current < $price ) {
        return rest_ensure_response([
            'ok'     => false,
            'reason' => 'insufficient_points',
            'points' => $current,
            'price'  => $price,
        ]);
    }

    $new_pts = $current - $price;
    update_user_meta( $user_id, 'tsumolog_points', $new_pts );

    // 購入履歴に追加
    $purchases[] = $post_id;
    update_user_meta( $user_id, 'tsumolog_purchases', $purchases );

    // ポイントログ
    $log_raw = get_user_meta( $user_id, 'tsumolog_log', true );
    $log     = $log_raw ? json_decode( $log_raw, true ) : [];
    array_unshift( $log, [
        'task' => 'download_kifu',
        'pts'  => -$price,
        'note' => "牌譜DL: {$post->post_title}",
        'ts'   => time() * 1000,
    ]);
    update_user_meta( $user_id, 'tsumolog_log', json_encode( array_slice( $log, 0, 200 ) ) );

    return rest_ensure_response([
        'ok'     => true,
        'points' => $new_pts,
        'json'   => json_decode( $post->post_content, true ),
    ]);
}


// ══════════════════════════════════════════════════════════════════════
// § 12. GET /purchases — 購入済み牌譜一覧
//
//   ユーザーが過去に購入した牌譜のメタ情報一覧を返す。
//   フロントのショップ「購入済み」タブで表示する。
// ══════════════════════════════════════════════════════════════════════

function tsumolog_get_purchases( WP_REST_Request $req ) {
    $user_id   = get_current_user_id();
    $purchases = get_user_meta( $user_id, 'tsumolog_purchases', true ) ?: [];

    if ( empty( $purchases ) ) {
        return rest_ensure_response( [] );
    }

    $posts = get_posts([
        'post_type'      => 'tsumolog_kifu',
        'post__in'       => array_map( 'intval', $purchases ),
        'posts_per_page' => -1,
        'post_status'    => [ 'publish', 'draft' ],
    ]);

    $result = array_map( function( $post ) {
        return [
            'post_id'    => $post->ID,
            'kifuId'     => get_post_meta( $post->ID, '_kifu_kifuId', true ),
            'title'      => $post->post_title,
            'date'       => get_post_meta( $post->ID, '_kifu_date', true ),
            'org'        => get_post_meta( $post->ID, '_kifu_org', true ),
            'tournament' => get_post_meta( $post->ID, '_kifu_tournament', true ),
            'purchased'  => true,
        ];
    }, $posts );

    return rest_ensure_response( $result );
}
