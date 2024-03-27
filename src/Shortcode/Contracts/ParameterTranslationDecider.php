<?php

namespace Rulatir\Cdatify\Shortcode\Contracts;

interface ParameterTranslationDecider
{
    public function shouldTranslateParameter(string $shortcodeName, string $parameterName, string $shortcodeText) : bool;
    public function shouldTranslateContent(string $shortcodeName, string $shortcodeText) : bool;
}