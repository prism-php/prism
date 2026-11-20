<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;

/**
 * The docs are published inside this package, so a snippet that cannot run is shipped
 * breakage. Two live examples were reaching readers at once: `PrismFake::create()->image()`
 * in the image-generation testing block (no such method, and the assertion it suggested
 * could not pass either) and `Audio::fromContent()` on four pages, where the method is
 * `fromRawContent()`. Nothing failed, because nothing asked.
 *
 * This asks. For every `use Prism\...` import in the docs, every static call on that
 * import is resolved through reflection -- which already honours inheritance, so a method
 * Audio gets from Media counts -- and through the container for facades, whose statics
 * arrive via __callStatic rather than being declared.
 *
 * @return list<array{class: class-string|string, method: string, doc: string}>
 */
function documentedStaticCalls(): array
{
    $docs = realpath(__DIR__.'/../../docs');

    $files = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($docs, FilesystemIterator::SKIP_DOTS),
            // .vitepress holds the BUILT copy of every page; scanning it reports each
            // finding twice and anchors them to paths nobody edits.
            fn (SplFileInfo $file): bool => ! in_array($file->getFilename(), ['.vitepress', 'node_modules'], true)
        )
    );

    $calls = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'md') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        preg_match_all('/^use\s+(Prism\\\\[\w\\\\]+);/m', $contents, $imports);

        $imported = [];
        foreach ($imports[1] as $fqcn) {
            $parts = explode('\\', $fqcn);
            $imported[end($parts)] = $fqcn;
        }

        if ($imported === []) {
            continue;
        }

        preg_match_all('/\b(\w+)::(\w+)\s*\(/', $contents, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (! isset($imported[$match[1]])) {
                continue;
            }

            $calls[] = [
                'class' => $imported[$match[1]],
                'method' => $match[2],
                'doc' => str_replace('\\', '/', substr((string) $file->getPathname(), strlen($docs) + 1)),
            ];
        }
    }

    return $calls;
}

it('finds static calls in the docs to check', function (): void {
    // Without this the suite below passes loudest when the scanner is broken: a regex
    // that matches nothing has no failures to report. A floor rather than an exact
    // count, so writing a new page does not fail a test about something else.
    expect(count(documentedStaticCalls()))->toBeGreaterThan(400);
});

it('resolves every class the docs import', function (): void {
    $unresolved = [];

    foreach (documentedStaticCalls() as $call) {
        $class = $call['class'];
        if (class_exists($class)) {
            continue;
        }
        if (interface_exists($class)) {
            continue;
        }
        if (enum_exists($class)) {
            continue;
        }
        if (trait_exists($class)) {
            continue;
        }

        // A companion package is documented here but installed separately, so its
        // classes are legitimately absent. Anything under this package's OWN namespace
        // is not: that is a doc importing a class Prism does not ship.
        if (str_starts_with($class, 'Prism\\Prism\\')) {
            $unresolved[] = "{$class} ({$call['doc']})";
        }
    }

    expect(array_values(array_unique($unresolved)))->toBe([]);
});

it('resolves every static method the docs call', function (): void {
    $unresolved = [];

    foreach (documentedStaticCalls() as $call) {
        $class = $call['class'];
        $method = $call['method'];

        if (! class_exists($class) && ! enum_exists($class)) {
            continue;
        }

        if (method_exists($class, $method)) {
            continue;
        }

        // Facades declare almost nothing: their statics are forwarded by __callStatic to
        // whatever the container resolves, so the instance is where the method lives.
        if (is_subclass_of($class, Facade::class)) {
            $root = $class::getFacadeRoot();

            if ($root !== null && method_exists($root, $method)) {
                continue;
            }
        }

        // Prism\Prism\Facades\Tool is NOT a Laravel facade -- it is a plain class whose
        // own __callStatic news up a Tool -- so the container cannot answer for it. What
        // such a class publishes is its @method block, so that is what we hold it to:
        // a forwarded method it does not document still fails here.
        if (method_exists($class, '__callStatic')) {
            $docblock = (string) (new ReflectionClass($class))->getDocComment();

            if (preg_match('/@method\s[^\n]*\b'.preg_quote($method, '/').'\s*\(/', $docblock) === 1) {
                continue;
            }
        }

        $unresolved[] = "{$class}::{$method}() ({$call['doc']})";
    }

    expect(array_values(array_unique($unresolved)))->toBe([]);
});
