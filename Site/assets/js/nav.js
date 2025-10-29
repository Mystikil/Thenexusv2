(function () {
  const nav = document.querySelector('.nx-nav');
  const burger = document.querySelector('.nx-burger');
  const menu = document.getElementById('nx-menu');
  let lastY = 0;

  window.addEventListener('scroll', () => {
    const y = window.scrollY || 0;
    if (nav) {
      nav.classList.toggle('is-scrolled', y > 2);
    }
    lastY = y;
  });

  if (!burger || !menu) {
    return;
  }

  const closeAllSubmenus = () => {
    document
      .querySelectorAll('.nx-has-submenu > .nx-link[aria-expanded="true"]')
      .forEach((btn) => btn.setAttribute('aria-expanded', 'false'));
  };

  burger.addEventListener('click', () => {
    const open = menu.classList.toggle('is-open');
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (!open) {
      closeAllSubmenus();
    }
  });

  menu.addEventListener('click', (e) => {
    const btn = e.target.closest('.nx-has-submenu > .nx-link');
    if (!btn) {
      return;
    }
    const expanded = btn.getAttribute('aria-expanded') === 'true';
    btn
      .closest('.nx-item')
      .parentElement.querySelectorAll(':scope > .nx-has-submenu > .nx-link')
      .forEach((b) => {
        if (b !== btn) {
          b.setAttribute('aria-expanded', 'false');
        }
      });
    btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    e.preventDefault();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeAllSubmenus();
      menu.classList.remove('is-open');
      burger.setAttribute('aria-expanded', 'false');
    }
  });

  document.addEventListener('click', (e) => {
    if (!menu.contains(e.target) && !burger.contains(e.target)) {
      closeAllSubmenus();
      menu.classList.remove('is-open');
      burger.setAttribute('aria-expanded', 'false');
    }
  });
})();
