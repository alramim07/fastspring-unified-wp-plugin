/* Customized FastSpring Checkout JS for Split Gateways */
(function ($) {
    'use strict';

    var checkoutForm = $('form.checkout');

    function setLoadingDone() { checkoutForm.removeClass('processing').unblock(); }
    function setLoadingOn() { checkoutForm.addClass('processing').block({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } }); }
    function getAjaxURL(e) { return woocommerce_fastspring_params.ajax_url.toString().replace('%%endpoint%%', 'wc_fastspring_' + e); }

    window.fastspringBeforeRequestHandler = function () { setLoadingDone(); };
    window.dataCallbackFunction = function (data) { console.log('FastSpring Data:', data); };

    window.errorCallback = function (code, string) {
        console.error('FastSpring Error: ', code, string);
        submitError('FastSpring API Error: ' + string + ' (' + code + ')');
    };

    window.fastspringPopupCloseHandler = function (e) {
        if (e && e.reference) {
            window.requestPaymentCompletionUrl(e || {}, function (err, o) {
                if (!err) { window.location = o.redirect_url; }
            });
        }
    };

    window.requestPaymentCompletionUrl = function (e, o) {
        e.security = woocommerce_fastspring_params.nonce.receipt;
        $.ajax({
            type: 'POST',
            dataType: 'json',
            data: JSON.stringify(e),
            url: getAjaxURL('get_receipt'),
            success: function (e) { o(null, e); },
            error: function (e) { o(e.responseText); }
        });
    };

    function launchFastSpring(e) {
        console.log('Launching FastSpring session:', e);

        if (!e || !e.payload) {
            submitError('Secure session could not be initialized.');
            return;
        }

        try {

            // 1️⃣ Reset any previous checkout/cart
            fastspring.builder.push({
                reset: true
            });

            // 2️⃣ Load the secure payload from WooCommerce
            fastspring.builder.secure(e.payload, e.key);

            if (e.locale && e.locale.language) {
                fastspring.builder.language(e.locale.language);
            }

            // 3️⃣ Wait briefly to ensure the session initializes
            setTimeout(function () {
                fastspring.builder.checkout();
            }, 300);

        } catch (err) {
            console.error(err);
            submitError('FastSpring checkout failed.');
        }
    }


    function setOrder(callback) {
        $.ajax({
            type: 'POST',
            url: wc_checkout_params.checkout_url,
            data: checkoutForm.serialize(),
            dataType: 'json',
            success: function (o) {
                try {
                    if (o.result !== 'success') throw new Error('failure');
                    callback(null, o);
                } catch (err) {
                    if (o.reload === true) return window.location.reload();
                    if (o.refresh === true) $(document.body).trigger('update_checkout');
                    o.messages ? submitError(o.messages) : submitError(wc_checkout_params.i18n_checkout_error);
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                submitError(errorThrown);
            }
        });
    }

    function doSubmit() {
        setLoadingOn();
        setOrder(function (err, result) {
            if (!err) {
                // Using fsc_payload which matches the parent plugin's return key
                launchFastSpring(result.session);
            }
        });
    }

    function submitError(e) {
        setLoadingDone();
        $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
        checkoutForm.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + e + '</div>');
        checkoutForm.removeClass('processing');
        $('html, body').animate({ scrollTop: checkoutForm.offset().top - 100 }, 1000);
        $(document.body).trigger('checkout_error');
    }

    function isFastSpringSelected() {
        var method = $('.woocommerce-checkout input[name="payment_method"]:checked').val();
        return method && method.indexOf('fastspring') === 0;
    }

    checkoutForm.on('checkout_place_order', function () {
        if (isFastSpringSelected()) {
            doSubmit();
            return false;
        }
    });

    checkoutForm.on('change', 'input[name="payment_method"]', function (e) {
        if ($(this).val().indexOf('fastspring') !== -1) {
            e.stopImmediatePropagation();
        }
    });

})(jQuery);