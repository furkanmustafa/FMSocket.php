<?php

class Socket {

    public $resource;
    static $Sockets = [];

    static $Queue = [];

    public $mode = null;
    public $domain = null;
    public $type = null;
    public $protocol = null;

    public $address = null;
    // for MODE_CLIENT Use:
    public $localPort = 0;
    public $remotePort = 0;
    // for MODE_LISTENER Use:
    public $port = 0;
    
    public $localSocket = null;

    const MODE_LISTENER     = 'listen';
    const MODE_CLIENT       = 'client';

    const QUEUE_READ        = 'read';
    const QUEUE_LISTEN      = 'listen';
    const QUEUE_WRITE       = 'write';

    function __construct($mode, $domainOrSocket, $type = null, $protocol = null) {
        if (!extension_loaded('sockets')) {
            throw new Exception('The sockets extension is not loaded.');
        }
        
        if (!$domainOrSocket) {
            throw new Exception('Cannot Create Socket');
        }
        
        $this->mode = $mode;
        
        if (is_resource($domainOrSocket)) {
            $this->resource = $domainOrSocket;
            return;
        }
        
        $this->domain = $domainOrSocket;
        $this->type = $type;
        $this->protocol = $protocol;
        
        $this->resource = socket_create($domainOrSocket, $type, $protocol);
        if (!$this->resource) {
            throw new Exception('Cannot Create Socket');
        }
        
        if ($this->mode == self::MODE_LISTENER) {
            $this->setReusable(true);
        }
    }
    
    static function TCPStreamServer() {
        return new FMSocket(self::MODE_LISTENER, AF_INET, SOCK_STREAM, SOL_TCP);
    }
    static function TCPStreamClient() {
        return new FMSocket(self::MODE_CLIENT, AF_INET, SOCK_STREAM, SOL_TCP);
    }
    static function UnixSocketServer() {
        return new FMSocket(self::MODE_LISTENER, AF_UNIX, SOCK_STREAM, 0);
    }
    static function UnixSocketClient() {
        return new FMSocket(self::MODE_CLIENT, AF_UNIX, SOCK_STREAM, 0);
    }

    function setOption($level, $optname, $optval) {
        return socket_set_option($this->resource, $level, $optname, $optval);
    }
    function getOption($level, $optname) {
        return socket_get_option($this->resource, $level, $optname);
    }
    function setReusable($reusable = true) {
        return $this->setOption(SOL_SOCKET, SO_REUSEADDR, $reusable ? 1 : 0);
    }
    function setBlocking($blocking = true) {
        if ($blocking) {
            return socket_set_block($this->resource);
        } else {
            return socket_set_nonblock($this->resource);
        }
    }

    function connect($address, $port = 0) {
        if ($port === 0 && strpos($address, ':') !== false) {
            list($address, $port) = explode(':', $address);
        }
        return socket_connect($this->resource, $address, $port);
    }

    function listen($address = 'localhost', $port = 0) {
        if (!socket_bind($this->resource, $address, $port))
            return false;
        if (!socket_listen($this->resource))
            return false;
        
        $this->port = $port;
        
        $this->listener = true;
        self::$Queue[] = [
            'mode' => 'listen',
            'socket' => $this
        ];
        return true;
    }

    function listenPort($port) {
        return $this->listen('localhost', $port);
    }

    function listenSock($path) {
        return $this->listen($path);
    }

    function read($length = 4096) {
        return socket_read($this->resource, $length, PHP_BINARY_READ);
    }
    
    function readline($length = 4096) {
        return socket_read($this->resource, $length, PHP_NORMAL_READ);
    }
    
    function write($string, $length = 0) {
        return socket_write($this->resource, $buffer, $length);
    }
    
    // MSG_OOB              Process out-of-band data.
    // MSG_PEEK             Receive data from the beginning of the receive queue without removing it from the queue.
    // MSG_WAITALL          Block until at least len are received. However, if a signal is caught or the remote host disconnects, the function may return less data.
    // MSG_DONTWAIT         With this flag set, the function returns even if it would normally have blocked.
    
    function receive(&$buf, $len = 4096, $flags = 0) {
        $result = socket_recv($this->resource, $buf, $len, $flags);
    }
    
