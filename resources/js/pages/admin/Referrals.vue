<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    BadgeEuro,
    Check,
    Clock3,
    Search,
    UserRoundPlus,
    WalletCards,
    X,
} from '@lucide/vue';
import { reactive } from 'vue';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import adminReferrals from '@/routes/admin/referrals';
import AdminEmptyState from './_components/AdminEmptyState.vue';
import AdminPagination from './_components/AdminPagination.vue';
import {
    cleanFilters,
    formatCurrency,
    formatDate,
    humanize,
    isPast,
    statusTone,
} from './_shared';
import type { AdminPaginator } from './_shared';

type ReferralRow = {
    id: number;
    status: string;
    clicked_at: string | null;
    registered_at: string | null;
    hired_at: string | null;
    hold_until: string | null;
    approved_at: string | null;
    paid_at: string | null;
    commission_cents: number;
    currency: string;
    metadata: Record<string, unknown> | null;
    referral_code: {
        id: number;
        user_id: number;
        code: string;
        commission_cents: number;
        currency: string;
        user: {
            id: number;
            name: string;
            email: string;
        };
    };
    referred_user: {
        id: number;
        name: string;
        email: string;
    } | null;
    application: {
        id: number;
        job_posting_id: number;
        status: string;
        job_posting: {
            id: number;
            company_id: number;
            title: string;
            company: {
                id: number;
                name: string;
            };
        };
    } | null;
};

type ReferralFilters = {
    search?: string;
    status?: string;
};

const props = defineProps<{
    referrals: AdminPaginator<ReferralRow>;
    filters: ReferralFilters;
    statuses: string[];
    summary: {
        holding_cents: number;
        approved_cents: number;
        paid_cents: number;
    };
}>();

const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
});

const updateForm = useForm({
    status: '',
    reason: '',
});

function applyFilters(): void {
    router.get(adminReferrals.index.url(), cleanFilters(filters), {
        preserveState: true,
        replace: true,
    });
}

function resetFilters(): void {
    router.get(adminReferrals.index.url(), {}, { replace: true });
}

function canApprove(referral: ReferralRow): boolean {
    return (
        ['hired', 'holding'].includes(referral.status) &&
        isPast(referral.hold_until)
    );
}

function updateReferral(
    referral: ReferralRow,
    status: 'approved' | 'paid' | 'rejected',
): void {
    let reason = '';

    if (status === 'rejected') {
        const input = window.prompt(
            'Bitte gib einen Ablehnungsgrund an (mindestens 5 Zeichen):',
        );

        if (input === null || input.trim().length < 5) {
            return;
        }

        reason = input.trim();
    } else if (
        !window.confirm(
            `Referral #${referral.id} wirklich auf „${humanize(status)}“ setzen?`,
        )
    ) {
        return;
    }

    updateForm.status = status;
    updateForm.reason = reason;
    updateForm.patch(adminReferrals.update.url(referral.id), {
        preserveScroll: true,
        onFinish: () => updateForm.reset(),
    });
}
</script>

