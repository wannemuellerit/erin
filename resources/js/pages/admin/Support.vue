<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Eye,
    MessageSquareReply,
    Search,
    Send,
    ShieldAlert,
    Tickets,
    X,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import adminSupport from '@/routes/admin/support';
import AdminEmptyState from './_components/AdminEmptyState.vue';
import AdminPagination from './_components/AdminPagination.vue';
import { cleanFilters, formatDate, humanize, statusTone } from './_shared';
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
}>();

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

const replyForm = useForm({
    body: '',
    is_internal: false,
});

const impersonationForm = useForm({
    reason: '',
});

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
        replyForm.clearErrors();
        impersonationForm.clearErrors();

        if (!ticket) {
            ticketForm.reset();

            return;
        }

        ticketForm.status = ticket.status;
        ticketForm.priority = ticket.priority;
        ticketForm.assigned_to = ticket.assigned_to?.toString() ?? '';
        replyForm.reset();
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

function sendReply(): void {
    if (!selectedTicket.value) {
        return;
    }

    replyForm.post(adminSupport.reply.url(selectedTicket.value.id), {
        preserveScroll: true,
        onSuccess: () => replyForm.reset(),
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
    <Head title="Support" />

    <div class="erin-page">
        <PageHeader
            eyebrow="Customer Operations"
            title="Support"
            :description="`${tickets.total} Tickets mit realer Zuweisung, Priorität und Bearbeitungsstatus.`"
            :icon="Tickets"
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <MetricCard
                label="Offene Moderationsfälle"
                :value="moderation.open_cases"
                hint="plattformweite Prüfung"
                :icon="ShieldAlert"
                tone="orange"
            />
            <MetricCard
                label="Ausstehendes Feedback"
                :value="moderation.pending_feedback"
                hint="noch nicht moderiert"
                :icon="MessageSquareReply"
                tone="violet"
            />
        </div>

        <SectionCard flush>
            <form
                class="grid gap-3 border-b border-slate-100 p-4 lg:grid-cols-[minmax(16rem,1fr)_12rem_11rem_14rem_auto]"
                @submit.prevent="applyFilters"
            >
                <label class="relative">
                    <span class="sr-only">Tickets suchen</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
                    />
                    <input
                        v-model="filters.search"
                        type="search"
                        placeholder="Nummer, Betreff oder Nutzer …"
                        class="erin-focus h-11 w-full rounded-xl border border-slate-200 pr-3 pl-10 text-sm"
                    />
                </label>
                <select
                    v-model="filters.status"
                    aria-label="Ticketstatus"
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
                <select
                    v-model="filters.priority"
                    aria-label="Priorität"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                >
                    <option value="">Alle Prioritäten</option>
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
                    aria-label="Zuständiger Mitarbeiter"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                >
                    <option value="">Alle Zuständigen</option>
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
                                    class="text-[11px] font-bold text-slate-400"
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
                            <p class="mt-2 truncate text-xs text-slate-500">
                                {{ ticket.requester.name }}
                            </p>
                            <div
                                class="mt-2 flex items-center justify-between text-[10px]"
                            >
                                <StatusBadge
                                    :label="humanize(ticket.status)"
                                    :tone="statusTone(ticket.status)"
                                />
                                <span class="text-slate-400">
                                    {{ ticket.messages_count }} Nachrichten
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
                                <span class="text-xs font-bold text-slate-400">
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
                                Erstellt
                                {{ formatDate(selectedTicket.created_at) }}
                            </p>
                        </div>
                        <div class="text-xs text-slate-500">
                            <p>
                                Letzte Antwort:
                                {{ formatDate(selectedTicket.last_reply_at) }}
                            </p>
                            <p class="mt-1">
                                Gelöst:
                                {{ formatDate(selectedTicket.resolved_at) }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="mt-6 grid gap-6 2xl:grid-cols-[minmax(0,1fr)_22rem]"
                    >
                        <div class="space-y-6">
                            <section class="rounded-2xl bg-slate-50 p-5">
                                <h3 class="text-sm font-bold text-slate-900">
                                    Ticketübersicht
                                </h3>
                                <p
                                    class="mt-2 text-sm leading-6 text-slate-600"
                                >
                                    Der Listen-Endpunkt liefert
                                    {{ selectedTicket.messages_count }}
                                    Nachrichten für dieses Ticket. Der
                                    Nachrichtenverlauf selbst ist nicht
                                    Bestandteil dieses Payloads.
                                </p>
                                <dl
                                    class="mt-4 grid gap-3 text-xs sm:grid-cols-2"
                                >
                                    <div>
                                        <dt class="text-slate-400">
                                            Anfragender
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
                                        <dt class="text-slate-400">
                                            Unternehmen
                                        </dt>
                                        <dd
                                            class="mt-1 font-semibold text-slate-800"
                                        >
                                            {{
                                                selectedTicket.company?.name ??
                                                'Kein Unternehmen'
                                            }}
                                        </dd>
                                    </div>
                                </dl>
                            </section>

                            <form
                                class="rounded-2xl border border-slate-200 p-5"
                                @submit.prevent="sendReply"
                            >
                                <h3 class="text-sm font-bold text-slate-900">
                                    Antwort verfassen
                                </h3>
                                <textarea
                                    v-model="replyForm.body"
                                    rows="6"
                                    placeholder="Antwort oder interne Notiz …"
                                    class="erin-focus mt-3 w-full rounded-xl border border-slate-200 p-3 text-sm leading-6"
                                />
                                <p
                                    v-if="replyForm.errors.body"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ replyForm.errors.body }}
                                </p>
                                <div
                                    class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <label
                                        class="flex items-center gap-2 text-xs font-semibold text-slate-600"
                                    >
                                        <input
                                            v-model="replyForm.is_internal"
                                            type="checkbox"
                                            class="size-4 rounded border-slate-300 text-blue-600"
                                        />
                                        Nur interne Notiz
                                    </label>
                                    <button
                                        type="submit"
                                        :disabled="replyForm.processing"
                                        class="erin-focus inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-xs font-bold text-white disabled:opacity-50"
                                    >
                                        <Send class="size-4" />
                                        {{
                                            replyForm.is_internal
                                                ? 'Notiz speichern'
                                                : 'Antwort senden'
                                        }}
                                    </button>
                                </div>
                            </form>
                        </div>

                        <aside class="space-y-5">
                            <form
                                class="rounded-2xl border border-slate-200 p-5"
                                @submit.prevent="updateTicket"
                            >
                                <h3 class="text-sm font-bold text-slate-900">
                                    Bearbeitung
                                </h3>
                                <label class="mt-4 block">
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                        >Status</span
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
                                        Priorität
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
                                        Zuständig
                                    </span>
                                    <select
                                        v-model="ticketForm.assigned_to"
                                        class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                    >
                                        <option value="">
                                            Nicht zugewiesen
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
                                    Ticket aktualisieren
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
                                        Read-only Nutzeransicht
                                    </h3>
                                </div>
                                <p
                                    class="mt-2 text-xs leading-5 text-amber-700"
                                >
                                    Der Zugriff benötigt einen Grund und wird
                                    auditiert.
                                </p>
                                <textarea
                                    v-model="impersonationForm.reason"
                                    rows="4"
                                    placeholder="Grund, mindestens 10 Zeichen …"
                                    class="erin-focus mt-3 w-full rounded-xl border border-amber-200 bg-white p-3 text-xs"
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
                                    class="erin-focus mt-3 h-10 w-full rounded-xl bg-amber-600 text-xs font-bold text-white disabled:opacity-50"
                                >
                                    Ansicht öffnen
                                </button>
                            </form>
                        </aside>
                    </div>
                </main>
            </div>
            <AdminEmptyState
                v-else
                title="Keine Supporttickets"
                description="Für die gewählten Filter wurden keine Tickets gefunden."
            />
            <AdminPagination :paginator="tickets" />
        </SectionCard>
    </div>
</template>
