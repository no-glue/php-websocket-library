<?php
// WebSocket Server and Client library
// Depends on http_lib.php   - receive_http_head(), fetch_http_headers()
//            stream_lib.php - stream_write(), stream_read()

/**
 *  Reads a websoket frame from given stream
 */
function receive_ws_frame($stream, $decode = true, &$raw_frame = '', &$is_fin = false, &$opcode = 1) {
    $timeout = 2; // hardcoded timeout
    $header = stream_read($stream, 2, $timeout);
    
    if (strlen($header) < 2) {
        trigger_error('Cannot read frame header', E_USER_WARNING);
        return false;
    }
    
    extract(unpack('Cfin_rsv_opcode/Cismask_length', $header), EXTR_OVERWRITE);
    
    // http://learn.javascript.ru/websockets#%D0%BE%D0%BF%D0%B8%D1%81%D0%B0%D0%BD%D0%B8%D0%B5-%D1%84%D1%80%D0%B5%D0%B9%D0%BC%D0%B0
    $is_fin  = $fin_rsv_opcode & 0x80 ? 1 : 0;
    $rsv_1   = $fin_rsv_opcode & 0x40 ? 1 : 0;
    $rsv_2   = $fin_rsv_opcode & 0x20 ? 1 : 0;
    $rsv_3   = $fin_rsv_opcode & 0x10 ? 1 : 0;
    $opcode  = $fin_rsv_opcode & 0x0F;
    $length  = $ismask_length  & 0x7F;
    $is_mask = $ismask_length  >> 7;
    
    // Получаем дополнительные байты длины, если они должны быть ( $length > 125 )
    // И заменяем значение $length
    $lenstr = '';
    if($length == 126) 
    {
        $lenstr = stream_read($stream, 2, $timeout);
        extract(unpack('nlength', $lenstr), EXTR_OVERWRITE);
    }
    elseif($length == 127) 
    {
        $lenstr = stream_read($stream, 8, $timeout);;
        extract(unpack('Nlength_big/Nlength', $lenstr), EXTR_OVERWRITE);
        // Если php 64-разрядный, то обретаем возможность передавать эксабайты данных 
        if ($length_big && PHP_INT_MAX > 2147483647) {
            $length = $length_big << 32 + $length;
        }
    }
    
    $masks = '';
    if ($is_mask) 
    {
        $masks = stream_read($stream, 4, $timeout);
    }
    
    $payload   = stream_read($stream, $length, $timeout);
    $raw_frame = $header.$lenstr.$masks.$payload;
    if (!$decode) return $raw_frame;
    if ($is_mask) 
    {
        $text = '';
        for ($i = 0; $i < strlen($payload); ++$i) {
            $text .= $payload[$i] ^ $masks[$i%4];
        }
        return $text;
    }
    else {
        return $payload;
    }
}

function transmit_ws_frame($stream, $data, $encode = true, $type = 'text', $mask = false) {
    if ($encode) $data = mask($data, $type, $mask);
    return stream_write($stream, $data);
}


//Unmask incoming framed text message
function unmask($text) {
    $length  = ord($text[1]) & 127;
    $is_mask = ord($text[1]) & 128;
    $mask = '\x00\x00\x00\x00';
    if($length == 126) {
        if ($is_mask) $masks = substr($text, 4, 4);
        $data = substr($text, $is_mask ? 8 : 4);
    }
    elseif($length == 127) {
        if ($is_mask) $masks = substr($text, 10, 4);
        $data  = substr($text, $is_mask ? 14 : 10);
    }
    else {
        if ($is_mask) $masks = substr($text, 2, 4);
        $data  = substr($text, $is_mask ? 6 : 2);
    }
    $text = "";
    for ($i = 0; $i < strlen($data); ++$i) {
        $text .= $data[$i] ^ $masks[$i%4];
    }
    return $text;
}

// Encode message for transfer to client.
// https://github.com/lemmingzshadow/php-websocket/blob/master/client/lib/class.websocket_client.php
// https://github.com/varspool/Wrench/blob/master/lib/Wrench/Frame/HybiFrame.php
// Remember, a server must not mask any frames that it sends to the client. 
function mask($payload, $type = 'text', $masked = false)
{
    $frameHead = array();
    $frame = '';
    $payloadLength = strlen($payload);
    
    switch($type)
    {       
        case 'text':
            // first byte indicates FIN, Text-Frame (10000001):
            $frameHead[0] = 129;                
        break;          
    
        case 'close':
            // first byte indicates FIN, Close Frame(10001000):
            $frameHead[0] = 136;
        break;
    
        case 'ping':
            // first byte indicates FIN, Ping frame (10001001):
            $frameHead[0] = 137;
        break;
    
        case 'pong':
            // first byte indicates FIN, Pong frame (10001010):
            $frameHead[0] = 138;
        break;
    }
    
    // set mask and payload length (using 1, 3 or 9 bytes) 
    if($payloadLength > 65535)
    {
        $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
        $frameHead[1] = ($masked === true) ? 255 : 127;
        for($i = 0; $i < 8; $i++)
        {
            $frameHead[$i+2] = bindec($payloadLengthBin[$i]);
        }
        // most significant bit MUST be 0 (close connection if frame too big)
        if($frameHead[2] > 127)
        {
            fclose ($stream);
            return false;
        }
    }
    elseif($payloadLength > 125)
    {
        $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
        $frameHead[1] = ($masked === true) ? 254 : 126;
        $frameHead[2] = bindec($payloadLengthBin[0]);
        $frameHead[3] = bindec($payloadLengthBin[1]);
    }
    else
    {
        $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
    }

    // convert frame-head to string:
    foreach(array_keys($frameHead) as $i)
    {
        $frameHead[$i] = chr($frameHead[$i]);
    }
    if($masked === true)
    {
        // generate a random mask:
        $mask = array();
        for($i = 0; $i < 4; $i++)
        {
            $mask[$i] = chr(rand(0, 255));
        }
        
        $frameHead = array_merge($frameHead, $mask);            
    }                       
    $frame = implode('', $frameHead);

    // append payload to frame:
    $framePayload = array();    
    for($i = 0; $i < $payloadLength; $i++)
    {       
        $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
    }

    return $frame;
}


//handshake new client.
function perform_handshaking($stream, $host, $port)
{
    $meta    = stream_get_meta_data($stream);    
    $headers = fetch_http_headers(receive_http_head($stream));
    
    if (empty($headers['Sec-WebSocket-Key'])) {
        if (function_exists('no_sec_key_response')) {
            stream_write($stream, no_sec_key_response($stream, $host, $port));
            usleep(100);
        }
        return false;
    }
    
    $secKey = $headers['Sec-WebSocket-Key'];
    $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    //hand shaking header
    $upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
    "Upgrade: websocket\r\n" .
    "Connection: Upgrade\r\n" .
    "WebSocket-Origin: $host\r\n" .
    "WebSocket-Location: ws".(strpos($meta["stream_type"], "ssl") !== false ? 's' : '')."://$host:$port/retranslator\r\n".
    "Sec-WebSocket-Accept: $secAccept\r\n\r\n";
    return stream_write($stream, $upgrade);
}
