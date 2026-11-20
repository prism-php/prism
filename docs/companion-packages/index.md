# Companion Packages

Install companion packages alongside `particle-academy/prism` for persistent sessions, semantic memory, scoped file access, remote tools and telemetry export.

## Available packages

| Package | Use | Setup |
|---|---|---|
| [Harness](/companion-packages/harness) | Conversations, session modes, approvals, authorization and subagents | Publish configuration, migrate and run `harness:doctor` |
| [MCP](/companion-packages/mcp) | Consume remote tools with trust, pins and invocation controls | Configure server endpoints, credentials and trust |
| [Memory](/companion-packages/memory) | Store records and recall relevant context | Configure embeddings, migrate and run the embedding queue |
| [Workspace](/companion-packages/workspace) | Guard file operations within an owner-scoped disk | Configure the disk, owner addressing and authorization |
| [Perplexity](https://github.com/Particle-Academy/prism-perplexity) | Perplexity endpoints outside the core provider API | Configure provider credentials and transport |
| [OpenTelemetry](/advanced/telemetry) | Export core telemetry events as spans | Install the exporter and configure a trusted telemetry sink |

## Installation

Choose the packages your application needs:

```bash
composer require particle-academy/prism-harness
composer require particle-academy/prism-mcp
composer require particle-academy/prism-memory
composer require particle-academy/prism-workspace
composer require particle-academy/prism-perplexity
composer require particle-academy/prism-opentelemetry
```

Run each guide's setup for its configuration, migrations, credentials and access policies. Installing a companion does not automatically expose its capabilities to a model.

## Combining packages

Harness owns the conversation and run lifecycle. Memory supplies recalled context or stores evicted turns. Workspace provides file operations you can wrap in application-authorized tools. MCP supplies remote tools after trust admission and invocation policy. OpenTelemetry exports events emitted by core.

Authorize participant/owner scopes before resolving them, preserve provider replay data when persisting conversations, and keep tool policies active when introducing recalled or remote content. Each package's guide describes the controls and defaults you must configure.
