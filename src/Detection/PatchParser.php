<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Detection;

use Composer\InstalledVersions;
use SebastianBergmann\Diff\Line;
use SebastianBergmann\Diff\Parser as DiffParser;
use ShipMonk\CopyPasteDetector\Exception\ErrorException;
use function array_map;
use function count;
use function dirname;
use function file;
use function file_exists;
use function file_get_contents;
use function is_file;
use function method_exists;
use function realpath;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function substr;
use const DIRECTORY_SEPARATOR;

/**
 * Parses a unified-diff patch file into a {@see ChangedLines} set.
 */
final class PatchParser
{

    public function __construct(
        private readonly string $cwd,
    )
    {
    }

    /**
     * @throws ErrorException
     */
    public function parse(string $patchFile): ChangedLines
    {
        if (!is_file($patchFile)) {
            throw new ErrorException("Patch file not found: {$patchFile}");
        }

        if (!str_ends_with($patchFile, '.patch') && !str_ends_with($patchFile, '.diff')) {
            throw new ErrorException("Unknown patch filepath {$patchFile}, expecting .patch or .diff extension");
        }

        if (!InstalledVersions::isInstalled('sebastian/diff')) {
            throw new ErrorException('In order to use --patch mode, you need to install sebastian/diff');
        }

        $patchContent = file_get_contents($patchFile);

        if ($patchContent === false) {
            throw new ErrorException("Failed to read patch file: {$patchFile}");
        }

        $gitRoot = $this->detectGitRoot();

        if ($gitRoot === null) {
            throw new ErrorException('In order to process patch files, you need to run inside a git repository folder.');
        }

        $gitRoot .= DIRECTORY_SEPARATOR;

        $diffs = (new DiffParser())->parse($patchContent);

        /** @var array<string, array<int, true>> $changes */
        $changes = [];

        foreach ($diffs as $diff) {
            $diffTo = method_exists($diff, 'to') ? $diff->to() : $diff->getTo();

            if ($diffTo === '/dev/null') {
                continue; // deleted file
            }

            if (!str_starts_with($diffTo, 'b/')) {
                throw new ErrorException("Patch file '{$patchFile}' uses unsupported prefix in '{$diffTo}'. Only standard 'b/' is supported. Please use 'git diff --dst-prefix=b/' to regenerate the patch file.");
            }

            $absolutePath = $gitRoot . substr($diffTo, 2);

            if (!is_file($absolutePath)) {
                throw new ErrorException("File '{$absolutePath}' referenced by patch '{$patchFile}' does not exist on disk. Is the patch up-to-date?");
            }

            $realPath = realpath($absolutePath);

            if ($realPath === false) {
                throw new ErrorException("Could not resolve real path of '{$absolutePath}'");
            }

            $actualFileLines = $this->readFileLines($realPath);
            $diffChunks = method_exists($diff, 'chunks') ? $diff->chunks() : $diff->getChunks();

            foreach ($diffChunks as $chunk) {
                $lineNumber = method_exists($chunk, 'end') ? $chunk->end() : $chunk->getEnd();
                $chunkLines = method_exists($chunk, 'lines') ? $chunk->lines() : $chunk->getLines();

                foreach ($chunkLines as $line) {
                    $lineType = method_exists($line, 'type') ? $line->type() : $line->getType();

                    if ($lineType === Line::ADDED) {
                        $lineContent = method_exists($line, 'content') ? $line->content() : $line->getContent();
                        $actualLine = $actualFileLines[$lineNumber - 1] ?? null;

                        if ($actualLine === null) {
                            throw new ErrorException("Patch file '{$patchFile}' refers to added line #{$lineNumber} with '{$lineContent}' contents in file '{$realPath}', but such line does not exist. Is the patch up-to-date?");
                        }

                        if ($actualLine !== $lineContent) {
                            throw new ErrorException("Patch file '{$patchFile}' has added line #{$lineNumber} that does not match actual content of file '{$realPath}'.\nPatch data: '{$lineContent}'\nFilesystem: '{$actualLine}'\n\nIs the patch up-to-date?");
                        }

                        $changes[$realPath][$lineNumber] = true;
                    }

                    if ($lineType !== Line::REMOVED) {
                        $lineNumber++;
                    }
                }
            }
        }

        return new ChangedLines($changes);
    }

    /**
     * Read file as list of lines without EOL chars; matches the per-line content sebastian/diff exposes.
     *
     * @return list<string>
     *
     * @throws ErrorException
     */
    private function readFileLines(string $file): array
    {
        $lines = file($file);

        if ($lines === false) {
            throw new ErrorException("Failed to read file: {$file}");
        }

        if ($lines === []) {
            return [];
        }

        $lastLine = $lines[count($lines) - 1];

        // A final newline means the file's last logical line is the empty string after it —
        // append an empty entry so line numbers stay 1:1 with the patch's idea of the file.
        if (rtrim($lastLine, "\n\r") !== $lastLine) {
            $lines[] = '';
        }

        return array_map(static fn (string $line): string => rtrim($line, "\n\r"), $lines);
    }

    private function detectGitRoot(): ?string
    {
        $dir = $this->cwd;

        while (true) {
            if (file_exists($dir . '/.git')) {
                $resolved = realpath($dir);
                return $resolved === false ? null : $resolved;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                return null;
            }

            $dir = $parent;
        }
    }

}
