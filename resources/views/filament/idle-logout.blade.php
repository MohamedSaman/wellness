{{--
    Idle sign-out (Administration → Security, admin_idle_minutes). Any key,
    click, scroll or touch in any of the user's tabs counts as activity
    (shared through localStorage); once none has happened for the limit, the
    page reloads and the server (LogOutIdleUsers) signs the user out. The
    dashboard's own refreshes are not activity on either side.
--}}
@if ($seconds > 0)
    <script>
        (() => {
            const limit = @js($seconds) * 1000;
            const key = 'pos.lastActivity';
            let last = Date.now();

            const touch = () => {
                last = Date.now();
                try { localStorage.setItem(key, String(last)); } catch {}
            };

            const lastActivity = () => {
                let shared = 0;
                try { shared = Number(localStorage.getItem(key)) || 0; } catch {}

                return Math.max(last, shared);
            };

            ['keydown', 'pointerdown', 'wheel', 'touchstart'].forEach((type) => {
                document.addEventListener(type, touch, { passive: true, capture: true });
            });
            touch();

            setInterval(() => {
                if (Date.now() - lastActivity() > limit + 5000) {
                    window.location.reload();
                }
            }, 15000);
        })();
    </script>
@endif
