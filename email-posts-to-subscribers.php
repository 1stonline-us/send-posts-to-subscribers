<?php
/**
 * Plugin Name: Email Posts to Subscribers
 * Plugin URI: https://notesrss.com/plugins/
 * Description: Collects email subscribers via Gravity Forms and emails new posts using SMTP.
 * Version: 1.2
 * Author: Michael Stuart
 * Author URI: https://notesrss.com/about/
 * Requires at least: 5.6  
 * Tested up to: 6.5  
 * Stable tag: 1.2  
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly;

// Activation Hook to Create Table
register_activation_hook(__FILE__, 'gfs_create_subscriber_table');
function gfs_create_subscriber_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'gfs_subscribers';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id INT NOT NULL AUTO_INCREMENT,
        email VARCHAR(255) NOT NULL UNIQUE,
        subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        active TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Admin Menu for Plugin Settings
add_action('admin_menu', 'gfs_add_admin_menu');
function gfs_add_admin_menu() {
    add_options_page('Email Posts to Subscribers', 'Email Subscribers', 'manage_options', 'email-posts-to-subscribers', 'gfs_settings_page');
}

// Add Settings Link to Plugins Page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'gfs_add_settings_link');
function gfs_add_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=email-posts-to-subscribers">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Register Settings
add_action('admin_init', 'gfs_register_settings');
function gfs_register_settings() {
    register_setting('gfs_settings_group', 'gfs_form_id');
    register_setting('gfs_settings_group', 'gfs_email_field_id');
    register_setting('gfs_settings_group', 'gfs_unsubscribe_form_id');
    register_setting('gfs_settings_group', 'gfs_unsubscribe_email_field_id');
    register_setting('gfs_settings_group', 'gfs_from_email');
}

// Admin Settings Page UI
function gfs_settings_page() {
    ?>
    <div class="wrap">
        <h1>Email Posts to Subscribers</h1>
        <form method="post" action="options.php">
            <?php settings_fields('gfs_settings_group'); ?>
            <?php do_settings_sections('gfs_settings_group'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Subscribe Form ID</th>
                    <td><input type="text" name="gfs_form_id" value="<?php echo esc_attr(get_option('gfs_form_id', '1')); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">Subscribe Email Field ID</th>
                    <td><input type="text" name="gfs_email_field_id" value="<?php echo esc_attr(get_option('gfs_email_field_id', '1')); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">Unsubscribe Form ID</th>
                    <td><input type="text" name="gfs_unsubscribe_form_id" value="<?php echo esc_attr(get_option('gfs_unsubscribe_form_id', '2')); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">Unsubscribe Email Field ID</th>
                    <td><input type="text" name="gfs_unsubscribe_email_field_id" value="<?php echo esc_attr(get_option('gfs_unsubscribe_email_field_id', '2')); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">From Email Address</th>
                    <td><input type="email" name="gfs_from_email" value="<?php echo esc_attr(get_option('gfs_from_email', get_bloginfo('admin_email'))); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
?>
