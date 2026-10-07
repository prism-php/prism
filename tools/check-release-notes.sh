#!/bin/sh
# Refuse a release whose notes do not DECLARE their breaking status.
#
# The annotated tag message is the changelog here. These packages ship no
# CHANGELOG file, so the annotation is what a consumer reads on the release
# page. publish.yml already refuses a lightweight tag and an empty annotation,
# on the reasoning that a release with nothing to say about itself should not be
# quietly publishable. This asks the one question a consumer most needs answered
# and refuses the same way: silence is not a valid answer to it.
#
# Measured before this existed: of the twenty most recent annotations across ten
# of these repositories, eighteen never used the word "breaking" at all --
# including every one of prism's six latest releases, and a prism-harness
# release that shipped a mandatory migration, a changed scope-matching rule and
# a raised framework floor. Nothing was concealing those. Nothing was asking.
#
# An annotation must carry exactly ONE of:
#
#   ## Breaking changes        a heading, with at least one line under it
#   BREAKING CHANGE: <text>    a label with its text on the same line
#   No breaking changes.       on a line of its own
#
# Both a breaking declaration and a no-breaking one is a contradiction and is
# refused. Neither is refused. The word "breaking" in prose does NOT satisfy
# this: the check matches the structural form, so it cannot be passed by
# accident by a note that happens to discuss breakage.
#
# A trap worth knowing before picking a form: git STRIPS '#' lines from a tag
# message under its default --cleanup=strip, treating them as commentary. So
#
#   git tag -a v1.2.3 -m '## Breaking changes
#   - foo is gone'
#
# yields an annotation reading only "- foo is gone". The heading is deleted
# silently, and this check then refuses a note whose author is certain it
# declared something. 'BREAKING CHANGE:' and 'No breaking changes.' carry no '#'
# to lose; a heading needs --cleanup=verbatim or -F. Headings are accepted here
# because five annotations in this estate already use them successfully.
#
# Usage:  tools/check-release-notes.sh <file>
#         tools/check-release-notes.sh --tag v1.2.3
#         <something> | tools/check-release-notes.sh
#
# Prefer --tag over piping `git tag -l --format='%(contents)'`: on a LIGHTWEIGHT
# tag that format silently yields the COMMIT message instead, so the check reads
# text the release will never publish and reports on it confidently. --tag
# refuses that case by name. This repository has already built a release page
# from the wrong text three separate ways for want of that distinction -- see the
# comments in .github/workflows/publish.yml.
#
# Exit:   0 declared    2 no declaration    3 contradictory    4 empty
#         1 usage error    5 not an annotated tag
set -eu

if [ "${1:-}" = "--tag" ]; then
    if [ "$#" -ne 2 ]; then
        echo "usage: $0 --tag <tagname>" >&2
        exit 1
    fi
    if [ "$(git cat-file -t "$2" 2>/dev/null)" != tag ]; then
        echo "::error::'$2' is not an annotated tag, so it carries no release notes. Tag with 'git tag -a' -- the message IS the changelog." >&2
        exit 5
    fi
    # Everything after the tag object's first blank line, minus any signature
    # trailer. Read this way rather than through %(contents), which cannot tell a
    # missing annotation from a real one -- the whole reason --tag exists.
    notes=$(git cat-file tag "$2" \
        | sed -n '/^$/,$p' | tail -n +2 \
        | sed -n '/^-----BEGIN PGP SIGNATURE-----$/q;p' \
        | tr -d '\r')
elif [ "$#" -gt 1 ]; then
    echo "usage: $0 [file]   (notes on stdin when no file is given)" >&2
    exit 1
elif [ "$#" -eq 1 ]; then
    if [ ! -r "$1" ]; then
        echo "check-release-notes: cannot read '$1'" >&2
        exit 1
    fi
    notes=$(tr -d '\r' < "$1")
else
    notes=$(tr -d '\r')
fi

# An empty annotation is its own refusal, so the script stands alone rather than
# depending on the workflow having checked first.
if [ -z "$(printf '%s' "$notes" | tr -d '[:space:]')" ]; then
    echo "::error::Release notes are empty. The annotation IS the changelog -- write it." >&2
    exit 4
