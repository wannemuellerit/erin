<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Activity,
    Download,
    FileLock2,
    Flag,
    ListChecks,
    PencilLine,
    Plus,
    Save,
    ShieldCheck,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/product/EmptyState.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import Textarea from '@/components/product/Textarea.vue';
import adminAccessList from '@/routes/admin/access-list';
import adminEmailTemplates from '@/routes/admin/email-templates';
import adminFeatureFlags from '@/routes/admin/feature-flags';
import adminGdprRequests from '@/routes/admin/gdpr-requests';
import AdminPagination from './_components/AdminPagination.vue';
import SystemLoginHistory from './_components/SystemLoginHistory.vue';
import SystemOverview from './_components/SystemOverview.vue';
import { useAdminI18n } from './_i18n';
import type { AdminPaginator } from './_shared';
import { statusTone } from './_shared';

type JsonConditions =
    Record<string, FormDataConvertible> | FormDataConvertible[] | null;

type FeatureFlagRow = {
    id: number;
    key: string;
    name: string;
    description: string | null;
    enabled: boolean;
    rollout_percentage: number;
    conditions: JsonConditions;
    updated_by: number | null;
    updater: {
        id: number;
        name: string;
        email: string;
    } | null;
    created_at: string;
    updated_at: string;
};

type LoginHistoryRow = {
    id: number;
    user_id: number | null;
    email: string;
    event: string;
    successful: boolean;
    ip_address: string | null;
    user_agent: string | null;
    failure_reason: string | null;
    created_at: string;
    user: {
        id: number;
        name: string;
        email: string;
    } | null;
};

type GdprRequestRow = {
    id: number;
    user_id: number;
    handled_by: number | null;
    verified_by: number | null;
    approved_by: number | null;
    type: 'export' | 'delete';
    status: string;
    reason: string | null;
    legal_hold: boolean;
    legal_hold_reason: string | null;
    failed_at: string | null;
    failure_reason: string | null;
    export_expires_at: string | null;
    downloaded_at: string | null;
    download_url: string | null;
    verified_at: string | null;
    due_at: string | null;
    completed_at: string | null;
    created_at: string;
    updated_at: string;
    user: {
        id: number;
        name: string;
        email: string;
        role: string;
        status: string;
    };
    handler: {
        id: number;
        name: string;
        email: string;
    } | null;
    verifier: {
        id: number;
        name: string;
        email: string;
    } | null;
    approver: {
        id: number;
        name: string;
        email: string;
    } | null;
};

type AccessListEntryRow = {
    id: number;
    list_type: 'blacklist' | 'whitelist';
    subject_type: 'email' | 'domain' | 'ip';
    value: string;
    reason: string;
    created_by: number | null;
    expires_at: string | null;
    created_at: string;
    updated_at: string;
    creator: {
        id: number;
        name: string;
        email: string;
    } | null;
};

type EmailTemplateRow = {
    id: number;
    key: string;
    locale: 'de' | 'en';
    subject: string;
    body_html: string;
    body_text: string | null;
    is_active: boolean;
    updated_by: number | null;
    created_at: string;
    updated_at: string;
    updater: {
        id: number;
        name: string;
        email: string;
    } | null;
};

type EmailTemplateGroup = {
    key: string;
    is_active: boolean;
    de: EmailTemplateRow | null;
    en: EmailTemplateRow | null;
    updated_at: string;
    updater: EmailTemplateRow['updater'];
};

const props = defineProps<{
    feature_flags: FeatureFlagRow[];
    login_history: LoginHistoryRow[];
    gdpr_requests: AdminPaginator<GdprRequestRow>;
    gdpr_statuses: string[];
    gdpr_types: Array<'export' | 'delete'>;
    access_list_entries: AccessListEntryRow[];
    email_templates: EmailTemplateRow[];
    gdpr: {
        open: number;
        overdue: number;
    };
    governance: {
        access_list_entries: number;
        email_templates: number;
    };
    runtime: {
        php: string;
        laravel: string;
        environment: string;
        debug: boolean;
        queue_connection: string;
        failed_jobs: number;
    };
    integrations: {
        stripe: boolean;
        openai: boolean;
        livekit: boolean;
        recent_failed_webhooks: number;
    };
}>();

const page = usePage();
const isSuperAdmin = computed(
    () => String(page.props.auth.user.role) === 'super_admin',
);
const { t, formatDate, humanize } = useAdminI18n();

const selectedGdprId = ref<number | null>(
    props.gdpr_requests.data[0]?.id ?? null,
);
const selectedGdpr = computed(
    () =>
        props.gdpr_requests.data.find(
            (request) => request.id === selectedGdprId.value,
        ) ??
        props.gdpr_requests.data[0] ??
        null,
);

const gdprCreateForm = useForm({
    user_id: '',
    type: 'export' as 'export' | 'delete',
    reason: '',
    due_at: '',
    legal_hold: false,
    legal_hold_reason: '',
});

const gdprUpdateForm = useForm({
    type: 'export' as 'export' | 'delete',
    status: 'requested',
    reason: '',
    due_at: '',
    legal_hold: false,
    legal_hold_reason: '',
});

const firstGdprCreateError = computed(
    () => Object.values(gdprCreateForm.errors)[0] as string | undefined,
);
const firstGdprUpdateError = computed(
    () => Object.values(gdprUpdateForm.errors)[0] as string | undefined,
);
const gdprTransitions: Record<string, string[]> = {
    requested: ['verified', 'rejected'],
    verified: ['processing', 'rejected'],
    failed: ['processing'],
};

watch(
    selectedGdpr,
    (request) => {
        gdprUpdateForm.clearErrors();

        if (!request) {
            return;
        }

        gdprUpdateForm.type = request.type;
        gdprUpdateForm.status =
            gdprTransitions[request.status]?.[0] ?? request.status;
        gdprUpdateForm.reason = request.reason ?? '';
        gdprUpdateForm.due_at = toLocalDateTime(request.due_at);
        gdprUpdateForm.legal_hold = request.legal_hold;
        gdprUpdateForm.legal_hold_reason = request.legal_hold_reason ?? '';
    },
    { immediate: true },
);
const allowedGdprStatuses = computed(() => {
    if (!selectedGdpr.value) {
        return [];
    }

    return gdprTransitions[selectedGdpr.value.status] ?? [];
});

function createGdprRequest(): void {
    gdprCreateForm.post(adminGdprRequests.store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            gdprCreateForm.reset();
            gdprCreateForm.type = 'export';
        },
    });
}

