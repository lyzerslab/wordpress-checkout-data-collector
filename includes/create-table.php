<?php
function cde_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkout_data';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id INT NOT NULL AUTO_INCREMENT,
        session_id VARCHAR(255) NOT NULL,
        field_name VARCHAR(255) NOT NULL,
        field_value TEXT NOT NULL,
        product_data TEXT, -- Add this column for product data
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
?>