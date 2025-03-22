<?php

namespace rareform\Prune\Tests;

use PHPUnit\Framework\TestCase;
use rareform\Prune\Prune;

class PruneTest extends TestCase
{
    /** @test */
    public function it_can_return_hello_message()
    {
        $prune = new Prune();
        $this->assertEquals('Hello from Craft Prune!', $prune->hello());
    }
}