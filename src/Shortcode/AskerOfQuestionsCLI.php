<?php

namespace Rulatir\Cdatify\Shortcode;


use Ahc\Cli\IO\Interactor;

class AskerOfQuestionsCLI implements Contracts\AskerOfQuestions
{

    public function yesOrNo(string $question, ?string $preamble=null, bool $default=false): bool
    {
        $interactor = new Interactor();
        return $interactor->confirm($this->formatQuestion($question,$preamble), $default ? 'y' : 'n');
    }

    protected function formatQuestion(string $question, ?string $preamble = null) : string
    {
        return implode("\n",array_filter([$preamble, $question]));
    }
}