<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    CalendarDays,
    CheckCircle2,
    Clock3,
    FileDown,
    Inbox,
    Plus,
    Video,
} from '@lucide/vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { useStatusLabels } from '@/composables/useStatusLabels';
import { update as updateAvailability } from '@/routes/availability';
import { respond } from '@/routes/interviews';
import type {
    Availability,
    Interview,
    InterviewProposal,
    StatusTone,
} from '@/types';

const props = withDefaults(
    defineProps<{
        perspective?: 'employer' | 'candidate';
        interviews?: Interview[];
        availability?: Availability[];
        timezone?: string;
    }>(),
    {
        perspective: 'employer',
        interviews: () => [],
        availability: () => [],
        timezone: 'Europe/Berlin',
    },
);
const availabilityForm = useForm({
    slots: props.availability.map((slot) => ({
        weekday: slot.weekday,
        starts_at: slot.starts_at.slice(0, 5),
        ends_at: slot.ends_at.slice(0, 5),
        timezone: slot.timezone,
    })),
});
const statusTone = (status: string): StatusTone =>
    status === 'confirmed'
        ? 'green'
        : status === 'cancelled' || status === 'no_show'
          ? 'red'
          : status === 'counter_proposed'
            ? 'violet'
            : 'yellow';
const { statusLabel: translatedStatusLabel } = useStatusLabels();
const statusLabel = (status: string) =>
    translatedStatusLabel('interview', status);
const person = (interview: Interview) =>
    props.perspective === 'candidate'
        ? (interview.application?.job_posting?.company?.name ?? 'Unternehmen')
        : (interview.application?.candidate_profile?.user?.name ?? 'Fachkraft');
const formatDate = (value?: string | null) =>
    value
        ? new Intl.DateTimeFormat('de-DE', {
              dateStyle: 'full',
              timeStyle: 'short',
          }).format(new Date(value))
        : 'Termin noch offen';
const accept = (interview: Interview, proposal: InterviewProposal) =>
    router.post(
        respond.url(interview.id),
        { response: 'accept', proposal_id: proposal.id },
        { preserveScroll: true },
    );
const cancelInterview = (interview: Interview) =>
    router.post(
        respond.url(interview.id),
        { response: 'cancel', note: 'Über die Plattform abgesagt' },
        { preserveScroll: true },
    );
const addSlot = () =>
    availabilityForm.slots.push({
        weekday: 1,
        starts_at: '09:00',
        ends_at: '12:00',
        timezone: props.timezone,
    });
</script>

