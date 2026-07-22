<?php

namespace FzMcp;

/**
 * The fzWHMCS-MCP-AI tool catalogue.
 *
 * Every entry maps an MCP tool to a WHMCS API action with a JSON Schema for
 * its arguments and a read/write classification that drives the permission
 * model.  Depth is concentrated on the two areas the operator requires fully
 * covered: administration and ticket/support. Breadth covers the rest of the
 * WHMCS API surface.
 *
 * Classification rule (mirrors the WHMCS verbs):
 *   read  : Get* / List* / *Details / *Status  -> "informar / registrar"
 *   write : Add* / Create* / Update* / Delete* / Accept* / Cancel* / Suspend*
 *           / Terminate* / Open* / Close* / Module* / Domain(register/renew...)
 *           / Send* / Log* / Set*  -> "informar e agir"
 */
class ToolRegistry
{
    const READ  = 'read';
    const WRITE = 'write';

    /** @var array|null cached catalogue */
    private static $tools = null;

    /**
     * @return array[] list of tool definitions
     */
    public static function all()
    {
        if (self::$tools !== null) {
            return self::$tools;
        }

        $t = array();

        // ---- helpers -------------------------------------------------------
        $str  = function ($desc, $enum = null) {
            $s = array('type' => 'string', 'description' => $desc);
            if ($enum !== null) { $s['enum'] = $enum; }
            return $s;
        };
        $int  = function ($desc) { return array('type' => 'integer', 'description' => $desc); };
        $num  = function ($desc) { return array('type' => 'number', 'description' => $desc); };
        $bool = function ($desc) { return array('type' => 'boolean', 'description' => $desc); };

        $obj = function ($props, $required = array(), $additional = true) {
            return array(
                'type'                 => 'object',
                'properties'           => $props,
                'required'             => $required,
                'additionalProperties' => (bool) $additional,
            );
        };

        // ======================================================================
        // CLIENTES / USUARIOS
        // ======================================================================
        $cat = 'Clientes';
        $t[] = array('name' => 'GetClients', 'category' => $cat, 'action' => 'GetClients', 'rw' => self::READ,
            'description' => 'Lista clientes com paginacao, busca e filtro por status.',
            'inputSchema' => $obj(array(
                'limitstart' => $int('Deslocamento inicial (paginacao).'),
                'limitnum'   => $int('Quantidade de registros (padrao 25).'),
                'search'     => $str('Termo de busca (nome, email, empresa).'),
                'status'     => $str('Filtro de status.', array('Active', 'Inactive', 'Closed')),
                'sorting'    => $str('Ordenacao.', array('ASC', 'DESC')),
            )));
        $t[] = array('name' => 'GetClientsDetails', 'category' => $cat, 'action' => 'GetClientsDetails', 'rw' => self::READ,
            'description' => 'Retorna os detalhes completos de um cliente.',
            'inputSchema' => $obj(array(
                'clientid' => $int('ID do cliente.'),
                'email'    => $str('Email do cliente (alternativa ao clientid).'),
                'stats'    => $bool('Incluir estatisticas do cliente.'),
            )));
        $t[] = array('name' => 'GetClientsProducts', 'category' => $cat, 'action' => 'GetClientsProducts', 'rw' => self::READ,
            'description' => 'Lista os produtos/servicos de um cliente.',
            'inputSchema' => $obj(array(
                'clientid'  => $int('ID do cliente.'),
                'serviceid' => $int('ID do servico especifico.'),
                'pid'       => $int('ID do produto.'),
                'domain'    => $str('Dominio associado.'),
                'stats'     => $bool('Incluir estatisticas.'),
                'limitstart'=> $int('Paginacao inicial.'),
                'limitnum'  => $int('Quantidade de registros.'),
            )));
        $t[] = array('name' => 'GetClientsDomains', 'category' => $cat, 'action' => 'GetClientsDomains', 'rw' => self::READ,
            'description' => 'Lista os dominios de um cliente.',
            'inputSchema' => $obj(array(
                'clientid' => $int('ID do cliente.'),
                'domainid' => $int('ID do dominio especifico.'),
                'domain'   => $str('Nome do dominio.'),
                'limitstart'=> $int('Paginacao inicial.'),
                'limitnum' => $int('Quantidade de registros.'),
            )));
        $t[] = array('name' => 'GetEmails', 'category' => $cat, 'action' => 'GetEmails', 'rw' => self::READ,
            'description' => 'Lista os emails enviados a um cliente.',
            'inputSchema' => $obj(array(
                'clientid'  => $int('ID do cliente.'),
                'date'      => $str('Filtrar por data (YYYY-MM-DD).'),
                'limitstart'=> $int('Paginacao inicial.'),
                'limitnum'  => $int('Quantidade de registros.'),
            ), array('clientid')));
        $t[] = array('name' => 'AddClient', 'category' => $cat, 'action' => 'AddClient', 'rw' => self::WRITE,
            'description' => 'Cria um novo cliente. (Escrita - requer nivel agir.)',
            'inputSchema' => $obj(array(
                'firstname'   => $str('Nome.'),
                'lastname'    => $str('Sobrenome.'),
                'email'       => $str('Email.'),
                'address1'    => $str('Endereco.'),
                'city'        => $str('Cidade.'),
                'state'       => $str('Estado/UF.'),
                'postcode'    => $str('CEP.'),
                'country'     => $str('Pais (codigo ISO, ex: BR).'),
                'phonenumber' => $str('Telefone.'),
                'password2'   => $str('Senha do cliente.'),
                'companyname' => $str('Empresa (opcional).'),
                'clientip'    => $str('IP do cliente (opcional).'),
                'noemail'     => $bool('Nao enviar email de boas-vindas.'),
            ), array('firstname', 'lastname', 'email', 'address1', 'city', 'state', 'postcode', 'country', 'phonenumber', 'password2')));
        $t[] = array('name' => 'UpdateClient', 'category' => $cat, 'action' => 'UpdateClient', 'rw' => self::WRITE,
            'description' => 'Atualiza dados de um cliente. (Escrita.)',
            'inputSchema' => $obj(array(
                'clientid'    => $int('ID do cliente.'),
                'firstname'   => $str('Nome.'),
                'lastname'    => $str('Sobrenome.'),
                'email'       => $str('Email.'),
                'address1'    => $str('Endereco.'),
                'city'        => $str('Cidade.'),
                'state'       => $str('Estado/UF.'),
                'postcode'    => $str('CEP.'),
                'country'     => $str('Pais (ISO).'),
                'phonenumber' => $str('Telefone.'),
                'status'      => $str('Status.', array('Active', 'Inactive', 'Closed')),
                'notes'       => $str('Anotacoes administrativas.'),
            ), array('clientid')));
        $t[] = array('name' => 'CloseClient', 'category' => $cat, 'action' => 'CloseClient', 'rw' => self::WRITE,
            'description' => 'Encerra (fecha) a conta de um cliente. (Escrita.)',
            'inputSchema' => $obj(array('clientid' => $int('ID do cliente.')), array('clientid')));
        $t[] = array('name' => 'DeleteClient', 'category' => $cat, 'action' => 'DeleteClient', 'rw' => self::WRITE,
            'description' => 'Exclui permanentemente um cliente. (Escrita - destrutivo.)',
            'inputSchema' => $obj(array(
                'clientid'          => $int('ID do cliente.'),
                'deleteusers'       => $bool('Excluir usuarios vinculados.'),
                'deletetransactions'=> $bool('Excluir transacoes.'),
            ), array('clientid')));
        $t[] = array('name' => 'GetCancelledPackages', 'category' => $cat, 'action' => 'GetCancelledPackages', 'rw' => self::READ,
            'description' => 'Lista pacotes com cancelamento agendado/efetuado.',
            'inputSchema' => $obj(array(
                'limitstart' => $int('Paginacao inicial.'),
                'limitnum'   => $int('Quantidade.'),
            )));

        // ======================================================================
        // PEDIDOS / ORDERS
        // ======================================================================
        $cat = 'Pedidos';
        $t[] = array('name' => 'GetOrders', 'category' => $cat, 'action' => 'GetOrders', 'rw' => self::READ,
            'description' => 'Lista pedidos com filtros por status/cliente.',
            'inputSchema' => $obj(array(
                'id'        => $int('ID do pedido.'),
                'userid'    => $int('ID do cliente.'),
                'status'    => $str('Status do pedido.', array('Pending', 'Active', 'Fraud', 'Cancelled')),
                'limitstart'=> $int('Paginacao inicial.'),
                'limitnum'  => $int('Quantidade.'),
            )));
        $t[] = array('name' => 'GetOrderStatuses', 'category' => $cat, 'action' => 'GetOrderStatuses', 'rw' => self::READ,
            'description' => 'Lista os status de pedido configurados e contagens.',
            'inputSchema' => $obj(array()));
        $t[] = array('name' => 'AddOrder', 'category' => $cat, 'action' => 'AddOrder', 'rw' => self::WRITE,
            'description' => 'Cria um novo pedido para um cliente. (Escrita.)',
            'inputSchema' => $obj(array(
                'clientid'      => $int('ID do cliente.'),
                'paymentmethod' => $str('Gateway de pagamento (ex: banktransfer).'),
                'pid'           => array('type' => 'array', 'description' => 'IDs dos produtos.', 'items' => $int('ID do produto.')),
                'domain'        => array('type' => 'array', 'description' => 'Dominios.', 'items' => $str('Dominio.')),
                'billingcycle'  => array('type' => 'array', 'description' => 'Ciclos de cobranca.', 'items' => $str('Ciclo.')),
                'promocode'     => $str('Codigo promocional.'),
                'noinvoice'     => $bool('Nao gerar fatura.'),
                'noemail'       => $bool('Nao enviar emails.'),
            ), array('clientid', 'paymentmethod')));
        $t[] = array('name' => 'AcceptOrder', 'category' => $cat, 'action' => 'AcceptOrder', 'rw' => self::WRITE,
            'description' => 'Aceita um pedido pendente (provisiona). (Escrita.)',
            'inputSchema' => $obj(array(
                'orderid'          => $int('ID do pedido.'),
                'autosetup'        => $bool('Executar provisionamento automatico.'),
                'sendregistrar'    => $bool('Enviar comandos ao registrador.'),
                'sendemail'        => $bool('Enviar email de boas-vindas.'),
            ), array('orderid')));
        $t[] = array('name' => 'PendingOrder', 'category' => $cat, 'action' => 'PendingOrder', 'rw' => self::WRITE,
            'description' => 'Marca um pedido como pendente. (Escrita.)',
            'inputSchema' => $obj(array('orderid' => $int('ID do pedido.')), array('orderid')));
        $t[] = array('name' => 'CancelOrder', 'category' => $cat, 'action' => 'CancelOrder', 'rw' => self::WRITE,
            'description' => 'Cancela um pedido. (Escrita.)',
            'inputSchema' => $obj(array(
                'orderid' => $int('ID do pedido.'),
                'cancelsub' => $bool('Cancelar assinatura no gateway.'),
            ), array('orderid')));
        $t[] = array('name' => 'FraudOrder', 'category' => $cat, 'action' => 'FraudOrder', 'rw' => self::WRITE,
            'description' => 'Marca um pedido como fraudulento. (Escrita.)',
            'inputSchema' => $obj(array('orderid' => $int('ID do pedido.')), array('orderid')));
        $t[] = array('name' => 'DeleteOrder', 'category' => $cat, 'action' => 'DeleteOrder', 'rw' => self::WRITE,
            'description' => 'Exclui um pedido permanentemente. (Escrita - destrutivo.)',
            'inputSchema' => $obj(array('orderid' => $int('ID do pedido.')), array('orderid')));

        // ======================================================================
        // FATURAS / FINANCEIRO
        // ======================================================================
        $cat = 'Faturas';
        $t[] = array('name' => 'GetInvoices', 'category' => $cat, 'action' => 'GetInvoices', 'rw' => self::READ,
            'description' => 'Lista faturas com filtros por cliente/status.',
            'inputSchema' => $obj(array(
                'userid'    => $int('ID do cliente.'),
                'status'    => $str('Status.', array('Draft', 'Unpaid', 'Paid', 'Overdue', 'Cancelled', 'Refunded', 'Collections')),
                'limitstart'=> $int('Paginacao inicial.'),
                'limitnum'  => $int('Quantidade.'),
            )));
        $t[] = array('name' => 'GetInvoice', 'category' => $cat, 'action' => 'GetInvoice', 'rw' => self::READ,
            'description' => 'Retorna os detalhes de uma fatura.',
            'inputSchema' => $obj(array('invoiceid' => $int('ID da fatura.')), array('invoiceid')));
        $t[] = array('name' => 'GetTransactions', 'category' => $cat, 'action' => 'GetTransactions', 'rw' => self::READ,
            'description' => 'Lista transacoes financeiras.',
            'inputSchema' => $obj(array(
                'invoiceid' => $int('ID da fatura.'),
                'clientid'  => $int('ID do cliente.'),
                'transid'   => $str('ID da transacao no gateway.'),
            )));
        $t[] = array('name' => 'GetCredits', 'category' => $cat, 'action' => 'GetCredits', 'rw' => self::READ,
            'description' => 'Lista os creditos de um cliente.',
            'inputSchema' => $obj(array('clientid' => $int('ID do cliente.')), array('clientid')));
        $t[] = array('name' => 'CreateInvoice', 'category' => $cat, 'action' => 'CreateInvoice', 'rw' => self::WRITE,
            'description' => 'Cria uma fatura para um cliente. (Escrita.)',
            'inputSchema' => $obj(array(
                'userid'        => $int('ID do cliente.'),
                'status'        => $str('Status inicial.', array('Draft', 'Unpaid', 'Paid')),
                'sendinvoice'   => $bool('Enviar a fatura por email.'),
                'paymentmethod' => $str('Gateway.'),
                'date'          => $str('Data (YYYY-MM-DD).'),
                'duedate'       => $str('Vencimento (YYYY-MM-DD).'),
                'itemdescription1' => $str('Descricao do item 1.'),
                'itemamount1'      => $num('Valor do item 1.'),
                'itemtaxed1'       => $bool('Item 1 tributado.'),
            ), array('userid')));
        $t[] = array('name' => 'UpdateInvoice', 'category' => $cat, 'action' => 'UpdateInvoice', 'rw' => self::WRITE,
            'description' => 'Atualiza uma fatura existente. (Escrita.)',
            'inputSchema' => $obj(array(
                'invoiceid' => $int('ID da fatura.'),
                'status'    => $str('Novo status.'),
                'duedate'   => $str('Novo vencimento (YYYY-MM-DD).'),
                'notes'     => $str('Anotacoes.'),
            ), array('invoiceid')));
        $t[] = array('name' => 'AddInvoicePayment', 'category' => $cat, 'action' => 'AddInvoicePayment', 'rw' => self::WRITE,
            'description' => 'Registra um pagamento em uma fatura. (Escrita.)',
            'inputSchema' => $obj(array(
                'invoiceid' => $int('ID da fatura.'),
                'transid'   => $str('ID da transacao.'),
                'gateway'   => $str('Gateway.'),
                'date'      => $str('Data do pagamento (YYYY-MM-DD HH:MM:SS).'),
                'amount'    => $num('Valor pago.'),
                'fees'      => $num('Taxas.'),
            ), array('invoiceid', 'transid')));
        $t[] = array('name' => 'ApplyCredit', 'category' => $cat, 'action' => 'ApplyCredit', 'rw' => self::WRITE,
            'description' => 'Aplica credito do cliente em uma fatura. (Escrita.)',
            'inputSchema' => $obj(array(
                'invoiceid' => $int('ID da fatura.'),
                'amount'    => $num('Valor a aplicar (ou "full").'),
            ), array('invoiceid')));
        $t[] = array('name' => 'AddCredit', 'category' => $cat, 'action' => 'AddCredit', 'rw' => self::WRITE,
            'description' => 'Adiciona credito na conta de um cliente. (Escrita.)',
            'inputSchema' => $obj(array(
                'clientid'    => $int('ID do cliente.'),
                'description' => $str('Descricao.'),
                'amount'      => $num('Valor do credito.'),
            ), array('clientid', 'description', 'amount')));
        $t[] = array('name' => 'AddBillableItem', 'category' => $cat, 'action' => 'AddBillableItem', 'rw' => self::WRITE,
            'description' => 'Cria um item faturavel avulso. (Escrita.)',
            'inputSchema' => $obj(array(
                'clientid'    => $int('ID do cliente.'),
                'description' => $str('Descricao do item.'),
                'amount'      => $num('Valor.'),
                'invoiceaction' => $str('Acao de faturamento.', array('noinvoice', 'nextcron', 'nextinvoice', 'duedate', 'recur')),
                'recur'       => $int('Recorrencia.'),
                'recurcycle'  => $str('Ciclo.', array('Days', 'Weeks', 'Months', 'Years')),
                'duedate'     => $str('Vencimento (YYYY-MM-DD).'),
            ), array('clientid', 'description', 'amount')));
        $t[] = array('name' => 'AddTransaction', 'category' => $cat, 'action' => 'AddTransaction', 'rw' => self::WRITE,
            'description' => 'Registra uma transacao financeira avulsa. (Escrita.)',
            'inputSchema' => $obj(array(
                'userid'      => $int('ID do cliente.'),
                'invoiceid'   => $int('ID da fatura.'),
                'description' => $str('Descricao.'),
                'amountin'    => $num('Valor de entrada.'),
                'amountout'   => $num('Valor de saida.'),
                'paymentmethod' => $str('Gateway.'),
                'transid'     => $str('ID da transacao.'),
                'date'        => $str('Data (YYYY-MM-DD).'),
            )));
        $t[] = array('name' => 'GenInvoices', 'category' => $cat, 'action' => 'GenInvoices', 'rw' => self::WRITE,
            'description' => 'Gera faturas em lote (rotina de cobranca). (Escrita.)',
            'inputSchema' => $obj(array(
                'noemails' => $bool('Nao enviar emails.'),
                'clientid' => $int('Restringir a um cliente.'),
            )));
        $t[] = array('name' => 'CapturePayment', 'category' => $cat, 'action' => 'CapturePayment', 'rw' => self::WRITE,
            'description' => 'Captura pagamento de uma fatura via gateway. (Escrita.)',
            'inputSchema' => $obj(array(
                'invoiceid' => $int('ID da fatura.'),
                'cvv'       => $str('CVV (se aplicavel).'),
            ), array('invoiceid')));

        // ======================================================================
        // PRODUTOS / SERVICOS
        // ======================================================================
        $cat = 'Produtos';
        $t[] = array('name' => 'GetProducts', 'category' => $cat, 'action' => 'GetProducts', 'rw' => self::READ,
            'description' => 'Lista produtos/planos do catalogo.',
            'inputSchema' => $obj(array(
                'pid'    => $int('ID do produto.'),
                'gid'    => $int('ID do grupo de produtos.'),
                'module' => $str('Filtrar por modulo.'),
            )));
        $t[] = array('name' => 'UpdateClientProduct', 'category' => $cat, 'action' => 'UpdateClientProduct', 'rw' => self::WRITE,
            'description' => 'Atualiza um servico contratado por um cliente. (Escrita.)',
            'inputSchema' => $obj(array(
                'serviceid'     => $int('ID do servico.'),
                'status'        => $str('Status.', array('Pending', 'Active', 'Suspended', 'Terminated', 'Cancelled', 'Fraud', 'Completed')),
                'domain'        => $str('Dominio.'),
                'nextduedate'   => $str('Proximo vencimento (YYYY-MM-DD).'),
                'recurringamount' => $num('Valor recorrente.'),
                'billingcycle'  => $str('Ciclo de cobranca.'),
                'suspendreason' => $str('Motivo de suspensao.'),
                'notes'         => $str('Anotacoes.'),
            ), array('serviceid')));
        $t[] = array('name' => 'ModuleCreate', 'category' => $cat, 'action' => 'ModuleCreate', 'rw' => self::WRITE,
            'description' => 'Executa a criacao/provisionamento do modulo do servico. (Escrita.)',
            'inputSchema' => $obj(array('serviceid' => $int('ID do servico (accountid).')), array('serviceid')));
        $t[] = array('name' => 'ModuleSuspend', 'category' => $cat, 'action' => 'ModuleSuspend', 'rw' => self::WRITE,
            'description' => 'Suspende o servico via modulo. (Escrita.)',
            'inputSchema' => $obj(array(
                'serviceid'     => $int('ID do servico.'),
                'suspendreason' => $str('Motivo da suspensao.'),
            ), array('serviceid')));
        $t[] = array('name' => 'ModuleUnsuspend', 'category' => $cat, 'action' => 'ModuleUnsuspend', 'rw' => self::WRITE,
            'description' => 'Reativa (unsuspend) o servico via modulo. (Escrita.)',
            'inputSchema' => $obj(array('serviceid' => $int('ID do servico.')), array('serviceid')));
        $t[] = array('name' => 'ModuleTerminate', 'category' => $cat, 'action' => 'ModuleTerminate', 'rw' => self::WRITE,
            'description' => 'Encerra (termina) o servico via modulo. (Escrita - destrutivo.)',
            'inputSchema' => $obj(array('serviceid' => $int('ID do servico.')), array('serviceid')));
        $t[] = array('name' => 'ModuleChangePackage', 'category' => $cat, 'action' => 'ModuleChangePackage', 'rw' => self::WRITE,
            'description' => 'Aplica mudanca de pacote no servidor via modulo. (Escrita.)',
            'inputSchema' => $obj(array('serviceid' => $int('ID do servico.')), array('serviceid')));
        $t[] = array('name' => 'ModuleCustom', 'category' => $cat, 'action' => 'ModuleCustom', 'rw' => self::WRITE,
            'description' => 'Executa uma funcao personalizada do modulo do servico. (Escrita.)',
            'inputSchema' => $obj(array(
                'serviceid' => $int('ID do servico.'),
                'func_name' => $str('Nome da funcao custom do modulo.'),
            ), array('serviceid', 'func_name')));
        $t[] = array('name' => 'UpgradeProduct', 'category' => $cat, 'action' => 'UpgradeProduct', 'rw' => self::WRITE,
            'description' => 'Cria um upgrade/downgrade de produto ou ciclo. (Escrita.)',
            'inputSchema' => $obj(array(
                'serviceid'     => $int('ID do servico.'),
                'type'          => $str('Tipo de upgrade.', array('product', 'configoptions')),
                'newproductid'  => $int('ID do novo produto (type=product).'),
                'newproductbillingcycle' => $str('Novo ciclo.'),
                'paymentmethod' => $str('Gateway.'),
                'calconly'      => $bool('Apenas calcular (nao aplicar).'),
            ), array('serviceid', 'type')));

        // ======================================================================
        // DOMINIOS
        // ======================================================================
        $cat = 'Dominios';
        $t[] = array('name' => 'DomainGetNameservers', 'category' => $cat, 'action' => 'DomainGetNameservers', 'rw' => self::READ,
            'description' => 'Consulta os nameservers de um dominio no registrador.',
            'inputSchema' => $obj(array('domainid' => $int('ID do dominio.')), array('domainid')));
        $t[] = array('name' => 'DomainGetLockingStatus', 'category' => $cat, 'action' => 'DomainGetLockingStatus', 'rw' => self::READ,
            'description' => 'Consulta o status de bloqueio (registrar lock) de um dominio.',
            'inputSchema' => $obj(array('domainid' => $int('ID do dominio.')), array('domainid')));
        $t[] = array('name' => 'DomainGetWhoisInfo', 'category' => $cat, 'action' => 'DomainGetWhoisInfo', 'rw' => self::READ,
            'description' => 'Consulta os dados de WHOIS/contatos de um dominio.',
            'inputSchema' => $obj(array('domainid' => $int('ID do dominio.')), array('domainid')));
        $t[] = array('name' => 'DomainRegister', 'category' => $cat, 'action' => 'DomainRegister', 'rw' => self::WRITE,
            'description' => 'Registra um dominio no registrador. (Escrita.)',
            'inputSchema' => $obj(array('domainid' => $int('ID do dominio.')), array('domainid')));
        $t[] = array('name' => 'DomainRenew', 'category' => $cat, 'action' => 'DomainRenew', 'rw' => self::WRITE,
            'description' => 'Renova um dominio no registrador. (Escrita.)',
            'inputSchema' => $obj(array(
                'domainid'  => $int('ID do dominio.'),
                'regperiod' => $int('Periodo de renovacao (anos).'),
            ), array('domainid')));
        $t[] = array('name' => 'DomainTransfer', 'category' => $cat, 'action' => 'DomainTransfer', 'rw' => self::WRITE,
            'description' => 'Inicia a transferencia de um dominio. (Escrita.)',
            'inputSchema' => $obj(array(
                'domainid' => $int('ID do dominio.'),
                'eppcode'  => $str('Codigo EPP/auth.'),
            ), array('domainid')));
        $t[] = array('name' => 'DomainToggleIdProtect', 'category' => $cat, 'action' => 'DomainToggleIdProtect', 'rw' => self::WRITE,
            'description' => 'Ativa/desativa protecao de ID (WHOIS privacy). (Escrita.)',
            'inputSchema' => $obj(array(
                'domainid'     => $int('ID do dominio.'),
                'idprotection' => $bool('true para ativar, false para desativar.'),
            ), array('domainid', 'idprotection')));
        $t[] = array('name' => 'DomainUpdateNameservers', 'category' => $cat, 'action' => 'DomainUpdateNameservers', 'rw' => self::WRITE,
            'description' => 'Atualiza os nameservers de um dominio. (Escrita.)',
            'inputSchema' => $obj(array(
                'domainid' => $int('ID do dominio.'),
                'ns1' => $str('Nameserver 1.'),
                'ns2' => $str('Nameserver 2.'),
                'ns3' => $str('Nameserver 3.'),
                'ns4' => $str('Nameserver 4.'),
                'ns5' => $str('Nameserver 5.'),
            ), array('domainid', 'ns1', 'ns2')));
        $t[] = array('name' => 'DomainUpdateLockingStatus', 'category' => $cat, 'action' => 'DomainUpdateLockingStatus', 'rw' => self::WRITE,
            'description' => 'Altera o bloqueio (registrar lock) de um dominio. (Escrita.)',
            'inputSchema' => $obj(array(
                'domainid'   => $int('ID do dominio.'),
                'lockstatus' => $bool('true para bloquear.'),
            ), array('domainid', 'lockstatus')));
        $t[] = array('name' => 'UpdateClientDomain', 'category' => $cat, 'action' => 'UpdateClientDomain', 'rw' => self::WRITE,
            'description' => 'Atualiza os dados internos de um dominio do cliente. (Escrita.)',
            'inputSchema' => $obj(array(
                'domainid'    => $int('ID do dominio.'),
                'status'      => $str('Status do dominio.'),
                'nextduedate' => $str('Proximo vencimento (YYYY-MM-DD).'),
                'expirydate'  => $str('Data de expiracao (YYYY-MM-DD).'),
                'recurringamount' => $num('Valor recorrente.'),
                'dnsmanagement'   => $bool('Gerenciamento de DNS.'),
                'idprotection'    => $bool('Protecao de ID.'),
            ), array('domainid')));

        // ======================================================================
        // TICKETS / SUPORTE  (cobertura integral requerida)
        // ======================================================================
        $cat = 'Tickets';
        $t[] = array('name' => 'GetTickets', 'category' => $cat, 'action' => 'GetTickets', 'rw' => self::READ,
            'description' => 'Lista tickets de suporte com filtros.',
            'inputSchema' => $obj(array(
                'limitstart' => $int('Paginacao inicial.'),
                'limitnum'   => $int('Quantidade.'),
                'deptid'     => $int('ID do departamento.'),
                'clientid'   => $int('ID do cliente.'),
                'email'      => $str('Email do solicitante.'),
                'status'     => $str('Status do ticket (ex: Open, Answered, Closed).'),
                'subject'    => $str('Filtro por assunto.'),
                'ignore_dept_assignments' => $bool('Ignorar atribuicoes de departamento.'),
            )));
        $t[] = array('name' => 'GetTicket', 'category' => $cat, 'action' => 'GetTicket', 'rw' => self::READ,
            'description' => 'Retorna um ticket com todas as respostas e notas.',
            'inputSchema' => $obj(array(
                'ticketid'  => $int('ID do ticket.'),
                'ticketnum' => $str('Numero do ticket (alternativa).'),
                'repliessort' => $str('Ordenacao das respostas.', array('ASC', 'DESC')),
            )));
        $t[] = array('name' => 'GetTicketCounts', 'category' => $cat, 'action' => 'GetTicketCounts', 'rw' => self::READ,
            'description' => 'Retorna contadores de tickets por status/departamento.',
            'inputSchema' => $obj(array(
                'ignoreDeptAssignments' => $bool('Ignorar atribuicoes de departamento.'),
            )));
        $t[] = array('name' => 'GetTicketNotes', 'category' => $cat, 'action' => 'GetTicketNotes', 'rw' => self::READ,
            'description' => 'Lista as notas internas de um ticket.',
            'inputSchema' => $obj(array('ticketid' => $int('ID do ticket.')), array('ticketid')));
        $t[] = array('name' => 'GetSupportDepartments', 'category' => $cat, 'action' => 'GetSupportDepartments', 'rw' => self::READ,
            'description' => 'Lista os departamentos de suporte e contagens.',
            'inputSchema' => $obj(array(
                'ignore_dept_assignments' => $bool('Ignorar atribuicoes.'),
            )));
        $t[] = array('name' => 'GetSupportStatuses', 'category' => $cat, 'action' => 'GetSupportStatuses', 'rw' => self::READ,
            'description' => 'Lista os status de ticket configurados e contagens.',
            'inputSchema' => $obj(array(
                'ignore_dept_assignments' => $bool('Ignorar atribuicoes.'),
            )));
        $t[] = array('name' => 'GetPredefinedReplies', 'category' => $cat, 'action' => 'GetTicketPredefinedReplies', 'rw' => self::READ,
            'description' => 'Lista respostas predefinidas de suporte.',
            'inputSchema' => $obj(array(
                'catid'   => $int('ID da categoria.'),
                'keyword' => $str('Palavra-chave.'),
            )));
        $t[] = array('name' => 'GetPredefinedReplyCategories', 'category' => $cat, 'action' => 'GetTicketPredefinedCats', 'rw' => self::READ,
            'description' => 'Lista categorias de respostas predefinidas.',
            'inputSchema' => $obj(array()));
        $t[] = array('name' => 'OpenTicket', 'category' => $cat, 'action' => 'OpenTicket', 'rw' => self::WRITE,
            'description' => 'Abre um novo ticket de suporte. (Escrita.)',
            'inputSchema' => $obj(array(
                'deptid'    => $int('ID do departamento.'),
                'subject'   => $str('Assunto.'),
                'message'   => $str('Mensagem inicial.'),
                'clientid'  => $int('ID do cliente (se registrado).'),
                'email'     => $str('Email (se nao registrado).'),
                'name'      => $str('Nome do solicitante (se nao registrado).'),
                'priority'  => $str('Prioridade.', array('Low', 'Medium', 'High')),
                'serviceid' => $int('Servico relacionado.'),
                'admin'     => $bool('Abrir como admin.'),
                'markdown'  => $bool('Interpretar mensagem como Markdown.'),
            ), array('deptid', 'subject', 'message')));
        $t[] = array('name' => 'AddTicketReply', 'category' => $cat, 'action' => 'AddTicketReply', 'rw' => self::WRITE,
            'description' => 'Adiciona uma resposta a um ticket. (Escrita.)',
            'inputSchema' => $obj(array(
                'ticketid'      => $int('ID do ticket.'),
                'message'       => $str('Conteudo da resposta.'),
                'clientid'      => $int('Responder como cliente (ID).'),
                'adminusername' => $str('Responder como admin (usuario).'),
                'status'        => $str('Definir status apos a resposta.'),
                'noemail'       => $bool('Nao enviar email.'),
                'markdown'      => $bool('Interpretar como Markdown.'),
            ), array('ticketid', 'message')));
        $t[] = array('name' => 'UpdateTicket', 'category' => $cat, 'action' => 'UpdateTicket', 'rw' => self::WRITE,
            'description' => 'Atualiza propriedades de um ticket (status, prioridade, depto, flag). (Escrita.)',
            'inputSchema' => $obj(array(
                'ticketid' => $int('ID do ticket.'),
                'deptid'   => $int('Mover para o departamento.'),
                'subject'  => $str('Novo assunto.'),
                'priority' => $str('Prioridade.', array('Low', 'Medium', 'High')),
                'status'   => $str('Novo status.'),
                'flag'     => $int('ID do admin para sinalizar (flag).'),
                'cc'       => $str('Enderecos em copia.'),
            ), array('ticketid')));
        $t[] = array('name' => 'AddTicketNote', 'category' => $cat, 'action' => 'AddTicketNote', 'rw' => self::WRITE,
            'description' => 'Adiciona uma nota interna a um ticket. (Escrita.)',
            'inputSchema' => $obj(array(
                'ticketid' => $int('ID do ticket.'),
                'message'  => $str('Conteudo da nota.'),
                'markdown' => $bool('Interpretar como Markdown.'),
            ), array('ticketid', 'message')));
        $t[] = array('name' => 'BlockTicketSender', 'category' => $cat, 'action' => 'BlockTicketSender', 'rw' => self::WRITE,
            'description' => 'Bloqueia o remetente de um ticket. (Escrita.)',
            'inputSchema' => $obj(array(
                'ticketid'      => $int('ID do ticket.'),
                'deleteticket'  => $bool('Excluir o ticket ao bloquear.'),
            ), array('ticketid')));
        $t[] = array('name' => 'MergeTicket', 'category' => $cat, 'action' => 'MergeTicket', 'rw' => self::WRITE,
            'description' => 'Mescla tickets em um unico. (Escrita.)',
            'inputSchema' => $obj(array(
                'ticketid'       => $int('ID do ticket principal.'),
                'mergeticketids' => $str('IDs a mesclar (separados por virgula).'),
                'newsubject'     => $str('Novo assunto (opcional).'),
            ), array('ticketid', 'mergeticketids')));
        $t[] = array('name' => 'DeleteTicket', 'category' => $cat, 'action' => 'DeleteTicket', 'rw' => self::WRITE,
            'description' => 'Exclui um ticket permanentemente. (Escrita - destrutivo.)',
            'inputSchema' => $obj(array('ticketid' => $int('ID do ticket.')), array('ticketid')));
        $t[] = array('name' => 'DeleteTicketReply', 'category' => $cat, 'action' => 'DeleteTicketReply', 'rw' => self::WRITE,
            'description' => 'Exclui uma resposta de um ticket. (Escrita.)',
            'inputSchema' => $obj(array(
                'ticketid' => $int('ID do ticket.'),
                'replyid'  => $int('ID da resposta.'),
            ), array('ticketid', 'replyid')));
        $t[] = array('name' => 'AddCancelRequest', 'category' => $cat, 'action' => 'AddCancelRequest', 'rw' => self::WRITE,
            'description' => 'Registra uma solicitacao de cancelamento de servico. (Escrita.)',
            'inputSchema' => $obj(array(
                'serviceid' => $int('ID do servico.'),
                'type'      => $str('Tipo.', array('Immediate', 'End of Billing Period')),
                'reason'    => $str('Motivo do cancelamento.'),
            ), array('serviceid', 'type')));

        // ======================================================================
        // SISTEMA / ADMINISTRACAO (cobertura integral requerida)
        // ======================================================================
        $cat = 'Sistema';
        $t[] = array('name' => 'WhmcsDetails', 'category' => $cat, 'action' => 'WhmcsDetails', 'rw' => self::READ,
            'description' => 'Retorna a versao e detalhes da instalacao WHMCS.',
            'inputSchema' => $obj(array()));
        $t[] = array('name' => 'GetStats', 'category' => $cat, 'action' => 'GetStats', 'rw' => self::READ,
            'description' => 'Retorna estatisticas gerais do painel (renda, pedidos, tickets).',
            'inputSchema' => $obj(array(
                'timezone' => $str('Fuso horario (opcional).'),
            )));
        $t[] = array('name' => 'GetHealthStatus', 'category' => $cat, 'action' => 'GetHealthStatus', 'rw' => self::READ,
            'description' => 'Retorna verificacoes de saude do sistema.',
            'inputSchema' => $obj(array(
                'fetchStatus' => $bool('Executar as verificacoes de status.'),
            )));
        $t[] = array('name' => 'GetActivityLog', 'category' => $cat, 'action' => 'GetActivityLog', 'rw' => self::READ,
            'description' => 'Consulta o log de atividades do sistema.',
            'inputSchema' => $obj(array(
                'limitstart'  => $int('Paginacao inicial.'),
                'limitnum'    => $int('Quantidade.'),
                'userid'      => $int('Filtrar por cliente.'),
                'user'        => $str('Filtrar por usuario/admin.'),
                'date'        => $str('Filtrar por data (YYYY-MM-DD).'),
                'description' => $str('Filtrar por descricao.'),
            )));
        $t[] = array('name' => 'GetAdminDetails', 'category' => $cat, 'action' => 'GetAdminDetails', 'rw' => self::READ,
            'description' => 'Retorna os detalhes do admin autenticado (config).',
            'inputSchema' => $obj(array()));
        $t[] = array('name' => 'GetStaffOnline', 'category' => $cat, 'action' => 'GetStaffOnline', 'rw' => self::READ,
            'description' => 'Lista os administradores online.',
            'inputSchema' => $obj(array()));
        $t[] = array('name' => 'GetToDoItems', 'category' => $cat, 'action' => 'GetToDoItems', 'rw' => self::READ,
            'description' => 'Lista os itens da lista de tarefas (To-Do).',
            'inputSchema' => $obj(array(
                'status'     => $str('Filtrar por status.'),
                'limitstart' => $int('Paginacao inicial.'),
                'limitnum'   => $int('Quantidade.'),
            )));
        $t[] = array('name' => 'UpdateToDoItem', 'category' => $cat, 'action' => 'UpdateToDoItem', 'rw' => self::WRITE,
            'description' => 'Atualiza um item da lista de tarefas. (Escrita.)',
            'inputSchema' => $obj(array(
                'itemid'  => $int('ID do item.'),
                'status'  => $str('Novo status.', array('New', 'Pending', 'In Progress', 'Completed', 'Postponed')),
                'title'   => $str('Titulo.'),
                'notes'   => $str('Notas.'),
                'duedate' => $str('Vencimento (YYYY-MM-DD).'),
            ), array('itemid')));
        $t[] = array('name' => 'GetConfigurationValue', 'category' => $cat, 'action' => 'GetConfigurationValue', 'rw' => self::READ,
            'description' => 'Le um valor de configuracao do WHMCS (tblconfiguration).',
            'inputSchema' => $obj(array('setting' => $str('Nome da configuracao.')), array('setting')));
        $t[] = array('name' => 'SetConfigurationValue', 'category' => $cat, 'action' => 'SetConfigurationValue', 'rw' => self::WRITE,
            'description' => 'Define um valor de configuracao do WHMCS. (Escrita - avancado.)',
            'inputSchema' => $obj(array(
                'setting' => $str('Nome da configuracao.'),
                'value'   => $str('Novo valor.'),
            ), array('setting', 'value')));
        $t[] = array('name' => 'GetEmailTemplates', 'category' => $cat, 'action' => 'GetEmailTemplates', 'rw' => self::READ,
            'description' => 'Lista os modelos de email.',
            'inputSchema' => $obj(array(
                'type'     => $str('Tipo do modelo (general, product, domain, invoice, support...).'),
                'language' => $str('Idioma.'),
            )));
        $t[] = array('name' => 'GetPaymentMethods', 'category' => $cat, 'action' => 'GetPaymentMethods', 'rw' => self::READ,
            'description' => 'Lista os gateways/metodos de pagamento ativos.',
            'inputSchema' => $obj(array()));
        $t[] = array('name' => 'GetCurrencies', 'category' => $cat, 'action' => 'GetCurrencies', 'rw' => self::READ,
            'description' => 'Lista as moedas configuradas.',
            'inputSchema' => $obj(array()));
        $t[] = array('name' => 'GetServers', 'category' => $cat, 'action' => 'GetServers', 'rw' => self::READ,
            'description' => 'Lista os servidores configurados e, opcionalmente, seu status.',
            'inputSchema' => $obj(array(
                'fetchStatus' => $bool('Buscar status/estatisticas de cada servidor.'),
            )));
        $t[] = array('name' => 'GetPromotions', 'category' => $cat, 'action' => 'GetPromotions', 'rw' => self::READ,
            'description' => 'Lista as promocoes cadastradas.',
            'inputSchema' => $obj(array('code' => $str('Filtrar por codigo.'))));
        $t[] = array('name' => 'GetTLDPricing', 'category' => $cat, 'action' => 'GetTLDPricing', 'rw' => self::READ,
            'description' => 'Consulta os precos de TLDs de dominio.',
            'inputSchema' => $obj(array(
                'currencyid' => $int('ID da moeda.'),
                'clientid'   => $int('ID do cliente (precos especificos).'),
            )));
        $t[] = array('name' => 'GetModuleQueue', 'category' => $cat, 'action' => 'GetModuleQueue', 'rw' => self::READ,
            'description' => 'Lista as tarefas de modulo com falha na fila de reprocessamento.',
            'inputSchema' => $obj(array(
                'relatedId'  => $int('ID relacionado.'),
                'moduleType' => $str('Tipo de modulo.'),
            )));
        $t[] = array('name' => 'LogActivity', 'category' => $cat, 'action' => 'LogActivity', 'rw' => self::WRITE,
            'description' => 'Escreve uma entrada no log de atividades. (Escrita.)',
            'inputSchema' => $obj(array(
                'description' => $str('Texto da atividade.'),
                'userid'      => $int('Cliente relacionado (opcional).'),
            ), array('description')));
        $t[] = array('name' => 'SendEmail', 'category' => $cat, 'action' => 'SendEmail', 'rw' => self::WRITE,
            'description' => 'Envia um email a partir de um modelo para um cliente/entidade. (Escrita.)',
            'inputSchema' => $obj(array(
                'messagename' => $str('Nome do modelo de email.'),
                'id'          => $int('ID da entidade relacionada (cliente/fatura/etc).'),
                'customtype'  => $str('Tipo customizado (general).'),
                'customsubject' => $str('Assunto custom.'),
                'custommessage' => $str('Mensagem custom (HTML).'),
                'customvars'  => $str('Variaveis custom (base64 serializado).'),
            )));
        $t[] = array('name' => 'SendAdminEmail', 'category' => $cat, 'action' => 'SendAdminEmail', 'rw' => self::WRITE,
            'description' => 'Envia um email administrativo interno aos admins. (Escrita.)',
            'inputSchema' => $obj(array(
                'messagename'  => $str('Nome do modelo (deixe vazio para custom).'),
                'type'         => $str('Destinatarios.', array('system', 'account', 'support')),
                'customsubject'=> $str('Assunto custom.'),
                'custommessage'=> $str('Mensagem custom.'),
                'deptid'       => $int('Departamento (type=support).'),
            )));

        self::$tools = $t;
        return self::$tools;
    }

    /**
     * @return array|null tool definition by name
     */
    public static function get($name)
    {
        foreach (self::all() as $tool) {
            if ($tool['name'] === $name) {
                return $tool;
            }
        }
        return null;
    }

    /**
     * Default permission level for a freshly-seeded tool:
     *   read  -> "read" (informar / registrar; executa leitura)
     *   write -> "read" (visivel, porem bloqueado ate o operador liberar "act")
     *
     * No write tool is executable by default.
     *
     * @return string one of disabled|read|act
     */
    public static function defaultLevel(array $tool)
    {
        // Both classes default to "read": read tools become usable, write tools
        // become visible-but-blocked (need explicit promotion to "act").
        return 'read';
    }
}
