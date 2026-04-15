<?php
/**
 * Plugin Name: EYWorks Enquiry Form
 * Plugin URI: https://github.com/twotenstudio/eyworks-enquiry-form
 * Description: Customisable enquiry form for EYWorks-powered nurseries with API integration, GTM tracking, local storage, email notifications, and admin dashboard.
 * Version: 2.10.0
 * Author: Two Ten Studio
 * Author URI: https://twotenstudio.co.uk
 * License: GPL-2.0+
 * Text Domain: eyworks-enquiry-form
 */

if (!defined('ABSPATH')) exit;

define('EYWORKS_PLUGIN_VERSION', '2.10.0');
define('EYWORKS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EYWORKS_PLUGIN_URL', plugin_dir_url(__FILE__));


// ─── SETTINGS HELPERS ────────────────────────────────────────────
// wp-config.php constants take priority, then Settings page values

function eyworks_get_setting($key, $default = '') {
    $settings = get_option('eyworks_settings', []);
    return !empty($settings[$key]) ? $settings[$key] : $default;
}

function eyworks_api_token() {
    if (defined('EYWORKS_API_TOKEN') && EYWORKS_API_TOKEN) return EYWORKS_API_TOKEN;
    return eyworks_get_setting('api_token');
}

function eyworks_api_base() {
    if (defined('EYWORKS_API_BASE') && EYWORKS_API_BASE) return EYWORKS_API_BASE;
    $subdomain = eyworks_get_setting('subdomain');
    if (empty($subdomain)) return '';
    // Strip protocol and trailing slashes if user pasted full URL
    $subdomain = preg_replace('#^https?://#', '', $subdomain);
    $subdomain = rtrim($subdomain, '/');
    // If it already contains eylog.co.uk, use as-is
    if (strpos($subdomain, 'eylog.co.uk') !== false) {
        return 'https://' . $subdomain . '/eyMan/index.php/api';
    }
    return 'https://' . $subdomain . '.eylog.co.uk/eyMan/index.php/api';
}

function eyworks_notify_email() {
    if (defined('EYWORKS_NOTIFY_EMAIL') && EYWORKS_NOTIFY_EMAIL) return EYWORKS_NOTIFY_EMAIL;
    $email = eyworks_get_setting('notify_email');
    return !empty($email) ? $email : get_option('admin_email');
}

function eyworks_is_configured() {
    return !empty(eyworks_api_token()) && !empty(eyworks_api_base());
}

function eyworks_log_submissions() {
    return eyworks_get_setting('log_submissions', '1') === '1';
}

function eyworks_required_fields() {
    $defaults = ['child_first_name', 'child_last_name', 'parent_first_name', 'email', 'phone', 'agree_terms'];
    $settings = get_option('eyworks_settings', []);
    if (isset($settings['required_fields']) && is_array($settings['required_fields'])) {
        return $settings['required_fields'];
    }
    return $defaults;
}

function eyworks_required_fields_map() {
    $map = [
        'child_first_name' => ['js_key' => 'first_name',           'element_id' => 'ew-child-first-name'],
        'child_last_name'  => ['js_key' => 'last_name',            'element_id' => 'ew-child-last-name'],
        'child_dob'        => ['js_key' => 'dob',                  'element_id' => 'ew-child-dob'],
        'child_gender'     => ['js_key' => 'gender',               'element_id' => 'ew-child-gender'],
        'parent_first_name'=> ['js_key' => 'parent_first_name',    'element_id' => 'ew-parent-first-name'],
        'parent_last_name' => ['js_key' => 'parent_last_name',     'element_id' => 'ew-parent-last-name'],
        'email'            => ['js_key' => 'email',                'element_id' => 'ew-parent-email'],
        'phone'            => ['js_key' => 'phone',                'element_id' => 'ew-phone'],
        'postcode'         => ['js_key' => 'postcode',             'element_id' => 'ew-postcode'],
        'start_date'       => ['js_key' => 'preffered_start_date', 'element_id' => 'ew-start-date'],
        'source'           => ['js_key' => 'source',               'element_id' => 'ew-source'],
        'agree_terms'      => ['js_key' => 'agree_terms',          'element_id' => 'ew-agree-terms'],
    ];
    $required = eyworks_required_fields();
    $js_map = [];
    foreach ($required as $key) {
        if (isset($map[$key])) {
            $js_map[$map[$key]['js_key']] = $map[$key]['element_id'];
        }
    }
    return $js_map;
}


// ─── DATABASE TABLE ──────────────────────────────────────────────
register_activation_hook(__FILE__, 'eyworks_create_table');

function eyworks_create_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'eyworks_enquiries';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        child_first_name varchar(60) NOT NULL DEFAULT '',
        child_last_name varchar(60) NOT NULL DEFAULT '',
        child_dob date DEFAULT NULL,
        child_gender varchar(20) DEFAULT '',
        parent_first_name varchar(60) NOT NULL DEFAULT '',
        parent_last_name varchar(60) DEFAULT '',
        parent_email varchar(100) NOT NULL DEFAULT '',
        phone varchar(45) NOT NULL DEFAULT '',
        postcode varchar(20) DEFAULT '',
        start_date date DEFAULT NULL,
        source varchar(100) DEFAULT '',
        utm_source varchar(200) DEFAULT '',
        utm_medium varchar(200) DEFAULT '',
        utm_campaign varchar(200) DEFAULT '',
        utm_content varchar(200) DEFAULT '',
        utm_term varchar(200) DEFAULT '',
        eyworks_status varchar(20) NOT NULL DEFAULT 'sent',
        eyworks_ref varchar(50) DEFAULT '',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// Auto-create table (no deactivation needed)
add_action('admin_init', function () {
    if (get_option('eyworks_db_version') !== EYWORKS_PLUGIN_VERSION) {
        eyworks_create_table();
        update_option('eyworks_db_version', EYWORKS_PLUGIN_VERSION);
    }
});


