<?php

namespace Rulatir\Cdatify\Shortcode\Contracts;

interface ParameterTranslationDecider
{
    public function shouldTranslate(string $shortcodeName, string $parameterName, string $shortcodeText) : bool;
}