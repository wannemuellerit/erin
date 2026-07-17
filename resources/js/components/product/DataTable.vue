<script setup lang="ts">
import { MoreHorizontal } from '@lucide/vue';
import type { ProductTableRow, TableColumn } from '@/types';

withDefaults(
    defineProps<{
        columns: TableColumn[];
        rows: ProductTableRow[];
        emptyLabel?: string;
    }>(),
    {
        emptyLabel: 'Noch keine Einträge vorhanden.',
    },
);
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 bg-slate-50/70">
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        class="px-5 py-3 text-[11px] font-bold tracking-wider text-slate-500 uppercase"
                        :class="{
                            'text-center': column.align === 'center',
                            'text-right': column.align === 'right',
                        }"
                    >
                        {{ column.label }}
                    </th>
                    <th class="w-12 px-5 py-3">
                        <span class="sr-only">Aktionen</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in rows"
                    :key="row.id"
                    class="border-b border-slate-100 last:border-0 hover:bg-slate-50/70"
                >
                    <td
                        v-for="column in columns"
                        :key="column.key"
                        class="px-5 py-4 text-slate-600"
                        :class="{
                            'text-center': column.align === 'center',
                            'text-right': column.align === 'right',
                        }"
                    >
                        <slot
                            :name="`cell-${column.key}`"
                            :row="row"
                            :value="row[column.key]"
                        >
                            {{ row[column.key] }}
                        </slot>
                    </td>
                    <td class="px-5 py-4 text-right">
                        <slot name="actions" :row="row">
                            <button
                                type="button"
                                class="erin-focus rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                aria-label="Weitere Aktionen"
                            >
                                <MoreHorizontal class="size-4" />
                            </button>
                        </slot>
                    </td>
                </tr>
                <tr v-if="rows.length === 0">
                    <td
                        :colspan="columns.length + 1"
                        class="px-5 py-12 text-center text-slate-400"
                    >
                        {{ emptyLabel }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
