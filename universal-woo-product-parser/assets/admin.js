/* global jQuery, UWP */
(function ($) {
    'use strict';

    var pollTimer = null;
    var busy = false;
    var ticking = false;

    function post(action, extra) {
        var data = $.extend({ action: action, nonce: UWP.nonce }, extra || {});
        return $.ajax({
            url: UWP.ajaxUrl,
            method: 'POST',
            data: data,
            dataType: 'json',
            timeout: 60000
        });
    }

    function formData() {
        var data = {};
        $('#uwp-form').find('input, select, textarea').each(function () {
            var $field = $(this);
            var name = $field.attr('name');
            if (!name) { return; }
            if ($field.is(':checkbox')) {
                if ($field.is(':checked')) { data[name] = 1; }
            } else {
                data[name] = $field.val();
            }
        });
        return data;
    }

    function say(text, kind) {
        var $line = $('<div/>').addClass('uwp-line uwp-line-' + (kind || 'info')).text(text);
        $('#uwp-output').prepend($line);
        $('#uwp-output').children().slice(30).remove();
    }

    function sayList(items) {
        if (!items || !items.length) { return; }
        items.forEach(function (item) { say(item, 'muted'); });
    }

    function renderStatus(status) {
        if (!status) { return; }

        $('#uwp-state')
            .text(status.state)
            .attr('data-running', status.running ? '1' : '0');

        var $failures = $('#uwp-failures');
        if (status.failures && status.failures.length) {
            $failures.html(
                '<b>Последние проблемные страницы:</b>' +
                status.failures.map(function (line) {
                    return '<div class="uwp-failure">' + $('<span/>').text(line).html() + '</div>';
                }).join('')
            ).show();
        } else {
            $failures.hide().empty();
        }

        $('#uwp-heartbeat').text(
            status.source
                ? 'Источник: ' + status.source + ' · последний проход: ' + status.heartbeat
                : 'Домен источника не задан'
        );

        $('#uwp-progress-bar').css('width', (status.progress || 0) + '%');

        var metrics = [
            ['Товаров импортировано', status.products_done],
            ['Товаров в очереди', status.products_queued],
            ['Страниц обойдено', status.pages_done],
            ['Страниц в очереди', status.pages_pending],
            ['Пропущено', status.skipped],
            ['Ошибок', status.failed]
        ];

        var html = metrics.map(function (row) {
            return '<div class="uwp-metric"><span class="uwp-metric-value">' + row[1] + '</span>' +
                '<span class="uwp-metric-label">' + row[0] + '</span></div>';
        }).join('');

        $('#uwp-metrics').html(html);
    }

    function renderLogs(logs) {
        if (!logs || !logs.length) { return; }
        var html = logs.map(function (entry) {
            return '<div class="uwp-log-line uwp-log-' + entry.level + '">' +
                '<span class="uwp-log-time">' + entry.time + '</span> ' +
                $('<span/>').text(entry.message).html() +
                '</div>';
        }).join('');
        $('#uwp-log').html(html);
    }

    function refresh() {
        if (busy) { return; }
        busy = true;

        post('uwp_status')
            .done(function (response) {
                if (!response || !response.success) { schedule(15000); return; }

                var status = response.data.status;
                renderStatus(status);
                renderLogs(response.data.logs);

                // Пока вкладка открыта, очередь двигает браузер. Это страховка
                // для хостингов, где не работают ни WP-Cron, ни обращение сайта
                // к самому себе — иначе фон там просто не стартует.
                if (status && status.running && status.pending > 0) { runTick(); return; }

                schedule(status && status.running ? 5000 : 12000);
            })
            .fail(function () {
                schedule(15000);
            })
            .always(function () { busy = false; });
    }

    function runTick() {
        if (ticking) { return; }
        ticking = true;

        post('uwp_run_tick')
            .done(function (response) {
                if (response && response.success) {
                    renderStatus(response.data.status);

                    var summary = response.data.summary;
                    if (summary && summary.messages && summary.messages.length) {
                        summary.messages.slice(0, 5).forEach(function (line) { say(line, 'muted'); });
                    }
                }
                schedule(400);
            })
            .fail(function (xhr) {
                say('Проход прерван: ' + failMessage(xhr), 'bad');
                schedule(10000);
            })
            .always(function () { ticking = false; });
    }

    function schedule(delay) {
        if (pollTimer) { clearTimeout(pollTimer); }
        pollTimer = setTimeout(refresh, delay);
    }

    function failMessage(xhr) {
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            return xhr.responseJSON.data.message;
        }
        if (xhr && xhr.status === 0) { return 'Нет связи с сайтом. Проверьте соединение.'; }
        return 'Ошибка запроса' + (xhr && xhr.status ? ' (HTTP ' + xhr.status + ')' : '');
    }

    $(function () {
        $('#uwp-start').on('click', function () {
            var $button = $(this);
            $button.prop('disabled', true).text('Запускаю…');

            var payload = formData();
            payload.fresh = $('#uwp-fresh').is(':checked') ? 1 : 0;

            post('uwp_start', payload)
                .done(function (response) {
                    if (response && response.success) {
                        say(response.data.message, 'ok');
                        sayList(response.data.details);
                        renderStatus(response.data.status);
                        schedule(2000);
                    } else {
                        say(response && response.data ? response.data.message : 'Не удалось запустить', 'bad');
                        sayList(response && response.data ? response.data.details : null);
                    }
                })
                .fail(function (xhr) { say(failMessage(xhr), 'bad'); })
                .always(function () { $button.prop('disabled', false).text('Запустить'); });
        });

        $('#uwp-stop').on('click', function () {
            post('uwp_stop')
                .done(function (response) {
                    say(response.data.message, 'ok');
                    renderStatus(response.data.status);
                })
                .fail(function (xhr) { say(failMessage(xhr), 'bad'); });
        });

        $('#uwp-clear').on('click', function () {
            if (!window.confirm('Очистить очередь и журнал? Импортированные товары останутся на месте.')) { return; }
            post('uwp_clear')
                .done(function (response) {
                    say(response.data.message, 'ok');
                    renderStatus(response.data.status);
                    $('#uwp-log').html('Пока пусто');
                })
                .fail(function (xhr) { say(failMessage(xhr), 'bad'); });
        });

        $('#uwp-inspect').on('click', function () {
            var $button = $(this);
            $button.prop('disabled', true).text('Проверяю…');
            $('#uwp-report').text('Загружаю страницу…');

            var payload = formData();
            payload.url = $('#uwp-inspect-url').val();

            post('uwp_inspect', payload)
                .done(function (response) {
                    if (response && response.success) { $('#uwp-report').text(response.data.report); }
                    else { $('#uwp-report').text(response && response.data ? response.data.message : 'Не удалось проверить страницу'); }
                })
                .fail(function (xhr) { $('#uwp-report').text(failMessage(xhr)); })
                .always(function () { $button.prop('disabled', false).text('Проверить страницу'); });
        });

        $('#uwp-form').on('submit', function (event) { event.preventDefault(); });

        refresh();
    });
}(jQuery));
