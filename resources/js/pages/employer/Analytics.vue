<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    BarChart3,
    BriefcaseBusiness,
    CalendarClock,
    CheckCircle2,
    MessagesSquare,
    UsersRound,
} from '@lucide/vue';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import DataTable from '@/components/product/DataTable.vue';
import MetricBarList from '@/components/product/MetricBarList.vue';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { useFormatters } from '@/composables/useFormatters';
import { useStatusLabels } from '@/composables/useStatusLabels';
import type { ProductTableRow, TableColumn } from '@/types';

type Analytics = {
    summary: {
        applications: number;
        interviews: number;
        hires: number;
        interview_rate: number;
        hire_rate: number;
        average_days_to_hire: number | null;
    };
    jobs: Array<{
        id: number;
        title: string;
        status: string;
        applications: number;
        interviews: number;
        hires: number;
        interview_rate: number;
        hire_rate: number;
    }>;
    countries: Array<{
        country: string;
        applications: number;
        share: number;
    }>;
    timeline: Array<{
        period: string;
        label: string;
        applications: number;
        interviews: number;
        hires: number;
    }>;
};

const props = defineProps<{
    analytics: Analytics;
    filters: { from: string; to: string };
}>();

const { t } = useI18n();
const { formatNumber } = useFormatters();
const { statusLabel } = useStatusLabels();
const filters = reactive({ ...props.filters });
const columns = computed<TableColumn[]>(() => [
    { key: 'title', label: t('operations.analytics.job') },
    {
        key: 'applications',
        label: t('operations.analytics.applications'),
        align: 'right',
    },
    {
        key: 'interviews',
        label: t('operations.analytics.interviews'),
        align: 'right',
    },
    {
        key: 'hires',
        label: t('operations.analytics.hires'),
        align: 'right',
    },
    {
        key: 'conversion',
        label: t('operations.analytics.conversion'),
        align: 'right',
    },
    { key: 'status', label: t('operations.analytics.status') },
]);
const rows = computed<ProductTableRow[]>(() =>
    props.analytics.jobs.map((job) => ({
        ...job,
        conversion: `${formatNumber(job.hire_rate, {
            maximumFractionDigits: 1,
        })} %`,
    })),
);
const countryBars = computed(() =>
    props.analytics.countries.map((country) => ({
        id: country.country,
        label: country.country,
        value: country.applications,
        detail: `${formatNumber(country.applications)} · ${formatNumber(
            country.share,
            { maximumFractionDigits: 1 },
        )} %`,
    })),
);
const maxTimeline = computed(() =>
    Math.max(1, ...props.analytics.timeline.map((item) => item.applications)),
);
const timelineHeight = (value: number) =>
    value <= 0 ? '0%' : `${Math.max(3, (value / maxTimeline.value) * 100)}%`;

