<?php

namespace App\Training;

class ExtractedPage
{
    public function __construct(
        public readonly ?string $title,
        public readonly string $text,
    ) {
    }
}
