{*
 * Zakładka „Cron”: co robi cron, ostatni przebieg, linie do crontaba, ręczne uruchomienie, ustawienia.
 *}
<div class="panel">
  <div class="panel-heading">
    <i class="icon-question-circle"></i> {l s='Co robi cron' mod='infinitysubiekt'}
  </div>

  <table class="table">
    <thead>
      <tr>
        <th>{l s='Zadanie' mod='infinitysubiekt'}</th>
        <th>{l s='Co robi' mod='infinitysubiekt'}</th>
        <th>{l s='Kiedy' mod='infinitysubiekt'}</th>
      </tr>
    </thead>
    <tbody>
      {foreach $cron.job_rows as $job}
        <tr>
          <td style="white-space:nowrap"><code>{$job.name|escape:'html':'UTF-8'}</code></td>
          <td>{$job.what|escape:'html':'UTF-8'}</td>
          <td style="white-space:nowrap">
            {if $job.off}
              <span class="label label-default">{$job.when|escape:'html':'UTF-8'}</span>
            {else}
              {$job.when|escape:'html':'UTF-8'}
            {/if}
          </td>
        </tr>
      {/foreach}
    </tbody>
  </table>

  <p class="help-block">
    {l s='Cron niczego nie zapisuje w Subiekcie i nie wystawia dokumentów. Wysłanie stanu ze sklepu do Subiekta to tylko ręczny przycisk na karcie produktu, bo tworzy dokument PW albo RW.' mod='infinitysubiekt'}
  </p>
</div>

<div class="panel">
  <div class="panel-heading">
    <i class="icon-time"></i> {l s='Cron' mod='infinitysubiekt'}
  </div>

  <table class="table">
    <tbody>
      <tr>
        <td class="col-lg-3"><strong>{l s='Ostatni przebieg' mod='infinitysubiekt'}</strong></td>
        <td>
          {if $cron.last_run}
            {$cron.last_run|escape:'html':'UTF-8'}
          {else}
            {l s='cron jeszcze nie działał' mod='infinitysubiekt'}
          {/if}
          {if $cron.stale}
            <div class="alert alert-warning" style="margin:10px 0 0">
              {l s='Cron nie działał dłużej niż (min):' mod='infinitysubiekt'} {$cron.stale_minutes|intval}.
              {l s='Kolejkę porządkuje też każdy poll agenta, ale stare zadania usuwa tylko cron.' mod='infinitysubiekt'}
            </div>
          {/if}
          {if $cron.log}
            <pre style="margin-top:10px">{$cron.log|escape:'html':'UTF-8'}</pre>
          {/if}
        </td>
      </tr>
      <tr>
        <td><strong>{l s='Przez HTTP (wget / curl)' mod='infinitysubiekt'}</strong></td>
        <td>
          {if $cron.http_url}
            <input type="text" class="form-control" readonly="readonly" value="*/5 * * * * wget -q -O - &quot;{$cron.http_url|escape:'html':'UTF-8'}&quot; &gt;/dev/null 2&gt;&amp;1" onclick="this.select();">
            <input type="text" class="form-control" readonly="readonly" style="margin-top:5px" value="*/5 * * * * curl -s &quot;{$cron.http_url|escape:'html':'UTF-8'}&quot; &gt;/dev/null 2&gt;&amp;1" onclick="this.select();">
            <p class="help-block">
              {l s='Opcjonalne parametry: &jobs=nazwa1,nazwa2 i &dry_run=1. Kody: 200 OK, 400 nieznane zadanie, 403 zły token, 409 przebieg w toku.' mod='infinitysubiekt'}
            </p>
          {else}
            <div class="alert alert-warning">
              {l s='Token crona jest pusty, więc wywołanie przez HTTP jest wyłączone.' mod='infinitysubiekt'}
              <a href="{$cron.settings_url|escape:'html':'UTF-8'}">{l s='Wygeneruj token w zakładce Ustawienia.' mod='infinitysubiekt'}</a>
            </div>
          {/if}
        </td>
      </tr>
      <tr>
        <td><strong>{l s='Z linii komend' mod='infinitysubiekt'}</strong></td>
        <td>
          <input type="text" class="form-control" readonly="readonly" value="*/5 * * * * php {$cron.cli_path|escape:'html':'UTF-8'} &gt;/dev/null 2&gt;&amp;1" onclick="this.select();">
          <p class="help-block">
            {l s='Opcje: --jobs=nazwa1,nazwa2, --dry-run, --help. Token nie jest potrzebny.' mod='infinitysubiekt'}
            {l s='Zadania:' mod='infinitysubiekt'} {$cron.jobs|escape:'html':'UTF-8'}.
          </p>
        </td>
      </tr>
      <tr>
        <td><strong>{l s='Uruchom teraz' mod='infinitysubiekt'}</strong></td>
        <td>
          <button type="button" class="btn btn-default" data-infsubiekt-cron-run="0">
            <i class="icon-play"></i> {l s='Uruchom' mod='infinitysubiekt'}
          </button>
          <button type="button" class="btn btn-default" data-infsubiekt-cron-run="1">
            <i class="icon-eye"></i> {l s='Dry-run' mod='infinitysubiekt'}
          </button>
          <pre data-infsubiekt-cron-result style="display:none;margin-top:10px"></pre>
        </td>
      </tr>
    </tbody>
  </table>
</div>

{$form_html nofilter}
