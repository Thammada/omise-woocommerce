<?php
defined( 'ABSPATH' ) or die( 'No direct script access allowed.' );

function register_omise_fbbot() {
	require_once dirname( __FILE__ ) . '/class-omise-payment.php';

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	if ( class_exists( 'Omise_Payment_FBBot' ) ) {
		return;
	}

	class Omise_Payment_FBBot extends Omise_Payment {
		private static $instance;
		
		public function __construct() {
			parent::__construct();

			$this->payment_page_url = "pay-on-messenger";
			$this->payment_purchase_complete_url = "complete-payment";
			$this->payment_error_url = "pay-on-messenger-error";
			$this->omise_3ds      = $this->get_option( 'omise_3ds', false ) == 'yes';

			add_action( 'wp_enqueue_scripts', array( $this, 'omise_assets' ) );

			add_filter( 'the_posts', array( $this, 'payment_page_detect' ) );
			add_filter( 'query_vars', array( $this, 'parameter_queryvars' ) );
		}

		public function omise_assets() {
			wp_enqueue_style( 'omise-css', plugins_url( '../../assets/css/omise-css.css', __FILE__ ), array(), OMISE_WOOCOMMERCE_PLUGIN_VERSION );

			wp_enqueue_script( 'omise-js', 'https://cdn.omise.co/omise.js', array( 'jquery' ), OMISE_WOOCOMMERCE_PLUGIN_VERSION, true );

			wp_enqueue_script( 'omise-util', plugins_url( '../../assets/javascripts/omise-util.js', __FILE__ ), array( 'omise-js' ), OMISE_WOOCOMMERCE_PLUGIN_VERSION, true );

      		wp_enqueue_script( 'omise-payment-on-messenger-form-handler', plugins_url( '../../assets/javascripts/omise-payment-on-messenger-form-handler.js', __FILE__ ), array( 'omise-js', 'omise-util' ), OMISE_WOOCOMMERCE_PLUGIN_VERSION, true );

      		wp_localize_script( 'omise-payment-on-messenger-form-handler', 'omise_params', array(
    			'key'       => $this->public_key()
      		 ) );
		}

		public static function get_instance() {
			if ( ! self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		public function parameter_queryvars( $qvars ) {
			$qvars[] = 'product_id';
			$qvars[] = 'messenger_id';
    		$qvars[] = 'error_message';

			return $qvars;
		}

		public function payment_page_detect( $posts ) {
			global $wp;
	    	global $wp_query;
			global $payment_page_detect;

			$this->is_omise_payment_page();
	    
		    if ( $this->is_omise_purchase_complete_page() ) {
		      	$image_url = site_url() . '/wp-content/plugins/omise-woocommerce/assets/images/omise_logo.png';
		      	$url = 'https://www.messenger.com/closeWindow/?image_url=' . $image_url . '&display_text=THANKS%20FOR%20PURCHASE';
		      	if ( wp_redirect( $url ) ) {
		        	exit;
		      	}

		      	return $posts;
		    }

		    // Create custom page
		    $post = new stdClass;

		    if ( $this->is_omise_payment_page() ) {
		    	$post->post_title = __( 'Your order' );
		    	$post->post_content = $this->payment_page_render();

		    } else if ( $this->is_omise_payment_error_page() ) {
		      	$post->post_title = __( 'System error' );
		      	$post->post_content = $this->payment_error_page_render();
		    }

		    if ( $this->is_accessible() ) {
				// Create cumtom page content
				$post->post_author = 1;
				$post->post_name = strtolower( $wp->request );
				$post->guid = get_bloginfo( 'wpurl' ) . '/' . strtolower( $wp->request );
				$post->ID = -1;
				$post->post_type = 'page';
				$post->post_status = 'static';
				$post->comment_status = 'closed';
				$post->ping_status = 'open';
				$post->comment_count = 0;
				$post->post_date = current_time( 'mysql' );
				$post->post_date_gmt = current_time( 'mysql', 1 );
				$posts = array();
				$posts[] = $post;

				// make wp_query
				$wp_query->is_page = true;
				$wp_query->is_singular = true;
				$wp_query->is_home = false;
				$wp_query->is_archive = false;
				$wp_query->is_category = false;
				unset( $wp_query->query["error"] );
				$wp_query->query_vars["error"] = "";
				$wp_query->is_404 = false;
		    }

	    	return $posts;
		}

		public function payment_page_render () {
			global $wp_query;

			if ( ! isset( $wp_query->query_vars['product_id'] ) || ! isset( $wp_query->query_vars['messenger_id'] ) ) {
				return '<strong>404 Your order not found</strong>';
			}

			$product_id = $wp_query->query_vars['product_id'];
			$messenger_id = $wp_query->query_vars['messenger_id'];

			$url = plugin_dir_path( dirname( __DIR__ ) ) . 'templates/fbbot/payment-form.php';
			ob_start();
			include( $url );
			return ob_get_clean();
		}

		public function payment_error_page_render () {
			global $wp_query;

			if ( ! isset( $wp_query->query_vars['error_message'] ) ) {
			  return '<strong>Woocommerce system has error. Please try again.</strong>';
			}

			$error_message = $wp_query->query_vars['error_message'];

			return '<strong>' . $error_message . '</strong>';
		}

		public function process_payment_by_bot( $params ) {
		    /* rearrange step
		      1. receive post data from payment page
		      2. Create wc order
		      3. Create charge 
		      4. update order status
		    */

		    $omise_token = $params['omise_token'];
		    $product_id = $params['product_id'];
		    $messenger_id = $params['messenger_id'];

		    $order = Omise_FBBot_WooCommerce::create_order( $product_id, $messenger_id );

		    $metadata = array(
				'source' => 'woo_omise_bot',
				'product_id' => $product_id,
				'messenger_id' => $messenger_id,
				'order_id' => $order->get_order_number()
		    );

		    $data = array(
				'amount'      => $this->format_amount_subunit( $order->get_total(), $order->get_currency() ),
				'currency'    => $order->get_currency(),
				'description' => 'OrderID is '.$order->get_id().' : This order created from Omise FBBot and CustomerID is '.$messenger_id,
				'metadata' => $metadata,
				'card' => $omise_token
		    );

		    if ( $this->omise_3ds ) {
		    	$return_uri =  site_url() . '/complete-payment';

		      	$data['return_uri'] = $return_uri;
		    }

		    // Create Charge
		    try {
		      	$charge = OmiseCharge::create( $data, '', $this->secret_key() );
		      	// We move checking charge status to request handler in handle triggered from omise method

		      	// Just sent message to user for let them know we received these order
		      	$prepare_confirm_message = Omise_FBBot_Conversation_Generator::prepare_confirm_order_message( $order->get_order_number() );
		      	$response = Omise_FBBot_HTTPService::send_message_to( $messenger_id, $prepare_confirm_message );

		      	// If merchant enable 3ds mode
		      	if ( isset ( $charge['authorize_uri'] ) ) {
		        	if ( wp_redirect( $charge['authorize_uri'] ) ) {
		          		error_log( 'redirect to -> '. $charge['authorize_uri'] );
		          		exit;
		        	}
		        
		        	return;
		      	}

		      	// If merchant disable 3ds mode : normal mode
		      	$redirect_uri =  site_url() . '/complete-payment';
		      	if ( wp_redirect( $redirect_uri ) ) {
		          	exit;
		      	}

		    } catch (Exception $e) {
		      	error_log("catch error : " . $e->getMessage());

		      	$error_message = str_replace(" ", "%20", $e->getMessage());

		      	// [WIP] - Redirect to error page
		      	$redirect_uri =  site_url() . '/pay-on-messenger-error/?error_message=' . $error_message;
		      	if ( wp_redirect( $redirect_uri ) ) {
		          	exit;
		      	}
		    }
  		}

  		private function is_omise_payment_page() {
			global $wp;

			if ( strtolower( $wp->request ) == $this->payment_page_url ) {
				return true;
			}

			return false;
		}

		private function is_omise_payment_error_page() {
			global $wp;
			
			if ( strtolower( $wp->request ) == $this->payment_error_url ) {
				return true;
			}

			return false;
		}

		private function is_omise_purchase_complete_page() {
			global $wp;
			
			if ( strtolower( $wp->request ) == $this->payment_purchase_complete_url ) {
				return true;
			}

			return false;
		}

		private function is_accessible() {
			global $wp;

			if ( strtolower( $wp->request ) == $this->payment_page_url || strtolower( $wp->request ) == $this->payment_error_url ) {
				return true;
			}

			return false;
		}
	}

	if ( ! function_exists( 'add_omise_fbbot' ) ) {
		Omise_Payment_FBBot::get_instance();
	}
}