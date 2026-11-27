// Bedda Website - Main JavaScript
// Mobile menu and shared functionality

// Inject skeleton loading styles globally so all pages have them
(function injectSkeletonStyles() {
    if (document.getElementById('bedda-skeleton-styles')) return;
    const style = document.createElement('style');
    style.id = 'bedda-skeleton-styles';
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

/** Production URL on live site; current origin when running locally (npm run dev). */
function beddaSiteOrigin() {
    const h = window.location.hostname;
    if (h === 'localhost' || h === '127.0.0.1') return window.location.origin;
    return 'https://bedda.ca';
}

function beddaLogProfitBlock(fields) {
    fetch('/api/log-checkout-failure.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(fields || {}),
        keepalive: true
    }).catch(() => {});
}

document.addEventListener('DOMContentLoaded', function() {
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

    // Shop by Need mobile submenu toggle — handled here for all pages
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
    'Be Mine Soap': 120,
    'Uni Exfoliating Soap': 120,
    'He-Man Exfoliating Soap': 120,
    'She-Ra Exfoliating Soap': 120,
    'The Massager Soap': 130,
    'Holy Grail Balm': 135,
    'Plain Jane Balm': 135,
    'Plain Jane Face & Body Balm': 135,
    'Creamsicle Balm': 135,
    'Minty Lip Balm': 70,
    'Pinky Minty Lip Balm': 70,
    'Plain Jane Soap': 100,
    'Plain Jane Face Balm': 135,
    'Plain Jane Soap Loaf': 1350,
    'Holiday Ginger Soap': 120,
    'Bedda Holiday Sanctuary Basket': 1100,
    'Holiday Gift Bag': 320,
    'Sampler Pack - Uni': 50,
    'Sampler Pack - He-Man': 50,
    'Sampler Pack - She-Ra': 50,
    'Sampler Pack - Holy Grail': 40,
};

function calculateHandlingCost(items) {
    let soapCount = 0, creamCount = 0, totalItems = 0;
    items.forEach(item => {
        const qty = item.quantity || 1;
        totalItems += qty;
        const name = (item.product || '').toLowerCase();
        if (name.includes('soap') && !name.includes('loaf')) soapCount += qty;
        if (name.includes('balm') || name.includes('cream')) creamCount += qty;
    });
        const isMailer = soapCount <= 5 && creamCount <= 5 && totalItems <= 5;
    const baseCost = isMailer ? 0.50 : 0.90;
    const FLAT_FEE = 3.00; // ← Your fixed handling fee
    return { 
        type: isMailer ? 'Bubble Mailer' : 'Shipping Box', 
        cost: baseCost + FLAT_FEE 
    };
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

// The only reward catalog in this file. 1 point per $1 spent, worth $0.05.
// A reward is a whole item. It is not a discount on the charge.
const BEDDA_REWARDS = Object.freeze([
    Object.freeze({ id: 'minty', name: 'Minty Lip Balm', points: 97 }),
    Object.freeze({ id: 'uni', name: 'Uni Exfoliating Soap', points: 112 }),
    Object.freeze({ id: 'shera', name: 'She-Ra Exfoliating Soap', points: 115 }),
    Object.freeze({ id: 'massager', name: 'The Massager', points: 125 }),
    Object.freeze({ id: 'plainjane', name: 'Plain Jane Balm', points: 200 }),
    Object.freeze({ id: 'holygrail', name: 'Holy Grail Balm', points: 200 }),
    Object.freeze({ id: 'creamsicle', name: 'Creamsicle Balm', points: 200 }),
    Object.freeze({ id: 'sensitive', name: 'Sensitive Skin Set', points: 754 })
]);

function beddaEscapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function beddaPointsWord(count) {
    const n = Math.max(0, Math.floor(Number(count) || 0));
    return n === 1 ? '1 point' : `${n} points`;
}

function beddaPointsEarned(subtotal) {
    const amount = Number(subtotal);
    if (!Number.isFinite(amount) || amount <= 0) return 0;
    return Math.floor(Math.round(amount * 100) / 100);
}

function beddaPointBalance(user) {
    const source = user === undefined ? window.currentUser : user;
    if (!source) return 0;
    const points = Number.parseInt(source.points, 10);
    if (!Number.isFinite(points) || points < 0) return 0;
    return points;
}

function beddaSignedInUser() {
    return window.currentUser && window.currentUser.id ? window.currentUser : null;
}

function affordableBeddaRewards(points) {
    const balance = Math.max(0, Math.floor(Number(points) || 0));
    return BEDDA_REWARDS.filter(reward => balance >= reward.points);
}

function nextBeddaRewardTier(points) {
    const balance = Math.max(0, Math.floor(Number(points) || 0));
    const upcoming = BEDDA_REWARDS.filter(reward => reward.points > balance);
    if (!upcoming.length) return [];
    const cost = upcoming[0].points;
    return upcoming.filter(reward => reward.points === cost);
}

function beddaJoin(parts, conjunction) {
    if (!parts.length) return '';
    if (parts.length === 1) return parts[0];
    if (parts.length === 2) return `${parts[0]} ${conjunction} ${parts[1]}`;
    return `${parts.slice(0, -1).join(', ')}, ${conjunction} ${parts[parts.length - 1]}`;
}

function beddaRewardLabels(rewards) {
    return rewards.map(reward => `${reward.name} (${beddaPointsWord(reward.points)})`);
}

function beddaNextRemainSentence(points) {
    const balance = Math.max(0, Math.floor(Number(points) || 0));
    const tier = nextBeddaRewardTier(balance);
    if (!tier.length) return 'This balance covers every reward on the shelf.';
    const remain = tier[0].points - balance;
    const verb = remain === 1 ? 'remains' : 'remain';
    const names = beddaJoin(tier.map(reward => reward.name), 'or');
    const each = tier.length > 1 ? ' each' : '';
    return `${beddaPointsWord(remain)} ${verb} until ${names} (${beddaPointsWord(tier[0].points)}${each}).`;
}

function beddaCheckoutRewardId(orderDetails) {
    const user = beddaSignedInUser();
    if (!user) return '';
    const raw = String((orderDetails && orderDetails.reward_id) || '').toLowerCase().replace(/[^a-z]/g, '');
    const reward = BEDDA_REWARDS.find(entry => entry.id === raw);
    if (!reward || beddaPointBalance(user) < reward.points) return '';
    return reward.id;
}

function beddaCheckoutCustomerId() {
    const user = beddaSignedInUser();
    return user ? user.id : null;
}

function beddaPaidLines(items) {
    return (items || []).map(item => ({
        product: item.product,
        price: parseFloat(item.price) || 0,
        quantity: parseInt(item.quantity, 10) || 1
    }));
}

function beddaRememberBoughtItems(items, rewardId) {
    const names = [];
    (items || []).forEach(item => {
        if (!item) return;
        if (item.product && !names.includes(item.product)) names.push(item.product);
        const title = item.bundleName || (item.metadata && item.metadata.bundleName);
        if (title && !names.includes(title)) names.push(title);
        if (item.bundleKey && !names.includes(item.bundleKey)) names.push(item.bundleKey);
    });
    const reward = BEDDA_REWARDS.find(entry => entry.id === rewardId);
    if (reward && !names.includes(reward.name)) names.push(reward.name);
    localStorage.setItem('bedda_last_items', JSON.stringify(names));
}

function beddaBundleTitle(button) {
    if (!button) return '';
    const accessible = (button.getAttribute('aria-label') || button.textContent || '').replace(/\s+/g, ' ').trim();
    let title = accessible
        .replace(/^add\s+/i, '')
        .replace(/,?\s*\$\d+(?:\.\d+)?/g, '')
        .replace(/,?\s*pickup only/ig, '')
        .replace(/^[,\s]+|[,\s]+$/g, '')
        .trim();
    const keyword = /\b(bundle|set|sampler|essentials|heart)\b/i;
    if (keyword.test(title)) return title;
    const slug = String(button.dataset && button.dataset.bundle || '').replace(/\s+/g, ' ').trim();
    if (keyword.test(slug)) return slug;
    return title || slug;
}

function beddaOpenSignIn() {
    const authModal = document.getElementById('auth-modal');
    // Cart and checkout sit above the auth dialog. Lift it so Sign in is actually usable.
    if (authModal) authModal.style.zIndex = '10060';
    const loginBtn = document.getElementById('login-btn');
    if (loginBtn) loginBtn.click();
}

function syncCheckoutRewardControl() {
    const rewardSelect = document.getElementById('reward-select');
    const pointsInfo = document.getElementById('points-info');
    const signInBtn = document.getElementById('reward-signin-btn');
    if (!rewardSelect) return;

    const user = beddaSignedInUser();
    const balance = user ? beddaPointBalance(user) : 0;
    const ready = user ? affordableBeddaRewards(balance) : [];
    const unavailable = !user || ready.length === 0;
    const order = window.currentOrderDetails;

    if (order) {
        order.reward_id = user ? beddaCheckoutRewardId(order) : '';
        order.customer_id = beddaCheckoutCustomerId();
        order.isSubscription = false;
        if (Object.prototype.hasOwnProperty.call(order, 'use_points')) delete order.use_points;
    }

    while (rewardSelect.firstChild) rewardSelect.removeChild(rewardSelect.firstChild);
    const none = document.createElement('option');
    none.value = '';
    none.textContent = 'No reward on this order';
    rewardSelect.appendChild(none);
    ready.forEach((reward) => {
        const option = document.createElement('option');
        option.value = reward.id;
        option.textContent = `${reward.name} — ${beddaPointsWord(reward.points)}`;
        rewardSelect.appendChild(option);
    });

    const selected = order ? order.reward_id : '';
    rewardSelect.value = ready.some(reward => reward.id === selected) ? selected : '';
    if (order && order.reward_id !== rewardSelect.value) order.reward_id = rewardSelect.value;

    rewardSelect.disabled = unavailable;
    if (unavailable) rewardSelect.setAttribute('aria-disabled', 'true');
    else rewardSelect.removeAttribute('aria-disabled');
    rewardSelect.setAttribute('aria-describedby', 'reward-select-help points-info');

    const chosen = BEDDA_REWARDS.find(entry => entry.id === (order && order.reward_id));
    const chosenPrefix = chosen
        ? `${chosen.name} is on this order for ${beddaPointsWord(chosen.points)}. `
        : '';
    let message;
    if (!user) {
        message = 'Sign in to use a reward. This control stays unavailable until you are signed in. Points buy a whole item, and only a balance you already have can cover one.';
    } else if (!ready.length) {
        message = `You have ${beddaPointsWord(balance)}. ${beddaNextRemainSentence(balance)} This control stays unavailable until one whole item is covered.`;
    } else if (!nextBeddaRewardTier(balance).length) {
        message = `${chosenPrefix}You have ${beddaPointsWord(balance)}. Choose one: ${beddaJoin(beddaRewardLabels(ready), 'or')}. Every reward on the shelf is listed. The charge does not change.`;
    } else {
        message = `${chosenPrefix}You have ${beddaPointsWord(balance)}. Choose one: ${beddaJoin(beddaRewardLabels(ready), 'or')}. The charge does not change. ${beddaNextRemainSentence(balance)}`;
    }

    if (pointsInfo) {
        pointsInfo.classList.remove('hidden');
        pointsInfo.textContent = message;
    }
    if (signInBtn) signInBtn.classList.toggle('hidden', !!user);
}

function bindCheckoutRewardControl() {
    const rewardSelect = document.getElementById('reward-select');
    const signInBtn = document.getElementById('reward-signin-btn');
    if (rewardSelect && rewardSelect.dataset.bound !== '1') {
        rewardSelect.dataset.bound = '1';
        rewardSelect.addEventListener('change', () => {
            const order = window.currentOrderDetails;
            const select = document.getElementById('reward-select');
            if (!order || !select) return;
            if (!beddaSignedInUser() || select.disabled) {
                select.value = '';
                order.reward_id = '';
                syncCheckoutRewardControl();
                return;
            }
            order.reward_id = beddaCheckoutRewardId({ reward_id: select.value || '' });
            order.customer_id = beddaCheckoutCustomerId();
            order.isSubscription = false;
            if (Object.prototype.hasOwnProperty.call(order, 'use_points')) delete order.use_points;
            syncCheckoutRewardControl();
            updateOrderSummary();
        });
    }
    if (signInBtn && signInBtn.dataset.bound !== '1') {
        signInBtn.dataset.bound = '1';
        signInBtn.addEventListener('click', beddaOpenSignIn);
    }
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
        const saved = localStorage.getItem('bedda_cart');
        const items = saved ? JSON.parse(saved) : [];
        if (Array.isArray(items)) {
            items.forEach(item => {
                if (!item || typeof item !== 'object') return;
                item.isSubscription = false;
                item.subscriptionInterval = null;
            });
        }
        return items;
    }

    saveCart() {
        localStorage.setItem('bedda_cart', JSON.stringify(this.items));
    }

    addItem(product, price, quantity = 1, metadata = {}) {
        const meta = metadata && typeof metadata === 'object' ? metadata : {};
        const qty = Math.max(1, parseInt(quantity, 10) || 1);
        const parsedPrice = parseFloat(price);
        const linePrice = Number.isFinite(parsedPrice) ? parsedPrice : 0;
        const included = meta.included === true;
        // A free piece of a set must not collapse into a paid line of the same name, or the paid price becomes $0.
        // Paid lines of the same name at different prices stay separate, so a $15.50 balm is not rewritten to a set's $13.60.
        const separateFromPaid = included || linePrice === 0;
        const priceKey = Math.round(linePrice * 100);
        const existing = this.items.find(item => {
            if (!item || item.product !== product) return false;
            const itemPrice = Number(item.price);
            const itemIncluded = item.included === true || !(itemPrice > 0);
            if (separateFromPaid) return itemIncluded;
            return !itemIncluded && Math.round(itemPrice * 100) === priceKey;
        });
        if (existing) {
            existing.quantity += qty;
            existing.isSubscription = false;
            existing.subscriptionInterval = null;
            if (included || (separateFromPaid && linePrice === 0)) existing.included = true;
            if (meta.bundleName && !existing.bundleName) existing.bundleName = meta.bundleName;
            if (meta.bundleKey && !existing.bundleKey) existing.bundleKey = meta.bundleKey;
        } else {
            const paidSibling = this.items.some(item => item && item.product === product && Number(item.price) > 0 && item.included !== true);
            const line = {
                product: product,
                price: linePrice,
                quantity: qty,
                id: Date.now().toString() + Math.random().toString(36).substr(2, 5),
                // A cart line is a one-time purchase. This checkout does not start a subscription.
                isSubscription: false,
                subscriptionInterval: null
            };
            if (included || (linePrice === 0 && paidSibling)) line.included = true;
            if (meta.bundleName) line.bundleName = meta.bundleName;
            if (meta.bundleKey) line.bundleKey = meta.bundleKey;
            this.items.push(line);
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
                isSubscription: false,
                subscriptionInterval: null
            });
        }
    }

    /**
     * Re-add past order line items into the cart (account reorder).
     * @param {Array<{product:string, price:number, quantity?:number, qty?:number}>} items
     * @param {{ openCart?: boolean }} options
     * @returns {number} count of line items added
     */
    reorderItems(items, options = {}) {
        if (!Array.isArray(items) || items.length === 0) {
            return 0;
        }

        let added = 0;
        items.forEach((item) => {
            const product = item && item.product;
            const price = item && item.price;
            if (!product || price === undefined || price === null) return;
            const qty = Math.max(1, parseInt(item.quantity ?? item.qty ?? 1, 10) || 1);
            this.addItem(product, price, qty, { skipCrossSell: true });
            added += 1;
        });

        if (added > 0 && options.openCart !== false) {
            const modal = document.getElementById('cart-modal');
            if (!modal) {
                this.setupCartModal();
            }
            const cartModal = document.getElementById('cart-modal');
            if (cartModal && cartModal.classList.contains('hidden')) {
                this.toggleCartModal();
            } else if (cartModal) {
                this.renderCartItems();
            }
        }

        if (window.beddaLogger && added > 0) {
            window.beddaLogger.logEvent('reorder', { itemCount: added });
        }

        return added;
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
        return this.items.reduce((sum, item) => {
            if (!item || item.included === true) return sum;
            return sum + (Number(item.price) * Number(item.quantity));
        }, 0);
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
            modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[10050] hidden';
            modal.innerHTML = `
                <div class="bg-white rounded-lg p-8 max-w-md w-full mx-4 max-h-96 overflow-y-auto">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="font-playfair text-2xl font-bold text-stone-800">Your Cart</h3>
                        <button type="button" id="close-cart-modal" class="text-stone-500 hover:text-stone-700" aria-label="Close cart">
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
                        <div class="text-6xl mb-4">🛒</div>
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
            this.renderGiftProgress();
            return;
        }

        emptyState.classList.add('hidden');
        totalSection.classList.remove('hidden');

        container.innerHTML = this.items.map(item => {
            const included = item.included === true;
            const name = beddaEscapeHtml(item.product);
            const detail = included
                ? 'Included in the set'
                : `$${Number(item.price).toFixed(2)} x ${item.quantity}`;
            const lineTotal = included
                ? ''
                : `<span class="font-semibold text-stone-800">$${(Number(item.price) * Number(item.quantity)).toFixed(2)}</span>`;
            return `
            <div class="flex justify-between items-center py-3 border-b border-stone-200">
                <div class="flex-1 pr-4">
                    <h4 class="font-medium text-stone-800">${name}</h4>
                    <p class="text-sm text-stone-600">${detail}</p>
                </div>
                <div class="flex items-center space-x-3">
                    ${lineTotal}
                    <button onclick="cartManager.removeItem('${item.id}')" class="text-red-500 hover:text-red-700 p-1" aria-label="Remove ${name}">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 28 28" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                    </button>
                </div>
            </div>
        `;
        }).join('');

        document.getElementById('cart-total').textContent = `$${this.getTotal().toFixed(2)}`;

        // Add checkout cross-sells
        this.renderCartCrossSells();

        // Add gift-with-purchase progress
        this.renderGiftProgress();
    }

    renderGiftProgress() {
        const container = document.getElementById('cart-gift-progress');
        if (!container) return;

        if (!this.items.length) {
            container.innerHTML = '';
            return;
        }

        const points = beddaPointsEarned(this.getTotal());
        const user = beddaSignedInUser();
        const earned = beddaEscapeHtml(beddaPointsWord(points));

        if (!user) {
            const first = BEDDA_REWARDS[0];
            container.innerHTML = `
                <div class="mt-4 pt-4 border-t border-stone-200">
                    <div class="bg-stone-50 border border-stone-200 rounded-lg p-4">
                        <p class="text-sm font-semibold text-stone-800">This order earns ${earned}.</p>
                        <p class="text-sm text-stone-600 mt-1">Sign in to keep them. 1 point per $1. Points buy a whole item. The shelf starts with ${beddaEscapeHtml(first.name)} at ${beddaEscapeHtml(beddaPointsWord(first.points))}.</p>
                        <button type="button" onclick="beddaOpenSignIn()" class="mt-3 w-full bg-sage-600 hover:bg-sage-700 text-white font-semibold py-2 px-4 rounded-lg text-sm transition-colors">
                            Sign in
                        </button>
                    </div>
                </div>
            `;
            return;
        }

        const currentPoints = beddaPointBalance(user);
        const ready = affordableBeddaRewards(currentPoints);
        const readyText = ready.length
            ? `Ready on this order: ${beddaJoin(beddaRewardLabels(ready), 'and')}.`
            : 'No whole item is covered by this balance yet.';

        container.innerHTML = `
            <div class="mt-4 pt-4 border-t border-stone-200">
                <div class="bg-sage-50 border border-sage-200 rounded-lg p-4">
                    <p class="text-sm font-semibold text-sage-900">This order earns ${earned}.</p>
                    <p class="text-sm text-sage-800 mt-1">1 point per $1. Points buy a whole item. They do not come off the bill.</p>
                    <p class="text-xs text-sage-700 mt-2">Balance now: <strong>${beddaEscapeHtml(beddaPointsWord(currentPoints))}</strong>.</p>
                    <p class="text-sm text-sage-800 mt-1">${beddaEscapeHtml(readyText)}</p>
                    <p class="text-xs text-sage-600 mt-2 border-t border-sage-200 pt-2">${beddaEscapeHtml(beddaNextRemainSentence(currentPoints))}</p>
                </div>
            </div>
        `;
    }

    renderCartCrossSells() {
        const container = document.getElementById('cart-cross-sells');
        if (!container) return;

        // Determine cross-sell based on cart contents
        const cartProducts = this.items.map(item => item.product.toLowerCase());
        let crossSells = [];

        // Logic: suggest complementary products
        if (cartProducts.some(p => p.includes('massager') || p.includes('uni') || p.includes('she-ra'))) {
            crossSells.push({ product: 'Holy Grail Balm', price: 15.50, reason: 'Perfect for after-exfoliation repair' });
        }
        if (cartProducts.some(p => p.includes('holy grail') || p.includes('balm'))) {
            crossSells.push({ product: 'Uni Exfoliating Soap', price: 5.60, reason: 'Gentle exfoliation prep' });
        }
        if (cartProducts.some(p => p.includes('soap')) && !cartProducts.some(p => p.includes('balm'))) {
            crossSells.push({ product: 'Holy Grail Balm', price: 15.50, reason: 'Complete your skincare routine' });
        }

        if (crossSells.length === 0) {
            container.innerHTML = '';
            return;
        }

        container.innerHTML = `
            <div class="mt-4 pt-4 border-t border-stone-200">
                <p class="text-xs font-semibold text-stone-600 mb-1">Complete your ritual</p>
                <p class="text-[11px] text-stone-500 mb-3">These curated additions keep your routine balanced and luxurious.</p>
                ${crossSells.slice(0, 2).map(item => `
                    <div class="flex items-center justify-between bg-stone-50 rounded-xl p-3 mb-2 cursor-pointer hover:bg-stone-100 transition-colors cart-cross-sell-item" data-product="${item.product}" data-price="${item.price}">
                        <div>
                            <div class="text-sm font-semibold text-stone-800">${item.product}</div>
                            <div class="text-[11px] text-stone-500">${item.reason}</div>
                        </div>
                        <div class="text-sm font-semibold text-stone-800">$${item.price.toFixed(2)}</div>
                    </div>
                `).join('')}
                <p class="text-[11px] text-sage-600">Add now to keep your regimen cohesive and skip a second shipment.</p>
            </div>
        `;

        // Add click handlers
        container.querySelectorAll('.cart-cross-sell-item').forEach(item => {
            item.addEventListener('click', () => {
                const product = item.dataset.product;
                const price = parseFloat(item.dataset.price);
                this.addItem(product, price, 1);
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
        return `BEDDA-${uuid}`.toUpperCase();
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

        orderDetails.isSubscription = false;
        orderDetails.reward_id = '';
        orderDetails.customer_id = beddaCheckoutCustomerId();
        if (Object.prototype.hasOwnProperty.call(orderDetails, 'use_points')) delete orderDetails.use_points;
        window.pendingBundleSubscription = null;

        modal.id = 'order-form-modal';
        window.currentOrderDetails = orderDetails;
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[10050]';
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
                    <h4 class="font-semibold text-blue-800 mb-2">🔒 Secure Checkout</h4>
                    <p class="text-sm text-blue-700 leading-relaxed">
                        Your payment will be processed securely via Stripe. 
                        After completing your order, you'll receive a confirmation email within a few minutes.
                    </p>
                </div>

                <!-- Fulfillment Method Toggle -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-stone-700 mb-3">How would you like to receive your order?</label>
                    <div class="flex gap-4">
                        <label class="flex items-center cursor-pointer">
                            <input type="radio" name="fulfillment_method" value="shipping" checked class="h-4 w-4 text-sage-600 border-stone-300 focus:ring-sage-500" onchange="toggleFulfillmentFields()">
                            <span class="ml-2 text-stone-700">Ship to Address</span>
                        </label>
                        <label class="flex items-center cursor-pointer">
                            <input type="radio" name="fulfillment_method" value="pickup" class="h-4 w-4 text-sage-600 border-stone-300 focus:ring-sage-500" onchange="toggleFulfillmentFields()">
                            <span class="ml-2 text-stone-700">Local Pickup</span>
                        </label>
                    </div>
                </div>

                <!-- Shipping Address Section (shown by default) -->
                <div id="shipping-fields" class="border-t border-stone-200 pt-4 mt-4">
                    <h4 class="font-medium text-stone-800 mb-4">Shipping Address</h4>
                    <!-- existing shipping fields here -->
                </div>

                <!-- Pickup Fields (hidden by default) -->
                <div id="pickup-fields" class="border-t border-stone-200 pt-4 mt-4 hidden">
                    <h4 class="font-medium text-stone-800 mb-4">Pickup Details</h4>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-stone-700 mb-1">Pickup Location *</label>
                            <select name="pickup_location" id="pickup-location" class="w-full px-4 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-sage-500 focus:border-sage-500" required>
                                <option value="">Select location...</option>
                                <option value="vaughan">Vaughan — Honey and Barry Arena (1st Thursday, 6:30-8:30 PM)</option>
                                <option value="mississauga">Mississauga — McDonald's Lot, 1256 Eglinton Ave W (1st Wednesday, 6:30-8:30 PM)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-stone-700 mb-1">Preferred Pickup Date *</label>
                            <input type="date" name="pickup_date" id="pickup-date" class="w-full px-4 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-sage-500 focus:border-sage-500" required min="">
                            <p class="text-xs text-stone-500 mt-1">Pickup dates are the first Wednesday/Thursday of each month. We'll confirm your exact slot via email.</p>
                        </div>
                    </div>
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
                            <span class="text-sm font-medium text-stone-700">Create a Bedda Rewards account to earn points on this order</span>
                        </label>
                        <div id="account-password-fields" class="hidden mt-3 space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-stone-700 mb-1">Password (min 8 characters)</label>
                                <input type="password" id="account-password" class="w-full px-4 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-sage-500 focus:border-sage-500" minlength="8">
                            </div>
                            <p class="text-xs text-stone-600">Your account is created with the name and email from this order. You earn 1 point per $1, and points buy a whole item.</p>
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
                                    <label class="block text-sm font-medium text-stone-700 mb-1">Province *</label>
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
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-stone-700 mb-1">Postal Code *</label>
                                    <input type="text" name="postalCode" id="postal-code" oninput="var v=this.value.replace(/\s/g,''); if(v.length>=6) updateShippingEstimate();" onblur="updateShippingEstimate()" class="w-full px-4 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-sage-500 focus:border-sage-500" placeholder="A1A 1A1" required maxlength="7">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-stone-700 mb-1">Country</label>
                                    <input type="text" value="Canada" disabled class="w-full px-4 py-2 border border-stone-300 rounded-lg bg-stone-100 text-stone-500">
                                    <input type="hidden" name="country" value="CA">
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
                                <span>Select your province to see estimated shipping costs</span>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Section -->
                    <div id="payment-wrapper" class="mt-4 hidden">
                        <h4 class="font-semibold text-stone-800 mb-2">Payment Details</h4>
                        <div id="order-summary-display" class="bg-stone-50 border border-stone-200 rounded-lg p-4 mb-3 text-sm">
                            <div class="flex justify-between mb-1"><span class="text-stone-600">Subtotal:</span><span id="summary-subtotal" class="font-medium">—</span></div>
                            <div id="summary-discount-row" class="flex justify-between mb-1 hidden"><span class="text-stone-600">Discount:</span><span id="summary-discount" class="font-medium text-green-600">—</span></div>
                            <div class="flex justify-between mb-1"><span class="text-stone-600">Shipping:</span><span id="summary-shipping" class="font-medium">—</span></div>
                            <div id="summary-handling-row" class="flex justify-between mb-1"><span class="text-stone-600" id="summary-handling-label">Handling:</span><span id="summary-handling" class="font-medium">—</span></div>
                            <div class="flex justify-between mb-1"><span class="text-stone-600">Tax:</span><span id="summary-tax" class="font-medium">—</span></div>
                            <div class="border-t border-stone-200 pt-2 mt-2 flex justify-between font-bold text-stone-800"><span>Total:</span><span id="summary-total">—</span></div>
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

                    <!-- Reward item: points buy a whole product, they do not reduce the bill -->
                    <div class="border-t border-stone-200 pt-4 mt-4" id="points-section">
                        <label id="reward-select-label" for="reward-select" class="block text-stone-800 font-semibold mb-1">Use points for an item</label>
                        <p id="reward-select-help" class="text-sm text-stone-600 mb-2">1 point per $1 spent. A reward is a whole item on this order. It does not change the subtotal, shipping, handling, tax, or the amount Stripe charges.</p>
                        <select id="reward-select" name="reward_id" aria-describedby="reward-select-help points-info" class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm disabled:cursor-not-allowed disabled:bg-stone-100 disabled:text-stone-500">
                            <option value="">No reward on this order</option>
                        </select>
                        <p id="points-info" class="mt-2 text-sm text-sage-700 bg-sage-50 rounded-lg p-3" role="status"></p>
                        <button type="button" id="reward-signin-btn" class="mt-3 w-full bg-sage-600 hover:bg-sage-700 text-white font-semibold py-2 px-4 rounded-lg text-sm transition-colors hidden">Sign in to use points</button>
                    </div>

                    <div class="flex items-start space-x-3 pt-2">
                        <input type="checkbox" id="newsletter-subscribe" name="newsletter" class="mt-1 h-4 w-4 text-sage-600 border-stone-300 rounded focus:ring-sage-500">
                        <label for="newsletter-subscribe" class="text-sm text-stone-700">
                            Email me when the next loaf is cut. That is the only letter, and it has no code.
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

        // Initialize Stripe Payment Element once checkout details are ready
        tryInitPayment(orderDetails);

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

        syncCheckoutRewardControl();
        bindCheckoutRewardControl();

        const emailInput = document.getElementById('customer-email');
        if (emailInput) {
            emailInput.addEventListener('input', () => tryInitPayment(orderDetails));
            emailInput.addEventListener('blur', () => tryInitPayment(orderDetails));
        }
        document.querySelectorAll('input[name="fulfillment_method"]').forEach(radio => {
            radio.addEventListener('change', () => tryInitPayment(orderDetails));
        });

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
            const province = document.getElementById('province').value;
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
                if (!province) missing.push('Province');
                
                const cleanPostal = (postalCode || '').replace(/\s/g, '').toUpperCase();
                const postalRegex = /^[A-Z][0-9][A-Z][0-9][A-Z][0-9]$/;
                if (!postalCode || !postalRegex.test(cleanPostal)) {
                    missing.push('Valid Canadian Postal Code (e.g., A1A 1A1)');
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
                const rewardId = beddaCheckoutRewardId(orderDetails);
                const customerId = beddaCheckoutCustomerId();
                orderDetails.reward_id = rewardId;
                orderDetails.customer_id = customerId;
                orderDetails.isSubscription = false;
                if (Object.prototype.hasOwnProperty.call(orderDetails, 'use_points')) delete orderDetails.use_points;

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
                    country: 'CA',
                    handling_cost: parseFloat(window.handlingCost?.cost || 0),
                    handling_type: window.handlingCost?.type || '',
                    items: beddaPaidLines(orderDetails.items),
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
                    is_subscription: false,
                    isSubscription: false,
                    newsletter: document.getElementById('newsletter-subscribe')?.checked || false,
                    form_timestamp: parseInt(document.getElementById('form-timestamp')?.value || Date.now() / 1000),
                    create_account: createAccount,
                    account_password: createAccount ? accountPassword : null,
                    customer_id: customerId,
                    customer_email: window.currentUser?.email || customerEmail,
                    reward_id: rewardId
                };
                localStorage.setItem('bedda_pending_order', JSON.stringify(pendingOrderPayload));
                beddaRememberBoughtItems(orderDetails.items, rewardId);
                localStorage.setItem('bedda_last_order', orderDetails.orderNumber);

                const clientSecret = window.stripeElements?.options?.clientSecret;
                if (clientSecret) {
                    const { paymentIntent: retrievedIntent, error: retrieveError } = await window.stripeInstance.retrievePaymentIntent(clientSecret);
                    if (retrievedIntent && retrievedIntent.status === 'succeeded') {
                        window.location.href = `/checkout-success?order_number=${orderDetails.orderNumber}&payment_intent=${retrievedIntent.id}`;
                        return;
                    }
                }

                const { error, paymentIntent } = await window.stripeInstance.confirmPayment({
                    elements: window.stripeElements,
                    confirmParams: {
                        return_url: `${beddaSiteOrigin()}/checkout-success?order_number=${orderDetails.orderNumber}`,
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
                                    country: 'CA'
                                }
                            }
                        }
                    },
                    redirect: 'if_required'
                });

                if (error) {
                    const fail = new Error(error.message || 'Payment failed');
                    fail.code = error.code || '';
                    fail.decline_code = error.decline_code || '';
                    throw fail;
                }

                // Payment succeeded (no redirect needed)
                statusDiv.className = 'mt-4 bg-green-50 border border-green-200 p-4 rounded-lg';
                statusDiv.innerHTML = `
                    <h4 class="font-semibold text-green-800 mb-2">✓ Payment Successful!</h4>
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
                    country: 'CA',
                    items: beddaPaidLines(orderDetails.items),
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
                    reward_id: rewardId,
                    newsletter: document.getElementById('newsletter-subscribe')?.checked || false,
                    form_timestamp: parseInt(document.getElementById('form-timestamp')?.value || Date.now() / 1000),
                    taxAmount: parseFloat(document.getElementById('summary-tax')?.textContent.replace('$', '')) || 0,
                    grandTotal: parseFloat(document.getElementById('summary-total')?.textContent.replace('$', '')) || 0,
                    payment_intent_id: paymentIntent?.id || null,
                    is_subscription: false,
                    isSubscription: false,
                    payment_status: paymentIntent?.status || 'unknown',
                    create_account: createAccount,
                    account_password: createAccount ? accountPassword : null,
                    customer_id: customerId,
                    customer_email: window.currentUser?.email || customerEmail
                };

                // Save to localStorage in case redirect happens (3D Secure)
                localStorage.setItem('bedda_pending_order', JSON.stringify(orderPayload));
                localStorage.setItem('bedda_last_order', orderDetails.orderNumber);
                beddaRememberBoughtItems(orderDetails.items, rewardId);

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
                        throw new Error(submitResult.message || 'Order could not be saved. Please contact orders@bedda.ca');
                    }

                    // Clear cart
                    if (window.cartManager) {
                        window.cartManager.items = [];
                        window.cartManager.saveCart();
                        window.cartManager.renderCartCount();
                    }

                    // Auto-subscribe to newsletter if checked
                    if (document.getElementById('newsletter-subscribe')?.checked) {
                        const nlEmail = document.getElementById('customer-email')?.value.trim();
                        const nlName  = document.getElementById('customer-name')?.value.trim();
                        if (nlEmail) {
                            fetch('/api/newsletter-subscribe.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ email: nlEmail, name: nlName || '' })
                            }).catch(() => {}); // fire and forget — don't block the order flow
                        }
                    }

                    const accountCreated = submitResult.account_created;
                    const pointsEarned   = submitResult.points_earned || 0;

                    // Update local user points so UI reflects new balance immediately
                    if (window.currentUser && submitResult.total_points !== undefined) {
                        window.currentUser.points = submitResult.total_points;
                        saveUserToStorage(window.currentUser);
                        updateRewardsDisplay();
                    }

                    statusDiv.innerHTML = `
                        <h4 class="font-semibold text-green-800 mb-2">✓ Order Confirmed!</h4>
                        <p class="text-sm text-green-700">Order #${orderDetails.orderNumber}</p>
                        <p class="text-sm text-green-700">You earned <strong>${pointsEarned} points</strong>!</p>
                        ${accountCreated ? '<p class="text-sm text-sage-700 mt-1 font-medium">🎉 Your Bedda Rewards account was created! You can now log in with your email and password.</p>' : ''}
                        <p class="text-sm text-green-700 mt-1">Redirecting to confirmation...</p>
                    `;

                    setTimeout(() => {
                        let successUrl = `${beddaSiteOrigin()}/checkout-success?order_number=${orderDetails.orderNumber}`;
                        if (submitResult.upsell_code && submitResult.upsell_value) {
                            successUrl += `&upsell_code=${submitResult.upsell_code}&upsell_value=${submitResult.upsell_value}`;
                        }
                        if (accountCreated) {
                            successUrl += '&account_created=1';
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

                    beddaLogProfitBlock({
                        kind: 'order_save_failed',
                        message: submitErr && submitErr.message ? submitErr.message : 'Order save failed after payment',
                        order_number: orderDetails.orderNumber,
                        email: customerEmail,
                        payment_intent_id: paymentIntent && paymentIntent.id ? paymentIntent.id : ''
                    });
                    statusDiv.className = 'mt-4 bg-sage-50 border border-sage-200 p-4 rounded-lg';
                    statusDiv.innerHTML = `
                        <h4 class="font-semibold text-sage-800 mb-2">Payment Received, Order Pending</h4>
                        <p class="text-sm text-sage-700">Your payment went through, but we had trouble saving your order details.</p>
                        <p class="text-sm text-sage-700 mt-2">Please email <strong>orders@bedda.ca</strong> with your order number <strong>#${orderDetails.orderNumber}</strong> and we'll sort it out right away.</p>
                        <p class="text-sm text-sage-700 mt-2">No additional charge will be made.</p>
                    `;
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Order';
                }

            } catch (error) {
                console.error('Payment error:', error);
                beddaLogProfitBlock({
                    kind: 'payment_failed',
                    code: error.code || '',
                    decline_code: error.decline_code || '',
                    message: error.message || '',
                    order_number: orderDetails.orderNumber,
                    email: customerEmail
                });
                const testingCard = error.decline_code === 'test_mode_live_card'
                    || (error.message || '').indexOf('while testing') !== -1;
                const shown = testingCard
                    ? 'We could not take the payment on our side. Your card was not charged. Email orders@bedda.ca and we will finish this order.'
                    : (error.message || 'Payment failed');
                statusDiv.className = 'mt-4 bg-red-50 border border-red-200 p-4 rounded-lg';
                statusDiv.innerHTML = `<h4 class="font-semibold text-red-800 mb-2">Payment was not completed</h4><p class="text-sm text-red-700">${shown}</p>`;
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit Order';
            }
        });

        // Wire up account creation checkbox now that modal is in the DOM
        initAccountCreation();
    }

    formatOrderForEmail(order) {
        let text = `BEDDA ORDER\n`;
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
        text += `Email us at josiechavd03@gmail.com\n`;
        
        return text;
    }

    showOrderModal(orderText, orderNumber) {
        // Create order modal
        const modal = document.createElement('div');
        modal.id = 'order-modal';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[10050]';
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
                        <li>Email to: <strong>josiechavd03@gmail.com</strong></li>
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
function tryInitPayment(orderDetails) {
    const details = orderDetails || window.currentOrderDetails;
    if (!details || !details.items || details.items.length === 0) return;

    const email = document.getElementById('customer-email')?.value?.trim();
    if (!email) return;

    const fulfillmentMethod = (document.querySelector('input[name="fulfillment_method"]:checked') || {}).value || 'shipping';
    if (fulfillmentMethod === 'shipping' && !window.selectedShippingOption) return;

    initPayment(details);
}

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

        orderDetails.isSubscription = false;
        orderDetails.reward_id = beddaCheckoutRewardId(orderDetails);
        orderDetails.customer_id = beddaCheckoutCustomerId();
        if (Object.prototype.hasOwnProperty.call(orderDetails, 'use_points')) delete orderDetails.use_points;

        // Build minimal intent payload (backend will recalculate totals securely).
        // reward_id rides along as a whole item. use_points is omitted so it cannot reduce the charge.
        const intentData = {
            orderNumber: orderDetails.orderNumber || '',
            items: beddaPaidLines(orderDetails.items),
            subtotal: subtotal, // client-side estimate only
            customerName: document.getElementById('customer-name')?.value || '',
            customerEmail: document.getElementById('customer-email')?.value || '',
            isSubscription: false,
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
            reward_id: orderDetails.reward_id,
            customer_id: orderDetails.customer_id,
            form_timestamp: parseInt(document.getElementById('form-timestamp')?.value || Date.now() / 1000)
        };

        const response = await fetch('/api/create-payment-intent.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(intentData)
        });

        const result = await response.json();

        if (!response.ok || !result.success || !result.clientSecret) {
            throw new Error(result.error || 'Payment initialization failed');
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

        const stripePublishableKey = result.stripePublishableKey || '';
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

    tryInitPayment(orderDetails);
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

    // A coupon can lower the merchandise subtotal. A reward cannot, and this checkout has no 5% subscription.
    let discount = 0;
    if (orderDetails.coupon && orderDetails.coupon.discount) {
        discount = parseFloat(orderDetails.coupon.discount);
    } else if (orderDetails.coupon && orderDetails.coupon.originalSubtotal && orderDetails.coupon.finalTotal) {
        discount = parseFloat(orderDetails.coupon.originalSubtotal) - parseFloat(orderDetails.coupon.finalTotal);
    }
    if (!Number.isFinite(discount) || discount < 0) discount = 0;
    discount = Math.min(discount, subtotal);

    if (discountRow && discountEl) {
        if (discount > 0) {
            discountRow.classList.remove('hidden');
            discountEl.textContent = `-$${discount.toFixed(2)}`;
        } else {
            discountRow.classList.add('hidden');
        }
    }

    // Shipping
    let shipping = 0;
    if (window.selectedShippingOption && typeof window.selectedShippingOption.total === 'number') {
        shipping = window.selectedShippingOption.total;
    }

    // ✅ FIX: Always recalculate handling from selected option or cart items
    const handling = window.selectedShippingOption?.handling 
        || calculateHandlingCost(window.cartManager?.items || []);
    const method = document.querySelector('input[name="fulfillment_method"]:checked')?.value || 'shipping';

    // ✅ FIX: Calculate tax BEFORE using it
    const province = document.getElementById('province')?.value || 'ON';
    const taxRate = getTaxRate(province);
    
    // Use the 'shipping' variable calculated right above it
    const taxableAmount = Math.max(0, subtotal - discount) + (method === 'shipping' ? shipping + handling.cost : 0);
    let tax = 0; // taxableAmount * taxRate; // UNCOMMENT THIS WHEN MAKING > 30K

    // ✅ NOW safe to use 'tax'
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
    } else if (shipping === 0 && window.selectedShippingOption && window.selectedShippingOption.id === 'fallback') {
        if (shippingEl) shippingEl.textContent = 'To be confirmed';
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

// Cross-Sell System for Phase 2
class CrossSellManager {
    constructor() {
        this.complementaryProducts = {
            'Be Mine Soap': ['Holy Grail Balm', 'Minty Lip Balm'],
            'Uni Exfoliating Soap': ['Holy Grail Balm'],
            'He-Man Exfoliating Soap': ['Plain Jane Face Balm'],
            'Holy Grail Balm': ['Uni Exfoliating Soap', 'Minty Lip Balm'],
            'Plain Jane Balm': ['Plain Jane Soap Loaf'],
            'Plain Jane Face Balm': ['Plain Jane Soap Loaf'],
            'Plain Jane Face & Body Balm': ['Plain Jane Soap Loaf'],
            'Minty Lip Balm': ['Holy Grail Balm'],
            'Pinky Minty Lip Balm': ['Holy Grail Balm'],
            'The Massager Soap': ['Holy Grail Balm'],
            'Holiday Ginger Soap': ['Holy Grail Balm', 'Minty Lip Balm']
        };
        
        this.routineCompletions = {
            'soap': {
                type: 'balm',
                message: 'Complete your routine with nourishing hydration',
                benefit: 'Lock in moisture after cleansing'
            },
            'balm': {
                type: 'soap',
                message: 'Don\'t forget the first step: gentle cleansing',
                benefit: 'Prepare skin for optimal absorption'
            }
        };

        this._batchDepth = 0;
        this._batchLast = null;
    }

    beginBatchAdd() {
        this._batchDepth += 1;
        this._batchLast = null;
    }

    endBatchAdd() {
        this._batchDepth = Math.max(0, this._batchDepth - 1);
        if (this._batchDepth === 0 && this._batchLast) {
            const { product, price } = this._batchLast;
            this._batchLast = null;
            setTimeout(() => this.showCrossSell(product, price), 600);
        }
    }

    isBatchAdding() {
        return this._batchDepth > 0;
    }

    showCrossSell(addedProduct, addedPrice) {
        if (this.isBatchAdding()) {
            this._batchLast = { product: addedProduct, price: addedPrice };
            return;
        }

        // Throttle: only one cross-sell prompt at a time, and not within 5s of last one
        const now = Date.now();
        if (this._lastShown && now - this._lastShown < 5000) return;
        document.querySelectorAll('[data-cross-sell-modal]').forEach(el => el.remove());
        this._lastShown = now;

        // Determine type of product added
        const isSoap = addedProduct.toLowerCase().includes('soap');
        const isBalm = addedProduct.toLowerCase().includes('balm');
        
        let complementary = this.complementaryProducts[addedProduct] || [];
        let routineMessage = '';
        
        if (isSoap) {
            routineMessage = 'Customers who bought soap also added balm to lock in moisture';
        } else if (isBalm) {
            routineMessage = 'Complete your routine with gentle cleansing';
        }

        if (complementary.length === 0) return;

        // Create modal
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[10050] p-4';
        modal.setAttribute('data-cross-sell-modal', 'true');
        modal.innerHTML = `
            <div class="bg-white rounded-2xl max-w-md w-full p-6 transform animate-bounce-in">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <div class="text-sm text-sage-600 font-semibold mb-1">Complete the Routine</div>
                        <h3 class="font-playfair text-2xl font-bold text-stone-800">Don't Forget...</h3>
                    </div>
                    <button onclick="this.closest('.fixed').remove()" class="text-stone-400 hover:text-stone-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                
                <div class="bg-sage-50 border border-sage-200 rounded-lg p-4 mb-6">
                    <p class="text-stone-700 mb-2">${routineMessage}</p>
                    <p class="text-sm text-stone-600">${isSoap ? 'Soap cleanses but can strip natural oils. Following with balm restores your skin barrier.' : 'Balm works best on clean skin. Start with a gentle cleanse first.'}</p>
                </div>

                <div class="space-y-3" id="cross-sell-options">
                    ${complementary.map(product => this.getProductHTML(product)).join('')}
                </div>

                <div class="mt-6 pt-6 border-t border-stone-200 flex gap-3">
                    <button onclick="window.location.href='/bundles'" class="flex-1 bg-sage-100 text-sage-800 py-3 rounded-lg font-semibold hover:bg-sage-200 transition-colors">
                        View Bundles (Save 20%)
                    </button>
                    <button onclick="this.closest('.fixed').remove(); if(window.cartManager) window.cartManager.toggleCartModal();" class="flex-1 bg-stone-800 text-white py-3 rounded-lg font-semibold hover:bg-stone-900 transition-colors">
                        Continue to Cart
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        
        // Track cross-sell impression
        if (window.beddaLogger) {
            window.beddaLogger.logEvent('cross_sell_shown', {
                triggerProduct: addedProduct,
                suggestions: complementary
            });
        } else {
            console.log('Cross-sell event would be logged:', addedProduct, complementary);
        }
    }

    getProductHTML(productName) {
        // Prices/names synced with products.html catalog
        const productDatabase = {
            'Holy Grail Balm': { price: '15.50', image: 'holy grail.jpg', benefit: 'Repairs dry skin overnight' },
            'Plain Jane Balm': { price: '15.50', image: 'Plane Jane Face body balm.jpg', benefit: 'Soothes sensitive skin instantly', displayName: 'Plain Jane Face Balm' },
            'Plain Jane Face Balm': { price: '15.50', image: 'Plane Jane Face body balm.jpg', benefit: 'Soothes sensitive skin instantly' },
            'Plain Jane Face & Body Balm': { price: '15.50', image: 'Plane Jane Face body balm.jpg', benefit: 'Soothes sensitive skin instantly', displayName: 'Plain Jane Face Balm' },
            'Uni Exfoliating Soap': { price: '5.60', image: 'Uni Soap.jpg', benefit: 'Smooths rough patches' },
            'Plain Jane Soap': { price: '4.05', image: 'Plain Jane Soap.jpg', benefit: 'Gentlest cleanse for sensitive types' },
            'Plain Jane Soap Loaf': { price: '38.99', image: 'Plain Jane Soap.jpg', benefit: 'Unscented bars for the whole family' },
            'Minty Lip Balm': { price: '4.85', image: 'Minty cream.jpg', benefit: 'Heals chapped lips fast' },
            'Pinky Minty Lip Balm': { price: '4.00', image: 'Minty cream.jpg', benefit: 'Peppermint lip care on clearance' }
        };

        const product = productDatabase[productName] || { price: '0.00', image: null, benefit: '' };
        const label = product.displayName || productName;

        // Build image HTML with fallback using simple concatenation
        let imageHTML;
        if (product.image) {
            imageHTML = '<img src="images/' + product.image + '" alt="' + label + '" class="w-16 h-16 object-contain p-1 rounded-lg bg-stone-100" onerror="this.src=\'images/default-product.jpg\'; this.onerror=null;">';
        } else {
            imageHTML = '<div class="w-16 h-16 bg-stone-200 rounded-lg flex items-center justify-center text-stone-400 text-xs">No Image</div>';
        }

        const cartName = product.displayName || productName;
        return '<div class="flex items-center gap-4 p-3 border border-stone-200 rounded-lg hover:border-sage-500 transition-colors">' +
            imageHTML +
            '<div class="flex-1">' +
                '<div class="font-bold text-stone-800">' + label + '</div>' +
                '<div class="text-sm text-stone-600">' + product.benefit + '</div>' +
                '<div class="text-sage-600 font-bold mt-1">$' + product.price + '</div>' +
            '</div>' +
            '<button onclick="window.cartManager.addItem(\'' + cartName.replace(/'/g, "\\'") + '\', ' + product.price + '); this.textContent=\'✓ Added\'; this.disabled=true;" class="px-4 py-2 bg-sage-600 text-white rounded-lg text-sm font-semibold hover:bg-sage-700 transition-colors">Add</button>' +
        '</div>';
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
    console.log('🟢 updateShippingEstimate CALLED');
    const province = document.getElementById('province')?.value;
    const postalCode = document.getElementById('postal-code')?.value;
    const shippingDiv = document.getElementById('shipping-estimate');
    const placeholder = document.getElementById('shipping-estimate-placeholder');

    // Check fulfilment method
    const methodInput = document.querySelector('input[name="fulfillment_method"]:checked');
    const method = methodInput ? methodInput.value : 'shipping';

    if (method === 'pickup') {
        // Directly show free pickup (no API call)
        if (shippingDiv) {
            shippingDiv.innerHTML = `
                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                    <strong>Local Pickup — FREE</strong>
                    <p class="text-sm text-green-800">You'll receive pickup location and time in your confirmation email.</p>
                </div>`;
            shippingDiv.classList.remove('hidden');
            placeholder?.classList.add('hidden');
        }
        window.selectedShippingOption = { id: 'pickup', carrier: 'Local Pickup', total: 0 };
        window.shippingOptions = null;
        return;
    }
    
    if (!province || !postalCode) {
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
                postal_code: postalCode,
                province: province,
                subtotal: subtotal,
                fulfillment_method: 'shipping'
            })
        });

        if (!response.ok) {
            const errorText = await response.text().catch(() => 'No response body');
            console.error(`API Error ${response.status}:`, errorText.substring(0, 500));
            throw new Error(`Shipping API returned ${response.status}`);
        }
        
        const result = await response.json();

        const handling = calculateHandlingCost(items);
        result.options.forEach(opt => {
            opt.handling = handling;
        });

        window.handlingCost = handling;
        
        if (result.success && result.options && result.options.length > 0) {
            console.log('success')
            // Store all options for selection
            window.shippingOptions = result.options;
            
            // Auto-select cheapest option initially
            const cheapest = result.options.reduce((min, opt) => 
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
                const optionsHTML = result.options.map(opt => `
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
                            ✓ Rates calculated in real-time via Chit Chats
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

        // Build fallback option just like a normal shipping card
        const fallbackOption = {
            id: 'fallback',
            carrier: 'Standard Shipping',
            delivery_time: '2-8 business days',
            tracking: 'Tracking included',
            total: 0,            // price will be confirmed via email
            breakdown: null,
            estimated: true
        };

        window.shippingOptions = [fallbackOption];
        window.selectedShippingOption = fallbackOption;

        if (shippingDiv) {
            shippingDiv.innerHTML = `
                <div class="space-y-3">
                    <h4 class="font-semibold text-stone-800">Select Shipping Method</h4>
                    <label class="flex items-start gap-3 p-3 border border-sage-500 bg-sage-50 rounded-lg cursor-pointer" data-option-id="fallback">
                        <input type="radio" name="shipping_option" value="fallback" class="mt-1" checked onchange="selectShippingOption('fallback')">
                        <div class="flex-1">
                            <div class="flex justify-between items-start">
                                <span class="font-semibold text-stone-800">Standard Shipping</span>
                                <span class="font-bold text-sage-700">$—</span>
                            </div>
                            <p class="text-sm text-stone-600 mt-1">2-8 business days</p>
                            <p class="text-xs text-stone-500 mt-1">Tracking included</p>
                            <p class="text-xs text-sage-700 mt-2">⚠ We'll confirm the exact price via email after you submit.</p>
                        </div>
                    </label>
                    <p class="text-xs text-stone-500 italic mt-2">
                        ✓ Rates will be confirmed based on your location
                    </p>
                </div>
            `;

            // Make the card clickable
            shippingDiv.querySelector('[data-option-id]').addEventListener('click', function(e) {
                if (e.target.tagName !== 'INPUT') {
                    const radio = this.querySelector('input[type="radio"]');
                    radio.checked = true;
                    selectShippingOption('fallback');
                }
            });

            shippingDiv.classList.remove('hidden');
            placeholder?.classList.add('hidden');
        }
    }
}

// Handle shipping option selection
function selectShippingOption(optionId) {
    const options = window.shippingOptions || [];
    const selected = options.find(opt => opt.id === optionId);
    
    if (!selected) return;
    
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
window.crossSellManager = crossSellManager;

document.getElementById('province')?.addEventListener('change', updateShippingEstimate);

function initCart() {
    cartManager = new CartManager();
    window.cartManager = cartManager;

    // Modify existing add-to-cart functionality to trigger cross-sell
    const originalAddToCart = window.cartManager ? window.cartManager.addItem.bind(window.cartManager) : null;
    if (originalAddToCart) {
        window.cartManager.addItem = function(product, price, quantity = 1, metadata = {}) {
            originalAddToCart(product, price, quantity, metadata);

            // During multi-item bundle adds, remember a product that can drive one post-batch prompt
            if (crossSellManager.isBatchAdding()) {
                const hasSuggestions = (crossSellManager.complementaryProducts[product] || []).length > 0;
                if (hasSuggestions || !crossSellManager._batchLast) {
                    crossSellManager._batchLast = { product, price };
                }
                return;
            }

            if (metadata && metadata.skipCrossSell) return;
            
            // Show cross-sell after a short delay (let the cart update first)
            setTimeout(() => {
                if (crossSellManager.isBatchAdding()) return;
                crossSellManager.showCrossSell(product, price);
            }, 600);
        };
    }
    
    // Handle BOTH regular products AND bundles (single path — reads qty from card .qty-input)
    document.querySelectorAll('.add-to-cart, .add-to-cart-bundle').forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            
            // Regular product
            if (button.classList.contains('add-to-cart')) {
                const product = e.currentTarget.dataset.product;
                const price = e.currentTarget.dataset.price;
                if (!product || !price) return;
                const card = button.closest('.product-card');
                const qtyInput = card?.querySelector('.qty-input');
                let qty = parseInt(qtyInput?.value, 10);
                if (!Number.isFinite(qty) || qty < 1) qty = 1;
                cartManager.addItem(product, price, qty);
                showButtonFeedback(button, qty > 1 ? `✓ Added ${qty}!` : 'Added!');
            }
            // Bundle
            else if (button.classList.contains('add-to-cart-bundle')) {
                if (button.dataset.sellAs === 'set') {
                    const setName = button.dataset.product;
                    const setPrice = button.dataset.price;
                    if (!setName || !setPrice) return;
                    cartManager.addItem(setName, setPrice, 1, {
                        bundleName: setName,
                        bundleKey: button.dataset.bundle || setName
                    });
                    showButtonFeedback(button, '✓ Added to Cart!');
                    return;
                }
                const bundle = button.dataset.bundle;
                const price = button.dataset.price;
                const itemsJson = button.dataset.items || '[]';
                
                try {
                    const items = JSON.parse(itemsJson);
                    
                    const bundleName = beddaBundleTitle(button);
                    const bundleKey = String(button.dataset.bundle || '').replace(/\s+/g, ' ').trim();
                    if (items.length > 0) {
                        crossSellManager.beginBatchAdd();
                        try {
                            items.forEach(item => {
                                if (!item || !item.product || item.price === undefined || item.price === null) return;
                                const itemPrice = Number(item.price);
                                if (!Number.isFinite(itemPrice)) return;
                                const included = item.included === true || itemPrice === 0;
                                cartManager.addItem(item.product, itemPrice, item.qty || item.quantity || 1, {
                                    included: included,
                                    bundleName: bundleName,
                                    bundleKey: bundleKey && bundleKey !== bundleName ? bundleKey : ''
                                });
                            });
                        } finally {
                            crossSellManager.endBatchAdd();
                        }
                    } else {
                        cartManager.addItem(bundle, price, 1, {
                            bundleName: bundleName,
                            bundleKey: bundleKey && bundleKey !== bundleName ? bundleKey : ''
                        });
                    }
                    
                    showButtonFeedback(button, '✓ Added to Cart!');
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
function openRewardsSignIn(options) {
    const modal = document.getElementById('auth-modal');
    const loginView = document.getElementById('auth-login-view');
    const registerView = document.getElementById('auth-register-view');
    if (!modal || !loginView) return;
    const loginTitle = loginView.querySelector('h2');
    const loginLead = loginView.querySelector('p');
    const registerTitle = registerView ? registerView.querySelector('h2') : null;
    const registerLead = registerView ? registerView.querySelector('p') : null;
    if (loginTitle) loginTitle.textContent = 'Keep your points';
    if (loginLead) loginLead.textContent = 'Sign in and the points from every order stay with you. 1 point per $1. Minty Lip Balm is 97 points, and it does not come off the price.';
    if (registerTitle) registerTitle.textContent = 'Start keeping points';
    if (registerLead) registerLead.textContent = 'Create an account before you order. The first whole item is Minty Lip Balm at 97 points.';
    const showRegister = options && options.register;
    loginView.classList.toggle('hidden', !!showRegister);
    if (registerView) registerView.classList.toggle('hidden', !showRegister);
    document.getElementById('auth-loading')?.classList.add('hidden');
    document.getElementById('auth-forgot-view')?.classList.add('hidden');
    document.getElementById('auth-reset-view')?.classList.add('hidden');
    modal.classList.remove('hidden');
    window.beddaRewardsSignIn = true;
    const focusId = showRegister ? 'register-first-name' : 'login-email';
    document.getElementById(focusId)?.focus();
}

document.addEventListener('DOMContentLoaded', () => {
    initCart();
    initAuth();
    document.getElementById('view-rewards-link')?.addEventListener('click', (event) => {
        if (window.currentUser) return;
        event.preventDefault();
        openRewardsSignIn();
    });
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
        localStorage.setItem('bedda_auth_user', JSON.stringify(userData));
    } catch (e) { /* storage full or disabled */ }
}
function loadUserFromStorage() {
    try {
        const raw = localStorage.getItem('bedda_auth_user');
        return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
}
function clearUserStorage() {
    try { localStorage.removeItem('bedda_auth_user'); } catch (e) {}
}

// customer-session.php avoids Cloudflare WAF on POST to *auth* paths; PUT on customer-auth.php is fallback
const AUTH_ENDPOINT_CANDIDATES = [
    { url: '/api/customer-session.php', writeMethod: 'POST' },
    { url: '/api/customer-auth.php', writeMethod: 'PUT' }
];
let _resolvedAuthEndpoint = null;

async function resolveAuthEndpoint() {
    if (_resolvedAuthEndpoint) return _resolvedAuthEndpoint;

    for (const candidate of AUTH_ENDPOINT_CANDIDATES) {
        try {
            const probe = await fetch(`${candidate.url}?action=me`, { credentials: 'include' });
            if (probe.status !== 404) {
                _resolvedAuthEndpoint = candidate;
                return candidate;
            }
        } catch (e) { /* try next */ }
    }

    _resolvedAuthEndpoint = AUTH_ENDPOINT_CANDIDATES[1];
    return _resolvedAuthEndpoint;
}

async function customerAuthRequest({ method = 'GET', action, body, headers: extraHeaders } = {}) {
    const endpoint = await resolveAuthEndpoint();
    const url = action
        ? `${endpoint.url}?action=${encodeURIComponent(action)}`
        : endpoint.url;

    const headers = {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(extraHeaders || {})
    };

    const isWrite = method === 'PUT' || method === 'POST';
    const fetchMethod = isWrite ? endpoint.writeMethod : method;

    let fetchBody;
    if (isWrite && body !== undefined) {
        headers['Content-Type'] = 'application/json';
        fetchBody = JSON.stringify(body);
    }

    return fetch(url, {
        method: fetchMethod,
        credentials: 'include',
        headers,
        body: fetchBody
    });
}

async function parseCustomerAuthResponse(response, fallbackMessage) {
    if (response.status === 404) {
        return { ok: false, message: 'Account service unavailable. Please refresh the page or try again later.' };
    }
    if (response.status === 503) {
        return { ok: false, message: 'Customer accounts are temporarily unavailable. Please check back later.' };
    }
    if (response.status === 403) {
        return { ok: false, message: 'Login blocked by security filter. Please refresh the page and try again.' };
    }
    const data = await response.json().catch(() => ({ success: false }));
    if (response.status === 400 && (data.message || '').toLowerCase().includes('invalid action')) {
        return { ok: false, message: 'Login service is updating. Please refresh and try again in a moment.' };
    }
    if (!response.ok || !data.success) {
        const message = data.message || fallbackMessage;
        return { ok: false, message, data };
    }
    return { ok: true, data };
}

// Authentication Module
function initAuth() {
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
        window.beddaRewardsSignIn = false;
        hideError();
    });

    // Close on backdrop click
    authModal.addEventListener('click', (e) => {
        if (e.target === authModal) {
            authModal.classList.add('hidden');
            window.beddaRewardsSignIn = false;
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
            const response = await customerAuthRequest({
                method: 'PUT',
                body: { action: 'login', email, password }
            });

            const result = await parseCustomerAuthResponse(response, 'Login failed. Please try again.');
            if (!result.ok) {
                showError(result.message);
                return;
            }

            window.currentUser = result.data.data;
            saveUserToStorage(result.data.data);
            authModal.classList.add('hidden');
            updateLoginButtonState(true);
            updateRewardsDisplay();
            if (window.beddaRewardsSignIn && !/\/account(\.html)?\/?$/.test(location.pathname)) {
                window.beddaRewardsSignIn = false;
                location.href = '/account';
            } else if (typeof window.beddaOnSignedIn === 'function') {
                window.beddaOnSignedIn();
            }
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
    forgotLink.innerHTML = `<button type="button" id="show-forgot" class="text-sm text-sage-600 hover:text-sage-700 underline">Forgot password?</button>`;
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
            const response = await customerAuthRequest({
                method: 'PUT',
                body: { action: 'register', first_name: firstName, last_name: lastName, email, password }
            });

            const result = await parseCustomerAuthResponse(response, 'Registration failed. Please try again.');
            if (!result.ok) {
                showError(result.message);
                return;
            }

            window.currentUser = result.data.data;
            saveUserToStorage(result.data.data);
            showSuccess(`Welcome, ${result.data.data.first_name || 'there'}! Your account is ready.`);
            updateLoginButtonState(true);
            updateRewardsDisplay();
            setTimeout(() => {
                authModal.classList.add('hidden');
                hideSuccess();
                if (window.beddaRewardsSignIn && !/\/account(\.html)?\/?$/.test(location.pathname)) {
                    window.beddaRewardsSignIn = false;
                    location.href = '/account';
                } else if (typeof window.beddaOnSignedIn === 'function') {
                    window.beddaOnSignedIn();
                }
            }, 2200);
        } catch (err) {
            console.error('Registration error:', err);
            showError('An error occurred. Please try again.');
        } finally {
            hideLoading();
        }
    });

    // Inject forgot-password views into modal
    authModal.querySelector('.bg-white').insertAdjacentHTML('beforeend', `
        <div id="auth-forgot-view" class="hidden">
            <h2 class="text-2xl font-playfair font-bold text-stone-800 mb-2">Reset Password</h2>
            <p class="text-stone-600 mb-6">Enter your email and we'll send you a reset code.</p>
            <form id="forgot-form">
                <input type="email" id="forgot-email" required placeholder="your@email.com" class="w-full border border-stone-300 rounded-lg px-4 py-3 mb-4 focus:outline-none focus:ring-2 focus:ring-sage-500">
                <button type="submit" class="w-full bg-sage-600 text-white py-3 rounded-lg font-medium hover:bg-sage-700 transition">Send Code</button>
            </form>
            <p class="text-center mt-4 text-stone-600 text-sm">
                <button type="button" id="back-to-login" class="text-sage-600 hover:text-sage-700 underline">Back to login</button>
            </p>
        </div>
        <div id="auth-reset-view" class="hidden">
            <h2 class="text-2xl font-playfair font-bold text-stone-800 mb-2">Enter Code</h2>
            <p class="text-stone-600 mb-6">Check your email for a 6-digit code.</p>
            <form id="reset-form">
                <input type="text" id="reset-code" required maxlength="6" placeholder="6-digit code" inputmode="numeric" pattern="[0-9]*" class="w-full border border-stone-300 rounded-lg px-4 py-3 mb-4 text-center tracking-widest font-mono text-lg focus:outline-none focus:ring-2 focus:ring-sage-500">
                <input type="password" id="reset-password" required minlength="8" placeholder="New password (min 8 chars)" class="w-full border border-stone-300 rounded-lg px-4 py-3 mb-4 focus:outline-none focus:ring-2 focus:ring-sage-500">
                <button type="submit" class="w-full bg-sage-600 text-white py-3 rounded-lg font-medium hover:bg-sage-700 transition">Update Password</button>
            </form>
            <p class="text-center mt-4 text-stone-600 text-sm">
                <button type="button" id="back-to-login2" class="text-sage-600 hover:text-sage-700 underline">Back to login</button>
            </p>
        </div>
    `);

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
            const response = await customerAuthRequest({
                method: 'PUT',
                body: { action: 'forgot', email }
            });
            const result = await parseCustomerAuthResponse(response, 'Failed to send reset code. Please try again.');
            if (!result.ok) {
                showError(result.message);
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
            const response = await customerAuthRequest({
                method: 'PUT',
                body: { action: 'reset-password', email, code, new_password: password }
            });
            const result = await parseCustomerAuthResponse(response, 'Failed to reset password. Please try again.');
            if (!result.ok) {
                showError(result.message);
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
                <h3 class="text-xl font-bold text-stone-800 mb-1" id="auth-success-msg"></h3>
                <p class="text-stone-500 text-sm">Redirecting you now...</p>
            `;
            authModal.querySelector('.bg-white').appendChild(successView);
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
        const response = await customerAuthRequest({ action: 'me' });

        // Handle 503 Service Unavailable (database not configured)
        if (response.status === 503) {
            console.log('Customer auth not available yet');
            if (!cached) updateLoginButtonState(false);
            return;
        }

        if (!response.ok) {
            if (response.status === 401) {
                // Expected behavior for guests — clear stale cached session
                console.log('Guest session: Please log in to unlock Bedda Rewards and other benefits!');
                window.currentUser = null;
                clearUserStorage();
                updateLoginButtonState(false);
                return;
            }
            if (response.status === 404) {
                console.warn('Account service unavailable');
                if (!cached) updateLoginButtonState(false);
                return;
            }
            if (response.status === 500) {
                console.warn('Auth API unavailable (server error). Using cached session if available.');
                if (!cached) updateLoginButtonState(false);
                return;
            }
            console.warn('Auth check failed:', response.status);
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
    } finally {
        window.beddaAuthReady = true;
        document.dispatchEvent(new Event('bedda-auth-ready'));
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
        <a href="/account" class="flex items-center px-4 py-2.5 text-sm text-stone-700 hover:bg-sage-50 hover:text-sage-700 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            My Account
        </a>
        <a href="/account#rewards" class="flex items-center px-4 py-2.5 text-sm text-stone-700 hover:bg-sage-50 hover:text-sage-700 transition-colors">
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
        const fullName = first && last ? `${first} ${last}` : (first || 'Bedda Member');
        loginBtn.title = `Logged in as ${fullName} (${window.currentUser.points || 0} points)`;
        ensureAccountDropdown();
    } else {
        // Reset to person icon
        loginBtn.innerHTML = `<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>`;
        loginBtn.title = 'Sign in to access Bedda Rewards';
        const dropdown = document.getElementById('account-dropdown');
        if (dropdown) dropdown.classList.add('hidden');
    }
}

function updateRewardsDisplay() {
    if (window.cartManager) {
        window.cartManager.renderGiftProgress();
    }
    syncCheckoutRewardControl();
}

async function logoutUser() {
    try {
        await customerAuthRequest({ action: 'logout' });
    } catch (e) {}
    window.currentUser = null;
    clearUserStorage();
    updateLoginButtonState(false);
    updateRewardsDisplay();
    // If on account page, redirect home
    if (/\/account(\.html)?\/?$/.test(location.pathname)) {
        location.href = '/';
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