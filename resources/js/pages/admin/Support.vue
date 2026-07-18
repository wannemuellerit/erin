<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Eye, MessageSquareReply, ShieldAlert, Tickets, X } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import EmptyState from '@/components/product/EmptyState.vue';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SearchField from '@/components/product/SearchField.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import SupportConversation from '@/components/product/SupportConversation.vue';
import Textarea from '@/components/product/Textarea.vue';
import adminSupport from '@/routes/admin/support';
import type { SupportTicketMessage } from '@/types';
import AdminPagination from './_components/AdminPagination.vue';
import { useAdminI18n } from './_i18n';
import { cleanFilters, statusTone } from './_shared';
import type { AdminPaginator } from './_shared';

type SupportTicketRow = {
    id: number;
    requester_id: number;
    company_id: number | null;
    assigned_to: number | null;
    number: string;
    subject: string;
    category: string;
    priority: string;
    status: string;
    last_reply_at: string | null;
    resolved_at: string | null;
    created_at: string;
    requester: {
        id: number;
        name: string;
        email: string;
        role: string;
        status: string;
    };
    company: {
        id: number;
        name: string;
        slug: string;
    } | null;
    assignee: {
        id: number;
        name: string;
        email: string;
    } | null;
    messages_count: number;
    external_id: string | null;
    messages: SupportTicketMessage[];
};

type StaffMember = {
    id: number;
    name: string;
    email: string;
    role: string;
};

type SupportFilters = {
    search?: string;
    status?: string;
    priority?: string;
    assigned_to?: number | string;
};

const props = defineProps<{
    tickets: AdminPaginator<SupportTicketRow>;
    filters: SupportFilters;
    statuses: string[];
    staff: StaffMember[];
    moderation: {
        open_cases: number;
        pending_feedback: number;
    };
    attachmentLimits: {
        maxFiles: number;
        maxFileMegabytes: number;
        maxTotalMegabytes: number;
    };
}>();
const page = usePage();
const currentUserId = computed(() => Number(page.props.auth?.user?.id ?? 0));

const priorities = ['low', 'normal', 'high', 'urgent'];

const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    priority: props.filters.priority ?? '',
    assigned_to: props.filters.assigned_to?.toString() ?? '',
});

const selectedId = ref<number | null>(props.tickets.data[0]?.id ?? null);
const selectedTicket = computed(
    () =>
        props.tickets.data.find((ticket) => ticket.id === selectedId.value) ??
        props.tickets.data[0] ??
        null,
);

const ticketForm = useForm({
    status: '',
    priority: '',
    assigned_to: '',
});

const impersonationForm = useForm({
    reason: '',
});

const { t, formatDate, humanize } = useAdminI18n();

watch(
    () => props.tickets.data,
    (tickets) => {
        if (!tickets.some((ticket) => ticket.id === selectedId.value)) {
            selectedId.value = tickets[0]?.id ?? null;
        }
    },
);

watch(
    selectedTicket,
    (ticket) => {
        ticketForm.clearErrors();
        impersonationForm.clearErrors();

        if (!ticket) {
            ticketForm.reset();

            return;
        }

        ticketForm.status = ticket.status;
        ticketForm.priority = ticket.priority;
        ticketForm.assigned_to = ticket.assigned_to?.toString() ?? '';
        impersonationForm.reset();
    },
    { immediate: true },
);

function applyFilters(): void {
    router.get(adminSupport.index.url(), cleanFilters(filters), {
        preserveState: true,
        replace: true,
    });
}

function resetFilters(): void {
    router.get(adminSupport.index.url(), {}, { replace: true });
}

function updateTicket(): void {
    if (!selectedTicket.value) {
        return;
    }

    ticketForm.patch(adminSupport.update.url(selectedTicket.value.id), {
        preserveScroll: true,
    });
}

function startImpersonation(): void {
    if (!selectedTicket.value) {
        return;
    }

    impersonationForm.post(
        adminSupport.impersonation.start.url(selectedTicket.value.requester.id),
    );
}
</script>

