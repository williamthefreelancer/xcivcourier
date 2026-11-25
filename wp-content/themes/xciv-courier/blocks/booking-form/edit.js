(function (wp) {
	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, TextControl } = wp.components;
	const { createElement: el, Fragment } = wp.element;

	registerBlockType('xciv-courier/booking-form', {
		edit: function Edit({ attributes, setAttributes }) {
			const blockProps = useBlockProps({ className: 'xciv-booking xciv-booking--editor' });

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: 'Booking form', initialOpen: true },
						el(TextControl, {
							label: 'Heading',
							value: attributes.heading,
							onChange: (value) => setAttributes({ heading: value }),
						}),
						el(TextControl, {
							label: 'Submit label',
							value: attributes.submitLabel,
							onChange: (value) => setAttributes({ submitLabel: value }),
						})
					)
				),
				el(
					'div',
					blockProps,
					attributes.heading
						? el('h2', { className: 'xciv-booking__title' }, attributes.heading)
						: null,
					el(
						'p',
						{ className: 'xciv-booking__editor-note' },
						'Multi-step queue booking form with conditional fields, SMS add-on, and WooCommerce checkout.'
					),
					el(
						'button',
						{ type: 'button', className: 'xciv-booking__submit', disabled: true },
						attributes.submitLabel || 'Continue to checkout'
					)
				)
			);
		},
		save: function Save() {
			return null;
		},
	});
})(window.wp);
