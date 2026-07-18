<script setup lang="ts">
import { KeyRound } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { DemoAccount, DemoAccountRole } from '@/types/demo';

const props = defineProps<{
    accounts: DemoAccount[];
    password: string;
}>();

const emit = defineEmits<{
    select: [account: DemoAccount];
}>();

const { t } = useI18n();

const roleOrder: DemoAccountRole[] = ['super_admin', 'employer', 'candidate'];

const accountGroups = computed(() =>
    roleOrder
        .map((role) => ({
            role,
            accounts: props.accounts.filter((account) => account.role === role),
        }))
        .filter((group) => group.accounts.length > 0),
);
</script>

<template>
    <section
        class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4"
        aria-labelledby="demo-access-title"
        data-test="demo-account-picker"
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:justify-between">
            <div>
                <h2
                    id="demo-access-title"
                    class="text-sm font-extrabold text-slate-950"
                >
                    {{ t('auth.demoAccess') }}
                </h2>
                <p class="mt-1 text-xs leading-5 text-slate-600">
                    {{ t('auth.demoAccessDescription') }}
                </p>
            </div>

            <div
                class="flex shrink-0 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2"
            >
                <KeyRound
                    class="size-4 text-[var(--erin-primary,#2563EB)]"
                    aria-hidden="true"
                />
                <div>
                    <p class="text-[10px] font-bold text-slate-500 uppercase">
                        {{ t('auth.demoSharedPassword') }}
                    </p>
                    <code
                        class="block font-mono text-xs font-bold text-slate-950"
                        data-test="demo-password"
                        >{{ password }}</code
                    >
                </div>
            </div>
        </div>

        <div class="mt-4 space-y-4">
            <section
                v-for="group in accountGroups"
                :key="group.role"
                :aria-labelledby="`demo-group-${group.role}`"
            >
                <div class="mb-2 flex items-center gap-2">
                    <h3
                        :id="`demo-group-${group.role}`"
                        class="text-xs font-extrabold text-slate-800"
                    >
                        {{ t(`roles.${group.role}`) }}
                    </h3>
                    <Badge
                        variant="outline"
                        class="bg-white text-[10px] text-slate-700"
                    >
                        {{
                            t('auth.demoAccountCount', {
                                count: group.accounts.length,
                            })
                        }}
                    </Badge>
                </div>

                <ul class="grid gap-2" role="list">
                    <li
                        v-for="account in group.accounts"
                        :key="account.id"
                        class="flex min-w-0 items-start gap-3 rounded-xl border border-slate-200 bg-white p-2.5"
                        :data-test="`demo-account-${account.id}`"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-bold text-slate-900">
                                {{ account.name }}
                            </p>
                            <code
                                class="block font-mono text-[11px] leading-4 break-all text-slate-600"
                                :data-test="`demo-email-${account.id}`"
                                >{{ account.email }}</code
                            >
                        </div>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="shrink-0 self-center hover:text-slate-950"
                            :aria-label="
                                t('auth.insertDemoAccount', {
                                    name: account.name,
                                })
                            "
                            :data-test="`insert-demo-account-${account.id}`"
                            @click="emit('select', account)"
                        >
                            {{ t('auth.insert') }}
                        </Button>
                    </li>
                </ul>
            </section>
        </div>
    </section>
</template>
