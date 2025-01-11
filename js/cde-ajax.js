jQuery(function ($) {
    // Listen for changes in checkout form fields
    $(document.body).on('change', 'input, select, textarea', function () {
        var fieldName = $(this).attr('name');
        var fieldValue = $(this).val();
        console.log('Field Changed:', fieldName, fieldValue); // Debugging line

        if (fieldValue) {
            $.post(cde_ajax_object.ajax_url, {
                action: 'save_checkout_data',
                nonce: cde_ajax_object.nonce,
                field_name: fieldName,
                field_value: fieldValue
            }, function (response) {
                if (response.success) {
                    console.log('Saved:', response.data);
                } else {
                    console.error('Error:', response.data);
                }
            });
        }
    });
});