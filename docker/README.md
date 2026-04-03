# Claire Chatbot Docker Image

[![Docker Pulls](https://img.shields.io/docker/pulls/semhoun/claire-chatbot)](https://hub.docker.com/r/semhoun/claire-chatbot)
[![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-777bb4?logo=php&logoColor=white)](https://php.net)
[![FrankenPHP](https://img.shields.io/badge/FrankenPHP-Caddy-ffb300)](https://frankenphp.dev/)
[![License](https://img.shields.io/badge/License-MIT-blue)](https://github.com/semhoun/claire-chatbot/blob/main/LICENSE)

Image Docker officielle de Claire Chatbot.

- Docker Hub: `semhoun/claire-chatbot`
- Repository: `https://hub.docker.com/r/semhoun/claire-chatbot`
- Sources: `https://github.com/semhoun/claire-chatbot`

Cette image embarque Claire avec PHP 8.4, FrankenPHP, Caddy, Supervisor, Doctrine, OpenTelemetry et les extensions PHP necessaires pour SQLite, MySQL, PostgreSQL, Redis, Memcache, GD et Imagick.

## Demarrage rapide

### Lancer le conteneur

```bash
docker run -d \
  --name claire \
  -p 8080:80 \
  -v claire_data:/data \
  -e OPENAPI_KEY=votre-cle-api \
  -e OPENAPI_URL=https://api.openai.com/v1 \
  -e OPENAPI_MODEL=gpt-5-mini \
  -e SESSION_JWT_SECRET=$(openssl rand -hex 32) \
  -e OTEL_PHP_AUTOLOAD_ENABLED=true \
  -e OTEL_SERVICE_NAME=claire \
  -e OTEL_TRACES_EXPORTER=none \
  -e OTEL_METRICS_EXPORTER=none \
  -e OTEL_LOGS_EXPORTER=console \
  -e OTEL_LOGS_PROCESSOR=simple \
  semhoun/claire-chatbot:latest
```

Application disponible sur `http://localhost:8080`.

### Initialiser la base

```bash
docker exec claire ./console migrations:migrate
```

## Image et runtime

- Base: `dunglas/frankenphp:php8.4-trixie`
- Serveur HTTP: FrankenPHP + Caddy
- Supervision: Supervisor
- Repertoire de travail: `/www`
- Donnees persistantes: `/data`
- Ports exposes: `80`, `443`
- Healthcheck: `GET /health`

Au demarrage, l'entrypoint:

- copie la configuration PHP de Claire dans `${PHP_INI_DIR}/conf.d`
- choisit `php.ini-development` ou `php.ini-production` selon `DEBUG_MODE`
- cree les repertoires de donnees et de cache si necessaire
- genere les proxies Doctrine si absents
- genere dynamiquement le `Caddyfile` selon `SERVER_NAME`, `ENABLE_LETSENCRYPT` et la configuration OpenTelemetry

## Variables d'environnement

### Obligatoires en pratique

| Variable | Description |
|----------|-------------|
| `OPENAPI_URL` | URL du fournisseur compatible OpenAI |
| `OPENAPI_MODEL` | Modele par defaut utilise pour le chat |
| `SESSION_JWT_SECRET` | Secret de signature JWT |
| `OTEL_PHP_AUTOLOAD_ENABLED` | Active l'auto-instrumentation OpenTelemetry |
| `OTEL_SERVICE_NAME` | Nom du service remonte par OpenTelemetry |
| `OTEL_TRACES_EXPORTER` | Exporteur de traces, ex: `none` ou `otlp` |
| `OTEL_METRICS_EXPORTER` | Exporteur de metriques, ex: `none` ou `otlp` |
| `OTEL_LOGS_EXPORTER` | Exporteur de logs, ex: `console`, `otlp`, `none` |
| `OTEL_LOGS_PROCESSOR` | Processeur de logs, ex: `simple` ou `batch` |

`OPENAPI_KEY` est requis si votre fournisseur LLM demande une authentification.

### HTTP / Caddy / conteneur

| Variable | Defaut | Description |
|----------|--------|-------------|
| `SERVER_NAME` | `www.docker.test` | Nom de domaine utilise par Caddy |
| `SERVER_ADMIN` | `webmaster@docker.test` | Adresse admin associee au serveur |
| `ENABLE_LETSENCRYPT` | `false` | Active HTTPS direct avec certificats Let's Encrypt |
| `ACME_EMAIL` | vide | Email ACME pour l'emission des certificats |
| `ENABLE_ACCESS_LOGS` | `true` | Conserve la compatibilite avec la config applicative |
| `DEBUG_MODE` | `false` | Active le mode debug PHP |
| `DATA_PATH` | `/data` | Repertoire racine des donnees persistantes |

### LLM

| Variable | Defaut | Description |
|----------|--------|-------------|
| `OPENAPI_KEY` | vide | Cle API du fournisseur |
| `OPENAPI_URL` | aucune | Base URL de l'API |
| `OPENAPI_MODEL` | aucune | Modele principal |
| `OPENAPI_MODEL_SUMMARY` | valeur de `OPENAPI_MODEL` | Modele dedie aux resumes |
| `OPENAPI_MODEL_EMBED` | vide | Modele d'embeddings pour le RAG |
| `OPENAPI_CONTEXT_WINDOW` | `50000` | Fenetre de contexte cible |
| `SEARXNG_URL` | vide | URL de SearXNG pour la recherche web |

### Base de donnees

| Variable | Defaut | Description |
|----------|--------|-------------|
| `DATABASE_KIND` | `sqlite` | `sqlite`, `mysql`, `postgres` ou `pgsql` |
| `DATABASE_HOST` | vide | Hote MySQL/PostgreSQL |
| `DATABASE_PORT` | vide | Port MySQL/PostgreSQL |
| `DATABASE_NAME` | vide | Nom de base |
| `DATABASE_USER` | vide | Utilisateur |
| `DATABASE_PASSWORD` | vide | Mot de passe |

En mode SQLite, la base est stockee dans `/data/database.sqlite`.

### Fichiers et stockage

| Variable | Defaut | Description |
|----------|--------|-------------|
| `FILES_PATH` | `${DATA_PATH}/filer` | Repertoire des fichiers uploades |

### OpenTelemetry

| Variable | Description |
|----------|-------------|
| `OTEL_PHP_EXCLUDED_URLS` | URLs exclues de l'instrumentation, ex: `/health` |
| `OTEL_PROPAGATORS` | Propagateurs de contexte, ex: `baggage,tracecontext` |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | Protocole OTLP, ex: `http/protobuf` |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | Endpoint OTLP commun |
| `OTEL_EXPORTER_OTLP_HEADERS` | En-tetes OTLP supplementaires |
| `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT` | Endpoint OTLP dedie aux traces |
| `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT` | Endpoint OTLP dedie aux metriques |
| `OTEL_EXPORTER_OTLP_LOGS_ENDPOINT` | Endpoint OTLP dedie aux logs |

Si `OTEL_EXPORTER_OTLP_ENDPOINT` est defini, l'entrypoint ajoute un bloc de tracing Caddy et expose un header `X-Trace-Id` sur les requetes gerees.

### Telegram

| Variable | Description |
|----------|-------------|
| `TELEGRAM_BOT_TOKEN` | Token du bot Telegram |
| `TELEGRAM_WEBHOOK_SECRET` | Secret du webhook Telegram |

### OpenID Connect

| Variable | Description |
|----------|-------------|
| `OPENID_WELLKNOWN_URL` | URL du document `.well-known/openid-configuration` |
| `OPENID_CLIENT_ID` | Client ID OIDC |
| `OPENID_CLIENT_SECRET` | Secret client OIDC |
| `OPENID_REDIRECT_URI_BASE` | Base d'URL publique de l'application |

### ComfyUI

| Variable | Defaut | Description |
|----------|--------|-------------|
| `COMFYUI_ENABLED` | `false` | Active la generation d'images |
| `COMFYUI_URL` | `http://localhost:8188` | URL de l'instance ComfyUI |
| `COMFYUI_TIMEOUT` | `300` | Timeout en secondes |
| `COMFYUI_WORKFLOW` | vide | Workflow JSON avec `{{PROMPT}}` |
| `COMFYUI_PROMPT_STYLE` | `sdxl` | Style de prompt: `sdxl` ou `flux` |

## Exemples Docker Compose

### Configuration minimale

```yaml
services:
  claire:
    image: semhoun/claire-chatbot:latest
    container_name: claire
    ports:
      - "8080:80"
    volumes:
      - claire_data:/data
    environment:
      OPENAPI_KEY: ${OPENAPI_KEY:?set_me}
      OPENAPI_URL: https://api.openai.com/v1
      OPENAPI_MODEL: gpt-5-mini
      SESSION_JWT_SECRET: ${SESSION_JWT_SECRET:?set_me}

      OTEL_PHP_AUTOLOAD_ENABLED: "true"
      OTEL_SERVICE_NAME: claire
      OTEL_PHP_EXCLUDED_URLS: /health
      OTEL_PROPAGATORS: baggage,tracecontext
      OTEL_TRACES_EXPORTER: none
      OTEL_METRICS_EXPORTER: none
      OTEL_LOGS_EXPORTER: console
      OTEL_LOGS_PROCESSOR: simple
    healthcheck:
      test: ["CMD", "curl", "--fail", "http://localhost/health"]
      interval: 15s
      timeout: 5s
      retries: 3
      start_period: 60s

volumes:
  claire_data:
```

### Configuration avec HTTPS natif et OTLP

```yaml
services:
  claire:
    image: semhoun/claire-chatbot:latest
    container_name: claire
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - claire_data:/data
    environment:
      SERVER_NAME: claire.example.com
      ENABLE_LETSENCRYPT: "true"
      ACME_EMAIL: admin@example.com

      OPENAPI_KEY: ${OPENAPI_KEY:?set_me}
      OPENAPI_URL: https://api.openai.com/v1
      OPENAPI_MODEL: gpt-5
      OPENAPI_MODEL_SUMMARY: gpt-5-mini
      OPENAPI_MODEL_EMBED: text-embedding-3-large

      SESSION_JWT_SECRET: ${SESSION_JWT_SECRET:?set_me}

      OTEL_PHP_AUTOLOAD_ENABLED: "true"
      OTEL_SERVICE_NAME: claire
      OTEL_PHP_EXCLUDED_URLS: /health
      OTEL_PROPAGATORS: baggage,tracecontext
      OTEL_TRACES_EXPORTER: otlp
      OTEL_METRICS_EXPORTER: otlp
      OTEL_LOGS_EXPORTER: otlp
      OTEL_LOGS_PROCESSOR: batch
      OTEL_EXPORTER_OTLP_PROTOCOL: http/protobuf
      OTEL_EXPORTER_OTLP_ENDPOINT: http://otel-collector:4318
    healthcheck:
      test: ["CMD", "curl", "--fail", "http://localhost/health"]
      interval: 15s
      timeout: 5s
      retries: 3
      start_period: 60s

volumes:
  claire_data:
```

## Commandes utiles

```bash
docker logs -f claire
docker exec claire ./console migrations:migrate
docker exec claire ./console cache:clear
docker exec claire ./console telegram:set-commands
docker exec -it claire bash
```

## Volumes, chemins et persistance

| Chemin | Usage |
|--------|-------|
| `/data` | Base SQLite, fichiers uploades, cache applicatif persistant |
| `/data/filer` | Stockage des fichiers envoyes |
| `/www/var/cache` | Cache local de l'application |
| `/www/var/tmp` | Repertoire temporaire |

## Sante et exposition reseau

- Endpoint de sante: `GET /health`
- Port HTTP: `80`
- Port HTTPS: `443`
- En HTTP simple, mappez `8080:80` ou placez le conteneur derriere un reverse proxy TLS
- En HTTPS natif, exposez `80` et `443`, configurez `SERVER_NAME` et activez `ENABLE_LETSENCRYPT=true`

## Limitations et remarques

- Le dossier `/data` doit etre persistant pour conserver la base SQLite et les fichiers utilisateurs.
- Si vous utilisez SQLite, pensez a executer `./console migrations:migrate` au premier demarrage.
- `ENABLE_ACCESS_LOGS` existe cote application, mais la journalisation HTTP est actuellement generee par le `Caddyfile` cree au demarrage.
- Le fichier `docker/compose.yml` du depot sert surtout d'exemple local de developpement et contient des valeurs specifiques a un environnement de travail.

## Liens

- Docker Hub: `https://hub.docker.com/r/semhoun/claire-chatbot`
- Documentation generale: `README.md`
- Dockerfile: `docker/Dockerfile`
- Exemple compose: `docker/compose.yml`
