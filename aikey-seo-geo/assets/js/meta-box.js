(function () {
    'use strict';
    function countChars(value) { return Array.from(value || '').length; }
    function init(box) {
        var title = box.querySelector('[data-aikey-title]');
        var description = box.querySelector('[data-aikey-description]');
        var titleCount = box.querySelector('[data-aikey-title-count]');
        var descriptionCount = box.querySelector('[data-aikey-description-count]');
        var previewTitle = box.querySelector('[data-aikey-preview-title]');
        var previewDescription = box.querySelector('[data-aikey-preview-description]');
        function update() {
            var titleValue = title ? title.value.trim() : '';
            var descriptionValue = description ? description.value.trim() : '';
            if (titleCount) { titleCount.textContent = String(countChars(title ? title.value : '')); }
            if (descriptionCount) { descriptionCount.textContent = String(countChars(description ? description.value : '')); }
            if (previewTitle) { previewTitle.textContent = titleValue || previewTitle.getAttribute('data-fallback') || ''; }
            if (previewDescription) { previewDescription.textContent = descriptionValue || previewDescription.getAttribute('data-fallback') || ''; }
        }
        if (title) { title.addEventListener('input', update); }
        if (description) { description.addEventListener('input', update); }
        update();

        box.querySelectorAll('[data-tab]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var id = tab.getAttribute('data-tab');
                box.querySelectorAll('[data-tab]').forEach(function (item) { item.classList.toggle('is-active', item === tab); });
                box.querySelectorAll('[data-panel]').forEach(function (panel) { panel.classList.toggle('is-active', panel.getAttribute('data-panel') === id); });
            });
        });

        var button = box.querySelector('[data-aikey-ai-analyze]');
        var spinner = box.querySelector('[data-aikey-ai-spinner]');
        var result = box.querySelector('[data-aikey-ai-result]');
        if (button && window.AIKEY_SEO_GEO) {
            button.addEventListener('click', function () {
                button.disabled = true;
                if (spinner) { spinner.classList.add('is-active'); }
                if (result) { result.textContent = '正在分析，请稍候…'; }
                fetch(window.AIKEY_SEO_GEO.restUrl + 'ai/analyze/' + window.AIKEY_SEO_GEO.postId, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'X-WP-Nonce': window.AIKEY_SEO_GEO.nonce},
                    body: '{}'
                }).then(function (res) {
                    return res.json().then(function (data) { if (!res.ok) { throw new Error(data.message || '请求失败'); } return data; });
                }).then(function (data) {
                    if (result) {
                        result.textContent = '';
                        var pre = document.createElement('pre');
                        pre.textContent = JSON.stringify(data.analysis || data, null, 2);
                        result.appendChild(pre);
                    }
                }).catch(function (err) {
                    if (result) { result.textContent = '分析失败：' + err.message; }
                }).finally(function () {
                    button.disabled = false;
                    if (spinner) { spinner.classList.remove('is-active'); }
                });
            });
        }
    }
    document.addEventListener('DOMContentLoaded', function () { document.querySelectorAll('[data-aikey-meta-box]').forEach(init); });
}());
