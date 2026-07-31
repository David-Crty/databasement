<?php

namespace App\Services\Backup;

use App\Support\Formatters;

/**
 * Bounded accumulator for captured command output.
 *
 * Keeps the first and last slice of a stream and counts what fell in between, so
 * a chatty command (e.g. a pg_dump emitting thousands of identical warnings)
 * cannot grow a job log without bound. Cuts are aligned to line boundaries
 * whenever possible so that a truncated line is never half-rendered, and so
 * ShellProcessor::sanitize() keeps seeing whole lines when redacting secrets.
 */
class OutputBuffer
{
    private string $head = '';

    private bool $headSealed = false;

    private string $tail = '';

    private int $droppedBytes = 0;

    private int $droppedLines = 0;

    public function __construct(
        private readonly int $headBytes = 16384,
        private readonly int $tailBytes = 16384,
    ) {}

    public function append(string $data): void
    {
        if (! $this->headSealed) {
            $data = $this->fillHead($data);
        }

        if ($data === '') {
            return;
        }

        $this->tail .= $data;
        $this->trimTail();
    }

    public function isTruncated(): bool
    {
        return $this->droppedBytes > 0;
    }

    public function toString(): string
    {
        $marker = $this->marker();

        if ($marker === null) {
            return $this->head.$this->tail;
        }

        return rtrim($this->head, "\n")."\n".$marker."\n".ltrim($this->tail, "\n");
    }

    /**
     * The line standing in for the omitted middle, or null if nothing was dropped.
     *
     * Public so a caller rendering the buffer line by line can tell the marker
     * apart from real output and style it differently.
     *
     * Not translated on purpose: this is log content, written by a queue worker
     * whose locale is unrelated to whoever later reads the log.
     */
    public function marker(): ?string
    {
        if (! $this->isTruncated()) {
            return null;
        }

        $omitted = Formatters::humanFileSize($this->droppedBytes);

        if ($this->droppedLines > 0) {
            $omitted = number_format($this->droppedLines).' lines, '.$omitted;
        }

        return "[... {$omitted} of output omitted ...]";
    }

    /**
     * Fill the head budget, returning whatever did not fit.
     *
     * Once full, the head is sealed on its last complete line so that no line is
     * split in half; the partial line it would have ended on is handed back to be
     * carried into the tail rather than dropped.
     */
    private function fillHead(string $data): string
    {
        $room = $this->headBytes - strlen($this->head);

        if (strlen($data) <= $room) {
            $this->head .= $data;

            return '';
        }

        $chunk = substr($data, 0, $room);
        $rest = substr($data, $room);
        $this->headSealed = true;

        $lastBreak = strrpos($chunk, "\n");

        if ($lastBreak === false) {
            $this->head .= $chunk;

            return $rest;
        }

        $this->head .= substr($chunk, 0, $lastBreak + 1);

        return substr($chunk, $lastBreak + 1).$rest;
    }

    /**
     * Drop the oldest bytes once the tail outgrows its budget.
     */
    private function trimTail(): void
    {
        $overflow = strlen($this->tail) - $this->tailBytes;

        if ($overflow <= 0) {
            return;
        }

        // Cut at the next line boundary so the tail starts on a whole line. Output
        // with no newline in the whole budget (not line-oriented) falls back to a
        // plain byte cut, which keeps the most recent bytes rather than none.
        $boundary = strpos($this->tail, "\n", $overflow);
        $cut = $boundary === false ? $overflow : $boundary + 1;

        $this->droppedBytes += $cut;
        $this->droppedLines += substr_count(substr($this->tail, 0, $cut), "\n");
        $this->tail = substr($this->tail, $cut);
    }
}
