<?php

namespace AxaZara\Bankai;

class Slack
{
    /**
     * Post a message to a Slack Incoming Webhook.
     *
     * Does nothing when no webhook URL is provided, so callers can pass an
     * optional/disabled webhook without guarding it themselves.
     */
    public static function send(string $message, ?string $webhookUrl): void
    {
        if (empty($webhookUrl)) {
            return;
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => json_encode(['text' => $message], JSON_THROW_ON_ERROR),
            ],
        ]);

        file_get_contents(
            filename: $webhookUrl,
            use_include_path: false,
            context: $context
        );
    }
}
