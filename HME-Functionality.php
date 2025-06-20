<?php
/*
Plugin Name:     HME-Functionality
Plugin URI:      https://your-site.com/
Description:     First item = base credits, extras = extra credits; show credits in shop/cart (with USD in parens); on variable products show min–max credits; revert to pure USD at checkout; material cost line under each cart item.
Version:         1.0
Author:          Fresh Concept
Author URI:      https://www.freshconcept.co/
Text Domain:     hme-functionality
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // exit if accessed directly
}

// turn off Woodmart's quick (flyout) cart
add_filter( 'woodmart_quick_cart_enabled', '__return_false' );

// force the header cart icon to link to WooCommerce cart URL
add_filter( 'woodmart_header_cart_link', function() {
    return wc_get_cart_url();
} );


// 1) Conversion rate: 1 credit = $11.364
if ( ! defined( 'FC_CREDIT_RATE' ) ) {
    define( 'FC_CREDIT_RATE', 11.3636363636 );
}

// Define Stripe Processing Fees
if ( ! defined( 'HME_STRIPE_CC_PERCENTAGE' ) ) {
    define( 'HME_STRIPE_CC_PERCENTAGE', 0.029 ); // 2.9%
}
if ( ! defined( 'HME_STRIPE_CC_FIXED' ) ) {
    define( 'HME_STRIPE_CC_FIXED', 0.30 ); // $0.30
}
if ( ! defined( 'HME_STRIPE_ACH_PERCENTAGE' ) ) {
    define( 'HME_STRIPE_ACH_PERCENTAGE', 0.008 ); // 0.8%
}
if ( ! defined( 'HME_STRIPE_ACH_MAX_FEE' ) ) {
    define( 'HME_STRIPE_ACH_MAX_FEE', 5.00 ); // $5.00
}

// Enqueue checkout scripts
add_action( 'wp_enqueue_scripts', 'hme_checkout_scripts' );
function hme_checkout_scripts() {
    if ( is_checkout() ) {
        wp_enqueue_script(
            'hme-checkout-fees',
            plugin_dir_url( __FILE__ ) . 'js/hme-checkout-fees.js',
            array( 'jquery', 'wc-checkout' ),
            '1.0.0', // Version
            true // In footer
        );

        // Pass data to our script
        wp_localize_script( 'hme-checkout-fees', 'hme_checkout_params', array(
            'cc_percentage' => HME_STRIPE_CC_PERCENTAGE,
            'cc_fixed' => HME_STRIPE_CC_FIXED,
            'ach_percentage' => HME_STRIPE_ACH_PERCENTAGE,
            'ach_max_fee' => HME_STRIPE_ACH_MAX_FEE,
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'hme-checkout-fees-nonce' )
        ) );
    }
}

// 2) Carry material cost into the cart item
add_filter( 'woocommerce_add_cart_item_data', 'hme_add_material_to_cart', 10, 3 );
function hme_add_material_to_cart( $cart_item_data, $product_id, $variation_id = 0 ) {
    // Get material cost from variation if it exists, otherwise from product
    $actual_id = $variation_id ? $variation_id : $product_id;
    $mat = get_post_meta( $actual_id, 'customer material', true );
    
    if ( $mat !== '' ) {
        // Strip currency symbols and any non-numeric chars except decimal point
        $mat_clean = preg_replace('/[^0-9.]/', '', $mat);
        $cart_item_data['fc_material'] = floatval($mat_clean);
    }
    return $cart_item_data;
}

// 3) Before totals: recalc each line's USD price from grouped credits
add_action( 'woocommerce_before_calculate_totals', 'hme_dynamic_credit_pricing_grouped', 20 );
function hme_dynamic_credit_pricing_grouped( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

    foreach ( $cart->cart_contents as $key => $item ) {
        $product = $item['data'];
        $qty = $item['quantity'];
        
        // ensure material cost is present
        if ( ! isset( $cart->cart_contents[ $key ]['fc_material'] ) ) {
            $actual_id = $product->get_id();
            $mat = get_post_meta( $actual_id, 'customer material', true );
            
            if ( $mat !== '' ) {
                $mat_clean = preg_replace('/[^0-9.]/', '', $mat);
                $cart->cart_contents[ $key ]['fc_material'] = floatval($mat_clean);
            }
        }

        // Get meta from variation if it's a variation, otherwise from the product
        $product_id = $product->is_type('variation') ? $product->get_id() : $product->get_id();
        $base = intval( get_post_meta( $product_id, 'base credits',  true ) );
        $extra = intval( get_post_meta( $product_id, 'extra credits', true ) );

        if ( $qty > 0 && ( $base + $extra ) > 0 ) {
            // Store the original base credits as the per-unit amount
            $cart->cart_contents[ $key ]['fc_credits_per_unit'] = $base;
            
            // Calculate total credits for this line item
            $total_credits = $base * $qty;
            $credit_price = $total_credits * FC_CREDIT_RATE;

            // Add material cost if present
            $material_cost = isset($cart->cart_contents[ $key ]['fc_material']) 
                ? $cart->cart_contents[ $key ]['fc_material'] * $qty 
                : 0;

            // Set total price including credits and materials
            $total_price = $credit_price + $material_cost;
            
            // apply total price (per unit)
            $item['data']->set_price( $total_price / $qty );
        }
    }
}

// 4) Show credits (or credit range) on product/shop pages; pure USD at checkout
add_filter( 'woocommerce_get_price_html', 'hme_show_credits_on_product', 10, 2 );
function hme_show_credits_on_product( $price_html, $product ) {
    // never override on checkout or thank-you
    if ( is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
        return $price_html;
    }

    // a) variable: show min–max of each variation's base credits
    if ( $product->is_type( 'variable' ) ) {
        $credits = [];
        foreach ( $product->get_children() as $vid ) {
            $b = intval( get_post_meta( $vid, 'base credits', true ) );
            if ( $b > 0 ) {
                $credits[] = $b;
            }
        }
        if ( $credits ) {
            $min = min( $credits );
            $max = max( $credits );
            if ( $min === $max ) {
                return sprintf( '%d credits', $min );
            }
            return sprintf( '%d–%d credits', $min, $max );
        }
    }

    // b) simple or single variation: show base credits only
    $base  = intval( get_post_meta( $product->get_id(), 'base credits',  true ) );
    $extra = intval( get_post_meta( $product->get_id(), 'extra credits', true ) );
    if ( $base + $extra > 0 ) {
        return sprintf( '%d credits', $base );
    }

    return $price_html;
}

// 5) Show credits in the cart item price and subtotal
add_filter( 'woocommerce_cart_item_price', 'hme_show_credits_in_cart', 999, 3 );
add_filter( 'woocommerce_cart_item_subtotal', 'hme_show_credits_in_cart', 999, 3 );
function hme_show_credits_in_cart( $price_html, $cart_item, $cart_item_key ) {
    if ( isset( $cart_item['fc_credits_per_unit'] ) ) {
        $credits = intval( $cart_item['fc_credits_per_unit'] );
        if ( current_filter() === 'woocommerce_cart_item_subtotal' ) {
            // For subtotal, multiply the base credits by quantity
            $credits *= $cart_item['quantity'];
        }
        return sprintf( '<span class="hme-credit-amount">%d credits</span>', $credits );
    }
    return $price_html;
}

// Add CSS to style credit amounts in cart
add_action( 'wp_head', 'hme_add_credit_styles' );
function hme_add_credit_styles() {
    ?>
    <style type="text/css">
    .hme-credit-amount {
        font-weight: 500;
    }
    /* Hide the dollar sign from credit amounts but keep it for cart totals */
    td.product-price .woocommerce-Price-currencySymbol,
    td.product-subtotal .woocommerce-Price-currencySymbol {
        display: none;
    }
    </style>
    <?php
}

