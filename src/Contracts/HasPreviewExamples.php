<?php

namespace Statamic\MailablesViewer\Contracts;

interface HasPreviewExamples
{
    /**
     * Example values the viewer builds the mailable with, keyed by constructor parameter name.
     *
     * @return array<string, mixed>
     */
    public static function previewExamples(): array;
}
