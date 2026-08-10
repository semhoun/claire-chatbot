<?php

declare(strict_types=1);

namespace App\Enums;

enum TelegramAction: string
{
    case TEXT = 'typing';
    case GENERATE = 'record_video';
    case PHOTO = 'upload_photo';
    case DOCUMENT = 'upload_document';
}
