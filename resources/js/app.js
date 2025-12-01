import copy from 'copy-to-clipboard';

document.addEventListener('alpine:init', () => {
    Alpine.directive('clipboard', (el, { expression }, { evaluate }) => {
        el.addEventListener('click', () => {
            copy(evaluate(expression));
            el.dispatchEvent(new CustomEvent('clipboard-copied', { bubbles: true }));
        });
    });
});
