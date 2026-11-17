{*
 * Panel listy zadań kolejki z filtrem statusu. Tabela odświeżana przez AJAX (akcja tasks).
 *}
<div class="panel">
  <div class="panel-heading">
    <i class="icon-tasks"></i> {l s='Kolejka zadań' mod='infinitysubiekt'}
    <small>({l s='ostatnie' mod='infinitysubiekt'} {$limit|intval})</small>
  </div>

  <div class="form-inline" style="margin-bottom:10px">
    <select class="form-control" data-infsubiekt-tasks-status>
      <option value="">{l s='wszystkie statusy' mod='infinitysubiekt'}</option>
      {foreach $statuses as $status}
        <option value="{$status|escape:'html':'UTF-8'}">{$status|escape:'html':'UTF-8'}</option>
      {/foreach}
    </select>
    <button type="button" class="btn btn-default" data-infsubiekt-tasks-refresh>
      <i class="icon-refresh"></i> {l s='Odśwież' mod='infinitysubiekt'}
    </button>
  </div>

  <div data-infsubiekt-tasks-table>
    {$table_html nofilter}
  </div>
</div>

<div class="panel">
  <div class="panel-heading">
    <i class="icon-time"></i> {l s='Czas wykonania według metody' mod='infinitysubiekt'}
  </div>
  <p class="help-block">{l s='Zadania zakończone sukcesem (done) zachowane w kolejce. Czas od pobrania zadania przez agenta do zapisu wyniku, z dokładnością do sekundy, bez oczekiwania w kolejce.' mod='infinitysubiekt'}</p>
  {if $stats}
    <div class="table-responsive">
      <table class="table table-condensed">
        <thead>
          <tr>
            <th>{l s='Metoda' mod='infinitysubiekt'}</th>
            <th class="text-right">{l s='Zadań' mod='infinitysubiekt'}</th>
            <th class="text-right">{l s='Min' mod='infinitysubiekt'}</th>
            <th class="text-right">{l s='Średnio' mod='infinitysubiekt'}</th>
            <th class="text-right">{l s='Max' mod='infinitysubiekt'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach $stats as $row}
            <tr>
              <td><code>{$row.method|escape:'html':'UTF-8'}</code></td>
              <td class="text-right">{$row.count|intval}</td>
              <td class="text-right">{$row.min|escape:'html':'UTF-8'}</td>
              <td class="text-right">{$row.avg|escape:'html':'UTF-8'}</td>
              <td class="text-right">{$row.max|escape:'html':'UTF-8'}</td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  {else}
    <p class="text-muted">{l s='Brak zakończonych zadań.' mod='infinitysubiekt'}</p>
  {/if}
</div>
