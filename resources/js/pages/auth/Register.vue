<script setup lang="ts">
import { Form, Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { BriefcaseBusiness, Check, UserRound } from '@lucide/vue';
import { ref, watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    passwordRules: string;
}>();

const page = usePage();
const query = new URLSearchParams(page.url.split('?')[1] ?? '');
const accountType = ref<'candidate' | 'company'>(
    query.get('role') === 'company' ? 'company' : 'candidate',
);
const selectedPlan = query.get('plan');
const { t } = useI18n();

watchEffect(() => {
    setLayoutProps({
        title: t('auth.createAccountTitle'),
        description: t('auth.createAccountDescription'),
    });
});
</script>

<template>
    <Head :title="t('auth.registerNow')" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <input type="hidden" name="role" :value="accountType" />
        <input
            v-if="accountType === 'company' && selectedPlan"
            type="hidden"
            name="plan_slug"
            :value="selectedPlan"
        />
        <div class="grid gap-6">
            <div class="grid grid-cols-2 gap-3">
                <button
                    type="button"
                    class="erin-focus relative rounded-xl border p-4 text-left"
                    :class="
                        accountType === 'candidate'
                            ? 'border-teal-400 bg-teal-50 ring-1 ring-teal-400'
                            : 'border-slate-200 hover:border-slate-300'
                    "
                    @click="accountType = 'candidate'"
                >
                    <UserRound
                        class="mb-2 size-5"
                        :class="
                            accountType === 'candidate'
                                ? 'text-teal-600'
                                : 'text-slate-400'
                        "
                    />
                    <span class="block text-sm font-bold text-slate-900">
                        {{ t('auth.candidateChoice') }}
                    </span>
                    <span
                        class="mt-1 block text-[11px] leading-4 text-slate-500"
                    >
                        {{ t('auth.candidateChoiceHint') }}
                    </span>
                    <Check
                        v-if="accountType === 'candidate'"
                        class="absolute top-3 right-3 size-4 text-teal-600"
                    />
                </button>
                <button
                    type="button"
                    class="erin-focus relative rounded-xl border p-4 text-left"
                    :class="
                        accountType === 'company'
                            ? 'border-blue-400 bg-blue-50 ring-1 ring-blue-400'
                            : 'border-slate-200 hover:border-slate-300'
                    "
                    @click="accountType = 'company'"
                >
                    <BriefcaseBusiness
                        class="mb-2 size-5"
                        :class="
                            accountType === 'company'
                                ? 'text-blue-600'
                                : 'text-slate-400'
                        "
                    />
                    <span class="block text-sm font-bold text-slate-900">
                        {{ t('auth.companyChoice') }}
                    </span>
                    <span
                        class="mt-1 block text-[11px] leading-4 text-slate-500"
                    >
                        {{ t('auth.companyChoiceHint') }}
                    </span>
                    <Check
                        v-if="accountType === 'company'"
                        class="absolute top-3 right-3 size-4 text-blue-600"
                    />
                </button>
            </div>
            <div class="grid gap-2">
                <Label for="name">{{ t('auth.fullName') }}</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="name"
                    name="name"
                    :placeholder="t('auth.fullNamePlaceholder')"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ t('auth.email') }}</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    :tabindex="2"
                    autocomplete="email"
                    name="email"
                    placeholder="name@beispiel.de"
                />
                <InputError :message="errors.email" />
            </div>

            <div v-if="accountType === 'company'" class="grid gap-2">
                <Label for="company_name">{{ t('auth.companyName') }}</Label>
                <Input
                    id="company_name"
                    type="text"
                    required
                    :tabindex="3"
                    autocomplete="organization"
                    name="company_name"
                    :placeholder="t('auth.companyNamePlaceholder')"
                />
                <InputError :message="errors.company_name" />
            </div>

            <div class="grid gap-2">
                <Label for="password">{{ t('auth.password') }}</Label>
                <PasswordInput
                    id="password"
                    required
                    :tabindex="accountType === 'company' ? 4 : 3"
                    autocomplete="new-password"
                    name="password"
                    :placeholder="t('auth.securePassword')"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">
                    {{ t('auth.confirmPassword') }}
                </Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    :tabindex="accountType === 'company' ? 5 : 4"
                    autocomplete="new-password"
                    name="password_confirmation"
                    :placeholder="t('auth.repeatPassword')"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-2 h-11 w-full rounded-xl bg-blue-600 font-bold hover:bg-blue-700"
                :tabindex="accountType === 'company' ? 6 : 5"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                {{
                    accountType === 'company'
                        ? t('auth.createCompanyAccount')
                        : t('auth.createFreeAccount')
                }}
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            {{ t('auth.alreadyRegistered') }}
            <TextLink
                :href="login()"
                class="underline underline-offset-4"
                :tabindex="accountType === 'company' ? 7 : 6"
                >{{ t('auth.signIn') }}</TextLink
            >
        </div>
    </Form>
</template>
