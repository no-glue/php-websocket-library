<?php
$host    = 'localhost';
$bind_ip = '0.0.0.0';
$port    = '8000';
$null    = NULL;
date_default_timezone_set('Europe/Moscow');
require (__DIR__.DIRECTORY_SEPARATOR.'http_lib.php');
require (__DIR__.DIRECTORY_SEPARATOR.'ws_lib.php');
require (__DIR__.DIRECTORY_SEPARATOR.'stream_lib.php');

//Create TCP/IP sream socket
$main_stream = $stream = setupTcpStreamServer($bind_ip.':'.$port, __DIR__.DIRECTORY_SEPARATOR."local.pem");

// Отображать статус?
$status_display = 1;

// create & add listning socket to the list
$clients   = array($stream);
$receivers = array();
$senders   = array();
$secure_only  = 1;
$require_auth = 0;

// start endless loop, so that our script doesn't stop
$cnt = 0;
while (true) {
    // manage multipal connections
    $changed = $clients;
    // Make a tick every second and returns the socket resources in $changed array
    stream_select($changed, $null, $null, 1, 0);
    
    if ($cnt++ > 999999) $cnt = 0;
    if ($status_display) {
        $ccnt = count($changed);
        if ($cnt %  10 == 0 || $ccnt) echo '.'.($ccnt ? $ccnt : '');
        if ($cnt % 500 == 0) echo ' We have '.(count($clients) - 1)." client(s)\n";
    }
    
    //check for new socket
    if ($changed && in_array($stream, $changed)) {
        $stream_new = stream_socket_accept($stream); //accpet new socket
        stream_set_blocking ($stream_new, true); // block the connection until SSL is done.
        $i = array_search($stream, $changed);
        
        if ($secure_only && !stream_socket_enable_crypto($stream_new, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
            drop_client($stream_new);
        }
        elseif (is_resource($stream_new)) 
        {
            
            if(!perform_handshaking($stream_new, $host, $port)) {//perform websocket handshake
                unset($changed[$i]);
                fclose($stream_new);
                continue;
            }
            $clients[] = $stream_new; //add socket to client array
            stream_set_blocking ($stream_new, false);
            $ip = stream_socket_get_name( $stream_new, true);
            send_message(array('type'=>'system', 'message'=>$ip.' connected')); //notify all receivers about new connection
        }
        //make room for new socket
        unset($changed[$i]);
    }
    
    //loop through all connected sockets
    foreach ($changed as $i => $changed_stream) {   
        
        if(!is_resource($changed_stream)) {
            drop_client($changed_stream);
            continue;
        }
        
        $found_key = array_search($changed_stream, $clients);
        $received_text = '';
        //check for any incomming data frames
        while(($decoded_frame = receive_ws_frame($changed_stream, true, $raw, $is_final)) && ($received_text .= $decoded_frame) && !$is_final);
        
        $tst_msg = json_decode($received_text, true);
        if (is_null($tst_msg)) {
            // TODO - добавить определение закрытия соединения клиентом  http://learn.javascript.ru/websockets#%D1%87%D0%B8%D1%81%D1%82%D0%BE%D0%B5-%D0%B7%D0%B0%D0%BA%D1%80%D1%8B%D1%82%D0%B8%D0%B5
            if ($raw) echo "Unknown message $received_text ".bin2hex($received_text)." got, closing connection \n";
            drop_client($changed_stream);
            unset($changed[$i]);
            continue(1);
        }
        // print_r($tst_msg);
        
        if (!empty($tst_msg['type']) and $tst_msg['type'] == 'bye') {
            drop_client($changed_stream);
            continue(1);
        }
        
        /* if ($tst_msg['type'] == 'auth') */ perform_auth($tst_msg, $changed_stream);
        if ($require_auth && empty($senders[$found_key])) continue(1);
        
        //prepare data to be sent to client
        $tst_msg['type'] = 'usermsg';
        send_message($tst_msg); //send data
        
        if (!$raw) { // check disconnected client
            drop_client($changed_stream);
        }
    }
}

function perform_auth($msg_arr, $stream) {
    if (empty($msg_arr['message'])) return false;
    global $clients;
    global $receivers;
    global $senders;  
    $meta = stream_get_meta_data($stream); // ["stream_type"] => "tcp_socket/ssl" для wss
    $mess = $msg_arr['message'];
    $found_key = array_search($stream, $clients);
    
    if ($msg_arr['message'] == 'receiver') 
    {
        return $receivers[$found_key] = array_merge(empty($receivers[$found_key]) ? array() : $receivers[$found_key], $msg_arr);
    } 
    elseif ($msg_arr['message'] == 'sender') 
    {
        return $senders[$found_key] = array_merge(empty($senders[$found_key]) ? array() : $senders[$found_key], $msg_arr);
    }
    
    return false;
        
}

function drop_client($changed_stream, $no_notify = false)
{
    global $clients;
    global $receivers;
    global $senders;
    global $main_stream;
    // remove client for $clients array
    $found_key = array_search($changed_stream, $clients);
    $ip = stream_socket_get_name($changed_stream, true);
    // stream_socket_shutdown($changed_stream, STREAM_SHUT_RDWR);
    if(is_resource($changed_stream)) fclose($changed_stream);
    unset($clients[$found_key]);
    unset($receivers[$found_key]);
    unset($senders[$found_key]);
    // TODO - разобраться, как такое может быть
    if (empty($clients)) $clients = array($main_stream);
    
    //notify all users about disconnected connection
    $no_notify || send_message(array('type'=>'system', 'message'=>$ip.' disconnected'));
}


function send_message($msg_arr, $stream = null)
{
    global $receivers;
    global $require_auth;
    if ($stream) 
    {
        $clients = array($stream);
    } 
    else
    {
        global $clients;
    }
    
    foreach($clients as $i => $changed_stream)
    {
        
        if (!$stream && $require_auth && empty($receivers[$i])) continue;
        
        transmit_ws_frame ($changed_stream, json_encode($msg_arr));
    }
    return true;
}

function no_sec_key_response() {
    $html = file_get_contents(__DIR__.DIRECTORY_SEPARATOR.'index.html');
    return "HTTP/1.1 200 OK\r\n" .
           "Content-Type: text/html; charset=utf-8\r\n" .
           "Connection: Close\r\n" .
           "Content-Length: ".strlen($html)."\r\n" .
           "\r\n".$html;
}

