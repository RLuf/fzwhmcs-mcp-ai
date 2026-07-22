<?php

namespace FzMcp;

/**
 * Transport-agnostic MCP server core.
 *
 * Implements the JSON-RPC 2.0 method set required by the Model Context
 * Protocol:  initialize, notifications/initialized, tools/list, tools/call,
 * ping.  Batch requests and unknown methods are handled per the JSON-RPC
 * spec.  Transports (HTTP / SSE / stdio) hand raw request bodies to
 * handleMessage()/handleRaw() and serialise whatever comes back.
 */
class Server
{
    const SERVER_NAME    = 'fzWHMCS-MCP-AI';
    const SERVER_VERSION = '1.0.1';

    /** Protocol version we advertise; also accept 2025-06-18 clients. */
    const PROTOCOL_VERSION = '2024-11-05';
    const SUPPORTED_PROTOCOLS = array('2024-11-05', '2025-06-18', '2025-03-26');

    /** @var ApiInterface */
    private $api;

    /** @var Permissions */
    private $perms;

    public function __construct(ApiInterface $api, Permissions $perms)
    {
        $this->api   = $api;
        $this->perms = $perms;
    }

    /**
     * Parse a raw JSON body (single request or batch) and dispatch.
     *
     * @param string $raw
     * @return array|null Response envelope, batch array, or null for a pure
     *                    notification (nothing to send back).
     */
    public function handleRaw($raw)
    {
        $decoded = json_decode($raw, true);
        if ($decoded === null && trim((string) $raw) !== 'null') {
            return JsonRpc::error(null, JsonRpc::PARSE_ERROR, 'JSON invalido.');
        }

        // Batch request.
        if (is_array($decoded) && $decoded !== array() && array_keys($decoded) === range(0, count($decoded) - 1)) {
            if ($decoded === array()) {
                return JsonRpc::error(null, JsonRpc::INVALID_REQUEST, 'Batch vazio.');
            }
            $responses = array();
            foreach ($decoded as $one) {
                $resp = $this->handleMessage($one);
                if ($resp !== null) {
                    $responses[] = $resp;
                }
            }
            return empty($responses) ? null : $responses;
        }

        return $this->handleMessage($decoded);
    }

    /**
     * Dispatch a single decoded JSON-RPC message.
     *
     * @param mixed $msg
     * @return array|null
     */
    public function handleMessage($msg)
    {
        if (!is_array($msg)) {
            return JsonRpc::error(null, JsonRpc::INVALID_REQUEST, 'Requisicao invalida.');
        }

        $id     = array_key_exists('id', $msg) ? $msg['id'] : null;
        $method = isset($msg['method']) ? $msg['method'] : null;
        $params = isset($msg['params']) && is_array($msg['params']) ? $msg['params'] : array();
        $isNotification = !array_key_exists('id', $msg);

        if (!is_string($method) || $method === '') {
            return $isNotification ? null : JsonRpc::error($id, JsonRpc::INVALID_REQUEST, 'Metodo ausente.');
        }

        switch ($method) {
            case 'initialize':
                return JsonRpc::result($id, $this->initialize($params));

            case 'notifications/initialized':
            case 'initialized':
                return null; // notification, no response

            case 'notifications/cancelled':
                return null;

            case 'ping':
                return JsonRpc::result($id, new \stdClass());

            case 'tools/list':
                return JsonRpc::result($id, $this->toolsList());

            case 'tools/call':
                return $this->toolsCall($id, $params);

            default:
                if ($isNotification) {
                    return null;
                }
                return JsonRpc::error($id, JsonRpc::METHOD_NOT_FOUND, 'Metodo desconhecido: ' . $method);
        }
    }

    // ---- method implementations -------------------------------------------

    private function initialize(array $params)
    {
        $requested = isset($params['protocolVersion']) ? $params['protocolVersion'] : self::PROTOCOL_VERSION;
        $protocol  = in_array($requested, self::SUPPORTED_PROTOCOLS, true) ? $requested : self::PROTOCOL_VERSION;

        return array(
            'protocolVersion' => $protocol,
            'serverInfo'      => array(
                'name'    => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ),
            'capabilities'    => array(
                'tools' => array('listChanged' => false),
            ),
            'instructions'    => 'Servidor MCP do WHMCS (fzWHMCS-MCP-AI). Ferramentas de escrita '
                . 'exigem nivel "agir" configurado pelo operador.',
        );
    }

