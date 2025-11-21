<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../vendor/autoload.php';

$apiKey = getenv('GEMINI_API_KEY') ?: null;

if (! $apiKey) {
    echo "Error: GEMINI_API_KEY not found in environment\n";
    exit(1);
}

$baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

echo "Creating FileSearchStore...\n";

$response = Http::post("{$baseUrl}/fileSearchStores?key={$apiKey}", [
    'displayName' => 'Prism Test Store',
]);

if (! $response->successful()) {
    echo "Error creating FileSearchStore (HTTP {$response->status()}):\n{$response->body()}\n";
    exit(1);
}

$storeName = $response->json('name');

if (! $storeName) {
    echo "Error: No store name in response:\n{$response->body()}\n";
    exit(1);
}

echo "Created FileSearchStore: {$storeName}\n\n";

echo "Uploading test PDF...\n";

$pdfPath = __DIR__.'/Fixtures/test-pdf.pdf';

if (! file_exists($pdfPath)) {
    echo "Error: PDF file not found at {$pdfPath}\n";
    exit(1);
}

$uploadResponse = Http::attach(
    'file',
    file_get_contents($pdfPath),
    'test-pdf.pdf'
)->post("{$baseUrl}/{$storeName}:uploadToFileSearchStore?key={$apiKey}", [
    'displayName' => 'Test PDF Document',
    'mimeType' => 'application/pdf',
]);

if (! $uploadResponse->successful()) {
    echo "Error uploading PDF (HTTP {$uploadResponse->status()}):\n{$uploadResponse->body()}\n";
    exit(1);
}

$operationName = $uploadResponse->json('name');

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

echo "Waiting for document processing...\n";

$maxAttempts = 30;
$attempt = 0;

while ($attempt < $maxAttempts) {
    sleep(2);
    $attempt++;

    $operationResponse = Http::get("{$baseUrl}/{$operationName}?key={$apiKey}");
    $operationData = $operationResponse->json();

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
