/**
 * OnlyBikes Enterprise Application Architecture
 * Senior Frontend Engineer Implementation
 * 
 * Architecture: Modular ES6+ with centralized state management
 * Pattern: State -> Actions -> Render Cycle
 * Analytics: Comprehensive event tracking with batch processing
 * Security: Client-side validation + honeypot protection
 */

// ============================================================================
// SECTION 1: CENTRALIZED DATA LAYER - PRODUCTS_DATA
// ============================================================================

const PRODUCTS_DATA = [
  {
    sku: "OB-BRAKE-UBEE-001",
    productId: "ob-brake-ubee-001",
    name: "Ultra Bee Brake Kit",
    price: 349.99,
    category: ["build", "braking", "surron", "universal"],
    bike: "surron",
    stock: 12,
    lowStock: 5,
    badge: "Build center",
    subBadge: "Braking",
    image: "images/products/Ultra-Bee-Brake.png",
    comingSoon: false,
    description: {
      short: "Rear moto brake upgrade for Sur-Ron Ultra Bee — stronger stopping power, improved bite, and a solid foundation for aggressive riding. Direct replacement when you verify mount, rotor, and line routing.",
      verify: "Sur-Ron Ultra Bee · confirm fitment before checkout"
    },
    details: [
      { label: "Position", value: "Rear (verify front/rear kit contents)" },
      { label: "Typical fit", value: "Sur-Ron Ultra Bee" },
      { label: "Includes", value: "Caliper, bracket, hardware — verify listing" },
      { label: "You verify", value: "Rotor diameter, mount spacing, brake line" }
    ]
  },
  {
    sku: "OB-BOLT-TI-001",
    productId: "ob-bolt-ti-001",
    name: "Titanium Bolt Kit",
    price: 89.99,
    category: ["build", "hardware", "surron", "universal"],
    bike: "surron",
    stock: 20,
    lowStock: 6,
    badge: "Build center",
    subBadge: "Hardware",
    image: "images/products/Surron%20Titanium%20Bolts.png",
    comingSoon: false,
    description: {
      short: "Lightweight titanium hardware for visible fasteners — cleaner look, corrosion resistance, and dress-up weight savings on compatible Sur-Ron builds."
    },
    details: [
      { label: "Material", value: "Grade 5 titanium (typical)" },
      { label: "You verify", value: "Thread pitch and length vs stock hardware" }
    ]
  },
  {
    sku: "OB-WHEEL-SM-001",
    productId: "ob-wheel-sm-001",
    name: "17×1.6 Supermoto Wheel Set for Talaria & Sur-Ron",
    price: 599.99,
    category: ["style", "wheels", "surron", "talaria", "universal"],
    bike: "universal",
    stock: 6,
    lowStock: 2,
    badge: "Style add-on",
    subBadge: "Wheels",
    image: "images/products/supermoto%20wheels.jpg",
    comingSoon: false,
    description: {
      short: "Premium 17-inch supermoto wheel set for improved street handling, a wider contact patch, and stability on pavement. Front and rear complete assembly — ideal for off-road to supermoto conversions.",
      bullets: [
        "Lightweight aluminum alloy · CNC-machined hub",
        "High-strength spoke layout · corrosion-resistant finish",
        "Direct replacement when fitment is confirmed"
      ]
    },
    details: [
      { label: "Wheel diameter", value: "17 in" },
      { label: "Rim width", value: "1.6 in" },
      { label: "Material", value: "Aluminum alloy" },
      { label: "Finish", value: "Anodized / powder coat (verify color)" },
      { label: "Package", value: "Front + rear wheels · bearings/spacers/rim strips (verify)" },
      { label: "Request fitment for", value: "Sur-Ron Light Bee X · Talaria Sting MX3/MX4 · Talaria XXX · E-Ride Pro · Segway X160/X260" }
    ]
  },
  {
    sku: "OB-LIGHT-BAJA-001",
    productId: "ob-light-baja-001",
    name: "3-Inch Baja Style LED Headlight",
    price: 49.99,
    category: ["style", "lighting", "surron", "talaria", "universal"],
    bike: "universal",
    stock: 18,
    lowStock: 6,
    badge: "Style add-on",
    subBadge: "Lighting",
    image: "images/products/Baja%20Headlight.jpg",
    comingSoon: false,
    description: {
      short: "Compact high-output 3-inch Baja-style LED auxiliary light — aluminum housing, weather-resistant build, and strong low-light visibility for e-moto and off-road setups.",
      bullets: [
        "Projector LED · shock-resistant design",
        "Low draw · long service life (verify spec)",
        "Bracket + harness in box (verify)"
      ]
    },
    details: [
      { label: "Lens size", value: "3 in" },
      { label: "Housing", value: "Aluminum alloy" },
      { label: "Waterproof", value: "IP67–IP68 (verify exact unit)" },
      { label: "Voltage", value: "12V–72V (verify)" },
      { label: "Typical fit", value: "Sur-Ron LBX / Ultra Bee · Talaria Sting / XXX · E-Ride Pro · Segway X260 · universal bars" }
    ]
  },
  {
    sku: "OB-TIRE-OFFROAD-001",
    productId: "ob-tire-offroad-001",
    name: "Front 70/100-19 & 90/100-16 Rear Tire + Tube for Off Road Dirt",
    price: 169.99,
    category: ["maintenance", "tires", "surron", "talaria", "universal"],
    bike: "universal",
    stock: 10,
    lowStock: 3,
    badge: "Maintenance",
    subBadge: "Tires",
    image: "images/products/70-90-100%20Tires.png",
    comingSoon: false,
    description: {
      short: "Aggressive off-road rubber bundle — deep tread for loose dirt, gravel, and mud. Front 70/100-19 plus rear 90/100-16 with tubes for dirt and trail riding.",
      bullets: [
        "Reinforced sidewall · high-traction tread",
        "Front sized for 19 in rim · verify rear clearance"
      ]
    },
    details: [
      { label: "Front tire", value: "70/100-19 · 19 in rim · 70 mm width" },
      { label: "Rear tire", value: "90/100-16 + tube" },
      { label: "Construction", value: "Bias ply (verify ply rating)" },
      { label: "Typical fit", value: "Sur-Ron Ultra Bee · Talaria MX · 19 in front dirt setups" }
    ]
  },
  {
    sku: "OB-BRAKE-PAD-001",
    productId: "ob-brake-pad-001",
    name: "Rear Brake Pads for Sur-Ron",
    price: 54.99,
    category: ["maintenance", "braking", "surron", "universal", "soon"],
    bike: "surron",
    stock: 0,
    lowStock: 0,
    badge: "Maintenance",
    subBadge: "Brakes",
    image: "images/products/Surron%20Brake%20Pads.jpg",
    comingSoon: true,
    description: {
      short: "High-friction rear brake pads to restore stopping power — wear-resistant compound, reduced fade, and consistent wet/dry performance when fitment is confirmed.",
      status: "Listing preview · not available to order yet"
    },
    details: [
      { label: "Position", value: "Rear" },
      { label: "Compound", value: "Semi-metallic / sintered (verify)" },
      { label: "Package", value: "1 pair · install spring (if included)" },
      { label: "Verify fitment", value: "Sur-Ron LBX · Ultra Bee · Talaria Sting / XXX · E-Ride Pro" }
    ]
  },
  {
    sku: "OB-FENDER-UBEE-001",
    productId: "ob-fender-ubee-001",
    name: "Sur-Ron Ultra Bee Front & Rear Fender Set",
    price: 79.99,
    category: ["style", "plastics", "surron", "universal", "soon"],
    bike: "surron",
    stock: 0,
    lowStock: 0,
    badge: "Style add-on",
    subBadge: "Plastics",
    image: null,
    comingSoon: true,
    photoSoon: true,
    description: {
      short: "OEM-style front and rear fender kit — impact-resistant plastic, UV-treated finish, and factory mount points for mud and debris protection on Ultra Bee builds.",
      status: "Listing preview · photo and checkout coming soon"
    },
    details: [
      { label: "Material", value: "Injection-molded plastic" },
      { label: "Finish", value: "Matte / gloss (verify)" },
      { label: "Package", value: "Front + rear fender · hardware (verify)" },
      { label: "Compatibility", value: "Sur-Ron Ultra Bee — confirm before other models" }
    ]
  }
];

