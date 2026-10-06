<?php

declare(strict_types=1);

// TimeWeb shared hosting: public_html/ IS the document root, while the rest of
// the application (app/, bootstrap/, config/, vendor/, .env, storage/) lives one
// level ABOVE it in the account root — outside the web root, as it should be.
//
// This thin entrypoint keeps a single source of truth: the canonical Laravel
// front controller in public/index.php. Every path inside it resolves relative
// to public/ (../vendor, ../bootstrap/app.php, ../storage), i.e. to the account
// root, which is exactly where they belong. Served requests still hit Laravel;
// the only thing exposed via HTTP is public_html/ itself.
require __DIR__ . '/../public/index.php';
