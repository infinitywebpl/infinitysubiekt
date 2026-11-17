{*
 * Zakładka „Licencja” (InfinitySubiektLicensePage), wariant demo: bez klucza i statusu licencji. Wartości z $html
 * i $features to gotowy HTML z klasy strony (tłumaczenia escapowane), więc idą bez filtra. Escapujemy tu tylko adresy.
 * Zmienne: $features, $github_url, $site_url, $html.
 *}
<div class="panel infsubiekt-license">
  <div class="panel-heading"><i class="icon-certificate"></i> {$html.header nofilter}</div>

  <p>{$html.demo nofilter}</p>

  <p><strong>{$html.full nofilter}</strong></p>
  <ul>
    {foreach $features as $feature}
      <li>{$feature nofilter}</li>
    {/foreach}
  </ul>

  <p>{$html.contact nofilter}</p>

  <div class="alert alert-info" style="margin-top: 20px;">
    <p><strong>{$html.more_title nofilter}</strong></p>
    <p>{$html.more_github nofilter}<br>{$html.more_site nofilter}</p>
    <p>
      <a href="{$github_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener" class="btn btn-default">{$html.more_github_button nofilter}</a>
      <a href="{$site_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener" class="btn btn-default">infinityweb.pl</a>
    </p>
  </div>

  <p class="help-block">{$html.footer nofilter}</p>
</div>
