<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { BriefcaseBusiness, CalendarDays, Inbox, Search } from '@lucide/vue';
import { ref } from 'vue';
import PageHeader from '@/components/product/PageHeader.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { useStatusLabels } from '@/composables/useStatusLabels';
import { withdraw } from '@/routes/candidate/applications';
import { respond } from '@/routes/candidate/invitations';
import type { StatusTone } from '@/types';

type Application = {
    id: number;
    status: string;
    pipeline_stage?: string;
    applied_at?: string;
    updated_at?: string;
    job_posting?: {
        id: number;
        title: string;
        company?: {
            id: number;
            name: string;
            logo_path?: string | null;
        } | null;
    } | null;
    status_history?: Array<{
        id: number;
        from_status?: string | null;
        to_status: string;
        note?: string | null;
        created_at?: string;
    }>;
    interviews?: Array<{
        id: number;
        status: string;
        starts_at?: string | null;
    }>;
    visa_case?: {
        id: number;
        status: string;
        progress?: number;
        steps?: unknown[];
    } | null;
};
type Invitation = {
    id: number;
    message?: string | null;
    expires_at?: string | null;
    job_posting?: {
        id: number;
        title: string;
        company?: { id: number; name: string } | null;
    } | null;
};

withDefaults(
    defineProps<{ applications?: Application[]; invitations?: Invitation[] }>(),
    {
        applications: () => [],
        invitations: () => [],
    },
);
const search = ref('');
const { statusLabel: translatedStatusLabel } = useStatusLabels();
const statusLabel = (status: string) =>
    translatedStatusLabel('application', status);
const tone = (status: string): StatusTone => {
    if (['hired', 'contract_signed', 'accepted'].includes(status)) {
        return 'green';
    }

    if (['rejected', 'withdrawn'].includes(status)) {
        return 'red';
    }

    if (['documents_missing'].includes(status)) {
        return 'orange';
    }

    if (
        [
            'interview_scheduled',
            'interview_completed',
            'final_selection',
        ].includes(status)
    ) {
        return 'violet';
    }

    return 'blue';
};
</script>

