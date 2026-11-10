<?php

namespace Tests\Feature;

use App\Auth\Domain\Services\AuthService;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffAvatarTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function signIn(): User
    {
        $user = User::factory()->create(['role' => 'staff', 'permissions' => []]);
        $this->actingAs($user, 'staff')->withSession(['auth_started_at' => now()->timestamp, 'auth_fingerprint' => app(AuthService::class)->fingerprint($user)]);
        Storage::fake('public');

        return $user;
    }

    public function test_staff_can_upload_replace_and_restore_default_avatar_without_losing_session(): void
    {
        $user = $this->signIn();
        $version = $user->fresh()->access_version;
        $this->postJson('/api/users/me/avatar', ['avatar' => UploadedFile::fake()->image('profile.png')->size(2048)])
            ->assertOk()->assertJsonStructure(['data' => ['user' => ['avatar_url']]]);
        $first = $user->fresh()->avatar_path;
        Storage::disk('public')->assertExists($first);
        $this->assertStringStartsWith('avatars/'.$user->id.'/', $first);
        $this->postJson('/api/users/me/avatar', ['avatar' => UploadedFile::fake()->image('replacement.jpg')])->assertOk();
        $second = $user->fresh()->avatar_path;
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
        $this->postJson('/api/users/me/avatar', ['avatar' => UploadedFile::fake()->image('replacement.jpeg')])->assertOk();
        $third = $user->fresh()->avatar_path;
        Storage::disk('public')->assertMissing($second);
        Storage::disk('public')->assertExists($third);

        $this->deleteJson('/api/users/me/avatar')->assertOk()->assertJsonPath('data.user.avatar_url', null);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar_path' => null]);
        Storage::disk('public')->assertMissing($third);
        $this->assertSame($version, $user->fresh()->access_version);
        $this->getJson('/api/auth/staff/me')->assertOk()->assertJsonPath('data.user.avatar_url', null);
    }

    public function test_invalid_oversized_and_unsupported_images_leave_current_avatar_intact(): void
    {
        $user = $this->signIn();
        $this->postJson('/api/users/me/avatar', ['avatar' => UploadedFile::fake()->image('valid.png')])->assertOk();
        $path = $user->fresh()->avatar_path;
        $this->postJson('/api/users/me/avatar', ['avatar' => UploadedFile::fake()->image('too-large.jpg')->size(2049)])->assertUnprocessable()->assertJsonValidationErrors('avatar');
        $this->postJson('/api/users/me/avatar', ['avatar' => UploadedFile::fake()->image('unsupported.gif')])->assertUnprocessable()->assertJsonValidationErrors('avatar');
        $this->postJson('/api/users/me/avatar', ['avatar' => UploadedFile::fake()->image('unsupported.webp')])->assertUnprocessable()->assertJsonValidationErrors('avatar');
        $this->postJson('/api/users/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent('fake.jpg', 'not image data')])->assertUnprocessable()->assertJsonValidationErrors('avatar');
        $this->postJson('/api/users/me/avatar', [])->assertUnprocessable()->assertJsonValidationErrors('avatar');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar_path' => $path]);
        Storage::disk('public')->assertExists($path);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_upload_cannot_choose_a_different_user_or_storage_path(): void
    {
        $user = $this->signIn();
        $other = User::factory()->create();

        $this->postJson('/api/users/me/avatar', ['avatar' => UploadedFile::fake()->image('photo.png'), 'id' => $other->id, 'avatar_path' => 'elsewhere.png'])
            ->assertUnprocessable()->assertJsonValidationErrors(['id', 'avatar_path']);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar_path' => null]);
        $this->assertDatabaseHas('users', ['id' => $other->id, 'avatar_path' => null]);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_authentication_is_required_for_upload_and_default_avatar(): void
    {
        $this->postJson('/api/users/me/avatar', [])->assertUnauthorized();
        $this->deleteJson('/api/users/me/avatar')->assertUnauthorized();
    }

    public function test_avatar_url_is_returned_for_settings_and_future_logins(): void
    {
        $user = $this->signIn();
        $response = $this->postJson('/api/users/me/avatar', ['avatar' => UploadedFile::fake()->image('photo.png')])->assertOk();
        $url = $response->json('data.user.avatar_url');
        $this->assertSame(Storage::disk('public')->url($user->fresh()->avatar_path), $url);
        $this->getJson('/api/users/me')->assertOk()->assertJsonPath('data.user.avatar_url', $url);
        $this->getJson('/api/auth/staff/me')->assertOk()->assertJsonPath('data.user.avatar_url', $url);
    }

    public function test_restoring_default_does_not_delete_unmanaged_files(): void
    {
        $user = $this->signIn();
        Storage::disk('public')->put('catalog/shared.png', 'shared asset');
        $user->avatar_path = 'catalog/shared.png';
        $user->save();

        $this->deleteJson('/api/users/me/avatar')->assertOk()->assertJsonPath('data.user.avatar_url', null);

        Storage::disk('public')->assertExists('catalog/shared.png');
    }
}
