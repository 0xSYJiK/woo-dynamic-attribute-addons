<?php
/**
 * Main Plugin Class File
 *
 * @package WDAA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Plugin Class
 */
class WDAA_Attribute_Addons {

	private static $instance = null;

	/**
	 * Request-level memoized lookup map for enabled add-on taxonomies
	 *
	 * @var array|bool|null
	 */
	private static $enabled_addons_map = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Initialize hooks after plugins are loaded and WooCommerce is active
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function init() {
		load_plugin_textdomain( 'wdaa', false, dirname( plugin_basename( WDAA_PLUGIN_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', function() {
				echo '<div class="error"><p><strong>' . esc_html__( 'افزونه ویژگی‌های قیمت‌دار:', 'wdaa' ) . '</strong> ' . esc_html__( 'برای کارکرد این افزونه، افزونه ووکامرس باید فعال باشد.', 'wdaa' ) . '</p></div>';
			} );
			return;
		}

		// 1. Admin: Taxonomy term fields, Settings API, and Admin Assets
		if ( is_admin() ) {
			add_action( 'current_screen', array( $this, 'register_taxonomy_hooks' ) );
			add_action( 'admin_init', array( $this, 'register_taxonomy_hooks' ) );
			add_action( 'admin_init', array( $this, 'register_plugin_settings' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		}

		// 2. Frontend: Enqueue scripts & styles
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// 3. Frontend: Display options on single product page
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_product_attribute_options' ), 15 );

		// 4. Cart: Add custom data on add to cart
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );

		// 5. Cart: Display custom options under product title
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_filter( 'wp_kses_allowed_html', array( $this, 'allow_svg_in_kses' ), 10, 2 );

		// 6. Cart & Checkout: Update cart item price directly so line items, subtotals, mini-cart, and totals reflect addon prices
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'update_cart_item_price' ), 20, 1 );

