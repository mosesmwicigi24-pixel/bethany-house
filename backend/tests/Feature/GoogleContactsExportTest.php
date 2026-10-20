<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Support\GoogleContactsCsv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The phone book for Google Contacts (owner, 2026-10-20): one row per phone,
 * the best name the hub knows, an empty name when it knows none — never an
 * invented label. The door: the owner account only, password and two-step
 * code typed each time.
 */
class GoogleContactsExportTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_EMAIL = 'mwicigi@icloud.com';
    private const PASSWORD    = 'correct-horse';

    private Google2FA $g;
    private string $secret;
    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        config(['audit.owner_account_email' => self::OWNER_EMAIL]);
        $this->g      = new Google2FA();
        $this->secret = $this->g->generateSecretKey();
        $this->outlet = Outlet::factory()->create(['country_code' => 'KE']);
    }

    /** The owner row already exists (migration 2026_07_07_100100 makes mwicigi@icloud.com the super admin); reuse it. */
    private function superAdmin(string $email, bool $twoFactor = true): User
    {
        $attrs = ['status' => 'active', 'user_type' => 'staff', 'password' => bcrypt(self::PASSWORD),
            'two_factor_enabled' => $twoFactor, 'two_factor_secret' => $twoFactor ? encrypt($this->secret) : null];
        $u = User::where('email', $email)->first();
        $u ? $u->forceFill($attrs)->save() : $u = User::factory()->create(['email' => $email] + $attrs);
        $u->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function code(): string
    {
        return $this->g->getCurrentOtp($this->secret);
    }

    /** The phone book: a named customer, a blank customer named on her order, a guest order, a nameless phone, two spellings, a lead. */
    private function phoneBook(): void
    {
        $ann = Customer::create(['customer_number' => 'C-1', 'first_name' => 'Ann', 'last_name' => 'Mwangi', 'email' => 'ann@example.test', 'phone' => '0722000111']);
        Order::factory()->create(['customer_id' => $ann->id, 'customer_phone' => '+254722000111', 'customer_first_name' => 'Annie', 'customer_last_name' => 'M',
            'outlet_id' => $this->outlet->id, 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => '2026-09-01 10:00:00']);

        $blank = Customer::create(['customer_number' => 'C-2', 'first_name' => '', 'last_name' => '', 'email' => 'c2@example.local', 'phone' => '0733000222']);
        Order::factory()->create(['customer_id' => $blank->id, 'customer_phone' => '0733000222', 'customer_first_name' => 'Mataia', 'customer_last_name' => '',
            'outlet_id' => $this->outlet->id, 'status' => 'confirmed', 'payment_status' => 'paid', 'created_at' => '2026-10-18 09:00:00']);

        Order::factory()->create(['customer_id' => null, 'customer_phone' => '254744000333', 'customer_first_name' => 'Peter', 'customer_last_name' => 'Otieno',
            'outlet_id' => $this->outlet->id, 'status' => 'confirmed', 'payment_status' => 'pending', 'created_at' => '2026-10-10 09:00:00']);

        Order::factory()->create(['customer_id' => null, 'customer_phone' => '0755000444', 'customer_first_name' => null, 'customer_last_name' => null,
            'outlet_id' => $this->outlet->id, 'status' => 'pending', 'payment_status' => 'pending']);

        DB::table('leads')->insert(['client_request_id' => 'L-1', 'intent' => 'quote', 'name' => 'Grace Wanjiru Kamau', 'phone' => '0766000555', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('leads')->insert(['client_request_id' => 'L-2', 'intent' => 'quote', 'name' => 'Should Not Win', 'phone' => '+254722000111', 'created_at' => now(), 'updated_at' => now()]);
    }

    // ── the file ─────────────────────────────────────────────────────────────

    public function test_one_row_per_phone_with_the_best_name_and_no_invented_names(): void
    {
        $this->phoneBook();
        $built = GoogleContactsCsv::build();
        $rows  = collect($built['rows'])->keyBy('Phone 1 - Value');

        $this->assertSame(5, $built['stats']['contacts'], 'five phones; 0722… and +254722… are one person');
        $this->assertSame(['Ann', 'Mwangi'], [$rows['+254722000111']['Given Name'], $rows['+254722000111']['Family Name']], 'the customer record beats the order name and the lead');
        $this->assertSame('Mataia', $rows['+254733000222']['Name'], 'a blank customer record takes the name typed on her order');
        $this->assertSame('Peter Otieno', $rows['+254744000333']['Name'], 'a guest order names its phone');
        $this->assertSame('', $rows['+254755000444']['Name'], 'no name anywhere: left empty, never invented');
        $this->assertStringContainsString('No name on file', $rows['+254755000444']['Notes']);
        $this->assertSame(['Grace', 'Wanjiru Kamau'], [$rows['+254766000555']['Given Name'], $rows['+254766000555']['Family Name']], 'a lead is split given/family');
        $this->assertStringContainsString('Customer C-1', $rows['+254722000111']['Notes']);
        $this->assertStringContainsString('1 order', $rows['+254722000111']['Notes']);
        $this->assertStringContainsString('Last order 2026-09-01', $rows['+254722000111']['Notes']);
        $this->assertSame('', $rows['+254733000222']['E-mail 1 - Value'], 'a .local placeholder email is not an email');
        $this->assertSame('ann@example.test', $rows['+254722000111']['E-mail 1 - Value']);
        $this->assertSame(GoogleContactsCsv::GROUP . ' ::: * myContacts', $rows['+254722000111']['Group Membership']);
        $this->assertSame(['named' => 4, 'unnamed' => 1, 'merged_spellings' => 1], ['named' => $built['stats']['named'], 'unnamed' => $built['stats']['unnamed'], 'merged_spellings' => $built['stats']['merged_spellings']]);
        $this->assertStringNotContainsString('Bethany Contact', GoogleContactsCsv::csv($built['rows']));
    }

    public function test_placeholder_names_on_file_count_as_no_name(): void
    {
        Customer::create(['customer_number' => 'C-9', 'first_name' => 'Bethany Contact 0404', 'last_name' => '', 'email' => 'c9@example.local', 'phone' => '0799000999']);
        Customer::create(['customer_number' => 'C-10', 'first_name' => 'Guest', 'last_name' => 'Unknown', 'email' => 'c10@example.local', 'phone' => '0799000998']);

        $rows = collect(GoogleContactsCsv::build()['rows'])->keyBy('Phone 1 - Value');
        $this->assertSame('', $rows['+254799000999']['Name']);
        $this->assertSame('', $rows['+254799000998']['Name']);
    }

    // ── the door ─────────────────────────────────────────────────────────────

    public function test_the_owner_takes_the_file_with_password_and_code_and_it_is_logged(): void
    {
        $this->phoneBook();
        Sanctum::actingAs($this->superAdmin(self::OWNER_EMAIL));

        $r = $this->post('/api/v1/admin/customers/export/google-contacts', ['password' => self::PASSWORD, 'code' => $this->code()]);
        $r->assertOk();
        $this->assertStringStartsWith('text/csv', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('google-contacts-', $r->headers->get('Content-Disposition'));
        $this->assertSame('5', $r->headers->get('X-Contacts'));
        $this->assertSame('1', $r->headers->get('X-Contacts-Unnamed'));
        $body = $r->getContent();
        // fputcsv quotes any cell with a space, which Google's importer reads as plain text.
        $this->assertStringContainsString('Name,"Given Name","Family Name","Phone 1 - Type","Phone 1 - Value"', $body);
        $this->assertStringContainsString('"Ann Mwangi",Ann,Mwangi,Mobile,+254722000111', $body);
        $this->assertStringContainsString(',,,Mobile,+254755000444,,,"No name on file', $body);
        $this->assertDatabaseHas('activity_log', ['event' => 'contacts_export_taken']);
    }

    public function test_another_super_admin_is_refused_even_with_the_right_password_and_code(): void
    {
        $this->phoneBook();
        Sanctum::actingAs($this->superAdmin('someone.else@example.test'));

        $this->postJson('/api/v1/admin/customers/export/google-contacts', ['password' => self::PASSWORD, 'code' => $this->code()])
            ->assertStatus(403)->assertJsonPath('message', 'Only the owner account can take the contacts file.');
        $this->assertDatabaseHas('activity_log', ['event' => 'contacts_export_refused']);
    }

    public function test_a_wrong_password_or_code_is_refused_and_five_tries_lock_the_door(): void
    {
        $this->phoneBook();
        Sanctum::actingAs($this->superAdmin(self::OWNER_EMAIL));

        $this->postJson('/api/v1/admin/customers/export/google-contacts', ['password' => 'wrong', 'code' => $this->code()])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'That password is not right.');
        $this->postJson('/api/v1/admin/customers/export/google-contacts', ['password' => self::PASSWORD, 'code' => '000000'])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'That code is not right.');
        $this->postJson('/api/v1/admin/customers/export/google-contacts', ['password' => self::PASSWORD])->assertStatus(422);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/admin/customers/export/google-contacts', ['password' => 'wrong', 'code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/v1/admin/customers/export/google-contacts', ['password' => self::PASSWORD, 'code' => $this->code()])
            ->assertStatus(429, 'five wrong tries: locked, even with the right password and code');
    }

    public function test_an_owner_without_two_step_sign_in_cannot_take_it(): void
    {
        Sanctum::actingAs($this->superAdmin(self::OWNER_EMAIL, twoFactor: false));

        $this->postJson('/api/v1/admin/customers/export/google-contacts', ['password' => self::PASSWORD, 'code' => '123456'])
            ->assertStatus(403)->assertJsonFragment(['message' => 'Two-step sign-in must be on for this account before the contacts file can be taken.']);
    }
}
