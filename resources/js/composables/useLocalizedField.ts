import { useI18n } from 'vue-i18n';

type LocalizedRecord = Record<string, unknown> | null | undefined;

export function useLocalizedField() {
    const { locale } = useI18n();

    const localizedField = (
        value: LocalizedRecord,
        field = 'name',
        fallback = '',
    ): string => {
        if (!value) {
            return fallback;
        }

        const preferredLocale = locale.value === 'en' ? 'en' : 'de';
        const fallbackLocale = preferredLocale === 'de' ? 'en' : 'de';
        const candidates = [
            value[`${field}_${preferredLocale}`],
            value[`${field}_${fallbackLocale}`],
            value[field],
        ];

        return (
            candidates.find(
                (candidate): candidate is string =>
                    typeof candidate === 'string' && candidate.trim() !== '',
            ) ?? fallback
        );
    };

    return { localizedField };
}
