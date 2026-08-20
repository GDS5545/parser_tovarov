<?php
/**
 * Custom WooCommerce product category archive template for ANEP Woo Category Table.
 */
if (!defined('ABSPATH')) {
    exit;
}

get_header('shop');

do_action('woocommerce_before_main_content');
?>
<header class="woocommerce-products-header anep-wct-header">
    <?php if (apply_filters('woocommerce_show_page_title', true)) : ?>
        <h1 class="woocommerce-products-header__title page-title"><?php woocommerce_page_title(); ?></h1>
    <?php endif; ?>
    <?php do_action('woocommerce_archive_description'); ?>
</header>
<?php
ANEP_Woo_Category_Table::instance()->render_category_archive();

do_action('woocommerce_after_main_content');
do_action('woocommerce_sidebar');
get_footer('shop');
