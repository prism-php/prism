<?php

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Illuminate\Support\Arr;
use Prism\Prism\Providers\OpenAI\File\DeleteResponse;
use Prism\Prism\Providers\OpenAI\File\ListResponse;
use Prism\Prism\Providers\OpenAI\File\Response;

trait PreparesFileResponses
{
    /**
     * @param  array <string, mixed>  $responseData
     */
    protected function prepareFileResponse(array $responseData): Response
    {
        return new Response(
            id: Arr::get($responseData, 'id'),
            fileName: Arr::get($responseData, 'filename'),
            bytes: Arr::get($responseData, 'bytes'),
            createdAt: Arr::get($responseData, 'created_at'),
            providerSpecificData: [
                'purpose' => Arr::get($responseData, 'purpose'),
                'expires_at' => Arr::get($responseData, 'expires_at'),
                'status' => Arr::get($responseData, 'status'),
                'status_details' => Arr::get($responseData, 'status_details'),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    protected function prepareFileListResponse(array $responseData): ListResponse
    {
        return new ListResponse(providerSpecificData: [
            'object' => Arr::get($responseData, 'object'),
            'data' => array_map(
                fn (array $file) => $this->prepareFileResponse($file),
                Arr::get($responseData, 'data', [])
            ),
            'has_more' => Arr::get($responseData, 'has_more'),
            'first_id' => Arr::get($responseData, 'first_id'),
            'last_id' => Arr::get($responseData, 'last_id'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    protected function prepareDeleteResponse(array $responseData): DeleteResponse
    {
        return new DeleteResponse(
            providerSpecificData: [
                'id' => Arr::get($responseData, 'id'),
                'object' => Arr::get($responseData, 'object'),
                'deleted' => Arr::get($responseData, 'deleted'),
            ]
        );
    }
}
