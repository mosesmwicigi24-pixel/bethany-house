<?php
/**
 * UI-tour fixture for the native apps' screen tour (apple/UITests).
 *
 * TEST DATA ONLY. Runs against a throwaway database on a CI runner whose name
 * must end in `_uitest`; it refuses anything else, so it can never touch a
 * real Hub. It does not change Laravel: it boots the app, as artisan tinker
 * would, and writes rows through the models and factories the backend's own
 * tests use. Every name says it is test data.
 *
 *   php apple/UITests/Fixtures/seed.php            # prints the tour account's API token
 *
 * The tour account's password is a fixed, test-only value (UITEST_PASSWORD
 * below) for a database that exists for the length of one CI job.
 */

use App\Models\Material;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$base = getenv('BACKEND_DIR') ?: __DIR__ . '/../../../backend';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (!str_ends_with((string) config('database.connections.' . config('database.default') . '.database'), '_uitest')) {
    fwrite(STDERR, "Refusing: the database name must end in _uitest.\n");
    exit(1);
}

const UITEST_EMAIL = 'ui-tour@bethanyhouse.test';
const UITEST_PASSWORD = 'ui-tour-only-2026';

Artisan::call('permission:sync');
$now = now();

// ── Outlet and people ───────────────────────────────────────────────────────
$outlet = Outlet::factory()->create(['code' => 'UIT', 'name' => 'UI Tour Shop', 'outlet_type' => 'retail', 'sales_channel' => 'pos', 'is_active' => true]);

$admin = User::factory()->create([
    'first_name' => 'Tour', 'last_name' => 'Admin', 'email' => UITEST_EMAIL,
    'password' => Hash::make(UITEST_PASSWORD), 'user_type' => 'staff', 'status' => 'active',
]);
$admin->assignRole('super_admin');
$admin->outlets()->attach($outlet->id, ['is_primary' => true]);

$clerk = User::factory()->create(['first_name' => 'Test', 'last_name' => 'Clerk', 'email' => 'clerk@bethanyhouse.test', 'reports_to' => $admin->id]);
$clerk->assignRole('pos_clerk');
$clerk->outlets()->attach($outlet->id, ['is_primary' => true]);
User::factory()->create(['first_name' => 'Web', 'last_name' => 'Customer', 'email' => 'customer@bethanyhouse.test', 'user_type' => 'customer']);

// ── Catalogue ───────────────────────────────────────────────────────────────
$cat = DB::table('categories')->insertGetId(['slug' => 'test-vestments', 'name_en' => 'Test Vestments', 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
$products = [];
foreach ([
    ['Test Clergy Cope', 'UIT-COPE', 22000, 171.50, false],
    ['Test Communion Cups (box)', 'UIT-CUPS', 10, null, false],
    ['Test Clergy Shirt', 'UIT-SHIRT', 2500, null, true],
] as [$name, $sku, $kes, $usd, $made]) {
    $p = Product::factory()->create(['sku' => $sku, 'category_id' => $cat, 'status' => 'active', 'is_producible' => $made]);
    DB::table('product_translations')->insert(['product_id' => $p->id, 'language_code' => 'en', 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('product_prices')->insert(['product_id' => $p->id, 'currency_code' => 'KES', 'regular_price' => $kes, 'created_at' => $now, 'updated_at' => $now]);
    if ($usd !== null) {
        DB::table('product_prices')->insert(['product_id' => $p->id, 'currency_code' => 'USD', 'regular_price' => $usd, 'created_at' => $now, 'updated_at' => $now]);
    }
    DB::table('inventory_items')->insert(['product_id' => $p->id, 'outlet_id' => $outlet->id, 'quantity_on_hand' => $made ? 0 : 40, 'quantity_reserved' => 0, 'reorder_point' => 5, 'reorder_quantity' => 10, 'created_at' => $now, 'updated_at' => $now]);
    $products[$sku] = $p;
}

// ── Materials, bill of materials, suppliers (as backend MaterialSourcingTest) ──
$mat = function (string $name, string $unit, float $cost, float $onHand) use ($outlet, $now) {
    $m = Material::create(['code' => 'MAT-' . strtoupper(substr(md5($name), 0, 6)), 'name' => $name, 'category' => 'fabric', 'unit_of_measure' => $unit, 'unit_cost' => $cost, 'is_active' => true]);
    DB::table('material_inventory')->insert(['material_id' => $m->id, 'outlet_id' => $outlet->id, 'quantity_on_hand' => $onHand, 'quantity_reserved' => 0, 'created_at' => $now, 'updated_at' => $now]);
    return $m;
};
$fabric = $mat('Test white cotton fabric', 'm', 400, 1);
$buttons = $mat('Test shirt buttons', 'pcs', 5, 120);
$lining = $mat('Test satin lining', 'm', 0, 0);
$bom = DB::table('bills_of_materials')->insertGetId(['product_id' => $products['UIT-SHIRT']->id, 'version' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
DB::table('bom_items')->insert([
    ['bom_id' => $bom, 'material_id' => $fabric->id, 'quantity' => 2.5, 'unit_of_measure' => 'm', 'created_at' => $now, 'updated_at' => $now],
    ['bom_id' => $bom, 'material_id' => $buttons->id, 'quantity' => 8, 'unit_of_measure' => 'pcs', 'created_at' => $now, 'updated_at' => $now],
    ['bom_id' => $bom, 'material_id' => $lining->id, 'quantity' => 1.5, 'unit_of_measure' => 'm', 'created_at' => $now, 'updated_at' => $now],
]);
$sup = fn (string $n) => DB::table('suppliers')->insertGetId(['code' => 'SUP-' . strtoupper(substr(md5($n), 0, 5)), 'name' => $n, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
$dk = $sup('Test Textiles Ltd');
$sup('Test Fabrics Co');
$fabric->update(['preferred_supplier_id' => $dk]);

// ── Orders (each sales bucket), one paid ────────────────────────────────────
foreach ([['pos', 'completed', 'paid'], ['online', 'processing', 'pending'], ['whatsapp', 'processing', 'partial']] as $i => [$type, $status, $pay]) {
    $o = Order::factory()->create(['order_type' => $type, 'status' => $status, 'payment_status' => $pay, 'outlet_id' => $outlet->id, 'subtotal' => 5000, 'total_amount' => 5000]);
    OrderItem::create(['order_id' => $o->id, 'product_id' => $products['UIT-SHIRT']->id, 'product_name' => 'Test Clergy Shirt', 'sku' => 'UIT-SHIRT', 'quantity' => 2, 'unit_price' => 2500, 'total_price' => 5000, 'requires_production' => true]);
    if ($pay !== 'pending') {
        Payment::factory()->create(['order_id' => $o->id, 'amount' => $pay === 'paid' ? 5000 : 2000, 'currency_code' => 'KES', 'status' => 'paid']);
    }
}

// ── A web review block with its stars (Home page → Customer Reviews) ──────────
DB::table('banners')->where('position', 'home_testimonial')->limit(1)->update(['updated_at' => $now]);

echo $admin->createToken('ui-tour-fixture')->plainTextToken, "\n";
