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
    const HASH_PREFIX = 'sha256:';

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
        if (self::isHashedToken($configured)) {
            return hash_equals(
                substr($configured, strlen(self::HASH_PREFIX)),
                hash('sha256', $presented)
            );
        }
        return hash_equals($configured, $presented);
    }

    /** Store only a one-way digest of the bearer token. */
    public static function hashToken($token)
    {
        if (!is_string($token) || $token === '') {
            return '';
        }
        return self::HASH_PREFIX . hash('sha256', $token);
    }

    /** Whether a stored value uses the supported one-way token format. */
    public static function isHashedToken($stored)
    {
        return is_string($stored)
            && preg_match('/^sha256:[a-f0-9]{64}$/', $stored) === 1;
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
