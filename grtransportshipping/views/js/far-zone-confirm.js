/* Explicit far-zone consent for PrestaShop 9 checkout.
 * Delegated handlers survive Hummingbird's AJAX delivery-step rerenders.
 * Server-side order validation remains the final, independent guard. */
(function () {
  'use strict';

  const activeSection = () => {
    for (const section of document.querySelectorAll('.grtransport-far-zone[data-confirm-url]')) {
      const row = section.closest('.delivery-option__item, .delivery-option');
      if (row && row.querySelector('input[type="radio"]:checked')) return section;
    }
    return null;
  };
  const notice = (section, message) => {
    const status = section.querySelector('.grtransport-far-status');
    if (status) status.textContent = message;
  };
  const confirmed = section => section.dataset.confirmed === '1';

  document.addEventListener('change', async event => {
    const checkbox = event.target.closest &&
      event.target.closest('.grtransport-far-zone .grtransport-far-ack');
    if (!checkbox) return;
    const section = checkbox.closest('.grtransport-far-zone');
    const choice = checkbox.checked;
    section.dataset.confirmed = '0'; // Never optimistically accept a click.
    checkbox.disabled = true;
    notice(section, choice ? 'Saving your explicit far-zone confirmation…'
      : 'Removing far-zone confirmation…');
    try {
      const body = new URLSearchParams({
        far_token: section.dataset.confirmToken || '',
        far_confirm: choice ? '1' : '0',
      });
      const response = await fetch(section.dataset.confirmUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body: body.toString()
      });
      const result = await response.json();
      if (!response.ok || !result.ok || Boolean(result.confirmed) !== choice) {
        throw new Error('Far-zone acknowledgement did not persist');
      }
      section.dataset.confirmed = choice ? '1' : '0';
      checkbox.checked = choice;
      notice(section, choice ? 'Far-zone option explicitly confirmed.'
        : 'Far-zone consent removed. Confirmation is required to proceed.');
    } catch (_) {
      checkbox.checked = false;
      section.dataset.confirmed = '0';
      notice(section, 'Far-zone confirmation could not be saved. Retry before continuing.');
    } finally {
      checkbox.disabled = false;
    }
  });

  const requireConfirmation = event => {
    const section = activeSection();
    if (!section || confirmed(section)) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    notice(section, 'Far from the station: tick the box and confirm before continuing to payment.');
    const checkbox = section.querySelector('.grtransport-far-ack');
    if (checkbox) checkbox.focus();
    section.scrollIntoView({block:'center',behavior:'smooth'});
  };
  // Classic/Hummingbird themes may trigger different submit paths.
  document.addEventListener('submit', event => {
    if (event.target.closest('#checkout-delivery-step')) requireConfirmation(event);
  }, true);
  document.addEventListener('click', event => {
    const button = event.target.closest &&
      event.target.closest('button[name="confirmDeliveryOption"], #checkout-delivery-step button.continue, #checkout-delivery-step button[data-link-action="confirm-delivery-option"]');
    if (button) requireConfirmation(event);
  }, true);
}());
