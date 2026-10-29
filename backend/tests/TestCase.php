<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Match how the React SPA calls the API: same Origin as
        // SANCTUM_STATEFUL_DOMAINS, so Sanctum enables the session.
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
            'Accept' => 'application/json',
        ]);
    }
}