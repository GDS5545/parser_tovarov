<?php
/**
 * Единая очередь задач и журнал.
 *
 * В отличие от прошлой версии таблица одна: страница, которая после загрузки
 * оказалась карточкой товара, не ставится в отдельную очередь и не скачивается
 * второй раз — она просто меняет kind на product вместе с уже разобранными данными.
 */

if (!defined('ABSPATH')) { exit; }

class UWP_DB {

    const DB_VERSION_OPTION = 'uwp_parser_db_version';
    const DB_VERSION        = '2.1.0';

    const KIND_PAGE    = 'page';
    const KIND_PRODUCT = 'product';

    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_DONE       = 'done';
    const STATUS_SKIPPED    = 'skipped';
    const STATUS_FAILED     = 'failed';

    public static function queue_table() {
        global $wpdb;
        return $wpdb->prefix . 'uwp_queue';
    }

    public static function log_table() {
        global $wpdb;
        return $wpdb->prefix . 'uwp_log';
    }

    public static function maybe_install() {
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) { return; }
        self::install();
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $queue   = self::queue_table();
        $log     = self::log_table();

        $sql_queue = "CREATE TABLE {$queue} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind VARCHAR(12) NOT NULL DEFAULT 'page',
            url TEXT NOT NULL,
            url_hash CHAR(32) NOT NULL,
            host VARCHAR(190) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            priority TINYINT NOT NULL DEFAULT 5,
            depth SMALLINT NOT NULL DEFAULT 0,
            path_json LONGTEXT NULL,
            data_json LONGTEXT NULL,
            attempts SMALLINT NOT NULL DEFAULT 0,
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY url_hash (url_hash),
            KEY status_kind (status, kind, priority, id),
            KEY kind_status (kind, status),
            KEY host (host)
        ) {$charset};";

        $sql_log = "CREATE TABLE {$log} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            level VARCHAR(10) NOT NULL DEFAULT 'info',
            message TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql_queue);
        dbDelta($sql_log);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    /**
     * Добавляет URL в очередь. Возвращает true только если запись реально новая.
     */
    public static function push($kind, $url, $depth = 0, $path = array(), $priority = 5, $data = null) {
        global $wpdb;

        $url = trim((string) $url);
        if ($url === '') { return false; }

        $hash = md5($url);
        $now  = current_time('mysql');

        $row = array(
            'kind'       => $kind,
            'url'        => $url,
            'url_hash'   => $hash,
            'host'       => (string) parse_url($url, PHP_URL_HOST),
            'status'     => self::STATUS_PENDING,
            'priority'   => max(0, min(9, intval($priority))),
            'depth'      => max(0, intval($depth)),
            'path_json'  => wp_json_encode(array_values((array) $path), JSON_UNESCAPED_UNICODE),
            'data_json'  => $data === null ? null : wp_json_encode($data, JSON_UNESCAPED_UNICODE),
            'attempts'   => 0,
            'product_id' => 0,
            'note'       => null,
            'created_at' => $now,
            'updated_at' => $now,
        );

        // suppress_errors: дубль по уникальному индексу — штатная ситуация, а не ошибка.
        $prev = $wpdb->suppress_errors(true);
        $ok   = $wpdb->insert(self::queue_table(), $row);
        $wpdb->suppress_errors($prev);

        return (bool) $ok;
    }

    /**
     * Ставит URL в очередь заново, даже если он там уже был.
     */
    public static function push_or_reset($kind, $url, $depth = 0, $path = array(), $priority = 5, $data = null) {
        global $wpdb;

        if (self::push($kind, $url, $depth, $path, $priority, $data)) { return true; }

        $updated = $wpdb->update(
            self::queue_table(),
            array(
                'kind'       => $kind,
                'status'     => self::STATUS_PENDING,
                'priority'   => max(0, min(9, intval($priority))),
                'depth'      => max(0, intval($depth)),
                'path_json'  => wp_json_encode(array_values((array) $path), JSON_UNESCAPED_UNICODE),
                'data_json'  => $data === null ? null : wp_json_encode($data, JSON_UNESCAPED_UNICODE),
                'attempts'   => 0,
                'note'       => null,
                'updated_at' => current_time('mysql'),
            ),
            array('url_hash' => md5(trim((string) $url)))
        );

        return $updated !== false;
    }

    public static function exists($url) {
        global $wpdb;
        $table = self::queue_table();
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE url_hash = %s LIMIT 1", md5(trim((string) $url))));
    }

    /**
     * Следующая задача. Товары идут раньше страниц: сначала доводим до конца то,
     * что уже разобрано, и только потом расширяем обход.
     */
    public static function claim_next() {
        global $wpdb;
        $table = self::queue_table();

        $row = $wpdb->get_row(
            "SELECT * FROM {$table}
             WHERE status = '" . self::STATUS_PENDING . "'
             ORDER BY (kind = '" . self::KIND_PRODUCT . "') DESC, priority ASC, id ASC
             LIMIT 1"
        );
        if (!$row) { return null; }

        // Атомарный захват: если параллельный тик успел раньше, идем дальше.
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, attempts = attempts + 1, updated_at = %s
             WHERE id = %d AND status = %s",
            self::STATUS_PROCESSING,
            current_time('mysql'),
            $row->id,
            self::STATUS_PENDING
        ));
        if (!$claimed) { return null; }

        $row->status   = self::STATUS_PROCESSING;
        $row->attempts = intval($row->attempts) + 1;
        return $row;
    }

    public static function finish($id, $status, $note = '', $product_id = 0, $data = null) {
        global $wpdb;
        $fields = array(
            'status'     => $status,
            'note'       => $note === '' ? null : mb_substr((string) $note, 0, 500),
            'updated_at' => current_time('mysql'),
        );
        if ($product_id) { $fields['product_id'] = intval($product_id); }
        if ($data !== null) { $fields['data_json'] = wp_json_encode($data, JSON_UNESCAPED_UNICODE); }

        $wpdb->update(self::queue_table(), $fields, array('id' => intval($id)));
    }

    /**
     * Возвращает задачу в очередь для новой попытки или помечает как проваленную.
     */
    public static function retry_or_fail($row, $note) {
        $max = UWP_Settings::int('max_attempts');
        if (intval($row->attempts) >= $max) {
            self::finish($row->id, self::STATUS_FAILED, $note);
            return false;
        }
        self::finish($row->id, self::STATUS_PENDING, $note);
        return true;
    }

    /**
     * Меняет тип задачи (страница оказалась карточкой товара) и сохраняет разбор.
     */
    public static function convert_to_product($id, $data, $path) {
        global $wpdb;
        $wpdb->update(
            self::queue_table(),
            array(
                'kind'       => self::KIND_PRODUCT,
                'status'     => self::STATUS_PENDING,
                'priority'   => 1,
                'path_json'  => wp_json_encode(array_values((array) $path), JSON_UNESCAPED_UNICODE),
                'data_json'  => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
                'attempts'   => 0,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => intval($id))
        );
    }

    public static function counts() {
        global $wpdb;
        $table = self::queue_table();
        $rows  = $wpdb->get_results("SELECT kind, status, COUNT(*) AS c FROM {$table} GROUP BY kind, status");

        $out = array(
            'page'    => array('pending' => 0, 'processing' => 0, 'done' => 0, 'skipped' => 0, 'failed' => 0),
            'product' => array('pending' => 0, 'processing' => 0, 'done' => 0, 'skipped' => 0, 'failed' => 0),
        );
        foreach ((array) $rows as $row) {
            if (!isset($out[$row->kind][$row->status])) { continue; }
            $out[$row->kind][$row->status] = intval($row->c);
        }
        return $out;
    }

    public static function pending_total() {
        global $wpdb;
        $table = self::queue_table();
        return intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = '" . self::STATUS_PENDING . "'"));
    }

    public static function processing_total() {
        global $wpdb;
        $table = self::queue_table();
        return intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = '" . self::STATUS_PROCESSING . "'"));
    }

    public static function visited_pages() {
        global $wpdb;
        $table = self::queue_table();
        return intval($wpdb->get_var("SELECT COUNT(*) FROM {$table}"));
    }

    public static function imported_products() {
        global $wpdb;
        $table = self::queue_table();
        return intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE kind = '" . self::KIND_PRODUCT . "' AND status = '" . self::STATUS_DONE . "'"));
    }

    /**
     * Зависшие processing возвращаются в очередь: тик мог умереть по таймауту PHP.
     */
    public static function requeue_stuck($older_than_minutes = 10) {
        global $wpdb;
        $table  = self::queue_table();
        // updated_at пишется в локальном времени сайта, поэтому и порог считаем в нем же.
        $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - ($older_than_minutes * 60));

        return intval($wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, updated_at = %s WHERE status = %s AND updated_at < %s",
            self::STATUS_PENDING,
            current_time('mysql'),
            self::STATUS_PROCESSING,
            $cutoff
        )));
    }

    public static function reset_queue() {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . self::queue_table());
    }

    public static function recent_failures($limit = 20) {
        global $wpdb;
        $table = self::queue_table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT url, note FROM {$table} WHERE status = %s ORDER BY updated_at DESC LIMIT %d",
            self::STATUS_FAILED,
            $limit
        ));
    }

    public static function log($level, $message) {
        global $wpdb;
        $wpdb->insert(self::log_table(), array(
            'level'      => $level,
            'message'    => mb_substr((string) $message, 0, 1000),
            'created_at' => current_time('mysql'),
        ));

        // Журнал не должен расти бесконечно.
        if (mt_rand(1, 50) === 1) { self::trim_log(); }
    }

    public static function trim_log($keep = 500) {
        global $wpdb;
        $table = self::log_table();
        $max   = intval($wpdb->get_var("SELECT MAX(id) FROM {$table}"));
        if ($max > $keep) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id < %d", $max - $keep));
        }
    }

    public static function recent_logs($limit = 60) {
        global $wpdb;
        $table = self::log_table();
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit));
    }

    public static function clear_log() {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . self::log_table());
    }

    public static function drop_tables() {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::queue_table());
        $wpdb->query('DROP TABLE IF EXISTS ' . self::log_table());
        delete_option(self::DB_VERSION_OPTION);
    }
}
