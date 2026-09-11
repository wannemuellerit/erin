<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import RequestFeedback from '@/components/RequestFeedback.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();

const readableTextColor = (background?: string): '#0f172a' | '#ffffff' => {
    const hex = (background ?? '').trim().replace(/^#/, '');
    const normalized =
        hex.length === 3
            ? hex
                  .split('')
                  .map((character) => character.repeat(2))
                  .join('')
            : hex;

    if (!/^[\da-f]{6}$/i.test(normalized)) {
        return '#ffffff';
    }

    const channels = [0, 2, 4].map((offset) => {
        const value =
            Number.parseInt(normalized.slice(offset, offset + 2), 16) / 255;

        return value <= 0.04045
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    });
    const luminance =
        0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];

    return (luminance + 0.05) / 0.05 >= 4.5 ? '#0f172a' : '#ffffff';
};

const themeStyle = computed(() => {
    const theme = (page.props.theme ?? {}) as Record<string, string>;

    return {
        '--erin-primary': theme.primary,
        '--erin-primary-foreground': readableTextColor(theme.primary),
        '--erin-primary-hover': theme.primary_hover,
        '--erin-secondary': theme.secondary,
        '--erin-accent': theme.accent,
        '--erin-accent-foreground': readableTextColor(theme.accent),
        '--erin-success': theme.success,
        '--erin-warning': theme.warning,
        '--erin-error': theme.error,
        '--erin-info': theme.info,
        '--primary': theme.primary,
        '--secondary': theme.secondary,
        '--accent': theme.accent,
    };
});
</script>

<template>
    <AppShell variant="sidebar" class="erin-theme-scope" :style="themeStyle">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <RequestFeedback />
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
