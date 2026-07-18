<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Building2, Clock3, MapPin, ShieldCheck, Users } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '@/components/product/PageHeader.vue';
import SearchField from '@/components/product/SearchField.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { useFormatters } from '@/composables/useFormatters';
import { show } from '@/routes/candidate/companies';

type Company = {
    id: number;
    name: string;
    slug?: string;
    industry?: string | null;
    city?: string | null;
    country_code?: string | null;
    employee_count?: number | null;
    description?: string | null;
    logo_url?: string | null;
    benefits?: string[] | Record<string, boolean> | null;
    last_active_at?: string | null;
    relevant_jobs_count?: number;
};
withDefaults(defineProps<{ companies?: Company[] }>(), { companies: () => [] });
const { t, te } = useI18n();
const { formatDate, formatNumber } = useFormatters();
const search = ref('');
const benefits = (company: Company) => {
    if (Array.isArray(company.benefits)) {
        return company.benefits;
    }

    return Object.entries(company.benefits ?? {})
        .filter(([, enabled]) => enabled)
        .map(([label]) => label);
};
const activity = (value?: string | null) =>
    value
        ? formatDate(value, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : t('candidate.companies.activityMissing');
const benefitLabel = (benefit: string) => {
    const key = `candidate.companies.benefits.${benefit}`;

    return te(key) ? t(key) : benefit.replaceAll('_', ' ');
};
</script>

<template>
    <Head :title="t('candidate.companies.metaTitle')" />
    <div class="erin-page">
        <PageHeader
            :eyebrow="t('candidate.companies.eyebrow')"
            :title="t('candidate.companies.title')"
            :description="t('candidate.companies.description')"
            :icon="Building2"
        >
            <template #actions
                ><StatusBadge
                    :label="t('candidate.companies.sortedByActivity')"
                    tone="blue"
            /></template>
        </PageHeader>
        <div class="erin-panel p-4">
            <SearchField
                v-model="search"
                :placeholder="t('candidate.companies.searchPlaceholder')"
            />
        </div>
        <div v-if="companies.length" class="grid gap-4 lg:grid-cols-2">
            <article
                v-for="company in companies.filter(
                    (item) =>
                        !search ||
                        [item.name, item.industry, item.city]
                            .join(' ')
                            .toLowerCase()
                            .includes(search.toLowerCase()),
                )"
                :key="company.id"
                class="erin-panel p-5"
            >
                <div class="flex items-start gap-4">
                    <span
                        class="grid size-14 shrink-0 place-items-center overflow-hidden rounded-2xl bg-gradient-to-br from-blue-100 to-teal-50 text-sm font-extrabold text-[var(--erin-primary)]"
                    >
                        <img
                            v-if="company.logo_url"
                            :src="company.logo_url"
                            :alt="company.name"
                            class="size-full object-cover"
                        />
                        <template v-else>{{
                            company.name.slice(0, 2)
                        }}</template>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-extrabold">{{ company.name }}</h2>
                            <ShieldCheck
                                class="size-4 text-[var(--erin-secondary)]"
                            />
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            {{
                                company.industry ||
                                t('candidate.companies.industryMissing')
                            }}
                        </p>
                        <div
                            class="mt-2 flex flex-wrap gap-3 text-[10px] text-slate-400"
                        >
                            <span class="flex items-center gap-1"
                                ><MapPin class="size-3" />{{
                                    company.city ||
                                    t('candidate.companies.locationOpen')
                                }}<template v-if="company.country_code"
                                    >, {{ company.country_code }}</template
                                ></span
                            >
                            <span
                                v-if="company.employee_count"
                                class="flex items-center gap-1"
                                ><Users class="size-3" />{{
                                    t('candidate.companies.employees', {
                                        count: formatNumber(
                                            company.employee_count,
                                        ),
                                    })
                                }}
                            </span>
                            <span class="flex items-center gap-1 text-teal-600"
                                ><Clock3 class="size-3" />{{
                                    t('candidate.companies.activeAt', {
                                        date: activity(company.last_active_at),
                                    })
                                }}</span
                            >
                        </div>
                    </div>
                </div>
                <p
                    v-if="company.description"
                    class="mt-4 line-clamp-3 text-sm leading-6 text-slate-600"
                >
                    {{ company.description }}
                </p>
                <div class="mt-5 rounded-xl bg-slate-50 p-3 text-center">
                    <p
                        class="text-lg font-extrabold text-[var(--erin-primary)]"
                    >
                        {{ company.relevant_jobs_count ?? 0 }}
                    </p>
                    <p class="text-[9px] text-slate-400">
                        {{ t('candidate.companies.relevantJobs') }}
                    </p>
                </div>
                <div
                    v-if="benefits(company).length"
                    class="mt-4 flex flex-wrap gap-1.5"
                >
                    <span
                        v-for="benefit in benefits(company)"
                        :key="benefit"
                        class="rounded-lg bg-teal-50 px-2.5 py-1 text-[10px] font-bold text-teal-700"
                        >{{ benefitLabel(benefit) }}</span
                    >
                </div>
                <Link
                    :href="show.url(company.id)"
                    class="mt-4 inline-flex h-10 items-center rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-700 hover:border-blue-300 hover:text-blue-700"
                >
                    {{ t('candidate.jobDetail.companyProfile') }}
                </Link>
            </article>
        </div>
        <div
            v-else
            class="erin-panel grid min-h-80 place-items-center p-8 text-center"
        >
            <div>
                <Building2 class="mx-auto size-9 text-slate-300" />
                <h2 class="mt-4 font-bold">
                    {{ t('candidate.companies.emptyTitle') }}
                </h2>
                <p class="mt-2 max-w-md text-sm text-slate-500">
                    {{ t('candidate.companies.emptyDescription') }}
                </p>
            </div>
        </div>
    </div>
</template>
