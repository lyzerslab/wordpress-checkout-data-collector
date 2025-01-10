<?php
/**
 * This file contains the table creation function for the Checkout Data to Excel plugin.
 * The table stores checkout data captured from WooCommerce orders.
 */

 function cde_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkout_data';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        field_name varchar(255) NOT NULL,
        field_value text NOT NULL,
        timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'cde_create_table');