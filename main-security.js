/**
 * OnlyBikes Store - Client-Side Security Enhancements
 * Form protection, rate limiting, CAPTCHA integration, and security monitoring
 * Note: Server-side validation is REQUIRED - this is defense in depth only
 */

class OnlyBikesSecurity {
    constructor() {
        this.formStartTime = null;
        this.captchaVerified = false;
        this.captchaSiteKey = 'da985e3c-21e0-4b18-a476-066e8692a3a6'; // Replace with your hCaptcha site key
        this.rateLimitStatus = null;
        this.init();
    }

    init() {
        this.setupFormSecurity();
        this.setupRateLimitChecking();
        this.setupInputValidation();
        this.setupMonitoring();
        this.addFormTimestamp();
    }

    // =========================
    // FORM SECURITY
    // =========================
    setupFormSecurity() {
        this.attachFormSecurity();
        // Form is created dynamically by main.js — watch for it
        if (!document.getElementById('order-submission-form')) {
            const observer = new MutationObserver(() => {
                if (document.getElementById('order-submission-form')) {
                    this.attachFormSecurity();
                    observer.disconnect();
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    }

    attachFormSecurity() {
        const orderForm = document.getElementById('order-submission-form');
        if (!orderForm || orderForm.dataset.securityAttached) return;
        orderForm.dataset.securityAttached = 'true';

        // Add honeypot if main.js hasn't already
        if (!orderForm.querySelector('#website-field')) {
            const honeypot = document.createElement('div');
            honeypot.style.cssText = 'position:absolute;left:-9999px;opacity:0;height:0;width:0;overflow:hidden;';
            honeypot.setAttribute('aria-hidden', 'true');
            honeypot.innerHTML = '<label for="website-field">Leave this empty</label><input type="text" name="website" id="website-field" tabindex="-1" autocomplete="off" value="">';
            orderForm.insertBefore(honeypot, orderForm.firstChild);
        }

        // Timing checks
        orderForm.querySelectorAll('input, textarea, select').forEach(input => {
            input.addEventListener('focus', () => {
                if (!this.formStartTime) this.formStartTime = Date.now();
            });
        });
    }

    addHoneypotField() {
        // Create hidden honeypot field (bots will fill this, humans won't see it)
        const honeypot = document.createElement('div');
        honeypot.style.cssText = 'position: absolute; left: -9999px; opacity: 0; height: 0; width: 0; overflow: hidden;';
        honeypot.setAttribute('aria-hidden', 'true');
        honeypot.innerHTML = `
            <label for="website-field">Leave this empty</label>
            <input type="text" name="website" id="website-field" tabindex="-1" autocomplete="off" value="">
        `;
        
        // Add to order form
        const orderForm = document.getElementById('order-form');
        if (orderForm && !orderForm.querySelector('#website-field')) {
            orderForm.insertBefore(honeypot, orderForm.firstChild);
        }
    }

    addTimingChecks() {
        // Record when forms are focused
        const orderForm = document.getElementById('order-form');
        if (orderForm) {
            orderForm.querySelectorAll('input, textarea, select').forEach(input => {
                input.addEventListener('focus', () => {
                    if (!this.formStartTime) {
                        this.formStartTime = Date.now();
                    }
                });
            });
        }
    }

    addFormTimestamp() {
        // Add timestamp to forms for server-side timing check
        const orderForm = document.getElementById('order-form');
        if (orderForm && !orderForm.querySelector('input[name="form_timestamp"]')) {
            const timestamp = document.createElement('input');
            timestamp.type = 'hidden';
            timestamp.name = 'form_timestamp';
            timestamp.value = Math.floor(Date.now() / 1000); // Unix timestamp
            orderForm.appendChild(timestamp);
        }
    }

    enhanceEmailValidation() {
        // RFC 5322 compliant email regex (simplified)
        const emailRegex = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;
        
        document.querySelectorAll('input[type="email"]').forEach(emailInput => {
            emailInput.addEventListener('blur', (e) => {
                if (e.target.value && !emailRegex.test(e.target.value)) {
                    e.target.setCustomValidity('Please enter a valid email address');
                    e.target.classList.add('border-red-500');
                } else {
                    e.target.setCustomValidity('');
                    e.target.classList.remove('border-red-500');
                }
            });
            
            emailInput.addEventListener('input', (e) => {
                e.target.setCustomValidity('');
                e.target.classList.remove('border-red-500');
            });
        });
    }

    // =========================
    // RATE LIMIT CHECKING
    // =========================
    setupRateLimitChecking() {
        // Check rate limit when email is entered
        const emailInput = document.getElementById('customer-email');
        if (emailInput) {
            emailInput.addEventListener('blur', () => {
                this.checkRateLimit();
            });
        }
    }

    async checkRateLimit() {
        const email = document.getElementById('customer-email')?.value;
        const phone = document.getElementById('phone-number')?.value;
        
        if (!email) return;
        
        try {
            const response = await fetch('/api/check-rate-limit.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ email, phone })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.rateLimitStatus = result.data;
                
                // Show CAPTCHA if required
                if (result.data.requiresCaptcha && !this.captchaVerified) {
                    this.showCaptcha();
                }
                
                // Show warning if approaching limit
                if (result.data.status?.email_remaining <= 1) {
                    this.showWarning('You are approaching the order limit for this email address.');
                }
            }
        } catch (error) {
            console.error('Rate limit check failed:', error);
        }
    }

    // =========================
    // CAPTCHA INTEGRATION
    // =========================
    showCaptcha() {
        // Check if CAPTCHA container already exists
        if (document.getElementById('captcha-container')) return;
        
        // Create CAPTCHA container
        const container = document.createElement('div');
        container.id = 'captcha-container';
        container.className = 'mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg';
        container.innerHTML = `
            <p class="text-sm text-yellow-800 mb-2">Please verify you're human:</p>
            <div id="hcaptcha-widget"></div>
            <p id="captcha-error" class="text-red-500 text-sm mt-2 hidden">Please complete the CAPTCHA.</p>
        `;
        
        // Insert before submit button
        const submitBtn = document.getElementById('submit-order-btn');
        if (submitBtn) {
            submitBtn.parentNode.insertBefore(container, submitBtn);
        }
        
        // Load hCaptcha script if not already loaded
        if (!window.hcaptcha) {
            const script = document.createElement('script');
            script.src = 'https://js.hcaptcha.com/1/api.js?onload=onHCaptchaLoad&render=explicit';
            script.async = true;
            script.defer = true;
            document.head.appendChild(script);
            
            // Define callback for when CAPTCHA loads
            window.onHCaptchaLoad = () => {
                this.renderCaptcha();
            };
        } else {
            this.renderCaptcha();
        }
    }

    renderCaptcha() {
        if (window.hcaptcha && document.getElementById('hcaptcha-widget')) {
            window.hcaptcha.render('hcaptcha-widget', {
                sitekey: this.captchaSiteKey,
                callback: (token) => {
                    this.onCaptchaSuccess(token);
                },
                'error-callback': () => {
                    this.onCaptchaError();
                }
            });
        }
    }

    async onCaptchaSuccess(token) {
        try {
            const response = await fetch('/api/verify-captcha.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ captchaToken: token })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.captchaVerified = true;
                document.getElementById('captcha-error')?.classList.add('hidden');
                this.showSuccess('Verification complete! You can now submit your order.');
            } else {
                this.onCaptchaError();
            }
        } catch (error) {
            console.error('CAPTCHA verification failed:', error);
            this.onCaptchaError();
        }
    }

    onCaptchaError() {
        this.captchaVerified = false;
        document.getElementById('captcha-error')?.classList.remove('hidden');
        
        // Reset CAPTCHA
        if (window.hcaptcha) {
            window.hcaptcha.reset();
        }
    }

    // =========================
    // ORDER FORM SUBMISSION
    // =========================
    setupOrderFormSubmission() {
        const orderForm = document.getElementById('order-submission-form');
        if (!orderForm) return;
        
        orderForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            // Check honeypot
            const honeypot = orderForm.querySelector('#website-field');
            if (honeypot && honeypot.value) {
                this.logSuspiciousActivity('Honeypot triggered', { value: honeypot.value });
                return false;
            }
            
            // Check timing
            if (this.formStartTime) {
                const elapsed = (Date.now() - this.formStartTime) / 1000;
                if (elapsed < 5) {
                    this.showError('Please take your time filling out the form.');
                    return false;
                }
            }
            
            // Check CAPTCHA if required
            if (this.rateLimitStatus?.requiresCaptcha && !this.captchaVerified) {
                this.showError('Please complete the CAPTCHA verification.');
                this.showCaptcha();
                return false;
            }
            
            // Gather form data
            const formData = this.gatherOrderData();
            
            // Submit order
            await this.submitOrder(formData);
        });
    }

    gatherOrderData() {
        const items = [];
        document.querySelectorAll('.cart-item').forEach(item => {
            items.push({
                product: item.dataset.product,
                price: parseFloat(item.dataset.price),
                quantity: parseInt(item.dataset.quantity)
            });
        });
        
        return {
            customerName: document.getElementById('customer-name')?.value,
            customerEmail: document.getElementById('customer-email')?.value,
            phoneNumber: document.getElementById('phone-number')?.value,
            streetAddress: document.getElementById('street-address')?.value,
            address2: document.getElementById('address-2')?.value,
            city: document.getElementById('city')?.value,
            province: document.getElementById('province')?.value,
            postalCode: document.getElementById('postal-code')?.value,
            newsletter: document.getElementById('newsletter')?.checked,
            items: items,
            form_timestamp: Math.floor(Date.now() / 1000)
        };
    }

    async submitOrder(orderData) {
        const submitBtn = document.getElementById('submit-order-btn');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';
        }
        
        try {
            const response = await fetch('/api/submit-order.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(orderData)
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showSuccess('Order submitted successfully! Order #: ' + result.data.orderNumber);
                
                // Clear cart
                if (typeof cartManager !== 'undefined') {
                    cartManager.clearCart();
                }
                
                // Reset form
                document.getElementById('order-form')?.reset();
                this.formStartTime = null;
                this.captchaVerified = false;
                
                // Remove CAPTCHA if present
                const captchaContainer = document.getElementById('captcha-container');
                if (captchaContainer) {
                    captchaContainer.remove();
                }
            } else {
                // Check if CAPTCHA is required
                if (result.data?.requiresCaptcha) {
                    this.rateLimitStatus = result.data;
                    this.showCaptcha();
                    this.showError('Please complete the verification to continue.');
                } else {
                    this.showError(result.message || 'Failed to submit order. Please try again.');
                }
            }
        } catch (error) {
            console.error('Order submission failed:', error);
            this.showError('Network error. Please check your connection and try again.');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit Order';
            }
        }
    }

