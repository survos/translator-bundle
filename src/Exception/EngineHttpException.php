<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Exception;

final class EngineHttpException extends TranslatorException
{
    /**
     * @param int|null $retryAfter seconds the origin asked callers to wait before retrying
     *     (from a 429/503 response's Retry-After header), when it sent one. Null when the
     *     origin didn't specify — callers should fall back to their own default backoff.
     */
    public function __construct(
        public readonly int $statusCode,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode);
    }
}
