<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { BriefcaseBusiness, Eye, Plus, Sparkles, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import DataTable from '@/components/product/DataTable.vue';
import EmptyState from '@/components/product/EmptyState.vue';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import SearchField from '@/components/product/SearchField.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { useFormatters } from '@/composables/useFormatters';
import { useLocalizedField } from '@/composables/useLocalizedField';
import { useStatusLabels } from '@/composables/useStatusLabels';
import { create, edit, status as updateStatus } from '@/routes/employer/jobs';
import type { ProductTableRow } from '@/types';

type Job = ProductTableRow & {
    title: string;
    status: string;
    position?: string;
    employment_type?: string;
    applications_count?: number;
    updated_at?: string;
    boosted_until?: string | null;
    location?: { name?: string; city?: string } | null;
    occupation?: { name_de?: string; name_en?: string } | null;
};
type Usage = { used: number; limit: number | null; remaining: number };
type Entitlements = {
    jobs?: Usage;
    boosts?: Usage;
};
const props = withDefaults(
    defineProps<{ jobs?: Job[]; entitlements?: Entitlements }>(),
    {
        jobs: () => [],
        entitlements: () => ({}),
    },
);
const { t, te } = useI18n();
const { formatDate } = useFormatters();
const { localizedField } = useLocalizedField();
const search = ref('');
const rows = computed<ProductTableRow[]>(() =>
    props.jobs
        .filter((job) => {
            const needle = search.value.trim().toLowerCase();

            return (
                !needle ||
                [
                    job.title,
                    job.position,
                    job.location?.name,
                    job.location?.city,
                    localizedField(job.occupation, 'name', ''),
                ]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase()
                    .includes(needle)
            );
        })
        .map((job) => ({
            ...job,
            applications: job.applications_count ?? 0,
            location_label:
                job.location?.city ??
                job.location?.name ??
                t('employer.jobs.noLocation'),
            occupation_label: localizedField(
                job.occupation,
                'name',
                job.position ?? '—',
            ),
            updated: job.updated_at ? formatDate(job.updated_at) : '—',
        })),
);
const published = computed(
    () => props.jobs.filter((job) => job.status === 'published').length,
);
const applications = computed(() =>
    props.jobs.reduce((total, job) => total + (job.applications_count ?? 0), 0),
);
const { statusLabel } = useStatusLabels();
const jobStatusLabel = (value: unknown) => statusLabel('job', String(value));
const columns = computed(() => [
    { key: 'title', label: t('employer.jobs.columns.job') },
    { key: 'status', label: t('employer.jobs.columns.status') },
    {
        key: 'applications',
        label: t('employer.jobs.columns.applications'),
        align: 'center' as const,
    },
    { key: 'occupation_label', label: t('employer.jobs.columns.occupation') },
    { key: 'location_label', label: t('employer.jobs.columns.location') },
    {
        key: 'updated',
        label: t('employer.jobs.columns.updated'),
        align: 'right' as const,
    },
]);
const employmentTypeLabel = (value: unknown) => {
    const status = String(value ?? '');
    const key = `employer.common.employmentTypes.${status}`;

    return te(key) ? t(key) : status.replaceAll('_', ' ');
};
</script>

<template>
    <Head :title="t('employer.jobs.metaTitle')" />
    <div class="erin-page">
        <PageHeader
            :eyebrow="t('employer.jobs.eyebrow')"
            :title="t('employer.jobs.title')"
            :description="t('employer.jobs.description')"
            :icon="BriefcaseBusiness"
        >
            <template #actions>
                <Link
                    :href="create()"
                    class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--erin-primary)] px-4 text-sm font-bold text-white hover:bg-[var(--erin-primary-hover)]"
                    ><Plus class="size-4" />
                    {{ t('employer.jobs.create') }}</Link
                >
            </template>
        </PageHeader>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard
                :label="t('employer.jobs.metrics.activeSlots')"
                :value="`${entitlements.jobs?.used ?? published} / ${entitlements.jobs?.limit ?? '∞'}`"
                :hint="t('employer.jobs.metrics.published')"
                :icon="BriefcaseBusiness"
            />
            <MetricCard
                :label="t('employer.jobs.metrics.applications')"
                :value="applications"
                :icon="Users"
                tone="teal"
            />
            <MetricCard
                :label="t('employer.jobs.metrics.total')"
                :value="jobs.length"
                :icon="Eye"
                tone="violet"
            />
            <MetricCard
                :label="t('employer.jobs.metrics.boosts')"
                :value="entitlements.boosts?.remaining ?? 0"
                :hint="t('employer.jobs.metrics.currentTerm')"
                :icon="Sparkles"
                tone="orange"
            />
        </div>
        <SectionCard flush>
            <template #actions>
                <SearchField
                    v-model="search"
                    size="sm"
                    class="w-64"
                    :placeholder="t('employer.jobs.searchPlaceholder')"
                />
            </template>
            <DataTable :columns="columns" :rows="rows">
                <template #cell-title="{ row }"
                    ><div>
                        <Link
                            :href="edit(Number(row.id))"
                            class="font-bold text-slate-900 hover:text-[var(--erin-primary)]"
                            >{{ row.title }}</Link
                        >
                        <p class="mt-0.5 text-xs text-slate-400">
                            {{ employmentTypeLabel(row.employment_type) }}
                        </p>
                    </div></template
                >
                <template #cell-status="{ value }"
                    ><StatusBadge
                        :label="jobStatusLabel(value)"
                        :tone="
                            value === 'published'
                                ? 'green'
                                : value === 'draft'
                                  ? 'slate'
                                  : 'yellow'
                        "
                /></template>
                <template #cell-applications="{ value }"
                    ><span class="font-bold text-slate-800">{{
                        value
                    }}</span></template
                >
                <template #actions="{ row }">
                    <div class="flex justify-end gap-2">
                        <Link
                            :href="edit(Number(row.id))"
                            class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[10px] font-bold text-slate-600"
                            >{{ t('employer.jobs.edit') }}</Link
                        >
                        <button
                            v-if="
                                row.status === 'draft' ||
                                row.status === 'paused'
                            "
                            type="button"
                            class="rounded-lg bg-teal-50 px-2.5 py-1.5 text-[10px] font-bold text-teal-700"
                            @click="
                                router.patch(
                                    updateStatus.url(Number(row.id)),
                                    { status: 'published' },
                                    { preserveScroll: true },
                                )
                            "
                        >
                            {{ t('employer.jobs.publish') }}
                        </button>
                        <button
                            v-else-if="row.status === 'published'"
                            type="button"
                            class="rounded-lg bg-amber-50 px-2.5 py-1.5 text-[10px] font-bold text-amber-700"
                            @click="
                                router.patch(
                                    updateStatus.url(Number(row.id)),
                                    { status: 'paused' },
                                    { preserveScroll: true },
                                )
                            "
                        >
                            {{ t('employer.jobs.pause') }}
                        </button>
                    </div>
                </template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="BriefcaseBusiness"
                        :title="t('employer.jobs.emptyTitle')"
                        :description="t('employer.jobs.emptyDescription')"
                    />
                </template>
            </DataTable>
        </SectionCard>
    </div>
</template>
