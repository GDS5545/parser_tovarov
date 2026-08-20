<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap ptv-help">
	<h1><?php esc_html_e( 'Как пользоваться плагином', 'parser-tovarov' ); ?></h1>

	<h2><?php esc_html_e( '1. Подготовьте товары', 'parser-tovarov' ); ?></h2>
	<p><?php esc_html_e( 'Плагин показывает обычные товары WooCommerce. Столбцы фильтра/таблицы — это глобальные атрибуты товара (Товары → Атрибуты), например «Марка», «Диаметр, мм», «Толщина стенки, мм», «ГОСТ/ТУ». Каждому товару нужно проставить нужные атрибуты на вкладке «Атрибуты» карточки товара.', 'parser-tovarov' ); ?></p>

	<h2><?php esc_html_e( '2. Вставьте шорткод', 'parser-tovarov' ); ?></h2>
	<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;overflow:auto;">[ptv_catalog
	categories="truby-nerzhaveyuschie,krug-nerzhaveyuschij"
	attributes="marka,diametr,tolschina-stenki,gost"
	columns="name,marka,diametr,tolschina-stenki,gost,price"
	quick_filter="marka"
	quick_filter_limit="14"
	per_page="20"
	title="Труба нержавеющая"
]</pre>

	<table class="widefat" style="max-width:900px;">
		<thead>
			<tr><th><?php esc_html_e( 'Параметр', 'parser-tovarov' ); ?></th><th><?php esc_html_e( 'Описание', 'parser-tovarov' ); ?></th></tr>
		</thead>
		<tbody>
			<tr><td><code>categories</code></td><td><?php esc_html_e( 'Слаги одной или нескольких категорий товаров через запятую — таблица покажет товары из всех сразу.', 'parser-tovarov' ); ?></td></tr>
			<tr><td><code>attributes</code></td><td><?php esc_html_e( 'Атрибуты для выпадающих фильтров (слаг без "pa_", он подставится автоматически).', 'parser-tovarov' ); ?></td></tr>
			<tr><td><code>columns</code></td><td><?php esc_html_e( 'Столбцы таблицы: name, price, sku и любые атрибуты. По умолчанию — name + все attributes + price.', 'parser-tovarov' ); ?></td></tr>
			<tr><td><code>quick_filter</code></td><td><?php esc_html_e( 'Атрибут для строки быстрых кнопок над таблицей (по умолчанию — первый из attributes).', 'parser-tovarov' ); ?></td></tr>
			<tr><td><code>per_page</code></td><td><?php esc_html_e( 'Товаров на странице (постраничная подгрузка через AJAX — сайт не тормозит даже на больших каталогах).', 'parser-tovarov' ); ?></td></tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( '3. Как это работает для покупателя', 'parser-tovarov' ); ?></h2>
	<ol>
		<li><?php esc_html_e( 'Покупатель фильтрует таблицу выпадающими списками, быстрыми кнопками или поиском — строки подгружаются через AJAX без перезагрузки страницы.', 'parser-tovarov' ); ?></li>
		<li><?php esc_html_e( 'Нажимает «Купить» — товар уходит в корзину WooCommerce, и сразу открывается корзина.', 'parser-tovarov' ); ?></li>
		<li><?php esc_html_e( 'В корзине два варианта: «Оформить заказ» (форма имя/телефон/комментарий/файл → заявка сохраняется в разделе «Заявки» и уходит в Bitrix24) либо «Продолжить выбор на сайте» (корзина просто закрывается, товары остаются).', 'parser-tovarov' ); ?></li>
	</ol>

	<h2><?php esc_html_e( '4. Производительность', 'parser-tovarov' ); ?></h2>
	<ul>
		<li><?php esc_html_e( 'CSS и JS подключаются только на страницах, где реально используется [ptv_catalog].', 'parser-tovarov' ); ?></li>
		<li><?php esc_html_e( 'Таблица не загружает весь каталог сразу — только текущую страницу (см. «Товаров на странице» в настройках).', 'parser-tovarov' ); ?></li>
		<li><?php esc_html_e( 'Списки значений фильтров кэшируются и автоматически сбрасываются при изменении товаров.', 'parser-tovarov' ); ?></li>
	</ul>
</div>