// 6) Display material cost under each cart item name
add_filter( 'woocommerce_cart_item_name', 'hme_show_material_under_item', 10, 3 );
function hme_show_material_under_item( $name, $cart_item, $cart_item_key ) {
    if ( isset( $cart_item['fc_material'] ) ) {
        $material_cost = $cart_item['fc_material'] * $cart_item['quantity'];
        $name .= sprintf( '<br><small>Material Cost: %s</small>', wc_price( $material_cost ) );
    }
    return $name;
}

/**
 * Replace the USD total in the header cart icon with your credit total.
 */
add_filter( 'woocommerce_cart_subtotal', 'hme_header_cart_total_in_credits', 999, 4 );
function hme_header_cart_total_in_credits( $cart_subtotal, $compound = false, $cart = null ) {
    if ( is_cart() || is_checkout() ) {
        return $cart_subtotal;
    }

    $total_credits = 0;
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( isset( $cart_item['fc_credits_per_unit'] ) ) {
            $total_credits += $cart_item['fc_credits_per_unit'] * $cart_item['quantity'];
        }
    }
    
    return sprintf( '<span class="hme-credit-total">%d Credits</span>', intval($total_credits) );
}

// Add JavaScript to maintain our credit display
add_action( 'wp_footer', 'hme_maintain_credit_display' );
function hme_maintain_credit_display() {
    ?>
    <script type="text/javascript">
    jQuery(function($) {
        function updateCartTotal() {
            $.post(
                '<?php echo admin_url('admin-ajax.php'); ?>', 
                {
                    'action': 'get_cart_credit_total'
                },
                function(response) {
                    if (response.success) {
                        $('.wd-cart-subtotal').html('<span class="hme-credit-total">' + response.data.total_credits + ' Credits</span>');
                    }
                }
            );
        }

        // Initial update
        updateCartTotal();

        // Update when cart updates
        $(document.body).on('updated_cart_totals added_to_cart removed_from_cart wc_fragments_loaded wc_fragments_refreshed', function() {
            updateCartTotal();
        });

        // Also use a small delay to catch any late updates
        setTimeout(updateCartTotal, 100);
        setTimeout(updateCartTotal, 500);
    });
    </script>
    <?php
}

// Also hook into the cart fragments to ensure our value persists through AJAX updates
add_filter( 'woocommerce_add_to_cart_fragments', 'hme_cart_fragments', 999 );
function hme_cart_fragments( $fragments ) {
    $total_credits = 0;
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( isset( $cart_item['fc_credits_per_unit'] ) ) {
            $total_credits += $cart_item['fc_credits_per_unit'] * $cart_item['quantity'];
        }
    }
    
    $fragments['.wd-cart-subtotal'] = sprintf(
        '<span class="wd-cart-subtotal"><span class="hme-credit-total">%d Credits</span></span>',
        intval($total_credits)
    );
    return $fragments;
}

// Add AJAX handler to get cart credit total
add_action( 'wp_ajax_get_cart_credit_total', 'hme_get_cart_credit_total' );
add_action( 'wp_ajax_nopriv_get_cart_credit_total', 'hme_get_cart_credit_total' );
function hme_get_cart_credit_total() {
    $total_credits = 0;
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( isset( $cart_item['fc_credits_per_unit'] ) ) {
            $total_credits += $cart_item['fc_credits_per_unit'] * $cart_item['quantity'];
        }
    }
    
    wp_send_json_success([
        'total_credits' => intval($total_credits)
    ]);
}

// // 8) Handle minimum credit requirements and free shipping notice
// add_filter( 'woocommerce_add_to_cart_validation', 'hme_validate_credit_minimum', 10, 2 );
// function hme_validate_credit_minimum( $passed, $product_id ) {
//     return $passed;
// }

// // Convert free shipping notice to use credits
// add_filter( 'woocommerce_free_shipping_notice', 'hme_convert_free_shipping_notice', 10, 3 );
// function hme_convert_free_shipping_notice( $message, $min_amount, $current_amount ) {
//     $total_credits = 0;
//     foreach ( WC()->cart->get_cart() as $cart_item ) {
//         if ( isset( $cart_item['fc_credits_per_unit'] ) ) {
//             $credits_per_unit = intval( $cart_item['fc_credits_per_unit'] );
//             $quantity = intval( $cart_item['quantity'] );
//             $total_credits += ($credits_per_unit * $quantity);
//         }
//     }

//     $min_credits = 22; // Minimum required credits
    
//     if ( $total_credits < $min_credits ) {
//         $remaining_credits = $min_credits - $total_credits;
//         return sprintf( 'Add %d more credits to cart and get free shipping!', $remaining_credits );
//     }
    
//     return 'You\'ve earned free shipping!';
// }

// Disable checkout when below minimum credits
add_action( 'wp_footer', 'hme_disable_checkout_if_below_minimum' );
function hme_disable_checkout_if_below_minimum() {
    // Only run on cart and checkout pages
    if ( ! is_cart() && ! is_checkout() ) {
        return;
    }

    $total_credits = 0;
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( isset( $cart_item['fc_credits_per_unit'] ) ) {
            $total_credits += $cart_item['fc_credits_per_unit'] * $cart_item['quantity'];
        }
    }

    $min_credits = 22;

    ?>
    <script type="text/javascript">
    jQuery(function($) {
        function checkCreditsAndUpdateUI() {
            $.post(
                '<?php echo admin_url('admin-ajax.php'); ?>', 
                {
                    'action': 'check_cart_credits'
                },
                function(response) {
                    if (response.success) {
                        var totalCredits = response.data.total_credits;
                        var minCredits = response.data.min_credits;
                        
                        if (totalCredits < minCredits) {
                            $('a.checkout-button, button.checkout-button').addClass('disabled').prop('disabled', true);
                            
                            // Add or update notice
                            var notice = 'A minimum of ' + minCredits + ' credits is required before checkout. You currently have ' + totalCredits + ' credits.';
                            if ($('.woocommerce-error.minimum-credits').length === 0) {
                                $('.woocommerce-notices-wrapper:first').append(
                                    '<div class="woocommerce-error minimum-credits">' + notice + '</div>'
                                );
                            } else {
                                $('.woocommerce-error.minimum-credits').html(notice);
                            }
                        } else {
                            $('a.checkout-button, button.checkout-button').removeClass('disabled').prop('disabled', false);
                            $('.woocommerce-error.minimum-credits').remove();
                        }
                    }
                }
            );
        }

        // Initial check
        checkCreditsAndUpdateUI();

        // Update on cart changes
        $(document.body).on('updated_cart_totals updated_checkout', function() {
            setTimeout(checkCreditsAndUpdateUI, 300);
        });

        // Also watch for quantity input changes
        $(document.body).on('change', 'input.qty', function() {
            setTimeout(checkCreditsAndUpdateUI, 300);
        });
    });
    </script>
    <style type="text/css">
    .checkout-button.disabled {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
    }
    .woocommerce-error.minimum-credits {
        z-index: 1000;
        position: relative;
    }
    </style>
    <?php
}

