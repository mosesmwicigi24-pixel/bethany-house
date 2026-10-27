# API contract

`openapi.json` is the OpenAPI 3.1 description of `/api`, generated from the
registered Laravel routes by `php artisan openapi:generate` (native apps
programme, Step 2). CI runs `php artisan openapi:generate --check` and fails
when a route changed without the file being regenerated and committed.

What the file states for every operation: method, path and path parameters,
whether a bearer token is required, and the gates the route's middleware
declares (`x-permissions`, `x-roles`, `x-step-up`, `x-staff-only`,
`x-signed-url`, `x-throttle`), plus the shared error responses (401, 403, 422,
423, 429). Response bodies are described in full only for the operations the
native clients depend on from day one (sign-in, second step, me, renew,
logout, PIN unlock, push registration); everything else is an open object,
to be tightened endpoint by endpoint as API Resources are introduced.

The Swift and Kotlin clients generate their models from this file. Do not
edit it by hand.
