<?php

declare(strict_types=1);

// Stránka 404. Na Apachi ji vrací ErrorDocument (.htaccess),
// při vývoji tools/dev-router.php.

require __DIR__ . '/_app.php';

not_found();
