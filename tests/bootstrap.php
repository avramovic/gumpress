<?php

require __DIR__ . '/stubs.php';

require __DIR__ . '/../gumpress/src/Data.php';
require __DIR__ . '/../gumpress/src/Notices.php';
require __DIR__ . '/../gumpress/src/Config.php';
require __DIR__ . '/../gumpress/src/Vault.php';
require __DIR__ . '/../gumpress/src/Env.php';
require __DIR__ . '/../gumpress/src/License.php';
require __DIR__ . '/../gumpress/src/Status.php';
require __DIR__ . '/../gumpress/src/Validator.php';
require __DIR__ . '/../gumpress/src/Strings.php';
require __DIR__ . '/../gumpress/src/Overrides.php';

require __DIR__ . '/../gumpress/src/Engine.php';

// Api/Module need more WP surface than the rest (options, transients,
// multisite detection) — see the option store in stubs.php. Admin/Updater
// stay out: they need the full admin/hook API and are still uncovered.
require __DIR__ . '/../gumpress/src/Api.php';
require __DIR__ . '/../gumpress/src/Module.php';
