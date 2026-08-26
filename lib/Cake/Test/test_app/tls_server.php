<?php
/**
 * TLS server fixture
 *
 * Helper for HttpSocketTest::testVerifyPeer(). Starts a TLS server on
 * 127.0.0.1 using a runtime-generated self-signed certificate, so that
 * peer verification deterministically fails when a client connects to it.
 *
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @package       Cake.Test.TestApp
 * @since         CakePHP(tm) v 2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

$pkey = openssl_pkey_new(array(
	'private_key_bits' => 2048,
	'private_key_type' => OPENSSL_KEYTYPE_RSA,
));
$csr = openssl_csr_new(array('commonName' => 'localhost'), $pkey);
$cert = openssl_csr_sign($csr, null, $pkey, 1, array('digest_alg' => 'sha256'));

openssl_x509_export($cert, $certPem);
openssl_pkey_export($pkey, $keyPem);

$pemFile = tempnam(sys_get_temp_dir(), 'cake_tls_');
file_put_contents($pemFile, $certPem . $keyPem);

$context = stream_context_create(array(
	'ssl' => array(
		'local_cert' => $pemFile,
	),
));
$server = @stream_socket_server(
	'tls://127.0.0.1:0',
	$errNo,
	$errStr,
	STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
	$context
);
if ($server === false) {
	unlink($pemFile);
	fwrite(STDERR, $errStr . "\n");
	exit(1);
}

$name = stream_socket_get_name($server, false);
$port = substr($name, strrpos($name, ':') + 1);
fwrite(STDOUT, $port . "\n");
fflush(STDOUT);

$conn = @stream_socket_accept($server, 10);
if (is_resource($conn)) {
	fclose($conn);
}

unlink($pemFile);
exit(0);
