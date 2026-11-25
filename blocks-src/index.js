import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, SelectControl, Disabled } from '@wordpress/components';
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

registerBlockType( 'wm/lksg-supplier-portal', {
	edit( { attributes, setAttributes } ) {
		const { showIntro = true, defaultTier = 1 } = attributes;
		const blockProps = useBlockProps();

		return createElement(
			Fragment,
			null,
			createElement(
				InspectorControls,
				null,
				createElement(
					PanelBody,
					{
						title: __( 'Formulareinstellungen', 'wm-lksg-supply-auditor' ),
						initialOpen: true,
					},
					createElement( ToggleControl, {
						label: __( 'Einführungstext anzeigen', 'wm-lksg-supply-auditor' ),
						checked: !! showIntro,
						onChange: ( value ) => setAttributes( { showIntro: !! value } ),
						__nextHasNoMarginBottom: true,
					} ),
					createElement( SelectControl, {
						label: __( 'Vorausgewählte Lieferantenstufe', 'wm-lksg-supply-auditor' ),
						value: String( defaultTier ),
						options: [
							{
								value: '1',
								label: __( 'Stufe 1 (unmittelbarer Vertragslieferant)', 'wm-lksg-supply-auditor' ),
							},
							{
								value: '2',
								label: __( 'Stufe 2 (Vorlieferant)', 'wm-lksg-supply-auditor' ),
							},
							{
								value: '3',
								label: __( 'Stufe 3 oder weiter (Rohstoff- oder Komponentenquelle)', 'wm-lksg-supply-auditor' ),
							},
						],
						onChange: ( value ) => setAttributes( { defaultTier: parseInt( value, 10 ) || 1 } ),
						__nextHasNoMarginBottom: true,
					} )
				)
			),
			createElement(
				'div',
				blockProps,
				createElement(
					Disabled,
					null,
					createElement( ServerSideRender, {
						block: 'wm/lksg-supplier-portal',
						attributes,
					} )
				)
			)
		);
	},
	save() {
		return null;
	},
} );
