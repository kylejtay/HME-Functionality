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
 *  BOOKNETIC APPOINTMENT ► UPDATE JOBNIMBUS DATES
 *****************************************************************/

// Add AJAX endpoints for appointment handling
add_action( 'wp_ajax_hme_appointment_scheduled', 'hme_handle_appointment_scheduled' );
add_action( 'wp_ajax_nopriv_hme_appointment_scheduled', 'hme_handle_appointment_scheduled' );

function hme_handle_appointment_scheduled() {
    // Verify nonce
    if ( ! wp_verify_nonce( $_POST['nonce'], 'hme-appointment-nonce' ) ) {
        wp_send_json_error( 'Invalid nonce' );
    }

    // Get order ID from session or URL parameter
    $order_id = null;
    
    if ( ! session_id() ) {
        session_start();
    }
    
    if ( isset( $_SESSION['hme_order_id'] ) ) {
        $order_id = $_SESSION['hme_order_id'];
        error_log( 'HME: Found order ID from session: ' . $order_id );
    } elseif ( isset( $_POST['order_id'] ) ) {
        $order_id = intval( $_POST['order_id'] );
        error_log( 'HME: Found order ID from POST: ' . $order_id );
    } elseif ( isset( $_GET['oid'] ) ) {
        $order_id = intval( $_GET['oid'] );
        error_log( 'HME: Found order ID from GET: ' . $order_id );
    }

    if ( ! $order_id ) {
        error_log( 'HME: No order ID found for appointment' );
        wp_send_json_error( 'No order ID found' );
    }

    // Get appointment data from POST
    $start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( $_POST['start_date'] ) : '';
    $end_date = isset( $_POST['end_date'] ) ? sanitize_text_field( $_POST['end_date'] ) : '';
    $appointment_id = isset( $_POST['appointment_id'] ) ? intval( $_POST['appointment_id'] ) : 0;
    
    error_log( 'HME: Processing appointment - Order: ' . $order_id . ', Start: ' . $start_date . ', End: ' . $end_date );

    // Get the order and JobNimbus job ID
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        error_log( 'HME: Order not found: ' . $order_id );
        wp_send_json_error( 'Order not found' );
    }

    $job_id = $order->get_meta( '_hme_jobnimbus_job_id' );
    if ( ! $job_id ) {
        error_log( 'HME: JobNimbus job ID not found for order: ' . $order_id );
        wp_send_json_error( 'JobNimbus job ID not found' );
    }

    // Convert dates to timestamps
    $date_start = strtotime( $start_date );
    $date_end = strtotime( $end_date );

    if ( ! $date_start || ! $date_end ) {
        error_log( 'HME: Invalid date format - Start: ' . $start_date . ', End: ' . $end_date );
        wp_send_json_error( 'Invalid date format' );
    }

    // Update job dates and status
    $job_result = hme_jn( "jobs/$job_id", 'PUT', [ 
        'date_start' => $date_start,
        'date_end' => $date_end,
        'status_name' => 'Scheduled'
    ] );

    if ( ! $job_result ) {
        error_log( 'HME: Failed to update JobNimbus job: ' . $job_id );
        wp_send_json_error( 'Failed to update job' );
    }

    // Update all related tasks with the same dates
    $tasks = hme_jn( "tasks?filter=" . rawurlencode( wp_json_encode( [
        'must' => [ [ 'term' => [ 'related.id' => $job_id ] ] ]
    ] ) ) );

    $updated_tasks = 0;
    if ( ! empty( $tasks['results'] ) ) {
        foreach ( $tasks['results'] as $task ) {
            $task_result = hme_jn( "tasks/{$task['jnid']}", 'PUT', [
                'date_start' => $date_start,
                'date_end' => $date_end
            ] );
            
            if ( $task_result ) {
                $updated_tasks++;
            }
        }
    }

    // Store appointment reference in order meta
    $order->update_meta_data( '_hme_appointment_id', $appointment_id );
    $order->update_meta_data( '_hme_appointment_start', $start_date );
    $order->update_meta_data( '_hme_appointment_end', $end_date );
    $order->save();

    error_log( 'HME: Successfully updated JobNimbus - Job: ' . $job_id . ', Tasks: ' . $updated_tasks );
    
    wp_send_json_success( [
        'message' => 'Appointment scheduled successfully',
        'job_id' => $job_id,
        'tasks_updated' => $updated_tasks
    ] );
}

