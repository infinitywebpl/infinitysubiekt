{*
 * Szczegóły zadania kolejki w popupie (akcja taskDetails, views/js/tasks.js).
 * Zmienne: $task (wiersz tabeli), $order_url, $wait, $duration, $params i $result (text, size_kb, truncated), $limit_kb.
 *}
<h4 style="margin-top:0">
  {l s='Zadanie' mod='infinitysubiekt'} #{$task.id|intval}
  <code>{$task.method|escape:'html':'UTF-8'}</code>
  <small>{$task.status|escape:'html':'UTF-8'}</small>
</h4>

<table class="table table-condensed" style="width:auto">
  <tbody>
    <tr>
      <th>{l s='Zamówienie' mod='infinitysubiekt'}</th>
      <td>
        {if $order_url}
          <a href="{$order_url|escape:'html':'UTF-8'}">{$task.order_id|intval}</a>
        {else}
          &ndash;
        {/if}
      </td>
    </tr>
    <tr><th>{l s='Priorytet' mod='infinitysubiekt'}</th><td>{$task.priority|intval}</td></tr>
    <tr><th>{l s='Próby' mod='infinitysubiekt'}</th><td>{$task.attempts|intval}</td></tr>
    <tr><th>{l s='Utworzone' mod='infinitysubiekt'}</th><td>{$task.created_at|escape:'html':'UTF-8'}</td></tr>
    <tr>
      <th>{l s='Pobrane przez agenta' mod='infinitysubiekt'}</th>
      <td>{if $task.locked_at}{$task.locked_at|escape:'html':'UTF-8'}{else}&ndash;{/if}</td>
    </tr>
    <tr><th>{l s='Zaktualizowane' mod='infinitysubiekt'}</th><td>{$task.updated_at|escape:'html':'UTF-8'}</td></tr>
    <tr>
      <th>{l s='Ważne do' mod='infinitysubiekt'}</th>
      <td>{if $task.expires_at}{$task.expires_at|escape:'html':'UTF-8'}{else}&ndash;{/if}</td>
    </tr>
    <tr>
      <th>{l s='Oczekiwanie w kolejce' mod='infinitysubiekt'}</th>
      <td>{if $wait}{$wait|escape:'html':'UTF-8'}{else}&ndash;{/if}</td>
    </tr>
    <tr>
      <th>{l s='Czas wykonania' mod='infinitysubiekt'}</th>
      <td>{if $duration}{$duration|escape:'html':'UTF-8'}{else}&ndash;{/if}</td>
    </tr>
  </tbody>
</table>
{if $task.attempts > 1}
  <p class="help-block">{l s='Czasy dotyczą ostatniej próby.' mod='infinitysubiekt'}</p>
{/if}

{if $task.error}
  <h4>{l s='Błąd' mod='infinitysubiekt'}</h4>
  <pre style="white-space:pre-wrap;color:#c00">{$task.error|escape:'html':'UTF-8'}</pre>
{/if}

<h4>
  {l s='Parametry' mod='infinitysubiekt'}
  {if $params.size_kb}<small>{$params.size_kb|intval} KB</small>{/if}
</h4>
{include file='./task_json.tpl' block=$params limit_kb=$limit_kb}

<h4>
  {l s='Wynik' mod='infinitysubiekt'}
  {if $result.size_kb}<small>{$result.size_kb|intval} KB</small>{/if}
</h4>
{include file='./task_json.tpl' block=$result limit_kb=$limit_kb}
