(() => {
    'use strict';
    const dialog = document.getElementById('staffLoginDialog');
    const openButton = document.getElementById('staffSignInButton');
    if (!dialog || !openButton || typeof dialog.showModal !== 'function') return;

    const closeButton = dialog.querySelector('.public-login-close');
    const username = dialog.querySelector('input[name="username"]');
    const loginPanel = dialog.querySelector('[data-login-panel]');
    const loginHeading = dialog.querySelector('[data-login-heading]');
    const forgotPanel = dialog.querySelector('[data-forgot-panel]');
    const showView = view => {
        const forgot = view === 'forgot';
        loginPanel.hidden = forgot;
        loginHeading.hidden = forgot;
        forgotPanel.hidden = !forgot;
        window.setTimeout(() => (forgot ? forgotPanel.querySelector('input') : username)?.focus({ preventScroll: true }), 0);
    };
    const open = () => {
        if (!dialog.open) dialog.showModal();
        window.setTimeout(() => (forgotPanel.hidden ? username : forgotPanel.querySelector('input'))?.focus({ preventScroll: true }), 0);
    };
    const close = () => {
        dialog.close();
        openButton.focus({ preventScroll: true });
    };

    openButton.addEventListener('click', open);
    dialog.querySelector('[data-show-forgot]')?.addEventListener('click', () => showView('forgot'));
    dialog.querySelector('[data-show-login]')?.addEventListener('click', () => showView('login'));
    closeButton.addEventListener('click', close);
    dialog.addEventListener('click', event => {
        const box = dialog.getBoundingClientRect();
        const outside = event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom;
        if (outside) close();
    });
    dialog.addEventListener('cancel', event => {
        event.preventDefault();
        close();
    });
    if (dialog.dataset.initialView === 'forgot') showView('forgot');
    if (dialog.hasAttribute('data-open-on-load')) open();
})();
