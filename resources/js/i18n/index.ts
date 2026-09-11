import { createI18n } from 'vue-i18n';
import { localeCatalogs } from '@/i18n/locale-catalogs';

export type SupportedLocale = 'de' | 'en' | 'pl' | 'ro' | 'hr' | 'es' | 'pt';

export const supportedLocales: SupportedLocale[] = [
    'de',
    'en',
    'pl',
    'ro',
    'hr',
    'es',
    'pt',
];
export const localeNames: Record<SupportedLocale, string> = {
    de: 'Deutsch',
    en: 'English',
    pl: 'Polski',
    ro: 'Română',
    hr: 'Hrvatski',
    es: 'Español',
    pt: 'Português',
};

export const normalizeLocale = (locale?: string | null): SupportedLocale =>
    supportedLocales.includes(locale as SupportedLocale)
        ? (locale as SupportedLocale)
        : 'de';

export const createErinI18n = (locale?: string | null) =>
    createI18n({
        legacy: false,
        locale: normalizeLocale(locale),
        fallbackLocale: 'de',
        messages: localeCatalogs,
    });
