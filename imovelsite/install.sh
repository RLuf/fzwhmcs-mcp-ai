#!/usr/bin/env bash
#
# install.sh — instalador idempotente da integração ImovelSite (WHMCS ⇄ WordPress).
#
# Roda em DUAS etapas, uma em cada servidor:
#
#   No servidor WordPress:   ./install.sh wordpress
#   No servidor WHMCS:       ./install.sh whmcs
#   Só verificação:          ./install.sh check
#
# Configure o bloco abaixo (ou exporte as variáveis antes de chamar).
# Tudo é idempotente: rodar de novo não duplica nada.
#
set -uo pipefail

# ---------------------------------------------------------------------------
# Configuração — AJUSTE AQUI
# ---------------------------------------------------------------------------
: "${WP_PATH:=/home/<CONTA_CPANEL>/public_html}"        # docroot do WordPress principal
: "${WP_ACCOUNT:=<CONTA_CPANEL>}"                        # usuário cPanel dono do site
: "${WP_SERVICE_USER:=whmcs-provisioner}"               # usuário de serviço do WP
: "${WP_SERVICE_EMAIL:=provisioner@<ROOT_DOMAIN>}"
: "${ROOT_DOMAIN:=<ROOT_DOMAIN>}"                   # domínio raiz dos subdomínios
: "${TEMPLATE_SRC:=/root/imovelsite-template}"          # tema proimovel + painel-corretor.php
: "${TEMPLATE_DST:=/home/<CONTA_CPANEL>/imovelsite-template}"
: "${CF_ZONE:=}"                                        # id da zona Cloudflare
: "${CF_TOKEN:=}"                                       # token Cloudflare (escopo Zone.DNS)
: "${SERVER_IP:=}"                                      # IP de destino do registro A

: "${WHMCS_PATH:=<CAMINHO_WHMCS>}"   # raiz do WHMCS
: "${WHMCS_OWNER:=<USUARIO_WHMCS>}"                       # usuário dono dos arquivos do WHMCS
: "${PRODUCT_ID:=89}"                                   # produto a migrar
: "${WELCOME_TPL_ID:=276}"                              # tblemailtemplates.id do boas-vindas
: "${NOTIFY_EMAIL:=}"                                   # avisos de registro manual de domínio
: "${API_HOST:=<ROOT_DOMAIN>}"                      # hostname onde a API REST responde
: "${CRED_FILE:=/root/imovelsite-red-api.cred}"         # credencial da API (gerada na etapa wordpress)

