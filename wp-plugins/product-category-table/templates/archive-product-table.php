<?php
/**
 * Шаблон архива категории товаров для Product Category Table.
 */
if (!defined('ABSPATH')) {
    exit;
}

get_header('shop');

do_action('woocommerce_before_main_content');

$plugin = PCT_Plugin::instance();
$updated_date = trim((string) $plugin->get_option('catalog_updated_date', ''));
?>
<header class="woocommerce-products-header pct-header">
    <?php if (apply_filters('woocommerce_show_page_title', true)) : ?>
        <h1 class="woocommerce-products-header__title page-title pct-title"><?php woocommerce_page_title(); ?></h1>
    <?php endif; ?>
    <?php if ($updated_date !== '') : ?>
        <div class="pct-updated"><span>Сортамент товаров актуализирован:</span> <strong><?php echo esc_html($updated_date); ?></strong></div>
    <?php endif; ?>
    <?php do_action('woocommerce_archive_description'); ?>
</header>
<?php
$plugin->render_category_archive();

do_action('woocommerce_after_main_content');
do_action('woocommerce_sidebar');
get_footer('shop');