function updateGdprRequest(): void {
    if (!selectedGdpr.value) {
        return;
    }

    gdprUpdateForm.patch(adminGdprRequests.update.url(selectedGdpr.value.id), {
        preserveScroll: true,
    });
}

const accessMode = ref<'create' | 'edit'>(
    props.access_list_entries.length > 0 ? 'edit' : 'create',
);
const selectedAccessId = ref<number | null>(
    props.access_list_entries[0]?.id ?? null,
);
const selectedAccessEntry = computed(
    () =>
        props.access_list_entries.find(
            (entry) => entry.id === selectedAccessId.value,
        ) ?? null,
);
const accessForm = useForm({
    list_type: 'blacklist' as 'blacklist' | 'whitelist',
    subject_type: 'email' as 'email' | 'domain' | 'ip',
    value: '',
    reason: '',
    expires_at: '',
});
const firstAccessError = computed(
    () => Object.values(accessForm.errors)[0] as string | undefined,
);

watch(
    selectedAccessEntry,
    (entry) => {
        if (accessMode.value !== 'edit' || !entry) {
            return;
        }

        populateAccessForm(entry);
    },
    { immediate: true },
);

function populateAccessForm(entry: AccessListEntryRow): void {
    accessForm.clearErrors();
    accessForm.list_type = entry.list_type;
    accessForm.subject_type = entry.subject_type;
    accessForm.value = entry.value;
    accessForm.reason = entry.reason;
    accessForm.expires_at = toLocalDateTime(entry.expires_at);
}

function editAccessEntry(entry: AccessListEntryRow): void {
    accessMode.value = 'edit';
    selectedAccessId.value = entry.id;
    populateAccessForm(entry);
}

function newAccessEntry(): void {
    accessMode.value = 'create';
    selectedAccessId.value = null;
    accessForm.reset();
    accessForm.list_type = 'blacklist';
    accessForm.subject_type = 'email';
    accessForm.clearErrors();
}

function saveAccessEntry(): void {
    if (accessMode.value === 'edit' && selectedAccessEntry.value) {
        accessForm.patch(
            adminAccessList.update.url(selectedAccessEntry.value.id),
            {
                preserveScroll: true,
            },
        );

        return;
    }

    accessForm.post(adminAccessList.store.url(), {
        preserveScroll: true,
        onSuccess: newAccessEntry,
    });
}

function deleteAccessEntry(entry: AccessListEntryRow): void {
    if (
        !window.confirm(
            t('system.access.deleteConfirm', { value: entry.value }),
        )
    ) {
        return;
    }

    router.delete(adminAccessList.destroy.url(entry.id), {
        preserveScroll: true,
        onSuccess: newAccessEntry,
    });
}

const emailTemplateGroups = computed<EmailTemplateGroup[]>(() => {
    const groups = new Map<string, EmailTemplateGroup>();

    for (const template of props.email_templates) {
        const existing = groups.get(template.key) ?? {
            key: template.key,
            is_active: template.is_active,
            de: null,
            en: null,
            updated_at: template.updated_at,
            updater: template.updater,
        };

        existing[template.locale] = template;

        if (new Date(template.updated_at) > new Date(existing.updated_at)) {
            existing.updated_at = template.updated_at;
            existing.updater = template.updater;
        }

        groups.set(template.key, existing);
    }

    return [...groups.values()];
});
const templateMode = ref<'create' | 'edit'>(
    emailTemplateGroups.value.length > 0 ? 'edit' : 'create',
);
const selectedTemplateKey = ref<string | null>(
    emailTemplateGroups.value[0]?.key ?? null,
);
const selectedTemplate = computed(
    () =>
        emailTemplateGroups.value.find(
            (template) => template.key === selectedTemplateKey.value,
        ) ?? null,
);
const templateForm = useForm({
    key: '',
    is_active: true,
    translations: {
        de: {
            subject: '',
            body_html: '',
            body_text: '',
        },
        en: {
            subject: '',
            body_html: '',
            body_text: '',
        },
    },
});
const firstTemplateError = computed(
    () => Object.values(templateForm.errors)[0] as string | undefined,
);

watch(
    selectedTemplate,
    (template) => {
        if (templateMode.value !== 'edit' || !template) {
            return;
        }

        populateTemplateForm(template);
    },
    { immediate: true },
);

function populateTemplateForm(template: EmailTemplateGroup): void {
    templateForm.clearErrors();
    templateForm.key = template.key;
    templateForm.is_active = template.is_active;
    templateForm.translations.de.subject = template.de?.subject ?? '';
    templateForm.translations.de.body_html = template.de?.body_html ?? '';
    templateForm.translations.de.body_text = template.de?.body_text ?? '';
    templateForm.translations.en.subject = template.en?.subject ?? '';
    templateForm.translations.en.body_html = template.en?.body_html ?? '';
    templateForm.translations.en.body_text = template.en?.body_text ?? '';
}

function editTemplate(template: EmailTemplateGroup): void {
    templateMode.value = 'edit';
    selectedTemplateKey.value = template.key;
    populateTemplateForm(template);
}

function newTemplate(): void {
    templateMode.value = 'create';
    selectedTemplateKey.value = null;
    templateForm.reset();
    templateForm.is_active = true;
    templateForm.clearErrors();
}

function saveTemplate(): void {
    templateForm.post(adminEmailTemplates.upsert.url(), {
        preserveScroll: true,
    });
}

function deleteTemplate(template: EmailTemplateGroup): void {
    if (
        !window.confirm(
            t('system.templates.deleteConfirm', {
                key: template.key,
            }),
        )
    ) {
        return;
    }

    router.delete(adminEmailTemplates.destroy.url(template.key), {
        preserveScroll: true,
        onSuccess: newTemplate,
    });
}

