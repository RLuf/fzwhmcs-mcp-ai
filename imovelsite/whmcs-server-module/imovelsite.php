<?php
/**
 * WHMCS Provisioning Module — ImovelSite Provisioner (REST)
 *
 * Substitui modules/servers/imovelsiteclone (SSH). Provisiona sites de corretor
 * chamando a API REST do plugin WordPress "imovelsite" no servidor RED
 * (red.webstorage.com.br), com autenticacao HTTP Basic (Application Password).
 *
 * Fluxo: POST /sites (job assincrono) -> poll GET /sites/{slug}/status ->
 * grava dominio/usuario/senha no servico (tblhosting) e o JSON da caixa de
 * e-mail em mod_imovelsite_log (usado pelo addon nos merge fields de e-mail).
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Imovelsite\ApiClient;
use WHMCS\Module\Server\Imovelsite\JobStore;

require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/JobStore.php';

function imovelsite_MetaData(): array
{
    return [
        'DisplayName'                => 'ImovelSite Provisioner (REST)',
        'APIVersion'                 => '1.1',
        'RequiresServer'             => true,
        'DefaultSSLPort'             => '443',
        'ServiceSingleSignOnLabel'   => 'Acessar painel WordPress',
    ];
}

function imovelsite_ConfigOptions(): array
{
    return [
        'api_base_path' => [
            'FriendlyName' => 'Caminho base da API',
            'Type'         => 'text',
            'Size'         => '40',
            'Default'      => '/wp-json/imovelsite/v1',
            'Description'  => 'Anexado a https://{hostname do servidor}',
        ],
        'request_timeout' => [
            'FriendlyName' => 'Timeout por requisição (s)',
            'Type'         => 'text',
            'Size'         => '5',
            'Default'      => '30',
        ],
        'poll_max_wait' => [
            'FriendlyName' => 'Espera máxima do provisionamento (s)',
            'Type'         => 'text',
            'Size'         => '5',
            'Default'      => '150',
            'Description'  => 'Depois disso o job segue no cron do servidor',
        ],
        'create_mailbox' => [
            'FriendlyName' => 'Criar caixa de e-mail',
            'Type'         => 'yesno',
            'Default'      => 'on',
            'Description'  => 'Criar caixa de e-mail junto com o site',
        ],
    ];
}

/**
 * Registra chamada remota no Module Log, mascarando credenciais.
 *
 * @param array        $params  Parametros do modulo (para replace vars).
 * @param string       $action  Nome da acao exibido no log.
 * @param array        $request Request sanitizado.
 * @param array        $result  Retorno do ApiClient (['code','body','raw']).
 */
function imovelsite_logCall(array $params, string $action, array $request, array $result): void
{
    $replaceVars = [];
    foreach (['serveraccesshash', 'serverpassword'] as $key) {
        if (!empty($params[$key]) && is_string($params[$key])) {
            $replaceVars[] = $params[$key];
        }
    }
    logModuleCall(
        'imovelsite',
        $action,
        ApiClient::sanitize($request),
        $result['raw'] ?? '',
        ApiClient::sanitize($result['body'] ?? []),
        $replaceVars
    );
}

/**
 * Slug automatico a partir do nome do cliente (portado de imovelsiteclone_slug):
 * translitera acentos, remove pontuacao, descarta particulas/sufixos
 * (da/de/do/dos/das/e/di/del/la/van/von/jr/junior/filho/filha/neto/neta/
 * sobrinho/segundo), junta primeira+ultima palavra, corta em 20 chars.
 */
function imovelsite_autoSlug(string $nome): string
{
    $nome = (string) @iconv('UTF-8', 'ASCII//TRANSLIT', $nome);
    $nome = strtolower(preg_replace('/[^a-zA-Z ]+/', ' ', $nome));
    $nome = trim(preg_replace('/\s+/', ' ', $nome));
    $stop = ['da', 'de', 'do', 'dos', 'das', 'e', 'di', 'del', 'la', 'van', 'von',
        'jr', 'junior', 'filho', 'filha', 'neto', 'neta', 'sobrinho', 'segundo'];
    $palavras = [];
    foreach (explode(' ', $nome) as $w) {
        if ($w !== '' && strlen($w) >= 2 && !in_array($w, $stop, true)) {
            $palavras[] = $w;
        }
    }
    $n = count($palavras);
    if ($n <= 1) {
        $slug = $palavras[0] ?? 'corretor';
    } elseif ($n === 2) {
        $slug = $palavras[0] . $palavras[1];
    } else {
        $slug = $palavras[0] . $palavras[$n - 1];
    }
    return substr($slug, 0, 20);
}

