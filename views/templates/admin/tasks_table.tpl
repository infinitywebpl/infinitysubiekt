{*
 * Tabela zadań kolejki.
 *}
{if $tasks}
  <div class="table-responsive">
    <table class="table table-condensed">
      <thead>
        <tr>
          <th>{l s='ID' mod='infinitysubiekt'}</th>
          <th>{l s='Metoda' mod='infinitysubiekt'}</th>
          <th>{l s='Status' mod='infinitysubiekt'}</th>
          <th>{l s='Zamówienie' mod='infinitysubiekt'}</th>
          <th>{l s='Priorytet' mod='infinitysubiekt'}</th>
          <th>{l s='Próby' mod='infinitysubiekt'}</th>
          <th>{l s='Utworzone' mod='infinitysubiekt'}</th>
          <th>{l s='Zaktualizowane' mod='infinitysubiekt'}</th>
          <th title="{l s='Od pobrania zadania przez agenta do zapisu wyniku' mod='infinitysubiekt'}">{l s='Czas wykonania' mod='infinitysubiekt'}</th>
          <th>{l s='Błąd' mod='infinitysubiekt'}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {foreach $tasks as $task}
          <tr data-infsubiekt-task-id="{$task.id|intval}" style="cursor:pointer" title="{l s='Kliknij, aby zobaczyć szczegóły' mod='infinitysubiekt'}">
            <td>{$task.id|intval}</td>
            <td><code>{$task.method|escape:'html':'UTF-8'}</code></td>
            <td>
              {if $task.status == 'done'}
                <span class="label label-success">{$task.status|escape:'html':'UTF-8'}</span>
              {elseif $task.status == 'failed'}
                <span class="label label-danger">{$task.status|escape:'html':'UTF-8'}</span>
              {elseif $task.status == 'processing'}
                <span class="label label-info">{$task.status|escape:'html':'UTF-8'}</span>
              {elseif $task.status == 'pending'}
                <span class="label label-warning">{$task.status|escape:'html':'UTF-8'}</span>
              {else}
                <span class="label label-default">{$task.status|escape:'html':'UTF-8'}</span>
              {/if}
            </td>
            <td>
              {if $task.order_url}
                <a href="{$task.order_url|escape:'html':'UTF-8'}">{$task.order_id|intval}</a>
              {else}
                &ndash;
              {/if}
            </td>
            <td>{$task.priority|intval}</td>
            <td>{$task.attempts|intval}</td>
            <td>{$task.created_at|escape:'html':'UTF-8'}</td>
            <td>{$task.updated_at|escape:'html':'UTF-8'}</td>
            <td>{if $task.duration}{$task.duration|escape:'html':'UTF-8'}{else}&ndash;{/if}</td>
            <td>{$task.error|escape:'html':'UTF-8'}</td>
            <td class="text-right">
              {if $task.status == 'pending' || $task.status == 'processing'}
                <button type="button" class="btn btn-default btn-xs btn-sm btn-outline-secondary" data-infsubiekt-action="cancelTask" data-infsubiekt-params="{ldelim}&quot;id_task&quot;:{$task.id|intval}{rdelim}" data-infsubiekt-confirm="{l s='Anulować zadanie? Nie wróci już do kolejki. Agent, który je wykonuje, nie zostanie zatrzymany, więc dokument mógł już powstać: sprawdź to w Subiekcie, zanim wystawisz ponownie.' mod='infinitysubiekt'}">
                  {l s='Anuluj' mod='infinitysubiekt'}
                </button>
              {/if}
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
{else}
  <p class="text-muted">{l s='Brak zadań.' mod='infinitysubiekt'}</p>
{/if}