// ─── SETTINGS PAGE ──────────────────────────────────────────────
add_action('admin_menu', function () {
    // Enquiries dashboard
    add_menu_page(
        'Tour Enquiries',
        'Tour Enquiries',
        'manage_options',
        'eyworks-enquiries',
        'eyworks_admin_page',
        'dashicons-clipboard',
        26
    );

    // Settings submenu
    add_submenu_page(
        'eyworks-enquiries',
        'EYWorks Settings',
        'Settings',
        'manage_options',
        'eyworks-settings',
        'eyworks_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('eyworks_settings_group', 'eyworks_settings', [
        'sanitize_callback' => 'eyworks_sanitize_settings',
    ]);

    // Connection section
    add_settings_section(
        'eyworks_connection',
        'EYWorks Connection',
        function () {
            echo '<p>Enter your EYWorks API credentials. You can find these in your EYWorks dashboard under <strong>Settings → Access Token → Enquiries</strong>.</p>';
        },
        'eyworks-settings'
    );

    add_settings_field('subdomain', 'EYWorks Subdomain', function () {
        $val = eyworks_get_setting('subdomain');
        $disabled = defined('EYWORKS_API_BASE') ? ' disabled' : '';
        echo '<input type="text" name="eyworks_settings[subdomain]" value="' . esc_attr($val) . '" class="regular-text" placeholder="e.g. myNursery"' . $disabled . '>';
        echo '<p class="description">Your EYWorks subdomain — the part before <code>.eylog.co.uk</code>. For example, if your EYWorks URL is <code>mynursery.eylog.co.uk</code>, enter <strong>mynursery</strong>.</p>';
        if (defined('EYWORKS_API_BASE')) {
            echo '<p class="description" style="color:#d63638;">Currently overridden by <code>EYWORKS_API_BASE</code> in wp-config.php</p>';
        }
    }, 'eyworks-settings', 'eyworks_connection');

    add_settings_field('api_token', 'API Token', function () {
        $val = eyworks_get_setting('api_token');
        $disabled = defined('EYWORKS_API_TOKEN') ? ' disabled' : '';
        $display_val = $disabled ? '••••••••••••••••••••' : esc_attr($val);
        echo '<input type="' . ($disabled ? 'text' : 'password') . '" name="eyworks_settings[api_token]" value="' . $display_val . '" class="large-text" placeholder="Paste your Enquiries API token here"' . $disabled . '>';
        echo '<p class="description">The Enquiries access token from your EYWorks dashboard.</p>';
        if (defined('EYWORKS_API_TOKEN')) {
            echo '<p class="description" style="color:#d63638;">Currently overridden by <code>EYWORKS_API_TOKEN</code> in wp-config.php</p>';
        }
    }, 'eyworks-settings', 'eyworks_connection');

    // Notification section
    add_settings_section(
        'eyworks_notifications',
        'Notifications',
        function () {
            echo '<p>Configure email notifications for new enquiries.</p>';
        },
        'eyworks-settings'
    );

    add_settings_field('notify_email', 'Notification Email', function () {
        $val = eyworks_get_setting('notify_email', get_option('admin_email'));
        $disabled = defined('EYWORKS_NOTIFY_EMAIL') ? ' disabled' : '';
        echo '<input type="email" name="eyworks_settings[notify_email]" value="' . esc_attr($val) . '" class="regular-text"' . $disabled . '>';
        echo '<p class="description">Email address to receive new enquiry notifications. Defaults to the site admin email.</p>';
        if (defined('EYWORKS_NOTIFY_EMAIL')) {
            echo '<p class="description" style="color:#d63638;">Currently overridden by <code>EYWORKS_NOTIFY_EMAIL</code> in wp-config.php</p>';
        }
    }, 'eyworks-settings', 'eyworks_notifications');

    // Data storage section
    add_settings_section(
        'eyworks_storage',
        'Data Storage',
        function () {
            echo '<p>Control whether enquiry submissions are saved to the WordPress database. Submissions are always sent to EYWorks regardless of this setting.</p>';
        },
        'eyworks-settings'
    );

    add_settings_field('log_submissions', 'Log Submissions Locally', function () {
        $val = eyworks_get_setting('log_submissions', '1');
        echo '<label><input type="checkbox" name="eyworks_settings[log_submissions]" value="1"' . checked($val, '1', false) . '> Save enquiry submissions to the WordPress database</label>';
        echo '<p class="description">When enabled, submissions appear in the Tour Enquiries dashboard and can be exported as CSV. When disabled, enquiries are still sent to EYWorks and email notifications still fire.</p>';
    }, 'eyworks-settings', 'eyworks_storage');

    // Required Fields section
    add_settings_section(
        'eyworks_required_fields',
        'Required Fields',
        function () {
            echo '<p>Choose which fields are compulsory on the enquiry form. Checked fields must be filled in before the form can be submitted.</p>';
        },
        'eyworks-settings'
    );

    add_settings_field('required_fields', 'Compulsory Fields', function () {
        $required = eyworks_required_fields();
        $all_fields = [
            'child_first_name' => 'Child First Name',
            'child_last_name'  => 'Child Last Name',
            'child_dob'        => 'Child Date of Birth',
            'child_gender'     => 'Child Gender',
            'parent_first_name'=> 'Parent/Guardian First Name',
            'parent_last_name' => 'Parent/Guardian Last Name',
            'email'            => 'Email',
            'phone'            => 'Phone',
            'postcode'         => 'Postal Code',
            'start_date'       => 'Preferred Start Date',
            'source'           => 'How did you hear about us?',
            'agree_terms'      => 'Consent Checkbox',
        ];
        foreach ($all_fields as $key => $label) {
            $checked = in_array($key, $required) ? ' checked' : '';
            echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="eyworks_settings[required_fields][]" value="' . esc_attr($key) . '"' . $checked . '> ' . esc_html($label) . '</label>';
        }
        echo '<p class="description">Select which fields visitors must complete before submitting the form.</p>';
    }, 'eyworks-settings', 'eyworks_required_fields');

    // Custom CSS section
    add_settings_section(
        'eyworks_appearance',
        'Appearance',
        function () {
            echo '<p>Customise the look and feel of the enquiry form.</p>';
        },
        'eyworks-settings'
    );

    add_settings_field('custom_css', 'Custom CSS', function () {
        $val = eyworks_get_setting('custom_css');
        echo '<textarea name="eyworks_settings[custom_css]" rows="10" class="large-text code" placeholder="/* e.g. change the submit button colour */&#10;.eyworks-submit { background: #333; }">' . esc_textarea($val) . '</textarea>';
        echo '<p class="description">Add custom CSS to override the default form styles. These styles are scoped to the <code>.eyworks-form-wrapper</code> container.</p>';
    }, 'eyworks-settings', 'eyworks_appearance');
});

