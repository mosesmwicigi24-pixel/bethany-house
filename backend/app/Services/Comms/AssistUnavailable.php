<?php

namespace App\Services\Comms;

/** The model could not answer: not set up, declined, or the API failed. The message is safe to show. */
class AssistUnavailable extends \RuntimeException
{
}
