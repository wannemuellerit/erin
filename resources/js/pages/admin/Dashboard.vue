<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    BriefcaseBusiness,
    Building2,
    CircleDollarSign,
    FileClock,
    FileText,
    ScrollText,
    Tickets,
    Users,
} from '@lucide/vue';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import EmptyState from '@/components/product/EmptyState.vue';
import { useAdminI18n } from './_i18n';

type AuditEntry = {
    id: number;
    event: string;
    auditable_type: string | null;
    auditable_id: number | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
    actor: {
        id: number;
        name: string;
        email: string;
    } | null;
};

defineProps<{
    metrics: {
        users: {
            total: number;
            candidates: number;
            companies: number;
            platform_staff: number;
            blocked: number;
        };
        companies: {
            total: number;
            active: number;
            blocked: number;
            past_due: number;
        };
        marketplace: {
            published_jobs: number;
            applications: number;
        };
        operations: {
            documents_waiting: number;
            visa_active: number;
            tickets_open: number;
            referrals_payable: number;
            referrals_payable_cents: number;
        };
    };
    recent_audit: AuditEntry[];
}>();

const { t, formatCurrency, formatDate, humanize } = useAdminI18n();

function targetLabel(entry: AuditEntry): string {
    if (!entry.auditable_type) {
        return t('common.system');
    }

    const type =
        entry.auditable_type.split('\\').at(-1) ?? entry.auditable_type;

    return `${humanize(type)}${entry.auditable_id ? ` #${entry.auditable_id}` : ''}`;
}
</script>

<template>
    <Head :title="t('dashboard.metaTitle')" />

    <div class="erin-page">
        <PageHeader
            :eyebrow="t('dashboard.eyebrow')"
            :title="t('dashboard.title')"
            :description="t('dashboard.description')"
            :icon="ScrollText"
        />

        <section>
            <h2
                class="mb-3 text-xs font-bold tracking-wider text-slate-600 uppercase"
            >
                {{ t('dashboard.platform') }}
            </h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    :label="t('dashboard.metrics.users')"
                    :value="metrics.users.total"
                    :hint="
                        t('dashboard.metrics.usersHint', {
                            candidates: metrics.users.candidates,
                            companies: metrics.users.companies,
                        })
                    "
                    :icon="Users"
                />
                <MetricCard
                    :label="t('dashboard.metrics.companies')"
                    :value="metrics.companies.total"
                    :hint="
                        t('dashboard.metrics.companiesHint', {
                            active: metrics.companies.active,
                            blocked: metrics.companies.blocked,
                        })
                    "
                    :icon="Building2"
                    tone="teal"
                />
                <MetricCard
                    :label="t('dashboard.metrics.publishedJobs')"
                    :value="metrics.marketplace.published_jobs"
                    :hint="
                        t('dashboard.metrics.applicationsHint', {
                            count: metrics.marketplace.applications,
                        })
                    "
                    :icon="BriefcaseBusiness"
                    tone="violet"
                />
                <MetricCard
                    :label="t('dashboard.metrics.pastDue')"
                    :value="metrics.companies.past_due"
                    :hint="t('dashboard.metrics.pastDueHint')"
                    :icon="CircleDollarSign"
                    tone="orange"
                />
            </div>
        </section>

        <section>
            <h2
                class="mb-3 text-xs font-bold tracking-wider text-slate-600 uppercase"
            >
                {{ t('dashboard.openProcesses') }}
            </h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    :label="t('dashboard.metrics.documentReview')"
                    :value="metrics.operations.documents_waiting"
                    :hint="t('dashboard.metrics.documentReviewHint')"
                    :icon="FileText"
                    tone="teal"
                />
                <MetricCard
                    :label="t('dashboard.metrics.visaCases')"
                    :value="metrics.operations.visa_active"
                    :hint="t('dashboard.metrics.visaCasesHint')"
                    :icon="FileClock"
                />
                <MetricCard
                    :label="t('dashboard.metrics.openTickets')"
                    :value="metrics.operations.tickets_open"
                    :hint="t('dashboard.metrics.openTicketsHint')"
                    :icon="Tickets"
                    tone="orange"
                />
                <MetricCard
                    :label="t('dashboard.metrics.referralPayouts')"
                    :value="metrics.operations.referrals_payable"
                    :hint="
                        formatCurrency(
                            metrics.operations.referrals_payable_cents,
                        )
                    "
                    :icon="CircleDollarSign"
                    tone="violet"
                />
            </div>
        </section>

        <SectionCard
            :title="t('dashboard.auditTitle')"
            :description="t('dashboard.auditDescription')"
            flush
        >
            <div v-if="recent_audit.length > 0" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left">
                    <thead class="bg-slate-50/80">
                        <tr
                            class="text-[11px] font-bold tracking-wide text-slate-500 uppercase"
                        >
                            <th class="px-5 py-3">
                                {{ t('dashboard.columns.event') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('dashboard.columns.actor') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('dashboard.columns.target') }}
                            </th>
                            <th class="px-5 py-3 text-right">
                                {{ t('dashboard.columns.time') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr
                            v-for="entry in recent_audit"
                            :key="entry.id"
                            class="text-sm text-slate-600"
                        >
                            <td class="px-5 py-4">
                                <p
                                    class="font-mono text-xs font-semibold text-slate-800"
                                >
                                    {{ entry.event }}
                                </p>
                            </td>
                            <td class="px-5 py-4">
                                <p class="font-semibold text-slate-800">
                                    {{
                                        entry.actor?.name ?? t('common.system')
                                    }}
                                </p>
                                <p
                                    v-if="entry.actor"
                                    class="mt-0.5 text-xs text-slate-600"
                                >
                                    {{ entry.actor.email }}
                                </p>
                            </td>
                            <td class="px-5 py-4 text-xs">
                                {{ targetLabel(entry) }}
                            </td>
                            <td
                                class="px-5 py-4 text-right text-xs whitespace-nowrap"
                            >
                                {{ formatDate(entry.created_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState
                v-else
                :title="t('dashboard.emptyTitle')"
                :description="t('dashboard.emptyDescription')"
            />
        </SectionCard>
    </div>
</template>
