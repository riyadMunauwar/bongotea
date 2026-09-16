<?php

use App\Enums\Permission;
use Illuminate\Support\Facades\File;

/**
 * The catalogue and the gates agree (ADR-0026, seam 3).
 *
 * The Permission enum is the single declaration site, so the one thing no
 * screen test can ever see is a case that no gate names — a dead gate a
 * Merchant can tick that grants nothing — or a gate that reaches for a
 * Permission by a bare string, off the enum's books. Every other ticket sees
 * only its own surface; this one reads the source of them all and holds the
 * two against each other.
 *
 * It reads the PHP tokens rather than the text, so a Permission named only in
 * a comment or a string does not read as a gate. It asserts nothing about the
 * Owner bypass, the sync command's writes or how the Roles screen builds its
 * ticks: each is observable at a higher seam, and the ADRs left its mechanism
 * free to change.
 */

/**
 * Every application PHP file, as an absolute path.
 *
 * @return list<string>
 */
function catalogueSourceFiles(): array
{
    return collect(File::allFiles(app_path()))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->map(fn ($file): string => $file->getPathname())
        ->values()
        ->all();
}

/**
 * The name of every enum case named as `Permission::Case` anywhere in the
 * application, read off the tokens so a mention in a comment or a string does
 * not count. The enum's own file is skipped: it is the declaration site, not a
 * gate that references it.
 *
 * @return list<string>
 */
function permissionCasesNamedByGates(): array
{
    $caseNames = array_map(fn (Permission $case): string => $case->name, Permission::cases());
    $enumFile = app_path('Enums'.DIRECTORY_SEPARATOR.'Permission.php');
    $named = [];

    foreach (catalogueSourceFiles() as $path) {
        if ($path === $enumFile) {
            continue;
        }

        $tokens = token_get_all(File::get($path));
        $last = count($tokens) - 3;

        for ($i = 0; $i <= $last; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING && $tokens[$i][1] === 'Permission'
                && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_DOUBLE_COLON
                && is_array($tokens[$i + 2]) && $tokens[$i + 2][0] === T_STRING
                && in_array($tokens[$i + 2][1], $caseNames, true)) {
                $named[$tokens[$i + 2][1]] = true;
            }
        }
    }

    return array_keys($named);
}

/**
 * Every `file:line` where a gate is asked with a string literal rather than a
 * typed case. The methods are the ability checks a gated surface calls; a
 * string handed to any of them is a Permission named off the enum's books,
 * which a rename would leave untouched and no case could ever hold.
 *
 * @return list<string>
 */
function stringLiteralGates(): array
{
    $abilityMethods = [
        'can', 'cannot', 'canAny', 'authorize',
        'hasPermissionTo', 'hasAnyPermission', 'hasAllPermissions', 'checkPermissionTo',
    ];
    $offences = [];

    foreach (catalogueSourceFiles() as $path) {
        $tokens = token_get_all(File::get($path));
        $count = count($tokens);

        for ($i = 0; $i < $count - 2; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_OBJECT_OPERATOR) {
                continue;
            }

            if (! is_array($tokens[$i + 1]) || $tokens[$i + 1][0] !== T_STRING
                || ! in_array($tokens[$i + 1][1], $abilityMethods, true)
                || $tokens[$i + 2] !== '(') {
                continue;
            }

            $argument = $i + 3;
            while ($argument < $count && is_array($tokens[$argument]) && $tokens[$argument][0] === T_WHITESPACE) {
                $argument++;
            }

            if ($argument < $count && is_array($tokens[$argument]) && $tokens[$argument][0] === T_CONSTANT_ENCAPSED_STRING) {
                $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                $offences[] = $relative.':'.$tokens[$i + 1][2];
            }
        }
    }

    return $offences;
}

/**
 * A case nothing names is a Permission a Merchant can grant that opens no
 * screen and holds back no act. This is the failure ADR-0026 exists to catch,
 * and the only place it can be caught: add a thirteenth case, gate nothing on
 * it, and every panel test still passes.
 */
it('names every Permission the enum holds in a gate somewhere', function () {
    $everyCase = array_map(fn (Permission $case): string => $case->name, Permission::cases());

    expect(array_values(array_diff($everyCase, permissionCasesNamedByGates())))->toBe([]);
});

/**
 * A typed case is a fatal at every reference site when it is renamed, which is
 * the whole reason the enum is preferred over a constant or a literal. A gate
 * that names its Permission with a string throws that away: the rename slides
 * past it and the screen quietly stops being reachable. So no gate may.
 */
it('routes every gate through a typed case, never a bare string', function () {
    expect(stringLiteralGates())->toBe([]);
});
