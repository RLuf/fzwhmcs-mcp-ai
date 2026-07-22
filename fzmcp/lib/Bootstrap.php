<?php

namespace FzMcp;

/**
 * Wires the object graph for the three transports. Locates and loads the
 * WHMCS init.php so localAPI()/Capsule are available, then builds a Server
 * backed by LocalApi + database-backed Permissions.
 */
class Bootstrap
{
    /** @var bool */
    private static $whmcsLoaded = false;

    /**
     * Attempt to load the WHMCS bootstrap (init.php). Idempotent.
     *
     * @return bool true if WHMCS is available afterwards.
     */
    public static function loadWhmcs()
    {
        if (self::$whmcsLoaded || function_exists('localAPI')) {
            self::$whmcsLoaded = true;
            return true;
        }
        // modules/addons/fzmcp/lib -> WHMCS root is four levels up.
        $candidates = array(
            dirname(dirname(dirname(dirname(__DIR__)))) . '/init.php',
            dirname(dirname(dirname(dirname(dirname(__DIR__))))) . '/init.php',
        );
        foreach ($candidates as $init) {
            if (is_file($init)) {
                require_once $init;
                if (function_exists('localAPI')) {
                    self::$whmcsLoaded = true;
                    return true;
                }
            }
        }
        return function_exists('localAPI');
    }

    /**
     * Register the FzMcp autoloader.
     */
    public static function autoload()
    {
        require_once __DIR__ . '/Autoload.php';
    }

    /**
     * Build a production Server (LocalApi + DB permissions).
     */
    public static function server()
    {
        self::autoload();
        $adminUser = Config::get('admin_user', '');
        $api   = new LocalApi($adminUser);
        $perms = Permissions::fromDatabase();
        return new Server($api, $perms);
    }

    /**
     * The configured bearer token (empty string if unset).
     */
    public static function token()
    {
        return (string) Config::get('bearer_token', '');
    }

    /**
     * Lê o bearer token DIRETO do banco (PDO via configuration.php), SEM carregar
     * o WHMCS inteiro. Usado para autenticar o handshake (initialize/ping) de forma
     * instantânea — o bootstrap do WHMCS (init.php) pode levar segundos em cold start
     * ou no check de licença, e o handshake não pode pagar esse custo.
     *
     * @return string token configurado, ou '' se indisponível.
     */
    public static function tokenLite()
    {
        return self::settingLite('bearer_token');
    }

    /**
     * Read one public-transport setting without bootstrapping all of WHMCS.
     * Only an explicit allowlist can be queried through this lightweight path.
     */
    public static function settingLite($setting)
    {
        $allowed = array('bearer_token', 'allowed_origins', 'enable_http');
        if (!in_array($setting, $allowed, true)) {
            return '';
        }
        static $cached = null;
        if (!is_array($cached)) {
            $cached = array();
        }
        if (array_key_exists($setting, $cached)) {
            return $cached[$setting];
        }
        $cached[$setting] = '';
        try {
            $conf = dirname(dirname(dirname(dirname(__DIR__)))) . '/configuration.php';
            if (!is_file($conf)) {
                return $cached[$setting];
            }
            // Inclui em escopo isolado para capturar as variáveis de conexão.
            $vars = (static function () use ($conf) {
                include $conf;
                return array(
                    'host' => isset($db_host) ? $db_host : 'localhost',
                    'user' => isset($db_username) ? $db_username : '',
                    'pass' => isset($db_password) ? $db_password : '',
                    'name' => isset($db_name) ? $db_name : '',
                    'port' => isset($db_port) && $db_port ? $db_port : null,
                );
            })();
            if ('' === $vars['name']) {
                return $cached[$setting];
            }
            $dsn = 'mysql:host=' . $vars['host'] . ($vars['port'] ? ';port=' . $vars['port'] : '')
                . ';dbname=' . $vars['name'] . ';charset=utf8';
            $pdo = new \PDO($dsn, $vars['user'], $vars['pass'], array(
                \PDO::ATTR_TIMEOUT => 3,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT,
            ));
            $stmt = $pdo->prepare("SELECT value FROM tbladdonmodules WHERE module='fzmcp' AND setting=:setting LIMIT 1");
            $stmt->execute(array(':setting' => $setting));
            $val = $stmt->fetchColumn();
            $cached[$setting] = $val === false ? '' : (string) $val;
        } catch (\Throwable $e) {
            $cached[$setting] = '';
        }
        return $cached[$setting];
    }
}
