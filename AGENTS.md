# AGENTS.md — Claire Chatbot Project Guidelines

## Project Overview

Claire is a PHP 8.5+ AI agent chatbot built with Slim 4, Doctrine ORM, Neuron AI, Vue 3, TypeScript, and Vite. It provides a web interface and API for interacting with LLMs via an OpenAI-compatible interface.

The project runs with PHP 8.5, Nginx, and Redis via Docker Compose. The deployment template in `docker/compose.yml` defaults to SQLite, with optional MariaDB; the root `compose.yml` is environment-specific development configuration using MySQL/MariaDB and external networks.

## Display Modes

The Vue chat has two distinct display modes sharing the same core components. The Telegram Mini-App (`/telegram/webapp`) is a separate Twig/JavaScript interface in `tmpl/telegram/webapp.twig`.

### Normal Mode (HTML Shell + Vue)

Full-page chat with a collapsible sidebar options panel.

- **Layout/bootstrap**: `App\Renderer\VueShell` renders `frontend/shell.html` and resolves the normal CSS bundle through the Vite manifest.
- **Application**: `frontend/main.ts` mounts `frontend/components/PublicApp.vue`, which handles the authentication callback, loads the JSON bootstrap, and renders `ClaireApp.vue`. `HomeController` returns the HTML shell or JSON bootstrap according to the request's `Accept` header.
- **Options panel**: Rendered and managed by Vue as a floating panel.

### Embed / Widget Mode

Floating widget injected into third-party pages via `window.claireEmbed(...)`.

- **Entry point**: `GET /embed` (`App\Controller\EmbedController`).
- **Bootstrap**: `/embed` returns standalone JSON configuration from `FrontendConfigFactory`.
- **Integration script**: `public/js/embed.js` is an autonomous IIFE bundle built from `frontend/embed.ts`.
- **Component**: `<claire-chat-widget>` is a Vue Custom Element rendered in Shadow DOM.
- **Teardown**: `window.destroyClaireEmbed()` removes the custom element; Vue closes its SSE stream and timers during unmount.
- **Test page**: `public/embed.html`.

The embed toolbar and the normal sidebar are variants of the same Vue component. The widget remains collapsed by default and expands to a nominal 400 x 600px panel, constrained by the viewport and safe areas.

#### Collapsed State

The widget defaults to `.is-collapsed`:
- Reduced to a circle using the internal `--claire-radius-pill` token, with its size controlled by CSS variables.
- Only the avatar is visible.
- Clicking the toolbar (or avatar) toggles the expanded chat panel.

These are internal layout tokens in the shared component styles, not overrides on the host page:

```css
.claire-app {
    --claire-embed-collapsed-size: 64px;   /* widget diameter */
    --claire-embed-collapsed-avatar: 56px; /* avatar size inside the circle */
}
```

### Chat Rendering and Themes

`ChatDataRenderer` prepares structured message/file/tool data for HTTP/SSE. Vue renders Markdown, messages, tools and browser interactions; there is no `ChatHtmlRenderer` in the current chat pipeline.

Shared CSS lives in `frontend/styles/index.css` and its responsibility-based imports. Vite bundles it for normal mode and embeds it in the widget's Shadow DOM. `ThemeRegistry` resolves local presets and filtered overrides; Vue applies tokens and variant attributes on `.claire-app`, not on the host page. Agent themes contain trusted CSS values, never stylesheets or selectors.

