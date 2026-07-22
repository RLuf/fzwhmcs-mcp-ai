# fzWHMCS-MCP-AI

Um **addon module do WHMCS que É um servidor MCP** (Model Context Protocol).
Expõe ações da API do WHMCS como *tools* MCP, consumidas pelo cliente externo
**FazAI**. As tools aparecem para o FazAI como `mcp__whmcs__<tool>`.

- WHMCS **8.13.1**, PHP **7.4+**
- JSON-RPC 2.0, protocolo MCP `2024-11-05` (compatível com `2025-06-18` / `2025-03-26`)
- **87 tools** cobrindo Clientes, Pedidos, Faturas, Produtos, Domínios,
  **Tickets/Suporte (cobertura integral)** e **Sistema/Admin (cobertura integral)**
- Três transportes reais: **Streamable HTTP**, **SSE legado** e **stdio**
- Autenticação por **Bearer token**
- **Modelo de permissão por tool** (tabela `mod_fzmcp_tools`): toda tool de
  **escrita nasce bloqueada** até o operador liberar o nível **“informar e agir”**

---

## 1. O que é

O módulo roda dentro do WHMCS e executa cada ação via
`localAPI($action, $params, $adminUser)`. A lógica de protocolo, catálogo de
tools, permissões e transportes vive em `lib/` e é compartilhada pelos três
transportes e pelo harness de autoteste — nada é duplicado.

```
fzmcp/
├── fzmcp.php                # addon WHMCS: _config/_activate/_deactivate/_upgrade/_output
├── hooks.php                # sincroniza o catálogo após deploy (nenhum hook de runtime)
├── whmcs.json               # manifesto Marketplace
├── README.md
├── lib/
│   ├── Autoload.php         # autoloader PSR-4 do namespace FzMcp
│   ├── JsonRpc.php          # envelopes e códigos de erro JSON-RPC 2.0
│   ├── SchemaValidator.php  # validação de argumentos (subset JSON Schema)
│   ├── ApiInterface.php     # contrato da camada de execução
│   ├── LocalApi.php         # adaptador de produção -> localAPI()
│   ├── MockApi.php          # adaptador offline (respostas simuladas)
│   ├── ToolRegistry.php     # catálogo das 87 tools (ação, categoria, schema, read/write)
│   ├── Permissions.php      # gate de permissão por tool (DB-backed)
│   ├── Auth.php             # Bearer token
│   ├── Config.php           # leitura/escrita em tbladdonmodules
│   ├── SessionQueue.php     # fila de mensagens (SSE legado)
│   ├── Server.php           # núcleo MCP (initialize/tools.list/tools.call/ping)
│   └── Bootstrap.php        # carrega o WHMCS e monta o Server
├── public/
│   └── mcp.php              # endpoint HTTP + SSE
└── bin/
    ├── mcp-stdio.php        # transporte stdio (CLI)
    └── selftest.php         # harness de autoteste offline
```

---

## 2. Endpoints dos 3 transportes

Instalação de exemplo: `https://<DOMINIO_WHMCS>/financeiro`.

| Transporte | Método | URL / comando |
|---|---|---|
| **Streamable HTTP** | `POST` | `https://<DOMINIO_WHMCS>/financeiro/modules/addons/fzmcp/public/mcp.php` |
| **SSE legado** | `GET` abre o stream; `POST ?session=<id>` entrega mensagens | mesma URL acima |
| **stdio** | CLI local | `/opt/cpanel/ea-php74/root/usr/bin/php modules/addons/fzmcp/bin/mcp-stdio.php` |

### Alias recomendado para `/mcp`

Sirva o endpoint em `https://<DOMINIO_WHMCS>/mcp`.

**Apache** (`.htaccess` na raiz do domínio, ou vhost):

