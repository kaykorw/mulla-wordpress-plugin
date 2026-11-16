/**
 * Mulla payment method for the WooCommerce block checkout.
 * No build step: uses the globals WooCommerce and WordPress already load.
 */
(function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var wcSettings = window.wc && window.wc.wcSettings;
	if (!registry || !wcSettings) {
		return;
	}

	var el = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;
	var decode = window.wp.htmlEntities.decodeEntities;

	var settings = wcSettings.getSetting('mulla_data', {});
	var i18n = settings.i18n || {};
	var providers = settings.providers || [];
	var title = decode(settings.title || 'Mobile Money');

	function normalize(phone) {
		var digits = String(phone || '').replace(/\D+/g, '');
		if (digits.length === 10 && digits.indexOf('07') === 0) digits = '25' + digits;
		else if (digits.length === 9 && digits.indexOf('7') === 0) digits = '250' + digits;
		return /^2507[2389]\d{7}$/.test(digits) ? digits : null;
	}

	function guessProvider(normalized) {
		var prefix = normalized ? normalized.slice(0, 5) : '';
		if (prefix === '25078' || prefix === '25079') return 'MTN_RW';
		if (prefix === '25072' || prefix === '25073') return 'AIRTEL_RW';
		return null;
	}

	function Fields(props) {
		var billingPhone = (props.billing && props.billing.billingAddress && props.billing.billingAddress.phone) || '';
		var phoneState = useState(billingPhone);
		var phone = phoneState[0];
		var setPhone = phoneState[1];
		var providerState = useState(guessProvider(normalize(billingPhone)) || (providers[0] && providers[0].value) || 'MTN_RW');
		var provider = providerState[0];
		var setProvider = providerState[1];

		var onPaymentSetup = props.eventRegistration.onPaymentSetup;
		var responseTypes = props.emitResponse.responseTypes;

		useEffect(function () {
			return onPaymentSetup(function () {
				var normalized = normalize(phone);
				if (!normalized) {
					return { type: responseTypes.ERROR, message: i18n.invalidPhone };
				}
				return {
					type: responseTypes.SUCCESS,
					meta: { paymentMethodData: { mulla_provider: provider, mulla_phone: normalized } },
				};
			});
		}, [onPaymentSetup, responseTypes, phone, provider]);

		return el(
			'div',
			{ className: 'mulla-fields' },
			settings.description ? el('p', null, decode(settings.description)) : null,
			settings.testMode ? el('p', { className: 'mulla-test-mode' }, i18n.testMode) : null,
			el(
				'fieldset',
				{ className: 'mulla-providers' },
				el('legend', null, i18n.network),
				providers.map(function (p) {
					return el(
						'label',
						{ key: p.value, className: 'mulla-provider' },
						el('input', {
							type: 'radio',
							name: 'mulla_provider',
							value: p.value,
							checked: provider === p.value,
							onChange: function () { setProvider(p.value); },
						}),
						' ' + p.label
					);
				})
			),
			el(
				'label',
				{ className: 'mulla-phone' },
				i18n.phone,
				el('input', {
					type: 'tel',
					autoComplete: 'tel',
					inputMode: 'tel',
					placeholder: '078 000 0000',
					value: phone,
					onChange: function (event) {
						var value = event.target.value;
						setPhone(value);
						var guess = guessProvider(normalize(value));
						if (guess && providers.some(function (p) { return p.value === guess; })) setProvider(guess);
					},
				})
			)
		);
	}

	var label = el(
		'span',
		{ className: 'mulla-label' },
		settings.icon ? el('img', { src: settings.icon, alt: '', className: 'mulla-label__icon' }) : null,
		title
	);

	registry.registerPaymentMethod({
		name: 'mulla',
		label: label,
		ariaLabel: title,
		content: el(Fields),
		edit: el(Fields),
		canMakePayment: function () { return true; },
		supports: { features: settings.supports || ['products'] },
	});
})();
