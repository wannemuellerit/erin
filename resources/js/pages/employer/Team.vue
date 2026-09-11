<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Mail, ShieldCheck, Trash2, UserPlus, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { useFormatters } from '@/composables/useFormatters';
import { invite, remove, transferOwnership } from '@/routes/employer/team';

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
    location_id?: number | null;
    location?: { id: number; name: string; city: string } | null;
    teams?: Array<{ id: number; name: string }>;
};

type Invitation = {
    id: number;
    email: string;
    role: string;
    expires_at?: string | null;
    status?: string;
};

type Team = {
    id: number;
    name: string;
    memberships?: Member[];
    location_id?: number | null;
    contact_membership_id?: number | null;
};

type Location = { id: number; name: string; city: string };
type OrganizationItem = {
    id: number;
    title?: string;
    label?: string;
    company_team_id?: number | null;
    contact_membership_id?: number | null;
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
        locations?: Location[];
        jobs?: OrganizationItem[];
        applications?: OrganizationItem[];
        seats?: SeatUsage;
        can_manage?: boolean;
        can_transfer_ownership?: boolean;
    }>(),
    {
        members: () => [],
        invitations: () => [],
        teams: () => [],
        locations: () => [],
        jobs: () => [],
        applications: () => [],
        seats: () => ({ used: 0, limit: null, remaining: null }),
        can_manage: false,
        can_transfer_ownership: false,
    },
);

