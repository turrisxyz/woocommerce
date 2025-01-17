/**
 * External dependencies
 */
import { useMemo } from '@wordpress/element';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { useCreateProductByType } from './use-create-product-by-type';
import { ProductType, ProductTypeKey } from './constants';

const useProductTypeListItems = (
	_productTypes: ProductType[],
	suggestedProductTypes: ProductTypeKey[] = [],
	{
		onClick,
	}: {
		onClick?: () => void;
	} = {}
) => {
	const { createProductByType } = useCreateProductByType();

	const productTypes = useMemo(
		() =>
			_productTypes.map( ( productType ) => ( {
				...productType,
				onClick: () => {
					createProductByType( productType.key );
					recordEvent( 'tasklist_product_template_selection', {
						product_type: productType.key,
						is_suggested: suggestedProductTypes.includes(
							productType.key
						),
					} );
					if ( typeof onClick === 'function' ) {
						onClick();
					}
				},
			} ) ),
		[ createProductByType ]
	);

	return productTypes;
};

export default useProductTypeListItems;
