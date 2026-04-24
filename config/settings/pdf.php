<?php

declare(strict_types=1);

use App\Services\Env;
use App\Services\Settings;

return [
    'enabled' => (bool) Env::get('PDF_ENABLED', true),
    'defaultFormat' => Env::get('PDF_DEFAULT_FORMAT', 'html'),
    'defaultPageSize' => Env::get('PDF_DEFAULT_PAGE_SIZE', 'A4'),
    'tempDir' => Env::get('PDF_TEMP_DIR', Settings::getAppRoot() . '/var/tmp'),
];
