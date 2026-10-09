<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class SyncProtocolConflict extends HttpException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct(409, $message);
    }
}
