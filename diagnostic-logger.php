<?php
/**
 * Plugin Name: Diagnostic Logger
 * Description: Logs SQL, API, and browser requests. View logs in admin or CLI. WP-CLI support included.
 * Version 1.5
 * Author: Zebraflux
 */

@define('WP_DEBUG', true);
@define('WP_DEBUG_LOG', true);
@define('WP_DEBUG_DISPLAY', false);

$log_dir = dirname(__DIR__) . '/logs';
$log_file = $log_dir . '/ip-diagnostic.log';
$browser_log_file = $log_dir . '/browser-requests.log';
$test_log = $log_dir . '/test.log';

if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// === Skip on plugin admin page ===
if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'diagnostic-logger') return;

// === IP Filter ===
$client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
if ($client_ip !== 'YOUR_IP_HERE') return;

// === Enable runtime features ===
if (get_option('diag_logger_enable_sql', true)) {
    @define('SAVEQUERIES', true);
}
@ini_set('log_errors', 1);
@ini_set('display_errors', 0);

file_put_contents($test_log, "MU plugin loaded at " . date('c') . "\n", FILE_APPEND);

// === Session & Page ID ===
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!isset($_COOKIE['DIAG_PAGE_ID'])) {
    $page_id = bin2hex(random_bytes(6));
    setcookie('DIAG_PAGE_ID', $page_id, 0, '/');
    $_COOKIE['DIAG_PAGE_ID'] = $page_id;
}
$page_id = $_COOKIE['DIAG_PAGE_ID'] ?? null;
if (!$page_id) return;

if (!isset($_SESSION['DIAG_PAGE_LOADS'])) $_SESSION['DIAG_PAGE_LOADS'] = [];
$_SESSION['DIAG_PAGE_LOADS'][$page_id] = ($_SESSION['DIAG_PAGE_LOADS'][$page_id] ?? 0) + 1;
$request_count = $_SESSION['DIAG_PAGE_LOADS'][$page_id];

// === API Tracking ===
if (get_option('diag_logger_enable_api', true)) {
    $GLOBALS['DIAGNOSTIC_API_CALLS'] = [];
    $GLOBALS['__DIAG_START__'] = microtime(true);
    add_filter('pre_http_request', function ($pre, $args, $url) {
        $start = microtime(true);
        add_filter('http_response', function ($result, $r_args, $r_url) use ($start, $args, $url) {
            $duration = round(microtime(true) - $start, 4);
            $GLOBALS['DIAGNOSTIC_API_CALLS'][] = [
                'method' => $args['method'] ?? 'GET',
                'url' => $url,
                'args' => $args,
                'response' => is_wp_error($result) ? $result->get_error_message() : wp_remote_retrieve_body($result),
                'code' => is_wp_error($result) ? 'error' : wp_remote_retrieve_response_code($result),
                'time' => $duration,
            ];
            return $result;
        }, 10, 3);
        return $pre;
    }, 5, 3);
}

// === Shutdown Logger ===
register_shutdown_function(function () use ($log_file, $page_id, $request_count) {
    global $wpdb;
    $t = date('Y-m-d H:i:s');
    $url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $pid = getmypid();
    $ppid = function_exists('posix_getppid') ? posix_getppid() : 'N/A';
    $proc_info = trim(shell_exec("ps -p {$pid} -o %cpu,%mem,etime,cmd --no-headers") ?: 'Unavailable');
    $request_time = round(microtime(true) - ($GLOBALS['__DIAG_START__'] ?? microtime(true)), 4);
    $sql_count = is_array($wpdb->queries) ? count($wpdb->queries) : 'N/A';
    $sql_time = is_array($wpdb->queries) ? array_sum(array_column($wpdb->queries, 1)) : 'N/A';

    $log = "[{$t}] PageID: {$page_id} | Request #{$request_count} to {$url}\n";
    $log .= "Time: {$request_time}s | PID: {$pid} | Parent: {$ppid}\nProc: {$proc_info}\n";
    $log .= "SQL: {$sql_count} queries | Time: " . round($sql_time, 4) . "s\n";

    if (!empty($GLOBALS['DIAGNOSTIC_API_CALLS'])) {
        $log .= "---- API ----\n";
        foreach ($GLOBALS['DIAGNOSTIC_API_CALLS'] as $i => $call) {
            $log .= sprintf("[%03d] %s %s\n", $i + 1, $call['method'], $call['url']);
            $log .= "Status: {$call['code']} | Time: {$call['time']}s\n";
            $log .= "Args: " . var_export($call['args'], true) . "\n";
            $log .= "Response: " . substr(var_export($call['response'], true), 0, 1000) . "\n\n";
        }
    }

    if (is_array($wpdb->queries)) {
        $log .= "---- SQL ----\n";
        foreach ($wpdb->queries as $i => $q) {
            $log .= sprintf("[%03d] (%.5fs)\n%s\n\n", $i + 1, $q[1], $q[0]);
        }
    }

    $log .= str_repeat('-', 80) . "\n\n";
    file_put_contents($log_file, $log, FILE_APPEND);
});

