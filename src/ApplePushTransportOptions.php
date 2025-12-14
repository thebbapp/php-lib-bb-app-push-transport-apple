<?php

declare(strict_types=1);

namespace BbApp\PushService\PushTransport\Apple;

use BbApp\PushService\PushTransportOptions;

/**
 * Configuration options for Apple push transport.
 *
 * @var string $team_id
 * @var string $key_id
 * @var string $private_key
 * @var string $bundle_id
 * @var bool $sandbox
 */
class ApplePushTransportOptions extends PushTransportOptions
{
    public $team_id;
    public $key_id;
    public $private_key;
    public $bundle_id;
    public $sandbox;

	/**
	 * Constructs Apple transport options with authentication and configuration details.
	 *
	 * @param string $team_id
	 * @param string $key_id
	 * @param string $private_key
	 * @param string $bundle_id
	 * @param bool $sandbox
	 */
    public function __construct(
        string $team_id,
        string $key_id,
        string $private_key,
        string $bundle_id,
        bool $sandbox
    ) {
        $this->team_id = $team_id;
        $this->key_id = $key_id;
        $this->private_key = $private_key;
        $this->bundle_id = $bundle_id;
        $this->sandbox = $sandbox;
    }
}
