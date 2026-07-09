<?php

namespace FzMcp;

/**
 * DB-backed per-tool permission model.
 *
 * Levels:
 *   disabled : tool hidden from tools/list and refused on tools/call.
 *   read     : "informar / registrar" - read tools execute; write tools are
 *              visible but refused (they require promotion to "act").
 *   act      : "informar e agir" - write tools may execute.
 *
 * The authoritative store is the `mod_fzmcp_tools` table. When WHMCS/Capsule
 * is unavailable (offline self-test) the class falls back to the registry
 * defaults so the gate can still be exercised.
 */
class Permissions
{
    const TABLE = 'mod_fzmcp_tools';

    /** @var array<string,string> tool name => level */
    private $levels;

    /**
     * @param array<string,string> $levels
     */
    public function __construct(array $levels)
    {
        $this->levels = $levels;
    }

    /**
     * Build a Permissions instance from registry defaults (no DB required).
     */
    public static function defaults()
    {
        $levels = array();
        foreach (ToolRegistry::all() as $tool) {
            $levels[$tool['name']] = ToolRegistry::defaultLevel($tool);
        }
        return new self($levels);
    }

    /**
     * Build a Permissions instance from the database (Capsule required).
     * Any tool missing a row falls back to its registry default.
     */
    public static function fromDatabase()
    {
        $levels = array();
        foreach (ToolRegistry::all() as $tool) {
            $levels[$tool['name']] = ToolRegistry::defaultLevel($tool);
        }
        if (class_exists('\WHMCS\Database\Capsule')) {
            try {
                $rows = \WHMCS\Database\Capsule::table(self::TABLE)->get(array('tool_name', 'level'));
                foreach ($rows as $row) {
                    $name = is_array($row) ? $row['tool_name'] : $row->tool_name;
                    $lvl  = is_array($row) ? $row['level'] : $row->level;
                    if (isset($levels[$name])) {
                        $levels[$name] = $lvl;
                    }
                }
            } catch (\Throwable $e) {
                // Table not migrated yet: keep defaults.
            }
        }
        return new self($levels);
    }

    /**
     * @return string one of disabled|read|act
     */
    public function level($toolName)
    {
        return isset($this->levels[$toolName]) ? $this->levels[$toolName] : 'disabled';
    }

    /**
     * A tool is listed by tools/list when it is not disabled.
     */
    public function isVisible($toolName)
    {
        return $this->level($toolName) !== 'disabled';
    }

    /**
     * Decide whether a tools/call may proceed.
     *
     * @return array{allowed:bool,code:int,message:string}
     */
    public function authorize(array $tool)
    {
        $level = $this->level($tool['name']);

        if ($level === 'disabled') {
            return array(
                'allowed' => false,
                'code'    => JsonRpc::TOOL_DISABLED,
                'message' => sprintf('A ferramenta "%s" esta desabilitada.', $tool['name']),
            );
        }

        if ($tool['rw'] === ToolRegistry::WRITE && $level !== 'act') {
            return array(
                'allowed' => false,
                'code'    => JsonRpc::TOOL_FORBIDDEN,
                'message' => sprintf(
                    'A ferramenta de escrita "%s" requer o nivel "agir" (informar e agir). Nivel atual: "%s".',
                    $tool['name'],
                    $level
                ),
            );
        }

        return array('allowed' => true, 'code' => 0, 'message' => '');
    }
}
