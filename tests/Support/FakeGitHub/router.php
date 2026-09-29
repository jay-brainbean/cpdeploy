<?php

declare(strict_types=1);

/*
 * Fake GitHub REST API for tests (§16.2), run with `php -S … router.php`.
 * State lives in <docroot>/state.json; every request is appended to <docroot>/calls.jsonl.
 *
 * Special tokens: "expired" → 401, "ratelimited" → 403 + x-ratelimit-remaining: 0,
 * "boom" → 500. Repos listed in state.noAdmin answer 403 on /keys.
 */

$root = (string) $_SERVER['DOCUMENT_ROOT'];
$stateFile = $root . '/state.json';
$state = json_decode((string) file_get_contents($stateFile), true);
$method = $_SERVER['REQUEST_METHOD'];
$uri = (string) $_SERVER['REQUEST_URI'];
$path = (string) parse_url($uri, PHP_URL_PATH);
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$body = (string) file_get_contents('php://input');

file_put_contents($root . '/calls.jsonl', json_encode([
    'method' => $method,
    'path' => $path,
    'query' => $query,
    'auth' => $auth,
    'accept' => $_SERVER['HTTP_ACCEPT'] ?? null,
    'api_version' => $_SERVER['HTTP_X_GITHUB_API_VERSION'] ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'body' => $body === '' ? null : json_decode($body, true),
]) . "\n", FILE_APPEND);

$send = static function (int $status, mixed $data, array $headers = []): void {
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
};
$save = static function () use (&$state, $stateFile): void {
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
};

if (($_SERVER['HTTP_ACCEPT'] ?? '') !== 'application/vnd.github+json' || ($_SERVER['HTTP_X_GITHUB_API_VERSION'] ?? '') !== '2022-11-28') {
    $send(400, ['message' => 'Missing Accept or X-GitHub-Api-Version header']);

    return;
}

if ($path === '/meta') {
    $send(200, ['ssh_keys' => $state['meta_ssh_keys'] ?? []]);

    return;
}

$token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
if ($token === 'boom') {
    $send(500, ['message' => 'Server Error']);

    return;
}
if ($token === 'ratelimited') {
    $send(403, ['message' => 'API rate limit exceeded'], ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1790683200']);

    return;
}
$user = $state['tokens'][$token] ?? null;
if ($user === null) {
    $send(401, ['message' => 'Bad credentials']);

    return;
}

$paginate = static function (array $items) use ($query, $path): array {
    $per = (int) ($query['per_page'] ?? 30);
    $page = max(1, (int) ($query['page'] ?? 1));
    $slice = array_slice($items, ($page - 1) * $per, $per);
    $headers = [];
    if (count($items) > $page * $per) {
        $next = $query;
        $next['page'] = $page + 1;
        $headers['Link'] = '<http://' . $_SERVER['HTTP_HOST'] . $path . '?' . http_build_query($next) . '>; rel="next"';
    }

    return [$slice, $headers];
};

if ($path === '/user') {
    $headers = isset($user['expires']) ? ['github-authentication-token-expiration' => $user['expires']] : [];
    $send(200, ['login' => $user['login']], $headers);

    return;
}
if ($path === '/user/repos') {
    $repos = array_map(static fn ($r) => array_diff_key($r, ['branches' => 1]), $state['repos']);
    [$slice, $headers] = $paginate($repos);
    $send(200, $slice, $headers);

    return;
}

if (preg_match('#^/repos/([^/]+)/([^/]+)(/.*)?$#', $path, $m) === 1) {
    $full = $m[1] . '/' . $m[2];
    $rest = $m[3] ?? '';
    $repo = null;
    foreach ($state['repos'] as $r) {
        if ($r['full_name'] === $full) {
            $repo = $r;
        }
    }
    if ($repo === null) {
        $send(404, ['message' => 'Not Found']);

        return;
    }
    if ($rest === '') {
        $send(200, array_diff_key($repo, ['branches' => 1]));

        return;
    }
    if ($rest === '/branches') {
        [$slice, $headers] = $paginate(array_map(static fn ($b) => ['name' => $b], $repo['branches']));
        $send(200, $slice, $headers);

        return;
    }
    if (str_starts_with($rest, '/keys')) {
        if (in_array($full, $state['noAdmin'] ?? [], true)) {
            $send(403, ['message' => 'Resource not accessible by personal access token']);

            return;
        }
        $keys = $state['keys'][$full] ?? [];
        if ($rest === '/keys' && $method === 'GET') {
            $send(200, $keys);

            return;
        }
        if ($rest === '/keys' && $method === 'POST') {
            $in = json_decode($body, true);
            if (($in['read_only'] ?? null) !== true) {
                $send(400, ['message' => 'cpdeploy must only add read-only keys']);

                return;
            }
            $material = implode(' ', array_slice(explode(' ', (string) $in['key']), 0, 2));
            foreach ($state['keys'] ?? [] as $list) {
                foreach ($list as $k) {
                    if ($k['key'] === $material) {
                        $send(422, ['message' => 'Validation Failed', 'errors' => [['message' => 'key is already in use']]]);

                        return;
                    }
                }
            }
            $id = $state['nextId']++;
            $state['keys'][$full][] = ['id' => $id, 'title' => $in['title'], 'key' => $material, 'read_only' => true];
            $save();
            $send(201, ['id' => $id, 'title' => $in['title'], 'key' => $material, 'read_only' => true]);

            return;
        }
        if (preg_match('#^/keys/(\d+)$#', $rest, $k) === 1 && $method === 'DELETE') {
            $before = count($keys);
            $state['keys'][$full] = array_values(array_filter($keys, static fn ($x) => $x['id'] !== (int) $k[1]));
            $save();
            http_response_code($before === count($state['keys'][$full]) ? 404 : 204);

            return;
        }
    }
}

$send(404, ['message' => 'Not Found']);
