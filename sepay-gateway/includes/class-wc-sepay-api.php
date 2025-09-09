<?php

if (! defined('ABSPATH')) {
    exit;
}

class WC_SePay_API
{
    public function get_oauth_url()
    {
        $this->check_oauth_rate_limit();

        $cached_oauth_url = get_transient('wc_sepay_oauth_url');
        if ($cached_oauth_url) {
            return $cached_oauth_url;
        }

        $state = $this->get_or_create_oauth_state();

        $response = wp_remote_post(SEPAY_WC_API_URL . '/woo/oauth/init', [
            'body' => [
                'redirect_uri' => $this->get_callback_url(),
                'state' => $state,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new Exception(esc_html($response->get_error_message()));
        }

        $this->handle_oauth_rate_limit($response);

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['oauth_url'])) {
            return null;
        }

        set_transient('wc_sepay_oauth_url', $data['oauth_url'], 300);

        return $data['oauth_url'];
    }

    private function check_oauth_rate_limit()
    {
        $rate_limit_until = get_transient('wc_sepay_oauth_rate_limited');
        if ($rate_limit_until && $rate_limit_until > time()) {
            $remaining_time = $rate_limit_until - time();
            throw new Exception("OAuth init rate limited. Please try again in {$remaining_time} seconds.");
        }
    }

    private function handle_oauth_rate_limit($response)
    {
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 429) {
            return;
        }

        $retry_after = wp_remote_retrieve_header($response, 'retry-after');
        $retry_seconds = $retry_after ? intval($retry_after) : 60;
        $rate_limit_until = time() + $retry_seconds;

        set_transient('wc_sepay_oauth_rate_limited', $rate_limit_until, $retry_seconds);

        $this->log_error('OAuth init rate limited by SePay API', [
            'retry_after' => $retry_seconds,
            'rate_limit_until' => date('Y-m-d H:i:s', $rate_limit_until),
            'site' => get_site_url()
        ]);

