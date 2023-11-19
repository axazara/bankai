<?php

namespace AxaZara\Bankai;

class Slack
{
    public static function send(
        string $message,
        string $webhookUrl
    ): void {
        $data = [
            'text' => $message,
        ];

        $options = [
            'http' => [
                'header'  => "Content-type: application/json\r\n",
                'method'  => 'POST',
                'content' => json_encode($data, JSON_THROW_ON_ERROR),
            ],
        ];

        $context = stream_context_create($options);

        file_get_contents(
            filename: $webhookUrl,
            use_include_path: false,
            context: $context
        );
    }
}
