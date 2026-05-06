<meta name="theme-legacy" content="{{ request()->cookie('theme', '') }}">
<script>
    function safeGetItem(key) {
        try { return localStorage.getItem(key); } catch (e) { return null; }
    }
    function safeSetItem(key, value) {
        try { localStorage.setItem(key, value); } catch (e) {}
    }

    function getTheme() {
        var legacy = document.querySelector('meta[name="theme-legacy"]');
        if (legacy && legacy.content && !safeGetItem('theme')) {
            safeSetItem('theme', legacy.content);
        }
        return safeGetItem('theme') ||
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
