<?php

declare(strict_types=1);

namespace Efabrica\PHPStanLatte\Error\LineMapper;

use InvalidArgumentException;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function json_encode;
use function preg_match;
use function preg_replace;

final class LineMapper
{
    private bool $debugMode;

    /** @var array<string, LineMap> */
    private array $lineMaps = [];

    public function __construct(bool $debugMode = false)
    {
        $this->debugMode = $debugMode;
    }

    public function getLineMap(string $compiledTemplatePath): LineMap
    {
        if (!file_exists($compiledTemplatePath)) {
            throw new InvalidArgumentException('Compiled template file "' . $compiledTemplatePath . '" doesn\'t exist.');
        }
        if (isset($this->lineMaps[$compiledTemplatePath])) {
            return $this->lineMaps[$compiledTemplatePath];
        }
        $lineMapFile = $compiledTemplatePath . '.map';
        if ($this->debugMode || !file_exists($lineMapFile)) {
            $lineMap = $this->parseLineMap($compiledTemplatePath);
            file_put_contents($lineMapFile, json_encode($lineMap->getLines()));
        } else {
            /** @var array<int, int> $lines */
            $lines = json_decode((string)file_get_contents($lineMapFile), true);
            $lineMap = new LineMap($lines);
        }
        $this->lineMaps[$compiledTemplatePath] = $lineMap;
        return $lineMap;
    }

    private function parseLineMap(string $compiledTemplatePath): LineMap
    {
        $originalPath = $compiledTemplatePath . '.original';
        if (!file_exists($originalPath)) {
            return $this->parseLineMapFromContent(file_get_contents($compiledTemplatePath) ?: '');
        }

        $originalContent = file_get_contents($originalPath) ?: '';
        $postprocessedContent = file_get_contents($compiledTemplatePath) ?: '';

        $originalLines = explode("\n", $originalContent);
        $postprocessedLines = explode("\n", $postprocessedContent);

        // Extract pos/line comments from original
        $posMap = []; // originalPhpLine => latteLine
        foreach ($originalLines as $i => $line) {
            if (preg_match('/\*(?:.*?)(?:line |pos )(?<number>\d+)(?::\d+)?(?:.*?)\*\//', $line, $matches)) {
                $posMap[$i + 1] = (int) $matches['number'];
            }
        }

        if (empty($posMap)) {
            return new LineMap();
        }

        // Build normalized code → original line numbers (only for lines with pos comments)
        $codeToOrigLines = [];
        foreach ($posMap as $origLineNum => $latteLine) {
            $code = $this->normalizeCode($originalLines[$origLineNum - 1]);
            if ($code !== '') {
                $codeToOrigLines[$code][] = $origLineNum;
            }
        }

        // For each postprocessed line, find matching original and map
        $lineMap = new LineMap();
        foreach ($postprocessedLines as $ppIdx => $ppLine) {
            $ppLineNum = $ppIdx + 1;
            $ppCode = $this->normalizeCode($ppLine);
            if ($ppCode === '' || !isset($codeToOrigLines[$ppCode])) {
                continue;
            }
            foreach ($codeToOrigLines[$ppCode] as $origLineNum) {
                if (isset($posMap[$origLineNum])) {
                    $lineMap->add($ppLineNum, $posMap[$origLineNum]);
                }
            }
        }

        return $lineMap;
    }

    private function parseLineMapFromContent(string $content): LineMap
    {
        $phpLineContents = explode("\n", $content);
        $lineMap = new LineMap();
        foreach ($phpLineContents as $i => $phpLineContent) {
            if (preg_match('/\*(?:.*?)(?:line |pos )(?<number>\d+)(?::\d+)?(?:.*?)\*\//', $phpLineContent, $matches)) {
                $latteLine = (int) $matches['number'];
                $lineMap->add($i + 1, $latteLine);
            }
        }
        return $lineMap;
    }

    /**
     * Normalize a code line for matching between original and pretty-printed output.
     */
    private function normalizeCode(string $line): string
    {
        // Remove comments
        $line = preg_replace('/\/\*.*?\*\//', '', $line);
        $line = preg_replace('/\/\/.*$/', '', $line);
        // Normalize LR alias and \Latte\Runtime\ to a common form
        $line = str_replace('LR\\', 'LatteRuntime_', $line);
        $line = str_replace('\\Latte\\Runtime\\', 'LatteRuntime_', $line);
        // Remove other leading backslashes before uppercase (namespace separators)
        $line = preg_replace('/\\\\(?=[A-Z])/', '', $line);
        // Remove spaces before semicolons (original has " ) ;" vs postprocessed " );")
        $line = preg_replace('/\s+;/', ';', $line);
        // Collapse all whitespace to single space
        $line = preg_replace('/\s+/', ' ', $line);
        return trim($line);
    }
}
