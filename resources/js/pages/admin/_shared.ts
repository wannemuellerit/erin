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
