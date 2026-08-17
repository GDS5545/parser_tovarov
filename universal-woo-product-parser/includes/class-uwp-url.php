<?php
/**
 * Работа с URL: нормализация, ограничение обхода одним доменом,
 * отсев мусорных и бесконечных ссылок (фильтры, сортировки, utm).
 *
 * Именно бесконтрольные фильтры каталога («?sort=price&color=red&...»)
 * раньше и создавали бесконечную очередь и видимость зависания.
 */

if (!defined('ABSPATH')) { exit; }

class UWP_Url {

    /** Параметры, которые ничего не меняют в содержимом страницы. */
    private static $drop_params = array(
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_referrer',
        'gclid', 'yclid', 'fbclid', 'ymclid', '_openstat', 'from', 'ref', 'roistat', 'sid',
        'PHPSESSID', 'phpsessid', 'sessionid', 'clear_cache', 'back_url_admin',
    );

    /** Параметры, которые размножают одну и ту же выдачу — обход по ним не идет. */
    private static $trap_params = array(
        'sort', 'order', 'orderby', 'direction', 'view', 'display', 'mode', 'limit',
        'per_page', 'perpage', 'show', 'compare', 'wishlist', 'add-to-cart', 'add_to_cart',
        'filter', 'filters', 'price', 'min_price', 'max_price', 'rating_filter', 'brand',
        'color', 'size', 'attribute', 'set_filter', 'del_filter', 'arrfilter', 'replytocom',
    );

    /** Параметры постраничной навигации — они нужны. */
    private static $page_params = array('page', 'p', 'pg', 'start', 'offset', 'paged', 'pagen_1', 'pagen_2', 'page_id');

    /** Расширения файлов, которые не являются страницами каталога. */
    const FILE_EXTENSIONS = 'jpg|jpeg|png|gif|webp|svg|bmp|ico|pdf|doc|docx|xls|xlsx|ppt|pptx|zip|rar|7z|gz|tar|mp3|mp4|avi|mov|wmv|css|js|json|xml|rss|txt|exe|dmg|apk';

    /**
     * Полный URL из относительного.
     */
    public static function absolute($href, $base) {
        $href = trim(html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '') { return ''; }

        if (preg_match('~^(javascript|mailto|tel|callto|sms|whatsapp|viber|data|ftp|skype|#)~i', $href)) { return ''; }

        // Протокол-относительный //host/path
        if (strpos($href, '//') === 0) {
            $scheme = parse_url($base, PHP_URL_SCHEME);
            return ($scheme ? $scheme : 'https') . ':' . $href;
        }
        if (preg_match('~^https?://~i', $href)) { return $href; }

        $parts  = parse_url($base);
        if (empty($parts['host'])) { return ''; }
        $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
        $host   = $parts['host'];
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        $root   = $scheme . '://' . $host . $port;

        if (strpos($href, '?') === 0) {
            $path = isset($parts['path']) ? $parts['path'] : '/';
            return $root . $path . $href;
        }
        if (strpos($href, '/') === 0) { return $root . $href; }

        $dir = isset($parts['path']) ? preg_replace('~/[^/]*$~', '/', $parts['path']) : '/';
        if ($dir === '' || $dir[0] !== '/') { $dir = '/' . $dir; }

        return $root . self::resolve_dots($dir . $href);
    }

