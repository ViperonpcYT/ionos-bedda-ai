(function () {
  function addSocialProof(card) {
    if (card.querySelector('[data-social-proof]')) return;

    const bike = card.dataset.bike || 'e-moto';
    const label = bike === 'universal'
      ? 'Popular add-on for e-moto riders'
      : `Popular with ${bike.replace('-', ' ').toUpperCase()} riders`;

    const proof = document.createElement('p');
    proof.dataset.socialProof = 'true';
    proof.className = 'mt-3 text-xs text-zinc-500';
    proof.textContent = label;

    const fitment = card.querySelector('p.text-green-300');
    if (fitment) {
      fitment.insertAdjacentElement('afterend', proof);
    }
  }

  function addCartReservationCue() {
    const cartModal = document.getElementById('cart-modal');
    if (!cartModal || cartModal.querySelector('[data-reservation-cue]')) return;

    const cue = document.createElement('p');
    cue.dataset.reservationCue = 'true';
    cue.className = 'text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2 my-2';
    cue.textContent = 'Items are not reserved until checkout is complete.';

    const target = cartModal.querySelector('#cart-items') || cartModal.querySelector('.cart-items');
    if (target) target.insertAdjacentElement('beforebegin', cue);
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.product-card').forEach(addSocialProof);

    const observer = new MutationObserver(addCartReservationCue);
    observer.observe(document.body, { childList: true, subtree: true });
    addCartReservationCue();
  });
})();
