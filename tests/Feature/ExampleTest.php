<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_guest_redirect_uses_forwarded_https_origin(): void
    {
        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-Host' => 'karyalay-portal.divyajivan.com',
        ])->get('/');

        $response->assertRedirect('https://karyalay-portal.divyajivan.com/login');
    }
}
