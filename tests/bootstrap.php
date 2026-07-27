<?php

require __DIR__ . '/stubs.php';

require __DIR__ . '/../gumpress/src/Data.php';
require __DIR__ . '/../gumpress/src/Notices.php';
require __DIR__ . '/../gumpress/src/Config.php';
require __DIR__ . '/../gumpress/src/Env.php';
require __DIR__ . '/../gumpress/src/License.php';
require __DIR__ . '/../gumpress/src/Status.php';
require __DIR__ . '/../gumpress/src/Validator.php';
require __DIR__ . '/../gumpress/src/Strings.php';
require __DIR__ . '/../gumpress/src/Overrides.php';

// Engine::create() itself needs Module.php (not loaded here — it needs
// heavier WP stubs this lightweight harness doesn't provide), but its pure
// Engine::maybe_warn_unsealed_config() helper only needs Config/Env/Notices,
// all already loaded above.
require __DIR__ . '/../gumpress/src/Engine.php';