<template>
    <Head title="Referrals" />

    <div class="erin-page">
        <PageHeader
            eyebrow="Growth Operations"
            title="Referrals"
            :description="`${referrals.total} Empfehlungen mit echter Attribution, Haltefrist und Auszahlungshistorie.`"
            :icon="UserRoundPlus"
        />

        <div class="grid gap-4 sm:grid-cols-3">
            <MetricCard
                label="In Haltefrist"
                :value="formatCurrency(summary.holding_cents)"
                :icon="Clock3"
                tone="orange"
            />
            <MetricCard
                label="Freigegeben"
                :value="formatCurrency(summary.approved_cents)"
                :icon="BadgeEuro"
                tone="teal"
            />
            <MetricCard
                label="Ausgezahlt"
                :value="formatCurrency(summary.paid_cents)"
                :icon="WalletCards"
                tone="violet"
            />
        </div>

        <SectionCard flush>
            <form
                class="grid gap-3 border-b border-slate-100 p-4 lg:grid-cols-[minmax(16rem,1fr)_13rem_auto]"
                @submit.prevent="applyFilters"
            >
                <label class="relative">
                    <span class="sr-only">Referrals suchen</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
                    />
                    <input
                        v-model="filters.search"
                        type="search"
                        placeholder="Code, Empfehlender oder Fachkraft …"
                        class="erin-focus h-11 w-full rounded-xl border border-slate-200 pr-3 pl-10 text-sm"
                    />
                </label>
                <select
                    v-model="filters.status"
                    aria-label="Referral-Status"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                >
                    <option value="">Alle Status</option>
                    <option
                        v-for="status in statuses"
                        :key="status"
                        :value="status"
                    >
                        {{ humanize(status) }}
                    </option>
                </select>
                <div class="flex gap-2">
                    <button
                        type="submit"
                        class="erin-focus h-11 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white"
                    >
                        Filtern
                    </button>
                    <button
                        type="button"
                        aria-label="Filter zurücksetzen"
                        class="erin-focus grid size-11 place-items-center rounded-xl border border-slate-200 text-slate-500"
                        @click="resetFilters"
                    >
                        <X class="size-4" />
                    </button>
                </div>
            </form>

            <div v-if="referrals.data.length > 0" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left">
                    <thead class="bg-slate-50/80">
                        <tr
                            class="text-[11px] font-bold tracking-wide text-slate-500 uppercase"
                        >
                            <th class="px-5 py-3">Empfehlung</th>
                            <th class="px-5 py-3">Vermittlung</th>
                            <th class="px-5 py-3">Provision</th>
                            <th class="px-5 py-3">Zeitachse</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3 text-right">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr
                            v-for="referral in referrals.data"
                            :key="referral.id"
                            class="align-top"
                        >
                            <td class="px-5 py-4">
                                <p
                                    class="font-mono text-xs font-bold text-blue-700"
                                >
                                    {{ referral.referral_code.code }}
                                </p>
                                <p
                                    class="mt-2 text-sm font-semibold text-slate-800"
                                >
                                    {{ referral.referral_code.user.name }}
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ referral.referral_code.user.email }}
                                </p>
                            </td>
                            <td class="px-5 py-4">
                                <p class="text-sm font-semibold text-slate-800">
                                    {{
                                        referral.referred_user?.name ??
                                        'Noch nicht registriert'
                                    }}
                                </p>
                                <p
                                    v-if="referral.referred_user"
                                    class="mt-0.5 text-xs text-slate-500"
                                >
                                    {{ referral.referred_user.email }}
                                </p>
                                <template v-if="referral.application">
                                    <p
                                        class="mt-2 text-xs font-semibold text-slate-700"
                                    >
                                        {{
                                            referral.application.job_posting
                                                .title
                                        }}
                                    </p>
                                    <p class="mt-0.5 text-xs text-slate-400">
                                        {{
                                            referral.application.job_posting
                                                .company.name
                                        }}
                                    </p>
                                </template>
                            </td>
                            <td class="px-5 py-4">
                                <p class="text-sm font-bold text-slate-900">
                                    {{
                                        formatCurrency(
                                            referral.commission_cents,
                                            referral.currency,
                                        )
                                    }}
                                </p>
                                <p class="mt-1 text-xs text-slate-400">
                                    Code-Satz:
                                    {{
                                        formatCurrency(
                                            referral.referral_code
                                                .commission_cents,
                                            referral.referral_code.currency,
                                        )
                                    }}
                                </p>
                            </td>
                            <td
                                class="px-5 py-4 text-xs whitespace-nowrap text-slate-500"
                            >
                                <p>
                                    Klick {{ formatDate(referral.clicked_at) }}
                                </p>
                                <p class="mt-1">
                                    Einstellung
                                    {{ formatDate(referral.hired_at) }}
                                </p>
                                <p class="mt-1">
                                    Haltefrist
                                    {{ formatDate(referral.hold_until) }}
                                </p>
                                <p v-if="referral.paid_at" class="mt-1">
                                    Auszahlung
                                    {{ formatDate(referral.paid_at) }}
                                </p>
                            </td>
                            <td class="px-5 py-4">
                                <StatusBadge
                                    :label="humanize(referral.status)"
                                    :tone="statusTone(referral.status)"
                                />
                                <p
                                    v-if="
                                        updateForm.errors.status ||
                                        updateForm.errors.reason
                                    "
                                    class="mt-2 max-w-48 text-xs text-red-600"
                                >
                                    {{
                                        updateForm.errors.status ??
                                        updateForm.errors.reason
                                    }}
                                </p>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end gap-2">
                                    <button
                                        v-if="canApprove(referral)"
                                        type="button"
                                        :disabled="updateForm.processing"
                                        class="erin-focus inline-flex h-9 items-center gap-1.5 rounded-lg bg-emerald-600 px-3 text-xs font-bold text-white disabled:opacity-50"
                                        @click="
                                            updateReferral(referral, 'approved')
                                        "
                                    >
                                        <Check class="size-3.5" />
                                        Freigeben
                                    </button>
                                    <button
                                        v-if="referral.status === 'approved'"
                                        type="button"
                                        :disabled="updateForm.processing"
                                        class="erin-focus h-9 rounded-lg bg-blue-600 px-3 text-xs font-bold text-white disabled:opacity-50"
                                        @click="
                                            updateReferral(referral, 'paid')
                                        "
                                    >
                                        Als ausgezahlt markieren
                                    </button>
                                    <button
                                        v-if="
                                            !['paid', 'rejected'].includes(
                                                referral.status,
                                            )
                                        "
                                        type="button"
                                        :disabled="updateForm.processing"
                                        class="erin-focus h-9 rounded-lg border border-red-200 px-3 text-xs font-bold text-red-700 disabled:opacity-50"
                                        @click="
                                            updateReferral(referral, 'rejected')
                                        "
                                    >
                                        Ablehnen
                                    </button>
                                    <span
                                        v-if="
                                            referral.status === 'paid' ||
                                            referral.status === 'rejected'
                                        "
                                        class="text-xs text-slate-400"
                                    >
                                        Abgeschlossen
                                    </span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <AdminEmptyState v-else />
            <AdminPagination :paginator="referrals" />
        </SectionCard>
    </div>
</template>
