import { useCatalog } from '@/context/CatalogContext';
import { useCart } from '@/context/CartContext';
import { formatPrice, type ProductRecord } from '@/data/products';
import { ComingSoonVisual } from './ComingSoonVisual';

function stockLabel(product: ProductRecord): string {
  if (product.comingSoon) return 'Coming soon';
  if (product.stockDisplay <= 0) return 'Out of stock';
  if (product.stockDisplay <= product.lowStock) return 'Low stock';
  return 'In stock';
}

export function ProductCard({ product }: { product: ProductRecord }) {
  const { expandedPanes, toggleProductPane } = useCatalog();
  const { addItem } = useCart();
  const pane = expandedPanes[product.id] ?? 'desc';
  const showDetails = pane === 'details';
  const canBuy = !product.comingSoon && product.price != null;

  return (
    <article
      className="product-card ob-card p-5"
      data-sku={product.sku}
      data-product-id={product.id}
      data-price={product.price ?? undefined}
      data-category={product.categories.join(' ')}
      data-bike={product.bike}
      data-stock={product.stockDisplay}
      data-low-stock={product.lowStock}
      data-coming-soon={product.comingSoon ? 'true' : undefined}
    >
      <div className="product-image relative aspect-[4/3] overflow-hidden rounded-xl bg-black">
        {product.image ? (
          <>
            <img
              src={product.image}
              alt={product.name}
              className="h-full w-full object-contain p-2"
              loading="lazy"
              decoding="async"
            />
            {product.comingSoon && (
              <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-zinc-950/65">
                <span className="text-[10px] font-black uppercase tracking-[0.35em] text-amber-200">
                  Coming soon
                </span>
              </div>
            )}
          </>
        ) : (
          <ComingSoonVisual label="Photo coming soon" />
        )}
      </div>

      <div className="mt-4 flex items-center justify-between gap-2">
        <span
          className={`ob-badge ${
            product.comingSoon
              ? 'border-amber-400/40 bg-amber-400/10 text-amber-200'
              : ''
          }`}
        >
          {product.badge}
        </span>
        <span className="text-xs text-zinc-500">{product.sublabel}</span>
      </div>

      <h3 className="mt-4 text-xl font-bold text-white">{product.name}</h3>

      <div className="product-copy mt-2 text-sm text-zinc-400" data-product-copy>
        <div
          className={`product-desc ${showDetails ? 'hidden' : ''}`}
          data-copy-pane="desc"
          dangerouslySetInnerHTML={{ __html: product.overviewHtml }}
        />
        <div
          className={`product-details ${showDetails ? '' : 'hidden'}`}
          data-copy-pane="details"
        >
          <dl className="space-y-2 text-xs">
            {product.details.map((row) =>
              row.fullWidth ? (
                <div key={row.label} className="pt-2">
                  <dt className="mb-1 text-zinc-500">{row.label}</dt>
                  <dd className="text-zinc-300">{row.value}</dd>
                </div>
              ) : (
                <div
                  key={row.label}
                  className="flex justify-between gap-3 border-b border-zinc-800 pb-2"
                >
                  <dt className="text-zinc-500">{row.label}</dt>
                  <dd className="text-right text-zinc-200">{row.value}</dd>
                </div>
              ),
            )}
          </dl>
        </div>
      </div>

      <button
        type="button"
        className="product-details-toggle"
        data-details-toggle
        aria-expanded={showDetails}
        onClick={() => toggleProductPane(product.id)}
      >
        {showDetails ? '← Back to overview' : 'Dimensions & details →'}
      </button>

      <div className="mt-4 flex items-end justify-between gap-2">
        <span
          className={`text-2xl font-black ${
            product.comingSoon ? 'text-zinc-500' : 'text-green-400'
          }`}
          data-display-price
        >
          {/* Presentation only — validated server-side */}
          {formatPrice(product.price)}
        </span>
        <span className="text-xs text-zinc-500" data-stock-badge>
          {stockLabel(product)}
        </span>
      </div>

      {canBuy ? (
        <button
          type="button"
          className="add-to-cart ob-btn ob-btn-primary mt-5 w-full"
          data-product={product.cartName}
          data-price={product.price!}
          onClick={() => addItem(product.cartName, product.price!)}
        >
          Add to bag
        </button>
      ) : (
        <button
          type="button"
          className="add-to-cart ob-btn ob-btn-ghost mt-5 w-full cursor-not-allowed opacity-60"
          disabled
        >
          Coming soon
        </button>
      )}
    </article>
  );
}