// ============================================================================
// SECTION 2: STATE MANAGEMENT STORE
// ============================================================================

class OnlyBikesStore {
  constructor() {
    this.state = {
      cart: this.loadCart(),
      activeFilter: 'all',
      activeBikeFilter: null,
      productToggleStates: new Map(), // productId -> 'desc' | 'details'
      stockData: new Map(),
      sessionId: this.generateSessionId(),
      userId: null,
      analyticsQueue: [],
      isOnline: navigator.onLine
    };
    
    this.listeners = new Map();
    this.batchFlushInterval = 5000;
    this.batchMaxSize = 30;
    
    this.init();
  }
  
  init() {
    this.setupEventListeners();
    this.startAnalyticsBatching();
    this.trackSessionStart();
  }
  
  // Unique session identification for analytics
  generateSessionId() {
    return 'sess_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
  }
  
  // LocalStorage persistence for cart
  loadCart() {
    try {
      const stored = localStorage.getItem('onlybikes_cart');
      return stored ? JSON.parse(stored) : [];
    } catch (e) {
      return [];
    }
  }
  
  saveCart() {
    try {
      localStorage.setItem('onlybikes_cart', JSON.stringify(this.state.cart));
    } catch (e) {
      console.warn('Failed to persist cart:', e);
    }
  }
  
