/**
 * Mulla → Settings: show only the key and webhook-secret fields of the selected environment
 * (Sandbox or Production). Without JavaScript every field stays visible.
 */
(function () {
	'use strict';

	var radios = document.querySelectorAll('.mulla-mode input[type="radio"]');
	if (!radios.length) {
		return;
	}

	function apply() {
		var checked = document.querySelector('.mulla-mode input[type="radio"]:checked');
		var mode = checked ? checked.value : 'test';

		document.querySelectorAll('.mulla-admin [data-mulla-mode]').forEach(function (row) {
			row.hidden = row.getAttribute('data-mulla-mode') !== mode;
		});
	}

	radios.forEach(function (radio) {
		radio.addEventListener('change', apply);
	});
	apply();
})();
