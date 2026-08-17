<?php
/**
 * Универсальное распознавание страницы.
 *
 * Никаких профилей конкретных сайтов. Порядок источников данных одинаков
 * для любого движка и идет от самого надежного к самому приблизительному:
 *
 *   1. JSON-LD schema.org/Product и BreadcrumbList
 *   2. Микроразметка itemtype="…/Product"
 *   3. OpenGraph / meta product:*
 *   4. Структурные признаки корзины, цены, артикула
 *   5. Ручные CSS-селекторы из настроек (перебивают все остальное)
 */

if (!defined('ABSPATH')) { exit; }

class UWP_Extractor {

    /** Порог суммы признаков, начиная с которого страница считается карточкой товара. */
    const PRODUCT_SCORE_THRESHOLD = 4;

    /** @var UWP_Dom */
    private $dom;

    /** @var string */
    private $url;

    /** @var array|null */
    private $ld_cache = null;

    public function __construct($html, $url) {
        $this->dom = new UWP_Dom($html);
        $this->url = $url;
    }

    public function dom() {
        return $this->dom;
    }

    /**
     * Главный метод: чем является страница и что с нее можно взять.
     *
     * @return array
     */
    public function analyze() {
        $product = $this->product_data();

        return array(
            'is_product'  => $product !== null,
            'product'     => $product,
            'breadcrumbs' => $this->breadcrumbs(),
            'title'       => $this->page_title(),
        );
    }

    // ---------------------------------------------------------------------
    // Определение товара
    // ---------------------------------------------------------------------

    /**
     * @return array|null null, если страница не карточка товара.
     */
    public function product_data() {
        $ld = $this->ld_product();

        $signals = $this->product_signals($ld !== null);
        if ($signals['score'] < self::PRODUCT_SCORE_THRESHOLD) { return null; }

        $title = $this->product_title($ld);
        if ($title === '') { return null; }

        $price_data = $this->product_price($ld);

        $data = array(
            'url'         => $this->url,
            'title'       => $title,
            'sku'         => $this->product_sku($ld),
            'price'       => $price_data['price'],
            'currency'    => $price_data['currency'],
            'in_stock'    => $this->product_stock($ld),
            'description' => $this->product_description($ld),
            'images'      => $this->product_images($ld),
            'attributes'  => $this->product_attributes(),
            'brand'       => $this->product_brand($ld),
            'signals'     => $signals['matched'],
        );

        return $data;
    }

    /**
     * Набор признаков карточки товара. Балльная система вместо жестких правил:
     * ни один признак не обязателен, но их сочетание однозначно.
     */
    private function product_signals($has_ld_product) {
        $score   = 0;
        $matched = array();

        // --- Сильные признаки: разметка прямо объявляет страницу товаром.
        if ($has_ld_product) {
            $score += 6;
            $matched[] = 'JSON-LD Product';
        }

        if ($this->dom->first('[itemtype*="schema.org/Product"]') || $this->dom->first('[itemtype*="schema.org/IndividualProduct"]')) {
            $score += 6;
            $matched[] = 'микроразметка Product';
        }

        $og_type = mb_strtolower($this->dom->meta('og:type'));
        if ($og_type !== '' && preg_match('~product|item|goods~', $og_type)) {
            $score += 5;
            $matched[] = 'og:type=' . $og_type;
        }

        if ($this->dom->meta('product:price:amount') !== '') {
            $score += 5;
            $matched[] = 'meta product:price';
        }

        $strong = $score > 0;

        // --- Средние признаки: элементы, которые бывают только на карточке.
        $buy = $this->buy_controls();
        if ($buy['count'] > 0) {
            $score += 2;
            $matched[] = 'кнопка покупки (' . $buy['how'] . ')';
        }

        if ($this->has_quantity_input()) {
            $score += 2;
            $matched[] = 'поле количества';
        }

        $prices = $this->price_nodes();
        if ($prices['count'] > 0) {
            $score += 2;
            $matched[] = 'цена на странице';
        }

        if ($this->has_sku_marker()) {
            $score += 2;
            $matched[] = 'артикул';
        }

        // --- Слабые признаки.
        if (count($this->dom->tags('h1')) === 1) {
            $score += 1;
            $matched[] = 'один H1';
        }

        if ($this->dom->first('[class*="product-gallery"]') || $this->dom->first('[class*="product-image"]')
            || $this->dom->first('[class*="product__image"]') || $this->dom->first('[class*="detail-gallery"]')
            || $this->dom->first('[class*="thumbs"]')) {
            $score += 1;
            $matched[] = 'галерея';
        }

        if ($this->dom->first('[class*="characteristic"]') || $this->dom->first('[class*="harakteristik"]')
            || $this->dom->first('[class*="specification"]') || $this->dom->first('.shop_attributes')) {
            $score += 1;
            $matched[] = 'блок характеристик';
        }

        // --- Отрицательный признак: это витрина раздела, а не одна карточка.
        //
        // Считать карточки в лоб нельзя: на нормальной странице товара почти всегда
        // есть блок «похожие товары», и раньше он один опускал оценку ниже порога.
        // Поэтому листингом страница признается только по массовости —
        // много цен и много кнопок покупки сразу — и только когда разметка
        // не объявила товар явно.
        $listing = $this->listing_evidence($prices['count'], $buy['count']);
        if (!$strong && $listing['is_listing']) {
            $score -= 5;
            $matched[] = 'похоже на витрину раздела: ' . $listing['reason'] . ' (-)';
        }

        return array('score' => $score, 'matched' => $matched, 'strong' => $strong);
    }

