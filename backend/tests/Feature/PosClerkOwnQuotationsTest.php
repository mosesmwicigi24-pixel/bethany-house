<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A clerk sees the quotations she raised, not the shop's.
 *
 * Orders were bounded in #286/#287 and quotations were not, so the leak moved
 * one document upstream rather than closing: the same cashier whose colleagues'
 * sales were hidden from her could still open the quotation list and read every
 * price the shop had offered, with the customer's name, email and phone beside
 * it. Quotation now carries the same boundary Order does — created_by, the
 * member of staff who raised it.
 *
 * Two things must NOT narrow with it, and both are here because both bit when
 * Order was scoped:
 *
 *   the customer's public /quote/{token} link, which has no viewer at all; and
 *   the QUO number generator, which asks a question about the table rather than
 *   about the caller, and would otherwise hand two clerks the same number.
 */
class PosClerkOwnQuotationsTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $this->outlet = Outlet::factory()->create();
    }

    // ── actors ───────────────────────────────────────────────────────────────

    private function clerk(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('pos_clerk', 'sanctum'));
        $user->outlets()->attach($this->outlet->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    /** The escalation target: holds quotations.* and is not scoped. */
    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    /** Somebody else on the shop floor — never the caller. */
    private function otherClerk(): User
    {
        return tap(User::factory()->create(), function (User $u) {
            $u->assignRole(Role::findByName('pos_clerk', 'sanctum'));
        })->fresh();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function quoteBy(?User $creator, string $customer = 'Jane'): Quotation
    {
        $quotation = Quotation::create([
            'outlet_id'           => $this->outlet->id,
            'source'              => 'admin',
            'status'              => Quotation::DRAFT,
            'currency_code'       => 'KES',
            'customer_first_name' => $customer,
            'customer_email'      => strtolower($customer) . '@example.test',
            'subtotal'            => 2000,
            'tax_amount'          => 0,
            'total_amount'        => 2000,
            'created_by'          => $creator?->id,
        ]);

        QuotationItem::create([
            'quotation_id'    => $quotation->id,
            'product_id'      => Product::factory()->create()->id,
            'product_name'    => 'Cassock',
            'quantity'        => 2,
            'unit_price'      => 1000,
            'discount_amount' => 0,
            'tax_amount'      => 0,
            'total_price'     => 2000,
        ]);

        return $quotation->fresh('items');
    }

    // ── the leak closes ──────────────────────────────────────────────────────

    public function test_a_clerk_sees_only_the_quotations_she_raised(): void
    {
        $theirs = $this->quoteBy($this->otherClerk());
        $clerk  = $this->clerk();
        $mine   = $this->quoteBy($clerk);

        $ids = collect($this->getJson('/api/v1/admin/quotations')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id), "another clerk's quotation must not appear");
    }

    public function test_the_detail_route_is_bounded_too(): void
    {
        // The IDOR: quotation ids are sequential and enumerable, and the detail
        // view carries the full line-by-line pricing.
        $theirs = $this->quoteBy($this->otherClerk());
        $this->clerk();

        $this->getJson("/api/v1/admin/quotations/{$theirs->id}")->assertNotFound();
    }

    public function test_the_search_box_narrows_with_the_list(): void
    {
        // The search ran on Quotation::query() like the list did, so it was the
        // way around an empty list: type a customer's name and read their quote.
        $theirs = $this->quoteBy($this->otherClerk(), 'Mercy');
        $clerk  = $this->clerk();
        $mine   = $this->quoteBy($clerk, 'Mercy');

        $ids = collect($this->getJson('/api/v1/admin/quotations?search=Mercy')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    public function test_the_pdf_of_another_clerks_quotation_is_not_served(): void
    {
        // A separate controller and a separate route, bounded by the same scope
        // without either being touched — the point of putting it on the model.
        $theirs = $this->quoteBy($this->otherClerk());
        $this->clerk();

        $this->get("/api/v1/admin/pdf/quotations/{$theirs->id}")->assertNotFound();
    }

    // ── the escalation path still works ──────────────────────────────────────

    public function test_an_admin_sees_every_quotation(): void
    {
        $a = $this->quoteBy($this->otherClerk());
        $b = $this->quoteBy($this->otherClerk());

        $this->admin();

        $ids = collect($this->getJson('/api/v1/admin/quotations')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($a->id));
        $this->assertTrue($ids->contains($b->id));
        $this->getJson("/api/v1/admin/quotations/{$a->id}")->assertOk();
    }

    // ── the customer's link is not a member of staff ─────────────────────────

    public function test_the_public_link_resolves_for_an_unauthenticated_visitor(): void
    {
        $quotation = $this->quoteBy($this->otherClerk());
        QuotationService::issue($quotation);
        $quotation->refresh();

        $this->getJson("/api/v1/quote/{$quotation->quote_token}")
            ->assertOk()
            ->assertJsonPath('quotation.quote_number', $quotation->quote_number);
    }

    public function test_the_public_link_resolves_even_while_a_scoped_clerk_is_signed_in(): void
    {
        // The admin SPA serves /quote/:token from the same origin as the API, so
        // a signed-in clerk's session travels with the customer's page. Scoped,
        // the quote would 404 for a colleague's quotation — a customer-facing
        // page breaking on whoever last used the browser.
        $quotation = $this->quoteBy($this->otherClerk());
        QuotationService::issue($quotation);
        $quotation->refresh();

        $this->clerk();

        // Invisible through the clerk's own view of the world…
        $this->assertNull(Quotation::find($quotation->id));

        // …and still served on the customer's link.
        $this->getJson("/api/v1/quote/{$quotation->quote_token}")
            ->assertOk()
            ->assertJsonPath('quotation.quote_number', $quotation->quote_number);
    }

    public function test_a_customer_can_still_accept_and_gets_a_pay_link(): void
    {
        // Accept converts the quote into an Order — itself a scoped model — and
        // hands back that order's pay-link. Both sides have to stay reachable.
        $quotation = $this->quoteBy($this->otherClerk());
        QuotationService::issue($quotation);
        $quotation->refresh();

        $this->clerk();

        $payToken = $this->postJson("/api/v1/quote/{$quotation->quote_token}/accept")
            ->assertOk()
            ->json('pay_token');

        $this->assertNotEmpty($payToken, 'the customer must still be handed a way to pay');
        $this->assertSame(Quotation::CONVERTED, $quotation->fresh()->status);
    }

    public function test_accepting_twice_still_returns_the_same_pay_link(): void
    {
        // The idempotent branch dereferences the converted order. That order is
        // created with no created_by on the public path, so under scope it
        // resolves to null for any clerk — the null-goes-somewhere trap.
        $quotation = $this->quoteBy($this->otherClerk());
        QuotationService::issue($quotation);
        $quotation->refresh();

        $first = $this->postJson("/api/v1/quote/{$quotation->quote_token}/accept")->assertOk()->json('pay_token');

        $this->clerk();

        $this->postJson("/api/v1/quote/{$quotation->quote_token}/accept")
            ->assertOk()
            ->assertJsonPath('pay_token', $first);
    }

    public function test_the_service_still_finds_the_order_a_quotation_already_became(): void
    {
        // convertToInvoice's idempotent branch answers "which order did this
        // become". Accepted on the public link that order has no created_by at
        // all, so a scoped caller asking the question gets null back and
        // dereferences it one line later — a 500 for a re-submitted accept.
        $quotation = $this->quoteBy($this->otherClerk());
        QuotationService::issue($quotation);
        $quotation->refresh();

        $this->postJson("/api/v1/quote/{$quotation->quote_token}/accept")->assertOk();

        $clerk  = $this->clerk();
        $result = QuotationService::convertToInvoice($quotation->fresh(), $clerk->id);

        $this->assertTrue($result['already']);
        $this->assertNotNull($result['order'], 'the order it already became must still be found');
        $this->assertSame($quotation->fresh()->converted_order_id, $result['order']->id);
    }

    // ── the number generator asks about the table, not the caller ────────────

    public function test_two_clerks_issuing_quotations_never_share_a_number(): void
    {
        $clerkA = $this->clerk();
        $quoteA = $this->quoteBy($clerkA);
        QuotationService::issue($quoteA, $clerkA->id);

        $clerkB = $this->clerk();
        $quoteB = $this->quoteBy($clerkB);
        QuotationService::issue($quoteB, $clerkB->id);

        $a = $quoteA->fresh()->quote_number;
        $b = $quoteB->fresh()->quote_number;

        $this->assertNotSame($a, $b, 'two clerks must never be handed the same QUO number');
        $this->assertSame('QUO-' . now()->format('Y') . '-0001', $a);
        $this->assertSame('QUO-' . now()->format('Y') . '-0002', $b);
    }

    /**
     * Why the generator survives scoping while Order's did not: it counts a
     * locked row in document_number_sequences rather than reading the table it
     * numbers, so there is no Eloquent query for the scope to narrow. Stated
     * here as an assertion, because the property is easy to lose by "tidying"
     * DocumentNumberService into a max(quote_number) lookup — which, scoped,
     * would answer 0001 for every clerk on the shop floor.
     */
    public function test_the_second_clerk_cannot_see_the_first_quotation_she_is_numbered_after(): void
    {
        $clerkA = $this->clerk();
        $quoteA = $this->quoteBy($clerkA);
        QuotationService::issue($quoteA, $clerkA->id);

        $clerkB = $this->clerk();

        $this->assertNull(Quotation::find($quoteA->id), 'the row is genuinely invisible to her…');
        $this->assertSame(0, Quotation::count());
        $this->assertSame(1, Quotation::withoutViewerScope()->count(), '…and the escape hatch still sees it');

        $quoteB = $this->quoteBy($clerkB);
        QuotationService::issue($quoteB, $clerkB->id);

        $this->assertSame(
            'QUO-' . now()->format('Y') . '-0002',
            $quoteB->fresh()->quote_number,
            'numbering must continue from the whole table, not from what this clerk can see',
        );
    }

    // ── the gap this leaves ──────────────────────────────────────────────────

    /**
     * pos_clerk holds quotations.view and NOT quotations.create, so bounding the
     * list to what she raised leaves her with an empty screen: there is no route
     * by which she can come to own a quotation. The requirement — "Quotation is
     * available but what Quotation she has created" — is only half met until
     * quotations.create joins the role, which is a decision about what a clerk
     * may do, not a bug in the boundary. Recorded as a test so the pairing is
     * found here rather than at a counter.
     */
    public function test_a_clerk_cannot_yet_raise_a_quotation_which_is_why_her_list_starts_empty(): void
    {
        // A shop floor with work on it, so "empty" means bounded rather than
        // merely unpopulated.
        $this->quoteBy($this->otherClerk());
        $this->clerk();

        $this->postJson('/api/v1/admin/quotations', [
            'items' => [['product_name' => 'Cassock', 'quantity' => 1, 'unit_price' => 1000]],
        ])->assertForbidden();

        $this->getJson('/api/v1/admin/quotations')->assertOk()->assertJsonCount(0, 'data');
    }
}