  // Reactive state updates
  setState(key, value) {
    const oldValue = this.state[key];
    this.state[key] = value;
    this.notify(key, value, oldValue);
  }
  
  subscribe(key, callback) {
    if (!this.listeners.has(key)) {
      this.listeners.set(key, new Set());
    }
    this.listeners.get(key).add(callback);
    
    // Return unsubscribe function
    return () => this.listeners.get(key)?.delete(callback);
  }
  
  notify(key, value, oldValue) {
    this.listeners.get(key)?.forEach(cb => cb(value, oldValue));
  }
  
  // Cart operations with analytics
  addToCart(product, quantity = 1) {
    const existing = this.state.cart.find(item => item.sku === product.sku);
    
    if (existing) {
      existing.quantity += quantity;
    } else {
      this.state.cart.push({
        sku: product.sku,
        name: product.name,
        price: product.price,
        quantity: quantity,
        addedAt: Date.now()
      });
    }
    
    this.saveCart();
    this.notify('cart', this.state.cart);
    this.trackEvent('add_to_cart', {
      sku: product.sku,
      name: product.name,
      price: product.price,
      quantity: quantity
    });
  }
  
  removeFromCart(sku) {
    const removed = this.state.cart.find(item => item.sku === sku);
    this.state.cart = this.state.cart.filter(item => item.sku !== sku);
    this.saveCart();
    this.notify('cart', this.state.cart);
    
    if (removed) {
      this.trackEvent('remove_from_cart', { sku, name: removed.name });
    }
  }
  
  getCartCount() {
    return this.state.cart.reduce((sum, item) => sum + item.quantity, 0);
  }
  
  getCartTotal() {
    return this.state.cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
  }
  
  // Product toggle state management
  toggleProductDetails(productId) {
    const current = this.state.productToggleStates.get(productId) || 'desc';
    const next = current === 'desc' ? 'details' : 'desc';
    this.state.productToggleStates.set(productId, next);
    this.notify('productToggle', { productId, state: next });
    
    this.trackEvent('product_toggle', {
      productId,
      state: next,
      sku: PRODUCTS_DATA.find(p => p.productId === productId)?.sku
    });
    
    return next;
  }
  
  getProductToggleState(productId) {
    return this.state.productToggleStates.get(productId) || 'desc';
  }
  
  // Filter state
  setFilter(filter) {
    this.setState('activeFilter', filter);
    this.trackEvent('filter_change', { filter });
  }
  
  // Stock management
  async fetchStock(sku) {
    if (this.state.stockData.has(sku)) {
      return this.state.stockData.get(sku);
    }
    
    try {
      const response = await fetch(`/api/get-stock.php?sku=${encodeURIComponent(sku)}`);
      const data = await response.json();
      this.state.stockData.set(sku, data);
      return data;
    } catch (e) {
      // Graceful degradation - use embedded data
      const product = PRODUCTS_DATA.find(p => p.sku === sku);
      return { available: product?.stock || 0, sku };
    }
  }
  
  // ==========================================================================
  // SECTION 3: ANALYTICS ENGINE (Enterprise-grade)
  // ==========================================================================
  
  setupEventListeners() {
    // Online/offline detection
    window.addEventListener('online', () => {
      this.state.isOnline = true;
      this.flushAnalyticsQueue();
    });
    
    window.addEventListener('offline', () => {
      this.state.isOnline = false;
    });
    
    // Page visibility changes
    document.addEventListener('visibilitychange', () => {
      this.trackEvent(document.hidden ? 'tab_hidden' : 'tab_visible', {
        timeOnPage: Date.now() - this.pageLoadTime
      });
    });
    
    // Page exit tracking
    window.addEventListener('beforeunload', () => {
      this.trackEvent('page_exit', {
        timeOnPage: Date.now() - this.pageLoadTime,
        scrollDepth: this.getScrollDepth(),
        cartCount: this.getCartCount()
      }, true); // Use sendBeacon for reliability
    });
    
    // Scroll depth tracking
    this.setupScrollTracking();
    
    // Click tracking
    this.setupClickTracking();
  }
  
  pageLoadTime = Date.now();
  scrollMilestones = new Set();
  
