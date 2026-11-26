<?php
declare(strict_types=1);

use App\Core\Environment;

// N1: an empty process value must beat .env, an absent one must fall back to it.
// Windows drops '' variables when it starts a process (confirmed on the trusted laptop run, PHP 8.5.13),
// so parents pass blanks through Environment::forChild(), which also lists them in AIS_ENV_BLANK.
function envOverrideRun(string $directory, array $env): array
{
    $code = 'require $argv[1]; App\Core\Environment::load($argv[2]); echo App\Core\Environment::get("X_SECRET", "none");';
    return runProcess([PHP_BINARY, '-r', $code, dirname(__DIR__, 2) . '/app/autoload.php', $directory . '/.env'], $env);
}

function envOverrideBase(): array
{
    return array_diff_key(getenv(), ['X_SECRET' => true, Environment::BLANK_KEYS => true]);
}

test('empty process env value overrides .env; absent value falls back to .env', function (): void {
    $directory = tempDirectory();
    try {
        file_put_contents($directory . '/.env', "X_SECRET=fromfile\n");
        $base = envOverrideBase();

        if (PHP_OS_FAMILY !== 'Windows') { // a raw '' only survives process creation outside Windows
            [$exit, $out] = envOverrideRun($directory, array_replace($base, ['X_SECRET' => '']));
            same(0, $exit);
            same('', $out);
        }

        [$exit, $out] = envOverrideRun($directory, Environment::forChild(array_replace($base, ['X_SECRET' => ''])));
        same(0, $exit);
        same('', $out);

        [$exit, $out] = envOverrideRun($directory, $base);
        same(0, $exit);
        same('fromfile', $out);
    } finally {
        removeTestDirectory($directory);
    }
});

test('N1 AIS_ENV_BLANK: a real process value still wins, other keys still fall back, and a malformed list fails closed', function (): void {
    $directory = tempDirectory();
    try {
        file_put_contents($directory . '/.env', "X_SECRET=fromfile\n");
        $base = envOverrideBase();

        [$exit, $out] = envOverrideRun($directory, array_replace($base, ['X_SECRET' => 'fromprocess', Environment::BLANK_KEYS => 'X_SECRET']));
        same([0, 'fromprocess'], [$exit, $out]);

        [$exit, $out] = envOverrideRun($directory, array_replace($base, [Environment::BLANK_KEYS => 'OTHER_KEY']));
        same([0, 'fromfile'], [$exit, $out]);

        foreach (['X_SECRET,', ',X_SECRET', 'X SECRET', 'X_SECRET;rm', '1X', 'x_secret'] as $bad) {
            [$exit, $out] = envOverrideRun($directory, array_replace($base, [Environment::BLANK_KEYS => $bad]));
            check($exit !== 0, "Malformed AIS_ENV_BLANK '{$bad}' was accepted.");
            check(!str_contains($out, 'fromfile'), "Malformed AIS_ENV_BLANK '{$bad}' leaked the .env value.");
        }
    } finally {
        removeTestDirectory($directory);
    }
});

test('N1 forChild(): blanks are listed and merged, a set value removes its key, and invalid input is refused', function (): void {
    $child = Environment::forChild(['A' => '', 'B' => 'b', 'C' => '', Environment::BLANK_KEYS => 'D']);
    same('A,C,D', $child[Environment::BLANK_KEYS]);
    same(['', 'b', ''], [$child['A'], $child['B'], $child['C']]);

    $child = Environment::forChild(['A' => 'set', Environment::BLANK_KEYS => 'A']);
    check(!isset($child[Environment::BLANK_KEYS]), 'A key with a real value must leave the blank list.');

    same(['B' => 'b'], Environment::forChild(['B' => 'b']));
    same('Invalid child environment.', throws(RuntimeException::class, static fn () => Environment::forChild(['A' => 1]))->getMessage());
    same('Invalid child environment.', throws(RuntimeException::class, static fn () => Environment::forChild(['A B' => '']))->getMessage());
    // Windows names are case-insensitive: a mixed-case blank (Db_Password) would not blank DB_PASSWORD here.
    same('Invalid child environment.', throws(RuntimeException::class, static fn () => Environment::forChild(['Db_Password' => '']))->getMessage());
    same(['ProgramFiles(x86)' => 'C:\\x'], Environment::forChild(['ProgramFiles(x86)' => 'C:\\x'])); // inherited OS names with a value pass
    same('Invalid environment configuration.', throws(RuntimeException::class,
        static fn () => Environment::forChild([Environment::BLANK_KEYS => 'A;B']))->getMessage());
});
