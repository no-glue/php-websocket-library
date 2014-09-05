<?php
$host = '127.0.0.2'; //host
$port = '8000'; //port
$null = NULL; //null var

require (__DIR__.DIRECTORY_SEPARATOR.'http_lib.php');
require (__DIR__.DIRECTORY_SEPARATOR.'ws_lib.php');
require (__DIR__.DIRECTORY_SEPARATOR.'stream_lib.php');

$context = stream_context_create();
stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
stream_context_set_option($context, 'ssl', 'verify_peer', false);

$stream = stream_socket_client ("tls://$host:$port", $errno, $errstr, 2, STREAM_CLIENT_CONNECT, $context);

fwrite ($stream, "Sec-WebSocket-Key: ".($r = rand(1,99999))."\r\n\r\n");

usleep(1000);
$headers = fetch_http_headers(receive_http_head($stream));
if ($headers['Sec-WebSocket-Accept'] == base64_encode(pack('H*', sha1($r . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')))) echo "handshake ok\n";

$mess = mask(json_encode(array('type' => 'auth', 'name' => 'alpha', 'color' => '0FC', 'message' => 'sender')));
stream_write($stream, $mess);

usleep(10000);
$meta = stream_get_meta_data ($stream);
if ($meta['unread_bytes']) stream_read($stream, $meta['unread_bytes']);
$mess = mask(json_encode(array('type' => 'usermsg', 'name' => 'alpha', 'color' => '0FC', 'message' => "Hello!")));
stream_write($stream, $mess);

usleep(10000);
$meta = stream_get_meta_data ($stream);
if ($meta['unread_bytes']) stream_read($stream, $meta['unread_bytes']);
$mess = mask(json_encode(array('type' => 'bye')));
stream_write($stream, $mess);