  setupScrollTracking() {
    const milestones = [25, 50, 75, 90];
    
    const checkScroll = () => {
      const depth = this.getScrollDepth();
      milestones.forEach(m => {
        if (depth >= m && !this.scrollMilestones.has(m)) {
          this.scrollMilestones.add(m);
          this.trackEvent(`scroll_${m}`, { depth, timestamp: Date.now() });
        }
      });
    };
    
    let ticking = false;
    window.addEventListener('scroll', () => {
      if (!ticking) {
        window.requestAnimationFrame(() => {
          checkScroll();
          ticking = false;
        });
        ticking = true;
      }
    }, { passive: true });
  }
  
  getScrollDepth() {
    const docHeight = document.documentElement.scrollHeight - window.innerHeight;
    if (docHeight <= 0) return 100;
    return Math.round((window.scrollY / docHeight) * 100);
  }
  
  setupClickTracking() {
    document.addEventListener('click', (e) => {
      const target = e.target.closest('[data-track]') || e.target.closest('button, a');
      if (!target) return;
      
      const elementPath = this.getElementPath(target);
      const text = target.textContent?.trim().substring(0, 50) || '';
      
      this.trackEvent('click', {
        element: target.tagName.toLowerCase(),
        path: elementPath,
        text: text,
        x: e.clientX,
        y: e.clientY
      });
    });
  }
  
  getElementPath(el) {
    const path = [];
    let current = el;
    
    while (current && current !== document.body) {
      let selector = current.tagName.toLowerCase();
      if (current.id) selector += `#${current.id}`;
      if (current.className) {
        const classes = current.className.toString().split(' ').slice(0, 3).join('.');
        if (classes) selector += `.${classes}`;
      }
      path.unshift(selector);
      current = current.parentElement;
    }
    
    return path.join(' > ');
  }
  
  trackSessionStart() {
    this.trackEvent('session_start', {
      userAgent: navigator.userAgent,
      screenResolution: `${screen.width}x${screen.height}`,
      viewport: `${window.innerWidth}x${window.innerHeight}`,
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
      referrer: document.referrer,
      url: window.location.href
    });
  }
  
  trackEvent(eventType, data = {}, useBeacon = false) {
    const event = {
      event_type: eventType,
      session_id: this.state.sessionId,
      user_id: this.state.userId,
      page: window.location.pathname,
      timestamp: new Date().toISOString(),
      data: this.sanitizeAnalyticsData(data)
    };
    
    if (useBeacon && navigator.sendBeacon) {
      navigator.sendBeacon('/api/log-event.php', JSON.stringify({ events: [event] }));
      return;
    }
    
    this.state.analyticsQueue.push(event);
    
    // Immediate flush if critical event
    if (['add_to_cart', 'checkout_start', 'purchase_complete'].includes(eventType)) {
      this.flushAnalyticsQueue();
    }
  }
  
  sanitizeAnalyticsData(data) {
    if (typeof data === 'string') {
      return data.replace(/[\x00-\x1F\x7F]/g, '').substring(0, 2000);
    }
    if (Array.isArray(data)) {
      return data.map(item => this.sanitizeAnalyticsData(item));
    }
    if (typeof data === 'object' && data !== null) {
      const sanitized = {};
      for (const [key, value] of Object.entries(data)) {
        const cleanKey = key.replace(/[^a-zA-Z0-9_]/g, '').substring(0, 100);
        sanitized[cleanKey] = this.sanitizeAnalyticsData(value);
      }
      return sanitized;
    }
    return data;
  }
  
  startAnalyticsBatching() {
    setInterval(() => this.flushAnalyticsQueue(), this.batchFlushInterval);
  }
  
  async flushAnalyticsQueue() {
    if (this.state.analyticsQueue.length === 0 || !this.state.isOnline) return;
    
    const batch = this.state.analyticsQueue.splice(0, this.batchMaxSize);
    
    try {
      const response = await fetch('/api/log-event.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ events: batch })
      });
      
      if (!response.ok) throw new Error('Analytics flush failed');
    } catch (e) {
      // Re-queue failed events
      this.state.analyticsQueue.unshift(...batch);
    }
  }
}

// ============================================================================
// SECTION 4: UI COMPONENT RENDERERS
// ============================================================================

class ProductCardRenderer {
  constructor(store) {
    this.store = store;
  }
  
