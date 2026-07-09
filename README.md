# fzWHMCS

Integrações profissionais para **WHMCS 8.x** — provisionamento por API REST autenticada, sem SSH em runtime.

Dois produtos convivem neste repositório:

| Produto | Estado | Descrição |
|---|---|---|
| [`imovelsite/`](#imovelsite) | **Em produção** | Provisionamento de sites WordPress de corretores, do carrinho ao e-mail de boas-vindas |
| [`fzmcp/`](#fzwhmcs-mcp-ai) | Em construção | Servidor MCP expondo a API do WHMCS como ferramentas para agentes de IA |

---

## ImovelSite

Vende um plano no WHMCS e entrega, em minutos e sem intervenção humana, um site WordPress completo para o corretor em `SEU_NOME.<ROOT_DOMAIN>`, com DNS, SSL e caixa de e-mail próprios.

### Arquitetura

```
WHMCS (servidor de billing)                    WordPress (servidor de sites)
├── whmcs-addon/            ──── HTTPS ────▶   wordpress-plugin/
│   banner do carrinho,     Basic Auth com     REST imovelsite/v1
│   validação do prefixo,   Application        ├── POST /sites        (provisiona, 202 + job)
│   e-mail de boas-vindas,  Password           ├── GET  /sites/{slug}/status
│   dashboard, cron         + capability       ├── POST /sites/{slug}/suspend|unsuspend
│                           dedicada           ├── POST /sites/{slug}/domain
└── whmcs-server-module/                       ├── DELETE /sites/{slug}?mode=archive
    CreateAccount, Suspend,                    └── GET  /sites/{slug}/sso
    Terminate, SSO, retry
```

**Nenhum SSH entre servidores.** O plugin executa localmente (`uapi`, `wp-cli`, API Cloudflare) o que antes era um `shell_exec` remoto — ganhando autenticação, validação, idempotência e log no caminho.

### Componentes

- **`imovelsite/whmcs-addon/`** — addon module. Injeta o bloco explicativo no carrinho (só nos produtos configurados, sem editar templates compartilhados), valida o prefixo escolhido pelo cliente, garante o e-mail de boas-vindas correto via `EmailPreSend` + merge fields da caixa de e-mail, enfileira registro manual de domínio, e oferece dashboard admin com retry.
- **`imovelsite/whmcs-server-module/`** — provisioning module. Fala com o plugin por REST: `CreateAccount` (com `idempotency_key` por serviço e polling do job), `Suspend`/`Unsuspend`/`Terminate`, `TestConnection`, SSO para o `wp-admin`. Serviços legados de outros módulos retornam no-op benigno em vez de erro.
- **`imovelsite/wordpress-plugin/`** — plugin WordPress. Rotas REST com `permission_callback` obrigatório checando capability própria; fila de jobs idempotente em tabela dedicada; provisionamento executado só em CLI (o `exec()` do PHP web fica desabilitado por segurança); cria subdomínio, banco, WordPress pt-BR, tema, painel do corretor, registro DNS na Cloudflare e caixa de e-mail.

### Segurança

- Autenticação por **Application Password** do WordPress (Basic sobre HTTPS), em usuário de serviço dedicado com capability `imovelsite_provision` — nunca uma conta humana.
- Segredos vivem em constantes do `wp-config.php` e no banco do WHMCS. **Nada de token, IP ou zona neste repositório.**
- Senhas mascaradas no Module Log do WHMCS.
- Provisionamento privilegiado isolado no CLI; a superfície web só enfileira.

### Instalação

1. **WordPress (servidor de sites):** copie `imovelsite/wordpress-plugin/` para `wp-content/plugins/imovelsite-provisioner/` e ative. Defina no `wp-config.php`:
   ```php
   define( 'IMOVELSITE_CF_TOKEN', '...' );      // token Cloudflare com escopo Zone.DNS
   define( 'IMOVELSITE_CF_ZONE', '...' );       // id da zona
   define( 'IMOVELSITE_SERVER_IP', '...' );     // IP de destino do registro A
   define( 'IMOVELSITE_TEMPLATE_DIR', '...' );  // tema + mu-plugin modelo
   ```
   Crie o usuário de serviço e a credencial:
   ```bash
   wp user create whmcs-provisioner provisioner@exemplo.com --role=imovelsite_service
   wp user application-password create whmcs-provisioner whmcs --porcelain
   ```
   Se o Apache descartar o header `Authorization`, adicione ao `.htaccess`:
   ```apache
   RewriteCond %{HTTP:Authorization} ^(.+)$
   RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
   ```
   E agende o processador da fila (CLI, a cada minuto):
   ```cron
   * * * * * wp eval "imovelsite_process_pending();" --path=/caminho/do/wp
   ```

2. **WHMCS:** copie `whmcs-addon/` e `whmcs-server-module/` para `modules/addons/imovelsite/` e `modules/servers/imovelsite/`. Ative o addon; cadastre o servidor (hostname = domínio da API, usuário = usuário de serviço, **access hash = Application Password**); aponte o produto para o módulo `imovelsite` com *autosetup = payment*.

   O arquivo `_includes-hooks-imovelsite_hooks_loader.php` vai para `includes/hooks/` apenas se a sua instalação não carregar `hooks.php` do addon automaticamente.

---

## fzWHMCS-MCP-AI

Servidor **MCP** (Model Context Protocol) expondo as ~161 ações da API do WHMCS como ferramentas utilizáveis por agentes de IA — **operacionais de verdade**: listar, conectar, executar, observar o resultado e reutilizar o contexto.

Painel administrativo em duas colunas, com permissão por ação:

- **(a) informar/registrar** — somente leitura
- **(b) informar e agir** — leitura e escrita
- **desabilitada**

Backend pela API externa do WHMCS (`identifier`/`secret` + API Roles + IP allowlist).

Em construção.

---

## Licença

Proprietário — Webstorage do Brasil. Uso interno.
