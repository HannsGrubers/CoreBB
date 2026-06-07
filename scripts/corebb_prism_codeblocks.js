(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function decodeHtml(value) {
        var textarea = document.createElement('textarea');
        textarea.innerHTML = value;
        return textarea.value;
    }

    function codeHtmlToText(element) {
        var html = element.innerHTML || '';
        html = html.replace(/<br\s*\/?\s*>/gi, '\n');
        return decodeHtml(html);
    }

    function languageFor(element) {
        var className = element.className || '';
        var match = className.match(/(?:^|\s)language-([a-z0-9_+#.-]+)(?:\s|$)/i);
        if (!match) {
            return '';
        }
        var language = String(match[1] || '').toLowerCase();
        var aliases = {
            'htm': 'html',
            'xhtml': 'html',
            'xml': 'xml',
            'js': 'javascript',
            'javascript': 'javascript',
            'sh': 'bash',
            'shell': 'bash',
            'py': 'python',
            'c++': 'cpp',
            'cs': 'csharp',
            'c#': 'csharp',
            'txt': 'text',
            'plain': 'text',
            'plaintext': 'text'
        };
        return aliases[language] || language;
    }

    ready(function () {
        if (!window.Prism || !window.Prism.languages || !document.querySelectorAll) {
            return;
        }

        var blocks = document.querySelectorAll('code.bbcode-code-content');
        for (var i = 0; i < blocks.length; i += 1) {
            var block = blocks[i];
            if (block.getAttribute('data-corebb-prism') === 'done') {
                continue;
            }

            var language = languageFor(block);
            var source = codeHtmlToText(block);
            block.setAttribute('data-corebb-prism', 'done');

            if (language === '' || language === 'text' || !window.Prism.languages[language]) {
                block.textContent = source;
                continue;
            }

            try {
                block.innerHTML = window.Prism.highlight(source, window.Prism.languages[language], language);
            } catch (err) {
                block.textContent = source;
            }
        }
    });
}());