fi

label='breaking[[:space:]]+changes?'
heading="^[[:space:]]*(#{1,6}[[:space:]]*)?${label}[[:space:]]*:?[[:space:]]*$"
inline="^[[:space:]]*(#{1,6}[[:space:]]*)?${label}[[:space:]]*:[[:space:]]*[^[:space:]]"
none="^[[:space:]]*no[[:space:]]+${label}[[:space:]]*\.?[[:space:]]*$"

# The no-breaking line must be matched BEFORE the breaking forms, or "No
# breaking changes." satisfies the heading pattern too and every release reads
# as contradictory. Anchoring `none` first is what keeps the two disjoint.
declared_none=$(printf '%s\n' "$notes" | grep -icE "$none" || true)

# Count only lines that are not the no-breaking declaration, so one line can
# never answer both ways.
breaking_lines=$(printf '%s\n' "$notes" | grep -ivE "$none" || true)
declared_heading=$(printf '%s\n' "$breaking_lines" | grep -icE "$heading" || true)
declared_inline=$(printf '%s\n' "$breaking_lines" | grep -icE "$inline" || true)

# A heading with nothing under it declares nothing. Find the first non-blank
# line after it; an immediately following heading means the section is empty.
heading_has_body=0
if [ "$declared_heading" -gt 0 ]; then
    at=$(printf '%s\n' "$breaking_lines" | grep -inE "$heading" | head -1 | cut -d: -f1)
    body=$(printf '%s\n' "$breaking_lines" | tail -n "+$((at + 1))" | grep -vE '^[[:space:]]*$' | head -1)
    case "$body" in
        '')          heading_has_body=0 ;;   # nothing follows the heading at all
        '#'*)        heading_has_body=0 ;;   # the next thing is another section
        *'#'*)       heading_has_body=1 ;;
        *)           heading_has_body=1 ;;
    esac
    # A heading indented under a list is still a heading; leading space is
    # stripped by the pattern, not here.
    if [ "$heading_has_body" -eq 0 ] && [ "$declared_inline" -gt 0 ]; then
        heading_has_body=1   # an inline label elsewhere carries the content
    fi
fi

declared_breaking=0
if [ "$declared_inline" -gt 0 ] || { [ "$declared_heading" -gt 0 ] && [ "$heading_has_body" -eq 1 ]; }; then
    declared_breaking=1
fi

if [ "$declared_breaking" -eq 1 ] && [ "$declared_none" -gt 0 ]; then
    echo "::error::Release notes both declare breaking changes and declare there are none. Say one." >&2
    exit 3
fi

if [ "$declared_breaking" -eq 1 ]; then
    echo "Release notes declare breaking changes (${declared_heading} heading(s), ${declared_inline} inline label(s))."
    exit 0
fi

if [ "$declared_none" -gt 0 ]; then
    echo "Release notes declare no breaking changes (${declared_none} declaration(s))."
    exit 0
fi

if [ "$declared_heading" -gt 0 ]; then
    echo "::error::Release notes have a 'Breaking changes' heading with nothing under it. Fill it in, or say 'No breaking changes.'" >&2
    exit 2
fi

cat >&2 <<'MSG'
::error::Release notes do not say whether this release breaks anything.
The annotation IS the changelog -- it is what a consumer reads on the release
page -- so it must answer that question explicitly. Add ONE of:

  ## Breaking changes
  - <what breaks, and what the consumer must do about it>

  BREAKING CHANGE: <what breaks, and what the consumer must do about it>

  No breaking changes.

The word "breaking" in prose does not count. Say it structurally so a consumer
scanning the release page finds it.

If you DID write a '## Breaking changes' heading, git deleted it. Its default
--cleanup=strip treats every '#' line in a tag message as a comment and removes
it without saying so. Use the 'BREAKING CHANGE:' form, which has no '#' to lose,
or tag with --cleanup=verbatim to keep the heading.
MSG
exit 2
