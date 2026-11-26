<?php
declare(strict_types=1);

require_once __DIR__ . '/MySQLTestSupport.php';

// Inventory of the selected suites, counted from the registered test names (not from a guess):
//   auth HTTP 6, Admin HTTP 5 (incl. T-05 audit-logs), RBAC HTTP 5, Security events HTTP 2, Students HTTP 4,
//   Academic HTTP 2, Assignment HTTP 3, Enrollment HTTP 11 (8 catalog + 3 request) = 38.
//   (auth HTTP 6 since 2026-11-26: 'auth HTTP: successful sign-ins count too, …' from the security review, finding L2.)
// Not selected on purpose (they seed JSON faculty/grade stores or own their server and MySQL variant, so the C-mode
// switch cannot run them): Faculty HTTP, Class roster HTTP, Faculty store, Grade HTTP, T-39 HTTP (has its own MariaDB test).
// C3/C4 run faculty on MySQL, where a faculty id must exist in SQL (FK / orphan check). The two tests that assign faculty 10
// ('Assignment HTTP: reads expose instructors...' and 'Enrollment HTTP: request, ownership, decisions...') therefore seed a real
// faculty profile for id 10 (tests/Enrollment/EnrollmentSupport.php: seedFacultyProfile/seedServerAssignment, tester-side rows on
// C3/C4, faculty.json in mock) and assert the real instructor name; the 'no profile => name null' fallback is asserted in mock
// mode only. So nothing is excluded: all 38 run on C1-C4. The exclusion mechanism and its exact-list check stay as a guard.
// Raw-store tests made MySQL-capable (state read/changed by tester SQL; byte comparisons replaced by row-level snapshot hashes;
// an unknown role and a corrupt/unreadable store replaced by schema-valid, model-invalid rows or moved tables):
//   auth HTTP reloads users...; RBAC HTTP role changes...; RBAC HTTP unknown stored roles...; Admin HTTP anonymous/non-admin/CSRF;
//   Admin HTTP create/list/get...; Security events HTTP (2); Students HTTP retained profiles; Students HTTP broken or corrupt store;
//   Enrollment HTTP corrupt store; delivery_mode; meetings/capacity 409 (bumpApprovedCount); Assignment HTTP unknown faculty ids;
//   Enrollment HTTP role matrix/CSRF/body rules, and closed term/OQ-3 (storeBytes). 'Academic HTTP ... retained' is in
//   tests/Students/AcademicRecordHttpTest.php (another owner): it needs `$users = serverUsers($server);` on line 63.
const HTTP_ON_MYSQL_FACULTY_SEEDED = [];

if (is_string(getenv('AIS_MYSQL_TEST_APP_PASSWORD')) && MySQLTestSupport::enabled()) {
    foreach (['C1', 'C2', 'C3', 'C4'] as $mode) {
        test('T-60 HTTP suites on MySQL ' . $mode . ': unchanged existing assertions', static function () use ($mode): void {
            $previous = getenv('AIS_HTTP_MYSQL');
            $failures = [];
            $count = 0;
            $excluded = [];
            try {
                putenv('AIS_HTTP_MYSQL=' . $mode);
                // The Core HTTP suite has a separate CoreHttpServer and is covered by W4/V9 for MySQL failures.
                // Every suite using AuthHttpServer is invoked. Tests that used to read or edit the raw JSON stores (deleted
                // after the import) now go through backend-agnostic helpers (tests/Auth/HttpSupport.php serverUsers() etc.,
                // tests/Enrollment/EnrollmentSupport.php serverEnrollment*()): mock keeps the original store access, MySQL
                // reads and changes the same facts with the guarded tester connection. Nothing is excluded.
                foreach ($GLOBALS['ais_tests'] as [$name, $action]) {
                    if (!preg_match('/\A(?:auth HTTP|Admin HTTP|RBAC HTTP|Security events HTTP|Students HTTP|Academic HTTP|Enrollment HTTP|Assignment HTTP):?/', $name)) {
                        continue;
                    }
                    if (in_array($mode, ['C3', 'C4'], true) && in_array($name, HTTP_ON_MYSQL_FACULTY_SEEDED, true)) {
                        $excluded[] = $name;
                        continue;
                    }
                    $count++;
                    try { $action(); }
                    catch (Throwable $error) { $failures[] = $name; }
                }
            } finally {
                putenv($previous === false ? 'AIS_HTTP_MYSQL' : 'AIS_HTTP_MYSQL=' . $previous);
            }
            $expectedExcluded = in_array($mode, ['C3', 'C4'], true) ? HTTP_ON_MYSQL_FACULTY_SEEDED : [];
            sort($excluded); sort($expectedExcluded);
            check($excluded === $expectedExcluded, 'HTTP MySQL exclusion list changed: ' . implode(' | ', $excluded));
            check($count + count($excluded) === 38 && $count === 38 - count($expectedExcluded),
                'HTTP MySQL suite inventory changed: ' . $count . ' run, ' . count($excluded) . ' excluded');
            check($failures === [], "HTTP MySQL failures (no skips):\n" . implode("\n", $failures));
        });
    }
}
