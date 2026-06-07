// CoreBB post toolbar
// Modern replacement for the old VNBoards-era selection code.
// Keeps the original public function names used by the PHP templates.

var pst_sdebugHTML = '';
var pst_oEditForm = null;
var pst_oEditBox = null;
var pst_sMode = 'basic';
var pst_text = '';

function pst_fnInit()
{
    if (!pst_oEditForm) {
        pst_oEditForm = document.forms.frmMain || document.getElementById('frmMain') || null;
    }

    if (!pst_oEditBox) {
        if (pst_oEditForm && pst_oEditForm.message_body) {
            pst_oEditBox = pst_oEditForm.message_body;
        } else {
            pst_oEditBox = document.getElementById('message_body') || document.querySelector('textarea[name="message_body"]');
        }
    }

    var oSelMode = document.getElementById('selFormatMode');
    if (oSelMode && oSelMode.options && oSelMode.selectedIndex >= 0) {
        pst_sMode = oSelMode.options[oSelMode.selectedIndex].value;
    }

    return !!pst_oEditBox;
}

function pst_fnShowDebug()
{
    var dbgOpt = 'height=350,width=300,scrollbars=yes';
    var dbgWin = window.open('about:blank', 'pst_fnShowDebug', dbgOpt);
    if (!dbgWin) {
        return;
    }
    dbgWin.document.write('<table><tr><td nowrap style="font-family:verdana;font-size:8pt;">');
    dbgWin.document.write(pst_sdebugHTML);
    dbgWin.document.write('</td></tr></table>');
    dbgWin.document.close();
    dbgWin.focus();
}

function pst_fnDebug(sMessage)
{
    if (pst_sdebugHTML.indexOf(sMessage) === -1) {
        pst_sdebugHTML += sMessage + '<br>';
    }
}

function pst_fnFocus()
{
    if (pst_fnInit()) {
        pst_oEditBox.focus();
    }
}

function pst_fnSaveCaret(oElement)
{
    // Modern textareas expose selectionStart/selectionEnd directly, so there is
    // nothing persistent to save. Keep this function for old inline handlers.
    if (oElement) {
        pst_oEditBox = oElement;
    }
}

function pst_fnGetSelection()
{
    return window.getSelection ? window.getSelection() : null;
}

function pst_fnGetSelectedText()
{
    pst_text = '';
    if (!pst_fnInit()) {
        return false;
    }

    var start = pst_oEditBox.selectionStart || 0;
    var end = pst_oEditBox.selectionEnd || 0;

    if (end > start) {
        pst_text = pst_oEditBox.value.substring(start, end);
        return true;
    }

    return false;
}

function pst_fnGetCaretPos(elem)
{
    if (!elem || typeof elem.selectionStart === 'undefined') {
        return 0;
    }
    return elem.selectionStart;
}

function pst_fnSplitPrompts(txtPrompt)
{
    if (!txtPrompt) {
        return [];
    }
    return String(txtPrompt).split('_');
}

function pst_fnSplitDefaults(txtDefault)
{
    if (!txtDefault) {
        return [];
    }
    return String(txtDefault).split('_');
}

function pst_fnTokenCount(txtCode)
{
    var match = String(txtCode).match(/\$\d+/g);
    if (!match) {
        return 0;
    }

    var maxToken = 0;
    for (var i = 0; i < match.length; i++) {
        var n = parseInt(match[i].substring(1), 10);
        if (n > maxToken) {
            maxToken = n;
        }
    }
    return maxToken;
}

function pst_fnApplyTemplate(txtCode, values)
{
    return String(txtCode).replace(/\$(\d+)/g, function(full, tokenNumber) {
        var index = parseInt(tokenNumber, 10) - 1;
        return typeof values[index] !== 'undefined' ? values[index] : '';
    });
}

