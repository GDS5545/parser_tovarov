(function ($) {
    'use strict';

    function initAutoSubmit($root) {
        var $selects = $root.find('[data-pct-autosubmit]');
        if (!$selects.length) {
            return;
        }
        $selects.on('change', function () {
            this.form.submit();
        });
        // С рабочим JS отдельная кнопка "Показать" не нужна — фильтр применяется сразу.
        $root.find('.pct-filter-submit').hide();
    }

    function initChips($root) {
        $root.on('click', '[data-pct-chips-toggle]', function (e) {
            e.preventDefault();
            $(this).closest('[data-pct-chips]').addClass('is-expanded');
        });
    }

    function escapeHtml(text) {
        return $('<div>').text(text || '').html();
    }

    /**
     * Список товаров "на заказ" копится в localStorage браузера покупателя, а не в
     * WooCommerce-корзине и не на сервере — кнопка "Заказать" (data-pct-add) в таблице
     * просто добавляет/увеличивает позицию локально, без AJAX. На сервер уходит только
     * финальная отправка формы: готовый список id/qty + обязательные имя и телефон,
     * которые превращаются в лид Bitrix24 (см. class-pct-cart.php).
     */
    function initQuoteList($root) {
        var STORAGE_KEY = 'pct_quote_items';
        var $widget = $('#pct-cart-widget');
        if (!$widget.length) {
            return;
        }

        var $toggle = $('#pct-cart-toggle');
        var $drawer = $('#pct-cart-drawer');
        var $count = $('#pct-cart-count');
        var $items = $('#pct-cart-items');
        var $form = $('#pct-cart-order-form');
        var $itemsField = $form.find('input[name="items"]');
        var $status = $form.find('.pct-cart-status');

        function readItems() {
            try {
                var raw = window.localStorage.getItem(STORAGE_KEY);
                var parsed = raw ? JSON.parse(raw) : [];
                return Array.isArray(parsed) ? parsed : [];
            } catch (e) {
                return [];
            }
        }

        function writeItems(items) {
            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
            } catch (e) {
                // localStorage недоступен (приватный режим и т.п.) — список не переживёт
                // переход на другую страницу, но текущая сессия продолжит работать.
            }
        }

        function render() {
            var items = readItems();
            $count.text(items.length).prop('hidden', items.length < 1);
            $itemsField.val(JSON.stringify(items.map(function (item) {
                return { id: item.id, qty: item.qty };
            })));

            if (!items.length) {
                $items.html('<p class="pct-cart-empty">Список пуст.</p>');
                return;
            }

            var html = '';
            items.forEach(function (item) {
                html += '<div class="pct-cart-item" data-id="' + item.id + '">'
                    + '<div class="pct-cart-item-name">' + escapeHtml(item.name) + '</div>'
                    + '<div class="pct-cart-item-row">'
                    + '<input type="number" class="pct-cart-item-qty" min="1" step="1" value="' + item.qty + '" data-id="' + item.id + '" aria-label="Количество">'
                    + '<button type="button" class="pct-cart-item-remove" data-id="' + item.id + '" aria-label="Удалить">&times;</button>'
                    + '</div></div>';
            });
            $items.html(html);
        }

        function addItem(id, name, qty) {
            id = parseInt(id, 10);
            qty = parseInt(qty, 10) || 1;
            if (!id) {
                return;
            }
            var items = readItems();
            var existing = null;
            for (var i = 0; i < items.length; i++) {
                if (items[i].id === id) {
                    existing = items[i];
                    break;
                }
            }
            if (existing) {
                existing.qty += qty;
            } else {
                items.push({ id: id, name: name, qty: qty });
            }
            writeItems(items);
            render();
        }

        function updateQty(id, qty) {
            var items = readItems().reduce(function (acc, item) {
                if (item.id === id) {
                    if (qty > 0) {
                        item.qty = qty;
                        acc.push(item);
                    }
                } else {
                    acc.push(item);
                }
                return acc;
            }, []);
            writeItems(items);
            render();
        }

        function removeItem(id) {
            var items = readItems().filter(function (item) {
                return item.id !== id;
            });
            writeItems(items);
            render();
        }

        function openDrawer() {
            render();
            $drawer.prop('hidden', false);
        }

        function closeDrawer() {
            $drawer.prop('hidden', true);
        }

        $toggle.on('click', function () {
            if ($drawer.prop('hidden')) {
                openDrawer();
            } else {
                closeDrawer();
            }
        });

        $widget.on('click', '[data-pct-cart-close]', closeDrawer);

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && !$drawer.prop('hidden')) {
                closeDrawer();
            }
        });

        $root.on('click', '[data-pct-add]', function () {
            var $btn = $(this);
            var $qtyInput = $btn.closest('.pct-action').find('.pct-qty');
            var qty = $qtyInput.length ? parseInt($qtyInput.val(), 10) : 1;
            addItem($btn.data('product-id'), $btn.data('product-name'), qty || 1);

            var original = $btn.text();
            $btn.text('Добавлено').addClass('is-added');
            window.setTimeout(function () {
                $btn.text(original).removeClass('is-added');
            }, 1200);
        });

        var qtyTimer = null;
        $items.on('input', '.pct-cart-item-qty', function () {
            var $input = $(this);
            var id = parseInt($input.data('id'), 10);
            window.clearTimeout(qtyTimer);
            qtyTimer = window.setTimeout(function () {
                var qty = parseInt($input.val(), 10) || 0;
                updateQty(id, qty);
            }, 400);
        });

        $items.on('click', '.pct-cart-item-remove', function () {
            removeItem(parseInt($(this).data('id'), 10));
        });

        $form.on('submit', function (e) {
            e.preventDefault();
            var items = readItems();
            if (!items.length) {
                $status.text('Список пуст, добавьте хотя бы один товар.').addClass('is-error');
                return;
            }

            var $submit = $form.find('.pct-cart-submit');
            $submit.prop('disabled', true);
            $status.text('Отправляем…').removeClass('is-error is-success');

            $.post(PCT.ajax_url, $form.serialize())
                .done(function (response) {
                    if (response && response.success) {
                        $status.text((response.data && response.data.message) || 'Заявка отправлена.').addClass('is-success');
                        writeItems([]);
                        render();
                        $form.trigger('reset');
                        window.setTimeout(closeDrawer, 2000);
                    } else {
                        $status.text((response && response.data && response.data.message) || 'Не удалось отправить заявку.').addClass('is-error');
                    }
                })
                .fail(function () {
                    $status.text('Не удалось отправить заявку. Попробуйте ещё раз.').addClass('is-error');
                })
                .always(function () {
                    $submit.prop('disabled', false);
                });
        });

        render();
    }

    $(function () {
        var $root = $(document);
        initAutoSubmit($root);
        initChips($root);
        initQuoteList($root);
    });
})(jQuery);
