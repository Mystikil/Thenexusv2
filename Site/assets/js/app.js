(function () {
    'use strict';

    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') ?? '' : '';

    /**
     * Wrapper around fetch that automatically appends the CSRF header.
     */
    window.csrfFetch = function csrfFetch(input, init = {}) {
        const options = { ...init };
        const headers = new Headers(init && init.headers ? init.headers : undefined);

        if (csrfToken) {
            if (!headers.has('X-CSRF-Token')) {
                headers.set('X-CSRF-Token', csrfToken);
            }
        }

        options.headers = headers;

        return fetch(input, options);
    };

    /**
     * Enhance the account theme selector with live preview messaging.
     */
    function enhanceThemeSelector() {
        const select = document.querySelector('[data-theme-select]');

        if (!select) {
            return;
        }

        const message = document.querySelector('[data-theme-select-message]');
        const defaultLabel = select.getAttribute('data-theme-default-label') || 'site default theme';
        const body = document.body;
        const initialValue = (select.value || '').trim();

        function selectedOption() {
            return select.options[select.selectedIndex] || null;
        }

        function updateMessage() {
            const option = selectedOption();
            const selectedValue = option ? option.value.trim() : '';
            const optionLabel = option ? (option.dataset.themeLabel || option.textContent || '').trim() : '';

            if (body) {
                if (selectedValue && selectedValue !== initialValue) {
                    body.setAttribute('data-preview-theme', selectedValue);
                } else {
                    body.removeAttribute('data-preview-theme');
                }
            }

            if (message) {
                if (selectedValue) {
                    if (selectedValue === initialValue) {
                        message.textContent = `Using the ${optionLabel || selectedValue} theme.`;
                    } else {
                        message.textContent = `Previewing “${optionLabel || selectedValue}”. Save to apply it permanently.`;
                    }
                } else {
                    message.textContent = `Using the ${defaultLabel}.`;
                }
            }
        }

        select.addEventListener('change', updateMessage);
        updateMessage();
    }

    function parseInterval(value, fallback) {
        const parsed = Number.parseInt(value, 10);

        if (Number.isFinite(parsed) && parsed > 0) {
            return parsed;
        }

        return fallback;
    }

    function setupWidgetAutoRefresh() {
        const widgets = document.querySelectorAll('.widget[data-auto-refresh]');

        if (!widgets.length) {
            return;
        }

        widgets.forEach((widget) => {
            const name = widget.getAttribute('data-auto-refresh');

            if (!name) {
                return;
            }

            const rawInterval = widget.getAttribute('data-interval');
            const intervalMs = Math.max(parseInterval(rawInterval, 15000), 5000);
            const rawLimit = widget.getAttribute('data-limit');
            const limit = parseInterval(rawLimit, 0);

            const fetchWidget = () => {
                const params = new URLSearchParams({ endpoint: 'widget', name });

                if (limit > 0) {
                    params.set('limit', String(limit));
                }

                fetch(`/api/public_read.php?${params.toString()}`, {
                    method: 'GET',
                    headers: { Accept: 'application/json' },
                })
                    .then((response) => {
                        if (!response.ok) {
                            throw new Error(`Widget refresh failed with status ${response.status}`);
                        }

                        return response.json();
                    })
                    .then((payload) => {
                        if (!payload) {
                            return;
                        }

                        const data = typeof payload.data === 'object' && payload.data !== null ? payload.data : payload;
                        const html = typeof data.html === 'string' ? data.html : '';

                        if (!html) {
                            return;
                        }

                        const template = document.createElement('template');
                        template.innerHTML = html.trim();

                        const nextWidget = template.content.querySelector('.widget');

                        if (!nextWidget) {
                            widget.innerHTML = html;
                            return;
                        }

                        widget.innerHTML = nextWidget.innerHTML;

                        Array.from(widget.attributes).forEach((attr) => {
                            if (attr.name.startsWith('data-')) {
                                widget.removeAttribute(attr.name);
                            }
                        });

                        Array.from(nextWidget.attributes).forEach((attr) => {
                            if (attr.name.startsWith('data-')) {
                                widget.setAttribute(attr.name, attr.value);
                            }
                        });

                        if (typeof data.ts === 'number') {
                            widget.setAttribute('data-last-refreshed', String(data.ts));
                        }
                    })
                    .catch((error) => {
                        console.error(error);
                    });
            };

            if (intervalMs > 0) {
                window.setInterval(fetchWidget, intervalMs);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        enhanceThemeSelector();
        setupWidgetAutoRefresh();
    });
})();
