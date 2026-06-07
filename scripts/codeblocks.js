(function () {
    function findCodeBlock(el) {
        while (el && el !== document) {
            if (el.className && (' ' + el.className + ' ').indexOf(' bbcode-code-block ') !== -1) {
                return el;
            }
            el = el.parentNode;
        }
        return null;
    }

    function decodeCodeText(block) {
        var b64 = block.getAttribute('data-code-b64') || '';
        if (!b64 || typeof atob !== 'function') {
            var code = block.querySelector ? block.querySelector('.bbcode-code-content') : null;
            return code ? code.textContent : '';
        }

        try {
            var binary = atob(b64);
            if (window.TextDecoder && window.Uint8Array) {
                var bytes = new Uint8Array(binary.length);
                for (var i = 0; i < binary.length; i++) {
                    bytes[i] = binary.charCodeAt(i);
                }
                return new TextDecoder('utf-8').decode(bytes);
            }

            try {
                return decodeURIComponent(escape(binary));
            } catch (ignore) {
                return binary;
            }
        } catch (e) {
            var fallback = block.querySelector ? block.querySelector('.bbcode-code-content') : null;
            return fallback ? fallback.textContent : '';
        }
    }

    function copyText(text, button) {
        function markCopied() {
            if (!button) {
                return;
            }
            var old = button.value || button.innerHTML;
            if (button.value !== undefined) {
                button.value = 'Copied';
            } else {
                button.innerHTML = 'Copied';
            }
            window.setTimeout(function () {
                if (button.value !== undefined) {
                    button.value = old;
                } else {
                    button.innerHTML = old;
                }
            }, 1200);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(markCopied, function () {
                fallbackCopy(text);
                markCopied();
            });
            return;
        }

        fallbackCopy(text);
        markCopied();
    }

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', 'readonly');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
        } catch (ignore) {}
        document.body.removeChild(ta);
    }

    function selectCode(block) {
        var code = block.querySelector ? block.querySelector('.bbcode-code-content') : null;
        if (!code || !document.createRange || !window.getSelection) {
            return;
        }
        var range = document.createRange();
        range.selectNodeContents(code);
        var selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    }

    function onClick(e) {
        e = e || window.event;
        var target = e.target || e.srcElement;
        if (!target || !target.getAttribute) {
            return;
        }
        var action = target.getAttribute('data-code-action');
        if (!action) {
            return;
        }
        var block = findCodeBlock(target);
        if (!block) {
            return;
        }
        if (e.preventDefault) {
            e.preventDefault();
        }
        e.returnValue = false;

        if (action === 'copy') {
            copyText(decodeCodeText(block), target);
        } else if (action === 'select') {
            selectCode(block);
        }
    }

    if (document.addEventListener) {
        document.addEventListener('click', onClick, false);
    } else if (document.attachEvent) {
        document.attachEvent('onclick', onClick);
    }
}());
