<?php
/**
 * Обход сайта-источника.
 *
 * Обход строго ограничен доменом из настроек: любая ссылка на другой хост
 * отбрасывается на входе, а перед импортом источник проверяется повторно.
 */

if (!defined('ABSPATH')) { exit; }

class UWP_Crawler {

    /**
     * Домен-источник из настроек в виде абсолютного URL.
     */
    public static function source_url() {
        $raw = trim((string) UWP_Settings::get('source_url'));
        if ($raw === '') { return ''; }
        if (!preg_match('~^https?://~i', $raw)) { $raw = 'https://' . ltrim($raw, '/'); }

        $normalized = UWP_Url::normalize($raw);
        return $normalized ? $normalized : '';
    }

    public static function source_host() {
        return UWP_Url::host(self::source_url());
    }

    /**
     * Начальное наполнение очереди: стартовая страница + карта сайта.
     *
     * @return array array('pages' => int, 'notes' => string[])
     */
    public static function seed() {
        $source = self::source_url();
        $notes  = array();

        if ($source === '') {
            return array('pages' => 0, 'notes' => array('Не указан домен источника.'));
        }

        $added = 0;
        if (UWP_DB::push_or_reset(UWP_DB::KIND_PAGE, $source, 0, array(), 0)) { $added++; }
        $notes[] = 'Стартовая страница: ' . $source;

        if (UWP_Settings::flag('use_sitemap')) {
            $sitemap = self::seed_from_sitemaps($source);
            $added  += $sitemap['added'];
            $notes   = array_merge($notes, $sitemap['notes']);
        }

        return array('pages' => $added, 'notes' => $notes);
    }

    /**
     * Карта сайта — самый дешевый способ найти все карточки товаров,
     * не обходя баннеры, фильтры и служебные страницы.
     */
    private static function seed_from_sitemaps($source) {
        $added = 0;
        $notes = array();
        $root  = self::root_url($source);

        $candidates = array();
        $robots = UWP_Http::get($root . '/robots.txt', 10);
        if (!is_wp_error($robots) && preg_match_all('~^\s*sitemap\s*:\s*(\S+)~im', $robots['body'], $m)) {
            foreach ($m[1] as $entry) { $candidates[] = trim($entry); }
        }
        $candidates[] = $root . '/sitemap.xml';
        $candidates[] = $root . '/sitemap_index.xml';
        $candidates[] = $root . '/sitemap-index.xml';
        $candidates[] = $root . '/wp-sitemap.xml';

        $candidates = array_values(array_unique($candidates));
        $seen_maps  = array();
        $queue      = $candidates;
        $max_maps   = 12;
        $max_urls   = UWP_Settings::int('max_pages');
        if ($max_urls <= 0) { $max_urls = 20000; }

        while ($queue && count($seen_maps) < $max_maps && $added < $max_urls) {
            $map_url = array_shift($queue);
            $map_url = UWP_Url::normalize($map_url);
            if ($map_url === '' || isset($seen_maps[$map_url])) { continue; }
            if (!UWP_Url::same_site($map_url, $source)) { continue; }
            $seen_maps[$map_url] = true;

            $response = UWP_Http::get($map_url, 15);
            if (is_wp_error($response)) { continue; }

            $parsed = self::parse_sitemap($response['body']);
            if ($parsed['sitemaps']) {
                foreach ($parsed['sitemaps'] as $child) { $queue[] = $child; }
            }

            foreach ($parsed['urls'] as $url) {
                if ($added >= $max_urls) { break; }
                if (self::enqueue_page($url, 1, array(), 3)) { $added++; }
            }

            if ($parsed['urls'] || $parsed['sitemaps']) {
                $notes[] = 'Карта сайта ' . $map_url . ': ссылок ' . count($parsed['urls']) . ', вложенных карт ' . count($parsed['sitemaps']);
            }
        }

        if ($added === 0) { $notes[] = 'Карта сайта не найдена или пуста — идем обычным обходом по ссылкам.'; }

        return array('added' => $added, 'notes' => $notes);
    }

