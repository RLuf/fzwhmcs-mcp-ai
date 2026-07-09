# Operação — ImovelSite (runbook)

Runbook do dia a dia da integração ImovelSite (WHMCS **bells** + WordPress **red**). Para instalação do zero, ver [INSTALL.md](INSTALL.md); para diagnóstico de erros, ver [TROUBLESHOOTING.md](TROUBLESHOOTING.md).

> `<slug>` = prefixo do site (ex.: `joaoimoveis`), domínio `<slug>.<ROOT_DOMAIN>`, docroot `/home/<CONTA_CPANEL>/<slug>`.
> Comandos no red rodam como a conta cPanel: `runuser -u <CONTA_CPANEL> -- ...`.

---

## 1. Onde ficam os logs

| Log | Onde | Para quê |
|---|---|---|
| **WHMCS Module Log** | Admin › Utilities › Logs › Module Log (`tblmodulelog`) | Toda chamada REST do módulo de servidor (CreateAccount, poll, Suspend, etc.), com request/response. Credenciais mascaradas (`ApiClient::sanitize` + replaceVars). |
| **`mod_imovelsite_log`** | Banco do WHMCS (bells) | Contrato compartilhado addon↔módulo: histórico de jobs por serviço, backups de e-mail, fila de domínios manuais, envios de welcome. |
| **`{prefix}imovelsite_jobs`** | Banco do site provisionador (red) | Fila real de provisionamento no WordPress. É onde o job nasce e é processado em CLI. |
| **WHMCS Activity Log** | Admin › Utilities › Logs › Activity Log (`logActivity`) | Mensagens do addon: welcome via cron, domínio pronto para mapeamento, erros capturados nos hooks. |
| **Dashboard do addon** | Configuração › Addons › ImovelSite | Visão consolidada: últimos 50 registros de `mod_imovelsite_log` com filtro por status, fila de domínios manuais, restauração do template. |

### `mod_imovelsite_log` — esquema e ações

Colunas: `id, serviceid, slug, action, status, job_id, request TEXT, response TEXT, attempts, created_at, updated_at`.

| `action` | Origem | Significado |
|---|---|---|
| `create` | módulo de servidor | disparo do provisionamento (POST /sites) e polling |
| `provision` | (equivalente a create no fluxo de retry/hook) | linha usada pelo `AfterCronJob`/EmailPreSend; guarda o JSON com `mailbox` |
| `welcome_email` | addon (hook cron) | envio do e-mail de boas-vindas (dedupe: 1 por serviço) |
| `domain_map` | addon (checkout) | domínio próprio do cliente aguardando registro manual |
| `email_backup` | addon (ativação) | backup do template de e-mail (`serviceid=0`, `slug='emailtpl-276'`) |

Estados (`status`): `pending`, `running`, `done`/`success`, `error`, `noop`, `ready`, `mapped`.

Consultas úteis:
```sql
-- Últimos jobs por serviço
SELECT id, serviceid, slug, action, status, attempts, updated_at
FROM mod_imovelsite_log ORDER BY id DESC LIMIT 30;

-- Provisionamentos travados
SELECT * FROM mod_imovelsite_log
WHERE action IN ('create','provision') AND status='pending' ORDER BY id;

-- Domínios manuais pendentes
SELECT id, serviceid, slug, status, created_at FROM mod_imovelsite_log
WHERE action='domain_map' AND status IN ('pending','ready') ORDER BY id DESC;
```

No red:
```bash
PREFIX=$(runuser -u <CONTA_CPANEL> -- /usr/local/bin/wp config get table_prefix --path=/home/<CONTA_CPANEL>/public_html)
runuser -u <CONTA_CPANEL> -- /usr/local/bin/wp db query \
  "SELECT id, slug, action, status, created_at, updated_at FROM ${PREFIX}imovelsite_jobs ORDER BY id DESC LIMIT 15" \
  --path=/home/<CONTA_CPANEL>/public_html
```

---

## 2. Reprocessar um provisionamento travado

Um job fica `pending` quando o provisionamento não concluiu dentro de `poll_max_wait` (150 s). Ele **não** fica perdido: há duas redes de reprocessamento automático e uma manual.

