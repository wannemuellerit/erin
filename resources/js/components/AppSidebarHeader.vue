<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { useEchoNotification } from '@laravel/echo-vue';
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
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
import { useFormatters } from '@/composables/useFormatters';
import { useProductNavigation } from '@/composables/useProductNavigation';
import { localeNames, supportedLocales } from '@/i18n';
import type { SupportedLocale } from '@/i18n';
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
const { formatDate } = useFormatters();
const { role, roleLabel } = useProductNavigation();

type SearchResult = {
    type: string;
    label: string;
    subtitle: string;
    url: string;
};

const searchInput = ref<HTMLInputElement | null>(null);
const searchQuery = ref('');
const searchGroups = ref<Record<string, SearchResult[]>>({});
const searchOpen = ref(false);
const searchLoading = ref(false);
const activeSearchIndex = ref(0);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let searchAbort: AbortController | undefined;
const flatSearchResults = computed(() =>
    Object.values(searchGroups.value).flat(),
);

watch(searchQuery, (value) => {
    clearTimeout(searchTimer);
    searchAbort?.abort();
    const query = value.trim();

    if (query.length < 2) {
        searchGroups.value = {};
        searchOpen.value = query.length > 0;

        return;
    }

    searchTimer = setTimeout(async () => {
        searchAbort = new AbortController();
        searchLoading.value = true;

        try {
            const response = await fetch(
                `/search?q=${encodeURIComponent(query)}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: searchAbort.signal,
                },
            );

            if (!response.ok) {
                throw new Error('search failed');
            }

            const payload = (await response.json()) as {
                groups: Record<string, SearchResult[]>;
            };
            searchGroups.value = payload.groups;
            activeSearchIndex.value = 0;
            searchOpen.value = true;
        } catch (error) {
            if (!(
                error instanceof DOMException && error.name === 'AbortError'
            )) {
                searchGroups.value = {};
            }
        } finally {
            searchLoading.value = false;
        }
    }, 250);
});

const openSearchResult = (result: SearchResult) => {
    searchOpen.value = false;
    searchQuery.value = '';
    router.visit(result.url);
};

const handleSearchKey = (event: KeyboardEvent) => {
    if (event.key === 'ArrowDown' && flatSearchResults.value.length > 0) {
        event.preventDefault();
        activeSearchIndex.value =
            (activeSearchIndex.value + 1) % flatSearchResults.value.length;
    } else if (event.key === 'ArrowUp' && flatSearchResults.value.length > 0) {
        event.preventDefault();
        activeSearchIndex.value =
            (activeSearchIndex.value - 1 + flatSearchResults.value.length) %
            flatSearchResults.value.length;
    } else if (
        event.key === 'Enter' &&
        flatSearchResults.value[activeSearchIndex.value]
    ) {
        event.preventDefault();
        openSearchResult(flatSearchResults.value[activeSearchIndex.value]);
    } else if (event.key === 'Escape') {
        searchOpen.value = false;
        searchInput.value?.blur();
    }
};

const handleGlobalShortcut = (event: KeyboardEvent) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        searchInput.value?.focus();
        searchOpen.value = true;
    }
};

onMounted(() => window.addEventListener('keydown', handleGlobalShortcut));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleGlobalShortcut);
    clearTimeout(searchTimer);
    searchAbort?.abort();
});

const impersonation = computed(
    () => page.props.impersonation as Impersonation | null | undefined,
);
const user = computed(() => page.props.auth?.user);
const sharedNotifications = (): NotificationFeed => {
    const shared = page.props.notifications as NotificationFeed | undefined;

    return shared ?? { unread_count: 0, items: [] };
};
const liveNotifications = ref<NotificationFeed>(sharedNotifications());
const notifications = computed<NotificationFeed>(() => liveNotifications.value);

watch(
    () => page.props.notifications,
    () => {
        liveNotifications.value = sharedNotifications();
    },
);

useEchoNotification<Record<string, unknown>>(
    `App.Models.User.${user.value?.id ?? 0}`,
    (payload) => {
        const nested =
            payload.data && typeof payload.data === 'object'
                ? (payload.data as SharedNotification['data'])
                : (payload as SharedNotification['data']);
        const id =
            typeof payload.id === 'string'
                ? payload.id
                : `live-${Date.now().toString()}`;

        if (
            liveNotifications.value.items.some(
                (notification) => notification.id === id,
            )
        ) {
            return;
        }

        liveNotifications.value = {
            unread_count: liveNotifications.value.unread_count + 1,
            items: [
                {
                    id,
                    type:
                        typeof payload.type === 'string'
                            ? payload.type
                            : undefined,
                    data: nested,
                    read_at: null,
                    created_at: new Date().toISOString(),
                },
                ...liveNotifications.value.items,
            ].slice(0, 8),
        };
    },
);

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

const notificationDate = (createdAt?: string | null) =>
    formatDate(
        createdAt,
        {
            dateStyle: 'medium',
            timeStyle: 'short',
        },
        '',
    );

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

const changeLocale = (nextLocale: SupportedLocale) => {
    router.post(
        updateLocale.url(),
        { locale: nextLocale },
        {
            preserveScroll: true,
            onSuccess: () => {
                locale.value = nextLocale;
                document.documentElement.lang = nextLocale;
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
        class="sticky top-0 z-20 flex h-[68px] shrink-0 items-center border-b border-border bg-card/95 px-4 backdrop-blur sm:px-6"
    >
        <div
            class="grid w-full grid-cols-[auto_minmax(0,1fr)] items-center gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(32rem,48rem)_minmax(0,1fr)]"
        >
            <div class="flex min-w-0 items-center gap-3 overflow-hidden">
                <SidebarTrigger
                    class="-ml-1 shrink-0 rounded-lg text-muted-foreground hover:bg-muted"
                    :aria-label="t('shell.menu')"
                />
                <div class="hidden h-5 w-px shrink-0 bg-border sm:block" />
                <Breadcrumbs
                    v-if="breadcrumbs.length > 0"
                    :breadcrumbs="breadcrumbs"
                    class="hidden min-w-0 lg:flex"
                />
            </div>

            <div
                class="col-start-2 flex w-full min-w-0 items-center gap-3 justify-self-center"
            >
                <div class="relative min-w-0 flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        ref="searchInput"
                        data-test="global-search-input"
                        v-model="searchQuery"
                        type="search"
                        :aria-label="t('shell.searchLabel')"
                        :placeholder="t('shell.searchPlaceholder')"
                        class="erin-focus h-10 w-full rounded-xl border border-border bg-muted pr-4 pl-10 text-sm text-foreground placeholder:text-muted-foreground hover:border-border focus:bg-card"
                        role="combobox"
                        aria-autocomplete="list"
                        :aria-expanded="searchOpen"
                        aria-controls="global-search-results"
                        @focus="searchOpen = searchQuery.length > 0"
                        @keydown="handleSearchKey"
                    />
                    <kbd
                        class="absolute top-1/2 right-3 hidden -translate-y-1/2 rounded border border-border bg-card px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground sm:block"
                    >
                        ⌘ K
                    </kbd>
                    <div
                        v-if="searchOpen"
                        id="global-search-results"
                        data-test="global-search-results"
                        role="listbox"
                        class="absolute top-12 right-0 left-0 z-50 max-h-[70vh] overflow-auto rounded-2xl border border-border bg-card p-2 shadow-2xl"
                    >
                        <p
                            v-if="searchQuery.trim().length < 2"
                            class="px-3 py-4 text-sm text-muted-foreground"
                        >
                            {{ t('shell.searchMinimum') }}
                        </p>
                        <p
                            v-else-if="searchLoading"
                            class="px-3 py-4 text-sm text-muted-foreground"
                            role="status"
                        >
                            {{ t('shell.searchLoading') }}
                        </p>
                        <template v-else>
                            <section
                                v-for="(results, group) in searchGroups"
                                :key="group"
                                class="mb-2 last:mb-0"
                            >
                                <h2
                                    v-if="results.length > 0"
                                    class="px-3 py-1 text-[10px] font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    {{ t(`shell.searchGroups.${group}`) }}
                                </h2>
                                <button
                                    v-for="result in results"
                                    :key="`${result.type}-${result.url}-${result.label}`"
                                    type="button"
                                    :data-test="`global-search-result-${result.type}`"
                                    role="option"
                                    :aria-selected="
                                        flatSearchResults[activeSearchIndex] ===
                                        result
                                    "
                                    class="erin-focus flex w-full items-start gap-3 rounded-xl px-3 py-2 text-left hover:bg-muted"
                                    :class="
                                        flatSearchResults[activeSearchIndex] ===
                                        result
                                            ? 'bg-blue-50'
                                            : ''
                                    "
                                    @mouseenter="
                                        activeSearchIndex =
                                            flatSearchResults.indexOf(result)
                                    "
                                    @click="openSearchResult(result)"
                                >
                                    <Search
                                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                    />
                                    <span class="min-w-0">
                                        <span
                                            class="block truncate text-sm font-semibold text-foreground"
                                            >{{ result.label }}</span
                                        >
                                        <span
                                            v-if="result.subtitle"
                                            class="block truncate text-xs text-muted-foreground"
                                            >{{ result.subtitle }}</span
                                        >
                                    </span>
                                </button>
                            </section>
                            <p
                                v-if="flatSearchResults.length === 0"
                                class="px-3 py-4 text-sm text-muted-foreground"
                            >
                                {{ t('shell.searchEmpty') }}
                            </p>
                        </template>
                    </div>
                </div>

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="erin-focus relative grid size-10 shrink-0 place-items-center rounded-xl border border-border bg-card text-muted-foreground hover:bg-muted"
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
                            <span class="font-bold text-foreground">
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
                            class="px-4 py-8 text-center text-sm text-muted-foreground"
                        >
                            {{ t('shell.noNotifications') }}
                        </div>
                        <DropdownMenuItem
                            v-for="notification in notifications.items"
                            :key="notification.id"
                            class="items-start gap-3 rounded-none border-b border-border px-4 py-3 last:border-0"
                            @click="openNotification(notification)"
                        >
                            <span
                                class="mt-1.5 size-2 shrink-0 rounded-full"
                                :class="
                                    notification.read_at
                                        ? 'bg-border'
                                        : 'bg-[var(--erin-primary,#2563EB)]'
                                "
                            />
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block text-sm font-semibold text-foreground"
                                >
                                    {{ notificationTitle(notification) }}
                                </span>
                                <span
                                    v-if="notificationDetail(notification)"
                                    class="mt-0.5 block truncate text-xs text-muted-foreground"
                                >
                                    {{ notificationDetail(notification) }}
                                </span>
                                <span
                                    v-if="notification.created_at"
                                    class="mt-1 block text-[10px] text-muted-foreground"
                                >
                                    {{
                                        notificationDate(
                                            notification.created_at,
                                        )
                                    }}
                                </span>
                            </span>
                            <Check
                                class="mt-1 size-3.5"
                                :class="
                                    notification.read_at
                                        ? 'text-emerald-500'
                                        : 'text-muted-foreground'
                                "
                            />
                        </DropdownMenuItem>

                        <button
                            v-if="notifications.unread_count > 0"
                            type="button"
                            class="block w-full border-t border-border px-4 py-3 text-center text-xs font-bold text-[var(--erin-primary-text)] hover:bg-muted"
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
                            data-test="header-profile-menu"
                            class="erin-focus hidden h-10 items-center gap-2 rounded-xl border border-border bg-card px-2 sm:flex"
                        >
                            <span
                                class="grid size-7 place-items-center rounded-lg bg-muted text-xs font-bold text-foreground"
                            >
                                {{
                                    user?.name?.slice(0, 2).toUpperCase() ??
                                    'ER'
                                }}
                            </span>
                            <span
                                class="hidden max-w-28 truncate text-xs font-semibold text-muted-foreground xl:block"
                            >
                                {{ user?.name ?? roleLabel }}
                            </span>
                            <ChevronDown
                                class="size-3.5 text-muted-foreground"
                            />
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
                                class="block truncate text-xs font-normal text-muted-foreground"
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
                            class="flex items-center gap-2 text-xs text-muted-foreground"
                        >
                            <Languages class="size-3.5" />
                            {{ t('shell.language') }}
                        </DropdownMenuLabel>
                        <DropdownMenuItem
                            v-for="language in supportedLocales"
                            :key="language"
                            :disabled="locale === language"
                            @click="changeLocale(language)"
                        >
                            {{ localeNames[language] }}
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

            <div class="hidden xl:block" aria-hidden="true" />
        </div>
    </header>
</template>
