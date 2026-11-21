<?php
declare(strict_types=1);

require_once __DIR__ . '/MySQLTestSupport.php';

if (MySQLTestSupport::enabled()) {
    test('MySQL test guard checks live target and connection session', static function (): void {
        $pdo = MySQLTestSupport::connect();
        MySQLTestSupport::assertBeforeReset($pdo);
        $session = $pdo->query('SELECT @@SESSION.sql_mode AS mode, @@SESSION.time_zone AS zone')->fetch();
        check(is_array($session));
        check(in_array('STRICT_ALL_TABLES', explode(',', $session['mode']), true), 'Strict SQL mode missing.');
        check(!str_contains($session['mode'], 'NO_BACKSLASH_ESCAPES'), 'Backslash escaping changed.');
        same('+00:00', $session['zone']);

        $port = getenv('DB_PORT');
        try {
            putenv('DB_PORT=1');
            throws(RuntimeException::class, static fn () => MySQLTestSupport::assertBeforeReset($pdo));
        } finally {
            putenv('DB_PORT=' . $port);
        }
        MySQLTestSupport::assertBeforeReset($pdo);
    });
}
