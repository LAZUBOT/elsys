<?php
require_once __DIR__ . '/firebase-sync.php';

$serviceAccountPath = __DIR__ . '/service-account.json';
$serviceAccountJson = getenv('FIREBASE_SERVICE_ACCOUNT_JSON');

$config = [
    'projectId' => 'testftth-43e82',
];

if (!empty($serviceAccountJson)) {
    $config['serviceAccount'] = json_decode($serviceAccountJson, true);
} elseif (file_exists($serviceAccountPath)) {
    $config['serviceAccountPath'] = $serviceAccountPath;
}

try {
    $sync = new FirebaseSync($config);
    echo "FirebaseSync class loaded successfully.\n";
    echo "Initial state:\n";
    print_r($sync->getState());

    if ($sync->initFirebase()) {
        echo "Firebase initialized successfully.\n";
    } else {
        echo "Firebase initialization failed or missing credentials.\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
