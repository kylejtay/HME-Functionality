jQuery(function($) {
    var hmeModal = {
        init: function() {
            this.createModal();
            this.bindEvents();
        },

        createModal: function() {
            var modalHtml = `
                <div id="hme-cart-modal" class="hme-modal" style="display: none;">
                    <div class="hme-modal-content">
                        <div class="hme-modal-header">
                            <h3>Service Location Information</h3>
                            <span class="hme-modal-close">&times;</span>
                        </div>
                        <form id="hme-cart-modal-form" enctype="multipart/form-data">
                            <div class="hme-modal-body">
                                <div class="hme-form-group">
                                    <label for="hme-service-location">Service Location *</label>
                                    <textarea id="hme-service-location" name="service_location" rows="4" 
                                              placeholder="Please describe where this service will be provided (e.g., front yard, kitchen, master bedroom, etc.)" 
                                              required></textarea>
                                </div>
                                <div class="hme-form-group">
                                    <label for="hme-service-photo">Photo of Area (Optional)</label>
                                    <input type="file" id="hme-service-photo" name="service_photo" 
                                           accept="image/*" />
                                    <small>Upload a photo to help us better understand the service area</small>
                                </div>
                                <input type="hidden" id="hme-product-id" name="product_id" value="">
                                <input type="hidden" id="hme-variation-id" name="variation_id" value="">
                                <input type="hidden" id="hme-quantity" name="quantity" value="1">
                            </div>
                            <div class="hme-modal-footer">
                                <button type="button" class="hme-btn hme-btn-secondary" id="hme-modal-cancel">Cancel</button>
                                <button type="submit" class="hme-btn hme-btn-primary" id="hme-modal-save">Add to Cart</button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            $('body').append(modalHtml);
        },

        bindEvents: function() {
            var self = this;

            // Intercept add to cart buttons
            $(document).on('click', '.single_add_to_cart_button:not(.disabled)', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                var $button = $(this);
                var $form = $button.closest('form.cart');
                
                // Skip if this is a variable product without variation selected
                if ($form.find('select[name="attribute_pa_size"]').length && !$form.find('select[name="attribute_pa_size"]').val()) {
                    return true; // Let default behavior handle this
                }

                // Get product data
                var productId = $form.find('input[name="add-to-cart"]').val() || $form.find('button[name="add-to-cart"]').val();
                var variationId = $form.find('input[name="variation_id"]').val() || '';
                var quantity = $form.find('input[name="quantity"]').val() || 1;

                // Store data in modal
                $('#hme-product-id').val(productId);
                $('#hme-variation-id').val(variationId);
                $('#hme-quantity').val(quantity);

                // Show modal
                self.showModal();
                
                return false;
            });

            // Close modal events
            $(document).on('click', '.hme-modal-close, #hme-modal-cancel', function() {
                self.hideModal();
            });

            // Close modal when clicking outside
            $(document).on('click', '#hme-cart-modal', function(e) {
                if (e.target.id === 'hme-cart-modal') {
                    self.hideModal();
                }
            });

            // Form submission
            $(document).on('submit', '#hme-cart-modal-form', function(e) {
                e.preventDefault();
                self.submitForm();
            });

            // ESC key to close modal
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27 && $('#hme-cart-modal').is(':visible')) {
                    self.hideModal();
                }
            });
        },

        showModal: function() {
            $('#hme-cart-modal').fadeIn(300);
            $('#hme-service-location').focus();
            $('body').addClass('hme-modal-open');
        },

        hideModal: function() {
            $('#hme-cart-modal').fadeOut(300);
            $('#hme-cart-modal-form')[0].reset();
            $('body').removeClass('hme-modal-open');
        },

        submitForm: function() {
            var self = this;
            var $form = $('#hme-cart-modal-form');
            var $submitBtn = $('#hme-modal-save');
            
            // Validate required fields
            var location = $('#hme-service-location').val().trim();
            if (!location) {
                alert('Please enter the service location.');
                $('#hme-service-location').focus();
                return;
            }

            // Disable submit button and show loading
            $submitBtn.prop('disabled', true).text('Adding to Cart...');

            // Prepare form data
            var formData = new FormData();
            formData.append('action', 'hme_add_to_cart_with_location');
            formData.append('product_id', $('#hme-product-id').val());
            formData.append('variation_id', $('#hme-variation-id').val());
            formData.append('quantity', $('#hme-quantity').val());
            formData.append('service_location', location);
            formData.append('nonce', hme_cart_params.nonce);

            // Add photo if selected
            var photoFile = $('#hme-service-photo')[0].files[0];
            if (photoFile) {
                formData.append('service_photo', photoFile);
            }

            // Submit via AJAX
            $.ajax({
                url: hme_cart_params.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        // Hide modal
                        self.hideModal();
                        
                        // Trigger cart update
                        $(document.body).trigger('added_to_cart', [
                            response.data.fragments, 
                            response.data.cart_hash, 
                            $submitBtn
                        ]);

                        // Show success message
                        if (response.data.message) {
                            self.showNotice(response.data.message, 'success');
                        }
                    } else {
                        self.showNotice(response.data || 'Error adding item to cart', 'error');
                    }
                },
                error: function() {
                    self.showNotice('Network error. Please try again.', 'error');
                },
                complete: function() {
                    $submitBtn.prop('disabled', false).text('Add to Cart');
                }
            });
        },

        showNotice: function(message, type) {
            var noticeClass = type === 'success' ? 'woocommerce-message' : 'woocommerce-error';
            var notice = '<div class="woocommerce-notices-wrapper"><div class="' + noticeClass + '" role="alert">' + message + '</div></div>';
            
            // Remove existing notices
            $('.woocommerce-notices-wrapper').remove();
            
            // Add new notice
            if ($('.woocommerce').length) {
                $('.woocommerce').first().prepend(notice);
            } else {
                $('main, .main, #main').first().prepend(notice);
            }

            // Scroll to notice
            $('html, body').animate({
                scrollTop: $('.woocommerce-notices-wrapper').offset().top - 100
            }, 500);

            // Auto-remove success notices
            if (type === 'success') {
                setTimeout(function() {
                    $('.woocommerce-notices-wrapper').fadeOut();
                }, 5000);
            }
        }
    };

    // Initialize when DOM is ready
    hmeModal.init();
}); 