// Add JavaScript to monitor Booknetic appointment creation
add_action( 'wp_footer', 'hme_booknetic_appointment_monitor' );
function hme_booknetic_appointment_monitor() {
    // Only run on the Booknetic scheduling page
    if ( ! is_page() || ! has_shortcode( get_post()->post_content, 'hme_booknetic' ) ) {
        return;
    }
    
    $order_id = isset( $_GET['oid'] ) ? intval( $_GET['oid'] ) : 0;
    ?>
    <script type="text/javascript">
    jQuery(function($) {
        console.log('HME: Appointment monitor started for order <?php echo $order_id; ?>');
        
        var appointmentProcessed = false;
        var initialPageState = $('body').html(); // Capture initial state
        
        // Monitor for actual changes/completions rather than existing elements
        function checkBookneticSuccess() {
            if (appointmentProcessed) return;
            
            // Check if we're on step 4 (Confirmation) and there's been a change
            var currentStep = $('.booknetic-step-4, [data-step="4"], .step-4').length > 0;
            var confirmationStep = $('.booknetic-confirmation-step, .confirmation-step').length > 0;
            
            // Look for NEW success/confirmation elements (not present on initial load)
            var currentPageState = $('body').html();
            var pageHasChanged = currentPageState !== initialPageState;
            
            // More specific success indicators that appear after booking
            var appointmentSuccessElements = $(
                '.booknetic-appointment-success, ' +
                '.booknetic-booking-confirmed, ' +
                '.appointment-confirmed-message, ' +
                '.booking-success-message, ' +
                '[data-status="confirmed"], ' +
                '[data-booking-confirmed="true"]'
            );
            
            // Check for confirmation buttons/text that appear after successful booking
            var confirmationText = $('body').text();
            var hasConfirmationText = confirmationText.includes('Your appointment has been') || 
                                    confirmationText.includes('Appointment confirmed') ||
                                    confirmationText.includes('Successfully booked') ||
                                    confirmationText.includes('Thank you for booking');
            
            // Only trigger if we detect actual success indicators AND page has changed
            if (appointmentSuccessElements.length > 0 || (hasConfirmationText && pageHasChanged)) {
                console.log('HME: Appointment completion detected!');
                console.log('HME: Success elements:', appointmentSuccessElements.length, 'Text confirmed:', hasConfirmationText, 'Page changed:', pageHasChanged);
                handleAppointmentSuccess();
                return;
            }
            
            // Alternative: Check if we're on the final step and dates are now visible
            if ((currentStep || confirmationStep) && pageHasChanged) {
                var dateElements = $('input[type="hidden"]').filter(function() {
                    return $(this).val().match(/\d{4}-\d{2}-\d{2}/) && $(this).val().match(/\d{2}:\d{2}/);
                });
                
                if (dateElements.length > 0) {
                    console.log('HME: Found appointment dates in final step');
                    handleAppointmentSuccess();
                    return;
                }
            }
        }
        
        function handleAppointmentSuccess() {
            if (appointmentProcessed) return;
            appointmentProcessed = true;
            
            console.log('HME: Booknetic appointment detected - processing...');
            
            // Try to extract appointment data from the page
            var startDate = extractAppointmentDate('start');
            var endDate = extractAppointmentDate('end');
            var appointmentId = extractAppointmentId();
            
            console.log('HME: Extracted data - Start:', startDate, 'End:', endDate, 'ID:', appointmentId);
            
            if (startDate && endDate) {
                console.log('HME: Sending to backend...');
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'hme_appointment_scheduled',
                    order_id: <?php echo $order_id; ?>,
                    start_date: startDate,
                    end_date: endDate,
                    appointment_id: appointmentId,
                    nonce: '<?php echo wp_create_nonce('hme-appointment-nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        console.log('HME: JobNimbus updated successfully', response.data);
                    } else {
                        console.log('HME: Error updating JobNimbus', response.data);
                    }
                }).fail(function(xhr, status, error) {
                    console.log('HME: AJAX failed', status, error);
                });
            } else {
                console.log('HME: Could not extract appointment dates');
                
                // Debug: Show what's on the page
                console.log('HME: Page HTML contains:');
                var relevantInputs = $('input[type="hidden"], input[type="text"], input[type="datetime-local"]').filter(function() {
                    var name = $(this).attr('name') || '';
                    var value = $(this).val() || '';
                    return name.toLowerCase().includes('date') || name.toLowerCase().includes('time') || 
                           value.match(/\d{4}-\d{2}-\d{2}/) || value.match(/\d{2}:\d{2}/);
                });
                relevantInputs.each(function() {
                    console.log('HME: Input found - Name:', $(this).attr('name'), 'Value:', $(this).val());
                });
            }
        }
        
        function extractAppointmentDate(type) {
            // Try different ways to extract appointment dates from Booknetic
            var date = null;
            
            // Method 1: Look for data attributes
            $('[data-appointment-' + type + '], [data-' + type + '-date], [data-' + type + ']').each(function() {
                date = $(this).data('appointment-' + type) || $(this).data(type + '-date') || $(this).data(type);
            });
            
            // Method 2: Look for various input patterns
            if (!date) {
                var inputs = $('input[name*="' + type + '"], input[name*="date"], input[name*="time"]');
                inputs.each(function() {
                    var val = $(this).val();
                    if (val && (val.match(/\d{4}-\d{2}-\d{2}/) || val.match(/\d{2}:\d{2}/))) {
                        if (type === 'start' && !date) date = val;
                        if (type === 'end' && date && val !== date) date = val; // Different from start
                    }
                });
            }
            
            // Method 3: Look in confirmation/summary text
            if (!date) {
                var confirmText = $('body').text();
                var patterns = [
                    /(\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}:?\d{0,2})/g,
                    /(\d{1,2}\/\d{1,2}\/\d{4}\s+\d{1,2}:\d{2})/g,
                    /(\d{2}-\d{2}-\d{4}\s+\d{1,2}:\d{2})/g
                ];
                
                patterns.forEach(function(pattern) {
                    if (!date) {
                        var matches = confirmText.match(pattern);
                        if (matches && matches.length > 0) {
                            date = matches[0];
                            if (type === 'end' && matches.length > 1) {
                                date = matches[1]; // Assume second match is end time
                            }
                        }
                    }
                });
            }
            
            // Method 4: Fallback - create end date from start date (add 1 hour)
            if (!date && type === 'end') {
                var startDate = extractAppointmentDate('start');
                if (startDate) {
                    var start = new Date(startDate);
                    start.setHours(start.getHours() + 1);
                    date = start.toISOString().slice(0, 19);
                }
            }
            
            console.log('HME: Extracted ' + type + ' date:', date);
            return date;
        }
        
        function extractAppointmentId() {
            // Try to find appointment ID
            var id = $('[data-appointment-id]').data('appointment-id');
            if (!id) {
                id = $('input[name*="appointment_id"]').val();
            }
            return id || Math.floor(Date.now() / 1000); // Fallback to timestamp
        }
        
        // Monitor for form submissions (Booknetic likely uses forms)
        $(document).on('submit', 'form', function() {
            console.log('HME: Form submitted, checking for appointment completion in 3 seconds...');
            setTimeout(checkBookneticSuccess, 3000);
        });
        
        // Monitor for button clicks that might complete booking
        $(document).on('click', 'button, input[type="submit"], .btn, .button', function() {
            var buttonText = $(this).text().toLowerCase();
            if (buttonText.includes('confirm') || buttonText.includes('book') || buttonText.includes('finish') || buttonText.includes('complete')) {
                console.log('HME: Booking-related button clicked:', buttonText);
                setTimeout(checkBookneticSuccess, 2000);
            }
        });
        
        // Monitor for AJAX completion
        $(document).ajaxComplete(function(event, xhr, settings) {
            console.log('HME: AJAX completed, checking for appointment completion...');
            setTimeout(checkBookneticSuccess, 1500);
        });
        
        // Monitor for URL hash changes (some booking systems use hash routing)
        $(window).on('hashchange', function() {
            console.log('HME: URL hash changed, checking for completion...');
            setTimeout(checkBookneticSuccess, 1000);
        });
        
        // Initial check (but won't trigger due to page state comparison)
        setTimeout(checkBookneticSuccess, 2000);
        
        // Periodic check with reduced frequency
        setInterval(checkBookneticSuccess, 5000);
    });
    </script>
    <?php
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
 *  AFTER PAYMENT ► SEND CUSTOMER TO BOOKNETIC
 *****************************************************************/
add_action( 'woocommerce_thankyou', 'hme_redirect_to_schedule_page', 5, 1 );

function hme_redirect_to_schedule_page( $order_id ) {

    if ( ! $order_id || is_admin() || wp_doing_ajax() ) return;

    $order = wc_get_order( $order_id );
    if ( ! $order || $order->has_status( 'failed' ) ) return;

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