function toLocalDateTime(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const offset = date.getTimezoneOffset() * 60_000;

    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

const selectedFlagId = ref<number | null>(props.feature_flags[0]?.id ?? null);
const mutatingFlagId = ref<number | null>(null);
const selectedFlag = computed(
    () =>
        props.feature_flags.find((flag) => flag.id === selectedFlagId.value) ??
        props.feature_flags[0] ??
        null,
);

const createConditionsText = ref('{}');
const updateConditionsText = ref('{}');
const createJsonError = ref('');
const updateJsonError = ref('');

const createForm = useForm({
    key: '',
    name: '',
    description: '',
    enabled: false,
    rollout_percentage: '0',
});

const updateForm = useForm({
    name: '',
    description: '',
    enabled: false,
    rollout_percentage: '0',
});

const firstCreateError = computed(
    () => Object.values(createForm.errors)[0] as string | undefined,
);
const firstUpdateError = computed(
    () => Object.values(updateForm.errors)[0] as string | undefined,
);

watch(
    selectedFlag,
    (flag) => {
        updateForm.clearErrors();
        updateJsonError.value = '';

        if (!flag) {
            updateForm.reset();
            updateConditionsText.value = '{}';

            return;
        }

        updateForm.name = flag.name;
        updateForm.description = flag.description ?? '';
        updateForm.enabled = flag.enabled;
        updateForm.rollout_percentage = flag.rollout_percentage.toString();
        updateConditionsText.value = JSON.stringify(
            flag.conditions ?? {},
            null,
            2,
        );
    },
    { immediate: true },
);

function parseConditions(
    value: string,
): Exclude<JsonConditions, null> | undefined {
    try {
        const parsed = JSON.parse(value) as unknown;

        if (parsed === null || typeof parsed !== 'object') {
            return undefined;
        }

        return parsed as
            Record<string, FormDataConvertible> | FormDataConvertible[];
    } catch {
        return undefined;
    }
}

function createFlag(): void {
    const conditions = parseConditions(createConditionsText.value);

    if (conditions === undefined) {
        createJsonError.value = t('system.flags.invalidJson');

        return;
    }

    createJsonError.value = '';
    createForm
        .transform((data) => ({
            ...data,
            conditions,
        }))
        .post(adminFeatureFlags.store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                createForm.reset();
                createConditionsText.value = '{}';
            },
        });
}

function updateFlag(): void {
    if (!selectedFlag.value) {
        return;
    }

    const conditions = parseConditions(updateConditionsText.value);

    if (conditions === undefined) {
        updateJsonError.value = t('system.flags.invalidJson');

        return;
    }

    updateJsonError.value = '';
    updateForm
        .transform((data) => ({
            ...data,
            conditions,
        }))
        .patch(adminFeatureFlags.update.url(selectedFlag.value.id), {
            preserveScroll: true,
        });
}

function toggleFlag(flag: FeatureFlagRow): void {
    mutatingFlagId.value = flag.id;
    router.patch(
        adminFeatureFlags.update.url(flag.id),
        {
            name: flag.name,
            description: flag.description ?? '',
            enabled: !flag.enabled,
            rollout_percentage: flag.rollout_percentage,
            conditions: flag.conditions,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                mutatingFlagId.value = null;
            },
        },
    );
}

function deleteFlag(flag: FeatureFlagRow): void {
    if (!window.confirm(t('system.flags.deleteConfirm', { key: flag.key }))) {
        return;
    }

    mutatingFlagId.value = flag.id;
    router.delete(adminFeatureFlags.destroy.url(flag.id), {
        preserveScroll: true,
        onFinish: () => {
            mutatingFlagId.value = null;
        },
    });
}
</script>

