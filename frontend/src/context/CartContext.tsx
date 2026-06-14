import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';

const CART_KEY = 'onlybikes_cart';

export type CartLine = {
  id: string;
  product: string;
  price: number;
  quantity: number;
};

type CartContextValue = {
  items: CartLine[];
  count: number;
  addItem: (product: string, price: number, quantity?: number) => void;
  openCart: () => void;
};

const CartContext = createContext<CartContextValue | null>(null);

function loadCart(): CartLine[] {
  try {
    const raw =
      localStorage.getItem(CART_KEY) ?? localStorage.getItem('bedda_cart');
    if (!raw) return [];
    const parsed = JSON.parse(raw) as CartLine[];
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

function syncLegacyCartCount(count: number) {
  const el = document.getElementById('cart-count');
  if (el) {
    el.textContent = String(count);
    (el as HTMLElement).style.display = count > 0 ? 'flex' : 'none';
  }
}

export function CartProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<CartLine[]>(() => loadCart());

  const persist = useCallback((next: CartLine[]) => {
    setItems(next);
    localStorage.setItem(CART_KEY, JSON.stringify(next));
    const count = next.reduce((s, i) => s + i.quantity, 0);
    syncLegacyCartCount(count);
    if (window.cartManager) {
      window.cartManager.items = next.map((line) => ({
        product: line.product,
        price: line.price,
        quantity: line.quantity,
        id: line.id,
        isSubscription: false,
        subscriptionInterval: null,
      }));
      window.cartManager.saveCart?.();
      window.cartManager.renderCartCount?.();
    }
  }, []);

  const count = useMemo(
    () => items.reduce((sum, item) => sum + item.quantity, 0),
    [items],
  );

  useEffect(() => {
    syncLegacyCartCount(count);
  }, [count]);

  const addItem = useCallback(
    (product: string, price: number, quantity = 1) => {
      if (window.cartManager?.addItem) {
        window.cartManager.addItem(product, price, quantity);
        setItems(loadCart());
        return;
      }
      const prev = loadCart();
      const existing = prev.find((i) => i.product === product);
      const next: CartLine[] = existing
        ? prev.map((i) =>
            i.product === product
              ? { ...i, quantity: i.quantity + quantity }
              : i,
          )
        : [
            ...prev,
            {
              id: `${Date.now()}${Math.random().toString(36).slice(2, 7)}`,
              product,
              price,
              quantity,
            },
          ];
      persist(next);
      if (window.beddaLogger?.logEvent) {
        window.beddaLogger.logEvent('add_to_cart', { product, price, quantity });
      }
    },
    [persist],
  );

  const openCart = useCallback(() => {
    if (window.cartManager?.toggleCartModal) {
      window.cartManager.toggleCartModal();
      return;
    }
    document.getElementById('cart-btn')?.click();
  }, []);

  const value = useMemo(
    () => ({ items, count, addItem, openCart }),
    [items, count, addItem, openCart],
  );

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart() {
  const ctx = useContext(CartContext);
  if (!ctx) throw new Error('useCart must be used within CartProvider');
  return ctx;
}
