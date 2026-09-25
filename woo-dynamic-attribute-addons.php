<?php
/**
 * Plugin Name: ویژگی‌های قیمت‌دار هوشمند ووکامرس (WooCommerce Dynamic Attribute Add-ons)
 * Plugin URI: https://dookht.com
 * Description: افزودن هزینه اضافی پویا به مقادیر ویژگی‌های محصول، نمایش دکمه‌های کارتی شیک در برگه محصول، محاسبه آنی قیمت و درج ردیف مجزا در فاکتور بدون نیاز به ساخت متغیرهای تکراری.
 * Version: 1.3.7
 * Author: dookht.com
 * Text Domain: wdaa
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'WDAA_VERSION', '1.3.7' );
define( 'WDAA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WDAA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Declare HPOS (High-Performance Order Storage) compatibility
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

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
        load_plugin_textdomain( 'wdaa', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="error"><p><strong>' . esc_html__( 'افزونه ویژگی‌های قیمت‌دار:', 'wdaa' ) . '</strong> ' . esc_html__( 'برای کارکرد این افزونه، افزونه ووکامرس باید فعال باشد.', 'wdaa' ) . '</p></div>';
            } );
            return;
        }

        // 1. Admin: Taxonomy term fields, Settings API, and Admin Assets
        if ( is_admin() ) {
            add_action( 'init', array( $this, 'register_taxonomy_hooks' ), 99 );
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

        // 6. Cart & Checkout: Add separate fee line for selected addon
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'calculate_addon_fees' ), 20, 1 );

        // 7. Orders: Save option data to order items
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
        update_option( 'wdaa_cache_version', $ver + 1, false );
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( 'wdaa' );
        }
    }

    /**
     * Admin: Register settings via WordPress Settings API
     */
    public function register_plugin_settings() {
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
        if ( in_array( $hook_suffix, array( 'woocommerce_page_wdaa-settings', 'edit-tags.php', 'term.php' ), true ) ) {
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
        if ( ! current_user_can( 'manage_product_terms' ) ) {
            return;
        }

        global $pagenow;

        $action        = ( isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        $is_tax_screen = in_array( $pagenow, array( 'edit-tags.php', 'term.php' ), true );
        $is_tax_ajax   = ( 'admin-ajax.php' === $pagenow && in_array( $action, array( 'add-tag', 'inline-save-tax' ), true ) );

        if ( ! $is_tax_screen && ! $is_tax_ajax ) {
            return;
        }

        if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
            return;
        }

        $attribute_taxonomies = wc_get_attribute_taxonomies();
        if ( empty( $attribute_taxonomies ) ) {
            return;
        }

        foreach ( $attribute_taxonomies as $tax ) {
            $taxonomy = wc_attribute_taxonomy_name( $tax->attribute_name );
            
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
            $price = sanitize_text_field( wp_unslash( $_POST['wdaa_extra_price'] ) );
            if ( '' === $price || ! is_numeric( $price ) || (float) $price <= 0 ) {
                delete_term_meta( $term_id, '_wdaa_extra_price' );
            } else {
                update_term_meta( $term_id, '_wdaa_extra_price', (float) $price );
            }
            $this->bump_cache_version();
        }
    }

    /**
     * Admin: Add custom column in term table
     */
    public function add_term_table_column( $columns ) {
        $columns['wdaa_extra_price'] = __( 'هزینه اضافی', 'wdaa' );
        return $columns;
    }

    /**
     * Admin: Display extra price in term table column
     */
    public function render_term_table_column( $content, $column_name, $term_id ) {
        if ( 'wdaa_extra_price' === $column_name ) {
            $price = get_term_meta( $term_id, '_wdaa_extra_price', true );
            if ( ! empty( $price ) && (float) $price > 0 ) {
                return '<strong class="wdaa-term-price-active">+' . esc_html( number_format_i18n( (float) $price ) ) . ' ' . esc_html( get_woocommerce_currency_symbol() ) . '</strong>';
            }
            return '<span class="wdaa-term-price-empty">—</span>';
        }
        return $content;
    }

    /**
     * Enqueue frontend CSS and JS
     */
    public function enqueue_frontend_assets() {
        if ( ! is_product() ) {
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
            array( 'jquery' ),
            WDAA_VERSION,
            true
        );

        // Pass currency symbol, price formatting, and translated labels to JS
        wp_localize_script( 'wdaa-frontend-script', 'wdaa_vars', array(
            'currency_symbol'        => get_woocommerce_currency_symbol(),
            'price_format'           => get_woocommerce_price_format(),
            'thousand_sep'           => wc_get_price_thousand_separator(),
            'decimal_sep'            => wc_get_price_decimal_separator(),
            'decimals'               => wc_get_price_decimals(),
            'i18n_total_with_addons' => __( 'مجموع با احتساب گزینه‌های انتخابی:', 'wdaa' ),
            'i18n_addons_cost'       => __( 'هزینه گزینه‌های انتخابی:', 'wdaa' ),
        ) );
    }

    /**
     * Check if a product attribute should be treated as an interactive Add-on
     */
    public static function is_addon_attribute( $taxonomy, $tax_label = '', $has_extra_price = false ) {
        $decoded_tax = urldecode( $taxonomy );

        if ( null === self::$enabled_addons_map ) {
            $saved_addons = get_option( 'wdaa_enabled_addons', null );
            if ( is_array( $saved_addons ) ) {
                self::$enabled_addons_map = array();
                foreach ( $saved_addons as $saved ) {
                    self::$enabled_addons_map[ $saved ]              = true;
                    self::$enabled_addons_map[ urldecode( $saved ) ] = true;
                }
            } else {
                self::$enabled_addons_map = false;
            }
        }

        // 1. Fast O(1) lookup when admin has saved settings
        if ( is_array( self::$enabled_addons_map ) ) {
            return isset( self::$enabled_addons_map[ $taxonomy ] ) || isset( self::$enabled_addons_map[ $decoded_tax ] );
        }

        // 2. Default fallback (ONLY before the admin has ever saved the settings page):
        if ( $has_extra_price ) {
            return true;
        }

        return self::is_default_addon_attribute( $taxonomy, $tax_label );
    }

    /**
     * Determine default addon attributes before admin saves settings for the first time
     */
    public static function is_default_addon_attribute( $taxonomy, $tax_label = '' ) {
        $tax_slug   = trim( mb_strtolower( urldecode( $taxonomy ), 'UTF-8' ) );
        $label_slug = trim( mb_strtolower( $tax_label, 'UTF-8' ) );

        // Exact slug matches (only exact base or color)
        $exact_slugs = array(
            'pa_base', 'pa_payeh', 'pa_پایه',
            'pa_color', 'pa_colour', 'pa_rang', 'pa_رنگ'
        );
        if ( in_array( $tax_slug, $exact_slugs, true ) ) {
            return true;
        }

        // Exact label matches (strictly 'پایه' or 'رنگ', NEVER technical sewing phrases like 'سیستم بلند کننده پایه خودکار')
        $exact_labels = array(
            'پایه', 'رنگ', 'color', 'colour', 'base'
        );
        if ( in_array( $label_slug, $exact_labels, true ) ) {
            return true;
        }

        return false;
    }

    /**
     * Frontend: Render attribute options as stylish pill buttons
     */
    public function render_product_attribute_options() {
        global $product;

        if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
            return;
        }

        $product_id     = $product->get_id();
        $cache_ver      = (int) get_option( 'wdaa_cache_version', 1 );
        $cache_key      = 'wdaa_product_addons_' . $product_id . '_v' . $cache_ver;
        $addon_sections = wp_cache_get( $cache_key, 'wdaa' );

        if ( false === $addon_sections ) {
            $addon_sections = array();
            $attributes     = $product->get_attributes();

            if ( ! empty( $attributes ) ) {
                $has_saved_config = is_array( get_option( 'wdaa_enabled_addons', null ) );

                foreach ( $attributes as $attribute ) {
                    // Must be a registered global taxonomy attribute (pa_*)
                    if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'is_taxonomy' ) || ! $attribute->is_taxonomy() ) {
                        continue;
                    }

                    // If used for variations (e.g. Size), let WooCommerce dropdown handle it!
                    if ( method_exists( $attribute, 'get_variation' ) && $attribute->get_variation() ) {
                        continue;
                    }

                    $taxonomy = method_exists( $attribute, 'get_name' ) ? $attribute->get_name() : '';
                    if ( empty( $taxonomy ) ) {
                        continue;
                    }

                    // Early skip: avoid querying terms & termmeta for attributes not enabled in settings
                    if ( $has_saved_config && ! self::is_addon_attribute( $taxonomy ) ) {
                        continue;
                    }

                    // Safely get all assigned terms for this product attribute
                    $terms = wc_get_product_terms( $product_id, $taxonomy, array( 'fields' => 'all' ) );

                    if ( empty( $terms ) || is_wp_error( $terms ) ) {
                        continue;
                    }

                    // Prime all term meta in a single batched query
                    update_termmeta_cache( wp_list_pluck( $terms, 'term_id' ) );

                    $has_extra_price = false;
                    $terms_data      = array();

                    foreach ( $terms as $term ) {
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

                    // Check if this attribute is an Add-on when using fallback rules
                    if ( ! $has_saved_config && ! self::is_addon_attribute( $taxonomy, $label, $has_extra_price ) ) {
                        continue;
                    }

                    // Sort options so 0 price / lowest price is first (and pre-selected)
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

            wp_cache_set( $cache_key, $addon_sections, 'wdaa', HOUR_IN_SECONDS );
        }

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
                                    esc_html( get_woocommerce_currency_symbol() ),
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
                                    <?php echo wp_kses_post( $price_badge ); ?>
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
     */
    public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
        if ( ! isset( $_POST['wdaa_option'] ) || ! is_array( $_POST['wdaa_option'] ) ) {
            return $cart_item_data;
        }

        // Verify add-to-cart nonce unconditionally for all requests
        if ( ! isset( $_POST['wdaa_cart_nonce'] ) || ! is_string( $_POST['wdaa_cart_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wdaa_cart_nonce'] ) ), 'wdaa_add_to_cart' ) ) {
            return $cart_item_data;
        }

        $raw_options       = map_deep( wp_unslash( $_POST['wdaa_option'] ), 'sanitize_text_field' );
        $selected_addons   = array();
        $total_extra_price = 0;
        $product_id        = absint( $product_id );
        $variation_id      = absint( $variation_id );
        $has_saved_config  = is_array( get_option( 'wdaa_enabled_addons', null ) );

        foreach ( $raw_options as $taxonomy => $term_id ) {
            if ( ! is_scalar( $term_id ) || ! is_string( $taxonomy ) ) {
                continue;
            }

            $term_id  = absint( $term_id );
            $tax_name = sanitize_text_field( $taxonomy );

            if ( empty( $tax_name ) || $term_id <= 0 ) {
                continue;
            }

            // Early check if admin has saved enabled add-ons
            if ( $has_saved_config && ! self::is_addon_attribute( $tax_name ) ) {
                continue;
            }

            // Single query per attribute: fetch product terms and validate membership in memory
            $product_terms = wc_get_product_terms( $product_id, $tax_name, array( 'fields' => 'all' ) );
            if ( empty( $product_terms ) || is_wp_error( $product_terms ) ) {
                continue;
            }

            $matched_term = null;
            $term_ids     = array();
            foreach ( $product_terms as $p_term ) {
                $term_ids[] = $p_term->term_id;
                if ( (int) $p_term->term_id === $term_id ) {
                    $matched_term = $p_term;
                }
            }

            if ( ! $matched_term ) {
                continue;
            }

            // Prime termmeta cache in one query for all terms of this attribute
            update_termmeta_cache( $term_ids );

            $extra_price = (float) get_term_meta( $term_id, '_wdaa_extra_price', true );
            $tax_label   = wc_attribute_label( $tax_name );

            if ( ! $has_saved_config ) {
                $has_any_extra_price = ( $extra_price > 0 );
                if ( ! $has_any_extra_price ) {
                    foreach ( $term_ids as $p_term_id ) {
                        if ( (float) get_term_meta( $p_term_id, '_wdaa_extra_price', true ) > 0 ) {
                            $has_any_extra_price = true;
                            break;
                        }
                    }
                }

                if ( ! self::is_addon_attribute( $tax_name, $tax_label, $has_any_extra_price ) ) {
                    continue;
                }
            }

            $selected_addons[] = array(
                'taxonomy'    => $tax_name,
                'tax_label'   => $tax_label,
                'term_id'     => $term_id,
                'term_name'   => sanitize_text_field( $matched_term->name ),
                'extra_price' => $extra_price,
            );

            $total_extra_price += $extra_price;
        }

        if ( ! empty( $selected_addons ) ) {
            $cart_item_data['wdaa_addons']      = $selected_addons;
            $cart_item_data['wdaa_extra_price'] = $total_extra_price;
            // Deterministic hash of selected addons so identical addon selections merge in cart while different ones stay separate
            $cart_item_data['wdaa_unique_id']   = md5( wp_json_encode( $selected_addons ) );
        }

        return $cart_item_data;
    }

    /**
     * Cart: Display selected addon under product title in Cart and Checkout
     */
    public function display_cart_item_data( $item_data, $cart_item ) {
        if ( ! empty( $cart_item['wdaa_addons'] ) && is_array( $cart_item['wdaa_addons'] ) ) {
            foreach ( $cart_item['wdaa_addons'] as $addon ) {
                $display_value = esc_html( $addon['term_name'] );
                if ( $addon['extra_price'] > 0 ) {
                    $display_value .= ' (+' . esc_html( number_format_i18n( (float) $addon['extra_price'] ) ) . ' ' . esc_html( get_woocommerce_currency_symbol() ) . ')';
                }

                $item_data[] = array(
                    'key'   => esc_html( $addon['tax_label'] ),
                    'value' => wp_kses_post( $display_value ),
                );
            }
        }

        return $item_data;
    }

    /**
     * Cart & Checkout: Calculate separate fee row for selected options
     */
    public function calculate_addon_fees( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        if ( ! is_object( $cart ) || ! method_exists( $cart, 'is_empty' ) || $cart->is_empty() ) {
            return;
        }

        $fees_to_add = array();

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( ! empty( $cart_item['wdaa_addons'] ) && is_array( $cart_item['wdaa_addons'] ) ) {
                $item_qty = isset( $cart_item['quantity'] ) ? absint( $cart_item['quantity'] ) : 1;

                foreach ( $cart_item['wdaa_addons'] as $addon ) {
                    if ( ! empty( $addon['extra_price'] ) && $addon['extra_price'] > 0 ) {
                        // Title for the separate fee row in invoice
                        $fee_title = sprintf( __( 'هزینه %s', 'wdaa' ), $addon['term_name'] );

                        if ( ! isset( $fees_to_add[ $fee_title ] ) ) {
                            $fees_to_add[ $fee_title ] = 0;
                        }

                        // Multiplied by product quantity (e.g. 2 mannequins = 2 bases)
                        $fees_to_add[ $fee_title ] += ( $addon['extra_price'] * $item_qty );
                    }
                }
            }
        }

        foreach ( $fees_to_add as $title => $amount ) {
            if ( $amount > 0 ) {
                $cart->add_fee( $title, $amount, false );
            }
        }
    }

    /**
     * Order: Save addon selection to order item meta
     */
    public function save_order_line_item_data( $item, $cart_item_key, $values, $order ) {
        if ( ! empty( $values['wdaa_addons'] ) && is_array( $values['wdaa_addons'] ) ) {
            foreach ( $values['wdaa_addons'] as $addon ) {
                $meta_value = $addon['term_name'];
                if ( $addon['extra_price'] > 0 ) {
                    $meta_value .= ' (+' . number_format_i18n( (float) $addon['extra_price'] ) . ' ' . get_woocommerce_currency_symbol() . ')';
                }
                $item->add_meta_data( $addon['tax_label'], $meta_value, true );
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
            'update_term_meta_cache' => true,
            'meta_query'             => array(
                array(
                    'key'     => '_wdaa_extra_price',
                    'value'   => 0,
                    'compare' => '>',
                    'type'    => 'NUMERIC',
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
        $saved_addons         = get_option( 'wdaa_enabled_addons', null );
        ?>
        <div class="wrap wdaa-admin-wrap">
            <h1 class="wdaa-admin-title">⚡ <?php esc_html_e( 'افزونه ویژگی‌های قیمت‌دار هوشمند ووکامرس', 'wdaa' ); ?></h1>

            <?php settings_errors(); ?>

            <div class="card wdaa-admin-card">
                <h2 class="wdaa-admin-card-title">⚙️ <?php esc_html_e( 'انتخاب ویژگی‌هایی که به عنوان افزودنی (Add-on) نمایش داده می‌شوند', 'wdaa' ); ?></h2>
                <p class="wdaa-admin-card-desc"><?php esc_html_e( 'ویژگی‌هایی که در زیر تیک می‌زنید، در برگه محصول به عنوان دکمه‌های کارتی مدرن نمایش داده می‌شوند (چه مثل پایه هزینه اضافه داشته باشند و چه مثل رنگ رایگان باشند):', 'wdaa' ); ?></p>
                <form method="post" action="options.php">
                    <?php settings_fields( 'wdaa_settings_group' ); ?>
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
                                    $tax_name    = wc_attribute_taxonomy_name( $tax->attribute_name );
                                    $decoded_tax = urldecode( $tax_name );

                                    if ( is_array( $saved_addons ) ) {
                                        $is_checked = false;
                                        foreach ( $saved_addons as $saved ) {
                                            if ( $saved === $tax_name || $saved === $decoded_tax || urldecode( $saved ) === $decoded_tax ) {
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
                    <?php submit_button( __( 'ذخیره تغییرات ویژگی‌ها', 'wdaa' ), 'primary', 'submit', false ); ?>
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
                                    <td class="wdaa-priced-amount">+<?php echo esc_html( number_format_i18n( (float) $item['price'] ) ); ?> <?php echo esc_html( get_woocommerce_currency_symbol() ); ?></td>
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

// Instantiate the plugin
WDAA_Attribute_Addons::get_instance();
