/**
 * Session management module for X-Claire-Auth header-based authentication.
 * Stores token in sessionStorage and injects headers for fetch/HTMX.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'claire_session_token';
    const MINI_STORAGE_KEY = 'claire_minitoken';
    const AUTH_HEADER = 'X-Claire-Auth';
    const TOKEN_HEADER = 'X-Claire-Token';
    const MINI_TOKEN_HEADER = 'X-Claire-Minitoken';
    const PROTECTED_PATH_PREFIX = '/files/serve/';

    let cachedMiniToken = null;
    let cachedMiniTokenExpiresAt = 0;

    function readTokenFromUrl() {
        try {
            const url = new URL(window.location.href);
            const token = url.searchParams.get('token');
            if (token && token !== '0') {
                url.searchParams.delete('token');
                window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : '') + url.hash);
                return token;
            }
        } catch (_error) {
            // no-op
        }

        return null;
    }

    function getSessionToken() {
        return sessionStorage.getItem(STORAGE_KEY);
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

    function setMiniToken(token, expiresInSec) {
        if (typeof token !== 'string' || token.length === 0) {
            return;
        }

        const now = Date.now();
        const marginMs = 2000;
        let ttlMs = Math.max(0, Number(expiresInSec || 0) * 1000 - marginMs);
        if (ttlMs <= 0) {
            const exp = readJwtExp(token);
            if (typeof exp === 'number') {
                ttlMs = Math.max(0, exp * 1000 - now - marginMs);
            }
        }

        cachedMiniToken = token;
        cachedMiniTokenExpiresAt = now + ttlMs;

        sessionStorage.setItem(MINI_STORAGE_KEY, JSON.stringify({
            token: token,
            expiresAt: cachedMiniTokenExpiresAt,
        }));
    }

    function loadMiniTokenFromStorage() {
        const raw = sessionStorage.getItem(MINI_STORAGE_KEY);
        if (!raw) {
            return;
        }

        try {
            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed.token !== 'string' || typeof parsed.expiresAt !== 'number') {
                return;
            }

            if (Date.now() >= parsed.expiresAt) {
                sessionStorage.removeItem(MINI_STORAGE_KEY);
                return;
            }

            cachedMiniToken = parsed.token;
            cachedMiniTokenExpiresAt = parsed.expiresAt;
        } catch (_error) {
            sessionStorage.removeItem(MINI_STORAGE_KEY);
        }
    }

    function hasValidMiniToken() {
        return typeof cachedMiniToken === 'string'
            && cachedMiniToken.length > 0
            && Date.now() < cachedMiniTokenExpiresAt;
    }

    function appendTokenToUrl(rawUrl, token, miniToken) {
        try {
            const resolved = new URL(rawUrl, window.location.origin);
            if (resolved.origin !== window.location.origin) {
                return rawUrl;
            }

            if (!resolved.pathname.startsWith(PROTECTED_PATH_PREFIX)) {
                return rawUrl;
            }

            resolved.searchParams.set('token', token);
            if (typeof miniToken === 'string' && miniToken.length > 0) {
                resolved.searchParams.set('minitoken', miniToken);
            }
            return resolved.pathname + resolved.search + resolved.hash;
        } catch (_error) {
            return rawUrl;
        }
    }

    function applyTokenToProtectedResources() {
        const token = getSessionToken();
        if (!token) {
            return;
        }

        const links = document.querySelectorAll('a.generated-file[href]');
        links.forEach(function (link) {
            const href = link.getAttribute('href');
            if (!href) {
                return;
            }
            const miniToken = hasValidMiniToken() ? cachedMiniToken : null;
            link.setAttribute('href', appendTokenToUrl(href, token, miniToken));
        });

        const images = document.querySelectorAll('img.generated-image[src]');
        images.forEach(function (image) {
            const src = image.getAttribute('src');
            if (!src) {
                return;
            }
            const miniToken = hasValidMiniToken() ? cachedMiniToken : null;
            image.setAttribute('src', appendTokenToUrl(src, token, miniToken));
        });
    }

    function setSessionToken(token) {
        if (typeof token !== 'string' || token.length === 0) {
            return;
        }

        sessionStorage.setItem(STORAGE_KEY, token);
        applyTokenToProtectedResources();
    }

    function clearSession() {
        sessionStorage.removeItem(STORAGE_KEY);
        sessionStorage.removeItem(MINI_STORAGE_KEY);
        cachedMiniToken = null;
        cachedMiniTokenExpiresAt = 0;
    }

    function getAuthHeaders(headers) {
        const out = Object.assign({}, headers || {});
        const token = getSessionToken();
        if (token) {
            out[AUTH_HEADER] = token;
        }

        return out;
    }

    function handleSessionResponse(response) {
        if (!response || !response.headers) {
            return;
        }

        const newToken = response.headers.get(TOKEN_HEADER);
        if (newToken) {
            setSessionToken(newToken);
        }

        const newMiniToken = response.headers.get(MINI_TOKEN_HEADER);
        if (newMiniToken) {
            setMiniToken(newMiniToken, 0);
        }
    }

    function isSameOriginRequest(input) {
        try {
            const requestUrl = input instanceof Request
                ? input.url
                : String(input);
            const resolved = new URL(requestUrl, window.location.origin);

            return resolved.origin === window.location.origin;
        } catch (_error) {
            return true;
        }
    }

    function configureFetch() {
        if (typeof window.fetch !== 'function' || window.fetch.__claireSessionWrapped) {
            return;
        }

        const originalFetch = window.fetch.bind(window);

        const wrappedFetch = async function(input, init) {
            let nextInit = init || {};

            if (isSameOriginRequest(input)) {
                const headers = new Headers(nextInit.headers || (input instanceof Request ? input.headers : undefined));
                const token = getSessionToken();
                if (token && !headers.has(AUTH_HEADER)) {
                    headers.set(AUTH_HEADER, token);
                }

                nextInit = Object.assign({}, nextInit, { headers: headers });
            }

            const response = await originalFetch(input, nextInit);
            handleSessionResponse(response);

            return response;
        };

        wrappedFetch.__claireSessionWrapped = true;
        window.fetch = wrappedFetch;
    }

    function configureHtmx() {
        if (typeof window.htmx === 'undefined') {
            return;
        }

        window.htmx.on('htmx:configRequest', function (evt) {
            const token = getSessionToken();
            if (token) {
                evt.detail.headers[AUTH_HEADER] = token;
            }
        });

        window.htmx.on('htmx:afterRequest', function (evt) {
            const xhr = evt.detail.xhr;
            if (!xhr) {
                return;
            }

            const newToken = xhr.getResponseHeader(TOKEN_HEADER);
            if (newToken) {
                setSessionToken(newToken);
            }

            applyTokenToProtectedResources();
        });
    }

    function configureProtectedResourceClicks() {
        document.addEventListener('click', function (event) {
            const link = event.target && event.target.closest
                ? event.target.closest('a.generated-file[href]')
                : null;
            if (!link) {
                return;
            }

            const token = getSessionToken();
            if (!token) {
                return;
            }

            const href = link.getAttribute('href');
            if (!href) {
                return;
            }

            const miniToken = hasValidMiniToken() ? cachedMiniToken : null;
            link.setAttribute('href', appendTokenToUrl(href, token, miniToken));
        });
    }

    function configureMutationObserver() {
        const observer = new MutationObserver(function () {
            applyTokenToProtectedResources();
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
        });
    }

    function bootstrap() {
        configureFetch();
        configureHtmx();
        configureProtectedResourceClicks();
        configureMutationObserver();
        applyTokenToProtectedResources();
    }

    loadMiniTokenFromStorage();
    const earlyTokenFromUrl = readTokenFromUrl();
    if (earlyTokenFromUrl) {
        setSessionToken(earlyTokenFromUrl);
    }

    configureFetch();
    configureHtmx();

    if (document.readyState !== 'loading') {
        bootstrap();
    } else {
        document.addEventListener('DOMContentLoaded', bootstrap);
    }

    window.ClaireSession = {
        getSessionToken: getSessionToken,
        getMiniToken: function () {
            return hasValidMiniToken() ? cachedMiniToken : null;
        },
        setSessionToken: setSessionToken,
        clearSession: clearSession,
        getAuthHeaders: getAuthHeaders,
        handleSessionResponse: handleSessionResponse,
    };
})();
