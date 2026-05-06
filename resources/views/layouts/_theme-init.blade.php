<meta name="theme-legacy" content="{{ request()->cookie('theme', '') }}">
<script>
    function getTheme() {
        var legacy = document.querySelector('meta[name="theme-legacy"]');
        if (legacy && legacy.content && !localStorage.getItem('theme')) {
            localStorage.setItem('theme', legacy.content);
        }
        return localStorage.getItem('theme') ||
            (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
    }

    function applyTheme() {
        document.documentElement.setAttribute('data-theme', getTheme());
    }

    applyTheme();

    // Re-apply theme instantly when Livewire's DOM morph removes/changes data-theme
    new MutationObserver(function () {
        if (document.documentElement.getAttribute('data-theme') !== getTheme()) {
            applyTheme();
        }
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    document.addEventListener('livewire:navigated', applyTheme);
</script>
