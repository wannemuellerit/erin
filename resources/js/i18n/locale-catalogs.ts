import adminDe from '@/i18n/messages/admin-de';
import adminEn from '@/i18n/messages/admin-en';
import adminEs from '@/i18n/messages/admin-es';
import adminHr from '@/i18n/messages/admin-hr';
import adminPl from '@/i18n/messages/admin-pl';
import adminPt from '@/i18n/messages/admin-pt';
import adminRo from '@/i18n/messages/admin-ro';
import authDe from '@/i18n/messages/auth-de';
import authEn from '@/i18n/messages/auth-en';
import authEs from '@/i18n/messages/auth-es';
import authHr from '@/i18n/messages/auth-hr';
import authPl from '@/i18n/messages/auth-pl';
import authPt from '@/i18n/messages/auth-pt';
import authRo from '@/i18n/messages/auth-ro';
import candidateDe from '@/i18n/messages/candidate-de';
import candidateEn from '@/i18n/messages/candidate-en';
import candidateEs from '@/i18n/messages/candidate-es';
import candidateHr from '@/i18n/messages/candidate-hr';
import candidatePl from '@/i18n/messages/candidate-pl';
import candidatePt from '@/i18n/messages/candidate-pt';
import candidateRo from '@/i18n/messages/candidate-ro';
import dashboardDe from '@/i18n/messages/dashboard-de';
import dashboardEn from '@/i18n/messages/dashboard-en';
import dashboardEs from '@/i18n/messages/dashboard-es';
import dashboardHr from '@/i18n/messages/dashboard-hr';
import dashboardPl from '@/i18n/messages/dashboard-pl';
import dashboardPt from '@/i18n/messages/dashboard-pt';
import dashboardRo from '@/i18n/messages/dashboard-ro';
import de from '@/i18n/messages/de';
import employerDe from '@/i18n/messages/employer-de';
import employerEn from '@/i18n/messages/employer-en';
import employerEs from '@/i18n/messages/employer-es';
import employerHr from '@/i18n/messages/employer-hr';
import employerPl from '@/i18n/messages/employer-pl';
import employerPt from '@/i18n/messages/employer-pt';
import employerRo from '@/i18n/messages/employer-ro';
import en from '@/i18n/messages/en';
import es from '@/i18n/messages/es';
import hr from '@/i18n/messages/hr';
import operationsDe from '@/i18n/messages/operations-de';
import operationsEn from '@/i18n/messages/operations-en';
import operationsEs from '@/i18n/messages/operations-es';
import operationsHr from '@/i18n/messages/operations-hr';
import operationsPl from '@/i18n/messages/operations-pl';
import operationsPt from '@/i18n/messages/operations-pt';
import operationsRo from '@/i18n/messages/operations-ro';
import pl from '@/i18n/messages/pl';
import pt from '@/i18n/messages/pt';
import ro from '@/i18n/messages/ro';
import statusDe from '@/i18n/messages/status-de';
import statusEn from '@/i18n/messages/status-en';
import statusEs from '@/i18n/messages/status-es';
import statusHr from '@/i18n/messages/status-hr';
import statusPl from '@/i18n/messages/status-pl';
import statusPt from '@/i18n/messages/status-pt';
import statusRo from '@/i18n/messages/status-ro';
import type { SupportedLocale } from '@/i18n';

const catalog = (
    root: Record<string, unknown>,
    auth: Record<string, unknown>,
    candidate: Record<string, unknown>,
    dashboard: Record<string, unknown>,
    employer: Record<string, unknown>,
    operations: Record<string, unknown>,
    status: Record<string, unknown>,
    admin: Record<string, unknown>,
) => ({
    ...root,
    auth: { ...(root.auth as Record<string, unknown>), ...auth },
    candidate,
    dashboard,
    employer,
    operations,
    status,
    admin,
});

export const localeCatalogs = {
    de: catalog(
        de,
        authDe,
        candidateDe,
        dashboardDe,
        employerDe,
        operationsDe,
        statusDe,
        adminDe,
    ),
    en: catalog(
        en,
        authEn,
        candidateEn,
        dashboardEn,
        employerEn,
        operationsEn,
        statusEn,
        adminEn,
    ),
    pl: catalog(
        pl,
        authPl,
        candidatePl,
        dashboardPl,
        employerPl,
        operationsPl,
        statusPl,
        adminPl,
    ),
    ro: catalog(
        ro,
        authRo,
        candidateRo,
        dashboardRo,
        employerRo,
        operationsRo,
        statusRo,
        adminRo,
    ),
    hr: catalog(
        hr,
        authHr,
        candidateHr,
        dashboardHr,
        employerHr,
        operationsHr,
        statusHr,
        adminHr,
    ),
    es: catalog(
        es,
        authEs,
        candidateEs,
        dashboardEs,
        employerEs,
        operationsEs,
        statusEs,
        adminEs,
    ),
    pt: catalog(
        pt,
        authPt,
        candidatePt,
        dashboardPt,
        employerPt,
        operationsPt,
        statusPt,
        adminPt,
    ),
} satisfies Record<SupportedLocale, Record<string, unknown>>;
