# Prompt reserva — reconstruir a integração ImovelSite do zero

Cole o bloco abaixo num agente de IA com acesso de shell aos dois servidores. Ele descreve o
sistema por inteiro: o agente consegue **reinstalar a partir do repositório** ou, se necessário,
**reescrever o código do zero** sem consultar mais ninguém.

Antes de colar, substitua os valores entre `<>` pelos do seu ambiente.

---

```
Você vai instalar (ou reconstruir) a integração ImovelSite entre um servidor WHMCS e um
servidor WordPress. Leia tudo antes de agir. Trabalhe em produção com cuidado: faça backup
antes de qualquer alteração de banco e verifique cada etapa com evidência real, não suposição.

## Regra inegociável
Provisionamento por SSH/shell_exec entre servidores é PROIBIDO. A comunicação WHMCS → WordPress
é HTTPS REST autenticada. Execução local de comandos DENTRO do servidor de destino (uapi,
wp-cli) é permitida e esperada.

## O que o sistema faz
Um cliente compra um plano no WHMCS. Após a confirmação do pagamento, o WHMCS chama uma API REST
no servidor WordPress, que cria um site WordPress completo para o cliente em
`SEU_NOME.<ROOT_DOMAIN>` (subdomínio, banco de dados, tema, painel do corretor, registro DNS na
Cloudflare e caixa de e-mail `SEU_NOME@<ROOT_DOMAIN>`). O WHMCS grava as credenciais no serviço
e envia um e-mail de boas-vindas com os dados do site e da caixa de e-mail.

## Ambiente
- WHMCS <VERSAO, ex. 8.13.1> em `<CAMINHO_WHMCS>`, URL `<URL_WHMCS>`, produto alvo pid=<PID>.
- WordPress principal em `<CAMINHO_WP>`, conta cPanel `<CONTA_CPANEL>`, domínio raiz `<ROOT_DOMAIN>`.
- DNS na Cloudflare: zona `<CF_ZONE>`, registros A apontando para `<SERVER_IP>` (proxied).
- Diretório de template (tema + mu-plugin do painel): `<TEMPLATE_DIR>`.

## Componentes a instalar
1. **Plugin WordPress `imovelsite-provisioner`** (em `wp-content/plugins/`)
   - Ativação: cria role `imovelsite_service` com capability `imovelsite_provision` e a tabela
     `{prefix}imovelsite_jobs` (id, idempotency_key UNIQUE, slug, action, status, payload,
     result, created_at, updated_at).
   - Rotas REST no namespace `imovelsite/v1`, todas com `permission_callback` verificando
     `current_user_can('imovelsite_provision')` — nunca `__return_true`:
     `GET /ping` · `POST /sites` · `GET /sites/{slug}/status` · `POST /sites/{slug}/suspend` ·
     `POST /sites/{slug}/unsuspend` · `DELETE /sites/{slug}?mode=archive` ·
     `POST /sites/{slug}/domain` · `GET /sites/{slug}/sso`
   - `POST /sites` recebe `{slug, email, display_name, idempotency_key, create_mailbox}`. Se a
     `idempotency_key` já existe, devolve o job existente (200). Senão enfileira e responde
     **202 `{job_id, status:"pending"}`**. Slug válido: `^[a-z0-9]{3,20}$`.
   - Provisionamento (portado de um shell script legado, agora em PHP, rodando **como a conta
     cPanel**, sem root): registro A na Cloudflare (proxied) → `uapi SubDomain addsubdomain` →
     `uapi Mysql create_database/create_user/set_privileges` (o nome do banco DEVE começar com
     `<CONTA_CPANEL>_`) → `wp core download/config create/core install --locale=pt_BR --skip-email`
     → copia o tema e ativa → instala plugins essenciais → copia o mu-plugin do painel →
     `option update is_site_corretor 1` → categorias → papel `corretor` → permalinks
     `/%postname%/` + `.htaccess` → apaga os posts 1,2,3 → `uapi Email add_pop` para a caixa.
   - O e-mail padrão de novo usuário do WordPress NÃO é enviado (quem envia é o WHMCS).
   - **`exec()` é desabilitado no PHP web** (por segurança). Portanto o job só roda em CLI:
     exponha `imovelsite_process_pending()` e agende no cron do sistema, a cada minuto:
     `* * * * * runuser -u <CONTA_CPANEL> -- <PHP> <WP_CLI> eval "imovelsite_process_pending();" --path=<CAMINHO_WP>`
     No contexto web, o handler do evento apenas retorna (não tente re-agendar em loop).
   - Segredos vêm de constantes do `wp-config.php`: `IMOVELSITE_CF_TOKEN`, `IMOVELSITE_CF_ZONE`,
     `IMOVELSITE_SERVER_IP`, `IMOVELSITE_TEMPLATE_DIR`. Nada de segredo no código.

2. **Módulo de provisionamento WHMCS `modules/servers/imovelsite/`**
   - `MetaData`: `RequiresServer => true`. `ConfigOptions`: api_base_path
     (`/wp-json/imovelsite/v1`), request_timeout (30), poll_max_wait (150), create_mailbox (yesno).
   - Cliente HTTP com Basic Auth: usuário = `serverusername`, senha = `serveraccesshash`
     (a Application Password do WordPress). Toda chamada passa por `logModuleCall()` com a senha
     mascarada.
   - `CreateAccount`: resolve o slug (campo customizado "Prefixo do Site" do cliente; se vazio,
     deriva do nome, com sufixo numérico se ocupado — checando `GET /status`), envia
     `POST /sites` com `idempotency_key = "whmcs-{serviceid}"`, faz polling do status até
     `poll_max_wait`; quando `done`, grava `tblhosting` (domain, username, `password=encrypt(...)`)
     e registra a resposta completa (com os dados da caixa de e-mail) em `mod_imovelsite_log`.
     Se estourar o tempo, deixa o job `pending` — o cron do addon conclui depois.
   - `SuspendAccount` / `UnsuspendAccount` / `TerminateAccount` (archive) / `TestConnection`
     (`GET /ping`) / `ServiceSingleSignOn` (redireciona ao `wp-admin`) /
     `AdminServicesTabFields` (status do job) / botão "Reprocessar Provisionamento".
   - Serviços legados de outro módulo devem retornar `success` (no-op) quando a API responde
     `not_found` — nunca erro.

3. **Addon WHMCS `modules/addons/imovelsite/`**
   - Settings: `product_ids` (lista de pids), `enable_cart_banner`, `welcome_email_template_id`,
     `notify_email_domains`.
   - `_activate`: cria `mod_imovelsite_log` (id, serviceid, slug, action, status, job_id,
     request, response, attempts, created_at, updated_at); faz backup do corpo do template de
     e-mail e o substitui pela versão rica; cria o campo customizado do produto.
   - `hooks.php`:
     * `ClientAreaPageCart` + `ClientAreaFooterOutput`: injetam, via JS, um bloco explicativo
       ACIMA do quadro de opções de domínio (achando `#selregister`/`#seltransfer`/`#selowndomain`
       e usando `insertAdjacentHTML('beforebegin')`), apenas nos produtos de `product_ids`.
       Não edite o template compartilhado do carrinho.
     * `ShoppingCartValidateCheckout`: valida o prefixo (`^[a-z0-9]{3,20}$`) e a disponibilidade.
     * `EmailPreSend`: aborta (`abortsend`) qualquer e-mail de boas-vindas de hospedagem que não
       seja o nosso, para serviços dos nossos produtos; e injeta os merge fields
       `mailbox_address`, `mailbox_password`, `mailbox_webmail_url`, `mailbox_imap_host`,
       `mailbox_smtp_host` lendo o JSON de resposta em `mod_imovelsite_log`.
     * `AfterShoppingCartCheckout`: se o pedido inclui registro de domínio, cria uma tarefa
       (`tbltodolist`) e avisa os administradores — o registro `.com.br` é manual.
     * `AfterCronJob`: reprocessa provisionamentos `pending` (via `localAPI('ModuleCreate')`,
       incrementando `attempts` ANTES de chamar) e, ao concluir, envia o e-mail de boas-vindas
       uma única vez (dedupe por linha `action='welcome_email'`).
   - `_output`: painel administrativo com a lista de jobs, botão de reprocessar, teste de
     conexão e restauração do backup do template.
   - O e-mail de boas-vindas mostra a seção da caixa de e-mail dentro de `{if $mailbox_address}`,
     com webmail e instruções de IMAP/SMTP para Outlook.

