<?php

declare(strict_types=1);

namespace BbApp\PushService\PushTransport\Apple\Error;

use BbApp\PushService\Error\PushTransportError;

/**
 * Thrown when an Apple device token is invalid.
 */
final class ApplePushTransportInvalidAppleToken extends PushTransportError
{
}
