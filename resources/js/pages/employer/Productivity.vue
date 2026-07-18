<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import {
    Activity,
    BellRing,
    Check,
    Clock3,
    Download,
    FileSpreadsheet,
    RotateCcw,
    Trash2,
    Upload,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/product/EmptyState.vue';
import FormField from '@/components/product/FormField.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import Textarea from '@/components/product/Textarea.vue';
import type { StatusTone } from '@/types';

type Reminder = {
    id: number;
    title: string;
    note?: string | null;
    priority: string;
    due_at: string;
    completed_at?: string | null;
    assignee: { id: number; name: string };
    candidate_profile?: {
        current_position?: string | null;
        desired_position?: string | null;
        current_country_code?: string | null;
    } | null;
    job_posting?: { id: number; title: string } | null;
};

type CandidateImport = {
    id: number;
    original_filename: string;
    status: string;
    total_rows: number;
    imported_rows: number;
    failed_rows: number;
    mapping?: {
        headers?: string[];
        preview?: Array<Record<string, string | null>>;
        selection?: Record<string, string | null>;
    };
    rows?: Array<{
        id: number;
        row_number: number;
        email?: string | null;
        errors?: Record<string, string[]>;
    }>;
    created_at: string;
};

type ActivityEntry = {
    id: number;
    event: string;
    actor?: { id: number; name: string } | null;
    payload?: Record<string, string | number | null>;
    occurred_at: string;
};

const props = withDefaults(
    defineProps<{
        company_id: number;
        reminders?: Reminder[];
        members?: Array<{ id: number; name: string; role: string }>;
        jobs?: Array<{ id: number; title: string }>;
        imports?: CandidateImport[];
        activity?: ActivityEntry[];
        import_fields?: string[];
    }>(),
    {
        reminders: () => [],
        members: () => [],
        jobs: () => [],
        imports: () => [],
        activity: () => [],
        import_fields: () => [],
    },
);

const { locale, t, te } = useI18n();
const activityItems = ref<ActivityEntry[]>([...props.activity]);
const reminderForm = useForm({
    title: '',
    note: '',
    due_at: '',
    priority: 'normal',
    assignee_id: props.members[0]?.id ?? null,
    job_posting_id: null as number | null,
});
const uploadForm = useForm<{ file: File | null }>({ file: null });
const pendingImport = computed(() =>
    props.imports.find((item) => item.status === 'awaiting_mapping'),
);
const mappingForm = useForm({
    mapping: Object.fromEntries(
        props.import_fields.map((field) => [
            field,
            pendingImport.value?.mapping?.selection?.[field] ?? '',
        ]),
    ) as Record<string, string>,
});

watch(
    pendingImport,
    (candidateImport) => {
        for (const field of props.import_fields) {
            mappingForm.mapping[field] =
                candidateImport?.mapping?.selection?.[field] ?? '';
        }
    },
    { deep: true },
);

useEcho<{ entry: ActivityEntry }>(
    `company.${props.company_id}`,
    '.activity.created',
    ({ entry }) => {
        if (!activityItems.value.some((item) => item.id === entry.id)) {
            activityItems.value.unshift(entry);
            activityItems.value = activityItems.value.slice(0, 40);
        }
    },
);

const openReminders = computed(() =>
    props.reminders.filter((reminder) => !reminder.completed_at),
);
const completedReminders = computed(() =>
    props.reminders.filter((reminder) => reminder.completed_at),
);

const addReminder = () => {
    reminderForm.post('/employer/reminders', {
        preserveScroll: true,
        onSuccess: () => reminderForm.reset('title', 'note', 'due_at'),
    });
};

const toggleReminder = (reminder: Reminder, completed: boolean) => {
    router.patch(
        `/employer/reminders/${reminder.id}`,
        { completed },
        { preserveScroll: true },
    );
};

const deleteReminder = (reminder: Reminder) => {
    router.delete(`/employer/reminders/${reminder.id}`, {
        preserveScroll: true,
    });
};

const upload = () => {
    uploadForm.post('/employer/candidate-imports', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => uploadForm.reset(),
    });
};

const startImport = () => {
    if (!pendingImport.value) {
        return;
    }

    mappingForm.patch(
        `/employer/candidate-imports/${pendingImport.value.id}/mapping`,
        { preserveScroll: true },
    );
};

const mappingError = (field: string): string | undefined =>
    (mappingForm.errors as Record<string, string | undefined>)[
        `mapping.${field}`
    ];
const rowErrorText = (row: NonNullable<CandidateImport['rows']>[number]) =>
    Object.values(row.errors ?? {})
        .flat()
        .join(' ');

const formatDate = (value: string) =>
    new Intl.DateTimeFormat(locale.value, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));

