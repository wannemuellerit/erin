<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Megaphone } from '@lucide/vue';
import { computed } from 'vue';
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';

type DashboardAd = {
    id: number;
    title: string;
    body: string;
    cta_label: string;
    url: string | null;
    media_url: string | null;
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

function record(type: 'impression' | 'click'): void {
    if (!ad.value || ad.value.id === 0) {
        return;
    }

    void fetch(`/ads/${ad.value.id}/${type}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'X-CSRF-TOKEN':
                document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content ?? '',
            Accept: 'application/json',
        },
        keepalive: true,
    });
}

onMounted(() => record('impression'));
</script>

<template>
    <aside
        v-if="ad"
        class="erin-panel overflow-hidden border-orange-200 bg-gradient-to-r from-orange-50 via-white to-teal-50 p-6"
        :aria-label="t('label')"
    >
        <div class="flex items-start gap-4">
            <img
                v-if="ad.media_url"
                :src="ad.media_url"
                alt=""
                class="h-28 w-40 rounded-2xl object-cover"
            />
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
                    @click="record('click')"
                    rel="noopener noreferrer"
                    class="erin-focus mt-4 inline-flex h-10 items-center rounded-xl bg-orange-500 px-4 text-sm font-bold text-white hover:bg-orange-600"
                >
                    {{ ad.cta_label }}
                </a>
            </div>
        </div>
    </aside>
</template>
