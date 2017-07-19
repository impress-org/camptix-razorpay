<?php
/**
 * Plugin Name: CampTix RazorPay Payment Gateway
 * Plugin URI: https://github.com/WordImpress/CampTix-Razorpay
 * Description: RazorPay Payment Gateway for CampTix
 * Author: WordImpress
 * Author URI: https://wordimpress.com:
 * Version: 0.3
 * Text Domain: camptix-razorpay
 * Domain Path: /languages
 * GitHub Plugin URI: https://github.com/WordImpress/Give-Razorpay
 */

// Exit if access directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class CampTix_RazorPar {
	/**
	 * Instance.
	 *
	 * @since
	 * @access static
	 * @var
	 */
	static private $instance;

	/**
	 * Singleton pattern.
	 *
	 * @since
	 * @access private
	 *
	 * @param CampTix_RazorPar .
	 */
	private function __construct() {
	}


	/**
	 * Get instance.
	 *
	 * @since
	 * @access static
	 * @return static
	 */
	static function get_instance() {
		if ( null === static::$instance ) {
			self::$instance = new static();
		}

		return self::$instance;
	}


	/**
	 * Setup
	 *
	 * @since 0.1
	 * @access public
	 */
	public function setup() {
		add_action( 'plugins_loaded', array( $this, 'setup_constants' ) );
		add_action( 'plugins_loaded', array( $this, 'setup_hooks' ) );
	}


	/**
	 * Setup constants
	 *
	 * @since 0.1
	 * @access public
	 */
	public function setup_constants() {
		define( 'CAMPTIX_RAZORPAY_VERSION', 0.2 );
		define( 'CAMPTIX_RAZORPAY_DIR', plugin_dir_path( __FILE__ ) );
		define( 'CAMPTIX_RAZORPAY_URL', plugin_dir_url( __FILE__ ) . '/' );
	}


	/**
	 * Setup hooks
	 *
	 * @since 0.1
	 * @access public
	 */
	public function setup_hooks() {
		add_filter( 'camptix_currencies', array( $this, 'add_inr_currency' ) );
		add_action( 'camptix_load_addons', array( $this, 'load_payment_method' ) );
	}

	/**
	 * Add INR currency
	 *
	 * @since  0.1
	 * @access public
	 *
	 * @param $currencies
	 *
	 * @return mixed
	 */
	public function add_inr_currency( $currencies ) {
		if ( ! in_array( 'INR', $currencies ) ) {
			$currencies['INR'] = array(
				'label'  => __( 'Indian Rupees', 'camptix-razorpay' ),
				'format' => 'Rs. %s',
			);
		}

		return $currencies;
	}

	/**
	 * Load the RazorPay Payment Method
	 *
	 * @since  0.1
	 * @access public
	 */
	function load_payment_method() {
		if ( ! class_exists( 'CampTix_Payment_Method_RazorPay' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'inc/class-camptix-payment-method-razorpay.php';
		}
		camptix_register_addon( 'CampTix_Payment_Method_RazorPay' );
	}
}

// Initialize plugin.
CampTix_RazorPar::get_instance()->setup();
