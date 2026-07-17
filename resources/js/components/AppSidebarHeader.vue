<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Bell,
    Check,
    ChevronDown,
    Languages,
    LogOut,
    Search,
    ShieldCheck,
    X,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useProductNavigation } from '@/composables/useProductNavigation';
import { update as updateLocale } from '@/routes/locale';
import {
    read as readNotification,
    readAll as readAllNotifications,
} from '@/routes/notifications';
import { logout } from '@/routes';
import { stop as stopImpersonation } from '@/routes/support/impersonation';
import type { BreadcrumbItem } from '@/types';

type Impersonation = {
    active: boolean;
    read_only: boolean;
    actor_name?: string | null;
    reason?: string | null;
};

type SharedNotification = {
    id: string;
    type?: string;
    data?: {
        title?: string;
        message?: string;
        url?: string;
    };
    read_at?: string | null;
    created_at?: string | null;
};

type NotificationFeed = {
    unread_count: number;
    items: SharedNotification[];
};

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();
const { locale, t } = useI18n();
const { role, roleLabel } = useProductNavigation();

const impersonation = computed(
    () => page.props.impersonation as Impersonation | null | undefined,
);
const user = computed(() => page.props.auth?.user);
const notifications = computed<NotificationFeed>(() => {
    const shared = page.props.notifications as NotificationFeed | undefined;

    return shared ?? { unread_count: 0, items: [] };
});

const settingsUrl = '/settings/profile';
const supportUrl = computed(() =>
    role.value === 'super_admin' || role.value === 'support'
        ? '/admin/support'
        : settingsUrl,
);

const notificationTitle = (notification: SharedNotification) =>
    notification.data?.title ??
    notification.data?.message ??
    notification.type ??
    t('shell.notifications');

const notificationDetail = (notification: SharedNotification) => {
    if (
        notification.data?.title &&
        notification.data.message !== notification.data.title
    ) {
        return notification.data.message;
    }

    return '';
};

const notificationDate = (createdAt?: string | null) => {
    if (!createdAt) {
        return '';
    }

    return new Intl.DateTimeFormat(locale.value, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(createdAt));
};

const openNotification = (notification: SharedNotification) => {
    const visitTarget = () => {
        if (notification.data?.url) {
            router.visit(notification.data.url);
        }
    };

    if (notification.read_at) {
        visitTarget();

        return;
    }

    router.post(
        readNotification.url(notification.id),
        {},
        {
            preserveScroll: true,
            onSuccess: visitTarget,
        },
    );
};

const markAllNotificationsRead = () => {
    router.post(
        readAllNotifications.url(),
        {},
        { preserveScroll: true, preserveState: true },
    );
};

const endImpersonation = () => {
    router.post(stopImpersonation.url());
};

const changeLocale = (nextLocale: 'de' | 'en') => {
    router.post(
        updateLocale.url(),
        { locale: nextLocale },
        {
            preserveScroll: true,
            onSuccess: () => {
                locale.value = nextLocale;
            },
        },
    );
};

const signOut = () => {
    router.flushAll();
    router.post(logout.url());
};
</script>

