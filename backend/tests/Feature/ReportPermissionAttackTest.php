<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductTranslation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cycle 9 — hostile permission audit of the Reports section.
 *
 * The spec (owner decisions):
 *   - reports.view is the front door for the whole section;
 *   - financial figures (expenses, P&L, net, cost, margin, profit) need
 *     reports.financial;
 *   - a file out of the building (CSV/spreadsheet) needs reports.export;
 *   - customer-level PII (names/phones/emails) should need customer
 *     permissions (customers.view / customers.insights);
 *   - a non-staff user (self-registered customer) is refused everywhere.
 *
 * One request per test case (data provider), so a single broken query cannot
 * abort the shared Postgres transaction and manufacture cascading failures.
 *
 * The fixture plants recognisable markers so a leak can be found by a plain
 * string search of the body, not just by guessing field names:
 *   customer phone digits 711000111 / 722000222, emails *.pii@example.test,
 *   product cost 4321, supplier email supplier.pii@example.test.
 */
class ReportPermissionAttackTest extends TestCase
{
    use RefreshDatabase;

    public const PII_MARKERS = [
        '711000111', '722000222',
        'zelda.pii@example.test', 'zelda.order@example.test',
        'oscar.pii@example.test', 'Piiname', 'Onetime',
        '733000333', 'wanda.pii@example.test', 'Wanda',
        '744000444', 'petra.pii@example.test', 'Petra',
    ];

    public const SUPPLIER_MARKERS = ['supplier.pii@example.test'];

    /** Every GET report route (from `php artisan route:list --json`). */
    public static function getRoutes(): array
    {
        return [
            'attach-rates', 'collections', 'customer-intelligence',
            'customers/aging', 'customers/analytics', 'customers/lifetime-value',
            'customers/retention', 'customers/summary', 'dashboard/kpis',
            'drill/revenue', 'drill/collected', 'drill/outstanding', 'drill/new_customers',
            'drill/production_completed', 'drill/expenses',
            'engine-room', 'executive', 'financial-intelligence',
            'financial/cash-flow', 'financial/expenses', 'financial/profit-loss',
            'financial/revenue', 'financial/tax', 'institutions', 'international',
            'inventory-intelligence', 'inventory/aging', 'inventory/low-stock',
            'inventory/movement', 'inventory/stock-on-hand', 'inventory/valuation',
            'order-pipeline', 'outreach-log',
            'pdf/customers', 'pdf/financial', 'pdf/inventory', 'pdf/procurement',
            'pdf/production', 'pdf/sales',
            'procurement-intelligence', 'production-intelligence',
            'production/costing-summary', 'production/costing/{po}',
            'production/efficiency', 'production/summary', 'production/tailor-productivity',
            'purchase-orders', 'replenishment', 'sales/by-category', 'sales/by-customer',
            'sales/by-outlet', 'sales/by-payment-method', 'sales/by-product', 'sales/ledger',
            'sales/neema', 'sales/returns', 'sales/summary', 'schedules',
            'seasonal-demand', 'second-purchase', 'stockout-loss', 'win-back',
        ];
    }

    /** Routes whose middleware chain carries reports.financial (route:list). */
    public const FINANCIAL_GATED = [
        'financial/cash-flow', 'financial/expenses', 'financial/profit-loss',
        'financial/revenue', 'financial/tax', 'pdf/financial',
    ];

    /** Routes that check reports.financial in the controller. */
    public const FINANCIAL_IN_CONTROLLER = ['financial-intelligence', 'drill/expenses'];

    /** Routes that honour ?export=csv (wantsExport call sites). */
    public const CSV_ROUTES = [
        'sales/summary', 'sales/by-product', 'sales/by-category', 'sales/by-customer',
        'sales/by-outlet', 'sales/returns',
        'customers/lifetime-value', 'inventory/stock-on-hand', 'inventory/movement',
        'financial/profit-loss', 'financial/expenses', 'production/summary',
        'production/tailor-productivity', 'purchase-orders', 'production/costing-summary',
        'replenishment', 'collections', 'attach-rates', 'stockout-loss', 'seasonal-demand',
        'international', 'second-purchase', 'order-pipeline', 'win-back', 'institutions',
        'outreach-log',
        // EnhancedReportController: own csvResponse on export === 'csv', NOT wantsExport.
        'inventory/valuation', 'financial/tax',
    ];

