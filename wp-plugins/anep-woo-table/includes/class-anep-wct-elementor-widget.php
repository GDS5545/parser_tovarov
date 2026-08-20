<?php
if (!defined('ABSPATH')) {
    exit;
}

class ANEP_WCT_Elementor_Widget extends \Elementor\Widget_Base {
    public function get_name() {
        return 'anep_wct_table';
    }

    public function get_title() {
        return 'ANEP таблица товаров';
    }

    public function get_icon() {
        return 'eicon-table';
    }

    public function get_categories() {
        return array('woocommerce-elements', 'general');
    }

    public function get_keywords() {
        return array('anep', 'woocommerce', 'товары', 'таблица', 'фильтр', 'каталог');
    }

    protected function register_controls() {
        $plugin = ANEP_Woo_Category_Table::instance();
        $attribute_options = $plugin->get_all_attribute_options();

        $this->start_controls_section(
            'section_content',
            array(
                'label' => 'Таблица товаров',
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            )
        );

        $this->add_control(
            'category',
            array(
                'label'       => 'Категория',
                'type'        => \Elementor\Controls_Manager::SELECT2,
                'options'     => $plugin->get_product_cat_options(true, true),
                'default'     => 'current',
                'label_block' => true,
                'description' => 'Для шаблона категории выбирай «Текущая категория». Для обычной страницы выбери конкретную категорию или «Все товары».',
            )
        );

        $this->add_control(
            'per_page',
            array(
                'label'   => 'Товаров на странице',
                'type'    => \Elementor\Controls_Manager::NUMBER,
                'min'     => 5,
                'max'     => 300,
                'step'    => 5,
                'default' => 50,
            )
        );

        $this->add_control(
            'show_chips',
            array(
                'label'        => 'Быстрые кнопки сверху',
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => 'Да',
                'label_off'    => 'Нет',
                'return_value' => 'yes',
                'default'      => 'yes',
            )
        );

        $this->add_control(
            'show_filters',
            array(
                'label'        => 'Фильтры продукции',
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => 'Да',
                'label_off'    => 'Нет',
                'return_value' => 'yes',
                'default'      => 'yes',
            )
        );

        $this->add_control(
            'show_pagination',
            array(
                'label'        => 'Пагинация',
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => 'Да',
                'label_off'    => 'Нет',
                'return_value' => 'yes',
                'default'      => 'yes',
            )
        );

        $this->add_control(
            'show_cart_summary',
            array(
                'label'        => 'Блок корзины над таблицей',
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => 'Да',
                'label_off'    => 'Нет',
                'return_value' => 'yes',
                'default'      => 'yes',
            )
        );

        $this->add_control(
            'style_source',
            array(
                'label'       => 'Стиль по умолчанию',
                'type'        => \Elementor\Controls_Manager::SELECT,
                'options'     => array(
                    ''      => 'Стили Elementor / минимальная база',
                    'elementor' => 'Стили Elementor / минимальная база',
                    'theme' => 'Из темы / Elementor Global Styles',
                    'anep'  => 'Встроенный стиль ANEP',
                    'custom' => 'Свои стили из админки',
                ),
                'default'     => 'elementor',
                'description' => 'Для шаблона архива выбирай этот режим. Он не берет цвета/кнопки из админки и дает настройкам вкладки «Стиль» Elementor нормальный приоритет.',
            )
        );

        $this->add_control(
            'archive_width_mode',
            array(
                'label'       => 'Ширина в шаблоне архива',
                'type'        => \Elementor\Controls_Manager::SELECT,
                'options'     => array(
                    'boxed'   => 'Ограничить ширину блока',
                    'inherit' => 'Как задано контейнером Elementor',
                    'full'    => 'На всю ширину',
                ),
                'default'     => 'boxed',
                'description' => 'Если архив WooCommerce растягивает таблицу на всю ширину, оставь «Ограничить ширину блока». Это работает даже когда настройки страницы архива игнорируются темой.',
            )
        );

        $this->add_responsive_control(
            'archive_max_width',
            array(
                'label'      => 'Максимальная ширина блока',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => array('px', '%'),
                'range'      => array(
                    'px' => array('min' => 320, 'max' => 1800),
                    '%'  => array('min' => 30, 'max' => 100),
                ),
                'default'    => array('size' => 1200, 'unit' => 'px'),
                'condition'  => array('archive_width_mode' => 'boxed'),
            )
        );

        $this->add_responsive_control(
            'archive_side_padding',
            array(
                'label'      => 'Боковой отступ блока',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => array('px'),
                'range'      => array('px' => array('min' => 0, 'max' => 80)),
                'default'    => array('size' => 0, 'unit' => 'px'),
                'description'=> 'Полезно для мобильной версии, чтобы таблица не прилипала к краю экрана.',
            )
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_columns',
            array(
                'label' => 'Фильтры и столбцы',
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            )
        );

        $this->add_control(
            'filter_attributes',
            array(
                'label'       => 'Какие атрибуты вывести фильтрами',
                'type'        => \Elementor\Controls_Manager::SELECT2,
                'options'     => $attribute_options,
                'multiple'    => true,
                'label_block' => true,
                'description' => 'Если не выбрать — будут использованы настройки WooCommerce → ANEP Table или авто-подбор.',
            )
        );

        $this->add_control(
            'column_attributes',
            array(
                'label'       => 'Какие атрибуты вывести столбцами',
                'type'        => \Elementor\Controls_Manager::SELECT2,
                'options'     => $attribute_options,
                'multiple'    => true,
                'label_block' => true,
                'description' => 'Порядок выбора — порядок колонок в таблице. Если колонок больше общего лимита, лишние будут скрыты.',
            )
        );

        $this->add_control(
            'max_filters',
            array(
                'label'   => 'Максимум фильтров при авто-подборе',
                'type'    => \Elementor\Controls_Manager::NUMBER,
                'min'     => 1,
                'max'     => 20,
                'step'    => 1,
                'default' => 6,
            )
        );

        $this->add_control(
            'max_columns',
            array(
                'label'   => 'Максимум колонок таблицы всего',
                'type'    => \Elementor\Controls_Manager::NUMBER,
                'min'     => 1,
                'max'     => 12,
                'step'    => 1,
                'default' => 6,
            )
        );

        $this->add_control(
            'hide_empty_columns',
            array(
                'label'        => 'Скрывать пустые столбцы',
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => 'Да',
                'label_off'    => 'Нет',
                'return_value' => 'yes',
                'default'      => 'yes',
                'description'  => 'Если в текущей категории/выборке у колонки нет данных, она не выводится. В другой категории, где данные есть, колонка появится.',
            )
        );

        $this->add_control(
            'mobile_compact',
            array(
                'label'        => 'Мобильный вид: только название и кнопки',
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => 'Да',
                'label_off'    => 'Нет',
                'return_value' => 'yes',
                'default'      => 'yes',
                'description'  => 'На телефоне скрывает характеристики, артикул, цену и отдельное поле количества. Остаются наименование товара и кнопки.',
            )
        );

        $this->add_control(
            'show_name_column',
            array(
                'label'        => 'Колонка «Наименование»',
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => 'Да',
                'label_off'    => 'Нет',
                'return_value' => 'yes',
                'default'      => 'yes',
            )
        );

        $this->add_control(
            'show_sku_column',
            array(
                'label'        => 'Колонка «Артикул»',
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => 'Да',
                'label_off'    => 'Нет',
                'return_value' => 'yes',
                'default'      => '',
            )
        );

        $this->add_control(
            'show_price_column',
            array(
                'label'        => 'Колонка «Цена»',
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => 'Да',
                'label_off'    => 'Нет',
                'return_value' => 'yes',
                'default'      => '',
            )
        );

        $this->add_control(
            'show_actions_column',
            array(
                'label'        => 'Колонка с кнопками',
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => 'Да',
                'label_off'    => 'Нет',
                'return_value' => 'yes',
                'default'      => 'yes',
            )
        );

        $this->add_control(
            'show_quantity',
            array(
                'label'        => 'Поле количества',
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => 'Да',
                'label_off'    => 'Нет',
                'return_value' => 'yes',
                'default'      => 'yes',
                'condition'    => array('show_actions_column' => 'yes'),
            )
        );

        $this->end_controls_section();

        $this->register_style_controls();
    }

