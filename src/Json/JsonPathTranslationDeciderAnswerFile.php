<?php

namespace Rulatir\Cdatify\Json;

use JsonException;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Rulatir\Cdatify\Shortcode\Contracts\AskerOfQuestions;

class JsonPathTranslationDeciderAnswerFile implements Contracts\JsonPathTranslationDecider
{
    protected array $answers;

    public function __construct(
        protected string $schemeName,
        protected FilesystemOperator $fs,
        protected AskerOfQuestions $asker,
        protected string $contextJson = ''
    ) {
        $this->answers = $this->loadAnswers();
    }

    public function shouldTranslate(string $path): bool
    {
        if (!isset($this->answers['paths'][$path])) {
            $this->answers['paths'][$path] = $this->askAboutPath($path);
            $this->saveAnswers();
        }
        return $this->answers['paths'][$path];
    }

    /**
     * Updates the context JSON for prompting about paths.
     */
    public function setContextJson(string $contextJson): void
    {
        $this->contextJson = $contextJson;
    }

    protected function loadAnswers(): array
    {
        try {
            $data = json_decode(
                $this->fs->read("{$this->schemeName}.json"),
                true,
                flags: JSON_THROW_ON_ERROR
            );
            // Ensure we have the expected structure
            if (!isset($data['__version']) || !isset($data['paths'])) {
                return $this->getEmptyStructure();
            }
            return $data;
        } catch (FilesystemException|JsonException) {
            return $this->getEmptyStructure();
        }
    }

    protected function getEmptyStructure(): array
    {
        return [
            '__version' => '1.0',
            'paths' => []
        ];
    }

    protected function saveAnswers(): void
    {
        try {
            $this->fs->write(
                "{$this->schemeName}.json",
                json_encode($this->answers, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
            );
        } catch (FilesystemException|JsonException) {
        }
    }

    protected function askAboutPath(string $path): bool
    {
        $preamble = "Unclassified JSON path $path encountered";
        if ($this->contextJson !== '') {
            $preamble .= " in the following JSON:\n" . $this->contextJson;
        }

        return $this->asker->yesOrNo(
            "Should values at path $path be translated?",
            $preamble
        );
    }
}
