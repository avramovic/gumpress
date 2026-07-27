<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * Explicit require list for this copy's engine — no autoloader at runtime,
 * so a drop-in stays a drop-in. Guarded so that two plugins bundling the
 * exact same GumPress version (a common case: two products from the same
 * author, or two independently-built plugins on the same release) don't
 * fatal on a duplicate class declaration when both of their copies of this
 * file get require'd.
 */
if (class_exists(__NAMESPACE__ . '\\Engine', false)) {
    return;
}

require __DIR__ . '/Data.php';
require __DIR__ . '/Notices.php';
require __DIR__ . '/Config.php';
require __DIR__ . '/Env.php';
require __DIR__ . '/License.php';
require __DIR__ . '/Status.php';
require __DIR__ . '/Validator.php';
require __DIR__ . '/Strings.php';
require __DIR__ . '/Overrides.php';
require __DIR__ . '/Api.php';
require __DIR__ . '/Module.php';
require __DIR__ . '/NullModule.php';
require __DIR__ . '/Admin.php';
require __DIR__ . '/Updater.php';
require __DIR__ . '/Engine.php';
