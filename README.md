FMSocket.php
============

Easy to use wrapper for php socket functions.

Simple TCP Server:
```php
$server = FMSocket::TCPStreamServer();
$server->listen('localhost', '8192');
$server->queue(Socket::QUEUE_LISTEN, function($serverSocket, $clientSocket) {
    static $connections = 0;
    $connections++;
    echo "New Connection! Greeting..\n";
    $clientSocket->send("SEE YOU!\n");
    $clientSocket->close();
    if ($connections >= 3) {
        $serverSocket->close();
        echo "Also closing the server";
    }
});
echo "Waiting for connections ..\n";
FMSocket::Loop();
echo "Done.\n\n";
```

Simple Unix Domain Sockets Server:
```php
// Just change the listening part
$server = FMSocket::UnixSocketServer();
$server->listen('/Users/furkan/test.sock');
```

### TODO
 - [X] Basic implementation for multiple TCP/UDS connections with callbacks
 - [ ] Libev Support
 - [ ] Pipelining
```php
    $parser = new HTTPParser();
    $socket->pipe($parser);
```
 
### LICENSE

GPLv3, See [LICENSE](LICENSE)

