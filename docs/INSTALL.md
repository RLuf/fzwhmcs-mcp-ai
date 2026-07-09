# Instalação — ImovelSite (WHMCS + WordPress)

Instalação reprodutível, do zero, em um par novo de servidores. Em produção desde **2026-07-09**.

> **Convenção deste documento.** Tudo que aparece entre `<...>` ou marcado com **[ADAPTAR]** depende do seu ambiente (ids, domínios, caminhos, IPs). Onde um fato não pôde ser confirmado, está marcado **[confirmar]**.

## Topologia de referência (produção atual)

| Papel | Servidor | Detalhe |
|---|---|---|
| Billing (WHMCS 8.13.1) | **bells** | `<CAMINHO_WHMCS>`, URL pública `https://<DOMINIO_WHMCS>/financeiro/` |
| Sites WordPress | **red** (`<SERVIDOR_WP>`) | cPanel, conta `<CONTA_CPANEL>`, docroots em `/home/<CONTA_CPANEL>/<slug>` |
| Domínio raiz dos sites | — | `<ROOT_DOMAIN>` (é também o **hostname da API**, não o hostname do red) |
| Produto WHMCS | `pid=89` | "Plano de Hospedagem e Manutenção Linux Imovel Site", grupo `gid=2` |
| Template de e-mail | `id=276` | "Imovel Site - Boas Vindas", `type=product` |

**Sem SSH em runtime.** O módulo de servidor do WHMCS fala com o plugin WordPress só por REST (HTTP Basic sobre HTTPS, Application Password). Todo o provisionamento privilegiado (uapi/wp-cli/Cloudflare) roda **localmente no red**, em **CLI**.

---

## Pré-requisitos

**No red (servidor de sites):**
- cPanel com a conta `<CONTA_CPANEL>` (ou equivalente **[ADAPTAR]**) já criada, apontando para o domínio raiz `<ROOT_DOMAIN>`.
- WordPress instalado no docroot principal da conta (`/home/<CONTA_CPANEL>/public_html`), que hospeda **o plugin provisionador** (não é um site de corretor).
- `wp-cli` em `/usr/local/bin/wp` e PHP CLI em `/usr/local/bin/php` (caminhos usados pelo plugin — ver `class-provisioner.php`). **[ADAPTAR]** se diferirem.
- `exec()` **desabilitado** no PHP web da conta (é o padrão de segurança no red) e **habilitado** no PHP CLI. O plugin depende disso: no web ele só enfileira; quem provisiona é o cron de sistema.
- Zona Cloudflare do domínio `<ROOT_DOMAIN>`, com um token de escopo `Zone.DNS`.
- Diretório de template do site (`proimovel` + `painel-corretor.php`) disponível para copiar (ver Parte 1, passo 6).
- Acesso root ao servidor para criar o **cron de sistema** e ajustar o `.htaccess`.

**No bells (servidor de billing):**
- WHMCS 8.13.1, PHP 7.4 (ionCube). O `php` CLI padrão do box **segfalha** (ionCube); para lint use `/opt/cpanel/ea-php74/root/usr/bin/php -n`.
- Acesso ao banco do WHMCS (SQL direto) e ao admin.
- Grupo de servidores no WHMCS onde o servidor `imovelsite` será cadastrado (o módulo exige servidor: `RequiresServer=true`).

**Backup antes de qualquer alteração no bells:**
```bash
/root/whmcs_bkp_completo.sh   # mysqldump gz + tar do diretório do WHMCS em /home/backup
```

---

## Parte 1 — WordPress (servidor red)

### 1.1 Copiar o plugin

Copie `imovelsite/wordpress-plugin/` para:
```
/home/<CONTA_CPANEL>/public_html/wp-content/plugins/imovelsite-provisioner/
```
Ajuste dono/permissões para a conta cPanel:
```bash
chown -R <CONTA_CPANEL>:<CONTA_CPANEL> /home/<CONTA_CPANEL>/public_html/wp-content/plugins/imovelsite-provisioner
```

### 1.2 Constantes no `wp-config.php`

Adicione **antes** da linha `/* That's all, stop editing! */`. Nada de segredo vai para o repositório — tudo vive aqui.

```php
// ImovelSite — provisionador
define( 'IMOVELSITE_CF_TOKEN',   '<TOKEN_CLOUDFLARE_ZONE_DNS>' );   // [ADAPTAR] escopo Zone.DNS
define( 'IMOVELSITE_CF_ZONE',    '<ID_DA_ZONA_CLOUDFLARE>' );        // [ADAPTAR]
define( 'IMOVELSITE_SERVER_IP',  '<IP_DO_SERVIDOR_RED>' );           // [ADAPTAR] destino do registro A
define( 'IMOVELSITE_TEMPLATE_DIR', '/home/<CONTA_CPANEL>/imovelsite-template' ); // ver passo 1.6
```

