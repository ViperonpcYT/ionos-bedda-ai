export {};

declare global {
  interface Window {
    ONLYBIKES_CONFIG?: {
      cartStorageKey?: string;
      siteUrl?: string;
    };
    cartManager?: {
      items: Array<{
        product: string;
        price: number;
        quantity: number;
        id: string;
        isSubscription?: boolean;
        subscriptionInterval?: string | null;
      }>;
      addItem: (product: string, price: number, quantity?: number) => void;
      saveCart?: () => void;
      renderCartCount?: () => void;
      toggleCartModal?: () => void;
    };
    beddaLogger?: {
      logEvent: (name: string, data?: Record<string, unknown>) => void;
    };
  }
}