/**
 * Slug base: campo personalizado "Prefixo do Site" (sanitizado, 3-20 chars)
 * ou, se vazio/invalido, slug automatico do nome do cliente.
 */
function imovelsite_baseSlug(array $params): string
{
    $custom = trim((string) ($params['customfields']['Prefixo do Site'] ?? ''));
    if ($custom !== '') {
        $slug = substr(preg_replace('/[^a-z0-9]+/', '', strtolower($custom)), 0, 20);
        if (strlen($slug) >= 3) {
            return $slug;
        }
    }
    $d = $params['clientsdetails'] ?? [];
    return imovelsite_autoSlug(trim(($d['firstname'] ?? '') . ' ' . ($d['lastname'] ?? '')));
}

/**
 * Verifica disponibilidade via GET /sites/{slug}/status; se ocupado,
 * tenta sufixos 2..5 (sempre respeitando o limite de 20 chars).
 *
 * @throws \RuntimeException se nenhuma variacao estiver livre.
 */
function imovelsite_pickAvailableSlug(ApiClient $client, array $params, string $base): string
{
    for ($i = 1; $i <= 5; $i++) {
        $slug = $i === 1 ? $base : substr($base, 0, 19) . $i;
        $resp = $client->get('/sites/' . $slug . '/status');
        imovelsite_logCall($params, 'CreateAccount:checkSlug', ['slug' => $slug], $resp);
        if ($resp['code'] === 404 || ($resp['body']['status'] ?? '') === 'not_found') {
            return $slug;
        }
    }
    throw new \RuntimeException('Nenhum slug disponível entre ' . $base . ' e ' . substr($base, 0, 19) . '5.');
}

/**
 * Resolve o slug de um servico ja existente: historico no JobStore,
 * senao primeiro rotulo do dominio (ex.: fulano.imovelsite.com.br -> fulano).
 */
function imovelsite_resolveSlug(array $params): string
{
    $last = JobStore::latestFor((int) ($params['serviceid'] ?? 0));
    if ($last !== null && trim((string) $last->slug) !== '') {
        return trim((string) $last->slug);
    }
    $dom = trim((string) ($params['domain'] ?? ''));
    if ($dom !== '') {
        $label = preg_replace('/[^a-z0-9-]/', '', strtolower(explode('.', $dom)[0]));
        if ($label !== '') {
            return $label;
        }
    }
    return '';
}

