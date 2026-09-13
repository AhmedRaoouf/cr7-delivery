<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOtpChannel;
use Tests\Concerns\RecordingOtpChannel;
use Tests\TestCase;

/**
 * D-112 — «برقم الموبايل اللي مسجل بيه ودا الرقم الاساسي في اي عنوان هيتضاف طالما محطش رقم
 * تاني ولما ييجي يحط رقم تاني هيوديه لتفعيل الرقم ب sms والتفعيل دا بيتم التحكم فيه من
 * الداشبورد».
 */
class AddressPhoneVerificationTest extends TestCase
{
    use FakesOtpChannel;
    use RefreshDatabase;

    private const OTHER = '01090009599';

    private User $customer;

    private RecordingOtpChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->channel = $this->fakeOtpChannel();
        $this->otpSmsMode();

        $this->customer = $this->makeCustomer();
        Sanctum::actingAs($this->customer);
        $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar']);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'home',
            'street' => 'شارع البحر',
            'zone_id' => $this->zone->id,
            'latitude' => 31.3835,
            'longitude' => 31.4835,
        ], $overrides);
    }

    public function test_the_accounts_own_number_is_stored_as_none(): void
    {
        $this->postJson('/api/v1/addresses', $this->payload(['contact_phone' => $this->customer->phone]))
            ->assertCreated()
            ->assertJsonPath('data.contact_phone', null)
            ->assertJsonPath('data.effective_contact_phone', $this->customer->phone)
            ->assertJsonPath('data.uses_account_phone', true);
    }

    public function test_another_number_is_refused_until_it_is_proven_and_then_saved(): void
    {
        $this->postJson('/api/v1/addresses', $this->payload(['contact_phone' => '+20 109 000 9599']))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'phone_not_verified')
            ->assertJsonPath('message', 'يلزم تأكيد هذا الرقم برسالة SMS قبل حفظه')
            ->assertJsonPath('errors.contact_phone.0', 'يلزم تأكيد هذا الرقم برسالة SMS قبل حفظه');

        $this->assertSame(0, UserAddress::query()->count());

        $this->postJson('/api/v1/addresses/phone/send-code', ['phone' => '+20 109 000 9599'])
            ->assertOk()
            ->assertJsonPath('data.phone', self::OTHER)
            ->assertJsonPath('data.sent', true)
            ->assertJsonPath('data.resend_in', 60)
            ->assertJsonPath('data.static_otp', null);

        $code = $this->channel->lastCodeFor(self::OTHER);
        $this->assertNotNull($code);

        // A wrong code proves nothing.
        $this->postJson('/api/v1/addresses/phone/verify', ['phone' => self::OTHER, 'code' => $code === '000000' ? '111111' : '000000'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'otp_invalid');
        $this->assertSame(0, $this->customer->verifiedPhones()->count());

        $verified = $this->postJson('/api/v1/addresses/phone/verify', ['phone' => self::OTHER, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.phone', self::OTHER)
            ->assertJsonPath('data.verified', true);

        // Never a session, never an account — unlike a `login` or `verify` code.
        $this->assertNull($verified->json('data.token'));
        $this->assertNull(User::query()->where('phone', self::OTHER)->first());
        $this->assertSame(1, $this->customer->verifiedPhones()->where('phone', self::OTHER)->count());

        $this->postJson('/api/v1/addresses', $this->payload(['contact_phone' => self::OTHER]))
            ->assertCreated()
            ->assertJsonPath('data.contact_phone', self::OTHER)
            ->assertJsonPath('data.effective_contact_phone', self::OTHER)
            ->assertJsonPath('data.uses_account_phone', false);

        // A number once proven is the account's to reuse, on any address, without a code.
        $this->postJson('/api/v1/addresses', $this->payload(['label' => 'work', 'contact_phone' => self::OTHER]))
            ->assertCreated();
        $this->assertCount(1, $this->channel->sent);
    }

    public function test_nothing_is_sent_for_a_number_there_is_nothing_to_prove(): void
    {
        $this->customer->verifiedPhones()->create(['phone' => self::OTHER, 'verified_at' => now()]);

        foreach ([$this->customer->phone, self::OTHER] as $phone) {
            $this->postJson('/api/v1/addresses/phone/send-code', ['phone' => $phone])
                ->assertOk()
                ->assertJsonPath('data.sent', false)
                ->assertJsonPath('data.already_verified', true);
        }

        $this->assertSame([], $this->channel->sent);
        $this->assertSame(0, OtpCode::query()->count());
    }

    public function test_with_alternates_off_only_the_accounts_number_is_accepted(): void
    {
        Settings::set('addresses.alternate_phone_allowed', false, 'addresses');

        $this->postJson('/api/v1/addresses', $this->payload(['contact_phone' => self::OTHER]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'alternate_phone_not_allowed')
            ->assertJsonPath('message', 'لا يمكن استخدام رقم غير رقم حسابك في العنوان')
            ->assertJsonPath('errors.contact_phone.0', 'لا يمكن استخدام رقم غير رقم حسابك في العنوان');

        $this->postJson('/api/v1/addresses/phone/send-code', ['phone' => self::OTHER])
            ->assertStatus(422)
            ->assertJsonPath('error', 'alternate_phone_not_allowed');

        $this->postJson('/api/v1/addresses/phone/verify', ['phone' => self::OTHER, 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'alternate_phone_not_allowed');

        $this->assertSame([], $this->channel->sent);

        $this->postJson('/api/v1/addresses', $this->payload(['contact_phone' => $this->customer->phone]))
            ->assertCreated();
    }

    public function test_with_proof_off_another_number_is_saved_as_typed(): void
    {
        Settings::set('addresses.alternate_phone_requires_otp', false, 'addresses');

        $this->postJson('/api/v1/addresses', $this->payload(['contact_phone' => self::OTHER]))
            ->assertCreated()
            ->assertJsonPath('data.contact_phone', self::OTHER);

        $this->assertSame([], $this->channel->sent);
    }

    public function test_an_edit_to_an_unproven_number_is_refused_the_same_way(): void
    {
        $address = $this->makeAddress($this->customer);

        $this->patchJson("/api/v1/addresses/{$address->id}", ['contact_phone' => self::OTHER])
            ->assertStatus(422)
            ->assertJsonPath('error', 'phone_not_verified');

        $this->assertNull($address->fresh()->contact_phone);

        // Setting it back to the account's number clears it, which is what it means.
        $address->forceFill(['contact_phone' => '01555000111'])->save();
        $this->patchJson("/api/v1/addresses/{$address->id}", ['contact_phone' => $this->customer->phone])
            ->assertOk()
            ->assertJsonPath('data.contact_phone', null);
    }

    public function test_deleting_the_default_hands_the_default_on(): void
    {
        $first = $this->makeAddress($this->customer);
        $second = $this->makeAddress($this->customer);
        $third = $this->makeAddress($this->customer);

        $this->assertSame($third->id, $this->customer->fresh()->default_address_id);

        $this->deleteJson("/api/v1/addresses/{$third->id}")->assertOk();

        // To the most recently added address that is left.
        $this->assertSame($second->id, $this->customer->fresh()->default_address_id);
        $this->assertTrue($second->fresh()->is_default);

        // Deleting one that is not the default leaves the default where it is.
        $this->deleteJson("/api/v1/addresses/{$first->id}")->assertOk();
        $this->assertSame($second->id, $this->customer->fresh()->default_address_id);

        // And the last one leaves none, rather than a pointer to a deleted row.
        $this->deleteJson("/api/v1/addresses/{$second->id}")->assertOk();
        $this->assertNull($this->customer->fresh()->default_address_id);
    }
}
