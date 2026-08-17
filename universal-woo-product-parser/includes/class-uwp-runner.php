<?php
/**
 * Исполнитель фоновой работы.
 *
 * Вся защита от зависаний собрана здесь:
 *  - тик ограничен по времени и по числу запросов;
 *  - параллельные тики отсекаются блокировкой;
 *  - следующий тик запускается неблокирующим запросом к самому себе,
 *    поэтому браузер ничего не ждет и 504 взяться неоткуда;
 *  - WP-Cron остается страховкой на случай, если цепочка оборвалась.
 */

if (!defined('ABSPATH')) { exit; }

class UWP_Runner {

    const HOOK_TICK      = 'uwp_parser_tick';
    const LOCK           = 'uwp_parser_lock';
    const OPT_RUNNING    = 'uwp_parser_running';
    const OPT_HEARTBEAT  = 'uwp_parser_heartbeat';
    const OPT_TOKEN      = 'uwp_parser_token';
    const OPT_STATS      = 'uwp_parser_stats';

    /** @var int отметка времени, после которой тик обязан завершиться */
    private static $deadline = 0;

    public static function is_running() {
        return (bool) get_option(self::OPT_RUNNING, 0);
    }

    public static function start() {
        update_option(self::OPT_RUNNING, 1, false);
        update_option(self::OPT_HEARTBEAT, time(), false);
        self::schedule_cron();
        self::spawn();
    }

    public static function stop() {
        update_option(self::OPT_RUNNING, 0, false);
        delete_transient(self::LOCK);
        self::unschedule_cron();
    }

    public static function schedule_cron() {
        if (!wp_next_scheduled(self::HOOK_TICK)) {
            wp_schedule_event(time() + 60, 'uwp_minute', self::HOOK_TICK);
        }
    }

