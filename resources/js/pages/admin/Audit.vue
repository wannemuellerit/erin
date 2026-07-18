<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Search, ScrollText, X } from '@lucide/vue';
import { reactive } from 'vue';
import EmptyState from '@/components/product/EmptyState.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import adminAudit from '@/routes/admin/audit';
import AdminPagination from './_components/AdminPagination.vue';
import { useAdminI18n } from './_i18n';
import { cleanFilters } from './_shared';
import type { AdminPaginator } from './_shared';

type AuditLogRow = {
    id: number;
    actor_id: number | null;
    company_id: number | null;
    event: string;
    auditable_type: string | null;
    auditable_id: number | null;
    before_values: Record<string, unknown> | null;
    after_values: Record<string, unknown> | null;
    metadata: Record<string, unknown> | null;
    ip_address: string | null;
    user_agent: string | null;
    created_at: string;
    actor: {
        id: number;
        name: string;
        email: string;
        role: string;
    } | null;
    company: {
        id: number;
        name: string;
        slug: string;
    } | null;
};

type AuditFilters = {
    search?: string;
    event?: string;
    actor_id?: number | string;
    company_id?: number | string;
    from?: string;
    until?: string;
};

const props = defineProps<{
    logs: AdminPaginator<AuditLogRow>;
    filters: AuditFilters;
    events: string[];
}>();

const filters = reactive({
    search: props.filters.search ?? '',
    event: props.filters.event ?? '',
    actor_id: props.filters.actor_id?.toString() ?? '',
    company_id: props.filters.company_id?.toString() ?? '',
    from: props.filters.from ?? '',
    until: props.filters.until ?? '',
});

const { t, formatDate, humanize } = useAdminI18n();

function applyFilters(): void {
    router.get(adminAudit.index.url(), cleanFilters(filters), {
        preserveState: true,
        replace: true,
    });
}

function resetFilters(): void {
    router.get(adminAudit.index.url(), {}, { replace: true });
}

function targetLabel(log: AuditLogRow): string {
    const type = log.auditable_type?.split('\\').at(-1);

    return type
        ? `${humanize(type)}${log.auditable_id ? ` #${log.auditable_id}` : ''}`
        : t('common.system');
}

function hasDetails(log: AuditLogRow): boolean {
    return Boolean(log.before_values || log.after_values || log.metadata);
}
</script>

