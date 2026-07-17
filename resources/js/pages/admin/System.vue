<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Activity,
    Database,
    FileLock2,
    Flag,
    KeyRound,
    ListChecks,
    Mail,
    PencilLine,
    Plus,
    Save,
    Server,
    ShieldCheck,
    Trash2,
    Webhook,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import adminAccessList from '@/routes/admin/access-list';
import adminEmailTemplates from '@/routes/admin/email-templates';
import adminFeatureFlags from '@/routes/admin/feature-flags';
import adminGdprRequests from '@/routes/admin/gdpr-requests';
import AdminEmptyState from './_components/AdminEmptyState.vue';
import AdminPagination from './_components/AdminPagination.vue';
import type { AdminPaginator } from './_shared';
import { formatDate, humanize, statusTone } from './_shared';

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
    type: 'export' | 'delete';
    status: string;
    reason: string | null;
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
});

const gdprUpdateForm = useForm({
    type: 'export' as 'export' | 'delete',
    status: 'requested',
    reason: '',
    due_at: '',
});

const firstGdprCreateError = computed(
    () => Object.values(gdprCreateForm.errors)[0] as string | undefined,
);
const firstGdprUpdateError = computed(
    () => Object.values(gdprUpdateForm.errors)[0] as string | undefined,
);

