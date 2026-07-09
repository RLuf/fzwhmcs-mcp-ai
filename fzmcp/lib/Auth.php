<?php

namespace FzMcp;

/**
 * Bearer-token authentication for the HTTP and SSE transports.
 *
 * stdio is considered trusted (local, already-authenticated shell) and does
 * not go through this class.
 */
class Auth
{
    /**
     * Extract the presented bearer token from request headers.
     *
     * @param array $headers Associative header map (case-insensitive lookup).
     * @return string|null
     */
    public static function extractToken(array $headers)
    {
        $auth = null;
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                $auth = $value;
                break;
            }
        }
        if ($auth === null) {
            return null;
        }
        if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Constant-time comparison of the presented token to the configured one.
     *
     * @param string|null $presented
     * @param string      $configured
     * @return bool
     */
    public static function verify($presented, $configured)
    {
        if (!is_string($presented) || $presented === '' || !is_string($configured) || $configured === '') {
            return false;
        }
        return hash_equals($configured, $presented);
    }

    /**
     * Generate a cryptographically strong bearer token.
     *
     * @return string
     */
    public static function generateToken()
    {
        return 'fzmcp_' . bin2hex(random_bytes(24));
    }
}
