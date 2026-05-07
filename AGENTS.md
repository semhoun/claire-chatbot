# AGENTS.md — Claire Chatbot Project Guidelines

## Project Overview

Claire is a PHP 8.5+ AI agent chatbot built with Slim 4, Twig, Doctrine ORM, and the Neuron AI library. It provides a web interface and API for interacting with LLMs via an OpenAI-compatible interface.

The project runs in Docker containers (PHP 8.5, PostgreSQL, Redis, Nginx) via Docker Compose.

## Display Modes

Claire has two distinct display modes sharing the same core components.

### Normal Mode (`layout.twig` + `chat.twig`)

Full-page chat with a collapsible sidebar options panel.

- **Layout**: `layout.twig` provides the HTML shell (`<html>`, `<head>`, asset links, scripts).
- **Sidebar block**: `{% block sidebar %}` wraps the options backdrop + floating panel. Child templates can override or empty it.
- **Chat page**: `chat.twig` extends `layout.twig`, renders the header (avatar + name + hamburger toggle) and includes `partials/chat_body.twig`.
- **Options panel**: Rendered via `partials/options_content.twig` inside `<aside id="claire-options-panel">`. It is a floating panel (not a grid column) that slides in from the right.

### Embed / Widget Mode (`embed.twig`)

Floating widget injected into third-party pages via `window.claireEmbed(...)`.

- **Entry point**: `GET /embed` (`App\Controller\EmbedController`).
- **Template**: `embed.twig` does **not** extend `layout.twig`. It renders only the widget markup (wrapper + toolbar + chat body + modal partial).
- **Integration script**: `public/js/embed.js` fetches `/embed` HTML, injects it into a container, loads JS/CSS assets, and handles SSO token exchange (`POST /auth/embed/exchange`).
- **Teardown**: `window.destroyClaireEmbed()` removes the widget, clears event listeners, and closes the SSE stream.
- **Test page**: `public/embed.html`.

#### Embed Toolbar

Instead of the full sidebar, the embed mode uses a horizontal toolbar (`partials/embed_toolbar.twig`):

- **Left**: avatar + brain name (clickable to expand/collapse the widget).
- **Right**: icon buttons/dropdowns — Nouvelle conversation, Historique, Fichiers, Documents RAG, Préférences, Compte.
- **Key IDs preserved**: `claire-history-toggle`, `claire-files-toggle`, `claire-rag-toggle`, `claire-brain-selector`, etc. so existing JS selectors work unchanged.

#### Collapsed State

The widget defaults to `.is-collapsed`:
- Reduced to a circle (`border-radius: 50%`) whose size is controlled by CSS variables.
- Only the avatar is visible.
- Clicking the toolbar (or avatar) toggles the expanded state (400×600px chat panel).

```css
:root {
    --claire-embed-collapsed-size: 64px;   /* widget diameter */
    --claire-embed-collapsed-avatar: 56px; /* avatar size inside the circle */
}
```

### Shared Partials

Both modes reuse the same partials to avoid duplication:

| Partial | Purpose |
|---------|---------|
| `partials/chat_body.twig` | `<main class="claire-chat-body">` (messages stream) + `<footer class="claire-chat-input">` (textarea + send form). |
| `partials/options_content.twig` | Options panel sections: Conversations, Données, Préférences, Compte. Used by the normal sidebar. |
| `partials/modal_dialog.twig` | Global modal backdrop + container, action indicator spinner, and bottom tooltip banner. Included by both `layout.twig` and `embed.twig`. |
| `partials/embed_toolbar.twig` | Horizontal toolbar specific to embed mode. |

### Architectural Rules

- **No layout inheritance for embed**: `embed.twig` is a standalone fragment so the JS can inject raw HTML without pulling in `<html>` or `<body>` tags.
- **No duplicate IDs**: `embed.twig` empties `{% block sidebar %}` from `layout.twig` (when used indirectly) so the options panel IDs never collide with the toolbar IDs.
- **No layout-mode toggle in embed**: The "Mode largeur" option is intentionally omitted from the embed toolbar because the widget has its own fixed sizing logic.

## Build / Lint / Test Commands

```bash
# Development server
composer start                    # php -S localhost:8080 -t public public/index.php

# Code quality - Rector (PHP 8.4 modernization)
composer rector-check             # Dry-run to see proposed changes
composer rector-fix               # Apply Rector fixes

# Code quality - PHP Insights
composer insights-check           # Run quality analysis
composer insights-fix             # Auto-fix style issues

# Pre-commit (runs all checks)
composer pre-commit               # Line endings + insights-fix + rector-fix

# Testing
vendor/bin/phpunit                # Run all tests
vendor/bin/phpunit test/Unit/Services/SettingsTest.php    # Run single test file
vendor/bin/phpunit --filter testGetReturnsValueForValidKey  # Run single test method

# Database migrations
./console migrations:migrate      # Apply Doctrine migrations
./console migrations:diff         # Generate migration from entities
./console migrations:generate     # Create empty migration
./console migrations:status       # Show migration status

# Cache management
./console cache:clear             # Clear container/route cache
./console cache:init              # Initialize/regenerate cache
./console generate:proxies        # Generate Doctrine proxies

# Telegram bot
./console telegram:webhook --set              # Set webhook URL (uses BASE_URL)
./console telegram:webhook --info             # Show webhook status
./console telegram:webhook --delete           # Remove webhook
./console telegram:set-commands               # Set bot commands
./console telegram:menu-button --set          # Set Mini-App menu button (uses BASE_URL)
./console telegram:menu-button --info         # Show current menu button
./console telegram:menu-button --delete       # Reset menu button to default

# Queue worker
./console queue:work                          # Process queue jobs

# Docker Compose (production)
docker compose up -d                          # Start the stack
docker compose logs -f claire                 # View logs
docker compose exec claire ./console migrations:migrate  # Run migrations
docker compose exec claire ./console cache:clear         # Clear cache
```

