<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ?export=csv on the MetricEngine-backed report endpoints — the same
 * contract ReportController's classic reports have honoured all along
 * (text/csv, UTF-8 BOM, attachment disposition, header row first), now
 * extended to all eight revenue engines via the shared ExportsCsv trait.
 *
 * Without ?export=csv every endpoint keeps answering JSON — the flag is
 * additive, never a behaviour change.
 */
class EngineCsvExportTest extends TestCase
{
    use RefreshDatabase;

    /** endpoint => expected first CSV header cell */
    private const ENDPOINTS = [
        '/api/v1/admin/reports/collections'     => 'Stage',
        '/api/v1/admin/reports/win-back'        => 'Customer',
        '/api/v1/admin/reports/replenishment'   => 'Customer',
        '/api/v1/admin/reports/attach-rates'    => 'Anchor Product',
        '/api/v1/admin/reports/stockout-loss'   => 'Product',
        '/api/v1/admin/reports/seasonal-demand' => 'Season',
        '/api/v1/admin/reports/institutions'    => 'Institution',
        '/api/v1/admin/reports/international'   => 'Group',
    ];

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_every_engine_endpoint_streams_csv_with_a_header_row(): void
    {
        $this->viewer();

        foreach (self::ENDPOINTS as $endpoint => $firstHeader) {
            $res = $this->get($endpoint . '?export=csv');

            $res->assertOk();
            $this->assertStringStartsWith(
                'text/csv',
                (string) $res->headers->get('Content-Type'),
                "{$endpoint} must answer text/csv under ?export=csv",
            );
            $this->assertStringContainsString(
                'attachment; filename=',
                (string) $res->headers->get('Content-Disposition'),
                "{$endpoint} must download as an attachment",
            );

            // First line (after the UTF-8 BOM) is the header row. fputcsv
            // quotes multi-word cells, so compare with quotes stripped.
            $body      = (string) $res->getContent();
            $firstLine = str_replace('"', '', (string) strtok(ltrim($body, "\xEF\xBB\xBF"), "\n"));
            $this->assertStringStartsWith(
                $firstHeader,
                $firstLine,
                "{$endpoint} CSV must start with the '{$firstHeader}' column",
            );
        }
    }

    public function test_collections_csv_carries_the_staged_rows(): void
    {
        $this->viewer();

        DB::table('quotations')->insert([
            'quote_number'        => 'QUO-000555',
            'status'              => 'sent',
            'source'              => 'admin',
            'currency_code'       => 'KES',
            'subtotal'            => 20000,
            'discount_amount'     => 0,
            'tax_amount'          => 0,
            'shipping_amount'     => 0,
            'total_amount'        => 20000,
            'customer_first_name' => 'Grace',
            'created_at'          => now()->subDays(4),
            'updated_at'          => now()->subDays(4),
        ]);

        $stalled = Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'confirmed',
            'currency_code'  => 'KES',
            'total_amount'   => 10000,
            'payment_status' => 'deposit',
        ]);
        Payment::create([
            'order_id'       => $stalled->id,
            'amount'         => 4000,
            'currency_code'  => 'KES',
            'payment_method' => 'cash',
            'status'         => 'paid',
            'paid_at'        => now()->subDays(10),
        ]);

        $body = (string) $this->get('/api/v1/admin/reports/collections?export=csv')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('open_quote', $body);
        $this->assertStringContainsString('QUO-000555', $body);
        $this->assertStringContainsString('stalled_deposit', $body);
        $this->assertStringContainsString($stalled->order_number, $body);
        $this->assertStringContainsString('unpaid_balance', $body);
    }

    public function test_without_the_flag_the_endpoint_still_answers_json(): void
    {
        $this->viewer();

        $this->getJson('/api/v1/admin/reports/collections')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['summary' => ['money_on_table']]);
    }

    public function test_csv_export_is_gated_by_reports_view(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('tailor', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        foreach (array_keys(self::ENDPOINTS) as $endpoint) {
            $this->get($endpoint . '?export=csv')->assertStatus(403);
        }
    }
}
