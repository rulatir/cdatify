<?php

namespace Rulatir\Cdatify\Json;

use Rulatir\Cdatify\Json\Contracts\JsonPathTranslationDecider;
use Rulatir\Cdatify\Utility;
use QueryPath\DOMQuery as DQ;

class JsonConverter
{
    public function __construct(
        protected JsonPathTranslationDecider $decider
    ) {
    }

    /**
     * Converts JSON string to HTML-like structure.
     *
     * @param string|null $json The JSON string to convert
     * @return string|null The HTML-ified JSON, or null if input is null
     */
    public function json2html(?string $json): ?string
    {
        if ($json === null) {
            return null;
        }

        // Update decider context
        if ($this->decider instanceof JsonPathTranslationDeciderAnswerFile) {
            $this->decider->setContextJson($json);
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // If JSON is invalid, return as-is
            return $json;
        }

        return $this->valueToHtml($data, '$');
    }

    /**
     * Converts HTML-ified JSON back to JSON string.
     *
     * @param string|null $html The HTML-ified JSON
     * @return string|null The JSON string, or null if input is null
     */
    public function html2json(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $container = Utility::documentify($html);
        $rootElement = $container->children()->first();

        if ($rootElement->length === 0) {
            return $html;
        }

        $value = $this->htmlToValue($rootElement);
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Converts a PHP value to HTML representation.
     */
    protected function valueToHtml(mixed $value, string $path): string
    {
        if (is_null($value)) {
            return '<hj-null/>';
        }

        if (is_bool($value)) {
            $boolStr = $value ? 'true' : 'false';
            $translate = $this->shouldTranslate($path) ? '' : ' translate="no"';
            return "<hj-b{$translate}>{$boolStr}</hj-b>";
        }

        if (is_numeric($value)) {
            $translate = $this->shouldTranslate($path) ? '' : ' translate="no"';
            return "<hj-n{$translate}>{$value}</hj-n>";
        }

        if (is_string($value)) {
            $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $translate = $this->shouldTranslate($path) ? '' : ' translate="no"';
            return "<hj-s{$translate}>{$escaped}</hj-s>";
        }

        if (is_array($value)) {
            // Check if it's an associative array (object) or indexed array
            if ($this->isAssoc($value)) {
                return $this->objectToHtml($value, $path);
            } else {
                return $this->arrayToHtml($value, $path);
            }
        }

        // Fallback for unexpected types
        return '';
    }

    /**
     * Converts an object to HTML representation.
     */
    protected function objectToHtml(array $object, string $path): string
    {
        $parts = ['<hj-o>'];
        foreach ($object as $key => $value) {
            $keyEscaped = htmlspecialchars($key, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $childPath = $path . '.' . $key;
            $valueHtml = $this->valueToHtml($value, $childPath);
            $parts[] = '<hj-kv>';
            $parts[] = "<hj-k translate=\"no\">{$keyEscaped}</hj-k>";
            $parts[] = "<hj-v>{$valueHtml}</hj-v>";
            $parts[] = '</hj-kv>';
        }
        $parts[] = '</hj-o>';
        return implode('', $parts);
    }

    /**
     * Converts an array to HTML representation.
     */
    protected function arrayToHtml(array $array, string $path): string
    {
        $parts = ['<hj-a>'];
        foreach ($array as $index => $value) {
            $childPath = $path . '[*]';
            $parts[] = $this->valueToHtml($value, $childPath);
        }
        $parts[] = '</hj-a>';
        return implode('', $parts);
    }

    /**
     * Converts HTML element back to PHP value.
     */
    protected function htmlToValue(DQ $element): mixed
    {
        $tag = $element->tag();

        return match ($tag) {
            'hj-null' => null,
            'hj-b' => $element->text() === 'true',
            'hj-n' => is_numeric($element->text()) ? $element->text() + 0 : $element->text(),
            'hj-s' => $element->text(),
            'hj-o' => $this->htmlToObject($element),
            'hj-a' => $this->htmlToArray($element),
            default => null,
        };
    }

    /**
     * Converts HTML object representation back to PHP array.
     */
    protected function htmlToObject(DQ $element): array
    {
        $result = [];
        $element->find('> hj-kv')->each(function ($index, $kv) use (&$result) {
            $kvElement = QQ($kv);
            $key = $kvElement->find('> hj-k')->text();
            $valueElement = $kvElement->find('> hj-v')->children()->first();
            $result[$key] = $this->htmlToValue($valueElement);
        });
        return $result;
    }

    /**
     * Converts HTML array representation back to PHP array.
     */
    protected function htmlToArray(DQ $element): array
    {
        $result = [];
        $element->children()->each(function ($index, $child) use (&$result) {
            $result[] = $this->htmlToValue(QQ($child));
        });
        return $result;
    }

    /**
     * Checks if the path should be translated according to the decider.
     */
    protected function shouldTranslate(string $path): bool
    {
        return $this->decider->shouldTranslate($path);
    }

    /**
     * Determines if an array is associative.
     */
    protected function isAssoc(array $arr): bool
    {
        if ([] === $arr) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
