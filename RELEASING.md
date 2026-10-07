# Releasing

A release is a tag push. `.github/workflows/publish.yml` does the rest.

There is **no package upload step or package upload token**. Composer resolves versions from git
tags and Packagist mirrors them over a webhook, so pushing the tag *is* the
publish. What the workflow adds is the part that has gone wrong here before:
proving the tag was tested, and proving the package actually became installable.

## Cutting a release

Complete the [release preflight](.github/RELEASING.md), including claim review
and successful strict Factcheck CI on the exact candidate SHA, before tagging.
The publish workflow runs after Packagist can see the tag; it cannot prevent
publication of an unchecked package.

1. Land the work on `main` and wait for all four gates and strict Factcheck to go green.
2. Tag it and push:

   ```
   git tag -a v0.2.0        # annotated: the message BECOMES the release notes
   git push origin v0.2.0
   ```

   **The tag annotation is the changelog.** These packages ship no CHANGELOG
   file, so what you write in the tag message is what a consumer reads on the
   release page and what arrives in their inbox. The workflow publishes it
   verbatim.

   A lightweight tag — `git tag v0.2.0` with no `-a` and no message — FAILS the
   release step rather than publishing an empty release. If a version is worth
   cutting, it is worth a sentence saying why.

   **Every annotation must declare its breaking status**, and the release
   refuses one that does not. Write one of these two lines, whichever is true:

   ```
   BREAKING CHANGE: <what breaks, and what the consumer must do about it>
   ```

   ```
   No breaking changes.
   ```

   A `## Breaking changes` heading with content under it also satisfies the
   check, but **only if you tag with `--cleanup=verbatim` or `-F`**. Under git's
   default cleanup, every `#` line in a tag message is treated as a comment and
   **deleted** — so `git tag -a -m '## Breaking changes …'` publishes an
   annotation with the heading silently missing. The two forms above carry no
   `#` to lose, which is why they come first.

   This file already asked for all of this in prose, and asking did not work: of
   the twenty most recent annotations across ten of these repositories, EIGHTEEN
   never used the word "breaking" at all. One of them shipped a mandatory
   migration, a changed scope-matching rule and a raised framework floor, under
   headings that described each change accurately and labelled none of them
   breaking. A consumer scanning that release page for the word found nothing.

   So check it BEFORE you push the tag — that is the only moment refusing still
   prevents a Composer publish:

   ```
   git tag -a v0.2.0                             # write the message
   sh tools/check-release-notes.sh --tag v0.2.0  # must pass
   git push origin v0.2.0                        # only then
   ```

   Use `--tag`, not a pipe from `git tag -l --format='%(contents)'`: on a
   lightweight tag that format yields the *commit* message instead, so the check
   would read text the release will never publish and approve it. `--tag`
   refuses that case by name.

   Prose mentioning "breaking" does not satisfy the check — it matches the
   structural form, so a note that merely discusses breakage still has to say
   which it is. Anyone holding a pinned digest or matching on an error code
   learns it here or not at all.

Composer takes the version from the tag, so there is nothing to bump in
`composer.json` — and a `version` key there is refused, because it reintroduces
exactly the tag-versus-declared disagreement Composer avoids by not having one.

## What it refuses, and why

- **No successful `tests.yml` run for that exact commit.** Not "nothing failed"
  — *succeeded*. A package whose tests never ran reports nothing failed, which
  is how `prism-opentelemetry` shipped v0.1.1 with 32 tests on disk that CI had
  never once executed.
- **Any other gate failed on that commit** — PHPStan, Formatting, Require
  Checker, Factcheck, whichever this repo has.
- **No successful strict Factcheck run for that exact commit.** A run on an
  earlier commit does not satisfy the release guard.
- **An annotation that does not declare its breaking status**, in either
  direction. Silence is not read as "nothing breaks" — it is refused, the same
  way a lightweight tag is, and for the same reason: the annotation is the only
  changelog a consumer gets. A `## Breaking changes` heading with nothing under
  it is refused too, and so is an annotation that declares both ways at once.
- **`composer.json` declares a `version`.**
- **Packagist never serves the version.** The release job succeeds and this one
  still fails, deliberately: a tag and a GitHub release are not a distribution.

## First publication

If the package has never been published, the last job fails no matter how good
the release is, because nothing is listening for the webhook yet. Submit the
package once at <https://packagist.org/packages/submit>; Packagist follows the
repository's tags on its own afterwards, and re-running that job then passes.

This is not hypothetical. `prism-human-plus` was tagged, released on GitHub and
uninstallable for days because nobody had submitted it, and nothing anywhere
reported a problem. `prism-memory`, `prism-workspace` and `prism-browser` were
named here as being in that state; all three have since been submitted and
Packagist serves them. Check before believing this paragraph about any package —
`curl -s -o /dev/null -w '%{http_code}' https://repo.packagist.org/p2/<vendor>/<package>.json`
answers it in one line, and a list of names in a document does not.
