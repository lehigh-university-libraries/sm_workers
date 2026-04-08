<?php

declare(strict_types=1);

namespace Drupal\sm_workers\Service;

use Psr\Log\LoggerInterface;

/**
 * Executes a shell-free command with stdin piped in and stdout captured.
 *
 * This is the canonical stdin/stdout runner for worker-mode derivative
 * processing. Callers fetch the input bytes (e.g. by downloading a source
 * file) and decide what to do with the output bytes (e.g. write back to
 * Drupal). stderr is captured and logged on failure but is never mixed into
 * the returned output.
 *
 * The command is always executed without a shell — argv[0] is the binary and
 * subsequent elements are individual arguments, so no shell escaping is
 * needed or performed.
 */
final class WorkerCommandExecutor {

  /**
   * Constructs the executor.
   */
  public function __construct(
    private LoggerInterface $logger,
  ) {}

  /**
   * Runs a command with the given stdin bytes and returns stdout.
   *
   * @param list<string> $argv
   *   Command argv. The first element is the executable path or binary name;
   *   remaining elements are individual arguments. Must be non-empty.
   * @param string $stdin
   *   Bytes to write to the command's stdin before it begins reading.
   * @param array<string, mixed> $logContext
   *   Optional key/value pairs added to log records (e.g. queue name).
   * @param int|null $timeoutSeconds
   *   Optional hard timeout in seconds for the subprocess runtime.
   * @param string|null $workingDirectory
   *   Optional working directory for the subprocess.
   * @param array<string, string>|null $environment
   *   Optional environment variables for the subprocess.
   * @param callable|null $heartbeat
   *   Optional callback invoked while the subprocess is still running.
   * @param int|null $heartbeatIntervalSeconds
   *   Minimum number of seconds between heartbeat callbacks.
   *
   * @return string
   *   Raw stdout bytes produced by the command.
   *
   * @throws \RuntimeException
   *   When the process cannot be started or exits with a non-zero code.
   */
  public function run(
    array $argv,
    string $stdin,
    array $logContext = [],
    ?int $timeoutSeconds = NULL,
    ?string $workingDirectory = NULL,
    ?array $environment = NULL,
    ?callable $heartbeat = NULL,
    ?int $heartbeatIntervalSeconds = NULL,
  ): string {
    if ($argv === []) {
      throw new \RuntimeException('Cannot execute an empty command argv.');
    }

    error_log(sprintf(
      '[sm_workers] Starting worker command. cmd=%s argv=%s',
      $argv[0],
      json_encode($argv, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
    ));

    $descriptorSpec = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];

    $process = proc_open($argv, $descriptorSpec, $pipes, $workingDirectory, $environment);
    if (!is_resource($process)) {
      $this->logger->error('Unable to start worker command.', $logContext + ['cmd' => $argv[0]]);
      throw new \RuntimeException(sprintf('Unable to start command "%s".', $argv[0]));
    }

    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);

    stream_set_blocking($pipes[1], FALSE);
    stream_set_blocking($pipes[2], FALSE);

    $stdout = '';
    $stderr = '';
    $timeoutSeconds = $timeoutSeconds !== NULL ? max(1, $timeoutSeconds) : NULL;
    $deadline = $timeoutSeconds !== NULL ? microtime(TRUE) + $timeoutSeconds : NULL;
    $heartbeatIntervalSeconds = $heartbeatIntervalSeconds !== NULL ? max(1, $heartbeatIntervalSeconds) : NULL;
    $lastHeartbeatAt = microtime(TRUE);

    while (TRUE) {
      $stdout .= (string) stream_get_contents($pipes[1]);
      $stderr .= (string) stream_get_contents($pipes[2]);

      $status = proc_get_status($process);
      if (!($status['running'] ?? FALSE)) {
        break;
      }

      if (
        $heartbeat !== NULL
        && $heartbeatIntervalSeconds !== NULL
        && (microtime(TRUE) - $lastHeartbeatAt) >= $heartbeatIntervalSeconds
      ) {
        $heartbeat();
        $lastHeartbeatAt = microtime(TRUE);
      }

      if ($deadline !== NULL && microtime(TRUE) >= $deadline) {
        proc_terminate($process);
        usleep(100000);
        $status = proc_get_status($process);
        if ($status['running'] ?? FALSE) {
          proc_terminate($process, 9);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->logger->error('Worker command timed out.', $logContext + [
          'cmd' => $argv[0],
          'argv' => $argv,
          'timeout_seconds' => $timeoutSeconds,
        ]);
        throw new \RuntimeException(sprintf(
          'Command "%s" timed out after %d seconds.',
          $argv[0],
          $timeoutSeconds,
        ));
      }

      usleep(100000);
    }

    $stdout .= (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
      $trimmedStderr = trim($stderr);
      $this->logger->error('Worker command failed.', $logContext + [
        'cmd' => $argv[0],
        'argv' => $argv,
        'exit_code' => $exitCode,
        'stderr' => $trimmedStderr,
      ]);
      error_log(sprintf(
        '[sm_workers] Worker command failed. cmd=%s argv=%s exit_code=%d%s',
        $argv[0],
        json_encode($argv, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        $exitCode,
        $trimmedStderr !== '' ? ' stderr=' . $trimmedStderr : '',
      ));
      throw new \RuntimeException(sprintf(
        'Command "%s" failed with exit code %d%s',
        $argv[0],
        $exitCode,
        $trimmedStderr !== '' ? ': ' . $trimmedStderr : '.',
      ));
    }

    error_log(sprintf(
      '[sm_workers] Worker command completed. cmd=%s argv=%s exit_code=0',
      $argv[0],
      json_encode($argv, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
    ));

    return $stdout;
  }

}
