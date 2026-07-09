<?php
/**
 * fzWHMCS-MCP-AI - offline self-test harness.
 *
 * Drives the MCP server in-process exactly as FazAI would, WITHOUT any network
 * or live MCP connection:
 *
 *   1. initialize                 -> asserts the response shape.
 *   2. tools/list                 -> asserts every tool has a valid schema.
 *   3. tools/call for EVERY tool  -> read tools dispatch; write tools MUST be
 *                                    blocked by the permission gate (default).
 *   4. malformed-args             -> asserts schema validation rejects bad input.
 *
 * Modes:
 *   live  - WHMCS init.php loaded, real localAPI() used (read tools hit the
 *           live system, read-only and safe).
 *   mock  - WHMCS cannot bootstrap from CLI; a MockApi returns canned,
 *           localAPI-shaped responses so protocol + schema + permission gate +
 *           dispatch are all still exercised offline.
 *
 * Run with ea-php74 (the stock CLI segfaults with ionCube):
 *   /opt/cpanel/ea-php74/root/usr/bin/php modules/addons/fzmcp/bin/selftest.php
 */

require_once dirname(__DIR__) . '/lib/Autoload.php';

use FzMcp\Bootstrap;
use FzMcp\Server;
use FzMcp\LocalApi;
use FzMcp\MockApi;
use FzMcp\Permissions;
use FzMcp\ToolRegistry;
use FzMcp\Config;

fwrite(STDOUT, "=====================================================================\n");
fwrite(STDOUT, " fzWHMCS-MCP-AI  -  Offline Self-Test Harness\n");
fwrite(STDOUT, "=====================================================================\n");

// ---------------------------------------------------------------------------
// Choose backend mode
// ---------------------------------------------------------------------------
$live = Bootstrap::loadWhmcs();
if ($live && function_exists('localAPI')) {
    $adminUser = Config::get('admin_user', '');
    $api  = new LocalApi($adminUser);
    $mode = 'LIVE (localAPI, leituras reais e seguras)';
} else {
    $api  = new MockApi();
    $mode = 'MOCK (WHMCS indisponivel no CLI; respostas simuladas)';
}

// Deterministic permission gate: registry defaults (write = bloqueado).
$perms  = Permissions::defaults();
$server = new Server($api, $perms);

fwrite(STDOUT, " Modo: {$mode}\n");
fwrite(STDOUT, " Backend: " . $api->mode() . "\n");
fwrite(STDOUT, "---------------------------------------------------------------------\n\n");

$fail = 0;
$pass = 0;

// ---------------------------------------------------------------------------
// 1) initialize
// ---------------------------------------------------------------------------
$init = $server->handleMessage(array(
    'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
    'params'  => array('protocolVersion' => '2025-06-18'),
));
$initOk = isset($init['result']['protocolVersion'])
    && isset($init['result']['serverInfo']['name'])
    && $init['result']['serverInfo']['name'] === 'fzWHMCS-MCP-AI'
    && isset($init['result']['capabilities']['tools']);
report('initialize', $initOk, $init['result']['serverInfo']['name'] . ' proto=' . $init['result']['protocolVersion']);
$initOk ? $pass++ : $fail++;

// notifications/initialized (should return nothing)
$note = $server->handleMessage(array('jsonrpc' => '2.0', 'method' => 'notifications/initialized'));
$noteOk = ($note === null);
report('notifications/initialized', $noteOk, 'sem resposta (correto)');
$noteOk ? $pass++ : $fail++;

// ping
$ping = $server->handleMessage(array('jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'));
$pingOk = isset($ping['result']);
report('ping', $pingOk, 'result presente');
$pingOk ? $pass++ : $fail++;

// unknown method
$unknown = $server->handleMessage(array('jsonrpc' => '2.0', 'id' => 3, 'method' => 'foo/bar'));
$unkOk = isset($unknown['error']['code']) && $unknown['error']['code'] === -32601;
report('metodo desconhecido -> -32601', $unkOk, 'erro JSON-RPC correto');
$unkOk ? $pass++ : $fail++;

// batch
$batch = $server->handleRaw('[{"jsonrpc":"2.0","id":10,"method":"ping"},{"jsonrpc":"2.0","id":11,"method":"ping"}]');
$batchOk = is_array($batch) && count($batch) === 2;
report('batch request (2)', $batchOk, 'duas respostas retornadas');
$batchOk ? $pass++ : $fail++;

// ---------------------------------------------------------------------------
// 2) tools/list  (valid schemas)
// ---------------------------------------------------------------------------
$list = $server->handleMessage(array('jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list'));
$tools = isset($list['result']['tools']) ? $list['result']['tools'] : array();
$schemaOk = !empty($tools);
foreach ($tools as $tl) {
    if (!isset($tl['name'], $tl['inputSchema']) || !is_array($tl['inputSchema'])
        || (isset($tl['inputSchema']['type']) && $tl['inputSchema']['type'] !== 'object')) {
        $schemaOk = false;
        break;
    }
}
report('tools/list schemas validos (' . count($tools) . ' tools)', $schemaOk, 'todos com inputSchema object');
$schemaOk ? $pass++ : $fail++;

fwrite(STDOUT, "\n");
fwrite(STDOUT, sprintf(" %-28s %-11s %-5s %-8s %-6s %s\n", 'TOOL', 'CATEGORIA', 'R/W', 'ESPERADO', 'RES', 'SAIDA (80c)'));
fwrite(STDOUT, " " . str_repeat('-', 105) . "\n");

// ---------------------------------------------------------------------------
// 3) tools/call for EVERY tool
// ---------------------------------------------------------------------------
$readPass = $readFail = $writePass = $writeFail = 0;
$byCat = array();

