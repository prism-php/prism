<?php

declare(strict_types=1);

namespace Prism\Prism\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Prism\Prism\Facades\PrismServer;

class PrismModelController
{
    public function __invoke(): JsonResponse
    {
        $prisms = PrismServer::prisms()
            ->map(fn (array $model): array => [
                'id' => $model['name'],
                'object' => 'model',
            ]);

        return response()->json(
            [
                'object' => 'list',
                'data' => $prisms->toArray(),
            ]
        );
    }
}
