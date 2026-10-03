<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A discount larger than App\Support\DiscountRule allows this caller.
 *
 * An HttpException (422), not a ValidationException, on purpose: the POS
 * entry points run their arithmetic inside try/catch blocks that rethrow
 * HttpExceptionInterface and turn everything else into a 500 "the till is
 * broken". The body still has the validation shape — `message` plus `errors`
 * keyed by the field — so the console can put the sentence under the input
 * that caused it.
 */
class DiscountAboveMaximum extends HttpException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct(422, $message);
    }

    public function render($request = null): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code'    => 'DISCOUNT_ABOVE_MAXIMUM',
            'errors'  => [$this->field => [$this->getMessage()]],
        ], 422);
    }
}