foreach (ToolRegistry::all() as $tool) {
    $args = sampleArgs($tool['inputSchema']);
    $env  = $server->callTool($tool['name'], $args);
    $res  = isset($env['result']) ? $env['result'] : array();
    $isError = !empty($res['isError']);
    $text = '';
    if (isset($res['content'][0]['text'])) {
        $text = $res['content'][0]['text'];
    }
    $snippet = trim(preg_replace('/\s+/', ' ', substr($text, 0, 80)));

    $cat = $tool['category'];
    if (!isset($byCat[$cat])) { $byCat[$cat] = array('read' => 0, 'write' => 0); }
    $byCat[$cat][$tool['rw']]++;

    if ($tool['rw'] === ToolRegistry::READ) {
        // Expect the gate to allow dispatch (not a permission error).
        $blocked = $isError && (strpos($text, 'requer o nivel') !== false || strpos($text, 'desabilitada') !== false);
        $ok = !$blocked;
        $expected = 'dispatch';
        $ok ? $readPass++ : $readFail++;
    } else {
        // Expect the gate to BLOCK the write by default.
        $ok = $isError && strpos($text, 'requer o nivel') !== false;
        $expected = 'bloqueio';
        $ok ? $writePass++ : $writeFail++;
    }

    $ok ? $pass++ : $fail++;
    fwrite(STDOUT, sprintf(
        " %-28s %-11s %-5s %-8s %-6s %s\n",
        substr($tool['name'], 0, 28),
        substr($cat, 0, 11),
        strtoupper(substr($tool['rw'], 0, 1)),
        $expected,
        $ok ? 'PASS' : 'FAIL',
        $snippet
    ));
}

// ---------------------------------------------------------------------------
// 4) malformed args rejected (read tool, wrong type on required field)
// ---------------------------------------------------------------------------
fwrite(STDOUT, "\n");
$bad = $server->callTool('GetEmails', array('clientid' => 'nao-e-inteiro'));
$badRes = isset($bad['result']) ? $bad['result'] : array();
$badOk = !empty($badRes['isError'])
    && isset($badRes['content'][0]['text'])
    && strpos($badRes['content'][0]['text'], 'Argumentos invalidos') !== false;
report('argumentos malformados rejeitados (GetEmails clientid=string)', $badOk,
    $badOk ? 'schema validou e rejeitou' : 'NAO rejeitou');
$badOk ? $pass++ : $fail++;

// missing required field
$bad2 = $server->callTool('GetInvoice', array());
$bad2Res = isset($bad2['result']) ? $bad2['result'] : array();
$bad2Ok = !empty($bad2Res['isError'])
    && strpos($bad2Res['content'][0]['text'], 'obrigatoria ausente') !== false;
report('campo obrigatorio ausente rejeitado (GetInvoice sem invoiceid)', $bad2Ok,
    $bad2Ok ? 'schema exigiu invoiceid' : 'NAO exigiu');
$bad2Ok ? $pass++ : $fail++;

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
fwrite(STDOUT, "\n=====================================================================\n");
fwrite(STDOUT, " RESUMO POR CATEGORIA (tools)\n");
fwrite(STDOUT, "---------------------------------------------------------------------\n");
$totR = $totW = 0;
foreach ($byCat as $cat => $c) {
    fwrite(STDOUT, sprintf(" %-14s leitura=%-3d escrita=%-3d\n", $cat, $c['read'], $c['write']));
    $totR += $c['read']; $totW += $c['write'];
}
fwrite(STDOUT, sprintf(" %-14s leitura=%-3d escrita=%-3d  total=%d\n", 'TOTAL', $totR, $totW, $totR + $totW));

fwrite(STDOUT, "\n=====================================================================\n");
fwrite(STDOUT, " RESULTADO\n");
fwrite(STDOUT, "---------------------------------------------------------------------\n");
fwrite(STDOUT, sprintf(" Modo backend .............. %s\n", $api->mode()));
fwrite(STDOUT, sprintf(" Tools de leitura .......... %d (dispatch OK: %d, falha: %d)\n", $totR, $readPass, $readFail));
fwrite(STDOUT, sprintf(" Tools de escrita .......... %d (bloqueadas OK: %d, falha: %d)\n", $totW, $writePass, $writeFail));
fwrite(STDOUT, sprintf(" Checagens totais .......... %d PASS / %d FAIL\n", $pass, $fail));
fwrite(STDOUT, " Status .................... " . ($fail === 0 ? "TODOS OS TESTES PASSARAM\n" : "HOUVE FALHAS\n"));
fwrite(STDOUT, "=====================================================================\n");

exit($fail === 0 ? 0 : 1);

// ===========================================================================
// helpers
// ===========================================================================
function report($label, $ok, $note)
{
    fwrite(STDOUT, sprintf(" [%s] %-55s %s\n", $ok ? 'PASS' : 'FAIL', $label, $note));
}

/**
 * Build a minimal valid argument set from a schema (required props only).
 */
function sampleArgs(array $schema)
{
    $args = array();
    $props = isset($schema['properties']) ? $schema['properties'] : array();
    $required = isset($schema['required']) ? $schema['required'] : array();
    foreach ($required as $name) {
        $p = isset($props[$name]) ? $props[$name] : array('type' => 'string');
        $args[$name] = sampleValue($p);
    }
    return $args;
}

function sampleValue(array $p)
{
    $type = isset($p['type']) ? $p['type'] : 'string';
    if (isset($p['enum']) && is_array($p['enum']) && !empty($p['enum'])) {
        return $p['enum'][0];
    }
    switch ($type) {
        case 'integer': return 1;
        case 'number':  return 1;
        case 'boolean': return true;
        case 'array':   return isset($p['items']) ? array(sampleValue($p['items'])) : array();
        case 'object':  return array();
        default:        return 'selftest';
    }
}
