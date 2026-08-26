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

$server = null;
$exitCode = 0;

try {
	if (!isset($argv[1], $argv[2])) {
		throw new RuntimeException('TLS fixture temporary file paths were not provided.');
	}
	$configFile = $argv[1];
	$pemFile = $argv[2];

	$config = "[ req ]\n" .
		"distinguished_name = req_distinguished_name\n" .
		"req_extensions = v3_req\n" .
		"prompt = no\n" .
		"\n" .
		"[ req_distinguished_name ]\n" .
		"CN = 127.0.0.1\n" .
		"\n" .
		"[ v3_req ]\n" .
		"basicConstraints = CA:FALSE\n" .
		"keyUsage = critical, digitalSignature, keyEncipherment\n" .
		"extendedKeyUsage = serverAuth\n" .
		"subjectAltName = IP:127.0.0.1\n";
	if (file_put_contents($configFile, $config) === false) {
		throw new RuntimeException('Unable to write the TLS fixture OpenSSL config file.');
	}

	$pkey = openssl_pkey_new(array(
		'config' => $configFile,
		'private_key_bits' => 2048,
		'private_key_type' => OPENSSL_KEYTYPE_RSA,
	));
	if ($pkey === false) {
		throw new RuntimeException('Unable to generate the TLS fixture private key.');
	}

	$csr = openssl_csr_new(
		array('commonName' => '127.0.0.1'),
		$pkey,
		array(
			'config' => $configFile,
			'digest_alg' => 'sha256',
			'req_extensions' => 'v3_req',
		)
	);
	if ($csr === false) {
		throw new RuntimeException('Unable to generate the TLS fixture certificate request.');
	}

	$cert = openssl_csr_sign(
		$csr,
		null,
		$pkey,
		1,
		array(
			'config' => $configFile,
			'digest_alg' => 'sha256',
			'x509_extensions' => 'v3_req',
		)
	);
	if ($cert === false) {
		throw new RuntimeException('Unable to sign the TLS fixture certificate.');
	}

	if (!openssl_x509_export($cert, $certPem) || !openssl_pkey_export($pkey, $keyPem)) {
		throw new RuntimeException('Unable to export the TLS fixture certificate.');
	}

	if (file_put_contents($pemFile, $certPem . $keyPem) === false) {
		throw new RuntimeException('Unable to write the TLS fixture certificate.');
	}

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
		throw new RuntimeException('Unable to start the TLS fixture server: ' . $errStr);
	}

	$name = stream_socket_get_name($server, false);
	if ($name === false) {
		throw new RuntimeException('Unable to read the TLS fixture server address.');
	}
	$port = substr($name, strrpos($name, ':') + 1);
	$startupInfo = json_encode(array(
		'port' => (int)$port,
	));
	if ($startupInfo === false) {
		throw new RuntimeException('Unable to encode the TLS fixture startup information.');
	}
	fwrite(STDOUT, $startupInfo . "\n");
	fflush(STDOUT);

	for ($i = 0; $i < 2; $i++) {
		$conn = @stream_socket_accept($server, 10);
		if (is_resource($conn)) {
			stream_set_timeout($conn, 10);
			$request = '';
			while (!feof($conn) && strpos($request, "\r\n\r\n") === false) {
				$chunk = fread($conn, 1024);
				if ($chunk === false) {
					break;
				}
				$request .= $chunk;
			}
			fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
			fflush($conn);
			fclose($conn);
		}
	}
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	$exitCode = 1;
} finally {
	if (is_resource($server)) {
		fclose($server);
	}
}

exit($exitCode);
