<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { ref, watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import DemoAccountPicker from '@/components/auth/DemoAccountPicker.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
/* @chisel-registration */
import { register } from '@/routes';
/* @end-chisel-registration */
import { store } from '@/routes/login';
import { request } from '@/routes/password';
/* @chisel-passkeys */
import PasskeyVerify from '@/components/PasskeyVerify.vue';
/* @end-chisel-passkeys */
import type { DemoAccount } from '@/types/demo';

defineOptions({
    inheritAttrs: false,
});

withDefaults(
    defineProps<{
        status?: string;
        canResetPassword: boolean;
        demoMode?: boolean;
        demoAccounts?: DemoAccount[];
        demoPassword?: string;
    }>(),
    {
        status: '',
        demoMode: false,
        demoAccounts: () => [],
        demoPassword: '',
    },
);

const email = ref('');
const password = ref('');
const { t } = useI18n();

watchEffect(() => {
    setLayoutProps({
        title: t('auth.welcomeBack'),
        description: t('auth.welcomeBackDescription'),
    });
});

const insertDemoCredentials = (account: DemoAccount, demoPassword: string) => {
    email.value = account.email;
    password.value = demoPassword;
};
</script>

<template>
    <Head :title="t('auth.signInTitle')" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex min-w-0 flex-col gap-6"
    >
        <div class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-6">
            <DemoAccountPicker
                v-if="demoMode && demoAccounts.length"
                :accounts="demoAccounts"
                :password="demoPassword"
                @select="
                    (account) => insertDemoCredentials(account, demoPassword)
                "
            />

            <div class="grid gap-2">
                <Label for="email" class="text-sm font-semibold text-slate-700">
                    {{ t('auth.email') }}
                </Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="email"
                    :placeholder="t('auth.emailPlaceholder')"
                    v-model="email"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label
                        for="password"
                        class="text-sm font-semibold text-slate-700"
                    >
                        {{ t('auth.password') }}
                    </Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                        :tabindex="5"
                    >
                        {{ t('auth.forgotPassword') }}
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    :placeholder="t('auth.password')"
                    v-model="password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span class="text-sm text-slate-600">
                        {{ t('auth.remember') }}
                    </span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-2 h-11 w-full rounded-xl bg-blue-600 font-bold hover:bg-blue-700"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                {{ t('auth.signIn') }}
            </Button>
        </div>

        <!-- @chisel-passkeys -->
        <PasskeyVerify />
        <!-- @end-chisel-passkeys -->

        <!-- @chisel-registration -->
        <div class="text-center text-sm text-muted-foreground">
            {{ t('auth.noAccount') }}
            <TextLink
                :href="register()"
                :tabindex="5"
                class="font-bold text-[var(--erin-primary,#2563EB)]"
            >
                {{ t('auth.registerNow') }}
            </TextLink>
        </div>
        <!-- @end-chisel-registration -->
    </Form>
</template>
