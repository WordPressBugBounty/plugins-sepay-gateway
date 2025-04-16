<style>
    .sepay-user-card {
        background-color: #f9f9f9;
        border: 1px solid #e5e5e5;
        border-radius: 5px;
        padding: 20px;
        margin-top: 20px;
    }

    .sepay-user-info h3 {
        margin: 0 0 20px;
    }

    .user-details p {
        margin: 0 0 10px;
    }

    .user-actions {
        margin-top: 20px;
    }

    .user-actions a {
        margin-right: 10px;
    }

    .sepay-user-info-error p {
        margin: 0;
    }

    .sepay-user-info-error .error {
        color: #ff0000;
    }
</style>

<div class="sepay-user-card">
    <?php if (!empty($user_info)) : ?>
        <div class="sepay-user-info">
            <h3>Thông tin tài khoản SePay</h3>
            <div class="user-details">
                <p><strong>Người dùng:</strong> <?php echo esc_html($user_info['last_name']) . ' ' . esc_html($user_info['first_name']) ?></p>
                <p><strong>Email:</strong> <?php echo esc_html($user_info['email']) ?></p>
                <p><strong>Lần kết nối gần đây:</strong>
                    <?php echo !empty($last_connected_at) ? esc_html($last_connected_at) : 'Chưa có dữ liệu' ?>
                </p>
            </div>
            <div class="user-actions">
                <a href="<?php echo esc_url($disconnect_url) ?>" class="button button-secondary button-small" onclick="return confirm('Bạn có chắc chắn muốn ngắt kết nối không? Cấu hình tài khoản SePay của bạn đã kết nối sẽ bị xóa khỏi trang web này.')">
                    Ngắt kết nối
                </a>
            </div>
        </div>
    <?php else : ?>
        <div class="sepay-user-info-error">
            <p class="error">Không thể lấy thông tin tài khoản. Vui lòng kết nối lại.</p>
            <div class="user-actions">
                <a href="<?php echo esc_url($reconnect_url) ?>" class="button button-primary">
                    Kết nối lại
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>
