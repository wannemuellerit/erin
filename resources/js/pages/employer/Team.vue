<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Mail, ShieldCheck, Trash2, UserPlus, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { invite, remove } from '@/routes/employer/team';

type Member = {
    id: number;
    role: string;
    accepted_at?: string | null;
    user?: {
        id: number;
        name: string;
        email: string;
        last_active_at?: string | null;
    } | null;
};

type Invitation = {
    id: number;
    email: string;
    role: string;
    expires_at?: string | null;
};

type Team = {
    id: number;
    name: string;
    memberships?: Member[];
};

type SeatUsage = {
    used: number;
    limit: number | null;
    remaining: number | null;
};

const props = withDefaults(
    defineProps<{
        members?: Member[];
        invitations?: Invitation[];
        teams?: Team[];
        seats?: SeatUsage;
        can_manage?: boolean;
    }>(),
    {
        members: () => [],
        invitations: () => [],
        teams: () => [],
        seats: () => ({ used: 0, limit: null, remaining: null }),
        can_manage: false,
    },
);

const showInviteForm = ref(false);
const inviteForm = useForm({
    email: '',
    role: 'recruiter',
});
const activeRecruiters = computed(
    () =>
        props.members.filter((member) =>
            ['owner', 'admin', 'recruiter'].includes(member.role),
        ).length,
);
const seatLabel = computed(() =>
    props.seats.limit === null
        ? `${props.seats.used} / ∞`
        : `${props.seats.used} / ${props.seats.limit}`,
);
const initials = (name?: string) =>
    (name ?? 'ER')
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
const roleLabel = (role: string) =>
    ({
        owner: 'Inhaber',
        admin: 'Admin',
        recruiter: 'Recruiter',
        viewer: 'Viewer',
    })[role] ?? role;