// === Admin JS Logging ===
add_action('admin_footer', function () {
    if (!get_option('diag_logger_enable_browser', true)) return;
    ?>
    <script>
    (function() {
        const getCookie = name => (`; ${document.cookie}`).split(`; ${name}=`)[1]?.split(';')[0];
        const sendLog = (method, url, status, time) => {
            navigator.sendBeacon('/wp-admin/admin-ajax.php?action=log_browser_request', JSON.stringify({
                method, url, status, duration: time.toFixed(2), diagPageId: getCookie('DIAG_PAGE_ID')
            }));
        };
        const oldFetch = window.fetch;
        window.fetch = function(...args) {
            const start = performance.now();
            return oldFetch(...args).then(res => {
                sendLog((args[1]?.method || 'GET'), args[0], res.status, performance.now() - start);
                return res;
            });
        };
        const open = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function(m, u) { this._m = m; this._u = u; return open.apply(this, arguments); };
        const send = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function() {
            const start = performance.now();
            this.addEventListener('loadend', () => sendLog(this._m, this._u, this.status, performance.now() - start));
            return send.apply(this, arguments);
        };
    })();
    </script>
    <?php
});

// === AJAX Browser Logger ===
add_action('wp_ajax_log_browser_request', function () use ($browser_log_file) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['url'])) { http_response_code(400); exit; }
    $log = "[" . date('Y-m-d H:i:s') . "] DIAG_PAGE_ID: {$data['diagPageId']} | {$data['method']} {$data['url']} | {$data['status']} | {$data['duration']}ms\n";
    file_put_contents($browser_log_file, $log, FILE_APPEND);
    wp_die();
});

// === Admin UI ===
add_action('admin_menu', function () {
    add_menu_page('Diagnostic Logger', 'Diagnostic Logger', 'manage_options', 'diagnostic-logger', 'diag_logger_admin_page');
});
add_action('admin_init', function () {
    register_setting('diag_logger_settings', 'diag_logger_enable_sql');
    register_setting('diag_logger_settings', 'diag_logger_enable_api');
    register_setting('diag_logger_settings', 'diag_logger_enable_browser');
});
add_action('wp_ajax_diag_logger_refresh', function () use ($log_file, $browser_log_file) {
    $log = $_GET['log'] === 'browser' ? $browser_log_file : $log_file;
    echo file_exists($log) ? esc_textarea(file_get_contents($log)) : 'No log found.';
    wp_die();
});
function diag_logger_admin_page() {
    global $log_file, $browser_log_file;
    ?>
    <div class="wrap">
        <h1>Diagnostic Logger</h1>
        <form method="post" action="options.php">
            <?php settings_fields('diag_logger_settings'); ?>
            <table class="form-table">
                <tr><th>Enable SQL Logging</th><td><input type="checkbox" name="diag_logger_enable_sql" value="1" <?php checked(1, get_option('diag_logger_enable_sql', 1)); ?>></td></tr>
                <tr><th>Enable API Logging</th><td><input type="checkbox" name="diag_logger_enable_api" value="1" <?php checked(1, get_option('diag_logger_enable_api', 1)); ?>></td></tr>
                <tr><th>Enable Browser Logging</th><td><input type="checkbox" name="diag_logger_enable_browser" value="1" <?php checked(1, get_option('diag_logger_enable_browser', 1)); ?>></td></tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <hr>
        <h2>Diagnostic Log</h2><button onclick="refreshLog('diag')">Refresh</button>
        <textarea id="diag-log" rows="20" style="width:100%;"><?php echo esc_textarea(@file_get_contents($log_file)); ?></textarea>
        <h2>Browser Log</h2><button onclick="refreshLog('browser')">Refresh</button>
        <textarea id="browser-log" rows="10" style="width:100%;"><?php echo esc_textarea(@file_get_contents($browser_log_file)); ?></textarea>
    </div>
    <script>
    function refreshLog(type) {
        const box = document.getElementById(type + '-log');
        box.value = 'Loading...';
        fetch(ajaxurl + '?action=diag_logger_refresh&log=' + type).then(r => r.text()).then(t => box.value = t);
    }
    </script>
    <?php
}
register_activation_hook(__FILE__, function () {
    add_option('diag_logger_enable_sql', 1);
    add_option('diag_logger_enable_api', 1);
    add_option('diag_logger_enable_browser', 1);
});

