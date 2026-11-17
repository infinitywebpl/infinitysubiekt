{*
 * Karta klienta: adresy jako kontrahenci Subiekta. $bs4 = karta na Symfony (PS 1.7.6+).
 *}
 <div class="col-lg-12">
<div class="{if $bs4}card{else}panel{/if}" data-infsubiekt-panel data-ajax-url="{$ajax_url|escape:'html':'UTF-8'}" data-infsubiekt-params="{$params_json|escape:'html':'UTF-8'}">
  {if $bs4}
    <h3 class="card-header"><i class="material-icons">sync</i> {l s='Subiekt GT - kontrahenci' mod='infinitysubiekt'}</h3>
    <div class="card-body">
  {else}
    <div class="panel-heading"><i class="icon-exchange"></i> {l s='Subiekt GT - kontrahenci' mod='infinitysubiekt'}</div>
  {/if}

  {include file='./customer_notice.tpl'}

  <div data-infsubiekt-table>
    {$table_html nofilter}
  </div>

  <button type="button" class="btn btn-default btn-outline-secondary" data-infsubiekt-action="saveCustomer">
    {l s='Wyślij wszystkie adresy' mod='infinitysubiekt'}
  </button>
  <pre data-infsubiekt-result style="display:none;margin-top:10px;white-space:pre-wrap"></pre>

  {if $bs4}
    </div>
  {/if}
</div>
</div> 