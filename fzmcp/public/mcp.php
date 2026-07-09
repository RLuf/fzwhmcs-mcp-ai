<?php
/**
 * fzWHMCS-MCP-AI - Public MCP endpoint (Streamable HTTP + legacy SSE).
 *
 * URL (direct):
 *   https://webstorage.net.br/financeiro/modules/addons/fzmcp/public/mcp.php
 * URL (aliased, recommended):
 *   https://webstorage.net.br/mcp     (see README for the rewrite rule)
 *
 * Transports served here:
 *   - Streamable HTTP : POST JSON-RPC 2.0. Response is JSON, or an SSE event
 *     stream when the client sends "Accept: text/event-stream".
 *   - Legacy SSE      : GET opens a text/event-stream, receives an "endpoint"
 *     event, then POSTs requests to that endpoint (?session=<id>); responses
 *     are delivered back over the open stream.
 *
 * Auth: Authorization: Bearer <token>  (401 otherwise). stdio is exempt.
 */

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once dirname(__DIR__) . '/lib/Autoload.php';

use FzMcp\Bootstrap;
use FzMcp\Auth;
use FzMcp\JsonRpc;
use FzMcp\SessionQueue;

@set_time_limit(0);
ignore_user_abort(true);

if (!Bootstrap::loadWhmcs()) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo JsonRpc::encode(JsonRpc::error(null, JsonRpc::INTERNAL_ERROR, 'WHMCS nao pode ser inicializado.'));
    exit;
}

// ---------------------------------------------------------------------------
// CORS / preflight
// ---------------------------------------------------------------------------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Session-Id, Accept, Last-Event-ID');
header('Access-Control-Expose-Headers: Mcp-Session-Id');

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------------------
// Collect request headers (portable across SAPIs)
// ---------------------------------------------------------------------------
$headers = array();
if (function_exists('getallheaders')) {
    $headers = getallheaders();
}
if (empty($headers)) {
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
            $headers[$name] = $v;
        }
    }
}

// ---------------------------------------------------------------------------
// Authentication (Bearer)
// ---------------------------------------------------------------------------
$configuredToken = Bootstrap::token();
$presented = Auth::extractToken($headers);
if ($configuredToken === '' || !Auth::verify($presented, $configuredToken)) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="fzmcp"');
    header('Content-Type: application/json');
    echo JsonRpc::encode(JsonRpc::error(null, JsonRpc::UNAUTHORIZED, 'Nao autorizado: token Bearer ausente ou invalido.'));
    exit;
}

$server = Bootstrap::server();

// Session id (streamable HTTP reconnection / legacy SSE routing)
$sessionId = null;
foreach ($headers as $k => $v) {
    if (strtolower($k) === 'mcp-session-id') {
        $sessionId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $v);
    }
}
if (isset($_GET['session'])) {
    $sessionId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $_GET['session']);
}

$accept = isset($headers['Accept']) ? $headers['Accept'] : (isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '');
$wantsStream = stripos($accept, 'text/event-stream') !== false;

// ---------------------------------------------------------------------------
// GET  ->  open a legacy SSE stream
// ---------------------------------------------------------------------------
if ($method === 'GET') {
    if (!$sessionId) {
        $sessionId = SessionQueue::newId();
    }
    $queue = new SessionQueue($sessionId);

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // disable nginx buffering
    header('Mcp-Session-Id: ' . $sessionId);
    while (ob_get_level() > 0) { ob_end_flush(); }

    // Tell the client where to POST its JSON-RPC requests.
    $self = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost')
        . (isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '/mcp');
    $endpoint = $self . '?session=' . $sessionId;

    echo "event: endpoint\n";
    echo 'data: ' . $endpoint . "\n\n";
    @flush();

    $start = time();
    $maxLifetime = 600; // 10 minutes then client reconnects
    while (!connection_aborted() && (time() - $start) < $maxLifetime) {
        foreach ($queue->drain() as $payload) {
            echo "event: message\n";
            echo 'data: ' . str_replace("\n", "\ndata: ", $payload) . "\n\n";
            @flush();
        }
        // keep-alive comment
        echo ": ping\n\n";
        @flush();
        usleep(500000); // 0.5s poll
    }
    $queue->destroy();
    exit;
}

// ---------------------------------------------------------------------------
// POST ->  Streamable HTTP request, or legacy SSE message delivery
// ---------------------------------------------------------------------------
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $response = $server->handleRaw($raw);

    // Legacy SSE delivery: a session was supplied -> push the response onto
    // the open GET stream and acknowledge with 202.
    if (isset($_GET['session']) && $sessionId) {
        if ($response !== null) {
            $queue = new SessionQueue($sessionId);
            $queue->push(JsonRpc::encode($response));
        }
        http_response_code(202);
        header('Content-Type: application/json');
        echo JsonRpc::encode(array('status' => 'accepted'));
        exit;
    }

    // Pure notification (no id) -> 202 with empty body.
    if ($response === null) {
        http_response_code(202);
        exit;
    }

    if (!$sessionId) {
        $sessionId = SessionQueue::newId();
    }
    header('Mcp-Session-Id: ' . $sessionId);

    // Streamable HTTP: stream the single response as SSE if the client asked.
    if ($wantsStream) {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) { ob_end_flush(); }
        echo "event: message\n";
        echo 'data: ' . str_replace("\n", "\ndata: ", JsonRpc::encode($response)) . "\n\n";
        @flush();
        exit;
    }

    header('Content-Type: application/json');
    echo JsonRpc::encode($response);
    exit;
}

http_response_code(405);
header('Allow: GET, POST, OPTIONS');
header('Content-Type: application/json');
echo JsonRpc::encode(JsonRpc::error(null, JsonRpc::INVALID_REQUEST, 'Metodo HTTP nao suportado.'));
