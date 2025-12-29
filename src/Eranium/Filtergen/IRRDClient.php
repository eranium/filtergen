<?php
/**
 * Eranium Filtergen
 * @copyright Eranium B.V.
 * @license Mozilla Public License 2.0
 * @link https://github.com/eranium/filtergen
 */

declare(strict_types=1);

namespace Eranium\Filtergen;

class IRRDClient
{
    /**
     * @var string
     */
    private string $irrdServer;
    /**
     * @var int
     */
    private int $irrdPort;
    /**
     * @var mixed
     */
    private mixed $irrdSocket;
    /**
     * @var array
     */
    public array $commands;

    /**
     * Creates object and assigns required vars.
     * @param  string  $irrdServer
     * @param  int  $irrdPort
     */
    public function __construct(string $irrdServer = 'rr.ntt.net', int $irrdPort = 43)
    {
        $this->irrdServer = $irrdServer;
        $this->irrdPort = $irrdPort;
    }

    /**
     * Destructor simply calls disconnect().
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Disconnects from IRRd server and cleans up.
     * @return void
     */
    public function disconnect(): void
    {
        if (isset($this->irrdSocket) && stream_get_meta_data($this->irrdSocket)['timed_out'] === false) {
            fclose($this->irrdSocket);
            unset($this->irrdSocket);
        }
    }

    /**
     * Connects to an IRRd server, by default we use the popular NTT hosted one.
     * @return $this
     * @throws \Exception
     */
    public function connect(): static
    {
        if (isset($this->irrdSocket) && stream_get_meta_data($this->irrdSocket)['timed_out'] === false) {
            throw new \Exception('Already connected to '.$this->irrdServer);
        }
        $this->irrdSocket = fsockopen("rr.ntt.net", $this->irrdPort, $errorCode, $errorMessage, 300);
        if (!$this->irrdSocket) {
            throw new \Exception('Could not connect to '.$this->irrdServer);
        }
        fwrite($this->irrdSocket, "!!\n");
        return $this;
    }

    /**
     * Sending commands to IRRd server, enabled multi command mode first.
     * @param  string  $command
     * @return $this
     * @throws \Exception
     */
    public function command(string $command)
    {
        if (!isset($this->irrdSocket)) {
            $this->connect();
        }
        if ($command == '!!') {
            throw new \Exception('The multicommand query !! is enabled by default and is not needed.');
        }
        $this->commands[trim($command)] = null;
        return $this;
    }

    /**
     * Read function that captures and processed output from an IRRd server.
     * @return array
     * @throws \Exception
     */
    public function read(): array
    {
        if (!$this->irrdSocket) {
            throw new \Exception('Not connected to an IRRD server yet.');
        }
        if (empty($this->commands)) {
            throw new \Exception('No commands set.');
        }
        foreach ($this->commands as $command => $output) {
            fwrite($this->irrdSocket, trim($command)."\n");
        }
        fwrite($this->irrdSocket, "!q\n");
        $inQuery = false;
        $commandsCounter = 0;
        while (!feof($this->irrdSocket)) {
            $line = fgets($this->irrdSocket);
            if ($line === false) {
                break;
            }

            if (str_starts_with($line, 'A')) {
                $inQuery = true;
                $len = (int)substr($line, 1);
                $data = '';
                while (strlen($data) < $len) {
                    $chunk = fread($this->irrdSocket, $len - strlen($data));
                    if (!$chunk) {
                        break;
                    }
                    $data .= $chunk;
                }
                $this->commands[array_keys($this->commands)[$commandsCounter]] = trim($data);
                $commandsCounter++;
                continue;
            }

            if (str_starts_with($line, 'C') && !$inQuery) {
                $commandsCounter++;
            }
            if (str_starts_with($line, 'C') && $inQuery) {
                $inQuery = false;
            }
            if (str_starts_with($line, 'D')) {
                $commandsCounter++;
            }
            if (str_starts_with($line, 'F')) {
                throw new \Exception('Invalid query.');
            }
        }

        $results = $this->commands;
        unset($this->commands);
        $this->disconnect();
        return $results;
    }

    /**
     * Follows the output of the IRRd server, useful for debugging.
     * @return void
     * @throws \Exception
     */
    public function follow(): void
    {
        if (!$this->irrdSocket) {
            throw new \Exception('Not connected to an IRRD server yet.');
        }
        if (empty($this->commands)) {
            throw new \Exception('No commands set.');
        }
        foreach ($this->commands as $command => $output) {
            fwrite($this->irrdSocket, trim($command)."\n");
        }
        while (($line = fgets($this->irrdSocket)) !== false) {
            echo trim($line).PHP_EOL;
        }
    }
}