  render(product) {
    const toggleState = this.store.getProductToggleState(product.productId);
    const isDetailsVisible = toggleState === 'details';
    
    return `
      <div class="product-card ob-card p-5" 
           data-sku="${product.sku}"
           data-product-id="${product.productId}"
           data-price="${product.price}"
           data-category="${product.category.join(' ')}"
           data-bike="${product.bike}"
           data-stock="${product.stock}"
           data-animate>
        
        ${this.renderImage(product)}
        
        <div class="mt-4 flex items-center justify-between gap-2">
          <span class="ob-badge ${product.comingSoon ? 'border-amber-400/40 text-amber-200 bg-amber-400/10' : ''}">${product.badge}</span>
          <span class="text-xs text-zinc-500">${product.subBadge}</span>
        </div>
        
        <h3 class="mt-4 font-bold text-xl text-white">${product.name}</h3>
        
        <div class="product-copy mt-2 text-sm text-zinc-400" data-product-copy>
          ${this.renderDescription(product, isDetailsVisible)}
        </div>
        
        <button type="button" 
                class="product-details-toggle text-xs font-bold" 
                data-details-toggle 
                data-product-id="${product.productId}"
                aria-expanded="${isDetailsVisible}"
                aria-controls="details-${product.productId}">
          ${isDetailsVisible ? '← Back to overview' : 'Dimensions & details →'}
        </button>
        
        <div class="mt-4 flex items-end justify-between gap-2">
          <span class="text-2xl font-black ${product.comingSoon ? 'text-zinc-500' : 'text-green-400'}">
            ${product.comingSoon ? 'TBD' : '$' + product.price.toFixed(2)}
          </span>
          <span class="text-xs" data-stock-badge data-sku="${product.sku}">
            ${product.comingSoon ? 'Coming soon' : ''}
          </span>
        </div>
        
        ${this.renderAddToCartButton(product)}
      </div>
    `;
  }
  
  renderImage(product) {
    if (product.comingSoon && product.photoSoon) {
      // Photo coming soon placeholder
      return `
        <div class="product-card__media rounded-xl bg-black" aria-label="Coming soon">
          <div class="product-card__soon-overlay">
            <div class="ob-timer-ring" aria-hidden="true">
              <svg class="ob-timer-svg" viewBox="0 0 64 64" fill="none">
                <circle cx="32" cy="32" r="28" stroke="currentColor" stroke-width="3" class="text-zinc-800"/>
                <circle cx="32" cy="32" r="28" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-dasharray="120 56" class="text-amber-400 ob-timer-hand"/>
                <line x1="32" y1="32" x2="32" y2="14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" class="text-green-400 ob-timer-needle"/>
                <circle cx="32" cy="32" r="3" fill="currentColor" class="text-zinc-300"/>
              </svg>
            </div>
            <span class="text-[10px] font-black uppercase tracking-[0.35em] text-amber-200">Photo coming soon</span>
          </div>
        </div>
      `;
    }
    
    if (product.comingSoon) {
      // Has image but coming soon
      return `
        <div class="product-card__media rounded-xl bg-black" style="background-image:url('${product.image}')" aria-label="Coming soon">
          <div class="product-card__soon-overlay">
            <div class="ob-timer-ring" aria-hidden="true">
              <svg class="ob-timer-svg" viewBox="0 0 64 64" fill="none">
                <circle cx="32" cy="32" r="28" stroke="currentColor" stroke-width="3" class="text-zinc-700"/>
                <circle cx="32" cy="32" r="28" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-dasharray="120 56" class="text-amber-400 ob-timer-hand"/>
                <line x1="32" y1="32" x2="32" y2="14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" class="text-green-400 ob-timer-needle"/>
                <circle cx="32" cy="32" r="3" fill="currentColor" class="text-zinc-300"/>
              </svg>
            </div>
            <span class="text-[10px] font-black uppercase tracking-[0.35em] text-amber-200">Coming soon</span>
          </div>
        </div>
      `;
    }
    
    // Regular product with image - bg-contain to show full product, not cropped
    return `
      <div class="product-card__media rounded-xl bg-black" 
           style="background-image:url('${product.image}')"
           role="img"
           aria-label="${product.name}">
      </div>
    `;
  }
  
