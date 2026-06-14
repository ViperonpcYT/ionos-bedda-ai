// OnlyBikes - Main JavaScript
// Mobile menu and shared functionality

// Inject skeleton loading styles globally so all pages have them
(function injectSkeletonStyles() {
    if (document.getElementById('onlybikes-skeleton-styles')) return;
    const style = document.createElement('style');
    style.id = 'onlybikes-skeleton-styles';
    style.textContent = `
        .skeleton {
            background: linear-gradient(90deg, #E8E4DC 25%, #f5f5f4 50%, #E8E4DC 75%);
            background-size: 200% 100%;
            animation: skeleton-pulse 1.5s ease-in-out infinite;
            border-radius: 0.375rem;
        }
        .skeleton-text { height: 1em; margin-bottom: 0.5em; }
        .skeleton-text.short { width: 40%; }
        .skeleton-text.medium { width: 60%; }
        .skeleton-text.long { width: 90%; }
        .skeleton-title { height: 1.25em; width: 70%; margin-bottom: 0.75em; }
        .skeleton-circle { border-radius: 50%; width: 40px; height: 40px; }
        .skeleton-box { height: 80px; width: 100%; margin-bottom: 0.75rem; }
        .skeleton-input { height: 42px; width: 100%; margin-bottom: 1rem; }
        .skeleton-button { height: 44px; width: 100%; margin-top: 0.5rem; }
        @keyframes skeleton-pulse {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
    `;
    document.head.appendChild(style);
})();

/** Live site origin — current host in production (avoids stale site-config placeholders). */
function onlyBikesSiteOrigin() {
    const h = window.location.hostname;
    if (h === 'localhost' || h === '127.0.0.1') return window.location.origin;
    return window.location.origin;
}
function beddaSiteOrigin() { return onlyBikesSiteOrigin(); }

/** Resolve /api/... paths on production vs local dev (also in js/site-config.js). */
function onlyBikesApiUrl(path) {
    const p = path.startsWith('/') ? path : '/' + path;
    const host = window.location.hostname;
    if (host === 'localhost' || host === '127.0.0.1') return p;
    return window.location.origin + p;
}

async function resolveStripePublishableKey() {
    try {
        const configUrl = typeof onlyBikesApiUrl === 'function'
            ? onlyBikesApiUrl('/api/public-config.php')
            : '/api/public-config.php';
        const res = await fetch(configUrl, { credentials: 'same-origin' });
        if (!res.ok) return '';
        const data = await res.json();
        return data.stripePublishableKey || '';
    } catch (e) {
        return '';
    }
}

function onlyBikesCustomerAuthUrl(query) {
    const q = query ? (query.startsWith('?') ? query : '?' + query) : '';
    const path = '/api/customer-auth.php' + q;
    return typeof onlyBikesApiUrl === 'function' ? onlyBikesApiUrl(path) : path;
}

function wireRoastNavLinks() {
    document.querySelectorAll('.roast-nav-link.hidden').forEach(function (el) {
        el.classList.remove('hidden');
    });

    var nav = document.querySelector('nav');
    if (!nav) return;

    function insertLivePvpLink(contactAnchor, pvpClass) {
        if (!contactAnchor || !contactAnchor.parentNode) return;
        var pvp = document.createElement('a');
        pvp.href = 'roast-pvp.html';
        pvp.textContent = 'Live PvP';
        pvp.className = pvpClass;
        contactAnchor.parentNode.insertBefore(pvp, contactAnchor);
    }

    function navHasRoastPvp(container) {
        return !!(container && container.querySelector('a[href="roast-pvp.html"]'));
    }

    nav.querySelectorAll('a[href="contact.html"]').forEach(function (contact) {
        if (contact.closest('#mobile-menu') || contact.closest('#mobile-menu-panel')) return;
        if (navHasRoastPvp(contact.parentNode)) return;
        var isLight = /stone|sage/.test(contact.className);
        insertLivePvpLink(
            contact,
            isLight ? 'text-sage-700 hover:text-sage-800 font-semibold' : 'ob-nav-link text-green-300'
        );
    });

    var mobileMenu = document.getElementById('mobile-menu') || document.getElementById('mobile-menu-panel');
    if (!mobileMenu || navHasRoastPvp(mobileMenu)) return;

    var mobileContact = mobileMenu.querySelector('a[href="contact.html"]');
    if (!mobileContact) return;

    var mobileLight = /stone|sage/.test(mobileContact.className);
    insertLivePvpLink(
        mobileContact,
        mobileLight ? 'block text-sage-700 hover:text-sage-800 font-semibold py-2' : 'block rounded-lg px-3 py-3 text-green-300 font-bold'
    );
}

document.addEventListener('DOMContentLoaded', function() {
    wireRoastNavLinks();

    // Mobile menu open/close
    // Supports both index.html IDs (mobile-menu-btn / mobile-menu)
    // and products.html IDs (mobile-menu-toggle / mobile-menu-panel)
    const menuBtn = document.getElementById('mobile-menu-btn') || document.getElementById('mobile-menu-toggle');
    const menuPanel = document.getElementById('mobile-menu') || document.getElementById('mobile-menu-panel');
    const menuIcon = document.getElementById('menu-icon');

    const HAMBURGER_ICON = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>';
    const CLOSE_ICON = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>';

    const setHamburgerIcon = () => {
        if (!menuIcon) return;
        menuIcon.setAttribute('fill', 'none');
        menuIcon.setAttribute('stroke', 'currentColor');
        menuIcon.setAttribute('stroke-width', '2');
        menuIcon.innerHTML = HAMBURGER_ICON;
    };

    const setCloseIcon = () => {
        if (!menuIcon) return;
        menuIcon.setAttribute('fill', 'none');
        menuIcon.setAttribute('stroke', 'currentColor');
        menuIcon.setAttribute('stroke-width', '2');
        menuIcon.innerHTML = CLOSE_ICON;
    };

    if (menuIcon) {
        setHamburgerIcon();
    }

    const handleMenuClose = () => {
        if (!menuPanel) return;
        menuPanel.classList.remove('open');
        setHamburgerIcon();
        if (menuBtn) {
            menuBtn.setAttribute('aria-expanded', 'false');
        }
    };

    if (menuBtn && menuPanel) {
        menuBtn.addEventListener('click', function() {
            const isOpen = menuPanel.classList.contains('open');
            if (isOpen) {
                menuPanel.classList.remove('open');
                setHamburgerIcon();
                menuBtn.setAttribute('aria-expanded', 'false');
            } else {
                menuPanel.classList.add('open');
                setCloseIcon();
                menuBtn.setAttribute('aria-expanded', 'true');
            }
        });
    }

    if (menuPanel) {
        const linkSelector = menuPanel.id === 'mobile-menu-panel' ? '#mobile-menu-panel a' : '#mobile-menu a';
        document.querySelectorAll(linkSelector).forEach(link => {
            link.addEventListener('click', handleMenuClose);
        });
    }

    // Shop mobile submenu toggle - handled here for all pages
    const shopToggle = document.getElementById('mobile-shop-toggle');
    if (shopToggle) {
        shopToggle.addEventListener('click', function() {
            const submenu = document.getElementById('mobile-shop-submenu');
            const icon = document.getElementById('mobile-shop-icon');
            if (submenu) submenu.classList.toggle('hidden');
            if (icon) icon.classList.toggle('rotate-180');
        });
    }
});

const PRODUCT_WEIGHTS = {
    'Ultra Bee Brake Kit': 8600,
    'Titanium Bolt Kit': 950,
    '17x1.6 Supermoto Wheel Set for Talaria & Sur-Ron': 8600,
    '3-Inch Baja Style LED Headlight': 500,
    'Front 70/100-19 & 90/100-16 Rear Tire + Tube for Off Road Dirt': 6000,
    'Rear Brake Pads for Sur-Ron': 150,
    'Sur-Ron Ultra Bee Front & Rear Fender Set': 3500,
    'Build Center Kit': 9550,
    'build-center-kit': 9550,
    'Style Stack Kit': 9100,
    'style-stack-kit': 9100,
    'Trail & Visibility Kit': 6500,
    'trail-visibility-kit': 6500,
    'Full Send Kit': 21650,
    'full-send-kit': 21650
};

/** Cart spend bonuses (Unlocked / progress in bag). Set enabled: true to turn back on. */
const CART_SPEND_BONUSES = {
    enabled: false,
    thresholds: [
        { amount: 50, gift: 'Brake pad refresh credit', icon: 'Bonus:' },
        { amount: 75, gift: 'Grip refresh credit', icon: 'Bonus:' }
    ]
};

function calculateHandlingCost(items) {
    let totalWeight = 0, totalItems = 0;
    items.forEach(item => {
        const qty = item.quantity || 1;
        totalItems += qty;
        totalWeight += (PRODUCT_WEIGHTS[item.product] || 300) * qty;
    });
    const isSmallParcel = totalWeight <= 500 && totalItems <= 3;
    const baseCost = isSmallParcel ? 0.75 : 1.25;
    const FLAT_FEE = 3.00;
    return { type: isSmallParcel ? 'Small Parcel' : 'Standard Parcel', cost: baseCost + FLAT_FEE };
}

// Shipping rate chart (low and high estimates)
const SHIPPING_RATES = {
    ontario: { min: [4,5,5,6,7,9], max: [6,7,8,10,12,15] },
    quebec: { min: [5,6,6,7,8,10], max: [7,8,9,11,13,16] },
    prairies: { min: [6,7,7,8,9,11], max: [8,9,10,12,14,18] }, // MB/SK
    west: { min: [7,8,8,9,10,13], max: [9,10,11,14,15,20] }, // AB/BC
    atlantic: { min: [8,9,10,11,12,15], max: [10,12,13,16,18,25] }, // NB/NS/PE/NL
    remote: { min: [18,18,20,22,25,30], max: [30,30,35,40,45,60] } // YT/NT/NU
};

const WEIGHT_BRACKETS = [20, 50, 100, 250, 500, 1000];

/** Chit Chats live quote helpers — no stock/fallback shipping at checkout. */
function getShippingCountry() {
    return (document.getElementById('country')?.value || 'CA').toUpperCase();
}

function isLiveShippingOption(opt) {
    return !!(opt && opt.id && opt.id !== 'fallback' && !opt.estimated && typeof opt.total === 'number' && opt.total > 0);
}

function postalReadyForQuote(country, postal) {
    const clean = (postal || '').replace(/\s/g, '').toUpperCase();
    if (country === 'CA') return /^[A-Z][0-9][A-Z][0-9][A-Z][0-9]$/.test(clean);
    if (country === 'US') return /^\d{5}(\d{4})?$/.test(clean);
    return clean.length >= 3;
}

function syncProvinceFieldForCountry() {
    const country = getShippingCountry();
    const caSelect = document.getElementById('province');
    const intlInput = document.getElementById('province-intl');
    const label = document.getElementById('province-label');
    const postalInput = document.getElementById('postal-code');
    if (!caSelect || !intlInput) return;

    if (country === 'CA' || country === 'US') {
        caSelect.classList.remove('hidden');
        caSelect.required = true;
        intlInput.classList.add('hidden');
        intlInput.required = false;
        intlInput.value = '';
        if (label) label.textContent = country === 'US' ? 'State *' : 'Province *';
        rebuildProvinceOptions(country);
        if (postalInput) postalInput.placeholder = country === 'US' ? '90210' : 'A1A 1A1';
        if (postalInput) postalInput.maxLength = country === 'US' ? 10 : 7;
    } else {
        caSelect.classList.add('hidden');
        caSelect.required = false;
        caSelect.value = '';
        intlInput.classList.remove('hidden');
        intlInput.required = false;
        if (label) label.textContent = 'Region (optional)';
        if (postalInput) postalInput.placeholder = 'Postal code';
        if (postalInput) postalInput.maxLength = 12;
    }
}

function rebuildProvinceOptions(country) {
    const select = document.getElementById('province');
    if (!select) return;
    const current = select.value;
    const ca = [
        ['', 'Select...'], ['ON', 'Ontario'], ['QC', 'Quebec'], ['BC', 'British Columbia'],
        ['AB', 'Alberta'], ['MB', 'Manitoba'], ['SK', 'Saskatchewan'], ['NS', 'Nova Scotia'],
        ['NB', 'New Brunswick'], ['NL', 'Newfoundland & Labrador'], ['PE', 'PEI'],
        ['NT', 'Northwest Territories'], ['NU', 'Nunavut'], ['YT', 'Yukon']
    ];
    const us = [
        ['', 'Select...'], ['AL', 'Alabama'], ['AK', 'Alaska'], ['AZ', 'Arizona'], ['AR', 'Arkansas'],
        ['CA', 'California'], ['CO', 'Colorado'], ['CT', 'Connecticut'], ['DE', 'Delaware'],
        ['FL', 'Florida'], ['GA', 'Georgia'], ['HI', 'Hawaii'], ['ID', 'Idaho'], ['IL', 'Illinois'],
        ['IN', 'Indiana'], ['IA', 'Iowa'], ['KS', 'Kansas'], ['KY', 'Kentucky'], ['LA', 'Louisiana'],
        ['ME', 'Maine'], ['MD', 'Maryland'], ['MA', 'Massachusetts'], ['MI', 'Michigan'],
        ['MN', 'Minnesota'], ['MS', 'Mississippi'], ['MO', 'Missouri'], ['MT', 'Montana'],
        ['NE', 'Nebraska'], ['NV', 'Nevada'], ['NH', 'New Hampshire'], ['NJ', 'New Jersey'],
        ['NM', 'New Mexico'], ['NY', 'New York'], ['NC', 'North Carolina'], ['ND', 'North Dakota'],
        ['OH', 'Ohio'], ['OK', 'Oklahoma'], ['OR', 'Oregon'], ['PA', 'Pennsylvania'],
        ['RI', 'Rhode Island'], ['SC', 'South Carolina'], ['SD', 'South Dakota'], ['TN', 'Tennessee'],
        ['TX', 'Texas'], ['UT', 'Utah'], ['VT', 'Vermont'], ['VA', 'Virginia'], ['WA', 'Washington'],
        ['WV', 'West Virginia'], ['WI', 'Wisconsin'], ['WY', 'Wyoming'], ['DC', 'District of Columbia']
    ];
    const list = country === 'US' ? us : ca;
    select.innerHTML = list.map(([v, t]) => `<option value="${v}">${t}</option>`).join('');
    if (current && list.some(([v]) => v === current)) select.value = current;
}

function getProvinceForQuote() {
    const country = getShippingCountry();
    if (country === 'CA' || country === 'US') {
        return document.getElementById('province')?.value || '';
    }
    return (document.getElementById('province-intl')?.value || '').trim().toUpperCase();
}

function clearShippingSelection() {
    window.selectedShippingOption = null;
    window.shippingOptions = null;
}

function showShippingQuoteError(message) {
    const shippingDiv = document.getElementById('shipping-estimate');
    const placeholder = document.getElementById('shipping-estimate-placeholder');
    clearShippingSelection();
    if (shippingDiv) {
        shippingDiv.innerHTML = `
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-semibold">Live shipping rates unavailable</p>
                <p class="mt-2">${message}</p>
                <p class="mt-2 text-xs">Checkout uses live carrier rates — shipping matches actual postage cost.</p>
                <button type="button" class="mt-3 ob-btn ob-btn-primary px-4 py-2 text-sm" onclick="updateShippingEstimate()">Try again</button>
            </div>`;
        shippingDiv.classList.remove('hidden');
    }
    placeholder?.classList.add('hidden');
    document.getElementById('payment-wrapper')?.classList.add('hidden');
    updateOrderSummary();
}

// OnlyBikes Rewards: linear discount based on points (1 point = $0.05)
function getDiscountFromPoints(points) {
    return (points * 0.05).toFixed(2);
}

// Cart functionality
class CartManager {
    constructor() {
        this.items = this.loadCart();
        this.init();
    }

    init() {
        this.renderCartCount();
        this.setupCartButton();
        this.setupCartModal();
    }

    loadCart() {
        const key = window.ONLYBIKES_CONFIG?.cartStorageKey || 'onlybikes_cart';
        let saved = localStorage.getItem(key);
        if (!saved) {
            const legacy = localStorage.getItem('bedda_cart');
            if (legacy) { saved = legacy; localStorage.setItem(key, legacy); }
        }
        const items = saved ? JSON.parse(saved) : [];
        return Array.isArray(items)
            ? items.map(item => ({
                ...item,
                quantity: Math.max(1, Math.min(10, parseInt(item.quantity, 10) || 1)),
            }))
            : [];
    }

