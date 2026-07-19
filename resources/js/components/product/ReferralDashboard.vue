<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    Euro,
    Gift,
    Mail,
    MousePointerClick,
    Send,
    Share2,
    UserCheck,
    Users,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/product/EmptyState.vue';
import FormField from '@/components/product/FormField.vue';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import Textarea from '@/components/product/Textarea.vue';
import { useFormatters } from '@/composables/useFormatters';
import { useStatusLabels } from '@/composables/useStatusLabels';
import de from '@/i18n/messages/product-components-de';
import en from '@/i18n/messages/product-components-en';
import { create, email as sendReferralEmail } from '@/routes/referrals';
import type { Referral, ReferralDashboardProps, StatusTone } from '@/types';

const props = withDefaults(defineProps<ReferralDashboardProps>(), {
    perspective: 'candidate',
    code: null,
    metrics: () => ({
        clicks: 0,
        registrations: 0,
        applications: 0,
        placements: 0,
        approved_cents: 0,
        paid_cents: 0,
    }),
    referrals: () => [],
});

const { t } = useI18n({
    useScope: 'local',
    messages: { de, en },
});
const { formatCurrency, formatDate: formatLocalizedDate } = useFormatters();

const copied = ref(false);
const showEmailForm = ref(false);
const emailForm = useForm({
    email: '',
    message: '',
});

const money = (amount: number, currency = 'EUR') =>
    formatCurrency(amount / 100, currency);
const formatDate = (value: string) =>
    formatLocalizedDate(value, { dateStyle: 'medium' });
const { statusLabel: translatedStatusLabel } = useStatusLabels();
const statusLabel = (status: string) =>
    translatedStatusLabel('referral', status);
const statusTone = (status: string): StatusTone => {
    if (status === 'paid' || status === 'approved') {
        return 'green';
    }

    if (status === 'rejected') {
        return 'red';
    }

    if (status === 'holding' || status === 'hired') {
        return 'orange';
    }

    if (status === 'applied') {
        return 'violet';
    }

    return 'blue';
};
const referralDate = (referral: Referral) =>
    referral.registered_at ??
    referral.clicked_at ??
    referral.hired_at ??
    referral.approved_at ??
    referral.paid_at;
const createLink = () => {
    router.post(create.url(), {}, { preserveScroll: true });
};
const copyLink = async () => {
    if (!props.code?.url) {
        return;
    }

    await navigator.clipboard.writeText(props.code.url);
    copied.value = true;
    window.setTimeout(() => {
        copied.value = false;
    }, 2000);
};
const shareLink = async () => {
    if (!props.code?.url) {
        return;
    }

    if (navigator.share) {
        await navigator.share({
            title: 'Erin',
            text: t('referralDashboard.shareText'),
            url: props.code.url,
        });

        return;
    }

    await copyLink();
};
const sendEmail = () => {
    emailForm.post(sendReferralEmail.url(), {
        preserveScroll: true,
        onSuccess: () => {
            emailForm.reset();
            showEmailForm.value = false;
        },
    });
};
</script>

