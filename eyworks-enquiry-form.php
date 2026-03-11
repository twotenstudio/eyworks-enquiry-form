<?php
/**
 * Plugin Name: EYWorks Enquiry Form
 * Description: Custom enquiry form for The Working Mums Club with EYWorks API integration, GTM tracking, local storage, and admin dashboard.
 * Version: 2.1.0
 * Author: Two Ten Studio
 */

if (!defined('ABSPATH')) exit;

// ─── CONFIGURATION ───────────────────────────────────────────────
if (!defined('EYWORKS_API_TOKEN')) {
    define('EYWORKS_API_TOKEN', 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJjdXN0b21lciI6InRoZXdvcmtpbmdtdW1zY2x1Yi5leWxvZy5jby51ayIsImFjY2VzcyI6WyJlbnF1aXJpZXMiXX0.0FsvW5iCuhUy66lr4hUFDSD3lJ1r-9He2rfP24L0i_w');
}

define('EYWORKS_API_BASE', 'https://theworkingmumsclub.eylog.co.uk/eyMan/index.php/api');

// Email address for notifications (comma-separate for multiple)
if (!defined('EYWORKS_NOTIFY_EMAIL')) {
    define('EYWORKS_NOTIFY_EMAIL', get_option('admin_email'));
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


// ─── AUTO-CREATE TABLE (runs once, no deactivation needed) ───────
add_action('admin_init', function () {
    if (get_option('eyworks_db_version') !== '2.1.0') {
        eyworks_create_table();
        update_option('eyworks_db_version', '2.1.0');
    }
});

// ─── ENQUEUE STYLES & SCRIPTS ────────────────────────────────────
// Enqueued via shortcode callback to work with any page builder
add_action('wp_enqueue_scripts', function () {
    // Register (don't enqueue yet) — the shortcode will enqueue them
    wp_register_style('eyworks-form-css', plugin_dir_url(__FILE__) . 'eyworks-form.css', [], '2.1.0');
    wp_register_script('eyworks-form-js', plugin_dir_url(__FILE__) . 'eyworks-form.js', [], '2.1.0', true);
    wp_localize_script('eyworks-form-js', 'eyworksForm', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('eyworks_enquiry_nonce'),
    ]);
});


// ─── HELPER: Get metadata from EYWorks (cached 12h) ─────────────
function eyworks_get_metadata() {
    $cached = get_transient('eyworks_enquiry_metadata');
    if ($cached) return $cached;

    $response = wp_remote_get(EYWORKS_API_BASE . '/enquirySettings', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . EYWORKS_API_TOKEN,
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
    // Enqueue the registered scripts/styles when shortcode is actually rendered
    wp_enqueue_style('eyworks-form-css');
    wp_enqueue_script('eyworks-form-js');

    $meta = eyworks_get_metadata();

    // Nursery (hidden)
    $nursery_id = !empty($meta['nursery'][0]['id']) ? $meta['nursery'][0]['id'] : base64_encode('1');

    // Source options
    $source_options = '<option value="">Select...</option>';
    if (!empty($meta['source'])) {
        foreach ($meta['source'] as $s) {
            $source_options .= '<option value="' . esc_attr($s['id']) . '">' . esc_html(trim($s['name'])) . '</option>';
        }
    } else {
        $source_map = [
            1 => 'College / Uni / NHS', 2 => 'Existing Parent', 7 => 'Friend / Family',
            8 => 'Health Professional', 3 => 'Marketing - Leaflet', 14 => 'Marketing - Newspaper / Magazine',
            4 => 'Marketing - Poster', 5 => 'Marketing - Seen signs', 9 => 'Marketing - TV in hospitals',
            13 => 'Marketing - Website', 6 => 'Passing By', 15 => 'Search Engine (Google, Bing, Other)',
            10 => 'Shows / Exhibitions', 11 => 'Staff', 12 => 'Staff on Pickup',
        ];
        foreach ($source_map as $id => $name) {
            $source_options .= '<option value="' . base64_encode($id) . '">' . esc_html($name) . '</option>';
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

            <h2 class="eyworks-section-title">Child Details</h2>

            <div class="eyworks-row">
                <div class="eyworks-field eyworks-half">
                    <label for="ew-child-first-name">Child First Name <span class="eyworks-req">*</span></label>
                    <input type="text" id="ew-child-first-name" maxlength="45" required>
                </div>
                <div class="eyworks-field eyworks-half">
                    <label for="ew-child-last-name">Child Last Name <span class="eyworks-req">*</span></label>
                    <input type="text" id="ew-child-last-name" maxlength="60" required>
                </div>
            </div>

            <div class="eyworks-row">
                <div class="eyworks-field eyworks-half">
                    <label for="ew-child-dob">Child Date of Birth / Expected DOB</label>
                    <input type="date" id="ew-child-dob">
                </div>
                <div class="eyworks-field eyworks-half">
                    <label for="ew-child-gender">Legal Gender</label>
                    <select id="ew-child-gender">
                        <option value="">Select (optional)...</option>
                        <option value="Female">Female</option>
                        <option value="Male">Male</option>
                        <option value="Other">Unknown / Other</option>
                    </select>
                </div>
            </div>

            <h2 class="eyworks-section-title">Parent / Guardian Details</h2>

            <div class="eyworks-row">
                <div class="eyworks-field eyworks-half">
                    <label for="ew-parent-first-name">First Name <span class="eyworks-req">*</span></label>
                    <input type="text" id="ew-parent-first-name" maxlength="45" required>
                </div>
                <div class="eyworks-field eyworks-half">
                    <label for="ew-parent-last-name">Last Name</label>
                    <input type="text" id="ew-parent-last-name" maxlength="60">
                </div>
            </div>

            <div class="eyworks-row">
                <div class="eyworks-field eyworks-half">
                    <label for="ew-parent-email">Email <span class="eyworks-req">*</span></label>
                    <input type="email" id="ew-parent-email" maxlength="45" required>
                </div>
                <div class="eyworks-field eyworks-half">
                    <label for="ew-phone">Phone <span class="eyworks-req">*</span></label>
                    <input type="tel" id="ew-phone" maxlength="45" required>
                </div>
            </div>

            <div class="eyworks-row">
                <div class="eyworks-field eyworks-half">
                    <label for="ew-postcode">Postal Code</label>
                    <input type="text" id="ew-postcode" maxlength="10">
                </div>
            </div>

            <h2 class="eyworks-section-title">Preferences</h2>

            <div class="eyworks-row">
                <div class="eyworks-field eyworks-half">
                    <label for="ew-start-date">Preferred Start Date</label>
                    <input type="date" id="ew-start-date" min="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="eyworks-field eyworks-half">
                    <label for="ew-source">How did you hear about us?</label>
                    <select id="ew-source">
                        <?php echo $source_options; ?>
                    </select>
                </div>
            </div>

            <div class="eyworks-row">
                <div class="eyworks-field eyworks-full">
                    <div class="eyworks-checkbox-wrap">
                        <input type="checkbox" id="ew-agree-terms" value="1" class="eyworks-checkbox" required>
                        <label for="ew-agree-terms" class="eyworks-consent-label">You agree to receive information from us via phone or email.</label>
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

    // Validate mandatory
    if (empty($nursery) || empty($first_name) || empty($last_name)
        || empty($parent_first_name) || empty($email) || empty($phone)) {
        wp_send_json_error(['message' => 'Please fill in all required fields.']);
    }

    if (!is_email($email)) {
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
    $api_url   = EYWORKS_API_BASE . '/enquiryPost';
    $json_body = wp_json_encode($payload);

    error_log('[EYWorks] POST ' . $api_url);
    error_log('[EYWorks] Payload: ' . $json_body);

    $response = wp_remote_post($api_url, [
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Bearer ' . EYWORKS_API_TOKEN,
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

    // ─── Save locally (always, even if EYWorks fails) ────────────
    global $wpdb;
    $table = $wpdb->prefix . 'eyworks_enquiries';

    // Ensure table exists (failsafe — creates if missing)
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
        $local_id = $wpdb->insert_id;
        error_log('[EYWorks] Local entry saved: #' . $local_id . ' (EYWorks: ' . $eyworks_status . ')');
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
        // Still saved locally — show success to user but log the EYWorks issue
        wp_send_json_success(['message' => 'Enquiry received — thank you!']);
    }
}


// ─── EMAIL NOTIFICATION ──────────────────────────────────────────
function eyworks_send_notification_email($data) {
    $to      = EYWORKS_NOTIFY_EMAIL;
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

    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    wp_mail($to, $subject, $body, $headers);
}


// ─── ADMIN DASHBOARD ─────────────────────────────────────────────
add_action('admin_menu', function () {
    add_menu_page(
        'Tour Enquiries',
        'Tour Enquiries',
        'manage_options',
        'eyworks-enquiries',
        'eyworks_admin_page',
        'dashicons-clipboard',
        26
    );
});

// CSV export must run BEFORE any HTML is output
add_action('admin_init', function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'eyworks-enquiries') return;
    if (!isset($_GET['action']) || $_GET['action'] !== 'export') return;
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'eyworks_export')) return;
    if (!current_user_can('manage_options')) return;

    eyworks_export_csv();
    exit;
});

function eyworks_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'eyworks_enquiries';

    // Handle delete
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && !empty($_GET['id'])) {
        if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'eyworks_delete_' . intval($_GET['id']))) {
            $wpdb->delete($table, ['id' => intval($_GET['id'])]);
            echo '<div class="notice notice-success"><p>Enquiry deleted.</p></div>';
        }
    }

    // Search
    $search = sanitize_text_field($_GET['s'] ?? '');
    $where  = '';
    if (!empty($search)) {
        $like  = '%' . $wpdb->esc_like($search) . '%';
        $where = $wpdb->prepare(
            " WHERE child_first_name LIKE %s OR child_last_name LIKE %s OR parent_first_name LIKE %s OR parent_last_name LIKE %s OR parent_email LIKE %s OR phone LIKE %s",
            $like, $like, $like, $like, $like, $like
        );
    }

    // Pagination
    $per_page    = 25;
    $current     = max(1, intval($_GET['paged'] ?? 1));
    $total       = $wpdb->get_var("SELECT COUNT(*) FROM $table $where");
    $total_pages = ceil($total / $per_page);
    $offset      = ($current - 1) * $per_page;

    $entries = $wpdb->get_results(
        "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset"
    );

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
                <?php else: ?>
                    <?php foreach ($entries as $e):
                        $row_id = intval($e->id);
                    ?>
                        <tr>
                            <td><?php echo $row_id; ?></td>
                            <td>
                                <strong><?php echo esc_html($e->child_first_name . ' ' . $e->child_last_name); ?></strong>
                                <?php if ($e->child_dob): ?>
                                    <br><small>DOB: <?php echo esc_html(date('d/m/Y', strtotime($e->child_dob))); ?></small>
                                <?php endif; ?>
                                <?php if ($e->child_gender): ?>
                                    <br><small><?php echo esc_html($e->child_gender); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(trim($e->parent_first_name . ' ' . $e->parent_last_name)); ?></td>
                            <td><a href="mailto:<?php echo esc_attr($e->parent_email); ?>"><?php echo esc_html($e->parent_email); ?></a></td>
                            <td><?php echo esc_html($e->phone); ?></td>
                            <td><?php echo esc_html($e->source ?: '—'); ?></td>
                            <td><?php echo $e->start_date ? esc_html(date('d/m/Y', strtotime($e->start_date))) : '—'; ?></td>
                            <td>
                                <?php if ($e->eyworks_status === 'sent'): ?>
                                    <span style="color:#27ae60;">&#10003; Sent</span>
                                <?php else: ?>
                                    <span style="color:#e74c3c;">&#10007; Failed</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(date('d/m/Y H:i', strtotime($e->created_at))); ?></td>
                            <td>
                                <a href="#" class="eyworks-toggle" onclick="var d=document.getElementById('ew-detail-<?php echo $row_id; ?>');d.classList.toggle('visible');this.textContent=d.classList.contains('visible')?'Hide':'View';return false;">View</a>
                                &nbsp;|&nbsp;
                                <?php
                                $del_url = wp_nonce_url(
                                    admin_url('admin.php?page=eyworks-enquiries&action=delete&id=' . $row_id),
                                    'eyworks_delete_' . $row_id
                                );
                                ?>
                                <a href="<?php echo esc_url($del_url); ?>" onclick="return confirm('Delete this enquiry?');" style="color:#a00;">Delete</a>
                            </td>
                        </tr>
                        <tr class="eyworks-detail-row">
                            <td colspan="10">
                                <div id="ew-detail-<?php echo $row_id; ?>" class="eyworks-detail-inner">
                                    <dl class="eyworks-detail-grid">
                                        <div>
                                            <dt>Child Name</dt>
                                            <dd><?php echo esc_html($e->child_first_name . ' ' . $e->child_last_name); ?></dd>
                                        </div>
                                        <div>
                                            <dt>Date of Birth</dt>
                                            <dd><?php echo $e->child_dob ? esc_html(date('d/m/Y', strtotime($e->child_dob))) : '—'; ?></dd>
                                        </div>
                                        <div>
                                            <dt>Gender</dt>
                                            <dd><?php echo esc_html($e->child_gender ?: '—'); ?></dd>
                                        </div>
                                        <div>
                                            <dt>Parent Name</dt>
                                            <dd><?php echo esc_html(trim($e->parent_first_name . ' ' . $e->parent_last_name)); ?></dd>
                                        </div>
                                        <div>
                                            <dt>Email</dt>
                                            <dd><a href="mailto:<?php echo esc_attr($e->parent_email); ?>"><?php echo esc_html($e->parent_email); ?></a></dd>
                                        </div>
                                        <div>
                                            <dt>Phone</dt>
                                            <dd><?php echo esc_html($e->phone); ?></dd>
                                        </div>
                                        <div>
                                            <dt>Postcode</dt>
                                            <dd><?php echo esc_html($e->postcode ?: '—'); ?></dd>
                                        </div>
                                        <div>
                                            <dt>Preferred Start Date</dt>
                                            <dd><?php echo $e->start_date ? esc_html(date('d/m/Y', strtotime($e->start_date))) : '—'; ?></dd>
                                        </div>
                                        <div>
                                            <dt>Source</dt>
                                            <dd><?php echo esc_html($e->source ?: '—'); ?></dd>
                                        </div>
                                        <div>
                                            <dt>EYWorks Status</dt>
                                            <dd>
                                                <?php if ($e->eyworks_status === 'sent'): ?>
                                                    <span style="color:#27ae60;">&#10003; Sent</span>
                                                    <?php if ($e->eyworks_ref): ?>
                                                        <br><small>Ref: <?php echo esc_html($e->eyworks_ref); ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span style="color:#e74c3c;">&#10007; Failed</span>
                                                <?php endif; ?>
                                            </dd>
                                        </div>
                                        <?php if ($e->utm_source || $e->utm_medium || $e->utm_campaign): ?>
                                        <div>
                                            <dt>UTM Source</dt>
                                            <dd><?php echo esc_html($e->utm_source ?: '—'); ?></dd>
                                        </div>
                                        <div>
                                            <dt>UTM Medium</dt>
                                            <dd><?php echo esc_html($e->utm_medium ?: '—'); ?></dd>
                                        </div>
                                        <div>
                                            <dt>UTM Campaign</dt>
                                            <dd><?php echo esc_html($e->utm_campaign ?: '—'); ?></dd>
                                        </div>
                                        <?php if ($e->utm_content): ?>
                                        <div>
                                            <dt>UTM Content</dt>
                                            <dd><?php echo esc_html($e->utm_content); ?></dd>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($e->utm_term): ?>
                                        <div>
                                            <dt>UTM Term</dt>
                                            <dd><?php echo esc_html($e->utm_term); ?></dd>
                                        </div>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                        <div>
                                            <dt>Submitted</dt>
                                            <dd><?php echo esc_html(date('d/m/Y H:i:s', strtotime($e->created_at))); ?></dd>
                                        </div>
                                    </dl>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links([
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $current,
                        'total'   => $total_pages,
                    ]);
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}


