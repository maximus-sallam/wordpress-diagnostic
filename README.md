# Diagnostic Logger

A powerful WordPress diagnostics plugin that logs detailed information about SQL queries, API calls, and browser-side requests — per page load — using a unique tracking cookie. Built for developers and sysadmins who need granular visibility into request performance.

---

## 🔧 Features

- ✅ **SQL Query Logging** (via `SAVEQUERIES`)
- ✅ **HTTP API Call Tracing** (with timing and response body)
- ✅ **Browser-side Request Logging** (AJAX + fetch, via admin JS)
- ✅ **Unique Page Load Tracking** via `DIAG_PAGE_ID` cookie
- ✅ **Per-request log entries** with PID, memory, and runtime info
- ✅ **MU-compatible design**
- ✅ **Admin UI** to enable/disable each debug feature
- ✅ **Live AJAX log viewer** in admin
- ✅ **WP-CLI Integration**
  - Enable/disable individual or all features
  - View and tail logs via `--lines`
  - Clear logs individually or with `--all`

---

## 📦 Installation

1. Copy this plugin folder into `wp-content/plugins/diagnostic-logger/`
2. Set your IP in the plugin (for security):
   ```php
   if ($client_ip !== 'YOUR_IP_HERE') return;


Activate via Plugins > Diagnostic Logger

(Optional) Move into mu-plugins if you want forced loading

🧪 How It Works
Every frontend/admin request by the specified IP is tagged with a DIAG_PAGE_ID

SQL queries and API calls are logged in /logs/ip-diagnostic.log

JavaScript in the admin footer logs browser fetch/XHR calls to /logs/browser-requests.log

Logs include PID, SQL query time, and API call response bodies

Logs are grouped and counted per-page-load using sessions and cookies

⚙️ Admin Panel Usage
Navigate to Admin > Diagnostic Logger:

✅ Enable/Disable:

SQL Logging

API Call Logging

Browser Request Logging

📝 View Logs:

Diagnostic Log (ip-diagnostic.log)

Browser Log (browser-requests.log)

Refresh with AJAX buttons

🧨 WP-CLI Commands
The plugin supports full CLI control:

# View Status

wp diagnostic-logger status

# Enable/Disable Logging

wp diagnostic-logger enable sql
wp diagnostic-logger disable api
wp diagnostic-logger enable --all

# Clear Logs

wp diagnostic-logger clear diag
wp diagnostic-logger clear browser
wp diagnostic-logger clear --all


# View Logs

# Show entire diagnostic log
wp diagnostic-logger view diag

# Tail last 50 lines of browser log
wp diagnostic-logger view browser --lines=50

📁 Log Files

All logs are saved in:

/wp-content/logs/
├── ip-diagnostic.log         # SQL and API details
└── browser-requests.log      # Browser-side fetch/XHR logging
Make sure this directory is writable by the server.

🔒 Security Notes
Logs only for the configured IP (YOUR_IP_HERE) — change this before use

Output is not sanitized for browser logs — do not expose log viewer to untrusted users

Long API responses are truncated in logs (1000 chars)

💡 Use Cases
Debug slow admin pages or AJAX calls

Trace specific requests using DIAG_PAGE_ID

Track and profile custom API calls

View SQL bottlenecks per request

Use WP-CLI to monitor logs on remote or headless servers

✅ Requirements
WordPress 5.0+

PHP 7.4+

SAVEQUERIES enabled for SQL logging

WP-CLI (for CLI usage)
