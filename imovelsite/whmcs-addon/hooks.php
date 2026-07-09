<?php
/**
 * ImovelSite addon — hooks.
 *
 * Todos os hooks são "gated" pelas configurações do addon (tbladdonmodules,
 * module='imovelsite') e envoltos em try/catch para nunca quebrar o carrinho
 * em produção.
 *
 * 1. ClientAreaPageCart          — expõe flags/lang ao template do carrinho.
 * 2. ClientAreaFooterOutput      — injeta o banner explicativo acima das opções de domínio.
 * 3. ShoppingCartValidateCheckout— valida o campo "Prefixo do Site" (slug).
 * 4. EmailPreSend                — aborta welcome de hosting padrão + merge fields de mailbox.
 * 5. AfterShoppingCartCheckout   — fila de registro manual de domínio (log + todo + aviso).
 * 6. AfterCronJob                — retenta provisionamentos pendentes; marca domínios prontos.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Acesso negado');
}

// ---------------------------------------------------------------------------
// Helpers (prefixados com imovelsite_hk_ para evitar colisões)
// ---------------------------------------------------------------------------

/**
 * Envia o e-mail de boas-vindas UMA única vez por serviço (dedupe via
 * mod_imovelsite_log action='welcome_email'). Usado pelo caminho do cron:
 * quando o provisionamento conclui fora da janela de polling do módulo,
 * o WHMCS não dispara o welcome sozinho.
 *
 * @param int $serviceId tblhosting.id
 */
function imovelsite_hk_send_welcome_once($serviceId)
{
    $serviceId = (int) $serviceId;
    if ($serviceId <= 0 || !function_exists('localAPI')) {
        return;
    }
    try {
        $already = Capsule::table('mod_imovelsite_log')
            ->where('serviceid', $serviceId)
            ->where('action', 'welcome_email')
            ->where('status', 'success')
            ->exists();
        if ($already) {
            return;
        }
        $settings = imovelsite_hk_settings();
        $tplName = Capsule::table('tblemailtemplates')
            ->where('id', (int) $settings['welcome_email_template_id'])
            ->value('name');
        if (!$tplName) {
            return;
        }
        $result = localAPI('SendEmail', ['messagename' => $tplName, 'id' => $serviceId]);
        $ok = isset($result['result']) && $result['result'] === 'success';
        Capsule::table('mod_imovelsite_log')->insert([
            'serviceid' => $serviceId,
            'slug' => '',
            'action' => 'welcome_email',
            'status' => $ok ? 'success' : 'error',
            'request' => $tplName,
            'response' => $ok ? '' : json_encode($result),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if (function_exists('logActivity')) {
            logActivity('ImovelSite: boas-vindas ' . ($ok ? 'enviado' : 'FALHOU') . ' via cron — serviço #' . $serviceId);
        }
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('ImovelSite welcome_once: ' . $e->getMessage());
        }
    }
}

/**
 * Addon settings from tbladdonmodules with sane defaults.
 *
 * @return array
 */
function imovelsite_hk_settings()
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }
    $settings = [
        'product_ids' => '89',
        'enable_cart_banner' => 'on',
        'welcome_email_template_id' => '276',
        'notify_email_domains' => '',
    ];
    try {
        $rows = Capsule::table('tbladdonmodules')->where('module', 'imovelsite')->get();
        foreach ($rows as $row) {
            if (array_key_exists($row->setting, $settings)) {
                $settings[$row->setting] = (string) $row->value;
            }
        }
    } catch (\Throwable $e) {
        // keep defaults
    }
    return $settings;
}

/**
 * @return int[] product ids handled by this addon
 */
function imovelsite_hk_product_ids()
{
    $settings = imovelsite_hk_settings();
    $ids = [];
    foreach (explode(',', $settings['product_ids']) as $part) {
        $part = (int) trim($part);
        if ($part > 0) {
            $ids[] = $part;
        }
    }
    return $ids ?: [89];
}

