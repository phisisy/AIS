<?php
declare(strict_types=1);

require_once __DIR__ . '/EnrollmentSupport.php';

/** A valid JSON body for a meeting (array encodes as a JSON object). */
function httpMeeting(string $day = 'MON', string $start = '09:00', string $end = '10:30'): array
{
    return ['day' => $day, 'start' => $start, 'end' => $end, 'room' => 'R-101'];
}

function httpSectionBody(array $override = []): array
{
    return array_replace(['term_id' => 1, 'course_code' => 'CS101', 'course_title' => 'Introduction to Computing',
        'units' => 3, 'section_code' => 'A', 'capacity' => 40, 'meetings' => [httpMeeting()]], $override);
}

test('Enrollment HTTP: role matrix over real sessions for all 7 routes', function (): void {
    $server = enrollmentServer();
    try {
        $token = enrollmentLogin($server, 'registrar@example.test');
        same(201, $server->request('POST', '/api/registrar/terms', termInput(), $token)['status']);
        same(201, $server->request('POST', '/api/registrar/sections', httpSectionBody(), $token)['status']);

        $denied = '{"success":false,"message":"Access denied.","errors":{}}';
        $n = 0;
        // [email, GET terms, POST term, PATCH term, GET sections, GET section, POST section, PATCH section]
        $matrix = [
            [null, 401, 401, 401, 401, 401, 401, 401],
            ['ana@example.test', 200, 403, 403, 200, 200, 403, 403],
            ['faculty@example.test', 200, 403, 403, 200, 200, 403, 403],
            ['registrar@example.test', 200, 201, 200, 200, 200, 201, 200],
            ['admin@example.test', 200, 201, 200, 200, 200, 201, 200],
        ];
        foreach ($matrix as [$email, $getTerms, $postTerm, $patchTerm, $getSections, $getSection, $postSection, $patchSection]) {
            $csrf = enrollmentLogin($server, $email);
            $who = $email ?? 'anonymous';
            $n++;
            $responses = [
                $server->request('GET', '/api/terms'),
                $server->request('POST', '/api/registrar/terms', termInput(['code' => "T{$n}"]), $csrf),
                $server->request('PATCH', '/api/registrar/terms/1', ['name' => "Renamed {$n}"], $csrf),
                $server->request('GET', '/api/sections'),
                $server->request('GET', '/api/sections/1'),
                $server->request('POST', '/api/registrar/sections', httpSectionBody(['section_code' => "S{$n}"]), $csrf),
                $server->request('PATCH', '/api/registrar/sections/1', ['course_title' => "Title {$n}"], $csrf),
            ];
            $got = array_column($responses, 'status');
            check($got === [$getTerms, $postTerm, $patchTerm, $getSections, $getSection, $postSection, $patchSection], "{$who}: got " . json_encode($got));
            foreach ([1, 2, 5, 6] as $i) {
                if ($email === null) {
                    same('Authentication required.', $responses[$i]['json']['message'] ?? null, "{$who} #{$i}");
                }
                if ($email === 'ana@example.test' || $email === 'faculty@example.test') {
                    same($denied, $responses[$i]['body']);
                }
            }
        }
        // Denied callers changed nothing: terms 1 + registrar T4 + admin T5; sections 1 + two creates.
        $csrf = enrollmentLogin($server, 'ana@example.test');
        same(['2026-1S', 'T4', 'T5'], array_column($server->request('GET', '/api/terms')['json']['data']['terms'], 'code'));
        $list = $server->request('GET', '/api/sections')['json']['data'];
        same(3, $list['pagination']['total']);
        same('Title 5', $server->request('GET', '/api/sections/1')['json']['data']['section']['course_title']);
        // Anonymous response body for 401 is exact and identical across routes.
        enrollmentLogin($server, null);
        $anon = $server->request('GET', '/api/terms');
        same($anon['body'], $server->request('GET', '/api/sections/1')['body']);
        same($anon['body'], $server->request('PATCH', '/api/registrar/sections/1', ['units' => 2], 'x')['body']);
    } finally {
        $server->close();
    }
});

