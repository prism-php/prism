<?php

declare(strict_types=1);

// Run after changing the decoder; prints reference-derived goldens for review.
// php dev/generate-embedding-corpus.php > tests/Fixtures/embedding-decoding.json
require __DIR__.'/../vendor/autoload.php';

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Support\EmbeddingDecoder;

$inputs = [
    ['float array control', [1, -2.5, 0.5, 0], 'An existing numeric array must remain a vector.'],
    ['little endian float32 control', base64_encode(hex2bin('0000803f000020c00000003f00000000')), 'Fixed IEEE-754 bytes discriminate little endian decoding from host-dependent or big endian decoding.'],
    ['malformed base64', 'not!base64', 'Reject invalid alphabet bytes rather than discard them.'],
    ['partial float', base64_encode(hex2bin('0000803f00')), 'Reject trailing bytes rather than silently shorten the vector.'],
    ['NaN', base64_encode(hex2bin('0000c07f')), 'Reject non-finite values before they poison similarity calculations.'],
    ['infinity', base64_encode(hex2bin('0000807f')), 'Reject positive infinity rather than return a numeric-looking vector.'],
    ['empty encoding', '', 'Reject an empty encoded vector.'],
    ['invalid padding bits', 'AAAAAB==', 'Reject noncanonical padding bits that strict base64 decoding alone accepts.'],
    ['wrong response type', null, 'Reject an invalid response type as a PrismException, never a TypeError.'],
];
$cases = [];
foreach ($inputs as $index => [$title, $embedding, $notes]) {
    try {
        $result = ['embedding' => EmbeddingDecoder::decode($embedding)->embedding];
    } catch (PrismException $exception) {
        $result = ['exception' => $exception::class];
    }
    $cases[] = [
        'id' => sprintf('emb-%04d', $index + 1),
        'title' => $title,
        'since' => '0.1.1',
        'embedding' => $embedding,
        'expected' => ['php' => $result, 'ts' => null, 'py' => null],
        'agrees' => false,
        'notes' => $notes,
    ];
}
echo json_encode([
    'suite' => 'embedding-decoding',
    'schema_version' => 1,
    'corpus_version' => '0.1.1',
    'generated_by' => 'prism:dev/generate-embedding-corpus.php executes EmbeddingDecoder; prism:tests/Providers/Base64EmbeddingsTest.php verifies both provider handlers. TS/Python are unverified, not asserted to agree.',
    'cases' => $cases,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
