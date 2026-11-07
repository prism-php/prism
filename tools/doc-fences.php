<?php

declare(strict_types=1);

/**
 * Extract every fenced PHP block in docs/ to a file PHPStan can analyse.
 *
 * WHY. The documentation ships inside this package, so a snippet that cannot run
 * is shipped breakage. tests/Documentation/DocumentedApiTest.php already resolves
 * every STATIC call on every `use Prism\...` import -- it found three live
 * defects -- but it cannot see an instance method, an argument count or a wrong
 * receiver, because that needs type inference through a fluent chain. PHPStan
 * already does exactly that, and understands @method annotations, Pest and
 * Collection natively, which a bespoke matcher would need a hand-maintained
 * allow-list to fake.
 *
 * WHAT THIS DOES NOT SOLVE, stated because it is the whole remaining cost: many
 * fences reference variables introduced only in surrounding prose ($user, $tools,
 * $schema). Those produce undefinedVariable errors that are EXPECTED and are
 * counted separately rather than silenced -- silencing them would also hide a
 * genuine typo in a variable name.
 *
 * Usage:
 *   php tools/doc-fences.php extract <outDir>    # write one .php per fence
 *   php tools/doc-fences.php report <outDir>     # partition a PHPStan json run
 */
$command = $argv[1] ?? '';
$outDir = $argv[2] ?? '';

if ($command === '' || $outDir === '') {
    fwrite(STDERR, "usage: doc-fences.php extract|report <outDir>\n");
    exit(2);
}

$docsRoot = __DIR__.'/../docs';

