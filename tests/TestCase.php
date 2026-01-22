<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\Concerns\TestViews;

abstract class TestCase extends BaseTestCase
{
    use TestViews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTestViews();
    }
}
