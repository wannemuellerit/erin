<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const { t } = useI18n();
</script>

<template>
    <AppLogoIcon class="size-9 shrink-0" />
    <div class="ml-1.5 grid flex-1 text-left">
        <span class="truncate text-base leading-tight font-bold text-slate-950">
            erin<span class="text-blue-600">.</span>
        </span>
        <span
            class="truncate text-[10px] font-semibold tracking-[0.12em] text-slate-600 uppercase"
        >
            {{ t('public.common.recruitingOs') }}
        </span>
    </div>
</template>
