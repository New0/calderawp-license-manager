<?php
/**
 * BlackBriar.
 *
 * @package   BlackBriar
 * @author    David <david@digilab.co.za>
 * @license   GPL-2.0+
 * @link      
 * @copyright 2015 David <david@digilab.co.za>
 */

/**
 * Plugin class.
 * @package BlackBriar
 * @author  David <david@digilab.co.za>
 */
class BlackBriar {

	/**
	 * The slug for this plugin
	 *
	 * @since 1.0.0
	 *
	 * @var      string
	 */
	protected $plugin_slug = 'blackbriar';

	/**
	 * Holds class isntance
	 *
	 * @since 1.0.0
	 *
	 * @var      object|BlackBriar
	 */
	protected static $instance = null;

	/**
	 * Holds the registered products
	 *
	 * @since 1.0.0
	 *
	 * @var      array
	 */
	protected $products = array();

	/**
	 * Holds the option screen prefix
	 *
	 * @since 1.0.0
	 *
	 * @var      string
	 */
	protected $plugin_screen_hook_suffix = null;

	/**
	 * Initialize the plugin by setting localization, filters, and administration functions.
	 *
	 * @since 1.0.0
	 *
	 * @access private
	 */
	private function __construct() {
		
		// Load plugin text domain
		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );

		// load licensing
		add_action( 'admin_init', array( $this, 'setup_updates' ) );		

		// Activate plugin when new blog is added
		add_action( 'wpmu_new_blog', array( $this, 'activate_new_site' ) );

		// Load admin style sheet and JavaScript.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_stylescripts' ) );

		// add license
		add_filter( 'blackbriar_get_single', array( $this, 'populate_plugins_themes') );

		// add edd update check
		add_action( 'blackbriar_setup_update_check-edd', array( $this, 'edd_update_setup' ) );

		// add foo update check
		add_action( 'blackbriar_setup_update_check-foo', array( $this, 'foo_update_setup' ) );

