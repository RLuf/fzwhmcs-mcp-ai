<?php
/**
 * ImovelSite — WHMCS addon module.
 *
 * Automação do produto "Imovel Site" (pid 89):
 *  - tabela de log mod_imovelsite_log (compartilhada com o módulo de servidor);
 *  - instalação do template rico de e-mail de boas-vindas (com backup);
 *  - campo customizado "Prefixo do Site|prefix" no produto;
 *  - dashboard admin (reprocessamento, domínios manuais, restore do e-mail);
 *  - hooks de carrinho/checkout/e-mail/cron em hooks.php.
 *
 * Compatível com WHMCS 8.13 / PHP 7.4.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Imovelsite\Admin\Dashboard;
use WHMCS\Module\Addon\Imovelsite\EmailTemplateInstaller;

require_once __DIR__ . '/lib/EmailTemplateInstaller.php';
require_once __DIR__ . '/lib/Admin/Dashboard.php';

/**
 * Addon configuration.
 *
 * @return array
 */
function imovelsite_config()
{
    return [
        'name' => 'ImovelSite',
        'description' => 'Automação do produto Imovel Site: banner no carrinho, validação do prefixo, '
            . 'e-mail de boas-vindas rico, retentativas de provisionamento e fila de domínios manuais.',
        'version' => '1.0.0',
        'author' => 'Webstorage/Fable',
        'language' => 'portuguese-br',
        'fields' => [
            'product_ids' => [
                'FriendlyName' => 'IDs dos produtos',
                'Type' => 'text',
                'Size' => '25',
                'Default' => '89',
                'Description' => 'IDs dos produtos Imovel Site, separados por vírgula.',
            ],
            'enable_cart_banner' => [
                'FriendlyName' => 'Banner no carrinho',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Exibir o banner explicativo acima das opções de domínio no carrinho.',
            ],
            'welcome_email_template_id' => [
                'FriendlyName' => 'ID do template de boas-vindas',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '276',
                'Description' => 'ID em tblemailtemplates do e-mail "Imovel Site - Boas Vindas".',
            ],
            'notify_email_domains' => [
                'FriendlyName' => 'E-mails p/ registro manual de domínios',
                'Type' => 'text',
                'Size' => '60',
                'Default' => '',
                'Description' => 'E-mails de administradores (separados por vírgula) a notificar quando '
                    . 'um pedido incluir domínio próprio a registrar manualmente.',
            ],
        ],
    ];
}

/**
 * Activation: create log table, install rich e-mail template (with backup),
 * create the "Prefixo do Site" product custom field.
 *
 * @return array
 */
function imovelsite_activate()
{
    $summary = [];

    try {
        // 1) Log table (shared contract with the imovelsite server module).
        if (!Capsule::schema()->hasTable('mod_imovelsite_log')) {
            Capsule::schema()->create('mod_imovelsite_log', function ($table) {
                /** @var \Illuminate\Database\Schema\Blueprint $table */
                $table->increments('id');
                $table->unsignedInteger('serviceid');
                $table->string('slug', 100)->default('');
                $table->string('action', 30);
                $table->string('status', 20);
                $table->string('job_id', 64)->nullable();
                $table->text('request')->nullable();
                $table->text('response')->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->index('serviceid', 'idx_service');
                $table->index('status', 'idx_status');
            });
            $summary[] = 'Tabela mod_imovelsite_log criada.';
        } else {
            $summary[] = 'Tabela mod_imovelsite_log já existia (mantida).';
        }

        // 2) Rich welcome e-mail template (backup first, then overwrite).
        $summary[] = EmailTemplateInstaller::install(276);

        // 3) Product custom field "Prefixo do Site|prefix" on pid 89.
        $productIds = [89];
        foreach ($productIds as $pid) {
            $exists = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('relid', $pid)
                ->where('fieldname', 'like', 'Prefixo do Site%')
                ->exists();
            if ($exists) {
                $summary[] = 'Campo customizado "Prefixo do Site" já existia no produto ' . $pid . '.';
                continue;
            }
            Capsule::table('tblcustomfields')->insert([
                'type' => 'product',
                'relid' => $pid,
                'fieldname' => 'Prefixo do Site|prefix',
                'fieldtype' => 'text',
                'description' => 'Escolha o endereço do seu site: SEU_NOME.imovelsite.com.br '
                    . '(letras minúsculas e números, 3-20 caracteres)',
                'fieldoptions' => '',
                'regexpr' => '',
                'adminonly' => '',
                'required' => 'on',
                'showorder' => 'on',
                'showinvoice' => '',
                'showdetail' => 'on',
                'sortorder' => 0,
            ]);
            $summary[] = 'Campo customizado "Prefixo do Site" criado no produto ' . $pid . '.';
        }
    } catch (\Throwable $e) {
        return [
            'status' => 'error',
            'description' => 'Falha na ativação do ImovelSite: ' . $e->getMessage(),
        ];
    }

    return [
        'status' => 'success',
        'description' => 'ImovelSite ativado. ' . implode(' ', $summary),
    ];
}

/**
 * Deactivation: keep the log table (shared with the server module) and the
 * e-mail template / custom field untouched.
 *
 * @return array
 */
function imovelsite_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'ImovelSite desativado. A tabela mod_imovelsite_log, o template de e-mail e o '
            . 'campo customizado foram mantidos.',
    ];
}

/**
 * Upgrade stub for future schema/data migrations.
 *
 * @param array $vars ['version' => currently installed version]
 *
 * @return void
 */
function imovelsite_upgrade($vars)
{
    $currentVersion = isset($vars['version']) ? $vars['version'] : '0';

    // Example for future migrations:
    // if (version_compare($currentVersion, '1.1.0', '<')) { ... }
    unset($currentVersion);
}

/**
 * Admin area output (dashboard).
 *
 * @param array $vars module config + WHMCS admin vars
 *
 * @return void
 */
function imovelsite_output($vars)
{
    $dashboard = new Dashboard();
    $dashboard->render($vars);
}
