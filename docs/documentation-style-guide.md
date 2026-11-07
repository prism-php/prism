# Technical Documentation Style Guide

## Purpose

Documentation helps readers install, configure and use the released software.
Describe available behavior, requirements and limitations. Keep implementation
history, debugging narratives and design deliberations in issues, pull requests
or decision records.

## Voice

- Use direct, professional language and address the reader as "you".
- Start with what the feature does and when to use it.
- Prefer concrete statements over marketing adjectives or rhetorical questions.
- Avoid jokes, analogies, exclamation marks and imagined failure stories.
- Explain a consequence only when it affects the reader's configuration or use.
- Do not narrate the author's reasoning or defend implementation choices.

For example, write "The iterator yields entries without loading the complete
listing into memory." Do not add a story about how the application might fail.

## Structure

- Begin with a short description, followed by prerequisites and a working example.
- Use descriptive headings such as "Configuration", "Response handling" and "Limits".
- Put advanced options after basic usage.
- Link to related guides instead of repeating their explanations.
- Use tables for comparable options, not as a substitute for every paragraph.
- Preserve existing heading anchors where possible; update links when headings change.

## Accuracy and scope

- Identify the language, package and framework to which an instruction applies.
- Do not describe Laravel integration as a requirement of TypeScript or Python.
- Verify API names and examples against the implementation.
- Distinguish provider support, model support and package support.
- State unsupported features directly and point to a supported alternative.
- Avoid promises about future releases; keep plans in the issue tracker.
- Do not present test anecdotes or benchmark observations as API guarantees.
- Qualify claims about locking, durability, security, schema validation and performance.
- Use package manifests for requirements and release notes for version-specific changes.
- Keep model availability and pricing out of general recommendations unless verified.

## Examples

- Show valid imports, prerequisites and the relevant result.
- Use the native API for each supported language; do not mechanically translate syntax.
- Use comments to explain assumptions or non-obvious behavior.
- Mark illustrative code and application-supplied variables explicitly.
- Keep credentials out of examples and use server-side environment configuration.
- Preserve working examples during editorial changes and validate any API changes.
- Do not publish examples for planned or unimplemented features as usable APIs.

## Warnings

Keep warnings short and actionable: state the condition, consequence and mitigation.
Retain security boundaries, data-loss risks and important provider limitations.
Do not replace specific requirements with broad claims such as "secure by default",
"no validation needed" or "exactly once".

## Review checklist

- Does each paragraph help the reader use or understand the feature?
- Are requirements and limitations scoped to the correct implementation?
- Are instructions concrete, without promotional or internal-development language?
- Are examples, links, headings and callouts still valid?
- Have changed examples and rendered pages been checked?
