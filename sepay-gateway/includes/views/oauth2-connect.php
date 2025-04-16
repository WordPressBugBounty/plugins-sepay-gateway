<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wc-sepay-account-settings-container">
    <div class="wc-sepay-account-box">
        <div class="wc-sepay-content">
            <h2>Kết nối tài khoản SePay của bạn</h2>

            <p>Kết nối tài khoản của bạn thông qua OAuth2 để trải nghiệm tính năng bảo mật cao và quản lý xác thực dễ dàng hơn.</p>

            <div class="oauth2-benefits">
                <h4>Lợi ích khi sử dụng OAuth2:</h4>
                <ul>
                    <li>Tự động thiết lập tài khoản ngân hàng và webhook</li>
                    <li>Bảo mật thông tin và xác thực cao</li>
                    <li>Tự động đồng bộ dữ liệu tài khoản ngân hàng</li>
                    <li>Đồng bộ thông tin cấu hình công ty từ SEPAY sang WordPress</li>
                </ul>
            </div>

            <div class="oauth2-actions">
                <a href="<?php echo esc_url($connect_url); ?>" class="button button-primary oauth2-connect-button">
                    Kết nối SePay
                </a>

                <p class="description">
                    Bạn sẽ được chuyển hướng đến trang xác thực tài khoản an toàn.
                </p>
            </div>
        </div>
    </div>
</div>

<style>
    .wc-sepay-account-box {
        background-color: #ffffff;
        color: #1e1e1e;
        position: relative;
        box-shadow: rgba(0, 0, 0, 0.1) 0 0 0 1px;
        outline: none;
        border-radius: 7px;
        max-width: 630px;
        overflow: hidden;
        width: 100%;
    }

    .wc-sepay-content {
        border-radius: 7px;
        box-sizing: border-box;
        height: auto;
        max-height: 100%;
        padding: 16px 24px;
    }

    .wc-sepay-content h2 {
        margin-top: 0;
        margin-bottom: 1em;
        font-size: 16px;
        color: #1e1e1e;
    }

    .wc-sepay-content p {
        margin: 0 0 1em;
        color: #1e1e1e;
    }

    .oauth2-benefits {
        margin: 20px 0;
    }

    .oauth2-benefits h4 {
        margin: 0 0 12px;
        font-size: 14px;
        color: #1e1e1e;
    }

    .oauth2-benefits ul {
        list-style: disc;
        margin-left: 20px;
        margin-bottom: 1em;
    }

    .oauth2-benefits li {
        margin: 8px 0;
        color: #1e1e1e;
    }

    .oauth2-actions {
        margin-top: 24px;
        padding-top: 16px;
        border-top: 1px solid rgba(0, 0, 0, 0.1);
    }

    .oauth2-connect-button {
        padding: 8px 20px !important;
        height: auto !important;
        line-height: 1.4 !important;
        font-size: 14px !important;
        border-radius: 4px !important;
    }

    .oauth2-actions .description {
        margin-top: 10px;
        font-size: 13px;
        color: #757575;
    }
</style>