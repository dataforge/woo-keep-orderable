<?php
/**
 * Plugin Name:       Woo Keep Orderable
 * Plugin URI:        https://github.com/dataforge/woo-keep-orderable
 * Description:       Keeps all products in a selected WooCommerce category always available to order, even if out of stock. Sets stock status to "on backorder" and enables backorders with notification for all products and variations in the category.
 * Version:           1.10
 * Author:            Dataforge
 * License:           GPL2
 * Text Domain:       woo-keep-orderable
 * GitHub Plugin URI: https://github.com/dataforge/woo-keep-orderable
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
        $target_categories = isset($_POST['target_categories']) && is_array($_POST['target_categories']) ? array_map('intval', $_POST['target_categories']) : array();
        update_option('woo_keep_orderable_enable_auto_run', $enable_auto_run);
        update_option('woo_keep_orderable_auto_run_interval', $auto_run_interval);
        update_option('woo_keep_orderable_target_categories', $target_categories);
        // Reschedule or clear cron based on settings
        woo_keep_orderable_reschedule_cron($enable_auto_run, $auto_run_interval);
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
    }
    
    // Migrate old single category setting to new multi-category format
    woo_keep_orderable_migrate_single_category();
    
    $enable_auto_run = get_option('woo_keep_orderable_enable_auto_run', 1);
    $auto_run_interval = get_option('woo_keep_orderable_auto_run_interval', 10);
    $target_categories = get_option('woo_keep_orderable_target_categories', array());

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
            <?php
            // Handle "Check for Plugin Updates" button
            if (isset($_POST['woo_keep_orderable_check_update']) && check_admin_referer('woo_keep_orderable_settings_nonce', 'woo_keep_orderable_settings_nonce')) {
                // Simulate the cron event for plugin update check
                do_action('wp_update_plugins');
                if (function_exists('wp_clean_plugins_cache')) {
                    wp_clean_plugins_cache(true);
                }
                // Remove the update_plugins transient to force a check
                delete_site_transient('update_plugins');
                // Call the update check directly as well
                if (function_exists('wp_update_plugins')) {
                    wp_update_plugins();
                }
                // Get update info
                $plugin_file = plugin_basename(__FILE__);
                $update_plugins = get_site_transient('update_plugins');
                $update_msg = '';
                if (isset($update_plugins->response) && isset($update_plugins->response[$plugin_file])) {
                    $new_version = $update_plugins->response[$plugin_file]->new_version;
                    $update_msg = '<div class="updated"><p>Update available: version ' . esc_html($new_version) . '.</p></div>';
                } else {
                    $update_msg = '<div class="updated"><p>No update available for this plugin.</p></div>';
                }
                echo $update_msg;
            }
            ?>
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
                        <th scope="row">Target Categories</th>
                        <td>
                            <div id="category-checkboxes" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                                <?php woo_keep_orderable_render_category_checkboxes($categories, $target_categories); ?>
                            </div>
                            <p class="description">Select one or more product categories to target for stock status fix. Checking a parent category will automatically select all subcategories.</p>
                        </td>
                    </tr>
                </table>
                <input type="submit" class="button button-primary" value="Save Settings" />
            </form>
            <form method="post" action="" style="margin-top:2em;">
                <?php wp_nonce_field('woo_keep_orderable_settings_nonce', 'woo_keep_orderable_settings_nonce'); ?>
                <input type="hidden" name="woo_keep_orderable_check_update" value="1">
                <?php submit_button('Check for Plugin Updates', 'secondary'); ?>
            </form>
        <?php else: ?>
            <div style="max-width:700px;">
                <p>
                    <strong>What does this tool do?</strong>
                </p>
                <ul style="list-style:disc; margin-left:20px;">
                    <li>Keeps all products in the selected categories always available to order, even if out of stock.</li>
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

// Use the selected categories from settings for stock status fix
function woo_keep_orderable_update_products() {
    $category_ids = get_option('woo_keep_orderable_target_categories', array());
    if (empty($category_ids)) {
        return; // No categories selected
    }
    
    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'tax_query'      => array(
            array(
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $category_ids,
                'operator' => 'IN',
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

// Migration function to convert old single category to new multi-category format
function woo_keep_orderable_migrate_single_category() {
    $old_category = get_option('woo_keep_orderable_target_category', '');
    $new_categories = get_option('woo_keep_orderable_target_categories', null);
    
    // Only migrate if old setting exists and new setting doesn't exist yet
    if (!empty($old_category) && $new_categories === null) {
        update_option('woo_keep_orderable_target_categories', array(intval($old_category)));
        delete_option('woo_keep_orderable_target_category'); // Clean up old option
    }
}

// Get categories organized hierarchically
function woo_keep_orderable_get_categories_hierarchical($categories) {
    $hierarchy = array();
    $all_categories = array();
    
    // Index all categories by ID and initialize children array
    foreach ($categories as $category) {
        $category->children = array();
        $all_categories[$category->term_id] = $category;
    }
    
    // Build hierarchy by assigning children to parents
    foreach ($categories as $category) {
        if ($category->parent == 0) {
            // Top-level category
            $hierarchy[$category->term_id] = $category;
        } else {
            // Child category - assign to parent if parent exists
            if (isset($all_categories[$category->parent])) {
                $all_categories[$category->parent]->children[] = $category;
            } else {
                // Parent not found, treat as top-level
                $hierarchy[$category->term_id] = $category;
            }
        }
    }
    
    return $hierarchy;
}

// Render category checkboxes with hierarchy
function woo_keep_orderable_render_category_checkboxes($categories, $selected_categories = array()) {
    if (empty($categories)) {
        echo '<p>No categories found.</p>';
        return;
    }
    
    $hierarchy = woo_keep_orderable_get_categories_hierarchical($categories);
    
    // Add JavaScript for parent/child checkbox behavior
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Handle parent checkbox changes
        $('.parent-category-checkbox').change(function() {
            var isChecked = $(this).is(':checked');
            var parentId = $(this).data('parent-id');
            
            // Check/uncheck all children
            $('.child-category-checkbox[data-parent="' + parentId + '"]').prop('checked', isChecked);
        });
        
        // Handle child checkbox changes
        $('.child-category-checkbox').change(function() {
            var parentId = $(this).data('parent');
            var allChildren = $('.child-category-checkbox[data-parent="' + parentId + '"]');
            var checkedChildren = $('.child-category-checkbox[data-parent="' + parentId + '"]:checked');
            
            // If all children are checked, check parent
            // If no children are checked, uncheck parent
            // If some children are checked, leave parent as user set it
            if (checkedChildren.length === allChildren.length) {
                $('.parent-category-checkbox[data-parent-id="' + parentId + '"]').prop('checked', true);
            } else if (checkedChildren.length === 0) {
                $('.parent-category-checkbox[data-parent-id="' + parentId + '"]').prop('checked', false);
            }
        });
    });
    </script>
    <?php
    
    foreach ($hierarchy as $parent) {
        $is_checked = in_array($parent->term_id, $selected_categories);
        $has_children = !empty($parent->children);
        
        echo '<div style="margin-bottom: 5px;">';
        echo '<label style="font-weight: bold;">';
        echo '<input type="checkbox" name="target_categories[]" value="' . esc_attr($parent->term_id) . '"';
        echo $is_checked ? ' checked' : '';
        echo $has_children ? ' class="parent-category-checkbox" data-parent-id="' . esc_attr($parent->term_id) . '"' : '';
        echo '> ';
        echo esc_html($parent->name);
        echo '</label>';
        echo '</div>';
        
        // Render children
        if ($has_children) {
            woo_keep_orderable_render_child_categories($parent->children, $selected_categories, $parent->term_id, 1);
        }
    }
}

// Recursively render child categories
function woo_keep_orderable_render_child_categories($children, $selected_categories, $parent_id, $level = 1) {
    $indent = $level * 20; // 20px per level
    
    foreach ($children as $child) {
        $is_checked = in_array($child->term_id, $selected_categories);
        
        echo '<div style="margin-left: ' . $indent . 'px; margin-bottom: 3px;">';
        echo '<label>';
        echo '<input type="checkbox" name="target_categories[]" value="' . esc_attr($child->term_id) . '"';
        echo $is_checked ? ' checked' : '';
        echo ' class="child-category-checkbox" data-parent="' . esc_attr($parent_id) . '"';
        echo '> ';
        echo esc_html($child->name);
        echo '</label>';
        echo '</div>';
        
        // Handle nested children (subcategories of subcategories)
        if (!empty($child->children)) {
            woo_keep_orderable_render_child_categories($child->children, $selected_categories, $parent_id, $level + 1);
        }
    }
}
