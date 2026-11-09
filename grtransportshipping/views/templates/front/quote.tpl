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
    <input type="hidden" name="pin" id="grtransport-is-pin" value="{if $quote_is_pin}1{else}0{/if}">
    <input type="hidden" name="lat" id="grtransport-pin-lat" value="{$quote_lat|escape:'htmlall':'UTF-8'}">
    <input type="hidden" name="lng" id="grtransport-pin-lng" value="{$quote_lng|escape:'htmlall':'UTF-8'}">
    <label for="grtransport-destination">{l s='Postcode, city or pinned address' mod='grtransportshipping'}</label>
    <input class="form-control" id="grtransport-destination" name="destination" type="text" maxlength="128" value="{$quote_destination|escape:'htmlall':'UTF-8'}" required>
    <label for="grtransport-note">{l s='Note for this quote (optional)' mod='grtransportshipping'}</label>
    <textarea class="form-control" id="grtransport-note" name="note" maxlength="500" rows="2">{$quote_note|escape:'htmlall':'UTF-8'}</textarea>
    <button class="btn btn-primary" type="submit">{l s='Check price' mod='grtransportshipping'}</button>
    <div id="grtransport-map-option" hidden>
      <button class="btn btn-secondary" type="button" id="grtransport-show-map">{l s='Choose destination on a map' mod='grtransportshipping'}</button>
      <div id="grtransport-map-panel" hidden>
        <p>{l s='Drop or drag your destination pin. The location is priced using the configured radius around listed destinations; this does not guarantee home delivery.' mod='grtransportshipping'}</p>
        <div id="grtransport-map" role="region" aria-label="{l s='Transport destination map' mod='grtransportshipping'}" data-config="{$quote_map|escape:'htmlall':'UTF-8'}"></div>
        <p id="grtransport-pin-status" role="status" aria-live="polite">{l s='Select a location on the map. Address lookup may be unavailable; coordinates will still be used for the quote.' mod='grtransportshipping'}</p>
        <button type="submit" class="btn btn-primary" id="grtransport-pin-submit" disabled>{l s='Check pin destination' mod='grtransportshipping'}</button>
      </div>
    </div>
  </form>
  {if $quote_submitted}
    <div role="status" aria-live="polite" id="grtransport-quote-result">
    {if $quote_route}
      {if $quote_far_required && !$quote_far_confirmed}
        <div class="alert alert-warning grtransport-far-preview" role="alert">
          <strong>{l s='Far from the station — FAR zone' mod='grtransportshipping'}</strong>
          <p>{l s='This address is outside the normal service reach. You may need to walk a long distance to the station. Proceed with this far-zone transport option anyway?' mod='grtransportshipping'}</p>
        </div>
        <form method="post" action="{$quote_action|escape:'htmlall':'UTF-8'}" id="grtransport-quote-far-confirm">
          <input type="hidden" name="fc" value="module">
          <input type="hidden" name="module" value="grtransportshipping">
          <input type="hidden" name="controller" value="quote">
          <input type="hidden" name="destination" value="{$quote_destination|escape:'htmlall':'UTF-8'}">
          <input type="hidden" name="pin" value="{if $quote_is_pin}1{else}0{/if}">
          <input type="hidden" name="lat" value="{$quote_lat|escape:'htmlall':'UTF-8'}">
          <input type="hidden" name="lng" value="{$quote_lng|escape:'htmlall':'UTF-8'}">
          <input type="hidden" name="note" value="{$quote_note|escape:'htmlall':'UTF-8'}">
          <label>
            <input type="checkbox" name="far_ack" value="1" required>
            {l s='I understand that the station is far away and explicitly choose to proceed with this option.' mod='grtransportshipping'}
          </label>
          <button class="btn btn-warning" type="submit" name="far_accept" value="1">{l s='Proceed with far-zone transport quote' mod='grtransportshipping'}</button>
        </form>
        <p>{l s='Any eventual checkout order requires a separate far-zone confirmation.' mod='grtransportshipping'}</p>
      {else}
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
      {/if}
    {else}
      <p class="alert alert-info">{$quote_message|escape:'htmlall':'UTF-8'}</p>
    {/if}
      {if $quote_note}
        <p class="grtransport-quote-note"><strong>{l s='Note' mod='grtransportshipping'}:</strong> {$quote_note|escape:'htmlall':'UTF-8'}</p>
      {/if}
    </div>
  {/if}
</section>
{/block}
