<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { LifeBuoy, MessageCircleQuestion, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/product/EmptyState.vue';
import FileAttachmentPicker from '@/components/product/FileAttachmentPicker.vue';
import FormField from '@/components/product/FormField.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import SupportConversation from '@/components/product/SupportConversation.vue';
import Textarea from '@/components/product/Textarea.vue';
import type { StatusTone, SupportTicket } from '@/types';

const props = withDefaults(
    defineProps<{
        tickets?: SupportTicket[];
        selected?: number | null;
        ticketing?: {
            provider: string;
            enabled: boolean;
        };
    }>(),
    {
        tickets: () => [],
        selected: null,
        ticketing: () => ({ provider: 'zammad', enabled: false }),
    },
);

const page = usePage();
const { t } = useI18n();
const selectedId = ref<number | null>(
    props.selected ?? props.tickets[0]?.id ?? null,
);
const showCreate = ref(props.tickets.length === 0);
const selectedTicket = computed(
    () =>
        props.tickets.find((ticket) => ticket.id === selectedId.value) ?? null,
);
const userId = computed(() => Number(page.props.auth?.user?.id ?? 0));
const form = useForm({
    subject: '',
    category: '',
    priority: 'normal',
    message: '',
    attachments: [] as File[],
});

watch(
    () => props.selected,
    (selected) => {
        if (selected) {
            selectedId.value = selected;
            showCreate.value = false;
        }
    },
);

const createTicket = () => {
    form.post('/support/tickets', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showCreate.value = false;
        },
    });
};

const toneFor = (status: string): StatusTone =>
    ['resolved', 'closed'].includes(status)
        ? 'green'
        : status === 'waiting_for_customer'
          ? 'orange'
          : 'blue';
</script>

<template>
    <Head :title="t('operations.support.title')" />

    <div class="erin-page">
        <PageHeader
            :eyebrow="t('operations.support.eyebrow')"
            :title="t('operations.support.title')"
            :description="t('operations.support.description')"
            :icon="LifeBuoy"
        >
            <template #actions>
                <button
                    type="button"
                    class="erin-focus inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white hover:bg-blue-700"
                    @click="showCreate = !showCreate"
                >
                    <Plus class="size-4" />
                    {{ t('operations.support.newTicket') }}
                </button>
            </template>
        </PageHeader>

        <SectionCard
            v-if="showCreate"
            :title="t('operations.support.newTicket')"
        >
            <form
                class="grid gap-4 lg:grid-cols-2"
                @submit.prevent="createTicket"
            >
                <FormField
                    id="support-subject"
                    required
                    :label="t('operations.support.subject')"
                    :error="form.errors.subject"
                >
                    <input
                        id="support-subject"
                        v-model="form.subject"
                        required
                        maxlength="180"
                        class="erin-focus h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm"
                        :placeholder="
                            t('operations.support.subjectPlaceholder')
                        "
                    />
                </FormField>
                <FormField
                    id="support-category"
                    :label="t('operations.support.category')"
                    :error="form.errors.category"
                >
                    <input
                        id="support-category"
                        v-model="form.category"
                        maxlength="80"
                        class="erin-focus h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm"
                        :placeholder="
                            t('operations.support.categoryPlaceholder')
                        "
                    />
                </FormField>
                <FormField
                    id="support-priority"
                    :label="t('operations.support.priority')"
                    :error="form.errors.priority"
                >
                    <select
                        id="support-priority"
                        v-model="form.priority"
                        class="erin-focus h-11 w-full rounded-xl border border-slate-200 bg-white px-3.5 text-sm"
                    >
                        <option
                            v-for="priority in [
                                'low',
                                'normal',
                                'high',
                                'urgent',
                            ]"
                            :key="priority"
                            :value="priority"
                        >
                            {{ t(`operations.support.priorities.${priority}`) }}
                        </option>
                    </select>
                </FormField>
                <div class="lg:col-span-2">
                    <FormField
                        id="support-message"
                        required
                        :label="t('operations.support.message')"
                        :error="form.errors.message"
                    >
                        <Textarea
                            id="support-message"
                            v-model="form.message"
                            rows="5"
                            :placeholder="
                                t('operations.support.messagePlaceholder')
                            "
                        />
                    </FormField>
                </div>
                <div class="lg:col-span-2">
                    <FileAttachmentPicker
                        id="support-new-ticket-attachments"
                        v-model="form.attachments"
                        :label="t('operations.support.attachments')"
                        :remove-label="t('operations.support.removeAttachment')"
                        :disabled="form.processing"
                    />
                    <p
                        v-if="form.errors.attachments"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ form.errors.attachments }}
                    </p>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ t('operations.support.attachmentHint') }}
                    </p>
                </div>
                <div class="lg:col-span-2 lg:text-right">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="erin-focus inline-flex h-11 items-center justify-center rounded-xl bg-orange-500 px-5 text-sm font-bold text-white hover:bg-orange-600 disabled:opacity-50"
                    >
                        {{
                            form.processing
                                ? t('operations.support.creating')
                                : t('operations.support.create')
                        }}
                    </button>
                </div>
            </form>
        </SectionCard>

        <div
            v-if="tickets.length"
            class="grid overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm xl:grid-cols-[22rem_minmax(0,1fr)]"
        >
            <aside class="border-b border-slate-200 xl:border-r xl:border-b-0">
                <div class="border-b border-slate-100 px-4 py-3">
                    <h2 class="text-sm font-bold text-slate-950">
                        {{ t('operations.support.ticketList') }}
                    </h2>
                </div>
                <div
                    class="max-h-[44rem] divide-y divide-slate-100 overflow-y-auto"
                >
                    <button
                        v-for="ticket in tickets"
                        :key="ticket.id"
                        type="button"
                        class="w-full p-4 text-left transition"
                        :class="
                            selectedId === ticket.id
                                ? 'bg-blue-50'
                                : 'hover:bg-slate-50'
                        "
                        @click="
                            selectedId = ticket.id;
                            showCreate = false;
                        "
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[11px] font-bold text-slate-400">
                                {{ ticket.number }}
                            </span>
                            <StatusBadge
                                :label="
                                    t(
                                        `operations.support.statuses.${ticket.status}`,
                                    )
                                "
                                :tone="toneFor(ticket.status)"
                                :dot="false"
                            />
                        </div>
                        <p
                            class="mt-2 truncate text-sm font-bold text-slate-900"
                        >
                            {{ ticket.subject }}
                        </p>
                        <p class="mt-1 line-clamp-2 text-xs text-slate-500">
                            {{ ticket.messages.at(-1)?.body }}
                        </p>
                    </button>
                </div>
            </aside>
            <SupportConversation
                v-if="selectedTicket"
                :key="selectedTicket.id"
                :ticket="selectedTicket"
                :reply-url="`/support/tickets/${selectedTicket.id}/reply`"
                :current-user-id="userId"
            />
        </div>

        <EmptyState
            v-else-if="!showCreate"
            panel
            :icon="MessageCircleQuestion"
            :title="t('operations.support.noTickets')"
            :description="t('operations.support.noTicketsDescription')"
        >
            <template #actions>
                <button
                    type="button"
                    class="erin-focus inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white"
                    @click="showCreate = true"
                >
                    <Plus class="size-4" />
                    {{ t('operations.support.newTicket') }}
                </button>
            </template>
        </EmptyState>
    </div>
</template>
