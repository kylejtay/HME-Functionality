jQuery(function($) {
    var hme_checkout_params = window.hme_checkout_params || {};
    var cc_percentage = parseFloat(hme_checkout_params.cc_percentage) || 0.029;
    var cc_fixed = parseFloat(hme_checkout_params.cc_fixed) || 0.30;
    var ach_percentage = parseFloat(hme_checkout_params.ach_percentage) || 0.008;
    var ach_max_fee = parseFloat(hme_checkout_params.ach_max_fee) || 5.00;

    var lastPaymentMethod = '';

    function calculateProcessingFee(subtotal, paymentMethod) {
        var fee = 0;
        if (typeof paymentMethod !== 'string') {
            paymentMethod = ''; 
        }
        // IMPORTANT: Replace 'stripe' and ACH IDs below with your actual payment method IDs
        // For example, if your Credit Card ID is 'stripe_cc' and ACH is 'stripe_us_bank_account'
        if (paymentMethod === 'stripe') { // Example: 'stripe' or 'stripe_cc'
            // Gross-up calculation: ( (Subtotal * Percentage) + Fixed ) / (1 - Percentage)
            fee = ( (subtotal * cc_percentage) + cc_fixed ) / (1 - cc_percentage);
        } else if (paymentMethod === 'stripe_us_bank_account' || paymentMethod === 'stripe_ach' || paymentMethod === 'bacss_debit' || paymentMethod === 'ach') { // Example: 'stripe_us_bank_account' or 'stripe_ach_direct_debit'
            // Gross-up calculation for percentage part, then apply cap
            var ach_fee_grossed_up_uncapped = (subtotal * ach_percentage) / (1 - ach_percentage);
            fee = Math.min(ach_fee_grossed_up_uncapped, ach_max_fee);
        }
        return fee;
    }

    function updateOrderReviewDisplay(isUserInitiated) {
        isUserInitiated = isUserInitiated || false; 
        var paymentMethod = $('input[name="payment_method"]:checked').val();

        var $orderReviewTable = $('table.woocommerce-checkout-review-order-table');
        var $subtotalRow = $orderReviewTable.find('.cart-subtotal');
        var subtotal = 0;
        var subtotalText = '';

        if ($subtotalRow.length) {
            var $priceAmount = $subtotalRow.find('.woocommerce-Price-amount').first();
            if ($priceAmount.find('bdi').length) {
                subtotalText = $priceAmount.find('bdi').html(); 
            } else {
                subtotalText = $priceAmount.html();
            }
        }
        
        if (subtotalText) {
            subtotalText = String(subtotalText).replace(/<[^>]*>/g, '');
            subtotalText = subtotalText.replace(/[^\d.]/g, '');
            subtotal = parseFloat(subtotalText);
        }

        if (isNaN(subtotal) || subtotal <= 0) {
            if (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.cart_totals && typeof wc_checkout_params.cart_totals.subtotal_value !== 'undefined') {
                 subtotal = parseFloat(wc_checkout_params.cart_totals.subtotal_value);
            } else if (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.cart_totals && typeof wc_checkout_params.cart_totals.cart_subtotal !== 'undefined'){
                var rawSubtotal = wc_checkout_params.cart_totals.cart_subtotal;
                var extracted = String(rawSubtotal).match(/[\d\.]+/g);
                if (extracted) {
                    subtotal = parseFloat(extracted.join(''));
                }
            }
        }
        
        $orderReviewTable.find('tr.fee').each(function() {
            var thText = $(this).find('th').text();
            if (thText === 'Credit/Debit Card Processing Fee' || thText === 'ACH Processing Fee') {
                $(this).remove();
            }
        });
        $orderReviewTable.find('tr.processing-fee-row').remove();

        if (isNaN(subtotal)) {
            console.error('HME Checkout Fees: Could not reliably determine subtotal. Fee display will be cleared.');
            return;
        }

        var processingFee = calculateProcessingFee(subtotal, paymentMethod);
        var feeLabelText = '';

        // IMPORTANT: Replace 'stripe' and ACH IDs below with your actual payment method IDs
        if (paymentMethod === 'stripe') { // Example: 'stripe' or 'stripe_cc'
            feeLabelText = 'Credit/Debit Card Processing Fee';
        } else if (paymentMethod === 'stripe_us_bank_account' || paymentMethod === 'stripe_ach' || paymentMethod === 'bacss_debit' || paymentMethod === 'ach') { // Example: 'stripe_us_bank_account' or 'stripe_ach_direct_debit'
            feeLabelText = 'ACH Processing Fee';
        }

        if (processingFee > 0 && feeLabelText !== '') {
            var feeFormatted = '<span class="woocommerce-Price-amount amount"><bdi>';
            var currencySymbol = $orderReviewTable.find('.cart-subtotal .woocommerce-Price-currencySymbol').first().text() || '$';
            feeFormatted += '<span class="woocommerce-Price-currencySymbol">' + currencySymbol + '</span>' + processingFee.toFixed(2);
            feeFormatted += '</bdi></span>';
            
            var feeRowHtml = '<tr class="processing-fee-row fee"><th>' + feeLabelText + '</th><td data-title="' + feeLabelText + '">' + feeFormatted + '</td></tr>';
            
            var $orderTotalRow = $orderReviewTable.find('.order-total');
            if ($orderTotalRow.length) {
                $orderTotalRow.before(feeRowHtml);
            } else if ($subtotalRow.length) {
                $subtotalRow.after(feeRowHtml);
            } else {
                $orderReviewTable.find('tbody').append(feeRowHtml);
            }
        }

        if (isUserInitiated && paymentMethod !== lastPaymentMethod) {
            $(document.body).trigger('update_checkout');
        }
        lastPaymentMethod = paymentMethod;
    }

    // Use 'change' event and delegate from a more specific static parent
    $('form.woocommerce-checkout').on('change', 'input[name="payment_method"]', function(){
        // No timeout needed for 'change' event usually, it fires after selection is made.
        updateOrderReviewDisplay(true); // Pass true for user initiated
    });

    $(document.body).on('updated_checkout', function() {
        setTimeout(function() { updateOrderReviewDisplay(false); }, 100); 
    });

    if ($('form.woocommerce-checkout').length) { 
        setTimeout(function() { updateOrderReviewDisplay(false); }, 250); 
    }
}); 