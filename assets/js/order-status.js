/**
 * Order-received page: follows a Mobile Money payment until it is approved, declined or expired.
 */
(function () {
	'use strict';

	var box = document.querySelector('[data-mulla-order-status]');
	if (!box) return;

	var message = box.querySelector('.mulla-status__message');
	var startedAt = Date.now();
	var MAX_WAIT_MS = 12 * 60 * 1000; // Mulla expires a charge after 10 minutes.

	function render(data) {
		box.setAttribute('data-state', data.state);
		message.textContent = data.message;
		if (data.retry_url && !box.querySelector('a')) {
			var link = document.createElement('a');
			link.href = data.retry_url;
			link.className = 'button';
			link.textContent = box.getAttribute('data-retry-label') || 'Try again';
			box.appendChild(link);
		}
	}

	function poll() {
		fetch(box.getAttribute('data-url'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then(function (response) { return response.json(); })
			.then(function (data) {
				render(data);
				if (data.state === 'paid') {
					window.setTimeout(function () { window.location.reload(); }, 1500);
					return;
				}
				if (data.state === 'pending' && Date.now() - startedAt < MAX_WAIT_MS) {
					window.setTimeout(poll, 4000);
				}
			})
			.catch(function () {
				if (Date.now() - startedAt < MAX_WAIT_MS) window.setTimeout(poll, 8000);
			});
	}

	if (box.getAttribute('data-state') === 'pending') {
		window.setTimeout(poll, 3000);
	}
})();
