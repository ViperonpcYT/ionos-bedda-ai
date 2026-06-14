/**
 * OnlyBikes public storefront config.
 * Safe to commit: no secrets here. Server secrets belong in api/.env.
 */
window.ONLYBIKES_CONFIG = {
  brandName: 'OnlyBikes',
  tagline: 'E-moto parts and upgrades',
  // Canonical public URL (used for emails/SEO). API calls use window.location.origin on the live site.
  siteOrigin: 'https://onlybikes.shop',
  orderPrefix: 'OB',
  supportEmail: 'support@onlybikes.shop',
  // PLACEHOLDER: add real social links when accounts are created.
  instagram: '',
  youtube: '',
  rewardsName: 'OnlyBikes Rewards',
  cartStorageKey: 'onlybikes_cart',
  authStorageKey: 'onlybikes_auth_user',
  pendingOrderKey: 'onlybikes_pending_order',
  lastOrderKey: 'onlybikes_last_order'
};
function onlyBikesSiteOrigin() {
  const host = window.location.hostname;
  if (host === 'localhost' || host === '127.0.0.1') return window.location.origin;
  // Same host serves HTML + /api — never trust a stale configured placeholder domain.
  return window.location.origin;
}

/** Resolve /api/... paths on production vs local dev server */
function onlyBikesApiUrl(path) {
  const p = path.startsWith('/') ? path : '/' + path;
  const host = window.location.hostname;
  if (host === 'localhost' || host === '127.0.0.1') return p;
  return window.location.origin + p;
}
