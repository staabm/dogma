<?php

namespace Dogma\Queue;


/**
 * An interface to Beanstalk queue service. Implements Beanstalk protocol spec 1.2
 * @link https://github.com/kr/beanstalkd/blob/master/doc/protocol.txt
 *
 * Based on Socket_Beanstalk class by David Persson [nperson@gmx.de]
 * @link https://github.com/davidpersson/beanstalk/
 *
 * @author Vlasta Neubauer [paranoi@centrum.cz]
 */
class BeanstalkClient extends \Dogma\Object {

    // job priority
    const TOP_PRIORITY = 0;
    const URGENT_PRIORITY = 512;
    const HIGH_PRIORITY = 1024; // everything under 1024 is urgent (see "current-jobs-urgent" in stats)
    const MEDIUM_PRIORITY = 2048;
    const LOW_PRIORITY = 4096;


    /** @var string */
    private $host;

    /** @var integer */
    private $port;

    /** @var integer */
    private $timeout;

    /** @var boolean */
    private $persistent;

    /** @var resource */
    private $connection;


    protected $defaultPriority = 2048;
    protected $defaultTimeToRun = 60;
    protected $defaultDelay = 0;


    // suspended job handling
    const IGNORE = -2;
    const NOTICE = -1;
    const THROW_EXCEPTION = 0;

    /** @var integer */
    private $onSuspended = 0;

    /** @var callback */
    private $onDeadline;


    /**
     * @param string $host server address
     * @param integer $port server port
     * @param integer $timeout connection timeout in seconds
     * @param boolean
     */
    public function __construct($host = '127.0.0.1', $port = 11300, $timeout = 1, $persistent = true) {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->persistent = $persistent;
    }


    public function __destruct() {
        $this->quit();
    }


    /**
     * @param integer
     */
    public function setDefaultPriority($priority) {
        $this->defaultPriority = abs((int)$priority);
    }


    /**
     * @param integer [seconds]
     */
    public function setDefaultDelay($delay) {
        $this->defaultDelay = abs((int)$delay);
    }


    /**
     * @param integer [seconds]
     */
    public function setDefaultTimeToRun($timeToRun) {
        $this->defaultTimeToRun = abs((int)$timeToRun);
    }


    /**
     * Initiate a socket connection to Beanstalk server.
     */
    private function connect() {
        if (isset($this->connection)) {
            $this->disconnect();
        }

        $function = $this->persistent ? 'pfsockopen' : 'fsockopen';
        $errNum = $errStr = '';
        $params = [$this->host, $this->port, &$errNum, &$errStr];

        if ($this->timeout) {
            $params[] = $this->timeout;
        }
        $this->connection = @call_user_func_array($function, $params);

        if (!empty($errNum) || !empty($errStr)) {
            $this->connection = null;
            throw new BeanstalkException("Socket: $errStr", $errNum);
        }

        if (!is_resource($this->connection)) {
            $this->connection = null;
            throw new BeanstalkException("Cannot create connection to Beanstalk server.");
        }

        // no timeout (blocking reads)
        stream_set_timeout($this->connection, -1);
    }


    /**
     * Close connection to Beanstalk server.
     */
    private function disconnect() {
        if (is_resource($this->connection)) {
            fclose($this->connection);
        }

        $this->connection = null;
    }


    /**
     * Send a message to server.
     *
     * @param string
     */
    private function send($data) {
        if (!$this->connection) {
            $this->connect();
        }
        echo ">> $data\n";
        $res = fwrite($this->connection, $data . "\r\n", strlen($data) + 2);

        if ($res === false) {
            throw new BeanstalkException("Cannot send message to Beanstalk server.");
        }
    }


    /**
     * Read a message from server.
     *
     * @param integer (bytes)
     * @return string
     */
    private function receive($length = null) {
        if ($length) {
            if (feof($this->connection)) {
                throw new BeanstalkException("No reply from Beanstalk server.");
            }
            $data = fread($this->connection, $length + 2);
            $meta = stream_get_meta_data($this->connection);

            if ($meta['timed_out']) {
                throw new BeanstalkException("Connection to Beanstalk server timed out.");
            }
            $data = rtrim($data, "\r\n");
            echo "   $data\n";

        } else {
            $data = stream_get_line($this->connection, 16384, "\r\n");
            if ($data === false) {
                throw new BeanstalkException("No reply from Beanstalk server.");
            }
            echo "   $data\n";
        }

        return $data;
    }


    /**
     * Send [QUIT] command and disconnect.
     */
    public function quit() {
        try {
            if ($this->connection) $this->send('quit');
        } catch (BeanstalkException $e) {
            // pass
        }
        $this->disconnect();
    }


