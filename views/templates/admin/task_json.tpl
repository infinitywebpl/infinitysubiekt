{*
 * Blok JSON w szczegółach zadania (task_details.tpl). Zmienne: $block (text, size_kb, truncated), $limit_kb.
 *}
{if $block.text != ''}
  {if $block.truncated}
    <p class="help-block">{l s='Pokazano pierwsze %d KB.' sprintf=[$limit_kb] mod='infinitysubiekt'}</p>
  {/if}
  <pre style="max-height:400px;overflow:auto;white-space:pre">{$block.text|escape:'html':'UTF-8'}</pre>
{else}
  <p class="text-muted">&ndash;</p>
{/if}
