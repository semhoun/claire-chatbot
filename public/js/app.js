/**
 * app.js - Claire Chatbot Global JS
 */
(function() {
    const baseUrl = document.body.getAttribute('data-base-url') || '';
    const acceptedExt = document.body.getAttribute('data-accepted-ext') || '';

    // --- DOM Helpers ---
    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    // --- Escape Key Handler ---
    const escapeHandlers = [];

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        for (const handler of escapeHandlers) {
            if (handler(e)) {
                e.preventDefault();
                break;
            }
        }
    });

    const onEscape = (predicate, action) => {
        escapeHandlers.push((e) => { if (predicate()) { action(); return true; } return false; });
    };

    // --- Layout & Options Panel ---
    (function() {
        const panel = document.getElementById('optionsPanel');
        const closeBtn = panel ? panel.querySelector('.options-close') : null;
        const toggleBtn = $('.options-toggle');
        const backdrop = document.getElementById('optionsBackdrop');
        if (!panel || !closeBtn || !toggleBtn) return;

        function closePanel(focusToggle = true) {
            panel.classList.remove('is-open');
            toggleBtn.classList.remove('is-active');
            toggleBtn.setAttribute('aria-expanded', 'false');
            if (backdrop) { backdrop.classList.remove('is-visible'); }
            if (focusToggle) {
                try { toggleBtn.focus(); } catch (e) {}
            }
        }

        closeBtn.addEventListener('click', () => closePanel(true));
        if (backdrop) backdrop.addEventListener('click', () => closePanel(true));
        onEscape(() => panel.classList.contains('is-open'), () => closePanel(true));

        const observer = new MutationObserver(function() {
            const isOpen = panel.classList.contains('is-open');
            toggleBtn.setAttribute('aria-expanded', String(isOpen));
            if (backdrop) { backdrop.classList.toggle('is-visible', isOpen); }
        });
        observer.observe(panel, { attributes: true, attributeFilter: ['class'] });
    })();

    window.setGlobalActionIndicatorState = function(isVisible) {
        const indicator = document.getElementById('globalActionIndicator');
        if (!indicator) return;
        indicator.classList.toggle('htmx-request', isVisible);
    };

    // --- Assistant & Workflow Selectors ---
    (function() {
        const sel = document.getElementById('brainSelector');
        if (!sel) return;
        sel.addEventListener('change', async function() {
            const val = sel.value;
            window.setGlobalActionIndicatorState(true);
            try {
                await fetch(baseUrl + '/config/brain_avatar', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ avatar: val }).toString(),
                });
            } catch (e) {
                console.error(e);
            } finally {
                window.setGlobalActionIndicatorState(false);
            }
            window.location.reload();
        });
    })();

    (function() {
        const sel = document.getElementById('comfyuiWorkflowSelector');
        if (!sel) return;
        sel.addEventListener('change', async function() {
            const val = sel.value;
            window.setGlobalActionIndicatorState(true);
            try {
                await fetch(baseUrl + '/config/comfyui_workflow', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ workflow: val }).toString(),
                });
            } catch (e) {
                console.error(e);
            } finally {
                window.setGlobalActionIndicatorState(false);
            }
        });
    })();

    // --- Toggle Utility ---
    function createToggle(btnId, panelId, onExpand) {
        const btn = document.getElementById(btnId);
        const panel = document.getElementById(panelId);
        if (!btn || !panel) return;

        const isExpanded = () => btn.getAttribute('aria-expanded') === 'true';
        const expand = () => {
            btn.setAttribute('aria-expanded', 'true');
            panel.style.display = '';
            if (onExpand) onExpand();
        };
        const collapse = () => {
            btn.setAttribute('aria-expanded', 'false');
            panel.innerHTML = '';
        };
        const toggle = () => { if (isExpanded()) collapse(); else expand(); };

        btn.addEventListener('click', toggle);
        btn.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggle();
            }
        });
    }

    // --- RAG Toggle ---
    createToggle('ragToggle', 'ragPanel', null);

    // --- Upload Dialog ---
    (function() {
        const root = document;
        const dlg = (function createDialog(){
            let existing = root.getElementById('uploadDialog');
            if (existing) return existing;
            const wrap = root.createElement('div');
            wrap.id = 'uploadDialog';
            wrap.setAttribute('role', 'dialog');
            wrap.setAttribute('aria-modal', 'true');
            wrap.setAttribute('aria-hidden', 'true');
            wrap.style.cssText = 'position:fixed; inset:0; display:none; align-items:center; justify-content:center; z-index:1000;';
            wrap.innerHTML = `
                <div class="dialog-backdrop" data-upload-backdrop></div>
                <div class="dialog-panel" role="document">
                    <div style="display:flex; align-items:center; gap:12px; padding:14px 18px; border-bottom:1px solid rgba(0,0,0,.08);">
                        <div id="uploadDialogTitle" class="options-panel__title" style="font-size:17px;">Téléverser un fichier</div>
                    </div>
                    <form id="uploadDialogForm"
                        style="padding:18px; display:flex; flex-direction:column; gap:14px;"
                        hx-encoding="multipart/form-data"
                        hx-indicator="#filesRagUploadIndicator"
                        >
                        <div id="filesRagUploadIndicator" class="htmx-indicator" aria-hidden="true"
                             style="position:absolute; inset:0; display:none; background:rgba(8,0,14,0.55); backdrop-filter:blur(2px); border-radius:10px; align-items:center; justify-content:center; z-index:5;">
                            <div style="display:flex; align-items:center; gap:10px; padding:8px 12px; background:rgba(30,0,50,0.9); border:1px solid rgba(255,255,255,0.08); border-radius:999px; box-shadow:0 10px 26px rgba(26,0,41,0.35);">
                                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" style="color:#ff7ce5;">
                                    <path d="M7 2h10v4a5 5 0 0 1-2.1 4.1L12 12l2.9 1.9A5 5 0 0 1 17 18v4H7v-4a5 5 0 0 1 2.1-4.1L12 12l-2.9-1.9A5 5 0 0 1 7 6V2z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                                    <path d="M8 4h8M8 20h8" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                                </svg>
                                <span style="color:#fff0fe; font-size:14px;">Téléversement et vectorisation en cours…</span>
                            </div>
                        </div>
                        <div class="upload-field" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                            <input type="file" name="file" id="uploadDialogInput"
                                   accept="${acceptedExt}" required
                                   style="position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden;" />
                            <label for="uploadDialogInput" class="btn dialog-panel-btn" aria-label="Parcourir" title="Parcourir…">Parcourir…</label>
                            <span id="uploadDialogFileName" style="min-width:180px; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color: wheat;" aria-live="polite">Aucun fichier sélectionné</span>
                        </div>
                        <div style="font-size:12px; color:#666;">Extensions autorisées: ${acceptedExt}</div>
                        <div style="display:flex; gap:8px; justify-content:flex-end;">
                            <button type="button" class="btn dialog-panel-btn" data-upload-cancel>Annuler</button>
                            <button type="submit" class="btn btn--primary" data-upload-submit disabled>Envoyer</button>
                        </div>
                    </form>
                </div>`;
            root.body.appendChild(wrap);
            return wrap;
        })();

        const form = dlg.querySelector('#uploadDialogForm');
        const input = dlg.querySelector('#uploadDialogInput');
        const btnCancel = dlg.querySelector('[data-upload-cancel]');
        const backdrop = dlg.querySelector('[data-upload-backdrop]');
        const title = dlg.querySelector('#uploadDialogTitle');

        function openDialog(ctx) {
            input.value = '';
            form.removeAttribute('hx-target');
            form.setAttribute('hx-swap', 'none');
            form.__ctx = ctx;

            if (ctx === 'files') {
                title.textContent = 'Téléverser un fichier';
                form.setAttribute('hx-post', baseUrl + '/files/upload');
                form.setAttribute('hx-target', '#filesList');
                form.setAttribute('hx-swap', 'innerHTML');
                const onAfter = function() {
                    try { if (window.htmx) { window.htmx.ajax('GET', baseUrl + '/files/count', {target:'#filesCountBadge', swap:'innerHTML'}); } } catch(_){ }
                    closeDialog();
                };
                form.addEventListener('htmx:afterOnLoad', onAfter, { once: true });
            } else {
                title.textContent = 'Téléverser un document RAG';
                form.setAttribute('hx-post', baseUrl + '/rag/upload');
                form.setAttribute('hx-swap', 'none');
                const onAfterReq = function() {
                    try {
                        var fb = document.getElementById('ragUploadFeedback');
                        if (fb) { fb.textContent = 'Fichier envoyé'; fb.style.opacity = '1'; setTimeout(function(){ fb.style.opacity = '0'; }, 1800); }
                    } catch(_) {}
                    closeDialog();
                };
                form.addEventListener('htmx:afterRequest', onAfterReq, { once: true });
            }

            dlg.style.display = 'flex';
            dlg.setAttribute('aria-hidden', 'false');
            const submitBtn = dlg.querySelector('[data-upload-submit]');
            if (submitBtn) submitBtn.disabled = true;
            setTimeout(function(){ const label = dlg.querySelector('label[for="uploadDialogInput"]'); (label||input).focus(); }, 0);
        }

        function closeDialog() {
            dlg.style.display = 'none';
            dlg.setAttribute('aria-hidden', 'true');
        }

        root.addEventListener('click', function(e){
            const btn = e.target.closest && e.target.closest('[data-open-upload-dialog]');
            if (!btn) return;
            const ctx = btn.getAttribute('data-upload-context') === 'rag' ? 'rag' : 'files';
            openDialog(ctx);
        });

        [btnCancel, backdrop].forEach((el) => { if (el) el.addEventListener('click', closeDialog); });
        onEscape(() => dlg.style.display !== 'none', closeDialog);

        input.addEventListener('change', function(){
            const fileChosen = input.files && input.files.length > 0;
            const submit = dlg.querySelector('[data-upload-submit]');
            const nameEl = dlg.querySelector('#uploadDialogFileName');
            if (nameEl) {
                nameEl.textContent = fileChosen ? (input.files[0].name || 'Fichier sélectionné') : 'Aucun fichier sélectionné';
                nameEl.title = nameEl.textContent;
            }
            if (submit) submit.disabled = !fileChosen;
        });
    })();

    // --- Layout Mode ---
    (function() {
        const toggle = document.getElementById('toggleLayoutMode');
        if (!toggle) return;
        const badge = toggle.querySelector('[data-layout-badge]');
        function currentMode() { return (toggle.getAttribute('data-layout-mode') || 'full'); }
        function setMode(mode) {
            toggle.setAttribute('data-layout-mode', mode);
            const isCompact = mode === 'compact';
            document.body.classList.toggle('compact', isCompact);
            if (badge) badge.textContent = isCompact ? 'Largeur 800px' : 'Plein écran';
        }
        async function postMode(mode) {
            try {
                await fetch(baseUrl + '/config/layout_mode', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ mode })
                });
            } catch (e) {}
        }
        function toggleClick() {
            const next = currentMode() === 'compact' ? 'full' : 'compact';
            setMode(next);
            postMode(next);
        }
        toggle.addEventListener('click', toggleClick);
        toggle.addEventListener('keydown', function(e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleClick(); } });
        setMode(currentMode());
    })();

    // --- History Tooltips ---
    (function() {
        const banner = document.getElementById('historyTooltipBanner');
        if (!banner) return;
        function showBannerFrom(el) {
            const text = el.getAttribute('data-tooltip') || '';
            if (!text) return;
            banner.textContent = text;
            banner.classList.add('is-visible');
            banner.setAttribute('aria-hidden', 'false');
        }
        function hideBanner() {
            banner.classList.remove('is-visible');
            banner.setAttribute('aria-hidden', 'true');
        }
        function wireTooltips(root) {
            const scope = root || document;
            const nodes = scope.querySelectorAll('.history-item__content[data-tooltip]');
            nodes.forEach(function(el) {
                if (el.__hasTooltipBanner) return;
                el.__hasTooltipBanner = true;
                el.addEventListener('mouseenter', function() { showBannerFrom(el); });
                el.addEventListener('mouseleave', hideBanner);
                el.addEventListener('focusin', function() { showBannerFrom(el); });
                el.addEventListener('focusout', hideBanner);
                el.addEventListener('touchstart', function() { showBannerFrom(el); }, { passive: true });
                el.addEventListener('touchend', function() { setTimeout(hideBanner, 120); });
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() { wireTooltips(document); });
        } else {
            wireTooltips(document);
        }
        window.addEventListener('htmx:afterSwap', function(e) {
            const t = e.target;
            try { if (t && (t.id === 'historyList' || (t.closest && t.closest('#historyList')))) { wireTooltips(t); } } catch (_) {}
        });
    })();

    // --- History Toggle ---
    createToggle('historyToggle', 'historyList', () => {
        if (window.htmx) {
            window.htmx.ajax('GET', baseUrl + '/history/list', {
                target: '#historyList', swap: 'innerHTML', indicator: '#globalActionIndicator'
            });
        }
    });

    // --- Files Toggle ---
    createToggle('filesToggle', 'filesList', () => {
        if (window.htmx) {
            window.htmx.ajax('GET', baseUrl + '/files/list', {
                target: '#filesList', swap: 'innerHTML', indicator: '#globalActionIndicator'
            });
        }
    });

    // --- Lightbox ---
    (function () {
        function createLightbox() {
            let lb = document.getElementById('imageLightbox');
            if (lb) return lb;
            lb = document.createElement('div');
            lb.id = 'imageLightbox';
            lb.className = 'image-lightbox';
            lb.setAttribute('role', 'dialog');
            lb.setAttribute('aria-modal', 'true');
            lb.setAttribute('aria-hidden', 'true');
            lb.innerHTML = `
                <div class="image-lightbox__backdrop" data-lightbox-close></div>
                <div class="image-lightbox__content">
                    <img class="image-lightbox__img" src="" alt="Image agrandie" data-lightbox-close>
                </div>
            `;
            document.body.appendChild(lb);
            return lb;
        }
        function openLightbox(src) {
            const lb = createLightbox();
            const img = lb.querySelector('.image-lightbox__img');
            img.src = src;
            lb.classList.add('is-open');
            lb.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
            document.addEventListener('keydown', onKeyDown);
        }
        function closeLightbox() {
            const lb = document.getElementById('imageLightbox');
            if (!lb) return;
            lb.classList.remove('is-open');
            lb.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
            document.removeEventListener('keydown', onKeyDown);
        }
        function onKeyDown(e) { if (e.key === 'Escape') { e.preventDefault(); closeLightbox(); } }
        document.addEventListener('click', function (e) {
            const img = e.target.closest('.generated-image');
            if (!img) return;
            e.preventDefault();
            openLightbox(img.src);
        });
        document.addEventListener('click', function (e) {
            if (e.target.closest('[data-lightbox-close]')) {
                closeLightbox();
            }
        });
    })();

    // --- Telegram Config Form ---
    window.onTelegramFormSuccess = function() {
        const confirmBtn = document.getElementById('modalConfirm');
        if (confirmBtn) {
            confirmBtn.onclick = null;
        }
        setTimeout(function() {
            const modalRoot = document.getElementById('modalRoot');
            const modalBackdrop = document.getElementById('modalBackdrop');
            if (document.activeElement && modalRoot?.contains(document.activeElement)) {
                document.activeElement.blur();
            }
            modalRoot?.classList.remove('is-open');
            modalRoot?.setAttribute('aria-hidden', 'true');
            modalBackdrop?.classList.remove('is-visible');
            document.body?.classList.remove('modal-open');
        }, 1500);
    };

    window.onTelegramFormError = function() {
        const confirmBtn = document.getElementById('modalConfirm');
        if (confirmBtn) {
            confirmBtn.disabled = false;
        }
    };

    // --- Chat Logic ---
    (function () {
        const chatStreamContainer = document.getElementById('chatStream');
        if (!chatStreamContainer) return;

        (function initSession() {
            const STORAGE_KEY = 'claireStreamSessionId';
            const serverSessionId = chatStreamContainer.getAttribute('data-stream-session-id');
            let sessionId = serverSessionId || window.sessionStorage.getItem(STORAGE_KEY);
            if (sessionId) {
                window.sessionStorage.setItem(STORAGE_KEY, sessionId);
            }
            window.claireStreamSessionId = sessionId;
        })();

        document.addEventListener('DOMContentLoaded', function() {
            const sessionId = window.claireStreamSessionId;
            if (sessionId) initChatEventSource(sessionId);
        });

        function setComposerBusyState(isBusy) {
            const form = document.getElementById('brain_chat');
            if (!form) return;
            const disablers = form.querySelectorAll('.form_disabler');
            disablers.forEach((element) => {
                if (isBusy) element.setAttribute('disabled', true);
                else element.removeAttribute('disabled');
            });
            form.classList.toggle('is-busy', isBusy);
        }

        function getChatBody() { return $('.chat-body'); }
        function scrollChatToBottom() {
            const chatBody = getChatBody();
            if (chatBody) chatBody.scrollTop = chatBody.scrollHeight;
        }

        function finalizeAssistantResponse() {
            const messages = document.getElementById('messages');
            if (messages) {
                const loader = messages.querySelector('[data-role="assistant-loader"]');
                if (loader) loader.remove();
            }
            setComposerBusyState(false);
        }

        function removeAssistantLoader() {
            const messages = document.getElementById('messages');
            if (!messages) return;
            const loader = messages.querySelector('[data-role="assistant-loader"]');
            if (loader) loader.remove();
        }

        function handleChatUpdate(eventType, update) {
            if (eventType === 'message') {
                console.log('message', update);
                return;
            }
            if (eventType === 'chat.assistant.start') {
                //  removeAssistantLoader();
                return;
            }
            if (eventType === 'chat.assistant.done') {
                finalizeAssistantResponse();
                return;
            }

            if (update.html === undefined) {
                return;
            }

            const chatStream= document.getElementById('messages');
            if (!chatStream) {
                console.log('chatStream not found');
                return;
            }

            if (eventType === 'chat.snapshot') {
                chatStream.innerHTML = update.html;
                return;
            }

            if (eventType === 'chat.assistant.placeholder') {
                const loader = chatStream.querySelector('[data-role="assistant-loader"]');
                if (loader) loader.outerHTML = update.html;
                else chatStream.insertAdjacentHTML('beforeend', update.html);
                return;
            }

            if (eventType === 'chat.assistant.update') {
                const element = document.getElementById('message_' + update.messageId);
                if (!element) return;
                element.innerHTML = update.html;
                return;
            }

            if (eventType === 'chat.tool.update') {
                const element = document.getElementById('toolscall_' + update.messageId);
                if (!element) return;
                element.innerHTML = update.html;
                return;
            }

            if (eventType === 'chat.error') {
                finalizeAssistantResponse();
                const messages = document.getElementById('messages');
                if (messages) {
                    messages.insertAdjacentHTML('beforeend', '<article class="message message--received"><div class="message__bubble"><span class="message__text">' + update.error + '</span></div></article>');
                }
            }
        }

        function bindChatEventSource(sessionId, eventSource) {
            window.chatEventSource = eventSource;
            const handleEvent = function(eventType, event) {
                try {
                    const update = JSON.parse(event.data);
                    handleChatUpdate(eventType, update);
                } catch (error) {}
            };
            eventSource.onmessage = function(event) { handleEvent('message', event); };
            eventSource.addEventListener('chat.error', function(event) { handleEvent('chat.error', event); });
            eventSource.addEventListener('chat.snapshot', function(event) { handleEvent('chat.snapshot', event); });
            eventSource.addEventListener('chat.assistant.start', function(event) { handleEvent('chat.assistant.start', event); });
            eventSource.addEventListener('chat.assistant.placeholder', function(event) { handleEvent('chat.assistant.placeholder', event); });
            eventSource.addEventListener('chat.assistant.update', function(event) { handleEvent('chat.assistant.update', event); });
            eventSource.addEventListener('chat.assistant.done', function(event) { handleEvent('chat.assistant.done', event); });
            eventSource.addEventListener('chat.tool.update', function(event) { handleEvent('chat.tool.update', event); });
            eventSource.onerror = function(error) {
                eventSource.close();
                setTimeout(function() { initChatEventSource(sessionId); }, 5000);
            };
        }

        function initChatEventSource(sessionId) {
            if (window.chatEventSource) window.chatEventSource.close();
            const chatId = $('[data-current-chat-id-input]')?.value || '';
            const sseUrl = baseUrl + '/brain/stream?sessionId=' + encodeURIComponent(sessionId) + '&chatId=' + encodeURIComponent(chatId);
            bindChatEventSource(sessionId, new EventSource(sseUrl));
        }

        window.chatClick = function chatClick(event) {
            const form = event.target;
            setComposerBusyState(true);
            const input = form.querySelector('[name="message"]');
            if (!input) return;
            const text = (input.value || '').trim();
            if (!text) return;
            const messages = document.getElementById('messages');
            const scrollBtn = document.getElementById('scrollDownBtn');
            if (!messages) return;
            const time = new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
            const wrapper = document.createElement('div');
            wrapper.innerHTML = '<article class="message message--sent"><div class="message__bubble"></div><span class="message__meta"></span></article>';
            const article = wrapper.firstElementChild;
            article.querySelector('.message__bubble').textContent = text;
            article.querySelector('.message__meta').textContent = time + ' • Vous';
            messages.appendChild(article);
            const loader = document.createElement('article');
            loader.className = 'message';
            loader.id = 'assistant-loader';
            loader.setAttribute('data-role', 'assistant-loader');
            loader.innerHTML = '<div class="message__bubble"><span class="typing-indicator" aria-hidden="true"><span class="typing-indicator__dot"></span><span class="typing-indicator__dot"></span><span class="typing-indicator__dot"></span></span></div>';
            messages.appendChild(loader);
            scrollChatToBottom();
            if (scrollBtn) scrollBtn.style.display = 'none';
        };

        function autoResizeComposer(input) {
            if (!input) return;
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 160) + 'px';
        }

        function updateComposerToggleState(input) {
            if (!input) return;
            const form = input.closest('form');
            if (!form) return;
            form.classList.toggle('chat-input__form--typing', input.value.trim() !== '');
        }

        window.clearInput = function clearInput(event) {
            const detail = event.detail || {};
            if (!detail.successful) return;
            const form = detail.elt instanceof HTMLFormElement ? detail.elt : (event.target instanceof HTMLFormElement ? event.target : null);
            if (!form) return;
            form.reset();
            $$('.chat-chip__remove').forEach(el => removeChatChip(el));
            const input = form.querySelector('[name="message"]');
            autoResizeComposer(input);
            updateComposerToggleState(input);
        };

        window.updatePersistentChatStream = function updatePersistentChatStream(chatId) {
            const chatStreamContainer = document.getElementById('chatStream');
            const messagesContainer = document.getElementById('messages');
            const chatIdInput = $('[data-current-chat-id-input]');
            const sessionId = window.claireStreamSessionId;
            if (!chatStreamContainer || !chatId || !sessionId) return;
            chatStreamContainer.setAttribute('data-chat-id', chatId);
            if (messagesContainer) messagesContainer.setAttribute('data-chat-id', chatId);
            if (chatIdInput) chatIdInput.value = chatId;
            initChatEventSource(sessionId);
        };

        function ensureToolNode(articleTarget, toolCallId) {
            const existing = document.getElementById(toolCallId);
            if (existing) return existing;
            const bubble = articleTarget.closest('.message')?.querySelector('.message__bubble') || articleTarget.parentElement;
            if (!bubble) return null;
            const placeholderHtml = '<div class="message__subbubble message__subbubble--toolcall">'
                + '<details class="toolcall">'
                + '<summary class="toolcall__summary" aria-label="Appels d\'outils" title="Appels d\'outils">'
                + '<svg class="toolcall__icon toolcall__icon--tool" width="18" height="18" viewBox="0 0 128 128" role="img" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M114 15.25H14A9.761 9.761 0 0 0 4.25 25v77A10.762 10.762 0 0 0 15 112.75h98A10.762 10.762 0 0 0 123.75 102V25a9.761 9.761 0 0 0-9.75-9.75zm6.25 9.75v11.683H52.888L61.37 18.75H114a6.257 6.257 0 0 1 6.25 6.25zM14 18.75h43.5l-8.484 17.933H7.75V25A6.257 6.257 0 0 1 14 18.75zm76.1 90.5-22.287-22.3a1.75 1.75 0 0 0-2.476 2.475l19.809 19.825h-8.871L58.627 91.593a1.762 1.762 0 0 0-1.908-.571 18.762 18.762 0 0 1-23.447-22.754l12.991 12.989a1.753 1.753 0 0 0 1.18.512c.368.021 3.7-.014 8.193-4.51s4.516-7.825 4.5-8.192a1.747 1.747 0 0 0-.512-1.174L46.637 54.9a18.751 18.751 0 0 1 22.745 23.49 1.743 1.743 0 0 0 .563 1.838l29.02 29.02zm22.905 0h-9.085l-30.9-30.9a22.248 22.248 0 0 0-30.281-25.8 1.749 1.749 0 0 0-.554 2.848l14.339 14.341a12.036 12.036 0 0 1-3.362 5.043 12.231 12.231 0 0 1-5.051 3.374L33.767 63.813a1.75 1.75 0 0 0-2.849.554 22.258 22.258 0 0 0 25.811 30.278l14.6 14.605H15A7.258 7.258 0 0 1 7.75 102V40.183h112.5V102a7.258 7.258 0 0 1-7.25 7.25z"/><path d="M21.57 33.466a5.75 5.75 0 1 0-5.75-5.75 5.756 5.756 0 0 0 5.75 5.75zm0-8a2.25 2.25 0 1 1-2.25 2.25 2.253 2.253 0 0 1 2.25-2.25zM37.626 33.466a5.75 5.75 0 1 0-5.75-5.75 5.756 5.756 0 0 0 5.75 5.75zm0-8a2.25 2.25 0 1 1-2.25 2.25 2.253 2.253 0 0 1 2.25-2.25zM67.522 29.466h44.745a1.75 1.75 0 0 0 0-3.5H67.522a1.75 1.75 0 0 0 0 3.5z"/></g></svg>'
                + '<svg class="toolcall__icon toolcall__icon--spinner" width="18" height="18" viewBox="0 0 24 24" role="img" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" opacity="0.25" /><path d="M21 12a9 9 0 0 0-9-9" stroke-linecap="round"><animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="1s" repeatCount="indefinite" /></path></g></svg>'
                + '<svg class="toolcall__icon toolcall__icon--done" width="18" height="18" viewBox="0 0 24 24" role="img" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9" opacity="0.25" /><path d="M8 12l2.5 2.5L16 9" /></g></svg>'
                + '<svg class="toolcall__chevron" width="14" height="14" viewBox="0 0 24 24" role="img" aria-hidden="true"><path d="M8 10l4 4 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                + '<span class="visually-hidden">Appels d\'outils</span>'
                + '</summary>'
                + '<div class="toolcall__text" id="' + toolCallId + '"></div>'
                + '</details>'
                + '</div>';
            bubble.insertAdjacentHTML('afterbegin', placeholderHtml);
            return document.getElementById(toolCallId);
        }

        window.handleLastExchangeResponse = function handleLastExchangeResponse(event) {
            const detail = event.detail || {};
            if (!detail.successful) { setComposerBusyState(false); return; }
            try {
                const payload = JSON.parse(detail.xhr.responseText || '{}');
                if (payload.chatId && window.updatePersistentChatStream) window.updatePersistentChatStream(payload.chatId);
            } catch (e) {}
            scrollChatToBottom();
            setComposerBusyState(false);
        };

        function $(sel, root=document) { return root.querySelector(sel); }
        function chipSelectorById(id) { return '.chat-chip[data-file-id="' + CSS.escape(String(id)) + '"]' }

        const containers = {
            chat: {
                chips: $('#chatAttachedFilesChat'),
                hidden: $('#chatAttachedFilesChatHidden'),
                fileInput: $('#chat-upload'),
            },
            stream: {
                chips: $('#chatAttachedFilesStream'),
                hidden: $('#chatAttachedFilesStreamHidden'),
                fileInput: $('#chat-upload-stream'),
            }
        };

        function svgFileIcon() { return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h7l5 5v15H6z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M13 2v6h6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>'; }
        function removeBtn() { return '<button type="button" class="chat-chip__remove" aria-label="Retirer" title="Retirer"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>'; }

        function createIdChip(fileId, fileName) {
            const chip = document.createElement('span');
            chip.className = 'chat-chip form_disabler';
            chip.setAttribute('data-type', 'id');
            chip.setAttribute('data-file-id', String(fileId));
            chip.title = fileName || '';
            chip.setAttribute('aria-label', fileName || 'Fichier');
            chip.innerHTML = svgFileIcon() + removeBtn();
            return chip;
        }

        function createLocalChip(tmpKey, file) {
            const chip = document.createElement('span');
            chip.className = 'chat-chip';
            chip.setAttribute('data-type', 'local');
            chip.setAttribute('data-tmp-key', tmpKey);
            chip.title = file.name;
            chip.setAttribute('aria-label', file.name);
            chip.innerHTML = svgFileIcon() + removeBtn();
            return chip;
        }

        function ensureHiddenId(hiddenContainer, fileId) {
            if (!hiddenContainer) return;
            const sel = 'input[type="hidden"][name="file_ids[]"][value="' + CSS.escape(String(fileId)) + '"]';
            if (hiddenContainer.querySelector(sel)) return;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'file_ids[]';
            input.value = String(fileId);
            hiddenContainer.appendChild(input);
        }

        function removeHiddenId(hiddenContainer, fileId) {
            if (!hiddenContainer) return;
            const sel = 'input[type="hidden"][name="file_ids[]"][value="' + CSS.escape(String(fileId)) + '"]';
            const node = hiddenContainer.querySelector(sel);
            if (node) node.remove();
        }

        function removeFromFileInput(fileInput, tmpKeyToRemove) {
            if (!fileInput || !fileInput.files || !fileInput.files.length) return;
            const dt = new DataTransfer();
            for (const file of fileInput.files) {
                const key = makeTmpKey(file);
                if (key !== tmpKeyToRemove) dt.items.add(file);
            }
            fileInput.files = dt.files;
        }

        function makeTmpKey(file) { return [file.name, file.size, file.type, file.lastModified].join('|'); }

        document.addEventListener('click', function (e) {
            const btn = e.target && e.target.closest ? e.target.closest('[data-add-file-id]') : null;
            if (!btn) return;
            const fileId = btn.getAttribute('data-add-file-id');
            const fileName = btn.getAttribute('data-add-file-name') || '';
            for (const mode of Object.keys(containers)) {
                const { chips, hidden } = containers[mode];
                if (!chips) continue;
                if (!chips.querySelector(chipSelectorById(fileId))) {
                    chips.appendChild(createIdChip(fileId, fileName));
                    ensureHiddenId(hidden, fileId);
                }
            }
        });

        for (const mode of Object.keys(containers)) {
            const { chips, fileInput } = containers[mode];
            if (!fileInput || !chips) continue;
            fileInput.addEventListener('change', function () {
                if (!fileInput.files || !fileInput.files.length) return;
                for (const file of fileInput.files) {
                    const tmpKey = makeTmpKey(file);
                    if (chips.querySelector('.chat-chip[data-type="local"][data-tmp-key="' + CSS.escape(tmpKey) + '"]')) continue;
                    chips.appendChild(createLocalChip(tmpKey, file));
                }
            });
        }

        const messageInput = $('.chat-input__field');
        if (messageInput) {
            autoResizeComposer(messageInput);
            updateComposerToggleState(messageInput);
            messageInput.addEventListener('input', function () {
                autoResizeComposer(messageInput);
                updateComposerToggleState(messageInput);
            });
            messageInput.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' || event.shiftKey) return;
                event.preventDefault();
                const form = messageInput.closest('form');
                if (!form || !messageInput.value.trim()) return;
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            });
        }

        window.removeChatChip = function removeChatChip(e) {
            const rem = e && e.closest ? e.closest('.chat-chip__remove') : null;
            if (!rem) return;
            const chip = rem.closest('.chat-chip');
            if (!chip) return;
            const type = chip.getAttribute('data-type');
            if (type === 'id') {
                const fid = chip.getAttribute('data-file-id');
                removeHiddenId(containers.chat.hidden, fid);
                removeHiddenId(containers.stream.hidden, fid);
                for (const mode of Object.keys(containers)) {
                    const list = containers[mode].chips;
                    if (!list) continue;
                    const dup = list.querySelector(chipSelectorById(fid));
                    if (dup) dup.remove();
                }
            } else if (type === 'local') {
                const tmpKey = chip.getAttribute('data-tmp-key');
                removeFromFileInput(containers.chat.fileInput, tmpKey);
                removeFromFileInput(containers.stream.fileInput, tmpKey);
                for (const mode of Object.keys(containers)) {
                    const list = containers[mode].chips;
                    if (!list) continue;
                    list.querySelectorAll('.chat-chip[data-type="local"][data-tmp-key="' + CSS.escape(tmpKey) + '"]').forEach(node => node.remove());
                }
            }
        }

        document.addEventListener('click', function (e) { window.removeChatChip(e.target); });

        // Scroll management
        const chatBody = $('.chat-body');
        const messages = document.getElementById('messages');
        const scrollBtn = document.getElementById('scrollDownBtn');
        if (chatBody && scrollBtn) {
            const threshold = 24;
            function atBottomOf(el) { return el.scrollTop + el.clientHeight >= el.scrollHeight - threshold; }
            function updateButtonVisibility() {
                if (chatBody.scrollHeight <= chatBody.clientHeight + 1) {
                    scrollBtn.style.display = 'none';
                    return;
                }
                const atBottom = atBottomOf(chatBody);
                scrollBtn.style.display = atBottom ? 'none' : 'inline-flex';
                if (!atBottom) {
                    scrollBtn.style.alignItems = 'center';
                    scrollBtn.style.justifyContent = 'center';
                }
            }
            chatBody.addEventListener('scroll', updateButtonVisibility, {passive: true});
            window.addEventListener('resize', updateButtonVisibility);
            scrollBtn.addEventListener('click', function () {
                chatBody.scrollTo({top: chatBody.scrollHeight, behavior: 'smooth'});
            });
            window.addEventListener('DOMContentLoaded', updateButtonVisibility);
            document.body.addEventListener('htmx:afterSwap', function (evt) {
                const detail = evt.detail || {};
                const target = detail.target;
                if (target && (target.id === 'messages' || (target.closest && target.closest('#messages')))) {
                    updateButtonVisibility();
                }
            });
            if (window.MutationObserver && messages) {
                const mo = new MutationObserver(updateButtonVisibility);
                mo.observe(messages, {childList: true, subtree: false});
            }
            updateButtonVisibility();
        }
    })();
})();
