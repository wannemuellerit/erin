import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

export function useFormatters() {
    const { locale } = useI18n();
    const intlLocale = computed(() =>
        locale.value === 'en' ? 'en-GB' : 'de-DE',
    );

    const formatDate = (
        value: string | number | Date | null | undefined,
        options: Intl.DateTimeFormatOptions = { dateStyle: 'medium' },
        fallback = '—',
    ): string => {
        if (value === null || value === undefined || value === '') {
            return fallback;
        }

        const date = value instanceof Date ? value : new Date(value);

        return Number.isNaN(date.getTime())
            ? String(value)
            : new Intl.DateTimeFormat(intlLocale.value, options).format(date);
    };

    const formatNumber = (
        value: number,
        options?: Intl.NumberFormatOptions,
    ): string => new Intl.NumberFormat(intlLocale.value, options).format(value);

    const formatCurrency = (
        value: number,
        currency = 'EUR',
        options: Omit<Intl.NumberFormatOptions, 'style' | 'currency'> = {},
    ): string =>
        new Intl.NumberFormat(intlLocale.value, {
            style: 'currency',
            currency,
            ...options,
        }).format(value);

    return {
        formatCurrency,
        formatDate,
        formatNumber,
        intlLocale,
    };
}
