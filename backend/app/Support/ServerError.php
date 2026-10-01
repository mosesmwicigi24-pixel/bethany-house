<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * What a catch-all block says to the caller when the work failed.
 *
 * Controllers used to answer with the exception's own message — and sometimes
 * its file and line. A QueryException's message is the SQL text with its
 * bindings; a filesystem or process error carries server paths and tool
 * output. Production runs with APP_DEBUG=false precisely so Laravel's handler
 * hides these, and every such catch walked around it by putting the message in
 * its own JSON. Most of those blocks logged nothing, so the response body was
 * the only place the detail went: here it goes to the log instead, in full,
 * and the caller gets the fixed sentence the endpoint already used.
 *
 * A refusal is not a failure. abort(403), abort(422), a ValidationException
 * (an imprest without enough float, a sale price above the regular price) and
 * an AuthorizationException are answers the code chose, and the framework
 * renders them as 4xx. A catch (\Exception) around them used to turn each one
 * into a 500; they are rethrown untouched.
 */
final class ServerError
{
    /**
     * Rethrow a refusal; otherwise log the failure and answer with $message.
     *
     * Call it after the block's own cleanup (DB::rollBack()), so a rethrown
     * refusal never leaves a transaction open.
     *
     * @param  array<string, mixed>  $context  extra log context (ids, not secrets)
     * @param  array<string, mixed>  $extra    extra keys the response already carried
     */
    public static function respond(
        Throwable $e,
        string $message,
        array $context = [],
        int $status = 500,
        array $extra = [],
    ): JsonResponse {
        if (self::isRefusal($e)) {
            throw $e;
        }

        self::log($e, $message, $context);

        return response()->json(['message' => $message] + $extra, $status);
    }

    /**
     * Text for a message shown inside an otherwise successful answer — a row
     * of an import, a Livewire flash — where the failure must not be rethrown.
     * A refusal's own words are written for the reader and pass through;
     * anything else is logged and replaced by $fallback.
     *
     * @param  array<string, mixed>  $context
     */
    public static function message(Throwable $e, string $fallback, array $context = []): string
    {
        if (self::isRefusal($e) && $e->getMessage() !== '') {
            return $e->getMessage();
        }

        self::log($e, $fallback, $context);

        return $fallback;
    }

    /** The exceptions Laravel's own handler answers with a deliberate 4xx. */
    public static function isRefusal(Throwable $e): bool
    {
        return $e instanceof HttpExceptionInterface
            || $e instanceof ValidationException
            || $e instanceof AuthorizationException;
    }

    /** @param array<string, mixed> $context */
    private static function log(Throwable $e, string $message, array $context): void
    {
        $request = app()->bound('request') ? request() : null;

        // Resolving the user can query the token table; after a database
        // failure that query may fail too, and must not mask the original.
        try {
            $userId = $request?->user()?->getAuthIdentifier();
        } catch (Throwable) {
            $userId = null;
        }

        // 'exception' makes the logger write the class, message, file, line
        // and trace — everything the response no longer carries.
        Log::error($message, $context + [
            'path'      => $request?->path(),
            'user_id'   => $userId,
            'exception' => $e,
        ]);
    }
}
