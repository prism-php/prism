# Building with Prism: A Hands-On Workshop

## Workshop Overview

This 3-hour workshop will guide participants through Prism's powerful capabilities for integrating AI into Laravel applications. Participants will build 2-3 practical AI workflows of increasing complexity, gaining hands-on experience with Prism's unified provider interface, multimodal inputs, structured output, and tools system.

### Prerequisites

- Basic knowledge of Laravel and PHP
- PHP 8.2+ and Laravel 11.0+
- API keys for at least one of these providers:
  - OpenAI (recommended)
  - Anthropic (recommended for advanced features)
  - Mistral, Ollama, or other supported providers (optional)

### Workshop Structure

- **Setup & Introduction** (30 minutes)
- **Project 1: Content Generation with Prism** (45 minutes)
- **Project 2: Multimodal Analysis with Prism** (60 minutes)
- **Project 3: Building an AI Assistant with Prism Tools** (45 minutes)

## Setup & Introduction (30 minutes)

1. **Prism Overview** (10 minutes)
   - Introduction to Prism's architecture and philosophy
   - Provider abstraction and switching between providers
   - Key features: tools, structured output, multimodal support
   
2. **Prism Setup and Configuration** (20 minutes)
   - Installing Prism in a Laravel project
   - Configuring providers and API keys
   - Understanding the Prism configuration file
   - Testing the installation with a simple text generation example

## Project 1: Content Generation with Prism (45 minutes)

**Difficulty: Beginner**

This project will demonstrate Prism's core text generation capabilities and provider abstraction, allowing participants to understand how to build flexible AI-powered content generation.

### Implementation Steps

1. **Setting up Basic Text Generation**
   - Using the `Prism::text()` API
   - Adding system prompts using `withSystemPrompt()`
   - Creating prompt templates as Laravel views
   - Switching between different providers with the same code

2. **Implementing Content Generation**
   - Creating a reusable content generation service
   - Handling different content types with prompt engineering
   - Implementing different generation parameters (temperature, max tokens)
   - Error handling for generation failures

3. **Building a Content Generation API**
   - Creating an API endpoint for content generation
   - Implementing provider switching based on request parameters
   - Returning generated content to clients

### Learning Outcomes

- Core Prism text generation workflows
- Provider abstraction and switching between providers
- Using system prompts and templates effectively
- Managing generation parameters
- Basic error handling

## Project 2: Multimodal Analysis with Prism (60 minutes)

**Difficulty: Intermediate**

This project will explore Prism's support for multimodal inputs and structured output, focusing on document and image analysis capabilities.

### Implementation Steps

1. **Working with Images and Documents**
   - Using the `Image` and `Document` value objects
   - Handling different input methods (path, base64, URL)
   - Understanding provider capabilities and limitations
   - Creating multimodal message sequences

2. **Implementing Structured Output**
   - Defining schemas using Prism's schema system
   - Using `ObjectSchema`, `StringSchema`, and other schema types
   - Working with `Prism::structured()` API
   - Handling structured parsing failures

3. **Building a Document Analysis Service**
   - Creating a document processing pipeline
   - Extracting structured information from documents
   - Handling different document types (PDF, images, text)
   - Implementing fallbacks when structured output fails

### Learning Outcomes

- Working with multimodal inputs in Prism
- Implementing structured output with schemas
- Handling provider-specific capabilities and limitations
- Building more complex AI workflows
- Advanced error handling strategies

## Project 3: Building an AI Assistant with Prism Tools (45 minutes)

**Difficulty: Advanced**

This project will demonstrate Prism's powerful tools system, allowing participants to build interactive AI assistants that can take actions within their application.

### Implementation Steps

1. **Understanding Prism's Tools System**
   - Tool concepts and architecture
   - Creating basic tools using `Tool::as()`
   - Defining tool parameters and schemas
   - Handling tool execution

2. **Implementing Custom Tools**
   - Creating a database search tool
   - Building a calculation/processing tool
   - Implementing an API integration tool
   - Managing tool results and error handling (tools return helpful error messages by default - can be disabled with `->withoutErrorHandling()` if exceptions are preferred)

3. **Building a Conversational Assistant**
   - Managing conversation state with message chains
   - Controlling tool choice with `withToolChoice()`
   - Implementing multi-step interactions
   - Streaming responses with Prism

### Learning Outcomes

- Creating and using custom tools with Prism
- Building interactive AI assistants
- Managing multi-step conversations
- Implementing streaming responses
- Advanced Prism workflows

## Conclusion and Extensions

- Recap of Prism's key capabilities
- Comparing provider-specific features
- Suggestions for extending the projects
- Resources for further learning

## Workshop Materials

- Workshop repository with starter code
- Solution code for reference
- Sample prompts, schemas, and tools
- Example documents and images for testing

---

## Setup Instructions

### 1. Create a new Laravel Project and Install Prism

```bash
composer create-project laravel/laravel prism-workshop
cd prism-workshop
composer require prism-php/prism
php artisan vendor:publish --tag=prism-config
```

### 2. Configure Prism Providers

Edit your `.env` file to add API keys:

```
OPENAI_API_KEY=your_openai_key_here
ANTHROPIC_API_KEY=your_anthropic_key_here
# Add other provider keys as needed
```

### 3. Create Project Directories

```bash
php artisan make:controller PrismController
mkdir -p resources/views/prism
mkdir -p resources/views/prompts
```

### 4. Setup Routes

Edit `routes/web.php` to add routes for each project:

```php
Route::get('/', function () {
    return view('welcome');
});

Route::prefix('prism')->group(function () {
    Route::get('/project1', [App\Http\Controllers\PrismController::class, 'project1']);
    Route::get('/project2', [App\Http\Controllers\PrismController::class, 'project2']);
    Route::get('/project3', [App\Http\Controllers\PrismController::class, 'project3']);
    
    // Add API endpoints as needed
    Route::post('/generate', [App\Http\Controllers\PrismController::class, 'generate']);
    Route::post('/analyze', [App\Http\Controllers\PrismController::class, 'analyze']);
    Route::post('/assistant', [App\Http\Controllers\PrismController::class, 'assistant']);
});
```

### 5. Start the Development Server

```bash
php artisan serve
```

---

## Additional Workshop Tips

- Have participants experiment with different providers to see how Prism's abstraction works
- Prepare examples of structured output schemas of varying complexity
- Create sample tools that demonstrate different parameter types
- Provide example documents and images for multimodal testing
- Consider comparing the same workflow across different providers to highlight Prism's flexibility