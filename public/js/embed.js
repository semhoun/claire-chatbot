(function () {
    'use strict';

    const EMBED_CONTAINER_ID = 'claire-embed-container';

    let currentRoot = null;
    let managedListeners = [];
    let managedObservers = [];

    function toAbsoluteBaseUrl(rawBaseUrl) {
        const base = typeof rawBaseUrl === 'string' && rawBaseUrl.trim() !== ''
            ? rawBaseUrl.trim()
            : window.location.origin;
        return base.endsWith('/') ? base.slice(0, -1) : base;
    }

    function resolveTarget(target) {
        if (target instanceof Element) {
            return target;
        }

        if (typeof target === 'string' && target.trim() !== '') {
            const resolved = document.querySelector(target);
            if (resolved instanceof Element) {
                return resolved;
            }
        }

        return document.body;
    }

    function ensureContainer(target) {
        const existing = target.querySelector('#' + EMBED_CONTAINER_ID);
        if (existing instanceof HTMLElement) {
            return existing;
        }

        const container = document.createElement('div');
        container.id = EMBED_CONTAINER_ID;
        target.appendChild(container);
        return container;
    }

    function readJwtExp(token) {
        try {
            const parts = String(token).split('.');
            if (parts.length !== 3) {
                return null;
            }

            const payloadPart = parts[1].replace(/-/g, '+').replace(/_/g, '/');
            const padded = payloadPart + '==='.slice((payloadPart.length + 3) % 4);
            const payload = JSON.parse(atob(padded));
            if (!payload || typeof payload.exp !== 'number') {
                return null;
            }

            return payload.exp;
        } catch (_error) {
            return null;
        }
    }

    function readJwtPayload(token) {
        try {
            const parts = String(token).split('.');
            if (parts.length !== 3) {
                return null;
            }

            const payloadPart = parts[1].replace(/-/g, '+').replace(/_/g, '/');
            const padded = payloadPart + '==='.slice((payloadPart.length + 3) % 4);
            const payload = JSON.parse(atob(padded));

            return payload && typeof payload === 'object' ? payload : null;
        } catch (_error) {
            return null;
        }
    }

    function getClaireTokenAudience(token) {
        const payload = readJwtPayload(token);
        if (!payload) {
            return null;
        }

        const aud = payload.aud;
        if (typeof aud === 'string') {
            if (aud === 'session' || aud === 'minitoken') {
                return aud;
            }

            return null;
        }

        if (Array.isArray(aud)) {
            if (aud.includes('session')) {
                return 'session';
            }

            if (aud.includes('minitoken')) {
                return 'minitoken';
            }
        }

        return null;
    }

    function storeMiniToken(miniToken) {
        if (typeof miniToken !== 'string' || miniToken.length === 0) {
            return;
        }

        const exp = readJwtExp(miniToken);
        if (typeof exp !== 'number') {
            return;
        }

        window.sessionStorage.setItem('claire_mini_token', JSON.stringify({
            token: miniToken,
            expiresAt: exp * 1000,
        }));
    }

    async function loadStyle(href) {
        const absoluteHref = new URL(href, window.location.href).href;
        const links = document.querySelectorAll('link[rel="stylesheet"]');
        for (const link of links) {
            if (link.href === absoluteHref) {
                return;
            }
        }

        await new Promise((resolve, reject) => {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = absoluteHref;
            link.onload = resolve;
            link.onerror = () => reject(new Error('Failed to load stylesheet: ' + absoluteHref));
            document.head.appendChild(link);
        });
    }

    async function loadScript(src) {
        const absoluteSrc = new URL(src, window.location.href).href;
        const scripts = document.querySelectorAll('script[src]');
        for (const script of scripts) {
            if (script.src === absoluteSrc) {
                return;
            }
        }

        await new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = absoluteSrc;
            script.async = false;
            script.dataset.claireEmbedManaged = '1';
            script.onload = resolve;
            script.onerror = () => reject(new Error('Failed to load script: ' + absoluteSrc));
            document.head.appendChild(script);
        });
    }

    function removeScript(src) {
        const absoluteSrc = new URL(src, window.location.href).href;
        const scripts = document.querySelectorAll(
            'script[src][data-claire-embed-managed="1"]'
        );
        for (const script of scripts) {
            if (script.src === absoluteSrc && script.parentNode) {
                script.parentNode.removeChild(script);
            }
        }
    }

    async function loadManagedScriptWithCapture(src) {
        const originalAddEventListener = EventTarget.prototype.addEventListener;
        const OriginalMutationObserver = window.MutationObserver;

        EventTarget.prototype.addEventListener = function(type, listener, options) {
            managedListeners.push({
                target: this,
                type: type,
                listener: listener,
                options: options,
            });

            return originalAddEventListener.call(
                this,
                type,
                listener,
                options
            );
        };

        if (typeof OriginalMutationObserver === 'function') {
            window.MutationObserver = class extends OriginalMutationObserver {
                constructor(callback) {
                    super(callback);
                    managedObservers.push(this);
                }
            };
        }

        try {
            await loadScript(src);
        } finally {
            EventTarget.prototype.addEventListener = originalAddEventListener;
            if (typeof OriginalMutationObserver === 'function') {
                window.MutationObserver = OriginalMutationObserver;
            }
        }
    }

    async function ensureAssets(baseUrl) {
        await loadStyle(baseUrl + '/css/style.css');
        await loadStyle(baseUrl + '/css/highlight.min.css');

        await loadScript(baseUrl + '/js/highlight.min.js');
        await loadScript(baseUrl + '/js/htmx.min.js');
        await loadScript(baseUrl + '/js/sse.js');
        await loadScript(baseUrl + '/js/session.js');
    }

    async function exchangeSsoToken(baseUrl, ssoToken, ssoTokenType) {
        const payload = { sso_token: ssoToken };
        if (typeof ssoTokenType === 'string' && ssoTokenType.trim() !== '') {
            payload.sso_token_type = ssoTokenType.trim();
        }

        const response = await window.fetch(baseUrl + '/auth/embed/exchange', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        });

        if (!response.ok) {
            throw new Error('SSO exchange failed with status ' + response.status);
        }

        const data = await response.json();
        if (!data || typeof data.session_token !== 'string') {
            throw new Error('SSO exchange response is invalid');
        }

        return data;
    }

    async function fetchEmbedHtml(baseUrl, sessionToken) {
        const embedUrl = new URL(baseUrl + '/embed');
        if (typeof sessionToken === 'string' && sessionToken !== '') {
            embedUrl.searchParams.set('token', sessionToken);
        }

        const response = await window.fetch(embedUrl.toString(), {
            method: 'GET',
            headers: {
                'Accept': 'text/html',
            },
        });

        if (!response.ok) {
            throw new Error('Embed page fetch failed with status ' + response.status);
        }

        const html = await response.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const appRoot = doc.querySelector('.claire-app');
        const body = doc.querySelector('body');

        return {
            html: appRoot ? appRoot.innerHTML : html,
            acceptedExt: body ? body.getAttribute('data-accepted-ext') || '' : '',
        };
    }

    function closeLiveEventSource() {
        if (window.claireChatEventSource && typeof window.claireChatEventSource.close === 'function') {
            window.claireChatEventSource.close();
        }
        window.claireChatEventSource = null;
    }

    function destroyClaireEmbed() {
        closeLiveEventSource();

        for (const entry of managedListeners) {
            try {
                entry.target.removeEventListener(
                    entry.type,
                    entry.listener,
                    entry.options
                );
            } catch (_error) {
                // no-op
            }
        }
        managedListeners = [];

        for (const observer of managedObservers) {
            try {
                observer.disconnect();
            } catch (_error) {
                // no-op
            }
        }
        managedObservers = [];

        if (currentRoot instanceof HTMLElement) {
            currentRoot.innerHTML = '';
        }

        const baseUrl = document.body.getAttribute('data-base-url')
            || window.location.origin;
        removeScript(baseUrl + '/js/app.js');
        removeScript(baseUrl + '/js/dialog.js');

        currentRoot = null;
        window.claireStreamSessionId = null;
    }

    async function claireEmbed(config) {
        const options = config && typeof config === 'object' ? config : {};
        const baseUrl = toAbsoluteBaseUrl(options.baseUrl);
        const target = resolveTarget(options.target);

        destroyClaireEmbed();

        const container = ensureContainer(target);
        container.innerHTML = '<div class="claire-embed-loading">Chargement…</div>';
        currentRoot = container;

        try {
            await ensureAssets(baseUrl);

            let sessionToken =
                typeof options.sessionToken === 'string'
                    ? options.sessionToken.trim()
                    : '';
            const genericToken =
                typeof options.token === 'string' ? options.token.trim() : '';
            const ssoToken =
                typeof options.ssoToken === 'string' ? options.ssoToken.trim() : '';
            let miniToken = '';
            const genericAudience =
                genericToken !== ''
                    ? getClaireTokenAudience(genericToken)
                    : null;

            if (sessionToken === '' && genericAudience !== null) {
                sessionToken = genericToken;
            }

            const exchangeToken = ssoToken !== '' ? ssoToken : genericToken;

            if (
                sessionToken === ''
                && exchangeToken !== ''
                && genericAudience === null
            ) {
                const exchange = await exchangeSsoToken(
                    baseUrl,
                    exchangeToken,
                    options.ssoTokenType
                );
                sessionToken = exchange.session_token;
                miniToken =
                    typeof exchange.mini_token === 'string'
                        ? exchange.mini_token
                        : '';
            }

            const embedData = await fetchEmbedHtml(baseUrl, sessionToken);

            document.body.setAttribute('data-base-url', baseUrl);
            if (embedData.acceptedExt !== '') {
                document.body.setAttribute('data-accepted-ext', embedData.acceptedExt);
            }

            container.innerHTML = embedData.html;

            if (window.claireSession && typeof window.claireSession.setSessionToken === 'function' && sessionToken !== '') {
                window.claireSession.setSessionToken(sessionToken);
            }
            if (miniToken !== '') {
                storeMiniToken(miniToken);
                if (window.claireSession && typeof window.claireSession.handleSessionResponse === 'function') {
                    window.claireSession.handleSessionResponse({
                        headers: {
                            get: function(name) {
                                const normalized = String(name || '').toLowerCase();
                                if (normalized === 'x-claire-token') {
                                    return sessionToken;
                                }
                                if (normalized === 'x-claire-minitoken') {
                                    return miniToken;
                                }
                                return null;
                            },
                        },
                    });
                }
            }

            await loadManagedScriptWithCapture(baseUrl + '/js/app.js');
            await loadManagedScriptWithCapture(baseUrl + '/js/dialog.js');

            if (window.htmx && typeof window.htmx.process === 'function') {
                window.htmx.process(container);
            }
        } catch (error) {
            container.innerHTML = '<div class="claire-embed-error">Erreur de chargement de Claire.</div>';
            throw error;
        }
    }

    window.claireEmbed = claireEmbed;
    window.destroyClaireEmbed = destroyClaireEmbed;
})();
