<?php

namespace Tests\Unit;

use App\Support\CountryInference;
use PHPUnit\Framework\TestCase;

/**
 * Phone → country inference. Order country codes cover < 10% of orders but
 * ~90% of customers have a phone, so the dialing prefix is our richest location
 * signal. Longest-prefix match; local Kenyan forms resolve to KE.
 */
class CountryInferenceTest extends TestCase
{
    public function test_explicit_country_code_wins_over_phone(): void
    {
        $this->assertSame('UG', CountryInference::resolve(['UG'], '+254712345678'));
        $this->assertSame('KE', CountryInference::resolve(['', null, 'ke'], null));
    }

    public function test_falls_back_to_phone_when_no_country_code(): void
    {
        $this->assertSame('KE', CountryInference::resolve([null, '', ''], '+254712345678'));
    }

    public function test_local_kenyan_numbers_resolve_to_ke(): void
    {
        $this->assertSame('KE', CountryInference::fromPhone('0712345678'));
        $this->assertSame('KE', CountryInference::fromPhone('0110345678'));
        $this->assertSame('KE', CountryInference::fromPhone('712345678'));
        $this->assertSame('KE', CountryInference::fromPhone('+254712345678'));
        $this->assertSame('KE', CountryInference::fromPhone('254712345678'));
    }

    public function test_international_prefixes_resolve_by_longest_match(): void
    {
        $this->assertSame('ZW', CountryInference::fromPhone('+263771234567'));  // Zimbabwe
        $this->assertSame('UG', CountryInference::fromPhone('+256701234567'));  // Uganda
        $this->assertSame('RW', CountryInference::fromPhone('+250781234567'));  // Rwanda
        $this->assertSame('CD', CountryInference::fromPhone('+243812345678'));  // DR Congo
        $this->assertSame('US', CountryInference::fromPhone('+14155550123'));   // US (1)
        $this->assertSame('GB', CountryInference::fromPhone('+447700900123'));  // UK (44)
        $this->assertSame('ZA', CountryInference::fromPhone('+27821234567'));   // South Africa (27)
    }

    public function test_00_international_prefix_is_handled(): void
    {
        $this->assertSame('UG', CountryInference::fromPhone('00256701234567'));
    }

    public function test_unresolvable_returns_null_not_a_guess(): void
    {
        $this->assertNull(CountryInference::fromPhone(null));
        $this->assertNull(CountryInference::fromPhone(''));
        $this->assertNull(CountryInference::fromPhone('+9990000'));  // unknown code
        $this->assertNull(CountryInference::resolve([null, '', ''], null));
    }
}
