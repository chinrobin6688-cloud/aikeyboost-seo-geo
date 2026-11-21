/* Only loaded when [aikey_language_switcher] renders. */
(function () {
    'use strict';
    document.addEventListener('change', function (event) {
        var select = event.target;
        if (!select || !select.matches || !select.matches('select[data-aikey-language-switcher]')) return;
        var next = select.value;
        if (next && /^https?:\/\//i.test(next)) window.location.assign(next);
    });
}());