    private function toolsList()
    {
        $tools = array();
        foreach (ToolRegistry::all() as $tool) {
            if (!$this->perms->isVisible($tool['name'])) {
                continue;
            }
            $tools[] = array(
                'name'        => $tool['name'],
                'description' => $this->describe($tool),
                'inputSchema' => $this->normaliseSchema($tool['inputSchema']),
            );
        }
        return array('tools' => $tools);
    }

    /**
     * @param mixed $id
     */
    private function toolsCall($id, array $params)
    {
        $name = isset($params['name']) ? $params['name'] : null;
        $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : array();

        if (!is_string($name) || $name === '') {
            return JsonRpc::error($id, JsonRpc::INVALID_PARAMS, 'Parametro "name" ausente.');
        }

        $tool = ToolRegistry::get($name);
        if ($tool === null) {
            return JsonRpc::error($id, JsonRpc::TOOL_UNKNOWN, 'Ferramenta desconhecida: ' . $name);
        }

        // Hidden (disabled) tools should not even acknowledge existence beyond
        // the permission error.
        $auth = $this->perms->authorize($tool);
        if (!$auth['allowed']) {
            // Return as a tools/call *result* with isError so MCP clients treat
            // it as a tool error (not a transport error), per MCP guidance.
            return JsonRpc::result($id, $this->errorContent($auth['message']));
        }

        // Validate arguments against the tool's input schema.
        $schema = $this->normaliseSchema($tool['inputSchema']);
        $errors = SchemaValidator::validate($schema, $args);
        if (!empty($errors)) {
            return JsonRpc::result($id, $this->errorContent(
                "Argumentos invalidos para {$name}:\n- " . implode("\n- ", $errors)
            ));
        }

        // Dispatch to WHMCS.
        $response = $this->api->call($tool['action'], $args);

        $result = isset($response['result']) ? $response['result'] : 'error';
        if ($result === 'error' || (isset($response['status']) && $response['status'] === 'error')) {
            $message = isset($response['message']) ? $response['message'] : 'Erro desconhecido da API do WHMCS.';
            return JsonRpc::result($id, $this->errorContent("WHMCS ({$tool['action']}): " . $message));
        }

        return JsonRpc::result($id, array(
            'content' => array(array(
                'type' => 'text',
                'text' => JsonRpc::pretty($response),
            )),
            'isError' => false,
        ));
    }

    /**
     * Execute a tool by name with arguments, bypassing nothing except the
     * transport. Used by the self-test harness. Returns the raw tools/call
     * result payload (with content/isError).
     */
    public function callTool($name, array $arguments)
    {
        return $this->toolsCall('selftest', array('name' => $name, 'arguments' => $arguments));
    }

    // ---- helpers ----------------------------------------------------------

    private function errorContent($message)
    {
        return array(
            'content' => array(array('type' => 'text', 'text' => $message)),
            'isError' => true,
        );
    }

    private function describe(array $tool)
    {
        $tag = $tool['rw'] === ToolRegistry::WRITE ? '[ESCRITA]' : '[LEITURA]';
        return sprintf('%s [%s] %s (acao WHMCS: %s)', $tag, $tool['category'], $tool['description'], $tool['action']);
    }

    /**
     * Ensure the schema is a well-formed JSON Schema object with the standard
     * $schema declaration MCP clients expect.
     */
    private function normaliseSchema(array $schema)
    {
        if (!isset($schema['type'])) {
            $schema['type'] = 'object';
        }
        // An empty PHP array JSON-encodes as "[]"; JSON Schema requires an
        // object ("{}") for "properties". Coerce so clients get valid schemas.
        if (!isset($schema['properties']) || $schema['properties'] === array()) {
            $schema['properties'] = new \stdClass();
        }
        return $schema;
    }

    public function api()
    {
        return $this->api;
    }

    public function permissions()
    {
        return $this->perms;
    }
}
