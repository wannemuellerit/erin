<script setup lang="ts">
import { MoreHorizontal } from '@lucide/vue';
import { computed, useSlots } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/product/EmptyState.vue';
import de from '@/i18n/messages/product-components-de';
import en from '@/i18n/messages/product-components-en';
import type { ProductTableRow, TableColumn } from '@/types';

const props = withDefaults(
    defineProps<{
        columns: TableColumn[];
        rows: ProductTableRow[];
        emptyLabel?: string;
        showActions?: boolean;
        rowHover?: boolean;
        minWidth?: string;
    }>(),
    {
        emptyLabel: '',
        showActions: false,
        rowHover: true,
        minWidth: '720px',
    },
);

const slots = useSlots();
const hasActions = computed(() => props.showActions || Boolean(slots.actions));

const { t } = useI18n({
    useScope: 'local',
    messages: { de, en },
});
</script>

<template>
    <div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm" :style="{ minWidth }">
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
                        <th v-if="hasActions" class="w-12 px-5 py-3">
                            <span class="sr-only">{{
                                t('dataTable.actions')
                            }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="border-b border-slate-100 last:border-0"
                        :class="{ 'hover:bg-slate-50/70': rowHover }"
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
                        <td v-if="hasActions" class="px-5 py-4 text-right">
                            <slot name="actions" :row="row">
                                <button
                                    type="button"
                                    class="erin-focus rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                    :aria-label="t('dataTable.moreActions')"
                                >
                                    <MoreHorizontal class="size-4" />
                                </button>
                            </slot>
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td :colspan="columns.length + (hasActions ? 1 : 0)">
                            <slot name="empty">
                                <EmptyState
                                    compact
                                    :title="emptyLabel || t('dataTable.empty')"
                                    :description="null"
                                />
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="$slots.pagination">
            <slot name="pagination" />
        </div>
    </div>
</template>