    saveCart() {
        localStorage.setItem(window.ONLYBIKES_CONFIG?.cartStorageKey || 'onlybikes_cart', JSON.stringify(this.items));
    }

    addItem(product, price, quantity = 1, metadata = {}) {
        quantity = Math.max(1, Math.min(10, parseInt(quantity, 10) || 1));
        const existing = this.items.find(item => item.product === product);
        if (existing) {
            existing.quantity += quantity;
            // Merge subscription metadata if provided
            if (metadata.isSubscription !== undefined) {
                existing.isSubscription = metadata.isSubscription;
            }
            if (metadata.subscriptionInterval) {
                existing.subscriptionInterval = metadata.subscriptionInterval;
            }
        } else {
            this.items.push({
                product: product,
                price: parseFloat(price),
                quantity: quantity,
                id: Date.now().toString() + Math.random().toString(36).substr(2, 5),
                // Store subscription metadata on new items
                isSubscription: !!metadata.isSubscription,
                subscriptionInterval: metadata.subscriptionInterval || null
            });
        }
        this.saveCart();
        this.renderCartCount();
        this.renderCartItems();

        // Log add to cart event
        if (window.beddaLogger) {
            window.beddaLogger.logEvent('add_to_cart', {
                product: product,
                price: parseFloat(price),
                quantity: quantity,
                isSubscription: !!metadata.isSubscription,
                subscriptionInterval: metadata.subscriptionInterval || null
            });
        }
    }

    removeItem(id) {
        const item = this.items.find(item => item.id === id);
        this.items = this.items.filter(item => item.id !== id);
        this.saveCart();
        this.renderCartCount();
        this.renderCartItems();

        // Log cart item removal
        if (window.beddaLogger && item) {
            window.beddaLogger.logEvent('cart_item_removed', {
                product: item.product,
                price: item.price,
                quantity: item.quantity
            });
        }
    }

    getTotal() {
        return this.items.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    }

    getItemCount() {
        return this.items.reduce((sum, item) => sum + item.quantity, 0);
    }

    renderCartCount() {
        const countEl = document.getElementById('cart-count');
        if (countEl) {
            const count = this.getItemCount();
            countEl.textContent = count;
            countEl.style.display = count > 0 ? 'flex' : 'none';
        }
    }

    setupCartButton() {
        const cartBtn = document.getElementById('cart-btn');
        if (cartBtn) {
            cartBtn.addEventListener('click', () => {
                this.toggleCartModal();
            });
        }
    }

