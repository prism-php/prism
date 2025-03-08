<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Foundation\Application;
use Mockery;
use PrismPHP\Prism\Contracts\Provider as ContractsProvider;
use PrismPHP\Prism\Enums\Provider;
use PrismPHP\Prism\PrismManager;
use PrismPHP\Prism\Providers\Anthropic\Anthropic;
use PrismPHP\Prism\Providers\DeepSeek\DeepSeek;
use PrismPHP\Prism\Providers\Gemini\Gemini;
use PrismPHP\Prism\Providers\Mistral\Mistral;
use PrismPHP\Prism\Providers\Ollama\Ollama;
use PrismPHP\Prism\Providers\OpenAI\OpenAI;
use PrismPHP\Prism\Providers\XAI\XAI;

it('can resolve Anthropic', function (): void {
    $manager = new PrismManager($this->app);

    expect($manager->resolve(Provider::Anthropic))->toBeInstanceOf(Anthropic::class);
    expect($manager->resolve('anthropic'))->toBeInstanceOf(Anthropic::class);
});

it('can resolve Ollama', function (): void {
    $manager = new PrismManager($this->app);

    expect($manager->resolve(Provider::Ollama))->toBeInstanceOf(Ollama::class);
    expect($manager->resolve('ollama'))->toBeInstanceOf(Ollama::class);
});

it('can resolve OpenAI', function (): void {
    $manager = new PrismManager($this->app);

    expect($manager->resolve(Provider::OpenAI))->toBeInstanceOf(OpenAI::class);
    expect($manager->resolve('openai'))->toBeInstanceOf(OpenAI::class);
});

it('can resolve Mistral', function (): void {
    $manager = new PrismManager($this->app);

    expect($manager->resolve(Provider::Mistral))->toBeInstanceOf(Mistral::class);
    expect($manager->resolve('mistral'))->toBeInstanceOf(Mistral::class);
});

it('can resolve XAI', function (): void {
    $manager = new PrismManager($this->app);

    expect($manager->resolve(Provider::XAI))->toBeInstanceOf(XAI::class);
    expect($manager->resolve('xai'))->toBeInstanceOf(XAI::class);
});

it('can resolve Gemini', function (): void {
    $manager = new PrismManager($this->app);

    expect($manager->resolve(Provider::Gemini))->toBeInstanceOf(Gemini::class);
    expect($manager->resolve('gemini'))->toBeInstanceOf(Gemini::class);
});

it('can resolve DeepSeek', function (): void {
    $manager = new PrismManager($this->app);

    expect($manager->resolve(Provider::DeepSeek))->toBeInstanceOf(DeepSeek::class);
    expect($manager->resolve('deepseek'))->toBeInstanceOf(DeepSeek::class);
});

it('allows for custom provider configuration', function (): void {
    $manager = new PrismManager($this->app);

    $manager->extend('test', function (Application $app, array $config) {
        expect($config)->toBe(['api_key' => '1234']);

        return Mockery::mock(ContractsProvider::class);
    });

    $manager->resolve('test', ['api_key' => '1234']);
});