The official internal theme API is [`config/themes/contract.json`](config/themes/contract.json). The [existing agent-creation guide](README.md#cerveaux-personnalisés-brainregistry) documents the scalar/object schema, all 71 public tokens, variants, fallback rules, local-agent deployment and cache/worker reload requirements. Keep that single enumeration synchronized with the contract rather than copying it here. Legacy YAML `css` / `css_inline` are ignored; there is no dynamic agent stylesheet loading.

### Architectural Rules

- **Standalone embed response**: `/embed` returns only its bootstrap JSON, not a full page.
- **No host-page globals**: embed code must not patch `window.fetch` or write configuration on `document.body`. The bootstrap may resolve the host-page `target` and insert its container; internal interactions must stay within the component root.
- **Shadow DOM isolation**: embed styles belong to the custom element bundle.
- **No htmx**: normal/embed chat interactions live in TypeScript/Vue, and the server provides structured data rather than chat HTML fragments. The separate Telegram Mini-App uses Twig and JavaScript.

## Build / Lint / Test Commands

```bash
# Frontend
npm install                       # Install Vue/TypeScript/Vite dependencies
npm run dev                       # Start Vite development server
npm run build                     # Type-check and build normal + embed bundles
npm test                          # Run Vitest frontend tests

# Code quality - Rector (PHP 8.4 modernization)
composer rector:check             # Dry-run to see proposed changes
composer rector:fix               # Apply Rector fixes

# Code quality - PHP Insights
composer insights:check           # Run quality analysis
composer insights:fix             # Auto-fix style issues

# Pre-commit (mutates files and permissions; does not run PHPUnit)
composer pre-commit               # Frontend build/tests, line endings, permissions, Rector/Insights fixes

# Testing
vendor/bin/phpunit                # Run all tests
vendor/bin/phpunit test/Unit/Services/SettingsTest.php    # Run single test file
vendor/bin/phpunit --filter testGetReturnsValueForValidKey  # Run single test method

# Database migrations
./console migrations:migrate      # Apply Doctrine migrations
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

# SSE daemon (separate from the Slim HTTP application)
./console sse:serve                           # Run the ReactPHP streaming server

# Docker Compose (root development configuration)
docker compose up -d                          # Start the stack
docker compose logs -f claire                 # View logs
docker compose exec claire ./console migrations:migrate  # Run migrations
docker compose exec claire ./console cache:clear         # Clear cache
```

For deployment, configure `docker/compose.yml` and use `docker compose -f docker/compose.yml ...` instead of the root configuration. The HTTP application, queue worker, and SSE daemon have separate responsibilities; Vite alone does not run the backend. There is no `composer start` script or registered `migrations:diff` command.

## Code Style Guidelines

### Browser Testing

- The agent can use Playwright with Chromium to inspect and test the live interface.
- Validate frontend changes at desktop and mobile viewport sizes when relevant.
- Store temporary screenshots and browser artifacts in `/tmp/kilo`, not in the repository.
- The public interface is available at `https://claire.dune.tf`; authentication may limit tests to the SSO screen.

### PHP Standards
- **PHP Version**: 8.5+ with strict typing (`declare(strict_types=1);`)
- **Line Length**: 80 chars soft limit, 120 chars absolute limit (comments excluded); project convention, not enforced by the currently disabled Insights line-length sniff
- **File Ending**: Unix line endings (LF); pre-commit normalizes PHP/CSS/JS/HTML/Twig files, but does not currently include TS/Vue files
- **Quality Gates**: min-quality 90%, min-architecture 80%, min-style 96%

### Naming Conventions
- **Classes**: PascalCase, `final readonly` where possible (e.g., `final readonly class HomeController`)
- **Methods/Properties**: camelCase (e.g., `getFirstName()`, `$firstName`)
- **Database Columns**: snake_case (e.g., `first_name`, `created_at`)
- **Constants**: UPPER_SNAKE_CASE in Brain classes (e.g., `NAME`, `DESCRIPTION`, `AVATAR`)
- **Namespaces**: `App\` prefix, PSR-4 autoloading from `src/`

### Imports & Formatting
The grouping below is a convention; the Insights alphabetical-import sniff is currently disabled. Keep imported names unique.

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

// 3. App imports (alphabetical)
use App\Brain\BrainRegistry;
use App\Entity\ChatHistory;
```

### Type Declarations
- Always use explicit return types
- Use union types where appropriate (`string|null`)
- Use modern PHP features, including constructor property promotion, match expressions, and named arguments; Rector currently targets PHP 8.4 modernization while the runtime requires PHP 8.5+
- Prefer readonly classes where appropriate; their instance properties are implicitly readonly
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
- **Brain/Avatar Pattern**: AI agents implement `BrainAvatar` with constants `NAME`, `DESCRIPTION`, `AVATAR`, `THEME`; the inherited theme is `cyberpunk`, Einstein uses `neon`.
- **Middleware**: PSR-15 middleware in `App\Middleware\`
- **Session Management**: `JwtSessionMiddleware` and `JwtTokenService` handle JWT sessions, with per-request state in `App\Services\Session\ArraySession` implementing `SessionManagerInterface`. `RememberSession` uses Redis for persistent remember sessions and revocation; the overall system is not fully stateless.
- **Telegram Sessions**: Dedicated `TelegramSession` entity for bot user persistence
- **Queue System**: Redis-backed infrastructure in `App\Services\Queue\`, with `QueueWorker` and `QueueMessage`; jobs live in `App\Job\` and are dispatched via `QueueDispatcherInterface`
- **SSE Transport**: `App\Sse\` runs a separate ReactPHP daemon serving `/brain/stream`, with an authenticated internal HTTP backend for stream lifecycle operations. Slim handles the HTTP API, queue workers execute jobs, and Vue consumes structured SSE events. Resource capabilities for streams/files are distinct from session JWTs.
- **Observability**: OpenTelemetry integration in `App\Brain\Observability\` for metrics, traces, and structured events
- **Embed Integration**: See "Display Modes" section above. Bootstrap JSON is loaded by `public/js/embed.js` with token exchange and managed teardown.

### Key Project Conventions
- Use `Env::get()` from `App\Services\Env` for environment variables
- Use `Settings::get('key.subkey')` for configuration access
- Use the request-scoped session abstraction in `App\Services\Session\`; preserve the distinction between session authentication, remember-session restoration, and resource capabilities
- Container injection via PHP-DI (autowiring enabled)
- Twig templates in `tmpl/`
- Public assets served from `public/`
- Embed integrations should use `window.claireEmbed({ baseUrl, target, token|ssoToken })` and avoid custom direct mounting logic

### Testing
- PHPUnit 12.5+ with tests in `test/Unit/`
- Test classes: `final class FooTest extends TestCase`
- Test methods: `public function testDescription(): void`
- Arrange-Act-Assert pattern preferred
- Use `assertSame()` for exact equality

### Prohibited Patterns
- Avoid `empty()` as a project convention; Insights does not enforce this because `DisallowEmptySniff` is disabled
- Unused parameters should be handled (config allows them but avoid)
- No trailing whitespace, use LF line endings only
