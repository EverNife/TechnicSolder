<?php

namespace App\Libraries;

use RuntimeException;

class ArchiveExistsException extends RuntimeException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct("{$path} already exists. Resend with replace to overwrite it.");
    }
}
