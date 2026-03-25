(function () {
    var popupId = 'ngm-preview';
    var scriptFlag = '__ngmPopupLoaded';

    function ensurePopup() {
        var popup = document.getElementById(popupId);
        if (popup) {
            return popup;
        }

        popup = document.createElement('div');
        popup.id = popupId;
        popup.style.cssText = [
            'position:fixed',
            'z-index:99999',
            'width:720px',
            'height:360px',
            'background:#08111c',
            'border:1px solid rgba(99,126,156,.35)',
            'border-radius:16px',
            'box-shadow:0 18px 40px rgba(0,0,0,.35)',
            'overflow:hidden',
            'pointer-events:none'
        ].join(';');

        var iframe = document.createElement('iframe');
        iframe.style.cssText = 'width:100%;height:100%;border:0;display:block;background:#08111c;pointer-events:none;';
        popup.appendChild(iframe);
        document.body.appendChild(popup);

        return popup;
    }

    function frameAdjustedPosition(anchor, event) {
        var x = event.clientX;
        var y = event.clientY;

        try {
            var win = anchor && anchor.ownerDocument && anchor.ownerDocument.defaultView ? anchor.ownerDocument.defaultView : null;
            var frame = win && win.frameElement ? win.frameElement : null;
            if (frame && typeof frame.getBoundingClientRect === 'function') {
                var rect = frame.getBoundingClientRect();
                x += rect.left;
                y += rect.top;
            }
        } catch (error) {
        }

        return { x: x, y: y };
    }

    function placePopup(popup, anchor, event) {
        var pos = frameAdjustedPosition(anchor, event);
        var width = 720;
        var height = 360;
        var x = Math.min(window.innerWidth - width - 20, pos.x + 18);
        var y = Math.min(window.innerHeight - height - 20, pos.y + 18);

        popup.style.left = Math.max(8, x) + 'px';
        popup.style.top = Math.max(8, y) + 'px';
    }

    window.showModernGraphPopup = function (anchor, event) {
        var popup = ensurePopup();
        var iframe = popup.querySelector('iframe');
        var url = anchor.getAttribute('rel') || '';

        if (iframe && iframe.getAttribute('src') !== url) {
            iframe.setAttribute('src', url);
        }

        popup.style.display = 'block';
        if (event) {
            placePopup(popup, anchor, event);
        }
    };

    window.moveModernGraphPopup = function (event, anchor) {
        var popup = document.getElementById(popupId);
        if (!popup || popup.style.display === 'none' || !event) {
            return;
        }

        placePopup(popup, anchor, event);
    };

    window.hideModernGraphPopup = function () {
        var popup = document.getElementById(popupId);
        if (popup) {
            popup.style.display = 'none';
        }
    };

    window.loadModernGraphPopupScript = function (callback) {
        if (window[scriptFlag]) {
            if (typeof callback === 'function') {
                callback();
            }
            return;
        }

        window[scriptFlag] = true;
        if (typeof callback === 'function') {
            callback();
        }
    };

    window[scriptFlag] = true;
}());
