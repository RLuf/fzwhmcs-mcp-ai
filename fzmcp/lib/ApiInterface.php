<?php

namespace FzMcp;

/**
 * Abstraction over the WHMCS API call surface so the server can be exercised
 * offline (MockApi) or in production (LocalApi -> localAPI()).
 */
interface ApiInterface
{
    /**
     * Execute a WHMCS API action.
     *
     * @param string $action WHMCS API action name (e.g. "GetClients")
     * @param array  $params Associative parameters
     * @return array WHMCS-shaped response, always containing a "result" key
     *               ("success" or "error") plus action-specific data.
     */
    public function call($action, array $params);

    /**
     * @return string A short label describing the backend ("live" | "mock").
     */
    public function mode();
}
