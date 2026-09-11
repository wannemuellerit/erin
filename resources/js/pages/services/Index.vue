<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Handshake, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/product/EmptyState.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';

type Offering = {
    id: number;
    service_type: string;
    country_code: string;
    title: string;
    description?: string;
    partner: string;
    currency_code?: string;
    price_minor?: number;
    version: number;
};
type ServiceCase = {
    id: string;
    service_type: string;
    target_country_code: string;
    status: string;
    public_status: string;
    purpose: string;
    service_details?: Record<string, string | number | null>;
    consent_expires_at?: string;
    withdrawn_at?: string;
    offering?: { title: string };
    organization?: { name: string };
    events: Array<{ id: number; summary: string; created_at: string }>;
    tasks: Array<{ id: number; title: string; due_at?: string | null }>;
};

const props = defineProps<{
    mode: 'candidate' | 'company' | 'partner';
    cases: ServiceCase[];
    offerings: Offering[];
    serviceTypes: string[];
    privacyNotice: string;
}>();
const { t } = useI18n();
const createForm = useForm({
    service_type: props.mode === 'company' ? 'payroll' : '',
    target_country_code: 'DE',
    offering_id: null as number | null,
    candidate_user_id: null as number | null,
    purpose: '',
    service_details: {
        source_language: '',
        target_language: '',
        document_type: '',
        page_count: null as number | null,
        deadline: '',
        offer_minor: null as number | null,
    },
    requested_services: [] as string[],
    shared_data_categories: ['identity', 'contact'] as string[],
    consent: false,
});
const statusForm = useForm({
    status: 'in_progress',
    public_status: 'in_progress',
    summary: '',
});
const availableOfferings = computed(() =>
    props.offerings.filter(
        (offering) =>
            !createForm.service_type ||
            offering.service_type === createForm.service_type,
    ),
);
const createUrl = computed(() =>
    props.mode === 'candidate' ? '/candidate/services' : '/employer/services',
);
const submit = () =>
    createForm.post(createUrl.value, {
        preserveScroll: true,
        onSuccess: () => createForm.reset('purpose', 'offering_id', 'consent'),
    });
const updateStatus = (item: ServiceCase) =>
    statusForm.patch(`/partner/cases/${item.id}`, {
        preserveScroll: true,
        onSuccess: () => statusForm.reset('summary'),
    });
const withdraw = (item: ServiceCase) =>
    router.post(
        `/candidate/services/${item.id}/withdraw`,
        {},
        { preserveScroll: true },
    );
const transfer = (item: ServiceCase) =>
    router.post(
        `/candidate/services/${item.id}/transfer`,
        {},
        { preserveScroll: true },
    );
const label = (value: string) => t(`services.types.${value}`);
</script>

