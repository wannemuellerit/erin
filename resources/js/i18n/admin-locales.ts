import de from '@/i18n/messages/admin-de';
import en from '@/i18n/messages/admin-en';
import es from '@/i18n/messages/admin-es';
import hr from '@/i18n/messages/admin-hr';
import pl from '@/i18n/messages/admin-pl';
import pt from '@/i18n/messages/admin-pt';
import ro from '@/i18n/messages/admin-ro';
import type { SupportedLocale } from '@/i18n';

export const adminMessages = {
    de,
    en,
    pl,
    ro,
    hr,
    es,
    pt,
} satisfies Record<SupportedLocale, typeof de>;
