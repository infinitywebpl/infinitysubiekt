{*
 * Mapa płatności (panel „Dokument sprzedaży”): moduł płatności sklepu → forma płatności w Subiekcie.
 * Selecty wysyłają infsubiekt_payment[moduł]; zapis składa z nich tekst INFSUBIEKT_PAYMENT_MAP.
 * „Pobierz formy płatności z Subiekta” (views/js/settings.js) podmienia opcje selectów bez przeładowania.
 * Zmienne: $rows (module, label, value; tylko aktywne moduły), $hidden (moduł => wartość: wpisy mapy nieaktywnych modułów), $options (value, label), $fetched_at, $forms_count, $field.
 *}
<div class="infsubiekt-payment-map" data-infsubiekt-payment-map>
  {foreach $hidden as $module => $value}
    <input type="hidden" name="{$field|escape:'html':'UTF-8'}[{$module|escape:'html':'UTF-8'}]" value="{$value|escape:'html':'UTF-8'}">
  {/foreach}
  <table class="table table-condensed">
    <thead>
      <tr>
        <th>{l s='Moduł płatności w sklepie' mod='infinitysubiekt'}</th>
        <th>{l s='Forma płatności w Subiekcie' mod='infinitysubiekt'}</th>
      </tr>
    </thead>
    <tbody>
      {foreach $rows as $row}
        <tr>
          <td>{$row.label|escape:'html':'UTF-8'}</td>
          <td>
            <select name="{$field|escape:'html':'UTF-8'}[{$row.module|escape:'html':'UTF-8'}]" class="form-control" data-infsubiekt-payment-select>
              {foreach $options as $option}
                <option value="{$option.value|escape:'html':'UTF-8'}"{if $option.value == $row.value} selected="selected"{/if}>{$option.label|escape:'html':'UTF-8'}</option>
              {/foreach}
            </select>
          </td>
        </tr>
      {foreachelse}
        <tr><td colspan="2">{l s='Brak aktywnych modułów płatności.' mod='infinitysubiekt'}</td></tr>
      {/foreach}
    </tbody>
  </table>

  <p>
    <button type="button" class="btn btn-default" data-infsubiekt-payment-fetch>
      <i class="icon-refresh"></i> {l s='Pobierz formy płatności z Subiekta' mod='infinitysubiekt'}
    </button>
    <span class="help-block" style="display: inline-block; margin-left: 10px;" data-infsubiekt-payment-status data-label-fetched="{l s='Lista z Subiekta pobrana:' mod='infinitysubiekt'}" data-label-forms="{l s='form' mod='infinitysubiekt'}">
      {if $fetched_at}
        {l s='Lista z Subiekta pobrana:' mod='infinitysubiekt'} {$fetched_at|escape:'html':'UTF-8'} ({$forms_count|intval} {l s='form' mod='infinitysubiekt'})
      {else}
        {l s='Lista z Subiekta nie była pobierana: karta i kredyt kupiecki tylko w wariancie domyślnym.' mod='infinitysubiekt'}
      {/if}
    </span>
  </p>
  <p class="help-block">
    {l s='Moduł z formą domyślną nie wysyła formy płatności, Subiekt bierze swoją domyślną. „Zapłacono przelewem” oznacza dokument już rozliczony: nie dla przelewu, na który się czeka (wtedy kredyt kupiecki z terminem). Rodzaj karty wymaga agenta 0.4.0. Pobranie listy nie zmienia mapy, zapisz ustawienia.' mod='infinitysubiekt'}
  </p>
</div>