  renderDescription(product, showDetails) {
    if (showDetails) {
      // Render details pane
      const detailsHtml = product.details.map(detail => {
        if (detail.label === 'Typical fit' || detail.label === 'Request fitment for' || detail.label === 'Verify fitment' || detail.label === 'Compatibility') {
          return `
            <div class="pt-2">
              <dt class="text-zinc-500 mb-1">${detail.label}</dt>
              <dd class="text-zinc-300">${detail.value}</dd>
            </div>
          `;
        }
        return `
          <div class="flex justify-between gap-3 border-b border-zinc-800 pb-2">
            <dt class="text-zinc-500">${detail.label}</dt>
            <dd class="text-zinc-200 text-right">${detail.value}</dd>
          </div>
        `;
      }).join('');
      
      return `
        <div class="product-desc hidden" data-copy-pane="desc" id="desc-${product.productId}">
          <p>${product.description.short}</p>
          ${product.description.verify ? `<p class="mt-3 text-xs text-green-300"><strong>Verify:</strong> ${product.description.verify}</p>` : ''}
          ${product.description.status ? `<p class="mt-3 text-xs text-amber-200"><strong>Status:</strong> ${product.description.status}</p>` : ''}
        </div>
        <div class="product-details" data-copy-pane="details" id="details-${product.productId}">
          <dl class="space-y-2 text-xs">${detailsHtml}</dl>
        </div>
      `;
    }
    
    // Render description pane (default)
    const bulletsHtml = product.description.bullets?.map(b => 
      `<li>${b}</li>`
    ).join('') || '';
    
    return `
      <div class="product-desc" data-copy-pane="desc" id="desc-${product.productId}">
        <p>${product.description.short}</p>
        ${bulletsHtml ? `<ul class="mt-3 space-y-1 text-xs text-zinc-300 list-disc pl-4">${bulletsHtml}</ul>` : ''}
        ${product.description.verify ? `<p class="mt-3 text-xs text-green-300"><strong>Verify:</strong> ${product.description.verify}</p>` : ''}
        ${product.description.status ? `<p class="mt-3 text-xs text-amber-200"><strong>Status:</strong> ${product.description.status}</p>` : ''}
      </div>
      <div class="product-details hidden" data-copy-pane="details" id="details-${product.productId}">
        <dl class="space-y-2 text-xs">
          ${product.details.map(detail => `
            <div class="flex justify-between gap-3 border-b border-zinc-800 pb-2">
              <dt class="text-zinc-500">${detail.label}</dt>
              <dd class="text-zinc-200 text-right">${detail.value}</dd>
            </div>
          `).join('')}
        </dl>
      </div>
    `;
  }
  
  renderAddToCartButton(product) {
    if (product.comingSoon) {
      return `
        <button class="add-to-cart mt-5 w-full ob-btn ob-btn-ghost opacity-60 cursor-not-allowed" 
                disabled>
          Coming soon
        </button>
      `;
    }
    
    return `
      <button class="add-to-cart mt-5 w-full ob-btn ob-btn-primary" 
              data-add-to-cart
              data-sku="${product.sku}"
              data-name="${product.name}"
              data-price="${product.price}">
        Add to bag
      </button>
    `;
  }
}

// ============================================================================
// SECTION 5: FILTER COMPONENT
// ============================================================================

class FilterComponent {
  constructor(store) {
    this.store = store;
    this.filters = ['all', 'surron', 'talaria', 'eride'];
    this.categoryFilters = ['build', 'style', 'maintenance', 'soon'];
  }
  
  render() {
    return `
      <div class="ob-scroll-tabs flex gap-2 pb-3 mb-4">
        ${this.filters.map(f => `
          <button data-filter="${f}" 
                  class="ob-btn ob-btn-ghost whitespace-nowrap ${f === 'all' ? 'bg-green-500 text-zinc-950 border-green-400' : ''}">
            ${f === 'all' ? 'All' : f.charAt(0).toUpperCase() + f.slice(1)}
          </button>
        `).join('')}
      </div>
      <div class="ob-scroll-tabs flex flex-wrap gap-2 pb-4 mb-8 border-b border-zinc-900">
        ${this.categoryFilters.map(f => {
          const labels = {
            build: 'Build center',
            style: 'Style add-ons',
            maintenance: 'Maintenance',
            soon: 'Coming soon'
          };
          return `
            <button data-filter="${f}" 
                    class="rounded-full border border-zinc-800 px-4 py-2 text-sm text-zinc-300 hover:border-zinc-600 transition-colors">
              ${labels[f]}
            </button>
          `;
        }).join('')}
      </div>
    `;
  }
  
  applyFilter(filter, cards) {
    cards.forEach(card => {
      const categories = (card.dataset.category || '').split(' ');
      const bike = card.dataset.bike;
      
      let show = filter === 'all';
      show = show || categories.includes(filter);
      show = show || bike === filter;
      
      card.classList.toggle('hidden', !show);
    });
    
    // Update button states
    document.querySelectorAll('[data-filter]').forEach(btn => {
      const isActive = btn.dataset.filter === filter;
      btn.classList.toggle('bg-green-500', isActive);
      btn.classList.toggle('text-zinc-950', isActive);
      btn.classList.toggle('border-green-400', isActive);
    });
  }
}

