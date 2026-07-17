import { useI18n } from 'vue-i18n';

export type StatusDomain =
    | 'application'
    | 'job'
    | 'interview'
    | 'document'
    | 'visaCase'
    | 'visaStep'
    | 'referral';

const humanize = (status: string): string =>
    status
        .replaceAll('_', ' ')
        .replace(/\b\p{L}/gu, (character) => character.toLocaleUpperCase());

export function useStatusLabels() {
    const { t, te } = useI18n();

    const statusLabel = (domain: StatusDomain, status: string): string => {
        const key = `status.${domain}.${status}`;

        return te(key) ? t(key) : humanize(status);
    };

    return { statusLabel };
}
