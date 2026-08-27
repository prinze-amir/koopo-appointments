(() => {
  'use strict';

  const form = document.querySelector('#signup-form');
  if (!form || !document.body.classList.contains('koopo-provider-onboarding')) return;

  const verification = form.querySelector('#koopo-email-verification');
  if (verification) {
    document.body.classList.add('koopo-onboarding-enhanced');
    const verifyShell = document.createElement('div');
    verifyShell.className = 'koopo-onboard-shell koopo-onboard-shell--verification';
    verifyShell.innerHTML = `
      <aside class="koopo-onboard-rail">
        <a class="koopo-onboard-brand" href="${window.KOOPO_PROVIDER_ONBOARDING?.bookUrl || '/bookable/'}"><span>Koopo</span><small>Provider setup</small></a>
        <div><span class="koopo-onboard-eyebrow">Account security</span><h1>Confirm it’s really you.</h1><p>Your account stays private until your email address is verified.</p></div>
        <ol class="koopo-onboard-progress" aria-label="Registration progress"><li class="is-complete"><button type="button" disabled><span>01</span><b>Account</b><small>Registered</small></button></li><li class="is-current"><button type="button" disabled aria-current="step"><span>02</span><b>Email</b><small>Enter your code</small></button></li></ol>
        <small class="koopo-onboard-trust">Never share this code with anyone. Koopo support will not ask for it.</small>
      </aside>
      <div class="koopo-onboard-stage"><div class="koopo-onboard-account-link">Already verified? <a href="${window.KOOPO_PROVIDER_ONBOARDING?.loginUrl || '/wp-login.php'}">Sign in</a></div><div class="koopo-onboard-panel"></div></div>`;
    form.parentNode.insertBefore(verifyShell, form);
    verifyShell.querySelector('.koopo-onboard-panel').appendChild(verification);
    form.hidden = true;

    const email = verification.querySelector('#koopo-verification-email');
    const code = verification.querySelector('#koopo-verification-code');
    const submit = verification.querySelector('.koopo-verify-submit');
    const resend = verification.querySelector('.koopo-verify-resend');
    const status = verification.querySelector('.koopo-verification-status');

    const request = async (url, payload) => {
      const response = await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json'}, body: JSON.stringify(payload)});
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.message || 'Something went wrong. Please try again.');
      return data;
    };
    const setBusy = (busy) => {
      submit.disabled = busy;
      resend.disabled = busy;
      verification.classList.toggle('is-busy', busy);
    };
    submit.addEventListener('click', async () => {
      if (!email.checkValidity()) return email.reportValidity();
      if (!code.checkValidity()) return code.reportValidity();
      setBusy(true);
      status.className = 'koopo-verification-status';
      status.textContent = 'Checking your code…';
      try {
        const data = await request(verification.dataset.endpoint, {email: email.value.trim(), code: code.value.trim()});
        status.className = 'koopo-verification-status is-success';
        status.textContent = data.pack_id ? 'Email verified. Preparing secure checkout…' : 'Email verified. Preparing your provider account…';
        if (data.pack_id && data.checkout_endpoint) {
          const checkout = await fetch(data.checkout_endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-WP-Nonce': data.nonce || ''},
            body: JSON.stringify({pack_id: data.pack_id}),
          });
          const checkoutData = await checkout.json().catch(() => ({}));
          if (!checkout.ok) throw new Error(checkoutData.message || 'Your account is verified, but secure checkout could not be prepared. Sign in and choose your plan from the seller dashboard.');
          window.location.assign(checkoutData.checkout_url);
          return;
        }
        window.location.assign(data.next_url || window.KOOPO_PROVIDER_ONBOARDING?.bookUrl || '/bookable/');
      } catch (error) {
        status.className = 'koopo-verification-status is-error';
        status.textContent = error.message;
        code.focus();
        code.select();
        setBusy(false);
      }
    });
    resend.addEventListener('click', async () => {
      if (!email.checkValidity()) return email.reportValidity();
      setBusy(true);
      status.className = 'koopo-verification-status';
      status.textContent = 'Sending a new code…';
      try {
        const data = await request(verification.dataset.resendEndpoint, {email: email.value.trim()});
        status.className = 'koopo-verification-status is-success';
        status.textContent = data.message || 'A new code has been sent.';
      } catch (error) {
        status.className = 'koopo-verification-status is-error';
        status.textContent = error.message;
      } finally {
        setBusy(false);
      }
    });
    code.addEventListener('input', () => { code.value = code.value.replace(/\D/g, '').slice(0, 6); });
    code.focus();
    return;
  }

  const account = form.querySelector('#basic-details-section');
  const community = form.querySelector('#profile-details-section');
  const store = form.querySelector('#koopo-vendor-section');
  const service = form.querySelector('#koopo-service-profile-section');
  const subscription = form.querySelector('#koopo-subscription-section');

  const usernameField = community?.querySelector('.field_username');
  const passwordField = account?.querySelector('.signup_password');
  if (usernameField && passwordField) account.insertBefore(usernameField, passwordField);

  const buddyFirstName = form.querySelector('.field_first-name input[type="text"]');
  const buddyLastName = form.querySelector('.field_last-name input[type="text"]');
  const configuredPhoneId = Number(window.KOOPO_PROVIDER_ONBOARDING?.phoneFieldId || 0);
  const buddyPhone = (configuredPhoneId ? form.querySelector(`#field_${configuredPhoneId}`) : null)
    || form.querySelector('.field_phone input[type="tel"], .field_phone input[type="text"]');
  const enforcePhoneRequired = () => {
    if (!buddyPhone) return;
    // BuddyBoss may remove the native required attribute after its own field
    // setup. Keep this callback idempotent: an unconditional setAttribute()
    // from inside the observer recursively queues another mutation and can
    // starve the page's main thread.
    if (!buddyPhone.required) buddyPhone.required = true;
    if (buddyPhone.getAttribute('aria-required') !== 'true') buddyPhone.setAttribute('aria-required', 'true');
  };
  if (buddyPhone) {
    enforcePhoneRequired();
    new MutationObserver(() => {
      if (!buddyPhone.hasAttribute('required')) enforcePhoneRequired();
    }).observe(buddyPhone, {attributes: true, attributeFilter: ['required']});
    buddyPhone.closest('.editfield')?.classList.remove('optional-field');
    buddyPhone.closest('fieldset')?.querySelector('.bp-optional-field-label')?.remove();
    const phoneLegend = buddyPhone.closest('fieldset')?.querySelector('legend');
    if (phoneLegend && !phoneLegend.querySelector('.koopo-required-mark')) phoneLegend.insertAdjacentHTML('beforeend', ' <b class="koopo-required-mark" aria-hidden="true">*</b>');
  }
  const panels = [
    {node: account, label: 'Account', hint: 'Sign-in details'},
    {node: community, label: 'About you', hint: 'Community profile'},
    {node: service, label: 'Services', hint: 'Public profile'},
    {node: store, label: 'Store', hint: 'Selling details'},
    {node: subscription, label: 'Plan', hint: 'Subscription'},
  ].filter((step) => step.node);
  if (panels.length < 3) return;

  const nativeSubmit = form.querySelector('[name="signup_submit"]');
  const submitWrap = nativeSubmit ? nativeSubmit.closest('.submit') || nativeSubmit.parentElement : null;
  const layout = form.querySelector('.layout-wrap');
  if (!nativeSubmit || !layout) return;

  document.body.classList.add('koopo-onboarding-enhanced');
  nativeSubmit.value = 'Register';
  if (nativeSubmit.tagName === 'BUTTON') nativeSubmit.textContent = 'Register';

  const shell = document.createElement('div');
  shell.className = 'koopo-onboard-shell';
  shell.innerHTML = `
    <aside class="koopo-onboard-rail">
      <a class="koopo-onboard-brand" href="${window.KOOPO_PROVIDER_ONBOARDING?.bookUrl || '/bookable/'}"><span>Koopo</span><small>Provider setup</small></a>
      <div><span class="koopo-onboard-eyebrow">Start taking appointments</span><h1>Build the profile customers can book.</h1><p>One account for your community identity, storefront, services, and calendar.</p></div>
      <ol class="koopo-onboard-progress" aria-label="Registration progress"></ol>
      <small class="koopo-onboard-trust">Your profile is not published until you verify your email.</small>
    </aside>
    <div class="koopo-onboard-stage"><div class="koopo-onboard-account-link">Already have an account? <a href="${window.KOOPO_PROVIDER_ONBOARDING?.loginUrl || '/wp-login.php'}">Sign in</a></div><div class="koopo-onboard-mobile-progress" aria-live="polite"></div><div class="koopo-onboard-panel"></div><div class="koopo-onboard-actions"><button type="button" class="koopo-onboard-back">Back</button><button type="button" class="koopo-onboard-next">Continue</button></div></div>`;
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

  const displayNameLabels = {
    first_and_lastname: 'First and last name',
    first_last_name: 'First and last name',
    first_name: 'First name',
    username: 'Username',
    company_name: 'Company name',
    nickname: 'Nickname',
    alias: 'Alias',
  };
  form.querySelectorAll('select option').forEach((option) => {
    const raw = String(option.textContent || option.value || '').trim().toLowerCase();
    if (displayNameLabels[raw]) option.textContent = displayNameLabels[raw];
  });

  const firstError = panels.findIndex((step) => step.node.querySelector('.error, .invalid, [aria-invalid="true"]'));
  if (firstError >= 0) current = firstError;

  function show(index, focus = false) {
    enforcePhoneRequired();
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
    enforcePhoneRequired();
    if (buddyPhone && panels[current].node.contains(buddyPhone)) {
      buddyPhone.setCustomValidity(buddyPhone.value.trim() ? '' : 'Phone number is required for provider accounts.');
    }
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
    if (validateCurrent()) {
      syncIdentity();
      show(current + 1, true);
    }
  });
  back.addEventListener('click', () => show(current - 1, true));
  progress.addEventListener('click', (event) => {
    const button = event.target.closest('[data-step]');
    if (button && Number(button.dataset.step) <= current) show(Number(button.dataset.step), true);
  });

  const storeName = form.querySelector('[name="koopo_store_name"]');
  const storeSlug = form.querySelector('[name="koopo_store_slug"]');
  const profileName = form.querySelector('[name="koopo_profile_name"]');
  const vendorFirstName = form.querySelector('[name="koopo_first_name"]');
  const vendorLastName = form.querySelector('[name="koopo_last_name"]');
  const vendorPhone = form.querySelector('[name="koopo_vendor_phone"]');
  let slugEdited = Boolean(storeSlug?.value);
  let storeNameEdited = Boolean(storeName?.value);
  const slugify = (value) => value.toLowerCase().trim().replace(/['’]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60);
  storeSlug?.addEventListener('input', () => { slugEdited = Boolean(storeSlug.value); });
  storeName?.addEventListener('input', () => {
    storeNameEdited = Boolean(storeName.value);
    if (storeSlug && !slugEdited) storeSlug.value = slugify(storeName.value);
  });
  profileName?.addEventListener('input', () => {
    if (storeName && !storeNameEdited) storeName.value = profileName.value;
    if (storeSlug && !slugEdited) storeSlug.value = slugify(profileName.value);
  });
  const syncIdentity = () => {
    const liveBuddyPhone = (configuredPhoneId ? form.querySelector(`#field_${configuredPhoneId}`) : null) || buddyPhone;
    if (vendorFirstName && !vendorFirstName.value) vendorFirstName.value = buddyFirstName?.value || '';
    if (vendorLastName && !vendorLastName.value) vendorLastName.value = buddyLastName?.value || '';
    if (vendorPhone && !vendorPhone.value) vendorPhone.value = liveBuddyPhone?.value || '';
  };
  [buddyFirstName, buddyLastName, buddyPhone].forEach((field) => field?.addEventListener('input', syncIdentity));
  syncIdentity();

  form.querySelectorAll('.koopo-onboard-plan input').forEach((radio) => {
    radio.addEventListener('change', () => {
      form.querySelectorAll('.koopo-onboard-plan').forEach((plan) => plan.classList.toggle('is-selected', Boolean(plan.querySelector('input:checked'))));
    });
  });
  form.querySelectorAll('.koopo-onboard-plan__toggle').forEach((toggle) => {
    toggle.addEventListener('click', () => {
      const features = document.getElementById(toggle.getAttribute('aria-controls'));
      if (!features) return;
      const expanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      toggle.textContent = expanded ? toggle.dataset.showLabel : toggle.dataset.hideLabel;
      features.hidden = expanded;
    });
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