    /**
     * Format delay argument to seconds.
     *
     * @param integer|float|string|\DateTime
     * @return integer
     */
    private function delayToSeconds($delay) {
        if (is_numeric($delay)) {
            if ((int)$delay < 0)
                trigger_error("BeanstalkClient: Job delay should not be negative. $delay given.", E_USER_WARNING);

            return abs((int)$delay);

        } elseif (is_string($delay) || $delay instanceof \DateTime) {
            if (is_string($delay)) $delay = new \DateTime($delay);
            $seconds = $delay->getTimestamp() - time();
            if ($seconds < 0)
                trigger_error("BeanstalkClient: Job delay should not be negative. $seconds given.", E_USER_WARNING);

            return abs($seconds);

        } else {
            throw new \InvalidArgumentException("Unsupported delay parameter given.");
        }
    }


    /**
     * Try to serialize job data.
     * Throws exception for unsupported types (null, bool, resource)
     *
     * @param array|object|integer|float
     * @return string
     */
    private function serializeJob($data) {
        if (is_object($data) || is_array($data) || is_int($data) || is_float($data)) {
            return serialize($data);
        } else {
            throw new \InvalidArgumentException("Unsupported job data type.");
        }
    }


    /**
     * Try to unserialize job data.
     *
     * @param string
     * @return string|array|object
     */
    private function unserializeJob($data) {
        $job = @unserialize($data);

        if ($job === false) {
            return $data;
        } else {
            return $job;
        }
    }


    /**
     * Set callback to call when a "DEADLINE SOON" signal received.
     *
     * @param callback
     */
    public function setOnDeadline($callback) {
        if (!is_callable($callback))
            throw new \InvalidArgumentException("Invalid callback onDeadline given.");

        $this->onDeadline = $callback;
    }


    /**
     * What to do if job is suspended by server.
     *
     * @param integer
     */
    public function setOnSuspended($action) {
        $this->onSuspended = $action;
    }


    /**
     * Handle case when a job is suspended *by server*.
     *
     * @param integer
     */
    private function suspended($jobId) {
        switch ($this->onSuspended) {
            case self::IGNORE;
                break;
            case self::NOTICE;
                trigger_error("BeanstalkClient: Job $jobId was suspended by server. Check and restore the suspended jobs!");
                break;
            case self::THROW_EXCEPTION;
            default:
                throw new BeanstalkException("BeanstalkClient: Job $jobId was suspended by server. Check and restore the suspended jobs!");
        }
    }


    // Producer Commands -----------------------------------------------------------------------------------------------


    /**
     * Insert a job into the queue. [PUT]
     * All other types except string will be serialized.
     *
     * @param string $data job data
     * @param integer|\DateTime $delay seconds of delay or time to start
     * @param integer $priority [0-2^32]. lower number means higher priority
     * @param integer $timeToRun worker timeout, before re-assigning job to another worker
     * @return integer job id
     */
    public function queue($data, $delay = null, $priority = null, $timeToRun = null) {
        if (!isset($priority)) $priority = $this->defaultPriority;
        if (!isset($timeToRun)) $timeToRun = $this->defaultTimeToRun;
        if (!isset($delay)) $delay = $this->defaultDelay;

        $priority  = abs((int)$priority);
        $timeToRun = abs((int)$timeToRun);
        if (!is_int($delay)) $delay = $this->delayToSeconds($delay);

        if (!is_string($data)) $data = $this->serializeJob($data);

        $this->send(sprintf('put %d %d %d %d', $priority, $delay, $timeToRun, strlen($data)));
        $this->send($data);

        $status = strtok($this->receive(), ' ');

        switch ($status) {
            case 'INSERTED':
                return (int)strtok(' '); // job id
            case 'BURIED':
                $this->suspended((int)strtok(' '));
                return -1;
                break;
            case 'EXPECTED_CRLF':
            case 'JOB_TOO_BIG':
            default:
                throw new BeanstalkException("Error when queueing a job: " . $status);
        }
    }


    /**
     * Select queue for inserting jobs. Default queue is "default". [USE]
     * Automatically creates queues.
     *
     * @param string $queue name (max 200 bytes)
     */
    public function selectQueue($queue) {
        $this->send(sprintf('use %s', $queue));
        $status = strtok($this->receive(), ' ');

        switch ($status) {
            case 'USING':
                break;
            default:
                throw new BeanstalkException("Error when selecting a queue: " . $status);
        }
    }


    // Worker Commands -------------------------------------------------------------------------------------------------


