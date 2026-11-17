{*
 * Ostrzeżenie o pustym prefiksie i tryb dopasowania kontrahenta.
 *}
{if $prefix_missing}
  <div class="alert alert-warning">
    {l s='Prefiks symbolu kontrahenta jest pusty, więc wysyłanie kontrahentów jest zablokowane. Ustaw go w konfiguracji modułu.' mod='infinitysubiekt'}
  </div>
{elseif $customer_mode == 'match'}
  <p class="help-block text-muted">
    {l s='Tryb dopasowania: agent szuka kontrahenta po symbolu, NIP, a potem e-mailu, zanim założy nowego.' mod='infinitysubiekt'}
  </p>
{/if}
