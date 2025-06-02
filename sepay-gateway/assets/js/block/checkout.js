const settings = window.wc.wcSettings.getSetting('paymentMethodData').sepay;
const label = window.wp.htmlEntities.decodeEntities(settings.title);

const Content = () => {
    return window.wp.element.createElement('div', {
        dangerouslySetInnerHTML: { __html: settings.description || '' }
    });
};

const Block_Gateway = {
    name: 'sepay',
    label: label,
    content: Object(window.wp.element.createElement)(Content, null),
    edit: Object(window.wp.element.createElement)(Content, null),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod(Block_Gateway);

document.addEventListener('DOMContentLoaded', () => {
    if (! settings.logo) {
        return;
    }

    const checkElement = setInterval(() => {
        const element = document.querySelector('#radio-control-wc-payment-method-options-sepay__label');
        if (element) {
            element.innerHTML = `<span style="width: 100%">${label}<span style="float: right"><img src="${settings.logo}" alt="${label}" /></span></span>`;
            clearInterval(checkElement);
        }
    }, 100);
});
