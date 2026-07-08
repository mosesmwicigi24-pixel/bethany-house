<?php

namespace App\Services;

use App\Enums\IdentityProvider;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use Illuminate\Support\Facades\DB;

/**
 * Resolves an inbound channel contact (a Meta WhatsApp / Instagram / Messenger
 * message, or any other channel) to a single Customer record.
 *
 * This is the heart of the Neema multichannel-identity epic. A person reaching
 * the business over WhatsApp today, and over Instagram tomorrow, presents two
 * different channel-scoped ids but is one customer. The resolver:
 *
 *   1. returns the same customer whenever the same (provider, provider_uid)
 *      contact comes back;
 *   2. merges a brand-new channel identity onto an EXISTING customer when it can
 *      prove they are the same person via a shared phone number;
 *   3. otherwise creates a fresh customer and hangs the identity off it.
 *
 * It never auto-merges two customers across providers on a weak signal (e.g. a
 * matching display name) — that stays a deliberate, manual action (future work).
 */
class CustomerIdentityResolver
{
    /**
     * Resolve a contact to a customer + identity.
     *
     * @param  array{
     *     provider: string|IdentityProvider,
     *     provider_uid: string,
     *     phone?: string|null,
     *     username?: string|null,
     *     name?: string|null,
     *     profile?: array<string, mixed>|null
     * }  $contact
     * @return array{
     *     customer: Customer,
     *     identity: CustomerIdentity,
     *     customer_created: bool,
     *     identity_created: bool
     * }
     */
    public function resolve(array $contact): array
    {
        $provider = $contact['provider'] instanceof IdentityProvider
            ? $contact['provider']
            : IdentityProvider::from($contact['provider']);

        $uid = trim((string) ($contact['provider_uid'] ?? ''));
        if ($uid === '') {
            throw new \InvalidArgumentException('provider_uid is required to resolve a customer identity.');
        }

        // WhatsApp's wa_id IS the phone number, so use it as the phone when the
        // caller didn't pass one explicitly.
        $rawPhone  = $contact['phone'] ?? ($provider === IdentityProvider::WHATSAPP ? $uid : null);
        $phoneE164 = $rawPhone ? $this->normalisePhone($rawPhone) : null;

        $username    = isset($contact['username']) ? trim((string) $contact['username']) ?: null : null;
        $displayName = isset($contact['name']) ? trim((string) $contact['name']) ?: null : null;
        $profile     = $contact['profile'] ?? null;

        return DB::transaction(function () use ($provider, $uid, $phoneE164, $username, $displayName, $profile) {
            // (1) Have we seen this exact contact before?
            $identity = CustomerIdentity::where('provider', $provider->value)
                ->where('provider_uid', $uid)
                ->lockForUpdate()
                ->first();

            if ($identity) {
                $this->refreshIdentity($identity, $phoneE164, $username, $displayName, $profile, $provider);
                $this->backfillCustomerPhone($identity->customer, $phoneE164);

                return [
                    'customer'         => $identity->customer,
                    'identity'         => $identity,
                    'customer_created' => false,
                    'identity_created' => false,
                ];
            }

            // (2) New contact — attach to an existing customer if a shared phone
            // proves it's the same person, otherwise (3) create one.
            $customer = $this->findCustomerByPhone($phoneE164);
            $customerCreated = false;

            if (! $customer) {
                $customer = $this->createCustomer($provider, $displayName, $phoneE164);
                $customerCreated = true;
            } else {
                $this->backfillCustomerPhone($customer, $phoneE164);
            }

            $identity = $customer->identities()->create([
                'provider'            => $provider->value,
                'provider_uid'        => $uid,
                'username'            => $username,
                'display_name'        => $displayName,
                'phone_e164'          => $phoneE164,
                'profile'             => $profile,
                // Meta hands us platform-authenticated ids on genuine webhooks.
                'verified_at'         => $provider->isMeta() ? now() : null,
                'last_interaction_at' => now(),
            ]);

            return [
                'customer'         => $customer->fresh(),
                'identity'         => $identity,
                'customer_created' => $customerCreated,
                'identity_created' => true,
            ];
        });
    }

