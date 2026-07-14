<?php

/**
 * tests/bootstrap.php
 * ==========================================================================
 * PHPUnit bootstrap. Loads dependency-free include files that are safe to
 * exercise without a database connection or an active web request.
 * ==========================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/routes.php';
require_once __DIR__ . '/../includes/permission.php';