<template>
    <Head :title="t('support.metaTitle')" />

    <div class="erin-page">
        <PageHeader
            :eyebrow="t('support.eyebrow')"
            :title="t('support.title')"
            :description="t('support.description', { count: tickets.total })"
            :icon="Tickets"
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <MetricCard
                :label="t('support.metrics.moderation')"
                :value="moderation.open_cases"
                :hint="t('support.metrics.moderationHint')"
                :icon="ShieldAlert"
                tone="orange"
            />
            <MetricCard
                :label="t('support.metrics.feedback')"
                :value="moderation.pending_feedback"
                :hint="t('support.metrics.feedbackHint')"
                :icon="MessageSquareReply"
                tone="violet"
            />
        </div>

        <SectionCard flush>
            <form
                class="grid gap-3 border-b border-slate-100 p-4 lg:grid-cols-[minmax(16rem,1fr)_12rem_11rem_14rem_auto]"
                @submit.prevent="applyFilters"
            >
                <SearchField
                    v-model="filters.search"
                    :label="t('support.searchLabel')"
                    :placeholder="t('support.searchPlaceholder')"
                />
                <select
                    v-model="filters.status"
                    :aria-label="t('support.ticketStatus')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                >
                    <option value="">{{ t('common.allStatuses') }}</option>
                    <option
                        v-for="status in statuses"
                        :key="status"
                        :value="status"
                    >
                        {{ humanize(status) }}
                    </option>
                </select>
                <select
                    v-model="filters.priority"
                    :aria-label="t('support.priority')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                >
                    <option value="">{{ t('support.allPriorities') }}</option>
                    <option
                        v-for="priority in priorities"
                        :key="priority"
                        :value="priority"
                    >
                        {{ humanize(priority) }}
                    </option>
                </select>
                <select
                    v-model="filters.assigned_to"
                    :aria-label="t('support.assignee')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                >
                    <option value="">{{ t('support.allAssignees') }}</option>
                    <option
                        v-for="member in staff"
                        :key="member.id"
                        :value="member.id"
                    >
                        {{ member.name }}
                    </option>
                </select>
                <div class="flex gap-2">
                    <button
                        type="submit"
                        class="erin-focus h-11 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white"
                    >
                        {{ t('common.filter') }}
                    </button>
                    <button
                        type="button"
                        :aria-label="t('common.resetFilters')"
                        class="erin-focus grid size-11 place-items-center rounded-xl border border-slate-200 text-slate-500"
                        @click="resetFilters"
                    >
                        <X class="size-4" />
                    </button>
                </div>
            </form>

            <div
                v-if="tickets.data.length > 0"
                class="grid min-h-[38rem] xl:grid-cols-[22rem_minmax(0,1fr)]"
            >
                <aside
                    class="border-b border-slate-200 xl:border-r xl:border-b-0"
                >
                    <div class="divide-y divide-slate-100">
                        <button
                            v-for="ticket in tickets.data"
                            :key="ticket.id"
                            type="button"
                            class="w-full p-4 text-left transition"
                            :class="
                                selectedTicket?.id === ticket.id
                                    ? 'bg-blue-50'
                                    : 'hover:bg-slate-50'
                            "
                            @click="selectedId = ticket.id"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <span
                                    class="text-[11px] font-bold text-slate-600"
                                >
                                    {{ ticket.number }}
                                </span>
                                <StatusBadge
                                    :label="humanize(ticket.priority)"
                                    :tone="
                                        ticket.priority === 'urgent'
                                            ? 'red'
                                            : ticket.priority === 'high'
                                              ? 'orange'
                                              : 'slate'
                                    "
                                />
                            </div>
                            <p
                                class="mt-2 text-sm leading-5 font-bold text-slate-800"
                            >
                                {{ ticket.subject }}
                            </p>
                            <p class="mt-2 truncate text-xs text-slate-600">
                                {{ ticket.requester.name }}
                            </p>
                            <div
                                class="mt-2 flex items-center justify-between text-[10px]"
                            >
                                <StatusBadge
                                    :label="humanize(ticket.status)"
                                    :tone="statusTone(ticket.status)"
                                />
                                <span class="text-slate-600">
                                    {{
                                        t(
                                            'support.messageCount',
                                            ticket.messages_count,
                                        )
                                    }}
                                </span>
                            </div>
                        </button>
                    </div>
                </aside>

                <main v-if="selectedTicket" class="p-5 sm:p-6">
                    <div
                        class="flex flex-col gap-4 lg:flex-row lg:justify-between"
                    >
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs font-bold text-slate-600">
                                    {{ selectedTicket.number }}
                                </span>
                                <StatusBadge
                                    :label="humanize(selectedTicket.status)"
                                    :tone="statusTone(selectedTicket.status)"
                                />
                            </div>
                            <h2 class="mt-2 text-xl font-bold text-slate-950">
                                {{ selectedTicket.subject }}
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ humanize(selectedTicket.category) }} ·
                                {{
                                    t('support.createdAt', {
                                        date: formatDate(
                                            selectedTicket.created_at,
                                        ),
                                    })
                                }}
                            </p>
                        </div>
                        <div class="text-xs text-slate-500">
                            <p>
                                {{
                                    t('support.lastReplyAt', {
                                        date: formatDate(
                                            selectedTicket.last_reply_at,
                                        ),
                                    })
                                }}
                            </p>
                            <p class="mt-1">
                                {{
                                    t('support.resolvedAt', {
                                        date: formatDate(
                                            selectedTicket.resolved_at,
                                        ),
                                    })
                                }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="mt-6 grid gap-6 2xl:grid-cols-[minmax(0,1fr)_22rem]"
                    >
                        <div class="space-y-6">
                            <div
                                class="overflow-hidden rounded-2xl border border-slate-200"
                            >
                                <SupportConversation
                                    :key="selectedTicket.id"
                                    :ticket="selectedTicket"
                                    :reply-url="
                                        adminSupport.reply.url(
                                            selectedTicket.id,
                                        )
                                    "
                                    :current-user-id="currentUserId"
                                    :attachment-limits="attachmentLimits"
                                    allow-internal
                                    message-field="body"
                                />
                            </div>
                            <section class="rounded-2xl bg-slate-50 p-5">
                                <h3 class="text-sm font-bold text-slate-900">
                                    {{ t('support.overview') }}
                                </h3>
                                <p
                                    class="mt-2 text-sm leading-6 text-slate-600"
                                >
                                    {{
                                        t(
                                            'support.messagePayloadHint',
                                            selectedTicket.messages_count,
                                        )
                                    }}
                                </p>
                                <dl
                                    class="mt-4 grid gap-3 text-xs sm:grid-cols-2"
                                >
                                    <div>
                                        <dt class="text-slate-600">
                                            {{ t('support.requester') }}
                                        </dt>
                                        <dd
                                            class="mt-1 font-semibold text-slate-800"
                                        >
                                            {{ selectedTicket.requester.name }}
                                        </dd>
                                        <dd class="text-slate-500">
                                            {{ selectedTicket.requester.email }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-slate-600">
                                            {{ t('support.company') }}
                                        </dt>
                                        <dd
                                            class="mt-1 font-semibold text-slate-800"
                                        >
                                            {{
                                                selectedTicket.company?.name ??
                                                t('support.noCompany')
                                            }}
                                        </dd>
                                    </div>
                                </dl>
                            </section>
                        </div>

                        <aside class="space-y-5">
                            <form
                                class="rounded-2xl border border-slate-200 p-5"
                                @submit.prevent="updateTicket"
                            >
                                <h3 class="text-sm font-bold text-slate-900">
                                    {{ t('support.editingTitle') }}
                                </h3>
                                <label class="mt-4 block">
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                        >{{ t('common.status') }}</span
                                    >
                                    <select
                                        v-model="ticketForm.status"
                                        class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                    >
                                        <option
                                            v-for="status in statuses"
                                            :key="status"
                                            :value="status"
                                        >
                                            {{ humanize(status) }}
                                        </option>
                                    </select>
                                </label>
                                <label class="mt-3 block">
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        {{ t('support.priority') }}
                                    </span>
                                    <select
                                        v-model="ticketForm.priority"
                                        class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                    >
                                        <option
                                            v-for="priority in priorities"
                                            :key="priority"
                                            :value="priority"
                                        >
                                            {{ humanize(priority) }}
                                        </option>
                                    </select>
                                </label>
                                <label class="mt-3 block">
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        {{ t('support.assignedTo') }}
                                    </span>
                                    <select
                                        v-model="ticketForm.assigned_to"
                                        class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                    >
                                        <option value="">
                                            {{ t('common.notAssigned') }}
                                        </option>
                                        <option
                                            v-for="member in staff"
                                            :key="member.id"
                                            :value="member.id"
                                        >
                                            {{ member.name }}
                                        </option>
                                    </select>
                                </label>
                                <p
                                    v-if="
                                        ticketForm.errors.status ||
                                        ticketForm.errors.priority ||
                                        ticketForm.errors.assigned_to
                                    "
                                    class="mt-2 text-xs text-red-600"
                                >
                                    {{
                                        ticketForm.errors.status ??
                                        ticketForm.errors.priority ??
                                        ticketForm.errors.assigned_to
                                    }}
                                </p>
                                <button
                                    type="submit"
                                    :disabled="ticketForm.processing"
                                    class="erin-focus mt-4 h-10 w-full rounded-xl bg-slate-900 text-xs font-bold text-white disabled:opacity-50"
                                >
                                    {{ t('support.updateTicket') }}
                                </button>
                            </form>

                            <form
                                class="rounded-2xl border border-amber-200 bg-amber-50 p-5"
                                @submit.prevent="startImpersonation"
                            >
                                <div
                                    class="flex items-center gap-2 text-amber-800"
                                >
                                    <Eye class="size-4" />
                                    <h3 class="text-sm font-bold">
                                        {{ t('support.readOnlyViewTitle') }}
                                    </h3>
                                </div>
                                <p
                                    class="mt-2 text-xs leading-5 text-amber-700"
                                >
                                    {{ t('support.supportViewDescription') }}
                                </p>
                                <Textarea
                                    v-model="impersonationForm.reason"
                                    rows="4"
                                    :placeholder="
                                        t('support.reasonPlaceholder')
                                    "
                                    class="mt-3 border-amber-300 text-xs"
                                />
                                <p
                                    v-if="impersonationForm.errors.reason"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ impersonationForm.errors.reason }}
                                </p>
                                <button
                                    type="submit"
                                    :disabled="impersonationForm.processing"
                                    class="erin-focus mt-3 h-10 w-full rounded-xl bg-amber-800 text-xs font-bold text-white hover:bg-amber-900 disabled:opacity-50"
                                >
                                    {{ t('support.openView') }}
                                </button>
                            </form>
                        </aside>
                    </div>
                </main>
            </div>
            <EmptyState
                v-else
                :title="t('support.emptyTitle')"
                :description="t('support.emptyDescription')"
            />
            <AdminPagination :paginator="tickets" />
        </SectionCard>
    </div>
</template>
