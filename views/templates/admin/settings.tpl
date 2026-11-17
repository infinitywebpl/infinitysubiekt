{*
 * Strona ustawień modułu: pasek zakładek (linki, każda zakładka to osobne wczytanie strony) i treść
 * otwartej zakładki w ramce doklejonej do paska.
 * Klasy podwójne: Bootstrap 3 (PS 1.6: li.active, label) i Bootstrap 4 (PS 1.7+/8: nav-link.active, badge).
 * Zmienne: $tabs (klucz => label, icon, url, badge, badge_class), $active_tab, $tab_content,
 * $demo_notice_html (komunikat wersji demo, gotowy HTML albo '').
 *}
{literal}<style>
  .infsubiekt-tabs { margin: 0; border-bottom: 0; }
  .infsubiekt-tabs > li > a, .infsubiekt-tabs .nav-link { margin-bottom: -1px; position: relative; z-index: 1; line-height: 20px; }
  .infsubiekt-tabs .label, .infsubiekt-tabs .badge { display: inline-block; font-size: 11px; line-height: 14px; padding: 0 6px; vertical-align: 1px; }
  .infsubiekt-tabs > li.active > a, .infsubiekt-tabs .nav-link.active { background: #fff; border: 1px solid #ddd; border-bottom-color: #fff; }
  .infsubiekt-tab-body { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 15px; margin-bottom: 15px; }
  .infsubiekt-tab-body > .panel:last-child, .infsubiekt-tab-body > form:last-child .panel { margin-bottom: 0; }
  .infsubiekt-demo-notice a { font-weight: 600; }
</style>{/literal}

<div id="infsubiekt-settings" data-ajax-url="{$ajax_url|escape:'html':'UTF-8'}">
  {$message_html nofilter}
  {$demo_notice_html nofilter}

  <ul class="nav nav-tabs infsubiekt-tabs">
    {foreach from=$tabs key=key item=tab}
      <li class="nav-item{if $key == $active_tab} active{/if}">
        <a class="nav-link{if $key == $active_tab} active{/if}" href="{$tab.url|escape:'html':'UTF-8'}">
          <i class="{$tab.icon|escape:'html':'UTF-8'}"></i> {$tab.label|escape:'html':'UTF-8'}
          {if $tab.badge != ''}
            <span class="label label-{$tab.badge_class|escape:'html':'UTF-8'} badge badge-{$tab.badge_class|escape:'html':'UTF-8'}">{$tab.badge|escape:'html':'UTF-8'}</span>
          {/if}
        </a>
      </li>
    {/foreach}
  </ul>
  <div class="infsubiekt-tab-body">
    {$tab_content nofilter}
  </div>
</div>