    protected function register_style_controls() {
        $this->start_controls_section(
            'section_style_general',
            array(
                'label' => 'Общее',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_control(
            'general_text_color',
            array(
                'label'     => 'Цвет текста',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => array('{{WRAPPER}} .anep-wct' => 'color: {{VALUE}};'),
            )
        );
        $this->add_control(
            'accent_color',
            array(
                'label'     => 'Акцентный цвет',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => array('{{WRAPPER}} .anep-wct' => '--awt-red: {{VALUE}};'),
            )
        );
        $this->add_control(
            'general_background',
            array(
                'label'     => 'Фон блока',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => array('{{WRAPPER}} .anep-wct' => 'background-color: {{VALUE}};'),
            )
        );
        $this->add_responsive_control(
            'general_margin',
            array(
                'label'      => 'Внешние отступы',
                'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => array('px', '%', 'em'),
                'selectors'  => array('{{WRAPPER}} .anep-wct' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'),
            )
        );
        $this->add_responsive_control(
            'general_padding',
            array(
                'label'      => 'Внутренние отступы',
                'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => array('px', '%', 'em'),
                'selectors'  => array('{{WRAPPER}} .anep-wct' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'),
            )
        );
        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_chips',
            array(
                'label' => 'Быстрые кнопки',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            )
        );
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), array('name' => 'chip_typography', 'selector' => '{{WRAPPER}} .anep-wct-chip'));
        $this->add_control('chip_color', array('label' => 'Цвет текста', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-chip' => 'color: {{VALUE}};')));
        $this->add_control('chip_bg', array('label' => 'Фон', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-chip' => 'background-color: {{VALUE}};')));
        $this->add_control('chip_hover_color', array('label' => 'Цвет при наведении', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-chip:hover' => 'color: {{VALUE}};')));
        $this->add_control('chip_hover_bg', array('label' => 'Фон при наведении', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-chip:hover' => 'background-color: {{VALUE}};')));
        $this->add_group_control(\Elementor\Group_Control_Border::get_type(), array('name' => 'chip_border', 'selector' => '{{WRAPPER}} .anep-wct-chip'));
        $this->add_responsive_control('chip_radius', array('label' => 'Скругление', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array('px', '%'), 'selectors' => array('{{WRAPPER}} .anep-wct-chip' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};')));
        $this->add_responsive_control('chip_padding', array('label' => 'Отступы внутри', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array('px', 'em'), 'selectors' => array('{{WRAPPER}} .anep-wct-chip' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};')));
        $this->add_responsive_control('chip_gap', array('label' => 'Расстояние между кнопками', 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array('px' => array('min' => 0, 'max' => 60)), 'selectors' => array('{{WRAPPER}} .anep-wct-chips' => 'gap: {{SIZE}}{{UNIT}};')));
        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_filters',
            array(
                'label' => 'Фильтры',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            )
        );
        $this->add_control('filter_wrap_bg', array('label' => 'Фон блока фильтров', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-filter-wrap' => 'background-color: {{VALUE}} !important;')));
        $this->add_responsive_control('filter_wrap_padding', array('label' => 'Отступы блока фильтров', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array('px', '%', 'em'), 'selectors' => array('{{WRAPPER}} .anep-wct-filter-wrap' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};')));
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), array('name' => 'filter_title_typography', 'label' => 'Типографика заголовка', 'selector' => '{{WRAPPER}} .anep-wct-filter-title'));
        $this->add_control('filter_title_color', array('label' => 'Цвет заголовка', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-filter-title' => 'color: {{VALUE}} !important;')));
        $this->add_responsive_control('filter_grid_gap', array('label' => 'Расстояние между фильтрами', 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array('px' => array('min' => 0, 'max' => 80)), 'selectors' => array('{{WRAPPER}} .anep-wct-filter-form' => 'gap: {{SIZE}}{{UNIT}};')));
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), array('name' => 'select_typography', 'label' => 'Типографика полей', 'selector' => '{{WRAPPER}} .anep-wct-filter-select, {{WRAPPER}} .anep-wct-filter-toggle, {{WRAPPER}} .anep-wct-filter-search, {{WRAPPER}} .anep-wct-filter-panel'));
        $this->add_control('select_color', array('label' => 'Цвет текста полей', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-filter-select, {{WRAPPER}} .anep-wct-filter-toggle, {{WRAPPER}} .anep-wct-filter-search, {{WRAPPER}} .anep-wct-filter-option' => 'color: {{VALUE}} !important;')));
        $this->add_control('select_bg', array('label' => 'Фон полей', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-filter-select, {{WRAPPER}} .anep-wct-filter-toggle, {{WRAPPER}} .anep-wct-filter-search, {{WRAPPER}} .anep-wct-filter-panel' => 'background-color: {{VALUE}} !important;')));
        $this->add_group_control(\Elementor\Group_Control_Border::get_type(), array('name' => 'select_border', 'selector' => '{{WRAPPER}} .anep-wct-filter-select, {{WRAPPER}} .anep-wct-filter-toggle, {{WRAPPER}} .anep-wct-filter-search, {{WRAPPER}} .anep-wct-filter-option'));
        $this->add_responsive_control('select_radius', array('label' => 'Скругление полей', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array('px', '%'), 'selectors' => array('{{WRAPPER}} .anep-wct-filter-select, {{WRAPPER}} .anep-wct-filter-toggle, {{WRAPPER}} .anep-wct-filter-search, {{WRAPPER}} .anep-wct-filter-panel' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;')));
        $this->add_responsive_control('select_padding', array('label' => 'Отступы полей', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array('px', 'em'), 'selectors' => array('{{WRAPPER}} .anep-wct-filter-select, {{WRAPPER}} .anep-wct-filter-toggle, {{WRAPPER}} .anep-wct-filter-search' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;')));
        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_table',
            array(
                'label' => 'Таблица',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            )
        );
        $this->add_control('table_bg', array('label' => 'Фон таблицы', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-table, {{WRAPPER}} .anep-wct-table-wrap' => 'background-color: {{VALUE}} !important;')));
        $this->add_group_control(\Elementor\Group_Control_Border::get_type(), array('name' => 'table_border', 'selector' => '{{WRAPPER}} .anep-wct-table'));
        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), array('name' => 'table_shadow', 'selector' => '{{WRAPPER}} .anep-wct-table-wrap'));
        $this->add_control('header_bg', array('label' => 'Фон заголовка', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-table thead th' => 'background-color: {{VALUE}} !important;')));
        $this->add_control('header_color', array('label' => 'Цвет заголовка', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-table th' => 'color: {{VALUE}} !important;')));
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), array('name' => 'header_typography', 'label' => 'Типографика заголовка', 'selector' => '{{WRAPPER}} .anep-wct-table th'));
        $this->add_control('body_color', array('label' => 'Цвет текста строк', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-table td' => 'color: {{VALUE}} !important;')));
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), array('name' => 'body_typography', 'label' => 'Типографика строк', 'selector' => '{{WRAPPER}} .anep-wct-table td'));
        $this->add_control('row_border_color', array('label' => 'Цвет линий строк', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-table th, {{WRAPPER}} .anep-wct-table td' => 'border-color: {{VALUE}} !important; border-bottom-color: {{VALUE}} !important;')));
        $this->add_control('row_hover_bg', array('label' => 'Фон строки при наведении', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-table tbody tr:hover td' => 'background-color: {{VALUE}} !important;')));
        $this->add_control('name_color', array('label' => 'Цвет ссылки товара', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-name a' => 'color: {{VALUE}} !important;')));
        $this->add_control('name_hover_color', array('label' => 'Цвет ссылки при наведении', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-name a:hover' => 'color: {{VALUE}} !important;')));
        $this->add_responsive_control('cell_padding', array('label' => 'Отступы ячеек', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array('px', 'em'), 'selectors' => array('{{WRAPPER}} .anep-wct-table th, {{WRAPPER}} .anep-wct-table td' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;')));
        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_buttons',
            array(
                'label' => 'Кнопки',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            )
        );
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), array('name' => 'button_typography', 'selector' => '{{WRAPPER}} .anep-wct-btn, {{WRAPPER}} .button.anep-wct-btn'));
        $this->add_responsive_control('button_padding', array('label' => 'Отступы кнопок', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array('px', 'em'), 'selectors' => array('{{WRAPPER}} .anep-wct-btn, {{WRAPPER}} .button.anep-wct-btn' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;')));
        $this->add_responsive_control('button_radius', array('label' => 'Скругление кнопок', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array('px', '%'), 'selectors' => array('{{WRAPPER}} .anep-wct-btn, {{WRAPPER}} .button.anep-wct-btn' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;')));
        $this->add_control('price_button_color', array('label' => '«Узнать цену» — текст', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-btn-outline, {{WRAPPER}} .button.anep-wct-btn-outline' => 'color: {{VALUE}} !important;')));
        $this->add_control('price_button_bg', array('label' => '«Узнать цену» — фон', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-btn-outline, {{WRAPPER}} .button.anep-wct-btn-outline' => 'background-color: {{VALUE}} !important;')));
        $this->add_control('price_button_border', array('label' => '«Узнать цену» — рамка', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-btn-outline, {{WRAPPER}} .button.anep-wct-btn-outline' => 'border-color: {{VALUE}} !important;')));
        $this->add_control('buy_button_color', array('label' => '«Купить» — текст', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-btn-red, {{WRAPPER}} .button.anep-wct-btn-red' => 'color: {{VALUE}} !important;')));
        $this->add_control('buy_button_bg', array('label' => '«Купить» — фон', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-btn-red, {{WRAPPER}} .button.anep-wct-btn-red' => 'background-color: {{VALUE}} !important; border-color: {{VALUE}} !important;')));
        $this->add_control('buy_button_hover_bg', array('label' => '«Купить» — фон при наведении', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .anep-wct-btn-red:hover, {{WRAPPER}} .button.anep-wct-btn-red:hover' => 'background-color: {{VALUE}} !important; border-color: {{VALUE}} !important;')));
        $this->add_responsive_control('button_gap', array('label' => 'Расстояние между кнопками', 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array('px' => array('min' => 0, 'max' => 50)), 'selectors' => array('{{WRAPPER}} .anep-wct-actions' => 'gap: {{SIZE}}{{UNIT}};')));
        $this->end_controls_section();
    }

    private function anep_wct_color_var(&$pairs, $var, $settings, $key) {
        if (!empty($settings[$key]) && is_string($settings[$key])) {
            $pairs[$var] = $settings[$key];
        }
    }

    private function anep_wct_dimension_value($value, $fallback_unit = 'px') {
        if (!is_array($value)) {
            return '';
        }
        $unit = !empty($value['unit']) ? $value['unit'] : $fallback_unit;
        $top = isset($value['top']) && $value['top'] !== '' ? $value['top'] : null;
        $right = isset($value['right']) && $value['right'] !== '' ? $value['right'] : null;
        $bottom = isset($value['bottom']) && $value['bottom'] !== '' ? $value['bottom'] : null;
        $left = isset($value['left']) && $value['left'] !== '' ? $value['left'] : null;
        if ($top === null && $right === null && $bottom === null && $left === null) {
            return '';
        }
        $top = $top === null ? 0 : $top;
        $right = $right === null ? $top : $right;
        $bottom = $bottom === null ? $top : $bottom;
        $left = $left === null ? $right : $left;
        return $top . $unit . ' ' . $right . $unit . ' ' . $bottom . $unit . ' ' . $left . $unit;
    }

    private function anep_wct_slider_value($value, $fallback_unit = 'px') {
        if (!is_array($value) || !isset($value['size']) || $value['size'] === '') {
            return '';
        }
        $unit = !empty($value['unit']) ? $value['unit'] : $fallback_unit;
        return $value['size'] . $unit;
    }

    private function build_shell_style_from_elementor_settings($settings) {
        $mode = !empty($settings['archive_width_mode']) ? sanitize_key($settings['archive_width_mode']) : 'boxed';
        if (!in_array($mode, array('boxed', 'inherit', 'full'), true)) {
            $mode = 'boxed';
        }

        $classes = array('anep-wct-elementor-shell', 'awt-shell-' . $mode);
        $style = 'box-sizing:border-box!important;display:block!important;float:none!important;clear:both!important;position:relative!important;left:auto!important;right:auto!important;';

        if ($mode === 'boxed') {
            $max_width = $this->anep_wct_slider_value($settings['archive_max_width'] ?? array(), 'px');
            if ($max_width === '') {
                $max_width = '1200px';
            }
            // Жесткий inline-режим для Elementor Archive / WooCommerce Archive.
            // Некоторые темы ставят archive/container width:100vw или max-width:none!important.
            // Поэтому здесь width/max-width/margins прописываются с !important прямо в атрибут style.
            $style .= 'width:min(100%,' . esc_attr($max_width) . ')!important;max-width:' . esc_attr($max_width) . '!important;';
            $style .= 'margin-left:auto!important;margin-right:auto!important;';
            $style .= '--awt-shell-max-width:' . esc_attr($max_width) . ';';
        } elseif ($mode === 'full') {
            $style .= 'width:100%!important;max-width:none!important;';
        } else {
            $style .= 'width:100%!important;max-width:100%!important;';
        }

        $pad = $this->anep_wct_slider_value($settings['archive_side_padding'] ?? array(), 'px');
        if ($pad !== '') {
            $style .= 'padding-left:' . esc_attr($pad) . ';padding-right:' . esc_attr($pad) . ';';
            $style .= '--awt-shell-pad-x:' . esc_attr($pad) . ';--awt-shell-pad-x-mobile:' . esc_attr($pad) . ';';
        }

        return array($classes, $style);
    }

    private function build_inline_style_from_elementor_settings($settings) {
        $pairs = array();
        $this->anep_wct_color_var($pairs, '--awt-e-color', $settings, 'general_text_color');
        $this->anep_wct_color_var($pairs, '--awt-red', $settings, 'accent_color');
        $this->anep_wct_color_var($pairs, '--awt-e-bg', $settings, 'general_background');
        $this->anep_wct_color_var($pairs, '--awt-e-chip-color', $settings, 'chip_color');
        $this->anep_wct_color_var($pairs, '--awt-e-chip-bg', $settings, 'chip_bg');
        $this->anep_wct_color_var($pairs, '--awt-e-chip-hover-color', $settings, 'chip_hover_color');
        $this->anep_wct_color_var($pairs, '--awt-e-chip-hover-bg', $settings, 'chip_hover_bg');
        $this->anep_wct_color_var($pairs, '--awt-e-filter-bg', $settings, 'filter_wrap_bg');
        $this->anep_wct_color_var($pairs, '--awt-e-filter-title-color', $settings, 'filter_title_color');
        $this->anep_wct_color_var($pairs, '--awt-e-filter-color', $settings, 'select_color');
        $this->anep_wct_color_var($pairs, '--awt-e-filter-field-bg', $settings, 'select_bg');
        $this->anep_wct_color_var($pairs, '--awt-e-table-bg', $settings, 'table_bg');
        $this->anep_wct_color_var($pairs, '--awt-e-header-bg', $settings, 'header_bg');
        $this->anep_wct_color_var($pairs, '--awt-e-header-color', $settings, 'header_color');
        $this->anep_wct_color_var($pairs, '--awt-e-body-color', $settings, 'body_color');
        $this->anep_wct_color_var($pairs, '--awt-e-row-border-color', $settings, 'row_border_color');
        $this->anep_wct_color_var($pairs, '--awt-e-row-hover-bg', $settings, 'row_hover_bg');
        $this->anep_wct_color_var($pairs, '--awt-e-name-color', $settings, 'name_color');
        $this->anep_wct_color_var($pairs, '--awt-e-name-hover-color', $settings, 'name_hover_color');
        $this->anep_wct_color_var($pairs, '--awt-e-price-btn-color', $settings, 'price_button_color');
        $this->anep_wct_color_var($pairs, '--awt-e-price-btn-bg', $settings, 'price_button_bg');
        $this->anep_wct_color_var($pairs, '--awt-e-price-btn-border', $settings, 'price_button_border');
        $this->anep_wct_color_var($pairs, '--awt-e-buy-btn-color', $settings, 'buy_button_color');
        $this->anep_wct_color_var($pairs, '--awt-e-buy-btn-bg', $settings, 'buy_button_bg');
        $this->anep_wct_color_var($pairs, '--awt-e-buy-btn-hover-bg', $settings, 'buy_button_hover_bg');

        foreach (array(
            'general_margin' => '--awt-e-general-margin',
            'general_padding' => '--awt-e-general-padding',
            'chip_radius' => '--awt-e-chip-radius',
            'chip_padding' => '--awt-e-chip-padding',
            'filter_wrap_padding' => '--awt-e-filter-padding',
            'select_radius' => '--awt-e-filter-radius',
            'select_padding' => '--awt-e-filter-field-padding',
            'cell_padding' => '--awt-e-cell-padding',
            'button_padding' => '--awt-e-btn-padding',
            'button_radius' => '--awt-e-btn-radius',
        ) as $key => $var) {
            $value = $this->anep_wct_dimension_value($settings[$key] ?? array());
            if ($value !== '') {
                $pairs[$var] = $value;
            }
        }

        foreach (array(
            'chip_gap' => '--awt-e-chip-gap',
            'filter_grid_gap' => '--awt-e-filter-gap',
            'button_gap' => '--awt-e-btn-gap',
        ) as $key => $var) {
            $value = $this->anep_wct_slider_value($settings[$key] ?? array());
            if ($value !== '') {
                $pairs[$var] = $value;
            }
        }

        // Elementor border group controls are generated by Elementor CSS normally, but these variables
        // make the archive template respond even when generated CSS is cached/stale.
        foreach (array(
            'chip_border' => '--awt-e-chip-border',
            'select_border' => '--awt-e-filter-border',
            'table_border' => '--awt-e-table-border',
        ) as $key => $var) {
            $border_type = $settings[$key . '_border'] ?? '';
            if ($border_type && $border_type !== 'none') {
                $width = $this->anep_wct_dimension_value($settings[$key . '_width'] ?? array());
                $color = !empty($settings[$key . '_color']) ? $settings[$key . '_color'] : 'currentColor';
                if ($width !== '') {
                    $parts = explode(' ', $width);
                    $pairs[$var] = ($parts[0] ?? '1px') . ' ' . $border_type . ' ' . $color;
                }
            }
        }

        $style = '';
        foreach ($pairs as $key => $value) {
            if ($value === '') {
                continue;
            }
            $key = preg_replace('/[^a-zA-Z0-9\-_]/', '', (string) $key);
            $style .= $key . ':' . esc_attr((string) $value) . ';';
        }
        return $style;
    }

    private function build_archive_scope_css($scope_id, $settings, $shell_style, $content_style) {
        $scope = '#' . preg_replace('/[^a-zA-Z0-9\-_]/', '', (string) $scope_id);
        if ($scope === '#') {
            return '';
        }

        // Минимальный критический CSS печатается прямо рядом с виджетом. Это нужно именно для
        // шаблонов архива: некоторые темы выводят WooCommerce-архив так, что CSS Elementor или
        // CSS, подключенный во время рендера, не успевает примениться.
        $css = $scope . '{' . $shell_style . "}\n";
        $safe_widget_id = str_replace('anep-wct-widget-', '', (string) $scope_id);
        $safe_widget_id = preg_replace('/[^a-zA-Z0-9\-_]/', '', $safe_widget_id);
        $mode = !empty($settings['archive_width_mode']) ? sanitize_key($settings['archive_width_mode']) : 'boxed';
        if ($mode === 'boxed') {
            $max_width = $this->anep_wct_slider_value($settings['archive_max_width'] ?? array(), 'px');
            if ($max_width === '') { $max_width = '1200px'; }
            // Дублируем ограничение на саму Elementor-обертку виджета.
            // Это решает случаи, когда тема растягивает wrapper архива до 100vw.
            $elem_scope = '.elementor-element-' . $safe_widget_id . '.elementor-widget-anep_wct_table';
            $css .= $elem_scope . '{width:min(100%,' . esc_attr($max_width) . ')!important;max-width:' . esc_attr($max_width) . '!important;margin-left:auto!important;margin-right:auto!important;float:none!important;clear:both!important;} ' . "\n";
            $css .= $elem_scope . ' > .elementor-widget-container{width:100%!important;max-width:100%!important;margin-left:auto!important;margin-right:auto!important;} ' . "\n";
        }
        $css .= $scope . ' .anep-wct{width:100%!important;max-width:100%!important;' . $content_style . "}\n";
        $css .= $scope . ' .anep-wct-table-wrap{width:100%!important;max-width:100%!important;overflow-x:auto;-webkit-overflow-scrolling:touch;} ' . "\n";
        $css .= $scope . ' .anep-wct-filter-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:var(--awt-e-filter-gap,16px);align-items:start;} ' . "\n";
        $css .= $scope . ' .anep-wct-filter-toggle,' . $scope . ' .anep-wct-filter-select,' . $scope . ' .anep-wct-filter-search{width:100%;box-sizing:border-box;} ' . "\n";
        $css .= $scope . ' .anep-wct-table{width:100%!important;border-collapse:collapse;border-spacing:0;} ' . "\n";
        $css .= '@media(max-width:760px){' . $scope . ' .anep-wct-filter-form{grid-template-columns:1fr;}' . $scope . '.awt-shell-boxed{padding-left:var(--awt-shell-pad-x-mobile,12px);padding-right:var(--awt-shell-pad-x-mobile,12px);}}' . "\n";
        return $css;
    }

    protected function render() {
        if (!class_exists('WooCommerce')) {
            echo '<div class="anep-wct-empty">Для виджета нужен WooCommerce.</div>';
            return;
        }

        $settings = $this->get_settings_for_display();
        $category = isset($settings['category']) ? sanitize_title($settings['category']) : 'current';
        $term = null;

        if ($category === 'current') {
            if (function_exists('is_product_category') && is_product_category()) {
                $term = get_queried_object();
            } else {
                echo '<div class="anep-wct-empty">Виджет стоит вне категории. Выбери конкретную категорию в настройках виджета или поставь «Все товары».</div>';
                return;
            }
        } elseif ($category === 'all') {
            $term = null;
        } else {
            $term = get_term_by('slug', $category, 'product_cat');
            if (!$term || is_wp_error($term)) {
                echo '<div class="anep-wct-empty">Категория не найдена.</div>';
                return;
            }
        }

        $args = array(
            'per_page'            => !empty($settings['per_page']) ? absint($settings['per_page']) : null,
            'max_filters'         => !empty($settings['max_filters']) ? absint($settings['max_filters']) : null,
            'max_columns'         => !empty($settings['max_columns']) ? absint($settings['max_columns']) : null,
            'filter_attributes'   => !empty($settings['filter_attributes']) ? (array) $settings['filter_attributes'] : array(),
            'column_attributes'   => !empty($settings['column_attributes']) ? (array) $settings['column_attributes'] : array(),
            'show_chips'          => !empty($settings['show_chips']) && $settings['show_chips'] === 'yes',
            'show_filters'        => !empty($settings['show_filters']) && $settings['show_filters'] === 'yes',
            'show_pagination'     => !empty($settings['show_pagination']) && $settings['show_pagination'] === 'yes',
            'show_cart_summary'   => !empty($settings['show_cart_summary']) && $settings['show_cart_summary'] === 'yes',
            'hide_empty_columns'   => !empty($settings['hide_empty_columns']) && $settings['hide_empty_columns'] === 'yes',
            'mobile_compact'        => !empty($settings['mobile_compact']) && $settings['mobile_compact'] === 'yes',
            'show_name_column'    => !empty($settings['show_name_column']) && $settings['show_name_column'] === 'yes',
            'show_sku_column'     => !empty($settings['show_sku_column']) && $settings['show_sku_column'] === 'yes',
            'show_price_column'   => !empty($settings['show_price_column']) && $settings['show_price_column'] === 'yes',
            'show_actions_column' => !empty($settings['show_actions_column']) && $settings['show_actions_column'] === 'yes',
            'show_quantity'        => !empty($settings['show_quantity']) && $settings['show_quantity'] === 'yes',
            'style_source'         => !empty($settings['style_source']) && in_array(sanitize_key($settings['style_source']), array('elementor', 'theme', 'anep', 'custom'), true) ? sanitize_key($settings['style_source']) : 'elementor',
            'inline_style'         => $this->build_inline_style_from_elementor_settings($settings),
        );

        if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
            $args['per_page'] = min((int) ($args['per_page'] ?: 50), 50);
        }

        list($shell_classes, $shell_style) = $this->build_shell_style_from_elementor_settings($settings);
        $scope_id = 'anep-wct-widget-' . $this->get_id();
        echo '<style>' . wp_kses_post($this->build_archive_scope_css($scope_id, $settings, $shell_style, $args['inline_style'])) . '</style>';
        echo '<div id="' . esc_attr($scope_id) . '" class="' . esc_attr(implode(' ', $shell_classes)) . '" style="' . esc_attr($shell_style) . '">';
        ANEP_Woo_Category_Table::instance()->render_table($term, $args);
        echo '</div>';
    }
}
