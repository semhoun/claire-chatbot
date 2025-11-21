# Claire — Agent de Chat IA (PHP, Slim 4)

Claire est une application web minimaliste de chat IA construite avec Slim 4 et Twig. Elle s’appuie sur la bibliothèque neuron-core pour piloter un LLM compatible OpenAI et fournit une petite interface web ainsi qu’un endpoint API pour échanger des messages.

## Sommaire

- Présentation
- Fonctionnalités
- Pile technique
- Prérequis
- Installation
- Configuration (LLM, logs, observabilité)
- Démarrage
- API et routes
- Développement & Qualité
- Déploiement avec Docker/Traefik
- Dépannage
- Licence

## Présentation

Claire est un agent conversationnel simple dont l’instruction système par défaut est définie dans `App\Services\Brain`. Elle conserve l’historique de session côté serveur et renvoie les réponses du modèle sous forme de snippets Markdown rendus par Twig.

## Fonctionnalités

- Interface web de chat basique (Twig + CSS).
- Endpoint API `POST /brain/chat` pour envoyer un message et récupérer la réponse de l’agent.
- Healthcheck `GET /health` (JSON) pour la supervision.
- Intégration d’un fournisseur LLM « OpenAI-like » (URL, clé et modèle configurables).
- Journalisation via Monolog (fichier ou stdout en mode Docker).
- Intégration OpenTelemetry et Inspector APM (optionnelles).

## Pile technique

- PHP 8.2+
- [Slim 4](https://www.slimframework.com/) (routing, middlewares)
- [PHP-DI](https://php-di.org/) (container)
- [Twig](https://twig.symfony.com/) (templates)
- [Monolog](https://github.com/Seldaek/monolog) (logs)
- [Doctrine ORM](https://www.doctrine-project.org/) (présent, non requis pour l’usage basique)
- [neuron-core/neuron-ai](https://packagist.org/packages/neuron-core/neuron-ai)
- OpenTelemetry, Inspector APM (optionnels)

## Prérequis

- PHP 8.2 ou supérieur avec les extensions:
  - `ext-json`
  - `ext-sqlite3` (exigée par le projet; Doctrine est installé mais pas nécessairement utilisé pour le chat)
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
  - `DEBUG_MODE` = `true|false` (active les logs plus verbeux)
  - `DOCKER_MODE` = `true|false` (redirige les logs Monolog vers stdout)

- Observabilité (optionnelle):
  - `INSPECTOR_INGESTION_KEY` — clé Inspector APM
  - Variables OpenTelemetry courantes: `OTEL_SERVICE_NAME`, `OTEL_TRACES_EXPORTER`, `OTEL_EXPORTER_OTLP_ENDPOINT`, etc.

Le point d’entrée des réglages de base se trouve dans `config/settings/_base_.php` et la configuration du logger dans `config/settings/logger.php`.

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

Un exemple de service est fourni dans `compose.yml` (adapté à un environnement Traefik). Ajustez les variables (`OPENAPI_*`, `OTEL_*`, etc.) puis démarrez votre stack Docker selon votre orchestration habituelle.

## API et routes

- `GET /health`
  - Retourne un JSON de statut.

- `POST /brain/chat`
  - Corps: `application/x-www-form-urlencoded` ou `application/json`
  - Champ requis: `message` (string)
  - Réponse: un fragment rendu (Markdown -> HTML) correspondant au message assistant. Ce point est pensé pour l’UI; pour un usage purement API, adaptez selon vos besoins.

Les routes sont enregistrées dans `config/routes.php` et dans les fichiers du dossier `config/routes/` (ex: `brain.php`). L’interface web est rendue via Twig (`tmpl/`).

## Développement & Qualité

Outils disponibles:

- Rector (refactoring):
  - Vérifier: `composer rector-check`
  - Appliquer: `composer rector-fix`

- PHP Insights (qualité):
  - Vérifier: `composer insights-check`
  - Corriger: `composer insights-fix`

- Slim Tracy (debug console) peut être activé en mode debug si configuré.

## Déploiement avec Docker/Traefik

Le fichier `compose.yml` fournit un exemple d’intégration avec Traefik et un conteneur web (image `semhoun/webserver:8.4`). Les logs sont envoyés sur `stdout` et plusieurs variables d’environnement d’observabilité sont déjà prévues.

Points d’attention:

- Ne commitez pas vos clés `OPENAPI_KEY` en clair.
- En production, vérifiez les permissions du dossier `var/` (cache, logs, tmp) et la désactivation de `DEBUG_MODE`.

## Dépannage

- 404 partout: vérifiez que le serveur pointe bien sur `public/index.php` et que vos règles de réécriture sont actives.
- 500 au `POST /brain/chat`: assurez-vous que `OPENAPI_URL`, `OPENAPI_KEY` et `OPENAPI_MODEL` sont correctement définis et que le réseau sortant fonctionne.
- Pas de logs: en mode Docker, les logs partent sur `stdout`; hors Docker, voir `var/log/app.log`.

## Licence

Ce projet est distribué sous licence MIT. Voir le fichier `LICENSE` pour plus d’informations.