    // =========================
    // INPUT VALIDATION
    // =========================
    setupInputValidation() {
        this.setupXSSProtection();
        this.validateInputs();
    }

    setupXSSProtection() {
        // Basic XSS pattern detection
        const dangerousPatterns = [
            /<script/i,
            /javascript:/i,
            /on\w+\s*=/i,
            /data:text\/html/i,
            /vbscript:/i,
            /expression\s*\(/i
        ];
        
        document.addEventListener('input', (e) => {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                const value = e.target.value;
                dangerousPatterns.forEach(pattern => {
                    if (pattern.test(value)) {
                        e.target.value = value.replace(pattern, '');
                        this.logSuspiciousActivity('XSS attempt detected', { 
                            pattern: pattern.toString(), 
                            field: e.target.name 
                        });
                        this.showWarning('Potentially dangerous content removed.');
                    }
                });
            }
        });
    }

    validateInputs() {
        // Enhanced input validation patterns
        const validators = {
            'customer-name': /^[a-zA-Z\s\-'\.]{2,50}$/,
            'phone-number': /^[\d\s\-\(\)\+]{10,20}$/,
            'street-address': /^[\w\s\-\.,#\/'()]{5,200}$/,
            'city': /^[a-zA-Z\s\-]{2,50}$/,
            'postal-code': /^[A-Za-z]\d[A-Za-z][\s-]?\d[A-Za-z]\d$/
        };
        
        Object.keys(validators).forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('blur', (e) => {
                    if (e.target.value && !validators[id].test(e.target.value)) {
                        e.target.classList.add('border-red-500');
                        e.target.setCustomValidity('Invalid format');
                    } else {
                        e.target.classList.remove('border-red-500');
                        e.target.setCustomValidity('');
                    }
                });
            }
        });
    }

    // =========================
    // MONITORING
    // =========================
    setupMonitoring() {
        this.setupErrorMonitoring();
        this.setupSuspiciousActivityMonitoring();
    }

    setupErrorMonitoring() {
        window.addEventListener('error', (e) => {
            this.logSecurityEvent('JavaScript Error', {
                message: e.message,
                filename: e.filename,
                lineno: e.lineno
            });
        });
    }

    setupSuspiciousActivityMonitoring() {
        // Lightweight bot detection via form timing checks only (no mousemove/keystroke tracking)
        // Previous implementation tracked every mouse movement and keystroke which caused severe lag.
        // Server-side rate limiting in checkRateLimit handles bot protection.
    }

    // =========================
    // UTILITY FUNCTIONS
    // =========================
    showSuccess(message) {
        this.showNotification(message, 'bg-green-500');
    }

    showError(message) {
        this.showNotification(message, 'bg-red-500');
    }

    showWarning(message) {
        this.showNotification(message, 'bg-yellow-500');
    }

    showNotification(message, bgColor) {
        const notification = document.createElement('div');
        notification.className = `fixed top-20 right-4 ${bgColor} text-white px-4 py-2 rounded-lg shadow-lg z-50`;
        notification.style.cssText = 'animation: fadeIn 0.3s ease;';
        notification.textContent = message;
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.style.opacity = '0';
            notification.style.transition = 'opacity 0.5s ease';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 500);
        }, 5000);
    }

    logSuspiciousActivity(type, data = {}) {
        console.warn('Security Alert:', type, data);
        
        // Store in session for potential reporting
        const alerts = JSON.parse(sessionStorage.getItem('security_alerts') || '[]');
        alerts.push({
            type,
            data,
            timestamp: new Date().toISOString(),
            url: window.location.href
        });
        
        // Keep only last 50 alerts
        if (alerts.length > 50) alerts.shift();
        sessionStorage.setItem('security_alerts', JSON.stringify(alerts));
    }

    logSecurityEvent(type, data) {
        console.log('Security Event:', type, data);
    }
}

// =========================
// INITIALIZE SECURITY
// =========================
let onlyBikesSecurity;

document.addEventListener('DOMContentLoaded', () => {
    onlyBikesSecurity = new OnlyBikesSecurity();
    
    // Make available globally for debugging
    window.onlyBikesSecurity = onlyBikesSecurity;
});

// =========================
// EXPORT FOR MODULE USE
// =========================
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { OnlyBikesSecurity };
}
