<?php

namespace Tests\Fixtures\Mail;

class Greeting
{
    public function message(): string
    {
        return 'Hello from the container';
    }
}
