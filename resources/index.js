mw.SGPack = {
	// String (UTF-8 sicher) decode
    rawdecode : function (str) {
		return decodeURIComponent((str+'')
	    	.replace(/%(?![\da-f]{2})/gi, function() {
				return '%25';
			}
		))
    },

	// String decodieren und mit insertTags in Editorfeld einsetzen
    insert : function (str) {
		var text = this.rawdecode(str+'');
		console.debug(text);
		astr = text.split('+');
		mw.toolbar.insertTags(astr[0],astr[1],astr[2]);
	},

	// String aus Dropdown Auswahl auslesen und in Editorfeld einsetzen
	insertSelect : function (sel) {
		var str = sel.options[sel.options.selectedIndex].value;
		this.insert(str);
	}
};
