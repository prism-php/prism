<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

require_once __DIR__.'/../vendor/autoload.php';

$apiKey = getenv('GEMINI_API_KEY') ?: null;

if (! $apiKey) {
    echo "Error: GEMINI_API_KEY not found in environment\n";
    exit(1);
}

$baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
$client = new Client;

echo "Creating FileSearchStore...\n";

try {
    $response = $client->post("{$baseUrl}/fileSearchStores?key={$apiKey}", [
        'json' => ['displayName' => 'Prism Test Store'],
    ]);

    $storeData = json_decode($response->getBody()->getContents(), true);
    $storeName = $storeData['name'] ?? null;

    if (! $storeName) {
        echo "Error: No store name in response\n";
        exit(1);
    }

    echo "Created FileSearchStore: {$storeName}\n\n";
} catch (GuzzleException $e) {
    echo "Error creating FileSearchStore: {$e->getMessage()}\n";
    exit(1);
}

echo "Uploading test PDF...\n";

$pdfPath = __DIR__.'/Fixtures/test-pdf.pdf';

if (! file_exists($pdfPath)) {
    echo "Error: PDF file not found at {$pdfPath}\n";
    exit(1);
}

try {
    $metadata = json_encode([
        'displayName' => 'Test PDF Document',
        'mimeType' => 'application/pdf',
    ]);

    $uploadResponse = $client->post("https://generativelanguage.googleapis.com/upload/v1beta/{$storeName}:uploadToFileSearchStore?key={$apiKey}", [
        'headers' => [
            'X-Goog-Upload-Protocol' => 'multipart',
        ],
        'multipart' => [
            [
                'name' => 'metadata',
                'contents' => $metadata,
                'headers' => ['Content-Type' => 'application/json; charset=UTF-8'],
            ],
            [
                'name' => 'file',
                'contents' => fopen($pdfPath, 'r'),
                'headers' => ['Content-Type' => 'application/pdf'],
            ],
        ],
    ]);

    $uploadBody = $uploadResponse->getBody()->getContents();

    echo "Upload HTTP status: {$uploadResponse->getStatusCode()}\n";
    echo "Upload response body:\n{$uploadBody}\n\n";

    $uploadData = json_decode($uploadBody, true);

    echo "Upload response (parsed):\n";
    print_r($uploadData);
    echo "\n";

    $operationName = $uploadData['name'] ?? null;

    if (! $operationName) {
        echo "Upload succeeded but no operation name returned\n";
        echo "Store created successfully: {$storeName}\n";
        echo "\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "✅ SUCCESS\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "FileSearchStore Name: {$storeName}\n";
        echo "\nUse this in your test:\n";
        echo "'{$storeName}'\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        exit(0);
    }

    echo "Upload initiated successfully\n";
    echo "Operation: {$operationName}\n\n";
} catch (GuzzleException $e) {
    echo "Error uploading PDF: {$e->getMessage()}\n";
    exit(1);
}

echo "Waiting for document processing...\n";

$maxAttempts = 30;
$attempt = 0;

while ($attempt < $maxAttempts) {
    sleep(2);
    $attempt++;

    try {
        $operationResponse = $client->get("{$baseUrl}/{$operationName}?key={$apiKey}");
        $operationData = json_decode($operationResponse->getBody()->getContents(), true);

        if (isset($operationData['done']) && $operationData['done'] === true) {
            if (isset($operationData['error'])) {
                echo "Error processing document:\n";
                print_r($operationData['error']);
                exit(1);
            }

            echo "Document processed successfully!\n\n";
            break;
        }

        echo '.';
    } catch (GuzzleException $e) {
        echo "Error checking operation status: {$e->getMessage()}\n";
        exit(1);
    }
}

if ($attempt >= $maxAttempts) {
    echo "\nTimeout waiting for document processing\n";
    exit(1);
}

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✅ SUCCESS\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "FileSearchStore Name: {$storeName}\n";
echo "\nUse this in your test:\n";
echo "'{$storeName}'\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
