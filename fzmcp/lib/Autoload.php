<?php
/**
 * fzWHMCS-MCP-AI - PSR-4-ish autoloader for the FzMcp namespace.
 *
 * The module deliberately avoids depending on the WHMCS Composer autoloader
 * so that the protocol/registry/permission layers can be booted in isolation
 * (see bin/selftest.php offline mode).
 */

if (!defined('FZMCP_LIB_DIR')) {
    define('FZMCP_LIB_DIR', __DIR__);
}

spl_autoload_register(function ($class) {
    $prefix = 'FzMcp\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = FZMCP_LIB_DIR . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
