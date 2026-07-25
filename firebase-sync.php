<?php
/**
 * Firebase Firestore sync helper in PHP.
 *
 * This class is a PHP port of the client-side firebase-sync.js module.
 * It uses Google service account credentials to authenticate with Firestore.
 */

class FirebaseSync
{
    private array $config;
    private ?string $accessToken = null;
    private bool $firebaseReady = false;
    private ?string $currentAgentId = null;
    private string $currentAgentName = '';
    private ?string $currentZoneId = null;
    private bool $cloudWriteEnabled = true;
    private array $userNotes = [];

    public function __construct(array $config)
    {
        $this->config = $config;

        if (empty($this->config['projectId'])) {
            throw new InvalidArgumentException('Firebase projectId is required.');
        }
    }

    public function setContext(array $context): void
    {
        $this->currentAgentId = $context['agentId'] ?? null;
        $this->currentAgentName = $context['agentName'] ?? '';
        $this->currentZoneId = $context['zoneId'] ?? null;
    }

    public function getState(): array
    {
        return [
            'firebaseReady' => $this->firebaseReady,
            'currentAgentId' => $this->currentAgentId,
            'currentAgentName' => $this->currentAgentName,
            'currentZoneId' => $this->currentZoneId,
            'userNotes' => $this->userNotes,
        ];
    }

    public function setCloudWriteState(bool $enabled): void
    {
        $this->cloudWriteEnabled = $enabled;
    }

    public function initFirebase(): bool
    {
        if ($this->firebaseReady && $this->accessToken !== null) {
            return true;
        }

        $serviceAccount = $this->loadServiceAccount();
        if (!$serviceAccount) {
            return false;
        }

        $this->accessToken = $this->fetchAccessToken($serviceAccount);
        $this->firebaseReady = $this->accessToken !== null;

        return $this->firebaseReady;
    }

