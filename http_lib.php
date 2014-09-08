<?php

function receive_http_head($stream) {
    if (!is_resource($stream))
    {    
        trigger_error('Invalid resource given', E_USER_WARNING);
        return false;
    }
    $expire = time() + 2;  // hardcoded timeout
    stream_set_timeout($stream, 1);
    $header = "";
    // Read until double CRLF
    while( !preg_match('/\r?\n\r?\n/', $header) && time() < $expire ) 
    {
        $header .= fgets($stream, 8192);
    }
    if (time() >= $expire) trigger_error('Timeout');
    if (!strpos($header, "\r\n")) {
        trigger_error( ' got '.$header."\n" );
        return false;
    }
    return $header;
}

function fetch_http_headers($receved_header) {
    $headers = array();
    $lines = preg_split("/\r\n/", $receved_header);
    foreach($lines as $line)
    {
        $line = chop($line);
        if(preg_match('/\A(\S+): ?(.*)\z/', $line, $matches))
        {
            $headers[$matches[1]] = $matches[2];
        }
    }
    return $headers;
}
