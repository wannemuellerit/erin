<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BriefcaseBusiness,
    Eye,
    Plus,
    Search,
    Sparkles,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import DataTable from '@/components/product/DataTable.vue';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
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
const rows = computed<ProductTableRow[]>(() =>
    props.jobs.map((job) => ({
        ...job,
        applications: job.applications_count ?? 0,
        location_label:
            job.location?.city ?? job.location?.name ?? 'Kein Standort',
        occupation_label:
            job.occupation?.name_de ??
            job.occupation?.name_en ??
            job.position ??
            '—',
        updated: job.updated_at
            ? new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium' }).format(
                  new Date(job.updated_at),
              )
            : '—',
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
const columns = [
    { key: 'title', label: 'Stellenanzeige' },
    { key: 'status', label: 'Status' },
    { key: 'applications', label: 'Bewerbungen', align: 'center' as const },
    { key: 'occupation_label', label: 'Beruf' },
    { key: 'location_label', label: 'Standort' },
    { key: 'updated', label: 'Aktualisiert', align: 'right' as const },
];
</script>

<template>
    <Head title="Stellenanzeigen" />
    <div class="erin-page">
        <PageHeader
            eyebrow="Recruiting"
            title="Stellenanzeigen"
            description="Erstellen, veröffentlichen und optimieren Sie Ihre offenen Positionen."
            :icon="BriefcaseBusiness"
        >
            <template #actions>
                <Link
                    :href="create()"
                    class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--erin-primary)] px-4 text-sm font-bold text-white hover:bg-[var(--erin-primary-hover)]"
                    ><Plus class="size-4" /> Neue Stellenanzeige</Link
                >
            </template>
        </PageHeader>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard
                label="Aktive Job-Slots"
                :value="`${entitlements.jobs?.used ?? published} / ${entitlements.jobs?.limit ?? '∞'}`"
                hint="Aktuell veröffentlicht"
                :icon="BriefcaseBusiness"
            />
            <MetricCard
                label="Bewerbungen gesamt"
                :value="applications"
                :icon="Users"
                tone="teal"
            />
            <MetricCard
                label="Stellen gesamt"
                :value="jobs.length"
                :icon="Eye"
                tone="violet"
            />
            <MetricCard
                label="Boosts verfügbar"
                :value="entitlements.boosts?.remaining ?? 0"
                hint="in dieser Laufzeit"
                :icon="Sparkles"
                tone="orange"
            />
        </div>
        <SectionCard flush>
            <template #actions>
                <div class="relative">
                    <Search
                        class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400"
                    /><input
                        type="search"
                        placeholder="Stelle suchen …"
                        class="h-9 w-64 rounded-lg border border-slate-200 pl-9 text-xs"
                    />
                </div>
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
                            {{ row.employment_type }}
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
                            >Bearbeiten</Link
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
                            Veröffentlichen
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
                            Pausieren
                        </button>
                    </div>
                </template>
            </DataTable>
            <div
                v-if="rows.length === 0"
                class="grid min-h-64 place-items-center p-8 text-center"
            >
                <div>
                    <BriefcaseBusiness class="mx-auto size-8 text-slate-300" />
                    <h2 class="mt-4 font-bold">Noch keine Stellenanzeigen</h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Erstellen Sie Ihre erste Stelle und veröffentlichen Sie
                        sie, sobald alle Angaben vollständig sind.
                    </p>
                </div>
            </div>
        </SectionCard>
    </div>
</template>
