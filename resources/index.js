mw.SGPack = {
	// String (UTF-8 sicher) decode
	rawdecode: function (str) {
		return decodeURIComponent(str).replace(/%(?![\da-f]{2})/gi, function () {
			return "%25";
		});
	},

	// String decodieren und mit insertTags in Editorfeld einsetzen
	insert: function (str) {
		let text = this.rawdecode(str + "");
		let astr = text.split('+');
		let currentFocused = $("#wpTextbox1");
		// Apply to dynamically created textboxes as well as normal ones
		$(document).on("focus", "textarea, input:text, .CodeMirror", function () {
			if ($(this).is(".CodeMirror")) {
				// CodeMirror hooks into #wpTextbox1 for textSelection changes
				currentFocused = $("#wpTextbox1");
			} else {
				currentFocused = $(this);
			}
		});

		if (currentFocused.length) {
			currentFocused.textSelection("encapsulateSelection", {
				pre: astr[0],
				peri: astr[1],
				post: astr[2],
			});
		}
	},

	// String aus Dropdown Auswahl auslesen und in Editorfeld einsetzen
	insertSelect: function (sel) {
		var str = sel.options[sel.options.selectedIndex].value;
		this.insert(str);
	}
};
