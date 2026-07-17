<script setup lang="ts">
import { Download, Filter, Plus, Search } from '@lucide/vue';
import type { Component } from 'vue';
import DataTable from '@/components/product/DataTable.vue';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import type { ProductTableRow, StatusTone, TableColumn } from '@/types';

type AdminMetric = {
    label: string;
    value: string | number;
    hint?: string;
    change?: string;
    trend?: 'up' | 'down' | 'neutral';
    icon?: Component;
    tone?: 'blue' | 'teal' | 'orange' | 'violet';
};

withDefaults(
    defineProps<{
        eyebrow?: string;
        title: string;
        description: string;
        icon?: Component;
        metrics?: AdminMetric[];
        columns: TableColumn[];
        rows: ProductTableRow[];
        searchPlaceholder?: string;
        primaryKey?: string;
        secondaryKey?: string;
        statusKey?: string;
        createLabel?: string;
    }>(),
    {
        eyebrow: 'Administration',
        icon: undefined,
        metrics: () => [],
        searchPlaceholder: 'Durchsuchen …',
        primaryKey: 'name',
        secondaryKey: 'email',
        statusKey: 'status',
        createLabel: '',
    },
);

const statusTone = (status: unknown): StatusTone => {
    const value = String(status).toLowerCase();

    if (
        ['aktiv', 'bezahlt', 'verifiziert', 'erledigt', 'freigegeben'].some(
            (item) => value.includes(item),
        )
    ) {
        return 'green';
    }

    if (
        ['gesperrt', 'fehlgeschlagen', 'kritisch', 'abgelehnt'].some((item) =>
            value.includes(item),
        )
    ) {
        return 'red';
    }

    if (
        ['prüfung', 'offen', 'neu', 'laufend'].some((item) =>
            value.includes(item),
        )
    ) {
        return 'blue';
    }

    if (
        ['wartet', 'ausstehend', 'warnung'].some((item) => value.includes(item))
    ) {
        return 'yellow';
    }

    return 'slate';
};
</script>

<template>
    <div class="erin-page">
        <PageHeader
            :eyebrow="eyebrow"
            :title="title"
            :description="description"
            :icon="icon"
        >
            <template #actions>
                <button
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700"
                >
                    <Download class="size-4" /> Export
                </button>
                <button
                    v-if="createLabel"
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white"
                >
                    <Plus class="size-4" /> {{ createLabel }}
                </button>
            </template>
        </PageHeader>
        <section
            v-if="metrics.length"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
        >
            <MetricCard
                v-for="metric in metrics"
                :key="metric.label"
                v-bind="metric"
            />
        </section>
        <SectionCard flush>
            <div
                class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row"
            >
                <div class="relative flex-1">
                    <Search
                        class="absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
                    /><input
                        type="search"
                        :placeholder="searchPlaceholder"
                        class="h-10 w-full rounded-xl border border-slate-200 pl-10 text-sm"
                    />
                </div>
                <button
                    type="button"
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-600"
                >
                    <Filter class="size-4" /> Filter
                </button>
            </div>
            <DataTable :columns="columns" :rows="rows">
                <template #[`cell-${primaryKey}`]="{ row, value }"
                    ><div>
                        <p class="font-bold text-slate-900">{{ value }}</p>
                        <p
                            v-if="row[secondaryKey]"
                            class="mt-0.5 text-xs text-slate-400"
                        >
                            {{ row[secondaryKey] }}
                        </p>
                    </div></template
                >
                <template #[`cell-${statusKey}`]="{ value }"
                    ><StatusBadge
                        :label="String(value)"
                        :tone="statusTone(value)"
                /></template>
            </DataTable>
        </SectionCard>
    </div>
</template>