/**
 * Addon language strings (pt-BR — client-facing texts are Brazilian Portuguese).
 *
 * @return array
 */
function imovelsite_hk_lang()
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $_ADDONLANG = [];
    $file = __DIR__ . '/lang/portuguese-br.php';
    if (is_file($file)) {
        include $file;
    }
    $lang = is_array($_ADDONLANG) ? $_ADDONLANG : [];
    return $lang;
}

/**
 * Does the current request/cart involve one of our product ids?
 *
 * @param array $vars hook template vars (optional)
 *
 * @return bool
 */
function imovelsite_hk_cart_pid_match($vars = [])
{
    $pids = imovelsite_hk_product_ids();

    if (isset($_REQUEST['pid']) && in_array((int) $_REQUEST['pid'], $pids, true)) {
        return true;
    }
    if (!empty($_SESSION['cart']['products']) && is_array($_SESSION['cart']['products'])) {
        foreach ($_SESSION['cart']['products'] as $product) {
            if (isset($product['pid']) && in_array((int) $product['pid'], $pids, true)) {
                return true;
            }
        }
    }
    if (isset($vars['productinfo']['pid']) && in_array((int) $vars['productinfo']['pid'], $pids, true)) {
        return true;
    }
    return false;
}

/**
 * Is the current client-area request the shopping cart?
 *
 * @param array $vars
 *
 * @return bool
 */
