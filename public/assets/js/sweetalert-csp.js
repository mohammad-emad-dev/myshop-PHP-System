(function () {
    'use strict';

    // SweetAlert2 11.10.0 creates one runtime <style> element. Give that
    // controlled element the existing per-response nonce before the library
    // is loaded, without weakening style-src or allowing inline attributes.
    var nonceMeta = document.querySelector('meta[name="csp-nonce"]');
    var nonce = nonceMeta ? nonceMeta.getAttribute('content') : '';

    if (!nonce || !window.Document || !Document.prototype.createElement) {
        return;
    }

    var originalCreateElement = Document.prototype.createElement;
    Document.prototype.createElement = function (tagName, options) {
        var element = originalCreateElement.call(this, tagName, options);

        if (String(tagName).toLowerCase() === 'style') {
            element.setAttribute('nonce', nonce);
        }

        return element;
    };
}());