const reminderTone = (reminder: Reminder): StatusTone =>
    reminder.completed_at
        ? 'green'
        : new Date(reminder.due_at) < new Date()
          ? 'red'
          : reminder.priority === 'high'
            ? 'orange'
            : 'blue';

const activityLabel = (entry: ActivityEntry) => {
    const key = `operations.productivity.activities.${entry.event}`;
    const payload = entry.payload ?? {};

    return t(te(key) ? key : 'operations.productivity.activities.fallback', {
        actor: entry.actor?.name ?? 'Erin',
        title: payload.title ?? '',
        candidate: payload.candidate_label ?? '',
        job: payload.job_title ?? '',
        filename: payload.filename ?? '',
        ticket: payload.ticket_number ?? '',
    });
};
</script>

<template>
    <Head :title="t('operations.productivity.title')" />

    <div class="erin-page">
        <PageHeader
            :eyebrow="t('operations.productivity.eyebrow')"
            :title="t('operations.productivity.title')"
            :description="t('operations.productivity.description')"
            :icon="BellRing"
        />

        <div
            class="grid gap-5 2xl:grid-cols-[minmax(0,1.15fr)_minmax(24rem,.85fr)]"
        >
            <div class="space-y-5">
                <SectionCard
                    :title="t('operations.productivity.reminders')"
                    :description="
                        t('operations.productivity.noRemindersDescription')
                    "
                >
                    <form
                        class="grid gap-4 md:grid-cols-2"
                        @submit.prevent="addReminder"
                    >
                        <FormField
                            id="reminder-title"
                            required
                            :label="t('operations.productivity.reminderTitle')"
                            :error="reminderForm.errors.title"
                        >
                            <input
                                id="reminder-title"
                                v-model="reminderForm.title"
                                required
                                maxlength="180"
                                class="erin-focus h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm"
                                :placeholder="
                                    t(
                                        'operations.productivity.reminderTitlePlaceholder',
                                    )
                                "
                            />
                        </FormField>
                        <FormField
                            id="reminder-due"
                            required
                            :label="t('operations.productivity.dueAt')"
                            :error="reminderForm.errors.due_at"
                        >
                            <input
                                id="reminder-due"
                                v-model="reminderForm.due_at"
                                type="datetime-local"
                                required
                                class="erin-focus h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm"
                            />
                        </FormField>
                        <FormField
                            id="reminder-assignee"
                            :label="t('operations.productivity.assignee')"
                            :error="reminderForm.errors.assignee_id"
                        >
                            <select
                                id="reminder-assignee"
                                v-model="reminderForm.assignee_id"
                                class="erin-focus h-11 w-full rounded-xl border border-slate-200 bg-white px-3.5 text-sm"
                            >
                                <option
                                    v-for="member in members"
                                    :key="member.id"
                                    :value="member.id"
                                >
                                    {{ member.name }}
                                </option>
                            </select>
                        </FormField>
                        <FormField
                            id="reminder-job"
                            :label="t('operations.analytics.job')"
                            :error="reminderForm.errors.job_posting_id"
                        >
                            <select
                                id="reminder-job"
                                v-model="reminderForm.job_posting_id"
                                class="erin-focus h-11 w-full rounded-xl border border-slate-200 bg-white px-3.5 text-sm"
                            >
                                <option :value="null">—</option>
                                <option
                                    v-for="job in jobs"
                                    :key="job.id"
                                    :value="job.id"
                                >
                                    {{ job.title }}
                                </option>
                            </select>
                        </FormField>
                        <div class="md:col-span-2">
                            <FormField
                                id="reminder-note"
                                :label="
                                    t('operations.productivity.reminderNote')
                                "
                                :error="reminderForm.errors.note"
                            >
                                <Textarea
                                    id="reminder-note"
                                    v-model="reminderForm.note"
                                    rows="3"
                                />
                            </FormField>
                        </div>
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-end md:col-span-2"
                        >
                            <FormField
                                id="reminder-priority"
                                class="flex-1"
                                :label="t('operations.productivity.priority')"
                            >
                                <select
                                    id="reminder-priority"
                                    v-model="reminderForm.priority"
                                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3.5 text-sm"
                                >
                                    <option
                                        v-for="priority in [
                                            'low',
                                            'normal',
                                            'high',
                                        ]"
                                        :key="priority"
                                        :value="priority"
                                    >
                                        {{
                                            t(
                                                `operations.support.priorities.${priority}`,
                                            )
                                        }}
                                    </option>
                                </select>
                            </FormField>
                            <button
                                type="submit"
                                :disabled="reminderForm.processing"
                                class="erin-focus h-11 rounded-xl bg-blue-600 px-5 text-sm font-bold text-white disabled:opacity-50"
                            >
                                {{ t('operations.productivity.addReminder') }}
                            </button>
                        </div>
                    </form>

                    <div
                        v-if="openReminders.length"
                        class="mt-6 divide-y divide-slate-100 border-t border-slate-100"
                    >
                        <article
                            v-for="reminder in openReminders"
                            :key="reminder.id"
                            class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center"
                        >
                            <span
                                class="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600"
                            >
                                <Clock3 class="size-4" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="font-bold text-slate-900">
                                    {{ reminder.title }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ formatDate(reminder.due_at) }} ·
                                    {{ reminder.assignee.name }}
                                    <template v-if="reminder.job_posting">
                                        · {{ reminder.job_posting.title }}
                                    </template>
                                </p>
                                <p
                                    v-if="reminder.note"
                                    class="mt-1.5 line-clamp-2 text-sm text-slate-600"
                                >
                                    {{ reminder.note }}
                                </p>
                            </div>
                            <StatusBadge
                                :label="
                                    new Date(reminder.due_at) < new Date()
                                        ? t('operations.productivity.overdue')
                                        : t('operations.productivity.due')
                                "
                                :tone="reminderTone(reminder)"
                            />
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    class="erin-focus grid size-9 place-items-center rounded-xl bg-emerald-50 text-emerald-600"
                                    :aria-label="
                                        t('operations.productivity.complete')
                                    "
                                    @click="toggleReminder(reminder, true)"
                                >
                                    <Check class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    class="erin-focus grid size-9 place-items-center rounded-xl bg-red-50 text-red-600"
                                    :aria-label="
                                        t('operations.productivity.delete')
                                    "
                                    @click="deleteReminder(reminder)"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </div>
                        </article>
                    </div>
                    <EmptyState
                        v-else
                        compact
                        :icon="Clock3"
                        :title="t('operations.productivity.noReminders')"
                        :description="
                            t('operations.productivity.noRemindersDescription')
                        "
                    />

                    <details v-if="completedReminders.length" class="mt-2">
                        <summary
                            class="cursor-pointer text-xs font-bold text-slate-500"
                        >
                            {{ completedReminders.length }}
                            {{ t('operations.productivity.complete') }}
                        </summary>
                        <div class="mt-2 space-y-2">
                            <div
                                v-for="reminder in completedReminders"
                                :key="reminder.id"
                                class="flex items-center gap-3 rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-500"
                            >
                                <Check class="size-4 text-emerald-500" />
                                <span class="flex-1 line-through">
                                    {{ reminder.title }}
                                </span>
                                <button
                                    type="button"
                                    :aria-label="
                                        t('operations.productivity.reopen')
                                    "
                                    @click="toggleReminder(reminder, false)"
                                >
                                    <RotateCcw class="size-4" />
                                </button>
                            </div>
                        </div>
                    </details>
                </SectionCard>

                <SectionCard
                    :title="t('operations.productivity.imports')"
                    :description="
                        t('operations.productivity.importDescription')
                    "
                >
                    <form
                        class="flex flex-col gap-3 sm:flex-row sm:items-end"
                        @submit.prevent="upload"
                    >
                        <FormField
                            id="candidate-import"
                            class="flex-1"
                            :label="t('operations.productivity.selectFile')"
                            :error="uploadForm.errors.file"
                        >
                            <input
                                id="candidate-import"
                                type="file"
                                accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                required
                                class="erin-focus block w-full rounded-xl border border-slate-200 p-2 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-2 file:font-bold file:text-blue-700"
                                @change="
                                    uploadForm.file =
                                        ($event.target as HTMLInputElement)
                                            .files?.[0] ?? null
                                "
                            />
                        </FormField>
                        <button
                            type="submit"
                            :disabled="
                                uploadForm.processing || !uploadForm.file
                            "
                            class="erin-focus inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white disabled:opacity-50"
                        >
                            <Upload class="size-4" />
                            {{ t('operations.productivity.upload') }}
                        </button>
                        <a
                            href="/employer/candidate-imports/template.csv"
                            class="erin-focus inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-700"
                        >
                            <Download class="size-4" />
                            {{ t('operations.productivity.template') }}
                        </a>
                    </form>

                    <form
                        v-if="pendingImport"
                        class="mt-6 rounded-2xl border border-blue-100 bg-blue-50/50 p-4"
                        @submit.prevent="startImport"
                    >
                        <h3 class="font-bold text-slate-900">
                            {{ t('operations.productivity.mapping') }} ·
                            {{ pendingImport.original_filename }}
                        </h3>
                        <div
                            v-if="pendingImport.mapping?.preview?.length"
                            class="mt-4 overflow-x-auto rounded-xl border border-blue-100 bg-white"
                        >
                            <table class="min-w-full text-left text-xs">
                                <caption class="sr-only">
                                    {{
                                        t(
                                            'operations.productivity.previewTitle',
                                        )
                                    }}
                                </caption>
                                <thead class="bg-slate-50 text-slate-500">
                                    <tr>
                                        <th
                                            v-for="header in pendingImport
                                                .mapping.headers ?? []"
                                            :key="header"
                                            class="px-3 py-2 font-bold whitespace-nowrap"
                                        >
                                            {{ header }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr
                                        v-for="(row, index) in pendingImport
                                            .mapping.preview"
                                        :key="index"
                                    >
                                        <td
                                            v-for="header in pendingImport
                                                .mapping.headers ?? []"
                                            :key="header"
                                            class="max-w-52 truncate px-3 py-2 text-slate-600"
                                        >
                                            {{ row[header] ?? '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div
                            class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3"
                        >
                            <FormField
                                v-for="field in import_fields"
                                :id="`mapping-${field}`"
                                :key="field"
                                :required="
                                    ['email', 'current_position'].includes(
                                        field,
                                    )
                                "
                                :label="
                                    t(
                                        `operations.productivity.importFields.${field}`,
                                    )
                                "
                                :error="mappingError(field)"
                            >
                                <select
                                    :id="`mapping-${field}`"
                                    v-model="mappingForm.mapping[field]"
                                    class="erin-focus h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                >
                                    <option value="">
                                        {{
                                            t(
                                                'operations.productivity.notMapped',
                                            )
                                        }}
                                    </option>
                                    <option
                                        v-for="header in pendingImport.mapping
                                            ?.headers ?? []"
                                        :key="header"
                                        :value="header"
                                    >
                                        {{ header }}
                                    </option>
                                </select>
                            </FormField>
                        </div>
                        <button
                            type="submit"
                            :disabled="mappingForm.processing"
                            class="erin-focus mt-4 h-10 rounded-xl bg-orange-500 px-4 text-sm font-bold text-white disabled:opacity-50"
                        >
                            {{ t('operations.productivity.startImport') }}
                        </button>
                    </form>

                    <div
                        v-if="imports.length"
                        class="mt-6 divide-y divide-slate-100 border-t border-slate-100"
                    >
                        <article
                            v-for="candidateImport in imports"
                            :key="candidateImport.id"
                            class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center"
                        >
                            <span
                                class="grid size-10 shrink-0 place-items-center rounded-xl bg-teal-50 text-teal-600"
                            >
                                <FileSpreadsheet class="size-4" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-bold text-slate-900">
                                    {{ candidateImport.original_filename }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ formatDate(candidateImport.created_at) }}
                                    <template
                                        v-if="candidateImport.total_rows > 0"
                                    >
                                        ·
                                        {{
                                            t(
                                                'operations.productivity.importRows',
                                                {
                                                    imported:
                                                        candidateImport.imported_rows,
                                                    failed: candidateImport.failed_rows,
                                                },
                                            )
                                        }}
                                    </template>
                                </p>
                                <ul
                                    v-if="candidateImport.rows?.length"
                                    class="mt-2 space-y-1 text-xs text-red-600"
                                >
                                    <li
                                        v-for="row in candidateImport.rows"
                                        :key="row.id"
                                    >
                                        {{
                                            t(
                                                'operations.productivity.errorRow',
                                                {
                                                    row: row.row_number,
                                                    error: rowErrorText(row),
                                                },
                                            )
                                        }}
                                    </li>
                                </ul>
                            </div>
                            <StatusBadge
                                :label="
                                    t(
                                        `operations.productivity.importStatuses.${candidateImport.status}`,
                                    )
                                "
                                :tone="
                                    candidateImport.status === 'completed'
                                        ? 'green'
                                        : candidateImport.status === 'failed'
                                          ? 'red'
                                          : candidateImport.status ===
                                              'completed_with_errors'
                                            ? 'orange'
                                            : 'blue'
                                "
                            />
                        </article>
                    </div>
                </SectionCard>
            </div>

            <SectionCard :title="t('operations.productivity.activity')" flush>
                <div
                    v-if="activityItems.length"
                    class="divide-y divide-slate-100"
                >
                    <article
                        v-for="entry in activityItems"
                        :key="entry.id"
                        class="flex gap-3 px-5 py-4"
                    >
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-500"
                        >
                            <Activity class="size-4" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm leading-6 text-slate-700">
                                {{ activityLabel(entry) }}
                            </p>
                            <time class="mt-1 block text-[11px] text-slate-400">
                                {{ formatDate(entry.occurred_at) }}
                            </time>
                        </div>
                    </article>
                </div>
                <EmptyState
                    v-else
                    compact
                    :icon="Activity"
                    :title="t('operations.productivity.noActivity')"
                    :description="
                        t('operations.productivity.noActivityDescription')
                    "
                />
            </SectionCard>
        </div>
    </div>
</template>
