# Claire — Agent de Chat IA (PHP, Slim 4)

Claire est une application web minimaliste de chat IA construite avec Slim 4 et Twig. Elle s’appuie sur la bibliothèque neuron-core pour piloter un LLM compatible OpenAI et fournit une petite interface web ainsi qu’un endpoint API pour échanger des messages.

## Fonctionnalités

- Interface web de chat basique (Twig + CSS).
- Endpoint API `POST /brain/chat` pour envoyer un message et récupérer la réponse de l’agent.
- Healthcheck `GET /health` (JSON) pour la supervision.
- Intégration d’un fournisseur LLM « OpenAI-like » (URL, clé et modèle configurables).
- Journalisation via Monolog, exportée par OpenTelemetry.
- Intégration OpenTelemetry pour traces/metrics/logs.

## Pile technique

- PHP 8.2+
- [Slim 4](https://www.slimframework.com/) (routing, middlewares)
- [PHP-DI](https://php-di.org/) (container)
- [Twig](https://twig.symfony.com/) (templates)
- [Monolog](https://github.com/Seldaek/monolog) (logs)
- [Doctrine ORM](https://www.doctrine-project.org/) (présent, non requis pour l’usage basique)
- [Neuron AI](https://www.neuron-ai.dev/) (agent LLM)
- [OpenTelemetry](https://opentelemetry.io/docs/languages/php/) (observabilité)

## Prérequis

- PHP 8.4 ou supérieur avec les extensions:
  - `ext-json`
  - `ext-sqlite3` ou 'ext-mysql' ou 'ext-pgsql'
  - `ext-libxml`
- Composer

## Installation

1. Cloner le dépôt puis installer les dépendances:
   ```bash
   composer install
   ```
2. (Optionnel) Si vous comptez utiliser les migrations/Doctrine, initialisez votre base de données selon vos besoins.

## Configuration

Les paramètres sont chargés depuis `config/settings/*.php` et complétés par des variables d’environnement. Les clés importantes:

- LLM (voir `config/settings/llm.php`):
  - `OPENAPI_KEY`   — clé d’API du fournisseur (compatible OpenAI)
  - `OPENAPI_URL`   — base URL de l’API (ex: https://api.openai.com/v1 ou un proxy type LiteLLM)
  - `OPENAPI_MODEL` — identifiant du modèle (ex: gpt-4o-mini, gpt-5.1, etc.)

- Mode et logs:
  - `DEBUG_MODE` = `true|false` (active un niveau de logs plus verbeux)

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

### Via Docker (exemple)

L’extrait ci‑dessous présente une configuration Docker Compose de référence. Adaptez les variables d’environnement (OPENAPI_*, OTEL_*) et, le cas échéant, les labels Traefik à votre contexte.

```yaml
services:
  claire:
    image: semhoun/webserver:8.4
    volumes:
      - .:/www
    environment:
      SERVER_ADMIN: webmaster@example.com
      DEBUG_MODE: "true"

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
      OPENAPI_MODEL: gpt-4o-mini
      # Optionnel
      # SEARXNG_URL: http://searxng:8080

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

networks:
  internal:
    external: true
    name: internal
```

Démarrez ensuite votre stack avec votre orchestrateur habituel (ex. `docker compose up -d`).

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

## Sécurité

- Ne commitez jamais vos clés ou secrets (`OPENAPI_KEY`, etc.).
- En production, désactivez `DEBUG_MODE` et vérifiez les permissions du répertoire `var/` (cache, logs, tmp).
- Si l’agent dispose d’outils web (lecture d’URL, recherche), restreignez l’accès public ou placez l’instance derrière une authentification/reverse proxy.
- Configurez le CORS en amont si vous exposez l’API à des origines externes.

## Développement & Qualité

Outils disponibles:

- Rector (refactoring):
  - Vérifier: `composer rector-check`
  - Appliquer: `composer rector-fix`

- PHP Insights (qualité):
  - Vérifier: `composer insights-check`
  - Corriger: `composer insights-fix`

- Slim Tracy (debug console) peut être activé en mode debug si configuré.

## Dépannage

- 500 au `GET /`:  vérifiez les permissions du dossier var/ (cache, logs, tmp).
- 500 au `POST /brain/chat`: assurez-vous que `OPENAPI_URL`, `OPENAPI_KEY` et `OPENAPI_MODEL` sont correctement définis et que le réseau sortant fonctionne.
- 404 partout: vérifiez que le serveur pointe bien sur `public/index.php` et que vos règles de réécriture sont actives.
- Pas de logs: les logs sont gérés par OpenTelemetry. Pour les voir dans la console, définissez `OTEL_LOGS_EXPORTER=console` (et `OTEL_LOGS_PROCESSOR=simple` pour un affichage immédiat). En alternance, configurez un export OTLP (`OTEL_LOGS_EXPORTER=otlp`) vers un collecteur comme l’OTel Collector.

## Todo
- Gérer les erreurs dans les outils

## Licence

Ce projet est distribué sous licence MIT. Voir le fichier `LICENSE` pour plus d’informations.