    // MSG_OOB              Send OOB (out-of-band) data.
    // MSG_EOR              Indicate a record mark. The sent data completes the record.
    // MSG_EOF              Close the sender side of the socket and include an appropriate notification of this at the end of the sent data. The sent data completes the transaction.
    // MSG_DONTROUTE        Bypass routing, use direct interface.
    
    function send($buf, $len = null, $flags = 0) {
        if ($len === null)
            $len = strlen($buf);
        
        return socket_send($this->resource, $buf, $len, $flags);
    }
    
    function sendTo($address, $buf, $len = null, $flags = 0, $port = 0) {
        if ($len === null)
            $len = strlen($buf);
        
        if ($port === 0 && strpos($address, ':') !== false) {
            list($address, $port) = explode(':', $address);
        }
        
        return socket_sendto($this->resource, $buf, $len, $flags, $address, $port);
    }
    
    function accept() {
        $clientSocketResource = socket_accept($this->resource);
        $clientSocket = new FMSocket(self::MODE_CLIENT, $clientSocketResource);
        
        if ($this->type != AF_UNIX) {
            socket_getpeername($clientSocketResource, $clientSocket->address, $clientSocket->remotePort);
        }
        $clientSocket->localPort = $this->port;
        $clientSocket->type = $this->type;
        $clientSocket->protocol = $this->protocol;
        $clientSocket->localSocket = $this;
        return $clientSocket;
    }
    
    function shutdown($how) {
        return socket_shutdown($this->resource, $how);
    }
    
    function close() {
        $this->dequeue();
        return socket_close($this->resource);
    }
    
    function queue($mode, $callback, $extra = []) {
        static $QueueCounter = 0;
        
        // TODO : 
        if ($mode == self::QUEUE_READ) {
            // test if there is data available first, call the callback right away if there is data ..
            // or do it in the loop before select maybe.. 
            // $n = socket_recv($socket, $tmp, 1024, 0); // MSG_PEEK ?
        }
        
        self::$Queue[$QueueCounter] = [
            'mode' => $mode,
            'callback' => $callback,
            'socket' => $this,
            'extra' => $extra,
            'id' => $QueueCounter
        ];
        $QueueCounter++;
        if ($QueueCounter > 16384)
            $QueueCounter = 0;
    }

    function dequeue() {
        // Dequeue All Async actions
        foreach (self::$Queue as $queueInfo) {
            if ($queueInfo['socket'] == $this) {
                unset(self::$Queue[$queueInfo['id']]);
            }
        }
    }

    static function DequeueItem($id) {
        unset(self::$Queue[$id]);
    }
    
    static function SearchQueueWithSock($resource) {
        foreach (self::$Queue as $queueItem) {
            if ($queueItem['socket']->resource != $resource) continue;
            return $queueItem;
        }
        return null;
    }
    
    static function Loop() {
        while (count(self::$Queue)) {
            
            $readSockets = [];
            $writeSockets = [];
            $exceptionSockets = NULL;
            $anySocket = [];
            
            foreach (self::$Queue as $queueItem) {
                if (in_array($queueItem['socket']->resource, $anySocket)) continue; // next run.
                if ($queueItem['mode'] == self::QUEUE_READ)
                    $readSockets[] = $queueItem['socket']->resource;
                if ($queueItem['mode'] == self::QUEUE_LISTEN)
                    $readSockets[] = $queueItem['socket']->resource;
                if ($queueItem['mode'] == self::QUEUE_WRITE)
                    $writeSockets[] = $queueItem['socket']->resource;
                
                $anySocket[] = $queueItem['socket']->resource;
            }
            
            if (socket_select($readSockets, $writeSockets, $exceptionSockets, NULL) < 1)
                continue;
            
            foreach (array_merge($readSockets, $writeSockets) as $sockResource) {
                $queueItem = self::SearchQueueWithSock($sockResource);
                if (!$queueItem) {
                    throw new Exception('This is quite strange, Cannot find the queue item');
                }
                
                if ($queueItem['mode'] == self::QUEUE_LISTEN) {
                    $clientSocket = $queueItem['socket']->accept();
                    $queueItem['callback']($queueItem['socket'], $clientSocket);
                } else {
                    $queueItem['callback']($queueItem['socket']);
                    self::DequeueItem($queueItem['id']);
                }
                
                
            }
        }
    }
}
