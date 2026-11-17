{*
 * Pozycje produktu (kombinacje albo sam produkt) ze stanem synchronizacji. Odświeżana przez AJAX.
 *}
{if $advanced_stock}
  <div class="alert alert-warning">
    {if $can_pull}
      {l s='Produkt korzysta z zaawansowanego zarządzania magazynem. Pobranie zapisuje stan w magazynie sklepu wybranym w konfiguracji modułu. Wysyłanie stanu do Subiekta jest wyłączone.' mod='infinitysubiekt'}
    {else}
      {l s='Produkt korzysta z zaawansowanego zarządzania magazynem. Żeby pobierać stan, wybierz magazyn sklepu w konfiguracji modułu. Wysyłanie stanu do Subiekta jest wyłączone.' mod='infinitysubiekt'}
    {/if}
  </div>
{/if}
{if $rows}
  <div class="table-responsive">
    <table class="table table-condensed">
      <thead>
        <tr>
          <th>{l s='Pozycja' mod='infinitysubiekt'}</th>
          <th>{l s='Kod w Subiekcie' mod='infinitysubiekt'}</th>
          <th>{l s='Stan w sklepie' mod='infinitysubiekt'}</th>
          <th>{l s='Cena zakupu netto' mod='infinitysubiekt'}</th>
          <th>{l s='Kartoteka wysłana' mod='infinitysubiekt'}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {foreach $rows as $row}
          <tr>
            <td>{$row.label|escape:'html':'UTF-8'}</td>
            <td>
              {if $row.code}
                <code>{$row.code|escape:'html':'UTF-8'}</code>
              {else}
                <span class="text-danger">{l s='brak referencji' mod='infinitysubiekt'}</span>
              {/if}
              {if $row.ean}<br><small class="text-muted">EAN {$row.ean|escape:'html':'UTF-8'}</small>{/if}
            </td>
            <td>{$row.quantity|intval}</td>
            <td>{$row.purchase_price|string_format:'%.2f'}</td>
            <td>
              {if $row.synced_at}
                {$row.synced_at|escape:'html':'UTF-8'}
                {if $row.subiekt_id}<small class="text-muted">id {$row.subiekt_id|intval}</small>{/if}
              {else}
                <span class="text-muted">{l s='nie wysłano' mod='infinitysubiekt'}</span>
              {/if}
            </td>
            <td class="text-right" style="white-space:nowrap">
              {if $row.code || $row.ean}
                <button type="button" class="btn btn-default btn-sm btn-outline-secondary" data-infsubiekt-action="saveProduct" data-infsubiekt-params="{$row.params_json|escape:'html':'UTF-8'}">
                  {l s='Wyślij kartotekę' mod='infinitysubiekt'}
                </button>
                {if $can_pull}
                  <button type="button" class="btn btn-default btn-sm btn-outline-secondary" data-infsubiekt-action="pullStock" data-infsubiekt-params="{$row.params_json|escape:'html':'UTF-8'}">
                    {l s='Pobierz stan' mod='infinitysubiekt'}
                  </button>
                {/if}
                <button type="button" class="btn btn-default btn-sm btn-outline-secondary" data-infsubiekt-action="pullPrice" data-infsubiekt-params="{$row.params_json|escape:'html':'UTF-8'}">
                  {l s='Pobierz cenę zakupu' mod='infinitysubiekt'}
                </button>
                {if !$advanced_stock}
                  <button type="button" class="btn btn-default btn-sm btn-outline-secondary" data-infsubiekt-action="pushStock" data-infsubiekt-params="{$row.params_json|escape:'html':'UTF-8'}" data-infsubiekt-confirm="{l s='Wysłanie stanu utworzy w Subiekcie dokument PW lub RW na różnicę. Kontynuować?' mod='infinitysubiekt'}">
                    {l s='Wyślij stan do Subiekta' mod='infinitysubiekt'}
                  </button>
                {/if}
                <button type="button" class="btn btn-default btn-sm btn-outline-secondary" data-infsubiekt-action="previewPayload" data-infsubiekt-params="{$row.preview_json|escape:'html':'UTF-8'}">
                  {l s='Podgląd danych' mod='infinitysubiekt'}
                </button>
              {/if}
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
{else}
  <p class="text-muted">{l s='Brak pozycji.' mod='infinitysubiekt'}</p>
{/if}