<template>
    <Head :title="t('audit.metaTitle')" />

    <div class="erin-page">
        <PageHeader
            :eyebrow="t('audit.eyebrow')"
            :title="t('audit.title')"
            :description="t('audit.description', { count: logs.total })"
            :icon="ScrollText"
        />

        <SectionCard flush>
            <form
                class="grid gap-3 border-b border-slate-100 p-4 xl:grid-cols-[minmax(14rem,1fr)_13rem_8rem_8rem_10rem_10rem_auto]"
                @submit.prevent="applyFilters"
            >
                <label class="relative">
                    <span class="sr-only">{{ t('audit.searchLabel') }}</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
                    />
                    <input
                        v-model="filters.search"
                        type="search"
                        :placeholder="t('audit.searchPlaceholder')"
                        class="erin-focus h-11 w-full rounded-xl border border-slate-200 pr-3 pl-10 text-sm"
                    />
                </label>
                <select
                    v-model="filters.event"
                    :aria-label="t('audit.event')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                >
                    <option value="">{{ t('audit.allEvents') }}</option>
                    <option v-for="event in events" :key="event" :value="event">
                        {{ event }}
                    </option>
                </select>
                <input
                    v-model="filters.actor_id"
                    type="number"
                    min="1"
                    :placeholder="t('audit.actorId')"
                    :aria-label="t('audit.actorId')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 px-3 text-sm"
                />
                <input
                    v-model="filters.company_id"
                    type="number"
                    min="1"
                    :placeholder="t('common.companyId')"
                    :aria-label="t('common.companyId')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 px-3 text-sm"
                />
                <input
                    v-model="filters.from"
                    type="date"
                    :aria-label="t('common.from')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 px-3 text-sm"
                />
                <input
                    v-model="filters.until"
                    type="date"
                    :aria-label="t('common.until')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 px-3 text-sm"
                />
                <div class="flex gap-2">
                    <button
                        type="submit"
                        class="erin-focus h-11 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white"
                    >
                        {{ t('common.filter') }}
                    </button>
                    <button
                        type="button"
                        :aria-label="t('common.resetFilters')"
                        class="erin-focus grid size-11 place-items-center rounded-xl border border-slate-200 text-slate-500"
                        @click="resetFilters"
                    >
                        <X class="size-4" />
                    </button>
                </div>
            </form>

            <div v-if="logs.data.length > 0" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left">
                    <thead class="bg-slate-50/80">
                        <tr
                            class="text-[11px] font-bold tracking-wide text-slate-500 uppercase"
                        >
                            <th class="px-5 py-3">
                                {{ t('audit.columns.event') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('audit.columns.actor') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('audit.columns.context') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('audit.columns.network') }}
                            </th>
                            <th class="px-5 py-3 text-right">
                                {{ t('audit.columns.time') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr
                            v-for="log in logs.data"
                            :key="log.id"
                            class="align-top"
                        >
                            <td class="px-5 py-4">
                                <p
                                    class="font-mono text-xs font-bold text-slate-800"
                                >
                                    {{ log.event }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ targetLabel(log) }}
                                </p>
                                <details v-if="hasDetails(log)" class="mt-2">
                                    <summary
                                        class="cursor-pointer text-xs font-semibold text-blue-600"
                                    >
                                        {{ t('audit.changeDetails') }}
                                    </summary>
                                    <div class="mt-2 max-w-xl space-y-2">
                                        <pre
                                            v-if="log.before_values"
                                            class="overflow-auto rounded-lg bg-slate-950 p-3 text-[10px] text-slate-200"
                                            >{{ t('audit.before') }}: {{
                                                JSON.stringify(
                                                    log.before_values,
                                                    null,
                                                    2,
                                                )
                                            }}</pre>
                                        <pre
                                            v-if="log.after_values"
                                            class="overflow-auto rounded-lg bg-slate-950 p-3 text-[10px] text-slate-200"
                                            >{{ t('audit.after') }}: {{
                                                JSON.stringify(
                                                    log.after_values,
                                                    null,
                                                    2,
                                                )
                                            }}</pre>
                                        <pre
                                            v-if="log.metadata"
                                            class="overflow-auto rounded-lg bg-slate-950 p-3 text-[10px] text-slate-200"
                                            >{{ t('audit.metadata') }}: {{
                                                JSON.stringify(
                                                    log.metadata,
                                                    null,
                                                    2,
                                                )
                                            }}</pre>
                                    </div>
                                </details>
                            </td>
                            <td class="px-5 py-4">
                                <p class="text-sm font-semibold text-slate-800">
                                    {{ log.actor?.name ?? t('common.system') }}
                                </p>
                                <p
                                    v-if="log.actor"
                                    class="mt-0.5 text-xs text-slate-500"
                                >
                                    {{ log.actor.email }}
                                </p>
                                <p
                                    v-if="log.actor"
                                    class="mt-1 text-[11px] text-slate-600"
                                >
                                    {{ humanize(log.actor.role) }} · #{{
                                        log.actor.id
                                    }}
                                </p>
                            </td>
                            <td class="px-5 py-4 text-xs text-slate-600">
                                <p>
                                    {{
                                        log.company?.name ??
                                        t('common.noCompany')
                                    }}
                                </p>
                                <p
                                    v-if="log.company"
                                    class="mt-1 text-slate-600"
                                >
                                    {{
                                        t('audit.companyReference', {
                                            id: log.company.id,
                                        })
                                    }}
                                </p>
                            </td>
                            <td class="px-5 py-4 text-xs text-slate-600">
                                <p>{{ log.ip_address ?? '—' }}</p>
                                <p
                                    v-if="log.user_agent"
                                    class="mt-1 max-w-56 truncate text-slate-600"
                                    :title="log.user_agent"
                                >
                                    {{ log.user_agent }}
                                </p>
                            </td>
                            <td
                                class="px-5 py-4 text-right text-xs whitespace-nowrap text-slate-500"
                            >
                                {{ formatDate(log.created_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState
                v-else
                :title="t('common.emptyTitle')"
                :description="t('common.emptyDescription')"
            />
            <AdminPagination :paginator="logs" />
        </SectionCard>
    </div>
</template>
