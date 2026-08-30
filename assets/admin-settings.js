(() => {
  const form = document.querySelector('.koopo-admin-settings form');
  if (!form) return;

  const saveBar = document.querySelector('.koopo-admin-settings__save');
  const saveState = saveBar?.querySelector('[data-koopo-save-state]');
  const markDirty = () => {
    saveBar?.classList.add('is-dirty');
    if (saveState) saveState.textContent = 'Unsaved changes';
  };

  form.addEventListener('change', markDirty);
  form.addEventListener('input', markDirty);
  document.querySelectorAll('[data-koopo-feature-toggle]').forEach((input) => {
    const sync = () => {
      const card = input.closest('[data-koopo-feature-card]');
      const status = card?.querySelector('[data-koopo-feature-status]');
      card?.classList.toggle('is-enabled', input.checked);
      card?.classList.toggle('is-disabled', !input.checked);
      if (status) status.textContent = input.checked ? 'Enabled' : 'Hidden';
    };
    input.addEventListener('change', sync);
    sync();
  });

  const links = [...document.querySelectorAll('[data-koopo-settings-nav]')];
  const sections = links.map((link) => document.querySelector(link.getAttribute('href'))).filter(Boolean);
  if ('IntersectionObserver' in window && sections.length) {
    const observer = new IntersectionObserver((entries) => {
      const visible = entries.filter((entry) => entry.isIntersecting).sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
      if (!visible) return;
      links.forEach((link) => link.classList.toggle('is-active', link.getAttribute('href') === `#${visible.target.id}`));
    }, {rootMargin: '-18% 0px -68% 0px', threshold: [0, .15, .4]});
    sections.forEach((section) => observer.observe(section));
  }
})();
