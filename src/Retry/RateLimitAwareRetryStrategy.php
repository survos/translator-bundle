<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Retry;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;

/**
 * Extends the default retry strategy so a 429 (rate limited) response that carries a
 * "Retry-After" header (seconds, or an HTTP-date) is honored instead of always falling
 * back to the exponential backoff computed by the parent class.
 *
 * Translation engines (DeepL, Google, LibreTranslate, ...) can all be rate-limited, so
 * this is applied uniformly to every scoped engine client rather than special-cased.
 */
final class RateLimitAwareRetryStrategy extends GenericRetryStrategy
{
    public function getDelay(AsyncContext $context, ?string $responseContent, ?\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $exception): int
    {
        if (429 === $context->getStatusCode()) {
            $retryAfterMs = $this->parseRetryAfter($context->getHeaders()['retry-after'][0] ?? null);
            if (null !== $retryAfterMs) {
                return $retryAfterMs;
            }
        }

        return parent::getDelay($context, $responseContent, $exception);
    }

    private function parseRetryAfter(?string $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        // Retry-After: <seconds>
        if (ctype_digit(trim($value))) {
            return (int) $value * 1000;
        }

        // Retry-After: <http-date>
        $timestamp = strtotime($value);
        if (false !== $timestamp) {
            $delaySeconds = $timestamp - time();

            return max(0, $delaySeconds * 1000);
        }

        return null;
    }
}
