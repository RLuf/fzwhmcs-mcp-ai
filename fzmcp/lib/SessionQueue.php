<?php

namespace FzMcp;

/**
 * File-backed message queue used by the legacy SSE transport to deliver
 * JSON-RPC responses produced by a POST /messages request onto the open
 * GET event-stream belonging to the same session.
 *
 * This is deliberately dependency-free (no broker) so it runs on plain shared
 * hosting. Messages are one-file-per-message under runtime/sessions/<id>/.
 */
class SessionQueue
{
    /** @var string */
    private $dir;

    public function __construct($sessionId)
    {
        $sessionId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $sessionId);
        $base = dirname(__DIR__) . '/runtime/sessions';
        if (!is_dir($base)) {
            @mkdir($base, 0770, true);
        }
        $this->dir = $base . '/' . $sessionId;
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0770, true);
        }
    }

    /**
     * Persist an encoded message for later delivery.
     */
    public function push($payload)
    {
        $file = $this->dir . '/' . microtime(true) . '-' . bin2hex(random_bytes(4)) . '.json';
        @file_put_contents($file, is_string($payload) ? $payload : JsonRpc::encode($payload), LOCK_EX);
    }

    /**
     * Fetch and remove all pending messages (oldest first).
     *
     * @return string[] raw JSON payloads
     */
    public function drain()
    {
        $out = array();
        $files = glob($this->dir . '/*.json');
        if (!$files) {
            return $out;
        }
        sort($files);
        foreach ($files as $file) {
            $data = @file_get_contents($file);
            if ($data !== false) {
                $out[] = $data;
            }
            @unlink($file);
        }
        return $out;
    }

    /**
     * Remove the session directory (on stream close).
     */
    public function destroy()
    {
        $files = glob($this->dir . '/*');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->dir);
    }

    public static function newId()
    {
        return bin2hex(random_bytes(16));
    }
}
