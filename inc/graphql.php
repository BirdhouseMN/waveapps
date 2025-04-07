<?php

function wave_debug_log($message) {
    $log_file = WP_CONTENT_DIR . '/wave-oauth-debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[{$timestamp}] {$message}\n";
    file_put_contents($log_file, $entry, FILE_APPEND);
}


function wave_graphql_request($access_token, $query, $variables = array()) {
    $response = wp_remote_post('https://gql.waveapps.com/graphql/public', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json',
        ),
        'body' => json_encode(array(
            'query'     => $query,
            'variables' => $variables,
        )),
    ));

    if (is_wp_error($response)) return null;
    return json_decode(wp_remote_retrieve_body($response), true);
}

// ====================================
// ACCESS TOKEN VALIDATOR / REFRESH
// ====================================
function wave_get_valid_access_token() {
    $access_token = get_option('wave_access_token');
    $refresh_token = get_option('wave_refresh_token');
    $client_id = get_option('wave_client_id');
    $client_secret = get_option('wave_client_secret');

    if (!$access_token || !$refresh_token || !$client_id || !$client_secret) return null;

    $test = wave_graphql_request($access_token, '{ viewer { id } }');
    $unauthorized = false;

    if (isset($test['errors'])) {
        foreach ($test['errors'] as $error) {
            if (strtolower($error['message']) === 'invalid request, authentication expired.') {
                $unauthorized = true;
                break;
            }
        }
    }

    if ($unauthorized) {
        $token_url = 'https://api.waveapps.com/oauth2/token/';

        $refresh = wp_remote_post($token_url, array(
            'body' => array(
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh_token,
            ),
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
        ));

        if (!is_wp_error($refresh)) {
            $new_tokens = json_decode(wp_remote_retrieve_body($refresh), true);
            if (isset($new_tokens['access_token'])) {
                update_option('wave_access_token', sanitize_text_field($new_tokens['access_token']));
                return $new_tokens['access_token'];
            }
        }

        return null;
    }

    return $access_token;
}

// ====================================
// OAUTH EXCHANGE HANDLER
// ====================================
function wave_exchange_code_for_tokens($code) {
    $client_id     = get_option('wave_client_id');
    $client_secret = get_option('wave_client_secret');
    $redirect_uri  = get_option('wave_redirect_uri');

    if (!$client_id || !$client_secret || !$redirect_uri) {
        wave_debug_log("[OAuth Exchange] Missing values — client_id: " . ($client_id ?: 'MISSING') . ", client_secret: " . ($client_secret ? 'SET' : 'MISSING') . ", redirect_uri: " . ($redirect_uri ?: 'MISSING'));
        return null;
    }

    $token_url = 'https://api.waveapps.com/oauth2/token/';

    $post_body = array(
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $redirect_uri,
    );

    wave_debug_log("[OAuth Exchange] Sending token request:\n" . print_r($post_body, true));

    $response = wp_remote_post($token_url, array(
        'body'    => $post_body,
        'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
    ));

    if (is_wp_error($response)) {
        wave_debug_log("[OAuth Exchange] WP Error: " . $response->get_error_message());
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    wave_debug_log("[OAuth Exchange] Raw response:\n" . $body);

    $json = json_decode($body, true);

    if (!isset($json['access_token'])) {
        wave_debug_log("[OAuth Exchange] Failed to retrieve access_token.");
        return null;
    }

    wave_debug_log("[OAuth Exchange] Access token received.");
    return $json;
}


