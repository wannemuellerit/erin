<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    History,
    Search,
    ShieldAlert,
    Users as UsersIcon,
    X,
} from '@lucide/vue';
import { reactive } from 'vue';
import EmptyState from '@/components/product/EmptyState.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import adminUsers from '@/routes/admin/users';
import AdminPagination from './_components/AdminPagination.vue';
import { useAdminI18n } from './_i18n';
import { cleanFilters, statusTone } from './_shared';
import type { AdminPaginator } from './_shared';

type UserRow = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    role: string;
    platform_role_id: number | null;
    platform_role?: { id: number; name: string } | null;
    status: string;
    locale: string;
    last_active_at: string | null;
    suspended_at: string | null;
    blocked_reason: string | null;
    created_at: string;
    storage_quota_bytes: number | null;
    storage_usage: {
        used_bytes: number;
        quota_bytes: number;
        remaining_bytes: number;
        percentage: number;
        reserved_bytes: number;
        custom_quota: boolean;
    };
    company_memberships_count: number;
    candidate_profile: {
        id: number;
        user_id: number;
        current_position: string | null;
        desired_position: string | null;
        current_country_code: string | null;
        completeness: number;
        published_at: string | null;
    } | null;
};

type UserFilters = {
    search?: string;
    role?: string;
    status?: string;
    sort?: string;
};

const props = defineProps<{
    users: AdminPaginator<UserRow>;
    filters: UserFilters;
    roles: string[];
    statuses: string[];
    platform_roles: Array<{ id: number; name: string }>;
}>();

const filters = reactive({
    search: props.filters.search ?? '',
    role: props.filters.role ?? '',
    status: props.filters.status ?? '',
    sort: props.filters.sort ?? 'newest',
});

const statusForm = useForm({
    status: '',
    reason: '',
});

const roleForm = useForm({
    role: '',
});
const platformRoleForm = useForm({
    platform_role_id: null as number | null,
});

const quotaForm = useForm({
    storage_quota_mb: null as number | null,
});

const { t, formatDate, humanize } = useAdminI18n();

function applyFilters(): void {
    router.get(adminUsers.index.url(), cleanFilters(filters), {
        preserveState: true,
        replace: true,
    });
}

function resetFilters(): void {
    router.get(adminUsers.index.url(), {}, { replace: true });
}

function updateStatus(user: UserRow, event: Event): void {
    const select = event.target as HTMLSelectElement;
    const nextStatus = select.value;

    if (nextStatus === user.status) {
        return;
    }

    let reason = '';

    if (['suspended', 'blocked'].includes(nextStatus)) {
        const input = window.prompt(t('common.reasonPrompt'));

        if (input === null || input.trim().length < 5) {
            select.value = user.status;

            return;
        }

        reason = input.trim();
    }

    statusForm.status = nextStatus;
    statusForm.reason = reason;
    statusForm.patch(adminUsers.status.update.url(user.id), {
        preserveScroll: true,
        onError: () => {
            select.value = user.status;
        },
        onFinish: () => statusForm.reset(),
    });
}

function updateRole(user: UserRow, event: Event): void {
    const select = event.target as HTMLSelectElement;
    const nextRole = select.value;

    if (nextRole === user.role) {
        return;
    }

    if (
        !window.confirm(
            t('users.roleConfirm', {
                name: user.name,
                role: humanize(nextRole),
            }),
        )
    ) {
        select.value = user.role;

        return;
    }

    roleForm.role = nextRole;
    roleForm.patch(adminUsers.role.update.url(user.id), {
        preserveScroll: true,
        onError: () => {
            select.value = user.role;
        },
        onFinish: () => roleForm.reset(),
    });
}
function updatePlatformRole(user: UserRow, event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    platformRoleForm.platform_role_id = value ? Number(value) : null;
    platformRoleForm.patch(`/admin/users/${user.id}/platform-role`, {
        preserveScroll: true,
        onFinish: () => platformRoleForm.reset(),
    });
}

function updateQuota(user: UserRow): void {
    const current = Math.round(user.storage_usage.quota_bytes / 1024 / 1024);
    const value = window.prompt(
        t('users.storagePrompt'),
        user.storage_usage.custom_quota ? current.toString() : '',
    );

    if (value === null) {
        return;
    }

    quotaForm.storage_quota_mb = value.trim() === '' ? null : Number(value);
    quotaForm.patch(adminUsers.storageQuota.update.url(user.id), {
        preserveScroll: true,
        onFinish: () => quotaForm.reset(),
    });
}
</script>