function eyworks_sanitize_settings($input) {
    $sanitized = [];
    $sanitized['subdomain']    = sanitize_text_field($input['subdomain'] ?? '');
    $sanitized['api_token']    = sanitize_text_field($input['api_token'] ?? '');
    $sanitized['notify_email'] = sanitize_email($input['notify_email'] ?? '');
    $sanitized['custom_css']        = wp_strip_all_tags($input['custom_css'] ?? '');
    $sanitized['log_submissions']   = !empty($input['log_submissions']) ? '1' : '0';

    // Required fields
    $valid_fields = ['child_first_name', 'child_last_name', 'child_dob', 'child_gender',
                     'parent_first_name', 'parent_last_name', 'email', 'phone',
                     'postcode', 'start_date', 'source', 'agree_terms'];
    $sanitized['required_fields'] = [];
    if (!empty($input['required_fields']) && is_array($input['required_fields'])) {
        foreach ($input['required_fields'] as $field) {
            if (in_array($field, $valid_fields, true)) {
                $sanitized['required_fields'][] = $field;
            }
        }
    }

    // Clear metadata cache when settings change (so new nursery/source data loads)
    delete_transient('eyworks_enquiry_metadata');

    return $sanitized;
}

function eyworks_settings_page() {
    ?>
    <div class="wrap">
        <h1>EYWorks Settings</h1>

        <?php
        // Connection status
        if (eyworks_is_configured()) {
            $meta = eyworks_get_metadata();
            if ($meta) {
                $nursery_name = $meta['nursery'][0]['name'] ?? 'Unknown';
                echo '<div class="notice notice-success"><p><strong>&#10003; Connected to EYWorks</strong> — Nursery: ' . esc_html($nursery_name) . '</p></div>';
            } else {
                echo '<div class="notice notice-warning"><p><strong>&#9888; Connection issue</strong> — Credentials are set but could not fetch data from EYWorks. Please check your subdomain and API token.</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p><strong>&#10007; Not connected</strong> — Please enter your EYWorks subdomain and API token below.</p></div>';
        }
        ?>

        <form method="post" action="options.php">
            <?php
            settings_fields('eyworks_settings_group');
            do_settings_sections('eyworks-settings');
            submit_button('Save Settings');
            ?>
        </form>

        <hr>
        <h2>Shortcode</h2>
        <p>Add the enquiry form to any page or post using this shortcode:</p>
        <p><code>[eyworks_enquiry_form]</code></p>
        <p>The form fields (sources, nurseries, sessions) are automatically loaded from your EYWorks account.</p>

        <hr>
        <h2>GTM Tracking</h2>
        <p>The form fires three <code>dataLayer</code> events for Google Tag Manager:</p>
        <table class="widefat" style="max-width:600px;">
            <thead><tr><th>Event</th><th>When</th></tr></thead>
            <tbody>
                <tr><td><code>tour_booking_attempted</code></td><td>User clicks Submit</td></tr>
                <tr><td><code>tour_booking_submitted</code></td><td>Enquiry saved successfully</td></tr>
                <tr><td><code>tour_booking_error</code></td><td>Submission failed</td></tr>
            </tbody>
        </table>
        <p style="margin-top:10px;">UTM parameters from the page URL are automatically captured and sent to EYWorks.</p>

        <?php if (defined('EYWORKS_API_TOKEN') || defined('EYWORKS_API_BASE') || defined('EYWORKS_NOTIFY_EMAIL')): ?>
        <hr>
        <h2>wp-config.php Overrides Active</h2>
        <p>Some settings are currently defined in <code>wp-config.php</code> and cannot be changed from this screen. Remove the constants from wp-config.php to manage them here instead.</p>
        <?php endif; ?>
    </div>
    <?php
}


// ─── ENQUEUE STYLES & SCRIPTS ────────────────────────────────────
add_action('wp_enqueue_scripts', function () {
    wp_register_style('eyworks-form-css', EYWORKS_PLUGIN_URL . 'eyworks-form.css', [], EYWORKS_PLUGIN_VERSION);
    wp_register_script('eyworks-form-js', EYWORKS_PLUGIN_URL . 'eyworks-form.js', [], EYWORKS_PLUGIN_VERSION, true);
    $required_map = eyworks_required_fields_map();
    wp_localize_script('eyworks-form-js', 'eyworksForm', [
        'ajaxUrl'     => admin_url('admin-ajax.php'),
        'nonce'       => wp_create_nonce('eyworks_enquiry_nonce'),
        'requiredMap' => !empty($required_map) ? $required_map : new stdClass(),
    ]);
});


// ─── HELPER: Get metadata from EYWorks (cached 12h) ─────────────
function eyworks_get_metadata() {
    if (!eyworks_is_configured()) return null;

    $cached = get_transient('eyworks_enquiry_metadata');
    if ($cached) return $cached;

    $response = wp_remote_get(eyworks_api_base() . '/enquirySettings', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . eyworks_api_token(),
            'Content-Type'  => 'application/json',
        ],
    ]);

    if (is_wp_error($response)) {
        error_log('[EYWorks] Metadata fetch error: ' . $response->get_error_message());
        return null;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($body['status']) && !empty($body['data'])) {
        set_transient('eyworks_enquiry_metadata', $body['data'], 12 * HOUR_IN_SECONDS);
        return $body['data'];
    }

    return null;
}


