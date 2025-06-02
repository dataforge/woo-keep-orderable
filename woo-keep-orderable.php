<?php
/*
Plugin Name: Woo Keep Orderable
Description: Keeps all products in a selected WooCommerce category always available to order, even if out of stock. Sets stock status to "on backorder" and enables backorders with notification for all products and variations in the category.
Version: 1.0.0
Author: Your Name
License: GPL2
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Add custom admin page under WooCommerce menu
function woo_keep_orderable_add_admin_menu_page() {
    add_submenu_page(
        'woocommerce',
        'Woo Keep Orderable',
        'Woo Keep Orderable',
        'manage_options',
        'woo_keep_orderable',
        'woo_keep_orderable_admin_page'
    );
}
add_action('admin_menu', 'woo_keep_orderable_add_admin_menu_page');

// Helper to (re)schedule or clear cron
function woo_keep_orderable_reschedule_cron($enable_auto_run, $interval) {
    $hook = 'woo_keep_orderable_update_products_hook';
    // Clear existing scheduled event
    $timestamp = wp_next_scheduled($hook);
    if ($timestamp) {
        wp_unschedule_event($timestamp, $hook);
    }
    if ($enable_auto_run) {
        // Register custom schedule
        add_filter('cron_schedules', function($schedules) use ($interval) {
            $schedules["woo_keep_orderable_interval"] = array(
                'interval' => $interval * 60,
                'display' => __('Every ' . $interval . ' minutes')
            );
            return $schedules;
        });
        // Schedule new event
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time(), 'woo_keep_orderable_interval', $hook);
        }
    }
}

// Callback function to display the custom admin page with tabs
function woo_keep_orderable_admin_page() {
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'run_fix';

    // Handle settings form submission
    if ($active_tab === 'settings' && isset($_POST['woo_keep_orderable_settings_nonce']) && wp_verify_nonce($_POST['woo_keep_orderable_settings_nonce'], 'woo_keep_orderable_settings_action')) {
        $enable_auto_run = isset($_POST['enable_auto_run']) ? 1 : 0;
        $auto_run_interval = isset($_POST['auto_run_interval']) ? max(1, intval($_POST['auto_run_interval'])) : 10;
        $target_category = isset($_POST['target_category']) ? sanitize_text_field($_POST['target_category']) : '';
        update_option('woo_keep_orderable_enable_auto_run', $enable_auto_run);
        update_option('woo_keep_orderable_auto_run_interval', $auto_run_interval);
        update_option('woo_keep_orderable_target_category', $target_category);
        // Reschedule or clear cron based on settings
        woo_keep_orderable_reschedule_cron($enable_auto_run, $auto_run_interval);
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
    }
    $enable_auto_run = get_option('woo_keep_orderable_enable_auto_run', 1);
    $auto_run_interval = get_option('woo_keep_orderable_auto_run_interval', 10);
    $target_category = get_option('woo_keep_orderable_target_category', '');

    // Get all product categories
    $categories = get_terms(array(
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
    ));

    ?>
    <div class="wrap">
        <h1>Woo Keep Orderable</h1>
        <h2 class="nav-tab-wrapper">
            <a href="<?php echo admin_url('admin.php?page=woo_keep_orderable&tab=run_fix'); ?>" class="nav-tab <?php echo $active_tab === 'run_fix' ? 'nav-tab-active' : ''; ?>">Run Fix</a>
            <a href="<?php echo admin_url('admin.php?page=woo_keep_orderable&tab=settings'); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
        </h2>

        <?php if ($active_tab === 'settings'): ?>
            <form method="post" action="">
                <?php wp_nonce_field('woo_keep_orderable_settings_action', 'woo_keep_orderable_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Run Automatically</th>
                        <td>
                            <input type="checkbox" name="enable_auto_run" value="1" <?php checked($enable_auto_run, 1); ?> />
                            <label for="enable_auto_run">Enable automatic stock status fix</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Interval (minutes)</th>
                        <td>
                            <input type="number" name="auto_run_interval" min="1" value="<?php echo esc_attr($auto_run_interval); ?>" />
                            <p class="description">How often to run the stock status fix (minimum 1 minute).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Target Category</th>
                        <td>
                            <select name="target_category" required>
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($target_category, $cat->term_id); ?>>
                                        <?php echo esc_html($cat->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Choose the product category to target for stock status fix.</p>
                        </td>
                    </tr>
                </table>
                <input type="submit" class="button button-primary" value="Save Settings" />
            </form>
        <?php else: ?>
            <div style="max-width:700px;">
                <p>
                    <strong>What does this tool do?</strong>
                </p>
                <ul style="list-style:disc; margin-left:20px;">
                    <li>Keeps all products in the selected category always available to order, even if out of stock.</li>
                    <li>Sets stock status to <strong>on backorder</strong> for products and variations with zero or negative stock.</li>
                    <li>Enables backorders with notification for all products and variations in the category.</li>
                    <li>Ensures "manage stock" is enabled for all variations, so the stock status and backorder logic can be applied.</li>
                    <li>Works for both simple and variable products.</li>
                    <li>Can be run manually here, or automatically at your chosen interval (see the Settings tab).</li>
                </ul>
            </div>
            <form method="post" action="">
                <?php wp_nonce_field('woo_keep_orderable_run_action', 'woo_keep_orderable_run_nonce'); ?>
                <input type="submit" name="woo_keep_orderable_run_button" class="button button-primary" value="Fix Stock Status">
            </form>
        <?php endif; ?>
    </div>
<?php
}

// Action to run when the button is clicked
function woo_keep_orderable_run_fix() {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!isset($_POST['woo_keep_orderable_run_nonce']) || !wp_verify_nonce($_POST['woo_keep_orderable_run_nonce'], 'woo_keep_orderable_run_action')) {
        return;
    }
    woo_keep_orderable_update_products();
}
add_action('admin_post_woo_keep_orderable_run', 'woo_keep_orderable_run_fix');

// Display success message and redirect
add_action('admin_notices', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'woo_keep_orderable' && isset($_GET['success'])) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p>Stock status updated successfully.</p>
        </div>
        <?php
    }
});

// Redirect back to the admin page after running the function
add_action('admin_init', function() {
    if (isset($_POST['woo_keep_orderable_run_button'])) {
        wp_redirect(admin_url('admin.php?page=woo_keep_orderable&success=1'));
        exit;
    }
});

// On load, ensure cron is scheduled or cleared based on settings
add_action('init', function() {
    $enable_auto_run = get_option('woo_keep_orderable_enable_auto_run', 1);
    $auto_run_interval = get_option('woo_keep_orderable_auto_run_interval', 10);
    woo_keep_orderable_reschedule_cron($enable_auto_run, $auto_run_interval);
});

// Use the selected category from settings for stock status fix
function woo_keep_orderable_update_products() {
    $category_id = get_option('woo_keep_orderable_target_category', '');
    if (!$category_id) {
        return; // No category selected
    }
    $category = get_term_by('id', $category_id, 'product_cat');
    if ($category) {
        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'tax_query'      => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $category->term_id,
                ),
            ),
        );

        $products = get_posts($args);

        foreach ($products as $product_post) {
            $product = wc_get_product($product_post->ID);
            woo_keep_orderable_update_stock_status_and_backorders($product);
        }
        wp_reset_postdata();
    }
}
// Schedule the update function to be called by the scheduled event
add_action('woo_keep_orderable_update_products_hook', 'woo_keep_orderable_update_products');

// Main function to update products stock status
function woo_keep_orderable_update_stock_status_and_backorders($product) {
    if ($product->is_type('variable')) {
        $variations = $product->get_children();
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if ($variation && !$variation->get_manage_stock()) {
                $variation->set_manage_stock(true);
                $variation->save();
            }
            woo_keep_orderable_apply_stock_and_backorders_logic($variation);
        }
        return;
    }
    if (!$product->get_manage_stock()) {
        $product->set_manage_stock(true);
        $product->save();
    }
    woo_keep_orderable_apply_stock_and_backorders_logic($product);
}

function woo_keep_orderable_apply_stock_and_backorders_logic($product) {
    $needs_update = false;
    $stock_quantity = $product->get_stock_quantity();
    $stock_status = $product->get_stock_status();
    $backorders = $product->get_backorders();

    if ($stock_quantity <= 0 && $stock_status !== 'onbackorder') {
        $product->set_stock_status('onbackorder');
        $needs_update = true;
    }

    if ($backorders !== 'notify') {
        $product->set_backorders('notify');
        $needs_update = true;
        error_log('Woo Keep Orderable: Changed backorders setting to Notify for product or variation ID ' . $product->get_id());
    }

    if ($needs_update) {
        $product->save();
        error_log('Woo Keep Orderable: Updated product or variation ID ' . $product->get_id() . ' to onbackorder with notify.');
    }
}

// Plugin activation: schedule cron if enabled
function woo_keep_orderable_activate() {
    $enable_auto_run = get_option('woo_keep_orderable_enable_auto_run', 1);
    $auto_run_interval = get_option('woo_keep_orderable_auto_run_interval', 10);
    woo_keep_orderable_reschedule_cron($enable_auto_run, $auto_run_interval);
}
register_activation_hook(__FILE__, 'woo_keep_orderable_activate');

// Plugin deactivation: clear scheduled cron
function woo_keep_orderable_deactivate() {
    $hook = 'woo_keep_orderable_update_products_hook';
    $timestamp = wp_next_scheduled($hook);
    if ($timestamp) {
        wp_unschedule_event($timestamp, $hook);
    }
}
register_deactivation_hook(__FILE__, 'woo_keep_orderable_deactivate');