```apache
RewriteEngine On
RewriteRule ^mcp/?$ /financeiro/modules/addons/fzmcp/public/mcp.php [L,QSA,P]
# Preserve o header Authorization (algumas configs de CGI/FastCGI o removem):
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

**Nginx**:

```nginx
location = /mcp {
    proxy_pass https://127.0.0.1/financeiro/modules/addons/fzmcp/public/mcp.php;
    proxy_set_header Authorization $http_authorization;
    proxy_buffering off;              # necessário para SSE
    proxy_set_header X-Accel-Buffering no;
}
```

---

## 3. Configuração do FazAI

```
MCP_SERVERS=whmcs
MCP_WHMCS_TRANSPORT=http            # ou sse
MCP_WHMCS_URL=https://<DOMINIO_WHMCS>/mcp
MCP_WHMCS_AUTH=Bearer:<token>
```

O `<token>` é gerado na ativação e visível no painel do addon
(*Addons → fzWHMCS-MCP-AI*). Use o botão **Regenerar token** para rotacioná-lo.

---

## 4. Modelo de permissão

Cada tool tem um **nível** na tabela `mod_fzmcp_tools`:

| Nível | Rótulo no painel | Efeito |
|---|---|---|
| `disabled` | desabilitado | Oculta a tool do `tools/list` e recusa o `tools/call`. |
| `read` | informar | Tool de **leitura** executa; tool de **escrita** fica **visível mas bloqueada**. |
| `act` | informar e agir | Tool de **escrita** passa a executar. |

Classificação (segue os verbos do WHMCS):

- **Leitura**: `Get*` / `List*` / `*Details` / `*Status`
- **Escrita**: `Add*` `Create*` `Update*` `Delete*` `Accept*` `Cancel*`
  `Suspend*` `Terminate*` `Open*` `Close*` `Module*` `Domain(register/renew/…)`
  `Send*` `Log*` `Set*`

**Padrão de segurança:** na ativação toda tool nasce em `read`. Logo, as **48
tools de escrita nascem bloqueadas** — nenhuma escrita executa até você mudar a
tool para **“informar e agir”** no painel. As 39 tools de leitura já ficam
utilizáveis.

O gate é aplicado igualmente nos três transportes e no botão *Testar
ferramenta* do painel.

---

## 5. Painel administrativo

*Addons → fzWHMCS-MCP-AI*:

- Bloco de configuração do FazAI + as 3 URLs de transporte
- **Token Bearer** (visualizar / regenerar)
- **Testar ferramenta**: dispara um `tools/call` real (passa pelo gate)
- Grade **em duas colunas**, tools agrupadas por categoria, cada uma com um
  `select` (*desabilitado / informar / informar e agir*)

---

## 6. Autoteste offline (`bin/selftest.php`)

Dirige o servidor em processo, **sem conexão MCP**, exatamente como o FazAI
faria: `initialize` → `tools/list` → `tools/call` em **todas** as tools →
leituras reais seguras → testes de argumentos malformados.

- O catálogo completo usa `MockApi` para provar schema, dispatch e permissões
  sem consultar dados de clientes.
- Tools de **escrita** são verificadas quanto ao **bloqueio de permissão**.
- Um conjunto pequeno de leituras sem identificador de cliente é validado no
  `localAPI` real, sem imprimir o corpo da resposta.
- Argumentos inválidos/faltantes são rejeitados pelo validador de schema.

Rode com **ea-php74** (a CLI padrão faz *segfault* com ionCube):

```bash
cd <CAMINHO_WHMCS>
/opt/cpanel/ea-php74/root/usr/bin/php modules/addons/fzmcp/bin/selftest.php
```

**Modos:** o harness usa **LIVE** quando consegue carregar `init.php` (localAPI
real); se o WHMCS não inicializar pela CLI, cai para **MOCK** (respostas
simuladas em formato localAPI) e rotula claramente o modo — protocolo, schema,
gate de permissão e dispatch continuam exercitados. Código de saída `0` = tudo
passou.

---

## 7. Colocando em produção (checklist do operador)

1. **Instalar**: copie a pasta `fzmcp/` para `modules/addons/fzmcp/` na
   instalação do WHMCS.
2. **Ativar**: *Configuração → Addon Modules → fzWHMCS-MCP-AI → Activate*
   (cria `mod_fzmcp_tools`, semeia as 87 tools, gera o token).
3. **Permissões de acesso** do addon: marque os grupos de admin que podem ver
   o painel.
4. **Usuário Admin da API**: em *Configurações* do módulo, informe um admin com
   acesso à API (usado pelo `localAPI`). Garanta que esse admin tenha permissão
   para as ações que você for liberar.
5. **Token**: copie o Bearer token do painel para o `MCP_WHMCS_AUTH` do FazAI.
6. **Liberar escritas**: para cada ação que o FazAI poderá *executar*, mude a
   tool para **“informar e agir”** e salve. (Ex.: `OpenTicket`, `AddTicketReply`,
   `UpdateTicket` para automação de suporte.)
7. **Alias `/mcp`**: adicione o rewrite da seção 2 e confirme que o header
   `Authorization` chega ao PHP.
8. **Validar**: rode o `selftest.php` e faça um `initialize`/`tools/list` pelo
   FazAI.

---

## 8. Segurança

- Token comparado com `hash_equals` (tempo constante); requests HTTP/SSE sem
  Bearer válido recebem **401**.
- O token e o usuário admin **nunca** aparecem nas respostas das tools.
- stdio é tratado como transporte **local e confiável** (sem Bearer).
- Argumentos são validados contra o schema **antes** de chamar o WHMCS; os
  parâmetros `action`/`responsetype` não podem ser sobrescritos pelo cliente.

---

## 9. Cobertura de tools

87 tools no total — **39 leitura / 48 escrita**:

| Categoria | Leitura | Escrita |
|---|---:|---:|
| Clientes | 6 | 4 |
| Pedidos | 2 | 6 |
| Faturas | 4 | 9 |
| Produtos | 1 | 8 |
| Domínios | 3 | 7 |
| **Tickets** | 8 | 9 |
| **Sistema/Admin** | 15 | 5 |

**Não incluído por decisão de segurança/escopo** (fácil de adicionar ao
`ToolRegistry` se desejado): `DecryptPassword`/`EncryptPassword` e
`GetClientPassword` (exposição de credenciais); ações de e-mail marketing e
afiliados; endpoints de KB/downloads. O `tools/list` sempre reflete o catálogo
atual filtrado pelas permissões.
