import { useI18n } from 'vue-i18n';
import { useFormatters } from '@/composables/useFormatters';
import { useLocalizedField } from '@/composables/useLocalizedField';
import de from '@/i18n/messages/admin-de';
import en from '@/i18n/messages/admin-en';

export function useAdminI18n() {
    const composer = useI18n({
        useScope: 'local',
        messages: { de, en },
    });
    const formatters = useFormatters();
    const { localizedField } = useLocalizedField();

    const formatDate = (
        value: string | number | Date | null | undefined,
    ): string =>
        formatters.formatDate(value, {
            dateStyle: 'medium',
            timeStyle: 'short',
        });

    const formatNumber = (value: number | null | undefined): string =>
        formatters.formatNumber(value ?? 0);

    const formatCurrency = (
        cents: number | null | undefined,
        currency = 'EUR',
    ): string =>
        formatters.formatCurrency((cents ?? 0) / 100, currency.toUpperCase());

    const formatBytes = (bytes: number | null | undefined): string => {
        if (!bytes) {
            return '0 B';
        }

        const units = ['B', 'KB', 'MB', 'GB'];
        const exponent = Math.min(
            Math.floor(Math.log(bytes) / Math.log(1024)),
            units.length - 1,
        );
        const value = bytes / 1024 ** exponent;

        return `${formatters.formatNumber(value, {
            maximumFractionDigits: exponent === 0 ? 0 : 1,
        })} ${units[exponent]}`;
    };

    const humanize = (value: string | null | undefined): string => {
        if (!value) {
            return '—';
        }

        const key = `values.${value}`;

        if (composer.te(key)) {
            return composer.t(key);
        }

        return value
            .replace(/\\/g, ' · ')
            .replace(/[_-]/g, ' ')
            .replace(/\b\p{L}/gu, (letter) =>
                letter.toLocaleUpperCase(composer.locale.value),
            );
    };

    return {
        ...composer,
        ...formatters,
        formatBytes,
        formatCurrency,
        formatDate,
        formatNumber,
        humanize,
        localizedField,
    };
}
