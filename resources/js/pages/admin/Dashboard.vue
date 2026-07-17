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
import AdminEmptyState from './_components/AdminEmptyState.vue';
import { formatCurrency, formatDate, humanize } from './_shared';

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

function targetLabel(entry: AuditEntry): string {
    if (!entry.auditable_type) {
        return 'System';
    }

    const type =
        entry.auditable_type.split('\\').at(-1) ?? entry.auditable_type;

    return `${humanize(type)}${entry.auditable_id ? ` #${entry.auditable_id}` : ''}`;
}
</script>

<template>
    <Head title="Admin Cockpit" />

    <div class="erin-page">
        <PageHeader
            eyebrow="Platform Operations"
            title="Admin Cockpit"
            description="Live-Kennzahlen aus Nutzern, Firmen, Marketplace und operativen Workflows."
            :icon="ScrollText"
        />

        <section>
            <h2
                class="mb-3 text-xs font-bold tracking-wider text-slate-400 uppercase"
            >
                Plattform
            </h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    label="Nutzer gesamt"
                    :value="metrics.users.total"
                    :hint="`${metrics.users.candidates} Fachkräfte · ${metrics.users.companies} Firmenkonten`"
                    :icon="Users"
                />
                <MetricCard
                    label="Unternehmen"
                    :value="metrics.companies.total"
                    :hint="`${metrics.companies.active} aktiv · ${metrics.companies.blocked} gesperrt`"
                    :icon="Building2"
                    tone="teal"
                />
                <MetricCard
                    label="Veröffentlichte Jobs"
                    :value="metrics.marketplace.published_jobs"
                    :hint="`${metrics.marketplace.applications} Bewerbungen insgesamt`"
                    :icon="BriefcaseBusiness"
                    tone="violet"
                />
                <MetricCard
                    label="Zahlung überfällig"
                    :value="metrics.companies.past_due"
                    hint="betroffene Unternehmen"
                    :icon="CircleDollarSign"
                    tone="orange"
                />
            </div>
        </section>

        <section>
            <h2
                class="mb-3 text-xs font-bold tracking-wider text-slate-400 uppercase"
            >
                Offene Vorgänge
            </h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    label="Dokumentprüfung"
                    :value="metrics.operations.documents_waiting"
                    hint="hochgeladen oder in Prüfung"
                    :icon="FileText"
                    tone="teal"
                />
                <MetricCard
                    label="Aktive Visa-Fälle"
                    :value="metrics.operations.visa_active"
                    hint="aktiv oder blockiert"
                    :icon="FileClock"
                />
                <MetricCard
                    label="Offene Tickets"
                    :value="metrics.operations.tickets_open"
                    hint="noch nicht gelöst"
                    :icon="Tickets"
                    tone="orange"
                />
                <MetricCard
                    label="Referral-Auszahlungen"
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
            title="Letzte Audit-Ereignisse"
            description="Die jüngsten protokollierten Plattformaktionen."
            flush
        >
            <div v-if="recent_audit.length > 0" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left">
                    <thead class="bg-slate-50/80">
                        <tr
                            class="text-[11px] font-bold tracking-wide text-slate-500 uppercase"
                        >
                            <th class="px-5 py-3">Ereignis</th>
                            <th class="px-5 py-3">Akteur</th>
                            <th class="px-5 py-3">Ziel</th>
                            <th class="px-5 py-3 text-right">Zeitpunkt</th>
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
                                    {{ entry.actor?.name ?? 'System' }}
                                </p>
                                <p
                                    v-if="entry.actor"
                                    class="mt-0.5 text-xs text-slate-400"
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
            <AdminEmptyState
                v-else
                title="Noch keine Audit-Ereignisse"
                description="Sobald Plattformaktionen protokolliert werden, erscheinen sie hier."
            />
        </SectionCard>
    </div>
</template>