/** @return list<string> */
function markdownFiles(string $dir): array
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            // .vitepress holds the BUILT copy; analysing it double-reports every
            // finding and anchors them to paths nobody edits.
            fn (SplFileInfo $f): bool => ! in_array($f->getFilename(), ['.vitepress', 'node_modules'], true),
        )
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'md') {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

if ($command === 'extract') {
    if (! is_dir($outDir) && ! mkdir($outDir, 0o777, true) && ! is_dir($outDir)) {
        fwrite(STDERR, "cannot create {$outDir}\n");
        exit(1);
    }
    foreach (glob($outDir.'/*.php') ?: [] as $stale) {
        unlink($stale);
    }

    $fences = 0;
    $skipped = 0;
    /** @var array<string, string> $origins */
    $origins = [];

    foreach (markdownFiles($docsRoot) as $path) {
        $relative = str_replace('\\', '/', substr((string) realpath($path), strlen((string) realpath($docsRoot)) + 1));
        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];
        $open = false;
        $buffer = [];
        $startLine = 0;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if (! $open && preg_match('/^```php\b/', $trimmed) === 1) {
                $open = true;
                $buffer = [];
                $startLine = $index + 2;

                continue;
            }
            if ($open && $trimmed === '```') {
                $open = false;
                $body = implode("\n", $buffer);

                // A fence that already declares a namespace or is pure prose
                // output is not a snippet somebody pastes; skip rather than
                // manufacture a file that fails for the wrong reason.
                if (trim($body) === '') {
                    $skipped++;

                    continue;
                }

                $name = preg_replace('/[^a-z0-9]+/i', '_', $relative.'_'.$startLine).'.php';

                // A fence that opens PHP anywhere must not be given a second
                // open tag. Nine do, and not always on the first line -- one
                // leads with a `// Job Class` label and opens PHP after it,
                // which is valid PHP (text before the tag is just output) and
                // broke only because I prepended a tag to it.
                //
                // The fence's origin goes in a SIDECAR MAP rather than a comment
                // in the file: a comment has to live inside the tags, which is
                // exactly the juggling that caused the bug, and reading it back
                // out of line 3 was fragile besides.
                $contents = str_contains($body, '<?php') ? $body : "<?php\n\n".$body;

                file_put_contents($outDir.'/'.$name, $contents."\n");
                $origins[$name] = "{$relative}:{$startLine}";
                $fences++;

                continue;
            }
            if ($open) {
                $buffer[] = $line;
            }
        }
    }

    // Separate the fences that are not standalone programs, LOUDLY.
    //
    // A fence may legitimately be a fragment: a config/prism.php array excerpt, a
    // menu of four alternative constructors with no statement terminators, two
    // `->using(...)` forms shown without a receiver, a labelled file preceded by
    // a comment. Those are correct documentation and will never parse alone.
    //
    // They have to be moved aside rather than left in place, because PHPStan
    // stops reporting semantic findings entirely while any file fails to parse
    // -- "Result is incomplete because of severe errors". One unparseable
    // fragment therefore hides every real finding in the other 400+, which is
    // this estate's favourite failure wearing a new hat.
    $fragmentDir = $outDir.'/fragments';
    if (! is_dir($fragmentDir) && ! mkdir($fragmentDir, 0o777, true) && ! is_dir($fragmentDir)) {
        fwrite(STDERR, "cannot create {$fragmentDir}\n");
        exit(1);
    }
    foreach (glob($fragmentDir.'/*.php') ?: [] as $stale) {
        unlink($stale);
    }

    $fragments = [];
    foreach (array_keys($origins) as $name) {
        $path = $outDir.'/'.$name;
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1', $output, $status);
        if ($status !== 0) {
            rename($path, $fragmentDir.'/'.$name);
            $fragments[$name] = $origins[$name];
            unset($origins[$name]);
        }
    }

    file_put_contents($outDir.'/origins.json', json_encode($origins, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    file_put_contents($outDir.'/fragments.json', json_encode($fragments, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    echo 'Extracted '.count($origins)." analysable fence(s).\n";
    echo 'Set aside '.count($fragments)." fence(s) that are not standalone programs (see fragments.json).\n";
    echo "Total fences: {$fences}, empty skipped: {$skipped}.\n";

    // VACUITY GUARDS. Credit to the Fancy agent for the rule behind these:
    // comparing against a measured source rather than a literal is necessary and
    // NOT sufficient, because "a source-comparing guard with no floor under it
    // degrades into a literal the moment its source goes quiet". If a docs
    // restructure or a regex change dropped extraction from 467 fences to 12,
    // PHPStan would analyse 12, find nothing, and the whole chain would report
    // clean. Nothing downstream can tell an empty finding list from an empty
    // input.
    //
    // Two checks, and they fail for different reasons on purpose.

    // 1. CONSERVATION, derived from the source rather than asserted: every
    //    ```php fence in docs/ must end up accounted for, either analysable or
    //    set aside. A fence silently dropped by the extractor is the failure this
    //    catches, and it needs no magic number.
    $declared = 0;
    foreach (markdownFiles($docsRoot) as $path) {
        // Leading whitespace allowed, because the extractor trims each line
        // before matching and so finds fences indented inside a list item.
        // Counting them differently here reported 464 against 467 on the first
        // run -- the guard was wrong, not the extractor, which is the failure
        // mode of every conservation check: it is only as right as its own
        // notion of the denominator.
        $declared += preg_match_all('/^\s*```php\b/m', (string) file_get_contents($path));
    }
    $accounted = count($origins) + count($fragments) + $skipped;
    if ($accounted !== $declared) {
        fwrite(STDERR, "VACUITY: docs/ declares {$declared} php fence(s); {$accounted} accounted for. The extractor is dropping fences.\n");
        exit(1);
    }

    // 2. A FLOOR, which is deliberately a literal -- but a literal that encodes
    //    a minimum plausible SCALE, never an answer. `toStartWith('^4')` went
    //    stale because it encoded the answer and reality moved past it; this
    //    fires only if reality collapses, which is the one case the conservation
    //    check above cannot see (zero declared equals zero accounted).
    $floor = 200;
    if ($declared < $floor) {
        fwrite(STDERR, "VACUITY: only {$declared} php fence(s) found in docs/, below the floor of {$floor}. Refusing to report a clean run on an input this small.\n");
        exit(1);
    }

    exit(0);
}

if ($command === 'report') {
    $raw = stream_get_contents(STDIN);
    $decoded = json_decode($raw, true);

    if (! is_array($decoded) || ! isset($decoded['files'])) {
        fwrite(STDERR, "report: expected PHPStan --error-format=json on stdin\n");
        exit(2);
    }

    // Expected in a documentation snippet: a variable the prose introduced, and
    // the things that follow from not knowing its type.
    $expectedPatterns = [
        '/^Variable \$\w+ might not be defined/',
        '/^Undefined variable/',
    ];

    // A fence is analysed in isolation, so a page that declares `use` once and
    // omits it in later examples loses that context. Those findings are about
    // the extraction, not the documentation, and there are hundreds of them --
    // left unsorted they bury the handful that matter.
    $contextLostPattern = '/unknown class|not found\.$|should return|should always throw|does not extend|unknown constant/';

    // THE SIGNAL. A member missing from a class PHPStan actually RESOLVED: the
    // namespace separator in the class name is what separates a real Prism type
    // from an illustrative class the doc invented for the example.
    $memberPattern = '/undefined (?:method|property|constant) [A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+::/';

    $originsPath = $outDir.'/origins.json';
    /** @var array<string, string> $origins */
    $origins = is_file($originsPath)
        ? (array) json_decode((string) file_get_contents($originsPath), true, 512, JSON_THROW_ON_ERROR)
        : [];

    // The same floor as extract, for the same reason: this command reports
    // "findings worth reading: 0" on an empty analysis just as happily as on a
    // clean one, and a reader cannot tell them apart. Refuse rather than
    // present a number that cannot mean what it looks like.
    if (count($origins) < 200) {
        fwrite(STDERR, 'VACUITY: origins.json lists '.count($origins)." analysable fence(s), below the floor of 200. Run extract first, or find out why it shrank.\n");
        exit(1);
    }

    $expected = 0;
    $contextLost = 0;
    $real = [];
    $syntax = [];

    /** @var array<string, array{messages: list<array{message: string, line: int}>}> $files */
    $files = $decoded['files'];
    foreach ($files as $file => $payload) {
        $fence = $origins[basename($file)] ?? basename($file);
        foreach ($payload['messages'] ?? [] as $message) {
            $text = (string) ($message['message'] ?? '');
            $isExpected = false;
            foreach ($expectedPatterns as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $isExpected = true;
                    break;
                }
            }
            if ($isExpected) {
                $expected++;

                continue;
            }

            // A fence that does not parse is a fence PHPStan never analysed, and
            // PHPStan stops reporting semantic findings entirely while any
            // remain -- "Result is incomplete because of severe errors". So
            // these are not findings about the documentation, they are the
            // reason there are no findings yet. Counted apart so a reader cannot
            // mistake the one for the other.
            if (preg_match('/^(Syntax error|A trailing comma is not allowed|Cannot use empty array elements|Namespace declaration statement has to be)/', $text) === 1) {
                $syntax[] = "{$fence}: {$text}";

                continue;
            }

            // Order matters: the member check runs BEFORE the context-lost
            // filter, because "undefined method Foo\Bar::baz()" also matches
            // nothing in that filter today but would if either pattern grew.
            // The signal must never be filtered as noise.
            if (preg_match($memberPattern, $text) === 1) {
                $real[] = "{$fence}: {$text}";

                continue;
            }

            if (preg_match($contextLostPattern, $text) === 1) {
                $contextLost++;

                continue;
            }

            $real[] = "{$fence}: {$text}";
        }
    }

    sort($real);
    sort($syntax);
    echo 'Fences that do not parse (blocking all analysis): '.count($syntax)."\n";
    echo "Undefined-variable findings (expected in snippets): {$expected}\n";
    echo "Context lost by per-fence extraction (missing use, doc-local class): {$contextLost}\n";
    echo 'FINDINGS WORTH READING: '.count($real)."\n\n";

    if ($syntax !== []) {
        echo "NOT PARSED -- no semantic analysis runs until these are resolved:\n";
        foreach ($syntax as $line) {
            echo "  {$line}\n";
        }
        echo "\n";
    }

    foreach ($real as $line) {
        echo "  {$line}\n";
    }

    exit(count($real) > 0 ? 1 : 0);
}

fwrite(STDERR, "unknown command: {$command}\n");
exit(2);
