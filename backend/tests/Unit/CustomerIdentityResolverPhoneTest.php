<?php

namespace Tests\Unit;

use App\Services\CustomerIdentityResolver;
use PHPUnit\Framework\TestCase;

/**
 * Pure phone-normalisation behaviour of the identity resolver — no DB. All
 * Kenyan local forms (and a WhatsApp wa_id) must collapse to one E.164 string so
 * the same person always resolves to the same identity.
 */
class CustomerIdentityResolverPhoneTest extends TestCase
{
    private CustomerIdentityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new CustomerIdentityResolver();
    }

    /**
     * @dataProvider kenyanNumbers
     */
    public function test_kenyan_forms_normalise_to_e164(string $input): void
    {
        $this->assertSame('+254712345678', $this->resolver->normalisePhone($input));
    }

    public static function kenyanNumbers(): array
    {
        return [
            'international +' => ['+254712345678'],
            'wa_id digits'    => ['254712345678'],
            'local zero'      => ['0712345678'],
            'bare nine'       => ['712345678'],
            'spaced'          => ['+254 712 345 678'],
        ];
    }

    public function test_foreign_number_keeps_its_country_code(): void
    {
        $this->assertSame('+12025550187', $this->resolver->normalisePhone('+1 202 555 0187'));
    }

    public function test_empty_input_returns_empty_string(): void
    {
        $this->assertSame('', $this->resolver->normalisePhone('   '));
    }
}