		// 7. Cart: Ensure custom addon data persists across WooCommerce cart session restores
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'get_cart_item_from_session' ), 10, 2 );

		// 8. Orders: Save option data to order items
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_line_item_data' ), 10, 4 );

		// 8. Admin: Menu page for quick guide and overview
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		}
	}

	/**
	 * Bump cache version to invalidate per-product object cache entries when settings or term prices change
	 */
	private function bump_cache_version() {
		self::$enabled_addons_map = null;
		delete_transient( 'wdaa_priced_terms_list' );
		$ver = (int) get_option( 'wdaa_cache_version', 1 );
		update_option( 'wdaa_cache_version', $ver + 1, true );
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'wdaa' );
		}
	}

	/**
	 * Admin: Register settings via WordPress Settings API
	 */
	public function register_plugin_settings() {
		if ( wp_doing_ajax() ) {
			return;
		}

		register_setting(
			'wdaa_settings_group',
			'wdaa_enabled_addons',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_enabled_addons' ),
				'default'           => array(),
			)
		);

		add_filter( 'option_page_capability_wdaa_settings_group', function() {
			return 'manage_woocommerce';
		} );

		$current_page = ( isset( $_GET['page'] ) && is_string( $_GET['page'] ) ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'wdaa-settings' !== $current_page ) {
			return;
		}

		add_settings_section(
			'wdaa_addons_section',
			'⚙️ ' . esc_html__( 'انتخاب ویژگی‌هایی که به عنوان افزودنی (Add-on) نمایش داده می‌شوند', 'wdaa' ),
			array( $this, 'render_settings_section_desc' ),
			'wdaa-settings'
		);

		add_settings_field(
			'wdaa_enabled_addons_field',
			esc_html__( 'ویژگی‌های محصول', 'wdaa' ),
			array( $this, 'render_enabled_addons_field' ),
			'wdaa-settings',
			'wdaa_addons_section'
		);
	}

	/**
	 * Admin: Render description for the settings section
	 */
	public function render_settings_section_desc() {
		echo '<p class="wdaa-admin-card-desc">' . esc_html__( 'ویژگی‌هایی که در زیر تیک می‌زنید، در برگه محصول به عنوان دکمه‌های کارتی مدرن نمایش داده می‌شوند (چه مثل پایه هزینه اضافه داشته باشند و چه مثل رنگ رایگان باشند):', 'wdaa' ) . '</p>';
	}

	/**
	 * Admin: Render the enabled addons table via the WordPress Settings API field callback
	 */
	public function render_enabled_addons_field() {
		$attribute_taxonomies = wc_get_attribute_taxonomies();
		$saved_addons         = get_option( 'wdaa_enabled_addons', null );
		?>
		<input type="hidden" name="wdaa_enabled_addons[]" value="">
		<table class="widefat fixed striped wdaa-admin-table">
			<thead>
				<tr>
					<th class="wdaa-col-active"><strong><?php esc_html_e( 'فعال', 'wdaa' ); ?></strong></th>
					<th><strong><?php esc_html_e( 'نام ویژگی', 'wdaa' ); ?></strong></th>
					<th><strong><?php esc_html_e( 'نامک (Slug)', 'wdaa' ); ?></strong></th>
					<th><strong><?php esc_html_e( 'وضعیت', 'wdaa' ); ?></strong></th>
				</tr>
			</thead>
			<tbody>
				<?php
				if ( ! empty( $attribute_taxonomies ) ) {
					foreach ( $attribute_taxonomies as $tax ) {
						$tax_name      = wc_attribute_taxonomy_name( $tax->attribute_name );
						$sanitized_tax = wc_sanitize_taxonomy_name( $tax_name );

						if ( is_array( $saved_addons ) ) {
							$is_checked = false;
							$tax_slug   = wc_sanitize_taxonomy_name( $tax_name );
							foreach ( $saved_addons as $saved ) {
								$s = (string) $saved;
								if ( $s === $tax_name || $s === $tax_slug || urldecode( $s ) === urldecode( $tax_name ) || str_replace( 'pa_', '', $s ) === str_replace( 'pa_', '', $tax_name ) ) {
									$is_checked = true;
									break;
								}
							}
						} else {
							$is_checked = self::is_default_addon_attribute( $tax_name, $tax->attribute_label );
						}
						?>
						<tr>
							<td class="wdaa-cell-center">
								<input type="checkbox" name="wdaa_enabled_addons[]" value="<?php echo esc_attr( $tax_name ); ?>" <?php checked( $is_checked, true ); ?>>
							</td>
							<td><strong><?php echo esc_html( $tax->attribute_label ); ?></strong></td>
							<td><code><?php echo esc_html( $tax_name ); ?></code></td>
							<td>
								<?php if ( $is_checked ) : ?>
									<span class="wdaa-status-enabled">✓ <?php esc_html_e( 'نمایش به عنوان افزودنی', 'wdaa' ); ?></span>
								<?php else : ?>
									<span class="wdaa-status-disabled"><?php esc_html_e( 'غیرفعال', 'wdaa' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<?php
					}
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Sanitize callback for wdaa_enabled_addons option
	 */
	public function sanitize_enabled_addons( $input ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$existing = get_option( 'wdaa_enabled_addons', array() );
			return is_array( $existing ) ? $existing : array();
		}

		if ( ! is_array( $input ) ) {
			return array();
		}

		$this->bump_cache_version();
		$sanitized = map_deep( wp_unslash( $input ), 'sanitize_text_field' );
		return array_values( array_filter( $sanitized, function( $item ) {
			return is_string( $item ) && '' !== $item;
		} ) );
	}

	/**
	 * Admin: Enqueue admin CSS on plugin and attribute term screens
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		$should_enqueue = ( 'woocommerce_page_wdaa-settings' === $hook_suffix );
		if ( ! $should_enqueue && in_array( $hook_suffix, array( 'edit-tags.php', 'term.php' ), true ) ) {
			$screen         = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$should_enqueue = ( $screen instanceof WP_Screen ) && ! empty( $screen->taxonomy ) && function_exists( 'taxonomy_is_product_attribute' ) && taxonomy_is_product_attribute( $screen->taxonomy );
		}

		if ( $should_enqueue ) {
			wp_enqueue_style(
				'wdaa-admin-style',
				WDAA_PLUGIN_URL . 'assets/css/admin.css',
				array(),
				WDAA_VERSION
			);
		}
	}

	/**
	 * Hook into WooCommerce attribute taxonomies (pa_*) only on relevant taxonomy screens and AJAX actions
	 */
	public function register_taxonomy_hooks() {
		static $registered = false;
		if ( $registered || ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}

		$action        = ( isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$screen        = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_tax_screen = ( $screen instanceof WP_Screen ) && in_array( $screen->base, array( 'edit-tags', 'term' ), true );
		$is_tax_ajax   = wp_doing_ajax() && in_array( $action, array( 'add-tag', 'inline-save-tax' ), true );

		if ( ! $is_tax_screen && ! $is_tax_ajax ) {
			return;
		}

		$registered = true;

		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return;
		}

		$attribute_taxonomies = wc_get_attribute_taxonomies();
		if ( empty( $attribute_taxonomies ) ) {
			return;
		}

		$active_tax = '';
		if ( $screen instanceof WP_Screen && ! empty( $screen->taxonomy ) ) {
			$active_tax = $screen->taxonomy;
		} elseif ( isset( $_REQUEST['taxonomy'] ) && is_string( $_REQUEST['taxonomy'] ) ) {
			$active_tax = sanitize_text_field( wp_unslash( $_REQUEST['taxonomy'] ) );
		}

		foreach ( $attribute_taxonomies as $tax ) {
			$taxonomy = wc_attribute_taxonomy_name( $tax->attribute_name );
			if ( '' !== $active_tax && $taxonomy !== $active_tax && wc_sanitize_taxonomy_name( $taxonomy ) !== wc_sanitize_taxonomy_name( $active_tax ) ) {
				continue;
			}

			// Add custom field to term create & edit forms
			add_action( "{$taxonomy}_add_form_fields", array( $this, 'render_add_term_field' ) );
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_edit_term_field' ), 10, 2 );

			// Save term meta
			add_action( "created_{$taxonomy}", array( $this, 'save_term_extra_price' ) );
			add_action( "edited_{$taxonomy}", array( $this, 'save_term_extra_price' ) );

			// Add extra price column in term list table
			add_filter( "manage_edit-{$taxonomy}_columns", array( $this, 'add_term_table_column' ) );
			add_filter( "manage_{$taxonomy}_custom_column", array( $this, 'render_term_table_column' ), 10, 3 );
		}
	}

	/**
	 * Admin: Render field in "Add New Term" form
	 */
	public function render_add_term_field() {
		?>
		<div class="form-field wdaa-term-extra-price-wrap">
			<?php wp_nonce_field( 'wdaa_save_term_price', 'wdaa_term_price_nonce' ); ?>
			<label for="wdaa_extra_price"><?php esc_html_e( 'هزینه اضافی (تومان)', 'wdaa' ); ?></label>
			<input type="number" name="wdaa_extra_price" id="wdaa_extra_price" class="wdaa-price-input" value="" min="0" step="1000" placeholder="<?php esc_attr_e( 'مثلاً: 300000', 'wdaa' ); ?>">
			<p class="description"><?php esc_html_e( 'در صورت انتخاب این مقدار توسط مشتری، این مبلغ به عنوان هزینه اضافی به فاکتور اضافه خواهد شد (برای گزینه‌های رایگان خالی بگذارید یا 0 وارد کنید).', 'wdaa' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Admin: Render field in "Edit Term" form
	 */
	public function render_edit_term_field( $term, $taxonomy ) {
		$extra_price = get_term_meta( $term->term_id, '_wdaa_extra_price', true );
		?>
		<tr class="form-field wdaa-term-extra-price-wrap">
			<th scope="row" valign="top"><label for="wdaa_extra_price"><?php esc_html_e( 'هزینه اضافی (تومان)', 'wdaa' ); ?></label></th>
			<td>
				<?php wp_nonce_field( 'wdaa_save_term_price', 'wdaa_term_price_nonce' ); ?>
				<input type="number" name="wdaa_extra_price" id="wdaa_extra_price" class="wdaa-price-input-edit" value="<?php echo esc_attr( $extra_price ); ?>" min="0" step="1000" placeholder="<?php esc_attr_e( 'مثلاً: 300000', 'wdaa' ); ?>">
				<p class="description"><?php esc_html_e( 'در صورت انتخاب این مقدار توسط مشتری، این مبلغ به عنوان هزینه اضافی به فاکتور اضافه خواهد شد (برای گزینه‌های رایگان خالی بگذارید یا 0 وارد کنید).', 'wdaa' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Admin: Save term extra price meta
	 */
	public function save_term_extra_price( $term_id ) {
		$term_id = absint( $term_id );
		if ( $term_id <= 0 ) {
			return;
		}

		if ( ! isset( $_POST['wdaa_term_price_nonce'] ) || ! is_string( $_POST['wdaa_term_price_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wdaa_term_price_nonce'] ) ), 'wdaa_save_term_price' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_term', $term_id ) && ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}

		if ( isset( $_POST['wdaa_extra_price'] ) && is_string( $_POST['wdaa_extra_price'] ) ) {
			$raw_price       = sanitize_text_field( wp_unslash( $_POST['wdaa_extra_price'] ) );
			$formatted_price = wc_format_decimal( $raw_price );
			if ( '' === $formatted_price || (float) $formatted_price <= 0 ) {
				delete_term_meta( $term_id, '_wdaa_extra_price' );
			} else {
				update_term_meta( $term_id, '_wdaa_extra_price', $formatted_price );
			}
			$this->bump_cache_version();
		}
	}

	/**
	 * Admin: Add custom column in term table
	 */
	public function add_term_table_column( $columns ) {
		$columns['wdaa_extra_price'] = esc_html__( 'هزینه اضافی', 'wdaa' );
		return $columns;
	}

	/**
	 * Return the host's #toman-icon SVG markup for currency symbol display
	 *
	 * @return string
	*/
	private static function get_toman_svg_markup() {
		return '<svg class="wdaa-toman-icon w-4 h-4" aria-hidden="true"><use href="#toman-icon" xlink:href="#toman-icon"></use></svg>';
	}

	/**
	 * Return allowed HTML tags for wp_kses() including <svg> and <use> for #toman-icon
	 *
	 * @return array
	 */
	private static function get_allowed_price_html() {
		$allowed        = wp_kses_allowed_html( 'post' );
		$allowed['svg'] = array(
			'class'       => true,
			'width'       => true,
			'height'      => true,
			'viewbox'     => true,
			'fill'        => true,
			'aria-hidden' => true,
			'role'        => true,
			'xmlns'       => true,
		);
		$allowed['use'] = array(
			'href'       => true,
			'xlink:href' => true,
		);
		return $allowed;
	}

	/**
	 * Allow <svg> and <use> tags in wp_kses_post context so WooCommerce cart/checkout templates render #toman-icon SVG
	 *
	 * @param array  $tags    Allowed tags.
	 * @param string $context Context name.
	 * @return array
	 */
	public function allow_svg_in_kses( $tags, $context ) {
		if ( 'post' === $context ) {
			$tags['svg'] = array(
				'class'       => true,
				'width'       => true,
				'height'      => true,
				'viewbox'     => true,
				'fill'        => true,
				'aria-hidden' => true,
				'role'        => true,
				'xmlns'       => true,
			);
			$tags['use'] = array(
				'href'       => true,
				'xlink:href' => true,
			);
		}
		return $tags;
	}

	/**
	 * Return clean plain-text currency label for admin tables and plain-text order meta
	 *
	 * @return string
	 */
	private static function get_clean_currency_label() {
		$raw_symbol = get_woocommerce_currency_symbol();
		$clean      = trim( wp_strip_all_tags( wp_specialchars_decode( (string) $raw_symbol, ENT_QUOTES ) ) );
		if ( '' === $clean || false !== stripos( (string) $raw_symbol, '<svg' ) ) {
			$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'IRT';
			if ( 'IRR' === $currency ) {
				return __( 'ریال', 'wdaa' );
			}
			return __( 'تومان', 'wdaa' );
		}
		return $clean;
	}

	/**
	 * Admin: Display extra price in term table column
	 */
	public function render_term_table_column( $content, $column_name, $term_id ) {
		if ( 'wdaa_extra_price' === $column_name ) {
			$price = get_term_meta( $term_id, '_wdaa_extra_price', true );
			if ( ! empty( $price ) && (float) $price > 0 ) {
				return '<strong class="wdaa-term-price-active">+' . esc_html( number_format_i18n( (float) $price ) ) . ' ' . esc_html( self::get_clean_currency_label() ) . '</strong>';
			}
			return '<span class="wdaa-term-price-empty">—</span>';
		}
		return $content;
	}

	/**
	 * Enqueue frontend CSS and JS
	 */
	public function enqueue_frontend_assets() {
		// Enqueue styles on cart and checkout so #toman-icon SVG and addon details format cleanly
		if ( function_exists( 'is_cart' ) && function_exists( 'is_checkout' ) && ( is_cart() || is_checkout() ) ) {
			wp_enqueue_style(
				'wdaa-frontend-style',
				WDAA_PLUGIN_URL . 'assets/css/frontend.css',
				array(),
				WDAA_VERSION
			);
			return;
		}

		if ( ! is_product() ) {
			return;
		}

		$product_id = get_queried_object_id();
		if ( $product_id <= 0 || empty( $this->get_product_addon_sections( $product_id ) ) ) {
			return;
		}

		wp_enqueue_style(
			'wdaa-frontend-style',
			WDAA_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			WDAA_VERSION
		);

		wp_enqueue_script(
			'wdaa-frontend-script',
			WDAA_PLUGIN_URL . 'assets/js/frontend.js',
			array( 'jquery', 'accounting' ),
			WDAA_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Pass currency symbol, price formatting, and translated labels to JS via wp_add_inline_script
		$script_data = array(
			'currency_symbol'        => esc_html( self::get_clean_currency_label() ),
			'price_format'           => esc_html( get_woocommerce_price_format() ),
			'thousand_sep'           => esc_html( wc_get_price_thousand_separator() ),
			'decimal_sep'            => esc_html( wc_get_price_decimal_separator() ),
			'decimals'               => absint( wc_get_price_decimals() ),
			'i18n_total_with_addons' => esc_html__( 'مجموع با احتساب گزینه‌های انتخابی:', 'wdaa' ),
			'i18n_addons_cost'       => esc_html__( 'هزینه گزینه‌های انتخابی:', 'wdaa' ),
		);

		wp_add_inline_script(
			'wdaa-frontend-script',
			'window.wdaa_vars = ' . wp_json_encode( $script_data ) . ';',
			'before'
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wdaa-frontend-script', 'wdaa', WDAA_PLUGIN_DIR . 'languages' );
		}
	}

	/**
	 * Check if a product attribute should be treated as an interactive Add-on
	 */
	public static function is_addon_attribute( $taxonomy, $tax_label = '', $has_extra_price = false ) {
		$sanitized_tax = wc_sanitize_taxonomy_name( $taxonomy );
		$decoded_tax   = urldecode( (string) $taxonomy );

		if ( null === self::$enabled_addons_map ) {
			$saved_addons = get_option( 'wdaa_enabled_addons', null );
			if ( is_array( $saved_addons ) ) {
				self::$enabled_addons_map = array();
				foreach ( $saved_addons as $saved ) {
					$s = (string) $saved;
					self::$enabled_addons_map[ $s ]                               = true;
					self::$enabled_addons_map[ urldecode( $s ) ]                  = true;
					self::$enabled_addons_map[ wc_sanitize_taxonomy_name( $s ) ] = true;
					self::$enabled_addons_map[ str_replace( 'pa_', '', $s ) ]     = true;
				}
			} else {
				self::$enabled_addons_map = false;
			}
		}

		// 1. If admin has saved settings
		if ( is_array( self::$enabled_addons_map ) ) {
			$checks = array(
				$taxonomy,
				$sanitized_tax,
				$decoded_tax,
				str_replace( 'pa_', '', $taxonomy ),
				'pa_' . str_replace( 'pa_', '', $taxonomy ),
			);
			foreach ( $checks as $c ) {
				if ( isset( self::$enabled_addons_map[ $c ] ) ) {
					return true;
				}
			}
			// If terms have extra price configured, always treat as addon
			if ( $has_extra_price ) {
				return true;
			}
			return false;
		}

		// 2. Default fallback before admin saves settings:
		if ( $has_extra_price ) {
			return true;
		}

		return self::is_default_addon_attribute( $taxonomy, $tax_label );
	}

	/**
	 * Determine default addon attributes before admin saves settings for the first time
	 */
	public static function is_default_addon_attribute( $taxonomy, $tax_label = '' ) {
		$tax_slug   = trim( wc_strtolower( (string) $taxonomy ) );
		$label_slug = trim( wc_strtolower( (string) $tax_label ) );

		$keywords = array( 'رنگ', 'color', 'colour', 'rang', 'پایه', 'payeh', 'base' );
		foreach ( $keywords as $kw ) {
			if ( false !== mb_strpos( $tax_slug, $kw ) || false !== mb_strpos( urldecode( $tax_slug ), $kw ) ) {
				return true;
			}
			if ( '' !== $label_slug && false !== mb_strpos( $label_slug, $kw ) ) {
				if ( mb_strlen( $label_slug ) <= 30 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Fetch and cache addon sections for a product using batched term/termmeta queries and persistent transient fallback
	 *
	 * @param WC_Product|int $product Product instance or ID.
	 * @return array
	 */
	private function get_product_addon_sections( $product ) {
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( absint( $product ) );
		}

		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return array();
		}

		// If variation product passed, resolve to parent product
		if ( $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			$product   = wc_get_product( $parent_id );
			if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
				return array();
			}
		}

		$product_id     = $product->get_id();
		$cache_ver      = (int) get_option( 'wdaa_cache_version', 1 );
		$cache_key      = 'wdaa_pa_' . $product_id . '_v' . $cache_ver;
		$use_ext_cache  = wp_using_ext_object_cache();
		$addon_sections = wp_cache_get( $cache_key, 'wdaa' );

		if ( false === $addon_sections && ! $use_ext_cache ) {
			$meta_cache = get_post_meta( $product_id, '_wdaa_addons_cache', true );
			if ( is_array( $meta_cache ) && isset( $meta_cache['ver'], $meta_cache['exp'], $meta_cache['data'] ) && (int) $meta_cache['ver'] === $cache_ver && (int) $meta_cache['exp'] > time() && is_array( $meta_cache['data'] ) ) {
				$addon_sections = $meta_cache['data'];
				wp_cache_set( $cache_key, $addon_sections, 'wdaa', HOUR_IN_SECONDS );
			}
		}

		if ( false === $addon_sections || ! is_array( $addon_sections ) ) {
			$addon_sections       = array();
			$attributes           = $product->get_attributes();
			$candidate_taxonomies = array();

			if ( ! empty( $attributes ) ) {
				$has_saved_config = is_array( get_option( 'wdaa_enabled_addons', null ) );

				foreach ( $attributes as $attribute ) {
					if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'is_taxonomy' ) || ! $attribute->is_taxonomy() ) {
						continue;
					}

					if ( method_exists( $attribute, 'get_variation' ) && $attribute->get_variation() ) {
						continue;
					}

					$taxonomy = method_exists( $attribute, 'get_name' ) ? $attribute->get_name() : '';
					if ( empty( $taxonomy ) ) {
						continue;
					}

					$label = wc_attribute_label( $taxonomy, $product );
					if ( $has_saved_config && ! self::is_addon_attribute( $taxonomy, $label ) ) {
						continue;
					}

					$candidate_taxonomies[] = $taxonomy;
				}

				if ( ! empty( $candidate_taxonomies ) ) {
					// Single batched query across all candidate attribute taxonomies for this product
					$all_terms = wp_get_object_terms(
						$product_id,
						$candidate_taxonomies,
						array(
							'fields'                 => 'all',
							'orderby'                => 'name',
							'order'                  => 'ASC',
							'update_term_meta_cache' => true,
						)
					);

					if ( ! empty( $all_terms ) && ! is_wp_error( $all_terms ) ) {
						$terms_by_tax = array();
						foreach ( $all_terms as $term ) {
							$terms_by_tax[ $term->taxonomy ][] = $term;
						}

						foreach ( $candidate_taxonomies as $taxonomy ) {
							if ( empty( $terms_by_tax[ $taxonomy ] ) ) {
								continue;
							}

							$has_extra_price = false;
							$terms_data      = array();

							foreach ( $terms_by_tax[ $taxonomy ] as $term ) {
								$extra_price = (float) get_term_meta( $term->term_id, '_wdaa_extra_price', true );
								if ( $extra_price > 0 ) {
									$has_extra_price = true;
								}
								$terms_data[] = array(
									'term_id'     => $term->term_id,
									'name'        => $term->name,
									'slug'        => $term->slug,
									'extra_price' => $extra_price,
								);
							}

							$label = wc_attribute_label( $taxonomy, $product );

							if ( ! $has_saved_config && ! self::is_addon_attribute( $taxonomy, $label, $has_extra_price ) ) {
								continue;
							}

							usort( $terms_data, function( $a, $b ) {
								return $a['extra_price'] <=> $b['extra_price'];
							} );

							$addon_sections[] = array(
								'taxonomy'   => $taxonomy,
								'label'      => $label,
								'terms_data' => $terms_data,
							);
						}
					}
				}
			}

			wp_cache_set( $cache_key, $addon_sections, 'wdaa', HOUR_IN_SECONDS );
			if ( ! $use_ext_cache ) {
				update_post_meta(
					$product_id,
					'_wdaa_addons_cache',
					array(
						'ver'  => $cache_ver,
						'exp'  => time() + HOUR_IN_SECONDS,
						'data' => $addon_sections,
					)
				);
			}
		}

		return $addon_sections;
	}

	/**
	 * Frontend: Render attribute options as stylish pill buttons
	 */
	public function render_product_attribute_options() {
		global $product;

		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		$addon_sections = $this->get_product_addon_sections( $product );

		if ( empty( $addon_sections ) ) {
			return;
		}

		// Render all addons inside ONE SINGLE MASTER BOX
		$product_price = (float) wc_get_price_to_display( $product );
		$is_variable   = $product->is_type( 'variable' ) ? '1' : '0';
		?>
		<div class="wdaa-master-box" id="wdaa-master-box" data-base-price="<?php echo esc_attr( $product_price ); ?>" data-is-variable="<?php echo esc_attr( $is_variable ); ?>">
			<?php
			wp_nonce_field( 'wdaa_add_to_cart', 'wdaa_cart_nonce' );
			$section_count = count( $addon_sections );
			foreach ( $addon_sections as $index => $section ) :
				$taxonomy   = $section['taxonomy'];
				$label      = $section['label'];
				$terms_data = $section['terms_data'];
				?>
				<div class="wdaa-addon-row" data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>">
					<div class="wdaa-attribute-header">
						<span class="wdaa-attribute-title"><?php echo esc_html( sprintf( __( 'انتخاب %s:', 'wdaa' ), $label ) ); ?></span>
					</div>
					<div class="wdaa-pills-container">
						<?php
						$is_first = true;
						foreach ( $terms_data as $term_item ) :
							$active_class = $is_first ? 'is-selected' : '';
							$price_badge  = '';

							if ( $term_item['extra_price'] > 0 ) {
								$price_badge = sprintf(
									'<span class="wdaa-pill-price"><span class="woocommerce-Price-currencySymbol wdaa-currency">%s</span><span class="wdaa-pill-amount">%s+</span></span>',
									self::get_toman_svg_markup(),
									esc_html( number_format_i18n( (float) $term_item['extra_price'] ) )
								);
							}
							?>
							<label class="wdaa-pill-item <?php echo esc_attr( $active_class ); ?>">
								<input type="radio" 
									   name="wdaa_option[<?php echo esc_attr( $taxonomy ); ?>]" 
									   value="<?php echo esc_attr( $term_item['term_id'] ); ?>" 
									   data-price="<?php echo esc_attr( $term_item['extra_price'] ); ?>" 
									   data-name="<?php echo esc_attr( $term_item['name'] ); ?>"
									   <?php checked( $is_first, true ); ?>>
								<span class="wdaa-pill-label">
									<span class="wdaa-pill-name"><?php echo esc_html( $term_item['name'] ); ?></span>
									<?php echo wp_kses( $price_badge, self::get_allowed_price_html() ); ?>
								</span>
							</label>
							<?php
							$is_first = false;
						endforeach;
						?>
					</div>
				</div>
				<?php
				if ( $index < $section_count - 1 ) :
					echo '<div class="wdaa-addon-divider"></div>';
				endif;
			endforeach;
			?>
		</div>
		<?php
	}

	/**
	 * Cart: Capture selected options when adding to cart
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 * @return array
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		// 1. Gather raw options from $_REQUEST, $_POST, or serialized AJAX form data
		$raw_options = null;

		if ( ! empty( $_REQUEST['wdaa_option'] ) ) {
			$raw_options = $_REQUEST['wdaa_option'];
		} elseif ( ! empty( $_POST['wdaa_option'] ) ) {
			$raw_options = $_POST['wdaa_option'];
		} elseif ( ! empty( $_REQUEST['data'] ) && is_string( $_REQUEST['data'] ) ) {
			wp_parse_str( wp_unslash( $_REQUEST['data'] ), $parsed );
			if ( ! empty( $parsed['wdaa_option'] ) ) {
				$raw_options = $parsed['wdaa_option'];
			}
		} elseif ( ! empty( $_REQUEST['form_data'] ) && is_string( $_REQUEST['form_data'] ) ) {
			wp_parse_str( wp_unslash( $_REQUEST['form_data'] ), $parsed );
			if ( ! empty( $parsed['wdaa_option'] ) ) {
				$raw_options = $parsed['wdaa_option'];
			}
		}

		if ( empty( $raw_options ) ) {
			return $cart_item_data;
		}

		if ( is_string( $raw_options ) ) {
			$decoded = json_decode( $raw_options, true );
			if ( is_array( $decoded ) ) {
				$raw_options = $decoded;
			}
		}

		if ( ! is_array( $raw_options ) ) {
			return $cart_item_data;
		}

		// 2. Resolve parent product if a variation ID was passed as product_id
		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );

		if ( $product_id <= 0 && $variation_id > 0 ) {
			$product_id = $variation_id;
		}

		$product = wc_get_product( $product_id );

		if ( $product && $product->is_type( 'variation' ) ) {
			$parent_id  = $product->get_parent_id();
			$product_id = $parent_id;
			$product    = wc_get_product( $product_id );
		}

		$selected_addons   = array();
		$total_extra_price = 0.0;

		// 3. Build section lookup map if sections exist for this product
		$addon_sections  = $this->get_product_addon_sections( $product_id );
		$sections_by_tax = array();
		if ( is_array( $addon_sections ) ) {
			foreach ( $addon_sections as $sec ) {
				$tax = (string) $sec['taxonomy'];
				$sections_by_tax[ $tax ]                               = $sec;
				$sections_by_tax[ urldecode( $tax ) ]                  = $sec;
				$sections_by_tax[ wc_sanitize_taxonomy_name( $tax ) ] = $sec;
				$sections_by_tax[ str_replace( 'pa_', '', $tax ) ]     = $sec;
			}
		}

		// 4. Process each submitted attribute addon
		foreach ( $raw_options as $taxonomy => $term_id ) {
			if ( ! is_scalar( $term_id ) || ! is_scalar( $taxonomy ) ) {
				continue;
			}

			$term_id = absint( $term_id );
			if ( $term_id <= 0 ) {
				continue;
			}

			$tax_key         = sanitize_text_field( (string) $taxonomy );
			$matched_term    = null;
			$tax_label       = '';
			$extra_price     = 0.0;
			$matched_section = null;

			$candidates = array(
				$tax_key,
				urldecode( $tax_key ),
				wc_sanitize_taxonomy_name( $tax_key ),
				str_replace( 'pa_', '', $tax_key ),
				'pa_' . str_replace( 'pa_', '', $tax_key ),
			);

			foreach ( $candidates as $cand ) {
				if ( isset( $sections_by_tax[ $cand ] ) ) {
					$matched_section = $sections_by_tax[ $cand ];
					break;
				}
			}

			if ( $matched_section && ! empty( $matched_section['terms_data'] ) ) {
				foreach ( $matched_section['terms_data'] as $t_item ) {
					if ( (int) $t_item['term_id'] === $term_id ) {
						$matched_term = $t_item;
						$tax_label    = $matched_section['label'];
						$extra_price  = (float) $t_item['extra_price'];
						break;
					}
				}
			}

			// Direct fallback: If not in cached sections, look up term directly in WordPress
			if ( ! $matched_term ) {
				$wp_term = get_term( $term_id );
				if ( $wp_term && ! is_wp_error( $wp_term ) ) {
					$matched_term = array(
						'term_id'     => $wp_term->term_id,
						'name'        => $wp_term->name,
						'slug'        => $wp_term->slug,
						'extra_price' => (float) get_term_meta( $wp_term->term_id, '_wdaa_extra_price', true ),
					);
					$tax_label   = wc_attribute_label( $wp_term->taxonomy, $product );
					$extra_price = (float) $matched_term['extra_price'];
					$tax_key     = $wp_term->taxonomy;
				}
			}

			if ( ! $matched_term ) {
				continue;
			}

			$selected_addons[] = array(
				'taxonomy'    => sanitize_text_field( $tax_key ),
				'tax_label'   => sanitize_text_field( $tax_label ),
				'term_id'     => $term_id,
				'term_name'   => sanitize_text_field( $matched_term['name'] ),
				'extra_price' => (float) $extra_price,
			);

			$total_extra_price += (float) $extra_price;
		}

		if ( ! empty( $selected_addons ) ) {
			$cart_item_data['wdaa_addons']      = $selected_addons;
			$cart_item_data['wdaa_extra_price'] = (float) $total_extra_price;

			// Deterministic hash so identical addon combinations merge while different ones stay separate line items
			$hash_data = $selected_addons;
			usort( $hash_data, function( $a, $b ) {
				return strcmp( (string) $a['taxonomy'], (string) $b['taxonomy'] );
			} );
			$cart_item_data['wdaa_unique_id'] = md5( wp_json_encode( $hash_data ) );
		}

		return $cart_item_data;
	}

	/**
	 * Cart: Ensure custom addon data persists across WooCommerce cart session restores
	 *
	 * @param array $cart_item Cart item data.
	 * @param array $values    Cart session values.
	 * @return array
	 */
	public function get_cart_item_from_session( $cart_item, $values ) {
		if ( isset( $values['wdaa_addons'] ) && is_array( $values['wdaa_addons'] ) ) {
			$cart_item['wdaa_addons'] = $values['wdaa_addons'];
		}
		if ( isset( $values['wdaa_extra_price'] ) ) {
			$cart_item['wdaa_extra_price'] = (float) $values['wdaa_extra_price'];
		}
		if ( isset( $values['wdaa_base_price'] ) ) {
			$cart_item['wdaa_base_price'] = (float) $values['wdaa_base_price'];
		}
		if ( isset( $values['wdaa_unique_id'] ) ) {
			$cart_item['wdaa_unique_id'] = $values['wdaa_unique_id'];
		}
		return $cart_item;
	}

	/**
	 * Cart & Checkout: Update cart item price directly so line items, subtotals, mini-cart, and totals reflect addon prices
	 *
	 * @param WC_Cart $cart Cart instance.
	 */
	public function update_cart_item_price( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item['wdaa_extra_price'] ) || (float) $cart_item['wdaa_extra_price'] <= 0 ) {
				continue;
			}

			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_price' ) || ! method_exists( $product, 'set_price' ) ) {
				continue;
			}

			$extra = (float) $cart_item['wdaa_extra_price'];

			// Retrieve or store true original base price to avoid compounding on multiple calculate_totals passes
			if ( ! isset( $cart_item['wdaa_base_price'] ) ) {
				$base_price = (float) $product->get_price( 'edit' );
				$cart->cart_contents[ $cart_item_key ]['wdaa_base_price'] = $base_price;
			} else {
				$base_price = (float) $cart_item['wdaa_base_price'];
			}

			$product->set_price( $base_price + $extra );
		}
	}

	/**
	 * Cart: Display selected addon under product title in Cart and Checkout
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['wdaa_addons'] ) && is_array( $cart_item['wdaa_addons'] ) ) {
			foreach ( $cart_item['wdaa_addons'] as $addon ) {
				$display_value = esc_html( (string) $addon['term_name'] );
				if ( ! empty( $addon['extra_price'] ) && (float) $addon['extra_price'] > 0 ) {
					$display_value .= ' (+' . esc_html( number_format_i18n( (float) $addon['extra_price'] ) ) . ' ' . self::get_toman_svg_markup() . ')';
				}

				$item_data[] = array(
					'key'   => esc_html( (string) $addon['tax_label'] ),
					'value' => wp_kses( $display_value, self::get_allowed_price_html() ),
				);
			}
		}

		return $item_data;
	}

	/**
	 * Backwards compatibility stub for calculate_addon_fees
	 *
	 * @param WC_Cart $cart Cart instance.
	 */
	public function calculate_addon_fees( $cart ) {
		// Handled via update_cart_item_price() directly on line items
	}

	/**
	 * Order: Save addon selection to order item meta
	 */
	public function save_order_line_item_data( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['wdaa_addons'] ) && is_array( $values['wdaa_addons'] ) ) {
			foreach ( $values['wdaa_addons'] as $addon ) {
				$meta_key   = isset( $addon['tax_label'] ) ? sanitize_text_field( wp_strip_all_tags( (string) $addon['tax_label'] ) ) : '';
				$meta_value = isset( $addon['term_name'] ) ? sanitize_text_field( wp_strip_all_tags( (string) $addon['term_name'] ) ) : '';
				if ( ! empty( $addon['extra_price'] ) && (float) $addon['extra_price'] > 0 ) {
					$currency_symbol = sanitize_text_field( self::get_clean_currency_label() );
					$meta_value     .= ' (+' . number_format_i18n( (float) $addon['extra_price'] ) . ' ' . $currency_symbol . ')';
				}
				if ( '' !== $meta_key && '' !== $meta_value ) {
					$item->add_meta_data( $meta_key, sanitize_text_field( $meta_value ), true );
				}
			}
		}
	}

	/**
	 * Admin: Add a neat guide page under WooCommerce menu
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'ویژگی‌های قیمت‌دار هوشمند', 'wdaa' ),
			__( 'ویژگی‌های قیمت‌دار', 'wdaa' ),
			'manage_woocommerce',
			'wdaa-settings',
			array( $this, 'render_admin_guide_page' )
		);
	}

	/**
	 * Fetch priced attribute terms in a single batched WP_Term_Query across all taxonomies and cache via Transients API
	 */
	private function get_priced_terms_list( $attribute_taxonomies ) {
		$cached = get_transient( 'wdaa_priced_terms_list' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		if ( empty( $attribute_taxonomies ) ) {
			return array();
		}

		$taxonomy_labels = array();
		foreach ( $attribute_taxonomies as $tax ) {
			$tax_name                     = wc_attribute_taxonomy_name( $tax->attribute_name );
			$taxonomy_labels[ $tax_name ] = $tax->attribute_label;
		}

		$terms = get_terms( array(
			'taxonomy'               => array_keys( $taxonomy_labels ),
			'hide_empty'             => false,
			'number'                 => 200,
			'no_found_rows'          => true,
			'update_term_meta_cache' => true,
			'meta_query'             => array(
				array(
					'key'     => '_wdaa_extra_price',
					'compare' => 'EXISTS',
				),
			),
		) );

		$priced_terms = array();
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$price = (float) get_term_meta( $term->term_id, '_wdaa_extra_price', true );
				if ( $price > 0 && isset( $taxonomy_labels[ $term->taxonomy ] ) ) {
					$priced_terms[] = array(
						'taxonomy'        => $term->taxonomy,
						'attribute_label' => $taxonomy_labels[ $term->taxonomy ],
						'term_id'         => $term->term_id,
						'term_name'       => $term->name,
						'price'           => $price,
					);
				}
			}
		}

		set_transient( 'wdaa_priced_terms_list', $priced_terms, HOUR_IN_SECONDS );
		return $priced_terms;
	}

	/**
	 * Admin: Render guide and configured attributes list
	 */
	public function render_admin_guide_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'شما مجوز دسترسی به این بخش را ندارید.', 'wdaa' ) );
		}

		$attribute_taxonomies = wc_get_attribute_taxonomies();
		?>
		<div class="wrap wdaa-admin-wrap">
			<h1 class="wdaa-admin-title">⚡ <?php esc_html_e( 'افزونه ویژگی‌های قیمت‌دار هوشمند ووکامرس', 'wdaa' ); ?></h1>

			<?php settings_errors(); ?>

			<div class="card wdaa-admin-card">
				<form method="post" action="options.php">
					<?php
					settings_fields( 'wdaa_settings_group' );
					do_settings_sections( 'wdaa-settings' );
					submit_button( esc_html__( 'ذخیره تغییرات ویژگی‌ها', 'wdaa' ), 'primary', 'submit', false );
					?>
				</form>
			</div>

			<div class="card wdaa-admin-card">
				<h2 class="wdaa-admin-card-title">📋 <?php esc_html_e( 'راهنمای استفاده سریع', 'wdaa' ); ?></h2>
				<ol class="wdaa-guide-list">
					<li><?php esc_html_e( 'به بخش محصولات ← ویژگی‌ها بروید.', 'wdaa' ); ?></li>
					<li><?php esc_html_e( 'روی «پیکربندی مقادیر» ویژگی مورد نظر (مثلاً پایه) کلیک کنید.', 'wdaa' ); ?></li>
					<li><?php esc_html_e( 'هنگام افزودن یا ویرایش هر مقدار (مثلاً پایه کتابی چوبی)، مبلغ مورد نظر را در کادر «هزینه اضافی (تومان)» وارد کنید (مثلاً: 300000). برای گزینه‌های بدون هزینه (مانند چهارپایه) آن را خالی بگذارید یا 0 بنویسید.', 'wdaa' ); ?></li>
					<li><?php esc_html_e( 'در صفحه ویرایش محصول، این ویژگی را به عنوان یک ویژگی ساده اضافه کنید (تیک «استفاده برای متغیرها» را بردارید تا نیازی به ساخت ده‌ها متغیر سنگین نباشد).', 'wdaa' ); ?></li>
					<li><?php esc_html_e( 'تمام! در برگه محصول دکمه‌های تگ شیک ظاهر شده و هزینه به فاکتور و صورت‌حساب افزوده می‌شود.', 'wdaa' ); ?></li>
				</ol>
			</div>

			<div class="card wdaa-admin-card wdaa-admin-card-last">
				<h2 class="wdaa-admin-card-title">🔍 <?php esc_html_e( 'مقادیر قیمت‌دار تعریف شده در فروشگاه شما', 'wdaa' ); ?></h2>
				<table class="widefat fixed striped wdaa-admin-table-spaced">
					<thead>
						<tr>
							<th><strong><?php esc_html_e( 'ویژگی', 'wdaa' ); ?></strong></th>
							<th><strong><?php esc_html_e( 'مقدار', 'wdaa' ); ?></strong></th>
							<th><strong><?php esc_html_e( 'هزینه اضافی', 'wdaa' ); ?></strong></th>
							<th><strong><?php esc_html_e( 'عملیات', 'wdaa' ); ?></strong></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$priced_terms = $this->get_priced_terms_list( $attribute_taxonomies );

						if ( ! empty( $priced_terms ) ) {
							foreach ( $priced_terms as $item ) {
								$edit_url = add_query_arg(
									array(
										'taxonomy'  => rawurlencode( $item['taxonomy'] ),
										'tag_ID'    => absint( $item['term_id'] ),
										'post_type' => 'product',
									),
									admin_url( 'term.php' )
								);
								?>
								<tr>
									<td><?php echo esc_html( $item['attribute_label'] ); ?></td>
									<td><strong><?php echo esc_html( $item['term_name'] ); ?></strong></td>
									<td class="wdaa-priced-amount">+<?php echo esc_html( number_format_i18n( (float) $item['price'] ) ); ?> <?php echo esc_html( self::get_clean_currency_label() ); ?></td>
									<td><a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'ویرایش', 'wdaa' ); ?></a></td>
								</tr>
								<?php
							}
						} else {
							?>
							<tr>
								<td colspan="4" class="wdaa-empty-row">
									<?php esc_html_e( 'هنوز هزینه اضافی برای هیچ مقداری ثبت نشده است. از منوی محصولات ← ویژگی‌ها مقادیر خود را پیکربندی کنید.', 'wdaa' ); ?>
								</td>
							</tr>
							<?php
						}
						?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
}