const submitInvite = () => {
    inviteForm.post(invite.url(), {
        preserveScroll: true,
        onSuccess: () => {
            inviteForm.reset();
            showInviteForm.value = false;
        },
    });
};
const removeMember = (member: Member) => {
    router.delete(remove.url(member.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Team & Rollen" />
    <div class="erin-page">
        <PageHeader
            eyebrow="Organisation"
            title="Team & Rollen"
            description="Verwalten Sie Recruiter, Zuständigkeiten und Zugriffsrechte."
            :icon="Users"
        >
            <template #actions>
                <button
                    v-if="can_manage"
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--erin-primary)] px-4 text-sm font-bold text-white"
                    @click="showInviteForm = !showInviteForm"
                >
                    <UserPlus class="size-4" />
                    Mitglied einladen
                </button>
            </template>
        </PageHeader>

        <form
            v-if="showInviteForm"
            class="erin-panel grid gap-4 p-5 sm:grid-cols-[1fr_12rem_auto]"
            @submit.prevent="submitInvite"
        >
            <label>
                <span class="text-xs font-bold text-slate-600">
                    E-Mail-Adresse
                </span>
                <input
                    v-model="inviteForm.email"
                    required
                    type="email"
                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                />
                <span
                    v-if="inviteForm.errors.email"
                    class="mt-1 block text-xs text-red-600"
                >
                    {{ inviteForm.errors.email }}
                </span>
            </label>
            <label>
                <span class="text-xs font-bold text-slate-600">Rolle</span>
                <select
                    v-model="inviteForm.role"
                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                >
                    <option value="admin">Admin</option>
                    <option value="recruiter">Recruiter</option>
                    <option value="viewer">Viewer</option>
                </select>
            </label>
            <button
                type="submit"
                :disabled="inviteForm.processing"
                class="mt-auto h-10 rounded-xl bg-[var(--erin-primary)] px-4 text-xs font-bold text-white disabled:opacity-50"
            >
                Einladung senden
            </button>
        </form>

        <div class="grid gap-4 sm:grid-cols-3">
            <MetricCard
                label="Teammitglieder"
                :value="seatLabel"
                :icon="Users"
            />
            <MetricCard
                label="Recruiting-Zugriff"
                :value="activeRecruiters"
                :icon="ShieldCheck"
                tone="teal"
            />
            <MetricCard
                label="Offene Einladungen"
                :value="invitations.length"
                :icon="Mail"
                tone="orange"
            />
        </div>

        <SectionCard
            title="Mitglieder"
            description="Zugriff auf das Unternehmensportal"
        >
            <div v-if="members.length" class="divide-y divide-slate-100">
                <div
                    v-for="member in members"
                    :key="member.id"
                    class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center"
                >
                    <span
                        class="grid size-11 shrink-0 place-items-center rounded-xl bg-blue-50 text-xs font-extrabold text-[var(--erin-primary)]"
                    >
                        {{ initials(member.user?.name) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-slate-900">
                            {{ member.user?.name ?? 'Unbekanntes Mitglied' }}
                        </p>
                        <p class="text-xs text-slate-400">
                            {{ member.user?.email }}
                        </p>
                    </div>
                    <div class="sm:w-40">
                        <p class="text-xs font-bold text-slate-700">
                            {{ roleLabel(member.role) }}
                        </p>
                        <p
                            v-if="member.user?.last_active_at"
                            class="mt-1 text-[10px] text-slate-400"
                        >
                            Aktiv
                            {{
                                new Intl.DateTimeFormat('de-DE', {
                                    dateStyle: 'medium',
                                }).format(new Date(member.user.last_active_at))
                            }}
                        </p>
                    </div>
                    <StatusBadge
                        :label="member.accepted_at ? 'Aktiv' : 'Ausstehend'"
                        :tone="member.accepted_at ? 'green' : 'yellow'"
                    />
                    <button
                        v-if="can_manage && member.role !== 'owner'"
                        type="button"
                        class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-red-600"
                        :aria-label="`${member.user?.name ?? 'Mitglied'} entfernen`"
                        @click="removeMember(member)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>
            </div>
            <p v-else class="py-10 text-center text-sm text-slate-400">
                Noch keine Teammitglieder vorhanden.
            </p>
        </SectionCard>

        <div class="grid gap-6 lg:grid-cols-2">
            <SectionCard
                title="Offene Einladungen"
                description="Noch nicht angenommene Teameinladungen"
            >
                <div v-if="invitations.length" class="space-y-3">
                    <article
                        v-for="invitation in invitations"
                        :key="invitation.id"
                        class="rounded-xl border border-slate-200 p-4"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold">
                                    {{ invitation.email }}
                                </p>
                                <p class="mt-1 text-xs text-slate-400">
                                    {{ roleLabel(invitation.role) }}
                                </p>
                            </div>
                            <StatusBadge label="Eingeladen" tone="yellow" />
                        </div>
                        <p
                            v-if="invitation.expires_at"
                            class="mt-2 text-[10px] text-slate-400"
                        >
                            Gültig bis
                            {{
                                new Intl.DateTimeFormat('de-DE', {
                                    dateStyle: 'medium',
                                }).format(new Date(invitation.expires_at))
                            }}
                        </p>
                    </article>
                </div>
                <p v-else class="py-8 text-center text-sm text-slate-400">
                    Keine offenen Einladungen.
                </p>
            </SectionCard>

            <SectionCard
                title="Teams"
                description="Organisierte Recruiting-Gruppen"
            >
                <div v-if="teams.length" class="space-y-3">
                    <article
                        v-for="team in teams"
                        :key="team.id"
                        class="rounded-xl border border-slate-200 p-4"
                    >
                        <p class="text-sm font-bold">{{ team.name }}</p>
                        <p class="mt-1 text-xs text-slate-400">
                            {{ team.memberships?.length ?? 0 }} Mitglieder
                        </p>
                    </article>
                </div>
                <p v-else class="py-8 text-center text-sm text-slate-400">
                    Noch keine Teams angelegt.
                </p>
            </SectionCard>
        </div>
    </div>
</template>