Constantes com **default no código** (só sobrescreva se necessário — ver `imovelsite-provisioner.php`):
- `IMOVELSITE_ACCOUNT_HOME` → `/home/<CONTA_CPANEL>`
- `IMOVELSITE_ROOT_DOMAIN` → `<ROOT_DOMAIN>`

### 1.3 Ativar o plugin, criar usuário de serviço e Application Password

```bash
cd /home/<CONTA_CPANEL>/public_html
runuser -u <CONTA_CPANEL> -- /usr/local/bin/wp plugin activate imovelsite-provisioner
```
A ativação (`register_activation_hook`) cria a tabela `{prefix}imovelsite_jobs`, o **role `imovelsite_service`** com a capability **`imovelsite_provision`**, e adiciona essa capability ao `administrator`.

Crie o usuário de serviço dedicado (nunca uma conta humana) e a credencial:
```bash
runuser -u <CONTA_CPANEL> -- /usr/local/bin/wp user create whmcs-provisioner provisioner@<ROOT_DOMAIN> \
  --role=imovelsite_service --user_pass="$(/usr/local/bin/wp eval 'echo wp_generate_password(24,true);')"

runuser -u <CONTA_CPANEL> -- /usr/local/bin/wp user application-password create whmcs-provisioner whmcs --porcelain
```
> Guarde a Application Password retornada (formato `xxxx xxxx xxxx xxxx xxxx xxxx`). Ela é o **access hash** do servidor no WHMCS (Parte 2.4). O plugin já força `wp_is_application_passwords_available => true`.

### 1.4 `.htaccess` — preservar o header Authorization (OBRIGATÓRIO)

O Apache no red **descarta** o header `Authorization`, o que quebra o HTTP Basic da API (resulta em 401 em toda rota). No `.htaccess` do docroot do plugin (`/home/<CONTA_CPANEL>/public_html/.htaccess`), **logo após** `RewriteEngine On`, insira:

```apache
RewriteCond %{HTTP:Authorization} ^(.+)$
RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
```

### 1.5 Cron de sistema (OBRIGATÓRIO) — provisionamento em CLI

Como `exec()` é desabilitado no PHP web, o WP-Cron web **não** provisiona: o hook `imovelsite_run_job` retorna imediatamente fora do SAPI `cli`. O trabalho real é feito por `imovelsite_process_pending()` em CLI, chamado por um **cron de sistema** (crontab de root):

```cron
* * * * * runuser -u <CONTA_CPANEL> -- /usr/local/bin/php -d memory_limit=512M /usr/local/bin/wp eval "imovelsite_process_pending();" --path=/home/<CONTA_CPANEL>/public_html >/dev/null 2>&1
```
`imovelsite_process_pending()` pega até 5 jobs `pending` por execução e chama `run_job()` para cada um.

### 1.6 Diretório de template do site

O provisionador copia o tema `proimovel` e o mu-plugin `painel-corretor.php` de `IMOVELSITE_TEMPLATE_DIR`. O diretório precisa ser **legível pela conta cPanel**:

```bash
cp -a /root/imovelsite-template /home/<CONTA_CPANEL>/imovelsite-template
chown -R <CONTA_CPANEL>:<CONTA_CPANEL> /home/<CONTA_CPANEL>/imovelsite-template
```
Estrutura esperada (ver `class-provisioner.php`):
```
/home/<CONTA_CPANEL>/imovelsite-template/
├── proimovel/            # tema (copiado para wp-content/themes/ e ativado)
└── painel-corretor.php   # mu-plugin (copiado para wp-content/mu-plugins/)
```
Se `proimovel/` faltar, o site é criado com o tema padrão; se `painel-corretor.php` faltar, o painel do corretor não é instalado. Nenhum dos dois aborta o provisionamento.

### 1.7 Prefixo de banco cPanel

O uapi da conta **só aceita** bancos com o prefixo `<CONTA_CPANEL>_`. Os bancos dos sites nascem como `<CONTA_CPANEL>_<slug8>` (8 primeiros chars do slug — ver `class-provisioner.php`). Nada a configurar, apenas confirme que o prefixo da conta é esse (uapi rejeita qualquer outro).

---

## Parte 2 — WHMCS (servidor bells)

### 2.1 Copiar os arquivos dos módulos

