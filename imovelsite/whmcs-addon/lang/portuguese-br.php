<?php
/**
 * ImovelSite addon — strings pt-BR.
 * Todas as strings visíveis ao usuário (carrinho, dashboard admin, validações).
 */

if (!defined('WHMCS')) {
    die('Acesso negado');
}

// ---------------------------------------------------------------------------
// Banner do carrinho (etapa de escolha de domínio)
// ---------------------------------------------------------------------------
$_ADDONLANG['cart_banner_title'] = 'Você já ganha endereço e e-mail GRÁTIS!';
$_ADDONLANG['cart_banner_body'] = "Ao adquirir o plano ImovelSite você automaticamente já ganha totalmente GRÁTIS o endereço no modelo SEU_NOME.<ROOT_DOMAIN> e uma conta de e-mail exclusiva no formato SEU_NOME@<ROOT_DOMAIN>. Sua conta no ImovelSite é provisionada INSTANTANEAMENTE após a confirmação do pagamento. Mesmo assim, se você JÁ TEM um domínio próprio ou deseja criar um novo domínio próprio (ex.: nomeescolhido.com.br), selecione uma das opções abaixo: marque 'Registrar um novo domínio' (R\$ 60,00/ano), clique em verificar disponibilidade e, confirmada a disponibilidade, prossiga normalmente — seu novo domínio será direcionado para o seu site de divulgação de imóveis no ImovelSite.";

// ---------------------------------------------------------------------------
// Validação do checkout (Prefixo do Site)
// ---------------------------------------------------------------------------
$_ADDONLANG['validation_prefix_missing'] = 'Informe o Prefixo do Site (o endereço ficará SEU_NOME.<ROOT_DOMAIN>). Volte à etapa de configuração do produto e preencha o campo "Prefixo do Site".';
$_ADDONLANG['validation_prefix_invalid'] = 'Prefixo do Site inválido: use apenas letras minúsculas e números, de 3 a 20 caracteres, sem espaços ou acentos (ex.: joaoimoveis).';
$_ADDONLANG['validation_prefix_taken'] = 'O prefixo escolhido já está em uso por outro cliente. Por favor, escolha outro Prefixo do Site.';
$_ADDONLANG['validation_prefix_duplicate_cart'] = 'Você usou o mesmo Prefixo do Site em mais de um produto do carrinho. Escolha um prefixo diferente para cada site.';

// ---------------------------------------------------------------------------
// Dashboard admin
// ---------------------------------------------------------------------------
$_ADDONLANG['dash_title'] = 'ImovelSite — Painel de Provisionamento';
$_ADDONLANG['dash_log_heading'] = 'Últimos registros (mod_imovelsite_log)';
$_ADDONLANG['dash_filter_status'] = 'Filtrar por status';
$_ADDONLANG['dash_filter_all'] = 'Todos';
$_ADDONLANG['dash_col_id'] = 'ID';
$_ADDONLANG['dash_col_service'] = 'Serviço';
$_ADDONLANG['dash_col_slug'] = 'Slug/Domínio';
$_ADDONLANG['dash_col_action'] = 'Ação';
$_ADDONLANG['dash_col_status'] = 'Status';
$_ADDONLANG['dash_col_job'] = 'Job';
$_ADDONLANG['dash_col_attempts'] = 'Tentativas';
$_ADDONLANG['dash_col_created'] = 'Criado em';
$_ADDONLANG['dash_col_updated'] = 'Atualizado em';
$_ADDONLANG['dash_col_actions'] = 'Ações';
$_ADDONLANG['dash_btn_reprocess'] = 'Reprocessar';
$_ADDONLANG['dash_btn_mark_mapped'] = 'Marcar mapeado';
$_ADDONLANG['dash_btn_restore_email'] = 'Restaurar backup do template de e-mail';
$_ADDONLANG['dash_confirm_restore_email'] = 'Restaurar o template de e-mail a partir do backup feito na ativação? O conteúdo atual do template será substituído.';
$_ADDONLANG['dash_confirm_reprocess'] = 'Chamar ModuleCreate novamente para este serviço?';
$_ADDONLANG['dash_domains_heading'] = 'Domínios aguardando registro manual';
$_ADDONLANG['dash_domains_empty'] = 'Nenhum domínio aguardando registro manual.';
$_ADDONLANG['dash_log_empty'] = 'Nenhum registro encontrado.';
$_ADDONLANG['dash_msg_reprocess_ok'] = 'ModuleCreate executado com sucesso para o serviço #%s.';
$_ADDONLANG['dash_msg_reprocess_fail'] = 'ModuleCreate falhou para o serviço #%s: %s';
$_ADDONLANG['dash_msg_mapped_ok'] = 'Registro #%s marcado como mapeado.';
$_ADDONLANG['dash_msg_restore_ok'] = 'Template de e-mail restaurado a partir do backup (log #%s).';
$_ADDONLANG['dash_msg_restore_none'] = 'Nenhum backup de template de e-mail encontrado no log.';
$_ADDONLANG['dash_msg_row_missing'] = 'Registro de log não encontrado.';
$_ADDONLANG['dash_email_section'] = 'Template de e-mail de boas-vindas';
$_ADDONLANG['dash_email_hint'] = 'O botão abaixo restaura o assunto e o corpo do template de boas-vindas a partir do backup gravado no momento da ativação do addon (linha action=email_backup do log).';

// ---------------------------------------------------------------------------
// Tarefas / notificações de registro manual de domínio
// ---------------------------------------------------------------------------
$_ADDONLANG['todo_register_domain_title'] = 'Registrar domínio %s (ImovelSite)';
$_ADDONLANG['todo_register_domain_desc'] = "Cliente #%d (%s) comprou o domínio %s junto com o plano ImovelSite (pedido #%d).\nRegistrar/transferir o domínio manualmente e apontar para o site do cliente.\nPedido: %s";
$_ADDONLANG['notify_domain_subject'] = '[ImovelSite] Registrar domínio %s (pedido #%d)';
$_ADDONLANG['notify_domain_body'] = "O pedido #%d inclui o produto ImovelSite e o domínio %s (%s).\n\nO registro do domínio é MANUAL: registre/transfira o domínio e depois use o painel do addon ImovelSite (Configurações > Addons > ImovelSite) para marcar o mapeamento.\n\nCliente: #%d %s\nLink do pedido: %s";
