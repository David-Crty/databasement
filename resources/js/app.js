import copy from 'copy-to-clipboard';

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
