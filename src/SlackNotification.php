<?php

namespace AxaZara\Bankai;

class SlackNotification
{
    public static function send($message, $webhookUrl): void
    {
        if (empty($webhookUrl)) {
            return;
        }

        file_get_contents(
            filename: $webhookUrl,
            use_include_path: false,
            context: stream_context_create(
                [
                    'http' => [
                        'method'  => 'POST',
                        'header'  => 'Content-Type: application/json',
                        'content' => json_encode(['text' => $message], JSON_THROW_ON_ERROR),
                    ],
                ]
            )
        );
    }
}
