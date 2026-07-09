<?php
/**
 * ImovelSite addon — English strings (mirror of portuguese-br.php).
 */

if (!defined('WHMCS')) {
    die('Access denied');
}

// ---------------------------------------------------------------------------
// Cart banner (domain selection step)
// ---------------------------------------------------------------------------
$_ADDONLANG['cart_banner_title'] = 'You already get a FREE address and e-mail!';
$_ADDONLANG['cart_banner_body'] = "When you purchase the ImovelSite plan you automatically get, completely FREE, an address in the format YOUR_NAME.<ROOT_DOMAIN> and an exclusive e-mail account in the format YOUR_NAME@<ROOT_DOMAIN>. Your ImovelSite account is provisioned INSTANTLY after payment confirmation. Even so, if you ALREADY HAVE your own domain or want to register a new one (e.g. chosenname.com.br), select one of the options below: choose 'Register a new domain' (R\$ 60.00/year), click to check availability and, once available, proceed normally — your new domain will be pointed to your ImovelSite property website.";

// ---------------------------------------------------------------------------
// Checkout validation (Site Prefix)
// ---------------------------------------------------------------------------
$_ADDONLANG['validation_prefix_missing'] = 'Please enter the Site Prefix (your address will be YOUR_NAME.<ROOT_DOMAIN>). Go back to the product configuration step and fill in the "Prefixo do Site" field.';
$_ADDONLANG['validation_prefix_invalid'] = 'Invalid Site Prefix: use only lowercase letters and numbers, 3 to 20 characters, no spaces or accents (e.g. joaoimoveis).';
$_ADDONLANG['validation_prefix_taken'] = 'The chosen prefix is already in use by another customer. Please choose another Site Prefix.';
$_ADDONLANG['validation_prefix_duplicate_cart'] = 'You used the same Site Prefix on more than one product in the cart. Choose a different prefix for each site.';

// ---------------------------------------------------------------------------
// Admin dashboard
// ---------------------------------------------------------------------------
$_ADDONLANG['dash_title'] = 'ImovelSite — Provisioning Dashboard';
$_ADDONLANG['dash_log_heading'] = 'Latest records (mod_imovelsite_log)';
$_ADDONLANG['dash_filter_status'] = 'Filter by status';
$_ADDONLANG['dash_filter_all'] = 'All';
$_ADDONLANG['dash_col_id'] = 'ID';
$_ADDONLANG['dash_col_service'] = 'Service';
$_ADDONLANG['dash_col_slug'] = 'Slug/Domain';
$_ADDONLANG['dash_col_action'] = 'Action';
$_ADDONLANG['dash_col_status'] = 'Status';
$_ADDONLANG['dash_col_job'] = 'Job';
$_ADDONLANG['dash_col_attempts'] = 'Attempts';
$_ADDONLANG['dash_col_created'] = 'Created at';
$_ADDONLANG['dash_col_updated'] = 'Updated at';
$_ADDONLANG['dash_col_actions'] = 'Actions';
$_ADDONLANG['dash_btn_reprocess'] = 'Reprocess';
$_ADDONLANG['dash_btn_mark_mapped'] = 'Mark mapped';
$_ADDONLANG['dash_btn_restore_email'] = 'Restore e-mail template backup';
$_ADDONLANG['dash_confirm_restore_email'] = 'Restore the e-mail template from the backup taken at activation? The current template content will be replaced.';
$_ADDONLANG['dash_confirm_reprocess'] = 'Call ModuleCreate again for this service?';
$_ADDONLANG['dash_domains_heading'] = 'Domains awaiting manual registration';
$_ADDONLANG['dash_domains_empty'] = 'No domains awaiting manual registration.';
$_ADDONLANG['dash_log_empty'] = 'No records found.';
$_ADDONLANG['dash_msg_reprocess_ok'] = 'ModuleCreate executed successfully for service #%s.';
$_ADDONLANG['dash_msg_reprocess_fail'] = 'ModuleCreate failed for service #%s: %s';
$_ADDONLANG['dash_msg_mapped_ok'] = 'Log record #%s marked as mapped.';
$_ADDONLANG['dash_msg_restore_ok'] = 'E-mail template restored from backup (log #%s).';
$_ADDONLANG['dash_msg_restore_none'] = 'No e-mail template backup found in the log.';
$_ADDONLANG['dash_msg_row_missing'] = 'Log record not found.';
$_ADDONLANG['dash_email_section'] = 'Welcome e-mail template';
$_ADDONLANG['dash_email_hint'] = 'The button below restores the welcome template subject and body from the backup saved when the addon was activated (action=email_backup log row).';

// ---------------------------------------------------------------------------
// Manual domain registration tasks / notifications
// ---------------------------------------------------------------------------
$_ADDONLANG['todo_register_domain_title'] = 'Register domain %s (ImovelSite)';
$_ADDONLANG['todo_register_domain_desc'] = "Client #%d (%s) bought the domain %s together with the ImovelSite plan (order #%d).\nRegister/transfer the domain manually and point it to the client site.\nOrder: %s";
$_ADDONLANG['notify_domain_subject'] = '[ImovelSite] Register domain %s (order #%d)';
$_ADDONLANG['notify_domain_body'] = "Order #%d includes the ImovelSite product and the domain %s (%s).\n\nDomain registration is MANUAL: register/transfer the domain and then use the ImovelSite addon dashboard (Configuration > Addons > ImovelSite) to mark the mapping.\n\nClient: #%d %s\nOrder link: %s";
