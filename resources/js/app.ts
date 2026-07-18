import { createInertiaApp, router } from '@inertiajs/vue3';
import { configureEcho } from '@laravel/echo-vue';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { createErinI18n, normalizeLocale } from '@/i18n';
import { initializeFlashToast } from '@/lib/flashToast';

type LocalePageProps = {
    auth?: {
        user?: {
            locale?: string | null;
        } | null;
    };
    platform?: {
        locale?: string | null;
    };
};

function localeFromPage(props: unknown): string | null | undefined {
    const pageProps = props as LocalePageProps;

    return pageProps.auth?.user?.locale ?? pageProps.platform?.locale;
}

if (import.meta.env.VITE_BROADCAST_CONNECTION === 'pusher') {
    configureEcho({
        broadcaster: 'pusher',
        key: import.meta.env.VITE_PUSHER_APP_KEY,
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
        forceTLS: true,
    });
} else {
    configureEcho({
        broadcaster: 'reverb',
    });
}

const appName = import.meta.env.VITE_APP_NAME || 'Erin';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case ['Welcome', 'Pricing', 'Legal', 'Contact'].includes(name):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#2563EB',
    },
    withApp: (app, { page }) => {
        const initialLocale = normalizeLocale(localeFromPage(page.props));
        const i18n = createErinI18n(initialLocale);

        app.use(i18n);

        if (typeof window !== 'undefined') {
            document.documentElement.lang = initialLocale;
            router.on('navigate', (event) => {
                const nextLocale = normalizeLocale(
                    localeFromPage(event.detail.page.props),
                );
                i18n.global.locale.value = nextLocale;
                document.documentElement.lang = nextLocale;
            });
        }
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
