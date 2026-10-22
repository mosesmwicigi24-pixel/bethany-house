<?php

/*
|--------------------------------------------------------------------------
| Assist — the language model behind the Messages "Assist" rail
|--------------------------------------------------------------------------
|
| Owner's messaging redesign (22 Oct 2026, PR 3): summarise a thread,
| translate between English and Kiswahili, draft the customer message from
| the order, suggest replies. Thread text leaves the hub for Anthropic's API
| when a person taps one of those; nothing runs on its own, nothing is
| stored by the hub beyond an audit row with the action and sizes (never
| the text). No key, no rail: the console shows "Assist is not set up".
|
*/

return [
    'enabled' => (bool) env('ASSIST_ENABLED', true),
    'api_key' => env('ANTHROPIC_API_KEY'),
    'model'   => env('ASSIST_MODEL', 'claude-opus-5-5'),
    // How many of the newest messages a summary or a draft may read.
    'max_messages' => (int) env('ASSIST_MAX_MESSAGES', 80),
];