// Add AJAX handler to check credits
add_action( 'wp_ajax_check_cart_credits', 'hme_check_cart_credits' );
add_action( 'wp_ajax_nopriv_check_cart_credits', 'hme_check_cart_credits' );
function hme_check_cart_credits() {
    $total_credits = 0;
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( isset( $cart_item['fc_credits_per_unit'] ) ) {
            $total_credits += $cart_item['fc_credits_per_unit'] * $cart_item['quantity'];
        }
    }
    
    wp_send_json_success([
        'total_credits' => $total_credits,
        'min_credits' => 22,
        'is_valid' => $total_credits >= 22
    ]);
}

// Prevent checkout if minimum credits not met
add_action( 'woocommerce_checkout_process', 'hme_prevent_checkout_if_below_minimum' );
function hme_prevent_checkout_if_below_minimum() {
    $total_credits = 0;
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( isset( $cart_item['fc_credits_per_unit'] ) ) {
            $total_credits += $cart_item['fc_credits_per_unit'] * $cart_item['quantity'];
        }
    }

    $min_credits = 22;
    
    if ( $total_credits < $min_credits ) {
        wc_add_notice( 
            sprintf( 'A minimum of %d credits is required before checkout. You currently have %d credits.', 
                $min_credits, 
                $total_credits 
            ), 
            'error' 
        );
        wp_redirect( wc_get_cart_url() );
        exit;
    }
}

// Add some styling for the total credits line
add_action('wp_head', 'hme_add_total_credits_styles');
function hme_add_total_credits_styles() {
    ?>
    <style type="text/css">
    .cart-total-credits {
        display: table-row !important;
    }
    .cart-total-credits th,
    .cart-total-credits td {
        padding-top: 1em !important;
        padding-bottom: 1em !important;
        font-weight: 500;
    }
    </style>
    <?php
}

// Disable checkout in flyout cart if credits are insufficient
add_action('wp_footer', 'hme_disable_flyout_checkout_if_credits_low');
function hme_disable_flyout_checkout_if_credits_low() {
    ?>
    <script type="text/javascript">
    jQuery(function($) {
        function checkCreditsAndDisableCheckout() {
            let totalCredits = 0;
            $('.woocommerce-mini-cart-item').each(function() {
                let creditText = $(this).find('.hme-credit-amount').text();
                let quantityText = $(this).find('.quantity').text().split('×')[0].trim();
                let credits = parseInt(creditText);
                let quantity = parseInt(quantityText);
                
                if (!isNaN(credits) && !isNaN(quantity)) {
                    totalCredits += (credits * quantity);
                }
            });
            
            // Update all checkout buttons in the flyout cart
            $('.woocommerce-mini-cart__buttons .checkout').each(function() {
                if (totalCredits < 22) {
                    $(this).addClass('disabled')
                        .prop('disabled', true)
                        .attr('title', 'Minimum 22 credits required')
                        .attr('href', '#')
                        .css('opacity', '0.5')
                        .css('cursor', 'not-allowed');
                } else {              
                    $(this).removeClass('disabled')
                        .prop('disabled', false)
                        .attr('href', '/checkout/')
                        .removeAttr('title')
                        .css('opacity', '')
                        .css('cursor', '');
                }
            });
        }

        // Check on page load and when cart updates
        $(document).on('wc_fragments_loaded wc_fragments_refreshed added_to_cart removed_from_cart', function() {
            setTimeout(checkCreditsAndDisableCheckout, 100);
        });
    });
    </script>
    <?php
}

// Add state validation for Utah only and set default state
add_action('wp_footer', 'hme_validate_utah_only');
function hme_validate_utah_only() {
    if (!is_checkout()) return;
    ?>
    <style type="text/css">
    .utah-warning {
        color: #b22222;
        margin-bottom: 1em;
        padding: 1em;
        background-color: #fff6f6;
        border: 1px solid #b22222;
        border-radius: 3px;
    }
    #place_order.disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    </style>
    <script type="text/javascript">
    jQuery(function($) {
        // Set default state to Utah
        $('#billing_state').val('UT');
        
        function updateStateValidation() {
            var state = $('#billing_state').val();
            if (state && state !== 'UT') {
                // Show browser alert
                alert('Sorry, our service is currently only available in Utah.');
                // Disable place order button
                $('#place_order').addClass('disabled').prop('disabled', true);
                
                // Add warning message above place order button
                if ($('.utah-warning').length === 0) {
                    $('#place_order').before('<div class="utah-warning">Sorry, our service is currently only available in Utah. Please select Utah to continue.</div>');
                }
            } else {
                // Enable place order button and remove warning
                $('#place_order').removeClass('disabled').prop('disabled', false);
                $('.utah-warning').remove();
            }
        }
        
        // Check when state changes
        $(document).on('change', '#billing_state', updateStateValidation);
        
        // Initial check
        updateStateValidation();

        // Prevent form submission if state is not Utah
        $('form.woocommerce-checkout').on('submit', function(e) {
            var state = $('#billing_state').val();
            if (state && state !== 'UT') {
                e.preventDefault();
                updateStateValidation();
                return false;
            }
        });
    });
    </script>
    <?php
}

// Also validate on the server side
add_action('woocommerce_checkout_process', 'hme_validate_utah_checkout');
function hme_validate_utah_checkout() {
    $state = isset($_POST['billing_state']) ? $_POST['billing_state'] : '';
    if ($state !== 'UT') {
        wc_add_notice('Sorry, our service is currently only available in Utah.', 'error');
    }
}

// Set default billing state to Utah
add_filter('default_checkout_billing_state', 'hme_default_checkout_state');
function hme_default_checkout_state() {
    return 'UT';
}