// ============================================================================
// SECTION 6: STOCK BADGE MANAGER
// ============================================================================

class StockBadgeManager {
  constructor(store) {
    this.store = store;
  }
  
  async updateAllBadges() {
    const badges = document.querySelectorAll('[data-stock-badge]');
    
    for (const badge of badges) {
      const sku = badge.dataset.sku;
      if (!sku) continue;
      
      const product = PRODUCTS_DATA.find(p => p.sku === sku);
      if (!product) continue;
      
      if (product.comingSoon) {
        badge.textContent = 'Coming soon';
        badge.className = 'text-xs ob-badge border-amber-400/40 text-amber-200 bg-amber-400/10';
        continue;
      }
      
      try {
        const stockData = await this.store.fetchStock(sku);
        this.renderBadge(badge, stockData, product.lowStock);
      } catch (e) {
        // Fallback to embedded data
        this.renderBadge(badge, { available: product.stock }, product.lowStock);
      }
    }
  }
  
  renderBadge(badge, data, lowStock) {
    const available = data.available;
    
    if (available <= 0) {
      badge.textContent = 'Sold out';
      badge.className = 'text-xs ob-badge border-red-500/40 text-red-200 bg-red-500/10';
      
      // Disable add to cart button
      const card = badge.closest('.product-card');
      const btn = card?.querySelector('[data-add-to-cart]');
      if (btn) {
        btn.disabled = true;
        btn.classList.add('opacity-60', 'cursor-not-allowed');
        btn.textContent = 'Sold out';
      }
    } else if (available <= lowStock) {
      badge.textContent = `Only ${available} left`;
      badge.className = 'text-xs ob-badge border-amber-400/50 text-amber-200 bg-amber-400/10';
    } else {
      badge.textContent = 'In stock';
      badge.className = 'text-xs ob-badge border-green-500/40 text-green-200 bg-green-500/10';
    }
  }
}

// ============================================================================
// SECTION 7: ANIMATION MANAGER
// ============================================================================

class AnimationManager {
  constructor() {
    this.observer = null;
  }
  
  init() {
    if (!('IntersectionObserver' in window)) {
      // Fallback for older browsers
      document.querySelectorAll('[data-animate]').forEach(el => {
        el.classList.add('is-visible');
      });
      return;
    }
    
    this.observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          this.observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.15, rootMargin: '0px 0px -50px 0px' });
    
    document.querySelectorAll('[data-animate]').forEach(el => {
      this.observer.observe(el);
    });
  }
  
  observeNewElements(container) {
    if (!this.observer) return;
    container.querySelectorAll('[data-animate]:not(.is-visible)').forEach(el => {
      this.observer.observe(el);
    });
  }
}

// ============================================================================
// SECTION 8: APP INITIALIZATION
// ============================================================================

class OnlyBikesApp {
  constructor() {
    this.store = new OnlyBikesStore();
    this.productRenderer = new ProductCardRenderer(this.store);
    this.filterComponent = new FilterComponent(this.store);
    this.stockManager = new StockBadgeManager(this.store);
    this.animationManager = new AnimationManager();
    
    this.init();
  }
  
