/**
 * MailHub: push notifications (Settings → Notifications).
 *
 * On every page, once per session: when notifications are on in this browser, its
 * subscription must be the one on file (browsers renew subscriptions; the server key
 * may have changed): if not, it is sent again. On the Notifications page: turn them on
 * or off here, send a test notification, remove devices, choose what notifies.
 */
(function () {
    var env, root = new URL('./', location.href).href;

    var supported = function () {
        return window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    };
    var ios = function () {
        return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform == 'MacIntel' && navigator.maxTouchPoints > 1);
    };
    var standalone = function () {
        return navigator.standalone === true || (window.matchMedia && matchMedia('(display-mode: standalone)').matches);
    };

    var keyBytes = function (b64) {
        var s = (b64 + '='.repeat((4 - b64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'),
            raw = atob(s), out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) {
            out[i] = raw.charCodeAt(i);
        }
        return out;
    };
    var b64u = function (buf) {
        var s = '', bytes = new Uint8Array(buf);
        for (var i = 0; i < bytes.length; i++) {
            s += String.fromCharCode(bytes[i]);
        }
        return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    };
    var sameKey = function (a, b) {
        if (!a || !b || a.byteLength != b.byteLength) {
            return false;
        }
        a = new Uint8Array(a);
        for (var i = 0; i < a.length; i++) {
            if (a[i] != b[i]) {
                return false;
            }
        }
        return true;
    };
    // the server's device id: the first 16 hex digits of sha256(endpoint)
    var idOf = function (endpoint) {
        return crypto.subtle.digest('SHA-256', new TextEncoder().encode(endpoint)).then(function (h) {
            return Array.prototype.map.call(new Uint8Array(h), function (b) {
                return ('0' + b.toString(16)).slice(-2);
            }).join('').slice(0, 16);
        });
    };

    var deviceLabel = function () {
        var ua = navigator.userAgent, b = 'Browser', o = '';
        if (/SamsungBrowser/.test(ua)) b = 'Samsung Internet';
        else if (/Edg\//.test(ua)) b = 'Edge';
        else if (/OPR\//.test(ua)) b = 'Opera';
        else if (/Firefox\/|FxiOS/.test(ua)) b = 'Firefox';
        else if (/Chrome\/|CriOS/.test(ua)) b = 'Chrome';
        else if (/Safari\//.test(ua)) b = 'Safari';
        if (/Android/.test(ua)) o = 'Android';
        else if (/iPhone|iPod/.test(ua)) o = 'iPhone';
        else if (/iPad/.test(ua) || (navigator.platform == 'MacIntel' && navigator.maxTouchPoints > 1)) o = 'iPad';
        else if (/CrOS/.test(ua)) o = 'ChromeOS';
        else if (/Windows/.test(ua)) o = 'Windows';
        else if (/Mac OS X/.test(ua)) o = 'macOS';
        else if (/Linux/.test(ua)) o = 'Linux';
        return b + (o ? ' · ' + o : '') + (standalone() ? ' (app)' : '');
    };

    var registration = function () {
        var url = new URL(env.sw, location.href);
        url.searchParams.set('root', root);
        return navigator.serviceWorker.register(url.href).then(function () {
            return navigator.serviceWorker.ready;
        });
    };

    var post = function (action, data, busy) {
        rcmail.http_post('plugin.mailhub_push-' + action, data || {}, busy ? rcmail.set_busy(true, 'loading') : null);
    };

    var subscribe = function (reg, replaces) {
        return reg.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: keyBytes(env.key)}).then(function (sub) {
            var j = sub.toJSON();
            post('subscribe', {_endpoint: j.endpoint, _p256dh: j.keys.p256dh, _auth: j.keys.auth,
                _label: deviceLabel(), _replaces: replaces || ''});
            return idOf(j.endpoint);
        });
    };

    // the subscription of this browser, null if none (or with an outdated server key)
    var current = function (reg) {
        return reg.pushManager.getSubscription().then(function (sub) {
            if (sub && sub.options && sub.options.applicationServerKey && !sameKey(sub.options.applicationServerKey, keyBytes(env.key))) {
                return sub.unsubscribe().then(function () { return null; });
            }
            return sub;
        });
    };

    var store = function (k, v) {
        try {
            if (v === undefined) return localStorage.getItem(k);
            if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v);
        } catch (e) {}
        return null;
    };

    /** Every page, once per session: re-send this browser's subscription if it changed. */
    var keepSubscribed = function () {
        try {
            if (sessionStorage.getItem('mhp-checked')) return;
            sessionStorage.setItem('mhp-checked', '1');
        } catch (e) {}
        var mine = store('mhp-id');
        if (!mine || Notification.permission != 'granted') {
            return;
        }
        registration().then(function (reg) {
            return current(reg).then(function (sub) {
                if (!sub) {
                    return subscribe(reg, mine).then(function (id) { store('mhp-id', id); });
                }
                return idOf(sub.endpoint).then(function (id) {
                    if (!(id in env.devices) || env.devices[id]) {
                        var j = sub.toJSON();
                        post('subscribe', {_endpoint: j.endpoint, _p256dh: j.keys.p256dh, _auth: j.keys.auth,
                            _label: deviceLabel(), _replaces: mine != id ? mine : ''});
                    }
                    store('mhp-id', id);
                });
            });
        }).catch(function (e) {
            console.warn('MailHub notifications:', e);
        });
    };

    // ------------------------------------------------------------------ page ---

    var page = function () {
        var label = function (name, vars) {
            var s = rcmail.get_label(name, 'mailhub_push');
            $.each(vars || {}, function (k, v) { s = s.split('$' + k).join(v); });
            return s;
        };
        var state = $('#mhp-state'), result = $('#mhp-result'),
            enable = $('#mhp-enable'), disable = $('#mhp-disable'), test = $('#mhp-test'), testall = $('#mhp-testall');
        var reg = null, myId = null, polling = null;

        var show = function (text, cls) {
            state.text(text).attr('class', 'mhp-state' + (cls ? ' ' + cls : ''));
        };
        var reload = function () {
            setTimeout(function () { location.reload(); }, 400);
        };

        var refresh = function () {
            enable.prop('hidden', true);
            disable.prop('hidden', true);
            test.prop('hidden', true);
            if (!supported()) {
                return show(label(ios() && !standalone() ? 'unsupported_ios' : 'unsupported'), 'mhp-warn');
            }
            if (!env.key) {
                return show(label('unsupported'), 'mhp-warn');
            }
            if (Notification.permission == 'denied') {
                return show(label('denied'), 'mhp-warn');
            }
            registration().then(function (r) {
                reg = r;
                return current(r);
            }).then(function (sub) {
                if (!sub || Notification.permission != 'granted') {
                    show(label('notenabled_here'));
                    enable.prop('hidden', false);
                    return;
                }
                return idOf(sub.endpoint).then(function (id) {
                    myId = id;
                    $('#mhp-devices li[data-id="' + id + '"] .mhp-badge').prop('hidden', false);
                    if (!(id in env.devices) || env.devices[id]) {  // on file no more: send it again
                        var j = sub.toJSON();
                        post('subscribe', {_endpoint: j.endpoint, _p256dh: j.keys.p256dh, _auth: j.keys.auth, _label: deviceLabel()});
                    }
                    store('mhp-id', id);
                    show(label('enabled_here'), 'mhp-good');
                    disable.prop('hidden', false);
                    test.prop('hidden', false);
                });
            }).catch(function (e) {
                show(label('subscribefailed') + ' ' + (e && e.message || e), 'mhp-warn');
            });
        };

        enable.on('click', function () {
            show(label('enabling'));
            // asked from the click: browsers only allow the prompt on a user gesture
            Notification.requestPermission().then(function (p) {
                if (p != 'granted') {
                    return refresh();
                }
                return (reg ? Promise.resolve(reg) : registration()).then(function (r) {
                    return subscribe(r, store('mhp-id'));
                }).then(function (id) {
                    store('mhp-id', id);
                    rcmail.addEventListener('plugin.mhp_subscribed', reload);
                });
            }).catch(function (e) {
                show(label('subscribefailed') + ' ' + (e && e.message || e), 'mhp-warn');
            });
        });

        var removeHere = function () {
            return (reg ? current(reg) : Promise.resolve(null)).then(function (sub) {
                store('mhp-id', null);
                return sub ? sub.unsubscribe() : true;
            });
        };
        disable.on('click', function () {
            removeHere().then(function () {
                if (myId) post('unsubscribe', {_id: myId}, true);
                rcmail.addEventListener('plugin.mhp_removed', reload);
            });
        });

        $('#mhp-devices').on('click', '.mhp-remove', function () {
            var id = $(this).data('id'), name = $(this).closest('li').find('.mhp-devname').contents().first().text();
            rcmail.show_popup_dialog($('<p>').text(label('removeconfirm', {device: name})), rcmail.get_label('remove', 'mailhub_push'), [
                {text: rcmail.get_label('remove', 'mailhub_push'), 'class': 'delete mainaction', click: function (e, ui, dlg) {
                    $(dlg || this).dialog('close');
                    (id == myId ? removeHere() : Promise.resolve()).then(function () {
                        post('unsubscribe', {_id: id}, true);
                        rcmail.addEventListener('plugin.mhp_removed', reload);
                    });
                }},
                {text: rcmail.get_label('cancel'), 'class': 'cancel', click: function (e, ui, dlg) {
                    $(dlg || this).dialog('close');
                }}
            ], {width: 420});
        });

        // ---- test notification: the mirror sends it; poll for its answer ----
        var sendTest = function (device) {
            clearInterval(polling);
            result.attr('class', 'mhp-result').text(label('testsending'));
            post('test', {_device: device}, true);
        };
        test.on('click', function () { sendTest(myId || 'all'); });
        testall.on('click', function () { sendTest('all'); });

        rcmail.addEventListener('plugin.mhp_testing', function (d) {
            var started = Date.now();
            polling = setInterval(function () {
                if (Date.now() - started > 20000) {
                    clearInterval(polling);
                    result.attr('class', 'mhp-result mhp-warn').text(label('testtimeout'));
                    return;
                }
                post('result', {_nonce: d.nonce});
            }, 1500);
        });
        rcmail.addEventListener('plugin.mhp_result', function (d) {
            if (!d.done) return;
            clearInterval(polling);
            result.empty().attr('class', 'mhp-result');
            if (!d.results || !d.results.length) {
                result.addClass('mhp-warn').text(label('testnone') + (d.error ? ' (' + d.error + ')' : ''));
                return;
            }
            $.each(d.results, function (i, r) {
                $('<div>').addClass(r.ok ? 'mhp-good' : 'mhp-bad')
                    .text(r.ok ? label('testok', {device: r.label}) : label('testfail', {device: r.label, error: r.error}))
                    .appendTo(result);
            });
        });

        // ---- what notifies: saved on each change ----
        $('#mhp-form').on('change', 'input.mhp-switch', function () {
            var form = $('#mhp-form'), on = [];
            form.find('input[name=notify]:checked').each(function () { on.push(this.value); });
            post('prefs', {_show_content: form.find('input[name=show_content]').prop('checked') ? '1' : '0', _notify: on}, true);
        });

        refresh();
    };

    window.rcmail && rcmail.addEventListener('init', function () {
        env = rcmail.env.mailhub_push;
        if (!env || !env.sw) {
            return;
        }
        if (rcmail.env.action == 'plugin.mailhub_push') {
            page();
        } else if (supported() && env.key) {
            keepSubscribed();
        }
    });
})();
