<div data-infsubiekt-feature-fetch-box>
  <button type="button" class="btn btn-default" data-infsubiekt-feature-fetch>
    <i class="icon-refresh"></i> {l s='Pobierz cechy z Subiekta' mod='infinitysubiekt'}
  </button>
  <span class="help-block" style="display: inline-block; margin-left: 10px;" data-infsubiekt-feature-status data-label-fetched="{l s='Lista z Subiekta pobrana:' mod='infinitysubiekt'}" data-label-features="{l s='cech' mod='infinitysubiekt'}">
    {if $fetched_at}
      {l s='Lista z Subiekta pobrana:' mod='infinitysubiekt'} {$fetched_at|escape:'html':'UTF-8'} ({$count|intval} {l s='cech' mod='infinitysubiekt'})
    {else}
      {l s='Lista cech nie była pobierana.' mod='infinitysubiekt'}
    {/if}
  </span>
</div>