```
imovelsite/whmcs-addon/          → modules/addons/imovelsite/
imovelsite/whmcs-server-module/  → modules/servers/imovelsite/
```
Caminho base no bells: `<CAMINHO_WHMCS>/modules/...` **[ADAPTAR]**.

Lint opcional (o `php` padrão segfalha por ionCube — use o PHP do EasyApache com `-n`):
```bash
/opt/cpanel/ea-php74/root/usr/bin/php -n -l modules/servers/imovelsite/imovelsite.php
```

### 2.2 Ativar o addon — os arquivos NÃO bastam

Ativar o addon exige **três coisas no banco**, além dos arquivos. Sem elas o addon não funciona (e, faltando a 3ª, `hooks.php` nunca carrega).

**(a) Linhas em `tbladdonmodules`** (`module='imovelsite'`). O jeito limpo é ativar pelo admin (**Configuração › Addon Modules › ImovelSite › Activate**), que grava `version`, `access`, e os campos de configuração. Se preferir SQL, os settings esperados são:

```sql
INSERT INTO tbladdonmodules (module, setting, value) VALUES
  ('imovelsite', 'version', '1.0.0'),
  ('imovelsite', 'access', ''),                              -- preenchido pela ativação (admin roles)
  ('imovelsite', 'product_ids', '89'),                       -- [ADAPTAR] pid do produto
  ('imovelsite', 'enable_cart_banner', 'on'),
  ('imovelsite', 'welcome_email_template_id', '276'),        -- [ADAPTAR] id do template
  ('imovelsite', 'notify_email_domains', 'admin@exemplo.com');  -- [ADAPTAR] avisos de domínio manual
```
A ativação (`imovelsite_activate()`) também cria a tabela `mod_imovelsite_log`, instala o template rico de e-mail (com backup) e cria o custom field — ver 2.5 e 2.6 para os detalhes/armadilhas.

**(b) `ActiveAddonModules`** precisa conter `imovelsite`:
```sql
UPDATE tblconfiguration
SET value = TRIM(BOTH ',' FROM CONCAT(value, ',imovelsite'))
WHERE setting = 'ActiveAddonModules' AND value NOT LIKE '%imovelsite%';
```

**(c) `AddonModulesHooks`** precisa conter `imovelsite` — **sem isto, `hooks.php` nunca é carregado** (banner, validação, e-mail e cron do addon ficam mortos):
```sql
UPDATE tblconfiguration
SET value = TRIM(BOTH ',' FROM CONCAT(value, ',imovelsite'))
WHERE setting = 'AddonModulesHooks' AND value NOT LIKE '%imovelsite%';
```
`AddonModulesPerms` (php-serializado) controla a **visibilidade do addon por role de admin**; normalmente é gravado pela ativação via admin. Se o addon não aparecer no menu, revise essa chave.

**Rede de segurança (loader de hooks).** Se, mesmo com (b) e (c), o `hooks.php` do addon não carregar (já observado nesta instalação), copie o loader para o caminho `includes/hooks/`, que o WHMCS sempre processa:
```
imovelsite/whmcs-addon/_includes-hooks-imovelsite_hooks_loader.php
   → includes/hooks/imovelsite_hooks_loader.php
```
Ele só age se o addon estiver ativo (`tbladdonmodules.version` existe) e vira no-op se `hooks.php` já tiver carregado (`function_exists('imovelsite_hk_settings')`).

### 2.3 Grupo de servidores

O módulo declara `RequiresServer=true`, então é preciso um **grupo de servidores**. Anote o `id` do grupo (`tblservergroups.id`) — vai no produto como `servergroup` (2.7).

### 2.4 Cadastrar o servidor

**Configuração › System Settings › Products/Services › Servers › Add New Server.** Valores exatos:

| Campo | Valor |
|---|---|
| Módulo (Type) | `imovelsite` |
| Hostname | `<ROOT_DOMAIN>` — **o domínio da API, NÃO** `<SERVIDOR_WP>` |
| Secure (SSL) | `on` |
| Port | `443` |
| Username | `whmcs-provisioner` (usuário de serviço WP, 1.3) |
| Access Hash | a **Application Password** gerada em 1.3 (com ou sem espaços) |

Associe o servidor ao grupo de 2.3. Options do módulo (`imovelsite_ConfigOptions`, defaults já bons):
- `api_base_path` = `/wp-json/imovelsite/v1`
- `request_timeout` = `30`
- `poll_max_wait` = `150` (depois disso o job segue pelo cron do red)
- `create_mailbox` = `on`

O `ApiClient` monta a base como `https://<hostname>/wp-json/imovelsite/v1` — por isso o hostname tem de ser o domínio que serve o WordPress do plugin.

