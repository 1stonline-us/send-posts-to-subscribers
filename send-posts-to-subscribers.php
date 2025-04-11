<?php
/**
 * Plugin Name: Send Posts to Subscribers
 * Plugin URI: https://notesrss.com/plugins/
 * Description: Collects email subscribers via Gravity Forms and emails new posts using SMTP.
 * Version: 1.6
 * Author: Michael Stuart
 * Author URI: https://notesrss.com/about/
 * Requires at least: 5.6  
 * Tested up to: 6.7 
 * Stable tag: 1.6  
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
    add_options_page('Send Posts to Subscribers', 'Email Subscribers', 'manage_options', 'send-posts-to-subscribers', 'gfs_settings_page');
}

// Add Settings Link to Plugins Page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'gfs_add_settings_link');
function gfs_add_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=send-posts-to-subscribers">Settings</a>';
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

// Secure Subscription Query
add_action('gform_after_submission', 'gfs_save_email', 10, 2);
function gfs_save_email($entry, $form) {
    global $wpdb;
    $form_id = get_option('gfs_form_id', '1');
    $email_field_id = get_option('gfs_email_field_id', '1');
    $table_name = $wpdb->prefix . 'gfs_subscribers';
    
    if (!isset($form['id']) || $form['id'] != $form_id) return;
    
    $email = sanitize_email($entry[$email_field_id]);
    if (!empty($email) && is_email($email)) {
        $wpdb->replace(
            $table_name,
            [ 'email' => sanitize_email($email), 'active' => 1 ],
            ['%s', '%d']
        );
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


// Secure Email Sending on Publish// add_action('publish_post', 'gfs_notify_subscribers_on_publish'); // Disabled to use debounce system
function gfs_notify_subscribers_on_publish($post_ID) {
    global $wpdb;
	
	if (!current_user_can('manage_options')) return;
    
    $post = get_post($post_ID);
    if (!$post) return;
    
    $post_permalink = esc_url(get_permalink($post_ID));
    $post_title = esc_html($post->post_title);
    $post_excerpt = esc_html(wp_trim_words($post->post_content, 30, '...'));
    
    $from_email = esc_html(get_option('gfs_from_email', get_bloginfo('admin_email')));
	$subscribers = $wpdb->get_col($wpdb->prepare("SELECT email FROM {$wpdb->prefix}gfs_subscribers WHERE active = %d", 1));

    if (!empty($subscribers)) {
        $subject = 'New Post: ' . $post_title;
        $message = '<h2>' . $post_title . '</h2>';
        $message .= '<p>' . $post_excerpt . '</p>';
        $message .= '<p><a href="' . $post_permalink . '">Read More</a></p>';
        
        $headers = ['Content-Type: text/html; charset=UTF-8', 'From: ' . $from_email];
        
        foreach ($subscribers as $email) {
            $sanitized_email = sanitize_email($email);
            if (!empty($sanitized_email) && is_email($sanitized_email)) {
                wp_mail($sanitized_email, $subject, $message, $headers);
            }
        }
    }
}

// Restrict Settings Page to Admins
function gfs_settings_page() {
    global $wpdb;

	echo '<h2>Email Queue Status (Last 10 Posts)</h2>';

	$recent_posts = get_posts(array(
		'numberposts' => 10,
		'post_type'   => 'post',
		'post_status' => 'publish',
		'orderby'     => 'post_date',
		'order'       => 'DESC'
	));

	if (!empty($recent_posts)) {
		echo '<ul>';
		foreach ($recent_posts as $post) {
			$post_ID = $post->ID;
			$next_run = wp_next_scheduled('gfs_send_delayed_post_email', array($post_ID));

			if ($next_run) {
				echo '<li>🕒 <strong>' . esc_html(get_the_title($post_ID)) . "</strong> (ID $post_ID) — Email scheduled at: <strong>" . date_i18n('Y-m-d H:i:s', $next_run) . '</strong></li>';
			} else {
				echo '<li>✅ <strong>' . esc_html(get_the_title($post_ID)) . "</strong> (ID $post_ID) — No email scheduled</li>";
			}
		}
		echo '</ul>';
	} else {
		echo '<p>No published posts found.</p>';
	}

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized access', 'send-posts-to-subscribers'));
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Send Posts to Subscribers', 'send-posts-to-subscribers'); ?></h1>
        <form method="post" action="options.php">
            <?php 
                settings_fields('gfs_settings_group'); 
                do_settings_sections('gfs_settings_group'); 
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html__('Subscribe Form ID', 'send-posts-to-subscribers'); ?></th>
                    <td><input type="text" name="gfs_form_id" value="<?php echo esc_attr(get_option('gfs_form_id', '1')); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Subscribe Email Field ID', 'send-posts-to-subscribers'); ?></th>
                    <td><input type="text" name="gfs_email_field_id" value="<?php echo esc_attr(get_option('gfs_email_field_id', '1')); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Unsubscribe Form ID', 'send-posts-to-subscribers'); ?></th>
                    <td><input type="text" name="gfs_unsubscribe_form_id" value="<?php echo esc_attr(get_option('gfs_unsubscribe_form_id', '')); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Unsubscribe Email Field ID', 'send-posts-to-subscribers'); ?></th>
                    <td><input type="text" name="gfs_unsubscribe_email_field_id" value="<?php echo esc_attr(get_option('gfs_unsubscribe_email_field_id', '')); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('From Email Address', 'send-posts-to-subscribers'); ?></th>
                    <td><input type="email" name="gfs_from_email" value="<?php echo esc_attr(get_option('gfs_from_email', get_bloginfo('admin_email'))); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}




// Hook into post save
add_action('save_post', 'gfs_debounce_post_email', 10, 3);

function gfs_debounce_post_email($post_ID, $post, $update) {
    if (wp_is_post_revision($post_ID) || $post->post_status !== 'publish') {
        return;
    }

	if (wp_is_post_revision($post_ID) || $post->post_status !== 'publish') {
		return;
	}

	$transient_key = 'gfs_email_pending_' . $post_ID;

	// Always clear and re-schedule the email
	wp_clear_scheduled_hook('gfs_send_delayed_post_email', array($post_ID));
	wp_schedule_single_event(current_time('timestamp') + 900, 'gfs_send_delayed_post_email', array($post_ID));

	// Reset the transient for the next 15-minute delay
	set_transient($transient_key, true, 900);
}

// Register the actual sending logic
add_action('gfs_send_delayed_post_email', 'gfs_send_email_to_subscribers');

function gfs_send_email_to_subscribers($post_ID) {
    // Original send logic (placeholder)
    $post = get_post($post_ID);
    if ($post && $post->post_status === 'publish') {

    }
}


function gfs_send_post_email_to_all_subscribers($post_ID) {
    global $wpdb;

    $subscribers_table = $wpdb->prefix . 'gfs_subscribers';
    $subscribers = $wpdb->get_results("SELECT email FROM $subscribers_table WHERE active = 1");

    if (empty($subscribers)) return;

    $post = get_post($post_ID);
    if (!$post || $post->post_status !== 'publish') return;

    $subject = 'New Post: ' . $post->post_title;

    $message = '<h2>' . esc_html($post->post_title) . '</h2>';
    $message .= wpautop($post->post_content);
    $message .= '<p><a href="' . esc_url(get_permalink($post_ID)) . '">Read the full post</a></p>';

    $headers = ['Content-Type: text/html; charset=UTF-8'];

    foreach ($subscribers as $subscriber) {
        $sanitized_email = sanitize_email($subscriber->email);
        if (!empty($sanitized_email) && is_email($sanitized_email)) {
            wp_mail($sanitized_email, $subject, $message, $headers);
        }
    }
}

// Manual testing trigger via ?trigger_post_email_test=POST_ID
add_action('admin_init', function () {
    if (current_user_can('manage_options') && isset($_GET['trigger_post_email_test'])) {
        $post_ID = intval($_GET['trigger_post_email_test']);

        wp_die("Test email triggered for post ID: " . $post_ID);
    }
});