function pst_fnFindCaretOffset(insertedText, selectedText)
{
    var selected = selectedText || '';
    var closeIndex = insertedText.indexOf('[/');
    var openClose = insertedText.indexOf(']');

    if (selected.length > 0) {
        return insertedText.length;
    }

    if (closeIndex > -1) {
        return closeIndex;
    }

    if (openClose > -1 && insertedText.indexOf('=') > -1) {
        return openClose + 1;
    }

    return insertedText.length;
}

function pst_fnInsertAtSelection(insertedText, caretOffset)
{
    if (!pst_fnInit()) {
        return false;
    }

    pst_oEditBox.focus();

    var value = pst_oEditBox.value;
    var start = pst_oEditBox.selectionStart || 0;
    var end = pst_oEditBox.selectionEnd || 0;

    pst_oEditBox.value = value.substring(0, start) + insertedText + value.substring(end);

    var nextPos = start + (typeof caretOffset === 'number' ? caretOffset : insertedText.length);
    pst_oEditBox.setSelectionRange(nextPos, nextPos);
    pst_oEditBox.focus();
    pst_text = '';
    return true;
}

function pst_fnInsertCode(txtCode)
{
    var selected = '';
    if (pst_fnInit()) {
        selected = pst_oEditBox.value.substring(pst_oEditBox.selectionStart || 0, pst_oEditBox.selectionEnd || 0);
    }
    return pst_fnInsertAtSelection(txtCode, pst_fnFindCaretOffset(txtCode, selected));
}

function pst_fnInsertCodeIE(txtCode)
{
    return pst_fnInsertCode(txtCode);
}

function pst_fnInsertCodeMoz(txtCode)
{
    return pst_fnInsertCode(txtCode);
}

function pst_fnDoMarkup(txtCode, txtPrompt, txtDefault)
{
    if (!pst_fnInit()) {
        return false;
    }

    var start = pst_oEditBox.selectionStart || 0;
    var end = pst_oEditBox.selectionEnd || 0;
    var selectedText = pst_oEditBox.value.substring(start, end);
    pst_text = selectedText;

    var tokenCount = pst_fnTokenCount(txtCode);
    var prompts = pst_fnSplitPrompts(txtPrompt);
    var defaults = pst_fnSplitDefaults(txtDefault);
    var values = [];

    for (var i = 0; i < tokenCount; i++) {
        values[i] = selectedText;
        if (typeof defaults[i] !== 'undefined' && defaults[i] !== '') {
            values[i] = defaults[i];
        }
    }

    if (txtPrompt) {
        var mode = pst_sMode;
        if (mode !== 'prompt' && String(txtPrompt).substring(0, 1) === '*') {
            mode = 'promptreq';
        }

        if (mode === 'prompt' || mode === 'promptreq') {
            for (var p = 0; p < prompts.length; p++) {
                var promptText = prompts[p];
                var forcePrompt = promptText.substring(0, 1) === '*';
                var doPrompt = mode === 'prompt' || (mode === 'promptreq' && forcePrompt);

                if (!doPrompt) {
                    continue;
                }

                var currentDefault = typeof values[p] !== 'undefined' ? values[p] : '';
                var response = window.prompt(promptText.replace(/^\*/, ''), currentDefault);

                if (response === null) {
                    return false;
                }

                values[p] = response;
            }
        }
    }

    var finalCode = pst_fnApplyTemplate(txtCode, values);
    var caretOffset = pst_fnFindCaretOffset(finalCode, selectedText);
    return pst_fnInsertAtSelection(finalCode, caretOffset);
}

function fnMarkupList(oSelect)
{
    pst_fnInit();
    var i = oSelect.selectedIndex;
    if (i > 0) {
        var selVal = oSelect.options[i].value;
        pst_fnDoMarkup('\n[' + selVal + ']\n[bullet]$1[/bullet]\n[/' + selVal + ']\n', 'Please provide text for the first item:', '');
    }
    oSelect.selectedIndex = 0;
}

