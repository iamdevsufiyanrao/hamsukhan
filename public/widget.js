/*!
 * Hamsukhan call widget.
 * <script src="https://YOUR-DOMAIN/widget.js" data-bot="BOT_PUBLIC_KEY" defer></script>
 * Renders the call button and starts a Vapi web call. Only the Vapi PUBLIC key ever reaches the browser.
 */
(function () {
    var script = document.currentScript;
    if (!script || window.__hamsukhan) return;
    window.__hamsukhan = true;

    var key = script.getAttribute('data-bot');
    var base = new URL(script.src).origin;
    if (!key) return console.warn('[Hamsukhan] data-bot attribute missing');

    var ICONS = {
        idle: '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M19 10v1a7 7 0 0 1-14 0v-1M12 18v4"/>',
        loading: '<path d="M21 12a9 9 0 1 1-6.2-8.6"/>',
        active: '<path d="M10.7 13.3a16 16 0 0 0 3.4 2.6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2v3a2 2 0 0 1-2.2 2A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9"/><path d="M22 2 2 22"/>',
        error: '<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>',
    };

    var state = 'idle', cfg, btn, vapi = null;

    function el(tag, css) {
        var e = document.createElement(tag);
        if (css) e.style.cssText = css;
        return e;
    }

    function render() {
        var s = cfg.widget.states[state];
        var icon = s.iconUrl
            ? '<img src="' + encodeURI(s.iconUrl) + '" width="18" height="18" alt="">'
            : '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"' + (state === 'loading' ? ' style="animation:mcspin 1s linear infinite"' : '') + '>' + ICONS[state] + '</svg>';
        btn.innerHTML = '';
        var ic = el('span', 'width:38px;height:38px;border-radius:10px;display:grid;place-items:center;flex:none;background:' + s.color);
        ic.innerHTML = icon;
        var tx = el('span', 'display:flex;flex-direction:column;min-width:0;text-align:left');
        var t = el('b', 'font-size:14px;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis');
        t.textContent = s.title;
        var sub = el('span', 'font-size:12px;color:#6B645D;white-space:nowrap;overflow:hidden;text-overflow:ellipsis');
        sub.textContent = s.sub || '';
        tx.appendChild(t);
        tx.appendChild(sub);
        btn.appendChild(ic);
        btn.appendChild(tx);
        btn.setAttribute('aria-label', s.title);
    }

    function set(s) {
        state = s;
        render();
    }

    function fail(reason) {
        set('error');
        if (reason) btn.title = reason;
        setTimeout(function () { if (state === 'error') set('idle'); }, 4000);
    }

    function start() {
        if (state === 'active' && vapi) return vapi.stop();
        if (state !== 'idle' && state !== 'error') return;
        set('loading');
        fetch(base + '/api/widget/' + key + '/start', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' } })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                if (!res.ok || !res.body.ok) return fail(res.body.reason === 'no_balance' ? 'This assistant is unavailable right now.' : 'Could not connect.');
                if (res.body.simulated || !res.body.vapiPublicKey) return fail('Demo mode: voice calls are not connected yet.');
                return import('https://esm.sh/@vapi-ai/web@2').then(function (mod) {
                    var Vapi = mod.default;
                    vapi = new Vapi(res.body.vapiPublicKey);
                    vapi.on('call-start', function () { set('active'); });
                    vapi.on('call-end', function () { vapi = null; set('idle'); });
                    vapi.on('error', function () { vapi = null; fail('The call dropped.'); });
                    return vapi.start(res.body.assistantId, res.body.overrides);
                });
            })
            .catch(function () { fail('Could not connect.'); });
    }

    function mount() {
        var w = cfg.widget, pos = (w.position || 'bottom-right').split('-'), off = (w.offset || 24) + 'px';
        var style = el('style');
        style.textContent = '@keyframes mcspin{to{transform:rotate(360deg)}}';
        document.head.appendChild(style);
        btn = el('button',
            'position:fixed;z-index:2147483000;' + pos[0] + ':' + off + ';' + pos[1] + ':' + off + ';width:' + (w.width || 230) + 'px;height:' + (w.height || 54) + 'px;' +
            'display:flex;align-items:center;gap:10px;padding:8px 14px 8px 8px;border:0;border-radius:14px;background:#fff;color:#1A1714;cursor:pointer;' +
            'box-shadow:0 6px 24px rgba(0,0,0,.18);font:14px/1.3 system-ui,-apple-system,Segoe UI,sans-serif;');
        btn.type = 'button';
        btn.addEventListener('click', start);
        document.body.appendChild(btn);
        render();
    }

    fetch(base + '/api/widget/' + key + '/config', { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (c) {
            if (!c || !c.active) return;
            cfg = c;
            if (document.body) mount(); else document.addEventListener('DOMContentLoaded', mount);
        })
        .catch(function () {});
})();
