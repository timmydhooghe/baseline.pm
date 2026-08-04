<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the marketing landing page renders', function () {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('welcome'));
});
