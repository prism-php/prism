<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

class MCPServer
{
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly ?string $authorizationToken = null,
        public readonly array $toolConfiguration = [],
    ) {}

    public function toArray(): array
    {
        $config = [
            'type' => 'url',
            'url' => $this->url,
            'name' => $this->name,
        ];

        if ($this->authorizationToken !== null) {
            $config['authorization_token'] = $this->authorizationToken;
        }

        if ($this->toolConfiguration !== []) {
            $config['tool_configuration'] = $this->toolConfiguration;
        }

        return $config;
    }
}