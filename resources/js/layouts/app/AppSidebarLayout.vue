<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const themeStyle = computed(() => {
    const theme = (page.props.theme ?? {}) as Record<string, string>;

    return {
        '--erin-primary': theme.primary,
        '--erin-primary-hover': theme.primary_hover,
        '--erin-secondary': theme.secondary,
        '--erin-accent': theme.accent,
        '--erin-success': theme.success,
        '--erin-warning': theme.warning,
        '--erin-error': theme.error,
        '--erin-info': theme.info,
        '--primary': theme.primary,
        '--secondary': theme.secondary,
        '--accent': theme.accent,
        '--background': theme.background,
        '--card': theme.surface,
        '--popover': theme.surface,
        '--muted': theme.surface_hover,
        '--border': theme.border,
        '--input': theme.divider,
        '--foreground': theme.text,
        '--card-foreground': theme.text,
        '--popover-foreground': theme.text,
        '--muted-foreground': theme.text_muted,
    };
});
</script>

<template>
    <AppShell variant="sidebar" :style="themeStyle">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