    /**
     * Кнопки и ссылки покупки. Ищем и по классам, и по тексту:
     * на самописных сайтах класс может быть любым, а надпись — почти всегда
     * «Купить», «В корзину», «Заказать» или их английский аналог.
     *
     * @return array array('count' => int, 'how' => string)
     */
    private function buy_controls() {
        $by_class = 0;
        $selectors = array(
            'form.cart', 'form[action*="cart"]', 'form[action*="basket"]', 'form[action*="korzina"]',
            'button[name="add-to-cart"]', 'input[name="add-to-cart"]',
            '[class*="add-to-cart"]', '[class*="add_to_cart"]', '[class*="addtocart"]',
            '[class*="buy"]', '[id*="buy"]', '[class*="kupit"]', '[id*="kupit"]',
            '[class*="to-cart"]', '[class*="tocart"]', '[class*="v-korzinu"]',
            '[class*="basket"]', '[class*="korzin"]', '[class*="zakaz"]', '[class*="order-btn"]',
            '[data-product-id]', '[data-product]', '[data-id][class*="btn"]',
        );
        foreach ($selectors as $selector) {
            $by_class += count($this->dom->find($selector));
            if ($by_class > 20) { break; }
        }

        $by_text = 0;
        $pattern = '~^(купить|в корзину|добавить в корзину|заказать|оформить заказ|положить в корзину|купить в 1 клик|быстрый заказ|add to cart|buy now|buy|order now|preorder)~iu';
        foreach (array('button', 'a', 'input') as $tag) {
            foreach ($this->dom->tags($tag) as $node) {
                if (!$node instanceof DOMElement) { continue; }
                $text = UWP_Dom::node_text($node);
                if ($text === '') { $text = trim($node->getAttribute('value')); }
                if ($text === '') { continue; }
                if (mb_strlen($text) <= 40 && preg_match($pattern, $text)) { $by_text++; }
                if ($by_text > 20) { break 2; }
            }
        }

        $how = array();
        if ($by_class) { $how[] = 'разметка'; }
        if ($by_text) { $how[] = 'текст'; }

        return array(
            'count' => max($by_class, $by_text),
            'text'  => $by_text,
            'how'   => $how ? implode(' + ', $how) : '',
        );
    }

    private function has_quantity_input() {
        $selectors = array(
            'input[name="quantity"]', 'input[name="qty"]', 'input[name*="quant"]',
            '[class*="quantity"]', '[class*="kolichestvo"]', '[class*="counter"] input',
            'input[type="number"]',
        );
        foreach ($selectors as $selector) {
            if ($this->dom->first($selector)) { return true; }
        }
        return false;
    }

    private function has_sku_marker() {
        if ($this->dom->first('[itemprop="sku"]') || $this->dom->first('[itemprop="mpn"]')) { return true; }
        foreach (array('[class*="sku"]', '[class*="artikul"]', '[class*="article-num"]', '[class*="art-num"]', '[class*="kod-tovara"]') as $selector) {
            if ($this->dom->first($selector)) { return true; }
        }
        return (bool) preg_match('~(артикул|код товара|кат\.?\s*номер)\s*[:№]~iu', wp_strip_all_tags($this->dom->raw_html()));
    }

    /**
     * Узлы с разобранной ценой. Количество — главный разделитель
     * карточки (одна-три цены) и витрины раздела (цена у каждого товара).
     */
    private function price_nodes() {
        $found = 0;
        $seen  = array();

        foreach (array('[itemprop="price"]', '[class*="price"]', '[class*="cena"]', '[class*="cost"]', '[data-price]') as $selector) {
            foreach ($this->dom->find($selector) as $node) {
                if (!$node instanceof DOMElement) { continue; }

                // Родительские обертки не считаем дважды.
                $key = spl_object_hash($node);
                if (isset($seen[$key])) { continue; }
                $seen[$key] = true;

                $text = UWP_Dom::node_text($node);
                if (mb_strlen($text) > 200) { continue; }

                $parsed = self::parse_price_string($text);
                if ($parsed['price'] !== '') { $found++; }

                if ($found > 40) { break 2; }
            }
        }

        return array('count' => $found);
    }

    /**
     * Насколько страница похожа на витрину раздела, а не на карточку.
     */
    private function listing_evidence($price_count, $buy_count) {
        $cards = 0;
        foreach (array('[class*="product-card"]', '[class*="catalog-item"]', '[class*="product-item"]', 'li.product', '[class*="tovar-item"]') as $selector) {
            $cards += count($this->dom->find($selector));
            if ($cards > 60) { break; }
        }

        // Витрина: цена повторяется у многих позиций и рядом с каждой — кнопка.
        if ($price_count >= 5 && $buy_count >= 4) {
            return array('is_listing' => true, 'reason' => 'цен ' . $price_count . ', кнопок покупки ' . $buy_count);
        }
        if ($cards >= 6 && $price_count >= 5) {
            return array('is_listing' => true, 'reason' => 'карточек ' . $cards . ', цен ' . $price_count);
        }
        if ($cards >= 10) {
            return array('is_listing' => true, 'reason' => 'карточек ' . $cards);
        }

        return array('is_listing' => false, 'reason' => '');
    }