// Add Stripe Processing Fees to Cart Total (Server-Side)
add_action( 'woocommerce_cart_calculate_fees', 'hme_add_stripe_processing_fee_to_cart' );
function hme_add_stripe_processing_fee_to_cart( $cart ) {
    if ( is_admin() && ! wp_doing_ajax() ) {
        return;
    }

    $is_checkout_context = false;
    if ( is_checkout() ) {
        $is_checkout_context = true;
    } elseif ( wp_doing_ajax() ) {
        $ajax_action = isset($_REQUEST['action']) ? wc_clean(wp_unslash($_REQUEST['action'])) : '';
        $wc_ajax_param = isset($_REQUEST['wc-ajax']) ? wc_clean(wp_unslash($_REQUEST['wc-ajax'])) : '';

        if ( $ajax_action === 'woocommerce_update_order_review' || $wc_ajax_param === 'update_order_review' ) {
            $is_checkout_context = true;
        }
    }

    if ( ! $is_checkout_context ) {
        return;
    }

    $chosen_payment_method = WC()->session->get( 'chosen_payment_method' );
    if ( empty( $chosen_payment_method ) ) {
        return; // No payment method selected, so no fee to calculate yet.
    }

    $subtotal = $cart->get_subtotal(); // Get subtotal before any other fees
    $processing_fee = 0;
    $fee_label = '';

    if ( $chosen_payment_method === 'stripe' ) { // Assuming 'stripe' is the ID for CC/Debit
        $fee_label = 'Credit/Debit Card Processing Fee';
        // Gross-up calculation: ( (Subtotal * Percentage) + Fixed ) / (1 - Percentage)
        $processing_fee = ( ($subtotal * HME_STRIPE_CC_PERCENTAGE) + HME_STRIPE_CC_FIXED ) / (1 - HME_STRIPE_CC_PERCENTAGE);
    } elseif ( $chosen_payment_method === 'stripe_us_bank_account' || $chosen_payment_method === 'stripe_ach' || $chosen_payment_method === 'bacss_debit' || $chosen_payment_method === 'ach' ) { // Common IDs for ACH
        $fee_label = 'ACH Processing Fee';
        // Gross-up calculation for percentage part, then apply cap
        $ach_fee_grossed_up_uncapped = ($subtotal * HME_STRIPE_ACH_PERCENTAGE) / (1 - HME_STRIPE_ACH_PERCENTAGE);
        $processing_fee = min($ach_fee_grossed_up_uncapped, HME_STRIPE_ACH_MAX_FEE);
    }

    if ( $processing_fee > 0 && !empty($fee_label) ) {
        $cart->add_fee( $fee_label, $processing_fee, true ); // true for taxable, adjust if needed
    }
}

/******************************************************************
 *  WOOCOMMERCE ORDER ► JOBNIMBUS BRIDGE
 *****************************************************************/
add_action( 'woocommerce_order_status_completed', 'hme_create_jobnimbus_records', 10, 1 );
add_action( 'woocommerce_order_status_processing', 'hme_create_jobnimbus_records', 10, 1 );

function hme_create_jobnimbus_records( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    // Skip if already processed
    if ( $order->get_meta( '_hme_jobnimbus_processed' ) ) return;

    $billing = $order->get_address( 'billing' );
    
    /* 1️⃣ Create/Find CONTACT using exact Postman structure */
    $contact_data = [
        'first_name' => $billing['first_name'],
        'last_name' => $billing['last_name'],
        'display_name' => trim( $billing['first_name'] . ' ' . $billing['last_name'] ),
        'email' => $billing['email'],
        'mobile_phone' => preg_replace( '/\D+/', '', $billing['phone'] ),
        'record_type_name' => 'Website Orders',
        'status_name' => 'New',
        'lead_source_name' => 'Website Order',
        'address_line1' => $billing['address_1'],
        'address_line2' => $billing['address_2'],
        'city' => $billing['city'],
        'state_text' => $billing['state'],
        'zip' => $billing['postcode'],
        'country' => $billing['country']
    ];

    $contact = hme_jn_search_or_create(
        'contacts',
        [ 'must' => [ [ 'term' => [ 'email' => $billing['email'] ] ] ] ],
        $contact_data
    );

    if ( ! $contact || empty( $contact['jnid'] ) ) {
        error_log( 'HME: Failed to create/find contact for order ' . $order_id );
        return;
    }

    /* 2️⃣ Create JOB using exact Postman structure */
    $job_data = [
        'name' => 'Website Order #' . $order_id,
        'record_type_name' => 'Website Order',
        'status_name' => 'New',
        'primary' => [ 'id' => $contact['jnid'] ],
        'cf_string_4' => $order_id
    ];

    $job = hme_jn( 'jobs', 'POST', $job_data );

    if ( ! $job || empty( $job['jnid'] ) ) {
        error_log( 'HME: Failed to create job for order ' . $order_id );
        return;
    }

    /* 3️⃣ Create TASK for each line item using exact Postman structure */
    $task_count = 0;
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        $task_data = [
            'title' => $product->get_name(),
            'related' => [
                [ 'id' => $job['jnid'] ]
            ],
            'date_start' => 0,  // No date initially - will be set by Booknetic webhook
            'date_end' => 0
        ];

        error_log( 'HME: Attempting to create task with data: ' . wp_json_encode( $task_data ) );
        
        $task_result = hme_jn( 'tasks', 'POST', $task_data );
        
        if ( $task_result && isset( $task_result['jnid'] ) ) {
            error_log( 'HME: Successfully created task "' . $product->get_name() . '" with ID: ' . $task_result['jnid'] );
            $task_count++;
        } else {
            error_log( 'HME: Failed to create task for product "' . $product->get_name() . '" in order ' . $order_id );
            error_log( 'HME: Task API response: ' . wp_json_encode( $task_result ) );
        }
    }

    error_log( 'HME: Created ' . $task_count . ' tasks out of ' . count( $order->get_items() ) . ' products' );

    // Mark as processed
    $order->update_meta_data( '_hme_jobnimbus_processed', true );
    $order->update_meta_data( '_hme_jobnimbus_job_id', $job['jnid'] );
    $order->save();
    
    error_log( 'HME: Successfully created JobNimbus records for order ' . $order_id . ' with job ID ' . $job['jnid'] );
}

/******************************************************************
 *  BOOKNETIC WEBHOOK ► UPDATE JOBNIMBUS DATES
 *****************************************************************/

// Add Booknetic webhook endpoint to receive appointment data directly
add_action( 'wp_ajax_hme_booknetic_webhook', 'hme_booknetic_webhook_handler' );
add_action( 'wp_ajax_nopriv_hme_booknetic_webhook', 'hme_booknetic_webhook_handler' );