function imovelsite_hk_is_cart_page($vars = [])
{
    // O fluxo de compra roda em cart.php E nas rotas amigáveis /store/... (filename=index).
    // O JS do banner só injeta onde existem as opções de domínio (#selregister etc.),
    // então o gate pode ser amplo sem vazar para outras páginas.
    if (isset($vars['filename']) && in_array($vars['filename'], ['cart', 'index'], true)) {
        return true;
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    return stripos($uri, 'cart.php') !== false || stripos($uri, '/store/') !== false;
}

/**
 * Banner HTML, built from templates/cart-banner.tpl (placeholders %%TITLE%%
 * and %%BODY%%) with an inline fallback. Strings come from the lang file.
 *
 * @return string
 */
function imovelsite_hk_banner_html()
{
    $lang = imovelsite_hk_lang();
    $title = htmlspecialchars(
        isset($lang['cart_banner_title']) ? $lang['cart_banner_title'] : 'Você já ganha endereço e e-mail GRÁTIS!',
        ENT_QUOTES,
        'UTF-8'
    );
    $body = htmlspecialchars(
        isset($lang['cart_banner_body']) ? $lang['cart_banner_body'] : '',
        ENT_QUOTES,
        'UTF-8'
    );

    $tpl = __DIR__ . '/templates/cart-banner.tpl';
    $html = is_file($tpl) ? (string) file_get_contents($tpl) : '';
    // Strip the Smarty-style header comment (file is used raw, not via Smarty).
    $html = preg_replace('/^\s*\{\*.*?\*\}/s', '', $html);

    if ($html === null || trim((string) $html) === '') {
        $html = '<div id="imovelsite-cart-banner" class="imovelsite-cart-banner" '
            . 'style="background:#f0f7e8;border:1px solid #88c354;border-radius:8px;'
            . 'padding:18px 22px;margin:0 0 22px;color:#333;">'
            . '<div style="font-size:18px;font-weight:bold;color:#33475e;margin:0 0 8px;">%%TITLE%%</div>'
            . '<div style="font-size:14px;line-height:1.6;">%%BODY%%</div>'
            . '</div>';
    }

    return str_replace(['%%TITLE%%', '%%BODY%%'], [$title, $body], $html);
}

/**
 * Map product id => custom field id for the "Prefixo do Site" field.
 *
 * @param int[] $pids
 *
 * @return array [pid => fieldid]
 */
function imovelsite_hk_prefix_field_ids(array $pids)
{
    $map = [];
    if (!$pids) {
        return $map;
    }
    $rows = Capsule::table('tblcustomfields')
        ->where('type', 'product')
        ->whereIn('relid', $pids)
        ->where('fieldname', 'like', 'Prefixo do Site%')
        ->get(['id', 'relid']);
    foreach ($rows as $row) {
        $map[(int) $row->relid] = (int) $row->id;
    }
    return $map;
}

/**
 * Extract a custom field value from a cart-session product entry.
 *
 * Estrutura observada no WHMCS 8: $_SESSION['cart']['products'][n]['customfields']
 * é um array associativo fieldid => valor (preenchido na etapa de configuração
 * do produto). Também aceitamos string serializada (variação legada) e o array
 * de request $_REQUEST['customfield'][fieldid] como fallback.
 *
 * @param array $product cart session product entry
 * @param int   $fieldId tblcustomfields.id
 *
 * @return string
 */
function imovelsite_hk_extract_cf_value($product, $fieldId)
{
    $fieldId = (int) $fieldId;
    if ($fieldId <= 0) {
        return '';
    }

    if (isset($product['customfields'])) {
        $cf = $product['customfields'];
        if (is_string($cf) && $cf !== '') {
            $decoded = @unserialize($cf, ['allowed_classes' => false]);
            if ($decoded === false) {
                $decoded = @unserialize(base64_decode($cf), ['allowed_classes' => false]);
            }
            if (is_array($decoded)) {
                $cf = $decoded;
            }
        }
        if (is_array($cf) && isset($cf[$fieldId])) {
            return trim((string) $cf[$fieldId]);
        }
    }

    if (isset($_REQUEST['customfield'][$fieldId])) {
        return trim((string) $_REQUEST['customfield'][$fieldId]);
    }

    return '';
}

// ---------------------------------------------------------------------------
// 1) ClientAreaPageCart — template vars for cart pages
// ---------------------------------------------------------------------------

add_hook('ClientAreaPageCart', 1, function ($vars) {
    try {
        if (!imovelsite_hk_cart_pid_match($vars)) {
            return [];
        }
        return [
            'imovelsiteBanner' => true,
            'imovelsiteLang' => imovelsite_hk_lang(),
        ];
    } catch (\Throwable $e) {
        return [];
    }
});

// ---------------------------------------------------------------------------
// 2) ClientAreaFooterOutput — inject banner above the domain options
// ---------------------------------------------------------------------------

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    try {
        $settings = imovelsite_hk_settings();
        if ($settings['enable_cart_banner'] !== 'on') {
            return '';
        }
        if (!imovelsite_hk_is_cart_page($vars) || !imovelsite_hk_cart_pid_match($vars)) {
            return '';
        }

        $bannerJson = json_encode(imovelsite_hk_banner_html(), JSON_UNESCAPED_UNICODE);

        return <<<HTML
<script>
(function () {
    var bannerHtml = {$bannerJson};
    function imovelsiteInjectBanner() {
        if (document.getElementById('imovelsite-cart-banner')) { return; }
        var radio = document.getElementById('selregister')
            || document.getElementById('seltransfer')
            || document.getElementById('selowndomain');
        if (!radio || typeof radio.closest !== 'function') { return; }
        // Sobe até o container das opções de domínio e insere o banner ANTES dele.
        var target = radio.closest('.domain-selection-options')
            || radio.closest('form')
            || radio.parentElement;
        if (!target || !target.insertAdjacentHTML) { return; }
        target.insertAdjacentHTML('beforebegin', bannerHtml);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', imovelsiteInjectBanner);
    } else {
        imovelsiteInjectBanner();
    }
})();
</script>
HTML;
    } catch (\Throwable $e) {
        return '';
    }
});

// ---------------------------------------------------------------------------
// 3) ShoppingCartValidateCheckout — validate "Prefixo do Site"
// ---------------------------------------------------------------------------