    /**
     * Отчет о распознавании для диагностики в админке.
     */
    public function detection_report() {
        $ld      = $this->ld_product();
        $signals = $this->product_signals($ld !== null);

        return array(
            'score'     => $signals['score'],
            'threshold' => self::PRODUCT_SCORE_THRESHOLD,
            'signals'   => $signals['matched'],
            'title'     => $this->product_title($ld),
        );
    }

    private function product_title($ld) {
        $custom = trim((string) UWP_Settings::get('sel_title'));
        if ($custom !== '') {
            $text = $this->dom->text($custom);
            if ($text !== '') { return $this->clean_title($text); }
        }

        if ($ld && !empty($ld['name'])) { return $this->clean_title($ld['name']); }

        $itemprop = $this->dom->text('[itemprop="name"]');
        if ($itemprop !== '') { return $this->clean_title($itemprop); }

        $h1 = $this->dom->text('h1');
        if ($h1 !== '') { return $this->clean_title($h1); }

        $og = $this->dom->meta('og:title');
        if ($og !== '') { return $this->clean_title($og); }

        $title = $this->dom->text('title');
        return $this->clean_title($title);
    }

    private function product_sku($ld) {
        if ($ld) {
            foreach (array('sku', 'mpn', 'productID', 'gtin', 'gtin13') as $key) {
                if (!empty($ld[$key]) && is_scalar($ld[$key])) { return UWP_Dom::clean($ld[$key]); }
            }
        }

        $sku = $this->dom->text('[itemprop="sku"]');
        if ($sku !== '') { return mb_substr(UWP_Dom::clean($sku), 0, 100); }

        $html  = $this->dom->raw_html();
        if (preg_match('~(?:артикул|код товара|sku|art\.?|арт\.?)\s*[:\-–]?\s*([A-Za-zА-Яа-я0-9._\-/]{2,40})~iu', wp_strip_all_tags($html), $m)) {
            return UWP_Dom::clean($m[1]);
        }
        return '';
    }

    /**
     * @return array array('price' => string, 'currency' => string)
     */
    private function product_price($ld) {
        $custom = trim((string) UWP_Settings::get('sel_price'));
        if ($custom !== '') {
            $text = $this->dom->text($custom);
            $parsed = self::parse_price_string($text);
            if ($parsed['price'] !== '') { return $parsed; }
        }

        if ($ld) {
            $offers = isset($ld['offers']) ? $ld['offers'] : null;
            $offer  = self::first_offer($offers);
            if ($offer) {
                foreach (array('price', 'lowPrice', 'highPrice') as $key) {
                    if (isset($offer[$key]) && is_scalar($offer[$key]) && (string) $offer[$key] !== '') {
                        return array(
                            'price'    => self::normalize_price_number((string) $offer[$key]),
                            'currency' => isset($offer['priceCurrency']) ? (string) $offer['priceCurrency'] : '',
                        );
                    }
                }
            }
        }

        $meta_price = $this->dom->meta('product:price:amount');
        if ($meta_price !== '') {
            return array(
                'price'    => self::normalize_price_number($meta_price),
                'currency' => $this->dom->meta('product:price:currency'),
            );
        }

        // Микроразметка: значение может быть в content, а не в тексте.
        $node = $this->dom->first('[itemprop="price"]');
        if ($node instanceof DOMElement) {
            $content = trim($node->getAttribute('content'));
            $value   = $content !== '' ? $content : UWP_Dom::node_text($node);
            $parsed  = self::parse_price_string($value);
            if ($parsed['price'] !== '') {
                if ($parsed['currency'] === '') { $parsed['currency'] = $this->dom->attr('[itemprop="priceCurrency"]', 'content'); }
                return $parsed;
            }
        }

        foreach (array('[class*="price"]', '[data-price]', '[class*="cost"]', '[class*="cena"]') as $selector) {
            foreach ($this->dom->find($selector) as $candidate) {
                if ($candidate instanceof DOMElement && $candidate->hasAttribute('data-price')) {
                    $parsed = self::parse_price_string($candidate->getAttribute('data-price'));
                    if ($parsed['price'] !== '') { return $parsed; }
                }
                $parsed = self::parse_price_string(UWP_Dom::node_text($candidate));
                if ($parsed['price'] !== '') { return $parsed; }
            }
        }

        return array('price' => '', 'currency' => '');
    }

    private function product_stock($ld) {
        if ($ld) {
            $offer = self::first_offer(isset($ld['offers']) ? $ld['offers'] : null);
            if ($offer && !empty($offer['availability'])) {
                $availability = is_array($offer['availability']) ? reset($offer['availability']) : $offer['availability'];
                return stripos((string) $availability, 'OutOfStock') === false && stripos((string) $availability, 'SoldOut') === false;
            }
        }

        $text = mb_strtolower(wp_strip_all_tags($this->dom->raw_html()));
        if (preg_match('~(нет в наличии|под заказ только|распродано|out of stock|товар закончился)~u', $text)) { return false; }
        return true;
    }

    private function product_description($ld) {
        $custom = trim((string) UWP_Settings::get('sel_description'));
        if ($custom !== '') {
            $node = $this->dom->first($custom);
            if ($node) { return $this->clean_description(UWP_Dom::node_html($node)); }
        }

        $selectors = array(
            '[itemprop="description"]',
            '#tab-description',
            '.woocommerce-product-details__short-description',
            '[class*="product-description"]',
            '[class*="product__description"]',
            '[class*="description"]',
            '[id*="description"]',
            '[class*="opisanie"]',
            '[id*="opisanie"]',
            '[class*="detail-text"]',
        );
        foreach ($selectors as $selector) {
            $node = $this->dom->first($selector);
            if (!$node) { continue; }
            $html = $this->clean_description(UWP_Dom::node_html($node));
            if (mb_strlen(wp_strip_all_tags($html)) >= 40) { return $html; }
        }

        if ($ld && !empty($ld['description']) && is_scalar($ld['description'])) {
            return $this->clean_description((string) $ld['description']);
        }

        $meta = $this->dom->meta('og:description');
        if ($meta === '') { $meta = $this->dom->meta('description'); }
        return $meta === '' ? '' : esc_html($meta);
    }

