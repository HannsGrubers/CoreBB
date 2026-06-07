// ==UserScript==
// @name          Auto Save Forms
// @description	  Saves form data every few seconds and gives option of repopulating form later.
// ==/UserScript==



(function() {

	var d = document;
	var i = d.getElementByName('message'); // inputs
	var t = d.getElementByName('message'); // textareas
	var f = d.getElementByName('message'); // textareas

	// Strip i down to just text inputs
	var newi = new Array();
	for (j=0;j<i.length;j++) {
		if (i[j].type == 'text') {
			newi.push(i[j]);
		}
	}
	i = newi;

	var box; // Box for offer to repopulate
	var boxtext; // Text for offer to repopulate
	var j;
	var e = new Array();
	var eo;
	var saving;

	function start() {
		for (j = 0; j < f.length; j++) {
			f[j].addEventListener("submit", clear, false);
		}
		for (j = 0; j < i.length; j++) {
			i[j].addEventListener("keyup", prepsave, false);
		}
		for (j = 0; j < t.length; j++) {
			t[j].addEventListener("keyup", prepsave, false);
		}
		offer_repopulate();
	}

	function clear() {
		var today = new Date();
		var expiry = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000); // In the past to expire cookie
		d.cookie = "WarNetPostCookie=; expires=" + expiry.toGMTString() + "; path=/";
	}

	function getCookie(name) {
		var re = new RegExp(name + "=([^;]+)");
		var value = re.exec(d.cookie);
		return (value != null) ? unescape(value[1]) : false;
	}

	function setCookie(name, value) {
		var today = new Date();
		var expiry = new Date(today.getTime() + 30 * 24 * 60 * 60 * 1000); // Expires after a month

		d.cookie = name + "=" + escape(value) + "; expires=" + expiry.toGMTString() + "; path=/";
	}

	function prepsave() {
		clearInterval(saving);
		saving = setInterval(savedata, 500);
	}

	function savedata() {
		e = new Array();
		for (j=0;j<i.length;j++) {
			e.push(i[j].value.toString());
		}
		for (j=0;j<t.length;j++) {
			e.push(t[j].value.toString());
		}
		setCookie('WarNetPostCookie', e.join("|"));
		clearInterval(saving);
	}

	function repopulate() {
		eo = getCookie('WarNetPostCookie').split("|");
		for (j=0;j<i.length;j++) {
			i[j].value = eo.shift();
		}
		for (j = 0; j < t.length; j++) {
			t[j].value = eo.shift();
		}
	}
	
	window.addEventListener("load", start, false);

})();