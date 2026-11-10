<?php

declare(strict_types=1);

/**
 * Categories and tags have separate tables. Business tests bypass middleware.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function taxonomyTestTable(string $kind = 'categories'): string
{
    return $kind;
}
function taxonomyTestPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Taxonomy Test '.Str::random(9),
        'slug' => 'taxonomy-test-'.Str::lower(Str::random(12)),
    ], $overrides);
}

function taxonomyTestRecords(): array
{
    return collect(['categories', 'tags'])->mapWithKeys(fn (string $table): array => [
        $table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ])->all();
}
function taxonomyTestCreate(string $kind, array $overrides = []): array
{
    $payload = taxonomyTestPayload($overrides);
    test()->postJson(route('catalog.'.$kind.'.store'), $payload)->assertSuccessful();

    // Filter by the unique slug, so no dependence on an undocumented JSON shape.
    $row = DB::table(taxonomyTestTable($kind))->where('slug', $payload['slug'])->first();
    expect($row)->not->toBeNull("{$kind} creation returned success but was not persisted.");

    return ['id' => (int) $row->id, 'payload' => $payload, 'record' => $row];
}

beforeEach(function (): void {
    foreach (['categories', 'tags'] as $kind) {
        foreach (['index', 'store', 'show', 'update', 'destroy'] as $action) {
            expect(Route::has('catalog.'.$kind.'.'.$action))
                ->toBeTrue("Missing catalog.{$kind}.{$action} route");
        }
    }
});

it('rejects anonymous category and tag reads and writes', function (): void {
    foreach (['categories', 'tags'] as $kind) {
        $calls = [
            fn () => $this->getJson(route("catalog.{$kind}.index")),
            fn () => $this->postJson(route("catalog.{$kind}.store"), taxonomyTestPayload()),
            fn () => $this->getJson(route("catalog.{$kind}.show", ['item' => 99999999])),
            fn () => $this->patchJson(route("catalog.{$kind}.update", ['item' => 99999999]), ['name' => 'Changed']),
            fn () => $this->deleteJson(route("catalog.{$kind}.destroy", ['item' => 99999999])),
        ];
        foreach ($calls as $call) {
            $response = $call();
            expect($response->status())->toBeIn([401, 403], "Anonymous {$kind} request returned {$response->status()}");
        }
    }
});

it('creates and lists categories and tags through their respective endpoints', function (string $kind): void {
    $this->withoutMiddleware();
    $made = taxonomyTestCreate($kind);

    expect($made['record']->name)->toBe($made['payload']['name']);
    expect($made['record']->slug)->toBe($made['payload']['slug']);
    $this->getJson(route("catalog.{$kind}.index"))->assertOk()
        ->assertSee($made['payload']['name']);
    $this->getJson(route("catalog.{$kind}.show", ['item' => $made['id']]))->assertOk()
        ->assertSee($made['payload']['slug']);
})->with(['categories', 'tags']);

it('updates categories and tags without replacing their identities', function (string $kind): void {
    $this->withoutMiddleware();
    $made = taxonomyTestCreate($kind);
    $slug = $made['payload']['slug'];

    $this->patchJson(route("catalog.{$kind}.update", ['item' => $made['id']]), [
        'name' => 'Renamed taxonomy entry',
    ])->assertSuccessful();

    $after = DB::table(taxonomyTestTable($kind))->where('id', $made['id'])->first();
    expect($after)->not->toBeNull();
    expect($after->name)->toBe('Renamed taxonomy entry');
    expect($after->slug)->toBe($slug);
})->with(['categories', 'tags']);

it('rejects empty required taxonomy fields without partial writes', function (string $kind, array $overrides): void {
    $this->withoutMiddleware();
    $before = taxonomyTestRecords();
    $this->postJson(route("catalog.{$kind}.store"), taxonomyTestPayload($overrides))
        ->assertUnprocessable();
    expect(taxonomyTestRecords())->toBe($before);
})->with([
    ['categories', ['name' => '']],
    ['categories', ['slug' => '']],
    ['tags', ['name' => '']],
    ['tags', ['slug' => '']],
]);

it('rejects duplicate slugs within the same taxonomy kind', function (string $kind): void {
    $this->withoutMiddleware();
    $first = taxonomyTestCreate($kind);
    $before = taxonomyTestRecords();

    $this->postJson(route("catalog.{$kind}.store"), taxonomyTestPayload([
        'slug' => $first['payload']['slug'],
    ]))->assertUnprocessable();
    expect(taxonomyTestRecords())->toBe($before);
})->with(['categories', 'tags']);

it('rejects renaming a taxonomy slug to an already occupied slug in that kind', function (string $kind): void {
    $this->withoutMiddleware();
    $first = taxonomyTestCreate($kind);
    $second = taxonomyTestCreate($kind);
    $before = taxonomyTestRecords();

    $this->patchJson(route("catalog.{$kind}.update", ['item' => $second['id']]), [
        'slug' => $first['payload']['slug'],
    ])->assertUnprocessable();
    expect(taxonomyTestRecords())->toBe($before);
})->with(['categories', 'tags']);

it('supports nested categories without turning tags into hierarchical records', function (): void {
    $this->withoutMiddleware();
    $parent = taxonomyTestCreate('categories');
    $child = taxonomyTestCreate('categories', ['parent_id' => $parent['id']]);

    $record = DB::table(taxonomyTestTable())->where('id', $child['id'])->first();
    expect($record)->not->toBeNull();
    expect((int) $record->parent_id)->toBe($parent['id']);
});

it('prevents a category from being its own parent', function (): void {
    $this->withoutMiddleware();
    $category = taxonomyTestCreate('categories');
    $before = taxonomyTestRecords();

    $this->patchJson(route('catalog.categories.update', ['item' => $category['id']]), [
        'parent_id' => $category['id'],
    ])->assertUnprocessable();
    expect(taxonomyTestRecords())->toBe($before);
});

it('prevents cycles through a category descendant', function (): void {
    $this->withoutMiddleware();
    $root = taxonomyTestCreate('categories');
    $child = taxonomyTestCreate('categories', ['parent_id' => $root['id']]);
    $grandchild = taxonomyTestCreate('categories', ['parent_id' => $child['id']]);
    $before = taxonomyTestRecords();

    $this->patchJson(route('catalog.categories.update', ['item' => $root['id']]), [
        'parent_id' => $grandchild['id'],
    ])->assertUnprocessable();
    expect(taxonomyTestRecords())->toBe($before);
});

it('rejects unknown parent category IDs without persisting an orphan', function (): void {
    $this->withoutMiddleware();
    $before = taxonomyTestRecords();

    $this->postJson(route('catalog.categories.store'), taxonomyTestPayload([
        'parent_id' => 99999999,
    ]))->assertUnprocessable();
    expect(taxonomyTestRecords())->toBe($before);
});

it('isolates categories and tags even when their numeric IDs overlap', function (): void {
    $this->withoutMiddleware();
    $category = taxonomyTestCreate('categories');
    $tag = taxonomyTestCreate('tags');
    expect($category['id'])->toBe($tag['id']);

    $this->getJson(route('catalog.tags.show', ['item' => $tag['id']]))
        ->assertOk()->assertSee($tag['payload']['slug']);
    $this->getJson(route('catalog.categories.show', ['item' => $category['id']]))
        ->assertOk()->assertSee($category['payload']['slug']);
    $this->patchJson(route('catalog.tags.update', ['item' => $tag['id']]), ['name' => 'Renamed tag'])
        ->assertSuccessful();
    expect((array) DB::table('categories')->where('id', $category['id'])->first())
        ->toBe((array) $category['record']);
    $this->assertDatabaseHas('tags', ['id' => $tag['id'], 'name' => 'Renamed tag']);

    $this->deleteJson(route('catalog.categories.destroy', ['item' => $category['id']]))->assertSuccessful();
    $this->assertDatabaseMissing('categories', ['id' => $category['id']]);
    $this->assertDatabaseHas('tags', ['id' => $tag['id'], 'name' => 'Renamed tag']);
});
it('returns not found for missing taxonomy IDs instead of writing records', function (string $kind): void {
    $this->withoutMiddleware();
    $before = taxonomyTestRecords();
    $missing = 99999999;

    $this->getJson(route("catalog.{$kind}.show", ['item' => $missing]))->assertNotFound();
    $this->patchJson(route("catalog.{$kind}.update", ['item' => $missing]), ['name' => 'Unknown'])
        ->assertNotFound();
    $this->deleteJson(route("catalog.{$kind}.destroy", ['item' => $missing]))->assertNotFound();
    expect(taxonomyTestRecords())->toBe($before);
})->with(['categories', 'tags']);

it('can remove an unused category or tag without affecting other taxonomy records', function (string $kind): void {
    $this->withoutMiddleware();
    $target = taxonomyTestCreate($kind);
    $other = taxonomyTestCreate($kind);

    $this->deleteJson(route("catalog.{$kind}.destroy", ['item' => $target['id']]))->assertSuccessful();

    expect(DB::table(taxonomyTestTable($kind))->where('id', $target['id'])->exists())->toBeFalse();
    expect(DB::table(taxonomyTestTable($kind))->where('id', $other['id'])->exists())->toBeTrue();
})->with(['categories', 'tags']);
