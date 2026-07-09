<?php

namespace FzMcp;

/**
 * JSON-RPC 2.0 helpers and canonical error codes.
 *
 * Standard codes come from the JSON-RPC 2.0 spec; the -320xx range is used
 * for MCP/server-specific conditions (auth, permission, disabled tool...).
 */
class JsonRpc
{
    // JSON-RPC 2.0 standard
    const PARSE_ERROR      = -32700;
    const INVALID_REQUEST  = -32600;
    const METHOD_NOT_FOUND = -32601;
    const INVALID_PARAMS   = -32602;
    const INTERNAL_ERROR   = -32603;

    // fzMCP application-specific
    const UNAUTHORIZED     = -32001; // bad/missing bearer token
    const TOOL_UNKNOWN     = -32002; // tool not in registry
    const TOOL_DISABLED    = -32010; // tool level = disabled
    const TOOL_FORBIDDEN   = -32011; // write tool without 'act' level
    const TOOL_EXEC_ERROR  = -32020; // WHMCS action returned an error

    /**
     * Build a JSON-RPC success response envelope.
     *
     * @param mixed $id
     * @param mixed $result
     */
    public static function result($id, $result)
    {
        return array(
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        );
    }

    /**
     * Build a JSON-RPC error response envelope.
     *
     * @param mixed  $id
     * @param int    $code
     * @param string $message
     * @param mixed  $data
     */
    public static function error($id, $code, $message, $data = null)
    {
        $err = array('code' => $code, 'message' => $message);
        if ($data !== null) {
            $err['data'] = $data;
        }
        return array(
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => $err,
        );
    }

    /**
     * Encode a value as compact JSON with slashes/unicode preserved.
     *
     * @param mixed $value
     */
    public static function encode($value)
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Encode a value as human readable JSON (used for tool text content).
     *
     * @param mixed $value
     */
    public static function pretty($value)
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