		// add ajax action for checking a license
		add_action( 'wp_ajax_blkbr_check_license', array( $this, 'check_license') );
		// add EDD actions
		add_action( 'blackbriar_validate_key-edd', array( $this, 'check_edd_license') );
		add_action( 'blackbriar_validate_key-foo', array( $this, 'check_foo_license') );
		// activate EDD license key		
		add_action( 'wp_ajax_blkbr_activate_edd_license', array( $this, 'activate_edd_license') );
		// deactivate EDD license key		
		add_action( 'wp_ajax_blkbr_deactivate_edd_license', array( $this, 'deactivate_edd_license') );
		// deactivate Foo license key		
		add_action( 'wp_ajax_blkbr_deactivate_foo_license', array( $this, 'deactivate_foo_license') );
	}

	public function check_license(){

		$data = stripslashes_deep( $_POST );
		$item 	= $data['item'];

		if( isset( $this->products[ $item ] ) ){
			$product = $this->products[ $item ];
			$product['license'] = $data['_value'];

			do_action( 'blackbriar_validate_key', $product );
			do_action( 'blackbriar_validate_key-' . $product['updater'], $product );

		}else{
			echo '<div class="notice notice-error"><p>' . __( 'Plugin is not active. Please activate the plugin to update the license status.', 'blackbriar' ) . '</p></div>';
		}

		exit;

	}

	/**
	 * check a license key via ajax
	 *
	 * @uses "wp_ajax_blkbr_check_license" hook
	 *
	 * @since 0.0.1
	 */
	public function check_edd_license(){

		$store_url = $_POST['url'];
		$item_name = $_POST['item'];
		$license = trim( $_POST['_value'] );
		$api_params = array(
			'edd_action' => 'check_license',
			'license' => $license,
			'item_name' => urlencode( $item_name )
		);

		// make transient of key.
		if( !empty( $_POST['autoload'] ) ){
			$license_data = get_transient( sanitize_key( $license ) );
		}

		if( empty( $license_data ) ){
			$response = wp_remote_get( add_query_arg( $api_params, $store_url ), array( 'timeout' => 15, 'sslverify' => false ) );

			if ( is_wp_error( $response ) ){
				echo '<div class="notice notice-error"><p>' . $response->get_error_message() . '</p></div>';
				exit;
			}

			$license_data = json_decode( wp_remote_retrieve_body( $response ) );

			set_transient( sanitize_key( $license ), $license_data, 18000 ); // 5 min transient on autoloads

		}

		// quick hook to save if active
		if( is_object( $license_data ) ){

			$item = urldecode( $license_data->item_name );
			$settings = BlackBriar::get_instance();

			if( !empty( $settings->products[ $item ]['key_store'] ) ){
				if( $license_data->license == 'valid' ){
					update_option( $settings->products[ $item ]['key_store'], $license );
				}else{
					delete_option( $settings->products[ $item ]['key_store'], $license );
				}
			}
		}

		$this->print_edd_response( $license_data, $api_params );

		exit;
	}

	/**
	 * activate a license key via ajax
	 *
	 * @uses "wp_ajax_blkbr_check_license" hook
	 *
	 * @since 0.0.1
	 */
	public function activate_edd_license(){

		$store_url = trailingslashit( $_POST['url'] );
		$item_name = trim( $_POST['item'] );
		$license = trim( $_POST['key'] );
			

		// data to send in our API request
		$api_params = array( 
			'edd_action'=> 'activate_license',
			'license' 	=> urlencode( $license ), 
			'item_name' => urlencode( $item_name ),
			'url'       => urlencode( home_url() )
		);
		$url = add_query_arg( $api_params, $store_url );

		// Call the custom API.
		$response = wp_remote_get( $url, array( 'timeout' => 15, 'sslverify' => false ) );

		// make sure the response came back okay
		if ( is_wp_error( $response ) ){
			echo '<div class="notice notice-error"><p>' . $response->get_error_message() . '</p></div>';
			exit;
		}

		// decode the license data
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );
		
		// quick hook to save if yes
		if( is_object( $license_data ) and !empty( $license_data->success ) ){
			
			$item = urldecode( $license_data->item_name );
			$settings = BlackBriar::get_instance();

			if( !empty( $settings->products[ $item ]['key_store'] ) ){
				update_option( $settings->products[ $item ]['key_store'], $license );
			}
		}

		$this->print_edd_response( $license_data, $api_params );

		exit;
	}

	/**
	 * deactivate a license key via ajax
	 *
	 * @uses "wp_ajax_blkbr_check_license" hook
	 *
	 * @since 0.0.1
	 */
	public function deactivate_edd_license(){

		$store_url = trailingslashit( $_POST['url'] );
		$item_name = trim( $_POST['item'] );
		$license = trim( $_POST['key'] );
			

		// data to send in our API request
		$api_params = array( 
			'edd_action'=> 'deactivate_license',
			'license' 	=> urlencode( $license ), 
			'item_name' => urlencode( $item_name ),
			'url'       => urlencode( home_url() )
		);
		$url = add_query_arg( $api_params, $store_url );

		// Call the custom API.
		$response = wp_remote_get( $url, array( 'timeout' => 15, 'sslverify' => false ) );

		// make sure the response came back okay
		if ( is_wp_error( $response ) ){
			echo '<div class="notice notice-error"><p>' . $response->get_error_message() . '</p></div>';
			exit;
		}

		// decode the license data
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		// quick hook to remove the key
		if( is_object( $license_data ) and !empty( $license_data->success ) ){
			
			$item = urldecode( $license_data->item_name );
			$settings = BlackBriar::get_instance();
			
			if( !empty( $settings->plugin[ $item ]['key_store'] ) ){
				delete_option( $settings->plugin[ $item ]['key_store'], $license );
			}
		}


		$this->print_edd_response( $license_data, $api_params );

		exit;
	}

	/**
	 * prints the response data
	 *
	 * @uses "wp_ajax_blkbr_check_license" hook
	 *
	 * @since 0.0.1
	 */
	public function print_edd_response( $license_data, $api_params ){

		if( !is_object( $license_data ) || $license_data->license == 'invalid' || $license_data->license == 'item_name_mismatch' ) {
			echo '<div class="notice notice-error"><p>' . __( 'Invalid Key', 'blackbriar' ) . '</p></div>';
			exit;
		// this license is no longer valid
		}else{

			if( $license_data->license == 'deactivated' ){
				// deactivated
				echo '<button type="button" class="button wp-baldrick" data-autoload="true" data-remove-element=".' . esc_attr( $_POST['id'] ) . '"></button>';
				echo '<span data-autoload="true" data-before="blkbr_get_config_object" data-load-element="#blackbriar-save-indicator" data-callback="blkbr_handle_save" data-action="blkbr_save_config" class="wp-baldrick"></span>';
				exit;
			}

			$class = 'notice-warning';
			if( $license_data->activations_left === 'unlimited' || $license_data->activations_left > 0 || $license_data->license == 'valid' ){
				$class = 'notice-success';
			}

			echo '<div class="notice ' . $class . '"><div style="padding:6px 6px 6px 0;">';
			
				echo '<span style="width: 85px; display: inline-block;">' . __( 'Licensed To', 'blackbriar' ) . '</span><strong>' . $license_data->customer_name . '</strong> <small style="display:inline; opacity:.7;" class="description">' . $license_data->customer_email . '</small><br>';
				echo '<span style="width: 85px; display: inline-block;">' . __( 'Activations', 'blackbriar' ) . '</span><strong>' . $license_data->site_count . '</strong><br>';
				echo '<span style="width: 85px; display: inline-block;">' . __( 'Remaining', 'blackbriar' ) . '</span><strong>' . ucwords( $license_data->activations_left ) . '</strong><br>';
				echo '<span style="width: 85px; display: inline-block;">' . __( 'Expires', 'blackbriar' ) . '</span><strong>' . date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $license_data->expires ) ) . '</strong><br>';
				
				
					echo '<div class="notice-footer" style="margin: 6px -18px -7px -12px; padding: 6px 12px; background: rgb(242, 242, 242) none repeat scroll 0% 0%; border-top: 1px solid rgb(223, 223, 223);">';
					
					if( ( $license_data->activations_left === 'unlimited' || (int) $license_data->activations_left > 0 ) && $license_data->license != 'valid' ){
						echo '<button type="button" class="button wp-baldrick" data-id="' . esc_attr( $_POST['id'] ) . '" data-load-element="#key-loading-' . esc_attr( $_POST['id'] ) . '" data-key="' . esc_attr( $api_params['license'] ) . '" data-active-class="disabled" data-target="#license-info-' . esc_attr( $_POST['id'] ) . '" data-item="' . esc_attr( $api_params['item_name'] ) . '" data-url="' . $_POST['url'] . '" data-action="blkbr_activate_edd_license" data-name="' . esc_attr( $_POST['name'] ) . '">' . __( 'Activate license', 'blackbriar' ) . '</button>';
					}else{
						if( $license_data->license == 'valid' ){
							if( empty( $_POST['autoload'] ) ){
								echo '<input type="hidden" name="' . $_POST['name'] . '" value="' . date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $license_data->expires ) ) . '">';
								echo '<span data-autoload="true" data-before="blkbr_get_config_object" data-load-element="#blackbriar-save-indicator" data-callback="blkbr_handle_save" data-action="blkbr_save_config" class="wp-baldrick"></span>';
							}
							echo '<button type="button" data-confirm="' . esc_attr( __( 'Remove this license?', 'blackbriar' ) ) . '"  class="button wp-baldrick" data-id="' . esc_attr( $_POST['id'] ) . '" data-load-element="#key-loading-' . esc_attr( $_POST['id'] ) . '" data-key="' . esc_attr( $api_params['license'] ) . '" data-active-class="disabled" data-target="#license-info-' . esc_attr( $_POST['id'] ) . '" data-item="' . esc_attr( $api_params['item_name'] ) . '" data-url="' . $_POST['url'] . '" data-action="blkbr_deactivate_edd_license">' . __( 'Deactivate License', 'blackbriar' ) . '</button>';
						}else{
							echo '<button type="button" class="button disabled" disabled="disabled">' . __( 'Activation limit reached', 'blackbriar' ) . '</button>';
						}

					}

					echo '</div>';

			echo '</div></div>';

			
			exit;
		}

	}


	public function check_foo_license( $plugin ){
		global $wp_version;


		$params = array(
			'body'       => array(
				'action'  => 'validate',
				'license' => $plugin['license'],
				'site'    => home_url()
			),
			'timeout' => 45,
			'user-agent' => 'WordPress/' . $wp_version . '; FooLicensing'
		);
		if( !empty( $_POST['autoload'] ) ){
			$license_data = get_transient( sanitize_key( $plugin['license'] ) );
		}
		if( empty( $license_data ) ){
			$response_raw = wp_remote_post( $plugin['url'] , $params );

			if (is_wp_error($response_raw)) {
				$error = $response_raw->get_error_message();
				$this->output_json_error(__('An error occurred while trying to validate your license key', $this->plugin_slug),
					$error);
				exit;
			} else if (wp_remote_retrieve_response_code($response_raw) != 200) {
				$this->output_json_error(__('An error occurred while trying to validate your license key', $this->plugin_slug),
					sprintf(__('The response code of [%s] was not expected', $this->plugin_slug), wp_remote_retrieve_response_code($response_raw)));
				exit;
			}
			$license_data = json_decode( wp_remote_retrieve_body( $response_raw ) );
			set_transient( sanitize_key( sanitize_key( $plugin['license'] ) ), $license_data, 18000 ); // 5 min transient on autoloads
		}
		

		if( is_object( $license_data ) ){
			if( !empty( $license_data->response->valid ) ){
				//var_dump( $license_data );
				//die;
				update_site_option( $plugin['slug'] . '_licensekey', $plugin['license'] );
				echo '<div class="notice notice-success"><div style="padding:6px 6px 6px 0;">';

					echo '<span style="width: 85px; display: inline-block;">' . __( 'Licensed For', 'blackbriar' ) . '</span><strong>' . $license_data->site . '</strong><br>';
					echo '<span style="width: 85px; display: inline-block;">' . __( 'Activations', 'blackbriar' ) . '</span><strong>' . count( $license_data->domains ) . '</strong><br>';
					echo '<span style="width: 85px; display: inline-block;">' . __( 'Expires', 'blackbriar' ) . '</span><strong>' . $license_data->expires . '</strong><br>';
					
					echo __( 'Detach at', 'blackbriar' ) . ' <a href="https://fooplugins.com/licenses/" target="_blank">https://fooplugins.com/licenses/</a> before removing license.';

						echo '<div class="notice-footer" style="margin: 6px -18px -7px -12px; padding: 6px 12px; background: rgb(242, 242, 242) none repeat scroll 0% 0%; border-top: 1px solid rgb(223, 223, 223);">';

						if( empty( $_POST['autoload'] ) ){
							echo '<input type="hidden" name="' . $_POST['name'] . '" value="' . date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $license_data->expires ) ) . '">';
							echo '<span data-autoload="true" data-before="blkbr_get_config_object" data-load-element="#blackbriar-save-indicator" data-callback="blkbr_handle_save" data-action="blkbr_save_config" class="wp-baldrick"></span>';
						}
						echo '<button type="button" data-confirm="' . esc_attr( __( 'Remove this license?', 'blackbriar' ) ) . '"  class="button wp-baldrick" data-id="' . esc_attr( $_POST['id'] ) . '" data-load-element="#key-loading-' . esc_attr( $_POST['id'] ) . '" data-key="' . esc_attr( $plugin['license'] ) . '" data-active-class="disabled" data-target="#license-info-' . esc_attr( $_POST['id'] ) . '" data-item="' . esc_attr( $plugin['name'] ) . '" data-url="' . $_POST['url'] . '" data-action="blkbr_deactivate_foo_license">' . __( 'Remove License', 'blackbriar' ) . '</button>';
						

						echo '</div>';

				echo '</div></div>';
			}else{
				delete_site_option( $plugin['slug'] . '_licensekey' );
				echo '<div class="notice notice-error"><p>' . $license_data->response->message . '</p></div>';
			}
		}

		exit;

	}
	/**
	 * deactivate a foo license key via ajax
	 *
	 * @uses "wp_ajax_blkbr_check_license" hook
	 *
	 * @since 0.0.1
	 */
	public function deactivate_foo_license(){

		$item_name = trim( $_POST['item'] );

		delete_site_option( $plugin['slug'] . '_licensekey', $plugin['license'] );

		echo '<button type="button" class="button wp-baldrick" data-autoload="true" data-remove-element=".' . esc_attr( $_POST['id'] ) . '"></button>';
		echo '<span data-autoload="true" data-before="blkbr_get_config_object" data-load-element="#blackbriar-save-indicator" data-callback="blkbr_handle_save" data-action="blkbr_save_config" class="wp-baldrick"></span>';


		exit;
	}
	public function setup_updates(){

		
		if( !empty( $this->products ) ){
			$plugins = $this->products;
		
			foreach( $plugins as $plugin_key=>$plugin ){

				if( empty( $plugin['updater'] ) ){
					continue;
				}


				if( ! class_exists( 'foolic_update_checker_v1_5' ) && $plugin['updater'] == 'foo' ){
					require_once( BLKBR_PATH . "classes/class-updater-foo.php" );
				}

				// do actions
				do_action( 'blackbriar_setup_update_check', $plugin );
				do_action( 'blackbriar_setup_update_check-' . $plugin['updater'], $plugin );

			}

		}



	}

	public function edd_update_setup( $plugin ){
		// include the updater
		if( ! class_exists( 'EDD_SL_Plugin_Updater' ) ){
			require_once( BLKBR_PATH . "classes/class-updater-edd.php" );
		}
		//get the key
		$plugin['license_key'] = trim( get_option( $plugin['key_store'] ) ); 
		// setup the updater
		new EDD_SL_Plugin_Updater( $plugin['url'], $plugin['file'], array(
			'version'	=> $plugin['version'],
			'license'	=> $plugin['license_key'],
			'item_name'	=> $plugin['name'],
			'url'		=> home_url()
		) );
	}

	public function foo_update_setup( $plugin ){
		// foo requires a slug
		if( empty( $plugin['slug'] ) ){
			return;
		}
		// include the updater
		if( ! class_exists( 'foolic_update_checker_v1_5' ) ){
			require_once( BLKBR_PATH . "classes/class-updater-foo.php" );
		}
		// get the key
		$plugin['license_key'] = get_site_option( $plugin['slug'] . '_licensekey' );
		// setup the updater
		//initialize plugin update checks with fooplugins.com
		new foolic_update_checker_v1_5(
			$plugin['file'], //the plugin file
			$plugin['url'], //the URL to check for updates
			$plugin['slug'], //the plugin slug
			$plugin['license_key']
		);
	}


	public function populate_plugins_themes( $config ){
		
		if( !empty( $this->products ) ){
			$config['plugins'] = $this->products;
			ksort( $config['plugins'] );
		}
		if( !empty( $this->products['theme'] ) ){
			$config['themes'] = $this->products['theme'];
			ksort( $config['themes'] );
		}

		return $config;
	}

	/**
	 * Adds a product to the register
	 *
	 * @since 1.0.0
	 *
	 */
	public function register_product( $params ) {

		// needs at least name, url and key_store
		if( empty( $params['name'] ) || empty( $params['url'] ) ){
			return;
		}

		if( !isset( $this->products[ $params['name'] ] ) ){
			$this->products[ $params['name'] ] = $params;
		}

	}

	/**
	 * Return an instance of this class.
	 *
	 * @since 1.0.0
	 *
	 * @return    object|BlackBriar    A single instance of this class.
	 */
	public static function get_instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since 1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain( $this->plugin_slug, FALSE, basename( BLKBR_PATH ) . '/languages');

	}
	
	/**
	 * Register and enqueue admin-specific style sheet.
	 *
	 * @since 1.0.0
	 *
	 * @return    null
	 */
	public function enqueue_admin_stylescripts() {

		$screen = get_current_screen();

		if( !is_object( $screen ) ){
			return;
		}

		
		
		if( false !== strpos( $screen->base, 'blackbriar' ) ){

			wp_enqueue_style( 'blackbriar-core-style', BLKBR_URL . '/assets/css/styles.css' );
			wp_enqueue_style( 'blackbriar-baldrick-modals', BLKBR_URL . '/assets/css/modals.css' );
			wp_enqueue_script( 'blackbriar-wp-baldrick', BLKBR_URL . '/assets/js/wp-baldrick-full.js', array( 'jquery' ) , false, true );
			wp_enqueue_script( 'jquery-ui-autocomplete' );
			wp_enqueue_script( 'jquery-ui-sortable' );
			wp_enqueue_style( 'blackbriar-codemirror-style', BLKBR_URL . '/assets/css/codemirror.css' );
			wp_enqueue_script( 'blackbriar-codemirror-script', BLKBR_URL . '/assets/js/codemirror.js', array( 'jquery' ) , false );
			wp_enqueue_script( 'blackbriar-core-script', BLKBR_URL . '/assets/js/scripts.js', array( 'blackbriar-wp-baldrick' ) , false );
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );			
		
		}


	}



}