    /**
     * @return string[] абсолютные URL картинок
     */
    private function product_images($ld) {
        $images = array();

        $custom = trim((string) UWP_Settings::get('sel_image'));
        if ($custom !== '') {
            foreach ($this->dom->find($custom) as $node) {
                $this->collect_image($node, $images);
            }
        }

        if ($ld && !empty($ld['image'])) {
            foreach ((array) $ld['image'] as $entry) {
                if (is_string($entry)) { $this->add_image($entry, $images); }
                elseif (is_array($entry) && !empty($entry['url'])) { $this->add_image($entry['url'], $images); }
            }
        }

        $og = $this->dom->meta('og:image');
        if ($og !== '') { $this->add_image($og, $images); }

        $selectors = array(
            '[itemprop="image"]',
            '[class*="product-gallery"] img',
            '[class*="product-image"] img',
            '[class*="product__image"] img',
            '[class*="gallery"] img',
            '[class*="slider"] img',
            'figure img',
        );
        foreach ($selectors as $selector) {
            foreach ($this->dom->find($selector) as $node) {
                $this->collect_image($node, $images);
                if (count($images) >= 12) { break 2; }
            }
        }

        return array_values(array_unique($images));
    }

    private function collect_image($node, array &$images) {
        if (!$node instanceof DOMElement) { return; }

        if (strtolower($node->nodeName) !== 'img') {
            foreach (array('content', 'href', 'data-large_image', 'src') as $attribute) {
                if ($node->hasAttribute($attribute)) { $this->add_image($node->getAttribute($attribute), $images); }
            }
            foreach ($node->getElementsByTagName('img') as $img) { $this->collect_image($img, $images); }
            return;
        }

        foreach (array('data-large_image', 'data-zoom-image', 'data-big', 'data-original', 'data-src', 'data-lazy-src', 'src') as $attribute) {
            if ($node->hasAttribute($attribute)) {
                $value = trim($node->getAttribute($attribute));
                if ($value !== '' && strpos($value, 'data:') !== 0) {
                    $this->add_image($value, $images);
                    return;
                }
            }
        }

        if ($node->hasAttribute('srcset')) {
            $first = trim(strtok($node->getAttribute('srcset'), ','));
            $first = trim(strtok($first, ' '));
            if ($first !== '') { $this->add_image($first, $images); }
        }
    }

    private function add_image($src, array &$images) {
        $src = UWP_Url::absolute($src, $this->url);
        if ($src === '' || strpos($src, 'data:') === 0) { return; }
        if (preg_match('~(sprite|placeholder|no-photo|nophoto|noimage|no-image|blank|loader|spinner|logo|icon)~i', $src)) { return; }
        if (!preg_match('~\.(jpe?g|png|webp|gif)(\?|$)~i', $src)) { return; }
        if (in_array($src, $images, true)) { return; }
        $images[] = $src;
    }

    /**
     * Характеристики товара из таблиц, списков определений и типовых блоков.
     *
     * @return array name => value
     */
    private function product_attributes() {
        if (!UWP_Settings::flag('import_attributes')) { return array(); }

        $attributes = array();
        $custom     = trim((string) UWP_Settings::get('sel_attributes'));
        $contexts   = array();

        if ($custom !== '') {
            $contexts = $this->dom->find($custom);
        }
        if (!$contexts) {
            $selectors = array(
                '[class*="characteristic"]', '[class*="harakteristik"]', '[class*="properties"]',
                '[class*="props"]', '[class*="specification"]', '[class*="attribute"]',
                '[class*="params"]', '[class*="parametr"]', '.woocommerce-product-attributes',
                '.shop_attributes', '[id*="specification"]',
            );
            foreach ($selectors as $selector) {
                $found = $this->dom->find($selector);
                if ($found) { $contexts = array_merge($contexts, $found); }
                if (count($contexts) >= 5) { break; }
            }
        }
        if (!$contexts) {
            // Последний вариант — все таблицы страницы, но без фанатизма.
            $contexts = array_slice($this->dom->tags('table'), 0, 3);
        }

        foreach ($contexts as $context) {
            $this->attributes_from_table($context, $attributes);
            $this->attributes_from_definition_list($context, $attributes);
            $this->attributes_from_pairs($context, $attributes);
            if (count($attributes) >= 40) { break; }
        }

        // Микроразметка additionalProperty
        foreach ($this->dom->find('[itemprop="additionalProperty"]') as $node) {
            $name  = UWP_Dom::clean($this->dom->text('[itemprop="name"]', $node));
            $value = UWP_Dom::clean($this->dom->text('[itemprop="value"]', $node));
            if ($name !== '' && $value !== '') { $attributes[$name] = $value; }
        }

        return $this->normalize_attributes($attributes);
    }