add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    try {
        $errors = [];
        $pids = imovelsite_hk_product_ids();
        $lang = imovelsite_hk_lang();

        $cartProducts = isset($_SESSION['cart']['products']) && is_array($_SESSION['cart']['products'])
            ? $_SESSION['cart']['products']
            : [];
        if (!$cartProducts) {
            return $errors;
        }

        $fieldMap = imovelsite_hk_prefix_field_ids($pids);
        $seenSlugs = [];

        foreach ($cartProducts as $product) {
            $pid = isset($product['pid']) ? (int) $product['pid'] : 0;
            if (!in_array($pid, $pids, true)) {
                continue;
            }

            $fieldId = isset($fieldMap[$pid]) ? $fieldMap[$pid] : 0;
            $raw = imovelsite_hk_extract_cf_value($product, $fieldId);
            $slug = strtolower(trim($raw));

            if ($slug === '') {
                $errors[] = isset($lang['validation_prefix_missing'])
                    ? $lang['validation_prefix_missing']
                    : 'Informe o Prefixo do Site.';
                continue;
            }

            if (!preg_match('/^[a-z0-9]{3,20}$/', $slug)) {
                $errors[] = isset($lang['validation_prefix_invalid'])
                    ? $lang['validation_prefix_invalid']
                    : 'Prefixo do Site inválido (use a-z e 0-9, 3-20 caracteres).';
                continue;
            }

            if (isset($seenSlugs[$slug])) {
                $errors[] = isset($lang['validation_prefix_duplicate_cart'])
                    ? $lang['validation_prefix_duplicate_cart']
                    : 'Prefixo repetido no carrinho.';
                continue;
            }
            $seenSlugs[$slug] = true;

            $taken = Capsule::table('tblhosting')
                ->where('domain', 'like', $slug . '.imovelsite.com.br')
                ->whereIn('domainstatus', ['Active', 'Suspended', 'Pending'])
                ->exists();
            if ($taken) {
                $errors[] = isset($lang['validation_prefix_taken'])
                    ? $lang['validation_prefix_taken']
                    : 'Este Prefixo do Site já está em uso.';
            }
        }

        return $errors;
    } catch (\Throwable $e) {
        return [];
    }
});

// ---------------------------------------------------------------------------
// 4) EmailPreSend — abort stock hosting welcome + mailbox merge fields
// ---------------------------------------------------------------------------