function hme_booknetic_webhook_handler() {
    error_log( 'HME: Booknetic webhook called with data: ' . wp_json_encode( $_POST ) );
    
    // Get the raw POST data (Booknetic might send JSON)
    $raw_data = file_get_contents('php://input');
    $json_data = json_decode($raw_data, true);
    
    error_log( 'HME: Raw webhook data: ' . $raw_data );
    error_log( 'HME: Parsed JSON data: ' . wp_json_encode( $json_data ) );
    
    // Try to extract appointment data from either POST or JSON
    $appointment_data = $json_data ?: $_POST;
    
    if ( empty( $appointment_data ) ) {
        error_log( 'HME: No appointment data received in webhook' );
        wp_send_json_error( 'No data received' );
    }
    
    // Extract customer email
    $customer_email = null;
    if ( isset( $appointment_data['customer_email'] ) ) {
        $customer_email = sanitize_email( $appointment_data['customer_email'] );
    } elseif ( isset( $appointment_data['email'] ) ) {
        $customer_email = sanitize_email( $appointment_data['email'] );
    }
    
    if ( ! $customer_email ) {
        error_log( 'HME: No customer email found in webhook data' );
        wp_send_json_error( 'No customer email found' );
    }
    
    // Extract appointment ID if available
    $appointment_id = null;
    if ( isset( $appointment_data['appointment_id'] ) ) {
        $appointment_id = intval( $appointment_data['appointment_id'] );
    }
    
    error_log( 'HME: Processing webhook - Email: ' . $customer_email . ', Appointment ID: ' . $appointment_id );
    
    // Find the most recent order for this customer email that has a JobNimbus job
    $orders = wc_get_orders( array(
        'billing_email' => $customer_email,
        'status' => array( 'wc-completed', 'wc-processing' ),
        'limit' => 10, // Check multiple orders to find one with JobNimbus job
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => array(
            array(
                'key' => '_hme_jobnimbus_job_id',
                'compare' => 'EXISTS'
            )
        )
    ) );
    
    if ( empty( $orders ) ) {
        // Fallback: look for any recent order without the JobNimbus requirement
        error_log( 'HME: No orders with JobNimbus jobs found, checking for any recent orders for: ' . $customer_email );
        $orders = wc_get_orders( array(
            'billing_email' => $customer_email,
            'status' => array( 'wc-completed', 'wc-processing' ),
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        ) );
        
        if ( empty( $orders ) ) {
            error_log( 'HME: No recent orders found for customer email: ' . $customer_email );
            wp_send_json_error( 'No orders found for customer' );
        }
    }
    
    $order = $orders[0];
    $order_id = $order->get_id();
    
    error_log( 'HME: Using order ' . $order_id . ' for customer ' . $customer_email );
    
    // Extract appointment dates
    $start_date = isset( $appointment_data['start_date'] ) ? $appointment_data['start_date'] : null;
    $end_date = isset( $appointment_data['end_date'] ) ? $appointment_data['end_date'] : null;
    
    error_log( 'HME: Webhook processing - Order: ' . $order_id . ', Appointment: ' . $appointment_id . ', Start: ' . $start_date . ', End: ' . $end_date );
    
    // Validate dates
    if ( ! $start_date || ! $end_date ) {
        error_log( 'HME: Missing appointment dates in webhook' );
        wp_send_json_error( 'Missing appointment dates' );
    }
    
    // Clean up duplicated dates (e.g., "06/25/2025 12:00 PM06/25/2025 12:00 PM" -> "06/25/2025 12:00 PM")
    $start_date = hme_clean_duplicate_date( $start_date );
    $end_date = hme_clean_duplicate_date( $end_date );
    
    error_log( 'HME: Cleaned dates - Start: ' . $start_date . ', End: ' . $end_date );
    
    // Convert dates if needed (BookNetic format might be MM/DD/YYYY H:i A)
    $start_timestamp = strtotime( $start_date );
    $end_timestamp = strtotime( $end_date );
    
    if ( ! $start_timestamp || ! $end_timestamp ) {
        error_log( 'HME: Invalid appointment date format - Start: ' . $start_date . ', End: ' . $end_date );
        wp_send_json_error( 'Invalid appointment date format' );
    }
    
    // Fix timezone issue for Mountain Time Zone
    // JobNimbus treats timestamps as UTC, but we need Mountain Time
    // Automatically detect Daylight Saving Time vs Standard Time
    
    // Create a DateTime object for the appointment date in Mountain Time
    $appointment_datetime = new DateTime( $start_date, new DateTimeZone('America/Denver') );
    $is_dst = $appointment_datetime->format('I'); // 1 if DST, 0 if standard time
    
    if ( $is_dst ) {
        // Mountain Daylight Time (MDT) = UTC-6, so add 6 hours
        $mountain_time_offset = 6 * 3600;
        $timezone_name = 'MDT (UTC-6)';
    } else {
        // Mountain Standard Time (MST) = UTC-7, so add 7 hours  
        $mountain_time_offset = 7 * 3600;
        $timezone_name = 'MST (UTC-7)';
    }
    
    $start_timestamp += $mountain_time_offset;
    $end_timestamp += $mountain_time_offset;
    
    error_log( 'HME: Adjusted timestamps for Mountain Time (' . $timezone_name . ') - Start: ' . date('Y-m-d H:i:s T', $start_timestamp) . ', End: ' . date('Y-m-d H:i:s T', $end_timestamp) );
    
    // Convert to standard format
    $start_date_formatted = date( 'Y-m-d H:i:s', $start_timestamp );
    $end_date_formatted = date( 'Y-m-d H:i:s', $end_timestamp );
    
    // Update JobNimbus
    $result = hme_update_jobnimbus_appointment_dates( $order_id, $start_date_formatted, $end_date_formatted, $appointment_id );
    
    if ( $result ) {
        error_log( 'HME: Successfully processed webhook for order ' . $order_id );
        wp_send_json_success( [ 'message' => 'Appointment processed successfully' ] );
    } else {
        error_log( 'HME: Failed to process webhook for order ' . $order_id );
        wp_send_json_error( 'Failed to process appointment' );
    }
}

// Helper function to clean up duplicated date strings from BookNetic
function hme_clean_duplicate_date( $date_string ) {
    if ( empty( $date_string ) ) {
        return $date_string;
    }
    
    // Remove any extra whitespace
    $date_string = trim( $date_string );
    
    // Pattern to match date formats like: MM/DD/YYYY H:i A
    $date_pattern = '/(\d{1,2}\/\d{1,2}\/\d{4}\s+\d{1,2}:\d{2}\s+[AP]M)/i';
    
    // Find all date matches in the string
    preg_match_all( $date_pattern, $date_string, $matches );
    
    if ( ! empty( $matches[1] ) ) {
        // Return the first valid date match
        return trim( $matches[1][0] );
    }
    
    // If no pattern match, try to detect if it's a simple duplication
    // e.g., "06/25/2025 12:00 PM06/25/2025 12:00 PM"
    $length = strlen( $date_string );
    if ( $length > 20 && $length % 2 === 0 ) {
        $half_length = $length / 2;
        $first_half = substr( $date_string, 0, $half_length );
        $second_half = substr( $date_string, $half_length );
        
        // If both halves are identical, return just one
        if ( $first_half === $second_half ) {
            return trim( $first_half );
        }
    }
    
    // Return original if no cleaning needed
    return $date_string;
}

// Function to update JobNimbus with appointment dates
function hme_update_jobnimbus_appointment_dates( $order_id, $start_date, $end_date, $appointment_id = null ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        error_log( 'HME: Order not found: ' . $order_id );
        return false;
    }

    $job_id = $order->get_meta( '_hme_jobnimbus_job_id' );
    if ( ! $job_id ) {
        error_log( 'HME: JobNimbus job ID not found for order: ' . $order_id );
        return false;
    }

    // Convert dates to timestamps
    $date_start = strtotime( $start_date );
    $date_end = strtotime( $end_date );

    if ( ! $date_start || ! $date_end ) {
        error_log( 'HME: Invalid date format - Start: ' . $start_date . ', End: ' . $end_date );
        return false;
    }

    error_log( 'HME: Updating JobNimbus job ' . $job_id . ' with dates: ' . $date_start . ' to ' . $date_end );

    // Update job dates and status
    $job_result = hme_jn( "jobs/$job_id", 'PUT', [ 
        'date_start' => $date_start,
        'date_end' => $date_end,
        'status_name' => 'Scheduled'
    ] );

    if ( ! $job_result ) {
        error_log( 'HME: Failed to update JobNimbus job: ' . $job_id );
        return false;
    }

    error_log( 'HME: Successfully updated job. Result: ' . wp_json_encode( $job_result ) );

    // Update all related tasks with the same dates
    $tasks = hme_jn( "tasks?filter=" . rawurlencode( wp_json_encode( [
        'must' => [ [ 'term' => [ 'related.id' => $job_id ] ] ]
    ] ) ) );

    error_log( 'HME: Found tasks for job ' . $job_id . ': ' . wp_json_encode( $tasks ) );

    $updated_tasks = 0;
    if ( ! empty( $tasks['results'] ) ) {
        foreach ( $tasks['results'] as $task ) {
            error_log( 'HME: Updating task ' . $task['jnid'] . ' with dates' );
            $task_result = hme_jn( "tasks/{$task['jnid']}", 'PUT', [
                'date_start' => $date_start,
                'date_end' => $date_end
            ] );
            
            if ( $task_result ) {
                $updated_tasks++;
                error_log( 'HME: Successfully updated task ' . $task['jnid'] );
            } else {
                error_log( 'HME: Failed to update task ' . $task['jnid'] );
            }
        }
    }

    // Store appointment reference in order meta
    $order->update_meta_data( '_hme_appointment_id', $appointment_id );
    $order->update_meta_data( '_hme_appointment_start', $start_date );
    $order->update_meta_data( '_hme_appointment_end', $end_date );
    $order->save();

    error_log( 'HME: Successfully updated JobNimbus - Job: ' . $job_id . ', Tasks: ' . $updated_tasks );
    
    return true;
}