<template>
    <Head title="Meine Bewerbungen" />
    <div class="erin-page">
        <PageHeader
            eyebrow="Mein Fortschritt"
            title="Meine Bewerbungen"
            description="Verfolge jeden Schritt und erkenne sofort, was als Nächstes zu tun ist."
            :icon="BriefcaseBusiness"
        />

        <section
            v-if="invitations.length"
            class="erin-panel border-teal-200 p-5"
        >
            <h2 class="font-extrabold">Offene Einladungen</h2>
            <div class="mt-3 grid gap-3 lg:grid-cols-2">
                <article
                    v-for="invitation in invitations"
                    :key="invitation.id"
                    class="rounded-xl bg-teal-50 p-4"
                >
                    <p class="text-sm font-bold">
                        {{
                            invitation.job_posting?.title ?? 'Stelleneinladung'
                        }}
                    </p>
                    <p class="mt-1 text-xs text-teal-700">
                        {{
                            invitation.job_posting?.company?.name ??
                            'Unternehmen'
                        }}
                    </p>
                    <p
                        v-if="invitation.message"
                        class="mt-3 text-xs leading-5 text-slate-600"
                    >
                        {{ invitation.message }}
                    </p>
                    <div class="mt-4 flex gap-2">
                        <button
                            class="h-9 rounded-lg bg-[var(--erin-primary)] px-3 text-xs font-bold text-white"
                            @click="
                                router.post(
                                    respond.url(invitation.id),
                                    { response: 'accepted' },
                                    { preserveScroll: true },
                                )
                            "
                        >
                            Annehmen
                        </button>
                        <button
                            class="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-600"
                            @click="
                                router.post(
                                    respond.url(invitation.id),
                                    { response: 'rejected' },
                                    { preserveScroll: true },
                                )
                            "
                        >
                            Ablehnen
                        </button>
                    </div>
                </article>
            </div>
        </section>

        <div class="erin-panel p-4">
            <div class="relative">
                <Search
                    class="absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
                /><input
                    v-model="search"
                    type="search"
                    placeholder="Bewerbungen durchsuchen …"
                    class="h-10 w-full rounded-xl border border-slate-200 pl-10 text-sm"
                />
            </div>
        </div>

        <div v-if="applications.length" class="space-y-4">
            <article
                v-for="application in applications.filter(
                    (item) =>
                        !search ||
                        [
                            item.job_posting?.title,
                            item.job_posting?.company?.name,
                        ]
                            .join(' ')
                            .toLowerCase()
                            .includes(search.toLowerCase()),
                )"
                :key="application.id"
                class="erin-panel p-5"
            >
                <div class="flex flex-col gap-5 lg:flex-row lg:items-center">
                    <span
                        class="grid size-12 shrink-0 place-items-center rounded-xl bg-blue-50 text-xs font-extrabold text-[var(--erin-primary)]"
                        >{{
                            application.job_posting?.company?.name?.slice(
                                0,
                                2,
                            ) ?? 'ER'
                        }}</span
                    >
                    <div class="min-w-0 flex-1">
                        <h2 class="font-extrabold">
                            {{
                                application.job_posting?.title ??
                                'Stelle nicht mehr verfügbar'
                            }}
                        </h2>
                        <p class="mt-1 text-xs text-slate-500">
                            {{
                                application.job_posting?.company?.name ??
                                'Unternehmen nicht verfügbar'
                            }}
                        </p>
                        <p
                            v-if="application.applied_at"
                            class="mt-2 flex items-center gap-1.5 text-[10px] text-slate-400"
                        >
                            <CalendarDays class="size-3" /> Beworben am
                            {{
                                new Intl.DateTimeFormat('de-DE', {
                                    dateStyle: 'medium',
                                }).format(new Date(application.applied_at))
                            }}
                        </p>
                    </div>
                    <StatusBadge
                        :label="statusLabel(application.status)"
                        :tone="tone(application.status)"
                    />
                    <div
                        v-if="application.visa_case"
                        class="min-w-44 rounded-xl bg-slate-50 p-3"
                    >
                        <p
                            class="text-[9px] font-bold tracking-wider text-slate-400 uppercase"
                        >
                            Visa-Prozess
                        </p>
                        <p class="mt-1 text-xs font-bold text-slate-700">
                            {{ application.visa_case.progress ?? 0 }} % ·
                            {{ application.visa_case.status }}
                        </p>
                    </div>
                    <button
                        v-if="
                            !['hired', 'rejected', 'withdrawn'].includes(
                                application.status,
                            )
                        "
                        class="h-9 rounded-lg border border-red-200 px-3 text-[10px] font-bold text-red-600"
                        @click="
                            router.post(
                                withdraw.url(application.id),
                                {},
                                { preserveScroll: true },
                            )
                        "
                    >
                        Zurückziehen
                    </button>
                </div>
                <div
                    v-if="application.status_history?.length"
                    class="mt-5 flex overflow-x-auto pb-1"
                >
                    <div
                        v-for="(history, index) in application.status_history"
                        :key="history.id"
                        class="flex min-w-32 flex-1 items-center"
                    >
                        <div class="text-center">
                            <span
                                class="mx-auto block size-2.5 rounded-full bg-[var(--erin-primary)]"
                            />
                            <p
                                class="mt-1.5 text-[8px] font-semibold text-slate-500"
                            >
                                {{ statusLabel(history.to_status) }}
                            </p>
                        </div>
                        <div
                            v-if="index < application.status_history.length - 1"
                            class="mb-4 h-px flex-1 bg-blue-300"
                        />
                    </div>
                </div>
            </article>
        </div>
        <div
            v-else
            class="erin-panel grid min-h-80 place-items-center p-8 text-center"
        >
            <div>
                <Inbox class="mx-auto size-9 text-slate-300" />
                <h2 class="mt-4 font-bold">Noch keine Bewerbungen</h2>
                <p class="mt-2 max-w-md text-sm text-slate-500">
                    Passende Stellen findest du im Jobbereich. Sobald du dich
                    bewirbst, erscheint der Status hier.
                </p>
            </div>
        </div>
    </div>
</template>
