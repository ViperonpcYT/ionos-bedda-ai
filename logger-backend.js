class OnlyBikesLogger {
    constructor() {
        this.sessionId = this.generateSessionId();
        this.userId = this.getUserId();
        this.events = [];
        this.pageStartTime = Date.now();
        this.currentPage = window.location.pathname;
        this.isActive = true;
        this.scrollData = [];
        this.clickData = [];
        this.timeData = [];
        this.errorData = [];
        this.isOnline = navigator.onLine;
        this.offlineQueue = [];
        this.batchQueue = [];           // NEW: batched events queue
        this.batchFlushInterval = 5000;   // NEW: flush every 5 seconds (was 10s)
        this.batchMaxSize = 30;         // NEW: or when queue reaches 30 (was 20)
        this.apiEndpoint = '/api/log-event.php';
        this.isFlushing = false;
        
        this.init();
    }

    init() {
        this.startSession();
        this.trackPageView();
        this.setupEventListeners();
        this.startTimers();
        this.trackPerformance();
        this.setupOfflineHandling();
        console.log('OnlyBikes IONOS Logger initialized');
    }

    setupOfflineHandling() {
        window.addEventListener('online', () => {
            this.isOnline = true;
            this.flushOfflineQueue();
        });
        window.addEventListener('offline', () => {
            this.isOnline = false;
        });
        // Try to flush offline queue every 60s (was 30s)
        setInterval(() => {
            if (this.isOnline && this.offlineQueue.length > 0) {
                this.flushOfflineQueue();
            }
        }, 60000);
    }

    generateSessionId() {
        return 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    getUserId() {
        let userId = localStorage.getItem('onlybikes_user_id');
        if (!userId) {
            userId = 'user_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            localStorage.setItem('onlybikes_user_id', userId);
        }
        return userId;
    }

    startSession() {
        this.logEvent('session_start', {
            sessionId: this.sessionId,
            userId: this.userId,
            userAgent: navigator.userAgent,
            screenResolution: `${screen.width}x${screen.height}`,
            viewport: `${window.innerWidth}x${window.innerHeight}`,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            referrer: document.referrer,
            timestamp: new Date().toISOString()
        });
    }

    trackPageView() {
        this.logEvent('page_view', {
            page: this.currentPage,
            url: window.location.href,
            title: document.title,
            loadTime: performance.now(),
            timestamp: new Date().toISOString()
        });
    }

    startTimers() {
        // Session heartbeat: reduced from 30s to 60s
        setInterval(() => {
            const timeOnPage = Date.now() - this.pageStartTime;
            this.timeData.push({ page: this.currentPage, timeOnPage, timestamp: new Date().toISOString() });
            this.logEvent('session_active', { sessionId: this.sessionId, duration: timeOnPage, timestamp: new Date().toISOString() });
        }, 60000);

        // Batch flush: send queued events every 5 seconds
        setInterval(() => {
            this.flushBatch();
        }, this.batchFlushInterval);
    }

    trackClick(element, event) {
        const clickInfo = {
            element: element.tagName.toLowerCase(),
            className: element.className || null,
            id: element.id || null,
            text: element.textContent?.substring(0, 50) || null,
            href: element.href || null,
            x: event.clientX,
            y: event.clientY,
            page: this.currentPage,
            timestamp: new Date().toISOString(),
            elementPath: this.getElementPath(element)
        };
        this.clickData.push(clickInfo);
        this.logEvent('click', clickInfo);
        if (element.classList.contains('add-to-cart')) this.trackAddToCart(element);
        else if (element.id === 'cart-btn') this.trackCartView();
        else if (element.closest('.bundle-highlight')) this.trackBundleClick();
        else if (element.href && element.href.includes('products.html')) this.trackProductNavigation();
    }

    trackScroll() {
        const scrollPercent = Math.round((window.scrollY / (document.body.scrollHeight - window.innerHeight)) * 100);
        const scrollInfo = { page: this.currentPage, scrollPercent: Math.min(scrollPercent, 100), scrollY: window.scrollY, timestamp: new Date().toISOString() };
        this.scrollData.push(scrollInfo);
        if (scrollPercent === 25) this.logEvent('scroll_25', scrollInfo);
        if (scrollPercent === 50) this.logEvent('scroll_50', scrollInfo);
        if (scrollPercent === 75) this.logEvent('scroll_75', scrollInfo);
        if (scrollPercent === 90) this.logEvent('scroll_90', scrollInfo);
    }

    trackAddToCart(button) {
        this.logEvent('add_to_cart', {
            product: button.dataset.product,
            price: parseFloat(button.dataset.price),
            page: this.currentPage,
            timestamp: new Date().toISOString()
        });
    }

    trackCartView() {
        this.logEvent('cart_view', { page: this.currentPage, cartItems: this.getCartItems(), timestamp: new Date().toISOString() });
    }

    trackBundleClick() {
        this.logEvent('bundle_click', { page: this.currentPage, timestamp: new Date().toISOString() });
    }

    trackProductNavigation() {
        this.logEvent('product_navigation', { from: this.currentPage, to: 'products.html', timestamp: new Date().toISOString() });
    }

    getCartItems() {
        if (window.onlyBikesStore && window.onlyBikesStore.cart) {
            return window.onlyBikesStore.cart.map(item => ({ name: item.name, price: item.price, quantity: item.quantity }));
        }
        return [];
    }

    trackPerformance() {
        window.addEventListener('load', () => {
            setTimeout(() => {
                const perfData = performance.getEntriesByType('navigation')[0];
                this.logEvent('performance', {
                    page: this.currentPage,
                    loadTime: perfData.loadEventEnd - perfData.loadEventStart,
                    domContentLoaded: perfData.domContentLoadedEventEnd - perfData.domContentLoadedEventStart,
                    totalTime: perfData.loadEventEnd - perfData.fetchStart,
                    timestamp: new Date().toISOString()
                });
            }, 0);
        });
    }

    trackError(error, context = {}) {
        const errorInfo = { message: error.message, stack: error.stack, page: this.currentPage, userAgent: navigator.userAgent, url: window.location.href, context, timestamp: new Date().toISOString() };
        this.errorData.push(errorInfo);
        this.logEvent('error', errorInfo);
    }

    async logEvent(eventType, data) {
        const event = { 
            event_type: eventType, 
            session_id: this.sessionId, 
            user_id: this.userId, 
            page: this.currentPage, 
            timestamp: new Date().toISOString(), 
            data: data || {} 
        };
        this.events.push(event);
        if (window.location.hostname === 'localhost') console.log('📊', eventType, data);

        this.batchQueue.push(event);

        // High-priority events flush immediately (include the event above)
        if (eventType === 'page_exit' || eventType === 'session_start') {
            this.flushBatch(true);
            return;
        }
        
        // Auto-flush if queue gets large
        if (this.batchQueue.length >= this.batchMaxSize) {
            this.flushBatch();
        }
        
        if (this.events.length > 1000) this.events = this.events.slice(-500);
    }

    async flushBatch(useBeacon = false) {
        if (this.batchQueue.length === 0) return;
        if (this.isFlushing) return; // Prevent concurrent flushes
        
        const eventsToSend = [...this.batchQueue];
        this.batchQueue = [];
        
        if (!this.isOnline) {
            this.offlineQueue.push(...eventsToSend);
            return;
        }
        
        this.isFlushing = true;
        this._lastFlushWarnAt = this._lastFlushWarnAt || 0;
        
        try {
            if (useBeacon && navigator.sendBeacon) {
                // Use sendBeacon for page-exit / unload scenarios
                const payload = { events: eventsToSend };
                navigator.sendBeacon(this.apiEndpoint, new Blob([JSON.stringify(payload)], { type: 'application/json' }));
            } else {
                // Normal batch POST
                const response = await fetch(this.apiEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ events: eventsToSend })
                });
                if (!response.ok) {
                    const hint = await response.text().catch(() => '');
                    const preview = hint.startsWith('<!DOCTYPE') ? 'HTML error page (check api/log-event.php on Ionos)' : hint.slice(0, 120);
                    throw new Error(`HTTP ${response.status}${preview ? ': ' + preview : ''}`);
                }
            }
        } catch (error) {
            // On failure, put events back in offline queue
            this.offlineQueue.push(...eventsToSend);
            const now = Date.now();
            if (now - this._lastFlushWarnAt > 60000) {
                this._lastFlushWarnAt = now;
                console.warn('Batch flush failed (events queued). Network tab: POST /api/log-event.php. Server: Ionos PHP log or api/logs/', error.message);
            }
        } finally {
            this.isFlushing = false;
        }
    }

    async flushOfflineQueue() {
        if (this.offlineQueue.length === 0) return;
        console.log(`Flushing ${this.offlineQueue.length} offline events...`);
        const eventsToSend = [...this.offlineQueue];
        this.offlineQueue = [];
        
        try {
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ events: eventsToSend })
            });
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        } catch (error) {
            this.offlineQueue.push(...eventsToSend);
            console.error('Failed to flush offline events:', error);
        }
    }

    setupEventListeners() {
        document.addEventListener('click', (e) => this.trackClick(e.target, e));
        let scrollTimeout;
        window.addEventListener('scroll', () => {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => this.trackScroll(), 100);
        });
        window.addEventListener('beforeunload', () => this.trackPageExit());
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.isActive = false;
                this.logEvent('tab_hidden', { page: this.currentPage, timestamp: new Date().toISOString() });
            } else {
                this.isActive = true;
                this.logEvent('tab_visible', { page: this.currentPage, timestamp: new Date().toISOString() });
            }
        });
        window.addEventListener('error', (e) => this.trackError(new Error(e.message), { filename: e.filename, lineno: e.lineno, colno: e.colno }));
        window.addEventListener('unhandledrejection', (e) => this.trackError(new Error(e.reason), { type: 'unhandled_promise_rejection' }));
        setInterval(() => { if (this.isActive) this.trackUserEngagement(); }, 120000);
    }

    // FIXED: Handle SVG elements where className is SVGAnimatedString
    getElementPath(element) {
        const path = [];
        let current = element;
        
        while (current && current !== document.body) {
            let selector = current.tagName.toLowerCase();
            if (current.id) {
                selector += `#${current.id}`;
            } else if (current.className) {
                // Handle SVG elements where className is an SVGAnimatedString
                let classString = '';
                if (typeof current.className === 'string') {
                    classString = current.className;
                } else if (current.className && current.className.baseVal) {
                    classString = current.className.baseVal;
                }
                if (classString) {
                    const firstClass = classString.split(' ')[0];
                    if (firstClass) {
                        selector += `.${firstClass}`;
                    }
                }
            }
            path.unshift(selector);
            current = current.parentElement;
        }
        
        return path.join(' > ');
    }

    trackUserEngagement() {
        const engagement = { clicks: this.clickData.length, scrolls: this.scrollData.length, timeOnPage: Date.now() - this.pageStartTime, page: this.currentPage, timestamp: new Date().toISOString() };
        this.logEvent('user_engagement', engagement);
    }

    trackPageExit() {
        const exitData = { page: this.currentPage, timeOnPage: Date.now() - this.pageStartTime, scrollDepth: this.getMaxScrollDepth(), clicks: this.clickData.length, exitType: this.getExitType(), timestamp: new Date().toISOString() };
        this.logEvent('page_exit', exitData);
        this.flushOfflineQueue();
    }

    getMaxScrollDepth() {
        return this.scrollData.length > 0 ? Math.max(...this.scrollData.map(s => s.scrollPercent)) : 0;
    }

    getExitType() {
        if (this.clickData.length === 0) return 'bounce';
        if (this.getMaxScrollDepth() < 25) return 'early_exit';
        return 'normal_exit';
    }
}

let onlyBikesLogger;
if (typeof window !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        onlyBikesLogger = new OnlyBikesLogger();
        window.onlyBikesLogger = onlyBikesLogger;
    });
}