// ─── CSV EXPORT ──────────────────────────────────────────────────
function eyworks_export_csv() {
    global $wpdb;
    $table   = $wpdb->prefix . 'eyworks_enquiries';
    $entries = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC", ARRAY_A);

    // Clear ALL output buffers — WordPress may have started rendering
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=tour-enquiries-' . date('Y-m-d') . '.csv');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // Headers
    fputcsv($output, [
        'ID', 'Child First Name', 'Child Last Name', 'Child DOB', 'Gender',
        'Parent First Name', 'Parent Last Name', 'Email', 'Phone', 'Postcode',
        'Preferred Start', 'Source', 'UTM Source', 'UTM Medium', 'UTM Campaign',
        'EYWorks Status', 'EYWorks Ref', 'Date',
    ]);

    foreach ($entries as $row) {
        fputcsv($output, [
            $row['id'],
            $row['child_first_name'],
            $row['child_last_name'],
            $row['child_dob'],
            $row['child_gender'],
            $row['parent_first_name'],
            $row['parent_last_name'],
            $row['parent_email'],
            $row['phone'],
            $row['postcode'],
            $row['start_date'],
            $row['source'],
            $row['utm_source'],
            $row['utm_medium'],
            $row['utm_campaign'],
            $row['eyworks_status'],
            $row['eyworks_ref'],
            $row['created_at'],
        ]);
    }

    fclose($output);
    die();
}
