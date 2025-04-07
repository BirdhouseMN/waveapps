<?php
/**
 * Plugin Name: Wave Invoice Portal
 * Description: Displays Wave invoices for logged-in users. Admin connects once; plugin handles automatic token refresh.
 * Version: 4.6
 * Author: Birdie
 */

define('WAVE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WAVE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WAVE_LOG_FILE', WAVE_PLUGIN_PATH . 'wave-sync-log.txt');

require_once WAVE_PLUGIN_PATH . 'inc/graphql.php';
require_once WAVE_PLUGIN_PATH . 'inc/invoice-functions.php';

function wave_enqueue_assets() {
    wp_enqueue_style('wave-invoice-style', WAVE_PLUGIN_URL . 'assets/style.css', array(), '1.0');
    wp_enqueue_script('wave-invoice-script', WAVE_PLUGIN_URL . 'assets/custom.js', array('jquery'), '1.0', true);
}
add_action('wp_enqueue_scripts', 'wave_enqueue_assets');

add_shortcode('wave_invoice', 'wave_render_invoice_portal');

function wave_register_oauth_endpoint() {
    add_rewrite_endpoint('oauth-callback', EP_ROOT);
}
add_action('init', 'wave_register_oauth_endpoint');


// ====================================
// OAUTH: HANDLE CALLBACK
// ====================================
function wave_oauth_handle_callback() {
    global $wp_query;
    if (!isset($wp_query->query_vars['oauth-callback'])) return;

    if (!isset($_GET['code']) || !isset($_GET['state']) || !wp_verify_nonce($_GET['state'], 'wave_oauth_state')) {
        wave_append_log("[OAuth Callback] Invalid state or missing parameters.");
        wp_die('Invalid OAuth state or missing parameters.');
    }

    $code = sanitize_text_field($_GET['code']);
    wave_append_log("[OAuth Callback] Received code: {$code}");

    $tokens = wave_exchange_code_for_tokens($code);
    wave_append_log("[OAuth Callback] Token response: " . print_r($tokens, true));

    if (!$tokens || !isset($tokens['access_token'])) {
        wave_append_log("[OAuth Callback] Failed to retrieve access token.");
        wp_die('Failed to get access token.');
    }

    update_option('wave_access_token', sanitize_text_field($tokens['access_token']));
    update_option('wave_refresh_token', sanitize_text_field($tokens['refresh_token']));
    update_option('wave_token_last_updated', current_time('mysql'));

    $query = '
        query {
            businesses {
                edges {
                    node {
                        id
                        name
                    }
                }
            }
        }
    ';
    $response = wave_graphql_request($tokens['access_token'], $query);
    wave_append_log("[OAuth Callback] Business query response: " . print_r($response, true));

    if (
        isset($response['data']['businesses']['edges'][0]['node']['id']) &&
        isset($response['data']['businesses']['edges'][0]['node']['name'])
    ) {
        $node = $response['data']['businesses']['edges'][0]['node'];
        update_option('wave_connected_business_name', sanitize_text_field($node['name']));
        update_option('wave_connected_business_id', sanitize_text_field($node['id']));
        wave_append_log("[OAuth Callback] Business connected: {$node['name']}");
    } else {
        delete_option('wave_connected_business_name');
        delete_option('wave_connected_business_id');
        wave_append_log("[OAuth Callback] No business node found.");
    }

    wp_redirect(admin_url('options-general.php?page=wave-invoice'));
    exit;
}


add_action('template_redirect', 'wave_oauth_handle_callback');



// ====================================
// ADMIN PAGE: ADD MENU ITEM
// ====================================
function wave_invoice_add_admin_page() {
    add_options_page(
        'Connect to Wave',
        'Connect to Wave',
        'manage_options',
        'wave-invoice',
        'wave_invoice_admin_page_html'
    );
}
add_action('admin_menu', 'wave_invoice_add_admin_page');


// ====================================
// ADMIN PAGE
// ====================================
function wave_invoice_admin_page_html() {
    if (!current_user_can('manage_options')) return;

    if (isset($_GET['wave_disconnect'])) {
        check_admin_referer('wave_disconnect_nonce');
        delete_option('wave_access_token');
        delete_option('wave_refresh_token');
        delete_option('wave_token_last_updated');
        delete_option('wave_client_id');
        delete_option('wave_client_secret');
        delete_option('wave_redirect_uri');
        delete_option('wave_connected_business_name');
        delete_option('wave_connected_business_id');
        echo '<div class="notice notice-success"><p>Disconnected from Wave and cleared settings.</p></div>';
    }

    if (isset($_POST['wave_save_settings']) && check_admin_referer('wave_save_settings_nonce')) {
        update_option('wave_client_id', sanitize_text_field($_POST['wave_client_id']));
        update_option('wave_client_secret', sanitize_text_field($_POST['wave_client_secret']));
        update_option('wave_redirect_uri', esc_url_raw($_POST['wave_redirect_uri']));
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    $client_id     = get_option('wave_client_id');
    $client_secret = get_option('wave_client_secret');
    $redirect_uri  = get_option('wave_redirect_uri');
    $access_token  = get_option('wave_access_token');
    $last_connected = get_option('wave_token_last_updated');
    $business_name = get_option('wave_connected_business_name');
    $connected = false;
    $connection_debug = '';

    // Re-check token validity using supported query
    if ($access_token) {
        $test = wave_graphql_request($access_token, '{
            businesses {
                edges {
                    node {
                        id
                    }
                }
            }
        }');

        if (
            isset($test['data']['businesses']['edges']) &&
            is_array($test['data']['businesses']['edges']) &&
            count($test['data']['businesses']['edges']) > 0
        ) {
            $connected = true;
        } else {
            $connected = false;
            $connection_debug = '<pre>Token test failed: ' . esc_html(print_r($test, true)) . '</pre>';
        }
    } else {
        $connection_debug = '<p><em>No valid access token available. Auto-refresh may have failed.</em></p>';
    }

    $scope = urlencode('business:read customer:read invoice:read');
    $state = wp_create_nonce('wave_oauth_state');
    $authorize_url = "https://api.waveapps.com/oauth2/authorize?client_id={$client_id}&response_type=code&scope={$scope}&redirect_uri={$redirect_uri}&state={$state}";

    ?>
    <div class="wrap">
        <h1>Wave Invoice Portal Settings</h1>
        <div style="display: flex; gap: 40px; align-items: flex-start;">
            <div style="flex: 1; min-width: 400px;">
                <?php if (!$client_id || !$client_secret || !$redirect_uri): ?>
                    <form method="post">
                        <?php wp_nonce_field('wave_save_settings_nonce'); ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="wave_client_id">Client ID</label></th>
                                <td><input type="text" name="wave_client_id" id="wave_client_id" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th><label for="wave_client_secret">Client Secret</label></th>
                                <td><input type="text" name="wave_client_secret" id="wave_client_secret" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th><label for="wave_redirect_uri">Redirect URI</label></th>
                                <td><input type="url" name="wave_redirect_uri" id="wave_redirect_uri" class="regular-text" required></td>
                            </tr>
                        </table>
                        <p><input type="submit" name="wave_save_settings" class="button button-primary" value="Save Settings"></p>
                    </form>
                <?php else: ?>
                    <table class="form-table">
                        <tr>
                            <th>Client ID</th>
                            <td><code><?php echo esc_html($client_id); ?></code></td>
                        </tr>
                        <tr>
                            <th>Client Secret</th>
                            <td><code>••••••••••••••••••••••••••••</code></td>
                        </tr>
                        <tr>
                            <th>Redirect URI</th>
                            <td><code><?php echo esc_url($redirect_uri); ?></code></td>
                        </tr>
                    </table>
                    <p><a href="<?php echo esc_url(wp_nonce_url(admin_url('options-general.php?page=wave-invoice&wave_disconnect=1'), 'wave_disconnect_nonce')); ?>" class="button-link" style="color:#dc3232;">Clear Settings</a></p>
                <?php endif; ?>

                <h2>Status</h2>
                <div style="padding:12px; margin:16px 0; background:<?php echo $connected ? '#e6f7ea' : '#fde8e8'; ?>; border-left:4px solid <?php echo $connected ? '#46b450' : '#dc3232'; ?>;">
                    <strong><?php echo $connected ? 'Connected to Wave' : 'Not Connected'; ?></strong>
                    <?php if ($connected): ?>
                        <p>Last connected: <?php echo date('F j, Y g:i a', strtotime($last_connected)); ?></p>
                        <?php if ($business_name): ?><p>Connected Business: <strong><?php echo esc_html($business_name); ?></strong></p><?php endif; ?>
                    <?php endif; ?>
                    <?php echo $connection_debug; ?>
                </div>

                <?php if ($connected): ?>
                    <form method="get" action="" style="margin-bottom: 20px;">
                        <input type="hidden" name="page" value="wave-invoice">
                        <input type="hidden" name="wave_disconnect" value="1">
                        <?php wp_nonce_field('wave_disconnect_nonce'); ?>
                        <button type="submit" class="button button-secondary" style="background:#dc3232; color:#fff;">Disconnect</button>
                    </form>

                    <form method="post" action="" style="margin-bottom: 20px;">
                        <?php wp_nonce_field('wave_sync_customers_nonce'); ?>
                        <input type="hidden" name="wave_sync_customers" value="1">
                        <label><input type="checkbox" name="wave_send_invites" value="1"> Send welcome emails to new users</label><br><br>
                        <button type="submit" class="button button-primary">Sync Wave Customers to WP Users</button>
                    </form>

                    <form method="post" action="" onsubmit="return confirm('Are you sure you want to remove all non-admin users?');" style="margin-bottom: 20px;">
                        <?php wp_nonce_field('wave_remove_wave_users_nonce'); ?>
                        <input type="hidden" name="wave_remove_wave_users" value="1">
                        <button type="submit" class="button" style="background: #c9302c; color: #fff;">Remove All Wave Users</button>
                    </form>

                    <?php
                    if (file_exists(WAVE_LOG_FILE)) {
                        $log_content = file_get_contents(WAVE_LOG_FILE);
                        echo '<details style="margin-top:30px;"><summary><strong>View Sync Log</strong></summary>';
                        echo '<pre style="max-height:300px; overflow:auto; background:#f8f8f8; padding:10px;">' . esc_html($log_content) . '</pre>';
                        echo '</details>';
                    }

                    wave_send_invoice_reminders_ui();
                    ?>
                <?php else: ?>
                    <?php if ($client_id && $redirect_uri): ?>
                        <a href="<?php echo esc_url($authorize_url); ?>" class="button button-primary">Connect to Wave</a>
                    <?php else: ?>
                        <p style="color:#dc3232;">Please enter and save your credentials before connecting.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div style="flex: 1; min-width: 300px; background:#f9f9f9; padding:20px; border-left: 4px solid #0073aa;">
                <h2 style="margin-top:0;">Creating an Application</h2>
                <ol style="padding-left:20px;">
                    <li>Make sure you are <a href="https://my.waveapps.com/login" target="_blank">logged into your Wave account</a>.</li>
                    <li>Go to <a href="https://developer.waveapps.com/hc/en-us/articles/360019762711-Manage-Applications" target="_blank">Manage Applications</a>.</li>
                    <li>Click <strong>"Get started"</strong>.</li>
                    <li>Fill out the form, accept terms, and click <strong>"Create application"</strong>.</li>
                    <li>Copy your <strong>Client ID</strong> and <strong>Client Secret</strong>.</li>
                    <li>Use Redirect URI: <code><?php echo esc_url(home_url('/oauth-callback/')); ?></code></li>
                    <li>Click <strong>"Create token"</strong> to generate access.</li>
                    <li>Use the token in the <code>Authorization</code> header.</li>
                    <li>Click <strong>"Revoke"</strong> to disable a token anytime.</li>
                </ol>
            </div>
        </div>
    </div>
    <?php
}




// ====================================
// ADMIN HOOK: HANDLE SYNC FORM SUBMIT
// ====================================
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;
    if (!isset($_POST['wave_sync_customers']) || !check_admin_referer('wave_sync_customers_nonce')) return;

    $results = wave_sync_customers_to_wp_users();

    if (is_array($results) && count($results)) {
        wave_append_log("[Admin Sync] Manual sync executed. Summary:\n" . implode("\n", $results));
        $summary = esc_html(array_shift($results));
        add_action('admin_notices', function () use ($summary) {
            echo '<div class="notice notice-success"><p><strong>Sync completed.</strong><br>' . $summary . '</p></div>';
        });
    } else {
        wave_append_log("[Admin Sync] No results returned from wave_sync_customers_to_wp_users().");
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Sync failed or returned no data.</strong></p></div>';
        });
    }
});

// ====================================
// ADMIN HOOK: HANDLE REMOVE USERS
// ====================================
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;
    if (!isset($_POST['wave_remove_all_users']) || !check_admin_referer('wave_remove_all_users_nonce')) return;

    $users = get_users(['role' => 'subscriber']);
    $removed = 0;

    foreach ($users as $user) {
        if (!user_can($user, 'manage_options')) {
            wp_delete_user($user->ID);
            $removed++;
        }
    }

    wave_append_log("[Admin Cleanup] Removed {$removed} synced subscriber(s) from WordPress.");

    add_action('admin_notices', function () use ($removed) {
        echo '<div class="notice notice-warning"><p><strong>Removed ' . $removed . ' synced user(s).</strong></p></div>';
    });
});


// ====================================
// CUSTOM ROLE ON PLUGIN ACTIVATION
// ====================================
function wave_register_custom_role() {
    add_role('wave_customer', 'Wave Customer', array('read' => true));
}
register_activation_hook(__FILE__, 'wave_register_custom_role');

// ====================================
// SYNC WAVE CUSTOMERS TO WP USERS
// ====================================
function wave_sync_customers_to_wp_users() {
    if (file_exists(WAVE_LOG_FILE)) {
        file_put_contents(WAVE_LOG_FILE, '');
    }

    $access_token   = get_option('wave_access_token');
    $client_id      = get_option('wave_client_id');
    $client_secret  = get_option('wave_client_secret');
    $redirect_uri   = get_option('wave_redirect_uri');
    $business_name  = get_option('wave_connected_business_name');
    $send_emails    = isset($_POST['wave_send_invites']) && $_POST['wave_send_invites'] === '1';

    if (!$access_token || !$client_id || !$client_secret || !$redirect_uri || !$business_name) {
        wave_append_log("[Wave Sync] Missing required values: access_token, client_id, client_secret, redirect_uri, business_name");
        return array('Missing required settings: access_token, client_id, client_secret, redirect_uri, business_name');
    }

    $query = '
        query {
            businesses {
                edges {
                    node {
                        id
                        name
                        customers {
                            edges {
                                node {
                                    id
                                    email
                                    firstName
                                    lastName
                                }
                            }
                        }
                    }
                }
            }
        }
    ';

    $response = wave_graphql_request($access_token, $query);
    wave_append_log("[Wave Sync] Raw customer response:\n" . print_r($response, true));

    if (!isset($response['data']['businesses']['edges']) || empty($response['data']['businesses']['edges'])) {
        wave_append_log("[Wave Sync] No businesses returned.");
        return array('No businesses returned from Wave.');
    }

    $target_business = null;
    foreach ($response['data']['businesses']['edges'] as $edge) {
        if ($edge['node']['name'] === $business_name) {
            $target_business = $edge['node'];
            break;
        }
    }

    if (!$target_business || !isset($target_business['customers']['edges'])) {
        wave_append_log("[Wave Sync] Target business not found or has no customers.");
        return array("No matching business or customers found for {$business_name}.");
    }

    $wave_customers = $target_business['customers']['edges'];
    $wave_emails = [];
    $results = [];
    $summary = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => 0, 'failed' => 0];

    foreach ($wave_customers as $entry) {
        $c = $entry['node'];
        $email = sanitize_email($c['email']);
        $first = sanitize_text_field($c['firstName']);
        $last = sanitize_text_field($c['lastName']);

        wave_append_log("[Wave Sync] Processing: {$email}");

        if (empty($email)) continue;
        $wave_emails[] = $email;

        if (email_exists($email)) {
            $user = get_user_by('email', $email);
            if ($user && ($user->first_name !== $first || $user->last_name !== $last)) {
                wp_update_user([
                    'ID' => $user->ID,
                    'first_name' => $first,
                    'last_name'  => $last,
                    'display_name' => $first . ' ' . $last,
                ]);
                wave_append_log("[Wave Sync] Updated user: {$email}");
                $summary['updated']++;
                $results[] = "Updated: {$email}";
            } else {
                wave_append_log("[Wave Sync] Skipped: {$email} (already exists)");
                $summary['skipped']++;
                $results[] = "Skipped: {$email} (already exists)";
            }
            wave_notify_new_invoices($user->ID, $email, $access_token);
            continue;
        }

        $username = sanitize_user(strtolower($first . $last));
        if (username_exists($username)) {
            $username .= '_' . time();
        }

        $password = wp_generate_password();
        $user_id = wp_create_user($username, $password, $email);

        if (is_wp_error($user_id)) {
            $error = $user_id->get_error_message();
            wave_append_log("[Wave Sync] Failed: {$email} ({$error})");
            $summary['failed']++;
            $results[] = "Failed: {$email} ({$error})";
        } else {
            wp_update_user([
                'ID'           => $user_id,
                'first_name'   => $first,
                'last_name'    => $last,
                'display_name' => $first . ' ' . $last,
                'role'         => 'subscriber',
            ]);

            if ($send_emails) {
                wp_mail(
                    $email,
                    'Welcome to Bert & Bird Rentals Invoice Portal',
                    "Hi {$first},\n\nWe've created your account. You can log in at:\n" . wp_login_url() . "\n\nUsername: {$email}\nUse 'Lost your password' to set your password.\n\nThanks!",
                    ['Content-Type: text/plain; charset=UTF-8']
                );
            }

            wave_append_log("[Wave Sync] Created: {$email}");
            $summary['added']++;
            $results[] = "Created user: {$email}";
            wave_notify_new_invoices($user_id, $email, $access_token);
        }
    }

    $existing_users = get_users(['role' => 'subscriber']);
    foreach ($existing_users as $user) {
        if (!in_array($user->user_email, $wave_emails)) {
            wp_delete_user($user->ID);
            wave_append_log("[Wave Sync] Deleted: {$user->user_email}");
            $summary['deleted']++;
            $results[] = "Deleted: {$user->user_email}";
        }
    }

    $summary_line = "Sync Summary: Added: {$summary['added']} | Updated: {$summary['updated']} | Skipped: {$summary['skipped']} | Deleted: {$summary['deleted']} | Failed: {$summary['failed']}";
    array_unshift($results, $summary_line);

    return $results;
}



// ====================================
// LOGGING FUNCTION
// ====================================
function wave_append_log($entry) {
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] {$entry}\n";

    // Attempt to write to file
    if (defined('WAVE_LOG_FILE')) {
        $written = @file_put_contents(WAVE_LOG_FILE, $line, FILE_APPEND);
        if ($written === false) {
            error_log("Wave Plugin Log Error: Failed to write to log file. Entry: " . $line);
        }
    } else {
        error_log("Wave Plugin Log Error: WAVE_LOG_FILE not defined. Entry: " . $line);
    }
}

// ====================================
// ADMIN TOOL: REMOVE ALL SYNCED USERS
// ====================================
function wave_remove_all_wave_users() {
    if (!current_user_can('manage_options')) return;
    if (!isset($_POST['wave_remove_wave_users']) || !check_admin_referer('wave_remove_wave_users_nonce')) return;

    $users = get_users([
        'role'   => 'subscriber',
        'fields' => ['ID', 'user_email'],
    ]);

    $deleted = 0;
    foreach ($users as $user) {
        if (user_can($user->ID, 'manage_options')) continue; // Skip admins
        wp_delete_user($user->ID);
        $deleted++;
    }

    wave_append_log("[Wave Cleanup] Removed {$deleted} subscriber users.");
    add_action('admin_notices', function () use ($deleted) {
        echo '<div class="notice notice-warning"><p><strong>Removed ' . $deleted . ' synced users.</strong></p></div>';
    });
}
add_action('admin_init', 'wave_remove_all_wave_users');



