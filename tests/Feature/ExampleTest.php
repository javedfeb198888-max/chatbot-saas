<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_home_page_sends_visitors_to_the_client_panel(): void
    {
        $this->get('/')->assertRedirect('/app');
    }
}
