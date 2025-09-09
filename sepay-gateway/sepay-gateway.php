<?php
/*
 * Plugin Name: SePay Gateway
 * Plugin URI: https://docs.sepay.vn/woocommerce.html
 * Description: SePay - Giải pháp tự động xác nhận thanh toán chuyển khoản ngân hàng
 * Author: SePay Team
 * Author URI: https://sepay.vn/
 * Version: 1.1.19
 * Requires Plugins: woocommerce
 * Text Domain: sepay-gateway
 * License: GNU General Public License v3.0
 */

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

if (!defined('ABSPATH')) {
    exit;
}

if (! defined('SEPAY_API_URL')) {
    define('SEPAY_API_URL', 'https://my.sepay.vn');
}

if (! defined('SEPAY_WC_API_URL')) {
    define('SEPAY_WC_API_URL', 'https://my.sepay.vn');
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'sepay_add_action_links');

function sepay_add_action_links($links): array
{
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=sepay') . '">Cài đặt</a>',
    );

    return array_merge($plugin_links, $links);
}

add_action('plugins_loaded', 'sepay_init_gateway_class');

function sepay_missing_wc_notice()
{
    $install_url = wp_nonce_url(
        add_query_arg(
            [
                'action' => 'install-plugin',
                'plugin' => 'woocommerce',
            ],
            admin_url('update.php')
        ),
        'install-plugin_woocommerce'
    );

    $admin_notice_content = sprintf(
        '%1$sWooCommerce chưa được kích hoạt.%2$s Plugin %3$sWooCommerce%4$s phải được kích hoạt để SePay Gateway có thể hoạt động. Vui lòng %5$scài đặt & kích hoạt WooCommerce &raquo;%6$s',
        '<strong>',
        '</strong>',
        '<a href="http://wordpress.org/extend/plugins/woocommerce/">',
        '</a>',
        '<a href="' . esc_url($install_url) . '">',
        '</a>'
    );

    echo '<div class="error">';
    echo '<p>' . wp_kses_post($admin_notice_content) . '</p>';
    echo '</div>';
}

add_filter('woocommerce_payment_gateways', 'sepay_add_gateway_class');

function sepay_add_gateway_class($gateways)
{
    $gateways[] = 'WC_Gateway_SePay';
    return $gateways;
}

