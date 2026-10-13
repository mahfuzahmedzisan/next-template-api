<?php

namespace App\Support;

use App\Enums\MediaKind;

final class AllowedMedia
{
    /**
     * @var array<string, array{kind: MediaKind, mimes: list<string>}>
     */
    public const TYPES = [
        'jpg' => ['kind' => MediaKind::Image, 'mimes' => ['image/jpeg', 'image/jpg', 'image/pjpeg']],
        'jpeg' => ['kind' => MediaKind::Image, 'mimes' => ['image/jpeg', 'image/jpg', 'image/pjpeg']],
        'png' => ['kind' => MediaKind::Image, 'mimes' => ['image/png']],
        'webp' => ['kind' => MediaKind::Image, 'mimes' => ['image/webp']],
        'gif' => ['kind' => MediaKind::Image, 'mimes' => ['image/gif']],
        'svg' => ['kind' => MediaKind::Image, 'mimes' => ['image/svg+xml', 'image/svg', 'text/xml', 'application/xml', 'text/plain', 'text/html']],
        'mp4' => ['kind' => MediaKind::Video, 'mimes' => ['video/mp4']],
        'mov' => ['kind' => MediaKind::Video, 'mimes' => ['video/quicktime']],
        'webm' => ['kind' => MediaKind::Video, 'mimes' => ['video/webm']],
        'mkv' => ['kind' => MediaKind::Video, 'mimes' => ['video/x-matroska']],
        'mp3' => ['kind' => MediaKind::Audio, 'mimes' => ['audio/mpeg', 'audio/mp3']],
        'wav' => ['kind' => MediaKind::Audio, 'mimes' => ['audio/wav', 'audio/x-wav', 'audio/wave']],
        'm4a' => ['kind' => MediaKind::Audio, 'mimes' => ['audio/mp4', 'audio/x-m4a', 'audio/m4a']],
        'ogg' => ['kind' => MediaKind::Audio, 'mimes' => ['audio/ogg', 'application/ogg']],
        'pdf' => ['kind' => MediaKind::Pdf, 'mimes' => ['application/pdf']],
        'txt' => ['kind' => MediaKind::Document, 'mimes' => ['text/plain']],
        'csv' => ['kind' => MediaKind::Document, 'mimes' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel']],
        'doc' => ['kind' => MediaKind::Document, 'mimes' => ['application/msword']],
        'docx' => ['kind' => MediaKind::Document, 'mimes' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip']],
        'xls' => ['kind' => MediaKind::Document, 'mimes' => ['application/vnd.ms-excel']],
        'xlsx' => ['kind' => MediaKind::Document, 'mimes' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip']],
        'ppt' => ['kind' => MediaKind::Document, 'mimes' => ['application/vnd.ms-powerpoint']],
        'pptx' => ['kind' => MediaKind::Document, 'mimes' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip']],
    ];

    /** @var list<string> */
    private const BLOCKED_SEGMENTS = [
        'php', 'phtml', 'phar', 'js', 'mjs', 'html', 'htm', 'svg', 'exe', 'dll', 'bat', 'cmd', 'sh',
        'bash', 'ps1', 'zip', 'rar', '7z', 'cgi', 'htaccess',
    ];

    public static function extension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    public static function kindFor(string $filename): ?MediaKind
    {
        $extension = self::extension($filename);

        return self::TYPES[$extension]['kind'] ?? null;
    }

    public static function rejectionReason(string $filename, string $mime, int $size): ?string
    {
        if (self::hasBlockedSegment($filename)) {
            return 'This filename is not allowed.';
        }

        $extension = self::extension($filename);
        $type = self::TYPES[$extension] ?? null;

        if ($type === null) {
            return 'This file type is not allowed.';
        }

        if (! in_array(strtolower($mime), $type['mimes'], true)) {
            return 'This file type is not allowed.';
        }

        if ($size < 1) {
            return 'The file is empty.';
        }

        if ($size > self::maxBytes($type['kind'])) {
            return 'This file is larger than the upload limit.';
        }

        return null;
    }

    public static function maxBytes(MediaKind $kind): int
    {
        $max = (int) config('media-hls.max_bytes');
        $perKind = (int) config('media-hls.max_bytes_by_kind.'.$kind->value, $max);

        return $perKind > 0 ? min($max, $perKind) : $max;
    }

    private static function hasBlockedSegment(string $filename): bool
    {
        $parts = array_values(array_filter(explode('.', strtolower($filename)), fn (string $part): bool => $part !== ''));

        if (count($parts) < 2) {
            return false;
        }

        array_pop($parts);

        foreach ($parts as $part) {
            if (in_array($part, self::BLOCKED_SEGMENTS, true)) {
                return true;
            }
        }

        return false;
    }
}
