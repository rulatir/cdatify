<?php

namespace Rulatir\Cdatify\Json\Contracts;

interface JsonPathTranslationDecider
{
    /**
     * Determines if a value at a given JSON path should be translated.
     *
     * @param string $path The JSON path (e.g., '$.author.name', '$.items[*].title')
     * @return bool True if the value should be translated, false otherwise
     */
    public function shouldTranslate(string $path): bool;
}
