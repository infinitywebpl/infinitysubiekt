{*
 * Zakładka „Wiedza dla AI”: notatki dla narzędzia MCP subiekt_query (InfinitySubiektNotesPage).
 * Zmienne: $notes (wiersze z edit_url), $form (id_note, title, content, active), $action_url, $new_url,
 * $submit_save, $submit_delete, $max_title, $max_content, $max_for_model, $source_ai.
 *}
<div class="panel">
  <div class="panel-heading">
    <i class="icon-lightbulb"></i> {l s='Wiedza dla AI o tym, jak firma prowadzi Subiekta' mod='infinitysubiekt'}
  </div>
  <p class="help-block">
    {l s='Czat AI odpytuje Subiekta narzędziem subiekt_query. Narzędzie zna Subiekta GT ogólnie, a te notatki mówią mu, jak dokumenty prowadzi ta firma, np. które dokumenty niosą ilości zakupu, które pola kart towarów są wypełnione albo co oznaczają magazyny i grupy towarów.' mod='infinitysubiekt'}
    {l s='Aktywne notatki (najwyżej %d) model dostaje na początku każdej rozmowy. Notatki zaproponowane przez model pojawiają się tu po akceptacji paczki zmian.' sprintf=[$max_for_model] mod='infinitysubiekt'}
    <strong>{l s='Nie wpisuj danych klientów: treść trafia do modelu AI.' mod='infinitysubiekt'}</strong>
  </p>

  {if $notes}
    <div class="table-responsive">
      <table class="table table-condensed">
        <thead>
          <tr>
            <th>{l s='Tytuł' mod='infinitysubiekt'}</th>
            <th>{l s='Treść' mod='infinitysubiekt'}</th>
            <th>{l s='Aktywna' mod='infinitysubiekt'}</th>
            <th>{l s='Źródło' mod='infinitysubiekt'}</th>
            <th>{l s='Zmieniona' mod='infinitysubiekt'}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {foreach $notes as $note}
            <tr{if !$note.active} class="text-muted"{/if}>
              <td><strong>{$note.title|escape:'html':'UTF-8'}</strong></td>
              <td style="white-space:pre-wrap;max-width:600px">{$note.content|escape:'html':'UTF-8'}</td>
              <td>
                {if $note.active}
                  <span class="label label-success badge badge-success">{l s='tak' mod='infinitysubiekt'}</span>
                {else}
                  <span class="label label-default badge badge-secondary">{l s='nie' mod='infinitysubiekt'}</span>
                {/if}
              </td>
              <td>{if $note.source == $source_ai}{l s='AI (zaakceptowana)' mod='infinitysubiekt'}{else}{l s='pracownik' mod='infinitysubiekt'}{/if}</td>
              <td>{$note.updated_at|escape:'html':'UTF-8'}</td>
              <td class="text-right" style="white-space:nowrap">
                <a class="btn btn-default btn-xs btn-sm btn-outline-secondary" href="{$note.edit_url|escape:'html':'UTF-8'}">
                  <i class="icon-pencil"></i> {l s='Edytuj' mod='infinitysubiekt'}
                </a>
                <form method="post" action="{$action_url|escape:'html':'UTF-8'}" style="display:inline" onsubmit="return confirm('{l s='Usunąć notatkę? Tego nie da się cofnąć.' mod='infinitysubiekt' js=1}');">
                  <input type="hidden" name="id_note" value="{$note.id_note|intval}">
                  <button type="submit" name="{$submit_delete|escape:'html':'UTF-8'}" value="1" class="btn btn-default btn-xs btn-sm btn-outline-danger">
                    <i class="icon-trash"></i> {l s='Usuń' mod='infinitysubiekt'}
                  </button>
                </form>
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  {else}
    <p class="text-muted">{l s='Brak notatek.' mod='infinitysubiekt'}</p>
  {/if}
</div>

<form method="post" action="{$action_url|escape:'html':'UTF-8'}" class="form-horizontal">
  <div class="panel">
    <div class="panel-heading">
      {if $form.id_note}
        <i class="icon-pencil"></i> {l s='Edycja notatki' mod='infinitysubiekt'} #{$form.id_note|intval}
      {else}
        <i class="icon-plus"></i> {l s='Nowa notatka' mod='infinitysubiekt'}
      {/if}
    </div>
    <input type="hidden" name="id_note" value="{$form.id_note|intval}">

    <div class="form-group">
      <label class="control-label col-lg-2" for="infsubiekt-note-title">{l s='Tytuł' mod='infinitysubiekt'}</label>
      <div class="col-lg-8">
        <input type="text" class="form-control" id="infsubiekt-note-title" name="note_title" maxlength="{$max_title|intval}" value="{$form.title|escape:'html':'UTF-8'}">
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-2" for="infsubiekt-note-content">{l s='Treść' mod='infinitysubiekt'}</label>
      <div class="col-lg-8">
        <textarea class="form-control" id="infsubiekt-note-content" name="note_content" rows="6" maxlength="{$max_content|intval}">{$form.content|escape:'html':'UTF-8'}</textarea>
        <p class="help-block">{l s='Najlepiej jedna reguła na notatkę: co, w jakich dokumentach i jak to liczyć. Do %d znaków.' sprintf=[$max_content] mod='infinitysubiekt'}</p>
      </div>
    </div>

    <div class="form-group">
      <div class="col-lg-offset-2 col-lg-8">
        <label>
          <input type="checkbox" name="note_active" value="1"{if $form.active} checked{/if}>
          {l s='Aktywna (model ją dostaje)' mod='infinitysubiekt'}
        </label>
      </div>
    </div>

    <div class="panel-footer">
      <button type="submit" name="{$submit_save|escape:'html':'UTF-8'}" value="1" class="btn btn-default pull-right">
        <i class="process-icon-save"></i> {l s='Zapisz' mod='infinitysubiekt'}
      </button>
      {if $form.id_note}
        <a class="btn btn-default" href="{$new_url|escape:'html':'UTF-8'}">
          <i class="process-icon-cancel"></i> {l s='Anuluj edycję' mod='infinitysubiekt'}
        </a>
      {/if}
    </div>
  </div>
</form>