function sepay_init_gateway_class()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'sepay_missing_wc_notice');
        return;
    }

    require_once dirname(__FILE__) . '/includes/class-wc-gateway-sepay.php';
    require_once dirname(__FILE__) . '/includes/class-wc-sepay-api.php';

    add_action('wp_ajax_nopriv_sepay_check_order_status', 'sepay_check_order_status');
    add_action('wp_ajax_sepay_check_order_status', 'sepay_check_order_status');
    add_action('wp_ajax_setup_sepay_webhook', 'handle_setup_sepay_webhook');

    function handle_setup_sepay_webhook()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Bạn không có quyền thực hiện hành động này.']);
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'sepay_webhook_setup')) {
            wp_send_json_error(['message' => 'Invalid nonce verification']);
        }

        $bank_account_id = isset($_POST['bank_account_id']) ? sanitize_text_field(wp_unslash($_POST['bank_account_id'])) : null;
        $sub_account = isset($_POST['sub_account']) ? sanitize_text_field(wp_unslash($_POST['sub_account'])) : null;

        $api = new WC_SePay_API();

        if (!$bank_account_id || (!$sub_account && $api->is_required_sub_account($bank_account_id))) {
            wp_send_json_error(['message' => 'Thiếu thông tin tài khoản ngân hàng hoặc tài khoản VA.']);
        }

        $settings = get_option('woocommerce_sepay_settings', []);

        $api_token = get_option('wc_sepay_webhook_api_key');

        if (empty($api_token)) {
            $api_token = $settings['api_key'] ?? null;
        }

        $webhook_url = home_url('/wp-json/sepay-gateway/v');

        $webhooks = $api->get_webhooks([
            'webhook_url' => $webhook_url,
            'api_key' => $api_token,
        ]);

        $webhook_id = isset($webhooks[0]['id']) ? $webhooks[0]['id'] : null;

        $response = $api->create_webhook($bank_account_id, $webhook_id, $api_token);

        if ($response['status'] !== 'success') {
            wp_send_json_error(['message' => 'Có lỗi xảy ra khi tạo webhook. Vui lòng thử lại sau.']);
        }

        $pay_code_prefixes = $api->get_pay_code_prefixes(false);

        if (empty($pay_code_prefixes)) {
            wp_send_json_error(['message' => 'Không tìm thấy prefix cho mã thanh toán.']);
        }

        $pay_code_prefix = $pay_code_prefixes[0];

        $needs_update = $pay_code_prefix['suffix_from'] !== 1 || $pay_code_prefix['suffix_to'] < 10;

        if ($needs_update) {
            try {
                $response = $api->update_company_configurations([
                    'payment_code_formats' => [
                        [
                            'prefix' => $pay_code_prefix['prefix'],
                            'suffix_from' => 1,
                            'suffix_to' => $pay_code_prefix['suffix_to'] < 10 ? 10 : $pay_code_prefix['suffix_to'],
                            'character_type' => 'NumberAndLetter',
                            'is_active' => 1,
                        ],
                    ],
                ]);

                if (is_wp_error($response)) {
                    wp_send_json_error(['message' => 'Có lỗi xảy ra khi cập nhật mã thanh toán.']);
                }

                $settings['pay_code_prefix'] = $pay_code_prefix['prefix'];
            } catch (Exception $e) {
                wp_send_json_error(['message' => 'Có lỗi xảy ra khi cập nhật mã thanh toán.']);
            }
        }

        $settings['enabled'] = 'yes';
        $settings['bank_account'] = $bank_account_id;
        $settings['sub_account'] = $sub_account;

        $settings['title'] = 'SePay';
        $settings['description'] = 'Thanh toán qua chuyển khoản ngân hàng với QR Code (VietQR). Tự động xác nhận thanh toán qua <a href="https://sepay.vn" target="_blank">SePay</a>.';
        $settings['logo'] = plugin_dir_url(__FILE__) . 'assets/images/sepay-logo.png';

        update_option('woocommerce_sepay_settings', $settings);
        wp_send_json_success(['message' => 'Webhook đã được tạo thành công!']);
    }

    function sepay_check_order_status()
    {
        if (!isset($_POST['order_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['order_nonce'])), 'submit_order')) {
            wp_die();
        }

        if (!isset($_POST['orderID'])) {
            wp_die();
        }

        $order_id = intval($_POST['orderID']);
        $order = wc_get_order($order_id);
        $downloads = [];

        if ($order->has_downloadable_item() && $order->is_download_permitted()) {
            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                $product = wc_get_product($product_id);

                $itemDownloads = array_map(function ($download) use ($product, $product_id) {
                    return [
                        'id' => $download['id'],
                        'product_id' => $product_id,
                        'product_name' => $product->get_data()['name'],
                        'name' => $download['name'],
                        'downloads_remaining' => $download['downloads_remaining'],
                        'download_url' => $download['download_url'],
                        'access_expires' => $download['access_expires'],
                    ];
                }, array_values($item->get_item_downloads()));
                $downloads = array_merge($downloads, $itemDownloads);
            }
        }

        echo wp_json_encode([
            'status' => true,
            'order_status' => $order->get_status(),
            'downloads' => $downloads
        ]);

        wp_die();
    }

    add_action('rest_api_init', function () {
        register_rest_route('sepay-gateway/v1', '/add-payment', [
            'methods' => 'POST',
            'callback' => 'sepay_api',
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('sepay-gateway/v2', '/add-payment', [
            'methods' => 'POST',
            'callback' => 'sepay_api',
            'permission_callback' => '__return_true',
        ]);
    });

    function sepay_api(WP_REST_Request $request): array
    {
        $parameters = $request->get_json_params();
        $authorization = $request->get_header('Authorization');

        $api_key = null;

        if ($authorization) {
            $arr1 = explode(' ', $authorization);

            if (count($arr1) == 2 && $arr1[0] === 'Apikey' && strlen($arr1[1]) >= 10) {
                $api_key = $arr1[1];
            }
        }

        if (!ctype_alnum($api_key) || strlen($api_key) < 10) {
            return [
                'success' => false,
                'message' => 'Invalid API Key format',
            ];
        }

        $api = new WC_SePay_API();
        $payment_gateways = WC_Payment_Gateways::instance();
        /** @var WC_Payment_Gateway $sepay_gateway */
        $sepay_gateway = $payment_gateways->payment_gateways()['sepay'];

        $webhook_api_key = get_option('wc_sepay_webhook_api_key') ? get_option('wc_sepay_webhook_api_key') : $sepay_gateway->get_option('api_key');

        if ($api_key !== $webhook_api_key) {
            return [
                'success' => false,
                'message' => 'Invalid API Key',
            ];
        }

        if (! is_array($parameters)) {
            return [
                'success' => false,
                'message' => 'Invalid JSON request',
            ];
        }

        if (
            !isset($parameters['accountNumber'])
            || !isset($parameters['gateway'])
            || !isset($parameters['code'])
            || !isset($parameters['transferType'])
            || !isset($parameters['transferAmount'])
        ) {
            return [
                'success' => false,
                'message' => 'Not enough required parameters',
            ];
        }

        if ($parameters['transferType'] !== 'in') {
            return [
                'success' => false,
                'message' => 'transferType must be in',
            ];
        }

        $s_order_id = str_replace($sepay_gateway->get_option('pay_code_prefix'), '', $parameters['code']);

        if (!is_numeric($s_order_id)) {
            return [
                'success' => false,
                'message' => "Order ID not found from pay code {$parameters['code']}",
            ];
        }

        $s_order_id = intval($s_order_id);
        $order = wc_get_order($s_order_id);

        if (!$order) {
            return [
                'success' => false,
                'message' => "Order ID $s_order_id not found",
            ];
        }

        $order_status = $order->get_status();

        if (in_array($order_status, ['completed', 'processing'])) {
            return [
                'success' => false,
                'message' => 'This order has already been completed before!',
            ];
        }

        $order_total = (int) $order->get_total();

        if (!is_numeric($order_total) || $order_total <= 0) {
            return [
                'success' => false,
                'message' => 'order_total is <= 0',
            ];
        }

        $order_note = sprintf(
            "SePay: Đã nhận thanh toán <b>%s</b> vào tài khoản <b>%s</b> tại ngân hàng <b>%s</b> vào lúc <b>%s</b>",
            wc_price($parameters['transferAmount']),
            $parameters['accountNumber'],
            $parameters['gateway'],
            $parameters['transactionDate']
        );

        $order_status_when_completed = $sepay_gateway->get_option('order_when_completed') ?: OrderStatus::PROCESSING;
        if ($order_total === $parameters['transferAmount']) {
            if (in_array($order_status_when_completed, array_keys($sepay_gateway->getWcOrderStatuses()))) {
                $order->update_status($order_status_when_completed);
            } else {
                $order->payment_complete();
            }

            wc_reduce_stock_levels($s_order_id);
            $order_note = sprintf(
                '%s. Trạng thái đơn hàng được chuyển từ %s sang %s',
                $order_note,
                wc_get_order_status_name($order_status),
                wc_get_order_status_name($order_status_when_completed)
            );
        } else if ($order_total > $parameters['transferAmount']) {
            $under_payment = wc_price($order_total - $parameters['transferAmount']);
            $order_note = "$order_note. Khách hàng thanh toán THIẾU: <b>$under_payment</b>";
        } else if ($order_total < $parameters['transferAmount']) {
            $over_payment = wc_price($parameters['transferAmount'] - $order_total);
            $order_note = "$order_note. Khách hàng thanh toán THỪA: <b>$over_payment</b>";
        }

        $order->add_order_note($order_note, false);

        return [
            'success' => true,
            'message' => $order_note,
        ];
    }
}

add_action('before_woocommerce_init', 'sepay_declare_woocommerce_support');

add_action('wp_ajax_sepay_get_bank_accounts', 'get_bank_accounts_ajax');
add_action('wp_ajax_sepay_get_bank_sub_accounts', 'get_bank_sub_accounts_ajax');
add_action('wp_ajax_sepay_get_pay_code_prefixes', 'get_paycode_prefix_ajax');

function get_bank_accounts_ajax()
{
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Unauthorized');
    }

    try {
        $api = new WC_SePay_API();
        $accounts = $api->get_bank_accounts(false);
        wp_send_json_success($accounts);
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}

function get_paycode_prefix_ajax()
{
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Unauthorized');
    }

    $api = new WC_SePay_API();
    $prefixes = $api->get_pay_code_prefixes(false);
    wp_send_json_success($prefixes);
}

function get_bank_sub_accounts_ajax()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Bạn không có quyền thực hiện hành động này.']);
    }

    $bank_account_id = isset($_POST['bank_account_id']) ? sanitize_text_field(wp_unslash($_POST['bank_account_id'])) : null;

    if (!$bank_account_id) {
        wp_send_json_error(['message' => 'Thiếu ID tài khoản ngân hàng.']);
    }

    $api = new WC_SePay_API();
    $sub_accounts = $api->get_bank_sub_accounts($bank_account_id);

    if (empty($sub_accounts)) {
        wp_send_json_error(['message' => 'Không tìm thấy tài khoản ảo.']);
    }

    wp_send_json_success($sub_accounts);
}

