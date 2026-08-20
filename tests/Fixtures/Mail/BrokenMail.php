<?php

namespace Tests\Fixtures\Mail;

use Illuminate\Mail\Mailable;

class BrokenMail extends Mailable
{
    public function __construct()
    {
        throw new \RuntimeException('Cannot instantiate broken mailable.');
    }
}
