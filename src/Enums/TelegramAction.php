<?php

declare(strict_types=1);

namespace App\Enums;

enum TelegramAction: string
{
    case TEXT = 'typing';
    case GENERATE = 'record_video';
    case PHOTO = 'upload_photo';
    case VIDEO = 'upload_video';
    case VOICE = 'upload_voice';
    case DOCUMENT = 'upload_document';
    case STICKER = 'choose_sticker';
    case LOCATION = 'find_location';
    case VIDEO_NOTE = 'upload_video_note';
}
