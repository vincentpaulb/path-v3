(function () {
    const shellSelector = '.path-pagination-shell[data-pagination-name]';
    let activeRequest = null;

    const findShell = (root, pageName) => [...root.querySelectorAll(shellSelector)]
        .find(shell => shell.dataset.paginationName === pageName);

    const findContainer = shell => shell?.closest('[data-async-pagination], section');

    async function updatePagination(url, pageName, historyMode = 'push') {
        const currentShell = findShell(document, pageName);
        const currentContainer = findContainer(currentShell);

        if (!currentShell || !currentContainer) {
            window.location.assign(url);
            return;
        }

        activeRequest?.abort();
        const requestController = new AbortController();
        activeRequest = requestController;
        currentContainer.classList.add('is-pagination-loading');
        currentContainer.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(url, {
                headers: {
                    'Accept': 'text/html',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                signal: requestController.signal
            });

            if (!response.ok) throw new Error(`Pagination request failed (${response.status})`);

            const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
            const nextShell = findShell(parsed, pageName);
            const nextContainer = findContainer(nextShell);

            if (!nextShell || !nextContainer) throw new Error('Pagination content was not found.');

            currentContainer.innerHTML = nextContainer.innerHTML;
            currentContainer.classList.remove('is-pagination-loading');
            currentContainer.removeAttribute('aria-busy');

            if (historyMode === 'push') {
                if (!history.state?.pathAsyncPagination) {
                    history.replaceState({ pathAsyncPagination: true, pageName }, '', window.location.href);
                }
                history.pushState({ pathAsyncPagination: true, pageName }, '', url);
            }

            document.dispatchEvent(new CustomEvent('path:pagination-updated', {
                detail: { container: currentContainer, pageName, url }
            }));

            const activePage = findShell(document, pageName)?.querySelector('[aria-current="page"]');
            if (activePage) {
                activePage.setAttribute('tabindex', '-1');
                activePage.focus({ preventScroll: true });
            }
        } catch (error) {
            if (error.name === 'AbortError') return;
            window.location.assign(url);
        } finally {
            if (activeRequest === requestController) {
                activeRequest = null;
                currentContainer.classList.remove('is-pagination-loading');
                currentContainer.removeAttribute('aria-busy');
            }
        }
    }

    document.addEventListener('click', event => {
        const link = event.target.closest(`${shellSelector} a[href]`);
        if (!link || event.defaultPrevented || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const destination = new URL(link.href, window.location.href);
        if (destination.origin !== window.location.origin) return;

        event.preventDefault();
        updatePagination(destination.href, link.closest(shellSelector).dataset.paginationName);
    });

    window.addEventListener('popstate', event => {
        if (!event.state?.pathAsyncPagination || !event.state.pageName) return;
        updatePagination(window.location.href, event.state.pageName, 'pop');
    });
})();
