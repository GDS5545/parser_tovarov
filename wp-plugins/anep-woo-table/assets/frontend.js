(function($){
    function openModal(button){
        var $btn = $(button);
        var $modal = $('.anep-wct-modal').last();
        $modal.addClass('is-open').attr('aria-hidden','false');
        $modal.find('[name="product_id"]').val($btn.data('product-id') || '');
        $modal.find('[name="product_name"]').val($btn.data('product-name') || '');
        $modal.find('[name="request_type"]').val($btn.data('request-type') || 'Заявка');
        $modal.find('.anep-wct-modal-product').text(($btn.data('request-type') || 'Заявка') + ': ' + ($btn.data('product-name') || 'товар'));
        $modal.find('.anep-wct-form-result').removeClass('is-error is-success').text('');
    }
    function closeModal(){
        $('.anep-wct-modal').removeClass('is-open').attr('aria-hidden','true');
    }
    function getRowQuantity($btn){
        var $row = $btn.closest('tr');
        var qty = parseInt($row.find('.anep-wct-qty-input').val(), 10);
        if(!qty || qty < 1){ qty = parseInt($btn.data('quantity'), 10) || 1; }
        return Math.max(1, qty);
    }
    function updateCartSummary(resp){
        if(resp && resp.data && typeof resp.data.cart_count !== 'undefined'){
            $('[data-cart-count]').text(resp.data.cart_count);
        }
        if(resp && resp.data && resp.data.cart_url){
            $('.anep-wct-cart-link').attr('href', resp.data.cart_url);
        }
    }
    function showButtonMessage($btn, text, isError){
        var original = $btn.data('original-text') || $btn.text();
        $btn.data('original-text', original);
        $btn.toggleClass('is-error', !!isError).toggleClass('is-added', !isError).text(text);
        window.clearTimeout($btn.data('awtTimer'));
        var timer = window.setTimeout(function(){
            $btn.text(original).removeClass('is-error is-added');
        }, isError ? 3000 : 1800);
        $btn.data('awtTimer', timer);
    }
    function handleAddToCart(button){
        var $btn = $(button);
        if($btn.prop('disabled') || $btn.hasClass('is-loading')){ return false; }
        var original = $btn.data('original-text') || $btn.text();
        $btn.data('original-text', original);
        var quantity = getRowQuantity($btn);
        var silent = !!(window.ANEP_WCT && ANEP_WCT.suppress_cart_popup === 'yes');
        $btn.prop('disabled', true).addClass('is-loading').text('Добавляем...');
        $.post(ANEP_WCT.ajax_url, {
            action: 'anep_wct_add_to_cart',
            nonce: ANEP_WCT.nonce,
            product_id: $btn.data('product-id') || $btn.data('product_id') || '',
            quantity: quantity,
            suppress_popup: silent ? 'yes' : 'no'
        }).done(function(resp){
            if(resp && resp.success){
                updateCartSummary(resp);
                if(!silent){
                    if(resp.data && resp.data.fragments){
                        $.each(resp.data.fragments, function(key, value){ $(key).replaceWith(value); });
                    }
                    $(document.body).trigger('added_to_cart', [
                        (resp.data && resp.data.fragments) ? resp.data.fragments : {},
                        (resp.data && resp.data.cart_hash) ? resp.data.cart_hash : '',
                        $btn
                    ]);
                } else {
                    $(document.body).trigger('anep_wct_added_quietly', [resp, $btn]);
                }
                showButtonMessage($btn, 'В корзине', false);
            } else {
                var message = (resp && resp.data && resp.data.message) ? resp.data.message : 'Не удалось добавить товар в корзину.';
                showButtonMessage($btn, message, true);
                if(window.console && console.warn){ console.warn('ANEP add to cart:', message, resp); }
            }
        }).fail(function(xhr){
            var message = 'Ошибка добавления. Проверьте настройки WooCommerce и кеш.';
            showButtonMessage($btn, message, true);
            if(window.console && console.warn){ console.warn('ANEP add to cart AJAX failed:', xhr); }
        }).always(function(){
            $btn.prop('disabled', false).removeClass('is-loading');
        });
        return false;
    }

    function norm(v){ return String(v || '').toLowerCase().trim(); }
    function contains(arr, value){
        value = norm(value);
        if(!value){ return false; }
        arr = arr || [];
        for(var i=0;i<arr.length;i++){
            if(norm(arr[i]) === value){ return true; }
        }
        return false;
    }
    function filterWrap($el){ return $el.closest('.anep-wct-filter-wrap'); }
    function getMatrix($wrap){
        var cached = $wrap.data('awtMatrix');
        if(cached){ return cached; }
        var raw = $.trim($wrap.find('.anep-wct-filter-matrix').first().text() || '');
        var parsed = {rows:[]};
        if(raw){
            try { parsed = JSON.parse(raw); } catch(e){ parsed = {rows:[]}; }
        }
        if(!parsed || !$.isArray(parsed.rows)){ parsed = {rows:[]}; }
        $wrap.data('awtMatrix', parsed);
        return parsed;
    }
    function getState($wrap){
        var state = {};
        $wrap.find('[data-awt-filter-field]').each(function(){
            var $field = $(this);
            var key = String($field.data('filter-key') || '');
            var value = $field.find('.anep-wct-filter-hidden').val() || '';
            if(key && value){ state[key] = value; }
        });
        return state;
    }
    function rowMatches(row, state, excludeKey){
        state = state || {};
        for(var key in state){
            if(!Object.prototype.hasOwnProperty.call(state, key)){ continue; }
            if(excludeKey && key === excludeKey){ continue; }
            var selected = state[key];
            if(!selected){ continue; }
            if(!row[key] || !contains(row[key], selected)){ return false; }
        }
        return true;
    }
    function optionAvailable($wrap, key, value){
        value = norm(value);
        if(!value){ return true; }
        var matrix = getMatrix($wrap);
        var rows = matrix.rows || [];
        if(!rows.length){ return true; }
        var state = getState($wrap);
        for(var i=0;i<rows.length;i++){
            var row = rows[i] || {};
            if(rowMatches(row, state, key) && row[key] && contains(row[key], value)){
                return true;
            }
        }
        return false;
    }
    function updateToggleText($field){
        var label = String($field.data('filter-label') || '');
        var value = $field.find('.anep-wct-filter-hidden').val() || '';
        var name = '';
        if(value){
            var $selected = $field.find('.anep-wct-filter-option.is-selected').not('.anep-wct-filter-option-clear').first();
            name = String($selected.data('filter-name') || $.trim($selected.find('.anep-wct-filter-option-name').text()) || value);
        }
        $field.find('.anep-wct-filter-toggle-text').text(name ? (label + ': ' + name) : label);
    }
    function updateChips($wrap){
        var $box = $wrap.find('[data-awt-active-filters]').first();
        if(!$box.length){ return; }
        $box.empty();
        var has = false;
        $wrap.find('[data-awt-filter-field]').each(function(){
            var $field = $(this);
            var key = String($field.data('filter-key') || '');
            var label = String($field.data('filter-label') || '');
            var value = $field.find('.anep-wct-filter-hidden').val() || '';
            if(!key || !value){ return; }
            var $selected = $field.find('.anep-wct-filter-option.is-selected').not('.anep-wct-filter-option-clear').first();
            var name = String($selected.data('filter-name') || $.trim($selected.find('.anep-wct-filter-option-name').text()) || value);
            var $chip = $('<span/>', {'class':'anep-wct-active-filter', 'data-filter-chip':key});
            $('<span/>', {'class':'anep-wct-active-filter-label'}).text(label + ':').appendTo($chip);
            $chip.append(' ');
            var $btn = $('<button/>', {type:'button', 'class':'anep-wct-active-filter-value', 'data-awt-remove-filter':key});
            $('<span/>').text(name).appendTo($btn);
            $('<span/>', {'class':'anep-wct-active-filter-x', 'aria-hidden':'true'}).text('×').appendTo($btn);
            $btn.appendTo($chip);
            $chip.appendTo($box);
            has = true;
        });
        $box.prop('hidden', !has);
    }
    function updateOptions($wrap){
        $wrap.find('[data-awt-filter-field]').each(function(){
            var $field = $(this);
            var key = String($field.data('filter-key') || '');
            var current = norm($field.find('.anep-wct-filter-hidden').val() || '');
            $field.find('.anep-wct-filter-option').not('.anep-wct-filter-option-clear').each(function(){
                var $opt = $(this);
                var val = norm($opt.data('filter-value') || '');
                var available = optionAvailable($wrap, key, val);
                var keepCurrent = current && val === current;
                $opt.toggleClass('is-unavailable', !available && !keepCurrent);
                $opt.prop('hidden', !available && !keepCurrent);
            });
            updateToggleText($field);
        });
        updateChips($wrap);
    }
    function closeFilterPanels(except){
        $('.anep-wct-filter-field').each(function(){
            var field = this;
            if(except && field === except){ return; }
            $(field).removeClass('is-open').find('.anep-wct-filter-toggle').attr('aria-expanded','false');
            $(field).find('.anep-wct-filter-panel').prop('hidden', true);
        });
    }
    function openFilterField($field){
        closeFilterPanels($field[0]);
        $field.addClass('is-open');
        $field.find('.anep-wct-filter-toggle').attr('aria-expanded','true');
        $field.find('.anep-wct-filter-panel').prop('hidden', false);
        var $search = $field.find('.anep-wct-filter-search');
        $search.val('');
        $field.find('.anep-wct-filter-option').show();
        window.setTimeout(function(){ $search.trigger('focus'); }, 20);
    }
    function setFilterValue($field, value, name){
        value = String(value || '');
        $field.find('.anep-wct-filter-hidden').val(value);
        $field.find('.anep-wct-filter-option').removeClass('is-selected').find('.anep-wct-filter-check').text('');
        if(value){
            var $selected = $field.find('.anep-wct-filter-option').filter(function(){
                return norm($(this).data('filter-value') || '') === norm(value);
            }).first();
            if($selected.length){
                $selected.addClass('is-selected').find('.anep-wct-filter-check').text('✓');
            } else if(name){
                $field.find('.anep-wct-filter-toggle-text').text(String($field.data('filter-label') || '') + ': ' + name);
            }
        }
        var $wrap = filterWrap($field);
        $wrap.find('.anep-wct-filter-form').addClass('anep-wct-filter-changed');
        updateOptions($wrap);
    }
    function prepareFilterForm($form){
        $form.find('select').each(function(){
            var $el = $(this);
            $el.prop('disabled', !$el.val());
        });
        $form.find('.anep-wct-filter-hidden').each(function(){
            var $el = $(this);
            $el.prop('disabled', !$el.val());
        });
    }
    function submitFilterForm($wrap){
        var $form = $wrap.find('.anep-wct-filter-form').first();
        if(!$form.length || $form.data('awtSubmitting')){ return; }
        $form.data('awtSubmitting', true).addClass('anep-wct-filter-submitting is-loading');
        closeFilterPanels();
        prepareFilterForm($form);
        if($form[0] && typeof $form[0].submit === 'function'){
            $form[0].submit();
        } else {
            $form.trigger('submit');
        }
    }

    // Перехватываем клик в capture-фазе раньше скриптов темы, чтобы они не открывали mini-cart/side-cart.
    if(document && document.addEventListener){
        document.addEventListener('click', function(e){
            var target = e.target;
            if(!target || !target.closest){ return; }
            var btn = target.closest('.anep-wct-add-cart');
            if(!btn){ return; }
            e.preventDefault();
            e.stopPropagation();
            if(e.stopImmediatePropagation){ e.stopImmediatePropagation(); }
            handleAddToCart(btn);
            return false;
        }, true);
    }

    $(document).on('click','.anep-wct-open-modal',function(e){e.preventDefault();openModal(this);});
    $(document).on('click','.anep-wct-modal-close,.anep-wct-modal-overlay',function(e){e.preventDefault();closeModal();});
    $(document).on('keyup',function(e){if(e.key === 'Escape'){closeModal(); closeFilterPanels(); }});

    $(document).on('click', '.anep-wct-filter-toggle', function(e){
        e.preventDefault();
        var $field = $(this).closest('[data-awt-filter-field]');
        if($field.hasClass('is-open')){ closeFilterPanels(); }
        else { openFilterField($field); }
    });

    $(document).on('click', '.anep-wct-filter-option', function(e){
        e.preventDefault();
        var $opt = $(this);
        if($opt.hasClass('is-unavailable')){ return; }
        var $field = $opt.closest('[data-awt-filter-field]');
        var oldValue = $field.find('.anep-wct-filter-hidden').val() || '';
        var newValue = $opt.data('filter-value') || '';
        setFilterValue($field, newValue, $opt.data('filter-name') || '');
        closeFilterPanels();
        if(norm(oldValue) !== norm(newValue)){
            submitFilterForm(filterWrap($field));
        }
    });

    $(document).on('input', '.anep-wct-filter-search', function(){
        var q = norm($(this).val());
        var $field = $(this).closest('[data-awt-filter-field]');
        $field.find('.anep-wct-filter-option').each(function(){
            var $opt = $(this);
            if($opt.prop('hidden')){ return; }
            if($opt.hasClass('anep-wct-filter-option-clear')){ $opt.toggle(!q); return; }
            var text = norm($opt.text());
            $opt.toggle(!q || text.indexOf(q) !== -1);
        });
    });

    $(document).on('click', '[data-awt-remove-filter]', function(e){
        e.preventDefault();
        var key = $(this).data('awt-remove-filter');
        var $wrap = filterWrap($(this));
        var $field = $wrap.find('[data-awt-filter-field][data-filter-key="' + key + '"]').first();
        if($field.length){
            setFilterValue($field, '', '');
            submitFilterForm($wrap);
        } else if(this.href) {
            window.location.href = this.href;
        }
    });

    $(document).on('click', function(e){
        if($(e.target).closest('.anep-wct-filter-field').length){ return; }
        closeFilterPanels();
    });

    // Совместимость со старым select-режимом, если шаблон был переопределен в теме.
    $(document).on('change','.anep-wct-filter-select',function(){
        var $form = $(this).closest('form');
        $form.addClass('anep-wct-filter-changed');
        submitFilterForm(filterWrap($(this)));
    });

    $(document).on('submit','.anep-wct-filter-form',function(){
        prepareFilterForm($(this));
    });

    $(document).on('submit','.anep-wct-request-form',function(e){
        e.preventDefault();
        var $form = $(this), $result = $form.find('.anep-wct-form-result'), $submit = $form.find('.anep-wct-submit');
        $result.removeClass('is-error is-success').text('Отправляем...');
        $submit.prop('disabled', true);
        $.post(ANEP_WCT.ajax_url, $form.serialize())
            .done(function(resp){
                if(resp && resp.success){
                    $result.addClass('is-success').text(resp.data.message || 'Заявка отправлена.');
                    $form[0].reset();
                    setTimeout(closeModal, 1500);
                } else {
                    $result.addClass('is-error').text((resp && resp.data && resp.data.message) ? resp.data.message : 'Ошибка отправки.');
                }
            })
            .fail(function(){ $result.addClass('is-error').text('Ошибка соединения. Попробуйте позже.'); })
            .always(function(){ $submit.prop('disabled', false); });
    });

    $(function(){
        $('.anep-wct-filter-wrap').each(function(){ updateOptions($(this)); });
    });
})(jQuery);