## Configuração do WHMCS (banco)
- Produto: `servertype='imovelsite'`, `servergroup=<id>`, `autosetup='payment'`,
  `welcomeemail=<id do template>`, campos `freedomain*` VAZIOS (para que o registro do domínio
  continue sendo cobrado), `showdomainoptions=1`.
- Campo customizado: `tblcustomfields` type='product', relid=<PID>,
  fieldname='Prefixo do Site|prefix', fieldtype='text', required='on', showorder='on'.
- Servidor: `tblservers` type='imovelsite', `hostname` = **o domínio onde a API responde**
  (não o hostname do servidor), `username` = usuário de serviço, `accesshash` = Application
  Password, secure='on', port=443. Crie também um `tblservergroups` + `tblservergroupsrel`.
- **Ativação do addon exige três coisas**, não só os arquivos:
  1. linhas em `tbladdonmodules` (module='imovelsite');
  2. `tblconfiguration.ActiveAddonModules` contendo `imovelsite`;
  3. `tblconfiguration.AddonModulesHooks` contendo `imovelsite` — **sem isso o `hooks.php`
     nunca é carregado**. (Alternativa defensiva: um loader em `includes/hooks/` que dá
     `require_once` no `hooks.php` do addon.)

## Armadilhas conhecidas (todas já custaram tempo)
| Sintoma | Causa | Correção |
|---|---|---|
| `GET /ping` autenticado devolve 401 | Apache descarta o header `Authorization` | No `.htaccess` do WordPress, logo após `RewriteEngine On`: `RewriteCond %{HTTP:Authorization} ^(.+)$` e `RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]` |
| `Access denied for user 'X'` ao criar o banco | prefixo de banco do cPanel | o nome do banco e do usuário DEVEM começar com `<CONTA_CPANEL>_` |
| Job preso em `pending` | `exec()` desabilitado no PHP web | o cron de sistema (CLI) é obrigatório |
| `Email Template not found` no `SendEmail` | template só existe como variante de idioma | deve haver linha mestre com `language=''` |
| `Invalid address: (cc)` ao enviar | coluna `copyto` com valor inválido (ex.: `1`) | limpar (`copyto=''`); a coluna de BCC chama-se `blind_copy_to` |
| Banner não aparece no carrinho | o fluxo usa a rota amigável `/store/...`, e o hook vê `filename='index'` | aceitar `cart` e `index` no gate |
| Hooks do addon não carregam | falta `AddonModulesHooks` | ver item 3 da ativação |
| `AddOrder` → `Client ID Not Found` | o parâmetro é `clientid`, não `userid` | corrigir a chamada |
| `404 rest_no_route` | plugin inativo ou permalinks | ativar o plugin; conferir `wp rewrite flush` |

