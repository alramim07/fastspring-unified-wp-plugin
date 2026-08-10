/* FastSpring Split Gateways - Express Checkout */
(function ($) {
    'use strict';

    function getAjaxURL(endpoint) {
        return woocommerce_fastspring_express_params.ajax_url.toString().replace('%%endpoint%%', endpoint);
    }

    // SBL callback stubs required by the script tag data-* attributes.
    window.fastspringBeforeRequestHandler = function () {};
    window.dataCallbackFunction = function () {};

    window.errorCallback = function (code, string) {
        console.error('FastSpring Error: ', code, string);
    };

    // Popup closed -> ask WooCommerce for the receipt URL (completes the order).
    window.fastspringPopupCloseHandler = function (data) {
        if (data && data.reference) {
            data.security = woocommerce_fastspring_express_params.nonce.receipt;
            $.ajax({
                type: 'POST',
                dataType: 'json',
                data: JSON.stringify(data),
                url: getAjaxURL('wc_fastspring_get_receipt'),
                success: function (res) {
                    if (res.redirect_url) {
                        window.location = res.redirect_url;
                    }
                },
                error: function (xhr) {
                    console.error('Receipt lookup failed', xhr.responseText);
                }
            });
        }
    };

    $(document.body).on('click', '.fssg-express-button', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var method = $btn.data('method');
        var productId = parseInt($btn.data('product-id'), 10) || 0;
        var isCart = $btn.data('cart') === 1;
        var qty = 1;

        if (!isCart) {
            var $qty = $('.quantity input.qty');
            if ($qty.length) {
                qty = Math.max(1, parseInt($qty.val(), 10) || 1);
            }
        }

        $btn.prop('disabled', true).addClass('fssg-express-loading');

        $.ajax({
            type: 'POST',
            url: getAjaxURL('fssg_wc_fastspring_express_order'),
            dataType: 'json',
            data: {
                security: woocommerce_fastspring_express_params.nonce.express,
                method: method,
                product_id: productId,
                cart: isCart ? 1 : 0,
                qty: qty
            },
            success: function (res) {
                $btn.prop('disabled', false).removeClass('fssg-express-loading');
                if (res.result === 'success' && res.session && res.session.payload) {
                    fastspring.builder.push({ reset: true });
                    fastspring.builder.secure(res.session.payload, res.session.key);
                    setTimeout(function () {
                        fastspring.builder.checkout();
                    }, 300);
                } else {
                    console.error('Express checkout failed', res);
                }
            },
            error: function (xhr) {
                $btn.prop('disabled', false).removeClass('fssg-express-loading');
                console.error('Express checkout request failed', xhr.responseText);
            }
        });
    });
})(jQuery);
