<?php
declare(strict_types=1);

namespace LengowExample;

use RuntimeException;

/** RFC-4180-style streaming reader for a CSV file or one CSV member inside a ZIP. */
final class CsvInput
{
    /** @var resource */
    private $stream;
    /** @var resource|null */
    private $process = null;
    /** @var resource|null */
    private $stderr = null;
    private ?\ZipArchive $zip = null;
    private array $header;
    private bool $closed = false;
    private bool $reachedEof = false;
    public int $rowsRead = 0;

    public function __construct(string $path, ?string $zipMember = null)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Input not found or unreadable: {$path}");
        }
        if ($zipMember === null) {
            $stream = @fopen($path, 'rb');
            if ($stream === false) throw new RuntimeException("Cannot open CSV: {$path}");
            $this->stream = $stream;
        } elseif (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) throw new RuntimeException("Cannot open ZIP: {$path}");
            $stream = $zip->getStream($zipMember);
            if ($stream === false) {
                $zip->close();
                throw new RuntimeException("Missing ZIP member: {$zipMember}");
            }
            $this->zip = $zip;
            $this->stream = $stream;
        } else {
            // PHP builds without ext-zip can stream using the system unzip utility.
            // An argv array avoids shell interpolation of user-supplied paths.
            $pipes = [];
            $process = @proc_open(['unzip', '-p', $path, $zipMember], [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot run unzip; extract the CSV files first');
            }
            fclose($pipes[0]);
            $this->process = $process;
            $this->stderr = $pipes[2];
            $this->stream = $pipes[1];
        }
        $header = fgetcsv($this->stream, 0, ',', '"', '');
        if (!is_array($header)) {
            $this->close();
            throw new RuntimeException('Missing CSV header or unreadable ZIP member');
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        if (count($header) !== count(array_unique($header))) {
            $this->close();
            throw new RuntimeException('Duplicate CSV header fields are unsupported');
        }
        $this->header = $header;
    }

    public function requireColumns(array $required): void
    {
        $missing = array_diff($required, $this->header);
        if ($missing) throw new RuntimeException('CSV missing columns: ' . implode(', ', $missing));
    }

    /** @return array<string,string>|null Null at EOF. */
    public function next(): ?array
    {
        if ($this->closed) throw new RuntimeException('CSV input already closed');
        while (($values = fgetcsv($this->stream, 0, ',', '"', '')) !== false) {
            if (count($values) === 1 && $values[0] === null) continue;
            $this->rowsRead++;
            if (count($values) !== count($this->header)) {
                throw new RuntimeException("Malformed CSV row {$this->rowsRead}: expected "
                    . count($this->header) . ' columns, got ' . count($values));
            }
            return array_combine($this->header, $values);
        }
        $this->reachedEof = true;
        return null;
    }

    public function close(): void
    {
        if ($this->closed) return;
        $this->closed = true;
        fclose($this->stream);
        if ($this->zip !== null) $this->zip->close();
        if (is_resource($this->process)) {
            $error = is_resource($this->stderr) ? stream_get_contents($this->stderr) : '';
            if (is_resource($this->stderr)) fclose($this->stderr);
            $status = proc_close($this->process);
            // An early stop intentionally closes the unzip output pipe (SIGPIPE is expected).
            if ($this->reachedEof && $status !== 0) {
                throw new RuntimeException('unzip failed: ' . trim((string)$error));
            }
        }
    }

    public function __destruct()
    {
        if (!$this->closed) {
            try { $this->close(); } catch (\Throwable $ignored) { /* no exception from destructor */ }
        }
    }
}