    /**
     * Ask for a job to assign. Job is reserved until finished, released or timed-out. [RESERVE]
     * When no timeout is given, waits until some job is ready.
     *
     * @param integer $timeout seconds to wait if queue is empty. 0 returns immediately.
     * @return \Dogma\Queue\BeanstalkJob
     */
    public function assign($timeout = null) {
        if (isset($timeout)) {
            $this->send(sprintf('reserve-with-timeout %d', $timeout));
        } else {
            $this->send('reserve');
        }
        $status = strtok($this->receive(), ' ');

        switch ($status) {
            case 'RESERVED':
                $id = (int)strtok(' ');
                $body = $this->unserializeJob($this->receive((int)strtok(' ')));
                break;
            case 'DEADLINE_SOON':
                /// if ($this->onDeadline) $this->onDeadline();
                return [];
            case 'TIMED_OUT':
                return [];
            default:
                throw new BeanstalkException("Error when claiming a job: " . $status);
        }

        return new BeanstalkJob($id, $body, true, $this);
    }


    /**
     * Finishes job and removes it from the queue. [DELETE]
     *
     * @param integer
     */
    public function finish($jobId) {
        $this->send(sprintf('delete %d', $jobId));
        $status = $this->receive();

        switch ($status) {
            case 'DELETED':
                return;
            case 'NOT_FOUND':
            default:
                throw new BeanstalkException("Error when finishing a job: " . $status);
        }
    }


    /**
     * Alias for finish().
     *
     * @param integer
     */
    public function delete($jobId) {
        $this->finish($jobId);
    }


    /**
     * Puts a reserved job back into the ready queue. [RELEASE]
     *
     * @param integer
     * @param integer|\DateTime
     * @param integer
     */
    public function release($jobId, $delay = null, $priority = null) {
        if (!isset($priority)) $priority = $this->defaultPriority;
        if (!isset($delay)) $delay = $this->defaultDelay;

        $priority = abs((int)$priority);
        if (!is_int($delay)) $delay = $this->delayToSeconds($delay);

        $this->send(sprintf('release %d %d %d', $jobId, $priority, $delay));
        $status = $this->receive();

        switch ($status) {
            case 'RELEASED':
                return;
            case 'BURIED':
                $this->suspended($jobId);
                return;
            case 'NOT_FOUND':
            default:
                throw new BeanstalkException("Error when releasing a job: " . $status);
        }
    }


    /**
     * Suspend a job. Job cannot be assigned to a worker until it is restored. [BURY]
     *
     * @param integer
     * @param integer
     */
    public function suspend($jobId, $priority = null) {
        if (!isset($priority)) $priority = $this->defaultPriority;

        $priority = abs((int)$priority);

        $this->send(sprintf('bury %d %d', $jobId, $priority));
        $status = $this->receive();

        switch ($status) {
            case 'BURIED':
                return;
            case 'NOT_FOUND':
            default:
                throw new BeanstalkException("Error when suspending a job: " . $status);
        }
    }


    /**
     * Restore a previously suspended job. It can be assigned to a worker now. [KICK*]
     *
     * @param integer $jobs max number of jobs to restore
     * @return integer number of jobs actualy restored
     */
    public function restore($jobs) {
        /// check for suspended (do not kick delayed jobs!)

        $this->send(sprintf('kick %d', $jobs));
        $status = strtok($this->receive(), ' ');

        switch ($status) {
            case 'KICKED':
                return (int)strtok(' ');
            default:
                throw new BeanstalkException("Error when restoring jobs: " . $status);
        }
    }


    /**
     * Reset the "time to run" of the job. [TOUCH]
     *
     * @param integer
     */
    public function touch($jobId) {
        $this->send(sprintf('touch %d', $jobId));
        $status = $this->receive();

        switch ($status) {
            case 'TOUCHED':
                return;
            case 'NOT_TOUCHED':
            default:
                throw new BeanstalkException("Error when touching a job: " . $status);
        }
    }


    /**
     * Watch queue. Jobs are claimed only from wathed queues. [WATCH]
     *
     * @param string
     */
    public function watchQueue($queue) {
        $this->send(sprintf('watch %s', $queue));
        $status = strtok($this->receive(), ' ');

        switch ($status) {
            case 'WATCHING':
                return;
            default:
                throw new BeanstalkException("Error when watching a queue: " . $status);
        }
    }


    /**
     * Ignore queue. Jobs are claimed only from wathed queues. [WATCH]
     *
     * @param string
     */
    public function ignoreQueue($queue) {
        $this->send(sprintf('ignore %s', $queue));
        $status = strtok($this->receive(), ' ');

        switch ($status) {
            case 'WATCHING':
                return;
            case 'NOT_IGNORED':
            default:
                throw new BeanstalkException("Error when ignoring a queue: " . $status);
        }
    }


    /**
     * Pause queue. No jobs from this queue will be assigned until the given time. [PAUSE-TUBE]
     *
     * @param string
     * @param integer|\DateTime seconds of delay or time to start
     */
    public function pauseQueue($queue, $delay) {
        if (!is_int($delay)) $delay = $this->delayToSeconds($delay);

        $this->send(sprintf('pause-tube %s %d', $queue, $delay));
        $status = strtok($this->receive(), ' ');

        switch ($status) {
            case 'WATCHING':
                return;
            case 'NOT_IGNORED':
            default:
                throw new BeanstalkException("Error when ignoring a queue: " . $status);
        }
    }


