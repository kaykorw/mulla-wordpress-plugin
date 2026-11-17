/**
 * [mulla_pay] form: starts the payment, then follows it until it is approved, declined or expired.
 */
(function () {
	'use strict';

	var MAX_WAIT_MS = 12 * 60 * 1000;
	var returned = false; // only one form on the page shows the result of a hosted-checkout return

	function setup(form) {
		var button = form.querySelector('button[type="submit"]');
		var status = form.querySelector('.mulla-status');
		var message = status.querySelector('.mulla-status__message');

		function show(state, text) {
			status.hidden = false;
			status.setAttribute('data-state', state);
			message.textContent = text;
		}

		function follow(url, startedAt) {
			fetch(url, { headers: { Accept: 'application/json' } })
				.then(function (response) { return response.json(); })
				.then(function (data) {
					if (data.status === 'SUCCESSFUL') {
						show('paid', data.message);
						return;
					}
					if (data.status === 'FAILED' || data.status === 'EXPIRED') {
						show('failed', data.message);
						button.disabled = false;
						return;
					}
					show('pending', data.message);
					if (Date.now() - startedAt < MAX_WAIT_MS) {
						window.setTimeout(function () { follow(url, startedAt); }, 4000);
					}
				})
				.catch(function () {
					window.setTimeout(function () { follow(url, startedAt); }, 8000);
				});
		}

		function statusUrl(payment, token) {
			return form.getAttribute('data-endpoint') + '/' + encodeURIComponent(payment) + '?token=' + encodeURIComponent(token);
		}

		// Back from Mulla's checkout page: show the result. The URL proves nothing, so ask the server.
		var params = new URLSearchParams(window.location.search);
		if (form.getAttribute('data-flow') === 'redirect' && params.get('mulla_payment') && params.get('mulla_token') && !returned) {
			returned = true;
			form.scrollIntoView({ block: 'center' });
			if (params.get('mulla_cancelled')) {
				show('failed', form.getAttribute('data-cancelled-label') || 'Payment cancelled.');
			} else {
				button.disabled = true;
				show('pending', '…');
				follow(statusUrl(params.get('mulla_payment'), params.get('mulla_token')), Date.now());
			}
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			if (!form.reportValidity()) return;

			button.disabled = true;
			show('pending', '…');

			var data = new FormData(form);
			var body = {};
			data.forEach(function (value, key) { body[key] = value; });

			fetch(form.getAttribute('data-endpoint'), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				body: JSON.stringify(body),
			})
				.then(function (response) {
					return response.json().then(function (json) { return { ok: response.ok, json: json }; });
				})
				.then(function (result) {
					if (!result.ok) {
						show('failed', result.json.message || 'Error');
						button.disabled = false;
						return;
					}
					if (result.json.redirect) {
						// Hosted checkout: Mulla's page collects the number, then sends the visitor back here.
						window.location.assign(result.json.redirect);
						return;
					}
					show('pending', result.json.message);
					var url = statusUrl(result.json.payment, result.json.token);
					window.setTimeout(function () { follow(url, Date.now()); }, 4000);
				})
				.catch(function () {
					show('failed', 'Network error. Please try again.');
					button.disabled = false;
				});
		});
	}

	document.querySelectorAll('[data-mulla-pay]').forEach(setup);
})();
