<?php

declare(strict_types=1);

// Regenerate the PHP observations without discarding measured port observations.
// php dev/generate-embedding-corpus.php --write
// Without --write, print the candidate for review; never redirect over its input.
require __DIR__.'/../vendor/autoload.php';

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Support\EmbeddingDecoder;

if (count($argv) > 2 || (isset($argv[1]) && $argv[1] !== '--write')) {
    throw new InvalidArgumentException('Usage: php dev/generate-embedding-corpus.php [--write]');
}

$fixture = __DIR__.'/../tests/Fixtures/embedding-decoding.json';
$corpus = json_decode(file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR);
foreach ($corpus['cases'] as &$case) {
    $previous = json_encode($case['expected']['php'], JSON_THROW_ON_ERROR);
    try {
        $result = ['embedding' => EmbeddingDecoder::decode($case['embedding'])->embedding];
    } catch (PrismException $exception) {
        $result = ['exception' => $exception::class];
    }
    $case['expected']['php'] = $result;
    if (json_encode($result, JSON_THROW_ON_ERROR) !== $previous) {
        // PHP cannot establish new port agreement without executing those ports.
        $case['agrees'] = false;
    }
}
unset($case);

// The canonical JSON writer uses two-space indentation. Keep the mirror exact.
$json = json_encode($corpus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$json = preg_replace_callback('/^(?: {4})+/m', fn (array $matches): string => str_repeat(' ', intdiv(strlen($matches[0]), 2)), $json)."\n";
if (($argv[1] ?? null) === '--write') {
    if (file_put_contents($fixture, $json) === false) {
        throw new RuntimeException('Could not write the embedding corpus.');
    }
} else {
    echo $json;
}
