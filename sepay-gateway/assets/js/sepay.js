let pay_status = 'Unpaid';
let account_number = sepay_vars.account_number;
let remark = sepay_vars.remark;
let amount = sepay_vars.amount;
let order_nonce = sepay_vars.order_nonce;
let download_mode = sepay_vars.download_mode;
let success_message = sepay_vars.success_message;

function check_invoice_status() {
    jQuery.ajax({
        url: sepay_vars.ajax_url,
        type: 'POST',
        data: {
            order_nonce: order_nonce,
            action: 'sepay_check_order_status',
            orderID: sepay_vars.order_id,
        },
        dataType: 'JSON',
        success: function (data) {
            pay_status = 'Unpaid';
            if (data.status === true && (data.order_status === 'processing' || data.order_status === 'completed')) {
                var div_paid_message = document.createElement('div');
                div_paid_message.innerHTML = `
                    <div class="paid-notification">
                        <svg version="1.1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 130.2 130.2">
                            <circle class="path circle" fill="none" stroke="#73AF55" stroke-width="6" stroke-miterlimit="10" cx="65.1" cy="65.1" r="62.1"/>
                            <polyline class="path check" fill="none" stroke="#73AF55" stroke-width="6" stroke-linecap="round" stroke-miterlimit="10" points="100.2,40.2 51.5,88.8 29.8,67.5 "/>
                        </svg>
                        ${success_message}
                    </div>
                `;

                jQuery('.sepay-message').append(div_paid_message);
                pay_status = 'Paid';

                jQuery('.sepay-pay-info').hide();
                jQuery('.sepay-pay-footer').hide();

                if (data.downloads.length === 0) return;

                jQuery('.sepay-download').show();

                let availableDownloads = data.downloads.filter((download) => download.downloads_remaining === '' || download.downloads_remaining > 0);

                if (download_mode === 'auto') {
                    let interval;
                    let downloadCount = 0;

                    function download_multiple(urls) {
                        if (urls.length === 0) {
                            clearInterval(interval);
                            return;
                        }

                        let url = urls.pop();
                        let downloadItemIndex = availableDownloads.findIndex((download) => download.download_url === url);

                        if (downloadItemIndex < 0) return;

                        if (!(availableDownloads[downloadItemIndex].downloads_remaining === '' || availableDownloads[downloadItemIndex].downloads_remaining >= 1)) {
                            availableDownloads.splice(downloadItemIndex, 1);
                            return;
                        }

                        let downloadFrame = document.createElement('iframe');
                        downloadFrame.style.display = 'none';
                        document.body.appendChild(downloadFrame);

                        try {
                            downloadFrame.contentWindow.location.href = url;
                            console.log(`Downloading: ${url}`);
                            downloadCount++;

                            let countdownElem = document.querySelector('.sepay-download .countdown');
                            if (countdownElem) {
                                countdownElem.innerHTML = `Đã bắt đầu tải ${downloadCount} tệp. Vui lòng kiểm tra thư mục tải xuống...`;
                            }

                            setTimeout(() => {
                                document.body.removeChild(downloadFrame);
                            }, 2000);
                        } catch (e) {
                            console.error('Download error:', e);
                            document.body.removeChild(downloadFrame);
                        }

                        if (availableDownloads[downloadItemIndex].downloads_remaining > 0) {
                            availableDownloads[downloadItemIndex].downloads_remaining = availableDownloads[downloadItemIndex].downloads_remaining - 1;
                        }
                    }

                    setTimeout(() => {
                        let urls = availableDownloads.map((download) => download.download_url);

                        if (urls.length === 0) {
                            alert('Toàn bộ lượt tải xuống đã hết, vui lòng kiểm tra chi tiết đơn hàng và mục tải xuống.');
                            return;
                        }

                        let countdownElem = document.querySelector('.sepay-download .countdown');
                        if (countdownElem) {
                            countdownElem.innerHTML = `Bắt đầu tải xuống ${urls.length} tệp...`;
                        }

                        interval = setInterval(() => download_multiple(urls), 1000);
                    }, 2000);

                    jQuery('.force-download').on('click', () => {
                        let urls = availableDownloads.map((download) => download.download_url);

                        if (urls.length === 0) {
                            alert('Toàn bộ lượt tải xuống đã hết, vui lòng kiểm tra chi tiết đơn hàng và mục tải xuống.');
                            return;
                        }

                        clearInterval(interval);
                        downloadCount = 0;

                        urls.forEach((url) => {
                            let a = document.createElement('a');
                            a.href = url;
                            a.download = '';
                            a.target = '_blank';
                            a.style.display = 'none';
                            document.body.appendChild(a);
                            a.click();
                            setTimeout(() => {
                                document.body.removeChild(a);
                            }, 100);
                            downloadCount++;
                        });

                        let countdownElem = document.querySelector('.sepay-download .countdown');
                        if (countdownElem) {
                            countdownElem.innerHTML = `Đã bắt đầu tải ${downloadCount} tệp. Vui lòng kiểm tra thư mục tải xuống...`;
                        }
                    });
                }

                if (download_mode === 'manual') {
                    const downloadGroups = [...new Set(data.downloads.map((download) => download.product_name))];

                    function formatDate(date) {
                        date = new Date();
                        let year = new Intl.DateTimeFormat('vi', { year: 'numeric' }).format(date);
                        let month = new Intl.DateTimeFormat('vi', { month: '2-digit' }).format(date);
                        let day = new Intl.DateTimeFormat('vi', { day: '2-digit' }).format(date);

                        return `${day}/${month}/${year}`;
                    }

                    function decrementDownloadRemaining(downloadId) {
                        let downloadItemIndex = availableDownloads.findIndex((download) => download.id === downloadId);

                        if (downloadItemIndex < 0) return;

                        if (availableDownloads[downloadItemIndex].downloads_remaining !== '' && availableDownloads[downloadItemIndex].downloads_remaining < 1) {
                            availableDownloads.splice(downloadItemIndex, 1);
                            jQuery(`#${downloadId} .download-button`).removeAttr('href').attr('disabled', true);
                            return;
                        }

                        if (availableDownloads[downloadItemIndex].downloads_remaining > 0) {
                            availableDownloads[downloadItemIndex].downloads_remaining = availableDownloads[downloadItemIndex].downloads_remaining - 1;
                            jQuery(`#${downloadId} .remaining`).html(availableDownloads[downloadItemIndex].downloads_remaining);
                        }
                    }

                    jQuery(document).on('click', '.download-item .download-button', (event) => {
                        const downloadId = jQuery(event.currentTarget).attr('download-id');

                        decrementDownloadRemaining(downloadId);
                    });

                    downloadGroups.forEach((group) => {
                        let downloadItemsHtml = '';

                        availableDownloads
                            .filter((download) => download.product_name === group)
                            .forEach((download) => {
                                downloadItemsHtml += `
                                <div class="download-item" id="${download.id}">
                                    <div class="download-info">
                                        <p class="download-name">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-paperclip"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                                            ${download.name}
                                        </p>
                                        <div>
                                            <p class="download-remaining">Lượt tải còn lại: <span class="remaining">${
                                                download.downloads_remaining !== '' ? download.downloads_remaining : '∞'
                                            }</span></p>
                                            <p class="download-expire">Hết hạn: ${download.access_expires ? formatDate(download.access_expires.date) : '∞'}</p>
                                        </div>
                                    </div>
                                    <a href="${download.download_url}" class="download-button" download-id="${download.id}" download-url="${download.download_url}">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round" class="lucide lucide-download">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                            <polyline points="7 10 12 15 17 10" />
                                            <line x1="12" x2="12" y1="15" y2="3" />
                                        </svg>
                                        Tải xuống
                                    </a>
                                </div>
                            `;
                            });

                        jQuery('.sepay-download .download-list').append(`
                            <div class="download-group">
                                ${group}
                            </div>
                            ${downloadItemsHtml}
                        `);
                    });
                }
            }
        },
    });
}

