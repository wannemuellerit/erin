self.addEventListener('push', (event) => {
    let payload = {};

    if (event.data) {
        try {
            payload = event.data.json();
        } catch {
            payload = {
                body: event.data.text(),
            };
        }
    }

    const title = payload.title || 'Erin';
    const options = {
        actions: payload.actions || [],
        badge: payload.badge,
        body: payload.body || '',
        data: payload.data || {},
        dir: payload.dir,
        icon: payload.icon || '/favicon.svg',
        image: payload.image,
        lang: payload.lang,
        renotify: payload.renotify,
        requireInteraction: payload.requireInteraction,
        tag: payload.tag,
        vibrate: payload.vibrate,
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const requestedUrl = event.notification.data?.url || '/dashboard';
    let targetUrl = new URL('/dashboard', self.location.origin);

    try {
        const parsedUrl = new URL(requestedUrl, self.location.origin);

        if (parsedUrl.origin === self.location.origin) {
            targetUrl = parsedUrl;
        }
    } catch {
        // Keep the safe dashboard fallback for malformed notification URLs.
    }

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then(async (clientList) => {
                for (const client of clientList) {
                    if ('navigate' in client) {
                        await client.navigate(targetUrl.href);
                    }

                    if ('focus' in client) {
                        return client.focus();
                    }
                }

                return self.clients.openWindow(targetUrl.href);
            }),
    );
});
