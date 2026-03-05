/**
 * Vitalytics Auto-Tracker v2.1
 *
 * TRUE auto-tracking with PHI-safe mode for healthcare applications.
 * No HTML modifications needed. Just include this script.
 *
 * Automatically tracks:
 *   - ALL button clicks
 *   - ALL link clicks
 *   - ALL form submissions
 *   - ALL page views (including SPA/Livewire navigation)
 *
 * PHI-Safe Mode (enabled via meta tag or config):
 *   - Never captures text content from elements
 *   - Uses only id, name, class for element identification
 *   - Safe for HIPAA-compliant healthcare applications
 *
 * Optional data attributes:
 *   - data-vitalytics-click="custom-name"     Override element identifier
 *   - data-vitalytics-label="Friendly Name"   Explicit label (only used if set)
 *   - data-vitalytics-feature="feature-name"  Mark as feature usage
 *   - data-vitalytics-ignore                  Exclude element from tracking
 */
(function() {
    'use strict';

    // Configuration
    var TRACK_ENDPOINT = '/vitalytics/track';
    var DEBUG = false;
    var PHI_SAFE = false;

    // Check for PHI-safe mode via meta tag
    var phiSafeMeta = document.querySelector('meta[name="vitalytics-phi-safe"]');
    if (phiSafeMeta) {
        PHI_SAFE = phiSafeMeta.content === 'true' || phiSafeMeta.content === '1';
    }

    // Get CSRF token for Laravel
    var csrfToken = null;
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        csrfToken = csrfMeta.content;
    }

    // Current screen context (auto-detected from URL)
    var currentScreen = getScreenFromUrl();

    /**
     * Debug logging
     */
    function log() {
        if (DEBUG && console && console.log) {
            console.log.apply(console, ['[Vitalytics]'].concat(Array.prototype.slice.call(arguments)));
        }
    }

    /**
     * Get screen name from current URL
     */
    function getScreenFromUrl() {
        var path = window.location.pathname;
        // Remove leading slash and convert to readable format
        var screen = path.replace(/^\//, '') || 'home';
        // Convert /users/123/edit to users-edit (strip numeric IDs for privacy)
        screen = screen.replace(/\/\d+\//g, '-').replace(/\/\d+$/, '');
        screen = screen.replace(/\//g, '-');
        return screen;
    }

    /**
     * Generate element identifier from element
     * In PHI-safe mode, never uses text content
     */
    function getElementIdentifier(el) {
        // Priority 1: Explicit data attribute (always safe - developer controlled)
        if (el.dataset.vitalyticsClick) {
            return el.dataset.vitalyticsClick;
        }

        // Priority 2: id attribute (safe - developer controlled)
        if (el.id) {
            return el.id;
        }

        // Priority 3: name attribute (safe - developer controlled)
        if (el.name) {
            return el.name;
        }

        // Priority 4: Text content - SKIP in PHI-safe mode
        if (!PHI_SAFE) {
            var text = (el.textContent || el.innerText || '').trim();
            if (text && text.length <= 50) {
                return text.toLowerCase()
                    .replace(/[^a-z0-9\s]/g, '')
                    .replace(/\s+/g, '-')
                    .substring(0, 30);
            }
        }

        // Priority 5: aria-label - SKIP in PHI-safe mode (may contain PHI)
        if (!PHI_SAFE && el.getAttribute('aria-label')) {
            return el.getAttribute('aria-label').toLowerCase().replace(/\s+/g, '-').substring(0, 30);
        }

        // Priority 6: title - SKIP in PHI-safe mode (may contain PHI)
        if (!PHI_SAFE && el.title) {
            return el.title.toLowerCase().replace(/\s+/g, '-').substring(0, 30);
        }

        // Priority 7: Class-based identifier (safe - developer controlled)
        var classes = el.className;
        if (typeof classes === 'string' && classes) {
            // Extract meaningful class names (skip utility classes)
            var meaningfulClass = classes.split(' ').find(function(c) {
                return c.length > 2 && !c.match(/^(p-|m-|w-|h-|text-|bg-|flex|grid|block|inline|hidden|hover:|focus:|active:|disabled:|sm:|md:|lg:|xl:|2xl:)/);
            });
            if (meaningfulClass) {
                return meaningfulClass;
            }
        }

        // Priority 8: Tag + position fallback
        var tag = el.tagName.toLowerCase();
        var parent = el.parentElement;
        if (parent) {
            var siblings = Array.prototype.slice.call(parent.children).filter(function(c) {
                return c.tagName === el.tagName;
            });
            var index = siblings.indexOf(el);
            if (siblings.length > 1) {
                return tag + '-' + (index + 1);
            }
        }

        return tag;
    }

    /**
     * Get friendly label for an element
     * In PHI-safe mode, only returns explicit data-vitalytics-label
     */
    function getElementLabel(el) {
        // Explicit label attribute - always safe (developer controlled)
        if (el.dataset.vitalyticsLabel) {
            return el.dataset.vitalyticsLabel;
        }

        // In PHI-safe mode, never derive labels from content
        if (PHI_SAFE) {
            return null;
        }

        // Use text content as natural label
        var text = (el.textContent || el.innerText || '').trim();
        if (text && text.length <= 50) {
            return text;
        }

        // aria-label
        if (el.getAttribute('aria-label')) {
            return el.getAttribute('aria-label');
        }

        // title
        if (el.title) {
            return el.title;
        }

        return null;
    }

    /**
     * Check if element should be tracked
     */
    function shouldTrackElement(el) {
        // Explicit ignore
        if (el.dataset.vitalyticsIgnore !== undefined) {
            return false;
        }

        // Skip invisible elements
        if (el.offsetParent === null && el.tagName !== 'BODY') {
            return false;
        }

        return true;
    }

    /**
     * Check if element is interactive (button, link, etc.)
     */
    function isInteractiveElement(el) {
        var tag = el.tagName.toLowerCase();

        // Buttons
        if (tag === 'button') return true;
        if (tag === 'input' && ['button', 'submit', 'reset'].includes(el.type)) return true;

        // Links with href
        if (tag === 'a' && el.href) return true;

        // Elements with click handlers or roles
        if (el.getAttribute('role') === 'button') return true;
        if (el.getAttribute('onclick')) return true;
        if (el.getAttribute('wire:click')) return true;
        if (el.getAttribute('x-on:click') || el.getAttribute('@click')) return true;
        if (el.getAttribute('v-on:click')) return true;

        // Clickable by cursor style (check computed style)
        try {
            var style = window.getComputedStyle(el);
            if (style.cursor === 'pointer') return true;
        } catch (e) {}

        return false;
    }

    /**
     * Get form identifier
     */
    function getFormIdentifier(form) {
        if (form.dataset.vitalyticsForm) {
            return form.dataset.vitalyticsForm;
        }
        if (form.id) {
            return form.id;
        }
        if (form.name) {
            return form.name;
        }
        // Use action path (strip query params for privacy)
        if (form.action) {
            try {
                var url = new URL(form.action, window.location.origin);
                var path = url.pathname.replace(/^\//, '').replace(/\//g, '-');
                return 'form-' + (path || 'submit');
            } catch (e) {
                return 'form';
            }
        }
        return 'form';
    }

    /**
     * Send tracking data to the server
     */
    function sendTrackingData(data) {
        // Add current screen context
        if (!data.screen) {
            data.screen = currentScreen;
        }

        var headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        };

        if (csrfToken) {
            headers['X-CSRF-TOKEN'] = csrfToken;
        }

        log('Sending:', data);

        fetch(TRACK_ENDPOINT, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(data),
            credentials: 'same-origin'
        }).catch(function(error) {
            // Silently fail - don't break user experience
            if (console && console.debug) {
                console.debug('Vitalytics: tracking failed', error);
            }
        });
    }

    /**
     * Parse JSON properties from data attribute
     */
    function parseProps(el) {
        var propsAttr = el.dataset.vitalyticsProps;
        if (!propsAttr) return null;

        try {
            return JSON.parse(propsAttr);
        } catch (e) {
            return null;
        }
    }

    /**
     * Track page view
     * In PHI-safe mode, doesn't include page title (may contain patient name)
     */
    function trackPageView() {
        currentScreen = getScreenFromUrl();

        var data = {
            type: 'screen',
            screen: currentScreen,
            properties: {
                url: window.location.pathname,  // Path only, no query params
                referrer: document.referrer ? new URL(document.referrer).pathname : null
            }
        };

        // Only include page title if not in PHI-safe mode
        if (!PHI_SAFE) {
            data.screenLabel = document.title || null;
            data.properties.url = window.location.href;
            data.properties.referrer = document.referrer || null;
        }

        sendTrackingData(data);
        log('Page view:', currentScreen, PHI_SAFE ? '(PHI-safe)' : '');
    }

    /**
     * Handle click tracking - AUTO-TRACKS ALL INTERACTIVE ELEMENTS
     */
    document.addEventListener('click', function(e) {
        var el = e.target;

        // Walk up the DOM to find an interactive element
        while (el && el !== document.body) {
            if (isInteractiveElement(el)) {
                break;
            }
            el = el.parentElement;
        }

        if (!el || el === document.body) {
            return;
        }

        if (!shouldTrackElement(el)) {
            return;
        }

        // Check if this is a feature usage (explicit attribute)
        if (el.dataset.vitalyticsFeature) {
            sendTrackingData({
                type: 'feature',
                feature: el.dataset.vitalyticsFeature,
                label: el.dataset.vitalyticsLabel || null,  // Only explicit labels
                screen: el.dataset.vitalyticsScreen || currentScreen,
                screenLabel: el.dataset.vitalyticsScreenLabel || null,
                properties: parseProps(el)
            });
            return;
        }

        // Track as click
        var identifier = getElementIdentifier(el);
        var label = getElementLabel(el);

        // For links, include path (not full URL with query params in PHI-safe mode)
        var properties = parseProps(el) || {};
        if (el.tagName.toLowerCase() === 'a' && el.href) {
            try {
                var linkUrl = new URL(el.href);
                if (PHI_SAFE) {
                    properties.href = linkUrl.pathname;
                } else {
                    properties.href = el.href;
                }
                if (linkUrl.host !== window.location.host) {
                    properties.external = true;
                }
            } catch (e) {}
        }

        sendTrackingData({
            type: 'click',
            element: identifier,
            label: label,
            screen: el.dataset.vitalyticsScreen || currentScreen,
            screenLabel: el.dataset.vitalyticsScreenLabel || null,
            properties: Object.keys(properties).length > 0 ? properties : null
        });

        log('Click:', identifier, label || '(no label)', PHI_SAFE ? '(PHI-safe)' : '');
    }, true);

    /**
     * Handle form submissions - AUTO-TRACKS ALL FORMS
     */
    document.addEventListener('submit', function(e) {
        var form = e.target;

        if (!form || form.tagName.toLowerCase() !== 'form') {
            return;
        }

        if (!shouldTrackElement(form)) {
            return;
        }

        var identifier = getFormIdentifier(form);
        var label = form.dataset.vitalyticsLabel || null;  // Only explicit labels

        // Count form fields (safe metadata)
        var fields = form.querySelectorAll('input, select, textarea');
        var fieldCount = fields.length;

        sendTrackingData({
            type: 'form',
            form: identifier,
            label: label,
            action: 'submitted',
            screen: form.dataset.vitalyticsScreen || currentScreen,
            screenLabel: form.dataset.vitalyticsScreenLabel || null,
            properties: {
                method: form.method || 'get',
                fieldCount: fieldCount
            }
        });

        log('Form submit:', identifier, PHI_SAFE ? '(PHI-safe)' : '');
    }, true);

    /**
     * Track screen views for modals (elements with data-vitalytics-screen-view)
     */
    var trackedScreenViews = new Set();

    function trackScreenView(el) {
        var screenName = el.dataset.vitalyticsScreenView;
        var screenLabel = el.dataset.vitalyticsScreenLabel || null;  // Only explicit labels
        var trackId = screenName + '-' + (el.id || Math.random().toString(36).substr(2, 9));

        if (trackedScreenViews.has(trackId)) return;
        trackedScreenViews.add(trackId);

        setTimeout(function() {
            trackedScreenViews.delete(trackId);
        }, 5000);

        sendTrackingData({
            type: 'screen',
            screen: screenName,
            screenLabel: screenLabel,
            properties: parseProps(el)
        });

        log('Modal/Screen view:', screenName);
    }

    function checkForScreenViews(node) {
        if (node.nodeType !== 1) return;

        if (node.matches && node.matches('[data-vitalytics-screen-view]')) {
            var style = window.getComputedStyle(node);
            if (style.display !== 'none' && style.visibility !== 'hidden') {
                trackScreenView(node);
            }
        }

        var children = node.querySelectorAll ? node.querySelectorAll('[data-vitalytics-screen-view]') : [];
        children.forEach(function(child) {
            var style = window.getComputedStyle(child);
            if (style.display !== 'none' && style.visibility !== 'hidden') {
                trackScreenView(child);
            }
        });
    }

    // Observe DOM for dynamically added modals
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(checkForScreenViews);

            if (mutation.type === 'attributes') {
                var el = mutation.target;
                if (el.matches && el.matches('[data-vitalytics-screen-view]')) {
                    var style = window.getComputedStyle(el);
                    if (style.display !== 'none' && style.visibility !== 'hidden') {
                        trackScreenView(el);
                    }
                }
            }
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['style', 'class']
    });

    /**
     * Track SPA navigation (History API, Livewire, Turbo, etc.)
     */
    var lastUrl = window.location.href;

    function checkUrlChange() {
        if (window.location.href !== lastUrl) {
            lastUrl = window.location.href;
            trackPageView();
        }
    }

    // Listen for History API changes
    var originalPushState = history.pushState;
    history.pushState = function() {
        originalPushState.apply(this, arguments);
        setTimeout(checkUrlChange, 0);
    };

    var originalReplaceState = history.replaceState;
    history.replaceState = function() {
        originalReplaceState.apply(this, arguments);
        setTimeout(checkUrlChange, 0);
    };

    window.addEventListener('popstate', function() {
        setTimeout(checkUrlChange, 0);
    });

    // Livewire navigation
    document.addEventListener('livewire:navigated', function() {
        setTimeout(trackPageView, 0);
    });

    // Turbo navigation
    document.addEventListener('turbo:load', function() {
        setTimeout(trackPageView, 0);
    });

    // Turbolinks (legacy)
    document.addEventListener('turbolinks:load', function() {
        setTimeout(trackPageView, 0);
    });

    /**
     * Track initial page view
     */
    trackPageView();

    // Check for any visible screen-view elements already in DOM
    document.querySelectorAll('[data-vitalytics-screen-view]').forEach(function(el) {
        var style = window.getComputedStyle(el);
        if (style.display !== 'none' && style.visibility !== 'hidden') {
            trackScreenView(el);
        }
    });

    /**
     * Expose API
     */
    window.VitalyticsTracker = {
        trackClick: function(element, screen, properties, label) {
            sendTrackingData({
                type: 'click',
                element: element,
                label: label || null,
                screen: screen || currentScreen,
                properties: properties || null
            });
        },
        trackFeature: function(feature, screen, properties, label) {
            sendTrackingData({
                type: 'feature',
                feature: feature,
                label: label || null,
                screen: screen || currentScreen,
                properties: properties || null
            });
        },
        trackScreen: function(screen, properties, screenLabel) {
            currentScreen = screen;
            sendTrackingData({
                type: 'screen',
                screen: screen,
                screenLabel: screenLabel || null,
                properties: properties || null
            });
        },
        trackForm: function(form, screen, properties, label) {
            sendTrackingData({
                type: 'form',
                form: form,
                label: label || null,
                action: 'submitted',
                screen: screen || currentScreen,
                properties: properties || null
            });
        },
        setScreen: function(screen) {
            currentScreen = screen;
        },
        getScreen: function() {
            return currentScreen;
        },
        enableDebug: function() {
            DEBUG = true;
            console.log('[Vitalytics] Debug mode enabled. PHI-safe:', PHI_SAFE);
        },
        enablePHISafe: function() {
            PHI_SAFE = true;
            console.log('[Vitalytics] PHI-safe mode enabled');
        },
        isPHISafe: function() {
            return PHI_SAFE;
        }
    };

    log('Auto-tracker initialized. Screen:', currentScreen, 'PHI-safe:', PHI_SAFE);

})();
