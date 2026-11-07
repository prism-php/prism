# Perplexity fixture provenance

`documented-agent-response.json` is extracted from Perplexity's published
[migration response example](https://docs.perplexity.ai/docs/agent-api/migrate-from-sonar/overview).
Its adjacent provenance file records retrieval time, source and fixture SHA256,
and the mechanical removal of Markdown quoting and standalone elision comments.
No response values were added. It is a documentation example, not a live run.

The other fixtures predate issue #103. Their live provenance has not been
verified in this change. They remain compatibility and fault-injection controls;
passing them does not establish live provider acceptance. Inline modified bodies
in regression tests are explicitly fault injections, not provider recordings.

Live acceptance belongs to the local Lab's operator-submitted research cases.
Those record sanitized outcomes only: status, reason, identifier presence,
citations count and timing. Missing observations remain unmeasured.
