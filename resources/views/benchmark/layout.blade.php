<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Salary benchmark')</title>
    <style>
        :root { --bg:#f7f7f5; --card:#fff; --ink:#1d1d1b; --muted:#6b6b66; --line:#e3e2dd; --accent:#2f6fde; --good:#1f8a4c; --bad:#c2410c; }
        * { box-sizing: border-box; }
        body { margin:0; font:14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background:var(--bg); color:var(--ink); }
        main { max-width:1200px; margin:0 auto; padding:24px 16px 64px; }
        h1 { font-size:22px; margin:0 0 4px; } h2 { font-size:16px; margin:28px 0 10px; }
        .muted { color:var(--muted); } a { color:var(--accent); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:8px; padding:16px; }
        form.grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:10px 14px; align-items:end; }
        label { display:flex; flex-direction:column; gap:3px; font-size:12px; color:var(--muted); }
        input, select { font:inherit; padding:6px 8px; border:1px solid var(--line); border-radius:6px; background:#fff; color:var(--ink); }
        .checks { grid-column:1/-1; display:flex; flex-wrap:wrap; gap:6px 18px; }
        .checks label { flex-direction:row; align-items:center; gap:6px; color:var(--ink); font-size:13px; }
        button { font:inherit; padding:7px 14px; border:0; border-radius:6px; background:var(--accent); color:#fff; cursor:pointer; }
        .scroll { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; background:var(--card); }
        th, td { padding:7px 10px; border-bottom:1px solid var(--line); text-align:left; white-space:nowrap; }
        th { font-size:12px; color:var(--muted); font-weight:600; }
        td.n, th.n { text-align:right; font-variant-numeric:tabular-nums; }
        .bar { height:8px; background:var(--accent); border-radius:4px; min-width:2px; }
        .fast { color:var(--good); font-weight:600; } .slow { color:var(--bad); }
        code, pre { font:12px/1.45 ui-monospace, Consolas, monospace; }
        pre { white-space:pre-wrap; word-break:break-word; margin:4px 0 10px; padding:8px; background:#f2f1ed; border-radius:6px; }
        details { margin:6px 0; } summary { cursor:pointer; }
        .stats { display:flex; flex-wrap:wrap; gap:12px; }
        .stat { background:var(--card); border:1px solid var(--line); border-radius:8px; padding:10px 14px; min-width:120px; }
        .stat b { display:block; font-size:20px; font-variant-numeric:tabular-nums; }
        #results { transition:opacity .15s; } #results.loading { opacity:.4; pointer-events:none; }
        button:disabled { opacity:.6; cursor:wait; }
        .error { color:var(--bad); background:#fdf1ec; border:1px solid #f4cdb9; border-radius:8px; padding:10px 14px; margin-top:16px; }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/axios/1.7.7/axios.min.js"></script>
</head>
<body>
<main>
    @yield('content')
</main>
<script>
    // Run the benchmark without a page reload: GET the form's URL with X-Partial and swap in the returned
    // Blade partial. The address bar is updated so a refresh or shared link reproduces the same run.
    (() => {
        const form = document.querySelector('form[data-ajax]');
        const results = document.getElementById('results');
        if (!form || !results) return;

        const button = form.querySelector('button[type=submit]');
        const status = form.querySelector('[data-status]');
        let timer;

        const escape = (text) => String(text).replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);

        async function run() {
            const url = `${form.action}?${new URLSearchParams(new FormData(form))}`;
            const started = performance.now();

            button.disabled = true;
            results.classList.add('loading');
            timer = setInterval(() => status.textContent = `running… ${((performance.now() - started) / 1000).toFixed(1)} s`, 100);

            try {
                const { data } = await axios.get(url, { headers: { 'X-Partial': '1', Accept: 'application/json' } });
                results.innerHTML = data;
                history.replaceState(null, '', url);
                status.textContent = `done in ${((performance.now() - started) / 1000).toFixed(2)} s (round trip)`;
            } catch (error) {
                const errors = error.response?.data?.errors;
                const messages = errors ? Object.values(errors).flat() : [error.response?.data?.message ?? error.message];
                results.insertAdjacentHTML('afterbegin', `<div class="error">${messages.map(escape).join('<br>')}</div>`);
                status.textContent = 'failed';
            } finally {
                clearInterval(timer);
                button.disabled = false;
                results.classList.remove('loading');
            }
        }

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            results.querySelector('.error')?.remove();
            run();
        });

        // Links like "Next page" carry the fields to change; apply them to the form and re-run.
        results.addEventListener('click', (event) => {
            const link = event.target.closest('a[data-ajax-set]');
            if (!link) return;
            event.preventDefault();
            for (const [name, value] of Object.entries(JSON.parse(link.dataset.ajaxSet))) {
                form.elements[name].value = value;
            }
            form.requestSubmit();
        });
    })();
</script>
</body>
</html>
