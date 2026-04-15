# Claire — Agent de Chat IA (PHP, Slim 4)

![PHP Version](https://img.shields.io/badge/PHP-8.5%2B-777bb4?logo=php&logoColor=white) ![Slim](https://img.shields.io/badge/Slim-4.x-4B4B4B) ![FrankenPHP](https://img.shields.io/badge/FrankenPHP-Caddy-ffb300) ![License](https://img.shields.io/badge/License-MIT-blue) [![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/semhoun/claire-chatbot)

> **Version 1.4.4** — Chatbot IA avec support multi-brain, Telegram, ComfyUI et OpenTelemetry

Claire est une application web de chat IA construite avec Slim 4, Twig et Neuron AI. Elle fournit une interface web, un endpoint API, une integration Telegram et un runtime Docker base sur FrankenPHP/Caddy pour piloter des LLM compatibles OpenAI.

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

Image Docker prete a l'emploi: [semhoun/claire-chatbot](https://hub.docker.com/r/semhoun/claire-chatbot)

```bash
docker build -t claire:latest -f docker/Dockerfile .
docker run -d -p 8080:80 \
  -e OPENAPI_KEY=votre-clé-api \
  -e SESSION_JWT_SECRET=$(openssl rand -hex 32) \
  -v claire_data:/opt/data \
  claire:latest
```

## Fonctionnalités

- Interface web de chat avec streaming SSE, horodatage, suppression du dernier message, affichage instantane du message utilisateur, raccourci pour revenir en bas de conversation et ajustements visuels sur les themes et la mise en page.
- Endpoint API `POST /brain/messages` pour envoyer un message au traitement asynchrone du chat web.
- Healthcheck `GET /health` (JSON) pour la supervision.
- Intégration d'un fournisseur LLM « OpenAI-like » (URL, clé et modèle configurables).
- Ajout automatique de la date et l'heure courantes dans le contexte système des agents.
- Memoire courte avec resume automatique pour contenir l'historique dans la fenetre de contexte.
- Recherche web optionnelle via SearXNG et RAG fichier via embeddings OpenAI-like.
- Génération d'images avec ComfyUI (optionnel), avec sélection et persistance du workflow par utilisateur depuis le web et Telegram.
- Support d'agents personnalisés via classes PHP et fichiers YAML.
- Intégration Telegram avec filtrage des balises `[OC]`, prise en charge des photos et documents, meilleur rendu MarkdownV2 des listes, association facultative d'un compte utilisateur via identifiant Telegram, et mise en queue des updates via un worker CLI Redis.
- Observabilite via OpenTelemetry pour traces, metriques et logs, y compris l'instrumentation interne des agents.
- Historique de conversation avec separation entre messages LLM et messages affiches a l'utilisateur, y compris conservation du message d'ouverture dans l'affichage.

## Pile technique

- PHP 8.5+
- [Slim 4](https://www.slimframework.com/) (routing, middlewares)
- [PHP-DI](https://php-di.org/) (container)
- [Twig](https://twig.symfony.com/) (templates)
- [Monolog](https://github.com/Seldaek/monolog) (logs)
- [Doctrine ORM](https://www.doctrine-project.org/) (persistance des données)
- [Neuron AI](https://www.neuron-ai.dev/) (agent LLM)
- [phptg/bot-api](https://github.com/phptg/bot-api) (bot Telegram)
- [OpenTelemetry](https://opentelemetry.io/docs/languages/php/) (observabilité)
- [Symfony YAML](https://symfony.com/doc/current/components/yaml.html) (cerveaux personnalisés)
- FrankenPHP + Caddy (runtime HTTP principal en conteneur)
- Docker (déploiement containerisé avec Supervisor)

## Prérequis

- PHP 8.5 ou supérieur avec les extensions:
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
  - `OPENAPI_CONTEXT_WINDOW` — taille de fenêtre de contexte cible utilisée par l'agent (défaut: `50000`)
  - `llm.shortMemory.messageToKeep` — nombre de messages recents conserves lors d'un resume automatique
  - `llm.shortMemory.maxTokens` — seuil de tokens a partir duquel la memoire courte compacte l'historique
  - `llm.summary.minMessages` / `llm.summary.maxMessages` — bornes utilisees pour declencher la generation du titre ou resume de conversation
  - `llm.workflow.timeout` — duree maximale d'execution d'un workflow d'agent
  - `SEARXNG_URL` — URL de l'instance SearXNG pour la recherche web (optionnel)

- Telegram (voir `config/settings/telegram.php`):
  - `TELEGRAM_BOT_TOKEN` — Token de votre bot Telegram (obtenu via @BotFather).
  - `TELEGRAM_WEBHOOK_SECRET` — secret partagé optionnel/recommandé pour sécuriser les webhooks Telegram.

- OpenID Connect / SSO (voir `config/settings/oidc.php`):
  - `OPENID_WELLKNOWN_URL` — URL du document de découverte OpenID Connect.
  - `OPENID_CLIENT_ID` — identifiant du client OIDC.
  - `OPENID_CLIENT_SECRET` — secret du client OIDC.
  - `OPENID_REDIRECT_URI_BASE` — URL publique racine de l'application; le callback effectif est `${OPENID_REDIRECT_URI_BASE}/auth/callback`.

- Queue (voir `config/settings/queue.php` et `config/settings/redis.php`):
  - `REDIS_HOST` — hote Redis (defaut: `127.0.0.1`).
  - `REDIS_PORT` — port Redis (defaut: `6379`).
  - `REDIS_DATABASE` — base Redis numerique (defaut: `0`).
  - `REDIS_PASSWORD` — mot de passe Redis (optionnel).
  - `REDIS_TIMEOUT` — timeout de connexion Redis en secondes (defaut: `2.0`).
  - `REDIS_READ_TIMEOUT` — timeout de lecture Redis en secondes (defaut: `5.0`). Évite les blocages indéfinis sur les opérations réseau.
  - `REDIS_PREFIX` — prefixe des cles Redis pour la queue.
  - `queue.timeout` — timeout par défaut pour l'opération BRPOP en secondes (defaut: `5`).

- Mode et logs:
  - `DEBUG_MODE` = `true|false` (active un niveau de logs plus verbeux)
  - `ENABLE_LETSENCRYPT` = `true|false` (active HTTPS automatique via Let's Encrypt dans le conteneur FrankenPHP/Caddy)
  - `ACME_EMAIL` — email utilisé pour l'enregistrement ACME/Let's Encrypt (optionnel)

- Stockage des fichiers et données:
  - `DATA_PATH` — chemin racine pour les données persistantes (base de données SQLite, fichiers utilisateur, artefacts stockés; par défaut: `var/data`). Ce répertoire est prévu pour les contenus générés et les données métier persistées, mais pas pour le cache applicatif.
  - `ADDONS_PATH` — chemin racine des extensions métier chargeables à chaud (par défaut: `var/addons`). Il contient notamment `agents/` pour les cerveaux YAML et `comfyui/` pour les workflows ComfyUI personnalisés. Si `${ADDONS_PATH}/agents` n'existe pas, le contenu de `${ADDONS_PATH}` est initialisé avec les fichiers par défaut fournis par l'application.
  - `FILES_PATH` — chemin vers le répertoire de stockage des fichiers (par défaut: `var/filer`)

- Observabilité (OpenTelemetry — requis):
  - Journalisation OpenTelemetry: les logs de l’application sont émis via l’intégration Monolog/OpenTelemetry.
  - Pour afficher les logs dans la console en développement, définissez `OTEL_LOGS_EXPORTER=console` (et éventuellement `OTEL_LOGS_PROCESSOR=simple`).
  - Variables d’environnement principales (par signal):
    - Générales
      - `OTEL_PHP_AUTOLOAD_ENABLED` — active l’auto‑instrumentation PHP (true/false).
      - `OTEL_SERVICE_NAME` — nom du service (utilisé par les 3 signaux).
      - `OTEL_RESOURCE_ATTRIBUTES` — attributs ressource supplémentaires (ex: `deployment.environment=dev,service.version=1.4.1`).
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

L'application permet de sélectionner différents « cerveaux » (avatars) pour l'agent (ex.: Claire, Einstein, Calliope). La sélection est mémorisée en session sous la clé `brain_avatar`.

- La logique de sélection est gérée par le registre `BrainRegistry`.
- Si une valeur invalide est fournie, l'application revient automatiquement sur l'avatar par défaut: `claire`.
- Vous pouvez exposer ce choix dans l'UI (ex.: select) ou via un paramètre de requête selon vos besoins.
- Cerveaux disponibles par défaut:
  - `claire` — Assistante généraliste
  - `einstein` — Expert scientifique
  - `calliope` — Calliope la conteuse

#### Ajout de cerveaux personnalisés via YAML

Vous pouvez créer vos propres agents sans écrire de code PHP en plaçant des fichiers YAML dans le répertoire `addons/agents/`.

Par défaut, ce chemin correspond à `${ADDONS_PATH}/agents`, avec `ADDONS_PATH=var/addons` si la variable n'est pas définie. Cela permet de séparer clairement:

- `DATA_PATH` pour les données persistantes générées par l'application
- `ADDONS_PATH` pour les ressources personnalisées fournies par l'exploitant

Au premier démarrage, si `${ADDONS_PATH}/agents` n'existe pas encore, l'application initialise `${ADDONS_PATH}` avec les fichiers par défaut afin de fournir une base immédiatement exploitable.

Chaque fichier définit un nouveau cerveau avec ses propres caractéristiques.

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

**Variables dans les instructions:**

Tous les agents (PHP et YAML) supportent les variables dans leurs instructions :

| Variable | Description |
|----------|-------------|
| `{{USER}}` | Remplacée par le nom d'affichage de l'utilisateur (si disponible) |

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
- Voir `addons/agents/calliope.yaml` pour un exemple complet avec CSS inline

**Points importants:**

- Le slug du cerveau est déterminé par le nom du fichier (ex: `coach.yaml` → slug `coach`)
- Les fichiers YAML sont chargés automatiquement au démarrage
- Un message d'accueil est choisi aléatoirement parmi la liste `welcomes`
- Les cerveaux YAML apparaissent automatiquement dans l'interface aux côtés des cerveaux PHP
- Voir le fichier exemple dans `addons/agents/calliope.yaml` (exemple complet d'un cerveau YAML avec CSS inline)

### Blocs internes `[OC]`

Claire utilise des balises internes `[OC]...[/OC]` pour porter des instructions ou contenus de contrôle qui ne doivent pas être affichés à l'utilisateur final.

- Dans l'interface web, le filtre Twig `filter_oc_tags` supprime automatiquement ces blocs avant le rendu Markdown.
- Dans Telegram, le même filtrage est appliqué avant conversion vers `MarkdownV2`.
- Si un message ne contient plus rien après filtrage, il n'est pas affiché ni envoyé.
- Le fichier `tmpl/partials/message.twig` applique ce filtrage avant rendu.

### Contexte temporel automatique

Les agents principaux ajoutent automatiquement la date et l'heure courantes à leurs instructions système.

- Cela améliore la contextualisation des réponses sensibles au temps.
- Le comportement est implémenté dans `App\Brain\Agent` et `App\Brain\RAG`.
- Les messages de bienvenue générés par l'agent utilisent également un bloc `[OC]` pour éviter d'exposer l'instruction interne.

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
| `COMFYUI_TIMEOUT` | Non | Timeout en secondes (défaut: 300) |
| `COMFYUI_DEFAULT_WORKFLOW` | Non | Slug du workflow ComfyUI à sélectionner par défaut s'il existe |

**Exemple de configuration minimale:**

```bash
# Activation de ComfyUI
export COMFYUI_ENABLED=true
export COMFYUI_URL=http://comfyui:8188
export COMFYUI_DEFAULT_WORKFLOW=flux
```

**Workflows multiples via `addons/comfyui/`:**

Vous pouvez définir plusieurs workflows ComfyUI, comme pour les agents YAML, en ajoutant un fichier `.yaml`, `.yml` ou `.json` dans `addons/comfyui/`.

Par défaut, ce chemin correspond à `${ADDONS_PATH}/comfyui`, avec `ADDONS_PATH=var/addons` si la variable n'est pas définie.

Chaque workflow doit définir:

- `label` : libellé affiché dans l'interface web et sur Telegram
- `workflow` : JSON ComfyUI avec les placeholders `{{PROMPT}}` et `{{SEED}}`

Exemple:

```yaml
label: Portrait Flux
workflow: |
  {
    "3": {
      "inputs": {
        "seed": {{SEED}}
      },
      "class_type": "KSampler"
    },
    "6": {
      "inputs": {
        "text": "{{PROMPT}}"
      },
      "class_type": "CLIPTextEncode"
    }
  }
```

**Notes de fonctionnement:**

- Le slug du workflow est déterminé par le nom du fichier, par exemple `portrait-flux.yaml` → `portrait-flux`
- Si `COMFYUI_DEFAULT_WORKFLOW` est défini et correspond à un slug existant, il devient le workflow par défaut
- Sinon, le premier fichier trouvé dans `addons/comfyui/` devient le workflow par défaut
- Le workflow sélectionné est mémorisé dans la session utilisateur et dans ses options persistées
- Le changement est disponible dans le menu web et via la commande Telegram `/comfyui`
- Ce système n'est actif que si `COMFYUI_ENABLED=true`
- Si aucun fichier n'est présent dans `addons/comfyui/`, la génération d'image n'est pas disponible

Note: le workflow doit contenir les placeholders `{{PROMPT}}` (remplacé par la description) et `{{SEED}}` (remplacé par une valeur aléatoire).

**Fonctionnement:**
- Lorsqu'activé, l'outil `generate_image` est disponible pour tous les cerveaux
- L'agent peut décider de générer une image en réponse à une demande utilisateur
- Les images générées sont stockées dans `var/generated_images/`
- Les images sont servies via l'endpoint `/files/img_serve/{id}`
- Le bot Telegram supporte l'envoi des images générées

### Authentification OpenID Connect (SSO)

L’application utilise une authentification SSO OpenID Connect via les routes `GET /auth/sso` et `GET /auth/callback`. Si la configuration OIDC n'est pas renseignée, elle bascule automatiquement sur un utilisateur de démonstration par défaut. La configuration est lue dans `config/settings/oidc.php` et repose sur les variables d’environnement suivantes:

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

### Queue de fond

Claire inclut une queue de fond minimaliste basee sur Redis pour deleguer certains traitements hors du cycle HTTP, en particulier les updates Telegram.

- **Exécution immédiate**: Les jobs sont exécutés dès qu'ils arrivent dans Redis (pas de planification différée).
- **Blocage Redis**: Le worker utilise `BRPOP` pour attendre passivement dans Redis quand la queue est vide, sans consommer de CPU.
- **Reconnexion automatique**: Le worker détecte les déconnexions Redis et se reconnecte automatiquement si nécessaire. Si la reconnexion échoue, le worker s'arrête proprement.
- **Timeout de lecture**: Le `REDIS_READ_TIMEOUT` évite les blocages indéfinis sur les opérations réseau lorsque la connexion est silencieusement fermée.

Le contrat des jobs est `App\Queue\QueueDoer` avec `make(ContainerInterface $container)` et `handle(array $payload): void`. Le contrat applicatif suppose que `handle()` absorbe ses erreurs et ne lève pas d'exception.

Lancer le worker:

```bash
./console queue:work
```

Traiter une queue specifique (par defaut: default):

```bash
./console queue:work --queue=default
```

Options utiles:

- `--once` — traite un seul job puis quitte
- `--timeout=5` — timeout en secondes pour l'opération BRPOP (défaut: 5s)
- `--max-jobs=100` — quitte apres N jobs
- `--max-time=3600` — quitte apres N secondes

Exemple:

```bash
export REDIS_HOST=127.0.0.1
export REDIS_PORT=6379
./console queue:work --timeout=10
```

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

Claire fournit une image Docker complete basée sur FrankenPHP, Caddy et PHP 8.5.

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
    volumes:
      - claire_data:/opt/data # Persistance des données (base SQLite, fichiers uploadés, etc.)
    ports:
      - "8080:80"
    environment:
      SERVER_ADMIN: webmaster@example.com
      SERVER_NAME: claire.example.com
      DEBUG_MODE: "false"
      ENABLE_LETSENCRYPT: "false"
      # ACME_EMAIL: admin@example.com

      DATA_PATH: /opt/data

      # Queue workers (optionnel, défaut: 1)
      # QUEUE_WORKERS: 2

      # Session JWT (obligatoire)
      SESSION_JWT_SECRET: ${SESSION_JWT_SECRET:?set_me}

      # OpenTelemetry (requis)
      OTEL_PHP_AUTOLOAD_ENABLED: "true"
      OTEL_SERVICE_NAME: claire
      OTEL_PHP_EXCLUDED_URLS: /health
      OTEL_PROPAGATORS: baggage,tracecontext
      OTEL_TRACES_EXPORTER: otlp
      OTEL_METRICS_EXPORTER: otlp
      # En développement, afficher les logs en console
      OTEL_LOGS_EXPORTER: console
      OTEL_LOGS_PROCESSOR: simple
      # Optionnel: configuration OTLP commune (si vous envoyez vers un collecteur)
      # OTEL_EXPORTER_OTLP_PROTOCOL: http/protobuf
      # OTEL_EXPORTER_OTLP_ENDPOINT: http://otel-collector:4318
      # OTEL_EXPORTER_OTLP_HEADERS: authorization=Bearer <token>

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

Notes Docker/FrankenPHP:

- L'image Docker utilise FrankenPHP avec Caddy comme serveur HTTP integre.
- Si `ENABLE_LETSENCRYPT=true`, le conteneur sert directement votre domaine `SERVER_NAME` en HTTPS avec certificats Let's Encrypt automatiques.
- Si `ENABLE_LETSENCRYPT=false`, le conteneur reste en HTTP sur le port 80, ce qui est recommande derriere un reverse proxy TLS.
- Pour Let's Encrypt, le domaine doit etre publiquement resolvable vers le conteneur et les ports 80/443 doivent etre accessibles.

#### Variables d'environnement Docker

- `QUEUE_WORKERS` — Nombre de workers de queue Redis à lancer (défaut: 1). Augmentez cette valeur si vous avez besoin de traiter plus de jobs en parallèle (ex: `QUEUE_WORKERS=4`). Chaque worker tourne dans son propre processus supervisé.

#### Points clés de l'image Docker

- **Base**: Debian Trixie Slim avec PHP 8.5
- **Services**: FrankenPHP + Caddy + Supervisor
- **Extensions PHP**: SQLite3, MySQL, PostgreSQL, GD, Imagick, Redis, Memcache, etc.
- **OpenTelemetry**: Extension pré-installée et configurée
- **Modules Caddy**: `transform-encoder`, Mercure, Vulcain
- **Volumes**: 
  - `/data` — Données persistantes (base SQLite, fichiers uploadés, cache, etc.)
  - `/www` — Code de l'application (pré-copié dans l'image, ou monté en dev)
- **Healthcheck**: Vérifie automatiquement l'endpoint `/health`
- **Ports**: Expose `80` et `443`

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

## API et routes

### Healthcheck

- `GET /health` — endpoint JSON simple pour supervision (liveness/readiness).
  - Réponse 200 (exemple):
```json
{
  "version": "1.4.4",
  "date": "2026-04-15T12:34:56+00:00"
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
- `GET /files/img_serve/{id}` — Récupération d'une image générée
- Types acceptés côté interface: images, PDF, DOC, DOCX, JSON, TXT et CSV

Organisation recommandée:

- `DATA_PATH` héberge les données d'exécution et de persistance, par exemple la base SQLite, les fichiers utilisateur et d'autres artefacts runtime persistés.
- `ADDONS_PATH` héberge les éléments déclaratifs maintenus par l'utilisateur ou l'intégrateur, par exemple les agents YAML et les workflows ComfyUI.
- Le cache applicatif reste dans `var/` au sein de l'application, indépendamment de `DATA_PATH`.

### Historique des conversations (/history)

- `GET /history/count` — Nombre de conversations
- `GET /history/list` — Liste des conversations
- `GET /history/open/{threadId}` — Ouvrir une conversation existante
- `POST /history/new` — Créer une nouvelle conversation
- `DELETE /history/exchange/last` — Annuler le dernier échange de la conversation courante
- `DELETE /history/delete/{threadId}` — Supprimer une conversation

### Configuration (/config)

- `POST /config/layout_mode` — Changer le mode d'affichage
- `POST /config/brain_avatar` — Changer l'avatar/cerveau actif
- `POST /config/telegram` — Configurer Telegram
- `GET /config/telegram_form` — Formulaire de configuration Telegram

### Streaming SSE (Server‑Sent Events) et proxy HTTP

L’endpoint de streaming utilise le type `text/event-stream` et écrit au fil de l’eau (pas de buffer côté application). Avec FrankenPHP/Caddy (runtime Docker), le streaming SSE fonctionne nativement sans configuration particulière.

Si vous placez l’application derrière un reverse proxy (Nginx, Traefik, Apache, etc.), désactivez la bufferisation pour garantir un streaming fluide:

- **Nginx**: `proxy_buffering off;` sur l’emplacement concerné
- **Traefik**: ajoutez l’annotation `traefik.http.middlewares.nobuffer.buffering.maxRequestBodyBytes: 0`
- **Apache**: `ProxySet flushpackets=on` dans la configuration du proxy

### Architecture SSE (Server-Sent Events)

**Note importante**: Depuis la version 1.4.1, l'interface web fonctionne **exclusivement en mode streaming** via SSE. Le mode chat classique (synchrone) a été supprimé.

L’interface web

L’interface web repose sur deux canaux SSE distincts:

1) Un flux HTMX SSE sur `GET /brain/stream?sessionId=<id>` pour les snapshots HTML nommés `chat.snapshot`.
2) Un `EventSource` natif sur `GET /brain/stream?sessionId=<id>&mode=incremental` pour les événements JSON incrémentaux (placeholder, delta, tool updates, fin de réponse).

Le `sessionId` est généré côté serveur lors du chargement de `/`, stocké en `sessionStorage` par onglet, puis réutilisé pour:

- la connexion SSE persistante;
- l’envoi des messages via `POST /brain/messages`;
- les actions liées à l’historique qui doivent publier un snapshot vers le bon onglet.

Le frontend charge l’extension SSE HTMX localement via `public/js/sse.js`. 
Exemple simplifié de cycle web actuel:

```text
GET /                    -> rend la page + stream_session_id
GET /brain/stream        -> ouvre les flux SSE liés au sessionId
POST /brain/messages     -> met le message en file et déclenche le traitement async
SSE chat.snapshot        -> remplace la liste HTML si nécessaire
SSE incremental JSON     -> alimente le message assistant en direct
```

#### Envoi d'un message au chat web

```http
POST /brain/messages HTTP/1.1
Content-Type: multipart/form-data; boundary=----BOUND

------BOUND
Content-Disposition: form-data; name="message"

Analyse ces documents, s'il te plaît.
------BOUND
Content-Disposition: form-data; name="sessionId"

sess-abc123
------BOUND
Content-Disposition: form-data; name="upload_files[]"; filename="notes.txt"
Content-Type: text/plain

(contenu du fichier)
------BOUND--
```

Réponses possibles:
- 202: message accepté et mis en file, avec `messageArticleId` en JSON.
- 400: si `sessionId` est absent.
- 422: si le champ `message` est vide.

#### Pièces jointes et fichiers

Deux mécanismes sont pris en charge côté serveur pour enrichir le contexte utilisateur:

1) Upload direct de fichiers via `multipart/form-data` avec le champ `upload_files[]`.
2) Référence à des fichiers déjà connus de l’application via une liste d’identifiants `file_ids` (IDs de l’entité `App\Entity\File`).

Exemple multipart (upload direct):

```http
POST /brain/messages HTTP/1.1
Content-Type: multipart/form-data; boundary=----BOUND

------BOUND
Content-Disposition: form-data; name="message"

Analyse ces documents, s'il te plaît.
------BOUND
Content-Disposition: form-data; name="sessionId"

sess-abc123
------BOUND
Content-Disposition: form-data; name="upload_files[]"; filename="notes.txt"
Content-Type: text/plain

(contenu du fichier)
------BOUND--
```

Exemple multipart avec fichiers déjà stockés:

```http
POST /brain/messages HTTP/1.1
Content-Type: multipart/form-data; boundary=----BOUND

------BOUND
Content-Disposition: form-data; name="message"

Utilise mes fichiers pour répondre.
------BOUND
Content-Disposition: form-data; name="sessionId"

sess-abc123
------BOUND
Content-Disposition: form-data; name="file_ids[]"

e7a4f2d8-...
------BOUND
Content-Disposition: form-data; name="file_ids[]"

6b0e9c1a-...
------BOUND--
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

Associer un compte web a Telegram:

- Depuis l'interface web, ouvrez la configuration Telegram puis enregistrez votre identifiant numerique Telegram.
- Cet identifiant permet au bot de reconnaitre et rattacher vos interactions Telegram a votre compte utilisateur.
- Vous pouvez recuperer cet identifiant via `@userinfobot` ou `@myidbot`.

#### Mode Webhook (recommandé pour la production)

Le mode webhook permet à Telegram d'envoyer les mises à jour directement à votre serveur via HTTPS.

**Configuration du webhook:**

1. Définissez `TELEGRAM_WEBHOOK_SECRET` dans votre fichier `.env` (générez une chaîne aléatoire sécurisée)
2. Configurez le webhook puis publiez le menu des commandes du bot:

```bash
# Option 1: Utiliser --domain (recommandé, HTTPS forcé)
./console telegram:webhook --domain=votre-domaine.com

# Option 2: Utiliser --url (URL complète personnalisée)
./console telegram:webhook --url=https://votre-domaine.com/webhook/telegram

# Vérifier le statut
./console telegram:webhook --info

# Supprimer le webhook (revenir au mode polling manuel)
./console telegram:webhook --delete

# Publier les commandes Telegram configurees dans le bot
./console telegram:set-commands
```

**Points d'entrée:**
- Endpoint webhook: `POST /webhook/telegram`
- Doit être accessible publiquement en HTTPS
- Vérifie le header `X-Telegram-Bot-Api-Secret-Token` (si `TELEGRAM_WEBHOOK_SECRET` est configuré)
- Le webhook enfile l'update Telegram dans la queue `default`; le traitement effectif est fait par `queue:work`

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

#### Traitement asynchrone des updates Telegram

Les updates Telegram recus par webhook ou par daemon ne sont plus traites directement dans le process de reception.

- `TelegramService` implemente le contrat `QueueDoer`.
- Le webhook et le daemon enfilent `TelegramService::class` dans la queue `default` avec un payload JSON.
- Le worker doit etre lance separement pour traiter les messages.

Exemple minimal:

```bash
./console queue:work
```

#### Fonctionnalités

Le bot Telegram supporte:

- **Messages texte**: Dialogue standard avec historique persistant
- **Photos**: Envoi d'images avec analyse (si un modèle de vision est configuré)
- **Documents**: Upload de fichiers pour analyse
- **Génération d'images**: Création d'images via ComfyUI (si configuré)
- **Commandes intégrées:**
  - `/start` — Démarrer une nouvelle conversation
  - `/help` — Afficher l'aide
  - `/brain` — Voir ou changer de personnalité (affiche la liste si sans argument)
  - `/comfyui` — Voir ou changer le workflow ComfyUI (affiche la liste si sans argument)

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

#### Mini-App Telegram

Claire inclut une Mini-App Telegram complète qui offre une interface web embarquée directement dans l'application Telegram.

**Fonctionnalités:**

- Interface de chat fluide et responsive optimisée pour mobile
- Authentification automatique via les données d'initialisation Telegram
- Sélection du brain/personnalité directement dans la Mini-App
- Gestion des workflows ComfyUI pour la génération d'images
- Support des pièces jointes et de l'historique des conversations

**Configuration du bouton Menu:**

Pour configurer le bouton du menu Telegram pointant vers la Mini-App:

```bash
# Configurer le bouton menu avec une URL spécifique
./console telegram:set-menu-button --url=https://votre-domaine.com/telegram/webapp

# Configurer avec un type par défaut (commands, web_app)
./console telegram:set-menu-button --type=web_app --text="Ouvrir Claire" --url=https://votre-domaine.com/telegram/webapp

# Réinitialiser le bouton menu
./console telegram:set-menu-button --type=commands
```

**Accès direct:**

La Mini-App est accessible à l'URL `/telegram/webapp` et nécessite:
- Une connexion HTTPS valide (exigence Telegram)
- Le `TELEGRAM_BOT_TOKEN` configuré
- Les données d'initialisation Telegram valides (transmises automatiquement par Telegram)

**Sécurité:**

Les données d'initialisation Telegram sont validées via HMAC-SHA256 pour garantir l'authenticité. Le service `TelegramWebAppValidator` vérifie:
- L'intégrité du hash des données
- La fraîcheur de la requête (auth_date)
- La signature cryptographique

## Développement & Qualité

### Commandes Composer

- Démarrer le serveur de développement:
  ```bash
  composer start
  ```

- Rector (modernisation PHP 8.5):
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
  - `./console telegram:set-menu-button` — Configure le bouton du menu Telegram (Mini-App)

- **Queue:**
  - `./console queue:work` — Lance le worker de queue
  - `./console queue:work --queue=default` — Traite la queue par defaut (Telegram utilise cette queue)
  - `./console queue:work --once` — Traite un seul job puis quitte

### Tests

PHPUnit est configuré pour les tests unitaires:

```bash
vendor/bin/phpunit                              # Tous les tests
vendor/bin/phpunit test/Unit/Services/FooTest.php      # Fichier spécifique
vendor/bin/phpunit --filter testMethodName    # Méthode spécifique
```

## Dépannage

- 500 au `GET /`:  vérifiez les permissions du dossier var/ (cache, logs, tmp).
- 404 partout: vérifiez que le serveur pointe bien sur `public/index.php` et que vos règles de réécriture sont actives.
- Pas de logs: les logs sont gérés par OpenTelemetry. Pour les voir dans la console, définissez `OTEL_LOGS_EXPORTER=console` (et `OTEL_LOGS_PROCESSOR=simple` pour un affichage immédiat). En alternance, configurez un export OTLP (`OTEL_LOGS_EXPORTER=otlp`) vers un collecteur comme l'OTel Collector.
- RAG inactif: assurez-vous que `OPENAPI_MODEL_EMBED` est défini. S'il est absent, le RAG est désactivé par conception.
- Génération d'images non disponible: vérifiez que `COMFYUI_ENABLED=true`, qu'au moins un workflow valide existe dans `addons/comfyui/`, et que l'instance ComfyUI est accessible à l'URL définie dans `COMFYUI_URL`.
- **Worker de queue bloqué**: Le worker détecte automatiquement les déconnexions Redis et tente de se reconnecter. Si vous observez des blocages, vérifiez que `REDIS_READ_TIMEOUT` est configuré (défaut: 5.0s) et que Redis est accessible. Les logs indiqueront les tentatives de reconnexion.

## Licence

Ce projet est distribué sous licence MIT. Voir le fichier `LICENSE` pour plus d’informations.
