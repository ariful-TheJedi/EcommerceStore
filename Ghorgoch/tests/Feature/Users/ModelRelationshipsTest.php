<?php

namespace Tests\Feature\Users;

use App\Catalog\Models\Category;
use App\Customers\Models\Address;
use App\Customers\Models\Customer;
use App\Users\Models\EmailVerificationToken;
use App\Users\Models\PasswordResetToken;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ModelRelationshipsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_resolves_customer_and_email_verification_token_relationships(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->for($user)->create();
        $token = EmailVerificationToken::factory()->for($user)->create();

        $this->assertSame($customer->getKey(), $user->customer->getKey());
        $this->assertSame($token->getKey(), $user->emailVerificationToken->getKey());
    }

    public function test_customer_resolves_its_addresses(): void
    {
        $customer = Customer::factory()->create();
        $address = Address::factory()->for($customer)->create();

        $this->assertTrue($customer->addresses->contains($address));
        $this->assertSame($customer->getKey(), $address->customer->getKey());
    }

    public function test_category_resolves_parent_and_children_relationships(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->for($parent, 'parent')->create();

        $this->assertSame($parent->getKey(), $child->parent->getKey());
        $this->assertTrue($parent->children->contains($child));
    }

    public function test_password_reset_token_resolves_user_by_email(): void
    {
        $user = User::factory()->create();
        $token = PasswordResetToken::factory()->create(['email' => $user->email]);

        $this->assertSame($user->getKey(), $token->user->getKey());
    }
}