test('Enrollment HTTP: missing or invalid CSRF on every staff write is 403 before validation', function (): void {
    $server = enrollmentServer();
    try {
        $token = enrollmentLogin($server, 'registrar@example.test');
        $server->request('POST', '/api/registrar/terms', termInput(), $token);
        $server->request('POST', '/api/registrar/sections', httpSectionBody(), $token);
        $before = [$server->request('GET', '/api/terms')['body'], $server->request('GET', '/api/sections')['body']];
        $writes = [['POST', '/api/registrar/terms', termInput(['code' => 'NEW'])], ['PATCH', '/api/registrar/terms/1', ['name' => 'X']],
            ['POST', '/api/registrar/sections', httpSectionBody(['section_code' => 'Z'])], ['PATCH', '/api/registrar/sections/1', ['units' => 2]],
            // Invalid bodies must still hit CSRF first.
            ['POST', '/api/registrar/terms', ['code' => '!']], ['PATCH', '/api/registrar/sections/1', ['term_id' => 2]]];
        foreach (['admin@example.test', 'registrar@example.test'] as $email) {
            enrollmentLogin($server, $email);
            foreach ([null, 'wrong-token'] as $bad) {
                foreach ($writes as [$method, $path, $body]) {
                    $response = $server->request($method, $path, $body, $bad);
                    same([403, 'Invalid CSRF token.'], [$response['status'], $response['json']['message']], "{$email} {$method} {$path}");
                }
            }
        }
        same($before, [$server->request('GET', '/api/terms')['body'], $server->request('GET', '/api/sections')['body']]);
    } finally {
        $server->close();
    }
});

test('Enrollment HTTP: non-JSON and malformed bodies are rejected', function (): void {
    $server = enrollmentServer();
    try {
        $token = enrollmentLogin($server, 'registrar@example.test');
        $url = (new ReflectionProperty($server, 'url'))->getValue($server);
        $raw = static function (string $method, string $path, string $type, string $body) use ($server, $url, $token): array {
            $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'content' => $body,
                'header' => "Connection: close
Content-Type: {$type}
X-CSRF-Token: {$token}
Cookie: " . $server->cookie()]]);
            $text = (string) file_get_contents($url . $path, false, $context);
            preg_match('~^HTTP/\S+ (\d+)~', $http_response_header[0], $m);
            return [(int) $m[1], $text];
        };
        foreach (['/api/registrar/terms' => 'POST', '/api/registrar/sections' => 'POST', '/api/registrar/terms/1' => 'PATCH'] as $path => $method) {
            foreach (['text/plain', 'application/x-www-form-urlencoded'] as $type) {
                same(415, $raw($method, $path, $type, json_encode(termInput()))[0], "{$method} {$path} {$type}");
            }
            // Syntactically invalid JSON, and JSON that is not an object.
            same(400, $raw($method, $path, 'application/json', '{"code":')[0], "{$method} {$path} malformed");
            check(in_array($raw($method, $path, 'application/json', '[1,2]')[0], [400, 422], true), "{$method} {$path} list body");
        }
        same(0, count($server->request('GET', '/api/terms')['json']['data']['terms']));
        // An empty JSON object is a validation error, not a malformed body.
        same(422, $server->request('POST', '/api/registrar/terms', [], $token)['status']);
        same(422, $server->request('PATCH', '/api/registrar/terms/1', [], $token)['status']);
    } finally {
        $server->close();
    }
});

