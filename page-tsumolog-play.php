<?php
/**
 * Template Name: Tsumolog 牌譜再生
 * Description: paifu-play.html をフルスクリーンで表示する専用ページテンプレート
 *
 * 設置場所: wp-content/themes/{your-theme}/page-tsumolog-play.php
 */

add_filter('show_admin_bar', '__return_false');
add_filter('body_class', function($classes){ return ['tsumolog-app-page']; });
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ツモログ 牌譜再生 | <?php bloginfo('name'); ?></title>
<?php wp_head(); ?>
<style>
html, body { margin: 0 !important; padding: 0 !important; overflow: hidden !important; height: 100%; background: #0f1117; }
#wpadminbar { display: none !important; }
html { margin-top: 0 !important; }
</style>
</head>
<body>
<?php
$app_file = get_template_directory() . '/tsumolog/paifu-play.html';
if ( file_exists( $app_file ) ) {
    $html = file_get_contents( $app_file );
    $html = preg_replace('/^[\s\S]*?<body[^>]*>/i', '', $html);
    $html = preg_replace('/<\/body>[\s\S]*$/i', '', $html);
    echo $html;
} else {
    echo '<div style="color:#fff;padding:40px;font-family:sans-serif;"><h2>⚠ paifu-play.html が見つかりません</h2></div>';
}
?>
<?php wp_footer(); ?>
</body>
</html>
