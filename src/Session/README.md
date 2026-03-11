# JWT Session System

## Overview

A stateless session management system using JSON Web Tokens (JWT) stored in HTTP cookies. Session data is serialized into a signed JWT, eliminating the need for server-side session storage while maintaining security through cryptographic signing.

## Architecture

### Interfaces

| Interface | Purpose |
|-----------|---------|
| `SessionInterface` | Core session data operations (get, set, has, delete, clear) |
| `SessionManagerInterface` | Session lifecycle management (start, destroy, regenerate ID) |
| `FlashInterface` | Flash message handling (add, get, clear messages) |

### Classes

| Class | Role |
|-------|------|
| `ArraySession` | Singleton implementation combining `SessionInterface` and `SessionManagerInterface`. Stores data in memory with JWT serialization support. Use `ArraySession::getInstance()` |
| `ArrayFlash` | Flash message implementation with read-once behavior and JWT serialization |
| `JwtSessionMiddleware` | PSR-15 middleware that reads JWT from cookies, populates session, and writes back at request end |
| `SessionException` | Exception thrown for session errors |

## How It Works

### JWT Storage in Cookie

Session data is encoded as a JWT and stored in an HTTP cookie:

```
Cookie: claire_chatbot=eyJhbGciOiJIUzI1NiIs...
```

The JWT payload contains:
- `sub` - Session ID (JWT subject claim, set via `->relatedTo()`)
- `data` - Serialized session data including flash messages
- `jti` - JWT ID (unique token identifier, UUID v4)
- Standard JWT claims (iat, nbf, exp)

### Auto-Generation of Session ID

When `start()` is called without an existing session ID, a new cryptographically secure 32-character hex ID is generated:

```php
$this->sessionId = bin2hex(random_bytes(16)); // 32 hex chars
```

### Data Persistence Between Requests

1. **Request**: Middleware reads JWT from cookie, validates signature, extracts session data
2. **Application**: Controller reads/writes session data in memory
3. **Response**: Middleware encodes session data to JWT, sets cookie

### Session ID Persistence Fix

The `setId(string $id)` method preserves the session ID when loading from an existing JWT:

```php
// In JwtSessionMiddleware::decodeAndPopulateSession()
$sessionId = $token->claims()->get('sub');
if (! in_array($sessionId, [null, '', '0'], true)) {
    $this->session->setId($sessionId);  // Preserve existing ID
}
```

Without this fix, each request would generate a new session ID, breaking session continuity.

### ArraySession Singleton Pattern

`ArraySession` is implemented as a singleton to ensure a single session instance throughout the request lifecycle:

```php
// Get the singleton instance
$session = ArraySession::getInstance();
```

The constructor is private - instantiation via `new ArraySession()` is not allowed.

### JWT Serialization Methods

Session data is serialized to/from arrays for JWT storage:

```php
// Export session data as array for JWT encoding
$data = $session->getStorageAsArray();  // Returns null if session not started or empty

// Import session data from array (when decoding JWT)
$session->setStorageFromArray($data);
```

### Cookie Optimization (Smart Refresh)

The middleware uses an intelligent algorithm to minimize unnecessary cookie writes:

**Cookie is sent only when:**

1. **Session data has changed** (middleware compares current data with original)
2. **Token is more than 50% through its lifetime** (proactive refresh)

**Why this matters:**

- Reduces bandwidth (no Set-Cookie header on every request)
- Improves performance (no cryptographic operations when not needed)
- Maintains session continuity (refresh before expiration)

### Cookie Deletion (Empty Session)

When session data is empty (`null` or `[]`), the middleware automatically deletes the cookie instead of writing a new JWT:

```
// If session is empty, cookie is deleted
Set-Cookie: claire_chatbot=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Lax

// If session has data, JWT cookie is written normally
Set-Cookie: claire_chatbot=eyJ...; Path=/; Max-Age=7200; SameSite=Lax
```

This ensures clean session termination when all data is cleared.


**How it works:**

```php
// Middleware tracks original session data
// Compare original data with current data
$currentData !== $originalData;  // true if changed

// Middleware checks token age
$elapsed = $now - $issuedAt;
$halfLifetime = ($expiresAt - $issuedAt) / 2;
$shouldRefresh = $elapsed > $halfLifetime;
```

**Example:** With a 2-hour (7200s) lifetime:
- Request at 0s: Cookie written (new session)
- Request at 30min: No cookie (data unchanged, < 50% elapsed)
- Request at 1h05min: Cookie written (> 50% elapsed, proactive refresh)
- Request at 1h30min: No cookie (refreshed recently)

