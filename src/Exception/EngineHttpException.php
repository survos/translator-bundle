<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Exception;

final class EngineHttpException extends TranslatorException
{
    public function __construct(public readonly int $statusCode, string $message)
    {
        parent::__construct($message, $statusCode);
    }
}