<template>
    <Head :title="t('services.metaTitle')" />
    <div class="erin-page space-y-6">
        <PageHeader
            :eyebrow="t('services.eyebrow')"
            :title="t('services.title')"
            :description="t('services.description')"
            :icon="Handshake"
        />
        <div
            class="flex gap-3 rounded-xl border border-teal-200 bg-teal-50 p-4 text-sm text-teal-900"
        >
            <ShieldCheck class="size-5 shrink-0" />
            <p>{{ privacyNotice }}</p>
        </div>

        <SectionCard
            v-if="mode !== 'partner'"
            :title="t('services.newCase')"
            :description="t('services.newCaseDescription')"
        >
            <form class="grid gap-4 md:grid-cols-2" @submit.prevent="submit">
                <label class="grid gap-1 text-sm"
                    ><span>{{ t('services.service') }}</span
                    ><select
                        v-model="createForm.service_type"
                        :disabled="mode === 'company'"
                        class="erin-input"
                        required
                    >
                        <option value="" disabled>
                            {{ t('services.select') }}
                        </option>
                        <option
                            v-for="type in serviceTypes"
                            :key="type"
                            :value="type"
                        >
                            {{ label(type) }}
                        </option>
                    </select></label
                >
                <label class="grid gap-1 text-sm"
                    ><span>{{ t('services.targetCountry') }}</span
                    ><input
                        v-model="createForm.target_country_code"
                        class="erin-input uppercase"
                        maxlength="2"
                        required
                /></label>
                <label v-if="mode === 'company'" class="grid gap-1 text-sm"
                    ><span>{{ t('services.candidateId') }}</span
                    ><input
                        v-model.number="createForm.candidate_user_id"
                        type="number"
                        min="1"
                        class="erin-input"
                        required
                /></label>
                <label class="grid gap-1 text-sm"
                    ><span>{{ t('services.offering') }}</span
                    ><select
                        v-model="createForm.offering_id"
                        class="erin-input"
                    >
                        <option :value="null">
                            {{ t('services.manualFallback') }}
                        </option>
                        <option
                            v-for="offering in availableOfferings"
                            :key="offering.id"
                            :value="offering.id"
                        >
                            {{
                                t('services.offeringLabel', {
                                    title: offering.title,
                                    partner: offering.partner,
                                    version: offering.version,
                                })
                            }}
                        </option>
                    </select></label
                >
                <label class="grid gap-1 text-sm md:col-span-2"
                    ><span>{{ t('services.purpose') }}</span
                    ><textarea
                        v-model="createForm.purpose"
                        class="erin-input min-h-24"
                        maxlength="300"
                        required
                    />
                </label>
                <fieldset
                    v-if="createForm.service_type === 'translation'"
                    class="grid gap-4 rounded-xl border border-border p-4 md:col-span-2 md:grid-cols-2"
                >
                    <legend class="px-2 font-semibold">
                        {{ t('services.translationDetails') }}
                    </legend>
                    <label class="grid gap-1 text-sm"
                        ><span>{{ t('services.sourceLanguage') }}</span
                        ><input
                            v-model="createForm.service_details.source_language"
                            class="erin-input uppercase"
                            maxlength="2"
                            required
                    /></label>
                    <label class="grid gap-1 text-sm"
                        ><span>{{ t('services.targetLanguage') }}</span
                        ><input
                            v-model="createForm.service_details.target_language"
                            class="erin-input uppercase"
                            maxlength="2"
                            required
                    /></label>
                    <label class="grid gap-1 text-sm"
                        ><span>{{ t('services.documentType') }}</span
                        ><input
                            v-model="createForm.service_details.document_type"
                            class="erin-input"
                            maxlength="80"
                            required
                    /></label>
                    <label class="grid gap-1 text-sm"
                        ><span>{{ t('services.pageCount') }}</span
                        ><input
                            v-model.number="
                                createForm.service_details.page_count
                            "
                            type="number"
                            min="1"
                            max="1000"
                            class="erin-input"
                            required
                    /></label>
                    <label class="grid gap-1 text-sm"
                        ><span>{{ t('services.deadline') }}</span
                        ><input
                            v-model="createForm.service_details.deadline"
                            type="date"
                            class="erin-input"
                            required
                    /></label>
                    <label class="grid gap-1 text-sm"
                        ><span>{{ t('services.optionalOffer') }}</span
                        ><input
                            v-model.number="
                                createForm.service_details.offer_minor
                            "
                            type="number"
                            min="0"
                            class="erin-input"
                    /></label>
                </fieldset>
                <label class="flex items-start gap-2 text-sm md:col-span-2"
                    ><input
                        v-model="createForm.consent"
                        type="checkbox"
                        class="mt-1"
                        required
                    /><span>{{ t('services.consent') }}</span></label
                >
                <div class="md:col-span-2">
                    <button
                        class="erin-button erin-button-primary"
                        :disabled="createForm.processing"
                    >
                        {{ t('services.create') }}
                    </button>
                </div>
            </form>
        </SectionCard>

        <SectionCard
            :title="
                mode === 'partner'
                    ? t('services.assignedCases')
                    : t('services.myCases')
            "
        >
            <EmptyState
                v-if="cases.length === 0"
                :icon="Handshake"
                :title="t('services.empty')"
                :description="t('services.emptyDescription')"
            />
            <div v-else class="space-y-4">
                <article
                    v-for="item in cases"
                    :key="item.id"
                    class="rounded-xl border border-border p-4"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div>
                            <h3 class="font-semibold">
                                {{ label(item.service_type) }} ·
                                {{ item.target_country_code }}
                            </h3>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    item.organization?.name ||
                                    item.offering?.title ||
                                    t('services.manualFallback')
                                }}
                            </p>
                        </div>
                        <StatusBadge :label="item.public_status" tone="teal" />
                    </div>
                    <p class="mt-3 text-sm">{{ item.purpose }}</p>
                    <div
                        v-if="item.tasks.length"
                        class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950"
                    >
                        <p class="font-semibold">
                            {{ t('services.openTasks') }}
                        </p>
                        <ul class="mt-1 list-disc pl-5">
                            <li v-for="task in item.tasks" :key="task.id">
                                {{ task.title }}
                            </li>
                        </ul>
                    </div>
                    <ol class="mt-3 space-y-1 text-sm text-muted-foreground">
                        <li v-for="event in item.events" :key="event.id">
                            {{ event.summary }}
                        </li>
                    </ol>
                    <div
                        v-if="mode === 'candidate' && !item.withdrawn_at"
                        class="mt-4 flex gap-2"
                    >
                        <button
                            class="erin-button erin-button-secondary"
                            @click="transfer(item)"
                        >
                            {{ t('services.transfer') }}</button
                        ><button
                            class="erin-button erin-button-danger"
                            @click="withdraw(item)"
                        >
                            {{ t('services.withdraw') }}</button
                        ><a
                            class="erin-button erin-button-secondary"
                            :href="`/candidate/services/${item.id}/export`"
                            >{{ t('services.export') }}</a
                        >
                    </div>
                    <form
                        v-if="mode === 'partner'"
                        class="mt-4 grid gap-2 md:grid-cols-3"
                        @submit.prevent="updateStatus(item)"
                    >
                        <select v-model="statusForm.status" class="erin-input">
                            <option value="in_progress">
                                {{ t('services.statuses.in_progress') }}
                            </option>
                            <option value="waiting_for_candidate">
                                {{
                                    t('services.statuses.waiting_for_candidate')
                                }}
                            </option>
                            <option value="submitted_to_authority">
                                {{
                                    t(
                                        'services.statuses.submitted_to_authority',
                                    )
                                }}
                            </option>
                            <option value="booked">
                                {{ t('services.statuses.booked') }}
                            </option>
                            <option value="completed">
                                {{ t('services.statuses.completed') }}
                            </option>
                            <option value="rejected">
                                {{ t('services.statuses.rejected') }}
                            </option></select
                        ><input
                            v-model="statusForm.summary"
                            class="erin-input"
                            :placeholder="t('services.statusSummary')"
                            required
                        /><button class="erin-button erin-button-primary">
                            {{ t('services.update') }}
                        </button>
                    </form>
                </article>
            </div>
        </SectionCard>
    </div>
</template>
