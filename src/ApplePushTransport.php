<?php

declare(strict_types=1);

namespace BbApp\PushService\PushTransport\Apple;

use BbApp\PushService\PushTransport\Apple\Error\{
    ApplePushTransportInvalidAppleToken
};

use BbApp\PushService\PushTransportAbstract;
use BbApp\ContentSource\ContentSourceAbstract;
use BbApp\Result\{Result, Success, Failure};

/**
 * Sends push notifications via Apple Push Notification service.
 *
 * @var ApplePushTransportOptions $options
 */
class ApplePushTransport extends PushTransportAbstract
{
	public $id = 'apple';
	public $message_envelope_subtitle = true;

	protected $options;

	/**
	 * Constructs an Apple push transport with the given content source and options.
	 *
	 * @param ContentSourceAbstract $content_source
	 * @param ApplePushTransportOptions $options
	 */
	public function __construct(
		ContentSourceAbstract $content_source,
		ApplePushTransportOptions $options
	) {
		parent::__construct($content_source, $options);
	}

	/**
	 * Sends a push notification to the specified Apple device tokens.
	 *
	 * @param array $tokens
	 * @param string $title
	 * @param string $body
	 * @param string|null $subtitle
	 * @param string|null $imageUrl
	 * @param string|null $url
	 * @param int|null $badge
	 * @return array
	 */
	public function send(
		array $tokens,
		string $title,
		string $body,
		?string $subtitle = null,
		?string $imageUrl = null,
		?string $url = null,
		?int $badge = null
	): array {
		if (
			$this->options->team_id === '' ||
			$this->options->key_id === '' ||
			$this->options->private_key === '' ||
			$this->options->bundle_id === '' ||
			empty($tokens)
		) {
			return [[], []];
		}

		$jwt = $this->get_apns_jwt(
			$this->options->team_id,
			$this->options->key_id,
			$this->options->private_key
		);

		if ($jwt === '') {
			return [[], []];
		}

		$host = $this->options->sandbox ? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
		$success = [];
		$invalid = [];

		$aps = [
			'alert' => array_filter(compact('title', 'subtitle', 'body')),
			'sound' => 'default'
		];

		if ($badge !== null) {
			$aps += compact('badge');
		}

		$payload = compact('aps');

		if (!empty($url)) {
			$payload['url'] = $url;
		}

		foreach ($tokens as $device_token) {
			$endpointUrl = $host . '/3/device/' . $device_token;

			// Use cURL directly with HTTP/2 support
			$ch = curl_init($endpointUrl);

			if ($ch === false) {
				error_log('Failed to initialize cURL for APNs');
				continue;
			}

			curl_setopt_array($ch, [
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => json_encode($payload),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT => 15,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
				CURLOPT_HTTPHEADER => [
					'authorization: bearer ' . $jwt,
					'apns-topic: ' . $this->options->bundle_id,
					'apns-push-type: alert',
					'content-type: application/json'
				]
			]);

			$resp_body = curl_exec($ch);
			$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$curl_error = curl_error($ch);
			curl_close($ch);

			if ($curl_error !== '') {
				error_log('cURL error for APNs: ' . $curl_error);
				continue;
			}

			$reason = '';

			if (is_string($resp_body) && $resp_body !== '') {
				$json = json_decode($resp_body, true);

				if (is_array($json) && isset($json['reason'])) {
					$reason = (string) $json['reason'];
				}
			}

			if ($code === 200) {
				$success[] = $device_token;
			} elseif ($code === 410 || $reason === 'BadDeviceToken' || $reason === 'Unregistered' || $reason === 'DeviceTokenNotForTopic') {
				$invalid[] = $device_token;
			}
		}

		return [$success, $invalid];
	}

