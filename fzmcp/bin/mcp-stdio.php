<?php
/**
 * fzWHMCS-MCP-AI - stdio transport.
 *
 * Reads newline-delimited JSON-RPC 2.0 messages from STDIN and writes the
 * responses (one JSON object per line) to STDOUT. Intended for FazAI running
 * on the same host as WHMCS.
 *
 * Usage:
 *   /opt/cpanel/ea-php74/root/usr/bin/php modules/addons/fzmcp/bin/mcp-stdio.php
 *
 * stdio is a trusted, local transport: no Bearer token is required. Everything
 * else (permission model, schema validation, dispatch) is identical to HTTP.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "mcp-stdio.php deve ser executado via CLI.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/lib/Autoload.php';

use FzMcp\Bootstrap;
use FzMcp\JsonRpc;

if (!Bootstrap::loadWhmcs()) {
    fwrite(STDERR, "Aviso: WHMCS init.php nao pode ser carregado; localAPI indisponivel.\n");
    // We still start the loop so protocol/permission errors are reported
    // properly to the client instead of crashing.
}

$server = Bootstrap::server();

$stdin = fopen('php://stdin', 'r');
if ($stdin === false) {
    fwrite(STDERR, "Falha ao abrir STDIN.\n");
    exit(1);
}

while (($line = fgets($stdin)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $response = $server->handleRaw($line);
    if ($response !== null) {
        fwrite(STDOUT, JsonRpc::encode($response) . "\n");
        @fflush(STDOUT);
    }
}

fclose($stdin);
exit(0);
