<?php

namespace Tests\Feature;

use App\Models\PromptTemplate;
use App\Models\User;
use Database\Seeders\PromptTemplateLibrary;
use Database\Seeders\PromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromptTemplateLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_library_has_five_hundred_unique_recipes_in_each_existing_category(): void
    {
        $templates = collect(PromptTemplateLibrary::templates());

        $this->assertSame([
            'Belajar' => 500,
            'Bisnis' => 500,
            'Coding' => 500,
            'Desain' => 500,
            'Excel' => 500,
            'Konten' => 500,
            'Marketplace' => 500,
            'Prompt Gambar' => 500,
            'Prompt Video' => 500,
            'UMKM' => 500,
        ], $templates->countBy('category')->sortKeys()->all());
        foreach (['template_key', 'title', 'prompt_text'] as $field) {
            $this->assertCount(5000, $templates->unique(fn ($row) => mb_strtolower(trim($row[$field]))), $field);
        }
    }

    public function test_import_is_idempotent_and_preserves_custom_rows_and_publication_choices(): void
    {
        $firstRecipe = PromptTemplateLibrary::templates()[0];
        $custom = PromptTemplate::create([
            'category' => 'Coding',
            'title' => $firstRecipe['title'],
            'prompt_text' => 'My private instructions must survive the library import.',
            'mode' => 'custom_mode',
            'is_active' => false,
            'sort_order' => -20,
        ]);
        $foreign = PromptTemplate::create([
            'template_key' => 'customer.support.receipt',
            'category' => 'Support',
            'title' => 'Custom receipt response',
            'prompt_text' => 'Use only the supplied receipt and support policy.',
            'mode' => null,
            'sort_order' => 4,
        ]);
        $customBefore = $custom->fresh()->getAttributes();
        $foreignBefore = $foreign->fresh()->getAttributes();

        $this->seed(PromptTemplateSeeder::class);
        PromptTemplate::where('template_key', $firstRecipe['template_key'])->update([
            'is_active' => false,
            'sort_order' => 777,
        ]);
        $before = PromptTemplate::orderBy('id')->get()->map->getAttributes()->all();
        $this->travel(1)->hours();
        $this->seed(PromptTemplateSeeder::class);

        $this->assertDatabaseCount('prompt_templates', 5002);
        $this->assertSame($before, PromptTemplate::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($customBefore, $custom->fresh()->getAttributes());
        $this->assertSame($foreignBefore, $foreign->fresh()->getAttributes());
        $this->assertDatabaseHas('prompt_templates', [
            'template_key' => $firstRecipe['template_key'],
            'mode' => 'coding_assistant',
            'is_active' => false,
            'sort_order' => 777,
        ]);
    }

    public function test_templates_are_bounded_and_pagination_is_stable_when_sort_orders_tie(): void
    {
        $member = User::factory()->create();
        $ids = [];
        for ($index = 1; $index <= 67; $index++) {
            $ids[] = $this->template(['title' => "Custom recipe {$index}"])->id;
        }
        $this->template(['title' => 'Unpublished recipe', 'is_active' => false]);

        $this->actingAs($member)->getJson('/api/templates')
            ->assertOk()
            ->assertJsonCount(24, 'templates')
            ->assertJsonPath('pagination', ['current_page' => 1, 'last_page' => 3, 'per_page' => 24, 'total' => 67])
            ->assertJsonMissingPath('grouped');

        $first = $this->getJson('/api/templates?per_page=60')->assertOk()
            ->assertJsonCount(60, 'templates')->json('templates');
        $last = $this->getJson('/api/templates?per_page=60&page=2')->assertOk()
            ->assertJsonCount(7, 'templates')
            ->assertJsonPath('pagination', ['current_page' => 2, 'last_page' => 2, 'per_page' => 60, 'total' => 67])
            ->json('templates');
        $this->assertSame($ids, array_column(array_merge($first, $last), 'id'));
        $this->getJson('/api/templates?per_page=61')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/templates?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_search_keeps_title_and_prompt_matches_inside_category_mode_and_active_scope(): void
    {
        $titleMatch = $this->template(['title' => 'Invoice retry diagnosis']);
        $promptMatch = $this->template(['title' => 'Payment worker', 'prompt_text' => 'Review this INVOICE queue worker.']);
        $this->template(['category' => 'UMKM', 'title' => 'Invoice for a shop', 'mode' => 'umkm_assistant']);
        $this->template(['title' => 'Hidden invoice', 'is_active' => false]);
        $this->template(['title' => 'Invoice project', 'mode' => 'project_builder']);
        $this->template(['category' => 'My category', 'title' => 'Invoice support', 'mode' => 'coding_assistant']);

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/templates?q=invoice&category=Coding&mode=coding_assistant')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('counts.Coding', 2)
            ->assertJsonPath('counts.UMKM', 0)
            ->assertJsonPath('counts.My category', 1);

        $this->assertSame([$titleMatch->id, $promptMatch->id], array_column($response->json('templates'), 'id'));
        $this->assertContains('My category', $response->json('categories'));
        $this->assertSame(['coding_assistant'], array_values(array_unique(array_column($response->json('templates'), 'mode'))));
    }

    public function test_search_treats_wildcard_characters_as_literal_text_and_reports_empty_pages(): void
    {
        $literal = $this->template(['title' => 'Validate 100%_complete! values']);
        $this->template(['title' => 'Validate 100xxcomplete values']);
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/templates?'.http_build_query(['q' => '100%_complete!']))
            ->assertOk()->assertJsonPath('pagination.total', 1);
        $this->assertSame([$literal->id], array_column($response->json('templates'), 'id'));

        $this->getJson('/api/templates?q=absent-term')
            ->assertOk()->assertJsonCount(0, 'templates')
            ->assertJsonPath('pagination', ['current_page' => 1, 'last_page' => 1, 'per_page' => 24, 'total' => 0])
            ->assertJsonPath('counts.Coding', 0);
    }

    private function template(array $attributes = []): PromptTemplate
    {
        return PromptTemplate::create(array_merge([
            'category' => 'Coding',
            'title' => 'Code review',
            'prompt_text' => 'Explain the concrete risks in the supplied code.',
            'mode' => 'coding_assistant',
            'is_active' => true,
            'sort_order' => 0,
        ], $attributes));
    }
}
