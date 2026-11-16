import de from '@/i18n/messages/product-components-de';
import en from '@/i18n/messages/product-components-en';
import es from '@/i18n/messages/product-components-es';
import hr from '@/i18n/messages/product-components-hr';
import pl from '@/i18n/messages/product-components-pl';
import pt from '@/i18n/messages/product-components-pt';
import ro from '@/i18n/messages/product-components-ro';
import type { SupportedLocale } from '@/i18n';

export const productMessages = {
    de,
    en,
    pl,
    ro,
    hr,
    es,
    pt,
} satisfies Record<SupportedLocale, typeof de>;