test('Enrollment HTTP: unknown and immutable fields are 422, immutable data is unchanged, errors never echo input', function (): void {
    $server = enrollmentServer();
    try {
        $token = enrollmentLogin($server, 'registrar@example.test');
        same(201, $server->request('POST', '/api/registrar/terms', termInput(), $token)['status']);
        $created = $server->request('POST', '/api/registrar/sections', httpSectionBody(), $token);
        same(201, $created['status']);
        same(['id', 'term_id', 'course_code', 'course_title', 'units', 'section_code', 'capacity', 'approved_count', 'remaining',
            'meetings', 'created_at', 'updated_at'], array_keys($created['json']['data']['section']));
        same('Section created.', $created['json']['message']);
        foreach ([['code' => 'NEW'], ['id' => 7], ['created_at' => 'x'], ['name' => 'ok', 'role' => 'admin']] as $body) {
            $r = $server->request('PATCH', '/api/registrar/terms/1', $body, $token);
            same([422, 'Validation failed.'], [$r['status'], $r['json']['message']]);
        }
        foreach (['term_id' => 2, 'course_code' => 'IT1', 'section_code' => 'Z', 'approved_count' => 5, 'remaining' => 0, 'id' => 9] as $field => $value) {
            $r = $server->request('PATCH', '/api/registrar/sections/1', [$field => $value], $token);
            same([422, ['Unknown field.']], [$r['status'], array_values($r['json']['errors'])], $field);
            check(array_key_exists($field, $r['json']['errors']));
        }
        foreach (['approved_count' => 5, 'instructors' => [], 'remaining' => 40] as $field => $value) {
            same(422, $server->request('POST', '/api/registrar/sections', httpSectionBody([$field => $value, 'section_code' => 'B']), $token)['status'], $field);
        }
        $s = $server->request('GET', '/api/sections/1')['json']['data']['section'];
        same(['CS101', 'A', 1, 0, 40], [$s['course_code'], $s['section_code'], $s['term_id'], $s['approved_count'], $s['remaining']]);
        same('2026-1S', $server->request('GET', '/api/terms')['json']['data']['terms'][0]['code']);
        $r = $server->request('POST', '/api/registrar/terms', termInput(['code' => 'secret code!']), $token);
        same(422, $r['status']);
        check(!str_contains($r['body'], 'secret code'), 'Input echoed.');
        // 409 envelopes.
        $dup = $server->request('POST', '/api/registrar/terms', termInput(['code' => '2026-1s']), $token);
        same([409, 'Validation failed.', ['code' => 'This term code is already in use.']], [$dup['status'], $dup['json']['message'], $dup['json']['errors']]);
        $dup = $server->request('POST', '/api/registrar/sections', httpSectionBody(), $token);
        same([409, ['section_code']], [$dup['status'], array_keys($dup['json']['errors'])]);
        $unknown = $server->request('POST', '/api/registrar/sections', httpSectionBody(['term_id' => 50]), $token);
        same([422, ['term_id' => 'Unknown term.']], [$unknown['status'], $unknown['json']['errors']]);
        // 404s.
        $nf = $server->request('PATCH', '/api/registrar/terms/99', ['name' => 'X'], $token);
        same('{"success":false,"message":"Term not found.","errors":{}}', $nf['body']);
        $nf = $server->request('PATCH', '/api/registrar/sections/99', ['units' => 2], $token);
        same('{"success":false,"message":"Section not found.","errors":{}}', $nf['body']);
        same('{"success":false,"message":"Section not found.","errors":{}}', $server->request('GET', '/api/sections/99')['body']);
    } finally {
        $server->close();
    }
});

test('Enrollment HTTP: meetings as JSON objects are accepted, as JSON arrays rejected, and capacity 409 is reported', function (): void {
    $server = enrollmentServer();
    try {
        $token = enrollmentLogin($server, 'admin@example.test');
        $server->request('POST', '/api/registrar/terms', termInput(), $token);
        $ok = $server->request('POST', '/api/registrar/sections', httpSectionBody(['meetings' => [httpMeeting('WED'), httpMeeting('MON')]]), $token);
        same(201, $ok['status']);
        same(['MON', 'WED'], array_column($ok['json']['data']['section']['meetings'], 'day'));
        $arr = $server->request('POST', '/api/registrar/sections', httpSectionBody(['section_code' => 'B', 'meetings' => [['MON', '09:00', '10:00', 'R-1']]]), $token);
        same([422, ['meetings.0']], [$arr['status'], array_keys($arr['json']['errors'])]);
        $overlap = $server->request('POST', '/api/registrar/sections', httpSectionBody(['section_code' => 'B',
            'meetings' => [httpMeeting('MON', '09:00', '10:30'), httpMeeting('MON', '10:00', '11:00')]]), $token);
        same([422, ['meetings' => 'Meetings 0 and 1 overlap.']], [$overlap['status'], $overlap['json']['errors']]);
        $back = $server->request('POST', '/api/registrar/sections', httpSectionBody(['section_code' => 'C',
            'meetings' => [httpMeeting('MON', '09:00', '10:00'), httpMeeting('MON', '10:00', '11:00')]]), $token);
        same(201, $back['status']);
        bumpApprovedCount($server->directory, 1, 7);
        $low = $server->request('PATCH', '/api/registrar/sections/1', ['capacity' => 6], $token);
        same([409, 'Validation failed.', ['capacity' => 'Capacity cannot be below the approved count (7).']],
            [$low['status'], $low['json']['message'], $low['json']['errors']]);
        $good = $server->request('PATCH', '/api/registrar/sections/1', ['capacity' => 7], $token);
        same([200, 'Section updated.', 0, 7], [$good['status'], $good['json']['message'],
            $good['json']['data']['section']['remaining'], $good['json']['data']['section']['approved_count']]);
        $term = $server->request('PATCH', '/api/registrar/terms/1', ['is_current' => true, 'enrollment_open' => true], $token);
        same(['Term updated.', true, true], [$term['json']['message'], $term['json']['data']['term']['is_current'], $term['json']['data']['term']['enrollment_open']]);
        same(422, $server->request('PATCH', '/api/registrar/terms/1', ['end_date' => '2026-01-01'], $token)['status']);
    } finally {
        $server->close();
    }
});

