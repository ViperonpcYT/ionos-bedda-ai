import { useCart } from '@/context/CartContext';

export function StickyMobileCart() {
  const { openCart } = useCart();

  return (
    <div className="fixed bottom-3 left-3 right-3 z-[45] flex items-center justify-between gap-4 rounded-2xl border border-green-500/40 bg-[#050507]/95 p-3 shadow-[0_16px_50px_rgba(0,0,0,0.5)] backdrop-blur-md md:hidden">
      <div className="min-w-0 flex-1">
        <div className="truncate text-sm font-black text-zinc-100">
          Ready to upgrade?
        </div>
        <div className="text-xs text-zinc-500">Open bag anytime</div>
      </div>
      <button
        type="button"
        id="mobile-sticky-cart"
        className="ob-btn ob-btn-primary shrink-0 px-4 py-2 text-sm"
        onClick={openCart}
      >
        View bag
      </button>
    </div>
  );
}
