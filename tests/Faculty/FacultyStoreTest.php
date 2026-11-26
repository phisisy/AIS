<?php
declare(strict_types=1);

require_once __DIR__ . '/FacultySupport.php';

test('Faculty store: every corruption throws on read and on write and leaves the file byte-identical', function (): void {
    $cases = [
        'unknown top-level key' => static function (array $s): array { $s['extra'] = []; return $s; },
        'faculty not a list' => static function (array $s): array { $s['faculty'] = ['a' => $s['faculty'][0]]; return $s; },
        'extra row key' => static function (array $s): array { $s['faculty'][0]['email'] = 'x'; return $s; },
        'missing row key' => static function (array $s): array { unset($s['faculty'][0]['department']); return $s; },
        'reordered row key' => static function (array $s): array { $s['faculty'][0] = array_reverse($s['faculty'][0], true); return $s; },
        'string id' => static function (array $s): array { $s['faculty'][0]['id'] = '1'; return $s; },
        'zero id' => static function (array $s): array { $s['faculty'][0]['id'] = 0; return $s; },
        'duplicate id' => static function (array $s): array { $s['faculty'][1]['id'] = 1; return $s; },
        'duplicate user_id' => static function (array $s): array { $s['faculty'][1]['user_id'] = 3; return $s; },
        'duplicate employee_number' => static function (array $s): array { $s['faculty'][1]['employee_number'] = 'EMP-001'; return $s; },
        'lowercase employee_number' => static function (array $s): array { $s['faculty'][0]['employee_number'] = 'emp-001'; return $s; },
        'control character in name' => static function (array $s): array { $s['faculty'][0]['first_name'] = "A\x07"; return $s; },
        '71 character name' => static function (array $s): array { $s['faculty'][0]['last_name'] = str_repeat('a', 71); return $s; },
        'bad status' => static function (array $s): array { $s['faculty'][0]['status'] = 'retired'; return $s; },
        'bad timestamp' => static function (array $s): array { $s['faculty'][0]['created_at'] = '2026-11-20'; return $s; },
        'history not a list' => static function (array $s): array { $s['history'] = ['a' => $s['history'][0]]; return $s; },
        'seq gap' => static function (array $s): array { $s['history'][1]['seq'] = 3; return $s; },
        'bad field' => static function (array $s): array { $s['history'][0]['field'] = 'first_name'; return $s; },
        'bad at' => static function (array $s): array { $s['history'][0]['at'] = 'yesterday'; return $s; },
        'actor_id 0' => static function (array $s): array { $s['history'][0]['actor_id'] = 0; return $s; },
        'unknown faculty_id' => static function (array $s): array { $s['history'][0]['faculty_id'] = 99; return $s; },
        'non-null from on a first entry' => static function (array $s): array { $s['history'][0]['from'] = 'inactive'; return $s; },
        'from differs from previous to' => static function (array $s): array {
            $s['history'][] = ['seq' => 3, 'at' => '2026-11-20T00:00:00Z', 'faculty_id' => 1, 'field' => 'status', 'from' => 'inactive', 'to' => 'active', 'actor_id' => 2];
            return $s;
        },
        'last to differs from current value' => static function (array $s): array { $s['faculty'][0]['department'] = 'Other'; return $s; },
        'profile without creation entries' => static function (array $s): array { $s['history'] = []; return $s; },
    ];
    foreach ($cases as $name => $corrupt) {
        $dir = tempDirectory();
        try {
            $f = facultyFixture($dir);
            $actor = $f['users']->findById(2);
            $f['service']->create($actor, facultyInput());
            $f['service']->create($actor, facultyInput(['user_id' => 6, 'employee_number' => 'EMP-006']));
            $store = $corrupt(json_decode(facultyStoreBytes($dir), true, 512, JSON_THROW_ON_ERROR));
            file_put_contents(facultyStorePath($dir), json_encode($store, JSON_THROW_ON_ERROR));
            $bytes = facultyStoreBytes($dir);
            try {
                throws(RuntimeException::class, fn () => $f['repo']->findById(1));
                throws(RuntimeException::class, fn () => $f['repo']->create(['user_id' => 7, 'employee_number' => 'EMP-007', 'first_name' => 'A',
                    'last_name' => 'B', 'department' => 'C', 'status' => 'active'], '2026-11-20T00:00:00Z', 2));
                throws(RuntimeException::class, fn () => $f['repo']->update(1, ['status' => 'inactive'], '2026-11-20T00:00:00Z', 2));
            } catch (Throwable $e) {
                throw new RuntimeException($name . ': ' . $e->getMessage());
            }
            same($bytes, facultyStoreBytes($dir));
        } finally { removeTestDirectory($dir); }
    }
});

test('Faculty store: a missing file loads empty', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        same([null, null, [], 0], [$f['repo']->findById(1), $f['repo']->findByUserId(3), $f['repo']->findByIds([1]), $f['repo']->search([], 1, 10)['total']]);
        same(null, $f['identity']->find(1));
    } finally { removeTestDirectory($dir); }
});

test('Faculty store: a corrupt store is a generic 500 over HTTP', function (): void {
    $server = facultyServer();
    try {
        $token = enrollmentLogin($server, 'registrar@example.test');
        is_dir($server->directory . '/faculty') || mkdir($server->directory . '/faculty', 0700, true);
        file_put_contents(facultyStorePath($server->directory), '{"faculty":"x","history":[]}');
        $response = $server->request('GET', '/api/registrar/faculty', null, $token);
        same(500, $response['status']);
        same('An internal error occurred.', $response['json']['message']);
        check(!str_contains($response['body'], 'faculty store'), 'No internals in the 500 body.');
    } finally { $server->close(); }
});