test('Enrollment HTTP: list validation, filters, term ordering and envelope headers', function (): void {
    $server = enrollmentServer();
    try {
        $token = enrollmentLogin($server, 'registrar@example.test');
        $server->request('POST', '/api/registrar/terms', termInput(['code' => 'LATE', 'start_date' => '2027-01-01', 'end_date' => '2027-05-01']), $token);
        $server->request('POST', '/api/registrar/terms', termInput(), $token);
        $server->request('POST', '/api/registrar/sections', httpSectionBody(), $token);
        $server->request('POST', '/api/registrar/sections', httpSectionBody(['term_id' => 2, 'course_code' => 'IT211', 'section_code' => 'B']), $token);
        $student = enrollmentLogin($server, 'ana@example.test');
        $terms = $server->request('GET', '/api/terms');
        same(['2026-1S', 'LATE'], array_column($terms['json']['data']['terms'], 'code'));
        same(['id', 'code', 'name', 'start_date', 'end_date', 'is_current', 'enrollment_open', 'created_at', 'updated_at'], array_keys($terms['json']['data']['terms'][0]));
        same(['sections', 'pagination'], array_keys($server->request('GET', '/api/sections')['json']['data']));
        same(['page' => 1, 'per_page' => 20, 'total' => 2], $server->request('GET', '/api/sections')['json']['data']['pagination']);
        same(1, count($server->request('GET', '/api/sections?term_id=2')['json']['data']['sections']));
        same('IT211', $server->request('GET', '/api/sections?course=it211')['json']['data']['sections'][0]['course_code']);
        same(1, count($server->request('GET', '/api/sections?search=computing&per_page=1')['json']['data']['sections']));
        foreach (['page=0', 'per_page=101', 'term_id=abc', 'search[]=x', 'course[]=x', 'course=x', 'page[]=1'] as $query) {
            same(422, $server->request('GET', '/api/sections?' . $query)['status'], $query);
        }
        same(404, $server->request('GET', '/api/sections/abc')['status']);
        same(404, $server->request('GET', '/api/sections/0')['status']);
        same(404, $server->request('GET', '/api/sections/-1')['status']);
        foreach ([$terms, $server->request('GET', '/api/sections/abc'), $server->request('GET', '/api/sections?page=0')] as $response) {
            check(str_starts_with($response['headers']['content-type'] ?? '', 'application/json'), 'Content-Type');
            same('no-store', $response['headers']['cache-control'] ?? null);
            same('nosniff', $response['headers']['x-content-type-options'] ?? null);
        }
    } finally {
        $server->close();
    }
});

test('Enrollment HTTP: a corrupt store gives a generic 500 on every route and is never written', function (): void {
    $server = enrollmentServer();
    try {
        $token = enrollmentLogin($server, 'registrar@example.test');
        $server->request('POST', '/api/registrar/terms', termInput(), $token);
        $server->request('POST', '/api/registrar/sections', httpSectionBody(), $token);
        // A store with approved_count > capacity and one that is not valid JSON.
        $good = json_decode((string) file_get_contents($server->directory . '/enrollment/enrollment.json'), true);
        $good['sections'][0]['approved_count'] = 99;
        foreach ([json_encode($good), '{"terms": [corrupt'] as $contents) {
            $path = writeEnrollmentStore($server->directory, $contents);
            $requests = [['GET', '/api/terms', null], ['POST', '/api/registrar/terms', termInput(['code' => 'NEW'])],
                ['PATCH', '/api/registrar/terms/1', ['name' => 'X']], ['GET', '/api/sections', null], ['GET', '/api/sections/1', null],
                ['POST', '/api/registrar/sections', httpSectionBody(['section_code' => 'Q'])], ['PATCH', '/api/registrar/sections/1', ['units' => 2]]];
            foreach ($requests as [$method, $uri, $body]) {
                $response = $server->request($method, $uri, $body, $token);
                same([500, 'An internal error occurred.', []], [$response['status'], $response['json']['message'], $response['json']['errors']], "{$method} {$uri}");
                check(!str_contains($response['body'], 'enrollment') && !str_contains($response['body'], 'Invalid'), 'Detail leaked: ' . $response['body']);
            }
            same($contents, file_get_contents($path));
        }
    } finally {
        $server->close();
    }
});