## Code Style Guidelines

### PHP Standards
- **PHP Version**: 8.5+ with strict typing (`declare(strict_types=1);`)
- **Line Length**: 80 chars soft limit, 120 chars absolute limit (comments excluded)
- **File Ending**: Unix line endings (LF) - enforced by pre-commit
- **Quality Gates**: min-quality 90%, min-architecture 85%, min-style 96%

### Naming Conventions
- **Classes**: PascalCase, `final readonly` where possible (e.g., `final readonly class HomeController`)
- **Methods/Properties**: camelCase (e.g., `getFirstName()`, `$firstName`)
- **Database Columns**: snake_case (e.g., `first_name`, `created_at`)
- **Constants**: UPPER_SNAKE_CASE in Brain classes (e.g., `NAME`, `DESCRIPTION`, `AVATAR`)
- **Namespaces**: `App\` prefix, PSR-4 autoloading from `src/`

### Imports & Formatting
```php
<?php

declare(strict_types=1);

namespace App\Controller;

// 1. Native PHP imports (alphabetical)
use InvalidArgumentException;
use RuntimeException;

// 2. Vendor imports (alphabetical)
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Views\Twig;

// 3. App imports (alphabetical)
use App\Brain\BrainRegistry;
use App\Entity\ChatHistory;
```

### Type Declarations
- Always use explicit return types
- Use union types where appropriate (`string|null`)
- Leverage PHP 8.4 features: constructor property promotion, match expressions, named arguments
- Use `readonly` properties in readonly classes
- Document complex array shapes with PHPDoc:
  ```php
  /** @return array<int, array{slug:string, name:string}> */
  ```

### Error Handling
- Use specific exceptions: `InvalidArgumentException`, `RuntimeException`, `JsonException`
- Always use `JSON_THROW_ON_ERROR` flag with json_encode/decode
- Catch exceptions with variable when needed: `catch (JsonException $e)`
- Use non-capturing catches when variable unused: `catch (InvalidArgumentException)`

### Doctrine ORM
- Use PHP 8 attributes for entity mapping (#[ORM\Entity], #[ORM\Column])
- Specify column types explicitly: `type: 'string'`, `type: 'blob'`, `nullable: true`
- Use snake_case for database column names
- Repository classes in `App\Repository\` namespace

### Architecture Patterns
- **Controllers**: Handle HTTP request/response, delegate to services
- **Services**: Business logic in `App\Services\`
- **Entities**: Doctrine entities in `App\Entity\`
- **Repositories**: Database access, extend `Doctrine\ORM\EntityRepository`
- **Brain/Avatar Pattern**: AI agents implement `BrainAvatar` interface with constants NAME, DESCRIPTION, AVATAR, CSS
- **Middleware**: PSR-15 middleware in `App\Middleware\`
- **Session Management**: JWT-based stateless sessions via `SessionManager`
- **Telegram Sessions**: Dedicated `TelegramSession` entity for bot user persistence
- **Queue System**: Redis-backed job queue in `App\Queue\` with `QueueWorker`, `QueueMessage`, and job classes
- **Observability**: OpenTelemetry integration in `App\Brain\Observability\` for metrics, traces, and structured events
- **Embed Integration**: See "Display Modes" section above. Server-rendered embed entry (`embed.twig`) loaded by client-side bootstrap (`public/js/embed.js`) with token exchange and managed teardown.

### Key Project Conventions
- Use `Env::get()` from `App\Services\Env` for environment variables
- Use `Settings::get('key.subkey')` for configuration access
- Session handling via JWT-based stateless session (`App\Session\SessionManager`)
- Container injection via PHP-DI (autowiring enabled)
- Twig templates in `tmpl/` directory
- Public assets served from `public/`
- Embed integrations should use `window.claireEmbed({ baseUrl, target, token|ssoToken })` and avoid custom direct mounting logic

### Testing
- PHPUnit 12.5+ with tests in `test/Unit/`
- Test classes: `final class FooTest extends TestCase`
- Test methods: `public function testDescription(): void`
- Arrange-Act-Assert pattern preferred
- Use `assertSame()` for exact equality

### Prohibited Patterns
- No `empty()` function (removed by PHP Insights config)
- Unused parameters should be handled (config allows them but avoid)
- No trailing whitespace, use LF line endings only
