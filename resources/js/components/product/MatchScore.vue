<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import de from '@/i18n/messages/product-components-de';
import en from '@/i18n/messages/product-components-en';

const props = withDefaults(
    defineProps<{
        score: number;
        size?: 'sm' | 'md' | 'lg';
        label?: string;
    }>(),
    {
        size: 'md',
        label: '',
    },
);
const { t } = useI18n({
    useScope: 'local',
    messages: { de, en },
});
const resolvedLabel = computed(() => props.label || t('matchScore.label'));

const safeScore = computed(() => Math.max(0, Math.min(100, props.score)));
const scoreColor = computed(() => {
    if (safeScore.value >= 85) {
        return '#14B8A6';
    }

    if (safeScore.value >= 70) {
        return '#2563EB';
    }

    return '#F97316';
});
const dimensions = {
    sm: 'size-14',
    md: 'size-[4.5rem]',
    lg: 'size-24',
};
</script>

<template>
    <div
        class="relative grid shrink-0 place-items-center rounded-full"
        :class="dimensions[size]"
        :style="{
            background: `conic-gradient(${scoreColor} ${safeScore * 3.6}deg, #e2e8f0 0deg)`,
        }"
    >
        <div
            class="absolute inset-[5px] grid place-items-center rounded-full bg-white"
        >
            <div class="text-center">
                <div
                    class="font-extrabold tracking-tight text-slate-950"
                    :class="
                        size === 'sm'
                            ? 'text-sm'
                            : size === 'lg'
                              ? 'text-xl'
                              : 'text-base'
                    "
                >
                    {{ safeScore }}%
                </div>
                <div
                    v-if="size !== 'sm'"
                    class="text-[9px] font-semibold text-slate-400 uppercase"
                >
                    {{ resolvedLabel }}
                </div>
            </div>
        </div>
    </div>
</template>
