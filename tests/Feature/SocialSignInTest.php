<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\SocialAccount;
use App\Models\SocialLinkTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIdentityTokens;
use Tests\TestCase;

/**
 * «المتابعة باستخدام Apple / Google» — `POST /auth/social` and `POST /auth/social/link`.
 *
 * The rule the whole flow exists to work around: **neither provider returns a phone
 * number.** Apple gives a subject, an email (usually a private relay alias) and a name on
 * the first authorisation only; Google gives a subject, an email and a name. `users.phone`
 * is unique, not null, and is what the captain dials — so the first sign-in always ends on
 * `CUS-07a` and one SMS code, and every later one skips straight in.
 */
class SocialSignInTest extends TestCase
{
    use RefreshDatabase, SignsIdentityTokens;

    protected function setUp(): void
    {
        parent::setUp();

        config(['otp.static' => true, 'otp.static_code' => '123456']);
        $this->withHeader('X-App', 'app');
        $this->fakeIdentityProviders();
    }

    // ───────────────────────────────── branch 1 · needs_phone

    public function test_a_new_google_identity_is_asked_for_a_phone_number(): void
    {
        $response = $this->postJson('/api/v1/auth/social', [
            'provider' => 'google',
            'id_token' => $this->googleToken('google-1', [
                'email' => 'ahmed@example.com',
                'email_verified' => true,
                'name' => 'أحمد الشناوي',
            ]),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'needs_phone')
            ->assertJsonPath('data.name', 'أحمد الشناوي')
            ->assertJsonPath('data.email', 'ahmed@example.com');

        // Nothing was created. There is no phone to create it with.
        $this->assertNotEmpty($response->json('data.link_ticket'));
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, SocialAccount::query()->count());
    }

    /**
     * Apple hands the full name to the **client**, once, and never puts it on the token.
     * The client is allowed to pass it along, and it is display copy — the `sub` inside
     * the signed token is the only thing that says who this is.
     */
    public function test_apples_one_and_only_name_is_taken_from_the_client_and_persisted(): void
    {
        $ticket = $this->postJson('/api/v1/auth/social', [
            'provider' => 'apple',
            'id_token' => $this->appleToken('apple-1', [
                'email' => 'relay@privaterelay.appleid.com',
                'email_verified' => 'true',
            ]),
            'name' => 'أحمد الشناوي',
        ])->assertOk()->assertJsonPath('data.name', 'أحمد الشناوي')->json('data.link_ticket');

        $this->assertSame('أحمد الشناوي', SocialLinkTicket::query()->firstOrFail()->name);

        $this->sendOtp('01011122233');

        $this->postJson('/api/v1/auth/social/link', [
            'link_ticket' => $ticket,
            'phone' => '01011122233',
            'code' => '123456',
        ])->assertOk()->assertJsonPath('data.user.name', 'أحمد الشناوي');

        $user = User::query()->where('phone', '01011122233')->firstOrFail();
        $this->assertSame('أحمد الشناوي', $user->name);
        // A relay address is verified, so it is adopted — `users.email` was empty.
        $this->assertSame('relay@privaterelay.appleid.com', $user->email);
        $this->assertSame(UserType::Customer, $user->type);
        $this->assertNotNull($user->phone_verified_at);
    }

    // ───────────────────────────────── branch 2 · signed_in

