<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';

export type ScreeningQuestionDraft = {
    question: string;
    type: string;
    is_required: boolean;
    options: string[];
};

const model = defineModel<ScreeningQuestionDraft[]>({ required: true });
const { t } = useI18n();
const fieldClass =
    'erin-focus h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm';

function add(): void {
    if (model.value.length < 5) {
        model.value.push({
            question: '',
            type: 'text',
            is_required: false,
            options: [],
        });
    }
}
function setType(question: ScreeningQuestionDraft, type: string): void {
    question.type = type;

    if (type !== 'choice') {
        question.options = [];
    }

    if (type === 'choice' && question.options.length < 2) {
        question.options = ['', ''];
    }
}
function addOption(question: ScreeningQuestionDraft): void {
    if (question.options.length < 10) {
        question.options.push('');
    }
}
</script>

<template>
    <div class="space-y-4">
        <article
            v-for="(question, index) in model"
            :key="index"
            class="rounded-xl border border-slate-200 p-4"
        >
            <div
                class="grid gap-3 md:grid-cols-[2rem_minmax(0,1fr)_10rem_auto]"
            >
                <span
                    class="grid size-8 place-items-center rounded-lg bg-slate-100 text-xs font-bold text-slate-500"
                    >{{ index + 1 }}</span
                >
                <input
                    v-model="question.question"
                    required
                    :class="fieldClass"
                    :placeholder="t('employer.jobForm.questionPlaceholder')"
                />
                <select
                    :value="question.type"
                    :class="fieldClass"
                    @change="
                        setType(
                            question,
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="text">
                        {{ t('employer.jobForm.questionTypes.text') }}
                    </option>
                    <option value="yes_no">
                        {{ t('employer.jobForm.questionTypes.yesNo') }}
                    </option>
                    <option value="choice">
                        {{ t('employer.jobForm.questionTypes.choice') }}
                    </option>
                </select>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    class="text-red-600"
                    @click="model.splice(index, 1)"
                    ><Trash2 class="size-4"
                /></Button>
            </div>
            <label
                class="mt-3 flex items-center gap-2 text-xs font-semibold text-slate-700"
                ><input v-model="question.is_required" type="checkbox" />{{
                    t('employer.jobForm.requiredQuestion')
                }}</label
            >
            <div
                v-if="question.type === 'choice'"
                class="mt-3 space-y-2 border-l-2 border-blue-100 pl-4"
            >
                <div
                    v-for="(_, optionIndex) in question.options"
                    :key="optionIndex"
                    class="flex gap-2"
                >
                    <input
                        v-model="question.options[optionIndex]"
                        required
                        :class="fieldClass"
                        :placeholder="
                            t('employer.jobForm.answerOption', {
                                number: optionIndex + 1,
                            })
                        "
                    />
                    <Button
                        v-if="question.options.length > 2"
                        type="button"
                        size="icon"
                        variant="ghost"
                        @click="question.options.splice(optionIndex, 1)"
                        ><Trash2 class="size-4"
                    /></Button>
                </div>
                <Button
                    v-if="question.options.length < 10"
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="addOption(question)"
                    ><Plus class="size-4" />{{
                        t('employer.jobForm.addAnswerOption')
                    }}</Button
                >
            </div>
        </article>
        <Button
            v-if="model.length < 5"
            type="button"
            variant="outline"
            @click="add"
            ><Plus class="size-4" />{{
                t('employer.jobForm.addQuestion')
            }}</Button
        >
    </div>
</template>
