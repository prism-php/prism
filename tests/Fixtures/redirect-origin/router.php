<?php

declare(strict_types=1);

/*
 * Router for the loopback servers used by tests/Http/ProviderRedirectOriginTest.php.
 *
 * ROLE=endpoint  answers /r/{status}/{target}/{rest} with that redirect status
 *                and a Location on the named target; answers /landed/* with 200.
 * ROLE=recorder  answers everything with 200.
 *
 * Every request either role receives is appended to LOG as one JSON line.
 */

$role = (string) getenv('ROLE');
$log = (string) getenv('LOG');

$uri = $_SERVER['REQUEST_URI'] ?? '/';

file_put_contents($log, json_encode([
    'role' => $role,
    'host' => $_SERVER['HTTP_HOST'] ?? '',
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $uri,
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
    'body' => file_get_contents('php://input'),
]).PHP_EOL, FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');

if ($role !== 'endpoint') {
    echo '{}';

    return true;
}

$path = (string) parse_url((string) $uri, PHP_URL_PATH);
$query = (string) parse_url((string) $uri, PHP_URL_QUERY);

if (preg_match('#^/r/(30[1-8])/([a-z]+)(/.*)?$#', $path, $match) === 1) {
    $targets = [
        'host' => (string) getenv('TARGET_HOST'),
        'port' => (string) getenv('TARGET_PORT'),
        'same' => (string) getenv('TARGET_SAME'),
        'scheme' => (string) getenv('TARGET_SCHEME'),
    ];

    $location = ($targets[$match[2]] ?? $targets['host']).'/landed'.($match[3] ?? '/');

    if ($query !== '') {
        $location .= '?'.$query;
    }

    http_response_code((int) $match[1]);
    header('Location: '.$location);
    echo '{}';

    return true;
}

if (str_starts_with($path, '/landed')) {
    echo json_encode(['ok' => true, 'method' => $_SERVER['REQUEST_METHOD'] ?? '']);

    return true;
}

http_response_code(404);
echo '{}';

return true;
