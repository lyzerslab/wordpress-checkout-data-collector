<?php
/**
 * Plugin Name: Checkout Data to Excel
 * Plugin URI: https://lyzerslab/wordpress-products/checkout-data-to-excel/
 * Description: Capture WooCommerce checkout data and allow downloading it as an Excel sheet from the admin panel.
 * Version: 1.2
 * Author: Lyzerslab
 * Author URI: https://lyzerslab/
 * Text Domain: checkout-data-to-excel
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.3
 * Requires PHP: 7.4
 * WC tested up to: 7.5
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Include the table creation function
require_once plugin_dir_path(__FILE__) . 'includes/create-table.php';

// Activation hook to create the database table
register_activation_hook(__FILE__, 'cde_create_table');

// Autoload PhpSpreadsheet library
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Enqueue JavaScript for AJAX
function cde_enqueue_scripts() {
    if (is_checkout() && !is_order_received_page()) {
        wp_enqueue_script('cde-ajax-script', plugins_url('js/cde-ajax.js', __FILE__), array('jquery'), '1.1', true);
        wp_localize_script('cde-ajax-script', 'cde_ajax_object', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cde_nonce'),
            'session_id' => session_id(), // Add session ID here
        ));
    }
}
add_action('wp_enqueue_scripts', 'cde_enqueue_scripts');

// Hook to capture checkout data after order is processed
add_action('woocommerce_checkout_update_order_meta', 'cde_capture_checkout_data', 10, 2);
function cde_capture_checkout_data($order_id, $data) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkout_data';

    $order = wc_get_order($order_id);
    $checkout_data = [
        'billing_first_name' => $order->get_billing_first_name(),
        'billing_last_name'  => $order->get_billing_last_name(),
        'billing_email'      => $order->get_billing_email(),
        'billing_phone'      => $order->get_billing_phone(),
        'billing_address'    => $order->get_billing_address_1(),
        'shipping_address'   => $order->get_shipping_address_1(),
        'order_total'        => $order->get_total(),
    ];

    $wpdb->insert($table_name, [
        'order_id'      => $order_id,
        'checkout_data' => json_encode($checkout_data),
    ]);
}

// Handle the AJAX request to save checkout data
add_action('wp_ajax_save_checkout_data', 'cde_save_checkout_data');
add_action('wp_ajax_nopriv_save_checkout_data', 'cde_save_checkout_data');

function cde_save_checkout_data() {
    // Check the nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cde_nonce')) {
        wp_send_json_error('Invalid nonce');
    }

    // Get the field data
    $field_name = sanitize_text_field($_POST['field_name']);
    $field_value = sanitize_text_field($_POST['field_value']);

    // Get the user ID if logged in or session ID for guests
    $user_id = get_current_user_id(); // Get the user ID if logged in
    if ($user_id == 0) {
        $session_id = session_id(); // Use session ID for guests
    } else {
        $session_id = $user_id; // Use user ID for logged-in users
    }

    // Debugging: Log the captured data
    error_log('Session/User ID: ' . $session_id); // Debugging line
    error_log('Field Data: ' . $field_name . ' => ' . $field_value); // Debugging line

    // Store the data in the database
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkout_data';

    // Save the checkout data
    $checkout_data = array(
        'field_name' => $field_name,
        'field_value' => $field_value,
        'session_id' => $session_id, // Store by session ID or user ID
        'timestamp' => current_time('mysql'),
    );

    // Insert data into the database
    $wpdb->insert($table_name, array(
        'checkout_data' => json_encode($checkout_data),
    ));

    // Debugging: Log the result of the insert query
    error_log('Data saved: ' . print_r($checkout_data, true)); // Debugging line

    // Return a success response
    wp_send_json_success('Data saved successfully');
}

// Add admin menu for exporting data
add_action('admin_menu', 'cde_add_admin_menu');
function cde_add_admin_menu() {
    add_menu_page(
        __('Checkout Data Export', 'checkout-data-to-excel'),
        __('Checkout Data Export', 'checkout-data-to-excel'),
        'manage_options',
        'checkout-data-export',
        'cde_admin_page',
        'dashicons-download',
        20
    );
}

// Admin page for downloading Excel
function cde_admin_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('Export Checkout Data to Excel', 'checkout-data-to-excel'); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field('cde_download_excel', 'cde_nonce'); ?>
            <p>
                <button type="submit" name="cde_download_excel" class="button button-primary">
                    <?php _e('Download Excel', 'checkout-data-to-excel'); ?>
                </button>
            </p>
        </form>
    </div>
    <?php
}

// Handle Excel download request
add_action('admin_init', 'cde_handle_excel_download');
function cde_handle_excel_download() {
    if (isset($_POST['cde_download_excel']) && check_admin_referer('cde_download_excel', 'cde_nonce')) {
        cde_generate_excel();
    }
}

// Generate the Excel file
function cde_generate_excel() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkout_data';
    $results = $wpdb->get_results("SELECT * FROM $table_name");

    if (empty($results)) {
        wp_die(__('No checkout data available to export.', 'checkout-data-to-excel'));
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Add headers
    $sheet->setCellValue('A1', 'Session/User ID');
    $sheet->setCellValue('B1', 'Field Name');
    $sheet->setCellValue('C1', 'Field Value');
    $sheet->setCellValue('D1', 'Timestamp');

    // Add data rows
    $row = 2;
    foreach ($results as $result) {
        $data = json_decode($result->checkout_data, true);
        $sheet->setCellValue("A$row", $data['session_id']);
        $sheet->setCellValue("B$row", $data['field_name']);
        $sheet->setCellValue("C$row", $data['field_value']);
        $sheet->setCellValue("D$row", $data['timestamp']);
        $row++;
    }

    // Generate and download Excel
    $writer = new Xlsx($spreadsheet);
    $file_path = plugin_dir_path(__FILE__) . 'checkout-data.xlsx';
    $writer->save($file_path);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="checkout-data.xlsx"');
    readfile($file_path);
    unlink($file_path); // Cleanup
    exit;
}

?>