    private static function parse_sitemap($xml) {
        $out = array('urls' => array(), 'sitemaps' => array());

        if (stripos($xml, '<sitemapindex') !== false) {
            if (preg_match_all('~<sitemap>.*?<loc>\s*(.*?)\s*</loc>.*?</sitemap>~is', $xml, $m)) {
                foreach ($m[1] as $loc) { $out['sitemaps'][] = html_entity_decode(trim($loc)); }
            }
            return $out;
        }

        if (preg_match_all('~<loc>\s*(.*?)\s*</loc>~is', $xml, $m)) {
            foreach ($m[1] as $loc) {
                $loc = html_entity_decode(trim($loc));
                if ($loc === '') { continue; }
                if (preg_match('~\.xml(\.gz)?$~i', $loc)) { $out['sitemaps'][] = $loc; }
                else { $out['urls'][] = $loc; }
            }
        }

        return $out;
    }

    private static function root_url($url) {
        $parts  = parse_url($url);
        $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
        $host   = isset($parts['host']) ? $parts['host'] : '';
        return $host === '' ? '' : $scheme . '://' . $host;
    }

    /**
     * Единая точка постановки страницы в очередь со всеми проверками.
     */
    public static function enqueue_page($url, $depth, $path = array(), $priority = 5) {
        $source = self::source_url();
        if ($source === '') { return false; }

        $url = UWP_Url::normalize($url);
        if ($url === '') { return false; }

        // Строго один домен — тот, что указан в настройках.
        if (!UWP_Url::same_site($url, $source)) { return false; }

        if (UWP_Url::is_file($url) || UWP_Url::is_service($url)) { return false; }
        if (!UWP_Url::passes_path_filters($url)) { return false; }

        $max_depth = UWP_Settings::int('max_depth');
        if ($max_depth > 0 && $depth > $max_depth) { return false; }

        $max_pages = UWP_Settings::int('max_pages');
        if ($max_pages > 0 && self::queue_size() >= $max_pages) { return false; }

        $pushed = UWP_DB::push(UWP_DB::KIND_PAGE, $url, $depth, $path, $priority);
        if ($pushed) { self::$queue_size++; }

        return $pushed;
    }

    /** @var int|null счетчик очереди, чтобы не делать COUNT(*) на каждую ссылку */
    private static $queue_size = null;

    private static function queue_size() {
        if (self::$queue_size === null) { self::$queue_size = UWP_DB::visited_pages(); }
        return self::$queue_size;
    }

    /**
     * Обработка одной страницы очереди.
     *
     * @param object $row строка очереди
     * @return array результат для журнала
     */
    public static function process_page($row) {
        $response = UWP_Http::get($row->url);

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            $code    = $response->get_error_code();

            // 404 и «не HTML» повторять бессмысленно.
            if (in_array($code, array('uwp_not_html', 'uwp_bad_url'), true) || strpos($message, 'HTTP 404') === 0 || strpos($message, 'HTTP 410') === 0) {
                UWP_DB::finish($row->id, UWP_DB::STATUS_SKIPPED, $message);
                return array('status' => 'skipped', 'message' => $row->url . ' → ' . $message);
            }

            UWP_DB::retry_or_fail($row, $message);
            return array('status' => 'error', 'message' => $row->url . ' → ' . $message);
        }

        $html      = $response['body'];
        $final_url = UWP_Url::normalize($response['url']);
        if ($final_url === '' || !UWP_Url::same_site($final_url, self::source_url())) { $final_url = $row->url; }

        $extractor = new UWP_Extractor($html, $final_url);
        $depth     = intval($row->depth);
        $path      = self::decode_path($row->path_json);

