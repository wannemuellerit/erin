<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useAdminI18n } from '../_i18n';
import StatusBadge from '@/components/product/StatusBadge.vue';

type Version = {
    id: number;
    version: string;
    weights: Record<string, number>;
    status: string;
    activation_reason: string | null;
    activated_at: string | null;
};

defineProps<{ versions: Version[] }>();
const { t } = useAdminI18n();
const factors = [
    'profession',
    'skills',
    'language',
    'experience',
    'employment',
    'availability',
    'salary',
    'relocation',
    'documents',
];
const form = useForm({
    version: '',
    weights: {
        profession: 25,
        skills: 20,
        language: 15,
        experience: 10,
        employment: 10,
        availability: 5,
        salary: 5,
        relocation: 5,
        documents: 5,
    } as Record<string, number>,
});
const total = computed(() =>
    Object.values(form.weights).reduce((sum, value) => sum + Number(value), 0),
);
const activation = useForm({ reason: '', confirmation: 'ACTIVATE' });

function createVersion(): void {
    form.post('/admin/system/match-score-versions', { preserveScroll: true });
}

function activate(id: number): void {
    activation.post(`/admin/system/match-score-versions/${id}/activate`, {
        preserveScroll: true,
        onSuccess: () => activation.reset('reason'),
    });
}
</script>

<template>
    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(22rem,.8fr)]">
        <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="createVersion">
            <label class="sm:col-span-3">
                <span class="text-xs font-bold text-muted-foreground">{{
                    t('system.matchScore.version')
                }}</span>
                <input
                    v-model="form.version"
                    required
                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-border px-3 text-sm"
                />
            </label>
            <label v-for="factor in factors" :key="factor">
                <span class="text-xs font-bold text-muted-foreground">{{
                    t(`system.matchScore.factors.${factor}`)
                }}</span>
                <input
                    v-model.number="form.weights[factor]"
                    type="number"
                    min="0"
                    max="100"
                    required
                    class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-border px-3 text-sm"
                />
            </label>
            <div class="flex items-center gap-3 sm:col-span-3">
                <span
                    class="text-sm font-bold"
                    :class="total === 100 ? 'text-emerald-700' : 'text-red-700'"
                    >{{ t('system.matchScore.total') }}: {{ total }}</span
                >
                <button
                    type="submit"
                    :disabled="form.processing || total !== 100"
                    class="erin-focus ml-auto h-10 rounded-xl bg-blue-600 px-5 text-xs font-bold text-white disabled:opacity-50"
                >
                    {{ t('system.matchScore.create') }}
                </button>
            </div>
            <p
                v-if="Object.values(form.errors)[0]"
                class="text-xs text-red-600 sm:col-span-3"
            >
                {{ Object.values(form.errors)[0] }}
            </p>
        </form>

        <div class="space-y-3">
            <article
                v-for="version in versions"
                :key="version.id"
                class="rounded-xl border border-border p-4"
            >
                <div class="flex items-center justify-between gap-3">
                    <strong>{{ version.version }}</strong>
                    <StatusBadge
                        :label="version.status"
                        :tone="version.status === 'active' ? 'green' : 'slate'"
                    />
                </div>
                <p class="mt-2 text-xs text-muted-foreground">
                    {{
                        Object.entries(version.weights)
                            .map(([key, value]) => `${key}: ${value}`)
                            .join(' · ')
                    }}
                </p>
                <form
                    v-if="version.status !== 'active'"
                    class="mt-3 flex gap-2"
                    @submit.prevent="activate(version.id)"
                >
                    <input
                        v-model="activation.reason"
                        required
                        minlength="10"
                        :placeholder="t('system.matchScore.reason')"
                        class="erin-focus h-9 min-w-0 flex-1 rounded-lg border border-border px-3 text-xs"
                    />
                    <button
                        type="submit"
                        :disabled="activation.processing"
                        class="erin-focus rounded-lg border border-blue-200 px-3 text-xs font-bold text-[var(--erin-primary-text-hover)]"
                    >
                        {{ t('system.matchScore.activate') }}
                    </button>
                </form>
            </article>
        </div>
    </div>
</template>
