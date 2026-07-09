<?php
/**
 * Installs / restores the rich HTML welcome e-mail template for ImovelSite.
 *
 * - install(): backs up the current tblemailtemplates row into
 *   mod_imovelsite_log (serviceid=0, action='email_backup') and then writes
 *   the HTML from templates/email-boas-vindas-imovelsite.html into the
 *   template body (custom=1).
 * - restore(): puts subject/message back from the latest email_backup row.
 */

namespace WHMCS\Module\Addon\Imovelsite;

use WHMCS\Database\Capsule;

class EmailTemplateInstaller
{
    const LOG_TABLE = 'mod_imovelsite_log';
    const TEMPLATE_FILE = 'email-boas-vindas-imovelsite.html';

    /**
     * Absolute path to the bundled HTML template.
     *
     * @return string
     */
    public static function templatePath()
    {
        return dirname(__DIR__) . '/templates/' . self::TEMPLATE_FILE;
    }

    /**
     * Read the HTML file, stripping the leading instruction comment
     * (the "<!-- WHMCS: ... -->" header) so it is not shipped in e-mails.
     *
     * @return string|null null when the file is missing/unreadable
     */
    public static function loadHtml()
    {
        $path = self::templatePath();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $html = file_get_contents($path);
        if ($html === false || trim($html) === '') {
            return null;
        }
        // Strip a leading instructions comment, if present.
        $trimmed = ltrim($html);
        if (strpos($trimmed, '<!-- WHMCS:') === 0) {
            $end = strpos($trimmed, '-->');
            if ($end !== false) {
                $html = ltrim(substr($trimmed, $end + 3));
            }
        }
        return $html;
    }

    /**
     * Backup the current template into mod_imovelsite_log and overwrite it
     * with the bundled HTML.
     *
     * @param int $templateId tblemailtemplates.id (e.g. 276)
     *
     * @return string human readable summary of what happened
     */
    public static function install($templateId)
    {
        $templateId = (int) $templateId;
        if ($templateId <= 0) {
            return 'Template de e-mail: ID inválido, nada feito.';
        }

        $row = Capsule::table('tblemailtemplates')->where('id', $templateId)->first();
        if (!$row) {
            return 'Template de e-mail: tblemailtemplates id=' . $templateId . ' não encontrado; nada feito.';
        }

        $html = self::loadHtml();
        if ($html === null) {
            return 'Template de e-mail: arquivo ' . self::TEMPLATE_FILE . ' não encontrado; nada feito.';
        }

        if (trim((string) $row->message) === trim($html)) {
            return 'Template de e-mail id=' . $templateId . ' já está atualizado (nenhum backup adicional criado).';
        }

        $now = date('Y-m-d H:i:s');
        $backupId = Capsule::table(self::LOG_TABLE)->insertGetId([
            'serviceid'  => 0,
            'slug'       => 'emailtpl-' . $templateId,
            'action'     => 'email_backup',
            'status'     => 'success',
            'job_id'     => null,
            'request'    => (string) $row->subject,
            'response'   => (string) $row->message,
            'attempts'   => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Capsule::table('tblemailtemplates')
            ->where('id', $templateId)
            ->update([
                'message' => $html,
                'custom'  => 1,
            ]);

        return 'Template de e-mail id=' . $templateId . ' atualizado com a versão rica (backup no log #' . $backupId . ').';
    }

    /**
     * Restore subject/message from the most recent email_backup log row.
     *
     * @param int $templateId
     *
     * @return int|false the log row id used, or false when no backup exists
     */
    public static function restore($templateId)
    {
        $templateId = (int) $templateId;
        if ($templateId <= 0) {
            return false;
        }

        $backup = Capsule::table(self::LOG_TABLE)
            ->where('action', 'email_backup')
            ->where('serviceid', 0)
            ->where('slug', 'emailtpl-' . $templateId)
            ->orderBy('id', 'desc')
            ->first();

        if (!$backup) {
            // Legacy fallback: any email_backup row (older versions did not set slug).
            $backup = Capsule::table(self::LOG_TABLE)
                ->where('action', 'email_backup')
                ->where('serviceid', 0)
                ->orderBy('id', 'desc')
                ->first();
        }

        if (!$backup) {
            return false;
        }

        Capsule::table('tblemailtemplates')
            ->where('id', $templateId)
            ->update([
                'subject' => (string) $backup->request,
                'message' => (string) $backup->response,
            ]);

        return (int) $backup->id;
    }
}