const applyFilters = () => {
    router.get('/employer/analytics', filters, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};
</script>

<template>
    <Head :title="t('operations.analytics.title')" />

    <div class="erin-page">
        <PageHeader
            :eyebrow="t('operations.analytics.eyebrow')"
            :title="t('operations.analytics.title')"
            :description="t('operations.analytics.description')"
            :icon="BarChart3"
        >
            <template #actions>
                <form
                    class="flex flex-wrap items-end gap-2"
                    @submit.prevent="applyFilters"
                >
                    <label class="text-xs font-bold text-slate-600">
                        {{ t('operations.analytics.from') }}
                        <input
                            v-model="filters.from"
                            type="date"
                            class="erin-focus mt-1 block h-10 rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </label>
                    <label class="text-xs font-bold text-slate-600">
                        {{ t('operations.analytics.to') }}
                        <input
                            v-model="filters.to"
                            type="date"
                            class="erin-focus mt-1 block h-10 rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </label>
                    <button
                        type="submit"
                        class="erin-focus h-10 rounded-xl bg-blue-600 px-4 text-xs font-bold text-white"
                    >
                        {{ t('operations.analytics.apply') }}
                    </button>
                </form>
            </template>
        </PageHeader>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <MetricCard
                :label="t('operations.analytics.applications')"
                :value="analytics.summary.applications"
                :icon="UsersRound"
                tone="blue"
            />
            <MetricCard
                :label="t('operations.analytics.interviewRate')"
                :value="`${analytics.summary.interview_rate} %`"
                :hint="`${analytics.summary.interviews} ${t('operations.analytics.interviews')}`"
                :icon="MessagesSquare"
                tone="teal"
            />
            <MetricCard
                :label="t('operations.analytics.hireRate')"
                :value="`${analytics.summary.hire_rate} %`"
                :hint="`${analytics.summary.hires} ${t('operations.analytics.hires')}`"
                :icon="CheckCircle2"
                tone="orange"
            />
            <MetricCard
                :label="t('operations.analytics.averageDays')"
                :value="
                    analytics.summary.average_days_to_hire === null
                        ? t('operations.analytics.noValue')
                        : t('operations.analytics.days', {
                              count: analytics.summary.average_days_to_hire,
                          })
                "
                :icon="CalendarClock"
                tone="violet"
            />
            <MetricCard
                :label="t('operations.analytics.jobPerformance')"
                :value="analytics.jobs.length"
                :icon="BriefcaseBusiness"
                tone="blue"
            />
        </div>

        <SectionCard :title="t('operations.analytics.timeline')">
            <table class="sr-only">
                <caption>
                    {{
                        t('operations.analytics.timeline')
                    }}
                </caption>
                <thead>
                    <tr>
                        <th scope="col">
                            {{ t('operations.analytics.period') }}
                        </th>
                        <th scope="col">
                            {{ t('operations.analytics.applications') }}
                        </th>
                        <th scope="col">
                            {{ t('operations.analytics.interviews') }}
                        </th>
                        <th scope="col">
                            {{ t('operations.analytics.hires') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in analytics.timeline" :key="item.period">
                        <th scope="row">{{ item.label }}</th>
                        <td>{{ formatNumber(item.applications) }}</td>
                        <td>{{ formatNumber(item.interviews) }}</td>
                        <td>{{ formatNumber(item.hires) }}</td>
                    </tr>
                </tbody>
            </table>
            <div
                class="flex min-h-56 items-end gap-2 overflow-x-auto pb-2"
                aria-hidden="true"
            >
                <div
                    v-for="item in analytics.timeline"
                    :key="item.period"
                    class="flex min-w-12 flex-1 flex-col items-center gap-2"
                >
                    <div
                        class="flex h-40 w-full items-end justify-center gap-1"
                    >
                        <span
                            class="w-3 rounded-t bg-blue-500"
                            :style="{
                                height: timelineHeight(item.applications),
                            }"
                            :title="`${item.applications} ${t('operations.analytics.applications')}`"
                        />
                        <span
                            class="w-3 rounded-t bg-teal-500"
                            :style="{
                                height: timelineHeight(item.interviews),
                            }"
                            :title="`${item.interviews} ${t('operations.analytics.interviews')}`"
                        />
                        <span
                            class="w-3 rounded-t bg-orange-500"
                            :style="{
                                height: timelineHeight(item.hires),
                            }"
                            :title="`${item.hires} ${t('operations.analytics.hires')}`"
                        />
                    </div>
                    <span class="max-w-20 truncate text-[10px] text-slate-500">
                        {{ item.label }}
                    </span>
                </div>
            </div>
            <div
                class="mt-3 flex flex-wrap justify-center gap-4 text-xs text-slate-500"
            >
                <span class="inline-flex items-center gap-1.5">
                    <i class="size-2 rounded-full bg-blue-500" />
                    {{ t('operations.analytics.applications') }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <i class="size-2 rounded-full bg-teal-500" />
                    {{ t('operations.analytics.interviews') }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <i class="size-2 rounded-full bg-orange-500" />
                    {{ t('operations.analytics.hires') }}
                </span>
            </div>
        </SectionCard>

        <div
            class="grid gap-5 xl:grid-cols-[minmax(0,1.45fr)_minmax(20rem,.55fr)]"
        >
            <SectionCard
                :title="t('operations.analytics.jobPerformance')"
                flush
            >
                <DataTable
                    :columns="columns"
                    :rows="rows"
                    :empty-label="t('operations.analytics.noJobs')"
                >
                    <template #cell-title="{ value }">
                        <span class="font-bold text-slate-900">
                            {{ value }}
                        </span>
                    </template>
                    <template #cell-status="{ value }">
                        <StatusBadge
                            :label="statusLabel('job', String(value))"
                            tone="slate"
                            :dot="false"
                        />
                    </template>
                </DataTable>
            </SectionCard>
            <SectionCard :title="t('operations.analytics.countrySources')">
                <MetricBarList
                    v-if="countryBars.length"
                    :items="countryBars"
                    tone="teal"
                />
                <p v-else class="py-8 text-center text-sm text-slate-500">
                    {{ t('operations.analytics.noCountries') }}
                </p>
            </SectionCard>
        </div>
    </div>
</template>
