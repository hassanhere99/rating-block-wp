/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';

/**
 * Sample values for the preview. The block has no data of its own in the
 * editor; the real rating is rendered on the server for each post.
 */
const SAMPLE_AVERAGE = 4;

/**
 * Editor view: a static preview and the three settings.
 *
 * @param {Object}                       props               Block props.
 * @param {Object}                       props.attributes    Block attributes.
 * @param {(attributes: Object) => void} props.setAttributes Attribute setter.
 * @return {Element} Element to render.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { label, showCount, allowVoting } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'rating-block-wp' ) }>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Label', 'rating-block-wp' ) }
						help={ __(
							'Shown above the stars. Leave empty for "Rate this post".',
							'rating-block-wp'
						) }
						value={ label }
						onChange={ ( value ) =>
							setAttributes( { label: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show vote count', 'rating-block-wp' ) }
						checked={ showCount }
						onChange={ ( value ) =>
							setAttributes( { showCount: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Allow voting', 'rating-block-wp' ) }
						help={ __(
							'When off, visitors see the rating but cannot vote.',
							'rating-block-wp'
						) }
						checked={ allowVoting }
						onChange={ ( value ) =>
							setAttributes( { allowVoting: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<p className="rating-block__title">
					{ label || __( 'Rate this post', 'rating-block-wp' ) }
				</p>
				<span
					className="rating-block__display"
					style={ {
						'--rating-block-fill': `${
							( SAMPLE_AVERAGE / 5 ) * 100
						}%`,
					} }
					aria-hidden="true"
				/>
				<p className="rating-block__summary">
					{ showCount
						? __(
								'Preview: 4.0 out of 5. Ratings: 12',
								'rating-block-wp'
							)
						: __( 'Preview: 4.0 out of 5', 'rating-block-wp' ) }
				</p>
			</div>
		</>
	);
}
