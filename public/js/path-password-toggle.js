(function () {
    'use strict';

    const eyeOpen = `
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M2.4 12s3.5-6 9.6-6 9.6 6 9.6 6-3.5 6-9.6 6-9.6-6-9.6-6Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="12" cy="12" r="2.6" fill="none" stroke="currentColor" stroke-width="1.8"/>
        </svg>`;

    const eyeClosed = `
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M3 3l18 18M10.6 6.2A10.8 10.8 0 0 1 12 6c6.1 0 9.6 6 9.6 6a15.8 15.8 0 0 1-2.5 3.2M6.2 6.2C3.8 7.8 2.4 12 2.4 12s3.5 6 9.6 6c1.7 0 3.2-.5 4.5-1.2M9.9 9.9a3 3 0 0 0 4.2 4.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>`;

    const enhanced = new Set();

    function resetButtonState(input, button) {
        input.type = 'password';
        button.setAttribute('aria-label', 'Show password');
        button.setAttribute('aria-pressed', 'false');
        button.title = 'Show password';
        button.innerHTML = eyeOpen;
    }

    function syncVisibility(input, button) {
        const hasValue = input.value.length > 0;
        button.hidden = !hasValue;
        button.classList.toggle('is-visible', hasValue);
        // Some application CSS sets display on buttons and can override the browser's
        // default [hidden] rule. Set it explicitly so empty password fields never show an eye.
        button.style.display = hasValue ? 'inline-flex' : 'none';

        if (!hasValue && input.type !== 'password') {
            resetButtonState(input, button);
        }
    }

    function enhance(input) {
        if (!(input instanceof HTMLInputElement) || input.dataset.passwordVisibilityReady === 'true') return;
        if (input.type !== 'password') return;

        input.dataset.passwordVisibilityReady = 'true';

        const wrapper = document.createElement('div');
        wrapper.className = 'password-visibility-field';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'password-visibility-toggle';
        button.setAttribute('aria-label', 'Show password');
        button.setAttribute('aria-pressed', 'false');
        button.title = 'Show password';
        button.innerHTML = eyeOpen;

        button.addEventListener('click', () => {
            if (!input.value) return;

            const revealing = input.type === 'password';
            input.type = revealing ? 'text' : 'password';
            button.setAttribute('aria-label', revealing ? 'Hide password' : 'Show password');
            button.setAttribute('aria-pressed', revealing ? 'true' : 'false');
            button.title = revealing ? 'Hide password' : 'Show password';
            button.innerHTML = revealing ? eyeClosed : eyeOpen;
            input.focus({ preventScroll: true });
            try {
                const end = input.value.length;
                input.setSelectionRange(end, end);
            } catch (_) {}
        });

        const sync = () => syncVisibility(input, button);
        input.addEventListener('input', sync);
        input.addEventListener('change', sync);
        wrapper.appendChild(button);
        enhanced.add({ input, button });
        sync();
    }

    function enhanceAll(root) {
        if (root instanceof HTMLInputElement && root.type === 'password') enhance(root);
        root.querySelectorAll?.('input[type="password"]').forEach(enhance);
    }

    function syncAll() {
        enhanced.forEach(entry => {
            if (!entry.input.isConnected || !entry.button.isConnected) {
                enhanced.delete(entry);
                return;
            }
            syncVisibility(entry.input, entry.button);
        });
    }

    function boot() {
        enhanceAll(document);
        syncAll();

        const observer = new MutationObserver(records => {
            records.forEach(record => record.addedNodes.forEach(node => {
                if (node.nodeType === Node.ELEMENT_NODE) enhanceAll(node);
            }));
            syncAll();
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });

        // Generator buttons set .value programmatically, which does not fire an input event.
        // Sync after clicks so the eye appears immediately for generated passwords too.
        document.addEventListener('click', () => window.setTimeout(syncAll, 0));
        document.addEventListener('reset', () => window.setTimeout(syncAll, 0), true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