    private function attributes_from_table($context, array &$attributes) {
        if (!$context instanceof DOMElement) { return; }
        $rows = strtolower($context->nodeName) === 'tr' ? array($context) : $context->getElementsByTagName('tr');

        foreach ($rows as $row) {
            $cells = array();
            foreach ($row->childNodes as $cell) {
                if (!$cell instanceof DOMElement) { continue; }
                $name = strtolower($cell->nodeName);
                if ($name === 'th' || $name === 'td') { $cells[] = UWP_Dom::node_text($cell); }
            }
            if (count($cells) < 2) { continue; }
            $this->put_attribute($attributes, $cells[0], $cells[1]);
        }
    }

    private function attributes_from_definition_list($context, array &$attributes) {
        if (!$context instanceof DOMElement) { return; }
        foreach ($context->getElementsByTagName('dt') as $dt) {
            $node = $dt->nextSibling;
            while ($node && $node->nodeType !== XML_ELEMENT_NODE) { $node = $node->nextSibling; }
            if ($node && strtolower($node->nodeName) === 'dd') {
                $this->put_attribute($attributes, UWP_Dom::node_text($dt), UWP_Dom::node_text($node));
            }
        }
    }

    /**
     * Разметка вида <li><span>Диаметр</span><span>20 мм</span></li> —
     * самый частый способ верстки характеристик вне таблиц.
     */
    private function attributes_from_pairs($context, array &$attributes) {
        if (!$context instanceof DOMElement) { return; }

        foreach (array('li', 'div') as $tag) {
            foreach ($context->getElementsByTagName($tag) as $item) {
                $children = array();
                foreach ($item->childNodes as $child) {
                    if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), array('span', 'div', 'b', 'strong', 'p'), true)) {
                        $children[] = UWP_Dom::node_text($child);
                    }
                }
                if (count($children) === 2) {
                    $this->put_attribute($attributes, $children[0], $children[1]);
                    continue;
                }

