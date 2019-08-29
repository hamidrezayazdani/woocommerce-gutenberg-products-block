<?php
/**
 * REST API Products controller customized for Products Block.
 *
 * Handles requests to the /products endpoint. These endpoints allow read-only access to editors.
 *
 * @internal This API is used internally by the block post editor--it is still in flux. It should not be used outside of wc-blocks.
 * @package WooCommerce/Blocks
 */

namespace Automattic\WooCommerce\Blocks\RestApi\Controllers;

defined( 'ABSPATH' ) || exit;

use \WC_REST_Products_Controller;

/**
 * REST API Products controller class.
 */
class Products extends WC_REST_Products_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wc/blocks';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'products';

	/**
	 * Register the routes for products.
	 */
	public function register_routes() {
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args'   => array(
					'id' => array(
						'description' => __( 'Unique identifier for the resource.', 'woo-gutenberg-products-block' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param(
							array(
								'default' => 'view',
							)
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Check if a given request has access to read items.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|boolean
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! \current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'woocommerce_rest_cannot_view', __( 'Sorry, you cannot list resources.', 'woo-gutenberg-products-block' ), array( 'status' => \rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Check if a given request has access to read an item.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|boolean
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! \current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'woocommerce_rest_cannot_view', __( 'Sorry, you cannot view this resource.', 'woo-gutenberg-products-block' ), array( 'status' => \rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Change read permissions to allow author access to this API.
	 *
	 * @param bool   $permission Permission.
	 * @param string $context Context of the request.
	 * @return bool
	 */
	public function change_permissions( $permission, $context ) {
		if ( 'read' === $context ) {
			$permission = current_user_can( 'edit_posts' );
		}
		return $permission;
	}

	/**
	 * Get a collection of posts.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {
		add_filter( 'woocommerce_rest_check_permissions', array( $this, 'change_permissions' ), 10, 2 );
		$response = parent::get_items( $request );
		remove_filter( 'woocommerce_rest_check_permissions', array( $this, 'change_permissions' ) );

		return $response;
	}

	/**
	 * Make extra product orderby features supported by WooCommerce available to the WC API.
	 * This includes 'price', 'popularity', and 'rating'.
	 *
	 * @param WP_REST_Request $request Request data.
	 * @return array
	 */
	protected function prepare_objects_query( $request ) {
		$args             = parent::prepare_objects_query( $request );
		$operator_mapping = array(
			'in'     => 'IN',
			'not_in' => 'NOT IN',
			'and'    => 'AND',
		);

		$category_operator  = $operator_mapping[ $request->get_param( 'category_operator' ) ];
		$tag_operator       = $operator_mapping[ $request->get_param( 'tag_operator' ) ];
		$attribute_operator = $operator_mapping[ $request->get_param( 'attribute_operator' ) ];
		$catalog_visibility = $request->get_param( 'catalog_visibility' );

		if ( $category_operator && isset( $args['tax_query'] ) ) {
			foreach ( $args['tax_query'] as $i => $tax_query ) {
				if ( 'product_cat' === $tax_query['taxonomy'] ) {
					$args['tax_query'][ $i ]['operator']         = $category_operator;
					$args['tax_query'][ $i ]['include_children'] = 'AND' === $category_operator ? false : true;
				}
			}
		}

		if ( $tag_operator && isset( $args['tax_query'] ) ) {
			foreach ( $args['tax_query'] as $i => $tax_query ) {
				if ( 'product_tag' === $tax_query['taxonomy'] ) {
					$args['tax_query'][ $i ]['operator'] = $tag_operator;
				}
			}
		}

		if ( $attribute_operator && isset( $args['tax_query'] ) ) {
			foreach ( $args['tax_query'] as $i => $tax_query ) {
				if ( in_array( $tax_query['taxonomy'], wc_get_attribute_taxonomy_names(), true ) ) {
					$args['tax_query'][ $i ]['operator'] = $attribute_operator;
				}
			}
		}

		if ( in_array( $catalog_visibility, array_keys( wc_get_product_visibility_options() ), true ) ) {
			$exclude_from_catalog = 'search' === $catalog_visibility ? '' : 'exclude-from-catalog';
			$exclude_from_search  = 'catalog' === $catalog_visibility ? '' : 'exclude-from-search';

			$args['tax_query'][] = array(
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => array( $exclude_from_catalog, $exclude_from_search ),
				'operator' => 'hidden' === $catalog_visibility ? 'AND' : 'NOT IN',
			);
		}

		return $args;
	}

	/**
	 * Get product data.
	 *
	 * @param \WC_Product|\WC_Product_Variation $product Product instance.
	 * @param string                            $context Request context. Options: 'view' and 'edit'.
	 * @return array
	 */
	protected function get_product_data( $product, $context = 'view' ) {
		return array(
			'id'             => $product->get_id(),
			'name'           => $product->get_title(),
			'variation'      => $product->is_type( 'variation' ) ? wc_get_formatted_variation( $product, true, true, false ) : '',
			'permalink'      => $product->get_permalink(),
			'sku'            => $product->get_sku(),
			'description'    => apply_filters( 'woocommerce_short_description', $product->get_short_description() ? $product->get_short_description() : wc_trim_string( $product->get_description(), 400 ) ),
			'onsale'         => $product->is_on_sale(),
			'price'          => $product->get_price(),
			'price_html'     => $product->get_price_html(),
			'prices'         => $this->get_prices( $product ),
			'images'         => $this->get_images( $product ),
			'average_rating' => $product->get_average_rating(),
			'review_count'   => $product->get_review_count(),
			'add_to_cart'    => [
				'text'          => $product->add_to_cart_text(),
				'description'   => $product->add_to_cart_description(),
				'supports_ajax' => $product->supports( 'ajax_add_to_cart' ),
			],
		);
	}

	/**
	 * Get a usable add to cart URL.
	 *
	 * @param \WC_Product|\WC_Product_Variation $product Product instance.
	 * @return array
	 */
	protected function get_add_to_cart_url( $product ) {
		$url = $product->add_to_cart_url();

		// Prevent relative URLs used by simple products.
		if ( strstr( $url, '/wp-json/wc/' ) ) {
			$url = '?add-to-cart=' . $product->get_id();
		}

		return $url;
	}

	/**
	 * Get an array of pricing data.
	 *
	 * @param \WC_Product|\WC_Product_Variation $product Product instance.
	 * @return array
	 */
	protected function get_prices( $product ) {
		$tax_display_mode = get_option( 'woocommerce_tax_display_shop' );
		$position         = get_option( 'woocommerce_currency_pos' );
		$symbol           = html_entity_decode( get_woocommerce_currency_symbol() );
		$prefix           = '';
		$suffix           = '';

		// No break so symbol is added.
		switch ( $position ) {
			case 'left_space':
				$prefix = $symbol . ' ';
				break;
			case 'left':
				$prefix = $symbol;
				break;
			case 'right_space':
				$suffix = ' ' . $symbol;
				break;
			case 'right':
				$suffix = $symbol;
				break;
		}

		$prices = [
			'currency_code'      => get_woocommerce_currency(),
			'decimal_separator'  => wc_get_price_decimal_separator(),
			'thousand_separator' => wc_get_price_thousand_separator(),
			'decimals'           => wc_get_price_decimals(),
			'price_prefix'       => $prefix,
			'price_suffix'       => $suffix,
		];

		$prices['price']         = 'incl' === $tax_display_mode ? wc_get_price_including_tax( $product ) : wc_get_price_excluding_tax( $product );
		$prices['regular_price'] = 'incl' === $tax_display_mode ? wc_get_price_including_tax( $product, [ 'price' => $product->get_regular_price() ] ) : wc_get_price_excluding_tax( $product, [ 'price' => $product->get_regular_price() ] );
		$prices['sale_price']    = 'incl' === $tax_display_mode ? wc_get_price_including_tax( $product, [ 'price' => $product->get_sale_price() ] ) : wc_get_price_excluding_tax( $product, [ 'price' => $product->get_sale_price() ] );
		$prices['price_range']   = $this->get_price_range( $product );

		return $prices;
	}

	/**
	 * Get price range from certain product types.
	 *
	 * @param \WC_Product|\WC_Product_Variation $product Product instance.
	 * @return arary|null
	 */
	protected function get_price_range( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			$prices = $product->get_variation_prices( true );

			if ( min( $prices['price'] ) !== max( $prices['price'] ) ) {
				return [
					'min_amount' => min( $prices['price'] ),
					'max_amount' => max( $prices['price'] ),
				];
			}
		}

		if ( $product->is_type( 'grouped' ) ) {
			$tax_display_mode = get_option( 'woocommerce_tax_display_shop' );
			$children         = array_filter( array_map( 'wc_get_product', $product->get_children() ), 'wc_products_array_filter_visible_grouped' );

			foreach ( $children as $child ) {
				if ( '' !== $child->get_price() ) {
					$child_prices[] = 'incl' === $tax_display_mode ? wc_get_price_including_tax( $child ) : wc_get_price_excluding_tax( $child );
				}
			}

			if ( ! empty( $child_prices ) ) {
				return [
					'min_amount' => min( $child_prices ),
					'max_amount' => max( $child_prices ),
				];
			}
		}

		return null;
	}

	/**
	 * Get the images for a product or product variation.
	 *
	 * @param \WC_Product|\WC_Product_Variation $product Product instance.
	 * @return array
	 */
	protected function get_images( $product ) {
		$images         = array();
		$attachment_ids = array();

		if ( $product->get_image_id() ) {
			$attachment_ids[] = $product->get_image_id();
		}

		$attachment_ids = wp_parse_id_list( array_merge( $attachment_ids, $product->get_gallery_image_ids() ) );

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment = wp_get_attachment_image_src( $attachment_id, 'full' );

			if ( ! is_array( $attachment ) ) {
				continue;
			}

			$thumbnail = wp_get_attachment_image_src( $attachment_id, 'woocommerce_thumbnail' );

			$images[] = array(
				'id'        => $attachment_id,
				'src'       => current( $attachment ),
				'thumbnail' => current( $thumbnail ),
				'srcset'    => wp_get_attachment_image_srcset( $attachment_id, 'full' ),
				'sizes'     => wp_get_attachment_image_sizes( $attachment_id, 'full' ),
				'name'      => get_the_title( $attachment_id ),
				'alt'       => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			);
		}

		return $images;
	}

	/**
	 * Update the collection params.
	 *
	 * Adds new options for 'orderby', and new parameters 'category_operator', 'attribute_operator'.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params                       = parent::get_collection_params();
		$params['orderby']['enum']    = array_merge( $params['orderby']['enum'], array( 'menu_order', 'comment_count' ) );
		$params['category_operator']  = array(
			'description'       => __( 'Operator to compare product category terms.', 'woo-gutenberg-products-block' ),
			'type'              => 'string',
			'enum'              => array( 'in', 'not_in', 'and' ),
			'default'           => 'in',
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['tag_operator']       = array(
			'description'       => __( 'Operator to compare product tags.', 'woo-gutenberg-products-block' ),
			'type'              => 'string',
			'enum'              => array( 'in', 'not_in', 'and' ),
			'default'           => 'in',
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['attribute_operator'] = array(
			'description'       => __( 'Operator to compare product attribute terms.', 'woo-gutenberg-products-block' ),
			'type'              => 'string',
			'enum'              => array( 'in', 'not_in', 'and' ),
			'default'           => 'in',
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['catalog_visibility'] = array(
			'description'       => __( 'Determines if hidden or visible catalog products are shown.', 'woo-gutenberg-products-block' ),
			'type'              => 'string',
			'enum'              => array( 'any', 'visible', 'catalog', 'search', 'hidden' ),
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => 'rest_validate_request_arg',
		);

		return $params;
	}

	/**
	 * Get the Product's schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'product_block_product',
			'type'       => 'object',
			'properties' => array(
				'id'             => array(
					'description' => __( 'Unique identifier for the resource.', 'woo-gutenberg-products-block' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'name'           => array(
					'description' => __( 'Product name.', 'woo-gutenberg-products-block' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'variation'      => array(
					'description' => __( 'Product variation attributes, if applicable.', 'woo-gutenberg-products-block' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'permalink'      => array(
					'description' => __( 'Product URL.', 'woo-gutenberg-products-block' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'description'    => array(
					'description' => __( 'Short description or excerpt from description.', 'woo-gutenberg-products-block' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'sku'            => array(
					'description' => __( 'Unique identifier.', 'woo-gutenberg-products-block' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'price'          => array(
					'description' => __( 'Current product price.', 'woo-gutenberg-products-block' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'onsale'         => array(
					'description' => __( 'Is the product on sale?', 'woo-gutenberg-products-block' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'prices'         => array(
					'description' => __( 'Price data.', 'woo-gutenberg-products-block' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'currency_code'      => array(
								'description' => __( 'Currency code.', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'decimal_separator'  => array(
								'description' => __( 'Decimal separator.', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'thousand_separator' => array(
								'description' => __( 'Thousand separator.', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'decimals'           => array(
								'description' => __( 'Number of decimal places.', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'price_prefix'       => array(
								'description' => __( 'Price prefix, e.g. currency.', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'price_suffix'       => array(
								'description' => __( 'Price prefix, e.g. currency.', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'price'              => array(
								'description' => __( 'Current product price.', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'regular_price'      => array(
								'description' => __( 'Regular product price', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'sale_price'         => array(
								'description' => __( 'Sale product price, if applicable.', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'price_range'        => array(
								'description' => __( 'Price range, if applicable.', 'woo-gutenberg-products-block' ),
								'type'        => 'object',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'min_amount' => array(
											'description' => __( 'Price amount.', 'woo-gutenberg-products-block' ),
											'type'        => 'string',
											'context'     => array( 'view', 'edit' ),
											'readonly'    => true,
										),
										'max_amount' => array(
											'description' => __( 'Price amount.', 'woo-gutenberg-products-block' ),
											'type'        => 'string',
											'context'     => array( 'view', 'edit' ),
											'readonly'    => true,
										),
									),
								),
							),
						),
					),
				),
				'price_html'     => array(
					'description' => __( 'Price formatted in HTML.', 'woo-gutenberg-products-block' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'average_rating' => array(
					'description' => __( 'Reviews average rating.', 'woo-gutenberg-products-block' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'review_count'   => array(
					'description' => __( 'Amount of reviews that the product has.', 'woo-gutenberg-products-block' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'images'         => array(
					'description' => __( 'List of images.', 'woo-gutenberg-products-block' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit', 'embed' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'        => array(
								'description' => __( 'Image ID.', 'woo-gutenberg-products-block' ),
								'type'        => 'integer',
								'context'     => array( 'view', 'edit' ),
							),
							'src'       => array(
								'description' => __( 'Full size image URL.', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'format'      => 'uri',
								'context'     => array( 'view', 'edit' ),
							),
							'thumbnail' => array(
								'description' => __( 'Thumbnail URL.', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'format'      => 'uri',
								'context'     => array( 'view', 'edit' ),
							),
							'srcset'    => array(
								'description' => __( 'Thumbnail srcset for responsive images.', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
							),
							'sizes'     => array(
								'description' => __( 'Thumbnail sizes for responsive images.', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
							),
							'name'      => array(
								'description' => __( 'Image name.', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
							),
							'alt'       => array(
								'description' => __( 'Image alternative text.', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
							),
						),
					),
				),
				'add_to_cart'    => array(
					'description' => __( 'Add to cart button parameters.', 'woo-gutenberg-products-block' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'text'         => array(
								'description' => __( 'Button text.', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'description'  => array(
								'description' => __( 'Button description.', 'woo-gutenberg-products-block' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'supports_ajax' => array(
								'description' => __( 'Whether or not AJAX is supported.', 'woo-gutenberg-products-block' ),
								'type'        => 'boolean',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
						),
					),
				),
			),
		);
		return $this->add_additional_fields_schema( $schema );
	}
}
