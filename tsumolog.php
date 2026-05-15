<?php
/**
 * Plugin Name: Tsumolog
 * Description: ツモログ用REST API（ユーザー情報・ポイント管理）
 * Version: 1.0
 * Author: Tsumolog
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ──────────────────────────────────────────
// REST APIエンドポイント登録
// ──────────────────────────────────────────
add_action( 'rest_api_init', function () {

    // GET /wp-json/tsumolog/v1/user
    // ログイン中のユーザー情報を返す
    register_rest_route( 'tsumolog/v1', '/user', [
        'methods'             => 'GET',
        'callback'            => 'tsumolog_get_user',
        'permission_callback' => 'tsumolog_is_logged_in',
    ]);

    // POST /wp-json/tsumolog/v1/points
    // ポイントを付与する
    register_rest_route( 'tsumolog/v1', '/points', [
        'methods'             => 'POST',
        'callback'            => 'tsumolog_grant_points',
        'permission_callback' => 'tsumolog_is_logged_in',
    ]);

    // POST /wp-json/tsumolog/v1/points/spend
    // ポイントを消費する
    register_rest_route( 'tsumolog/v1', '/points/spend', [
        'methods'             => 'POST',
        'callback'            => 'tsumolog_spend_points',
        'permission_callback' => 'tsumolog_is_logged_in',
    ]);

    // GET /wp-json/tsumolog/v1/users
    // 全ユーザー一覧（管理者のみ）
    register_rest_route( 'tsumolog/v1', '/users', [
        'methods'             => 'GET',
        'callback'            => 'tsumolog_get_users',
        'permission_callback' => 'tsumolog_is_admin',
    ]);

});

// ──────────────────────────────────────────
// 権限チェック
// ──────────────────────────────────────────
function tsumolog_is_logged_in() {
    return is_user_logged_in();
}
function tsumolog_is_admin() {
    return current_user_can( 'manage_options' );
}

// ──────────────────────────────────────────
// GET /user — ユーザー情報取得
// ──────────────────────────────────────────
function tsumolog_get_user() {
    $user    = wp_get_current_user();
    $user_id = $user->ID;

    $points  = (int) get_user_meta( $user_id, 'tsumolog_points', true );
    $log_raw = get_user_meta( $user_id, 'tsumolog_log',    true );
    $log     = $log_raw ? json_decode( $log_raw, true ) : [];

    return rest_ensure_response([
        'id'        => 'wp_' . $user_id,
        'name'      => $user->display_name,   // ← WordPressの表示名
        'email'     => $user->user_email,
        'points'    => $points,
        'log'       => array_slice( $log, 0, 200 ),
        'createdAt' => strtotime( $user->user_registered ) * 1000,
    ]);
}

// ──────────────────────────────────────────
// POST /points — ポイント付与
// ──────────────────────────────────────────
function tsumolog_grant_points( WP_REST_Request $req ) {
    $user_id = get_current_user_id();
    $task    = sanitize_text_field( $req->get_param('task') );
    $pts     = (int) $req->get_param('pts');
    $note    = sanitize_text_field( $req->get_param('note') );

    if ( $pts <= 0 ) {
        return new WP_Error( 'invalid_pts', 'ptsは1以上', ['status' => 400] );
    }

    // 現在のポイントに加算
    $current = (int) get_user_meta( $user_id, 'tsumolog_points', true );
    $new_pts = $current + $pts;
    update_user_meta( $user_id, 'tsumolog_points', $new_pts );

    // ログに追記
    $log_raw = get_user_meta( $user_id, 'tsumolog_log', true );
    $log     = $log_raw ? json_decode( $log_raw, true ) : [];
    array_unshift( $log, [
        'task' => $task,
        'pts'  => $pts,
        'note' => $note,
        'ts'   => time() * 1000,
    ]);
    $log = array_slice( $log, 0, 200 ); // 最大200件
    update_user_meta( $user_id, 'tsumolog_log', json_encode( $log ) );

    return rest_ensure_response([
        'points'  => $new_pts,
        'granted' => $pts,
    ]);
}

// ──────────────────────────────────────────
// POST /points/spend — ポイント消費
// ──────────────────────────────────────────
function tsumolog_spend_points( WP_REST_Request $req ) {
    $user_id = get_current_user_id();
    $task    = sanitize_text_field( $req->get_param('task') );
    $pts     = (int) $req->get_param('pts');
    $note    = sanitize_text_field( $req->get_param('note') );

    $current = (int) get_user_meta( $user_id, 'tsumolog_points', true );

    if ( $current < $pts ) {
        return rest_ensure_response([
            'ok'     => false,
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

// ──────────────────────────────────────────
// GET /users — 全ユーザー一覧（管理者用）
// ──────────────────────────────────────────
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

    // ポイント降順
    usort( $result, fn($a, $b) => $b['pts'] - $a['pts'] );

    return rest_ensure_response( $result );
}
