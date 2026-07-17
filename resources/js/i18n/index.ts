import { createI18n } from 'vue-i18n';
import authDe from '@/i18n/messages/auth-de';
import authEn from '@/i18n/messages/auth-en';
import dashboardDe from '@/i18n/messages/dashboard-de';
import dashboardEn from '@/i18n/messages/dashboard-en';
import de from '@/i18n/messages/de';
import en from '@/i18n/messages/en';
import statusDe from '@/i18n/messages/status-de';
import statusEn from '@/i18n/messages/status-en';

export type SupportedLocale = 'de' | 'en';

export const supportedLocales: SupportedLocale[] = ['de', 'en'];

export const normalizeLocale = (locale?: string | null): SupportedLocale =>
    supportedLocales.includes(locale as SupportedLocale)
        ? (locale as SupportedLocale)
        : 'de';

export const createErinI18n = (locale?: string | null) =>
    createI18n({
        legacy: false,
        locale: normalizeLocale(locale),
        fallbackLocale: 'de',
        messages: {
            de: {
                ...de,
                auth: { ...de.auth, ...authDe },
                dashboard: dashboardDe,
                status: statusDe,
            },
            en: {
                ...en,
                auth: { ...en.auth, ...authEn },
                dashboard: dashboardEn,
                status: statusEn,
            },
        },
    });