// ─── SHORTCODE ───────────────────────────────────────────────────
add_shortcode('eyworks_enquiry_form', function () {
    if (!eyworks_is_configured()) {
        if (current_user_can('manage_options')) {
            return '<div class="eyworks-error" style="padding:1rem;border:1px solid #fecaca;border-radius:6px;background:#fef2f2;color:#991b1b;">
                <p><strong>EYWorks Enquiry Form:</strong> Not configured yet. <a href="' . admin_url('admin.php?page=eyworks-settings') . '">Go to Settings</a> to connect your EYWorks account.</p>
            </div>';
        }
        return ''; // Hide from visitors if not configured
    }

    wp_enqueue_style('eyworks-form-css');
    wp_enqueue_script('eyworks-form-js');

    $custom_css = eyworks_get_setting('custom_css');
    if (!empty($custom_css)) {
        wp_add_inline_style('eyworks-form-css', $custom_css);
    }

    $meta     = eyworks_get_metadata();
    $required = eyworks_required_fields();

    // Nursery (hidden)
    $nursery_id = !empty($meta['nursery'][0]['id']) ? $meta['nursery'][0]['id'] : base64_encode('1');

    // Source options
    $source_options = '<option value="">Select...</option>';
    if (!empty($meta['source'])) {
        foreach ($meta['source'] as $s) {
            $source_options .= '<option value="' . esc_attr($s['id']) . '">' . esc_html(trim($s['name'])) . '</option>';
        }
    }

    ob_start();
    ?>
    <div class="eyworks-form-wrapper" id="eyworks-form-wrapper">

        <div class="eyworks-success" id="eyworks-success" style="display:none;">
            <div class="eyworks-success-icon">&#10003;</div>
            <h3>Thank you for your enquiry!</h3>
            <p>We've received your details and will be in touch shortly to arrange your tour.</p>
        </div>

        <div class="eyworks-error" id="eyworks-error" style="display:none;"></div>

        <div id="eyworks-form-container">
            <input type="hidden" id="ew-nursery" value="<?php echo esc_attr($nursery_id); ?>">

            <h3 class="eyworks-section-title">Child Details</h3>

            <?php $child_name_req = in_array('child_first_name', $required) || in_array('child_last_name', $required); ?>
            <fieldset class="gfield gfield--type-name gfield--input-type-name gfield--width-full <?php echo $child_name_req ? 'gfield_contains_required' : ''; ?> field_sublabel_below gfield--no-description field_description_below gfield_visibility_visible">
                <legend class="gfield_label gform-field-label gfield_label_before_complex">Child Name<?php if ($child_name_req): ?><span class="gfield_required"><span class="gfield_required gfield_required_asterisk">*</span></span><?php endif; ?></legend>
                <div class="ginput_complex ginput_container ginput_container--name no_prefix has_first_name no_middle_name has_last_name no_suffix gf_name_has_2 ginput_container_name gform-grid-row">
                    <span class="name_first gform-grid-col gform-grid-col--size-auto">
                        <input type="text" id="ew-child-first-name" maxlength="45"<?php if (in_array('child_first_name', $required)) echo ' aria-required="true"'; ?>>
                        <label for="ew-child-first-name" class="gform-field-label gform-field-label--type-sub">First</label>
                    </span>
                    <span class="name_last gform-grid-col gform-grid-col--size-auto">
                        <input type="text" id="ew-child-last-name" maxlength="60"<?php if (in_array('child_last_name', $required)) echo ' aria-required="true"'; ?>>
                        <label for="ew-child-last-name" class="gform-field-label gform-field-label--type-sub">Last</label>
                    </span>
                </div>
            </fieldset>

            <div class="eyworks-row">
                <div class="eyworks-field eyworks-half">
                    <label for="ew-child-dob">Child Date of Birth / Expected DOB <?php if (in_array('child_dob', $required)) echo '<span class="eyworks-req">*</span>'; ?></label>
                    <input type="date" id="ew-child-dob"<?php if (in_array('child_dob', $required)) echo ' aria-required="true"'; ?>>
                </div>
                <div class="eyworks-field eyworks-half">
                    <label for="ew-child-gender">Legal Gender <?php if (in_array('child_gender', $required)) echo '<span class="eyworks-req">*</span>'; ?></label>
                    <select id="ew-child-gender">
                        <option value="">Select<?php echo in_array('child_gender', $required) ? '...' : ' (optional)...'; ?></option>
                        <option value="Female">Female</option>
                        <option value="Male">Male</option>
                        <option value="Other">Unknown / Other</option>
                    </select>
                </div>
            </div>

            <h3 class="eyworks-section-title">Parent / Guardian Details</h3>

            <?php $parent_name_req = in_array('parent_first_name', $required) || in_array('parent_last_name', $required); ?>
            <fieldset class="gfield gfield--type-name gfield--input-type-name gfield--width-full <?php echo $parent_name_req ? 'gfield_contains_required' : ''; ?> field_sublabel_below gfield--no-description field_description_below gfield_visibility_visible">
                <legend class="gfield_label gform-field-label gfield_label_before_complex">Name<?php if ($parent_name_req): ?><span class="gfield_required"><span class="gfield_required gfield_required_asterisk">*</span></span><?php endif; ?></legend>
                <div class="ginput_complex ginput_container ginput_container--name no_prefix has_first_name no_middle_name has_last_name no_suffix gf_name_has_2 ginput_container_name gform-grid-row">
                    <span class="name_first gform-grid-col gform-grid-col--size-auto">
                        <input type="text" id="ew-parent-first-name" maxlength="45"<?php if (in_array('parent_first_name', $required)) echo ' aria-required="true"'; ?>>
                        <label for="ew-parent-first-name" class="gform-field-label gform-field-label--type-sub">First</label>
                    </span>
                    <span class="name_last gform-grid-col gform-grid-col--size-auto">
                        <input type="text" id="ew-parent-last-name" maxlength="60"<?php if (in_array('parent_last_name', $required)) echo ' aria-required="true"'; ?>>
                        <label for="ew-parent-last-name" class="gform-field-label gform-field-label--type-sub">Last</label>
                    </span>
                </div>
            </fieldset>

            <div class="eyworks-row">
                <div class="eyworks-field eyworks-half">
                    <label for="ew-parent-email">Email <?php if (in_array('email', $required)) echo '<span class="eyworks-req">*</span>'; ?></label>
                    <input type="email" id="ew-parent-email" maxlength="45"<?php if (in_array('email', $required)) echo ' required aria-required="true"'; ?>>
                </div>
                <div class="eyworks-field eyworks-half">
                    <label for="ew-phone">Phone <?php if (in_array('phone', $required)) echo '<span class="eyworks-req">*</span>'; ?></label>
                    <input type="tel" id="ew-phone" maxlength="45"<?php if (in_array('phone', $required)) echo ' required aria-required="true"'; ?>>
                </div>
            </div>

            <div class="eyworks-row">
                <div class="eyworks-field eyworks-half">
                    <label for="ew-postcode">Postal Code <?php if (in_array('postcode', $required)) echo '<span class="eyworks-req">*</span>'; ?></label>
                    <input type="text" id="ew-postcode" maxlength="10"<?php if (in_array('postcode', $required)) echo ' aria-required="true"'; ?>>
                </div>
            </div>

            <h3 class="eyworks-section-title">Preferences</h3>

            <div class="eyworks-row">
                <div class="eyworks-field eyworks-half">
                    <label for="ew-start-date">Preferred Start Date <?php if (in_array('start_date', $required)) echo '<span class="eyworks-req">*</span>'; ?></label>
                    <input type="date" id="ew-start-date" min="<?php echo date('Y-m-d'); ?>"<?php if (in_array('start_date', $required)) echo ' aria-required="true"'; ?>>
                </div>
                <div class="eyworks-field eyworks-half">
                    <label for="ew-source">How did you hear about us? <?php if (in_array('source', $required)) echo '<span class="eyworks-req">*</span>'; ?></label>
                    <select id="ew-source"<?php if (in_array('source', $required)) echo ' aria-required="true"'; ?>>
                        <?php echo $source_options; ?>
                    </select>
                </div>
            </div>

            <div class="eyworks-row">
                <div class="eyworks-field eyworks-full">
                    <div class="eyworks-checkbox-wrap">
                        <input type="checkbox" id="ew-agree-terms" value="1" class="eyworks-checkbox"<?php if (in_array('agree_terms', $required)) echo ' required aria-required="true"'; ?>>
                        <label for="ew-agree-terms" class="eyworks-consent-label">You agree to receive information from us via phone or email.<?php if (in_array('agree_terms', $required)) echo ' <span class="eyworks-req">*</span>'; ?></label>
                    </div>
                </div>
            </div>

            <div class="eyworks-row">
                <div class="eyworks-field eyworks-full">
                    <button type="button" id="eyworks-submit-btn" class="eyworks-submit">
                        <span class="btn-text">Submit Enquiry</span>
                        <span class="btn-loading" style="display:none;">Submitting...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
});


