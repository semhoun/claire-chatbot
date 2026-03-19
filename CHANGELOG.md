# Changelog

Tous les changements notables de ce projet seront documentés dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet adhère à [Semantic Versioning](https://semver.org/lang/fr/).

## [Unreleased]

### Added
- Support des cerveaux personnalisés via fichiers YAML dans `addons/agents/`
- Classe `YamlBrain` pour charger dynamiquement les agents depuis des fichiers YAML
- Intégration automatique des brains YAML dans `BrainRegistry`
- Fichier exemple `addons/agents/flashy.yaml` (réplique en YAML du cerveau Flashy IoT)
- Dépendance `symfony/yaml` pour le parsing YAML

## [1.2.0] - 2026-03-19

### Added
- Génération d'images avec ComfyUI (outil `generate_image`)
- Personnalisation du prompt système de Claire via `CLAIRE_PROMPT`
- Messages d'accueil personnalisables avec sélection aléatoire (`CLAIRE_WELCOME_MESSAGES` ou `CLAIRE_WELCOME_MESSAGE`)
- Service `ComfyUIService` pour l'intégration avec ComfyUI
- Extension Twig `GeneratedImageExtension` pour l'affichage des images générées
- Post-processing des messages avec `PostProcessChatNode` et `PostProcessStreamingNode`
- Endpoint `/files/generated/{filename}` pour servir les images générées
- Support de deux styles de prompts pour ComfyUI : SDXL (keywords) et Flux (natural language)

### Changed
- Amélioration du service Telegram avec support des images générées
- Refactoring des traits de l'Agent (ajout de `Nodes` et `Tools`)
- Extension de l'interface `MessagePostProcessorInterface` pour le traitement des messages

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
- Commandes Telegram mises à jour: `/start`, `/help`, `/list`, `/brain`
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

[Unreleased]: https://github.com/semhoun/claire-chatbot/compare/1.2.0...HEAD
[1.2.0]: https://github.com/semhoun/claire-chatbot/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/semhoun/claire-chatbot/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/semhoun/claire-chatbot/releases/tag/1.0.0
