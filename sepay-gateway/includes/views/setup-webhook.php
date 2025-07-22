<style>
    .wc-sepay-webhook-settings-container {
        display: flex;
        flex-direction: column;
        gap: 12px;
        justify-content: center;
        align-items: center;
    }

    .wc-sepay-webhook-box {
        background-color: #ffffff;
        color: #1e1e1e;
        position: relative;
        box-shadow: rgba(0, 0, 0, 0.1) 0 0 0 1px;
        outline: none;
        border-radius: 7px;
        max-width: 570px;
        overflow: hidden;
        padding: 20px;
    }

    .wc-sepay-webhook-box h2 {
        font-size: 20px;
        font-weight: bold;
        margin: 0 0 20px;
    }

    .wc-sepay-helper-text {
        font-size: 14px;
        color: #555;
        margin-bottom: 20px;
    }

    .wc-sepay-account-item {
        display: flex;
        align-items: center;
        gap: 15px;
        border: 1px solid #ddd;
        border-radius: 5px;
        padding: 10px;
        background-color: #f9f9f9;
        transition: 0.3s all ease;
        cursor: pointer;
    }

    .wc-sepay-account-item:hover {
        background-color: #f1f7ff;
        border-color: #007cba;
    }

    .wc-sepay-account-item input {
        margin: 0;
    }

    .wc-sepay-account-icon {
        width: 32px;
        height: 32px;
        object-fit: contain;
        flex-shrink: 0;
        border-radius: 5px;
    }

    .wc-sepay-account-details {
        font-size: 14px;
    }

    .wc-sepay-account-holder {
        font-weight: 600;
        font-size: 15px;
    }

    .button-primary {
        width: 100%;
        text-align: center;
        margin-top: 20px !important;
    }

    .wc-sepay-account-list {
        max-height: 300px;
        overflow-y: auto;
        border-radius: 5px;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .wc-sepay-sub-account-list {
        border: 1px solid #eaeaea;
        border-radius: 10px;
        padding: 15px;
        margin-top: 14px;
        background-color: #fafafa;
    }

    .wc-sepay-sub-account-list p {
        margin-bottom: 10px;
        font-size: 14px;
        font-weight: 600;
    }

    .wc-sepay-sub-account-item {
        display: flex;
        align-items: center;
        gap: 15px;
        margin: 8px 0;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 10px;
        background-color: #ffffff;
        transition: 0.3s all ease;
        cursor: pointer;
    }

    .wc-sepay-sub-account-item:hover {
        background-color: #eef5ff;
        border-color: #007cba;
    }

    .wc-sepay-sub-account-item input {
        margin: 0;
        flex-shrink: 0;
    }

    .wc-sepay-sub-account-details {
        font-size: 14px;
        line-height: 1.5;
    }

    .wc-sepay-sub-account-holder {
        font-weight: 600;
        font-size: 15px;
        color: #1e1e1e;
    }

    .wc-sepay-sub-account-number {
        color: #444;
        font-size: 13px;
    }

    .loading-spinner {
        display: none;
        margin: 10px 0;
        text-align: center;
    }

    .loading-spinner div {
        width: 30px;
        height: 30px;
        margin: auto;
        border: 3px solid #ddd;
        border-top-color: #007cba;
        border-radius: 50%;
        animation: spinner 1s linear infinite;
    }

    @keyframes spinner {
        from {
            transform: rotate(0deg);
        }

        to {
            transform: rotate(360deg);
        }
    }
</style>

<div class="wc-sepay-webhook-settings-container">
    <div class="wc-sepay-webhook-box">
        <h2>Chọn tài khoản ngân hàng</h2>

        <?php if (empty($bank_accounts)): ?>
            <p class="wc-sepay-helper-text">Không tìm thấy tài khoản ngân hàng nào. Vui lòng thêm tài khoản ngân hàng trên trang quản lý tài khoản ngân hàng của SePay trước khi tiếp tục.</p>
            <div style="display: flex; gap: 10px; align-items: center; justify-content: space-between">
                <a href="https://my.sepay.vn/bankaccount/connect" target="_blank">
                    Thêm tài khoản ngân hàng
                </a>
                <a href="<?php echo esc_url($reconnect_url) ?>" class="button">
                    Kết nối lại
                </a>
            </div>
        <?php else: ?>
            <p class="wc-sepay-helper-text">
                Hệ thống cần bạn chọn một tài khoản ngân hàng để sử dụng trong việc nhận và xử lý thanh toán. Vui lòng chọn một
                tài khoản ngân hàng mà bạn muốn sử dụng.
            </p>
            <?php wp_nonce_field('sepay_webhook_setup', 'sepay_webhook_setup_nonce'); ?>
            <div class="wc-sepay-account-list">
                <?php foreach ($bank_accounts as $account): ?>
                    <label class="wc-sepay-account-item">
                        <input type="radio" name="bank_account_id" value="<?php echo esc_attr($account['id']); ?>" data-bank-short-name="<?php echo esc_attr($account['bank']['short_name']); ?>" required <?php echo $account['account_number'] == $old_bank_account_number ? 'checked' : ''; ?>>
                        <?php if (!empty($account['bank']['icon_url'])): ?>
                            <img src="<?php echo esc_url($account['bank']['icon_url']); ?>" alt="<?php echo esc_attr($account['bank']['short_name']); ?>" class="wc-sepay-account-icon">
                        <?php endif; ?>
                        <div class="wc-sepay-account-details">
                            <div class="wc-sepay-account-holder">
                                <?php echo esc_html($account['account_holder_name']); ?>
                            </div>
                            <div>
                                <?php echo esc_html($account['bank']['short_name']); ?> - <?php echo esc_html($account['account_number']); ?>
                            </div>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="wc-sepay-sub-account-list" style="display: none;">
                <p class="wc-sepay-helper-text" style="margin-top: 0">Chọn tài khoản VA:</p>
                <div class="loading-spinner">
                    <div></div>
                </div>
                <div id="wc-sepay-sub-account-container"></div>
            </div>
            <button type="submit" class="components-button is-primary button-primary" id="complete-setup" disabled>Hoàn tất thiết lập</button>
        <?php endif; ?>
    </div>
    <?php if (isset($disconnect_url) && !empty($disconnect_url)): ?>
        <div style="margin-top:12px;">
            <a href="<?php echo esc_url($disconnect_url); ?>" style="color:#0073aa;text-decoration:underline;">Hủy kết nối</a>
        </div>
    <?php endif; ?>
</div>

<script>
    window.addEventListener('beforeunload', function(event) {
        event.stopImmediatePropagation();
    });
</script>