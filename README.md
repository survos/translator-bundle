# Survos Translator Bundle

A Symfony 7.3 / PHP 8.4 bundle that unifies multiple translation engines (DeepL, LibreTranslate, …), adds smart caching, and optional async processing via Messenger. Designed to replace the legacy `libre-bundle` in the Survos translation server.

> Target repo to integrate with next: [https://github.com/survos-sites/translation-server](https://github.com/survos-sites/translation-server)

---

## Features

* ✅ Drop‑in service: `Survos\TranslatorBundle\Service\Translator`
* 🔌 Pluggable engines: DeepL, LibreTranslate (more welcome)
* 🧠 Cache‑first: Symfony Cache (PSR‑6/16) with per‑engine TTL & busting
* 🚀 Async mode: Messenger message + worker for heavy workloads
* 📝 Rich metadata: hash, engine, source/target, confidence, token counts
* 🧪 Handy CLI for quick checks & warmups

---

## Installation

```bash
composer require survos/translator-bundle
```

If you use Symfony Flex, the bundle is auto‑enabled. Otherwise, add to `config/bundles.php`:

```php
return [
    // ...
    Survos\TranslatorBundle\SurvosTranslatorBundle::class => ['all' => true],
];
```

---

## Environment & API Keys

Set the following in your `.env.local` (or server secrets). Only configure the engines you’ll use.

```dotenv
### Core ###
TRANSLATOR_DEFAULT_ENGINE=libre   # libre | deepl
TRANSLATOR_CACHE_TTL=86400        # seconds (1 day default)
TRANSLATOR_TIMEOUT=10             # HTTP seconds

### DeepL ###
DEEPL_API_KEY=\!\!put-your-key-here\!\!
# Optional: free vs pro endpoint auto‑detected by key suffix (-free). Override if needed:
DEEPL_BASE_URI=https://api-free.deepl.com/v2

### LibreTranslate ###
LIBRETRANSLATE_BASE_URI=https://translate.argosopentech.com
# If your instance requires a key:
LIBRETRANSLATE_API_KEY=
```

> Pro tip: keep **engine‑specific** keys/names distinct per environment to avoid accidental cross‑use.

---

## Bundle Configuration

Create `config/packages/survos_translator.yaml`:

```yaml
survos_translator:
  default_engine: '%env(string:TRANSLATOR_DEFAULT_ENGINE)%'
  timeout: '%env(int:TRANSLATOR_TIMEOUT)%'
  cache_ttl: '%env(int:TRANSLATOR_CACHE_TTL)%'

  engines:
    deepl:
      api_key: '%env(DEEPL_API_KEY)%'
      base_uri: '%env(default:~:DEEPL_BASE_URI)%'  # null => autodetect
    libre:
      base_uri: '%env(LIBRETRANSLATE_BASE_URI)%'
      api_key: '%env(default::LIBRETRANSLATE_API_KEY)%'  # may be empty
```

### Optional: Dedicated Cache Pool

```yaml
framework:
  cache:
    pools:
      survos_translator.cache:
        adapter: cache.app
        default_lifetime: 86400
```

The bundle will auto‑wire a pool named `survos_translator.cache` if present, otherwise falls back to `cache.app`.

---

## In-process engine (`type: bergamot`)

Translation models loaded into the PHP process and called through FFI
([survos/bergamot](../../lib/bergamot)): no HTTP round trip, no service to keep running, and no cache layer in the
bundle (a call takes milliseconds; the application's translation memory is the one place a result is kept).

```yaml
survos_translator:
  default_engine: fast
  engines:
    libre:
      type: libre
      base_uri: '%env(resolve:LIBRE_HOST)%'
    fast:
      type: bergamot
      home: '%env(resolve:BERGAMOT_HOME)%'   # holds native/ and models/
      variant: base                           # tiny | base | base-memory
      fallback: libre                         # optional
```

```bash
bin/console translator:models             # what is installed for each bergamot engine
bin/console translator:models --install   # download the two models of the configured size, checksum-verified
bin/console translator:models --verify
```

`fast` translates in-process when the native libraries and the models are on this machine **and** the pair is one
the models cover: English to and from cs, de, es, fi, fr, it, nl, pt, sv, and between any two of those through
English. Otherwise the request goes to `fallback`: another language, a machine without the models, or a text the
model refuses (a sentence over 120 model tokens, unknown-token output). Without a `fallback`, each of those cases is
a `TranslatorException` that says why. Every result's `meta` says who translated: `via`, `in_process`, `models`, and
`in_process_skipped` with the reason when the fallback was used.

Under a web server PHP needs `ffi.enable=1`; the command line (and so `messenger:consume`) always has FFI. Models
load on first use and stay loaded for the life of the service: once per FrankenPHP worker or consumer process.

## Quick Start (Sync)

```php
<?php
namespace App\Controller;

use Survos\TranslatorBundle\Service\Translator; // the facade
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

final class DemoController extends AbstractController
{
    public function translate(Translator $translator): Response
    {
        $result = $translator->translate(
            text: 'Hello world',
            target: 'es',        // ISO 639-1 or BCP-47; engine normalizes
            source: 'en',        // optional; auto‑detect if omitted
            domain: 'ui',        // optional context tag for caching
            options: [           // engine‑specific extras
                'formality' => 'prefer_less', // DeepL example
            ]
        );

        // $result is a DTO with: text, source, target, engine, detectedSource, meta, cached
        return new Response($result->text); // "Hola mundo"
    }
}
```

### Minimal Service Call (no controller)

```php
$translated = $translator->translate('Save', 'es');
```

---

## Async Translation (Messenger)

Enable a transport (choose one) in `config/packages/messenger.yaml`:

```yaml
framework:
  messenger:
    transports:
      translator: '%env(MESSENGER_TRANSPORT_DSN)%' # e.g. doctrine://default | redis://localhost | amqp://...
    routing:
      Survos\TranslatorBundle\Message\TranslateText: translator
```

Dispatch work:

```php
use Survos\TranslatorBundle\Message\TranslateText;
use Symfony\Component\Messenger\MessageBusInterface;

$bus->dispatch(new TranslateText('Hello world', 'es', source: 'en', domain: 'ui'));
```

Run a worker:

```bash
php bin/console messenger:consume translator -vv
```

The worker uses the same caching rules; repeated requests are cheap.

---

## CLI Utilities (Symfony 7.3 style)

The bundle ships a small demo command to smoke‑test config and warm cache.

```bash
php bin/console translator:demo "Hello" --to=es --from=en --engine=deepl
```

Sample implementation pattern (your app command):

```php
<?php
namespace App\Command;

use Survos\TranslatorBundle\Service\Translator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;

#[AsCommand('translator:demo')]
final class TranslatorDemoCommand
{
    public function __construct(private Translator $translator) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Phrase to translate')] string $text,
        #[Option('to')] ?string $to = null,
        #[Option('from')] ?string $from = null,
        #[Option('engine')] ?string $engine = null,
    ): int {
        $res = $this->translator->translate($text, $to ?? 'es', $from, options: ['engine' => $engine]);
        $io->success(sprintf('[%s] %s → %s: %s', $res->engine, $res->source ?? 'auto', $res->target, $res->text));
        return Command::SUCCESS;
    }
}
```

> Notes for this user/project: parameters follow your preferred Symfony 7.3 attribute style (invokable, `SymfonyStyle`, attributes in `__invoke`).

---

## Engine Behavior

* **DeepL**

    * Auto‑selects Free/Pro endpoint by key suffix (`-free` = Free); can override via `DEEPL_BASE_URI`.
    * Supports options: `formality`, `glossary_id`, `preserve_formatting`, etc.
* **LibreTranslate**

    * Works with any compatible instance; set `LIBRETRANSLATE_BASE_URI`.
    * Some deployments require `LIBRETRANSLATE_API_KEY`; others don’t.

Both engines normalize language codes and report back `detectedSource` when source is omitted.

---

## Caching Strategy

* Key = hash(text, source, target, engine, domain, options subset)
* Default TTL via `TRANSLATOR_CACHE_TTL` or cache pool lifetime
* Bust per domain/engine using provided cache‑clearer:

```bash
php bin/console translator:cache:clear --engine=libre --domain=ui
```

---

## Error Handling

* Network/HTTP errors raise `TranslationTransportException`
* Invalid configuration raises `TranslationConfigException`
* Engines return `TranslationResult` with `meta["cached"] = true|false`

Catch & fallback example:

```php
try {
    $res = $translator->translate('Hello', 'fr', options: ['engine' => 'deepl']);
} catch (\Throwable $e) {
    // Fallback to Libre
    $res = $translator->translate('Hello', 'fr', options: ['engine' => 'libre']);
}
```

---

## Replacing the Legacy `libre-bundle`

1. **Remove old services/config** tied to `libre-bundle`.
2. **Install** this bundle and add envs: `LIBRETRANSLATE_BASE_URI`, optional `LIBRETRANSLATE_API_KEY`.
3. **Search/Replace** old client/service with `Survos\TranslatorBundle\Service\Translator`.
4. **Switch endpoints**: old `/translate` controllers can now delegate to the new service.
5. **Enable async** in the translation server by routing `TranslateText` via Messenger.
6. **Keep your existing cache**: point the pool name to `survos_translator.cache`.

Minimal controller in translation‑server style:

```php
#[Route('/api/translate', name: 'api_translate', methods: ['POST'])]
public function api(Request $req, Translator $translator): JsonResponse
{
    $text = (string) $req->request->get('text', '');
    $to   = (string) $req->request->get('to', 'es');
    $from = $req->request->get('from');
    $engine = $req->request->get('engine');

    $res = $translator->translate($text, $to, $from, options: ['engine' => $engine]);

    return $this->json([
        'text' => $res->text,
        'source' => $res->source ?? $res->detectedSource,
        'target' => $res->target,
        'engine' => $res->engine,
        'cached' => (bool)($res->meta['cached'] ?? false),
    ]);
}
```

---

## Testing Locally

```bash
# 1) Provide env vars (see above)
cp .env .env.local && $EDITOR .env.local

# 2) Quick smoke‑test
php bin/console translator:demo "Hello world" --to=es

# 3) Try the controller
symfony server:start -d
curl -X POST https://127.0.0.1:8000/api/translate -F text='Hello' -F to=fr
```

---

## Extending with New Engines

1. Implement `EngineInterface` (e.g., `AcmeEngine`).
2. Tag the service with `survos.translator.engine` and give it a `name`.
3. Now you can call with `options: ['engine' => 'acme']`.

Skeleton:

```php
final class AcmeEngine implements EngineInterface
{
    public function name(): string { return 'acme'; }
    public function translate(TranslationInput $in): TranslationResult { /* ... */ }
}
```

---

## Roadmap

* Google/Bing providers
* Glossaries & per‑project domains
* Token accounting & quotas per engine
* Batch API for paragraphs (keeps caching per unit)

---

## License

MIT © Survos

