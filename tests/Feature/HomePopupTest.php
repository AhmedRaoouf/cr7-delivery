<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\HomePopup;
use App\Models\StoreCategory;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «العرض المنبثق» — the card the home screen opens with, and the fact that the operator
 * owns every part of it.
 *
 * «تخلي أول ما يخش على الهوم بيظهر له كرت كده فيه علامة X… الأدمين بيحدد شكله من
 * الداشبورد، فاديله الأكسيس إن هو يعمل أي حاجة فيه، يحط زرار، يحط زرارين، يظبط كل حاجة،
 * الصورة، الكلام على الصورة… وتخليه أصلا إن هو يظهر ولا ما يظهرش… وطبعا لما يدوس عليه
 * يروح في المكان الصح بتاعه».
 *
 * So what is under test is not the card that ships — it is the one written at 2am from
 * the dashboard, the switch that takes it off the air, and the promise that «اطلب الآن»
 * lands somewhere.
 */
class HomePopupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
        Storage::fake('public');
    }

    private function actingAsAdmin(string $role = 'super_admin'): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole($role);
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);
        $this->withHeader('X-App', 'admin');

        return $admin;
    }

    private function makePopup(array $attributes): HomePopup
    {
        return HomePopup::query()->create($attributes + ['link_type' => 'none']);
    }

    /** @return array<int, array<string, mixed>> */
    private function appSees(string $language = 'ar'): array
    {
        return $this->withHeader('X-App', 'app')
            ->withHeader('Accept-Language', $language)
            ->getJson('/api/v1/home-popups')
            ->assertOk()
            ->json('data');
    }

    /**
     * The switch, the window and the order — the three things that decide whether a card
     * is drawn over the first screen the customer sees.
     */
    public function test_the_app_is_shown_only_the_cards_that_are_live_now_in_the_operators_order(): void
    {
        $this->makePopup(['title_ar' => 'الكرت الثاني', 'sort_order' => 5]);
        $this->makePopup(['title_ar' => 'الكرت الأول', 'sort_order' => 1]);
        $this->makePopup(['title_ar' => 'مطفأ', 'is_active' => false]);
        $this->makePopup(['title_ar' => 'لم يبدأ بعد', 'starts_at' => now()->addDay()]);
        $this->makePopup(['title_ar' => 'انتهى موسمه', 'ends_at' => now()->subDay()]);
        // No window at all is «حتى أقول غير ذلك», not «expired».
        $this->makePopup(['title_ar' => 'بلا مواعيد', 'sort_order' => 9, 'starts_at' => null, 'ends_at' => null]);

        $this->assertSame(
            ['الكرت الأول', 'الكرت الثاني', 'بلا مواعيد'],
            array_column($this->appSees(), 'title'),
        );
    }

    /**
     * «تبقى ربطه بالحاجات اللي جوا برضه زي العروض» — the app resolves a banner's
     * destination in one place, and a popup has to arrive in that same shape or the
     * resolver has to learn a second one.
     */
    public function test_a_card_speaks_the_same_destination_shape_as_a_banner(): void
    {
        $restaurant = $this->makeRestaurant('بوكس ع السريع');
        $product = $this->makeProduct($restaurant, 'برجر سماش', 130);
        $category = StoreCategory::query()->create([
            'slug' => 'pharmacies', 'name_ar' => 'صيدليات', 'name_en' => 'Pharmacies',
        ]);

        $this->makePopup([
            'title_ar' => 'المنتج', 'sort_order' => 0,
            'link_type' => 'product', 'product_id' => $product->id,
        ]);
        $this->makePopup([
            'title_ar' => 'التصنيف', 'sort_order' => 1,
            'link_type' => 'category', 'store_category_id' => $category->id,
        ]);
        $this->makePopup([
            'title_ar' => 'بلا وجهة', 'sort_order' => 2,
        ]);

        $cards = $this->appSees();

        $this->assertSame('product', $cards[0]['link_type']);
        $this->assertSame((string) $product->id, $cards[0]['link_value']);

        $this->assertSame('category', $cards[1]['link_type']);
        // A slug, not an id: `filter[store_category]` is what the app sends it back as,
        // and a slug survives the operator renaming the category.
        $this->assertSame('pharmacies', $cards[1]['link_value']);

        $this->assertSame('none', $cards[2]['link_type']);
        $this->assertNull($cards[2]['link_value']);
    }

    /** Accept-Language picks the copy, exactly as it does for a product or a category. */
    public function test_the_card_answers_in_the_language_that_was_asked_for(): void
    {
        $this->makePopup([
            'title_ar' => 'أهلًا بك', 'title_en' => 'Welcome',
            'primary_label_ar' => 'اطلب الآن', 'primary_label_en' => 'Order now',
        ]);

        $this->assertSame('أهلًا بك', $this->appSees('ar')[0]['title']);
        $this->assertSame('اطلب الآن', $this->appSees('ar')[0]['primary_label']);
        $this->assertSame('Welcome', $this->appSees('en')[0]['title']);
        $this->assertSame('Order now', $this->appSees('en')[0]['primary_label']);
    }

    /**
     * The card is marketing, so it is gated on the permission the banners beside it use.
     *
     * Reading the queue is not gated — an operator who cannot author one can still see
     * what the customer is being shown, the way `/admin/banners` and
     * `/admin/store-categories` already work.
     */
    public function test_an_admin_without_the_banner_permission_cannot_author_a_card(): void
    {
        $this->actingAsAdmin('support');

        $this->getJson('/api/v1/admin/home-popups')->assertOk();

        $this->postJson('/api/v1/admin/home-popups', ['title_ar' => 'عرض'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');

        $popup = $this->makePopup(['title_ar' => 'عرض قائم']);

        $this->postJson("/api/v1/admin/home-popups/{$popup->id}", ['_method' => 'PATCH', 'title_ar' => 'تعديل'])
            ->assertStatus(403);
        $this->deleteJson("/api/v1/admin/home-popups/{$popup->id}")->assertStatus(403);
        $this->postJson('/api/v1/admin/home-popups-reorder', ['ids' => [$popup->id]])->assertStatus(403);

        $this->assertSame('عرض قائم', $popup->fresh()->title_ar);
    }

    /** The whole life of one card, art included. */
    public function test_an_operator_authors_a_card_replaces_its_art_and_retires_it(): void
    {
        $this->actingAsAdmin();
        $restaurant = $this->makeRestaurant('بوكس ع السريع');
        $product = $this->makeProduct($restaurant, 'برجر سماش', 130);

        $created = $this->post('/api/v1/admin/home-popups', [
            'title_ar' => 'أهلًا بك في CR7 Delivery',
            'title_en' => 'Welcome to CR7 Delivery',
            'body_ar' => 'ابدأ بأكثر طبق يُطلب في جمصة.',
            'link_type' => 'product',
            'product_id' => $product->id,
            'primary_label_ar' => 'اطلب الآن',
            'primary_label_en' => 'Order now',
            'secondary_label_ar' => 'ليس الآن',
            'secondary_label_en' => 'Not now',
            'theme' => '#E10600',
            'image' => UploadedFile::fake()->image('welcome.png', 800, 1000),
        ])->assertCreated();

        $id = $created->json('data.id');
        $firstArt = $created->json('data.image');

        $this->assertNotNull($firstArt);
        Storage::disk('public')->assertExists('home-popups/'.basename($firstArt));
        $this->assertSame((string) $product->id, $created->json('data.link_value'));
        // Not sent, so the shipped defaults are what a card gets.
        $this->assertSame('once_per_day', $created->json('data.frequency'));
        $this->assertSame('dismiss', $created->json('data.secondary_action'));
        $this->assertTrue($created->json('data.is_active'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'home_popups.create']);

        // `_method=PATCH` over POST, because PHP does not populate `$_FILES` on a PATCH.
        $updated = $this->post("/api/v1/admin/home-popups/{$id}", [
            '_method' => 'PATCH',
            'title_ar' => 'أهلًا بك',
            'image' => UploadedFile::fake()->image('welcome-2.png', 800, 1000),
        ])->assertOk();

        $secondArt = $updated->json('data.image');

        $this->assertNotSame($firstArt, $secondArt);
        Storage::disk('public')->assertExists('home-popups/'.basename($secondArt));
        // A replaced picture takes the old file with it, or a season of edits is a
        // season of orphans on a disk nobody sweeps.
        Storage::disk('public')->assertMissing('home-popups/'.basename($firstArt));
        // The destination was not in the PATCH body and must survive it.
        $this->assertSame((string) $product->id, $updated->json('data.link_value'));

        $this->deleteJson("/api/v1/admin/home-popups/{$id}")->assertOk();

        $this->assertSoftDeleted('home_popups', ['id' => $id]);
        $this->assertSame([], $this->appSees());
    }

    /**
     * The artwork can be taken **off**, not only replaced.
     *
     * A multipart body cannot carry a JSON null, so an absent file has to mean «leave it
     * alone» — otherwise a PATCH that changes only the headline would blank the picture
     * of a live card. Which leaves no way at all to clear one unless there is a sibling
     * boolean, and there was not: the dashboard could put the wrong artwork on a card and
     * then only ever swap it for other artwork.
     */
    public function test_the_artwork_can_be_taken_off_a_card_and_not_merely_replaced(): void
    {
        $this->actingAsAdmin();

        $created = $this->post('/api/v1/admin/home-popups', [
            'title_ar' => 'إعلان',
            'link_type' => 'none',
            'image' => UploadedFile::fake()->image('art.png', 800, 1000),
        ])->assertCreated();

        $id = $created->json('data.id');
        $art = $created->json('data.image');
        Storage::disk('public')->assertExists('home-popups/'.basename($art));

        // An edit that carries no file leaves the picture exactly where it was.
        $this->post("/api/v1/admin/home-popups/{$id}", [
            '_method' => 'PATCH',
            'title_ar' => 'إعلان جديد',
        ])->assertOk()->assertJsonPath('data.image', $art);

        Storage::disk('public')->assertExists('home-popups/'.basename($art));

        // The sibling boolean is the only thing that clears it — and it takes the file.
        $this->post("/api/v1/admin/home-popups/{$id}", [
            '_method' => 'PATCH',
            'remove_image' => true,
        ])->assertOk()->assertJsonPath('data.image', null);

        Storage::disk('public')->assertMissing('home-popups/'.basename($art));
    }

    /** «يظهر كذا واحد يعني ورا بعض» — and the operator decides in what order. */
    public function test_the_queue_is_reordered_in_one_request(): void
    {
        $this->actingAsAdmin();

        $ids = collect(['الأول', 'الثاني', 'الثالث'])
            ->map(fn (string $title, int $i) => $this->makePopup(['title_ar' => $title, 'sort_order' => $i])->id)
            ->all();

        $this->postJson('/api/v1/admin/home-popups-reorder', ['ids' => array_reverse($ids)])->assertOk();

        $this->assertSame(
            ['الثالث', 'الثاني', 'الأول'],
            array_column($this->appSees(), 'title'),
        );
    }

    /**
     * A card that promises a dish has to carry one.
     *
     * This is the banners' own defect, refused before it is stored: `link_type` and the
     * five columns that could hold a destination are independent, so nothing but this
     * stops «اطلب الآن» from being a button that swallows the tap.
     */
    public function test_a_card_that_promises_a_destination_must_carry_one(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/home-popups', [
            'title_ar' => 'عرض بلا وجهة',
            'link_type' => 'product',
            'primary_label_ar' => 'اطلب الآن',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.product_id.0', 'اختر المنتج الذي يفتحه الكرت.');

        $this->postJson('/api/v1/admin/home-popups', [
            'title_ar' => 'عرض بلا مطعم', 'link_type' => 'restaurant',
        ])->assertStatus(422)->assertJsonPath('errors.restaurant_id.0', 'اختر المطعم الذي يفتحه الكرت.');

        $this->postJson('/api/v1/admin/home-popups', [
            'title_ar' => 'عرض بلا تصنيف', 'link_type' => 'category',
        ])->assertStatus(422)->assertJsonPath('errors.store_category_id.0', 'اختر التصنيف الذي يفتحه الكرت.');

        $this->postJson('/api/v1/admin/home-popups', [
            'title_ar' => 'عرض بلا كود', 'link_type' => 'promo',
        ])->assertStatus(422)->assertJsonPath('errors.code.0', 'أدخل كود الخصم الذي يحمله الكرت.');

        $this->postJson('/api/v1/admin/home-popups', [
            'title_ar' => 'عرض برابط مكسور', 'link_type' => 'url', 'url' => 'htp:/كسر',
        ])->assertStatus(422)->assertJsonValidationErrors('url');

        // «يحط زرارين» — a second button that navigates but carries no words is a blank
        // rectangle beside «اطلب الآن».
        $this->postJson('/api/v1/admin/home-popups', [
            'title_ar' => 'زر بلا كلام', 'secondary_action' => 'link',
        ])->assertStatus(422)->assertJsonPath('errors.secondary_label_ar.0', 'أدخل نص الزر الثاني قبل ربطه بوجهة.');

        $this->assertSame(0, HomePopup::query()->count());
    }

    /**
     * Switching a live card's destination is judged against the row it will become, not
     * against the two fields the PATCH happens to carry.
     */
    public function test_switching_a_live_cards_destination_cannot_leave_it_pointing_nowhere(): void
    {
        $this->actingAsAdmin();
        $popup = $this->makePopup(['title_ar' => 'عرض', 'link_type' => 'promo', 'code' => 'WELCOME20']);

        $this->postJson("/api/v1/admin/home-popups/{$popup->id}", [
            '_method' => 'PATCH', 'link_type' => 'product',
        ])->assertStatus(422)->assertJsonPath('errors.product_id.0', 'اختر المنتج الذي يفتحه الكرت.');

        $restaurant = $this->makeRestaurant('بوكس ع السريع');
        $product = $this->makeProduct($restaurant, 'برجر سماش', 130);

        $this->postJson("/api/v1/admin/home-popups/{$popup->id}", [
            '_method' => 'PATCH', 'link_type' => 'product', 'product_id' => $product->id,
        ])->assertOk();

        $popup->refresh();

        $this->assertSame($product->id, $popup->product_id);
        // The old destination is cleared, not left behind: the app's resolver reads the
        // ids before it reads `link_type`, so a stale one is what the button would open.
        $this->assertNull($popup->code);
    }
}
