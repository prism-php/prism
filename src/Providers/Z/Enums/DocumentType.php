<?php

namespace Prism\Prism\Providers\Z\Enums;

enum DocumentType: string
{
    case FileUrl = 'file_url';

    case VideoUrl = 'video_url';

    case ImageUrl = 'image_url';
}
