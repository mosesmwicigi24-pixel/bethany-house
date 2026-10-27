<?php

namespace Tests\Unit\Push;

use App\Services\Push\ApnsSender;
use PHPUnit\Framework\TestCase;

/** The ES256 JOSE signature is r||s, not the DER that OpenSSL emits. */
class ApnsSignatureTest extends TestCase
{
    public function test_der_signature_converts_to_raw_r_and_s_of_fixed_length(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_sign('hello', $der, $key, OPENSSL_ALGO_SHA256);

        $raw = ApnsSender::derToRawSignature($der, 32);

        $this->assertSame(64, strlen($raw));

        // And the raw signature verifies once rebuilt as DER by hand: r and s
        // round-trip, so no byte was lost to the padding logic.
        $r = ltrim(substr($raw, 0, 32), "\x00");
        $s = ltrim(substr($raw, 32), "\x00");
        $int = fn (string $v) => "\x02" . chr(strlen($v) + (ord($v[0]) & 0x80 ? 1 : 0)) . ((ord($v[0]) & 0x80) ? "\x00" : '') . $v;
        $body = $int($r) . $int($s);
        $rebuilt = "\x30" . chr(strlen($body)) . $body;

        $pub = openssl_pkey_get_details($key)['key'];
        $this->assertSame(1, openssl_verify('hello', $rebuilt, $pub, OPENSSL_ALGO_SHA256));
    }

    public function test_short_values_are_left_padded(): void
    {
        // r = 0x01, s = 0x02 → both padded to 32 bytes.
        $der = "\x30\x06\x02\x01\x01\x02\x01\x02";

        $raw = ApnsSender::derToRawSignature($der, 32);

        $this->assertSame(str_pad("\x01", 32, "\x00", STR_PAD_LEFT) . str_pad("\x02", 32, "\x00", STR_PAD_LEFT), $raw);
    }
}
