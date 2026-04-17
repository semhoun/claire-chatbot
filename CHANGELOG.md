# Changelog

Tous les changements notables de ce projet seront documentés dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet adhère à [Semantic Versioning](https://semver.org/lang/fr/).

## [Unreleased]

## [1.4.5] - 2026-04-17

### Added
- Validation HMAC des données d'initialisation Telegram pour la Mini-App
- Variable d'environnement `BASE_URL` pour définir l'URL de base de l'application (utilisée pour les URLs absolues dans les emails, webhooks, etc.)

### Changed
- **BREAKING**: L'authentification SSO OpenID Connect est désormais obligatoire (suppression du fallback utilisateur de démonstration)
- **BREAKING**: Suppression du mode daemon Telegram (polling) - seul le mode webhook est supporté
- **BREAKING**: La commande `telegram:webhook` utilise maintenant `--set` (utilise `BASE_URL`) au lieu de `--domain` et `--url`
- Mise à jour de `OidcClient` avec gestion améliorée des tokens et revocation
- Optimisation du JavaScript avec chargement différé des ressources non critiques
- Nettoyage du code suite à l'analyse PHP Insights
- Amélioration du rendu Markdown dans les messages Telegram

### Fixed
- Corrections de formatage MarkdownV2 pour Telegram
- Correction de l'URL du webhook Telegram pour utiliser la `BASE_URL` configurée
- Corrections mineures de formatage MarkdownV2 pour Telegram

### Removed
- **BREAKING**: Suppression du support des fichiers `.env` - l'application utilise uniquement les variables d'environnement système/Docker (retrait de la dépendance `vlucas/phpdotenv`)

## [1.4.4] - 2026-04-15

### Added
- **Mini-App Telegram** : Interface web complète embarquée dans Telegram avec authentification automatique
  - Contrôleur `TelegramWebAppController` avec validation des données WebApp
  - Service `TelegramWebAppValidator` pour vérifier l'intégrité des données Telegram
  - Template `tmpl/telegram_webapp/index.twig` avec interface de chat dédiée
  - Support de la configuration utilisateur (brain, workflow ComfyUI) dans la mini-app
  - Endpoint API sécurisé pour la mini-app (`/telegram/webapp/*`)
- Commande `telegram:set-menu-button` pour configurer le bouton du menu Telegram pointant vers la Mini-App
- Support complet des données d'initialisation Telegram (user, hash, auth_date) avec validation HMAC

### Changed
- Refonte du service `TelegramService` pour supporter la Mini-App avec méthodes d'envoi de messages et gestion des erreurs améliorées
- Mise à jour des routes Telegram pour inclure les endpoints de la Mini-App

### Fixed
- Correction dans `ComfyUIService` pour la gestion des workflows
- Correction de la détection des workflows dans `ComfyUIWorkflowRegistry`
- Amélioration de la gestion des erreurs Redis dans `RedisClient`

## [1.4.3] - 2026-04-13

### Changed
- Migration de tout le JavaScript inline vers un fichier dédié `public/js/app.js` pour une meilleure maintenabilité
- Ajustement du ratio d'aspect des images dans les workflows ComfyUI (flux.yaml, zit.yaml)

### Fixed
- Correction mineure dans `RedisClient`

## [1.4.2] - 2026-04-12

### Added
- Fonction `App::Env()` pour remplacer l'ancien helper `env()` avec une approche plus robuste
- Support de ZImage Turbo pour la génération d'images (workflow plus rapide et léger)

### Changed
- **Queue**: Migration de l'architecture Sorted Set (ZRANGEBYSCORE) vers List (BRPOP/LPUSH) pour une exécution immédiate des jobs
- **Queue**: Suppression du paramètre `availableAt` - tous les jobs sont exécutés dès que possible
- **Queue**: Remplacement du polling PHP par un blocage Redis avec `BRPOP` (timeout configurable)
- **Queue**: Option `--sleep` remplacée par `--timeout` dans `queue:work`
- **Queue**: Le worker n'attend plus en PHP quand la queue est vide, mais bloque directement dans Redis
- Sauvegarde des sessions Telegram uniquement si des modifications ont été détectées (optimisation SQLite)
- Nettoyage du code suite à l'analyse PHP Insights
- Suppression des configurations de serveurs web alternatifs non utilisés
- Mise à jour des tests unitaires

### Fixed
- Correction des migrations pour assurer la compatibilité avec PostgreSQL (renommage de la table `user` en `account`)
- Correction du rafraîchissement forcé de l'entité de session Telegram, particulièrement utile pour SQLite
- Correction des migrations pour éviter les conflits de noms de tables réservés

### Removed
- **RedisClient**: Suppression des méthodes inutilisées `eval()`, `zadd()`, `expire()`, `ping()`
- **ComfyUI**: Suppression du support SDXL et de la génération de mots-clés (remplacé par ZImage Turbo)

## [1.4.1] - 2026-04-11

### Added
- Support de la variable `{{USER}}` dans les instructions de tous les agents, remplacée par le nom d'affichage de l'utilisateur
- Documentation de l'authentification SSO OpenID Connect via `/auth/sso` et `/auth/callback`, avec fallback automatique sur un utilisateur de démonstration si OIDC n'est pas configuré
- Documentation de l'association d'un compte utilisateur web avec Telegram via l'enregistrement d'un identifiant Telegram dans l'interface
- Documentation des commandes Telegram `telegram:webhook` et `telegram:set-commands`, ainsi que du secret `TELEGRAM_WEBHOOK_SECRET`

### Changed
- Validation du brain actif lors de la connexion pour éviter de conserver une sélection invalide
- Initialisation des addons depuis `/opt/addons` lorsqu'ils ne sont pas encore présents
- Mise à jour du README pour refléter la version 1.4.1, l'endpoint `/files/img_serve/{id}`, les types de fichiers acceptés et la persistance des workflows ComfyUI côté web/Telegram

### Fixed
- Correction des retours à la ligne dans les messages
- Suppression du retour automatique en bas de conversation pendant le streaming
- Désactivation de l'affichage d'image pendant le streaming
- Alignement de la documentation sur le comportement actuel du streaming SSE par onglet via `sessionId`
- Nettoyage de code divers

## [1.4.0] - 2026-04-09

### Added
- Nouvelles méthodes dans `RedisClientInterface`: `ping()`, `close()`, `setReadTimeout()`, `reconnect()`
- Variable d'environnement `REDIS_READ_TIMEOUT` (défaut: 5.0s) pour configurer le timeout de lecture Redis
- Implementation complete de Server-Sent Events (SSE) pour le streaming des reponses
- Support des workers de queue multiples dans Docker avec variable `QUEUE_WORKERS`
- Possibilite de supprimer le dernier message d'une conversation
- Identifiant SSE unique pour chaque connexion streaming

### Changed

- **BREAKING**: Déplacement du répertoire applicatif de `/www` vers `/opt/www` dans l'image Docker
- **BREAKING**: L'interface web passe en mode streaming uniquement (suppression du mode chat classique)
- Remplacement de `chatid` par `session_id` dans les endpoints pour plus de coherence
- Le `QueueWorkCommand` utilise l'abstraction cache au lieu de Redis directement
- Amelioration de l'interface utilisateur et corrections visuelles diverses
- Optimisation du systeme de files d'attente avec meilleure gestion des workers
- Amélioration du prompt de résumé dans `ShortMemory` pour mieux préserver les directives et préférences utilisateur
- **Telegram**: La commande `/brain` sans arguments affiche maintenant la personnalité actuelle ET la liste des personnalités disponibles
- **Telegram**: Reformattage de l'affichage des workflows ComfyUI (suppression des titres markdown, texte simple)

### Removed
- **Telegram**: Suppression de la commande `/list` (fonctionnalité intégrée dans `/brain`)

### Fixed
- Correction de la suppression du dernier message dans l'historique
- Correction de l'ajout de fichiers dans les conversations
- Correction de l'interface Telegram et gestion des sessions
- Correction du blocage des workers de queue après période d'inactivité causé par la fermeture de la connexion Redis
- Ajout de la reconnexion automatique Redis dans le worker avec détection via `ping()` avant chaque tentative de job
- Configuration du `readTimeout` Redis pour éviter les blocages indéfinis sur les opérations réseau
- Correction du formatage MarkdownV2 pour les slugs de workflows et de brains dans Telegram (remplacement du gras par du code monospace pour éviter les erreurs de parsing avec les underscores)

## [1.3.3] - 2026-04-05

### Added
- Infrastructure de queue minimaliste avec worker CLI `queue:work` basee sur Redis
- Integration du traitement Telegram en asynchrone via la queue

### Changed
- Simplification du modele de queue: un job est retire de la queue des sa prise en charge par le worker
- `TelegramService` implemente maintenant directement le contrat `QueueDoer`
- Mise a jour du `README.md` pour documenter la queue Redis et le lancement du worker

### Removed
- Suppression de la gestion des retries, des echecs et des reservations temporaires dans l'infrastructure de queue
- Suppression du statut logique des jobs dans le code applicatif de la queue
- Suppression de l'implementation Doctrine de la queue et de la migration associee

## [1.3.2] - 2026-04-05

### Added
- Registre de workflows ComfyUI chargeant automatiquement les fichiers YAML, YML ou JSON depuis `addons/comfyui/`
- Nouvelle option dans le menu web pour choisir le workflow ComfyUI actif lorsque ComfyUI est active
- Nouvelle commande Telegram `/comfyui` pour afficher et changer le workflow ComfyUI courant
- Documentation des workflows ComfyUI multiples dans le `README.md`

### Changed
- Evolution de l'integration ComfyUI pour supporter plusieurs workflows selectionnables, sur le modele des agents YAML
- Memorisation du workflow ComfyUI choisi dans la session utilisateur et dans les options persistantes de l'utilisateur
- Adaptation automatique du type de prompt image selon le workflow ComfyUI selectionne
- Le workflow ComfyUI par defaut peut etre force via `COMFYUI_DEFAULT_WORKFLOW`, sinon le premier fichier charge est utilise
- Suppression du README local dans `addons/comfyui/` au profit de la documentation centrale

### Removed
- Suppression du support de configuration mono-workflow via les variables `COMFYUI_WORKFLOW` et `COMFYUI_PROMPT_STYLE`

### Fixed
- Desactivation complete des options de workflow ComfyUI lorsque ComfyUI est desactivee
- Gestion plus explicite de l'absence de workflows ComfyUI valides

## [1.3.1] - 2026-04-04

### Added
- Prise en charge des photos dans le message d'ouverture Telegram quand l'avatar retourne une reponse contenant des images
- Generation automatique du titre et du resume de conversation si absents (pour les sessions Telegram)

### Changed
- Amelioration visuelle des themes de chat, de la feuille de style principale et du template `tmpl/chat.twig`
- Harmonisation des valeurs de session Telegram avec les parametres utilisateur et les valeurs par defaut de `session.defaultParams`

### Fixed
- Application du timeout de workflow aux updates Telegram pour eviter un traitement sans limite de temps
- Correction du rendu MarkdownV2 des listes ordonnees envoye par Telegram
- Suppression du fichier de theme `public/css/flashy.css` devenu obsolete

## [1.3.0] - 2026-04-03

### Added
- Middleware de memoire courte pour resumer automatiquement l'historique quand la fenetre de contexte devient trop grande
- Instrumentation OpenTelemetry simplifiee pour les spans et logs internes des agents
- Bouton d'annulation du dernier echange dans l'interface web
- Bouton flottant pour revenir rapidement en bas de conversation
- Colonnes `display_messages` et `display_messages_count` dans `chat_history` pour separer l'historique LLM de l'historique d'affichage
- Migration de base de donnees pour convertir les historiques existants vers le nouveau format
- Timeout configurable sur les appels LLM et le workflow d'agent
- Parametres `llm.shortMemory` et `llm.summary` pour piloter la memoire courte et la generation de resumes
- Sauvegarde du message d'ouverture dans l'historique affiche a l'utilisateur

### Changed
- Mise à jour de la documentation pour le runtime Docker basé sur FrankenPHP/Caddy
- Documentation des variables `ENABLE_LETSENCRYPT` et `ACME_EMAIL` pour activer HTTPS automatique via Let's Encrypt
- Migration du runtime Docker principal vers FrankenPHP avec Caddy et activation des modules `transform-encoder`, Mercure et Vulcain
- Suppression de Tracy au profit d'OpenTelemetry pour l'observabilite et le diagnostic
- Amelioration de la persistance des conversations avec separation entre messages internes LLM et messages affichables
- Optimisation de la generation des resumes et de la sequence des messages historiques
- Amelioration de l'interface de chat avec affichage immediat du message utilisateur, meilleur etat de chargement et navigation plus fluide dans les longues conversations
- Le mode de chat par defaut passe a `stream`
- La liste des cerveaux par defaut remplace `flashy` par `calliope`
- Mise a jour du README pour refleter la version courante 1.3.0, le runtime FrankenPHP/Caddy, la disparition de Tracy et les nouvelles capacites de l'historique de conversation

### Fixed
- Correction et nettoyage des historiques de conversation invalides ou incomplets
- Suppression de l'indicateur textuel `Envoi en cours...` au profit d'un comportement de chargement plus discret

## [1.2.3] - 2026-03-31

### Added
- Documentation de la version 1.2.3 dans le README et le changelog

### Changed
- Mise à jour du README pour refléter la version courante 1.2.3 dans les exemples et l'introduction

### Added
- Filtre Twig `OCFilterExtension` avec filtre `filter_oc_tags` pour retirer les blocs internes `[OC]...[/OC]` avant rendu
- Tests unitaires pour le filtrage des balises `[OC]`
- Option de configuration `OPENAPI_CONTEXT_WINDOW` pour ajuster la fenêtre de contexte LLM
- Injection automatique de la date et de l'heure courantes dans les instructions système des agents et du RAG

### Changed
- Le template `tmpl/partials/message.twig` filtre désormais les balises `[OC]` avant conversion Markdown et n'affiche plus les messages vides après nettoyage
- Le service Telegram filtre aussi les balises `[OC]` avant l'envoi des messages et légendes
- Le message d'accueil généré par l'agent encapsule désormais son prompt interne dans des balises `[OC]`

### Fixed
- Empêche l'affichage côté web et Telegram des contenus internes marqués `[OC]`

## [1.2.0] - 2026-03-21

### Added
- Horodatage des messages avec affichage relatif (il y a X min) et tooltip date complète
- Extension Twig `TimestampExtension` pour le formatage des dates
- Observer `TimestampObserver` pour l'ajout automatique des timestamps
- Tests unitaires pour le système d'horodatage des messages
- Ajout de la date courante dans le prompt système pour une meilleure contextualisation
- Support des cerveaux personnalisés via fichiers YAML dans `addons/agents/`
- Classe `YamlBrain` pour charger dynamiquement les agents depuis des fichiers YAML
- Intégration automatique des brains YAML dans `BrainRegistry`
- Fichier exemple `addons/agents/flashy.yaml` (réplique en YAML du cerveau Flashy IoT)
- Fichier exemple `addons/agents/coach.yaml` (exemple de cerveau coach personnel)
- Dépendance `symfony/yaml` pour le parsing YAML
- Support Docker avec image complète (Apache, PHP-FPM, Supervisor, fcron)
- Dockerfile multi-stage pour optimiser la taille de l'image
- Configuration Apache intégrée avec mod_rewrite et proxy_fcgi
- Healthcheck Docker sur l'endpoint `/health`
- Volume `/data` pour la persistance des données
- Variable d'environnement `DATA_PATH` pour configurer le chemin des données persistantes
- Entrypoint Docker pour la configuration dynamique
- Support OpenTelemetry dans l'image Docker
- Génération d'images avec ComfyUI (outil `generate_image`)
- Service `ComfyUIService` pour l'intégration avec ComfyUI
- Extension Twig `GeneratedImageExtension` pour l'affichage des images générées
- Post-processing des messages avec `PostProcessChatNode` et `PostProcessStreamingNode`
- Endpoint `/files/generated/{filename}` pour servir les images générées
- Support de deux styles de prompts pour ComfyUI : SDXL (keywords) et Flux (natural language)

### Changed
- Suppression du fichier CSS `claire.css` (migration vers CSS inline ou YAML)
- Refactoring de `BrainRegistry` pour améliorer la gestion des cerveaux YAML
- Amélioration de la gestion des nodes et tools dans `AgentTrait`
- Extension du trait `Tools` pour une meilleure extensibilité
- Optimisation de `TelegramService` pour la gestion des sessions et images générées
- Amélioration de `ComfyUIService` pour le post-processing des images
- Amélioration du service Telegram avec support des images générées
- Refactoring des traits de l'Agent (ajout de `Nodes` et `Tools`)
- Extension de l'interface `MessagePostProcessorInterface` pour le traitement des messages

### Fixed
- Correction de la détection du style de prompt ComfyUI (SDXL vs Flux)
- Correction du traitement des messages générés avec images
- Amélioration de la gestion des erreurs dans le service Telegram
- Correction des permissions de fichiers dans l'image Docker

## [1.1.0] - 2026-03-16

### Added
- Sécurisation du webhook Telegram avec token secret (`TELEGRAM_WEBHOOK_SECRET`)
- Commande `telegram:webhook` avec options `--domain` (HTTPS forcé, chemin auto-généré) et `--url` (URL complète)
- Sessions JWT stateless pour l'authentification
- Support de Telegram Bot avec sessions dédiées (`TelegramSession`)
- Gestion des sessions depuis la requête HTTP via `SessionFromRequestTrait`
- Tests unitaires supplémentaires (Auth, Settings, FileController, etc.)
- Commande console `cache:init` pour initialiser le cache
- Nouvelle commande console `telegram:set-commands` pour configurer le menu des commandes du bot Telegram
- Outil de recherche web (`web_search`) via SearXNG
- Support du SDK Telegram `phptg/bot-api` (remplace `irazasyed/telegram-bot-sdk`)
- Commandes Telegram mises à jour: `/start`, `/help`, `/brain`
- Entité `TelegramSession` pour persister les sessions utilisateurs Telegram
- Repository `TelegramSessionRepository` pour la gestion des sessions Telegram

### Changed
- Nettoyage du code avec Rector et PHP Insights
- Refactoring complet de la gestion des sessions (passage à JWT stateless)
- Remplacement de la bibliothèque Telegram par `phptg/bot-api`
- Mise à jour des contrôleurs pour utiliser `SessionFromRequestTrait`
- Refactoring de `Agent` avec séparation des traits (AIProvider, Constructor, Middleware, UserChatHistory)
- Amélioration du `BrainRegistry` pour la gestion des avatars
- Simplification de la gestion des réponses JSON avec `JsonRenderer`

### Fixed
- Correction de bugs sur les bordures d'affichage
- Correction des appels d'outils (tool calls)
- Correction de la summarisation pour les LLM qui ajoutent du texte avant les données
- Correction du mode streaming
- Correction des fins de ligne (CRLF → LF)
- Correction des permissions de fichiers
- Correction du chat Telegram avec gestion appropriée des sessions
- Correction du brain par défaut lors de la sélection invalide

## [1.0.0] - 2026-03-15

### Added
- **Multi-brains** : Support de multiples agents IA (brains) avec sélection dynamique
- **Système de fichiers** : Upload, gestion et téléchargement de fichiers
  - Support des tokens pour récupération de fichiers (compatible ChatGPT)
  - Support des extensions de fichiers configurables
  - Indicateur de téléchargement visuel
- **RAG (Retrieval-Augmented Generation)** : Recherche et injection de contexte dans les conversations
- **Mode streaming** : Réponses en temps réel des agents
- **Résumés automatiques** : Génération de résumés des conversations
- **Authentification** :
  - Support SSO/OpenID Connect
  - Middleware d'authentification
  - Gestion des utilisateurs avec historique de chat lié
- **Base de données** :
  - Intégration Doctrine ORM
  - Entité `ChatHistory` pour persister les conversations
  - Entité `User` pour la gestion des utilisateurs
  - Support SQLite, MySQL et PostgreSQL
  - Système de migrations Doctrine
- **Agent Tools** : Outils extensibles pour les agents IA
- **Observabilité** :
  - Intégration OpenTelemetry complète
  - Export OTLP
  - Auto-instrumentation Slim, PSR-18, Doctrine, cURL, Guzzle
- **Interface** :
  - Templates Twig avec support Markdown
  - Mise en forme du code avec coloration syntaxique
  - Support des diagrammes Mermaid
  - Affichage optimisé pour mobile
  - Middleware de détection proxy
- **Configuration** :
  - Support des fichiers `.env` via vlucas/phpdotenv
  - Fonctions helper `env()` et `env_required()`
  - Classe `Settings` pour la configuration centralisée
- **Code quality** :
  - Intégration Rector pour la modernisation PHP 8.4
  - PHP Insights pour l'analyse de qualité
  - Pré-commit hooks pour les fins de ligne et permissions
- **Console** : Commandes Symfony Console pour la gestion de la base de données
- **Health check** : Endpoint `/health` pour le monitoring

### Changed
- Refactoring complet de l'Agent avec pattern Brain/Avatar
- Factorisation du chat et du streaming
- Mise à jour de la bibliothèque Neuron AI

### Fixed
- Correction de la gestion des erreurs et envoi vers OpenTelemetry
- Correction des dépendances
- Divers correctifs pour l'affichage mobile

---

[Unreleased]: https://github.com/semhoun/claire-chatbot/compare/1.5.0...HEAD
[1.5.0]: https://github.com/semhoun/claire-chatbot/compare/1.4.5...1.5.0
[1.4.5]: https://github.com/semhoun/claire-chatbot/compare/1.4.4...1.4.5
[1.4.4]: https://github.com/semhoun/claire-chatbot/compare/1.4.3...1.4.4
[1.4.3]: https://github.com/semhoun/claire-chatbot/compare/1.4.2...1.4.3
[1.4.2]: https://github.com/semhoun/claire-chatbot/compare/1.4.1...1.4.2
[1.4.1]: https://github.com/semhoun/claire-chatbot/compare/1.4.0...1.4.1
[1.4.0]: https://github.com/semhoun/claire-chatbot/compare/1.3.3...1.4.0
[1.3.3]: https://github.com/semhoun/claire-chatbot/compare/1.3.2...1.3.3
[1.3.2]: https://github.com/semhoun/claire-chatbot/compare/1.3.1...1.3.2
[1.3.1]: https://github.com/semhoun/claire-chatbot/compare/1.3.0...1.3.1
[1.3.0]: https://github.com/semhoun/claire-chatbot/compare/1.2.3...1.3.0
[1.2.3]: https://github.com/semhoun/claire-chatbot/compare/1.2.0...1.2.3
[1.2.0]: https://github.com/semhoun/claire-chatbot/releases/tag/1.2.0
[1.1.0]: https://github.com/semhoun/claire-chatbot/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/semhoun/claire-chatbot/releases/tag/1.0.0
