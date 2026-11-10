<?php

namespace Tests\Feature;

use App\Auth\Domain\Services\AuthService;
use App\Catalog\Domain\Services\MediaService;
use App\Catalog\Models\Category;
use App\Catalog\Models\FrontPageSetting;
use App\Catalog\Models\Product;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FrontPageTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(array $permissions = ['catalog.view', 'catalog.manage']): void
    {
        $user = User::factory()->create(['role' => 'staff', 'permissions' => $permissions]);
        $this->actingAs($user, 'staff')->withSession(['auth_started_at' => now()->timestamp, 'auth_fingerprint' => app(AuthService::class)->fingerprint($user)]);
    }

    /** @return array<string, mixed> */
    private function content(): array
    {
        return [
            'hero' => ['visible' => true, 'heading' => 'Our store', 'slides' => [['id' => 'first', 'image' => '/images/hero.png', 'alt' => 'Hero', 'eyebrow' => 'Welcome', 'title' => 'Shop today', 'accent' => 'Everyday', 'description' => 'Fresh products', 'href' => '/products', 'action' => 'Shop now']]],
            'categories' => ['visible' => true, 'title' => 'Categories', 'subtitle' => 'Browse our store', 'ids' => []],
            'featured' => ['visible' => true, 'title' => 'Our picks', 'ids' => []],
            'new_arrivals' => ['visible' => true, 'title' => 'New arrivals', 'ids' => [], 'mode' => 'latest', 'limit' => 4],
            'promotions' => ['visible' => false, 'cards' => []],
            'benefits' => ['visible' => false, 'items' => []],
            'faq' => ['visible' => false, 'title' => 'Questions', 'description' => '', 'items' => []],
        ];
    }

    public function test_public_defaults_are_available_but_administration_requires_authentication(): void
    {
        $this->getJson('/api/catalog/front-page')->assertOk()->assertJsonPath('data.settings.content', null);
        $this->getJson('/api/front-page')->assertUnauthorized();
        $this->patchJson('/api/front-page', ['content' => $this->content(), 'version' => 0])->assertUnauthorized();
        $this->postJson('/api/front-page/images', ['image' => UploadedFile::fake()->image('hero.png')])->assertUnauthorized();
    }

    public function test_read_only_staff_can_read_but_cannot_publish_or_upload(): void
    {
        $this->signIn(['catalog.view']);
        $this->getJson('/api/front-page')->assertOk();
        $this->patchJson('/api/front-page', ['content' => $this->content(), 'version' => 0])->assertForbidden();
        $this->postJson('/api/front-page/images', ['image' => UploadedFile::fake()->image('hero.png')])->assertForbidden();
    }

    public function test_saved_selections_keep_order_and_exclude_unpublished_or_inactive_records(): void
    {
        $this->signIn();
        $categories = Category::factory()->count(2)->create(['is_active' => true]);
        $inactive = Category::factory()->create(['is_active' => false]);
        $products = Product::factory()->count(2)->create(['status' => 'published']);
        $draft = Product::factory()->create(['status' => 'draft']);
        $content = $this->content();
        $content['categories']['ids'] = [$categories[1]->id, $inactive->id, $categories[0]->id];
        $content['featured']['ids'] = [$products[1]->id, $draft->id, $products[0]->id];
        $content['new_arrivals'] = ['visible' => true, 'title' => 'Hand picked', 'ids' => [$draft->id, $products[1]->id, $products[0]->id], 'mode' => 'selected', 'limit' => 1];
        $this->patchJson('/api/front-page', ['content' => $content, 'version' => 0])->assertOk()->assertJsonPath('data.version', 1);
        $saved = FrontPageSetting::findOrFail(1);
        $this->assertSame($content['featured']['ids'], $saved->content['featured']['ids']);
        $this->assertSame(1, $saved->version);
        $this->getJson('/api/front-page')->assertOk()->assertJsonPath('data.content.featured.ids', $content['featured']['ids']);
        $this->getJson('/api/catalog/front-page')->assertOk()
            ->assertJsonPath('data.categories.0.id', $categories[1]->id)->assertJsonPath('data.categories.1.id', $categories[0]->id)->assertJsonCount(2, 'data.categories')
            ->assertJsonPath('data.featured.0.id', $products[1]->id)->assertJsonPath('data.featured.1.id', $products[0]->id)->assertJsonCount(2, 'data.featured')
            ->assertJsonPath('data.new_arrivals.0.id', $products[1]->id)->assertJsonCount(1, 'data.new_arrivals')
            ->assertJsonPath('data.settings.content.hero.slides.0.title', 'Shop today')->assertJsonMissingPath('data.featured.0.updated_at');
    }

    public function test_new_arrivals_use_latest_products_and_hidden_sections_return_no_records(): void
    {
        $this->signIn();
        Product::factory()->create(['status' => 'published', 'created_at' => now()->subDays(2)]);
        $newest = Product::factory()->create(['status' => 'published', 'created_at' => now()->subDay()]);
        Product::factory()->create(['status' => 'draft', 'created_at' => now()]);
        $content = $this->content();
        $content['new_arrivals']['limit'] = 1;
        $this->patchJson('/api/front-page', ['content' => $content, 'version' => 0])->assertOk();
        $this->getJson('/api/catalog/front-page')->assertOk()->assertJsonCount(1, 'data.new_arrivals')->assertJsonPath('data.new_arrivals.0.id', $newest->id);
        foreach ($content as &$section) {
            $section['visible'] = false;
        }
        unset($section);
        $content['hero']['slides'] = [];
        $this->patchJson('/api/front-page', ['content' => $content, 'version' => 1])->assertOk();
        $this->getJson('/api/catalog/front-page')->assertOk()->assertJsonCount(0, 'data.categories')->assertJsonCount(0, 'data.featured')->assertJsonCount(0, 'data.new_arrivals')->assertJsonPath('data.settings.content.hero.visible', false);
    }

    public function test_anchor_links_do_not_prevent_section_visibility_changes_from_saving(): void
    {
        $this->signIn();
        $content = $this->content();
        $content['hero']['slides'][0]['href'] = '#collection';
        $content['hero']['visible'] = false;
        $content['categories']['visible'] = false;
        $content['featured']['visible'] = false;

        $this->patchJson('/api/front-page', ['content' => $content, 'version' => 0])->assertOk()
            ->assertJsonPath('data.content.hero.slides.0.href', '#collection')
            ->assertJsonPath('data.content.hero.visible', false)
            ->assertJsonPath('data.content.categories.visible', false)
            ->assertJsonPath('data.content.featured.visible', false);
        $this->getJson('/api/catalog/front-page')->assertOk()
            ->assertJsonPath('data.settings.content.hero.visible', false)
            ->assertJsonPath('data.settings.content.categories.visible', false)
            ->assertJsonPath('data.settings.content.featured.visible', false);

        $content['hero']['visible'] = true;
        $this->patchJson('/api/front-page', ['content' => $content, 'version' => 1])->assertOk();
        $this->getJson('/api/catalog/front-page')->assertOk()->assertJsonPath('data.settings.content.hero.visible', true);
    }

    public function test_stale_updates_do_not_overwrite_another_administrators_changes(): void
    {
        $this->signIn();
        $content = $this->content();
        $this->patchJson('/api/front-page', ['content' => $content, 'version' => 0])->assertOk();
        $content['featured']['title'] = 'Stale overwrite';
        $this->patchJson('/api/front-page', ['content' => $content, 'version' => 0])->assertConflict();
        $this->getJson('/api/front-page')->assertOk()->assertJsonPath('data.content.featured.title', 'Our picks')->assertJsonPath('data.version', 1);
    }

    public function test_sections_are_saved_in_one_settings_record(): void
    {
        $this->signIn();
        $content = $this->content();
        $this->patchJson('/api/front-page', ['content' => $content, 'version' => 0])->assertOk();
        $content['hero']['heading'] = 'Updated heading';
        $content['benefits']['visible'] = true;
        $this->patchJson('/api/front-page', ['content' => $content, 'version' => 1])->assertOk();

        $this->assertDatabaseCount('front_page_settings', 1);
        $settings = FrontPageSetting::findOrFail(1);
        $this->assertSame(2, $settings->version);
        $this->assertSame('Updated heading', $settings->content['hero']['heading']);
        $this->assertTrue($settings->content['benefits']['visible']);
        $this->assertArrayHasKey('faq', $settings->content);
    }

    public function test_a_failed_database_write_preserves_previous_settings(): void
    {
        $this->signIn();
        $content = $this->content();
        $this->patchJson('/api/front-page', ['content' => $content, 'version' => 0])->assertOk();
        FrontPageSetting::updating(function (FrontPageSetting $settings): void {
            throw new \RuntimeException('Simulated persistence failure.');
        });
        $content['featured']['title'] = 'Unsaved title';

        $this->patchJson('/api/front-page', ['content' => $content, 'version' => 1])->assertStatus(500);
        $this->getJson('/api/front-page')->assertOk()->assertJsonPath('data.content.featured.title', 'Our picks')->assertJsonPath('data.version', 1);
    }

    public function test_invalid_links_unknown_fields_and_missing_references_are_rejected(): void
    {
        $this->signIn();
        $content = $this->content();
        $content['hero']['slides'][0]['href'] = 'javascript:alert(1)';
        $content['hero']['unknown'] = true;
        $content['featured']['ids'] = [999999];
        $content['new_arrivals']['limit'] = 100;
        $this->patchJson('/api/front-page', ['content' => $content, 'version' => 0])->assertUnprocessable()->assertJsonValidationErrors(['content.hero', 'content.hero.slides.0.href', 'content.featured.ids.0', 'content.new_arrivals.limit']);
        $this->getJson('/api/front-page')->assertOk()->assertJsonPath('data.content', null);
    }

    public function test_uploaded_hero_images_use_shared_storage_and_survive_orphan_cleanup(): void
    {
        $this->signIn();
        Storage::fake(config('catalog.media_disk'));
        $uploaded = $this->postJson('/api/front-page/images', ['image' => UploadedFile::fake()->image('hero.jpg')])->assertCreated()->json('data');
        $path = $uploaded['image_path'];
        Storage::disk(config('catalog.media_disk'))->assertExists($path);
        $content = $this->content();
        $content['hero']['slides'][0]['image_path'] = $path;
        $content['hero']['slides'][0]['image'] = $uploaded['url'];
        $this->patchJson('/api/front-page', ['content' => $content, 'version' => 0])->assertOk();
        touch(Storage::disk(config('catalog.media_disk'))->path($path), now()->subDays(2)->timestamp);
        app(MediaService::class)->cleanup();
        Storage::disk(config('catalog.media_disk'))->assertExists($path);
        $this->getJson('/api/catalog/front-page')->assertOk()->assertJsonPath('data.settings.content.hero.slides.0.image', $uploaded['url']);
        $this->postJson('/api/front-page/images', ['image' => UploadedFile::fake()->image('large.png')->size(5121)])->assertUnprocessable()->assertJsonValidationErrors('image');
    }
}
