(function () {
  function initAnimations() {
    const items = document.querySelectorAll('[data-animate]');
    if (!items.length) return;
    if (!('IntersectionObserver' in window)) { items.forEach(el => el.classList.add('is-visible')); return; }
    const io = new IntersectionObserver((entries) => entries.forEach(entry => { if (entry.isIntersecting) { entry.target.classList.add('is-visible'); io.unobserve(entry.target); } }), { threshold: 0.15 });
    items.forEach(el => io.observe(el));
  }
  function initMobileMenuLock() {
    const btn = document.getElementById('mobile-menu-btn') || document.getElementById('mobile-menu-toggle');
    const menu = document.getElementById('mobile-menu') || document.getElementById('mobile-menu-panel');
    if (!btn || !menu) return;
    btn.addEventListener('click', () => setTimeout(() => document.body.classList.toggle('overflow-hidden', menu.classList.contains('open')), 0));
    menu.querySelectorAll('a,button').forEach(el => el.addEventListener('click', () => document.body.classList.remove('overflow-hidden')));
  }
  function initProductFilters() {
    const buttons = document.querySelectorAll('[data-filter]');
    const cards = document.querySelectorAll('.product-card');
    if (!buttons.length || !cards.length) return;
    const params = new URLSearchParams(window.location.search);
    const initial = params.get('bike') || params.get('category') || params.get('filter') || 'all';
    function apply(filter) {
      cards.forEach(card => {
        const cats = (card.dataset.category || '').split(/\s+/);
        const show = filter === 'all' || cats.includes(filter) || card.dataset.bike === filter;
        card.classList.toggle('hidden', !show);
      });
      buttons.forEach(btn => {
        const active = btn.dataset.filter === filter;
        btn.classList.toggle('bg-green-500', active);
        btn.classList.toggle('text-zinc-950', active);
        btn.classList.toggle('border-green-400', active);
      });
    }
    buttons.forEach(btn => btn.addEventListener('click', () => apply(btn.dataset.filter)));
    apply(initial);
  }
  function initProductDetailsToggle() {
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-details-toggle]');
      if (!btn) return;
      
      const card = btn.closest('.product-card');
      if (!card) return;
      
      const desc = card.querySelector('.product-desc');
      const details = card.querySelector('.product-details');
      
      if (!desc || !details) return;
      
      const detailsHidden = details.classList.contains('hidden');
      
      if (detailsHidden) {
        details.classList.remove('hidden');
        desc.classList.add('hidden');
        btn.textContent = '← Back to overview';
        btn.setAttribute('aria-expanded', 'true');
      } else {
        details.classList.add('hidden');
        desc.classList.remove('hidden');
        btn.textContent = 'Dimensions & details →';
        btn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    initAnimations();
    initMobileMenuLock();
    initProductFilters();
    initProductDetailsToggle();
  });
})();