add_hook('EmailPreSend', 1, function ($vars) {
    try {
        $messageName = isset($vars['messagename']) ? (string) $vars['messagename'] : '';
        $relId = isset($vars['relid']) ? (int) $vars['relid'] : 0;
        if ($messageName === '' || $relId <= 0) {
            return [];
        }

        $settings = imovelsite_hk_settings();
        $pids = imovelsite_hk_product_ids();

        // Name of OUR welcome template (id from settings, default 276).
        $ourTemplateName = '';
        $tplId = (int) $settings['welcome_email_template_id'];
        if ($tplId > 0) {
            $tplRow = Capsule::table('tblemailtemplates')->where('id', $tplId)->first(['name']);
            if ($tplRow) {
                $ourTemplateName = (string) $tplRow->name;
            }
        }

        // ------------------------------------------------------------------
        // (a) Safety net: never send stock hosting-welcome e-mails for our product.
        // ------------------------------------------------------------------
        $stockWelcomeNames = [
            'Hosting Account Welcome Email',
            'cPanel Hosting Welcome Email',
            'Reseller Account Welcome Email',
            'Other Product/Service Welcome Email',
            'VPS Server Welcome Email',
            'Dedicated Server Welcome Email',
        ];
        $looksLikeHostingWelcome = in_array($messageName, $stockWelcomeNames, true)
            || (stripos($messageName, 'welcome') !== false && $messageName !== $ourTemplateName);

        if ($looksLikeHostingWelcome && $messageName !== $ourTemplateName) {
            $service = Capsule::table('tblhosting')->where('id', $relId)->first(['id', 'packageid']);
            if ($service && in_array((int) $service->packageid, $pids, true)) {
                return ['abortsend' => true];
            }
        }

        // ------------------------------------------------------------------
        // (b) Mailbox merge fields for OUR welcome template.
        // ------------------------------------------------------------------
        if ($ourTemplateName === '' || $messageName !== $ourTemplateName) {
            return [];
        }

        $service = Capsule::table('tblhosting')->where('id', $relId)->first(['id', 'packageid']);
        if (!$service || !in_array((int) $service->packageid, $pids, true)) {
            return [];
        }

        // Latest provision log row for this service containing mailbox data.
        $rows = Capsule::table('mod_imovelsite_log')
            ->where('serviceid', $relId)
            ->whereIn('action', ['provision', 'create'])
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get(['response']);

        $mailbox = null;
        foreach ($rows as $row) {
            if (!$row->response) {
                continue;
            }
            $data = json_decode((string) $row->response, true);
            if (is_array($data) && isset($data['mailbox']) && is_array($data['mailbox'])) {
                $mailbox = $data['mailbox'];
                break;
            }
        }

        if (!$mailbox) {
            return [];
        }

        $address = isset($mailbox['address']) ? $mailbox['address'] : (isset($mailbox['email']) ? $mailbox['email'] : '');
        if ((string) $address === '') {
            return [];
        }

        $webmail = isset($mailbox['webmail_url']) ? $mailbox['webmail_url']
            : (isset($mailbox['webmail']) ? $mailbox['webmail'] : 'https://webmail.imovelsite.com.br');
        $imap = isset($mailbox['imap_host']) ? $mailbox['imap_host']
            : (isset($mailbox['imap']) ? $mailbox['imap'] : 'mail.imovelsite.com.br');
        $smtp = isset($mailbox['smtp_host']) ? $mailbox['smtp_host']
            : (isset($mailbox['smtp']) ? $mailbox['smtp'] : 'mail.imovelsite.com.br');

        return [
            'mailbox_address' => (string) $address,
            'mailbox_password' => isset($mailbox['password']) ? (string) $mailbox['password'] : '',
            'mailbox_webmail_url' => (string) $webmail,
            'mailbox_imap_host' => (string) $imap,
            'mailbox_smtp_host' => (string) $smtp,
        ];
    } catch (\Throwable $e) {
        return [];
    }
});

// ---------------------------------------------------------------------------
// 5) AfterShoppingCartCheckout — manual domain registration queue
// ---------------------------------------------------------------------------

