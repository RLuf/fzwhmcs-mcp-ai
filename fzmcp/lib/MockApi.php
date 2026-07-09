<?php

namespace FzMcp;

/**
 * Offline API adapter used by the self-test harness when WHMCS cannot be
 * bootstrapped from the CLI. Returns canned, localAPI-shaped responses so the
 * protocol, schema validation, permission gating and dispatch are all still
 * exercised end-to-end without touching the live system.
 */
class MockApi implements ApiInterface
{
    public function call($action, array $params)
    {
        // A few representative actions get realistic shapes; everything else
        // gets a generic success envelope echoing the action + params so the
        // dispatch path is proven.
        switch ($action) {
            case 'GetClients':
                return array(
                    'result'      => 'success',
                    'totalresults'=> 2,
                    'clients'     => array('client' => array(
                        array('id' => 1, 'firstname' => 'Ana',  'lastname' => 'Souza', 'email' => 'ana@example.com'),
                        array('id' => 2, 'firstname' => 'Bruno','lastname' => 'Lima',  'email' => 'bruno@example.com'),
                    )),
                );
            case 'GetTickets':
                return array(
                    'result'       => 'success',
                    'totalresults' => 1,
                    'tickets'      => array('ticket' => array(
                        array('id' => 10, 'tid' => 'ABC-000010', 'subject' => 'Duvida de fatura', 'status' => 'Open'),
                    )),
                );
            case 'GetSupportDepartments':
                return array(
                    'result'      => 'success',
                    'departments' => array('department' => array(
                        array('id' => 1, 'name' => 'Suporte'),
                        array('id' => 2, 'name' => 'Financeiro'),
                    )),
                );
            case 'WhmcsDetails':
                return array(
                    'result'  => 'success',
                    'whmcs'   => array('version' => '8.13.1'),
                );
            case 'GetStats':
                return array(
                    'result'          => 'success',
                    'income'          => array('totalCollections' => '0.00'),
                    'orders'          => array('pending' => 0),
                    'tickets'         => array('awaitingReply' => 0),
                );
            default:
                return array(
                    'result'  => 'success',
                    'mock'    => true,
                    'action'  => $action,
                    'echo'    => $params,
                    'message' => 'Resposta simulada (modo offline).',
                );
        }
    }

    public function mode()
    {
        return 'mock';
    }
}
