<?php

namespace Rulatir\Cdatify\Shortcode;

use JsonException;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Rulatir\Cdatify\Shortcode\Contracts\AskerOfQuestions;

class ParameterTranslationDeciderAnswerFile implements Contracts\ParameterTranslationDecider
{

    protected array $answers;
    public function __construct(
        protected string $schemeName,
        protected FilesystemOperator $fs,
        protected AskerOfQuestions $asker
    ) {
        $this->answers = $this->loadAnswers();
    }
    public function shouldTranslate(string $shortcodeName, string $parameterName, string $shortcodeText): bool
    {
        $key = "$shortcodeName:$parameterName";
        if (null===$this->answers[$key] ?? null) {
            $this->answers[$key] = $this->ask($shortcodeName, $parameterName, $shortcodeText);
            $this->saveAnswers();
        }
        return $this->answers[$key];
    }

    protected function loadAnswers() : array
    {
        try {
            return json_decode($this->fs->read("{$this->schemeName}.json"), true, flags: JSON_THROW_ON_ERROR);
        }
        catch(FilesystemException|JsonException) {
            return [];
        }
    }

    protected function saveAnswers() : void
    {
        try{
            $this->fs->write(
                "{$this->schemeName}.json",
                json_encode($this->answers, JSON_PRETTY_PRINT|JSON_FORCE_OBJECT|JSON_THROW_ON_ERROR)
            );
        } catch (FilesystemException|JsonException) {}
    }

    protected function ask(string $shortcodeName, string $parameterName, string $shortcodeText) : bool
    {
        return $this->asker->yesOrNo(
            "Should $shortcodeName.$parameterName be translated?",
            "Unclassified parameter $parameterName of shortcode $shortcodeName encountered in the following context:\n"
            .$shortcodeText
        );
    }
}