    /**
     * Update the mutable fields of a returning identity: capture a phone/handle we
     * didn't have before, keep the display name current, and stamp the touch.
     */
    private function refreshIdentity(
        CustomerIdentity $identity,
        ?string $phoneE164,
        ?string $username,
        ?string $displayName,
        ?array $profile,
        IdentityProvider $provider
    ): void {
        $identity->phone_e164          = $phoneE164 ?: $identity->phone_e164;
        $identity->username            = $username ?: $identity->username;
        $identity->display_name        = $displayName ?: $identity->display_name;
        $identity->profile             = $profile ?: $identity->profile;
        $identity->last_interaction_at = now();
        if ($provider->isMeta() && ! $identity->verified_at) {
            $identity->verified_at = now();
        }
        $identity->save();
    }

    /**
     * Find an existing customer by phone, tolerant of how the number was stored
     * (+254…, 254…, 07…, 7…). Index-friendly — no full-table scan.
     */
    private function findCustomerByPhone(?string $phoneE164): ?Customer
    {
        if (! $phoneE164) {
            return null;
        }

        return Customer::whereIn('phone', $this->phoneVariants($phoneE164))->first();
    }

    /**
     * Backfill a customer's phone if it was unknown and we've now learned it.
     */
    private function backfillCustomerPhone(Customer $customer, ?string $phoneE164): void
    {
        if ($phoneE164 && empty($customer->phone)) {
            $customer->phone = $phoneE164;
            $customer->save();
        }
    }

    /**
     * Create a customer for a contact we've never met on any channel.
     */
    private function createCustomer(IdentityProvider $provider, ?string $displayName, ?string $phoneE164): Customer
    {
        [$firstName, $lastName] = $this->splitName($displayName, $provider);

        return Customer::create([
            'first_name'         => $firstName,
            'last_name'          => $lastName,
            'phone'              => $phoneE164,
            'customer_type'      => 'individual',
            'status'             => 'active',
            // Neema/WhatsApp contacts start without an email; the Customer model
            // generates a placeholder so the NOT-NULL/unique column is satisfied.
        ]);
    }

    /**
     * @return array{0: string, 1: string} [first_name, last_name]
     */
    private function splitName(?string $displayName, IdentityProvider $provider): array
    {
        $name = $displayName ? trim($displayName) : '';
        if ($name === '') {
            return [$provider->label() . ' contact', ''];
        }

        $parts = preg_split('/\s+/', $name, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * Plausible stored representations of a normalised E.164 number, for matching
     * against the free-form `customers.phone` column.
     *
     * @return array<int, string>
     */
    private function phoneVariants(string $phoneE164): array
    {
        $variants = [$phoneE164];                       // +254712345678
        $digits   = ltrim($phoneE164, '+');             // 254712345678
        $variants[] = $digits;

        if (str_starts_with($digits, '254') && strlen($digits) === 12) {
            $local = substr($digits, 3);                // 712345678
            $variants[] = '0' . $local;                 // 0712345678
            $variants[] = $local;                       // 712345678
        }

        return array_values(array_unique($variants));
    }

    /**
     * Normalise a phone number to E.164 (`+254XXXXXXXXX`), defaulting bare/local
     * Kenyan numbers to the +254 country code. Numbers already in another country
     * code (given with a leading +) are preserved.
     *
     * Accepts: +254712345678, 254712345678, 0712345678, 712345678, whatsapp wa_id.
     */
    public function normalisePhone(string $phone): string
    {
        $hadPlus = str_starts_with(trim($phone), '+');
        $digits  = preg_replace('/[^0-9]/', '', $phone);

        if ($digits === '') {
            return '';
        }

        // Kenyan local forms → +254…
        if (str_starts_with($digits, '254') && strlen($digits) === 12) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '+254' . substr($digits, 1);
        }
        if (strlen($digits) === 9) {
            return '+254' . $digits;
        }

        // Already an international number given with a leading + — keep it.
        if ($hadPlus) {
            return '+' . $digits;
        }

        // Unknown shape: return digits as E.164 best-effort so equal inputs
        // still resolve to equal identities.
        return '+' . $digits;
    }
}
