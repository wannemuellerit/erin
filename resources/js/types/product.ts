import type { LucideIcon } from '@lucide/vue';

export type ProductRole = 'candidate' | 'employer' | 'support' | 'super_admin';

export type ProductNavItem = {
    label: string;
    href: string;
    icon: LucideIcon;
    badge?: string | number;
};

export type ProductNavGroup = {
    label?: string;
    items: ProductNavItem[];
};

export type Metric = {
    label: string;
    value: string | number;
    change?: string;
    trend?: 'up' | 'down' | 'neutral';
    hint?: string;
};

export type StatusTone =
    | 'blue'
    | 'teal'
    | 'orange'
    | 'green'
    | 'yellow'
    | 'red'
    | 'slate'
    | 'violet';

export type TableColumn = {
    key: string;
    label: string;
    align?: 'left' | 'center' | 'right';
};

export type ProductTableRow = Record<string, unknown> & {
    id: string | number;
};
