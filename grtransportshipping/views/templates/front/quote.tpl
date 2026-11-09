{extends file='page.tpl'}

{block name='page_title'}{l s='Check transport shipping' mod='grtransportshipping'}{/block}

{block name='page_content'}
<section class="grtransport-quote">
  <p>{l s='Enter a Greek postcode or city to check the current transport price. No account or cart is needed.' mod='grtransportshipping'}</p>
  <form method="get" action="{$quote_action|escape:'htmlall':'UTF-8'}" id="grtransport-quote-form">
    {* Preserve legacy index.php module routing when friendly URLs are disabled. *}
    <input type="hidden" name="fc" value="module">
    <input type="hidden" name="module" value="grtransportshipping">
    <input type="hidden" name="controller" value="quote">
    <label for="grtransport-destination">{l s='Postcode or city' mod='grtransportshipping'}</label>
    <input class="form-control" id="grtransport-destination" name="destination" type="text" maxlength="128" value="{$quote_destination|escape:'htmlall':'UTF-8'}" required>
    <button class="btn btn-primary" type="submit">{l s='Check price' mod='grtransportshipping'}</button>
  </form>
  {if $quote_submitted}
    <div role="status" aria-live="polite" id="grtransport-quote-result">
    {if $quote_route}
      <dl>
        <dt>{l s='Route' mod='grtransportshipping'}</dt>
        <dd>{$quote_route.origin_name|escape:'htmlall':'UTF-8'} → {$quote_route.station_name|escape:'htmlall':'UTF-8'}</dd>
        <dt>{l s='Carrier' mod='grtransportshipping'}</dt>
        <dd>{$quote_carrier|escape:'htmlall':'UTF-8'}</dd>
        <dt>{l s='Transport price including eligible handling' mod='grtransportshipping'}</dt>
        <dd>{$quote_price|escape:'htmlall':'UTF-8'}</dd>
      </dl>
      {$quote_notice nofilter}{* Escaped by the module's shared checkout notice renderer. *}
      <p>{l s='Current route estimate; checkout confirms the final amount. Cart discounts and free shipping are not included here.' mod='grtransportshipping'}</p>
    {else}
      <p class="alert alert-info">{$quote_message|escape:'htmlall':'UTF-8'}</p>
    {/if}
    </div>
  {/if}
  <div id="grtransport-map-option" hidden>
    <button class="btn btn-secondary" type="button" id="grtransport-show-map">{l s='Choose destination on a map' mod='grtransportshipping'}</button>
    <div id="grtransport-map-panel" hidden>
      <p>{l s='Click the map or drag the pin. The nearest listed destination station within the configured radius is used:' mod='grtransportshipping'} {$quote_pin_radius|escape:'htmlall':'UTF-8'} {l s='km (inclusive boundary). This does not mean home delivery. The line connects terminals and is not a travel itinerary.' mod='grtransportshipping'}</p>
      <div id="grtransport-map" role="region" aria-label="{l s='Transport destination map' mod='grtransportshipping'}" data-config="{$quote_map|escape:'htmlall':'UTF-8'}"></div>
      <form method="get" action="{$quote_action|escape:'htmlall':'UTF-8'}" id="grtransport-pin-form">
        <input type="hidden" name="fc" value="module">
        <input type="hidden" name="module" value="grtransportshipping">
        <input type="hidden" name="controller" value="quote">
        <input type="hidden" name="pin" value="1">
        <input type="hidden" name="lat" id="grtransport-pin-lat">
        <input type="hidden" name="lng" id="grtransport-pin-lng">
        <button type="submit" class="btn btn-primary" id="grtransport-pin-submit" disabled>{l s='Check pin destination' mod='grtransportshipping'}</button>
      </form>
    </div>
  </div>
</section>
{/block}
