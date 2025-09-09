<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_SePay extends WC_Payment_Gateway
{
    protected $api;

    public $displayed_bank_name;

    public $bank_name_display_type;

    public $bank_accounts = [];

    public $bank_bin;

    public $bank_logo_url;

    private $cached_bank_account_data = null;

    private $cached_bank_accounts = null;

    private $cached_user_info = null;

    public function __construct()
    {
        $this->api = new WC_SePay_API();
        $this->id = 'sepay';
        $this->has_fields = false;
        $this->method_title = 'SePay';
        $this->method_description = 'Thanh toán qua chuyển khoản ngân hàng với QR Code (VietQR). Tự động xác nhận thanh toán qua <a href="https://sepay.vn" target="_blank">SePay</a>.';
        $this->supports = ['products'];

        if (is_admin()) {
            $this->init_form_fields();
        }
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->bank_name_display_type = $this->get_option('show_bank_name');

        $bank_data = $this->get_bank_data();

        $this->get_bank_account_data();

        if ($this->cached_bank_account_data && $this->api->is_connected()) {
            $this->bank_bin = $this->cached_bank_account_data['bank']['bin'];
            $this->bank_logo_url = $this->cached_bank_account_data['bank']['logo_url'];
        } else {
            $this->bank_bin = array_key_exists($this->get_option('bank_select'), $bank_data) ? $bank_data[$this->get_option('bank_select')]['bin'] : null;
            $this->bank_logo_url = array_key_exists($this->get_option('bank_select'), $bank_data) ? sprintf('https://my.sepay.vn/assets/images/banklogo/%s.png', strtolower($bank_data[$this->get_option('bank_select')]['short_name'])) : null;
        }

        if (! $this->api->is_connected()) {
            $bank_brand_name = array_key_exists($this->get_option('bank_select'), $bank_data) ? $bank_data[$this->get_option('bank_select')]['short_name'] : null;
            if ($this->bank_name_display_type == "brand_name")
                $this->displayed_bank_name = $bank_brand_name;
            else if ($this->bank_name_display_type == "full_name")
                $this->displayed_bank_name = $bank_data[$this->get_option('bank_select')]['full_name'];
            else if ($this->bank_name_display_type == "full_include_brand")
                $this->displayed_bank_name = $bank_data[$this->get_option('bank_select')]['full_name'] . " (" . $bank_data[$this->get_option('bank_select')]['short_name'] . ")";
            else
                $this->displayed_bank_name = $bank_brand_name;

            $this->method_description .= '<br><div id="content-render">URL API của bạn là: <span id="site_url">Đang tải url ...</span></div>';
        } elseif ($this->cached_bank_account_data) {
            $this->displayed_bank_name = $this->get_display_bank_name($this->cached_bank_account_data['bank']);
        }

        add_action('admin_init', [$this, 'lazy_load_bank_data']);
        add_action('woocommerce_api_wc_sepay_oauth', [$this, 'sepay_handle_oauth_callback']);
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);

        if (isset($_GET['reconnect']) && isset($_GET['_wpnonce'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'sepay_reconnect') && current_user_can('manage_woocommerce')) {
                $oauth_url = $this->api->get_oauth_url();
                if (! $oauth_url) {
                    WC_Admin_Settings::add_error('Không thể kết nối tới SePay. Vui lòng thử lại sau.');
                    return;
                }
                wp_redirect($oauth_url);
                exit;
            } else {
                WC_Admin_Settings::add_error('Xác thực thất bại. Vui lòng thử lại.');
                return;
            }
        }

        if (isset($_GET['disconnect']) && isset($_GET['_wpnonce'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'sepay_disconnect') && current_user_can('manage_woocommerce')) {
                $this->api->disconnect();
                wp_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=sepay'));
                exit;
            } else {
                WC_Admin_Settings::add_error('Xác thực thất bại. Vui lòng thử lại.');
                return;
            }
        }
    }

    public function lazy_load_bank_data()
    {
        if (!is_admin()) {
            return;
        }
        $this->initialize_bank_account_logic();
    }

    public function get_bank_account_data()
    {
        if ($this->cached_bank_account_data === null && $this->get_option('bank_account') && $this->api->is_connected()) {
            $this->cached_bank_account_data = $this->api->get_bank_account($this->get_option('bank_account'));

            if ($this->cached_bank_account_data) {
                $settings = get_option('woocommerce_sepay_settings', []);
                $settings['bank_select'] = strtolower($this->cached_bank_account_data['bank']['short_name']);
                $settings['bank_account_number'] = $this->cached_bank_account_data['account_number'];
                $settings['bank_account_holder'] = $this->cached_bank_account_data['account_holder_name'];
                update_option('woocommerce_sepay_settings', $settings);
            }
        }
        return $this->cached_bank_account_data;
    }

    public function get_bank_accounts()
    {
        if ($this->cached_bank_accounts === null) {
            $this->cached_bank_accounts = $this->api->get_bank_accounts();
        }
        return $this->cached_bank_accounts;
    }

    public function get_user_info()
    {
        if ($this->cached_user_info === null) {
            $this->cached_user_info = $this->api->get_user_info();
        }
        return $this->cached_user_info;
    }

    public function process_admin_options()
    {
        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_key($_POST['_wpnonce']), 'woocommerce-settings')) {
            WC_Admin_Settings::add_error('Nonce verification failed');
            return false;
        }

        if (! $this->api->is_connected()) {
            return parent::process_admin_options();
        }

        $bank_account_id = isset($_POST["{$this->plugin_id}{$this->id}_bank_account"])
            ? sanitize_text_field(wp_unslash($_POST["{$this->plugin_id}{$this->id}_bank_account"]))
            : null;

        $sub_account = isset($_POST["{$this->plugin_id}{$this->id}_sub_account"])
            ? sanitize_text_field(wp_unslash($_POST["{$this->plugin_id}{$this->id}_sub_account"]))
            : null;

        if (empty($bank_account_id)) {
            WC_Admin_Settings::add_error('Vui lòng chọn tài khoản ngân hàng trước khi lưu.');
            return false;
        }

        $excluded_sub_account_banks = ['TPBank', 'VPBank', 'VietinBank'];
        $bank_account = array_filter($this->bank_accounts, function ($account) use ($bank_account_id) {
            return $account['id'] == $bank_account_id;
        });

        if (!empty($bank_account)) {
            reset($bank_account);
            $key = key($bank_account);
            $bank_short_name = $bank_account[$key]['bank']['short_name'];

            if (in_array($bank_short_name, $excluded_sub_account_banks) && !empty($sub_account)) {
                WC_Admin_Settings::add_error('Ngân hàng ' . $bank_short_name . ' không hỗ trợ tài khoản VA. Vui lòng bỏ chọn tài khoản VA.');
                return false;
            }
        }

        $required_sub_account_banks = ['BIDV', 'OCB', 'MSB', 'KienLongBank'];
        $bank_account = array_filter($this->bank_accounts, function ($account) use ($bank_account_id) {
            return $account['id'] == $bank_account_id;
        });

        if (!empty($bank_account)) {
            reset($bank_account);
            $key = key($bank_account);
            $bank_short_name = $bank_account[$key]['bank']['short_name'];

            if (in_array($bank_short_name, $required_sub_account_banks) && empty($sub_account)) {
                WC_Admin_Settings::add_error('Ngân hàng ' . $bank_short_name . ' yêu cầu phải chọn tài khoản VA. Vui lòng chọn tài khoản VA trước khi lưu.');
                return false;
            }
        }

        $current_settings = get_option('woocommerce_sepay_settings', []);
        $current_bank_account_id = isset($current_settings['bank_account']) ? $current_settings['bank_account'] : null;

        if ($current_bank_account_id !== $bank_account_id) {
            $this->update_sepay_webhook($bank_account_id);
        }

        $pay_code_prefixes = $this->api->get_pay_code_prefixes();
        $pay_code_prefix = isset($_POST["{$this->plugin_id}{$this->id}_pay_code_prefix"])
            ? sanitize_text_field(wp_unslash($_POST["{$this->plugin_id}{$this->id}_pay_code_prefix"]))
            : null;

        if (empty($pay_code_prefix)) {
            WC_Admin_Settings::add_error('Vui lòng chọn tiền tố mã thanh toán');
            return false;
        }

        $prefix_data = array_values(array_filter($pay_code_prefixes, function ($prefix) use ($pay_code_prefix) {
            return $prefix['prefix'] === $pay_code_prefix;
        }));

        if (empty($prefix_data)) {
            WC_Admin_Settings::add_error('Tiền tố mã thanh toán không hợp lệ');
            return false;
        }

        $current_prefix = $prefix_data[0];
        $needs_update = $current_prefix['suffix_from'] !== 1 || $current_prefix['suffix_to'] < 10;

        if ($needs_update) {
            try {
                $response = $this->api->update_company_configurations([
                    'payment_code_formats' => [
                        [
                            'prefix' => $pay_code_prefix,
                            'suffix_from' => 1,
                            'suffix_to' => $current_prefix['suffix_to'] < 10 ? 10 : $current_prefix['suffix_to'],
                            'character_type' => 'NumberAndLetter',
                            'is_active' => 1,
                        ],
                    ],
                ]);

                if (is_wp_error($response)) {
                    WC_Admin_Settings::add_error('Không thể cập nhật cấu hình mã thanh toán');
                    return false;
                }
            } catch (Exception $e) {
                WC_Admin_Settings::add_error($e->getMessage());
                return false;
            }
        }

        return parent::process_admin_options();
    }

    private function update_sepay_webhook($bank_account_id)
    {
        $webhook_id = get_option('wc_sepay_webhook_id');

        if (empty($webhook_id)) {
            return false;
        }

        $webhook = $this->api->get_webhook($webhook_id);

        if (! $webhook) {
            $response = $this->api->create_webhook($bank_account_id);

            if (is_wp_error($response)) {
                return false;
            }

            return true;
        }

        $response = $this->api->update_webhook($webhook_id, [
            'bank_account_id' => (int) $bank_account_id,
        ]);

        if (empty($response)) {
            return false;
        }

        return true;
    }

    public function init_form_fields()
    {
        if (! $this->api->is_connected()) {
            try {
                $this->api->refresh_token();
            } catch (Exception $e) {
                $this->init_old_form_fields();
                return;
            }

            $this->init_form_fields();
            return;
        }

        $form_fields = [
            'enabled' => [
                'title' => 'Bật/Tắt',
                'type' => 'checkbox',
                'label' => 'Bật SePay',
                'default' => 'no',
            ],
            'title' => [
                'title' => 'Tiêu đề',
                'type' => 'text',
                'description' => 'Tiêu đề hiển thị cho khách hàng khi thanh toán.',
                'default' => 'Chuyển khoản ngân hàng (Quét mã QR)',
                'desc_tip' => true,
            ],
            'description' => [
                'title' => 'Mô tả',
                'type' => 'textarea',
                'description' => 'Mô tả hiển thị cho khách hàng khi thanh toán.',
                'default' => 'Thanh toán chuyển khoản qua mã QR (VietQR). Tự động xác nhận thanh toán bởi SePay.',
                'desc_tip' => true,
            ],
        ];

        $this->bank_accounts = $this->get_bank_accounts();
        $account_options = $this->build_bank_account_options();

        $pay_code_prefixes = $this->api->get_pay_code_prefixes();
        $prefix_options = array_combine(array_column($pay_code_prefixes, 'prefix'), array_column($pay_code_prefixes, 'prefix'));

        if (! is_null($this->bank_accounts)) {
            $form_fields['bank_account'] = [
                'title' => 'Tài khoản ngân hàng',
                'type' => 'select',
                'description' => 'Chọn tài khoản ngân hàng để hiển thị mã QR và hướng dẫn thanh toán.',
                'options' => $account_options,
                'class' => 'sepay-bank-account',
                'default' => '',
                'desc_tip' => true,
            ];

            $selected_bank_account = $this->get_option('bank_account');
            $sub_account_options = [];

            $excluded_sub_account_banks = ['TPBank', 'VPBank', 'VietinBank'];
            $show_sub_account_field = true;

            if (!empty($selected_bank_account)) {
                $bank_account = array_filter($this->bank_accounts, function ($account) use ($selected_bank_account) {
                    return $account['id'] == $selected_bank_account;
                });

                if (!empty($bank_account)) {
                    reset($bank_account);
                    $key = key($bank_account);
                    $bank_short_name = $bank_account[$key]['bank']['short_name'];

                    if (in_array($bank_short_name, $excluded_sub_account_banks)) {
                        $show_sub_account_field = false;
                    }
                }
            }

            if ($show_sub_account_field) {
                $sub_accounts = $this->api->get_bank_sub_accounts($selected_bank_account);

                $sub_account_options = [];

                if (! empty($sub_accounts)) {
                    $sub_account_options[''] = '-- Chọn tài khoản ảo --';
                    foreach ($sub_accounts as $sub_account) {
                        $sub_account_options[$sub_account['account_number']] = $sub_account['account_number'] . ($sub_account['label'] ? " - {$sub_account['label']}" : '');
                    }
                } else {
                    $sub_account_options[''] = '-- Không có tài khoản VA nào --';
                }

                $form_fields['sub_account'] = [
                    'title' => 'Tài khoản VA',
                    'type' => 'select',
                    'description' => 'Chọn tài khoản VA để nhận thanh toán (nếu có). Một số ngân hàng yêu cầu phải chọn tài khoản VA. <br> (Lưu ý: TPBank, VPBank và VietinBank không hỗ trợ tài khoản VA).',
                    'options' => $sub_account_options,
                    'class' => 'dynamic-sub-account',
                    'default' => $this->get_option('sub_account'),
                ];
            } else {
                $form_fields['sub_account'] = [
                    'title' => 'Tài khoản VA',
                    'type' => 'select',
                    'description' => 'Ngân hàng này không hỗ trợ tài khoản VA.',
                    'options' => [
                        '' => '-- Không hỗ trợ tài khoản VA --',
                    ],
                    'class' => 'dynamic-sub-account',
                    'default' => '',
                    'custom_attributes' => [
                        'disabled' => 'disabled',
                    ],
                ];
            }
        }

        $form_fields['pay_code_prefix'] = [
            'title' => 'Tiền tố mã thanh toán',
            'type' => 'select',
            'description' => 'Chọn tiền tố cho mã thanh toán. Cấu hình mã thanh toán tại <a href="https://my.sepay.vn/company/configuration" target="_blank">Cấu hình chung</a> trên SePay.',
            'options' => $prefix_options,
            'class' => 'sepay-pay-code-prefix',
            'default' => '',
        ];

        $form_fields['success_message'] = [
            'title' => 'Thông báo thành công',
            'type' => 'textarea',
            'description' => 'Thông báo hiển thị khi thanh toán thành công. HTML và JS được hỗ trợ.',
            'default' => '<h2 class="text-success">Thanh toán thành công</h2>',
            'desc_tip' => true,
        ];

        $form_fields['order_when_completed'] = [
            'title' => 'Trạng thái đơn hàng khi hoàn tất',
            'type' => 'select',
            'description' => 'Chọn trạng thái đơn hàng khi thanh toán hoàn tất. Nếu chọn "Không chỉ định", trạng thái đơn hàng sẽ được xử lý theo luồng của WooCommerce.',
            'options' => $this->getWcOrderStatuses(),
            'default' => 'processing',
            'desc_tip' => true,
        ];

        $form_fields['download_mode'] = [
            'title' => 'Chế độ tải xuống',
            'type' => 'select',
            'description' => 'Chọn chế độ tải xuống cho khách hàng. Dành cho các sản phẩm có thể tải xuống.',
            'options' => [
                'auto' => 'Tự động',
                'manual' => 'Thủ công',
            ],
            'default' => 'auto',
            'desc_tip' => true,
        ];

        $form_fields['show_bank_name'] = [
            'title' => 'Hiển thị tên ngân hàng',
            'type' => 'select',
            'description' => 'Thông tin hiển thị tên ngân hàng tại ô thanh toán. Ví dụ: Tên viết tắt: MSB. Tên đầy đủ: Ngân hàng TMCP Hàng Hải Việt Nam. Tên đầy đủ kèm tên viết tắt: Ngân hàng TMCP Hàng Hải Việt Nam (MSB).',
            'options' => [
                'brand_name' => 'Tên viết tắt',
                'full_name' => 'Tên đầy đủ',
                'full_include_brand' => 'Tên đầy đủ kèm viết tắt',
            ],
            'default' => 'brand_name',
            'desc_tip' => true,
        ];

        $form_fields['logo'] = [
            'title' => 'Logo',
            'type' => 'text',
            'description' => 'URL logo hiển thị trên phương thức thanh toán ở trang thanh toán.',
            'default' => plugins_url('assets/images/sepay-logo.png', __DIR__),
        ];

        $this->form_fields = $form_fields;
    }

    public function init_old_form_fields()
    {
        $this->form_fields = array(
            'url_root' => array(
                'title'       => '',
                'label'       => '',
                'type'        => 'hidden',
                'description' => '',
                'default'     => get_site_url(),
            ),
            'enabled' => array(
                'title'       => 'Bật/Tắt',
                'label'       => 'Bật/Tắt SePay Gateway',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => 'Tên hiển thị',
                'type'        => 'text',
                'description' => 'Tên phương thức thanh toán. Tên này sẽ hiển thị ở trang thanh toán.',
                'desc_tip'    => true,
                'default'     => 'Chuyển khoản ngân hàng (Quét mã QR)'
            ),
            'description' => array(
                'title'       => 'Mô tả',
                'type'        => 'textarea',
                'desc_tip'    => true,
                'description' => 'Mô tả này sẽ hiển thị ở trang thanh toán phía khách hàng.',
                'default'     => 'Chuyển khoản vào tài khoản của chúng tôi (Có thể quét mã QR). Đơn hàng sẽ được xác nhận ngay sau khi chuyển khoản thành công.',
            ),
            'bank_select' => array(
                'title'       => 'Ngân hàng',
                'type'        => 'select',
                'class'    => 'wc-enhanced-select',
                'css'      => 'min-width: 350px;',
                'desc_tip'    => true,
                'description' => 'Chọn đúng ngân hàng nhận thanh toán của bạn.',
                'options'  => array(
                    'vietcombank' => 'Vietcombank',
                    'vpbank' => 'VPBank',
                    'acb' => 'ACB',
                    'sacombank' => 'Sacombank',
                    'hdbank' => 'HDBank',
                    'vietinbank' => 'VietinBank',
                    'techcombank' => 'Techcombank',
                    'mbbank' => 'MBBank',
                    'bidv' => 'BIDV',
                    'msb' => 'MSB',
                    'shinhanbank' => 'ShinhanBank',
                    'tpbank' => 'TPBank',
                    'eximbank' => 'Eximbank',
                    'vib' => 'VIB',
                    'agribank' => 'Agribank',
                    'publicbank' => 'PublicBank',
                    'kienlongbank' => 'KienLongBank',
                    'ocb' => 'OCB',
                ),
            ),
            'bank_account_number' => array(
                'title'       => 'Số tài khoản',
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => 'Điền đúng số tài khoản ngân hàng.',
            ),
            'bank_account_holder' => array(
                'title'       => 'Chủ tài khoản',
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => 'Điền đúng tên chủ tài khoản.',
            ),
            'pay_code_prefix' => array(
                'title'       => 'Tiền tố mã thanh toán',
                'type'        => 'text',
                'default'     => 'DH',
                'desc_tip'    => true,
                'description' => 'Hãy chắn chắn Tiền tố mã thanh toán tại đây trùng khớp với Tiền tố tại my.sepay.vn -> Cấu hình công ty -> Cấu trúc mã thanh toán',
            ),
            'api_key' => array(
                'title'       => 'API Key',
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => 'Điền API Key này vào SePay khi bạn tạo webhook tại my.sepay.vn. API Key phải dài hơn 10 ký tự, chỉ bao gồm chữ và số.',
                'default'     =>  bin2hex(random_bytes(24)),
            ),
            'success_message' => array(
                'title'       => 'Thông điệp thanh toán thành công',
                'type'        => 'textarea',
                'desc_tip'    => true,
                'description' => 'Nội dung thể hiện sau khi khách hàng thanh toán thành công. Hỗ trợ chữ thuần, HTML và Javascript',
                'default'     => '<h2 class="text-success">Thanh toán thành công</h2>',
            ),
            'order_when_completed' => array(
                'title'       => 'Trạng thái đơn hàng sau thanh toán',
                'type'        => 'select',
                'desc_tip'    => true,
                'description' => 'Trạng thái đơn hàng sau khi thanh toán thành công. Nếu bạn không chỉ định, trạng thái đơn hàng sẽ được xử lý theo luồng của WooCommerce.',
                'options'  => $this->getWcOrderStatuses(),
                'default' => 'processing',
            ),
            'download_mode' => array(
                'title'       => 'Chế độ tải xuống sau khi thanh toán',
                'type'        => 'select',
                'desc_tip'    => true,
                'description' => 'Dành cho các sản phẩm có thể tải xuống',
                'options' => [
                    'auto' => 'Tự động',
                    'manual' => 'Thủ công'
                ],
                'default' => 'manual'
            ),
            'show_bank_name' => array(
                'title'       => 'Hiển thị tên ngân hàng',
                'type'        => 'select',
                'desc_tip'    => true,
                'description' => 'Thông tin hiển thị tên ngân hàng tại ô thanh toán.Ví dụ: Tên viết tắt: MSB. Tên đầy đủ: Ngân hàng TMCP Hàng Hải Việt Nam. Tên đầy đủ kèm tên viết tắt: Ngân hàng TMCP Hàng Hải Việt Nam (MSB)',
                'options'  => array(
                    'brand_name' => 'Tên viết tắt',
                    'full_name' => 'Tên đầy đủ',
                    'full_include_brand' => 'Tên đầy đủ kèm tên viết tắt',
                ),
            ),
        );
    }

    public function getWcOrderStatuses()
    {
        $statuses = wc_get_order_statuses();
        $result = [];

        foreach ($statuses as $key => $label) {
            $result[str_replace('wc-', '', $key)] = $label;
        }

        return $result;
    }

    public function admin_options()
    {
        if ($this->api->is_connected()) {
            $webhook_id = get_option('wc_sepay_webhook_id');

            if (empty($webhook_id)) {
                global $hide_save_button;
                $hide_save_button = true;

                $this->display_admin_header();
                $this->render_setup_webhook_view();
                return;
            }

            $webhook = $this->api->get_webhook($webhook_id);

            if (! $webhook) {
                $this->api->create_webhook($this->get_option('bank_account'));
            }

            $user_info = $this->api->get_user_info();

            $last_connected_at = get_option('wc_sepay_last_connected_at', null);

            if ($last_connected_at) {
                $last_connected_at = date_i18n('d/m/Y H:i:s', strtotime($last_connected_at));
            }

            $reconnect_url = wp_nonce_url(
                admin_url('admin.php?page=wc-settings&tab=checkout&section=sepay&reconnect=1'),
                'sepay_reconnect'
            );

            $disconnect_url = wp_nonce_url(
                admin_url('admin.php?page=wc-settings&tab=checkout&section=sepay&disconnect=1'),
                'sepay_disconnect'
            );

            require_once plugin_dir_path(__FILE__) . 'views/user-info.php';

            if ($user_info) {
                parent::admin_options();
            } else {
                global $hide_save_button;
                $hide_save_button = true;
            }

            return;
        }

        if (isset($_GET['oauth2'])) {
            $this->display_admin_header();
            $this->render_connect_account_view();
        } else {
            $connect_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=sepay&oauth2=1');
            require_once plugin_dir_path(__FILE__) . 'views/oauth2-connect.php';
            parent::admin_options();
        }
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $this->update_order_status_and_clear_cart($order);

        return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }

    public function thankyou_page($order_id)
    {
        $order = wc_get_order($order_id);
        $remark = $this->get_remark($order_id);

        if ($this->api->is_connected() && $this->cached_bank_account_data) {
            $bank_account_id = $this->get_option('bank_account');
            $bank_account = $this->api->get_bank_account($bank_account_id);

            $required_sub_account_banks = ['BIDV', 'OCB', 'MSB', 'KienLongBank'];
            $bank_short_name = $this->cached_bank_account_data['bank']['short_name'];

            if (in_array($bank_short_name, $required_sub_account_banks)) {
                $account_number = $this->get_option('sub_account');
                if (empty($account_number)) {
                    $account_number = $bank_account['account_number'];
                }
            } else {
                $account_number = $this->get_option('sub_account') ? $this->get_option('sub_account') : $bank_account['account_number'];
            }

            $account_holder_name = $this->cached_bank_account_data['account_holder_name'];
            $bank_bin = $this->cached_bank_account_data['bank']['bin'];
            $bank_logo_url = $this->cached_bank_account_data['bank']['logo_url'];
            $displayed_bank_name = $this->displayed_bank_name;
        } else {
            $bank_select = $this->get_option('bank_select');
            $bank_info = $this->get_bank_info($bank_select);

            $account_number = $this->get_option('sub_account') ? $this->get_option('sub_account') : $this->get_option('bank_account_number');
            $account_holder_name = $this->get_option('bank_account_holder');

            if ($bank_info) {
                $bank_bin = $bank_info['bin'];
                $bank_logo_url = sprintf('https://my.sepay.vn/assets/images/banklogo/%s.png', strtolower($bank_info['short_name']));

                if ($this->bank_name_display_type == "brand_name") {
                    $displayed_bank_name = $bank_info['short_name'];
                } else if ($this->bank_name_display_type == "full_name") {
                    $displayed_bank_name = $bank_info['full_name'];
                } else if ($this->bank_name_display_type == "full_include_brand") {
                    $displayed_bank_name = $bank_info['full_name'] . " (" . $bank_info['short_name'] . ")";
                } else {
                    $displayed_bank_name = $bank_info['short_name'];
                }
            }
        }

        if ($this->should_skip_thankyou_page($order)) {
            return;
        }

        $qr_code_url = sprintf(
            'https://qr.sepay.vn/img?acc=%s&bank=%s&amount=%s&des=%s&template=compact',
            $account_number,
            $bank_bin,
            $order->get_total(),
            $remark
        );

        require_once plugin_dir_path(__FILE__) . 'views/transfer-info.php';

        $this->enqueue_sepay_scripts($order_id, $order);
    }

    public function enqueue_sepay_scripts($order_id, $order)
    {
        $script_version = filemtime(plugin_dir_path(__DIR__) . 'assets/js/sepay.js');
        $style_version = filemtime(plugin_dir_path(__DIR__) . 'assets/css/sepay.css');

        wp_enqueue_script('sepay_script', plugin_dir_url(__DIR__) . 'assets/js/sepay.js', ['jquery'], $script_version, true);
        wp_enqueue_style('sepay_style', plugin_dir_url(__DIR__) . 'assets/css/sepay.css', [], $style_version);

        if ($this->api->is_connected()) {
            $required_sub_account_banks = ['BIDV', 'OCB', 'MSB', 'KienLongBank'];
            $bank_short_name = $this->cached_bank_account_data['bank']['short_name'] ?? '';

            if (in_array($bank_short_name, $required_sub_account_banks)) {
                $account_number = $this->get_option('sub_account');
                if (empty($account_number)) {
                    $account_number = $this->cached_bank_account_data['account_number'] ?? $this->get_option('bank_account_number');
                }
            } else {
                $account_number = $this->get_option('sub_account') ? $this->get_option('sub_account') : ($this->cached_bank_account_data['account_number'] ?? $this->get_option('bank_account_number'));
            }
        } else {
            $account_number = $this->get_option('sub_account') ? $this->get_option('sub_account') : $this->get_option('bank_account_number');
        }

        wp_localize_script('sepay_script', 'sepay_vars', [
            'ajax_url' => esc_url(admin_url('admin-ajax.php')),
            'order_code' => $this->get_option('pay_code_prefix') . $order_id,
            'account_number' => $account_number,
            'remark' => $this->get_remark($order_id),
            'amount' => $order->get_total(),
            'order_nonce' => wp_create_nonce('submit_order'),
            'order_id' => $order_id,
            'download_mode' => $this->get_option('download_mode'),
            'success_message' => $this->get_option('success_message') ? wp_kses_post($this->get_option('success_message')) : '<p>Thanh toán thành công!</p>',
        ]);
    }

    public function get_remark($order_id): string
    {
        $remark = $this->get_option('pay_code_prefix') . $order_id;

        // VietinBank, ABBANK
        if (in_array($this->bank_bin, ['970415', '970425'])) {
            $remark = "SEVQR $remark";
        }

        return $remark;
    }

    public function sepay_handle_oauth_callback()
    {
        if (empty($_GET['access_token']) || empty($_GET['refresh_token']) || empty($_GET['state'])) {
            wp_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=sepay'));
            exit;
        }

        $saved_state = get_transient('wc_sepay_oauth_state');

        if ($_GET['state'] !== $saved_state) {
            wp_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=sepay'));
            exit;
        }

        update_option('wc_sepay_access_token', sanitize_text_field(wp_unslash($_GET['access_token'])));
        update_option('wc_sepay_refresh_token', sanitize_text_field(wp_unslash($_GET['refresh_token'])));
        $expires_in = isset($_GET['expires_in']) ? intval(wp_unslash($_GET['expires_in'])) : 3600;
        update_option('wc_sepay_token_expires', time() + $expires_in);
        update_option('wc_sepay_last_connected_at', current_time('mysql'));

        delete_transient('wc_sepay_rate_limited');
        delete_transient('wc_sepay_refresh_failure');
        delete_transient('wc_sepay_oauth_url');
        delete_transient('wc_sepay_oauth_rate_limited');
        delete_transient('wc_sepay_oauth_state');

        wp_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=sepay'));
        exit;
    }

    private function build_bank_account_options()
    {
        $options = [];
        foreach ($this->bank_accounts as $account) {
            $label = sprintf(
                '%s - %s - %s',
                $account['bank']['short_name'],
                $account['account_number'],
                $account['account_holder_name']
            );
            $options[$account['id']] = $label;
        }
        return $options;
    }

    private function initialize_bank_account_logic()
    {
        if (!$this->get_option('bank_account')) {
            return;
        }

        $bank_account_data = $this->get_bank_account_data();
        if ($bank_account_data) {
            $this->displayed_bank_name = $this->get_display_bank_name($bank_account_data['bank']);
            $this->bank_bin = $bank_account_data['bank']['bin'];
            $this->bank_logo_url = $bank_account_data['bank']['logo_url'];
        }
    }

    private function get_display_bank_name($bank): string
    {
        switch ($this->bank_name_display_type) {
            case 'full_name':
                return $bank['full_name'];
            case 'full_include_brand':
                return "{$bank['full_name']} ({$bank['short_name']})";
            case 'brand_name':
            default:
                return $bank['short_name'];
        }
    }

    private function should_skip_thankyou_page($order)
    {
        return $order->get_payment_method() !== $this->id ||
            $order->has_status(['processing', 'completed']);
    }

    private function display_admin_header()
    {
        echo '<h2>' . esc_html($this->get_method_title());
        wc_back_link('Quay lại thanh toán', admin_url('admin.php?page=wc-settings&tab=checkout'));
        echo '</h2>';
    }

    private function render_connect_account_view()
    {
        global $hide_save_button;
        $hide_save_button = true;

        try {
            $sepayOauthUrl = $this->api->get_oauth_url();
        } catch (Exception $e) {
            $sepayOauthUrl = null;
        }

        $sepayLogoUrl = plugin_dir_url(__DIR__) . 'assets/images/banner.png';
        require_once plugin_dir_path(__FILE__) . 'views/connect-account.php';
    }

    private function update_order_status_and_clear_cart($order)
    {
        $order->update_status('on-hold', 'Đang chờ thanh toán');

        if (function_exists('WC')) {
            WC()->cart->empty_cart();
        }
    }

    private function render_setup_webhook_view()
    {
        $bank_accounts = $this->bank_accounts;
        $old_bank_account_number = $this->get_option('bank_account_number');
        $reconnect_url = wp_nonce_url(
            add_query_arg('reconnect', '1', admin_url('admin.php?page=wc-settings&tab=checkout&section=sepay')),
            'sepay_reconnect'
        );
        $disconnect_url = wp_nonce_url(
            add_query_arg('disconnect', '1', admin_url('admin.php?page=wc-settings&tab=checkout&section=sepay')),
            'sepay_disconnect'
        );
        require_once plugin_dir_path(__FILE__) . 'views/setup-webhook.php';
    }

    private function get_bank_data()
    {
        return array(
            'vietcombank' => array('bin' => '970436', 'code' => 'VCB', 'short_name' => 'Vietcombank', 'full_name' => 'Ngân hàng TMCP Ngoại Thương Việt Nam'),
            'vpbank' => array('bin' => '970432', 'code' => 'VPB', 'short_name' => 'VPBank', 'full_name' => 'Ngân hàng TMCP Việt Nam Thịnh Vượng'),
            'acb' => array('bin' => '970416', 'code' => 'ACB', 'short_name' => 'ACB', 'full_name' => 'Ngân hàng TMCP Á Châu'),
            'sacombank' => array('bin' => '970403', 'code' => 'STB', 'short_name' => 'Sacombank', 'full_name' => 'Ngân hàng TMCP Sài Gòn Thương Tín'),
            'hdbank' => array('bin' => '970437', 'code' => 'HDB', 'short_name' => 'HDBank', 'full_name' => 'Ngân hàng TMCP Phát triển Thành phố Hồ Chí Minh'),
            'vietinbank' => array('bin' => '970415', 'code' => 'ICB', 'short_name' => 'VietinBank', 'full_name' => 'Ngân hàng TMCP Công thương Việt Nam'),
            'techcombank' => array('bin' => '970407', 'code' => 'TCB', 'short_name' => 'Techcombank', 'full_name' => 'Ngân hàng TMCP Kỹ thương Việt Nam'),
            'mbbank' => array('bin' => '970422', 'code' => 'MB', 'short_name' => 'MBBank', 'full_name' => 'Ngân hàng TMCP Quân đội'),
            'bidv' => array('bin' => '970418', 'code' => 'BIDV', 'short_name' => 'BIDV', 'full_name' => 'Ngân hàng TMCP Đầu tư và Phát triển Việt Nam'),
            'msb' => array('bin' => '970426', 'code' => 'MSB', 'short_name' => 'MSB', 'full_name' => 'Ngân hàng TMCP Hàng Hải Việt Nam'),
            'shinhanbank' => array('bin' => '970424', 'code' => 'SHBVN', 'short_name' => 'ShinhanBank', 'full_name' => 'Ngân hàng TNHH MTV Shinhan Việt Nam'),
            'tpbank' => array('bin' => '970423', 'code' => 'TPB', 'short_name' => 'TPBank', 'full_name' => 'Ngân hàng TMCP Tiên Phong'),
            'eximbank' => array('bin' => '970431', 'code' => 'EIB', 'short_name' => 'Eximbank', 'full_name' => 'Ngân hàng TMCP Xuất Nhập khẩu Việt Nam'),
            'vib' => array('bin' => '970441', 'code' => 'VIB', 'short_name' => 'VIB', 'full_name' => 'Ngân hàng TMCP Quốc tế Việt Nam'),
            'agribank' => array('bin' => '970405', 'code' => 'VBA', 'short_name' => 'Agribank', 'full_name' => 'Ngân hàng Nông nghiệp và Phát triển Nông thôn Việt Nam'),
            'publicbank' => array('bin' => '970439', 'code' => 'PBVN', 'short_name' => 'PublicBank', 'full_name' => 'Ngân hàng TNHH MTV Public Việt Nam'),
            'kienlongbank' =>  array('bin' => '970452', 'code' => 'KLB', 'short_name' => 'KienLongBank', 'full_name' => 'Ngân hàng TMCP Kiên Long'),
            'ocb' => array('bin' => '970448', 'code' => 'OCB', 'short_name' => 'OCB', 'full_name' => 'Ngân hàng TMCP Phương Đông'),
            'abbank' => array('bin' => '970425', 'code' => 'ABBANK', 'short_name' => 'ABBANK', 'full_name' => 'Ngân hàng TMCP An Bình'),
        );
    }

    private function get_bank_info($identifier)
    {
        $bank_data = $this->get_bank_data();

        if (isset($bank_data[$identifier])) {
            return $bank_data[$identifier];
        }

        foreach ($bank_data as $key => $bank) {
            if (
                strtolower($bank['code']) === strtolower($identifier) ||
                $bank['bin'] === $identifier ||
                $bank['short_name'] === $identifier ||
                strtolower($bank['short_name']) === strtolower($identifier)
            ) {
                return $bank;
            }
        }

        return null;
    }
}