setInterval(function () {
    if (pay_status === 'Unpaid') {
        check_invoice_status();
    }
}, 5000);

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById("sepay_copy_account_number").addEventListener("click", function () {
        navigator.clipboard.writeText(account_number);
        document.getElementById("sepay_copy_account_number_btn").innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15"  class="bi bi-check2" viewBox="0 0 16 16">  <path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/ fill="#4bbf73"></svg>';
        setTimeout(function () {
            document.getElementById("sepay_copy_account_number_btn").innerHTML = '<svg width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M6.625 3.125C6.34886 3.125 6.125 3.34886 6.125 3.625V4.875H13.375C14.3415 4.875 15.125 5.6585 15.125 6.625V13.875H16.375C16.6511 13.875 16.875 13.6511 16.875 13.375V3.625C16.875 3.34886 16.6511 3.125 16.375 3.125H6.625ZM15.125 15.125H16.375C17.3415 15.125 18.125 14.3415 18.125 13.375V3.625C18.125 2.6585 17.3415 1.875 16.375 1.875H6.625C5.6585 1.875 4.875 2.6585 4.875 3.625V4.875H3.625C2.6585 4.875 1.875 5.6585 1.875 6.625V16.375C1.875 17.3415 2.6585 18.125 3.625 18.125H13.375C14.3415 18.125 15.125 17.3415 15.125 16.375V15.125ZM13.875 6.625C13.875 6.34886 13.6511 6.125 13.375 6.125H3.625C3.34886 6.125 3.125 6.34886 3.125 6.625V16.375C3.125 16.6511 3.34886 16.875 3.625 16.875H13.375C13.6511 16.875 13.875 16.6511 13.875 16.375V6.625Z" fill="rgba(51, 102, 255, 1)"></path></svg>';
        }, 2000);
    });
  
    document.getElementById("sepay_copy_amount").addEventListener("click", function () {
        navigator.clipboard.writeText(amount);
        document.getElementById("sepay_copy_amount_btn").innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15"  class="bi bi-check2" viewBox="0 0 16 16">  <path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/ fill="#4bbf73"></svg>';
        setTimeout(function () {
            document.getElementById("sepay_copy_amount_btn").innerHTML = '<svg width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M6.625 3.125C6.34886 3.125 6.125 3.34886 6.125 3.625V4.875H13.375C14.3415 4.875 15.125 5.6585 15.125 6.625V13.875H16.375C16.6511 13.875 16.875 13.6511 16.875 13.375V3.625C16.875 3.34886 16.6511 3.125 16.375 3.125H6.625ZM15.125 15.125H16.375C17.3415 15.125 18.125 14.3415 18.125 13.375V3.625C18.125 2.6585 17.3415 1.875 16.375 1.875H6.625C5.6585 1.875 4.875 2.6585 4.875 3.625V4.875H3.625C2.6585 4.875 1.875 5.6585 1.875 6.625V16.375C1.875 17.3415 2.6585 18.125 3.625 18.125H13.375C14.3415 18.125 15.125 17.3415 15.125 16.375V15.125ZM13.875 6.625C13.875 6.34886 13.6511 6.125 13.375 6.125H3.625C3.34886 6.125 3.125 6.34886 3.125 6.625V16.375C3.125 16.6511 3.34886 16.875 3.625 16.875H13.375C13.6511 16.875 13.875 16.6511 13.875 16.375V6.625Z" fill="rgba(51, 102, 255, 1)"></path></svg>';
        }, 2000);
    });
  
    document.getElementById("sepay_copy_transfer_content").addEventListener("click", function () {
        navigator.clipboard.writeText(remark);
        document.getElementById("sepay_copy_transfer_content_btn").innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15"  class="bi bi-check2" viewBox="0 0 16 16">  <path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/ fill="#4bbf73"></svg>';
        setTimeout(function () {
            document.getElementById("sepay_copy_transfer_content_btn").innerHTML = '<svg width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M6.625 3.125C6.34886 3.125 6.125 3.34886 6.125 3.625V4.875H13.375C14.3415 4.875 15.125 5.6585 15.125 6.625V13.875H16.375C16.6511 13.875 16.875 13.6511 16.875 13.375V3.625C16.875 3.34886 16.6511 3.125 16.375 3.125H6.625ZM15.125 15.125H16.375C17.3415 15.125 18.125 14.3415 18.125 13.375V3.625C18.125 2.6585 17.3415 1.875 16.375 1.875H6.625C5.6585 1.875 4.875 2.6585 4.875 3.625V4.875H3.625C2.6585 4.875 1.875 5.6585 1.875 6.625V16.375C1.875 17.3415 2.6585 18.125 3.625 18.125H13.375C14.3415 18.125 15.125 17.3415 15.125 16.375V15.125ZM13.875 6.625C13.875 6.34886 13.6511 6.125 13.375 6.125H3.625C3.34886 6.125 3.125 6.34886 3.125 6.625V16.375C3.125 16.6511 3.34886 16.875 3.625 16.875H13.375C13.6511 16.875 13.875 16.6511 13.875 16.375V6.625Z" fill="rgba(51, 102, 255, 1)"></path></svg>';
        }, 2000);
    });
});
