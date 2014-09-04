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
stream_set_blocking ($stream, false);
stream_get_contents ($stream, 1024);
$mess = mask(json_encode(array('type' => 'usermsg', 'name' => 'alpha', 'color' => '0FC', 'message' => "Hello!")));
stream_write($stream, $mess);

usleep(10000);
$mess = mask(json_encode(array('type' => 'bye')));
stream_write($stream, $mess);
stream_get_contents ($stream, 1024);


//Encode message for transfer to client without masking.
function mask_without_mask($text)
{
	$b1 = 129;// first byte indicates FIN, Text-Frame (10000001):
	$length = strlen($text);
	
	if($length <= 125)
		$header = pack('CC', $b1, $length);
	elseif($length > 125 && $length < 65536)
		$header = pack('CCn', $b1, 126, $length);
	elseif($length >= 65536)
		$header = pack('CCNN', $b1, 127, $length);
	return $header.$text;
}

