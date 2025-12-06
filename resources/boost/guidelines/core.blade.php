## Prism

- Prism is a Laravel package for integrating Large Language Models (LLMs) into applications with a fluent, expressive and eloquent API.
- Prism supports multiple AI providers: OpenAI, Anthropic, Ollama, Mistral, Groq, XAI, Gemini, VoyageAI, ElevenLabs, DeepSeek, and OpenRouter, Amazon Bedrock.
- Always use the `Prism\Prism\Facades\Prism` facade, class, or `prism()` helper function for all LLM interactions.
- Prism documentation follows the `llms.txt` format for its docs website and its hosted at `https://prismphp.com/**`
- **Before implementing any features using Prism, use the `web-search` tool to get the latest docs for that specific feature. The docs listing is available in <available-docs>**

### Basic Usage Patterns
- Use `Prism::text()` for text generation, `Prism::structured()` for structured output, `Prism::embeddings()` for embeddings, `Prism::image()` for image generation, and `Prism::audio()` for audio processing.
- Always chain the `using()` method to specify provider and model before generating responses.
- Use `asText()`, `asStructured()`, `asStream()`, `asEmbeddings()`, `asDataStreamResponse()`, `asEventStreamResponse()`, `asBroadcast()` etc. to finalize the request based on the desired response type.
- You can also use the fluent `prism()` helper function as an alternative to the Prism facade.

<available-docs>
## Getting Started
- [https://prismphp.com/getting-started/introduction.md] Use these docs for comprehensive introduction to Prism package, overview of architecture, list of all supported LLM providers (OpenAI, Anthropic, Gemini, etc.), and understanding the core philosophy behind Prism's unified API design
- [https://prismphp.com/getting-started/installation.md] Use these docs for step-by-step installation instructions via Composer, package registration, publishing config files, and initial setup requirements including PHP version compatibility
- [https://prismphp.com/getting-started/configuration.md] Use these docs for detailed configuration guide including environment variables, API keys setup for each provider, config file structure, default provider selection, and Laravel integration options

## Core Concepts
- [https://prismphp.com/core-concepts/text-generation.md] Use these docs for complete guide to text generation including basic usage patterns, the fluent API, provider/model selection, prompt engineering, max tokens configuration, temperature settings, and response handling
- [https://prismphp.com/core-concepts/streaming-output.md] Use these docs for streaming responses in real-time, handling chunked output, streaming event types, and streaming response types
- [https://prismphp.com/core-concepts/tools-function-calling.md] Use these docs for comprehensive tool/function calling functionality, defining tools with JSON schemas, registering handler functions, multi-step tool execution, error handling in tools, and provider-specific tool calling capabilities
- [https://prismphp.com/core-concepts/structured-output.md] Use these docs for generating structured JSON output, defining schemas and handling structured responses across different providers
- [https://prismphp.com/core-concepts/embeddings.md] Use these docs for creating vector embeddings from text and documents, choosing embedding models, and use cases like semantic search and similarity matching
- [https://prismphp.com/core-concepts/image-generation.md] Use these docs for generating images from text prompts, configuring image size and quality, working with different image models and handling image responses
- [https://prismphp.com/core-concepts/audio.md] Use these docs for audio processing including text-to-speech (TTS) synthesis, speech-to-text (STT) transcription, voice selection, audio format options, and handling audio files
- [https://prismphp.com/core-concepts/schemas.md] Use these docs for defining and working with schemas for structured output, JSON schema specifications
- [https://prismphp.com/core-concepts/prism-server.md] Use these docs for setting up and using Prism Server, Prism Server is a powerful feature that allows you to expose your Prism-powered AI models through a standardized API.
- [https://prismphp.com/core-concepts/testing.md] Use these docs for testing Prism integrations avoiding real API calls in tests, and assertion helpers

## Input Modalities
- [https://prismphp.com/input-modalities/images.md] Use these docs for passing images as input to LLMs, supporting multiple image formats
- [https://prismphp.com/input-modalities/documents.md] Use these docs for processing documents (PDFs, Word docs, etc.) as input, document parsing and text extraction
- [https://prismphp.com/input-modalities/audio.md] Use these docs for using audio files as input, audio transcription to text, supported audio formats
- [https://prismphp.com/input-modalities/video.md] Use these docs for working with video input for supporting providers.

## Providers
- [https://prismphp.com/providers/anthropic.md] Use these docs for Anthropic (Claude) provider and provider-specific parameters
- [https://prismphp.com/providers/deepseek.md] Use these docs for DeepSeek provider and provider-specific parameters
- [https://prismphp.com/providers/elevenlabs.md] Use these docs for ElevenLabs text-to-speech provider and provider-specific parameters
- [https://prismphp.com/providers/gemini.md] Use these docs for Google Gemini provider and provider-specific parameters
- [https://prismphp.com/providers/groq.md] Use these docs for Groq provider setup and provider-specific parameters
- [https://prismphp.com/providers/mistral.md] Use these docs for Mistral AI provider and provider-specific parameters
- [https://prismphp.com/providers/ollama.md] Use these docs for Ollama local LLM provider and provider-specific parameters
- [https://prismphp.com/providers/openai.md] Use these docs for OpenAI provider and provider-specific parameters
- [https://prismphp.com/providers/openrouter.md] Use these docs for OpenRouter provider and provider-specific parameters
- [https://prismphp.com/providers/voyageai.md] Use these docs for VoyageAI embeddings provider and provider-specific parameters
- [https://prismphp.com/providers/xai.md] Use these docs for XAI (Grok) provider and provider-specific parameters

## Advanced
- [https://prismphp.com/advanced/error-handling.md] Use these docs for error handling strategies,
- [https://prismphp.com/advanced/custom-providers.md] Use these docs for creating custom provider implementations, extending the Provider base class, implementing required methods (text, stream, structured, embeddings)
- [https://prismphp.com/advanced/rate-limits.md] Use these docs for managing API rate limits across providers.
- [https://prismphp.com/advanced/provider-interoperability.md] Use these docs for switching between providers seamlessly, writing provider-agnostic code
</available-docs>

#### Prism Relay (Model Context Protocol Integration) (https://github.com/prism-php/relay)

#### Prism Bedrock (AWS Bedrock Provider) (https://github.com/prism-php/bedrock)