add_hook('AfterShoppingCartCheckout', 1, function ($vars) {
    try {
        $orderId = isset($vars['OrderID']) ? (int) $vars['OrderID'] : 0;
        if ($orderId <= 0) {
            return;
        }

        $settings = imovelsite_hk_settings();
        $pids = imovelsite_hk_product_ids();
        $lang = imovelsite_hk_lang();

        // Services of OUR product in this order.
        $serviceIds = isset($vars['ServiceIDs']) ? $vars['ServiceIDs'] : [];
        if (is_string($serviceIds)) {
            $serviceIds = array_filter(array_map('intval', explode(',', $serviceIds)));
        }
        $serviceQuery = Capsule::table('tblhosting')->whereIn('packageid', $pids);
        if (!empty($serviceIds)) {
            $serviceQuery->whereIn('id', $serviceIds);
        } else {
            $serviceQuery->where('orderid', $orderId);
        }
        $services = $serviceQuery->get(['id', 'userid']);
        if (count($services) === 0) {
            return;
        }
        $firstServiceId = (int) $services->first()->id;

        // Register/Transfer domain items in this order.
        $domainIds = isset($vars['DomainIDs']) ? $vars['DomainIDs'] : [];
        if (is_string($domainIds)) {
            $domainIds = array_filter(array_map('intval', explode(',', $domainIds)));
        }
        $domainQuery = Capsule::table('tbldomains')->whereIn('type', ['Register', 'Transfer']);
        if (!empty($domainIds)) {
            $domainQuery->whereIn('id', $domainIds);
        } else {
            $domainQuery->where('orderid', $orderId);
        }
        $domains = $domainQuery->get(['id', 'domain', 'type', 'userid']);
        if (count($domains) === 0) {
            return;
        }

        $order = Capsule::table('tblorders')->where('id', $orderId)->first(['id', 'userid']);
        $clientId = $order ? (int) $order->userid : 0;
        $clientName = '';
        if ($clientId > 0) {
            $client = Capsule::table('tblclients')->where('id', $clientId)->first(['firstname', 'lastname']);
            if ($client) {
                $clientName = trim($client->firstname . ' ' . $client->lastname);
            }
        }

        $systemUrl = '';
        try {
            $conf = Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->first(['value']);
            $systemUrl = $conf ? rtrim((string) $conf->value, '/') : '';
        } catch (\Throwable $e) {
            // optional
        }
        $orderLink = $systemUrl !== ''
            ? $systemUrl . '/admin/orders.php?action=view&id=' . $orderId
            : 'orders.php?action=view&id=' . $orderId;

        $now = date('Y-m-d H:i:s');

        foreach ($domains as $domain) {
            $domainName = strtolower(trim((string) $domain->domain));
            if ($domainName === '' || substr($domainName, -18) === '.imovelsite.com.br') {
                continue;
            }

            // Avoid duplicate pending rows for the same domain.
            $exists = Capsule::table('mod_imovelsite_log')
                ->where('action', 'domain_map')
                ->where('slug', substr($domainName, 0, 100))
                ->whereIn('status', ['pending', 'ready'])
                ->exists();
            if ($exists) {
                continue;
            }

            Capsule::table('mod_imovelsite_log')->insert([
                'serviceid' => $firstServiceId,
                'slug' => substr($domainName, 0, 100),
                'action' => 'domain_map',
                'status' => 'pending',
                'job_id' => null,
                'request' => json_encode([
                    'orderid' => $orderId,
                    'domainid' => (int) $domain->id,
                    'type' => (string) $domain->type,
                    'clientid' => $clientId,
                ]),
                'response' => null,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // To-Do list entry (due tomorrow).
            $titleTpl = isset($lang['todo_register_domain_title']) ? $lang['todo_register_domain_title'] : 'Registrar domínio %s (ImovelSite)';
            $descTpl = isset($lang['todo_register_domain_desc']) ? $lang['todo_register_domain_desc'] : "Cliente #%d (%s), domínio %s, pedido #%d.\n%s";
            Capsule::table('tbltodolist')->insert([
                'date' => date('Y-m-d'),
                'title' => sprintf($titleTpl, $domainName),
                'description' => sprintf($descTpl, $clientId, $clientName, $domainName, $orderId, $orderLink),
                'admin' => 0,
                'status' => 'New',
                'duedate' => date('Y-m-d', strtotime('+1 day')),
            ]);

            // Admin notification.
            $subjectTpl = isset($lang['notify_domain_subject']) ? $lang['notify_domain_subject'] : '[ImovelSite] Registrar domínio %s (pedido #%d)';
            $bodyTpl = isset($lang['notify_domain_body']) ? $lang['notify_domain_body'] : "Pedido #%d, domínio %s (%s). Cliente #%d %s. %s";
            $subject = sprintf($subjectTpl, $domainName, $orderId);
            $body = sprintf($bodyTpl, $orderId, $domainName, (string) $domain->type, $clientId, $clientName, $orderLink);

            if (function_exists('localAPI')) {
                localAPI('SendAdminEmail', [
                    'customsubject' => $subject,
                    'custommessage' => nl2br($body),
                    'type' => 'system',
                ]);
            }

            // Additionally, notify the configured addresses directly (the
            // SendAdminEmail API cannot target arbitrary recipients).
            $notify = trim((string) $settings['notify_email_domains']);
            if ($notify !== '') {
                foreach (explode(',', $notify) as $to) {
                    $to = trim($to);
                    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                        @mail($to, $subject, $body, 'Content-Type: text/plain; charset=UTF-8');
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('ImovelSite AfterShoppingCartCheckout: ' . $e->getMessage());
        }
    }
});

// ---------------------------------------------------------------------------
// 6) AfterCronJob — retry pending provisions; flag ready domain mappings
// ---------------------------------------------------------------------------

add_hook('AfterCronJob', 1, function ($vars) {
    try {
        $pids = imovelsite_hk_product_ids();
        $now = date('Y-m-d H:i:s');

        // --- Retry pending provisions (attempts < 10) -----------------------
        $pending = Capsule::table('mod_imovelsite_log')
            ->whereIn('action', ['provision', 'create'])
            ->where('status', 'pending')
            ->where('attempts', '<', 10)
            ->orderBy('id')
            ->limit(20)
            ->get();

        foreach ($pending as $row) {
            $serviceId = (int) $row->serviceid;
            $service = $serviceId > 0
                ? Capsule::table('tblhosting')->where('id', $serviceId)->first(['id', 'packageid', 'domain', 'domainstatus'])
                : null;

            if (!$service) {
                Capsule::table('mod_imovelsite_log')->where('id', $row->id)->update([
                    'status' => 'error',
                    'updated_at' => $now,
                ]);
                continue;
            }
            if (!in_array((int) $service->packageid, $pids, true)) {
                continue;
            }

            $hasDomain = trim((string) $service->domain) !== '';
            if ($hasDomain && $service->domainstatus === 'Active') {
                // Module completed (possibly via an earlier retry) — mark done.
                Capsule::table('mod_imovelsite_log')->where('id', $row->id)->update([
                    'status' => 'success',
                    'updated_at' => $now,
                ]);
                continue;
            }

            if (!$hasDomain || $service->domainstatus === 'Pending') {
                // Increment BEFORE calling so a fatal inside ModuleCreate
                // cannot cause an infinite retry loop.
                Capsule::table('mod_imovelsite_log')->where('id', $row->id)->update([
                    'attempts' => (int) $row->attempts + 1,
                    'updated_at' => $now,
                ]);
                if (function_exists('localAPI')) {
                    $result = localAPI('ModuleCreate', ['serviceid' => $serviceId]);
                    if (isset($result['result']) && $result['result'] === 'success') {
                        Capsule::table('mod_imovelsite_log')->where('id', $row->id)->update([
                            'status' => 'success',
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                        imovelsite_hk_send_welcome_once($serviceId);
                    }
                }
            }
            // Other domainstatus (Suspended/Terminated/Cancelled): leave as is.
        }

        // --- Domain mappings whose WHMCS domain is now Active ---------------
        $maps = Capsule::table('mod_imovelsite_log')
            ->where('action', 'domain_map')
            ->where('status', 'pending')
            ->limit(50)
            ->get();

        foreach ($maps as $row) {
            $slug = trim((string) $row->slug);
            if ($slug === '') {
                continue;
            }
            $active = Capsule::table('tbldomains')
                ->where('domain', $slug)
                ->where('status', 'Active')
                ->exists();
            if ($active) {
                Capsule::table('mod_imovelsite_log')->where('id', $row->id)->update([
                    'status' => 'ready',
                    'updated_at' => $now,
                ]);
                if (function_exists('logActivity')) {
                    logActivity('ImovelSite: domínio ' . $slug . ' ativo no WHMCS — pronto para mapeamento manual '
                        . '(log #' . $row->id . ', serviço #' . $row->serviceid . ').');
                }
            }
        }
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('ImovelSite AfterCronJob: ' . $e->getMessage());
        }
    }
});
