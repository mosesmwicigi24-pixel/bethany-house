<?php

namespace Tests\Feature;

use App\Mail\StorefrontOtpMail;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Passwordless "Find my orders" lookup (POST /storefront/otp/{request,verify},
 * GET /storefront/my-orders). Covers WhatsApp AUTHENTICATION-template delivery,
 * email delivery + fallback, phone-encoding matching, single-use codes, the
 * verified session, resend cooldown and no-enumeration.
 */
class StorefrontOtpLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default'                       => 'array',
            'services.whatsapp.token'             => 'test-token',
            'services.whatsapp.phone_number_id'   => '999',
            'services.whatsapp.otp_template'      => 'order_lookup_code',
            'services.whatsapp.otp_template_lang' => 'en',
        ]);
        Cache::flush(); // reset throttle buckets + any codes between tests
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);
        Mail::fake();
    }

    private function order(array $attrs): Order
    {
        return Order::factory()->create(array_merge(['order_type' => 'online'], $attrs));
    }

    /** The OTP actually delivered over WhatsApp (from the faked template body). */
    private function whatsappCode(): ?string
    {
        foreach (Http::recorded() as [$request, $response]) {
            $data = $request->data();
            if (($data['type'] ?? null) === 'template') {
                foreach ($data['template']['components'] ?? [] as $c) {
                    if (($c['type'] ?? null) === 'body') {
                        return $c['parameters'][0]['text'] ?? null;
                    }
                }
            }
        }
        return null;
    }

    public function test_phone_lookup_sends_whatsapp_template_and_returns_all_orders(): void
    {
        $this->order(['customer_phone' => '+254712345678', 'order_number' => 'ORD-AAA111']);
        $this->order(['customer_phone' => '0712345678',    'order_number' => 'ORD-AAA222']); // same person, local form

        $req = $this->postJson('/api/v1/storefront/otp/request', ['contact' => '+254712345678']);
        $req->assertOk()->assertJsonPath('sent', true);
        $this->assertContains('whatsapp', $req->json('channels'));

        Http::assertSent(function ($request) {
            $d = $request->data();
            return str_contains($request->url(), '/messages')
                && ($d['type'] ?? '') === 'template'
                && ($d['to'] ?? '') === '254712345678'
                && ($d['template']['name'] ?? '') === 'order_lookup_code'
                && ($d['template']['language']['code'] ?? '') === 'en';
        });

        $code = $this->whatsappCode();
        $this->assertNotNull($code);

        $verify = $this->postJson('/api/v1/storefront/otp/verify', ['contact' => '254712345678', 'code' => $code]);
        $verify->assertOk();
        $this->assertNotEmpty($verify->json('token'));
        $this->assertCount(2, $verify->json('orders')); // both encodings resolve to one person
    }

    public function test_wrong_code_is_rejected_then_correct_code_succeeds(): void
    {
        $this->order(['customer_phone' => '+254700000000']);
        $this->postJson('/api/v1/storefront/otp/request', ['contact' => '+254700000000'])->assertOk();

        $this->postJson('/api/v1/storefront/otp/verify', ['contact' => '+254700000000', 'code' => '000000'])
            ->assertStatus(422);

        $this->postJson('/api/v1/storefront/otp/verify', ['contact' => '+254700000000', 'code' => $this->whatsappCode()])
            ->assertOk();
    }

    public function test_code_is_single_use(): void
    {
        $this->order(['customer_phone' => '+254711222333']);
        $this->postJson('/api/v1/storefront/otp/request', ['contact' => '+254711222333'])->assertOk();
        $code = $this->whatsappCode();

        $this->postJson('/api/v1/storefront/otp/verify', ['contact' => '+254711222333', 'code' => $code])->assertOk();
        // Reusing the same code must fail — it was consumed.
        $this->postJson('/api/v1/storefront/otp/verify', ['contact' => '+254711222333', 'code' => $code])->assertStatus(422);
    }

    public function test_email_lookup_uses_email_channel(): void
    {
        $this->order(['customer_email' => 'grace@example.com', 'customer_phone' => '+254733111222']);

        $r = $this->postJson('/api/v1/storefront/otp/request', ['contact' => 'GRACE@example.com']);
        $r->assertOk();
        $this->assertContains('email', $r->json('channels'));
        Mail::assertSent(StorefrontOtpMail::class);
    }

    public function test_phone_with_email_on_file_also_emails_as_fallback(): void
    {
        $this->order(['customer_phone' => '+254799888777', 'customer_email' => 'p@example.com']);

        $this->postJson('/api/v1/storefront/otp/request', ['contact' => '+254799888777'])->assertOk();
        Mail::assertSent(StorefrontOtpMail::class); // insurance while template pending
    }

    public function test_my_orders_requires_a_valid_session(): void
    {
        $this->getJson('/api/v1/storefront/my-orders')->assertStatus(401);
        $this->getJson('/api/v1/storefront/my-orders', ['X-BH-Session' => 'bogus'])->assertStatus(401);

        $this->order(['customer_email' => 'joan@example.com']);
        $this->postJson('/api/v1/storefront/otp/request', ['contact' => 'joan@example.com'])->assertOk();

        $code = null;
        Mail::assertSent(StorefrontOtpMail::class, function ($m) use (&$code) { $code = $m->code; return true; });

        $token = $this->postJson('/api/v1/storefront/otp/verify', ['contact' => 'joan@example.com', 'code' => $code])
            ->json('token');

        $this->getJson('/api/v1/storefront/my-orders', ['X-BH-Session' => $token])
            ->assertOk()->assertJsonCount(1, 'orders');
    }

    public function test_unknown_contact_does_not_enumerate(): void
    {
        $this->postJson('/api/v1/storefront/otp/request', ['contact' => '+254701010101'])
            ->assertOk()->assertJsonPath('sent', true);
    }

    public function test_invalid_contact_is_rejected(): void
    {
        $this->postJson('/api/v1/storefront/otp/request', ['contact' => 'not-a-contact'])->assertStatus(422);
    }

    public function test_resend_cooldown_blocks_immediate_repeat(): void
    {
        $this->order(['customer_phone' => '+254702020202']);
        $this->postJson('/api/v1/storefront/otp/request', ['contact' => '+254702020202'])->assertOk();
        $this->postJson('/api/v1/storefront/otp/request', ['contact' => '+254702020202'])->assertStatus(429);
    }
}
