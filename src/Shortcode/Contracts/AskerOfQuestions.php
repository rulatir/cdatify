<?php

namespace Rulatir\Cdatify\Shortcode\Contracts;

interface AskerOfQuestions
{
    public function yesOrNo(string $question,?string $preamble=null, bool $default=false) : bool;
}