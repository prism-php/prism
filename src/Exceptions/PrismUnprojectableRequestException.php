<?php

declare(strict_types=1);

namespace Prism\Prism\Exceptions;

class PrismUnprojectableRequestException extends PrismException
{
    public function __construct()
    {
        parent::__construct('The request requires tool approval resolution before its payload can be projected.');
    }
}
