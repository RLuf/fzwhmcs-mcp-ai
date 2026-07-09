<?php
/**
 * Cliente HTTP para a API REST do plugin ImovelSite (WordPress) no servidor RED.
 *
 * Base URL: https://{serverhostname}{api_base_path}
 * Auth: HTTP Basic (serverusername + serveraccesshash = WordPress Application Password).
 */

namespace WHMCS\Module\Server\Imovelsite;

class ApiClient
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $username;

    /** @var string */
    private $password;

    /** @var int */
    private $timeout;

    /** Caminho base padrao da API quando a opcao do produto esta vazia. */
    const DEFAULT_BASE_PATH = '/wp-json/imovelsite/v1';

    /**
     * @param array $params Parametros padrao do modulo WHMCS.
     *                      Usa: serverhostname|serverip, serverusername, serveraccesshash,
     *                      configoption1 (api_base_path), configoption2 (request_timeout).
     * @throws \RuntimeException se o servidor nao tiver hostname/IP.
     */
    public function __construct(array $params)
    {
        $host = trim((string) ($params['serverhostname'] ?? ''));
        if ($host === '') {
            $host = trim((string) ($params['serverip'] ?? ''));
        }
        if ($host === '') {
            throw new \RuntimeException('Servidor sem hostname/IP configurado no WHMCS.');
        }

        $basePath = trim((string) ($params['configoption1'] ?? ''));
        if ($basePath === '') {
            $basePath = self::DEFAULT_BASE_PATH;
        }

        $this->baseUrl  = 'https://' . $host . '/' . trim($basePath, '/');
        $this->username = (string) ($params['serverusername'] ?? '');
        $this->password = (string) ($params['serveraccesshash'] ?? '');

        $timeout = (int) ($params['configoption2'] ?? 0);
        $this->timeout = $timeout > 0 ? $timeout : 30;
    }

    /**
     * @return array{code:int,body:array,raw:string}
     */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * @return array{code:int,body:array,raw:string}
     */
    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, $body);
    }

    /**
     * @return array{code:int,body:array,raw:string}
     */
    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * Executa a requisicao HTTP.
     *
     * @return array{code:int,body:array,raw:string}
     * @throws \RuntimeException em erro de rede/cURL.
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        $ch = curl_init($url);
        $headers = ['Accept: application/json'];

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $this->username . ':' . $this->password,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('Falha de conexão com a API (' . $url . '): ' . $err);
        }

        $decoded = json_decode((string) $raw, true);

        return [
            'code' => $code,
            'body' => is_array($decoded) ? $decoded : [],
            'raw'  => (string) $raw,
        ];
    }

    /**
     * Mascara credenciais em estruturas de request/response antes de logar.
     *
     * @param mixed $data
     * @return mixed
     */
    public static function sanitize($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        $masked = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/pass|accesshash|secret|token/i', $key)) {
                $masked[$key] = '***';
            } elseif (is_array($value)) {
                $masked[$key] = self::sanitize($value);
            } else {
                $masked[$key] = $value;
            }
        }
        return $masked;
    }
}
