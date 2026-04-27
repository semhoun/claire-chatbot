/**
 * Session management module for X-Claire-Auth header-based authentication.
 * Stores token in sessionStorage, injects headers for fetch/HTMX,
 * and mirrors token into a cookie for EventSource requests.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'claire_session_token';
    const COOKIE_KEY = 'claire_session_token';
    const AUTH_HEADER = 'X-Claire-Auth';
    const TOKEN_HEADER = 'X-Claire-Token';

    function readCookie(name) {
        const prefix = name + '=';
        const parts = document.cookie ? document.cookie.split(';') : [];
        for (let i = 0; i < parts.length; i += 1) {
            const part = parts[i].trim();
            if (part.indexOf(prefix) === 0) {
                return decodeURIComponent(part.slice(prefix.length));
            }
        }

        return null;
    }

    function writeCookie(token) {
        const secure = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = COOKIE_KEY + '=' + encodeURIComponent(token)
            + '; Path=/; SameSite=Lax' + secure;
    }

    function deleteCookie() {
        document.cookie = COOKIE_KEY
            + '=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Lax';
    }

    function getSessionToken() {
        const storageToken = sessionStorage.getItem(STORAGE_KEY);
        if (storageToken) {
            return storageToken;
        }

        const cookieToken = readCookie(COOKIE_KEY);
        if (cookieToken) {
            sessionStorage.setItem(STORAGE_KEY, cookieToken);
            return cookieToken;
        }

        return null;
    }

    function setSessionToken(token) {
        if (typeof token !== 'string' || token.length === 0) {
            return;
        }

        sessionStorage.setItem(STORAGE_KEY, token);
        writeCookie(token);
    }

    function clearSession() {
        sessionStorage.removeItem(STORAGE_KEY);
        deleteCookie();
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
        });
    }

    function bootstrap() {
        const token = getSessionToken();
        if (token) {
            writeCookie(token);
        }

        configureFetch();
        configureHtmx();
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
        setSessionToken: setSessionToken,
        clearSession: clearSession,
        getAuthHeaders: getAuthHeaders,
        handleSessionResponse: handleSessionResponse,
    };
})();