// ─── AJAX HANDLER ────────────────────────────────────────────────
add_action('wp_ajax_eyworks_submit_enquiry', 'eyworks_handle_submission');
add_action('wp_ajax_nopriv_eyworks_submit_enquiry', 'eyworks_handle_submission');

function eyworks_handle_submission() {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'eyworks_enquiry_nonce')) {
        wp_send_json_error(['message' => 'Security check failed. Please refresh and try again.']);
    }

    if (!eyworks_is_configured()) {
        wp_send_json_error(['message' => 'The enquiry form is not configured yet.']);
    }

    // Sanitize
    $nursery           = sanitize_text_field($_POST['nursery'] ?? '');
    $first_name        = sanitize_text_field($_POST['first_name'] ?? '');
    $last_name         = sanitize_text_field($_POST['last_name'] ?? '');
    $parent_first_name = sanitize_text_field($_POST['parent_first_name'] ?? '');
    $parent_last_name  = sanitize_text_field($_POST['parent_last_name'] ?? '');
    $email             = sanitize_email($_POST['email'] ?? '');
    $phone             = sanitize_text_field($_POST['phone'] ?? '');
    $dob               = sanitize_text_field($_POST['dob'] ?? '');
    $gender            = sanitize_text_field($_POST['gender'] ?? '');
    $postcode          = sanitize_text_field($_POST['postcode'] ?? '');
    $start_date        = sanitize_text_field($_POST['preffered_start_date'] ?? '');
    $source            = sanitize_text_field($_POST['source'] ?? '');
    $source_text       = sanitize_text_field($_POST['source_text'] ?? '');

    $utm_source   = sanitize_text_field($_POST['utm_source'] ?? '');
    $utm_medium   = sanitize_text_field($_POST['utm_medium'] ?? '');
    $utm_campaign = sanitize_text_field($_POST['utm_campaign'] ?? '');
    $utm_content  = sanitize_text_field($_POST['utm_content'] ?? '');
    $utm_term     = sanitize_text_field($_POST['utm_term'] ?? '');

    // Validate mandatory (nursery is always required)
    if (empty($nursery)) {
        wp_send_json_error(['message' => 'Please fill in all required fields.']);
    }

    $required = eyworks_required_fields();
    $field_values = [
        'child_first_name' => $first_name,
        'child_last_name'  => $last_name,
        'child_dob'        => $dob,
        'child_gender'     => $gender,
        'parent_first_name'=> $parent_first_name,
        'parent_last_name' => $parent_last_name,
        'email'            => $email,
        'phone'            => $phone,
        'postcode'         => $postcode,
        'start_date'       => $start_date,
        'source'           => $source,
    ];
    foreach ($required as $key) {
        if ($key === 'agree_terms') continue; // Frontend-only
        if (isset($field_values[$key]) && empty($field_values[$key])) {
            wp_send_json_error(['message' => 'Please fill in all required fields.']);
        }
    }

    if (!empty($email) && !is_email($email)) {
        wp_send_json_error(['message' => 'Please enter a valid email address.']);
    }

    // ─── Build EYWorks API payload ───────────────────────────────
    $payload = [
        'nursery'           => $nursery,
        'first_name'        => $first_name,
        'last_name'         => $last_name,
        'parent_first_name' => $parent_first_name,
        'email'             => $email,
        'phone'             => $phone,
        'utm_source'        => $utm_source,
        'utm_medium'        => $utm_medium,
        'utm_campaign'      => $utm_campaign,
        'utm_content'       => $utm_content,
        'utm_term'          => $utm_term,
    ];

    if (!empty($parent_last_name)) $payload['parent_last_name'] = $parent_last_name;
    if (!empty($dob))              $payload['dob'] = $dob;
    if (!empty($postcode))         $payload['postcode'] = $postcode;
    if (!empty($start_date))       $payload['preffered_start_date'] = $start_date;
    if (!empty($source))           $payload['source'] = $source;

    // ─── POST to EYWorks ─────────────────────────────────────────
    $api_url   = eyworks_api_base() . '/enquiryPost';
    $json_body = wp_json_encode($payload);

    error_log('[EYWorks] POST ' . $api_url);
    error_log('[EYWorks] Payload: ' . $json_body);

    $response = wp_remote_post($api_url, [
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Bearer ' . eyworks_api_token(),
            'Content-Type'  => 'application/json',
        ],
        'body' => $json_body,
    ]);

    $eyworks_status = 'error';
    $eyworks_ref    = '';

    if (!is_wp_error($response)) {
        $status_code   = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        error_log('[EYWorks] Response [' . $status_code . ']: ' . $response_body);

        if ($status_code >= 200 && $status_code < 300 && !empty($response_data['status'])) {
            $eyworks_status = 'sent';
            $eyworks_ref    = $response_data['data']['id'] ?? '';
        }
    } else {
        error_log('[EYWorks] Connection error: ' . $response->get_error_message());
    }

    // ─── Save locally (if logging enabled) ─────────────────────────
    if (eyworks_log_submissions()) {
        global $wpdb;
        $table = $wpdb->prefix . 'eyworks_enquiries';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            error_log('[EYWorks] Table missing — creating now');
            eyworks_create_table();
        }

        $insert_result = $wpdb->insert($table, [
            'child_first_name'  => $first_name,
            'child_last_name'   => $last_name,
            'child_dob'         => !empty($dob) ? $dob : null,
            'child_gender'      => $gender,
            'parent_first_name' => $parent_first_name,
            'parent_last_name'  => $parent_last_name,
            'parent_email'      => $email,
            'phone'             => $phone,
            'postcode'          => $postcode,
            'start_date'        => !empty($start_date) ? $start_date : null,
            'source'            => $source_text,
            'utm_source'        => $utm_source,
            'utm_medium'        => $utm_medium,
            'utm_campaign'      => $utm_campaign,
            'utm_content'       => $utm_content,
            'utm_term'          => $utm_term,
            'eyworks_status'    => $eyworks_status,
            'eyworks_ref'       => $eyworks_ref,
            'created_at'        => current_time('mysql'),
        ]);

        if ($insert_result === false) {
            error_log('[EYWorks] DB insert FAILED: ' . $wpdb->last_error);
        } else {
            error_log('[EYWorks] Local entry saved: #' . $wpdb->insert_id . ' (EYWorks: ' . $eyworks_status . ')');
        }
    }

    // ─── Send email notification ─────────────────────────────────
    eyworks_send_notification_email([
        'child_name'  => $first_name . ' ' . $last_name,
        'child_dob'   => $dob,
        'gender'      => $gender,
        'parent_name' => $parent_first_name . ' ' . $parent_last_name,
        'email'       => $email,
        'phone'       => $phone,
        'postcode'    => $postcode,
        'start_date'  => $start_date,
        'source'      => $source_text,
    ]);

    // ─── Return response ─────────────────────────────────────────
    if ($eyworks_status === 'sent') {
        wp_send_json_success(['message' => 'Enquiry submitted successfully.']);
    } else {
        wp_send_json_success(['message' => 'Enquiry received — thank you!']);
    }
}