function sepay_declare_woocommerce_support()
{
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__);
    }
}

add_action('woocommerce_blocks_loaded', 'sepay_woocommerce_block_support');

function sepay_woocommerce_block_support()
{
    if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        require_once dirname(__FILE__) . '/includes/class-wc-sepay-blocks-support.php';

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function (PaymentMethodRegistry $payment_method_registry) {
                $payment_method_registry->register(new WC_SePay_Blocks_Support());
            }
        );
    }
}

add_action('admin_enqueue_scripts', 'sepay_add_scripts');

function sepay_add_scripts()
{
    $script_path = plugin_dir_path(__FILE__) . 'assets/js/main.js';

    if (file_exists($script_path)) {
        $script_version = filemtime($script_path);
    } else {
        $script_version = '';
    }

    wp_register_script(
        'sepay-option-js',
        plugin_dir_url(__FILE__) . 'assets/js/main.js',
        ['jquery'],
        $script_version,
        true
    );

    wp_enqueue_script('sepay-option-js');
}

register_activation_hook(__FILE__, 'sepay_activate');

function sepay_activate()
{
    set_transient('wc_sepay_activation_redirect', true, 30);
}

add_action('admin_init', 'sepay_redirect');

function sepay_redirect()
{
    if (get_transient('wc_sepay_activation_redirect')) {
        delete_transient('wc_sepay_activation_redirect');
        if (!isset($_GET['page']) || $_GET['page'] !== 'wc-settings' || !isset($_GET['section']) || $_GET['section'] !== 'sepay') {
            wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=sepay&oauth2=1'));
            exit;
        }
    }
}

add_action('upgrader_process_complete', 'sepay_clear_cache_after_update', 10, 2);

function sepay_clear_cache_after_update($upgrader_object, $options)
{
    if ($options['action'] === 'update' && $options['type'] === 'plugin') {
        foreach ($options['plugins'] as $plugin) {
            if (plugin_basename(__FILE__) === $plugin) {
                delete_transient('wc_sepay_bank_accounts');
                break;
            }
        }
    }
}
