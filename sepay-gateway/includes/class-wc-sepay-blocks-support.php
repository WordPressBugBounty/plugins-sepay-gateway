<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (! defined('ABSPATH')) {
    exit;
}

final class WC_SePay_Blocks_Support extends AbstractPaymentMethodType
{
    protected $name = 'sepay';

    public function initialize() {
        $this->settings = get_option('woocommerce_sepay_settings', []);
    }

    public function is_active()
    {
        return filter_var($this->get_setting('enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function get_payment_method_script_handles()
    {
        $script_path = plugin_dir_path(__DIR__) . 'assets/js/block/checkout.js';

        if (file_exists($script_path)) {
            $script_version = filemtime($script_path);
        } else {
            $script_version = '';
        }

        wp_register_script(
            'wc-sepay-blocks-integration',
            plugin_dir_url(__DIR__) . 'assets/js/block/checkout.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            $script_version,
            true
        );

        return ['wc-sepay-blocks-integration'];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
            'logo' => $this->get_setting('logo', plugins_url('assets/images/sepay-logo.png', __DIR__)),
            'supports' => $this->get_supported_features(),
        ];
    }
}
