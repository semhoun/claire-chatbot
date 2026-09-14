<h1 align="center"><code>&lt; CLAIRE /&gt;</code></h1>

<p align="center">
  <strong>Un assistant IA auto-hébergé, accessible sur le web, dans vos sites et sur Telegram.</strong>
</p>

<p align="center">
  <img src="claire-readme.png" alt="Portrait de Claire" />
</p>

![PHP](https://img.shields.io/badge/PHP-8.5%2B-777BB4?logo=php&logoColor=white) ![Vue](https://img.shields.io/badge/Vue-3-42B883?logo=vuedotjs&logoColor=white) ![Slim](https://img.shields.io/badge/Slim-4.x-4B4B4B) ![FrankenPHP](https://img.shields.io/badge/FrankenPHP-Caddy-ffb300) ![License](https://img.shields.io/badge/License-MIT-blue) [![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/semhoun/claire-chatbot)

Claire réunit une interface de conversation, des agents personnalisables et des outils de recherche et de génération. Connectez votre fournisseur de modèles compatible OpenAI, configurez votre authentification OpenID Connect, puis utilisez la même application en plein écran, en widget embarqué ou avec un bot Telegram.

Le projet utilise PHP 8.5, Slim 4 et Neuron AI côté serveur, Vue 3 et TypeScript côté navigateur. L'image Docker regroupe FrankenPHP/Caddy, un daemon SSE et les workers de traitement. Une base SQL conserve les données applicatives ; Redis assure la queue et la coordination des traitements.

[Code source](https://github.com/semhoun/claire-chatbot) · [Image Docker](https://hub.docker.com/r/semhoun/claire-chatbot) · [Historique des versions](CHANGELOG.md)

## Sommaire

- [Fonctionnalités](#fonctionnalités)
- [Installation Docker](#installation-docker)
- [Configuration](#configuration)
- [Utilisation](#utilisation)
- [Cerveaux personnalisés (BrainRegistry)](#cerveaux-personnalisés-brainregistry)
- [Widget embarqué](#widget-embarqué)
- [Telegram](#telegram)
- [API et authentification](#api-et-authentification)
- [Architecture](#architecture)
- [Exploitation et mises à jour](#exploitation-et-mises-à-jour)
- [Développement](#développement)
- [Dépannage](#dépannage)

## Fonctionnalités

| Domaine | Ce que propose Claire |
| --- | --- |
| Conversations | Réponses en streaming SSE, rendu Markdown et code, historique, reprise de conversation et suppression du dernier échange. |
| Agents | Claire et Einstein intégrés ; ajout d'agents YAML sans modifier le code. |
| Apparence | Six thèmes, personnalisation par agent et composants partagés entre interface normale et widget. |
| Documents | Pièces jointes et recherche augmentée par documents personnels : fichiers, texte collé et URL. |
| Mémoire | Résumé automatique du contexte court et mémoire durable facultative entre les conversations. |
| Recherche web | Outil de recherche via une instance SearXNG. |
| Audio | Dictée, synthèse vocale, choix de voix et production de fichiers audio via Mistral. |
| Création | Génération d'images avec ComfyUI et de PDF depuis HTML ou Markdown. |
| Intégrations | Widget isolé en Shadow DOM, bot Telegram et Mini-App de configuration. |
| Installation mobile | Application web installable sur ordinateur et mobile, avec connexion Internet requise. |
| Exploitation | Traitements asynchrones Redis, journal de livraison Telegram, traces, métriques et logs OpenTelemetry. |

**À prévoir avant de démarrer :** un fournisseur LLM, un fournisseur OIDC, une base SQL et Redis. Les fonctions audio, recherche web, RAG, images et Telegram dépendent de services ou de réglages supplémentaires. L'auto-hébergement de Claire ne signifie pas que le modèle s'exécute localement : les échanges sont transmis au fournisseur configuré.

## Installation Docker

### 1. Préparer les services

Le chemin recommandé est le modèle de distribution [`docker/compose.yml`](docker/compose.yml). Il fournit Claire, Redis persistant et une interface OpenTelemetry, avec SQLite pour le stockage SQL.

Prérequis :

- Docker Engine et le plugin Docker Compose.
- Un domaine pointant vers le serveur ; les ports 80 et 443 doivent être accessibles pour le HTTPS automatique de l'exemple.
- Un endpoint LLM compatible OpenAI, un modèle et, si le fournisseur l'exige, une clé API.
- Un client OpenID Connect autorisant l'URI de retour `https://claire.example.com/auth/callback`, adaptée à votre domaine.
- Un secret JWT aléatoire d'au moins 32 caractères, conservé durablement.

```bash
git clone https://github.com/semhoun/claire-chatbot.git
cd claire-chatbot
openssl rand -hex 32
```

Enregistrez le secret généré dans votre gestionnaire de secrets ou dans l'environnement de déploiement sous `SESSION_JWT_SECRET`. Ne le régénérez pas à chaque lancement de Compose.

### 2. Adapter le modèle Compose

Modifiez [`docker/compose.yml`](docker/compose.yml) avant de démarrer :

| Réglage | Action |
| --- | --- |
| `BASE_URL`, `SERVER_NAME`, `ACME_EMAIL` | Remplacer le domaine et l'adresse de contact des certificats. |
| `OPENAPI_URL`, `OPENAPI_MODEL`, `OPENAPI_KEY` | Configurer votre fournisseur de modèles. Adapter aussi les modèles de résumé et d'embeddings. |
| `OPENID_WELLKNOWN_URL`, `OPENID_CLIENT_ID` | Configurer votre fournisseur OIDC et ajouter `OPENID_CLIENT_SECRET` si nécessaire. |
| `SESSION_JWT_SECRET` | Fournir la variable attendue par Compose. |
| `MISTRAL_AUDIO_ENABLED` | Mettre à `"false"` si vous ne configurez pas l'audio ; le modèle Compose l'active explicitement. |
| `ALLOWED_CORS_ORIGINS` | Ajouter les origines autorisées, notamment celles des sites utilisant le widget. |
| Port `4318` du service `otel-gui` | Restreindre son exposition au réseau d'administration, ou supprimer sa publication publique. |

Le fichier `compose.yml` **à la racine** est propre à un environnement de développement avec réseaux externes et reverse proxy. Ce n'est pas le modèle d'installation générique. Les commandes Docker de ce guide sélectionnent donc explicitement `docker/compose.yml`.

L'application lit les variables de ses processus, **pas un fichier `.env` PHP**. Compose peut utiliser un `.env` pour ses substitutions, mais seules les variables déclarées dans `environment` ou transmises explicitement arrivent dans le conteneur. Pour un emplacement sans ambiguïté, utilisez l'option Compose `--env-file /chemin/vers/claire.env` avec vos commandes.

### 3. Démarrer et initialiser

Depuis la racine du dépôt, avec la configuration et le secret disponibles :

```bash
docker compose -f docker/compose.yml config --quiet
docker compose -f docker/compose.yml pull
docker compose -f docker/compose.yml up -d

docker compose -f docker/compose.yml exec --user www-data claire \
  ./console migrations:migrate --no-interaction
docker compose -f docker/compose.yml exec --user www-data claire \
  ./console migrations:status

docker compose -f docker/compose.yml logs -f claire
```

**Le démarrage de l'image n'applique pas les migrations.** Cette séquence concerne une première installation : gardez le service hors trafic jusqu'à l'initialisation de la base. Pour un environnement existant, suivez la [procédure de mise à jour](#exploitation-et-mises-à-jour).

Ouvrez ensuite votre URL publique et connectez-vous avec le SSO. `GET /health` renvoie la version et la date ; ce contrôle ne remplace pas un essai de conversation pour vérifier le modèle, Redis et les workers.

### Données persistantes

| Volume du modèle | Chemin dans le service | Contenu |
| --- | --- | --- |
| `claire-data` | `/opt/data` dans Claire | Base SQLite, fichiers et données RAG. |
| `claire-addons` | `/opt/addons` dans Claire | Agents YAML et workflows ComfyUI. |
| `claire-redis` | `/data` dans Redis | Queue, états de génération et autres données Redis persistantes. |

Redis n'est pas un simple cache jetable. Conservez sa persistance AOF et la politique `maxmemory-policy noeviction` du modèle. Ne lancez pas `docker compose down -v` pour une mise à jour ordinaire : cette option supprime les volumes.

## Configuration

Les fichiers [`config/settings/`](config/settings/) constituent la référence détaillée. Les valeurs ci-dessous sont les défauts du code lorsqu'ils existent ; le modèle Compose peut les remplacer.

### Socle applicatif

| Variable | Usage / défaut |
| --- | --- |
| `BASE_URL` | URL publique obligatoire, en HTTPS pour la production. |
| `APP_NAME` | Nom de l'interface normale et de la PWA ; `Claire`. Ne renomme ni les agents ni le widget. |
| `OPENAPI_URL` | URL de base de l'API LLM, obligatoire. |
| `OPENAPI_MODEL` | Modèle de conversation, obligatoire. |
| `OPENAPI_KEY` | Clé du fournisseur, selon ses exigences. Le préfixe est bien `OPENAPI_`. |
| `OPENAPI_MODEL_SUMMARY` | Modèle de résumé ; reprend `OPENAPI_MODEL`. |
| `OPENAPI_MODEL_EMBED` | Modèle d'embeddings nécessaire au RAG. |
| `OPENAPI_CONTEXT_WINDOW` | Fenêtre de contexte ; `50000`. |
| `OPENAPI_REQUEST_TIMEOUT` | Timeout HTTP du fournisseur en secondes ; `180`. |
| `OPENID_WELLKNOWN_URL` | URL de découverte OIDC, obligatoire. |
| `OPENID_CLIENT_ID` | Identifiant du client OIDC, obligatoire. |
| `OPENID_CLIENT_SECRET` | Secret du client, selon le fournisseur. |
| `OPENID_ACCESS_TOKEN_CLIENT_IDS` | Clients émetteurs autorisés pour l'échange de jetons d'accès SSO. Voir la [configuration OIDC](config/settings/oidc.php). |
| `SESSION_JWT_SECRET` | Secret de signature stable, au moins 32 caractères. |
| `SESSION_LIFETIME` | Durée d'un JWT de session en secondes ; `900`. |
| `SESSION_REFRESH_BEFORE_EXPIRE` | Marge de renouvellement en secondes ; `120`. |
| `SESSION_REFRESH_MIN_INTERVAL` | Intervalle minimal entre tentatives de renouvellement ; `30`. |
| `ALLOWED_CORS_ORIGINS` | Origines séparées par des virgules sans espaces ; `*` par défaut. Préférer une liste explicite. |
| `DEBUG_MODE` | Mode debug ; `false`. Ne pas activer en production. |

### SQL et Redis

Définissez explicitement `DATABASE_KIND` : `sqlite`, `mysql` ou **`pgsql`** pour PostgreSQL. Le modèle Docker choisit SQLite ; le code ne fournit pas de pilote par défaut.

Pour MySQL/MariaDB ou PostgreSQL, ajoutez `DATABASE_HOST`, `DATABASE_PORT`, `DATABASE_NAME`, `DATABASE_USER` et `DATABASE_PASSWORD`. Avec SQLite, le fichier est `<DATA_PATH>/database.sqlite`.

| Variable | Usage / défaut |
| --- | --- |
| `DATA_PATH` | Répertoire des données ; `<dépôt>/var/data` hors image Docker. |
| `ADDONS_PATH` | Répertoire des extensions ; `<dépôt>/var/addons` hors image Docker. |
| `REDIS_HOST` | Hôte Redis, obligatoire et accessible aux processus web, SSE et workers. |
| `REDIS_PORT` | `6379`. |
| `REDIS_DATABASE` | `0`. |
| `REDIS_PASSWORD` | Mot de passe Redis, si configuré. |
| `REDIS_PREFIX` | Préfixe des clés ; `claire:`. |
| `REDIS_TIMEOUT` | Timeout de connexion en secondes ; `2.0`. |
| `REDIS_READ_TIMEOUT` | Timeout de lecture en secondes ; `20.0`. |
| `QUEUE_WORKERS` | Nombre de workers supervisés dans l'image ; `8`. |
| `QUEUE_WORKER_TIMEOUT` | Attente d'un job en secondes ; `5`. |
| `QUEUE_WORKER_MAX_JOBS` | Recyclage après `256` jobs ; `0` pour illimité. |
| `QUEUE_WORKER_MAX_TIME` | Recyclage après `3600` secondes ; `0` pour illimité. |

### Services optionnels

| Fonction | Variables principales |
| --- | --- |
| Recherche web | `SEARXNG_URL`. |
| RAG | `OPENAPI_MODEL_EMBED`, `RAG_CHUNK_SIZE=1000`, `RAG_TOP_K=4`. |
| Mémoire durable | `LONG_TERM_MEMORY_MAX_CHARACTERS=4000`, `LONG_TERM_MEMORY_UPDATE_EVERY_USER_MESSAGES=5`, `LONG_TERM_MEMORY_REBUILD_BATCH_SIZE=20`. |
| Images | `COMFYUI_ENABLED=false`, `COMFYUI_URL`, `COMFYUI_DEFAULT_WORKFLOW`. |
| PDF | `PDF_ENABLED=true`, `PDF_DEFAULT_FORMAT=html`, `PDF_DEFAULT_PAGE_SIZE=A4`, `PDF_TEMP_DIR`. |
| Telegram | `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`. |
| Observabilité | `OTEL_SERVICE_NAME`, exporteurs `OTEL_TRACES_EXPORTER`, `OTEL_METRICS_EXPORTER`, `OTEL_LOGS_EXPORTER` et endpoint `OTEL_EXPORTER_OTLP_ENDPOINT`. |

### Audio Mistral

L'audio est indépendant du fournisseur utilisé pour le chat. Configurez une clé Mistral dédiée et des voix acceptées par le fournisseur.

| Variable | Défaut du code / rôle |
| --- | --- |
| `MISTRAL_AUDIO_ENABLED` | `false`. |
| `MISTRAL_AUDIO_API_URL` | `https://api.mistral.ai/v1`. |
| `MISTRAL_AUDIO_API_KEY` | Clé de l'API audio. |
| `MISTRAL_AUDIO_TRANSCRIPTION_MODEL` | `voxtral-mini-latest`. |
| `MISTRAL_AUDIO_SPEECH_MODEL` | `voxtral-mini-tts-2603`. |
| `MISTRAL_AUDIO_VOICES` | Tableau JSON de voix `{ "id": "...", "label": "..." }` ; `[]`. |
| `MISTRAL_AUDIO_DEFAULT_VOICE` | Identifiant par défaut ; première voix configurée si absent. |
| `MISTRAL_AUDIO_MAX_RECORDING_SECONDS` | Durée maximale de dictée web ; `300`. |

Les modèles réellement utilisés sont imposés côté serveur, même pour les routes audio acceptant un champ `model`. Le streaming natif du fournisseur audio n'est pas activé.

## Utilisation

### Conversations et mémoire

Après connexion, choisissez un agent, démarrez une conversation et envoyez votre message avec ou sans pièces jointes. L'historique permet de retrouver les échanges ; la dernière conversation est restaurée après rechargement. Les générations sont isolées par utilisateur et conversation.

Claire résume automatiquement le contexte court. La **mémoire long terme**, désactivée par défaut, s'active dans les préférences web, du widget ou de la Mini-App. Elle conserve une synthèse par utilisateur entre ses conversations, évolue périodiquement et peut être reconstruite depuis les résumés existants. Elle est supprimée avec le compte.

### Documents et recherche

Le panneau RAG permet d'ajouter un fichier, du texte ou une URL, de consulter les segments et d'activer, désactiver ou supprimer un document. Avec un modèle d'embeddings configuré et au moins un document actif, l'agent dispose de l'outil `rag_search`.

Les documents sont rattachés à l'utilisateur et stockés sous `<DATA_PATH>/rag`. Leur indexation fait appel au fournisseur d'embeddings configuré : tenez-en compte avant d'importer des données confidentielles.

La recherche web utilise séparément `SEARXNG_URL`. Elle ne remplace pas l'index documentaire personnel.

### Voix, images et PDF

- **Voix** : dictez un message, choisissez une voix et activez la lecture automatique ou demandez la synthèse d'une réponse. La lecture dépend aussi des règles d'autoplay du navigateur.
- **Fichiers audio** : l'outil `generate_speech` produit un MP3 conservé dans la conversation avec un lecteur protégé.
- **Images** : activez ComfyUI et déployez des workflows YAML dans `<ADDONS_PATH>/comfyui`. Chaque fichier contient un `label` et un champ `workflow` avec le graphe JSON ComfyUI ; les modèles de workflow peuvent utiliser `{{PROMPT}}` et `{{SEED}}`. Utilisez un graphe complet adapté à votre instance, pas un simple fragment de nœuds.
- **PDF** : l'outil `generate_pdf` accepte HTML ou Markdown, plusieurs formats de page, orientations et marges. Les documents sont liés à la conversation ; `PDF_TEMP_DIR` doit être accessible en écriture aux workers.

### Installer l'application web

Ouvrez directement Claire en HTTPS, hors navigation privée, puis utilisez l'installation proposée par votre navigateur :

| Plateforme | Parcours habituel |
| --- | --- |
| Chrome / Edge sur ordinateur | Icône d'installation dans la barre d'adresse ou menu du navigateur. |
| Chrome sur Android | Menu, puis « Installer l'application » ou « Ajouter à l'écran d'accueil ». |
| Safari sur iPhone / iPad | Partager, puis « Sur l'écran d'accueil » ; activer « Ouvrir comme app web » si proposé. |

La PWA concerne l'interface normale, pas le widget. Elle reste soumise au SSO et nécessite Internet : **aucun service worker ni mode hors ligne n'est fourni**. `APP_NAME` définit son nom et le titre de la page. Les navigateurs peuvent conserver temporairement l'ancien nom après un changement.

Le manifeste public est disponible à `/manifest.webmanifest`, ou sous le chemin de montage de Claire. Il doit renvoyer du JSON avec ses icônes accessibles, sans redirection SSO.

## Cerveaux personnalisés (BrainRegistry)

Un « cerveau » définit l'identité et les instructions d'un agent. Les agents PHP `claire` et `einstein` sont livrés avec le projet. Vos agents YAML sont déployés séparément, sans modification du registre PHP.

### Créer un agent YAML

Ajoutez `coach.yaml` dans `/opt/addons/agents/` pour l'image Docker, ou dans `<ADDONS_PATH>/agents/` hors Docker. Le nom du fichier sans `.yaml` devient le slug `coach`.

```yaml
name: "Coach Personnel"
description: "Un accompagnement concret pour avancer dans vos projets"
theme: energy
welcomes:
  - "Quel objectif souhaitez-vous travailler aujourd'hui ?"
  - "Commençons par une prochaine étape réalisable."
instruction: |
  Tu es un coach personnel bienveillant et pragmatique.
  Aide la personne à clarifier son objectif, puis propose des actions concrètes.
  Distingue les faits des hypothèses et adapte tes conseils à ses contraintes.
```

Le champ facultatif `avatar` accepte une chaîne représentant l'image de l'agent, par exemple une URL d'image ou une URL de données. Utilisez un slug distinct de ceux des agents intégrés. Sauvegardez vos fichiers privés séparément du dépôt : `local_data/addons/agents/` est ignoré par Git.

### Choisir un thème

| Preset | Ambiance |
| --- | --- |
| `cyberpunk` | Violet et rose, fond dégradé ; défaut de Claire. |
| `neon` | Cyan et bleu ; thème d'Einstein. |
| `energy` | Orange sur anthracite et ardoise. |
| `light` | Clair, éditorial, fond uni. |
| `romantic` | Rouge et bordeaux, finition satinée. |
| `dark` | Presque noir, accents bleus, fond uni. |

Les six presets sont versionnés dans [`config/themes/`](config/themes/). Leur disponibilité n'implique pas la présence d'agents YAML supplémentaires.

Le raccourci `theme: energy` sélectionne un preset. Pour personnaliser un agent, remplacez ce champ par un objet :

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

Les champs `preset`, `tokens` et `variants` sont optionnels. Des maps vides (`{}`) signifient « aucune surcharge ». Les clés sont sensibles à la casse et les valeurs des tokens doivent être des **chaînes YAML**, notamment les couleurs hexadécimales et les nombres.

Un agent PHP implémentant `BrainAvatar` hérite de `THEME = 'cyberpunk'`. Il peut choisir un autre preset, comme Einstein :

```php
public const string THEME = 'neon';
```

### Résolution et sécurité des thèmes

`ThemeRegistry` charge le répertoire local de confiance `themes.path`, défini dans [`config/settings/themes.php`](config/settings/themes.php), par défaut `<dépôt>/config/themes`. Il n'existe pas de variable intégrée `THEMES_PATH`.

Un preset tel que `light.yaml` contient directement les maps `tokens` et `variants`, sans enveloppe `theme` ni clé `preset`. Son nom respecte `[a-z][a-z0-9-]*`. Les deux maps sont requises mais peuvent être vides. Un fichier illisible ou de structure incorrecte est ignoré ; les entrées individuelles inconnues ou invalides sont filtrées.

Les règles de résolution sont les suivantes :

- Sans thème, ou avec une référence invalide ou inconnue, le preset sélectionné est `cyberpunk`. Une référence est un slug exact, jamais un chemin, une URL ou `light.yaml`.
- Les surcharges autorisées remplacent les valeurs du preset clé par clé, même lorsqu'une référence inconnue a déclenché le repli sur `cyberpunk`.
- Si le répertoire ou le contrat est indisponible ou invalide, la résolution renvoie `cyberpunk` avec des maps vides. Si seul le preset de repli manque, les surcharges autorisées par un contrat valide restent applicables.
- Les presets ne sont pas implicitement fusionnés avec `cyberpunk.yaml`. Les styles communs du frontend fournissent les valeurs de secours.
- Le contrat valide les noms et les types, **pas la sécurité ni la syntaxe des valeurs CSS**. Réservez ces fichiers à des administrateurs de confiance. Fournissez des valeurs de propriétés, pas des sélecteurs, des blocs `:root`, des `@import` ou des feuilles de style.

Le bootstrap expose toujours un objet de la forme suivante dans `brainInfo` et `brains`, y compris lorsque les maps sont vides :

```json
{"preset":"cyberpunk","tokens":{},"variants":{}}
```

Vue applique les tokens et les attributs `data-theme-controls` / `data-theme-effects` à `.claire-app`. Le changement d'agent retire les anciennes surcharges. Le widget applique le même thème dans son Shadow DOM, sans modifier la page hôte ni télécharger de feuille CSS d'agent.

**Migration des anciens agents :** `BrainAvatar::CSS` est remplacé par `THEME`. Les champs YAML `css` et `css_inline` sont ignorés ; `cssInline` et `dynamicCss` ne font plus partie du contrat frontend.

### API interne des thèmes

La source officielle est [`config/themes/contract.json`](config/themes/contract.json), un fichier interne au dépôt, pas un endpoint HTTP. Voici les **71 tokens publics**, regroupés par rôle. Les valeurs de référence restent dans les presets et [`frontend/styles/`](frontend/styles/).

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

Deux variantes sont publiques : `controls: solid | outline | soft` et `effects: none | glow | satin`. Toute autre clé ou valeur est ignorée. Les variantes réutilisent les styles communs et [`frontend/styles/variants.css`](frontend/styles/variants.css), sans sélecteur propre à chaque preset. La coloration du code suit également les tokens sémantiques du thème.

Les autres variables CSS internes, notamment celles des dimensions du widget, ne sont pas automatiquement des tokens publics d'agent.

### Recharger les agents

Les registres conservent agents et thèmes en mémoire par instance. Après modification, redémarrez les processus persistants concernés, notamment les workers de queue et les workers web, puis rechargez les interfaces ouvertes.

Lors d'un changement de configuration ou de code/DI, videz aussi le cache compilé **avant** le redémarrage. `cache:init` génère les proxies Doctrine : ce n'est pas une commande de rechargement des thèmes. Vider le cache disque ne recharge pas les instances déjà actives ; hors debug, le bootstrap web reconstruit le conteneur DI compilé.

## Widget embarqué

Le widget utilise les mêmes composants et fonctions de chat que l'application normale. Il apparaît replié sous forme d'avatar, puis s'ouvre en panneau de conversation au clic.

Chargez le bundle depuis votre instance et transmettez un jeton obtenu par le parcours d'authentification du site hôte :

```html
<div id="claire-root"></div>
<script src="https://claire.example.com/js/embed.js"></script>
<script>
  async function mountClaire(ssoAccessToken) {
    await window.claireEmbed({
      baseUrl: 'https://claire.example.com',
      target: '#claire-root',
      ssoToken: ssoAccessToken,
      ssoTokenType: 'access_token'
    });
  }
  // Appeler mountClaire avec le jeton de l'utilisateur connecté.
</script>
```

Ne placez pas de jeton permanent dans une page publique. Le fournisseur OIDC doit autoriser l'échange : un access token exige notamment une audience Claire et un client émetteur accepté par `OPENID_ACCESS_TOKEN_CLIENT_IDS`. Les ID tokens utilisent `ssoTokenType: 'id_token'` et sont également validés côté serveur. Configurez les origines CORS de Claire pour les sites hôtes.

Vous pouvez aussi fournir `sessionToken` avec un JWT Claire déjà obtenu. L'option `token` accepte un jeton de session Claire, pas implicitement un jeton SSO.

| Élément | Rôle |
| --- | --- |
| `POST /auth/embed/exchange` | Échange le jeton SSO contre une session Claire. |
| `GET /embed` | Renvoie le bootstrap JSON authentifié, pas une page HTML. |
| `window.claireEmbed(...)` | Initialise le widget ; remplace l'instance précédente si nécessaire. |
| `window.destroyClaireEmbed()` | Démonte le widget et ferme ses flux et timers. |
| [`public/embed.html`](public/embed.html) | Page de test d'intégration. |

Le Custom Element `<claire-chat-widget>` utilise un Shadow DOM. Le bundle ne remplace pas `window.fetch` et n'écrit pas la configuration sur `document.body`. Fournissez un `target` existant pour monter le widget à l'emplacement prévu.

## Telegram

### Configurer le bot

Créez un bot avec BotFather, puis configurez `TELEGRAM_BOT_TOKEN` et un `TELEGRAM_WEBHOOK_SECRET` robuste. **Sans secret webhook, son contrôle est désactivé.** L'URL publique de Claire doit être accessible à Telegram.

Après application de l'environnement et des migrations :

```bash
docker compose -f docker/compose.yml exec --user www-data claire \
  ./console telegram:webhook --set
docker compose -f docker/compose.yml exec --user www-data claire \
  ./console telegram:webhook --info
docker compose -f docker/compose.yml exec --user www-data claire \
  ./console telegram:set-commands
docker compose -f docker/compose.yml exec --user www-data claire \
  ./console telegram:menu-button --set
```

Les commandes `telegram:webhook` et `telegram:menu-button` acceptent aussi `--delete` et `--info`. Le webhook est construit à partir de `BASE_URL`.

### Associer un utilisateur

Dans l'interface web, ouvrez la configuration Telegram et renseignez votre **identifiant utilisateur numérique**, pas votre `@username`. Il doit être unique. Effacez le champ et enregistrez pour dissocier le compte.

Le bot accepte les commandes `/start`, `/help`, `/brain` et `/comfyui`, ainsi que les messages, photos et documents. Les voix et fichiers audio entrants sont transcrits lorsque l'audio Mistral est configuré ; le bot peut aussi répondre en audio.

La Mini-App permet de gérer les préférences, choisir une voix, ouvrir une nouvelle conversation et reconstruire la mémoire durable. C'est une interface de configuration liée au compte, pas une copie complète du chat web.

## API et authentification

Claire expose une **API applicative**. La compatibilité OpenAI concerne le fournisseur LLM et la forme des routes audio, pas une implémentation générale de `/v1/chat/completions`.

### Sessions et ressources

| Mécanisme | Utilisation |
| --- | --- |
| Connexion web | `GET /auth/sso`, puis retour sur `GET /auth/callback`. |
| JWT de session | Envoyé dans `X-Claire-Auth`, jamais dans l'URL. |
| Renouvellement | Récupérer `X-Claire-Token` dans les réponses ; `GET /auth/refresh` permet un refresh silencieux. |
| Connexion persistante | Cookie web/PWA `Secure`, `HttpOnly`, `SameSite=Lax`, associé à Redis ; limite absolue de sept jours. |
| Restauration / déconnexion | `POST /auth/remember` et `POST /logout`, gérés par le frontend. HTTPS, même origine et `X-Claire-Remember: 1` requis ; `Sec-Fetch-Site` doit être absent ou `same-origin`. |
| Fichiers et SSE | Jetons dédiés obtenus via `POST /auth/resource-token`. Seuls ces jetons limités sont prévus dans le paramètre d'URL `token`. |

Une session courte ne doit pas être confondue avec le `sessionId` d'un flux SSE, qui identifie le canal du client. Les anciens mini-tokens et les JWT de session placés dans les URL de ressources ne sont pas acceptés.

### Envoyer un message

1. Créez une conversation avec `POST /history/new` et récupérez `threadId` et `sessionId`. Pour reprendre une conversation, fournissez votre identifiant de canal client `sessionId` à `GET /history/open/{threadId}?sessionId=SESSION_ID` : cette route ne renvoie que `threadId`, elle ne crée pas de `sessionId` pour vous.
2. Demandez un jeton de ressource pour ce couple `threadId` / `sessionId` et conservez le même canal pour le flux et les messages.
3. Ouvrez le flux SSE et, pour une nouvelle conversation, attendez la fin de l'initialisation : `chat.snapshot` avec `responding: false`.
4. Envoyez le message ; la réponse finale arrivera sur le flux, pas dans la réponse HTTP du POST.

Exemple avec les identifiants et le JWT obtenus lors de ces étapes :

```bash
curl 'https://claire.example.com/brain/messages' \
  -H "X-Claire-Auth: $CLAIRE_SESSION_TOKEN" \
  --data-urlencode 'message=Bonjour Claire !' \
  --data-urlencode "threadId=$THREAD_ID" \
  --data-urlencode "sessionId=$SESSION_ID"
```

Réponse d'acceptation, statut **202** :

```json
{"threadId":"...","messageId":"assistant-message-...","accepted":true}
```

La création de conversation est elle-même asynchrone et renvoie `{threadId, sessionId}`. Une conversation occupée ou supprimée peut produire un conflit `409` lors de l'envoi. Des pièces jointes peuvent être transmises avec `file_ids[]` ou `upload_files[]`.

Pour obtenir un jeton de ressource, envoyez à `POST /auth/resource-token`, avec `X-Claire-Auth`, un corps JSON tel que :

```json
{
  "resources": [
    {"type": "file", "fileId": "FILE_ID"},
    {"type": "stream", "threadId": "THREAD_ID", "sessionId": "SESSION_ID"}
  ]
}
```

Une portée unique peut aussi être fournie directement. La réponse contient `{token, expiresAt}`. Le jeton dure au plus 300 secondes ; un lot accepte au plus 32 portées et 4000 octets sérialisés. Renouvelez les jetons expirés et ne les transmettez pas à des URL tierces.

Le flux s'ouvre sur :

```text
GET /brain/stream?threadId=THREAD_ID&sessionId=SESSION_ID&token=RESOURCE_TOKEN
```

### Routes principales

Les routes applicatives exigent une session ou, pour les ressources concernées, un jeton dédié. Le healthcheck et le manifeste sont publics. Les déclarations HTTP sont regroupées dans [`config/routes/`](config/routes/) ; le flux SSE est servi séparément par le daemon.

| Domaine | Routes |
| --- | --- |
| Santé | `GET /health` |
| Historique | `GET /history/count`, `GET /history/list`, `GET /history/open/{threadId}` |
| Conversations | `POST /history/new`, `DELETE /history/exchange/last`, `DELETE /history/delete/{threadId}` |
| Messages | `POST /brain/messages`, `POST /brain/audio` |
| Fichiers | `GET /files/count`, `GET /files/list`, `GET /files/serve/{id}` |
| Gestion des fichiers | `POST /files/upload`, `POST /files/upload_rag`, `DELETE /files/delete/{id}` |
| Consultation RAG | `GET /rag/list`, `GET /rag/count`, `GET /rag/segments/{id}` |
| Gestion RAG | `POST /rag/upload`, `POST /rag/text`, `POST /rag/url`, `POST /rag/toggle/{id}`, `DELETE /rag/delete/{id}` |
| Préférences | `POST /config/audio`, `POST /config/brain_avatar`, `POST /config/long_term_memory`, `POST /config/long_term_memory/rebuild` |
| Audio | `POST /v1/audio/transcriptions`, `POST /v1/audio/speech` |

`/files/serve/{id}` sert images, audio, PDF et autres fichiers. L'ancienne route `/files/img_serve/{id}` est supprimée.

### Routes audio

`POST /v1/audio/transcriptions` accepte un multipart avec `file` et `model`, et les formats de réponse `json`, `verbose_json` ou `text`. Les extensions acceptées incluent WAV, MP3, FLAC, OGG et WebM.

`POST /v1/audio/speech` accepte `input`, `model`, `voice` et éventuellement `response_format`. La voix doit appartenir à la liste serveur. L'entrée est limitée à 4096 octets ; ni streaming audio, ni instructions de synthèse, ni vitesse différente de `1.0` ne sont pris en charge. Un service audio non configuré renvoie `503`.

Dans le chat, la synthèse asynchrone est livrée en Base64 par l'événement `chat.audio.ready`. Le bouton reste désactivé pendant sa génération, puis la lecture démarre si le navigateur l'autorise.

## Architecture

```text
Navigateur / widget / Telegram
              |
       FrankenPHP + Caddy
         |             |
    API Slim 4     /brain/stream
         |             |
         |       Daemon SSE ReactPHP
         |        |             |
         |   Redis Pub/Sub   Backend Slim interne
         |
     Queue Redis --> Workers --> LLM et outils externes
                         |
                    SQL + fichiers
```

Le shell HTML est préparé par `VueShell`. `ChatDataRenderer` fournit les données HTTP/SSE ; Vue rend les messages, le Markdown et les outils. Les styles partagés sont compilés pour le web et inclus dans le Shadow DOM du widget. Il n'y a ni htmx ni chargement dynamique de feuilles CSS d'agents.

Le routage Caddy livré réserve `127.0.0.1:8081` au daemon SSE et `127.0.0.1:8082` au backend Slim interne. Ces listeners ne doivent pas être publiés. Dans Docker, l'entrypoint génère un `SSE_INTERNAL_SECRET` aléatoire à chaque démarrage et le partage entre processus ; il remplace une éventuelle valeur existante, sans l'écrire sur disque ni la journaliser.

Le transport SSE utilise Redis Pub/Sub **sans persistance ni rejeu `Last-Event-ID`**. Une reconnexion obtient un nouveau jeton et un snapshot SQL. Une demande audio perdue doit être relancée manuellement.

### Réglages SSE

Les limites détaillées sont définies dans [`config/settings/sse.php`](config/settings/sse.php). Elles sont chargées au démarrage ; toute modification exige un redémarrage.

| Variables | Défauts / rôle |
| --- | --- |
| `SSE_DURATION` | Connexion autorisée pendant `1800` s, maximum `86400`, indépendamment de l'expiration du JWT d'ouverture. |
| `SSE_CHECK_INTERVAL`, `SSE_KEEPALIVE` | Contrôles de génération/révocation et maintien de connexion ; `15` s chacun. |
| `SSE_HTTP_TIMEOUT` | Appels internes ; `10` s. |
| `SSE_MAX_CONNECTIONS` | `1000` connexions par daemon. |
| `SSE_MAX_HTTP_REQUESTS`, `SSE_MAX_REDIS_COMMANDS` | Concurrence des appels internes ; `16` et `64`. |
| `SSE_MAX_PENDING_EVENTS` | `256` événements en attente par connexion. |
| `SSE_MAX_CLIENT_BUFFER`, `SSE_MAX_GLOBAL_BUFFER` | Budgets de tampon ; `16777216` et `134217728` octets. |
| `SSE_WRITE_TIMEOUT`, `SSE_SHUTDOWN_TIMEOUT` | Client lent et arrêt du daemon ; `15` et `5` s. |

## Exploitation et mises à jour

### Déployer une nouvelle version

Consultez le [CHANGELOG](CHANGELOG.md) et privilégiez une version d'image identifiée pour vos déploiements reproductibles.

1. Sauvegardez la base SQL, les fichiers, les agents locaux et Redis selon une procédure cohérente avec les traitements en cours.
2. Retirez le service du trafic et arrêtez les anciens workers avant de modifier le schéma ou le code.
3. Déployez la nouvelle version. Dans un environnement de maintenance utilisant le nouveau code et les mêmes volumes, exécutez `./console cache:clear`, `./console migrations:migrate --no-interaction`, puis `./console app:generate-proxies`.
4. Redémarrez ensemble le web, le daemon SSE et les workers avec la nouvelle configuration. Après modification de l'environnement Compose, recréez les conteneurs : un simple `restart` n'applique pas de nouvelles variables.
5. Vérifiez le statut des migrations, le SSO, une conversation avec streaming et les intégrations utilisées, puis rouvrez le trafic et rechargez les clients.

Déployez producteurs, daemon, proxy et frontend ensemble. Un rollback doit restaurer un ensemble cohérent, en tenant compte des migrations SQL. Prévoir une courte interruption avec reconnexion ; ne videz pas Redis pour effectuer la migration SSE, les anciennes listes expirent seules.

Pour une migration depuis les versions antérieures à 2.1, appliquez notamment les migrations créant le journal `telegram_generation`, remplacez les styles d'agents `css` / `CSS` par `theme` / `THEME` et adaptez les clients aux jetons de ressources ainsi qu'à `/files/serve/{id}`.

### Queue et diagnostic

Redis et au moins un worker sont requis pour le chat web, le widget, l'audio asynchrone et Telegram. L'image supervise déjà les workers et les recycle selon leurs limites de durée et de jobs. Hors Docker, fournissez votre propre supervision ; les extensions PHP `pcntl` et `posix` sont nécessaires au renouvellement des baux.

Pour un worker ponctuel au premier plan :

```bash
./console queue:work --max-jobs=100 --max-time=3600 --timeout=5
```

Les jobs sont réservés avec un bail renouvelable, retentés lorsque cela est sûr et conservés en dead-letter en cas d'échec non récupérable. Une génération ayant déjà tenté des outils n'est pas rejouée aveuglément.

La commande `chat:maintenance` permet de diagnostiquer une conversation sans relancer le LLM ni les outils. Elle fonctionne **en simulation par défaut** :

```bash
docker compose -f docker/compose.yml exec --user www-data claire \
  ./console chat:maintenance --user USER --thread THREAD

docker compose -f docker/compose.yml exec --user www-data claire \
  ./console chat:maintenance --compact --retention-days 7

docker compose -f docker/compose.yml exec --user www-data claire \
  ./console chat:maintenance --compact --retention-days 7 --apply --cursor 0
```

Poursuivez chaque parcours avec le curseur `sql:v1:` renvoyé, via `--cursor`, jusqu'à `0`. Après simulation, recommencez l'application à `0`. La rétention de sept jours n'est pas une tâche planifiée automatiquement.

La compaction efface uniquement les corps des journaux Telegram entièrement confirmés et livrés. Les identifiants anti-rejeu restent en SQL sans expiration ; les journaux actifs, ambigus ou en échec sont conservés. `--user USER --thread THREAD --reconcile --apply` ne marque une génération orpheline en erreur que si l'absence de travail restant est démontrée. Cette garantie suppose que les données de queue Redis n'ont pas été supprimées ou évincées.

### Journaux et sécurité

Le modèle Compose configure les logs console ; la présence d'un collecteur OpenTelemetry n'active pas automatiquement les traces et métriques, dont les exporteurs y valent `none`. Adaptez les exporteurs et l'endpoint à votre infrastructure.

Protégez les secrets, sauvegardes et données d'observabilité. N'exposez pas Redis, les listeners SSE internes ou l'interface de collecte sur Internet. Une restriction CORS contrôle l'accès des navigateurs, pas l'authentification de tous les clients HTTP.

## Développement

### Préparer l'environnement

Prévoyez PHP 8.5+, Composer 2, Node.js/npm, Redis et une base SQL. Le Dockerfile utilise Node 24 pour les builds frontend. Les extensions PHP requises comprennent notamment `curl`, `fileinfo`, `dom`, `libxml`, `pdo`, `redis`, `pcntl` et `posix`, plus le pilote PDO choisi ; Composer vérifie aussi les exigences transitives.

```bash
composer install
composer check-platform-reqs
npm ci
npm run build
```

Exportez les variables décrites dans la [configuration](#configuration), dont `DATABASE_KIND`, et préparez les répertoires de données et `var/` avec les droits d'écriture appropriés. Pour une exécution hors Docker, partagez un `SSE_INTERNAL_SECRET` aléatoire entre le backend et le daemon.

```bash
./console migrations:migrate
./console app:generate-proxies
```

Pour vérifier uniquement les routes HTTP :

```bash
php -S localhost:8080 -t public public/index.php
```

**Ce serveur seul ne fournit pas le chat complet en streaming.** Une installation complète doit aussi lancer `./console queue:work` et `./console sse:serve` dans des processus séparés, servir le backend Slim interne et router `/brain/stream` via Caddy. Prenez l'image Docker et les configurations de [`docker/rootfs/`](docker/rootfs/) comme référence d'assemblage. Le serveur local exige également un fournisseur OIDC acceptant l'URI de retour locale choisie.

### Frontend et tests

| Commande | Effet |
| --- | --- |
| `npm run build` | Vérification TypeScript, build normal dans `public/build/` et bundle autonome `public/js/embed.js`. |
| `npm run dev` | Lance Vite. Le shell PHP charge les bundles construits ; le HMR n'y est pas câblé automatiquement. |
| `npm test` | Tests frontend Vitest. |
| `composer test` ou `vendor/bin/phpunit` | Tests PHP. |
| `composer frontend:check` | Build et tests frontend. |
| `composer rector:check` | Vérification Rector sans application des corrections. |
| `composer insights:check` | Analyse de qualité PHP. |

`composer rector:fix` et `composer insights:fix` appliquent des corrections. **`composer pre-commit` modifie aussi les fichiers** : reconstruction des assets, normalisation des fins de ligne, permissions et corrections automatiques. Ce n'est pas une simple suite de tests ; il nécessite notamment `dos2unix`.

### Repères dans le dépôt

| Chemin | Responsabilité |
| --- | --- |
| [`src/Brain/`](src/Brain/) | Agents, outils et orchestration LLM. |
| [`src/Controller/`](src/Controller/) | Contrôleurs HTTP. |
| [`src/Services/`](src/Services/) | Services applicatifs. |
| [`src/Entity/`](src/Entity/), [`migrations/`](migrations/) | Modèle SQL et migrations Doctrine. |
| [`src/Services/Queue/`](src/Services/Queue/), [`src/Job/`](src/Job/) | Queue et traitements asynchrones. |
| [`frontend/`](frontend/) | Entrées `main.ts` et `embed.ts`, composants Vue et services TypeScript. |
| [`config/`](config/) | Injection de dépendances, routes, réglages et thèmes. |
| [`test/Unit/`](test/Unit/) | Tests PHPUnit. |
| [`docker/`](docker/) | Construction de l'image, entrypoint et supervision. |

Les conventions de contribution sont décrites dans [`AGENTS.md`](AGENTS.md). Pour les migrations, `./console migrations:generate` crée un squelette et `./console migrations:status` affiche l'état. La commande de génération des proxies est `./console app:generate-proxies` ; `./console cache:init` assure également leur génération.

## Dépannage

| Symptôme | Vérifications |
| --- | --- |
| Échec au lancement de Compose | Utiliser `-f docker/compose.yml`, fournir `SESSION_JWT_SECRET` et remplacer les valeurs d'exemple. |
| Connexion SSO impossible | Vérifier découverte OIDC, client, secret éventuel et correspondance exacte de `BASE_URL/auth/callback`. |
| Erreur SQL après installation ou mise à jour | Appliquer les migrations avec le nouveau code avant de remettre les traitements en service. |
| Erreur 500 ou cache impossible à générer | Vérifier les logs, les permissions de `var/` et du répertoire de données, ainsi que le pilote SQL. |
| Message accepté mais aucune réponse | Vérifier Redis, les workers, l'accès LLM et le routage du daemon SSE. Un serveur PHP seul ne suffit pas. |
| Conversation bloquée | Utiliser `chat:maintenance` en simulation ; ne pas purger Redis ni rejouer aveuglément les outils. |
| Flux SSE ou fichier refusé | Renouveler le jeton de ressource et vérifier sa portée exacte ; ne pas envoyer le JWT de session dans l'URL. |
| Widget non authentifié | Vérifier le type de jeton SSO, l'audience, les clients autorisés et les origines CORS. `/embed` requiert une session. |
| RAG indisponible | Configurer un modèle d'embeddings accessible et activer au moins un document. |
| Audio indisponible ou silencieux | Vérifier activation, clé Mistral, modèles, voix et autorisations de lecture du navigateur. |
| ComfyUI absent | Vérifier activation, URL et présence de workflows complets dans le répertoire des extensions. |
| Thème ou agent inchangé | Redémarrer les processus persistants et recharger le client ; vider le cache compilé si la configuration a changé. |
| Nouvelle variable non prise en compte | Recréer le conteneur, puis recharger les processus et caches concernés. Un `restart` seul conserve l'ancien environnement. |
| PWA non proposée | Vérifier HTTPS, manifeste JSON public et icônes ; les possibilités d'installation dépendent du navigateur. |

## Licence

Claire est distribué sous [licence MIT](LICENSE).