    private static function resolve_dots($path) {
        $segments = explode('/', $path);
        $out      = array();
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '') { continue; }
            if ($segment === '..') { array_pop($out); continue; }
            $out[] = $segment;
        }
        $result = '/' . implode('/', $out);
        if (substr($path, -1) === '/' && substr($result, -1) !== '/') { $result .= '/'; }
        return $result;
    }

    /**
     * Каноничный вид URL: без якоря, без мусорных параметров, со стабильным порядком.
     * Одна страница = один URL = одна запись в очереди.
     */
    public static function normalize($url) {
        $url = trim((string) $url);
        if ($url === '') { return ''; }

        $url   = strtok($url, '#');
        $parts = parse_url($url);
        if (empty($parts['host']) || empty($parts['scheme'])) { return ''; }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, array('http', 'https'), true)) { return ''; }

        $host = strtolower($parts['host']);
        $port = '';
        if (!empty($parts['port']) && !in_array(intval($parts['port']), array(80, 443), true)) {
            $port = ':' . intval($parts['port']);
        }

        $path = isset($parts['path']) ? $parts['path'] : '/';
        $path = preg_replace('~/{2,}~', '/', $path);
        if ($path === '') { $path = '/'; }

        $query = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $params);
            $keep = array();
            foreach ($params as $key => $value) {
                $lower = strtolower($key);
                if (in_array($lower, array_map('strtolower', self::$drop_params), true)) { continue; }
                if (is_array($value)) { continue; }
                $keep[$key] = $value;
            }
            ksort($keep);
            if ($keep) { $query = '?' . http_build_query($keep); }
        }

        return $scheme . '://' . $host . $port . $path . $query;
    }

    public static function host($url) {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return preg_replace('~^www\.~', '', $host);
    }

    /**
     * Тот же домен, что и источник. www и без www считаем одним сайтом.
     */
    public static function same_site($url, $source_url) {
        $a = self::host($url);
        $b = self::host($source_url);
        return $a !== '' && $a === $b;
    }

    public static function path($url) {
        $path = (string) parse_url($url, PHP_URL_PATH);
        return $path === '' ? '/' : $path;
    }

    /**
     * Ссылка ведет на файл, а не на страницу.
     */
    public static function is_file($url) {
        $path = self::path($url);
        return (bool) preg_match('~\.(' . self::FILE_EXTENSIONS . ')$~i', $path);
    }

    /**
     * Технические разделы любого движка, куда парсеру заходить незачем.
     */
    public static function is_service($url) {
        $path  = strtolower(self::path($url));
        $query = strtolower((string) parse_url($url, PHP_URL_QUERY));

        // Только то, что действительно никогда не бывает разделом каталога.
        // Раньше список был шире и заодно отсекал живые разделы: «media», «order»,
        // «tag» и подобные слова встречаются в адресах товарных категорий.
        $service = '~(^|/)(wp-admin|wp-login|wp-json|wp-content|wp-includes|administrator|bitrix/admin|cart|korzina|basket|checkout|oformlenie-zakaza|compare|sravnenie|wishlist|izbrannoe|login|signin|signup|register|registraciya|logout|logout\.php|account|cabinet|lichnyj-kabinet|myaccount|profile|search|poisk|feed|rss|comment-page-\d+|print|ajax)(/|$)~';
        if (preg_match($service, $path)) { return true; }

        if ($query !== '') {
            parse_str($query, $params);
            foreach (array_keys($params) as $key) {
                if (in_array(strtolower($key), array_map('strtolower', self::$trap_params), true)) { return true; }
            }
        }

        return false;
    }

    /**
     * Ссылка на постраничную навигацию — ее стоит обходить в первую очередь,
     * иначе половина каталога останется недоступной.
     */
    public static function is_pagination($url) {
        $path  = strtolower(self::path($url));
        if (preg_match('~/(page|stranica|p)/\d+/?$~', $path)) { return true; }

        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($query === '') { return false; }
        parse_str($query, $params);
        foreach (array_keys($params) as $key) {
            if (in_array(strtolower($key), self::$page_params, true)) { return true; }
        }
        return false;
    }

    /**
     * Сегменты пути без служебных хвостов — основа для запасного дерева категорий.
     */
    public static function segments($url) {
        $path = trim(self::path($url), '/');
        if ($path === '') { return array(); }
        $segments = array();
        foreach (explode('/', $path) as $segment) {
            $segment = rawurldecode($segment);
            if ($segment === '' || preg_match('~^(index|default)\.(php|html?|aspx?)$~i', $segment)) { continue; }
            $segments[] = $segment;
        }
        return $segments;
    }

    /**
     * Проверка по спискам «только эти пути» / «кроме этих путей» из настроек.
     */
    public static function passes_path_filters($url) {
        $path    = rawurldecode(self::path($url)) . (parse_url($url, PHP_URL_QUERY) ? '?' . parse_url($url, PHP_URL_QUERY) : '');
        $path_lc = mb_strtolower($path);

        foreach (UWP_Settings::lines('exclude_paths') as $needle) {
            if (self::rule_matches($needle, $path_lc)) { return false; }
        }

        $include = UWP_Settings::lines('include_paths');
        if (!$include) { return true; }

        foreach ($include as $needle) {
            if (self::rule_matches($needle, $path_lc)) { return true; }
        }
        return false;
    }

    private static function rule_matches($rule, $path_lc) {
        $rule = trim($rule);
        if ($rule === '') { return false; }

        // Полный URL в правиле — берем только путь.
        if (preg_match('~^https?://~i', $rule)) {
            $rule = (string) parse_url($rule, PHP_URL_PATH);
        }
        $rule = mb_strtolower(trim($rule));
        if ($rule === '' || $rule === '/') { return false; }

        return mb_strpos($path_lc, $rule) !== false;
    }

    /**
     * Красивое имя раздела из slug: «poroshki-metalla» → «Poroshki metalla».
     * Используется только как последний запасной вариант для категорий.
     */
    public static function prettify_segment($segment) {
        $segment = preg_replace('~\.(html?|php|aspx?)$~i', '', (string) $segment);
        $segment = str_replace(array('-', '_', '+'), ' ', $segment);
        $segment = preg_replace('~\s+~u', ' ', trim($segment));
        if ($segment === '') { return ''; }
        return function_exists('mb_convert_case') ? mb_convert_case($segment, MB_CASE_TITLE, 'UTF-8') : ucfirst($segment);
    }
}
