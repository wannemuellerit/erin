import type { StatusTone } from '@/types';

export type AdminPaginatorLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type AdminPaginator<T> = {
    data: T[];
    current_page: number;
    first_page_url?: string;
    from: number | null;
    last_page: number;
    last_page_url?: string;
    links: AdminPaginatorLink[];
    next_page_url?: string | null;
    path?: string;
    per_page: number;
    prev_page_url?: string | null;
    to: number | null;
    total: number;
};

const dateFormatter = new Intl.DateTimeFormat('de-DE', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const numberFormatter = new Intl.NumberFormat('de-DE');

const labels: Record<string, string> = {
    active: 'Aktiv',
    applied: 'Beworben',
    approved: 'Freigegeben',
    archived: 'Archiviert',
    blocked: 'Gesperrt',
    canceled: 'Storniert',
    cancelled: 'Storniert',
    candidate: 'Fachkraft',
    clicked: 'Geklickt',
    clean: 'Sauber',
    closed: 'Geschlossen',
    company_admin: 'Firmenadmin',
    company_owner: 'Firmeninhaber',
    completed: 'Abgeschlossen',
    cv: 'Lebenslauf',
    draft: 'Entwurf',
    driving_license: 'Führerschein',
    enabled: 'Aktiv',
    employer: 'Unternehmen',
    failed: 'Fehlgeschlagen',
    health_certificate: 'Gesundheitsnachweis',
    hired: 'Eingestellt',
    holding: 'Haltefrist',
    in_progress: 'In Arbeit',
    in_review: 'In Prüfung',
    inactive: 'Inaktiv',
    identity_card: 'Personalausweis',
    infected: 'Bedrohung gefunden',
    language_certificate: 'Sprachzertifikat',
    open: 'Offen',
    low: 'Niedrig',
    normal: 'Normal',
    high: 'Hoch',
    urgent: 'Dringend',
    paid: 'Ausgezahlt',
    passport: 'Reisepass',
    past_due: 'Zahlung überfällig',
    pending: 'Ausstehend',
    processing: 'In Bearbeitung',
    qualification: 'Ausbildungsnachweis',
    recruiter: 'Recruiter',
    registered: 'Registriert',
    rejected: 'Abgelehnt',
    resolved: 'Gelöst',
    restricted: 'Eingeschränkt',
    support: 'Support',
    suspended: 'Suspendiert',
    super_admin: 'Superadmin',
    uploaded: 'Hochgeladen',
    unknown: 'Unbekannt',
    verified: 'Verifiziert',
    viewer: 'Leser',
    waiting_for_customer: 'Wartet auf Rückmeldung',
    employment_reference: 'Arbeitszeugnis',
    police_clearance: 'Führungszeugnis',
    expired: 'Abgelaufen',
};

export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : dateFormatter.format(date);
}

export function formatNumber(value: number | null | undefined): string {
    return numberFormatter.format(value ?? 0);
}

export function formatCurrency(
    cents: number | null | undefined,
    currency = 'EUR',
): string {
    return new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: currency.toUpperCase(),
    }).format((cents ?? 0) / 100);
}

export function formatBytes(bytes: number | null | undefined): string {
    if (!bytes) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    const exponent = Math.min(
        Math.floor(Math.log(bytes) / Math.log(1024)),
        units.length - 1,
    );
    const value = bytes / 1024 ** exponent;

    return `${new Intl.NumberFormat('de-DE', {
        maximumFractionDigits: exponent === 0 ? 0 : 1,
    }).format(value)} ${units[exponent]}`;
}

export function humanize(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return (
        labels[value] ??
        value
            .replace(/\\/g, ' · ')
            .replace(/[_-]/g, ' ')
            .replace(/\b\w/g, (letter) => letter.toUpperCase())
    );
}

export function statusTone(status: string | null | undefined): StatusTone {
    if (!status) {
        return 'slate';
    }

    if (
        [
            'active',
            'approved',
            'clean',
            'completed',
            'enabled',
            'paid',
            'resolved',
            'verified',
        ].includes(status)
    ) {
        return 'green';
    }

    if (['hired', 'processing'].includes(status)) {
        return 'teal';
    }

    if (
        [
            'blocked',
            'failed',
            'infected',
            'past_due',
            'rejected',
            'suspended',
        ].includes(status)
    ) {
        return 'red';
    }

    if (['holding', 'in_review', 'pending'].includes(status)) {
        return 'yellow';
    }

    if (['in_progress', 'open'].includes(status)) {
        return 'blue';
    }

    if (['draft', 'restricted'].includes(status)) {
        return 'violet';
    }

    return 'slate';
}

export function cleanFilters(
    filters: Record<string, string | number | null | undefined>,
): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(filters).filter(
            ([, value]) =>
                value !== '' && value !== null && value !== undefined,
        ),
    ) as Record<string, string | number>;
}

export function isPast(value: string | null | undefined): boolean {
    return value ? new Date(value).getTime() < Date.now() : false;
}
