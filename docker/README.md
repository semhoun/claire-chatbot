# Claire Chatbot

[![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-777bb4?logo=php&logoColor=white)](https://php.net) [![Slim](https://img.shields.io/badge/Slim-4.x-4B4B4B)](https://www.slimframework.com/) [![License](https://img.shields.io/badge/License-MIT-blue)](LICENSE)

**Claire** is a lightweight AI agent chatbot built with PHP 8.4, Slim 4, and Twig. It provides a web interface and API for interacting with LLMs through an OpenAI-compatible interface.

## Features

- **Web Chat Interface** — Clean, responsive UI with message history
- **API Endpoint** — `POST /brain/chat` for programmatic access
- **Multiple AI Personalities** — Switch between different "brains" (Claire, Einstein, Flashy, or custom YAML agents)
- **File Management** — Upload and analyze documents (RAG support with embeddings)
- **Image Generation** — Integration with ComfyUI for AI-generated images
- **Telegram Bot** — Use Claire as a Telegram bot (webhook or polling)
- **OpenID Connect SSO** — Authentication via OIDC providers
- **OpenTelemetry** — Built-in observability (traces, metrics, logs)
- **Multi-database** — SQLite (default), MySQL/MariaDB, or PostgreSQL

## Quick Start

```bash
docker run -d -p 8080:80 \
  -e OPENAPI_KEY=your-api-key \
  -e SESSION_JWT_SECRET=$(openssl rand -hex 32) \
  -e OTEL_LOGS_EXPORTER=console \
  -e OTEL_LOGS_PROCESSOR=simple \
  -v claire_data:/data \
  --name claire \
  semhoun/claire-chatbot:latest
```

Then open http://localhost:8080

## Required Environment Variables

| Variable | Description |
|----------|-------------|
| `OPENAPI_KEY` | Your OpenAI-compatible API key |
| `SESSION_JWT_SECRET` | JWT signing secret (generate with `openssl rand -hex 32`) |
| `OTEL_LOGS_EXPORTER` | Set to `console` for development, `otlp` for production |
| `OTEL_LOGS_PROCESSOR` | Set to `simple` for immediate output, `batch` for production |

## Optional Environment Variables

### LLM Configuration
| Variable | Default | Description |
|----------|---------|-------------|
| `OPENAPI_URL` | `https://api.openai.com/v1` | API base URL |
| `OPENAPI_MODEL` | — | Default model (e.g., `gpt-4o-mini`) |
| `OPENAPI_MODEL_SUMMARY` | — | Model for summarization tasks |
| `OPENAPI_MODEL_EMBED` | — | Embedding model for RAG (omit to disable RAG) |
| `SEARXNG_URL` | — | SearXNG instance for web search |

### Database (SQLite is default)
| Variable | Description |
|----------|-------------|
| `DATABASE_KIND` | `sqlite`, `mysql`, or `postgres` |
| `DATABASE_HOST` | Database host (MySQL/PostgreSQL) |
| `DATABASE_PORT` | Database port |
| `DATABASE_NAME` | Database name |
| `DATABASE_USER` | Database user |
| `DATABASE_PASSWORD` | Database password |

### Authentication (SSO)
| Variable | Description |
|----------|-------------|
| `OPENID_WELLKNOWN_URL` | OIDC discovery URL |
| `OPENID_CLIENT_ID` | Client ID |
| `OPENID_CLIENT_SECRET` | Client secret |
| `OPENID_REDIRECT_URI_BASE` | Public URL base (e.g., `https://claire.example.com`) |

### Telegram Bot
| Variable | Description |
|----------|-------------|
| `TELEGRAM_BOT_TOKEN` | Token from @BotFather |
| `TELEGRAM_WEBHOOK_SECRET` | Secret token for webhook security |

### ComfyUI (Image Generation)
| Variable | Description |
|----------|-------------|
| `COMFYUI_ENABLED` | Set to `true` to enable |
| `COMFYUI_URL` | ComfyUI instance URL |
| `COMFYUI_WORKFLOW` | JSON workflow with `{{PROMPT}}` placeholder |
| `COMFYUI_PROMPT_STYLE` | `sdxl` or `flux` |

### General
| Variable | Default | Description |
|----------|---------|-------------|
| `DEBUG_MODE` | `false` | Enable debug logging |
| `DISABLE_TRACY_BAR` | `true` | Disable Tracy debug bar |
| `DATA_PATH` | `/data` | Data persistence path |
| `SERVER_NAME` | `www.docker.test` | Server name |
| `SERVER_ADMIN` | `webmaster@docker.test` | Admin email |

## Docker Compose Example

```yaml
services:
  claire:
    image: semhoun/claire-chatbot:latest
    ports:
      - "8080:80"
    volumes:
      - claire_data:/data
    environment:
      # Required
      OPENAPI_KEY: ${OPENAPI_KEY}
      SESSION_JWT_SECRET: ${SESSION_JWT_SECRET}
      
      # OpenTelemetry (required)
      OTEL_PHP_AUTOLOAD_ENABLED: "true"
      OTEL_SERVICE_NAME: claire
      OTEL_LOGS_EXPORTER: console
      OTEL_LOGS_PROCESSOR: simple
      OTEL_TRACES_EXPORTER: none
      OTEL_METRICS_EXPORTER: none
      
      # LLM
      OPENAPI_URL: https://api.openai.com/v1
      OPENAPI_MODEL: gpt-4o-mini
      
      # Database (SQLite default)
      DATABASE_KIND: sqlite
      
      # Optional: SSO
      # OPENID_WELLKNOWN_URL: https://auth.example.com/.well-known/openid-configuration
      # OPENID_CLIENT_ID: ${OPENID_CLIENT_ID}
      # OPENID_CLIENT_SECRET: ${OPENID_CLIENT_SECRET}
      # OPENID_REDIRECT_URI_BASE: https://claire.example.com
      
      # Optional: Telegram
      # TELEGRAM_BOT_TOKEN: ${TELEGRAM_BOT_TOKEN}
      
      # Optional: ComfyUI
      # COMFYUI_ENABLED: "true"
      # COMFYUI_URL: http://comfyui:8188
    healthcheck:
      test: ["CMD", "curl", "--fail", "http://localhost/health"]
      interval: 15s
      timeout: 5s
      retries: 3
      start_period: 60s

volumes:
  claire_data:
```

## Volumes

| Path | Description |
|------|-------------|
| `/data` | Persistent data (SQLite database, uploaded files, cache, generated images) |

## Ports

| Port | Description |
|------|-------------|
| `80` | HTTP web interface and API |

## Health Check

The container includes a health check at `GET /health` returning:
```json
{
  "version": "1.0.0",
  "date": "2025-01-01T12:34:56+00:00"
}
```

## First Run

On first startup, run migrations to initialize the database:

```bash
docker exec claire ./console migrations:migrate
```

## Custom Agents

Create custom AI personalities by mounting YAML files to `/www/addons/agents/`:

```yaml
name: "My Custom Agent"
description: "A specialized assistant"
avatar: "data:image/png;base64,..."
css_inline: |
  :root { --accent: #FF6B6B; }
welcomes:
  - "Hello! How can I help?"
  - "Welcome! What would you like to discuss?"
instruction: |
  You are a helpful assistant specialized in...
```

## API Usage

### Chat Endpoint

```bash
curl -X POST http://localhost:8080/brain/chat \
  -H "Content-Type: application/json" \
  -d '{"message": "Hello Claire!", "mode": "chat"}'
```

### File Upload

```bash
curl -X POST http://localhost:8080/files/upload \
  -F "files[]=@document.pdf"
```

## Image Information

- **Base:** Debian Trixie Slim
- **PHP:** 8.4 with FPM
- **Web Server:** Apache 2 with mod_proxy_fcgi
- **Process Manager:** Supervisor
- **Extensions:** PDO (SQLite, MySQL, PostgreSQL), GD, Imagick, Redis, OpenTelemetry
- **Cron:** fcron for scheduled tasks

## Source & Documentation

- **Source:** https://github.com/semhoun/claire-chatbot
- **Documentation:** See README.md in the repository for full details

## License

MIT License — See [LICENSE](https://github.com/semhoun/claire-chatbot/blob/main/LICENSE) for details.