**Automático (não precisa fazer nada):**
- `AfterCronJob` (cron do WHMCS) retenta linhas `create`/`provision` em `pending` com `attempts < 10`, chamando `localAPI('ModuleCreate')`. Ao concluir, marca `success` e dispara o welcome uma vez. O `attempts` é incrementado **antes** da chamada (evita loop infinito em fatal).
- No red, o cron de sistema roda `imovelsite_process_pending()` a cada minuto, processando jobs `pending` da fila do WordPress.

**Manual — pelo WHMCS (recomendado):**
- **Dashboard do addon:** botão **"Reprocessar"** nas linhas `provision` com status `pending`/`error` → chama `ModuleCreate` para o serviço.
- **Aba do serviço:** botão **"Reprocessar Provisionamento"** (`imovelsite_RetryProvision` → reexecuta `CreateAccount`, idempotente via `idempotency_key='whmcs-<serviceid>'` e reuso do slug já registrado).

**Manual — no red (empurrar a fila na hora):**
```bash
runuser -u <CONTA_CPANEL> -- /usr/local/bin/php -d memory_limit=512M /usr/local/bin/wp \
  eval "imovelsite_process_pending();" --path=/home/<CONTA_CPANEL>/public_html
```

> Reprocessar é seguro: se o site já existe, o POST /sites devolve o job existente (idempotência por `idempotency_key`) e `site_exists()` evita recriação.

---

## 3. Suspender / reativar / arquivar um site

O ciclo de vida é acionado pelo WHMCS (mudança de estado do serviço) e propaga para o red por REST. Todos toleram site inexistente na API (404/`not_found` → **no-op benigno**, protege serviços legados do pid 89).

| Ação | Gatilho no WHMCS | Efeito no red |
|---|---|---|
| **Suspender** | Serviço › Suspend (ou automático por atraso) | `.htaccess` passa a responder **503**; login da caixa de e-mail travado (`suspend_login`). Backup do `.htaccess` em `.htaccess.imovelsite-bkp`. |
| **Reativar** | Serviço › Unsuspend | Restaura o `.htaccess` do backup; destrava a caixa (`unsuspend_login`). |
| **Arquivar** (terminate) | Serviço › Terminate | Suspende, renomeia o docroot para `<slug>-archived-AAAAMMDD-HHMMSS` e **remove o registro A** na Cloudflare. **Purge (apagar de vez) é manual.** |

Fazer manualmente pela API (ex.: fora do fluxo do WHMCS):
```bash
BASE=https://<ROOT_DOMAIN>/wp-json/imovelsite/v1
AUTH='whmcs-provisioner:<APP_PASSWORD>'
curl -u "$AUTH" -X POST   "$BASE/sites/<slug>/suspend"
curl -u "$AUTH" -X POST   "$BASE/sites/<slug>/unsuspend"
curl -u "$AUTH" -X DELETE "$BASE/sites/<slug>?mode=archive"
```
> `mode=archive` é o único suportado; qualquer outro `mode` retorna **501**. O purge definitivo (remover o diretório `-archived-*` e o banco) é operação manual no red, feita com cuidado após confirmar que não há retenção contratual.

---

## 4. Mapear domínio próprio do cliente

Quando o cliente compra/transfere um domínio próprio junto ao plano, o registro é **manual** (o hook `AfterShoppingCartCheckout` só enfileira e avisa — não registra o domínio no registrador).

Fluxo:
1. No checkout, o addon cria uma linha `domain_map status='pending'` em `mod_imovelsite_log`, um item na **To-Do List** do WHMCS (vencimento amanhã) e envia aviso aos e-mails em `notify_email_domains` + admin.
2. Quando o domínio fica **Active** no WHMCS (`tbldomains`), o `AfterCronJob` marca a linha como `ready` e loga no Activity Log.
3. **Registre/transfira o domínio** no registrador (ex.: `.com.br` no Registro.br) — passo humano.
4. **Aponte o domínio para o site** — chame a API de mapeamento:
   ```bash
   curl -u 'whmcs-provisioner:<APP_PASSWORD>' -X POST \
     -H 'Content-Type: application/json' \
     -d '{"domain":"nomedocliente.com.br"}' \
     https://<ROOT_DOMAIN>/wp-json/imovelsite/v1/sites/<slug>/domain
   ```
   Isso cria um **addon domain** no cPanel apontando para o mesmo docroot e ajusta `home`/`siteurl` do WordPress para o domínio próprio. A resposta traz `dns_instructions` (registro A para `IMOVELSITE_SERVER_IP`, ou CNAME para `<slug>.<ROOT_DOMAIN>`; SSL via AutoSSL/Cloudflare após propagação).
