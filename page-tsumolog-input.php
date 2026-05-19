<?php
/**
 * Template Name: Tsumolog 牌譜入力
 * Description: paifu-input.html をフルスクリーンで表示する専用ページテンプレート
 *
 * 設置場所: wp-content/themes/{your-theme}/page-tsumolog-input.php
 */

// ── WPの認証チェック（未ログインならログインページへ）──
// コメントアウトするとゲスト（未ログイン）でも使えるようになる
// if ( ! is_user_logged_in() ) {
//     wp_redirect( wp_login_url( get_permalink() ) );
//     exit;
// }

// WPの管理バーを非表示にする
add_filter('show_admin_bar', '__return_false');

// WPのデフォルトCSSが干渉しないようbody classをリセット
add_filter('body_class', function($classes){
    return ['tsumolog-app-page'];
});
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ツモログ 牌譜入力 | <?php bloginfo('name'); ?></title>
<?php
// wp_head() で以下が自動出力される:
//   - tsumologConfig（nonce・userId・isLoggedIn等）← tsumolog.php の wp_head フック
//   - WPのセキュリティ関連メタ
wp_head();
?>
<style>
/* WPテーマのスタイルをリセット */
html, body {
  margin: 0 !important;
  padding: 0 !important;
  overflow: hidden !important; /* アプリ内でスクロール管理するため */
  height: 100%;
  background: #0f1117;
}
/* WPの管理バー分のオフセットをキャンセル */
#wpadminbar { display: none !important; }
html { margin-top: 0 !important; }
</style>
</head>
<body>
<?php
// paifu-input.html の <body> 内コンテンツを取得して展開
// HTMLファイルはテーマフォルダ内の tsumolog/ に置く
$app_file = get_template_directory() . '/tsumolog/paifu-input.html';

if ( file_exists( $app_file ) ) {
    $html = file_get_contents( $app_file );
    // <html>〜<head>〜</head><body> と </body></html> を除去して中身だけ取り出す
    $html = preg_replace('/^[\s\S]*?<body[^>]*>/i', '', $html);
    $html = preg_replace('/<\/body>[\s\S]*$/i', '', $html);
    echo $html;
} else {
    echo '<div style="color:#fff;padding:40px;font-family:sans-serif;">';
    echo '<h2>⚠ paifu-input.html が見つかりません</h2>';
    echo '<p>wp-content/themes/' . get_template() . '/tsumolog/paifu-input.html を配置してください。</p>';
    echo '</div>';
}
?>
<?php wp_footer(); ?>
</body>
</html>