<template>
    <Head :title="t('users.metaTitle')" />

    <div class="erin-page">
        <PageHeader
            :eyebrow="t('users.eyebrow')"
            :title="t('users.title')"
            :description="t('users.description', { count: users.total })"
            :icon="UsersIcon"
        />

        <SectionCard flush>
            <form
                class="grid gap-3 border-b border-slate-100 p-4 lg:grid-cols-[minmax(16rem,1fr)_12rem_12rem_12rem_auto]"
                @submit.prevent="applyFilters"
            >
                <label class="relative">
                    <span class="sr-only">{{ t('users.searchLabel') }}</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
                    />
                    <input
                        v-model="filters.search"
                        type="search"
                        :placeholder="t('users.searchPlaceholder')"
                        class="erin-focus h-11 w-full rounded-xl border border-slate-200 bg-white pr-3 pl-10 text-sm"
                    />
                </label>
                <select
                    v-model="filters.role"
                    :aria-label="t('users.roleFilter')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                >
                    <option value="">{{ t('common.allRoles') }}</option>
                    <option v-for="role in roles" :key="role" :value="role">
                        {{ humanize(role) }}
                    </option>
                </select>
                <select
                    v-model="filters.status"
                    :aria-label="t('users.statusFilter')"
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
                    v-model="filters.sort"
                    :aria-label="t('users.sorting')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                >
                    <option value="newest">{{ t('users.newest') }}</option>
                    <option value="oldest">{{ t('users.oldest') }}</option>
                    <option value="last_active">
                        {{ t('users.lastActive') }}
                    </option>
                </select>
                <div class="flex gap-2">
                    <button
                        type="submit"
                        class="erin-focus h-11 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white hover:bg-blue-700"
                    >
                        {{ t('common.filter') }}
                    </button>
                    <button
                        type="button"
                        :aria-label="t('common.resetFilters')"
                        class="erin-focus grid size-11 place-items-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50"
                        @click="resetFilters"
                    >
                        <X class="size-4" />
                    </button>
                </div>
            </form>

            <div v-if="users.data.length > 0" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left">
                    <thead class="bg-slate-50/80">
                        <tr
                            class="text-[11px] font-bold tracking-wide text-slate-500 uppercase"
                        >
                            <th class="px-5 py-3">
                                {{ t('users.columns.user') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('users.columns.profile') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('users.columns.storage') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('users.columns.activity') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('users.columns.role') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('users.columns.status') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr
                            v-for="user in users.data"
                            :key="user.id"
                            class="align-top"
                        >
                            <td class="px-5 py-4">
                                <p class="text-sm font-bold text-slate-900">
                                    {{ user.name }}
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ user.email }}
                                </p>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    <StatusBadge
                                        :label="
                                            user.email_verified_at
                                                ? t('users.emailVerified')
                                                : t('users.emailOpen')
                                        "
                                        :tone="
                                            user.email_verified_at
                                                ? 'green'
                                                : 'yellow'
                                        "
                                    />
                                    <span class="text-[11px] text-slate-600">
                                        #{{ user.id }} ·
                                        {{ user.locale.toUpperCase() }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-5 py-4 text-xs text-slate-600">
                                <template v-if="user.candidate_profile">
                                    <p class="font-semibold text-slate-800">
                                        {{
                                            user.candidate_profile
                                                .current_position ??
                                            user.candidate_profile
                                                .desired_position ??
                                            t('users.professionOpen')
                                        }}
                                    </p>
                                    <p class="mt-1">
                                        {{
                                            t('users.completeness', {
                                                value: user.candidate_profile
                                                    .completeness,
                                            })
                                        }}
                                        <span
                                            v-if="
                                                user.candidate_profile
                                                    .current_country_code
                                            "
                                        >
                                            ·
                                            {{
                                                user.candidate_profile
                                                    .current_country_code
                                            }}
                                        </span>
                                    </p>
                                </template>
                                <template v-else>
                                    {{
                                        t(
                                            'users.companyMemberships',
                                            user.company_memberships_count,
                                        )
                                    }}
                                </template>
                            </td>
                            <td
                                class="min-w-44 px-5 py-4 text-xs text-slate-600"
                            >
                                <div
                                    class="flex items-center justify-between gap-2"
                                >
                                    <span class="font-semibold text-slate-800">
                                        {{
                                            (
                                                user.storage_usage.used_bytes /
                                                1024 /
                                                1024
                                            ).toFixed(1)
                                        }}
                                        MB
                                    </span>
                                    <button
                                        type="button"
                                        class="erin-focus rounded-lg text-xs font-bold text-blue-600"
                                        @click="updateQuota(user)"
                                    >
                                        {{ t('users.changeStorageLimit') }}
                                    </button>
                                </div>
                                <div
                                    class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"
                                >
                                    <div
                                        class="h-full rounded-full bg-teal-500"
                                        :style="{
                                            width: `${user.storage_usage.percentage}%`,
                                        }"
                                    />
                                </div>
                                <p class="mt-1">
                                    {{
                                        t('users.storageUsage', {
                                            percentage:
                                                user.storage_usage.percentage,
                                            quota: Math.round(
                                                user.storage_usage.quota_bytes /
                                                    1024 /
                                                    1024,
                                            ),
                                        })
                                    }}
                                    <span
                                        v-if="user.storage_usage.custom_quota"
                                    >
                                        · {{ t('users.individualLimit') }}
                                    </span>
                                </p>
                            </td>
                            <td
                                class="px-5 py-4 text-xs whitespace-nowrap text-slate-500"
                            >
                                <p>{{ formatDate(user.last_active_at) }}</p>
                                <p class="mt-1 text-slate-600">
                                    {{
                                        t('users.createdAt', {
                                            date: formatDate(user.created_at),
                                        })
                                    }}
                                </p>
                                <Link
                                    :href="`/admin/audit?actor_id=${user.id}`"
                                    class="erin-focus mt-2 inline-flex items-center gap-1.5 rounded-lg text-xs font-bold text-blue-600 hover:text-blue-700"
                                >
                                    <History class="size-3.5" />
                                    {{ t('users.showHistory') }}
                                </Link>
                            </td>
                            <td class="px-5 py-4">
                                <select
                                    :value="user.role"
                                    :disabled="roleForm.processing"
                                    :aria-label="
                                        t('common.roleFor', {
                                            name: user.name,
                                        })
                                    "
                                    class="erin-focus h-9 min-w-36 rounded-lg border border-slate-200 bg-white px-2 text-xs font-semibold disabled:opacity-60"
                                    @change="updateRole(user, $event)"
                                >
                                    <option
                                        v-for="role in roles"
                                        :key="role"
                                        :value="role"
                                    >
                                        {{ humanize(role) }}
                                    </option>
                                </select>
                                <p
                                    v-if="roleForm.errors.role"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ roleForm.errors.role }}
                                </p>
                                <select
                                    v-if="user.role === 'support'"
                                    :value="user.platform_role_id ?? ''"
                                    class="erin-focus mt-2 h-9 min-w-36 rounded-lg border border-slate-200 bg-white px-2 text-xs"
                                    :aria-label="
                                        t('users.platformRoleFor', {
                                            name: user.name,
                                        })
                                    "
                                    @change="updatePlatformRole(user, $event)"
                                >
                                    <option value="">
                                        {{ t('users.defaultSupportRole') }}
                                    </option>
                                    <option
                                        v-for="platformRole in platform_roles"
                                        :key="platformRole.id"
                                        :value="platformRole.id"
                                    >
                                        {{ platformRole.name }}
                                    </option>
                                </select>
                            </td>
                            <td class="px-5 py-4">
                                <StatusBadge
                                    :label="humanize(user.status)"
                                    :tone="statusTone(user.status)"
                                />
                                <select
                                    :value="user.status"
                                    :disabled="statusForm.processing"
                                    :aria-label="
                                        t('common.statusFor', {
                                            name: user.name,
                                        })
                                    "
                                    class="erin-focus mt-2 block h-9 min-w-36 rounded-lg border border-slate-200 bg-white px-2 text-xs font-semibold disabled:opacity-60"
                                    @change="updateStatus(user, $event)"
                                >
                                    <option
                                        v-for="status in statuses"
                                        :key="status"
                                        :value="status"
                                    >
                                        {{ humanize(status) }}
                                    </option>
                                </select>
                                <p
                                    v-if="user.blocked_reason"
                                    class="mt-2 max-w-52 text-xs leading-5 text-red-600"
                                >
                                    <ShieldAlert class="mr-1 inline size-3.5" />
                                    {{ user.blocked_reason }}
                                </p>
                                <p
                                    v-if="
                                        statusForm.errors.status ||
                                        statusForm.errors.reason
                                    "
                                    class="mt-1 max-w-52 text-xs text-red-600"
                                >
                                    {{
                                        statusForm.errors.status ??
                                        statusForm.errors.reason
                                    }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState
                v-else
                :title="t('common.emptyTitle')"
                :description="t('common.emptyDescription')"
            />
            <AdminPagination :paginator="users" />
        </SectionCard>
    </div>
</template>
