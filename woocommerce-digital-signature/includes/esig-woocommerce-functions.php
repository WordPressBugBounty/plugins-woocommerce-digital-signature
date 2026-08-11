<?php
/**
 * WooCommerce E-Signature helper functions.
 *
 * Shared utility functions and filter registrations used by the WooCommerce
 * Digital Signature add-on. Also registers the esig_reserved_page_ids filter
 * so that core E-Signature and the Stand Alone Documents add-on know which
 * WooCommerce pages should never be intercepted as signing pages.
 *
 * @package WoocommerceDigitalSignature
 * @since   2.0.3
 */

if ( ! function_exists( 'ESIG_WOO_GET' ) ) {

	/**
	 * Retrieve and sanitize a GET parameter for WooCommerce E-Signature.
	 *
	 * @since 2.0.3
	 *
	 * @param string $key The GET parameter key to retrieve.
	 *
	 * @return string Sanitized value, or empty string when not set.
	 */
	function ESIG_WOO_GET( $key ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		$value = filter_input( INPUT_GET, $key, FILTER_DEFAULT );
		return sanitize_text_field( $value );
	}

}

if ( ! function_exists( 'esig_woocommerce_get' ) ) {

	/**
	 * Retrieve and sanitize a value from $_GET, an array, or an object.
	 *
	 * @since 2.0.3
	 *
	 * @param string            $name  Key or property name to retrieve.
	 * @param array|object|null $data  Source to read from. When null, reads from $_GET.
	 *
	 * @return string|array|false Sanitized value, or false when the key is not present.
	 */
	function esig_woocommerce_get( $name, $data = null ) {

		if ( ! isset( $data ) ) {
			return ESIG_WOO_GET( $name ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		}

		if ( is_array( $data ) ) {
			if ( isset( $data[ $name ] ) ) {
				$value = wp_unslash( $data[ $name ] );
				if ( is_array( $value ) ) {
					return $value;
				}
				return sanitize_text_field( $value );
			}
			return false;
		}

		if ( is_object( $data ) ) {
			if ( isset( $data->$name ) ) {
				return sanitize_text_field( wp_unslash( $data->$name ) );
			}
			return false;
		}

		return false;
	}

}

if ( ! function_exists( 'esig_woocommerce_sanitize_init' ) ) {

	/**
	 * Sanitize a value to an integer for WooCommerce E-Signature.
	 *
	 * @since 2.0.3
	 *
	 * @param mixed $number The value to sanitize.
	 *
	 * @return string|false Sanitized integer string, or false on failure.
	 */
	function esig_woocommerce_sanitize_init( $number ) {
		return filter_var( $number, FILTER_SANITIZE_NUMBER_INT );
	}
}

/**
 * Register WooCommerce system page IDs onto the shared esig_reserved_page_ids filter.
 *
 * Other E-Signature plugins (core, stand-alone documents add-on, etc.) consume
 * reserved page IDs exclusively via:
 *
 *   apply_filters( 'esig_reserved_page_ids', [] )
 *
 * They have no direct dependency on this add-on. When this add-on is inactive
 * the filter simply returns whatever other consumers have added (or an empty
 * array), so non-WooCommerce sites are completely unaffected.
 *
 * WooCommerce pages protected: checkout, cart, shop, myaccount.
 * Any page that WooCommerce reports as -1 or 0 (not yet configured) is silently
 * excluded from the list.
 *
 * @since 2.0.3
 */
add_filter( 'esig_reserved_page_ids', 'esig_woo_register_reserved_page_ids' );

if ( ! function_exists( 'esig_woo_register_reserved_page_ids' ) ) {
	/**
	 * Populate the esig_reserved_page_ids filter with WooCommerce system page IDs.
	 *
	 * @since 2.0.3
	 *
	 * @param int[] $page_ids Page IDs already registered by other add-ons.
	 *
	 * @return int[] Merged array of reserved page IDs.
	 */
	function esig_woo_register_reserved_page_ids( array $page_ids ): array {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return $page_ids;
		}

		$woo_system_pages = array( 'checkout', 'cart', 'shop', 'myaccount' );
		$woo_ids          = array();

		foreach ( $woo_system_pages as $page_key ) {
			$id = (int) wc_get_page_id( $page_key );
			if ( $id > 0 ) {
				$woo_ids[] = $id;
			}
		}

		return array_values( array_unique( array_merge( $page_ids, $woo_ids ) ) );
	}
}
