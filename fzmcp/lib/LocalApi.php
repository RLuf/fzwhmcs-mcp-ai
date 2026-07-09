<?php

namespace FzMcp;

/**
 * Production API adapter: dispatches to WHMCS localAPI().
 *
 * Requires WHMCS to be bootstrapped (init.php loaded) so that the global
 * localAPI() function is available.
 */
class LocalApi implements ApiInterface
{
    /** @var string Admin username with API access, from addon config. */
    private $adminUser;

    public function __construct($adminUser)
    {
        $this->adminUser = (string) $adminUser;
    }

    public function call($action, array $params)
    {
        if (!function_exists('localAPI')) {
            return array(
                'result'  => 'error',
                'message' => 'WHMCS nao esta inicializado (localAPI indisponivel).',
            );
        }

        // Never allow a caller to override the admin identity or inject a
        // response-format that would break parsing.
        unset($params['action']);
        $params['responsetype'] = 'json';

        try {
            $result = localAPI($action, $params, $this->adminUser);
        } catch (\Throwable $e) {
            return array(
                'result'  => 'error',
                'message' => 'Excecao ao executar ' . $action . ': ' . $e->getMessage(),
            );
        }

        if (!is_array($result)) {
            return array('result' => 'error', 'message' => 'Resposta invalida da API do WHMCS.');
        }
        return $result;
    }

    public function mode()
    {
        return 'live';
    }
}
