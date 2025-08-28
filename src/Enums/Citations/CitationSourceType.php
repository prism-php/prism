<?php

declare(strict_types=1);

namespace Prism\Prism\Enums\Citations;

enum CitationSourceType: string
{
    case Document = 'document';
    case Url = 'url';
}
