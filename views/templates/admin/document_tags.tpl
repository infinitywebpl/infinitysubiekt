{*
 * Pomoc do szablonów tekstów dokumentu (panel „Teksty dokumentu”): tabela tagów, klik wstawia tag
 * w miejscu kursora w ostatnio wybranym polu (views/js/settings.js).
 * Zmienne: $tags (code, label), $fields (id pól szablonów po przecinku; pierwsze = domyślne), $subtitle_length, $original_length.
 *}
{literal}<style>
  .infsubiekt-tags { margin-bottom: 0; }
  .infsubiekt-tags .infsubiekt-tag { cursor: pointer; white-space: nowrap; }
  .infsubiekt-tags .infsubiekt-tag:hover { background: #d9edf7; }
  .infsubiekt-tags td.infsubiekt-tag-cell { width: 1%; }
  .infsubiekt-tags ul { padding-left: 18px; margin-bottom: 0; }
</style>{/literal}

<div class="infsubiekt-tags panel" data-infsubiekt-tag-fields="{$fields|escape:'html':'UTF-8'}">
  <div class="panel-heading"><i class="icon-book"></i> {l s='Tagi' mod='infinitysubiekt'}</div>
  <p class="help-block">{l s='Kliknij tag, aby wstawić go w miejscu kursora w ostatnio wybranym polu.' mod='infinitysubiekt'}</p>

  <table class="table table-condensed">
    <tbody>
      {foreach $tags as $tag}
        <tr>
          <td class="infsubiekt-tag-cell"><code class="infsubiekt-tag" data-infsubiekt-tag="{$tag.code|escape:'html':'UTF-8'}">{$tag.code|escape:'html':'UTF-8'}</code></td>
          <td>{$tag.label|escape:'html':'UTF-8'}</td>
        </tr>
      {/foreach}
    </tbody>
  </table>

  <ul class="help-block">
    <li>{l s='Kwoty: brutto, dwa miejsca po przecinku dziesiętnym, bez waluty (waluta osobno). Data: dd.mm.rrrr.' mod='infinitysubiekt'}</li>
    <li>{l s='Podtytuł z' mod='infinitysubiekt'} <code>{literal}{id_order}{/literal}</code> {l s='albo' mod='infinitysubiekt'} <code>{literal}{reference}{/literal}</code> {l s='w pierwszych' mod='infinitysubiekt'} {$subtitle_length|intval} {l s='znakach: po awarii agent rozpozna wystawiony już dokument i zadanie powtórzy się bezpiecznie. Bez tego takie zadanie kończy się błędem i trzeba sprawdzić w Subiekcie, czy dokument powstał.' mod='infinitysubiekt'}</li>
    <li>{l s='Numer oryginału: najwyżej' mod='infinitysubiekt'} {$original_length|intval} {l s='znaków, dłuższy tekst agent obcina.' mod='infinitysubiekt'}</li>
    <li>{l s='Puste pole = pole w Subiekcie zostaje puste.' mod='infinitysubiekt'}</li>
  </ul>
</div>
