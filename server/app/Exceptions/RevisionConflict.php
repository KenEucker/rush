<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class RevisionConflict extends HttpException
{
    public function __construct()
    {
        parent::__construct(409, 'This record has changed. Reload it and review your changes before retrying.');
    }
}
