<?php
/**
 * Plugin Name: Email Posts to Subscribers
 * Plugin URI: https://notesrss.com/plugins/
 * Description: Collects email subscribers via Gravity Forms and emails new posts using SMTP.
 * Version: 1.3
 * Author: Michael Stuart
 * Author URI: https://notesrss.com/about/
 * Requires at least: 5.6  
 * Tested up to: 6.5  
 * Stable tag: 1.3  
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

// Capture Subscriptions via Gravity Forms
add_action('gform_after_submission', 'gfs_save_email', 10, 2);
function gfs_save_email($entry, $form) {
    global $wpdb;
    $form_id = get_option('gfs_form_id', '1');
    $email_field_id = get_option('gfs_email_field_id', '1');
    $table_name = $wpdb->prefix . 'gfs_subscribers';
    
    if (!isset($form['id']) || $form['id'] != $form_id) return;
    
    $email = sanitize_email($entry[$email_field_id]);
    if (!empty($email) && is_email($email)) {
        $wpdb->replace($table_name, [ 'email' => $email, 'active' => 1 ], ['%s', '%d']);
    }
}

// Handle Unsubscriptions (Only If Enabled)
add_action('gform_after_submission', 'gfs_unsubscribe_email', 10, 2);
function gfs_unsubscribe_email($entry, $form) {
    global $wpdb;

    // Get settings
    $form_id = get_option('gfs_unsubscribe_form_id', '');
    $email_field_id = get_option('gfs_unsubscribe_email_field_id', '');
    $table_name = $wpdb->prefix . 'gfs_subscribers';

    // If unsubscribe form is not set, do nothing
    if (empty($form_id) || empty($email_field_id)) {
        return;
    }

    // Ensure the correct form is being processed
    if (!isset($form['id']) || $form['id'] != $form_id) {
        return;
    }

    // Validate email
    $email = sanitize_email($entry[$email_field_id]);
    if (!empty($email) && is_email($email)) {
        $wpdb->update($table_name, ['active' => 0], ['email' => $email], ['%d'], ['%s']);
    }
}


add_action('publish_post', 'gfs_notify_subscribers_on_publish');
function gfs_notify_subscribers_on_publish($post_ID) {
    global $wpdb;

    // Ensure we get the correct post details
    $post = get_post($post_ID);
    if (!$post) return;

    // Get the correct permalink, title, and excerpt
    $post_permalink = get_permalink($post_ID);
    $post_title = esc_html($post->post_title);
    $post_excerpt = !empty($post->post_excerpt) ? esc_html($post->post_excerpt) : wp_trim_words($post->post_content, 30, '...');

    // Get subscribers
    $table_name = $wpdb->prefix . 'gfs_subscribers';
    $from_email = get_option('gfs_from_email', get_bloginfo('admin_email'));
    $subscribers = $wpdb->get_col("SELECT email FROM $table_name WHERE active = 1");

    if (!empty($subscribers)) {
        $subject = 'New Post: ' . $post_title;
        $message = '<h2>' . $post_title . '</h2>';
        $message .= '<p>' . $post_excerpt . '</p>';
        $message .= '<p><a href="' . esc_url($post_permalink) . '">Read More</a></p>';

        $headers = ['Content-Type: text/html; charset=UTF-8', 'From: ' . esc_html($from_email)];

        foreach ($subscribers as $email) {
            if (!wp_mail($email, $subject, $message, $headers)) {
                error_log("Failed to send email to: " . $email);
            }
        }
    }
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