<template>
    <div class="erin-page">
        <PageHeader
            eyebrow="Termine"
            title="Interviews"
            description="Gespräche, Terminvorschläge und Wochenverfügbarkeit an einem Ort."
            :icon="CalendarDays"
        />
        <div class="grid gap-6 xl:grid-cols-[1.25fr_0.75fr]">
            <SectionCard
                title="Interviews"
                :description="`${interviews.length} Termine und Vorschläge`"
            >
                <div v-if="interviews.length" class="space-y-3">
                    <article
                        v-for="interview in interviews"
                        :key="interview.id"
                        class="rounded-xl border border-slate-200 p-4"
                    >
                        <div
                            class="flex flex-col gap-4 sm:flex-row sm:items-center"
                        >
                            <div
                                class="grid size-12 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-600"
                            >
                                <Video class="size-5" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-bold text-slate-900">
                                        {{ person(interview) }}
                                    </h3>
                                    <StatusBadge
                                        :label="statusLabel(interview.status)"
                                        :tone="statusTone(interview.status)"
                                    />
                                </div>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{
                                        interview.application?.job_posting
                                            ?.title ?? 'Stelle nicht verfügbar'
                                    }}
                                </p>
                                <p
                                    class="mt-2 flex items-center gap-1.5 text-xs font-medium text-slate-600"
                                >
                                    <Clock3
                                        class="size-3.5 text-[var(--erin-secondary)]"
                                    />{{ formatDate(interview.starts_at) }}
                                </p>
                            </div>
                            <div class="flex gap-2">
                                <a
                                    v-if="interview.ics_url"
                                    :href="interview.ics_url"
                                    class="grid size-10 place-items-center rounded-xl border border-slate-200 text-slate-500"
                                    title="Kalenderdatei herunterladen"
                                    ><FileDown class="size-4"
                                /></a>
                                <button
                                    v-if="
                                        ![
                                            'cancelled',
                                            'completed',
                                            'no_show',
                                        ].includes(interview.status)
                                    "
                                    class="h-10 rounded-xl border border-red-200 px-3 text-xs font-bold text-red-600"
                                    @click="cancelInterview(interview)"
                                >
                                    Absagen
                                </button>
                            </div>
                        </div>
                        <div
                            v-if="
                                interview.proposals?.some(
                                    (proposal) => proposal.status === 'pending',
                                )
                            "
                            class="mt-4 grid gap-2 border-t border-slate-100 pt-4 sm:grid-cols-2"
                        >
                            <button
                                v-for="proposal in interview.proposals.filter(
                                    (item) => item.status === 'pending',
                                )"
                                :key="proposal.id"
                                class="flex items-center justify-between rounded-xl bg-slate-50 p-3 text-left hover:bg-blue-50"
                                @click="accept(interview, proposal)"
                            >
                                <span
                                    ><span class="block text-xs font-bold">{{
                                        formatDate(proposal.starts_at)
                                    }}</span
                                    ><span class="text-[10px] text-slate-400">{{
                                        proposal.timezone
                                    }}</span></span
                                >
                                <span
                                    class="text-[10px] font-bold text-[var(--erin-primary)]"
                                    >Bestätigen</span
                                >
                            </button>
                        </div>
                    </article>
                </div>
                <div v-else class="py-12 text-center">
                    <Inbox class="mx-auto size-8 text-slate-300" />
                    <p class="mt-3 text-sm text-slate-400">
                        Noch keine Interviews geplant.
                    </p>
                </div>
            </SectionCard>

            <SectionCard
                title="Wochenverfügbarkeit"
                description="Zeiten für neue Terminvorschläge"
            >
                <form
                    class="space-y-3"
                    @submit.prevent="
                        availabilityForm.put(updateAvailability.url(), {
                            preserveScroll: true,
                        })
                    "
                >
                    <div
                        v-for="(slot, index) in availabilityForm.slots"
                        :key="index"
                        class="grid grid-cols-[1fr_5rem_5rem_auto] gap-2"
                    >
                        <select
                            v-model.number="slot.weekday"
                            class="h-9 rounded-lg border border-slate-200 px-2 text-xs"
                        >
                            <option
                                v-for="(day, dayIndex) in [
                                    'Montag',
                                    'Dienstag',
                                    'Mittwoch',
                                    'Donnerstag',
                                    'Freitag',
                                    'Samstag',
                                    'Sonntag',
                                ]"
                                :key="day"
                                :value="dayIndex + 1"
                            >
                                {{ day }}
                            </option>
                        </select>
                        <input
                            v-model="slot.starts_at"
                            type="time"
                            class="h-9 rounded-lg border border-slate-200 px-1 text-xs"
                        />
                        <input
                            v-model="slot.ends_at"
                            type="time"
                            class="h-9 rounded-lg border border-slate-200 px-1 text-xs"
                        />
                        <button
                            type="button"
                            class="text-xs font-bold text-red-500"
                            @click="availabilityForm.slots.splice(index, 1)"
                        >
                            ×
                        </button>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-9 items-center gap-2 text-xs font-bold text-[var(--erin-primary)]"
                        @click="addSlot"
                    >
                        <Plus class="size-4" /> Zeitraum hinzufügen
                    </button>
                    <button
                        type="submit"
                        :disabled="availabilityForm.processing"
                        class="h-10 w-full rounded-xl bg-[var(--erin-primary)] text-xs font-bold text-white disabled:opacity-50"
                    >
                        Verfügbarkeit speichern
                    </button>
                </form>
            </SectionCard>
        </div>
        <section class="erin-panel overflow-hidden bg-slate-900 p-6 text-white">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                <span
                    class="grid size-12 place-items-center rounded-2xl bg-white/10 text-teal-300"
                    ><CheckCircle2 class="size-6"
                /></span>
                <div class="flex-1">
                    <h2 class="font-extrabold">
                        Sichere Interviews direkt in Erin
                    </h2>
                    <p class="mt-1 text-sm leading-6 text-slate-300">
                        Videoräume werden erst für bestätigte Termine
                        freigeschaltet; Aufzeichnungen sind deaktiviert.
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>
