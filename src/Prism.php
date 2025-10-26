<?php

declare(strict_types=1);

namespace Prism\Prism;

use Prism\Prism\Audio\PendingRequest as PendingAudioRequest;
use Prism\Prism\Embeddings\PendingRequest as PendingEmbeddingRequest;
use Prism\Prism\Images\PendingRequest as PendingImageRequest;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;

class Prism
{
    public function text(): PendingTextRequest
    {
        return new PendingTextRequest;
    }

    public function structured(): PendingStructuredRequest
    {
        return new PendingStructuredRequest;
    }

    public function embeddings(): PendingEmbeddingRequest
    {
        return new PendingEmbeddingRequest;
    }

    public function image(): PendingImageRequest
    {
        return new PendingImageRequest;
    }

    public function audio(): PendingAudioRequest
    {
        return new PendingAudioRequest;
    }
}