    // Show Commands --------------------------------------------------------------------------------------------------


    /**
     * Show a job. [PEEK]
     *
     * @param integer
     * @param boolean $stats with statistics
     * @return \Dogma\Queue\BeanstalkJob|null
     */
    public function showJob($jobId, $stats = false) {
        $this->send(sprintf('peek %d', $jobId));
        return $this->readJob($stats);
    }


    /**
     * Show the next ready job. [PEEK-READY]
     *
     * @param boolean $stats with statistics
     * @return \Dogma\Queue\BeanstalkJob|null
     */
    public function showNextReadyJob($stats = false) {
        $this->send('peek-ready');
        return $this->readJob($stats);
    }


    /**
     * Show the job with the shortest delay left. [PEEK-DELAYED]
     *
     * @param boolean $stats with statistics
     * @return \Dogma\Queue\BeanstalkJob|null
     */
    public function showNextDelayedJob($stats = false) {
        $this->send('peek-delayed');
        return $this->readJob($stats);
    }


    /**
     * Inspect the next job in the list of buried jobs. [PEEK-BURIED]
     *
     * @param boolean $stats with statistics
     * @return \Dogma\Queue\BeanstalkJob|null
     */
    public function showNextSuspendedJob($stats = false) {
        $this->send('peek-buried');
        return $this->readJob($stats);
    }


    /**
     * Handles response for all show methods.
     *
     * @param boolean $stats with statistics
     * @return \Dogma\Queue\BeanstalkJob|null
     */
    private function readJob($stats) {
        $status = strtok($this->receive(), ' ');

        switch ($status) {
            case 'FOUND':
                $id = (int)strtok(' ');
                $data = $this->unserializeJob($this->receive((int)strtok(' ')));
                break;
            case 'NOT_FOUND':
                return null;
            default:
                throw new BeanstalkException("Error when reading a job: " . $status);
        }

        if ($stats) {
            $st = $this->getJobStats($id);
        } else {
            $st = [];
        }

        return new BeanstalkJob($id, $data, false, $this, $st);
    }


    // Stats Commands --------------------------------------------------------------------------------------------------


    /**
     * Get statistical information about a job. [STATS-JOB]
     *
     * @param integer
     * @return array
     */
    public function getJobStats($jobId) {
        $this->send(sprintf('stats-job %d', $jobId));
        return $this->readStats();
    }


    /**
     * Get statistical information about a queue. [STATS-TUBE]
     *
     * @param string $queue name
     * @return array
     */
    public function getQueueStats($queue) {
        $this->send(sprintf('stats-tube %s', $queue));
        return $this->readStats();
    }


    /**
     * Get statistical information about the server. [STATS]
     *
     * @return array
     */
    public function getServerStats() {
        $this->send('stats');
        return $this->readStats();
    }


    /**
     * Get a list of all server queues. [LIST-TUBES]
     *
     * @return array
     */
    public function getQueues() {
        $this->send('list-tubes');
        return $this->readStats();
    }


    /**
     * Get selected queue. [LIST-TUBE-USED]
     *
     * @return string
     */
    public function getSelectedQueue() {
        $this->send('list-tube-used');
        return $this->readStats();
    }


    /**
     * Get list of fatched queues. [LIST-TUBES-WATCHED]
     *
     * @return array
     */
    public function getWatchedQueues() {
        $this->send('list-tubes-watched');
        return $this->readStats();
    }


    /**
     * Handles responses for all stat methods.
     *
     * @return array|string
     */
    private function readStats() {
        $status = strtok($this->receive(), ' ');

        switch ($status) {
            case 'OK':
                $response = $this->receive((int)strtok(' '));
                return $this->decodeYaml($response);
            default:
                throw new BeanstalkException("Error when reading stats: " . $status);
        }
    }


    /**
     * Decodes YAML data. This is a super naive decoder which just works on a
     * subset of YAML which is commonly returned by beanstalk.
     *
     * @param string $data Yaml list or dictionary
     * @return array
     */
    private function decodeYaml($data) {
        $data = array_slice(explode("\n", $data), 1);
        $result = [];

        foreach ($data as $key => $value) {
            if ($value[0] === '-') {
                $value = ltrim($value, '- ');

            } elseif (strpos($value, ':') !== false) {
                list($key, $value) = explode(':', $value);
                $value = ltrim($value, ' ');
            }
            if (is_numeric($value)) {
                $value = (int) $value == $value ? (int) $value : (float) $value;
            }
            $result[$key] = $value;
        }
        return $result;
    }

}
