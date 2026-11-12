/* Configure-only enhancements. Data contracts and map rendering are unchanged. */
(() => {
  'use strict';
  const root = document.getElementById('grtrans-configure');
  if (!root) return;
  root.classList.add('grtrans-js');
  const pageAdvanced=root.querySelector('#grtrans-page-advanced-toggle');
  if(pageAdvanced) {
    try {pageAdvanced.checked=sessionStorage.getItem('grtrans-page-advanced')==='1';}catch(_){}
    const applyAdvanced=()=>{
      root.classList.toggle('grtrans-show-advanced',pageAdvanced.checked);
      try {sessionStorage.setItem('grtrans-page-advanced',pageAdvanced.checked?'1':'0');}catch(_){}
    };
    pageAdvanced.addEventListener('change',applyAdvanced);applyAdvanced();
  }

  const copy = async button => {
    const code = document.getElementById(button.dataset.grtransCopy);
    if (!code) return;
    try {
      if (!navigator.clipboard || !navigator.clipboard.writeText) throw new Error('Clipboard unavailable');
      await navigator.clipboard.writeText(code.textContent);
      button.title = button.dataset.copyOk;
      button.setAttribute('aria-label', button.dataset.copyOk);
      button.dataset.copyState = 'copied';
    } catch (_) {
      button.title = button.dataset.copyFail;
      button.setAttribute('aria-label', button.dataset.copyFail);
      const range = document.createRange();
      range.selectNodeContents(code);
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
    }
  };
  root.addEventListener('click', event => {
    const button = event.target.closest('[data-grtrans-copy]');
    if (button && root.contains(button)) copy(button);
  });

  // Settings post all current values through the existing authenticated handler.
  // No new endpoint or independent per-field storage semantics are introduced.
  for (const form of root.querySelectorAll('form[data-grtrans-autosave]')) {
    const status = form.querySelector('[data-grtrans-save-status]');
    const copy = {
      saving: form.dataset.stateSaving || 'Saving…',
      saved: form.dataset.stateSaved || 'Saved',
      unsaved: form.dataset.stateUnsaved || 'Unsaved changes',
      failed: form.dataset.stateFailed || 'Could not save. Correct the values and try again.'
    };
    let pending = false;
    let running = false;
    let scheduled = null;
    let revision = 0;
    const report = (message, mode) => {
      if (!status) return;
      status.textContent = message;
      status.dataset.state = mode;
    };
    const persist = async () => {
      if (running || !pending) return;
      running = true;
      try {
        while (pending) {
          pending = false;
          const submittedRevision = revision;
          report(copy.saving, 'saving');
          if (!form.checkValidity()) {
            report(copy.failed, 'error');
            break;
          }
          const payload = new FormData(form);
          payload.set('grtrans_autosave', '1');
          try {
            const response = await fetch(form.action, {
              method: 'POST',
              body: payload,
              credentials: 'same-origin',
              headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const document = new DOMParser().parseFromString(await response.text(), 'text/html');
            const result = document.querySelector('[data-grtrans-autosave-result]');
            if (!result || result.dataset.grtransAutosaveResult !== 'ok') {
              throw new Error(result ? result.textContent.trim() : copy.failed);
            }
            if (revision === submittedRevision && !pending) report(copy.saved, 'saved');
          } catch (error) {
            if (revision === submittedRevision && !pending) {
              report(error.message || copy.failed, 'error');
              break;
            }
          }
        }
      } finally {
        running = false;
      }
    };
    const changed = immediate => {
      revision += 1;
      pending = true;
      report(copy.unsaved, 'unsaved');
      clearTimeout(scheduled);
      if (immediate) persist();
      else scheduled = setTimeout(persist, 550);
    };
    form.addEventListener('input', event => {
      if (event.target.closest('input[type="hidden"]')) return;
      changed(false);
    });
    form.addEventListener('change', event => {
      if (event.target.matches('input[type="checkbox"],input[type="number"],select')) changed(true);
      else changed(false);
    });
    form.addEventListener('submit', event => {
      event.preventDefault();
      changed(true);
    });
  }

  // A route is a consequential, multi-field record: keep its existing explicit
  // signed save action and show whether the visible form differs from the DB.
  const route = root.querySelector('#grtrans-route-editor form');
  const routeStatus = root.querySelector('[data-grtrans-route-state]');
  if (route && routeStatus) {
    let edited = false;
    route.addEventListener('input', () => {
      if (edited) return;
      edited = true;
      routeStatus.textContent = routeStatus.dataset.unsavedLabel || 'Unsaved changes';
      routeStatus.dataset.state = 'unsaved';
    });
    route.addEventListener('change', () => {
      if (edited) return;
      edited = true;
      routeStatus.textContent = routeStatus.dataset.unsavedLabel || 'Unsaved changes';
      routeStatus.dataset.state = 'unsaved';
    });
  }

  // Consequential network edits keep the signed, explicit Save, with visible
  // draft state from first paint through edits, reset and failed POST recovery.
  const networkForm=root.querySelector('#grtrans-network-editor form');
  const networkState=root.querySelector('[data-grtrans-network-state]');
  if(networkForm && networkState) {
    const baseline=new URLSearchParams(new FormData(networkForm)).toString();
    const retained=networkState.dataset.state==='unsaved';
    const ready=networkState.dataset.readyLabel || 'No changes';
    const unsaved=networkState.dataset.unsavedLabel || 'Unsaved changes';
    const refresh=()=>{
      const dirty=retained || new URLSearchParams(new FormData(networkForm)).toString()!==baseline;
      networkState.textContent=dirty?unsaved:ready;
      networkState.dataset.state=dirty?'unsaved':'ready';
    };
    networkForm.addEventListener('input',refresh);
    networkForm.addEventListener('change',refresh);
    networkForm.addEventListener('submit',()=>{
      if(!networkForm.checkValidity())return;
      networkState.textContent='Saving…';
      networkState.dataset.state='saving';
    });
    refresh();
  }

  const simulator = root.querySelector('[data-grtrans-simulator]');
  if (simulator) {
    const station = simulator.querySelector('[name="station_id"]');
    const options = [...simulator.querySelectorAll('[data-sim-method]')];
    const panels = [...simulator.querySelectorAll('[data-sim-fields]')];
    let active = station && Number(station.value) > 0 ? 'station' : 'address';
    const render = mode => {
      active = mode;
      options.forEach(option => option.setAttribute('aria-pressed', String(option.dataset.simMethod === mode)));
      panels.forEach(panel => { panel.hidden = panel.dataset.simFields !== mode; });
      if (mode === 'address' && station) station.value = '0';
    };
    options.forEach(button => button.addEventListener('click', () => render(button.dataset.simMethod)));
    render(active);
    const result = root.querySelector('[data-grtrans-result]');
    if (result) simulator.addEventListener('input', () => { result.dataset.stale = 'true'; });
    simulator.addEventListener('submit', event => {
      if (active !== 'station') return;
      if (!station || Number(station.value) <= 0) {
        event.preventDefault();
        station?.focus();
        station?.setCustomValidity(simulator.dataset.stationRequired || 'Choose a station.');
        station?.reportValidity();
      }
    });
    station?.addEventListener('change', () => station.setCustomValidity(''));
  }
})();
