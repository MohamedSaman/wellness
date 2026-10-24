{{--
    Alt+P opens the POS from any admin page. One listener for the page (the
    topbar button exists twice, for small and large screens). event.code is
    the physical key, so Option+P works on a Mac too.
--}}
<script>
    document.addEventListener('keydown', (event) => {
        if (event.altKey && ! event.ctrlKey && ! event.metaKey && ! event.shiftKey && event.code === 'KeyP') {
            event.preventDefault();
            window.location.assign(@js(route('pos.index')));
        }
    });
</script>
