{* ImovelSite — banner injetado ACIMA das opções de domínio no carrinho.
   Usado por hooks.php via file_get_contents + str_replace dos placeholders
   %%TITLE%% e %%BODY%% (já escapados). Estilos inline, neutros ao tema. *}
<div id="imovelsite-cart-banner" class="imovelsite-cart-banner" style="background:#f0f7e8;border:1px solid #88c354;border-radius:8px;padding:18px 22px;margin:0 0 22px;color:#333;font-family:inherit;">
    <div class="imovelsite-cart-banner-title" style="font-size:18px;font-weight:bold;color:#33475e;margin:0 0 8px;">
        %%TITLE%%
    </div>
    <div class="imovelsite-cart-banner-body" style="font-size:14px;line-height:1.6;">
        %%BODY%%
    </div>
</div>
