<?php
/**
 * Экран администратора.
 *
 * Управление сведено к четырем кнопкам: «Запустить», «Остановить»,
 * «Очистить очередь» и «Проверить страницу». Все остальное плагин
 * решает сам, а состояние показывает панель, которая обновляется без
 * перезагрузки и без длинных запросов.
 */

if (!defined('ABSPATH')) { exit; }

class UWP_Admin {

    const CAPABILITY = 'manage_woocommerce';
    const NONCE      = 'uwp_parser_admin';
    const SLUG       = 'uwp-parser';

    public function hooks() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));

        add_action('wp_ajax_uwp_start', array($this, 'ajax_start'));
        add_action('wp_ajax_uwp_stop', array($this, 'ajax_stop'));
        add_action('wp_ajax_uwp_clear', array($this, 'ajax_clear'));
        add_action('wp_ajax_uwp_status', array($this, 'ajax_status'));
        add_action('wp_ajax_uwp_inspect', array($this, 'ajax_inspect'));
        add_action('wp_ajax_uwp_run_tick', array($this, 'ajax_run_tick'));
    }

    private function capability() {
        return current_user_can(self::CAPABILITY) ? self::CAPABILITY : 'manage_options';
    }

    public function add_menu() {
        $parent = class_exists('WooCommerce') ? 'woocommerce' : 'tools.php';
        add_submenu_page(
            $parent,
            'Парсер товаров',
            'Парсер товаров',
            $this->capability(),
            self::SLUG,
            array($this, 'render')
        );
    }

    public function assets($hook) {
        if (strpos((string) $hook, self::SLUG) === false) { return; }

        wp_enqueue_style('uwp-admin', UWP_URL . 'assets/admin.css', array(), UWP_VERSION);
        wp_enqueue_script('uwp-admin', UWP_URL . 'assets/admin.js', array('jquery'), UWP_VERSION, true);

        wp_localize_script('uwp-admin', 'UWP', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE),
        ));
    }

    // ---------------------------------------------------------------------
    // AJAX
    // ---------------------------------------------------------------------

    private function guard() {
        if (!current_user_can(self::CAPABILITY) && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Недостаточно прав.'), 403);
        }
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(array('message' => 'Устарела страница администратора. Обновите ее и повторите.'), 403);
        }
    }

    /**
     * Единственная «большая» кнопка: сохранить настройки, наполнить очередь и запустить фон.
     */
    public function ajax_start() {
        $this->guard();

        $settings = UWP_Settings::save($this->posted_form());

        if (trim((string) $settings['source_url']) === '') {
            wp_send_json_error(array('message' => 'Укажите домен источника — без него парсеру нечего обходить.'));
        }
        if (!UWP_Importer::woocommerce_ready()) {
            wp_send_json_error(array('message' => 'WooCommerce не активен. Товары складывать некуда.'));
        }

        UWP_DB::maybe_install();

        $fresh = !empty($_POST['fresh']);
        if ($fresh) { UWP_DB::reset_queue(); }

        $seed = UWP_Crawler::seed();
        if ($seed['pages'] === 0 && UWP_DB::pending_total() === 0) {
            wp_send_json_error(array(
                'message' => 'Не удалось поставить в очередь ни одной страницы. Проверьте адрес источника кнопкой «Проверить страницу».',
                'details' => $seed['notes'],
            ));
        }

        UWP_Runner::start();
        UWP_DB::log('info', 'Запуск парсера. Источник: ' . UWP_Crawler::source_url() . '. В очереди: ' . UWP_DB::pending_total() . '.');

        wp_send_json_success(array(
            'message' => 'Парсер запущен. В очереди страниц: ' . UWP_DB::pending_total() . '.',
            'details' => $seed['notes'],
            'status'  => UWP_Runner::status(),
        ));
    }

    public function ajax_stop() {
        $this->guard();
        UWP_Runner::stop();
        UWP_DB::log('info', 'Парсер остановлен вручную.');

        wp_send_json_success(array(
            'message' => 'Парсер остановлен. Очередь сохранена — повторный запуск продолжит с того же места.',
            'status'  => UWP_Runner::status(),
        ));
    }

    public function ajax_clear() {
        $this->guard();
        UWP_Runner::stop();
        UWP_DB::reset_queue();
        UWP_DB::clear_log();

        wp_send_json_success(array(
            'message' => 'Очередь и журнал очищены. Импортированные товары не тронуты.',
            'status'  => UWP_Runner::status(),
        ));
    }

    public function ajax_status() {
        $this->guard();

        $logs = array();
        foreach (UWP_DB::recent_logs(40) as $entry) {
            $logs[] = array(
                'time'    => mysql2date('H:i:s', $entry->created_at),
                'level'   => $entry->level,
                'message' => $entry->message,
            );
        }

        wp_send_json_success(array(
            'status' => UWP_Runner::status(),
            'logs'   => $logs,
        ));
    }

    /**
     * Тик очереди, запущенный открытой вкладкой админки.
     *
     * Нужен для хостингов, где заблокированы обращения сайта к самому себе
     * и отключен WP-Cron: там фоновая цепочка не стартует и очередь стоит.
     * Пока страница парсера открыта, работу двигает браузер. Тик ограничен
     * тем же бюджетом времени, поэтому запрос всегда короткий.
     */
    public function ajax_run_tick() {
        $this->guard();

        if (!UWP_Runner::is_running()) {
            wp_send_json_success(array('ran' => false, 'status' => UWP_Runner::status()));
        }

        $summary = UWP_Runner::tick(false);

        wp_send_json_success(array(
            'ran'      => true,
            'summary'  => $summary,
            'status'   => UWP_Runner::status(),
        ));
    }

    /**
     * Диагностика одной страницы: что плагин на ней увидел и куда положит товар.
     */
    public function ajax_inspect() {
        $this->guard();
        UWP_Settings::save($this->posted_form());

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        if ($url === '') { $url = UWP_Crawler::source_url(); }
        if ($url === '') {
            wp_send_json_error(array('message' => 'Укажите адрес страницы или домен источника.'));
        }

        $started  = microtime(true);
        $response = UWP_Http::get($url);
        $elapsed  = round(microtime(true) - $started, 2);

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Страница не открылась: ' . $response->get_error_message() . ' (' . $elapsed . ' с)'));
        }

        $extractor = new UWP_Extractor($response['body'], $response['url']);
        $product   = $extractor->product_data();
        $crumbs    = $extractor->breadcrumbs($product !== null, $product ? $product['title'] : '');
        $links     = $extractor->links();

        $lines   = array();
        $lines[] = 'Загружено за ' . $elapsed . ' с, ' . number_format_i18n(strlen($response['body'])) . ' байт, HTTP ' . $response['code'];
        $lines[] = 'Домен источника из настроек: ' . (UWP_Crawler::source_url() ? UWP_Crawler::source_url() : 'не задан');
        $lines[] = 'Тот же домен: ' . (UWP_Url::same_site($response['url'], UWP_Crawler::source_url()) ? 'да' : 'НЕТ — страница не будет импортирована');
        $lines[] = '';

        if ($product) {
            $path = UWP_Crawler::resolve_category_path($crumbs, array(), $response['url'], $product['title']);

            $lines[] = 'Это карточка товара. Признаки: ' . implode(', ', $product['signals']);
            $lines[] = 'Название: ' . $product['title'];
            $lines[] = 'Артикул: ' . ($product['sku'] !== '' ? $product['sku'] : '—');
            $lines[] = 'Цена: ' . ($product['price'] !== '' ? $product['price'] . ' ' . $product['currency'] : 'не найдена');
            $lines[] = 'Наличие: ' . ($product['in_stock'] ? 'в наличии' : 'нет в наличии');
            $lines[] = 'Изображений: ' . count($product['images']);
            $lines[] = 'Характеристик: ' . count($product['attributes']);
            $lines[] = 'Описание: ' . (mb_strlen(wp_strip_all_tags($product['description'])) > 0 ? mb_strlen(wp_strip_all_tags($product['description'])) . ' символов' : 'не найдено');
            $lines[] = '';
            $lines[] = 'Категории источника: ' . ($path ? implode(' / ', $path) : 'не определены');
            $lines[] = 'Ляжет в каталог WooCommerce: ' . $this->preview_category($path);

            if ($product['attributes']) {
                $lines[] = '';
                $lines[] = 'Характеристики:';
                $shown = 0;
                foreach ($product['attributes'] as $name => $value) {
                    $lines[] = '  • ' . $name . ': ' . $value;
                    if (++$shown >= 12) { break; }
                }
            }
        } else {
            $report = $extractor->detection_report();

            $lines[] = 'Это НЕ карточка товара — страница будет обойдена как раздел каталога.';
            $lines[] = 'Оценка признаков товара: ' . $report['score'] . ' из нужных ' . $report['threshold'] . '.';
            $lines[] = 'Что нашлось: ' . ($report['signals'] ? implode(', ', $report['signals']) : 'ничего товарного');
            if ($report['title'] === '') {
                $lines[] = 'Название товара не определилось — задайте селектор названия в разделе «Дополнительно».';
            }
            $lines[] = '';
            $lines[] = 'Заголовок страницы: ' . $extractor->page_title();
            $lines[] = 'Хлебные крошки: ' . ($crumbs ? implode(' / ', $crumbs) : 'не найдены');
            $lines[] = 'Всего ссылок: ' . count($links);

            $accepted = 0;
            $sample   = array();
            foreach ($links as $link) {
                $candidate = $link['url'];
                if (!UWP_Url::same_site($candidate, UWP_Crawler::source_url())) { continue; }
                if (UWP_Url::is_file($candidate) || UWP_Url::is_service($candidate)) { continue; }
                if (!UWP_Url::passes_path_filters($candidate)) { continue; }
                $accepted++;
                if (count($sample) < 15) { $sample[] = $candidate; }
            }
            $lines[] = 'Из них пойдут в обход: ' . $accepted;
            if ($sample) {
                $lines[] = '';
                $lines[] = 'Примеры ссылок для обхода:';
                foreach ($sample as $item) { $lines[] = '  • ' . $item; }
            }
        }

        wp_send_json_success(array('report' => implode("\n", $lines)));
    }

    private function preview_category($path) {
        $root = trim((string) UWP_Settings::get('root_category'));
        $full = $path;
        if ($root !== '') { array_unshift($full, $root); }

        if (!$full) { return 'категория не определена (товар попадет в корень каталога)'; }
        return implode(' / ', $full);
    }

    /**
     * Значения формы настроек из POST.
     */
    private function posted_form() {
        $form = array('__uwp_form' => 1);

        foreach (array_keys(UWP_Settings::defaults()) as $key) {
            if (isset($_POST[$key])) { $form[$key] = $_POST[$key]; }
        }

        return $form;
    }

    // ---------------------------------------------------------------------
    // Разметка страницы
    // ---------------------------------------------------------------------

    public function render() {
        if (!current_user_can(self::CAPABILITY) && !current_user_can('manage_options')) {
            wp_die('Недостаточно прав для доступа к этой странице.');
        }

        UWP_DB::maybe_install();
        $s = UWP_Settings::all();
        ?>
        <div class="wrap uwp">
            <h1>Парсер товаров <span class="uwp-version">v<?php echo esc_html(UWP_VERSION); ?></span></h1>

            <?php if (!UWP_Importer::woocommerce_ready()) : ?>
                <div class="notice notice-error"><p>WooCommerce не активен. Плагин не сможет создавать товары.</p></div>
            <?php endif; ?>

            <p class="uwp-intro">
                Укажите домен каталога, из которого нужно забрать товары, и нажмите «Запустить».
                Плагин сам найдет разделы и карточки, разберет их и разложит товары
                по дереву категорий WooCommerce. Работа идет в фоне — страницу можно закрыть.
            </p>

            <div class="uwp-panel" id="uwp-status-panel">
                <div class="uwp-status-head">
                    <span class="uwp-badge" id="uwp-state">…</span>
                    <span class="uwp-heartbeat" id="uwp-heartbeat"></span>
                </div>
                <div class="uwp-progress"><div class="uwp-progress-bar" id="uwp-progress-bar"></div></div>
                <div class="uwp-metrics" id="uwp-metrics"></div>
                <div class="uwp-failures" id="uwp-failures" style="display:none"></div>
            </div>

            <p class="uwp-hint">
                Пока эта страница открыта, очередь двигает браузер — это работает
                на любом хостинге. Закрытую вкладку подхватывает фоновый режим.
            </p>

            <div class="uwp-actions">
                <button type="button" class="button button-primary button-hero" id="uwp-start">Запустить</button>
                <button type="button" class="button button-hero" id="uwp-stop">Остановить</button>
                <button type="button" class="button" id="uwp-clear">Очистить очередь</button>
                <label class="uwp-fresh"><input type="checkbox" id="uwp-fresh" checked> начать заново</label>
            </div>

            <div class="uwp-output" id="uwp-output"></div>

            <form id="uwp-form" class="uwp-form">
                <div class="uwp-card">
                    <h2>Источник</h2>

                    <div class="uwp-field">
                        <label for="source_url">Домен или раздел каталога</label>
                        <input type="text" id="source_url" name="source_url" value="<?php echo esc_attr($s['source_url']); ?>" placeholder="https://example.kz/catalog/">
                        <p class="uwp-hint">Обходится строго этот домен. Если указать конкретный раздел, обход начнется с него.</p>
                    </div>

                    <div class="uwp-grid">
                        <div class="uwp-field">
                            <label for="include_paths">Только эти разделы</label>
                            <textarea id="include_paths" name="include_paths" rows="4" placeholder="/catalog/&#10;/produkciya/"><?php echo esc_textarea($s['include_paths']); ?></textarea>
                            <p class="uwp-hint">По одному фрагменту адреса в строке. Пусто — весь сайт.</p>
                        </div>
                        <div class="uwp-field">
                            <label for="exclude_paths">Кроме этих разделов</label>
                            <textarea id="exclude_paths" name="exclude_paths" rows="4"><?php echo esc_textarea($s['exclude_paths']); ?></textarea>
                            <p class="uwp-hint">Новости, контакты и прочее, что товарами не является.</p>
                        </div>
                    </div>

                    <label class="uwp-check"><input type="checkbox" name="use_sitemap" value="1" <?php checked($s['use_sitemap'], 1); ?>> Использовать карту сайта источника (быстрее и полнее)</label>
                </div>

                <div class="uwp-card">
                    <h2>Куда класть в каталог</h2>

                    <div class="uwp-grid">
                        <div class="uwp-field">
                            <label for="root_category">Корневая категория WooCommerce</label>
                            <input type="text" id="root_category" name="root_category" value="<?php echo esc_attr($s['root_category']); ?>" placeholder="например: Каталог поставщика">
                            <p class="uwp-hint">Пусто — дерево источника создается на верхнем уровне каталога.</p>
                        </div>
                        <div class="uwp-field">
                            <label for="post_status">Статус новых товаров</label>
                            <select id="post_status" name="post_status">
                                <option value="draft" <?php selected($s['post_status'], 'draft'); ?>>Черновик</option>
                                <option value="publish" <?php selected($s['post_status'], 'publish'); ?>>Опубликован</option>
                                <option value="pending" <?php selected($s['post_status'], 'pending'); ?>>На утверждении</option>
                                <option value="private" <?php selected($s['post_status'], 'private'); ?>>Личный</option>
                            </select>
                        </div>
                    </div>

                    <label class="uwp-check"><input type="checkbox" name="create_categories" value="1" <?php checked($s['create_categories'], 1); ?>> Создавать недостающие категории по хлебным крошкам источника</label>
                    <label class="uwp-check"><input type="checkbox" name="require_category" value="1" <?php checked($s['require_category'], 1); ?>> Пропускать товары, для которых категория не определилась</label>
                    <label class="uwp-check"><input type="checkbox" name="update_existing" value="1" <?php checked($s['update_existing'], 1); ?>> Обновлять ранее импортированные товары</label>
                </div>

                <div class="uwp-card">
                    <h2>Что импортировать</h2>

                    <label class="uwp-check"><input type="checkbox" name="import_prices" value="1" <?php checked($s['import_prices'], 1); ?>> Цены</label>
                    <label class="uwp-check"><input type="checkbox" name="quote_mode" value="1" <?php checked($s['quote_mode'], 1); ?>> Товары без цены помечать как «Цена по запросу» (заказ остается доступен)</label>
                    <label class="uwp-check"><input type="checkbox" name="import_attributes" value="1" <?php checked($s['import_attributes'], 1); ?>> Характеристики</label>
                    <label class="uwp-check"><input type="checkbox" name="import_images" value="1" <?php checked($s['import_images'], 1); ?>> Изображения</label>

                    <div class="uwp-field uwp-narrow">
                        <label for="max_images">Изображений на товар</label>
                        <input type="number" id="max_images" name="max_images" min="0" max="10" value="<?php echo esc_attr($s['max_images']); ?>">
                    </div>
                </div>

                <details class="uwp-card uwp-advanced">
                    <summary>Дополнительно: скорость, пределы и ручные селекторы</summary>

                    <div class="uwp-grid uwp-grid-3">
                        <div class="uwp-field">
                            <label for="requests_per_tick">Запросов за один проход</label>
                            <input type="number" id="requests_per_tick" name="requests_per_tick" min="1" max="20" value="<?php echo esc_attr($s['requests_per_tick']); ?>">
                        </div>
                        <div class="uwp-field">
                            <label for="tick_budget">Секунд на один проход</label>
                            <input type="number" id="tick_budget" name="tick_budget" min="5" max="60" value="<?php echo esc_attr($s['tick_budget']); ?>">
                        </div>
                        <div class="uwp-field">
                            <label for="request_delay_ms">Пауза между запросами, мс</label>
                            <input type="number" id="request_delay_ms" name="request_delay_ms" min="0" max="10000" value="<?php echo esc_attr($s['request_delay_ms']); ?>">
                        </div>
                        <div class="uwp-field">
                            <label for="request_timeout">Таймаут запроса, с</label>
                            <input type="number" id="request_timeout" name="request_timeout" min="5" max="60" value="<?php echo esc_attr($s['request_timeout']); ?>">
                        </div>
                        <div class="uwp-field">
                            <label for="max_depth">Глубина обхода</label>
                            <input type="number" id="max_depth" name="max_depth" min="0" max="20" value="<?php echo esc_attr($s['max_depth']); ?>">
                        </div>
                        <div class="uwp-field">
                            <label for="max_pages">Предел страниц в очереди</label>
                            <input type="number" id="max_pages" name="max_pages" min="0" value="<?php echo esc_attr($s['max_pages']); ?>">
                        </div>
                        <div class="uwp-field">
                            <label for="max_products">Предел товаров, 0 — без предела</label>
                            <input type="number" id="max_products" name="max_products" min="0" value="<?php echo esc_attr($s['max_products']); ?>">
                        </div>
                        <div class="uwp-field">
                            <label for="max_attempts">Попыток на страницу</label>
                            <input type="number" id="max_attempts" name="max_attempts" min="1" max="10" value="<?php echo esc_attr($s['max_attempts']); ?>">
                        </div>
                    </div>

                    <div class="uwp-grid">
                        <div class="uwp-field">
                            <label for="user_agent">User-Agent</label>
                            <input type="text" id="user_agent" name="user_agent" value="<?php echo esc_attr($s['user_agent']); ?>">
                        </div>
                        <div class="uwp-field">
                            <label for="proxy">Прокси, если сайт не открывается с сервера</label>
                            <input type="text" id="proxy" name="proxy" value="<?php echo esc_attr($s['proxy']); ?>" placeholder="user:pass@host:port">
                        </div>
                    </div>

                    <h3>Ручные CSS-селекторы</h3>
                    <p class="uwp-hint">Нужны редко — только если автоматический разбор промахнулся на конкретном сайте. Пусто = определять автоматически.</p>
                    <div class="uwp-grid uwp-grid-3">
                        <div class="uwp-field">
                            <label for="sel_title">Название товара</label>
                            <input type="text" id="sel_title" name="sel_title" value="<?php echo esc_attr($s['sel_title']); ?>">
                        </div>
                        <div class="uwp-field">
                            <label for="sel_price">Цена</label>
                            <input type="text" id="sel_price" name="sel_price" value="<?php echo esc_attr($s['sel_price']); ?>">
                        </div>
                        <div class="uwp-field">
                            <label for="sel_description">Описание</label>
                            <input type="text" id="sel_description" name="sel_description" value="<?php echo esc_attr($s['sel_description']); ?>">
                        </div>
                        <div class="uwp-field">
                            <label for="sel_image">Изображения</label>
                            <input type="text" id="sel_image" name="sel_image" value="<?php echo esc_attr($s['sel_image']); ?>">
                        </div>
                        <div class="uwp-field">
                            <label for="sel_attributes">Характеристики</label>
                            <input type="text" id="sel_attributes" name="sel_attributes" value="<?php echo esc_attr($s['sel_attributes']); ?>">
                        </div>
                        <div class="uwp-field">
                            <label for="sel_breadcrumbs">Хлебные крошки</label>
                            <input type="text" id="sel_breadcrumbs" name="sel_breadcrumbs" value="<?php echo esc_attr($s['sel_breadcrumbs']); ?>">
                        </div>
                        <div class="uwp-field">
                            <label for="sel_product_link">Ссылки на карточки в разделе</label>
                            <input type="text" id="sel_product_link" name="sel_product_link" value="<?php echo esc_attr($s['sel_product_link']); ?>">
                        </div>
                    </div>
                </details>

                <div class="uwp-card">
                    <h2>Проверка страницы</h2>
                    <p class="uwp-hint">Покажет, что плагин видит на конкретном адресе и в какую категорию каталога положит товар. Ничего не импортирует.</p>
                    <div class="uwp-inspect">
                        <input type="url" id="uwp-inspect-url" placeholder="https://example.kz/catalog/tovar/">
                        <button type="button" class="button" id="uwp-inspect">Проверить страницу</button>
                    </div>
                    <pre class="uwp-report" id="uwp-report"></pre>
                </div>
            </form>

            <div class="uwp-card">
                <h2>Журнал</h2>
                <div class="uwp-log" id="uwp-log">Пока пусто</div>
            </div>
        </div>
        <?php
    }
}
