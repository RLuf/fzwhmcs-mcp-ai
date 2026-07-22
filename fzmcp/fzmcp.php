<?php
/**
 * fzWHMCS-MCP-AI
 * ------------------------------------------------------------------
 * A WHMCS addon module that IS an MCP (Model Context Protocol) server,
 * exposing WHMCS API actions as MCP tools consumed by the FazAI client.
 *
 * Standard WHMCS addon entry points live in this file; the protocol,
 * registry, permission and transport logic live under lib/ and are shared
 * with the three transports (public/mcp.php, bin/mcp-stdio.php) and the
 * offline harness (bin/selftest.php).
 *
 * WHMCS 8.13.1 / PHP 7.4+
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Autoload.php';

use FzMcp\ToolRegistry;
use FzMcp\Permissions;
use FzMcp\Auth;
use FzMcp\Config;
use FzMcp\Server;
use FzMcp\LocalApi;
use FzMcp\JsonRpc;

/**
 * Addon configuration + metadata.
 */
function fzmcp_config()
{
    return array(
        'name'        => 'fzWHMCS-MCP-AI',
        'version'     => '1.0.1',
        'author'      => 'Webstorage / FazAI',
        'language'    => 'portuguese-br',
        'description' => 'Servidor MCP (Model Context Protocol) para WHMCS. '
            . 'Expoe acoes da API como ferramentas MCP via HTTP, SSE e stdio, '
            . 'com permissao por ferramenta e bloqueio de escrita por padrao.',
        'fields'      => array(
            'admin_user' => array(
                'FriendlyName' => 'Usuario Admin da API',
                'Type'         => 'text',
                'Size'         => '30',
                'Default'      => '',
                'Description'  => 'Usuario administrador (com acesso a API) usado pelo localAPI. '
                    . 'Precisa ter permissao para as acoes que voce liberar.',
            ),
            'enable_http' => array(
                'FriendlyName' => 'Transporte HTTP/SSE',
                'Type'         => 'yesno',
                'Default'      => 'yes',
                'Description'  => 'Habilita o endpoint publico (public/mcp.php).',
            ),
            'allowed_origins' => array(
                'FriendlyName' => 'Origens web permitidas',
                'Type'         => 'text',
                'Size'         => '60',
                'Default'      => '',
                'Description'  => 'Lista separada por virgulas de origens HTTPS. Vazio bloqueia chamadas de navegador; clientes MCP sem Origin continuam permitidos.',
            ),
        ),
    );
}

/**
 * Activation: create + seed the permission table and generate the token.
 */
function fzmcp_activate()
{
    try {
        if (!Capsule::schema()->hasTable(Permissions::TABLE)) {
            Capsule::schema()->create(Permissions::TABLE, function ($table) {
                $table->increments('id');
                $table->string('tool_name', 100)->unique();
                $table->string('category', 60)->default('');
                $table->string('whmcs_action', 100)->default('');
                $table->string('rw', 8)->default('read');       // read|write
                $table->string('level', 12)->default('read');   // disabled|read|act
                $table->timestamp('updated_at')->nullable();
            });
        }

        fzmcp_seed_tools();

        return array(
            'status'      => 'success',
            'description' => 'fzWHMCS-MCP-AI ativado. Tabela de permissoes criada e '
                . count(ToolRegistry::all()) . ' ferramentas registradas. '
                . 'Defina o Usuario Admin da API e gere o token Bearer no painel do addon. '
                . 'Ferramentas de escrita permanecem bloqueadas ate o nivel "agir".',
        );
    } catch (\Throwable $e) {
        return array('status' => 'error', 'description' => 'Falha na ativacao: ' . $e->getMessage());
    }
}

/**
 * Deactivation: drop the permission table (token/config removed by WHMCS).
 */
function fzmcp_deactivate()
{
    try {
        if (Capsule::schema()->hasTable(Permissions::TABLE)) {
            Capsule::schema()->drop(Permissions::TABLE);
        }
        return array('status' => 'success', 'description' => 'fzWHMCS-MCP-AI desativado e tabela removida.');
    } catch (\Throwable $e) {
        return array('status' => 'error', 'description' => 'Falha ao desativar: ' . $e->getMessage());
    }
}

/**
 * Upgrade: ensure the table exists and (re)seed any newly-added tools without
 * disturbing existing operator choices.
 */