## Verificação obrigatória (só declare pronto com evidência)
1. `curl -u usuario:app_password https://<API_HOST>/wp-json/imovelsite/v1/ping` → `{"ok":true,...}`;
   sem autenticação → **401**.
2. Crie um produto de teste OCULTO (clone do real) e faça um pedido completo:
   pedido → fatura → pagamento → provisionamento automático → serviço `Active`.
3. Se o pedido incluir registro de domínio, confira na fatura a **linha do domínio com o valor
   cheio** (o plano não dá domínio grátis).
4. O site do cliente responde 200 em `https://<slug>.<ROOT_DOMAIN>/`.
5. O e-mail de boas-vindas chega com: domínio, usuário, senha, link do `wp-admin`, endereço da
   caixa de e-mail, senha da caixa, URL do webmail e os dados de IMAP/SMTP.
6. Senhas aparecem mascaradas no Module Log do WHMCS.

## Rollback
Reverter o produto para o módulo anterior e `autosetup=''`; remover `imovelsite` de
`ActiveAddonModules` e `AddonModulesHooks`; restaurar o corpo do template a partir da linha
`action='email_backup'` em `mod_imovelsite_log`; desativar o plugin do WordPress (os sites já
criados continuam no ar).

## Se o repositório estiver disponível
`git clone <REPO> && cd imovelsite && ./install.sh wordpress` (no servidor WordPress), copie o
arquivo de credencial gerado para o servidor do WHMCS, e então `./install.sh whmcs`. Depois
`./install.sh check`. Leia `docs/INSTALL.md`, `docs/OPERACAO.md` e `docs/TROUBLESHOOTING.md`.
```
