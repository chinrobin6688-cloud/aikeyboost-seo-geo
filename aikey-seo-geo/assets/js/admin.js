(function () {
    'use strict';

    function copyText(value) {
        if (!value) { return Promise.reject(new Error('empty')); }
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(value);
        }
        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            area.value = value;
            area.setAttribute('readonly', 'readonly');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            try {
                if (document.execCommand('copy')) { resolve(); } else { reject(new Error('copy')); }
            } catch (e) { reject(e); }
            document.body.removeChild(area);
        });
    }

    function flashButton(button, text) {
        var original = button.getAttribute('data-aikey-original-label') || button.textContent;
        button.setAttribute('data-aikey-original-label', original);
        button.textContent = text;
        button.classList.add('is-aikey-success');
        window.setTimeout(function () {
            button.textContent = original;
            button.classList.remove('is-aikey-success');
        }, 1600);
    }

    document.addEventListener('change', function (event) {
        var selectAll = event.target.closest('.aikey-select-all[data-target]');
        if (!selectAll) { return; }
        var selector = selectAll.getAttribute('data-target');
        if (!selector) { return; }
        document.querySelectorAll(selector).forEach(function (checkbox) {
            checkbox.checked = selectAll.checked;
        });
    });

    document.addEventListener('click', function (event) {
        var copyButton = event.target.closest('[data-aikey-copy]');
        if (copyButton) {
            event.preventDefault();
            var selector = copyButton.getAttribute('data-aikey-copy');
            var field = selector ? document.querySelector(selector) : null;
            if (!field) { return; }
            copyText(field.value || field.textContent || '').then(function () {
                flashButton(copyButton, '已复制');
            }).catch(function () {
                field.focus();
                if (typeof field.select === 'function') { field.select(); }
                flashButton(copyButton, '请手动复制');
            });
            return;
        }


        var copyFirstButton = event.target.closest('[data-aikey-copy-first]');
        if (copyFirstButton) {
            event.preventDefault();
            var firstSelector = copyFirstButton.getAttribute('data-aikey-copy-first');
            var firstField = firstSelector ? document.querySelector(firstSelector) : null;
            if (!firstField) { return; }
            var firstValue = String(firstField.value || firstField.textContent || '').split(/\r?\n/).filter(Boolean)[0] || '';
            if (!firstValue) { flashButton(copyFirstButton, '没有链接'); return; }
            copyText(firstValue).then(function () { flashButton(copyFirstButton, '已复制'); }).catch(function () { flashButton(copyFirstButton, '复制失败'); });
            return;
        }

        var generateButton = event.target.closest('[data-aikey-url-list-generate]');
        if (generateButton) {
            event.preventDefault();
            var listField = document.querySelector('#aikey-manual-url-list');
            var limitField = document.querySelector('#aikey-url-list-limit');
            var countField = document.querySelector('#aikey-url-list-count');
            var urls = Array.isArray(window.AIKEYManualSubmissionUrls) ? window.AIKEYManualSubmissionUrls : [];
            var limit = parseInt(limitField ? limitField.value : '100', 10);
            if (!Number.isFinite(limit)) { limit = 100; }
            limit = Math.max(1, Math.min(500, limit));
            if (limitField) { limitField.value = String(limit); }
            var selected = urls.slice(0, limit);
            if (listField) { listField.value = selected.join('\n'); }
            if (countField) { countField.textContent = String(selected.length); }
            flashButton(generateButton, selected.length ? '已生成' : '暂无链接');
            return;
        }

    });
})();
