<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\User;
use App\Support\ServerError;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * A failed request says what failed, never how.
 *
 * About a hundred catch blocks answered with the exception's message — some
 * with its file and line. A QueryException's message is the SQL statement and
 * its bindings, so a database failure handed the caller the schema, the values
 * being written and the server's paths, past APP_DEBUG=false, which exists to
 * hide exactly that. Each test below makes Postgres itself refuse a write (a
 * trigger that raises), so the exception is a real QueryException taking the
 * real path, and asserts the body carries the endpoint's fixed sentence only —
 * while the log still receives everything.
 *
 * The same catch blocks swallowed refusals: a ValidationException or abort()
 * thrown inside them came back as a 500. Those now pass through as the 4xx the
 * code meant.
 */
class ServerErrorLeakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);   // as in production

        // Rolled back with the test's transaction, like everything else here.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION leak_test_forced_failure() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'leak-test forced failure';
            END $$;
        SQL);
    }

    /** Make Postgres refuse writes to $table, so the endpoint's catch block runs. */
    private function failWritesTo(string $table, string $events = 'INSERT OR UPDATE OR DELETE', ?string $when = null): void
    {
        DB::unprepared(
            "CREATE TRIGGER leak_test_forced_failure BEFORE {$events} ON {$table} FOR EACH ROW "
            . ($when ? "WHEN ({$when}) " : '')
            . 'EXECUTE FUNCTION leak_test_forced_failure()'
        );
    }

    /** @param list<string> $permissions */
    private function actingAsStaffWith(array $permissions): User
    {
        $user = User::factory()->create(['status' => 'active']);
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function assertFailsWithoutDetail(TestResponse $response, string $message): void
    {
        // Exactly {message}: no 'error', 'file', 'line', 'step', 'hint' or 'trace'.
        $response->assertStatus(500)->assertExactJson(['message' => $message]);

        $body = $response->getContent();
        foreach (['SQLSTATE', 'leak-test forced failure', 'Connection: pgsql', '/app/', '/var/www', '.php'] as $detail) {
            $this->assertStringNotContainsString($detail, $body, "response leaked '{$detail}'");
        }

        // The detail is not lost: it went to the log, with the exception attached.
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $logged, array $context = []) => $logged === $message
                && ($context['exception'] ?? null) instanceof QueryException
                && str_contains($context['exception']->getMessage(), 'leak-test forced failure'))
            ->once();
    }

    // ── a database failure is logged, not returned ──────────────────────────

    public function test_settings_update_no_longer_returns_the_sql_as_error(): void
    {
        // Was: {"message":"Failed to save settings.","error":"SQLSTATE[P0001] … update \"settings\" …"}
        $this->actingAsStaffWith(['settings.view', 'settings.edit']);
        $this->failWritesTo('settings');
        Log::spy();

        $response = $this->putJson('/api/v1/admin/settings', ['order_prefix' => 'BH']);

        $this->assertFailsWithoutDetail($response, 'Failed to save settings.');
    }

    public function test_user_delete_no_longer_returns_the_file_and_line(): void
    {
        // Was: {"message":"Failed to delete user","error":"SQLSTATE…","step":"/app/…/Connection.php:825"}
        $this->actingAsStaffWith(['users.view', 'users.delete']);
        $victim = User::factory()->create(['status' => 'active']);
        $this->failWritesTo('users', 'UPDATE', 'NEW.deleted_at IS NOT NULL');   // the soft delete
        Log::spy();

        $response = $this->deleteJson("/api/v1/admin/users/{$victim->id}");

        $this->assertFailsWithoutDetail($response, 'Failed to delete user');
        $this->assertNull($victim->fresh()->deleted_at, 'the delete rolled back');
    }

    public function test_expense_submit_no_longer_returns_the_sql_as_its_message(): void
    {
        // Was: {"message":"SQLSTATE[P0001] … update \"expenses\" set \"status\" = pending_approval …"}
        $this->actingAsStaffWith(['expenses.view', 'expenses.create']);
        $category = ExpenseCategory::create(['name' => 'Office', 'code' => 'OFFICE', 'is_active' => true]);
        $id = $this->postJson('/api/v1/admin/expenses', [
            'title' => 'Printer ink', 'category_id' => $category->id, 'expense_date' => now()->toDateString(),
            'amount' => 400, 'currency_code' => 'KES', 'payment_method' => 'cash', 'vendor_name' => 'Text Book Centre',
        ])->assertCreated()->json('expense.id');
        $this->failWritesTo('expenses', 'UPDATE');
        Log::spy();

        $response = $this->postJson("/api/v1/admin/expenses/{$id}/submit");

        $this->assertFailsWithoutDetail($response, 'The expense could not be submitted.');
    }

    // ── a refusal is an answer, not a failure ───────────────────────────────

    public function test_a_validation_refusal_inside_the_catch_is_a_422_not_a_500(): void
    {
        // ProductPrice refuses a sale price at or above the regular price with a
        // ValidationException "so the API answers 422 with a usable message".
        // ProductController::update's catch (\Exception) turned it into a 500
        // reading only "Failed to update product." — the reason never reached
        // the screen.
        $this->actingAsStaffWith(['products.view', 'products.edit']);
        $product = Product::factory()->create();
        DB::table('currencies')->insertOrIgnore(['code' => 'KES', 'name' => 'Kenyan Shilling', 'symbol' => 'KSh']);

        $response = $this->putJson("/api/v1/admin/products/{$product->id}", [
            'prices' => [['currency_code' => 'KES', 'regular_price' => 1000, 'sale_price' => 1500]],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['sale_price']);
        $this->assertSame(0, DB::table('product_prices')->where('product_id', $product->id)->count(), 'nothing was saved');
    }

    public function test_respond_rethrows_refusals_and_hides_everything_else(): void
    {
        $refusals = [
            new AccessDeniedHttpException('Not your outlet.'),
            ValidationException::withMessages(['amount' => 'Too much.']),
        ];
        foreach ($refusals as $refusal) {
            try {
                ServerError::respond($refusal, 'Failed.');
                $this->fail(get_class($refusal) . ' was swallowed');
            } catch (\Throwable $thrown) {
                $this->assertSame($refusal, $thrown);
            }
        }

        Log::spy();
        $response = ServerError::respond(new \RuntimeException('pg_dump: error: connection to server at "db" failed'), 'Backup failed.', status: 422, extra: ['ok' => false]);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['message' => 'Backup failed.', 'ok' => false], $response->getData(true));
    }

    public function test_message_passes_a_refusal_through_and_replaces_anything_else(): void
    {
        // For texts that must not rethrow: a bulk-import row, a Livewire flash.
        Log::spy();

        $this->assertSame('The KES sale price must be lower.', ServerError::message(
            ValidationException::withMessages(['sale_price' => 'The KES sale price must be lower.']),
            'could not be saved.',
        ));
        $this->assertSame('could not be saved.', ServerError::message(
            new \PDOException('SQLSTATE[23505]: Unique violation: duplicate key value (sku)=(CASSOCK-1)'),
            'could not be saved.',
        ));
        Log::shouldHaveReceived('error')->once();
    }

    // ── and the class cannot come back ──────────────────────────────────────

    public function test_no_http_code_puts_exception_detail_in_what_it_returns(): void
    {
        // An exception's message, file, line or trace may appear in app/Http
        // only inside a Log call. The two exceptions are deliberate:
        $allowed = [
            // DatabaseManagementService's own refusals — "Backup record not
            // found.", "'x' is not a recognized clearable table.", "pg_dump is
            // not available on this server…" — written for the super-admin.
            'Controllers/Api/DatabaseManagementController.php' => "/^return response\\(\\)->json\\(\\['message' => \\\$e->getMessage\\(\\)\\], 4\\d\\d\\);$/",
            // Fixed on audit/reports-cycle-9 (the Reports PR) and left alone here
            // so the two branches do not collide. Delete this entry once it lands.
            'Controllers/Api/ReportController.php' => "/^return response\\(\\)->json\\(\\['message' => \\\$e->getMessage\\(\\), 'file' => \\\$e->getFile\\(\\), 'line' => \\\$e->getLine\\(\\)\\], 500\\);$/",
        ];

        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Http')));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(app_path('Http')) + 1);
            $lines    = file($file->getPathname(), FILE_IGNORE_NEW_LINES);

            foreach ($lines as $i => $line) {
                if (!preg_match('/\$\w+->(getMessage|getFile|getLine|getTraceAsString)\(\)/', $line)) {
                    continue;
                }
                // The whole statement the call sits in: back to the previous ; { or }.
                $start = $i;
                while ($start > 0 && !preg_match('/[;{}]\s*$/', $lines[$start - 1])) {
                    $start--;
                }
                $statement = implode(' ', array_map('trim', array_slice($lines, $start, $i - $start + 1)));
                if (preg_match('/Log::|logger\(/', $statement)) {
                    continue;
                }
                if (isset($allowed[$relative]) && preg_match($allowed[$relative], trim($line))) {
                    continue;
                }
                $offenders[] = "{$relative}:" . ($i + 1) . '  ' . trim($line);
            }
        }

        $this->assertSame([], $offenders, "Exception detail returned to the caller — use App\\Support\\ServerError:\n" . implode("\n", $offenders));
    }
}
