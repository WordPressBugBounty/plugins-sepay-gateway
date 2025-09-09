<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<style>
    .wc-sepay-account-settings-container {
        display: flex;
        justify-content: center;
        padding: 20px;
    }

    .wc-sepay-account-box {
        background-color: #ffffff;
        color: #1e1e1e;
        position: relative;
        box-shadow: rgba(0, 0, 0, 0.1) 0 0 0 1px;
        outline: none;
        border-radius: 7px;
        max-width: 570px;
        overflow: hidden;
    }

    .wc-sepay-account-box img {
        width: 100%;
        height: auto;
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
</style>

<div class="wc-sepay-account-settings-container">
    <div class="wc-sepay-account-box">
        <img src="<?php echo esc_url($sepayLogoUrl) ?>" alt="SePay Logo">
        <div class="wc-sepay-content">
            <h2>Bắt đầu với SePay</h2>
            <p>Để bắt đầu với SePay, bạn cần kết nối tài khoản SePay của mình với cửa hàng WooCommerce.</p>
            <?php if ($sepayOauthUrl): ?>
                <a href="<?php echo esc_url($sepayOauthUrl) ?>" class="components-button is-primary">Kết nối tài khoản</a>
            <?php else: ?>
                <p style="color: #d63638; font-weight: bold;">Không thể kết nối tới SePay. Vui lòng thử lại sau.</p>
                <button onclick="window.location.reload()" class="components-button is-secondary">Thử lại</button>
            <?php endif; ?>
        </div>
    </div>
</div>