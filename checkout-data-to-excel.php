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

// Start PHP session if not already started
if (!session_id()) {
    session_start();
}

// Include table creation function
require_once plugin_dir_path(__FILE__) . 'includes/create-table.php';

// Register activation hook for table creation
register_activation_hook(__FILE__, 'cde_create_table');

// Autoload PhpSpreadsheet
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Enqueue JavaScript for AJAX handling
function cde_enqueue_scripts() {
    if (is_checkout() && !is_order_received_page()) {
        wp_enqueue_script('cde-ajax-script', plugins_url('js/cde-ajax.js', __FILE__), array('jquery'), '1.1', true);
        wp_localize_script('cde-ajax-script', 'cde_ajax_object', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cde_nonce'),
            'session_id' => session_id(),
        ));
    }
}
add_action('wp_enqueue_scripts', 'cde_enqueue_scripts');

// Handle AJAX request to save checkout data
add_action('wp_ajax_save_checkout_data', 'cde_save_checkout_data');
add_action('wp_ajax_nopriv_save_checkout_data', 'cde_save_checkout_data');

function cde_save_checkout_data() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cde_nonce')) {
        wp_send_json_error('Invalid nonce');
    }

    // Sanitize and capture the input
    $field_name = sanitize_text_field($_POST['field_name']);
    $field_value = sanitize_text_field($_POST['field_value']);

    // Get session or user ID
    $user_id = get_current_user_id();
    $session_id = ($user_id == 0) ? session_id() : $user_id;

    // Save data to the database
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkout_data';

    $result = $wpdb->insert($table_name, [
        'session_id' => $session_id,
        'field_name' => $field_name,
        'field_value' => $field_value,
        'timestamp' => current_time('mysql'),
    ]);

    if ($result === false) {
        error_log('Database error: ' . $wpdb->last_error);
        wp_send_json_error('Failed to save data');
    }

    wp_send_json_success('Data saved successfully');
}

// Capture WooCommerce checkout data
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

// Admin page to download Excel
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

// Generate and download Excel file
function cde_generate_excel() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkout_data';
    
    // Query to restructure the data
    $query = "
        SELECT 
            session_id,
            MAX(CASE WHEN field_name = 'billing_first_name' THEN field_value END) AS billing_first_name,
            MAX(CASE WHEN field_name = 'billing_last_name' THEN field_value END) AS billing_last_name,
            MAX(CASE WHEN field_name = 'billing_company' THEN field_value END) AS billing_company,
            MAX(CASE WHEN field_name = 'billing_address_1' THEN field_value END) AS billing_address_1,
            MAX(CASE WHEN field_name = 'billing_address_2' THEN field_value END) AS billing_address_2,
            MAX(CASE WHEN field_name = 'billing_city' THEN field_value END) AS billing_city,
            MAX(CASE WHEN field_name = 'billing_postcode' THEN field_value END) AS billing_postcode,
            MAX(CASE WHEN field_name = 'billing_phone' THEN field_value END) AS billing_phone,
            MAX(CASE WHEN field_name = 'billing_email' THEN field_value END) AS billing_email,
            MAX(timestamp) AS last_updated
        FROM $table_name
        GROUP BY session_id;
    ";
    
    // Execute the query and fetch the results
    $results = $wpdb->get_results($query);

    if (empty($results)) {
        wp_die(__('No checkout data available to export.', 'checkout-data-to-excel'));
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Set headers for the exported data
    $sheet->setCellValue('A1', 'Session/User ID');
    $sheet->setCellValue('B1', 'Billing First Name');
    $sheet->setCellValue('C1', 'Billing Last Name');
    $sheet->setCellValue('D1', 'Billing Company');
    $sheet->setCellValue('E1', 'Billing Address 1');
    $sheet->setCellValue('F1', 'Billing Address 2');
    $sheet->setCellValue('G1', 'Billing City');
    $sheet->setCellValue('H1', 'Billing Postcode');
    $sheet->setCellValue('I1', 'Billing Phone');
    $sheet->setCellValue('J1', 'Billing Email');
    $sheet->setCellValue('K1', 'Last Updated');

    $current_row = 2;

    // Write the data to the sheet
    foreach ($results as $row) {
        $sheet->setCellValue("A$current_row", $row->session_id);
        $sheet->setCellValue("B$current_row", $row->billing_first_name);
        $sheet->setCellValue("C$current_row", $row->billing_last_name);
        $sheet->setCellValue("D$current_row", $row->billing_company);
        $sheet->setCellValue("E$current_row", $row->billing_address_1);
        $sheet->setCellValue("F$current_row", $row->billing_address_2);
        $sheet->setCellValue("G$current_row", $row->billing_city);
        $sheet->setCellValue("H$current_row", $row->billing_postcode);
        $sheet->setCellValue("I$current_row", $row->billing_phone);
        $sheet->setCellValue("J$current_row", $row->billing_email);
        $sheet->setCellValue("K$current_row", $row->last_updated);
        $current_row++;
    }

    // Save and serve the Excel file
    $file_name = 'checkout-data.xlsx';
    $file_path = plugin_dir_path(__FILE__) . $file_name;

    $writer = new Xlsx($spreadsheet);
    $writer->save($file_path);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=$file_name");
    readfile($file_path);
    unlink($file_path); // Cleanup after serving
    exit;
}
?>