/**
 * MailHub service worker: shows the push notifications mailhub-mirror sends (new mail,
 * a test) and opens MailHub when one is clicked. `root` (the webmail's URL) comes from
 * the registration (sw.js?root=...): this file is served from under static.php.
 * No fetch handler: nothing is cached, MailHub stays an online app.
 */
var ROOT = new URL(self.location).searchParams.get('root') || new URL('../../../', self.location).href;
var ICON = new URL('../mailhub_branding/icons/icon-192.png', self.location).href;
var BADGE = new URL('badge-96.png', self.location).href;

self.addEventListener('install', function () {
    self.skipWaiting();
});
self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    var d = {};
    try {
        d = event.data ? event.data.json() : {};
    } catch (e) {
        d = {body: event.data ? event.data.text() : ''};
    }
    event.waitUntil(self.registration.showNotification(d.title || 'MailHub', {
        body: d.body || '',
        tag: d.tag || 'mailhub',
        renotify: !!d.tag,
        icon: ICON,
        badge: BADGE,
        data: {url: new URL(d.url || '?_task=mail', ROOT).href}
    }));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || ROOT;
    event.waitUntil(self.clients.matchAll({type: 'window', includeUncontrolled: true}).then(function (list) {
        // a MailHub window already open: bring it forward as it is (it may hold a draft;
        // it refreshes its message list itself)
        for (var i = 0; i < list.length; i++) {
            var c = list[i];
            if (c.url.indexOf(ROOT) === 0 && 'focus' in c) {
                return c.focus();
            }
        }
        return self.clients.openWindow(url);
    }));
});
