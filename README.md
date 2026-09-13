# Claire — Agent de Chat IA (PHP, Slim 4)

![PHP Version](https://img.shields.io/badge/PHP-8.5%2B-777bb4?logo=php&logoColor=white) ![Slim](https://img.shields.io/badge/Slim-4.x-4B4B4B) ![FrankenPHP](https://img.shields.io/badge/FrankenPHP-Caddy-ffb300) ![License](https://img.shields.io/badge/License-MIT-blue) [![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/semhoun/claire-chatbot)

> **Claire** — Chatbot IA Vue/TypeScript multi-brain avec Telegram, ComfyUI, PDF et OpenTelemetry

Claire est une application de chat IA construite avec Slim 4, Vue 3, TypeScript et Neuron AI. Elle s'exécute dans un conteneur Docker basé sur FrankenPHP/Caddy et fournit une interface web, une API REST, une intégration Telegram et une observabilité complète via OpenTelemetry.


## Fonctionnalités

- Interface web de chat avec streaming SSE, horodatage, suppression du dernier message
- Mode widget embarqué (`/embed`) injecté via `window.claireEmbed(...)` pour intégration sur site tiers
- API REST `POST /brain/messages` et healthcheck `GET /health`
- Multi-brain : sélection dynamique d'agents IA (Claire, Einstein, Calliope...)
- Création d'agents personnalisés via fichiers YAML dans `/opt/addons/agents/`
- Mémoire courte avec résumé automatique de l'historique
- Mémoire long terme optionnelle, évolutive entre les conversations et reconstructible depuis les résumés
- Recherche web via SearXNG et RAG par documents (fichiers, texte, URL) avec embeddings
- Transcription audio (dictée vocale) et synthèse vocale (TTS) avec l'API audio Mistral
- Génération d'images avec ComfyUI (workflows multiples)
- Génération de documents PDF depuis HTML ou Markdown
- Intégration Telegram complète (messages, photos, documents, Mini-App)
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

| Variable | Description | Défaut                    |
|----------|-------------|---------------------------|
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
| `QUEUE_WORKER_TIMEOUT` | Timeout BRPOP du worker (secondes) | `5`                       |
| `QUEUE_WORKER_MAX_JOBS` | Nombre max de jobs par worker | `0` (illimité)            |
| `QUEUE_WORKER_MAX_TIME` | Durée de vie max d'un worker (secondes) | `0` (illimité)            |
| `SSE_QUEUE_TTL` | Durée de vie des messages SSE en file d'attente (secondes) | `60`                      |
| `SSE_POP_TIMEOUT` | Timeout de lecture bloquante SSE (secondes) | `15`                      |

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

Activez par défaut (`PDF_ENABLED=true`). Les agents peuvent générer des documents PDF depuis du HTML ou du Markdown via l'outil `generate_pdf` :

- Formats supportés : HTML, Markdown
- Formats de page : A4, Letter, A3, A5
- Orientations : portrait, paysage
- Marges configurables

Les fichiers générés sont liés à la conversation et accessibles dans l'historique de chat.

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
- Le backend peut renvoyer un JWT rafraîchi via `X-Claire-Token` et un mini-token via `X-Claire-Minitoken`.
- Endpoint de refresh silencieux: `GET /auth/refresh` (retour `204` avec en-têtes de session si renouvellement).
- Endpoint d'échange SSO pour le widget: `POST /auth/embed/exchange`.
- Les ressources protégées (fichiers servis) acceptent un paramètre de query `token` pour les liens/images signés côté client.

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
- `GET /files/img_serve/{id}` ou `/files/serve/{id}` (images, audio et PDF générés)

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
npm install

# Exporter les variables
export OPENAPI_KEY=votre-clé-api
export OPENAPI_URL=https://api.openai.com/v1
export OPENAPI_MODEL=gpt-4o-mini
export SESSION_JWT_SECRET=$(openssl rand -hex 32)

# Initialiser et lancer
./console migrations:migrate
npm run build            # Compiler les bundles Vue (normal + embed)
composer start
```

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
| Worker bloqué | Vérifiez Redis et `REDIS_READ_TIMEOUT` |

## Licence

MIT — Voir le fichier `LICENSE`.