    public function loadCustomersFromFirestore(string $zoneId): ?array
    {
        if (!$this->firebaseReady || !$this->accessToken || !$this->currentAgentId || $zoneId === '') {
            return null;
        }

        $payload = [
            'structuredQuery' => [
                'from' => [
                    ['collectionId' => 'customers'],
                ],
                'where' => [
                    'compositeFilter' => [
                        'op' => 'AND',
                        'filters' => [
                            [
                                'fieldFilter' => [
                                    'field' => ['fieldPath' => 'agentId'],
                                    'op' => 'EQUAL',
                                    'value' => ['stringValue' => (string)$this->currentAgentId],
                                ],
                            ],
                            [
                                'fieldFilter' => [
                                    'field' => ['fieldPath' => 'zoneId'],
                                    'op' => 'EQUAL',
                                    'value' => ['stringValue' => $zoneId],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->requestFirestore('/documents:runQuery', 'POST', $payload);
        if ($result === null) {
            return null;
        }

        $items = [];
        foreach ($result as $entry) {
            if (!isset($entry['document'])) {
                continue;
            }
            $items[] = $this->decodeFirestoreDocument($entry['document']);
        }

        return $items;
    }

    public function syncCustomerRecordsToFirestore(array $customers, string $zoneId): bool
    {
        if (!$this->firebaseReady || !$this->accessToken || !$this->currentAgentId || empty($customers)) {
            return false;
        }

        foreach ($customers as $item) {
            if (empty($item['id'])) {
                continue;
            }

            $payload = [
                'fields' => $this->buildFirestoreFields([
                    'customerId' => (string)$item['id'],
                    'agentId' => (string)$this->currentAgentId,
                    'zoneId' => $zoneId !== '' ? (string)$zoneId : '',
                    'name' => $item['name'] ?? '',
                    'mobile' => $item['mobile'] ?? '',
                    'username' => $item['username'] ?? '',
                    'serial' => $item['serial'] ?? '',
                    'fdt' => $item['fdt'] ?? '',
                    'fat' => $item['fat'] ?? '',
                    'expires' => $item['expires'] ?? '',
                    'remainingDaysText' => $item['remainingDaysText'] ?? '',
                    'category' => $item['category'] ?? '',
                    'startedAt' => $item['startedAt'] ?? '',
                    'status' => $item['status'] ?? '',
                    'deviceId' => $item['deviceId'] ?? '',
                    'devicePassword' => $item['devicePassword'] ?? '',
                    'ontVendor' => $item['ontVendor'] ?? '',
                    'rxPower' => $item['rxPower'] ?? '',
                    'rxStatus' => $item['rxStatus'] ?? '',
                    'note' => $item['note'] ?? '',
                    'lastSyncedAt' => gmdate('c'),
                    'updatedAt' => gmdate('c'),
                    'source' => 'php-backend',
                ]),
            ];

            $path = sprintf('/documents/customers/%s', rawurlencode((string)$item['id']));
            $response = $this->requestFirestore($path, 'PATCH', $payload);
            if ($response === null) {
                return false;
            }
        }

        return true;
    }

    public function saveNoteToCloud(string $customerId, string $noteText): bool
    {
        if (!$this->firebaseReady || !$this->accessToken || !$this->currentAgentId || $customerId === '') {
            return false;
        }

        $this->userNotes[$customerId] = $noteText;
        $timestamp = gmdate('c');

        $notePayload = [
            'fields' => $this->buildFirestoreFields([
                'note' => $noteText,
                'customerId' => $customerId,
                'agentId' => (string)$this->currentAgentId,
                'updatedAt' => $timestamp,
                'source' => 'php-backend',
            ]),
        ];

        $customerPayload = [
            'fields' => $this->buildFirestoreFields([
                'note' => $noteText,
                'customerId' => $customerId,
                'agentId' => (string)$this->currentAgentId,
                'updatedAt' => $timestamp,
                'source' => 'php-backend',
            ]),
        ];

        $notePath = sprintf('/documents/notes/%s/customerNotes/%s', rawurlencode((string)$this->currentAgentId), rawurlencode($customerId));
        $customerPath = sprintf('/documents/customers/%s', rawurlencode($customerId));

        $noteResponse = $this->requestFirestore($notePath, 'PATCH', $notePayload);
        $customerResponse = $this->requestFirestore($customerPath, 'PATCH', $customerPayload);

        return $noteResponse !== null && $customerResponse !== null;
    }

    public function setUserNotes(array $notes): void
    {
        $this->userNotes = array_merge($this->userNotes, $notes);
    }

    public function getUserNotes(): array
    {
        return $this->userNotes;
    }

    private function loadServiceAccount(): ?array
    {
        if (!empty($this->config['serviceAccount'])) {
            return $this->config['serviceAccount'];
        }

        if (!empty($this->config['serviceAccountPath']) && file_exists($this->config['serviceAccountPath'])) {
            $content = file_get_contents($this->config['serviceAccountPath']);
            if ($content === false) {
                return null;
            }
            $decoded = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
            return $decoded;
        }

        return null;
    }

    private function fetchAccessToken(array $serviceAccount): ?string
    {
        if (empty($serviceAccount['client_email']) || empty($serviceAccount['private_key'])) {
            return null;
        }

        $tokenUri = $this->config['tokenUri'] ?? 'https://oauth2.googleapis.com/token';
        $jwt = $this->buildJwtAssertion($serviceAccount['client_email'], $serviceAccount['private_key'], $tokenUri);

        $response = $this->sendRequest(
            'POST',
            $tokenUri,
            'grant_type=' . rawurlencode('urn:ietf:params:oauth:grant-type:jwt-bearer') . '&assertion=' . rawurlencode($jwt),
            ['Content-Type: application/x-www-form-urlencoded'],
            false
        );

        if ($response['status'] !== 200 || empty($response['body']['access_token'])) {
            return null;
        }

        return $response['body']['access_token'];
    }

    private function buildJwtAssertion(string $clientEmail, string $privateKey, string $tokenUri): string
    {
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/datastore',
            'aud' => $tokenUri,
            'exp' => $now + 3600,
            'iat' => $now,
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header)),
            $this->base64UrlEncode(json_encode($claims)),
        ];

        $signatureInput = implode('.', $segments);
        $signature = '';
        $privateKeyResource = openssl_pkey_get_private($privateKey);
        if ($privateKeyResource === false || !openssl_sign($signatureInput, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign JWT assertion with provided service account private key.');
        }

        openssl_free_key($privateKeyResource);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function requestFirestore(string $path, string $method = 'GET', ?array $payload = null): ?array
    {
        if (!$this->accessToken) {
            return null;
        }

        $url = sprintf(
            'https://firestore.googleapis.com/v1/projects/%s/databases/(default)%s',
            rawurlencode($this->config['projectId']),
            $path
        );

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
        ];

        $response = $this->sendRequest($method, $url, $payload, $headers, true);
        return $response['status'] >= 200 && $response['status'] < 300 ? $response['body'] : null;
    }

    private function sendRequest(string $method, string $url, $payload = null, array $headers = [], bool $json = true): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($payload !== null) {
            if ($json) {
                $body = json_encode($payload);
            } else {
                $body = is_string($payload) ? $payload : http_build_query($payload);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);

        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('cURL error when sending request: ' . $error);
        }

        $body = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $body = ['raw' => $raw];
        }

        return [
            'status' => $status,
            'body' => $body,
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function buildFirestoreFields(array $data): array
    {
        $fields = [];
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $fields[$key] = ['booleanValue' => $value];
            } elseif (is_int($value)) {
                $fields[$key] = ['integerValue' => (string)$value];
            } elseif (is_float($value)) {
                $fields[$key] = ['doubleValue' => $value];
            } elseif ($value === null) {
                $fields[$key] = ['nullValue' => null];
            } elseif (is_array($value)) {
                $fields[$key] = ['mapValue' => ['fields' => $this->buildFirestoreFields($value)]];
            } else {
                $fields[$key] = ['stringValue' => (string)$value];
            }
        }

        return $fields;
    }

    private function decodeFirestoreDocument(array $document): array
    {
        $output = [];
        $output['id'] = basename($document['name'] ?? '');
        $fields = $document['fields'] ?? [];

        foreach ($fields as $key => $value) {
            $output[$key] = $this->decodeFirestoreValue($value);
        }

        return $output;
    }

    private function decodeFirestoreValue(array $fieldValue)
    {
        if (isset($fieldValue['stringValue'])) {
            return $fieldValue['stringValue'];
        }

        if (isset($fieldValue['integerValue'])) {
            return (int)$fieldValue['integerValue'];
        }

        if (isset($fieldValue['doubleValue'])) {
            return (float)$fieldValue['doubleValue'];
        }

        if (isset($fieldValue['booleanValue'])) {
            return (bool)$fieldValue['booleanValue'];
        }

        if (isset($fieldValue['timestampValue'])) {
            return $fieldValue['timestampValue'];
        }

        if (isset($fieldValue['mapValue'])) {
            $map = [];
            $innerFields = $fieldValue['mapValue']['fields'] ?? [];
            foreach ($innerFields as $innerKey => $innerValue) {
                $map[$innerKey] = $this->decodeFirestoreValue($innerValue);
            }
            return $map;
        }

        if (isset($fieldValue['arrayValue'])) {
            $values = $fieldValue['arrayValue']['values'] ?? [];
            return array_map([$this, 'decodeFirestoreValue'], $values);
        }

        if (isset($fieldValue['nullValue'])) {
            return null;
        }

        return null;
    }
}
