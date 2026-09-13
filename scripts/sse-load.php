<?php

declare(strict_types=1);

use App\Test\Fixtures\Sse\SseLoad;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/test/fixtures/sse/SseLoad.php';

$options = getopt('', ['clients:', 'seconds:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php scripts/sse-load.php [--clients=64] [--seconds=3]\n"
        . "Clients: 4..512; seconds per idle/active plateau: 1..30. Artifacts: /tmp/kilo/sse-load-*.\n"
        . "Starts real bin/sse, a Slim fixture on free port 8082, and disposable local Redis.\n"
        . "Uses redis-server if installed, otherwise Docker's existing redis:7-alpine image.\n"
        . "Does not pull images, change the production stack, or validate proxies/browsers.\n");
    exit(0);
}
$clients = filter_var(
    $options['clients'] ?? 64,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 4, 'max_range' => 512]],
);
$seconds = filter_var(
    $options['seconds'] ?? 3,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 30]],
);
if ($clients === false || $seconds === false) {
    fwrite(STDERR, "Invalid --clients or --seconds; see --help\n");
    exit(2);
}
exit(new SseLoad($clients, $seconds)->run());
