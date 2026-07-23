# AGENTS.md — fzWHMCS MCP AI

Instruções para manter o addon MCP atualmente instalado no WHMCS.

## 1. Escopo

- Código ativo: `fzmcp/`.
- Código histórico: `imovelsite/`; não implantar no provisionamento atual.
- Produção do ImovelSite: `RLuf/imovelsite-whmcs-module`.
- Produto comercial mais amplo: `RLuf/fzwhmcs-ai`; não copiar seus arquivos
  para produção sem comparar versões, schemas e migrações.

## 2. Regras invioláveis

1. Ferramentas de escrita ficam bloqueadas por padrão.
2. Nunca habilitar `act` em massa ou por conveniência de teste.
3. Bearer bruto aparece uma única vez e nunca entra no Git/log.
4. O banco guarda somente hash do token.
5. O addon usa `localAPI()`; não criar campo de senha administrativa.
6. Endpoint HTTP exige HTTPS, token válido e origem controlada.
7. Respostas e logs não podem expor senha, token, chave, hash ou payload
   sensível do WHMCS.
8. `hooks.php` é carregado globalmente: manter mínimo e sem consulta pesada.
9. Não modificar produto 89, Iugu, Nota Fácil/Edvan ou provisionamento durante
   trabalho exclusivo do MCP.
10. Atualização do WHMCS segue `docs/WHMCS-UPGRADE.md`.

## 3. Versão e release

A versão precisa coincidir em:

- `fzmcp/fzmcp.php` (`fzmcp_config`);
- `fzmcp/whmcs.json`, enquanto o manifesto mantiver o campo;
- documentação e artefato publicado;
- registro `tbladdonmodules` após a atualização.

Mudança de schema ou configuração exige lógica idempotente em
`fzmcp_upgrade($vars)`. Upgrade nunca pode regenerar token, redefinir permissões
ou apagar escolhas do operador.

## 4. Fluxo obrigatório

1. Ler este arquivo, `README.md` e o documento relacionado.
2. Preservar mudanças existentes (`git status --short`).
3. Comparar fonte, instalação e versão registrada.
4. Fazer backup de banco/arquivos do WHMCS antes de escrita.
5. Alterar a fonte, testes e documentação juntos.
6. Rodar lint e `bin/selftest.php`.
7. Instalar em cópia isolada ou janela autorizada.
8. Executar upgrade do addon e confirmar token/permissões preservados.
9. Testar endpoint anônimo (`401`) e autenticado (`initialize`, `tools/list`,
   leitura segura).
10. Confirmar que escrita continua bloqueada.
11. Commitar e enviar após o gate.

## 5. Testes

```bash
find fzmcp -name '*.php' -print0 | xargs -0 -n1 php -l
php fzmcp/bin/selftest.php
git diff --check
```

No Bells, use a CLI PHP compatível com WHMCS/ionCube, não necessariamente o
`php` padrão.

## 6. Documentação obrigatória

Toda tool nova ou alterada deve documentar:

- ação WHMCS real;
- categoria, leitura/escrita e nível padrão;
- schema e parâmetros obrigatórios;
- retorno e erros;
- permissão necessária;
- teste com mock e, para leitura segura, teste live;
- impacto de upgrade e rollback.

Atualize contagens de ferramentas somente a partir de `ToolRegistry`, nunca por
memória. Não documente uma ação como funcional se ela não existe na versão alvo
do WHMCS.
