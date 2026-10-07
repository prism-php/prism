#!/bin/sh
# Mutation tests for check-release-notes.sh.
#
# Both directions are asserted. The negative controls matter more than the
# positive ones: a check that accepts everything also "passes" every valid note,
# so the cases that MUST be refused are what establish it is doing anything --
# in particular the prose case, which is the one a naive `grep -i breaking`
# would wave through.
set -eu
# Absolute, because the --tag cases run from inside a throwaway repository.
#
# Either filename, because this file is BYTE-IDENTICAL to the copy each package
# vendors as tools/check-release-notes.sh, and each repository's CI diffs its
# copy against the canonical one here to catch drift. Resolving the name instead
# of hardcoding it is what keeps the two copies identical and that diff honest.
here="$(cd "$(dirname "$0")" && pwd)"
for candidate in "$here/check.sh" "$here/check-release-notes.sh"; do
    [ -f "$candidate" ] && script="$candidate" && break
done
if [ -z "${script:-}" ]; then
    echo "cannot find the checker next to $0" >&2
    exit 1
fi
pass=0
fail=0

check() {
    want=$1
    name=$2
    notes=$3
    got=0
    printf '%s' "$notes" | sh "$script" >/dev/null 2>&1 || got=$?
    if [ "$got" -eq "$want" ]; then
        pass=$((pass + 1))
        printf '  ok    exit %d  %s\n' "$got" "$name"
    else
        fail=$((fail + 1))
        printf '  FAIL  wanted %d, got %d  %s\n' "$want" "$got" "$name"
    fi
}

echo 'ACCEPTED (exit 0)'
check 0 'heading with a bullet'        '## Breaking changes
- `foo()` is gone; call `bar()`.
'
check 0 'deeper heading, star bullet'  '### Breaking Changes

* The floor moved.
'
check 0 'inline label'                 'BREAKING CHANGE: `foo()` is gone; call `bar()`.
'
check 0 'inline, plural, no hash'      'BREAKING CHANGES: the floor moved.
'
check 0 'explicit none'                'Fixes a parser bug.

No breaking changes.
'
check 0 'none, lowercase, no period'   'no breaking changes
'
check 0 'none after a long body'       'Adds four providers and a gate.

Each one is additive.

No breaking changes.
'

echo
echo 'REFUSED -- no declaration (exit 2)'
check 2 'ordinary notes, silent'       'Fixes a parser bug and adds a gate.
'
check 2 'PROSE ONLY -- the grep trap'  'Reworks the resolver internally. This avoids breaking
downstream consumers, who need no changes.
'
check 2 'prose: "non-breaking"'        'A non-breaking refactor of the stream handler.
'
check 2 'heading, nothing under it'    '## Breaking changes
'
check 2 'heading, next line a heading' '## Breaking changes

## Fixed
- a parser bug
'
check 2 'label with no text after it'  'BREAKING CHANGE:
'

echo
echo 'REFUSED -- contradictory (exit 3)'
check 3 'says both'                    '## Breaking changes
- `foo()` is gone.

No breaking changes.
'

echo
echo 'REFUSED -- empty (exit 4)'
check 4 'empty'                        ''
check 4 'whitespace only'              '

   
'

echo
echo '--tag, against real git tag objects'

# A throwaway repository, because the two failures --tag exists to prevent are
# both properties of the tag OBJECT and cannot be reproduced from text.
repo=$(mktemp -d)
trap 'rm -rf "$repo"' EXIT
(
    cd "$repo"
    git init -q .
    git config user.email t@example.test
    git config user.name t
    git config commit.gpgsign false
    git config tag.gpgsign false
    : > a && git add a && git commit -qm 'Fix a parser bug and add a gate'
    git tag -a declared -m 'BREAKING CHANGE: `foo()` is gone; call `bar()`.'
    git tag -a silent -m 'Fixes a parser bug.'
    git tag -a heading-verbatim --cleanup=verbatim -m '## Breaking changes
- `foo()` is gone.'
    # The cleanup trap, recorded as a case rather than a warning: this author
    # wrote a heading and git removed it, so the annotation declares nothing.
    git tag -a heading-stripped -m '## Breaking changes
- `foo()` is gone.'
    git tag lightweight
) >/dev/null 2>&1

tagcheck() {
    want=$1
    name=$2
    got=0
    ( cd "$repo" && sh "$script" --tag "$3" ) >/dev/null 2>&1 || got=$?
    if [ "$got" -eq "$want" ]; then
        pass=$((pass + 1))
        printf '  ok    exit %d  %s\n' "$got" "$name"
    else
        fail=$((fail + 1))
        printf '  FAIL  wanted %d, got %d  %s\n' "$want" "$got" "$name"
    fi
}

tagcheck 0 'annotated, inline label'            declared
tagcheck 0 'annotated, heading via verbatim'    heading-verbatim
tagcheck 2 'annotated, silent'                  silent
tagcheck 2 'heading STRIPPED by git cleanup'    heading-stripped
tagcheck 5 'LIGHTWEIGHT tag refused by name'    lightweight
tagcheck 5 'no such tag'                        nope

# The trap is only worth a case if the stripping is real. Assert the mechanism
# directly, so this suite fails if git ever stops doing it rather than quietly
# keeping a case that proves nothing.
if [ -n "$(cd "$repo" && git cat-file tag heading-stripped | grep '^#' || true)" ]; then
    fail=$((fail + 1))
    echo '  FAIL  git no longer strips "#" from tag messages; the heading case is now vacuous'
else
    pass=$((pass + 1))
    echo '  ok              git does strip "#" from tag messages (the trap is real)'
fi

echo
printf 'passed %d, failed %d\n' "$pass" "$fail"
[ "$pass" -ge 23 ] || { echo "VACUITY: expected at least 23 cases, ran $((pass + fail))" >&2; exit 1; }
[ "$fail" -eq 0 ]
