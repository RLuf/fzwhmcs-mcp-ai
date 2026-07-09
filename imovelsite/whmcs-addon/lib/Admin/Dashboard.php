<?php
/**
 * Admin dashboard for the ImovelSite addon.
 *
 * Renders (echoes) the module output page:
 *  - last 50 mod_imovelsite_log rows, with status filter;
 *  - "Reprocessar" (localAPI ModuleCreate) for pending/error provision rows;
 *  - manual-domain queue (action=domain_map) with "Marcar mapeado";
 *  - restore of the welcome e-mail template backup.
 */

namespace WHMCS\Module\Addon\Imovelsite\Admin;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Imovelsite\EmailTemplateInstaller;

class Dashboard
{
    const LOG_TABLE = 'mod_imovelsite_log';

    /** @var array */
    protected $lang = [];

    /** @var string */
    protected $modulelink = '';

    /**
     * Entry point: handle POST actions and echo the dashboard HTML.
     *
     * @param array $vars addon output vars (modulelink, settings, _lang, ...)
     *
     * @return void
     */
    public function render($vars)
    {
        $this->modulelink = isset($vars['modulelink']) ? $vars['modulelink'] : 'addonmodules.php?module=imovelsite';
        $this->lang = isset($vars['_lang']) && is_array($vars['_lang']) ? $vars['_lang'] : [];

        $messages = [];
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['imovelsite_action'])) {
            $messages = $this->handlePost($vars);
        }

        $this->echoStyles();

        echo '<h2 style="margin-top:0;">' . $this->e($this->t('dash_title', 'ImovelSite — Painel')) . '</h2>';

        foreach ($messages as $msg) {
            $class = $msg[0] === 'success' ? 'alert-success' : ($msg[0] === 'info' ? 'alert-info' : 'alert-danger');
            echo '<div class="alert ' . $class . '">' . $this->e($msg[1]) . '</div>';
        }

        $this->sectionEmailTemplate();
        $this->sectionManualDomains();
        $this->sectionLog();
    }

    // ------------------------------------------------------------------
    // POST actions
    // ------------------------------------------------------------------

    /**
     * @param array $vars
     *
     * @return array list of [type, message]
     */
    protected function handlePost($vars)
    {
        // CSRF: use WHMCS admin token validation when available. check_token()
        // halts the request itself when the token is invalid.
        if (function_exists('check_token')) {
            check_token('WHMCS.admin.default');
        } else {
            // Fallback: require same-host referer (noted limitation).
            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if ($host === '' || stripos($referer, $host) === false) {
                return [['error', 'Requisição rejeitada (verificação de origem falhou).']];
            }
        }

        $action = (string) $_POST['imovelsite_action'];
        $logId = isset($_POST['log_id']) ? (int) $_POST['log_id'] : 0;

        try {
            if ($action === 'reprocess') {
                return $this->actionReprocess($logId);
            }
            if ($action === 'mark_mapped') {
                return $this->actionMarkMapped($logId);
            }
            if ($action === 'restore_email') {
                return $this->actionRestoreEmail($vars);
            }
        } catch (\Throwable $e) {
            return [['error', 'Erro: ' . $e->getMessage()]];
        }

        return [];
    }

    /**
     * Re-run ModuleCreate for the service of a provision log row.
     *
     * @param int $logId
     *
     * @return array
     */
    protected function actionReprocess($logId)
    {
        $row = Capsule::table(self::LOG_TABLE)->where('id', $logId)->first();
        if (!$row) {
            return [['error', $this->t('dash_msg_row_missing', 'Registro de log não encontrado.')]];
        }

        $serviceId = (int) $row->serviceid;
        if (!function_exists('localAPI')) {
            return [['error', 'localAPI indisponível neste contexto.']];
        }

        $result = localAPI('ModuleCreate', ['serviceid' => $serviceId]);

        // Do not overwrite request/response here: the server module owns the
        // provision row content (it may hold the mailbox JSON used by the
        // welcome e-mail). Only bump attempts/updated_at.
        Capsule::table(self::LOG_TABLE)->where('id', $logId)->update([
            'attempts' => (int) $row->attempts + 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if (isset($result['result']) && $result['result'] === 'success') {
            return [['success', sprintf($this->t('dash_msg_reprocess_ok', 'ModuleCreate OK para o serviço #%s.'), $serviceId)]];
        }

        $err = isset($result['message']) ? $result['message'] : 'erro desconhecido';
        return [['error', sprintf($this->t('dash_msg_reprocess_fail', 'ModuleCreate falhou p/ serviço #%s: %s'), $serviceId, $err)]];
    }

    /**
     * Mark a domain_map row as mapped (manual registration done + pointed).
     *
     * @param int $logId
     *
     * @return array
     */
    protected function actionMarkMapped($logId)
    {
        $row = Capsule::table(self::LOG_TABLE)
            ->where('id', $logId)
            ->where('action', 'domain_map')
            ->first();
        if (!$row) {
            return [['error', $this->t('dash_msg_row_missing', 'Registro de log não encontrado.')]];
        }

        Capsule::table(self::LOG_TABLE)->where('id', $logId)->update([
            'status' => 'mapped',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return [['success', sprintf($this->t('dash_msg_mapped_ok', 'Registro #%s marcado como mapeado.'), $logId)]];
    }

    /**
     * Restore the welcome e-mail template from the email_backup log row.
     *
     * @param array $vars
     *
     * @return array
     */
    protected function actionRestoreEmail($vars)
    {
        $templateId = (int) (isset($vars['welcome_email_template_id']) && $vars['welcome_email_template_id'] !== ''
            ? $vars['welcome_email_template_id']
            : 276);

        $usedId = EmailTemplateInstaller::restore($templateId);
        if ($usedId === false) {
            return [['error', $this->t('dash_msg_restore_none', 'Nenhum backup encontrado.')]];
        }

        return [['success', sprintf($this->t('dash_msg_restore_ok', 'Template restaurado (log #%s).'), $usedId)]];
    }

    // ------------------------------------------------------------------
    // Sections
    // ------------------------------------------------------------------

    protected function sectionEmailTemplate()
    {
        echo '<div class="imovelsite-panel">';
        echo '<h3>' . $this->e($this->t('dash_email_section', 'Template de e-mail de boas-vindas')) . '</h3>';
        echo '<p class="text-muted">' . $this->e($this->t('dash_email_hint', '')) . '</p>';
        echo '<form method="post" action="' . $this->e($this->modulelink) . '" '
            . 'onsubmit="return confirm(' . $this->e($this->jsString($this->t('dash_confirm_restore_email', 'Restaurar backup?'))) . ');">';
        echo $this->tokenField();
        echo '<input type="hidden" name="imovelsite_action" value="restore_email" />';
        echo '<button type="submit" class="btn btn-default btn-sm">'
            . $this->e($this->t('dash_btn_restore_email', 'Restaurar backup do template de e-mail')) . '</button>';
        echo '</form></div>';
    }

    protected function sectionManualDomains()
    {
        $rows = Capsule::table(self::LOG_TABLE)
            ->where('action', 'domain_map')
            ->whereIn('status', ['pending', 'ready'])
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get();

        echo '<div class="imovelsite-panel">';
        echo '<h3>' . $this->e($this->t('dash_domains_heading', 'Domínios aguardando registro manual')) . '</h3>';

        if (count($rows) === 0) {
            echo '<p class="text-muted">' . $this->e($this->t('dash_domains_empty', 'Nenhum domínio pendente.')) . '</p></div>';
            return;
        }

        echo '<table class="datatable imovelsite-table" width="100%"><thead><tr>'
            . '<th>' . $this->e($this->t('dash_col_id', 'ID')) . '</th>'
            . '<th>' . $this->e($this->t('dash_col_service', 'Serviço')) . '</th>'
            . '<th>' . $this->e($this->t('dash_col_slug', 'Domínio')) . '</th>'
            . '<th>' . $this->e($this->t('dash_col_status', 'Status')) . '</th>'
            . '<th>' . $this->e($this->t('dash_col_created', 'Criado em')) . '</th>'
            . '<th>' . $this->e($this->t('dash_col_actions', 'Ações')) . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            echo '<tr>'
                . '<td>' . (int) $row->id . '</td>'
                . '<td>' . $this->serviceLink($row->serviceid) . '</td>'
                . '<td>' . $this->e($row->slug) . '</td>'
                . '<td>' . $this->statusBadge($row->status) . '</td>'
                . '<td>' . $this->e($row->created_at) . '</td>'
                . '<td>' . $this->inlineActionForm('mark_mapped', $row->id, $this->t('dash_btn_mark_mapped', 'Marcar mapeado')) . '</td>'
                . '</tr>';
        }

        echo '</tbody></table></div>';
    }

    protected function sectionLog()
    {
        $statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : '';

        $query = Capsule::table(self::LOG_TABLE)->orderBy('id', 'desc')->limit(50);
        if ($statusFilter !== '') {
            $query->where('status', $statusFilter);
        }
        $rows = $query->get();

        $statuses = Capsule::table(self::LOG_TABLE)
            ->select('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');

        echo '<div class="imovelsite-panel">';
        echo '<h3>' . $this->e($this->t('dash_log_heading', 'Últimos registros')) . '</h3>';

        // Status filter links (GET).
        echo '<p>' . $this->e($this->t('dash_filter_status', 'Filtrar por status')) . ': ';
        echo '<a href="' . $this->e($this->modulelink) . '"'
            . ($statusFilter === '' ? ' style="font-weight:bold;"' : '') . '>'
            . $this->e($this->t('dash_filter_all', 'Todos')) . '</a>';
        foreach ($statuses as $status) {
            echo ' | <a href="' . $this->e($this->modulelink . '&status=' . urlencode($status)) . '"'
                . ($statusFilter === $status ? ' style="font-weight:bold;"' : '') . '>'
                . $this->e($status) . '</a>';
        }
        echo '</p>';

        if (count($rows) === 0) {
            echo '<p class="text-muted">' . $this->e($this->t('dash_log_empty', 'Nenhum registro.')) . '</p></div>';
            return;
        }

        echo '<table class="datatable imovelsite-table" width="100%"><thead><tr>'
            . '<th>' . $this->e($this->t('dash_col_id', 'ID')) . '</th>'
            . '<th>' . $this->e($this->t('dash_col_service', 'Serviço')) . '</th>'
            . '<th>' . $this->e($this->t('dash_col_slug', 'Slug')) . '</th>'
            . '<th>' . $this->e($this->t('dash_col_action', 'Ação')) . '</th>'
            . '<th>' . $this->e($this->t('dash_col_status', 'Status')) . '</th>'
            . '<th>' . $this->e($this->t('dash_col_job', 'Job')) . '</th>'
            . '<th>' . $this->e($this->t('dash_col_attempts', 'Tent.')) . '</th>'
            . '<th>' . $this->e($this->t('dash_col_created', 'Criado')) . '</th>'
            . '<th>' . $this->e($this->t('dash_col_updated', 'Atualizado')) . '</th>'
            . '<th>' . $this->e($this->t('dash_col_actions', 'Ações')) . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $actions = '';
            if ($row->action === 'provision' && in_array($row->status, ['pending', 'error'], true)) {
                $actions .= $this->inlineActionForm(
                    'reprocess',
                    $row->id,
                    $this->t('dash_btn_reprocess', 'Reprocessar'),
                    $this->t('dash_confirm_reprocess', 'Reprocessar?')
                );
            }
            if ($row->action === 'domain_map' && in_array($row->status, ['pending', 'ready'], true)) {
                $actions .= $this->inlineActionForm('mark_mapped', $row->id, $this->t('dash_btn_mark_mapped', 'Marcar mapeado'));
            }

            echo '<tr>'
                . '<td>' . (int) $row->id . '</td>'
                . '<td>' . $this->serviceLink($row->serviceid) . '</td>'
                . '<td>' . $this->e($row->slug) . '</td>'
                . '<td>' . $this->e($row->action) . '</td>'
                . '<td>' . $this->statusBadge($row->status) . '</td>'
                . '<td>' . $this->e((string) $row->job_id) . '</td>'
                . '<td>' . (int) $row->attempts . '</td>'
                . '<td>' . $this->e($row->created_at) . '</td>'
                . '<td>' . $this->e($row->updated_at) . '</td>'
                . '<td>' . $actions . '</td>'
                . '</tr>';
        }

        echo '</tbody></table></div>';
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Hidden CSRF token input for admin POST forms.
     *
     * @return string
     */
    protected function tokenField()
    {
        if (function_exists('generate_token')) {
            return generate_token('WHMCS.admin.default');
        }
        return '';
    }

    /**
     * Small inline POST form with a single action button.
     *
     * @param string      $action
     * @param int         $logId
     * @param string      $label
     * @param string|null $confirm
     *
     * @return string
     */
    protected function inlineActionForm($action, $logId, $label, $confirm = null)
    {
        $onsubmit = $confirm !== null && $confirm !== ''
            ? ' onsubmit="return confirm(' . $this->e($this->jsString($confirm)) . ');"'
            : '';

        return '<form method="post" action="' . $this->e($this->modulelink) . '" style="display:inline;margin:0 4px 0 0;"' . $onsubmit . '>'
            . $this->tokenField()
            . '<input type="hidden" name="imovelsite_action" value="' . $this->e($action) . '" />'
            . '<input type="hidden" name="log_id" value="' . (int) $logId . '" />'
            . '<button type="submit" class="btn btn-default btn-xs btn-sm">' . $this->e($label) . '</button>'
            . '</form>';
    }

    /**
     * @param int $serviceId
     *
     * @return string
     */
    protected function serviceLink($serviceId)
    {
        $serviceId = (int) $serviceId;
        if ($serviceId <= 0) {
            return '—';
        }
        return '<a href="clientsservices.php?id=' . $serviceId . '" target="_blank">#' . $serviceId . '</a>';
    }

    /**
     * @param string $status
     *
     * @return string
     */
    protected function statusBadge($status)
    {
        $status = (string) $status;
        $colors = [
            'success' => '#5cb85c',
            'mapped' => '#5cb85c',
            'ready' => '#5bc0de',
            'pending' => '#f0ad4e',
            'error' => '#d9534f',
            'failed' => '#d9534f',
        ];
        $color = isset($colors[$status]) ? $colors[$status] : '#777';
        return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;color:#fff;'
            . 'font-size:11px;background:' . $color . ';">' . $this->e($status) . '</span>';
    }

    protected function echoStyles()
    {
        echo '<style>'
            . '.imovelsite-panel{background:#fff;border:1px solid #ddd;border-radius:6px;padding:15px 18px;margin-bottom:18px;}'
            . '.imovelsite-panel h3{margin-top:0;color:#33475e;}'
            . '.imovelsite-table th,.imovelsite-table td{padding:6px 8px;vertical-align:middle;}'
            . '</style>';
    }

    /**
     * Lang lookup with fallback.
     *
     * @param string $key
     * @param string $fallback
     *
     * @return string
     */
    protected function t($key, $fallback = '')
    {
        return isset($this->lang[$key]) && $this->lang[$key] !== '' ? $this->lang[$key] : $fallback;
    }

    /**
     * @param string $value
     *
     * @return string
     */
    protected function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * JSON-encode a string for safe embedding inside inline JS.
     *
     * @param string $value
     *
     * @return string
     */
    protected function jsString($value)
    {
        return json_encode((string) $value, JSON_UNESCAPED_UNICODE);
    }
}
