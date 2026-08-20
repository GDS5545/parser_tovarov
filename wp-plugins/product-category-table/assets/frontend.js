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

    $(function () {
        var $root = $(document);
        initAutoSubmit($root);
        initChips($root);
        initQuantitySync($root);
        initRequestModal($root);
    });
})(jQuery);