const showInviteForm = ref(false);
const { t, te } = useI18n();
const { formatDate } = useFormatters();
const inviteForm = useForm({
    email: '',
    role: 'viewer',
});
const teamForm = useForm({
    name: '',
    location_id: null as number | null,
    contact_membership_id: null as number | null,
    membership_ids: [] as number[],
});
const editableTeams = ref(
    props.teams.map((team) => ({
        ...team,
        location_id: team.location_id ?? null,
        contact_membership_id: team.contact_membership_id ?? null,
        membership_ids: (team.memberships ?? []).map((member) => member.id),
    })),
);
const organizationJobs = ref(props.jobs.map((job) => ({ ...job })));
const organizationApplications = ref(
    props.applications.map((application) => ({ ...application })),
);
const recruitingAccessCount = computed(
    () =>
        props.members.filter((member) =>
            ['owner', 'admin'].includes(member.role),
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
    te(`employer.team.roles.${role}`) ? t(`employer.team.roles.${role}`) : role;
const memberCountLabel = (count: number) =>
    t(
        count === 1
            ? 'employer.team.memberCount.one'
            : 'employer.team.memberCount.other',
        { count },
    );
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
const updateMember = (
    member: Member,
    payload: Partial<{
        role: string;
        location_id: number | null;
        team_ids: number[];
    }>,
) => {
    router.patch(`/employer/team/members/${member.id}`, payload, {
        preserveScroll: true,
    });
};
const selectedNumbers = (event: Event) =>
    Array.from((event.target as HTMLSelectElement).selectedOptions).map(
        (option) => Number(option.value),
    );
const submitTeam = () => {
    teamForm.post('/employer/team/teams', {
        preserveScroll: true,
        onSuccess: () => teamForm.reset(),
    });
};
const updateTeam = (team: (typeof editableTeams.value)[number]) => {
    router.put(`/employer/team/teams/${team.id}`, team, {
        preserveScroll: true,
    });
};
const deleteTeam = (team: Team) => {
    if (window.confirm(t('employer.team.deleteTeamConfirm'))) {
        router.delete(`/employer/team/teams/${team.id}`, {
            preserveScroll: true,
        });
    }
};
const resendInvitation = (invitation: Invitation) =>
    router.post(
        `/employer/team/invitations/${invitation.id}/resend`,
        {},
        { preserveScroll: true },
    );
const revokeInvitation = (invitation: Invitation) =>
    router.delete(`/employer/team/invitations/${invitation.id}`, {
        preserveScroll: true,
    });
const updateOrganization = (
    type: 'jobs' | 'applications',
    item: OrganizationItem,
) => {
    router.patch(`/employer/team/${type}/${item.id}/organization`, {
        company_team_id: item.company_team_id || null,
        contact_membership_id: item.contact_membership_id || null,
    });
};
const transferOwner = (member: Member) => {
    if (
        !window.confirm(
            t('employer.team.transferOwnershipConfirm', {
                name: member.user?.name ?? member.user?.email,
            }),
        )
    ) {
        return;
    }

    router.post(transferOwnership.url(member.id), {}, { preserveScroll: true });
};
</script>

<template>
    <Head :title="t('employer.team.metaTitle')" />
    <div class="erin-page">
        <PageHeader
            :eyebrow="t('employer.team.eyebrow')"
            :title="t('employer.team.title')"
            :description="t('employer.team.description')"
            :icon="Users"
        >
            <template #actions>
                <button
                    v-if="can_manage"
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--erin-primary)] px-4 text-sm font-bold text-[var(--erin-primary-foreground)]"
                    @click="showInviteForm = !showInviteForm"
                >
                    <UserPlus class="size-4" />
                    {{ t('employer.team.inviteMember') }}
                </button>
            </template>
        </PageHeader>

        <form
            v-if="showInviteForm"
            class="erin-panel grid gap-4 p-5 sm:grid-cols-[1fr_12rem_auto]"
            @submit.prevent="submitInvite"
        >
            <label>
                <span class="text-xs font-bold text-muted-foreground">
                    {{ t('employer.team.email') }}
                </span>
                <input
                    v-model="inviteForm.email"
                    required
                    type="email"
                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-border px-3 text-sm"
                />
                <span
                    v-if="inviteForm.errors.email"
                    class="mt-1 block text-xs text-red-600"
                >
                    {{ inviteForm.errors.email }}
                </span>
            </label>
            <label>
                <span class="text-xs font-bold text-muted-foreground">
                    {{ t('employer.team.role') }}
                </span>
                <select
                    v-model="inviteForm.role"
                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-border px-3 text-sm"
                >
                    <option value="admin">
                        {{ t('employer.team.roles.admin') }}
                    </option>
                    <option value="viewer">
                        {{ t('employer.team.roles.viewer') }}
                    </option>
                </select>
            </label>
            <button
                type="submit"
                :disabled="inviteForm.processing"
                class="mt-auto h-10 rounded-xl bg-[var(--erin-primary)] px-4 text-xs font-bold text-[var(--erin-primary-foreground)] disabled:opacity-50"
            >
                {{ t('employer.team.sendInvitation') }}
            </button>
        </form>

        <div class="grid gap-4 sm:grid-cols-3">
            <MetricCard
                :label="t('employer.team.metrics.members')"
                :value="seatLabel"
                :icon="Users"
            />
            <MetricCard
                :label="t('employer.team.metrics.recruitingAccess')"
                :value="recruitingAccessCount"
                :icon="ShieldCheck"
                tone="teal"
            />
            <MetricCard
                :label="t('employer.team.metrics.openInvitations')"
                :value="invitations.length"
                :icon="Mail"
                tone="orange"
            />
        </div>

        <SectionCard
            :title="t('employer.team.membersTitle')"
            :description="t('employer.team.membersDescription')"
        >
            <div v-if="members.length" class="divide-y divide-border">
                <div
                    v-for="member in members"
                    :key="member.id"
                    :data-member-email="member.user?.email"
                    class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center"
                >
                    <span
                        class="grid size-11 shrink-0 place-items-center rounded-xl bg-blue-50 text-xs font-extrabold text-[var(--erin-primary-text)]"
                    >
                        {{ initials(member.user?.name) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-foreground">
                            {{
                                member.user?.name ??
                                t('employer.team.unknownMember')
                            }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ member.user?.email }}
                        </p>
                    </div>
                    <div class="grid gap-2 sm:w-64">
                        <select
                            v-if="can_manage && member.role !== 'owner'"
                            :value="member.role"
                            class="h-9 rounded-lg border border-border px-2 text-xs font-bold"
                            :aria-label="t('employer.team.changeRole')"
                            @change="
                                updateMember(member, {
                                    role: ($event.target as HTMLSelectElement)
                                        .value,
                                })
                            "
                        >
                            <option value="admin">
                                {{ t('employer.team.roles.admin') }}
                            </option>
                            <option value="viewer">
                                {{ t('employer.team.roles.viewer') }}
                            </option>
                        </select>
                        <p
                            v-else
                            class="text-xs font-bold text-muted-foreground"
                        >
                            {{ roleLabel(member.role) }}
                        </p>
                        <select
                            v-if="can_manage"
                            :value="member.location_id ?? ''"
                            class="h-9 rounded-lg border border-border px-2 text-xs"
                            :aria-label="t('employer.team.memberLocation')"
                            @change="
                                updateMember(member, {
                                    location_id:
                                        Number(
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        ) || null,
                                })
                            "
                        >
                            <option value="">
                                {{ t('employer.team.noLocation') }}
                            </option>
                            <option
                                v-for="location in locations"
                                :key="location.id"
                                :value="location.id"
                            >
                                {{ location.name }} · {{ location.city }}
                            </option>
                        </select>
                        <select
                            v-if="can_manage"
                            multiple
                            :value="member.teams?.map((team) => team.id) ?? []"
                            class="min-h-16 rounded-lg border border-border px-2 text-xs"
                            :aria-label="t('employer.team.memberTeams')"
                            @change="
                                updateMember(member, {
                                    team_ids: selectedNumbers($event),
                                })
                            "
                        >
                            <option
                                v-for="team in editableTeams"
                                :key="team.id"
                                :value="team.id"
                            >
                                {{ team.name }}
                            </option>
                        </select>
                        <p
                            v-if="member.user?.last_active_at"
                            class="mt-1 text-[10px] text-muted-foreground"
                        >
                            {{
                                t('employer.team.activeOn', {
                                    date: formatDate(
                                        member.user.last_active_at,
                                    ),
                                })
                            }}
                        </p>
                    </div>
                    <StatusBadge
                        :label="
                            member.accepted_at
                                ? t('employer.team.status.active')
                                : t('employer.team.status.pending')
                        "
                        :tone="member.accepted_at ? 'green' : 'yellow'"
                    />
                    <button
                        v-if="can_transfer_ownership && member.role !== 'owner'"
                        type="button"
                        class="rounded-lg border border-orange-200 px-3 py-2 text-xs font-bold text-orange-700 hover:bg-orange-50"
                        @click="transferOwner(member)"
                    >
                        {{ t('employer.team.transferOwnership') }}
                    </button>
                    <button
                        v-if="can_manage && member.role !== 'owner'"
                        type="button"
                        class="grid size-9 place-items-center rounded-lg text-muted-foreground hover:bg-red-50 hover:text-red-600"
                        :aria-label="
                            t('employer.team.removeMember', {
                                name:
                                    member.user?.name ??
                                    t('employer.team.member'),
                            })
                        "
                        @click="removeMember(member)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>
            </div>
            <p v-else class="py-10 text-center text-sm text-muted-foreground">
                {{ t('employer.team.noMembers') }}
            </p>
        </SectionCard>

        <div class="grid gap-6 lg:grid-cols-2">
            <SectionCard
                :title="t('employer.team.invitationsTitle')"
                :description="t('employer.team.invitationsDescription')"
            >
                <div v-if="invitations.length" class="space-y-3">
                    <article
                        v-for="invitation in invitations"
                        :key="invitation.id"
                        class="rounded-xl border border-border p-4"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold">
                                    {{ invitation.email }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ roleLabel(invitation.role) }}
                                </p>
                            </div>
                            <StatusBadge
                                :label="
                                    t(
                                        `employer.team.status.${invitation.status ?? 'invited'}`,
                                    )
                                "
                                :tone="
                                    invitation.status === 'expired'
                                        ? 'red'
                                        : 'yellow'
                                "
                            />
                        </div>
                        <p
                            v-if="invitation.expires_at"
                            class="mt-2 text-[10px] text-muted-foreground"
                        >
                            {{
                                t('employer.team.validUntil', {
                                    date: formatDate(invitation.expires_at),
                                })
                            }}
                        </p>
                        <div v-if="can_manage" class="mt-3 flex gap-2">
                            <button
                                type="button"
                                class="h-8 rounded-lg border px-3 text-xs font-bold"
                                @click="resendInvitation(invitation)"
                            >
                                {{ t('employer.team.resendInvitation') }}
                            </button>
                            <button
                                type="button"
                                class="h-8 rounded-lg border border-red-200 px-3 text-xs font-bold text-red-600"
                                @click="revokeInvitation(invitation)"
                            >
                                {{ t('employer.team.revokeInvitation') }}
                            </button>
                        </div>
                    </article>
                </div>
                <p
                    v-else
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    {{ t('employer.team.noInvitations') }}
                </p>
            </SectionCard>

            <SectionCard
                :title="t('employer.team.teamsTitle')"
                :description="t('employer.team.teamsDescription')"
            >
                <form
                    v-if="can_manage"
                    class="mb-4 grid gap-2 rounded-xl bg-muted p-3"
                    @submit.prevent="submitTeam"
                >
                    <input
                        v-model="teamForm.name"
                        required
                        class="h-9 rounded-lg border px-2 text-xs"
                        :placeholder="t('employer.team.teamName')"
                    />
                    <div class="grid gap-2 sm:grid-cols-2">
                        <select
                            v-model="teamForm.location_id"
                            class="h-9 rounded-lg border px-2 text-xs"
                            :aria-label="t('employer.team.teamLocation')"
                        >
                            <option :value="null">
                                {{ t('employer.team.noLocation') }}
                            </option>
                            <option
                                v-for="location in locations"
                                :key="location.id"
                                :value="location.id"
                            >
                                {{ location.name }} · {{ location.city }}
                            </option>
                        </select>
                        <select
                            v-model="teamForm.contact_membership_id"
                            class="h-9 rounded-lg border px-2 text-xs"
                            :aria-label="t('employer.team.contactPerson')"
                        >
                            <option :value="null">
                                {{ t('employer.team.noContact') }}
                            </option>
                            <option
                                v-for="member in members"
                                :key="member.id"
                                :value="member.id"
                            >
                                {{ member.user?.name }}
                            </option>
                        </select>
                    </div>
                    <select
                        v-model="teamForm.membership_ids"
                        multiple
                        class="min-h-20 rounded-lg border px-2 text-xs"
                        :aria-label="t('employer.team.teamMembers')"
                    >
                        <option
                            v-for="member in members"
                            :key="member.id"
                            :value="member.id"
                        >
                            {{ member.user?.name }} ·
                            {{ roleLabel(member.role) }}
                        </option>
                    </select>
                    <button
                        type="submit"
                        class="h-9 rounded-lg bg-[var(--erin-primary)] px-3 text-xs font-bold text-[var(--erin-primary-foreground)]"
                    >
                        {{ t('employer.team.createTeam') }}
                    </button>
                </form>
                <div v-if="editableTeams.length" class="space-y-3">
                    <article
                        v-for="team in editableTeams"
                        :key="team.id"
                        class="grid gap-2 rounded-xl border border-border p-4"
                    >
                        <input
                            v-if="can_manage"
                            v-model="team.name"
                            class="h-9 rounded-lg border px-2 text-sm font-bold"
                            :aria-label="t('employer.team.teamName')"
                        />
                        <p v-else class="text-sm font-bold">{{ team.name }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ memberCountLabel(team.membership_ids.length) }}
                        </p>
                        <template v-if="can_manage">
                            <select
                                v-model="team.location_id"
                                class="h-9 rounded-lg border px-2 text-xs"
                                :aria-label="t('employer.team.teamLocation')"
                            >
                                <option :value="null">
                                    {{ t('employer.team.noLocation') }}
                                </option>
                                <option
                                    v-for="location in locations"
                                    :key="location.id"
                                    :value="location.id"
                                >
                                    {{ location.name }} · {{ location.city }}
                                </option>
                            </select>
                            <select
                                v-model="team.contact_membership_id"
                                class="h-9 rounded-lg border px-2 text-xs"
                                :aria-label="t('employer.team.contactPerson')"
                            >
                                <option :value="null">
                                    {{ t('employer.team.noContact') }}
                                </option>
                                <option
                                    v-for="member in members"
                                    :key="member.id"
                                    :value="member.id"
                                >
                                    {{ member.user?.name }}
                                </option>
                            </select>
                            <select
                                v-model="team.membership_ids"
                                multiple
                                class="min-h-20 rounded-lg border px-2 text-xs"
                                :aria-label="t('employer.team.teamMembers')"
                            >
                                <option
                                    v-for="member in members"
                                    :key="member.id"
                                    :value="member.id"
                                >
                                    {{ member.user?.name }}
                                </option>
                            </select>
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    class="h-9 rounded-lg bg-[var(--erin-primary)] px-3 text-xs font-bold text-[var(--erin-primary-foreground)]"
                                    @click="updateTeam(team)"
                                >
                                    {{ t('employer.team.saveTeam') }}
                                </button>
                                <button
                                    type="button"
                                    class="h-9 rounded-lg border border-red-200 px-3 text-xs font-bold text-red-600"
                                    @click="deleteTeam(team)"
                                >
                                    {{ t('employer.team.deleteTeam') }}
                                </button>
                            </div>
                        </template>
                    </article>
                </div>
                <p
                    v-else
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    {{ t('employer.team.noTeams') }}
                </p>
            </SectionCard>
        </div>

        <SectionCard
            v-if="can_manage"
            :title="t('employer.team.assignmentsTitle')"
            :description="t('employer.team.assignmentsDescription')"
        >
            <div class="grid gap-5 lg:grid-cols-2">
                <div>
                    <h3 class="mb-2 text-sm font-bold">
                        {{ t('employer.team.jobs') }}
                    </h3>
                    <article
                        v-for="job in organizationJobs"
                        :key="job.id"
                        class="mb-2 grid gap-2 rounded-xl border p-3 sm:grid-cols-[1fr_10rem_10rem_auto]"
                    >
                        <span class="self-center text-xs font-bold">{{
                            job.title
                        }}</span>
                        <select
                            v-model="job.company_team_id"
                            class="h-9 rounded-lg border px-2 text-xs"
                            :aria-label="t('employer.team.assignedTeam')"
                        >
                            <option :value="null">—</option>
                            <option
                                v-for="team in editableTeams"
                                :key="team.id"
                                :value="team.id"
                            >
                                {{ team.name }}
                            </option>
                        </select>
                        <select
                            v-model="job.contact_membership_id"
                            class="h-9 rounded-lg border px-2 text-xs"
                            :aria-label="t('employer.team.contactPerson')"
                        >
                            <option :value="null">—</option>
                            <option
                                v-for="member in members"
                                :key="member.id"
                                :value="member.id"
                            >
                                {{ member.user?.name }}
                            </option>
                        </select>
                        <button
                            type="button"
                            class="h-9 rounded-lg border px-3 text-xs font-bold"
                            @click="updateOrganization('jobs', job)"
                        >
                            {{ t('employer.team.saveAssignment') }}
                        </button>
                    </article>
                </div>
                <div>
                    <h3 class="mb-2 text-sm font-bold">
                        {{ t('employer.team.applications') }}
                    </h3>
                    <article
                        v-for="application in organizationApplications"
                        :key="application.id"
                        class="mb-2 grid gap-2 rounded-xl border p-3 sm:grid-cols-[1fr_10rem_10rem_auto]"
                    >
                        <span class="self-center text-xs font-bold">{{
                            application.label
                        }}</span>
                        <select
                            v-model="application.company_team_id"
                            class="h-9 rounded-lg border px-2 text-xs"
                            :aria-label="t('employer.team.assignedTeam')"
                        >
                            <option :value="null">—</option>
                            <option
                                v-for="team in editableTeams"
                                :key="team.id"
                                :value="team.id"
                            >
                                {{ team.name }}
                            </option>
                        </select>
                        <select
                            v-model="application.contact_membership_id"
                            class="h-9 rounded-lg border px-2 text-xs"
                            :aria-label="t('employer.team.contactPerson')"
                        >
                            <option :value="null">—</option>
                            <option
                                v-for="member in members"
                                :key="member.id"
                                :value="member.id"
                            >
                                {{ member.user?.name }}
                            </option>
                        </select>
                        <button
                            type="button"
                            class="h-9 rounded-lg border px-3 text-xs font-bold"
                            @click="
                                updateOrganization('applications', application)
                            "
                        >
                            {{ t('employer.team.saveAssignment') }}
                        </button>
                    </article>
                </div>
            </div>
        </SectionCard>
    </div>
</template>
