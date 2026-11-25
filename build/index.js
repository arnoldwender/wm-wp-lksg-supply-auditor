( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var ToggleControl = wp.components.ToggleControl;
	var SelectControl = wp.components.SelectControl;
	var Disabled = wp.components.Disabled;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var ServerSideRender = wp.serverSideRender;

	registerBlockType( 'wm/lksg-supplier-portal', {
		edit: function ( props ) {
			var attributes = props.attributes || {};
			var setAttributes = props.setAttributes;
			var showIntro = attributes.showIntro !== false;
			var defaultTier = attributes.defaultTier || 1;
			var blockProps = useBlockProps();

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __( 'Formulareinstellungen', 'wm-lksg-supply-auditor' ),
							initialOpen: true
						},
						el( ToggleControl, {
							label: __( 'Einführungstext anzeigen', 'wm-lksg-supply-auditor' ),
							checked: !! showIntro,
							onChange: function ( value ) {
								setAttributes( { showIntro: !! value } );
							},
							__nextHasNoMarginBottom: true
						} ),
						el( SelectControl, {
							label: __( 'Vorausgewählte Lieferantenstufe', 'wm-lksg-supply-auditor' ),
							value: String( defaultTier ),
							options: [
								{
									value: '1',
									label: __( 'Stufe 1 (unmittelbarer Vertragslieferant)', 'wm-lksg-supply-auditor' )
								},
								{
									value: '2',
									label: __( 'Stufe 2 (Vorlieferant)', 'wm-lksg-supply-auditor' )
								},
								{
									value: '3',
									label: __( 'Stufe 3 oder weiter (Rohstoff- oder Komponentenquelle)', 'wm-lksg-supply-auditor' )
								}
							],
							onChange: function ( value ) {
								setAttributes( { defaultTier: parseInt( value, 10 ) || 1 } );
							},
							__nextHasNoMarginBottom: true
						} )
					)
				),
				el(
					'div',
					blockProps,
					el(
						Disabled,
						null,
						el( ServerSideRender, {
							block: 'wm/lksg-supplier-portal',
							attributes: attributes
						} )
					)
				)
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );
