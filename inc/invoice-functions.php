<?php

// ====================================
// HELPER: GET CONNECTED BUSINESS ID
// ====================================
function wave_get_business_id($access_token) {
    $business_id = get_option('wave_connected_business_id');
    if ($business_id) return $business_id;

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

    $response = wave_graphql_request($access_token, $query);
    if (
        isset($response['data']['businesses']['edges'][0]['node']['id']) &&
        isset($response['data']['businesses']['edges'][0]['node']['name'])
    ) {
        $node = $response['data']['businesses']['edges'][0]['node'];
        update_option('wave_connected_business_id', sanitize_text_field($node['id']));
        return $node['id'];
    }

    return null;
}


function wave_admin_oauth_button() {
    if (!current_user_can('manage_options')) return '';
    $client_id = 'EYszz9QG1w9yKtNzQb78llpwsrOu2wvRIc7Vtqgs';
    $redirect_uri = 'https://bertbirdrentals.com/oauth-callback/';
    $scope = urlencode('business:read customer:read invoice:read');
    $state = wp_create_nonce('wave_oauth_state');
    $authorize_url = "https://api.waveapps.com/oauth2/authorize?client_id={$client_id}&response_type=code&scope={$scope}&redirect_uri={$redirect_uri}&state={$state}";
    return '<a href="' . esc_url($authorize_url) . '" class="button">Connect Admin Account to Wave</a>';
}

function wave_get_customer_and_business_id($access_token, $user_email) {
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
                                }
                            }
                        }
                    }
                }
            }
        }
    ';

    $response = wave_graphql_request($access_token, $query);
    if (!$response || !isset($response['data']['businesses']['edges'])) return null;

    foreach ($response['data']['businesses']['edges'] as $business) {
        if ($business['node']['name'] === 'Bert & Bird Rentals') {
            foreach ($business['node']['customers']['edges'] as $customer) {
                if (strtolower($customer['node']['email']) === strtolower($user_email)) {
                    return array(
                        'business_id' => $business['node']['id'],
                        'customer_id' => $customer['node']['id'],
                    );
                }
            }
        }
    }

    return null;
}

function wave_render_invoice_portal() {
    if (!is_user_logged_in()) {
        $login_url = wp_login_url(get_permalink());
        return '<p><a href="' . esc_url($login_url) . '">Log in</a> to view your invoices.</p>';
    }

    $user = wp_get_current_user();
    $user_email = $user->user_email;
    $user_name = $user->display_name;

    $access_token = wave_get_valid_access_token();
    if (!$access_token) return '<p><strong>Missing access token.</strong></p>';

    $ids = wave_get_customer_and_business_id($access_token, $user_email);
    if (!$ids) return '<p><strong>No matching customer in Wave for your account.</strong></p>';

    $query = '
        query($businessId: ID!) {
            business(id: $businessId) {
                invoices {
                    edges {
                        node {
                            id
                            createdAt
                            dueDate
                            status
                            pdfUrl
                            customer { id }
                            total { raw }
                        }
                    }
                }
            }
        }
    ';

    $response = wave_graphql_request($access_token, $query, array('businessId' => $ids['business_id']));
    if (!isset($response['data']['business']['invoices']['edges'])) {
        return '<p><strong>No invoices found for your account.</strong></p>';
    }

    $filter = isset($_GET['status']) ? strtolower($_GET['status']) : 'all';
    $unpaid_statuses = ['unsent', 'viewed', 'overdue'];
    $total_balance = 0;
    $output = '<div class="wave-invoice-portal">';

    $output .= '<p style="margin-bottom: 20px;">Logged in as <strong>' . esc_html($user_name) . '</strong></p>';

    $output .= '<div class="wave-filters">';
    $output .= '<a href="?status=all" class="' . ($filter === 'all' ? 'active' : '') . '">All</a>';
    $output .= '<a href="?status=unpaid" class="' . ($filter === 'unpaid' ? 'active' : '') . '">Unpaid</a>';
    $output .= '<a href="?status=paid" class="' . ($filter === 'paid' ? 'active' : '') . '">Paid</a>';
    $output .= '</div>';

    $output .= '<table class="wave-invoice-table">';
    $output .= '<thead><tr><th>Date</th><th>Due</th><th>Status</th><th>Amount</th><th>Action</th></tr></thead><tbody>';

    $has_rows = false;
    foreach ($response['data']['business']['invoices']['edges'] as $invoice) {
        $node = $invoice['node'];
        if ($node['customer']['id'] !== $ids['customer_id']) continue;

        $status = strtolower($node['status']);
        if ($filter === 'unpaid' && !in_array($status, $unpaid_statuses)) continue;
        if ($filter === 'paid' && $status !== 'paid') continue;

        $has_rows = true;
        $amount = floatval($node['total']['raw']) / 100;
        if (!in_array($status, ['paid', 'voided'])) $total_balance += $amount;

        $output .= '<tr>';
        $output .= '<td>' . esc_html(date('Y-m-d', strtotime($node['createdAt']))) . '</td>';
        $output .= '<td>' . esc_html($node['dueDate']) . '</td>';
        $output .= '<td>' . ucfirst($status) . '</td>';
        $output .= '<td>$' . number_format($amount, 2) . '</td>';
        $output .= '<td><a href="' . esc_url($node['pdfUrl']) . '" target="_blank">' . ($status === 'paid' ? 'View' : 'Pay Now') . '</a></td>';
        $output .= '</tr>';
    }

    if (!$has_rows) {
        $output .= '<tr><td colspan="5">No invoices found for this filter.</td></tr>';
    }

    $output .= '</tbody></table>';

    $output .= '<div class="wave-balance">';
    $output .= '<strong>Outstanding Balance:</strong> $' . number_format($total_balance, 2);
    $output .= '</div>';

    $output .= '<button id="wave-export-csv" class="wave-export-btn">Export CSV</button>';
    $output .= '</div>';

    return $output;
}

function wave_notify_new_invoices($user_id, $email, $access_token) {
    if (!$user_id || !$email || !$access_token) return;

    $business_id = wave_get_business_id($access_token);
    if (!$business_id) return;

    $query = '
        query GetInvoices($businessId: ID!) {
            business(id: $businessId) {
                customerInvoices {
                    edges {
                        node {
                            id
                            status
                            customer { id }
                        }
                    }
                }
            }
        }
    ';

    $response = wave_graphql_request($access_token, $query, ['businessId' => $business_id]);
    if (!isset($response['data']['business']['customerInvoices']['edges'])) return;

    $user = get_user_by('ID', $user_id);
    if (!$user) return;

    $customer_id = wave_get_customer_id($access_token, $email);
    if (!$customer_id) return;

    $notified_key = 'wave_notified_invoices_' . $user_id;
    $notified_ids = get_user_meta($user_id, $notified_key, true);
    if (!is_array($notified_ids)) $notified_ids = [];

    $new_invoices = [];
    foreach ($response['data']['business']['customerInvoices']['edges'] as $invoice) {
        $node = $invoice['node'];
        if ($node['customer']['id'] === $customer_id && strtolower($node['status']) === 'unpaid') {
            if (!in_array($node['id'], $notified_ids)) {
                $new_invoices[] = $node['id'];
                $notified_ids[] = $node['id'];
            }
        }
    }

    if (!empty($new_invoices)) {
        wp_mail(
            $email,
            'You have a new invoice from Bert & Bird Rentals',
            "Hello,\n\nYou have a new unpaid invoice available in your portal:\nhttps://bertbirdrentals.com/invoices/\n\nThank you!",
            ['Content-Type: text/plain; charset=UTF-8']
        );
        update_user_meta($user_id, $notified_key, $notified_ids);
    }
}

