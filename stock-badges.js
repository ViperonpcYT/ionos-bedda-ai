(function () {
  async function fetchStock(sku) {
    try {
      const path = '/api/get-stock.php?sku=' + encodeURIComponent(sku);
      const url = typeof onlyBikesApiUrl === 'function' ? onlyBikesApiUrl(path) : path;
      const res = await fetch(url, { credentials: 'same-origin' });
      return res.ok ? await res.json() : null;
    } catch (e) { return null; }
  }
  function renderBadge(card, data) {
    const slot = card.querySelector('[data-stock-badge]');
    if (!slot) return;
    if (card.dataset.comingSoon === 'true') {
      slot.textContent = 'Coming soon';
      slot.className = 'ob-badge border-amber-400/50 text-amber-200 bg-amber-400/10';
      const btn = card.querySelector('.add-to-cart');
      if (btn) { btn.disabled = true; btn.textContent = 'Coming soon'; btn.classList.add('opacity-60', 'cursor-not-allowed'); }
      return;
    }
    const available = Number(data?.available ?? data?.stock_in_stock ?? card.dataset.stock ?? 0);
    const low = Number(data?.low_stock_threshold ?? card.dataset.lowStock ?? 5);
    if (available <= 0) {
      slot.textContent = 'Sold out'; slot.className = 'ob-badge border-red-500/40 text-red-200 bg-red-500/10';
      const btn = card.querySelector('.add-to-cart'); if (btn) { btn.disabled = true; btn.textContent = 'Sold out'; btn.classList.add('opacity-50', 'cursor-not-allowed'); }
    } else if (available <= low) { slot.textContent = 'Only ' + available + ' left'; slot.className = 'ob-badge border-amber-400/50 text-amber-200 bg-amber-400/10'; }
    else { slot.textContent = 'In stock'; slot.className = 'ob-badge'; }
  }
  document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('.product-card[data-sku]').forEach(async card => renderBadge(card, await fetchStock(card.dataset.sku))));
})();
