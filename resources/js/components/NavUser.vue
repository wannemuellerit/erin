<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { SidebarMenu, SidebarMenuItem } from '@/components/ui/sidebar';
import UserInfo from '@/components/UserInfo.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const { t } = useI18n();
</script>

<template>
    <p
        class="mb-1.5 px-2 text-[10px] font-extrabold tracking-[0.12em] text-amber-800 uppercase group-data-[collapsible=icon]:hidden"
    >
        {{ t('shell.loggedInAs') }}
    </p>
    <SidebarMenu>
        <SidebarMenuItem>
            <div
                data-test="sidebar-impersonated-user"
                class="flex h-11 items-center gap-2 rounded-xl border border-amber-200 bg-card px-2 text-amber-950 shadow-sm group-data-[collapsible=icon]:size-8 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:border-0 group-data-[collapsible=icon]:bg-transparent group-data-[collapsible=icon]:p-0 group-data-[collapsible=icon]:shadow-none group-data-[collapsible=icon]:[&>div:last-child]:hidden"
            >
                <UserInfo :user="user" />
            </div>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