    setupCartModal() {
        // Create modal if it doesn't exist
        if (!document.getElementById('cart-modal')) {
            const modal = document.createElement('div');
            modal.id = 'cart-modal';
            modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden';
            modal.innerHTML = `
                <div class="bg-white rounded-lg p-8 max-w-md w-full mx-4 max-h-96 overflow-y-auto">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="font-playfair text-2xl font-bold text-stone-800">Your Cart</h3>
                        <button id="close-cart-modal" class="text-stone-500 hover:text-stone-700">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 28 28">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    <div id="cart-skeleton" class="space-y-4 mb-6">
                        <div class="flex items-center space-x-3">
                            <div class="skeleton skeleton-circle flex-shrink-0"></div>
                            <div class="flex-1">
                                <div class="skeleton skeleton-text medium"></div>
                                <div class="skeleton skeleton-text short"></div>
                            </div>
                            <div class="skeleton skeleton-text short" style="width:60px"></div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <div class="skeleton skeleton-circle flex-shrink-0"></div>
                            <div class="flex-1">
                                <div class="skeleton skeleton-text medium"></div>
                                <div class="skeleton skeleton-text short"></div>
                            </div>
                            <div class="skeleton skeleton-text short" style="width:60px"></div>
                        </div>
                    </div>
                    <div id="cart-items" class="space-y-4 mb-6 hidden"></div>
                    <div id="cart-cross-sells"></div>
                    <div id="cart-gift-progress"></div>
                    <div id="cart-empty" class="text-center py-8 hidden">
                        <div class="text-6xl mb-4">Bag</div>
                        <p class="text-stone-600">Your cart is empty</p>
                    </div>
                    <div id="cart-total-section" class="border-t pt-6 mb-6 hidden">
                        <div class="flex justify-between items-center mb-6">
                            <span class="font-semibold text-stone-800">Total:</span>
                            <span id="cart-total" class="font-bold text-xl text-stone-800">$0.00</span>
                        </div>
                        <button id="create-order-btn" class="w-full bg-sage-600 text-white py-3 rounded-lg font-semibold hover:bg-sage-700 transition-colors">
                            Create Order
                        </button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            // Setup close button
            document.getElementById('close-cart-modal').addEventListener('click', () => {
                this.hideCartModal();
            });

            setTimeout(updateShippingEstimate, 100);
            
            // Setup create order button
            document.getElementById('create-order-btn').addEventListener('click', () => {
                this.createOrder();
            });

            // Close on outside click
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.hideCartModal();
                }
            });
        }
    }

    toggleCartModal() {
        const modal = document.getElementById('cart-modal');
        if (!modal) return; // Guard clause

        if (modal.classList.contains('hidden')) {
            // Show skeleton immediately for perceived performance
            const skeleton = document.getElementById('cart-skeleton');
            const itemsContainer = document.getElementById('cart-items');
            if (skeleton) skeleton.classList.remove('hidden');
            if (itemsContainer) itemsContainer.classList.add('hidden');
            modal.classList.remove('hidden');
            // Small delay so skeleton is visible before render
            requestAnimationFrame(() => this.renderCartItems());

            // Log cart view event
            if (window.beddaLogger) {
                window.beddaLogger.logEvent('cart_view', {
                    itemCount: this.getItemCount(),
                    total: this.getTotal(),
                    items: this.items.map(i => ({ product: i.product, quantity: i.quantity, price: i.price }))
                });
            }
        } else {
            modal.classList.add('hidden');
        }
    }

    hideCartModal() {
        const modal = document.getElementById('cart-modal');
        modal.classList.add('hidden');
    }

    renderCartItems() {
        const container = document.getElementById('cart-items');
        const emptyState = document.getElementById('cart-empty');
        const totalSection = document.getElementById('cart-total-section');
        const skeleton = document.getElementById('cart-skeleton');

        if (!container) return;

        // Hide skeleton, show content area
        if (skeleton) skeleton.classList.add('hidden');
        container.classList.remove('hidden');

        if (this.items.length === 0) {
            container.innerHTML = '';
            container.classList.add('hidden');
            emptyState.classList.remove('hidden');
            totalSection.classList.add('hidden');
            return;
        }

        emptyState.classList.add('hidden');
        totalSection.classList.remove('hidden');

        container.innerHTML = this.items.map(item => `
            <div class="flex justify-between items-center py-3 border-b border-stone-200">
                <div class="flex-1 pr-4">
                    <h4 class="font-medium text-stone-800">${item.product}</h4>
                    <p class="text-sm text-stone-600">$${item.price.toFixed(2)} x ${item.quantity}</p>
                </div>
                <div class="flex items-center space-x-3">
                    <span class="font-semibold text-stone-800">$${(item.price * item.quantity).toFixed(2)}</span>
                    <button onclick="cartManager.removeItem('${item.id}')" class="text-red-500 hover:text-red-700 p-1" aria-label="Remove item">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 28 28">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                    </button>
                </div>
            </div>
        `).join('');

        document.getElementById('cart-total').textContent = `$${this.getTotal().toFixed(2)}`;

        // Add checkout cross-sells
        this.renderCartCrossSells();

        // Add gift-with-purchase progress
        this.renderGiftProgress();
    }

    renderGiftProgress() {
        const container = document.getElementById('cart-gift-progress');
        if (!container) return;

        const total = this.getTotal();
        // Points estimation (1 point = $1)
        const points = Math.floor(total);
        
        const thresholds = CART_SPEND_BONUSES.enabled ? CART_SPEND_BONUSES.thresholds : [];

        const nextThreshold = thresholds.find(t => total < t.amount);
        const unlockedGifts = thresholds.filter(t => total >= t.amount);

        const isLoggedIn = window.currentUser !== null && window.currentUser !== undefined;
        const currentPoints = isLoggedIn ? parseInt(window.currentUser.points || 0) : 0;
        const totalPoints = currentPoints + points;
        const expectedDiscount = getDiscountFromPoints(totalPoints);

        let html = '<div class="mt-4 pt-4 border-t border-stone-200 space-y-3">';

        // 1. Loyalty Points & Discount
        if (total > 0) {
            if (isLoggedIn) {
                html += `
                    <div class="bg-gradient-to-r from-sage-50 to-sage-100 border-2 border-sage-300 rounded-lg p-4 shadow-sm">
                        <p class="text-sm font-bold text-sage-900 flex items-center gap-2 mb-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            OnlyBikes Rewards - You're earning!
                        </p>
                        <div class="bg-white rounded-lg p-3 border border-sage-200">
                            <p class="text-sm text-sage-800 font-semibold">Earn <strong class="text-sage-600 text-lg">${points} points</strong> with this order!</p>
                            <p class="text-sm text-sage-700 mt-1">1 point = <strong class="text-sage-600">$0.05</strong> toward your next order</p>
                            <p class="text-xs text-sage-600 mt-2 border-t border-sage-200 pt-2">Your balance: <strong>${currentPoints} points</strong> = <strong>$${(currentPoints * 0.05).toFixed(2)}</strong> available</p>
                        </div>
                    </div>
                `;
            } else {
                html += `
                    <div class="bg-gradient-to-r from-stone-100 to-stone-200 border-2 border-stone-300 rounded-lg p-4 shadow-sm">
                        <p class="text-sm font-bold text-stone-700 flex items-center gap-2 mb-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Unlock OnlyBikes Rewards
                        </p>
                        <div class="bg-white rounded-lg p-3 border border-stone-300">
                            <p class="text-sm text-stone-700">Sign in to earn <strong class="text-sage-600 text-lg">${points} points</strong> and unlock rewards!</p>
                            <p class="text-sm text-stone-600 mt-1">Use points to pay for future orders (1 point = $0.05)</p>
                            <button onclick="document.getElementById('login-btn').click()" class="mt-3 w-full bg-sage-500 hover:bg-sage-600 text-white font-semibold py-2 px-4 rounded-lg text-sm transition-colors">
                                Sign In & Earn Points
                            </button>
                        </div>
                    </div>
                `;
            }
        }

        // 2. Free Gifts
        if (unlockedGifts.length > 0) {
            html += '<div class="bg-stone-50 border border-stone-200 rounded-lg p-3">';
            html += '<p class="text-xs font-bold text-stone-800 mb-1">Unlocked:</p>';
            unlockedGifts.forEach(gift => {
                html += `<div class="text-xs text-stone-700 mt-1">${gift.icon} <strong>${gift.gift}</strong></div>`;
            });
            html += '</div>';
        }

        if (nextThreshold) {
            const progress = Math.min((total / nextThreshold.amount) * 100, 100);
            const remaining = (nextThreshold.amount - total).toFixed(2);
            html += '<div class="bg-sage-50 border border-sage-200 rounded-lg p-3">';
            html += '<p class="text-xs font-bold text-sage-800 mb-2 flex items-center gap-1">Add $' + remaining + ' more for ' + nextThreshold.gift + '</p>';
            html += '<div class="w-full bg-sage-200 rounded-full h-2">';
            html += '<div class="bg-sage-600 h-2 rounded-full transition-all" style="width: ' + progress + '%"></div>';
            html += '</div>';
            html += '</div>';
        }

        html += '</div>';
        container.innerHTML = html;
    }

    renderCartCrossSells() {
        const container = document.getElementById('cart-cross-sells');
        if (!container) return;

        // Determine cross-sell based on cart contents
        const cartProducts = this.items.map(item => item.product.toLowerCase());
        let crossSells = [];

        // Logic: suggest complementary parts
        if (cartProducts.some(p => p.includes('brake') || p.includes('ultra bee'))) {
            crossSells.push({ product: '3-Inch Baja Style LED Headlight', price: 49.99, reason: 'Visibility add-on for the same shipment' });
        }
        if (cartProducts.some(p => p.includes('bolt') || p.includes('titanium'))) {
            crossSells.push({ product: '3-Inch Baja Style LED Headlight', price: 49.99, reason: 'Style add-on for the same shipment' });
        }
        if (cartProducts.some(p => p.includes('wheel') || p.includes('supermoto'))) {
            crossSells.push({ product: 'Front 70/100-19 & 90/100-16 Rear Tire + Tube for Off Road Dirt', price: 169.99, reason: 'Fresh rubber for your wheel setup' });
        }
        if (cartProducts.some(p => p.includes('baja') || p.includes('headlight') || p.includes('light'))) {
            crossSells.push({ product: 'Front 70/100-19 & 90/100-16 Rear Tire + Tube for Off Road Dirt', price: 169.99, reason: 'Complete the dirt setup in one order' });
        }

        if (crossSells.length === 0) {
            container.innerHTML = '';
            return;
        }

        container.innerHTML = `
            <div class="mt-4 pt-4 border-t border-stone-200">
                <p class="text-xs font-semibold text-stone-600 mb-1">Complete your build</p>
                <p class="text-[11px] text-stone-500 mb-3">Add-ons ship with this order and help finish the build.</p>
                ${crossSells.slice(0, 2).map(item => `
                    <div class="flex items-center justify-between bg-stone-50 rounded-xl p-3 mb-2 cursor-pointer hover:bg-stone-100 transition-colors cart-cross-sell-item" data-product="${item.product}" data-price="${item.price}">
                        <div>
                            <div class="text-sm font-semibold text-stone-800">${item.product}</div>
                            <div class="text-[11px] text-stone-500">${item.reason}</div>
                        </div>
                        <div class="text-sm font-semibold text-stone-800">$${item.price.toFixed(2)}</div>
                    </div>
                `).join('')}
                <p class="text-[11px] text-sage-600">Items are not reserved until checkout is complete.</p>
            </div>
        `;

        // Add click handlers
        container.querySelectorAll('.cart-cross-sell-item').forEach(item => {
            item.addEventListener('click', () => {
                const product = item.dataset.product;
                const price = parseFloat(item.dataset.price);
                this.addItem(product, price, 1, { skipCrossSell: true });
                this.renderCartItems();
            });
        });
    }

    generateOrderNumber() {
        // Generate UUID v4 (random)
        const uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
        const prefix = window.ONLYBIKES_CONFIG?.orderPrefix || 'OB';
        return `${prefix}-${uuid}`.toUpperCase();
    }

    createOrder() {
        if (this.items.length === 0) return;

        const orderNumber = this.generateOrderNumber();
        const orderDate = new Date().toLocaleString();
        
        const orderDetails = {
            orderNumber: orderNumber,
            date: orderDate,
            items: this.items,
            subtotal: this.getTotal()
        };

        this.showOrderSubmissionForm(orderDetails);
    }

    showOrderSubmissionForm(orderDetails) {
        // Remove any existing modal
        const existingModal = document.getElementById('order-form-modal');
        if (existingModal) existingModal.remove();

        const timestamp = Math.floor(Date.now() / 1000);

        const modal = document.createElement('div');

        const hasSubscriptionItem = this.items.some(item => item.isSubscription);
        if (hasSubscriptionItem) {
            orderDetails.isSubscription = true;
            // Use interval from first subscription item found
            const subItem = this.items.find(item => item.subscriptionInterval);
            orderDetails.subscriptionInterval = subItem?.subscriptionInterval || '2';
            
            // Also set the global flag for initPayment to read
            window.pendingBundleSubscription = {
                interval: orderDetails.subscriptionInterval,
                source: 'cart_items'
            };
        }

        modal.id = 'order-form-modal';
        window.currentOrderDetails = orderDetails;
        modal.className = 'fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-0 sm:p-4';
        modal.innerHTML = `
            <div class="bg-white rounded-lg p-8 max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="font-playfair text-2xl font-bold text-stone-800">Complete Your Order</h3>
                    <button id="close-order-form-modal" class="text-stone-500 hover:text-stone-700">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 28 28">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <div class="mb-6">
                    <h4 class="font-semibold text-stone-800 mb-3">Order Summary</h4>
                    <div class="bg-stone-50 p-4 rounded-lg text-sm space-y-1">
                        <p><strong>Order #:</strong> ${orderDetails.orderNumber}</p>
                        <p><strong>Items:</strong> ${orderDetails.items.length}</p>
                        <p><strong>Total:</strong> $${orderDetails.subtotal.toFixed(2)}</p>
                    </div>
                </div>

                <div class="bg-blue-50 p-4 rounded-lg mb-6 border border-blue-200">
                    <h4 class="font-semibold text-blue-800 mb-2">Secure Checkout</h4>
                    <p class="text-sm text-blue-700 leading-relaxed">
                        Your payment will be processed securely via Stripe. 
                        After completing your order, you'll receive a confirmation email within a few minutes.
                    </p>
                </div>
                <!-- Fulfillment Method -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-stone-700 mb-3">How would you like to receive your order?</label>
                    <div class="rounded-lg border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700">
                        <input type="radio" name="fulfillment_method" value="shipping" checked class="h-4 w-4 text-sage-600 border-stone-300 focus:ring-sage-500" onchange="toggleFulfillmentFields()">
                        <span class="ml-2 font-semibold">Ship to Address</span>
                        <p class="mt-2 text-xs text-stone-500">Shipping to your address is available at checkout.</p>
                    </div>
                </div>
<!-- Shipping Address Section (shown by default) -->
                <div id="shipping-fields" class="border-t border-stone-200 pt-4 mt-4">
                    <h4 class="font-medium text-stone-800 mb-4">Shipping Address</h4>
                    <!-- existing shipping fields here -->
                </div>
                <!-- Pickup Fields (intentionally disabled until real launch policy exists) -->
                <div id="pickup-fields" class="border-t border-stone-200 pt-4 mt-4 hidden">
                    <h4 class="font-medium text-stone-800 mb-4">Pickup Unavailable</h4>
                    <input type="hidden" name="pickup_location" id="pickup-location" value="">
                    <input type="hidden" name="pickup_date" id="pickup-date" value="">
                    <p class="text-xs text-stone-500 mt-1"></p>
                </div>
                
<form id="order-submission-form" class="space-y-4">
                    <div style="position:absolute;left:-9999px;opacity:0;height:0;width:0;overflow:hidden;" aria-hidden="true">
                        <label for="website-field">Leave this empty</label>
                        <input type="text" name="website" id="website-field" tabindex="-1" autocomplete="off" value="">
                    </div>                    
                    <div>
                        <label class="block text-sm font-medium text-stone-700 mb-2">Full Name *</label>
                        <input type="text" name="customerName" id="customer-name" class="w-full px-4 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-sage-500 focus:border-sage-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-stone-700 mb-2">Email Address *</label>
                        <input type="email" name="customerEmail" id="customer-email" class="w-full px-4 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-sage-500 focus:border-sage-500" required>
                    </div>
                    
                    <!-- Account Creation Option -->
                    <div id="create-account-section" class="bg-sage-50 border border-sage-200 rounded-lg p-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="create-account-checkbox" class="w-4 h-4 text-sage-600 rounded focus:ring-sage-500">
                            <span class="text-sm font-medium text-stone-700">Create an OnlyBikes Rewards account to earn points on this order</span>
                        </label>
                        <div id="account-password-fields" class="hidden mt-3 space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-stone-700 mb-1">Password (min 8 characters)</label>
                                <input type="password" id="account-password" class="w-full px-4 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-sage-500 focus:border-sage-500" minlength="8">
                            </div>
                            <p class="text-xs text-stone-600">Your account will be created with the name and email from this order. You'll earn 1 point per $1 spent!</p>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-stone-700 mb-2">Phone Number *</label>
                        <input type="tel" name="phoneNumber" id="phone-number" class="w-full px-4 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-sage-500 focus:border-sage-500" required placeholder="(555) 123-4567">
                    </div>
                    
                    <div class="border-t border-stone-200 pt-4 mt-4">
                        <h4 class="font-medium text-stone-800 mb-4">Shipping Address</h4>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-stone-700 mb-1">Street Address *</label>
                                <input type="text" name="streetAddress" id="street-address" class="w-full px-4 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-sage-500 focus:border-sage-500" placeholder="123 Main Street" required>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-stone-700 mb-1">Apt/Unit/Suite (optional)</label>
                                <input type="text" name="address2" id="address-2" class="w-full px-4 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-sage-500 focus:border-sage-500" placeholder="Unit 4, Apartment B, etc.">
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-stone-700 mb-1">City *</label>
                                    <input type="text" name="city" id="city" class="w-full px-4 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-sage-500 focus:border-sage-500" placeholder="Toronto" required>
                                </div>
                                <div>
                                    <label id="province-label" class="block text-sm font-medium text-stone-700 mb-1">Province *</label>
                                    <select name="province" id="province" onchange="updateShippingEstimate()" class="w-full px-4 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-sage-500 focus:border-sage-500" required>
                                        <option value="">Select...</option>
                                        <option value="ON">Ontario</option>
                                        <option value="QC">Quebec</option>
                                        <option value="BC">British Columbia</option>
                                        <option value="AB">Alberta</option>
                                        <option value="MB">Manitoba</option>
                                        <option value="SK">Saskatchewan</option>
                                        <option value="NS">Nova Scotia</option>
                                        <option value="NB">New Brunswick</option>
                                        <option value="NL">Newfoundland & Labrador</option>
                                        <option value="PE">PEI</option>
                                        <option value="NT">Northwest Territories</option>
                                        <option value="NU">Nunavut</option>
                                        <option value="YT">Yukon</option>
                                    </select>
                                    <input type="text" name="provinceIntl" id="province-intl" class="hidden w-full px-4 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-sage-500 focus:border-sage-500" placeholder="State / region">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-stone-700 mb-1">Postal / ZIP Code *</label>
                                    <input type="text" name="postalCode" id="postal-code" oninput="var c=getShippingCountry(); var v=this.value.replace(/\s/g,''); if(postalReadyForQuote(c,v)) updateShippingEstimate();" onblur="updateShippingEstimate()" class="w-full px-4 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-sage-500 focus:border-sage-500" placeholder="A1A 1A1" required maxlength="12">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-stone-700 mb-1">Country *</label>
                                    <select name="country" id="country" onchange="syncProvinceFieldForCountry(); updateShippingEstimate();" class="w-full px-4 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-sage-500 focus:border-sage-500" required>
                                        <option value="CA" selected>Canada</option>
                                        <option value="US">United States</option>
                                        <option value="GB">United Kingdom</option>
                                        <option value="AU">Australia</option>
                                        <option value="NZ">New Zealand</option>
                                        <option value="DE">Germany</option>
                                        <option value="FR">France</option>
                                        <option value="NL">Netherlands</option>
                                        <option value="IE">Ireland</option>
                                        <option value="IT">Italy</option>
                                        <option value="ES">Spain</option>
                                        <option value="SE">Sweden</option>
                                        <option value="NO">Norway</option>
                                        <option value="DK">Denmark</option>
                                        <option value="FI">Finland</option>
                                        <option value="BE">Belgium</option>
                                        <option value="CH">Switzerland</option>
                                        <option value="AT">Austria</option>
                                        <option value="JP">Japan</option>
                                        <option value="KR">South Korea</option>
                                        <option value="SG">Singapore</option>
                                        <option value="HK">Hong Kong</option>
                                        <option value="MX">Mexico</option>
                                        <option value="BR">Brazil</option>
                                        <option value="IN">India</option>
                                        <option value="PH">Philippines</option>
                                        <option value="TH">Thailand</option>
                                        <option value="AE">United Arab Emirates</option>
                                        <option value="IL">Israel</option>
                                        <option value="ZA">South Africa</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="shipping-estimate-container" class="mt-4">
                        <div id="shipping-estimate" class="hidden"></div>
                        <div id="shipping-estimate-placeholder" class="bg-stone-50 border border-stone-200 rounded-lg p-4 text-sm text-stone-600">
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-stone-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                </svg>
                                <span>Enter your address to see live shipping rates</span>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Section -->
                    <div id="payment-wrapper" class="mt-4 hidden">
                        <h4 class="font-semibold text-stone-800 mb-2">Payment Details</h4>
                        <div id="order-summary-display" class="bg-stone-50 border border-stone-200 rounded-lg p-4 mb-3 text-sm">
                            <div class="flex justify-between mb-1"><span class="text-stone-600">Subtotal:</span><span id="summary-subtotal" class="font-medium">-</span></div>
                            <div id="summary-discount-row" class="flex justify-between mb-1 hidden"><span class="text-stone-600">Discount:</span><span id="summary-discount" class="font-medium text-green-600">-</span></div>
                            <div class="flex justify-between mb-1"><span class="text-stone-600">Shipping:</span><span id="summary-shipping" class="font-medium">-</span></div>
                            <div id="summary-handling-row" class="flex justify-between mb-1"><span class="text-stone-600" id="summary-handling-label">Handling:</span><span id="summary-handling" class="font-medium">-</span></div>
                            <div class="flex justify-between mb-1"><span class="text-stone-600">Tax:</span><span id="summary-tax" class="font-medium">-</span></div>
                            <div class="border-t border-stone-200 pt-2 mt-2 flex justify-between font-bold text-stone-800"><span>Total:</span><span id="summary-total">-</span></div>
                        </div>
                        <div id="payment-element-container" class="p-4 border border-stone-200 rounded-lg bg-stone-50 min-h-[120px]"></div>
                        <div id="payment-message" class="mt-2 text-sm"></div>
                    </div>

                    <!-- Coupon Code Section -->
                    <div class="border-t border-stone-200 pt-4 mt-4">
                        <label class="block text-sm font-medium text-stone-700 mb-2">Coupon Code (optional)</label>
                        <div class="flex gap-2">
                            <input type="text" id="coupon-input" class="flex-1 px-3 py-2 border border-stone-300 rounded-lg text-sm uppercase" placeholder="Enter code" maxlength="50">
                            <button type="button" id="coupon-apply-btn" class="bg-stone-800 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-stone-900 transition-colors">Apply</button>
                        </div>
                        <div id="coupon-message" class="mt-2 text-sm hidden"></div>
                        <div id="coupon-applied" class="hidden bg-green-50 border border-green-200 rounded-lg p-3 mt-3">
                            <div class="flex justify-between items-center">
                                <span class="text-green-800 font-medium" id="coupon-code-display"></span>
                                <button type="button" id="coupon-remove-btn" class="text-green-600 hover:text-green-800 text-sm font-medium">Remove</button>
                            </div>
                            <div class="text-green-700 text-sm mt-1" id="coupon-display-value"></div>
                        </div>
                    </div>

                    <!-- Pay with Points Section -->
                    <div class="border-t border-stone-200 pt-4 mt-4" id="points-section">
                        <label class="flex items-start cursor-pointer">
                            <input type="checkbox" id="use-points-checkbox" name="use_points" class="mt-1 h-5 w-5 text-sage-600 border-stone-300 rounded focus:ring-sage-500">
                            <div class="ml-3">
                                <span class="block text-stone-800 font-semibold">Pay with Points</span>
                                <span class="block text-sm text-stone-600">Use your reward points to pay for this order (1 point = $0.05). Minimum $1.00 payment required.</span>
                            </div>
                        </label>
                        <div id="points-info" class="mt-2 text-sm text-sage-700 bg-sage-50 rounded-lg p-3 hidden">
                            <span id="points-balance-display">0</span> points available = <strong id="points-discount-display">$0.00</strong> discount
                        </div>
                    </div>
                    
                    <!-- Subscription Toggle -->
                    <div class="mb-6 bg-sage-50 p-4 rounded-lg border border-sage-200">
                        <label class="flex items-start cursor-pointer">
                            <input type="checkbox" id="subscribe-checkbox" name="is_subscription" class="mt-1 h-5 w-5 text-sage-600 border-stone-300 rounded focus:ring-sage-500" ${orderDetails.isSubscription ? 'checked' : ''}>
                            <div class="ml-3">
                                <span class="block text-stone-800 font-semibold">Subscribe & Save 5%</span>
                                <span class="block text-sm text-stone-600">Auto-ship and never run out. Cancel anytime.</span>
                            </div>
                        </label>
                        <div id="subscription-intervals" class="mt-3 ml-8 hidden">
                            <label class="block text-sm font-medium text-stone-700 mb-1">Deliver every:</label>
                            <select id="subscription-interval" class="w-full px-4 py-2 border border-stone-300 rounded-lg">
                                <option value="1" ${orderDetails.subscriptionInterval === '1' ? 'selected' : ''}>Every month (brake pads / grips)</option>
                                <option value="2" ${orderDetails.subscriptionInterval === '2' ? 'selected' : ''}>Every 3 months (consumables refresh)</option>
                                <option value="3" ${orderDetails.subscriptionInterval === '3' ? 'selected' : ''}>3 Months</option>
                            </select>
                        </div>
                    </div>

                    <!-- Terms acceptance (required) -->
                    <div class="border-t border-stone-200 pt-4 mt-4">
                        <label class="flex items-start cursor-pointer">
                            <input type="checkbox" id="terms-accept-checkbox" name="terms_accepted" required class="mt-1 h-5 w-5 text-sage-600 border-stone-300 rounded focus:ring-sage-500">
                            <span class="ml-3 text-sm text-stone-700">
                                I agree that <strong>all sales are final with no refunds, returns, or exchanges</strong>. I am solely responsible for fitment, installation, and use. OnlyBikes is not liable for misfit parts, damage, injury, loss, or any other claim. I have read the <a href="terms.html" target="_blank" rel="noopener" class="text-green-700 underline">Terms</a> and <a href="returns.html" target="_blank" rel="noopener" class="text-green-700 underline">No-Return Policy</a>.
                            </span>
                        </label>
                    </div>

                    <!-- Newsletter Checkbox (keep this below) -->
                    <div class="flex items-start space-x-3 pt-2">
                        <input type="checkbox" id="newsletter-subscribe" name="newsletter" class="mt-1 h-4 w-4 text-sage-600 border-stone-300 rounded focus:ring-sage-500">
                        <label for="newsletter-subscribe" class="text-sm text-stone-700">
                            Subscribe to our newsletter for product updates, special offers, and fitment drops
                        </label>
                    </div>
                    
                    <!-- Security timestamp -->
                    <input type="hidden" name="form_timestamp" id="form-timestamp" value="${timestamp}">
                    
                    <div class="flex space-x-4 pt-4">
                        <button type="button" id="cancel-order-btn" class="flex-1 border border-stone-300 text-stone-700 py-2 rounded-lg hover:bg-stone-50 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" id="submit-order-btn" class="flex-1 bg-sage-600 text-white py-2 rounded-lg hover:bg-sage-700 transition-colors">
                            Submit Order
                        </button>
                    </div>
                </form>
                
                <div id="order-status" class="mt-4 hidden"></div>
            </div>
        `;
        
        document.body.appendChild(modal);
        syncProvinceFieldForCountry();

        // Initialize Stripe Payment Element immediately
        initPayment(orderDetails);

        // --- Coupon event listeners ---
        const couponApplyBtn = document.getElementById('coupon-apply-btn');
        const couponRemoveBtn = document.getElementById('coupon-remove-btn');
        const couponInput = document.getElementById('coupon-input');
        const couponAppliedDiv = document.getElementById('coupon-applied');
        const couponMessage = document.getElementById('coupon-message');
        const cartTotalSpan = document.getElementById('cart-total');

        if (couponApplyBtn) {
            couponApplyBtn.addEventListener('click', async () => {
                const code = couponInput.value.trim().toUpperCase();
                if (!code) return;

                couponApplyBtn.disabled = true;
                couponApplyBtn.textContent = 'Applying...';

                try {
                    const response = await fetch('/api/validate-coupon.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ code, subtotal: window.currentOrderDetails.subtotal })
                    });
                    const result = await response.json();

                    if (result.success) {
                        console.log('Coupon validated successfully:', result);
                        // Store coupon data on orderDetails for later submission
                        window.currentOrderDetails.coupon = {
                            code: result.code,
                            discount: result.discount,
                            originalSubtotal: window.currentOrderDetails.subtotal,
                            finalTotal: result.newTotal
                        };
                        // Update UI
                        document.getElementById('coupon-code-display').textContent = result.code;
                        document.getElementById('coupon-display-value').textContent =
                            `-${result.displayValue} discount`;
                        if (cartTotalSpan) cartTotalSpan.textContent = `$${result.newTotal.toFixed(2)}`;
                        if (couponAppliedDiv) couponAppliedDiv.classList.remove('hidden');
                        couponInput.value = '';
                        couponMessage.textContent = result.message;
                        couponMessage.className = 'mt-2 text-sm text-green-600';

                        // Log coupon applied event
                        if (window.beddaLogger) {
                            window.beddaLogger.logEvent('coupon_applied', {
                                code: result.code,
                                discount: result.discount,
                                originalSubtotal: window.currentOrderDetails.subtotal,
                                finalTotal: result.newTotal
                            });
                        }

                        // Recreate payment intent with new total
                        await recreatePaymentIntent();
                        updateOrderSummary();
                    } else {
                        console.warn('Shipping API returned no options:', result);
                        couponMessage.textContent = result.message;
                        couponMessage.className = 'mt-2 text-sm text-red-600';
                        delete window.currentOrderDetails.coupon;

                        // Log coupon failed event
                        if (window.beddaLogger) {
                            window.beddaLogger.logEvent('coupon_failed', {
                                code: code,
                                reason: result.message
                            });
                        }
                    }
                    couponMessage.classList.remove('hidden');
                } catch (err) {
                    couponMessage.textContent = 'Network error. Please try again.';
                    couponMessage.className = 'mt-2 text-sm text-red-600';
                    couponMessage.classList.remove('hidden');
                } finally {
                    couponApplyBtn.disabled = false;
                    couponApplyBtn.textContent = 'Apply';
                }
            });
        }

        if (couponRemoveBtn) {
            couponRemoveBtn.addEventListener('click', async () => {
                delete window.currentOrderDetails.coupon;
                if (couponAppliedDiv) couponAppliedDiv.classList.add('hidden');
                if (cartTotalSpan) cartTotalSpan.textContent = `$${window.currentOrderDetails.subtotal.toFixed(2)}`;
                if (couponMessage) {
                    couponMessage.textContent = 'Coupon removed';
                    couponMessage.className = 'mt-2 text-sm text-green-600';
                    couponMessage.classList.remove('hidden');
                    setTimeout(() => couponMessage.classList.add('hidden'), 3000);
                }
                // Recreate payment intent without coupon
                await recreatePaymentIntent();
                updateOrderSummary();
            });
        }

        // === POINTS EVENT LISTENERS ===
        const usePointsCheckbox = document.getElementById('use-points-checkbox');
        const pointsInfo = document.getElementById('points-info');
        const pointsBalanceDisplay = document.getElementById('points-balance-display');
        const pointsDiscountDisplay = document.getElementById('points-discount-display');

        // Show points info if logged in
        if (window.currentUser && window.currentUser.points > 0) {
            if (pointsInfo) pointsInfo.classList.remove('hidden');
            if (pointsBalanceDisplay) pointsBalanceDisplay.textContent = window.currentUser.points;
            if (pointsDiscountDisplay) pointsDiscountDisplay.textContent = '$' + (window.currentUser.points * 0.05).toFixed(2);
        }

        if (usePointsCheckbox) {
            usePointsCheckbox.addEventListener('change', async (e) => {
                if (!window.currentUser) {
                    e.preventDefault();
                    e.target.checked = false;
                    alert('Please sign in to use your reward points.');
                    return;
                }

                if (window.currentUser.points <= 0) {
                    e.preventDefault();
                    e.target.checked = false;
                    alert('You have no points available. Earn points by making purchases!');
                    return;
                }

                window.currentOrderDetails.use_points = e.target.checked;
                window.currentOrderDetails.customer_id = window.currentUser.id;
                
                // Recreate payment intent with points
                await recreatePaymentIntent();
                updateOrderSummary();
            });
        }

        // === SUBSCRIPTION EVENT LISTENERS ===
        const subscribeCheck = document.getElementById('subscribe-checkbox');
        const intervalSelect = document.getElementById('subscription-interval');
        const intervalContainer = document.getElementById('subscription-intervals');

        if (subscribeCheck) {
            subscribeCheck.addEventListener('change', (e) => {
                // Toggle interval selector visibility
                if (intervalContainer) {
                    intervalContainer.classList.toggle('hidden', !e.target.checked);
                }
                
                // If enabling subscription, disable coupon and points (only 5% discount allowed)
                if (e.target.checked) {
                    // Clear coupon
                    if (window.currentOrderDetails.coupon) {
                        window.currentOrderDetails.coupon = null;
                        const couponInput = document.getElementById('coupon-input');
                        const couponStatus = document.getElementById('coupon-status');
                        if (couponInput) couponInput.value = '';
                        if (couponStatus) couponStatus.classList.add('hidden');
                    }
                    
                    // Clear points
                    const usePointsCheckbox = document.getElementById('use-points-checkbox');
                    if (usePointsCheckbox) {
                        usePointsCheckbox.checked = false;
                        usePointsCheckbox.disabled = true;
                        window.currentOrderDetails.use_points = false;
                    }
                    
                    // Disable coupon input
                    const couponInput = document.getElementById('coupon-input');
                    const couponApplyBtn = document.getElementById('coupon-apply-btn');
                    if (couponInput) couponInput.disabled = true;
                    if (couponApplyBtn) couponApplyBtn.disabled = true;
                } else {
                    // Re-enable coupon and points when subscription is disabled
                    const usePointsCheckbox = document.getElementById('use-points-checkbox');
                    if (usePointsCheckbox) usePointsCheckbox.disabled = false;
                    
                    const couponInput = document.getElementById('coupon-input');
                    const couponApplyBtn = document.getElementById('coupon-apply-btn');
                    if (couponInput) couponInput.disabled = false;
                    if (couponApplyBtn) couponApplyBtn.disabled = false;
                }
                
                // Update order details
                window.currentOrderDetails.isSubscription = e.target.checked;
                window.currentOrderDetails.subscriptionInterval = intervalSelect?.value || '1';
                // Refresh payment intent and summary
                recreatePaymentIntent();
                updateOrderSummary();
            });
        }

        if (intervalSelect) {
            intervalSelect.addEventListener('change', (e) => {
                window.currentOrderDetails.subscriptionInterval = e.target.value;
                recreatePaymentIntent();
            });
        }
        // === END SUBSCRIPTION LISTENERS ===

        if (couponInput) {
            couponInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (couponApplyBtn) couponApplyBtn.click();
                }
            });
        }

        // IMPORTANT: Attach event listeners AFTER adding to DOM
        document.getElementById('close-order-form-modal').addEventListener('click', () => {
            modal.remove();
        });
        
        document.getElementById('cancel-order-btn').addEventListener('click', () => {
            modal.remove();
        });
        
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.remove();
        });
        
        // Handle form submission
        document.getElementById('order-submission-form').addEventListener('submit', async (e) => {
            e.preventDefault();

            const orderDetails = window.currentOrderDetails || {};
            
            // Validate that order has items and subtotal
            if (!orderDetails.items || orderDetails.items.length === 0) {
                alert('Your cart is empty. Please add items before submitting your order.');
                return;
            }
            
            if (!orderDetails.subtotal || orderDetails.subtotal <= 0) {
                alert('Invalid order total. Please add items to your cart.');
                return;
            }

            const submitBtn = document.getElementById('submit-order-btn');
            const statusDiv = document.getElementById('order-status');

            // Gather current form values
            const customerName = document.getElementById('customer-name').value.trim();
            const customerEmail = document.getElementById('customer-email').value.trim();
            const phoneNumber = document.getElementById('phone-number').value.trim();
            const streetAddress = document.getElementById('street-address').value.trim();
            const city = document.getElementById('city').value.trim();
            const province = getProvinceForQuote();
            const country = getShippingCountry();
            const postalCode = (document.getElementById('postal-code')?.value || '').trim();
            const fulfillmentMethod = document.querySelector('input[name="fulfillment_method"]:checked')?.value || 'shipping';

            // Validate required fields first with friendly messages
            const missing = [];
            
            // Name validation (min 2 chars)
            if (!customerName || customerName.length < 2) {
                missing.push('Valid Full Name (at least 2 characters)');
            }
            
            // Email validation
            if (!customerEmail || !customerEmail.includes('@')) {
                missing.push('Valid Email Address');
            }
            
            // Phone validation (at least 10 digits)
            const cleanPhone = phoneNumber.replace(/\D/g, '');
            if (!phoneNumber || cleanPhone.length < 10) {
                missing.push('Valid Phone Number (at least 10 digits)');
            }

            if (!document.getElementById('terms-accept-checkbox')?.checked) {
                missing.push('Acceptance of Terms and No-Return Policy (required checkbox)');
            }

            // Check if account creation is requested
            const createAccount = document.getElementById('create-account-checkbox')?.checked || false;
            const accountPassword = document.getElementById('account-password')?.value || '';
            
            if (createAccount) {
                if (!accountPassword || accountPassword.length < 8) {
                    missing.push('Password (min 8 characters)');
                }
            }

            if (fulfillmentMethod === 'shipping') {
                if (!streetAddress || streetAddress.length < 5) missing.push('Valid Street Address');
                if (!city || city.length < 2) missing.push('Valid City');
                if ((country === 'CA' || country === 'US') && !province) {
                    missing.push(country === 'US' ? 'State' : 'Province');
                }

                const cleanPostal = (postalCode || '').replace(/\s/g, '').toUpperCase();
                if (country === 'CA') {
                    const postalRegex = /^[A-Z][0-9][A-Z][0-9][A-Z][0-9]$/;
                    if (!postalCode || !postalRegex.test(cleanPostal)) {
                        missing.push('Valid Canadian postal code (e.g., A1A 1A1)');
                    }
                } else if (country === 'US') {
                    if (!/^\d{5}(\d{4})?$/.test(cleanPostal)) {
                        missing.push('Valid US ZIP code (e.g., 90210)');
                    }
                } else if (!cleanPostal || cleanPostal.length < 3) {
                    missing.push('Valid postal code');
                }

                if (!isLiveShippingOption(window.selectedShippingOption)) {
                    missing.push('Live shipping rate (wait for Chit Chats rates to load, or tap Try again)');
                }
            } else {
                const pickupLocation = document.getElementById('pickup-location')?.value;
                const pickupDate = document.getElementById('pickup-date')?.value;
                if (!pickupLocation) missing.push('Pickup Location');
                if (!pickupDate) missing.push('Pickup Date');
            }

            if (missing.length > 0) {
                statusDiv.className = 'mt-4 bg-sage-50 border border-sage-200 p-4 rounded-lg';
                statusDiv.innerHTML = `
                    <h4 class="font-semibold text-sage-800 mb-2">Almost there!</h4>
                    <p class="text-sm text-sage-700">Please fill in the following to complete your order:</p>
                    <ul class="text-sm text-sage-700 mt-2 list-disc list-inside">
                        ${missing.map(m => `<li>${m}</li>`).join('')}
                    </ul>
                    <p class="text-sm text-sage-700 mt-2">The payment form will appear once all details are entered.</p>
                `;
                statusDiv.classList.remove('hidden');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Processing Payment...';
            statusDiv.className = 'mt-4 text-center text-sage-600';
            statusDiv.textContent = 'Processing your payment...';
            statusDiv.classList.remove('hidden');

            const address2 = (document.getElementById('address-2')?.value || '').trim();
            const cleanPostalCode = postalCode.replace(/\s/g,'').toUpperCase();

            // Log checkout start event
            if (window.beddaLogger) {
                window.beddaLogger.logEvent('checkout_start', {
                    orderNumber: orderDetails.orderNumber,
                    itemCount: orderDetails.items ? orderDetails.items.length : 0,
                    subtotal: orderDetails.subtotal,
                    fulfillmentMethod: fulfillmentMethod
                });
            }

            try {
                // If Stripe isn't ready, try to re-initialize once
                if (!window.stripeInstance || !window.stripeElements) {
                    statusDiv.textContent = 'Preparing payment form...';
                    await initPayment(orderDetails);
                    await new Promise(r => setTimeout(r, 500));
                }

                if (!window.stripeInstance || !window.stripeElements) {
                    throw new Error('Payment form could not be loaded. Please check your internet connection and try again.');
                }

                // Pre-save order payload to localStorage in case 3D Secure redirects
                const pendingOrderPayload = {
                    orderNumber: orderDetails.orderNumber,
                    orderDate: orderDetails.date || new Date().toLocaleString(),
                    customerName: customerName,
                    customerEmail: customerEmail,
                    phoneNumber: phoneNumber,
                    streetAddress: streetAddress,
                    address2: address2,
                    city: city,
                    province: province,
                    postalCode: cleanPostalCode,
                    country: country,
                    handling_cost: parseFloat(window.handlingCost?.cost || 0),
                    handling_type: window.handlingCost?.type || '',
                    items: (orderDetails.items || []).map(item => ({
                        product: item.product,
                        price: parseFloat(item.price) || 0,
                        quantity: parseInt(item.quantity) || 1
                    })),
                    subtotal: parseFloat(orderDetails.subtotal) || 0,
                    fulfillment_method: fulfillmentMethod,
                    pickup_location: fulfillmentMethod === 'pickup' ? (document.getElementById('pickup-location')?.value || '') : null,
                    pickup_date: fulfillmentMethod === 'pickup' ? (document.getElementById('pickup-date')?.value || '') : null,
                    shipping_option: window.selectedShippingOption ? {
                        id: window.selectedShippingOption.id,
                        carrier: window.selectedShippingOption.carrier,
                        total: parseFloat(window.selectedShippingOption.total) || 0
                    } : null,
                    coupon: orderDetails.coupon || null,
                    is_subscription: !!orderDetails.isSubscription,
                    subscription_interval: orderDetails.subscriptionInterval || '1',
                    newsletter: document.getElementById('newsletter-subscribe')?.checked || false,
                    form_timestamp: parseInt(document.getElementById('form-timestamp')?.value || Date.now() / 1000),
                    create_account: createAccount,
                    account_password: createAccount ? accountPassword : null,
                    customer_id: window.currentUser?.id || null,
                    customer_email: window.currentUser?.email || customerEmail
                };
                localStorage.setItem(window.ONLYBIKES_CONFIG?.pendingOrderKey || 'onlybikes_pending_order', JSON.stringify(pendingOrderPayload));
                localStorage.setItem(window.ONLYBIKES_CONFIG?.lastOrderKey || 'onlybikes_last_order', orderDetails.orderNumber);

                const clientSecret = window.stripeElements?.options?.clientSecret;
                if (clientSecret) {
                    const { paymentIntent: retrievedIntent, error: retrieveError } = await window.stripeInstance.retrievePaymentIntent(clientSecret);
                    if (retrievedIntent && retrievedIntent.status === 'succeeded') {
                        window.location.href = `/checkout-success.html?order_number=${orderDetails.orderNumber}&payment_intent=${retrievedIntent.id}`;
                        return;
                    }
                }

                const { error, paymentIntent } = await window.stripeInstance.confirmPayment({
                    elements: window.stripeElements,
                    confirmParams: {
                        return_url: `${beddaSiteOrigin()}/checkout-success.html?order_number=${orderDetails.orderNumber}`,
                        payment_method_data: {
                            billing_details: {
                                name: customerName,
                                email: customerEmail,
                                phone: phoneNumber,
                                address: {
                                    line1: streetAddress,
                                    line2: address2 || undefined,
                                    city: city,
                                    state: province,
                                    postal_code: cleanPostalCode,
                                    country: country
                                }
                            }
                        }
                    },
                    redirect: 'if_required'
                });

                if (error) throw new Error(error.message || 'Payment failed');

                // Payment succeeded (no redirect needed)
                statusDiv.className = 'mt-4 bg-green-50 border border-green-200 p-4 rounded-lg';
                statusDiv.innerHTML = `
                    <h4 class="font-semibold text-green-800 mb-2">Payment Successful!</h4>
                    <p class="text-sm text-green-700">Saving your order...</p>
                `;

                // Build order payload for backend
                const orderPayload = {
                    orderNumber: orderDetails.orderNumber,
                    orderDate: orderDetails.date || new Date().toLocaleString(),
                    customerName: customerName,
                    customerEmail: customerEmail,
                    phoneNumber: phoneNumber,
                    streetAddress: streetAddress,
                    address2: address2,
                    city: city,
                    province: province,
                    postalCode: cleanPostalCode,
                    country: country,
                    items: (orderDetails.items || []).map(item => ({
                        product: item.product,
                        price: parseFloat(item.price) || 0,
                        quantity: parseInt(item.quantity) || 1
                    })),
                    subtotal: parseFloat(orderDetails.subtotal) || 0,
                    fulfillment_method: fulfillmentMethod,
                    pickup_location: fulfillmentMethod === 'pickup' ? (document.getElementById('pickup-location')?.value || '') : null,
                    pickup_date: fulfillmentMethod === 'pickup' ? (document.getElementById('pickup-date')?.value || '') : null,
                    shipping_option: window.selectedShippingOption ? {
                        id: window.selectedShippingOption.id,
                        carrier: window.selectedShippingOption.carrier,
                        total: parseFloat(window.selectedShippingOption.total) || 0
                    } : null,
                    coupon: orderDetails.coupon || null,
                    use_points: orderDetails.use_points || false,
                    newsletter: document.getElementById('newsletter-subscribe')?.checked || false,
                    form_timestamp: parseInt(document.getElementById('form-timestamp')?.value || Date.now() / 1000),
                    taxAmount: parseFloat(document.getElementById('summary-tax')?.textContent.replace('$', '')) || 0,
                    grandTotal: parseFloat(document.getElementById('summary-total')?.textContent.replace('$', '')) || 0,
                    payment_intent_id: paymentIntent?.id || null,
                    is_subscription: !!orderDetails.isSubscription,
                    subscription_interval: orderDetails.subscriptionInterval || '1',
                    payment_status: paymentIntent?.status || 'unknown',
                    create_account: createAccount,
                    account_password: createAccount ? accountPassword : null,
                    customer_id: window.currentUser?.id || null,
                    customer_email: window.currentUser?.email || customerEmail
                };

                // Save to localStorage in case redirect happens (3D Secure)
                localStorage.setItem(window.ONLYBIKES_CONFIG?.pendingOrderKey || 'onlybikes_pending_order', JSON.stringify(orderPayload));
                localStorage.setItem(window.ONLYBIKES_CONFIG?.lastOrderKey || 'onlybikes_last_order', orderDetails.orderNumber);

                // Submit order to backend
                let submitResponse = null;
                let submitResult = null;

                try {
                    submitResponse = await fetch('/api/submit-order.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify(orderPayload)
                    });

                    submitResult = await submitResponse.json();

                    if (!submitResponse.ok || !submitResult.success) {
                        console.error('Order save failed:', submitResult);
                        throw new Error(submitResult.message || 'Order could not be saved. Please contact support@onlybikes.shop');
                    }

                    // Clear cart
                    if (window.cartManager) {
                        window.cartManager.items = [];
                        window.cartManager.saveCart();
                        window.cartManager.renderCartCount();
                    }

                    const accountCreated = submitResult.account_created;
                    const pointsEarned   = submitResult.points_earned || 0;
                    const rewardCoupon   = submitResult.reward_coupon || null;

                    if (window.currentUser && submitResult.total_points !== undefined) {
                        window.currentUser.points = submitResult.total_points;
                        saveUserToStorage(window.currentUser);
                        updateRewardsDisplay();
                    }

                    // Newsletter signup runs server-side in submit-order.php (avoids fetch cancelled on redirect)
                    const nlResult = submitResult.newsletter;
                    const nlMessage = nlResult && nlResult.success && nlResult.confirmationEmailSent
                        ? '<p class="text-sm text-sage-700 mt-1">Check your inbox to confirm your newsletter subscription.</p>'
                        : (nlResult && nlResult.success && nlResult.confirmationEmailSent === false
                            ? '<p class="text-sm text-amber-700 mt-1">Newsletter signup saved — confirmation email may arrive shortly.</p>'
                            : '');

                    statusDiv.innerHTML = `
                        <h4 class="font-semibold text-green-800 mb-2">Order Confirmed!</h4>
                        <p class="text-sm text-green-700">Order #${orderDetails.orderNumber}</p>
                        <p class="text-sm text-green-700">You earned <strong>${pointsEarned} points</strong>!</p>
                        ${accountCreated ? '<p class="text-sm text-sage-700 mt-1 font-medium">Your OnlyBikes Rewards account was created! You can now log in with your email and password.</p>' : ''}
                        ${rewardCoupon ? `<p class="text-sm text-sage-700 mt-1 font-medium">Reward unlocked: <strong>${submitResult.coupon_discount}% OFF</strong> coupon ready on the next page!</p>` : ''}
                        ${nlMessage}
                        <p class="text-sm text-green-700 mt-1">Redirecting to confirmation...</p>
                    `;

                    setTimeout(() => {
                        let successUrl = `${beddaSiteOrigin()}/checkout-success.html?order_number=${orderDetails.orderNumber}`;
                        if (submitResult.upsell_code && submitResult.upsell_value) {
                            successUrl += `&upsell_code=${submitResult.upsell_code}&upsell_value=${submitResult.upsell_value}`;
                        }
                        if (accountCreated) {
                            successUrl += '&account_created=1';
                        }
                        if (rewardCoupon) {
                            successUrl += `&reward_coupon=${encodeURIComponent(rewardCoupon)}&coupon_discount=${submitResult.coupon_discount || 0}&coupon_expires=${encodeURIComponent(submitResult.coupon_expires || '')}`;
                        }
                        window.location.href = successUrl;
                    }, 1500);

                } catch (submitErr) {
                    console.error('Submit order error:', submitErr);

                    // Try to log raw response for debugging (only if response exists and body not consumed)
                    if (submitResponse && submitResponse.bodyUsed === false) {
                        try {
                            const text = await submitResponse.text();
                            console.error('Raw server response (first 800 chars):', text.substring(0, 800));
                        } catch (debugErr) {
                            console.error('Could not read response text:', debugErr);
                        }
                    }

                    statusDiv.className = 'mt-4 bg-sage-50 border border-sage-200 p-4 rounded-lg';
                    statusDiv.innerHTML = `
                        <h4 class="font-semibold text-sage-800 mb-2">Payment Received, Order Pending</h4>
                        <p class="text-sm text-sage-700">Your payment went through, but we had trouble saving your order details.</p>
                        <p class="text-sm text-sage-700 mt-2">Please email <strong>support@onlybikes.shop</strong> with your order number <strong>#${orderDetails.orderNumber}</strong> and we'll sort it out right away.</p>
                        <p class="text-sm text-sage-700 mt-2">No additional charge will be made.</p>
                    `;
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Order';
                }

            } catch (error) {
                console.error('Payment error:', error);
                statusDiv.className = 'mt-4 bg-red-50 border border-red-200 p-4 rounded-lg';
                statusDiv.innerHTML = `<h4 class="font-semibold text-red-800 mb-2">Payment Failed</h4><p class="text-sm text-red-700">${error.message}</p>`;
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit Order';
            }
        });

        // Wire up account creation checkbox now that modal is in the DOM
        initAccountCreation();
    }

    formatOrderForEmail(order) {
        let text = `ONLYBIKES ORDER\n`;
        text += `Order Number: ${order.orderNumber}\n`;
        text += `Date: ${order.date}\n\n`;
        text += `ORDER ITEMS:\n`;
        
        order.items.forEach((item, index) => {
            text += `${index + 1}. ${item.product} - $${item.price.toFixed(2)} x ${item.quantity} = $${(item.price * item.quantity).toFixed(2)}\n`;
        });
        
        text += `\nSUBTOTAL: $${order.subtotal.toFixed(2)}\n\n`;
        text += `SHIPPING INFORMATION:\n`;
        text += `Please provide your shipping address and postal code in your email.\n\n`;
        text += `PAYMENT:\n`;
        text += `Payment instructions will be provided via email after we receive your order.\n\n`;
        text += `QUESTIONS?\n`;
        text += `Email us at support@onlybikes.shop\n`;
        
        return text;
    }

    showOrderModal(orderText, orderNumber) {
        // Create order modal
        const modal = document.createElement('div');
        modal.id = 'order-modal';
        modal.className = 'fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-0 sm:p-4';
        modal.innerHTML = `
            <div class="bg-white rounded-lg p-8 max-w-lg w-full mx-4 max-h-96 overflow-y-auto">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="font-playfair text-2xl font-bold text-stone-800">Order Created!</h3>
                    <button id="close-order-modal" class="text-stone-500 hover:text-stone-700">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 28 28">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <div class="mb-6 p-4 bg-sage-50 rounded-lg border border-sage-200">
                    <p class="text-sm text-sage-800 mb-2"><strong>Order Number:</strong></p>
                    <div class="flex items-center justify-between">
                        <code id="order-number" class="font-mono text-lg text-sage-900">${orderNumber}</code>
                        <button id="copy-order-number" class="text-sage-600 hover:text-sage-700 text-sm font-medium">
                            Copy
                        </button>
                    </div>
                </div>
                
                <div class="mb-6">
                    <h4 class="font-semibold text-stone-800 mb-3">Your Order Details:</h4>
                    <pre id="order-details" class="text-sm text-stone-700 bg-stone-50 p-4 rounded-lg overflow-x-auto whitespace-pre-wrap">${orderText}</pre>
                </div>
                
                <div class="mb-6 flex space-x-2">
                    <button id="copy-order-btn" class="flex-1 bg-sage-600 text-white py-2 rounded-lg font-semibold hover:bg-sage-700 transition-colors">
                        Copy Order Details
                    </button>
                </div>
                
                <div class="bg-green-50 p-4 rounded-lg border border-green-200 mb-6">
                    <h4 class="font-semibold text-green-800 mb-2">Next Steps:</h4>
                    <ol class="text-sm text-green-700 space-y-2 list-decimal list-inside">
                        <li>Copy your order details above</li>
                        <li>Email to: <strong>support@onlybikes.shop</strong></li>
                        <li>Include your shipping address and postal code</li>
                        <li>We'll reply with payment instructions and shipping details within 24 hours</li>
                    </ol>
                </div>
                
                <div class="text-center">
                    <button id="done-btn" class="bg-stone-800 text-white px-6 py-2 rounded-lg hover:bg-stone-900 transition-colors">
                        Done
                    </button>
                </div>
            </div>
        `;
        
        toggleFulfillmentFields();
        
        // Setup event listeners
        document.getElementById('close-order-modal').addEventListener('click', () => {
            if (document.body.contains(modal)) {
                document.body.removeChild(modal);
            }
        });
        
        document.getElementById('done-btn').addEventListener('click', () => {
            if (document.body.contains(modal)) {
                document.body.removeChild(modal);
            }
        });
        
        document.getElementById('copy-order-number').addEventListener('click', () => {
            navigator.clipboard.writeText(orderNumber);
            this.showCopyFeedback('Order number copied!');
        });
        
        document.getElementById('copy-order-btn').addEventListener('click', () => {
            navigator.clipboard.writeText(orderText);
            this.showCopyFeedback('Order details copied!');
        });
        
        // Close on outside click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                if (document.body.contains(modal)) {
                    document.body.removeChild(modal);
                }
            }
        });
        
        // Clear cart after order creation
        this.items = [];
        this.saveCart();
        this.renderCartCount();
        this.hideCartModal();
    }

    showCopyFeedback(message) {
        const feedback = document.createElement('div');
        feedback.className = 'fixed top-20 right-4 bg-green-500 text-white px-4 py-2 rounded-lg shadow-lg z-50';
        feedback.textContent = message;
        document.body.appendChild(feedback);
        
        setTimeout(() => {
            if (document.body.contains(feedback)) {
                document.body.removeChild(feedback);
            }
        }, 3000);
    }
}

// Initialize Stripe Payment Element in the order modal
async function initPayment(orderDetails) {
    const wrapper = document.getElementById('payment-wrapper');
    const msg = document.getElementById('payment-message');
    const container = document.getElementById('payment-element-container');

    if (!wrapper || !container) return;

    // Defensive check - if orderDetails is empty, try to get from window
    if (!orderDetails || !orderDetails.items || orderDetails.items.length === 0) {
        orderDetails = window.currentOrderDetails || {};
    }

    // Final validation - if still no items, show error
    if (!orderDetails.items || orderDetails.items.length === 0) {
        container.innerHTML = '<div class="text-center py-4 text-red-600">No items in cart. Please add items before proceeding.</div>';
        msg.innerHTML = 'Please add items to your cart first.';
        msg.className = 'mt-2 text-sm text-red-600';
        return;
    }

    const fulfillment_method = (document.querySelector('input[name="fulfillment_method"]:checked') || {}).value || 'shipping';
    if (fulfillment_method === 'shipping' && !isLiveShippingOption(window.selectedShippingOption)) {
        wrapper.classList.add('hidden');
        msg.innerHTML = 'Complete your address and wait for live shipping rates before paying.';
        msg.className = 'mt-2 text-sm text-amber-700';
        return;
    }

    wrapper.classList.remove('hidden');
    container.innerHTML = `
        <div class="space-y-3">
            <div class="skeleton skeleton-text long"></div>
            <div class="skeleton skeleton-input"></div>
            <div class="skeleton skeleton-input"></div>
            <div class="skeleton skeleton-button"></div>
        </div>
        <p class="text-sm text-stone-500 mt-3">Loading secure payment form...</p>
    `;

    try {
        const subtotal = parseFloat(orderDetails.subtotal) || 0;

        // Get shipping cost if available
        let shippingTotal = 0;
        if (window.selectedShippingOption && typeof window.selectedShippingOption.total === 'number') {
            shippingTotal = window.selectedShippingOption.total;
        }

        // Get province for tax calculation
        const province = document.getElementById('province')?.value || 'ON';
        const taxRate = getTaxRate(province);

        // Calculate final amount (subtotal - coupon + shipping + tax)
        let itemTotal = subtotal;
        if (orderDetails.coupon && orderDetails.coupon.finalTotal) {
            itemTotal = parseFloat(orderDetails.coupon.finalTotal);
        } else if (orderDetails.coupon && orderDetails.coupon.discount) {
            itemTotal = subtotal - parseFloat(orderDetails.coupon.discount);
        }

        const tax = (itemTotal + shippingTotal) * taxRate;

        const fulfillment_method = (document.querySelector('input[name="fulfillment_method"]:checked') || {}).value || 'shipping';
        let pickup_location = ''; 
        let pickup_date = '';
        if (fulfillment_method === 'pickup') {
            pickup_location = document.getElementById('pickup-location')?.value || '';
            pickup_date = document.getElementById('pickup-date')?.value || '';
        }

        // Build minimal intent payload (backend will recalculate totals securely)
        const intentData = {
            orderNumber: orderDetails.orderNumber || '',
            items: orderDetails.items || [],
            subtotal: subtotal, // client-side estimate only
            customerName: document.getElementById('customer-name')?.value || '',
            customerEmail: document.getElementById('customer-email')?.value || '',
            // Subscription fields
            isSubscription: !!orderDetails.isSubscription,
            interval: orderDetails.subscriptionInterval || '1',
            // Fulfillment
            fulfillment_method: fulfillment_method,
            pickup_location: pickup_location,
            pickup_date: pickup_date,
            // Shipping (if applicable)
            shipping_option: window.selectedShippingOption ? {
                total: parseFloat(window.selectedShippingOption.total) || 0
            } : null,
            // Coupon (if applied)
            coupon: orderDetails.coupon || null,
            // Points (if using)
            use_points: orderDetails.use_points || false,
            customer_id: orderDetails.customer_id || null,
            form_timestamp: parseInt(document.getElementById('form-timestamp')?.value || Date.now() / 1000)
        };

        // Route to correct endpoint
        const endpoint = orderDetails.isSubscription
            ? onlyBikesApiUrl('/api/create-subscription.php')
            : onlyBikesApiUrl('/api/create-payment-intent.php');

        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(intentData)
        });

        const result = await response.json();

        if (!response.ok || !result.success || !result.clientSecret) {
            const detail = result.error || result.details || result.message || `HTTP ${response.status}`;
            throw new Error(detail);
        }

        // Load Stripe if needed
        if (typeof Stripe === 'undefined') {
            await new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = 'https://js.stripe.com/v3/';
                script.onload = resolve;
                script.onerror = () => reject(new Error('Failed to load Stripe'));
                document.head.appendChild(script);
            });
        }

        const stripePublishableKey = result.stripePublishableKey || await resolveStripePublishableKey();
        if (!stripePublishableKey) throw new Error('Checkout is temporarily unavailable. Please try again later or contact support.');
        window.stripeInstance = Stripe(stripePublishableKey);
        window.stripeElements = window.stripeInstance.elements({ clientSecret: result.clientSecret });
        const paymentElement = window.stripeElements.create('payment');

        container.innerHTML = '';
        paymentElement.mount('#payment-element-container');

        // Update the order summary display
        updateOrderSummary();

        msg.textContent = 'Your payment information is secure and encrypted.';
        msg.className = 'mt-2 text-sm text-green-600';

    } catch (err) {
        console.error('Payment init error:', err);
        container.innerHTML = '';
        const hint = err && err.message ? err.message : 'Check your details and connection.';
        msg.innerHTML = 'Payment could not load: ' + hint;
        msg.className = 'mt-2 text-sm text-red-600';
    }
}

async function recreatePaymentIntent() {
    const orderDetails = window.currentOrderDetails;
    if (!orderDetails) return;

    // Clean up existing Stripe elements
    const container = document.getElementById('payment-element-container');
    if (container) container.innerHTML = '';

    window.stripeElements = null;
    window.stripeInstance = null;

    await initPayment(orderDetails);
}

// Update the order summary display above the payment form
function updateOrderSummary() {
    const orderDetails = window.currentOrderDetails;
    if (!orderDetails) return;

    const subtotalEl = document.getElementById('summary-subtotal');
    const discountRow = document.getElementById('summary-discount-row');
    const discountEl = document.getElementById('summary-discount');
    const shippingEl = document.getElementById('summary-shipping');
    const handlingRow = document.getElementById('summary-handling-row');
    const handlingEl = document.getElementById('summary-handling');
    const handlingLabel = document.getElementById('summary-handling-label');
    const taxEl = document.getElementById('summary-tax');
    const totalEl = document.getElementById('summary-total');

    if (!subtotalEl || !totalEl) return;

    const subtotal = parseFloat(orderDetails.subtotal) || 0;
    subtotalEl.textContent = `$${subtotal.toFixed(2)}`;

    // Discount - subscriptions only get 5%, regular orders can stack coupon + points
    let discount = 0;
    
    if (orderDetails.isSubscription) {
        // Subscription: only 5% discount (coupon and points not allowed)
        discount = subtotal * 0.05;
    } else {
        // Regular order: stack coupon and points
        if (orderDetails.coupon && orderDetails.coupon.discount) {
            discount += parseFloat(orderDetails.coupon.discount);
        } else if (orderDetails.coupon && orderDetails.coupon.originalSubtotal && orderDetails.coupon.finalTotal) {
            discount += parseFloat(orderDetails.coupon.originalSubtotal) - parseFloat(orderDetails.coupon.finalTotal);
        }
        
        if (orderDetails.use_points && window.currentUser && window.currentUser.points > 0) {
            discount += window.currentUser.points * 0.05;
        }
        
        // Cap discount at subtotal to prevent negative totals
        discount = Math.min(discount, subtotal);
    }

    if (discount > 0) {
        discountRow.classList.remove('hidden');
        discountEl.textContent = `-$${discount.toFixed(2)}`;
    } else {
        discountRow.classList.add('hidden');
    }

    // Shipping
    let shipping = 0;
    if (window.selectedShippingOption && typeof window.selectedShippingOption.total === 'number') {
        shipping = window.selectedShippingOption.total;
    }

    // FIX: Always recalculate handling from selected option or cart items
    const handling = window.selectedShippingOption?.handling 
        || calculateHandlingCost(window.cartManager?.items || []);
    const method = document.querySelector('input[name="fulfillment_method"]:checked')?.value || 'shipping';

    // FIX: Calculate tax BEFORE using it
    const province = document.getElementById('province')?.value || 'ON';
    const taxRate = getTaxRate(province);
    
    // Use the 'shipping' variable calculated right above it
    const taxableAmount = Math.max(0, subtotal - discount) + (method === 'shipping' ? shipping + handling.cost : 0);
    let tax = 0; // taxableAmount * taxRate; // UNCOMMENT THIS WHEN MAKING > 30K

    // NOW safe to use 'tax'
    if (taxEl) taxEl.textContent = `$${tax.toFixed(2)}`;

    // Show/hide handling row depending on fulfillment method
    if (handlingRow) {
        if (method === 'shipping') {
            handlingRow.classList.remove('hidden');
            if (handlingEl) handlingEl.textContent = `$${handling.cost.toFixed(2)}`;
            if (handlingLabel) handlingLabel.textContent = `Handling`;
        } else {
            handlingRow.classList.add('hidden');
        }
    }

    // Calculate total safely
    const total = Math.max(0, subtotal - discount) + (method === 'shipping' ? shipping + handling.cost : 0) + tax;
    if (totalEl) totalEl.textContent = `$${total.toFixed(2)}`;

    // Update shipping display
    if (method === 'pickup') {
        if (shippingEl) shippingEl.textContent = 'FREE (Pickup)';
    } else if (!isLiveShippingOption(window.selectedShippingOption)) {
        if (shippingEl) shippingEl.textContent = '-';
    } else {
        if (shippingEl) shippingEl.textContent = `$${shipping.toFixed(2)}`;
    }
}

// Toggle fulfillment fields visibility
function toggleFulfillmentFields() {
    const method = document.querySelector('input[name="fulfillment_method"]:checked').value;
    const shippingFields = document.getElementById('shipping-fields');
    const pickupFields = document.getElementById('pickup-fields');
    const pickupDate = document.getElementById('pickup-date');

    if (method === 'pickup') {
        shippingFields.classList.add('hidden');
        pickupFields.classList.remove('hidden');
        // Set min date to tomorrow
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        pickupDate.min = tomorrow.toISOString().split('T')[0];
        // Make shipping fields not required
        document.querySelectorAll('#shipping-fields [required]').forEach(el => {
            el.required = false;
            el.dataset.wasRequired = 'true';
        });
        // Make pickup fields required
        document.querySelectorAll('#pickup-fields [name]').forEach(el => {
            if (el.name === 'pickup_location' || el.name === 'pickup_date') {
                el.required = true;
            }
        });
    } else {
        shippingFields.classList.remove('hidden');
        pickupFields.classList.add('hidden');
        // Restore shipping field requirements
        document.querySelectorAll('#shipping-fields [data-was-required="true"]').forEach(el => {
            el.required = true;
            delete el.dataset.wasRequired;
        });
        // Make pickup fields not required
        document.querySelectorAll('#pickup-fields [name]').forEach(el => {
            el.required = false;
        });
        updateShippingEstimate();
    }
}

// Cross-Sell System
class CrossSellManager {
    constructor() {
        this.complementaryProducts = {
            'Ultra Bee Brake Kit': ['3-Inch Baja Style LED Headlight'],
            'Titanium Bolt Kit': ['3-Inch Baja Style LED Headlight'],
            '17x1.6 Supermoto Wheel Set for Talaria & Sur-Ron': ['3-Inch Baja Style LED Headlight', 'Front 70/100-19 & 90/100-16 Rear Tire + Tube for Off Road Dirt'],
            '3-Inch Baja Style LED Headlight': ['Front 70/100-19 & 90/100-16 Rear Tire + Tube for Off Road Dirt'],
            'Front 70/100-19 & 90/100-16 Rear Tire + Tube for Off Road Dirt': ['Titanium Bolt Kit']
        };
        this.productDatabase = {
            '3-Inch Baja Style LED Headlight': { price: 49.99, benefit: 'Baja-style visibility' },
            'Front 70/100-19 & 90/100-16 Rear Tire + Tube for Off Road Dirt': { price: 169.99, benefit: 'Off-road rubber + tubes' },
            'Titanium Bolt Kit': { price: 89.99, benefit: 'Dress-up hardware' }
        };
    }

    getProductInfo(productName) {
        return this.productDatabase[productName] || { price: 0, benefit: '' };
    }

    closeModal() {
        document.querySelectorAll('[data-cross-sell-modal]').forEach(el => el.remove());
    }

    showCrossSell(addedProduct) {
        const now = Date.now();
        if (this._lastShown && now - this._lastShown < 5000) return;

        const complementary = this.complementaryProducts[addedProduct] || [];
        if (complementary.length === 0) return;

        this.closeModal();
        this._lastShown = now;

        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black/70 flex items-center justify-center z-[110] p-4';
        modal.setAttribute('data-cross-sell-modal', 'true');

        const panel = document.createElement('div');
        panel.className = 'ob-card max-w-md w-full p-6';
        panel.innerHTML = `
            <div class="flex justify-between items-start mb-4">
                <div>
                    <div class="ob-badge mb-2">Complete the build</div>
                    <h3 class="font-display text-2xl uppercase">Don&rsquo;t forget</h3>
                </div>
                <button type="button" data-cross-sell-close class="text-zinc-400 hover:text-green-300 p-1" aria-label="Close">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="rounded-xl border border-zinc-800 bg-zinc-900/80 p-4 mb-6 text-sm text-zinc-400">
                <p class="text-zinc-200 mb-2">Riders often add these to finish the build.</p>
                <p><strong class="text-red-200">All sales final.</strong> You are responsible for fitment. OnlyBikes is not liable for misfit or any other issue. Items are not reserved until checkout is complete.</p>
            </div>
            <div class="space-y-3" data-cross-sell-options></div>
            <div class="mt-6 pt-6 border-t border-zinc-800 flex gap-3">
                <button type="button" data-cross-sell-kits class="flex-1 ob-btn ob-btn-ghost py-3 text-sm">View kits</button>
                <button type="button" data-cross-sell-cart class="flex-1 ob-btn ob-btn-primary py-3 text-sm">Continue to bag</button>
            </div>
        `;

        const optionsRoot = panel.querySelector('[data-cross-sell-options]');
        complementary.forEach(productName => {
            optionsRoot.appendChild(this.buildProductRow(productName));
        });

        panel.querySelector('[data-cross-sell-close]').addEventListener('click', () => this.closeModal());
        panel.querySelector('[data-cross-sell-kits]').addEventListener('click', () => {
            window.location.href = 'bundles.html';
        });
        panel.querySelector('[data-cross-sell-cart]').addEventListener('click', () => {
            this.closeModal();
            window.cartManager?.toggleCartModal();
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) this.closeModal();
        });

        modal.appendChild(panel);
        document.body.appendChild(modal);

        const logger = window.onlybikesLogger || window.beddaLogger;
        if (logger) {
            logger.logEvent('cross_sell_shown', {
                triggerProduct: addedProduct,
                suggestions: complementary
            });
        }
    }

    buildProductRow(productName) {
        const info = this.getProductInfo(productName);
        const row = document.createElement('div');
        row.className = 'flex items-center gap-4 p-3 border border-zinc-800 rounded-xl bg-zinc-950/50';

        const thumb = document.createElement('div');
        thumb.className = 'w-16 h-16 shrink-0 rounded-lg bg-zinc-800 flex items-center justify-center text-[10px] text-zinc-500 uppercase tracking-wide text-center px-1';
        thumb.textContent = 'OnlyBikes';

        const meta = document.createElement('div');
        meta.className = 'flex-1 min-w-0';
        const title = document.createElement('div');
        title.className = 'font-bold text-zinc-100 text-sm leading-snug';
        title.textContent = productName;
        const benefit = document.createElement('div');
        benefit.className = 'text-xs text-zinc-500 mt-0.5';
        benefit.textContent = info.benefit;
        const price = document.createElement('div');
        price.className = 'text-green-400 font-bold mt-1';
        price.textContent = '$' + info.price.toFixed(2);
        meta.appendChild(title);
        meta.appendChild(benefit);
        meta.appendChild(price);

        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'shrink-0 ob-btn ob-btn-primary px-4 py-2 text-sm min-h-[44px]';
        addBtn.textContent = 'Add';
        addBtn.addEventListener('click', () => {
            if (!window.cartManager || info.price <= 0) return;
            window.cartManager.addItem(productName, info.price, 1, { skipCrossSell: true });
            addBtn.textContent = 'Added';
            addBtn.disabled = true;
        });

        row.appendChild(thumb);
        row.appendChild(meta);
        row.appendChild(addBtn);
        return row;
    }
}

function calculateShippingEstimate(totalWeightGrams, province) {
    // Determine region from province
    let region;
    province = province?.toUpperCase() || 'ON';
    
    switch(province) {
        case 'ON': region = 'ontario'; break;
        case 'QC': region = 'quebec'; break;
        case 'MB':
        case 'SK': region = 'prairies'; break;
        case 'AB':
        case 'BC': region = 'west'; break;
        case 'NB':
        case 'NS':
        case 'PE':
        case 'NL': region = 'atlantic'; break;
        case 'YT':
        case 'NT':
        case 'NU': region = 'remote'; break;
        default: region = 'ontario';
    }
    
    // Find weight bracket
    let totalWeight = 0;
    items.forEach(item => {
        const qty = item.quantity || 1;
        let weight = 100;
        const prodName = item.product || '';
        if (PRODUCT_WEIGHTS[prodName]) {
            weight = PRODUCT_WEIGHTS[prodName];
        } else if (prodName.startsWith('Custom Loaf')) {
            weight = 1350;
        } else {
            for (const [key, val] of Object.entries(PRODUCT_WEIGHTS)) {
                if (prodName.includes(key)) { weight = val; break; }
            }
        }
        totalWeight += weight * qty;
    });
    
    const rates = SHIPPING_RATES[region];
    return {
        min: rates.min[bracketIndex],
        max: rates.max[bracketIndex],
        weight: totalWeightGrams
    };
}

async function updateShippingEstimate() {
    console.log('updateShippingEstimate called');
    const country = getShippingCountry();
    const province = getProvinceForQuote();
    const postalCode = document.getElementById('postal-code')?.value;
    const shippingDiv = document.getElementById('shipping-estimate');
    const placeholder = document.getElementById('shipping-estimate-placeholder');

    const methodInput = document.querySelector('input[name="fulfillment_method"]:checked');
    const method = methodInput ? methodInput.value : 'shipping';

    if (method === 'pickup') {
        if (shippingDiv) {
            shippingDiv.innerHTML = `
                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                    <strong>Local Pickup - FREE</strong>
                    <p class="text-sm text-green-800">You'll receive pickup location and time in your confirmation email.</p>
                </div>`;
            shippingDiv.classList.remove('hidden');
            placeholder?.classList.add('hidden');
        }
        window.selectedShippingOption = { id: 'pickup', carrier: 'Local Pickup', total: 0 };
        window.shippingOptions = null;
        return;
    }

    if ((country === 'CA' || country === 'US') && !province) {
        clearShippingSelection();
        placeholder?.classList.remove('hidden');
        shippingDiv?.classList.add('hidden');
        return;
    }

    if (!postalCode || !postalReadyForQuote(country, postalCode)) {
        clearShippingSelection();
        placeholder?.classList.remove('hidden');
        shippingDiv?.classList.add('hidden');
        return;
    }

    console.log('province:', province, 'postalCode:', postalCode); 
    
    // Get cart data - prioritize order details over cart manager
    let items = [];
    let subtotal = 0;
    
    if (window.currentOrderDetails && window.currentOrderDetails.items && window.currentOrderDetails.items.length > 0) {
        items = window.currentOrderDetails.items;
        subtotal = parseFloat(window.currentOrderDetails.subtotal) || 0;
    } else if (window.cartManager) {
        items = window.cartManager.items;
        subtotal = window.cartManager.getTotal();
    }
    
    console.log('subtotal:', subtotal, 'items length:', items.length);

    if (items.length === 0) {
        console.warn('No items found for shipping quote');
        placeholder?.classList.remove('hidden');
        shippingDiv?.classList.add('hidden');
        return;
    }
    
    // Show loading state
    if (shippingDiv) {
        shippingDiv.innerHTML = '<div class="text-center py-4"><div class="animate-spin h-5 w-5 border-2 border-sage-600 border-t-transparent rounded-full mx-auto mb-2"></div><p class="text-sm text-stone-600">Fetching real-time shipping rates...</p></div>';
        shippingDiv.classList.remove('hidden');
        placeholder?.classList.add('hidden');
    }
    
    try {
        console.log('fetching response from shipping quote');

        const response = await fetch('/api/get-shipping-quote.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                items: items,
                postal_code: postalCode.replace(/\s/g, '').toUpperCase(),
                province: province,
                country_code: country,
                subtotal: subtotal,
                fulfillment_method: 'shipping',
                name: document.getElementById('customer-name')?.value || '',
                street_address: document.getElementById('street-address')?.value || '',
                address2: document.getElementById('address-2')?.value || '',
                city: document.getElementById('city')?.value || ''
            })
        });

        if (!response.ok) {
            const errorText = await response.text().catch(() => 'No response body');
            console.error(`API Error ${response.status}:`, errorText.substring(0, 500));
            let errMsg = `Shipping API returned ${response.status}`;
            try {
                const errJson = JSON.parse(errorText);
                if (errJson.message) errMsg = errJson.message;
                if (errJson.code === 'shipping_weight_exceeded' && Array.isArray(errJson.lines)) {
                    const detail = errJson.lines.map(l => `${l.quantity}× ${l.product} (${l.weight_g}g)`).join(', ');
                    errMsg += detail ? ` — ${detail}` : '';
                }
            } catch (_) {}
            throw new Error(errMsg);
        }
        
        const result = await response.json();

        if (!result.success || !result.options || result.options.length === 0) {
            let errMsg = result.message || 'No shipping options available';
            if (result.code === 'shipping_weight_exceeded' && Array.isArray(result.lines)) {
                const detail = result.lines.map(l => `${l.quantity}× ${l.product} (${l.weight_g}g)`).join(', ');
                errMsg = `${result.message}${detail ? ' — ' + detail : ''}`;
            }
            throw new Error(errMsg);
        }

        const liveOptions = result.options.filter(isLiveShippingOption);
        if (liveOptions.length === 0) {
            throw new Error('No live shipping rates are available for this address.');
        }

        const handling = calculateHandlingCost(items);
        liveOptions.forEach(opt => {
            opt.handling = handling;
        });

        window.handlingCost = handling;
        
        if (result.success && liveOptions.length > 0) {
            console.log('success')
            window.shippingOptions = liveOptions;
            
            const cheapest = liveOptions.reduce((min, opt) => 
                opt.total < min.total ? opt : min
            );
            window.selectedShippingOption = cheapest;
            
            // Calculate total with tax
            const taxRate = getTaxRate(province);
            const tax = 0 //subtotal * taxRate;
            const total = subtotal + cheapest.total + handling.cost + tax;
            
            if (shippingDiv) {
                console.log('building cart options')
                // Build option cards
                const optionsHTML = liveOptions.map(opt => `
                    <label class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer hover:border-sage-500 transition-colors ${opt.id === cheapest.id ? 'border-sage-500 bg-sage-50' : 'border-stone-200'}" data-option-id="${opt.id}">
                        <input type="radio" name="shipping_option" value="${opt.id}" class="mt-1" ${opt.id === cheapest.id ? 'checked' : ''} onchange="selectShippingOption('${opt.id}')">
                        <div class="flex-1">
                            <div class="flex justify-between items-start">
                                <span class="font-semibold text-stone-800">${opt.carrier}</span>
                                <span class="font-bold text-sage-700">$${opt.total.toFixed(2)}</span>
                            </div>
                            <p class="text-sm text-stone-600 mt-1">${opt.delivery_time}</p>
                            <p class="text-xs text-stone-500 mt-1">${opt.tracking}</p>
                            ${opt.breakdown ? `
                                <details class="mt-2 text-xs text-stone-600">
                                    <summary class="cursor-pointer">View breakdown</summary>
                                    <div class="mt-1 space-y-1 pl-2">
                                        <div class="flex justify-between">
                                            <span>Postage:</span>
                                            <span>$${opt.breakdown.postage.toFixed(2)}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span>Insurance:</span>
                                            <span>$${opt.breakdown.insurance.toFixed(2)}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span>Tax:</span>
                                            <span>$${opt.breakdown.tax.toFixed(2)}</span>
                                        </div>
                                    </div>
                                </details>
                            ` : ''}
                        </div>
                    </label>
                `).join('');
                
                shippingDiv.innerHTML = `
                    <div class="space-y-3">
                        <h4 class="font-semibold text-stone-800">Select Shipping Method</h4>
                        ${optionsHTML}
                        <div class="border-t border-stone-200 pt-3 mt-3">
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-stone-600">Subtotal:</span>
                                <span class="font-medium">$${subtotal.toFixed(2)}</span>
                            </div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-stone-600">Postage:</span>
                                <span class="font-medium" id="selected-postage-cost">$${cheapest.total.toFixed(2)}</span>
                            </div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-stone-600">Handling (${handling.type}):</span>
                                <span class="font-medium" id="selected-handling-cost">$${handling.cost.toFixed(2)}</span>
                            </div>
                            <div class="flex justify-between text-sm mb-2">
                                <span class="text-stone-600">Tax (${(taxRate*100).toFixed(0)}%):</span>
                                <span class="font-medium" id="selected-tax">$${tax.toFixed(2)}</span>
                            </div>
                            <div class="flex justify-between font-bold text-lg pt-2 border-t">
                                <span>Total:</span>
                                <span id="selected-total">$${total.toFixed(2)}</span>
                            </div>
                        </div>
                        <p class="text-xs text-stone-500 italic mt-2">
                            Rates calculated in real-time via Chit Chats
                        </p>
                    </div>
                `;
                
                // Add click handlers to option cards (for better UX)
                shippingDiv.querySelectorAll('[data-option-id]').forEach(card => {
                    card.addEventListener('click', (e) => {
                        if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'SUMMARY') {
                            const radio = card.querySelector('input[type="radio"]');
                            radio.checked = true;
                            selectShippingOption(radio.value);
                        }
                    });
                });
                
                shippingDiv.classList.remove('hidden');
                placeholder?.classList.add('hidden');

                // Update payment intent with new shipping cost
                recreatePaymentIntent();
            }
        } else {
            throw new Error(result.message || 'No shipping options available');
        }
    } catch (error) {
        console.error('Shipping calculation error:', error);
        showShippingQuoteError(error.message || 'Please check your address and try again.');
    }
}

// Handle shipping option selection
function selectShippingOption(optionId) {
    const options = window.shippingOptions || [];
    const selected = options.find(opt => opt.id === optionId);
    
    if (!selected || !isLiveShippingOption(selected)) return;
    
    // Update global selection
    window.selectedShippingOption = selected;
    
    // Update UI totals  
    const province = document.getElementById('province')?.value;
    const subtotal = window.cartManager ? window.cartManager.getTotal() : 0;
    const handling = selected.handling || calculateHandlingCost(window.cartManager ? window.cartManager.items : []);
    window.handlingCost = handling;
    const taxRate = getTaxRate(province);
    const taxableAmount = subtotal + selected.total + handling.cost;
    let tax = 0; // taxableAmount * taxRate; // UNCOMMENT THIS WHEN MAKING > 30K
    const total = subtotal + selected.total + handling.cost + tax;
    
    // Update DOM elements
    const shippingCostEl = document.getElementById('selected-shipping-cost');
    const taxEl = document.getElementById('selected-tax');
    const postageEl = document.getElementById('selected-postage-cost');
    const handlingEl = document.getElementById('selected-handling-cost');
    const totalEl = document.getElementById('selected-total');
    
    if (postageEl) postageEl.textContent = `$${selected.total.toFixed(2)}`;
    if (handlingEl) {
        handlingEl.textContent = `$${handling.cost.toFixed(2)}`;
        // Also update the label text if needed
        const handlingLabel = handlingEl.closest('.flex')?.querySelector('span:first-child');
        if (handlingLabel) {
            handlingLabel.textContent = `Handling (${handling.type}):`;
        }
    }
    if (taxEl) taxEl.textContent = `$${tax.toFixed(2)}`;
    if (totalEl) totalEl.textContent = `$${total.toFixed(2)}`;
    
    // Update visual selection state
    document.querySelectorAll('[data-option-id]').forEach(card => {
        const isSelected = card.dataset.optionId === optionId;
        card.classList.toggle('border-sage-500', isSelected);
        card.classList.toggle('bg-sage-50', isSelected);
        card.classList.toggle('border-stone-200', !isSelected);
    });

    // Recreate payment intent with updated shipping cost
    recreatePaymentIntent();
    updateOrderSummary();
}

function getTaxRate(province) {
    const rates = {
        'ON': 0.13, 'QC': 0.14975, 'BC': 0.12, 'AB': 0.05,
        'SK': 0.11, 'MB': 0.12, 'NS': 0.15, 'NB': 0.15,
        'NL': 0.15, 'PE': 0.15, 'NT': 0.05, 'NU': 0.05, 'YT': 0.05
    };
    return rates[province] || 0.13;
}


// Initialize cart manager
let cartManager;
// Initialize Cross-Sell
const crossSellManager = new CrossSellManager();

document.getElementById('province')?.addEventListener('change', updateShippingEstimate);

function initCart() {
    cartManager = new CartManager();
    window.cartManager = cartManager;

    // Modify existing add-to-cart functionality to trigger cross-sell
    const originalAddToCart = window.cartManager ? window.cartManager.addItem.bind(window.cartManager) : null;
    if (originalAddToCart) {
        window.cartManager.addItem = function(product, price, quantity, metadata) {
            metadata = metadata || {};
            originalAddToCart(product, price, quantity, metadata);
            if (metadata.skipCrossSell) return;
            setTimeout(() => {
                crossSellManager.showCrossSell(product);
            }, 600);
        };
    }
    
    // Handle BOTH regular products AND bundles
    document.querySelectorAll('.add-to-cart, .add-to-cart-bundle').forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            
            // Regular product
            if (button.classList.contains('add-to-cart')) {
                if (button.disabled) return;
                const card = button.closest('.product-card');
                if (card?.dataset.comingSoon === 'true') return;
                const product = e.currentTarget.dataset.product;
                const price = e.currentTarget.dataset.price;
                if (!product || !price) return;
                cartManager.addItem(product, price);
                showButtonFeedback(button, 'Added!');
            }
            // Bundle
            else if (button.classList.contains('add-to-cart-bundle')) {
                const bundle = button.dataset.bundle;
                const price = button.dataset.price;
                const itemsJson = button.dataset.items || '[]';
                
                try {
                    const items = JSON.parse(itemsJson);
                    
                    if (items.length > 0) {
                        // Add each item individually
                        items.forEach(item => {
                            if (item.product && typeof item.price === 'number') {
                                cartManager.addItem(item.product, item.price, item.qty || 1);
                            }
                        });
                    } else {
                        // Add bundle as single item
                        cartManager.addItem(bundle, price);
                    }
                    
                    showButtonFeedback(button, 'Added to Cart!');
                } catch (err) {
                    console.error('Error parsing bundle items:', err);
                }
            }
        });
    });
}

// Reusable button feedback function
function showButtonFeedback(button, successText) {
    const originalText = button.textContent;
    const originalClasses = button.className;
    
    button.textContent = successText;
    button.classList.add('bg-green-600');
    
    setTimeout(() => {
        button.textContent = originalText;
        button.className = originalClasses;
    }, 2000);
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    initCart();
    initAuth();
    if (new URLSearchParams(window.location.search).get('openCart') === '1') {
        setTimeout(() => window.cartManager?.toggleCartModal(), 400);
    }
});

// Account Creation in Checkout
function initAccountCreation() {
    const createAccountCheckbox = document.getElementById('create-account-checkbox');
    const accountPasswordFields = document.getElementById('account-password-fields');

    if (createAccountCheckbox && accountPasswordFields) {
        createAccountCheckbox.addEventListener('change', function() {
            if (this.checked) {
                accountPasswordFields.classList.remove('hidden');
            } else {
                accountPasswordFields.classList.add('hidden');
            }
        });

        // Hide account creation section if user is already logged in
        if (window.currentUser) {
            document.getElementById('create-account-section').classList.add('hidden');
        }
    }
}

// Authentication Helpers
function saveUserToStorage(userData) {
    try {
        localStorage.setItem(window.ONLYBIKES_CONFIG?.authStorageKey || 'onlybikes_auth_user', JSON.stringify(userData));
    } catch (e) { /* storage full or disabled */ }
}
function loadUserFromStorage() {
    try {
        const raw = localStorage.getItem(window.ONLYBIKES_CONFIG?.authStorageKey || 'onlybikes_auth_user') || localStorage.getItem('bedda_auth_user');
        return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
}
function clearUserStorage() {
    try { localStorage.removeItem(window.ONLYBIKES_CONFIG?.authStorageKey || 'onlybikes_auth_user'); localStorage.removeItem('bedda_auth_user'); } catch (e) {}
}

function ensureAuthModal() {
    if (document.getElementById('auth-modal')) return;
    const wrap = document.createElement('div');
    wrap.innerHTML = `<div id="auth-modal" class="fixed inset-0 bg-black/70 z-[100] hidden flex items-center justify-center p-4">
    <div class="rounded-2xl max-w-md w-full p-6 relative border border-zinc-800">
        <button id="close-auth-modal" type="button" class="absolute top-4 right-4 text-zinc-400 hover:text-green-300" aria-label="Close">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        <div id="auth-login-view">
            <h2 class="text-2xl font-display uppercase mb-2">Welcome Back</h2>
            <p class="text-zinc-400 mb-6">Sign in to access your OnlyBikes Rewards points</p>
            <form id="login-form" class="space-y-4">
                <div><label class="block text-sm font-medium text-zinc-300 mb-1">Email</label><input type="email" id="login-email" required class="w-full px-4 py-2 border border-zinc-700 rounded-lg bg-zinc-900"></div>
                <div><label class="block text-sm font-medium text-zinc-300 mb-1">Password</label><input type="password" id="login-password" required class="w-full px-4 py-2 border border-zinc-700 rounded-lg bg-zinc-900"></div>
                <button type="submit" class="w-full ob-btn ob-btn-primary">Sign In</button>
            </form>
            <p class="text-center mt-4 text-sm text-zinc-400">Don't have an account? <button id="show-register" type="button" class="text-green-400 font-semibold hover:underline">Create one</button></p>
        </div>
        <div id="auth-register-view" class="hidden">
            <h2 class="text-2xl font-display uppercase mb-2">Create Account</h2>
            <p class="text-zinc-400 mb-6">Join OnlyBikes Rewards — earn 1 point per $1 spent.</p>
            <form id="register-form" class="space-y-4">
                <div><label class="block text-sm font-medium text-zinc-300 mb-1">First Name</label><input type="text" id="register-first-name" required class="w-full px-4 py-2 border border-zinc-700 rounded-lg bg-zinc-900"></div>
                <div><label class="block text-sm font-medium text-zinc-300 mb-1">Last Name</label><input type="text" id="register-last-name" required class="w-full px-4 py-2 border border-zinc-700 rounded-lg bg-zinc-900"></div>
                <div><label class="block text-sm font-medium text-zinc-300 mb-1">Email</label><input type="email" id="register-email" required class="w-full px-4 py-2 border border-zinc-700 rounded-lg bg-zinc-900"></div>
                <div><label class="block text-sm font-medium text-zinc-300 mb-1">Password (min 8 characters)</label><input type="password" id="register-password" required minlength="8" class="w-full px-4 py-2 border border-zinc-700 rounded-lg bg-zinc-900"></div>
                <button type="submit" class="w-full ob-btn ob-btn-primary">Create Account</button>
            </form>
            <p class="text-center mt-4 text-sm text-zinc-400">Already have an account? <button id="show-login" type="button" class="text-green-400 font-semibold hover:underline">Sign in</button></p>
        </div>
        <div id="auth-loading" class="hidden py-6"><div class="skeleton skeleton-title mb-4"></div><div class="skeleton skeleton-input"></div><div class="skeleton skeleton-input"></div><div class="skeleton skeleton-button"></div></div>
        <div id="auth-error" class="hidden mt-4 p-3 bg-red-950/40 border border-red-900/50 rounded-lg text-red-200 text-sm"></div>
    </div></div>`;
    document.body.appendChild(wrap.firstElementChild);
}

// Authentication Module
function initAuth() {
    ensureAuthModal();
    const loginBtn = document.getElementById('login-btn');
    const authModal = document.getElementById('auth-modal');
    const closeAuthModal = document.getElementById('close-auth-modal');
    const showRegisterBtn = document.getElementById('show-register');
    const showLoginBtn = document.getElementById('show-login');
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const loginView = document.getElementById('auth-login-view');
    const registerView = document.getElementById('auth-register-view');
    const loadingView = document.getElementById('auth-loading');
    const errorView = document.getElementById('auth-error');

    if (!loginBtn || !authModal) return;

    // Open modal or toggle account dropdown
    loginBtn.addEventListener('click', (e) => {
        if (window.currentUser) {
            e.stopPropagation();
            const dropdown = document.getElementById('account-dropdown');
            if (dropdown) dropdown.classList.toggle('hidden');
            return;
        }
        authModal.classList.remove('hidden');
        showLoginView();
    });

    // Close modal
    closeAuthModal.addEventListener('click', () => {
        authModal.classList.add('hidden');
        hideError();
    });

    // Close on backdrop click
    authModal.addEventListener('click', (e) => {
        if (e.target === authModal) {
            authModal.classList.add('hidden');
            hideError();
        }
    });

    // Switch to register view
    showRegisterBtn.addEventListener('click', () => {
        showRegisterView();
    });

    // Switch to login view
    showLoginBtn.addEventListener('click', () => {
        showLoginView();
    });

    // Handle login form submission
    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = document.getElementById('login-email').value;
        const password = document.getElementById('login-password').value;

        showLoading();
        hideError();

        try {
            const response = await fetch(onlyBikesCustomerAuthUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'login', email, password })
            });

            // Handle 503 Service Unavailable (database not configured)
            if (response.status === 503) {
                showError('Customer accounts are temporarily unavailable. Please check back later.');
                return;
            }

            const data = await response.json().catch(() => ({ success: false }));

            if (!response.ok || !data.success) {
                showError(data.message || 'Login failed. Please try again.');
                return;
            }

            window.currentUser = data.data;
            saveUserToStorage(data.data);
            authModal.classList.add('hidden');
            updateLoginButtonState(true);
            updateRewardsDisplay();
        } catch (err) {
            console.error('Login error:', err);
            showError('An error occurred. Please try again.');
        } finally {
            hideLoading();
        }
    });

    // Inject forgot password link
    const forgotLink = document.createElement('div');
    forgotLink.className = 'text-right mt-2 mb-2';
    forgotLink.innerHTML = `<button type="button" id="show-forgot" class="text-sm text-green-400 hover:text-green-300 underline">Forgot password?</button>`;
    loginForm.appendChild(forgotLink);

    // Handle register form submission
    registerForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const firstName = document.getElementById('register-first-name').value;
        const lastName = document.getElementById('register-last-name').value;
        const email = document.getElementById('register-email').value;
        const password = document.getElementById('register-password').value;

        showLoading();
        hideError();

        try {
            const response = await fetch(onlyBikesCustomerAuthUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'register', first_name: firstName, last_name: lastName, email, password })
            });

            // Handle 503 Service Unavailable (database not configured)
            if (response.status === 503) {
                showError('Customer accounts are temporarily unavailable. Please check back later.');
                return;
            }

            const data = await response.json().catch(() => ({ success: false }));

            if (!response.ok || !data.success) {
                showError(data.message || 'Registration failed. Please try again.');
                return;
            }

            window.currentUser = data.data;
            saveUserToStorage(data.data);
            showSuccess(`Welcome, ${data.data.first_name || 'there'}! Your account is ready.`);
            updateLoginButtonState(true);
            updateRewardsDisplay();
            // Auto-close modal after animation
            setTimeout(() => {
                authModal.classList.add('hidden');
                hideSuccess();
            }, 2200);
        } catch (err) {
            console.error('Registration error:', err);
            showError('An error occurred. Please try again.');
        } finally {
            hideLoading();
        }
    });

    const authPanel = authModal.querySelector(':scope > div');
    if (authPanel && !document.getElementById('auth-forgot-view')) {
    authPanel.insertAdjacentHTML('beforeend', `
        <div id="auth-forgot-view" class="hidden">
            <h2 class="text-2xl font-display uppercase mb-2">Reset Password</h2>
            <p class="text-zinc-400 mb-6">Enter your email and we'll send you a reset code.</p>
            <form id="forgot-form">
                <input type="email" id="forgot-email" required placeholder="your@email.com" class="w-full border border-zinc-700 rounded-lg px-4 py-3 mb-4 bg-zinc-900">
                <button type="submit" class="w-full ob-btn ob-btn-primary">Send Code</button>
            </form>
            <p class="text-center mt-4 text-zinc-400 text-sm">
                <button type="button" id="back-to-login" class="text-green-400 hover:text-green-300 underline">Back to login</button>
            </p>
        </div>
        <div id="auth-reset-view" class="hidden">
            <h2 class="text-2xl font-display uppercase mb-2">Enter Code</h2>
            <p class="text-zinc-400 mb-6">Check your email for a 6-digit code.</p>
            <form id="reset-form">
                <input type="text" id="reset-code" required maxlength="6" placeholder="6-digit code" inputmode="numeric" pattern="[0-9]*" class="w-full border border-zinc-700 rounded-lg px-4 py-3 mb-4 text-center tracking-widest font-mono text-lg bg-zinc-900">
                <input type="password" id="reset-password" required minlength="8" placeholder="New password (min 8 chars)" class="w-full border border-zinc-700 rounded-lg px-4 py-3 mb-4 bg-zinc-900">
                <button type="submit" class="w-full ob-btn ob-btn-primary">Update Password</button>
            </form>
            <p class="text-center mt-4 text-zinc-400 text-sm">
                <button type="button" id="back-to-login2" class="text-green-400 hover:text-green-300 underline">Back to login</button>
            </p>
        </div>
    `);
    }

    document.getElementById('show-forgot').addEventListener('click', () => showForgotView());
    document.getElementById('back-to-login').addEventListener('click', () => showLoginView());
    document.getElementById('back-to-login2').addEventListener('click', () => showLoginView());

    // Handle forgot form
    document.getElementById('forgot-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = document.getElementById('forgot-email').value;
        showLoading();
        hideError();
        try {
            const response = await fetch(onlyBikesCustomerAuthUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'forgot', email })
            });
            if (response.status === 503) {
                showError('Customer accounts are temporarily unavailable. Please check back later.');
                return;
            }
            const data = await response.json().catch(() => ({ success: false }));
            if (!response.ok || !data.success) {
                showError(data.message || 'Failed to send reset code. Please try again.');
                return;
            }
            window.resetEmail = email;
            showResetView();
        } catch (err) {
            console.error('Forgot error:', err);
            showError('An error occurred. Please try again.');
        } finally {
            hideLoading();
        }
    });

    // Handle reset form
    document.getElementById('reset-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const code = document.getElementById('reset-code').value.replace(/\D/g, '');
        const password = document.getElementById('reset-password').value;
        const email = window.resetEmail || '';
        if (code.length !== 6) {
            showError('Please enter the 6-digit code.');
            return;
        }
        if (password.length < 8) {
            showError('Password must be at least 8 characters.');
            return;
        }
        showLoading();
        hideError();
        try {
            const response = await fetch(onlyBikesCustomerAuthUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'reset-password', email, code, new_password: password })
            });
            if (response.status === 503) {
                showError('Customer accounts are temporarily unavailable. Please check back later.');
                return;
            }
            const data = await response.json().catch(() => ({ success: false }));
            if (!response.ok || !data.success) {
                showError(data.message || 'Failed to reset password. Please try again.');
                return;
            }
            showSuccess('Password updated! You can now log in.');
            setTimeout(() => {
                hideSuccess();
                showLoginView();
            }, 2500);
        } catch (err) {
            console.error('Reset error:', err);
            showError('An error occurred. Please try again.');
        } finally {
            hideLoading();
        }
    });

    // Check if user is already logged in on page load
    checkAuthStatus();

    function showLoginView() {
        loginView.classList.remove('hidden');
        registerView.classList.add('hidden');
        const forgotView = document.getElementById('auth-forgot-view');
        if (forgotView) forgotView.classList.add('hidden');
        const resetView = document.getElementById('auth-reset-view');
        if (resetView) resetView.classList.add('hidden');
        hideError();
    }

    function showRegisterView() {
        loginView.classList.add('hidden');
        registerView.classList.remove('hidden');
        const forgotView = document.getElementById('auth-forgot-view');
        if (forgotView) forgotView.classList.add('hidden');
        const resetView = document.getElementById('auth-reset-view');
        if (resetView) resetView.classList.add('hidden');
        hideError();
    }

    function showLoading() {
        loginView.classList.add('hidden');
        registerView.classList.add('hidden');
        loadingView.classList.remove('hidden');
        const forgotView = document.getElementById('auth-forgot-view');
        if (forgotView) forgotView.classList.add('hidden');
        const resetView = document.getElementById('auth-reset-view');
        if (resetView) resetView.classList.add('hidden');
    }

    function hideLoading() {
        loadingView.classList.add('hidden');
    }

    function showSuccess(message) {
        let successView = document.getElementById('auth-success');
        if (!successView) {
            successView = document.createElement('div');
            successView.id = 'auth-success';
            successView.className = 'hidden text-center py-10';
            successView.innerHTML = `
                <div class="success-checkmark mb-4">
                    <div class="check-icon">
                        <span class="icon-line line-tip"></span>
                        <span class="icon-line line-long"></span>
                        <div class="icon-circle"></div>
                        <div class="icon-fix"></div>
                    </div>
                </div>
                <style>
                    .success-checkmark { width: 80px; height: 80px; border-radius: 50%; display: block; stroke-width: 2; stroke: #fff; stroke-miterlimit: 10; margin: 0 auto; box-shadow: inset 0 0 0 #7E6D58; animation: fill .4s ease-in-out .4s forwards, scale .3s ease-in-out .9s both; }
                    .success-checkmark .check-icon { width: 80px; height: 80px; border-radius: 50%; display: block; stroke-width: 2; stroke: #fff; stroke-miterlimit: 10; margin: 0 auto; box-shadow: inset 0 0 0 #7E6D58; animation: fill .4s ease-in-out .4s forwards, scale .3s ease-in-out .9s both; }
                    .success-checkmark .check-icon .icon-circle { stroke-dasharray: 166; stroke-dashoffset: 166; stroke-width: 2; stroke-miterlimit: 10; stroke: #7E6D58; fill: none; animation: stroke .6s cubic-bezier(0.65, 0, 0.45, 1) forwards; }
                    .success-checkmark .check-icon .icon-line { fill: none; stroke: #fff; stroke-width: 5; stroke-linecap: round; stroke-linejoin: round; }
                    .success-checkmark .check-icon .line-tip { width: 25px; top: 46px; left: 14px; transform: rotate(45deg); animation: icon-line-tip .75s; position: absolute; border-radius: 2px; height: 5px; background: #fff; }
                    .success-checkmark .check-icon .line-long { width: 47px; top: 38px; right: 8px; transform: rotate(-45deg); animation: icon-line-long .75s; position: absolute; border-radius: 2px; height: 5px; background: #fff; }
                    @keyframes stroke { 100% { stroke-dashoffset: 0; } }
                    @keyframes scale { 0%, 100% { transform: none; } 50% { transform: scale3d(1.1, 1.1, 1); } }
                    @keyframes fill { 100% { box-shadow: inset 0 0 0 100px #7E6D58; } }
                    @keyframes icon-line-tip { 0% { width: 0; left: 14px; top: 46px; } 54% { width: 0; left: 14px; top: 46px; } 70% { width: 25px; left: 14px; top: 46px; } 84% { width: 17px; left: 18px; top: 44px; } 100% { width: 25px; left: 14px; top: 46px; } }
                    @keyframes icon-line-long { 0% { width: 0; right: 22px; top: 38px; } 65% { width: 0; right: 22px; top: 38px; } 84% { width: 47px; right: 8px; top: 38px; } 100% { width: 47px; right: 8px; top: 38px; } }
                </style>
                <h3 class="text-xl font-bold text-white mb-1" id="auth-success-msg"></h3>
                <p class="text-zinc-400 text-sm">Redirecting you now...</p>
            `;
            const authPanel = authModal.querySelector(':scope > div');
            if (authPanel) authPanel.appendChild(successView);
        }
        document.getElementById('auth-success-msg').textContent = message;
        loginView.classList.add('hidden');
        registerView.classList.add('hidden');
        loadingView.classList.add('hidden');
        const forgotView = document.getElementById('auth-forgot-view');
        if (forgotView) forgotView.classList.add('hidden');
        const resetView = document.getElementById('auth-reset-view');
        if (resetView) resetView.classList.add('hidden');
        successView.classList.remove('hidden');
    }

    function hideSuccess() {
        const successView = document.getElementById('auth-success');
        if (successView) successView.classList.add('hidden');
    }

    function showError(message) {
        errorView.textContent = message;
        errorView.classList.remove('hidden');
    }

    function hideError() {
        errorView.classList.add('hidden');
    }

    function showForgotView() {
        loginView.classList.add('hidden');
        registerView.classList.add('hidden');
        loadingView.classList.add('hidden');
        hideSuccess();
        const forgotViewEl = document.getElementById('auth-forgot-view');
        if (forgotViewEl) forgotViewEl.classList.remove('hidden');
        const resetViewEl = document.getElementById('auth-reset-view');
        if (resetViewEl) resetViewEl.classList.add('hidden');
        hideError();
    }

    function showResetView() {
        loginView.classList.add('hidden');
        registerView.classList.add('hidden');
        loadingView.classList.add('hidden');
        hideSuccess();
        const forgotViewEl = document.getElementById('auth-forgot-view');
        if (forgotViewEl) forgotViewEl.classList.add('hidden');
        const resetViewEl = document.getElementById('auth-reset-view');
        if (resetViewEl) resetViewEl.classList.remove('hidden');
        hideError();
    }
}

async function checkAuthStatus() {
    // Restore from localStorage immediately for fast UI
    const cached = loadUserFromStorage();
    if (cached) {
        window.currentUser = cached;
        updateLoginButtonState(true);
        updateRewardsDisplay();
    }

    try {
        const response = await fetch(onlyBikesCustomerAuthUrl('action=me'), { credentials: 'include' });

        // Handle 503 Service Unavailable (database not configured)
        if (response.status === 503) {
            console.log('Customer auth not available yet');
            if (!cached) updateLoginButtonState(false);
            return;
        }

        if (!response.ok) {
            if (response.status === 401) {
                // Expected behavior for guests
                console.log('Guest session: Please log in to unlock OnlyBikes Rewards and other benefits!');
                if (!cached) updateLoginButtonState(false);
                return;
            }
            if (response.status === 500) {
                console.warn('Auth API unavailable (server error). Using cached session if available.');
                if (!cached) updateLoginButtonState(false);
                return;
            }
            const errText = await response.text();
            const preview = errText.startsWith('<!DOCTYPE') ? 'HTML error page' : errText.slice(0, 200);
            console.error('Server error response:', preview);
            if (!cached) updateLoginButtonState(false);
            return;
        }

        const data = await response.json();

        if (data.success && data.data) {
            window.currentUser = data.data;
            saveUserToStorage(data.data);
            updateLoginButtonState(true);
            updateRewardsDisplay();
        } else {
            window.currentUser = null;
            clearUserStorage();
            updateLoginButtonState(false);
        }
    } catch (err) {
        console.warn('Auth check unavailable:', err.message || err);
        if (!cached) updateLoginButtonState(false);
    }
}

function ensureAccountDropdown() {
    let wrapper = document.getElementById('login-btn-wrapper');
    if (wrapper) return;
    const btn = document.getElementById('login-btn');
    if (!btn) return;
    const parent = btn.parentNode;
    wrapper = document.createElement('div');
    wrapper.id = 'login-btn-wrapper';
    wrapper.className = 'relative inline-block';
    parent.insertBefore(wrapper, btn);
    wrapper.appendChild(btn);

    const dropdown = document.createElement('div');
    dropdown.id = 'account-dropdown';
    dropdown.className = 'hidden absolute right-0 mt-2 w-52 bg-white rounded-xl shadow-xl border border-stone-200 z-[70] py-2 overflow-hidden';
    dropdown.innerHTML = `
        <a href="account.html" class="flex items-center px-4 py-2.5 text-sm text-stone-700 hover:bg-sage-50 hover:text-sage-700 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            My Account
        </a>
        <a href="account.html#rewards" class="flex items-center px-4 py-2.5 text-sm text-stone-700 hover:bg-sage-50 hover:text-sage-700 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Rewards & Points
        </a>
        <div class="border-t border-stone-100 my-1"></div>
        <button type="button" id="account-logout-btn" class="w-full text-left flex items-center px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Log Out
        </button>
    `;
    wrapper.appendChild(dropdown);

    dropdown.querySelector('#account-logout-btn').addEventListener('click', async (e) => {
        e.stopPropagation();
        dropdown.classList.add('hidden');
        await logoutUser();
    });
}

function updateLoginButtonState(isLoggedIn) {
    const loginBtn = document.getElementById('login-btn');
    if (!loginBtn) return;

    if (isLoggedIn && window.currentUser) {
        const first = window.currentUser.first_name || '';
        const last  = window.currentUser.last_name  || '';
        const initials = (first[0] || '') + (last[0] || '');
        loginBtn.innerHTML = initials
            ? `<span class="flex items-center justify-center w-6 h-6 bg-sage-600 text-white rounded-full text-xs font-bold">${initials.toUpperCase()}</span>`
            : `<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>`;
        const fullName = first && last ? `${first} ${last}` : (first || 'OnlyBikes Member');
        loginBtn.title = `Logged in as ${fullName} (${window.currentUser.points || 0} points)`;
        ensureAccountDropdown();
    } else {
        // Reset to person icon
        loginBtn.innerHTML = `<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>`;
        loginBtn.title = 'Sign in to access OnlyBikes Rewards';
        const dropdown = document.getElementById('account-dropdown');
        if (dropdown) dropdown.classList.add('hidden');
    }
}

function updateRewardsDisplay() {
    // This will be called to update the cart rewards display based on login state
    // For now, we'll trigger a re-render of the gift progress
    if (window.cartManager) {
        window.cartManager.renderGiftProgress();
    }
}

async function logoutUser() {
    try {
        await fetch(onlyBikesCustomerAuthUrl('action=logout'), { credentials: 'include' });
    } catch (e) {}
    window.currentUser = null;
    clearUserStorage();
    updateLoginButtonState(false);
    updateRewardsDisplay();
    // If on account page, redirect home
    if (location.pathname.includes('account.html')) {
        location.href = 'index.html';
    }
}

// Close account dropdown when clicking outside
document.addEventListener('click', (e) => {
    const dropdown = document.getElementById('account-dropdown');
    const wrapper = document.getElementById('login-btn-wrapper');
    if (dropdown && !dropdown.classList.contains('hidden') && wrapper && !wrapper.contains(e.target)) {
        dropdown.classList.add('hidden');
    }
});
