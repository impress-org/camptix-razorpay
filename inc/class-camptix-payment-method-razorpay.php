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
			add_filter( 'camptix_form_register_complete_attendee_object', array( $this, 'add_attendee_info' ), 10, 3 );
			add_action( 'camptix_checkout_update_post_meta', array( $this, 'save_attendee_info' ), 10, 2 );
			add_filter( 'camptix_metabox_attendee_info_additional_rows', array( $this, 'show_attendee_info' ), 10, 2 );
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
	 * Get mechant credentials
	 *
	 * @since  0.1
	 * @access public
	 * @return array
	 */
	public function get_merchant_credentials() {
		$merchant = array(
			'key_id'      => $this->options['test_key_id'],
			'key_secret'  => $this->options['test_key_secret'],
		);

		if ( ! $this->options['sandbox'] ) {
			$merchant = array(
				'key_id'      => $this->options['live_key_id'],
				'key_secret'  => $this->options['live_key_secret'],
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
				if ( 'payment_cancel' == $_GET['tix_action'] ) {
					// $this->payment_cancel();
				}

				if ( 'payment_return' == $_GET['tix_action'] ) {
					$this->payment_return();
				}

				if ( 'payment_notify' == $_GET['tix_action'] ) {
					// $this->payment_notify();
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
		/* @var  CampTix_Plugin $camptix */
		global $camptix;

		if ( ! $payment_token || empty( $payment_token ) ) {
			return;
		}

		$return_url = add_query_arg( array(
			'tix_action'         => 'payment_return',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => $this->id,
		), $camptix->get_tickets_url() );

		$info       = $this->get_order( $payment_token );
		$extra_info = array(
			'fullname'       => trim( get_post_meta( $info['attendee_id'], 'tix_first_name', true ) . ' ' . get_post_meta( $info['attendee_id'], 'tix_last_name', true ) ),
			'email'          => get_post_meta( $info['attendee_id'], 'tix_email', true ),
			'phone'          => get_post_meta( $info['attendee_id'], 'tix_phone', true ),
			'total_in_paisa' => ( $info['total'] * 100 ),
			'return_url'     => $return_url,
			'popup_title'    => $this->options['razorpay_popup_title'],
		);

		wp_send_json_success( array_merge( $info, $extra_info ) );
	}

	/**
	 *
	 */
	function payment_return() {
		/* @var  CampTix_Plugin $camptix */
		global $camptix;

		// Set logs.
		$this->log( sprintf( 'Running payment_return. Request data attached.' ), null, $_REQUEST );
		$this->log( sprintf( 'Running payment_return. Server data attached.' ), null, $_SERVER );

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		// Bailout.
		if ( empty( $payment_token ) ) {
			return;
		}

		// Get all attendees for order.
		$attendees = get_posts(
			array(
				'posts_per_page' => 1,
				'post_type'      => 'tix_attendee',
				'post_status'    => array( 'draft', 'pending', 'publish', 'cancel', 'refund', 'failed' ),
				'meta_query'     => array(
					array(
						'key'     => 'tix_payment_token',
						'compare' => '=',
						'value'   => $payment_token,
						'type'    => 'CHAR',
					),
				),
			)
		);

		// Bailout.
		if ( empty( $attendees ) ) {
			return;
		}

		// Reset attendees.
		$attendee = reset( $attendees );

		// Complete payment
		$camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $_GET );

		// Show ticket to attendee.
		$access_token = get_post_meta( $attendee->ID, 'tix_access_token', true );
		$url          = add_query_arg( array(
			'tix_action'       => 'access_tickets',
			'tix_access_token' => $access_token,
		), $camptix->get_tickets_url() );

		// Redirect to ticket page.
		wp_safe_redirect( esc_url_raw( $url . '#tix' ) );

		exit();
	}

	/**
	 * @return bool
	 */
	public function is_gateway_enable() {
		return isset( $this->camptix_options['payment_methods'][ $this->id ] );
	}
}


