<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Megaphone } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

type DashboardAd = {
    title: string;
    body: string;
    cta_label: string;
    url: string | null;
};

const page = usePage();
const { t } = useI18n({
    useScope: 'local',
    messages: {
        de: { label: 'Anzeige' },
        en: { label: 'Advertisement' },
    },
});
const ad = computed(
    () =>
        (
            page.props.platform as {
                dashboard_ad?: DashboardAd | null;
            }
        )?.dashboard_ad ?? null,
);
</script>

<template>
    <aside
        v-if="ad"
        class="erin-panel overflow-hidden border-orange-200 bg-gradient-to-r from-orange-50 via-white to-teal-50 p-6"
        :aria-label="t('label')"
    >
        <div class="flex items-start gap-4">
            <span
                class="grid size-11 shrink-0 place-items-center rounded-2xl bg-orange-100 text-orange-600"
            >
                <Megaphone class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
                <p
                    class="text-[10px] font-bold tracking-[0.16em] text-orange-700 uppercase"
                >
                    {{ t('label') }}
                </p>
                <h2 class="mt-1 text-lg font-extrabold text-slate-900">
                    {{ ad.title }}
                </h2>
                <p class="mt-1 text-sm leading-6 text-slate-600">
                    {{ ad.body }}
                </p>
                <a
                    v-if="ad.url && ad.cta_label"
                    :href="ad.url"
                    rel="noopener noreferrer"
                    class="erin-focus mt-4 inline-flex h-10 items-center rounded-xl bg-orange-500 px-4 text-sm font-bold text-white hover:bg-orange-600"
                >
                    {{ ad.cta_label }}
                </a>
            </div>
        </div>
    </aside>
</template>
