<?php

namespace FzMcp;

/**
 * Minimal, dependency-free JSON Schema validator covering the subset of
 * Draft-07 features actually used by the tool catalogue:
 *   - type (object, string, integer, number, boolean, array)
 *   - required
 *   - enum
 *   - properties (recursive)
 *   - items (for arrays)
 *   - additionalProperties (bool)
 *
 * Returns a list of human-readable error strings (empty = valid).
 */
class SchemaValidator
{
    /**
     * @param array $schema JSON Schema (as PHP array)
     * @param mixed $data   The value to validate
     * @return string[] validation errors (empty means valid)
     */
    public static function validate(array $schema, $data, $path = '$')
    {
        $errors = array();

        $type = isset($schema['type']) ? $schema['type'] : null;
        if ($type !== null && !self::checkType($type, $data)) {
            $errors[] = sprintf('%s: esperado tipo "%s"', $path, is_array($type) ? implode('|', $type) : $type);
            // If the base type is wrong there is no point recursing further.
            return $errors;
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $ok = false;
            foreach ($schema['enum'] as $candidate) {
                if ($candidate === $data || (string) $candidate === (string) $data) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $errors[] = sprintf('%s: valor fora do enum permitido', $path);
            }
        }

        if ($type === 'object' || (is_array($data) && self::isAssoc($data))) {
            $props = isset($schema['properties']) ? $schema['properties'] : array();
            $required = isset($schema['required']) ? $schema['required'] : array();
            $dataArr = is_array($data) ? $data : array();

            foreach ($required as $req) {
                if (!array_key_exists($req, $dataArr)) {
                    $errors[] = sprintf('%s: propriedade obrigatoria ausente "%s"', $path, $req);
                }
            }

            $additional = array_key_exists('additionalProperties', $schema)
                ? $schema['additionalProperties'] : true;
            foreach ($dataArr as $key => $value) {
                if (isset($props[$key]) && is_array($props[$key])) {
                    $errors = array_merge(
                        $errors,
                        self::validate($props[$key], $value, $path . '.' . $key)
                    );
                } elseif ($additional === false) {
                    $errors[] = sprintf('%s: propriedade nao permitida "%s"', $path, $key);
                }
            }
        }

        if ($type === 'array' && is_array($data) && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($data as $i => $item) {
                $errors = array_merge(
                    $errors,
                    self::validate($schema['items'], $item, $path . '[' . $i . ']')
                );
            }
        }

        return $errors;
    }

    private static function checkType($type, $data)
    {
        if (is_array($type)) {
            foreach ($type as $t) {
                if (self::checkType($t, $data)) {
                    return true;
                }
            }
            return false;
        }
        switch ($type) {
            case 'object':
                return is_array($data); // associative array in PHP
            case 'array':
                return is_array($data) && (empty($data) || !self::isAssoc($data));
            case 'string':
                // JSON-RPC clients frequently send scalars as strings; accept
                // scalars that stringify cleanly.
                return is_string($data) || is_int($data) || is_float($data);
            case 'integer':
                return is_int($data) || (is_string($data) && preg_match('/^-?\d+$/', $data));
            case 'number':
                return is_int($data) || is_float($data)
                    || (is_string($data) && is_numeric($data));
            case 'boolean':
                return is_bool($data)
                    || (is_string($data) && in_array(strtolower($data), array('true', 'false', '0', '1'), true))
                    || $data === 0 || $data === 1;
            case 'null':
                return $data === null;
            default:
                return true;
        }
    }

    private static function isAssoc($arr)
    {
        if (!is_array($arr) || $arr === array()) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