### 2.5 Template de e-mail (id 276) — armadilhas conhecidas

A ativação do addon já sobrescreve o **corpo** do template `id=276` com o HTML rico (`EmailTemplateInstaller::install(276)`), guardando backup em `mod_imovelsite_log` (`action='email_backup'`, `slug='emailtpl-276'`). Mas há três condições que precisam estar certas na linha do template, senão o envio falha:

1. **Linha master com `language=''`.** O `localAPI('SendEmail')` só encontra o template se existir a linha-mestra com `language` vazio. Uma linha só com `language='portuguese-br'` resulta em **"Email Template not found"**.
2. **`copyto` vazio.** Um valor inválido (ex.: `'1'`) causa **"Invalid address: (cc)"**. Deixe vazio.
3. **`blind_copy_to` vazio** (a coluna é `blind_copy_to`, não `blindcopyto`).

Confira/corrija:
```sql
SELECT id, name, type, language, copyto, blind_copy_to
FROM tblemailtemplates WHERE id = 276;

UPDATE tblemailtemplates
SET copyto = '', blind_copy_to = ''
WHERE id = 276 AND (copyto <> '' OR blind_copy_to <> '');
```
Se não houver linha master (`language=''`), crie-a a partir da existente **[confirmar]** o layout de colunas antes de inserir; o esperado é `name='Imovel Site - Boas Vindas'`, `type='product'`.

**Merge fields extras** injetados pelo hook `EmailPreSend` do addon (lidos do JSON `mailbox` no `mod_imovelsite_log`): `{$mailbox_address}`, `{$mailbox_password}`, `{$mailbox_webmail_url}`, `{$mailbox_imap_host}`, `{$mailbox_smtp_host}`. O template envolve a seção do e-mail profissional em `{if $mailbox_address}`.

### 2.6 Custom field "Prefixo do Site"

A ativação tenta criar o custom field. **Atenção:** o código de ativação inclui uma coluna `showdetail`, que **não existe** nesta instalação do WHMCS — o layout real de `tblcustomfields` aqui é:

```
id, type, relid, fieldname, fieldtype, description, fieldoptions, regexpr,
adminonly, required, showorder, showinvoice, sortorder, created_at, updated_at
```
(sem `showdetail`). Se a ativação falhar por causa dessa coluna, crie o campo por SQL, **omitindo `showdetail`**:

```sql
INSERT INTO tblcustomfields
  (type, relid, fieldname, fieldtype, description, fieldoptions, regexpr,
   adminonly, required, showorder, showinvoice, sortorder, created_at, updated_at)
VALUES
  ('product', 89, 'Prefixo do Site|prefix', 'text',
   'Escolha o endereço do seu site: SEU_NOME.<ROOT_DOMAIN> (letras minúsculas e números, 3-20 caracteres)',
   '', '', '', 'on', 'on', '', 0, NOW(), NOW());
```
`relid=89` **[ADAPTAR]** = pid do produto. `required='on'` e `showorder='on'` são essenciais (o campo tem de aparecer e ser obrigatório na configuração do produto no carrinho). O sufixo `|prefix` no `fieldname` é o nome interno; o rótulo visível é "Prefixo do Site".

### 2.7 Configuração do produto para go-live

No produto `pid=89` (**Products/Services › editar**), aba **Module Settings** e **Details**:

| Campo | Valor go-live | Observação |
|---|---|---|
| `servertype` | `imovelsite` | módulo de servidor REST |
| `servergroup` | `<id do grupo do servidor>` | de 2.3 |
| `autosetup` | `payment` | provisiona ao confirmar pagamento |
| `welcomeemail` | `276` | template rico de boas-vindas |
| campos de freedomain | **VAZIOS** | assim o registro `.com.br` (R$ 60/ano) **É cobrado** |
| `showdomainoptions` | `1` | mostra as opções de domínio no carrinho |

SQL equivalente (confira nomes de coluna antes de aplicar):
```sql
UPDATE tblproducts
SET servertype = 'imovelsite',
    servergroup = <ID_GRUPO_SERVIDOR>,   -- [ADAPTAR]
    autosetup  = 'payment',
    welcomeemail = 276,
    showdomainoptions = 1,
    freedomain = ''                       -- garante cobrança do domínio
WHERE id = 89;                            -- [ADAPTAR]
```
> O produto era um clone de teste `pid=176` (oculto) apontando para `servertype='imovelsiteclone'`. O go-live migra `pid=89` de `imovelsiteclone`/SSH para `imovelsite`/REST.

---

## Parte 3 — Verificação