	/**
	 * Handles a scheduled push notification event for the given tokens and content.
	 *
	 * @param array $tokens
	 * @param array $envelope
	 * @param string $content_type
	 * @param int $content_id
	 * @return array
	 */
	public function handle_scheduled_event(
		array $tokens,
		array $envelope,
		string $content_type,
		int $content_id
	): array {
		$apple_tokens = [];
		$id_by_token = [];

		foreach ($tokens as $token) {
			if ($this->token_user_content_can_view($token, $content_type, $content_id) === false) {
				continue;
			}

			$token_str = bin2hex($token['token']);

			if ($token_str !== '') {
				$apple_tokens[] = $token_str;
				$id_by_token[$token_str] = (int) $token['id'];
			}
		}

		$success_ids = [];
		$invalid_ids = [];

		if (!empty($apple_tokens)) {
			list($apns_success_tokens, $apns_invalid_tokens) = $this->send(
				$apple_tokens,
				$envelope['title'],
				$envelope['message'],
				$envelope['subtitle'],
				$envelope['imageUrl'],
				$envelope['url'],
				1
			);

			foreach ($apns_success_tokens as $token) {
				if (isset($id_by_token[$token])) {
					$success_ids[] = (int) $id_by_token[$token];
				}
			}

			foreach ($apns_invalid_tokens as $token) {
				if (isset($id_by_token[$token])) {
					$invalid_ids[] = (int) $id_by_token[$token];
				}
			}
		}

		return [
			'success_ids' => array_values(array_unique($success_ids)),
			'invalid_ids' => array_values(array_unique($invalid_ids))
		];
	}

	/**
	 * Validates an Apple push token format.
	 *
	 * @param string $token
	 * @return Result
	 */
	public function validate_push_token(string $token): Result
	{
		if (!preg_match('/^[A-Fa-f0-9]{64}$/', $token)) {
            return new Failure(new ApplePushTransportInvalidAppleToken());
		}

		return new Success();
	}

	/**
	 * Encodes an Apple push token for storage.
	 *
	 * @param string $token
	 * @return string
	 */
	public function encode_push_token(string $token): string
	{
		return pack('H*', $token);
	}

	/**
	 * Decodes an Apple push token from storage.
	 *
	 * @param string $encoded_token
	 * @return string
	 */
	public function decode_push_token(string $encoded_token): string
	{
		return strtoupper(bin2hex($encoded_token));
	}

	/**
	 * Generates a JWT for Apple Push Notification service authentication.
	 *
	 * @param string $team_id
	 * @param string $key_id
	 * @param string $private_key_p8
	 * @return string
	 */
	private function get_apns_jwt(
		string $team_id,
		string $key_id,
		string $private_key_p8
	): string {
		$header = [
			'alg' => 'ES256',
			'kid' => $key_id,
			'typ' => 'JWT',
		];
		$claims = [
			'iss' => $team_id,
			'iat' => time(),
		];

		$segments = [];
		$segments[] = $this->base64url_encode(json_encode($header));
		$segments[] = $this->base64url_encode(json_encode($claims));
		$signing_input = implode('.', $segments);

		$private_key = openssl_pkey_get_private($private_key_p8);
		if ($private_key === false) {
			return '';
		}

		$signature = '';
		$ok = openssl_sign($signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256);

		if (version_compare(PHP_VERSION, '8.0', '<')) {
			openssl_pkey_free($private_key);
		}

		if (!$ok) {
			return '';
		}

		$raw_signature = $this->ecdsa_der_to_raw($signature);

		if ($raw_signature === '') {
			return '';
		}

		$segments[] = $this->base64url_encode($raw_signature);
		return implode('.', $segments);
	}

	/**
	 * Encodes data using base64url encoding.
	 *
	 * @param string $data
	 * @return string
	 */
	private function base64url_encode(string $data): string
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	/**
	 * Converts an ECDSA DER signature to raw format.
	 *
	 * @param string $der
	 * @return string
	 */
	private function ecdsa_der_to_raw(string $der): string
	{
        $totalLength = 64;
		$offset = 0;
		$derLen = strlen($der);
		if ($derLen < 8 || ord($der[$offset]) !== 0x30) {
			return '';
		}
		$offset++;
		$seqLen = ord($der[$offset++]);
		if ($seqLen & 0x80) {
			$nb = $seqLen & 0x7f;
			$seqLen = 0;
			for ($i = 0; $i < $nb; $i++) {
				$seqLen = ($seqLen << 8) | ord($der[$offset++]);
			}
		}
		if (ord($der[$offset++]) !== 0x02) {
			return '';
		}
		$rLen = ord($der[$offset++]);
		$r = substr($der, $offset, $rLen);
		$offset += $rLen;
		if (ord($der[$offset++]) !== 0x02) {
			return '';
		}
		$sLen = ord($der[$offset++]);
		$s = substr($der, $offset, $sLen);

		$r = ltrim($r, "\x00");
		$s = ltrim($s, "\x00");
		$r = str_pad($r, $totalLength / 2, "\x00", STR_PAD_LEFT);
		$s = str_pad($s, $totalLength / 2, "\x00", STR_PAD_LEFT);

		return $r . $s;
	}
}
