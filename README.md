# Claire — Agent de Chat IA (PHP, Slim 4)

![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-777bb4?logo=php&logoColor=white) ![Slim](https://img.shields.io/badge/Slim-4.x-4B4B4B) ![Twig](https://img.shields.io/badge/Twig-3.x-43A047) ![License](https://img.shields.io/badge/License-MIT-blue) [![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/semhoun/claire-chatbot)

Claire est une application web minimaliste de chat IA construite avec Slim 4 et Twig. Elle s'appuie sur la bibliothèque neuron-core pour piloter un LLM compatible OpenAI et fournit une petite interface web ainsi qu'un endpoint API pour échanger des messages.

## Démarrage rapide

```bash
# 1. Cloner et installer les dépendances
git clone https://github.com/semhoun/claire-chatbot.git
cd claire-chatbot
composer install

# 2. Créer un fichier .env avec votre clé API
cat > .env <<EOF
OPENAPI_KEY=votre-clé-api
OPENAPI_URL=https://api.openai.com/v1
OPENAPI_MODEL=gpt-4o-mini
SESSION_JWT_SECRET=$(openssl rand -hex 32)
OTEL_LOGS_EXPORTER=console
OTEL_LOGS_PROCESSOR=simple
EOF

# 3. Initialiser la base de données
./console migrations:migrate

# 4. Lancer le serveur de développement
composer start
# Ouvrir http://localhost:8080
```

**Ou avec Docker:**

```bash
docker build -t claire:latest -f docker/Dockerfile .
docker run -d -p 8080:80 \
  -e OPENAPI_KEY=votre-clé-api \
  -e SESSION_JWT_SECRET=$(openssl rand -hex 32) \
  -v claire_data:/data \
  claire:latest
```

## Fonctionnalités

- Interface web de chat basique (Twig + CSS).
- Endpoint API `POST /brain/chat` pour envoyer un message et récupérer la réponse de l'agent.
- Healthcheck `GET /health` (JSON) pour la supervision.
- Intégration d'un fournisseur LLM « OpenAI-like » (URL, clé et modèle configurables).
- Génération d'images avec ComfyUI (optionnel).
- Journalisation via Monolog, exportée par OpenTelemetry.
- Intégration OpenTelemetry pour traces/metrics/logs.

## Pile technique

- PHP 8.4+
- [Slim 4](https://www.slimframework.com/) (routing, middlewares)
- [PHP-DI](https://php-di.org/) (container)
- [Twig](https://twig.symfony.com/) (templates)
- [Monolog](https://github.com/Seldaek/monolog) (logs)
- [Doctrine ORM](https://www.doctrine-project.org/) (persistance des données)
- [Neuron AI](https://www.neuron-ai.dev/) (agent LLM)
- [phptg/bot-api](https://github.com/phptg/bot-api) (bot Telegram)
- [OpenTelemetry](https://opentelemetry.io/docs/languages/php/) (observabilité)
- [Symfony YAML](https://symfony.com/doc/current/components/yaml.html) (cerveaux personnalisés)
- Docker (déploiement containerisé avec Apache, PHP-FPM, Supervisor)

## Prérequis

- PHP 8.4 ou supérieur avec les extensions:
  - `ext-json`
  - `ext-sqlite3` ou 'ext-mysql' ou 'ext-pgsql'
  - `ext-libxml`
- Composer
- Ajustez `max_execution_time` dans php.ini (ex: `max_execution_time=300`) car les appels LLM peuvent être longs.

## Installation

1. Cloner le dépôt puis installer les dépendances:
   ```bash
   composer install
   ```
2. (Optionnel) Si vous comptez utiliser les migrations/Doctrine, initialisez votre base de données selon vos besoins.

## Configuration

Les paramètres sont chargés depuis `config/settings/*.php` et complétés par des variables d’environnement. Les clés importantes:

- LLM (voir `config/settings/llm.php`):
  - `OPENAPI_KEY`   — clé d'API du fournisseur (compatible OpenAI)
  - `OPENAPI_URL`   — base URL de l'API (ex: https://api.openai.com/v1 ou un proxy type LiteLLM)
  - `OPENAPI_MODEL` — identifiant du modèle par défaut (ex: gpt-4o-mini, gpt-5.1, etc.)
  - `OPENAPI_MODEL_SUMMARY` — modèle dédié aux tâches de synthèse/résumé (optionnel; si absent, `OPENAPI_MODEL` sera utilisé)
  - `OPENAPI_MODEL_EMBED` — modèle dédié aux embeddings (optionnel; si absent, le RAG est désactivé)
  - `SEARXNG_URL` — URL de l'instance SearXNG pour la recherche web (optionnel)

- Telegram (voir `config/settings/telegram.php`):
  - `TELEGRAM_BOT_TOKEN` — Token de votre bot Telegram (obtenu via @BotFather).

- Mode et logs:
  - `DEBUG_MODE` = `true|false` (active un niveau de logs plus verbeux)
  - `DISABLE_TRACY_BAR` = `true|false` (permet de désactiver la barre de debug Tracy, activée par défaut si non spécifié)

- Stockage des fichiers:
  - `FILES_PATH` — chemin vers le répertoire de stockage des fichiers (par défaut: `var/filer`).

- Observabilité (OpenTelemetry — requis):
  - Journalisation OpenTelemetry: les logs de l’application sont émis via l’intégration Monolog/OpenTelemetry.
  - Pour afficher les logs dans la console en développement, définissez `OTEL_LOGS_EXPORTER=console` (et éventuellement `OTEL_LOGS_PROCESSOR=simple`).
  - Variables d’environnement principales (par signal):
    - Générales
      - `OTEL_PHP_AUTOLOAD_ENABLED` — active l’auto‑instrumentation PHP (true/false).
      - `OTEL_SERVICE_NAME` — nom du service (utilisé par les 3 signaux).
      - `OTEL_RESOURCE_ATTRIBUTES` — attributs ressource supplémentaires (ex: `deployment.environment=dev,service.version=1.0.0`).
      - `OTEL_PROPAGATORS` — propagateurs de contexte (ex: `baggage,tracecontext`).
    - Traces
      - `OTEL_TRACES_EXPORTER` — exporteur des traces (`otlp`, `none`).
      - `OTEL_TRACES_SAMPLER` — stratégie d’échantillonnage (ex: `parentbased_always_on`, `traceidratio`).
      - `OTEL_TRACES_SAMPLER_ARG` — paramètre du sampler (ex: `0.1` pour 10%).
    - Metrics
      - `OTEL_METRICS_EXPORTER` — exporteur des métriques (`otlp`, `none`).
    - Logs
      - `OTEL_LOGS_EXPORTER` — exporteur des logs (`console`, `otlp`, `none`).
      - `OTEL_LOGS_PROCESSOR` — processeur des logs (`simple` pour affichage immédiat, `batch` pour production).
    - Export OTLP (commun, et surcharges par signal)
      - `OTEL_EXPORTER_OTLP_PROTOCOL` — protocole (`http/protobuf` recommandé, ou `grpc`).
      - `OTEL_EXPORTER_OTLP_ENDPOINT` — endpoint commun OTLP (optionnel, ex: `http://collector:4318`).
      - `OTEL_EXPORTER_OTLP_HEADERS` — en‑têtes additionnels (optionnels, ex: `authorization=Bearer <token>`).
      - `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT` — endpoint traces (optionnel; surcharge de `..._ENDPOINT`).
      - `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT` — endpoint métriques (optionnel; surcharge de `..._ENDPOINT`).
      - `OTEL_EXPORTER_OTLP_LOGS_ENDPOINT` — endpoint logs (optionnel; surcharge de `..._ENDPOINT`).

  Note: les endpoints OTLP et les headers sont optionnels. Si vous ne les définissez pas, l’exporteur appliquera ses valeurs par défaut. Par exemple, pour afficher les logs uniquement en console, il suffit de définir `OTEL_LOGS_EXPORTER=console` sans renseigner d’endpoint OTLP.

### Avatars / Cerveaux (BrainRegistry)

L'application permet de sélectionner différents « cerveaux » (avatars) pour l'agent (ex.: Claire, Einstein, Flashy). La sélection est mémorisée en session sous la clé `brain_avatar`.

- La logique de sélection est gérée par le registre `BrainRegistry`.
- Si une valeur invalide est fournie, l'application revient automatiquement sur l'avatar par défaut: `claire`.
- Vous pouvez exposer ce choix dans l'UI (ex.: select) ou via un paramètre de requête selon vos besoins.
- Cerveaux disponibles par défaut:
  - `claire` — Assistante généraliste
  - `einstein` — Expert scientifique
  - `flashy` — Réponses rapides et concises

#### Ajout de cerveaux personnalisés via YAML

Vous pouvez créer vos propres agents sans écrire de code PHP en plaçant des fichiers YAML dans le répertoire `addons/agents/`. Chaque fichier définit un nouveau cerveau avec ses propres caractéristiques.

**Structure d'un fichier YAML:**

```yaml
name: "Nom de l'Assistant"
description: "Description courte affichée dans l'interface"
avatar: "data:image/png;base64,iVBORw0K......"        # URL ou data URI de l'avatar
css: ""           # Nom du fichier CSS dans public/css/ (optionnel)
css_inline: |     # CSS inline directement dans le fichier (optionnel)
  :root {
    --accent: #FF6B6B;
  }
  .chat-header {
    background: #ffffff;
  }
welcomes:
  - "Premier message d'accueil possible"
  - "Deuxième message d'accueil possible"
  - "Troisième message d'accueil possible"
instruction: |
  Le prompt système complet de l'assistant.
  Définissez ici son rôle, son ton, ses compétences.
  Utilisez plusieurs lignes si nécessaire.
```

**Exemple de fichier `addons/agents/coach.yaml`:**

```yaml
name: "Coach Personnel"
description: "Un coach motivant pour vous aider à atteindre vos objectifs"
avatar: ""
css: ""
css_inline: |
  :root {
    --accent: #FF6B35;
    --accent-light: #FF8C42;
  }
  .message--sent .message__bubble {
    background: linear-gradient(135deg, var(--accent), var(--accent-light));
  }
welcomes:
  - "Prêt à relever de nouveaux défis aujourd'hui ?"
  - "Bonjour champion ! Que veux-tu accomplir ?"
  - "Salut ! Je suis là pour te motiver et te guider."
instruction: |
  Tu es un coach personnel motivant et bienveillant.
  Ton rôle est d'aider l'utilisateur à définir ses objectifs,
  à rester motivé et à surmonter les obstacles.
  Sois encourageant, pose des questions pertinentes et propose
  des plans d'action concrets.
```

**Champs disponibles:**

| Champ | Type | Description |
|-------|------|-------------|
| `name` | string | Nom affiché de l'assistant (obligatoire) |
| `description` | string | Description courte pour la sélection (obligatoire) |
| `avatar` | string | URL ou data URI de l'image avatar (optionnel) |
| `css` | string | Nom d'un fichier CSS externe dans `public/css/` (optionnel) |
| `css_inline` | string | CSS inline directement dans le YAML (optionnel, recommandé) |
| `welcomes` | array | Liste de messages d'accueil (un choisi aléatoirement) |
| `instruction` | string | Prompt système complet de l'assistant (obligatoire) |

**Notes sur le CSS:**

- **Recommandé**: Utilisez `css_inline` pour embarquer le CSS directement dans le fichier YAML
- **Alternative**: Utilisez `css` pour référencer un fichier externe (ex: `css: "monbrain.css"`)
- Vous pouvez utiliser les deux simultanément si nécessaire
- Le CSS inline est injecté via une balise `<style>` dans le `<head>`
- Voir `addons/agents/flashy.yaml` pour un exemple complet avec CSS inline

**Points importants:**

- Le slug du cerveau est déterminé par le nom du fichier (ex: `coach.yaml` → slug `coach`)
- Les fichiers YAML sont chargés automatiquement au démarrage
- Un message d'accueil est choisi aléatoirement parmi la liste `welcomes`
- Les cerveaux YAML apparaissent automatiquement dans l'interface aux côtés des cerveaux PHP
- Voir le fichier exemple dans `addons/agents/flashy.yaml` (réplique complète en YAML du cerveau Flashy IoT avec CSS inline)

#### Recherche Web (Web Search)

L'application prend en charge la recherche web via SearXNG lorsque la variable d'environnement `SEARXNG_URL` est configurée. Cette fonctionnalité ajoute l'outil `web_search` aux agents, leur permettant d'effectuer des recherches sur internet pour enrichir leurs réponses.

- Configuration: définissez `SEARXNG_URL` avec l'URL de votre instance SearXNG (ex: `http://searxng:8080`)
- L'outil est automatiquement disponible pour tous les cerveaux lorsque la configuration est présente

### Génération d'images avec ComfyUI

L'application peut générer des images via ComfyUI lorsque la configuration appropriée est définie. Cette fonctionnalité ajoute l'outil `generate_image` aux agents, leur permettant de créer des images à partir de descriptions textuelles.

**Configuration requise:**

| Variable | Obligatoire | Description |
|----------|-------------|-------------|
| `COMFYUI_ENABLED` | Oui | Active la génération d'images (`true` ou `false`) |
| `COMFYUI_URL` | Non | URL de l'instance ComfyUI (défaut: `http://localhost:8188`) |
| `COMFYUI_WORKFLOW` | Oui | Workflow ComfyUI au format JSON avec placeholder `{{PROMPT}}` |
| `COMFYUI_TIMEOUT` | Non | Timeout en secondes (défaut: 300) |
| `COMFYUI_PROMPT_STYLE` | Non | Style de prompt: `sdxl` (keywords) ou `flux` (natural language) - défaut: `sdxl` |

**Exemple de configuration:**

```bash
# Activation de ComfyUI
export COMFYUI_ENABLED=true
export COMFYUI_URL=http://comfyui:8188
export COMFYUI_WORKFLOW='{"3":{"inputs":{"seed":0,"steps":20,"cfg":8,"sampler_name":"euler","scheduler":"normal","denoise":1,"model":["4",0],"positive":["6",0],"negative":["7",0],"latent_image":["5",0]},"class_type":"KSampler"},...}'
export COMFYUI_PROMPT_STYLE=flux
```

Note: Le workflow doit contenir un placeholder `{{PROMPT}}` qui sera remplacé par la description de l'image à générer.

**Styles de prompts:**
- `sdxl`: Prompts optimisés pour SDXL (mots-clés séparés par des virgules)
- `flux`: Prompts en langage naturel pour Flux

**Fonctionnement:**
- Lorsqu'activé, l'outil `generate_image` est disponible pour tous les cerveaux
- L'agent peut décider de générer une image en réponse à une demande utilisateur
- Les images générées sont stockées dans `var/generated_images/`
- Les images sont servies via l'endpoint `/files/generated/{filename}`
- Le bot Telegram supporte l'envoi des images générées

### Authentification OpenID Connect (SSO)

L’application prend en charge une authentification via SSO OpenID Connect, si les paramètres sont définis sinon elle utilise un utilisateur par défaut. La configuration est lue dans `config/settings/oidc.php` et repose sur les variables d’environnement suivantes:

- `OPENID_WELLKNOWN_URL` — URL du document de découverte OpenID Connect 1.0 (ex: `https://votre-idp/.well-known/openid-configuration`).
- `OPENID_CLIENT_ID` — identifiant du client OIDC (côté fournisseur).
- `OPENID_CLIENT_SECRET` — secret du client OIDC (côté fournisseur).
- `OPENID_REDIRECT_URI_BASE` — base d’URL publique de votre application (ex: `https://claire.example.com`). L’URI de redirection effective sera `${OPENID_REDIRECT_URI_BASE}/auth/callback` et doit être enregistrée à l’identique dans la fiche du client côté IdP.

Autres détails:
- Scopes utilisés par défaut: `openid email profile` (envoyés avec un séparateur espace, encodé en `+` dans l’URL d’autorisation).
- Endpoints utilisés: `authorization_endpoint`, `token_endpoint` et `userinfo_endpoint` découverts via le document `.well-known`.
- En cas d’échec d’authentification, l’application reste SSO‑only et ne propose pas de login/mot de passe.

Dépannage rapide:
- Erreur `invalid_client` lors de l’échange de code: vérifiez l’ID et le secret, et surtout que la méthode d’authentification configurée pour VOTRE client au token endpoint côté IdP correspond à celle attendue (souvent `client_secret_basic` ou `client_secret_post`). Assurez‑vous également que l’URI de redirection enregistrée correspond exactement à `https://votre-domaine/auth/callback`.
- Erreur de redirection: vérifiez `OPENID_REDIRECT_URI_BASE` et les règles de proxy/host (Traefik) afin que l’URL publique corresponde bien au host utilisé par les utilisateurs.

### Session Management (JWT)

Claire utilise un système de session stateless basé sur JWT. Les données de session sont stockées dans un cookie JWT signé, éliminant le besoin de stockage côté serveur.

- Configuration: `config/settings/session.php`
- Variables d'environnement obligatoires:
  - `SESSION_JWT_SECRET` — Clé secrète pour la signature JWT (obligatoire)
  - `SESSION_JWT_ALGORITHM` — Algorithme de signature (défaut: HS256)
- Routes publiques (contournent la session): configurées dans `config/settings/security.php`

Pour plus de détails, voir `src/Session/README.md`.

### Base de données (Doctrine ORM / DBAL)

Le projet inclut Doctrine ORM/DBAL et peut fonctionner avec SQLite (par défaut), MySQL/MariaDB ou PostgreSQL. La configuration est lue depuis `config/settings/database.php` et pilotée par des variables d’environnement prefixées `DATABASE_`.

- Variables d’environnement supportées:
  - `DATABASE_KIND` — pilote de base de données. Valeurs possibles: `sqlite`, `mysql`, `postgres` (ou `pgsql`).
  - `DATABASE_HOST` — hôte (MySQL/PostgreSQL uniquement).
  - `DATABASE_PORT` — port (MySQL/PostgreSQL uniquement; ex: 3306 pour MySQL, 5432 pour PostgreSQL).
  - `DATABASE_NAME` — nom de la base (MySQL/PostgreSQL uniquement).
  - `DATABASE_USER` — utilisateur (MySQL/PostgreSQL uniquement).
  - `DATABASE_PASSWORD` — mot de passe (MySQL/PostgreSQL uniquement).

- SQLite
  - Si `DATABASE_KIND=sqlite`, aucune autre variable n’est nécessaire.
  - Le fichier de base de données est créé/lu à l’emplacement par défaut: `var/database.sqlite` (chemin défini dans `config/settings/database.php`). Assurez‑vous que le processus PHP a les droits d’écriture sur le dossier `var/`.

- MySQL / MariaDB
  - Exemple minimal:
    ```env
    DATABASE_KIND=mysql
    DATABASE_HOST=localhost
    DATABASE_PORT=3306
    DATABASE_NAME=claire
    DATABASE_USER=claire
    DATABASE_PASSWORD=change_me
    ```

- PostgreSQL
  - Exemple minimal:
    ```env
    DATABASE_KIND=postgres
    DATABASE_HOST=localhost
    DATABASE_PORT=5432
    DATABASE_NAME=claire
    DATABASE_USER=claire
    DATABASE_PASSWORD=change_me
    ```

Migrations Doctrine
- Pour initialiser la base de données et/ou la mettre à jour, exécutez:

  ```bash
  ./console migrations:migrate
  ```

- Cette commande applique toutes les migrations disponibles (dossier `migrations/`) et maintient la table de version `db_version` conformément à la configuration définie dans `config/settings/database.php`.

## Démarrage

### Via PHP intégré

1. Exportez vos variables d’environnement (au besoin).
2. Lancez le serveur de développement:
   ```bash
   composer start
   ```
   ou
   ```bash
   php -S localhost:8080 -t public public/index.php
   ```
3. Ouvrez http://localhost:8080

### Via Docker

Claire fournit une image Docker complète avec Apache, PHP 8.4, et toutes les dépendances nécessaires.

#### Build de l'image

```bash
docker build -t claire:latest -f docker/Dockerfile .
```

#### Utilisation avec Docker Compose

L'extrait ci‑dessous présente une configuration Docker Compose de référence. Adaptez les variables d'environnement (OPENAPI_*, OTEL_*) et, le cas échéant, les labels Traefik à votre contexte.

```yaml
services:
  claire:
    image: claire:latest
    # Ou utilisez l'image de développement avec volumes montés:
    # image: semhoun/webserver:8.4
    # volumes:
    #   - .:/www
    volumes:
      - claire_data:/data  # Persistance des données (base SQLite, fichiers uploadés, etc.)
    ports:
      - "8080:80"
    environment:
      SERVER_ADMIN: webmaster@example.com
      SERVER_NAME: claire.example.com
      DEBUG_MODE: "false"
      DISABLE_TRACY_BAR: "true"

      DATA_PATH: /data

      # Session JWT (obligatoire)
      SESSION_JWT_SECRET: ${SESSION_JWT_SECRET:?set_me}

      # OpenTelemetry (requis)
      OTEL_PHP_AUTOLOAD_ENABLED: "true"
      OTEL_SERVICE_NAME: claire
      OTEL_PROPAGATORS: baggage,tracecontext
      OTEL_TRACES_EXPORTER: otlp
      OTEL_METRICS_EXPORTER: otlp
      # En développement, afficher les logs en console
      OTEL_LOGS_EXPORTER: console
      OTEL_LOGS_PROCESSOR: simple
      # Optionnel: configuration OTLP commune (si vous envoyez vers un collecteur)
      # OTEL_EXPORTER_OTLP_PROTOCOL: http/protobuf
      # OTEL_EXPORTER_OTLP_ENDPOINT: http://otel-collector:4318  # optionnel
      # OTEL_EXPORTER_OTLP_HEADERS: authorization=Bearer <token> # optionnel

      # LLM (remplacez par vos valeurs / variables d'env)
      OPENAPI_KEY: ${OPENAPI_KEY:?set_me}
      OPENAPI_URL: https://api.openai.com/v1
      OPENAPI_MODEL: gpt-5
      # Optionnels (précisez si vous souhaitez des modèles dédiés)
      OPENAPI_MODEL_SUMMARY: gpt-5-mini
      OPENAPI_MODEL_EMBED: text-embedding-3-large
      # NB: si `OPENAPI_MODEL_EMBED` est omis, le RAG est désactivé
      # Optionnel
      # SEARXNG_URL: http://searxng:8080

      # ComfyUI (génération d'images, optionnel)
      # COMFYUI_ENABLED: true
      # COMFYUI_URL: http://comfyui:8188
      # COMFYUI_WORKFLOW: '{"3":{"inputs":{...}},...}' # JSON avec placeholder {{PROMPT}}
      # COMFYUI_PROMPT_STYLE: flux

      # Telegram (optionnel)
      # TELEGRAM_BOT_TOKEN: ${TELEGRAM_BOT_TOKEN:?set_me}
      # TELEGRAM_WEBHOOK_SECRET: ${TELEGRAM_WEBHOOK_SECRET:?set_me}

      # OpenID Connect (SSO)
      OPENID_WELLKNOWN_URL: https://auth.example.com/.well-known/openid-configuration
      OPENID_CLIENT_ID: ${OPENID_CLIENT_ID:?set_me}
      OPENID_CLIENT_SECRET: ${OPENID_CLIENT_SECRET:?set_me}
      OPENID_REDIRECT_URI_BASE: https://claire.example.com

      # Base de données
      # Choix simple (par défaut): SQLite, aucune autre variable requise
      DATABASE_KIND: sqlite

      # Exemple MySQL/MariaDB (décommentez et ajustez)
      # DATABASE_KIND: mysql
      # DATABASE_HOST: mysql
      # DATABASE_PORT: 3306
      # DATABASE_NAME: claire
      # DATABASE_USER: claire
      # DATABASE_PASSWORD: change_me

      # Exemple PostgreSQL (décommentez et ajustez)
      # DATABASE_KIND: postgres
      # DATABASE_HOST: postgres
      # DATABASE_PORT: 5432
      # DATABASE_NAME: claire
      # DATABASE_USER: claire
      # DATABASE_PASSWORD: change_me
    healthcheck:
      test: ["CMD", "curl", "--fail", "http://localhost/health"]
      interval: 15s
      timeout: 5s
      retries: 3
      start_period: 60s

volumes:
  claire_data:

networks:
  internal:
    external: true
    name: internal
```

#### Points clés de l'image Docker

- **Base**: Debian Trixie Slim avec PHP 8.4
- **Services**: Apache + PHP-FPM + Supervisor + fcron
- **Extensions PHP**: SQLite3, MySQL, PostgreSQL, GD, Imagick, Redis, Memcache, etc.
- **OpenTelemetry**: Extension pré-installée et configurée
- **Volumes**: 
  - `/data` — Données persistantes (base SQLite, fichiers uploadés, cache, etc.)
  - `/www` — Code de l'application (pré-copié dans l'image, ou monté en dev)
- **Healthcheck**: Vérifie automatiquement l'endpoint `/health`
- **Port**: Expose le port 80 (HTTP)

#### Démarrage

```bash
# Démarrer la stack
docker compose up -d

# Voir les logs
docker compose logs -f claire

# Exécuter des commandes dans le conteneur
docker compose exec claire ./console migrations:migrate
docker compose exec claire ./console cache:clear
```

#### Développement avec volumes montés

Pour un développement actif avec rechargement automatique, utilisez l'image `semhoun/webserver:8.4` avec un volume monté:

```yaml
services:
  claire:
    image: semhoun/webserver:8.4
    volumes:
      - .:/www
    # ... reste de la configuration
```

## API et routes

### Healthcheck

- `GET /health` — endpoint JSON simple pour supervision (liveness/readiness).
  - Réponse 200 (exemple):
    ```json
    {
      "version": "1.0.0",
      "date": "2025-01-01T12:34:56+00:00"
    }
    ```
  - Utilisation typique: sonde de conteneur/orchestrateur (Docker, Traefik, Kubernetes, etc.).
  - Aucun corps requis, pas d’authentification par défaut.

### Gestion des fichiers (/files)

- `GET /files/count` — Nombre total de fichiers stockés
- `GET /files/list` — Liste des fichiers (JSON)
- `POST /files/upload` — Upload de fichier(s) standard
- `POST /files/upload_rag` — Upload de fichier pour le RAG (vectorisation)
- `DELETE /files/delete/{id}` — Suppression d'un fichier
- `GET /files/generated/{filename}` — Récupération d'une image générée

### Historique des conversations (/history)

- `GET /history/count` — Nombre de conversations
- `GET /history/list` — Liste des conversations
- `GET /history/open/{threadId}` — Ouvrir une conversation existante
- `POST /history/new` — Créer une nouvelle conversation
- `DELETE /history/delete/{threadId}` — Supprimer une conversation

### Configuration (/config)

- `POST /config/chat_mode` — Changer le mode de chat (sync/stream)
- `POST /config/layout_mode` — Changer le mode d'affichage
- `POST /config/brain_avatar` — Changer l'avatar/cerveau actif
- `POST /config/telegram` — Configurer Telegram
- `GET /config/telegram_form` — Formulaire de configuration Telegram

### Streaming SSE (Server‑Sent Events) et proxy HTTP

L’endpoint de streaming utilise le type `text/event-stream` et écrit au fil de l’eau (pas de buffer côté application). Cependant, certains reverse proxies / frontaux web peuvent bufferiser la réponse et empêcher l’affichage progressif côté navigateur.

Pour garantir un streaming fluide, désactivez la bufferisation au niveau du proxy. Exemple avec Apache quand PHP est servi via `mod_proxy_fcgi`:

```apache
# Assurez-vous que mod_proxy et mod_proxy_fcgi sont activés
# a2enmod proxy proxy_fcgi

# Votre mapping vers PHP-FPM (à adapter à votre environnement)
# ProxyPassMatch ^/(.*\.php(/.*)?)$ fcgi://127.0.0.1:9000/var/www/html/$1

# Désactiver la bufferisation pour le backend FastCGI
<Proxy "fcgi://localhost/" flushpackets=on flushwait=20>
</Proxy>
```

Notes:
- Remplacez l’URL FastCGI (`fcgi://localhost/` ou `fcgi://127.0.0.1:9000/…`) par celle de votre instance PHP‑FPM.
- Selon la version d’Apache, vous pouvez également utiliser `ProxySet` à l’intérieur du bloc `<Proxy>`:
  ```apache
  <Proxy "fcgi://127.0.0.1:9000">
      ProxySet flushpackets=on flushwait=20
  </Proxy>
  ```
- Si vous utilisez un autre proxy (Nginx, Traefik, etc.), désactivez la bufferisation équivalente (ex. Nginx: `proxy_buffering off;` sur l’emplacement concerné).

### Endpoint de chat: POST /brain/chat

L’agent supporte deux modes de réponse:

- Mode « chat » (synchrone): la réponse complète est rendue côté serveur et renvoyée en une fois.
- Mode streaming SSE: la réponse est envoyée progressivement via un flux `text/stream` (utilise une sortie non tamponnée côté application; veillez à désactiver la bufferisation côté proxy, voir plus haut).

La sélection du mode peut se faire via le champ `mode` dans le corps de la requête (`chat` par défaut, ou `stream`).

#### Requête JSON simple (mode chat)

```http
POST /brain/chat HTTP/1.1
Content-Type: application/json

{
  "message": "Bonjour Claire !",
  "mode": "chat"
}
```

Réponses possibles:
- 200: corps HTML fragment (ex.: rendu Twig `partials/message.twig`) ou contenu texte selon l’intégration front.
- 422: si le champ `message` est vide.

#### Streaming SSE (mode stream)

```http
POST /brain/chat HTTP/1.1
Accept: text/event-stream
Content-Type: application/json

{
  "message": "Explique-moi la relativité en 3 points",
  "mode": "stream"
}
```

La réponse aura des en-têtes: `content-type: text/stream`, `cache-control: no-cache`. Les chunks contiendront du texte (et potentiellement des informations d’outillage) au fil de l’eau.

#### Pièces jointes et fichiers

Deux mécanismes sont pris en charge côté serveur pour enrichir le contexte utilisateur:

1) Upload direct de fichiers via `multipart/form-data` avec le champ `upload_files[]`.
2) Référence à des fichiers déjà connus de l’application via une liste d’identifiants `file_ids` (IDs de l’entité `App\Entity\File`).

Exemple multipart (upload direct):

```http
POST /brain/chat HTTP/1.1
Content-Type: multipart/form-data; boundary=----BOUND

------BOUND
Content-Disposition: form-data; name="message"

Analyse ces documents, s'il te plaît.
------BOUND
Content-Disposition: form-data; name="mode"

chat
------BOUND
Content-Disposition: form-data; name="upload_files[]"; filename="notes.txt"
Content-Type: text/plain

(contenu du fichier)
------BOUND--
```

Exemple JSON (fichiers déjà stockés):

```http
POST /brain/chat HTTP/1.1
Content-Type: application/json

{
  "message": "Utilise mes fichiers pour répondre.",
  "mode": "chat",
  "file_ids": ["e7a4f2d8-...", "6b0e9c1a-..."]
}
```

Comportement serveur:
- Si `message` est vide ou absent → 422.
- Les fichiers uploadés sont lus en mémoire et intégrés au contexte interne du message utilisateur.
- Les erreurs de lecture d’un fichier n’interrompent pas la requête: elles sont journalisées (best‑effort).

## Sécurité

- Ne commitez jamais vos clés ou secrets (`OPENAPI_KEY`, etc.).
- En production, désactivez `DEBUG_MODE` et vérifiez les permissions du répertoire `var/` (cache, logs, tmp).
- Si l’agent dispose d’outils web (lecture d’URL, recherche), restreignez l’accès public ou placez l’instance derrière une authentification/reverse proxy.
- Configurez le CORS en amont si vous exposez l’API à des origines externes.

### Bot Telegram

Claire peut être utilisée comme bot Telegram avec deux modes de fonctionnement : **webhook** (recommandé pour la production) ou **daemon** (polling, utile pour le développement local).

#### Vue d'ensemble

L'intégration Telegram permet d'interagir avec Claire directement depuis l'application Telegram. Le bot supporte les messages texte, les photos et les documents, avec une historique de conversation persistante par utilisateur.

#### Prérequis

1. Créez un bot via [@BotFather](https://t.me/BotFather) sur Telegram.
2. Récupérez le token API fourni (format: `123456789:ABCdefGHIjklMNOpqrsTUVwxyz`).

#### Configuration

Variable d'environnement (définie dans `.env` ou via Docker):

| Variable | Obligatoire | Description |
|----------|-------------|-------------|
| `TELEGRAM_BOT_TOKEN` | Oui | Token du bot fourni par @BotFather |
| `TELEGRAM_WEBHOOK_SECRET` | Recommandé | Token secret pour sécuriser le webhook (générez une chaîne aléatoire de 32+ caractères) |

#### Mode Webhook (recommandé pour la production)

Le mode webhook permet à Telegram d'envoyer les mises à jour directement à votre serveur via HTTPS.

**Configuration du webhook:**

1. Définissez `TELEGRAM_WEBHOOK_SECRET` dans votre fichier `.env` (générez une chaîne aléatoire sécurisée)
2. Configurez le webhook:

```bash
# Option 1: Utiliser --domain (recommandé, HTTPS forcé)
./console telegram:webhook --domain=votre-domaine.com

# Option 2: Utiliser --url (URL complète personnalisée)
./console telegram:webhook --url=https://votre-domaine.com/webhook/telegram

# Vérifier le statut
./console telegram:webhook --info

# Supprimer le webhook (revenir au mode polling manuel)
./console telegram:webhook --delete
```

**Points d'entrée:**
- Endpoint webhook: `POST /webhook/telegram`
- Doit être accessible publiquement en HTTPS
- Vérifie le header `X-Telegram-Bot-Api-Secret-Token` (si `TELEGRAM_WEBHOOK_SECRET` est configuré)

**Exemple Docker Compose (webhook):**
```yaml
environment:
  TELEGRAM_BOT_TOKEN: ${TELEGRAM_BOT_TOKEN:?set_me}
  TELEGRAM_WEBHOOK_SECRET: ${TELEGRAM_WEBHOOK_SECRET:?set_me}
```

#### Mode Daemon (polling, utile pour le développement)

Le mode daemon interroge périodiquement les serveurs Telegram pour récupérer les nouveaux messages.

**Lancer le daemon:**

```bash
# Mode simple
./console telegram:daemon

# Avec options personnalisées
./console telegram:daemon --timeout=60 --limit=50

# Arrêt gracieux: Ctrl+C
```

**Options disponibles:**
- `--timeout`: Timeout des requêtes long polling (défaut: 30s)
- `--limit`: Nombre maximum de messages à récupérer par requête (défaut: 100)

**Configuration Docker Compose (daemon):**
```yaml
services:
  claire:
    # ... configuration web ...
    environment:
      TELEGRAM_BOT_TOKEN: ${TELEGRAM_BOT_TOKEN:?set_me}
```

#### Fonctionnalités

Le bot Telegram supporte:

- **Messages texte**: Dialogue standard avec historique persistant
- **Photos**: Envoi d'images avec analyse (si un modèle de vision est configuré)
- **Documents**: Upload de fichiers pour analyse
- **Génération d'images**: Création d'images via ComfyUI (si configuré)
- **Changement de cerveau**: Commande `/<nom_du_cerveau>` pour basculer d'avatar
- **Commandes intégrées:**
  - `/start` — Démarrer une nouvelle conversation
  - `/help` — Afficher l'aide
  - `/list` — Lister les personnalités
  - `/brain` — Voir ou changer de personnalité

#### Configuration de la base de données

Le bot Telegram nécessite une colonne supplémentaire dans la table des utilisateurs pour stocker l'identifiant Telegram:

```bash
./console migrations:migrate
```

#### Exemples d'utilisation

**Démarrer une conversation:**
```
/start
Bonjour Claire, peux-tu m'aider avec un problème de mathématiques?
```

**Changer de cerveau:**
```
/einstein
Quelle est la théorie de la relativité restreinte?
```

**Envoyer une photo:**
- Joindre une image avec ou sans légende
- Le bot analyse l'image si un modèle de vision est configuré

**Envoyer un document:**
- Joindre un fichier PDF, texte, etc.
- Le bot peut analyser le contenu du document

**Demander une génération d'image:**
```
Claire, peux-tu générer une image d'un chat sur la lune?
```
- L'agent génère l'image via ComfyUI et l'envoie sur Telegram
- Nécessite la configuration de ComfyUI (voir section dédiée)

## Développement & Qualité

### Commandes Composer

- Démarrer le serveur de développement:
  ```bash
  composer start
  ```

- Rector (modernisation PHP 8.4):
  - Vérifier: `composer rector-check`
  - Appliquer: `composer rector-fix`

- PHP Insights (qualité):
  - Vérifier: `composer insights-check`
  - Corriger: `composer insights-fix`

- Pre-commit (tous les checks):
  ```bash
  composer pre-commit
  ```
  Corrige les fins de ligne (LF), applique PHP Insights et Rector.

### Commandes Console

Le fichier `./console` fournit des commandes pour la gestion du cache et des migrations:

- **Cache:**
  - `./console cache:clear` — Vide le cache (conteneur, routes, etc.)
  - `./console cache:init` — Initialise/régénère le cache
  - `./console generate:proxies` — Génère les proxies Doctrine

- **Migrations Doctrine:**
  - `./console migrations:migrate` — Applique les migrations
  - `./console migrations:diff` — Génère une migration depuis les entités
  - `./console migrations:generate` — Crée une migration vide
  - `./console migrations:status` — Affiche le statut des migrations

- **Telegram:**
  - `./console telegram:webhook --url=https://...` — Configure le webhook
  - `./console telegram:webhook --domain=...` — Configure le webhook avec domaine (HTTPS forcé)
  - `./console telegram:webhook --info` — Vérifie le statut du webhook
  - `./console telegram:webhook --delete` — Supprime le webhook
  - `./console telegram:daemon` — Lance le daemon (polling)
  - `./console telegram:set-commands` — Configure le menu des commandes du bot Telegram (SetMyCommands)

### Tests

PHPUnit est configuré pour les tests unitaires:

```bash
vendor/bin/phpunit                              # Tous les tests
vendor/bin/phpunit test/Unit/Services/FooTest.php      # Fichier spécifique
vendor/bin/phpunit --filter testMethodName    # Méthode spécifique
```

### Debug

- Slim Tracy (debug console) est activable en mode debug via la configuration.
- Définissez `DISABLE_TRACY_BAR=false` pour activer la barre de debug.

## Dépannage

- 500 au `GET /`:  vérifiez les permissions du dossier var/ (cache, logs, tmp).
- 404 partout: vérifiez que le serveur pointe bien sur `public/index.php` et que vos règles de réécriture sont actives.
- Pas de logs: les logs sont gérés par OpenTelemetry. Pour les voir dans la console, définissez `OTEL_LOGS_EXPORTER=console` (et `OTEL_LOGS_PROCESSOR=simple` pour un affichage immédiat). En alternance, configurez un export OTLP (`OTEL_LOGS_EXPORTER=otlp`) vers un collecteur comme l’OTel Collector.
- RAG inactif: assurez-vous que `OPENAPI_MODEL_EMBED` est défini. S’il est absent, le RAG est désactivé par conception.
- Génération d'images non disponible: vérifiez que `COMFYUI_ENABLED=true` et que `COMFYUI_WORKFLOW` contient un workflow JSON valide avec le placeholder `{{PROMPT}}`. Assurez-vous que l'instance ComfyUI est accessible à l'URL définie dans `COMFYUI_URL`.

## Licence

Ce projet est distribué sous licence MIT. Voir le fichier `LICENSE` pour plus d’informations.