// ─── EMAIL NOTIFICATION ──────────────────────────────────────────
function eyworks_send_notification_email($data) {
    $to      = eyworks_notify_email();
    $subject = 'New Tour Enquiry: ' . $data['child_name'];

    $body  = "A new tour enquiry has been submitted.\n\n";
    $body .= "Child: {$data['child_name']}\n";
    if (!empty($data['child_dob']))  $body .= "DOB: {$data['child_dob']}\n";
    if (!empty($data['gender']))     $body .= "Gender: {$data['gender']}\n";
    $body .= "Parent: {$data['parent_name']}\n";
    $body .= "Email: {$data['email']}\n";
    $body .= "Phone: {$data['phone']}\n";
    if (!empty($data['postcode']))   $body .= "Postcode: {$data['postcode']}\n";
    if (!empty($data['start_date'])) $body .= "Preferred Start: {$data['start_date']}\n";
    if (!empty($data['source']))     $body .= "Source: {$data['source']}\n";

    $body .= "\n---\nView all enquiries in WordPress: " . admin_url('admin.php?page=eyworks-enquiries');

    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}


// ─── CSV EXPORT (runs before HTML output) ────────────────────────
add_action('admin_init', function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'eyworks-enquiries') return;
    if (!isset($_GET['action']) || $_GET['action'] !== 'export') return;
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'eyworks_export')) return;
    if (!current_user_can('manage_options')) return;

    eyworks_export_csv();
    exit;
});

function eyworks_export_csv() {
    global $wpdb;
    $table   = $wpdb->prefix . 'eyworks_enquiries';
    $entries = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC", ARRAY_A);

    while (ob_get_level()) { ob_end_clean(); }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=tour-enquiries-' . date('Y-m-d') . '.csv');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'ID', 'Child First Name', 'Child Last Name', 'Child DOB', 'Gender',
        'Parent First Name', 'Parent Last Name', 'Email', 'Phone', 'Postcode',
        'Preferred Start', 'Source', 'UTM Source', 'UTM Medium', 'UTM Campaign',
        'EYWorks Status', 'EYWorks Ref', 'Date',
    ]);

    foreach ($entries as $row) {
        fputcsv($output, [
            $row['id'], $row['child_first_name'], $row['child_last_name'],
            $row['child_dob'], $row['child_gender'], $row['parent_first_name'],
            $row['parent_last_name'], $row['parent_email'], $row['phone'],
            $row['postcode'], $row['start_date'], $row['source'],
            $row['utm_source'], $row['utm_medium'], $row['utm_campaign'],
            $row['eyworks_status'], $row['eyworks_ref'], $row['created_at'],
        ]);
    }

    fclose($output);
    die();
}


