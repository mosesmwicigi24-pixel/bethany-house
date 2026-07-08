<?php

namespace App\Http\Controllers\Api;

use App\Enums\IdentityProvider;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CustomerIdentityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Multichannel identity endpoints for the Neema agent.
 *
 * `resolve` is the one the agent calls per inbound conversation: hand it the
 * channel contact and it returns the customer behind it (creating/linking as
 * needed), so the agent can then place a pending order against a real
 * `customer_id` regardless of which Meta channel the shopper came in on.
 */
class CustomerIdentityController extends Controller
{
    public function __construct(private readonly CustomerIdentityResolver $resolver)
    {
    }

    /**
     * Resolve an inbound channel contact to a customer + identity.
     *
     * POST /api/v1/admin/neema/identities/resolve
     */
    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider'     => ['required', 'string', 'in:' . implode(',', IdentityProvider::values())],
            'provider_uid' => ['required', 'string', 'max:191'],
            'phone'        => ['nullable', 'string', 'max:30'],
            'username'     => ['nullable', 'string', 'max:255'],
            'name'         => ['nullable', 'string', 'max:255'],
            'profile'      => ['nullable', 'array'],
        ]);

        try {
            $result = $this->resolver->resolve($validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Customer identity resolve failed', [
                'provider' => $validated['provider'],
                'error'    => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to resolve customer identity.'], 500);
        }

        return response()->json([
            'customer_created' => $result['customer_created'],
            'identity_created' => $result['identity_created'],
            'customer'         => $this->transformCustomer($result['customer']),
            'identity'         => $this->transformIdentity($result['identity']),
        ], $result['identity_created'] ? 201 : 200);
    }

    /**
     * List the channel identities linked to a customer.
     *
     * GET /api/v1/admin/customers/{id}/identities
     */
    public function index(int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        return response()->json([
            'data' => $customer->identities()
                ->orderByDesc('last_interaction_at')
                ->get()
                ->map(fn ($identity) => $this->transformIdentity($identity))
                ->all(),
        ]);
    }

    private function transformCustomer(Customer $customer): array
    {
        return [
            'id'            => $customer->id,
            'customer_number' => $customer->customer_number,
            'first_name'    => $customer->first_name,
            'last_name'     => $customer->last_name,
            'full_name'     => $customer->full_name,
            'phone'         => $customer->phone,
            'email'         => $customer->email,
        ];
    }

    private function transformIdentity($identity): array
    {
        return [
            'id'                  => $identity->id,
            'customer_id'         => $identity->customer_id,
            'provider'            => $identity->provider->value,
            'provider_uid'        => $identity->provider_uid,
            'username'            => $identity->username,
            'display_name'        => $identity->display_name,
            'phone_e164'          => $identity->phone_e164,
            'verified'            => $identity->isVerified(),
            'last_interaction_at' => $identity->last_interaction_at,
        ];
    }
}
