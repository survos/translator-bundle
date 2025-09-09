<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Service;

use Survos\TranslatorBundle\Contract\TranslatorEngineInterface;

final class TranslatorManager
{
    public function __construct(public readonly TranslatorRegistry $registry) {}

    public function default(): TranslatorEngineInterface { return $this->registry->getDefault(); }

    public function by(string $name): ?TranslatorEngineInterface { return $this->registry->get($name); }

    /** @return list<string> */
    public function names(): array { return $this->registry->names(); }

    public function defaultName(): string { return $this->registry->defaultName(); }

    public static function calcHash(string $string, string $locale): string
    {
        assert(strlen($locale)===2, "Invalid Locale: $locale");
        $str = substr_replace(hash('xxh3', $string), strtoupper($locale), 3, 0); // insert locale into 3rd position
//        dd(hexdec($str), $str, strlen($str)); // 255^8 = a 19-digit number
        return $str;
    }

}
