<?php

declare(strict_types=1);

namespace Prism\Prism\Enums;

enum ChunkType
{
    case Message;
    case Thinking;
    case Meta;
}