function fnMarkupHighlight(oSelect)
{
    pst_fnInit();
    var i = oSelect.selectedIndex;
    if (i > 0) {
        var selVal = oSelect.options[i].value;
        pst_fnDoMarkup('[hl=' + selVal + ']$1[/hl]', 'Please provide text to highlight:', '');
    }
    oSelect.selectedIndex = 0;
}

function fnMarkupColor(oSelect)
{
    pst_fnInit();
    var i = oSelect.selectedIndex;
    if (i > 0) {
        var selVal = oSelect.options[i].value;
        pst_fnDoMarkup('[color=' + selVal + ']$1[/hl]', 'Please provide text to color:', '');
    }
    oSelect.selectedIndex = 0;
}

function fnMarkupCodePrompt(oButton)
{
    pst_fnInit();
    pst_fnDoMarkup('\n[code=$1]\n$2\n[/code]\n', '*Enter code language (html, php, css, js, sql, etc.) or leave blank:_Paste code:', 'text_');
}

function fnMarkupColorPrompt(oButton)
{
    pst_fnInit();
    pst_fnDoMarkup('[color=$1]$2[/hl]', '*Enter text color (#0096ff or blue):_Please provide text to color:', '#0096ff_');
}

function fnMarkupHighlightPrompt(oButton)
{
    pst_fnInit();
    pst_fnDoMarkup('[hl=$1]$2[/hl]', '*Enter highlight color (#0096ff or blue):_Please provide text to highlight:', '#0096ff_');
}

function fnMarkupSizePrompt(oButton)
{
    pst_fnInit();
    pst_fnDoMarkup('[size=$1]$2[/size]', '*Enter size (for example +2, -1, 1-7, small, large):_Please provide text to size:', '+2_');
}

function fnMarkupFace(txtFace)
{
    pst_fnInit();
    pst_fnInsertAtSelection('[face_' + txtFace + ']', ('[face_' + txtFace + ']').length);

    var menu = document.getElementById('divFacesMenu');
    if (menu && menu.style) {
        menu.style.display = 'none';
    }
}

function fnMarkupListItem(oButton)
{
    pst_fnInit();
    pst_fnDoMarkup('\n   [bullet]$1[/bullet]', 'Please provide text for the first item:', '');
}

function fnMarkupQuote(oButton)
{
    pst_fnInit();
    pst_fnDoMarkup('\n[quote=$1]$2[/quote]\n', '*Please provide the username to quote:_Please provide the text to quote:', 'username');
}

function fnMarkupHR(oButton)
{
    pst_fnInit();
    pst_fnInsertAtSelection('\n[hr]\n', 6);
}

function fnMarkupBlockquote(oButton)
{
    pst_fnInit();
    pst_fnDoMarkup('\n[blockquote]$1[/blockquote]\n', 'Please provide the text to BLOCKQUOTE:', '');
}

function fnMarkupLink(oButton)
{
    pst_fnInit();
    pst_fnDoMarkup('[link=$1]$2[/link]', '*Enter URL:_Please provide the text to LINK:', 'http://');
}

function fnMarkupImage(oButton)
{
    pst_fnInit();
    pst_fnDoMarkup('[image=$1]', '*Enter URL:_Please provide URL to the image to LINK:', 'http://');
}

function fnMarkupUnderline(oButton)
{
    pst_fnInit();
    pst_fnDoMarkup('[u]$1[/u]', 'Please provide the text to UNDERLINE', '');
}

function fnMarkupItalic(oButton)
{
    pst_fnInit();
    pst_fnDoMarkup('[i]$1[/i]', 'Please provide the text to ITALICIZE', '');
}

function fnMarkupBold(oButton)
{
    pst_fnInit();
    pst_fnDoMarkup('[b]$1[/b]', 'Please provide the text to BOLD', '');
}

function fnMarkupOverline(oButton)
{
    pst_fnInit();
    pst_fnDoMarkup('[o]$1[/o]', 'Please provide the text to OVERLINE', '');
}
