{*
 * Karta zamówienia: dokument, kontrahenci i zadania. $bs4 = karta na Symfony (PS 1.7.7+).
 * Treść ($body_html, order_body.tpl) jest odświeżana w całości po akcjach AJAX.
 *}
<div class="{if $bs4}card mt-2{else}panel{/if}" data-infsubiekt-panel data-ajax-url="{$ajax_url|escape:'html':'UTF-8'}" data-infsubiekt-params="{$params_json|escape:'html':'UTF-8'}">
  {if $bs4}
    <h3 class="card-header"><i class="material-icons">sync</i> {l s='Subiekt GT' mod='infinitysubiekt'}</h3>
    <div class="card-body">
  {else}
    <div class="panel-heading"><i class="icon-exchange"></i> {l s='Subiekt GT' mod='infinitysubiekt'}</div>
  {/if}

  <pre data-infsubiekt-result style="display:none;margin-bottom:10px;white-space:pre-wrap"></pre>

  <div data-infsubiekt-table>
    {$body_html nofilter}
  </div>

  {if $bs4}
    </div>
  {/if}
</div>
