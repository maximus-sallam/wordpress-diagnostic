<?php
/**
 * Plugin Name: Diagnostic Logger
 * Description: Logs request info, SQL, and API calls for each request during a page load, identified via cookie-based diag_page_id.
 */

// Force-enable debugging and query logging, overriding wp-config.php if necessary
ini_set('display_errors', 0);
@define('WP_DEBUG', true);
@define('WP_DEBUG_LOG', true);
@define('WP_DEBUG_DISPLAY', false);
@define('SAVEQUERIES', true);

// Just in case constants were defined too early, also force at runtime
@ini_set('log_errors', 1);
@ini_set('display_errors', 0);

$client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];

if ($client_ip !== 'YOUR_IP_HERE') {
    return;
}

$log_dir = dirname(__DIR__) . '/logs';
$log_file = $log_dir . '/ip-diagnostic.log';
$test_log = $log_dir . '/test.log';

if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// Test log to confirm plugin is loading
file_put_contents($test_log, "MU plugin loaded at " . date('c') . "\n", FILE_APPEND);

// Start session for tracking per page load request count
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Generate or retrieve diag_page_id from cookie
if (!isset($_COOKIE['DIAG_PAGE_ID'])) {
    $page_id = bin2hex(random_bytes(6));
    setcookie('DIAG_PAGE_ID', $page_id, 0, '/');
    $_COOKIE['DIAG_PAGE_ID'] = $page_id;
}

$page_id = $_COOKIE['DIAG_PAGE_ID'] ?? null;
if (!$page_id) {
    file_put_contents($test_log, "No diag_page_id available\n", FILE_APPEND);
    return;
}

// Count requests in this page load
if (!isset($_SESSION['DIAG_PAGE_LOADS'])) {
    $_SESSION['DIAG_PAGE_LOADS'] = [];
}

if (!isset($_SESSION['DIAG_PAGE_LOADS'][$page_id])) {
    $_SESSION['DIAG_PAGE_LOADS'][$page_id] = 1;
} else {
    $_SESSION['DIAG_PAGE_LOADS'][$page_id]++;
}

$request_count = $_SESSION['DIAG_PAGE_LOADS'][$page_id];

// Initialize tracking
$GLOBALS['DIAGNOSTIC_API_CALLS'] = [];
$GLOBALS['__DIAG_START__'] = microtime(true);

// Track API calls in detail (including timing and response)
add_filter('pre_http_request', function ($pre, $args, $url) {
    $start = microtime(true);

    add_filter('http_response', function ($result, $r_args, $r_url) use ($start, $args, $url) {
        $duration = round(microtime(true) - $start, 4);

        $GLOBALS['DIAGNOSTIC_API_CALLS'][] = [
            'method'   => $args['method'] ?? 'GET',
            'url'      => $url,
            'args'     => $args,
            'response' => is_wp_error($result) ? $result->get_error_message() : wp_remote_retrieve_body($result),
            'code'     => is_wp_error($result) ? 'error' : wp_remote_retrieve_response_code($result),
            'time'     => $duration,
        ];

        return $result;
    }, 10, 3);

    return $pre;
}, 5, 3);

// Register shutdown function for logging
register_shutdown_function(function () use ($log_file, $page_id, $request_count) {
    global $wpdb;

    $timestamp = date('Y-m-d H:i:s');
    $page_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $pid = getmypid();
    $ppid = function_exists('posix_getppid') ? posix_getppid() : 'N/A';
    $proc_info = shell_exec("ps -p {$pid} -o %cpu,%mem,etime,cmd --no-headers");
    $proc_info = trim($proc_info) ?: 'Unavailable';
    $request_time = round(microtime(true) - ($GLOBALS['__DIAG_START__'] ?? microtime(true)), 4);

    $sql_query_count = is_array($wpdb->queries) ? count($wpdb->queries) : 'N/A';
    $sql_total_time = 'N/A';
    if (is_array($wpdb->queries)) {
        $sql_total_time = 0;
        foreach ($wpdb->queries as $query) {
            $sql_total_time += $query[1];
        }
        $sql_total_time = round($sql_total_time, 4);
    }

    $log_entry = "[{$timestamp}] PageID: {$page_id} | Request #{$request_count} to {$page_url}\n";
    $log_entry .= "Total Request Time: {$request_time} seconds\n";
    $log_entry .= "PHP PID: {$pid}, Parent PID: {$ppid}\n";
    $log_entry .= "Process Info: {$proc_info}\n";
    $log_entry .= "SQL Queries: {$sql_query_count}, SQL Time: {$sql_total_time} sec\n";

    // Detailed API call log
    if (!empty($GLOBALS['DIAGNOSTIC_API_CALLS'])) {
        $log_entry .= "---- API Call Details ----\n";
        foreach ($GLOBALS['DIAGNOSTIC_API_CALLS'] as $i => $call) {
            $log_entry .= sprintf("[%03d] %s %s\n", $i + 1, $call['method'], $call['url']);
            $log_entry .= "Status: {$call['code']} | Time: {$call['time']} sec\n";
            $log_entry .= "Args: " . var_export($call['args'], true) . "\n";
            $log_entry .= "Response: " . substr(var_export($call['response'], true), 0, 1000) . "\n\n";
        }
    } else {
        $log_entry .= "API Calls: 0\n";
    }

    if (is_array($wpdb->queries)) {
        $log_entry .= "---- SQL Query Details ----\n";
        foreach ($wpdb->queries as $i => $query) {
            $q = $query[0];
            $t = $query[1];
            $log_entry .= sprintf("[%03d] (%.5f sec)\n%s\n\n", $i + 1, $t, $q);
        }
    }

    $log_entry .= str_repeat('-', 80) . "\n\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
});

add_action('admin_footer', function () {
    ?>
    <script>
    (function() {
        const originalFetch = window.fetch;
        window.fetch = function(...args) {
            const [resource, config] = args;
            const start = performance.now();
            return originalFetch.apply(this, args).then(response => {
                const duration = performance.now() - start;
                navigator.sendBeacon('/wp-admin/admin-ajax.php?action=log_browser_request', JSON.stringify({
                    method: (config && config.method) || 'GET',
                    url: resource,
                    duration: duration.toFixed(2),
                    status: response.status,
                    diagPageId: getCookie('DIAG_PAGE_ID')
                }));
                return response;
            });
        };

        const open = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function(method, url) {
            this._method = method;
            this._url = url;
            return open.apply(this, arguments);
        };
        const send = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function() {
            const start = performance.now();
            this.addEventListener('loadend', function() {
                const duration = performance.now() - start;
                navigator.sendBeacon('/wp-admin/admin-ajax.php?action=log_browser_request', JSON.stringify({
                    method: this._method,
                    url: this._url,
                    duration: duration.toFixed(2),
                    status: this.status,
                    diagPageId: getCookie('DIAG_PAGE_ID')
                }));
            });
            return send.apply(this, arguments);
        };

        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
        }
    })();
    </script>
    <?php
});

add_action('wp_ajax_log_browser_request', function () {
    $log_dir = dirname(__DIR__) . '/logs';
    $log_file = $log_dir . '/browser-requests.log';

    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (!$data || empty($data['url'])) {
        http_response_code(400);
        exit;
    }

    $log = "[" . date('Y-m-d H:i:s') . "] DIAG_PAGE_ID: {$data['diagPageId']} | {$data['method']} {$data['url']} | {$data['status']} | {$data['duration']}ms\n";
    file_put_contents($log_file, $log, FILE_APPEND);

    wp_die();
});
