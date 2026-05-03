<meta name="theme-legacy" content="{{ request()->cookie('theme', '') }}">
<script>
    function applyTheme() {
        var legacy = document.querySelector('meta[name="theme-legacy"]');
        if (legacy && legacy.content && !localStorage.getItem('theme')) {
            localStorage.setItem('theme', legacy.content);
        }
        var theme = localStorage.getItem('theme') ||
            (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
        document.documentElement.setAttribute('data-theme', theme);
    }
    applyTheme();
    document.addEventListener('livewire:navigated', applyTheme);
</script>
