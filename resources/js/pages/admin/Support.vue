<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Bot,
    Eye,
    MessageSquareReply,
    ShieldAlert,
    Tickets,
    X,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import AdminPagination from './_components/AdminPagination.vue';
import { useAdminI18n } from './_i18n';
import { cleanFilters, statusTone } from './_shared';
import type { AdminPaginator } from './_shared';
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

type ModerationFeedbackRow = {
    id: number;
    sentiment: string;
    reason_code: string;
    comment: string | null;
    created_at: string;
    author: { id: number; name: string; email: string };
    subject_user: { id: number; name: string; email: string } | null;
    subject_company: { id: number; name: string } | null;
    application: {
        id: number;
        job_posting: { id: number; title: string };
    } | null;
};

type ModerationCaseRow = {
    id: number;
    reason: string;
    severity: string;
    priority: string;
    status: string;
    created_at: string;
    subject_user: { id: number; name: string; email: string } | null;
    subject_company: { id: number; name: string } | null;
    assignee: { id: number; name: string; email: string } | null;
};

type ChatbotGovernance = {
    metrics: {
        sessions: number;
        handoff_rate: number;
        helpful_rate: number;
        average_latency_ms: number;
        escalations: number;
        expired_sources: number;
    };
    articles: Array<{
        id: number;
        stable_key: string;
        version: number;
        locale: string;
        title: string;
        source_url: string | null;
        status: string;
        target_roles: string[] | null;
        published_at: string | null;
        expires_at: string | null;
    }>;
    prompts: Array<{
        id: number;
        version: number;
        model: string | null;
        active: boolean;
        allowed_tools: string[] | null;
        created_at: string;
    }>;
};

const props = defineProps<{
    tickets: AdminPaginator<SupportTicketRow>;
    filters: SupportFilters;
    statuses: string[];
    staff: StaffMember[];
    moderation: {
        open_cases: number;
        pending_feedback: number;
        cases: ModerationCaseRow[];
        feedback: ModerationFeedbackRow[];
    };
    attachmentLimits: {
        maxFiles: number;
        maxFileMegabytes: number;
        maxTotalMegabytes: number;
    };
    chatbotGovernance: ChatbotGovernance;
}>();
const page = usePage();
const currentUserId = computed(() => Number(page.props.auth?.user?.id ?? 0));
const isSuperAdmin = computed(
    () => page.props.auth?.user?.role === 'super_admin',
);
const feedbackReasons = reactive<Record<number, string>>({});
const caseResolutions = reactive<Record<number, string>>({});
const casePriorities = reactive<Record<number, string>>({});

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