// Keep a simple JavaScript-based fallback for direct database queries
add_action( 'wp_footer', 'hme_booknetic_database_monitor' );
function hme_booknetic_database_monitor() {
    // Only run on the Booknetic scheduling page
    if ( ! is_page() || ! has_shortcode( get_post()->post_content, 'hme_booknetic' ) ) {
        return;
    }
    
    $order_id = isset( $_GET['oid'] ) ? intval( $_GET['oid'] ) : 0;
    
    // Get current user data for auto-population
    $current_user = wp_get_current_user();
    $user_data = array();
    
    if ( $current_user->ID ) {
        $user_data = array(
            'email' => $current_user->user_email,
            'first_name' => $current_user->first_name,
            'last_name' => $current_user->last_name,
            'phone' => get_user_meta( $current_user->ID, 'billing_phone', true ),
            'display_name' => $current_user->display_name
        );
        
        // If no first/last name, try to split display name
        if ( empty( $user_data['first_name'] ) && ! empty( $user_data['display_name'] ) ) {
            $name_parts = explode( ' ', $user_data['display_name'], 2 );
            $user_data['first_name'] = $name_parts[0];
            $user_data['last_name'] = isset( $name_parts[1] ) ? $name_parts[1] : '';
        }
    }
    ?>
    <script type="text/javascript">
    jQuery(function($) {
        console.log('HME: Database monitor started for order <?php echo $order_id; ?>');
        
        // Auto-populate user data if logged in
        <?php if ( ! empty( $user_data['email'] ) ): ?>
        var userData = <?php echo wp_json_encode( $user_data ); ?>;
        console.log('HME: Auto-populating user data:', userData);
        
        function populateBookneticForm() {
            // Check if we're on the Information step (step 3) and form fields are visible
            var isInformationStep = false;
            var hasFormFields = false;
            
            // Check for step 3 indicators
            if ( $('.bkntc-step-3').length > 0 && $('.bkntc-step-3').is(':visible') ) {
                isInformationStep = true;
            } else if ( $('[data-step-id="information"]').length > 0 && $('[data-step-id="information"]').is(':visible') ) {
                isInformationStep = true;
            } else if ( $('.bkntc-step.active').length > 0 && $('.bkntc-step.active').text().toLowerCase().includes('information') ) {
                isInformationStep = true;
            }
            
            // Check if form fields actually exist and are visible
            var emailExists = false;
            var nameExists = false;
            
            // Check for email fields without case-insensitive flag
            if ($('input[type="email"]:visible, input[name="email"]:visible').length > 0) {
                emailExists = true;
            } else {
                // Manually check for email placeholders (case-insensitive)
                $('input:visible').each(function() {
                    var placeholder = $(this).attr('placeholder') || '';
                    if (placeholder.toLowerCase().indexOf('email') !== -1) {
                        emailExists = true;
                        return false; // break
                    }
                });
            }
            
            // Check for name fields without case-insensitive flag
            if ($('input[name="name"]:visible').length > 0) {
                nameExists = true;
            } else {
                // Manually check for name placeholders (case-insensitive)
                $('input:visible').each(function() {
                    var placeholder = $(this).attr('placeholder') || '';
                    if (placeholder.toLowerCase().indexOf('name') !== -1) {
                        nameExists = true;
                        return false; // break
                    }
                });
            }
            
            if ( emailExists || nameExists ) {
                hasFormFields = true;
            }
            
            console.log('HME: Step check - Information step:', isInformationStep, 'Form fields exist:', hasFormFields);
            
            if ( isInformationStep && hasFormFields ) {
                console.log('HME: Information step detected with form fields, attempting auto-population');
                
                // Try different possible selectors for email field
                var emailSelectors = [
                    'input[name="email"]:visible',
                    'input[type="email"]:visible',
                    'input[placeholder*="Email"]:visible',
                    '#email:visible',
                    '.email-field input:visible',
                    '[data-field="email"] input:visible'
                ];
                
                emailSelectors.forEach(function(selector) {
                    var $email = $(selector);
                    if ( $email.length && !$email.val() ) {
                        $email.val(userData.email).trigger('change').trigger('input');
                        console.log('HME: Populated email field using selector:', selector);
                    }
                });
                
                // Additional check for email fields with case-insensitive placeholder matching
                $('input:visible').each(function() {
                    var $input = $(this);
                    var placeholder = $input.attr('placeholder') || '';
                    if (!$input.val() && placeholder.toLowerCase().indexOf('email') !== -1) {
                        $input.val(userData.email).trigger('change').trigger('input');
                        console.log('HME: Populated email field with placeholder:', placeholder);
                    }
                });
                
                // Try different possible selectors for name fields
                var nameSelectors = [
                    'input[name="name"]:visible',
                    'input[name="first_name"]:visible',
                    'input[name="firstname"]:visible',
                    'input[placeholder*="Name"]:visible',
                    '#name:visible',
                    '#first_name:visible',
                    '.name-field input:visible',
                    '[data-field="name"] input:visible'
                ];
                
                nameSelectors.forEach(function(selector) {
                    var $name = $(selector);
                    if ( $name.length && !$name.val() ) {
                        $name.val(userData.first_name).trigger('change').trigger('input');
                        console.log('HME: Populated name field using selector:', selector, 'with value:', userData.first_name);
                    }
                });
                
                // Additional check for name fields with case-insensitive placeholder matching
                $('input:visible').each(function() {
                    var $input = $(this);
                    var placeholder = $input.attr('placeholder') || '';
                    if (!$input.val() && (placeholder.toLowerCase().indexOf('name') !== -1 || placeholder.toLowerCase().indexOf('first') !== -1)) {
                        $input.val(userData.first_name).trigger('change').trigger('input');
                        console.log('HME: Populated name field with placeholder:', placeholder);
                    }
                });
                
                // Try different possible selectors for surname/last name fields
                var surnameSelectors = [
                    'input[name="surname"]:visible',
                    'input[name="last_name"]:visible',
                    'input[name="lastname"]:visible',
                    'input[placeholder*="Surname"]:visible',
                    'input[placeholder*="Last Name"]:visible',
                    '#surname:visible',
                    '#last_name:visible',
                    '.surname-field input:visible',
                    '[data-field="surname"] input:visible'
                ];
                
                surnameSelectors.forEach(function(selector) {
                    var $surname = $(selector);
                    if ( $surname.length && !$surname.val() ) {
                        $surname.val(userData.last_name).trigger('change').trigger('input');
                        console.log('HME: Populated surname field using selector:', selector, 'with value:', userData.last_name);
                    }
                });
                
                // Additional check for surname fields with case-insensitive placeholder matching
                $('input:visible').each(function() {
                    var $input = $(this);
                    var placeholder = $input.attr('placeholder') || '';
                    if (!$input.val() && (placeholder.toLowerCase().indexOf('surname') !== -1 || placeholder.toLowerCase().indexOf('last') !== -1)) {
                        $input.val(userData.last_name).trigger('change').trigger('input');
                        console.log('HME: Populated surname field with placeholder:', placeholder);
                    }
                });
                
                // Try different possible selectors for phone fields
                if ( userData.phone ) {
                    var phoneSelectors = [
                        'input[name="phone"]:visible',
                        'input[type="tel"]:visible',
                        'input[placeholder*="Phone"]:visible',
                        '#phone:visible',
                        '.phone-field input:visible',
                        '[data-field="phone"] input:visible'
                    ];
                    
                    phoneSelectors.forEach(function(selector) {
                        var $phone = $(selector);
                        if ( $phone.length && !$phone.val() ) {
                            $phone.val(userData.phone).trigger('change').trigger('input');
                            console.log('HME: Populated phone field using selector:', selector, 'with value:', userData.phone);
                        }
                    });
                    
                    // Additional check for phone fields with case-insensitive placeholder matching
                    $('input:visible').each(function() {
                        var $input = $(this);
                        var placeholder = $input.attr('placeholder') || '';
                        if (!$input.val() && placeholder.toLowerCase().indexOf('phone') !== -1) {
                            $input.val(userData.phone).trigger('change').trigger('input');
                            console.log('HME: Populated phone field with placeholder:', placeholder);
                        }
                    });
                }
                
                return true; // Form found and populated
            }
            return false; // Not on information step yet or form not ready
        }
        
        // Set up a MutationObserver to watch for step changes
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList' || mutation.type === 'attributes') {
                    // Check if we've moved to the information step
                    populateBookneticForm();
                }
            });
        });
        
        // Start observing the document for changes
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'style']
        });
        
        // Also try periodically in case the observer misses something
        var checkInterval = setInterval(function() {
            if ( populateBookneticForm() ) {
                clearInterval(checkInterval);
                console.log('HME: Auto-population successful, stopping periodic checks');
            }
        }, 1000);
        <?php endif; ?>
        
        var appointmentProcessed = false;
        var checkCount = 0;
        
        function checkForNewAppointment() {
            if (appointmentProcessed || checkCount > 24) return; // Stop after 2 minutes (24 * 5 seconds)
            
            checkCount++;
            console.log('HME: Database check #' + checkCount);
            
            $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'hme_check_recent_appointments',
                order_id: <?php echo $order_id; ?>,
                nonce: '<?php echo wp_create_nonce('hme-appointment-nonce'); ?>'
            }, function(response) {
                if (response.success && response.data.appointment_found) {
                    appointmentProcessed = true;
                    console.log('HME: New appointment found in database!', response.data);
                    
                    // Show success message
                    $('body').append('<div class="hme-success-notice" style="position:fixed;top:20px;right:20px;background:#4CAF50;color:white;padding:15px;border-radius:5px;z-index:9999;">Appointment scheduled successfully!</div>');
                    setTimeout(function() { $('.hme-success-notice').fadeOut(); }, 3000);
                }
            });
        }
        
        // Check every 5 seconds for new appointments
        var checkInterval = setInterval(function() {
            if (appointmentProcessed) {
                clearInterval(checkInterval);
                return;
            }
            checkForNewAppointment();
        }, 5000);
        
        // Initial check after 3 seconds
        setTimeout(checkForNewAppointment, 3000);
    });
    </script>
    <?php
}

