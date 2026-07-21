<?php
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

class esig_hold_payment {

	protected static $instance = null;

	/**
	 * Returns an instance of this class.
	 *
	 * @since  0.1
	 *
	 * @return object A single instance of this class.
	 */
	public static function get_instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor — wires up admin hooks and the AJAX handler.
	 *
	 * @since  0.1
	 * @access private
	 */
	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'esig_order_meta_box' ) );
		add_action( 'esig_woo_order_agreement_action', array( $this, 'esig_order_meta_box' ) );
		add_action( 'wp_ajax_esig_create_order_agreement', array( $this, 'create_order_agreement' ) );
		add_action( 'admin_menu', array( $this, 'register_resend_page' ) );
	}

	/**
	 * Register the e-signature resend page slug used by the order meta box Resend link.
	 *
	 * The e-signature core registers `esign-resend_invite-document` which the router
	 * maps to the non-existent method `resend_invite()`. The correct slug for the
	 * existing `resend()` method is `esign-resend-document`. We register it here as a
	 * hidden submenu page so WordPress accepts the URL and routes it correctly.
	 *
	 * @since  2.0.2
	 *
	 * @return void
	 */
	public function register_resend_page() {
		add_submenu_page( ' ', '', '', 'read', 'esign-resend-document', array( $this, 'handle_resend_page' ) );
	}

	/**
	 * Handle the esign-resend-document admin page request.
	 *
	 * Delegates to WP_E_DocumentsController::resend() which looks up all unsigned
	 * invitations for the document and sends the invitation email, then redirects
	 * back to the callBackUrl.
	 *
	 * @since  2.0.2
	 *
	 * @return void
	 */
	public function handle_resend_page() {
		if ( ! function_exists( 'WP_E_Sig' ) ) {
			return;
		}
		$controller = new \WpEsignature\Controllers\DocumentsController();
		$controller->resend();
	}

	/**
	 * Return the WooCommerce order screen ID, compatible with both classic
	 * orders (shop_order) and HPOS (woocommerce_page_wc-orders).
	 *
	 * @since  2.0.2
	 * @access private
	 *
	 * @return string Screen ID string.
	 */
	private function get_order_screen_id() {
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			return wc_get_page_screen_id( 'shop-order' );
		}
		return 'shop_order';
	}

	/**
	 * Resolve the current order ID from the request, supporting both classic
	 * orders (?post=X) and HPOS (?id=X).
	 *
	 * @since  2.0.2
	 * @access private
	 *
	 * @return int|false Order ID, or false if not resolvable.
	 */
	private function get_order_id_from_request() {
		// HPOS passes ?id=X; classic orders pass ?post=X.
		$order_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! $order_id ) {
			$order_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		}
		return $order_id ? $order_id : false;
	}

	/**
	 * Create a new agreement document and send the invitation email to the customer.
	 *
	 * Triggered via AJAX from the order meta box. Guards against duplicate document
	 * creation by persisting the guard meta immediately after saveThenSend() — even
	 * when the email step throws — so that rapid or repeated clicks cannot create
	 * more than one copy per agreement.
	 *
	 * @since  0.1
	 *
	 * @return void Outputs JSON and exits.
	 */
	public function create_order_agreement() {
		// Security: Verify nonce. wp_die(-1) is the WP AJAX convention — it
		// returns HTTP 200 with body "-1", which jQuery's .done() handles so
		// the JS error path shows a proper alert instead of the generic fail().
		$nonce = esigpost( 'esig_woo_nonce' );
		if ( ! wp_verify_nonce( $nonce, 'esig-woo-order' ) ) {
			wp_die( -1 );
		}

		// Security: Verify the user has permission to manage orders.
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_posts' ) ) {
			wp_die( -1 );
		}

		$postId = absint( esigpost( 'esig_woo_order' ) );
		$order  = wc_get_order( $postId );

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'esig-commerce' ) ) );
		}

		$docs      = $order->get_meta( '_esig_after_checkout_doc_list', true );
		$contracts = json_decode( $docs );

		if ( ! is_object( $contracts ) && ! is_array( $contracts ) ) {
			wp_send_json_error( array( 'message' => __( 'No agreement list found for this order.', 'esig-commerce' ) ) );
		}

		$mail_sent = false;

		foreach ( $contracts as $doc_id => $status ) {

			if ( 'no' !== $status || ! is_numeric( $doc_id ) ) {
				continue;
			}

			// Guard: skip if the invitation email was already sent for this document.
			// Checked against both HPOS order meta and legacy post meta.
			$already_sent = $order->get_meta( '_esig-agreement-created-' . $doc_id, true );
			if ( ! $already_sent ) {
				$already_sent = get_post_meta( $postId, '_esig-agreement-created-' . $doc_id, true );
			}
			if ( $already_sent ) {
				continue;
			}

			// The document was already copied from the template at checkout by
			// clone_document(). The invitation row was saved at that point too.
			// All we need to do here is send the invitation email.
			$invitations = \WpEsignature\Models\Invite::getInstance()->getInvitations( $doc_id );

			if ( empty( $invitations ) ) {
				continue;
			}

			$invitation_id = null;

			foreach ( $invitations as $invite ) {
				$inv_id  = $invite->invitation_id;
				$user_id = $invite->user_id;

				$mail_sent     = \WpEsignature\Models\Invite::getInstance()->send_invitation( $inv_id, $user_id, $doc_id );
				$invitation_id = $inv_id;
			}

			if ( ! $invitation_id ) {
				continue;
			}

			// Persist guard in both HPOS order meta and legacy post meta.
			$order->update_meta_data( '_esig-agreement-created-' . $doc_id, $invitation_id );
			$order->save();
			update_post_meta( $postId, '_esig-agreement-created-' . $doc_id, $invitation_id );

			// Mark this document as sent in the checkout doc list.
			esig_woo_logic::update_after_checkout_doc_list( $postId, $doc_id, $doc_id );
		}

		wp_send_json_success( array( 'mail_sent' => $mail_sent ) );
	}

	/**
	 * Register the WP E-Signature meta box on the order edit screen.
	 *
	 * Supports both classic orders (shop_order post type) and HPOS
	 * (woocommerce_page_wc-orders). The meta box is shown only when the order
	 * has at least one e-signature agreement attached (signed or unsigned).
	 *
	 * @since  0.1
	 *
	 * @return void
	 */
	public function esig_order_meta_box() {
		if ( ! function_exists( 'WP_E_Sig' ) ) {
			return;
		}

		$order_id = $this->get_order_id_from_request();
		if ( ! $order_id ) {
			return;
		}

		if ( ! $this->order_has_esig_agreements( $order_id ) ) {
			return;
		}

		$screen = $this->get_order_screen_id();

		add_meta_box(
			'esig-woo-order-meta-box',
			__( 'WP E-Signature', 'esig-woocommerce' ),
			array( $this, 'esig_order_meta_box_content' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * Render the content of the WP E-Signature order meta box.
	 *
	 * Lists all e-signature agreements attached to the order, showing their
	 * signing status and providing a "Resend Agreement" action link for any
	 * documents that have not yet been signed.
	 *
	 * @since  0.1
	 *
	 * @return void
	 */
	public function esig_order_meta_box_content() {
		$order_id = $this->get_order_id_from_request();
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$doc_list = $this->get_order_doc_list( $order );

		if ( empty( $doc_list ) || ! is_array( $doc_list ) ) {
			printf( '<p>%s</p>', esc_html__( 'No e-signature agreements found for this order.', 'esig-woocommerce' ) );
			return;
		}

		$has_unsigned = false;
		$i            = 0;

		foreach ( $doc_list as $doc_id => $status ) {

			if ( ! is_numeric( $doc_id ) ) {
				continue;
			}

			// Retrieve the invitation ID stored when the agreement was sent.
			// Check HPOS order meta first, fall back to post meta for legacy orders.
			$invitation_id = $order->get_meta( '_esig-agreement-created-' . $doc_id, true );
			if ( ! $invitation_id ) {
				$invitation_id = get_post_meta( $order_id, '_esig-agreement-created-' . $doc_id, true );
			}

			if ( $invitation_id ) {
				$is_signed = $this->is_signed_doc( $invitation_id );

				if ( $is_signed ) {
					// Signed: show status only.
					printf(
						'<p>%s &mdash; <strong>%s</strong></p>',
						esc_html( $this->document_title( $invitation_id ) ),
						esc_html__( 'Signed', 'esig-woocommerce' )
					);
				} else {
					// Unsigned: show resend link.
					$has_unsigned = true;
					if ( $i === 0 ) {
						printf( '<p><strong>%s</strong></p>', esc_html__( 'Unsigned agreement(s) for this order:', 'esig-woocommerce' ) );
					}
					printf(
						'<p>%s &mdash; <a href="%s">%s</a></p>',
						esc_html( $this->document_title( $invitation_id ) ),
						esc_url( $this->get_resend_url( $invitation_id, $order_id ) ),
						esc_html__( 'Resend Agreement', 'esig-woocommerce' )
					);
					++$i;
				}
			} elseif ( 'no' === $status ) {
				// Agreement assigned but invitation not sent yet (manual send needed).
				$has_unsigned = true;
				if ( $i === 0 ) {
					printf( '<p><strong>%s</strong></p>', esc_html__( 'Unsigned agreement(s) for this order:', 'esig-woocommerce' ) );
					?>
					<input id="esig_woo_order_id" name="esig_woo_order_id" type="hidden" value="<?php echo esc_attr( $order_id ); ?>">
					<button id="esig-woo-unsigned-agreement-send" class="button button-primary">
						<?php esc_html_e( 'Send agreement for signature', 'esig-woocommerce' ); ?>
					</button>
					<?php
					++$i;
				}
			}
		}

		if ( ! $has_unsigned && $i === 0 ) {
			// All agreements in the list are signed.
			printf( '<p>%s</p>', esc_html__( 'All agreements for this order have been signed.', 'esig-woocommerce' ) );
		}
	}

	/**
	 * Build the "Resend Agreement" URL for a given invitation, with a return
	 * URL that works for both classic and HPOS order screens.
	 *
	 * @since  0.1
	 * @access private
	 *
	 * @param  int $invitation_id E-signature invitation ID.
	 * @param  int $order_id      WooCommerce order ID.
	 *
	 * @return string Escaped admin URL.
	 */
	private function get_resend_url( $invitation_id, $order_id ) {
		if ( ! function_exists( 'WP_E_Sig' ) ) {
			return '';
		}
		$document_id = WP_E_Sig()->invite->getdocumentid_By_inviteid( $invitation_id );

		// Build a return URL that is compatible with both classic and HPOS.
		$screen = $this->get_order_screen_id();
		if ( 'shop_order' === $screen ) {
			$return_url = add_query_arg(
				array(
					'post'   => $order_id,
					'action' => 'edit',
				),
				admin_url( 'post.php' )
			);
		} else {
			$return_url = add_query_arg(
				array(
					'page'   => 'wc-orders',
					'action' => 'edit',
					'id'     => $order_id,
				),
				admin_url( 'admin.php' )
			);
		}

		$resend_url = add_query_arg(
			apply_filters(
				'esig_resend_url_filter',
				array(
					'page'        => 'esign-resend-document',
					'document_id' => $document_id,
					'callBackUrl' => rawurlencode( esc_url_raw( $return_url ) ),
				)
			),
			admin_url( 'admin.php' )
		);

		return apply_filters( 'esig_resend_url', esc_url_raw( $resend_url ) );
	}

	/**
	 * Return the document title for a given invitation ID.
	 *
	 * @since  0.1
	 * @access private
	 *
	 * @param  int $invitation_id E-signature invitation ID.
	 *
	 * @return string Document title, or empty string if not found.
	 */
	private function document_title( $invitation_id ) {
		if ( ! function_exists( 'WP_E_Sig' ) ) {
			return '';
		}
		$document_id = WP_E_Sig()->invite->getdocumentid_By_inviteid( $invitation_id );
		$doc         = WP_E_Sig()->document->getDocument( $document_id );
		return isset( $doc->document_title ) ? $doc->document_title : '';
	}

	/**
	 * Check whether the signer on a given invitation has already signed.
	 *
	 * @since  0.1
	 * @access private
	 *
	 * @param  int $invitation_id E-signature invitation ID.
	 *
	 * @return bool True if the document has been signed, false otherwise.
	 */
	private function is_signed_doc( $invitation_id ) {
		if ( ! function_exists( 'WP_E_Sig' ) ) {
			return false;
		}
		$invite = WP_E_Sig()->invite->getInviteBy( 'invitation_id', $invitation_id );
		if ( ! is_object( $invite ) ) {
			return false;
		}
		return WP_E_Sig()->signature->userHasSignedDocument( $invite->user_id, $invite->document_id );
	}

	/**
	 * Determine whether the given order has any e-signature agreements attached.
	 *
	 * Uses the WooCommerce order meta API so it works with both classic order
	 * storage (wp_postmeta) and HPOS (custom order tables).
	 *
	 * @since  2.0.2
	 * @access private
	 *
	 * @param  int $order_id WooCommerce order ID.
	 *
	 * @return bool True if the order has at least one e-signature agreement.
	 */
	private function order_has_esig_agreements( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$doc_list = $this->get_order_doc_list( $order );
		return ! empty( $doc_list ) && is_array( $doc_list );
	}

	/**
	 * Retrieve and decode the e-signature document list stored on an order.
	 *
	 * @since  2.0.2
	 * @access private
	 *
	 * @param  WC_Order $order WooCommerce order object.
	 *
	 * @return array|null Associative array of document_id => status, or null.
	 */
	private function get_order_doc_list( $order ) {
		$raw = $order->get_meta( '_esig_after_checkout_doc_list', true );
		if ( empty( $raw ) ) {
			return null;
		}
		$decoded = json_decode( stripslashes_deep( str_replace( '\\', '', $raw ) ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	// pre_process_checkout() was removed — it was dead code (never hooked)
	// that contained a debug update_option() call serialising the entire
	// WC session object to the database.
}