// ─── ADMIN DASHBOARD ─────────────────────────────────────────────
function eyworks_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'eyworks_enquiries';

    if (isset($_GET['action']) && $_GET['action'] === 'delete' && !empty($_GET['id'])) {
        if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'eyworks_delete_' . intval($_GET['id']))) {
            $wpdb->delete($table, ['id' => intval($_GET['id'])]);
            echo '<div class="notice notice-success"><p>Enquiry deleted.</p></div>';
        }
    }

    $search = sanitize_text_field($_GET['s'] ?? '');
    $where  = '';
    if (!empty($search)) {
        $like  = '%' . $wpdb->esc_like($search) . '%';
        $where = $wpdb->prepare(
            " WHERE child_first_name LIKE %s OR child_last_name LIKE %s OR parent_first_name LIKE %s OR parent_last_name LIKE %s OR parent_email LIKE %s OR phone LIKE %s",
            $like, $like, $like, $like, $like, $like
        );
    }

    $per_page    = 25;
    $current     = max(1, intval($_GET['paged'] ?? 1));
    $total       = $wpdb->get_var("SELECT COUNT(*) FROM $table $where");
    $total_pages = ceil($total / $per_page);
    $offset      = ($current - 1) * $per_page;

    $entries = $wpdb->get_results("SELECT * FROM $table $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");

    $export_url = wp_nonce_url(admin_url('admin.php?page=eyworks-enquiries&action=export'), 'eyworks_export');
    ?>
    <style>
        .eyworks-detail-row td { padding: 0 !important; background: #f9f9f9; }
        .eyworks-detail-inner { padding: 12px 20px 16px; display: none; }
        .eyworks-detail-inner.visible { display: block; }
        .eyworks-detail-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px 24px; }
        .eyworks-detail-grid dt { font-weight: 600; font-size: 11px; text-transform: uppercase; color: #888; margin: 0; }
        .eyworks-detail-grid dd { margin: 2px 0 10px; font-size: 13px; color: #333; }
        .eyworks-toggle { cursor: pointer; color: #2271b1; text-decoration: none; }
        .eyworks-toggle:hover { color: #135e96; }
    </style>
    <div class="wrap">
        <h1 class="wp-heading-inline">Tour Enquiries</h1>
        <a href="<?php echo esc_url($export_url); ?>" class="page-title-action">Export CSV</a>
        <span class="subtitle" style="margin-left: 10px;"><?php echo intval($total); ?> total</span>

        <form method="get" style="margin: 15px 0;">
            <input type="hidden" name="page" value="eyworks-enquiries">
            <p class="search-box">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search enquiries...">
                <input type="submit" class="button" value="Search">
                <?php if (!empty($search)): ?>
                    <a href="<?php echo admin_url('admin.php?page=eyworks-enquiries'); ?>" class="button">Clear</a>
                <?php endif; ?>
            </p>
        </form>

        <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
            <thead>
                <tr>
                    <th style="width:35px;">#</th>
                    <th>Child</th>
                    <th>Parent</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Source</th>
                    <th>Start Date</th>
                    <th>EYWorks</th>
                    <th>Submitted</th>
                    <th style="width:100px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($entries)): ?>
                    <tr><td colspan="10">No enquiries found.</td></tr>
                <?php else: foreach ($entries as $e): $row_id = intval($e->id); ?>
                    <tr>
                        <td><?php echo $row_id; ?></td>
                        <td>
                            <strong><?php echo esc_html($e->child_first_name . ' ' . $e->child_last_name); ?></strong>
                            <?php if ($e->child_dob): ?><br><small>DOB: <?php echo esc_html(date('d/m/Y', strtotime($e->child_dob))); ?></small><?php endif; ?>
                            <?php if ($e->child_gender): ?><br><small><?php echo esc_html($e->child_gender); ?></small><?php endif; ?>
                        </td>
                        <td><?php echo esc_html(trim($e->parent_first_name . ' ' . $e->parent_last_name)); ?></td>
                        <td><a href="mailto:<?php echo esc_attr($e->parent_email); ?>"><?php echo esc_html($e->parent_email); ?></a></td>
                        <td><?php echo esc_html($e->phone); ?></td>
                        <td><?php echo esc_html($e->source ?: '—'); ?></td>
                        <td><?php echo $e->start_date ? esc_html(date('d/m/Y', strtotime($e->start_date))) : '—'; ?></td>
                        <td><?php echo $e->eyworks_status === 'sent' ? '<span style="color:#27ae60;">&#10003; Sent</span>' : '<span style="color:#e74c3c;">&#10007; Failed</span>'; ?></td>
                        <td><?php echo esc_html(date('d/m/Y H:i', strtotime($e->created_at))); ?></td>
                        <td>
                            <a href="#" class="eyworks-toggle" onclick="var d=document.getElementById('ew-detail-<?php echo $row_id; ?>');d.classList.toggle('visible');this.textContent=d.classList.contains('visible')?'Hide':'View';return false;">View</a>
                            &nbsp;|&nbsp;
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=eyworks-enquiries&action=delete&id=' . $row_id), 'eyworks_delete_' . $row_id)); ?>" onclick="return confirm('Delete this enquiry?');" style="color:#a00;">Delete</a>
                        </td>
                    </tr>
                    <tr class="eyworks-detail-row"><td colspan="10"><div id="ew-detail-<?php echo $row_id; ?>" class="eyworks-detail-inner"><dl class="eyworks-detail-grid">
                        <div><dt>Child Name</dt><dd><?php echo esc_html($e->child_first_name . ' ' . $e->child_last_name); ?></dd></div>
                        <div><dt>Date of Birth</dt><dd><?php echo $e->child_dob ? esc_html(date('d/m/Y', strtotime($e->child_dob))) : '—'; ?></dd></div>
                        <div><dt>Gender</dt><dd><?php echo esc_html($e->child_gender ?: '—'); ?></dd></div>
                        <div><dt>Parent Name</dt><dd><?php echo esc_html(trim($e->parent_first_name . ' ' . $e->parent_last_name)); ?></dd></div>
                        <div><dt>Email</dt><dd><a href="mailto:<?php echo esc_attr($e->parent_email); ?>"><?php echo esc_html($e->parent_email); ?></a></dd></div>
                        <div><dt>Phone</dt><dd><?php echo esc_html($e->phone); ?></dd></div>
                        <div><dt>Postcode</dt><dd><?php echo esc_html($e->postcode ?: '—'); ?></dd></div>
                        <div><dt>Preferred Start</dt><dd><?php echo $e->start_date ? esc_html(date('d/m/Y', strtotime($e->start_date))) : '—'; ?></dd></div>
                        <div><dt>Source</dt><dd><?php echo esc_html($e->source ?: '—'); ?></dd></div>
                        <div><dt>EYWorks</dt><dd><?php echo $e->eyworks_status === 'sent' ? '<span style="color:#27ae60;">&#10003; Sent</span>' . ($e->eyworks_ref ? '<br><small>Ref: ' . esc_html($e->eyworks_ref) . '</small>' : '') : '<span style="color:#e74c3c;">&#10007; Failed</span>'; ?></dd></div>
                        <?php if ($e->utm_source || $e->utm_medium || $e->utm_campaign): ?>
                        <div><dt>UTM Source</dt><dd><?php echo esc_html($e->utm_source ?: '—'); ?></dd></div>
                        <div><dt>UTM Medium</dt><dd><?php echo esc_html($e->utm_medium ?: '—'); ?></dd></div>
                        <div><dt>UTM Campaign</dt><dd><?php echo esc_html($e->utm_campaign ?: '—'); ?></dd></div>
                        <?php endif; ?>
                        <div><dt>Submitted</dt><dd><?php echo esc_html(date('d/m/Y H:i:s', strtotime($e->created_at))); ?></dd></div>
                    </dl></div></td></tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="tablenav bottom"><div class="tablenav-pages">
            <?php echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $current, 'total' => $total_pages]); ?>
        </div></div>
        <?php endif; ?>
    </div>
    <?php
}

// ─── GITHUB AUTO-UPDATER ────────────────────────────────────────
// Checks the public GitHub repo for new releases and integrates
// with the WordPress plugin update system.

define('EYWORKS_GITHUB_REPO', 'twotenstudio/eyworks-enquiry-form');

add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (empty($transient->checked)) return $transient;

    $response = wp_remote_get('https://api.github.com/repos/' . EYWORKS_GITHUB_REPO . '/releases/latest', [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/vnd.github.v3+json'],
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return $transient;
    }

    $release = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($release['tag_name'])) return $transient;

    $remote_version = ltrim($release['tag_name'], 'v');
    $plugin_file    = plugin_basename(__FILE__);

    if (version_compare(EYWORKS_PLUGIN_VERSION, $remote_version, '<')) {
        // Prefer a zip asset if attached; otherwise use the GitHub source zip
        $download_url = $release['zipball_url'];
        if (!empty($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (substr($asset['name'], -4) === '.zip') {
                    $download_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        $transient->response[$plugin_file] = (object) [
            'slug'        => 'eyworks-enquiry-form',
            'plugin'      => $plugin_file,
            'new_version' => $remote_version,
            'url'         => 'https://github.com/' . EYWORKS_GITHUB_REPO,
            'package'     => $download_url,
        ];
    } else {
        // Tell WordPress we checked and are up-to-date, so it clears any stale update notices
        $transient->no_update[$plugin_file] = (object) [
            'slug'        => 'eyworks-enquiry-form',
            'plugin'      => $plugin_file,
            'new_version' => EYWORKS_PLUGIN_VERSION,
            'url'         => 'https://github.com/' . EYWORKS_GITHUB_REPO,
        ];
        unset($transient->response[$plugin_file]);
    }

    return $transient;
});

// Show plugin info in the update details modal
add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'eyworks-enquiry-form') {
        return $result;
    }

    $response = wp_remote_get('https://api.github.com/repos/' . EYWORKS_GITHUB_REPO . '/releases/latest', [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/vnd.github.v3+json'],
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return $result;
    }

    $release = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($release['tag_name'])) return $result;

    return (object) [
        'name'          => 'EYWorks Enquiry Form',
        'slug'          => 'eyworks-enquiry-form',
        'version'       => ltrim($release['tag_name'], 'v'),
        'author'        => '<a href="https://twotenstudio.co.uk">Two Ten Studio</a>',
        'homepage'      => 'https://github.com/' . EYWORKS_GITHUB_REPO,
        'sections'      => [
            'description'  => 'Customisable enquiry form for EYWorks-powered nurseries with API integration, GTM tracking, local storage, email notifications, and admin dashboard.',
            'changelog'    => nl2br(esc_html($release['body'] ?? 'See GitHub for release notes.')),
        ],
        'download_link' => $release['zipball_url'],
    ];
}, 10, 3);

