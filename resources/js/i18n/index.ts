import { createI18n } from 'vue-i18n';
import authDe from '@/i18n/messages/auth-de';
import authEn from '@/i18n/messages/auth-en';
import candidateDe from '@/i18n/messages/candidate-de';
import candidateEn from '@/i18n/messages/candidate-en';
import dashboardDe from '@/i18n/messages/dashboard-de';
import dashboardEn from '@/i18n/messages/dashboard-en';
import de from '@/i18n/messages/de';
import employerDe from '@/i18n/messages/employer-de';
import employerEn from '@/i18n/messages/employer-en';
import en from '@/i18n/messages/en';
import operationsDe from '@/i18n/messages/operations-de';
import operationsEn from '@/i18n/messages/operations-en';
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
                candidate: candidateDe,
                dashboard: dashboardDe,
                employer: employerDe,
                operations: operationsDe,
                status: statusDe,
            },
            en: {
                ...en,
                auth: { ...en.auth, ...authEn },
                candidate: candidateEn,
                dashboard: dashboardEn,
                employer: employerEn,
                operations: operationsEn,
                status: statusEn,
            },
        },
    });
