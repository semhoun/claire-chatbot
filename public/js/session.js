/**
 * Session management module for X-Claire-Auth header-based authentication.
 * Stores token in sessionStorage and injects headers for fetch/HTMX.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'claire_session_token';
    const MINI_STORAGE_KEY = 'claire_mini_token';
    const AUTH_HEADER = 'X-Claire-Auth';
    const TOKEN_HEADER = 'X-Claire-Token';
    const MINI_TOKEN_HEADER = 'X-Claire-Minitoken';
    const PROTECTED_PATH_PREFIX = '/files/serve/';
    const REFRESH_ENDPOINT_PATH = '/auth/refresh';
    const DEFAULT_REFRESH_TIMEOUT_SEC = 300;
    const DEFAULT_REFRESH_MIN_INTERVAL_SEC = 30;
    const MAX_REFRESH_BACKOFF_FACTOR = 4;

    let cachedSessionToken = null;
    let cachedSessionTokenExpiresAt = 0;
    let cachedMiniToken = null;
    let cachedMiniTokenExpiresAt = 0;
    let sessionRefreshTimerId = null;
    let sessionRefreshInFlight = false;
    let lastSessionRefreshAttemptAt = 0;
    let sessionRefreshErrorCount = 0;

    function readTokensFromUrl() {
        try {
            const url = new URL(window.location.href);
            const token = url.searchParams.get('token');
            const miniToken = url.searchParams.get('minitoken');
            const hasSessionToken = !!(token && token !== '0');
            const hasMiniToken = !!(miniToken && miniToken !== '0');

            if (hasSessionToken || hasMiniToken) {
                url.searchParams.delete('token');
                url.searchParams.delete('minitoken');
                window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : '') + url.hash);

                return {
                    token: hasSessionToken ? token : null,
                    miniToken: hasMiniToken ? miniToken : null,
                };
            }
        } catch (_error) {
            // no-op
        }

        return {
            token: null,
            miniToken: null,
        };
    }

    function getConfiguredRefreshTimeoutMs() {
        const raw = Number(window.claireRefreshTimeout);
        if (!Number.isFinite(raw) || raw < 0) {
            return DEFAULT_REFRESH_TIMEOUT_SEC * 1000;
        }

        return Math.floor(raw) * 1000;
    }

    function getConfiguredRefreshMinIntervalMs() {
        const raw = Number(window.claireRefreshMinInterval);
        if (!Number.isFinite(raw) || raw < 1) {
            return DEFAULT_REFRESH_MIN_INTERVAL_SEC * 1000;
        }

        return Math.floor(raw) * 1000;
    }

    function getBaseUrlPath() {
        const rawBaseUrl = document.body && document.body.dataset
            ? document.body.dataset.baseUrl
            : '';
        const baseUrl = typeof rawBaseUrl === 'string' ? rawBaseUrl.trim() : '';
        if (baseUrl === '' || baseUrl === '/') {
            return '';
        }

        return baseUrl.endsWith('/') ? baseUrl.slice(0, -1) : baseUrl;
    }

    function getRefreshEndpoint() {
        return getBaseUrlPath() + REFRESH_ENDPOINT_PATH;
    }

    function getSessionToken() {
        if (typeof cachedSessionToken === 'string'
            && cachedSessionToken.length > 0
            && Date.now() < cachedSessionTokenExpiresAt) {
            return cachedSessionToken;
        }

        cachedSessionToken = null;
        cachedSessionTokenExpiresAt = 0;
        sessionStorage.removeItem(STORAGE_KEY);

        return null;
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

        applyTokenToProtectedResources();
    }

    function loadSessionTokenFromStorage() {
        const raw = sessionStorage.getItem(STORAGE_KEY);
        if (!raw) {
            return;
        }

        try {
            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed.token !== 'string' || typeof parsed.expiresAt !== 'number') {
                sessionStorage.removeItem(STORAGE_KEY);
                return;
            }

            if (Date.now() >= parsed.expiresAt) {
                sessionStorage.removeItem(STORAGE_KEY);
                return;
            }

            cachedSessionToken = parsed.token;
            cachedSessionTokenExpiresAt = parsed.expiresAt;
        } catch (_error) {
            sessionStorage.removeItem(STORAGE_KEY);
        }
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

    function appendTokenToUrl(rawUrl, miniToken) {
        try {
            if (typeof miniToken !== 'string' || miniToken.length === 0) {
                return rawUrl;
            }

            const resolved = new URL(rawUrl, window.location.origin);
            if (resolved.origin !== window.location.origin) {
                return rawUrl;
            }

            if (!resolved.pathname.startsWith(PROTECTED_PATH_PREFIX)) {
                return rawUrl;
            }

            resolved.searchParams.set('token', miniToken);
            return resolved.pathname + resolved.search + resolved.hash;
        } catch (_error) {
            return rawUrl;
        }
    }

    function applyTokenToProtectedResources() {
        if (!hasValidMiniToken()) {
            return;
        }

        const miniToken = cachedMiniToken;

        const links = document.querySelectorAll('a.generated-file[href]');
        links.forEach(function (link) {
            const href = link.getAttribute('href');
            if (!href) {
                return;
            }
            link.setAttribute('href', appendTokenToUrl(href, miniToken));
        });

        const images = document.querySelectorAll('img.generated-image');
        images.forEach(function (image) {
            const src = image.getAttribute('data-protected-src') || image.getAttribute('src');
            if (!src) {
                return;
            }
            image.setAttribute('src', appendTokenToUrl(src, miniToken));
        });
    }

    function setSessionToken(token) {
        if (typeof token !== 'string' || token.length === 0) {
            return;
        }

        const exp = readJwtExp(token);
        const expiresAt = typeof exp === 'number'
            ? exp * 1000
            : Date.now();

        cachedSessionToken = token;
        cachedSessionTokenExpiresAt = expiresAt;

        sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
            token: token,
            expiresAt: expiresAt,
        }));

        sessionRefreshErrorCount = 0;
        scheduleSessionRefresh();
        applyTokenToProtectedResources();
    }

    function clearSession() {
        if (sessionRefreshTimerId !== null) {
            clearTimeout(sessionRefreshTimerId);
            sessionRefreshTimerId = null;
        }

        sessionStorage.removeItem(STORAGE_KEY);
        sessionStorage.removeItem(MINI_STORAGE_KEY);
        cachedSessionToken = null;
        cachedSessionTokenExpiresAt = 0;
        cachedMiniToken = null;
        cachedMiniTokenExpiresAt = 0;
        sessionRefreshInFlight = false;
        lastSessionRefreshAttemptAt = 0;
        sessionRefreshErrorCount = 0;
    }

    function clearSessionRefreshTimer() {
        if (sessionRefreshTimerId !== null) {
            clearTimeout(sessionRefreshTimerId);
            sessionRefreshTimerId = null;
        }
    }

    function scheduleSessionRefreshWithDelay(delayMs) {
        clearSessionRefreshTimer();

        const safeDelay = Math.max(0, Math.floor(delayMs));
        sessionRefreshTimerId = window.setTimeout(function () {
            void attemptSessionRefresh();
        }, safeDelay);
    }

    function scheduleSessionRefresh() {
        const token = getSessionToken();
        if (!token) {
            clearSessionRefreshTimer();
            return;
        }

        const now = Date.now();
        const refreshAt = cachedSessionTokenExpiresAt - getConfiguredRefreshTimeoutMs();
        const minNextAt = lastSessionRefreshAttemptAt + getConfiguredRefreshMinIntervalMs();
        const nextAttemptAt = Math.max(refreshAt, minNextAt);

        scheduleSessionRefreshWithDelay(nextAttemptAt - now);
    }

    function scheduleSessionRefreshRetry() {
        const minIntervalMs = getConfiguredRefreshMinIntervalMs();
        const backoffFactor = Math.min(
            MAX_REFRESH_BACKOFF_FACTOR,
            Math.max(1, sessionRefreshErrorCount)
        );
        const targetAttemptAt = Math.max(
            Date.now() + backoffFactor * minIntervalMs,
            lastSessionRefreshAttemptAt + minIntervalMs
        );

        scheduleSessionRefreshWithDelay(targetAttemptAt - Date.now());
    }

    async function attemptSessionRefresh() {
        if (sessionRefreshInFlight) {
            return;
        }

        const token = getSessionToken();
        if (!token) {
            clearSessionRefreshTimer();
            return;
        }

        const now = Date.now();
        const minIntervalMs = getConfiguredRefreshMinIntervalMs();
        const earliestAllowedAt = lastSessionRefreshAttemptAt + minIntervalMs;
        if (now < earliestAllowedAt) {
            scheduleSessionRefreshWithDelay(earliestAllowedAt - now);
            return;
        }

        sessionRefreshInFlight = true;
        lastSessionRefreshAttemptAt = now;

        try {
            const response = await window.fetch(getRefreshEndpoint(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/plain, */*',
                },
            });

            if (response.status === 401 || response.status === 403) {
                clearSession();

                return;
            }

            if (!response.ok) {
                throw new Error('Session refresh failed with status ' + response.status);
            }

            const refreshedToken = response.headers.get(TOKEN_HEADER);
            if (refreshedToken) {
                setSessionToken(refreshedToken);
            } else {
                sessionRefreshErrorCount = 0;
                scheduleSessionRefresh();
            }

            const refreshedMiniToken = response.headers.get(MINI_TOKEN_HEADER);
            if (refreshedMiniToken) {
                setMiniToken(refreshedMiniToken, 0);
            }
        } catch (_error) {
            sessionRefreshErrorCount += 1;
            scheduleSessionRefreshRetry();
        } finally {
            sessionRefreshInFlight = false;
        }
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

            const newMiniToken = xhr.getResponseHeader(MINI_TOKEN_HEADER);
            if (newMiniToken) {
                setMiniToken(newMiniToken, 0);
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

            if (!hasValidMiniToken()) {
                event.preventDefault();
                return;
            }

            const href = link.getAttribute('href');
            if (!href) {
                return;
            }

            link.setAttribute('href', appendTokenToUrl(href, cachedMiniToken));
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

    loadSessionTokenFromStorage();
    loadMiniTokenFromStorage();
    const earlyTokensFromUrl = readTokensFromUrl();
    if (earlyTokensFromUrl.miniToken) {
        setMiniToken(earlyTokensFromUrl.miniToken, 0);
    }

    if (earlyTokensFromUrl.token) {
        setSessionToken(earlyTokensFromUrl.token);
    }

    scheduleSessionRefresh();

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
