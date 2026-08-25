(() => {
  'use strict';

  const form = document.querySelector('#signup-form');
  if (!form || !document.body.classList.contains('koopo-provider-onboarding')) return;

  const account = form.querySelector('#basic-details-section');
  const community = form.querySelector('#profile-details-section');
  const store = form.querySelector('#koopo-vendor-section');
  const service = form.querySelector('#koopo-service-profile-section');
  const panels = [
    {node: account, label: 'Account', hint: 'Sign-in details'},
    {node: community, label: 'About you', hint: 'Community profile'},
    {node: store, label: 'Store', hint: 'Selling details'},
    {node: service, label: 'Services', hint: 'Public profile'},
  ].filter((step) => step.node);
  if (panels.length < 3) return;

  const nativeSubmit = form.querySelector('[name="signup_submit"]');
  const submitWrap = nativeSubmit ? nativeSubmit.closest('.submit') || nativeSubmit.parentElement : null;
  const layout = form.querySelector('.layout-wrap');
  if (!nativeSubmit || !layout) return;

  document.body.classList.add('koopo-onboarding-enhanced');
  nativeSubmit.value = 'Create account & profile';
  if (nativeSubmit.tagName === 'BUTTON') nativeSubmit.textContent = 'Create account & profile';

  const shell = document.createElement('div');
  shell.className = 'koopo-onboard-shell';
  shell.innerHTML = `
    <aside class="koopo-onboard-rail">
      <a class="koopo-onboard-brand" href="${window.KOOPO_PROVIDER_ONBOARDING?.bookUrl || '/bookable/'}"><span>Koopo</span><small>Provider setup</small></a>
      <div><span class="koopo-onboard-eyebrow">Start taking appointments</span><h1>Build the profile customers can book.</h1><p>One account for your community identity, storefront, services, and calendar.</p></div>
      <ol class="koopo-onboard-progress" aria-label="Registration progress"></ol>
      <small class="koopo-onboard-trust">Your profile is not published until you verify your email.</small>
    </aside>
    <div class="koopo-onboard-stage"><div class="koopo-onboard-mobile-progress" aria-live="polite"></div><div class="koopo-onboard-panel"></div><div class="koopo-onboard-actions"><button type="button" class="koopo-onboard-back">Back</button><button type="button" class="koopo-onboard-next">Continue</button></div></div>`;
  layout.parentNode.insertBefore(shell, layout);
  shell.querySelector('.koopo-onboard-panel').appendChild(layout);

  const legalWrap = document.createElement('div');
  legalWrap.className = 'koopo-onboard-legal';
  Array.from(form.children).forEach((child) => {
    if (child !== shell && child !== submitWrap) legalWrap.appendChild(child);
  });

  const progress = shell.querySelector('.koopo-onboard-progress');
  panels.forEach((step, index) => {
    const item = document.createElement('li');
    item.innerHTML = `<button type="button" data-step="${index}"><span>${String(index + 1).padStart(2, '0')}</span><b>${step.label}</b><small>${step.hint}</small></button>`;
    progress.appendChild(item);
  });

  const actions = shell.querySelector('.koopo-onboard-actions');
  const back = shell.querySelector('.koopo-onboard-back');
  const next = shell.querySelector('.koopo-onboard-next');
  const mobileProgress = shell.querySelector('.koopo-onboard-mobile-progress');
  shell.querySelector('.koopo-onboard-stage').insertBefore(legalWrap, actions);
  if (submitWrap) actions.appendChild(submitWrap);
  let current = 0;

  const firstError = panels.findIndex((step) => step.node.querySelector('.error, .invalid, [aria-invalid="true"]'));
  if (firstError >= 0) current = firstError;

  function show(index, focus = false) {
    current = Math.max(0, Math.min(index, panels.length - 1));
    panels.forEach((step, stepIndex) => {
      step.node.hidden = stepIndex !== current;
      step.node.classList.toggle('is-current', stepIndex === current);
    });
    progress.querySelectorAll('li').forEach((item, stepIndex) => {
      item.classList.toggle('is-current', stepIndex === current);
      item.classList.toggle('is-complete', stepIndex < current);
      item.querySelector('button').disabled = stepIndex > current;
      item.querySelector('button').setAttribute('aria-current', stepIndex === current ? 'step' : 'false');
    });
    back.hidden = current === 0;
    next.hidden = current === panels.length - 1;
    if (submitWrap) submitWrap.hidden = current !== panels.length - 1;
    legalWrap.hidden = current !== panels.length - 1;
    mobileProgress.innerHTML = `<span>Step ${current + 1} of ${panels.length}</span><strong>${panels[current].label}</strong><i style="--progress:${((current + 1) / panels.length) * 100}%"></i>`;
    if (focus) panels[current].node.querySelector('h2, legend, input, select')?.focus({preventScroll: true});
    shell.scrollIntoView({behavior: 'smooth', block: 'start'});
  }

  function validateCurrent() {
    const requiredModes = panels[current].node.querySelectorAll('input[name="koopo_service_modes[]"]');
    if (requiredModes.length && !Array.from(requiredModes).some((field) => field.checked)) {
      requiredModes[0].setCustomValidity('Choose at least one service delivery option.');
      requiredModes[0].reportValidity();
      return false;
    }
    requiredModes.forEach((field) => field.setCustomValidity(''));
    const controls = panels[current].node.querySelectorAll('input, select, textarea');
    for (const control of controls) {
      if (!control.checkValidity()) {
        control.reportValidity();
        return false;
      }
    }
    return true;
  }

  next.addEventListener('click', () => {
    if (validateCurrent()) show(current + 1, true);
  });
  back.addEventListener('click', () => show(current - 1, true));
  progress.addEventListener('click', (event) => {
    const button = event.target.closest('[data-step]');
    if (button && Number(button.dataset.step) <= current) show(Number(button.dataset.step), true);
  });

  const storeName = form.querySelector('[name="koopo_store_name"]');
  const storeSlug = form.querySelector('[name="koopo_store_slug"]');
  let slugEdited = Boolean(storeSlug?.value);
  const slugify = (value) => value.toLowerCase().trim().replace(/['’]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60);
  storeSlug?.addEventListener('input', () => { slugEdited = Boolean(storeSlug.value); });
  storeName?.addEventListener('input', () => {
    if (storeSlug && !slugEdited) storeSlug.value = slugify(storeName.value);
  });

  form.addEventListener('invalid', (event) => {
    const index = panels.findIndex((step) => step.node.contains(event.target));
    if (index >= 0 && index !== current) show(index);
  }, true);
  form.addEventListener('submit', (event) => {
    const invalidIndex = panels.findIndex((step) => Array.from(step.node.querySelectorAll('input, select, textarea')).some((field) => !field.checkValidity()));
    if (invalidIndex >= 0) {
      event.preventDefault();
      show(invalidIndex);
      panels[invalidIndex].node.querySelector(':invalid')?.reportValidity();
    }
  });

  show(current);
})();
