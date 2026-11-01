{*
 * @author    CommerceStream Innovate SRL
 * @copyright 2026 CommerceStream Innovate SRL
 * @license   https://opensource.org/licenses/MIT MIT
 *}
<div class="card mt-2" id="efacturiplus-order">
  <div class="card-header"><h3 class="card-header-title">eFacturiPlus</h3></div>
  <div class="card-body">
    {if !empty($efp.error)}<p class="text-danger">{$efp.error|escape:'html':'UTF-8'}</p>{/if}
    {if empty($efp.invoice_id)}
      <p>{l s='Comanda nu are factură.' mod='efacturiplus'}</p>
      <a class="btn btn-primary" href="{$efp_tasks.issue|escape:'html':'UTF-8'}">{l s='Emite factura' mod='efacturiplus'}</a>
    {else}
      <p><strong>{l s='Factura' mod='efacturiplus'} {$efp.label|escape:'html':'UTF-8'}</strong><br>{$efp.spv|escape:'html':'UTF-8'}</p>
      {if !empty($efp.storno_label)}<p>{l s='Stornată prin' mod='efacturiplus'} {$efp.storno_label|escape:'html':'UTF-8'}</p>{/if}
      <a class="btn btn-outline-secondary" target="_blank" rel="noopener" href="{$efp_tasks.pdf|escape:'html':'UTF-8'}">{l s='Descarcă PDF' mod='efacturiplus'}</a>
      <a class="btn btn-outline-secondary" href="{$efp_tasks.refresh|escape:'html':'UTF-8'}">{l s='Actualizează' mod='efacturiplus'}</a>
      <a class="btn btn-outline-secondary" href="{$efp_tasks.spv|escape:'html':'UTF-8'}">{l s='Trimite în SPV' mod='efacturiplus'}</a>
      {if empty($efp.storno_label)}
        <a class="btn btn-outline-danger" href="{$efp_tasks.storno|escape:'html':'UTF-8'}">{l s='Stornează factura' mod='efacturiplus'}</a>
      {else}
        <a class="btn btn-outline-secondary" href="{$efp_tasks.spv_storno|escape:'html':'UTF-8'}">{l s='Trimite stornarea în SPV' mod='efacturiplus'}</a>
      {/if}
    {/if}
  </div>
</div>
