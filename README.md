# Claire — Agent de Chat IA (PHP, Slim 4)

![PHP Version](https://img.shields.io/badge/PHP-8.5%2B-777bb4?logo=php&logoColor=white) ![Slim](https://img.shields.io/badge/Slim-4.x-4B4B4B) ![FrankenPHP](https://img.shields.io/badge/FrankenPHP-Caddy-ffb300) ![License](https://img.shields.io/badge/License-MIT-blue) [![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/semhoun/claire-chatbot)

> **Claire** — Chatbot IA multi-brain avec Telegram, ComfyUI, PDF et OpenTelemetry

Claire est une application de chat IA construite avec Slim 4, Twig et Neuron AI. Elle s'exécute dans un conteneur Docker basé sur FrankenPHP/Caddy et fournit une interface web, une API REST, une intégration Telegram et une observabilité complète via OpenTelemetry.


## Fonctionnalités

- Interface web de chat avec streaming SSE, horodatage, suppression du dernier message
- API REST `POST /brain/messages` et healthcheck `GET /health`
- Multi-brain : sélection dynamique d'agents IA (Claire, Einstein, Calliope...)
- Création d'agents personnalisés via fichiers YAML dans `/opt/addons/agents/`
- Mémoire courte avec résumé automatique de l'historique
- Recherche web via SearXNG et RAG fichier via embeddings
- Génération d'images avec ComfyUI (workflows multiples)
- Génération de documents PDF depuis HTML ou Markdown
- Intégration Telegram complète (messages, photos, documents, Mini-App)
- Queue de fond Redis pour traitements asynchrones
- Observabilité OpenTelemetry (traces, métriques, logs)
- Authentification SSO OpenID Connect obligatoire

## Pile technique

- **Runtime** : FrankenPHP + Caddy (PHP 8.5)
- **Framework** : Slim 4 avec PHP-DI
- **Templates** : Twig
- **ORM** : Doctrine ORM/DBAL (SQLite, MySQL, PostgreSQL)
- **LLM** : Neuron AI avec support OpenAI-compatible
- **Queue** : Redis (BRPOP/LPUSH)
- **Observabilité** : OpenTelemetry SDK + auto-instrumentation
- **PDF** : mPDF (génération de documents)
- **Bot** : phptg/bot-api (Telegram)

## Configuration

### Variables obligatoires

| Variable | Description |
|----------|-------------|
| `BASE_URL` | URL publique de l'application (ex: `https://claire.example.com`) |
| `OPENAPI_KEY` | Clé API du fournisseur LLM |
| `OPENAPI_URL` | URL de l'API LLM |
| `OPENAPI_MODEL` | Modèle par défaut |
| `OPENID_WELLKNOWN_URL` | URL de découverte OpenID Connect |
| `OPENID_CLIENT_ID` | Identifiant client OIDC |
| `SESSION_JWT_SECRET` | Clé secrète JWT (min 32 caractères) |

### Variables optionnelles

| Variable | Description | Défaut |
|----------|-------------|--------|
| `OPENAPI_MODEL_SUMMARY` | Modèle pour les résumés | valeur de `OPENAPI_MODEL` |
| `OPENAPI_MODEL_EMBED` | Modèle pour embeddings (RAG) | désactivé |
| `OPENAPI_REQUEST_TIMEOUT` | Timeout des requêtes API (secondes) | `180` |
| `SEARXNG_URL` | URL SearXNG pour recherche web | - |
| `TELEGRAM_BOT_TOKEN` | Token du bot Telegram | - |
| `TELEGRAM_WEBHOOK_SECRET` | Secret webhook Telegram | - |
| `COMFYUI_ENABLED` | Active la génération d'images | `false` |
| `COMFYUI_URL` | URL de l'instance ComfyUI | `http://localhost:8188` |
| `PDF_ENABLED` | Active la génération de PDF | `true` |
| `PDF_DEFAULT_FORMAT` | Format d'entrée par défaut (`html`, `markdown`) | `html` |
| `PDF_DEFAULT_PAGE_SIZE` | Format de page par défaut (`A4`, `Letter`, `A3`, `A5`) | `A4` |
| `PDF_TEMP_DIR` | Répertoire temporaire pour la génération PDF | `<app>/var/tmp` |
| `DATABASE_KIND` | Type de base (`sqlite`, `mysql`, `postgres`) | `sqlite` |
| `DEBUG_MODE` | Mode debug | `false` |
| `QUEUE_WORKERS` | Nombre de workers de queue | `8` |
| `QUEUE_WORKER_TIMEOUT` | Timeout BRPOP du worker (secondes) | `5` |
| `QUEUE_WORKER_MAX_JOBS` | Nombre max de jobs par worker | `0` (illimité) |
| `QUEUE_WORKER_MAX_TIME` | Durée de vie max d'un worker (secondes) | `0` (illimité) |
| `SSE_QUEUE_TTL` | Durée de vie des messages SSE en file d'attente (secondes) | `60` |
| `SSE_POP_TIMEOUT` | Timeout de lecture bloquante SSE (secondes) | `15` |

Voir [`docker/compose.yml`](docker/compose.yml) pour un exemple complet avec toutes les variables.

### Volumes

| Chemin        | Usage |
|---------------|-------|
| `/opt/data`   | Base SQLite, fichiers uploadés, données persistantes |
| `/opt/addons` | Agents YAML personnalisés, workflows ComfyUI |

### Commandes Docker utiles

```bash
# Démarrer la stack
docker compose up -d

# Voir les logs
docker compose logs -f claire

# Exécuter des commandes
docker compose exec claire ./console migrations:migrate
docker compose exec claire ./console cache:clear
docker compose exec claire ./console telegram:set-commands
docker compose exec claire ./console telegram:webhook --set

# Lancer le worker de queue
docker compose exec claire ./console queue:work
```

## Cerveaux personnalisés (BrainRegistry)

Créez vos propres agents sans coder en ajoutant des fichiers YAML dans `/opt/addons/agents/` :

```yaml
name: "Coach Personnel"
description: "Un coach motivant pour vous aider à atteindre vos objectifs"
avatar: "data:image/png;base64,..."
css_inline: |
  :root { --accent: #FF6B35; }
welcomes:
  - "Prêt à relever de nouveaux défis ?"
  - "Bonjour champion !"
instruction: |
  Tu es un coach personnel motivant et bienveillant...
```

Les cerveaux par défaut : `claire` (généraliste), `einstein` (scientifique), `calliope` (conteuse).

## ComfyUI (Génération d'images)

Activez avec `COMFYUI_ENABLED=true` et ajoutez des workflows dans `/opt/addons/comfyui/` :

```yaml
label: Portrait Flux
workflow: |
  {
    "3": { "inputs": { "seed": {{SEED}} }, "class_type": "KSampler" },
    "6": { "inputs": { "text": "{{PROMPT}}" }, "class_type": "CLIPTextEncode" }
  }
```

## Génération de PDF

Activez par défaut (`PDF_ENABLED=true`). Les agents peuvent générer des documents PDF depuis du HTML ou du Markdown via l'outil `generate_pdf` :

- Formats supportés : HTML, Markdown
- Formats de page : A4, Letter, A3, A5
- Orientations : portrait, paysage
- Marges configurables

Les fichiers générés sont liés à la conversation et accessibles dans l'historique de chat.

## Telegram Bot

### Configuration

| Variable | Description |
|----------|-------------|
| `TELEGRAM_BOT_TOKEN` | Token de @BotFather |
| `TELEGRAM_WEBHOOK_SECRET` | Secret pour sécuriser le webhook |

### Commandes de configuration

```bash
# Configurer le webhook
docker compose exec claire ./console telegram:webhook --set

# Vérifier le statut
docker compose exec claire ./console telegram:webhook --info

# Configurer le bouton Mini-App
docker compose exec claire ./console telegram:menu-button --set
```

Le bot supporte les commandes `/start`, `/help`, `/brain`, `/comfyui`.

## Queue Redis

Nécessaire pour Telegram et le streaming SSE multi-instance :

```bash
# Lancer le worker
docker compose exec claire ./console queue:work

# Options
--once        # Traiter un seul job
--timeout=5   # Timeout BRPOP
--max-jobs=100
```

## API

### Healthcheck

`GET /health` — Retourne la version et la date.

### Envoi de message

```http
POST /brain/messages HTTP/1.1
Content-Type: multipart/form-data; boundary=----BOUND

------BOUND
Content-Disposition: form-data; name="message"

Bonjour Claire !
------BOUND
Content-Disposition: form-data; name="sessionId"

sess-abc123
------BOUND--
```

### Gestion des fichiers

- `GET /files/count`, `GET /files/list`
- `POST /files/upload`, `POST /files/upload_rag`
- `DELETE /files/delete/{id}`
- `GET /files/img_serve/{id}` (images et PDF générés)

### Historique

- `GET /history/count`, `GET /history/list`
- `GET /history/open/{threadId}`, `POST /history/new`
- `DELETE /history/exchange/last`, `DELETE /history/delete/{threadId}`

## Démarrage rapide (Docker)

```bash
# Lancer Claire avec Docker
docker run -d \
  --name claire \
  -p 8080:80 \
  -v claire_data:/opt/data \
  -e OPENAPI_KEY=votre-clé-api \
  -e OPENAPI_URL=https://api.openai.com/v1 \
  -e OPENAPI_MODEL=gpt-4o-mini \
  -e SESSION_JWT_SECRET=$(openssl rand -hex 32) \
  -e OTEL_PHP_AUTOLOAD_ENABLED=true \
  -e OTEL_SERVICE_NAME=claire \
  -e OTEL_LOGS_EXPORTER=console \
  -e OTEL_LOGS_PROCESSOR=simple \
  semhoun/claire-chatbot:latest

# Initialiser la base de données
docker exec claire ./console migrations:migrate

# Accéder à l'application
# Ouvrir http://localhost:8080
```

**Avec Docker Compose:**

```yaml
services:
  claire:
    image: semhoun/claire-chatbot:latest
    container_name: claire
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - claire-data:/opt/data
      - claire-addons:/opt/addons
    environment:
      # === Configuration serveur ===
      BASE_URL: https://claire.example.com
      SERVER_NAME: claire.example.com
      ENABLE_LETSENCRYPT: true
      ACME_EMAIL: admin@example.com

      # === LLM Configuration ===
      OPENAPI_KEY: ${OPENAPI_KEY:?set_me}
      OPENAPI_URL: https://api.mistral.ai/v1
      OPENAPI_MODEL: mistral-large-latest

      # === Authentification OpenID (obligatoire) ===
      OPENID_WELLKNOWN_URL: https://lastlogin.net/.well-known/openid-configuration
      OPENID_CLIENT_ID: https://claire.example.com

      # === Sécurité ===
      SESSION_JWT_SECRET: ${SESSION_JWT_SECRET:?set_me}

      # === Observabilité ===
      OTEL_PHP_AUTOLOAD_ENABLED: true
      OTEL_SERVICE_NAME: claire
      OTEL_LOGS_EXPORTER: console
      OTEL_LOGS_PROCESSOR: simple

  redis:
    image: redis:7-alpine
    restart: unless-stopped

volumes:
  claire-data:
  claire-addons:
  claire-redis:
```

Image Docker : [semhoun/claire-chatbot](https://hub.docker.com/r/semhoun/claire-chatbot)


## Développement local (optionnel)

Pour contribuer ou modifier le code :

```bash
# Cloner et installer
git clone https://github.com/semhoun/claire-chatbot.git
cd claire-chatbot
composer install

# Exporter les variables
export OPENAPI_KEY=votre-clé-api
export OPENAPI_URL=https://api.openai.com/v1
export OPENAPI_MODEL=gpt-4o-mini
export SESSION_JWT_SECRET=$(openssl rand -hex 32)

# Initialiser et lancer
./console migrations:migrate
composer start
```

### Qualité du code

```bash
composer rector-check    # Vérifier
composer rector-fix      # Appliquer
composer insights-check  # Analyser
composer insights-fix    # Corriger
vendor/bin/phpunit       # Tests
composer pre-commit      # Tous les checks
```

## Dépannage

| Problème | Solution |
|----------|----------|
| 500 au `GET /` | Vérifiez les permissions du dossier `var/` |
| Pas de logs | Définissez `OTEL_LOGS_EXPORTER=console` |
| RAG inactif | Vérifiez `OPENAPI_MODEL_EMBED` |
| ComfyUI non dispo | Vérifiez `COMFYUI_ENABLED=true` et les workflows |
| Worker bloqué | Vérifiez Redis et `REDIS_READ_TIMEOUT` |

## Licence

MIT — Voir le fichier `LICENSE`.
