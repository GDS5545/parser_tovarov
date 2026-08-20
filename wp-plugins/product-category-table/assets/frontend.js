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

    function initQuantitySync($root) {
        $root.on('input change', '.pct-qty', function () {
            var $input = $(this);
            var value = parseInt($input.val(), 10);
            if (!value || value < 1) {
                value = 1;
                $input.val(1);
            }
            $input.closest('.pct-action').find('.pct-btn-buy').attr('data-quantity', value);
        });
    }

    function initRequestModal($root) {
        var $modal = $('#pct-request-modal');
        if (!$modal.length) {
            return;
        }
        var $form = $modal.find('#pct-request-form');
        var $status = $modal.find('.pct-modal-status');
        var $productField = $form.find('input[name="product_id"]');
        var $productLabel = $modal.find('.pct-modal-product');

        function openModal(productId, productName) {
            $productField.val(productId);
            $productLabel.text(productName || '');
            $status.text('').removeClass('is-error is-success');
            $modal.prop('hidden', false);
            $modal.find('input[name="name"]').trigger('focus');
        }

        function closeModal() {
            $modal.prop('hidden', true);
        }

        $root.on('click', '[data-pct-request]', function () {
            openModal($(this).data('product-id'), $(this).data('product-name'));
        });

        $modal.on('click', '[data-pct-modal-close]', closeModal);

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && !$modal.prop('hidden')) {
                closeModal();
            }
        });

        $form.on('submit', function (e) {
            e.preventDefault();
            var $submit = $form.find('.pct-modal-submit');
            $submit.prop('disabled', true);
            $status.text('Отправляем…').removeClass('is-error is-success');

            $.post(PCT.ajax_url, $form.serialize())
                .done(function (response) {
                    if (response && response.success) {
                        $status.text((response.data && response.data.message) || 'Заявка отправлена.').addClass('is-success');
                        $form.trigger('reset');
                        $productField.val($productField.val());
                        window.setTimeout(closeModal, 1500);
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
    }

    function initFloatingCart($root) {
        var $widget = $('#pct-cart-widget');
        if (!$widget.length) {
            return;
        }

        var $toggle = $('#pct-cart-toggle');
        var $drawer = $('#pct-cart-drawer');
        var $count = $('#pct-cart-count');
        var $items = $('#pct-cart-items');
        var $total = $('#pct-cart-total');
        var $form = $('#pct-cart-order-form');
        var $status = $form.find('.pct-cart-status');

        function applySummary(data) {
            if (!data) {
                return;
            }
            var count = data.count || 0;
            $count.text(count).prop('hidden', count < 1);
            if (typeof data.total === 'string') {
                $total.text(data.total !== '' ? data.total : '—');
            }
            if (typeof data.items_html === 'string') {
                $items.html(data.items_html);
            }
        }

        function fetchCart() {
            return $.post(PCT.ajax_url, {
                action: 'pct_get_cart',
                nonce: PCT.cart_nonce
            }).done(function (response) {
                if (response && response.success) {
                    applySummary(response.data);
                }
            });
        }

        function openDrawer() {
            $drawer.prop('hidden', false);
            fetchCart();
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

        // "Заказать" в таблице — стандартный ajax_add_to_cart WooCommerce
        // (wc-add-to-cart.js). Он сам шлёт запрос и вешает событие ниже на body.
        $(document.body).on('added_to_cart', function () {
            fetchCart();
        });

        var qtyTimer = null;
        $items.on('input', '.pct-cart-item-qty', function () {
            var $input = $(this);
            window.clearTimeout(qtyTimer);
            qtyTimer = window.setTimeout(function () {
                var qty = parseInt($input.val(), 10);
                $.post(PCT.ajax_url, {
                    action: 'pct_update_cart_item',
                    nonce: PCT.cart_nonce,
                    key: $input.data('key'),
                    quantity: qty
                }).done(function (response) {
                    if (response && response.success) {
                        applySummary(response.data);
                    }
                });
            }, 400);
        });

        $items.on('click', '.pct-cart-item-remove', function () {
            var key = $(this).data('key');
            $.post(PCT.ajax_url, {
                action: 'pct_remove_cart_item',
                nonce: PCT.cart_nonce,
                key: key
            }).done(function (response) {
                if (response && response.success) {
                    applySummary(response.data);
                }
            });
        });

        $form.on('submit', function (e) {
            e.preventDefault();
            var $submit = $form.find('.pct-cart-submit');
            $submit.prop('disabled', true);
            $status.text('Оформляем…').removeClass('is-error is-success');

            $.post(PCT.ajax_url, $form.serialize())
                .done(function (response) {
                    if (response && response.success) {
                        $status.text((response.data && response.data.message) || 'Заказ оформлен.').addClass('is-success');
                        applySummary(response.data);
                        $form.trigger('reset');
                        window.setTimeout(closeDrawer, 2000);
                    } else {
                        $status.text((response && response.data && response.data.message) || 'Не удалось оформить заказ.').addClass('is-error');
                    }
                })
                .fail(function () {
                    $status.text('Не удалось оформить заказ. Попробуйте ещё раз.').addClass('is-error');
                })
                .always(function () {
                    $submit.prop('disabled', false);
                });
        });

        // Показать актуальное количество сразу при загрузке любой страницы сайта.
        fetchCart();
    }

    $(function () {
        var $root = $(document);
        initAutoSubmit($root);
        initChips($root);
        initQuantitySync($root);
        initRequestModal($root);
        initFloatingCart($root);
    });
})(jQuery);
