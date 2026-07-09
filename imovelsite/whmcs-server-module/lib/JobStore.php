<?php
/**
 * Persistencia de jobs de provisionamento na tabela mod_imovelsite_log.
 *
 * A tabela e criada pelo modulo addon (modules/addons/imovelsite/). Enquanto ela
 * nao existir, todos os metodos degradam graciosamente (retornam null/false)
 * sem quebrar o fluxo de provisionamento.
 *
 * Schema: id, serviceid, slug, action, status, job_id, request TEXT,
 *         response TEXT, attempts, created_at, updated_at.
 */

namespace WHMCS\Module\Server\Imovelsite;

use WHMCS\Database\Capsule;

class JobStore
{
    const TABLE = 'mod_imovelsite_log';

    /** @var bool|null cache do hasTable dentro da mesma requisicao */
    private static $tableExists;

    private static function ready(): bool
    {
        if (self::$tableExists === null) {
            try {
                self::$tableExists = Capsule::schema()->hasTable(self::TABLE);
            } catch (\Throwable $e) {
                self::$tableExists = false;
            }
        }
        return self::$tableExists;
    }

    /**
     * Grava uma nova linha de job. Retorna o id inserido ou null.
     *
     * @param int         $serviceid
     * @param string      $slug
     * @param string      $action  create|suspend|unsuspend|terminate
     * @param string      $status  pending|running|done|error|noop
     * @param array       $request
     * @param array       $response
     * @return int|null
     */
    public static function record(int $serviceid, string $slug, string $action, string $status, array $request, array $response): ?int
    {
        if (!self::ready()) {
            return null;
        }
        try {
            $now = date('Y-m-d H:i:s');
            return (int) Capsule::table(self::TABLE)->insertGetId([
                'serviceid'  => $serviceid,
                'slug'       => $slug,
                'action'     => $action,
                'status'     => $status,
                'job_id'     => (string) ($response['job_id'] ?? ''),
                'request'    => json_encode(ApiClient::sanitize($request)),
                'response'   => json_encode($response),
                'attempts'   => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Linha mais recente de um servico, ou null.
     *
     * @return object|null
     */
    public static function latestFor(int $serviceid)
    {
        if (!self::ready()) {
            return null;
        }
        try {
            return Capsule::table(self::TABLE)
                ->where('serviceid', $serviceid)
                ->orderBy('id', 'desc')
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Atualiza status/response de uma linha existente (incrementa attempts).
     */
    public static function markStatus(?int $id, string $status, array $response): bool
    {
        if ($id === null || $id <= 0 || !self::ready()) {
            return false;
        }
        try {
            Capsule::table(self::TABLE)->where('id', $id)->update([
                'status'     => $status,
                'response'   => json_encode($response),
                'attempts'   => Capsule::raw('attempts + 1'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
