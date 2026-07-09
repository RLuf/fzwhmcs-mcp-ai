<?php
/**
 * ImovelSite — loader dos hooks do addon.
 *
 * O carregador nativo de hooks de addons desta instalação não incluiu o
 * modules/addons/imovelsite/hooks.php mesmo com o addon ativo (ActiveAddonModules
 * + AddonModulesHooks configurados). Este loader garante o carregamento pelo
 * caminho includes/hooks/, que o WHMCS sempre processa.
 *
 * Guardas:
 *  - só carrega se o addon estiver ativo (tbladdonmodules possui version);
 *  - nunca inclui duas vezes (se o loader nativo passar a funcionar, este vira no-op).
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (function_exists('imovelsite_hk_settings')) {
    return; // hooks do addon ja carregados pelo WHMCS
}

try {
    $active = Capsule::table('tbladdonmodules')
        ->where('module', 'imovelsite')
        ->where('setting', 'version')
        ->exists();
    if (!$active) {
        return;
    }
} catch (\Throwable $e) {
    return;
}

$hooksFile = dirname(__DIR__, 2) . '/modules/addons/imovelsite/hooks.php';
if (is_file($hooksFile)) {
    require_once $hooksFile;
}
