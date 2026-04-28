# Plan B — Fork `laravel/ai` avec driver Infomaniak/Euria

> Fork public de `laravel/ai` (branche `0.x`)  
> Objectif : ajouter Euria comme driver natif `Ai::provider('infomaniak')`  
> Orientation communauté — README complet, docs, PR upstream possible
> Package : `martin-lechene/laravel-ai` (fork) ou contribution directe

---

## Table des matières

1. [Contexte & stratégie](#1-contexte--stratégie)
2. [Analyse de l'architecture `laravel/ai`](#2-analyse-de-larchitecture-laravelai)
3. [Différences Plan A vs Plan B](#3-différences-plan-a-vs-plan-b)
4. [Étape 0 — Forker le repo](#4-étape-0--forker-le-repo)
5. [Mapping de l'architecture existante](#5-mapping-de-larchitecture-existante)
6. [Fichiers à créer — Driver Infomaniak](#6-fichiers-à-créer--driver-infomaniak)
7. [Structure complète du fork](#7-structure-complète-du-fork)
8. [Implémentation — `InfromaniakDriver` (Text)](#8-implémentation--infomaniakdriver-text)
9. [Implémentation — Streaming Infomaniak](#9-implémentation--streaming-infomaniak)
10. [Implémentation — Embeddings Infomaniak](#10-implémentation--embeddings-infomaniak)
11. [Implémentation — Images Infomaniak (SDXL/Flux)](#11-implémentation--images-infomaniak-sdxlflux)
12. [Implémentation — Audio Transcription (Whisper)](#12-implémentation--audio-transcription-whisper)
13. [Implémentation — Function Calling](#13-implémentation--function-calling)
14. [Enregistrement du driver dans le Manager](#14-enregistrement-du-driver-dans-le-manager)
15. [Configuration `config/ai.php` — ajout Infomaniak](#15-configuration-configaiphp--ajout-infomaniak)
16. [Enum `Lab` — ajout de la constante `Infomaniak`](#16-enum-lab--ajout-de-la-constante-infomaniak)
17. [Provider Support Table — mise à jour](#17-provider-support-table--mise-à-jour)
18. [Tests Pest — intégration dans la suite existante](#18-tests-pest--intégration-dans-la-suite-existante)
19. [CI/CD — GitHub Actions fork](#19-cicd--github-actions-fork)
20. [Documentation communauté](#20-documentation-communauté)
21. [Stratégie PR upstream vers `laravel/ai`](#21-stratégie-pr-upstream-vers-laravelai)
22. [Roadmap fork](#22-roadmap-fork)

---

## 1. Contexte & stratégie

### Pourquoi forker plutôt que seulement un package standalone ?

Le Plan A (`martin-lechene/laravel-euria`) est un package indépendant — idéal pour des projets qui ne veulent pas `laravel/ai`. Le Plan B est différent dans sa nature : il vise à **intégrer Euria directement dans l'écosystème `laravel/ai`** de sorte que les utilisateurs existants du SDK officiel puissent simplement écrire :

```php
// config/ai.php
'providers' => [
    'infomaniak' => [
        'driver' => 'infomaniak',
        'key'    => env('INFOMANIAK_API_TOKEN'),
        'url'    => env('INFOMANIAK_AI_BASE_URL', 'https://api.infomaniak.com/1/ai'),
    ],
],
```

Et ensuite utiliser exactement la même API Laravel :

```php
use Laravel\Ai\Enums\Lab;

// Avec le driver Infomaniak natif dans laravel/ai
$response = (new MyAgent)->prompt(
    'Analyse ce document...',
    provider: Lab::Infomaniak,
    model: 'mixtral',
);
```

### Stratégie de fork publique

Le fork sera maintenu sous `martin-lechene/laravel-ai` (ou un nom choisi) avec :
- Un `README` qui explique clairement que c'est un fork de `laravel/ai` avec le driver Infomaniak en plus
- Synchronisation régulière avec l'upstream `laravel/ai`
- Une PR upstream ouverte vers `laravel/ai` officiel dès que le driver est stable
- Documentation dédiée à Euria dans le fork

---

## 2. Analyse de l'architecture `laravel/ai`

Avant de modifier quoi que ce soit, voici la structure exacte du repo `laravel/ai` à comprendre :

### Structure `src/` de `laravel/ai` (branche 0.x)

```
src/
├── AiManager.php              # Driver Manager principal (extends Manager)
├── AiServiceProvider.php      # Service Provider
├── Promptable.php             # Trait (prompt, stream, queue, broadcastOnQueue)
│
├── Concerns/
│   └── RemembersConversations.php
│
├── Contracts/
│   ├── Agent.php
│   ├── Conversational.php
│   ├── HasStructuredOutput.php
│   └── HasTools.php
│
├── Drivers/                   # ← C'est ici qu'on ajoute le driver Infomaniak
│   ├── AnthropicDriver.php
│   ├── AzureOpenAiDriver.php
│   ├── CohereDriver.php
│   ├── DeepSeekDriver.php
│   ├── ElevenLabsDriver.php
│   ├── GeminiDriver.php
│   ├── GroqDriver.php
│   ├── JinaDriver.php
│   ├── MistralDriver.php
│   ├── OllamaDriver.php
│   ├── OpenAiDriver.php
│   ├── OpenRouterDriver.php
│   ├── VoyageAiDriver.php
│   └── XAiDriver.php
│   └── [+ InfomaniakDriver.php]  ← NOUVEAU
│
├── Enums/
│   └── Lab.php                # ← Ajouter Lab::Infomaniak
│
├── Events/
│   ├── RequestSent.php
│   ├── ResponseReceived.php
│   └── TokensConsumed.php
│
├── Messages/
│   ├── Message.php
│   └── MessageCollection.php
│
├── Responses/
│   ├── AgentResponse.php
│   ├── AudioResponse.php
│   ├── EmbeddingResponse.php
│   ├── ImageResponse.php
│   ├── StreamedAgentResponse.php
│   └── StructuredAgentResponse.php
│
└── Tools/
    └── Tool.php
```

### Comment `laravel/ai` résout les drivers

Dans `AiManager.php`, chaque driver est résolu via une méthode `create{DriverName}Driver()` :

```php
// laravel/ai — AiManager.php (simplifié)
protected function createOpenaiDriver(array $config): OpenAiDriver
{
    return new OpenAiDriver(
        apiKey:  $config['key'],
        baseUrl: $config['url'] ?? null,
    );
}

protected function createAnthropicDriver(array $config): AnthropicDriver
{
    return new AnthropicDriver(
        apiKey:  $config['key'],
        baseUrl: $config['url'] ?? null,
    );
}

// → On ajoute :
protected function createInfomaniakDriver(array $config): InfomaniakDriver
{
    return new InfomaniakDriver(
        apiKey:  $config['key'],
        baseUrl: $config['url'] ?? 'https://api.infomaniak.com/1/ai',
    );
}
```

### Comment les drivers sont structurés

Chaque driver dans `laravel/ai` implémente plusieurs méthodes selon les capacités qu'il supporte :

```php
interface DriverContract {
    // Text (obligatoire)
    public function complete(Request $request): Response;
    public function stream(Request $request): StreamedResponse;

    // Optionnel selon capacités
    public function embed(EmbeddingRequest $request): EmbeddingResponse;
    public function generateImage(ImageRequest $request): ImageResponse;
    public function transcribe(AudioRequest $request): AudioResponse;
}
```

L'avantage énorme de l'API Infomaniak est sa **compatibilité OpenAI** — le format de requête/réponse est identique. On peut donc réutiliser une grande partie de la logique de `OpenAiDriver` et juste surcharger l'URL de base et l'auth.

---

## 3. Différences Plan A vs Plan B

| Aspect | Plan A (standalone) | Plan B (fork) |
|---|---|---|
| Package name | `martin-lechene/laravel-euria` | `martin-lechene/laravel-ai` (fork) |
| Dépendance | Aucune dep sur `laravel/ai` | Fork de `laravel/ai` |
| Usage | `Euria::text(...)` | `Ai::provider('infomaniak')->text(...)` |
| Compatibilité | Package indépendant | Compatible avec tout l'écosystème `laravel/ai` |
| Agents | Système propre cloné | Système natif `laravel/ai` avec provider Infomaniak |
| Maintenance | 100% autonome | Synchroniser avec upstream régulièrement |
| PR upstream | N/A | Oui, contribuer à `laravel/ai` officiel |
| Audience | Devs qui veulent que Euria | Devs déjà sur `laravel/ai` |

---

## 4. Étape 0 — Forker le repo

### Commandes shell

```bash
# 1. Forker sur GitHub (via UI ou CLI)
gh repo fork laravel/ai --org martin-lechene --remote --clone

# 2. Renommer le remote upstream
cd ai
git remote rename origin fork
git remote add upstream https://github.com/laravel/ai.git

# 3. Créer la branche de travail
git checkout -b feature/infomaniak-driver

# 4. (Optionnel) Renommer le package dans composer.json
# "name": "martin-lechene/laravel-ai"
```

### Politique de synchronisation upstream

```bash
# Synchroniser régulièrement avec laravel/ai
git fetch upstream
git rebase upstream/0.x
git push fork feature/infomaniak-driver --force-with-lease
```

Recommandation : synchroniser au minimum à chaque release de `laravel/ai`.

---

## 5. Mapping de l'architecture existante

### Fichiers à NE PAS modifier

Ces fichiers appartiennent à `laravel/ai` et doivent rester intacts pour faciliter la sync upstream :

- Tous les drivers existants (`AnthropicDriver`, `OpenAiDriver`, etc.)
- `AiServiceProvider.php` — sauf pour enregistrer le nouveau driver
- `Promptable.php` — trait commun
- `Contracts/*.php` — interfaces communes
- `Responses/*.php` — réponses communes
- `Messages/*.php`

### Fichiers à MODIFIER (diff minimal)

| Fichier | Modification |
|---|---|
| `src/AiManager.php` | Ajouter `createInfomaniakDriver()` |
| `src/Enums/Lab.php` | Ajouter `case Infomaniak = 'infomaniak'` |
| `config/ai.php` | Ajouter le bloc provider `infomaniak` |
| `README.md` | Documenter Euria/Infomaniak |

### Fichiers à CRÉER (nouveaux)

| Fichier | Contenu |
|---|---|
| `src/Drivers/InfomaniakDriver.php` | Driver principal Infomaniak |
| `src/Drivers/Infomaniak/TextHandler.php` | Handler LLM text |
| `src/Drivers/Infomaniak/StreamHandler.php` | Handler streaming SSE |
| `src/Drivers/Infomaniak/EmbeddingHandler.php` | Handler embeddings |
| `src/Drivers/Infomaniak/ImageHandler.php` | Handler SDXL/Flux |
| `src/Drivers/Infomaniak/AudioHandler.php` | Handler Whisper STT |
| `tests/Drivers/InfomaniakDriverTest.php` | Tests unitaires |
| `tests/Feature/InfomaniakIntegrationTest.php` | Tests feature |
| `docs/providers/infomaniak.md` | Documentation |

---

## 6. Fichiers à créer — Driver Infomaniak

### Vue d'ensemble du dossier driver

```
src/Drivers/
├── [drivers existants laravel/ai...]
│
└── InfomaniakDriver.php          # Point d'entrée du driver
    │
    └── Infomaniak/               # Handlers spécialisés
        ├── InfomaniakClient.php  # Client HTTP Guzzle
        ├── TextHandler.php
        ├── StreamHandler.php
        ├── EmbeddingHandler.php
        ├── ImageHandler.php
        └── AudioHandler.php
```

---

## 7. Structure complète du fork

```
martin-lechene/laravel-ai (fork)
│
├── [Tous les fichiers laravel/ai intacts]
│
├── src/
│   ├── AiManager.php                 ← MODIFIÉ (+createInfomaniakDriver)
│   ├── Enums/
│   │   └── Lab.php                   ← MODIFIÉ (+Infomaniak case)
│   │
│   └── Drivers/
│       ├── [drivers existants...]
│       │
│       ├── InfomaniakDriver.php      ← NOUVEAU
│       └── Infomaniak/
│           ├── InfomaniakClient.php  ← NOUVEAU
│           ├── TextHandler.php       ← NOUVEAU
│           ├── StreamHandler.php     ← NOUVEAU
│           ├── EmbeddingHandler.php  ← NOUVEAU
│           ├── ImageHandler.php      ← NOUVEAU
│           └── AudioHandler.php      ← NOUVEAU
│
├── config/
│   └── ai.php                        ← MODIFIÉ (+infomaniak provider)
│
├── tests/
│   ├── [tests existants laravel/ai...]
│   │
│   ├── Drivers/
│   │   └── InfomaniakDriverTest.php  ← NOUVEAU
│   └── Feature/
│       └── InfomaniakIntegrationTest.php ← NOUVEAU
│
└── docs/
    └── providers/
        └── infomaniak.md             ← NOUVEAU
```

---

## 8. Implémentation — `InfromaniakDriver` (Text)

### `InfomaniakClient.php` — Client HTTP partagé

```php
// src/Drivers/Infomaniak/InfomaniakClient.php
namespace Laravel\Ai\Drivers\Infomaniak;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

class InfomaniakClient
{
    protected Client $guzzle;

    public function __construct(
        protected string $apiKey,
        protected string $baseUrl = 'https://api.infomaniak.com/1/ai',
        protected int    $timeout = 60,
    ) {
        $this->guzzle = new Client([
            'base_uri' => rtrim($this->baseUrl, '/'),
            'timeout'  => $this->timeout,
            'headers'  => [
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ]);
    }

    public function post(string $endpoint, array $payload): array
    {
        $response = $this->guzzle->post($endpoint, [
            RequestOptions::JSON => $payload,
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    public function postStream(string $endpoint, array $payload): \Generator
    {
        $response = $this->guzzle->post($endpoint, [
            RequestOptions::JSON   => array_merge($payload, ['stream' => true]),
            RequestOptions::STREAM => true,
        ]);

        $body = $response->getBody();

        while (!$body->eof()) {
            $line = trim($body->read(4096));
            if (str_starts_with($line, 'data: ')) {
                $json = substr($line, 6);
                if ($json === '[DONE]') break;
                $data = json_decode($json, true);
                if ($data) yield $data;
            }
        }
    }

    public function postMultipart(string $endpoint, array $multipart): array
    {
        $response = $this->guzzle->post($endpoint, [
            RequestOptions::MULTIPART => $multipart,
            RequestOptions::HEADERS   => [
                'Authorization' => 'Bearer '.$this->apiKey,
                // Content-Type multipart géré par Guzzle
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }
}
```

### `InfomaniakDriver.php` — Driver principal

```php
// src/Drivers/InfomaniakDriver.php
namespace Laravel\Ai\Drivers;

use Laravel\Ai\Drivers\Infomaniak\InfomaniakClient;
use Laravel\Ai\Drivers\Infomaniak\TextHandler;
use Laravel\Ai\Drivers\Infomaniak\StreamHandler;
use Laravel\Ai\Drivers\Infomaniak\EmbeddingHandler;
use Laravel\Ai\Drivers\Infomaniak\ImageHandler;
use Laravel\Ai\Drivers\Infomaniak\AudioHandler;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Responses\EmbeddingResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\AudioResponse;

class InfomaniakDriver
{
    protected InfomaniakClient  $client;
    protected TextHandler       $text;
    protected StreamHandler     $stream;
    protected EmbeddingHandler  $embedding;
    protected ImageHandler      $image;
    protected AudioHandler      $audio;

    public function __construct(
        string  $apiKey,
        ?string $baseUrl = null,
        int     $timeout = 60,
    ) {
        $this->client    = new InfomaniakClient($apiKey, $baseUrl ?? 'https://api.infomaniak.com/1/ai', $timeout);
        $this->text      = new TextHandler($this->client);
        $this->stream    = new StreamHandler($this->client);
        $this->embedding = new EmbeddingHandler($this->client);
        $this->image     = new ImageHandler($this->client);
        $this->audio     = new AudioHandler($this->client);
    }

    /**
     * Complétion LLM text (chat).
     */
    public function complete(array $messages, string $model, array $options = []): AgentResponse
    {
        return $this->text->complete($messages, $model, $options);
    }

    /**
     * Streaming SSE.
     */
    public function stream(array $messages, string $model, array $options = []): StreamedAgentResponse
    {
        return $this->stream->stream($messages, $model, $options);
    }

    /**
     * Embeddings vectoriels.
     */
    public function embed(string|array $input, string $model, array $options = []): EmbeddingResponse
    {
        return $this->embedding->embed($input, $model, $options);
    }

    /**
     * Génération d'images (SDXL / Flux).
     */
    public function generateImage(string $prompt, string $model, array $options = []): ImageResponse
    {
        return $this->image->generate($prompt, $model, $options);
    }

    /**
     * Transcription audio (Whisper).
     */
    public function transcribe(string $audioPath, string $model, array $options = []): AudioResponse
    {
        return $this->audio->transcribe($audioPath, $model, $options);
    }
}
```

### `TextHandler.php`

```php
// src/Drivers/Infomaniak/TextHandler.php
namespace Laravel\Ai\Drivers\Infomaniak;

use Laravel\Ai\Events\RequestSent;
use Laravel\Ai\Events\ResponseReceived;
use Laravel\Ai\Events\TokensConsumed;
use Laravel\Ai\Responses\AgentResponse;

class TextHandler
{
    public function __construct(
        protected InfomaniakClient $client,
    ) {}

    public function complete(array $messages, string $model, array $options = []): AgentResponse
    {
        $payload = array_merge([
            'model'    => $model,
            'messages' => $messages,
        ], $options);

        // Structured output
        if (isset($options['json_schema'])) {
            $payload['response_format'] = [
                'type'        => 'json_schema',
                'json_schema' => ['schema' => $options['json_schema']],
            ];
            unset($payload['json_schema']);
        }

        // Function calling
        if (isset($options['tools'])) {
            $payload['tools'] = $options['tools'];
        }

        event(new RequestSent('infomaniak', '/openai/chat/completions', $payload));

        $data = $this->client->post('/openai/chat/completions', $payload);

        event(new ResponseReceived('infomaniak', $data));

        if (isset($data['usage'])) {
            event(new TokensConsumed(
                provider:         'infomaniak',
                model:            $model,
                promptTokens:     $data['usage']['prompt_tokens'] ?? 0,
                completionTokens: $data['usage']['completion_tokens'] ?? 0,
            ));
        }

        return new AgentResponse(
            text:         $data['choices'][0]['message']['content'] ?? '',
            finishReason: $data['choices'][0]['finish_reason'] ?? 'stop',
            toolCalls:    $data['choices'][0]['message']['tool_calls'] ?? [],
            usage:        $data['usage'] ?? [],
            raw:          $data,
        );
    }
}
```

---

## 9. Implémentation — Streaming Infomaniak

### `StreamHandler.php`

```php
// src/Drivers/Infomaniak/StreamHandler.php
namespace Laravel\Ai\Drivers\Infomaniak;

use Laravel\Ai\Responses\StreamedAgentResponse;

class StreamHandler
{
    public function __construct(
        protected InfomaniakClient $client,
    ) {}

    public function stream(array $messages, string $model, array $options = []): StreamedAgentResponse
    {
        $payload = array_merge([
            'model'    => $model,
            'messages' => $messages,
        ], $options);

        $generator = $this->client->postStream('/openai/chat/completions', $payload);

        return new StreamedAgentResponse(function () use ($generator) {
            foreach ($generator as $chunk) {
                $content = $chunk['choices'][0]['delta']['content'] ?? null;
                if ($content !== null) {
                    yield $content;
                }
            }
        });
    }
}
```

### Usage dans un Agent avec le driver Infomaniak

```php
use Laravel\Ai\Enums\Lab;

// Streaming avec Euria
Route::get('/euria/stream', function () {
    return (new MyAgent)->stream(
        'Explique la souveraineté numérique en 3 points.',
        provider: Lab::Infomaniak,
        model: 'mixtral',
    );
});
```

---

## 10. Implémentation — Embeddings Infomaniak

### `EmbeddingHandler.php`

```php
// src/Drivers/Infomaniak/EmbeddingHandler.php
namespace Laravel\Ai\Drivers\Infomaniak;

use Laravel\Ai\Responses\EmbeddingResponse;

class EmbeddingHandler
{
    public function __construct(
        protected InfomaniakClient $client,
    ) {}

    public function embed(string|array $input, string $model, array $options = []): EmbeddingResponse
    {
        $data = $this->client->post('/openai/embeddings', array_merge([
            'input' => $input,
            'model' => $model,
        ], $options));

        return new EmbeddingResponse(
            embeddings: array_map(fn ($item) => $item['embedding'], $data['data'] ?? []),
            usage:      $data['usage'] ?? [],
            raw:        $data,
        );
    }
}
```

### Usage

```php
use Laravel\Ai\Enums\Lab;

$embedding = AI::provider(Lab::Infomaniak)
    ->embed('La souveraineté des données est fondamentale.');

$vector = $embedding->first(); // tableau de floats
```

---

## 11. Implémentation — Images Infomaniak (SDXL/Flux)

### `ImageHandler.php`

```php
// src/Drivers/Infomaniak/ImageHandler.php
namespace Laravel\Ai\Drivers\Infomaniak;

use Laravel\Ai\Responses\ImageResponse;

class ImageHandler
{
    public function __construct(
        protected InfomaniakClient $client,
    ) {}

    public function generate(string $prompt, string $model, array $options = []): ImageResponse
    {
        $data = $this->client->post('/openai/images/generations', array_merge([
            'prompt' => $prompt,
            'model'  => $model,
            'n'      => $options['n'] ?? 1,
            'size'   => $options['size'] ?? 'square',
        ], $options));

        return new ImageResponse(
            images: array_map(
                fn ($item) => $item['url'] ?? $item['b64_json'],
                $data['data'] ?? []
            ),
            raw: $data,
        );
    }
}
```

### Usage dans `laravel/ai` avec provider Infomaniak

```php
use Laravel\Ai\Enums\Lab;

// Via la Facade AI::
$image = AI::provider(Lab::Infomaniak)
    ->model('sdxl')
    ->generateImage('Un paysage alpin suisse en hiver, style réaliste');

echo $image->first(); // URL de l'image générée

// Avec Flux
$image = AI::provider(Lab::Infomaniak)
    ->model('flux')
    ->generateImage('Portrait d\'un professionnel, fond blanc', ['n' => 2]);

foreach ($image->all() as $url) {
    echo $url;
}
```

---

## 12. Implémentation — Audio Transcription (Whisper)

### `AudioHandler.php`

```php
// src/Drivers/Infomaniak/AudioHandler.php
namespace Laravel\Ai\Drivers\Infomaniak;

use Laravel\Ai\Responses\AudioResponse;

class AudioHandler
{
    public function __construct(
        protected InfomaniakClient $client,
    ) {}

    public function transcribe(string $audioPath, string $model, array $options = []): AudioResponse
    {
        $multipart = [
            ['name' => 'model',    'contents' => $model],
            ['name' => 'file',     'contents' => fopen($audioPath, 'r'), 'filename' => basename($audioPath)],
        ];

        if (isset($options['language'])) {
            $multipart[] = ['name' => 'language', 'contents' => $options['language']];
        }

        if (isset($options['prompt'])) {
            $multipart[] = ['name' => 'prompt', 'contents' => $options['prompt']];
        }

        $data = $this->client->postMultipart('/openai/audio/transcriptions', $multipart);

        return new AudioResponse(
            text:     $data['text'] ?? '',
            language: $data['language'] ?? null,
            duration: $data['duration'] ?? null,
            raw:      $data,
        );
    }
}
```

### Usage

```php
use Laravel\Ai\Enums\Lab;

$transcription = AI::provider(Lab::Infomaniak)
    ->model('whisper-1')
    ->transcribe('/storage/audio/meeting.mp3', ['language' => 'fr']);

echo $transcription->text;
echo $transcription->language; // 'fr'
echo $transcription->duration; // 142.3 (secondes)
```

---

## 13. Implémentation — Function Calling

Le Function Calling dans `laravel/ai` passe par le système de `Tools`. Le driver Infomaniak supporte la même interface OpenAI pour les tools — le payload est identique.

### Passage des tools au driver Infomaniak

```php
// Dans TextHandler.php, les tools sont passés directement dans le payload :

if (isset($options['tools'])) {
    $payload['tools']       = $options['tools'];
    $payload['tool_choice'] = $options['tool_choice'] ?? 'auto';
}
```

### Exemple d'Agent avec tools via le driver Infomaniak

```php
// app/Ai/Agents/InfomaniakAgent.php
namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class InfomaniakAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Tu es un assistant utilisant l\'infrastructure souveraine Infomaniak.';
    }

    public function tools(): iterable
    {
        return [
            new \App\Ai\Tools\SearchDatabase,
            new \App\Ai\Tools\FetchWeather,
        ];
    }

    // Configuration du provider par défaut pour cet agent
    public function provider(): string
    {
        return Lab::Infomaniak->value;
    }

    public function model(): string
    {
        return 'mixtral';
    }
}
```

### Boucle de tool calling multi-turn

```php
// Dans AgentRunner (laravel/ai gère déjà ça)
// Le driver Infomaniak retourne les tool_calls dans AgentResponse::toolCalls()
// Le runner de laravel/ai les résout automatiquement

$response = (new InfomaniakAgent)->prompt('Quel temps fait-il à Genève ?');
// → laravel/ai détecte le tool call
// → exécute FetchWeather::handle(['city' => 'Genève'])
// → renvoie le résultat au modèle
// → le modèle génère la réponse finale
echo $response; // "À Genève, il fait actuellement 18°C et ensoleillé."
```

---

## 14. Enregistrement du driver dans le Manager

### Modification de `AiManager.php`

```php
// src/AiManager.php — MODIFICATION MINIMALE

namespace Laravel\Ai;

use Illuminate\Support\Manager;
use Laravel\Ai\Drivers\InfomaniakDriver;
// ... autres imports existants

class AiManager extends Manager
{
    // [Méthodes existantes non modifiées...]

    /**
     * Create an Infomaniak AI Services (Euria) driver instance.
     *
     * @param  array<string, mixed>  $config
     */
    protected function createInfomaniakDriver(array $config): InfomaniakDriver
    {
        return new InfomaniakDriver(
            apiKey:  $config['key'] ?? throw new \InvalidArgumentException('Infomaniak API token is required.'),
            baseUrl: $config['url'] ?? 'https://api.infomaniak.com/1/ai',
            timeout: $config['timeout'] ?? 60,
        );
    }

    // [Reste des méthodes non modifiées...]
}
```

**Note** : le Manager de Laravel résout automatiquement `createInfomaniakDriver()` quand `driver` vaut `'infomaniak'` dans la config — pas d'autre enregistrement nécessaire.

---

## 15. Configuration `config/ai.php` — ajout Infomaniak

```php
// config/ai.php — AJOUT du bloc Infomaniak uniquement

return [

    // [Config existante laravel/ai non modifiée...]

    'providers' => [

        // [Providers existants non modifiés...]

        /*
        |----------------------------------------------------------------------
        | Infomaniak AI Services (Euria)
        |----------------------------------------------------------------------
        | Plateforme souveraine européenne hébergée en Suisse.
        | API compatible OpenAI. Modèles : Mixtral, Llama, DeepSeek, Whisper,
        | SDXL, Flux.
        | Créer un token sur : https://manager.infomaniak.com
        */
        'infomaniak' => [
            'driver'  => 'infomaniak',
            'key'     => env('INFOMANIAK_API_TOKEN'),
            'url'     => env('INFOMANIAK_AI_BASE_URL', 'https://api.infomaniak.com/1/ai'),
            'timeout' => env('INFOMANIAK_TIMEOUT', 60),
        ],

    ],

    'defaults' => [
        // [Défauts existants non modifiés...]
        // Optionnel : utiliser Infomaniak par défaut
        // 'provider' => env('AI_PROVIDER', 'infomaniak'),
    ],
];
```

### Variables `.env` à ajouter

```dotenv
# Infomaniak AI Services (Euria)
INFOMANIAK_API_TOKEN=your_oauth2_token_here
INFOMANIAK_AI_BASE_URL=https://api.infomaniak.com/1/ai
INFOMANIAK_TIMEOUT=60

# Modèles par défaut Infomaniak
INFOMANIAK_TEXT_MODEL=mixtral
INFOMANIAK_IMAGE_MODEL=sdxl
INFOMANIAK_AUDIO_MODEL=whisper-1
INFOMANIAK_EMBEDDING_MODEL=text-embedding-3-small
```

---

## 16. Enum `Lab` — ajout de la constante `Infomaniak`

```php
// src/Enums/Lab.php — AJOUT D'UN CASE UNIQUEMENT

namespace Laravel\Ai\Enums;

enum Lab: string
{
    // [Cases existants non modifiés...]
    case Anthropic  = 'anthropic';
    case Azure      = 'azure';
    case Cohere     = 'cohere';
    case DeepSeek   = 'deepseek';
    case ElevenLabs = 'elevenlabs';
    case Gemini     = 'gemini';
    case Groq       = 'groq';
    case Jina       = 'jina';
    case Mistral    = 'mistral';
    case Ollama     = 'ollama';
    case OpenAI     = 'openai';
    case OpenRouter = 'openrouter';
    case VoyageAI   = 'voyageai';
    case XAI        = 'xai';

    // ← NOUVEAU
    case Infomaniak = 'infomaniak';
}
```

### Usage dans le code

```php
use Laravel\Ai\Enums\Lab;

// Prompt avec provider Infomaniak explicite
$response = (new MyAgent)->prompt(
    'Analyse ce document...',
    provider: Lab::Infomaniak,
    model: 'mixtral',
);

// Ou via la config si Infomaniak est le provider par défaut
$response = (new MyAgent)->prompt('Analyse ce document...');
```

---

## 17. Provider Support Table — mise à jour

Le tableau de support dans la doc `laravel/ai` doit être mis à jour pour inclure Infomaniak :

```markdown
| Feature      | Providers |
|---|---|
| Text         | OpenAI, Anthropic, Gemini, Azure, Groq, xAI, DeepSeek, Mistral, Ollama, **Infomaniak** |
| Images       | OpenAI, Gemini, xAI, **Infomaniak (SDXL, Flux)** |
| TTS          | OpenAI, ElevenLabs |
| STT          | OpenAI, ElevenLabs, Mistral, **Infomaniak (Whisper)** |
| Embeddings   | OpenAI, Gemini, Azure, Cohere, Mistral, Jina, VoyageAI, **Infomaniak** |
| Reranking    | Cohere, Jina |
| Files        | OpenAI, Anthropic, Gemini |
```

---

## 18. Tests Pest — intégration dans la suite existante

### `tests/Drivers/InfomaniakDriverTest.php`

```php
<?php

use Laravel\Ai\Drivers\InfomaniakDriver;
use Laravel\Ai\Drivers\Infomaniak\InfomaniakClient;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\EmbeddingResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Enums\Lab;

beforeEach(function () {
    $this->driver = new InfomaniakDriver(
        apiKey:  'test-token',
        baseUrl: 'https://api.infomaniak.com/1/ai',
    );
});

// --- Text ---

it('construit le driver avec les bons paramètres', function () {
    expect($this->driver)->toBeInstanceOf(InfomaniakDriver::class);
});

it('complete retourne un AgentResponse', function () {
    // Mock HTTP via Guzzle MockHandler
    $mockClient = Mockery::mock(InfomaniakClient::class);
    $mockClient->shouldReceive('post')
        ->with('/openai/chat/completions', Mockery::any())
        ->andReturn([
            'model'   => 'mixtral',
            'choices' => [['message' => ['content' => 'Bonjour !'], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ]);

    $response = (new \Laravel\Ai\Drivers\Infomaniak\TextHandler($mockClient))
        ->complete([['role' => 'user', 'content' => 'Dis bonjour']], 'mixtral');

    expect($response)->toBeInstanceOf(AgentResponse::class)
        ->and($response->text)->toBe('Bonjour !')
        ->and($response->finishReason)->toBe('stop');
});

it('dispatch l\'event TokensConsumed', function () {
    \Illuminate\Support\Facades\Event::fake();

    $mockClient = Mockery::mock(InfomaniakClient::class);
    $mockClient->shouldReceive('post')->andReturn([
        'choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']],
        'usage'   => ['prompt_tokens' => 5, 'completion_tokens' => 10, 'total_tokens' => 15],
    ]);

    (new \Laravel\Ai\Drivers\Infomaniak\TextHandler($mockClient))
        ->complete([['role' => 'user', 'content' => 'Test']], 'mixtral');

    \Illuminate\Support\Facades\Event::assertDispatched(\Laravel\Ai\Events\TokensConsumed::class);
});

// --- Embeddings ---

it('embed retourne un EmbeddingResponse', function () {
    $mockClient = Mockery::mock(InfomaniakClient::class);
    $mockClient->shouldReceive('post')
        ->with('/openai/embeddings', Mockery::any())
        ->andReturn([
            'data'  => [['embedding' => array_fill(0, 1536, 0.1)]],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ]);

    $response = (new \Laravel\Ai\Drivers\Infomaniak\EmbeddingHandler($mockClient))
        ->embed('Texte de test', 'text-embedding-3-small');

    expect($response)->toBeInstanceOf(EmbeddingResponse::class)
        ->and($response->first())->toHaveCount(1536);
});

// --- Images ---

it('generateImage retourne un ImageResponse pour SDXL', function () {
    $mockClient = Mockery::mock(InfomaniakClient::class);
    $mockClient->shouldReceive('post')
        ->with('/openai/images/generations', Mockery::any())
        ->andReturn([
            'data' => [['url' => 'https://cdn.infomaniak.com/image-123.png']],
        ]);

    $response = (new \Laravel\Ai\Drivers\Infomaniak\ImageHandler($mockClient))
        ->generate('Un paysage suisse', 'sdxl');

    expect($response)->toBeInstanceOf(ImageResponse::class)
        ->and($response->first())->toBe('https://cdn.infomaniak.com/image-123.png');
});

// --- Audio ---

it('transcribe retourne un AudioResponse', function () {
    $mockClient = Mockery::mock(InfomaniakClient::class);
    $mockClient->shouldReceive('postMultipart')
        ->with('/openai/audio/transcriptions', Mockery::any())
        ->andReturn(['text' => 'Bonjour, voici ma transcription.', 'language' => 'fr']);

    $tmpFile = tempnam(sys_get_temp_dir(), 'audio') . '.mp3';
    file_put_contents($tmpFile, 'fake audio content');

    $response = (new \Laravel\Ai\Drivers\Infomaniak\AudioHandler($mockClient))
        ->transcribe($tmpFile, 'whisper-1', ['language' => 'fr']);

    expect($response)->toBeInstanceOf(AudioResponse::class)
        ->and($response->text)->toBe('Bonjour, voici ma transcription.')
        ->and($response->language)->toBe('fr');

    unlink($tmpFile);
});

// --- Auth ---

it('utilise le Bearer token dans les headers', function () {
    $client = new InfomaniakClient('tok-test-123', 'https://api.infomaniak.com/1/ai');
    // Vérifier que le client injecte bien le token
    expect($client)->toBeInstanceOf(InfomaniakClient::class);
    // Un test d'intégration réel nécessite un mock Guzzle handler
});
```

### `tests/Feature/InfomaniakIntegrationTest.php`

```php
<?php

use Laravel\Ai\Enums\Lab;

// Tests feature avec AI::fake() de laravel/ai
// Ces tests utilisent le système de fake existant de laravel/ai

it('peut utiliser Infomaniak via Lab::Infomaniak', function () {
    // Avec le fake de laravel/ai
    \Laravel\Ai\Facades\AI::fake([
        \Laravel\Ai\Responses\AgentResponse::make(text: 'Réponse Euria'),
    ]);

    // Un agent configuré avec Infomaniak comme provider
    $response = \Laravel\Ai\Facades\AI::provider(Lab::Infomaniak)
        ->complete([['role' => 'user', 'content' => 'Test Euria']]);

    expect($response->text)->toBe('Réponse Euria');
});

it('Infomaniak est reconnu comme provider valide dans l\'enum Lab', function () {
    expect(Lab::Infomaniak->value)->toBe('infomaniak');
    expect(Lab::from('infomaniak'))->toBe(Lab::Infomaniak);
});
```

---

## 19. CI/CD — GitHub Actions fork

### `.github/workflows/tests.yml`

Le fork reprend exactement la matrice CI de `laravel/ai` et y ajoute les combinaisons pour PHP 8.1 :

```yaml
name: Tests (Fork + Infomaniak Driver)

on:
  push:
    branches: [main, 0.x, feature/infomaniak-driver]
  pull_request:
    branches: [main, 0.x]

jobs:
  test:
    name: PHP ${{ matrix.php }} / Laravel ${{ matrix.laravel }}
    runs-on: ubuntu-latest

    strategy:
      fail-fast: false
      matrix:
        php:     ['8.1', '8.2', '8.3', '8.4']
        laravel: ['10.*', '11.*', '12.*', '13.*']
        exclude:
          - php: '8.1'
            laravel: '13.*'

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: mbstring, json, curl, fileinfo
          coverage: xdebug

      - name: Install dependencies
        run: |
          composer require "laravel/framework:${{ matrix.laravel }}" \
            --no-interaction --no-update
          composer update --prefer-dist --no-interaction

      - name: Laravel Pint
        run: vendor/bin/pint --test

      - name: PHPStan
        run: vendor/bin/phpstan analyse --memory-limit=512M

      - name: Pest
        run: vendor/bin/pest --coverage --min=80

  sync-check:
    name: Check upstream sync
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0

      - name: Check if behind upstream
        run: |
          git remote add upstream https://github.com/laravel/ai.git
          git fetch upstream
          BEHIND=$(git rev-list HEAD..upstream/0.x --count)
          echo "Commits behind upstream: $BEHIND"
          if [ "$BEHIND" -gt 10 ]; then
            echo "⚠️ Fork is $BEHIND commits behind upstream laravel/ai. Consider rebasing."
          fi
```

### `.github/workflows/release.yml`

```yaml
name: Release Fork

on:
  push:
    tags:
      - 'v*.*.*-euria'   # Tags spécifiques fork: ex v0.1.0-euria

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Create Release
        uses: softprops/action-gh-release@v1
        with:
          generate_release_notes: true
          body: |
            ## Fork martin-lechene/laravel-ai
            Ce release ajoute le driver Infomaniak (Euria) à laravel/ai.
            Basé sur laravel/ai ${{ github.ref_name }}.
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

---

## 20. Documentation communauté

### README du fork — sections spécifiques à ajouter

```markdown
## Fork Notice

Ce repository est un fork de [laravel/ai](https://github.com/laravel/ai) avec l'ajout 
du driver **Infomaniak AI Services (Euria)** — plateforme souveraine hébergée en Suisse.

> Pour un package standalone dédié Euria, voir [martin-lechene/laravel-euria](https://github.com/martin-lechene/laravel-euria).

## Ajout : Provider Infomaniak (Euria)

### Installation

\`\`\`bash
composer require martin-lechene/laravel-ai
\`\`\`

### Configuration

\`\`\`dotenv
INFOMANIAK_API_TOKEN=your_oauth2_token
INFOMANIAK_AI_BASE_URL=https://api.infomaniak.com/1/ai
\`\`\`

\`\`\`php
// config/ai.php
'providers' => [
    'infomaniak' => [
        'driver' => 'infomaniak',
        'key'    => env('INFOMANIAK_API_TOKEN'),
        'url'    => env('INFOMANIAK_AI_BASE_URL'),
    ],
],
\`\`\`

### Utilisation

\`\`\`php
use Laravel\Ai\Enums\Lab;

// Complétion LLM
$response = (new MyAgent)->prompt('Bonjour !', provider: Lab::Infomaniak, model: 'mixtral');

// Embeddings
$embedding = AI::provider(Lab::Infomaniak)->embed('Mon texte à vectoriser');

// Génération d'image
$image = AI::provider(Lab::Infomaniak)->model('sdxl')->generateImage('Un lac suisse en été');

// Transcription Whisper
$text = AI::provider(Lab::Infomaniak)->model('whisper-1')->transcribe('/audio.mp3');
\`\`\`

### Modèles disponibles

| Capacité    | Modèles Infomaniak          |
|---|---|
| LLM Text    | `mixtral`, `llama-3`, `deepseek`, `mistral-7b` |
| Embeddings  | `text-embedding-3-small`    |
| Images      | `sdxl`, `flux`              |
| Audio STT   | `whisper-1`                 |

### Différences avec les autres providers

- **Souveraineté** : données hébergées en Suisse, conformité FADP + RGPD
- **Open source** : modèles open source uniquement (Mistral, Llama, DeepSeek)
- **Facturation** : par token LLM — taux compétitifs
- **Rate limit** : 60 req/min
- **API** : compatible OpenAI (même format de requête)
```

### `docs/providers/infomaniak.md`

```markdown
# Provider Infomaniak (Euria)

## Présentation

Infomaniak AI Services est une plateforme d'IA souveraine hébergée en Suisse, offrant :
- Des modèles open source de pointe (Mixtral, Llama 3, DeepSeek, Whisper, SDXL, Flux)
- Une conformité stricte FADP (loi suisse) et RGPD
- Aucun usage de vos données pour entraîner des modèles
- Infrastructure 100% énergies renouvelables

## Obtenir un token API

1. Connectez-vous à [manager.infomaniak.com](https://manager.infomaniak.com)
2. Accédez à **AI Services**
3. Créez un produit AI Services
4. Générez un token API (OAuth2)
5. Copiez le token dans votre `.env` : `INFOMANIAK_API_TOKEN=...`

## Endpoints

| Capacité    | Endpoint Infomaniak                         |
|---|---|
| Chat/LLM    | `POST /openai/chat/completions`             |
| Embeddings  | `POST /openai/embeddings`                   |
| Images      | `POST /openai/images/generations`           |
| Audio STT   | `POST /openai/audio/transcriptions`         |

## Limites

- **Rate limit** : 60 requêtes/minute
- **Audio** : illimité (Whisper)
- **Images** : 1 à 4 images par génération
- **Documents** : jusqu'à 100 MB (certaines offres : 20 MB)

## Exemples complets

[Voir le dossier `examples/infomaniak/`]
```

---

## 21. Stratégie PR upstream vers `laravel/ai`

### Quand ouvrir la PR ?

Ouvrir la PR upstream uniquement quand :

1. Le driver passe **100% des tests** (PHPStan level 9 + Pest)
2. La documentation est complète
3. Les conventions de code de `laravel/ai` sont respectées (Pint, style, PHPDoc)
4. Au moins une version stable du fork a été utilisée en production

### Template de PR upstream

```markdown
## Add Infomaniak AI Services (Euria) Driver

### What this PR adds

This PR adds a new driver for [Infomaniak AI Services](https://www.infomaniak.com/en/hosting/ai-services) (Euria), 
a sovereign AI platform hosted in Switzerland.

**Capabilities supported:**
- ✅ LLM Text (Chat/Completion) — Mixtral, Llama 3, DeepSeek, Mistral
- ✅ Streaming SSE
- ✅ Embeddings
- ✅ Image Generation (SDXL, Flux)
- ✅ Audio Transcription (Whisper)
- ✅ Function Calling / Tool Use
- ✅ Structured Output (JSON Schema)

**Why Infomaniak?**
- GDPR + FADP compliant, hosted exclusively in Switzerland
- OpenAI-compatible API format
- Growing European developer community
- Ethical, renewable-energy-powered infrastructure

### Changes

- `src/Drivers/InfomaniakDriver.php` (new)
- `src/Drivers/Infomaniak/` (new, 5 handlers)
- `src/Enums/Lab.php` (+1 case)
- `src/AiManager.php` (+1 method)
- `config/ai.php` (+1 provider block)
- `tests/Drivers/InfomaniakDriverTest.php` (new)

### Testing

All existing tests pass. New tests added for all Infomaniak capabilities.

```
composer test
✓ 47 tests, 0 failures
```

### Related

- Infomaniak AI API: https://developer.infomaniak.com/docs/api
- Standalone package: https://github.com/martin-lechene/laravel-euria
```

### Points de friction potentiels avec l'upstream

| Point | Risque | Mitigation |
|---|---|---|
| `Lab::Infomaniak` dans l'enum | Faible — juste un case | Respecter le style existant |
| Dépendance Guzzle dans le driver | Moyen — laravel/ai utilise peut-être un client interne | Adapter au client HTTP natif du package |
| Format de réponse différent | Faible — API OpenAI-compatible | Tests exhaustifs |
| Scope du package | Moyen — les mainteneurs peuvent refuser | Avoir le standalone comme fallback |

---

## 22. Roadmap fork

### Phase 1 — Setup fork (jour 1)

- [ ] Fork GitHub `laravel/ai` → `martin-lechene/laravel-ai`
- [ ] Configurer remote upstream
- [ ] Créer branche `feature/infomaniak-driver`
- [ ] Mettre à jour `composer.json` (name → `martin-lechene/laravel-ai`)
- [ ] GitHub Actions fork (tests.yml + sync-check)

### Phase 2 — Driver core (jours 2–4)

- [ ] Créer `InfomaniakClient.php`
- [ ] Créer `InfomaniakDriver.php`
- [ ] Implémenter `TextHandler.php`
- [ ] Implémenter `StreamHandler.php`
- [ ] Ajouter `Lab::Infomaniak` dans l'enum
- [ ] Enregistrer `createInfomaniakDriver()` dans `AiManager`
- [ ] Mettre à jour `config/ai.php`
- [ ] Tests unitaires TextHandler

### Phase 3 — Toutes les capacités (jours 5–7)

- [ ] `EmbeddingHandler.php` + tests
- [ ] `ImageHandler.php` (SDXL + Flux) + tests
- [ ] `AudioHandler.php` (Whisper) + tests
- [ ] Function Calling end-to-end test
- [ ] Structured Output test
- [ ] PHPStan level 9 passé sur les nouveaux fichiers

### Phase 4 — Docs & communauté (jours 8–10)

- [ ] README fork (section Infomaniak)
- [ ] `docs/providers/infomaniak.md`
- [ ] `CHANGELOG.md` entrée pour le driver Infomaniak
- [ ] Release tag `v0.1.0-euria`
- [ ] PR upstream ouverte sur `laravel/ai`
- [ ] Post communauté (Reddit r/laravel, LaraNews, Twitter/X)

---

## Récapitulatif : différences clés entre les deux fichiers modifiés

### `AiManager.php` — diff minimal

```diff
+ protected function createInfomaniakDriver(array $config): InfomaniakDriver
+ {
+     return new InfomaniakDriver(
+         apiKey:  $config['key'] ?? throw new \InvalidArgumentException('...'),
+         baseUrl: $config['url'] ?? 'https://api.infomaniak.com/1/ai',
+         timeout: $config['timeout'] ?? 60,
+     );
+ }
```

### `Lab.php` — diff minimal

```diff
  enum Lab: string
  {
      // [existants...]
+     case Infomaniak = 'infomaniak';
  }
```

### `config/ai.php` — diff minimal

```diff
  'providers' => [
      // [existants...]
+     'infomaniak' => [
+         'driver' => 'infomaniak',
+         'key'    => env('INFOMANIAK_API_TOKEN'),
+         'url'    => env('INFOMANIAK_AI_BASE_URL', 'https://api.infomaniak.com/1/ai'),
+     ],
  ],
```

**Ces 3 diffs sont exactement ce qui sera proposé en PR upstream.** Tout le reste (les handlers, le client HTTP) est isolé dans `src/Drivers/Infomaniak/` et n'impacte aucun fichier existant.

---

*Fin du Plan B — Fork `martin-lechene/laravel-ai` avec driver Infomaniak/Euria*
