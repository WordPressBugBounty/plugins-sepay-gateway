<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h2>Thiết lập webhook SePay</h2>
    <p>Để hoàn tất cấu hình, vui lòng chọn tài khoản ngân hàng để thiết lập webhook tự động xác nhận thanh toán.</p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('select_bank_account', 'bank_account_nonce'); ?>
        <input type="hidden" name="action" value="handle_bank_account_selection">
        
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="bank_account">Tài khoản ngân hàng</label>
                    </th>
                    <td>
                        <select name="bank_account" id="bank_account" class="regular-text" required>
                            <option value="">-- Chọn tài khoản --</option>
                            <?php foreach ($bank_accounts as $account): ?>
                                <option value="<?php echo esc_attr($account['id']); ?>">
                                    <?php echo esc_html(sprintf(
                                        '%s - %s - %s',
                                        $account['bank']['short_name'],
                                        $account['account_number'],
                                        $account['account_holder_name']
                                    )); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Chọn tài khoản ngân hàng để nhận thông báo thanh toán tự động.</p>
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="submit">
            <input type="submit" name="submit" id="submit" class="button button-primary" value="Thiết lập webhook">
        </p>
    </form>
</div>