function fzmcp_upgrade($vars)
{
    try {
        if (!Capsule::schema()->hasTable(Permissions::TABLE)) {
            fzmcp_activate();
            return;
        }
        fzmcp_seed_tools();
        $storedToken = Config::get('bearer_token', '');
        if ($storedToken !== '' && !Auth::isHashedToken($storedToken)) {
            Config::set('bearer_token', Auth::hashToken($storedToken));
        }
    } catch (\Throwable $e) {
        // Surface nothing fatal on upgrade.
    }
}

/**
 * Seed/refresh the tool rows. Existing rows keep their operator-chosen level;
 * only new tools are inserted with their default level.
 */
function fzmcp_seed_tools()
{
    $existing = array();
    foreach (Capsule::table(Permissions::TABLE)->get(array('tool_name')) as $row) {
        $existing[is_array($row) ? $row['tool_name'] : $row->tool_name] = true;
    }
    foreach (ToolRegistry::all() as $tool) {
        if (isset($existing[$tool['name']])) {
            // Keep operator level, but refresh metadata.
            Capsule::table(Permissions::TABLE)
                ->where('tool_name', $tool['name'])
                ->update(array(
                    'category'     => $tool['category'],
                    'whmcs_action' => $tool['action'],
                    'rw'           => $tool['rw'],
                    'updated_at'   => date('Y-m-d H:i:s'),
                ));
            continue;
        }
        Capsule::table(Permissions::TABLE)->insert(array(
            'tool_name'    => $tool['name'],
            'category'     => $tool['category'],
            'whmcs_action' => $tool['action'],
            'rw'           => $tool['rw'],
            'level'        => ToolRegistry::defaultLevel($tool),
            'updated_at'   => date('Y-m-d H:i:s'),
        ));
    }
}

/**
 * Admin panel: two-column tool grid with per-tool permission selects, token
 * management, transport endpoint URLs and a "testar tool" runner.
 */
