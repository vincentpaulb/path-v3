(() => {
    'use strict';
    const fallback = document.getElementById('mapUnavailable');
    const provinceColors = {
        cebu: '#196fc0', bohol: '#b45309', negros: '#7c3bb4', siquijor: '#00856a',
        'negros oriental': '#7c3bb4', 'negros occidental': '#be346b'
    };
    const provinceColor = province => {
        const name = (province || '').trim().toLowerCase();
        if (provinceColors[name]) return provinceColors[name];
        let hash = 0;
        for (const letter of name) hash = (hash * 31 + letter.charCodeAt(0)) >>> 0;
        return `hsl(${hash % 360}, 65%, 37%)`;
    };
    const sharedColor = provinces => {
        const names = [...new Set(provinces.map(name => (name || '').trim().toLowerCase()))];
        return names.length === 1 ? provinceColor(names[0]) : '#475569';
    };
    const applyProvinceColors = root => {
        root.querySelectorAll('[data-province-color]').forEach(item => {
            item.style.setProperty('--province-color', provinceColor(item.dataset.provinceColor));
        });
    };
    applyProvinceColors(document);
    const reportDialog = document.getElementById('directoryReportDialog');
    const reportForm = document.getElementById('directoryReportForm');
    let reportKey = '';
    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-report-key]');
        if (!trigger) return;
        reportKey = trigger.dataset.reportKey;
        reportForm.reset();
        document.getElementById('directoryReportName').textContent = trigger.dataset.reportName;
        document.getElementById('directoryReportStatus').textContent = '';
        reportDialog.showModal();
    });
    document.getElementById('directoryReportClose')?.addEventListener('click', () => reportDialog.close());
    reportForm?.addEventListener('submit', async event => {
        event.preventDefault();
        const status = document.getElementById('directoryReportStatus');
        const submit = reportForm.querySelector('[type=submit]');
        submit.disabled = true;
        status.textContent = 'Sending report…';
        try {
            const response = await fetch(`${window.publicDirectory.reportUrl}/${encodeURIComponent(reportKey)}/report`, {
                method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: new FormData(reportForm)
            });
            const result = await response.json();
            if (!response.ok) throw new Error(response.status === 429 ? 'Please wait a few minutes before sending another report.' : (result.message || 'Unable to send report. Please try again.'));
            status.textContent = result.message;
            reportForm.reset();
        } catch (error) { status.textContent = error.message; }
        finally { submit.disabled = false; }
    });
    const legend = document.querySelector('.directory-legend');
    if (legend) {
        legend.open = !window.matchMedia('(max-width: 850px)').matches;
        legend.addEventListener('keydown', event => {
            if (event.key === 'Escape' && legend.open) {
                legend.open = false;
                legend.querySelector('summary').focus();
                event.stopPropagation();
            }
        });
    }
    const selection = document.getElementById('directorySelection');
    const list = document.getElementById('directoryResults');
    const listToggle = document.getElementById('directoryListToggle');
    const setListVisible = visible => {
        list.hidden = !visible;
        listToggle.setAttribute('aria-expanded', String(visible));
        listToggle.textContent = visible ? (window.matchMedia('(max-width: 850px)').matches ? 'Back to map' : 'Hide list') : 'Browse proponents';
    };
    listToggle.hidden = false;
    setListVisible(!window.matchMedia('(max-width: 850px)').matches);
    listToggle.addEventListener('click', () => setListVisible(list.hidden));
    const mobileLayout = window.matchMedia('(max-width: 850px)');
    const filterToggle = document.getElementById('directoryFilterToggle');
    const filters = document.getElementById('directoryFilters');
    const setFiltersVisible = visible => {
        filters.hidden = !visible;
        filterToggle.setAttribute('aria-expanded', String(visible));
        filterToggle.textContent = visible ? 'Hide search and filters' : 'Search and filters';
    };
    filterToggle.hidden = !mobileLayout.matches;
    setFiltersVisible(!mobileLayout.matches);
    filterToggle.addEventListener('click', () => setFiltersVisible(filters.hidden));
    mobileLayout.addEventListener('change', () => {
        setListVisible(!mobileLayout.matches);
        filterToggle.hidden = !mobileLayout.matches;
        setFiltersVisible(!mobileLayout.matches);
    });
    if (!window.L || !window.publicDirectory) {
        fallback.hidden = false;
        selection.textContent = 'Map unavailable. Names, addresses, and filters remain available in the directory.';
        return;
    }
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const map = L.map('publicProjectMap', { scrollWheelZoom: true, zoomSnap: 0.25, zoomAnimation: !reducedMotion, fadeAnimation: !reducedMotion }).setView([10.0, 123.6], 8);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).on('tileerror', () => { fallback.hidden = false; }).addTo(map);
    const clearSelection = () => {
        document.querySelectorAll('.directory-card-selected').forEach(card => card.classList.remove('directory-card-selected'));
        document.querySelectorAll('.directory-show-map[aria-pressed]').forEach(button => button.setAttribute('aria-pressed', 'false'));
    };
    const pinIcon = (color, projects) => {
        const industries = new Set(projects.map(project => project.industry_sector));
        const pin = document.createElement('span');
        pin.className = 'directory-industry-pin';
        pin.style.setProperty('--province-color', color);
        pin.setAttribute('aria-hidden', 'true');
        const symbol = document.createElement('i');
        const icon = industries.size > 1 ? 'fa-layer-group' : projects[0].industry_icon;
        symbol.classList.add('fa-solid', /^fa-[a-z-]+$/.test(icon || '') ? icon : 'fa-location-dot');
        pin.append(symbol);
        return L.divIcon({
            className: 'directory-province-pin', html: pin,
            iconSize: [32, 40], iconAnchor: [16, 39], popupAnchor: [0, -36], tooltipAnchor: [16, -24]
        });
    };
    const markerLayer = L.markerClusterGroup ? L.markerClusterGroup({
        maxClusterRadius: 45,
        showCoverageOnHover: false,
        // Keep marker membership synchronous: delayed cluster transitions can
        // remove a marker immediately after a shared-link popup is opened.
        animate: false,
        iconCreateFunction: cluster => {
            const children = cluster.getAllChildMarkers();
            const count = children.reduce((sum, marker) => sum + marker.options.projectCount, 0);
            const color = sharedColor(children.flatMap(marker => marker.options.provinces));
            return L.divIcon({
                html: `<span style="background:${color}">${count}</span>`, className: 'directory-cluster',
                iconSize: [38, 38], iconAnchor: [19, 19]
            });
        }
    }).addTo(map) : L.layerGroup().addTo(map);
    const groups = new Map();
    window.publicDirectory.locations.forEach(project => {
        const coordinates = project.coordinates;
        if (!Array.isArray(coordinates) || coordinates.length !== 2 || !coordinates.every(Number.isFinite)) return;
        const key = coordinates.join(',');
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(project);
    });
    const bounds = [];
    const fitLocations = () => {
        const frame = window.publicDirectory.initialBounds?.length ? window.publicDirectory.initialBounds : bounds;
        if (frame.length) map.fitBounds(frame, {
            paddingTopLeft: [20, 70],
            paddingBottomRight: [20, 70], maxZoom: 11, animate: false
        });
        else map.setView([10.0, 123.6], 8);
    };
    const projectMarkers = new Map();
    const popupOptions = () => ({
        className: 'directory-pin-popup',
        minWidth: Math.max(160, Math.min(400, map.getSize().x - 72)),
        maxWidth: Math.max(160, Math.min(400, map.getSize().x - 72)),
        maxHeight: Math.max(120, map.getSize().y - 140),
        autoPanPadding: [16, 20]
    });
    groups.forEach(projects => {
        const content = document.createElement('div');
        const entries = new Map();
        content.className = 'directory-pin-details';
        L.DomEvent.disableScrollPropagation(content);
        if (projects.length > 1) {
            const count = document.createElement('div');
            count.className = 'directory-pin-count';
            count.textContent = `${projects.length} projects at this location`;
            content.append(count);
        }
        projects.forEach(project => {
            const entry = document.createElement('section');
            entry.className = 'directory-pin-project';
            entries.set(project.map_key, entry);
            const badge = document.createElement('span');
            badge.className = 'directory-pin-badge';
            badge.textContent = 'Funded project';
            const heading = document.createElement('h3');
            heading.textContent = project.proponent;
            const details = document.createElement('dl');
            [['Address', project.address], ['Province', project.province], ['Program sector', project.sector], ['Industry sector', project.industry_sector], ['Location accuracy', project.location_accuracy]].forEach(([label, value]) => {
                const row = document.createElement('div');
                const term = document.createElement('dt');
                term.textContent = label;
                const description = document.createElement('dd');
                description.textContent = value || 'Not specified';
                row.append(term, description);
                details.append(row);
            });
            entry.append(badge, heading, details);
            if (project.photo || project.highlight) {
                const highlight = document.createElement('div');
                highlight.className = 'directory-highlight';
                if (project.photo) {
                    const photo = document.createElement('img');
                    photo.src = project.photo;
                    photo.alt = project.photo_alt || `Project photo for ${project.proponent}`;
                    photo.loading = 'lazy';
                    highlight.append(photo);
                }
                if (project.highlight) {
                    const summary = document.createElement('p');
                    summary.textContent = project.highlight;
                    highlight.append(summary);
                }
                entry.append(highlight);
            }
            const report = document.createElement('button');
            report.type = 'button';
            report.className = 'directory-report-button';
            report.dataset.reportKey = project.map_key;
            report.dataset.reportName = project.proponent;
            report.textContent = 'Report incorrect information';
            const share = document.createElement('a');
            share.className = 'directory-share-link';
            const shareUrl = new URL(window.publicDirectory.url, window.location.origin);
            shareUrl.hash = `project=${project.map_key}`;
            share.href = shareUrl.href;
            share.textContent = 'Share project link';
            share.title = 'Copy a link to this project';
            share.addEventListener('click', async event => {
                if (!navigator.clipboard?.writeText) return;
                event.preventDefault();
                try {
                    await navigator.clipboard.writeText(share.href);
                    share.textContent = 'Link copied';
                    selection.textContent = 'Project link copied. Anyone with the link can open this public map location.';
                } catch {
                    share.textContent = 'Right-click or long-press to copy link';
                }
            });
            const actions = document.createElement('div');
            actions.className = 'directory-pin-actions';
            actions.append(report, share);
            entry.append(actions);
            content.append(entry);
        });
        const coordinates = projects[0].coordinates;
        const provinces = projects.map(project => project.province);
        const marker = L.marker(coordinates, { icon: pinIcon(sharedColor(provinces), projects), provinces, projectCount: projects.length, title: projects.map(project => `${project.proponent} (${project.province}) — ${project.industry_sector}`).join('; ') })
            .bindPopup(content, popupOptions());
        markerLayer.addLayer(marker);
        marker.on('popupopen', () => {
            marker.getElement()?.classList.add('directory-marker-selected');
            const keys = new Set(projects.map(project => project.map_key));
            document.querySelectorAll('[data-map-project]').forEach(card => {
                if (!keys.has(card.dataset.mapProject)) return;
                card.classList.add('directory-card-selected');
                card.querySelector('.directory-show-map').setAttribute('aria-pressed', 'true');
            });
        });
        marker.on('popupclose', () => {
            marker.getElement()?.classList.remove('directory-marker-selected');
            clearSelection();
        });
        marker.on('click', () => {
            content.querySelectorAll('.directory-pin-project, .directory-pin-count').forEach(entry => { entry.hidden = false; });
            marker.getPopup()?.update();
            clearSelection();
            const keys = new Set(projects.map(project => project.map_key));
            document.querySelectorAll('[data-map-project]').forEach(card => {
                if (!keys.has(card.dataset.mapProject)) return;
                card.classList.add('directory-card-selected');
                card.querySelector('.directory-show-map').setAttribute('aria-pressed', 'true');
            });
            selection.textContent = projects.length === 1
                ? `Selected: ${projects[0].proponent} · ${projects[0].province}`
                : `${projects.length} projects share this location. Their details are available in the popup.`;
        });
        projects.forEach(project => projectMarkers.set(project.map_key, { marker, coordinates, content, name: project.proponent, entry: entries.get(project.map_key) }));
        bounds.push(coordinates);
    });
    fitLocations();
    const reset = document.getElementById('directoryMapReset');
    if (reset) {
        reset.disabled = false;
        reset.addEventListener('click', () => {
            map.closePopup();
            clearSelection();
            selection.textContent = 'Showing all matching locations. Choose a proponent or pin to explore.';
            map.invalidateSize();
            fitLocations();
        });
    }
    if (window.ResizeObserver) {
        const observer = new ResizeObserver(() => map.invalidateSize({ pan: false }));
        observer.observe(document.getElementById('publicProjectMap'));
    }
    const openProposalDetails = (target, key) => {
        // A standalone popup owns its own content and is not removed when
        // MarkerCluster replaces or temporarily detaches the source marker.
        const content = document.createElement('div');
        content.className = 'directory-pin-details';
        const entry = target.entry.cloneNode(true);
        entry.hidden = false;
        const share = entry.querySelector('.directory-share-link');
        if (share) {
            share.textContent = 'Share project link';
            share.addEventListener('click', async event => {
                if (!navigator.clipboard?.writeText) return;
                event.preventDefault();
                try {
                    await navigator.clipboard.writeText(share.href);
                    share.textContent = 'Link copied';
                } catch {
                    share.textContent = 'Right-click or long-press to copy link';
                }
            });
        }
        content.append(entry);
        L.DomEvent.disableScrollPropagation(content);
        const popup = L.popup({
            ...popupOptions(),
            closeOnClick: false, offset: [0, -36]
        }).setLatLng(target.coordinates).setContent(content).openOn(map);
        target.marker.getElement()?.classList.add('directory-marker-selected');
        popup.on('remove', () => {
            target.marker.getElement()?.classList.remove('directory-marker-selected');
            clearSelection();
        });
        clearSelection();
        document.querySelectorAll('[data-map-project]').forEach(card => {
            if (card.dataset.mapProject !== key) return;
            card.classList.add('directory-card-selected');
            card.querySelector('.directory-show-map').setAttribute('aria-pressed', 'true');
        });
    };
    const bindDirectoryCards = () => {
        applyProvinceColors(list);
        document.querySelectorAll('[data-map-project]').forEach(card => {
            const target = projectMarkers.get(card.dataset.mapProject);
            if (!target) return;
            const button = card.querySelector('.directory-show-map');
            button.disabled = false;
            button.setAttribute('aria-pressed', 'false');
            button.setAttribute('aria-controls', 'publicProjectMap');
            card.classList.add('directory-card-clickable');
            card.addEventListener('click', event => {
                if (event.target.closest('details, [data-report-key], a')) return;
                clearSelection();
                card.classList.add('directory-card-selected');
                button.setAttribute('aria-pressed', 'true');
                selection.textContent = `Selected: ${card.querySelector('h3').textContent}. Location details are open on the map.`;
                if (window.matchMedia('(max-width: 850px)').matches) {
                    setListVisible(false);
                    listToggle.focus({ preventScroll: true });
                }
                map.stop();
                map.invalidateSize({ animate: false, pan: false });
                map.setView(target.coordinates, 17, { animate: false });
                const openDetails = () => {
                    openProposalDetails(target, card.dataset.mapProject);
                };
                if (markerLayer.zoomToShowLayer) markerLayer.zoomToShowLayer(target.marker, openDetails);
                else openDetails();
            });
        });
    };
    bindDirectoryCards();
    const openSharedProject = () => {
        const key = new URLSearchParams(window.location.hash.slice(1)).get('project');
        if (!key) return;
        const target = projectMarkers.get(key);
        if (!target) {
            selection.textContent = 'This project location is no longer available in these public results.';
            return;
        }
        if (window.matchMedia('(max-width: 850px)').matches) setListVisible(false);
        map.stop();
        map.invalidateSize({ animate: false, pan: false });
        map.setView(target.coordinates, 17, { animate: false });
        const open = () => {
            openProposalDetails(target, key);
            selection.textContent = `Selected: ${target.name}. Public project details are open on the map.`;
        };
        if (markerLayer.zoomToShowLayer) markerLayer.zoomToShowLayer(target.marker, open);
        else open();
    };
    openSharedProject();
    window.addEventListener('hashchange', openSharedProject);
    mobileLayout.addEventListener('change', () => {
        requestAnimationFrame(() => {
            map.invalidateSize({ animate: false, pan: false });
            if (new URLSearchParams(window.location.hash.slice(1)).has('project')) openSharedProject();
            else fitLocations();
        });
    });
    let paginationRequest = null;
    list.addEventListener('click', async event => {
        const link = event.target.closest('#directoryPagination a');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        paginationRequest?.abort();
        paginationRequest = new AbortController();
        const grid = list.querySelector('.directory-grid');
        grid.setAttribute('aria-busy', 'true');
        selection.textContent = 'Loading the next directory page…';
        try {
            const response = await fetch(link.href, {
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                signal: paginationRequest.signal
            });
            if (!response.ok) throw new Error('Directory page could not be loaded.');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const nextGrid = page.querySelector('.directory-grid');
            const nextPagination = page.getElementById('directoryPagination');
            const nextSummary = page.querySelector('.directory-results>.directory-section-heading>span');
            if (!nextGrid || !nextPagination) throw new Error('Directory response was incomplete.');
            grid.replaceWith(nextGrid);
            document.getElementById('directoryPagination').replaceWith(nextPagination);
            const summary = list.querySelector('.directory-section-heading>span');
            if (summary && nextSummary) summary.replaceWith(nextSummary);
            history.pushState({ directoryPage: true }, '', link.href);
            clearSelection();
            map.closePopup();
            bindDirectoryCards();
            nextGrid.focus({ preventScroll: true });
            selection.textContent = 'Directory page updated. The map remains in its current position.';
        } catch (error) {
            if (error.name !== 'AbortError') {
                selection.textContent = `${error.message} Reload the page and try again.`;
                grid.removeAttribute('aria-busy');
            }
        }
    });
})();