        // 1. Страница оказалась карточкой товара — переводим ее в товары
        //    вместе с уже разобранными данными, повторная загрузка не нужна.
        $product = $extractor->product_data();
        if ($product !== null) {
            // На карточке последний элемент крошек — сам товар, категорией он не является.
            $breadcrumbs = $extractor->breadcrumbs(true, $product['title']);
            $category    = self::resolve_category_path($breadcrumbs, $path, $final_url, $product['title']);

            $product['category_path'] = $category;
            $product['url']           = $final_url;

            UWP_DB::convert_to_product($row->id, $product, $category);
            return array(
                'status'  => 'product',
                'message' => 'Товар найден: ' . $product['title'] . ($category ? ' [' . implode(' / ', $category) . ']' : ' [категория не определена]'),
            );
        }

        // 2. Обычная страница каталога — собираем ссылки.
        $listing_path = self::resolve_category_path($extractor->breadcrumbs(), $path, $final_url, '');
        $added        = self::enqueue_links($extractor, $depth, $listing_path);

        UWP_DB::finish($row->id, UWP_DB::STATUS_DONE, 'ссылок +' . $added);

        return array(
            'status'  => 'page',
            'message' => 'Страница ' . $final_url . ': новых ссылок ' . $added,
        );
    }

    /**
     * Складывает найденные ссылки в очередь: сначала карточки и пагинация.
     */
    private static function enqueue_links(UWP_Extractor $extractor, $depth, $path) {
        $added    = 0;
        $products = array_flip($extractor->likely_product_links());

        foreach ($extractor->links() as $link) {
            $url = $link['url'];

            if (!empty($link['pagination'])) {
                // Пагинация остается на той же глубине и в той же категории.
                if (self::enqueue_page($url, $depth, $path, 2)) { $added++; }
                continue;
            }

            $priority = isset($products[$url]) ? 3 : 6;
            if (self::enqueue_page($url, $depth + 1, $path, $priority)) { $added++; }

            if ($added >= 500) { break; }
        }

        return $added;
    }

    /**
     * Итоговый путь категорий страницы.
     *
     * Приоритет: хлебные крошки → путь, унаследованный от страницы-родителя →
     * сегменты URL. Название самого товара из пути убирается.
     *
     * @return string[]
     */
    public static function resolve_category_path($breadcrumbs, $inherited, $url, $product_title = '') {
        $path = array();

        if ($breadcrumbs) { $path = $breadcrumbs; }
        elseif ($inherited) { $path = $inherited; }
        else { $path = self::path_from_url($url); }

        // Хвост крошек часто совпадает с названием товара — он не категория.
        if ($product_title !== '' && $path) {
            $last = end($path);
            if (self::same_text($last, $product_title)) { array_pop($path); }
        }

        $out = array();
        foreach ($path as $item) {
            $item = UWP_Dom::clean($item);
            if ($item === '' || mb_strlen($item) > 120) { continue; }
            $out[] = $item;
            if (count($out) >= 6) { break; }
        }

        return $out;
    }

    /**
     * Запасной вариант дерева категорий — по сегментам адреса.
     * Последний сегмент карточки товара отбрасывается.
     */
    private static function path_from_url($url) {
        $segments = UWP_Url::segments($url);
        if (!$segments) { return array(); }

        array_pop($segments);

        $skip = array('catalog', 'katalog', 'shop', 'store', 'product', 'products', 'tovar', 'tovary', 'produkciya', 'produktsiya', 'category', 'kategorii', 'ru', 'kz', 'en');
        $out  = array();
        foreach ($segments as $segment) {
            if (in_array(mb_strtolower($segment), $skip, true)) { continue; }
            $pretty = UWP_Url::prettify_segment($segment);
            if ($pretty !== '') { $out[] = $pretty; }
        }

        return array_slice($out, 0, 5);
    }

    private static function same_text($a, $b) {
        $normalize = function ($text) {
            return preg_replace('~[^\p{L}\p{N}]+~u', '', mb_strtolower(UWP_Dom::clean($text)));
        };
        $a = $normalize($a);
        $b = $normalize($b);
        return $a !== '' && $a === $b;
    }

    public static function decode_path($json) {
        $path = json_decode((string) $json, true);
        return is_array($path) ? array_values($path) : array();
    }
}