    public function test_a_returning_identity_signs_straight_in_with_no_code(): void
    {
        $this->linkGoogle('google-1', '01011122233');

        $response = $this->postJson('/api/v1/auth/social', [
            'provider' => 'google',
            'id_token' => $this->googleToken('google-1', ['email' => 'ahmed@example.com']),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'signed_in')
            ->assertJsonPath('data.user.phone', '01011122233');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame(0, SocialLinkTicket::query()->whereNull('consumed_at')->count());
    }

    /** An existing customer keeps their account — the provider is attached to it, not beside it. */
    public function test_the_provider_attaches_to_the_account_that_already_owns_the_phone(): void
    {
        $existing = User::factory()->create([
            'phone' => '01011122233',
            'type' => 'customer',
            'name' => 'أحمد',
        ]);

        $this->linkGoogle('google-1', '01011122233');

        $this->assertSame(1, User::query()->where('phone', '01011122233')->count());
        $this->assertSame($existing->id, SocialAccount::query()->firstOrFail()->user_id);
        // The name they typed themselves is not overwritten by the provider's.
        $this->assertSame('أحمد', $existing->fresh()->name);
    }

    // ───────────────────────────────── branch 3 · the named refusals

    /** D-45 — one phone is one role, and the refusal says which one. */
    public function test_a_phone_that_belongs_to_a_captain_is_refused_by_name(): void
    {
        User::factory()->create(['phone' => '01011122233', 'type' => 'driver']);

        $ticket = $this->startGoogle('google-1');
        $this->sendOtp('01011122233');

        $this->postJson('/api/v1/auth/social/link', [
            'link_ticket' => $ticket,
            'phone' => '01011122233',
            'code' => '123456',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error', 'wrong_client')
            ->assertJsonPath('meta.account_type', 'driver');

        // Nothing half-happened: no identity attached, and the ticket is still spendable.
        $this->assertSame(0, SocialAccount::query()->count());
        $this->assertNull(SocialLinkTicket::query()->firstOrFail()->consumed_at);
    }

    /**
     * Two devices, one Apple account, fifteen minutes of overlap.
     *
     * Both start the flow before either finishes, so two live tickets exist for the same
     * subject. The second one to be spent must be refused by name — without the check it
     * collides on the unique index and answers a 500.
     */
    public function test_one_provider_identity_belongs_to_one_account(): void
    {
        $first = $this->startGoogle('google-1');
        $second = $this->startGoogle('google-1');

        $this->sendOtp('01011122233');
        $this->postJson('/api/v1/auth/social/link', [
            'link_ticket' => $first, 'phone' => '01011122233', 'code' => '123456',
        ])->assertOk();

        $this->sendOtp('01011122244');
        $this->postJson('/api/v1/auth/social/link', [
            'link_ticket' => $second, 'phone' => '01011122244', 'code' => '123456',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'provider_already_linked');

        $this->assertSame(0, User::query()->where('phone', '01011122244')->count());
    }

    /** The second tap on «المتابعة باستخدام Google» is a sign-in, not a second ticket. */
    public function test_a_linked_identity_never_asks_for_a_phone_again(): void
    {
        $this->linkGoogle('google-1', '01011122233');

        $this->postJson('/api/v1/auth/social', [
            'provider' => 'google',
            'id_token' => $this->googleToken('google-1'),
        ])->assertOk()->assertJsonPath('data.status', 'signed_in');
    }

    public function test_an_account_keeps_at_most_one_identity_per_provider(): void
    {
        $this->linkGoogle('google-1', '01011122233');

        // A different Google account, the same phone.
        $ticket = $this->startGoogle('google-2');
        $this->sendOtp('01011122233');

        $this->postJson('/api/v1/auth/social/link', [
            'link_ticket' => $ticket,
            'phone' => '01011122233',
            'code' => '123456',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'provider_conflict');

        $this->assertSame(1, SocialAccount::query()->count());
    }

    public function test_a_link_ticket_is_spent_once(): void
    {
        $ticket = $this->startGoogle('google-1');
        $this->sendOtp('01011122233');

        $this->postJson('/api/v1/auth/social/link', [
            'link_ticket' => $ticket, 'phone' => '01011122233', 'code' => '123456',
        ])->assertOk();

        $this->sendOtp('01011122244');

        $this->postJson('/api/v1/auth/social/link', [
            'link_ticket' => $ticket, 'phone' => '01011122244', 'code' => '123456',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'social_link_expired');
    }

    public function test_a_wrong_code_does_not_link_anything(): void
    {
        $ticket = $this->startGoogle('google-1');
        $this->sendOtp('01011122233');

        $this->postJson('/api/v1/auth/social/link', [
            'link_ticket' => $ticket, 'phone' => '01011122233', 'code' => '000000',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'otp_invalid');

        $this->assertSame(0, SocialAccount::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    /** A client-supplied identity is worth nothing on its own — this is the whole point. */
    public function test_an_unverifiable_token_never_reaches_the_database(): void
    {
        $this->postJson('/api/v1/auth/social', [
            'provider' => 'google',
            'id_token' => 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJhdHRhY2tlciJ9.',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error', 'social_token_invalid');

        $this->assertSame(0, SocialLinkTicket::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_provider_with_no_client_ids_is_refused_rather_than_trusted(): void
    {
        $token = $this->googleToken('google-1');
        config(['services.google.client_ids' => '']);

        $this->postJson('/api/v1/auth/social', ['provider' => 'google', 'id_token' => $token])
            ->assertStatus(501)
            ->assertJsonPath('error', 'social_not_configured');
    }

    public function test_an_unknown_provider_is_refused_by_validation(): void
    {
        $this->postJson('/api/v1/auth/social', ['provider' => 'facebook', 'id_token' => str_repeat('a', 40)])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
    }

    public function test_a_suspended_account_cannot_sign_in_with_its_provider(): void
    {
        $this->linkGoogle('google-1', '01011122233');

        User::query()->where('phone', '01011122233')->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/social', [
            'provider' => 'google',
            'id_token' => $this->googleToken('google-1'),
        ])
            ->assertStatus(423)
            ->assertJsonPath('error', 'account_suspended');
    }

    // ───────────────────────────────── helpers

    private function startGoogle(string $subject): string
    {
        return $this->postJson('/api/v1/auth/social', [
            'provider' => 'google',
            'id_token' => $this->googleToken($subject, [
                'email' => "{$subject}@example.com",
                'email_verified' => true,
                'name' => 'أحمد الشناوي',
            ]),
        ])->assertOk()->json('data.link_ticket');
    }

    private function linkGoogle(string $subject, string $phone): void
    {
        $ticket = $this->startGoogle($subject);
        $this->sendOtp($phone);

        $this->postJson('/api/v1/auth/social/link', [
            'link_ticket' => $ticket,
            'phone' => $phone,
            'code' => '123456',
        ])->assertOk();
    }

    /** The same `POST /auth/otp/send` the phone screen already calls — no parallel path. */
    private function sendOtp(string $phone): void
    {
        $this->postJson('/api/v1/auth/otp/send', ['phone' => $phone, 'purpose' => 'login'])->assertOk();
    }
}