<template>
    <div class="erin-page">
        <PageHeader
            :eyebrow="t('referralDashboard.eyebrow')"
            :title="t('referralDashboard.title')"
            :description="t('referralDashboard.description')"
            :icon="Gift"
        />

        <section
            class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 to-blue-800 p-6 text-white shadow-xl shadow-blue-900/15 sm:p-8"
        >
            <div class="erin-grid absolute inset-0 opacity-15" />
            <div
                class="relative grid items-center gap-8 lg:grid-cols-[1fr_auto]"
            >
                <div>
                    <p
                        class="text-xs font-bold tracking-wider text-teal-300 uppercase"
                    >
                        {{ t('referralDashboard.personalLink') }}
                    </p>
                    <h2 class="mt-2 text-2xl font-extrabold">
                        {{ t('referralDashboard.heroTitle') }}
                    </h2>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-6 text-blue-100/80"
                    >
                        {{ t('referralDashboard.heroDescription') }}
                    </p>
                    <div v-if="code" class="mt-5 max-w-xl">
                        <div class="flex rounded-xl bg-white p-1.5">
                            <input
                                readonly
                                :value="code.url"
                                class="min-w-0 flex-1 bg-transparent px-3 text-xs font-medium text-slate-600 outline-none"
                            />
                            <button
                                type="button"
                                class="inline-flex h-9 items-center gap-2 rounded-lg bg-[var(--erin-primary)] px-3 text-xs font-bold text-white"
                                @click="copyLink"
                            >
                                <Check v-if="copied" class="size-3.5" />
                                <Copy v-else class="size-3.5" />
                                {{
                                    t(
                                        copied
                                            ? 'referralDashboard.copied'
                                            : 'referralDashboard.copy',
                                    )
                                }}
                            </button>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <button
                                type="button"
                                class="inline-flex h-9 items-center gap-2 rounded-lg bg-white/10 px-3 text-xs font-bold ring-1 ring-white/10"
                                @click="showEmailForm = !showEmailForm"
                            >
                                <Mail class="size-3.5" />
                                {{ t('referralDashboard.email') }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex h-9 items-center gap-2 rounded-lg bg-white/10 px-3 text-xs font-bold ring-1 ring-white/10"
                                @click="shareLink"
                            >
                                <Share2 class="size-3.5" />
                                {{ t('referralDashboard.share') }}
                            </button>
                        </div>
                    </div>
                    <button
                        v-else
                        type="button"
                        class="mt-5 h-10 rounded-xl bg-white px-4 text-xs font-bold text-[var(--erin-primary)]"
                        @click="createLink"
                    >
                        {{ t('referralDashboard.createLink') }}
                    </button>
                </div>
                <div
                    class="hidden size-40 place-items-center rounded-full bg-white/10 ring-1 ring-white/15 lg:grid"
                >
                    <div
                        class="grid size-28 place-items-center rounded-full bg-teal-400/20"
                    >
                        <Gift class="size-12 text-teal-300" />
                    </div>
                </div>
            </div>
        </section>

        <form
            v-if="showEmailForm && code"
            class="erin-panel grid gap-4 p-5 sm:grid-cols-2"
            @submit.prevent="sendEmail"
        >
            <FormField
                id="referral-email"
                :label="t('referralDashboard.recipientEmail')"
                :error="emailForm.errors.email"
                required
            >
                <input
                    id="referral-email"
                    v-model="emailForm.email"
                    required
                    type="email"
                    class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                />
            </FormField>
            <FormField
                id="referral-message"
                class="sm:row-span-2"
                :label="t('referralDashboard.personalMessage')"
            >
                <Textarea
                    id="referral-message"
                    v-model="emailForm.message"
                    rows="4"
                />
            </FormField>
            <button
                type="submit"
                :disabled="emailForm.processing"
                class="h-10 rounded-xl bg-[var(--erin-primary)] text-xs font-bold text-white disabled:opacity-50"
            >
                {{ t('referralDashboard.sendRecommendation') }}
            </button>
        </form>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <MetricCard
                :label="t('referralDashboard.metrics.clicks')"
                :value="metrics.clicks"
                :icon="MousePointerClick"
            />
            <MetricCard
                :label="t('referralDashboard.metrics.registrations')"
                :value="metrics.registrations"
                :icon="Users"
                tone="teal"
            />
            <MetricCard
                :label="t('referralDashboard.metrics.applications')"
                :value="metrics.applications"
                :icon="Send"
                tone="violet"
            />
            <MetricCard
                :label="t('referralDashboard.metrics.placements')"
                :value="metrics.placements"
                :icon="UserCheck"
                tone="orange"
            />
            <MetricCard
                :label="t('referralDashboard.metrics.approvedCommission')"
                :value="money(metrics.approved_cents)"
                :hint="
                    t('referralDashboard.metrics.paidHint', {
                        amount: money(metrics.paid_cents),
                    })
                "
                :icon="Euro"
                tone="teal"
            />
        </section>

        <SectionCard
            :title="t('referralDashboard.listTitle')"
            :description="t('referralDashboard.listDescription')"
        >
            <div v-if="referrals.length" class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead>
                        <tr
                            class="border-b border-slate-200 text-left text-[10px] font-bold tracking-wider text-slate-400 uppercase"
                        >
                            <th class="pb-3">
                                {{ t('referralDashboard.columns.reference') }}
                            </th>
                            <th class="pb-3">
                                {{ t('referralDashboard.columns.captured') }}
                            </th>
                            <th class="pb-3">
                                {{ t('referralDashboard.columns.status') }}
                            </th>
                            <th class="pb-3">
                                {{ t('referralDashboard.columns.holdPeriod') }}
                            </th>
                            <th class="pb-3 text-right">
                                {{ t('referralDashboard.columns.commission') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="referral in referrals"
                            :key="referral.id"
                            class="border-b border-slate-100 last:border-0"
                        >
                            <td class="py-4 font-bold text-slate-800">
                                #REF-{{ referral.id }}
                            </td>
                            <td class="py-4 text-slate-500">
                                {{
                                    referralDate(referral)
                                        ? formatDate(
                                              referralDate(referral) as string,
                                          )
                                        : '—'
                                }}
                            </td>
                            <td class="py-4">
                                <StatusBadge
                                    :label="statusLabel(referral.status)"
                                    :tone="statusTone(referral.status)"
                                />
                            </td>
                            <td class="py-4 text-slate-500">
                                {{
                                    referral.hold_until
                                        ? formatDate(referral.hold_until)
                                        : '—'
                                }}
                            </td>
                            <td
                                class="py-4 text-right font-bold text-slate-800"
                            >
                                {{
                                    referral.commission_cents
                                        ? money(
                                              referral.commission_cents,
                                              referral.currency ?? 'EUR',
                                          )
                                        : '—'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState
                v-else
                compact
                :icon="Gift"
                :title="t('referralDashboard.emptyTitle')"
                :description="t('referralDashboard.emptyDescription')"
            />
        </SectionCard>
    </div>
</template>
