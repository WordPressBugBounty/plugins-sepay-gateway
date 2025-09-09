jQuery(document).ready(function () {
  let setupWebhookTimeout;

  jQuery(".wc-sepay-account-list").on(
    "change",
    'input[name="bank_account_id"]',
    function () {
      const selectedAccountId = jQuery(this).val();
      const bankShortName = jQuery(this).data("bank-short-name");
      const subAccountContainer = jQuery(".wc-sepay-sub-account-list");
      const subAccountList = jQuery("#wc-sepay-sub-account-container");
      const loadingSpinner = jQuery(".loading-spinner");
      const submitButton = jQuery(".button-primary");

      if (setupWebhookTimeout) {
        clearTimeout(setupWebhookTimeout);
      }

      subAccountContainer.hide();
      subAccountList.empty();
      submitButton.prop("disabled", true);

      if (selectedAccountId) {
        loadingSpinner.show();

        const excludedSubAccountBanks = ["TPBank", "VPBank", "VietinBank"];
        const requiredSubAccountBanks = ["BIDV", "MSB", "KienLongBank", "OCB"];
        const requiresSubAccount =
          requiredSubAccountBanks.includes(bankShortName) &&
          !excludedSubAccountBanks.includes(bankShortName);

        if (requiresSubAccount) {
          subAccountContainer.show();
        } else {
          submitButton.prop("disabled", false);
        }

        setupWebhookTimeout = setTimeout(function () {
          jQuery.ajax({
            url: ajaxurl,
            method: "POST",
            data: {
              action: "sepay_get_bank_sub_accounts",
              bank_account_id: selectedAccountId,
            },
            success: function (response) {
              subAccountList.empty();
              loadingSpinner.hide();

              if (
                response.success &&
                response.data &&
                response.data.length > 0
              ) {
                if (requiresSubAccount) {
                  response.data.forEach(function (subAccount) {
                    const subAccountHtml = `
                                        <label class="wc-sepay-sub-account-item">
                                            <input type="radio" name="sub_account" value="${subAccount.account_number}">
                                            <div class="wc-sepay-sub-account-details">
                                                <div class="wc-sepay-sub-account-holder">
                                                    ${subAccount.account_holder_name}
                                                </div>
                                                <div class="wc-sepay-sub-account-number">
                                                    ${subAccount.account_number}
                                                </div>
                                            </div>
                                        </label>`;
                    subAccountList.append(subAccountHtml);
                  });
                  subAccountContainer.show();
                }
              } else if (requiresSubAccount) {
                subAccountContainer.show();
                subAccountList.append(
                  `Vui lòng thêm tài khoản VA cho tài khoản ngân hàng ${bankShortName} trên trang quản lý <a href="https://my.sepay.vn/bankaccount/details/${selectedAccountId}" target="_blank">tài khoản ngân hàng của SePay</a> trước khi tiếp tục.`
                );
              }
            },
            error: function () {
              loadingSpinner.hide();
              if (requiresSubAccount) {
                subAccountContainer.show();
                subAccountList.append(
                  "<p>Đã xảy ra lỗi khi tải tài khoản ảo. Vui lòng thử lại.</p>"
                );
              }
            },
          });
        }, 300);
      } else {
        submitButton.prop("disabled", false);
      }
    }
  );

  jQuery(".wc-sepay-sub-account-list").on(
    "change",
    'input[name="sub_account"]',
    function () {
      jQuery(".button-primary").prop("disabled", false);
    }
  );

  jQuery("#complete-setup").on("click", function (e) {
    e.preventDefault();

    const selectBankAccountEl = jQuery('input[name="bank_account_id"]:checked');
    const selectedBankAccount = selectBankAccountEl.val();
    const selectedSubAccount = jQuery(
      'input[name="sub_account"]:checked'
    ).val();
    const submitButton = jQuery(this);

    if (!selectedBankAccount) {
      return;
    }

    submitButton.prop("disabled", true).text("Đang xử lý...");

    jQuery.ajax({
      url: ajaxurl,
      method: "POST",
      data: {
        action: "setup_sepay_webhook",
        bank_account_id: selectedBankAccount,
        sub_account: selectedSubAccount,
        _wpnonce: jQuery("#sepay_webhook_setup_nonce").val(),
      },
      success: function (response) {
        if (response.success) {
          window.location.reload();
        } else {
          submitButton.prop("disabled", false).text("Hoàn tất thiết lập");
          alert(response.data.message);
        }
      },
      error: function () {
        submitButton.prop("disabled", false).text("Hoàn tất thiết lập");
      },
    });
  });

  const subAccountField = jQuery(".dynamic-sub-account");
  const bankAccountField = jQuery(".sepay-bank-account");
  const payCodePrefixField = jQuery(".sepay-pay-code-prefix");
  const loadingMessage =
    '<option value="">Đang tải danh sách tài khoản ảo...</option>';
  let isFetchingBankAccounts = false;
  let isFetchingPayCodePrefixes = false;

  bankAccountField.on("mousedown", function (e) {
    if (this.hasAttribute("size") || isFetchingBankAccounts) return;

    isFetchingBankAccounts = true;

    const oldVal = bankAccountField.val();

    jQuery.ajax({
      url: ajaxurl,
      method: "POST",
      data: {
        action: "sepay_get_bank_accounts",
      },
      success: function (response) {
        let options = [];
        options.push('<option value="">-- Chọn tài khoản ảo --</option>');
        if (response.success && response.data.length > 0) {
          options = response.data.map(function (bankAccount) {
            return `<option value="${bankAccount.id}">${bankAccount.bank.brand_name} - ${bankAccount.account_number} - ${bankAccount.account_holder_name}</option>`;
          });
        }

        bankAccountField.html(options.join(""));
        bankAccountField.val(oldVal);
      },
      complete: function () {
        isFetchingBankAccounts = false;
      },
    });
  });

  payCodePrefixField.on("mousedown", function (e) {
    if (this.hasAttribute("size") || isFetchingPayCodePrefixes) return;

    isFetchingPayCodePrefixes = true;

    const oldVal = payCodePrefixField.val();

    jQuery.ajax({
      url: ajaxurl,
      method: "POST",
      data: {
        action: "sepay_get_pay_code_prefixes",
      },
      success: function (response) {
        if (response.success && response.data.length > 0) {
          const options = response.data.map(function (payCodePrefix) {
            return `<option value="${payCodePrefix.prefix}">${payCodePrefix.prefix}</option>`;
          });

          payCodePrefixField.html(options.join(""));
          payCodePrefixField.val(oldVal);
        }
      },
      complete: function () {
        isFetchingPayCodePrefixes = false;
      },
    });
  });

  let subAccountTimeout;

  bankAccountField.on("change", function () {
    const selectedBankAccountId = jQuery(this).val();
    const selectedOption = jQuery(this).find("option:selected");
    const bankName = selectedOption.text().split(" - ")[0];
    const currentSubAccountValue = subAccountField.val();

    if (subAccountTimeout) {
      clearTimeout(subAccountTimeout);
    }

    if (!selectedBankAccountId) {
      subAccountField.html(
        '<option value="">Vui lòng chọn tài khoản ngân hàng trước</option>'
      );
      subAccountField.prop("disabled", true);
      return;
    }

    const excludedSubAccountBanks = ["TPBank", "VPBank", "VietinBank"];
    const requiredSubAccountBanks = ["BIDV", "MSB", "KienLongBank", "OCB"];

    if (excludedSubAccountBanks.includes(bankName)) {
      subAccountField.html(
        '<option value="">Ngân hàng ' +
          bankName +
          " không hỗ trợ tài khoản VA</option>"
      );
      subAccountField.prop("disabled", true);
      return;
    }

    subAccountField.prop("disabled", false);
    subAccountField.html(loadingMessage);

    subAccountTimeout = setTimeout(function () {
      jQuery.ajax({
        url: ajaxurl,
        method: "POST",
        data: {
          action: "sepay_get_bank_sub_accounts",
          bank_account_id: selectedBankAccountId,
        },
        success: function (response) {
          let options = [];
          if (response.success && response.data.length > 0) {
            options.push('<option value="">-- Chọn tài khoản ảo --</option>');
            response.data.map(function (subAccount) {
              options.push(
                `<option value="${subAccount.account_number}">${
                  subAccount.account_number
                }${subAccount.label ? ` - ${subAccount.label}` : ""}</option>`
              );
            });
          } else {
            options.push('<option value="">Không có tài khoản VA nào</option>');
          }
          subAccountField.html(options.join(""));

          if (
            currentSubAccountValue &&
            response.success &&
            response.data.length > 0
          ) {
            const subAccountExists = response.data.some(function (subAccount) {
              return subAccount.account_number === currentSubAccountValue;
            });
            if (subAccountExists) {
              subAccountField.val(currentSubAccountValue);
            }
          }
        },
        error: function () {
          subAccountField.html(
            '<option value="">Lỗi khi tải tài khoản ảo. Vui lòng thử lại.</option>'
          );
        },
      });
    }, 300);
  });

  const checkedBankAccount = jQuery(".wc-sepay-account-item input:checked");
  if (checkedBankAccount.length) {
    checkedBankAccount.trigger("change");
    jQuery(".wc-sepay-account-list").animate(
      {
        scrollTop: checkedBankAccount.offset().top - 100,
      },
      500
    );
  }

  if (
    bankAccountField.length &&
    bankAccountField.val() &&
    subAccountField.val()
  ) {
    setTimeout(function () {
      const savedSubAccountValue = subAccountField.val();
      bankAccountField.trigger("change");

      if (savedSubAccountValue) {
        setTimeout(function () {
          if (
            subAccountField.find(`option[value="${savedSubAccountValue}"]`)
              .length
          ) {
            subAccountField.val(savedSubAccountValue);
          }
        }, 200);
      }
    }, 100);
  }

  function update_account_number_field_ui() {
    const bank = jQuery("#woocommerce_sepay_bank_select").val();
    const excludedSubAccountBanks = ["tpbank", "vpbank", "vietinbank"];
    const requiredSubAccountBanks = ["bidv", "ocb", "msb", "kienlongbank"];

    if (
      requiredSubAccountBanks.includes(bank) &&
      !excludedSubAccountBanks.includes(bank)
    ) {
      jQuery("label[for=woocommerce_sepay_bank_account_number]").html("Số VA");
      jQuery("input[name=woocommerce_sepay_bank_account_number]")
        .parent()
        .find(".help-text")
        .html(
          "Vui lòng điền chính xác <strong>số VA</strong> để nhận được biến động giao dịch."
        );
    } else {
      jQuery("label[for=woocommerce_sepay_bank_account_number]").html(
        "Số tài khoản"
      );
      jQuery("input[name=woocommerce_sepay_bank_account_number]")
        .parent()
        .find(".help-text")
        .html(
          "Vui lòng điền chính xác <strong>số tài khoản ngân hàng</strong> để nhận được biến động giao dịch."
        );
    }
  }
  function check_url_site() {
    let base_url = jQuery("#woocommerce_sepay_url_root").val();
    let url = base_url + "/wp-json/sepay-gateway/v1/add-payment";

    if (!base_url) {
      jQuery("#content-render").css("display", "none");
      return;
    } else {
      jQuery("#content-render").css("display", "block");
    }

    jQuery.ajax({
      url: url,
      type: "POST",
      contentType: "application/json",
      success: function (response) {
        // console.log("result: " + response);
        jQuery("#site_url").html(url);
      },
      error: function (xhr, status, error) {
        // console.error("Exception:", error);
        jQuery("#site_url").html(
          base_url + "/?rest_route=/sepay-gateway/v1/add-payment"
        );
      },
    });
  }
  jQuery("document").ready(() => {
    jQuery("input[name=woocommerce_sepay_bank_account_number]")
      .parent()
      .append(
        '<div class="help-text" style="box-sizing: border-box; color: #856404; background-color: #fff3cd; border-color: #ffeeba; padding: .75rem 1.25rem; border-radius: .25rem; border: 1px solid transparent; margin-top: 0.5rem; max-width: 400px;"></div>'
      );
    update_account_number_field_ui();

    jQuery("#woocommerce_sepay_bank_select").on("change", (event) => {
      update_account_number_field_ui();
    });
    check_url_site();
  });
});