// === WP-CLI Integration with --all and --lines
if (defined('WP_CLI') && WP_CLI) {
    class Diagnostic_Logger_CLI {
        private $options = [
            'sql' => 'diag_logger_enable_sql',
            'api' => 'diag_logger_enable_api',
            'browser' => 'diag_logger_enable_browser',
        ];
        private $log_dir, $diag_log, $browser_log;

        public function __construct() {
            $this->log_dir = dirname(__DIR__) . '/logs';
            $this->diag_log = $this->log_dir . '/ip-diagnostic.log';
            $this->browser_log = $this->log_dir . '/browser-requests.log';
        }

        public function enable($args, $assoc_args) {
            if (isset($assoc_args['all']) || ($args[0] ?? '') === '--all') {
                foreach (array_keys($this->options) as $opt) update_option($this->options[$opt], 1);
                WP_CLI::success("All logging options enabled.");
            } else {
                $this->toggle($args[0], 1);
            }
        }

        public function disable($args, $assoc_args) {
            if (isset($assoc_args['all']) || ($args[0] ?? '') === '--all') {
                foreach (array_keys($this->options) as $opt) update_option($this->options[$opt], 0);
                WP_CLI::success("All logging options disabled.");
            } else {
                $this->toggle($args[0], 0);
            }
        }

        public function status() {
            foreach ($this->options as $key => $opt) {
                WP_CLI::line("$key: " . (get_option($opt, 1) ? 'enabled' : 'disabled'));
            }
        }

        public function view($args, $assoc_args) {
            $type = $args[0] ?? 'diag';
            $lines = isset($assoc_args['lines']) ? intval($assoc_args['lines']) : null;
            $file = $type === 'browser' ? $this->browser_log : $this->diag_log;
            if (!file_exists($file)) WP_CLI::error("Log file not found.");
            $content = file($file);
            if ($lines) $content = array_slice($content, -$lines);
            WP_CLI::line(implode('', $content));
        }

        public function clear($args, $assoc_args) {
            if (isset($assoc_args['all']) || ($args[0] ?? '') === '--all') {
                @file_put_contents($this->diag_log, '');
                @file_put_contents($this->browser_log, '');
                WP_CLI::success("All logs cleared.");
            } else {
                $log = $args[0] === 'browser' ? $this->browser_log : $this->diag_log;
                if (file_exists($log)) {
                    file_put_contents($log, '');
                    WP_CLI::success("Cleared $args[0] log.");
                } else {
                    WP_CLI::warning("Log file not found.");
                }
            }
        }

        private function toggle($key, $val) {
            if (!isset($this->options[$key])) WP_CLI::error("Invalid option. Use: sql, api, browser or --all");
            update_option($this->options[$key], $val);
            WP_CLI::success(ucfirst($key) . " logging " . ($val ? 'enabled' : 'disabled'));
        }
    }
    WP_CLI::add_command('diagnostic-logger', 'Diagnostic_Logger_CLI');
}
