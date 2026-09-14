# Claire — Agent de Chat IA (PHP, Slim 4)

![PHP Version](https://img.shields.io/badge/PHP-8.5%2B-777bb4?logo=php&logoColor=white) ![Slim](https://img.shields.io/badge/Slim-4.x-4B4B4B) ![FrankenPHP](https://img.shields.io/badge/FrankenPHP-Caddy-ffb300) ![License](https://img.shields.io/badge/License-MIT-blue) [![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/semhoun/claire-chatbot)

> **Claire** — Chatbot IA Vue/TypeScript multi-brain avec Telegram, ComfyUI, PDF et OpenTelemetry

Claire est une application de chat IA construite avec Slim 4, Vue 3, TypeScript et Neuron AI. Elle s'exécute dans un conteneur Docker basé sur FrankenPHP/Caddy et fournit une interface web, une API REST, une intégration Telegram et une observabilité complète via OpenTelemetry.

La version **2.1.0** rassemble l'audio Mistral, les thèmes d'agents, l'installation PWA et les améliorations de fiabilité du chat. Consultez le [CHANGELOG](CHANGELOG.md#210---2026-09-13) et les [consignes de mise à niveau](#mise-à-niveau-vers-210) avant déploiement.

## Fonctionnalités

- Interface web de chat avec streaming SSE, horodatage, suppression du dernier message
- Application web installable (PWA) sur ordinateur et mobile, avec nom configurable et connexion Internet requise
- Mode widget embarqué (`/embed`) injecté via `window.claireEmbed(...)` pour intégration sur site tiers
- API REST `POST /brain/messages` et healthcheck `GET /health`
- Multi-brain : sélection dynamique d'agents IA (Claire, Einstein, Calliope...)
- Création d'agents personnalisés via fichiers YAML dans `/opt/addons/agents/`
- Six thèmes intégrés, personnalisables par agent et partagés entre le chat web et le widget
- Restauration de la dernière conversation après rechargement, avec isolation des générations par utilisateur et conversation
- Mémoire courte avec résumé automatique de l'historique
- Mémoire long terme optionnelle, évolutive entre les conversations et reconstructible depuis les résumés
- Recherche web via SearXNG et RAG par documents (fichiers, texte, URL) avec embeddings
- Transcription audio (dictée vocale) et synthèse vocale (TTS) avec l'API audio Mistral
- Génération d'images avec ComfyUI (workflows multiples)
- Génération de documents PDF depuis HTML ou Markdown
- Intégration Telegram complète (messages, photos, documents, audio, Mini-App)
- Queue de fond Redis pour traitements asynchrones
- Observabilité OpenTelemetry (traces, métriques, logs)
- Authentification SSO OpenID Connect obligatoire

## Pile technique

- **Runtime** : FrankenPHP + Caddy (PHP 8.5)
- **Framework** : Slim 4 avec PHP-DI
- **Frontend** : Vue 3, TypeScript et Vite
- **Rendu web** : shell HTML via `VueShell`, données HTTP/SSE préparées par `ChatDataRenderer` ; Markdown, messages et outils rendus par Vue
- **ORM** : Doctrine ORM/DBAL (SQLite, MySQL, PostgreSQL)
- **LLM** : Neuron AI avec support OpenAI-compatible
- **Queue** : Redis avec réservation, renouvellement des baux, retries et conservation des jobs en échec
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
| `REDIS_HOST` | Hôte Redis accessible depuis l'application et les workers |

### Variables optionnelles

| Variable | Description | Défaut                    |
|----------|-------------|---------------------------|
| `APP_NAME` | Nom de l'application normale et de la PWA (sans modifier les agents ni le widget) | `Claire` |
| `OPENAPI_MODEL_SUMMARY` | Modèle pour les résumés | valeur de `OPENAPI_MODEL` |
| `OPENAPI_MODEL_EMBED` | Modèle pour embeddings (RAG) | désactivé                 |
| `RAG_CHUNK_SIZE` | Taille maximale des segments RAG | `1000`                    |
| `RAG_TOP_K` | Nombre de résultats retournés par recherche RAG | `4`                       |
| `LONG_TERM_MEMORY_MAX_CHARACTERS` | Taille maximale de la mémoire long terme | `4000`                    |
| `LONG_TERM_MEMORY_UPDATE_EVERY_USER_MESSAGES` | Fréquence de mise à jour, en messages utilisateur | `5`                       |
| `LONG_TERM_MEMORY_REBUILD_BATCH_SIZE` | Nombre de résumés traités par lot lors d'une reconstruction | `20`                      |
| `OPENAPI_REQUEST_TIMEOUT` | Timeout des requêtes API (secondes) | `180`                     |
| `MISTRAL_AUDIO_ENABLED` | Active transcription et synthèse vocale | `false` |
| `MISTRAL_AUDIO_API_URL` | URL de l'API audio Mistral | `https://api.mistral.ai/v1` |
| `MISTRAL_AUDIO_API_KEY` | Clé dédiée à l'API audio Mistral | - |
| `MISTRAL_AUDIO_TRANSCRIPTION_MODEL` | Modèle de transcription imposé côté serveur | `voxtral-mini-latest` |
| `MISTRAL_AUDIO_SPEECH_MODEL` | Modèle de synthèse imposé côté serveur | `voxtral-mini-tts-2603` |
| `MISTRAL_AUDIO_VOICES` | Liste JSON des voix, ex. `[{"id":"voice-id","label":"Claire"}]` | `[]` |
| `MISTRAL_AUDIO_DEFAULT_VOICE` | Identifiant de la voix par défaut | première voix configurée |
| `MISTRAL_AUDIO_MAX_RECORDING_SECONDS` | Durée maximale d'une dictée web | `300` |
| `SESSION_LIFETIME` | Durée de vie des JWT de session (secondes) | `900`                     |
| `SESSION_REFRESH_BEFORE_EXPIRE` | Marge avant expiration pour déclencher le refresh (secondes) | `120`                     |
| `SESSION_REFRESH_MIN_INTERVAL` | Intervalle minimal entre deux tentatives de refresh (secondes) | `30`                      |
| `OPENID_CLIENT_SECRET` | Secret client OIDC, selon le fournisseur | - |
| `REDIS_PORT` | Port Redis | `6379` |
| `REDIS_DATABASE` | Numéro de base Redis | `0` |
| `REDIS_PASSWORD` | Mot de passe Redis | - |
| `REDIS_TIMEOUT` | Timeout de connexion Redis (secondes) | `2.0` |
| `REDIS_READ_TIMEOUT` | Timeout de lecture Redis (secondes) | `20.0` |
| `REDIS_PREFIX` | Préfixe des clés Redis | `claire:` |
| `SEARXNG_URL` | URL SearXNG pour recherche web | -                         |
| `TELEGRAM_BOT_TOKEN` | Token du bot Telegram | -                         |
| `TELEGRAM_WEBHOOK_SECRET` | Secret webhook Telegram | -                         |
| `COMFYUI_ENABLED` | Active la génération d'images | `false`                   |
| `COMFYUI_URL` | URL de l'instance ComfyUI | `http://localhost:8188`   |
| `PDF_ENABLED` | Active la génération de PDF | `true`                    |
| `PDF_DEFAULT_FORMAT` | Format d'entrée par défaut (`html`, `markdown`) | `html`                    |
| `PDF_DEFAULT_PAGE_SIZE` | Format de page par défaut (`A4`, `Letter`, `A3`, `A5`) | `A4`                      |
| `PDF_TEMP_DIR` | Répertoire temporaire pour la génération PDF | `<app>/var/tmp`           |
| `DATABASE_KIND` | Type de base (`sqlite`, `mysql`, `postgres`) | `sqlite`                  |
| `DEBUG_MODE` | Mode debug | `false`                   |
| `QUEUE_WORKERS` | Nombre de workers de queue | `8`                       |
| `QUEUE_WORKER_TIMEOUT` | Durée d'attente maximale d'un job par le worker (secondes) | `5` |
| `QUEUE_WORKER_MAX_JOBS` | Nombre max de jobs par worker (`0` : illimité) | `256` |
| `QUEUE_WORKER_MAX_TIME` | Durée de vie max d'un worker en secondes (`0` : illimitée) | `3600` |
| `SSE_DURATION` | Durée autorisée d'une connexion SSE, indépendante du JWT d'ouverture (secondes, max 86400) | `1800` |
| `SSE_CHECK_INTERVAL` | Intervalle des contrôles génération et révocation remember (secondes) | `15` |
| `SSE_KEEPALIVE` | Intervalle des commentaires SSE (secondes) | `15` |
| `SSE_HTTP_TIMEOUT` | Timeout des appels HTTP internes (secondes) | `10` |
| `SSE_MAX_CONNECTIONS` | Nombre maximal de connexions par daemon | `1000` |
| `SSE_MAX_HTTP_REQUESTS` | Concurrence maximale des appels internes | `16` |
| `SSE_MAX_REDIS_COMMANDS` | Commandes Redis simultanées maximales | `64` |
| `SSE_MAX_PENDING_EVENTS` | Événements en attente maximaux par connexion | `256` |
| `SSE_MAX_CLIENT_BUFFER` | Budget de tampon par connexion (octets) | `16777216` |
| `SSE_MAX_GLOBAL_BUFFER` | Budget global des tampons (octets) | `134217728` |
| `SSE_WRITE_TIMEOUT` | Durée maximale de blocage d'un client lent (secondes) | `15` |
| `SSE_SHUTDOWN_TIMEOUT` | Délai maximal d'arrêt du daemon (secondes) | `5` |

Le transport SSE utilise Redis Pub/Sub sans persistance ni rejeu `Last-Event-ID`.
Chaque reconnexion obtient un nouveau jeton et un snapshot SQL ; les demandes audio
perdues sont abandonnées et peuvent être relancées manuellement. Caddy route
exclusivement `/brain/stream` vers le daemon ReactPHP supervisé. Ses listeners et
le backend Slim interne restent sur loopback, sans ports Docker publiés.
Le routage livré utilise `127.0.0.1:8081` pour le daemon et
`http://127.0.0.1:8082` pour le backend interne ; conserver ces adresses avec
le Caddyfile fourni.

Dans Docker, l'entrypoint génère un nouveau `SSE_INTERNAL_SECRET` aléatoire à
chaque démarrage du conteneur et l'exporte aux processus FrankenPHP et SSE.
Il remplace toute valeur préexistante, n'est ni journalisé ni écrit sur disque,
et reste identique lors du redémarrage individuel d'un processus par Supervisor.
Aucune configuration de ce secret dans Compose n'est nécessaire.

Pour cette migration, déployer producteurs, daemon, proxy et frontend ensemble,
redémarrer les workers persistants et invalider les caches de conteneur/routes.
Prévoir une courte coupure avec reconnexion, sans vider Redis : les anciennes
listes SSE expirent seules. Un rollback doit restaurer la version complète.
Les réglages SSE sont chargés une fois ; leur modification exige un redémarrage.

### API audio

Lorsque l'audio est configuré, Claire expose deux routes authentifiées par la
session Claire et compatibles avec les requêtes OpenAI usuelles :

- `POST /v1/audio/transcriptions` accepte un formulaire multipart avec `file`
  et `model`, et renvoie `json`, `verbose_json` ou `text` ;
- `POST /v1/audio/speech` accepte `input`, `model`, `voice` et
  `response_format`, puis renvoie directement le flux audio.

Les modèles réellement appelés restent ceux configurés côté serveur. La valeur
`voice` doit correspondre à un identifiant présent dans `MISTRAL_AUDIO_VOICES`.
Le streaming natif du fournisseur audio n'est pas activé.

Dans l'interface web et l'embed, la synthèse peut être générée automatiquement
pour chaque nouvelle réponse, ou à la demande avec le bouton du message. Dans
les deux modes, le worker livre le résultat en Base64 par l'événement SSE
`chat.audio.ready`. Pendant une génération, le bouton reste désactivé jusqu'à
la réception de cet événement, puis la lecture démarre automatiquement lorsque
la politique d'autoplay du navigateur l'autorise.

Lorsque l'audio Mistral est disponible, les agents disposent également de
l'outil `generate_speech`. Il transforme jusqu'à 4096 caractères en fichier
MP3, avec une voix et un nom de fichier optionnels. Le fichier est conservé
avec la conversation et affiché directement dans un lecteur audio protégé.

Voir [`docker/compose.yml`](docker/compose.yml) pour un exemple étendu de configuration, avec `REDIS_DATABASE`, un volume Redis persistant, la journalisation AOF et la politique `noeviction`.

L'application lit les variables d'environnement système, sans charger de fichier `.env`. Docker Compose peut toutefois utiliser son propre `.env` pour substituer les variables de son fichier YAML.

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

# Lancer un worker supplémentaire au premier plan (déjà supervisés dans l'image)
docker compose exec claire ./console queue:work
```

## Mise à niveau vers 2.1.0

1. Sauvegardez la base, les volumes persistants et les agents locaux. Suspendez les nouvelles requêtes et arrêtez les anciens workers avant de migrer.
2. Déployez la nouvelle image ou le nouveau code et appliquez `./console migrations:migrate` dans cet environnement, avant de remettre les workers en service. La migration `Version20260912130000` crée le journal SQL `telegram_generation` ; le démarrage Docker n'applique pas les migrations automatiquement.
3. Migrez les agents personnalisés de `CSS` / `css` / `css_inline` vers `THEME` / `theme`, selon le guide ci-dessous. Les anciens styles d'agents ne sont plus chargés.
4. Videz le cache compilé avec `./console cache:clear`, puis redémarrez les processus web et tous les workers. Pour une installation depuis les sources, reconstruisez également les bundles avec `npm run build`.
5. Rechargez les interfaces ouvertes. Les clients API externes doivent utiliser `/files/serve/{id}` et les jetons de ressources dédiés pour leurs liens et flux SSE, plutôt que les anciens mini-tokens ou un JWT de session dans l'URL.

Les queues restent exclusivement dans Redis : le journal SQL Telegram ne constitue pas une queue ni un outbox SQL. Conservez les données Redis pendant la mise à niveau ; ne purgez pas les jobs pour débloquer une conversation.

## Application installable (PWA)

L'interface normale peut être installée comme une application et ouverte dans une
fenêtre dédiée (`standalone`). Ouvrez directement l'URL publique de Claire en
**HTTPS**, hors navigation privée : l'installation ne concerne pas le widget
embarqué sur un site tiers. L'authentification SSO reste nécessaire pour utiliser
le chat.

### Installation

- **Chrome / Edge sur ordinateur** : utilisez l'icône d'installation dans la barre
  d'adresse, ou l'entrée d'installation du site dans le menu du navigateur.
- **Chrome sur Android** : ouvrez le menu du navigateur, puis choisissez
  **Installer l'application** ou **Ajouter à l'écran d'accueil**.
- **Safari sur iPhone / iPad** : ouvrez **Partager**, puis **Sur l'écran d'accueil**.
  Activez **Ouvrir comme app web** si cette option est proposée, puis confirmez.

Les libellés et la disponibilité des commandes dépendent du navigateur et de sa
version. Aucun service worker, cache applicatif ou mode hors ligne n'est fourni :
**une connexion Internet reste requise**, même après installation.

### Nom de l'application

La variable facultative `APP_NAME` vaut `Claire` par défaut. Avec l'exemple Docker
Compose, vous pouvez la définir dans le fichier `.env` utilisé par Compose :

```dotenv
APP_NAME="Mon assistant"
```

Elle définit le titre de la page avant et après connexion, le nom proposé à
l'installation et les métadonnées iOS. Elle ne renomme ni les agents, ni le widget,
ni le service OpenTelemetry. Le nom est échappé dans le HTML.

Après modification, recréez le service pour appliquer l'environnement
(`docker compose up -d --force-recreate claire`), videz le cache compilé
(`docker compose exec claire ./console cache:clear`), puis rechargez les processus
web persistants (`docker compose restart claire`) et la page. Un simple redémarrage
ne met pas à jour les variables d'environnement d'un conteneur existant.
Le navigateur peut conserver temporairement l'ancien nom d'une application déjà
installée.

### Manifeste et diagnostic

Le manifeste dynamique `GET /manifest.webmanifest` est public avant authentification
et renvoyé avec le type `application/manifest+json`. Il déclare les icônes PNG
192x192 et 512x512, la langue française et le mode `standalone`. Les chemins
`start_url` et `scope` valent `./` ; `id` est omis pour que l'identité dérive de
`start_url` et reste propre au chemin de montage, à la racine ou sous un sous-chemin.

Si l'installation n'est pas proposée, vérifiez que la page se charge sans erreur
en HTTPS et que le manifeste ainsi que ses icônes sont accessibles. Sous un chemin
de montage tel que `/chat`, vérifiez `/chat/manifest.webmanifest`. Le manifeste doit
renvoyer du JSON, pas une page SSO ni une erreur. Rechargez la page après toute
mise à jour du déploiement.

## Cerveaux personnalisés (BrainRegistry)

Créez vos propres agents sans coder en ajoutant des fichiers `.yaml` dans le répertoire `llm.yamlBrains.path` : `/opt/addons/agents/` dans le conteneur, ou `<ADDONS_PATH>/agents/` (par défaut `var/addons/agents/`). Le nom du fichier sans `.yaml` devient le slug de sélection.

```yaml
name: "Coach Personnel"
description: "Un coach motivant pour vous aider à atteindre vos objectifs"
avatar: "data:image/png;base64,..."
theme: energy
welcomes:
  - "Prêt à relever de nouveaux défis ?"
  - "Bonjour champion !"
instruction: |
  Tu es un coach personnel motivant et bienveillant...
```

### Thème d'un agent

Le raccourci scalaire `theme: energy` sélectionne un preset du catalogue. La forme objet permet de surcharger certaines valeurs sans écrire de feuille CSS. Exemple à utiliser à la place du champ `theme` ci-dessus :

```yaml
theme:
  preset: light
  tokens:
    --claire-accent: "#005c9f"
    --claire-focus-ring: "#005c9f"
    --claire-bubble-radius: "14px"
  variants:
    controls: solid
    effects: none
```

`preset`, `tokens` et `variants` sont optionnels dans cette forme. `tokens: {}` et `variants: {}` signifient « aucune surcharge », pas « effacer le preset ». Les clés sont sensibles à la casse. Les valeurs des tokens doivent être des chaînes YAML : mettez notamment les couleurs hexadécimales et les nombres entre guillemets.

Pour un agent PHP implémentant `BrainAvatar`, la constante héritée `THEME` vaut `cyberpunk`. Claire conserve ce défaut ; Einstein déclare :

```php
public const string THEME = 'neon';
```

Les six presets sont versionnés dans [`config/themes/`](config/themes/). Les deux agents PHP sont livrés par défaut ; les quatre agents YAML ci-dessous sont des affectations **locales**, disponibles seulement si leurs fichiers sont déployés :

| Slug de sélection | Agent | Origine | Preset | Identité |
|-------------------|-------|---------|--------|----------|
| `claire` | Claire | PHP | `cyberpunk` | Futuriste violet/rose, fond dégradé |
| `einstein` | Einstein | PHP | `neon` | Électrique cyan/bleu, bulles envoyées sans rose, fond dégradé |
| `coach` | Coach Personnel | YAML local | `energy` | Orange dynamique sur anthracite/ardoise, fond dégradé sans marron |
| `calliope` | Calliope | YAML local | `light` | Éditorial lumineux, fond uni |
| `claire-gf` | Claire GF | YAML local | `romantic` | Érotique et feutré, rouge passion/bordeaux, finition satinée, fond dégradé |
| `thanos` | Thanos | YAML local | `dark` | Presque noir, ardoise sombre et accents bleus, fond uni |

Le slug local actuel est `thanos` (`thanos.yaml`), sans alias `dark-test`. Remplacez une ancienne sélection `dark-test` par `thanos`.

### Résolution et déploiement

`App\Services\ThemeRegistry` lit le chemin serveur `themes.path`, défini dans [`config/settings/themes.php`](config/settings/themes.php) et valant par défaut `<app>/config/themes`. Ce réglage désigne un **répertoire local de confiance**, contenant `contract.json` et les presets `*.yaml`, jamais une URL ni un chemin fourni par l'agent. Il n'existe pas de variable d'environnement `THEMES_PATH` intégrée.

Un fichier de catalogue, par exemple `light.yaml`, contient directement les deux maps `tokens` et `variants`, sans enveloppe `theme` ni champ `preset`. Son nom sans extension est sa référence ; il doit respecter `[a-z][a-z0-9-]*`. Les deux maps sont requises dans un preset, mais peuvent être vides (`{}`). Un YAML illisible, une map manquante ou une liste non vide à la place d'une map fait ignorer ce fichier. En revanche, des entrées inconnues ou invalides dans ces maps sont simplement filtrées, sans rejeter le preset entier.

- Sans `theme`, ou avec une référence absente, invalide ou inconnue, le preset sélectionné devient `cyberpunk`. Une référence est un slug exact, pas `light.yaml`, un chemin ou une URL.
- Les surcharges valides remplacent les valeurs du preset sélectionné clé par clé, **même si une référence inconnue a déclenché le fallback**. Les tokens inconnus ou non textuels et les variantes non autorisées sont ignorés ; les autres valeurs du preset restent intactes.
- Si le répertoire ou le contrat manque ou si la structure du contrat est invalide, la résolution produit `cyberpunk` avec des maps vides. Si seul le preset `cyberpunk` est indisponible, sa base est vide mais les surcharges autorisées par un contrat valide restent applicables. Les tokens communs du frontend fournissent les valeurs de secours ; les presets ne sont pas fusionnés implicitement avec le fichier `cyberpunk.yaml`.
- Le contrat filtre les **noms** et les types, pas la syntaxe ni la sécurité des valeurs CSS. Utilisez uniquement des fichiers administrés de confiance et des valeurs CSS adaptées à chaque propriété, jamais des sélecteurs, blocs `:root`, règles `@import` ou feuilles de style. Par exemple, `--claire-body-background` accepte une couleur ou `linear-gradient(...)`, car il alimente `background`.

`FrontendConfigFactory` expose toujours `theme` sous la forme `{ preset: string, tokens: Record<string, string>, variants: Record<string, string> }` dans `brainInfo` et `brains`. Les maps JSON sont des **objets**, même vides. Ainsi, en l'absence de catalogue utilisable :

```json
{"preset":"cyberpunk","tokens":{},"variants":{}}
```

Vue applique les propriétés et les attributs `data-theme-controls` / `data-theme-effects` sur `.claire-app`, en mode normal comme dans le Shadow DOM du widget. Un changement d'agent remplace immédiatement le thème et retire les anciennes surcharges ; aucune feuille CSS d'agent n'est téléchargée et le thème ne modifie pas la page hôte de l'embed.

**Rupture volontaire :** `BrainAvatar::CSS` est remplacé par `THEME`. Les anciens champs YAML `css` et `css_inline` sont ignorés, sans compatibilité transitoire ; `cssInline` et `dynamicCss` ne font plus partie du contrat frontend. Migrez les agents externes vers `theme` au lieu de conserver leurs anciennes feuilles ou blocs CSS.

Les agents sous `local_data/addons/agents/` sont ignorés par Git : déployez et sauvegardez leurs fichiers séparément, avec les permissions appropriées, sans publier leurs instructions privées. Le catalogue central, lui, est versionné. `ThemeRegistry` et `BrainRegistry` conservent respectivement le catalogue et les agents YAML en mémoire par instance. Après modification, redémarrez les processus de longue durée qui les utilisent (workers de queue et workers web le cas échéant). Lors de la mise à niveau du code/DI ou d'un changement de configuration, videz aussi le cache compilé avec `./console cache:clear` avant de relancer les processus. Hors mode debug, le bootstrap web reconstruit le conteneur DI compilé ; `./console cache:init` génère les proxies Doctrine, ce n'est pas une commande de rechargement des thèmes. Vider le cache disque ne recharge pas les instances déjà actives ; rechargez également les interfaces ouvertes pour récupérer le nouveau bootstrap.

### API interne des thèmes

La source officielle de la liste autorisée est [`config/themes/contract.json`](config/themes/contract.json), partagée par le registre et les contrôles frontend. Ce fichier de dépôt n'est pas un endpoint HTTP. Les autres variables CSS du socle, notamment celles de dimensionnement de l'embed, ne sont pas automatiquement des tokens publics d'agent.

Les **71 tokens publics** sont regroupés ci-dessous par rôle. Les valeurs de référence sont dans les presets et `frontend/styles/`, plutôt que dupliquées ici :

- **Typographie et schéma de couleurs (4)** : `--claire-font`, `--claire-font-heading`, `--claire-font-mono`, `--claire-color-scheme`.
- **Fonds et surfaces (8)** : `--claire-body-background`, `--claire-surface-chat`, `--claire-header-bg`, `--claire-input-bar-bg`, `--claire-surface-muted`, `--claire-surface-hover`, `--claire-surface-active`, `--claire-surface-code`.
- **Texte et accents (4)** : `--claire-text-primary`, `--claire-text-secondary`, `--claire-accent`, `--claire-accent-light`.
- **Bordures et focus (4)** : `--claire-border`, `--claire-border-strong`, `--claire-focus-ring`, `--claire-placeholder`.
- **Rayons (5)** : `--claire-radius`, `--claire-radius-sm`, `--claire-radius-md`, `--claire-radius-lg`, `--claire-bubble-radius`.
- **Bulles et métadonnées (8)** : `--claire-bubble-sent-background`, `--claire-bubble-sent-text`, `--claire-bubble-sent-meta`, `--claire-bubble-received-background`, `--claire-bubble-received-text`, `--claire-bubble-received-meta`, `--claire-bubble-border`, `--claire-bubble-shadow`.
- **Contrôles (8)** : `--claire-control-background`, `--claire-control-text`, `--claire-control-hover`, `--claire-control-primary-background`, `--claire-control-primary-text`, `--claire-control-send-background`, `--claire-control-send-text`, `--claire-control-shadow`.
- **Champs (4)** : `--claire-field-background`, `--claire-field-focus-background`, `--claire-field-border`, `--claire-field-focus-shadow`.
- **Panneaux, dialogues et infobulles (4)** : `--claire-panel-background`, `--claire-dialog-background`, `--claire-dialog-shadow`, `--claire-tooltip-bg`.
- **Overlays et élévations (5)** : `--claire-overlay-dialog`, `--claire-overlay-drawer`, `--claire-shadow`, `--claire-elevation-low`, `--claire-elevation-raised`.
- **Avatar et effets (4)** : `--claire-avatar-border`, `--claire-avatar-shadow`, `--claire-effect-glow`, `--claire-effect-satin`.
- **Danger (5)** : `--claire-danger`, `--claire-danger-text`, `--claire-danger-background`, `--claire-danger-border`, `--claire-danger-on`.
- **Succès (4)** : `--claire-success`, `--claire-success-text`, `--claire-success-background`, `--claire-success-border`.
- **Scrollbars et lien Telegram (4)** : `--claire-scrollbar-track`, `--claire-scrollbar-thumb`, `--claire-scrollbar-hover`, `--claire-telegram-link`.

Les **variantes publiques** sont `controls: solid | outline | soft` (contrôles pleins, contour ou adoucis) et `effects: none | glow | satin` (sans effet, lueur ou finition satinée). Toute autre clé ou valeur est ignorée. `solid` et `none` réutilisent les règles du socle ; les autres finitions sont définies dans `frontend/styles/variants.css`, sans sélecteurs propres à chaque preset. La coloration du code utilise également les tokens sémantiques du thème, sans feuille Highlight distincte imposant une palette claire.

## Mémoire long terme

Depuis la version 1.6.0, Claire peut conserver une synthèse durable des informations utiles d'un utilisateur entre ses conversations. Cette fonctionnalité est désactivée par défaut et s'active dans les préférences de l'interface web, du widget ou de la Mini-App Telegram.

Lorsque la mémoire est active, elle évolue périodiquement à partir des échanges et est ajoutée au contexte des nouvelles requêtes. L'utilisateur peut également la reconstruire à partir des résumés de ses conversations existantes. Les données sont isolées par utilisateur dans la table `long_term_memory` et supprimées en cascade avec le compte.

Après une mise à niveau vers la version 1.6.0, appliquez la migration correspondante :

```bash
docker compose exec claire ./console migrations:migrate
```

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

Activée par défaut (`PDF_ENABLED=true`), la génération de PDF permet aux agents de produire des documents depuis du HTML ou du Markdown via l'outil `generate_pdf` :

- Formats supportés : HTML, Markdown
- Formats de page : A4, Letter, A3, A5
- Orientations : portrait, paysage
- Marges configurables
- Styles de document intégrés pour la typographie, les tableaux et la pagination

Les fichiers générés sont liés à la conversation et accessibles dans l'historique de chat via des liens protégés. Le répertoire `PDF_TEMP_DIR` doit être accessible en écriture aux workers.

## RAG (Recherche augmentée)

Claire indexe des documents par utilisateur pour enrichir les réponses de l'agent (retrieval-augmented generation). Depuis l'interface web, l'utilisateur peut ajouter un document (fichier, texte collé ou URL), l'activer/désactiver, le supprimer ou consulter ses segments.

Lorsqu'au moins un document actif existe, l'outil `rag_search` est automatiquement mis à la disposition de l'agent pour interroger ces documents. Les embeddings sont calculés via le modèle `OPENAPI_MODEL_EMBED` (requis), et le découpage en segments ainsi que le nombre de résultats sont réglables via `RAG_CHUNK_SIZE` et `RAG_TOP_K`.

Les documents sont stockés par utilisateur dans le volume `/opt/data` (répertoire `rag/`) et référencés dans la table `rag_document`. Après une mise à niveau vers la version 2.0.0, appliquez la migration correspondante :

```bash
docker compose exec claire ./console migrations:migrate
```

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

Depuis l'interface web, chaque utilisateur peut associer son identifiant Telegram (User ID) à son compte pour recevoir les notifications. L'identifiant est validé (numérique uniquement) et doit être unique ; il peut être dissocié à tout moment en effaçant le champ.

Les messages vocaux et fichiers audio entrants sont transcrits lorsque l'audio Mistral est configuré. Le bot peut répondre en audio et envoyer les fichiers produits par `generate_speech` ; la voix se choisit dans les préférences de la Mini-App.

### Journaux et maintenance

Les générations Telegram et leurs étapes de livraison sont journalisées dans la table SQL `telegram_generation` pour éviter de rejouer des outils ou des envois déjà tentés. La commande `chat:maintenance` permet le diagnostic et la compaction, **en simulation par défaut** :

```bash
# Examiner une conversation bloquée, sans rejouer la génération
docker compose exec claire ./console chat:maintenance --user USER --thread THREAD

# Simuler la compaction des corps des journaux livrés depuis plus de 7 jours
docker compose exec claire ./console chat:maintenance --compact --retention-days 7

# Appliquer la compaction, depuis le début du parcours
docker compose exec claire ./console chat:maintenance --compact --retention-days 7 --apply --cursor 0
```

Poursuivez chaque parcours avec le curseur `sql:v1:` renvoyé, via `--cursor`, jusqu'à `0`. Après une simulation, recommencez le parcours d'application à `0`. La rétention de sept jours est une valeur par défaut de la commande, pas une tâche planifiée automatiquement.

Seuls les corps des journaux entièrement confirmés et livrés sont effacés ; les identifiants et marqueurs anti-rejeu restent en SQL sans expiration. Les journaux actifs, en échec ou ambigus sont conservés. Pour une génération orpheline, `--user USER --thread THREAD --reconcile --apply` ne marque une erreur que si l'absence de travail restant est démontrée, sans relancer le LLM ni les outils. Cette preuve suppose que les données de queue n'ont pas été supprimées ou évincées de Redis.

## Queue Redis

Redis et au moins un worker sont nécessaires au chat web, au widget, à l'audio asynchrone et à Telegram, y compris sur une seule instance. L'image Docker démarre déjà les workers sous Supervisor (`QUEUE_WORKERS=8` par défaut). Hors Docker, lancez et supervisez-les séparément ; les extensions PHP `pcntl` et `posix` sont requises pour le renouvellement des baux.

```bash
# Lancer le worker
docker compose exec claire ./console queue:work

# Options
--max-jobs=1  # S'arrêter après un job traité
--timeout=5   # Attente maximale d'un job (secondes)
--max-jobs=100
--max-time=3600
```

Les jobs sont réservés avec un bail renouvelable et peuvent être retentés avec un délai progressif. Les échecs non récupérables sont conservés en dead-letter ; une génération ayant déjà tenté des appels d'outils n'est pas rejouée aveuglément. Utilisez le diagnostic `chat:maintenance` avant toute intervention sur une conversation bloquée.

En production, configurez un volume Redis persistant, une politique de persistance adaptée et `maxmemory-policy noeviction`. Redis contient les jobs et des états de génération, pas seulement un cache jetable ; une perte de ces données compromet la reprise sûre des traitements.

## Mode embarqué (Widget)

Claire expose un mode widget prêt à intégrer dans une page externe.

- Endpoint de bootstrap JSON : `GET /embed`
- Bootstrap JS : `public/js/embed.js` (IIFE autonome, pas de dépendance externe)
- Échange SSO -> session Claire : `POST /auth/embed/exchange`
- Fonction globale d'initialisation : `window.claireEmbed({ baseUrl, target, token|ssoToken })`
- Fonction de teardown : `window.destroyClaireEmbed()`

Exemple minimal :

```html
<div id="claire-root"></div>
<script src="https://claire.example.com/js/embed.js"></script>
<script>
  window.claireEmbed({
    baseUrl: 'https://claire.example.com',
    target: '#claire-root',
    ssoToken: '<TOKEN_SSO>'
  });
</script>
```

Une page de validation locale est fournie dans `public/embed.html`.

Le widget est distribué comme un **Custom Element Vue** (`<claire-chat-widget>`) avec **Shadow DOM**. Il reste isolé de la page hôte : il ne modifie pas `window.fetch`, n'écrit pas de configuration sur `document.body` et ne sort jamais de son conteneur racine. La commande `npm run build` génère l’application normale dans `public/build/` et reconstruit le script compatible `<script>` dans `public/js/embed.js`.

## API

### Authentification session (JWT)

- Le frontend envoie le JWT de session via l'en-tête `X-Claire-Auth`.
- Le backend peut renvoyer un JWT rafraîchi via `X-Claire-Token`.
- Endpoint de refresh silencieux: `GET /auth/refresh` (retour `204` avec en-têtes de session si renouvellement).
- Endpoint d'échange SSO pour le widget: `POST /auth/embed/exchange`.
- `POST /auth/resource-token`, authentifié par le JWT de session, délivre un jeton limité aux fichiers ou au flux SSE demandés.
- Les URL de ressources acceptent ce jeton dédié dans `token`. Les JWT de session dans l'URL et les anciens mini-tokens ne sont plus acceptés pour les authentifier.

Exemple de corps JSON pour obtenir un jeton partagé entre un fichier et un flux :

```json
{"resources":[{"type":"file","fileId":"FILE_ID"},{"type":"stream","threadId":"THREAD_ID","sessionId":"SESSION_ID"}]}
```

Une portée unique peut aussi être envoyée directement, par exemple `{"type":"file","fileId":"FILE_ID"}`. La réponse contient `{token, expiresAt}`. Le jeton expire au plus tard après 300 secondes ; un lot accepte au maximum 32 portées et 4000 octets sérialisés. Le client doit renouveler les jetons expirés et reconnecter les flux avec la portée exacte, sans transmettre ces jetons à des URL tierces.

### Healthcheck

`GET /health` — Retourne la version et la date.

### Envoi de message

```http
POST /brain/messages HTTP/1.1
X-Claire-Auth: <JWT_SESSION>
Content-Type: multipart/form-data; boundary=----BOUND

------BOUND
Content-Disposition: form-data; name="message"

Bonjour Claire !
------BOUND
Content-Disposition: form-data; name="sessionId"

sess-abc123
------BOUND
Content-Disposition: form-data; name="threadId"

<THREAD_ID>
------BOUND--
```

Utilisez le `threadId` d'une conversation créée par `POST /history/new` ou ouverte depuis l'historique. La réponse `202` contient `{threadId, messageId, accepted: true}` : le traitement est asynchrone. Une conversation occupée ou supprimée peut produire un conflit `409`.

La création via `POST /history/new` est elle aussi asynchrone et renvoie `{threadId, sessionId}`. Réutilisez ce `sessionId`, obtenez le jeton du flux et attendez la fin de l'initialisation (`chat.snapshot` avec `responding: false`) avant d'envoyer un premier message.

Le résultat arrive sur `GET /brain/stream?threadId=THREAD_ID&sessionId=SESSION_ID&token=RESOURCE_TOKEN`, avec un jeton autorisant ce couple conversation/session. Le `sessionId` identifie le canal SSE du client, pas son JWT d'authentification.

### Gestion des fichiers

- `GET /files/count`, `GET /files/list`
- `POST /files/upload`, `POST /files/upload_rag`
- `DELETE /files/delete/{id}`
- `GET /files/serve/{id}` (images, audio, PDF et autres fichiers ; l'ancienne route `/files/img_serve/{id}` est supprimée)

### RAG

- `GET /rag/list`, `GET /rag/count`
- `GET /rag/segments/{id}`
- `POST /rag/upload` (fichier), `POST /rag/text`, `POST /rag/url`
- `POST /rag/toggle/{id}` (activer/désactiver), `DELETE /rag/delete/{id}`

### Audio

- `POST /v1/audio/transcriptions` (transcription audio compatible OpenAI)
- `POST /v1/audio/speech` (synthèse vocale compatible OpenAI)
- `POST /brain/audio` (génération de synthèse vocale pour un message)
- `POST /config/audio` (mise à jour des préférences audio utilisateur)

### Historique

- `GET /history/count`, `GET /history/list`
- `GET /history/open/{threadId}`, `POST /history/new`
- `DELETE /history/exchange/last`, `DELETE /history/delete/{threadId}`

## Démarrage rapide (Docker)

```bash
# Réseau et Redis persistants pour cet exemple local
docker network create claire-net
docker run -d --name claire-redis --network claire-net \
  -v claire_redis:/data redis:7-alpine \
  redis-server --appendonly yes --maxmemory-policy noeviction

# Lancer Claire avec Docker
docker run -d \
  --name claire \
  --network claire-net \
  -p 8080:80 \
  -v claire_data:/opt/data \
  -e BASE_URL=http://localhost:8080 \
  -e REDIS_HOST=claire-redis \
  -e OPENID_WELLKNOWN_URL=https://votre-sso.example.com/.well-known/openid-configuration \
  -e OPENID_CLIENT_ID=votre-client-id \
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

Adaptez le domaine et le fournisseur OIDC (URI de retour : `BASE_URL/auth/callback`). Ajoutez `OPENID_CLIENT_SECRET` si votre fournisseur l'exige. L'exemple HTTP local nécessite un fournisseur acceptant une URI de retour localhost ; utilisez HTTPS pour la production et l'installation PWA.

```yaml
services:
  claire:
    image: semhoun/claire-chatbot:latest
    container_name: claire
    restart: unless-stopped
    depends_on:
      - redis
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - claire-data:/opt/data
      - claire-addons:/opt/addons
    environment:
      # === Configuration serveur ===
      BASE_URL: https://claire.example.com
      APP_NAME: ${APP_NAME:-Claire}
      SERVER_NAME: claire.example.com
      ENABLE_LETSENCRYPT: "true"
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

      # === Queue et états de génération ===
      REDIS_HOST: redis
      REDIS_DATABASE: 0

      # === Observabilité ===
      OTEL_PHP_AUTOLOAD_ENABLED: "true"
      OTEL_SERVICE_NAME: claire
      OTEL_LOGS_EXPORTER: console
      OTEL_LOGS_PROCESSOR: simple

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: ["redis-server", "--appendonly", "yes", "--maxmemory-policy", "noeviction"]
    volumes:
      - claire-redis:/data

volumes:
  claire-data:
  claire-addons:
  claire-redis:
```

Image Docker : [semhoun/claire-chatbot](https://hub.docker.com/r/semhoun/claire-chatbot)


## Développement local (optionnel)

Prérequis : PHP 8.5+, Composer, Node.js/npm, Redis et une base de données configurée (SQLite par défaut). Les extensions `pcntl` et `posix` sont nécessaires aux workers. Pour contribuer ou modifier le code :

```bash
# Cloner et installer
git clone https://github.com/semhoun/claire-chatbot.git
cd claire-chatbot
composer install
npm install

# Exporter les variables
export BASE_URL=http://localhost:8080
export REDIS_HOST=127.0.0.1
export OPENID_WELLKNOWN_URL=https://votre-sso.example.com/.well-known/openid-configuration
export OPENID_CLIENT_ID=votre-client-id
export OPENAPI_KEY=votre-clé-api
export OPENAPI_URL=https://api.openai.com/v1
export OPENAPI_MODEL=gpt-4o-mini
export SESSION_JWT_SECRET=$(openssl rand -hex 32)
# Hors Docker uniquement : partager ce secret entre le daemon et le backend Slim.
export SSE_INTERNAL_SECRET=$(openssl rand -hex 32)

# Initialiser et lancer
./console migrations:migrate
npm run build            # Compiler les bundles Vue (normal + embed)
composer start           # HTTP seul ; pour HTTP + SSE, utiliser Caddy/Supervisor
```

Hors Supervisor, `./console sse:serve` lance le daemon et `./console queue:work` lance un
worker avec le même environnement. Le daemon nécessite aussi le listener Slim
interne et le reverse proxy Caddy ; un serveur PHP seul ne sert plus le SSE.
La commande SSE utilise la console Symfony du projet avec un bootstrap isolé :
ReactPHP conserve la boucle réseau non bloquante, tandis que Slim exécute
l'autorisation et les snapshots sur le listener HTTP interne. Le conteneur
métier, Doctrine et le client Redis synchrone ne sont pas chargés par cette commande.
Configurez le fournisseur OIDC pour autoriser `http://localhost:8080/auth/callback`
et exportez aussi `OPENID_CLIENT_SECRET` si nécessaire.

### Développement frontend

```bash
npm run dev              # Serveur Vite avec rechargement à chaud
npm run build            # Type-check + build normal + build embed
npm test                 # Tests Vitest
```

Les sources du frontend se trouvent dans `frontend/` :

- `main.ts` : point d'entrée de l'application web complète
- `embed.ts` : point d'entrée du widget embarqué (Custom Element)
- `components/` : composants Vue partagés (`ClaireApp.vue`, `ClaireIcon.vue`)
- `services/session-client.ts` : gestion du JWT côté navigateur

### Qualité du code

```bash
composer rector:check      # Vérifier
composer rector:fix        # Appliquer
composer insights:check    # Analyser
composer insights:fix      # Corriger
vendor/bin/phpunit         # Tests PHP
npm test                   # Tests frontend
composer pre-commit        # Tous les checks
```

## Dépannage

| Problème | Solution |
|----------|----------|
| 500 au `GET /` | Vérifiez les permissions du dossier `var/` |
| Pas de logs | Définissez `OTEL_LOGS_EXPORTER=console` |
| RAG inactif | Vérifiez `OPENAPI_MODEL_EMBED` |
| ComfyUI non dispo | Vérifiez `COMFYUI_ENABLED=true` et les workflows |
| Worker bloqué | Vérifiez Redis, les workers supervisés et `REDIS_READ_TIMEOUT` ; diagnostiquez la conversation avec `chat:maintenance` sans purger Redis |
| Fichier ou flux SSE refusé | Renouvelez le jeton via `POST /auth/resource-token` et vérifiez sa portée ; utilisez `/files/serve/{id}` |
| Erreur SQL Telegram après mise à niveau | Appliquez les migrations, puis redémarrez les workers |

## Licence

MIT — Voir le fichier `LICENSE`.
