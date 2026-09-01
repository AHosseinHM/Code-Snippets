<?php
/**
 * Plugin Name: JALDIO Bale Orders
 * Description: ارسال سفارش‌های جدید ووکامرس به بله، با صفحه تنظیمات زیر منوی ووکامرس و پشتیبانی از Checkout کلاسیک و Blocks.
 * Version: 1.2.0
 * Author: JALDIO
 * Requires Plugins: woocommerce
 * Text Domain: jaldio-bale-orders
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class Jaldio_Bale_Orders {
    const OPTION_TOKEN   = 'jaldio_bale_token';
    const OPTION_CHAT_ID = 'jaldio_bale_chat_id';
    const META_SENT      = '_jaldio_bale_sent';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 99 );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

        // Classic checkout: fired after order is saved with items.
        add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'handle_order' ), 20, 1 );

        // Checkout Block / Store API: order has completed processing and is ready for payment.
        add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'handle_order' ), 20, 1 );

        // Fallbacks for custom checkout/payment flows. Duplicate protection prevents repeat sends.
        add_action( 'woocommerce_payment_complete', array( __CLASS__, 'handle_order_id' ), 20, 1 );
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'handle_order_id' ), 20, 1 );
        add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'handle_order_id' ), 20, 1 );
    }

    public static function admin_menu() {
        add_submenu_page(
            'woocommerce',
            'ارسال سفارش‌ها به بله',
            'بله سفارش‌ها',
            'manage_woocommerce',
            'jaldio-bale-orders',
            array( __CLASS__, 'settings_page' )
        );
    }

    public static function register_settings() {
        register_setting( 'jaldio_bale_orders_group', self::OPTION_TOKEN, array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ) );
        register_setting( 'jaldio_bale_orders_group', self::OPTION_CHAT_ID, array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ) );
    }

    public static function settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        ?>
        <div class="wrap" dir="rtl">
            <h1>ارسال سفارش‌های ووکامرس به بله</h1>
            <p>توکن ربات بله و Chat ID مقصد را وارد کنید.</p>
            <form method="post" action="options.php">
                <?php settings_fields( 'jaldio_bale_orders_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="jaldio_bale_token">توکن ربات بله</label></th>
                        <td><input type="text" class="regular-text" id="jaldio_bale_token" name="<?php echo esc_attr( self::OPTION_TOKEN ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_TOKEN, '' ) ); ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="jaldio_bale_chat_id">Chat ID</label></th>
                        <td><input type="text" class="regular-text" id="jaldio_bale_chat_id" name="<?php echo esc_attr( self::OPTION_CHAT_ID ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_CHAT_ID, '' ) ); ?>"></td>
                    </tr>
                </table>
                <?php submit_button( 'ذخیره تنظیمات' ); ?>
            </form>
            <hr>
            <p><strong>نکته:</strong> اگر Snippet یا نسخه قدیمی افزونه فعال است، آن را غیرفعال کنید تا پیام تکراری ارسال نشود.</p>
        </div>
        <?php
    }

    public static function handle_order_id( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order ) self::handle_order( $order );
    }

    public static function handle_order( $order ) {
        if ( is_numeric( $order ) ) $order = wc_get_order( $order );
        if ( ! $order instanceof WC_Order ) return;

        if ( $order->get_meta( self::META_SENT, true ) ) return;

        // Always re-read persisted order to avoid stale/incomplete in-memory objects.
        $fresh_order = wc_get_order( $order->get_id() );
        if ( $fresh_order instanceof WC_Order ) $order = $fresh_order;

        $items = $order->get_items( 'line_item' );
        if ( empty( $items ) ) {
            // Do not send an incomplete message. Fallback hooks can retry later in the same order lifecycle.
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->warning(
                    'Order #' . $order->get_id() . ' has no line items yet; Bale message skipped for retry on a later order hook.',
                    array( 'source' => 'jaldio-bale-orders' )
                );
            }
            return;
        }

        $message = self::build_message( $order, $items );
        if ( ! $message ) return;

        if ( self::send_message( $message ) ) {
            $order->update_meta_data( self::META_SENT, current_time( 'mysql' ) );
            $order->save();
        }
    }

    private static function build_message( WC_Order $order, $items ) {
        $order_id = $order->get_id();
        $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        if ( ! $name ) $name = 'ثبت نشده';
        $phone = $order->get_billing_phone() ?: 'ثبت نشده';
        $email = $order->get_billing_email() ?: 'ثبت نشده';
        $payment = $order->get_payment_method_title() ?: 'ثبت نشده';
        $shipping = $order->get_shipping_method() ?: 'ثبت نشده';

        $address_parts = array_filter( array(
            self::state_name( $order ),
            $order->get_billing_city(),
            $order->get_billing_address_1(),
            $order->get_billing_address_2(),
        ) );
        $address = implode( '، ', $address_parts );
        if ( $order->get_billing_postcode() ) {
            $address .= ( $address ? "\n" : '' ) . 'کد پستی: ' . $order->get_billing_postcode();
        }
        if ( ! $address ) $address = 'ثبت نشده';

        $products = '';
        foreach ( $items as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) continue;
            $products .= '• ' . $item->get_name() . ' × ' . (int) $item->get_quantity() . ' — ' . self::price( $item->get_total() ) . "\n";
            $product = $item->get_product();
            if ( $product && $product->get_sku() ) {
                $products .= '  SKU: ' . $product->get_sku() . "\n";
            }
            // فقط ویژگی‌های واقعی محصول متغیر (مثل رنگ و سایز) نمایش داده شوند.
            // متاهای داخلی افزونه‌ها مانند _puiw_* عمداً نمایش داده نمی‌شوند.
            if ( $product && $product->is_type( 'variation' ) ) {
                $variation_attributes = $product->get_variation_attributes();

                foreach ( $variation_attributes as $attribute_name => $attribute_value ) {
                    if ( '' === $attribute_value ) continue;

                    $taxonomy = str_replace( 'attribute_', '', $attribute_name );
                    $label    = wc_attribute_label( $taxonomy, $product );
                    $value    = $attribute_value;

                    if ( taxonomy_exists( $taxonomy ) ) {
                        $term = get_term_by( 'slug', $attribute_value, $taxonomy );
                        if ( $term && ! is_wp_error( $term ) ) {
                            $value = $term->name;
                        }
                    }

                    $products .= '  ' . wp_strip_all_tags( $label ) . ': ' . wp_strip_all_tags( $value ) . "\n";
                }
            }

            $products .= "\n";
        }
        $products = trim( $products );
        if ( ! $products ) return false;

        $status = wc_get_order_status_name( $order->get_status() );
        $note = $order->get_customer_note() ?: 'بدون توضیحات';
        $date = $order->get_date_created();
        $date_text = $date ? $date->date_i18n( 'Y/m/d H:i' ) : wp_date( 'Y/m/d H:i' );
        $admin_url = method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order_id . '&action=edit' );

        $message  = "🛒 سفارش جدید ثبت شد\n";
        $message .= "━━━━━━━━━━━━━━━━━━\n\n";
        $message .= "🔢 شماره سفارش: #{$order_id}\n";
        $message .= "📅 تاریخ: {$date_text}\n\n";
        $message .= "👤 اطلاعات مشتری\n";
        $message .= "نام: {$name}\n📱 موبایل: {$phone}\n📧 ایمیل: {$email}\n\n";
        $message .= "📦 محصولات سفارش\n{$products}\n\n";
        $message .= "💰 مبلغ کل: " . self::price( $order->get_total() ) . "\n";
        $message .= "💳 روش پرداخت: {$payment}\n🚚 روش ارسال: {$shipping}\n📌 وضعیت: {$status}\n\n";
        $message .= "📍 آدرس مشتری\n{$address}\n\n";
        $message .= "📝 توضیحات مشتری\n{$note}\n\n";
        $message .= "━━━━━━━━━━━━━━━━━━\n🔗 مشاهده سفارش در پنل مدیریت:\n{$admin_url}";
        return $message;
    }

    private static function state_name( WC_Order $order ) {
        $state = $order->get_billing_state();
        if ( ! $state ) return '';
        $country = $order->get_billing_country();
        if ( function_exists( 'WC' ) && WC()->countries ) {
            $states = WC()->countries->get_states( $country );
            if ( is_array( $states ) && isset( $states[ $state ] ) ) return $states[ $state ];
        }
        return $state;
    }

    private static function price( $price ) {
        return number_format_i18n( (float) $price ) . ' تومان';
    }

    private static function send_message( $message ) {
        $token = trim( (string) get_option( self::OPTION_TOKEN, '' ) );
        $chat_id = trim( (string) get_option( self::OPTION_CHAT_ID, '' ) );
        if ( ! $token || ! $chat_id ) return false;

        $url = 'https://tapi.bale.ai/bot' . rawurlencode( $token ) . '/sendMessage';
        $response = wp_remote_post( $url, array(
            'timeout' => 20,
            'body' => array( 'chat_id' => $chat_id, 'text' => $message ),
        ) );

        if ( is_wp_error( $response ) ) {
            self::log_error( $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 300 ) {
            self::log_error( 'HTTP ' . $code . ' - ' . $body );
            return false;
        }
        return true;
    }

    private static function log_error( $message ) {
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->error( $message, array( 'source' => 'jaldio-bale-orders' ) );
        }
    }
}

add_action( 'plugins_loaded', function() {
    if ( class_exists( 'WooCommerce' ) ) {
        Jaldio_Bale_Orders::init();
    }
} );
