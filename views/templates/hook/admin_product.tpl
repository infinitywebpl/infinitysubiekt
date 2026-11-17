{*
 * Karta produktu: kartoteka towaru, stan i cena zakupu. $bs4 = karta na Symfony (PS 1.7+).
 *}
<div class="{if $bs4}card{else}panel product-tab{/if}" data-infsubiekt-panel data-ajax-url="{$ajax_url|escape:'html':'UTF-8'}" data-infsubiekt-params="{$params_json|escape:'html':'UTF-8'}">
  {if $bs4}
    <h3 class="card-header"><i class="material-icons">sync</i> {l s='Subiekt GT' mod='infinitysubiekt'}</h3>
    <div class="card-body">
  {else}
    <h3><i class="icon-exchange"></i> {l s='Subiekt GT' mod='infinitysubiekt'}</h3>
  {/if}

  {if !$id_product}
    <div class="alert alert-info">{l s='Zapisz produkt, żeby wysłać go do Subiekta.' mod='infinitysubiekt'}</div>
  {else}
    <div data-infsubiekt-table>
      {$table_html nofilter}
    </div>

    <button type="button" class="btn btn-default btn-outline-secondary" data-infsubiekt-action="saveProduct">
      {l s='Wyślij kartotekę (wszystkie pozycje)' mod='infinitysubiekt'}
    </button>
    <button type="button" class="btn btn-default btn-outline-secondary" data-infsubiekt-action="pullStock">
      {l s='Pobierz stan (wszystkie pozycje)' mod='infinitysubiekt'}
    </button>
    <button type="button" class="btn btn-default btn-outline-secondary" data-infsubiekt-action="pullPrice">
      {l s='Pobierz cenę zakupu (wszystkie pozycje)' mod='infinitysubiekt'}
    </button>
    <pre data-infsubiekt-result style="display:none;margin-top:10px;white-space:pre-wrap"></pre>
  {/if}

  {if $bs4}
    </div>
  {/if}
</div>