// ====================================
// ADMIN HOOK: PROCESS REMINDER FORM
// ====================================
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;
    if (!isset($_POST['wave_send_reminders']) || !check_admin_referer('wave_send_reminders_nonce')) return;

    $email = sanitize_email($_POST['reminder_email'] ?? '');
    $from = sanitize_text_field($_POST['reminder_from'] ?? '');
    $to = sanitize_text_field($_POST['reminder_to'] ?? '');

    if (!$email || !$from || !$to) {
        wave_append_log("[Reminder] Missing input data.");
        return;
    }

    $access_token = get_option('wave_access_token');
    if (!$access_token) return;

    $user = get_user_by('email', $email);
    if (!$user) {
        wave_append_log("[Reminder] No WP user found for {$email}");
        return;
    }

    wave_send_invoice_reminders($user->ID, $email, $from, $to, $access_token);
});

// ====================================
// DAILY SYNC EVENT
// ====================================
function wave_execute_daily_sync_event() {
    wave_append_log("[CRON DEBUG] wave_daily_sync_event triggered.");
    $results = wave_sync_customers_to_wp_users();

    if (is_array($results)) {
        wave_append_log("[Auto Sync] Daily sync executed. Summary:\n" . implode("\n", $results));
    } else {
        wave_append_log("[Auto Sync] No results returned from wave_sync_customers_to_wp_users().");
    }
}
add_action('wave_daily_sync_event', 'wave_execute_daily_sync_event');

if (!wp_next_scheduled('wave_daily_sync_event')) {
    wp_schedule_event(time(), 'daily', 'wave_daily_sync_event');
}


// ====================================
// PLUGIN ACTIVATION: FLUSH REWRITE RULES
// ====================================
register_activation_hook(__FILE__, function () {
    wave_register_oauth_endpoint();
    flush_rewrite_rules();
});


// ====================================
// FRONTEND REDIRECT FOR NON-ADMINS - REMOVE LATER MAYBE
// ====================================
add_action('template_redirect', function () {
    if (is_admin()) return;
    if (current_user_can('manage_options')) return;

    wp_redirect('https://www.google.com');
    exit;
});

// ====================================
// REDIRECT NON-ADMINS FROM WP-LOGIN
// ====================================
add_action('login_init', function () {
    if (!current_user_can('manage_options')) {
        wp_redirect('https://www.google.com');
        exit;
    }
});

