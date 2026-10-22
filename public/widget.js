/*!
 * Hamsukhan call widget.
 * <script src="https://YOUR-DOMAIN/widget.js" data-bot="BOT_PUBLIC_KEY" defer></script>
 * Renders the call button and starts a Vapi web call. Only the Vapi PUBLIC key ever reaches the browser.
 * Everything lives in a Shadow DOM, so the host site's CSS can't break the widget and ours can't leak out.
 * The voice SDK is only downloaded after the visitor agrees to start a call.
 */
(function () {
    var script = document.currentScript;
    if (!script || window.__hamsukhan) return;
    window.__hamsukhan = true;

    var key = script.getAttribute('data-bot');
    var base = new URL(script.src).origin;
    if (!key) return console.warn('[Hamsukhan] data-bot attribute missing');

    var SDK = 'https://esm.sh/@vapi-ai/web@3.0.0'; // exact version (v2 pulled an unsupported daily-js)
    var ICONS = {
        idle: '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M19 10v1a7 7 0 0 1-14 0v-1M12 18v4"/>',
        loading: '<path d="M21 12a9 9 0 1 1-6.2-8.6"/>',
        active: '<path d="M10.7 13.3a16 16 0 0 0 3.4 2.6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2v3a2 2 0 0 1-2.2 2A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9"/><path d="M22 2 2 22"/>',
        error: '<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>',
    };
    var CSS =
        ':host{all:initial}*{box-sizing:border-box;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}' +
        '@keyframes spin{to{transform:rotate(360deg)}}' +
        '.btn{position:fixed;z-index:2147483000;display:flex;align-items:center;gap:10px;padding:8px 14px 8px 8px;border:0;border-radius:14px;background:#fff;color:#1A1714;cursor:pointer;box-shadow:0 6px 24px rgba(0,0,0,.18);font-size:14px;line-height:1.3;text-align:left}' +
        '.btn:focus-visible,.panel button:focus-visible,.panel input:focus-visible{outline:3px solid #2563EB;outline-offset:2px}' +
        '.ic{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;flex:none}' +
        '.tx{display:flex;flex-direction:column;min-width:0}.tx b{font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tx span{font-size:12px;color:#6B645D;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
        '.panel{position:fixed;z-index:2147483001;width:min(340px,calc(100vw - 24px));max-height:calc(100vh - 110px);overflow:auto;background:#fff;color:#1A1714;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.25);padding:16px;font-size:14px;line-height:1.45}' +
        '.panel h4{margin:0 0 6px;font-size:15px}.panel p{margin:0 0 10px;color:#57514B;font-size:13px}.panel a{color:#C94A07}' +
        '.row{display:flex;gap:8px;margin-top:10px}.row button{flex:1}' +
        '.p{height:38px;border:0;border-radius:10px;background:#E8590C;color:#fff;font-weight:700;cursor:pointer;font-size:14px}.p[disabled]{opacity:.6;cursor:default}' +
        '.g{height:38px;border:1px solid #E9E4DE;border-radius:10px;background:#fff;color:#1A1714;font-weight:600;cursor:pointer;font-size:13px}' +
        '.panel input{width:100%;height:38px;border:1px solid #D9D2CA;border-radius:10px;padding:0 10px;font-size:14px;margin-top:6px;color:#1A1714;background:#fff}' +
        '.chk{display:flex;gap:8px;align-items:flex-start;font-size:12.5px;color:#57514B;margin-top:8px}.chk input{width:auto;height:auto;margin:3px 0 0}' +
        '.msg{font-size:13px;margin-top:8px}.err{color:#D92D20}.ok{color:#15803D}.x{position:absolute;top:8px;right:10px;border:0;background:none;font-size:20px;cursor:pointer;color:#8C847C;line-height:1}' +
        '.live{display:grid;gap:4px;max-height:150px;overflow:auto;margin-top:8px;font-size:13px;background:#F7F5F2;border-radius:10px;padding:8px}' +
        '@media (prefers-color-scheme:dark){.btn,.panel{background:#1A1714;color:#F4F1ED}.tx span,.panel p,.chk{color:#C4BDB5}.g{background:#1A1714;color:#F4F1ED;border-color:#3A352F}.panel input{background:#110F0D;color:#F4F1ED;border-color:#3A352F}.live{background:#110F0D}}';

    var state = 'idle', cfg, root, btn, panel, vapi = null, starting = false;
    var consented = false;
    try { consented = sessionStorage.getItem('hamsukhan-consent') === '1'; } catch (e) {}

    function h(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        return e;
    }

    function render(sub) {
        var s = cfg.widget.states[state];
        btn.innerHTML = '';
        var ic = h('span', 'ic');
        ic.style.background = s.color;
        if (s.iconUrl) {
            var img = h('img');
            img.src = s.iconUrl; img.width = 18; img.height = 18; img.alt = '';
            ic.appendChild(img);
        } else {
            ic.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"' + (state === 'loading' ? ' style="animation:spin 1s linear infinite"' : '') + '>' + ICONS[state] + '</svg>';
        }
        var tx = h('span', 'tx');
        tx.appendChild(h('b', null, s.title));
        tx.appendChild(h('span', null, sub || s.sub || ''));
        btn.appendChild(ic);
        btn.appendChild(tx);
        btn.setAttribute('aria-label', s.title);
    }

    function set(s, sub) { state = s; render(sub); }

    function closePanel() { if (panel) { panel.remove(); panel = null; } }

    function openPanel(build) {
        closePanel();
        var w = cfg.widget, pos = (w.position || 'bottom-right').split('-'), off = w.offset || 24;
        panel = h('div', 'panel');
        panel.setAttribute('role', 'dialog');
        panel.style[pos[0]] = (off + (w.height || 54) + 10) + 'px';
        panel.style[pos[1]] = off + 'px';
        var x = h('button', 'x', '×');
        x.setAttribute('aria-label', 'Close');
        x.onclick = closePanel;
        panel.appendChild(x);
        build(panel);
        root.appendChild(panel);
        var first = panel.querySelector('input,button.p');
        if (first) first.focus();
    }

    /** Disclosure before the first call: AI + recording + microphone. */
    function consentPanel() {
        openPanel(function (p) {
            p.appendChild(h('h4', null, 'Talk to our AI receptionist'));
            p.appendChild(h('p', null, 'You will be speaking with an AI assistant' + (cfg.business ? ' for ' + cfg.business : '') + ', not a person. The call is recorded and transcribed so the team can follow up. Your browser will ask to use your microphone.'));
            var pp = h('p');
            var a = h('a', null, 'Privacy policy');
            a.href = cfg.privacyUrl; a.target = '_blank'; a.rel = 'noopener';
            pp.appendChild(a);
            p.appendChild(pp);
            var row = h('div', 'row');
            var go = h('button', 'p', 'Start call');
            go.onclick = function () {
                consented = true;
                try { sessionStorage.setItem('hamsukhan-consent', '1'); } catch (e) {}
                closePanel();
                startCall();
            };
            var cb = h('button', 'g', 'Request a callback');
            cb.onclick = callbackPanel;
            row.appendChild(go); row.appendChild(cb);
            p.appendChild(row);
        });
    }

    /** Human fallback: name + phone, the business calls back. */
    function callbackPanel(note) {
        openPanel(function (p) {
            p.appendChild(h('h4', null, 'Request a callback'));
            p.appendChild(h('p', null, typeof note === 'string' ? note : 'Leave your number and the team will call or WhatsApp you back.'));
            var name = h('input'); name.placeholder = 'Your name'; name.autocomplete = 'name'; name.maxLength = 80;
            var phone = h('input'); phone.placeholder = 'Phone / WhatsApp, e.g. 0300 1234567'; phone.type = 'tel'; phone.autocomplete = 'tel'; phone.maxLength = 30;
            var msg = h('input'); msg.placeholder = 'What is it about? (optional)'; msg.maxLength = 300; msg.dir = 'auto';
            var chkL = h('label', 'chk'); var chk = h('input'); chk.type = 'checkbox';
            chkL.appendChild(chk); chkL.appendChild(h('span', null, 'I agree to be contacted about this request.'));
            var out = h('div', 'msg');
            out.setAttribute('aria-live', 'polite');
            var send = h('button', 'p', 'Send');
            send.style.width = '100%'; send.style.marginTop = '10px';
            send.onclick = function () {
                if (!name.value.trim() || !phone.value.trim()) { out.className = 'msg err'; out.textContent = 'Please enter your name and phone number.'; return; }
                if (!chk.checked) { out.className = 'msg err'; out.textContent = 'Please tick the box to agree to be contacted.'; return; }
                send.disabled = true; send.textContent = 'Sending…';
                post('/callback', { name: name.value.trim(), phone: phone.value.trim(), message: msg.value.trim(), consent: true }).then(function (res) {
                    if (res.ok && res.body.ok) {
                        p.innerHTML = '';
                        p.appendChild(h('h4', null, 'Thank you!'));
                        p.appendChild(h('p', null, 'We have your request and will get back to you soon.'));
                        setTimeout(closePanel, 4000);
                    } else {
                        send.disabled = false; send.textContent = 'Send';
                        out.className = 'msg err'; out.textContent = (res.body && res.body.message) || 'Could not send, please try again.';
                    }
                });
            };
            [name, phone, msg, chkL, send, out].forEach(function (e) { p.appendChild(e); });
        });
    }

    function livePanel() {
        openPanel(function (p) {
            p.appendChild(h('h4', null, 'On call with our AI receptionist'));
            var st = h('p', 'status', 'Connecting…');
            st.setAttribute('aria-live', 'polite');
            p.appendChild(st);
            p.appendChild(h('div', 'live'));
            var row = h('div', 'row');
            var end = h('button', 'g', 'Hang up');
            end.onclick = function () { if (vapi) vapi.stop(); };
            row.appendChild(end);
            p.appendChild(row);
        });
    }

    function status(text) {
        if (panel) { var s = panel.querySelector('.status'); if (s) s.textContent = text; }
        if (state === 'active') render(text);
    }

    function addLine(role, text) {
        var box = panel && panel.querySelector('.live');
        if (!box) return;
        var d = h('div'); d.dir = 'auto';
        var b = h('b', null, role === 'user' ? 'You: ' : 'AI: ');
        d.appendChild(b); d.appendChild(document.createTextNode(text));
        box.appendChild(d);
        box.scrollTop = box.scrollHeight;
    }

    function post(path, body) {
        return fetch(base + '/api/widget/' + key + path, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body || {}) })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, body: j }; }); })
            .catch(function () { return { ok: false, status: 0, body: { message: 'No internet connection. Please check your network and try again.' } }; });
    }

    function fail(reason) {
        starting = false;
        vapi = null;
        set('error');
        callbackPanel(reason + ' You can leave your number and the team will call you back.');
        setTimeout(function () { if (state === 'error') set('idle'); }, 5000);
    }

    function micError(e) {
        var t = String((e && (e.name || (e.error && e.error.message) || e.errorMsg || e.message)) || '');
        if (/NotAllowed|Permission|denied/i.test(t)) return 'Microphone access is blocked.';
        if (/NotFound|device/i.test(t)) return 'No microphone was found.';
        return 'The call could not connect.';
    }

    function startCall() {
        if (starting) return;
        if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return fail('This browser cannot use the microphone here.');
        starting = true;
        set('loading');
        post('/start').then(function (res) {
            if (!res.ok || !res.body.ok) return fail(res.body.reason === 'no_balance' || res.body.reason === 'inactive' ? 'The assistant is not available right now.' : (res.body.message || 'Could not connect.'));
            if (res.body.simulated || !res.body.vapiPublicKey || !res.body.assistantId) return fail('Voice calls are not switched on yet.');
            return import(SDK).then(function (mod) {
                var Vapi = mod.default;
                vapi = new Vapi(res.body.vapiPublicKey);
                livePanel();
                vapi.on('call-start', function () { starting = false; set('active', 'Listening…'); status('Listening, speak now'); });
                vapi.on('speech-start', function () { status('Speaking…'); });
                vapi.on('speech-end', function () { status('Listening, speak now'); });
                vapi.on('message', function (m) {
                    if (m && m.type === 'transcript' && m.transcriptType === 'final' && m.transcript) addLine(m.role, m.transcript);
                });
                vapi.on('call-end', function () { starting = false; vapi = null; set('idle'); status('Call ended. Thank you!'); setTimeout(closePanel, 2500); });
                vapi.on('error', function (e) { console.warn('[Hamsukhan]', e); closePanel(); fail(micError(e)); });
                return vapi.start(res.body.assistantId, res.body.overrides);
            });
        }).catch(function (e) { closePanel(); fail(micError(e)); });
    }

    function onClick() {
        if (state === 'active' && vapi) return vapi.stop();
        if (state === 'loading' || starting) return;
        if (panel) return closePanel();
        consented ? startCall() : consentPanel();
    }

    function mount() {
        var host = document.createElement('div');
        host.id = 'hamsukhan-widget';
        document.body.appendChild(host);
        root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
        var style = document.createElement('style');
        style.textContent = CSS;
        root.appendChild(style);

        var w = cfg.widget, pos = (w.position || 'bottom-right').split('-'), off = (w.offset || 24) + 'px';
        btn = h('button', 'btn');
        btn.type = 'button';
        btn.style[pos[0]] = off;
        btn.style[pos[1]] = off;
        btn.style.width = 'min(' + (w.width || 230) + 'px, calc(100vw - 24px))';
        btn.style.height = (w.height || 54) + 'px';
        btn.addEventListener('click', onClick);
        root.appendChild(btn);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closePanel(); });
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