<template>
    <div
        v-if="impersonation?.active"
        role="status"
        aria-live="polite"
        class="flex min-h-11 items-center justify-center gap-3 bg-amber-50 px-4 py-2 text-center text-xs font-medium text-amber-900 ring-1 ring-amber-200 ring-inset"
    >
        <ShieldCheck class="size-4 shrink-0 text-amber-600" />
        <span>
            <strong>{{ t('shell.supportView') }}:</strong>
            {{
                t('shell.supportViewText', {
                    account: impersonation.actor_name ?? t('roles.support'),
                })
            }}
            <span v-if="impersonation.reason" class="ml-1">
                {{
                    t('shell.supportViewReason', {
                        reason: impersonation.reason,
                    })
                }}
            </span>
        </span>
        <button
            type="button"
            class="ml-2 inline-flex items-center gap-1 font-bold text-amber-700 hover:text-amber-900"
            @click="endImpersonation"
        >
            <X class="size-3.5" />
            {{ t('shell.endSupportView') }}
        </button>
    </div>

    <header
        class="sticky top-0 z-20 flex h-[68px] shrink-0 items-center border-b border-slate-200 bg-white/95 px-4 backdrop-blur sm:px-6"
    >
        <div class="flex w-full items-center gap-3">
            <SidebarTrigger
                class="-ml-1 rounded-lg text-slate-500 hover:bg-slate-100"
                :aria-label="t('shell.menu')"
            />
            <div class="hidden h-5 w-px bg-slate-200 sm:block" />
            <Breadcrumbs
                v-if="breadcrumbs.length > 0"
                :breadcrumbs="breadcrumbs"
                class="hidden lg:flex"
            />
            <div class="relative ml-0 max-w-xl flex-1 lg:ml-3">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
                />
                <input
                    type="search"
                    :aria-label="t('shell.searchLabel')"
                    :placeholder="t('shell.searchPlaceholder')"
                    class="erin-focus h-10 w-full rounded-xl border border-slate-200 bg-slate-50 pr-4 pl-10 text-sm text-slate-900 placeholder:text-slate-400 hover:border-slate-300 focus:bg-white"
                />
                <kbd
                    class="absolute top-1/2 right-3 hidden -translate-y-1/2 rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-semibold text-slate-400 sm:block"
                >
                    ⌘ K
                </kbd>
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="erin-focus relative grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-slate-500 hover:bg-slate-50"
                        :aria-label="t('shell.openNotifications')"
                    >
                        <Bell class="size-[18px]" />
                        <span
                            v-if="notifications.unread_count > 0"
                            class="absolute top-1.5 right-1.5 size-2 rounded-full bg-[var(--erin-accent,#F97316)] ring-2 ring-white"
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    :side-offset="10"
                    class="w-[min(24rem,calc(100vw-2rem))] rounded-2xl p-0 shadow-2xl"
                >
                    <DropdownMenuLabel
                        class="flex items-center justify-between px-4 py-3.5"
                    >
                        <span class="font-bold text-slate-950">
                            {{ t('shell.notifications') }}
                        </span>
                        <span
                            v-if="notifications.unread_count > 0"
                            class="rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-bold text-orange-700"
                        >
                            {{
                                t('shell.newNotifications', {
                                    count: notifications.unread_count,
                                })
                            }}
                        </span>
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator class="m-0" />

                    <div
                        v-if="notifications.items.length === 0"
                        class="px-4 py-8 text-center text-sm text-slate-500"
                    >
                        {{ t('shell.noNotifications') }}
                    </div>
                    <DropdownMenuItem
                        v-for="notification in notifications.items"
                        :key="notification.id"
                        class="items-start gap-3 rounded-none border-b border-slate-100 px-4 py-3 last:border-0"
                        @click="openNotification(notification)"
                    >
                        <span
                            class="mt-1.5 size-2 shrink-0 rounded-full"
                            :class="
                                notification.read_at
                                    ? 'bg-slate-200'
                                    : 'bg-[var(--erin-primary,#2563EB)]'
                            "
                        />
                        <span class="min-w-0 flex-1">
                            <span
                                class="block text-sm font-semibold text-slate-900"
                            >
                                {{ notificationTitle(notification) }}
                            </span>
                            <span
                                v-if="notificationDetail(notification)"
                                class="mt-0.5 block truncate text-xs text-slate-500"
                            >
                                {{ notificationDetail(notification) }}
                            </span>
                            <span
                                v-if="notification.created_at"
                                class="mt-1 block text-[10px] text-slate-400"
                            >
                                {{ notificationDate(notification.created_at) }}
                            </span>
                        </span>
                        <Check
                            class="mt-1 size-3.5"
                            :class="
                                notification.read_at
                                    ? 'text-emerald-500'
                                    : 'text-slate-300'
                            "
                        />
                    </DropdownMenuItem>

                    <button
                        v-if="notifications.unread_count > 0"
                        type="button"
                        class="block w-full border-t border-slate-100 px-4 py-3 text-center text-xs font-bold text-[var(--erin-primary,#2563EB)] hover:bg-slate-50"
                        @click="markAllNotificationsRead"
                    >
                        {{ t('shell.markAllRead') }}
                    </button>
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="erin-focus hidden h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-2 sm:flex"
                    >
                        <span
                            class="grid size-7 place-items-center rounded-lg bg-blue-100 text-xs font-bold text-[var(--erin-primary,#2563EB)]"
                        >
                            {{ user?.name?.slice(0, 2).toUpperCase() ?? 'ER' }}
                        </span>
                        <span
                            class="hidden max-w-28 truncate text-xs font-semibold text-slate-700 xl:block"
                        >
                            {{ user?.name ?? roleLabel }}
                        </span>
                        <ChevronDown class="size-3.5 text-slate-400" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    :side-offset="10"
                    class="w-56 rounded-xl"
                >
                    <DropdownMenuLabel>
                        <span class="block text-sm font-bold">
                            {{ user?.name ?? t('shell.userFallback') }}
                        </span>
                        <span
                            class="block truncate text-xs font-normal text-slate-400"
                        >
                            {{ user?.email }}
                        </span>
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem as-child>
                        <Link :href="settingsUrl">
                            {{ t('shell.profileSettings') }}
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="role === 'super_admin' || role === 'support'"
                        as-child
                    >
                        <Link :href="supportUrl">
                            {{ t('shell.helpSupport') }}
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuLabel
                        class="flex items-center gap-2 text-xs text-slate-500"
                    >
                        <Languages class="size-3.5" />
                        {{ t('shell.language') }}
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        :disabled="locale === 'de'"
                        @click="changeLocale('de')"
                    >
                        {{ t('shell.german') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        :disabled="locale === 'en'"
                        @click="changeLocale('en')"
                    >
                        {{ t('shell.english') }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        class="cursor-pointer text-rose-600 focus:text-rose-700"
                        data-test="product-logout-button"
                        @click="signOut"
                    >
                        <LogOut class="size-3.5" />
                        {{ t('shell.logout') }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </header>
</template>