5. **Marque como mapeado** no dashboard do addon (**"Marcar mapeado"**) para tirar da fila (`status='mapped'`).

> O DNS do domínio próprio do cliente **não** é gerenciado automaticamente — só o subdomínio `<slug>.<ROOT_DOMAIN>` (registro A proxied na Cloudflare) é. Oriente o cliente/registrador conforme `dns_instructions`.

---

## 5. Reenviar o e-mail de boas-vindas

O welcome é enviado uma única vez por serviço (dedupe via `mod_imovelsite_log action='welcome_email' status='success'`). Para **reenviar**:

1. Garanta que o serviço tem uma linha `create`/`provision` com o JSON de `mailbox` no `response` (senão os campos de e-mail profissional virão vazios — o `EmailPreSend` os lê de lá).
2. Remova/anule o dedupe se quiser que o caminho do cron reenvie:
   ```sql
   UPDATE mod_imovelsite_log SET status='resent'
   WHERE serviceid=<sid> AND action='welcome_email' AND status='success';
   ```
3. Reenvie pelo WHMCS: **Serviço do cliente › Send Message › "Imovel Site - Boas Vindas"**, ou via API:
   ```
   localAPI('SendEmail', ['messagename' => 'Imovel Site - Boas Vindas', 'id' => <serviceid>])
   ```
   O hook `EmailPreSend` reinjeta os merge fields de mailbox automaticamente.

> Se der **"Email Template not found"** ou **"Invalid address: (cc)"**, ver TROUBLESHOOTING (linha master `language=''`, `copyto`/`blind_copy_to` vazios).

---

## 6. Backup

**WHMCS (bells)** — antes de qualquer mudança de configuração:
```bash
/root/whmcs_bkp_completo.sh   # mysqldump gz + tar do diretório do WHMCS em /home/backup
```

**Sites (red):** cada site é um WordPress independente na conta cPanel. Use o backup do cPanel/JetBackup da conta `<CONTA_CPANEL>` **[confirmar]** a política de retenção vigente. Os dados críticos de um site são o docroot `/home/<CONTA_CPANEL>/<slug>` e o banco `<CONTA_CPANEL>_<slug8>`.

**Antes de arquivar/purgar** um site, confirme que há backup do docroot e do banco correspondente.

---

## 7. O que monitorar

| Sinal | Como | Ação se disparar |
|---|---|---|
| Jobs `pending` acumulando | `SELECT COUNT(*) FROM mod_imovelsite_log WHERE action IN ('create','provision') AND status='pending' AND attempts >= 3` | Cron do red parado, API 401/500, ou Cloudflare/uapi falhando. Ver TROUBLESHOOTING. |
| Cron de sistema no red | crontab de root ativo; jobs saindo de `pending` na fila `{prefix}imovelsite_jobs` | Se a fila não anda, o cron de 1 min pode ter sido removido ou o wp-cli/PHP path mudou. |
| `/ping` autenticado | `curl -u ...` retorna `ok:true` | 401 → Authorization/`.htaccess`; 404 → plugin/permalinks. |
| Envios de welcome falhando | `SELECT * FROM mod_imovelsite_log WHERE action='welcome_email' AND status='error'` | Template/`copyto` — ver TROUBLESHOOTING. |
| Fila de domínios manuais | Dashboard do addon / To-Do List | Registrar e mapear domínios `pending`/`ready`. |
| Erros nos hooks do addon | Activity Log com prefixo "ImovelSite" | Investigar mensagem específica. |
| Certificado/SSL dos sites | site abre em `https://` sem aviso | Registro A proxied garante SSL de borda; domínios próprios dependem de AutoSSL/propagação. |
