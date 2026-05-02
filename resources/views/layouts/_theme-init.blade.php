<script>
(function () {
    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.*+?^=!:${}()|[\]/\\])/g, '\\$1') + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    var mode = getCookie('theme_mode') || 'manual';
    var theme;

    if (mode === 'auto') {
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        theme = mq.matches
            ? (getCookie('dark_theme') || 'dark')
            : (getCookie('light_theme') || 'light');

        mq.addEventListener('change', function (e) {
            document.documentElement.setAttribute(
                'data-theme',
                e.matches ? (getCookie('dark_theme') || 'dark') : (getCookie('light_theme') || 'light')
            );
        });
    } else {
        theme = getCookie('theme') || 'dark';
    }

    document.documentElement.setAttribute('data-theme', theme);
}());
</script>