WP_CLI=${WP_CLI:-/usr/local/bin/wp}
PHP=${PHP:-/usr/local/bin/php}

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ---------------------------------------------------------------------------
# Utilidades
# ---------------------------------------------------------------------------
c_ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
c_skip() { printf '  \033[36m·\033[0m %s\n' "$*"; }
c_warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
c_die()  { printf '  \033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
step()   { printf '\n\033[1m%s\033[0m\n' "$*"; }

need_root() { [ "$(id -u)" -eq 0 ] || c_die "rode como root"; }

wpx() { runuser -u "$WP_ACCOUNT" -- "$PHP" -d memory_limit=512M "$WP_CLI" "$@" --path="$WP_PATH"; }

whmcs_db() {
    local conf="$WHMCS_PATH/configuration.php"
    [ -f "$conf" ] || c_die "configuration.php não encontrado em $WHMCS_PATH"
    DB_USER=$(sed -n "s/^\$db_username *= *['\"]\(.*\)['\"].*/\1/p" "$conf")
    DB_PASS=$(sed -n "s/^\$db_password *= *['\"]\(.*\)['\"].*/\1/p" "$conf")
    DB_NAME=$(sed -n "s/^\$db_name *= *['\"]\(.*\)['\"].*/\1/p" "$conf")
    [ -n "$DB_NAME" ] || c_die "não consegui ler as credenciais do banco"
}
sqlq() { mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e "$1"; }

# ---------------------------------------------------------------------------
# Etapa 1 — servidor WordPress
# ---------------------------------------------------------------------------
install_wordpress() {
    need_root
    step "1/6  Pré-requisitos"
    [ -f "$WP_PATH/wp-config.php" ] || c_die "wp-config.php não encontrado em $WP_PATH"
    [ -x "$WP_CLI" ] || c_die "wp-cli não encontrado em $WP_CLI"
    [ -n "$CF_TOKEN" ] && [ -n "$CF_ZONE" ] && [ -n "$SERVER_IP" ] || \
        c_die "defina CF_TOKEN, CF_ZONE e SERVER_IP (DNS automático depende deles)"
    c_ok "WordPress em $WP_PATH; wp-cli ok"

    step "2/6  Diretório de template acessível pela conta"
    if [ -d "$TEMPLATE_DST" ]; then
        c_skip "$TEMPLATE_DST já existe"
    else
        [ -d "$TEMPLATE_SRC" ] || c_die "template de origem não encontrado: $TEMPLATE_SRC"
        cp -a "$TEMPLATE_SRC" "$TEMPLATE_DST"
        c_ok "copiado de $TEMPLATE_SRC"
    fi
    chown -R "$WP_ACCOUNT:$WP_ACCOUNT" "$TEMPLATE_DST"

    step "3/6  Plugin imovelsite-provisioner"
    local dst="$WP_PATH/wp-content/plugins/imovelsite-provisioner"
    rm -rf "$dst"
    cp -a "$HERE/wordpress-plugin" "$dst"
    chown -R "$WP_ACCOUNT:$WP_ACCOUNT" "$dst"
    for f in "$dst"/*.php "$dst"/includes/*.php; do
        "$PHP" -l "$f" >/dev/null || c_die "erro de sintaxe em $f"
    done
    c_ok "instalado e verificado"

    step "4/6  Constantes no wp-config.php"
    if grep -q IMOVELSITE_CF_TOKEN "$WP_PATH/wp-config.php"; then
        c_skip "constantes já presentes"
    else
        cp -a "$WP_PATH/wp-config.php" "$WP_PATH/wp-config.php.bkp-imovelsite-$(date +%F)"
        awk -v tok="$CF_TOKEN" -v zone="$CF_ZONE" -v ip="$SERVER_IP" -v tpl="$TEMPLATE_DST" '
            NR==1 {
                print
                printf "define( '\''IMOVELSITE_CF_TOKEN'\'', '\''%s'\'' );\n", tok
                printf "define( '\''IMOVELSITE_CF_ZONE'\'', '\''%s'\'' );\n", zone
                printf "define( '\''IMOVELSITE_SERVER_IP'\'', '\''%s'\'' );\n", ip
                printf "define( '\''IMOVELSITE_TEMPLATE_DIR'\'', '\''%s'\'' );\n", tpl
                next
            } 1' "$WP_PATH/wp-config.php" > "$WP_PATH/wp-config.php.new"
        "$PHP" -l "$WP_PATH/wp-config.php.new" >/dev/null || c_die "wp-config.php gerado com erro"
        mv "$WP_PATH/wp-config.php.new" "$WP_PATH/wp-config.php"
        chown "$WP_ACCOUNT:$WP_ACCOUNT" "$WP_PATH/wp-config.php"
        c_ok "4 constantes adicionadas (backup salvo)"
    fi

    step "5/6  .htaccess: repassar o header Authorization"
    # Sem isso o Apache descarta o Basic Auth e toda chamada da API volta 401.
    local ht="$WP_PATH/.htaccess"
    if grep -q HTTP_AUTHORIZATION "$ht" 2>/dev/null; then
        c_skip "regra já presente"
    else
        cp -a "$ht" "$ht.bkp-imovelsite-$(date +%F)" 2>/dev/null || true
        sed -i '0,/RewriteEngine On/s//RewriteEngine On\nRewriteCond %{HTTP:Authorization} ^(.+)$\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%1]/' "$ht"
        grep -q HTTP_AUTHORIZATION "$ht" && c_ok "regra adicionada" || c_warn "adicione manualmente a regra HTTP_AUTHORIZATION"
    fi

    step "6/6  Ativação, usuário de serviço e cron"
    wpx plugin activate imovelsite-provisioner >/dev/null 2>&1 && c_ok "plugin ativado" || c_skip "plugin já ativo"

    if wpx user get "$WP_SERVICE_USER" --field=ID >/dev/null 2>&1; then
        c_skip "usuário $WP_SERVICE_USER já existe"
    else
        wpx user create "$WP_SERVICE_USER" "$WP_SERVICE_EMAIL" --role=imovelsite_service --porcelain >/dev/null \
            && c_ok "usuário $WP_SERVICE_USER criado"
    fi
    wpx user set-role "$WP_SERVICE_USER" imovelsite_service >/dev/null 2>&1 || true

    # Provisionamento só roda em CLI (exec() fica desabilitado no PHP web, por segurança).
    local cronline="* * * * * runuser -u $WP_ACCOUNT -- $PHP -d memory_limit=512M $WP_CLI eval \"imovelsite_process_pending();\" --path=$WP_PATH >/dev/null 2>&1"
    if crontab -l 2>/dev/null | grep -qF "imovelsite_process_pending"; then
        c_skip "cron já instalado"
    else
        ( crontab -l 2>/dev/null; echo "$cronline" ) | crontab -
        c_ok "cron instalado (processa a fila a cada minuto)"
    fi

    step "Credencial da API"
    local pass
    pass=$(wpx user application-password create "$WP_SERVICE_USER" whmcs --porcelain 2>/dev/null | tail -1)
    [ -n "$pass" ] || c_die "falha ao gerar Application Password"
    umask 077
    printf 'BASE_URL=https://%s/wp-json/imovelsite/v1\nUSER=%s\nAPP_PASSWORD=%s\n' \
        "$API_HOST" "$WP_SERVICE_USER" "$pass" > "$CRED_FILE"
    chmod 600 "$CRED_FILE"
    c_ok "gravada em $CRED_FILE (chmod 600)"
    c_warn "copie este arquivo para o servidor WHMCS antes de rodar './install.sh whmcs'"

    step "Verificação"
    local code
    code=$(curl -s -o /dev/null -m 15 -w '%{http_code}' -u "$WP_SERVICE_USER:$pass" \
        "https://$API_HOST/wp-json/imovelsite/v1/ping")
    [ "$code" = "200" ] && c_ok "GET /ping autenticado → 200" || c_die "GET /ping retornou $code (esperado 200)"
    code=$(curl -s -o /dev/null -m 15 -w '%{http_code}' "https://$API_HOST/wp-json/imovelsite/v1/ping")
    [ "$code" = "401" ] && c_ok "sem autenticação → 401 (correto)" || c_warn "sem auth retornou $code (esperado 401)"

    printf '\n\033[1;32mWordPress pronto.\033[0m Próximo passo: ./install.sh whmcs no servidor do WHMCS.\n\n'
}

# ---------------------------------------------------------------------------
# Etapa 2 — servidor WHMCS
# ---------------------------------------------------------------------------
install_whmcs() {
    need_root
    step "1/6  Pré-requisitos"
    [ -f "$WHMCS_PATH/init.php" ] || c_die "WHMCS não encontrado em $WHMCS_PATH"
    [ -f "$CRED_FILE" ] || c_die "credencial não encontrada em $CRED_FILE (rode a etapa wordpress e copie o arquivo)"
    whmcs_db
    local api_user api_pass
    api_user=$(sed -n 's/^USER=//p' "$CRED_FILE")
    api_pass=$(sed -n 's/^APP_PASSWORD=//p' "$CRED_FILE")
    [ -n "$api_pass" ] || c_die "APP_PASSWORD ausente em $CRED_FILE"
    c_ok "WHMCS em $WHMCS_PATH; banco $DB_NAME; credencial ok"

    step "2/6  Backup"
    if [ -x /root/whmcs_bkp_completo.sh ]; then
        c_warn "rodando backup completo — pode demorar"
        bash /root/whmcs_bkp_completo.sh >/dev/null 2>&1 && c_ok "backup concluído" || c_warn "backup falhou; siga por sua conta e risco"
    else
        mysqldump -u"$DB_USER" -p"$DB_PASS" --single-transaction "$DB_NAME" | gzip > "/root/whmcs-pre-imovelsite-$(date +%F-%H%M).sql.gz" \
            && c_ok "dump do banco salvo em /root" || c_die "backup falhou"
    fi

    step "3/6  Arquivos dos módulos"
    cp -a "$HERE/whmcs-addon"          "$WHMCS_PATH/modules/addons/imovelsite.new"
    cp -a "$HERE/whmcs-server-module"  "$WHMCS_PATH/modules/servers/imovelsite.new"
    rm -f "$WHMCS_PATH/modules/addons/imovelsite.new/_includes-hooks-imovelsite_hooks_loader.php"
    rm -rf "$WHMCS_PATH/modules/addons/imovelsite" "$WHMCS_PATH/modules/servers/imovelsite"
    mv "$WHMCS_PATH/modules/addons/imovelsite.new"  "$WHMCS_PATH/modules/addons/imovelsite"
    mv "$WHMCS_PATH/modules/servers/imovelsite.new" "$WHMCS_PATH/modules/servers/imovelsite"
    cp -a "$HERE/whmcs-addon/_includes-hooks-imovelsite_hooks_loader.php" \
          "$WHMCS_PATH/includes/hooks/imovelsite_hooks_loader.php"
    chown -R "$WHMCS_OWNER:$WHMCS_OWNER" \
        "$WHMCS_PATH/modules/addons/imovelsite" \
        "$WHMCS_PATH/modules/servers/imovelsite" \
        "$WHMCS_PATH/includes/hooks/imovelsite_hooks_loader.php"
    c_ok "addon, módulo de servidor e loader instalados"

    step "4/6  Banco: tabela de log, addon e servidor"
    sqlq "CREATE TABLE IF NOT EXISTS mod_imovelsite_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        serviceid INT UNSIGNED NOT NULL,
        slug VARCHAR(100) NOT NULL DEFAULT '',
        action VARCHAR(30) NOT NULL,
        status VARCHAR(20) NOT NULL,
        job_id VARCHAR(64) NULL,
        request TEXT NULL, response TEXT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        KEY idx_service (serviceid), KEY idx_status (status)
    ) DEFAULT CHARSET=utf8" && c_ok "mod_imovelsite_log"

    # Settings do addon
    if [ "$(sqlq "SELECT COUNT(*) FROM tbladdonmodules WHERE module='imovelsite'")" = "0" ]; then
        sqlq "INSERT INTO tbladdonmodules (module,setting,value) VALUES
            ('imovelsite','version','1.0.0'),
            ('imovelsite','access',''),
            ('imovelsite','product_ids','$PRODUCT_ID'),
            ('imovelsite','enable_cart_banner','on'),
            ('imovelsite','welcome_email_template_id','$WELCOME_TPL_ID'),
            ('imovelsite','notify_email_domains','$NOTIFY_EMAIL')"
        c_ok "settings do addon"
    else
        c_skip "settings do addon já existem"
    fi

    # ActiveAddonModules e AddonModulesHooks — sem o segundo, hooks.php NUNCA carrega.
    for setting in ActiveAddonModules AddonModulesHooks; do
        local cur; cur=$(sqlq "SELECT value FROM tblconfiguration WHERE setting='$setting'")
        case ",$cur," in
            *,imovelsite,*) c_skip "$setting já contém imovelsite" ;;
            *) sqlq "UPDATE tblconfiguration SET value='${cur:+$cur,}imovelsite' WHERE setting='$setting'"
               c_ok "$setting atualizado" ;;
        esac
    done

    # Servidor + grupo
    local srv_id grp_id
    srv_id=$(sqlq "SELECT id FROM tblservers WHERE type='imovelsite' LIMIT 1")
    if [ -z "$srv_id" ]; then
        sqlq "INSERT INTO tblservers (name,ipaddress,hostname,username,password,accesshash,secure,port,active,disabled,type,maxaccounts,monthlycost,assignedips,noc,statusaddress,nameserver1,nameserver1ip,nameserver2,nameserver2ip,nameserver3,nameserver3ip,nameserver4,nameserver4ip,nameserver5,nameserver5ip)
            VALUES ('ImovelSite REST','$SERVER_IP','$API_HOST','$api_user','','$api_pass','on',443,1,0,'imovelsite',0,0,'','','','','','','','','','','','')"
        srv_id=$(sqlq "SELECT id FROM tblservers WHERE type='imovelsite' LIMIT 1")
        c_ok "servidor criado (id=$srv_id)"
    else
        sqlq "UPDATE tblservers SET hostname='$API_HOST', username='$api_user', accesshash='$api_pass', secure='on', port=443, active=1 WHERE id=$srv_id"
        c_ok "servidor atualizado (id=$srv_id)"
    fi
    grp_id=$(sqlq "SELECT id FROM tblservergroups WHERE name='ImovelSite REST' LIMIT 1")
    if [ -z "$grp_id" ]; then
        sqlq "INSERT INTO tblservergroups (name,filltype) VALUES ('ImovelSite REST',1)"
        grp_id=$(sqlq "SELECT id FROM tblservergroups WHERE name='ImovelSite REST' LIMIT 1")
        sqlq "INSERT INTO tblservergroupsrel (groupid,serverid) VALUES ($grp_id,$srv_id)"
        c_ok "grupo de servidores criado (id=$grp_id)"
    else
        c_skip "grupo de servidores já existe (id=$grp_id)"
    fi

    step "5/6  Template de e-mail e campo do produto"
    # SendEmail só encontra o template se houver linha mestre com language=''.
    local lang; lang=$(sqlq "SELECT language FROM tblemailtemplates WHERE id=$WELCOME_TPL_ID")
    if [ -n "$lang" ]; then
        sqlq "UPDATE tblemailtemplates SET language='' WHERE id=$WELCOME_TPL_ID"
        c_ok "template $WELCOME_TPL_ID promovido a mestre (language='')"
    else
        c_skip "template $WELCOME_TPL_ID já é mestre"
    fi
    # 'copyto' com valor inválido gera "Invalid address: (cc)" e o envio falha.
    local cc; cc=$(sqlq "SELECT copyto FROM tblemailtemplates WHERE id=$WELCOME_TPL_ID")
    if [ -n "$cc" ] && ! printf '%s' "$cc" | grep -q '@'; then
        sqlq "UPDATE tblemailtemplates SET copyto='' WHERE id=$WELCOME_TPL_ID"
        c_ok "copyto inválido ('$cc') limpo"
    fi
    # Corpo do e-mail: backup e substituição pela versão rica (com seção da caixa de e-mail).
    local html="$WHMCS_PATH/modules/addons/imovelsite/templates/email-boas-vindas-imovelsite.html"
    if [ -f "$html" ]; then
        if [ "$(sqlq "SELECT COUNT(*) FROM mod_imovelsite_log WHERE action='email_backup' AND slug='emailtpl-$WELCOME_TPL_ID'")" = "0" ]; then
            mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
                INSERT INTO mod_imovelsite_log (serviceid,slug,action,status,request,response,created_at,updated_at)
                SELECT 0,'emailtpl-$WELCOME_TPL_ID','email_backup','success',subject,message,NOW(),NOW()
                FROM tblemailtemplates WHERE id=$WELCOME_TPL_ID"
            c_ok "backup do corpo do template salvo em mod_imovelsite_log"
        else
            c_skip "backup do template já existe"
        fi
        c_warn "corpo do template NÃO foi sobrescrito; aplique pelo admin ou ative o addon pela interface"
    fi

    if [ "$(sqlq "SELECT COUNT(*) FROM tblcustomfields WHERE type='product' AND relid=$PRODUCT_ID AND fieldname LIKE 'Prefixo do Site%'")" = "0" ]; then
        sqlq "INSERT INTO tblcustomfields (type,relid,fieldname,fieldtype,description,fieldoptions,regexpr,adminonly,required,showorder,showinvoice,sortorder,created_at,updated_at)
            VALUES ('product',$PRODUCT_ID,'Prefixo do Site|prefix','text','Escolha o endereco do seu site: SEU_NOME.$ROOT_DOMAIN (letras minusculas e numeros, 3-20 caracteres)','','','','on','on','',0,NOW(),NOW())"
        c_ok "campo 'Prefixo do Site' criado no produto $PRODUCT_ID"
    else
        c_skip "campo 'Prefixo do Site' já existe"
    fi

    step "6/6  Configuração do produto $PRODUCT_ID"
    c_warn "esta é a virada: o produto passa a provisionar pelo módulo REST"
    sqlq "UPDATE tblproducts SET
        servertype='imovelsite', servergroup=$grp_id, autosetup='payment',
        configoption1='/wp-json/imovelsite/v1', configoption2='30', configoption3='150', configoption4='on'
        WHERE id=$PRODUCT_ID"
    c_ok "produto migrado (autosetup=payment)"
    sqlq "SELECT CONCAT('  produto ',id,': módulo=',servertype,' grupo=',servergroup,' autosetup=',autosetup,' welcome=',welcomeemail) FROM tblproducts WHERE id=$PRODUCT_ID"

    printf '\n\033[1;32mWHMCS pronto.\033[0m Rode ./install.sh check e depois um pedido de teste.\n\n'
}

# ---------------------------------------------------------------------------
# Verificação
# ---------------------------------------------------------------------------
run_check() {
    step "API"
    if [ -f "$CRED_FILE" ]; then
        local u p base
        u=$(sed -n 's/^USER=//p' "$CRED_FILE")
        p=$(sed -n 's/^APP_PASSWORD=//p' "$CRED_FILE")
        base=$(sed -n 's/^BASE_URL=//p' "$CRED_FILE")
        local body; body=$(curl -s -m 15 -u "$u:$p" "$base/ping")
        printf '%s' "$body" | grep -q '"ok":true' && c_ok "ping autenticado: $body" || c_warn "ping falhou: $body"
        local code; code=$(curl -s -o /dev/null -m 15 -w '%{http_code}' "$base/ping")
        [ "$code" = "401" ] && c_ok "sem auth → 401" || c_warn "sem auth → $code (esperado 401)"
    else
        c_warn "credencial ausente ($CRED_FILE) — pulei o teste da API"
    fi

    if [ -f "$WHMCS_PATH/configuration.php" ]; then
        whmcs_db
        step "WHMCS"
        sqlq "SELECT CONCAT('  produto ',id,': ',servertype,' / autosetup=',IFNULL(NULLIF(autosetup,''),'(vazio!)')) FROM tblproducts WHERE id=$PRODUCT_ID"
        for s in ActiveAddonModules AddonModulesHooks; do
            case ",$(sqlq "SELECT value FROM tblconfiguration WHERE setting='$s'")," in
                *,imovelsite,*) c_ok "$s contém imovelsite" ;;
                *) c_warn "$s NÃO contém imovelsite (hooks não vão carregar)" ;;
            esac
        done
        [ "$(sqlq "SELECT COUNT(*) FROM tblcustomfields WHERE type='product' AND relid=$PRODUCT_ID AND fieldname LIKE 'Prefixo do Site%'")" -gt 0 ] \
            && c_ok "campo 'Prefixo do Site' presente" || c_warn "campo 'Prefixo do Site' ausente"
        [ -z "$(sqlq "SELECT language FROM tblemailtemplates WHERE id=$WELCOME_TPL_ID")" ] \
            && c_ok "template $WELCOME_TPL_ID é mestre" || c_warn "template $WELCOME_TPL_ID tem language definido (SendEmail não acha)"
        local cc; cc=$(sqlq "SELECT copyto FROM tblemailtemplates WHERE id=$WELCOME_TPL_ID")
        [ -z "$cc" ] || printf '%s' "$cc" | grep -q '@' && c_ok "copyto do template ok" || c_warn "copyto inválido: '$cc'"
        sqlq "SELECT CONCAT('  jobs: ',status,' = ',COUNT(*)) FROM mod_imovelsite_log GROUP BY status" 2>/dev/null || c_warn "mod_imovelsite_log ausente"
    fi

    if [ -f "$WP_PATH/wp-config.php" ]; then
        step "WordPress"
        grep -q IMOVELSITE_CF_TOKEN "$WP_PATH/wp-config.php" && c_ok "constantes IMOVELSITE_* presentes" || c_warn "constantes ausentes no wp-config.php"
        grep -q HTTP_AUTHORIZATION "$WP_PATH/.htaccess" 2>/dev/null && c_ok "regra HTTP_AUTHORIZATION no .htaccess" || c_warn "regra HTTP_AUTHORIZATION ausente (API dará 401)"
        crontab -l 2>/dev/null | grep -qF imovelsite_process_pending && c_ok "cron da fila instalado" || c_warn "cron ausente (jobs ficarão presos em pending)"
    fi
    echo
}

# ---------------------------------------------------------------------------
case "${1:-}" in
    wordpress) install_wordpress ;;
    whmcs)     install_whmcs ;;
    check)     run_check ;;
    *)
        cat <<EOF
Instalador da integração ImovelSite.

  $0 wordpress   # no servidor dos sites (WordPress + cPanel)
  $0 whmcs       # no servidor de billing (WHMCS)
  $0 check       # verificação (roda em qualquer um dos dois)

Configure as variáveis no topo do script, ou exporte-as:
  CF_TOKEN, CF_ZONE, SERVER_IP, WP_PATH, WHMCS_PATH, PRODUCT_ID, NOTIFY_EMAIL, ...

A etapa 'wordpress' gera $CRED_FILE — copie-o para o servidor do WHMCS
antes de rodar a etapa 'whmcs'.
EOF
        exit 1 ;;
esac
