<?php
/**
 * Seeds a scratch database for the Android live render check
 * (app/src/test/.../render/LiveRenderTest.kt). See docs/NATIVE_APPS_ANDROID.md.
 *
 * Writes test records and a Sanctum token for the super admin. It refuses to
 * run unless DB_DATABASE ends in "_e2e", so it can never touch a real database.
 *
 *   cd backend && DB_DATABASE=bh_e2e ... php artisan migrate:fresh --force
 *   php artisan permission:sync && php artisan db:seed --class=CountriesSeeder --force
 *   php ../android/tools/live-hub-fixture.php   # prints the token path
 */
$backend = dirname(__DIR__, 2) . '/backend';
require $backend . '/vendor/autoload.php';
$app = require $backend . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = config('database.connections.' . config('database.default') . '.database');
if (!is_string($db) || !str_ends_with($db, '_e2e')) {
    fwrite(STDERR, "Refusing to seed: database '$db' does not end in _e2e.\n");
    exit(1);
}

use App\Models\{User, Outlet, Product, ProductVariant, ProductTranslation, Order, Payment, InventoryItem, LeaveRequest, Shift, TimeEntry, EmployeeProfile, Customer};

function step(string $name, callable $fn) { try { $r = $fn(); echo "OK   $name\n"; return $r; } catch (Throwable $e) { echo "FAIL $name: " . substr($e->getMessage(), 0, 300) . "\n"; return null; } }

$admin = User::role('super_admin')->first();
$admin->forceFill(['two_factor_enabled' => false, 'must_setup_2fa' => false, 'status' => 'active'])->save();

$outlet = step('outlet', fn () => Outlet::where('code', 'WST')->first() ?? Outlet::factory()->create(['name' => 'Westlands Store', 'code' => 'WST']));
if ($outlet) $admin->outlets()->syncWithoutDetaching([$outlet->id]);

$products = step('products', function () {
    $out = [];
    foreach (['Kitenge Dress', 'Linen Shirt', 'Ankara Blazer'] as $i => $name) {
        $p = Product::factory()->create(['status' => 'active']);
        ProductTranslation::create(['product_id' => $p->id, 'language_code' => 'en', 'name' => $name, 'description' => "$name description"]);
        $v = ProductVariant::factory()->create(['product_id' => $p->id, 'is_default' => true, 'is_active' => true]);
        foreach (['KES' => 2500 + $i * 1000, 'USD' => 25 + $i * 10] as $cur => $amt) {
            Illuminate\Support\Facades\DB::table('product_prices')->insert(['product_id' => $p->id, 'product_variant_id' => $v->id, 'currency_code' => $cur, 'regular_price' => $amt, 'cost_price' => $amt * 0.4, 'created_at' => now(), 'updated_at' => now()]);
        }
        $out[] = $p;
    }
    return $out;
});
step('inventory', fn () => collect($products ?? [])->each(fn ($p, $i) => InventoryItem::factory()->create(['product_id' => $p->id, 'outlet_id' => $outlet->id, 'quantity_on_hand' => [40, 3, 0][$i]])));

$customer = step('customer', fn () => Customer::create(['customer_number' => 'CUS-0001', 'first_name' => 'Amani', 'last_name' => 'Otieno', 'email' => 'amani@example.com', 'phone' => '+254711000111', 'customer_type' => 'individual']));

// Sales truth in two currencies: one KES till sale paid, one USD web order part-paid (deposit), one pending KES quote-to-order.
$orders = step('orders', function () use ($outlet, $customer, $admin) {
    $o1 = Order::factory()->create(['order_number' => 'ORD-E2E-0001', 'order_type' => 'pos', 'status' => 'completed', 'currency_code' => 'KES', 'subtotal' => 4500, 'total_amount' => 4500, 'payment_status' => 'paid', 'outlet_id' => $outlet->id, 'customer_id' => $customer?->id, 'user_id' => $admin->id]);
    $o2 = Order::factory()->create(['order_number' => 'ORD-E2E-0002', 'order_type' => 'online', 'status' => 'confirmed', 'currency_code' => 'USD', 'subtotal' => 120, 'total_amount' => 120, 'payment_status' => 'partial', 'outlet_id' => $outlet->id, 'customer_id' => $customer?->id]);
    $o3 = Order::factory()->create(['order_number' => 'ORD-E2E-0003', 'order_type' => 'pos', 'status' => 'pending', 'currency_code' => 'KES', 'subtotal' => 2500, 'total_amount' => 2500, 'payment_status' => 'pending', 'outlet_id' => $outlet->id]);
    return [$o1, $o2, $o3];
});
step('payments', function () use ($orders) {
    Payment::factory()->create(['order_id' => $orders[0]->id, 'payment_number' => 'PAY-E2E-0001', 'payment_method' => 'mpesa', 'amount' => 4500, 'currency_code' => 'KES', 'status' => 'paid']);
    Payment::factory()->create(['order_id' => $orders[1]->id, 'payment_number' => 'PAY-E2E-0002', 'payment_method' => 'card', 'amount' => 50, 'currency_code' => 'USD', 'status' => 'paid']);
});

$staff = step('staff', fn () => User::factory()->create(['first_name' => 'Wanjiru', 'last_name' => 'Kamau', 'email' => 'wanjiru@example.com', 'status' => 'active']));
if ($staff) $staff->outlets()->syncWithoutDetaching([$outlet->id]);
step('employee profile', fn () => EmployeeProfile::factory()->create(['user_id' => $staff->id]));
step('leave', fn () => LeaveRequest::factory()->create(['user_id' => $staff->id]));
step('shift', fn () => Shift::factory()->create(['user_id' => $staff->id, 'outlet_id' => $outlet->id, 'status' => 'published']));
step('time entry', fn () => TimeEntry::factory()->create(['user_id' => $staff->id, 'outlet_id' => $outlet->id]));

$token = $admin->createToken('android-e2e')->plainTextToken;
$out = sys_get_temp_dir() . '/hub-e2e-token.txt';
file_put_contents($out, $token);
echo "TOKEN written to $out; admin id {$admin->id}\n";
