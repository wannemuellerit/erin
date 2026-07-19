<?php

namespace App\Http\Controllers\Admin;

use App\Models\Skill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SkillTaxonomyController extends AdminController
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $skill = Skill::query()->create([
            ...Arr::except($validated, ['occupation_ids']),
            'slug' => $this->uniqueSlug($validated['name_de']),
        ]);
        $skill->occupations()->sync($validated['occupation_ids'] ?? []);
        $this->audit($request, 'admin.skill.created', $skill, [], $skill->toArray());

        return back()->with('success', __('Der Skill wurde erstellt.'));
    }

    public function update(Request $request, Skill $skill): RedirectResponse
    {
        $validated = $this->validated($request);
        $before = $skill->load('occupations:id')->toArray();
        $skill->update(Arr::except($validated, ['occupation_ids']));
        $skill->occupations()->sync($validated['occupation_ids'] ?? []);
        $this->audit($request, 'admin.skill.updated', $skill, $before, $skill->fresh('occupations:id')->toArray());

        return back()->with('success', __('Der Skill wurde aktualisiert.'));
    }

    public function destroy(Request $request, Skill $skill): RedirectResponse
    {
        $before = $skill->toArray();
        $skill->update(['is_active' => false]);
        $this->audit($request, 'admin.skill.deactivated', $skill, $before, $skill->toArray());

        return back()->with('success', __('Der Skill wurde deaktiviert.'));
    }

    /**
     * @return array{name_de: string, name_en: string, is_active: bool, occupation_ids?: list<int>}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name_de' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
            'occupation_ids' => ['array'],
            'occupation_ids.*' => ['integer', Rule::exists('occupations', 'id')],
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'skill';
        $slug = $base;
        $suffix = 2;
        while (Skill::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
