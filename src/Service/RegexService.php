<?php

namespace App\Service;

class RegexService
{
    public function getEscapedStrings(
        array|string $strings,
    ): array|string
    {
        $getEscapedString = $this->getEscapedString(...);

        if (\is_array($strings)) {
            \array_walk(
                $strings,
                static fn($partOfPath) => '~.*' . $getEscapedString($partOfPath) . '.*~',
            );
        }

        if (\is_string($strings)) {
            $strings = $getEscapedString($strings);
        }

        return $strings;
    }

    private function getEscapedString(
        string $string,
    ): string
    {
        $string = \strtr(
            $string,
            [
                '$' => '\$',
                '^' => '\^',
                '|' => '[|]',
                '+' => '[+]',
                '*' => '[*]',
                '?' => '[?]',
                '[' => '[[]',
                ']' => '[]]',
                '\\' => '(?:\\\\|\/)',
                '/' => '(?:\\|\/)',
                '.' => '[.]',
                '-' => '[-]',
                ')' => '[)]',
                '(' => '[(]',
                '{' => '[{]',
                '}' => '[}]',
            ]
        );

        return $string;
    }
}