const knowledgeForm = useForm({
    stable_key: '',
    locale: 'de',
    title: '',
    body: '',
    source_url: '',
    target_roles: ['candidate', 'company'] as string[],
    expires_at: '',
});
const promptForm = useForm({
    instructions: '',
    allowed_tools: ['knowledge_search', 'support_handoff'] as string[],
    safety_rules: [
        'source_only',
        'no_state_mutations',
        'prompt_injection_guard',
        'pii_redaction',
    ] as string[],
    model: '',
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

function reviewFeedback(
    feedback: ModerationFeedbackRow,
    decision: 'approved' | 'rejected',
): void {
    router.patch(
        `/admin/moderation/feedback/${feedback.id}`,
        {
            decision,
            reason: feedbackReasons[feedback.id] ?? '',
        },
        { preserveScroll: true },
    );
}

function updateModerationCase(
    moderationCase: ModerationCaseRow,
    action: 'assign' | 'escalate' | 'resolve' | 'dismiss' | 'block',
): void {
    router.patch(
        `/admin/moderation/cases/${moderationCase.id}`,
        {
            action,
            assigned_to: action === 'assign' ? currentUserId.value : undefined,
            priority:
                casePriorities[moderationCase.id] ?? moderationCase.priority,
            resolution: caseResolutions[moderationCase.id] ?? '',
        },
        { preserveScroll: true },
    );
}

function storeKnowledge(): void {
    knowledgeForm.post('/admin/support/knowledge', {
        preserveScroll: true,
        onSuccess: () =>
            knowledgeForm.reset('title', 'body', 'source_url', 'expires_at'),
    });
}

function storePrompt(): void {
    promptForm.post('/admin/support/prompts', {
        preserveScroll: true,
        onSuccess: () => promptForm.reset('instructions', 'model'),
    });
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

        <SectionCard
            :title="t('support.chatbot.title')"
            :description="t('support.chatbot.description')"
        >
            <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
                <MetricCard
                    :label="t('support.chatbot.sessions')"
                    :value="chatbotGovernance.metrics.sessions"
                    :icon="Bot"
                    tone="blue"
                />
                <MetricCard
                    :label="t('support.chatbot.handoffRate')"
                    :value="`${chatbotGovernance.metrics.handoff_rate}%`"
                    :icon="MessageSquareReply"
                    tone="orange"
                />
                <MetricCard
                    :label="t('support.chatbot.helpfulRate')"
                    :value="`${chatbotGovernance.metrics.helpful_rate}%`"
                    :icon="MessageSquareReply"
                    tone="teal"
                />
                <MetricCard
                    :label="t('support.chatbot.latency')"
                    :value="`${chatbotGovernance.metrics.average_latency_ms} ms`"
                    :icon="Bot"
                    tone="violet"
                />
                <MetricCard
                    :label="t('support.chatbot.escalations')"
                    :value="chatbotGovernance.metrics.escalations"
                    :icon="ShieldAlert"
                    tone="orange"
                />
                <MetricCard
                    :label="t('support.chatbot.expiredSources')"
                    :value="chatbotGovernance.metrics.expired_sources"
                    :icon="ShieldAlert"
                    tone="orange"
                />
            </div>

            <div v-if="isSuperAdmin" class="mt-6 grid gap-6 xl:grid-cols-2">
                <form
                    class="space-y-3 rounded-2xl border border-border p-4"
                    @submit.prevent="storeKnowledge"
                >
                    <h3 class="font-bold text-foreground">
                        {{ t('support.chatbot.newSource') }}
                    </h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <input
                            v-model="knowledgeForm.stable_key"
                            required
                            pattern="[a-z0-9._-]+"
                            class="erin-focus h-10 rounded-xl border border-border px-3 text-sm"
                            :placeholder="t('support.chatbot.sourceKey')"
                        />
                        <select
                            v-model="knowledgeForm.locale"
                            :aria-label="t('support.chatbot.sourceLocale')"
                            class="erin-focus h-10 rounded-xl border border-border bg-card px-3 text-sm"
                        >
                            <option value="de">
                                {{ t('support.chatbot.german') }}
                            </option>
                            <option value="en">
                                {{ t('support.chatbot.english') }}
                            </option>
                        </select>
                    </div>
                    <input
                        v-model="knowledgeForm.title"
                        required
                        maxlength="180"
                        class="erin-focus h-10 w-full rounded-xl border border-border px-3 text-sm"
                        :placeholder="t('support.chatbot.sourceTitle')"
                    />
                    <Textarea
                        v-model="knowledgeForm.body"
                        required
                        rows="5"
                        :placeholder="t('support.chatbot.sourceBody')"
                    />
                    <input
                        v-model="knowledgeForm.source_url"
                        type="url"
                        class="erin-focus h-10 w-full rounded-xl border border-border px-3 text-sm"
                        :placeholder="t('support.chatbot.sourceUrl')"
                    />
                    <input
                        v-model="knowledgeForm.expires_at"
                        type="datetime-local"
                        :aria-label="t('support.chatbot.sourceExpiresAt')"
                        class="erin-focus h-10 w-full rounded-xl border border-border px-3 text-sm"
                    />
                    <button
                        type="submit"
                        :disabled="knowledgeForm.processing"
                        class="erin-focus rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white"
                    >
                        {{ t('support.chatbot.saveDraft') }}
                    </button>
                </form>

                <form
                    class="space-y-3 rounded-2xl border border-border p-4"
                    @submit.prevent="storePrompt"
                >
                    <h3 class="font-bold text-foreground">
                        {{ t('support.chatbot.newPrompt') }}
                    </h3>
                    <Textarea
                        v-model="promptForm.instructions"
                        required
                        rows="7"
                        :placeholder="t('support.chatbot.promptInstructions')"
                    />
                    <input
                        v-model="promptForm.model"
                        class="erin-focus h-10 w-full rounded-xl border border-border px-3 text-sm"
                        :placeholder="t('support.chatbot.model')"
                    />
                    <button
                        type="submit"
                        :disabled="promptForm.processing"
                        class="erin-focus rounded-xl bg-violet-600 px-4 py-2 text-sm font-bold text-white"
                    >
                        {{ t('support.chatbot.savePrompt') }}
                    </button>
                </form>
            </div>

            <div class="mt-6 grid gap-6 xl:grid-cols-2">
                <section>
                    <h3 class="text-sm font-bold text-foreground">
                        {{ t('support.chatbot.sources') }}
                    </h3>
                    <div class="mt-2 space-y-2">
                        <article
                            v-for="article in chatbotGovernance.articles"
                            :key="article.id"
                            class="flex items-center justify-between gap-3 rounded-xl border border-border p-3 text-sm"
                        >
                            <div>
                                <strong>{{ article.title }}</strong>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        t('support.chatbot.sourceMeta', {
                                            key: article.stable_key,
                                            version: article.version,
                                            locale: article.locale,
                                            status: article.status,
                                        })
                                    }}
                                </p>
                            </div>
                            <div v-if="isSuperAdmin" class="flex gap-2">
                                <button
                                    v-if="article.status === 'draft'"
                                    type="button"
                                    class="erin-focus rounded-lg bg-green-600 px-2 py-1 text-xs font-bold text-white"
                                    @click="
                                        router.post(
                                            `/admin/support/knowledge/${article.id}/publish`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    "
                                >
                                    {{ t('support.chatbot.publish') }}
                                </button>
                                <button
                                    v-if="article.status === 'published'"
                                    type="button"
                                    class="erin-focus rounded-lg border border-red-200 px-2 py-1 text-xs font-bold text-red-700"
                                    @click="
                                        router.post(
                                            `/admin/support/knowledge/${article.id}/retire`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    "
                                >
                                    {{ t('support.chatbot.retire') }}
                                </button>
                            </div>
                        </article>
                    </div>
                </section>
                <section>
                    <h3 class="text-sm font-bold text-foreground">
                        {{ t('support.chatbot.prompts') }}
                    </h3>
                    <div class="mt-2 space-y-2">
                        <article
                            v-for="prompt in chatbotGovernance.prompts"
                            :key="prompt.id"
                            class="flex items-center justify-between rounded-xl border border-border p-3 text-sm"
                        >
                            <span
                                >{{
                                    t('support.chatbot.promptMeta', {
                                        version: prompt.version,
                                        model:
                                            prompt.model ||
                                            t('support.chatbot.defaultModel'),
                                    })
                                }}
                                <strong v-if="prompt.active"
                                    >({{ t('support.chatbot.active') }})</strong
                                ></span
                            >
                            <button
                                v-if="isSuperAdmin && !prompt.active"
                                type="button"
                                class="erin-focus rounded-lg bg-violet-600 px-2 py-1 text-xs font-bold text-white"
                                @click="
                                    router.post(
                                        `/admin/support/prompts/${prompt.id}/activate`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                "
                            >
                                {{ t('support.chatbot.activate') }}
                            </button>
                        </article>
                    </div>
                </section>
            </div>
        </SectionCard>

        <SectionCard
            :title="t('support.moderationTitle')"
            :description="t('support.moderationDescription')"
        >
            <div class="grid gap-6 xl:grid-cols-2">
                <section>
                    <h3 class="text-sm font-bold text-foreground">
                        {{ t('support.pendingFeedbackTitle') }}
                    </h3>
                    <div
                        v-if="moderation.feedback.length"
                        class="mt-3 space-y-3"
                    >
                        <article
                            v-for="feedback in moderation.feedback"
                            :key="feedback.id"
                            class="rounded-2xl border border-border p-4"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <div>
                                    <p
                                        class="text-sm font-bold text-foreground"
                                    >
                                        {{ feedback.author.name }} →
                                        {{
                                            feedback.subject_user?.name ??
                                            feedback.subject_company?.name
                                        }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{
                                            feedback.application?.job_posting
                                                .title
                                        }}
                                        · {{ formatDate(feedback.created_at) }}
                                    </p>
                                </div>
                                <StatusBadge
                                    :label="humanize(feedback.sentiment)"
                                    :tone="
                                        feedback.sentiment === 'negative'
                                            ? 'red'
                                            : 'green'
                                    "
                                />
                            </div>
                            <p class="mt-3 text-sm text-muted-foreground">
                                {{ humanize(feedback.reason_code) }}
                                <span v-if="feedback.comment">
                                    · {{ feedback.comment }}
                                </span>
                            </p>
                            <template v-if="isSuperAdmin">
                                <Textarea
                                    v-model="feedbackReasons[feedback.id]"
                                    rows="2"
                                    :placeholder="
                                        t('support.moderationReasonPlaceholder')
                                    "
                                    class="mt-3 text-xs"
                                />
                                <div class="mt-3 flex gap-2">
                                    <button
                                        type="button"
                                        class="erin-focus rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white"
                                        @click="
                                            reviewFeedback(feedback, 'approved')
                                        "
                                    >
                                        {{ t('support.approveFeedback') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="erin-focus rounded-xl bg-red-600 px-3 py-2 text-xs font-bold text-white"
                                        @click="
                                            reviewFeedback(feedback, 'rejected')
                                        "
                                    >
                                        {{ t('support.rejectFeedback') }}
                                    </button>
                                </div>
                            </template>
                        </article>
                    </div>
                    <p v-else class="mt-3 text-sm text-muted-foreground">
                        {{ t('support.noPendingFeedback') }}
                    </p>
                </section>

                <section>
                    <h3 class="text-sm font-bold text-foreground">
                        {{ t('support.moderationCasesTitle') }}
                    </h3>
                    <div v-if="moderation.cases.length" class="mt-3 space-y-3">
                        <article
                            v-for="moderationCase in moderation.cases"
                            :key="moderationCase.id"
                            class="rounded-2xl border border-border p-4"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <div>
                                    <p
                                        class="text-sm font-bold text-foreground"
                                    >
                                        {{
                                            moderationCase.subject_user?.name ??
                                            moderationCase.subject_company?.name
                                        }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ humanize(moderationCase.reason) }} ·
                                        {{
                                            formatDate(
                                                moderationCase.created_at,
                                            )
                                        }}
                                    </p>
                                </div>
                                <StatusBadge
                                    :label="humanize(moderationCase.status)"
                                    :tone="statusTone(moderationCase.status)"
                                />
                            </div>
                            <p class="mt-2 text-xs text-muted-foreground">
                                {{
                                    t('support.caseAssignment', {
                                        name:
                                            moderationCase.assignee?.name ??
                                            t('common.notAssigned'),
                                    })
                                }}
                            </p>
                            <template v-if="isSuperAdmin">
                                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                    <select
                                        v-model="
                                            casePriorities[moderationCase.id]
                                        "
                                        class="erin-focus h-10 rounded-xl border border-border bg-card px-3 text-xs"
                                    >
                                        <option value="">
                                            {{
                                                humanize(
                                                    moderationCase.priority,
                                                )
                                            }}
                                        </option>
                                        <option value="normal">
                                            {{ humanize('normal') }}
                                        </option>
                                        <option value="high">
                                            {{ humanize('high') }}
                                        </option>
                                        <option value="urgent">
                                            {{ humanize('urgent') }}
                                        </option>
                                    </select>
                                    <button
                                        type="button"
                                        class="erin-focus rounded-xl border border-border px-3 py-2 text-xs font-bold text-muted-foreground"
                                        @click="
                                            updateModerationCase(
                                                moderationCase,
                                                'assign',
                                            )
                                        "
                                    >
                                        {{ t('support.assignToMe') }}
                                    </button>
                                </div>
                                <Textarea
                                    v-model="caseResolutions[moderationCase.id]"
                                    rows="2"
                                    :placeholder="
                                        t('support.resolutionPlaceholder')
                                    "
                                    class="mt-2 text-xs"
                                />
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="erin-focus rounded-xl bg-amber-500 px-3 py-2 text-xs font-bold text-white"
                                        @click="
                                            updateModerationCase(
                                                moderationCase,
                                                'escalate',
                                            )
                                        "
                                    >
                                        {{ t('support.escalateCase') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="erin-focus rounded-xl bg-slate-900 px-3 py-2 text-xs font-bold text-white"
                                        @click="
                                            updateModerationCase(
                                                moderationCase,
                                                'resolve',
                                            )
                                        "
                                    >
                                        {{ t('support.resolveCase') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="erin-focus rounded-xl border border-border px-3 py-2 text-xs font-bold text-muted-foreground"
                                        @click="
                                            updateModerationCase(
                                                moderationCase,
                                                'dismiss',
                                            )
                                        "
                                    >
                                        {{ t('support.dismissCase') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="erin-focus rounded-xl bg-red-700 px-3 py-2 text-xs font-bold text-white"
                                        @click="
                                            updateModerationCase(
                                                moderationCase,
                                                'block',
                                            )
                                        "
                                    >
                                        {{ t('support.blockSubject') }}
                                    </button>
                                </div>
                            </template>
                        </article>
                    </div>
                    <p v-else class="mt-3 text-sm text-muted-foreground">
                        {{ t('support.noModerationCases') }}
                    </p>
                </section>
            </div>
        </SectionCard>

        <SectionCard flush>
            <form
                class="grid gap-3 border-b border-border p-4 lg:grid-cols-[minmax(16rem,1fr)_12rem_11rem_14rem_auto]"
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
                    class="erin-focus h-11 rounded-xl border border-border bg-card px-3 text-sm"
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
                    class="erin-focus h-11 rounded-xl border border-border bg-card px-3 text-sm"
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
                    class="erin-focus h-11 rounded-xl border border-border bg-card px-3 text-sm"
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
                        class="erin-focus grid size-11 place-items-center rounded-xl border border-border text-muted-foreground"
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
                <aside class="border-b border-border xl:border-r xl:border-b-0">
                    <div class="divide-y divide-border">
                        <button
                            v-for="ticket in tickets.data"
                            :key="ticket.id"
                            type="button"
                            class="w-full p-4 text-left transition"
                            :class="
                                selectedTicket?.id === ticket.id
                                    ? 'bg-blue-50'
                                    : 'hover:bg-muted'
                            "
                            @click="selectedId = ticket.id"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <span
                                    class="text-[11px] font-bold text-muted-foreground"
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
                                class="mt-2 text-sm leading-5 font-bold text-foreground"
                            >
                                {{ ticket.subject }}
                            </p>
                            <p
                                class="mt-2 truncate text-xs text-muted-foreground"
                            >
                                {{ ticket.requester.name }}
                            </p>
                            <div
                                class="mt-2 flex items-center justify-between text-[10px]"
                            >
                                <StatusBadge
                                    :label="humanize(ticket.status)"
                                    :tone="statusTone(ticket.status)"
                                />
                                <span class="text-muted-foreground">
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
                                <span
                                    class="text-xs font-bold text-muted-foreground"
                                >
                                    {{ selectedTicket.number }}
                                </span>
                                <StatusBadge
                                    :label="humanize(selectedTicket.status)"
                                    :tone="statusTone(selectedTicket.status)"
                                />
                            </div>
                            <h2 class="mt-2 text-xl font-bold text-foreground">
                                {{ selectedTicket.subject }}
                            </h2>
                            <p class="mt-1 text-xs text-muted-foreground">
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
                        <div class="text-xs text-muted-foreground">
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
                                class="overflow-hidden rounded-2xl border border-border"
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
                            <section class="rounded-2xl bg-muted p-5">
                                <h3 class="text-sm font-bold text-foreground">
                                    {{ t('support.overview') }}
                                </h3>
                                <p
                                    class="mt-2 text-sm leading-6 text-muted-foreground"
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
                                        <dt class="text-muted-foreground">
                                            {{ t('support.requester') }}
                                        </dt>
                                        <dd
                                            class="mt-1 font-semibold text-foreground"
                                        >
                                            {{ selectedTicket.requester.name }}
                                        </dd>
                                        <dd class="text-muted-foreground">
                                            {{ selectedTicket.requester.email }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">
                                            {{ t('support.company') }}
                                        </dt>
                                        <dd
                                            class="mt-1 font-semibold text-foreground"
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
                                class="rounded-2xl border border-border p-5"
                                @submit.prevent="updateTicket"
                            >
                                <h3 class="text-sm font-bold text-foreground">
                                    {{ t('support.editingTitle') }}
                                </h3>
                                <label class="mt-4 block">
                                    <span
                                        class="text-xs font-bold text-muted-foreground"
                                        >{{ t('common.status') }}</span
                                    >
                                    <select
                                        v-model="ticketForm.status"
                                        class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-border bg-card px-3 text-sm"
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
                                        class="text-xs font-bold text-muted-foreground"
                                    >
                                        {{ t('support.priority') }}
                                    </span>
                                    <select
                                        v-model="ticketForm.priority"
                                        class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-border bg-card px-3 text-sm"
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
                                        class="text-xs font-bold text-muted-foreground"
                                    >
                                        {{ t('support.assignedTo') }}
                                    </span>
                                    <select
                                        v-model="ticketForm.assigned_to"
                                        class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-border bg-card px-3 text-sm"
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
