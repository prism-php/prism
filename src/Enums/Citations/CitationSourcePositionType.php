<?php

declare(strict_types=1);

namespace Prism\Prism\Enums\Citations;

enum CitationSourcePositionType: string
{
    case Character = 'character';
    case Page = 'page';
    case Chunk = 'chunk';
}
