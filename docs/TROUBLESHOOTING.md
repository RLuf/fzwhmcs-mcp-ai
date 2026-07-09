# Troubleshooting — ImovelSite

Armadilhas reais observadas no deploy de produção (2026-07-09), com causa verificável e correção. Ver também [INSTALL.md](INSTALL.md) e [OPERACAO.md](OPERACAO.md).

## Tabela de sintomas

| # | Sintoma | Onde aparece | Causa | Correção |
|---|---|---|---|---|
| 1 | **401** no ping / Test Connection falha | `curl .../v1/ping`; Module Log | Apache no red **descarta o header `Authorization`**, então o WordPress não vê o HTTP Basic → capability não atende | Adicionar ao `.htaccess` do docroot do plugin, **logo após `RewriteEngine On`**, a regra de repasse do Authorization (ver 1.4 do INSTALL) e testar de novo |
| 2 | **401** mesmo com Authorization ok | `curl`/Module Log | Usuário sem a capability `imovelsite_provision`, ou access hash errada (não é a Application Password) | Conferir usuário `whmcs-provisioner` no role `imovelsite_service`; regenerar Application Password e colar em Access Hash do servidor |
| 3 | **"Email Template not found"** ao enviar welcome | `SendEmail` (Module/Activity Log) | `localAPI SendEmail` só acha o template se existir linha **master** com `language=''`; só `language='portuguese-br'` não basta | Garantir linha `tblemailtemplates` id=276 com `language=''`. Ver 2.5 do INSTALL |
| 4 | **"Invalid address: (cc)"** no envio | envio do welcome | Coluna `copyto` com valor inválido (`'1'`) | `UPDATE tblemailtemplates SET copyto='' WHERE id=276;` (a coluna de BCC é `blind_copy_to`, deixe vazia também) |
| 5 | **Job preso em `pending`** | `mod_imovelsite_log`; aba do serviço | Provisionamento não concluiu na janela `poll_max_wait` (150 s) — normal para sites pesados; ou o cron do red não roda | Aguardar o `AfterCronJob`/cron do red reconciliar; ou **Reprocessar** (dashboard/aba do serviço); ou empurrar `imovelsite_process_pending()` no red. Se `attempts>=10`, investigar erro real na fila do red |
| 6 | **"does not begin with the required prefix"** | fila do red (`result`/status `error`); Module Log | uapi do cPanel exige que o **nome do banco** comece com `<CONTA_CPANEL>_`; qualquer outro prefixo é rejeitado | O plugin já usa `<CONTA_CPANEL>_<slug8>`. Confirmar que a conta cPanel é `<CONTA_CPANEL>` e que `IMOVELSITE_ACCOUNT_HOME`/prefixo batem. **[ADAPTAR]** o prefixo se a conta tiver outro nome |
| 7 | **Banner não aparece** no carrinho | carrinho (`/store/...` ou `cart.php`) | (a) `enable_cart_banner` ≠ `on`; (b) produto do carrinho não está em `product_ids`; (c) hooks do addon não carregaram; (d) o JS não achou o container das opções de domínio | Conferir settings em `tbladdonmodules`; garantir `product_ids` com o pid; validar que `hooks.php` carrega (#8); o gate aceita `filename` `cart` **e** `index` (rota amigável), então não é isso |
| 8 | **Hooks não carregam** (banner, validação, welcome-cron mortos) | comportamento geral | `AddonModulesHooks` **não contém `imovelsite`** → o WHMCS nunca inclui `hooks.php` | Adicionar `imovelsite` a `AddonModulesHooks` (2.2c do INSTALL). Se ainda assim não carregar, instalar o **loader** em `includes/hooks/imovelsite_hooks_loader.php` |
| 9 | **`php -l` segfault** ao lintar no bells | terminal | O `php` CLI padrão do bells segfalha por ionCube | Usar `/opt/cpanel/ea-php74/root/usr/bin/php -n -l <arquivo>` |
| 10 | **"Client ID Not Found"** ao criar pedido de teste | `AddOrder` (localAPI) | Parâmetro errado: usou `userid` em vez de `clientid` | Chamar `AddOrder` com **`clientid`** |
| 11 | **404 `rest_no_route`** em qualquer rota | `curl`/Module Log | Plugin inativo no red, permalinks desligados, ou base path errado | Ativar `imovelsite-provisioner`; permalinks "Post name" (o plugin grava `.htaccess` nos sites, mas o docroot do plugin precisa de permalinks bonitos); conferir `api_base_path=/wp-json/imovelsite/v1` e hostname = `<ROOT_DOMAIN>` |
| 12 | **Addon não aparece** no menu admin | Configuração › Addons | `ActiveAddonModules` sem `imovelsite`, ou `AddonModulesPerms` (serializado) sem permissão para o role do admin | Adicionar `imovelsite` a `ActiveAddonModules` (2.2b); reativar pelo admin para regravar `AddonModulesPerms` |
| 13 | **Ativação do addon falha** citando coluna | ativação (`imovelsite_activate`) | O código insere `tblcustomfields.showdetail`, coluna **inexistente** nesta instalação | Criar o custom field por SQL **omitindo `showdetail`** (2.6 do INSTALL) |
| 14 | **Domínio `.com.br` não é cobrado** na fatura | carrinho/fatura | Campos `freedomain*` do produto preenchidos → registro sai grátis | Esvaziar os campos freedomain do produto (`freedomain=''`); com `showdomainoptions=1` o registro R$ 60/ano é cobrado |
| 15 | **E-mail chega sem a seção de e-mail profissional** | e-mail do cliente | Sem JSON de `mailbox` na linha `create`/`provision`, o `EmailPreSend` não injeta os merge fields; o template esconde a seção (`{if $mailbox_address}`) | Confirmar que o provisionamento gravou `response` com `mailbox` (caixa criada; `create_mailbox=on`). Reprocessar se necessário e reenviar o welcome |
| 16 | **Serviços legados do pid 89 dando erro** em suspend/terminate | Module Log | Serviços antigos não existem na API REST do red | É tratado como **no-op benigno** (404/`not_found` → `success` com registro `noop`). Se virar erro, conferir se a resposta é mesmo 404 e não 500 |
| 17 | **Bootstrap do WHMCS não roda em CLI** para testes | scripts de teste | ionCube/ambiente do bells não inicializa o WHMCS por linha de comando | Usar um **web runner protegido por token** dentro do docroot e **apagá-lo** após o teste |

## Detalhes e verificação por item

### #1 / #11 — 401 vs 404 no ping
São causas diferentes. **401** = a requisição chegou ao WordPress mas a autenticação não passou (quase sempre o header Authorization descartado, #1). **404 `rest_no_route`** = a rota REST não existe do ponto de vista do WordPress (plugin inativo, permalinks, base path). Teste isolando:
```bash
curl -i -u 'whmcs-provisioner:<APP_PASSWORD>' https://<ROOT_DOMAIN>/wp-json/imovelsite/v1/ping
```
- Corpo `{"code":"rest_forbidden",...,"status":401}` → autenticação (#1/#2).
- Corpo `{"code":"rest_no_route",...}` → rota/plugin (#11).

### #3/#4 — template de e-mail
Diagnóstico rápido:
```sql
SELECT id, name, type, language, copyto, blind_copy_to
FROM tblemailtemplates WHERE id = 276;
```
Precisa existir a linha com `language=''` (#3) e `copyto`/`blind_copy_to` vazios (#4). O corpo (HTML rico) é gravado pela ativação do addon; se preciso, restaurar o backup pelo dashboard (`action='email_backup'`).

### #5 — anatomia de um job travado
Ordem de reconciliação (nenhuma ação humana necessária no caso comum):
1. Módulo faz polling até 150 s; passando disso grava `pending` e retorna mensagem "em andamento".
2. Cron do red (`imovelsite_process_pending`, a cada 1 min) conclui o job na fila do WordPress.
3. `AfterCronJob` do WHMCS (a cada rodada do cron do WHMCS) chama `ModuleCreate`, marca `success` e envia o welcome uma vez.

Só intervenha (Reprocessar) se após alguns minutos o status seguir `pending` **e** o site não existir no red. Verifique o erro real na fila do red:
```bash
PREFIX=$(runuser -u <CONTA_CPANEL> -- /usr/local/bin/wp config get table_prefix --path=/home/<CONTA_CPANEL>/public_html)
runuser -u <CONTA_CPANEL> -- /usr/local/bin/wp db query \
  "SELECT id, slug, status, result FROM ${PREFIX}imovelsite_jobs WHERE status='error' ORDER BY id DESC LIMIT 5" \
  --path=/home/<CONTA_CPANEL>/public_html
```

### #6 — prefixo de banco
Falha típica de `Mysql create_database` via uapi quando o nome não começa com `<CONTA_CPANEL>_`. O plugin monta `<CONTA_CPANEL>_<slug8>` (`substr($slug,0,8)`), o que dá margem para colisão se dois slugs compartilharem os 8 primeiros chars **[confirmar]** comportamento em colisão; na prática os slugs são curtos e distintos.

### #8 — três chaves, não só arquivos
Ativar addon no WHMCS exige, além dos arquivos: linhas em `tbladdonmodules`, `imovelsite` em `ActiveAddonModules` **e** em `AddonModulesHooks`. A terceira é a mais esquecida e é a que carrega `hooks.php`. Verificação:
```sql
SELECT setting, value FROM tblconfiguration
WHERE setting IN ('ActiveAddonModules','AddonModulesHooks','AddonModulesPerms');
```
Ambas as listas devem conter `imovelsite`. O loader em `includes/hooks/` é a rede de segurança quando o carregador nativo falha (já observado aqui).

### #10 — parâmetros do AddOrder
No `localAPI('AddOrder', ...)` o cliente é **`clientid`**. Usar `userid` resulta em "Client ID Not Found". (No hook `AfterShoppingCartCheckout` do addon, o campo lido de `tblorders` é `userid` — não confundir contextos.)

### #13 — coluna `showdetail`
O `imovelsite_activate()` insere `tblcustomfields` com uma chave `showdetail` que **não existe** nesta instalação (layout real listado em 2.6 do INSTALL). Se a ativação abortar, crie o campo por SQL sem essa coluna. Confirme o layout antes:
```sql
SHOW COLUMNS FROM tblcustomfields;
```