// Add a new AJAX handler to check for recent appointments
add_action( 'wp_ajax_hme_check_recent_appointments', 'hme_check_recent_appointments' );
add_action( 'wp_ajax_nopriv_hme_check_recent_appointments', 'hme_check_recent_appointments' );
function hme_check_recent_appointments() {
    if ( ! wp_verify_nonce( $_POST['nonce'], 'hme-appointment-nonce' ) ) {
        wp_send_json_error( 'Invalid nonce' );
    }
    
    $order_id = intval( $_POST['order_id'] );
    
    // Get the order to find customer email
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_send_json_error( 'Order not found' );
    }
    
    $customer_email = $order->get_billing_email();
    
    global $wpdb;
    
    // Check multiple possible table structures
    $table_names = [
        $wpdb->prefix . 'booknetic_appointments',
        $wpdb->prefix . 'bkntc_appointments', 
        $wpdb->prefix . 'appointments'
    ];
    
    $appointment_found = false;
    $appointments = [];
    
    foreach ( $table_names as $table_name ) {
        // Check if table exists
        $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) );
        
        if ( $table_exists ) {
            error_log( 'HME: Found Booknetic table: ' . $table_name );
            
            // Try to find appointments by customer email
            $recent_appointments = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table_name} 
                 WHERE email = %s 
                    AND (created_date > DATE_SUB(NOW(), INTERVAL 10 MINUTE) 
                         OR date > DATE_SUB(NOW(), INTERVAL 1 DAY))
                 ORDER BY id DESC LIMIT 5",
                $customer_email
            ) );
            
            // If no email column, try other approaches
            if ( empty( $recent_appointments ) ) {
                            // Try to find recent appointments and match them later
            // First check if created_date column exists
            $columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name}" );
            $has_created_date = false;
            foreach ( $columns as $column ) {
                if ( $column->Field === 'created_date' ) {
                    $has_created_date = true;
                    break;
                }
            }
            
            if ( $has_created_date ) {
                $recent_appointments = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM {$table_name} 
                     WHERE (created_date > DATE_SUB(NOW(), INTERVAL 10 MINUTE) 
                            OR date > DATE_SUB(NOW(), INTERVAL 1 DAY))
                     ORDER BY id DESC LIMIT %d",
                    10
                ) );
            } else {
                // Fallback to just checking date field
                $recent_appointments = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM {$table_name} 
                     WHERE date > DATE_SUB(NOW(), INTERVAL 1 DAY)
                     ORDER BY id DESC LIMIT %d",
                    10
                ) );
            }
            }
            
            error_log( 'HME: Found ' . count( $recent_appointments ) . ' recent appointments in ' . $table_name );
            
            if ( ! empty( $recent_appointments ) ) {
                $appointment_found = true;
                $appointments = array_merge( $appointments, $recent_appointments );
                
                // Try to process the most recent appointment
                $latest_appointment = $recent_appointments[0];
                
                // Extract date and time info
                $start_date = null;
                $end_date = null;
                
                // Check various date fields
                if ( isset( $latest_appointment->date ) && isset( $latest_appointment->start_time ) ) {
                    $start_date = $latest_appointment->date . ' ' . $latest_appointment->start_time;
                } elseif ( isset( $latest_appointment->start_date_time ) ) {
                    $start_date = $latest_appointment->start_date_time;
                } elseif ( isset( $latest_appointment->datetime ) ) {
                    $start_date = $latest_appointment->datetime;
                }
                
                // Calculate end date
                if ( $start_date ) {
                    if ( isset( $latest_appointment->end_time ) ) {
                        $end_date = $latest_appointment->date . ' ' . $latest_appointment->end_time;
                    } elseif ( isset( $latest_appointment->end_date_time ) ) {
                        $end_date = $latest_appointment->end_date_time;
                    } else {
                        // Default: add 1 hour to start time
                        $end_date = date( 'Y-m-d H:i:s', strtotime( $start_date ) + 3600 );
                    }
                    
                    error_log( 'HME: Found appointment dates - Start: ' . $start_date . ', End: ' . $end_date );
                    
                    // Update JobNimbus directly
                    $update_result = hme_update_jobnimbus_appointment_dates( 
                        $order_id, 
                        $start_date, 
                        $end_date, 
                        $latest_appointment->id ?? null 
                    );
                    
                    if ( $update_result ) {
                        error_log( 'HME: Successfully updated JobNimbus from database check' );
                    }
                }
                
                break; // Found appointments, no need to check other tables
            }
        }
    }
    
    // If no specific table found, show table structure for debugging
    if ( ! $appointment_found ) {
        $booknetic_tables = $wpdb->get_results( "SHOW TABLES LIKE '%booknetic%'" );
        $bkntc_tables = $wpdb->get_results( "SHOW TABLES LIKE '%bkntc%'" );
        $all_tables = array_merge( $booknetic_tables, $bkntc_tables );
        error_log( 'HME: Available Booknetic-related tables: ' . wp_json_encode( $all_tables ) );
    }
    
    wp_send_json_success( [
        'appointment_found' => $appointment_found,
        'appointments' => $appointments,
        'order_id' => $order_id,
        'customer_email' => $customer_email
    ] );
}

