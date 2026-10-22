<?php

namespace App\Services\Comms;

use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The model could not answer: not set up, nothing to work on, declined, or
 * the API failed. A refusal written for the reader — it carries its own 422
 * response (`code: assist_unavailable`), so Laravel renders it as such and no
 * controller ever echoes an exception's message (ServerErrorLeakTest).
 */
class AssistUnavailable extends HttpResponseException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct(response()->json(['message' => $message, 'code' => 'assist_unavailable'], 422), $previous);
    }
}
