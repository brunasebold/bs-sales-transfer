define([], function () {
    'use strict';

    return function (config, form) {
        var progress = form.querySelector('.bs-transfer-progress');
        var bar = form.querySelector('.bs-transfer-progress-bar');
        var label = form.querySelector('.bs-transfer-progress-label');
        var result = form.querySelector('.bs-transfer-result');
        var button = form.querySelector('button[type="submit"]');
        var timer = null;
        var finished = false;

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (button.disabled) {
                return;
            }
            finished = false;
            button.disabled = true;
            result.hidden = true;
            result.textContent = '';
            showProgress(config.preparing, true);
            var token = newToken();
            var body = new FormData(form);
            body.append('progress_token', token);
            body.append('isAjax', '1');
            timer = window.setInterval(function () {
                poll(token);
            }, 700);
            poll(token);

            window.fetch(form.action, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            }).then(function (response) {
                return response.json().then(function (data) {
                    data.ok = response.ok && data.ok !== false;
                    return data;
                });
            }).then(function (data) {
                finish(data);
            }).catch(function () {
                finish({ok: false, message: config.failed, errors: [], warnings: [], failed: 1});
            });
        });

        function poll(token) {
            if (finished) {
                return;
            }
            var url = config.progressUrl + (config.progressUrl.indexOf('?') === -1 ? '?' : '&') +
                'token=' + encodeURIComponent(token);
            window.fetch(url, {
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            }).then(function (response) {
                return response.json();
            }).then(function (state) {
                if (finished || !state || state.status === 'waiting') {
                    return;
                }
                var processed = state.processed || 0;
                var total = state.total || 0;
                if (total > 0) {
                    bar.style.width = Math.min(100, Math.round((processed / total) * 100)) + '%';
                    bar.classList.remove('is-busy');
                    label.textContent = fill(config.running, [state.label || String(processed), processed, total]);
                    return;
                }
                showProgress(fill(config.counting, [state.label || String(processed), processed]), true);
            }).catch(function () {
                return null;
            });
        }

        function finish(data) {
            finished = true;
            window.clearInterval(timer);
            button.disabled = false;
            progress.hidden = true;
            bar.classList.remove('is-busy');
            result.hidden = false;
            result.className = 'bs-transfer-result ' + (data.ok && !data.failed ? 'is-success' : 'is-error');
            result.textContent = '';
            var title = document.createElement('strong');
            title.textContent = data.message || config.failed;
            result.appendChild(title);
            addLines(data.errors);
            addLines(data.warnings);
            if (data.ok) {
                var file = form.querySelector('input[type="file"]');
                if (file) {
                    file.value = '';
                }
            }
            result.scrollIntoView({block: 'nearest'});
        }

        function addLines(lines) {
            (lines || []).forEach(function (line) {
                var paragraph = document.createElement('p');
                paragraph.textContent = line;
                result.appendChild(paragraph);
            });
        }

        function showProgress(text, busy) {
            progress.hidden = false;
            label.textContent = text;
            if (busy) {
                bar.style.width = '';
                bar.classList.add('is-busy');
            }
        }

        function fill(template, values) {
            return String(template).replace(/%(\d+)/g, function (match, index) {
                var value = values[Number(index) - 1];
                return value === undefined || value === null ? '' : String(value);
            });
        }

        function newToken() {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID();
            }
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (symbol) {
                var random = window.Math.random() * 16 | 0;
                var digit = symbol === 'x' ? random : (random & 0x3 | 0x8);
                return digit.toString(16);
            });
        }
    };
});
