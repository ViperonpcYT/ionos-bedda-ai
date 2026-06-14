(function () {
  function lockBodyWhenMenuOpen() {
    const btn = document.getElementById('mobile-menu-btn') || document.getElementById('mobile-menu-toggle');
    const menu = document.getElementById('mobile-menu') || document.getElementById('mobile-menu-panel');
    if (!btn || !menu) return;

    const sync = () => {
      document.body.classList.toggle('overflow-hidden', menu.classList.contains('open'));
    };

    btn.addEventListener('click', () => setTimeout(sync, 0));
    menu.querySelectorAll('a,button').forEach((el) => {
      el.addEventListener('click', () => document.body.classList.remove('overflow-hidden'));
    });
  }

  function wireStickyCart() {
    const sticky = document.getElementById('mobile-sticky-cart');
    if (!sticky) return;

    sticky.addEventListener('click', () => {
      const cart = document.getElementById('cart-btn') || document.getElementById('cart-button');
      if (cart) cart.click();
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    lockBodyWhenMenuOpen();
    wireStickyCart();
  });
})();
