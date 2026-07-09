<?php
/**
 * fzWHMCS-MCP-AI - hooks.
 *
 * WHMCS auto-includes this file for active addon modules. The MCP server is
 * driven by its own transport endpoints (public/mcp.php, bin/mcp-stdio.php)
 * rather than by request hooks, so no runtime hooks are required.
 *
 * A single lightweight hook is registered to keep the tool catalogue in sync
 * with the database after a code deploy that adds new tools, without requiring
 * a manual module upgrade click.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Autoload.php';

add_hook('AdminAreaHeadOutput', 1, function ($vars) {
    // Only act on the fzmcp addon page, and only if the table already exists.
    if (!isset($vars['filename']) || strpos((string) $vars['filename'], 'addonmodules') === false) {
        return '';
    }
    if (!isset($_GET['module']) || $_GET['module'] !== 'fzmcp') {
        return '';
    }
    try {
        if (Capsule::schema()->hasTable(\FzMcp\Permissions::TABLE)
            && function_exists('fzmcp_seed_tools')) {
            fzmcp_seed_tools();
        }
    } catch (\Throwable $e) {
        // never break the admin area
    }
    return '';
});
