<?php
/**
 * CampTix RazorPay Payment Method
 *
 * This class handles all RazorPay integration for CampTix
 *
 * @since          0.1
 * @package        CampTix
 * @category       Class
 * @author         _KDC-Labs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // End if().


// Load Razorpay sdk.
require_once CAMPTIX_RAZORPAY_DIR . 'inc/lib/razorpay-php/Razorpay.php';

class CampTix_Payment_Method_RazorPay extends CampTix_Payment_Method {
	/**
	 * Payment gateway id.
	 *
	 * @since 0.1
	 * @var string
	 */
	public $id = 'camptix_razorpay';

	/**
	 * Payment gateway label
	 *
	 * @since 0.1
	 * @var string
	 */
	public $name = 'RazorPay';

	/**
	 * Payment gateway description
	 *
	 * @since 0.1
	 * @var string
	 */
	public $description = 'RazorPay Indian payment gateway.';

	/**
	 * Supported currencies
	 *
	 * @since 0.1
	 * @var array
	 */
	public $supported_currencies = array( 'INR' );


	/**
	 * Supported features
	 *
	 * @since 0.1
	 * @var array
	 */
	public $supported_features = array(
		'refund-single' => false,
		'refund-all'    => false,
	);

	/**
	 * We can have an array to store our options.
	 * Use $this->get_payment_options() to retrieve them.
	 */
	protected $options = array();

	/**
	 * This is to Initiate te CampTix options
	 *
	 * @since 0.1
	 */
	public function camptix_init() {
		$this->options = wp_parse_args(
			$this->get_payment_options(),
			array(
				'razorpay_popup_title' => '',
				'live_key_id'          => '',
				'live_key_secret'      => '',
				'test_key_id'          => '',
				'test_key_secret'      => '',
				'sandbox'              => true,
			)
		);

		// Apply hooks only when payment gateway enable.
		if ( $this->is_gateway_enable() ) {
			add_action( 'template_redirect', array( $this, 'template_redirect' ) );
			add_action( 'camptix_attendee_form_additional_info', array( $this, 'add_phone_field' ), 10, 3 );
			add_filter( 'camptix_register_order_summary_header', array( $this, 'add_order_id_field' ), 10, 3 );
			add_filter( 'camptix_form_register_complete_attendee_object', array( $this, 'add_attendee_info' ), 10, 3 );
			add_action( 'camptix_checkout_update_post_meta', array( $this, 'save_attendee_info' ), 10, 2 );
			add_filter( 'camptix_metabox_attendee_info_additional_rows', array( $this, 'show_attendee_info' ), 10, 2 );
			add_action( 'wp_ajax_verify_payment', array( $this, 'verify_payment' ) );
			add_action( 'wp_ajax_nopriv_verify_payment', array( $this, 'verify_payment' ) );
		}
	}


	/**
	 * Show extra attendee information
	 *
	 * @since 0.1
	 * access public
	 *
	 * @param $rows
	 * @param $attendee
	 *
	 * @return array
	 */
	public function show_attendee_info( $rows, $attendee ) {
		if ( $attendee_phone = get_post_meta( $attendee->ID, 'tix_phone', true ) ) {
			$rows[] = array(
				__( 'Phone Number', 'camptix-razorpay' ),
				$attendee_phone,
			);
		}

		if ( $receipt_id = get_post_meta( $attendee->ID, 'tix_receipt_id', true ) ) {
			$rows[] = array(
				__( 'Razorpay Receipt ID', 'camptix-razorpay-' ),
				$receipt_id,
			);
		}

		return $rows;
	}


	/**
	 * Add extra attendee information
	 *
	 * @since  0.1
	 * @access public
	 *
	 * @param $attendee
	 * @param $attendee_info
	 * @param $current_count
	 *
	 * @return mixed
	 */
	public function add_attendee_info( $attendee, $attendee_info, $current_count ) {
		if ( ! empty( $_POST['tix_attendee_info'][ $current_count ]['phone'] ) ) {
			$attendee->phone = trim( $_POST['tix_attendee_info'][ $current_count ]['phone'] );
		}

		return $attendee;
	}


	/**
	 * Save extra attendee information
	 *
	 * @since  0.1
	 * @access public
	 *
	 * @param $attendee_id
	 * @param $attendee
	 */
	public function save_attendee_info( $attendee_id, $attendee ) {
		if ( property_exists( $attendee, 'phone' ) ) {
			update_post_meta( $attendee_id, 'tix_phone', $attendee->phone );
		}
	}


	/**
	 * Add phone field
	 *
	 * @since  0.1
	 * @access public
	 *
	 * @param $form_data
	 * @param $current_count
	 * @param $tickets_selected_count
	 *
	 * @return string
	 */
	public function add_phone_field( $form_data, $current_count, $tickets_selected_count ) {
		ob_start();
		?>
		<tr class="tix-row-phone">
			<td class="tix-required tix-left"><?php _e( 'Phone Number', 'camptix-razorpay' ); ?>
				<span class="tix-required-star">*</span>
			</td>
			<?php $value = isset( $form_data['tix_attendee_info'][ $current_count ]['phone'] ) ? $form_data['tix_attendee_info'][ $current_count ]['phone'] : ''; ?>
			<td class="tix-right">
				<input name="tix_attendee_info[<?php echo esc_attr( $current_count ); ?>][phone]" type="text" value="<?php echo esc_attr( $value ); ?>"/>
			</td>
		</tr>
		<?php
		echo ob_get_clean();
	}


	/**
	 * Start session and store ticket and coupon info
	 *
	 * @since  0.2
	 * @access public
	 *
	 * @param $form_heading
	 *
	 * @return mixed
	 */
	public function add_order_id_field( $form_heading ) {
		$this->session_start();

		$ticket_info = ! empty( $_POST['tix_tickets_selected'] ) ?
			array_map( 'esc_attr', $_POST['tix_tickets_selected'] ) :
			array();
		$coupon_id   = esc_attr( $_POST['tix_coupon'] );

		// Compare session data with latest data.
		if (
			$ticket_info !== $this->get_session_data( 'tickets_info' ) ||
			$coupon_id !== $this->get_session_data( 'coupon_id' )
		) {
			$this->set_session_data( 'tickets_info', $ticket_info );
			$this->set_session_data( 'coupon_id', $coupon_id );

			// Unset session order.
			$this->unset_session_data( 'order' );
		}

		return $form_heading;
	}


	/**
	 * Get mechant credentials
	 *
	 * @since  0.1
	 * @access public
	 * @return array
	 */
	public function get_merchant_credentials() {
		$merchant = array(
			'key_id'     => $this->options['test_key_id'],
			'key_secret' => $this->options['test_key_secret'],
		);

		if ( ! $this->options['sandbox'] ) {
			$merchant = array(
				'key_id'     => $this->options['live_key_id'],
				'key_secret' => $this->options['live_key_secret'],
			);
		}

		return $merchant;
	}

	/**
	 * Process payment gateway actions.
	 *
	 * @since  0.1
	 * @access public
	 */
	function template_redirect() {
		if ( isset( $_GET['tix_action'] ) ) {

			if ( isset( $_REQUEST['tix_payment_method'] ) && $this->id === $_REQUEST['tix_payment_method'] ) {
				if ( 'payment_failed' == $_GET['tix_action'] ) {
					$this->payment_failed();
				}

				if ( 'payment_return' == $_GET['tix_action'] ) {
					$this->payment_return();
				}
			}

			if ( 'attendee_info' == $_GET['tix_action'] ) {

				wp_register_script( 'razorpay-js', 'https://checkout.razorpay.com/v1/checkout-new.js' );
				wp_enqueue_script( 'razorpay-js' );
				wp_register_script( 'camptix-razorpay-popup-js', CAMPTIX_RAZORPAY_URL . 'assets/frontend/js/camptix-razorpay-popup.js', array( 'jquery' ), false, CAMPTIX_RAZORPAY_VERSION );
				wp_enqueue_script( 'camptix-razorpay-popup-js' );

				$merchant = $this->get_merchant_credentials();

				$data = array(
					'merchant_key_id' => $merchant['key_id'],
					'gateway_id'      => $this->id,
					'popup'           => array(
						'color' => apply_filters( 'camptix_razorpay_popup_color', '' ),

						// Ideal logo size: https://i.imgur.com/n5tjHFD.png
						'image' => apply_filters( 'camptix_razorpay_popup_logo_image', '' ),
					),
					'errors'          => array(
						'phone' => __( 'Please fill in all required fields.', 'camptix-razorpay' ),
					),
				);

				wp_localize_script( 'camptix-razorpay-popup-js', 'camptix_razorpay_vars', $data );
			}
		}// End if().

	}

	/**
	 * Add settings.
	 *
	 * @since  0.1
	 * @access public
	 */
	public function payment_settings_fields() {
		$this->add_settings_field_helper(
			'razorpay_popup_title',
			_( 'Razorpay Popup Title', 'camptix-razorpay' ),
			array( $this, 'field_text' )
		);

		$this->add_settings_field_helper(
			'live_key_id',
			__( 'Live Key ID', 'camptix-razorpay' ),
			array( $this, 'field_text' )
		);

		$this->add_settings_field_helper(
			'live_key_secret',
			__( 'Live Key Secret', 'camptix-razorpay' ),
			array( $this, 'field_text' )
		);

		$this->add_settings_field_helper(
			'test_key_id',
			__( 'Test Key ID', 'camptix-razorpay' ),
			array( $this, 'field_text' )
		);

		$this->add_settings_field_helper(
			'test_key_secret',
			__( 'Test Key Secret', 'camptix-razorpay' ),
			array( $this, 'field_text' )
		);

		$this->add_settings_field_helper(
			'sandbox',
			__( 'Sandbox Mode', 'camptix-razorpay' ),
			array( $this, 'field_yesno' ),
			__( 'The RazorPay Sandbox is a way to test payments without using real accounts and transactions. When enabled it will use sandbox merchant details instead of the ones defined above.', 'camptix-razorpay' )
		);
	}

	/**
	 * Validate options
	 *
	 * @since  0.1
	 * @access public
	 *
	 * @param array $input
	 *
	 * @return array
	 */
	public function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['razorpay_popup_title'] ) ) {
			$output['razorpay_popup_title'] = $input['razorpay_popup_title'];
		}

		if ( isset( $input['merchant_id'] ) ) {
			$output['merchant_id'] = $input['merchant_id'];
		}

		if ( isset( $input['live_key_id'] ) ) {
			$output['live_key_id'] = $input['live_key_id'];
		}

		if ( isset( $input['live_key_secret'] ) ) {
			$output['live_key_secret'] = $input['live_key_secret'];
		}

		if ( isset( $input['test_key_id'] ) ) {
			$output['test_key_id'] = $input['test_key_id'];
		}

		if ( isset( $input['test_key_secret'] ) ) {
			$output['test_key_secret'] = $input['test_key_secret'];
		}

		if ( isset( $input['sandbox'] ) ) {
			$output['sandbox'] = (bool) $input['sandbox'];
		}

		return $output;
	}


	/**
	 * CampTix Payment CheckOut : Generate & Submit the payment form.
	 *
	 * @since  0.1
	 * @access public
	 *
	 * @param string $payment_token
	 *
	 * @return void
	 */
	public function payment_checkout( $payment_token ) {
		$this->session_start();

		/* @var  CampTix_Plugin $camptix */
		global $camptix;

		// Bailout.
		if ( ! $payment_token || empty( $payment_token ) ) {
			return;
		}

		// Store order in session if not exist.
		/* @var object $order */
		if ( ! ( $order = $this->get_session_data( 'order' ) ) ) {
			// Order info.
			$order_detail = $this->razorpay_order_info(
				$this->get_session_data( 'tickets_info' ),
				$this->get_session_data( 'coupon_id' )
			);
			$this->set_session_data( 'order_detail', $order_detail );

			// Receipt id.
			$receipt_id = uniqid( 'camtix-razorpay' );
			$this->set_session_data( 'receipt_id', $receipt_id );


			// Creates order
			$api   = $this->get_razjorpay( 'api' );
			$order = $api->order->create(
				array(
					'receipt'         => $receipt_id,
					'amount'          => $order_detail['total'] * 100,
					'currency'        => 'INR',
					'payment_capture' => true,
				)
			);

			$this->set_session_data( 'order', $order );
		}

		// Payment urls.
		$return_url = add_query_arg( array(
			'tix_action'         => 'payment_return',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => $this->id,
			'transaction_id'     => $order->id,
		), $camptix->get_tickets_url() );

		$cancel_url = add_query_arg( array(
			'tix_action'         => 'payment_failed',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => $this->id,
			'transaction_id'     => $order->id,
		), $camptix->get_tickets_url() );


		// Checkout Info.
		$info       = $this->get_order( $payment_token );
		$extra_info = array(
			'order_id'       => $order->id,
			'fullname'       => trim( get_post_meta( $info['attendee_id'], 'tix_first_name', true ) . ' ' . get_post_meta( $info['attendee_id'], 'tix_last_name', true ) ),
			'email'          => get_post_meta( $info['attendee_id'], 'tix_email', true ),
			'phone'          => get_post_meta( $info['attendee_id'], 'tix_phone', true ),
			'total_in_paisa' => ( $info['total'] * 100 ),
			'return_url'     => $return_url,
			'failed_url'     => $cancel_url,
			'popup_title'    => $this->options['razorpay_popup_title'],
		);

		wp_send_json_success( array_merge( $info, $extra_info ) );
	}

	/**
	 * Process payment return.
	 *
	 * @since  0.2
	 * @access public
	 */
	function payment_return() {
		$this->session_start();

		/* @var  CampTix_Plugin $camptix */
		global $camptix;

		$camptix->log( sprintf( 'Running payment_return. Request data attached.' ), 0, $_REQUEST, "{$this->id}_payment_return" );
		$camptix->log( sprintf( 'Running payment_return. Server data attached.' ), 0, $_REQUEST, "{$this->id}_payment_return" );

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		// Bailout.
		if ( empty( $payment_token ) ) {
			return;
		}

		$this->session_destroy();

		// Complete payment
		$camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $_GET );
	}

	/**
	 * Runs when the attendee's payment fail during checkout at Razorpay.
	 * This will simply tell CampTix to put the created attendee drafts into to Failed state.
	 *
	 * @since  0.2
	 * @access public
	 */
	public function payment_failed() {
		$this->session_start();

		/* @var CampTix_Plugin $camptix */
		global $camptix;

		$camptix->log( sprintf( 'Running payment_return. Request data attached.' ), 0, $_REQUEST, "{$this->id}_payment_return" );
		$camptix->log( sprintf( 'Running payment_return. Server data attached.' ), 0, $_REQUEST, "{$this->id}_payment_return" );

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
		$receipt_id    = esc_attr( $_GET['receipt_id'] );

		if ( ! $payment_token ) {
			die( 'empty token' );
		}

		$this->session_destroy();

		// Failed Payment.
		return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $_GET );
	}

	/**
	 * Check if razorpay enbale or not
	 *
	 * @since  0.2
	 * @access public
	 *
	 * @return bool
	 */
	public function is_gateway_enable() {
		return isset( $this->camptix_options['payment_methods'][ $this->id ] );
	}


	/**
	 * Get razorpay API object
	 *
	 * @since  0.2
	 * @access private
	 *
	 * @param string $object_type
	 *
	 * @return object
	 */
	private function get_razjorpay( $object_type ) {
		$merchant = $this->get_merchant_credentials();

		switch ( $object_type ) {
			case 'api':
				return new \Razorpay\Api\Api( $merchant['key_id'], $merchant['key_secret'] );
			case 'utility':
				return new \Razorpay\Api\Utility();
		}
	}

	/**
	 * Array of selected ticket and there quantity
	 * key:value ticket_id:quantity
	 *
	 * @since  0.2
	 * @access pricate
	 *
	 * @param array  $tickets
	 * @param string $coupon_id
	 *
	 * @return array
	 */
	private function razorpay_order_info( $tickets, $coupon_id ) {
		global $wpdb;

		$order_info = array(
			'tickets'  => array(),
			'quantity' => 0,
			'total'    => 0,
		);
		$total      = $coupon_percentage = $coupon_price = 0;

		// Bailout.
		if ( empty( $tickets ) ) {
			return $order_info;
		}

		// Get coupon detail.
		if ( ! empty( $coupon_id ) ) {
			// Get coupon.
			$coupon_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE post_title = %s",
					$coupon_id
				)
			);

			// Get coupon info.
			if ( ! empty( $coupon_id ) ) {
				$coupon            = get_post( $coupon_id );
				$coupon_percentage = get_post_meta( $coupon->ID, 'tix_discount_percent', true );
				$coupon_price      = get_post_meta( $coupon->ID, 'tix_discount_price', true );
			}
		}

		// Calculate total.
		foreach ( $tickets as $ticket_id => $count ) {
			$order_info['tickets'][] = $ticket_id;
			$order_info['quantity']  += $count;

			$price = get_post_meta( $ticket_id, 'tix_price', true );
			if ( $coupon_percentage ) {
				$price -= ( ( $price * $coupon_percentage ) / 100 );
			} elseif ( $coupon_price ) {
				$price -= $coupon_price;
			}

			$total += $price * $count;
		}

		$order_info['total'] = $total;

		return $order_info;
	}

	/**
	 * Start session.
	 *
	 * @since  0.2
	 * @access private
	 */
	private function session_start() {
		if ( ! session_id() ) {
			session_start();
		}
	}

	/**
	 * Start session.
	 *
	 * @since  0.2
	 * @access private
	 */
	private function session_destroy() {
		// Unset all of the session variables.
		$_SESSION = array();

		// Finally, destroy the session.
		session_destroy();
	}

	/**
	 * Getter and setter for session data
	 *
	 * @since  0.2
	 * @access private
	 *
	 * @param $key
	 * @param $action
	 * @param $data
	 *
	 * @return mixed
	 */
	private function session_data( $key, $action = 'set', $data = null ) {
		// Bailout.
		if ( empty( $key ) ) {
			return false;
		}

		switch ( $action ) {
			case 'set':
				$_SESSION['camtix_razorpay'][ $key ] = $data;

				return true;

			case 'get':
				if ( 'all' === $key ) {
					return $_SESSION['camtix_razorpay'];
				} elseif ( isset( $_SESSION['camtix_razorpay'][ $key ] ) ) {
					return $_SESSION['camtix_razorpay'][ $key ];
				} else {
					return false;
				}
			case'unset':
				unset( $_SESSION['camtix_razorpay'][ $key ] );

				return true;
		}
	}


	/**
	 * Get session data.
	 *
	 * @since  0.2
	 * @access private
	 *
	 * @param $key
	 *
	 * @return mixed
	 */
	private function get_session_data( $key ) {
		return $this->session_data( $key, 'get' );
	}

	/**
	 * Set session data
	 *
	 * @since  0.2
	 * @access private
	 *
	 * @param      $key
	 * @param null $data
	 *
	 * @return mixed
	 */
	private function set_session_data( $key, $data = null ) {
		return $this->session_data( $key, 'set', $data );
	}

	/**
	 * Unset session data.
	 *
	 * @since  0.2
	 * @access private
	 *
	 * @param $key
	 *
	 * @return mixed
	 */
	private function unset_session_data( $key ) {
		return $this->session_data( $key, 'unset' );
	}

	/**
	 * Verify payment.
	 *
	 * @since  0.2
	 * @access public
	 */
	public function verify_payment() {
		if (
			empty( $_POST['razorpay_order_id'] ) ||
			empty( $_POST['razorpay_payment_id'] ) ||
			empty( $_POST['razorpay_signature'] )
		) {
			wp_send_json_error();
		}

		$this->session_start();

		// Get order id from session.
		$order                      = $this->get_session_data( 'order' );
		$_POST['razorpay_order_id'] = $order->id;

		/* @var  \Razorpay\Api\Utility $utility */
		$api = $this->get_razjorpay( 'api' );

		/* @var  \Razorpay\Api\Utility $utility */
		$utility = $this->get_razjorpay( 'utility' );

		// Verify response signature.
		try {
			$utility->verifyPaymentSignature( $_POST );

		} catch ( Exception $e ) {

			// Record error.
			if ( WP_DEBUG && WP_DEBUG_LOG ) {
				error_log( $e->getMessage() );
			}

			wp_send_json_error();
		}

		wp_send_json_success();
	}
}

// @todo:set transaction id in return url
// @todo: easy function to set and get value from session
// @todo validate session ticket info and coupon_id when restart again


