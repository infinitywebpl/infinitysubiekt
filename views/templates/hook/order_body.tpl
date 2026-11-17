{*
 * Treść panelu zamówienia: dokument, kontrahenci, zadania zamówienia.
 *}
<h4>{l s='Dokument' mod='infinitysubiekt'}</h4>
{if $number}
  <p>
    <strong>{$number|escape:'html':'UTF-8'}</strong>
    {if $document_type}({$document_type|escape:'html':'UTF-8'}){/if}
    {if $issued_at}<span class="text-muted">{l s='zapisano' mod='infinitysubiekt'} {$issued_at|escape:'html':'UTF-8'}</span>{/if}
  </p>
  <p>
    <a class="btn btn-default btn-outline-secondary" href="{$pdf_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">
      {l s='Pobierz PDF' mod='infinitysubiekt'}
    </a>
    <button type="button" class="btn btn-default btn-outline-secondary" data-infsubiekt-action="previewPayload" data-infsubiekt-params="{$preview_json|escape:'html':'UTF-8'}" title="{l s='Dane zbudowane z obecnego stanu zamówienia i konfiguracji, nie zapis tego, co wysłano przy wystawieniu.' mod='infinitysubiekt'}">
      {l s='Podgląd danych dokumentu' mod='infinitysubiekt'}
    </button>
    <span class="help-block text-muted" style="display:inline">
      {l s='Zawsze aktualna wersja z Subiekta (do 60 s).' mod='infinitysubiekt'}
      {if $pdf_at}{l s='Ostatnio pobrano:' mod='infinitysubiekt'} {$pdf_at|escape:'html':'UTF-8'}{/if}
    </span>
  </p>
{else}
  <p>
    {l s='Nie wystawiono.' mod='infinitysubiekt'}
    {l s='Rodzaj według konfiguracji:' mod='infinitysubiekt'} <strong>{$configured_type|escape:'html':'UTF-8'}</strong>.
  </p>
  {if $active_task_id}
    <div class="alert alert-info">
      {l s='Dokument jest w trakcie wystawiania, zadanie' mod='infinitysubiekt'} #{$active_task_id|intval}.
      {l s='Kliknij „Wystaw” ponownie, gdy agent skończy: numer zostanie przejęty.' mod='infinitysubiekt'}
    </div>
  {/if}
  <p>
    <button type="button" class="btn btn-primary" data-infsubiekt-action="makeInvoice" data-infsubiekt-confirm="{$confirm_text|escape:'html':'UTF-8'}">
      {l s='Wystaw' mod='infinitysubiekt'}
    </button>
    <button type="button" class="btn btn-default btn-outline-secondary" data-infsubiekt-action="previewPayload" data-infsubiekt-params="{$preview_json|escape:'html':'UTF-8'}">
      {l s='Podgląd danych dokumentu' mod='infinitysubiekt'}
    </button>
    <button type="button" class="btn btn-default btn-outline-secondary" data-infsubiekt-action="findDocument" title="{l s='Szuka w Subiekcie dokumentu tego rodzaju wystawionego dla zamówienia wcześniej, np. przed uruchomieniem modułu. Niczego nie zapisuje.' mod='infinitysubiekt'}">
      {l s='Dopasuj w Subiekcie' mod='infinitysubiekt'}
    </button>
  </p>
  {if $match}
    <p class="text-muted">
      {l s='Dokumenty z Subiekta wystawione od' mod='infinitysubiekt'} {$match.date_from|escape:'html':'UTF-8'}
      {l s='do' mod='infinitysubiekt'} {$match.date_to|escape:'html':'UTF-8'}.
      {l s='Przypisz tylko dokument, który na pewno dotyczy tego zamówienia.' mod='infinitysubiekt'}
    </p>
    {if $match.rows}
      <table class="table">
        <thead>
          <tr>
            <th>{l s='Dokument' mod='infinitysubiekt'}</th>
            <th>{l s='Nabywca' mod='infinitysubiekt'}</th>
            <th>{l s='Zgodność z zamówieniem' mod='infinitysubiekt'}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {foreach $match.rows as $row}
            <tr{if $row.likely} class="success table-success"{/if}>
              <td>
                <strong>{$row.number|escape:'html':'UTF-8'}</strong><br>
                {$row.date|escape:'html':'UTF-8'}{if $row.total !== ''}, {$row.total|escape:'html':'UTF-8'} {l s='brutto' mod='infinitysubiekt'}{/if}
                {foreach $row.texts as $text}
                  <br><span class="text-muted">{$text.label|escape:'html':'UTF-8'}:</span> {$text.text|escape:'html':'UTF-8'}
                {/foreach}
              </td>
              <td>{$row.buyer|escape:'html':'UTF-8'}</td>
              <td>
                {if $row.error}
                  <span class="text-danger">{l s='Nie wczytano dokumentu:' mod='infinitysubiekt'} {$row.error|escape:'html':'UTF-8'}</span>
                {else}
                  {foreach $row.checks as $check}
                    <span class="{if $check.ok === true}text-success{elseif $check.ok === false}text-danger{else}text-muted{/if}">
                      {if $check.ok === true}&#10004;{elseif $check.ok === false}&#10008;{else}&ndash;{/if}
                      {$check.label|escape:'html':'UTF-8'}
                    </span><br>
                  {/foreach}
                {/if}
              </td>
              <td>
                {if $row.taken_by}
                  <span class="text-muted">{l s='Przypisany do zamówienia' mod='infinitysubiekt'} #{$row.taken_by|intval}</span>
                {else}
                  <button type="button" class="btn {if $row.likely}btn-primary{else}btn-default btn-outline-secondary{/if}" data-infsubiekt-action="assignDocument" data-infsubiekt-params="{$row.params_json|escape:'html':'UTF-8'}" data-infsubiekt-confirm="{$row.confirm_text|escape:'html':'UTF-8'}">
                    {l s='Przypisz' mod='infinitysubiekt'}
                  </button>
                {/if}
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {/if}
  {/if}
{/if}

<h4>{l s='Kontrahenci' mod='infinitysubiekt'}</h4>
{include file='./customer_notice.tpl'}
{$customers_html nofilter}
<p>
  <button type="button" class="btn btn-default btn-outline-secondary" data-infsubiekt-action="saveCustomer">
    {l s='Synchronizuj kontrahenta' mod='infinitysubiekt'}
  </button>
</p>

<h4>{l s='Zadania zamówienia' mod='infinitysubiekt'}</h4>
{$tasks_html nofilter}
