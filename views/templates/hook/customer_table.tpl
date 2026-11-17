{*
 * Adresy ze stanem synchronizacji kontrahenta. Odświeżana przez AJAX po akcji saveCustomer.
 *}
{if $rows}
  <div class="table-responsive">
    <table class="table table-condensed">
      <thead>
        <tr>
          <th>{l s='Adres' mod='infinitysubiekt'}</th>
          <th>{l s='Symbol w Subiekcie' mod='infinitysubiekt'}</th>
          <th>{l s='Dopasowanie' mod='infinitysubiekt'}</th>
          <th>{l s='Wysłano' mod='infinitysubiekt'}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {foreach $rows as $row}
          <tr>
            <td>
              {if $row.label}<strong>{$row.label|escape:'html':'UTF-8'}</strong><br>{/if}
              {$row.summary|escape:'html':'UTF-8'}
              <small class="text-muted">(#{$row.id_address|intval}{if $row.deleted}, {l s='usunięty' mod='infinitysubiekt'}{/if})</small>
            </td>
            <td>
              {if $row.symbol}
                <code>{$row.symbol|escape:'html':'UTF-8'}</code>
                {if $row.subiekt_id}<small class="text-muted">id {$row.subiekt_id|intval}</small>{/if}
              {else}
                <span class="text-muted">{l s='nie wysłano' mod='infinitysubiekt'}</span>
              {/if}
            </td>
            <td>{$row.matched_by|escape:'html':'UTF-8'}</td>
            <td>{$row.synced_at|escape:'html':'UTF-8'}</td>
            <td class="text-right" style="white-space:nowrap">
              {if $send_per_address}
                <button type="button" class="btn btn-default btn-sm btn-outline-secondary" data-infsubiekt-action="saveCustomer" data-infsubiekt-params="{$row.params_json|escape:'html':'UTF-8'}">
                  {l s='Wyślij do Subiekta' mod='infinitysubiekt'}
                </button>
              {/if}
              <button type="button" class="btn btn-default btn-sm btn-outline-secondary" data-infsubiekt-action="previewPayload" data-infsubiekt-params="{$row.params_json|escape:'html':'UTF-8'}">
                {l s='Podgląd danych' mod='infinitysubiekt'}
              </button>
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
{else}
  <p class="text-muted">{l s='Brak adresów.' mod='infinitysubiekt'}</p>
{/if}
