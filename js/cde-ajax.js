jQuery(function($) {
    // Delay AJAX requests to avoid sending multiple requests for the same user input
    var timeout = null;
    
    // Listen for changes in checkout fields
    $('form.checkout').on('change', 'input, select, textarea', function() {
        var fieldName = $(this).attr('name'); // Get the name of the field
        var fieldValue = $(this).val();       // Get the value of the field

        // Ensure the field has a value before sending
        if (fieldValue) {
            clearTimeout(timeout);  // Clear the previous timeout (debounce)

            timeout = setTimeout(function() {
                var data = {
                    action: 'save_checkout_data',  // The AJAX action name
                    nonce: cde_ajax_object.nonce,  // Security nonce
                    field_name: fieldName,         // Field name (billing_phone, shipping_address, etc.)
                    field_value: fieldValue,       // Field value
                    session_id: cde_ajax_object.session_id  // User session ID or order ID (optional)
                };

                // Send the data via AJAX
                $.post(cde_ajax_object.ajax_url, data, function(response) {
                    // Optionally handle the response (like showing a success message)
                    if (response.success) {
                        console.log('Data saved successfully:', response.data);
                    } else {
                        console.error('Error saving data:', response.data);
                    }
                });
            }, 500); // Delay by 500ms (debounce)
        }
    });
});