    public static function unschedule_cron() {
        $timestamp = wp_next_scheduled(self::HOOK_TICK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK_TICK);
            $timestamp = wp_next_scheduled(self::HOOK_TICK);
        }
    }

    public static function token() {
        $token = get_option(self::OPT_TOKEN, '');
        if (!$token) {
            $token = wp_generate_password(24, false, false);
            update_option(self::OPT_TOKEN, $token, false);
        }
        return $token;
    }

    /**
     * Запуск следующего тика фоном. Запрос уходит без ожидания ответа.
     */
    public static function spawn() {
        if (!self::is_running()) { return; }
        if (get_transient(self::LOCK)) { return; }

        wp_remote_post(admin_url('admin-ajax.php'), array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
            'body'      => array(
                'action' => 'uwp_tick',
                'token'  => self::token(),
            ),
            'cookies'   => array(),
        ));
    }

    /**
     * Один тик обработки очереди.
     *
     * @return array сводка тика
     */
    public static function tick() {
        $summary = array(
            'processed' => 0,
            'products'  => 0,
            'pages'     => 0,
            'errors'    => 0,
            'messages'  => array(),
            'pending'   => 0,
            'stopped'   => false,
        );

        if (!self::is_running()) {
            $summary['stopped'] = true;
            return $summary;
        }

        if (get_transient(self::LOCK)) {
            $summary['messages'][] = 'Предыдущий тик еще выполняется.';
            return $summary;
        }

        $budget = UWP_Settings::int('tick_budget');
        set_transient(self::LOCK, 1, $budget + 30);

        self::raise_limits($budget);
        self::$deadline = time() + $budget;

        // Записи, зависшие в processing после аварийного завершения PHP.
        UWP_DB::requeue_stuck(10);

        $max_requests = UWP_Settings::int('requests_per_tick');
        $max_products = UWP_Settings::int('max_products');

        try {
            for ($i = 0; $i < $max_requests; $i++) {
                if (self::out_of_time()) { break; }

                if ($max_products > 0 && UWP_DB::imported_products() >= $max_products) {
                    $summary['messages'][] = 'Достигнут лимит товаров (' . $max_products . '). Останавливаюсь.';
                    self::finish_run('Лимит товаров достигнут');
                    $summary['stopped'] = true;
                    break;
                }

                $row = UWP_DB::claim_next();
                if (!$row) {
                    // Останавливаемся только если работы действительно не осталось.
                    // Пустой ответ бывает и при гонке за задачу с параллельным тиком.
                    if (UWP_DB::pending_total() > 0 || UWP_DB::processing_total() > 0) { break; }

                    $summary['messages'][] = 'Очередь пуста — работа завершена.';
                    self::finish_run('Очередь пуста');
                    $summary['stopped'] = true;
                    break;
                }

                $result = self::process_row($row);
                $summary['processed']++;

                if ($result['status'] === 'error') { $summary['errors']++; }
                elseif ($result['status'] === 'product' || $result['status'] === 'created' || $result['status'] === 'updated') { $summary['products']++; }
                else { $summary['pages']++; }

                if (!empty($result['message'])) {
                    $summary['messages'][] = $result['message'];
                    UWP_DB::log($result['status'] === 'error' ? 'error' : 'info', $result['message']);
                }

                UWP_Http::polite_delay();
            }
        } catch (Throwable $e) {
            $summary['errors']++;
            $summary['messages'][] = 'Сбой тика: ' . $e->getMessage();
            UWP_DB::log('error', 'Сбой тика: ' . $e->getMessage());
        }

        $summary['pending'] = UWP_DB::pending_total();

        update_option(self::OPT_HEARTBEAT, time(), false);
        update_option(self::OPT_STATS, array(
            'time'      => time(),
            'processed' => $summary['processed'],
            'errors'    => $summary['errors'],
        ), false);

        delete_transient(self::LOCK);

        // Есть работа — сразу заказываем следующий тик.
        if (!$summary['stopped'] && $summary['pending'] > 0 && self::is_running()) {
            self::spawn();
        }

        return $summary;
    }

    private static function process_row($row) {
        if ($row->kind === UWP_DB::KIND_PRODUCT) {
            return UWP_Importer::import_row($row);
        }
        return UWP_Crawler::process_page($row);
    }

    private static function finish_run($reason) {
        update_option(self::OPT_RUNNING, 0, false);
        self::unschedule_cron();
        UWP_DB::log('info', 'Парсер остановлен: ' . $reason . '. Импортировано товаров: ' . UWP_DB::imported_products() . '.');
    }

    private static function out_of_time() {
        if (self::$deadline && time() >= self::$deadline) { return true; }

        if (function_exists('memory_get_usage')) {
            $limit = self::memory_limit_bytes();
            if ($limit > 0 && memory_get_usage(true) > $limit * 0.8) { return true; }
        }
        return false;
    }

    private static function memory_limit_bytes() {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') { return 0; }

        $unit   = strtolower(substr($value, -1));
        $number = (float) $value;

        if ($unit === 'g') { $number *= 1024 * 1024 * 1024; }
        elseif ($unit === 'm') { $number *= 1024 * 1024; }
        elseif ($unit === 'k') { $number *= 1024; }

        return (int) $number;
    }

    private static function raise_limits($budget) {
        if (function_exists('set_time_limit')) { @set_time_limit($budget + 60); }
        @ini_set('memory_limit', '512M');
        if (function_exists('ignore_user_abort')) { @ignore_user_abort(true); }
    }

    /**
     * Данные для панели состояния в админке.
     */
    public static function status() {
        $counts = UWP_DB::counts();

        $total_done = $counts['page']['done'] + $counts['page']['skipped'] + $counts['page']['failed']
            + $counts['product']['done'] + $counts['product']['skipped'] + $counts['product']['failed'];
        $pending    = UWP_DB::pending_total();
        $total      = $total_done + $pending + $counts['page']['processing'] + $counts['product']['processing'];

        $heartbeat = intval(get_option(self::OPT_HEARTBEAT, 0));
        $running   = self::is_running();

        $state = 'Остановлен';
        if ($running) {
            $state = ($heartbeat && (time() - $heartbeat) > 180)
                ? 'Запущен, но тики не идут — проверьте WP-Cron'
                : 'Работает';
        }

        return array(
            'running'         => $running,
            'state'           => $state,
            'pending'         => $pending,
            'pages_done'      => $counts['page']['done'],
            'pages_pending'   => $counts['page']['pending'],
            'products_done'   => $counts['product']['done'],
            'products_queued' => $counts['product']['pending'],
            'skipped'         => $counts['page']['skipped'] + $counts['product']['skipped'],
            'failed'          => $counts['page']['failed'] + $counts['product']['failed'],
            'total'           => $total,
            'progress'        => $total > 0 ? min(100, round($total_done / $total * 100)) : 0,
            'heartbeat'       => $heartbeat ? human_time_diff($heartbeat, time()) . ' назад' : 'еще не было',
            'source'          => UWP_Crawler::source_url(),
        );
    }
}
