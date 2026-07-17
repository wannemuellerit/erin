<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
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
const { navigation, role, roleLabel } = useProductNavigation();
const { currentUrl, isCurrentOrParentUrl } = useCurrentUrl();

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
        class="border-r border-slate-200"
    >
        <SidebarHeader class="border-b border-slate-100 px-3 py-3.5">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        as-child
                        class="h-12 hover:bg-slate-50"
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
                class="mx-2 mt-1 rounded-lg bg-slate-50 px-3 py-2 text-[10px] font-bold tracking-wider text-slate-500 uppercase group-data-[collapsible=icon]:hidden"
            >
                {{ t('shell.area') }}:
                <span class="text-[var(--erin-primary,#2563EB)]">
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
                        class="mt-3 h-8 px-2 text-[10px] font-bold tracking-[0.12em] text-slate-400 uppercase"
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
                                    class="h-10 rounded-xl px-3 text-slate-500 transition hover:bg-slate-50 hover:text-slate-900 data-[active=true]:bg-blue-50 data-[active=true]:font-bold data-[active=true]:text-[var(--erin-primary,#2563EB)]"
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

        <SidebarFooter class="border-t border-slate-100 p-3">
            <NavUser />
        </SidebarFooter>
    </Sidebar>
</template>
