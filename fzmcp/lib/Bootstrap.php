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
}
