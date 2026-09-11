<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogo from '@/components/AppLogo.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useProductNavigation } from '@/composables/useProductNavigation';

const { t } = useI18n();
const page = usePage();
const { navigation, role, roleLabel } = useProductNavigation();
const { currentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const impersonation = computed(
    () => page.props.impersonation as { active?: boolean } | null | undefined,
);

const isActive = (href: string) => {
    if (href === '/dashboard' || href === '/admin') {
        return currentUrl.value === href;
    }

    return isCurrentOrParentUrl(href);
};
</script>

<template>
    <Sidebar
        collapsible="icon"
        variant="sidebar"
        class="border-r border-border"
    >
        <SidebarHeader class="border-b border-border px-3 py-3.5">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        as-child
                        class="h-12 hover:bg-muted"
                    >
                        <Link
                            :href="
                                role === 'super_admin' || role === 'support'
                                    ? '/admin'
                                    : '/dashboard'
                            "
                        >
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <div
                class="mx-2 mt-1 rounded-lg bg-muted px-3 py-2 text-[10px] font-bold tracking-wider text-muted-foreground uppercase group-data-[collapsible=icon]:hidden"
            >
                {{ t('shell.area') }}:
                <span class="text-[var(--erin-primary-text)]">
                    {{ roleLabel }}
                </span>
            </div>
        </SidebarHeader>

        <SidebarContent class="px-2 py-3">
            <nav :aria-label="t('shell.mainNavigation')">
                <SidebarGroup
                    v-for="(group, groupIndex) in navigation"
                    :key="group.label ?? groupIndex"
                    class="p-0"
                >
                    <SidebarGroupLabel
                        v-if="group.label"
                        class="mt-3 h-8 px-2 text-[10px] font-bold tracking-[0.12em] text-muted-foreground uppercase"
                    >
                        {{ group.label }}
                    </SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu class="gap-1">
                            <SidebarMenuItem
                                v-for="item in group.items"
                                :key="item.href"
                            >
                                <SidebarMenuButton
                                    as-child
                                    :tooltip="item.label"
                                    :is-active="isActive(item.href)"
                                    class="h-10 rounded-xl px-3 text-muted-foreground transition hover:bg-muted hover:text-foreground data-[active=true]:bg-blue-50 data-[active=true]:font-bold data-[active=true]:text-[var(--erin-primary-text)] dark:data-[active=true]:bg-blue-950/40"
                                >
                                    <Link :href="item.href">
                                        <component
                                            :is="item.icon"
                                            class="size-[18px]"
                                        />
                                        <span>{{ item.label }}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>
            </nav>
        </SidebarContent>

        <SidebarFooter
            v-if="impersonation?.active"
            class="border-t border-amber-200 bg-amber-50/80 p-3"
        >
            <NavUser />
        </SidebarFooter>
    </Sidebar>
</template>