// ====================================
// ADMIN UI: REMINDER FORM
// ====================================
function wave_send_invoice_reminders_ui() {
    if (!current_user_can('manage_options')) return;

    $users = get_users([
        'orderby' => 'display_name',
        'order' => 'ASC',
        'fields' => ['ID', 'display_name', 'user_email']
    ]);

    echo '<div style="margin-top: 40px; padding: 20px; border-top: 1px solid #ccc;">';
    echo '<h2>Send Invoice Reminders</h2>';
    echo '<form method="post">';
    wp_nonce_field('wave_send_reminders_nonce');
    echo '<input type="hidden" name="wave_send_reminders" value="1">';

    echo '<label for="reminder_email">Select Customer:</label><br>';
    echo '<select name="reminder_email" id="reminder_email" required>';
    echo '<option value="">-- Select --</option>';
    foreach ($users as $user) {
        echo '<option value="' . esc_attr($user->user_email) . '">' .
             esc_html($user->display_name . ' (' . $user->user_email . ')') .
             '</option>';
    }
    echo '</select><br><br>';

    echo '<label for="reminder_from">From Date:</label><br>';
    echo '<input type="date" name="reminder_from" required><br><br>';

    echo '<label for="reminder_to">To Date:</label><br>';
    echo '<input type="date" name="reminder_to" required><br><br>';

    echo '<input type="submit" class="button button-primary" value="Send Reminders">';
    echo '</form>';
    echo '</div>';
}


// ====================================
// SEND INVOICE REMINDERS
// ====================================
function wave_send_invoice_reminders($user_id, $email, $start_date, $end_date, $access_token) {
    wave_append_log("[Reminder] Function triggered for {$email} ({$start_date} to {$end_date})");

    if (!$user_id || !$email || !$access_token || !$start_date || !$end_date) {
        wave_append_log("[Reminder] Missing parameters. Aborting.");
        return;
    }

    $customer_info = wave_get_customer_and_business_id($access_token, $email);
    if (!$customer_info) {
        wave_append_log("[Reminder] No matching Wave customer for {$email}");
        return;
    }

    $query = '
        query GetInvoices($businessId: ID!) {
            business(id: $businessId) {
                invoices {
                    edges {
                        node {
                            id
                            createdAt
                            dueDate
                            status
                            pdfUrl
                            customer { id }
                            total { raw }
                        }
                    }
                }
            }
        }
    ';

    $invoices_response = wave_graphql_request($access_token, $query, [
        'businessId' => $customer_info['business_id']
    ]);

    if (!isset($invoices_response['data']['business']['invoices']['edges'])) {
        wave_append_log("[Reminder] No invoice data returned for {$email}");
        return;
    }

    $matching_invoices = [];
    foreach ($invoices_response['data']['business']['invoices']['edges'] as $invoice_edge) {
        $invoice = $invoice_edge['node'];
        $created = substr($invoice['createdAt'], 0, 10); // YYYY-MM-DD

        if (
            $invoice['customer']['id'] === $customer_info['customer_id'] &&
            strtolower($invoice['status']) !== 'paid' &&
            $created >= $start_date &&
            $created <= $end_date
        ) {
            $amount = number_format($invoice['total']['raw'] / 100, 2);
            $matching_invoices[] = "- Invoice ID: {$invoice['id']}\n  Date: {$created}\n  Amount: \${$amount}\n  Pay: {$invoice['pdfUrl']}";
        }
    }

    wave_append_log("[Reminder] Found " . count($matching_invoices) . " unpaid invoice(s) for {$email}");

    if (!empty($matching_invoices)) {
        $subject = 'Your Invoice Reminder';
        $message = "Hello,\n\nHere are your unpaid invoices from {$start_date} to {$end_date}:\n\n";
        $message .= implode("\n\n", $matching_invoices);
        $message .= "\n\nYou can also view them anytime here:\nhttps://bertbirdrentals.com/invoices/\n\nThanks!";

        $headers = ['Content-Type: text/plain; charset=UTF-8', 'From: Bert & Bird Rentals <support@bertbirdrentals.com>'];

        $sent = wp_mail($email, $subject, $message, $headers);

        wave_append_log("[Reminder] Email to {$email} was " . ($sent ? "sent" : "NOT sent"));
    } else {
        wave_append_log("[Reminder] No unpaid invoices in range for {$email}");
    }
}
