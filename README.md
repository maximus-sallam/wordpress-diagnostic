# Diagnostic Logger for WordPress

A must-use (MU) plugin for advanced diagnostics of WordPress page loads. It logs SQL queries, HTTP API calls, browser-side network activity, and server-side metrics — all tied together by a unique page request ID.

---

## 🚀 Why Use This Plugin?

This tool is built to help you:

- Debug performance issues
- Trace unexpected API requests
- Identify heavy or repeated SQL queries
- Monitor what happens during every WordPress page load

---

## 🔍 What It Captures

- SQL queries (with count and total execution time)
- WordPress HTTP API requests (`wp_remote_get()`, etc.)
- JavaScript-initiated `fetch()` and `XMLHttpRequest` calls
- Page execution time (PHP runtime)
- CPU and memory usage (via `ps`, if supported)
- Request count per page view (tracked via PHP session)
- All data grouped by a unique `DIAG_PAGE_ID` cookie

---

## 📁 Installation Instructions

1. Copy the plugin file into your WordPress MU plugin directory:

   `wp-content/mu-plugins/diagnostic-logger.php`

2. Open the plugin file and find the line that checks your IP address.

   Replace `'YOUR_IP_HERE'` with your actual IP address to ensure diagnostics only run when you're accessing the site.

3. Ensure the following directory exists and is writable:

   `wp-content/logs/`

   If it doesn't exist, the plugin will attempt to create it automatically.

---

## 🧪 How to Confirm It's Working

After installing:

- Visit any page on your site
- Then check these files inside `wp-content/logs/`:
  - `test.log` — confirms the plugin loaded
  - `ip-diagnostic.log` — main log for SQL, API calls, and server metrics
  - `browser-requests.log` — logs browser-side activity like `fetch()` and AJAX

---

## ✅ Features Summary

| Feature                      | Description                                                             |
|------------------------------|-------------------------------------------------------------------------|
| SQL Query Logging            | Tracks all SQL queries with timing and details                          |
| WordPress API Logging        | Logs all `wp_remote_*()` calls with status and response                 |
| Browser Network Logging      | Captures `fetch()` and `XMLHttpRequest` data using `sendBeacon()`       |
| Request Grouping             | Ties everything to a `DIAG_PAGE_ID` cookie per page load                |
| Request Counting             | Uses PHP sessions to count sequential hits within the same load         |
| Server Metrics               | Includes CPU usage, memory, and process info                            |
| Session & Cookie Isolation   | Makes analyzing grouped behavior easy across multiple requests          |

---

## ⚠️ Important Usage Notes

- This plugin forces:
  - `WP_DEBUG = true`
  - `WP_DEBUG_LOG = true`
  - `SAVEQUERIES = true`

- It is **not intended for long-term or production use**.  
  Logs will grow quickly and the overhead may impact performance.

---

## 🧹 When You're Done

Once you've completed diagnostics:

- Delete the plugin from the `mu-plugins/` directory
- Remove the `wp-content/logs/` directory if no longer needed

---

## 📜 License

This diagnostic plugin is provided as-is for internal use only.  
No support or warranty is provided.

---
