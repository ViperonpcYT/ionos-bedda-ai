// Bedda Website - Comprehensive User Behavior Logger
// Cost-effective client-side logging solution

class BeddaLogger {
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
        
        this.init();
    }

    init() {
        this.startSession();
        this.trackPageView();
        this.setupEventListeners();
        this.startTimers();
        this.trackPerformance();
        console.log('Bedda Logger initialized');
    }

    // Session Management
    generateSessionId() {
        return 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    getUserId() {
        let userId = localStorage.getItem('bedda_user_id');
        if (!userId) {
            userId = 'user_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            localStorage.setItem('bedda_user_id', userId);
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

    // Page View Tracking
    trackPageView() {
        this.logEvent('page_view', {
            page: this.currentPage,
            url: window.location.href,
            title: document.title,
            loadTime: performance.now(),
            timestamp: new Date().toISOString()
        });
    }

    // Time Tracking
    startTimers() {
        // Track time on page
        setInterval(() => {
            const timeOnPage = Date.now() - this.pageStartTime;
            this.timeData.push({
                page: this.currentPage,
                timeOnPage: timeOnPage,
                timestamp: new Date().toISOString()
            });
        }, 5000); // Log every 5 seconds

        // Track session duration
        setInterval(() => {
            this.logEvent('session_active', {
                sessionId: this.sessionId,
                duration: Date.now() - this.pageStartTime,
                timestamp: new Date().toISOString()
            });
        }, 30000); // Log every 30 seconds
    }

    // Click Tracking
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

        // Track specific interactions
        if (element.classList.contains('add-to-cart')) {
            this.trackAddToCart(element);
        } else if (element.id === 'cart-btn') {
            this.trackCartView();
        } else if (element.closest('.bundle-highlight')) {
            this.trackBundleClick();
        } else if (element.href && element.href.includes('products.html')) {
            this.trackProductNavigation();
        }
    }

    // Scroll Tracking
    trackScroll() {
        const scrollPercent = Math.round((window.scrollY / (document.body.scrollHeight - window.innerHeight)) * 100);
        const scrollInfo = {
            page: this.currentPage,
            scrollPercent: Math.min(scrollPercent, 100),
            scrollY: window.scrollY,
            timestamp: new Date().toISOString()
        };

        this.scrollData.push(scrollInfo);

        // Log scroll milestones
        if (scrollPercent === 25) this.logEvent('scroll_25', scrollInfo);
        if (scrollPercent === 50) this.logEvent('scroll_50', scrollInfo);
        if (scrollPercent === 75) this.logEvent('scroll_75', scrollInfo);
        if (scrollPercent === 90) this.logEvent('scroll_90', scrollInfo);
    }

    // E-commerce Tracking
    trackAddToCart(button) {
        const product = button.dataset.product;
        const price = button.dataset.price;
        
        this.logEvent('add_to_cart', {
            product: product,
            price: parseFloat(price),
            page: this.currentPage,
            timestamp: new Date().toISOString()
        });
    }

    trackCartView() {
        this.logEvent('cart_view', {
            page: this.currentPage,
            cartItems: this.getCartItems(),
            timestamp: new Date().toISOString()
        });
    }

    trackBundleClick() {
        this.logEvent('bundle_click', {
            page: this.currentPage,
            timestamp: new Date().toISOString()
        });
    }

    trackProductNavigation() {
        this.logEvent('product_navigation', {
            from: this.currentPage,
            to: 'products.html',
            timestamp: new Date().toISOString()
        });
    }

    getCartItems() {
        // This would integrate with your existing cart system
        if (window.beddaStore && window.beddaStore.cart) {
            return window.beddaStore.cart.map(item => ({
                name: item.name,
                price: item.price,
                quantity: item.quantity
            }));
        }
        return [];
    }

    // Performance Tracking
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

    // Error Tracking
    trackError(error, context = {}) {
        const errorInfo = {
            message: error.message,
            stack: error.stack,
            page: this.currentPage,
            userAgent: navigator.userAgent,
            url: window.location.href,
            context: context,
            timestamp: new Date().toISOString()
        };

        this.errorData.push(errorInfo);
        this.logEvent('error', errorInfo);
    }

    // User Behavior Tracking
    trackUserEngagement() {
        const engagement = {
            clicks: this.clickData.length,
            scrolls: this.scrollData.length,
            timeOnPage: Date.now() - this.pageStartTime,
            page: this.currentPage,
            timestamp: new Date().toISOString()
        };

        this.logEvent('user_engagement', engagement);
    }

    // Page Exit Tracking
    trackPageExit() {
        const exitData = {
            page: this.currentPage,
            timeOnPage: Date.now() - this.pageStartTime,
            scrollDepth: this.getMaxScrollDepth(),
            clicks: this.clickData.length,
            exitType: this.getExitType(),
            timestamp: new Date().toISOString()
        };

        this.logEvent('page_exit', exitData);
        this.saveData();
    }

    getMaxScrollDepth() {
        return this.scrollData.length > 0 ? 
            Math.max(...this.scrollData.map(s => s.scrollPercent)) : 0;
    }

    getExitType() {
        // Simple exit type detection
        if (this.clickData.length === 0) return 'bounce';
        if (this.getMaxScrollDepth() < 25) return 'early_exit';
        return 'normal_exit';
    }

    // Utility Functions
    getElementPath(element) {
        const path = [];
        let current = element;
        
        while (current && current !== document.body) {
            let selector = current.tagName.toLowerCase();
            if (current.id) {
                selector += `#${current.id}`;
            } else if (current.className) {
                selector += `.${current.className.split(' ')[0]}`;
            }
            path.unshift(selector);
            current = current.parentElement;
        }
        
        return path.join(' > ');
    }

    // Event Logging
    logEvent(eventType, data) {
        const event = {
            type: eventType,
            sessionId: this.sessionId,
            userId: this.userId,
            page: this.currentPage,
            timestamp: new Date().toISOString(),
            data: data
        };

        this.events.push(event);
        
        // Console logging for development
        if (window.location.hostname === 'localhost') {
            console.log('📊', eventType, data);
        }

        // Prevent memory overflow
        if (this.events.length > 1000) {
            this.events = this.events.slice(-500);
        }
    }

    // Data Storage
    saveData() {
        const dataToSave = {
            sessionId: this.sessionId,
            userId: this.userId,
            events: this.events,
            summary: {
                totalEvents: this.events.length,
                totalClicks: this.clickData.length,
                totalScrolls: this.scrollData.length,
                totalErrors: this.errorData.length,
                sessionDuration: Date.now() - this.pageStartTime,
                maxScrollDepth: this.getMaxScrollDepth()
            },
            timestamp: new Date().toISOString()
        };

        try {
            localStorage.setItem('bedda_analytics', JSON.stringify(dataToSave));
        } catch (e) {
            console.warn('Failed to save analytics data:', e);
        }
    }

    getAnalyticsData() {
        try {
            return JSON.parse(localStorage.getItem('bedda_analytics') || '{}');
        } catch (e) {
            return {};
        }
    }

    // Event Listeners Setup
    setupEventListeners() {
        // Click tracking
        document.addEventListener('click', (e) => {
            this.trackClick(e.target, e);
        });

        // Scroll tracking (throttled)
        let scrollTimeout;
        window.addEventListener('scroll', () => {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => {
                this.trackScroll();
            }, 100);
        });

        // Page exit tracking
        window.addEventListener('beforeunload', () => {
            this.trackPageExit();
        });

        // Visibility change (tab switching)
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.isActive = false;
                this.logEvent('tab_hidden', {
                    page: this.currentPage,
                    timestamp: new Date().toISOString()
                });
            } else {
                this.isActive = true;
                this.logEvent('tab_visible', {
                    page: this.currentPage,
                    timestamp: new Date().toISOString()
                });
            }
        });

        // Error tracking
        window.addEventListener('error', (e) => {
            this.trackError(new Error(e.message), {
                filename: e.filename,
                lineno: e.lineno,
                colno: e.colno
            });
        });

        // Unhandled promise rejections
        window.addEventListener('unhandledrejection', (e) => {
            this.trackError(new Error(e.reason), {
                type: 'unhandled_promise_rejection'
            });
        });

        // Track user engagement periodically
        setInterval(() => {
            if (this.isActive) {
                this.trackUserEngagement();
            }
        }, 60000); // Every minute
    }

    // Export Functions
    exportData(format = 'json') {
        const data = this.getAnalyticsData();
        
        if (format === 'json') {
            return JSON.stringify(data, null, 2);
        } else if (format === 'csv') {
            return this.convertToCSV(data.events || []);
        }
        
        return data;
    }

    convertToCSV(events) {
        if (events.length === 0) return '';
        
        const headers = ['timestamp', 'event_type', 'page', 'user_id', 'session_id', 'data'];
        const csvContent = [
            headers.join(','),
            ...events.map(event => [
                event.timestamp,
                event.type,
                event.page,
                event.userId,
                event.sessionId,
                JSON.stringify(event.data).replace(/"/g, '""')
            ].join(','))
        ].join('\n');
        
        return csvContent;
    }

    // Clear data
    clearData() {
        localStorage.removeItem('bedda_analytics');
        localStorage.removeItem('bedda_user_id');
        this.events = [];
        this.clickData = [];
        this.scrollData = [];
        this.errorData = [];
        console.log('Analytics data cleared');
    }
}

// Initialize logger when DOM is ready
let beddaLogger;

if (typeof window !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        beddaLogger = new BeddaLogger();
        
        // Make logger available globally for debugging
        window.beddaLogger = beddaLogger;
        
        // Auto-save data every 30 seconds
        setInterval(() => {
            beddaLogger.saveData();
        }, 30000);
    });
}

// Export for module use
if (typeof module !== 'undefined' && module.exports) {
    module.exports = BeddaLogger;
}