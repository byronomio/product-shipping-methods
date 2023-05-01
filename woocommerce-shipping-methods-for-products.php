<?php

/**
 * Plugin Name: WooCommerce Shipping Methods For Products
 * Description: A custom WooCommerce plugin to assign different shipping methods to products and add local pickup addresses.
 * Version: 1.1
 * Author: Byron Jacobs
 * Author URI: https://byronjacobs.co.za
 * License: GPLv2 or later
 * Text Domain: product-shipping-methods
 *
 * This plugin adds the ability to assign specific shipping methods to products in WooCommerce. It also allows adding local pickup addresses for each Local Pickup shipping method. The available shipping methods for a cart will be filtered based on the selected shipping methods of the products in the cart. The local pickup addresses will be displayed in the customer's order confirmation email.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Check if WooCommerce is active
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    // Add meta box for shipping methods
    add_action('add_meta_boxes', 'psm_add_shipping_methods_metabox');
    function psm_add_shipping_methods_metabox()
    {
        add_meta_box(
            'psm_shipping_methods',
            __('Shipping Methods', 'product-shipping-methods'),
            'psm_shipping_methods_metabox_callback',
            'product',
            'side',
            'default'
        );
    }

    /**
     * Meta box callback to display the shipping methods for a product.
     *
     * @param WP_Post $post The post object.
     */
    function psm_shipping_methods_metabox_callback($post)
    {
        $shipping_zones = WC_Shipping_Zones::get_zones();
        $all_shipping_methods = array();

        foreach ($shipping_zones as $shipping_zone) {
            $zone_shipping_methods = $shipping_zone['shipping_methods'];
            $all_shipping_methods = array_merge($all_shipping_methods, $zone_shipping_methods);
        }

        $selected_methods = get_post_meta($post->ID, '_psm_shipping_methods', true);
        if (!is_array($selected_methods)) {
            $selected_methods = array();
        }

        echo '<div id="psm_shipping_methods_wrapper">';

        foreach ($all_shipping_methods as $shipping_method) {
            $checked = in_array($shipping_method->instance_id, $selected_methods) ? 'checked' : '';
            echo '<p><label><input type="checkbox" name="_psm_shipping_methods[]" value="' . esc_attr($shipping_method->instance_id) . '" ' . $checked . '> ' . esc_html($shipping_method->title) . '</label></p>';
        }

        echo '</div>';

        // Add nonce field
        wp_nonce_field('psm_save_shipping_methods_meta', 'psm_shipping_methods_nonce');

        // Add CSS for better display of checkboxes
        echo '<style>
        #psm_shipping_methods_wrapper p {
            margin-bottom: 5px;
        }
        #psm_shipping_methods_wrapper input[type="checkbox"] {
            margin-right: 5px;
        }
    </style>';
    }

    // Save custom field value
    add_action('save_post', 'psm_save_shipping_methods_meta', 10, 2);
    function psm_save_shipping_methods_meta($post_id, $post)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!isset($_POST['psm_shipping_methods_nonce']) || !wp_verify_nonce($_POST['psm_shipping_methods_nonce'], 'psm_save_shipping_methods_meta')) {
            return;
        }
        if ('product' !== $post->post_type) {
            return;
        }

        $shipping_methods = isset($_POST['_psm_shipping_methods']) ? array_map('wc_clean', $_POST['_psm_shipping_methods']) : array();
        update_post_meta($post_id, '_psm_shipping_methods', $shipping_methods);

        foreach ($shipping_methods as $shipping_method_id) {
            if (substr($shipping_method_id, 0, 12) === 'local_pickup') {
                $address_key = '_psm_local_pickup_address_' . $shipping_method_id;
                $address_value = isset($_POST[$address_key]) ? sanitize_text_field($_POST[$address_key]) : '';
                update_post_meta($post_id, $address_key, $address_value);
            }
        }
    }

    // Filter available shipping methods based on product's shipping methods
    add_filter('woocommerce_package_rates', 'psm_filter_shipping_methods', 10, 2);
    function psm_filter_shipping_methods($rates, $package)
    {
        $product_shipping_methods = array();

        // Get product shipping methods in cart
        foreach ($package['contents'] as $item) {
            $product_id = $item['product_id'];
            $shipping_methods = get_post_meta($product_id, '_psm_shipping_methods', true);

            if (!empty($shipping_methods) && is_array($shipping_methods)) {
                $product_shipping_methods = array_merge($product_shipping_methods, $shipping_methods);
            }
        }

        if (!empty($product_shipping_methods)) {
            $product_shipping_methods = array_unique($product_shipping_methods);
            $new_rates = array();

            foreach ($rates as $rate_key => $rate) {
                if (in_array($rate->instance_id, $product_shipping_methods)) {
                    $new_rates[$rate_key] = $rate;
                } else {
                    unset($rates[$rate_key]);
                }
            }

            return !empty($new_rates) ? $new_rates : $rates;
        }

        return $rates;
    }

    // Add address field to the Local Pickup shipping method settings
    add_filter('woocommerce_shipping_instance_form_fields_local_pickup', 'psm_add_local_pickup_address_field');
    function psm_add_local_pickup_address_field($settings)
    {
        $settings['pickup_address'] = array(
            'title'       => __('Pickup Address', 'product-shipping-methods'),
            'type'        => 'text',
            'description' => __('Enter the pickup address for this local pickup shipping method.', 'product-shipping-methods'),
            'default'     => '',
            'desc_tip'    => true,
            'custom_attributes' => array(
                'class' => 'psm-local-pickup-address',
            ),
        );

        return $settings;
    }

    // Add the pickup address to the customer order confirmation email
    add_action('woocommerce_email_after_order_table', 'psm_add_pickup_address_to_email', 10, 4);
    function psm_add_pickup_address_to_email($order, $sent_to_admin, $plain_text, $email)
    {
        if ($email->id !== 'customer_completed_order' && $email->id !== 'customer_processing_order') {
            return;
        }

        $items = $order->get_items();
        $pickup_addresses = array();

        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            $shipping_methods = get_post_meta($product_id, '_psm_shipping_methods', true);

            foreach ($shipping_methods as $shipping_method_id) {
                if (substr($shipping_method_id, 0, 12) === 'local_pickup') {
                    $address_key = '_psm_local_pickup_address_' . $shipping_method_id;
                    $address = get_post_meta($product_id, $address_key, true);
                    if (!empty($address) && !in_array($address, $pickup_addresses)) {
                        $pickup_addresses[] = $address;
                    }
                }
            }
        }

        if (!empty($pickup_addresses)) {
            echo $plain_text ? "\n\n" : '<br>';
            echo $plain_text ? __('Pickup Addresses:', 'product-shipping-methods') . "\n\n" : '<h2>' . __('Pickup Addresses:', 'product-shipping-methods') . '</h2>';

            foreach ($pickup_addresses as $address) {
                echo $plain_text ? $address . "\n\n" : '<p>' . $address . '</p>';
            }
        }
    }
}