// After installing from a GitHub zipball the folder is named owner-repo-hash.
// Rename it back to the expected plugin directory so WordPress can activate it.
add_filter('upgrader_post_install', function ($response, $hook_extra, $result) {
    if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(__FILE__)) {
        return $response;
    }

    global $wp_filesystem;
    $proper_destination = WP_PLUGIN_DIR . '/eyworks-enquiry-form';

    // If the extracted folder already has the right name, nothing to do
    if ($result['destination'] === $proper_destination . '/') {
        return $response;
    }

    $wp_filesystem->move($result['destination'], $proper_destination);
    $result['destination'] = $proper_destination . '/';

    // Re-activate if it was active before the update
    if (is_plugin_active($hook_extra['plugin'])) {
        activate_plugin($hook_extra['plugin']);
    }

    return $response;
}, 10, 3);


// ─── SETTINGS LINK ON PLUGINS PAGE ──────────────────────────────
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=eyworks-settings') . '">Settings</a>';
    array_unshift($links, $settings_link);

    $check_url = wp_nonce_url(admin_url('plugins.php?eyworks_check_update=1'), 'eyworks_check_update');
    $links[] = '<a href="' . esc_url($check_url) . '">Check for updates</a>';

    return $links;
});

// Handle the "Check for updates" click — clear the transient so WordPress re-checks
add_action('admin_init', function () {
    if (empty($_GET['eyworks_check_update'])) return;
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'eyworks_check_update')) return;
    if (!current_user_can('update_plugins')) return;

    delete_site_transient('update_plugins');
    wp_update_plugins();

    wp_safe_redirect(admin_url('plugins.php?eyworks_updated=1'));
    exit;
});

// Show an admin notice after the check completes
add_action('admin_notices', function () {
    if (empty($_GET['eyworks_updated']) || !current_user_can('update_plugins')) return;
    $transient = get_site_transient('update_plugins');
    $plugin_file = plugin_basename(EYWORKS_PLUGIN_DIR . 'eyworks-enquiry-form.php');
    if (!empty($transient->response[$plugin_file])) {
        $new = $transient->response[$plugin_file]->new_version;
        echo '<div class="notice notice-warning is-dismissible"><p><strong>EYWorks Enquiry Form:</strong> Version ' . esc_html($new) . ' is available. <a href="' . esc_url(self_admin_url('update-core.php')) . '">Update now</a>.</p></div>';
    } else {
        echo '<div class="notice notice-success is-dismissible"><p><strong>EYWorks Enquiry Form:</strong> You are running the latest version (' . EYWORKS_PLUGIN_VERSION . ').</p></div>';
    }
});
