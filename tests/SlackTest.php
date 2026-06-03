<?php

declare(strict_types=1);

namespace Tests;

use AxaZara\Bankai\Slack;
use PHPUnit\Framework\TestCase as BaseTestCase;

class SlackTest extends BaseTestCase
{
    public function test_send_is_a_noop_when_the_webhook_is_null(): void
    {
        // No webhook configured: the call must return without attempting any request.
        Slack::send('deployment complete', null);

        $this->expectNotToPerformAssertions();
    }

    public function test_send_is_a_noop_when_the_webhook_is_empty(): void
    {
        Slack::send('deployment complete', '');

        $this->expectNotToPerformAssertions();
    }
}