  init() {
    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => this.bootstrap());
    } else {
      this.bootstrap();
    }
  }
  
  bootstrap() {
    this.renderProducts();
    this.setupEventListeners();
    this.stockManager.updateAllBadges();
    this.animationManager.init();
    this.updateCartCount();
    
    // Check URL params for initial filter
    this.applyUrlFilter();
    
    // Track page view
    this.store.trackEvent('page_view', {
      url: window.location.href,
      title: document.title,
      loadTime: performance.now()
    });
  }
  
  renderProducts() {
    const grid = document.getElementById('products-grid');
    if (!grid) return;
    
    // Clear existing content
    grid.innerHTML = PRODUCTS_DATA.map(p => this.productRenderer.render(p)).join('');
    
    // Re-observe new elements for animations
    this.animationManager.observeNewElements(grid);
  }
  
  setupEventListeners() {
    // Product toggle clicks - using event delegation
    document.addEventListener('click', (e) => {
      const toggleBtn = e.target.closest('[data-details-toggle]');
      if (toggleBtn) {
        e.preventDefault();
        const productId = toggleBtn.dataset.productId;
        if (productId) {
          this.handleToggleClick(productId, toggleBtn);
        }
      }
      
      // Add to cart clicks
      const addBtn = e.target.closest('[data-add-to-cart]');
      if (addBtn) {
        e.preventDefault();
        const sku = addBtn.dataset.sku;
        const name = addBtn.dataset.name;
        const price = parseFloat(addBtn.dataset.price);
        if (sku && name && price) {
          this.handleAddToCart({ sku, name, price });
        }
      }
      
      // Filter clicks
      const filterBtn = e.target.closest('[data-filter]');
      if (filterBtn && !toggleBtn && !addBtn) {
        const filter = filterBtn.dataset.filter;
        if (filter) {
          this.handleFilterChange(filter);
        }
      }
    });
    
    // Cart count subscription
    this.store.subscribe('cart', () => {
      this.updateCartCount();
    });
    
    // Mobile menu toggle
    const mobileMenuBtn = document.getElementById('mobile-menu-btn');
    const mobileMenu = document.getElementById('mobile-menu');
    if (mobileMenuBtn && mobileMenu) {
      mobileMenuBtn.addEventListener('click', () => {
        mobileMenu.classList.toggle('open');
        document.body.classList.toggle('overflow-hidden', mobileMenu.classList.contains('open'));
      });
    }
    
    // Cart button
    const cartBtn = document.getElementById('cart-btn');
    if (cartBtn && window.cartManager) {
      cartBtn.addEventListener('click', () => {
        window.cartManager.openCartModal();
      });
    }
    
    // Mobile sticky cart
    const mobileStickyCart = document.getElementById('mobile-sticky-cart');
    if (mobileStickyCart && window.cartManager) {
      mobileStickyCart.addEventListener('click', () => {
        window.cartManager.openCartModal();
      });
    }
  }
  
  handleToggleClick(productId, btn) {
    const newState = this.store.toggleProductDetails(productId);
    
    // Update UI
    const card = btn.closest('.product-card');
    if (!card) return;
    
    const descPane = card.querySelector('[data-copy-pane="desc"]');
    const detailsPane = card.querySelector('[data-copy-pane="details"]');
    
    if (newState === 'details') {
      descPane?.classList.add('hidden');
      detailsPane?.classList.remove('hidden');
      btn.textContent = '← Back to overview';
      btn.setAttribute('aria-expanded', 'true');
    } else {
      descPane?.classList.remove('hidden');
      detailsPane?.classList.add('hidden');
      btn.textContent = 'Dimensions & details →';
      btn.setAttribute('aria-expanded', 'false');
    }
  }
  
  handleAddToCart(product) {
    this.store.addToCart(product, 1);
    this.showAddToCartFeedback(product.name);
  }
  
  showAddToCartFeedback(productName) {
    // Simple toast notification
    const toast = document.createElement('div');
    toast.className = 'fixed bottom-20 left-1/2 -translate-x-1/2 bg-green-500 text-zinc-950 px-4 py-2 rounded-full text-sm font-bold z-50 shadow-lg';
    toast.textContent = `Added ${productName.substring(0, 30)}${productName.length > 30 ? '...' : ''} to bag`;
    document.body.appendChild(toast);
    
    setTimeout(() => {
      toast.remove();
    }, 2000);
  }
  
  handleFilterChange(filter) {
    this.store.setFilter(filter);
    
    const cards = document.querySelectorAll('.product-card');
    this.filterComponent.applyFilter(filter, cards);
    
    // Update URL without reload
    const url = new URL(window.location);
    if (filter === 'all') {
      url.searchParams.delete('filter');
    } else {
      url.searchParams.set('filter', filter);
    }
    window.history.replaceState({}, '', url);
  }
  
  applyUrlFilter() {
    const params = new URLSearchParams(window.location.search);
    const filter = params.get('filter') || params.get('bike') || params.get('category') || 'all';
    
    if (filter !== 'all') {
      this.handleFilterChange(filter);
    }
  }
  
  updateCartCount() {
    const count = this.store.getCartCount();
    const cartCountEl = document.getElementById('cart-count');
    if (cartCountEl) {
      cartCountEl.textContent = count;
    }
    
    // Also update legacy cart if exists
    if (window.cartManager && typeof window.cartManager.getCount === 'function') {
      const legacyCount = window.cartManager.getCount();
      if (legacyCount !== count) {
        // Sync legacy cart with new store
        window.cartManager.items = this.store.state.cart;
        window.cartManager.saveCart();
      }
    }
  }
}

// ============================================================================
// SECTION 9: GLOBAL EXPORTS & INITIALIZATION
// ============================================================================

// Export for debugging and legacy compatibility
window.OnlyBikesStore = OnlyBikesStore;
window.PRODUCTS_DATA = PRODUCTS_DATA;

// Initialize the app
window.onlyBikesApp = new OnlyBikesApp();

// Legacy bridge for existing code
window.onlyBikesStore = window.onlyBikesApp.store;

console.log('[OnlyBikes] Enterprise application initialized');
