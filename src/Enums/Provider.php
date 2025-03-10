<?php

declare(strict_types=1);

namespace Prism\Prism\Enums;

enum Provider: string
{
    case Anthropic = 'anthropic';
    case DeepSeek = 'deepseek';
    case Ollama = 'ollama';
    case OpenAI = 'openai';
    case Mistral = 'mistral';
    case Groq = 'groq';
    case XAI = 'xai';
    case Gemini = 'gemini';
    case VoyageAI = 'voyageai';
}
