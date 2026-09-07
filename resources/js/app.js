import copy from 'copy-to-clipboard';
import Chart from 'chart.js/auto';

window.Chart = Chart;

const successIcon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" class="inline flex-shrink-0 w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>';

window.successToast = (title, timeout = 6000) => window.toast({
    toast: {
        type: 'success',
        title,
        description: null,
        position: 'toast-bottom',
        icon: successIcon,
        css: 'alert-success',
        timeout,
        noProgress: false,
        progressClass: null,
    },
});

document.addEventListener('alpine:init', () => {
    Alpine.directive('clipboard', (el, { expression }, { evaluate }) => {
        el.addEventListener('click', () => {
            copy(evaluate(expression));
            el.dispatchEvent(new CustomEvent('clipboard-copied', { bubbles: true }));
        });
    });

    Alpine.data('chart', (config, options = {}) => ({
        init() {
            this.$nextTick(() => {
                const canvas = this.$refs.canvas;
                if (!canvas) return;

                const resolveColor = (color) => {
                    if (color && color.startsWith('--')) {
                        return getComputedStyle(document.documentElement).getPropertyValue(color).trim();
                    }
                    return color;
                };

                config.data.datasets.forEach(dataset => {
                    if (Array.isArray(dataset.backgroundColor)) {
                        dataset.backgroundColor = dataset.backgroundColor.map(resolveColor);
                    } else {
                        dataset.backgroundColor = resolveColor(dataset.backgroundColor);
                    }
                });

                // Add byte formatting tooltip if requested
                if (options.formatBytes) {
                    const formatBytes = (bytes) => {
                        if (bytes === 0) return '0 B';
                        const k = 1024;
                        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
                        const i = Math.floor(Math.log(bytes) / Math.log(k));
                        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
                    };

                    config.options = config.options || {};
                    config.options.plugins = config.options.plugins || {};
                    config.options.plugins.tooltip = config.options.plugins.tooltip || {};
                    config.options.plugins.tooltip.callbacks = {
                        label: (context) => {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            return `${label}: ${formatBytes(value)}`;
                        }
                    };
                }

                new Chart(canvas, config);
            });
        }
    }));
});

document.addEventListener('livewire:init', () => {
    const boundTo = (el, field) => el.getAttributeNames().some((name) => {
        if (! name.startsWith('wire:model')) {
            return false;
        }

        const model = el.getAttribute(name);

        // Exact match first, then either side of a nested path: an error on
        // `form.channel_ids.0` should still find an input bound to
        // `form.channel_ids`, and vice versa.
        return model === field
            || model.startsWith(`${field}.`)
            || field.startsWith(`${model}.`);
    });

    const findField = (field) => field
        ? Array.from(document.querySelectorAll('input, select, textarea')).find((el) => boundTo(el, field))
        : null;

    // daisyUI collapses and <details> hide their content outright, so an error
    // inside one is invisible even after scrolling.
    const expandAncestors = (el) => {
        for (let node = el.parentElement; node && node !== document.body; node = node.parentElement) {
            if (node.tagName === 'DETAILS') {
                node.open = true;
            }

            if (node.classList.contains('collapse')) {
                const toggle = node.querySelector(':scope > input[type="checkbox"], :scope > input[type="radio"]');
                if (toggle) {
                    toggle.checked = true;
                }
            }
        }
    };

    // Mary marks an invalid control by class, on the wrapper for some
    // components, so fall back to the focusable descendant when there is one.
    const firstErroredControl = () => {
        const flagged = document.querySelector('[class~="!input-error"], [class~="!select-error"], [class~="!textarea-error"], [class~="textarea-error"], [class~="!border-error"]');

        return flagged?.querySelector('input, select, textarea') ?? flagged;
    };

    Livewire.on('validation-failed', ({ field }) => {
        requestAnimationFrame(() => {
            const target = findField(field) ?? firstErroredControl();

            if (! target) {
                return;
            }

            expandAncestors(target);
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            target.focus({ preventScroll: true });
        });
    });
});