## Configuration

### Session Settings (`config/settings/session.php`)

```php
return [
    // JWT signing (REQUIRED)
    'jwt_secret' => _env('JWT_SECRET', bin2hex(random_bytes(32))),
    'jwt_algorithm' => _env('JWT_ALGORITHM', 'HS256'),

    // Cookie settings
    'name' => 'claire_chatbot',     // Cookie name
    'lifetime' => 7200,             // Expiration in seconds (2 hours)
    'domain' => null,               // Cookie domain (null = current)
    'secure' => false,              // HTTPS only
    'httponly' => false,             // JavaScript can access
];
```

### Environment Variables

```bash
# Required: Secret for JWT signing (min 256 bits recommended)
JWT_SECRET=your-super-secret-key-here-at-least-32-characters

# Optional: Algorithm (default: HS256)
JWT_ALGORITHM=HS256
```

**Important**: The JWT secret is auto-generated if not set, but this invalidates all sessions on deployment. Always set `JWT_SECRET` explicitly in production.

## Public Routes Configuration

Routes that bypass authentication (`config/settings/security.php`):

```php
return [
    'public_routes' => [
        '/health',           // Health check endpoint
        '/logout',           // Logout page (clears session)
        '/auth',             // Authentication callback
        '/webhook/telegram', // Telegram webhook (uses token auth)
    ],
];
```

Used by `AuthMiddleware` to skip authentication for specific paths. `JwtSessionMiddleware` processes session data for all routes.

## Usage Examples

### Getting/Setting Session Data

```php
use App\Session\ArraySession;

final readonly class ChatController
{
    private ArraySession $session;

    public function __construct()
    {
        $this->session = ArraySession::getInstance();
    }

    public function index(): void
    {
        // Store user preference
        $this->session->set('preferred_model', 'gpt-4');

        // Retrieve with default
        $model = $this->session->get('preferred_model', 'default-model');

        // Check existence
        if ($this->session->has('user_id')) {
            // ...
        }

        // Delete key
        $this->session->delete('temporary_data');

        // Clear all
        $this->session->clear();
    }
}
```

### Using Flash Messages

```php
// In controller processing form
$this->session->getFlash()->add('success', 'Message sent!');
$this->session->getFlash()->add('error', 'Connection failed');

// In template (displayed once, then auto-cleared)
{% for message in flash.get('success') %}
    <div class="alert alert-success">{{ message }}</div>
{% endfor %}
```

Flash messages implement read-once behavior - they are cleared after being retrieved via `get()` or `all()`.

### Checking Authentication

```php
use App\Middleware\AuthMiddleware;

// AuthMiddleware sets this on successful authentication
$this->session->set(AuthMiddleware::AUTHENTICATED, true);
$this->session->set(AuthMiddleware::USER_ID, $user->getId());

// Check if authenticated
$isLoggedIn = $this->session->get(AuthMiddleware::AUTHENTICATED, false);
```

### Session Lifecycle Management

```php
// Start session (usually handled by middleware)
$this->session->start();

// Regenerate ID (after privilege escalation)
$this->session->regenerateId();

// Destroy session (logout)
$this->session->destroy();
```

## Security Considerations

### JWT Signing Prevents Tampering

All session data is cryptographically signed using HMAC-SHA256. Any modification to the JWT invalidates the signature, causing the session to be rejected and a new one created.

### HttpOnly Cookies (Disabled by Default)

By default, cookies do NOT use the `HttpOnly` flag, allowing JavaScript access:

```
Set-Cookie: claire_chatbot=eyJ...; SameSite=Lax
```

This allows client-side JavaScript to read the session token if needed. To enable HttpOnly protection against XSS attacks:

```php
'httponly' => true,  // Prevent JavaScript access
```

### Secure Flag for HTTPS

Enable `secure` in production to ensure cookies are only sent over HTTPS:

```php
'secure' => true,  // HTTPS only
```

This prevents session hijacking via man-in-the-middle attacks on insecure connections.

### SameSite Protection

Cookies use `SameSite=Lax` to prevent CSRF attacks while allowing normal navigation:

- `Lax`: Cookie sent on same-site requests and top-level cross-site GET requests
- Blocks POST/DELETE/PUT requests from other origins

### Session Expiration

JWT tokens have built-in expiration (`exp` claim). The middleware validates this on each request. Default lifetime is 2 hours.

### Secret Key Protection

The JWT secret must be:
- At least 256 bits (32 characters) for HS256
- Kept confidential (never committed to version control)
- Rotated periodically in production
- Same across all server instances (for load balancing)
