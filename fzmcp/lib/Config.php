<?php

namespace FzMcp;

/**
 * Read/write access to the addon's stored settings in tbladdonmodules
 * (module = "fzmcp"). WHMCS persists the fields declared in fzmcp_config()
 * here automatically; this helper also stores the generated bearer token and
 * lets the panel update values.
 */
class Config
{
    const MODULE = 'fzmcp';

    /** @var array<string,string>|null in-memory cache */
    private static $cache = null;

    /**
     * @return array<string,string>
     */
    public static function all()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = array();
        if (class_exists('\WHMCS\Database\Capsule')) {
            try {
                $rows = \WHMCS\Database\Capsule::table('tbladdonmodules')
                    ->where('module', self::MODULE)
                    ->get(array('setting', 'value'));
                foreach ($rows as $row) {
                    $setting = is_array($row) ? $row['setting'] : $row->setting;
                    $value   = is_array($row) ? $row['value'] : $row->value;
                    $out[$setting] = $value;
                }
            } catch (\Throwable $e) {
                // no table yet
            }
        }
        self::$cache = $out;
        return $out;
    }

    public static function get($key, $default = '')
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * Upsert a setting value in tbladdonmodules.
     */
    public static function set($key, $value)
    {
        if (!class_exists('\WHMCS\Database\Capsule')) {
            return false;
        }
        $exists = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', self::MODULE)
            ->where('setting', $key)
            ->exists();
        if ($exists) {
            \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', self::MODULE)
                ->where('setting', $key)
                ->update(array('value' => $value));
        } else {
            \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->insert(array('module' => self::MODULE, 'setting' => $key, 'value' => $value));
        }
        self::$cache = null;
        return true;
    }
}
