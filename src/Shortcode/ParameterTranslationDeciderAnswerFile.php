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
    public function shouldTranslateParameter(string $shortcodeName, string $parameterName, string $shortcodeText): bool
    {
        if (null===$this->answers['shortcodes'][$shortcodeName]["parameters"][$parameterName] ?? null) {
            $this->answers['shortcodes'][$shortcodeName]["parameters"][$parameterName]
                = $this->askAboutParameter($shortcodeName, $parameterName, $shortcodeText);
            $this->saveAnswers();
        }
        return $this->answers['shortcodes'][$shortcodeName]["parameters"][$parameterName];
    }

    public function shouldTranslateContent(string $shortcodeName, string $shortcodeText): bool
    {
        if (null===$this->answers['shortcodes'][$shortcodeName]["content"] ?? null) {
            $this->answers['shortcodes'][$shortcodeName]["content"]
                = $this->askAboutContent($shortcodeName, $shortcodeText);
            $this->saveAnswers();
        }
        return $this->answers['shortcodes'][$shortcodeName]["content"];
    }


    protected function loadAnswers() : array
    {
        try {
            $data = json_decode($this->fs->read("{$this->schemeName}.json"), true, flags: JSON_THROW_ON_ERROR);
            if (version_compare($data["__version"] ?? "0.0", "1.1", "<")) {
                return $this->loadOldVersion($data);
            }
            return $data;
        }
        catch(FilesystemException|JsonException) {
            return [];
        }
    }

    protected function loadOldVersion(array $data) : array
    {
        $result = [
            "__version" => "1.1",
            "shortcodes" => []
        ];
        foreach($data as $key=>$value) {
            [$sc,$param] = explode(":",$key);
            $result["shortcodes"][$sc] ??= [
                "content" => null,
                "parameters" => []
            ];
            $result["shortcodes"][$sc]["parameters"][$param]=$value;
        }
        return $result;
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

    protected function askAboutParameter(string $shortcodeName, string $parameterName, string $shortcodeText) : bool
    {
        return $this->asker->yesOrNo(
            "Should $shortcodeName.$parameterName be translated?",
            "Unclassified parameter $parameterName of shortcode $shortcodeName encountered in the following context:\n"
            .$shortcodeText
        );
    }

    protected function askAboutContent(string $shortcodeName, string $shortcodeText) : bool
    {
        return $this->asker->yesOrNo(
            "Should the contents of $shortcodeName be translated?",
            "Unclassified shortcode $shortcodeName with content encountered in the following context:\n"
            .$shortcodeText
        );
    }
}