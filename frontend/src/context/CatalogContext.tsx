import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  productMatchesFilters,
  PRODUCTS_DATA,
  type BikeFilter,
  type ProductRecord,
  type RoleFilter,
} from '@/data/products';

type CatalogContextValue = {
  bikeFilter: BikeFilter;
  roleFilter: RoleFilter;
  setBikeFilter: (f: BikeFilter) => void;
  setRoleFilter: (f: RoleFilter) => void;
  visibleProducts: ProductRecord[];
  expandedPanes: Record<string, 'desc' | 'details'>;
  toggleProductPane: (productId: string) => void;
};

const CatalogContext = createContext<CatalogContextValue | null>(null);

function parseBikeFilter(raw: string | null): BikeFilter {
  if (raw === 'surron' || raw === 'talaria' || raw === 'eride') return raw;
  return 'all';
}

function parseRoleFilter(
  params: URLSearchParams,
): RoleFilter {
  const category = params.get('category');
  const filter = params.get('filter');
  if (filter === 'soon') return 'soon';
  if (
    category === 'build' ||
    category === 'style' ||
    category === 'maintenance'
  ) {
    return category;
  }
  return 'all';
}

export function CatalogProvider({ children }: { children: ReactNode }) {
  const [searchParams, setSearchParams] = useSearchParams();
  const [expandedPanes, setExpandedPanes] = useState<
    Record<string, 'desc' | 'details'>
  >({});

  const bikeFilter = parseBikeFilter(searchParams.get('bike'));
  const roleFilter = parseRoleFilter(searchParams);

  const setBikeFilter = useCallback(
    (f: BikeFilter) => {
      const next = new URLSearchParams(searchParams);
      if (f === 'all') next.delete('bike');
      else next.set('bike', f);
      setSearchParams(next, { replace: true });
    },
    [searchParams, setSearchParams],
  );

  const setRoleFilter = useCallback(
    (f: RoleFilter) => {
      const next = new URLSearchParams(searchParams);
      next.delete('category');
      next.delete('filter');
      if (f === 'soon') next.set('filter', 'soon');
      else if (f !== 'all') next.set('category', f);
      setSearchParams(next, { replace: true });
    },
    [searchParams, setSearchParams],
  );

  const visibleProducts = useMemo(
    () =>
      PRODUCTS_DATA.filter((p) =>
        productMatchesFilters(p, bikeFilter, roleFilter),
      ),
    [bikeFilter, roleFilter],
  );

  const toggleProductPane = useCallback((productId: string) => {
    setExpandedPanes((prev) => {
      const current = prev[productId] ?? 'desc';
      return {
        ...prev,
        [productId]: current === 'desc' ? 'details' : 'desc',
      };
    });
  }, []);

  const value = useMemo(
    () => ({
      bikeFilter,
      roleFilter,
      setBikeFilter,
      setRoleFilter,
      visibleProducts,
      expandedPanes,
      toggleProductPane,
    }),
    [
      bikeFilter,
      roleFilter,
      setBikeFilter,
      setRoleFilter,
      visibleProducts,
      expandedPanes,
      toggleProductPane,
    ],
  );

  return (
    <CatalogContext.Provider value={value}>{children}</CatalogContext.Provider>
  );
}

export function useCatalog() {
  const ctx = useContext(CatalogContext);
  if (!ctx) throw new Error('useCatalog must be used within CatalogProvider');
  return ctx;
}
