<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Webhook.php';

use FreePBX\modules\RegistrationwatchWebhook;

function webhook_assert(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$error = null;
webhook_assert(
	RegistrationwatchWebhook::validateDestinationUrl('https://hooks.example.invalid/events', false, $error) !== null,
	'HTTPS destinations with a valid host should be accepted.'
);
webhook_assert(
	RegistrationwatchWebhook::validateDestinationUrl('http://hooks.example.invalid/events', false, $error) === null,
	'HTTP should be rejected unless the private-network exception is enabled.'
);
webhook_assert(
	RegistrationwatchWebhook::validateDestinationUrl('http://hooks.example.invalid/events', true, $error) !== null,
	'The explicit internal-network option should permit HTTP URLs for runtime address checking.'
);
webhook_assert(
	RegistrationwatchWebhook::validateDestinationUrl('https://user:pass@example.invalid/hook', true, $error) === null,
	'URLs containing embedded credentials should be rejected.'
);
webhook_assert(
	RegistrationwatchWebhook::validateDestinationUrl('https://[::1]/hook', true, $error) !== null,
	'Literal addresses are validated at delivery time rather than rejected syntactically.'
);
webhook_assert(
	RegistrationwatchWebhook::validateDestinationUrl('https://localhost/hook', true, $error) === null,
	'Localhost names should always be rejected.'
);
webhook_assert(
	RegistrationwatchWebhook::validateDestinationUrl('https://hooks.example.invalid./event', false, $error) === null,
	'Trailing-dot hostnames must not bypass the cURL address pin.'
);
foreach (['127.1', '0177.0.0.1', '2130706433', '0x7f000001'] as $ambiguousAddress) {
	webhook_assert(
		RegistrationwatchWebhook::validateDestinationUrl('https://' . $ambiguousAddress . '/hook', false, $error) === null,
		'Noncanonical numeric IP forms must not bypass hostname validation.'
	);
}
foreach ([
	'api_key',
	'key',
	'sig',
	'signature',
	'passphrase',
	'pass',
	'passcode',
	'pwd',
	'clientKey',
	'clientPass',
	'AWSAccessKeyId',
	'X-Amz-Signature',
] as $credentialKey) {
	webhook_assert(
		RegistrationwatchWebhook::validateDestinationUrl('https://hooks.example.invalid/?' . rawurlencode($credentialKey) . '=secret', false, $error) === null,
		'Common credential query parameters should be rejected in favor of headers.'
	);
}
$credentialError = null;
RegistrationwatchWebhook::validateDestinationUrl(
	'https://hooks.example.invalid/?key=contract-secret-value',
	false,
	$credentialError
);
webhook_assert(
	strpos((string)$credentialError, 'contract-secret-value') === false,
	'Destination validation errors must not echo credential values.'
);
foreach ([
	'https://hooks.example.invalid/?source=pbx;sig=secret',
	'https://hooks.example.invalid/?source=pbx&auth%5Btoken%5D=secret',
] as $credentialUrl) {
	webhook_assert(
		RegistrationwatchWebhook::validateDestinationUrl($credentialUrl, false, $error) === null,
		'Encoded, nested, and semicolon-delimited credential query names must be rejected.'
	);
}

$transportCalls = [];
$resolver = static function (string $host): array {
	return ['8.8.8.8'];
};
$transport = static function ($url, $method, $headers, $body, $parts, $address) use (&$transportCalls): array {
	$transportCalls[] = compact('url', 'method', 'headers', 'body', 'parts', 'address');
	return ['http_status' => 202, 'error' => null];
};
$destination = [
	'url' => 'https://hooks.example.invalid/event',
	'method' => 'POST',
	'headers' => ['Authorization' => 'Bearer secret-value'],
];
$postResult = RegistrationwatchWebhook::deliver($destination, ['event' => 'registration.unregistered'], $resolver, $transport);
webhook_assert($postResult['status'] && $postResult['http_status'] === 202, 'A 2xx response should be a successful delivery.');
webhook_assert($transportCalls[0]['method'] === 'POST', 'POST should be the default method.');
webhook_assert($transportCalls[0]['headers']['Content-Type'] === 'application/json; charset=utf-8', 'POST should send JSON content.');
webhook_assert(json_decode($transportCalls[0]['body'], true) === ['event' => 'registration.unregistered'], 'POST should send the payload as valid JSON.');
webhook_assert(strpos(implode(' ', $transportCalls[0]['headers']), 'secret-value') !== false, 'Configured authorization headers should be passed to the HTTP transport.');

$getDestination = [
	'url' => 'https://hooks.example.invalid/event?source=pbx',
	'method' => 'GET',
	'headers' => [],
];
$getResult = RegistrationwatchWebhook::deliver(
	$getDestination,
	['event' => 'registration.unregistered', 'extension' => '2001'],
	$resolver,
	$transport
);
webhook_assert($getResult['status'], 'GET should accept a successful endpoint response.');
webhook_assert($transportCalls[1]['method'] === 'GET' && $transportCalls[1]['body'] === '', 'GET should not send a request body.');
webhook_assert(strpos($transportCalls[1]['url'], 'source=pbx&event=registration.unregistered&extension=2001') !== false, 'GET payload values should be URL-encoded query parameters.');

$privateCalls = 0;
$privateDestination = [
	'url' => 'http://192.168.1.12:8123/hook',
	'method' => 'POST',
	'allow_private' => true,
];
$privateResult = RegistrationwatchWebhook::deliver(
	$privateDestination,
	['event' => 'registration.test'],
	static function (): array {
		return ['192.168.1.12'];
	},
	static function () use (&$privateCalls): array {
		$privateCalls++;
		return ['http_status' => 200];
	}
);
webhook_assert($privateResult['status'] && $privateCalls === 1, 'An explicitly allowed RFC1918 endpoint should be usable for internal HTTP integrations.');

$blockedResolvers = [
	static function (): array { return ['127.0.0.1']; },
	static function (): array { return ['::ffff:127.0.0.1']; },
	static function (): array { return ['169.254.169.254']; },
	static function (): array { return ['8.8.8.8', '10.0.0.5']; },
	static function (): array { return ['::1']; },
	static function (): array { return ['100.64.0.1']; },
	static function (): array { return ['192.0.2.1']; },
	static function (): array { return ['198.18.0.1']; },
	static function (): array { return ['203.0.113.1']; },
	static function (): array { return ['224.0.0.1']; },
	static function (): array { return ['64:ff9b::a00:1']; },
	static function (): array { return ['2001:db8::1']; },
	static function (): array { return ['2002::1']; },
	static function (): array { return ['ff02::1']; },
];
foreach ($blockedResolvers as $blockedResolver) {
	$blocked = RegistrationwatchWebhook::deliver(
		['url' => 'https://hooks.example.invalid/event', 'method' => 'POST'],
		['event' => 'registration.test'],
		$blockedResolver,
		static function (): array {
			throw new RuntimeException('Blocked destinations must not reach the HTTP transport.');
		}
	);
	webhook_assert(!$blocked['status'] && strpos((string)$blocked['error'], 'blocked network') !== false, 'Loopback, link-local, mixed private/public DNS, and IPv6 loopback destinations should be blocked.');
}
$literalLoopback = RegistrationwatchWebhook::deliver(
	['url' => 'https://[::1]/event', 'method' => 'POST'],
	['event' => 'registration.test'],
	static function (): array {
		throw new RuntimeException('An IP literal must not invoke DNS.');
	},
	static function (): array {
		throw new RuntimeException('A blocked IP literal must not reach the HTTP transport.');
	}
);
webhook_assert(!$literalLoopback['status'], 'Loopback IP literals must be blocked before connection.');

$resolveHost = new ReflectionMethod(RegistrationwatchWebhook::class, 'resolveHost');
$resolveHost->setAccessible(true);
$dnsCalls = [];
$dnsExchange = static function (string $server, string $query, float $timeout) use (&$dnsCalls): string {
	$dnsCalls[] = ['server' => $server, 'timeout' => $timeout];
	$type = (int)unpack('ntype', substr($query, -4, 2))['type'];
	$answer = '';
	if ($type === 1) {
		$answer = "\xc0\x0c" . pack('nnNn', 1, 1, 60, 4) . inet_pton('93.184.216.34');
	} elseif ($type === 28) {
		$ipv6 = inet_pton('2606:2800:220:1:248:1893:25c8:1946');
		$answer = "\xc0\x0c" . pack('nnNn', 28, 1, 60, strlen($ipv6)) . $ipv6;
	}
	return substr($query, 0, 2)
		. pack('nnnnn', 0x8180, 1, $answer === '' ? 0 : 1, 0, 0)
		. substr($query, 12)
		. $answer;
};
$resolvedAddresses = $resolveHost->invoke(null, 'hooks.example.com', $dnsExchange, ['192.0.2.53']);
webhook_assert(
	$resolvedAddresses === ['93.184.216.34', inet_ntop(inet_pton('2606:2800:220:1:248:1893:25c8:1946'))],
	'Bounded DNS resolution should collect both A and AAAA answers from the configured resolver.'
);
webhook_assert(
	count($dnsCalls) === 2 && $dnsCalls[0]['server'] === '192.0.2.53'
		&& $dnsCalls[0]['timeout'] > 0 && $dnsCalls[0]['timeout'] <= 1.0,
	'DNS queries should use the configured timeout budget for each address family.'
);
$cnameExchange = static function (string $server, string $query, float $timeout): string {
	$type = (int)unpack('ntype', substr($query, -4, 2))['type'];
	$question = substr($query, 12);
	$targetName = "\x07backend\x07example\x03com\0";
	$cnamePrefix = "\xc0\x0c" . pack('nnNn', 5, 1, 60, strlen($targetName));
	$targetNameOffset = 12 + strlen($question) + strlen($cnamePrefix);
	$answers = $cnamePrefix . $targetName;
	if ($type === 1) {
		$answers .= pack('n', 0xc000 | $targetNameOffset)
			. pack('nnNn', 1, 1, 60, 4)
			. inet_pton('93.184.216.34');
	}
	return substr($query, 0, 2)
		. pack('nnnnn', 0x8180, 1, $type === 1 ? 2 : 1, 0, 0)
		. $question
		. $answers;
};
$cnameAddresses = $resolveHost->invoke(null, 'hooks.example.com', $cnameExchange, ['192.0.2.53']);
webhook_assert($cnameAddresses === ['93.184.216.34'], 'DNS resolution should follow a CNAME chain to its address record.');
$failedDns = $resolveHost->invoke(
	null,
	'hooks.example.com',
	static function (): ?string {
		return null;
	},
	['192.0.2.53']
);
webhook_assert($failedDns === [], 'Unavailable DNS responses must fail closed without returning an unverified address.');
$unresolvedDelivery = RegistrationwatchWebhook::deliver(
	['url' => 'https://hooks.example.invalid/event', 'method' => 'POST'],
	['event' => 'registration.test'],
	static function (): array {
		return [];
	},
	static function (): array {
		throw new RuntimeException('An unresolved destination must not reach the HTTP transport.');
	}
);
webhook_assert(
	!$unresolvedDelivery['status'] && $unresolvedDelivery['retryable'],
	'Resolver failures must fail closed and remain eligible for bounded queue retries.'
);

$dnsExchangeMethod = new ReflectionMethod(RegistrationwatchWebhook::class, 'dnsExchangeOnPort');
$dnsExchangeMethod->setAccessible(true);
$fixtureCode = '$socket = stream_socket_server("udp://127.0.0.1:0", $errno, $error, STREAM_SERVER_BIND);'
	. 'if (!is_resource($socket)) { fwrite(STDERR, $error); exit(1); }'
	. 'echo stream_socket_get_name($socket, false), "\\n"; fflush(STDOUT);'
	. '$peer = ""; $query = stream_socket_recvfrom($socket, 65535, 0, $peer);'
	. 'usleep(800000); if ($peer !== "") { stream_socket_sendto($socket, $query ?: "", 0, $peer); } sleep(1);';
$dnsFixture = proc_open([PHP_BINARY, '-r', $fixtureCode], [
	0 => ['pipe', 'r'],
	1 => ['pipe', 'w'],
	2 => ['pipe', 'w'],
], $dnsFixturePipes);
webhook_assert(is_resource($dnsFixture), 'A controlled local DNS timeout fixture should start.');
fclose($dnsFixturePipes[0]);
try {
	stream_set_timeout($dnsFixturePipes[1], 2);
	$fixtureAddress = trim((string)fgets($dnsFixturePipes[1]));
	$fixturePort = strrpos($fixtureAddress, ':');
	webhook_assert($fixturePort !== false, 'The controlled DNS fixture should report its bound UDP port.');
	$fixturePort = (int)substr($fixtureAddress, $fixturePort + 1);
	$startedAt = hrtime(true);
	$timedOutDns = $dnsExchangeMethod->invoke(null, '127.0.0.1', str_repeat("\0", 12), 0.15, $fixturePort);
	$dnsElapsed = (hrtime(true) - $startedAt) / 1000000000;
} finally {
	proc_terminate($dnsFixture);
	fclose($dnsFixturePipes[1]);
	fclose($dnsFixturePipes[2]);
	proc_close($dnsFixture);
}
webhook_assert($timedOutDns === null, 'A resolver that answers after the deadline must fail closed.');
webhook_assert($dnsElapsed < 0.6, 'The real UDP resolver exchange must honor its configured timeout.');

$ipv6TransportCalls = [];
$ipv6Delivery = RegistrationwatchWebhook::deliver(
	['url' => 'https://hooks.example.invalid/event', 'method' => 'POST'],
	['event' => 'registration.test'],
	static function (): array {
		return ['2606:2800:220:1:248:1893:25c8:1946'];
	},
	static function ($url, $method, $headers, $body, $parts, $address) use (&$ipv6TransportCalls): array {
		$ipv6TransportCalls[] = $address;
		return ['http_status' => 204];
	}
);
webhook_assert(
	$ipv6Delivery['status'] && $ipv6TransportCalls === ['2606:2800:220:1:248:1893:25c8:1946'],
	'A validated public IPv6 address should be passed through for delivery.'
);
$ulaTransportCalls = [];
$ulaDelivery = RegistrationwatchWebhook::deliver(
	['url' => 'http://[fd12:3456:789a::1]/event', 'method' => 'POST', 'allow_private' => true],
	['event' => 'registration.test'],
	null,
	static function ($url, $method, $headers, $body, $parts, $address) use (&$ulaTransportCalls): array {
		$ulaTransportCalls[] = $address;
		return ['http_status' => 204];
	}
);
webhook_assert(
	$ulaDelivery['status'] && $ulaTransportCalls === ['fd12:3456:789a::1'],
	'An explicitly enabled ULA IPv6 destination should be usable for internal integrations.'
);

$rebindResolverCalls = 0;
$rebindDelivery = RegistrationwatchWebhook::deliver(
	['url' => 'https://hooks.example.invalid/event', 'method' => 'POST'],
	['event' => 'registration.test'],
	static function () use (&$rebindResolverCalls): array {
		$rebindResolverCalls++;
		return $rebindResolverCalls === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
	},
	static function ($url, $method, $headers, $body, $parts, $address): array {
		return ['http_status' => $address === '93.184.216.34' ? 200 : 0];
	}
);
webhook_assert(
	$rebindDelivery['status'] && $rebindResolverCalls === 1,
	'Delivery must use the single validated DNS answer and must not perform an unchecked second resolution.'
);

$curlOptionsMethod = new ReflectionMethod(RegistrationwatchWebhook::class, 'curlOptions');
$curlOptionsMethod->setAccessible(true);
$curlOptions = $curlOptionsMethod->invoke(
	null,
	'POST',
	['Authorization' => 'Bearer secret-value'],
	'{}',
	['host' => 'hooks.example.invalid', 'port' => 443, 'scheme' => 'https'],
	'93.184.216.34'
);
if (function_exists('curl_init')) {
	$curlHandle = curl_init('https://hooks.example.invalid/event');
	webhook_assert(is_resource($curlHandle) || is_object($curlHandle), 'A local cURL handle should initialize for transport-option validation.');
	webhook_assert(curl_setopt_array($curlHandle, $curlOptions), 'The installed cURL runtime should accept the validated IPv4 pin and redirect restrictions.');
	curl_close($curlHandle);
}
webhook_assert(
	$curlOptions[CURLOPT_RESOLVE] === ['hooks.example.invalid:443:93.184.216.34'],
	'cURL must pin the hostname to the validated IPv4 address.'
);
webhook_assert(
	$curlOptions[CURLOPT_FOLLOWLOCATION] === false && $curlOptions[CURLOPT_MAXREDIRS] === 0,
	'HTTP redirects must not be followed, preventing validation bypass and credential forwarding.'
);
$ipv6CurlOptions = $curlOptionsMethod->invoke(
	null,
	'GET',
	[],
	'',
	['host' => 'hooks.example.invalid', 'port' => 443, 'scheme' => 'https'],
	'2606:2800:220:1:248:1893:25c8:1946'
);
webhook_assert(
	$ipv6CurlOptions[CURLOPT_RESOLVE] === ['hooks.example.invalid:443:[2606:2800:220:1:248:1893:25c8:1946]'],
	'cURL must format a validated IPv6 address correctly when pinning the destination.'
);
$redirectCalls = 0;
$redirectResult = RegistrationwatchWebhook::deliver(
	$destination,
	['event' => 'registration.test'],
	$resolver,
	static function () use (&$redirectCalls): array {
		$redirectCalls++;
		return ['http_status' => 302, 'error' => 'redirect response'];
	}
);
webhook_assert(!$redirectResult['status'] && $redirectCalls === 1, 'Redirect responses must fail without a second transport request.');

$httpPrivateBlocked = RegistrationwatchWebhook::deliver(
	['url' => 'http://hooks.example.invalid/event', 'method' => 'POST', 'allow_private' => true],
	['event' => 'registration.test'],
	$resolver,
	static function (): array {
		throw new RuntimeException('Public HTTP destinations must not reach the HTTP transport.');
	}
);
webhook_assert(!$httpPrivateBlocked['status'], 'The internal HTTP exception must not permit public HTTP endpoints.');

foreach (['100.100.100.200', '168.63.129.16', 'fd00:ec2::254'] as $metadataAddress) {
	$metadataBlocked = RegistrationwatchWebhook::deliver(
		['url' => 'https://hooks.example.invalid/event', 'method' => 'POST', 'allow_private' => true],
		['event' => 'registration.test'],
		static function () use ($metadataAddress): array {
			return [$metadataAddress];
		},
		static function (): array {
			throw new RuntimeException('Cloud metadata destinations must not reach the HTTP transport.');
		}
	);
	webhook_assert(!$metadataBlocked['status'], 'Cloud metadata addresses must remain blocked when private destinations are enabled.');
}
foreach (['100.64.0.1', '192.0.2.1', '198.18.0.1', '2001:db8::1', '64:ff9b::a00:1'] as $reservedAddress) {
	$reservedBlocked = RegistrationwatchWebhook::deliver(
		['url' => 'https://hooks.example.invalid/event', 'method' => 'POST', 'allow_private' => true],
		['event' => 'registration.test'],
		static function () use ($reservedAddress): array {
			return [$reservedAddress];
		},
		static function (): array {
			throw new RuntimeException('Reserved destinations must never reach the HTTP transport.');
		}
	);
	webhook_assert(!$reservedBlocked['status'], 'The private-network exception must not permit reserved or special-use addresses.');
}

$templateError = null;
$rendered = RegistrationwatchWebhook::renderPayload(
	'{"extension":"{{extension}}","state":"{{current_status}}","unknown":"{{missing}}"}',
	['extension' => "2001\"\n", 'current_status' => 'Not registered'],
	$templateError
);
webhook_assert($rendered !== null, 'A valid custom JSON template should render.');
webhook_assert($rendered['extension'] === "2001\"\n" && $rendered['unknown'] === '', 'Template values must be escaped as JSON strings and missing values must be empty.');
webhook_assert(
	RegistrationwatchWebhook::renderPayload('{"broken":', ['extension' => '2001'], $templateError) === null,
	'Invalid custom JSON should fail validation.'
);
webhook_assert(
	RegistrationwatchWebhook::renderPayload('{"x":"{{unknown}}"}', ['extension' => '2001'], $templateError) !== null,
	'Unknown variables in persisted templates should substitute an empty string safely.'
);
webhook_assert(
	RegistrationwatchWebhook::renderPayload('{"extension":{{extension}}}', ['extension' => '2001'], $templateError) === null,
	'Payload placeholders outside JSON string values must be rejected.'
);
webhook_assert(
	RegistrationwatchWebhook::renderPayload('{"{{extension}}":"value"}', ['extension' => '2001'], $templateError) === null,
	'Payload placeholders in JSON property names must be rejected.'
);
webhook_assert(
	RegistrationwatchWebhook::renderPayload('{"extension":"{{extension}}"}', ['extension' => "\xB1"], $templateError) === null,
	'Payload values that cannot be JSON-encoded must fail explicitly.'
);

$headerError = null;
webhook_assert(
	RegistrationwatchWebhook::validateHeaders(['Authorization' => "Bearer token\r\nX-Evil: true"], $headerError) === null,
	'Header values containing CR/LF must be rejected.'
);
webhook_assert(
	RegistrationwatchWebhook::validateHeaders(['Host' => 'attacker.example'], $headerError) === null,
	'The caller must not override the Host header.'
);
webhook_assert(
	RegistrationwatchWebhook::validateHeaders(['Authorization' => 'Bearer first', 'authorization' => 'Bearer second'], $headerError) === null,
	'Header names must be unique regardless of case.'
);
webhook_assert(
	RegistrationwatchWebhook::validateHeaders(['X-Value' => ['nested']], $headerError) === null,
	'Non-scalar custom header values must be rejected.'
);

$masterSecret = 'local-freepbx-database-credential';
$encryptedHeaders = RegistrationwatchWebhook::encryptHeaders(['Authorization' => 'Bearer private-value'], $masterSecret);
webhook_assert(is_string($encryptedHeaders) && $encryptedHeaders !== '', 'Authentication headers should be encrypted before persistence.');
webhook_assert(
	RegistrationwatchWebhook::decryptHeaders($encryptedHeaders, $masterSecret) === ['Authorization' => 'Bearer private-value'],
	'Encrypted authentication headers should round-trip with the local master secret.'
);
webhook_assert(
	RegistrationwatchWebhook::decryptHeaders($encryptedHeaders, 'wrong-secret') === null,
	'Authentication header ciphertext must fail closed with the wrong master secret.'
);

$serverError = RegistrationwatchWebhook::deliver(
	$destination,
	['event' => 'registration.test'],
	$resolver,
	static function (): array {
		return ['http_status' => 503];
	}
);
webhook_assert(!$serverError['status'] && $serverError['http_status'] === 503 && $serverError['retryable'], 'HTTP 5xx responses should be unsuccessful and retryable.');
$connectionError = RegistrationwatchWebhook::deliver(
	$destination,
	['event' => 'registration.test'],
	$resolver,
	static function (): array {
		return ['http_status' => 0, 'error' => 'The endpoint request timed out.'];
	}
);
webhook_assert(!$connectionError['status'] && $connectionError['retryable'], 'Connection failures and timeouts should be retryable.');

$curlOptionsMethod = new ReflectionMethod(RegistrationwatchWebhook::class, 'curlOptions');
$curlOptionsMethod->setAccessible(true);
$destinationParts = RegistrationwatchWebhook::validateDestinationUrl($destination['url'], false, $error);
$requestOptions = $curlOptionsMethod->invoke(
	null,
	'POST',
	[],
	'{}',
	$destinationParts,
	'93.184.216.34'
);
webhook_assert(
	$requestOptions[CURLOPT_CONNECTTIMEOUT] === RegistrationwatchWebhook::CONNECT_TIMEOUT_SECONDS
		&& $requestOptions[CURLOPT_TIMEOUT] === RegistrationwatchWebhook::REQUEST_TIMEOUT_SECONDS,
	'HTTP delivery must retain its bounded connect and total request timeouts.'
);

echo "Webhook transport contract passed.\n";