    // ── fixture ─────────────────────────────────────────────────────────────

    /** Seeds one of everything a report reads. Returns ids the routes need. */
    public static function seedReportFixture(): array
    {
        $outlet = Outlet::factory()->create();
        $owner  = User::factory()->create();

        $product = Product::factory()->create(['status' => 'active', 'published_at' => now()->subDay()]);
        ProductTranslation::create(['product_id' => $product->id, 'language_code' => 'en', 'name' => 'Audit Cassock']);
        DB::table('product_prices')->insert([
            'product_id' => $product->id, 'currency_code' => 'KES',
            'regular_price' => 9000, 'cost_price' => 4321,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_items')->insert([
            'product_id' => $product->id, 'outlet_id' => $outlet->id,
            'quantity_on_hand' => 3, 'quantity_reserved' => 0, 'reorder_point' => 5, 'reorder_quantity' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $zelda = Customer::create([
            'customer_number' => 'C-AUDIT-1', 'first_name' => 'Zelda', 'last_name' => 'Piiname',
            'email' => 'zelda.pii@example.test', 'phone' => '+254711000111', 'customer_type' => 'church',
        ]);
        $oscar = Customer::create([
            'customer_number' => 'C-AUDIT-2', 'first_name' => 'Oscar', 'last_name' => 'Onetime',
            'email' => 'oscar.pii@example.test', 'phone' => '+254722000222',
        ]);

        $order = function (Customer $c, string $phone, string $email, $when, array $attrs = []) use ($outlet, $product) {
            $o = Order::factory()->create(array_merge([
                'order_type' => 'pos', 'outlet_id' => $outlet->id, 'status' => 'completed',
                'payment_status' => 'paid', 'total_amount' => 9000, 'subtotal' => 9000,
                'currency_code' => 'KES', 'customer_id' => $c->id,
                'customer_first_name' => $c->first_name, 'customer_last_name' => $c->last_name,
                'customer_phone' => $phone, 'customer_email' => $email,
                'created_at' => $when, 'updated_at' => $when,
            ], $attrs));
            DB::table('order_items')->insert([
                'order_id' => $o->id, 'product_id' => $product->id, 'sku' => 'SKU-AUDIT',
                'product_name' => 'Audit Cassock', 'quantity' => 1, 'unit_price' => 9000,
                'total_price' => 9000, 'cost_price' => 4321,
                'created_at' => $when, 'updated_at' => $when,
            ]);
            if (($attrs['payment_status'] ?? 'paid') === 'paid') {
                Payment::create(['order_id' => $o->id, 'amount' => 9000, 'currency_code' => 'KES',
                    'payment_method' => 'cash', 'status' => 'paid', 'paid_at' => $when]);
            }

            return $o;
        };

        // Zelda: a lapsed repeat buyer (win-back / replenishment / institutions).
        $order($zelda, '0711000111', 'zelda.order@example.test', now()->subDays(400));
        $order($zelda, '0711000111', 'zelda.order@example.test', now()->subDays(300));
        $order($zelda, '0711000111', 'zelda.order@example.test', now()->subDays(200));
        // Zelda: an open part-paid order (collections / pipeline / aging).
        $open = $order($zelda, '0711000111', 'zelda.order@example.test', now()->subDays(5),
            ['status' => 'processing', 'payment_status' => 'partial']);
        Payment::create(['order_id' => $open->id, 'amount' => 2000, 'currency_code' => 'KES',
            'payment_method' => 'cash', 'status' => 'paid', 'paid_at' => now()->subDays(5)]);
        // Oscar: a recent one-time buyer (second-purchase worklist).
        $order($oscar, '0722000222', 'oscar.pii@example.test', now()->subDays(10));

        // Wanda Chapel: an institution (name regex), a rhythmic buyer gone quiet
        // — feeds win-back, replenishment and institutions.
        $wanda = Customer::create([
            'customer_number' => 'C-AUDIT-3', 'first_name' => 'Wanda', 'last_name' => 'Chapel',
            'email' => 'wanda.pii@example.test', 'phone' => '+254733000333', 'customer_type' => 'business',
        ]);
        foreach ([320, 260, 200] as $d) {
            $order($wanda, '0733000333', 'wanda.pii@example.test', now()->subDays($d));
        }
        // Petra: an unconfirmed pending cart (order pipeline).
        $petra = Customer::create([
            'customer_number' => 'C-AUDIT-4', 'first_name' => 'Petra', 'last_name' => 'Pending',
            'email' => 'petra.pii@example.test', 'phone' => '+254744000444',
        ]);
        // ...and a 30-day rhythm, last bought 35 days ago (replenishment radar: due).
        foreach ([95, 65, 35] as $d) {
            $order($petra, '0744000444', 'petra.pii@example.test', now()->subDays($d));
        }
        $order($petra, '0744000444', 'petra.pii@example.test', now()->subDays(3),
            ['status' => 'pending', 'payment_status' => 'pending']);
        // A logged win-back contact (outreach log).
        DB::table('win_back_outreach')->insert([
            'customer_id' => $wanda->id, 'phone' => '254733000333', 'name' => 'Wanda Chapel',
            'channel' => 'call', 'contacted_by' => $owner->id,
            'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);

        // Money out (financial endpoints).
        $categoryId = DB::table('expense_categories')->insertGetId([
            'name' => 'Rent', 'code' => 'RENT-AUD', 'budget_monthly' => 5000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('expenses')->insert([
            'reference_number' => 'EXP-AUD-1', 'title' => 'Rent', 'category_id' => $categoryId,
            'amount' => 1500, 'amount_kes' => 1500, 'currency_code' => 'KES',
            'expense_date' => now()->format('Y-m-d'), 'status' => 'approved',
            'payment_method' => 'cash', 'created_by' => $owner->id, 'outlet_id' => $outlet->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Procurement.
        $supplierId = DB::table('suppliers')->insertGetId([
            'code' => 'SUP-AUD', 'name' => 'Audit Textiles', 'email' => 'supplier.pii@example.test',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $materialId = DB::table('materials')->insertGetId([
            'code' => 'FAB-AUD', 'name' => 'Audit Wool', 'unit_of_measure' => 'm',
            'unit_cost' => 800, 'reorder_point' => 50, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-AUD-1', 'supplier_id' => $supplierId, 'outlet_id' => $outlet->id,
            'order_date' => now()->subDays(10)->format('Y-m-d'), 'status' => 'received',
            'subtotal' => 8000, 'total_amount' => 8000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_order_items')->insert([
            'purchase_order_id' => $poId, 'item_type' => 'material', 'material_id' => $materialId,
            'description' => 'Audit Wool 10m', 'quantity' => 10, 'unit_price' => 800, 'total_price' => 8000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Production.
        $prod = ProductionOrder::create([
            'order_number' => 'PRD-AUD-1', 'product_id' => $product->id, 'quantity' => 2,
            'status' => 'completed', 'priority' => 'normal', 'due_date' => now()->addDays(3),
            'completed_at' => now()->subDay(), 'outlet_id' => $outlet->id,
        ]);
        DB::table('material_allocations')->insert([
            'production_order_id' => $prod->id, 'material_id' => $materialId,
            'quantity_required' => 3, 'quantity_allocated' => 3, 'quantity_used' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['production_order_id' => $prod->id, 'outlet_id' => $outlet->id];
    }

    /** Period-taking routes default to this_month — one day on the 1st — so widen them. */
    public const WIDE_WINDOW = [
        'executive', 'customer-intelligence', 'production-intelligence', 'inventory-intelligence',
        'financial-intelligence', 'drill/revenue', 'drill/collected', 'drill/outstanding',
        'drill/new_customers', 'drill/production_completed', 'drill/expenses',
    ];

    public static function uri(string $route, array $ids, string $query = ''): string
    {
        $q = array_filter([in_array($route, self::WIDE_WINDOW, true) ? 'period=last_30' : '', $query]);

        return '/api/v1/admin/reports/' . str_replace('{po}', (string) $ids['production_order_id'], $route)
            . ($q ? '?' . implode('&', $q) : '');
    }

    private function actAs(array $perms, string $userType = 'staff', string $status = 'active'): User
    {
        $user = User::factory()->create(['user_type' => $userType, 'status' => $status]);
        foreach ($perms as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    public static function body(TestResponse $res): string
    {
        return $res->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            ? (string) $res->streamedContent()
            : (string) $res->getContent();
    }

    public static function excerpt(TestResponse $res): string
    {
        return substr(preg_replace('/\s+/', ' ', self::body($res)), 0, 300);
    }

    private function assertNoMarkers(TestResponse $res, array $markers, string $what): void
    {
        $body  = self::body($res);
        $found = array_values(array_filter($markers, fn ($m) => str_contains($body, $m)));
        $this->assertSame([], $found, "{$what}: body carries " . implode(', ', $found)
            . ' — status ' . $res->getStatusCode() . ' — ' . self::excerpt($res));
    }

    // ── 1. outsiders ─────────────────────────────────────────────────────────

    public static function everyRoute(): array
    {
        $cases = [];
        foreach (self::getRoutes() as $r) {
            $cases["GET {$r}"] = ['GET', $r];
        }
        foreach ([['POST', 'export/excel'], ['POST', 'export/pdf'], ['POST', 'schedules'],
                  ['DELETE', 'schedules/x'], ['POST', 'win-back/outreach']] as [$m, $r]) {
            $cases["{$m} {$r}"] = [$m, $r];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyRoute')]
    public function test_unauthenticated_is_refused(string $method, string $route): void
    {
        $ids = self::seedReportFixture();
        $res = $this->json($method, self::uri($route, $ids));
        $this->assertSame(401, $res->getStatusCode(), "{$method} {$route}: " . self::excerpt($res));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyRoute')]
    public function test_a_customer_account_holding_every_report_permission_is_refused(string $method, string $route): void
    {
        $ids = self::seedReportFixture();
        $this->actAs(['reports.view', 'reports.financial', 'reports.export', 'customers.view'], 'customer');
        $res = $this->json($method, self::uri($route, $ids), $method === 'GET' ? [] : ['name' => 'x']);
        $this->assertSame(403, $res->getStatusCode(), "{$method} {$route}: " . self::excerpt($res));
        $this->assertNoMarkers($res, self::PII_MARKERS, "{$method} {$route}");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyRoute')]
    public function test_staff_with_zero_permissions_is_refused(string $method, string $route): void
    {
        $ids = self::seedReportFixture();
        $this->actAs([]);
        $res = $this->json($method, self::uri($route, $ids), $method === 'GET' ? [] : ['name' => 'x']);
        $this->assertSame(403, $res->getStatusCode(), "{$method} {$route}: " . self::excerpt($res));
    }

    /**
     * A staff account set to `inactive` keeps its Sanctum tokens (only
     * `suspended` revokes them — UserController) and EnsureStaff checks
     * user_type, not status. Does a deactivated report viewer still read?
     */
    public function test_an_inactive_staff_account_cannot_read_reports(): void
    {
        self::seedReportFixture();
        $this->actAs(['reports.view'], 'staff', 'inactive');
        $res = $this->getJson('/api/v1/admin/reports/sales/by-customer');
        $this->assertSame(403, $res->getStatusCode(), 'inactive staff: ' . self::excerpt($res));
    }

    // ── 2. reports.view only ────────────────────────────────────────────────

    public static function viewOnlyRoutes(): array
    {
        $cases = [];
        foreach (self::getRoutes() as $r) {
            $cases[$r] = [$r];
        }

        return $cases;
    }

    /**
     * reports.view alone: never a 5xx; financial routes refused; and wherever a
     * 200 comes back, no customer PII, supplier contact, or cost marker in the
     * body — since this user holds neither customers.* nor reports.financial.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('viewOnlyRoutes')]
    public function test_reports_view_only_gets_no_financials_and_no_customer_pii(string $route): void
    {
        $ids = self::seedReportFixture();
        $this->actAs(['reports.view']);
        $res    = $this->getJson(self::uri($route, $ids));
        $status = $res->getStatusCode();

        $this->assertLessThan(500, $status, "{$route}: server error — " . self::excerpt($res));

        if (in_array($route, self::FINANCIAL_GATED, true) || in_array($route, self::FINANCIAL_IN_CONTROLLER, true)) {
            $this->assertSame(403, $status, "{$route}: financial route reached without reports.financial");
            return;
        }

        if ($route === 'executive') {
            $this->assertNull($res->json('kpis.financial'), 'executive: CFO block shown without reports.financial');
        }

        if ($status !== 200) {
            return;
        }

        $this->assertNoMarkers($res, self::PII_MARKERS, "{$route} (customer PII without customers.*)");
        $this->assertNoMarkers($res, self::SUPPLIER_MARKERS, "{$route} (supplier contact)");
        $this->assertNoMarkers($res, ['4321'], "{$route} (unit cost without reports.financial)");

        $json = str_contains((string) $res->headers->get('Content-Type'), 'json') ? $res->json() : null;
        if (is_array($json)) {
            $keys = self::financialKeys($json);
            $this->assertSame([], $keys, "{$route}: cost/profit/margin fields without reports.financial: "
                . implode(', ', array_slice($keys, 0, 12)));
        }
    }

    /** Dotted paths of keys that name cost / profit / margin / COGS. */
    private static function financialKeys(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $path = $prefix === '' ? (string) $k : "{$prefix}.{$k}";
            // A key that is present but null carries nothing (stock-on-hand's
            // cost_per_unit is hard-coded null) — only a value is a leak.
            if ($v !== null && is_string($k) && preg_match('/(^|_)(cost|costs|cogs|profit|margin)(_|$)|unit_cost|cost_price|gross_profit|net_profit/i', $k)) {
                $out[] = preg_replace('/\.\d+\./', '.*.', $path);
            }
            if (is_array($v)) {
                $out = array_merge($out, self::financialKeys($v, $path));
            }
        }

        return array_values(array_unique($out));
    }

    // ── 3. reports.view + reports.financial, no reports.export ──────────────

    public static function csvRoutes(): array
    {
        $cases = [];
        foreach (self::CSV_ROUTES as $r) {
            foreach (['csv', 'excel', '1'] as $v) {
                $cases["{$r} ?export={$v}"] = [$r, $v];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('csvRoutes')]
    public function test_no_csv_without_reports_export(string $route, string $value): void
    {
        $ids = self::seedReportFixture();
        $this->actAs(['reports.view', 'reports.financial', 'customers.view', 'customers.insights']);
        $res = $this->get(self::uri($route, $ids, 'export=' . $value), ['Accept' => 'application/json']);

        $this->assertLessThan(500, $res->getStatusCode(), "{$route}?export={$value}: " . self::excerpt($res));
        $this->assertStringNotContainsString('text/csv', (string) $res->headers->get('Content-Type'),
            "{$route}?export={$value}: CSV handed out without reports.export (status {$res->getStatusCode()}): "
            . self::excerpt($res));
    }

    /**
     * The same user, same routes, asking for a FILE by the other door: the
     * PDF endpoints. A PDF leaves the building exactly as a CSV does
     * (ExportsCsv::wantsExport's own docblock: "taking a file out of the
     * building"). Does reports.export gate it?
     */
    public static function pdfRoutes(): array
    {
        return array_combine(
            ['pdf/sales', 'pdf/customers', 'pdf/inventory', 'pdf/procurement', 'pdf/production', 'pdf/financial'],
            [['pdf/sales'], ['pdf/customers'], ['pdf/inventory'], ['pdf/procurement'], ['pdf/production'], ['pdf/financial']],
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pdfRoutes')]
    public function test_no_pdf_file_without_reports_export(string $route): void
    {
        $ids = self::seedReportFixture();
        $this->actAs(['reports.view', 'reports.financial', 'customers.view', 'customers.insights']);
        $res = $this->get(self::uri($route, $ids), ['Accept' => 'application/json']);

        $this->assertLessThan(500, $res->getStatusCode(), "{$route}: " . self::excerpt($res));
        $this->assertNotSame('application/pdf', $res->headers->get('Content-Type'),
            "{$route}: PDF file (status {$res->getStatusCode()}, " . strlen((string) $res->getContent())
            . ' bytes) handed to a user without reports.export');
    }

    /**
     * The customers PDF, opened by a reports.view-only user (no customers.*).
     * dompdf compresses page streams, so inflate them before searching.
     */
    public function test_reports_view_only_customers_pdf_carries_no_customer_pii(): void
    {
        $ids = self::seedReportFixture();
        $this->actAs(['reports.view']);
        $res = $this->get(self::uri('pdf/customers', $ids));
        $this->assertSame(200, $res->getStatusCode());

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', (string) $res->getContent(), $m);
        $text = implode('', array_map(fn ($s) => (string) @gzuncompress($s), $m[1]));
        $this->assertNotSame('', $text, 'could not inflate the PDF — the probe is blind');

        $found = array_values(array_filter(self::PII_MARKERS, fn ($mk) => str_contains($text, $mk)));
        $this->assertSame([], $found, 'pdf/customers carries customer PII for a reports.view-only user: ' . implode(', ', $found));
    }

    /** The financial drill gate is an exact string compare — try to step round it. */
    public static function drillSpellings(): array
    {
        return ['Expenses' => ['Expenses'], 'EXPENSES' => ['EXPENSES'], 'trailing space' => ['expenses%20'],
                'encoded' => ['%65xpenses'], 'unknown metric' => ['net_profit']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('drillSpellings')]
    public function test_the_expense_drill_cannot_be_reached_by_another_spelling(string $metric): void
    {
        self::seedReportFixture();
        $this->actAs(['reports.view']);
        $res = $this->getJson("/api/v1/admin/reports/drill/{$metric}?period=last_30");

        $this->assertLessThan(500, $res->getStatusCode(), self::excerpt($res));
        $this->assertNotSame('expense', $res->json('rows.0.kind'), "drill/{$metric} returned expense rows: " . self::excerpt($res));
        $this->assertStringNotContainsString('"kind":"expense"', self::body($res));
    }

    // ── 4. writes behind reports.view ───────────────────────────────────────

    /**
     * POST win-back/outreach writes a row (win_back_outreach) behind
     * reports.view alone — no customers.* permission. Record what it does.
     */
    public function test_reports_view_only_cannot_write_customer_outreach(): void
    {
        self::seedReportFixture();
        $this->actAs(['reports.view']);
        $res = $this->postJson('/api/v1/admin/reports/win-back/outreach', [
            'phone' => '0711000111', 'name' => 'Zelda Piiname', 'channel' => 'call',
        ]);

        $this->assertLessThan(500, $res->getStatusCode(), self::excerpt($res));
        $this->assertContains($res->getStatusCode(), [401, 403],
            'a read-only report viewer wrote a customer contact record: ' . $res->getStatusCode() . ' ' . self::excerpt($res));
    }

    /**
     * A user with reports.export but NOT reports.financial schedules a
     * `financial` report to an outside address. Nothing executes schedules in
     * this codebase today (no reader of report_schedule_* outside the
     * controller), so this records intent, not a live leak.
     */
    public function test_reports_export_without_financial_cannot_schedule_the_financial_report(): void
    {
        self::seedReportFixture();
        $this->actAs(['reports.view', 'reports.export']);
        $res = $this->postJson('/api/v1/admin/reports/schedules', [
            'name' => 'leak', 'report_type' => 'financial', 'frequency' => 'daily',
            'recipients' => ['outside@example.test'], 'format' => 'csv',
        ]);

        $this->assertLessThan(500, $res->getStatusCode(), self::excerpt($res));
        $this->assertSame(403, $res->getStatusCode(), 'financial schedule accepted without reports.financial: ' . self::excerpt($res));
    }

    // ── 5. positive controls: the privileged user DOES get the data ─────────

    /**
     * Without these, every "absent" assertion above could be passing because
     * the fixture never produced the data. A full-permission user must see the
     * markers on the routes the findings name.
     */
    public static function positiveControls(): array
    {
        return [
            'sales/by-customer has PII'      => ['sales/by-customer', '711000111'],
            'second-purchase has PII'        => ['second-purchase', 'oscar.pii@example.test'],
            'drill/new_customers has PII'    => ['drill/new_customers', 'oscar.pii@example.test'],
            'production/costing has cost'    => ['production/costing/{po}', 'unit_cost'],
            'costing-summary has cost'       => ['production/costing-summary', 'cost'],
            'executive has financial block'  => ['executive', '"financial"'],
            'purchase-orders has supplier'   => ['purchase-orders', 'supplier.pii@example.test'],
            'win-back has PII'               => ['win-back', '733000333'],
            'replenishment has PII'          => ['replenishment', '744000444'],
            'institutions has PII'           => ['institutions', 'Wanda'],
            'order-pipeline has PII'         => ['order-pipeline', 'petra.pii@example.test'],
            'outreach-log has PII'           => ['outreach-log', '733000333'],
            'customer-intelligence has PII'  => ['customer-intelligence', 'Piiname'],
            'collections has PII'            => ['collections', '711000111'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('positiveControls')]
    public function test_positive_control_full_user_sees_the_data(string $route, string $marker): void
    {
        $ids = self::seedReportFixture();
        $this->actAs(['reports.view', 'reports.financial', 'reports.export', 'customers.view', 'customers.insights']);
        $res = $this->getJson(self::uri($route, $ids))->assertOk();
        $this->assertStringContainsString($marker, (string) $res->getContent(), "{$route}: fixture did not produce {$marker}");
    }
}
