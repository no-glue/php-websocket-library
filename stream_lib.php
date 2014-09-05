<?php

function stream_write($stream, $data, $length = 0, $timeout = 3) {
    if(!$length) $length = strlen($data);
    $expire = time() + $timeout;
    $written = 0;
    while (($sent = fwrite($stream, substr($data, $written, $length), $length - $written)) && ($written += $sent) && $written < $length && time() < $expire);
    return $sent ? $written : false;
}

function stream_read($stream, $length, $timeout = 2) {
    $data = '';
    $expire = time() + $timeout;
    while (strlen($data .= stream_get_contents($stream, $length)) < $length && time() < $expire);
    return $data ? $data : false;
}

#setup and listen to a tcp IP/port, returning the socket stream
function setupTcpStreamServer($bindTo, $pemfile, $pem_passphrase = '') {
    
    if (!(is_readable($pemfile) && filesize($pemfile))) {   
        global $host;
        createCert($pemfile, $pem_passphrase, $host);
    }
    
    #create a stream context for our SSL settings
    $context = stream_context_create();

    #Setup the SSL Options
    stream_context_set_option($context, 'ssl', 'local_cert', $pemfile);     // Our SSL Cert in PEM format
    stream_context_set_option($context, 'ssl', 'passphrase', $pem_passphrase);  // Private key Password
    
    // В этом нет необходимости, нужно только для клиентов
    // stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
    // stream_context_set_option($context, 'ssl', 'verify_peer', false);

    #create a stream socket on IP:Port
    $socket = stream_socket_server("tcp://{$bindTo}", $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $context);
    stream_socket_enable_crypto($socket, false);

    return $socket;
}

// Создаем самоподписанный сертификат со случайным серийным номером
function createCert($pemfile, $pem_passphrase, $host = null){

    // Certificate data:
    $dn = array(                        // The following array of data is needed to generate the SSL Cert
        "countryName" => "UK",          // Set your country name
        "stateOrProvinceName" => "Somerset",
        "localityName" => "Glastonbury",// Ser your city name
        "organizationName" => "The Brain Room Limited",
        "organizationalUnitName" => "PHP Documentation Team",
        "commonName" => $host ? $host : "Wez Furlong" ,  // Set your full hostname. e.g demo.example.com
        "emailAddress" => "wez@example.com",
        "serialNumber" => mt_rand(0, 100000),
    );

    $config = array(
        "digest_alg" => "sha512",
        "private_key_bits" => 4096,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    );
    
    // Generate certificate
    $privkey = openssl_pkey_new($config);
    $cert    = openssl_csr_new($dn, $privkey);
    $cert    = openssl_csr_sign($cert, null, $privkey, 365, $config, mt_rand(0, 100000));

    // Generate PEM file
    $pem = array();
    openssl_x509_export($cert, $pem[0]);
    openssl_pkey_export($privkey, $pem[1], $pem_passphrase, $config);
    $pem = implode($pem);
    if (!$pem) { print("Cannot generate a certificate\n"); die(2); }
    // Save PEM file
    file_put_contents($pemfile, $pem);
    
    return $pemfile;
}