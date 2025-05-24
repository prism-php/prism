<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\ValueObjects\MCPServer;

trait HasMCPServers
{
    /** @var array<MCPServer> */
    protected array $mcpServers = [];

    public function withMCPServer(
        string $name,
        string $url,
        ?string $authorizationToken = null,
        array $toolConfiguration = []
    ): self {
        $this->mcpServers[] = new MCPServer(
            name: $name,
            url: $url,
            authorizationToken: $authorizationToken,
            toolConfiguration: $toolConfiguration
        );

        return $this;
    }

    public function withMCPServers(array $servers): self
    {
        foreach ($servers as $server) {
            if ($server instanceof MCPServer) {
                $this->mcpServers[] = $server;
            } elseif (is_array($server)) {
                $this->withMCPServer(
                    name: $server['name'],
                    url: $server['url'],
                    authorizationToken: $server['authorization_token'] ?? null,
                    toolConfiguration: $server['tool_configuration'] ?? []
                );
            }
        }

        return $this;
    }

    public function getMCPServers(): array
    {
        return $this->mcpServers;
    }

    public function hasMCPServers(): bool
    {
        return ! empty($this->mcpServers);
    }
}