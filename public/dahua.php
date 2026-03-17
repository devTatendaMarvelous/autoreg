<?php
// Force clear any output buffers
ob_clean();

// Set headers exactly as the Dahua guide requires
header("HTTP/1.1 200 OK");
header("Connection: keep-alive");
header("Content-Length: 0");
header("Content-Type: text/plain");

// Log the hit manually
file_put_contents('dahua_log.txt', "Connect hit at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

exit;
