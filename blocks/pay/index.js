/**
 * "Mulla Pay" block editor UI. Rendered on the server (same output as [mulla_pay]).
 * No build step: uses the WordPress globals listed in index.asset.php.
 */
(function (wp) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType('mulla/pay', {
		edit: function (props) {
			var a = props.attributes;
			function field(key, label, help, type) {
				return el(TextControl, {
					label: label,
					help: help,
					type: type || 'text',
					value: a[key],
					onChange: function (value) {
						var next = {};
						next[key] = value;
						props.setAttributes(next);
					},
				});
			}

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __('Payment', 'mulla-payments') },
						field('reason', __('Reason', 'mulla-payments'), __('Shown to the payer and on the Mulla payment.', 'mulla-payments')),
						field('amount', __('Amount (RWF)', 'mulla-payments'), __('Leave empty to let the payer choose.', 'mulla-payments'), 'number'),
						field('min', __('Minimum amount (RWF)', 'mulla-payments'), __('Used when the payer chooses the amount.', 'mulla-payments'), 'number'),
						field('button', __('Button text', 'mulla-payments'))
					)
				),
				el(ServerSideRender, { block: 'mulla/pay', attributes: a })
			);
		},
		save: function () {
			return null;
		},
	});
})(window.wp);