                // Вариант «Диаметр: 20 мм» одной строкой.
                if (!$children) {
                    $text = UWP_Dom::node_text($item);
                    if ($text !== '' && mb_strlen($text) <= 120 && preg_match('~^([^:]{2,40}):\s*(.+)$~u', $text, $m)) {
                        $this->put_attribute($attributes, $m[1], $m[2]);
                    }
                }
                if (count($attributes) >= 40) { return; }
            }
        }
    }

    private function put_attribute(array &$attributes, $name, $value) {
        $name  = trim(preg_replace('~[:：]+\s*$~u', '', UWP_Dom::clean($name)));
        $value = UWP_Dom::clean($value);

        if ($name === '' || $value === '') { return; }
        if (mb_strlen($name) > 60 || mb_strlen($value) > 200) { return; }
        if ($name === $value) { return; }
        if (preg_match('~^(цена|price|стоимость|итого|всего|купить|в корзину)$~iu', $name)) { return; }
        if (isset($attributes[$name])) { return; }

        $attributes[$name] = $value;
    }

    private function normalize_attributes($attributes) {
        $out = array();
        foreach ($attributes as $name => $value) {
            $name  = UWP_Dom::clean($name);
            $value = UWP_Dom::clean($value);
            if ($name === '' || $value === '') { continue; }
            $out[$name] = $value;
            if (count($out) >= 30) { break; }
        }
        return $out;
    }

    private function product_brand($ld) {
        if ($ld && !empty($ld['brand'])) {
            $brand = $ld['brand'];
            if (is_array($brand)) { $brand = isset($brand['name']) ? $brand['name'] : reset($brand); }
            if (is_scalar($brand)) { return UWP_Dom::clean($brand); }
        }
        $brand = $this->dom->text('[itemprop="brand"]');
        return $brand === '' ? '' : mb_substr($brand, 0, 80);
    }

    // ---------------------------------------------------------------------
    // Хлебные крошки и категории
    // ---------------------------------------------------------------------

    /**
     * Путь категорий страницы, от верхнего уровня к нижнему.
     *
     * @param bool   $drop_current убрать последний элемент, если это текущая страница
     *                             (на карточке товара это его название, а не категория)
     * @param string $title        название товара для сверки
     * @return string[]
     */
    public function breadcrumbs($drop_current = false, $title = '') {
        $entries = $this->breadcrumb_entries();

        if ($drop_current && $entries) {
            $last = end($entries);
            if ($this->is_current_page_entry($last, $title)) { array_pop($entries); }
        }

        $names = array();
        foreach ($entries as $entry) { $names[] = $entry['name']; }

        return $this->clean_path($names);
    }

    /**
     * Элемент крошек указывает на саму эту страницу?
     * Признаки: нет ссылки, ссылка на текущий адрес или совпадение с названием товара.
     */
    private function is_current_page_entry($entry, $title) {
        if (empty($entry['url'])) { return true; }

        $current = UWP_Url::normalize($this->url);
        $target  = UWP_Url::normalize($entry['url']);
        if ($current !== '' && $current === $target) { return true; }

        if ($title !== '' && self::same_text($entry['name'], $title)) { return true; }

        return false;
    }

    private static function same_text($a, $b) {
        $normalize = function ($text) {
            return preg_replace('~[^\p{L}\p{N}]+~u', '', mb_strtolower(UWP_Dom::clean($text)));
        };
        $a = $normalize($a);
        $b = $normalize($b);
        return $a !== '' && $a === $b;
    }

    /**
     * Крошки как пары «название → адрес». Адрес пустой, если элемент не ссылка.
     *
     * @return array список array('name' => string, 'url' => string)
     */
    public function breadcrumb_entries() {
        $entries = $this->ld_breadcrumbs();
        if (!$entries) { $entries = $this->microdata_breadcrumbs(); }
        if (!$entries) { $entries = $this->dom_breadcrumbs(); }
        return $entries;
    }

    private function ld_breadcrumbs() {
        foreach ($this->ld_nodes() as $node) {
            if (!self::ld_is_type($node, 'BreadcrumbList')) { continue; }
            if (empty($node['itemListElement']) || !is_array($node['itemListElement'])) { continue; }

            $items = array();
            foreach ($node['itemListElement'] as $index => $element) {
                if (!is_array($element)) { continue; }

                $name = '';
                $url  = '';

                if (!empty($element['name']) && is_scalar($element['name'])) { $name = (string) $element['name']; }

                if (isset($element['item'])) {
                    $item = $element['item'];
                    if (is_string($item)) { $url = $item; }
                    elseif (is_array($item)) {
                        if ($name === '' && !empty($item['name']) && is_scalar($item['name'])) { $name = (string) $item['name']; }
                        foreach (array('@id', 'url') as $key) {
                            if (!empty($item[$key]) && is_scalar($item[$key])) { $url = (string) $item[$key]; break; }
                        }
                    }
                }
                if ($url === '' && !empty($element['url']) && is_scalar($element['url'])) { $url = (string) $element['url']; }

                if ($name === '') { continue; }

                $position = isset($element['position']) ? intval($element['position']) : ($index + 1);
                $items[$position] = array(
                    'name' => UWP_Dom::clean($name),
                    'url'  => $url === '' ? '' : UWP_Url::absolute($url, $this->url),
                );
            }

            if ($items) {
                ksort($items);
                return array_values($items);
            }
        }
        return array();
    }

    private function microdata_breadcrumbs() {
        $entries = array();

        foreach ($this->dom->find('[itemtype*="BreadcrumbList"] [itemprop="itemListElement"]') as $node) {
            $name = UWP_Dom::clean($this->dom->text('[itemprop="name"]', $node));
            if ($name === '') { $name = UWP_Dom::node_text($node); }
            if ($name === '') { continue; }

            $url  = '';
            $link = $this->dom->first('a', $node);
            if ($link instanceof DOMElement) { $url = UWP_Url::absolute($link->getAttribute('href'), $this->url); }

            $entries[] = array('name' => $name, 'url' => $url);
        }

        return $entries;
    }

    private function dom_breadcrumbs() {
        $selectors = array();
        $custom    = trim((string) UWP_Settings::get('sel_breadcrumbs'));
        if ($custom !== '') { $selectors[] = $custom; }

        $selectors = array_merge($selectors, array(
            '.woocommerce-breadcrumb',
            '[class*="breadcrumb"]',
            '[id*="breadcrumb"]',
            '[class*="bread-crumb"]',
            '[class*="crumbs"]',
            '[class*="navigation-path"]',
        ));

        foreach ($selectors as $selector) {
            $node = $this->dom->first($selector);
            if (!$node) { continue; }

            $entries = $this->entries_from_container($node, 0);
            if (count($entries) >= 1) { return $entries; }
        }
        return array();
    }

    /**
     * Разбирает контейнер крошек по прямым потомкам, чтобы вместе со ссылками
     * забрать и последний элемент — текущий раздел, который ссылкой обычно не является.
     */
    private function entries_from_container(DOMNode $container, $depth) {
        if ($depth > 3) { return array(); }

        $elements = array();
        foreach ($container->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) { $elements[] = $child; }
        }

        // Обертка вида <nav><ul>…</ul></nav> — спускаемся внутрь.
        if (count($elements) === 1 && in_array(strtolower($elements[0]->nodeName), array('ul', 'ol', 'div', 'nav', 'span', 'p'), true)) {
            $inner = $this->entries_from_container($elements[0], $depth + 1);
            if ($inner) { return $inner; }
        }

        // Обходим детей в исходном порядке: и ссылки, и текст текущего раздела между ними.
        $entries = array();
        foreach ($container->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text = trim(UWP_Dom::clean($child->nodeValue), " \t\n\r/|>»›→-—");
                if ($text !== '' && mb_strlen($text) <= 120) {
                    $entries[] = array('name' => $text, 'url' => '');
                }
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) { continue; }

            $name = UWP_Dom::node_text($child);
            if ($name === '' || mb_strlen($name) > 120) { continue; }

            $url = '';
            if (strtolower($child->nodeName) === 'a') {
                $url = UWP_Url::absolute($child->getAttribute('href'), $this->url);
            } else {
                $link = $this->dom->first('a', $child);
                if ($link instanceof DOMElement) { $url = UWP_Url::absolute($link->getAttribute('href'), $this->url); }
            }

            $entries[] = array('name' => $name, 'url' => $url);
        }

        return $entries;
    }

    /**
     * Чистит путь: убирает «Главную», пустые и слишком длинные элементы,
     * повторы и хвост, совпадающий с названием товара.
     */
    private function clean_path($path) {
        $stop = array(
            'главная', 'главная страница', 'home', 'домой', 'сайт', 'начало', 'basty', 'басты бет',
            'каталог', 'catalog', 'katalog', 'магазин', 'shop', 'store', 'продукция', 'produkciya',
            'produktsiya', 'товары', 'products', 'все товары', 'каталог товаров', 'каталог продукции',
        );

        $out = array();
        foreach ((array) $path as $item) {
            $item = UWP_Dom::clean($item);
            $item = preg_replace('~\s*\(\s*\d+\s*\)\s*$~u', '', $item);
            $item = preg_replace('~\s+\d+\s*(товаров?|товара|шт\.?|items?)$~iu', '', $item);
            $item = trim($item, " \t\n\r\0\x0B/|»›-");

            if ($item === '' || mb_strlen($item) > 120) { continue; }
            if (in_array(mb_strtolower($item), $stop, true)) { continue; }

            $out[] = $item;
        }

        // Уникальные с сохранением порядка.
        $seen   = array();
        $unique = array();
        foreach ($out as $item) {
            $key = mb_strtolower($item);
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $unique[]   = $item;
        }

        return array_values($unique);
    }

    // ---------------------------------------------------------------------
    // Ссылки
    // ---------------------------------------------------------------------

    /**
     * Все ссылки страницы в абсолютном и нормализованном виде.
     *
     * @return array список array('url' => …, 'text' => …, 'pagination' => bool)
     */
    public function links() {
        $out  = array();
        $seen = array();

        foreach ($this->dom->tags('a') as $node) {
            if (!$node instanceof DOMElement) { continue; }

            $rel = strtolower($node->getAttribute('rel'));
            if (strpos($rel, 'nofollow') !== false && strpos($rel, 'next') === false) { continue; }

            $href = $node->getAttribute('href');
            if ($href === '' || $href === '#') { continue; }

            $url = UWP_Url::normalize(UWP_Url::absolute($href, $this->url));
            if ($url === '' || isset($seen[$url])) { continue; }
            $seen[$url] = true;

            $out[] = array(
                'url'        => $url,
                'text'       => UWP_Dom::node_text($node),
                'pagination' => strpos($rel, 'next') !== false || UWP_Url::is_pagination($url),
            );
        }

        // link rel=next вне <a>
        $next = $this->dom->attr('link[rel="next"]', 'href');
        if ($next !== '') {
            $url = UWP_Url::normalize(UWP_Url::absolute($next, $this->url));
            if ($url !== '' && !isset($seen[$url])) {
                $out[] = array('url' => $url, 'text' => '', 'pagination' => true);
            }
        }

        return $out;
    }

    /**
     * Ссылки, которые по структуре страницы похожи на карточки товаров:
     * повторяющиеся блоки с одинаковым набором классов.
     * Нужны, чтобы товары обходились раньше остального сайта.
     */
    public function likely_product_links() {
        $urls   = array();
        $custom = trim((string) UWP_Settings::get('sel_product_link'));

        $selectors = $custom !== '' ? array($custom) : array(
            '[class*="product"] a[href]',
            '[class*="catalog-item"] a[href]',
            '[class*="item-card"] a[href]',
            '[class*="card"] a[href]',
            '[class*="tovar"] a[href]',
            '[itemtype*="schema.org/Product"] a[href]',
        );

        foreach ($selectors as $selector) {
            foreach ($this->dom->find($selector) as $node) {
                if (!$node instanceof DOMElement) { continue; }
                $href = $node->getAttribute('href');
                if ($href === '') { continue; }
                $url = UWP_Url::normalize(UWP_Url::absolute($href, $this->url));
                if ($url !== '') { $urls[$url] = true; }
            }
            if (count($urls) >= 200) { break; }
        }

        return array_keys($urls);
    }

    public function page_title() {
        $h1 = $this->dom->text('h1');
        if ($h1 !== '') { return $h1; }
        return $this->dom->text('title');
    }

    // ---------------------------------------------------------------------
    // JSON-LD
    // ---------------------------------------------------------------------

    /**
     * Все узлы JSON-LD страницы, развернутые из @graph и массивов.
     */
    public function ld_nodes() {
        if ($this->ld_cache !== null) { return $this->ld_cache; }

        $nodes = array();
        foreach ($this->dom->find('script[type="application/ld+json"]') as $script) {
            $raw = trim($script->textContent);
            if ($raw === '') { continue; }
            $raw = preg_replace('~^\s*<!\[CDATA\[|\]\]>\s*$~', '', $raw);

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                // Частая беда — висячие запятые в разметке CMS.
                $data = json_decode(preg_replace('~,\s*([}\]])~', '$1', $raw), true);
            }
            if (is_array($data)) { self::flatten_ld($data, $nodes, 0); }
            if (count($nodes) > 100) { break; }
        }

        $this->ld_cache = $nodes;
        return $nodes;
    }

    private static function flatten_ld($data, array &$nodes, $depth) {
        if ($depth > 6 || count($nodes) > 100) { return; }

        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $child) {
                if (is_array($child)) { self::flatten_ld($child, $nodes, $depth + 1); }
            }
        }

        if (isset($data['@type'])) {
            $nodes[] = $data;
            // Вложенные сущности вроде mainEntity тоже могут содержать Product.
            foreach (array('mainEntity', 'mainEntityOfPage', 'item', 'itemListElement') as $key) {
                if (isset($data[$key]) && is_array($data[$key])) { self::flatten_ld($data[$key], $nodes, $depth + 1); }
            }
            return;
        }

        foreach ($data as $child) {
            if (is_array($child)) { self::flatten_ld($child, $nodes, $depth + 1); }
        }
    }

    /**
     * @return array|null узел Product, если он есть
     */
    public function ld_product() {
        foreach ($this->ld_nodes() as $node) {
            if (self::ld_is_type($node, 'Product') || self::ld_is_type($node, 'IndividualProduct') || self::ld_is_type($node, 'ProductModel')) {
                return $node;
            }
        }
        return null;
    }

    public static function ld_is_type($node, $type) {
        if (!is_array($node) || !isset($node['@type'])) { return false; }
        foreach ((array) $node['@type'] as $value) {
            if (!is_scalar($value)) { continue; }
            $value = trim(str_replace('http://schema.org/', '', (string) $value), '/ ');
            if (strcasecmp($value, $type) === 0) { return true; }
        }
        return false;
    }

    private static function first_offer($offers) {
        if (!is_array($offers)) { return null; }
        if (isset($offers['price']) || isset($offers['lowPrice']) || isset($offers['availability'])) { return $offers; }
        foreach ($offers as $offer) {
            if (is_array($offer) && (isset($offer['price']) || isset($offer['lowPrice']) || isset($offer['availability']))) { return $offer; }
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // Утилиты
    // ---------------------------------------------------------------------

    /**
     * Универсальный разбор цены: «12 500,50 ₸», «$1,299.00», «1 299 руб.»
     *
     * @return array array('price' => string, 'currency' => string)
     */
    public static function parse_price_string($text) {
        $text = UWP_Dom::clean($text);
        if ($text === '') { return array('price' => '', 'currency' => ''); }

        $currency = '';
        $map = array(
            'KZT' => '~₸|тг\b|тенге~iu',
            'RUB' => '~₽|руб\b|руб\.|рублей~iu',
            'USD' => '~\$|usd|долл~iu',
            'EUR' => '~€|eur|евро~iu',
            'UAH' => '~₴|грн~iu',
            'BYN' => '~бел\.?\s*руб|byn~iu',
            'UZS' => '~сум|uzs~iu',
            'KGS' => '~сом|kgs~iu',
            'PLN' => '~zł|pln~iu',
            'GBP' => '~£|gbp~iu',
        );
        foreach ($map as $code => $pattern) {
            if (preg_match($pattern, $text)) { $currency = $code; break; }
        }

        // Первое число длиной от одного знака с разделителями групп.
        if (!preg_match('~(\d[\d\s\x{00A0}\'`.,]*\d|\d)~u', $text, $m)) {
            return array('price' => '', 'currency' => $currency);
        }

        return array('price' => self::normalize_price_number($m[1]), 'currency' => $currency);
    }

    /**
     * «12 500,50» → «12500.50», «1,299.00» → «1299.00»
     */
    public static function normalize_price_number($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') { return ''; }

        $raw = str_replace(array("\xc2\xa0", ' ', "'", '`'), '', $raw);
        $raw = preg_replace('~[^\d.,\-]~u', '', $raw);
        if ($raw === '') { return ''; }

        $last_comma = strrpos($raw, ',');
        $last_dot   = strrpos($raw, '.');

        if ($last_comma !== false && $last_dot !== false) {
            // Тот разделитель, что правее, — дробный.
            if ($last_comma > $last_dot) { $raw = str_replace('.', '', $raw); $raw = str_replace(',', '.', $raw); }
            else { $raw = str_replace(',', '', $raw); }
        } elseif ($last_comma !== false) {
            $decimals = strlen($raw) - $last_comma - 1;
            $raw = ($decimals === 3) ? str_replace(',', '', $raw) : str_replace(',', '.', $raw);
        } elseif ($last_dot !== false) {
            $decimals = strlen($raw) - $last_dot - 1;
            // «1.299» с тремя знаками — это разделитель тысяч, а не копейки.
            if ($decimals === 3 && substr_count($raw, '.') === 1 && strlen($raw) > 4) { $raw = str_replace('.', '', $raw); }
        }

        $raw = preg_replace('~[^\d.]~', '', $raw);
        if ($raw === '' || !is_numeric($raw)) { return ''; }
        if ((float) $raw <= 0) { return ''; }

        return rtrim(rtrim(number_format((float) $raw, 2, '.', ''), '0'), '.');
    }

    private function clean_title($title) {
        $title = UWP_Dom::clean($title);
        if ($title === '') { return ''; }

        // Хвосты из <title>: «Товар — Купить в Астане | Название сайта»
        $title = preg_replace('~\s*[|｜]\s*[^|]{0,60}$~u', '', $title);
        $title = preg_replace('~\s+(купить|заказать|цена|в наличии)\b.*$~iu', '', $title);
        $title = preg_replace('~\s*[-–—]\s*(купить|заказать|цена|интернет-магазин).*$~iu', '', $title);

        return trim(UWP_Dom::clean($title), " \t\n\r-–—|:");
    }

    private function clean_description($html) {
        if ($html === '') { return ''; }

        $allowed = array(
            'p' => array(), 'br' => array(), 'strong' => array(), 'b' => array(),
            'em' => array(), 'i' => array(), 'u' => array(), 'ul' => array(), 'ol' => array(),
            'li' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(),
            'table' => array(), 'thead' => array(), 'tbody' => array(), 'tr' => array(),
            'th' => array(), 'td' => array(), 'span' => array(), 'div' => array(),
        );
        $html = wp_kses($html, $allowed);

        // Вычищаем строки с контактами и призывами — они везде разные, но всегда лишние.
        $html = preg_replace('~<(div|span)[^>]*>\s*</\1>~i', '', $html);
        $text = wp_strip_all_tags($html);
        if (mb_strlen($text) < 15) { return ''; }
        if (mb_strlen($text) > 20000) { $html = mb_substr($html, 0, 20000); }

        return trim($html);
    }
}
