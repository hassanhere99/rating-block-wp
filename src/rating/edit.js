/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';

/**
 * Editor view. Placeholder until the block is built in a later phase.
 *
 * @return {Element} Element to render.
 */
export default function Edit() {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			{ __( 'Rating Block placeholder.', 'rating-block-wp' ) }
		</div>
	);
}
