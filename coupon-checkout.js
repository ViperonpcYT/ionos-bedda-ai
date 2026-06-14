// Coupon functionality for checkout
class CouponManager {
    constructor(cartManager) {
        this.cartManager = cartManager;
        this.appliedCoupon = null;
        this.originalSubtotal = 0;
        this.init();
    }
    
    init() {
        // Inject coupon input into cart modal
        this.injectCouponUI();
        this.setupEventListeners();
    }
    
    injectCouponUI() {
        const totalSection = document.getElementById('cart-total-section');
        if (!totalSection) return;
        
        const couponHTML = `
            <div class="border-t pt-4 mb-4">
                <label class="block text-sm font-medium text-stone-700 mb-2">Coupon Code</label>
                <div class="flex gap-2">
                    <input type="text" id="coupon-input" class="flex-1 px-3 py-2 border border-stone-300 rounded-lg text-sm uppercase" placeholder="Enter code" maxlength="50">
                    <button id="coupon-apply-btn" class="bg-stone-800 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-stone-900 transition-colors">Apply</button>
                </div>
                <div id="coupon-message" class="mt-2 text-sm hidden"></div>
            </div>
            <div id="coupon-applied" class="hidden bg-green-50 border border-green-200 rounded-lg p-3 mb-4">
                <div class="flex justify-between items-center">
                    <span class="text-green-800 font-medium" id="coupon-code-display"></span>
                    <button id="coupon-remove-btn" class="text-green-600 hover:text-green-800 text-sm font-medium">Remove</button>
                </div>
                <div class="text-green-700 text-sm mt-1" id="coupon-discount-display"></div>
            </div>
        `;
        
        // Insert before the Create Order button
        const createOrderBtn = totalSection.querySelector('#create-order-btn');
        if (createOrderBtn) {
            createOrderBtn.insertAdjacentHTML('beforebegin', couponHTML);
        }
    }
    
    setupEventListeners() {
        document.addEventListener('click', (e) => {
            if (e.target.id === 'coupon-apply-btn') {
                this.applyCoupon();
            }
            if (e.target.id === 'coupon-remove-btn') {
                this.removeCoupon();
            }
        });
        
        document.getElementById('coupon-input')?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.applyCoupon();
            }
        });
    }
    
    async applyCoupon() {
        const input = document.getElementById('coupon-input');
        const message = document.getElementById('coupon-message');
        const applyBtn = document.getElementById('coupon-apply-btn');
        
        const code = input.value.trim().toUpperCase();
        if (!code) {
            this.showMessage(message, 'Please enter a code', 'error');
            return;
        }
        
        applyBtn.disabled = true;
        applyBtn.textContent = 'Applying...';
        this.hideMessage(message);
        
        const subtotal = this.cartManager.getTotal();
        
        try {
            const response = await fetch('/api/validate-coupon.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code, subtotal })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.appliedCoupon = {
                    code: result.code,
                    discount: result.discount,
                    displayValue: result.displayValue,
                    newTotal: result.newTotal
                };
                this.originalSubtotal = subtotal;
                
                // Update UI
                document.getElementById('coupon-code-display').textContent = result.code;
                document.getElementById('coupon-discount-display').textContent = 
                    `-${result.displayValue} discount applied`;
                document.getElementById('cart-total').textContent = `$${result.newTotal.toFixed(2)}`;
                
                document.getElementById('coupon-applied').classList.remove('hidden');
                input.value = '';
                this.showMessage(message, result.message, 'success');
                
                // Store for order submission
                this.cartManager.appliedCoupon = this.appliedCoupon;
                
            } else {
                this.showMessage(message, result.message, 'error');
                this.appliedCoupon = null;
            }
        } catch (err) {
            this.showMessage(message, 'Network error. Please try again.', 'error');
        } finally {
            applyBtn.disabled = false;
            applyBtn.textContent = 'Apply';
        }
    }
    
    removeCoupon() {
        this.appliedCoupon = null;
        this.cartManager.appliedCoupon = null;
        
        document.getElementById('coupon-applied').classList.add('hidden');
        document.getElementById('cart-total').textContent = `$${this.cartManager.getTotal().toFixed(2)}`;
        
        const message = document.getElementById('coupon-message');
        this.showMessage(message, 'Coupon removed', 'success');
    }
    
    showMessage(element, text, type) {
        element.textContent = text;
        element.className = `mt-2 text-sm ${type === 'error' ? 'text-red-600' : 'text-green-600'}`;
        element.classList.remove('hidden');
        setTimeout(() => element.classList.add('hidden'), 5000);
    }
    
    hideMessage(element) {
        element.classList.add('hidden');
    }
    
    // Update order submission to include coupon
    updateOrderSubmission(originalCreateOrder) {
        const self = this;
        return function(orderDetails) {
            if (self.appliedCoupon) {
                orderDetails.coupon = {
                    code: self.appliedCoupon.code,
                    discount: self.appliedCoupon.discount,
                    originalSubtotal: self.originalSubtotal,
                    finalTotal: self.appliedCoupon.newTotal
                };
                // Adjust subtotal for backend processing
                orderDetails.subtotal = self.appliedCoupon.newTotal;
            }
            return originalCreateOrder.call(this, orderDetails);
        };
    }
}

// Initialize with existing cart manager
document.addEventListener('DOMContentLoaded', function() {
    if (window.cartManager) {
        window.couponManager = new CouponManager(window.cartManager);
        
        // Override createOrder to include coupon data
        if (window.cartManager.createOrder) {
            const original = window.cartManager.createOrder.bind(window.cartManager);
            window.cartManager.createOrder = function() {
                const orderDetails = {
                    orderNumber: this.generateOrderNumber(),
                    date: new Date().toLocaleString(),
                    items: this.items,
                    subtotal: this.getTotal()
                };
                
                // Apply coupon if present
                if (window.couponManager?.appliedCoupon) {
                    orderDetails.coupon = {
                        code: window.couponManager.appliedCoupon.code,
                        discount: window.couponManager.appliedCoupon.discount,
                        originalSubtotal: window.couponManager.originalSubtotal,
                        finalTotal: window.couponManager.appliedCoupon.newTotal
                    };
                    orderDetails.subtotal = window.couponManager.appliedCoupon.newTotal;
                }
                
                this.showOrderSubmissionForm(orderDetails);
            };
        }
    }
});