watch(
    selectedGdpr,
    (request) => {
        gdprUpdateForm.clearErrors();

        if (!request) {
            return;
        }

        gdprUpdateForm.type = request.type;
        gdprUpdateForm.status = request.status;
        gdprUpdateForm.reason = request.reason ?? '';
        gdprUpdateForm.due_at = toLocalDateTime(request.due_at);
    },
    { immediate: true },
);

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
    if (!window.confirm(`Eintrag „${entry.value}“ wirklich löschen?`)) {
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
            `E-Mail-Template „${template.key}“ in DE und EN wirklich löschen?`,
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
        createJsonError.value =
            'Bedingungen müssen ein gültiges JSON-Objekt oder -Array sein.';

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
        updateJsonError.value =
            'Bedingungen müssen ein gültiges JSON-Objekt oder -Array sein.';

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
    if (!window.confirm(`Feature Flag „${flag.key}“ wirklich löschen?`)) {
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
    <Head title="System" />

    <div class="erin-page">
        <PageHeader
            eyebrow="Platform Operations"
            title="System & Governance"
            description="Reale Laufzeitkonfiguration, Governance-Daten, Feature Flags und Login-Historie."
            :icon="Activity"
        />

        <div
            v-if="!isSuperAdmin"
            class="flex items-start gap-3 rounded-2xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900"
        >
            <ShieldCheck class="mt-0.5 size-4 shrink-0" />
            <p>
                Support-Leseansicht: Governance-Daten sind sichtbar, Änderungen
                bleiben ausschließlich Superadmins vorbehalten.
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard
                label="Fehlgeschlagene Jobs"
                :value="runtime.failed_jobs"
                :hint="`Queue: ${runtime.queue_connection}`"
                :icon="Database"
                tone="orange"
            />
            <MetricCard
                label="Offene DSGVO-Anfragen"
                :value="gdpr.open"
                :hint="`${gdpr.overdue} überfällig`"
                :icon="ShieldCheck"
                tone="teal"
            />
            <MetricCard
                label="Zugriffslisteneinträge"
                :value="governance.access_list_entries"
                :icon="KeyRound"
            />
            <MetricCard
                label="E-Mail-Templates"
                :value="governance.email_templates"
                :icon="Mail"
                tone="violet"
            />
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <SectionCard
                title="Laufzeit"
                description="Direkt aus der aktiven Laravel-Instanz."
            >
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl bg-slate-50 p-4">
                        <dt
                            class="text-[10px] font-bold text-slate-400 uppercase"
                        >
                            Laravel
                        </dt>
                        <dd
                            class="mt-1 font-mono text-sm font-bold text-slate-800"
                        >
                            {{ runtime.laravel }}
                        </dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <dt
                            class="text-[10px] font-bold text-slate-400 uppercase"
                        >
                            PHP
                        </dt>
                        <dd
                            class="mt-1 font-mono text-sm font-bold text-slate-800"
                        >
                            {{ runtime.php }}
                        </dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <dt
                            class="text-[10px] font-bold text-slate-400 uppercase"
                        >
                            Umgebung
                        </dt>
                        <dd class="mt-1 text-sm font-bold text-slate-800">
                            {{ runtime.environment }}
                        </dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <dt
                            class="text-[10px] font-bold text-slate-400 uppercase"
                        >
                            Debug
                        </dt>
                        <dd class="mt-2">
                            <StatusBadge
                                :label="runtime.debug ? 'Aktiv' : 'Inaktiv'"
                                :tone="runtime.debug ? 'yellow' : 'green'"
                            />
                        </dd>
                    </div>
                </dl>
            </SectionCard>

            <SectionCard
                title="Integrationen"
                description="Konfigurationsstatus; keine erfundene Verfügbarkeitsmessung."
            >
                <div class="grid gap-3 sm:grid-cols-2">
                    <div
                        v-for="integration in [
                            { name: 'Stripe', configured: integrations.stripe },
                            { name: 'OpenAI', configured: integrations.openai },
                            {
                                name: 'LiveKit',
                                configured: integrations.livekit,
                            },
                        ]"
                        :key="integration.name"
                        class="flex items-center justify-between rounded-xl border border-slate-200 p-4"
                    >
                        <div class="flex items-center gap-3">
                            <span
                                class="grid size-9 place-items-center rounded-xl bg-blue-50 text-blue-600"
                            >
                                <Server class="size-4" />
                            </span>
                            <span class="text-sm font-bold text-slate-800">
                                {{ integration.name }}
                            </span>
                        </div>
                        <StatusBadge
                            :label="
                                integration.configured
                                    ? 'Konfiguriert'
                                    : 'Nicht konfiguriert'
                            "
                            :tone="integration.configured ? 'green' : 'slate'"
                        />
                    </div>
                    <div
                        class="flex items-center justify-between rounded-xl border border-slate-200 p-4"
                    >
                        <div class="flex items-center gap-3">
                            <span
                                class="grid size-9 place-items-center rounded-xl bg-orange-50 text-orange-600"
                            >
                                <Webhook class="size-4" />
                            </span>
                            <span class="text-sm font-bold text-slate-800">
                                Webhook-Fehler / 24 h
                            </span>
                        </div>
                        <StatusBadge
                            :label="
                                integrations.recent_failed_webhooks.toString()
                            "
                            :tone="
                                integrations.recent_failed_webhooks > 0
                                    ? 'red'
                                    : 'green'
                            "
                        />
                    </div>
                </div>
            </SectionCard>
        </div>

        <SectionCard
            title="DSGVO-Anfragen"
            description="Export- und Löschanfragen mit Verantwortlichkeit, Frist und nachvollziehbarem Bearbeitungsstatus."
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
                                <span
                                    >Frist
                                    {{ formatDate(request.due_at) }}</span
                                >
                            </div>
                        </button>
                    </div>
                    <AdminEmptyState
                        v-else
                        title="Keine DSGVO-Anfragen"
                        description="Derzeit liegen keine Export- oder Löschanfragen vor."
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
                                    Anfrage #{{ selectedGdpr.id }}
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
                                <dt class="font-bold text-slate-400">Typ</dt>
                                <dd class="mt-1 text-slate-700">
                                    {{ humanize(selectedGdpr.type) }}
                                </dd>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <dt class="font-bold text-slate-400">Frist</dt>
                                <dd class="mt-1 text-slate-700">
                                    {{ formatDate(selectedGdpr.due_at) }}
                                </dd>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <dt class="font-bold text-slate-400">
                                    Bearbeitung
                                </dt>
                                <dd class="mt-1 text-slate-700">
                                    {{
                                        selectedGdpr.handler?.name ??
                                        'Nicht zugewiesen'
                                    }}
                                </dd>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <dt class="font-bold text-slate-400">
                                    Angefragt
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
                                    Anfrage bearbeiten
                                </h3>
                            </div>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                <label>
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        Typ
                                    </span>
                                    <select
                                        v-model="gdprUpdateForm.type"
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
                                        Status
                                    </span>
                                    <select
                                        v-model="gdprUpdateForm.status"
                                        class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                    >
                                        <option
                                            v-for="status in gdpr_statuses"
                                            :key="status"
                                            :value="status"
                                        >
                                            {{ humanize(status) }}
                                        </option>
                                    </select>
                                </label>
                            </div>
                            <label class="mt-3 block">
                                <span class="text-xs font-bold text-slate-600">
                                    Frist
                                </span>
                                <input
                                    v-model="gdprUpdateForm.due_at"
                                    type="datetime-local"
                                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                                />
                            </label>
                            <label class="mt-3 block">
                                <span class="text-xs font-bold text-slate-600">
                                    Grund / Bearbeitungsnotiz
                                </span>
                                <textarea
                                    v-model="gdprUpdateForm.reason"
                                    rows="4"
                                    class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3 text-sm"
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
                                :disabled="gdprUpdateForm.processing"
                                class="erin-focus mt-4 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-xs font-bold text-white disabled:opacity-50"
                            >
                                <Save class="size-4" />
                                Status speichern
                            </button>
                        </form>

                        <form
                            class="rounded-2xl border border-slate-200 p-5"
                            @submit.prevent="createGdprRequest"
                        >
                            <div class="flex items-center gap-2">
                                <Plus class="size-4 text-teal-600" />
                                <h3 class="font-bold text-slate-950">
                                    Anfrage anlegen
                                </h3>
                            </div>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                <label>
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        Nutzer-ID
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
                                        Typ
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
                                    Frist
                                </span>
                                <input
                                    v-model="gdprCreateForm.due_at"
                                    type="datetime-local"
                                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                                />
                            </label>
                            <label class="mt-3 block">
                                <span class="text-xs font-bold text-slate-600">
                                    Grund
                                </span>
                                <textarea
                                    v-model="gdprCreateForm.reason"
                                    rows="4"
                                    class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3 text-sm"
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
                                Anfrage speichern
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </SectionCard>

        <SectionCard
            title="Blacklist & Whitelist"
            description="Zeitlich begrenzbare Regeln für konkrete E-Mail-Adressen, Domains und IP-Adressen."
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
                        Neuer Listeneintrag
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
                    <AdminEmptyState
                        v-else
                        title="Keine Zugriffsregeln"
                        description="Blacklist und Whitelist sind leer."
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
                                                ? 'Listeneintrag bearbeiten'
                                                : 'Listeneintrag anlegen'
                                        }}
                                    </h3>
                                    <p
                                        v-if="selectedAccessEntry"
                                        class="mt-0.5 text-xs text-slate-400"
                                    >
                                        Erstellt von
                                        {{
                                            selectedAccessEntry.creator?.name ??
                                            'System'
                                        }}
                                        am
                                        {{
                                            formatDate(
                                                selectedAccessEntry.created_at,
                                            )
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
                                Löschen
                            </button>
                        </div>
                        <div
                            class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                        >
                            <label>
                                <span class="text-xs font-bold text-slate-600">
                                    Liste
                                </span>
                                <select
                                    v-model="accessForm.list_type"
                                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                >
                                    <option value="blacklist">Blacklist</option>
                                    <option value="whitelist">Whitelist</option>
                                </select>
                            </label>
                            <label>
                                <span class="text-xs font-bold text-slate-600">
                                    Zieltyp
                                </span>
                                <select
                                    v-model="accessForm.subject_type"
                                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                >
                                    <option value="email">E-Mail</option>
                                    <option value="domain">Domain</option>
                                    <option value="ip">IP-Adresse</option>
                                </select>
                            </label>
                            <label>
                                <span class="text-xs font-bold text-slate-600">
                                    Wert
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
                                    Ablauf
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
                                Begründung
                            </span>
                            <textarea
                                v-model="accessForm.reason"
                                rows="3"
                                class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3 text-sm"
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
                                    ? 'Änderungen speichern'
                                    : 'Eintrag anlegen'
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
                        <p class="mt-4 text-xs text-slate-400">
                            Ablauf:
                            {{ formatDate(selectedAccessEntry.expires_at) }} ·
                            Erstellt von
                            {{ selectedAccessEntry.creator?.name ?? 'System' }}
                        </p>
                    </div>
                </div>
            </div>
        </SectionCard>

        <SectionCard
            title="E-Mail-Templates"
            description="Zweisprachige Systemvorlagen; Speichern aktualisiert Deutsch und Englisch atomar."
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
                        Neues E-Mail-Template
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
                                        template.is_active ? 'Aktiv' : 'Inaktiv'
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
                                    'Kein Betreff'
                                }}
                            </p>
                        </button>
                    </div>
                    <AdminEmptyState
                        v-else
                        title="Keine E-Mail-Templates"
                        description="Es wurden noch keine Vorlagen angelegt."
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
                                                ? 'Template bearbeiten'
                                                : 'Template anlegen'
                                        }}
                                    </h3>
                                    <p
                                        v-if="selectedTemplate"
                                        class="mt-0.5 text-xs text-slate-400"
                                    >
                                        Geändert
                                        {{
                                            formatDate(
                                                selectedTemplate.updated_at,
                                            )
                                        }}
                                        <template
                                            v-if="selectedTemplate.updater"
                                        >
                                            von
                                            {{ selectedTemplate.updater.name }}
                                        </template>
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
                                DE & EN löschen
                            </button>
                        </div>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <label>
                                <span class="text-xs font-bold text-slate-600">
                                    Technischer Schlüssel
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
                                Template aktiv
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
                                        locale === 'de' ? 'Deutsch' : 'Englisch'
                                    }}
                                </legend>
                                <label class="block">
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        Betreff
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
                                        HTML-Inhalt
                                    </span>
                                    <textarea
                                        v-model="
                                            templateForm.translations[locale]
                                                .body_html
                                        "
                                        rows="8"
                                        class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3 font-mono text-xs"
                                    />
                                </label>
                                <label class="mt-3 block">
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        Text-Alternative
                                    </span>
                                    <textarea
                                        v-model="
                                            templateForm.translations[locale]
                                                .body_text
                                        "
                                        rows="5"
                                        class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3 text-sm"
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
                            DE & EN speichern
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
                                    'Keine Übersetzung'
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
            title="Feature Flags"
            description="Anlegen, schalten, staffeln und löschen über die vorhandenen Admin-Actions."
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
                                        class="mt-1 truncate font-mono text-[11px] text-slate-400"
                                    >
                                        {{ flag.key }}
                                    </p>
                                </div>
                                <StatusBadge
                                    :label="flag.enabled ? 'Aktiv' : 'Inaktiv'"
                                    :tone="flag.enabled ? 'green' : 'slate'"
                                />
                            </div>
                            <p class="mt-3 text-xs text-slate-500">
                                Rollout {{ flag.rollout_percentage }} %
                            </p>
                        </button>
                    </div>
                    <AdminEmptyState
                        v-else
                        title="Keine Feature Flags"
                        description="Lege rechts das erste Flag an."
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
                                    Flag bearbeiten
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
                                        ? 'Deaktivieren'
                                        : 'Aktivieren'
                                }}
                            </button>
                        </div>

                        <label class="mt-4 block">
                            <span class="text-xs font-bold text-slate-600"
                                >Name</span
                            >
                            <input
                                v-model="updateForm.name"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </label>
                        <label class="mt-3 block">
                            <span class="text-xs font-bold text-slate-600">
                                Beschreibung
                            </span>
                            <textarea
                                v-model="updateForm.description"
                                rows="3"
                                class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3 text-sm"
                            />
                        </label>
                        <label class="mt-3 block">
                            <span class="text-xs font-bold text-slate-600">
                                Rollout in Prozent
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
                                Bedingungen als JSON
                            </span>
                            <textarea
                                v-model="updateConditionsText"
                                rows="6"
                                class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3 font-mono text-xs"
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
                                Löschen
                            </button>
                            <button
                                type="submit"
                                :disabled="updateForm.processing"
                                class="erin-focus h-10 rounded-xl bg-blue-600 px-4 text-xs font-bold text-white disabled:opacity-50"
                            >
                                Änderungen speichern
                            </button>
                        </div>
                        <p class="mt-4 text-[11px] text-slate-400">
                            Zuletzt geändert
                            {{ formatDate(selectedFlag.updated_at) }}
                            <template v-if="selectedFlag.updater">
                                von {{ selectedFlag.updater.name }}
                            </template>
                        </p>
                    </form>

                    <form
                        class="rounded-2xl border border-slate-200 p-5"
                        @submit.prevent="createFlag"
                    >
                        <div class="flex items-center gap-2">
                            <Plus class="size-4 text-teal-600" />
                            <h3 class="font-bold text-slate-950">
                                Neues Feature Flag
                            </h3>
                        </div>
                        <label class="mt-4 block">
                            <span class="text-xs font-bold text-slate-600">
                                Technischer Schlüssel
                            </span>
                            <input
                                v-model="createForm.key"
                                placeholder="bereich.funktion"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 font-mono text-xs"
                            />
                        </label>
                        <label class="mt-3 block">
                            <span class="text-xs font-bold text-slate-600"
                                >Name</span
                            >
                            <input
                                v-model="createForm.name"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </label>
                        <label class="mt-3 block">
                            <span class="text-xs font-bold text-slate-600">
                                Beschreibung
                            </span>
                            <textarea
                                v-model="createForm.description"
                                rows="3"
                                class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3 text-sm"
                            />
                        </label>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <label>
                                <span class="text-xs font-bold text-slate-600">
                                    Rollout %
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
                                Sofort aktiv
                            </label>
                        </div>
                        <label class="mt-3 block">
                            <span class="text-xs font-bold text-slate-600">
                                Bedingungen als JSON
                            </span>
                            <textarea
                                v-model="createConditionsText"
                                rows="5"
                                class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3 font-mono text-xs"
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
                            Feature Flag anlegen
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
                                    selectedFlag.enabled ? 'Aktiv' : 'Inaktiv'
                                "
                                :tone="selectedFlag.enabled ? 'green' : 'slate'"
                            />
                        </div>
                        <p class="mt-4 text-sm text-slate-600">
                            {{
                                selectedFlag.description ??
                                'Keine Beschreibung hinterlegt.'
                            }}
                        </p>
                        <dl class="mt-4 grid gap-3 text-xs sm:grid-cols-2">
                            <div class="rounded-xl bg-slate-50 p-3">
                                <dt class="font-bold text-slate-400">
                                    Rollout
                                </dt>
                                <dd class="mt-1 text-slate-700">
                                    {{ selectedFlag.rollout_percentage }} %
                                </dd>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <dt class="font-bold text-slate-400">
                                    Zuletzt geändert
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

        <SectionCard
            title="Login-Historie"
            description="Die letzten vom Backend gelieferten Anmeldeereignisse."
            flush
        >
            <div v-if="login_history.length > 0" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left">
                    <thead class="bg-slate-50/80">
                        <tr
                            class="text-[11px] font-bold tracking-wide text-slate-500 uppercase"
                        >
                            <th class="px-5 py-3">Konto</th>
                            <th class="px-5 py-3">Ereignis</th>
                            <th class="px-5 py-3">Ergebnis</th>
                            <th class="px-5 py-3">Netzwerk</th>
                            <th class="px-5 py-3 text-right">Zeitpunkt</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr
                            v-for="entry in login_history"
                            :key="entry.id"
                            class="align-top"
                        >
                            <td class="px-5 py-4">
                                <p class="text-sm font-semibold text-slate-800">
                                    {{ entry.user?.name ?? entry.email }}
                                </p>
                                <p
                                    v-if="entry.user"
                                    class="mt-0.5 text-xs text-slate-500"
                                >
                                    {{ entry.email }}
                                </p>
                            </td>
                            <td class="px-5 py-4 text-xs text-slate-600">
                                {{ humanize(entry.event) }}
                            </td>
                            <td class="px-5 py-4">
                                <StatusBadge
                                    :label="
                                        entry.successful
                                            ? 'Erfolgreich'
                                            : (entry.failure_reason ??
                                              'Fehlgeschlagen')
                                    "
                                    :tone="entry.successful ? 'green' : 'red'"
                                />
                            </td>
                            <td class="px-5 py-4 text-xs text-slate-600">
                                <p>{{ entry.ip_address ?? '—' }}</p>
                                <p
                                    v-if="entry.user_agent"
                                    class="mt-1 max-w-64 truncate text-slate-400"
                                    :title="entry.user_agent"
                                >
                                    {{ entry.user_agent }}
                                </p>
                            </td>
                            <td
                                class="px-5 py-4 text-right text-xs whitespace-nowrap text-slate-500"
                            >
                                {{ formatDate(entry.created_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <AdminEmptyState
                v-else
                title="Keine Login-Ereignisse"
                description="Die Login-Historie enthält derzeit keine Einträge."
            />
        </SectionCard>
    </div>
</template>