### 3.1 Ping autenticado na API (do bells, ou de qualquer host)

```bash
curl -u 'whmcs-provisioner:<APP_PASSWORD_SEM_ESPACOS_OU_COM>' \
  https://<ROOT_DOMAIN>/wp-json/imovelsite/v1/ping
```
Esperado: `{"ok":true,"service":"imovelsite-provisioner","version":"1.0.0","time":"..."}`.
- **401** → header Authorization sendo descartado (revise 1.4) ou capability ausente (revise 1.3).
- **404 `rest_no_route`** → plugin inativo ou permalinks off no red.

No WHMCS, o botão **Test Connection** do servidor exercita esse mesmo `/ping`.

### 3.2 Pedido de teste

Faça um pedido do produto no carrinho (área do cliente), preenchendo **Prefixo do Site** (ex.: `testecorretor`). O fluxo de compra usa a rota amigável `/store/...`, então tudo bem se a URL não for `cart.php`. Ao confirmar o pagamento:

- **Fatura:** deve conter o item do plano **e** o domínio `.com.br` a **R$ 60,00/ano** (se você selecionou registrar domínio próprio e os campos freedomain estão vazios).
- **Provisionamento:** o módulo dispara `POST /sites`, faz polling por até `poll_max_wait` (150 s). Concluindo na janela, grava domínio/usuário/senha no serviço; passando disso, o job termina pelo cron do red e o `AfterCronJob` do addon reconcilia e dispara o welcome.
- **Serviço no WHMCS:** domínio `testecorretor.<ROOT_DOMAIN>`, usuário = slug, senha gravada. Aba admin do serviço mostra "Status do Provisionamento" e a caixa de e-mail.
- **E-mail de boas-vindas:** enviado uma única vez (dedupe via `mod_imovelsite_log action='welcome_email'`), com a seção de e-mail profissional preenchida.
- **Site no ar:** `https://testecorretor.<ROOT_DOMAIN>` responde (SSL de borda Cloudflare imediato, pois o registro A é `proxied`); `/wp-admin` abre.

Consulte o log:
```sql
SELECT id, serviceid, slug, action, status, attempts, created_at, updated_at
FROM mod_imovelsite_log ORDER BY id DESC LIMIT 20;
```
No red:
```bash
runuser -u <CONTA_CPANEL> -- /usr/local/bin/wp db query \
  "SELECT id, slug, status, action, created_at FROM \$(wp config get table_prefix)imovelsite_jobs ORDER BY id DESC LIMIT 10" \
  --path=/home/<CONTA_CPANEL>/public_html
```

### 3.3 Testar sem carrinho (opcional, mais rápido)

O bootstrap do WHMCS **não funciona por CLI** neste box. Para exercitar `AddOrder`/`ModuleCreate`, use um **web runner protegido por token** dentro do docroot do WHMCS e **apague-o depois**. Note: o parâmetro do `AddOrder` (localAPI) é **`clientid`** (não `userid`).

---

## Parte 4 — Rollback

Para voltar ao estado anterior (produto no módulo SSH antigo, addon desligado) sem destruir dados:

1. **Produto de volta ao módulo antigo:**
   ```sql
   UPDATE tblproducts SET servertype = 'imovelsiteclone', autosetup = '' WHERE id = 89;  -- [ADAPTAR]
   ```
2. **Desativar o addon** (remover das duas chaves de configuração):
   ```sql
   UPDATE tblconfiguration
   SET value = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', value, ','), ',imovelsite,', ','))
   WHERE setting IN ('ActiveAddonModules', 'AddonModulesHooks');
   ```
   E, se instalado, remover `includes/hooks/imovelsite_hooks_loader.php`.
3. **Restaurar o corpo do template de e-mail** a partir do backup (linha `action='email_backup'`, `slug='emailtpl-276'`):
   - Pelo dashboard do addon: botão **"Restaurar backup do template de e-mail"**; ou
   - Manualmente:
     ```sql
     SELECT request AS subject, response AS message
     FROM mod_imovelsite_log
     WHERE action='email_backup' AND slug='emailtpl-276'
     ORDER BY id DESC LIMIT 1;
     -- aplique subject/message de volta em tblemailtemplates id=276
     ```
4. **Plugin WordPress:** pode ser desativado no red — **os sites existentes continuam servindo** (o plugin só provisiona/gerencia; não serve os sites). O cron de sistema pode ser removido junto.

A tabela `mod_imovelsite_log`, o custom field e o template **não** são apagados na desativação (`imovelsite_deactivate()` os mantém), preservando histórico e a possibilidade de restaurar.
