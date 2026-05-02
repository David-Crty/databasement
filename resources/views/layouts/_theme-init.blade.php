<script>
(function () {
    function applyTheme() {
        var meta = document.querySelector('meta[name="theme-config"]');
        if (!meta) { return; }
        var mode = meta.getAttribute('data-mode') || 'manual';
        var theme;
        if (mode === 'auto') {
            theme = window.matchMedia('(prefers-color-scheme: dark)').matches
                ? (meta.getAttribute('data-dark') || 'dark')
                : (meta.getAttribute('data-light') || 'light');
        } else {
            theme = meta.getAttribute('data-theme') || 'dark';
        }
        document.documentElement.setAttribute('data-theme', theme);
    }

    applyTheme();

    // Re-apply after wire:navigate updates the <head> meta tag from the new page response
    document.addEventListener('livewire:navigated', applyTheme);

    // Re-apply when OS light/dark preference changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applyTheme);
}());
</script>
