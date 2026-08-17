<?php
/**
 * HTTP-загрузка страниц.
 *
 * Отвечает за то, чтобы один запрос никогда не подвесил тик:
 * жесткий таймаут, лимит размера ответа, отказ от не-HTML контента
 * и приведение кодировки к UTF-8 (windows-1251 в рунете встречается постоянно).
 */

if (!defined('ABSPATH')) { exit; }

class UWP_Http {

    /** Больше 4 МБ одной HTML-страницы — это уже не каталог, а проблема. */
    const MAX_BYTES = 4194304;

    /**
     * @return array|WP_Error array('body' => string, 'url' => финальный URL, 'code' => int)
     */
    public static function get($url, $timeout = null) {
        $url = trim((string) $url);
        if ($url === '' || !preg_match('~^https?://~i', $url)) {
            return new WP_Error('uwp_bad_url', 'Некорректный URL: ' . $url);
        }

        $timeout = $timeout ? intval($timeout) : UWP_Settings::int('request_timeout');
        $args    = array(
            'timeout'             => $timeout,
            'redirection'         => 5,
            'sslverify'           => false,
            'limit_response_size' => self::MAX_BYTES,
            'user-agent'          => (string) UWP_Settings::get('user_agent'),
            'headers'             => array(
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control'   => 'no-cache',
            ),
        );

        $proxy = trim((string) UWP_Settings::get('proxy'));
        if ($proxy !== '') { $args['proxy'] = $proxy; }

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return new WP_Error('uwp_http', 'Сеть: ' . $response->get_error_message());
        }

        $code = intval(wp_remote_retrieve_response_code($response));
        if ($code < 200 || $code >= 400) {
            return new WP_Error('uwp_http_status', 'HTTP ' . $code);
        }

        $type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if ($type !== '' && !preg_match('~(text/html|application/xhtml|text/xml|application/xml|text/plain)~', $type)) {
            return new WP_Error('uwp_not_html', 'Не HTML-страница (' . $type . ')');
        }

        $body = (string) wp_remote_retrieve_body($response);
        if (strlen($body) < 32) {
            return new WP_Error('uwp_empty', 'Пустой ответ');
        }

        // Итоговый URL после редиректов — важен для склейки относительных ссылок.
        $final = $url;
        if (isset($response['http_response']) && is_object($response['http_response']) && method_exists($response['http_response'], 'get_response_object')) {
            $object = $response['http_response']->get_response_object();
            if ($object && !empty($object->url)) { $final = (string) $object->url; }
        }

        return array(
            'body' => self::to_utf8($body, $type),
            'url'  => $final ? $final : $url,
            'code' => $code,
        );
    }

    /**
     * Приводит тело ответа к UTF-8, ориентируясь на заголовок и мета-тег.
     */
    public static function to_utf8($body, $content_type = '') {
        $charset = '';

        if ($content_type && preg_match('~charset\s*=\s*["\']?([\w\-]+)~i', $content_type, $m)) {
            $charset = strtolower($m[1]);
        }
        if ($charset === '' && preg_match('~<meta[^>]+charset\s*=\s*["\']?([\w\-]+)~i', substr($body, 0, 4096), $m)) {
            $charset = strtolower($m[1]);
        }

        if ($charset === '' || in_array($charset, array('utf-8', 'utf8'), true)) {
            // Даже при заявленном UTF-8 попадаются битые байты — вычищаем их.
            if (!self::is_valid_utf8($body)) {
                $body = self::convert($body, 'windows-1251');
            }
            return $body;
        }

        return self::convert($body, $charset);
    }

    private static function is_valid_utf8($string) {
        return (bool) preg_match('//u', $string);
    }

    private static function convert($body, $from) {
        $from = strtoupper($from);
        if (in_array($from, array('CP1251', 'WIN-1251', 'WINDOWS1251'), true)) { $from = 'WINDOWS-1251'; }

        if (function_exists('iconv')) {
            $converted = @iconv($from, 'UTF-8//IGNORE', $body);
            if ($converted !== false && $converted !== '') { return $converted; }
        }
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($body, 'UTF-8', $from);
            if ($converted !== '' && $converted !== false) { return $converted; }
        }
        return $body;
    }

    /**
     * Пауза между запросами к источнику, чтобы не устроить ему нагрузку.
     */
    public static function polite_delay() {
        $ms = UWP_Settings::int('request_delay_ms');
        if ($ms > 0) { usleep(min(10000, $ms) * 1000); }
    }
}