function fzmcp_output($vars)
{
    $modulelink = $vars['modulelink'];
    $notice = '';
    $testResult = null;
    $generatedToken = null;

    // ---- handle POST actions ------------------------------------------------
    $action = isset($_POST['fzmcp_action']) ? $_POST['fzmcp_action'] : '';
    if ($action !== '') {
        if (function_exists('check_token')) {
            check_token('WHMCS.admin.default');
        } else {
            $referer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
            $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
            if ($host === '' || stripos($referer, $host) === false) {
                $notice = 'notice-error:Requisicao rejeitada pela verificacao de origem.';
                $action = '';
            }
        }
    }
    if ($action === 'save_perms' && isset($_POST['level']) && is_array($_POST['level'])) {
        $valid = array('disabled', 'read', 'act');
        $count = 0;
        foreach ($_POST['level'] as $toolName => $level) {
            if (!in_array($level, $valid, true)) { continue; }
            if (ToolRegistry::get($toolName) === null) { continue; }
            Capsule::table(Permissions::TABLE)
                ->where('tool_name', $toolName)
                ->update(array('level' => $level, 'updated_at' => date('Y-m-d H:i:s')));
            $count++;
        }
        $notice = 'notice-success:Permissoes atualizadas (' . $count . ' ferramentas).';
    } elseif ($action === 'regen_token') {
        $generatedToken = Auth::generateToken();
        Config::set('bearer_token', Auth::hashToken($generatedToken));
        $notice = 'notice-success:Novo token Bearer gerado. Copie agora: ele nao sera exibido novamente.';
    } elseif ($action === 'test_tool') {
        $toolName = isset($_POST['test_tool_name']) ? $_POST['test_tool_name'] : '';
        $rawArgs  = isset($_POST['test_tool_args']) ? trim($_POST['test_tool_args']) : '';
        $args = array();
        if ($rawArgs !== '') {
            $decoded = json_decode($rawArgs, true);
            if (is_array($decoded)) { $args = $decoded; }
        }
        $adminUser = Config::get('admin_user', '');
        $server = new Server(new LocalApi($adminUser), Permissions::fromDatabase());
        $env = $server->callTool($toolName, $args);
        $testResult = array(
            'tool' => $toolName,
            'json' => JsonRpc::pretty($env),
        );
    }

    // ---- gather state -------------------------------------------------------
    $storedToken = Config::get('bearer_token', '');
    $tokenDisplay = $generatedToken !== null
        ? $generatedToken
        : ($storedToken !== '' ? '<TOKEN_JA_CONFIGURADO>' : '<GERE_UM_TOKEN>');
    $adminUser = Config::get('admin_user', '');
    $csrfField = function_exists('generate_token') ? generate_token('WHMCS.admin.default') : '';
    $levels = array();
    foreach (Capsule::table(Permissions::TABLE)->get(array('tool_name', 'level')) as $row) {
        $levels[is_array($row) ? $row['tool_name'] : $row->tool_name] = is_array($row) ? $row['level'] : $row->level;
    }

    $systemUrl = rtrim($vars['systemurl'] ?? '', '/');
    $httpUrl = $systemUrl . '/modules/addons/fzmcp/public/mcp.php';
    $sseUrl  = $httpUrl; // GET on the same endpoint opens the legacy SSE stream
    $stdioCmd = '/opt/cpanel/ea-php74/root/usr/bin/php modules/addons/fzmcp/bin/mcp-stdio.php';

    // group tools by category
    $byCat = array();
    foreach (ToolRegistry::all() as $tool) {
        $byCat[$tool['category']][] = $tool;
    }

    $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };

    // ---- render -------------------------------------------------------------
    ob_start();
    ?>
    <style>
        .fzmcp-wrap { font-size: 13px; }
        .fzmcp-grid { display: flex; flex-wrap: wrap; gap: 16px; }
        .fzmcp-col { flex: 1 1 46%; min-width: 380px; }
        .fzmcp-card { border: 1px solid #d7dde3; border-radius: 6px; margin-bottom: 16px; background: #fff; }
        .fzmcp-card h3 { margin: 0; padding: 8px 12px; background: #2b3a4a; color: #fff; border-radius: 6px 6px 0 0; font-size: 13px; }
        .fzmcp-tool { display: flex; align-items: center; justify-content: space-between; padding: 6px 12px; border-top: 1px solid #eef1f4; gap: 8px; }
        .fzmcp-tool:first-of-type { border-top: none; }
        .fzmcp-tool .meta { flex: 1; }
        .fzmcp-tool .name { font-weight: 600; }
        .fzmcp-badge { display: inline-block; font-size: 10px; padding: 1px 6px; border-radius: 3px; color: #fff; margin-left: 6px; }
        .fzmcp-badge.read { background: #2d8f47; }
        .fzmcp-badge.write { background: #c0392b; }
        .fzmcp-tool select { min-width: 150px; }
        .fzmcp-panel { border: 1px solid #d7dde3; border-radius: 6px; padding: 12px; margin-bottom: 16px; background: #f8fafb; }
        .fzmcp-panel code { background: #eceff1; padding: 2px 5px; border-radius: 3px; word-break: break-all; }
        .fzmcp-token { font-family: monospace; background: #fff8e1; border: 1px solid #ffe082; padding: 6px 8px; border-radius: 4px; word-break: break-all; display: inline-block; }
        .fzmcp-note { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .fzmcp-note.ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
        pre.fzmcp-out { background: #1e1e1e; color: #d4d4d4; padding: 12px; border-radius: 6px; max-height: 380px; overflow: auto; font-size: 12px; }
        .fzmcp-desc { color: #607d8b; font-size: 11px; }
    </style>

    <div class="fzmcp-wrap">
        <h2>fzWHMCS-MCP-AI &mdash; Servidor MCP para WHMCS</h2>

        <?php if ($notice !== ''): list($cls, $msg) = explode(':', $notice, 2); ?>
            <div class="fzmcp-note ok"><strong><?php echo $h($msg); ?></strong></div>
        <?php endif; ?>

        <div class="fzmcp-panel">
            <strong>Configuracao FazAI</strong>
            <pre style="margin:8px 0;background:#eceff1;padding:10px;border-radius:4px;">MCP_SERVERS=whmcs
MCP_WHMCS_TRANSPORT=http            # ou sse
MCP_WHMCS_URL=<?php echo $h($httpUrl); ?>

MCP_WHMCS_AUTH=Bearer:<?php echo $h($tokenDisplay); ?></pre>
            <div>
                <strong>Endpoints dos 3 transportes:</strong>
                <ul style="margin:6px 0 0 18px;">
                    <li>Streamable HTTP (POST): <code><?php echo $h($httpUrl); ?></code></li>
                    <li>SSE legado (GET abre o stream): <code><?php echo $h($sseUrl); ?></code></li>
                    <li>stdio (CLI local): <code><?php echo $h($stdioCmd); ?></code></li>
                </ul>
                <p class="fzmcp-desc">Recomendado: crie um rewrite para servir em <code><?php echo $h($systemUrl); ?>/mcp</code> (veja o README).</p>
            </div>
            <div style="margin-top:10px;">
                <strong>Usuario Admin da API:</strong>
                <?php echo $adminUser !== '' ? '<code>' . $h($adminUser) . '</code>' : '<span style="color:#c0392b;">nao definido (configure em Configuracoes do modulo)</span>'; ?>
            </div>
            <div style="margin-top:10px;">
                <strong>Token Bearer:</strong>
                <span class="fzmcp-token"><?php echo $h($tokenDisplay); ?></span>
                <form method="post" action="<?php echo $h($modulelink); ?>" style="display:inline;" onsubmit="return confirm('Gerar um novo token invalida o token atual. Continuar?');">
                    <?php echo $csrfField; ?>
                    <input type="hidden" name="fzmcp_action" value="regen_token">
                    <button type="submit" class="btn btn-sm btn-warning">Regenerar token</button>
                </form>
            </div>
        </div>

        <!-- Testar tool -->
        <div class="fzmcp-panel">
            <strong>Testar ferramenta</strong>
            <form method="post" action="<?php echo $h($modulelink); ?>">
                <?php echo $csrfField; ?>
                <input type="hidden" name="fzmcp_action" value="test_tool">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px;">
                    <select name="test_tool_name" class="form-control" style="width:auto;">
                        <?php foreach (ToolRegistry::all() as $tl): ?>
                            <option value="<?php echo $h($tl['name']); ?>"><?php echo $h($tl['category'] . ' / ' . $tl['name'] . ' (' . $tl['rw'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="test_tool_args" class="form-control" style="flex:1;min-width:260px;" placeholder='Argumentos JSON, ex: {"limitnum":5}'>
                    <button type="submit" class="btn btn-primary">Executar tools/call</button>
                </div>
                <p class="fzmcp-desc">O teste passa pelo mesmo gate de permissao do MCP. Ferramentas de escrita sem nivel "agir" serao bloqueadas.</p>
            </form>
            <?php if ($testResult): ?>
                <div style="margin-top:8px;">
                    <strong>Resultado de <?php echo $h($testResult['tool']); ?>:</strong>
                    <pre class="fzmcp-out"><?php echo $h($testResult['json']); ?></pre>
                </div>
            <?php endif; ?>
        </div>

        <!-- Permissoes por ferramenta -->
        <form method="post" action="<?php echo $h($modulelink); ?>">
            <?php echo $csrfField; ?>
            <input type="hidden" name="fzmcp_action" value="save_perms">
            <div style="margin-bottom:10px;">
                <button type="submit" class="btn btn-success">Salvar permissoes</button>
                <span class="fzmcp-desc" style="margin-left:8px;">
                    Niveis: <strong>desabilitado</strong> (oculto) &middot;
                    <strong>informar</strong> (leitura/registrar) &middot;
                    <strong>informar e agir</strong> (permite escrita).
                    Escrita so executa em "informar e agir".
                </span>
            </div>
            <div class="fzmcp-grid">
                <?php
                $cats = array_keys($byCat);
                $mid = (int) ceil(count($cats) / 2);
                $columns = array(array_slice($cats, 0, $mid), array_slice($cats, $mid));
                foreach ($columns as $colCats): ?>
                    <div class="fzmcp-col">
                        <?php foreach ($colCats as $cat): ?>
                            <div class="fzmcp-card">
                                <h3><?php echo $h($cat); ?> (<?php echo count($byCat[$cat]); ?>)</h3>
                                <?php foreach ($byCat[$cat] as $tool):
                                    $lvl = isset($levels[$tool['name']]) ? $levels[$tool['name']] : ToolRegistry::defaultLevel($tool);
                                    ?>
                                    <div class="fzmcp-tool">
                                        <div class="meta">
                                            <span class="name"><?php echo $h($tool['name']); ?></span>
                                            <span class="fzmcp-badge <?php echo $tool['rw']; ?>"><?php echo $tool['rw'] === 'write' ? 'ESCRITA' : 'LEITURA'; ?></span>
                                            <div class="fzmcp-desc"><?php echo $h($tool['description']); ?></div>
                                        </div>
                                        <select name="level[<?php echo $h($tool['name']); ?>]">
                                            <option value="disabled" <?php echo $lvl === 'disabled' ? 'selected' : ''; ?>>desabilitado</option>
                                            <option value="read" <?php echo $lvl === 'read' ? 'selected' : ''; ?>>informar</option>
                                            <option value="act" <?php echo $lvl === 'act' ? 'selected' : ''; ?>>informar e agir</option>
                                        </select>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:10px;">
                <button type="submit" class="btn btn-success">Salvar permissoes</button>
            </div>
        </form>
    </div>
    <?php
    echo ob_get_clean();
}
