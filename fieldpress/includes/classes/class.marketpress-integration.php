<?php

/**
 * @copyright Incsub ( http://incsub.com/ )
 *
 * @license http://opensource.org/licenses/GPL-2.0 GNU General Public License, version 2 ( GPL-2.0 )
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston,
 * MA 02110-1301 USA
 *
 */
if ( ! class_exists( 'FieldPress_MarketPress3_Integration' ) ) {

	/**
	 * FieldPress class for integrating with other plugins
	 *
	 * @since 1.2.6.1
	 *
	 */
	class FieldPress_MarketPress_Integration {

		private static $mp_base = false;
		private static $updated = false;
		private static $field_id = 0;
		private static $product_ctp = 'product';
		private static $looping = false;

		public static function init() {

			add_action( 'admin_init', array( __CLASS__, 'begin_integration' ) );
			add_action( 'init', array( __CLASS__, 'begin_integration' ) );

		}

		public static function begin_integration() {

			if ( ! self::is_active() ) {
				return false;
			}

			if ( false === self::$mp_base ) {
				self::$mp_base = self::get_base();
			}

			// General hooks
			add_action( 'fieldpress_field_created', array( __CLASS__, 'update_product_from_field' ) );
			add_action( 'fieldpress_field_updated', array( __CLASS__, 'update_product_from_field' ) );
			add_action( 'fieldpress_mp_update_product', array( __CLASS__, 'maybe_create_product' ) );
			add_action( 'post_updated', array( __CLASS__, 'update_field_from_product' ), 10, 3 );
			add_filter( 'fieldpress_shortcode_field_cost', array( __CLASS__, 'shortcode_cost' ), 10, 2 );
			add_action( 'fieldpress_general_options_page', array( __CLASS__, 'add_mp_general_option' ) );
			add_action( 'fieldpress_update_settings', array( __CLASS__, 'save_mp_general_option' ), 10, 2 );

			// Enable Payment Support
			add_filter( 'fieldpress_offer_paid_fields', array( __CLASS__, 'enable_payment' ) );

			// Specific hooks
			switch ( self::$mp_base ) {
				case '3.0':
					/**
					 * Enroll upon pay
					 *
					 * Reference to order ID, will need to get the actual product using the MarketPress Order class
					 */
					add_action( 'mp_order_order_paid', array( __CLASS__, 'field_paid_3pt0' ) );

					/**
					 * Override thumbnail placeholder with field list image.
					 * Note: Typically field products won't have thumbnails, but if a product image is set, this filter
					 * will not override the set product image.
					 */
					add_filter( 'mp_product_image_show_placeholder', array(
						__CLASS__,
						'placeholder_to_field_image'
					), 10, 2 );

					/**
					 * Return field list image as product image for: `mp_product_images` meta
					 */
					add_filter( 'get_post_metadata', array( __CLASS__, 'field_product_images_meta' ), 10, 4 );


					add_filter( 'mp_order/notification_subject', array(
						__CLASS__,
						'cp_mp_order_notification_subject',
						10,
						2
					) );
					add_filter( 'mp_order/notification_body', array(
						__CLASS__,
						'cp_mp_order_notification_body',
						10,
						2
					) );

					add_filter( 'mp_meta/product', array( __CLASS__, 'verify_meta' ), 10, 3 );

					add_filter( 'mp_product/on_sale', array( __CLASS__, 'fix_mp3_on_sale'), 10, 2 );

					// Fix missing MP3.0 meta fields
					add_filter( 'wpmudev_field/get_value/sku', array( __CLASS__, 'fix_mp3_sku' ), 10, 4 );
					add_filter( 'wpmudev_field/get_value/regular_price', array( __CLASS__, 'fix_mp3_regular_price' ), 10, 4 );
					add_filter( 'wpmudev_field/get_value/has_sale', array( __CLASS__, 'fix_mp3_has_sale' ), 10, 4 );
					add_filter( 'wpmudev_field/get_value/sale_price[amount]', array( __CLASS__, 'fix_mp3_sale_price_amount' ), 10, 4 );
					add_filter( 'wpmudev_field/get_value/file_url', array( __CLASS__, 'fix_mp3_file_url' ), 10, 4 );

					self::$product_ctp = MP_Product::get_post_type();

					break;

				case '2.0':

					add_action( 'mp_new_order', array( __CLASS__, 'listen_for_paid_status_for_fields_2pt0' ) );
					add_action( 'mp_order_paid', array(
						__CLASS__,
						'listen_for_paid_status_changes_for_fields_2pt0'
					) );

					add_filter( 'mp_order_notification_subject', array(
						__CLASS__,
						'cp_mp_order_notification_subject',
						10,
						2
					) );
					add_filter( 'mp_order_notification_body', array(
						__CLASS__,
						'cp_mp_order_notification_body',
						10,
						2
					) );

					break;
			}


		}

		public static function enable_payment( $payment_supported ) {
			$payment_supported = true;

			return $payment_supported;
		}

		public static function is_active() {

			// Don't allow on campus
			if ( FieldPress_Capabilities::is_campus() ) {
				return false;
			}

			$plugins = get_option( 'active_plugins' );

			$activated = false;

			if ( is_multisite() ) {
				$active_sitewide_plugins = get_site_option( "active_sitewide_plugins" );
			} else {
				$active_sitewide_plugins = array();
			}

			foreach( $plugins as $plugin ) {
				$activated =  preg_match( '/marketpress.php/', $plugin ) || $activated;
			}
			foreach( $active_sitewide_plugins as $plugin => $signature ) {
				$activated =  preg_match( '/marketpress.php/', $plugin ) || $activated;
			}

			return $activated;
		}

		public static function get_base() {
			$mp_version = self::get_version();

			// Strip out any beta or RC components from version... get base version
			$mp_version    = preg_replace( '/\.\D.*/', '', $mp_version );
			self::$mp_base = version_compare( $mp_version, '3.0' ) > - 1 ? '3.0' : '2.0';

			return self::$mp_base;
		}

		public static function get_version() {
			$mp_version = false;

			if ( defined( 'MP_VERSION' ) ) {
				$mp_version = MP_VERSION;
			} else {
				global $mp_version;
			}

			return $mp_version;
		}

		public static function product_settings( $content, $field_id ) {

			switch ( self::get_base() ) {

				case '3.0':
					return self::mp2_product_settings( $content, $field_id );
					break;


				case '2.0':
					return self::mp2_product_settings( $content, $field_id );
					break;

			}

		}

		// Return settings, this is a filter
		public static function maybe_create_product( $field_id ) {

			self::$field_id = $field_id;

			$mp_product_details = array();
			if ( isset( $field_id ) && $field_id !== 0 ) {
				$mp_product_details = get_post_custom( $field_id );
			}

			$product_id     = isset( $mp_product_details['mp_product_id'] ) ? (int) $mp_product_details['mp_product_id'][0] : false;
			$product_id     = empty( $product_id ) && isset( $mp_product_details['marketpress_product'] ) ? (int) $mp_product_details['marketpress_product'][0] : $product_id;
			$product_status = ! empty( $product_id ) ? get_post_status( $product_id ) : false;
			$product_id     = ! empty( $product_id ) && $product_status ? $product_id : false;

			$is_paid = isset( $mp_product_details['paid_field'] ) && 'on' === $mp_product_details['paid_field'][0] ? true : false;

			// Check if the corresponding product exists, if not, set product ID to false. This happens if the product "accidentally" got deleted.
			$product_id = ! empty( $product_id ) && get_post_status( $product_id ) ? $product_id : false;

			// Assume product does not exist and create one
			if ( false === $product_id && $is_paid ) {

				$field = get_post( $field_id );

				$product = array(
					'post_title'     => $field->post_title,
					'post_content'   => $field->post_content,
					'post_excerpt'   => $field->post_excerpt,
					'post_type'      => self::$product_ctp,
					'ping_status'    => 'closed',
					'comment_status' => 'closed',
					'post_status'    => 'publish'
				);

				$product_id = wp_insert_post( $product );

				// Avoid the looping
				self::$updated = true;
			}

			// If its not paid and a product doesn't exist, do nothing.
			if ( false === $product_id ) {
				return false;
			}

			self::update_product_meta( $product_id, $mp_product_details, $field_id );

			// Make sure we update the product ID in the field trip
			update_post_meta( $field_id, 'mp_product_id', $product_id );
			update_post_meta( $field_id, 'marketpress_product', $product_id );
			// Limit quantity per order to 1 by default. There are no common uses cases where this would need to be higher.
			update_post_meta( $product_id, 'per_order_limit', 1 );

			return true;
		}

		public static function update_product_meta( $product_id, $settings, $field_id ) {

			$base = self::get_base();

			$product_meta = array();
			switch ( $base ) {

				case '3.0':
					$product_meta = array(
						'sku'               => $settings['mp_sku'][0],
						'regular_price'     => $settings['mp_price'][0],
						'has_sale'          => $settings['mp_is_sale'][0],
						'sale_price_amount' => $settings['mp_sale_price'][0],
						'field_id'         => $field_id,
						'file_url'          => get_permalink( $field_id )
					);
					break;

				case '2.0':
					$product_meta = array(
						'mp_sku'        => array( $settings['mp_sku'][0] ),
						'mp_price'      => array( $settings['mp_price'][0] ),
						'mp_is_sale'    => $settings['mp_is_sale'][0],
						'mp_sale_price' => array( $settings['mp_sale_price'][0] ),
						'mp_field_id'  => $field_id,
						'mp_file'       => get_permalink( $field_id )
					);

					break;

			}

			// Create Auto SKU
			if ( ! empty( $settings['auto_sku'][0] ) || empty( $settings['mp_sku'][0] ) ) {
				$sku_prefix = apply_filters( 'fieldpress_field_sku_prefix', 'CP-' );
				$sku        = $sku_prefix . str_pad( $field_id, 5, "0", STR_PAD_LEFT );

				switch ( $base ) {

					case '3.0':
						$product_meta['sku'] = $sku;
						break;

					case '2.0':
						$product_meta['mp_sku'] = $sku;
						break;

				}

			}

			foreach ( $product_meta as $key => $value ) {
				update_post_meta( $product_id, $key, $value );
			}

		}

		public static function update_product_from_field( $field_id ) {

			self::$field_id = $field_id;

			// Avoid possible messy loop
			if ( self::$updated ) {
				self::$updated = false;

				return;
			}

			$mp_product_details = array();
			if ( isset( $field_id ) && $field_id !== 0 ) {
				$mp_product_details = get_post_custom( $field_id );
			}

			// If field status is no longer paid, but an MP ID exists, then disable the MP product (don't delete)
			$product_id     = isset( $mp_product_details['mp_product_id'] ) ? (int) $mp_product_details['mp_product_id'][0] : false;
			$product_id     = empty( $product_id ) && isset( $mp_product_details['marketpress_product'] ) ? (int) $mp_product_details['marketpress_product'][0] : $product_id;
			$product_status = ! empty( $product_id ) ? get_post_status( $product_id ) : false;
			$product_id     = ! empty( $product_id ) && $product_status ? $product_id : false;

			$is_paid = isset( $mp_product_details['paid_field'] ) && 'on' === $mp_product_details['paid_field'][0] ? true : false;

			// Update and publish
			if ( false !== $product_id && $is_paid ) {
				$post_id = $product_id;
				if ( ! empty( $product_status ) && 'publish' !== $product_status ) {
					$product       = array(
						'ID'          => $product_id,
						'post_status' => 'publish'
					);
					self::$updated = true;
					$post_id       = wp_update_post( $product );
				}

				self::update_product_meta( $post_id, $mp_product_details, $field_id );
			}

			// Update and hide
			if ( false !== $product_id && ! $is_paid ) {
				$post_id = $product_id;
				if ( ! empty( $product_status ) && 'publish' === $product_status ) {
					$product       = array(
						'ID'          => $product_id,
						'post_status' => 'draft'
					);
					self::$updated = true;
					$post_id       = wp_update_post( $product );
				}

				self::update_product_meta( $post_id, $mp_product_details, $field_id );
			}

		}

		public static function update_field_from_product( $product_id, $post, $before_update ) {

			$x = '';
			// If its not a product, exit
			if ( self::$product_ctp !== $post->post_type || ! self::is_active() ) {
				return;
			}

			// If update is caused by this class already, then bail
			if ( self::$updated ) {
				self::$updated = false;

				return;
			}

			$base = self::get_base();

			$field_id = false;
			switch ( $base ) {
				case '3.0':
					$field_id = (int) get_post_meta( $product_id, 'field_id', true );
					break;
				case '2.0':
					$field_id = (int) get_post_meta( $product_id, 'mp_field_id', true );
					break;
			}

			// No point proceeding if there is no associated field
			if ( empty( $field_id ) ) {
				return;
			}

			$sku     = $price = $sale_price = '';
			$is_sale = $is_paid = false;

			switch ( $base ) {

				case '3.0':

					$sku        = isset( $_POST['sku'] ) ? sanitize_text_field( $_POST['sku'] ) : get_post_meta( $product_id, 'sku', true );
					$sku        = is_array( $sku ) ? array_shift( $sku ) : $sku;
					$price      = isset( $_POST['regular_price'] ) ? sanitize_text_field( $_POST['regular_price'] ) : get_post_meta( $product_id, 'regular_price', true );
					$price      = is_array( $price ) ? array_shift( $price ) : $price;
					$sale_price = isset( $_POST['sale_price']['amount'] ) ? sanitize_text_field( $_POST['sale_price']['amount'] ) : get_post_meta( $product_id, 'sale_price_amount', true );
					$sale_price = is_array( $sale_price ) ? array_shift( $sale_price ) : $sale_price;
					$is_sale    = isset( $_POST['has_sale'] ) ? sanitize_text_field( $_POST['has_sale'] ) : get_post_meta( $product_id, 'has_sale', true );
					$is_paid    = 'publish' === $post->post_status;

					break;

				case '2.0':

					$sku        = get_post_meta( $product_id, 'mp_sku', true );
					$sku        = is_array( $sku ) ? array_shift( $sku ) : $sku;
					$price      = get_post_meta( $product_id, 'mp_price', true );
					$price      = is_array( $price ) ? array_shift( $price ) : $price;
					$sale_price = get_post_meta( $product_id, 'mp_sale_price', true );
					$sale_price = is_array( $sale_price ) ? array_shift( $sale_price ) : $sale_price;
					$is_sale    = get_post_meta( $product_id, 'mp_is_sale', true );
					$is_paid    = 'publish' === $post->post_status;

					break;

			}

			$meta = array(
				'mp_sku'        => $sku,
				'mp_price'      => $price,
				'mp_is_sale'    => $is_sale,
				'mp_sale_price' => $sale_price,
				'paid_field'   => $is_paid ? 'on' : 'off'
			);

			foreach ( $meta as $key => $value ) {
				update_post_meta( $field_id, $key, $value );
			}

			self::$updated = true;

		}

		public static function shortcode_cost( $content, $field_id ) {

			$product_id = get_post_meta( $field_id, 'mp_product_id', true );

			return do_shortcode( '[mp_product_price product_id="' . $product_id . '" label=""]' );

		}


		public static function mp2_product_settings( $content, $field_id ) {

			$field      = new Field( (int) $field_id );
			$paid_field = ( FieldPress_MarketPress_Integration::is_active() || cp_use_woo() ) ? $field->details->paid_field : false;

			$auto_sku    = $field->details->auto_sku;
			$mp_settings = get_option( 'mp_settings' );
			$gateways    = 0;

			$settings_gateways = (array) $mp_settings['gateways'];
			if( isset( $settings_gateways['allowed'] ) ) {
				foreach ( $settings_gateways['allowed'] as $gw => $active ) {
					$gateways += ! empty( $active ) ? 1 : 0;
				}
			}

			$gateways = $gateways > 0 ? true : false;

			$hidden_class = ! FieldPress_MarketPress_Integration::is_active() ? 'hidden' : '';

			$content = '
				<div class="cp-markertpress-is-active ' . $hidden_class . '">
			';

			if ( isset( $field_id ) && $field_id !== 0 ) {
				$mp_product_details = get_post_custom( $field_id );
			}

			$product_id    = isset( $mp_product_details['mp_product_id'] ) ? (int) $mp_product_details['mp_product_id'][0] : false;
			$product_id    = empty( $product_id ) && isset( $mp_product_details['marketpress_product'] ) ? (int) $mp_product_details['marketpress_product'][0] : $product_id;
			$mp_product_id = $product_id;

			$product_exists = 0 != $mp_product_id ? true : false;

			$paid_field = ! isset( $paid_field ) ? 'off' : $paid_field;
			$paid_field = ! $product_exists ? 'off' : $paid_field;

			$paid_field = 'off' === $paid_field && isset( $mp_product_details['paid_field'] ) ? $mp_product_details['paid_field'][0] : $paid_field;

			if ( isset( $marketpress_product ) && $marketpress_product !== '' ) {
				$marketpress_product_sku = $mp_product_details['mp_sku'][0];
			} else {
				$marketpress_product_sku = '';
			}

			$input_state = 'off' == $paid_field ? 'disabled="disabled"' : '';

			$value = ! empty( $mp_product_id ) ? $mp_product_id : '';

			$content .= '
					<input type="hidden" name="meta_mp_product_id" id="mp_product_id" value="' . esc_attr( $value ) . '"/>
			';

			$hidden_class = ( $paid_field != 'on' ) ? 'hidden' : '';

			$content .= '
					<div class="field-paid-field-details ' . $hidden_class . '">
			';

			$content .= '
						<div class="field-sku">
							<p>
								<input type="checkbox" name="meta_auto_sku" ' . ( isset( $auto_sku ) && $auto_sku == 'on' ? 'checked' : '' ) . ' ' . $input_state . '/>
								' . esc_html__( 'Automatically generate Stock Keeping Stop (SKU)', 'cp' ) . '
							</p>
							<input type="text" name="mp_sku" id="mp_sku" placeholder="CP-000001" value="' . esc_attr( isset( $marketpress_product_sku[0] ) ? $marketpress_product_sku[0] : '' ) . '" ' . $input_state . '/>
						</div>
			';

			$content .= '
						<div class="field-price">
							<span class="price-label ' . esc_attr( $paid_field == 'on' ? 'required' : '' ) . '">' . esc_html__( 'Price', 'cp' ) . '</span>
							<input type="text" name="mp_price" id="mp_price" value="' . esc_attr( isset( $mp_product_details['mp_price'][0] ) ? esc_attr( $mp_product_details['mp_price'][0] ) : '' ) . '" ' . $input_state . ' />
						</div>
						<div class="clearfix"></div>
			';

			$mp_is_sale = isset( $mp_product_details["mp_is_sale"][0] ) ? $mp_product_details["mp_is_sale"][0] : 0;
			$content .= '
						<div class="field-sale-price">
							<p>
								<input type="checkbox" id="mp_is_sale" name="mp_is_sale" value="' . esc_attr( $mp_is_sale ) . '" ' . checked( $mp_is_sale, '1', false ) . ' ' . $input_state . ' />
								' . esc_html__( 'Enabled Sale Price', 'cp' ) . '
 							</p>
							<span class="price-label ' . esc_attr( isset( $mp_product_details ) && ! empty( $mp_product_details["mp_is_sale"] ) && checked( $mp_product_details["mp_is_sale"][0], '1', false ) ? "required" : "" ) . '">' . esc_html__( 'Sale Price', 'cp' ) . '</span>
							<input type="text" name="mp_sale_price" id="mp_sale_price" value="' . ( ! empty( $mp_product_details['mp_sale_price'] ) ? esc_attr( $mp_product_details["mp_sale_price"][0] ) : 0 ) . '" ' . $input_state . ' />
						</div>
						<div class="clearfix"></div>
			';

			if ( current_user_can( 'manage_options' ) ) {

				//Try to dequeue need-help script to avoid need-help popup
				wp_dequeue_script( 'mp-need-help' );

				$gateway_url = admin_url( 'edit.php?post_type=' . self::$product_ctp . '&page=marketpress&tab=gateways&cp_admin_ref=cp_field_creation_page' );
				if ( self::get_base() === '3.0' ) {
					$gateway_url = admin_url( 'admin.php?page=store-settings-payments&cp_admin_ref=cp_field_creation_page' );
				}

				$content .= '
						<div class="field-enable-gateways ' . esc_attr( $gateways ? 'gateway-active' : 'gateway-undefined' ) . '">
							<a href="' . esc_url_raw( $gateway_url . '&TB_iframe=true&width=600&height=550' ) . '" class="button button-incomplete-gateways thickbox ' . esc_attr( $gateways ? 'hide' : '' ) . '" style="' . esc_attr( $gateways ? 'display:none' : '' ) . '">' . esc_html__( 'Setup Payment Gateways', 'cp' ) . '</a>
							<span class="payment-gateway-required ' . esc_attr( ! $gateways && $paid_field == 'on' ? 'required' : '' ) . '"></span>
							<a href="' . esc_url_raw( $gateway_url . '&TB_iframe=true&width=600&height=550' ) . '" class="button button-edit-gateways thickbox ' . esc_attr( $gateways ? '' : 'hide' ) . '" style="' . esc_attr( $gateways ? '' : 'display:none' ) . '">' . esc_html__( 'Edit Payment Gateways', 'cp' ) . '</a>
						</div>
				';

			} else {

				$content .= '<div class="field-enable-gateways gateway-active"></div>';

			}


			$content .= '
					</div>
				</div>
			';

			return $content;
		}

		public static function add_mp_general_option() {
			?>
			<div class="postbox">
				<h3 class="hndle" style='cursor:auto;'><span><?php _e( 'MarketPress', 'cp' ); ?></span></h3>

				<div class="inside">
					<table class="form-table">
						<tbody>
						<tr valign="top">
							<th scope="row"><?php _e( 'Redirect MarketPress product post to a parent field post', 'cp' ); ?></th>
							<td>
								<a class="help-icon" href="javascript:;"></a>

								<div class="tooltip">
									<div class="tooltip-before"></div>
									<div class="tooltip-button">&times;</div>
									<div class="tooltip-content">
										<?php _e( 'If checked, visitors who try to access MarketPress single post will be automatically redirected to a parent field single post.', 'cp' ) ?>
									</div>
								</div>
								<input type='checkbox' name='option_redirect_mp_to_field' <?php echo( ( get_option( 'redirect_mp_to_field', 0 ) ) ? 'checked' : '' ); ?> />
							</td>
						</tr>
						</tbody>
					</table>
				</div>
			</div>
			<?php
		}

		function save_mp_general_option( $tab, $post ) {
			if ( $tab == 'general' ) {
				if ( isset( $post[ 'option_redirect_mp_to_field' ] ) ) {
					update_option( 'redirect_mp_to_field', 1 );
				} else {
					update_option( 'redirect_mp_to_field', 0 );
				}
			}
		}

		public static function field_paid_3pt0( $order ) {

			$order_post = get_post( $order->ID );
			$cart       = $order->get_cart();
			$items      = $cart->get_items();

			foreach ( $items as $product_id => $qty ) {

				$field_id = (int) get_post_meta( $product_id, 'field_id', true );
				$user_id   = $order_post->post_author;

				// If not enrolled...
				if ( ! Student::enrolled_in_field( $field_id, $user_id ) ) {

					//Then enroll..
					Student::enroll( $field_id, $user_id );
				}

			}
		}

		/* Listen for MarketPress purchase status changes */

		public static function listen_for_paid_status_for_fields_2pt0( $order ) {
			global $mp;

			$allowed_mp_statuses = apply_filters( 'cp_allowed_purchase_status_for_enroll', array(
				'order_paid',
				'order_shipped'
			) );

			if ( in_array( $order->post_status, $allowed_mp_statuses ) ) {

				$products = array_keys( $order->mp_cart_info );
				$student  = new Student( $order->post_author );

				foreach ( $products as $product_id ) {
					$field_id = Field::get_field_id_by_marketpress_product_id( $product_id );
					if ( ! empty( $field_id ) ) {
						$student->enroll_in_field( $field_id );
					}
				}
			}
		}

		public static function listen_for_paid_status_changes_for_fields_2pt0( $order ) {
			global $mp;

			$products = array_keys( $order->mp_cart_info );
			$student  = new Student( $order->post_author );

			foreach ( $products as $product_id ) {
				$field_id = Field::get_field_id_by_marketpress_product_id( $product_id );
				if ( ! empty( $field_id ) ) {
					$student->enroll_in_field( $field_id );
				}
			}
		}

		public static function placeholder_to_field_image( $show, $post_id ) {

			$field_id = ! empty( $post_id ) ? get_post_meta( $post_id, 'field_id', true ) : 0;

			if ( ! empty ( $field_id ) ) {

				self::$field_id = $field_id;
				add_filter( 'mp_default_product_img', array( __CLASS__, 'replace_image' ) );

				return 1;
			}

			return $show;
		}

		public static function replace_image( $img_src ) {

			$featured_url = get_post_meta( self::$field_id, 'featured_url', true );

			if ( ! empty( $featured_url ) ) {
				if ( is_ssl() ) {
					$featured_url = str_replace( 'http://', 'https://', $featured_url );
				}

				$img_src = $featured_url;
			}

			return $img_src;
		}

		public static function field_product_images_meta( $value, $post_id, $meta_key, $single ) {

			if ( 'mp_product_images' === $meta_key && ! self::$looping ) {

				// Avoid looping, because we're calling this meta again.
				self::$looping = true;

				$product_images = get_post_meta( $post_id, $meta_key, $single );

				if ( empty( $product_images ) ) {
					$field_id    = ! empty( $post_id ) ? get_post_meta( $post_id, 'field_id', true ) : 0;
					$featured_url = ! empty( $field_id ) ? get_post_meta( $field_id, 'featured_url', true ) : '';
					$admin_edit   = isset( $_GET['action'] ) && 'edit' === $_GET['action'];
					$value        = ! empty( $featured_url ) && ! $admin_edit ? $featured_url : $value;
				}

				// No longer looping
				self::$looping = false;
			}

			return $value;
		}

		public static function cp_mp_order_notification_subject( $subject, $order ) {
			if ( cp_get_order_field_id( $order->ID ) ) {
				return fieldpress_get_mp_order_email_subject();
			} else {
				return $subject;
			}
		}

		public static function cp_mp_order_notification_body( $content, $order ) {

			if ( '3.0' === self::get_base() && ! is_object( $order ) && ! empty( $order ) ) {
				$order = new MP_Order( $order );
			}

			if ( cp_get_order_field_id( $order->ID ) ) {
				$field_id = cp_get_order_field_id( $order->ID );
				$field    = new Field( $field_id );

				$tags = array(
					'CUSTOMER_NAME',
					'BLOG_NAME',
					'LOGIN_ADDRESS',
					'WEBSITE_ADDRESS',
					'FIELD_ADDRESS',
					'FIELD_TITLE',
					'ORDER_ID',
					'ORDER_STATUS_URL'
				);

				$field_title   = '';
				$field_address = '';
				$order_name = '';
				$tracking_url = '';

				switch ( self::get_base() ) {

					case '3.0':
						$cart       = $order->get_cart();
						$items      = $cart->get_items();

						$order_post = get_post( $order->ID );
						$field_title   = '';
						$field_address = '';
						$order_name     = $order->get_meta( 'mp_billing_info->first_name' ) . ' ' . $order->get_meta( 'mp_billing_info->last_name' );

						$counter = 0;
						foreach ( $items as $product_id => $qty ) {
							$counter += 1;
							$field_id = (int) get_post_meta( $product_id, 'field_id', true );
							$field_title .= get_post_field( 'post_title', $field_id );
							$field_address .= get_permalink( $field_id );
							if ( count( $items ) > 0 && $counter !== count( $items ) ) {
								$field_title .= ', ';
								$field_address .= ', ';
							}
						}

						$tracking_url = apply_filters( 'wpml_marketpress_tracking_url', mp_orderstatus_link( false, true ) . $order_post->post_title . '/' );

						break;

					case '2.0':

						$order_name = $order->mp_shipping_info['name'];
						$field_address = $field->get_permalink();
						$field_title = $field->details->post_title;

						$tracking_url = apply_filters( 'wpml_marketpress_tracking_url', mp_orderstatus_link( false, true ) . $order->post_title . '/' );

						break;

				}

				$tags_replaces = array(
					$order_name,
					get_bloginfo(),
					cp_student_login_address(),
					home_url(),
					$field_address,
					$field_title,
					$order->ID,
					$tracking_url
				);

				$message = fieldpress_get_mp_order_content_email();

				$message = str_replace( $tags, $tags_replaces, $message );

				add_filter( 'wp_mail_from', 'my_mail_from_function', 99 );

				if ( ! function_exists( 'my_mail_from_function' ) ) {

					function my_mail_from_function( $email ) {
						return fieldpress_get_mp_order_from_email();
					}

				}

				add_filter( 'wp_mail_from_name', 'my_mail_from_name_function', 99 );

				if ( ! function_exists( 'my_mail_from_name_function' ) ) {

					function my_mail_from_name_function( $name ) {
						return fieldpress_get_mp_order_from_name();
					}

				}

				return $message;
			} else {
				return $content;
			}
		}

		public static function verify_meta( $value, $post_id, $name ) {

			$meta_keys = array(
				'sku'               => 'mp_sku',
				'regular_price'     => 'mp_price',
				'has_sale'          => 'mp_is_sale',
				'sale_price_amount' => 'mp_sale_price',
				'field_id'         => 'mp_field_id',
				'file_url'          => 'mp_file'
			);

			if( array_key_exists( $name, $meta_keys ) ) {

				$field_id = get_post_meta( $post_id, 'field_id', true );
				$field_id = empty( $field_id ) ? get_post_meta( $post_id, 'mp_field_id', true ) : $field_id;

				if( empty( $field_id ) ) {
					return $value;
				}

				$item_value = get_post_meta( $post_id, $name, true );
				$item_value = empty( $item_value ) ? get_post_meta( $post_id, $meta_keys[ $name ], true ) : $item_value;
				$item_value = is_array( $item_value ) ? $item_value[0] : $item_value;

				return empty( $item_value ) ? $value : $item_value;

			}

			return $value;

		}

		public static function fix_mp3_sku( $value, $post_id, $raw, $field ) {
			return self::verify_meta( $value, $post_id, 'sku' );
		}

		public static function fix_mp3_regular_price( $value, $post_id, $raw, $field ) {
			return self::verify_meta( $value, $post_id, 'regular_price' );
		}

		public static function fix_mp3_has_sale( $value, $post_id, $raw, $field ) {
			return self::verify_meta( $value, $post_id, 'has_sale' );
		}

		public static function fix_mp3_sale_price_amount( $value, $post_id, $raw, $field ) {
			return self::verify_meta( $value, $post_id, 'sale_price_amount' );
		}

		public static function fix_mp3_file_url( $value, $post_id, $raw, $field ) {
			return self::verify_meta( $value, $post_id, 'file_url' );
		}

		public static function fix_mp3_on_sale( $on_sale, $product ) {
			$field_id = empty( self::$field_id ) ? get_post_meta( $product->ID, 'field_id', true ) : self::$field_id;

			if( ! empty( $field_id ) ) {
				$on_sale = (int) get_post_meta( $field_id, 'mp_is_sale', true );
			}

			return $on_sale;
		}


	}

	/**
	 * For Reference:  MP2.0 vs MP 3.0
	 * ===============================
	 *    mp_sku             => sku
	 *    mp_price           => regular_price
	 *    mp_sale_price      => sale_price_amount
	 *    mp_track_inventory => track_inventory
	 *    mp_inventory       => inventory
	 *    mp_special_tax     => special_tax_rate
	 *    mp_is_sale         => has_sale
	 *    mp_shipping        => extra_shipping_cost
	 *    mp_shipping        => weight_extra_shipping_cost
	 *    mp_file            => file_url
	 *    mp_product_link    => external_url
	 */

}


FieldPress_MarketPress_Integration::init();