<template>
    <Head :title="t('system.metaTitle')" />

    <div class="erin-page">
        <PageHeader
            :eyebrow="t('system.eyebrow')"
            :title="t('system.title')"
            :description="t('system.description')"
            :icon="Activity"
        />

        <div
            v-if="!isSuperAdmin"
            class="flex items-start gap-3 rounded-2xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900"
        >
            <ShieldCheck class="mt-0.5 size-4 shrink-0" />
            <p>
                {{ t('system.supportNotice') }}
            </p>
        </div>

        <SystemOverview
            :runtime="runtime"
            :integrations="integrations"
            :gdpr="gdpr"
            :governance="governance"
        />

        <SectionCard
            :title="t('system.gdpr.title')"
            :description="t('system.gdpr.description')"
            flush
        >
            <div class="grid xl:grid-cols-[22rem_minmax(0,1fr)]">
                <aside
                    class="border-b border-slate-200 xl:border-r xl:border-b-0"
                >
                    <div
                        v-if="gdpr_requests.data.length > 0"
                        class="space-y-2 p-3"
                    >
                        <button
                            v-for="request in gdpr_requests.data"
                            :key="request.id"
                            type="button"
                            class="erin-focus w-full rounded-xl border p-4 text-left transition"
                            :class="
                                selectedGdpr?.id === request.id
                                    ? 'border-blue-200 bg-blue-50'
                                    : 'border-slate-200 hover:bg-slate-50'
                            "
                            @click="selectedGdprId = request.id"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p
                                        class="truncate text-sm font-bold text-slate-900"
                                    >
                                        {{ request.user.name }}
                                    </p>
                                    <p
                                        class="mt-0.5 truncate text-xs text-slate-500"
                                    >
                                        {{ request.user.email }}
                                    </p>
                                </div>
                                <StatusBadge
                                    :label="humanize(request.status)"
                                    :tone="statusTone(request.status)"
                                />
                            </div>
                            <div
                                class="mt-3 flex items-center justify-between gap-3 text-xs text-slate-500"
                            >
                                <span>{{ humanize(request.type) }}</span>
                                <span>{{
                                    t('system.gdpr.deadline', {
                                        date: formatDate(request.due_at),
                                    })
                                }}</span>
                            </div>
                        </button>
                    </div>
                    <EmptyState
                        v-else
                        :title="t('system.gdpr.emptyTitle')"
                        :description="t('system.gdpr.emptyDescription')"
                        compact
                    />
                    <AdminPagination :paginator="gdpr_requests" />
                </aside>

                <div class="space-y-6 p-5 sm:p-6">
                    <div
                        v-if="selectedGdpr"
                        class="rounded-2xl border border-slate-200 p-5"
                    >
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div>
                                <p
                                    class="text-xs font-bold tracking-wide text-blue-600 uppercase"
                                >
                                    {{
                                        t('system.gdpr.requestReference', {
                                            id: selectedGdpr.id,
                                        })
                                    }}
                                </p>
                                <h3 class="mt-1 font-bold text-slate-950">
                                    {{ selectedGdpr.user.name }}
                                </h3>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ selectedGdpr.user.email }} ·
                                    {{ humanize(selectedGdpr.user.role) }}
                                </p>
                            </div>
                            <StatusBadge
                                :label="humanize(selectedGdpr.status)"
                                :tone="statusTone(selectedGdpr.status)"
                            />
                        </div>

                        <dl
                            class="mt-4 grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-4"
                        >
                            <div class="rounded-xl bg-slate-50 p-3">
                                <dt class="font-bold text-slate-600">
                                    {{ t('system.gdpr.type') }}
                                </dt>
                                <dd class="mt-1 text-slate-700">
                                    {{ humanize(selectedGdpr.type) }}
                                </dd>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <dt class="font-bold text-slate-600">
                                    {{ t('system.gdpr.dueAt') }}
                                </dt>
                                <dd class="mt-1 text-slate-700">
                                    {{ formatDate(selectedGdpr.due_at) }}
                                </dd>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <dt class="font-bold text-slate-600">
                                    {{ t('system.gdpr.handler') }}
                                </dt>
                                <dd class="mt-1 text-slate-700">
                                    {{
                                        selectedGdpr.handler?.name ??
                                        t('common.notAssigned')
                                    }}
                                </dd>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <dt class="font-bold text-slate-600">
                                    {{ t('common.created') }}
                                </dt>
                                <dd class="mt-1 text-slate-700">
                                    {{ formatDate(selectedGdpr.created_at) }}
                                </dd>
                            </div>
                        </dl>
                        <p
                            v-if="selectedGdpr.reason"
                            class="mt-4 rounded-xl border border-slate-100 p-3 text-sm whitespace-pre-line text-slate-600"
                        >
                            {{ selectedGdpr.reason }}
                        </p>
                        <div
                            v-if="selectedGdpr.legal_hold"
                            class="mt-4 rounded-xl border border-orange-200 bg-orange-50 p-3 text-sm text-orange-800"
                        >
                            <strong>{{ t('system.gdpr.legalHold') }}</strong>
                            <p class="mt-1">
                                {{ selectedGdpr.legal_hold_reason }}
                            </p>
                        </div>
                        <p
                            v-if="selectedGdpr.failure_reason"
                            class="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700"
                        >
                            {{ selectedGdpr.failure_reason }}
                        </p>
                        <a
                            v-if="selectedGdpr.download_url"
                            :href="selectedGdpr.download_url"
                            class="erin-focus mt-4 inline-flex h-10 items-center gap-2 rounded-xl bg-teal-600 px-4 text-xs font-bold text-white"
                        >
                            <Download class="size-4" />
                            {{ t('system.gdpr.downloadExport') }}
                        </a>
                    </div>

                    <div v-if="isSuperAdmin" class="grid gap-6 2xl:grid-cols-2">
                        <form
                            v-if="selectedGdpr"
                            class="rounded-2xl border border-slate-200 p-5"
                            @submit.prevent="updateGdprRequest"
                        >
                            <div class="flex items-center gap-2">
                                <PencilLine class="size-4 text-blue-600" />
                                <h3 class="font-bold text-slate-950">
                                    {{ t('system.gdpr.editTitle') }}
                                </h3>
                            </div>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                <label>
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        {{ t('system.gdpr.type') }}
                                    </span>
                                    <select
                                        v-model="gdprUpdateForm.type"
                                        disabled
                                        class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                    >
                                        <option
                                            v-for="type in gdpr_types"
                                            :key="type"
                                            :value="type"
                                        >
                                            {{ humanize(type) }}
                                        </option>
                                    </select>
                                </label>
                                <label>
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        {{ t('system.gdpr.status') }}
                                    </span>
                                    <select
                                        v-model="gdprUpdateForm.status"
                                        class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                    >
                                        <option
                                            v-for="status in allowedGdprStatuses"
                                            :key="status"
                                            :value="status"
                                        >
                                            {{ humanize(status) }}
                                        </option>
                                    </select>
                                </label>
                            </div>
                            <label
                                class="mt-3 flex items-start gap-3 rounded-xl border border-slate-200 p-3"
                            >
                                <input
                                    v-model="gdprUpdateForm.legal_hold"
                                    type="checkbox"
                                    class="erin-focus mt-0.5 size-4"
                                />
                                <span>
                                    <span
                                        class="block text-xs font-bold text-slate-700"
                                    >
                                        {{ t('system.gdpr.legalHold') }}
                                    </span>
                                    <span
                                        class="mt-1 block text-xs text-slate-500"
                                    >
                                        {{ t('system.gdpr.legalHoldHint') }}
                                    </span>
                                </span>
                            </label>
                            <label
                                v-if="gdprUpdateForm.legal_hold"
                                class="mt-3 block"
                            >
                                <span class="text-xs font-bold text-slate-600">
                                    {{ t('system.gdpr.legalHoldReason') }}
                                </span>
                                <Textarea
                                    v-model="gdprUpdateForm.legal_hold_reason"
                                    rows="3"
                                    class="mt-1.5"
                                />
                            </label>
                            <label class="mt-3 block">
                                <span class="text-xs font-bold text-slate-600">
                                    {{ t('system.gdpr.dueAt') }}
                                </span>
                                <input
                                    v-model="gdprUpdateForm.due_at"
                                    type="datetime-local"
                                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                                />
                            </label>
                            <label class="mt-3 block">
                                <span class="text-xs font-bold text-slate-600">
                                    {{ t('system.gdpr.reason') }}
                                </span>
                                <Textarea
                                    v-model="gdprUpdateForm.reason"
                                    rows="4"
                                    class="mt-1.5"
                                />
                            </label>
                            <p
                                v-if="firstGdprUpdateError"
                                class="mt-2 text-xs text-red-600"
                            >
                                {{ firstGdprUpdateError }}
                            </p>
                            <button
                                type="submit"
                                :disabled="
                                    gdprUpdateForm.processing ||
                                    allowedGdprStatuses.length === 0
                                "
                                class="erin-focus mt-4 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-xs font-bold text-white disabled:opacity-50"
                            >
                                <Save class="size-4" />
                                {{ t('system.gdpr.saveStatus') }}
                            </button>
                        </form>

                        <form
                            class="rounded-2xl border border-slate-200 p-5"
                            @submit.prevent="createGdprRequest"
                        >
                            <div class="flex items-center gap-2">
                                <Plus class="size-4 text-teal-600" />
                                <h3 class="font-bold text-slate-950">
                                    {{ t('system.gdpr.createTitle') }}
                                </h3>
                            </div>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                <label>
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        {{ t('system.gdpr.userId') }}
                                    </span>
                                    <input
                                        v-model="gdprCreateForm.user_id"
                                        type="number"
                                        min="1"
                                        placeholder="123"
                                        class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                                    />
                                </label>
                                <label>
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        {{ t('system.gdpr.type') }}
                                    </span>
                                    <select
                                        v-model="gdprCreateForm.type"
                                        class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                    >
                                        <option
                                            v-for="type in gdpr_types"
                                            :key="type"
                                            :value="type"
                                        >
                                            {{ humanize(type) }}
                                        </option>
                                    </select>
                                </label>
                            </div>
                            <label class="mt-3 block">
                                <span class="text-xs font-bold text-slate-600">
                                    {{ t('system.gdpr.dueAt') }}
                                </span>
                                <input
                                    v-model="gdprCreateForm.due_at"
                                    type="datetime-local"
                                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                                />
                            </label>
                            <label class="mt-3 block">
                                <span class="text-xs font-bold text-slate-600">
                                    {{ t('system.gdpr.reason') }}
                                </span>
                                <Textarea
                                    v-model="gdprCreateForm.reason"
                                    rows="4"
                                    class="mt-1.5"
                                />
                            </label>
                            <p
                                v-if="firstGdprCreateError"
                                class="mt-2 text-xs text-red-600"
                            >
                                {{ firstGdprCreateError }}
                            </p>
                            <button
                                type="submit"
                                :disabled="gdprCreateForm.processing"
                                class="erin-focus mt-4 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl bg-teal-600 px-4 text-xs font-bold text-white disabled:opacity-50"
                            >
                                <FileLock2 class="size-4" />
                                {{ t('system.gdpr.saveRequest') }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </SectionCard>

        <SectionCard
            :title="t('system.access.title')"
            :description="t('system.access.description')"
            flush
        >
            <div class="grid xl:grid-cols-[22rem_minmax(0,1fr)]">
                <aside
                    class="border-b border-slate-200 p-3 xl:border-r xl:border-b-0"
                >
                    <button
                        v-if="isSuperAdmin"
                        type="button"
                        class="erin-focus mb-3 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl border border-blue-200 bg-blue-50 text-xs font-bold text-blue-700"
                        @click="newAccessEntry"
                    >
                        <Plus class="size-4" />
                        {{ t('system.access.new') }}
                    </button>
                    <div
                        v-if="access_list_entries.length > 0"
                        class="max-h-[32rem] space-y-2 overflow-y-auto"
                    >
                        <button
                            v-for="entry in access_list_entries"
                            :key="entry.id"
                            type="button"
                            class="erin-focus w-full rounded-xl border p-4 text-left transition"
                            :class="
                                selectedAccessEntry?.id === entry.id &&
                                accessMode === 'edit'
                                    ? 'border-blue-200 bg-blue-50'
                                    : 'border-slate-200 hover:bg-slate-50'
                            "
                            @click="editAccessEntry(entry)"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p
                                        class="truncate font-mono text-xs font-bold text-slate-900"
                                    >
                                        {{ entry.value }}
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ humanize(entry.subject_type) }}
                                    </p>
                                </div>
                                <StatusBadge
                                    :label="humanize(entry.list_type)"
                                    :tone="
                                        entry.list_type === 'blacklist'
                                            ? 'red'
                                            : 'green'
                                    "
                                />
                            </div>
                            <p class="mt-3 truncate text-xs text-slate-500">
                                {{ entry.reason }}
                            </p>
                        </button>
                    </div>
                    <EmptyState
                        v-else
                        :title="t('system.access.emptyTitle')"
                        :description="t('system.access.emptyDescription')"
                        compact
                    />
                </aside>

                <div class="p-5 sm:p-6">
                    <form
                        v-if="isSuperAdmin"
                        class="rounded-2xl border border-slate-200 p-5"
                        @submit.prevent="saveAccessEntry"
                    >
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div class="flex items-center gap-2">
                                <ListChecks class="size-4 text-blue-600" />
                                <div>
                                    <h3 class="font-bold text-slate-950">
                                        {{
                                            accessMode === 'edit'
                                                ? t('system.access.editTitle')
                                                : t('system.access.createTitle')
                                        }}
                                    </h3>
                                    <p
                                        v-if="selectedAccessEntry"
                                        class="mt-0.5 text-xs text-slate-600"
                                    >
                                        {{
                                            t('system.access.createdByAt', {
                                                name:
                                                    selectedAccessEntry.creator
                                                        ?.name ??
                                                    t('common.system'),
                                                date: formatDate(
                                                    selectedAccessEntry.created_at,
                                                ),
                                            })
                                        }}
                                    </p>
                                </div>
                            </div>
                            <button
                                v-if="
                                    accessMode === 'edit' && selectedAccessEntry
                                "
                                type="button"
                                class="erin-focus inline-flex h-9 items-center gap-2 rounded-lg border border-red-200 px-3 text-xs font-bold text-red-700"
                                @click="deleteAccessEntry(selectedAccessEntry)"
                            >
                                <Trash2 class="size-4" />
                                {{ t('common.delete') }}
                            </button>
                        </div>
                        <div
                            class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                        >
                            <label>
                                <span class="text-xs font-bold text-slate-600">
                                    {{ t('system.access.listType') }}
                                </span>
                                <select
                                    v-model="accessForm.list_type"
                                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                >
                                    <option value="blacklist">
                                        {{ humanize('blacklist') }}
                                    </option>
                                    <option value="whitelist">
                                        {{ humanize('whitelist') }}
                                    </option>
                                </select>
                            </label>
                            <label>
                                <span class="text-xs font-bold text-slate-600">
                                    {{ t('system.access.subjectType') }}
                                </span>
                                <select
                                    v-model="accessForm.subject_type"
                                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                >
                                    <option value="email">
                                        {{ humanize('email') }}
                                    </option>
                                    <option value="domain">
                                        {{ humanize('domain') }}
                                    </option>
                                    <option value="ip">
                                        {{ humanize('ip') }}
                                    </option>
                                </select>
                            </label>
                            <label>
                                <span class="text-xs font-bold text-slate-600">
                                    {{ t('system.access.value') }}
                                </span>
                                <input
                                    v-model="accessForm.value"
                                    :placeholder="
                                        accessForm.subject_type === 'email'
                                            ? 'name@example.org'
                                            : accessForm.subject_type ===
                                                'domain'
                                              ? 'example.org'
                                              : '203.0.113.10'
                                    "
                                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 font-mono text-xs"
                                />
                            </label>
                            <label>
                                <span class="text-xs font-bold text-slate-600">
                                    {{ t('system.access.expiresAt') }}
                                </span>
                                <input
                                    v-model="accessForm.expires_at"
                                    type="datetime-local"
                                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                                />
                            </label>
                        </div>
                        <label class="mt-3 block">
                            <span class="text-xs font-bold text-slate-600">
                                {{ t('system.access.reason') }}
                            </span>
                            <Textarea
                                v-model="accessForm.reason"
                                rows="3"
                                class="mt-1.5"
                            />
                        </label>
                        <p
                            v-if="firstAccessError"
                            class="mt-2 text-xs text-red-600"
                        >
                            {{ firstAccessError }}
                        </p>
                        <button
                            type="submit"
                            :disabled="accessForm.processing"
                            class="erin-focus mt-4 inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 text-xs font-bold text-white disabled:opacity-50"
                        >
                            <Save class="size-4" />
                            {{
                                accessMode === 'edit'
                                    ? t('system.access.saveChanges')
                                    : t('system.access.create')
                            }}
                        </button>
                    </form>

                    <div
                        v-else-if="selectedAccessEntry"
                        class="rounded-2xl border border-slate-200 p-5"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p
                                    class="font-mono text-sm font-bold text-slate-900"
                                >
                                    {{ selectedAccessEntry.value }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{
                                        humanize(
                                            selectedAccessEntry.subject_type,
                                        )
                                    }}
                                </p>
                            </div>
                            <StatusBadge
                                :label="humanize(selectedAccessEntry.list_type)"
                                :tone="
                                    selectedAccessEntry.list_type ===
                                    'blacklist'
                                        ? 'red'
                                        : 'green'
                                "
                            />
                        </div>
                        <p
                            class="mt-4 rounded-xl bg-slate-50 p-4 text-sm whitespace-pre-line text-slate-600"
                        >
                            {{ selectedAccessEntry.reason }}
                        </p>
                        <p class="mt-4 text-xs text-slate-600">
                            {{
                                t('system.access.expiresAtValue', {
                                    date: formatDate(
                                        selectedAccessEntry.expires_at,
                                    ),
                                    name:
                                        selectedAccessEntry.creator?.name ??
                                        t('common.system'),
                                })
                            }}
                        </p>
                    </div>
                </div>
            </div>
        </SectionCard>

        <SectionCard
            :title="t('system.templates.title')"
            :description="t('system.templates.description')"
            flush
        >
            <div class="grid xl:grid-cols-[22rem_minmax(0,1fr)]">
                <aside
                    class="border-b border-slate-200 p-3 xl:border-r xl:border-b-0"
                >
                    <button
                        v-if="isSuperAdmin"
                        type="button"
                        class="erin-focus mb-3 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl border border-blue-200 bg-blue-50 text-xs font-bold text-blue-700"
                        @click="newTemplate"
                    >
                        <Plus class="size-4" />
                        {{ t('system.templates.new') }}
                    </button>
                    <div
                        v-if="emailTemplateGroups.length > 0"
                        class="max-h-[36rem] space-y-2 overflow-y-auto"
                    >
                        <button
                            v-for="template in emailTemplateGroups"
                            :key="template.key"
                            type="button"
                            class="erin-focus w-full rounded-xl border p-4 text-left transition"
                            :class="
                                selectedTemplate?.key === template.key &&
                                templateMode === 'edit'
                                    ? 'border-blue-200 bg-blue-50'
                                    : 'border-slate-200 hover:bg-slate-50'
                            "
                            @click="editTemplate(template)"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p
                                        class="truncate font-mono text-xs font-bold text-slate-900"
                                    >
                                        {{ template.key }}
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        DE {{ template.de ? '✓' : '–' }} · EN
                                        {{ template.en ? '✓' : '–' }}
                                    </p>
                                </div>
                                <StatusBadge
                                    :label="
                                        template.is_active
                                            ? t('common.active')
                                            : t('common.inactive')
                                    "
                                    :tone="
                                        template.is_active ? 'green' : 'slate'
                                    "
                                />
                            </div>
                            <p class="mt-3 truncate text-xs text-slate-500">
                                {{
                                    template.de?.subject ??
                                    template.en?.subject ??
                                    t('system.templates.noSubject')
                                }}
                            </p>
                        </button>
                    </div>
                    <EmptyState
                        v-else
                        :title="t('system.templates.emptyTitle')"
                        :description="t('system.templates.emptyDescription')"
                        compact
                    />
                </aside>

                <div class="p-5 sm:p-6">
                    <form
                        v-if="isSuperAdmin"
                        class="rounded-2xl border border-slate-200 p-5"
                        @submit.prevent="saveTemplate"
                    >
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div class="flex items-center gap-2">
                                <Mail class="size-4 text-blue-600" />
                                <div>
                                    <h3 class="font-bold text-slate-950">
                                        {{
                                            templateMode === 'edit'
                                                ? t(
                                                      'system.templates.editTitle',
                                                  )
                                                : t(
                                                      'system.templates.createTitle',
                                                  )
                                        }}
                                    </h3>
                                    <p
                                        v-if="selectedTemplate"
                                        class="mt-0.5 text-xs text-slate-600"
                                    >
                                        {{
                                            selectedTemplate.updater
                                                ? t(
                                                      'system.templates.changedByAt',
                                                      {
                                                          date: formatDate(
                                                              selectedTemplate.updated_at,
                                                          ),
                                                          name: selectedTemplate
                                                              .updater.name,
                                                      },
                                                  )
                                                : t(
                                                      'system.templates.changedAt',
                                                      {
                                                          date: formatDate(
                                                              selectedTemplate.updated_at,
                                                          ),
                                                      },
                                                  )
                                        }}
                                    </p>
                                </div>
                            </div>
                            <button
                                v-if="
                                    templateMode === 'edit' && selectedTemplate
                                "
                                type="button"
                                class="erin-focus inline-flex h-9 items-center gap-2 rounded-lg border border-red-200 px-3 text-xs font-bold text-red-700"
                                @click="deleteTemplate(selectedTemplate)"
                            >
                                <Trash2 class="size-4" />
                                {{ t('system.templates.deleteBoth') }}
                            </button>
                        </div>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <label>
                                <span class="text-xs font-bold text-slate-600">
                                    {{ t('system.templates.technicalKey') }}
                                </span>
                                <input
                                    v-model="templateForm.key"
                                    :readonly="templateMode === 'edit'"
                                    placeholder="application.status_changed"
                                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 font-mono text-xs read-only:bg-slate-50 read-only:text-slate-500"
                                />
                            </label>
                            <label
                                class="flex items-end gap-2 pb-2 text-sm font-semibold text-slate-700"
                            >
                                <input
                                    v-model="templateForm.is_active"
                                    type="checkbox"
                                    class="size-4 rounded border-slate-300 text-blue-600"
                                />
                                {{ t('system.templates.enabled') }}
                            </label>
                        </div>

                        <div class="mt-5 grid gap-5 2xl:grid-cols-2">
                            <fieldset
                                v-for="locale in ['de', 'en'] as const"
                                :key="locale"
                                class="rounded-2xl border border-slate-200 p-4"
                            >
                                <legend
                                    class="px-2 text-xs font-black tracking-wide text-slate-500 uppercase"
                                >
                                    {{
                                        locale === 'de'
                                            ? t('system.templates.german')
                                            : t('system.templates.english')
                                    }}
                                </legend>
                                <label class="block">
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        {{ t('system.templates.subject') }}
                                    </span>
                                    <input
                                        v-model="
                                            templateForm.translations[locale]
                                                .subject
                                        "
                                        class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                                    />
                                </label>
                                <label class="mt-3 block">
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        {{ t('system.templates.htmlBody') }}
                                    </span>
                                    <Textarea
                                        v-model="
                                            templateForm.translations[locale]
                                                .body_html
                                        "
                                        rows="8"
                                        class="mt-1.5 font-mono text-xs"
                                    />
                                </label>
                                <label class="mt-3 block">
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        {{ t('system.templates.textBody') }}
                                    </span>
                                    <Textarea
                                        v-model="
                                            templateForm.translations[locale]
                                                .body_text
                                        "
                                        rows="5"
                                        class="mt-1.5"
                                    />
                                </label>
                            </fieldset>
                        </div>
                        <p
                            v-if="firstTemplateError"
                            class="mt-3 text-xs text-red-600"
                        >
                            {{ firstTemplateError }}
                        </p>
                        <button
                            type="submit"
                            :disabled="templateForm.processing"
                            class="erin-focus mt-4 inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 text-xs font-bold text-white disabled:opacity-50"
                        >
                            <Save class="size-4" />
                            {{ t('system.templates.saveBoth') }}
                        </button>
                    </form>

                    <div
                        v-else-if="selectedTemplate"
                        class="grid gap-5 2xl:grid-cols-2"
                    >
                        <article
                            v-for="locale in ['de', 'en'] as const"
                            :key="locale"
                            class="rounded-2xl border border-slate-200 p-5"
                        >
                            <p
                                class="text-xs font-black tracking-wide text-blue-600 uppercase"
                            >
                                {{ locale }}
                            </p>
                            <h3 class="mt-2 font-bold text-slate-950">
                                {{
                                    selectedTemplate[locale]?.subject ??
                                    t('system.templates.noTranslation')
                                }}
                            </h3>
                            <pre
                                class="mt-4 max-h-64 overflow-auto rounded-xl bg-slate-50 p-4 font-sans text-xs whitespace-pre-wrap text-slate-600"
                                >{{
                                    selectedTemplate[locale]?.body_text ??
                                    selectedTemplate[locale]?.body_html ??
                                    '—'
                                }}</pre>
                        </article>
                    </div>
                </div>
            </div>
        </SectionCard>

        <SectionCard
            :title="t('system.flags.title')"
            :description="t('system.flags.description')"
            flush
        >
            <div class="grid xl:grid-cols-[20rem_minmax(0,1fr)]">
                <aside
                    class="border-b border-slate-200 p-3 xl:border-r xl:border-b-0"
                >
                    <div v-if="feature_flags.length > 0">
                        <button
                            v-for="flag in feature_flags"
                            :key="flag.id"
                            type="button"
                            class="mb-2 w-full rounded-xl border p-4 text-left last:mb-0"
                            :class="
                                selectedFlag?.id === flag.id
                                    ? 'border-blue-200 bg-blue-50'
                                    : 'border-slate-200 hover:bg-slate-50'
                            "
                            @click="selectedFlagId = flag.id"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p
                                        class="truncate text-sm font-bold text-slate-900"
                                    >
                                        {{ flag.name }}
                                    </p>
                                    <p
                                        class="mt-1 truncate font-mono text-[11px] text-slate-600"
                                    >
                                        {{ flag.key }}
                                    </p>
                                </div>
                                <StatusBadge
                                    :label="
                                        flag.enabled
                                            ? t('common.active')
                                            : t('common.inactive')
                                    "
                                    :tone="flag.enabled ? 'green' : 'slate'"
                                />
                            </div>
                            <p class="mt-3 text-xs text-slate-500">
                                {{
                                    t('system.flags.rollout', {
                                        value: flag.rollout_percentage,
                                    })
                                }}
                            </p>
                        </button>
                    </div>
                    <EmptyState
                        v-else
                        :title="t('system.flags.emptyTitle')"
                        :description="t('system.flags.emptyDescription')"
                        compact
                    />
                </aside>

                <div
                    v-if="isSuperAdmin"
                    class="grid gap-6 p-5 sm:p-6 2xl:grid-cols-2"
                >
                    <form
                        v-if="selectedFlag"
                        class="rounded-2xl border border-slate-200 p-5"
                        @submit.prevent="updateFlag"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold text-blue-600">
                                    {{ selectedFlag.key }}
                                </p>
                                <h3 class="mt-1 font-bold text-slate-950">
                                    {{ t('system.flags.editTitle') }}
                                </h3>
                            </div>
                            <button
                                type="button"
                                :disabled="mutatingFlagId === selectedFlag.id"
                                class="erin-focus rounded-lg px-3 py-2 text-xs font-bold"
                                :class="
                                    selectedFlag.enabled
                                        ? 'bg-slate-100 text-slate-700'
                                        : 'bg-emerald-50 text-emerald-700'
                                "
                                @click="toggleFlag(selectedFlag)"
                            >
                                {{
                                    selectedFlag.enabled
                                        ? t('system.flags.deactivate')
                                        : t('system.flags.activate')
                                }}
                            </button>
                        </div>

                        <label class="mt-4 block">
                            <span class="text-xs font-bold text-slate-600">
                                {{ t('system.flags.name') }}
                            </span>
                            <input
                                v-model="updateForm.name"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </label>
                        <label class="mt-3 block">
                            <span class="text-xs font-bold text-slate-600">
                                {{ t('system.flags.descriptionLabel') }}
                            </span>
                            <Textarea
                                v-model="updateForm.description"
                                rows="3"
                                class="mt-1.5"
                            />
                        </label>
                        <label class="mt-3 block">
                            <span class="text-xs font-bold text-slate-600">
                                {{ t('system.flags.rolloutPercentage') }}
                            </span>
                            <input
                                v-model="updateForm.rollout_percentage"
                                type="number"
                                min="0"
                                max="100"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </label>
                        <label class="mt-3 block">
                            <span class="text-xs font-bold text-slate-600">
                                {{ t('system.flags.conditions') }}
                            </span>
                            <Textarea
                                v-model="updateConditionsText"
                                rows="6"
                                class="mt-1.5 font-mono text-xs"
                            />
                        </label>
                        <p
                            v-if="updateJsonError || firstUpdateError"
                            class="mt-2 text-xs text-red-600"
                        >
                            {{ updateJsonError || firstUpdateError }}
                        </p>
                        <div class="mt-4 flex justify-between gap-3">
                            <button
                                type="button"
                                class="erin-focus inline-flex h-10 items-center gap-2 rounded-xl border border-red-200 px-3 text-xs font-bold text-red-700"
                                @click="deleteFlag(selectedFlag)"
                            >
                                <Trash2 class="size-4" />
                                {{ t('common.delete') }}
                            </button>
                            <button
                                type="submit"
                                :disabled="updateForm.processing"
                                class="erin-focus h-10 rounded-xl bg-blue-600 px-4 text-xs font-bold text-white disabled:opacity-50"
                            >
                                {{ t('system.flags.saveChanges') }}
                            </button>
                        </div>
                        <p class="mt-4 text-[11px] text-slate-600">
                            {{
                                selectedFlag.updater
                                    ? t('system.flags.lastChangedBy', {
                                          date: formatDate(
                                              selectedFlag.updated_at,
                                          ),
                                          name: selectedFlag.updater.name,
                                      })
                                    : t('system.flags.lastChanged', {
                                          date: formatDate(
                                              selectedFlag.updated_at,
                                          ),
                                      })
                            }}
                        </p>
                    </form>

                    <form
                        class="rounded-2xl border border-slate-200 p-5"
                        @submit.prevent="createFlag"
                    >
                        <div class="flex items-center gap-2">
                            <Plus class="size-4 text-teal-600" />
                            <h3 class="font-bold text-slate-950">
                                {{ t('system.flags.createTitle') }}
                            </h3>
                        </div>
                        <label class="mt-4 block">
                            <span class="text-xs font-bold text-slate-600">
                                {{ t('system.flags.technicalKey') }}
                            </span>
                            <input
                                v-model="createForm.key"
                                placeholder="bereich.funktion"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 font-mono text-xs"
                            />
                        </label>
                        <label class="mt-3 block">
                            <span class="text-xs font-bold text-slate-600">
                                {{ t('system.flags.name') }}
                            </span>
                            <input
                                v-model="createForm.name"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </label>
                        <label class="mt-3 block">
                            <span class="text-xs font-bold text-slate-600">
                                {{ t('system.flags.descriptionLabel') }}
                            </span>
                            <Textarea
                                v-model="createForm.description"
                                rows="3"
                                class="mt-1.5"
                            />
                        </label>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <label>
                                <span class="text-xs font-bold text-slate-600">
                                    {{ t('system.flags.rolloutPercentage') }}
                                </span>
                                <input
                                    v-model="createForm.rollout_percentage"
                                    type="number"
                                    min="0"
                                    max="100"
                                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                                />
                            </label>
                            <label
                                class="flex items-end gap-2 pb-2 text-sm font-semibold text-slate-700"
                            >
                                <input
                                    v-model="createForm.enabled"
                                    type="checkbox"
                                    class="size-4 rounded border-slate-300 text-blue-600"
                                />
                                {{ t('system.flags.activeImmediately') }}
                            </label>
                        </div>
                        <label class="mt-3 block">
                            <span class="text-xs font-bold text-slate-600">
                                {{ t('system.flags.conditions') }}
                            </span>
                            <Textarea
                                v-model="createConditionsText"
                                rows="5"
                                class="mt-1.5 font-mono text-xs"
                            />
                        </label>
                        <p
                            v-if="createJsonError || firstCreateError"
                            class="mt-2 text-xs text-red-600"
                        >
                            {{ createJsonError || firstCreateError }}
                        </p>
                        <button
                            type="submit"
                            :disabled="createForm.processing"
                            class="erin-focus mt-4 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl bg-teal-600 text-xs font-bold text-white disabled:opacity-50"
                        >
                            <Flag class="size-4" />
                            {{ t('system.flags.create') }}
                        </button>
                    </form>
                </div>
                <div v-else class="p-5 sm:p-6">
                    <div
                        v-if="selectedFlag"
                        class="rounded-2xl border border-slate-200 p-5"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-mono text-xs text-blue-600">
                                    {{ selectedFlag.key }}
                                </p>
                                <h3 class="mt-1 font-bold text-slate-950">
                                    {{ selectedFlag.name }}
                                </h3>
                            </div>
                            <StatusBadge
                                :label="
                                    selectedFlag.enabled
                                        ? t('common.active')
                                        : t('common.inactive')
                                "
                                :tone="selectedFlag.enabled ? 'green' : 'slate'"
                            />
                        </div>
                        <p class="mt-4 text-sm text-slate-600">
                            {{
                                selectedFlag.description ??
                                t('system.flags.noDescription')
                            }}
                        </p>
                        <dl class="mt-4 grid gap-3 text-xs sm:grid-cols-2">
                            <div class="rounded-xl bg-slate-50 p-3">
                                <dt class="font-bold text-slate-600">
                                    {{ t('system.flags.rolloutPercentage') }}
                                </dt>
                                <dd class="mt-1 text-slate-700">
                                    {{ selectedFlag.rollout_percentage }} %
                                </dd>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <dt class="font-bold text-slate-600">
                                    {{ t('common.updated') }}
                                </dt>
                                <dd class="mt-1 text-slate-700">
                                    {{ formatDate(selectedFlag.updated_at) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </SectionCard>

        <SystemLoginHistory :login-history="login_history" />
    </div>
</template>
