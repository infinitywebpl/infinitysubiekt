{*
 * Zakładka „Agent”: status, endpoint, ping.
 *}
<div class="panel">
  <div class="panel-heading">
    <i class="icon-exchange"></i> {l s='Agent Subiekta' mod='infinitysubiekt'}
  </div>

  <table class="table">
    <tbody>
      <tr>
        <td class="col-lg-3"><strong>{l s='Status' mod='infinitysubiekt'}</strong></td>
        <td>
          {if $online}
            <span class="label label-success">{l s='online' mod='infinitysubiekt'}</span>
          {else}
            <span class="label label-danger">{l s='offline' mod='infinitysubiekt'}</span>
          {/if}
          &nbsp;
          {if $last_seen}
            {l s='ostatni sygnał:' mod='infinitysubiekt'} {$last_seen|escape:'html':'UTF-8'}
          {else}
            {l s='agent jeszcze się nie połączył' mod='infinitysubiekt'}
          {/if}
        </td>
      </tr>
      <tr>
        <td><strong>{l s='Endpoint dla agenta' mod='infinitysubiekt'}</strong></td>
        <td>
          <input type="text" class="form-control" readonly="readonly" value="{$endpoint_url|escape:'html':'UTF-8'}" onclick="this.select();">
          <p class="help-block">
            {l s='Wpisz w konfiguracji agenta jako endpoint. Agent dokleja op=poll&wait=N albo op=result&id=N i wysyła nagłówek X-Agent-Token.' mod='infinitysubiekt'}
            {l s='Maksymalne czekanie pollu:' mod='infinitysubiekt'} {$poll_wait|intval} s.
          </p>
          {if !$token_set}
            <div class="alert alert-warning">
              {l s='Token agenta jest pusty, więc endpoint odpowiada 403.' mod='infinitysubiekt'}
              <a href="{$settings_url|escape:'html':'UTF-8'}">{l s='Wygeneruj token w zakładce Ustawienia.' mod='infinitysubiekt'}</a>
            </div>
          {/if}
        </td>
      </tr>
      <tr>
        <td><strong>{l s='Pobierz agenta' mod='infinitysubiekt'}</strong></td>
        <td>
          <a class="btn btn-default" href="{$agent_download_url|escape:'html':'UTF-8'}">
            <i class="icon-download"></i> {l s='Pobierz ZIP' mod='infinitysubiekt'}
          </a>
          <p class="help-block">
            {if $agent_package.version}{l s='Wersja:' mod='infinitysubiekt'} {$agent_package.version|escape:'html':'UTF-8'}.{/if}
            {if $agent_package.updated_at}{l s='Najnowszy plik agenta na sklepie:' mod='infinitysubiekt'} {$agent_package.updated_at|escape:'html':'UTF-8'}.{/if}
            {l s='Paczka powstaje przy każdym pobraniu i nie zawiera config.php ani logów, więc można ją rozpakować na istniejącego agenta. Potem zrestartuj usługę agenta.' mod='infinitysubiekt'}
          </p>
        </td>
      </tr>
      <tr>
        <td><strong>{l s='Zdalna aktualizacja' mod='infinitysubiekt'}</strong></td>
        <td>
          <p>
            {l s='Wersja działającego agenta:' mod='infinitysubiekt'}
            <strong>{if $agent_version}{$agent_version|escape:'html':'UTF-8'}{else}{l s='nieznana (agent jeszcze nie pollował)' mod='infinitysubiekt'}{/if}</strong>.
            {l s='Podpisane wydanie na sklepie:' mod='infinitysubiekt'}
            <strong>{if $agent_release.exists}{$agent_release.version|escape:'html':'UTF-8'}{else}{l s='brak release.json' mod='infinitysubiekt'}{/if}</strong>{if $agent_release.created_at} ({$agent_release.created_at|escape:'html':'UTF-8'}){/if}.
          </p>
          {if $agent_release.exists && $agent_release.changed}
            <div class="alert alert-warning">
              {l s='Pliki agenta na sklepie różnią się od podpisanego release.json, więc agent odrzuci aktualizację. Podpisz wydanie ponownie (agent/tools/release.php sign) i wgraj release.json. Różnice:' mod='infinitysubiekt'}
              {$agent_release_changed|escape:'html':'UTF-8'}
            </div>
          {/if}
          <button type="button" class="btn btn-default" data-infsubiekt-agent-action="updateAgent"
                  data-infsubiekt-confirm="{l s='Zaktualizować agenta do wersji ze sklepu? Agent pobierze pliki, sprawdzi podpis i uruchomi się ponownie.' mod='infinitysubiekt'}"
                  {if !$agent_release.exists || $agent_release.changed}disabled="disabled"{/if}>
            <i class="icon-cloud-download"></i> {l s='Zaktualizuj agenta' mod='infinitysubiekt'}
          </button>
          <button type="button" class="btn btn-default" data-infsubiekt-agent-action="rollbackAgent"
                  data-infsubiekt-confirm="{l s='Przywrócić poprzednią wersję agenta? Agent uruchomi się ponownie.' mod='infinitysubiekt'}">
            <i class="icon-undo"></i> {l s='Przywróć poprzednią wersję' mod='infinitysubiekt'}
          </button>
          <p class="help-block">
            {l s='Agent przyjmuje tylko wydanie podpisane kluczem autora i wyższe niż działające. Po instalacji uruchamia się ponownie; nowa wersja zostaje, gdy pierwszy raz połączy się ze sklepem, a po 3 nieudanych startach agent wraca do poprzedniej. Wymaga update_enabled w config.php agenta. Szczegóły w wyniku Ping (release).' mod='infinitysubiekt'}
          </p>
          <pre data-infsubiekt-agent-result style="display:none;margin-top:10px;white-space:pre-wrap"></pre>
        </td>
      </tr>
      <tr>
        <td><strong>{l s='Test połączenia' mod='infinitysubiekt'}</strong></td>
        <td>
          <button type="button" class="btn btn-default" data-infsubiekt-ping>
            <i class="icon-refresh"></i> {l s='Ping' mod='infinitysubiekt'}
          </button>
          <span class="help-block" style="display:inline">{l s='Tworzy zadanie ping i czeka na odpowiedź agenta do 15 s.' mod='infinitysubiekt'}</span>
          <pre data-infsubiekt-ping-result style="display:none;margin-top:10px;white-space:pre-wrap"></pre>
        </td>
      </tr>
    </tbody>
  </table>
</div>