        throw new Exception("Rate limited. Please try again in {$retry_seconds} seconds.");
    }

    private function get_or_create_oauth_state()
    {
        $state = get_transient('wc_sepay_oauth_state');
        if (!$state) {
            $state = wp_generate_password(32, false);
            set_transient('wc_sepay_oauth_state', $state, 300);
        }
        return $state;
    }

    public function get_bank_accounts($cache = true)
    {
        if (!$this->is_connected()) {
            return null;
        }

        if ($cache) {
            $bank_accounts = get_transient('wc_sepay_bank_accounts');

            if ($bank_accounts) {
                return $bank_accounts;
            }
        } else {
            delete_transient('wc_sepay_bank_accounts');
        }

        try {
            $response = $this->make_request('bank-accounts');
            $data = $response['data'] ?? [];

            if ($cache) {
                set_transient('wc_sepay_bank_accounts', $data, 3600);
            }

            return $data;
        } catch (Exception $e) {
            return [];
        }
    }

    public function get_company_info($cache = true)
    {
        if (!$this->is_connected()) {
            return null;
        }

        if ($cache) {
            $company = get_transient('wc_sepay_company');

            if ($company) {
                return $company;
            }
        } else {
            delete_transient('wc_sepay_company');
        }

        try {
            $response = $this->make_request('company');
            $data = $response['data'] ?? null;

            if ($cache) {
                set_transient('wc_sepay_company', $data, 3600);
            }

            return $data;
        } catch (Exception $e) {
            return null;
        }
    }

    public function get_pay_code_prefixes($cache = true): array
    {
        try {
            $company = $this->get_company_info($cache);

            if (
                empty($company)
                || empty($company['configurations']['paycode'])
                || $company['configurations']['paycode'] !== true
            ) {
                return [];
            }

            $formats = $company['configurations']['payment_code_formats'] ?? [];
            $prefixes = [];

            foreach ($formats as $format) {
                if ($format['is_active']) {
                    $prefixes[] = [
                        'prefix' => $format['prefix'],
                        'suffix_from' => $format['suffix_from'],
                        'suffix_to' => $format['suffix_to'],
                        'character_type' => $format['character_type']
                    ];
                }
            }
            return $prefixes;
        } catch (Exception $e) {
            return [];
        }
    }

    public function update_company_configurations($data)
    {
        if (!$this->is_connected()) {
            return null;
        }

        try {
            $response = $this->make_request('company/configurations', 'PATCH', $data);
            return $response['data'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function log_error($message, $context = [])
    {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->error($message, [
                'source' => 'sepay',
                'context' => $context
            ]);
        }
    }

    public function make_request($endpoint, $method = 'GET', $data = null)
    {
        if (!$this->is_connected()) {
            return null;
        }

        try {
            $access_token = $this->get_access_token();
        } catch (Exception $e) {
            $this->log_error('Failed to get access token', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$access_token) {
            $this->log_error('No access token available');
            return null;
        }

        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
                'User-Agent' => 'WooCommerce-SePay-Gateway/' . $this->get_plugin_version() . ' (WordPress/' . get_bloginfo('version') . '; WooCommerce/' . (defined('WC_VERSION') ? WC_VERSION : 'Unknown') . ')',
            ],
            'timeout' => 30,
        ];

        if ($data !== null && $method !== 'GET') {
            $args['body'] = json_encode($data);
        } else if ($data !== null && $method === 'GET') {
            $endpoint .= '?' . http_build_query($data);
        }

        $url = SEPAY_API_URL . '/api/v1/' . $endpoint;

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->log_error('API request failed', [
                'error' => $response->get_error_message(),
                'endpoint' => $endpoint,
            ]);
            throw new Exception(esc_html($response->get_error_message()));
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($data['error']) && $data['error'] === 'access_denied') {
            $this->log_error('Access denied, attempting to refresh token', [
                'endpoint' => $endpoint
            ]);
            try {
                $this->refresh_token();
            } catch (Exception $e) {
                $this->log_error('Failed to refresh token', [
                    'error' => $e->getMessage(),
                ]);
                return null;
            }

            return $this->make_request($endpoint, $method, $data);
        }

        return $data;
    }

    public function refresh_token()
    {
        $refresh_token = get_option('wc_sepay_refresh_token');
        if (empty($refresh_token)) {
            throw new Exception('No refresh token available');
        }

        $this->check_refresh_rate_limit();

        $response = wp_remote_post(SEPAY_WC_API_URL . '/woo/oauth/refresh', [
            'body' => ['refresh_token' => $refresh_token],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new Exception(esc_html($response->get_error_message()));
        }

        $this->handle_refresh_rate_limit($response);

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $this->validate_refresh_response($data, wp_remote_retrieve_response_code($response));

        $this->update_tokens($data);

        return $data['access_token'];
    }

    private function check_refresh_rate_limit()
    {
        $rate_limit_until = get_transient('wc_sepay_rate_limited');
        if ($rate_limit_until && $rate_limit_until > time()) {
            $remaining_time = $rate_limit_until - time();
            throw new Exception("Rate limited. Please try again in {$remaining_time} seconds.");
        }
    }

    private function handle_refresh_rate_limit($response)
    {
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 429) {
            return;
        }

        $retry_after = wp_remote_retrieve_header($response, 'retry-after');
        $retry_seconds = $retry_after ? intval($retry_after) : 60;
        $rate_limit_until = time() + $retry_seconds;

        set_transient('wc_sepay_rate_limited', $rate_limit_until, $retry_seconds);

        $this->log_error('Rate limited by SePay API', [
            'retry_after' => $retry_seconds,
            'rate_limit_until' => date('Y-m-d H:i:s', $rate_limit_until),
            'site' => get_site_url(),
        ]);

        throw new Exception("Rate limited. Please try again in {$retry_seconds} seconds.");
    }

    private function validate_refresh_response($data, $http_code)
    {
        if (empty($data['access_token'])) {
            $this->log_error('Invalid refresh token response', [
                'response' => $data,
                'http_code' => $http_code,
                'site' => get_site_url(),
            ]);

            if (isset($data['error']) && in_array($data['error'], ['invalid_grant', 'invalid_token', 'unauthorized'])) {
                $this->log_error('Refresh token is invalid, disconnecting', [
                    'error' => $data['error'],
                    'site' => get_site_url(),
                ]);
                $this->disconnect();
                throw new Exception('Refresh token is invalid. Please reconnect to SePay.');
            }

            throw new Exception('Invalid refresh token response');
        }
    }

    private function update_tokens($data)
    {
        $access_token = $data['access_token'];
        $refresh_token = !empty($data['refresh_token']) ? $data['refresh_token'] : get_option('wc_sepay_refresh_token');
        $token_expires = time() + intval($data['expires_in']);

        update_option('wc_sepay_access_token', $access_token);
        update_option('wc_sepay_refresh_token', $refresh_token);
        update_option('wc_sepay_token_expires', $token_expires);

        delete_transient('wc_sepay_rate_limited');
    }

    public function get_access_token()
    {
        $access_token = get_option('wc_sepay_access_token');
        if (empty($access_token)) {
            throw new Exception('Not connected to SePay');
        }

        $this->check_refresh_rate_limit();

        $token_expires = (int) get_option('wc_sepay_token_expires');
        if ($token_expires < time() + 300) {
            $access_token = $this->refresh_token();
        }

        return $access_token;
    }

    public function get_callback_url()
    {
        return add_query_arg('wc-api', 'wc_sepay_oauth', home_url('/'));
    }

    public function is_connected()
    {
        return !empty(get_option('wc_sepay_access_token'))
            && !empty(get_option('wc_sepay_refresh_token'))
            && get_option('wc_sepay_token_expires') > time() + 300;
    }

    public function get_bank_account($id)
    {
        $bank_account = get_transient('wc_sepay_bank_account_' . $id);

        if ($bank_account) {
            return $bank_account;
        }

        try {
            $response = $this->make_request('bank-accounts/' . $id);
            $data = $response['data'] ?? null;

            if ($data) {
                set_transient('wc_sepay_bank_account_' . $id, $data, 3600);
            }

            return $data;
        } catch (Exception $e) {
            return null;
        }
    }

    public function get_webhooks($data = null)
    {
        if (!$this->is_connected()) {
            return [];
        }

        try {
            $response = $this->make_request('webhooks', 'GET', $data);

            return $response['data'] ?? [];
        } catch (Exception $e) {
            return [];
        }
    }

    public function get_webhook($id)
    {
        if (!$this->is_connected()) {
            return null;
        }

        $cache = get_transient('wc_sepay_webhook_' . $id);

        if ($cache) {
            return $cache;
        }

        try {
            $response = $this->make_request('webhooks/' . $id);

            if ($response['data']) {
                set_transient('wc_sepay_webhook_' . $id, $response['data'], 3600);
            }

            return $response['data'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    public function create_webhook($bank_account_id, $webhook_id = null, $api_key = null)
    {
        if (!$this->is_connected()) {
            return null;
        }

        $api_key = $api_key ?: wp_generate_password(32, false);

        $api_path = 'sepay-gateway/v2/add-payment';
        $webhook_url = get_option('permalink_structure') ? '/wp-json/' . $api_path : '/?rest_route=/' . $api_path;
        $webhook_url = home_url($webhook_url);

        if ($webhook_id) {
            $response = $this->update_webhook($webhook_id, [
                'bank_account_id' => (int) $bank_account_id,
                'event_type' => 'In_only',
                'authen_type' => 'Api_Key',
                'request_content_type' => 'Json',
                'api_key' => $api_key,
                'webhook_url' => $webhook_url,
                'name' => sprintf('[%s] WooCommerce Webhook', get_bloginfo('name')),
                'is_verify_payment' => 1,
                'skip_if_no_code' => 1,
                'only_va' => 0,
                'active' => 1,
            ]);
        } else {
            $response = $this->make_request('webhooks', 'POST', [
                'bank_account_id' => (int) $bank_account_id,
                'event_type' => 'In_only',
                'authen_type' => 'Api_Key',
                'request_content_type' => 'Json',
                'api_key' => $api_key,
                'webhook_url' => $webhook_url,
                'name' => sprintf('[%s] WooCommerce Webhook', get_bloginfo('name')),
                'is_verify_payment' => 1,
                'skip_if_no_code' => 1,
                'only_va' => 0,
                'active' => 1,
            ]);
        }

        if (isset($response['status']) && $response['status'] === 'success') {
            update_option('wc_sepay_webhook_id', $response['data']['id'] ?? $webhook_id ?? null);
            update_option('wc_sepay_webhook_api_key', $api_key);
            $settings = get_option('woocommerce_sepay_settings', []);
            $settings['api_key'] = $api_key;
            update_option('woocommerce_sepay_settings', $settings);
        }

        return $response;
    }

    public function get_bank_sub_accounts($bank_account_id, $cache = true)
    {
        if (!$this->is_connected()) {
            return [];
        }

        $cache_key = 'wc_sepay_bank_sub_accounts_' . $bank_account_id;

        if ($cache) {
            $sub_accounts = get_transient($cache_key);

            if (is_array($sub_accounts)) {
                return $sub_accounts;
            }
        }

        try {
            $response = $this->make_request("bank-accounts/$bank_account_id/sub-accounts");
            $data = $response['data'] ?? [];

            if ($cache) {
                set_transient($cache_key, $data, 3600);
            }

            return $data;
        } catch (Exception $e) {
            return [];
        }
    }

    public function update_webhook($webhook_id, $data)
    {
        if (!$this->is_connected()) {
            return null;
        }

        try {
            $response = $this->make_request('webhooks/' . $webhook_id, 'PATCH', $data);

            return $response;
        } catch (Exception $e) {
            return null;
        }
    }

    public function get_user_info()
    {
        if (!$this->is_connected()) {
            return null;
        }

        $user_info = get_transient('wc_sepay_user_info');

        if ($user_info) {
            return $user_info;
        }

        try {
            $response = $this->make_request('me');
            $data = $response['data'] ?? null;

            if ($data) {
                set_transient('wc_sepay_user_info', $data, 3600);
            }

            return $data;
        } catch (Exception $e) {
            return null;
        }
    }

    public function disconnect()
    {
        $this->update_settings_with_webhook_key();

        $this->clear_oauth_data();
        $this->clear_cache_data();
    }

    private function update_settings_with_webhook_key()
    {
        $settings = get_option('woocommerce_sepay_settings');
        if ($settings) {
            $settings['api_key'] = get_option('wc_sepay_webhook_api_key');
            update_option('woocommerce_sepay_settings', $settings);
        }
    }

    private function clear_oauth_data()
    {
        delete_option('wc_sepay_access_token');
        delete_option('wc_sepay_refresh_token');
        delete_option('wc_sepay_token_expires');
        delete_option('wc_sepay_webhook_id');
        delete_option('wc_sepay_webhook_api_key');
        delete_option('wc_sepay_last_connected_at');
    }

    private function clear_cache_data()
    {
        $transients = [
            'wc_sepay_bank_accounts',
            'wc_sepay_user_info',
            'wc_sepay_company',
            'wc_sepay_bank_sub_accounts',
            'wc_sepay_rate_limited',
            'wc_sepay_oauth_rate_limited',
        ];

        foreach ($transients as $transient) {
            delete_transient($transient);
        }
    }

    public function is_required_sub_account($bank_account_id, $bank_accounts = null)
    {
        $required_sub_account_banks = ['BIDV', 'OCB', 'MSB', 'KienLongBank'];
        $excluded_sub_account_banks = ['TPBank', 'VPBank', 'VietinBank'];
        $bank_accounts = $bank_accounts ?? $this->get_bank_accounts();

        $bank_account = array_filter($bank_accounts, function ($account) use ($bank_account_id) {
            return $account['id'] == $bank_account_id;
        });

        if (empty($bank_account)) {
            return false;
        }

        reset($bank_account);
        $key = key($bank_account);
        $bank_short_name = $bank_account[$key]['bank']['short_name'];

        if (in_array($bank_short_name, $excluded_sub_account_banks)) {
            return false;
        }

        return in_array($bank_short_name, $required_sub_account_banks);
    }

    private function get_plugin_version()
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_file = dirname(dirname(__FILE__)) . '/sepay-gateway.php';
        $plugin_data = get_plugin_data($plugin_file);

        return $plugin_data['Version'] ?? '1.0.0';
    }
}
