<?php

namespace App\Http\Controllers\Admin;

use App\Models\SupportChatPrompt;
use App\Models\SupportKnowledgeArticle;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupportKnowledgeController extends AdminController
{
    public function storeArticle(Request $request, ActivityRecorder $activity): RedirectResponse
    {
        $validated = $request->validate([
            'stable_key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/'],
            'locale' => ['required', Rule::in(config('app.supported_locales', ['de', 'en']))],
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:50000'],
            'source_url' => ['nullable', 'url:https', 'max:2048'],
            'target_roles' => ['nullable', 'array'],
            'target_roles.*' => [Rule::in(['candidate', 'company', 'support', 'super_admin'])],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $user = $request->user();
        abort_if($user === null, 401);

        $article = DB::transaction(function () use ($validated, $user): SupportKnowledgeArticle {
            $previous = SupportKnowledgeArticle::query()
                ->where('stable_key', $validated['stable_key'])
                ->where('locale', $validated['locale'])
                ->lockForUpdate()
                ->latest('version')
                ->first();

            return SupportKnowledgeArticle::query()->create([
                ...$validated,
                'version' => ($previous === null ? 0 : $previous->version) + 1,
                'status' => 'draft',
                'created_by' => $user->getKey(),
                'supersedes_id' => $previous?->getKey(),
            ]);
        });
        $activity->record(
            'support.knowledge_version_created',
            $user,
            null,
            $article,
            ['stable_key' => $article->stable_key, 'version' => $article->version, 'locale' => $article->locale],
            null,
            'platform',
        );

        return back()->with('success', __('Entwurf der Wissensquelle wurde versioniert gespeichert.'));
    }

    public function publishArticle(
        Request $request,
        SupportKnowledgeArticle $article,
        ActivityRecorder $activity,
    ): RedirectResponse {
        abort_unless($article->status === 'draft', 422, __('Nur Entwürfe können freigegeben werden.'));
        $user = $request->user();
        abort_if($user === null, 401);

        DB::transaction(function () use ($article, $user): void {
            SupportKnowledgeArticle::query()
                ->where('stable_key', $article->stable_key)
                ->where('locale', $article->locale)
                ->where('status', 'published')
                ->whereKeyNot($article->getKey())
                ->update(['status' => 'retired']);
            $article->update([
                'status' => 'published',
                'published_at' => now(),
                'approved_by' => $user->getKey(),
            ]);
        });
        $activity->record(
            'support.knowledge_published',
            $user,
            null,
            $article,
            ['stable_key' => $article->stable_key, 'version' => $article->version, 'locale' => $article->locale],
            null,
            'platform',
        );

        return back()->with('success', __('Wissensquelle wurde veröffentlicht.'));
    }

    public function retireArticle(
        Request $request,
        SupportKnowledgeArticle $article,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        $article->update(['status' => 'retired']);
        $activity->record(
            'support.knowledge_retired',
            $user,
            null,
            $article,
            ['stable_key' => $article->stable_key, 'version' => $article->version, 'locale' => $article->locale],
            null,
            'platform',
        );

        return back()->with('success', __('Wissensquelle wurde zurückgezogen.'));
    }

    public function storePrompt(Request $request, ActivityRecorder $activity): RedirectResponse
    {
        $validated = $request->validate([
            'instructions' => ['required', 'string', 'max:20000'],
            'allowed_tools' => ['nullable', 'array'],
            'allowed_tools.*' => [Rule::in(['knowledge_search', 'support_handoff'])],
            'safety_rules' => ['nullable', 'array'],
            'safety_rules.*' => ['string', 'max:500'],
            'model' => ['nullable', 'string', 'max:120'],
        ]);
        $user = $request->user();
        abort_if($user === null, 401);

        $prompt = DB::transaction(function () use ($validated, $user): SupportChatPrompt {
            $version = ((int) SupportChatPrompt::query()->lockForUpdate()->max('version')) + 1;

            return SupportChatPrompt::query()->create([
                ...$validated,
                'version' => $version,
                'active' => false,
                'created_by' => $user->getKey(),
            ]);
        });
        $activity->record(
            'support.prompt_version_created',
            $user,
            null,
            $prompt,
            ['version' => $prompt->version],
            null,
            'platform',
        );

        return back()->with('success', __('Prompt-Version wurde als Entwurf gespeichert.'));
    }

    public function activatePrompt(Request $request, SupportChatPrompt $prompt, ActivityRecorder $activity): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        DB::transaction(function () use ($prompt): void {
            SupportChatPrompt::query()->where('active', true)->update(['active' => false]);
            $prompt->update(['active' => true]);
        });
        $activity->record(
            'support.prompt_activated',
            $user,
            null,
            $prompt,
            ['version' => $prompt->version],
            null,
            'platform',
        );

        return back()->with('success', __('Prompt-Version wurde aktiviert.'));
    }
}