/* ──────────────────  helper: search or create  ───────────────────── */
function hme_jn_search_or_create( $entity, array $filter, array $createBody ) {
    $hits = hme_jn(
        "$entity?filter=" . rawurlencode( wp_json_encode( $filter ) )
    );
    return $hits['results'][0] ?? hme_jn( $entity, 'POST', $createBody );
}

/* ──────────────────  helper: find task/contact/job by ext id  ────── */
function hme_jn_find_by_external( $entity, $extId ) {
    $hits = hme_jn(
        "$entity?filter=" . rawurlencode( wp_json_encode( [
            'must' => [ [ 'term' => [ 'external_id' => $extId ] ] ]
        ] ) )
    );
    return $hits['results'][0] ?? null;
}

/* ──────────────────  low-level JobNimbus call  ───────────────────── */
function hme_jn( $endpoint, $method = 'GET', $body = null ) {
    $resp = wp_remote_request(
        "https://app.jobnimbus.com/api1/$endpoint",
        [
            'method'  => $method,
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . HME_JN_API_KEY,
                'Content-Type'  => 'application/json'
            ],
            'body'    => $body ? wp_json_encode( $body ) : null
        ]
    );
    
    $response_code = wp_remote_retrieve_response_code( $resp );
    $response_body = wp_remote_retrieve_body( $resp );
    
    if ( is_wp_error( $resp ) ) {
        error_log( 'HME JobNimbus API Error: ' . $resp->get_error_message() );
        return null;
    }
    
    if ( $response_code >= 400 ) {
        error_log( 'HME JobNimbus API HTTP Error ' . $response_code . ': ' . $response_body );
        return null;
    }
    
    $result = json_decode( $response_body, true );
    
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        error_log( 'HME JobNimbus API JSON Error: ' . json_last_error_msg() );
        return null;
    }
    
    return $result;
}

/******************************************************************
 *  AFTER PAYMENT ► SEND CUSTOMER TO BOOKNETIC WITH AUTO-LOGIN
 *****************************************************************/
add_action( 'woocommerce_thankyou', 'hme_redirect_to_schedule_page', 5, 1 );

function hme_redirect_to_schedule_page( $order_id ) {

    if ( ! $order_id || is_admin() || wp_doing_ajax() ) return;

    $order = wc_get_order( $order_id );
    if ( ! $order || $order->has_status( 'failed' ) ) return;

    // Get customer information
    $customer_id = $order->get_customer_id();
    $customer_email = $order->get_billing_email();

    // Ensure customer is logged in to WordPress for Booknetic auto-population
    if ( ! is_user_logged_in() && $customer_id ) {
        // Log in the customer automatically
        wp_set_current_user( $customer_id );
        wp_set_auth_cookie( $customer_id );
        error_log( 'HME: Auto-logged in customer ' . $customer_id . ' for Booknetic integration' );
    } elseif ( ! is_user_logged_in() && $customer_email ) {
        // Try to find user by email if no customer_id
        $user = get_user_by( 'email', $customer_email );
        if ( $user ) {
            wp_set_current_user( $user->ID );
            wp_set_auth_cookie( $user->ID );
            error_log( 'HME: Auto-logged in user by email ' . $customer_email . ' for Booknetic integration' );
        }
    }

    /* price → minutes */
    $rate = 75;                                 // $ / hour
    $minutes = ceil( (float) $order->get_total() / $rate * 60 );

    /* minutes → service_id */
    $service_map = [
        30  => 1,      // 1 hr   service ID
        60  => 2,      // 1.5 hr service ID
        90 => 3,      // 2 hr   service ID
        120 => 4,
    ];
    // choose the closest bracket (or default)
    $service_id = $service_map[ $minutes ] ?? $service_map[60];

    /* stash info for middleware if you wish */
    $order->update_meta_data( '_hme_service_minutes', $minutes );
    $order->update_meta_data( '_hme_service_id', $service_id );
    $order->save();

    // Store order ID in session for Booknetic to use
    if ( ! session_id() ) {
        session_start();
    }
    $_SESSION['hme_order_id'] = $order_id;

    /* build URL */
    $target = add_query_arg(
        [
            'oid'        => $order_id,
            'service_id' => $service_id
        ],
        site_url( '/schedule-your-visit/' )
    );

    wp_safe_redirect( $target );
    exit;
}

/******************************************************************
 *  [hme_booknetic]  →  renders Booknetic with service_id from ?service_id=
 *****************************************************************/
add_shortcode( 'hme_booknetic', function ( $atts ) {

	// Default/fallback service if query param missing
	$default_service = 2;

	// Pull id from the URL:  /schedule-your-visit/?service_id=123&oid=2163
	$service_id = isset( $_GET['service_id'] )
		? intval( $_GET['service_id'] )
		: $default_service;
		
	$order_id = isset( $_GET['oid'] ) ? intval( $_GET['oid'] ) : '';

	/*
	|---------------------------------------------------------------
	|  Compose the REAL Booknetic shortcode string
	|---------------------------------------------------------------
	|  ⤷ Use whatever other attributes you like here.
	*/
	$booknetic = sprintf(
		'[booknetic appearance="Minimal"
		 location_step="yes"
		 date_time_step="yes"
		 information_step="yes"
		 confirmation_step="yes"
		 hide_header="yes"
		 service="%d"
		 staff_any="yes"
		 custom_field_woo_id="%s"]',
		$service_id,
		$order_id
	);

	// Parse & return it so Booknetic runs
	return do_shortcode( $booknetic );
} );