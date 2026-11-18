<?php

namespace FreePBX\modules;

class RegistrationwatchWebhook {
	const MAX_DESTINATIONS = 20;
	const MAX_DESTINATION_CONFIG_BYTES = 8000000;
	const MAX_PAYLOAD_BYTES = 8192;
	const MAX_HEADERS = 16;
	const CONNECT_TIMEOUT_SECONDS = 2;
	const REQUEST_TIMEOUT_SECONDS = 5;
	const DNS_TIMEOUT_SECONDS = 2.0;
	const DNS_QUERY_TIMEOUT_SECONDS = 1.0;
	const DNS_DEFAULT_PORT = 53;
	const DNS_MAX_RECORDS = 256;
	const DNS_MAX_CNAME_HOPS = 8;

	public static function validateDestinationUrl(string $url, bool $allowPrivate, ?string &$error = null): ?array {
		$error = null;
		if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
			$error = 'Enter a valid destination URL.';
			return null;
		}

		$parts = parse_url($url);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
			$error = 'Enter an absolute HTTP or HTTPS URL without credentials or a fragment.';
			return null;
		}

		$scheme = strtolower((string)$parts['scheme']);
		if ($scheme !== 'https' && !($scheme === 'http' && $allowPrivate)) {
			$error = 'HTTPS is required. HTTP is allowed only for explicitly enabled private-network destinations.';
			return null;
		}

		$hostInput = strtolower(trim((string)$parts['host'], '[]'));
		$host = rtrim($hostInput, '.');
		if ($hostInput !== $host) {
			$error = 'Destination host names must not end with a dot.';
			return null;
		}
		if ($host === '' || $host === 'localhost' || substr($host, -10) === '.localhost' || substr($host, -6) === '.local') {
			$error = 'Loopback and local-only hostnames are not allowed.';
			return null;
		}

		if (filter_var($host, FILTER_VALIDATE_IP) === false
			&& filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
			$error = 'The destination host name is invalid.';
			return null;
		}
		if (filter_var($host, FILTER_VALIDATE_IP) === false
			&& preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+)){0,3}$/i', $host)) {
			$error = 'Ambiguous numeric IP address formats are not allowed.';
			return null;
		}

		$port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
		if ($port < 1 || $port > 65535) {
			$error = 'The destination port is invalid.';
			return null;
		}

		if (in_array($host, ['metadata.google.internal', 'metadata.google', 'metadata.azure.internal', 'instance-data.ec2.internal'], true)) {
			$error = 'Cloud metadata destinations are not allowed.';
			return null;
		}
		if (!empty($parts['query'])) {
			foreach (preg_split('/[&;]/', (string)$parts['query']) as $parameter) {
				$equals = strpos($parameter, '=');
				$key = strtolower(urldecode($equals === false ? $parameter : substr($parameter, 0, $equals)));
				if (preg_match('/(?:auth|bearer|token|secret|password|passwd|passphrase|passcode|credential|signature|(?:^|[^a-z0-9])(?:key|sig|sign|pass|pwd)(?:$|[^a-z0-9])|(?:api|access|client|encryption|private|public|secret|signing).?(?:key|pass(?:word|phrase|code)?))/', $key)) {
					$error = 'Do not put credentials in the destination URL; use encrypted custom headers instead.';
					return null;
				}
			}
		}

		return [
			'host' => $host,
			'port' => $port,
			'scheme' => $scheme,
		];
	}

	public static function validateHeaders(array $headers, ?string &$error = null): ?array {
		$error = null;
		if (count($headers) > self::MAX_HEADERS) {
			$error = 'A maximum of 16 custom headers is allowed.';
			return null;
		}

		$validated = [];
		$seenNames = [];
		foreach ($headers as $name => $value) {
			$name = trim((string)$name);
			if (!is_scalar($value)) {
				$error = 'Custom header names or values are invalid.';
				return null;
			}
			$value = (string)$value;
			$lowerName = strtolower($name);
			if (!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]{1,128}$/', $name)
				|| in_array($lowerName, ['host', 'content-length', 'transfer-encoding', 'connection', 'proxy-authorization'], true)
				|| isset($seenNames[$lowerName])
				|| strlen($value) > 2048
				|| preg_match('/[\x00-\x1f\x7f]/', $value)) {
				$error = 'Custom header names or values are invalid.';
				return null;
			}
			$seenNames[$lowerName] = true;
			$validated[$name] = $value;
		}

		return $validated;
	}

	public static function renderPayload(?string $template, array $variables, ?string &$error = null): ?array {
		$error = null;
		if ($template === null || trim($template) === '') {
			return $variables;
		}
		if (strlen($template) > self::MAX_PAYLOAD_BYTES) {
			$error = 'The JSON payload template exceeds 8192 bytes.';
			return null;
		}
		if (!self::placeholdersAreJsonStringValues($template)) {
			$error = 'Payload variables must appear inside JSON string values.';
			return null;
		}

		$encodingFailed = false;
		$rendered = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function (array $matches) use ($variables, &$encodingFailed) {
			$value = isset($variables[$matches[1]]) ? (string)$variables[$matches[1]] : '';
			$encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if (!is_string($encoded)) {
				$encodingFailed = true;
				return '';
			}
			return substr($encoded, 1, -1);
		}, $template);
		if ($encodingFailed) {
			$error = 'A payload variable could not be encoded as valid JSON text.';
			return null;
		}
		if (!is_string($rendered) || substr(ltrim($rendered), 0, 1) !== '{') {
			$error = 'The payload template must render a JSON object.';
			return null;
		}

		$payload = json_decode($rendered, true);
		if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE || substr(ltrim($rendered), 0, 1) !== '{') {
			$error = 'The payload template is not valid JSON.';
			return null;
		}

		return $payload;
	}

	private static function placeholdersAreJsonStringValues(string $template): bool {
		preg_match_all('/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/', $template, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as $match) {
			$offset = (int)$match[1];
			$inString = false;
			$escaped = false;
			for ($index = 0; $index < $offset; $index++) {
				$character = $template[$index];
				if ($escaped) {
					$escaped = false;
				} elseif ($character === '\\' && $inString) {
					$escaped = true;
				} elseif ($character === '"') {
					$inString = !$inString;
				}
			}
			if (!$inString) {
				return false;
			}

			$end = $offset + strlen((string)$match[0]);
			$escaped = false;
			for (; $end < strlen($template); $end++) {
				$character = $template[$end];
				if ($escaped) {
					$escaped = false;
				} elseif ($character === '\\') {
					$escaped = true;
				} elseif ($character === '"') {
					$end++;
					break;
				}
			}
			while ($end < strlen($template) && ctype_space($template[$end])) {
				$end++;
			}
			if ($end < strlen($template) && $template[$end] === ':') {
				return false;
			}
		}
		return true;
	}

	public static function deliver(
		array $destination,
		array $payload,
		?callable $resolver = null,
		?callable $transport = null
	): array {
		$url = trim((string)($destination['url'] ?? ''));
		$allowPrivate = !empty($destination['allow_private']);
		$partsError = null;
		$parts = self::validateDestinationUrl($url, $allowPrivate, $partsError);
		if ($parts === null) {
			return ['status' => false, 'http_status' => null, 'error' => $partsError];
		}

		$addresses = filter_var($parts['host'], FILTER_VALIDATE_IP) !== false
			? [$parts['host']]
			: ($resolver !== null ? $resolver($parts['host']) : self::resolveHost($parts['host']));
		$addresses = is_array($addresses) ? array_values(array_unique($addresses)) : [];
		if (!$addresses) {
			return [
				'status' => false,
				'http_status' => null,
				'error' => 'The destination host could not be resolved.',
				'retryable' => true,
			];
		}

		foreach ($addresses as $address) {
			if (!self::isAllowedAddress((string)$address, $allowPrivate)) {
				return ['status' => false, 'http_status' => null, 'error' => 'The destination resolves to a blocked network address.'];
			}
		}
		if ($parts['scheme'] === 'http' && (!$allowPrivate || !self::allAddressesPrivate($addresses))) {
			return ['status' => false, 'http_status' => null, 'error' => 'HTTP is permitted only for an explicitly enabled private-network destination.'];
		}

		$method = strtoupper((string)($destination['method'] ?? 'POST'));
		if (!in_array($method, ['POST', 'GET'], true)) {
			return ['status' => false, 'http_status' => null, 'error' => 'Unsupported HTTP method.'];
		}

		$headersError = null;
		$headers = self::validateHeaders((array)($destination['headers'] ?? []), $headersError);
		if ($headers === null) {
			return ['status' => false, 'http_status' => null, 'error' => $headersError];
		}

		$body = '';
		$requestUrl = $url;
		if ($method === 'POST') {
			$body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if (!is_string($body)) {
				return ['status' => false, 'http_status' => null, 'error' => 'The notification payload could not be encoded as JSON.'];
			}
			$headers = ['Content-Type' => 'application/json; charset=utf-8'] + $headers;
		} else {
			$query = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
			$requestUrl .= (strpos($requestUrl, '?') === false ? '?' : '&') . $query;
		}

		if ($transport !== null) {
			$response = $transport($requestUrl, $method, $headers, $body, $parts, (string)$addresses[0]);
		} else {
			$response = self::curlRequest($requestUrl, $method, $headers, $body, $parts, (string)$addresses[0]);
		}

		$status = isset($response['http_status']) ? (int)$response['http_status'] : 0;
		$success = $status >= 200 && $status < 300;
		return [
			'status' => $success,
			'http_status' => $status > 0 ? $status : null,
			'error' => $success ? null : (string)($response['error'] ?? ($status > 0 ? 'The endpoint returned an unsuccessful HTTP status.' : 'The connection failed.')),
			'retryable' => $status === 0 || $status === 408 || $status === 429 || $status >= 500,
		];
	}

	public static function encryptHeaders(array $headers, string $masterSecret): ?string {
		if ($masterSecret === '' || !function_exists('openssl_encrypt')) {
			return null;
		}
		try {
			$nonce = random_bytes(12);
		} catch (\Throwable $e) {
			return null;
		}
		$key = hash('sha256', "registrationwatch-webhook-headers\0" . $masterSecret, true);
		$tag = '';
		$plain = json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($plain)) {
			return null;
		}
		$ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'registrationwatch-webhook-v1', 16);
		if (!is_string($ciphertext)) {
			return null;
		}
		return base64_encode($nonce . $tag . $ciphertext);
	}

	public static function decryptHeaders(string $encrypted, string $masterSecret): ?array {
		if ($encrypted === '' || $masterSecret === '' || !function_exists('openssl_decrypt')) {
			return null;
		}
		$data = base64_decode($encrypted, true);
		if (!is_string($data) || strlen($data) < 29) {
			return null;
		}
		$nonce = substr($data, 0, 12);
		$tag = substr($data, 12, 16);
		$ciphertext = substr($data, 28);
		$key = hash('sha256', "registrationwatch-webhook-headers\0" . $masterSecret, true);
		$plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'registrationwatch-webhook-v1');
		if (!is_string($plain)) {
			return null;
		}
		$headers = json_decode($plain, true);
		return is_array($headers) ? $headers : null;
	}

	private static function resolveHost(string $host, ?callable $exchange = null, ?array $servers = null): array {
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return [$host];
		}
		if (!function_exists('hrtime')) {
			return [];
		}
		$servers = $servers ?? self::dnsServers();
		if (!$servers) {
			return [];
		}

		$deadline = self::monotonicTime() + self::DNS_TIMEOUT_SECONDS;
		$addresses = [];
		foreach ([1, 28] as $type) {
			$queryDeadline = min($deadline, self::monotonicTime() + self::DNS_QUERY_TIMEOUT_SECONDS);
			$result = self::queryDns($host, $type, $servers, $queryDeadline, $exchange);
			if (!$result['complete']) {
				return [];
			}
			$addresses = array_merge($addresses, $result['addresses']);
		}
		return array_values(array_unique($addresses));
	}

	private static function dnsServers(): array {
		$contents = @file_get_contents('/etc/resolv.conf');
		if (!is_string($contents)) {
			return [];
		}

		$servers = [];
		foreach (preg_split('/\r?\n/', $contents) as $line) {
			if (!preg_match('/^\s*nameserver\s+([^\s#]+)/i', $line, $matches)) {
				continue;
			}
			$server = trim($matches[1], '[]');
			if (filter_var($server, FILTER_VALIDATE_IP) !== false) {
				$servers[] = $server;
			}
		}
		return array_values(array_unique($servers));
	}

	private static function queryDns(string $host, int $type, array $servers, float $deadline, ?callable $exchange): array {
		$encodedHost = self::encodeDnsName($host);
		if ($encodedHost === null) {
			return ['complete' => false, 'addresses' => []];
		}

		try {
			$id = random_int(0, 65535);
		} catch (\Throwable $e) {
			return ['complete' => false, 'addresses' => []];
		}
		$query = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0) . $encodedHost . pack('nn', $type, 1);
		$serverCount = count($servers);
		foreach (array_values($servers) as $index => $server) {
			$remaining = $deadline - self::monotonicTime();
			if ($remaining <= 0) {
				break;
			}
			$timeout = $remaining / ($serverCount - $index);
			$response = $exchange !== null
				? $exchange((string)$server, $query, $timeout)
				: self::dnsExchange((string)$server, $query, $timeout);
			if (!is_string($response)) {
				continue;
			}

			$parsed = self::parseDnsResponse($response, $id, $host, $type);
			if ($parsed !== null) {
				return ['complete' => true, 'addresses' => $parsed];
			}
		}
		return ['complete' => false, 'addresses' => []];
	}

	private static function encodeDnsName(string $host): ?string {
		$host = rtrim($host, '.');
		if ($host === '') {
			return null;
		}

		$encoded = '';
		foreach (explode('.', $host) as $label) {
			$length = strlen($label);
			if ($length < 1 || $length > 63) {
				return null;
			}
			$encoded .= chr($length) . $label;
		}
		$encoded .= "\0";
		return strlen($encoded) <= 255 ? $encoded : null;
	}

	private static function parseDnsResponse(string $response, int $id, string $host, int $type): ?array {
		if (strlen($response) < 12) {
			return null;
		}
		$header = unpack('nid/nflags/nquestions/nanswers/nauthority/nadditional', substr($response, 0, 12));
		$flags = (int)$header['flags'];
		$recordCount = (int)$header['answers'] + (int)$header['authority'] + (int)$header['additional'];
		if ((int)$header['id'] !== $id
			|| ($flags & 0x8000) === 0
			|| ($flags & 0x7800) !== 0
			|| ($flags & 0x0200) !== 0
			|| (int)$header['questions'] !== 1
			|| $recordCount > self::DNS_MAX_RECORDS) {
			return null;
		}

		$rcode = $flags & 0x000f;
		if ($rcode !== 0 && $rcode !== 3) {
			return null;
		}

		$offset = 12;
		$question = self::decodeDnsName($response, $offset, strlen($response));
		if ($question === null) {
			return null;
		}
		$offset = $question['offset'];
		if ($offset + 4 > strlen($response)) {
			return null;
		}
		$questionFields = unpack('ntype/nclass', substr($response, $offset, 4));
		$offset += 4;
		if (self::canonicalDnsName($question['name']) !== self::canonicalDnsName($host)
			|| (int)$questionFields['type'] !== $type
			|| (int)$questionFields['class'] !== 1) {
			return null;
		}
		if ($rcode === 3) {
			return [];
		}

		$addressesByName = [];
		$aliases = [];
		$answerCount = (int)$header['answers'];
		for ($index = 0; $index < $recordCount; $index++) {
			$owner = self::decodeDnsName($response, $offset, strlen($response));
			if ($owner === null) {
				return null;
			}
			$offset = $owner['offset'];
			if ($offset + 10 > strlen($response)) {
				return null;
			}
			$record = unpack('ntype/nclass/Nttl/nlength', substr($response, $offset, 10));
			$offset += 10;
			$dataOffset = $offset;
			$dataEnd = $dataOffset + (int)$record['length'];
			if ($dataEnd > strlen($response)) {
				return null;
			}
			$offset = $dataEnd;
			if ($index >= $answerCount || (int)$record['class'] !== 1) {
				continue;
			}

			$ownerName = self::canonicalDnsName($owner['name']);
			if ((int)$record['type'] === $type) {
				$expectedLength = $type === 1 ? 4 : 16;
				if ((int)$record['length'] !== $expectedLength) {
					return null;
				}
				$address = @inet_ntop(substr($response, $dataOffset, $expectedLength));
				if (is_string($address)) {
					$addressesByName[$ownerName][] = $address;
				}
			} elseif ((int)$record['type'] === 5) {
				$alias = self::decodeDnsName($response, $dataOffset, $dataEnd);
				if ($alias === null || $alias['offset'] > $dataEnd) {
					return null;
				}
				$aliasName = self::canonicalDnsName($alias['name']);
				if (isset($aliases[$ownerName]) && $aliases[$ownerName] !== $aliasName) {
					return null;
				}
				$aliases[$ownerName] = $aliasName;
			}
		}

		$addresses = [];
		$current = self::canonicalDnsName($host);
		$visited = [];
		for ($hop = 0; $hop <= self::DNS_MAX_CNAME_HOPS; $hop++) {
			if (isset($visited[$current])) {
				return null;
			}
			$visited[$current] = true;
			if (isset($addressesByName[$current])) {
				$addresses = array_merge($addresses, $addressesByName[$current]);
			}
			if (!isset($aliases[$current])) {
				break;
			}
			if ($hop === self::DNS_MAX_CNAME_HOPS) {
				return null;
			}
			$current = $aliases[$current];
		}
		return array_values(array_unique($addresses));
	}

	private static function canonicalDnsName(string $name): string {
		return strtolower(rtrim($name, '.')) . '.';
	}

	private static function decodeDnsName(string $packet, int $offset, int $limit): ?array {
		$packetLength = strlen($packet);
		$labels = [];
		$visitedPointers = [];
		$nextOffset = null;
		$cursor = $offset;
		for ($steps = 0; $steps < 128; $steps++) {
			$bound = $nextOffset === null ? min($limit, $packetLength) : $packetLength;
			if ($cursor >= $bound) {
				return null;
			}
			$length = ord($packet[$cursor]);
			if (($length & 0xc0) === 0xc0) {
				if ($cursor + 1 >= $bound) {
					return null;
				}
				$pointer = (($length & 0x3f) << 8) | ord($packet[$cursor + 1]);
				if ($pointer >= $packetLength || isset($visitedPointers[$pointer])) {
					return null;
				}
				$visitedPointers[$pointer] = true;
				if ($nextOffset === null) {
					$nextOffset = $cursor + 2;
				}
				$cursor = $pointer;
				continue;
			}
			if (($length & 0xc0) !== 0) {
				return null;
			}
			$cursor++;
			if ($length === 0) {
				return [
					'name' => $labels ? implode('.', $labels) . '.' : '.',
					'offset' => $nextOffset ?? $cursor,
				];
			}
			if ($length > 63 || $cursor + $length > $bound) {
				return null;
			}
			$labels[] = substr($packet, $cursor, $length);
			$cursor += $length;
		}
		return null;
	}

	private static function dnsExchange(string $server, string $query, float $timeout): ?string {
		return self::dnsExchangeOnPort($server, $query, $timeout, self::DNS_DEFAULT_PORT);
	}

	private static function dnsExchangeOnPort(string $server, string $query, float $timeout, int $port): ?string {
		if ($timeout <= 0 || $port < 1 || $port > 65535 || filter_var($server, FILTER_VALIDATE_IP) === false) {
			return null;
		}
		$deadline = self::monotonicTime() + $timeout;
		$address = strpos($server, ':') !== false ? '[' . $server . ']' : $server;
		$errno = 0;
		$error = '';
		$socket = @stream_socket_client(
			'udp://' . $address . ':' . $port,
			$errno,
			$error,
			$timeout,
			STREAM_CLIENT_CONNECT
		);
		if (!is_resource($socket)) {
			return null;
		}
		$response = null;
		if (self::setDnsStreamTimeout($socket, $deadline) && @fwrite($socket, $query) === strlen($query)) {
			$packet = @fread($socket, 65535);
			$streamStatus = stream_get_meta_data($socket);
			if (is_string($packet) && $packet !== '' && empty($streamStatus['timed_out'])) {
				$response = $packet;
			}
		}
		fclose($socket);
		if (!is_string($response)) {
			return null;
		}

		if (strlen($response) >= 4) {
			$flags = unpack('nflags', substr($response, 2, 2));
			if (((int)$flags['flags'] & 0x0200) !== 0) {
				$remaining = $deadline - self::monotonicTime();
				return $remaining > 0 ? self::dnsTcpExchange($server, $query, $deadline, $port) : null;
			}
		}
		return $response;
	}

	private static function dnsTcpExchange(string $server, string $query, float $deadline, int $port): ?string {
		$remaining = $deadline - self::monotonicTime();
		if ($remaining <= 0) {
			return null;
		}
		$address = strpos($server, ':') !== false ? '[' . $server . ']' : $server;
		$errno = 0;
		$error = '';
		$socket = @stream_socket_client(
			'tcp://' . $address . ':' . $port,
			$errno,
			$error,
			$remaining,
			STREAM_CLIENT_CONNECT
		);
		if (!is_resource($socket)) {
			return null;
		}

		$packet = null;
		if (self::dnsWriteAll($socket, pack('n', strlen($query)) . $query, $deadline)) {
			$lengthData = self::dnsReadExact($socket, 2, $deadline);
			if (is_string($lengthData)) {
				$length = (int)unpack('nlength', $lengthData)['length'];
				if ($length >= 12) {
					$packet = self::dnsReadExact($socket, $length, $deadline);
				}
			}
		}
		fclose($socket);
		return $packet;
	}

	private static function setDnsStreamTimeout($socket, float $deadline): bool {
		$remaining = $deadline - self::monotonicTime();
		if ($remaining <= 0) {
			return false;
		}
		$seconds = (int)$remaining;
		$microseconds = (int)ceil(($remaining - $seconds) * 1000000);
		if ($microseconds >= 1000000) {
			$seconds++;
			$microseconds = 0;
		}
		return @stream_set_timeout($socket, $seconds, $microseconds);
	}

	private static function dnsWriteAll($socket, string $data, float $deadline): bool {
		$offset = 0;
		$length = strlen($data);
		while ($offset < $length) {
			if (!self::setDnsStreamTimeout($socket, $deadline)) {
				return false;
			}
			$written = @fwrite($socket, substr($data, $offset));
			if (!is_int($written) || $written <= 0) {
				return false;
			}
			$offset += $written;
		}
		return true;
	}

	private static function dnsReadExact($socket, int $length, float $deadline): ?string {
		$data = '';
		while (strlen($data) < $length) {
			if (!self::setDnsStreamTimeout($socket, $deadline)) {
				return null;
			}
			$chunk = @fread($socket, $length - strlen($data));
			if (!is_string($chunk) || $chunk === '') {
				return null;
			}
			$data .= $chunk;
		}
		return $data;
	}

	private static function isAllowedAddress(string $address, bool $allowPrivate): bool {
		if (filter_var($address, FILTER_VALIDATE_IP) === false) {
			return false;
		}
		$packed = @inet_pton($address);
		if (is_string($packed) && strlen($packed) === 16
			&& substr($packed, 0, 10) === str_repeat("\0", 10)
			&& substr($packed, 10, 2) === "\xff\xff") {
			$mappedIpv4 = inet_ntop(substr($packed, 12, 4));
			return is_string($mappedIpv4) && self::isAllowedAddress($mappedIpv4, $allowPrivate);
		}
		$canonical = is_string($packed) ? strtolower((string)inet_ntop($packed)) : strtolower($address);
		if (in_array($canonical, ['100.100.100.200', '168.63.129.16', 'fd00:ec2::254'], true)) {
			return false;
		}
		if (self::isRfc1918OrUla($canonical)) {
			return $allowPrivate;
		}

		if (filter_var($canonical, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			foreach ([
				'0.0.0.0/8',
				'100.64.0.0/10',
				'127.0.0.0/8',
				'169.254.0.0/16',
				'192.0.0.0/24',
				'192.0.2.0/24',
				'192.88.99.0/24',
				'198.18.0.0/15',
				'198.51.100.0/24',
				'203.0.113.0/24',
				'224.0.0.0/4',
				'240.0.0.0/4',
			] as $range) {
				if (self::isInCidr($canonical, $range)) {
					return false;
				}
			}
			return filter_var($canonical, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
		}

		if (!self::isInCidr($canonical, '2000::/3')) {
			return false;
		}
		foreach (['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'] as $range) {
			if (self::isInCidr($canonical, $range)) {
				return false;
			}
		}
		return true;
	}

	private static function isRfc1918OrUla(string $address): bool {
		if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			return preg_match('/^(?:10\.|192\.168\.|172\.(?:1[6-9]|2[0-9]|3[01])\.)/', $address) === 1;
		}
		$packed = @inet_pton($address);
		if (!is_string($packed) || strlen($packed) !== 16) {
			return false;
		}
		$first = ord($packed[0]);
		return $first === 0xfc || $first === 0xfd;
	}

	private static function isInCidr(string $address, string $cidr): bool {
		$parts = explode('/', $cidr, 2);
		if (count($parts) !== 2) {
			return false;
		}
		$addressBytes = @inet_pton($address);
		$networkBytes = @inet_pton($parts[0]);
		$prefixLength = (int)$parts[1];
		if (!is_string($addressBytes) || !is_string($networkBytes)
			|| strlen($addressBytes) !== strlen($networkBytes)
			|| $prefixLength < 0 || $prefixLength > strlen($addressBytes) * 8) {
			return false;
		}

		$wholeBytes = intdiv($prefixLength, 8);
		if (substr($addressBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
			return false;
		}
		$remainingBits = $prefixLength % 8;
		if ($remainingBits === 0) {
			return true;
		}
		$mask = (0xff << (8 - $remainingBits)) & 0xff;
		return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
	}

	private static function allAddressesPrivate(array $addresses): bool {
		foreach ($addresses as $address) {
			if (!self::isRfc1918OrUla((string)$address)) {
				return false;
			}
		}
		return count($addresses) > 0;
	}

	private static function curlRequest(string $url, string $method, array $headers, string $body, array $parts, string $address): array {
		if (!function_exists('curl_init')) {
			return ['http_status' => 0, 'error' => 'The PHP cURL extension is unavailable.'];
		}
		if (filter_var($parts['host'], FILTER_VALIDATE_IP) === false && !defined('CURLOPT_RESOLVE')) {
			return ['http_status' => 0, 'error' => 'The HTTP transport cannot pin the validated destination address.'];
		}

		$handle = curl_init($url);
		if ($handle === false) {
			return ['http_status' => 0, 'error' => 'The HTTP request could not be initialized.'];
		}
		$options = self::curlOptions($method, $headers, $body, $parts, $address);
		if (!curl_setopt_array($handle, $options)) {
			curl_close($handle);
			return ['http_status' => 0, 'error' => 'The HTTP transport could not enforce the validated destination settings.'];
		}
		$result = curl_exec($handle);
		$status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$errorNumber = curl_errno($handle);
		curl_close($handle);
		if ($result === false) {
			return ['http_status' => $status, 'error' => $errorNumber === 28 ? 'The endpoint request timed out.' : 'The endpoint connection failed.'];
		}
		return ['http_status' => $status, 'error' => null];
	}

	private static function curlOptions(string $method, array $headers, string $body, array $parts, string $address): array {
		$headerLines = [];
		foreach ($headers as $name => $value) {
			$headerLines[] = $name . ': ' . $value;
		}
		$resolveAddress = strpos($address, ':') !== false ? '[' . $address . ']' : $address;
		$protocols = CURLPROTO_HTTPS | ($parts['scheme'] === 'http' ? CURLPROTO_HTTP : 0);
		$options = [
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_HEADER => false,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
			CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_PROTOCOLS => $protocols,
			CURLOPT_REDIR_PROTOCOLS => $protocols,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_PROXY => '',
			CURLOPT_NOPROXY => '*',
			CURLOPT_NOSIGNAL => true,
			CURLOPT_WRITEFUNCTION => static function ($handle, string $data): int {
				return strlen($data);
			},
			CURLOPT_HTTPHEADER => $headerLines,
		];
		if (filter_var($parts['host'], FILTER_VALIDATE_IP) === false) {
			$options[CURLOPT_RESOLVE] = [$parts['host'] . ':' . $parts['port'] . ':' . $resolveAddress];
		}
		if ($method === 'POST') {
			$options[CURLOPT_POST] = true;
			$options[CURLOPT_POSTFIELDS] = $body;
		} else {
			$options[CURLOPT_HTTPGET] = true;
		}
		return $options;
	}

	private static function monotonicTime(): float {
		return hrtime(true) / 1000000000;
	}
}
