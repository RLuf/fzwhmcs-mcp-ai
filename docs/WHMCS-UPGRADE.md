# Upgrade do WHMCS com o fzWHMCS MCP AI

## Objetivo

Preservar acesso ao painel, token, permissões e compatibilidade de `localAPI()`
durante uma atualização do WHMCS.

## Antes da atualização

1. Confirmar a versão alvo do WHMCS, PHP e ionCube.
2. Fazer backup completo dos arquivos e banco.
3. Exportar de forma sanitizada:
   - versão registrada do addon;
   - grupo administrativo em `access`;
   - presença (não o valor) do token;
   - usuário `localAPI`;
   - contagem por nível de permissão.
4. Rodar `bin/selftest.php` e guardar o resumo.
5. Fazer teste do endpoint:
   - sem token: `401`;
   - com token: `initialize`, `ping` e `tools/list`;
   - uma leitura segura;
   - uma escrita confirmadamente bloqueada.
6. Repetir tudo em cópia isolada do WHMCS atualizado.

## Compatibilidade

- O servidor atual suporta PHP 7.4+, mas o WHMCS e módulos codificados podem
  impor uma versão específica.
- Use a CLI PHP que carrega o mesmo ambiente compatível do WHMCS.
- Valide cada ação `localAPI` anunciada pelo catálogo na versão alvo.
- `GetHealthStatus` permanece fora do catálogo enquanto a instalação alvo não
  suportar essa chamada com segurança.

## Atualizar o addon

1. Colocar o WHMCS em janela de manutenção.
2. Copiar `fzmcp/` sobre `modules/addons/fzmcp/`, preservando permissões.
3. Abrir **Configurações → Módulos Addon** para disparar o upgrade oficial.
4. Confirmar que a versão do código e `tbladdonmodules.version` coincidem.
5. Não desativar/reativar como método de upgrade: a desativação pode remover a
   tabela de permissões.

O callback `fzmcp_upgrade()` deve ser idempotente e preservar:

- Bearer token/hash;
- usuário administrativo;
- transporte e origens;
- níveis definidos pelo operador.

## Gate pós-upgrade

```text
addon ativo -> painel acessível -> token preservado
-> tools/list corresponde ao ToolRegistry
-> leituras seguras funcionam
-> escritas continuam bloqueadas
-> endpoint anônimo retorna 401
-> logs não contêm segredos
```

Rodar:

```bash
/CAMINHO/PHP-COMPATIVEL modules/addons/fzmcp/bin/selftest.php
```

## Rollback

Se painel, `localAPI`, token ou catálogo falharem:

1. interromper clientes MCP;
2. restaurar arquivos e banco do mesmo backup;
3. limpar apenas caches documentados do WHMCS;
4. repetir o gate da versão anterior;
5. registrar ação/tool incompatível antes de nova tentativa.

Nunca gerar token novo como “correção” de incompatibilidade. Isso quebra todos
os clientes e esconde a causa real.