function imovelsite_CreateAccount(array $params)
{
    try {
        $sid   = (int) $params['serviceid'];
        $d     = $params['clientsdetails'] ?? [];
        $email = trim((string) ($d['email'] ?? ''));
        if ($email === '') {
            return 'Cliente sem e-mail — não provisionado.';
        }
        $displayName = trim(($d['firstname'] ?? '') . ' ' . ($d['lastname'] ?? ''));

        $client = new ApiClient($params);

        // Retry idempotente: reaproveita o slug de tentativa anterior, se houver.
        $slug = '';
        $last = JobStore::latestFor($sid);
        if ($last !== null && $last->action === 'create' && trim((string) $last->slug) !== '') {
            $slug = trim((string) $last->slug);
        } else {
            $slug = imovelsite_pickAvailableSlug($client, $params, imovelsite_baseSlug($params));
        }

        // Dispara o job de criacao (idempotente por idempotency_key).
        $create = [
            'slug'            => $slug,
            'email'           => $email,
            'display_name'    => $displayName,
            'idempotency_key' => 'whmcs-' . $sid,
            'create_mailbox'  => (($params['configoption4'] ?? 'on') === 'on'),
        ];
        $resp = $client->post('/sites', $create);
        imovelsite_logCall($params, 'CreateAccount', $create, $resp);

        if (!in_array($resp['code'], [200, 202], true)) {
            JobStore::record($sid, $slug, 'create', 'error', $create, $resp['body']);
            return 'Falha ao iniciar provisionamento (HTTP ' . $resp['code'] . '): '
                . substr($resp['raw'], 0, 300);
        }
        $jobId = (string) ($resp['body']['job_id'] ?? '');
        $logId = JobStore::record($sid, $slug, 'create', (string) ($resp['body']['status'] ?? 'pending'), $create, $resp['body']);

        // Poll do status a cada 10s ate poll_max_wait.
        $maxWait   = (int) ($params['configoption3'] ?? 0);
        $maxWait   = $maxWait > 0 ? $maxWait : 150;
        $interval  = 10;
        $deadline  = time() + $maxWait;
        $lastBody  = $resp['body'];

        while (true) {
            $poll = $client->get('/sites/' . $slug . '/status');
            imovelsite_logCall($params, 'CreateAccount:poll', ['slug' => $slug], $poll);
            $lastBody = $poll['body'];
            $status   = (string) ($lastBody['status'] ?? '');

            if ($status === 'done') {
                return imovelsite_finishCreate($params, $sid, $slug, $logId, $lastBody);
            }
            if ($status === 'error') {
                JobStore::markStatus($logId, 'error', $lastBody);
                return 'Falha no provisionamento: ' . (string) ($lastBody['error'] ?? 'erro desconhecido');
            }
            if (time() + $interval > $deadline) {
                break;
            }
            sleep($interval);
        }

        JobStore::markStatus($logId, 'pending', $lastBody);
        return 'Provisionamento em andamento (job ' . ($jobId !== '' ? $jobId : $slug)
            . ') — será concluído pelo cron; use o botão Reprocessar se necessário.';
    } catch (\Throwable $e) {
        logModuleCall('imovelsite', 'CreateAccount', ApiClient::sanitize([
            'serviceid' => $params['serviceid'] ?? null,
        ]), $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * Job concluido: grava acesso no servico, guarda mailbox no JobStore e
 * registra nota no cliente.
 *
 * @param int|null $logId Linha do JobStore desta criacao.
 * @param array    $st    Corpo do GET /sites/{slug}/status com status=done.
 * @return string 'success'
 */
function imovelsite_finishCreate(array $params, int $sid, string $slug, ?int $logId, array $st): string
{
    $domain    = trim((string) ($st['domain'] ?? ''));
    $adminUser = trim((string) ($st['admin_user'] ?? ''));
    $adminPass = (string) ($st['admin_pass'] ?? '');

    // Acesso visivel na area do cliente (mesmo padrao do modulo antigo).
    try {
        $update = [];
        if ($domain !== '') {
            $update['domain'] = $domain;
        }
        if ($adminUser !== '') {
            $update['username'] = $adminUser;
        }
        if ($adminPass !== '') {
            $update['password'] = encrypt($adminPass);
        }
        if ($update !== []) {
            Capsule::table('tblhosting')->where('id', $sid)->update($update);
        }
    } catch (\Throwable $e) {
        logModuleCall('imovelsite', 'CreateAccount:writeback', ['serviceid' => $sid], $e->getMessage());
    }

    // Status completo (inclui mailbox) fica no JobStore para os merge fields
    // de e-mail do addon (EmailPreSend).
    JobStore::markStatus($logId, 'done', $st);

    if ($domain !== '') {
        try {
            localAPI('AddClientNote', [
                'userid' => (int) $params['userid'],
                'notes'  => 'Site provisionado: https://' . $domain . '/wp-admin'
                    . ($adminUser !== '' ? ' (usuário: ' . $adminUser . ')' : '')
                    . '. Senha no cadastro do serviço.',
                'sticky' => true,
            ]);
        } catch (\Throwable $e) {
            // nota e best-effort; nao falha o provisionamento
        }
    }

    return 'success';
}

/**
 * Suspend/Unsuspend/Terminate compartilham o mesmo fluxo.
 * 404/not_found e tratado como sucesso no-op (protege os 10 servicos
 * legados do pid 89, que nao existem na API do RED).
 */
function imovelsite_lifecycle(array $params, string $action, string $logAction)
{
    try {
        $sid  = (int) ($params['serviceid'] ?? 0);
        $slug = imovelsite_resolveSlug($params);
        if ($slug === '') {
            return 'Não foi possível determinar o slug do serviço (sem histórico e sem domínio).';
        }

        $client = new ApiClient($params);
        if ($action === 'terminate') {
            $resp = $client->delete('/sites/' . $slug . '?mode=archive');
        } else {
            $resp = $client->post('/sites/' . $slug . '/' . $action);
        }
        imovelsite_logCall($params, $logAction, ['slug' => $slug], $resp);
        $body = $resp['body'];

        if (($body['code'] ?? '') === 'rest_no_route') {
            return 'API não encontrada no servidor (rest_no_route) — verifique o plugin no RED.';
        }
        if ($resp['code'] === 404 || ($body['status'] ?? '') === 'not_found') {
            JobStore::record($sid, $slug, $action, 'noop', ['slug' => $slug], $body);
            return 'success'; // site inexistente na API: no-op registrado no Module Log
        }
        if ($resp['code'] === 200 && !empty($body['ok'])) {
            JobStore::record($sid, $slug, $action, 'done', ['slug' => $slug], $body);
            return 'success';
        }
        return 'Resposta inesperada da API (HTTP ' . $resp['code'] . '): ' . substr($resp['raw'], 0, 300);
    } catch (\Throwable $e) {
        logModuleCall('imovelsite', $logAction, ['serviceid' => $params['serviceid'] ?? null], $e->getMessage());
        return $e->getMessage();
    }
}

function imovelsite_SuspendAccount(array $params)
{
    return imovelsite_lifecycle($params, 'suspend', 'SuspendAccount');
}

function imovelsite_UnsuspendAccount(array $params)
{
    return imovelsite_lifecycle($params, 'unsuspend', 'UnsuspendAccount');
}

function imovelsite_TerminateAccount(array $params)
{
    return imovelsite_lifecycle($params, 'terminate', 'TerminateAccount');
}

function imovelsite_TestConnection(array $params): array
{
    try {
        $client = new ApiClient($params);
        $resp   = $client->get('/ping');
        imovelsite_logCall($params, 'TestConnection', ['endpoint' => '/ping'], $resp);
        if ($resp['code'] === 200 && !empty($resp['body']['ok'])) {
            return ['success' => true, 'error' => ''];
        }
        return [
            'success' => false,
            'error'   => 'Resposta inesperada do /ping (HTTP ' . $resp['code'] . '): ' . substr($resp['raw'], 0, 200),
        ];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function imovelsite_ServiceSingleSignOn(array $params): array
{
    $slug = imovelsite_resolveSlug($params);
    if ($slug !== '') {
        try {
            $client = new ApiClient($params);
            $resp   = $client->get('/sites/' . $slug . '/sso');
            imovelsite_logCall($params, 'ServiceSingleSignOn', ['slug' => $slug], $resp);
            $url = (string) ($resp['body']['url'] ?? '');
            if ($resp['code'] === 200 && $url !== '') {
                return ['success' => true, 'redirectTo' => $url];
            }
        } catch (\Throwable $e) {
            // cai no fallback abaixo
        }
    }

    $dom = trim((string) ($params['domain'] ?? ''));
    if ($dom === '') {
        return ['success' => false, 'errorMsg' => 'Site ainda não provisionado.'];
    }
    return ['success' => true, 'redirectTo' => 'https://' . $dom . '/wp-admin'];
}

function imovelsite_AdminLink(array $params): string
{
    $host = trim((string) ($params['serverhostname'] ?? ''));
    if ($host === '') {
        $host = trim((string) ($params['serverip'] ?? ''));
    }
    if ($host === '') {
        return '';
    }
    return '<a href="https://' . htmlspecialchars($host)
        . '/wp-admin" target="_blank">Abrir wp-admin de ' . htmlspecialchars($host) . '</a>';
}

function imovelsite_AdminServicesTabFields(array $params): array
{
    $row = JobStore::latestFor((int) ($params['serviceid'] ?? 0));
    if ($row === null) {
        return [
            'Status do Provisionamento' => 'Sem registros (serviço nunca provisionado por este módulo ou tabela mod_imovelsite_log ausente).',
        ];
    }

    $fields = [
        'Status do Provisionamento' => htmlspecialchars(sprintf(
            '%s — slug: %s — ação: %s (atualizado em %s)',
            (string) $row->status,
            (string) $row->slug,
            (string) $row->action,
            (string) $row->updated_at
        )),
    ];

    $resp    = json_decode((string) $row->response, true);
    $mailbox = is_array($resp) ? (string) ($resp['mailbox']['address'] ?? '') : '';
    if ($mailbox !== '') {
        $fields['Caixa de E-mail'] = htmlspecialchars($mailbox);
    }

    return $fields;
}

function imovelsite_AdminCustomButtonArray(): array
{
    return [
        'Reprocessar Provisionamento' => 'RetryProvision',
    ];
}

function imovelsite_RetryProvision(array $params)
{
    // Reexecuta o fluxo de criacao; idempotente via idempotency_key e
    // reaproveitamento do slug registrado no JobStore.
    return imovelsite_CreateAccount($params);
}
